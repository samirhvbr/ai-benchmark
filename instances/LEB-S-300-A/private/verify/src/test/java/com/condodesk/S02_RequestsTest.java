package com.condodesk;

import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.util.ArrayList;
import java.util.List;

/** Redirects, sessions, the login lock and errors: the rows of CATALOG.md sections 2.2 to 2.4 that touch the request itself. */
class S02_RequestsTest extends VBase {

    @Test
    @Verifies({"SEC-012"})
    void sec012_afterLoginOnlyALocalPathIsFollowed() throws Exception {
        List<String> escaped = new ArrayList<>();
        for (String next : new String[]{"//evil.example/", "/\\evil.example", "/\t/evil.example", "///evil.example/x"}) {
            Browser b = browser();
            b.get("/login");
            R r = b.post("/login", "email", OWNER_A101, "password", PASSWORD, "next", next);
            String to = r.location();
            if (r.status() != 303 || to == null || !isLocal(to)) escaped.add(next.replace("\t", "<TAB>") + " -> " + r.status() + " " + to);
        }
        Browser b = browser();
        b.get("/login");
        R legit = b.post("/login", "email", OWNER_A101, "password", PASSWORD, "next", "/invoices?month=2026-03");
        boolean works = legit.status() == 303 && "/invoices?month=2026-03".equals(legit.location());
        Verdict.fixed("SEC-012", escaped.isEmpty() && works, "after a login with next set to //evil.example/, /\\evil.example, a tab-led path and three slashes, the redirects that left the site: "
                + (escaped.isEmpty() ? "none" : escaped) + " (expected none); a local path with its query went to " + legit.location() + " (expected /invoices?month=2026-03)");
    }

    /** A Location that stays on the site: one slash then a character that is not a slash, a backslash or a control character. */
    private static boolean isLocal(String to) {
        if (!to.startsWith("/") || to.length() > 1 && (to.charAt(1) == '/' || to.charAt(1) == '\\')) return false;
        for (char c : to.toCharArray()) if (c < 0x20 || c == 0x7f) return false;
        return true;
    }

    @Test
    @Verifies({"SEC-013"})
    void sec013_loginStartsANewSession() throws Exception {
        Browser attacker = browser();
        attacker.get("/login");
        String planted = attacker.session();
        Browser victim = browser();
        victim.cookies.put("CDSESSION", planted);
        victim.get("/login");
        R login = victim.post("/login", "email", OWNER_A101, "password", PASSWORD);
        if (login.status() != 303) throw refused("POST /login", login);
        victim.get("/");
        boolean rotated = victim.session() != null && !victim.session().equals(planted);
        Browser replay = browser();
        replay.cookies.put("CDSESSION", planted);
        int replayed = replay.get("/account").status();
        boolean works = victim.get("/account").status() == 200;
        victim.post("/logout");
        boolean loggedOut = victim.get("/account").status() == 303;
        Verdict.fixed("SEC-013", rotated && replayed == 303 && works && loggedOut, "the cookie planted before login, replayed after the victim logged in, answered " + replayed
                + " (expected 303, to the login page); the victim's cookie changed at login: " + rotated + " (expected true); the victim's own pages answered 200: " + works
                + " and after logout 303: " + loggedOut);
    }

    @Test
    @Verifies({"SEC-019"})
    void sec019_theMobileLoginIsLockedLikeTheBrowsers() throws Exception {
        List<Integer> wrong = new ArrayList<>();
        for (int i = 0; i < 8; i++) wrong.add(api("POST", "/api/tokens", null, java.util.Map.of("email", OWNER_A101, "password", "wrong-password-" + i)).status());
        int right = api("POST", "/api/tokens", null, java.util.Map.of("email", OWNER_A101, "password", PASSWORD)).status();
        boolean locked = wrong.contains(429) && right == 429;
        int other = api("POST", "/api/tokens", null, java.util.Map.of("email", TENANT_A102, "password", PASSWORD)).status();
        Browser b = browser();
        b.get("/login");
        List<Integer> web = new ArrayList<>();
        for (int i = 0; i < 6; i++) web.add(b.post("/login", "email", OWNER_A201, "password", "wrong-password-" + i).status());
        boolean browserLock = web.get(5) == 429;
        Verdict.fixed("SEC-019", locked && other == 201 && browserLock, "eight wrong passwords on /api/tokens answered " + wrong + " and the right one then answered " + right
                + " (expected a 429 among them and 429 for the right one); another account's token answered " + other + " (expected 201); six wrong passwords in the browser ended in "
                + web.get(5) + " (expected 429)");
    }

    @Test
    @Verifies({"SEC-020"})
    void sec020_anInternalErrorShowsNoInternals() throws Exception {
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/documents");
        if (marcos.upload("/documents", "file", "minutes.pdf", PDF, "kind", "MINUTES").status() != 303) throw new IllegalStateException("POST /documents answered not 303");
        long id = (Long) value("select id from document");
        String stored = (String) value("select stored_name from document where id = ?", id);
        Files.delete(storage.resolve(String.valueOf(buildingA)).resolve(stored));   // the stored file is gone: the download fails inside
        R r = marcos.get("/documents/" + id + "/download");
        String body = r.body();
        boolean leaks = body.contains(storage.toString()) || body.contains("Exception") || body.contains("java.") || body.contains(stored);
        boolean incident = r.status() == 500 && body.matches("(?s).*\\(incident [0-9a-f]{8}\\).*");
        Verdict.fixed("SEC-020", !leaks && incident, "a download whose stored file is gone answered " + r.status() + (leaks ? " and its body shows internals (a path, an exception or a file name)" : " with no internals")
                + "; it carries an incident id: " + incident + " (expected 500, no internals, an incident id)");
    }

    @Test
    @Verifies({"SEC-005"})
    void sec005_anUploadWithoutItsTokenIsRefused() throws Exception {
        long invoice = invoice(unitA101, "2026-03", java.time.LocalDate.parse("2026-04-10"), "300.00");
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/documents");
        marcos.csrf = null;                                   // a form posted from another site cannot know the token
        int document = marcos.upload("/documents", "file", "x.pdf", PDF, "kind", "OTHER").status();
        Browser ana = loggedIn(OWNER_A101);
        ana.get("/invoices/" + invoice);
        ana.csrf = null;
        int proof = ana.upload("/invoices/" + invoice + "/proof", "file", "p.pdf", PDF).status();
        Browser alice = loggedIn(ADMIN);
        alice.get("/admin/statements");
        alice.csrf = null;
        int statement = alice.upload("/admin/statements", "statement", "s.ofx", ofx(new String[]{"F9", "20260303", "10.00", "memo of nobody"})).status();
        long stored = count("select count(*) from document") + count("select count(*) from payment");
        boolean blocked = denied(document) && denied(proof) && denied(statement) && stored == 0;
        Browser again = loggedIn(MANAGER_A);
        again.get("/documents");
        int legit = again.upload("/documents", "file", "x.pdf", PDF, "kind", "OTHER").status();
        Verdict.fixed("SEC-005", blocked && legit == 303, "uploads posted with the session cookie and no token answered " + document + " (a document), " + proof + " (a payment proof) and " + statement
                + " (a bank statement), expected a refusal each, and left " + stored + " rows (expected 0); the same upload with its token answered " + legit + " (expected 303)");
    }
}
