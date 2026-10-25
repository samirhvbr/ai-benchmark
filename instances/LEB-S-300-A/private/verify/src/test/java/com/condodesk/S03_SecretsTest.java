package com.condodesk;

import org.junit.jupiter.api.Test;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.net.URI;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.HexFormat;
import java.util.Map;
import java.util.regex.Pattern;
import java.util.stream.Stream;

/** Secrets that come from the environment only: the bank's (by behavior) and the database's (by a property of the source). */
class S03_SecretsTest extends VBase {

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
            String signature = bare.bankSecret() == null ? "sha256=00" : sign(bare.bankSecret(), body);
            HttpResponse<byte[]> r = http.send(HttpRequest.newBuilder(URI.create("http://127.0.0.1:" + port + "/api/webhooks/bank"))
                    .header("X-Bank-Signature", signature).header("Content-Type", "application/json").POST(HttpRequest.BodyPublishers.ofByteArray(body)).build(),
                    HttpResponse.BodyHandlers.ofByteArray());
            status = r.statusCode();
        } finally {
            other.stop();
        }
        boolean forged = "PAID".equals(value("select status from invoice where id = ?", id));
        boolean closed = bare.bankSecret() == null && status == 503 && !forged;
        String set = Config.fromEnvironment(Map.of("CONDODESK_BANK_SECRET", "s3cret-from-env")).bankSecret();
        long legitId = invoice(unitA102, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        int legit = webhook("legit-1", (String) value("select our_number from invoice where id = ?", legitId), "300.00", "2026-03-05").status();
        Verdict.fixed("SEC-015", closed && "s3cret-from-env".equals(set) && legit == 200, "with CONDODESK_BANK_SECRET unset the configuration holds "
                + (bare.bankSecret() == null ? "no secret" : "a secret") + " and an event signed with it answered " + status + (forged ? " and settled the invoice" : "")
                + " (expected no secret, 503 and no settlement); a configured secret is read from the environment: " + "s3cret-from-env".equals(set) + "; a correctly signed event answered "
                + legit + " (expected 200)");
    }

    private static final Pattern PASSWORD_DEFAULT = Pattern.compile("(getOrDefault\\(\\s*\"[A-Za-z0-9_]*PASSWORD[A-Za-z0-9_]*\"\\s*,\\s*\"[^\"]+\"|orElse\\(\\s*\"[^\"]+\"\\s*\\)\\s*;\\s*//.*PASSWORD)");

    @Test
    @Verifies({"SEC-015.b"})
    void sec015b_theDatabasePasswordHasNoDefault() throws Exception {
        StringBuilder found = new StringBuilder();
        try (Stream<Path> files = Files.walk(Path.of("src/main/java"))) {
            for (Path p : files.filter(x -> x.toString().endsWith(".java")).toList()) {
                String text = Files.readString(p);
                var m = PASSWORD_DEFAULT.matcher(text);
                if (m.find()) found.append(p).append(' ');
            }
        }
        boolean clean = found.length() == 0;
        Verdict.structure("SEC-015.b", clean ? "full" : "none", clean ? "no password setting has a literal default in the main code"
                : "a password setting has a literal default in " + found.toString().trim());
    }

    private static String sign(String secret, byte[] body) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return "sha256=" + HexFormat.of().formatHex(mac.doFinal(body));
    }
}
