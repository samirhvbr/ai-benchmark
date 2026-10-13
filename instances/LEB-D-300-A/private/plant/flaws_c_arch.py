"""LEB-D-300-A — the ARCH rows of ../CATALOG.md §4 (Template R), except ARCH-001, which is planted last (flaws_z_god.py)."""

from paths import SRC

COLLECTION_PAGE = (
    "    private Response collection(Request req) {\n"
    "        long building = req.building();\n"
    "        Long y = req.queryLong(\"year\");\n"
    "        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid(\"year must be between 2000 and 2100.\");\n"
    "        YearCollection collection = reports.collection(req.principal(), building, y == null ? null : y.intValue());\n"
    "        StringBuilder b = new StringBuilder(\"<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>\");\n"
    "        for (MonthCollection m : collection.months()) {\n"
    "            b.append(\"<tr><td>\").append(m.month()).append(\"</td><td class=\\\"num\\\">\").append(Html.money(m.billed())).append(\"</td><td class=\\\"num\\\">\")\n"
    "                    .append(Html.money(m.settled())).append(\"</td><td class=\\\"num\\\">\").append(Html.money(m.received())).append(\"</td><td class=\\\"num\\\">\")\n"
    "                    .append(m.rate() == null ? \"—\" : m.rate().toPlainString() + \"%\").append(\"</td></tr>\");\n"
    "        }\n"
    "        return Response.html(Html.page(req, \"Collection \" + collection.year(), b.append(\"</table>\").toString()));\n"
    "    }\n"
)

COLLECTION_PAGE_WITH_SQL = (
    "    private Response collection(Request req) {\n"
    "        long building = req.building();\n"
    "        req.principal().requireManager(building);\n"
    "        Long y = req.queryLong(\"year\");\n"
    "        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid(\"year must be between 2000 and 2100.\");\n"
    "        StringBuilder b = new StringBuilder(\"<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>\");\n"
    "        int year = db.read(c -> {\n"
    "            String zone = Db.one(c, \"select time_zone from building where id = ?\", rs -> rs.getString(\"time_zone\"), building)\n"
    "                    .orElseThrow(AppException::notFound);\n"
    "            int shown = y != null ? y.intValue() : LocalDate.ofInstant(clock.instant(), ZoneId.of(zone)).getYear();\n"
    "            Db.list(c, \"select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, \"\n"
    "                            + \"coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id \"\n"
    "                            + \"left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id \"\n"
    "                            + \"where u.building_id = ? and i.month like ? group by i.month order by i.month\",\n"
    "                    rs -> {\n"
    "                        BigDecimal billed = rs.getBigDecimal(\"billed\");\n"
    "                        BigDecimal settled = rs.getBigDecimal(\"settled\");\n"
    "                        String share = billed.signum() == 0 ? \"—\"\n"
    "                                : settled.multiply(BigDecimal.valueOf(100)).divide(billed, 1, RoundingMode.HALF_UP).toPlainString() + \"%\";\n"
    "                        b.append(\"<tr><td>\").append(rs.getString(\"month\")).append(\"</td><td class=\\\"num\\\">\").append(Html.money(billed))\n"
    "                                .append(\"</td><td class=\\\"num\\\">\").append(Html.money(settled)).append(\"</td><td class=\\\"num\\\">\")\n"
    "                                .append(Html.money(rs.getBigDecimal(\"received\"))).append(\"</td><td class=\\\"num\\\">\").append(share).append(\"</td></tr>\");\n"
    "                        return share;\n"
    "                    },\n"
    "                    building, String.format(\"%04d-%%\", shown));\n"
    "            return shown;\n"
    "        });\n"
    "        return Response.html(Html.page(req, \"Collection \" + year, b.append(\"</table>\").toString()));\n"
    "    }\n"
)

CNAB_PARSER = '''package com.condodesk.billing;

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
        for (String line : new String(file, StandardCharsets.ISO_8859_1).split("\\r?\\n")) {
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
'''

