package br.netx.chamados;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;

import javax.sql.DataSource;
import java.io.PrintWriter;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Statement;
import java.sql.Timestamp;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneId;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.logging.Logger;
import java.util.stream.Stream;

/**
 * Base dos testes de caracterização. Usa só a superfície pública declarada no manifesto (App, Config, JobRunner), o HTTP e o banco.
 * Cada teste ganha um esquema novo do PostgreSQL, criado a partir de schema.sql (e de migrations/, se existir).
 */
abstract class TestBase {
    static final ObjectMapper JSON = new ObjectMapper();
    static final String BANCO = System.getenv().getOrDefault("LEB_DB_URL", "jdbc:postgresql://127.0.0.1:5432/leb_dev");
    static final String USUARIO = System.getenv().getOrDefault("LEB_DB_USER", "leb_dev");
    static final String SENHA_BANCO = System.getenv().getOrDefault("LEB_DB_PASSWORD", "leb_dev_pw");
    static final String SENHA = "senha-segura-1";

    /** Relógio que o teste move à vontade. */
    static final class Relogio extends Clock {
        volatile Instant agora;

        Relogio(Instant inicio) {
            this.agora = inicio;
        }

        void avancar(Duration d) {
            agora = agora.plus(d);
        }

        @Override public ZoneId getZone() { return ZoneId.of("UTC"); }
        @Override public Clock withZone(ZoneId zone) { return this; }
        @Override public Instant instant() { return agora; }
    }

    /** DataSource que abre conexões já posicionadas no esquema do teste. */
    static final class DataSourceDoEsquema implements DataSource {
        private final String url;

        DataSourceDoEsquema(String schema) {
            this.url = BANCO + (BANCO.contains("?") ? "&" : "?") + "currentSchema=" + schema;
        }

        @Override public Connection getConnection() throws SQLException { return DriverManager.getConnection(url, USUARIO, SENHA_BANCO); }
        @Override public Connection getConnection(String u, String p) throws SQLException { return DriverManager.getConnection(url, u, p); }
        @Override public PrintWriter getLogWriter() { return null; }
        @Override public void setLogWriter(PrintWriter out) { }
        @Override public void setLoginTimeout(int seconds) { }
        @Override public int getLoginTimeout() { return 0; }
        @Override public Logger getParentLogger() { return Logger.getGlobal(); }
        @Override public <T> T unwrap(Class<T> iface) throws SQLException { throw new SQLException("não é um wrapper"); }
        @Override public boolean isWrapperFor(Class<?> iface) { return false; }
    }

    record R(int status, JsonNode corpo, String bruto, java.net.http.HttpHeaders cabecalhos) {
        JsonNode j(String campo) { return corpo.get(campo); }
    }

    String schema;
    DataSource ds;
    Relogio relogio;
    Path notificacoes;
    App app;
    String raiz;
    final HttpClient http = HttpClient.newHttpClient();

    // cenário padrão: duas empresas, uma plataforma
    long empA, empB, admA, atdA, tecUsuarioA, admB, plat, cliA, cliB, tecA;
    static final String ADMIN_A = "admin@a.test", ATENDENTE_A = "atendente@a.test", TECNICO_A = "tecnico@a.test", ADMIN_B = "admin@b.test", PLATAFORMA = "plataforma@netx.test";

    @BeforeEach
    void preparar() throws Exception {
        schema = "t_" + Long.toHexString(System.nanoTime());
        try (Connection c = DriverManager.getConnection(BANCO, USUARIO, SENHA_BANCO); Statement s = c.createStatement()) {
            s.execute("create schema " + schema);
        }
        ds = new DataSourceDoEsquema(schema);
        executar(Files.readString(Path.of("schema.sql")));
        Path migracoes = Path.of("migrations");
        if (Files.isDirectory(migracoes)) {
            try (Stream<Path> arquivos = Files.list(migracoes)) {
                for (Path p : arquivos.filter(x -> x.toString().endsWith(".sql")).sorted().toList()) executar(Files.readString(p));
            }
        }
        // quarta-feira, 09:00 em São Paulo
        relogio = new Relogio(Instant.parse("2026-03-04T12:00:00Z"));
        notificacoes = Files.createTempDirectory("notificacoes");
        app = App.create(new Config(0, false, Duration.ofSeconds(60), notificacoes, Duration.ofHours(8)), paraAApp(ds), relogio);
        raiz = "http://localhost:" + app.start();

        empA = inserir("insert into empresa (nome, fuso_horario) values ('Alfa', 'America/Sao_Paulo') returning id");
        empB = inserir("insert into empresa (nome, fuso_horario) values ('Beta', 'America/Sao_Paulo') returning id");
        admA = usuario(empA, ADMIN_A, "ADMIN");
        atdA = usuario(empA, ATENDENTE_A, "ATENDENTE");
        tecUsuarioA = usuario(empA, TECNICO_A, "TECNICO");
        admB = usuario(empB, ADMIN_B, "ADMIN");
        plat = usuario(null, PLATAFORMA, "PLATAFORMA");
        cliA = inserir("insert into cliente (empresa_id, nome) values (?, 'Cliente A') returning id", empA);
        cliB = inserir("insert into cliente (empresa_id, nome) values (?, 'Cliente B') returning id", empB);
        tecA = inserir("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, 'Tecnico A') returning id", empA, tecUsuarioA);
    }

    /** Gancho para quem precisa observar ou interferir nas conexões que a aplicação recebe. */
    DataSource paraAApp(DataSource base) {
        return base;
    }

