"""ARCH rows: splits and moves done another way are accepted; partial and cosmetic ones are not."""

import os

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(HERE))))
LEGACY = os.path.join(HERE, "..", "..", "public", "code", "src", "main", "java", "com", "condodesk")
REFERENCE = os.path.join(ROOT, "systems", "300-A", "reference", "src", "main", "java", "com", "condodesk")
SRC = "src/main/java/com/condodesk/"


def read(base, path):
    with open(os.path.join(base, path), encoding="utf-8") as f:
        return f.read()


def charges_as_money():
    text = read(REFERENCE, "billing/Charges.java")
    return (text.replace("package com.condodesk.billing;", "package com.condodesk.money;")
            .replace("public final class Charges", "public final class Money").replace("private Charges()", "private Money()"))


def use_money(text):
    text = text.replace("Charges.", "Money.")
    if "import com.condodesk.billing.Charges;" in text:
        return text.replace("import com.condodesk.billing.Charges;", "import com.condodesk.money.Money;")
    return text.replace("package com.condodesk.billing;\n", "package com.condodesk.billing;\n\nimport com.condodesk.money.Money;\n", 1)


def moved_to_repo(path):
    return read(LEGACY, path).replace("package com.condodesk.reports;", "package com.condodesk.repo;").replace("com.condodesk.reports.", "com.condodesk.repo.")


def reports_to_repo(text):
    return text.replace("com.condodesk.reports.", "com.condodesk.repo.")


FACADE = '''package com.condodesk.billing;

import com.condodesk.domain.Principal;

import java.math.BigDecimal;
import java.time.YearMonth;

/** One entry point for the money side of the pages and the API. */
public final class Finance {
    private Finance() {
    }

    public static BillingService.RunResult run(BillingService billing, Principal p, long buildingId, YearMonth month, BigDecimal budget) {
        return billing.run(p, buildingId, month, budget);
    }

    public static PaymentService.Outcome webhook(PaymentService payments, byte[] body, String signature) {
        return payments.webhook(body, signature);
    }
}
'''

STATIC_SOURCE = '''    /** The application's data source, set by {@code App.create}. */
    private static DataSource dataSource;

    public static void configure(DataSource ds) {
        dataSource = ds;
    }'''
INSTANCE_SOURCE = '''    private final DataSource dataSource;

    public Db(DataSource dataSource) {
        this.dataSource = dataSource;
    }'''
APP_DB = "        Db.configure(dataSource);\n        Db db = new Db();"

COLLECTION_PAGE = '''    private Response collection(Request req) {
        long building = req.building();
        req.principal().requireManager(building);
        Long y = req.queryLong("year");
        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid("year must be between 2000 and 2100.");
        StringBuilder b = new StringBuilder("<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>");
        int year = db.read(c -> {
            String zone = Db.one(c, "select time_zone from building where id = ?", rs -> rs.getString("time_zone"), building)
                    .orElseThrow(AppException::notFound);
            int shown = y != null ? y.intValue() : LocalDate.ofInstant(clock.instant(), ZoneId.of(zone)).getYear();
            Db.list(c, "select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, "
                            + "coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id "
                            + "left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id "
                            + "where u.building_id = ? and i.month like ? group by i.month order by i.month",
                    rs -> {
                        BigDecimal billed = rs.getBigDecimal("billed");
                        BigDecimal settled = rs.getBigDecimal("settled");
                        String share = billed.signum() == 0 ? "—"
                                : settled.multiply(BigDecimal.valueOf(100)).divide(billed, 1, RoundingMode.HALF_UP).toPlainString() + "%";
                        b.append("<tr><td>").append(rs.getString("month")).append("</td><td class=\\"num\\">").append(Html.money(billed))
                                .append("</td><td class=\\"num\\">").append(Html.money(settled)).append("</td><td class=\\"num\\">")
                                .append(Html.money(rs.getBigDecimal("received"))).append("</td><td class=\\"num\\">").append(share).append("</td></tr>");
                        return share;
                    },
                    building, String.format("%04d-%%", shown));
            return shown;
        });
        return Response.html(Html.page(req, "Collection " + year, b.append("</table>").toString()));
    }'''
