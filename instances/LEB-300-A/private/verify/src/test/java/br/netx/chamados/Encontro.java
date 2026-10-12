package br.netx.chamados;

import javax.sql.DataSource;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.List;
import java.util.function.Predicate;

/**
 * Ponto de encontro entre duas conexões da aplicação, para forçar um entrelaçamento sem dormir. Cada conexão que executa um comando que casa
 * com o gatilho espera até que (1) as outras tenham chegado, (2) alguma conexão que ainda não chegou esteja provadamente bloqueada numa
 * trava do servidor, ou (3) o teto de segurança estoure. O teto produz resultado inconclusivo, nunca aprovação.
 * O veredito é o mesmo se a espera durar 2 ms ou 2 s: a espera termina por estado, não por tempo.
 */
final class Encontro {
    private final int pares;
    private final Predicate<String> gatilho;
    private final DataSource monitor;
    private final Instrumento instrumento;
    private final long tetoMs;
    volatile boolean estourou;
    private final Object trava = new Object();
    private int chegaram;
    private int saidas;

    Encontro(Instrumento instrumento, DataSource monitor, int pares, Predicate<String> gatilho, long tetoMs) {
        this.instrumento = instrumento;
        this.monitor = monitor;
        this.pares = pares;
        this.gatilho = gatilho;
        this.tetoMs = tetoMs;
    }

    /** Um par que terminou sem chegar ao gatilho (por exemplo, não encontrou o que reivindicar) não vai mais chegar: quem espera é liberado. */
    void saiu() {
        synchronized (trava) {
            saidas++;
            trava.notifyAll();
        }
    }

    /** Para usar como Instrumento.Gancho. */
    void aoExecutar(Instrumento.Conexao c, String sql, List<Object> args) {
        if (c.chegou || !gatilho.test(sql.toLowerCase())) return;
        c.chegou = true;
        synchronized (trava) {
            chegaram++;
            trava.notifyAll();
        }
        long limite = System.nanoTime() + tetoMs * 1_000_000L;
        while (true) {
            synchronized (trava) {
                if (chegaram + saidas >= pares) return;
            }
            if (alguemBloqueado()) return;
            if (System.nanoTime() > limite) {
                estourou = true;
                return;
            }
            try {
                Thread.sleep(2);   // polling: não decide nada, só espera o estado mudar
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return;
            }
        }
    }

    /** Alguma conexão aberta que ainda não chegou está esperando uma trava? Então ela não vai chegar enquanto a outra esperar. */
    private boolean alguemBloqueado() {
        return alguemBloqueado(instrumento, monitor);
    }

    private static final java.util.Set<String> DO_VERIFICADOR = java.util.Set.of("Portao", "Encontro", "Instrumento", "Resultado", "Estrutura", "VBase", "TestBase", "VerificaExtension");

    /** Alguma thread está bloqueada num monitor Java dentro do código da aplicação (ou do que ela chama)? */
    private static boolean alguemBloqueadoNoMonitor() {
        for (java.lang.management.ThreadInfo t : java.lang.management.ManagementFactory.getThreadMXBean().dumpAllThreads(false, false)) {
            if (t.getThreadState() != Thread.State.BLOCKED) continue;
            for (StackTraceElement f : t.getStackTrace()) {
                String classe = f.getClassName();
                if (classe.startsWith("br.netx.chamados.") && !DO_VERIFICADOR.contains(classe.substring(classe.lastIndexOf('.') + 1).replaceAll("[$].*", "")) && !classe.matches(".*[.]V\\d.*")) return true;
            }
        }
        return false;
    }

    static boolean alguemBloqueado(Instrumento instrumento, DataSource monitor) {
        if (alguemBloqueadoNoMonitor()) return true;
        List<Integer> pids = new ArrayList<>();
        synchronized (instrumento.conexoes) {
            for (Instrumento.Conexao k : instrumento.conexoes) if (k.aberta && !k.chegou && k.pid > 0) pids.add(k.pid);
        }
        if (pids.isEmpty()) return false;
        try (Connection c = monitor.getConnection(); PreparedStatement ps = c.prepareStatement(
                "select count(*) from pg_stat_activity where pid = any(?) and wait_event_type = 'Lock'")) {
            ps.setArray(1, c.createArrayOf("integer", pids.toArray()));
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1) > 0;
            }
        } catch (SQLException e) {
            return false;
        }
    }
}
