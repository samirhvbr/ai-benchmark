package com.condodesk;

import com.fasterxml.jackson.databind.JsonNode;
import org.junit.jupiter.api.Test;

import java.time.LocalDate;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Callable;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

/** Bookings of the common areas. "Today" is Wednesday 2026-03-04, 09:00 in São Paulo. */
class BookingTest extends TestBase {

    private R book(Browser b, long area, long unit, String date, String start, String end, int guests) throws Exception {
        return b.post("/bookings", "area", String.valueOf(area), "unit", String.valueOf(unit), "date", date, "start", start, "end", end,
                "guests", String.valueOf(guests));
    }

    private Browser resident(String email) throws Exception {
        Browser b = loggedIn(email);
        b.get("/bookings");
        return b;
    }

    private String statusOf(long unit, String startsAt) throws Exception {
        return (String) value("select status from booking where unit_id = ? and starts_at = ?::timestamptz and status <> 'CANCELLED'", unit, startsAt);
    }

    @Test
    void aFreeAreaIsConfirmedAndAPaidOneWaitsForTheManager() throws Exception {
        Browser ana = resident(OWNER_A101);
        R free = book(ana, barbecue, unitA101, "2026-03-07", "12:00", "16:00", 10);
        assertEquals(303, free.status(), free.body());
        assertTrue(free.location().startsWith("/bookings?flash="));
        assertEquals("CONFIRMED", statusOf(unitA101, "2026-03-07 12:00-03"));
        assertEquals(303, book(ana, partyRoom, unitA101, "2026-03-08", "18:00", "22:00", 30).status());
        assertEquals("REQUESTED", statusOf(unitA101, "2026-03-08 18:00-03"));
        long id = (Long) value("select id from booking where area_id = ?", partyRoom);
        ana.get("/bookings");
        assertEquals(403, ana.post("/bookings/" + id + "/confirm").status());
        Browser m = resident(MANAGER_A);
        assertTrue(m.get("/bookings").body().contains("Confirm"));
        assertEquals(303, m.post("/bookings/" + id + "/confirm").status());
        assertEquals("CONFIRMED", statusOf(unitA101, "2026-03-08 18:00-03"));
        assertEquals(409, m.post("/bookings/" + id + "/confirm").status());
    }

    @Test
    void theListShowsTheBuildingsLocalTimes() throws Exception {
        Browser ana = resident(OWNER_A101);
        book(ana, barbecue, unitA101, "2026-03-07", "12:00", "16:00", 10);
        String page = ana.get("/bookings").body();
        assertTrue(page.contains("<td>2026-03-07 12:00</td><td>2026-03-07 16:00</td>"), page);
    }

    @Test
    void overlappingBookingsAreRefusedAndAdjacentOnesAccepted() throws Exception {
        assertEquals(303, book(resident(OWNER_A101), barbecue, unitA101, "2026-03-07", "12:00", "16:00", 10).status());
        Browser bruno = resident(TENANT_A102);
        assertEquals(409, book(bruno, barbecue, unitA102, "2026-03-07", "15:00", "17:00", 5).status());
        assertEquals(409, book(bruno, barbecue, unitA102, "2026-03-07", "11:00", "13:00", 5).status());
        assertEquals(303, book(bruno, barbecue, unitA102, "2026-03-07", "16:00", "18:00", 5).status());
        assertEquals(303, book(bruno, partyRoom, unitA102, "2026-03-07", "12:00", "16:00", 5).status());
    }

    @Test
    void theAreasRulesAreChecked() throws Exception {
        Browser ana = resident(OWNER_A101);
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-07", "08:00", "10:00", 5).status());
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-07", "19:00", "22:00", 5).status());
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-07", "10:00", "15:00", 5).status());
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-07", "10:00", "12:00", 21).status());
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-07", "12:00", "11:00", 5).status());
        assertEquals(400, book(ana, barbecue, unitA101, "2026-03-03", "10:00", "12:00", 5).status());
        assertEquals(303, book(ana, barbecue, unitA101, "2026-03-07", "17:00", "21:00", 20).status());
        assertEquals(0, count("select count(*) from booking where starts_at < '2026-03-07 17:00-03'"));
    }

    @Test
    void aUnitHoldsAtMostTwoUpcomingBookings() throws Exception {
        Browser ana = resident(OWNER_A101);
        assertEquals(303, book(ana, barbecue, unitA101, "2026-03-07", "10:00", "12:00", 5).status());
        assertEquals(303, book(ana, barbecue, unitA101, "2026-03-14", "10:00", "12:00", 5).status());
        assertEquals(409, book(ana, partyRoom, unitA101, "2026-03-21", "10:00", "12:00", 5).status());
        long first = (Long) value("select id from booking where starts_at = '2026-03-07 10:00-03'");
        ana.get("/bookings");
        assertEquals(303, ana.post("/bookings/" + first + "/cancel").status());
        assertEquals(303, book(ana, partyRoom, unitA101, "2026-03-21", "10:00", "12:00", 5).status());
    }

