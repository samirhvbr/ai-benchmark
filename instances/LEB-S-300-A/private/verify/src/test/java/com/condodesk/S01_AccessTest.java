package com.condodesk;

import org.junit.jupiter.api.Test;

import java.time.LocalDate;

/** Who may do what, and see what: the rows of CATALOG.md section 2.3 and 2.4 that delete one access check. */
class S01_AccessTest extends VBase {
    static final String STRONG = "tulip-harbor-2026";

    @Test
    @Verifies({"SEC-016"})
    void sec016_onlyAnAdminCreatesAccounts() throws Exception {
        Browser ana = loggedIn(OWNER_A101);
        R fromResident = ana.post("/admin/users", "email", "mallory@elsewhere.test", "name", "Mallory", "role", "ADMIN", "password", STRONG);
        Browser marcos = loggedIn(MANAGER_A);
        R fromManager = marcos.post("/admin/users", "email", "mallory2@elsewhere.test", "name", "Mallory Two", "role", "ADMIN", "password", STRONG);
        // the manifest gives the whole page to the admin: a check on the new account's role leaves the other roles open to whoever posts them
        R manager = ana.post("/admin/users", "email", "mallory3@elsewhere.test", "name", "Mallory Three", "role", "MANAGER", "building", String.valueOf(buildingB), "password", STRONG);
        R resident = ana.post("/admin/users", "email", "mallory4@elsewhere.test", "name", "Mallory Four", "role", "RESIDENT", "building", String.valueOf(buildingA),
                "unit", String.valueOf(unitA102), "kind", "TENANT", "password", STRONG);
        long created = count("select count(*) from app_user where email like 'mallory%'");
        boolean blocked = denied(fromResident.status()) && denied(fromManager.status()) && denied(manager.status()) && denied(resident.status()) && created == 0;
        Browser alice = loggedIn(ADMIN);
        R legit = alice.post("/admin/users", "email", "new.owner@maple.test", "name", "New Owner", "role", "RESIDENT", "building", String.valueOf(buildingA),
                "unit", String.valueOf(unitA102), "kind", "OWNER", "password", STRONG);
        boolean works = legit.status() == 303 && count("select count(*) from app_user where email = 'new.owner@maple.test'") == 1;
        Verdict.fixed("SEC-016", blocked && works, "a resident posting a new ADMIN account answered " + fromResident.status() + ", a manager " + fromManager.status()
                + "; a resident posting a new MANAGER of another building " + manager.status() + " and a new RESIDENT " + resident.status()
                + " (expected a refusal, 403 or 404, each), and " + created + " accounts of that attacker exist (expected 0); the admin creating a resident answered " + legit.status() + " (expected 303)");
    }

