package com.condodesk;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.sun.net.httpserver.HttpServer;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import javax.sql.DataSource;
import java.io.ByteArrayOutputStream;
import java.io.PrintWriter;
import java.net.InetSocketAddress;
import java.net.URI;
import java.net.URLEncoder;
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
import java.time.LocalDate;
import java.time.ZoneId;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.logging.Logger;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Collectors;
import java.util.stream.Stream;

/**
 * Base of the characterization tests. They use only the public surface the manifest declares (App, Config, HTTP and the
 * database schema). Each test gets a new PostgreSQL schema made from schema.sql, a clock it moves by hand, two buildings with
 * their people and areas, a site on the fetch allowlist and an internal server that is not on it.
 */
abstract class TestBase {
    static final ObjectMapper JSON = new ObjectMapper();
    static final String DB_URL = System.getenv().getOrDefault("LEB_DB_URL", "jdbc:postgresql://127.0.0.1:5432/leb_dev");
    static final String DB_USER = System.getenv().getOrDefault("LEB_DB_USER", "leb_dev");
    static final String DB_PASSWORD = System.getenv().getOrDefault("LEB_DB_PASSWORD", "leb_dev_pw");
    static final String PASSWORD = "maple-court-2026";
    static final String BANK_SECRET = "test-bank-secret";
    static final ZoneId SAO_PAULO = ZoneId.of("America/Sao_Paulo");
    static final byte[] PDF = "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n".getBytes(StandardCharsets.US_ASCII);
    static final byte[] PNG = HexFormat.of().parseHex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d4944415478da63f8ffff3f0005fe02fea7d6a4c50000000049454e44ae426082");

    static final String ADMIN = "admin@condodesk.test", MANAGER_A = "manager@maple.test", MANAGER_B = "manager@cedar.test",
            OWNER_A101 = "ana@maple.test", TENANT_A102 = "bruno@maple.test", OWNER_A201 = "carla@maple.test", OWNER_B101 = "dora@cedar.test";

    /** A clock the test moves by hand. */
    static final class TestClock extends Clock {
        volatile Instant now;

        TestClock(Instant start) {
            this.now = start;
        }

        void advance(Duration d) {
            now = now.plus(d);
        }

        void set(Instant t) {
            now = t;
        }

        @Override public ZoneId getZone() { return ZoneId.of("UTC"); }
        @Override public Clock withZone(ZoneId zone) { return this; }
        @Override public Instant instant() { return now; }
    }

    /** A DataSource whose connections are already set to the test's schema. */
    static final class SchemaDataSource implements DataSource {
        private final String url;

        SchemaDataSource(String schema) {
            this.url = DB_URL + (DB_URL.contains("?") ? "&" : "?") + "currentSchema=" + schema;
        }

        @Override public Connection getConnection() throws SQLException { return DriverManager.getConnection(url, DB_USER, DB_PASSWORD); }
        @Override public Connection getConnection(String u, String p) throws SQLException { return DriverManager.getConnection(url, u, p); }
        @Override public PrintWriter getLogWriter() { return null; }
        @Override public void setLogWriter(PrintWriter out) { }
        @Override public void setLoginTimeout(int seconds) { }
        @Override public int getLoginTimeout() { return 0; }
        @Override public Logger getParentLogger() { return Logger.getGlobal(); }
        @Override public <T> T unwrap(Class<T> iface) throws SQLException { throw new SQLException("not a wrapper"); }
        @Override public boolean isWrapperFor(Class<?> iface) { return false; }
    }

    /** An HTTP response, with the JSON body read when there is one. */
    record R(int status, String body, byte[] bytes, java.net.http.HttpHeaders headers) {
        JsonNode json() {
            try {
                return JSON.readTree(body);
            } catch (Exception e) {
                throw new AssertionError("not JSON: " + body, e);
            }
        }

        String header(String name) {
            return headers.firstValue(name).orElse(null);
        }

        String location() {
            return header("Location");
        }
    }

    String schema;
    DataSource ds;
    TestClock clock;
    Path storage;
    App app;
    String base;
    HttpServer docsSite;
    HttpServer internal;
    String docsHost;
    String internalHost;
    final HttpClient http = HttpClient.newBuilder().followRedirects(HttpClient.Redirect.NEVER).build();

