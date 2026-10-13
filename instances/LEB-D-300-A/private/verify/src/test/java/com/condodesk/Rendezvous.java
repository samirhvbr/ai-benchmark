package com.condodesk;

import javax.sql.DataSource;
import java.lang.management.ManagementFactory;
import java.lang.management.ThreadInfo;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.function.Predicate;

/**
 * A meeting point for the application's connections, to force an interleaving without sleeping. Each connection that runs a
 * command matching the trigger waits until (1) the other parties have arrived (or left without arriving), (2) some connection
 * that has not arrived is provably waiting for a lock, in the database or in the application's own code, or (3) the safety
 * ceiling runs out. The ceiling gives an inconclusive result, never an approval. The verdict is the same whether the wait lasts
 * 2 ms or 2 s: it ends on a state, not on a time.
 */
final class Rendezvous {
    private final Instrument instrument;
    private final DataSource monitor;
    private final int parties;
    private final Predicate<String> trigger;
    private final long ceilingMs;
    private final Object lock = new Object();
    private int arrived;
    private int left;
    volatile boolean overran;

    Rendezvous(Instrument instrument, DataSource monitor, int parties, Predicate<String> trigger, long ceilingMs) {
        this.instrument = instrument;
        this.monitor = monitor;
        this.parties = parties;
        this.trigger = trigger;
        this.ceilingMs = ceilingMs;
    }

    /** A party that ended without reaching the trigger (it was refused earlier) will not arrive: whoever waits is let go. */
    void left() {
        synchronized (lock) {
            left++;
            lock.notifyAll();
        }
    }

    int arrivals() {
        synchronized (lock) {
            return arrived;
        }
    }

    /** For use as Instrument.Hook. */
    void before(Instrument.Conn c, String sql, List<Object> args) {
        if (c.arrived || !trigger.test(sql.toLowerCase())) return;
        c.arrived = true;
        synchronized (lock) {
            arrived++;
            lock.notifyAll();
        }
        long limit = System.nanoTime() + ceilingMs * 1_000_000L;
        while (true) {
            synchronized (lock) {
                if (arrived + left >= parties) return;
            }
            if (someoneIsWaitingForALock(instrument, monitor)) return;
            if (System.nanoTime() > limit) {
                overran = true;
                return;
            }
            try {
                Thread.sleep(2);   // polling: it decides nothing, it only waits for the state to change
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return;
            }
        }
    }

    // ---- who is waiting for a lock

    private static final Map<String, Boolean> MAIN = new ConcurrentHashMap<>();

    /** Is the class part of the code under evaluation (target/classes), not of the tests or the verifiers (target/test-classes)? */
    static boolean isMainClass(String name) {
        if (!name.startsWith("com.condodesk.")) return false;
        return MAIN.computeIfAbsent(name.replaceAll("\\$.*", ""), n -> {
            try {
                var source = Class.forName(n, false, Rendezvous.class.getClassLoader()).getProtectionDomain().getCodeSource();
                return source != null && !source.getLocation().toString().contains("test-classes");
            } catch (ClassNotFoundException | LinkageError e) {
                return false;
            }
        });
    }

    /** Some thread is waiting for a monitor or a lock of java.util.concurrent from inside the code under evaluation, and is not held by a verifier. */
    static boolean someoneWaitsInsideTheApplication() {
        for (ThreadInfo t : ManagementFactory.getThreadMXBean().dumpAllThreads(false, false)) {
            Thread.State s = t.getThreadState();
            if (s != Thread.State.BLOCKED && s != Thread.State.WAITING && s != Thread.State.TIMED_WAITING) continue;
            StackTraceElement[] stack = t.getStackTrace();
            boolean held = false, inApp = false, onALock = s == Thread.State.BLOCKED;
            for (StackTraceElement f : stack) {
                String c = f.getClassName();
                if (c.startsWith(Rendezvous.class.getName()) || c.startsWith(Hold.class.getName())) held = true;
                if (isMainClass(c)) inApp = true;
                if (c.startsWith("java.util.concurrent.locks.")) onALock = true;
            }
            if (!held && inApp && onALock) return true;
        }
        return false;
    }

    /** Some open connection that has not arrived is waiting for a lock in the database, or some thread waits for one in the application. */
    static boolean someoneIsWaitingForALock(Instrument instrument, DataSource monitor) {
        if (someoneWaitsInsideTheApplication()) return true;
        List<Integer> pids = new ArrayList<>();
        synchronized (instrument.connections) {
            for (Instrument.Conn k : instrument.connections) if (k.open && !k.arrived && k.pid > 0) pids.add(k.pid);
        }
        if (pids.isEmpty()) return false;
        try (Connection c = monitor.getConnection(); PreparedStatement ps = c.prepareStatement(
                "select count(*) from pg_stat_activity where pid = any(?) and wait_event_type = 'Lock'")) {
            ps.setArray(1, c.createArrayOf("integer", pids.toArray()));
            try (ResultSet rs = ps.executeQuery()) {
                rs.next();
                return rs.getLong(1) > 0;
            }
        } catch (SQLException e) {
            return false;
        }
    }
}
