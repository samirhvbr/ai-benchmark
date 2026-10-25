"""LEB-S-300-A: the access rows of ../CATALOG.md section 2.3 and 2.4 that delete one check (Template C)."""

from paths import SRC

FLAWS = [
    # The admin check at the start of the account creation is gone: any logged-in user can create an admin.
    ("SEC-016", [
        (SRC + "auth/AuthService.java",
         "        admin.requireAdmin();\n        if (email == null || !email.matches(",
         "        if (email == null || !email.matches("),
    ]),
    # The manager check of a payment registered by hand is gone: a resident settles their own invoice.
    ("SEC-016.b", [
        (SRC + "billing/PaymentService.java",
         "            Invoice i = finance.invoice(c, invoiceId).orElseThrow(AppException::notFound);\n            p.requireManager(i.buildingId());\n",
         "            Invoice i = finance.invoice(c, invoiceId).orElseThrow(AppException::notFound);\n"),
    ]),
    # The unit and building check of an invoice view is gone: any logged-in user reads any invoice by its id.
    ("SEC-017", [
        (SRC + "billing/BillingService.java",
         "            if (!p.canSeeUnit(i.buildingId(), i.unitId())) throw AppException.notFound();\n",
         ""),
    ]),
    # The visibility check of a document download is gone: any logged-in user downloads any document by its id.
    ("SEC-017.b", [
        (SRC + "docs/DocumentService.java",
         "        StoredDocument d = db.read(c -> content.document(c, id)).filter(x -> canSee(p, x)).orElseThrow(AppException::notFound);\n",
         "        StoredDocument d = db.read(c -> content.document(c, id)).orElseThrow(AppException::notFound);\n"),
        (SRC + "docs/DocumentService.java",
         "    private boolean canSee(Principal p, StoredDocument d) {\n"
         "        return d.unitId() == null ? p.belongsTo(d.buildingId()) : p.canSeeUnit(d.buildingId(), d.unitId());\n"
         "    }\n\n",
         ""),
    ]),
]
