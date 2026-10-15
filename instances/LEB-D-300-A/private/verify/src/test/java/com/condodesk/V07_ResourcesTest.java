package com.condodesk;

import org.junit.jupiter.api.Test;

import java.io.ByteArrayOutputStream;
import java.io.PrintStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.logging.Handler;
import java.util.logging.Level;
import java.util.logging.LogRecord;
import java.util.logging.Logger;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Stream;

/** What the system opens and runs: connections given back, an external command's failure, and the trace of an internal error. */
class V07_ResourcesTest extends VBase {

    @Test
    @Verifies({"BUG-003"})
    void bug003_aReadThatFailsGivesItsConnectionBack() throws Exception {
        long other = invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        String t = token(OWNER_A101);
        instr.reset();
        List<Integer> statuses = new ArrayList<>();
        for (int i = 0; i < 50; i++) statuses.add(api("GET", "/api/invoices/" + other, t, null).status());
        long opened = instr.opened.get(), closed = instr.closed.get();
        boolean notFound = statuses.stream().allMatch(s -> s == 404);
        Verdict.fixed("BUG-003", notFound && opened == closed, "50 requests for another unit's invoice answered " + (notFound ? "404 each" : statuses)
                + "; the application opened " + opened + " connections and closed " + closed + " (expected as many closed as opened)");
    }

    /** The stored file of a document, wherever the application keeps it under its storage folder. */
    private Path storedFile(long documentId) throws Exception {
        String name = (String) value("select stored_name from document where id = ?", documentId);
        try (Stream<Path> files = Files.walk(storage)) {
            return files.filter(p -> p.getFileName().toString().equals(name)).findFirst()
                    .orElseThrow(() -> new IllegalStateException("the stored file of document " + documentId + " is not under the storage folder"));
        }
    }

    private long upload(Browser m, String name) throws Exception {
        R r = m.upload("/documents", "file", name, PDF, "kind", "MINUTES");
        if (r.status() != 303) throw refused("POST /documents", r);
        return count("select max(id) from document");
    }

    @Test
    @Verifies({"BUG-010"})
    void bug010_anArchiveWithAMissingFileIsNotServedAsWhole() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        ok(m, "/documents");
        upload(m, "minutes.pdf");
        long gone = upload(m, "rules.pdf");
        ok(m, "/documents");
        R whole = m.post("/documents/archive", "label", "whole");
        Files.delete(storedFile(gone));
        R missing = m.post("/documents/archive", "label", "missing");
        boolean ok = whole.status() == 200 && missing.status() >= 400;
        Verdict.fixed("BUG-010", ok, "the archive of two documents answered " + whole.status() + " (expected 200); with one stored file deleted it answered "
                + missing.status() + " (expected an error, not 200)");
    }

    private static final Pattern INCIDENT = Pattern.compile("incident ([0-9a-f]{8})");

    @Test
    @Verifies({"BUG-015"})
    void bug015_theRecordOfAnInternalErrorCarriesItsCause() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        ok(m, "/documents");
        long id = upload(m, "minutes.pdf");
        Files.delete(storedFile(id));
        List<LogRecord> records = Collections.synchronizedList(new ArrayList<>());
        Handler capture = new Handler() {
            @Override public void publish(LogRecord r) { records.add(r); }
            @Override public void flush() { }
            @Override public void close() { }
        };
        capture.setLevel(Level.ALL);
        Logger root = Logger.getLogger("");
        PrintStream err = System.err, out = System.out;
        ByteArrayOutputStream printed = new ByteArrayOutputStream();
        R r;
        root.addHandler(capture);
        try {
            PrintStream both = new PrintStream(printed, true, StandardCharsets.UTF_8);
            System.setErr(both);
            System.setOut(both);
            r = m.get("/documents/" + id + "/download");
        } finally {
            System.setErr(err);
            System.setOut(out);
            root.removeHandler(capture);
        }
        Matcher inc = INCIDENT.matcher(r.body());
        if (r.status() != 500 || !inc.find()) {
            Verdict.inconclusive("BUG-015", "the download of a document whose file is gone answered " + r.status() + " without an incident id; the scenario does not reach an internal error");
            return;
        }
        String incident = inc.group(1);
        boolean generic = !r.body().contains("NoSuchFile") && !r.body().contains("Exception");
        boolean inRecord = false;
        synchronized (records) {
            for (LogRecord rec : records) {
                String text = String.valueOf(rec.getMessage()) + " " + java.util.Arrays.toString(rec.getParameters());
                if (text.contains(incident) && (carriesTheCause(rec.getThrown()) || text.contains("NoSuchFileException") && text.contains("\tat "))) inRecord = true;
            }
        }
        String console = printed.toString(StandardCharsets.UTF_8);
        int at = console.indexOf(incident);
        boolean inConsole = at >= 0 && console.contains("NoSuchFileException") && console.contains("\tat ");
        boolean ok = generic && (inRecord || inConsole);
        Verdict.fixed("BUG-015", ok, "the download of a document whose file is gone answered 500 with incident " + incident + (generic ? ", without the cause" : ", WITH the cause")
                + "; a log record with that incident carries the exception of the missing file, itself or as the cause of what was thrown: " + inRecord
                + "; the process output carries the incident, the exception and its stack: " + inConsole);
    }

    /** The failure's own exception is the missing file's, itself or somewhere down the chain of causes (a wrapper that dropped it carries nothing). */
    private static boolean carriesTheCause(Throwable thrown) {
        Throwable t = thrown;
        for (int hops = 0; t != null && hops < 20; hops++, t = t.getCause()) {
            if (t instanceof java.nio.file.NoSuchFileException) return true;
        }
        return false;
    }
}
