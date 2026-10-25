package com.condodesk;

import com.fasterxml.jackson.databind.JsonNode;
import org.junit.jupiter.api.Test;

import java.math.BigDecimal;
import java.time.Instant;
import java.time.LocalDate;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** The billing run, the invoices, what they cost after the due date, and the payments that settle them. */
class BillingTest extends TestBase {

    private Browser billMarch(String budget) throws Exception {
        Browser b = loggedIn(ADMIN);
        b.get("/admin/billing");
        R r = b.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-03", "budget", budget);
        assertEquals(303, r.status(), r.body());
        return b;
    }

    private BigDecimal amountOf(long unit, String month) throws Exception {
        return (BigDecimal) value("select amount from invoice where unit_id = ? and month = ?", unit, month);
    }

    private String ourNumberOf(long unit, String month) throws Exception {
        return (String) value("select our_number from invoice where unit_id = ? and month = ?", unit, month);
    }

    @Test
    void theBudgetIsSplitByFractionAndAddsUpExactly() throws Exception {
        billMarch("1000.01");
        assertEquals(new BigDecimal("300.00"), amountOf(unitA101, "2026-03"));
        assertEquals(new BigDecimal("300.00"), amountOf(unitA102, "2026-03"));
        assertEquals(new BigDecimal("400.01"), amountOf(unitA201, "2026-03"));
        assertEquals(0, count("select count(*) from invoice i join unit u on u.id = i.unit_id where u.building_id = ?", buildingB));
    }

    @Test
    void leftoverCentsGoToTheLargestRemaindersThenByLabel() throws Exception {
        billMarch("1000.02");
        assertEquals(new BigDecimal("300.01"), amountOf(unitA101, "2026-03"));
        assertEquals(new BigDecimal("300.00"), amountOf(unitA102, "2026-03"));
        assertEquals(new BigDecimal("400.01"), amountOf(unitA201, "2026-03"));
    }

