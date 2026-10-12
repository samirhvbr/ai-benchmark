package com.condodesk.web;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;

/** An HTTP response: status, content type, body and extra headers. Header values never carry CR or LF. */
public record Response(int status, String contentType, byte[] body, List<String[]> headers) {
    static final ObjectMapper JSON = new ObjectMapper();

    public static Response html(int status, String html) {
        return new Response(status, "text/html; charset=utf-8", html.getBytes(StandardCharsets.UTF_8), List.of());
    }

    public static Response html(String html) {
        return html(200, html);
    }

    /** A 303 to a local path. */
    public static Response redirect(String location) {
        return new Response(303, null, new byte[0], List.of()).with("Location", location);
    }

    public static Response json(int status, Object value) {
        try {
            return new Response(status, "application/json; charset=utf-8", JSON.writeValueAsBytes(value), List.of());
        } catch (JsonProcessingException e) {
            throw new IllegalStateException(e);
        }
    }

    /** A file the browser saves under the given name and does not try to render or sniff. */
    public static Response download(String contentType, String fileName, byte[] bytes) {
        return new Response(200, contentType, bytes, List.of())
                .with("Content-Disposition", contentDisposition(fileName))
                .with("X-Content-Type-Options", "nosniff");
    }

    public static Response text(int status, String contentType, String body) {
        return new Response(status, contentType, body.getBytes(StandardCharsets.UTF_8), List.of());
    }

    public Response with(String name, String value) {
        if (value.indexOf('\r') >= 0 || value.indexOf('\n') >= 0) throw new IllegalArgumentException("line break in header " + name);
        List<String[]> h = new ArrayList<>(headers);
        h.add(new String[]{name, value});
        return new Response(status, contentType, body, List.copyOf(h));
    }

    /** {@code attachment} with an ASCII fallback name and the exact name in RFC 5987 form. */
    static String contentDisposition(String fileName) {
        String clean = fileName.replaceAll("[\\p{Cntrl}]", "").trim();
        if (clean.isEmpty()) clean = "download";
        String ascii = clean.replaceAll("[^A-Za-z0-9._ -]", "_");
        String exact = URLEncoder.encode(clean, StandardCharsets.UTF_8).replace("+", "%20");
        return "attachment; filename=\"" + ascii + "\"; filename*=UTF-8''" + exact;
    }
}
