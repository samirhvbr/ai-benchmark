#!/usr/bin/env python3
"""Builds private/matrix.json and matrix.md from the metadata below and the legacy tree (locations come from anchors, never typed by hand).
   usage: gerar_matriz.py [LEGACY_CODE_DIR]     default: build/legado     Writes next to this script."""
import json, re, sys, pathlib

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent / "variantes"))
import mapa  # noqa: E402  (which verifier method emits each verdict id)

HERE = pathlib.Path(__file__).resolve().parent
LEGACY = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else HERE / "build" / "legado"
J = "src/main/java/br/netx/chamados/"
POINTS_C = {"Crítica": 10, "Alta": 8, "Média": 6, "Baixa": 5}
POINTS_R = {"Crítica": 12, "Alta": 10, "Média": 8, "Baixa": 6}

# id, severity, difficulty, template, file, anchor (regex, first match), evidence (symptom), expected_fix, verify, notes, extras
F = []
def flaw(id, sev, dif, tpl, file, anchor, evidence, fix, verify, notes="", **extra):
    F.append(dict(id=id, sev=sev, dif=dif, tpl=tpl, file=file, anchor=anchor, evidence=evidence, fix=fix, verify=verify, notes=notes, extra=extra))

# ---- SEC (Template C)
flaw("SEC-001.a", "Crítica", "Fácil", "C", J + "repo/ChamadoRepo.java", r'c\.status = \'" \+ f\.status\(\)',
     "The status and priority filters of the ticket list are spliced into the statement text; a crafted value changes which rows come back or raises a database error.",
     "Bind the filter values as statement parameters (or validate them against the enum before use). Result sets and error behavior must not depend on the filter text.",
     "V05_SegurancaTest#sec001a",
     "Keep plain JDBC. An allowlist check against the enum is an accepted equivalent. Escaping quotes by hand is a partial fix. The ordering fragment built from the enum is NOT part of this flaw (decoy SEC-001.b).")
flaw("SEC-016", "Crítica", "Moderada", "C", J + "http/CadastroHandlers.java", r'private Resp alterarUsuario',
     "Any authenticated user of a company can change another user's role through the user update route; only authentication is checked, the role is not.",
     "Require the ADMIN role of the same company before applying a user update; other roles get 403 and the row stays unchanged.",
     "V05_SegurancaTest#sec016",
     "The check may live in a handler, a service or a filter. The company boundary (404 for other companies) must keep working.")
flaw("SEC-017.a", "Crítica", "Difícil", "C", J + "servico/ChamadoService.java", r'return chamados\.eventos\(tx, id\);',
     "The event list of a ticket is returned without checking that the ticket belongs to the caller's company, so another company's events can be read by id.",
     "Resolve the ticket within the caller's company first (404 otherwise) or filter the events by company.",
     "V05_SegurancaTest#sec017a",
     "The detail route already checks the company; the sibling route does not. Fixing only the visible route is the trap.")
flaw("SEC-015", "Alta", "Fácil", "C", "src/main/java/br/netx/chamados/App.java", r'private void garantirContaDaPlataforma',
     "On startup the application creates a platform account whose password is a literal in the source; that credential logs in.",
     "Remove the built-in credential. The first platform account must come from configuration or an operator action, never from the code.",
     "V05_SegurancaTest#sec015",
     "Reading the secret from an environment variable at startup is accepted. The verifier also looks for the literal in the main sources.")
flaw("SEC-014.a", "Alta", "Moderada", "C", J + "auth/Auth.java", r'public String hash\(String senha\)',
     "New passwords are stored as an unsalted fast digest, so equal passwords give equal stored values and the value is the plain digest of the password.",
     "Store new passwords with a salted, deliberately slow scheme (PBKDF2, bcrypt, scrypt or Argon2); keep verifying the legacy stored format so existing users can still log in.",
     "V05_SegurancaTest#sec014a",
     "Using only JDK primitives (PBKDF2WithHmacSHA256) is accepted and expected. The same digest helper is legitimately used elsewhere as a content fingerprint (decoy SEC-014.b).")
