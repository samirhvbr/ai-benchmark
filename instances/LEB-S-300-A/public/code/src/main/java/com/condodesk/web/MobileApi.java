package com.condodesk.web;

import com.condodesk.auth.AuthService;
import com.condodesk.auth.Sessions;
import com.condodesk.billing.BillingService;
import com.condodesk.billing.BillingService.InvoiceView;
import com.condodesk.billing.PaymentService;
import com.condodesk.booking.BookingService;
import com.condodesk.booking.BookingService.BookingRequest;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Booking;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.Payment;
import com.condodesk.domain.Principal;
import com.condodesk.web.Server.Gate;
import com.fasterxml.jackson.databind.JsonNode;

import java.time.LocalDate;
import java.time.LocalTime;
import java.time.format.DateTimeParseException;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** The JSON API of the residents' mobile app, and the bank's webhook. */
final class MobileApi {
    private final AuthService auth;
    private final Sessions sessions;
    private final BillingService billing;
    private final PaymentService payments;
    private final BookingService bookings;

    MobileApi(AuthService auth, Sessions sessions, BillingService billing, PaymentService payments, BookingService bookings) {
        this.auth = auth;
        this.sessions = sessions;
        this.billing = billing;
        this.payments = payments;
        this.bookings = bookings;
    }

    void register(Server s) {
        s.route("POST", "/api/tokens", Gate.OPEN, this::token);
        s.route("GET", "/api/me", Gate.API, this::me);
        s.route("GET", "/api/invoices", Gate.API, this::invoices);
        s.route("GET", "/api/invoices/{id}", Gate.API, this::invoice);
        s.route("GET", "/api/bookings", Gate.API, this::bookings);
        s.route("POST", "/api/bookings", Gate.API, this::book);
        s.route("DELETE", "/api/bookings/{id}", Gate.API, this::cancel);
        s.route("POST", "/api/webhooks/bank", Gate.OPEN, this::bankWebhook);
    }

    private Response token(Request req) {
        JsonNode body = req.json();
        Principal p = auth.authenticate(text(body, "email"), text(body, "password"), req.clientAddress(), false);
        return Response.json(201, Map.of("token", sessions.issueApiToken(p.userId())));
    }

    private Response me(Request req) {
        Principal p = req.principal();
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", p.userId());
        m.put("email", p.email());
        m.put("name", p.name());
        m.put("role", p.role());
        m.put("building_id", p.buildingId());
        m.put("unit_ids", p.unitIds().stream().sorted().toList());
        return Response.json(200, m);
    }

    private Response invoices(Request req) {
        InvoiceStatus status = FinancePages.enumOf(InvoiceStatus.class, req.query("status"));
        List<Invoice> items = billing.list(req.principal(), null, status, null, "due", true, req.page()).items();
        return Response.json(200, Map.of("invoices", items.stream().map(MobileApi::invoiceJson).toList()));
    }

    private Response invoice(Request req) {
        InvoiceView v = billing.view(req.principal(), req.id());
        Map<String, Object> m = invoiceJson(v.invoice());
        m.put("amount_due", v.dueToday().toPlainString());
        m.put("days_late", v.daysLate());
        m.put("payments", v.payments().stream().map(MobileApi::paymentJson).toList());
        return Response.json(200, m);
    }

    private Response bookings(Request req) {
        Principal p = req.principal();
        if (p.buildingId() == null) throw AppException.invalid("This account has no building.");
        return Response.json(200, Map.of("bookings", bookings.board(p, p.buildingId()).bookings().stream().map(MobileApi::bookingJson).toList()));
    }

    private Response book(Request req) {
        JsonNode b = req.json();
        try {
            Booking k = bookings.book(req.principal(), new BookingRequest(number(b, "area_id"), number(b, "unit_id"), LocalDate.parse(text(b, "date")),
                    LocalTime.parse(text(b, "start")), LocalTime.parse(text(b, "end")), b.has("guests") ? smallNumber(b, "guests") : 0));
            return Response.json(201, bookingJson(k));
        } catch (DateTimeParseException e) {
            throw AppException.invalid("date is YYYY-MM-DD; start and end are HH:MM.");
        }
    }

    private Response cancel(Request req) {
        bookings.cancel(req.principal(), req.id());
        return new Response(204, null, new byte[0], List.of());
    }

    private Response bankWebhook(Request req) {
        PaymentService.Outcome o = payments.webhook(req.body(), req.header("X-Bank-Signature"));
        return Response.json(200, Map.of("outcome", o));
    }

    private static Map<String, Object> invoiceJson(Invoice i) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", i.id());
        m.put("unit", i.unitLabel());
        m.put("month", i.month().toString());
        m.put("due_date", i.dueDate().toString());
        m.put("amount", i.amount().toPlainString());
        m.put("our_number", i.ourNumber());
        m.put("status", i.status());
        m.put("paid_on", i.paidOn() == null ? null : i.paidOn().toString());
        return m;
    }

    private static Map<String, Object> paymentJson(Payment p) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("amount", p.amount().toPlainString());
        m.put("paid_on", p.paidOn().toString());
        m.put("source", p.source());
        return m;
    }

    private static Map<String, Object> bookingJson(Booking k) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", k.id());
        m.put("area_id", k.areaId());
        m.put("area", k.areaName());
        m.put("unit_id", k.unitId());
        m.put("starts_at", k.startsAt().toString());
        m.put("ends_at", k.endsAt().toString());
        m.put("guests", k.guests());
        m.put("status", k.status());
        return m;
    }

    private static String text(JsonNode body, String field) {
        JsonNode n = body.get(field);
        if (n == null || !n.isTextual() || n.asText().isBlank()) throw AppException.invalid(field + " is required.");
        return n.asText();
    }

    private static long number(JsonNode body, String field) {
        JsonNode n = body.get(field);
        if (n == null || !n.isIntegralNumber()) throw AppException.invalid(field + " must be a whole number.");
        return n.asLong();
    }

    /** A whole number that fits an {@code int}; a larger one is refused, never cut down to its low bits. */
    private static int smallNumber(JsonNode body, String field) {
        JsonNode n = body.get(field);
        if (n == null || !n.isIntegralNumber() || !n.canConvertToInt()) throw AppException.invalid(field + " must be a whole number.");
        return n.asInt();
    }
}
