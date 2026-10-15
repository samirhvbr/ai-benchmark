package com.condodesk;

import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Decoys: code that looks like a flaw and is not. Each verifier proves the property that code guarantees; whoever "fixes" the decoy
 * and breaks the property loses points (intervention), and whoever reports it as a flaw loses points (diagnosis). The legacy and
 * the reference both pass every one.
 */
class V06_DecoyTest extends VBase {

    private List<String> amountsOfRun(String month, long building) throws Exception {
        List<String> out = new ArrayList<>();
        for (var r : rows("select i.amount from invoice i join unit u on u.id = i.unit_id where i.month = ? and u.building_id = ? order by u.label", month, building)) {
            out.add(((java.math.BigDecimal) r.get("amount")).toPlainString());
        }
        return out;
    }

    @Test
    @Verifies({"BUG-013.b"})
    void bug013b_theInvoicesOfARunAddUpToItsBudget() throws Exception {
        long thirds = insert("insert into building (name, address, due_day) values ('Thirds Hall', '3 Third Street', 10) returning id");
        for (String[] u : new String[][] {{"Unit 1", "0.333333"}, {"Unit 2", "0.333333"}, {"Unit 3", "0.333334"}}) {
            insert("insert into unit (building_id, label, ideal_fraction) values (?, ?, ?::numeric) returning id", thirds, u[0], u[1]);
        }
        Browser a = loggedIn(ADMIN);
        ok(a, "/admin/billing");
        R r1 = a.post("/admin/billing", "building", String.valueOf(thirds), "month", "2026-04", "budget", "100.00");
        R r2 = a.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-04", "budget", "1000.01");
        if (r1.status() != 303 || r2.status() != 303) throw refused("POST /admin/billing", r1.status() != 303 ? r1 : r2);
        List<String> t = amountsOfRun("2026-04", thirds), m = amountsOfRun("2026-04", buildingA);
        boolean holds = t.equals(List.of("33.33", "33.33", "33.34")) && m.equals(List.of("300.00", "300.00", "400.01"));
        Verdict.decoy("BUG-013.b", holds, "100.00 over fractions 0.333333, 0.333333, 0.333334: " + t + " (expected 33.33, 33.33, 33.34); 1000.01 over 0.30, 0.30, 0.40: "
                + m + " (expected 300.00, 300.00, 400.01)");
    }

    /** How many different things the page lists: an invoice by the invoice its link points to, a notice by its id (the public tests pin the article's id and class). */
    private static int distinct(Pattern p, String page) {
        Set<String> seen = new HashSet<>();
        Matcher m = p.matcher(page);
        while (m.find()) seen.add(m.group(1));
        return seen.size();
    }

    private static final Pattern INVOICE_ROW = V03_PerformanceTest.INVOICE_LINK;
    private static final Pattern NOTICE = Pattern.compile("<article id=\"notice-(\\d+)\"");

    @Test
    @Verifies({"ARCH-014"})
    void arch014_pagesKeepTheirContractedSizes() throws Exception {
        lateUnits(buildingA, 25, "Paged", "2026-01", LocalDate.parse("2026-02-10"));
        for (int i = 1; i <= 12; i++) {
            insert("insert into notice (building_id, author_id, title, body, pinned, published_at) values (?, ?, ?, 'text', false, ?) returning id",
                    buildingA, managerA, "Notice " + i, Instant.parse("2026-03-01T12:00:00Z").plusSeconds(i));
        }
        Browser a = loggedIn(ADMIN);
        int invoices1 = distinct(INVOICE_ROW, ok(a, "/invoices?building=" + buildingA).body());
        int invoices2 = distinct(INVOICE_ROW, ok(a, "/invoices?building=" + buildingA + "&page=2").body());
        Browser m = loggedIn(MANAGER_A);
        int notices1 = distinct(NOTICE, ok(m, "/notices").body());
        int notices2 = distinct(NOTICE, ok(m, "/notices?page=2").body());
        boolean holds = invoices1 == 20 && invoices2 == 5 && notices1 == 10 && notices2 == 2;
        Verdict.decoy("ARCH-014", holds, "25 invoices: " + invoices1 + " then " + invoices2 + " (expected 20 and 5); 12 notices: " + notices1 + " then " + notices2 + " (expected 10 and 2)");
    }

    @Test
    @Verifies({"BUG-010.b"})
    void bug010b_aMalformedStatementIsStillRefused() throws Exception {
        long id = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String whole = new String(ofx(new String[] {"F1", "20260303", "300.00", "PIX " + ourNumberOf(id)}), java.nio.charset.StandardCharsets.UTF_8);
        Browser b = loggedIn(ADMIN);
        ok(b, "/admin/statements");
        List<Integer> statuses = new ArrayList<>();
        for (String bad : List.of(whole.substring(0, whole.indexOf("</STMTTRN>")), whole.replace("</MEMO>", "</MEM>"), "<OFX><unclosed>")) {
            statuses.add(b.upload("/admin/statements", "statement", "bad.ofx", bad.getBytes(java.nio.charset.StandardCharsets.UTF_8)).status());
        }
        long payments = count("select count(*) from payment");
        boolean holds = statuses.equals(List.of(400, 400, 400)) && payments == 0;
        Verdict.decoy("BUG-010.b", holds, "a truncated statement, one with a mismatched tag and an unclosed one: " + statuses + ", payments recorded " + payments
                + " (expected 400 for each and none)");
    }

    @Test
    @Verifies({"PERF-015.b"})
    void perf015b_twoRequestsForOneSlotLeaveOneBooking() throws Exception {
        String ana = token(OWNER_A101), carla = token(OWNER_A201);
        Rendezvous rv = new Rendezvous(instr, ds, 2, sql -> sql.strip().startsWith("insert into booking"), V02_ConcurrencyTest.CEILING_MS);
        instr.hook = rv::before;
        ExecutorService pool = Executors.newFixedThreadPool(2);
        R a, b;
        try {
            Future<R> fa = pool.submit(party(rv, () -> api("POST", "/api/bookings", ana, booking(barbecue, unitA101, "2026-03-07", "12:00", "15:00", 4))));
            Future<R> fb = pool.submit(party(rv, () -> api("POST", "/api/bookings", carla, booking(barbecue, unitA201, "2026-03-07", "12:00", "15:00", 4))));
            a = fa.get(2 * V02_ConcurrencyTest.CEILING_MS, TimeUnit.MILLISECONDS);
            b = fb.get(2 * V02_ConcurrencyTest.CEILING_MS, TimeUnit.MILLISECONDS);
        } finally {
            instr.hook = null;
            pool.shutdownNow();
        }
        if (rv.overran) {
            Verdict.inconclusive("PERF-015.b", "the rendezvous ran out of its ceiling");
            return;
        }
        long bookings = count("select count(*) from booking where area_id = ? and status <> 'CANCELLED'", barbecue);
        List<Integer> statuses = List.of(a.status(), b.status()).stream().sorted().toList();
        boolean holds = statuses.equals(List.of(201, 409)) && bookings == 1;
        Verdict.decoy("PERF-015.b", holds, "two units asked for the same slot, held together at the insert: answers " + statuses + ", bookings of the slot " + bookings
                + " (expected [201, 409] and 1)");
    }
}
