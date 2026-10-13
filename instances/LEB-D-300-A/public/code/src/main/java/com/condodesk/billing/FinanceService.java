package com.condodesk.billing;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.Payment;
import com.condodesk.domain.Model.PaymentSource;
import com.condodesk.domain.Model.Role;
import com.condodesk.domain.Model.Unit;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.FinanceRepository;
import com.condodesk.repo.FinanceRepository.InvoiceFilter;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.io.IOException;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.SQLException;
import java.time.Clock;
import java.time.LocalDate;
import java.time.Period;
import java.time.YearMonth;
import java.time.format.DateTimeParseException;
import java.util.Comparator;
import java.util.EnumMap;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.UUID;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * The money side of CondoDesk: the monthly billing run and its invoices, the payments from bank statements, from the bank's
 * webhook and by hand, how they settle invoices, and the rules that price them.
 */
public final class FinanceService {
    public static final int PAGE_SIZE = 20;
    private static final BigDecimal HUNDRED = BigDecimal.valueOf(100);
    /** A month of interest is charged as thirty days. */
    private static final BigDecimal INTEREST_DAYS = BigDecimal.valueOf(30);
    private static final Pattern OUR_NUMBER = Pattern.compile("\\b(\\d{6}-\\d{4}-\\d{6})\\b");
    private static final ObjectMapper JSON = new ObjectMapper();

    public record RunResult(long runId, int invoices, BigDecimal total) {}

    /** An invoice with what it costs today and the payments made against it. */
    public record InvoiceView(Invoice invoice, Building building, BigDecimal dueToday, long daysLate, List<Payment> payments) {}

    public enum Outcome { PAID, PARTIAL, UNMATCHED, DUPLICATE, ALREADY_SETTLED }

    private final Db db;
    private final BuildingRepository buildings;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;
    private final String bankSecret;

    public FinanceService(Db db, BuildingRepository buildings, FinanceRepository finance, AuditLog audit, Clock clock, String bankSecret) {
        this.db = db;
        this.buildings = buildings;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
        this.bankSecret = bankSecret;
    }

    /** Bills a month of a building: one invoice per unit, due on the building's due day of the next month. */
    public RunResult run(Principal p, long buildingId, YearMonth month, BigDecimal budget) {
        p.requireAdmin();
        return db.tx(c -> {
            Building b = buildings.byId(c, buildingId).orElseThrow(AppException::notFound);
            List<Unit> units = buildings.units(c, buildingId);
            if (units.isEmpty()) throw AppException.invalid("The building has no units.");
            long runId;
            try {
                runId = finance.insertRun(c, buildingId, month, budget, p.userId(), clock.instant());
            } catch (SQLException e) {
                if ("23505".equals(e.getSQLState())) throw AppException.conflict(month + " is already billed for " + b.name() + ".");
                throw e;
            }
            LocalDate due = month.plusMonths(1).atDay(b.dueDay());
            Map<Long, BigDecimal> shares = split(budget, units);
            for (Unit u : units) {
                finance.insertInvoice(c, runId, u.id(), month, due, shares.get(u.id()), ourNumber(month, buildingId, u.id()));
            }
            audit.record(c, p.userId(), "billing_run", b.name() + " " + month + " budget " + budget.toPlainString(), clock.instant());
            return new RunResult(runId, units.size(), budget);
        });
    }

    public Page<Invoice> list(Principal p, Long buildingId, InvoiceStatus status, YearMonth month, String sort, boolean descending, int page) {
        InvoiceFilter f;
        if (p.isAdmin()) {
            f = new InvoiceFilter(buildingId, null, status, month, sort, descending);
        } else if (p.role() == Role.MANAGER) {
            f = new InvoiceFilter(p.buildingId(), null, status, month, sort, descending);
        } else {
            f = new InvoiceFilter(p.buildingId(), p.unitIds(), status, month, sort, descending);
        }
        return db.read(c -> finance.search(c, f, page, PAGE_SIZE));
    }

    public InvoiceView view(Principal p, long invoiceId) {
        return db.read(c -> {
            Invoice i = finance.invoice(c, invoiceId).orElseThrow(AppException::notFound);
            if (!p.canSeeUnit(i.buildingId(), i.unitId())) throw AppException.notFound();
            Building b = buildings.byId(c, i.buildingId()).orElseThrow();
            LocalDate today = b.dateAt(clock.instant());
            boolean open = i.status() == InvoiceStatus.OPEN;
            BigDecimal due = open ? amountDue(i.amount(), i.dueDate(), b, today) : BigDecimal.ZERO;
            return new InvoiceView(i, b, due, open ? daysLate(i.dueDate(), today) : 0, finance.payments(c, i.id()));
        });
    }

