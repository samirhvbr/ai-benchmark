package com.condodesk;

import org.junit.jupiter.api.Test;

import java.time.LocalDate;
import java.util.Map;

/** The decoys of CATALOG.md section 4: code that looks wrong and is not. Each verifier proves the property the code guarantees, so that a "fix" that breaks it is seen. */
class S08_DecoyTest extends VBase {

    @Test
    @Verifies({"SEC-001.c"})
    void sec001c_theOwnersOfLateUnitsAreStillListed() throws Exception {
        invoice(unitA101, "2025-12", LocalDate.parse("2026-01-10"), "300.00");
        invoice(unitA201, "2025-12", LocalDate.parse("2026-01-10"), "400.00");
        Browser m = loggedIn(MANAGER_A);
        String page = ok(m, "/reports/delinquency").body();
        String csv = ok(m, "/reports/delinquency.csv").body();
        boolean holds = page.contains("Ana Owner") && page.contains("Carla Owner") && csv.contains("Apt 101,Ana Owner,") && csv.contains("Apt 201,Carla Owner,");
        Verdict.decoy("SEC-001.c", holds, "the delinquency report lists the owners of the late units, on the page and in the CSV: " + holds + " (expected true)");
    }

    @Test
    @Verifies({"SEC-007.b"})
    void sec007b_aDownloadKeepsItsExactNameAndNoLineBreak() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/documents");
        setupUpload(m, "rules, 2026 ü.pdf", PDF, "RULES");
        long id = (Long) value("select id from document");
        R d = m.get("/documents/" + id + "/download");
        String h = String.valueOf(d.header("Content-Disposition"));
        boolean holds = d.status() == 200 && h.startsWith("attachment") && h.contains("filename*=UTF-8''rules%2C%202026%20%C3%BC.pdf") && !h.contains("\r") && !h.contains("\n");
        Verdict.decoy("SEC-007.b", holds, "a document named \"rules, 2026 ü.pdf\" downloads as an attachment carrying its exact name in the RFC 5987 form and no line break: " + holds + " (header: " + h + ")");
    }

    @Test
    @Verifies({"SEC-003.b"})
    void sec003b_anErrorPageEscapesWhatItRepeats() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        R r = m.get("/invoices?status=" + enc("<script>alert(1)</script>"));
        boolean holds = r.status() == 400 && !r.body().contains("<script>alert(1)</script>") && r.body().contains("&lt;script&gt;alert(1)&lt;/script&gt;");
        Verdict.decoy("SEC-003.b", holds, "an invoice list asked for a status that is markup answered " + r.status() + " (expected 400) and repeats it escaped: " + holds + " (expected true)");
    }

    @Test
    @Verifies({"SEC-015.c"})
    void sec015c_theDummyPasswordIsNeverACredential() throws Exception {
        String message = "Invalid email or password.";
        R viaApi = api("POST", "/api/tokens", null, Map.of("email", OWNER_A101, "password", "dummy-password-never-used"));
        R missing = api("POST", "/api/tokens", null, Map.of("email", "nobody@maple.test", "password", "dummy-password-never-used"));
        R wrong = api("POST", "/api/tokens", null, Map.of("email", OWNER_A201, "password", "some-other-wrong-password"));
        boolean holds = viaApi.status() == 401 && missing.status() == 401 && wrong.status() == 401 && missing.body().contains(message) && wrong.body().contains(message) && api("POST", "/api/tokens", null, Map.of("email", TENANT_A102, "password", PASSWORD)).status() == 201;
        Verdict.decoy("SEC-015.c", holds, "the literal the code hashes to equalize timing logs nobody in (" + viaApi.status() + " for an account, " + missing.status() + " for a missing one, " + wrong.status() + " for a wrong password, all 401 with the same message), and a right password still does: " + holds + " (expected true)");
    }

    @Test
    @Verifies({"SEC-016.c"})
    void sec016c_theOpenRoutesAreTheLoginAndTheSignedWebhook() throws Exception {
        long id = invoice(unitA101, "2026-03", LocalDate.parse("2026-04-10"), "300.00");
        String our = (String) value("select our_number from invoice where id = ?", id);
        boolean login = api("POST", "/api/tokens", null, Map.of("email", OWNER_A101, "password", PASSWORD)).status() == 201;
        byte[] body = JSON.writeValueAsBytes(Map.of("event_id", "d-1", "our_number", our, "amount", "300.00", "paid_on", "2026-03-05"));
        int unsigned = api("POST", "/api/webhooks/bank", null, body).status();
        int forged = api("POST", "/api/webhooks/bank", null, body, "X-Bank-Signature", "sha256=00").status();
        int signed = webhook("d-2", our, "300.00", "2026-03-05").status();
        boolean holds = login && unsigned == 401 && forged == 401 && signed == 200;
        Verdict.decoy("SEC-016.c", holds, "the mobile login needs no session (" + login + "), the bank webhook refuses an unsigned and a forged event (" + unsigned + ", " + forged + ": expected 401 each) and accepts a signed one (" + signed + ": expected 200)");
    }

    @Test
    @Verifies({"SEC-017.c"})
    void sec017c_theBuildingParameterIsAnAdminsAndOnlyAnAdmins() throws Exception {
        String own = ok(loggedIn(MANAGER_A), "/bookings?building=" + buildingB).body();
        String resident = ok(loggedIn(OWNER_A101), "/bookings?building=" + buildingB).body();
        String admin = ok(loggedIn(ADMIN), "/bookings?building=" + buildingB).body();
        boolean holds = own.contains("Barbecue") && !own.contains("Gym") && resident.contains("Barbecue") && !resident.contains("Gym") && admin.contains("Gym") && !admin.contains("Barbecue");
        Verdict.decoy("SEC-017.c", holds, "a manager and a resident of building A asking for building B's bookings still get their own building's board, and the admin gets building B's: " + holds + " (expected true)");
    }
}
