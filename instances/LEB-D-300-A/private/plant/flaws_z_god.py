"""LEB-D-300-A — ARCH-001 of ../CATALOG.md §4: BillingService, PaymentService and Charges merged into one finance class.

Planted last, on purpose: BUG-013, BUG-014, BUG-007 and PERF-015 are planted in their original classes by the earlier modules, and
the merge carries them, as they are, into the merged class. The edits below repoint the callers; the post-hook writes the merged
class from the three planted texts and deletes them.
"""

from paths import SRC

BILLING = SRC + "billing/BillingService.java"
PAYMENT = SRC + "billing/PaymentService.java"
CHARGES = SRC + "billing/Charges.java"
MERGED = SRC + "billing/FinanceService.java"

HEADER = '''/**
 * The money side of CondoDesk: the monthly billing run and its invoices, the payments from bank statements, from the bank's
 * webhook and by hand, how they settle invoices, and the rules that price them.
 */
public final class FinanceService {
    public static final int PAGE_SIZE = 20;
    private static final BigDecimal HUNDRED = BigDecimal.valueOf(100);
    /** A month of interest is charged as thirty days. */
    private static final BigDecimal INTEREST_DAYS = BigDecimal.valueOf(30);
    private static final Pattern OUR_NUMBER = Pattern.compile("\\\\b(\\\\d{6}-\\\\d{4}-\\\\d{6})\\\\b");
    private static final ObjectMapper JSON = new ObjectMapper();

    public record RunResult(long runId, int invoices, BigDecimal total) {}

    /** An invoice with what it costs today and the payments made against it. */
    public record InvoiceView(Invoice invoice, Building building, BigDecimal dueToday, long daysLate, List<Payment> payments) {}

    public enum Outcome { PAID, PARTIAL, UNMATCHED, DUPLICATE, ALREADY_SETTLED }

    private final Db db;
    private final BuildingRepository buildings;
    private final FinanceRepository finance;
    private final AuditLog audit;
    private final Clock clock;
    private final String bankSecret;

    public FinanceService(Db db, BuildingRepository buildings, FinanceRepository finance, AuditLog audit, Clock clock, String bankSecret) {
        this.db = db;
        this.buildings = buildings;
        this.finance = finance;
        this.audit = audit;
        this.clock = clock;
        this.bankSecret = bankSecret;
    }

'''

# The declarations HEADER restates; each must still be in its source, as the reference wrote it.
DECLARED = {
    BILLING: ["    public static final int PAGE_SIZE = 20;\n",
              "    public record RunResult(long runId, int invoices, BigDecimal total) {}\n",
              "    public record InvoiceView(Invoice invoice, Building building, BigDecimal dueToday, long daysLate, List<Payment> payments) {}\n"],
    PAYMENT: ["    private static final Pattern OUR_NUMBER = Pattern.compile(\"\\\\b(\\\\d{6}-\\\\d{4}-\\\\d{6})\\\\b\");\n",
              "    private static final ObjectMapper JSON = new ObjectMapper();\n",
              "    public enum Outcome { PAID, PARTIAL, UNMATCHED, DUPLICATE, ALREADY_SETTLED }\n",
              "    private final String bankSecret;\n"],
    CHARGES: ["    private static final BigDecimal HUNDRED = BigDecimal.valueOf(100);\n",
              "    private static final BigDecimal INTEREST_DAYS = BigDecimal.valueOf(30);\n"],
}

# Where each class's methods start: everything from here to the closing brace moves into the merged class.
FIRST_METHOD = {
    BILLING: "    /** Bills a month of a building:",
    PAYMENT: "    /** Imports the credits of a statement.",
    CHARGES: "    /**\n     * Splits the budget by ideal fraction,",
}


def merge(files, read):
    texts = {path: read(path) for path in (BILLING, PAYMENT, CHARGES)}
    imports, bodies = set(), []
    for path in (BILLING, PAYMENT, CHARGES):
        text = texts[path]
        for line in DECLARED[path]:
            assert line in text, "%s no longer declares: %s" % (path, line.strip())
        imports.update(l for l in text.splitlines() if l.startswith("import "))
        start = text.index(FIRST_METHOD[path])
        end = text.rstrip().rindex("}")
        bodies.append(text[start:end].rstrip() + "\n")
    methods = "\n".join(bodies).replace("Charges.", "")
    assert "BillingService" not in methods and "PaymentService" not in methods
    key = lambda line: line.rstrip(";")          # the class before its nested types, as the reference's files order them
    groups = [sorted((i for i in imports if i.startswith("import com.")), key=key),
              sorted((i for i in imports if i.startswith("import javax.")), key=key)
              + sorted((i for i in imports if i.startswith("import java.")), key=key)]
    assert sum(len(g) for g in groups) == len(imports), "an import of an unexpected kind"
    files[MERGED] = ("package com.condodesk.billing;\n\n" + "\n".join(groups[0]) + "\n\n" + "\n".join(groups[1]) + "\n\n"
                     + HEADER + methods + "}\n")
    for path in (BILLING, PAYMENT, CHARGES):
        files[path] = None


