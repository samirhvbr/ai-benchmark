package com.condodesk.web;

import com.condodesk.Config;
import com.condodesk.auth.AuthService;
import com.condodesk.auth.Sessions;
import com.condodesk.billing.FinanceService;
import com.condodesk.booking.BookingService;
import com.condodesk.db.Db;
import com.condodesk.docs.DocumentService;
import com.condodesk.domain.AppException;
import com.condodesk.notices.NoticeService;
import com.condodesk.reports.ReportService;
import com.condodesk.web.Server.Gate;

import java.io.IOException;
import java.io.InputStream;
import java.time.Clock;
import java.util.Map;

/** Every route of the application, page by page, plus the static files. */
public final class Routes {
    private static final Map<String, String> STATIC = Map.of("app.js", "text/javascript; charset=utf-8", "app.css", "text/css; charset=utf-8");

    private Routes() {
    }

    public static void register(Server server, Config config, Db db, Clock clock, Sessions sessions, AuthService auth, FinanceService billing,
                                BookingService bookings, NoticeService notices, DocumentService documents,
                                ReportService reports) {
        new AccountPages(auth, sessions, reports, config.secureCookies()).register(server);
        new FinancePages(billing, documents).register(server);
        new BookingPages(bookings).register(server);
        new ContentPages(notices, documents).register(server);
        new ReportPages(reports, db, clock).register(server);
        new AdminPages(auth).register(server);
        new MobileApi(auth, sessions, billing, bookings).register(server);
        server.route("GET", "/static/{name}", Gate.OPEN, Routes::staticFile);
    }

    private static Response staticFile(Request req) throws IOException {
        String name = req.path().substring("/static/".length());
        String type = STATIC.get(name);
        if (type == null) throw AppException.notFound();
        try (InputStream in = Routes.class.getResourceAsStream("/static/" + name)) {
            if (in == null) throw AppException.notFound();
            return new Response(200, type, in.readAllBytes(), java.util.List.of()).with("Cache-Control", "public, max-age=3600");
        }
    }
}
