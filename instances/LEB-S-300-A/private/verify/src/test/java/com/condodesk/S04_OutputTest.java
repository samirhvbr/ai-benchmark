package com.condodesk;

import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
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
        long id = (Long) value("select max(id) from notice where title like '%img src=x onerror=1%'");   // also found when a fix escaped the title on input
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
        setupUpload(marcos, "minutes.pdf", PDF, "MINUTES");
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
        // the third has no closing bracket: a fix that strips whole tags leaves it, and the page's own closing quote completes it
        for (String q : new String[]{"<script>alert(1)</script>", "\"><img src=x onerror=1>", "<img src=x onerror=1"}) {
            String body = ok(ana, "/notices?q=" + enc(q)).body();
            if (body.contains(q)) raw.add(q);
        }
        String found = ok(ana, "/notices?q=water").body();
        boolean works = found.contains("notices for “water”") && found.contains("Water shutdown on Friday") && found.contains("value=\"water\"");
        Verdict.fixed("SEC-003", raw.isEmpty() && works, "search terms that come back as markup: " + (raw.isEmpty() ? "none" : raw) + " (expected none); a plain search still finds its notice and repeats its term: " + works + " (expected true)");
    }

    /** The ways the page script can write a string into the page as markup or run it as code, assignments by the dot form and by the bracket form included. */
    private static final Pattern SINK = Pattern.compile("(\\.\\s*(innerHTML|outerHTML|srcdoc)\\s*\\+?=(?!=)|\\[\\s*[\"'`](innerHTML|outerHTML|srcdoc)[\"'`]\\s*\\]\\s*\\+?=(?!=)"
            + "|insertAdjacentHTML\\s*\\(|document\\s*\\.\\s*write(ln)?\\s*\\(|\\beval\\s*\\(|\\bFunction\\s*\\(|createContextualFragment\\s*\\(|\\bset(Timeout|Interval)\\s*\\()");
    /** A variable the script fills from something the visitor controls through the address. */
    private static final Pattern FROM_ADDRESS = Pattern.compile("\\b(?:var|let|const)\\s+(\\w+)\\s*=\\s*([^;]*)");
    private static final Pattern READS_ADDRESS = Pattern.compile("URLSearchParams|\\blocation\\s*\\.|document\\s*\\.\\s*(URL|referrer)|window\\s*\\.\\s*name");
    private static final Pattern TEXT_SINK = Pattern.compile("\\.(textContent|innerText)\\s*=(?!=)|createTextNode\\s*\\(|\\.(append|prepend)\\s*\\(");

    /** What a sink is given: from just after its assignment or its opening parenthesis to the end of the statement or of the call, quotes and brackets respected. */
    private static String expression(String s, int from) {
        int depth = 0;
        char quote = 0;
        for (int i = from; i < s.length(); i++) {
            char ch = s.charAt(i);
            if (quote != 0) {
                if (ch == '\\') i++;
                else if (ch == quote) quote = 0;
            } else if (ch == '"' || ch == '\'' || ch == '`') quote = ch;
            else if (ch == '(' || ch == '[' || ch == '{') depth++;
            else if (ch == ')' || ch == ']' || ch == '}') {
                if (depth == 0) return s.substring(from, i);
                depth--;
            } else if (ch == ';' && depth == 0) return s.substring(from, i);
        }
        return s.substring(from);
    }

    /**
     * The row is decided only where the script says it: a variable read from the address, never assigned again, handed as it is to a markup sink is the weakness itself (none);
     * a script with no markup sink fed by anything but a literal, which still shows the message as text, is closed (full). Everything else (a cleansing call, a variable computed from
     * the one read, another way of writing to the page) is a delivery the judge reads, so it is inconclusive: a pattern here cannot tell a sound cleanser from a broken one.
     */
    @Test
    @Verifies({"SEC-004"})
    void sec004_noValueFromTheUrlReachesAnHtmlSink() throws Exception {
        String script = Files.readString(Path.of("src/main/resources/static/app.js")).replaceAll("(?s)/\\*.*?\\*/", "").replaceAll("(?m)//.*$", "");
        Set<String> fromAddress = new HashSet<>();
        Matcher d = FROM_ADDRESS.matcher(script);
        while (d.find()) if (READS_ADDRESS.matcher(d.group(2)).find()) fromAddress.add(d.group(1));
        for (String name : new ArrayList<>(fromAddress)) {                  // never assigned again: a later assignment may be a cleansing step
            Matcher again = Pattern.compile("(?<![\\w.])" + name + "\\s*[-+*/]?=(?!=)").matcher(script);
            int assignments = 0;
            while (again.find()) assignments++;
            if (assignments != 1) fromAddress.remove(name);
        }
        Matcher m = SINK.matcher(script);
        List<String> bare = new ArrayList<>(), other = new ArrayList<>();
        while (m.find()) {
            String rhs = expression(script, m.end()).trim();
            if (rhs.matches("([\"'`])[^\"'`$\\\\]*\\1")) continue;                // a string literal: nothing from the URL
            if (m.group(5) != null && (rhs.startsWith("function") || rhs.contains("=>"))) continue;     // a timer given a function runs no string
            (fromAddress.contains(rhs) ? bare : other).add(m.group(1).trim() + " " + abbreviate(rhs));
        }
        boolean showsText = script.contains("flash") && TEXT_SINK.matcher(script).find();
        if (!bare.isEmpty()) Verdict.structure("SEC-004", "none", "a value read from the address reaches an HTML sink as it is: " + bare);
        else if (!other.isEmpty()) Verdict.inconclusive("SEC-004", "a sink is fed by something other than a literal and other than the address's value as it is, which the judge must read: " + other);
        else if (!showsText) Verdict.inconclusive("SEC-004", "the script has no HTML sink fed by a variable, but it no longer shows the flash message as text: the judge must read it");
        else Verdict.structure("SEC-004", "full", "the page script has no HTML sink fed by a variable and still shows the message as text");
    }

    /**
     * A cell is neutralized when it no longer starts a formula and still carries its text, in whatever form the fix writes it (an apostrophe, a space, a quoted prefix): the manifest
     * contracts the CSV's quoting, not the form of the neutralization. Both CSVs the application writes are read, the delinquency report and the building archive's index.
     */
    @Test
    @Verifies({"SEC-008"})
    void sec008_aCellThatLooksLikeAFormulaIsNeutralized() throws Exception {
        String[] hostile = {"=1+1", "+1+1", "-1+1", "@SUM(1)", "\t1+1", "\r1+1"};
        for (int i = 0; i < hostile.length; i++) lateOwner("Apt 30" + (i + 1), hostile[i], i);
        lateOwner("Apt 307", "Smith, John \"JJ\"", 6);
        lateOwner("Apt 308", "Ana = Owner", 7);
        Browser marcos = loggedIn(MANAGER_A);
        List<List<String>> report = csvRows(ok(marcos, "/reports/delinquency.csv").body());
        List<String> open = new ArrayList<>(), lost = new ArrayList<>();
        for (int i = 0; i < hostile.length; i++) {
            String label = hostile[i].replace("\t", "<TAB>").replace("\r", "<CR>");
            String cell = null;
            for (List<String> r : report) if (r.get(0).equals("Apt 30" + (i + 1))) cell = r.get(1);
            if (cell == null || !cell.contains(hostile[i].strip())) lost.add("report " + label);
            else if (startsFormula(cell)) open.add("report " + label);
        }
        boolean format = report.stream().anyMatch(r -> r.get(0).equals("Apt 307") && r.get(1).equals("Smith, John \"JJ\""))
                && report.stream().anyMatch(r -> r.get(0).equals("Apt 308") && r.get(1).equals("Ana = Owner"));
        // the building archive's index carries the documents' names, which a resident chooses
        marcos.get("/documents");
        String[] names = {"=1+1.pdf", "+1+1.pdf", "-1+1.pdf", "@SUM(1).pdf"};
        for (String n : names) setupUpload(marcos, n, PDF, "OTHER");
        List<List<String>> index = csvRows(firstEntryText(marcos.post("/documents/archive")));
        for (String n : names) {
            String cell = null;
            for (List<String> r : index) if (r.size() > 1 && r.get(1).contains(n.substring(1))) cell = r.get(1);
            if (cell == null) lost.add("index " + n);
            else if (startsFormula(cell)) open.add("index " + n);
        }
        Verdict.fixed("SEC-008", open.isEmpty() && lost.isEmpty() && format, "cells that start a formula and reach a CSV un-neutralized (the delinquency report's owner names, the building archive's index of document names): "
                + (open.isEmpty() ? "none" : open) + " (expected none); cells that lost their text: " + (lost.isEmpty() ? "none" : lost) + " (expected none); commas and quotes are still quoted and a name with an equals sign inside is left as it is: " + format + " (expected true)");
    }

    /** A unit of the building with an owner of that name and an invoice that is overdue. */
    private void lateOwner(String unitLabel, String ownerName, int n) throws Exception {
        long unit = insert("insert into unit (building_id, label, ideal_fraction) values (?, ?, 0.0001) returning id", buildingA, unitLabel);
        resident("owner" + n + "@maple.test", ownerName, buildingA, unit, "OWNER");
        invoice(unit, "2025-12", LocalDate.parse("2026-01-10"), "300.00");
    }
}
