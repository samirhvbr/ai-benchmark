"""LEB-S-300-A's answer key, row by row (CATALOG.md is the same profile on paper). tools/make_matrix.py turns it into matrix.json
and matrix.md: locations come from the anchors in public/code/, verifier paths from private/verify/.

`evidence` is written as the symptom, never as a line of the code: the packager refuses a package that contains a long text of
the matrix (harness/pack.py, leak markers). Every row is behavioral (Template C): the reference attack and its variations decide C3,
and a legitimate-use probe in the same verdict decides that the function survived (a fix that removes the function is not a fix)."""

HEADER = {
    "leb_spec": "1.5.1",
    "instance": "LEB-S-300-A",
    "level": 300,
    "version": "1.0",
    "language": "java21-maven-postgresql",
    "task_version": "S-1.0.0",
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
    # ---- injection and output
    flaw("SEC-001", "Crítica", "Moderada", "C", "repo/FinanceRepository.java", r"SORTS\.getOrDefault\(f\.sort\(\), f\.sort\(\)\)",
         "The invoice list's sort parameter is read as SQL: a sort made of a condition on another table orders the list differently, or fails, "
         "depending on that condition, which lets a logged-in user read data of other tables one bit at a time.",
         "Sort only by the four keys the manifest names (a closed list of columns), and refuse or ignore any other value without it reaching the SQL text.",
         "The verifier sends two sorts that differ only in a condition on app_user, by ordering and by a division by zero, and requires the same response to both; "
         "the four keys must still sort both ways. Binding is impossible for a column name: an allowlist is the fix.", dimensions=["injecao"]),
    flaw("SEC-001.b", "Crítica", "Difícil", "C", "repo/ContentRepository.java", r"n\.title ilike '\" \+ pattern",
         "A word of the notice search that closes the string and adds a condition changes which notices come back, depending on a condition on another table.",
         "Bind each search pattern as a parameter, keeping the escaping of the LIKE wildcards, so that a quote is only a character of the word.",
         "The verifier's payload avoids spaces (the search splits on them) and underscores (the wildcard escaping would alter them). A fix that rejects words with a quote "
         "is accepted by the attack probe but fails the legitimate probe only if it refuses ordinary words; an apostrophe in a real word should keep working.", dimensions=["injecao"]),
    flaw("SEC-002", "Crítica", "Moderada", "C", "web/ContentPages.java", r"\+ n\.title\(\) \+ \"</a></h2>",
         "A notice whose title carries markup, and a manager whose name does, reach every resident's notice pages as markup, so a manager can run script in a resident's session.",
         "Escape the title and the author's name where the notice is written, keeping the line breaks and **bold** that the body gets after escaping.",
         "The same page builder escapes the page's own title and heading; only the notice block is open.", dimensions=["saida"]),
    flaw("SEC-002.b", "Alta", "Difícil", "C", "web/ContentPages.java", r"\.append\(d\.originalName\(\)\)\.append\(\"</a></td><td>\"\)",
         "A payment proof named with markup, sent by a resident, reaches the manager's document list as markup, so a resident can run script in a manager's session.",
         "Escape the document's name where the list is written (and its source address, which cannot carry markup but is written the same way).",
         "The name survives the upload's own cleaning, which removes directory parts and control characters only.", dimensions=["saida"]),
    flaw("SEC-003", "Alta", "Fácil", "C", "web/ContentPages.java", r"\.append\(\" notices for “\"\)\.append\(q\)",
         "The line that repeats a notice search term writes it as markup, so a crafted link runs script in the visitor's session.",
         "Escape the term where it is repeated, as the search box next to it already does.",
         "Only the results line is open; the search field's value is escaped.", dimensions=["saida"]),
    flaw("SEC-004", "Alta", "Moderada", "C", "/src/main/resources/static/app.js", r"box\.innerHTML = message",
         "The message a redirect passes in the address is written into the page as markup by the page script, so a crafted link runs script.",
         "Write the message as text, not as markup.",
         "No engine here runs the script: the verifier reads it, decides only a variable handed to a markup sink as it was read (not fixed) or a script with no such sink that still shows the message as text (fixed), and leaves every other script to the judge.", dimensions=["saida"]),
    flaw("SEC-005", "Alta", "Moderada", "C", "web/Server.java", r"boolean upload = type != null",
         "An upload (a document, a payment proof, a bank statement) posted from another site with the victim's session cookie and no token is accepted.",
         "Check the form's token on every state-changing request of the browser side, multipart ones included.",
         "The token is also accepted in a header; the forms send it as a field.", dimensions=["sessao"]),
    flaw("SEC-008", "Média", "Fácil", "C", "reports/Csv.java", r"v\.charAt\(0\) == '='",
         "A cell that starts with plus, minus, at or a tab reaches the CSV files as a formula for a spreadsheet; only one that starts with an equals sign is neutralized.",
         "Neutralize every cell whose first character can start a formula (=, +, -, @, tab, carriage return), without changing the rest of the format.",
         "The archive's index.csv uses the same cell writer.", dimensions=["saida"]),
    # ---- files, processes, parsers, requests
    flaw("SEC-006", "Crítica", "Difícil", "C", "docs/DocumentService.java", r"resolve\(String\.valueOf\(buildingId\)\)\.resolve\(fileName\)",
         "The old viewer's link reads a file by a name the caller chooses, and a name that climbs out of the building's folder (or an absolute path) returns another building's document or a file outside the storage.",
         "Resolve the name, normalize it and require it to stay inside the building's folder, answering 404 otherwise; the route and its answers for the building's own files stay.",
         "The route is in the public manifest: removing it is a change of the contract. Looking the file up by its document id would also close it, if the route's contract is kept.", dimensions=["arquivos"]),
    flaw("SEC-009", "Crítica", "Difícil", "C", "docs/Archiver.java", r"new ProcessBuilder\(\"sh\", \"-c\", command\)",
         "The archive's label reaches a shell command: a label with a semicolon, a command substitution, backticks or a new line makes the server run a command of the caller's choice.",
         "Run tar with an argument vector and no shell, and keep refusing a label outside letters, digits, dot, dash and underscore (40 characters at most).",
         "The verifier looks for the files the commands would create; a label that is merely refused with 400 passes.", dimensions=["processos"]),
    flaw("SEC-010", "Alta", "Moderada", "C", "billing/OfxParser.java", r"DocumentBuilderFactory f = DocumentBuilderFactory\.newInstance\(\)",
         "A bank statement whose XML declares an external entity or an external DTD gets the server to read a local file and store its text in a payment's memo.",
         "Turn off DOCTYPE processing and external entities in the existing parser, and keep the statement import as it is for valid files.",
         "The JDK's own default is the vulnerable one; the fix is the parser's configuration, not a different library.", dimensions=["parsers"]),
    flaw("SEC-011", "Alta", "Difícil", "C", "docs/UrlFetcher.java", r"Redirect\.NORMAL",
         "A document fetched from an approved site can be a redirect to an address inside the network, and the server then fetches and stores what is there.",
         "Do not follow redirects (or check every hop against the allowlist), and keep refusing hosts that are not on it.",
         "The test site already serves a redirect to an internal server.", dimensions=["rede"]),
    flaw("SEC-012", "Média", "Fácil", "C", "web/AccountPages.java", r"if \(next == null \|\| !next\.startsWith\(\"/\"\)\) return \"/\";",
         "After login the visitor can be sent to another site: an address that starts with two slashes, or a slash and a backslash, is followed.",
         "Follow only a local path: one slash, then not a slash or a backslash, and no control characters; otherwise go to the home page.",
         "A local path with its query still has to be followed.", dimensions=["sessao"]),
    # ---- sessions, passwords, secrets, rate limit
    flaw("SEC-013", "Alta", "Fácil", "C", "auth/Sessions.java", r"update user_session set user_id",
         "A session cookie obtained before login keeps working after the victim logs in with it, so whoever planted it is logged in as the victim.",
         "Start a new session at login: a new identifier (and token) and the old one dropped.",
         "Logout and the idle expiry must keep working.", dimensions=["sessao"]),
    flaw("SEC-014", "Alta", "Difícil", "C", "auth/Passwords.java", r"return IMPORTED \+ sha256Hex\(password\);",
         "A new or changed password is stored as an unsalted fast hash, and accounts imported from the old system keep theirs after logging in.",
         "Store new passwords with the salted slow hash the system already has, and upgrade an imported hash when its owner logs in; do not invalidate imported passwords.",
         "Invalidating every imported password at once is a break of compatibility (COMP-003), not a fix.", dimensions=["credenciais"]),
    flaw("SEC-015", "Alta", "Fácil", "C", "Config.java", r"getOrDefault\(\"CONDODESK_BANK_SECRET\"",
         "With the bank secret's variable unset, the configuration holds a secret written in the code and the webhook accepts events signed with it.",
         "Read the secret from the environment only: unset means no secret, and the webhook answers that it is not configured.",
         "The manifest says the variable is unset by default.", dimensions=["segredos"]),
    flaw("SEC-015.b", "Média", "Fácil", "C", "App.java", r"getOrDefault\(\"CONDODESK_DB_PASSWORD\"",
         "The database password has a value written in the code that the program uses when the variable is unset.",
         "Read the password from the environment only, with no literal default.",
         "By behavior: the application is started with no database password and the stand-in for the database records whether it sends one.", dimensions=["segredos"]),
    flaw("SEC-016", "Crítica", "Fácil", "C", "auth/AuthService.java", r"public long createUser",
         "A resident or a manager can create an account, an administrator's included, by posting the admin form.",
         "Require the admin role at the start of the account creation, answering 403 to anyone else.",
         "The admin's own creation of accounts must keep working.", dimensions=["autorizacao"]),
    flaw("SEC-016.b", "Alta", "Moderada", "C", "billing/PaymentService.java", r"public Outcome manual",
         "A resident can register a payment by hand on an invoice, their own or another building's, and settle it.",
         "Require the building's manager (or an admin) before registering a payment by hand.",
         "The manager's registration must keep working.", dimensions=["autorizacao"]),
    flaw("SEC-017", "Crítica", "Moderada", "C", "billing/BillingService.java", r"public InvoiceView view",
         "Any logged-in user can read any invoice by its number, another unit's or another building's, on the page and in the mobile API.",
         "Check that the caller lives in the invoice's unit or manages its building, answering 404 otherwise.",
         "The same service answers the page and the API, so one fix closes both.", dimensions=["autorizacao"]),
    flaw("SEC-017.b", "Crítica", "Moderada", "C", "docs/DocumentService.java", r"public Download download",
         "Any logged-in user can download any document by its number: another unit's payment proof or another building's minutes.",
         "Check that the caller may see the document (its unit's residents and the building's managers for a proof, the building's members for a building document), answering 404 otherwise.",
         "The building archive has its own manager check and is not part of this row.", dimensions=["autorizacao"]),
    flaw("SEC-018", "Crítica", "Difícil", "C", "docs/DocumentService.java", r"BLOCKED\.contains\(ext\)",
         "A page or an image of markup can be uploaded as a document and is served back as a page, so an uploaded file runs script in the application's origin.",
         "Accept only PDF, PNG and JPEG by their content and serve every download as an attachment.",
         "Either layer closes the attack: refusing the type at upload, or serving every file as an attachment.", dimensions=["arquivos"]),
    flaw("SEC-019", "Média", "Fácil", "C", "auth/AuthService.java", r"if \(counted\) throttle\.check",
         "The mobile login accepts any number of wrong passwords without slowing down or locking, so an account's password can be guessed there.",
         "Count failures and honour the lock on the mobile login as the browser's does (429 while locked).",
         "The browser's lock must stay.", dimensions=["abuso"]),
    flaw("SEC-020", "Média", "Fácil", "C", "web/Server.java", r"\"Something went wrong \(incident \" \+ incident \+ \"\): \" \+ e",
         "An internal error's answer shows the exception, including paths of the server's disk, instead of only an incident number.",
         "Answer an internal error with the generic message and its incident number, and keep the detail in the log.",
         "The incident id must stay in the message and the log.", dimensions=["exposicao"]),
]

