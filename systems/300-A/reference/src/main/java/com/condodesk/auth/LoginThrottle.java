package com.condodesk.auth;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;

import java.sql.Connection;
import java.sql.SQLException;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Locale;
import java.util.Optional;

/**
 * Counts failed logins per account and per client address. Five failures on an account, or twenty from one address, within
 * fifteen minutes lock that key for fifteen minutes; a locked key answers 429 even to the right password.
 */
public final class LoginThrottle {
    static final int ACCOUNT_LIMIT = 5;
    static final int ADDRESS_LIMIT = 20;
    static final Duration WINDOW = Duration.ofMinutes(15);
    static final Duration LOCK = Duration.ofMinutes(15);

    private record Counter(int failures, Instant windowStart, Instant lockedUntil) {}

    private final Db db;
    private final Clock clock;

    public LoginThrottle(Db db, Clock clock) {
        this.db = db;
        this.clock = clock;
    }

    public void check(String email, String address) {
        Instant now = clock.instant();
        boolean locked = db.read(c -> isLocked(c, accountKey(email), now) || isLocked(c, addressKey(address), now));
        if (locked) throw AppException.tooMany();
    }

    public void failure(String email, String address) {
        db.tx(c -> {
            count(c, accountKey(email), ACCOUNT_LIMIT);
            count(c, addressKey(address), ADDRESS_LIMIT);
            return null;
        });
    }

    /** A successful login clears the account's count; the address keeps its own. */
    public void success(String email) {
        db.tx(c -> Db.update(c, "delete from login_throttle where key = ?", accountKey(email)));
    }

    private boolean isLocked(Connection c, String key, Instant now) throws SQLException {
        Optional<Instant> until = Db.one(c, "select locked_until from login_throttle where key = ?", rs -> Db.instant(rs, "locked_until"), key);
        return until.isPresent() && until.get() != null && now.isBefore(until.get());
    }

    private void count(Connection c, String key, int limit) throws SQLException {
        Instant now = clock.instant();
        Optional<Counter> current = Db.one(c, "select failures, window_start, locked_until from login_throttle where key = ? for update",
                rs -> new Counter(rs.getInt("failures"), Db.instant(rs, "window_start"), Db.instant(rs, "locked_until")), key);
        Counter next;
        if (current.isEmpty() || current.get().windowStart().plus(WINDOW).isBefore(now)) {
            next = new Counter(1, now, null);
        } else {
            next = new Counter(current.get().failures() + 1, current.get().windowStart(), current.get().lockedUntil());
        }
        if (next.failures() >= limit && next.lockedUntil() == null) next = new Counter(next.failures(), next.windowStart(), now.plus(LOCK));
        Db.update(c, "insert into login_throttle (key, failures, window_start, locked_until) values (?, ?, ?, ?) "
                        + "on conflict (key) do update set failures = excluded.failures, window_start = excluded.window_start, locked_until = excluded.locked_until",
                key, next.failures(), next.windowStart(), next.lockedUntil());
    }

    private static String accountKey(String email) {
        return "account:" + email.trim().toLowerCase(Locale.ROOT);
    }

    private static String addressKey(String address) {
        return "address:" + address;
    }
}