    long buildingA, buildingB, unitA101, unitA102, unitA201, unitB101, unitB102, partyRoom, barbecue, gym;
    long admin, managerA, managerB, ownerA101, tenantA102, ownerA201, ownerB101;

    @BeforeEach
    void setUpSystem() throws Exception {
        schema = "t_" + Long.toHexString(System.nanoTime()) + "_" + UUID.randomUUID().toString().substring(0, 6);
        try (Connection c = DriverManager.getConnection(DB_URL, DB_USER, DB_PASSWORD); Statement s = c.createStatement()) {
            s.execute("create schema " + schema);
        }
        ds = new SchemaDataSource(schema);
        exec(Files.readString(Path.of("schema.sql")));
        Path migrations = Path.of("migrations");
        if (Files.isDirectory(migrations)) {
            try (Stream<Path> files = Files.list(migrations)) {
                for (Path p : files.filter(x -> x.toString().endsWith(".sql")).sorted().toList()) exec(Files.readString(p));
            }
        }
        startSites();
        // Wednesday, 2026-03-04, 09:00 in São Paulo
        clock = new TestClock(Instant.parse("2026-03-04T12:00:00Z"));
        storage = Files.createTempDirectory("condodesk-docs");
        app = App.create(new Config(0, storage, false, Duration.ofMinutes(30), Set.of(docsHost), BANK_SECRET, Config.DEFAULT_MAX_UPLOAD),
                forTheApp(ds), clock);
        base = "http://127.0.0.1:" + app.start();
        seed();
    }

    /** A hook for tests that watch or interfere with the connections the application gets. */
    DataSource forTheApp(DataSource plain) {
        return plain;
    }

    @AfterEach
    void tearDownSystem() throws Exception {
        app.stop();
        docsSite.stop(0);
        internal.stop(0);
        try (Connection c = DriverManager.getConnection(DB_URL, DB_USER, DB_PASSWORD); Statement s = c.createStatement()) {
            s.execute("drop schema " + schema + " cascade");
        }
        try (Stream<Path> files = Files.walk(storage)) {
            for (Path p : files.sorted(Comparator.reverseOrder()).toList()) Files.deleteIfExists(p);
        }
    }

    private void seed() throws SQLException {
        buildingA = insert("insert into building (name, address, due_day) values ('Maple Court', '12 Maple Street', 10) returning id");
        buildingB = insert("insert into building (name, address, due_day) values ('Cedar House', '40 Cedar Avenue', 5) returning id");
        unitA101 = insert("insert into unit (building_id, label, ideal_fraction) values (?, 'Apt 101', 0.30) returning id", buildingA);
        unitA102 = insert("insert into unit (building_id, label, ideal_fraction) values (?, 'Apt 102', 0.30) returning id", buildingA);
        unitA201 = insert("insert into unit (building_id, label, ideal_fraction) values (?, 'Apt 201', 0.40) returning id", buildingA);
        unitB101 = insert("insert into unit (building_id, label, ideal_fraction) values (?, 'Apt 101', 0.50) returning id", buildingB);
        unitB102 = insert("insert into unit (building_id, label, ideal_fraction) values (?, 'Apt 102', 0.50) returning id", buildingB);
        admin = user(ADMIN, "Alice Admin", "ADMIN", null);
        managerA = user(MANAGER_A, "Marcos Manager", "MANAGER", buildingA);
        managerB = user(MANAGER_B, "Mia Manager", "MANAGER", buildingB);
        ownerA101 = resident(OWNER_A101, "Ana Owner", buildingA, unitA101, "OWNER");
        tenantA102 = resident(TENANT_A102, "Bruno Tenant", buildingA, unitA102, "TENANT");
        ownerA201 = resident(OWNER_A201, "Carla Owner", buildingA, unitA201, "OWNER");
        ownerB101 = resident(OWNER_B101, "Dora Owner", buildingB, unitB101, "OWNER");
        partyRoom = insert("insert into common_area (building_id, name, opens, closes, max_hours, capacity, fee) "
                + "values (?, 'Party room', '10:00', '22:00', 6, 40, 50.00) returning id", buildingA);
        barbecue = insert("insert into common_area (building_id, name, opens, closes, max_hours, capacity, fee) "
                + "values (?, 'Barbecue', '09:00', '21:00', 4, 20, 0) returning id", buildingA);
        gym = insert("insert into common_area (building_id, name, opens, closes, max_hours, capacity, fee) "
                + "values (?, 'Gym', '06:00', '23:00', 2, 8, 0) returning id", buildingB);
    }