    /** The bank's reference of an invoice: billing month, building and unit. */
    static String ourNumber(YearMonth month, long buildingId, long unitId) {
        return String.format("%04d%02d-%04d-%06d", month.getYear(), month.getMonthValue(), buildingId, unitId);
    }

    /** Imports the credits of a statement. Returns how many lines had each outcome. */
    public Map<Outcome, Integer> importStatement(Principal p, byte[] ofx) {
        p.requireAdmin();
        List<OfxParser.Entry> entries = OfxParser.parse(ofx);
        Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);
        for (OfxParser.Entry e : entries) {
            if (!e.type().equals("CREDIT") || e.amount().signum() <= 0) continue;
            if (e.fitId().isEmpty()) throw AppException.invalid("A statement line has no FITID.");
            Outcome o = db.tx(c -> settle(c, PaymentSource.STATEMENT, "ofx:" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo()));
            counts.merge(o, 1, Integer::sum);
        }
        db.tx(c -> {
            audit.record(c, p.userId(), "statement_import", counts.toString(), clock.instant());
            return null;
        });
        return counts;
    }

    /** A payment the bank reports on its webhook, signed with HMAC-SHA256 of the raw body. Events are handled one at a time. */
    public synchronized Outcome webhook(byte[] body, String signature) {
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
            p.requireManager(i.buildingId());
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
        BigDecimal due = amountDue(i.amount(), i.dueDate(), b, paidOn);
        if (!finance.paidTotal(c, i.id()).equals(due)) return Outcome.PARTIAL;
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

    /**
     * Splits the budget by ideal fraction, in cents. Each unit gets the floor of its exact share; the cents left over go one each
     * to the units with the largest remainders (ties by label), so the shares add up to the budget exactly.
     */
    public static Map<Long, BigDecimal> split(BigDecimal budget, List<Unit> units) {
        BigDecimal cents = budget.movePointRight(2);
        BigDecimal fractions = units.stream().map(Unit::idealFraction).reduce(BigDecimal.ZERO, BigDecimal::add);
        record Share(Unit unit, long floor, BigDecimal remainder) {}
        List<Share> shares = units.stream().map(u -> {
            BigDecimal exact = cents.multiply(u.idealFraction()).divide(fractions, 12, RoundingMode.HALF_EVEN);
            BigDecimal floor = exact.setScale(0, RoundingMode.FLOOR);
            return new Share(u, floor.longValueExact(), exact.subtract(floor));
        }).toList();
        long left = cents.longValueExact() - shares.stream().mapToLong(Share::floor).sum();
        List<Share> byRemainder = shares.stream()
                .sorted(Comparator.comparing(Share::remainder).reversed().thenComparing(s -> s.unit().label())).toList();
        Map<Long, BigDecimal> out = new LinkedHashMap<>();
        for (Share s : shares) out.put(s.unit().id(), BigDecimal.valueOf(s.floor()));
        for (int i = 0; i < left; i++) out.merge(byRemainder.get(i).unit().id(), BigDecimal.ONE, BigDecimal::add);
        out.replaceAll((id, c) -> c.movePointLeft(2).setScale(2, RoundingMode.UNNECESSARY));
        return out;
    }

    /** The amount due on a date: the invoice amount, plus the late fee and pro rata interest once the due date has passed. */
    public static BigDecimal amountDue(BigDecimal amount, LocalDate dueDate, Building building, LocalDate on) {
        long days = daysLate(dueDate, on);
        if (days == 0) return amount.setScale(2, RoundingMode.HALF_UP);
        BigDecimal fee = amount.multiply(building.lateFeePct()).divide(HUNDRED, 12, RoundingMode.HALF_EVEN);
        BigDecimal interest = amount.multiply(building.monthlyInterestPct()).multiply(BigDecimal.valueOf(days))
                .divide(HUNDRED.multiply(INTEREST_DAYS), 12, RoundingMode.HALF_EVEN);
        return amount.add(fee).add(interest).setScale(2, RoundingMode.HALF_UP);
    }

    public static long daysLate(LocalDate dueDate, LocalDate on) {
        return on.isAfter(dueDate) ? Period.between(dueDate, on).getDays() : 0;
    }
}
