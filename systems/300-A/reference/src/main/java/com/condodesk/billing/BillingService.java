package com.condodesk.billing;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.Payment;
import com.condodesk.domain.Model.Role;
import com.condodesk.domain.Model.Unit;
import com.condodesk.domain.Principal;
import com.condodesk.repo.AuditLog;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.FinanceRepository;
import com.condodesk.repo.FinanceRepository.InvoiceFilter;

import java.math.BigDecimal;
import java.sql.SQLException;
import java.time.Clock;
import java.time.LocalDate;
import java.time.YearMonth;
import java.util.List;
import java.util.Map;

/** The monthly billing run and the invoices it creates. */
public final class BillingService {
    public static final int PAGE_SIZE = 20;

    public record RunResult(long runId, int invoices, BigDecimal total) {}

    /** An invoice with what it costs today and the payments made against it. */
    public record InvoiceView(Invoice invoice, Building building, BigDecimal dueToday, long daysLate, List<Payment> payments) {}

    private final Db db;
    private final BuildingRepository buildings;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;

    public BillingService(Db db, BuildingRepository buildings, FinanceRepository finance, AuditLog audit, Clock clock) {
        this.db = db;
        this.buildings = buildings;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
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
            Map<Long, BigDecimal> shares = Charges.split(budget, units);
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
            BigDecimal due = open ? Charges.amountDue(i.amount(), i.dueDate(), b, today) : BigDecimal.ZERO;
            return new InvoiceView(i, b, due, open ? Charges.daysLate(i.dueDate(), today) : 0, finance.payments(c, i.id()));
        });
    }

    /** The bank's reference of an invoice: billing month, building and unit. */
    static String ourNumber(YearMonth month, long buildingId, long unitId) {
        return String.format("%04d%02d-%04d-%06d", month.getYear(), month.getMonthValue(), buildingId, unitId);
    }
}
