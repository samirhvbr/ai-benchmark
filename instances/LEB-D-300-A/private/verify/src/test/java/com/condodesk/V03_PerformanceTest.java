package com.condodesk;

import org.junit.jupiter.api.Test;

import java.lang.management.ManagementFactory;
import java.lang.management.ThreadMXBean;
import java.time.Instant;
import java.time.LocalDate;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Performance: what is measured is an amount of work (commands, rows read, growth), never an absolute time. */
class V03_PerformanceTest extends VBase {

    @Test
    @Verifies({"PERF-001"})
    void perf001_theDelinquencyReportDoesNotRunAQueryPerLateInvoice() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        lateUnits(buildingA, 3, "Few", "2026-01", LocalDate.parse("2026-02-10"));
        ok(m, "/reports/delinquency");                                     // warms whatever the application caches
        long few = commandsFor(m, "/reports/delinquency");
        lateUnits(buildingA, 27, "Many", "2025-12", LocalDate.parse("2026-01-10"));
        String page = ok(m, "/reports/delinquency").body();
        long many = commandsFor(m, "/reports/delinquency");
        boolean listsAll = page.contains("Owner of Few 3") && page.contains("Owner of Many 27");
        Verdict.fixed("PERF-001", many - few <= 1 && listsAll, "commands per request: " + few + " with 3 late invoices and " + many
                + " with 30, each of a unit with an owner of its own (they should be the same); the report lists every owner: " + listsAll);
    }

    @Test
    @Verifies({"PERF-003"})
    void perf003_onePageOfInvoicesDoesNotReadEveryMatchingInvoice() throws Exception {
        Browser a = loggedIn(ADMIN);
        String list = "/invoices?building=" + buildingA;
        lateUnits(buildingA, 40, "Page", "2026-01", LocalDate.parse("2026-02-10"));
        ok(a, list);
        long small = rowsFor(a, list);
        lateUnits(buildingA, 360, "More", "2025-12", LocalDate.parse("2026-01-10"));
        String page = ok(a, list).body();
        long large = rowsFor(a, list);
        long second = rowsFor(a, list + "&page=2");
        Set<String> first = invoiceIds(page), next = invoiceIds(ok(a, list + "&page=2").body());
        boolean counted = page.contains("400 invoices.");
        boolean paged = first.size() == 20 && next.size() == 20 && Collections.disjoint(first, next);
        Verdict.fixed("PERF-003", large - small <= 2 && second - small <= 2 && counted && paged, "rows read for one page of 20: " + small + " with 40 matching invoices, "
                + large + " with 400, and " + second + " for the second page (they should be the same); the page still says \"400 invoices.\": " + counted
                + "; the second page has 20 other invoices: " + paged);
    }

    /** A link to one invoice, found by where it points: the quotes, the other attributes and the cell around it are the page's own. */
    static final Pattern INVOICE_LINK = Pattern.compile("href\\s*=\\s*[\"']/invoices/(\\d+)(?=[\"'?#])", Pattern.CASE_INSENSITIVE);

    private static Set<String> invoiceIds(String page) {
        Set<String> out = new HashSet<>();
        Matcher m = INVOICE_LINK.matcher(page);
        while (m.find()) out.add(m.group(1));
        return out;
    }

    /** The text a reader sees: the tags of the page and its scripts and styles left out, the white space collapsed. */
    static String textOf(String page) {
        return page.replaceAll("(?is)<(script|style)\\b.*?</\\1>", " ").replaceAll("(?s)<[^>]*>", " ").replace("&nbsp;", " ").replaceAll("\\s+", " ");
    }

    /** A building on the admin's dashboard is its name, its units and its open invoices, in that order; the markup around them is the page's own. */
    private static final Pattern BUILDING_ROW = Pattern.compile("Maple Court (\\d+) (\\d+)\\b");

    private static int openOnTheAdminsDashboard(String page) {
        Matcher m = BUILDING_ROW.matcher(textOf(page));
        return m.find() ? Integer.parseInt(m.group(2)) : -1;
    }

    @Test
    @Verifies({"PERF-010"})
    void perf010_theAdminsDashboardShowsTheInvoicesOfToday() throws Exception {
        Browser a = loggedIn(ADMIN);
        int before = openOnTheAdminsDashboard(ok(a, "/").body());
        ok(a, "/admin/billing");
        R run = a.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-04", "budget", "900.00");
        if (run.status() != 303) throw refused("POST /admin/billing", run);
        int afterBilling = openOnTheAdminsDashboard(ok(a, "/").body());
        // what an invoice stops being open for is a payment: the manager registers one in full, and the dashboard must follow it too
        long invoice = ((Number) value("select min(i.id) from invoice i join unit u on u.id = i.unit_id where u.building_id = ? and i.month = '2026-04'", buildingA)).longValue();
        String amount = ((java.math.BigDecimal) value("select amount from invoice where id = ?", invoice)).toPlainString();
        Browser m = loggedIn(MANAGER_A);
        ok(m, "/invoices/" + invoice);
        R paid = m.post("/invoices/" + invoice + "/payments", "amount", amount, "paid_on", "2026-03-04");
        if (paid.status() != 303) throw refused("POST /invoices/" + invoice + "/payments", paid);
        int afterPayment = openOnTheAdminsDashboard(ok(a, "/").body());
        Verdict.fixed("PERF-010", before == 0 && afterBilling == 3 && afterPayment == 2, "open invoices of Maple Court on the admin's dashboard: " + before
                + " before a billing run of its 3 units, " + afterBilling + " after it and " + afterPayment + " after the manager registered the full payment of one of them (expected 0, 3 and 2)");
    }

    @Test
    @Verifies({"PERF-006"})
    void perf006_paymentsHaveAnIndexLeadingOnTheirInvoice() throws Exception {
        long leading = count("select count(*) from pg_index i join pg_class t on t.oid = i.indrelid join pg_namespace n on n.oid = t.relnamespace "
                + "join pg_attribute a on a.attrelid = t.oid and a.attnum = i.indkey[0] "
                + "where n.nspname = ? and t.relname = 'payment' and a.attname = 'invoice_id' and not i.indisprimary", schema);
        Verdict.fixed("PERF-006", leading > 0, leading > 0 ? "an index leads on payment(invoice_id)" : "no index leads on payment(invoice_id) after schema.sql and the migrations");
    }

    @Test
    @Verifies({"PERF-009"})
    void perf009_theManagersOverdueCountDoesNotReadEveryOverdueInvoice() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        lateUnits(buildingA, 3, "Few", "2026-01", LocalDate.parse("2026-02-10"));
        ok(m, "/");
        long few = rowsFor(m, "/");
        lateUnits(buildingA, 297, "Many", "2025-12", LocalDate.parse("2026-01-10"));
        String page = ok(m, "/").body();
        long many = rowsFor(m, "/");
        boolean counted = page.contains("Overdue invoices: <strong>300</strong>");
        Verdict.fixed("PERF-009", many - few <= 2 && counted, "rows read for the manager's dashboard: " + few + " with 3 overdue invoices and " + many
                + " with 300 (they should be the same); it still says 300 overdue: " + counted);
    }

    /** At most this ratio of processor time between 16,000 and 4,000 accounts: linear growth is 4, the quadratic list is 16. */
    static final double MAX_RATIO = 8.0;

    @Test
    @Verifies({"PERF-012"})
    void perf012_theUserListGrowsInAStraightLine() throws Exception {
        Browser a = loggedIn(ADMIN);
        accounts(1, 3_993);                                                // with the 7 seeded users, 4,000 accounts
        ok(a, "/admin/users");
        ok(a, "/admin/users");                                             // the JIT has seen the code twice
        long small = cpuOf(a, "/admin/users");
        accounts(3_994, 12_000);                                           // 16,000
        ok(a, "/admin/users");
        long large = cpuOf(a, "/admin/users");
        String page = ok(a, "/admin/users").body();
        boolean listsAll = USER_ROW.matcher(textOf(page)).find();
        double ratio = (double) large / Math.max(1, small);
        Verdict.fixed("PERF-012", ratio < MAX_RATIO && listsAll, String.format("processor time of the application for the user list: %d ms with 4,000 accounts and %d ms with 16,000, "
                + "a ratio of %.1f (linear is 4; the limit is %.0f); the list still shows every account with its unit: %s", small, large, ratio, MAX_RATIO, listsAll));
    }

    /** An account on the list is its email, its role and its unit, with whatever else the row shows between them (never the next account's). */
    private static final Pattern USER_ROW = Pattern.compile("listed-15993@many\\.test(?: (?!listed-)\\S+){0,5}? Apt 101\\b");

    /** Accounts number from..to, each a tenant of Apt 101 of Maple Court, so that every account has one residency. */
    private void accounts(int from, int n) throws Exception {
        update("with p as (insert into app_user (email, name, role, building_id, password_hash, created_at) "
                        + "select 'listed-' || g || '@many.test', 'Listed ' || g, 'RESIDENT', ?, 'sha256:x', ? from generate_series(?, ?) g returning id) "
                        + "insert into residency (user_id, unit_id, kind) select id, ?, 'TENANT' from p",
                buildingA, Instant.parse("2025-01-01T00:00:00Z"), from, from + n - 1, unitA101);
    }

    /** The least processor time, over three requests, that every thread of this JVM spent while the page was answered. */
    private long cpuOf(Browser b, String path) throws Exception {
        ThreadMXBean mx = ManagementFactory.getThreadMXBean();
        long best = Long.MAX_VALUE;
        for (int i = 0; i < 3; i++) {
            Map<Long, Long> start = new HashMap<>();
            for (long id : mx.getAllThreadIds()) start.put(id, mx.getThreadCpuTime(id));
            ok(b, path);
            long spent = 0;
            for (long id : mx.getAllThreadIds()) {
                long now = mx.getThreadCpuTime(id);
                if (now < 0) continue;
                Long before = start.get(id);
                spent += now - (before == null || before < 0 ? 0 : before);
            }
            best = Math.min(best, spent / 1_000_000L);
        }
        return best;
    }
}