flaw("SEC-017.b", "Alta", "Especialista", "C", J + "relatorio/RelatorioService.java", r'String chave = String\.valueOf\(mes\);',
     "The SLA report is cached by month only: a company asking right after another gets the other company's numbers for the same month.",
     "Include the company in the cache key (or drop the cache); the 30-second validity must keep working.",
     "V05_SegurancaTest#sec017b",
     "The cache itself is part of the contract (manifest); removing it is accepted only if responses stay correct. Keying by company and month is the canonical fix.",
     dimensions=["consistencia"])
flaw("SEC-008", "Média", "Difícil", "C", J + "relatorio/CsvExportador.java", r'static String texto\(',
     "Text cells that begin with an equals, plus, minus or at sign are written as they come, so a spreadsheet will run them as formulas.",
     "Neutralize risky leading characters in text cells with a leading apostrophe; leave every other byte of the file unchanged.",
     "V05_SegurancaTest#sec008",
     "Numbers and dates must not get the apostrophe. The verifier compares the rest of the file byte for byte.")
flaw("SEC-020", "Média", "Moderada", "C", J + "http/CadastroHandlers.java", r'm\.put\("senha_hash"',
     "User listings and details include the stored password hash.",
     "Remove the hash from every user representation.",
     "V05_SegurancaTest#sec020",
     "Every route returning a user is checked, including the list, the detail, creation and update responses.")
flaw("SEC-013", "Baixa", "Difícil", "C", J + "auth/Auth.java", r'where s\.token = \? and s\.expira_em > \? and u\.ativo',
     "After logout the same token still authenticates: the session lookup does not honor the invalidation flag.",
     "Make the session lookup require the session to be active (and keep expiry), so a logged-out token gets 401.",
     "V05_SegurancaTest#sec013",
     "Deleting the session row on logout is an accepted equivalent.")

# ---- ARCH (Template R)
flaw("ARCH-001", "Crítica", "Difícil", "R", J + "servico/ChamadoService.java", r'public final class ChamadoService',
     "One service class concentrates ticket handling, service orders, notification fan-out, idempotency and SLA arithmetic.",
     "Split the responsibilities into cohesive collaborators with their own state; no class should concentrate them again.",
     "V04_EstruturaTest#arch001",
     "Size is not the criterion: reformatting or shortening lines, moving methods into a helper or into a base class the god class extends separates nothing, and a long cohesive class is not penalized for being long. The verifier looks at groups of methods that share no collaborator and no call, and at how many data-access classes one class depends on (the class is seen together with what it inherits); it caps R3, the judge reads the split.")
flaw("ARCH-002", "Alta", "Moderada", "R", J + "http/RelatorioHandlers.java", r'"/relatorios/status"',
     "A report route in the HTTP layer opens a database statement itself instead of going through the report service.",
     "Move the query behind the service/repository layer; the HTTP layer must not reference the JDBC API.",
     "V04_EstruturaTest#arch002",
     "The HTTP layer is detected by dependency (it uses the JDK HTTP server or a class that does), not by package name.")
flaw("ARCH-004", "Média", "Difícil", "R", J + "job/EscalonamentoJob.java", r'select email from usuario where empresa_id = \? and ativo and papel in',
     "The rule that decides who is notified when a ticket is escalated is written twice, once in the job and once in the notification path.",
     "Keep one definition of the recipient rule and call it from both places.",
     "V04_EstruturaTest#arch004",
     "The verifier looks for the same recipient query text in more than one class.")
flaw("ARCH-008", "Alta", "Difícil", "R", J + "repo/NotificacaoRepo.java", r'import br\.netx\.chamados\.servico\.ChamadoService;',
     "Persistence code depends on a service class that depends on persistence code: the packages form a cycle through a shared static helper.",
     "Break the cycle (move the shared helper to a lower-level package, or pass the value in) without merging packages.",
     "V04_EstruturaTest#arch008",
     "Merging packages to hide the cycle fails the package-count floor. The composition root package is exempt.")
