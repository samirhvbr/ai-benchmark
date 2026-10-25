package com.condodesk;

import org.junit.jupiter.api.Test;

/** How a password is stored: SEC-014 of CATALOG.md section 2.3. */
class S07_PasswordsTest extends VBase {
    static final String STRONG = "tulip-harbor-2026";
    static final String STORED = "pbkdf2-sha256\\$600000\\$[A-Za-z0-9+/=]+\\$[A-Za-z0-9+/=]+";

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
        boolean newOnes = h1.matches(STORED) && h2.matches(STORED) && !h1.equals(h2);
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/account");
        R change = ana.post("/account/password", "current", PASSWORD, "password", "cedar-lantern-2027");
        if (change.status() != 303) throw refused("POST /account/password", change);
        boolean changed = hashOf(OWNER_A101).matches(STORED);
        String before = hashOf(OWNER_A201);
        Browser carla = browser();
        carla.get("/login");
        R login = carla.post("/login", "email", OWNER_A201, "password", PASSWORD);
        boolean imported = before.startsWith("sha256:") && login.status() == 303 && hashOf(OWNER_A201).matches(STORED);
        Browser again = browser();
        again.get("/login");
        boolean stillWorks = again.post("/login", "email", OWNER_A201, "password", PASSWORD).status() == 303
                && browser().post("/login", "email", "first@maple.test", "password", STRONG).status() != 500 && loggedInOk("first@maple.test", STRONG)
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