COLLECTION_PAGE_FIXED = '''    private Response collection(Request req) {
        long building = req.building();
        req.principal().requireManager(building);
        Long y = req.queryLong("year");
        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid("year must be between 2000 and 2100.");
        ReportService.YearCollection year = reports.collection(req.principal(), building, y == null ? null : y.intValue());
        StringBuilder b = new StringBuilder("<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>");
        for (ReportService.MonthCollection m : year.months()) {
            String share = m.billed().signum() == 0 ? "—"
                    : m.settled().multiply(BigDecimal.valueOf(100)).divide(m.billed(), 1, RoundingMode.HALF_UP).toPlainString() + "%";
            b.append("<tr><td>").append(m.month()).append("</td><td class=\\"num\\">").append(Html.money(m.billed()))
                    .append("</td><td class=\\"num\\">").append(Html.money(m.settled())).append("</td><td class=\\"num\\">")
                    .append(Html.money(m.received())).append("</td><td class=\\"num\\">").append(share).append("</td></tr>");
        }
        return Response.html(Html.page(req, "Collection " + year.year(), b.append("</table>").toString()));
    }'''
PAGE_FIELDS = '''    private final ReportService reports;
    private final Db db;
    private final Clock clock;

    ReportPages(ReportService reports, Db db, Clock clock) {
        this.reports = reports;
        this.db = db;
        this.clock = clock;
    }'''
COLLECTION_SERVICE = '''    /** What was billed, settled and received each month of a year. */
    public record MonthCollection(String month, BigDecimal billed, BigDecimal settled, BigDecimal received) {}

    public record YearCollection(int year, List<MonthCollection> months) {}

    public YearCollection collection(Principal p, long buildingId, Integer year) {
        p.requireManager(buildingId);
        return db.read(c -> {
            Building b = buildings.byId(c, buildingId).orElseThrow(AppException::notFound);
            int shown = year != null ? year : b.dateAt(clock.instant()).getYear();
            List<MonthCollection> months = Db.list(c, "select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, "
                            + "coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id "
                            + "left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id "
                            + "where u.building_id = ? and i.month like ? group by i.month order by i.month",
                    rs -> new MonthCollection(rs.getString("month"), rs.getBigDecimal("billed"), rs.getBigDecimal("settled"), rs.getBigDecimal("received")),
                    buildingId, String.format("%04d-%%", shown));
            return new YearCollection(shown, months);
        });
    }

    public Dashboard dashboard(Principal p) {'''
ROUTES_SIGNATURE = "    public static void register(Server server, Config config, Db db, Clock clock, Sessions sessions,"

RESIDENT_LOOP = '''        for (Invoice i : open) {
            long late = i.dueDate().isBefore(today) ? ChronoUnit.DAYS.between(i.dueDate(), today) : 0;
            BigDecimal owed = i.amount();
            if (late > 0) {
                owed = owed.add(i.amount().multiply(b.lateFeePct()).divide(BigDecimal.valueOf(100), 12, RoundingMode.HALF_EVEN))
                        .add(i.amount().multiply(b.monthlyInterestPct()).multiply(BigDecimal.valueOf(late))
                                .divide(BigDecimal.valueOf(3000), 12, RoundingMode.HALF_EVEN));
            }
            due = due.add(owed.setScale(2, RoundingMode.HALF_UP));
        }'''

NOTICE_INSERT = '''            long id = Db.insert(c, "insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)",
                    buildingId, p.userId(), title, body, pinned, clock.instant());'''
NOTICE_GET = '''        return db.read(c -> Db.one(c, "select n.id, n.building_id, u.name as author_name, n.title, n.body, n.pinned, n.published_at, "
                        + "(n.published_at at time zone (select time_zone from building where building.id = n.building_id))::date as published_on "
                        + "from notice n join app_user u on u.id = n.author_id where n.id = ?",
                rs -> new Notice(rs.getLong("id"), rs.getLong("building_id"), rs.getString("author_name"), rs.getString("title"),
                        rs.getString("body"), rs.getBoolean("pinned"), Db.instant(rs, "published_at"), Db.date(rs, "published_on")), id))
                .filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);'''
