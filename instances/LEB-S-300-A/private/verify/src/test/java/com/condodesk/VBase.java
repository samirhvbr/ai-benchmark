package com.condodesk;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;
import java.util.zip.GZIPInputStream;

/** Base of the security verifiers: scenario steps that the system under evaluation refuses fail as "X answered NNN: …". */
abstract class VBase extends TestBase {

    /** Fails the scenario, in the form VerifiesExtension reads as "the system refused a step". */
    static IllegalStateException refused(String step, R r) {
        return new IllegalStateException(step + " answered " + r.status() + ": " + abbreviate(r.body()));
    }

    static String abbreviate(String s) {
        return s == null ? "" : s.length() > 300 ? s.substring(0, 300) + "…" : s;
    }

    /** A refusal the manifest names: 400, 401, 403 or 404 (any 4xx a request can be refused with). */
    static boolean denied(int status) {
        return status >= 400 && status < 500;
    }

    /** POST /documents as the setup of a row: a refusal here is the system's answer to a legitimate upload, so the step ends as "refused" and not without a verdict. */
    R setupUpload(Browser b, String file, byte[] data, String kind) throws Exception {
        R r = b.upload("/documents", "file", file, data, "kind", kind);
        if (r.status() != 303) throw refused("POST /documents", r);
        return r;
    }

    /** GET as the browser, requiring 200. */
    R ok(Browser b, String path) throws Exception {
        R r = b.get(path);
        if (r.status() != 200) throw refused("GET " + path, r);
        return r;
    }

    /** The cells of every row of a CSV (RFC 4180): quoted cells, doubled quotes, and line breaks inside quotes. */
    static List<List<String>> csvRows(String csv) {
        List<List<String>> rows = new ArrayList<>();
        List<String> row = new ArrayList<>();
        StringBuilder cell = new StringBuilder();
        boolean quoted = false, any = false;
        for (int i = 0; i < csv.length(); i++) {
            char ch = csv.charAt(i);
            if (quoted) {
                if (ch == '"' && i + 1 < csv.length() && csv.charAt(i + 1) == '"') { cell.append('"'); i++; }
                else if (ch == '"') quoted = false;
                else cell.append(ch);
            } else if (ch == '"') { quoted = true; any = true; }
            else if (ch == ',') { row.add(cell.toString()); cell.setLength(0); any = true; }
            else if (ch == '\r' || ch == '\n') {
                if (ch == '\r' && i + 1 < csv.length() && csv.charAt(i + 1) == '\n') i++;
                if (any || cell.length() > 0) { row.add(cell.toString()); rows.add(row); }
                row = new ArrayList<>(); cell.setLength(0); any = false;
            } else { cell.append(ch); any = true; }
        }
        if (any || cell.length() > 0) { row.add(cell.toString()); rows.add(row); }
        return rows;
    }

    /** The text of the first entry of a tar.gz answer (the building archive's index.csv), or "" when it cannot be read. */
    static String firstEntryText(R r) {
        try (GZIPInputStream in = new GZIPInputStream(new ByteArrayInputStream(r.bytes()))) {
            byte[] header = in.readNBytes(512);
            long size = Long.parseLong(new String(header, 124, 11, StandardCharsets.US_ASCII).trim(), 8);
            return new String(in.readNBytes((int) size), StandardCharsets.UTF_8);
        } catch (Exception e) {
            return "";
        }
    }

    /** True when a spreadsheet would read the cell as a formula: its first character is one of those that start one. */
    static boolean startsFormula(String cell) {
        return !cell.isEmpty() && "=+-@\t\r".indexOf(cell.charAt(0)) >= 0;
    }
}
