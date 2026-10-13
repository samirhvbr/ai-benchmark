package com.condodesk.web;

import com.condodesk.domain.AppException;
import com.condodesk.reports.ReportService;
import com.condodesk.reports.ReportService.YearCollection;
import com.condodesk.reports.ReportService.Delinquency;
import com.condodesk.reports.ReportService.DelinquencyCsv;
import com.condodesk.reports.ReportService.Delinquent;
import com.condodesk.reports.ReportService.MonthCollection;
import com.condodesk.web.Server.Gate;

import java.nio.charset.StandardCharsets;

import static com.condodesk.web.Html.esc;

/** The delinquency report (page and CSV) and the collection of a year. */
final class ReportPages {
    private final ReportService reports;

    ReportPages(ReportService reports) {
        this.reports = reports;
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
        Long y = req.queryLong("year");
        if (y != null && (y < 2000 || y > 2100)) throw AppException.invalid("year must be between 2000 and 2100.");
        YearCollection collection = reports.collection(req.principal(), building, y == null ? null : y.intValue());
        StringBuilder b = new StringBuilder("<table><tr><th>Month</th><th>Billed</th><th>Settled</th><th>Received</th><th>Settled share</th></tr>");
        for (MonthCollection m : collection.months()) {
            b.append("<tr><td>").append(m.month()).append("</td><td class=\"num\">").append(Html.money(m.billed())).append("</td><td class=\"num\">")
                    .append(Html.money(m.settled())).append("</td><td class=\"num\">").append(Html.money(m.received())).append("</td><td class=\"num\">")
                    .append(m.rate() == null ? "—" : m.rate().toPlainString() + "%").append("</td></tr>");
        }
        return Response.html(Html.page(req, "Collection " + collection.year(), b.append("</table>").toString()));
    }
}
