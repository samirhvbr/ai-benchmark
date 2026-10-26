"""SEC-016, SEC-016.b, SEC-017, SEC-017.b and SEC-019: a missing check put back another way, and checks that are too weak."""

AUTH = "auth/AuthService.java"
PAY = "billing/PaymentService.java"
BILL = "billing/BillingService.java"
DOCS = "docs/DocumentService.java"
MOBILE = "web/MobileApi.java"

CREATE_FIRST = r'''        if (email == null || !email.matches("[^@\\s]+@[^@\\s]+\\.[^@\\s]+")) throw AppException.invalid("Enter a valid email.");
'''
SETTLE = '''            Outcome o = settle(c, PaymentSource.MANUAL,'''
VIEW_BUILDING = '''            Building b = buildings.byId(c, i.buildingId()).orElseThrow();
            LocalDate today = b.dateAt(clock.instant());
            boolean open'''
DOWNLOAD = '''        StoredDocument d = db.read(c -> content.document(c, id)).orElseThrow(AppException::notFound);'''

CASES = [
    dict(target="SEC-016", name="sec016-admin-check-restored", kind="alternative", expect="fixed",
         edits=[(AUTH, CREATE_FIRST, "        admin.requireAdmin();\n" + CREATE_FIRST)],
         note="the check back where the reference has it"),
    dict(target="SEC-016", name="sec016-role-check-inline", kind="alternative", expect="fixed",
         edits=[(AUTH, CREATE_FIRST, "        if (!admin.isAdmin()) throw AppException.forbidden();\n" + CREATE_FIRST)],
         note="the same check written with the principal's own question"),
    dict(target="SEC-016", name="sec016-residents-only-refused", kind="partial", expect="not_fixed",
         edits=[(AUTH, CREATE_FIRST, "        if (admin.role() == Role.RESIDENT) throw AppException.forbidden();\n" + CREATE_FIRST)],
         note="a manager can still create an admin"),
    dict(target="SEC-016.b", name="sec016b-manager-check-restored", kind="alternative", expect="fixed",
         edits=[(PAY, SETTLE, "            p.requireManager(i.buildingId());\n" + SETTLE)],
         note="the check back where the reference has it"),
    dict(target="SEC-016.b", name="sec016b-same-building-only", kind="partial", expect="not_fixed",
         edits=[(PAY, SETTLE, "            if (p.buildingId() == null || p.buildingId() != i.buildingId()) throw AppException.forbidden();\n" + SETTLE)],
         note="a resident of the invoice's building still settles it"),
    dict(target="SEC-017", name="sec017-unit-check-restored", kind="alternative", expect="fixed",
         edits=[(BILL, VIEW_BUILDING, "            if (!p.canSeeUnit(i.buildingId(), i.unitId())) throw AppException.notFound();\n" + VIEW_BUILDING)],
         note="the check back where the reference has it"),
    dict(target="SEC-017", name="sec017-forbidden-instead-of-not-found", kind="alternative", expect="fixed",
         edits=[(BILL, VIEW_BUILDING, "            if (!p.canSeeUnit(i.buildingId(), i.unitId())) throw AppException.forbidden();\n" + VIEW_BUILDING)],
         note="403 for what one may not see: the manifest names both 403 and 404, so the verifier accepts either refusal"),
    dict(target="SEC-017", name="sec017-building-only", kind="partial", expect="not_fixed",
         edits=[(BILL, VIEW_BUILDING, "            if (!p.belongsTo(i.buildingId())) throw AppException.notFound();\n" + VIEW_BUILDING)],
         note="another unit's invoice of the same building is still read"),
    dict(target="SEC-017.b", name="sec017b-visibility-restored", kind="alternative", expect="fixed",
         edits=[(DOCS, DOWNLOAD, "        StoredDocument d = db.read(c -> content.document(c, id))\n"
                 "                .filter(x -> x.unitId() == null ? p.belongsTo(x.buildingId()) : p.canSeeUnit(x.buildingId(), x.unitId()))\n"
                 "                .orElseThrow(AppException::notFound);")],
         note="the reference's check, inline"),
    dict(target="SEC-017.b", name="sec017b-building-only", kind="partial", expect="not_fixed",
         edits=[(DOCS, DOWNLOAD, "        StoredDocument d = db.read(c -> content.document(c, id)).filter(x -> p.belongsTo(x.buildingId())).orElseThrow(AppException::notFound);")],
         note="another unit's payment proof of the same building is still downloaded"),
    dict(target="SEC-019", name="sec019-counted", kind="alternative", expect="fixed",
         edits=[(MOBILE, 'req.clientAddress(), false);', 'req.clientAddress(), true);')],
         note="the mobile login goes through the same lock as the browser's"),
    dict(target="SEC-019", name="sec019-counted-never-checked", kind="partial", expect="not_fixed",
         edits=[(MOBILE, 'req.clientAddress(), false);', 'req.clientAddress(), true);'),
                (AUTH, "        if (counted) throttle.check(email, address);\n", "")],
         note="failures are counted but the lock is never honoured"),
]
