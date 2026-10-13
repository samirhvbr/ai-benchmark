package com.condodesk;

import javax.sql.DataSource;
import java.time.Instant;
import java.time.LocalDate;
import java.util.LinkedHashMap;
import java.util.Map;
import java.util.concurrent.Callable;

/** Base of the verifiers that watch or interfere with the application's connections. Scenario steps fail as "X answered NNN: …". */
abstract class VBase extends TestBase {
    Instrument instr;

    @Override
    DataSource forTheApp(DataSource plain) {
        instr = new Instrument(plain);
        return instr.dataSource();
    }

    /** Fails the scenario, in the form VerifiesExtension reads as "the system refused a step". */
    static IllegalStateException refused(String step, R r) {
        return new IllegalStateException(step + " answered " + r.status() + ": " + abbreviate(r.body()));
    }

    static String abbreviate(String s) {
        return s == null ? "" : s.length() > 300 ? s.substring(0, 300) + "…" : s;
    }

    /** GET as the browser, requiring 200. */
    R ok(Browser b, String path) throws Exception {
        R r = b.get(path);
        if (r.status() != 200) throw refused("GET " + path, r);
        return r;
    }

    /** The database commands the application sent to answer one browser GET. */
    long commandsFor(Browser b, String path) throws Exception {
        instr.reset();
        ok(b, path);
        return instr.commands.get();
    }

    /** The rows the application read to answer one browser GET. */
    long rowsFor(Browser b, String path) throws Exception {
        instr.reset();
        ok(b, path);
        return instr.rows.get();
    }

    /** A booking request of the mobile API, as a map. */
    static Map<String, Object> booking(long area, long unit, String date, String start, String end, int guests) {
        Map<String, Object> body = new LinkedHashMap<>();
        body.put("area_id", area);
        body.put("unit_id", unit);
        body.put("date", date);
        body.put("start", start);
        body.put("end", end);
        body.put("guests", guests);
        return body;
    }

    /** A booking inserted directly, for a scenario that needs one in place. */
    long bookingInDb(long area, long unit, long by, Instant start, Instant end, String status) throws Exception {
        return insert("insert into booking (area_id, unit_id, user_id, starts_at, ends_at, guests, status, created_at) values (?, ?, ?, ?, ?, 1, ?, ?) returning id",
                area, unit, by, start, end, status, clock.instant());
    }

    /** The bank's reference of an invoice inserted by {@link #invoice}. */
    String ourNumberOf(long invoiceId) throws Exception {
        return (String) value("select our_number from invoice where id = ?", invoiceId);
    }

    /**
     * n units in a building, labelled "{prefix} {i}", each with an owner of its own, and one open invoice of 100.00 per unit for
     * the month, due on the date. Returns the billing run's id.
     */
    long lateUnits(long building, int n, String prefix, String month, LocalDate due) throws Exception {
        update("with u as (insert into unit (building_id, label, ideal_fraction) select ?, ? || ' ' || g, 0.0001 from generate_series(1, ?) g returning id, label), "
                + "p as (insert into app_user (email, name, role, building_id, password_hash, created_at) "
                + "select lower(replace(label, ' ', '-')) || '@late.test', 'Owner of ' || label, 'RESIDENT', ?, 'sha256:x', ? from u returning id, email) "
                + "insert into residency (user_id, unit_id, kind) select p.id, u.id, 'OWNER' from u join p on p.email = lower(replace(u.label, ' ', '-')) || '@late.test'",
                building, prefix, n, building, Instant.parse("2025-01-01T00:00:00Z"));
        long run = insert("insert into billing_run (building_id, month, budget, created_by, created_at) values (?, ?, 1000, ?, ?) returning id",
                building, month, admin, clock.instant());
        update("insert into invoice (run_id, unit_id, month, due_date, amount, our_number, status) "
                + "select ?, id, ?, ?, 100.00, 'L-' || ? || '-' || id, 'OPEN' from unit where building_id = ? and label like ?",
                run, month, due, month, building, prefix + " %");
        return run;
    }

    /** Runs the call on a thread of its own and tells the rendezvous when it ends, so that a party refused early never leaves the other waiting. */
    static <T> Callable<T> party(Rendezvous rv, Callable<T> call) {
        return () -> {
            try {
                return call.call();
            } finally {
                rv.left();
            }
        };
    }
}
