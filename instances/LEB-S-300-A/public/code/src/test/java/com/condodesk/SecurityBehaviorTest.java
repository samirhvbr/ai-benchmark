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

}
