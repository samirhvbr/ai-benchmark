package com.condodesk;

import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

/** Requests at the same time: interleavings are forced by state (a rendezvous or a hold), never by sleeping. */
class V02_ConcurrencyTest extends VBase {
    static final long CEILING_MS = 60_000;

    @Test
    @Verifies({"BUG-011"})
    void bug011_twoRequestsOfOneUnitCannotBothPassItsLimit() throws Exception {
        // the unit already holds one booking that has not started, so one more is allowed, not two
        bookingInDb(partyRoom, unitA101, ownerA101, Instant.parse("2026-03-20T15:00:00Z"), Instant.parse("2026-03-20T17:00:00Z"), "CONFIRMED");
        String t = token(OWNER_A101);
        Rendezvous rv = new Rendezvous(instr, ds, 2, sql -> sql.strip().startsWith("insert into booking"), CEILING_MS);
        instr.hook = rv::before;
        ExecutorService pool = Executors.newFixedThreadPool(2);
        R a, b;
        try {
            Future<R> fa = pool.submit(party(rv, () -> api("POST", "/api/bookings", t, booking(partyRoom, unitA101, "2026-03-12", "10:00", "12:00", 5))));
            Future<R> fb = pool.submit(party(rv, () -> api("POST", "/api/bookings", t, booking(barbecue, unitA101, "2026-03-12", "10:00", "12:00", 5))));
            a = fa.get(2 * CEILING_MS, TimeUnit.MILLISECONDS);
            b = fb.get(2 * CEILING_MS, TimeUnit.MILLISECONDS);
        } finally {
            instr.hook = null;
            pool.shutdownNow();
        }
        if (rv.overran) {
            Verdict.inconclusive("BUG-011", "the rendezvous ran out of its ceiling");
            return;
        }
        long upcoming = count("select count(*) from booking where unit_id = ? and status <> 'CANCELLED' and starts_at > ?", unitA101, clock.instant());
        List<Integer> statuses = List.of(a.status(), b.status()).stream().sorted().toList();
        boolean ok = statuses.equals(List.of(201, 409)) && upcoming == 2;
        Verdict.fixed("BUG-011", ok, "a unit holding one upcoming booking sent two requests, for two areas, held together at the insert: answers " + statuses
                + ", upcoming bookings " + upcoming + " (expected [201, 409] and 2; " + rv.arrivals() + " of the 2 reached the insert)");
    }

    @Test
    @Verifies({"PERF-015"})
    void perf015_anEventHeldInsideItsTransactionDoesNotStopAnotherInvoicesEvent() throws Exception {
        long x = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        long y = invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        String ox = ourNumberOf(x), oy = ourNumberOf(y);
        Hold hold = new Hold(sql -> sql.strip().startsWith("insert into payment"), CEILING_MS);
        instr.hook = hold::before;
        ExecutorService pool = Executors.newFixedThreadPool(2);
        String state;
        Future<R> first = null, second = null;
        try {
            first = pool.submit(() -> webhook("ev-x", ox, "300.00", "2026-03-04"));
            if (!hold.awaitHeld()) {
                Verdict.inconclusive("PERF-015", "the first event never reached its payment insert");
                return;
            }
            second = pool.submit(() -> webhook("ev-y", oy, "400.00", "2026-03-04"));
            long limit = System.nanoTime() + CEILING_MS * 1_000_000L;
            state = null;
            while (state == null) {
                if (second.isDone()) state = "answered";
                else if (Rendezvous.someoneIsWaitingForALock(instr, ds)) state = "waits for a lock";
                else if (System.nanoTime() > limit) state = "neither, within the ceiling";
                else Thread.sleep(2);   // polling: it decides nothing, it only waits for the state to change
            }
        } finally {
            hold.release();
        }
        R r1, r2;
        try {
            r1 = first.get(CEILING_MS, TimeUnit.MILLISECONDS);
            r2 = second.get(CEILING_MS, TimeUnit.MILLISECONDS);
        } finally {
            instr.hook = null;
            pool.shutdownNow();
        }
        if (state.startsWith("neither")) {
            Verdict.inconclusive("PERF-015", "the second event neither answered nor waited for a lock within the ceiling");
            return;
        }
        boolean bothPaid = r1.status() == 200 && r2.status() == 200 && "PAID".equals(value("select status from invoice where id = ?", x))
                && "PAID".equals(value("select status from invoice where id = ?", y));
        boolean ok = state.equals("answered") && bothPaid;
        Verdict.fixed("PERF-015", ok, "while an event for one invoice was held inside its transaction, an event for another invoice " + state
                + " (expected: answered); afterwards both answered " + r1.status() + " and " + r2.status() + ", both invoices paid: " + bothPaid);
    }
}
