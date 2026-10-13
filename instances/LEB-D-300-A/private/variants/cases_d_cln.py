"""CLN rows: clean-ups done another way are accepted; cosmetic ones are not."""


def method_replaced(start, next_start, new):
    """An edit that replaces a whole method: from the line `start` up to the line `next_start`, which stays."""
    def edit(text):
        i, j = text.index(start), text.index(next_start)
        return text[:i] + new + "\n\n" + text[j:]
    return edit


def lines_joined(start, next_start, by=3):
    """An edit that joins every `by` lines of a method into one: fewer lines, the same statements."""
    def edit(text):
        i, j = text.index(start), text.index(next_start)
        lines = text[i:j].rstrip("\n").split("\n")
        joined = [" ".join(l.strip() for l in lines[k:k + by]) for k in range(0, len(lines), by)]
        return text[:i] + "\n".join("    " + l for l in joined) + "\n\n" + text[j:]
    return edit


LIST_STEPS = '''    private Response list(Request req) {
        Principal p = req.principal();
        InvoiceStatus status = statusOf(req);
        YearMonth month = monthOf(req);
        String sort = req.query("sort");
        if (sort != null && !FinanceRepository.SORTS.containsKey(sort)) throw AppException.invalid("Unknown sort: " + sort);
        boolean desc = "desc".equals(req.query("dir"));
        Long building = p.isAdmin() ? req.queryLong("building") : null;
        Page<Invoice> page = billing.list(p, building, status, month, sort, desc, req.page());
        StringBuilder b = new StringBuilder();
        filterForm(b, building, status, month);
        table(b, page);
        Map<String, String> filters = new LinkedHashMap<>();
        if (building != null) filters.put("building", building.toString());
        if (status != null) filters.put("status", status.name());
        if (month != null) filters.put("month", month.toString());
        if (sort != null) filters.put("sort", sort);
        if (desc) filters.put("dir", "desc");
        b.append(Html.pager("/invoices", filters, page));
        b.append("<p>").append(page.total()).append(" invoices.</p>");
        return Response.html(Html.page(req, "Invoices", b.toString()));
    }

    private static InvoiceStatus statusOf(Request req) {
        String text = req.query("status");
        if (text == null) return null;
        try {
            return InvoiceStatus.valueOf(text);
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("Unknown value: " + text);
        }
    }

    private static YearMonth monthOf(Request req) {
        String text = req.query("month");
        if (text == null) return null;
        try {
            return YearMonth.parse(text);
        } catch (DateTimeParseException e) {
            throw AppException.invalid("The month must be written YYYY-MM.");
        }
    }

    private static void filterForm(StringBuilder b, Long building, InvoiceStatus status, YearMonth month) {
        b.append("<form method=\\"get\\" action=\\"/invoices\\">");
        if (building != null) b.append("<input type=\\"hidden\\" name=\\"building\\" value=\\"").append(building).append("\\">");
        b.append("<label>Status <select name=\\"status\\"><option value=\\"\\">Any</option>");
        for (InvoiceStatus s : InvoiceStatus.values()) b.append("<option").append(s == status ? " selected" : "").append(">").append(s).append("</option>");
        b.append("</select></label>");
        b.append("<label>Month <input name=\\"month\\" placeholder=\\"YYYY-MM\\" value=\\"").append(esc(month)).append("\\"></label>");
        b.append("<button>Filter</button></form>\\n");
    }

    private static void table(StringBuilder b, Page<Invoice> page) {
        b.append("<table><tr><th><a href=\\"?sort=unit\\">Unit</a></th><th><a href=\\"?sort=month\\">Month</a></th>")
                .append("<th><a href=\\"?sort=due\\">Due</a></th><th><a href=\\"?sort=amount\\">Amount</a></th><th>Status</th></tr>");
        for (Invoice i : page.items()) {
            b.append("<tr><td><a href=\\"/invoices/").append(i.id()).append("\\">").append(esc(i.unitLabel())).append("</a></td>")
                    .append("<td>").append(i.month()).append("</td><td>").append(i.dueDate()).append("</td>")
                    .append("<td class=\\"num\\">").append(Html.money(i.amount())).append("</td><td>").append(i.status()).append("</td></tr>");
        }
        b.append("</table>");
    }'''

CANCEL_GUARDS = '''    public void cancel(Principal p, long bookingId) {
        db.tx(c -> {
            Booking bk = bookings.lock(c, bookingId).filter(k -> p.canSeeUnit(k.buildingId(), k.unitId())).orElseThrow(AppException::notFound);
            if (bk.status() == BookingStatus.CANCELLED) throw AppException.conflict("The booking is already cancelled.");
            Instant now = clock.instant();
            if (!bk.startsAt().isAfter(now)) throw AppException.conflict("The booking has already started.");
            if (!p.manages(bk.buildingId()) && now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                throw AppException.conflict("Bookings can be cancelled up to 48 hours before they start.");
            }
            bookings.setStatus(c, bk.id(), BookingStatus.CANCELLED);
            audit.record(c, p.userId(), "booking_cancelled", String.valueOf(bk.id()), now);
            return null;
        });
    }'''