NOTICE_REPO = '''    public long insertNotice(Connection c, long buildingId, long authorId, String title, String body, boolean pinned, Instant now) throws SQLException {
        return Db.insert(c, "insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)",
                buildingId, authorId, title, body, pinned, now);
    }

    public Optional<Notice> noticeById(Connection c, long id) throws SQLException {
        return Db.one(c, NOTICE + " where n.id = ?", ContentRepository::notice, id);
    }

    public long insertDocument('''
NOTICE_SQL_MOVED = [("repo/ContentRepository.java", "    public long insertDocument(", NOTICE_REPO),
                    ("notices/NoticeService.java", NOTICE_INSERT, "            long id = content.insertNotice(c, buildingId, p.userId(), title, body, pinned, clock.instant());"),
                    ("notices/NoticeService.java", NOTICE_GET,
                     "        return db.read(c -> content.noticeById(c, id)).filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);")]

TITLE = 'if (title == null || title.length() > 200) throw AppException.invalid("The title is required and has at most 200 characters.");'
BODY = 'if (body == null || body.length() > 10000) throw AppException.invalid("The text is required and has at most 10000 characters.");'
WORDS = 'if (words.size() > 5) throw AppException.invalid("Search with at most 5 words.");'
PAGE = "    public static final int PAGE_SIZE = 10;"
LIMITS = PAGE + "\n    static final int MAX_TITLE = 200;\n    static final int MAX_BODY = 10_000;\n    static final int MAX_WORDS = 5;"

