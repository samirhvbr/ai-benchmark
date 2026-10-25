package com.condodesk.reports;

import java.util.List;
import java.util.stream.Collectors;

/** CSV rows (RFC 4180) that a spreadsheet opens as data: a cell that would start a formula is prefixed with an apostrophe. */
public final class Csv {
    private Csv() {
    }

    public static String row(List<String> cells) {
        return cells.stream().map(Csv::cell).collect(Collectors.joining(",")) + "\r\n";
    }

    static String cell(String value) {
        String v = value == null ? "" : value;
        if (!v.isEmpty() && v.charAt(0) == '=') v = "'" + v;
        if (v.contains(",") || v.contains("\"") || v.contains("\n") || v.contains("\r")) v = "\"" + v.replace("\"", "\"\"") + "\"";
        return v;
    }
}
