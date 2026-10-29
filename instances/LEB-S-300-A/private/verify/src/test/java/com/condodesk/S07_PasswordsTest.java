package com.condodesk;

import org.junit.jupiter.api.Test;

import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** How a password is stored: SEC-014 of CATALOG.md section 2.3. */
class S07_PasswordsTest extends VBase {
    static final String STRONG = "tulip-harbor-2026";
    /** The manifest contracts "the system's own hash", not its form: PBKDF2 with SHA-256 or SHA-512 is what the JDK gives without a dependency, written as name, iterations, salt and key. */
    static final Pattern STORED = Pattern.compile("pbkdf2-sha(256|512)\\$(\\d{1,9})\\$([A-Za-z0-9+/=_-]{16,})\\$([A-Za-z0-9+/=_-]{20,})");

    /** Salted (a salt of at least sixteen characters) and slow (the iterations OWASP gives as the floor for the hash, 600,000 for SHA-256 and 210,000 for SHA-512, at any value above it). */
    static boolean slowAndSalted(String stored) {
        if (stored == null) return false;
        Matcher m = STORED.matcher(stored);
        return m.matches() && Long.parseLong(m.group(2)) >= ("256".equals(m.group(1)) ? 600_000 : 210_000);
    }

    private String hashOf(String email) throws Exception {
        return (String) value("select password_hash from app_user where email = ?", email);
    }

    @Test
    @Verifies({"SEC-014"})
    void sec014_passwordsAreStoredSaltedAndSlowAndTheOldOnesAreUpgraded() throws Exception {
        Browser alice = loggedIn(ADMIN);
        alice.get("/admin/users");
        for (String email : new String[]{"first@maple.test", "second@maple.test"}) {
            R r = alice.post("/admin/users", "email", email, "name", "New Tenant", "role", "RESIDENT", "building", String.valueOf(buildingA), "unit", String.valueOf(unitA102), "kind", "TENANT", "password", STRONG);
            if (r.status() != 303) throw refused("POST /admin/users", r);
        }
        String h1 = hashOf("first@maple.test"), h2 = hashOf("second@maple.test");
        boolean newOnes = slowAndSalted(h1) && slowAndSalted(h2) && !h1.equals(h2);
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/account");
        R change = ana.post("/account/password", "current", PASSWORD, "password", "cedar-lantern-2027");
        if (change.status() != 303) throw refused("POST /account/password", change);
        boolean changed = slowAndSalted(hashOf(OWNER_A101));
        String before = hashOf(OWNER_A201);
        Browser carla = browser();
        carla.get("/login");
        R login = carla.post("/login", "email", OWNER_A201, "password", PASSWORD);
        boolean imported = before.startsWith("sha256:") && login.status() == 303 && slowAndSalted(hashOf(OWNER_A201));
        Browser again = browser();
        again.get("/login");
        boolean stillWorks = again.post("/login", "email", OWNER_A201, "password", PASSWORD).status() == 303
                && loggedInOk("first@maple.test", STRONG)
                && loggedInOk(OWNER_A101, "cedar-lantern-2027");
        Verdict.fixed("SEC-014", newOnes && changed && imported && stillWorks, "two accounts created with the same password are stored salted and slow, and differently: " + newOnes + "; a changed password is: " + changed
                + "; an account imported from the old system is upgraded at its next login: " + imported + " (expected true each); the logins that must keep working do: " + stillWorks + " (expected true)");
    }

    private boolean loggedInOk(String email, String password) throws Exception {
        Browser b = browser();
        b.get("/login");
        return b.post("/login", "email", email, "password", password).status() == 303;
    }
}
