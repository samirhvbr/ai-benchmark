package com.condodesk;

import org.junit.jupiter.api.Test;

import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.time.Duration;
import java.time.LocalDate;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.zip.GZIPInputStream;

import static org.junit.jupiter.api.Assertions.assertArrayEquals;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** The notice board and the documents. */
class ContentTest extends TestBase {

    private Browser manager() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/notices");
        return m;
    }

    private long publish(Browser m, String title, String body, boolean pinned) throws Exception {
        R r = pinned ? m.post("/notices", "title", title, "body", body, "pinned", "1") : m.post("/notices", "title", title, "body", body);
        assertEquals(303, r.status(), r.body());
        String loc = r.location();
        return Long.parseLong(loc.substring(loc.indexOf("#notice-") + 8));
    }

    @Test
    void aNoticeKeepsItsLineBreaksAndBold() throws Exception {
        Browser m = manager();
        long id = publish(m, "Water", "Water off on **Friday**\nfrom 9 to 12.", false);
        String page = loggedIn(OWNER_A101).get("/notices").body();
        assertTrue(page.contains("<article id=\"notice-" + id + "\" class=\"notice\">"), page);
        assertTrue(page.contains("Water off on <strong>Friday</strong><br>\nfrom 9 to 12."), page);
        assertTrue(page.contains("Marcos Manager · 2026-03-04"));
        assertEquals(200, loggedIn(OWNER_A101).get("/notices/" + id).status());
    }

    @Test
    void theSearchWantsEveryWordAndShowsPinnedNoticesFirst() throws Exception {
        Browser m = manager();
        publish(m, "Pool closed", "The pool is closed for cleaning.", false);
        publish(m, "Assembly", "General assembly on the 20th; the pool budget is on the agenda.", true);
        publish(m, "Garage", "Garage painting next week.", false);
        String all = loggedIn(OWNER_A101).get("/notices").body();
        assertTrue(all.indexOf("Assembly") < all.indexOf("Garage") && all.indexOf("Garage") < all.indexOf("Pool closed"));
        String pool = loggedIn(OWNER_A101).get("/notices?q=pool").body();
        assertTrue(pool.contains("2 notices for") && pool.contains("Pool closed") && pool.contains("Assembly") && !pool.contains("Garage"));
        assertTrue(loggedIn(OWNER_A101).get("/notices?q=pool+cleaning").body().contains("1 notices for"));
        assertTrue(loggedIn(OWNER_A101).get("/notices?q=100%25").body().contains("0 notices for"));
        assertEquals(400, loggedIn(OWNER_A101).get("/notices?q=a+b+c+d+e+f").status());
    }

    @Test
    void theBoardIsPaged() throws Exception {
        Browser m = manager();
        for (int i = 1; i <= 12; i++) {
            publish(m, "Notice " + i, "Text " + i, false);
            clock.advance(Duration.ofMinutes(1));
        }
        String first = loggedIn(OWNER_A101).get("/notices").body();
        assertTrue(first.contains("Notice 12") && !first.contains("Notice 2<") && first.contains("Page 1 of 2"));
        assertTrue(loggedIn(OWNER_A101).get("/notices?page=2").body().contains("Notice 2<"));
    }

    @Test
    void eachBuildingSeesOnlyItsOwnNotices() throws Exception {
        long id = publish(manager(), "Maple only", "For Maple Court.", false);
        assertFalse(loggedIn(OWNER_B101).get("/notices").body().contains("Maple only"));
        assertEquals(404, loggedIn(OWNER_B101).get("/notices/" + id).status());
        assertTrue(loggedIn(ADMIN).get("/notices?building=" + buildingA).body().contains("Maple only"));
    }

    @Test
    void aManagerUploadsADocumentThatResidentsDownload() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        R up = m.upload("/documents", "file", "minutes 2026-02.pdf", PDF, "kind", "MINUTES");
        assertEquals(303, up.status(), up.body());
        Browser ana = loggedIn(OWNER_A101);
        String list = ana.get("/documents").body();
        assertTrue(list.contains("minutes 2026-02.pdf"));
        long id = (Long) value("select id from document");
        R d = ana.get("/documents/" + id + "/download");
        assertEquals(200, d.status());
        assertArrayEquals(PDF, d.bytes());
        assertEquals("application/pdf", d.header("Content-Type"));
        assertTrue(d.header("Content-Disposition").startsWith("attachment; filename=\"minutes 2026-02.pdf\""));
        assertFalse(loggedIn(OWNER_B101).get("/documents").body().contains("minutes"));
    }

    @Test
    void aResidentSendsAPaymentProofThatOnlyTheUnitAndTheManagerSee() throws Exception {
        long inv = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/invoices/" + inv);
        R up = ana.upload("/invoices/" + inv + "/proof", "file", "receipt.png", PNG);
        assertEquals(303, up.status(), up.body());
        assertEquals(List.of(Map.of("kind", "PROOF", "unit_id", unitA101)),
                rows("select kind, unit_id from document").stream().map(r -> Map.of("kind", r.get("kind"), "unit_id", r.get("unit_id"))).toList());
        long id = (Long) value("select id from document");
        assertEquals(200, ana.get("/documents/" + id + "/download").status());
        assertEquals(200, loggedIn(MANAGER_A).get("/documents/" + id + "/download").status());
        assertTrue(loggedIn(MANAGER_A).get("/documents").body().contains("receipt.png"));
        assertFalse(loggedIn(TENANT_A102).get("/documents").body().contains("receipt.png"));
        Browser bruno = loggedIn(TENANT_A102);
        bruno.get("/invoices");
        assertEquals(404, bruno.upload("/invoices/" + inv + "/proof", "file", "receipt.png", PNG).status());
    }

    @Test
    void uploadsAreCheckedByContentAndSize() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        byte[] big = Arrays.copyOf(PDF, 5 * 1024 * 1024 + 1);
        assertEquals(400, m.upload("/documents", "file", "big.pdf", big, "kind", "OTHER").status());
        assertEquals(400, m.upload("/documents", "file", "proof.pdf", PDF, "kind", "PROOF").status());
        assertEquals(303, m.upload("/documents", "file", "photo.JPG", new byte[]{(byte) 0xff, (byte) 0xd8, (byte) 0xff, 0x00}, "kind", "OTHER").status());
        assertEquals(1, count("select count(*) from document"));
    }

    @Test
    void residentsDoNotUploadBuildingDocuments() throws Exception {
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/documents");
        assertEquals(403, ana.upload("/documents", "file", "minutes.pdf", PDF, "kind", "MINUTES").status());
        assertEquals(0, count("select count(*) from document"));
    }

    @Test
    void aDocumentIsFetchedFromAnApprovedSite() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        String url = "http://" + docsHost + "/files/minutes-2026-02.pdf";
        assertEquals(303, m.post("/documents/fetch", "kind", "MINUTES", "url", url).status());
        assertEquals(List.of(url), rows("select source_url from document").stream().map(r -> r.get("source_url")).toList());
        assertTrue(loggedIn(OWNER_A101).get("/documents").body().contains("minutes-2026-02.pdf"));
        assertEquals(400, m.post("/documents/fetch", "kind", "MINUTES", "url", "http://" + docsHost + "/files/missing.pdf").status());
    }

    @Test
    void theArchiveHoldsEveryDocumentAndAnIndex() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        m.upload("/documents", "file", "minutes.pdf", PDF, "kind", "MINUTES");
        m.upload("/documents", "file", "rules, 2026.pdf", PDF, "kind", "RULES");
        m.get("/documents");
        R a = m.post("/documents/archive", "label", "march-2026");
        assertEquals(200, a.status(), a.body());
        assertTrue(a.header("Content-Disposition").startsWith("attachment; filename=\"march-2026.tar.gz\""));
        Map<String, byte[]> entries = untar(a.bytes());
        List<String> stored = rows("select stored_name from document order by id").stream().map(r -> (String) r.get("stored_name")).toList();
        assertEquals(3, entries.size());
        assertTrue(entries.keySet().containsAll(stored));
        String index = new String(entries.get("index.csv"), StandardCharsets.UTF_8);
        assertTrue(index.startsWith("file,name,kind,created_at\r\n") && index.contains("\"rules, 2026.pdf\",RULES"), index);
        assertArrayEquals(PDF, entries.get(stored.get(0)));
        R unnamed = m.post("/documents/archive");
        assertTrue(unnamed.header("Content-Disposition").startsWith("attachment; filename=\"documents-2026-03-04.tar.gz\""));
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/documents");
        assertEquals(403, ana.post("/documents/archive").status());
    }

    /** The regular files of a tar.gz, by name. */
    static Map<String, byte[]> untar(byte[] gz) throws IOException {
        byte[] tar;
        try (InputStream in = new GZIPInputStream(new ByteArrayInputStream(gz)); ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            in.transferTo(out);
            tar = out.toByteArray();
        }
        Map<String, byte[]> files = new LinkedHashMap<>();
        int pos = 0;
        while (pos + 512 <= tar.length && tar[pos] != 0) {
            String name = new String(tar, pos, 100, StandardCharsets.US_ASCII).replace("\0", "");
            long size = Long.parseLong(new String(tar, pos + 124, 11, StandardCharsets.US_ASCII).replace("\0", "").trim(), 8);
            char type = (char) tar[pos + 156];
            if (type == '0' || type == '\0') files.put(name, Arrays.copyOfRange(tar, pos + 512, pos + 512 + (int) size));
            pos += 512 + (int) ((size + 511) / 512) * 512;
        }
        return files;
    }
}
