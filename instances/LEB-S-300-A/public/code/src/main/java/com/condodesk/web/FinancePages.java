package com.condodesk.web;

import com.condodesk.billing.BillingService;
import com.condodesk.billing.BillingService.InvoiceView;
import com.condodesk.billing.BillingService.RunResult;
import com.condodesk.billing.PaymentService;
import com.condodesk.billing.PaymentService.Outcome;
import com.condodesk.docs.DocumentService;
import com.condodesk.domain.AppException;
import com.condodesk.domain.Model.Invoice;
import com.condodesk.domain.Model.InvoiceStatus;
import com.condodesk.domain.Model.Page;
import com.condodesk.domain.Model.Payment;
import com.condodesk.domain.Principal;
import com.condodesk.repo.FinanceRepository;
import com.condodesk.web.Server.Gate;

import java.time.YearMonth;
import java.time.format.DateTimeParseException;
import java.util.LinkedHashMap;
import java.util.Map;

import static com.condodesk.web.Html.esc;

/** Invoices, payment proofs, payments by hand, the billing run and the statement import. */
final class FinancePages {
    private final BillingService billing;
    private final PaymentService payments;
    private final DocumentService documents;

    FinancePages(BillingService billing, PaymentService payments, DocumentService documents) {
        this.billing = billing;
        this.payments = payments;
        this.documents = documents;
    }

    void register(Server s) {
        s.route("GET", "/invoices", Gate.BROWSER, this::list);
        s.route("GET", "/invoices/{id}", Gate.BROWSER, this::view);
        s.route("POST", "/invoices/{id}/proof", Gate.BROWSER, this::proof);
        s.route("POST", "/invoices/{id}/payments", Gate.BROWSER, this::manualPayment);
        s.route("GET", "/admin/billing", Gate.BROWSER, this::billingForm);
        s.route("POST", "/admin/billing", Gate.BROWSER, this::runBilling);
        s.route("GET", "/admin/statements", Gate.BROWSER, this::statementForm);
        s.route("POST", "/admin/statements", Gate.BROWSER, this::importStatement);
    }

    private Response list(Request req) {
        Principal p = req.principal();
        InvoiceStatus status = enumOf(InvoiceStatus.class, req.query("status"));
        YearMonth month = monthOf(req.query("month"));
        String sort = req.query("sort");
        boolean desc = "desc".equals(req.query("dir"));
        Long building = p.isAdmin() ? req.queryLong("building") : null;
        Page<Invoice> page = billing.list(p, building, status, month, sort, desc, req.page());
        StringBuilder b = new StringBuilder("<form method=\"get\" action=\"/invoices\">");
        if (building != null) b.append("<input type=\"hidden\" name=\"building\" value=\"").append(building).append("\">");
        b.append("<label>Status <select name=\"status\"><option value=\"\">Any</option>");
        for (InvoiceStatus s : InvoiceStatus.values()) {
            b.append("<option").append(s == status ? " selected" : "").append(">").append(s).append("</option>");
        }
        b.append("</select></label><label>Month <input name=\"month\" placeholder=\"YYYY-MM\" value=\"").append(esc(month))
                .append("\"></label><button>Filter</button></form>\n<table><tr>")
                .append(sortHeader("unit", "Unit")).append(sortHeader("month", "Month")).append(sortHeader("due", "Due"))
                .append(sortHeader("amount", "Amount")).append("<th>Status</th></tr>");
        for (Invoice i : page.items()) {
            b.append("<tr><td><a href=\"/invoices/").append(i.id()).append("\">").append(esc(i.unitLabel())).append("</a></td><td>").append(i.month())
                    .append("</td><td>").append(i.dueDate()).append("</td><td class=\"num\">").append(Html.money(i.amount())).append("</td><td>")
                    .append(i.status()).append("</td></tr>");
        }
        b.append("</table>");
        Map<String, String> filters = new LinkedHashMap<>();
        filters.put("building", building == null ? null : building.toString());
        filters.put("status", status == null ? null : status.name());
        filters.put("month", month == null ? null : month.toString());
        filters.put("sort", sort);
        filters.put("dir", desc ? "desc" : null);
        b.append(Html.pager("/invoices", filters, page)).append("<p>").append(page.total()).append(" invoices.</p>");
        return Response.html(Html.page(req, "Invoices", b.toString()));
    }