DECOYS = [
    decoy("SEC-001.c", "diagnostico", "repo/ReportRepository.java", r"r\.unit_id in \(",
          "SQL built by joining ids",
          "The ids are numbers read from the database for the report, never text from a request."),
    decoy("SEC-003.b", "diagnostico", "web/Html.java", r"public static String error\(",
          "a request's text repeated in an error page",
          "The message is escaped when the page is written, so markup in it is shown as text."),
    decoy("SEC-007.b", "diagnostico", "web/Response.java", r"static String contentDisposition\(",
          "a header built from a file name the user chose",
          "Control characters are removed, the name is escaped for the header's two forms, and the HTTP server refuses a line break in any header."),
    decoy("SEC-015.c", "diagnostico", "auth/Passwords.java", r"private static final String DUMMY",
          "a password written in the source",
          "It is hashed once so that a login for a missing account takes as long as one for a wrong password; no login accepts it."),
    decoy("SEC-016.c", "intervencao", "web/MobileApi.java", r"\"/api/webhooks/bank\"",
          "an open route that changes data",
          "The route is open to the bank, not to sessions, and every event must carry a valid signature of the shared secret; adding a session check would break the bank's calls."),
    decoy("SEC-017.c", "intervencao", "web/Request.java", r"public long building\(\)",
          "an object chosen by a request parameter",
          "The parameter is read for an admin only; everyone else gets their own building whatever the request says."),
]