    @Test
    void aUnitWithAnInvoiceOverdueForMoreThanThirtyDaysCannotBook() throws Exception {
        invoice(unitA101, "2026-01", LocalDate.parse("2026-02-02"), "300.00");
        Browser ana = resident(OWNER_A101);
        assertEquals(303, book(ana, barbecue, unitA101, "2026-03-07", "10:00", "12:00", 5).status());
        invoice(unitA101, "2025-12", LocalDate.parse("2026-02-01"), "300.00");
        assertEquals(409, book(ana, barbecue, unitA101, "2026-03-08", "10:00", "12:00", 5).status());
    }

    @Test
    void residentsCancelUpToFortyEightHoursBeforeAndTheManagerLater() throws Exception {
        Browser ana = resident(OWNER_A101);
        book(ana, barbecue, unitA101, "2026-03-06", "10:00", "12:00", 5);   // 49 hours ahead
        book(ana, barbecue, unitA101, "2026-03-05", "10:00", "12:00", 5);   // 25 hours ahead
        long far = (Long) value("select id from booking where starts_at = '2026-03-06 10:00-03'");
        long near = (Long) value("select id from booking where starts_at = '2026-03-05 10:00-03'");
        ana.get("/bookings");
        assertEquals(303, ana.post("/bookings/" + far + "/cancel").status());
        assertEquals(409, ana.post("/bookings/" + far + "/cancel").status());
        assertEquals(409, ana.post("/bookings/" + near + "/cancel").status());
        Browser m = resident(MANAGER_A);
        assertEquals(303, m.post("/bookings/" + near + "/cancel").status());
        assertEquals(2, count("select count(*) from booking where status = 'CANCELLED'"));
    }

    @Test
    void residentsBookOnlyForTheirOwnUnitsInTheirOwnBuilding() throws Exception {
        Browser ana = resident(OWNER_A101);
        assertEquals(403, book(ana, barbecue, unitA102, "2026-03-07", "10:00", "12:00", 5).status());
        assertEquals(404, book(ana, gym, unitA101, "2026-03-07", "10:00", "12:00", 1).status());
        assertEquals(403, book(ana, barbecue, unitB101, "2026-03-07", "10:00", "12:00", 1).status());
        Browser m = resident(MANAGER_A);
        assertEquals(303, book(m, barbecue, unitA102, "2026-03-07", "10:00", "12:00", 5).status());
        assertEquals(0, count("select count(*) from booking where unit_id <> ?", unitA102));
    }

    @Test
    void concurrentRequestsForTheSameSlotLeaveOneBooking() throws Exception {
        String[] tokens = {token(OWNER_A101), token(TENANT_A102), token(OWNER_A201), token(MANAGER_A)};
        long[] units = {unitA101, unitA102, unitA201, unitA102};
        ExecutorService pool = Executors.newFixedThreadPool(8);
        CountDownLatch go = new CountDownLatch(1);
        List<Future<Integer>> results = new ArrayList<>();
        for (int i = 0; i < 8; i++) {
            int k = i % 4;
            Callable<Integer> call = () -> {
                go.await();
                Map<String, Object> body = new LinkedHashMap<>();
                body.put("area_id", barbecue);
                body.put("unit_id", units[k]);
                body.put("date", "2026-03-07");
                body.put("start", "12:00");
                body.put("end", "15:00");
                body.put("guests", 4);
                return api("POST", "/api/bookings", tokens[k], body).status();
            };
            results.add(pool.submit(call));
        }
        go.countDown();
        int created = 0, refused = 0;
        for (Future<Integer> f : results) {
            int s = f.get();
            if (s == 201) created++;
            else if (s == 409) refused++;
        }
        pool.shutdown();
        assertEquals(1, created);
        assertEquals(7, refused);
        assertEquals(1, count("select count(*) from booking where area_id = ? and status <> 'CANCELLED'", barbecue));
    }

    @Test
    void theMobileAppBooksListsAndCancels() throws Exception {
        String token = token(OWNER_A101);
        Map<String, Object> body = new LinkedHashMap<>();
        body.put("area_id", barbecue);
        body.put("unit_id", unitA101);
        body.put("date", "2026-03-07");
        body.put("start", "12:00");
        body.put("end", "14:00");
        R created = api("POST", "/api/bookings", token, body);
        assertEquals(201, created.status(), created.body());
        JsonNode k = created.json();
        assertEquals("2026-03-07T15:00:00Z", k.get("starts_at").asText());
        assertEquals("CONFIRMED", k.get("status").asText());
        assertEquals(1, api("GET", "/api/bookings", token, null).json().get("bookings").size());
        assertEquals(0, api("GET", "/api/bookings", token(OWNER_A201), null).json().get("bookings").size());
        assertEquals(404, api("DELETE", "/api/bookings/" + k.get("id").asLong(), token(TENANT_A102), null).status());
        assertEquals(204, api("DELETE", "/api/bookings/" + k.get("id").asLong(), token, null).status());
        assertEquals("CANCELLED", value("select status from booking where id = ?", k.get("id").asLong()));
        body.put("date", "07/03/2026");
        assertEquals(400, api("POST", "/api/bookings", token, body).status());
    }
}
