package com.condodesk;

import org.junit.jupiter.api.Test;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import java.util.zip.GZIPInputStream;

/** What the server reads, runs, parses, fetches and stores for a request: SEC-006, SEC-009, SEC-010, SEC-011 and SEC-018 of CATALOG.md section 2.2 and 2.4. */
class S06_FilesTest extends VBase {

    private String uploaded(Browser b, String name, byte[] data, String kind) throws Exception {
        b.get("/documents");
        R r = b.upload("/documents", "file", name, data, "kind", kind);
        if (r.status() != 303) throw refused("POST /documents (" + name + ")", r);
        return (String) value("select stored_name from document order by id desc limit 1");
    }

    @Test
    @Verifies({"SEC-006"})
    void sec006_theViewersLinkStaysInTheBuildingsFolder() throws Exception {
        String ownA = uploaded(loggedIn(MANAGER_A), "minutes.pdf", PDF, "MINUTES");
        Browser b = loggedIn(MANAGER_B);
        String ownB = uploaded(b, "rules.pdf", PDF, "RULES");
        Path outside = Files.createTempFile("leb-outside", ".txt");
        String marker = "OUTSIDE-SECRET-" + UUID.randomUUID();
        Files.writeString(outside, marker);
        Path folderB = storage.resolve(String.valueOf(buildingB));
        List<String> payloads = List.of("../" + buildingA + "/" + ownA, "./../" + buildingA + "/" + ownA, folderB.relativize(outside).toString(), outside.toAbsolutePath().toString());
        List<String> leaked = new ArrayList<>();
        for (String p : payloads) {
            R r = b.get("/documents/raw?file=" + enc(p));
            if (r.status() == 200 && (r.body().contains(marker) || r.body().startsWith("%PDF"))) leaked.add(p.length() > 60 ? "..." + p.substring(p.length() - 40) : p);
        }
        R mine = b.get("/documents/raw?file=" + enc(ownB));
        R missing = b.get("/documents/raw?file=" + enc("nothing-here.pdf"));
        boolean works = mine.status() == 200 && mine.body().startsWith("%PDF") && missing.status() == 404;
        Files.deleteIfExists(outside);
        Verdict.fixed("SEC-006", leaked.isEmpty() && works, "a manager of building B reading, through the old viewer's link, another building's file and a file outside the storage: the requests that returned content: "
                + (leaked.isEmpty() ? "none" : leaked) + " (expected none); the building's own file answered " + mine.status() + " and a missing name " + missing.status() + " (expected 200 and 404)");
    }

    @Test
    @Verifies({"SEC-009"})
    void sec009_theArchiveLabelNeverReachesAShell() throws Exception {
        String run = UUID.randomUUID().toString().substring(0, 8);
        Path tmp = Path.of(System.getProperty("java.io.tmpdir"));
        Path[] markers = {tmp.resolve("leb-s-pwned-" + run + "-1"), tmp.resolve("leb-s-pwned-" + run + "-2"), tmp.resolve("leb-s-pwned-" + run + "-3"), tmp.resolve("leb-s-pwned-" + run + "-4")};
        String[] labels = {"x;touch " + markers[0] + ";#", "$(touch " + markers[1] + ")", "`touch " + markers[2] + "`", "x\ntouch " + markers[3] + "\n#"};
        Browser m = loggedIn(MANAGER_A);
        uploaded(m, "minutes.pdf", PDF, "MINUTES");
        m.get("/documents");
        List<String> ran = new ArrayList<>();
        for (int i = 0; i < labels.length; i++) {
            m.post("/documents/archive", "label", labels[i]);
            if (Files.exists(markers[i])) ran.add("variant " + (i + 1));
            Files.deleteIfExists(markers[i]);
        }
        R labelled = m.post("/documents/archive", "label", "march-2026");
        R unnamed = m.post("/documents/archive");
        boolean works = labelled.status() == 200 && String.valueOf(labelled.header("Content-Disposition")).contains("march-2026.tar.gz") && firstEntry(labelled).equals("index.csv")
                && unnamed.status() == 200 && String.valueOf(unnamed.header("Content-Disposition")).contains("documents-2026-03-04.tar.gz");
        Verdict.fixed("SEC-009", ran.isEmpty() && works, "labels that would run a command (after a semicolon, in $( ), in backticks, on a new line): the commands that ran: " + (ran.isEmpty() ? "none" : ran)
                + " (expected none); a plain label gave an archive named for it with its index.csv, and without a label the dated name: " + works + " (expected true)");
    }

    private static String firstEntry(R r) {
        try (GZIPInputStream in = new GZIPInputStream(new ByteArrayInputStream(r.bytes()))) {
            return new String(in.readNBytes(100), StandardCharsets.UTF_8).split("\0")[0];
        } catch (Exception e) {
            return "(not a gzip: " + e.getClass().getSimpleName() + ")";
        }
    }

