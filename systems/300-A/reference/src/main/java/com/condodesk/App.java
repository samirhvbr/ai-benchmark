package com.condodesk;

import com.condodesk.auth.AuthService;
import com.condodesk.auth.LoginThrottle;
import com.condodesk.auth.Sessions;
import com.condodesk.billing.BillingService;
import com.condodesk.billing.PaymentService;
import com.condodesk.booking.BookingService;
import com.condodesk.db.Db;
import com.condodesk.docs.Archiver;
import com.condodesk.docs.DocumentService;
import com.condodesk.docs.UrlFetcher;
import com.condodesk.notices.NoticeService;
import com.condodesk.reports.ReportService;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BookingRepository;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.ContentRepository;
import com.condodesk.repo.FinanceRepository;
import com.condodesk.repo.ReportRepository;
import com.condodesk.repo.UserRepository;
import com.condodesk.web.Routes;
import com.condodesk.web.Server;
import org.postgresql.ds.PGSimpleDataSource;

import javax.sql.DataSource;
import java.time.Clock;
import java.util.Map;

/** Builds CondoDesk from its configuration, a data source and a clock. */
public final class App {
    private final Server server;
    private final int port;

    private App(Server server, int port) {
        this.server = server;
        this.port = port;
    }

    public static App create(Config config, DataSource dataSource, Clock clock) {
        Db db = new Db(dataSource);
        UserRepository users = new UserRepository();
        BuildingRepository buildings = new BuildingRepository();
        FinanceRepository finance = new FinanceRepository();
        BookingRepository bookingRepo = new BookingRepository();
        ContentRepository content = new ContentRepository();
        ReportRepository reportRepo = new ReportRepository();
        AuditLog audit = new AuditLog();

        Sessions sessions = new Sessions(db, clock, config.sessionIdle());
        AuthService auth = new AuthService(db, users, buildings, new LoginThrottle(db, clock), audit, clock);
        BillingService billing = new BillingService(db, buildings, finance, audit, clock);
        PaymentService payments = new PaymentService(db, buildings, finance, audit, clock, config.bankSecret());
        BookingService bookings = new BookingService(db, buildings, bookingRepo, finance, audit, clock);
        NoticeService notices = new NoticeService(db, content, audit, clock);
        DocumentService documents = new DocumentService(db, buildings, content, finance, audit, clock, config.storageDir(),
                config.maxUploadBytes(), new UrlFetcher(config.fetchAllowedHosts(), config.maxUploadBytes()), new Archiver());
        ReportService reports = new ReportService(db, buildings, finance, reportRepo, clock);

        Server server = new Server(sessions, auth, config.secureCookies(), config.maxUploadBytes());
        Routes.register(server, config, sessions, auth, billing, payments, bookings, notices, documents, reports);
        return new App(server, config.port());
    }

    /** Starts listening and returns the port. */
    public int start() {
        return server.start(port);
    }

    public void stop() {
        server.stop();
    }

    public static void main(String[] args) {
        Map<String, String> env = System.getenv();
        PGSimpleDataSource ds = new PGSimpleDataSource();
        ds.setUrl(env.getOrDefault("CONDODESK_DB_URL", "jdbc:postgresql://127.0.0.1:5432/condodesk"));
        ds.setUser(env.getOrDefault("CONDODESK_DB_USER", "condodesk"));
        ds.setPassword(env.get("CONDODESK_DB_PASSWORD"));
        int port = create(Config.fromEnvironment(env), ds, Clock.systemUTC()).start();
        System.out.println("CondoDesk listening on port " + port);
    }
}