    /** An account imported from the previous system: unsalted SHA-256, which the application upgrades at login. */
    long user(String email, String name, String role, Long building) throws SQLException {
        return insert("insert into app_user (email, name, role, building_id, password_hash, created_at) values (?, ?, ?, ?, ?, ?) returning id",
                email, name, role, building, "sha256:" + sha256Hex(PASSWORD), Instant.parse("2025-01-01T00:00:00Z"));
    }

    long resident(String email, String name, long building, long unit, String kind) throws SQLException {
        long id = user(email, name, "RESIDENT", building);
        update("insert into residency (user_id, unit_id, kind) values (?, ?, ?)", id, unit, kind);
        return id;
    }

    // ---- the two sites the fetch feature talks to

    private void startSites() throws Exception {
        docsSite = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        docsSite.createContext("/", ex -> {
            String path = ex.getRequestURI().getPath();
            byte[] body;
            int status = 200;
            switch (path) {
                case "/files/minutes-2026-02.pdf" -> body = PDF;
                case String p when p.startsWith("/files/report") -> body = PDF;
                case "/files/notes.txt" -> body = "plain text".getBytes(StandardCharsets.UTF_8);
                case "/files/moved.pdf" -> {
                    ex.getResponseHeaders().add("Location", "http://" + internalHost + "/secret.pdf");
                    status = 302;
                    body = new byte[0];
                }
                default -> {
                    status = 404;
                    body = new byte[0];
                }
            }
            ex.sendResponseHeaders(status, body.length == 0 ? -1 : body.length);
            if (body.length > 0) ex.getResponseBody().write(body);
            ex.close();
        });
        docsSite.start();
        internal = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        internal.createContext("/", ex -> {
            byte[] body = ("%PDF-1.4 internal secret " + ex.getRequestURI().getPath()).getBytes(StandardCharsets.US_ASCII);
            ex.sendResponseHeaders(200, body.length);
            ex.getResponseBody().write(body);
            ex.close();
        });
        internal.start();
        docsHost = "127.0.0.1:" + docsSite.getAddress().getPort();
        internalHost = "127.0.0.1:" + internal.getAddress().getPort();
    }

    // ---- database

    void exec(String sql) throws SQLException {
        try (Connection c = ds.getConnection(); Statement s = c.createStatement()) {
            s.execute(sql);
        }
    }

