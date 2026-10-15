"""Decoys: a change that keeps the property holds; one that breaks it does not."""

FINANCE = "billing/FinanceService.java"
OFX = "billing/OfxParser.java"
BOOKING = "booking/BookingService.java"
BUILDINGS = "repo/BuildingRepository.java"
FINANCE_PAGES = "web/FinancePages.java"
INVOICE_LINK = '            b.append("<td><a href=\\"/invoices/").append(i.id()).append("\\">").append(esc(i.unitLabel())).append("</a></td>");'
INVOICE_LINK_RESTYLED = '            b.append("<td class=\\"unit\\"><a class=\\"inv\\" href=\'/invoices/").append(i.id()).append("\'>").append(esc(i.unitLabel())).append("</a></td>");'
OFX_CATCH = "        } catch (SAXException | IOException | NumberFormatException | DateTimeParseException e) {\n            throw invalid();"

CASES = [
    # ---- BUG-013.b: the budget split's intermediate rounding
    dict(target="BUG-013.b", name="bug013b-half-up-at-twelve-decimals", kind="decoy", expect="holds",
         edits=[(FINANCE, "divide(fractions, 12, RoundingMode.HALF_EVEN)", "divide(fractions, 12, RoundingMode.HALF_UP)")],
         note="the 'fix' a report would suggest: harmless, the floors do not move"),
    dict(target="BUG-013.b", name="bug013b-each-share-rounded", kind="decoy", expect="broken",
         edits=[(FINANCE, "BigDecimal floor = exact.setScale(0, RoundingMode.FLOOR);", "BigDecimal floor = exact.setScale(0, RoundingMode.HALF_UP);"),
                (FINANCE, "        for (int i = 0; i < left; i++) out.merge(byRemainder.get(i).unit().id(), BigDecimal.ONE, BigDecimal::add);\n", "")],
         note="each share rounded on its own: the invoices no longer add up to the budget"),

    # ---- ARCH-014: the contracted page sizes
    dict(target="ARCH-014", name="arch014-renamed", kind="decoy", expect="holds",
         edits=[(FINANCE, lambda t: t.replace("PAGE_SIZE", "INVOICES_PER_PAGE"))]),
    dict(target="ARCH-014", name="arch014-invoice-links-restyled", kind="decoy", expect="holds",
         edits=[(FINANCE_PAGES, INVOICE_LINK, INVOICE_LINK_RESTYLED)],
         note="the page sizes hold after a delivery restyled the invoice rows (single quotes, classes): the verifier counts the invoices a page links to, not their tags"),
    dict(target="ARCH-014", name="arch014-another-size", kind="decoy", expect="broken",
         edits=[(FINANCE, "public static final int PAGE_SIZE = 20;", "public static final int PAGE_SIZE = 25;")]),

    # ---- BUG-010.b: the parser's error handler
    dict(target="BUG-010.b", name="bug010b-renamed", kind="decoy", expect="holds",
         edits=[(OFX, lambda t: t.replace("SILENT", "WARNINGS_ONLY"))]),
    dict(target="BUG-010.b", name="bug010b-errors-swallowed", kind="decoy", expect="broken",
         edits=[(OFX, OFX_CATCH, OFX_CATCH.replace("throw invalid();", "return new ArrayList<>();"))],
         note="a malformed statement imported as an empty one"),

    # ---- PERF-015.b: the area's lock
    dict(target="PERF-015.b", name="perf015b-advisory-lock-per-area", kind="decoy", expect="holds",
         edits=[(BUILDINGS, '" where id = ? for update"', '" where id = ?"'),
                (BOOKING, "            CommonArea area = buildings.lockArea(c, r.areaId())",
                 '            Db.one(c, "select pg_advisory_xact_lock(?)", rs -> true, r.areaId());\n            CommonArea area = buildings.lockArea(c, r.areaId())')],
         note="another guard that orders two requests for one area"),
    dict(target="PERF-015.b", name="perf015b-lock-removed", kind="decoy", expect="broken",
         edits=[(BUILDINGS, '" where id = ? for update"', '" where id = ?"')]),
]
