package com.condodesk;

import org.junit.jupiter.api.Test;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.io.DataInputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.net.InetAddress;
import java.net.ServerSocket;
import java.net.Socket;
import java.net.URI;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.time.LocalDate;
import java.util.HashMap;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.concurrent.atomic.AtomicReference;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Secrets that come from the environment only: the bank's (by behavior) and the database's (by a property of the source). */
class S03_SecretsTest extends VBase {
    static final String PLANTED_BANK_SECRET = "cd-bank-7f3a91c2";

    private static boolean unset(String secret) {
        return secret == null || secret.isEmpty();
    }

    @Test
    @Verifies({"SEC-015"})
    void sec015_theBankSecretHasNoDefault() throws Exception {
        long id = invoice(unitA101, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        String our = (String) value("select our_number from invoice where id = ?", id);
        Config bare = Config.fromEnvironment(Map.of("CONDODESK_PORT", "0", "CONDODESK_STORAGE", storage.toString()));   // CONDODESK_BANK_SECRET is not set
        App other = App.create(bare, ds, clock);
        int status;
        try {
            int port = other.start();
            byte[] body = JSON.writeValueAsBytes(Map.of("event_id", "forged-1", "our_number", our, "amount", "300.00", "paid_on", "2026-03-05"));
            // a secret that is empty is as good as none (the reference answers 503 for it); the event is signed with the secret the planted code carried, the one a visitor of the source would use
            String signature = sign(unset(bare.bankSecret()) ? PLANTED_BANK_SECRET : bare.bankSecret(), body);
            HttpResponse<byte[]> r = http.send(HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + "/api/webhooks/bank"))
                    .header("X-Bank-Signature", signature).header("Content-Type", "application/json").POST(HttpRequest.BodyPublishers.ofByteArray(body)).build(),
                    HttpResponse.BodyHandlers.ofByteArray());
            status = r.statusCode();
        } finally {
            other.stop();
        }
        boolean forged = "PAID".equals(value("select status from invoice where id = ?", id));
        boolean closed = unset(bare.bankSecret()) && status == 503 && !forged;
        String set = Config.fromEnvironment(Map.of("CONDODESK_BANK_SECRET", "s3cret-from-env")).bankSecret();
        long legitId = invoice(unitA102, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        int legit = webhook("legit-1", (String) value("select our_number from invoice where id = ?", legitId), "300.00", "2026-03-05").status();
        Verdict.fixed("SEC-015", closed && "s3cret-from-env".equals(set) && legit == 200, "with CONDODESK_BANK_SECRET unset the configuration holds "
                + (unset(bare.bankSecret()) ? "no secret" : "a secret") + " and an event signed with it answered " + status + (forged ? " and settled the invoice" : "")
                + " (expected no secret, 503 and no settlement); a configured secret is read from the environment: " + "s3cret-from-env".equals(set) + "; a correctly signed event answered "
                + legit + " (expected 200)");
    }

    /**
     * What the application does with no database password in its environment, by running it: its main method in a child JVM, pointed at a stand-in for the database that asks for a clear-text
     * password and records what the client sends. With the variable absent nothing may be sent (a client with no password sends none); with the variable set to a value, that value must arrive.
     * Moving the literal, splitting it, reading it from another place or turning it into a default of another call all show up as a password the environment never gave.
     */
    @Test
    @Verifies({"SEC-015.b"})
    void sec015b_theDatabasePasswordHasNoDefault() throws Exception {
        String absent = passwordSentWith(Map.of());
        String absentLog = lastRun;
        String given = passwordSentWith(Map.of("CONDODESK_DB_PASSWORD", "from-the-environment-7"));
        // an empty password is no secret (as an empty bank secret is none): it is what a program with no password configured may send
        boolean closed = absent == null || absent.isEmpty();
        Verdict.fixed("SEC-015.b", closed && "from-the-environment-7".equals(given), "[no variable: " + absentLog + "] [variable set: " + lastRun + "] started with no CONDODESK_DB_PASSWORD, the application sent the database "
                + (closed ? "no password" : "a password of " + absent.length() + " characters (" + absent.charAt(0) + "…)") + " (expected none); started with it set, it sent "
                + (given == null ? "none" : "a password that " + ("from-the-environment-7".equals(given) ? "is the one given" : "is not the one given")) + " (expected the one given)");
    }

