package com.condodesk.web;

import com.condodesk.auth.AuthService;
import com.condodesk.auth.Sessions;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Principal;
import com.condodesk.reports.ReportService;
import com.condodesk.reports.ReportService.BuildingTotals;
import com.condodesk.reports.ReportService.Dashboard;
import com.condodesk.web.Server.Gate;

import static com.condodesk.web.Html.esc;

/** Login, logout, the dashboard and the password change. */
final class AccountPages {
    private final AuthService auth;
    private final Sessions sessions;
    private final ReportService reports;
    private final boolean secureCookies;

    AccountPages(AuthService auth, Sessions sessions, ReportService reports, boolean secureCookies) {
        this.auth = auth;
        this.sessions = sessions;
        this.reports = reports;
        this.secureCookies = secureCookies;
    }

    void register(Server s) {
        s.route("GET", "/login", Gate.GUEST, this::loginForm);
        s.route("POST", "/login", Gate.GUEST, this::login);
        s.route("POST", "/logout", Gate.BROWSER, this::logout);
        s.route("GET", "/", Gate.BROWSER, this::dashboard);
        s.route("GET", "/account", Gate.BROWSER, this::account);
        s.route("POST", "/account/password", Gate.BROWSER, this::changePassword);
    }

    private Response loginForm(Request req) {
        if (req.principal() != null) return Response.redirect("/");
        return Response.html(form(req, req.query("next"), null, null));
    }

    private Response login(Request req) {
        String email = req.form("email");
        String next = req.form("next");
        Principal p;
        try {
            p = auth.authenticate(email, req.form("password"), req.clientAddress());
        } catch (AppException e) {
            return Response.html(e.status(), form(req, next, email, e.getMessage()));
        }
        Sessions.Issued s = sessions.login(req.sessionToken(), p.userId());
        return Response.redirect(safeNext(next)).with("Set-Cookie", sessions.cookie(s.token(), secureCookies));
    }

    private Response logout(Request req) {
        sessions.logout(req.sessionToken());
        return Response.redirect("/login").with("Set-Cookie", sessions.expiredCookie(secureCookies));
    }

    private Response dashboard(Request req) {
        Principal p = req.principal();
        Dashboard d = reports.dashboard(p);
        StringBuilder b = new StringBuilder();
        switch (p.role()) {
            case RESIDENT -> b.append("<p>Open invoices: <strong>").append(d.openInvoices()).append("</strong>, ").append(d.overdueInvoices())
                    .append(" overdue. Due today: <strong>").append(Html.money(d.openDue())).append("</strong>.</p>");
            case MANAGER -> b.append("<p>Overdue invoices: <strong>").append(d.overdueInvoices()).append("</strong>. Bookings waiting for you: <strong>")
                    .append(d.pendingBookings()).append("</strong>.</p>");
            case ADMIN -> {
                b.append("<table><tr><th>Building</th><th>Units</th><th>Open invoices</th><th></th></tr>");
                for (BuildingTotals t : d.buildings()) {
                    String q = "?building=" + t.id();
                    b.append("<tr><td>").append(esc(t.name())).append("</td><td>").append(t.units()).append("</td><td>").append(t.openInvoices())
                            .append("</td><td><a href=\"/invoices").append(q).append("\">Invoices</a> <a href=\"/reports/delinquency").append(q)
                            .append("\">Delinquency</a> <a href=\"/bookings").append(q).append("\">Bookings</a> <a href=\"/notices").append(q)
                            .append("\">Notices</a> <a href=\"/documents").append(q).append("\">Documents</a></td></tr>");
                }
                b.append("</table>");
            }
        }
        return Response.html(Html.page(req, "Welcome, " + p.name(), b.toString()));
    }

    private Response account(Request req) {
        return Response.html(Html.page(req, "Account", "<p>" + esc(req.principal().email()) + "</p>"
                + "<form method=\"post\" action=\"/account/password\">" + Html.csrf(req)
                + "<label>Current password <input type=\"password\" name=\"current\" required></label>"
                + "<label>New password <input type=\"password\" name=\"password\" required minlength=\"10\"></label>"
                + "<button>Change password</button></form>"));
    }

    private Response changePassword(Request req) {
        auth.changePassword(req.principal(), req.form("current"), req.form("password"));
        return Response.redirect(Html.flash("/account", "Password changed."));
    }

    private static String form(Request req, String next, String email, String error) {
        return Html.page(req, "Log in", (error == null ? "" : "<p class=\"error\">" + esc(error) + "</p>")
                + "<form method=\"post\" action=\"/login\">" + Html.csrf(req)
                + "<input type=\"hidden\" name=\"next\" value=\"" + esc(next) + "\">"
                + "<label>Email <input type=\"email\" name=\"email\" value=\"" + esc(email) + "\" required></label>"
                + "<label>Password <input type=\"password\" name=\"password\" required></label><button>Log in</button></form>");
    }

    /** Where to go after login: a local path only, never another site. */
    static String safeNext(String next) {
        if (next == null || !next.startsWith("/") || next.startsWith("//") || next.startsWith("/\\")) return "/";
        for (int i = 0; i < next.length(); i++) {
            if (next.charAt(i) < 0x20 || next.charAt(i) == 0x7f) return "/";
        }
        return next;
    }
}
