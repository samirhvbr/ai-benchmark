package com.condodesk;

import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.function.Predicate;

/**
 * Holds the first connection that runs a command matching the trigger, inside whatever transaction it is in, until the verifier
 * lets it go; every later command and connection passes. The ceiling only keeps a broken scenario from hanging.
 */
final class Hold {
    private final Predicate<String> trigger;
    private final long ceilingMs;
    private final CountDownLatch reached = new CountDownLatch(1);
    private final CountDownLatch release = new CountDownLatch(1);
    private volatile boolean taken;
    volatile boolean overran;

    Hold(Predicate<String> trigger, long ceilingMs) {
        this.trigger = trigger;
        this.ceilingMs = ceilingMs;
    }

    /** For use as Instrument.Hook. */
    void before(Instrument.Conn c, String sql, List<Object> args) {
        if (taken || !trigger.test(sql.toLowerCase())) return;
        synchronized (this) {
            if (taken) return;
            taken = true;
        }
        c.arrived = true;
        reached.countDown();
        try {
            if (!release.await(ceilingMs, TimeUnit.MILLISECONDS)) overran = true;
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    /** Waits until a connection is held here; false when the ceiling ran out first. */
    boolean awaitHeld() throws InterruptedException {
        return reached.await(ceilingMs, TimeUnit.MILLISECONDS);
    }

    void release() {
        release.countDown();
    }
}
