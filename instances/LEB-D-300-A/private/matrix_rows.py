"""LEB-D-300-A's answer key, row by row (CATALOG.md is the same profile on paper). tools/make_matrix.py turns it into matrix.json
and matrix.md: locations come from the anchors in public/code/, verifier paths from private/verify/.

`evidence` is written as the symptom, never as a line of the code: the packager refuses a package that contains a long text of
the matrix (harness/pack.py, leak markers)."""

HEADER = {
    "leb_spec": "1.5.1",
    "instance": "LEB-D-300-A",
    "level": 300,
    "version": "1.0",
    "language": "java21-maven-postgresql",
    "task_version": "D-1.0.0",
    "publication": "aggregate",
}

SRC = "src/main/java/com/condodesk/"
ROUTE_PATTERN = r'\.route\("([A-Z]+)", "([^"]+)"'

SYMBOLS = [
    "com.condodesk.App.create(Config,DataSource,Clock):App",
    "com.condodesk.App.start():int",
    "com.condodesk.App.stop():void",
    "com.condodesk.App.main(String[]):void",
    "com.condodesk.Config(int port,Path storageDir,boolean secureCookies,Duration sessionIdle,Set<String> fetchAllowedHosts,String bankSecret,int maxUploadBytes)",
    "com.condodesk.Config.fromEnvironment(Map<String,String>):Config",
    "com.condodesk.Config.DEFAULT_MAX_UPLOAD:int",
]

ARTIFACTS = [
    "delinquency CSV: UTF-8, CRLF, RFC 4180; header unit,owners,month,due_date,amount,amount_due,days_late",
    "document archive: tar.gz with index.csv (file,name,kind,created_at) and every document under its stored name",
]


def flaw(id, severity, difficulty, template, file, anchor, evidence, fix, notes="", **extra):
    return dict(id=id, severity=severity, difficulty=difficulty, template=template, file=file, anchor=anchor, evidence=evidence,
                fix=fix, notes=notes, extra=extra)


def decoy(id, kind, file, anchor, looks, why):
    return dict(id=id, kind=kind, file=file, anchor=anchor, looks=looks, why=why)