    private String lastRun = "";

    private String passwordSentWith(Map<String, String> extra) throws Exception {
        AtomicReference<String> password = new AtomicReference<>();
        try (ServerSocket stub = new ServerSocket(0, 5, InetAddress.getLoopbackAddress())) {
            Thread acceptor = new Thread(() -> {
                while (!stub.isClosed()) {
                    try {
                        Socket s = stub.accept();
                        Thread t = new Thread(() -> askForAPassword(s, password));
                        t.setDaemon(true);
                        t.start();
                    } catch (IOException e) {
                        return;
                    }
                }
            });
            acceptor.setDaemon(true);
            acceptor.start();
            Path home = Files.createTempDirectory("leb-app");
            Path output = Files.createTempFile("leb-app-out", ".txt");
            ProcessBuilder pb = new ProcessBuilder(Path.of(System.getProperty("java.home"), "bin", "java").toString(), "-cp",
                    System.getProperty("surefire.test.class.path", System.getProperty("java.class.path")), "com.condodesk.App")
                    .redirectErrorStream(true).redirectOutput(output.toFile());
            Map<String, String> env = new HashMap<>(Map.of("CONDODESK_DB_URL", "jdbc:postgresql://127.0.0.1:" + stub.getLocalPort() + "/stub", "CONDODESK_DB_USER", "stub",
                    "CONDODESK_PORT", "0", "CONDODESK_STORAGE", home.toString(), "CONDODESK_BANK_SECRET", "unused"));
            env.putAll(extra);
            pb.environment().clear();
            pb.environment().putAll(env);
            Process p = pb.start();
            String reached = "the application never said it was listening";
            try {
                int port = -1;
                for (int i = 0; i < 80 && port < 0 && p.isAlive(); i++) {
                    Thread.sleep(100);
                    Matcher m = Pattern.compile("listening on port (\\d+)").matcher(Files.readString(output));
                    if (m.find()) port = Integer.parseInt(m.group(1));
                }
                if (port > 0) {              // the application opens its database connection when a request needs it: a sign-in does
                    try {
                        HttpResponse<Void> r = http.send(HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + "/api/tokens")).timeout(Duration.ofSeconds(8))
                                .header("Content-Type", "application/json").POST(HttpRequest.BodyPublishers.ofString("{\"email\":\"someone@maple.test\",\"password\":\"x\"}")).build(),
                                HttpResponse.BodyHandlers.discarding());
                        reached = "a sign-in answered " + r.statusCode();
                    } catch (Exception e) {
                        reached = "a sign-in failed with " + e.getClass().getSimpleName();
                    }
                    Thread.sleep(300);       // the stand-in may still be reading the last message
                }
            } finally {
                p.destroyForcibly();
            }
            lastRun = reached;
        }
        return password.get();
    }

    /** The stand-in's side of one connection: refuse encryption, ask for a clear-text password, and keep what comes back. */
    private static void askForAPassword(Socket s, AtomicReference<String> password) {
        try (s) {
            DataInputStream in = new DataInputStream(s.getInputStream());
            OutputStream out = s.getOutputStream();
            while (true) {
                int length = in.readInt();
                int code = in.readInt();
                in.readFully(new byte[length - 8]);
                if (code == 80877103 || code == 80877104) {          // a request for SSL or GSS encryption: not here
                    out.write('N');
                    out.flush();
                    continue;
                }
                break;                                               // the start-up message
            }
            out.write(new byte[]{'R', 0, 0, 0, 8, 0, 0, 0, 3});      // AuthenticationCleartextPassword
            out.flush();
            if (in.read() == 'p') {                                  // a client with no password ends the connection instead
                byte[] data = new byte[in.readInt() - 4];
                in.readFully(data);
                password.set(new String(data, 0, Math.max(0, data.length - 1), StandardCharsets.UTF_8));
            }
        } catch (IOException ignored) {
        }
    }

    private static String sign(String secret, byte[] body) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return "sha256=" + HexFormat.of().formatHex(mac.doFinal(body));
    }
}
