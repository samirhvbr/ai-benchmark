package com.condodesk.reports;

import com.condodesk.billing.FinanceService;
import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Building;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Principal;
import com.condodesk.repo.BuildingRepository;
import com.condodesk.repo.FinanceRepository;
import com.condodesk.repo.ReportRepository;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.sql.Connection;
import java.sql.SQLException;
import java.time.Clock;
import java.time.LocalDate;
import java.time.YearMonth;
import java.time.temporal.ChronoUnit;
import java.util.ArrayList;
import java.util.List;

/** The delinquency report and the dashboards. */
public final class ReportService {

    public record Delinquent(String unit, String owners, YearMonth month, LocalDate dueDate, BigDecimal amount, BigDecimal amountDue, long daysLate) {}

    public record Delinquency(Building building, LocalDate asOf, List<Delinquent> rows, BigDecimal totalDue) {}

    /** A building as the admin's dashboard lists it. */
    public record BuildingTotals(long id, String name, int units, int openInvoices) {}

    /** The delinquency CSV and the building's date it was taken on. */
    public record DelinquencyCsv(LocalDate asOf, String text) {}

    /** What the first page shows: a resident's open invoices, a manager's pending work, or the admin's buildings. */
    public record Dashboard(int openInvoices, BigDecimal openDue, int overdueInvoices, int pendingBookings, List<BuildingTotals> buildings) {}

    public static final List<String> CSV_HEADER = List.of("unit", "owners", "month", "due_date", "amount", "amount_due", "days_late");

    private final Db db;
    private final BuildingRepository buildings;
    private final FinanceRepository finance;
    private final ReportRepository reports;
    private final Clock clock;
    /** The admin's building totals, read once: buildings are few and change little. */
    private List<BuildingTotals> buildingTotals;

    public ReportService(Db db, BuildingRepository buildings, FinanceRepository finance, ReportRepository reports, Clock clock) {
        this.db = db;
        this.buildings = buildings;
        this.finance = finance;
        this.reports = reports;
        this.clock = clock;
    }

    /** The building's invoices past their due date and still open, with what each one costs today. */
    public Delinquency delinquency(Principal p, long buildingId) {
        p.requireManager(buildingId);
        return db.read(c -> {
            Building b = buildings.byId(c, buildingId).orElseThrow(AppException::notFound);
            LocalDate today = b.dateAt(clock.instant());
            List<Invoice> late = finance.openDueBefore(c, buildingId, today);
            List<Delinquent> rows = new ArrayList<>();
            BigDecimal total = BigDecimal.ZERO;
            for (Invoice i : late) {
                BigDecimal due = FinanceService.amountDue(i.amount(), i.dueDate(), b, today);
                rows.add(new Delinquent(i.unitLabel(), reports.ownersOf(c, i.unitId()), i.month(), i.dueDate(), i.amount(), due,
                        FinanceService.daysLate(i.dueDate(), today)));
                total = total.add(due);
            }
            return new Delinquency(b, today, rows, total);
        });
    }

    public DelinquencyCsv delinquencyCsv(Principal p, long buildingId) {
        Delinquency report = delinquency(p, buildingId);
        StringBuilder out = new StringBuilder(Csv.row(CSV_HEADER));
        for (Delinquent d : report.rows()) {
            out.append(Csv.row(List.of(d.unit(), d.owners(), d.month().toString(), d.dueDate().toString(), d.amount().toPlainString(),
                    d.amountDue().toPlainString(), String.valueOf(d.daysLate()))));
        }
        return new DelinquencyCsv(report.asOf(), out.toString());
    }

    public Dashboard dashboard(Principal p) {
        return db.read(c -> switch (p.role()) {
            case RESIDENT -> resident(c, p);
            case MANAGER -> manager(c, p.buildingId());
            case ADMIN -> new Dashboard(0, BigDecimal.ZERO, 0, 0, adminTotals(c));
        });
    }

    private List<BuildingTotals> adminTotals(Connection c) throws SQLException {
        if (buildingTotals == null) buildingTotals = reports.buildingTotals(c);
        return buildingTotals;
    }

    private Dashboard resident(Connection c, Principal p) throws SQLException {
        Building b = buildings.byId(c, p.buildingId()).orElseThrow();
        LocalDate today = b.dateAt(clock.instant());
        List<Invoice> open = finance.openOfUnits(c, p.unitIds());
        BigDecimal due = BigDecimal.ZERO;
        for (Invoice i : open) {
            long late = i.dueDate().isBefore(today) ? ChronoUnit.DAYS.between(i.dueDate(), today) : 0;
            BigDecimal owed = i.amount();
            if (late > 0) {
                owed = owed.add(i.amount().multiply(b.lateFeePct()).divide(BigDecimal.valueOf(100), 12, RoundingMode.HALF_EVEN))
                        .add(i.amount().multiply(b.monthlyInterestPct()).multiply(BigDecimal.valueOf(late))
                                .divide(BigDecimal.valueOf(3000), 12, RoundingMode.HALF_EVEN));
            }
            due = due.add(owed.setScale(2, RoundingMode.HALF_UP));
        }
        int overdue = (int) open.stream().filter(i -> i.dueDate().isBefore(today)).count();
        return new Dashboard(open.size(), due, overdue, 0, List.of());
    }

    private Dashboard manager(Connection c, long buildingId) throws SQLException {
        Building b = buildings.byId(c, buildingId).orElseThrow();
        LocalDate today = b.dateAt(clock.instant());
        int overdue = finance.openDueBefore(c, buildingId, today).size();
        return new Dashboard(0, BigDecimal.ZERO, overdue, reports.pendingBookings(c, buildingId, clock.instant()), List.of());
    }
}
