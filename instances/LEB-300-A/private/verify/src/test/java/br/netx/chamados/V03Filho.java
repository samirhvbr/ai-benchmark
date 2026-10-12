package br.netx.chamados;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.time.Instant;

/** Processo-filho do verificador de memória: roda uma passada dos jobs num esquema já povoado, com o heap que o pai impôs. */
public final class V03Filho {
    private V03Filho() {}

    public static void main(String[] args) throws Exception {
        try {
            Path dir = Files.createTempDirectory("notificacoes-filho");
            TestBase.Relogio relogio = new TestBase.Relogio(Instant.parse("2026-03-04T13:00:00Z"));
            App app = App.create(new Config(0, false, Duration.ofSeconds(60), dir, Duration.ofHours(8)), new TestBase.DataSourceDoEsquema(args[0]), relogio);
            app.jobs().executarUmaVez();
            System.out.println("FILHO-OK");
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
}
