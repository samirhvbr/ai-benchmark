package com.condodesk.web;

import com.condodesk.booking.BookingService;
import com.condodesk.booking.BookingService.BookingRequest;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Booking;
import com.condodesk.domain.Model.BookingStatus;
import com.condodesk.domain.Model.CommonArea;
import com.condodesk.domain.Principal;
import com.condodesk.web.Server.Gate;

import java.time.LocalTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;

import static com.condodesk.web.Html.esc;

/** The bookings page: the list, the booking form, cancellation and confirmation. */
final class BookingPages {
    private static final DateTimeFormatter WHEN = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm");

    private final BookingService bookings;

    BookingPages(BookingService bookings) {
        this.bookings = bookings;
    }

    void register(Server s) {
        s.route("GET", "/bookings", Gate.BROWSER, this::list);
        s.route("POST", "/bookings", Gate.BROWSER, this::book);
        s.route("POST", "/bookings/{id}/cancel", Gate.BROWSER, this::cancel);
        s.route("POST", "/bookings/{id}/confirm", Gate.BROWSER, this::confirm);
    }

    private Response list(Request req) {
        Principal p = req.principal();
        long building = req.building();
        BookingService.Board board = bookings.board(p, building);
        ZoneId zone = board.building().zone();
        StringBuilder b = new StringBuilder("<table><tr><th>Area</th><th>Unit</th><th>From</th><th>To</th><th>Guests</th><th>Status</th><th></th></tr>");
        for (Booking k : board.bookings()) {
            b.append("<tr><td>").append(esc(k.areaName())).append("</td><td>").append(esc(k.unitLabel())).append("</td><td>")
                    .append(WHEN.format(k.startsAt().atZone(zone))).append("</td><td>").append(WHEN.format(k.endsAt().atZone(zone)))
                    .append("</td><td>").append(k.guests()).append("</td><td>").append(k.status()).append("</td><td>");
            if (k.status() != BookingStatus.CANCELLED) b.append(action(req, k.id(), "cancel", "Cancel"));
            if (k.status() == BookingStatus.REQUESTED && p.manages(k.buildingId())) b.append(action(req, k.id(), "confirm", "Confirm"));
            b.append("</td></tr>");
        }
        b.append("</table><h2>Book an area</h2><form method=\"post\" action=\"/bookings\">").append(Html.csrf(req))
                .append("<input type=\"hidden\" name=\"building\" value=\"").append(building).append("\"><label>Area <select name=\"area\">");
        for (CommonArea a : board.areas()) {
            b.append("<option value=\"").append(a.id()).append("\">").append(esc(a.name())).append(" (").append(a.opens()).append("–")
                    .append(a.closes()).append(a.fee().signum() > 0 ? ", fee " + Html.money(a.fee()) : "").append(")</option>");
        }
        b.append("</select></label><label>Unit id <input name=\"unit\" required").append(p.unitIds().size() == 1 ? " value=\"" + p.unitIds().iterator().next() + "\"" : "")
                .append("></label><label>Date <input type=\"date\" name=\"date\" required></label><label>From <input type=\"time\" name=\"start\" required></label>")
                .append("<label>To <input type=\"time\" name=\"end\" required></label><label>Guests <input type=\"number\" name=\"guests\" value=\"0\" min=\"0\"></label>")
                .append("<button>Book</button></form>");
        return Response.html(Html.page(req, "Bookings", b.toString()));
    }

    private Response book(Request req) {
        Booking k = bookings.book(req.principal(), new BookingRequest(longForm(req, "area"), longForm(req, "unit"), req.formDate("date"),
                time(req, "start"), time(req, "end"), req.form("guests") == null ? 0 : intForm(req, "guests")));
        String msg = k.status() == BookingStatus.REQUESTED ? "Booking requested; the manager will confirm it." : "Booking confirmed.";
        return Response.redirect(Html.flash("/bookings" + buildingQuery(req), msg));
    }

    private Response cancel(Request req) {
        bookings.cancel(req.principal(), req.id());
        return Response.redirect(Html.flash("/bookings" + buildingQuery(req), "Booking cancelled."));
    }

    private Response confirm(Request req) {
        bookings.confirm(req.principal(), req.id());
        return Response.redirect(Html.flash("/bookings" + buildingQuery(req), "Booking confirmed."));
    }

    private static String action(Request req, long id, String verb, String label) {
        return "<form class=\"inline\" method=\"post\" action=\"/bookings/" + id + "/" + verb + "\">" + Html.csrf(req) + "<button>" + label + "</button></form>";
    }

    private static String buildingQuery(Request req) {
        return req.principal().isAdmin() && req.form("building") != null ? "?building=" + Html.url(req.form("building")) : "";
    }

    private static long longForm(Request req, String name) {
        try {
            return Long.parseLong(req.requireForm(name));
        } catch (NumberFormatException e) {
            throw AppException.invalid(name + " must be a number.");
        }
    }

    private static int intForm(Request req, String name) {
        long value = longForm(req, name);
        if (value != (int) value) throw AppException.invalid(name + " must be a number.");
        return (int) value;
    }

    private static LocalTime time(Request req, String name) {
        try {
            return LocalTime.parse(req.requireForm(name));
        } catch (DateTimeParseException e) {
            throw AppException.invalid(name + " must be a time (HH:MM).");
        }
    }
}
