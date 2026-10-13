"""LEB-D-300-A — the CLN rows of ../CATALOG.md §5 (Template R)."""

from paths import SRC

LIST_PAGE = r'''    private Response list(Request req) {
        Principal p = req.principal();
        InvoiceStatus status = enumOf(InvoiceStatus.class, req.query("status"));
        YearMonth month = monthOf(req.query("month"));
        String sort = req.query("sort");
        if (sort != null && !FinanceRepository.SORTS.containsKey(sort)) throw AppException.invalid("Unknown sort: " + sort);
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
'''

LIST_PAGE_GIANT = r'''    private Response list(Request req) {
        Principal p = req.principal();
        InvoiceStatus status = null;
        String statusParam = req.query("status");
        if (statusParam != null) {
            try {
                status = InvoiceStatus.valueOf(statusParam);
            } catch (IllegalArgumentException e) {
                throw AppException.invalid("Unknown value: " + statusParam);
            }
        }
        YearMonth month = null;
        String monthParam = req.query("month");
        if (monthParam != null) {
            try {
                month = YearMonth.parse(monthParam);
            } catch (DateTimeParseException e) {
                throw AppException.invalid("The month must be written YYYY-MM.");
            }
        }
        String sort = req.query("sort");
        if (sort != null && !FinanceRepository.SORTS.containsKey(sort)) throw AppException.invalid("Unknown sort: " + sort);
        boolean desc = "desc".equals(req.query("dir"));
        Long building = null;
        if (p.isAdmin()) {
            building = req.queryLong("building");
        }
        Page<Invoice> page = billing.list(p, building, status, month, sort, desc, req.page());
        StringBuilder b = new StringBuilder();
        b.append("<form method=\"get\" action=\"/invoices\">");
        if (building != null) {
            b.append("<input type=\"hidden\" name=\"building\" value=\"").append(building).append("\">");
        }
        b.append("<label>Status <select name=\"status\"><option value=\"\">Any</option>");
        for (InvoiceStatus s : InvoiceStatus.values()) {
            b.append("<option");
            if (s == status) {
                b.append(" selected");
            }
            b.append(">").append(s).append("</option>");
        }
        b.append("</select></label>");
        b.append("<label>Month <input name=\"month\" placeholder=\"YYYY-MM\" value=\"").append(esc(month)).append("\"></label>");
        b.append("<button>Filter</button></form>\n");
        b.append("<table><tr>");
        b.append("<th><a href=\"?sort=unit\">Unit</a></th>");
        b.append("<th><a href=\"?sort=month\">Month</a></th>");
        b.append("<th><a href=\"?sort=due\">Due</a></th>");
        b.append("<th><a href=\"?sort=amount\">Amount</a></th>");
        b.append("<th>Status</th></tr>");
        for (Invoice i : page.items()) {
            b.append("<tr>");
            b.append("<td><a href=\"/invoices/").append(i.id()).append("\">").append(esc(i.unitLabel())).append("</a></td>");
            b.append("<td>").append(i.month()).append("</td>");
            b.append("<td>").append(i.dueDate()).append("</td>");
            b.append("<td class=\"num\">").append(Html.money(i.amount())).append("</td>");
            b.append("<td>").append(i.status()).append("</td>");
            b.append("</tr>");
        }
        b.append("</table>");
        Map<String, String> filters = new LinkedHashMap<>();
        if (building != null) {
            filters.put("building", building.toString());
        }
        if (status != null) {
            filters.put("status", status.name());
        }
        if (month != null) {
            filters.put("month", month.toString());
        }
        if (sort != null) {
            filters.put("sort", sort);
        }
        if (desc) {
            filters.put("dir", "desc");
        }
        b.append(Html.pager("/invoices", filters, page));
        b.append("<p>").append(page.total()).append(" invoices.</p>");
        return Response.html(Html.page(req, "Invoices", b.toString()));
    }
'''

CANCEL = r'''    public void cancel(Principal p, long bookingId) {
        db.tx(c -> {
            Booking bk = bookings.lock(c, bookingId).filter(x -> p.canSeeUnit(x.buildingId(), x.unitId())).orElseThrow(AppException::notFound);
            Instant now = clock.instant();
            if (bk.status() == BookingStatus.CANCELLED) throw AppException.conflict("The booking is already cancelled.");
            if (!bk.startsAt().isAfter(now)) throw AppException.conflict("The booking has already started.");
            if (!p.manages(bk.buildingId()) && now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                throw AppException.conflict("Bookings can be cancelled up to 48 hours before they start.");
            }
            bookings.setStatus(c, bk.id(), BookingStatus.CANCELLED);
            audit.record(c, p.userId(), "booking_cancelled", String.valueOf(bk.id()), now);
            return null;
        });
    }
'''

