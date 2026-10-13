package com.condodesk;

import com.fasterxml.jackson.databind.JsonNode;
import org.junit.jupiter.api.Test;

import java.time.Duration;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Login, logout, sessions, the password change, accounts and the mobile token. */
class AccountTest extends TestBase {

    @Test
    void theLoginPageGivesASessionAndAForm() throws Exception {
        Browser b = browser();
        R r = b.get("/login");
        assertEquals(200, r.status());
        assertNotNull(b.session());
        assertNotNull(b.csrf);
        assertTrue(r.body().contains("name=\"email\""));
    }

    @Test
    void aPageAsksForLoginAndComesBackAfterIt() throws Exception {
        Browser b = browser();
        R r = b.get("/invoices?status=OPEN");
        assertEquals(303, r.status());
        assertEquals("/login?next=%2Finvoices%3Fstatus%3DOPEN", r.location());
        b.get(r.location());
        R in = b.post("/login", "email", OWNER_A101, "password", PASSWORD, "next", "/invoices?status=OPEN");
        assertEquals(303, in.status());
        assertEquals("/invoices?status=OPEN", in.location());
        assertEquals(200, b.get("/invoices?status=OPEN").status());
    }

    @Test
    void theEmailIsNotCaseSensitive() throws Exception {
        Browser b = browser();
        b.get("/login");
        assertEquals(303, b.post("/login", "email", "  Ana@Maple.TEST ", "password", PASSWORD).status());
    }

    @Test
    void aWrongPasswordShowsTheFormAgain() throws Exception {
        Browser b = browser();
        b.get("/login");
        R r = b.post("/login", "email", OWNER_A101, "password", "wrong-password");
        assertEquals(401, r.status());
        assertTrue(r.body().contains("Invalid email or password."));
        assertTrue(r.body().contains("value=\"" + OWNER_A101 + "\""));
        assertEquals(401, b.post("/login", "email", "nobody@maple.test", "password", PASSWORD).status());
    }

    @Test
    void anInactiveAccountCannotLogIn() throws Exception {
        update("update app_user set active = false where id = ?", ownerA101);
        Browser b = browser();
        b.get("/login");
        assertEquals(401, b.post("/login", "email", OWNER_A101, "password", PASSWORD).status());
    }

    @Test
    void logoutEndsTheSession() throws Exception {
        Browser b = loggedIn(OWNER_A101);
        R out = b.post("/logout");
        assertEquals(303, out.status());
        assertEquals("/login", out.location());
        assertEquals(303, b.get("/").status());
    }

    @Test
    void aSessionEndsAfterThirtyIdleMinutes() throws Exception {
        Browser b = loggedIn(OWNER_A101);
        clock.advance(Duration.ofMinutes(29));
        assertEquals(200, b.get("/").status());
        clock.advance(Duration.ofMinutes(29));
        assertEquals(200, b.get("/").status());
        clock.advance(Duration.ofMinutes(31));
        assertEquals(303, b.get("/").status());
    }

    @Test
    void theDashboardShowsEachRoleItsOwnSummary() throws Exception {
        invoice(unitA101, "2026-01", java.time.LocalDate.parse("2026-02-10"), "300.00");
        assertTrue(loggedIn(OWNER_A101).get("/").body().contains("Open invoices: <strong>1</strong>, 1 overdue."));
        assertTrue(loggedIn(MANAGER_A).get("/").body().contains("Overdue invoices: <strong>1</strong>."));
        String adminPage = loggedIn(ADMIN).get("/").body();
        assertTrue(adminPage.contains("Maple Court") && adminPage.contains("Cedar House"));
    }

    @Test
    void thePasswordChangeKeepsThePolicy() throws Exception {
        Browser b = loggedIn(OWNER_A101);
        b.get("/account");
        assertEquals(400, b.post("/account/password", "current", PASSWORD, "password", "short").status());
        assertEquals(400, b.post("/account/password", "current", PASSWORD, "password", "qwertyuiop").status());
        assertEquals(400, b.post("/account/password", "current", "not-the-password", "password", "a-much-longer-one").status());
        R ok = b.post("/account/password", "current", PASSWORD, "password", "a-much-longer-one");
        assertEquals(303, ok.status());
        Browser again = browser();
        again.login(OWNER_A101, "a-much-longer-one");
        assertEquals(200, again.get("/").status());
    }

    @Test
    void anAdminCreatesAResidentWhoCanLogIn() throws Exception {
        Browser b = loggedIn(ADMIN);
        b.get("/admin/users");
        R r = b.post("/admin/users", "email", "eva@maple.test", "name", "Eva New", "role", "RESIDENT", "building", String.valueOf(buildingA),
                "unit", String.valueOf(unitA201), "kind", "TENANT", "password", "eva-first-password");
        assertEquals(303, r.status());
        assertTrue(b.get("/admin/users").body().contains("Eva New"));
        assertEquals(1, count("select count(*) from residency r join app_user u on u.id = r.user_id where u.email = 'eva@maple.test' and r.unit_id = ?", unitA201));
        Browser eva = browser();
        eva.login("eva@maple.test", "eva-first-password");
        assertEquals(200, eva.get("/invoices").status());
        assertEquals(409, b.post("/admin/users", "email", "EVA@maple.test", "name", "Eva Again", "role", "MANAGER", "building",
                String.valueOf(buildingA), "password", "eva-first-password").status());
        assertEquals(400, b.post("/admin/users", "email", "fred@maple.test", "name", "Fred", "role", "RESIDENT", "building",
                String.valueOf(buildingA), "unit", String.valueOf(unitB101), "kind", "OWNER", "password", "fred-first-password").status());
    }

    @Test
    void theMobileAppGetsATokenAndItsUser() throws Exception {
        String token = token(OWNER_A101);
        R me = api("GET", "/api/me", token, null);
        assertEquals(200, me.status());
        JsonNode j = me.json();
        assertEquals(OWNER_A101, j.get("email").asText());
        assertEquals("RESIDENT", j.get("role").asText());
        assertEquals(buildingA, j.get("building_id").asLong());
        assertEquals(unitA101, j.get("unit_ids").get(0).asLong());
        assertEquals(401, api("GET", "/api/me", null, null).status());
        assertEquals(401, api("GET", "/api/me", "not-a-token", null).status());
        assertEquals(401, api("POST", "/api/tokens", null, Map.of("email", OWNER_A101, "password", "wrong")).status());
    }

    @Test
    void errorsOfTheApiAreJson() throws Exception {
        R r = api("GET", "/api/invoices/999999", token(OWNER_A101), null);
        assertEquals(404, r.status());
        assertEquals("not_found", r.json().get("error").get("code").asText());
    }
}
