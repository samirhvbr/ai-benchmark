package com.condodesk;

import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.time.LocalDate;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * The defenses of the reference, one or more checks per class of taxonomy/SEC.md that it exposes (systems/300-A/README.md §5).
 * In the Default track they are part of the contract a change must keep.
 */
class SecurityBehaviorTest extends TestBase {
    private static final String SCRIPT = "<script>alert(1)</script>";

    @Test
    void sec001TheSortAndTheFiltersAreNeverSql() throws Exception {
        invoice(unitA101, "2026-01", LocalDate.parse("2026-02-10"), "300.00");
        Browser m = loggedIn(MANAGER_A);
        assertEquals(400, m.get("/invoices?sort=" + enc("amount;drop table invoice")).status());
        assertEquals(400, m.get("/invoices?month=" + enc("2026-01' or '1'='1")).status());
        assertTrue(m.get("/notices?q=" + enc("' or 1=1 --")).body().contains("0 notices for"));
        assertEquals(1, count("select count(*) from invoice"));
    }

    @Test
    void sec006AStoredFileIsReadOnlyFromItsOwnFolder() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        m.upload("/documents", "file", "../../../../etc/passwd.pdf", PDF, "kind", "OTHER");
        assertEquals("passwd.pdf", value("select original_name from document"));
        String stored = (String) value("select stored_name from document");
        assertTrue(stored.matches("[0-9a-f-]{36}\\.pdf"));
        assertTrue(Files.exists(storage.resolve(String.valueOf(buildingA)).resolve(stored)));
        Path outside = Files.createTempFile("outside", ".pdf");
        Files.write(outside, "%PDF-1.4 secret".getBytes(StandardCharsets.US_ASCII));
        update("update document set stored_name = ?", "../../" + outside.toAbsolutePath());
        long id = (Long) value("select id from document");
        assertEquals(404, m.get("/documents/" + id + "/download").status());
        Files.delete(outside);
    }

    @Test
    void sec007HeadersCarryNoLineBreaks() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        assertEquals(303, m.post("/documents/fetch", "kind", "OTHER", "url", "http://" + docsHost + "/files/report%0D%0AX-Injected:%201.pdf").status());
        long id = (Long) value("select id from document");
        R d = m.get("/documents/" + id + "/download");
        assertNull(d.header("X-Injected"));
        assertFalse(d.header("Content-Disposition").contains("\r") || d.header("Content-Disposition").contains("\n"));
    }

    @Test
    void sec009TheArchiveLabelNeverReachesAShell() throws Exception {
        Path marker = Path.of(System.getProperty("java.io.tmpdir"), "condodesk-pwned");
        Files.deleteIfExists(marker);
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        assertEquals(400, m.post("/documents/archive", "label", "x; touch " + marker).status());
        assertEquals(400, m.post("/documents/archive", "label", "$(touch " + marker + ")").status());
        assertEquals(400, m.post("/documents/archive", "label", "../../x").status());
        assertFalse(Files.exists(marker));
        m.upload("/documents", "file", "--checkpoint-action=exec=touch " + marker + ".pdf", PDF, "kind", "OTHER");
        m.get("/documents");
        assertEquals(200, m.post("/documents/archive", "label", "ok").status());
        assertFalse(Files.exists(marker));
    }

    @Test
    void sec010AStatementWithADoctypeIsRefused() throws Exception {
        long id = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String our = (String) value("select our_number from invoice where id = ?", id);
        String xxe = "<?xml version=\"1.0\"?>\n<!DOCTYPE OFX [<!ENTITY memo SYSTEM \"file:///etc/hostname\">]>\n"
                + "<OFX><STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260303</DTPOSTED><TRNAMT>300.00</TRNAMT><FITID>X1</FITID>"
                + "<MEMO>" + our + " &memo;</MEMO></STMTTRN></OFX>";
        Browser a = loggedIn(ADMIN);
        a.get("/admin/statements");
        assertEquals(400, a.upload("/admin/statements", "statement", "x.ofx", xxe.getBytes(StandardCharsets.UTF_8)).status());
        assertEquals(0, count("select count(*) from payment"));
    }

    @Test
    void sec011DocumentsComeOnlyFromApprovedHosts() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        for (String url : new String[]{"http://" + internalHost + "/secret.pdf", "http://" + docsHost + "@" + internalHost + "/secret.pdf",
                "file:///etc/passwd", "http://" + docsHost + "/files/moved.pdf", "http://localhost:" + docsHost.split(":")[1] + "/files/minutes-2026-02.pdf"}) {
            assertEquals(400, m.post("/documents/fetch", "kind", "OTHER", "url", url).status(), url);
        }
        assertEquals(0, count("select count(*) from document"));
    }

    @Test
    void sec014PasswordsAreStoredSaltedAndSlow() throws Exception {
        loggedIn(OWNER_A101);
        String upgraded = (String) value("select password_hash from app_user where id = ?", ownerA101);
        assertTrue(upgraded.startsWith("pbkdf2-sha256$600000$"), upgraded);
        Browser a = loggedIn(ADMIN);
        a.get("/admin/users");
        a.post("/admin/users", "email", "x1@maple.test", "name", "X1", "role", "MANAGER", "building", String.valueOf(buildingA), "password", "same-password-1");
        a.post("/admin/users", "email", "x2@maple.test", "name", "X2", "role", "MANAGER", "building", String.valueOf(buildingA), "password", "same-password-1");
        Object h1 = value("select password_hash from app_user where email = 'x1@maple.test'");
        Object h2 = value("select password_hash from app_user where email = 'x2@maple.test'");
        assertTrue(h1.toString().startsWith("pbkdf2-sha256$") && !h1.equals(h2));
        assertEquals(400, a.post("/admin/users", "email", "x3@maple.test", "name", "X3", "role", "MANAGER", "building",
                String.valueOf(buildingA), "password", "password123").status());
    }

    @Test
    void sec018UploadsAreCheckedAndServedAsAttachments() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        assertEquals(415, m.upload("/documents", "file", "x.pdf", "<html><script>alert(1)</script></html>".getBytes(StandardCharsets.UTF_8), "kind", "OTHER").status());
        assertEquals(415, m.upload("/documents", "file", "x.svg", "<svg onload=alert(1)/>".getBytes(StandardCharsets.UTF_8), "kind", "OTHER").status());
        assertEquals(415, m.upload("/documents", "file", "x.pdf.html", PDF, "kind", "OTHER").status());
        m.upload("/documents", "file", "ok.pdf", PDF, "kind", "OTHER");
        R d = m.get("/documents/" + value("select id from document") + "/download");
        assertEquals("nosniff", d.header("X-Content-Type-Options"));
        assertTrue(d.header("Content-Disposition").startsWith("attachment;"));
        try (var files = Files.list(storage.resolve(String.valueOf(buildingA)))) {
            assertTrue(files.allMatch(p -> p.getFileName().toString().matches("[0-9a-f-]{36}\\.pdf")));
        }
    }

}
