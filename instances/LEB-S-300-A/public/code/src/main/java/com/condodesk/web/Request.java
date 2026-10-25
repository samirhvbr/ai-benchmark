package com.condodesk.web;

import com.condodesk.auth.Sessions.Session;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Principal;
import com.fasterxml.jackson.databind.JsonNode;
import com.sun.net.httpserver.HttpExchange;

import java.io.IOException;
import java.math.BigDecimal;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.LinkedHashMap;
import java.util.Map;

/** A request as the handlers see it: path variables, query, form or JSON body, and who is calling. */
public final class Request {
    private final HttpExchange exchange;
    private final Map<String, String> vars;
    private final Map<String, String> query;
    private final byte[] body;
    private Map<String, String> form;
    private Map<String, Multipart.Part> parts;
    Principal principal;
    Session session;
    String sessionToken;

    Request(HttpExchange exchange, Map<String, String> vars, byte[] body) {
        this.exchange = exchange;
        this.vars = vars;
        this.query = decode(exchange.getRequestURI().getRawQuery());
        this.body = body;
    }

    public String method() {
        return exchange.getRequestMethod();
    }

    public String path() {
        return exchange.getRequestURI().getPath();
    }

    /** The path with its query string, as the browser asked for it. */
    public String target() {
        String q = exchange.getRequestURI().getRawQuery();
        return exchange.getRequestURI().getRawPath() + (q == null ? "" : "?" + q);
    }

    public Principal principal() {
        return principal;
    }

    public String sessionToken() {
        return sessionToken;
    }

    /** The CSRF token of the browser session, for the forms of the page. */
    public String csrf() {
        return session == null ? "" : session.csrfToken();
    }

    public String clientAddress() {
        return exchange.getRemoteAddress().getAddress().getHostAddress();
    }

    public String header(String name) {
        return exchange.getRequestHeaders().getFirst(name);
    }

    public String cookie(String name) {
        String all = header("Cookie");
        if (all == null) return null;
        for (String c : all.split(";")) {
            int eq = c.indexOf('=');
            if (eq > 0 && c.substring(0, eq).trim().equals(name)) return c.substring(eq + 1).trim();
        }
        return null;
    }

    public long id() {
        return pathLong("id");
    }

    public long pathLong(String name) {
        try {
            return Long.parseLong(vars.get(name));
        } catch (NumberFormatException e) {
            throw AppException.notFound();
        }
    }

    /** The building a page is about: the caller's own, or for an admin the one named by the {@code building} parameter. */
    public long building() {
        if (!principal.isAdmin()) {
            if (principal.buildingId() == null) throw AppException.forbidden();
            return principal.buildingId();
        }
        String v = query("building") != null ? query("building") : form("building");
        if (v == null) throw AppException.invalid("Choose a building.");
        try {
            return Long.parseLong(v);
        } catch (NumberFormatException e) {
            throw AppException.invalid("building must be a number.");
        }
    }

    public String query(String name) {
        String v = query.get(name);
        return v == null || v.isBlank() ? null : v.trim();
    }

    public Long queryLong(String name) {
        String v = query(name);
        if (v == null) return null;
        try {
            return Long.parseLong(v);
        } catch (NumberFormatException e) {
            throw AppException.invalid(name + " must be a number.");
        }
    }

    public int page() {
        Long p = queryLong("page");
        if (p == null) return 1;
        if (p < 1 || p > 100_000) throw AppException.invalid("page must be between 1 and 100000.");
        return p.intValue();
    }

    /** A text field of a form, URL-encoded or multipart. */
    public String form(String name) {
        String v;
        if (isMultipart()) {
            Multipart.Part p = parts().get(name);
            v = p == null || p.fileName() != null ? null : p.text();
        } else {
            v = form().get(name);
        }
        return v == null || v.isBlank() ? null : v.trim();
    }

    public String requireForm(String name) {
        String v = form(name);
        if (v == null) throw AppException.invalid(name + " is required.");
        return v;
    }

    public BigDecimal formMoney(String name) {
        try {
            BigDecimal v = new BigDecimal(requireForm(name));
            if (v.signum() <= 0 || v.scale() > 2) throw new NumberFormatException();
            return v;
        } catch (NumberFormatException e) {
            throw AppException.invalid(name + " must be a positive amount with at most two decimals.");
        }
    }

    public LocalDate formDate(String name) {
        try {
            return LocalDate.parse(requireForm(name));
        } catch (DateTimeParseException e) {
            throw AppException.invalid(name + " must be a date (YYYY-MM-DD).");
        }
    }

    /** An uploaded file of a multipart form. */
    public Multipart.Part file(String name) {
        Multipart.Part p = isMultipart() ? parts().get(name) : null;
        if (p == null || p.fileName() == null || p.data().length == 0) throw AppException.invalid("Choose a file to upload.");
        return p;
    }

    public JsonNode json() {
        try {
            JsonNode n = Response.JSON.readTree(body);
            if (n == null || !n.isObject()) throw AppException.invalid("The body must be a JSON object.");
            return n;
        } catch (IOException e) {
            throw AppException.invalid("The body is not valid JSON.");
        }
    }

    public byte[] body() {
        return body;
    }

    private boolean isMultipart() {
        String ct = header("Content-Type");
        return ct != null && ct.toLowerCase().startsWith("multipart/form-data");
    }

    private Map<String, Multipart.Part> parts() {
        if (parts == null) parts = Multipart.parse(header("Content-Type"), body);
        return parts;
    }

    private Map<String, String> form() {
        if (form == null) {
            String ct = header("Content-Type");
            form = ct != null && ct.startsWith("application/x-www-form-urlencoded")
                    ? decode(new String(body, StandardCharsets.UTF_8)) : Map.of();
        }
        return form;
    }

    static Map<String, String> decode(String raw) {
        Map<String, String> out = new LinkedHashMap<>();
        if (raw == null || raw.isEmpty()) return out;
        try {
            for (String pair : raw.split("&")) {
                int eq = pair.indexOf('=');
                String k = URLDecoder.decode(eq < 0 ? pair : pair.substring(0, eq), StandardCharsets.UTF_8);
                out.putIfAbsent(k, eq < 0 ? "" : URLDecoder.decode(pair.substring(eq + 1), StandardCharsets.UTF_8));
            }
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("The request is malformed.");
        }
        return out;
    }
}