FLAWS = [
    # The collection page runs the month totals SQL itself, computes the settled share and writes the HTML.
    ("ARCH-002", [
        (SRC + "web/ReportPages.java",
         "import com.condodesk.domain.AppException;\n"
         "import com.condodesk.reports.ReportService;\n"
         "import com.condodesk.reports.ReportService.YearCollection;\n",
         "import com.condodesk.db.Db;\n"
         "import com.condodesk.domain.AppException;\n"
         "import com.condodesk.reports.ReportService;\n"),
        (SRC + "web/ReportPages.java",
         "import com.condodesk.reports.ReportService.Delinquent;\n"
         "import com.condodesk.reports.ReportService.MonthCollection;\n"
         "import com.condodesk.web.Server.Gate;\n\n"
         "import java.nio.charset.StandardCharsets;\n",
         "import com.condodesk.reports.ReportService.Delinquent;\n"
         "import com.condodesk.web.Server.Gate;\n\n"
         "import java.math.BigDecimal;\n"
         "import java.math.RoundingMode;\n"
         "import java.nio.charset.StandardCharsets;\n"
         "import java.time.Clock;\n"
         "import java.time.LocalDate;\n"
         "import java.time.ZoneId;\n"),
        (SRC + "web/ReportPages.java",
         "    private final ReportService reports;\n\n"
         "    ReportPages(ReportService reports) {\n"
         "        this.reports = reports;\n"
         "    }\n",
         "    private final ReportService reports;\n"
         "    private final Db db;\n"
         "    private final Clock clock;\n\n"
         "    ReportPages(ReportService reports, Db db, Clock clock) {\n"
         "        this.reports = reports;\n"
         "        this.db = db;\n"
         "        this.clock = clock;\n"
         "    }\n"),
        (SRC + "web/ReportPages.java", COLLECTION_PAGE, COLLECTION_PAGE_WITH_SQL),
        (SRC + "web/Routes.java",
         "import com.condodesk.booking.BookingService;\n",
         "import com.condodesk.booking.BookingService;\nimport com.condodesk.db.Db;\n"),
        (SRC + "web/Routes.java",
         "import java.io.InputStream;\n",
         "import java.io.InputStream;\nimport java.time.Clock;\n"),
        (SRC + "web/Routes.java",
         "    public static void register(Server server, Config config, Sessions sessions,",
         "    public static void register(Server server, Config config, Db db, Clock clock, Sessions sessions,"),
        (SRC + "web/Routes.java",
         "        new ReportPages(reports).register(server);",
         "        new ReportPages(reports, db, clock).register(server);"),
        (SRC + "App.java",
         "        Routes.register(server, config, sessions,",
         "        Routes.register(server, config, db, clock, sessions,"),
        (SRC + "reports/ReportService.java",
         "/** The delinquency report, the collection of a year and the dashboards. */",
         "/** The delinquency report and the dashboards. */"),
        (SRC + "reports/ReportService.java",
         "    public record MonthCollection(YearMonth month, BigDecimal billed, BigDecimal settled, BigDecimal received, BigDecimal rate) {}\n\n"
         "    /** The collection of one year: the year shown, and its billed months. */\n"
         "    public record YearCollection(int year, List<MonthCollection> months) {}\n\n",
         ""),
        (SRC + "reports/ReportService.java",
         "    /**\n"
         "     * Per billed month of the year: what was billed, the invoices settled, the money received, and the settled share. Without a\n"
         "     * year, the current year on the building's calendar.\n"
         "     */\n"
         "    public YearCollection collection(Principal p, long buildingId, Integer year) {\n"
         "        p.requireManager(buildingId);\n"
         "        return db.read(c -> {\n"
         "            int y = year != null ? year : buildings.byId(c, buildingId).orElseThrow(AppException::notFound).dateAt(clock.instant()).getYear();\n"
         "            return new YearCollection(y, reports.monthTotals(c, buildingId, y).stream()\n"
         "                    .map(t -> new MonthCollection(t.month(), t.billed(), t.settled(), t.received(), Charges.rate(t.settled(), t.billed())))\n"
         "                    .toList());\n"
         "        });\n"
         "    }\n\n",
         ""),
        (SRC + "repo/ReportRepository.java",
         "import java.math.BigDecimal;\nimport java.sql.Connection;\nimport java.sql.SQLException;\nimport java.time.Instant;\nimport java.time.YearMonth;\n",
         "import java.sql.Connection;\nimport java.sql.SQLException;\nimport java.time.Instant;\n"),
        (SRC + "repo/ReportRepository.java",
         "    public record MonthTotals(YearMonth month, BigDecimal billed, BigDecimal settled, BigDecimal received) {}\n\n",
         ""),
        (SRC + "repo/ReportRepository.java",
         "    /** Per billed month of the year: the invoices' total, the total of those settled, and the money received for them. */\n"
         "    public List<MonthTotals> monthTotals(Connection c, long buildingId, int year) throws SQLException {\n"
         "        return Db.list(c, \"select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, \"\n"
         "                        + \"coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id \"\n"
         "                        + \"left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id \"\n"
         "                        + \"where u.building_id = ? and i.month like ? group by i.month order by i.month\",\n"
         "                rs -> new MonthTotals(YearMonth.parse(rs.getString(\"month\")), rs.getBigDecimal(\"billed\"), rs.getBigDecimal(\"settled\"),\n"
         "                        rs.getBigDecimal(\"received\")),\n"
         "                buildingId, String.format(\"%04d-%%\", year));\n"
         "    }\n\n",
         ""),
        (SRC + "billing/Charges.java",
         "\n    /** The share of the billed amount that was paid, in percent with one decimal; null when nothing was billed. */\n"
         "    public static BigDecimal rate(BigDecimal paid, BigDecimal billed) {\n"
         "        if (billed.signum() == 0) return null;\n"
         "        return paid.multiply(HUNDRED).divide(billed, 1, RoundingMode.HALF_UP);\n"
         "    }\n",
         ""),
    ]),
    # The notice service writes and reads the notice table with SQL of its own.
    ("ARCH-003", [
        (SRC + "notices/NoticeService.java",
         "            long id = content.insertNotice(c, buildingId, p.userId(), title, body, pinned, clock.instant());",
         "            long id = Db.insert(c, \"insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)\",\n"
         "                    buildingId, p.userId(), title, body, pinned, clock.instant());"),
        (SRC + "notices/NoticeService.java",
         "        return db.read(c -> content.notice(c, id)).filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);",
         "        return db.read(c -> Db.one(c, \"select n.id, n.building_id, u.name as author_name, n.title, n.body, n.pinned, n.published_at, \"\n"
         "                        + \"(n.published_at at time zone (select time_zone from building where building.id = n.building_id))::date as published_on \"\n"
         "                        + \"from notice n join app_user u on u.id = n.author_id where n.id = ?\",\n"
         "                rs -> new Notice(rs.getLong(\"id\"), rs.getLong(\"building_id\"), rs.getString(\"author_name\"), rs.getString(\"title\"),\n"
         "                        rs.getString(\"body\"), rs.getBoolean(\"pinned\"), Db.instant(rs, \"published_at\"), Db.date(rs, \"published_on\")), id))\n"
         "                .filter(n -> p.belongsTo(n.buildingId())).orElseThrow(AppException::notFound);"),
        (SRC + "repo/ContentRepository.java",
         "    public long insertNotice(Connection c, long buildingId, long authorId, String title, String body, boolean pinned, Instant now) throws SQLException {\n"
         "        return Db.insert(c, \"insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, ?, ?, ?)\",\n"
         "                buildingId, authorId, title, body, pinned, now);\n"
         "    }\n\n"
         "    public Optional<Notice> notice(Connection c, long id) throws SQLException {\n"
         "        return Db.one(c, NOTICE + \" where n.id = ?\", ContentRepository::notice, id);\n"
         "    }\n\n",
         ""),
    ]),
    # The admin's building totals record moves into the report service, which the repository then depends on: repo <-> reports.
    ("ARCH-008", [
        (SRC + "repo/ReportRepository.java",
         "import com.condodesk.db.Db;\n",
         "import com.condodesk.db.Db;\nimport com.condodesk.reports.ReportService.BuildingTotals;\n"),
        (SRC + "repo/ReportRepository.java",
         "    public record BuildingTotals(long id, String name, int units, int openInvoices) {}\n\n",
         ""),
        (SRC + "reports/ReportService.java",
         "import com.condodesk.repo.ReportRepository;\nimport com.condodesk.repo.ReportRepository.BuildingTotals;\n",
         "import com.condodesk.repo.ReportRepository;\n"),
        (SRC + "reports/ReportService.java",
         "    /** The delinquency CSV and the building's date it was taken on. */\n",
         "    /** A building as the admin's dashboard lists it. */\n"
         "    public record BuildingTotals(long id, String name, int units, int openInvoices) {}\n\n"
         "    /** The delinquency CSV and the building's date it was taken on. */\n"),
        (SRC + "web/AccountPages.java",
         "import com.condodesk.reports.ReportService.Dashboard;\nimport com.condodesk.repo.ReportRepository.BuildingTotals;\n",
         "import com.condodesk.reports.ReportService.BuildingTotals;\nimport com.condodesk.reports.ReportService.Dashboard;\n"),
    ]),
    # The data source is kept in a static field of Db, set by every App.create.
    ("ARCH-013", [
        (SRC + "db/Db.java",
         "    private final DataSource dataSource;\n\n"
         "    public Db(DataSource dataSource) {\n"
         "        this.dataSource = dataSource;\n"
         "    }\n",
         "    /** The application's data source, set by {@code App.create}. */\n"
         "    private static DataSource dataSource;\n\n"
         "    public static void configure(DataSource ds) {\n"
         "        dataSource = ds;\n"
         "    }\n"),
        (SRC + "App.java",
         "        Db db = new Db(dataSource);\n",
         "        Db.configure(dataSource);\n"
         "        Db db = new Db();\n"),
    ]),
    # The amount-due rule written a second time, with a day count of its own.
    ("ARCH-004", [
        (SRC + "reports/ReportService.java",
         "import java.math.BigDecimal;\n",
         "import java.math.BigDecimal;\nimport java.math.RoundingMode;\n"),
        (SRC + "reports/ReportService.java",
         "import java.time.YearMonth;\n",
         "import java.time.YearMonth;\nimport java.time.temporal.ChronoUnit;\n"),
        (SRC + "reports/ReportService.java",
         "        BigDecimal due = open.stream().map(i -> Charges.amountDue(i.amount(), i.dueDate(), b, today)).reduce(BigDecimal.ZERO, BigDecimal::add);\n",
         "        BigDecimal due = BigDecimal.ZERO;\n"
         "        for (Invoice i : open) {\n"
         "            long late = i.dueDate().isBefore(today) ? ChronoUnit.DAYS.between(i.dueDate(), today) : 0;\n"
         "            BigDecimal owed = i.amount();\n"
         "            if (late > 0) {\n"
         "                owed = owed.add(i.amount().multiply(b.lateFeePct()).divide(BigDecimal.valueOf(100), 12, RoundingMode.HALF_EVEN))\n"
         "                        .add(i.amount().multiply(b.monthlyInterestPct()).multiply(BigDecimal.valueOf(late))\n"
         "                                .divide(BigDecimal.valueOf(3000), 12, RoundingMode.HALF_EVEN));\n"
         "            }\n"
         "            due = due.add(owed.setScale(2, RoundingMode.HALF_UP));\n"
         "        }\n"),
    ]),
    # The notice limits are bare literals, each written in its check and in its message.
    ("ARCH-009", [
        (SRC + "notices/NoticeService.java",
         "    static final int MAX_TITLE = 200;\n    static final int MAX_BODY = 10_000;\n    static final int MAX_WORDS = 5;\n",
         ""),
        (SRC + "notices/NoticeService.java",
         "        if (title == null || title.length() > MAX_TITLE) throw AppException.invalid(\"The title is required and has at most \" + MAX_TITLE + \" characters.\");\n"
         "        if (body == null || body.length() > MAX_BODY) throw AppException.invalid(\"The text is required and has at most \" + MAX_BODY + \" characters.\");\n",
         "        if (title == null || title.length() > 200) throw AppException.invalid(\"The title is required and has at most 200 characters.\");\n"
         "        if (body == null || body.length() > 10000) throw AppException.invalid(\"The text is required and has at most 10000 characters.\");\n"),
        (SRC + "notices/NoticeService.java",
         "        if (words.size() > MAX_WORDS) throw AppException.invalid(\"Search with at most \" + MAX_WORDS + \" words.\");",
         "        if (words.size() > 5) throw AppException.invalid(\"Search with at most 5 words.\");"),
    ]),
    # A parser of the bank's old CNAB return file, which the OFX import replaced: nothing calls it.
    ("ARCH-010", [
        (SRC + "billing/CnabParser.java", None, CNAB_PARSER),
    ]),
]