POS = [("ARCH-001", merge)]

FLAWS = [
    ("ARCH-001", [
        (SRC + "App.java",
         "import com.condodesk.billing.BillingService;\nimport com.condodesk.billing.PaymentService;\n",
         "import com.condodesk.billing.FinanceService;\n"),
        (SRC + "App.java",
         "        BillingService billing = new BillingService(db, buildings, finance, audit, clock);\n"
         "        PaymentService payments = new PaymentService(db, buildings, finance, audit, clock, config.bankSecret());\n",
         "        FinanceService billing = new FinanceService(db, buildings, finance, audit, clock, config.bankSecret());\n"),
        (SRC + "App.java",
         "auth, billing, payments, bookings, notices, documents, reports);",
         "auth, billing, bookings, notices, documents, reports);"),
        (SRC + "web/Routes.java",
         "import com.condodesk.billing.BillingService;\nimport com.condodesk.billing.PaymentService;\n",
         "import com.condodesk.billing.FinanceService;\n"),
        (SRC + "web/Routes.java",
         "AuthService auth, BillingService billing,\n                                PaymentService payments, BookingService bookings,",
         "AuthService auth, FinanceService billing,\n                                BookingService bookings,"),
        (SRC + "web/Routes.java",
         "        new FinancePages(billing, payments, documents).register(server);",
         "        new FinancePages(billing, documents).register(server);"),
        (SRC + "web/Routes.java",
         "        new MobileApi(auth, sessions, billing, payments, bookings).register(server);",
         "        new MobileApi(auth, sessions, billing, bookings).register(server);"),
        (SRC + "web/FinancePages.java",
         "import com.condodesk.billing.BillingService;\n"
         "import com.condodesk.billing.BillingService.InvoiceView;\n"
         "import com.condodesk.billing.BillingService.RunResult;\n"
         "import com.condodesk.billing.PaymentService;\n"
         "import com.condodesk.billing.PaymentService.Outcome;\n",
         "import com.condodesk.billing.FinanceService;\n"
         "import com.condodesk.billing.FinanceService.InvoiceView;\n"
         "import com.condodesk.billing.FinanceService.Outcome;\n"
         "import com.condodesk.billing.FinanceService.RunResult;\n"),
        (SRC + "web/FinancePages.java",
         "    private final BillingService billing;\n    private final PaymentService payments;\n",
         "    private final FinanceService billing;\n"),
        (SRC + "web/FinancePages.java",
         "    FinancePages(BillingService billing, PaymentService payments, DocumentService documents) {\n"
         "        this.billing = billing;\n"
         "        this.payments = payments;\n",
         "    FinancePages(FinanceService billing, DocumentService documents) {\n"
         "        this.billing = billing;\n"),
        (SRC + "web/FinancePages.java",
         "        Outcome o = payments.manual(", "        Outcome o = billing.manual("),
        (SRC + "web/FinancePages.java",
         "        Map<Outcome, Integer> counts = payments.importStatement(", "        Map<Outcome, Integer> counts = billing.importStatement("),
        (SRC + "web/MobileApi.java",
         "import com.condodesk.billing.BillingService;\n"
         "import com.condodesk.billing.BillingService.InvoiceView;\n"
         "import com.condodesk.billing.PaymentService;\n",
         "import com.condodesk.billing.FinanceService;\n"
         "import com.condodesk.billing.FinanceService.InvoiceView;\n"),
        (SRC + "web/MobileApi.java",
         "    private final BillingService billing;\n    private final PaymentService payments;\n",
         "    private final FinanceService billing;\n"),
        (SRC + "web/MobileApi.java",
         "    MobileApi(AuthService auth, Sessions sessions, BillingService billing, PaymentService payments, BookingService bookings) {",
         "    MobileApi(AuthService auth, Sessions sessions, FinanceService billing, BookingService bookings) {"),
        (SRC + "web/MobileApi.java",
         "        this.billing = billing;\n        this.payments = payments;\n",
         "        this.billing = billing;\n"),
        (SRC + "web/MobileApi.java",
         "        PaymentService.Outcome o = payments.webhook(", "        FinanceService.Outcome o = billing.webhook("),
        (SRC + "reports/ReportService.java",
         "import com.condodesk.billing.Charges;\n", "import com.condodesk.billing.FinanceService;\n"),
        (SRC + "reports/ReportService.java",
         "                BigDecimal due = Charges.amountDue(", "                BigDecimal due = FinanceService.amountDue("),
        (SRC + "reports/ReportService.java",
         "                        Charges.daysLate(i.dueDate(), today)));", "                        FinanceService.daysLate(i.dueDate(), today)));"),
    ]),
]