FLAWS = [
    # ---- BUG (Template C)
    flaw("BUG-011", "Crítica", "Especialista", "C", "booking/BookingService.java", r"buildings\.unit\(c, r\.unitId\(\)\)",
         "Two booking requests of one unit, for two different areas, can both pass the limit of two upcoming bookings: each counts the "
         "unit's bookings before the other has written, so the unit ends with three.",
         "Decide the requests of one unit one after the other (lock the unit's row before counting, or an equivalent that holds across "
         "connections), so that the second one sees the first and gets 409.",
         "The verifier holds both requests at their insert until both arrive or one is provably waiting for a lock. A lock on the unit "
         "row, an advisory lock per unit and a serializable transaction that retries are accepted; a lock inside the JVM is accepted by "
         "this probe but not by the manifest, which allows several instances. Removing the area's lock is the decoy PERF-015.b.",
         dimensions=["concorrencia", "consistencia"]),
    flaw("BUG-014", "Alta", "Difícil", "C", "billing/FinanceService.java", r"Outcome o = db\.tx\(c -> settle\(c, PaymentSource\.STATEMENT",
         "A statement whose later line is invalid is refused with 400, but the lines before it are already recorded and their invoices "
         "settled: the import is not whole or nothing.",
         "Import every line of a statement in one transaction, so that a refused statement records nothing.",
         "Validating every line before recording any is not enough: the verifier also makes the database refuse a later line, so "
         "the recording itself must be one transaction.",
         dimensions=["resiliencia", "consistencia"]),
    flaw("BUG-006", "Alta", "Difícil", "C", "booking/BookingService.java", r"LocalDate\.now\(clock\)",
         "Around midnight in the building, the rule that blocks a unit with an invoice more than thirty days overdue counts the days "
         "from the date in UTC, so a unit exactly thirty days late is refused.",
         "Take today's date on the building's calendar (its time zone), as every other date rule does.",
         "The verifier runs with the JVM in Tokyo, so the JVM's default zone is not a fix.")
    ,
    flaw("BUG-013", "Alta", "Moderada", "C", "billing/FinanceService.java", r"Period\.between\(dueDate, on\)\.getDays\(\)",
         "From one month late on, the days late restart from zero: an invoice 53 days late is charged and shown as 22 days late, in the "
         "amount due, the API, the invoice page and the delinquency report.",
         "Count the days late as the length of the period in days.",
         "The copy of the rule in the resident's dashboard is ARCH-004; unifying it without fixing the count spreads the error.")
    ,
    flaw("BUG-003", "Alta", "Difícil", "C", "db/Db.java", r"^\s+Connection c = dataSource\.getConnection\(\);",
         "Every read that ends in an error (a not-found decided inside it, a database error) never gives its connection back.",
         "Close the connection whatever the work does (try-with-resources or finally).",
         "The verifier counts the connections the application opens and closes through its DataSource; the driver closing a leaked "
         "connection when it is collected does not count.",
         dimensions=["resiliencia"]),
    flaw("BUG-010", "Média", "Moderada", "C", "docs/Archiver.java", r"proc\.waitFor\(TIMEOUT_SECONDS",
         "When a stored file is missing, the building's archive is still answered with 200 and downloaded as if it were whole: the "
         "failure of the archiving command is not looked at.",
         "Treat a non-zero exit status of the archiving command as a failure (an error answer), keeping a whole archive answered as "
         "before.",
         "Any error status is accepted for the incomplete archive; leaving the missing file out and saying so is not, because the user "
         "gets a file that looks whole.",
         dimensions=["resiliencia"]),
    flaw("BUG-007", "Média", "Moderada", "C", "billing/FinanceService.java", r"paidTotal\(c, i\.id\(\)\)\.equals\(due\)",
         "Only a total exactly equal to the amount due settles an invoice: a payment above it, or two payments that together exceed "
         "it, leave the invoice open for good.",
         "Settle when the payments cover the amount due (at least it), comparing the values, not their scale.",
         "Comparing with compareTo for equality only is still wrong for a payment above the amount due.")
    ,
    flaw("BUG-015", "Baixa", "Moderada", "C", "web/Server.java", r'LOG\.severe\("incident "',
         "An internal error is logged with its incident id but without the exception, so the cause and the stack trace of every 500 "
         "are lost to whoever investigates it.",
         "Log the exception with the record that carries the incident id; the response stays generic.",
         "Any logging that puts the exception, with its stack, in the same record as the incident id, or in the process output next "
         "to it, is accepted; the exception's message alone is not.",
         dimensions=["resiliencia"]),

    # ---- PERF (Template C)
    flaw("PERF-001", "Alta", "Moderada", "C", "reports/ReportService.java", r"reports\.ownersOf\(c, i\.unitId\(\)\)",
         "The delinquency report runs one more query for every late invoice to fetch the owners of its unit.",
         "Fetch the owners of every listed unit at once (a join or one batched query); commands per request must not grow with the "
         "number of late invoices.",
         "A cache per request that still runs one query per distinct unit does not pass: every unit of the probe is distinct.")
    ,
    flaw("PERF-003", "Alta", "Fácil", "C", "repo/FinanceRepository.java", r"all\.subList\(from",
         "The invoice list reads every matching invoice and keeps one page of them in memory.",
         "Page in the query (limit and offset, or keyset) and count the total with a count query.",
         "The page must still say how many invoices match, and the second page must hold the next invoices.")
    ,
    flaw("PERF-010", "Alta", "Difícil", "C", "reports/ReportService.java", r"if \(buildingTotals == null\)",
         "The admin's dashboard keeps the buildings' totals from its first request for the life of the application: invoices billed "
         "afterwards never show as open.",
         "Compute the totals per request (they are aggregate queries), or invalidate them when invoices change.",
         "A cache that is right after every change is accepted; one that only expires with time is not, because the dashboard shows a "
         "wrong number until then.")
    ,
    flaw("PERF-006", "Média", "Moderada", "C", "/schema.sql", r"create table payment \(",
         "Payments are filtered and joined by their invoice on every settlement, invoice page and collection report, with no index for "
         "it.",
         "Add an index leading on the payment's invoice, in a migration (the schema file is the contract).",
         "Only additive migrations count; a composite index that leads on the invoice is accepted.")
    ,
    flaw("PERF-012", "Média", "Especialista", "C", "repo/UserRepository.java", r"for \(Residence r : residences\)",
         "The admin's user list joins the users and their residencies in memory with two nested loops, so its cost grows with the "
         "square of the number of accounts.",
         "Join in the query, or group the residencies by user in a map, so the list grows linearly.",
         "Measured as the processor time of the application's threads at two sizes, as a ratio, never as an absolute time.")
    ,
    flaw("PERF-015", "Média", "Moderada", "C", "billing/FinanceService.java", r"public synchronized Outcome webhook",
         "Every bank event, of every building, waits for the one before to finish, although the row lock on the invoice already "
         "orders the events of one invoice.",
         "Drop the application-wide serialization of the webhook; keep the invoice's row lock.",
         "Replacing it with another application-wide lock, in the JVM or in the database, is not a fix.",
         dimensions=["concorrencia"]),
    flaw("PERF-009", "Baixa", "Fácil", "C", "reports/ReportService.java", r"finance\.openDueBefore\(c, buildingId, today\)\.size\(\)",
         "The manager's dashboard loads every overdue invoice of the building to show how many there are.",
         "Count them in the query.",
         ""),

    # ---- ARCH (Template R)
    flaw("ARCH-001", "Crítica", "Difícil", "R", "billing/FinanceService.java", r"public final class FinanceService",
         "One class bills the month, lists and shows invoices, imports statements, checks and handles the bank's webhook, settles "
         "payments and holds the money rules.",
         "Separate the billing run, the payments and their settlement, and the money rules (split, amount due, days late) into cohesive "
         "classes; the money rules need no database.",
         "Size is not the criterion. The verifier sees which classes run under the insert of a billing run's invoices and under the "
         "settlement of an invoice, and whether the class that floors a budget's shares reaches the database; it caps R3, the judge "
         "reads the split. A facade that still sits on both paths is not a split.")
    ,
    flaw("ARCH-013", "Alta", "Especialista", "R", "db/Db.java", r"private static DataSource dataSource;",
         "The database helper keeps the data source in a static field that every new application overwrites: two applications in one "
         "JVM end up on the last one's database.",
         "Hold the data source in the helper's instance, given at construction, so that each application keeps its own.",
         "A thread-local or a static map keyed by anything is not a fix.")
    ,
    flaw("ARCH-008", "Alta", "Difícil", "R", "repo/ReportRepository.java", r"import com\.condodesk\.reports\.ReportService\.BuildingTotals;",
         "The persistence package depends on the reports package, which depends on persistence: a cycle through the record of the "
         "admin's building totals.",
         "Break the cycle (the record belongs with the data access, or the repository returns its own type) without merging packages.",
         "Merging packages fails the verifier's floor of ten packages under the root; the root package, which wires everything, is "
         "outside the check.")
    ,
    flaw("ARCH-002", "Alta", "Moderada", "R", "web/ReportPages.java", r"int year = db\.read\(",
         "A page of the HTTP layer opens the database itself to compute the collection report, instead of going through the report "
         "service.",
         "Move the query behind the report service and the data access; the HTTP layer must not reference JDBC or the database helper.",
         "The HTTP layer is found by dependency (the classes that use the JDK's server, and their packages), not by package name.")
    ,
    flaw("ARCH-004", "Média", "Difícil", "R", "reports/ReportService.java", r"owed = owed\.add\(i\.amount\(\)\.multiply\(b\.lateFeePct\(\)\)",
         "The amount-due rule is written a second time for the resident's dashboard, with its own day count, which already disagrees "
         "with the rule's own once an invoice is a month late.",
         "Keep one definition of the amount due and call it from the dashboard.",
         "The verifier recognizes a computation of the late fee or of the interest by a division next to a call named for the rate; "
         "this is its one dependence on names. Unifying the copies does not fix BUG-013.")
    ,
    flaw("ARCH-003", "Média", "Moderada", "R", "notices/NoticeService.java", r'Db\.insert\(c, "insert into notice',
         "The notice service writes and reads the notice table with SQL of its own, next to the rules of a notice, while the "
         "repository keeps the search.",
         "Move the notice SQL to the data access; the service keeps the rules.",
         "")
    ,
    flaw("ARCH-010", "Baixa", "Fácil", "R", "billing/CnabParser.java", r"public final class CnabParser",
         "A parser of the bank's old return file format is kept although nothing uses it.",
         "Delete it.",
         "")
    ,
    flaw("ARCH-009", "Baixa", "Fácil", "R", "notices/NoticeService.java", r"title\.length\(\) > 200",
         "The notice limits (title, text and search words) are bare numbers, each written twice, in the check and in its message.",
         "Give each limit a named constant and build its message from it.",
         "Any constant name is accepted; whether the name says what the number means is for the judge.")
    ,

    # ---- CLN (Template R)
    flaw("CLN-001", "Média", "Moderada", "R", "web/FinancePages.java", r"private Response list\(Request req\)",
         "The invoice list page is one long method that parses the filters, builds the table and the pager inline.",
         "Split it into named steps; no method over 45 useful lines or 45 statements.",
         "")
    ,
    flaw("CLN-007", "Média", "Moderada", "R", "booking/BookingService.java", r"public void cancel\(Principal p, long bookingId\)",
         "Cancelling a booking nests five decisions, with the main flow at the bottom.",
         "Use guard clauses; no chain of nested decisions deeper than 3.",
         "")
    ,
    flaw("CLN-002", "Baixa", "Fácil", "R", "web/MobileApi.java", r"private static <E extends Enum<E>> E enumOf",
         "The parsing of an enum parameter is copied, body and all, into two more classes of the HTTP layer.",
         "Keep one copy and call it from the others.",
         "")
    ,
    flaw("CLN-005", "Baixa", "Fácil", "R", "web/AdminPages.java", r"// private Response deactivate",
         "An old handler is left in the code as comments, with its route.",
         "Delete the commented-out code.",
         ""),
]

DECOYS = [
    decoy("BUG-013.b", "diagnostico", "billing/FinanceService.java", r"divide\(fractions, 12, RoundingMode\.HALF_EVEN\)",
          "money rounded half-even",
          "The quotient at 12 decimals is an intermediate value: each share is floored and the leftover cents go to the largest "
          "remainders, so the invoices add up to the budget exactly, and the rounding of the 12th decimal never moves a floor."),
    decoy("ARCH-014", "diagnostico", "billing/FinanceService.java", r"public static final int PAGE_SIZE = 20;",
          "hard-coded configuration",
          "Twenty invoices and ten notices per page are the contract of the manifest, not a setting."),
    decoy("BUG-010.b", "diagnostico", "billing/OfxParser.java", r"SILENT = new ErrorHandler",
          "an error handler that swallows errors",
          "It ignores warnings only; errors and fatal errors are thrown, so a malformed statement is refused with 400."),
    decoy("PERF-015.b", "intervencao", "booking/BookingService.java", r"buildings\.lockArea\(c, r\.areaId\(\)\)",
          "needless blocking",
          "The area's row lock decides two requests for one slot one after the other; without it both pass the overlap check and the "
          "slot is booked twice."),
]
