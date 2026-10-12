package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.Statement;
import java.time.Duration;
import java.time.Instant;
import java.util.Map;

/** Falhas de arquitetura que só aparecem executando: o que a configuração manda e duas aplicações na mesma JVM. */
class V04_ConfiguracaoTest extends VBase {

    @Test
    @Verifica({"ARCH-014.a"})
    void arch014a_aConfiguracaoManda() throws Exception {
        // tokenValidade: um token de 1 minuto não vale 2 minutos depois
        app.stop();
        app = App.create(new Config(0, false, Duration.ofSeconds(60), notificacoes, Duration.ofMinutes(1)), paraAApp(ds), relogio);
        raiz = "http://localhost:" + app.start();
        String t = token(ATENDENTE_A);
        boolean valeAgora = get("/chamados", t).status() == 200;
        relogio.avancar(Duration.ofMinutes(2));
        boolean expirou = get("/chamados", t).status() == 401;
        boolean validade = valeAgora && expirou;

        // intervaloJobs: com 200 ms o despacho roda sozinho e entrega a notificação; o teto de 20 s só serve para não esperar para sempre
        app.stop();
        atualizar("delete from notificacao");
        notificacoesPendentes(1);
        relogio.avancar(Duration.ofMinutes(1));
        app = App.create(new Config(0, true, Duration.ofMillis(200), notificacoes, Duration.ofHours(8)), paraAApp(ds), relogio);
        raiz = "http://localhost:" + app.start();
        long limite = System.nanoTime() + 20_000_000_000L;
        while (contar("select count(*) from notificacao where estado = 'PENDENTE'") > 0 && System.nanoTime() < limite) Thread.sleep(50);   // espera por estado, com teto
        boolean intervalo = contar("select count(*) from notificacao where estado = 'PENDENTE'") == 0;

        String resultado = validade && intervalo ? "full" : validade || intervalo ? "half" : "none";
        Resultado.r("ARCH-014.a", resultado, "tokenValidade de 1 minuto expira o token em 2 minutos: " + validade + "; intervaloJobs de 200 ms entrega sozinho dentro de 20 s: " + intervalo);
    }

    @Test
    @Verifica({"ARCH-013"})
    void arch013_duasAplicacoesNaMesmaJvmSaoIndependentes() throws Exception {
        String schema2 = "t_" + Long.toHexString(System.nanoTime()) + "b";
        try (Connection c = DriverManager.getConnection(BANCO, USUARIO, SENHA_BANCO); Statement s = c.createStatement()) {
            s.execute("create schema " + schema2);
        }
        App app2 = null;
        try {
            DataSourceDoEsquema ds2 = new DataSourceDoEsquema(schema2);
            try (Connection c = ds2.getConnection(); Statement s = c.createStatement()) {
                s.execute(Files.readString(Path.of("schema.sql")));
                Path migracoes = Path.of("migrations");
                if (Files.isDirectory(migracoes)) {
                    try (var arquivos = Files.list(migracoes)) {
                        for (Path p : arquivos.filter(x -> x.toString().endsWith(".sql")).sorted().toList()) s.execute(Files.readString(p));
                    }
                }
            }
            Relogio relogio2 = new Relogio(Instant.parse("2026-06-01T12:00:00Z"));
            app2 = App.create(new Config(0, false, Duration.ofSeconds(60), Files.createTempDirectory("notificacoes2"), Duration.ofHours(8)), ds2, relogio2);
            String raiz2 = "http://localhost:" + app2.start();

            // a primeira aplicação continua vendo o próprio banco
            R login1 = post("/auth/login", null, Map.of("email", ATENDENTE_A, "senha", SENHA));
            boolean primeira = login1.status() == 200;
            // a segunda tem o banco vazio: o usuário da primeira não existe lá
            String raizAntes = raiz;
            raiz = raiz2;
            R login2 = post("/auth/login", null, Map.of("email", ATENDENTE_A, "senha", SENHA));
            raiz = raizAntes;
            boolean segunda = login2.status() == 401;
            Resultado.r("ARCH-013", primeira && segunda ? "full" : "none",
                    "com duas aplicações na JVM, o login do usuário da primeira devolve " + login1.status() + " nela (esperado 200) e " + login2.status() + " na segunda, de banco vazio (esperado 401)");
        } finally {
            if (app2 != null) app2.stop();
            try (Connection c = DriverManager.getConnection(BANCO, USUARIO, SENHA_BANCO); Statement s = c.createStatement()) {
                s.execute("drop schema " + schema2 + " cascade");
            }
        }
    }
}
