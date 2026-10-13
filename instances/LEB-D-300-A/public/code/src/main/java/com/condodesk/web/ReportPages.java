package com.condodesk.web;

import com.condodesk.db.Db;
import com.condodesk.domain.AppException;
import com.condodesk.reports.ReportService;
import com.condodesk.reports.ReportService.Delinquency;
import com.condodesk.reports.ReportService.DelinquencyCsv;
import com.condodesk.reports.ReportService.Delinquent;
import com.condodesk.web.Server.Gate;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.LocalDate;
import java.time.ZoneId;

import static com.condodesk.web.Html.esc;

/** The delinquency report (page and CSV) and the collection of a year. */
final class ReportPages {
    private final ReportService reports;
    private final Db db;
    private final Clock clock;

    ReportPages(ReportService reports, Db db, Clock clock) {
        this.reports = reports;
        this.db = db;
        this.clock = clock;
    }

    void register(Server s) {
        s.route("GET", "/reports/delinquency", Gate.BROWSER, this::delinquency);
        s.route("GET", "/reports/delinquency.csv", Gate.BROWSER, this::delinquencyCsv);
        s.route("GET", "/reports/collection", Gate.BROWSER, this::collection);
    }

    private Response delinquency(Request req) {
        long building = req.building();
        Delinquency d = reports.delinquency(req.principal(), building);
        String q = req.principal().isAdmin() ? "?building=" + building : "";
        StringBuilder b = new StringBuilder("<p>").append(esc(d.building().name())).append(", as of ").append(d.asOf())
                .append(". <a href=\"/reports/delinquency.csv").append(q).append("\">Download CSV</a> · <a href=\"/reports/collection").append(q)
                .append("\">Collection</a></p><table><tr><th>Unit</th><th>Owners</th><th>Month</th><th>Due</th><th>Amount</th><th>Due today</th><th>Days late</th></tr>");
        for (Delinquent r : d.rows()) {
            b.append("<tr><td>").append(esc(r.unit())).append("</td><td>").append(esc(r.owners())).append("</td><td>").append(r.month())
                    .append("</td><td>").append(r.dueDate()).append("</td><td class=\"num\">").append(Html.money(r.amount()))
                    .append("</td><td class=\"num\">").append(Html.money(r.amountDue())).append("</td><td class=\"num\">").append(r.daysLate()).append("</td></tr>");
        }
        b.append("</table><p>Total due today: <strong id=\"total-due\">").append(Html.money(d.totalDue())).append("</strong></p>");
        return Response.html(Html.page(req, "Delinquency", b.toString()));
    }

    private Response delinquencyCsv(Request req) {
        long building = req.building();
        DelinquencyCsv csv = reports.delinquencyCsv(req.principal(), building);
        return Response.download("text/csv; charset=utf-8", "delinquency-" + building + "-" + csv.asOf() + ".csv",
                csv.text().getBytes(StandardCharsets.UTF_8));
    }

    private Response collection(Request req) {
        long building = req.building();
        req.principal().requireManager(building);
        Long y = req.queryLong("year");
        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid("year must be between 2000 and 2100.");
        StringBuilder b = new StringBuilder("<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>");
        int year = db.read(c -> {
            String zone = Db.one(c, "select time_zone from building where id = ?", rs -> rs.getString("time_zone"), building)
                    .orElseThrow(AppException::notFound);
            int shown = y != null ? y.intValue() : LocalDate.ofInstant(clock.instant(), ZoneId.of(zone)).getYear();
            Db.list(c, "select i.month, sum(i.amount) as billed, sum(case when i.status = 'PAID' then i.amount else 0 end) as settled, "
                            + "coalesce(sum(r.received), 0) as received from invoice i join unit u on u.id = i.unit_id "
                            + "left join (select invoice_id, sum(amount) as received from payment group by invoice_id) r on r.invoice_id = i.id "
                            + "where u.building_id = ? and i.month like ? group by i.month order by i.month",
                    rs -> {
                        BigDecimal billed = rs.getBigDecimal("billed");
                        BigDecimal settled = rs.getBigDecimal("settled");
                        String share = billed.signum() == 0 ? "—"
                                : settled.multiply(BigDecimal.valueOf(100)).divide(billed, 1, RoundingMode.HALF_UP).toPlainString() + "%";
                        b.append("<tr><td>").append(rs.getString("month")).append("</td><td class=\"num\">").append(Html.money(billed))
                                .append("</td><td class=\"num\">").append(Html.money(settled)).append("</td><td class=\"num\">")
                                .append(Html.money(rs.getBigDecimal("received"))).append("</td><td class=\"num\">").append(share).append("</td></tr>");
                        return share;
                    },
                    building, String.format("%04d-%%", shown));
            return shown;
        });
        return Response.html(Html.page(req, "Collection " + year, b.append("</table>").toString()));
    }
}
