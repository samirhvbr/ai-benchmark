package com.condodesk.web;

import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.Role;
import com.condodesk.domain.Principal;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.text.DecimalFormat;
import java.text.DecimalFormatSymbols;
import java.util.Locale;
import java.util.Map;
import java.util.stream.Collectors;

/** HTML building blocks. Every value that did not come from this code goes through {@link #esc}. */
public final class Html {
    private Html() {
    }

    public static String esc(Object value) {
        if (value == null) return "";
        String s = value.toString();
        StringBuilder b = new StringBuilder(s.length() + 16);
        for (int i = 0; i < s.length(); i++) {
            char ch = s.charAt(i);
            switch (ch) {
                case '&' -> b.append("&amp;");
                case '<' -> b.append("&lt;");
                case '>' -> b.append("&gt;");
                case '"' -> b.append("&quot;");
                case '\'' -> b.append("&#39;");
                default -> b.append(ch);
            }
        }
        return b.toString();
    }

    public static String money(BigDecimal value) {
        DecimalFormat f = new DecimalFormat("#,##0.00", DecimalFormatSymbols.getInstance(Locale.US));
        return f.format(value.setScale(2, RoundingMode.HALF_UP));
    }

    public static String url(String value) {
        return URLEncoder.encode(value, StandardCharsets.UTF_8);
    }

    public static String csrf(Request req) {
        return "<input type=\"hidden\" name=\"_csrf\" value=\"" + esc(req.csrf()) + "\">";
    }

    public static String page(Request req, String title, String body) {
        Principal p = req == null ? null : req.principal();
        StringBuilder h = new StringBuilder(4096);
        h.append("<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>").append(esc(title))
                .append(" · CondoDesk</title><link rel=\"stylesheet\" href=\"/static/app.css\"></head>\n<body><header><a class=\"brand\" href=\"/\">CondoDesk</a>");
        if (p != null) {
            h.append("<nav><a href=\"/invoices\">Invoices</a> <a href=\"/bookings\">Bookings</a> <a href=\"/notices\">Notices</a> ")
                    .append("<a href=\"/documents\">Documents</a>");
            if (p.role() != Role.RESIDENT) h.append(" <a href=\"/reports/delinquency\">Reports</a>");
            if (p.isAdmin()) h.append(" <a href=\"/admin/users\">Admin</a>");
            h.append(" <a href=\"/account\">Account</a></nav><span class=\"who\">").append(esc(p.name())).append("</span>")
                    .append("<form method=\"post\" action=\"/logout\">").append(csrf(req)).append("<button>Log out</button></form>");
        }
        h.append("</header>\n<div id=\"flash\" class=\"flash\" hidden></div>\n<main><h1>").append(esc(title)).append("</h1>\n")
                .append(body).append("\n</main><script src=\"/static/app.js\"></script></body></html>\n");
        return h.toString();
    }

    public static String error(Request req, int status, String message) {
        return page(req, status == 404 ? "Not found" : "Something is wrong", "<p class=\"error\">" + esc(message) + "</p>");
    }

    /** Links to the other pages of a list, keeping its filters. */
    public static String pager(String path, Map<String, String> filters, Page<?> page) {
        if (page.pages() <= 1) return "";
        String base = filters.entrySet().stream().filter(e -> e.getValue() != null)
                .map(e -> url(e.getKey()) + "=" + url(e.getValue()) + "&").collect(Collectors.joining());
        StringBuilder h = new StringBuilder("<p class=\"pager\">");
        if (page.page() > 1) h.append("<a href=\"").append(esc(path + "?" + base + "page=" + (page.page() - 1))).append("\">Previous</a> ");
        h.append("Page ").append(page.page()).append(" of ").append(page.pages());
        if (page.page() < page.pages()) h.append(" <a href=\"").append(esc(path + "?" + base + "page=" + (page.page() + 1))).append("\">Next</a>");
        return h.append("</p>").toString();
    }

    /** A redirect target with a message for the next page to show. */
    public static String flash(String path, String message) {
        return path + (path.contains("?") ? "&" : "?") + "flash=" + url(message);
    }
}
