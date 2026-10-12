package com.condodesk.repo;

import com.condodesk.db.Db;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.Payment;
import com.condodesk.domain.Model.PaymentSource;

import java.math.BigDecimal;
import java.sql.Connection;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.time.LocalDate;
import java.time.YearMonth;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

/** Billing runs, invoices and payments. */
public final class FinanceRepository {
    private static final String INVOICE = "select i.id, i.unit_id, u.label as unit_label, u.building_id, i.month, i.due_date, i.amount, "
            + "i.our_number, i.status, i.paid_on from invoice i join unit u on u.id = i.unit_id";

    /** The columns an invoice list may be sorted by, and the SQL each one stands for. */
    public static final Map<String, String> SORTS = Map.of(
            "month", "i.month", "due", "i.due_date", "amount", "i.amount", "unit", "u.label");

    /**
     * What an invoice list shows: a building, or the given units (a resident's), and optional filters.
     * {@code sort} is a key of {@link #SORTS}.
     */
    public record InvoiceFilter(Long buildingId, Set<Long> unitIds, InvoiceStatus status, YearMonth month, String sort, boolean descending) {}

    public long insertRun(Connection c, long buildingId, YearMonth month, BigDecimal budget, long userId, Instant now) throws SQLException {
        return Db.insert(c, "insert into billing_run (building_id, month, budget, created_by, created_at) values (?, ?, ?, ?, ?)",
                buildingId, month.toString(), budget, userId, now);
    }

    public void insertInvoice(Connection c, long runId, long unitId, YearMonth month, LocalDate due, BigDecimal amount, String ourNumber)
            throws SQLException {
        Db.update(c, "insert into invoice (run_id, unit_id, month, due_date, amount, our_number, status) values (?, ?, ?, ?, ?, ?, 'OPEN')",
                runId, unitId, month.toString(), due, amount, ourNumber);
    }

    public Optional<Invoice> invoice(Connection c, long id) throws SQLException {
        return Db.one(c, INVOICE + " where i.id = ?", FinanceRepository::invoice, id);
    }

    /** The invoice of an our-number, locked until the transaction ends. */
    public Optional<Invoice> lockByOurNumber(Connection c, String ourNumber) throws SQLException {
        return Db.one(c, INVOICE + " where i.our_number = ? for update of i", FinanceRepository::invoice, ourNumber);
    }

    public Page<Invoice> search(Connection c, InvoiceFilter f, int page, int size) throws SQLException {
        StringBuilder where = new StringBuilder(" where true");
        List<Object> args = new ArrayList<>();
        if (f.buildingId() != null) {
            where.append(" and u.building_id = ?");
            args.add(f.buildingId());
        }
        if (f.unitIds() != null) {
            where.append(" and i.unit_id = any(?)");
            args.add(Db.ids(f.unitIds()));
        }
        if (f.status() != null) {
            where.append(" and i.status = ?");
            args.add(f.status());
        }
        if (f.month() != null) {
            where.append(" and i.month = ?");
            args.add(f.month().toString());
        }
        long total = Db.one(c, "select count(*) as n from invoice i join unit u on u.id = i.unit_id" + where,
                rs -> rs.getLong("n"), args.toArray()).orElse(0L);
        String order = SORTS.getOrDefault(f.sort(), "i.month") + (f.descending() ? " desc" : " asc") + ", u.label, i.id";
        args.add(size);
        args.add((long) (page - 1) * size);
        List<Invoice> items = Db.list(c, INVOICE + where + " order by " + order + " limit ? offset ?", FinanceRepository::invoice, args.toArray());
        return new Page<>(items, page, size, total);
    }

    /** Records a payment unless the bank's id is already known. Returns its id, or empty for a duplicate. */
    public Optional<Long> insertPayment(Connection c, String externalId, PaymentSource source, BigDecimal amount, LocalDate paidOn,
                                        String memo, Instant now) throws SQLException {
        return Db.one(c, "insert into payment (external_id, source, amount, paid_on, memo, created_at) values (?, ?, ?, ?, ?, ?) "
                        + "on conflict (external_id) do nothing returning id",
                rs -> rs.getLong("id"), externalId, source, amount, paidOn, memo, now);
    }

    public void linkPayment(Connection c, long paymentId, long invoiceId) throws SQLException {
        Db.update(c, "update payment set invoice_id = ? where id = ?", invoiceId, paymentId);
    }

    public BigDecimal paidTotal(Connection c, long invoiceId) throws SQLException {
        return Db.one(c, "select coalesce(sum(amount), 0) as total from payment where invoice_id = ?",
                rs -> rs.getBigDecimal("total"), invoiceId).orElse(BigDecimal.ZERO);
    }

    public void markPaid(Connection c, long invoiceId, LocalDate paidOn) throws SQLException {
        Db.update(c, "update invoice set status = 'PAID', paid_on = ? where id = ?", paidOn, invoiceId);
    }

    public List<Payment> payments(Connection c, long invoiceId) throws SQLException {
        return Db.list(c, "select id, invoice_id, external_id, source, amount, paid_on, memo from payment where invoice_id = ? order by paid_on, id",
                FinanceRepository::payment, invoiceId);
    }

    /** Payments that matched no invoice, newest first. */
    public List<Payment> unmatched(Connection c, int limit) throws SQLException {
        return Db.list(c, "select id, invoice_id, external_id, source, amount, paid_on, memo from payment where invoice_id is null "
                + "order by paid_on desc, id desc limit ?", FinanceRepository::payment, limit);
    }

    /** Whether the unit has an open invoice that was due before the given date. */
    public boolean hasOpenDueBefore(Connection c, long unitId, LocalDate date) throws SQLException {
        return Db.one(c, "select exists (select 1 from invoice where unit_id = ? and status = 'OPEN' and due_date < ?) as late",
                rs -> rs.getBoolean("late"), unitId, date).orElse(false);
    }

    /** The open invoices of the units, by due date. */
    public List<Invoice> openOfUnits(Connection c, Set<Long> unitIds) throws SQLException {
        return Db.list(c, INVOICE + " where i.unit_id = any(?) and i.status = 'OPEN' order by i.due_date, u.label",
                FinanceRepository::invoice, Db.ids(unitIds));
    }

    /** The open invoices of a building that were due before the date, by unit and month. */
    public List<Invoice> openDueBefore(Connection c, long buildingId, LocalDate date) throws SQLException {
        return Db.list(c, INVOICE + " where u.building_id = ? and i.status = 'OPEN' and i.due_date < ? order by u.label, i.month",
                FinanceRepository::invoice, buildingId, date);
    }

    private static Invoice invoice(ResultSet rs) throws SQLException {
        return new Invoice(rs.getLong("id"), rs.getLong("unit_id"), rs.getString("unit_label"), rs.getLong("building_id"),
                YearMonth.parse(rs.getString("month")), Db.date(rs, "due_date"), rs.getBigDecimal("amount"), rs.getString("our_number"),
                InvoiceStatus.valueOf(rs.getString("status")), Db.date(rs, "paid_on"));
    }

    private static Payment payment(ResultSet rs) throws SQLException {
        return new Payment(rs.getLong("id"), Db.nullableLong(rs, "invoice_id"), rs.getString("external_id"),
                PaymentSource.valueOf(rs.getString("source")), rs.getBigDecimal("amount"), Db.date(rs, "paid_on"), rs.getString("memo"));
    }
}