    @AfterEach
    void encerrar() throws Exception {
        app.stop();
        try (Connection c = DriverManager.getConnection(BANCO, USUARIO, SENHA_BANCO); Statement s = c.createStatement()) {
            s.execute("drop schema " + schema + " cascade");
        }
    }

    // ---- banco

    void executar(String sql) throws SQLException {
        try (Connection c = ds.getConnection(); Statement s = c.createStatement()) {
            s.execute(sql);
        }
    }

    long inserir(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            ligar(ps, args);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1);
            }
        }
    }

    void atualizar(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            ligar(ps, args);
            ps.executeUpdate();
        }
    }

    List<Map<String, Object>> consultar(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            ligar(ps, args);
            try (ResultSet rs = ps.executeQuery()) {
                List<Map<String, Object>> linhas = new ArrayList<>();
                int n = rs.getMetaData().getColumnCount();
                while (rs.next()) {
                    Map<String, Object> linha = new LinkedHashMap<>();
                    for (int i = 1; i <= n; i++) linha.put(rs.getMetaData().getColumnLabel(i), rs.getObject(i));
                    linhas.add(linha);
                }
                return linhas;
            }
        }
    }

    long contar(String sql, Object... args) throws SQLException {
        return ((Number) consultar(sql, args).get(0).values().iterator().next()).longValue();
    }

    private static void ligar(java.sql.PreparedStatement ps, Object[] args) throws SQLException {
        for (int i = 0; i < args.length; i++) {
            Object v = args[i];
            if (v instanceof Instant t) ps.setTimestamp(i + 1, Timestamp.from(t));
            else ps.setObject(i + 1, v);
        }
    }

    long usuario(Long empresa, String email, String papel) throws SQLException {
        String hash = "sha256:" + HexFormat.of().formatHex(sha256(SENHA));
        return inserir("insert into usuario (empresa_id, email, nome, papel, senha_hash) values (?, ?, ?, ?, ?) returning id", empresa, email, email, papel, hash);
    }

    private static byte[] sha256(String texto) {
        try {
            return MessageDigest.getInstance("SHA-256").digest(texto.getBytes(StandardCharsets.UTF_8));
        } catch (java.security.NoSuchAlgorithmException e) {
            throw new IllegalStateException(e);
        }
    }

    // ---- HTTP

    R chamar(String metodo, String caminho, String token, Object corpo, String... cabecalhos) throws Exception {
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create(raiz + caminho));
        if (token != null) b.header("Authorization", "Bearer " + token);
        for (int i = 0; i + 1 < cabecalhos.length; i += 2) b.header(cabecalhos[i], cabecalhos[i + 1]);
        String texto = corpo == null ? null : corpo instanceof String s ? s : JSON.writeValueAsString(corpo);
        b.method(metodo, texto == null ? HttpRequest.BodyPublishers.noBody() : HttpRequest.BodyPublishers.ofString(texto));
        if (texto != null) b.header("Content-Type", "application/json");
        HttpResponse<String> resp = http.send(b.build(), HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
        JsonNode json = null;
        String ct = resp.headers().firstValue("Content-Type").orElse("");
        if (!resp.body().isEmpty() && ct.startsWith("application/json")) json = JSON.readTree(resp.body());
        return new R(resp.statusCode(), json, resp.body(), resp.headers());
    }

    R get(String caminho, String token) throws Exception { return chamar("GET", caminho, token, null); }
    R post(String caminho, String token, Object corpo, String... cabecalhos) throws Exception { return chamar("POST", caminho, token, corpo, cabecalhos); }
    R patch(String caminho, String token, Object corpo) throws Exception { return chamar("PATCH", caminho, token, corpo); }

    String token(String email) throws Exception {
        R r = post("/auth/login", null, Map.of("email", email, "senha", SENHA));
        if (r.status() != 200) throw new IllegalStateException("login de " + email + " falhou: " + r.bruto());
        return r.j("token").asText();
    }

    long abrirChamado(String token, long cliente, String titulo, String prioridade) throws Exception {
        Map<String, Object> corpo = new LinkedHashMap<>();
        corpo.put("cliente_id", cliente);
        corpo.put("titulo", titulo);
        if (prioridade != null) corpo.put("prioridade", prioridade);
        R r = post("/chamados", token, corpo);
        if (r.status() != 201) throw new IllegalStateException("abertura falhou: " + r.bruto());
        return r.j("id").asLong();
    }

    long criarOrdem(String token, long chamado, long tecnico) throws Exception {
        R r = post("/chamados/" + chamado + "/ordens", token, Map.of("tecnico_id", tecnico, "tipo", "VISITA", "agendada_para", "2026-03-05T13:00:00Z"));
        if (r.status() != 201) throw new IllegalStateException("ordem falhou: " + r.bruto());
        return r.j("id").asLong();
    }

    /** Insere um chamado direto no banco, com os instantes que o teste quiser. */
    long chamadoNoBanco(long empresa, long cliente, long criador, String prioridade, String status, Instant aberto, Instant prazo, Instant resolvido) throws SQLException {
        return inserir("insert into chamado (empresa_id, cliente_id, titulo, prioridade, status, aberto_em, prazo_resposta, prazo_resolucao, resolvido_em, criado_por) "
                + "values (?, ?, 'Chamado direto', ?, ?, ?, ?, ?, ?, ?) returning id",
                empresa, cliente, prioridade, status, aberto, prazo, prazo, resolvido, criador);
    }
}
