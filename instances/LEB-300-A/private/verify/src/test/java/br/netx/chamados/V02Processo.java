package br.netx.chamados;

import javax.sql.DataSource;
import java.io.PrintWriter;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.time.Duration;
import java.time.Instant;
import java.util.logging.Logger;

/**
 * Uma instância da aplicação num processo (JVM) à parte, para provar o que duas threads ou dois carregadores de classe não provam: uma trava que só existe
 * dentro de uma JVM não vale entre processos. O pai combina com o filho por arquivos numa pasta: o filho avisa que chegou ao comando-gatilho e só segue
 * quando o pai libera. Nenhum tempo decide nada; o teto (90 s) apenas evita esperar para sempre.
 *
 * modos: "jobs" (roda uma passada dos processamentos e sai) e "http" (sobe o servidor, avisa a porta e fica até a pasta ter o arquivo "fim").
 */
public final class V02Processo {
    private V02Processo() {}

    /** DataSource do esquema do teste, com um nome de aplicação que o pai usa para ver quem está esperando uma trava. */
    static final class FonteDoEsquema implements DataSource {
        private final String url;

        FonteDoEsquema(String esquema, String aplicacao) {
            String base = TestBase.BANCO;
            this.url = base + (base.contains("?") ? "&" : "?") + "currentSchema=" + esquema + "&ApplicationName=" + aplicacao;
        }

        @Override public Connection getConnection() throws SQLException { return DriverManager.getConnection(url, TestBase.USUARIO, TestBase.SENHA_BANCO); }
        @Override public Connection getConnection(String u, String p) throws SQLException { return DriverManager.getConnection(url, u, p); }
        @Override public PrintWriter getLogWriter() { return null; }
        @Override public void setLogWriter(PrintWriter out) { }
        @Override public void setLoginTimeout(int seconds) { }
        @Override public int getLoginTimeout() { return 0; }
        @Override public Logger getParentLogger() { return Logger.getGlobal(); }
        @Override public <T> T unwrap(Class<T> iface) throws SQLException { throw new SQLException("não é um wrapper"); }
        @Override public boolean isWrapperFor(Class<?> iface) { return false; }
    }

    public static void main(String[] a) {
        try {
            String modo = a[0], esquema = a[1], id = a[4], gatilho = a[5].toLowerCase();
            Path notificacoes = Path.of(a[2]), rv = Path.of(a[3]);
            Instrumento ins = new Instrumento(new FonteDoEsquema(esquema, "leb-proc-" + esquema + "-" + id));
            TestBase.Relogio relogio = new TestBase.Relogio(Instant.parse("2026-03-04T12:00:00Z"));
            App app = App.create(new Config(0, false, Duration.ofSeconds(60), notificacoes, Duration.ofHours(8)), ins.dataSource(), relogio);
            ins.gancho = (c, sql, args) -> {
                if (c.chegou || !sql.toLowerCase().contains(gatilho)) return;
                c.chegou = true;
                String chave = id + "-" + rodada(rv);
                tocar(rv.resolve("chegou-" + chave));
                if (!esperar(rv.resolve("vai-" + chave), 90_000)) tocar(rv.resolve("estourou-" + chave));
            };
            if (modo.equals("jobs")) {
                app.jobs().executarUmaVez();
                System.out.println("FILHO-OK");
                System.exit(0);
            }
            int porta = app.start();
            escreverAtomico(rv.resolve("porta-" + id), String.valueOf(porta));
            esperar(rv.resolve("fim"), 600_000);
            app.stop();
            System.exit(0);
        } catch (Throwable t) {
            StringBuilder cadeia = new StringBuilder();
            for (Throwable c = t; c != null && cadeia.length() < 4000; c = c.getCause()) {
                cadeia.append(c.getClass().getName()).append(": ").append(String.valueOf(c.getMessage()).replace('\n', ' '));
                StackTraceElement[] pilha = c.getStackTrace();
                for (int i = 0; i < Math.min(8, pilha.length); i++) cadeia.append(" @ ").append(pilha[i]);
                cadeia.append(" <- ");
            }
            System.out.println("FILHO-FALHOU " + cadeia);
            System.exit(3);
        }
    }

    /** A rodada em curso, escolhida pelo pai: o mesmo filho atende várias rodadas, cada uma com os seus arquivos de chegada e liberação. */
    static String rodada(Path rv) {
        try {
            return Files.exists(rv.resolve("rodada")) ? Files.readString(rv.resolve("rodada")).strip() : "0";
        } catch (java.io.IOException e) {
            return "0";
        }
    }

    /** Escreve num arquivo temporário e o move de uma vez: quem lê nunca vê o arquivo existindo e vazio. */
    static void escreverAtomico(Path arquivo, String texto) {
        try {
            Path tmp = arquivo.resolveSibling(arquivo.getFileName() + ".tmp");
            Files.writeString(tmp, texto);
            Files.move(tmp, arquivo, java.nio.file.StandardCopyOption.ATOMIC_MOVE, java.nio.file.StandardCopyOption.REPLACE_EXISTING);
        } catch (java.io.IOException e) {
            throw new java.io.UncheckedIOException(e);
        }
    }

    static void tocar(Path arquivo) {
        try {
            Files.writeString(arquivo, "x");
        } catch (java.io.IOException e) {
            throw new java.io.UncheckedIOException(e);
        }
    }

    /** Espera um arquivo aparecer. O polling não decide nada: só espera o estado mudar. */
    static boolean esperar(Path arquivo, long tetoMs) {
        long limite = System.nanoTime() + tetoMs * 1_000_000L;
        while (System.nanoTime() < limite) {
            if (Files.exists(arquivo)) return true;
            try {
                Thread.sleep(5);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return false;
            }
        }
        return false;
    }
}