    @Test
    @Verifies({"SEC-016.b"})
    void sec016b_onlyAManagerRegistersAPaymentByHand() throws Exception {
        long mine = invoice(unitA101, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        long theirs = invoice(unitB101, "2026-03", LocalDate.parse("2026-04-05"), "500.00");
        long toSettle = invoice(unitA102, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        long foreign = invoice(unitA201, "2026-03", LocalDate.parse("2026-04-10"), "400.00");
        long byAdmin = invoice(unitB102, "2026-03", LocalDate.parse("2026-04-10"), "350.00");
        Browser ana = loggedIn(OWNER_A101);
        R own = ana.post("/invoices/" + mine + "/payments", "amount", "300.00", "paid_on", "2026-03-05");
        R other = ana.post("/invoices/" + theirs + "/payments", "amount", "500.00", "paid_on", "2026-03-05");
        // the manifest gives the payment to "the building's manager": a manager of another building is not that
        R rival = loggedIn(MANAGER_B).post("/invoices/" + foreign + "/payments", "amount", "400.00", "paid_on", "2026-03-05");
        long paid = count("select count(*) from invoice where id in (?, ?, ?) and status = 'PAID'", mine, theirs, foreign);
        long rows = count("select count(*) from payment where source = 'MANUAL'");
        boolean blocked = denied(own.status()) && denied(other.status()) && denied(rival.status()) && paid == 0 && rows == 0;
        Browser marcos = loggedIn(MANAGER_A);
        R legit = marcos.post("/invoices/" + toSettle + "/payments", "amount", "300.00", "paid_on", "2026-03-05");
        R admin = loggedIn(ADMIN).post("/invoices/" + byAdmin + "/payments", "amount", "350.00", "paid_on", "2026-03-05");
        boolean works = legit.status() == 303 && "PAID".equals(value("select status from invoice where id = ?", toSettle))
                && admin.status() == 303 && "PAID".equals(value("select status from invoice where id = ?", byAdmin));
        Verdict.fixed("SEC-016.b", blocked && works, "a resident registering a payment on their own invoice answered " + own.status() + ", on another building's " + other.status()
                + "; a manager of another building registering one on this building's invoice " + rival.status() + " (expected a refusal, 403 or 404, each); invoices settled by them: " + paid
                + ", manual payments recorded: " + rows + " (expected 0 and 0); the building's manager registering one answered " + legit.status() + " and an admin " + admin.status()
                + " (expected 303 and PAID each)");
    }

    @Test
    @Verifies({"SEC-017"})
    void sec017_anInvoiceIsReadOnlyByWhoseItIs() throws Exception {
        long mine = invoice(unitA101, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        long sameBuilding = invoice(unitA201, "2026-03", LocalDate.parse("2026-04-10"), "400.00");
        long otherBuilding = invoice(unitB101, "2026-03", LocalDate.parse("2026-04-05"), "500.00");
        Browser ana = loggedIn(OWNER_A101);
        int a = ana.get("/invoices/" + sameBuilding).status();
        int b = ana.get("/invoices/" + otherBuilding).status();
        String token = token(OWNER_A101);
        int c = api("GET", "/api/invoices/" + otherBuilding, token, null).status();
        int d = api("GET", "/api/invoices/" + sameBuilding, token, null).status();
        int e = loggedIn(MANAGER_B).get("/invoices/" + sameBuilding).status();
        boolean blocked = denied(a) && denied(b) && denied(c) && denied(d) && denied(e);
        int own = ana.get("/invoices/" + mine).status();
        int manager = loggedIn(MANAGER_A).get("/invoices/" + sameBuilding).status();
        int admin = loggedIn(ADMIN).get("/invoices/" + otherBuilding).status();
        boolean works = own == 200 && manager == 200 && admin == 200;
        Verdict.fixed("SEC-017", blocked && works, "a resident reading another unit's invoice answered " + a + ", another building's " + b + ", over the API " + c + " and " + d
                + "; a manager of another building " + e + " (expected a refusal, 403 or 404, each); the owner's own invoice answered " + own + ", the building's manager reading a unit's " + manager
                + ", the admin reading another building's " + admin + " (expected 200 each)");
    }

    @Test
    @Verifies({"SEC-017.b"})
    void sec017b_aDocumentIsDownloadedOnlyByWhoMaySeeIt() throws Exception {
        long carlasInvoice = invoice(unitA201, "2026-03", LocalDate.parse("2026-04-10"), "400.00");
        Browser marcos = loggedIn(MANAGER_A);
        marcos.get("/documents");
        setupUpload(marcos, "minutes.pdf", PDF, "MINUTES");
        Browser carla = loggedIn(OWNER_A201);
        carla.get("/invoices/" + carlasInvoice);
        R sent = carla.upload("/invoices/" + carlasInvoice + "/proof", "file", "proof.pdf", PDF);
        if (sent.status() != 303) throw refused("POST /invoices/" + carlasInvoice + "/proof", sent);
        long minutes = (Long) value("select id from document where kind = 'MINUTES'");
        long proof = (Long) value("select id from document where kind = 'PROOF'");
        Browser ana = loggedIn(OWNER_A101);
        int a = ana.get("/documents/" + proof + "/download").status();
        int b = loggedIn(MANAGER_B).get("/documents/" + minutes + "/download").status();
        int c = loggedIn(OWNER_B101).get("/documents/" + proof + "/download").status();
        boolean blocked = denied(a) && denied(b) && denied(c);
        int building = ana.get("/documents/" + minutes + "/download").status();
        int mine = carla.get("/documents/" + proof + "/download").status();
        int manager = marcos.get("/documents/" + proof + "/download").status();
        boolean works = building == 200 && mine == 200 && manager == 200;
        Verdict.fixed("SEC-017.b", blocked && works, "a resident downloading another unit's payment proof answered " + a + ", a manager of another building the building's minutes " + b
                + ", a resident of another building the proof " + c + " (expected a refusal, 403 or 404, each); the building-wide minutes for a resident answered " + building + ", the owner's own proof "
                + mine + ", the manager reading the proof " + manager + " (expected 200 each)");
    }
}
