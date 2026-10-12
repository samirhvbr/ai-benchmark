package br.netx.chamados;

import javax.sql.DataSource;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.util.ArrayList;
import java.util.List;

/** O lado do pai: sobe instâncias da aplicação em JVMs independentes sobre o mesmo banco e as solta, por estado, na ordem que o teste escolher. */
final class Processos implements AutoCloseable {
    static final long TETO_MS = 90_000;

    final Path rv;
    private final DataSource monitor;
    private final String esquema;
    private final Path notificacoes;
    final List<Process> filhos = new ArrayList<>();
    final List<Path> saidas = new ArrayList<>();

    Processos(DataSource monitor, String esquema, Path notificacoes) throws IOException {
        this.rv = Files.createTempDirectory("rv");
        this.monitor = monitor;
        this.esquema = esquema;
        this.notificacoes = notificacoes;
    }

    Process iniciar(String modo, String id, String gatilho) throws IOException {
        String jvm = ProcessHandle.current().info().command().orElse("java");
        Path saida = rv.resolve("saida-" + id + ".txt");
        Process p = new ProcessBuilder(jvm, "-cp", System.getProperty("java.class.path"), "br.netx.chamados.V02Processo", modo, esquema,
                notificacoes.toString(), rv.toString(), id, gatilho).redirectErrorStream(true).redirectOutput(saida.toFile()).start();
        filhos.add(p);
        saidas.add(saida);
        return p;
    }

    boolean chegou(String chave) {
        return Files.exists(rv.resolve("chegou-" + chave));
    }

    void soltar(String chave) {
        V02Processo.tocar(rv.resolve("vai-" + chave));
    }

    boolean esperarArquivo(String nome) {
        return V02Processo.esperar(rv.resolve(nome), TETO_MS);
    }

    String saida(int i) {
        try {
            return Files.exists(saidas.get(i)) ? Files.readString(saidas.get(i), StandardCharsets.UTF_8).strip() : "";
        } catch (IOException e) {
            return "";
        }
    }

    boolean estourou() {
        try (var s = Files.list(rv)) {
            return s.anyMatch(p -> p.getFileName().toString().startsWith("estourou-"));
        } catch (IOException e) {
            return false;
        }
    }

    /** Alguma conexão de alguma instância filha está esperando uma trava do servidor? Então a outra instância não vai chegar enquanto a primeira esperar. */
    boolean alguemEsperaTrava() {
        // só as instâncias deste cenário (o nome da aplicação leva o esquema do teste): outra avaliação no mesmo banco não pode liberar esta por engano
        try (Connection c = monitor.getConnection(); PreparedStatement ps = c.prepareStatement(
                "select count(*) from pg_stat_activity where application_name like ? and wait_event_type = 'Lock'")) {
            ps.setString(1, "leb-proc-" + esquema + "-%");
            ResultSet rs = ps.executeQuery();
            rs.next();
            return rs.getLong(1) > 0;
        } catch (java.sql.SQLException e) {
            return false;
        }
    }

    /**
     * Espera até que a instância esteja no gatilho, tenha terminado (não havia o que fazer) ou, só para quem não é a primeira, até que alguma instância esteja
     * provadamente esperando uma trava. Devolve false se o teto estourou.
     */
    boolean esperarChegada(String chave, java.util.function.BooleanSupplier terminou, boolean aceitaBloqueio) {
        long limite = System.nanoTime() + TETO_MS * 1_000_000L;
        while (System.nanoTime() < limite) {
            if (chegou(chave) || terminou.getAsBoolean()) return true;
            if (aceitaBloqueio && alguemEsperaTrava()) return true;
            try {
                Thread.sleep(5);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return false;
            }
        }
        return false;
    }

    void rodada(int r) {
        V02Processo.escreverAtomico(rv.resolve("rodada"), String.valueOf(r));
    }

    int porta(String id) {
        if (!esperarArquivo("porta-" + id)) return -1;
        try {
            return Integer.parseInt(Files.readString(rv.resolve("porta-" + id)).strip());
        } catch (IOException | NumberFormatException e) {
            return -1;
        }
    }

    @Override
    public void close() {
        V02Processo.tocar(rv.resolve("fim"));
        for (Process p : filhos) p.destroyForcibly();
    }
}