    long insert(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            bind(ps, args);
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1);
            }
        }
    }

    int update(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            bind(ps, args);
            return ps.executeUpdate();
        }
    }

    List<Map<String, Object>> rows(String sql, Object... args) throws SQLException {
        try (Connection c = ds.getConnection(); var ps = c.prepareStatement(sql)) {
            bind(ps, args);
            try (ResultSet rs = ps.executeQuery()) {
                List<Map<String, Object>> out = new ArrayList<>();
                int n = rs.getMetaData().getColumnCount();
                while (rs.next()) {
                    Map<String, Object> row = new LinkedHashMap<>();
                    for (int i = 1; i <= n; i++) row.put(rs.getMetaData().getColumnLabel(i), rs.getObject(i));
                    out.add(row);
                }
                return out;
            }
        }
    }

    Object value(String sql, Object... args) throws SQLException {
        List<Map<String, Object>> r = rows(sql, args);
        return r.isEmpty() ? null : r.get(0).values().iterator().next();
    }

    long count(String sql, Object... args) throws SQLException {
        return ((Number) value(sql, args)).longValue();
    }

    private static void bind(java.sql.PreparedStatement ps, Object[] args) throws SQLException {
        for (int i = 0; i < args.length; i++) {
            Object v = args[i];
            if (v instanceof Instant t) ps.setTimestamp(i + 1, Timestamp.from(t));
            else if (v instanceof LocalDate d) ps.setDate(i + 1, java.sql.Date.valueOf(d));
            else ps.setObject(i + 1, v);
        }
    }

    /** An open invoice inserted directly, for tests that need one without a billing run. */
    long invoice(long unit, String month, LocalDate due, String amount) throws SQLException {
        long building = (Long) value("select building_id from unit where id = ?", unit);
        long run = rows("select id from billing_run where building_id = ? and month = ?", building, month).stream()
                .map(r -> ((Number) r.get("id")).longValue()).findFirst().orElse(-1L);
        if (run < 0) {
            run = insert("insert into billing_run (building_id, month, budget, created_by, created_at) values (?, ?, 1000, ?, ?) returning id",
                    building, month, admin, clock.instant());
        }
        String our = month.replace("-", "") + "-" + String.format("%04d-%06d", building, unit);
        return insert("insert into invoice (run_id, unit_id, month, due_date, amount, our_number, status) values (?, ?, ?, ?, ?::numeric, ?, 'OPEN') returning id",
                run, unit, month, due, amount, our);
    }

    // ---- browsers and API clients

    Browser browser() {
        return new Browser();
    }

    Browser loggedIn(String email) throws Exception {
        Browser b = new Browser();
        b.login(email, PASSWORD);
        return b;
    }

    /** A browser: keeps its cookies and the CSRF token of the last page that had a form. */
    final class Browser {
        final Map<String, String> cookies = new LinkedHashMap<>();
        String csrf;

        R get(String path) throws Exception {
            return send(HttpRequest.newBuilder(URI.create(base + path)).GET());
        }

        R post(String path, String... fields) throws Exception {
            Map<String, String> form = new LinkedHashMap<>();
            if (csrf != null) form.put("_csrf", csrf);
            for (int i = 0; i + 1 < fields.length; i += 2) form.put(fields[i], fields[i + 1]);
            return postForm(path, form);
        }

        R postForm(String path, Map<String, String> form) throws Exception {
            String body = form.entrySet().stream().map(e -> enc(e.getKey()) + "=" + enc(e.getValue())).collect(Collectors.joining("&"));
            return send(HttpRequest.newBuilder(URI.create(base + path)).header("Content-Type", "application/x-www-form-urlencoded")
                    .POST(HttpRequest.BodyPublishers.ofString(body)));
        }

        /** A multipart form with one file; the CSRF token goes along unless {@code withCsrf} is false. */
        R upload(String path, String fileField, String fileName, byte[] data, String... fields) throws Exception {
            String boundary = "----condodesk" + UUID.randomUUID();
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            List<String> all = new ArrayList<>();
            if (csrf != null) all.addAll(List.of("_csrf", csrf));
            all.addAll(List.of(fields));
            for (int i = 0; i + 1 < all.size(); i += 2) {
                out.writeBytes(("--" + boundary + "\r\nContent-Disposition: form-data; name=\"" + all.get(i) + "\"\r\n\r\n" + all.get(i + 1) + "\r\n")
                        .getBytes(StandardCharsets.UTF_8));
            }
            out.writeBytes(("--" + boundary + "\r\nContent-Disposition: form-data; name=\"" + fileField + "\"; filename=\"" + fileName
                    + "\"\r\nContent-Type: application/octet-stream\r\n\r\n").getBytes(StandardCharsets.UTF_8));
            out.writeBytes(data);
            out.writeBytes(("\r\n--" + boundary + "--\r\n").getBytes(StandardCharsets.UTF_8));
            return send(HttpRequest.newBuilder(URI.create(base + path)).header("Content-Type", "multipart/form-data; boundary=" + boundary)
                    .POST(HttpRequest.BodyPublishers.ofByteArray(out.toByteArray())));
        }

        void login(String email, String password) throws Exception {
            get("/login");
            R r = post("/login", "email", email, "password", password);
            if (r.status() != 303) throw new IllegalStateException("login of " + email + " failed: " + r.status() + " " + r.body());
            get("/");
        }

        String session() {
            return cookies.get("CDSESSION");
        }

        R send(HttpRequest.Builder b) throws Exception {
            if (!cookies.isEmpty()) {
                b.header("Cookie", cookies.entrySet().stream().map(e -> e.getKey() + "=" + e.getValue()).collect(Collectors.joining("; ")));
            }
            HttpResponse<byte[]> resp = http.send(b.build(), HttpResponse.BodyHandlers.ofByteArray());
            for (String c : resp.headers().allValues("Set-Cookie")) {
                String pair = c.split(";", 2)[0];
                int eq = pair.indexOf('=');
                String name = pair.substring(0, eq).trim(), value = pair.substring(eq + 1).trim();
                if (value.isEmpty() || c.contains("Max-Age=0")) cookies.remove(name);
                else cookies.put(name, value);
            }
            String body = new String(resp.body(), StandardCharsets.UTF_8);
            Matcher m = CSRF.matcher(body);
            if (m.find()) csrf = m.group(1);
            return new R(resp.statusCode(), body, resp.body(), resp.headers());
        }
    }

    private static final Pattern CSRF = Pattern.compile("name=\"_csrf\" value=\"([^\"]+)\"");

    String token(String email) throws Exception {
        R r = api("POST", "/api/tokens", null, Map.of("email", email, "password", PASSWORD));
        if (r.status() != 201) throw new IllegalStateException("token for " + email + " failed: " + r.body());
        return r.json().get("token").asText();
    }

    R api(String method, String path, String token, Object body, String... headers) throws Exception {
        HttpRequest.Builder b = HttpRequest.newBuilder(URI.create(base + path));
        if (token != null) b.header("Authorization", "Bearer " + token);
        for (int i = 0; i + 1 < headers.length; i += 2) b.header(headers[i], headers[i + 1]);
        byte[] bytes = body == null ? null : body instanceof byte[] raw ? raw : JSON.writeValueAsBytes(body);
        b.method(method, bytes == null ? HttpRequest.BodyPublishers.noBody() : HttpRequest.BodyPublishers.ofByteArray(bytes));
        if (bytes != null) b.header("Content-Type", "application/json");
        HttpResponse<byte[]> resp = http.send(b.build(), HttpResponse.BodyHandlers.ofByteArray());
        return new R(resp.statusCode(), new String(resp.body(), StandardCharsets.UTF_8), resp.body(), resp.headers());
    }

    /** The bank's webhook, signed as the bank signs it. */
    R webhook(String eventId, String ourNumber, String amount, String paidOn) throws Exception {
        byte[] body = JSON.writeValueAsBytes(Map.of("event_id", eventId, "our_number", ourNumber, "amount", amount, "paid_on", paidOn));
        return api("POST", "/api/webhooks/bank", null, body, "X-Bank-Signature", sign(body));
    }

    static String sign(byte[] body) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(BANK_SECRET.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return "sha256=" + HexFormat.of().formatHex(mac.doFinal(body));
    }

    /** An OFX 2 statement with the given credits: each one is {FITID, date yyyymmdd, amount, memo}. */
    static byte[] ofx(String[]... credits) {
        StringBuilder s = new StringBuilder("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?OFX OFXHEADER=\"200\" VERSION=\"220\"?>\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n");
        for (String[] c : credits) {
            s.append("<STMTTRN><TRNTYPE>").append(c.length > 4 ? c[4] : "CREDIT").append("</TRNTYPE><DTPOSTED>").append(c[1])
                    .append("120000</DTPOSTED><TRNAMT>").append(c[2]).append("</TRNAMT><FITID>").append(c[0]).append("</FITID><MEMO>")
                    .append(c[3]).append("</MEMO></STMTTRN>\n");
        }
        return s.append("</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>\n").toString().getBytes(StandardCharsets.UTF_8);
    }

    static String sha256Hex(String text) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(text.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception e) {
            throw new IllegalStateException(e);
        }
    }

    static String enc(String s) {
        return URLEncoder.encode(s, StandardCharsets.UTF_8);
    }
}