flaw("ARCH-013", "Alta", "Especialista", "R", J + "db/Contexto.java", r'private static volatile DataSource dataSource;',
     "The data source of the most recently built application is kept in a static holder, so two application instances in one JVM share the last one's database.",
     "Hold the data source in instance state passed through construction; two instances must stay independent.",
     "V04_ConfiguracaoTest#arch013",
     "Making the static thread-local or per-class-loader is not a fix.")
flaw("ARCH-011", "Média", "Moderada", "R", J + "relatorio/CsvExportador.java", r'tx\.connection\(\)\.prepareStatement\(',
     "The class that formats the CSV also runs the database query for it.",
     "Separate querying from formatting: the formatter receives rows and returns text.",
     "V04_EstruturaTest#arch011",
     "The class holding the CSV header must not reference the JDBC API. The query may live in any repository class.")
flaw("ARCH-014.a", "Média", "Moderada", "R", J + "auth/Auth.java", r'Duration\.ofHours\(8\)',
     "The configured token validity and job interval are ignored: eight hours and sixty seconds are fixed in the code.",
     "Use Config.tokenValidade and Config.intervaloJobs where the fixed values are.",
     "V04_ConfiguracaoTest#arch014a",
     "Two sites: the token expiry in the authentication class and the scheduler interval in the composition root. Fixing one gives half.")
flaw("ARCH-009", "Baixa", "Fácil", "R", J + "servico/ChamadoService.java", r'multipliedBy\(8\)\.dividedBy\(10\)',
     "Business limits (an escalation fraction, a batch size, a retry base and a maximum number of attempts) appear as bare numbers inside logic.",
     "Give each limit a named constant (or configuration) with its meaning.",
     "V04_EstruturaTest#arch009",
     "The verifier counts bare numbers outside the HTTP layer and the composition root, against a ceiling calibrated on the reference. Any constant name is accepted; whether the name says what the number means is for the judge.")

# ---- BUG (Template C)
flaw("BUG-011.a", "Crítica", "Especialista", "C", J + "servico/ChamadoService.java", r'campos\.putIfAbsent\("titulo", atual\.titulo\(\)\);',
     "A partial ticket update writes back every column it read, so two simultaneous updates of different fields lose one of them.",
     "Write only the fields received (and/or lock the row for the read-modify-write); both simultaneous changes must survive.",
     "V02_ConcorrenciaTest#bug011a",
     "The interleaving is forced by state, never by sleeping, with threads in one JVM and again with two independent JVMs. A row lock on the read or a column-level update are both accepted; a lock that lives only inside one JVM is not.",
     dimensions=["concorrencia", "consistencia"])
flaw("BUG-011.b", "Alta", "Especialista", "C", J + "repo/NotificacaoRepo.java", r"where estado = 'PENDENTE' and proxima_tentativa_em <= \? order by id limit \?\"",
     "Two dispatch runs at the same time both pick up the same pending notifications and deliver them twice.",
     "Claim pending rows atomically (row lock with skip-locked, or claim by update) so each notification is delivered once.",
     "V02_ConcorrenciaTest#bug011b",
     "A file-side guard alone is not a fix. The verifier holds both runners at their first write until both have claimed or one is provably blocked, with threads in one JVM and again with two independent JVMs; a lock that lives only inside one JVM does not pass.",
     dimensions=["concorrencia", "consistencia"])
flaw("BUG-014", "Alta", "Difícil", "C", J + "job/LancamentoJob.java", r'private int lancarLote\(Instant agora\)',
     "The posting job records its resume point before doing the work, in another transaction; an interruption between the two skips orders for good.",
     "Make the resume point and the work atomic (same transaction), or derive pending work from the data instead of a stored position.",
     "V02_ConcorrenciaTest#bug014",
     "The verifier interrupts at every database command of a run and requires the retry to leave each order posted exactly once.",
     dimensions=["resiliencia", "consistencia"])
flaw("BUG-006", "Alta", "Difícil", "C", J + "relatorio/RelatorioService.java", r'ZoneId\.systemDefault\(\)',
     "The report month and the posting period use the zone of the JVM instead of the company's zone, so month boundaries move with the server.",
     "Use the company's time zone for both the report period and the posting competence.",
     "V01_NegocioTest#bug006",
     "Two sites: the report service and the posting job. The verifier runs with the JVM in a distant zone.")
