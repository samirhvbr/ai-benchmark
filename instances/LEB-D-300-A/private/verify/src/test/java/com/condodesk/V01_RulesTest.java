package com.condodesk;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.time.LocalDate;
import java.util.Arrays;
import java.util.TimeZone;

/** Business rules: the building's calendar, the days late, what settles an invoice, and a statement taken whole or not at all. */
class V01_RulesTest extends VBase {
    private TimeZone jvmZone;

    @BeforeEach
    void jvmFarFromTheBuilding() {
        jvmZone = TimeZone.getDefault();
        TimeZone.setDefault(TimeZone.getTimeZone("Asia/Tokyo"));   // the buildings are in São Paulo: no rule may depend on the JVM's zone
    }

    @AfterEach
    void restoreZone() {
        TimeZone.setDefault(jvmZone);
    }

    @Test
    @Verifies({"BUG-006"})
    void bug006_theOverdueBlockCountsDaysOnTheBuildingsCalendar() throws Exception {
        // 2026-03-04T01:00Z is March 3, 22:00 in São Paulo and March 4, 10:00 in Tokyo
        clock.set(Instant.parse("2026-03-04T01:00:00Z"));
        invoice(unitA101, "2026-01", LocalDate.parse("2026-02-01"), "300.00");   // 30 days late on the building's calendar
        invoice(unitA201, "2026-01", LocalDate.parse("2026-01-31"), "300.00");   // 31 days late
        R thirty = api("POST", "/api/bookings", token(OWNER_A101), booking(barbecue, unitA101, "2026-03-10", "10:00", "12:00", 2));
        R thirtyOne = api("POST", "/api/bookings", token(OWNER_A201), booking(barbecue, unitA201, "2026-03-11", "10:00", "12:00", 2));
        // later the same day in UTC, March 4 in São Paulo too: an invoice due February 1 is now 31 days late
        clock.set(Instant.parse("2026-03-04T12:00:00Z"));
        invoice(unitA102, "2026-01", LocalDate.parse("2026-02-01"), "300.00");
        R morning = api("POST", "/api/bookings", token(TENANT_A102), booking(barbecue, unitA102, "2026-03-12", "10:00", "12:00", 2));
        boolean ok = thirty.status() == 201 && thirtyOne.status() == 409 && morning.status() == 409;
        Verdict.fixed("BUG-006", ok, "at 2026-03-04T01:00Z (March 3 in São Paulo), 30 days late: " + thirty.status() + " (expected 201), 31 days late: "
                + thirtyOne.status() + " (expected 409); at 12:00Z (March 4 in São Paulo), 31 days late: " + morning.status() + " (expected 409); the JVM is in Tokyo");
    }

    @Test
    @Verifies({"BUG-013"})
    void bug013_theDaysLateAreTheLengthOfThePeriodInDays() throws Exception {
        long id = invoice(unitA101, "2025-12", LocalDate.parse("2026-01-10"), "300.00");   // 53 days late on 2026-03-04
        R api = api("GET", "/api/invoices/" + id, token(OWNER_A101), null);
        if (api.status() != 200) throw refused("GET /api/invoices/" + id, api);
        String apiDue = api.json().get("amount_due").asText();
        long apiDays = api.json().get("days_late").asLong();
        String page = ok(loggedIn(OWNER_A101), "/invoices/" + id).body();
        boolean pageOk = page.contains("311.30") && page.contains("53 days late");
        String csv = ok(loggedIn(MANAGER_A), "/reports/delinquency.csv").body();
        String line = Arrays.stream(csv.split("\r\n")).filter(l -> l.startsWith("Apt 101,")).findFirst().orElse("(no line)");
        boolean csvOk = line.equals("Apt 101,Ana Owner,2025-12,2026-01-10,300.00,311.30,53");
        boolean ok = apiDue.equals("311.30") && apiDays == 53 && pageOk && csvOk;
        Verdict.fixed("BUG-013", ok, "an invoice of 300.00 due 2026-01-10, read on 2026-03-04: the API says amount_due " + apiDue + " and days_late " + apiDays
                + " (expected 311.30 and 53); the invoice page shows them: " + pageOk + "; the delinquency CSV line: " + line);
    }

    @Test
    @Verifies({"BUG-007"})
    void bug007_aPaymentAboveTheAmountDueSettlesTheInvoice() throws Exception {
        long over = invoice(unitA102, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        R once = webhook("ev-over", ourNumberOf(over), "310.00", "2026-03-04");
        if (once.status() != 200) throw refused("the webhook", once);
        long twice = invoice(unitA201, "2026-02", LocalDate.parse("2026-03-10"), "400.00");
        R first = webhook("ev-part", ourNumberOf(twice), "399.99", "2026-03-04");
        R second = webhook("ev-rest", ourNumberOf(twice), "50.00", "2026-03-04");
        if (first.status() != 200 || second.status() != 200) throw refused("the webhook", first.status() != 200 ? first : second);
        String o1 = once.json().get("outcome").asText(), o2 = first.json().get("outcome").asText(), o3 = second.json().get("outcome").asText();
        Object s1 = value("select status from invoice where id = ?", over), s2 = value("select status from invoice where id = ?", twice);
        boolean ok = o1.equals("PAID") && "PAID".equals(s1) && o2.equals("PARTIAL") && o3.equals("PAID") && "PAID".equals(s2);
        Verdict.fixed("BUG-007", ok, "310.00 for an invoice of 300.00: " + o1 + ", invoice " + s1 + " (expected PAID, PAID); 399.99 then 50.00 for one of 400.00: "
                + o2 + " then " + o3 + ", invoice " + s2 + " (expected PARTIAL, PAID, PAID)");
    }

    @Test
    @Verifies({"BUG-014"})
    void bug014_aStatementIsImportedWholeOrNotAtAll() throws Exception {
        long id = invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        String our = ourNumberOf(id);
        Browser b = loggedIn(ADMIN);
        ok(b, "/admin/statements");
        R bad = b.upload("/admin/statements", "statement", "bad.ofx",
                ofx(new String[] {"F1", "20260303", "300.00", "PIX " + our}, new String[] {"", "20260303", "10.00", "no id"}));
        long paymentsAfterBad = count("select count(*) from payment");
        Object statusAfterBad = value("select status from invoice where id = ?", id);
        R good = b.upload("/admin/statements", "statement", "good.ofx", ofx(new String[] {"F1", "20260303", "300.00", "PIX " + our}));
        Object statusAfterGood = value("select status from invoice where id = ?", id);
        boolean ok = bad.status() == 400 && paymentsAfterBad == 0 && "OPEN".equals(statusAfterBad) && good.status() == 200 && "PAID".equals(statusAfterGood);
        Verdict.fixed("BUG-014", ok, "a matched line then a line without FITID: " + bad.status() + ", " + paymentsAfterBad + " payments recorded, invoice " + statusAfterBad
                + " (expected 400, 0, OPEN); the matched line alone afterwards: " + good.status() + ", invoice " + statusAfterGood + " (expected 200, PAID)");
    }
}
