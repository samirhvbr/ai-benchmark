package com.condodesk.auth;

import com.condodesk.db.Db;

import java.security.SecureRandom;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.Optional;

/**
 * Browser sessions and mobile API tokens. Only the SHA-256 of a token is stored, so a copy of the database does not hand out
 * sessions. A browser session starts anonymous (the login form needs a CSRF token) and is replaced by a new one at login.
 */
public final class Sessions {
    public static final String COOKIE = "CDSESSION";
    private static final SecureRandom RANDOM = new SecureRandom();

    public record Session(String idHash, Long userId, String csrfToken) {}

    /** A new session and the token the browser keeps for it. */
    public record Issued(String token, Session session) {}

    private final Db db;
    private final Clock clock;
    private final Duration idle;

    public Sessions(Db db, Clock clock, Duration idle) {
        this.db = db;
        this.clock = clock;
        this.idle = idle;
    }

    /** The live session of a token, touched; an expired one is removed. */
    public Optional<Session> resolve(String token) {
        if (token == null || token.isEmpty()) return Optional.empty();
        String idHash = Passwords.sha256Hex(token);
        Instant now = clock.instant();
        return db.tx(c -> {
            Optional<Instant> lastSeen = Db.one(c, "select last_seen_at from user_session where id_hash = ?",
                    rs -> Db.instant(rs, "last_seen_at"), idHash);
            if (lastSeen.isEmpty()) return Optional.empty();
            if (lastSeen.get().plus(idle).isBefore(now)) {
                Db.update(c, "delete from user_session where id_hash = ?", idHash);
                return Optional.empty();
            }
            Db.update(c, "update user_session set last_seen_at = ? where id_hash = ?", now, idHash);
            return Db.one(c, "select id_hash, user_id, csrf_token from user_session where id_hash = ?",
                    rs -> new Session(rs.getString("id_hash"), Db.nullableLong(rs, "user_id"), rs.getString("csrf_token")), idHash);
        });
    }

    public Issued anonymous() {
        return create(null);
    }

    /** Logs the user in. */
    public Issued login(String previousToken, long userId) {
        Optional<Session> kept = resolve(previousToken);
        if (kept.isPresent()) {
            db.tx(c -> Db.update(c, "update user_session set user_id = ? where id_hash = ?", userId, kept.get().idHash()));
            return new Issued(previousToken, new Session(kept.get().idHash(), userId, kept.get().csrfToken()));
        }
        return create(userId);
    }

    public void logout(String token) {
        db.tx(c -> Db.update(c, "delete from user_session where id_hash = ?", Passwords.sha256Hex(token)));
    }

    public String cookie(String token, boolean secure) {
        return COOKIE + "=" + token + "; Path=/; HttpOnly; SameSite=Lax" + (secure ? "; Secure" : "");
    }

    public String expiredCookie(boolean secure) {
        return COOKIE + "=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax" + (secure ? "; Secure" : "");
    }

    public String issueApiToken(long userId) {
        String token = randomToken();
        db.tx(c -> Db.insert(c, "insert into api_token (user_id, token_hash, created_at) values (?, ?, ?)",
                userId, Passwords.sha256Hex(token), clock.instant()));
        return token;
    }

    /** The user of a mobile API token. */
    public Optional<Long> resolveApiToken(String token) {
        if (token == null || token.isEmpty()) return Optional.empty();
        String hash = Passwords.sha256Hex(token);
        return db.tx(c -> {
            Optional<Long> user = Db.one(c, "select user_id from api_token where token_hash = ?", rs -> rs.getLong("user_id"), hash);
            if (user.isPresent()) Db.update(c, "update api_token set last_used_at = ? where token_hash = ?", clock.instant(), hash);
            return user;
        });
    }

    private Issued create(Long userId) {
        String token = randomToken();
        Session s = new Session(Passwords.sha256Hex(token), userId, randomToken());
        Instant now = clock.instant();
        db.tx(c -> Db.update(c, "insert into user_session (id_hash, user_id, csrf_token, created_at, last_seen_at) values (?, ?, ?, ?, ?)",
                s.idHash(), userId, s.csrfToken(), now, now));
        return new Issued(token, s);
    }

    static String randomToken() {
        byte[] b = new byte[32];
        RANDOM.nextBytes(b);
        return Base64.getUrlEncoder().withoutPadding().encodeToString(b);
    }
}