flaw("BUG-007", "Média", "Difícil", "C", J + "servico/ChamadoService.java", r'isBefore\(prazoResolucao\)',
     "A ticket resolved exactly at its deadline counts as late, and a deadline that falls exactly at closing time is moved to the next working day.",
     "Make the deadline inclusive and keep a deadline that lands on the end of the working window at that instant.",
     "V01_NegocioTest#bug007",
     "Two boundary conditions in one rule; both must hold.")
flaw("BUG-013", "Média", "Moderada", "C", J + "relatorio/RelatorioService.java", r'RoundingMode\.DOWN',
     "Percentages and hour averages are truncated instead of rounded half up: two of three shows 66.6.",
     "Round half up to one decimal everywhere the manifest says so.",
     "V01_NegocioTest#bug013")
flaw("BUG-009", "Média", "Fácil", "C", J + "dominio/Dominio.java", r'case RESOLVIDO -> para == FECHADO;',
     "A resolved ticket cannot be reopened for attendance although the contract allows it, and the resolution time is not cleared.",
     "Allow the reopening transition and clear the resolution timestamp when it happens.",
     "V01_NegocioTest#bug009")
flaw("BUG-015", "Baixa", "Moderada", "C", J + "db/Db.java", r'super\(causa\.getMessage\(\)\);',
     "When a database call fails, the error log of the failure no longer shows what actually went wrong; the original cause is dropped.",
     "Keep the original exception as the cause of the wrapper (or log it) so the failure record shows it; the client still gets a generic 500.",
     "V07_DiagnosticoTest#bug015",
     "The response must stay generic. The cause must reach the server-side record.",
     dimensions=["resiliencia"])

# ---- PERF (Template C)
flaw("PERF-001", "Alta", "Moderada", "C", J + "repo/ChamadoRepo.java", r'select nome from cliente where id = \?',
     "Listing tickets runs extra statements for each row to fetch the client and technician names.",
     "Fetch the names in the list statement (join) or in one batched statement; commands per request must not grow with the page size.",
     "V03_DesempenhoTest#perf001")
flaw("PERF-003", "Alta", "Fácil", "C", J + "repo/OrdemRepo.java", r'todas\.subList',
     "The service order list reads the whole table and slices the page in memory.",
     "Paginate in the statement (limit and offset or keyset); rows read must not grow with the table.",
     "V03_DesempenhoTest#perf003")
flaw("PERF-005", "Alta", "Difícil", "C", J + "relatorio/RelatorioService.java", r'tecnicosAtivos\(tx, empresaId\)',
     "The technician productivity report runs one statement per technician.",
     "Aggregate in one statement; commands per request must not grow with the number of technicians.",
     "V03_DesempenhoTest#perf005")
flaw("PERF-006", "Média", "Moderada", "C", "schema.sql", r'create table chamado \(',
     "The tables are filtered by company, status and parent ticket on every request but carry no secondary index.",
     "Add an additive migration with indexes whose first column is the company on tickets and the ticket on service orders.",
     "V03_DesempenhoTest#perf006",
     "Only additive migrations are allowed (manifest). A composite index leading with the column is accepted.")
flaw("PERF-011", "Média", "Difícil", "C", J + "job/EscalonamentoJob.java", r'chamados::emAndamento',
     "The escalation pass loads every open ticket in full, descriptions included, so memory grows with the number of tickets.",
     "Read only the columns the pass needs (or stream); a pass over twenty thousand tickets of 5 KB must fit in a 48 MB heap.",
     "V03_DesempenhoTest#perf011",
     "The verifier runs a child JVM with a small heap.",
     dimensions=["resiliencia"])
flaw("PERF-012", "Média", "Especialista", "C", J + "job/DespachoJob.java", r'chaves\.contains\(chave\)',
     "The dispatch pass searches a growing list for every notification, so its cost grows with the square of the backlog.",
     "Use a hash-based set (or push the check into the database) so the pass grows linearly.",
     "V03_DesempenhoTest#perf012",
     "Measured as processor time of the pass at two sizes, as a ratio, not as an absolute time.")
