"""The deliveries the two reviewers of 2026-10-29 described, written as control cases: each is a delivery the verifier of its row judged wrongly before the review,
with the verdict it must give. A case here that is expected `not_fixed` or `fixed` is the proof that the finding was real and is closed."""

AUTH = "auth/AuthService.java"
PAY = "billing/PaymentService.java"

CREATE_FIRST = r'''        if (email == null || !email.matches("[^@\\s]+@[^@\\s]+\\.[^@\\s]+")) throw AppException.invalid("Enter a valid email.");
'''
SETTLE = '''            Outcome o = settle(c, PaymentSource.MANUAL,'''
JS = "/src/main/resources/static/app.js"
SINK = "    box.innerHTML = message;"
CSV = "reports/Csv.java"
CELL = """if (!v.isEmpty() && v.charAt(0) == '=') v = "'" + v;"""
REPORT = "reports/ReportService.java"
PAGES = "web/ContentPages.java"
TERM = '''.append(" notices for “").append(q)'''
DOCS = "docs/DocumentService.java"
BLOCKED = '''java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js")'''

CASES = [
    dict(target="SEC-016", name="review-sec016-new-admin-only", kind="partial", expect="not_fixed",
         edits=[(AUTH, CREATE_FIRST, "        if (role == Role.ADMIN) admin.requireAdmin();\n" + CREATE_FIRST)],
         note="only the creation of an admin is checked: a resident still creates a manager of any building"),
    dict(target="SEC-016.b", name="review-sec016b-manager-of-any-building", kind="partial", expect="not_fixed",
         edits=[(PAY, SETTLE, "            if (!p.isAdmin() && !\"MANAGER\".equals(p.role().name())) throw AppException.forbidden();\n" + SETTLE)],
         note="any manager settles any building's invoice"),
    dict(target="SEC-016.b", name="review-sec016b-admin-locked-out", kind="wrong", expect="not_fixed",
         edits=[(PAY, SETTLE, "            if (!\"MANAGER\".equals(p.role().name()) || p.buildingId() == null || p.buildingId() != i.buildingId()) throw AppException.forbidden();\n" + SETTLE)],
         note="the admin, whom the manifest lets register a payment, is refused"),
    dict(target="SEC-004", name="review-sec004-escaped-in-a-variable", kind="alternative", expect="inconclusive",
         edits=[(JS, SINK, '    var safe = message.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");\n    box.innerHTML = safe;')],
         note="a sound escape written with replace calls and kept in a variable: no pattern can tell it from a broken one, so the judge reads it (it was none)"),
    dict(target="SEC-004", name="review-sec004-escaped-and-reassigned", kind="alternative", expect="inconclusive",
         edits=[(JS, SINK, '    message = message.replace(/&/g, "&amp;").replace(/</g, "&lt;");\n    box.innerHTML = message;')],
         note="the variable is escaped where it is read, then written as markup: the sink is fed by the same name, but not as it was read"),
    dict(target="SEC-004", name="review-sec004-bracket-form", kind="wrong", expect="not_fixed",
         edits=[(JS, SINK, '    box["innerHTML"] = message;')],
         note="the same weakness written with the bracket form (it was full)"),
    dict(target="SEC-004", name="review-sec004-contextual-fragment", kind="wrong", expect="not_fixed",
         edits=[(JS, SINK, '    box.appendChild(document.createRange().createContextualFragment(message));')],
         note="markup parsed from the message by another call (it was full)"),
    dict(target="SEC-004", name="review-sec004-message-dropped", kind="wrong", expect="inconclusive",
         edits=[(JS, SINK, '    // no message')],
         note="the flash message is no longer shown, which the manifest contracts: the judge reads it (it was full)"),
    dict(target="SEC-003", name="review-sec003-tags-stripped", kind="wrong", expect="not_fixed",
         edits=[(PAGES, TERM, '''.append(" notices for “").append(q.replaceAll("<[^>]*>", ""))''')],
         note="closed tags are removed, an unclosed one still reaches the page (it was full)"),
    dict(target="SEC-008", name="review-sec008-quoted-prefix", kind="alternative", expect="fixed",
         edits=[(CSV, CELL, '''if (!v.isEmpty() && "=+-@\\t\\r".indexOf(v.charAt(0)) >= 0) return "\\"'" + v.replace("\\"", "\\"\\"") + "\\"";''')],
         note="the neutralization written as a quoted cell with the apostrophe inside, which is valid CSV and was read as open"),
    dict(target="SEC-008", name="review-sec008-no-carriage-return", kind="partial", expect="not_fixed",
         edits=[(CSV, CELL, '''if (!v.isEmpty() && "=+-@\\t".indexOf(v.charAt(0)) >= 0) v = "'" + v;''')],
         note="every leading character but the carriage return (it was full)"),
    dict(target="SEC-008", name="review-sec008-report-only", kind="partial", expect="not_fixed",
         edits=[(REPORT, "d.unit(), d.owners(),", '''d.unit(), (d.owners() != null && !d.owners().isEmpty() && "=+-@\\t\\r".indexOf(d.owners().charAt(0)) >= 0 ? "'" + d.owners() : d.owners()),''')],
         note="the delinquency report neutralized in its own code, the archive's index.csv left as it was (it was full)"),
    dict(target="SEC-018", name="review-sec018-html-and-svg-only", kind="partial", expect="not_fixed",
         edits=[(DOCS, BLOCKED, '''java.util.Set.of("php", "jsp", "exe", "sh", "bat", "js", "html", "svg")''')],
         note="html and svg refused, htm still stored as a page (it was full)"),
]
