package com.condodesk.web;

import com.condodesk.domain.AppException;

import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.Locale;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** A minimal multipart/form-data reader for the upload forms: text fields and files, all in memory. */
final class Multipart {
    private static final Pattern BOUNDARY = Pattern.compile("boundary=(?:\"([^\"]+)\"|([^;\\s]+))");
    private static final Pattern NAME = Pattern.compile("\\bname=\"([^\"]*)\"");
    private static final Pattern FILENAME = Pattern.compile("\\bfilename=\"([^\"]*)\"");

    /** One part of the form. A text field has no file name. */
    record Part(String name, String fileName, String contentType, byte[] data) {
        String text() {
            return new String(data, StandardCharsets.UTF_8);
        }
    }

    private Multipart() {
    }

    static Map<String, Part> parse(String contentType, byte[] body) {
        Matcher m = BOUNDARY.matcher(contentType);
        if (!m.find()) throw AppException.invalid("The form has no boundary.");
        byte[] delimiter = ("--" + (m.group(1) != null ? m.group(1) : m.group(2))).getBytes(StandardCharsets.ISO_8859_1);
        Map<String, Part> parts = new LinkedHashMap<>();
        int pos = indexOf(body, delimiter, 0);
        if (pos < 0) throw AppException.invalid("The form is malformed.");
        while (true) {
            int start = pos + delimiter.length;
            if (start + 1 < body.length && body[start] == '-' && body[start + 1] == '-') break;   // closing delimiter
            start += 2;                                                                             // CRLF after the delimiter
            int headersEnd = indexOf(body, "\r\n\r\n".getBytes(StandardCharsets.ISO_8859_1), start);
            int next = indexOf(body, delimiter, start);
            if (headersEnd < 0 || next < 0 || headersEnd > next) throw AppException.invalid("The form is malformed.");
            String headers = new String(body, start, headersEnd - start, StandardCharsets.UTF_8);
            byte[] data = Arrays.copyOfRange(body, headersEnd + 4, next - 2);                       // CRLF before the next delimiter
            String disposition = header(headers, "content-disposition");
            Matcher name = NAME.matcher(disposition);
            if (name.find()) {
                Matcher file = FILENAME.matcher(disposition);
                parts.put(name.group(1), new Part(name.group(1), file.find() ? file.group(1) : null, header(headers, "content-type"), data));
            }
            pos = next;
        }
        return parts;
    }

    private static String header(String headers, String wanted) {
        for (String line : headers.split("\r\n")) {
            int colon = line.indexOf(':');
            if (colon > 0 && line.substring(0, colon).trim().toLowerCase(Locale.ROOT).equals(wanted)) return line.substring(colon + 1).trim();
        }
        return "";
    }

    private static int indexOf(byte[] haystack, byte[] needle, int from) {
        outer:
        for (int i = Math.max(from, 0); i <= haystack.length - needle.length; i++) {
            for (int j = 0; j < needle.length; j++) {
                if (haystack[i + j] != needle[j]) continue outer;
            }
            return i;
        }
        return -1;
    }
}