flaw("PERF-013", "Baixa", "Moderada", "C", J + "servico/ChamadoService.java", r'c = chamados\.travar\(tx, c\.id\(\), c\.empresaId\(\)\)\.orElseThrow\(\);',
     "Completing a service order reads the same ticket row twice in one request.",
     "Reuse the row already read.",
     "V03_DesempenhoTest#perf013")

# ---- CLN (Template R)
flaw("CLN-007", "Média", "Moderada", "R", J + "servico/ChamadoService.java", r'public Visao transicionar\(',
     "The ticket transition method nests decisions five levels deep, which hides the main flow.",
     "Flatten with guard clauses or extracted methods; nesting of decisions at most four levels in any method.",
     "V04_EstruturaTest#cln007")
flaw("CLN-001", "Média", "Moderada", "R", J + "job/DespachoJob.java", r'public int executar\(\)',
     "The dispatch job is one long method that reads, deduplicates, writes and handles failures inline.",
     "Split into named steps; no method over forty useful lines.",
     "V04_EstruturaTest#cln001")
flaw("CLN-002", "Baixa", "Fácil", "R", J + "http/ChamadoHandlers.java", r'private static int pagina\(Req r\)',
     "The pagination parameter parsing is copied in two handler classes.",
     "Keep one copy and call it from both.",
     "V04_EstruturaTest#cln002")
flaw("CLN-005", "Baixa", "Fácil", "R", J + "http/Servidor.java", r'// servidor\.setExecutor\(Executors\.newCachedThreadPool\(\)\);',
     "A block of disabled statements is left in the server startup code.",
     "Delete the commented-out code.",
     "V04_EstruturaTest#cln005")

# ---- decoys
D = []
def decoy(id, kind, file, anchor, looks, why, verify):
    D.append(dict(id=id, kind=kind, file=file, anchor=anchor, looks=looks, why=why, verify=verify))

decoy("SEC-001.b", "diagnostico", J + "repo/ChamadoRepo.java", r'private static String ordenacaoPorPrioridade',
      "text built by concatenation inside a statement", "The concatenated text is made from the constants of an enum, never from input; there is nothing to inject.", "V06_IscasTest#d1")
decoy("SEC-014.b", "diagnostico", J + "servico/ChamadoService.java", r'Auth\.sha256Hex\(corpo',
      "a fast digest used for secrets", "A digest of the request body used as a content fingerprint for idempotency, not a password hash.", "V06_IscasTest#d2")
decoy("ARCH-014.b", "diagnostico", J + "servico/ChamadoService.java", r'PADROES = new EnumMap',
      "hard-coded configuration", "The default SLA table is the contract (manifest); moving it would change documented behavior.", "V06_IscasTest#d3")
decoy("PERF-015", "intervencao", J + "job/LancamentoJob.java", r'pg_advisory_xact_lock',
      "needless blocking", "The lock keeps one posting per order when two runners meet; removing it without another guard duplicates postings.", "V06_IscasTest#i1")
decoy("ARCH-006", "intervencao", J + "servico/ChamadoService.java", r'public Visao abrir\(',
      "coupling of an event to its notifications", "Notifications are written in the same transaction as the event so they only exist when the operation succeeds; splitting them breaks all-or-nothing.", "V06_IscasTest#i2")

VERIFICADORES = mapa.verificadores()


def verify_of(id):
    classe, metodo = VERIFICADORES[id]
    return f"verify/src/test/java/br/netx/chamados/{classe}.java#{metodo}"


def line_of(file, anchor):
    path = LEGACY / file
    for n, linha in enumerate(path.read_text(encoding="utf-8").split("\n"), 1):
        if re.search(anchor, linha):
            return n
    sys.exit(f"anchor not found: {file} /{anchor}/")