CANCEL_NESTED = r'''    public void cancel(Principal p, long bookingId) {
        db.tx(c -> {
            Optional<Booking> found = bookings.lock(c, bookingId);
            if (found.isPresent() && p.canSeeUnit(found.get().buildingId(), found.get().unitId())) {
                Booking bk = found.get();
                Instant now = clock.instant();
                if (bk.status() != BookingStatus.CANCELLED) {
                    if (bk.startsAt().isAfter(now)) {
                        boolean allowed = true;
                        if (!p.manages(bk.buildingId())) {
                            if (now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                                allowed = false;
                            }
                        }
                        if (allowed) {
                            bookings.setStatus(c, bk.id(), BookingStatus.CANCELLED);
                            audit.record(c, p.userId(), "booking_cancelled", String.valueOf(bk.id()), now);
                            return null;
                        } else {
                            throw AppException.conflict("Bookings can be cancelled up to 48 hours before they start.");
                        }
                    } else {
                        throw AppException.conflict("The booking has already started.");
                    }
                } else {
                    throw AppException.conflict("The booking is already cancelled.");
                }
            } else {
                throw AppException.notFound();
            }
        });
    }
'''

ENUM_OF = r'''
    private static <E extends Enum<E>> E enumOf(Class<E> type, String value) {
        if (value == null) return null;
        try {
            return Enum.valueOf(type, value);
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("Unknown value: " + value);
        }
    }
'''

FLAWS = [
    # One method of about 75 non-blank lines: filters, sort, table, filter map and pager inlined.
    ("CLN-001", [
        (SRC + "web/FinancePages.java", LIST_PAGE, LIST_PAGE_GIANT),
        (SRC + "web/FinancePages.java",
         "    private static String sortHeader(String key, String label) {\n"
         "        return \"<th><a href=\\\"?sort=\" + key + \"\\\">\" + label + \"</a></th>\";\n"
         "    }\n\n",
         ""),
    ]),
    # Five nested levels instead of guard clauses.
    ("CLN-007", [
        (SRC + "booking/BookingService.java",
         "import java.util.List;\n",
         "import java.util.List;\nimport java.util.Optional;\n"),
        (SRC + "booking/BookingService.java", CANCEL, CANCEL_NESTED),
    ]),
    # FinancePages.enumOf copied, with the same body, into MobileApi and ContentPages.
    ("CLN-002", [
        (SRC + "web/MobileApi.java",
         "        InvoiceStatus status = FinancePages.enumOf(InvoiceStatus.class, req.query(\"status\"));",
         "        InvoiceStatus status = enumOf(InvoiceStatus.class, req.query(\"status\"));"),
        (SRC + "web/MobileApi.java",
         "    private static String text(JsonNode body, String field) {",
         ENUM_OF.lstrip("\n") + "\n    private static String text(JsonNode body, String field) {"),
        (SRC + "web/ContentPages.java",
         "import com.condodesk.docs.DocumentService.Download;\n",
         "import com.condodesk.docs.DocumentService.Download;\nimport com.condodesk.domain.AppException;\n"),
        (SRC + "web/ContentPages.java",
         "        documents.upload(req.principal(), building, FinancePages.enumOf(DocumentKind.class, req.requireForm(\"kind\")), f.fileName(), f.data());",
         "        documents.upload(req.principal(), building, enumOf(DocumentKind.class, req.requireForm(\"kind\")), f.fileName(), f.data());"),
        (SRC + "web/ContentPages.java",
         "        documents.fetch(req.principal(), building, FinancePages.enumOf(DocumentKind.class, req.requireForm(\"kind\")), req.requireForm(\"url\"));",
         "        documents.fetch(req.principal(), building, enumOf(DocumentKind.class, req.requireForm(\"kind\")), req.requireForm(\"url\"));"),
        (SRC + "web/ContentPages.java",
         "    private static String documentsPath(Request req, long building) {\n"
         "        return \"/documents\" + (req.principal().isAdmin() ? \"?building=\" + building : \"\");\n"
         "    }\n",
         "    private static String documentsPath(Request req, long building) {\n"
         "        return \"/documents\" + (req.principal().isAdmin() ? \"?building=\" + building : \"\");\n"
         "    }\n" + ENUM_OF),
    ]),
    # A block of commented-out code: an old handler that deactivated an account.
    ("CLN-005", [
        (SRC + "web/AdminPages.java",
         "        s.route(\"POST\", \"/admin/users\", Gate.BROWSER, this::create);\n",
         "        s.route(\"POST\", \"/admin/users\", Gate.BROWSER, this::create);\n"
         "        // s.route(\"POST\", \"/admin/users/{id}/deactivate\", Gate.BROWSER, this::deactivate);\n"),
        (SRC + "web/AdminPages.java",
         "    private static Long optionalLong(Request req, String name) {",
         "    // Deactivation moved to the nightly import of the owners' associations; kept here in case the office wants the button back.\n"
         "    // private Response deactivate(Request req) {\n"
         "    //     long id = req.id();\n"
         "    //     auth.deactivate(req.principal(), id);\n"
         "    //     return Response.redirect(Html.flash(\"/admin/users\", \"Account \" + id + \" deactivated.\"));\n"
         "    // }\n\n"
         "    private static Long optionalLong(Request req, String name) {"),
    ]),
]