CASES = [
    # ---- ARCH-001: who bills and who settles
    dict(target="ARCH-001", name="arch001-reference", kind="alternative", expect="fixed", base="reference",
         note="the reference itself: billing, payments and the money rules apart"),
    dict(target="ARCH-001", name="arch001-split-with-other-names", kind="alternative", expect="fixed", base="reference",
         delete=["billing/Charges.java"], files={SRC + "money/Money.java": charges_as_money()},
         edits=[("billing/BillingService.java", use_money), ("billing/PaymentService.java", use_money), ("reports/ReportService.java", use_money)],
         note="the money rules renamed and moved to a package of their own: the verifier reads behavior, not names"),
    dict(target="ARCH-001", name="arch001-facade-on-both-paths", kind="partial", expect="half", base="reference",
         files={SRC + "billing/Finance.java": FACADE},
         edits=[("web/FinancePages.java", "RunResult r = billing.run(req.principal(),", "RunResult r = com.condodesk.billing.Finance.run(billing, req.principal(),"),
                ("web/MobileApi.java", 'PaymentService.Outcome o = payments.webhook(req.body(),',
                 'PaymentService.Outcome o = com.condodesk.billing.Finance.webhook(payments, req.body(),')],
         note="one class still sits on the billing run and on the settlement"),
    dict(target="ARCH-001", name="arch001-money-rules-take-the-database", kind="partial", expect="half", base="reference",
         edits=[("billing/Charges.java", "    private Charges() {\n    }",
                 "    private Charges() {\n    }\n\n    static Object database(com.condodesk.db.Db db) {\n        return db;\n    }")],
         note="billing and settlement apart, but the class that splits a budget reaches the database helper"),

    # ---- ARCH-013: one data source per application
    dict(target="ARCH-013", name="arch013-instance-data-source", kind="alternative", expect="fixed",
         edits=[("db/Db.java", STATIC_SOURCE, INSTANCE_SOURCE), ("App.java", APP_DB, "        Db db = new Db(dataSource);")]),
    dict(target="ARCH-013", name="arch013-static-kept-beside", kind="partial", expect="half",
         edits=[("db/Db.java", STATIC_SOURCE, INSTANCE_SOURCE.replace("        this.dataSource = dataSource;", "        this.dataSource = dataSource;\n        last = dataSource;")
                 + "\n\n    private static DataSource last;"),
                ("App.java", APP_DB, "        Db db = new Db(dataSource);")],
         note="each application uses its own, but a static field still holds the last data source"),
    dict(target="ARCH-013", name="arch013-thread-local", kind="wrong", expect="not_fixed",
         edits=[("db/Db.java", "    private static DataSource dataSource;", "    private static final ThreadLocal<DataSource> SOURCE = new ThreadLocal<>();"),
                ("db/Db.java", "        dataSource = ds;", "        SOURCE.set(ds);"),
                ("db/Db.java", lambda t: t.replace("dataSource.getConnection()", "SOURCE.get().getConnection()"))],
         note="the server's threads never see the value the building thread set"),

    # ---- ARCH-008: no cycle between packages
    dict(target="ARCH-008", name="arch008-record-with-the-data-access", kind="alternative", expect="fixed",
         edits=[("repo/ReportRepository.java", "import com.condodesk.reports.ReportService.BuildingTotals;\n", ""),
                ("repo/ReportRepository.java", "public final class ReportRepository {\n",
                 "public final class ReportRepository {\n    /** A building as the admin's dashboard lists it. */\n    public record BuildingTotals(long id, String name, int units, int openInvoices) {}\n"),
                ("reports/ReportService.java", "    /** A building as the admin's dashboard lists it. */\n    public record BuildingTotals(long id, String name, int units, int openInvoices) {}\n", ""),
                ("reports/ReportService.java", "import com.condodesk.repo.ReportRepository;\n",
                 "import com.condodesk.repo.ReportRepository;\nimport com.condodesk.repo.ReportRepository.BuildingTotals;\n"),
                ("web/AccountPages.java", "import com.condodesk.reports.ReportService.BuildingTotals;", "import com.condodesk.repo.ReportRepository.BuildingTotals;")]),
    dict(target="ARCH-008", name="arch008-packages-merged", kind="wrong", expect="not_fixed",
         delete=["reports/ReportService.java", "reports/Csv.java"],
         files={SRC + "repo/ReportService.java": moved_to_repo("reports/ReportService.java"), SRC + "repo/Csv.java": moved_to_repo("reports/Csv.java")},
         edits=[(p, reports_to_repo) for p in ("App.java", "web/ReportPages.java", "web/AccountPages.java", "web/Routes.java", "docs/DocumentService.java",
                                               "repo/ReportRepository.java")],
         note="the cycle disappears into one package: below the floor of ten packages"),

    # ---- ARCH-002: the HTTP layer and the database
    dict(target="ARCH-002", name="arch002-collection-in-the-service", kind="alternative", expect="fixed",
         edits=[("reports/ReportService.java", "    public Dashboard dashboard(Principal p) {", COLLECTION_SERVICE),
                ("web/ReportPages.java", PAGE_FIELDS, "    private final ReportService reports;\n\n    ReportPages(ReportService reports) {\n        this.reports = reports;\n    }"),
                ("web/ReportPages.java", COLLECTION_PAGE, COLLECTION_PAGE_FIXED),
                ("web/Routes.java", ROUTES_SIGNATURE, "    public static void register(Server server, Config config, Sessions sessions,"),
                ("web/Routes.java", "new ReportPages(reports, db, clock)", "new ReportPages(reports)"),
                ("App.java", "Routes.register(server, config, db, clock, sessions,", "Routes.register(server, config, sessions,")]),
    dict(target="ARCH-002", name="arch002-routes-still-carry-the-database", kind="partial", expect="not_fixed",
         edits=[("reports/ReportService.java", "    public Dashboard dashboard(Principal p) {", COLLECTION_SERVICE),
                ("web/ReportPages.java", PAGE_FIELDS, "    private final ReportService reports;\n\n    ReportPages(ReportService reports) {\n        this.reports = reports;\n    }"),
                ("web/ReportPages.java", COLLECTION_PAGE, COLLECTION_PAGE_FIXED),
                ("web/Routes.java", "new ReportPages(reports, db, clock)", "new ReportPages(reports)")],
         note="the page no longer queries, but the routing still takes the database helper"),

    # ---- ARCH-004: one amount-due rule
    dict(target="ARCH-004", name="arch004-dashboard-calls-the-rule", kind="alternative", expect="fixed",
         edits=[("reports/ReportService.java", RESIDENT_LOOP, "        for (Invoice i : open) due = due.add(FinanceService.amountDue(i.amount(), i.dueDate(), b, today));")]),
    dict(target="ARCH-004", name="arch004-copy-kept-in-a-helper", kind="wrong", expect="not_fixed",
         edits=[("reports/ReportService.java", RESIDENT_LOOP, "        for (Invoice i : open) due = due.add(owedToday(i, b, today));"),
                ("reports/ReportService.java", "    private Dashboard manager(Connection c, long buildingId) throws SQLException {", '''    private static BigDecimal owedToday(Invoice i, Building b, LocalDate today) {
        long late = i.dueDate().isBefore(today) ? ChronoUnit.DAYS.between(i.dueDate(), today) : 0;
        BigDecimal owed = i.amount();
        if (late > 0) {
            owed = owed.add(i.amount().multiply(b.lateFeePct()).divide(BigDecimal.valueOf(100), 12, RoundingMode.HALF_EVEN))
                    .add(i.amount().multiply(b.monthlyInterestPct()).multiply(BigDecimal.valueOf(late))
                            .divide(BigDecimal.valueOf(3000), 12, RoundingMode.HALF_EVEN));
        }
        return owed.setScale(2, RoundingMode.HALF_UP);
    }

    private Dashboard manager(Connection c, long buildingId) throws SQLException {''')],
         note="the copy moved into a method of the same class"),

    # ---- ARCH-003: the notice SQL with the data access
    dict(target="ARCH-003", name="arch003-sql-in-the-repository", kind="alternative", expect="fixed", edits=NOTICE_SQL_MOVED),
    dict(target="ARCH-003", name="arch003-rules-moved-with-the-sql", kind="wrong", expect="not_fixed",
         edits=NOTICE_SQL_MOVED + [("repo/ContentRepository.java", "                buildingId, authorId, title, body, pinned, now);\n    }",
                                    "                buildingId, authorId, title, body, pinned, now);\n    }\n\n"
                                    "    static boolean fits(String title, String body) {\n        return title.length() <= 200 && body.length() <= 10000;\n    }")],
         note="the repository now holds the rule as well as the SQL"),

    # ---- ARCH-010: the unused parser
    dict(target="ARCH-010", name="arch010-deleted", kind="alternative", expect="fixed", delete=["billing/CnabParser.java"]),

    # ---- ARCH-009: the notice limits named once
    dict(target="ARCH-009", name="arch009-named-limits", kind="alternative", expect="fixed",
         edits=[("notices/NoticeService.java", PAGE, LIMITS),
                ("notices/NoticeService.java", TITLE, 'if (title == null || title.length() > MAX_TITLE) throw AppException.invalid("The title is required and has at most " + MAX_TITLE + " characters.");'),
                ("notices/NoticeService.java", BODY, 'if (body == null || body.length() > MAX_BODY) throw AppException.invalid("The text is required and has at most " + MAX_BODY + " characters.");'),
                ("notices/NoticeService.java", WORDS, 'if (words.size() > MAX_WORDS) throw AppException.invalid("Search with at most " + MAX_WORDS + " words.");')]),
    dict(target="ARCH-009", name="arch009-messages-still-literal", kind="partial", expect="half",
         edits=[("notices/NoticeService.java", PAGE, LIMITS),
                ("notices/NoticeService.java", TITLE, TITLE.replace("length() > 200", "length() > MAX_TITLE")),
                ("notices/NoticeService.java", BODY, BODY.replace("length() > 10000", "length() > MAX_BODY")),
                ("notices/NoticeService.java", WORDS, WORDS.replace("size() > 5", "size() > MAX_WORDS"))],
         note="the checks use the names, the messages still repeat the numbers"),
]