ENUM_OF = '''    private static <E extends Enum<E>> E enumOf(Class<E> type, String value) {
        if (value == null) return null;
        try {
            return Enum.valueOf(type, value);
        } catch (IllegalArgumentException e) {
            throw AppException.invalid("Unknown value: " + value);
        }
    }
'''
ENUM_OF_RENAMED = (ENUM_OF.replace("(Class<E> type, String value)", "(Class<E> kind, String text)").replace("if (value == null)", "if (text == null)")
                   .replace("Enum.valueOf(type, value)", "Enum.valueOf(kind, text)").replace('+ value);', '+ text);'))

ROUTE_COMMENT = '        // s.route("POST", "/admin/users/{id}/deactivate", Gate.BROWSER, this::deactivate);\n'
HANDLER_COMMENT = '''    // Deactivation moved to the nightly import of the owners' associations; kept here in case the office wants the button back.
    // private Response deactivate(Request req) {
    //     long id = req.id();
    //     auth.deactivate(req.principal(), id);
    //     return Response.redirect(Html.flash("/admin/users", "Account " + id + " deactivated."));
    // }

'''

CASES = [
    # ---- CLN-001: the invoice list in named steps
    dict(target="CLN-001", name="cln001-named-steps", kind="alternative", expect="fixed",
         edits=[("web/FinancePages.java", method_replaced("    private Response list(Request req) {", "    private Response view(Request req) {", LIST_STEPS))]),
    dict(target="CLN-001", name="cln001-lines-joined", kind="wrong", expect="not_fixed",
         edits=[("web/FinancePages.java", lines_joined("    private Response list(Request req) {", "    private Response view(Request req) {"))],
         note="three lines on each line: under the line limit, the same 65 statements"),

    # ---- CLN-007: cancelling with guard clauses
    dict(target="CLN-007", name="cln007-guard-clauses", kind="alternative", expect="fixed",
         edits=[("booking/BookingService.java", method_replaced("    public void cancel(Principal p, long bookingId) {", "    public void confirm(Principal p, long bookingId) {",
                                                                CANCEL_GUARDS))]),
    dict(target="CLN-007", name="cln007-one-level-less", kind="partial", expect="not_fixed",
         edits=[("booking/BookingService.java", '''                        if (!p.manages(bk.buildingId())) {
                            if (now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                                allowed = false;
                            }
                        }''', '''                        if (!p.manages(bk.buildingId()) && now.isAfter(bk.startsAt().minus(CANCEL_NOTICE))) {
                            allowed = false;
                        }''')],
         note="four levels instead of five"),

    # ---- CLN-002: one parser of enum parameters
    dict(target="CLN-002", name="cln002-one-copy", kind="alternative", expect="fixed",
         edits=[("web/MobileApi.java", ENUM_OF, ""), ("web/MobileApi.java", "enumOf(InvoiceStatus.class", "FinancePages.enumOf(InvoiceStatus.class"),
                ("web/ContentPages.java", ENUM_OF, ""), ("web/ContentPages.java", lambda t: t.replace("enumOf(DocumentKind.class", "FinancePages.enumOf(DocumentKind.class"))]),
    dict(target="CLN-002", name="cln002-copies-renamed", kind="wrong", expect="not_fixed",
         edits=[("web/MobileApi.java", ENUM_OF, ENUM_OF_RENAMED), ("web/ContentPages.java", ENUM_OF, ENUM_OF_RENAMED)],
         note="the same bodies with other variable names"),

    # ---- CLN-005: the commented-out handler
    dict(target="CLN-005", name="cln005-deleted", kind="alternative", expect="fixed",
         edits=[("web/AdminPages.java", ROUTE_COMMENT, ""), ("web/AdminPages.java", HANDLER_COMMENT, "")]),
    dict(target="CLN-005", name="cln005-block-comment", kind="wrong", expect="not_fixed",
         edits=[("web/AdminPages.java", ROUTE_COMMENT, '        /* s.route("POST", "/admin/users/{id}/deactivate", Gate.BROWSER, this::deactivate); */\n'),
                ("web/AdminPages.java", HANDLER_COMMENT, "    /*\n" + HANDLER_COMMENT.replace("    // ", "     * ").replace("    //", "     *").rstrip("\n") + "\n     */\n\n")],
         note="the same code, in a block comment"),
]