    private Response view(Request req) {
        Principal p = req.principal();
        InvoiceView v = billing.view(p, req.id());
        Invoice i = v.invoice();
        StringBuilder b = new StringBuilder("<dl><dt>Unit</dt><dd>").append(esc(i.unitLabel())).append(" · ").append(esc(v.building().name()))
                .append("</dd><dt>Month</dt><dd>").append(i.month()).append("</dd><dt>Due date</dt><dd>").append(i.dueDate())
                .append("</dd><dt>Amount</dt><dd>").append(Html.money(i.amount())).append("</dd><dt>Our number</dt><dd>").append(esc(i.ourNumber()))
                .append("</dd><dt>Status</dt><dd>").append(i.status()).append(i.paidOn() == null ? "" : " on " + i.paidOn()).append("</dd>");
        if (i.status() == InvoiceStatus.OPEN) {
            b.append("<dt>Due today</dt><dd><strong>").append(Html.money(v.dueToday())).append("</strong>")
                    .append(v.daysLate() > 0 ? " (" + v.daysLate() + " days late)" : "").append("</dd>");
        }
        b.append("</dl><h2>Payments</h2><table><tr><th>Date</th><th>Amount</th><th>Source</th></tr>");
        for (Payment pay : v.payments()) {
            b.append("<tr><td>").append(pay.paidOn()).append("</td><td class=\"num\">").append(Html.money(pay.amount())).append("</td><td>")
                    .append(pay.source()).append("</td></tr>");
        }
        b.append("</table>");
        if (i.status() == InvoiceStatus.OPEN) {
            b.append("<h2>Send a payment proof</h2><form method=\"post\" action=\"/invoices/").append(i.id())
                    .append("/proof\" enctype=\"multipart/form-data\">").append(Html.csrf(req))
                    .append("<input type=\"file\" name=\"file\" accept=\".pdf,.png,.jpg,.jpeg\" required><button>Send</button></form>");
            if (p.manages(i.buildingId())) {
                b.append("<h2>Register a payment</h2><form method=\"post\" action=\"/invoices/").append(i.id()).append("/payments\">").append(Html.csrf(req))
                        .append("<label>Amount <input name=\"amount\" required></label><label>Paid on <input type=\"date\" name=\"paid_on\" required></label>")
                        .append("<button>Register</button></form>");
            }
        }
        return Response.html(Html.page(req, "Invoice " + i.month() + " · " + i.unitLabel(), b.toString()));
    }

    private Response proof(Request req) {
        Multipart.Part f = req.file("file");
        documents.uploadProof(req.principal(), req.id(), f.fileName(), f.data());
        return Response.redirect(Html.flash("/invoices/" + req.id(), "Proof received. The office will check it."));
    }

    private Response manualPayment(Request req) {
        Outcome o = payments.manual(req.principal(), req.id(), req.formMoney("amount"), req.formDate("paid_on"));
        return Response.redirect(Html.flash("/invoices/" + req.id(), "Payment registered: " + o + "."));
    }

    private Response billingForm(Request req) {
        req.principal().requireAdmin();
        return Response.html(Html.page(req, "Monthly billing", "<form method=\"post\" action=\"/admin/billing\">" + Html.csrf(req)
                + "<label>Building id <input name=\"building\" required></label><label>Month <input name=\"month\" placeholder=\"YYYY-MM\" required></label>"
                + "<label>Budget <input name=\"budget\" required></label><button>Bill the month</button></form>"));
    }

    private Response runBilling(Request req) {
        long building = req.building();
        YearMonth month = monthOf(req.requireForm("month"));
        RunResult r = billing.run(req.principal(), building, month, req.formMoney("budget"));
        return Response.redirect(Html.flash("/invoices?building=" + building + "&month=" + month,
                r.invoices() + " invoices created, " + Html.money(r.total()) + " in total."));
    }

    private Response statementForm(Request req) {
        req.principal().requireAdmin();
        return Response.html(Html.page(req, "Bank statement", "<form method=\"post\" action=\"/admin/statements\" enctype=\"multipart/form-data\">"
                + Html.csrf(req) + "<input type=\"file\" name=\"statement\" accept=\".ofx,.xml\" required><button>Import</button></form>"));
    }

    private Response importStatement(Request req) {
        Map<Outcome, Integer> counts = payments.importStatement(req.principal(), req.file("statement").data());
        StringBuilder b = new StringBuilder("<table><tr><th>Outcome</th><th>Lines</th></tr>");
        for (Outcome o : Outcome.values()) {
            b.append("<tr><td>").append(o).append("</td><td class=\"num\" id=\"").append(o.name().toLowerCase()).append("\">")
                    .append(counts.getOrDefault(o, 0)).append("</td></tr>");
        }
        return Response.html(Html.page(req, "Statement imported", b.append("</table>").toString()));
    }

    private static String sortHeader(String key, String label) {
        return "<th><a href=\"?sort=" + key + "\">" + label + "</a></th>";
    }

    static YearMonth monthOf(String value) {
        if (value == null) return null;
        try {
            return YearMonth.parse(value);
        } catch (DateTimeParseException e) {
            throw AppException.invalid("The month must be written YYYY-MM.");
        }
    }

    static <E extends Enum<E>> E enumOf(Class<E> type, String value) {
        if (value == null) return null;
        try {
            return Enum.valueOf(type, value);
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("Unknown value: " + value);
        }
    }
}
