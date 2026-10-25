package com.condodesk;

import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** What the application writes out: the page, the script and the CSV. The rows of CATALOG.md section 2.1. */
class S04_OutputTest extends VBase {

    @Test
    @Verifies({"SEC-002"})
    void sec002_aStoredNoticeIsEscaped() throws Exception {
        update("update app_user set name = ? where id = ?", "<b onmouseover=1>Marcos</b>", managerA);
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/notices");
        R hostile = marcos.post("/notices", "title", "<img src=x onerror=1>", "body", "Line one\n**bold**");
        marcos.post("/notices", "title", "Water shutdown on Friday", "body", "The water is off from 9 to 11.");
        if (hostile.status() != 303) throw refused("POST /notices", hostile);
        long id = (Long) value("select id from notice where title like '<img%'");
        Browser ana = loggedIn(OWNER_A101);
        String list = ok(ana, "/notices").body();
        String single = ok(ana, "/notices/" + id).body();
        boolean titleRaw = list.contains("<img src=x onerror=1>") || single.contains("<img src=x onerror=1>");
        boolean authorRaw = list.contains("<b onmouseover=1>") || single.contains("<b onmouseover=1>");
        boolean shown = list.contains("&lt;img src=x onerror=1&gt;") && list.contains("Water shutdown on Friday") && single.contains("<strong>bold</strong>") && single.contains("Line one<br>");
        Verdict.fixed("SEC-002", !titleRaw && !authorRaw && shown, "a notice titled <img src=x onerror=1> by a manager whose name is <b onmouseover=1>Marcos</b>: the title reaches a resident's page as markup: "
                + titleRaw + ", the author's name does: " + authorRaw + " (expected false, false); the text is still shown as text and the bold and line breaks of the body still apply: " + shown + " (expected true)");
    }

    @Test
    @Verifies({"SEC-002.b"})
    void sec002b_aDocumentNameIsEscaped() throws Exception {
        long invoice = invoice(unitA201, "2026-03", LocalDate.parse("2026-04-10"), "400.00");
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/documents");
        if (marcos.upload("/documents", "file", "minutes.pdf", PDF, "kind", "MINUTES").status() != 303) throw new IllegalStateException("POST /documents answered not 303");
        Browser carla = loggedIn(OWNER_A201);
        carla.get("/invoices/" + invoice);
        R sent = carla.upload("/invoices/" + invoice + "/proof", "file", "<svg onload=1>.pdf", PDF);
        if (sent.status() != 303) throw refused("POST /invoices/" + invoice + "/proof", sent);
        String page = ok(marcos, "/documents").body();
        boolean raw = page.contains("<svg onload=1>");
        boolean shown = page.contains("&lt;svg onload=1&gt;.pdf") && page.contains(">minutes.pdf</a>");
        Verdict.fixed("SEC-002.b", !raw && shown, "a payment proof named <svg onload=1>.pdf reaches the manager's document list as markup: " + raw + " (expected false); the names are still listed as text: " + shown + " (expected true)");
    }

    @Test
    @Verifies({"SEC-003"})
    void sec003_aSearchTermIsEscapedWhereItIsRepeated() throws Exception {
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/notices");
        marcos.post("/notices", "title", "Water shutdown on Friday", "body", "The water is off from 9 to 11.");
        Browser ana = loggedIn(OWNER_A101);
        List<String> raw = new ArrayList<>();
        for (String q : new String[]{"<script>alert(1)</script>", "\"><img src=x onerror=1>"}) {
            String body = ok(ana, "/notices?q=" + enc(q)).body();
            if (body.contains(q)) raw.add(q);
        }
        String found = ok(ana, "/notices?q=water").body();
        boolean works = found.contains("notices for “water”") && found.contains("Water shutdown on Friday") && found.contains("value=\"water\"");
        Verdict.fixed("SEC-003", raw.isEmpty() && works, "search terms that come back as markup: " + (raw.isEmpty() ? "none" : raw) + " (expected none); a plain search still finds its notice and repeats its term: " + works + " (expected true)");
    }

    private static final Pattern SINK = Pattern.compile("(\\.(innerHTML|outerHTML)\\s*\\+?=|insertAdjacentHTML\\s*\\(|document\\.write(ln)?\\s*\\(|\\beval\\s*\\(|new\\s+Function\\s*\\(|set(Timeout|Interval)\\s*\\(\\s*[\"'`])([^;]*);?");
    private static final Pattern CLEANSER = Pattern.compile("\\b(escape\\w*|esc\\w*|sanitize\\w*|encode\\w*|DOMPurify)\\s*\\(");

    @Test
    @Verifies({"SEC-004"})
    void sec004_noValueFromTheUrlReachesAnHtmlSink() throws Exception {
        String script = Files.readString(Path.of("src/main/resources/static/app.js")).replaceAll("(?s)/\\*.*?\\*/", "").replaceAll("(?m)//.*$", "");
        Matcher m = SINK.matcher(script);
        List<String> raw = new ArrayList<>(), cleaned = new ArrayList<>();
        while (m.find()) {
            String rhs = m.group(5).trim();
            if (rhs.matches("([\"'`])[^\"'`$]*\\1")) continue;           // a string literal: nothing from the URL
            (CLEANSER.matcher(rhs).find() ? cleaned : raw).add(m.group(1).trim() + " " + abbreviate(rhs));
        }
        String result = !raw.isEmpty() ? "none" : !cleaned.isEmpty() ? "half" : "full";
        Verdict.structure("SEC-004", result, raw.isEmpty() && cleaned.isEmpty() ? "the page script has no HTML sink fed by a variable"
                : (raw.isEmpty() ? "an HTML sink is fed through a cleansing call, which the judge must read: " + cleaned : "an HTML sink is fed by a variable directly: " + raw));
    }

    @Test
    @Verifies({"SEC-008"})
    void sec008_aCellThatLooksLikeAFormulaIsNeutralized() throws Exception {
        String[] hostile = {"=1+1", "+1+1", "-1+1", "@SUM(1)", "\t1+1"};
        for (int i = 0; i < hostile.length; i++) lateOwner("Apt 30" + (i + 1), hostile[i], i);
        lateOwner("Apt 306", "Smith, John \"JJ\"", 5);
        lateOwner("Apt 307", "Ana = Owner", 6);
        String csv = ok(loggedIn(MANAGER_A), "/reports/delinquency.csv").body();
        List<String> open = new ArrayList<>();
        for (int i = 0; i < hostile.length; i++) if (!csv.contains("Apt 30" + (i + 1) + ",'" + hostile[i] + ",")) open.add(hostile[i].replace("\t", "<TAB>"));
        boolean format = csv.contains("Apt 306,\"Smith, John \"\"JJ\"\"\",") && csv.contains("Apt 307,Ana = Owner,");
        Verdict.fixed("SEC-008", open.isEmpty() && format, "owner names that start a formula and reach the CSV un-neutralized: " + (open.isEmpty() ? "none" : open) + " (expected none); commas and quotes are still quoted and a name with an equals sign inside is untouched: " + format + " (expected true)");
    }

    /** A unit of the building with an owner of that name and an invoice that is overdue. */
    private void lateOwner(String unitLabel, String ownerName, int n) throws Exception {
        long unit = insert("insert into unit (building_id, label, ideal_fraction) values (?, ?, 0.0001) returning id", buildingA, unitLabel);
        resident("owner" + n + "@maple.test", ownerName, buildingA, unit, "OWNER");
        invoice(unit, "2025-12", LocalDate.parse("2026-01-10"), "300.00");
    }
}