    @Test
    @Verifies({"SEC-010"})
    void sec010_aStatementCannotReadFilesThroughItsXml() throws Exception {
        Path secret = Files.createTempFile("leb-xxe", ".txt");
        String word = "TOPSECRET-" + UUID.randomUUID();
        Files.writeString(secret, word);
        Path dtd = Files.createTempFile("leb-xxe", ".dtd");
        String dtdWord = "TOPSECRETDTD-" + UUID.randomUUID();
        Files.writeString(dtd, "<!ENTITY memo \"" + dtdWord + "\">");
        String body = "<STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260303</DTPOSTED><TRNAMT>10.00</TRNAMT><FITID>%s</FITID><MEMO>&memo;</MEMO></STMTTRN></OFX>";
        String viaEntity = "<?xml version=\"1.0\"?>\n<!DOCTYPE OFX [<!ENTITY memo SYSTEM \"" + secret.toUri() + "\">]>\n<OFX>" + String.format(body, "X1");
        String viaDtd = "<?xml version=\"1.0\"?>\n<!DOCTYPE OFX [<!ENTITY % d SYSTEM \"" + dtd.toUri() + "\"> %d;]>\n<OFX>" + String.format(body, "X2");
        Browser a = loggedIn(ADMIN);
        a.get("/admin/statements");
        int s1 = a.upload("/admin/statements", "statement", "x.ofx", viaEntity.getBytes(StandardCharsets.UTF_8)).status();
        int s2 = a.upload("/admin/statements", "statement", "y.ofx", viaDtd.getBytes(StandardCharsets.UTF_8)).status();
        long read = count("select count(*) from payment where memo like ? or memo like ?", "%TOPSECRET-%", "%TOPSECRETDTD-%");
        long invoice = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String our = (String) value("select our_number from invoice where id = ?", invoice);
        R legit = a.upload("/admin/statements", "statement", "ok.ofx", ofx(new String[]{"F1", "20260303", "300.00", "PIX " + our}));
        boolean works = legit.status() == 200 && "PAID".equals(value("select status from invoice where id = ?", invoice));
        Files.deleteIfExists(secret);
        Files.deleteIfExists(dtd);
        Verdict.fixed("SEC-010", read == 0 && works, "statements whose XML reads a local file (an external entity, an external DTD) answered " + s1 + " and " + s2 + " and left " + read
                + " payments that hold what they read (expected 0); a valid statement still settles its invoice: " + works + " (expected true)");
    }

    @Test
    @Verifies({"SEC-011"})
    void sec011_aFetchNeverFollowsAnApprovedSiteToAnInternalOne() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        List<String> reached = new ArrayList<>();
        for (String url : new String[]{"http://" + docsHost + "/files/moved.pdf", "http://" + internalHost + "/secret.pdf", "http://" + docsHost + "@" + internalHost + "/secret.pdf"}) {
            long before = count("select count(*) from document");
            int s = m.post("/documents/fetch", "kind", "OTHER", "url", url).status();
            if (s == 303 || count("select count(*) from document") > before) reached.add(url.replace(internalHost, "internal").replace(docsHost, "docs") + " -> " + s);
        }
        R legit = m.post("/documents/fetch", "kind", "OTHER", "url", "http://" + docsHost + "/files/minutes-2026-02.pdf");
        boolean works = legit.status() == 303 && count("select count(*) from document where source_url like '%minutes-2026-02.pdf'") == 1;
        Verdict.fixed("SEC-011", reached.isEmpty() && works, "fetches that reached the internal site (a redirect from the approved one, the address itself, a userinfo trick): " + (reached.isEmpty() ? "none" : reached)
                + " (expected none); a document from the approved site is still fetched: " + works + " (expected true)");
    }

    @Test
    @Verifies({"SEC-018"})
    void sec018_anUploadIsNeverServedAsAPage() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        List<String> pages = new ArrayList<>();
        String[][] hostile = {{"x.html", "<html><script>alert(1)</script></html>"}, {"x.svg", "<svg xmlns=\"http://www.w3.org/2000/svg\" onload=\"alert(1)\"/>"},
                {"x.htm", "<html><script>alert(1)</script></html>"}};
        // the manager's upload and the resident's payment proof share the one stored-file path: a fix kept to one route leaves the other
        long invoice = invoice(unitA101, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/invoices/" + invoice);
        for (String[] h : hostile) {
            for (boolean proof : new boolean[]{false, true}) {
                Browser who = proof ? ana : m;
                R up = proof ? ana.upload("/invoices/" + invoice + "/proof", "file", h[0], h[1].getBytes(StandardCharsets.UTF_8))
                        : m.upload("/documents", "file", h[0], h[1].getBytes(StandardCharsets.UTF_8), "kind", "OTHER");
                if (up.status() != 303) continue;                                         // refused: closed
                long id = (Long) value("select max(id) from document");
                R d = who.get("/documents/" + id + "/download");
                String type = String.valueOf(d.header("Content-Type")).toLowerCase();
                String disposition = String.valueOf(d.header("Content-Disposition")).toLowerCase();
                if (d.status() == 200 && (type.startsWith("text/html") || type.contains("svg")) && !disposition.startsWith("attachment")) pages.add((proof ? "a payment proof " : "a document ") + h[0]);
            }
        }
        boolean works = true;
        for (String[] f : new String[][]{{"minutes.pdf", "pdf"}, {"photo.png", "png"}, {"scan.jpg", "jpg"}}) {
            byte[] data = f[1].equals("pdf") ? PDF : f[1].equals("png") ? PNG : new byte[]{(byte) 0xff, (byte) 0xd8, (byte) 0xff, (byte) 0xe0, 0, 0x10, 'J', 'F', 'I', 'F', 0};
            if (m.upload("/documents", "file", f[0], data, "kind", "OTHER").status() != 303) { works = false; continue; }
            long id = (Long) value("select max(id) from document");
            R d = m.get("/documents/" + id + "/download");
            works &= d.status() == 200 && String.valueOf(d.header("Content-Disposition")).contains("filename=\"" + f[0] + "\"") && d.bytes().length == data.length;
        }
        Verdict.fixed("SEC-018", pages.isEmpty() && works, "uploads that were served back as a page (a .html, an .htm and an .svg with a script, as a document and as a payment proof): " + (pages.isEmpty() ? "none" : pages) + " (expected none); a PDF, a PNG and a JPEG still upload and download as attachments under their names: "
                + works + " (expected true)");
    }
}
