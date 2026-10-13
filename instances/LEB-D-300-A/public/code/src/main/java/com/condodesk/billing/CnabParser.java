package com.condodesk.billing;

import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;

/** Reads the bank's return file in the CNAB 240 layout: one settled title per pair of T and U segments. */
public final class CnabParser {
    private static final DateTimeFormatter DATE = DateTimeFormatter.ofPattern("ddMMyyyy");

    /** One settled title of the return file. */
    public record Title(String ourNumber, BigDecimal amount, LocalDate paidOn) {}

    private CnabParser() {
    }

    public static List<Title> parse(byte[] file) {
        List<Title> titles = new ArrayList<>();
        String ourNumber = null;
        for (String line : new String(file, StandardCharsets.ISO_8859_1).split("\r?\n")) {
            if (line.length() < 240 || line.charAt(7) != '3') continue;          // detail records only
            char segment = line.charAt(13);
            if (segment == 'T') {
                ourNumber = line.substring(37, 57).trim();
            } else if (segment == 'U' && ourNumber != null) {
                BigDecimal paid = new BigDecimal(line.substring(77, 92)).movePointLeft(2);
                titles.add(new Title(ourNumber, paid, LocalDate.parse(line.substring(137, 145), DATE)));
                ourNumber = null;
            }
        }
        return titles;
    }
}
