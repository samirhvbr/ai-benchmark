package com.condodesk.domain;

import java.math.BigDecimal;
import java.time.Instant;
import java.time.LocalDate;
import java.time.LocalTime;
import java.time.YearMonth;
import java.time.ZoneId;

/** The records the services and pages exchange. */
public final class Model {
    private Model() {
    }

    public enum Role { ADMIN, MANAGER, RESIDENT }

    public enum InvoiceStatus { OPEN, PAID, CANCELLED }

    public enum PaymentSource { STATEMENT, WEBHOOK, MANUAL }

    public enum BookingStatus { REQUESTED, CONFIRMED, CANCELLED }

    public enum DocumentKind { MINUTES, RULES, STATEMENT, PROOF, OTHER }

    public record Building(long id, String name, String address, ZoneId zone, int dueDay, BigDecimal lateFeePct,
                           BigDecimal monthlyInterestPct) {
        /** The building's calendar date at the given instant. */
        public LocalDate dateAt(Instant at) {
            return LocalDate.ofInstant(at, zone);
        }
    }

    public record Unit(long id, long buildingId, String label, BigDecimal idealFraction) {}

    public record User(long id, String email, String name, Role role, Long buildingId, String passwordHash, boolean active) {}

    public record Invoice(long id, long unitId, String unitLabel, long buildingId, YearMonth month, LocalDate dueDate,
                          BigDecimal amount, String ourNumber, InvoiceStatus status, LocalDate paidOn) {}

    public record Payment(long id, Long invoiceId, String externalId, PaymentSource source, BigDecimal amount, LocalDate paidOn,
                          String memo) {}

    public record CommonArea(long id, long buildingId, String name, LocalTime opens, LocalTime closes, int maxHours, int capacity,
                             BigDecimal fee) {}

    public record Booking(long id, long areaId, String areaName, long buildingId, long unitId, String unitLabel, long userId,
                          Instant startsAt, Instant endsAt, int guests, BookingStatus status) {}

    public record Notice(long id, long buildingId, String authorName, String title, String body, boolean pinned,
                         Instant publishedAt) {}

    public record StoredDocument(long id, long buildingId, Long unitId, DocumentKind kind, String originalName, String storedName,
                                 String contentType, long sizeBytes, String sourceUrl, Instant createdAt) {}

    /** A page of results and the total number of rows that match. */
    public record Page<T>(java.util.List<T> items, int page, int size, long total) {
        public int pages() {
            return (int) Math.max(1, (total + size - 1) / size);
        }
    }
}