    @Test
    void aMonthIsBilledOnceDueOnTheDueDayOfTheNextMonth() throws Exception {
        Browser b = billMarch("900.00");
        assertEquals(LocalDate.parse("2026-04-10"), ((java.sql.Date) value("select due_date from invoice where unit_id = ?", unitA101)).toLocalDate());
        assertEquals("202603-" + String.format("%04d-%06d", buildingA, unitA101), ourNumberOf(unitA101, "2026-03"));
        assertEquals(409, b.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-03", "budget", "900.00").status());
        assertEquals(3, count("select count(*) from invoice"));
        assertEquals(400, b.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-13", "budget", "900.00").status());
        assertEquals(400, b.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-04", "budget", "900.001").status());
    }

    @Test
    void onlyAnAdminRunsTheBilling() throws Exception {
        Browser m = loggedIn(MANAGER_A);
        m.get("/invoices");
        assertEquals(403, m.post("/admin/billing", "month", "2026-03", "budget", "900.00").status());
        assertEquals(0, count("select count(*) from invoice"));
    }

    @Test
    void eachRoleListsItsOwnInvoices() throws Exception {
        billMarch("1000.00");
        String resident = loggedIn(OWNER_A101).get("/invoices").body();
        assertTrue(resident.contains("Apt 101") && !resident.contains("Apt 102"));
        String manager = loggedIn(MANAGER_A).get("/invoices").body();
        assertTrue(manager.contains("Apt 101") && manager.contains("Apt 102") && manager.contains("Apt 201"));
        assertTrue(loggedIn(MANAGER_B).get("/invoices").body().contains("0 invoices."));
        assertTrue(loggedIn(ADMIN).get("/invoices?building=" + buildingA).body().contains("3 invoices."));
    }

    @Test
    void theListFiltersSortsAndPages() throws Exception {
        for (int m = 1; m <= 9; m++) invoice(unitA101, "2025-0" + m, LocalDate.parse("2025-0" + m + "-10").plusMonths(1), "100.00");
        for (int m = 1; m <= 9; m++) invoice(unitA102, "2025-0" + m, LocalDate.parse("2025-0" + m + "-10").plusMonths(1), "100.00");
        for (int m = 1; m <= 9; m++) invoice(unitA201, "2025-0" + m, LocalDate.parse("2025-0" + m + "-10").plusMonths(1), "1" + m + "0.00");
        update("update invoice set status = 'PAID', paid_on = due_date where month = '2025-01'");
        Browser b = loggedIn(MANAGER_A);
        R first = b.get("/invoices");
        assertTrue(first.body().contains("27 invoices.") && first.body().contains("Page 1 of 2"));
        assertTrue(b.get("/invoices?page=2").body().contains("Page 2 of 2"));
        assertTrue(b.get("/invoices?status=PAID").body().contains("3 invoices."));
        assertTrue(b.get("/invoices?month=2025-04").body().contains("3 invoices."));
        String byAmount = b.get("/invoices?sort=amount&dir=desc").body();
        assertTrue(byAmount.indexOf("190.00") < byAmount.indexOf("100.00"));
        assertEquals(400, b.get("/invoices?sort=password_hash").status());
        assertEquals(400, b.get("/invoices?status=LOST").status());
    }

    @Test
    void anInvoiceCostsTheLateFeeAndProRataInterestAfterItsDueDate() throws Exception {
        long id = invoice(unitA101, "2026-01", LocalDate.parse("2026-02-17"), "300.00");
        Browser b = loggedIn(OWNER_A101);
        // 2026-03-04 in São Paulo: 15 days late. 300.00 + 2% (6.00) + 1% a month for 15 days (1.50)
        assertTrue(b.get("/invoices/" + id).body().contains("<strong>307.50</strong> (15 days late)"));
        JsonNode j = api("GET", "/api/invoices/" + id, token(OWNER_A101), null).json();
        assertEquals("307.50", j.get("amount_due").asText());
        assertEquals(15, j.get("days_late").asInt());
    }

    @Test
    void lateIsCountedOnTheBuildingsCalendar() throws Exception {
        long id = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String token = token(OWNER_A101);
        clock.set(Instant.parse("2026-03-11T02:30:00Z"));   // still March 10 in São Paulo
        assertEquals("300.00", api("GET", "/api/invoices/" + id, token, null).json().get("amount_due").asText());
        clock.set(Instant.parse("2026-03-11T03:30:00Z"));   // March 11 in São Paulo
        assertEquals("306.10", api("GET", "/api/invoices/" + id, token, null).json().get("amount_due").asText());
    }

    @Test
    void aPaymentByHandSettlesWhenItCoversTheAmountDue() throws Exception {
        long id = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        Browser m = loggedIn(MANAGER_A);
        m.get("/invoices/" + id);
        R partial = m.post("/invoices/" + id + "/payments", "amount", "100.00", "paid_on", "2026-03-04");
        assertTrue(partial.location().contains("PARTIAL"));
        assertEquals("OPEN", value("select status from invoice where id = ?", id));
        R rest = m.post("/invoices/" + id + "/payments", "amount", "200.00", "paid_on", "2026-03-05");
        assertTrue(rest.location().contains("PAID"));
        assertEquals("PAID", value("select status from invoice where id = ?", id));
        assertEquals(LocalDate.parse("2026-03-05"), ((java.sql.Date) value("select paid_on from invoice where id = ?", id)).toLocalDate());
    }

    @Test
    void aStatementSettlesMatchedInvoicesAndIsIdempotent() throws Exception {
        long a = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        long c = invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        byte[] statement = ofx(
                new String[]{"F1", "20260303", "300.00", "PIX " + ourNumberOf(unitA101, "2026-02")},
                new String[]{"F2", "20260303", "150.00", "TED ref " + ourNumberOf(unitA201, "2026-02")},
                new String[]{"F3", "20260303", "99.00", "unknown payer"},
                new String[]{"F4", "20260303", "-35.00", "bank fee", "DEBIT"});
        Browser b = loggedIn(ADMIN);
        b.get("/admin/statements");
        R r = b.upload("/admin/statements", "statement", "march.ofx", statement);
        assertEquals(200, r.status(), r.body());
        assertTrue(r.body().contains("id=\"paid\">1<") && r.body().contains("id=\"partial\">1<") && r.body().contains("id=\"unmatched\">1<"));
        assertEquals("PAID", value("select status from invoice where id = ?", a));
        assertEquals("OPEN", value("select status from invoice where id = ?", c));
        R again = b.upload("/admin/statements", "statement", "march.ofx", statement);
        assertTrue(again.body().contains("id=\"duplicate\">3<"));
        assertEquals(3, count("select count(*) from payment"));
    }

    @Test
    void aStatementWithABadLineRecordsNothing() throws Exception {
        invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        byte[] statement = ofx(new String[]{"F1", "20260303", "300.00", "PIX " + ourNumberOf(unitA101, "2026-02")},
                new String[]{"", "20260303", "10.00", "no id"});
        Browser b = loggedIn(ADMIN);
        b.get("/admin/statements");
        assertEquals(400, b.upload("/admin/statements", "statement", "bad.ofx", statement).status());
        assertEquals(0, count("select count(*) from payment"));
        assertEquals(400, b.upload("/admin/statements", "statement", "bad.ofx", "<OFX><unclosed>".getBytes()).status());
    }

    @Test
    void theBanksWebhookSettlesAndIgnoresRepeats() throws Exception {
        long id = invoice(unitA102, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String our = ourNumberOf(unitA102, "2026-02");
        R r = webhook("ev-1", our, "300.00", "2026-03-04");
        assertEquals(200, r.status(), r.body());
        assertEquals("PAID", r.json().get("outcome").asText());
        assertEquals("DUPLICATE", webhook("ev-1", our, "300.00", "2026-03-04").json().get("outcome").asText());
        assertEquals("ALREADY_SETTLED", webhook("ev-2", our, "300.00", "2026-03-04").json().get("outcome").asText());
        assertEquals("UNMATCHED", webhook("ev-3", "209901-0001-000001", "10.00", "2026-03-04").json().get("outcome").asText());
        assertEquals("PAID", value("select status from invoice where id = ?", id));
    }

    @Test
    void aLatePaymentMustCoverFeeAndInterest() throws Exception {
        long id = invoice(unitA102, "2026-01", LocalDate.parse("2026-02-17"), "300.00");
        String our = ourNumberOf(unitA102, "2026-01");
        assertEquals("PARTIAL", webhook("ev-1", our, "300.00", "2026-03-04").json().get("outcome").asText());
        assertEquals("PAID", webhook("ev-2", our, "7.50", "2026-03-04").json().get("outcome").asText());
        assertEquals("PAID", value("select status from invoice where id = ?", id));
    }

    @Test
    void theDelinquencyReportListsWhatIsLateWithWhatItCostsToday() throws Exception {
        invoice(unitA101, "2026-01", LocalDate.parse("2026-02-17"), "300.00");
        invoice(unitA201, "2026-01", LocalDate.parse("2026-02-17"), "400.00");
        invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        Browser m = loggedIn(MANAGER_A);
        R page = m.get("/reports/delinquency");
        assertEquals(200, page.status());
        assertTrue(page.body().contains("Ana Owner") && page.body().contains("Carla Owner"));
        assertTrue(page.body().contains("<strong id=\"total-due\">717.50</strong>"));
        R csv = m.get("/reports/delinquency.csv");
        assertEquals(200, csv.status());
        assertTrue(csv.header("Content-Disposition").startsWith("attachment;"));
        assertEquals(List.of("unit,owners,month,due_date,amount,amount_due,days_late",
                        "Apt 101,Ana Owner,2026-01,2026-02-17,300.00,307.50,15",
                        "Apt 201,Carla Owner,2026-01,2026-02-17,400.00,410.00,15"),
                List.of(csv.body().split("\r\n")));
        assertEquals(403, loggedIn(OWNER_A101).get("/reports/delinquency").status());
        assertFalse(loggedIn(MANAGER_B).get("/reports/delinquency.csv?building=" + buildingA).body().contains("Ana Owner"));
    }

    @Test
    void theCollectionReportGivesEachMonthItsSettledShare() throws Exception {
        long a = invoice(unitA101, "2026-01", LocalDate.parse("2026-02-10"), "300.00");
        invoice(unitA102, "2026-01", LocalDate.parse("2026-02-10"), "300.00");
        invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        webhook("ev-1", ourNumberOf(unitA101, "2026-01"), "300.00", "2026-02-09");
        String page = loggedIn(MANAGER_A).get("/reports/collection?year=2026").body();
        assertTrue(page.contains("<td>2026-01</td><td class=\"num\">600.00</td><td class=\"num\">300.00</td><td class=\"num\">300.00</td><td class=\"num\">50.0%</td>"), page);
        assertTrue(page.contains("<td>2026-02</td><td class=\"num\">400.00</td><td class=\"num\">0.00</td><td class=\"num\">0.00</td><td class=\"num\">0.0%</td>"), page);
        assertEquals("PAID", value("select status from invoice where id = ?", a));
    }

    @Test
    void theMobileAppListsTheResidentsInvoices() throws Exception {
        invoice(unitA101, "2026-01", LocalDate.parse("2026-02-10"), "300.00");
        invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "310.00");
        invoice(unitA102, "2026-02", LocalDate.parse("2026-03-10"), "320.00");
        JsonNode list = api("GET", "/api/invoices", token(OWNER_A101), null).json().get("invoices");
        assertEquals(2, list.size());
        assertEquals("2026-02", list.get(0).get("month").asText());
        assertEquals(Map.of("unit", "Apt 101", "amount", "310.00", "status", "OPEN"),
                Map.of("unit", list.get(0).get("unit").asText(), "amount", list.get(0).get("amount").asText(), "status", list.get(0).get("status").asText()));
    }
}
