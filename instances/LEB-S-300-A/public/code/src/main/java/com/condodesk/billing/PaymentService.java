package com.condodesk.billing;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.PaymentSource;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.FinanceRepository;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.io.IOException;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.SQLException;
import java.time.Clock;
import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.EnumMap;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/** Payments from bank statements, from the bank's webhook and by hand, and how they settle invoices. */
public final class PaymentService {
    private static final Pattern OUR_NUMBER = Pattern.compile("\\b(\\d{6}-\\d{4}-\\d{6})\\b");
    private static final ObjectMapper JSON = new ObjectMapper();

    public enum Outcome { PAID, PARTIAL, UNMATCHED, DUPLICATE, ALREADY_SETTLED }

    private final Db db;
    private final BuildingRepository buildings;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;
    private final String bankSecret;

    public PaymentService(Db db, BuildingRepository buildings, FinanceRepository finance, AuditLog audit, Clock clock, String bankSecret) {
        this.db = db;
        this.buildings = buildings;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
        this.bankSecret = bankSecret;
    }

    /** Imports the credits of a statement, all or nothing. Returns how many lines had each outcome. */
    public Map<Outcome, Integer> importStatement(Principal p, byte[] ofx) {
        p.requireAdmin();
        List<OfxParser.Entry> entries = OfxParser.parse(ofx);
        return db.tx(c -> {
            Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);
            for (OfxParser.Entry e : entries) {
                if (!e.type().equals("CREDIT") || e.amount().signum() <= 0) continue;
                if (e.fitId().isEmpty()) throw AppException.invalid("A statement line has no FITID.");
                Outcome o = settle(c, PaymentSource.STATEMENT, "ofx:" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo());
                counts.merge(o, 1, Integer::sum);
            }
            audit.record(c, p.userId(), "statement_import", counts.toString(), clock.instant());
            return counts;
        });
    }

    /** A payment the bank reports on its webhook, signed with HMAC-SHA256 of the raw body. */
    public Outcome webhook(byte[] body, String signature) {
        if (bankSecret == null || bankSecret.isEmpty()) throw AppException.unavailable("The bank webhook is not configured.");
        if (signature == null || !MessageDigest.isEqual(signature.getBytes(StandardCharsets.UTF_8), sign(body).getBytes(StandardCharsets.UTF_8))) {
            throw new AppException(401, "bad_signature", "The signature does not match.");
        }
        JsonNode event;
        try {
            event = JSON.readTree(body);
        } catch (IOException e) {
            throw AppException.invalid("The event is not valid JSON.");
        }
        String eventId = field(event, "event_id");
        String ourNumber = field(event, "our_number");
        BigDecimal amount;
        LocalDate paidOn;
        try {
            amount = new BigDecimal(field(event, "amount"));
            paidOn = LocalDate.parse(field(event, "paid_on"));
        } catch (NumberFormatException | DateTimeParseException e) {
            throw AppException.invalid("amount and paid_on must be a decimal and a date.");
        }
        if (amount.signum() <= 0) throw AppException.invalid("amount must be positive.");
        return db.tx(c -> settle(c, PaymentSource.WEBHOOK, "hook:" + eventId, ourNumber, amount, paidOn, null));
    }

    /** A payment registered by the building's manager or an admin, for example one made at the office. */
    public Outcome manual(Principal p, long invoiceId, BigDecimal amount, LocalDate paidOn) {
        return db.tx(c -> {
            Invoice i = finance.invoice(c, invoiceId).orElseThrow(AppException::notFound);
            Outcome o = settle(c, PaymentSource.MANUAL, "manual:" + UUID.randomUUID(), i.ourNumber(), amount, paidOn, "by user " + p.userId());
            audit.record(c, p.userId(), "manual_payment", i.ourNumber() + " " + amount.toPlainString(), clock.instant());
            return o;
        });
    }

    String sign(byte[] body) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(bankSecret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            return "sha256=" + HexFormat.of().formatHex(mac.doFinal(body));
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    /**
     * Records the payment and settles its invoice when it covers the amount due on the payment's date. A payment whose bank id
     * was already recorded changes nothing.
     */
    private Outcome settle(Connection c, PaymentSource source, String externalId, String ourNumber, BigDecimal amount, LocalDate paidOn,
                           String memo) throws SQLException {
        Optional<Long> paymentId = finance.insertPayment(c, externalId, source, amount, paidOn, memo, clock.instant());
        if (paymentId.isEmpty()) return Outcome.DUPLICATE;
        Optional<Invoice> invoice = ourNumber == null ? Optional.empty() : finance.lockByOurNumber(c, ourNumber);
        if (invoice.isEmpty()) return Outcome.UNMATCHED;
        Invoice i = invoice.get();
        finance.linkPayment(c, paymentId.get(), i.id());
        if (i.status() != InvoiceStatus.OPEN) return Outcome.ALREADY_SETTLED;
        Building b = buildings.byId(c, i.buildingId()).orElseThrow();
        BigDecimal due = Charges.amountDue(i.amount(), i.dueDate(), b, paidOn);
        if (finance.paidTotal(c, i.id()).compareTo(due) < 0) return Outcome.PARTIAL;
        finance.markPaid(c, i.id(), paidOn);
        return Outcome.PAID;
    }

    static String ourNumberIn(String memo) {
        Matcher m = OUR_NUMBER.matcher(memo == null ? "" : memo);
        return m.find() ? m.group(1) : null;
    }

    private static String field(JsonNode event, String name) {
        JsonNode n = event.get(name);
        if (n == null || n.isNull() || n.asText().isBlank()) throw AppException.invalid(name + " is required.");
        return n.asText().trim();
    }
}