entries = []
for f in F:
    pts = (POINTS_C if f["tpl"] == "C" else POINTS_R)[f["sev"]]
    e = {"id": f["id"], "exists": True, "severity": f["sev"], "difficulty": f["dif"], "points": pts, "template": f["tpl"],
         "location": f"code/{f['file']}:{line_of(f['file'], f['anchor'])}", "evidence": f["evidence"], "expected_fix": f["fix"],
         "verify": verify_of(f["id"]), "proves": ["C3"] if f["tpl"] == "C" else ["R3"]}
    if f["notes"]:
        e["notes"] = f["notes"]
    e.update(f["extra"])
    entries.append(e)
for d in D:
    entries.append({"id": d["id"], "exists": False, "decoy_kind": d["kind"], "verify": verify_of(d["id"]),
                    "location": f"code/{d['file']}:{line_of(d['file'], d['anchor'])}",
                    "notes": f"DECOY. Looks like {d['looks']}. {d['why']} Reporting it is PEN-004; changing it so that the property breaks is a regression."})

# ---- public surface, taken from the reference and the schema (they are the contract)
ref = HERE / "reference"
routes = sorted({f"{m} {c}" for m, c in re.findall(r's\.rota\("([A-Z]+)", "([^"]+)"', "".join(p.read_text(encoding="utf-8") for p in (ref / J).glob("http/*.java")))})
schema = (ref / "schema.sql").read_text(encoding="utf-8")
tabelas = []
for nome, corpo in re.findall(r"create table (\w+) \((.*?)\n\);", schema, re.S):
    if nome in ("sessao", "job_checkpoint", "chave_idempotencia"):
        continue
    colunas = [l.split()[0] for l in corpo.strip().split("\n") if l.strip() and not l.strip().startswith(("--", "primary", "unique", "foreign"))]
    tabelas.append(f"{nome}({','.join(colunas)})")
matrix = {
    "leb_spec": "1.4.0", "instance": "LEB-300-A", "level": 300, "version": "1.0", "language": "java21-maven-postgresql",
    "task_version": "1.2.0",
    "publication": "aggregate",
    "public_surface": {
        "symbols": ["br.netx.chamados.App.create(Config,DataSource,Clock):App", "br.netx.chamados.App.start():int", "br.netx.chamados.App.stop():void",
                    "br.netx.chamados.App.jobs():JobRunner", "br.netx.chamados.JobRunner.executarUmaVez():void",
                    "br.netx.chamados.Config(int porta,boolean agendadorAtivo,Duration intervaloJobs,Path notificacaoDir,Duration tokenValidade)"],
        "routes": routes,
        "db_schema": tabelas,
        "artifacts": ["CSV header: ID;Titulo;Cliente;Prioridade;Status;Aberto em;Prazo resolucao;Resolvido em;Tecnico;SLA cumprido",
                      "notification file: one JSON line per recipient in <notificacaoDir>/<empresa_id>.jsonl with chave, destinatario, assunto, corpo, enviada_em"],
    },
    "entries": entries,
}
(HERE / "matrix.json").write_text(json.dumps(matrix, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

linhas = ["# LEB-300-A matrix (private)", "", f"{len([e for e in entries if e['exists']])} planted flaws, {len([e for e in entries if not e['exists']])} decoys. Generated by `gerar_matriz.py`.", "",
          "| ID | Exists | Severity | Difficulty | Points | Template | Location | Verifier |", "|---|---|---|---|---:|---|---|---|"]
for e in entries:
    linhas.append(f"| {e['id']} | {'yes' if e['exists'] else '**no (decoy)**'} | {e.get('severity', '-')} | {e.get('difficulty', '-')} | {e.get('points', 0)} | {e.get('template', '-')} | `{e['location']}` | `{e['verify']}` |")
por_cat = {}
for e in entries:
    if e["exists"]:
        por_cat.setdefault(e["id"].split("-")[0], 0)
        por_cat[e["id"].split("-")[0]] += e["points"]
linhas += ["", "Base points per category: " + ", ".join(f"{k} {v}" for k, v in sorted(por_cat.items())), ""]
(HERE / "matrix.md").write_text("\n".join(linhas), encoding="utf-8")
print(f"matrix.json: {len(entries)} entries ({len(F)} flaws, {len(D)} decoys); points {sum(e.get('points', 0) for e in entries)}")
