# Versão — AI-BENCHMARK

**Versão atual:** `0.2.192`

Padrão de avaliação de engenharia de software para LLMs (spec RFC, instâncias LEB, harness e scorecard).

> ⚠️ The **RFC spec has its own version** (today `1.5.1`, declared in `README.md`), and so
> do the instances (LEB-100-A `v1.1`). This file versions the **repository**, not the
> spec: the numbers are independent on purpose.

> Este arquivo é a **fonte da verdade** da versão do projeto: quem precisar exibir ou
> reportar a versão extrai o **primeiro número semver (`X.Y.Z`)** encontrado aqui.
> Mantenha a linha **"Versão atual"** como a primeira ocorrência de um número.
>
> `0.1.0` marca o início do **versionamento**, não o início do projeto — o que veio
> antes continua no `git log`.

---

## 1. Convenção de Versionamento (`X.Y.Z`)

| Componente | Significado | Como sobe |
|---|---|---|
| **X** | Release estável | Manual |
| **Y** | Mudança estrutural — Nova seção do conjunto, mudança de escopo, reorganização estrutural. | Manual |
| **Z** | Incremento a cada entrega (ver gatilhos) | A cada entrega |

### Gatilhos de bump do `Z`

- Criar ou remover um **documento** do conjunto.
- Mudar uma **regra, decisão ou procedimento** já publicado.
- Alterar **estrutura ou formato** que outra ferramenta consome.
- Adicionar ou alterar **dado/exemplo** que serve de referência.

> Correção de texto, comentário e formatação **não** exigem bump.

---

## 2. Formato de Commit Obrigatório

```
X.Y.Z - Short description in English (US)
```

**Non-negotiable rules:**

1. The version **always** comes from this `version.md`, bumped **in the same commit** as the change.
2. The message is in **English (US)** (house rule since 2026-09-03; the Portuguese messages
   already in the log stay), specific enough for `git log --grep`.
3. Conventional Commits (`feat:`, `fix:`, `chore:`…) and vague messages ("update", "wip")
   are **forbidden**.
4. One subject per commit.

> **Whoever does the work commits it.** The COMMITTER skill is off in this repository
> (`enabled: false` in `.committer.yml` since 2026-09-03): the agent writes the changelog
> entry below first, and its heading is the commit subject. Details in the commits block of
> `CLAUDE.md`.

---

## 3. Changelog

> Ordem decrescente (mais recente no topo).

### `0.2.192` — 2026-10-13 — LEB-D-300-A's planted tree is written to public/code/, and its public suite passes on it; no key yet

- `instances/LEB-D-300-A/public/code/` is the output of the five planting modules on the corrected reference (`tools/plant.py`),
  without `.gitignore`; `public/manifest.md` is the system's manifest, unchanged. Its main code differs from the reference only in
  the files the catalog's rows touch; `pom.xml` and the test sources are the reference's, but one test.
- `BillingTest.aStatementWithABadLineRecordsNothing` becomes `aMalformedStatementIsRefused`: its first part sees BUG-014 and leaves
  the public suite, recorded with its full body in `private/removed-from-public-tests.txt`; its last assertion stays. The
  untrimmed test fails on the planted tree and passes on the reference.
- On the planted tree the public suite passes, 70 of 70, `SecurityBehaviorTest` included. Sampled every half second during that
  run, the database never held more than 4 connections (limit 200), so BUG-003's leak does not disturb the suite; the catalog's
  §8 says so, and its §10 names the modules as they are.
- The instance is listed by `./leb instancias` and is not runnable: it has no matrix, verifiers or variant cases yet, and
  `./leb pacote` refuses it (0.2.191). Its README, the instance index and `systems/300-A/README.md §9` say so.

### `0.2.191` — 2026-10-13 — `leb pacote` refuses an instance that has its private folder but no matrix: it is still being authored

- `harness/pack.py`: an instance with a `private/` folder and no `private/matrix.json` is refused, with a message that says it is
  still being authored and not runnable (`matrix/MATRIX.md §4`). Until now the packager only warned and went on, binding the
  package to no key, to no level or version, and to the default task 1.0.0, the full LEB's, which asks for security work. With
  `LEB-D-300-A`'s planted tree about to land in `public/code/`, `./leb pacote LEB-D-300-A` would have built such a package.
- Unchanged: a public copy without a `private/` folder still packages with the warning, and `--matrix-sha` or `--instance-id`
  still packages on purpose. LEB-100-A and LEB-300-A package as before.
- `tests/test_pack_tarefa.py`: the refusal, the override that bypasses it, and the public copy that still packages.

### `0.2.190` — 2026-10-13 — LEB-D-300-A plants its god class last: billing, payments and the money rules merged into one FinanceService

- `instances/LEB-D-300-A/private/plant/flaws_z_god.py` plants ARCH-001 of the catalog (§4). Its edits repoint the callers (`App`,
  `Routes`, `FinancePages`, `MobileApi`, `ReportService`) to one `FinanceService`; its post-hook writes that class from the
  planted texts of `BillingService`, `PaymentService` and `Charges` (their imports merged, their methods carried over unchanged)
  and deletes the three. The module's name makes it run after every other group, so BUG-013, BUG-014, BUG-007 and PERF-015 are
  planted where they belong and then moved, as they are, into the merged class. Before merging, the hook checks that each source
  still declares what the merged class restates.
- The merged class has 267 lines: the billing run, the invoice list and view, the statement import, the webhook and its
  signature, the settlement, and the money rules.
- All 27 rows are now planted. The full tree compiles and, of the 70 public tests, only
  `BillingTest.aStatementWithABadLineRecordsNothing` fails, the one the catalog (§8) says sees BUG-014.

### `0.2.189` — 2026-10-13 — LEB-D-300-A plants its four code-quality flaws, each measured against the limit the catalog declares

- `instances/LEB-D-300-A/private/plant/flaws_d_cln.py` plants the CLN rows of the catalog (§5): CLN-001 inlines the filters, the
  sort headers, the table and the pager into `FinancePages.list`, which grows to 80 non-blank lines against the declared 45 (the
  helper it no longer calls goes with it); CLN-007 writes `BookingService.cancel` as five nested `if`s; CLN-002 copies
  `FinancePages.enumOf` into `MobileApi` and `ContentPages` with the same body; and CLN-005 leaves a commented-out handler and
  route in `AdminPages`.
- Measured on the planted tree: the longest method has 80 non-blank lines, the deepest nesting of `if` is 5, and three methods
  share one body; on the reference the same measures give 35, 2 and none. The catalog's "about 75" for CLN-001 becomes 80.
- With every earlier group the tree compiles and the public suite still fails only on the test that sees BUG-014.

### `0.2.188` — 2026-10-13 — LEB-D-300-A plants seven of its eight architecture flaws; the god class of ARCH-001 comes last, on its own

- `instances/LEB-D-300-A/private/plant/flaws_c_arch.py` plants the ARCH rows of the catalog (§4) except ARCH-001: ARCH-002 moves
  the collection report into its page (SQL, the settled-share rule and the HTML in one handler; the service method, the repository
  query and `Charges.rate` go), ARCH-003 gives the notice service SQL of its own beside the repository, ARCH-008 moves the
  admin's building totals record into the report service so that `repo` and `reports` depend on each other, ARCH-013 keeps the
  data source in a static field of `Db`, ARCH-004 writes the amount-due rule a second time on the resident's dashboard, ARCH-009
  turns the notice limits into bare literals, and ARCH-010 adds the unused parser of the bank's old CNAB return file.
- Checked with the bugs and the performance flaws: every edit matches once, the tree compiles, and the public suite still fails
  only on the test that sees BUG-014.

### `0.2.187` — 2026-10-13 — LEB-D-300-A plants its seven performance flaws, none of which changes what a page or the API returns

- `instances/LEB-D-300-A/private/plant/flaws_b_perf.py` plants the PERF rows of the catalog (§3): PERF-001 reads the owners of
  each late invoice's unit by one query per invoice, PERF-003 reads every matching invoice and keeps a page in memory, PERF-010
  keeps the admin's building totals for the life of the application, PERF-006 drops the index on `payment(invoice_id)`, PERF-012
  joins users and units in memory with nested loops, PERF-015 makes the webhook `synchronized`, and PERF-009 loads every overdue
  invoice to count them. Repository methods that only the reference's versions used go with them, so that no planted row leaves
  dead code behind.
- Checked with the bugs of 0.2.186: every edit matches once, the tree compiles, and the public suite still fails only on the test
  that sees BUG-014.

### `0.2.186` — 2026-10-13 — LEB-D-300-A's planting starts with its eight bugs, each an exact edit on the corrected reference

- `instances/LEB-D-300-A/private/plant/flaws_a_bugs.py` plants the BUG rows of the catalog (§2) with `tools/plant.py`, and
  `plant/paths.py` holds the source path the modules share. BUG-011 removes the unit's row lock (and its now unused repository
  method), BUG-006 dates the overdue block on the clock's zone, BUG-013 counts days late with `Period.getDays`, BUG-014 settles
  each statement line in its own transaction, BUG-007 compares the paid total with `equals`, BUG-003 closes a read's connection
  only when the work returns, BUG-010 drops the check of `tar`'s exit status, and BUG-015 logs an incident without its exception.
- Checked: every edit matches once, the planted tree compiles, and of the 70 public tests only
  `BillingTest.aStatementWithABadLineRecordsNothing` fails, the test the catalog (§8) says sees BUG-014. The suite's leaked
  connections did not reach the database's limit.
- The legacy tree is not written yet: it comes, with the public suite, once every group is planted.

### `0.2.185` — 2026-10-13 — System A's reference is corrected where the review of the LEB-D-300-A catalog found it unclean, before either instance is planted

- **Dates on the building's calendar (R1).** The collection report's default year, the date in the delinquency CSV's file name,
  a notice's date and a document's "Added" date were the UTC date: from 21:00 in São Paulo they showed the next day (and, on
  December 31, the next year). `ReportService` now gives the pages the building's date (`YearCollection`, `DelinquencyCsv`), and
  `Notice.publishedOn` and `StoredDocument.createdOn` come from the database already converted to the building's zone.
  `ReportPages` no longer needs the clock, and `Routes.register` loses that parameter.
- **Guests refused beyond an `int` (R2).** The mobile API and the booking form cast the number of guests from `long` to `int`:
  4,294,967,297 guests became 1 and passed the capacity check. Such a value now answers 400.
- **A count query for the manager's overdue invoices (R3)**, instead of loading them all to take the list's size.
- **Six members nothing called (R4)** are removed: `Response.text`, `FinanceRepository.unmatched`, `BuildingRepository.all` and
  `area`, `DataAccessException.sqlState` and `isUniqueViolation`. The system page's "kept for the report" becomes "recorded without
  an invoice", which is what the code does.
- The 70 characterization tests pass. A throwaway test, kept out of every suite, failed on the reference before these changes
  and passes after them for R1 and R2. Main code: 3,921 lines (3,418 not blank) in 41 files. The manifest does not change: none
  of these behaviors is part of the contract.
- `instances/LEB-D-300-A/private/CATALOG.md §9` and `systems/300-A/README.md §9` record the corrections as made.

### `0.2.184` — 2026-10-13 — LEB-D-300-A has its catalog: 27 maintenance flaws and 4 decoys on CondoDesk, written before any planting code

- `instances/LEB-D-300-A/private/CATALOG.md`: the planting profile of the Default instance of system A, on paper. ARCH 8, BUG 8,
  PERF 7, CLN 4, no SEC (LEB-300-A's counts without its security rows), and 4 decoys, 12.9 % of the rows. Each row gives its
  site in the reference, the mechanism, and the probe (Template C) or the name-independent property (Template R) its verifier
  will check; the PERF and race probes count commands and rows, or hold a transaction at a gate, through the test hook
  `TestBase.forTheApp`.
- Its rules: no row weakens a defense (`SecurityBehaviorTest` stays public and passes on the planted tree, and no row touches an
  escaping, a check, an allowlist or the parser's configuration); one line, one flaw, with a section on where rows meet; decoys
  carry taxonomy ids from the start (LEB-300-A's catalog drifted from its matrix on exactly that); one public test loses the part
  that sees BUG-014.
- Writing it was also a review of the reference, which found four things to correct before either instance is planted: dates
  shown in UTC instead of on the building's calendar, a `long` narrowed to `int` for the guests of a booking, a count that loads
  every overdue invoice, and six members nothing calls. Four patterns are accepted as they are, with their reason, so that a
  report of one is judged by a rule fixed before any run.
- `instances/LEB-D-300-A/README.md` gives the profile's counts; `systems/300-A/README.md §9` marks the catalog done and lists what
  follows. No code, matrix, verifier, rule, weight or result changes.

### `0.2.183` — 2026-10-13 — A generic planting engine plants any instance's flaws from its profile, and plants LEB-300-A's legacy tree byte for byte

- `tools/plant.py PROFILE_DIR REFERENCE_DIR OUT_DIR [--only ID,ID] [--check]`: the engine of LEB-300-A
  (`instances/LEB-300-A/private/plant/plantar.py`), made generic for the next series (`systems/300-A/README.md §7`, item 3). The
  edits mean what they meant there: an `old` text must occur exactly once in the file as the earlier edits left it, `None`
  creates a file, `"*"` replaces it, a `None` replacement deletes it, and `POS` hooks run after every edit. What changed: the
  profile folder is an argument and goes on `sys.path` (a profile keeps its paths in a module of its own, so no `SRC` is fixed
  in the engine), `--only` refuses an id the profile does not define, and the messages are in English.
- `tests/test_plant.py`: one synthetic profile per kind of edit and per refusal, and the equivalence: from LEB-300-A's reference
  and profile, the engine writes a `src/` identical to that instance's `private/build/legado/src/`, file by file.
- LEB-300-A's own engine stays in its instance, unchanged: the instance is immutable since its commit.
- No instance, rule, weight or result changes.

### `0.2.182` — 2026-10-13 — A review of the new series' documents brings them up to date: what stands before a first run, the reference's size, the spec version and the commit rule

- The owner asked whether the runs of `LEB-D-300-A` could start. They cannot: the instance is a README, with no planted tree, key,
  verifier or package. The review found that the status pages did not say so in full.
- `systems/300-A/README.md` §9 lists two blockers it did not track: the task statement of each track (`protocol/TAREFA.md` and
  its versions ask one delivery for security and maintenance together, and design §4.1 forbids asking the Default track for
  security work) and the evaluator of the new series (design §12 step 2). A closing paragraph says what an instance's first run
  needs on top of them: a clean package, the whole instance committed and pushed before the run (`matrix/MATRIX.md §4`), and a
  bench snapshot holding the package.
- The same page no longer says that the instances do not exist (their folders do, since 0.2.178), and §1 gives the reference's
  size as it is since the fixes of 0.2.174 and 0.2.175: 3,914 lines of main code, 3,410 not blank, in 41 files (it said 3,887 and
  3,385).
- `instances/LEB-D-300-A/README.md` and `instances/LEB-S-300-A/README.md` say that the instance cannot run yet and point at that
  list, kept in one place.
- `version.md`: the header gives the spec as 1.5.1 (it said 1.4.0), and §2 states the commit rule in force: the message in
  English (US) since 2026-09-03, and the agent commits its own work because COMMITTER is off (`enabled: false` in
  `.committer.yml`). Both passages are now in English.
- Documents only: no rule, tool, weight, instance file or result changes.

### `0.2.181` — 2026-10-12 — The second run of Claude Sonnet 5.5 at max is voided because the agent's own Opus 5.5 subagents wrote part of it: the agent is back to 932, with one run

- `results/2026/LEB-300-A/aggregate.json`: Claude Sonnet 5.5 at max goes from two runs (932 and 864) back to one, 932 (Platinum). The published score rises from 864 to 932, the agent stays 1st, its lead over Claude Sonnet 5.5 at xhigh (860) goes from 4 points to 72, and it is still not official (1/3, PROTOCOL §4). The board has a Platinum again. No other agent's score or place moves.
- **Why void.** In run 2 the agent launched four read-only subagents of its own accord with the client's Agent tool (`model: "opus"`), two in each stage. They ran on Claude Opus 5.5 and wrote 122 of the session's 543 responses; Claude Sonnet 5.5 wrote the other 421 (counted by message id: the "882 messages" of the 0.2.163 note counted events, not responses). The audit files the session as SWITCHED, and a delivery with another model in it does not count as the requested model's work (`docs/new-series-design.md` §7).
- **Why now, and why it is not a retry.** The run was scored and published (0.2.163), with a note about the subagents, before the owner decided that anything the audit files as OPERATOR ERROR or SWITCHED waits for his decision. He decided that this run is void, as the void runs of Claude Fable 5.1 on LEB-100-A are: its folder in the private archive becomes `void-1`, with a `VOID.md` that says it was scored first. The criterion is the record, never the result: voiding it raises the agent's score, and the same record would have voided a run with a higher total. A void run holds no run number, so the agent's next run is its run 2.
- The reading of the agent is rewritten for one run and says that a second was voided. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.180` — 2026-10-12 — The run of Claude Sonnet 5.5 filed as high that ran at medium is withdrawn: the agent keeps its score, 855, with two runs

- `results/2026/LEB-300-A/aggregate.json`: Claude Sonnet 5.5 at high goes from three runs (897, 763 and 855) to two (897 and 855), still numbered 1 and 3. The published score is the lowest of two, 855, the same number as before because it was the median of three; the agent keeps its place, 3rd, and is no longer official (2/3, PROTOCOL §4). No other agent's score or place moves.
- **What was wrong.** Run 2 was filed as `high`, but its session log shows it ran at `medium`: before the first message the operator typed `/model` Fable 5.1, `/effort high`, `/model` Sonnet 5.5 and `/effort medium`, and all 40 responses of its main conversation carry `effort: medium` (`tools/auditar-tentativa.py` files it as OPERATOR ERROR). The filing copied the model block of run 1 and compared it with nothing. Every other Claude run, published or held, was checked against its responses the same way, and none differs; the operator's collection tool now refuses a run whose responses carry another effort than the declared one.
- **Why withdrawn, and why it is not a retry.** A record that is not true to its session log is what `PROTOCOL §4` item 5 withdraws: the folder in the private archive becomes `withdrawn-2` with a `WITHDRAWN.md`, kept for audit and not counted toward the three of item 4, so the agent may run again (its next run is `run-4`). The criterion is the record, never the result: it was the lowest of the agent's three totals, and the same rule would have withdrawn a higher one. It cannot be refiled as a run of the `medium` agent, which already has three.
- `protocol/PROTOCOL.md` §4 item 5 now covers it: a record that the session log contradicts, in model or in effort, is withdrawn the same way, and the effort of a run is what the client writes on its responses, never the commands typed before the first message.
- `docs/new-series-design.md` §1 item 3 no longer quotes the withdrawn run: it gives the per-run totals of three agents (Claude Opus 5.5 at xhigh 822–886, Claude Sonnet 5.5 at high 855–897, Claude Opus 5.5 at high 848–866) as of this version, and compares their spread with the gaps between places 2 and 6.
- The reading of the agent is rewritten for two runs, and says that a run was withdrawn. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No public tool, weight, penalty or test changes.

### `0.2.179` — 2026-10-12 — Spec 1.5.1: the documents call the matrix what it is, the answer key published with its instance, which the bench machines cannot reach

- The owner restated the rule: the answer key, and every key to come, is published on GitHub with its instance, and that is why
  the bench VMs cannot reach GitHub. Several documents still described the key as hidden, secret or private, from before 1.5.0.
- `SPEC.md` 1.5.1 (PATCH, editorial): §1 and §5 call the matrix the answer key, public with its instance before the first run, never
  seen by the agent during a run because the runs happen on machines that cannot reach GitHub; release notes added. No rule
  changes.
- `matrix/MATRIX.md` (opening), `README.md` (how it works, the documents table, the LEB-100-A status line, spec 1.5.1),
  `README_br.md` (the same two passages; its spec version, stale at 1.4.0), `BENCHMARK.md` (the summary and the split between
  what the model receives and what only the evaluator uses) and `instances/README.md` (the `private/` label) say the same.

### `0.2.178` — 2026-10-12 — The two instances of system A have their folders in instances/ from now on, before any of their files exist

- `instances/LEB-D-300-A/README.md` and `instances/LEB-S-300-A/README.md`: what each instance is, the system it is built from, its
  status (in authoring: no package, no key, no run) and the layout it will have. By the owner's rule, an instance is in this
  folder while it is being thought, and every file lands here as it is written; the folders hold no `public/code/` yet, so the
  harness does not list them as instances.
- `instances/README.md`: the index lists both, and its LEB-300-A row now links the published instance with its stack and counts.
- `systems/README.md` says both folders exist from the day a system is specified.

### `0.2.177` — 2026-10-12 — The candidate VM guide says how a run is closed: collect, compare with the VM, then restore the clean snapshot by name

- `docs/candidate-vm.md` §9 gains *Closing a run*: the run is over (`/exit`, no runner process), the delivery and every transcript are
  brought to the workstation and compared file by file (SHA-256) with the VM's copy, and only then is the VM powered off and
  restored to its clean snapshot, named, never "the latest", which can be a snapshot taken after a run. The key is applied on the
  workstation after the comparison. The order exists because the restore destroys the only other copy of the run.

### `0.2.176` — 2026-10-12 — System A gets its 70 characterization tests and its manifest

- `systems/300-A/reference/src/test/`: six classes, 70 tests, all passing against a disposable PostgreSQL. Each test gets its own
  schema, a clock it moves, two buildings with their people and areas, a site on the fetch allowlist and an internal server that
  is not on it. `AccountTest`, `BillingTest`, `BookingTest`, `ContentTest` and `StaticFilesTest` characterize the system (among
  them eight concurrent requests for one booking slot, of which exactly one succeeds); `SecurityBehaviorTest` checks the
  reference's defense for each of the 20 security classes. Three defects planted by hand in a copy (the area lock removed, a
  notice not escaped, the budget split rounding each share) each made their test fail; the race failed in three runs out of
  three.
- `systems/300-A/manifest.md`: the contract both instances will ship: the Java surface, every page and API route with who may
  use it, the business rules, the CSV and archive formats, the database and the configuration. It states the access rules, as
  LEB-300-A's did, and leaves out what would point at a planted vulnerability (formula neutralization in the CSV, the upload
  check by content, the webhook's answer without a secret).
- `systems/300-A/README.md` §8 says how to run the tests from an empty Maven cache: one online run of the test that needs no
  database fetches everything, Surefire's JUnit provider included, which a run of zero tests does not.

### `0.2.175` — 2026-10-12 — System A's reference accepts accounts imported with the previous system's SHA-256 hash and upgrades them at login

- `Passwords.verify` also accepts `sha256:<hex>`, the unsalted hash of accounts imported from the system CondoDesk replaced, and
  `AuthService.authenticate` stores a PBKDF2 hash in its place after the first successful login. That is the canonical fix of
  SEC-014 (`taxonomy/SEC.md`: a hash with cost, migrated at login, without invalidating every password at once), so the clean
  reference has it.
- It is also what lets one characterization suite serve both instances: the tests create their users with the imported hash,
  which a legacy tree with a weak hash and a fixed tree both accept. `systems/300-A/README.md` §5 says so in the SEC-014 row.

### `0.2.174` — 2026-10-12 — System A's reference no longer fails on an invoice list without a sort or on a column read as null, two defects its first tests found

- `FinanceRepository.search` looked the sort up in an immutable map, which throws on a null key: every invoice list without
  `sort` answered 500. It now falls back to the month when no sort is given.
- `Db.one` wrapped the mapped value in `Optional.of`, which throws on null: the failed-login check read a null `locked_until` and
  every login answered 500. It now treats a null value as absent, and `LoginThrottle` reads it that way.

### `0.2.173` — 2026-10-12 — System A's reference code is written, with the series' scripts that build it offline and give it a disposable database

- `systems/300-A/reference/`: CondoDesk, the clean system both instances of system A will be planted from. 41 Java files, 3,887
  lines: the JDK HTTP server with browser sessions (CSRF token, a new session at login, idle expiry), mobile API tokens, failed
  login counting, PBKDF2 passwords; the billing run (largest-remainder split), late fee and pro rata interest, payments from OFX
  statements, the bank webhook (HMAC) and by hand; bookings decided under a row lock; notices; documents checked by content,
  stored under generated names, fetched only from allowlisted hosts and archived with `tar` run without a shell; the
  delinquency report (page and CSV) and the collection report. Same dependencies as LEB-300-A. It compiles offline; its
  characterization tests are the next step, so it is not yet tested.
- `harness/java/mvn.sh` runs Maven in a pinned container against a local repository used as the offline cache; `harness/java/dev-db.sh`
  keeps a disposable PostgreSQL 16 on an internal Docker network. Their container and network names are their own, so they never
  touch another evaluation.
- `systems/300-A/README.md`: the real size (above level 300's 3,000 lines, because the security classes need both the browser
  pages and the mobile API), the archive open to the building's manager, and the authoring status.

### `0.2.172` — 2026-10-12 — The next series names its instances and specifies its first reference system, a condominium management application

- `docs/new-series-design.md` §2 records two decisions the owner took today: the instances are named `LEB-D-<level>-<letter>`
  (Default) and `LEB-S-<level>-<letter>` (Security), the same letter being the same system; and system A of level 300 is a
  condominium management application in Java 21, Maven and PostgreSQL, the toolchain the bench VMs carry, written in English.
  §5 says where the systems live, §12 marks step 3 started, and §13 drops the question of names.
- `systems/README.md`: what a reference system is and how its two instances come from it.
- `systems/300-A/README.md`: the specification of CondoDesk, the system the reference is written against: constraints (LEB-300-A's
  exact dependencies, so the bench VMs' offline Maven cache serves it unchanged), roles, data, behavior, the place where each of
  the 20 security classes meets the reference and how the reference defends it, the hosts for the Default profile, how the
  instances are built, and the authoring status. It also records why this series publishes its evaluator scripts: LEB-300-A's
  never reached the repository.
- `README.md` lists `systems/` among the documents.

### `0.2.171` — 2026-10-12 — LEB-300-A is published with its answer key, by the owner's order

- `instances/LEB-300-A/`: `public/` (code and manifest) and `private/` (matrix, catalogue, runner contract, legacy tree,
  verifiers, planting and generation scripts, clean reference, variant cases), committed by the owner's express order
  (MATRIX §4, item 7) under the rule of 0.2.169. Its `matrix.json` hashes to `c42c8287…3181`, the hash every LEB-300-A result
  cites; the runs' package (`7e35825a…`) names it as `3331a107…`, the same matrix without the `publication` header key (the
  instance README shows the check). Left out: the operator's validation folder and build outputs.

### `0.2.170` — 2026-10-12 — The resolver test compares with the repository's own instances instead of a fixed list

- `tests/test_resolver.py` checked that the repository lists exactly `LEB-100-A` (plus the synthetic instance of the test).
  Under the rule of 0.2.169 every instance is committed here when it is created, so that fixed list would fail with each new
  one, starting with LEB-300-A. The test now reads the repository's instances first, requires LEB-100-A among them, and checks
  that the search path adds the synthetic one to that list. It passes with and without another instance in `instances/`;
  173 tests. No tool, rule or published result changes.

### `0.2.169` — 2026-10-12 — Spec 1.5.0: an instance and its answer key are committed to the public repository at creation, before any run

- **The owner's rule of 2026-10-12.** An instance, its answer key (`private/`) included, is committed to this public repository
  when it is created, and no run starts before that commit is on GitHub. The commit's public timestamp is what proves that
  every run used that instance and that key.
- **Why.** The earlier rule kept the key private and published only its SHA-256, to be checked when the key was revealed at
  retirement. That proves nothing about a run made before the hash was public, and LEB-300-A's hash reached GitHub in the same
  commit as its first result (0.2.128, 2026-10-08): the results published after that commit are covered by the public hash,
  the runs before it only by the operator's private freeze record. The new rule closes the gap for every instance from now on.
  What it cannot prevent, a model trained after the publication having seen the key, is handled as LEB-100-A already does: every
  run records its model's training cutoff, and the result says so when the cutoff is later than the publication.
- **Where it is written.** `matrix/MATRIX.md §4` is rewritten in English as eight items: publication at creation; the hashes every
  run records and anyone can recompute; the isolation that keeps the key from the agent during a run; the training cutoff;
  expiry, counted from the publication; official runs; active instances, whose per-run material stays aggregate-only under the
  owner's case-by-case orders; and the reason, with LEB-300-A's history and the hash its key must match. `SPEC.md` goes to
  **1.5.0** (§9 item 8 and the release notes; MINOR, no change to points, penalties, IDs or published matrices). The rule is
  repeated where an author or an operator meets it: the instance authoring steps (`instances/README.md`, step 10 and the public
  and private note), the matrix template, the first item of the pre-run checklist (`BENCHMARK.md`), `README.md` (and its
  Portuguese copy) and the next-series design (§5, both sets).
- LEB-300-A's own instance and key are not part of this commit; they must hash to the `matrix_sha256` its results cite
  (`c42c8287…3181`). No tool, test or published result changes; 173 tests pass and the export check is unchanged.

### `0.2.168` — 2026-10-12 — The documents correct seven things a replicator's review of the public repository found wrong

A review that tried to replicate LEB-100-A from this repository alone rebuilt the published package byte for byte and recomputed
a published scorecard identically (and `tools/export-results.py --check` passes), but found these errors in the documents:

- The command that rebuilds the published package was nowhere: the documents give mode S, or mode A with 20 turns, and the
  published runs are mode A with 30. `instances/LEB-100-A/README.md` now gives `./leb pacote LEB-100-A --mode A --turnos 30`
  with the package's SHA-256, and `BENCHMARK.md` says the same at the packaging step.
- `docs/candidate-vm.md` said fallback is on without its scope: that profile runs task 1.2.0 (PROTOCOL §3.1); a run of task
  1.0.0, such as the published LEB-100-A runs, turns the switch off (PROTOCOL §3).
- `scoring/JUDGE.md`: the `score.py` example broke when pasted, because comments after a line continuation swallowed it.
- `BENCHMARK.md` step 8 said deliveries and scorecards do not reach the public repository; it now says where a published run's
  curated copy lives, and that an active instance publishes only its aggregate.
- `harness/README.md` says where the model's cost really is in published runs (`run.json`), and that `score.py` takes it as
  JSON through `--cost`, not as the `custo.txt` the operator saves.
- `README.md` counted twenty-seven LEB-100-A agents; it now gives 37 agents, 92 runs, 26 official.
- `docs/new-series-design.md` §7 said the audit ran on public LEB-100-A records; it ran on the operator's transcripts of
  published runs, and the transcripts are not published.

What the review found that needs the owner's decision (unpublished judge prompts, the environment of the LEB-100-A runs, the
transcripts the protocol says are published, pinned evaluation images, the retirement rule) is left as it is. No protocol text,
tool or published result changes; 173 tests pass.

### `0.2.167` — 2026-10-12 — `leb pacote` prints the operator's first message exactly as PROTOCOL §3 fixes it

- A review of the public repository from a replicator's seat found that `./leb pacote` told the operator to say *"Leia o
  TAREFA.md e execute. ..."*, while PROTOCOL §3 (*Operator replies*) and every published run use *"Agora leia o TAREFA.md e
  execute. Devolva code/ alterado, RELATORIO.md e achados.json."*. The script now prints the protocol's sentence. Nothing else in
  the package or the scoring changes; 173 tests pass.

### `0.2.166` — 2026-10-12 — The audit names a subagent that the agent itself launched on another model, instead of calling the switch unexplained

- In run 2 of Claude Sonnet 5.5 at max effort on LEB-300-A (published in 0.2.163, with the fact disclosed), the agent launched
  four subagents asking the Agent tool for `"model": "opus"`; they answered 122 of the session's 543 responses on
  claude-opus-5-5. `tools/auditar-tentativa.py` filed them as switched with the cause *unexplained*, because no stop preceded
  them. The client records the request twice: in the `input.model` of the Agent tool call and in the `model` of the subagent's
  `<agent>.meta.json`, next to the call's id.
- The audit now reads both. A subagent that starts on a model outside the allowed set after its launcher asked for it is filed
  as switched with the cause *requested by the agent ("opus")*; the report gives each subagent's requested model and the text
  report shows it next to the subagent's type. The outcome is unchanged: any model outside the allowed set is a switch, and
  under the candidate profile the client would not run that request on another model.
- One new test, on the real shapes, with the request read from the meta file and, without it, from the tool call (20 for this
  tool, 173 in all); the new branch was broken on purpose and the test failed. Run on that session, the four subagents come out
  with this cause and the 122 responses match the count of the run's record. No protocol text or published result changes.

### `0.2.165` — 2026-10-12 — The next-series design adds an expected score per scheduled attempt, and splits blocks into at the start and during the work

- `docs/new-series-design.md` (still `PROPOSED`), two additions the owner decided on 2026-10-12:
  - §9, **expected score per scheduled attempt**, published with its interval next to the quality of the evaluable deliveries
    and the completion rate, never in place of them. Every scheduled attempt counts with the score of what it left: a completed
    one with its score, a blocked one or one whose budget ran out with the score of the work folder it left, graded by the same
    evaluator. Switched attempts are left out and investigated; lab failures and operator errors are rerun. A configuration
    whose hard cases end blocked can no longer look strong on the quality of the rest alone.
  - §6.2, **where a block falls**: *blocked at the start* when the work folder the attempt left is identical to the package (the
    agent changed nothing before the stop), *blocked during the work* otherwise. The lab compares every attempt's folder with
    its package, so the split needs no judgment; §9 publishes blocks split this way.
- §6.2 now says that a blocked attempt's state enters the expected score and never the quality score; §13 narrows the open
  question on that state to whether it is also published as a profile of its own. No tool, test or published result changes.

### `0.2.164` — 2026-10-12 — The next-series design marks step 1 done: the unattended run passed a smoke case on a bench VM

- `docs/new-series-design.md` (still `PROPOSED`), §10: the lab's driver, kept with the lab's private material, runs one case per
  command through the six steps: it restores and starts a bench VM through a hypervisor token limited to those VMs and to power
  and snapshot operations, installs the profile and runs the preflight, runs `claude -p` once under a budget, collects the main
  and subagent transcripts, the delivery and the `-p` result, audits the session and files the case. A ledger refuses to roll
  back a VM that the lab did not itself leave restored or collected. A smoke case (create one file) passed on a bench VM:
  preflight, a 13-second session on the pinned model, collection, audit *completed*. §10 also records the owner's choice of
  non-interactive runs and the fields of the `-p` result the driver keeps (`safety_stops`, the permission refusals).
- §6.1 corrects "the tools pre-approved": the cases run in the client's auto permission mode, as the LEB-300-A pilot's sessions
  did, with the client's refusals counted, and never with permissions bypassed. It adds that the runner's saved model and effort
  defaults are cleared before each case, because the bench snapshots carried different ones (a `sonnet` default, a `[1m]`
  variant). The status and §12 mark step 1 done; §13 drops the question on interactive or non-interactive runs. No tool, test
  or published result changes.

### `0.2.163` — 2026-10-12 — The LEB-300-A aggregate adds a second run of Claude Sonnet 5.5 at max effort, which lowers its published score from 932 to 864

- `results/2026/LEB-300-A/aggregate.json`: Claude Sonnet 5.5 at max goes from one run (932, Platinum) to two (932 and 864). With fewer than three runs the published score is the lowest, so the agent moves from 932 (Platinum) to 864 (Gold). It stays 1st, 4 points ahead of Claude Sonnet 5.5 at xhigh (860), and it is still not official (2/3, PROTOCOL §4). The 932 of run 1 remains the highest single run of the instance, but no agent shows Platinum for now. No other agent changed; the categories, cost, session time and tokens shown for the agent are those of the run whose score is published (run 2).
- **Run 2** (Claude Code, two of three): 864 (Gold). It fixed 31 flaws by the verifier's count and reported 31, with no false positive, 39 real findings outside the matrix and no penalty; its report was rated 43 of 50. It took 25 compatibility points (COMP-008) for a login lockout that answers 429, a status outside the manifest's list, also to the right password (889 without it); each of the seven earlier runs with the same behaviour had been charged the same way (checked by searching the deliveries for the status against the verdicts). The agent used 208.5 minutes of session (216.9 minutes less the 8.4 that the operator took between the two stages), US$ 81.94 and 238.8 million tokens, against 92.5 minutes, US$ 10.75 and 15.7 million tokens in run 1. Mechanical evaluation: 36 probes, characterization 70 of 70.
- **Subagents on another model.** Run 1 used none; in run 2 the agent itself launched four read-only reviewers with the client's Agent tool (`model: opus`), two in each stage. Each answered only with Claude Opus 5.5 and handed its report back to the main session, which answered only with Claude Sonnet 5.5 (882 messages). The client's totals above include them: US$ 58.25 for Sonnet 5.5 and US$ 23.69 for Opus 5.5. The only other Claude runs of the instance with subagents (Claude Haiku 4.5 run 2 and Claude Haiku 5.5 xhigh run 3) had them on the main session's own model, so this is the only Claude run whose subagents answered with another one. The protocol of this series records the subagents and the models observed and does not forbid them; the rule that only the requested model may answer belongs to the proposed design of the next series (`docs/new-series-design.md`). The run stands as the agent's, with the fact in the run record and in the agent's reading.
- **Two checks of the verifier that miss.** The check of a low-severity flaw (5 points) that reads the cause of a failure from the standard streams missed a fix again: the delivery logs the exception with its cause through `System.Logger`, which that check does not see (872 if counted; see 0.2.159). And the check of a low-severity clean-code flaw read a prose comment of the delivery, a sentence that ends with a semicolon, as code left behind, although the code it was meant to find was deleted (878 if counted, 886 with the other check). The mechanical result prevails and no check was changed here; the owner decides whether to change them and to repeat the mechanical evaluation of the runs they affect.
- Judged by two fresh Opus 5.5 subagents with the standing prompts, including the paragraphs on bundled findings and on the login lockout; the lockout was charged in the first pass, and no finding was credited to more than one row (checked after the verdict).
- The reading of the agent is rewritten for two runs. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.162` — 2026-10-12 — The next-series design records the pin probed on a bench VM: every response came from the pinned model

- `docs/new-series-design.md` (still `PROPOSED`), §6.1: on a bench VM, with Claude Code 2.1.295 and Claude Fable 5.1 pinned at
  high by the candidate profile, six trivial non-interactive sessions (no safety classifier touched) all answered with the
  pinned model. That held for the default session, for a session asked for `claude-opus-4-8` and one asked for the alias `opus`
  (each answered by the pinned model, with a system warning), for an Explore subagent (it defaults to the client's auxiliary
  model) and for the 1M-context variant. No other model, the auxiliary one included, shows in the sessions' model usage. The
  policy's effort outranked the runner's saved one, `--effort max` reached the responses, and the advisor stayed off.
  `claude doctor` reports that the runner's Pro/Max account gets no console policy, so the file is the policy in force. The
  safety fallback itself is not something a probe can show; the audit of the first runs is where it is seen.
- §7 adds the two things the probe taught the audit (0.2.160); §10 says that a non-interactive session writes no turn records,
  so the driver takes duration, turns and cost from the JSON result of `-p`; §12 marks the probe done, leaving the unattended
  run; §13 replaces the question the probe answered with the context window as part of a configuration (the exact list admits
  the 1M variant, and the transcript does not say which one ran). No tool, test or published result changes.

### `0.2.161` — 2026-10-12 — The preflight reports a runner's saved per-model effort that the policy outranks, instead of failing on it

- On the bench VM probe, the runner's settings still held a per-model effort saved by `/effort` (`modelSettings`, xhigh for the
  requested model) while the policy declared high. `tools/perfil-candidato.py check` failed on it, and every response of the
  probe ran at high: the managed `effortLevel` outranked it, as the settings reference says it should.
- `check` now lists such a user effort with what outranks it (the managed `effortLevel`, or `--effort max` at launch) and fails
  only when nothing does. A `CLAUDE_CODE_EFFORT_LEVEL` in the user's `env` still fails: that variable outranks both.
- One new test (11 for this tool, 172 in all); the new rule was broken on purpose and the test failed. No protocol text or
  published result changes.

### `0.2.160` — 2026-10-12 — The audit records a model the client was asked for and its managed settings refused, the type of each subagent, and a session's span

- A probe on a bench VM, under the candidate profile (0.2.156), asked the client for `--model claude-opus-4-8` and for the alias
  `opus`. The client answered both with the pinned model and no error, and wrote one system warning in the transcript:
  `Model "claude-opus-4-8" is restricted by your organization's settings. Using claude-fable-5-1 instead.`
  `tools/auditar-tentativa.py` now reads that warning. In the main conversation it is an *operator error*: the requested model
  answered, so the work stays attributable, but the launcher or a setting asked for another model, so the procedure was not the
  declared one. In a subagent it is listed.
- Each subagent now carries its type (`Explore`, say), read from the `<agent>.meta.json` that the client writes next to its
  transcript; the text report counts the subagents by type.
- A non-interactive session (`claude -p`) writes no turn records, so it reported 0 turns and 0 minutes. The report now gives the
  span from the first message to the end (`span_seconds`), and says when there are no turn records.
- Three new tests (19 for this tool, 171 in all); both new checks were broken on purpose and a test failed each time. Run on the
  probe's own transcripts, the session asked for `claude-opus-4-8` comes out *operator error* and the subagent session shows its
  Explore subagent on the pinned model. No protocol text or published result changes.

### `0.2.159` — 2026-10-12 — The LEB-300-A aggregate adds a third run of Claude Opus 5.5 at xhigh effort, which makes the agent official

- `results/2026/LEB-300-A/aggregate.json`: Claude Opus 5.5 at xhigh goes from two runs (886 and 822, published 822) to three (886, 822 and 845) and becomes official (3/3, PROTOCOL §4). Its score is the median, 845 (Gold), which puts it 5th instead of 6th: it passes Claude Fable 5.1 at high (836), which moves to 6th; the first four places do not change. No run score changed in the other runs; the categories, cost, session time and tokens shown for the agent are those of the median run (run 3).
- **Run 3** (Claude Code, one of three): 845 (Gold). It fixed 32 flaws and reported 28, with no false positive, 24 real findings outside the matrix and no penalty; its report was rated 44 of 50. It took 25 compatibility points (COMP-008) for a login lockout that answers 429, a status outside the manifest's list, also to the right password. The agent used 74.3 minutes of session (79.9 minutes less the 5.6 that the operator took between the two stages), US$ 13.69 and 15.3 million tokens. Mechanical evaluation: 36 probes, characterization 70 of 70.
- **The lockout was judged twice.** The first matching pass did not charge it (870). Six earlier runs of this instance with the same behaviour had each been charged COMP-008 once, and none had been left uncharged (checked by reading every delivery and every verdict), so the same matching judge was asked, with that fact and nothing else, to decide that single call again; it charged COMP-008 once and recorded the change in `notes` (845). Nothing else in the verdict changed, and the first pass is kept in the private archive.
- **A mechanical check that misses fixes logged through `java.util.logging`.** The check of one low-severity flaw (5 points) reads the cause of a failure from what is written to the standard streams during the request. A delivery that logs through `java.util.logging` is seen only when no record was logged earlier in the same JVM, so such a fix counts as not fixed. It happened in this run (853 if counted) and in two others: Claude Sonnet 5.5 at max (932 as scored, 940 if counted) and run 1 of Claude Sonnet 5.5 at xhigh (860, the published score, 866 if counted). The mechanical result prevails and the scores stay as scored; the effect is 6 to 8 points per run, and the check is not changed by this release.
- Judged by two fresh Opus 5.5 subagents with the standing prompts, including the paragraph on bundled findings; no finding was credited to more than one row (checked after the verdict).
- The reading of the agent is rewritten for three runs. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.158` — 2026-10-12 — The owner confirms the rule on stops: only a stop that ends the work blocks an attempt

- `docs/new-series-design.md` (still `PROPOSED`): the owner confirmed the refinement that 0.2.157 proposed. The decision table of
  §2 now reads that a safeguard stop after which the requested model does no further work ends the attempt as *blocked*, and that
  a stop it recovers from on its own, or one inside a subagent, is counted in the stop rate while the delivery is evaluated. §6.2
  records the confirmation and rewords *completed* and *blocked* to match; §13 drops the question.
- `tools/auditar-tentativa.py` already applies this rule (0.2.155), so no tool or test changes. No protocol text or published
  result changes.

### `0.2.157` — 2026-10-12 — The next-series design records what step 1 found, and proposes a refinement on stops the model recovers from

- `docs/new-series-design.md` stays `PROPOSED`. Its status now says that step 1 has its two tools (0.2.155 and 0.2.156) and
  that nothing else is built; §12 says what step 1 still lacks: the probe that shows the pin holding on a bench VM, and the
  unattended run.
- §6.1 spells out the profile as `tools/perfil-candidato.py` writes it: the exact allowlist, why there is no `deniedModels`
  (denying `claude-sonnet-5` would block Claude Sonnet 5.5 itself), no overload fallback, the advisor off, the effort, a managed
  file the runner can read, and the one thing no file check sees, a policy from the claude.ai console, which the lab confirms
  with `/status` and `claude doctor`.
- §6.2 says how the audit decides the outcome, including *inconclusive* when a record cannot be attributed, and adds a
  **proposed refinement for the owner to confirm**: an attempt is *blocked* only when a stop in the main conversation leaves the
  requested model doing no further work; a stop it recovers from on its own, and a stop inside a subagent, are counted in the
  stop rate and the delivery is evaluated. The decision table of §2 is unchanged until the owner decides.
- §7 describes the audit and what real transcripts taught it; §13 replaces the question on `availableModels` values with the two
  that the lab probe answers, and adds the refinement. No tool, test, protocol text or published result changes.

### `0.2.156` — 2026-10-12 — A tool writes the candidate profile's managed policy, which pins the requested model, and checks a machine against it

- New `tools/perfil-candidato.py`, the other tool of step 1 of `docs/new-series-design.md` (§6.1). `policy` prints the
  `managed-settings.json` of the profile; `check` is a read-only preflight of a machine before the first message. Exit 0 every
  check holds, 1 one failed, 2 a path or an argument could not be used.
- The policy, from Claude Code's settings reference: `availableModels` holds the requested model alone (plus `--allow`), with
  `availableModelsMatch: "exact"`, `enforceAvailableModels` and `model`, so that no other model can answer in the main conversation,
  a subagent, a skill or the advisor, and a safety flag finds no model to fall back to. The default prefix matching would let an
  id permit the later versions that extend it, hence `exact`, which needs v2.1.283; `requiredMinimumVersion` keeps an older client
  from starting. No `fallbackModel` chain (the overload fallback). The advisor is off through `CLAUDE_CODE_DISABLE_ADVISOR_TOOL`
  in the policy's `env`, which the client merges from every managed source. `switchModelsOnFlag` is false, the account's skills,
  plugins and connectors and the Artifact tool are off, and `effortLevel` is the declared effort (`max` is not a settings value
  and is passed at launch).
- **No `deniedModels`, on purpose:** an id without a minor version also blocks the later minor versions, so denying
  `claude-sonnet-5`, the fallback of Claude Sonnet 5.5, would block Claude Sonnet 5.5 itself. The exact allowlist already denies
  every other model, and `check` refuses a denied entry that would block the requested model.
- `check` reads the managed file and its drop-ins, merged as the client merges them (owned by root, writable by root only, and
  readable by the runner: a policy the runner cannot read is skipped without notice); the runner's home (no cached
  server-managed policy, no model, effort, advisor, hook, plugin or output-style override, no CLAUDE.md, skills, agents or
  commands, no MCP server, the work folder trusted, no transcript or memory of an earlier session); the work folder (no project
  settings, CLAUDE.md or `.mcp.json`); with `--env`, the launcher's environment (no variable that picks a model, a provider, an
  API address or another effort); and the client's version.
- **What a file check cannot see:** a policy that the account's organization delivers from the claude.ai console outranks the
  file and, by default, replaces it whole. In the lab, `/status` must name `Enterprise managed settings (file)` as the setting
  source with no `Skipped sources`, and `claude doctor` must report no server-managed settings; the audit of the transcripts
  (0.2.155) shows whether the pin held. Whether `exact` admits a dated id of an allowed model, and what the client's auxiliary
  model does under the pin, are left to that lab probe.
- New `tests/test_perfil_candidato.py` (10 cases, synthetic machines in a temporary folder); each check was also broken on purpose
  to see a test fail (5 mutations, 5 caught). 168 tests in all. `harness/README.md` lists the tool. No protocol text, instance,
  weight, penalty or published result changes, and the LEB-300-A pilot does not use the tool.

### `0.2.155` — 2026-10-12 — A read-only tool audits one attempt's Claude Code session: who answered, every safeguard stop, and the outcome

- New `tools/auditar-tentativa.py`, the audit of step 1 of `docs/new-series-design.md` (§7). It reads one session, the main
  transcript and every subagent transcript under `<session-id>/subagents/` (workflows included), and reports each model that
  answered, with its responses, tool calls and effort; every safeguard stop, with its category, its place in the transcript and the
  tool calls the allowed models made after it on their own; the safeguard notices and the model fallback events; the operator's
  messages, slash commands and interruptions; the API errors; and how the session ended.
- It decides one outcome, the first that applies: operator error, switched, lab failure, blocked, budget exhausted, inconclusive,
  completed. These are the outcomes of the design's §6.2, plus *inconclusive* when the record cannot be attributed. A stop the model
  recovers from on its own (it goes on calling tools, with no substitute) is counted and reported, not fatal; a stop after which it
  does no more work is *blocked*, and work that only an operator's new message brought back does not count as recovery.
  `--requested` names the model; `--allow` adds one the lab allows (the client's auxiliary model, say), matched exactly or with a
  date suffix and never as a prefix (`claude-sonnet-5` does not allow `claude-sonnet-5-5`); `--message` and `--allow-command`
  declare the procedure; `--effort` is the effort every response must carry; `--stopped-by` says the lab stopped the client. Exit 0
  completed, 1 any other outcome, 2 inconclusive.
- What reading real transcripts taught it:
  - a response is one API message, and the client writes one line per content block, so responses are counted by message id.
    `tools/modelos-usados.py` counts lines: the 33 "responses" of claude-opus-4-8 in the void-2 record of Claude Fable 5.1 at xhigh
    are 33 transcript lines, which are 16 API responses;
  - in subagents, a stopped response's own lines can lack `stop_reason: "refusal"`, and the note the client adds after it ("Your
    response above was stopped by a safety classifier") is then the only trace of the stop; a subagent's switch writes no fallback
    event;
  - every response records its effort, so the effort an attempt is filed under can be checked.
- Run against public LEB-100-A records: both void attempts of Claude Fable 5.1 at xhigh come out *switched* (a fallback to
  claude-opus-4-8 after a cyber stop), and run 1 of Claude Sonnet 5.5 at max effort in ultracode shows the nine of its 68 subagents
  that switched to claude-sonnet-5, as its run note says, each after a stop in that subagent. Four of that run's 17 stops are seen
  only through the client's note.
- New `tests/test_auditar_tentativa.py` (16 cases, synthetic sessions in the shapes the client writes). Each check was also broken
  on purpose to see a test fail: 8 mutations, 8 caught. 158 tests in all. `harness/README.md` lists the tool. No protocol text,
  instance, weight, penalty or published result changes, and the LEB-300-A pilot does not use the tool.

### `0.2.154` — 2026-10-12 — The LEB-300-A aggregate adds GLM-5.3-Flash at max effort

- `results/2026/LEB-300-A/aggregate.json` goes from twenty-six agents to twenty-seven. The new one is **GLM-5.3-Flash at max effort** (OpenCode through Novita AI, one run of three, so not official): **693 of 1000 (Silver), 14th**. Agents below it move down one place; the first thirteen places do not change.
- The run fixed 25 flaws and reported 23, with no false positive, 9 real findings outside the matrix, no compatibility violation and no penalty; its report was rated 40 of 50. The agent used 52.8 minutes of session (66.2 minutes less the 13.4 that the operator took between the two stages), US$ 0.54 and 13.4 million tokens. Mechanical evaluation: 36 probes, characterization 70 of 70.
- The same model at high effort (`glm-5.3-flash-high`, 568) ran through Z.ai's own API, and this run went through Novita AI, so the 125 points between them mix the effort and the host. The session records the host's own id, `zai-org/glm-5.3-flash`, and the variant `max`; Z.ai's model page lists `reasoning_effort: max` among its recommended settings, and Novita AI's page describes no effort control. The model block of the run records both.
- Judged by two fresh Opus 5.5 subagents with the standing prompts, including the paragraph on bundled findings; no finding was credited to more than one row (checked after the verdict).
- **Two calls kept as judged.** (1) A change in a background job moves the creation of a folder out of the per-item error handling, so a folder that cannot be created now aborts the whole pass; the judge did not charge the penalty for introducing a new bug (15 points if charged), because it only happens with a misconfigured folder. (2) The new request-body cap answers 413, a status outside the manifest's error list; it was not charged as a compatibility violation.
- The reading of the agent is written in both languages. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.153` — 2026-10-12 — Propose the next LEB series: two independent tracks, LEB Default and LEB Security

- New `docs/new-series-design.md`, marked `PROPOSED`: the design the owner decided on 2026-10-12 for the series that follows the full LEB. Two tracks with their own package, session, budget and score, never added; several reference systems, each yielding both tracks; five scheduled runs per case, all counted and all published by outcome; the requested model pinned in the client (`availableModels`) and traced in every agent, subagents included; a blind evaluator that charges a regression once; an automated lab.
- Why, from this repository's own data: model switches after a safety flag (two void LEB-100-A attempts, and `switchModelsOnFlag` does not cover subagents), the saturation of small instances (`tools/saturacao.py` on LEB-100-A), spreads inside one agent at the top of LEB-300-A that are larger than the gaps between agents, and the double charge of one regression that `SPEC.md §6.2` documents. Recomputed with `harness/score.py` itself on the 92 public LEB-100-A runs, all of them reproduced exactly: five runs broke a characterization test, and the worst one lost 235 points to the two charges together.
- `README.md` lists the document. No protocol text, instance, weight, penalty, tool, test or published result changes; the LEB-300-A pilot continues under its own rules.

### `0.2.152` — 2026-10-12 — The LEB-300-A aggregate adds Claude Sonnet 5.5 at max effort, which leads the board with 932 (Platinum)

- `results/2026/LEB-300-A/aggregate.json` goes from twenty-five agents to twenty-six. The new one is **Claude Sonnet 5.5 at max effort** (Claude Code, one run of three, so not official): **932 of 1000 (Platinum), 1st**. It is the first Platinum of this board and the highest score of any run so far; the previous highest, 911, is the second run of the same model at xhigh effort. Every other agent moves down one place, and Claude Sonnet 5.5 at xhigh (860) is now 2nd.
- The run fixed 34 flaws and reported 31, with one false positive, 22 real findings outside the matrix, no compatibility violation and no penalty; its report was rated 41 of 50. The agent used 92.5 minutes of session (108.5 minutes less the 16.0 that the operator took between the two stages), US$ 10.75 and 15.7 million tokens. Mechanical evaluation: 36 probes, characterization 70 of 70. No subagent and no workflow was used: this is plain max effort, not the multi-agent mode that LEB-100 publishes as a different agent (`claude-sonnet-5.5-max-ultracode`).
- Judged by two fresh Opus 5.5 subagents with the standing prompts, including the paragraph on bundled findings; no finding was credited to more than one row (checked after the verdict).
- **Two contestable calls, both kept as judged.** (1) The mechanical check of one low-severity flaw (5 points) did not register a fix that the matching judge read as correct in the code: the check looks at a side effect of the request, and the delivery produces it through a path that the check does not observe. The mechanical result prevails (the protocol takes C3 from it) and the judge flagged it in `notes`; counted as fixed, the run would score 940. (2) One report finding was classed as a false positive because the race it describes is real but breaks no contract in the manifest; read as a real defect it moves to the extra findings and changes only the calibration, not the total.
- The operator sent only the two fixed messages (`operator_replies` is 0). During stage 1 the agent hit the output token limit and Claude Code itself sent its standard resume message ("Output token limit hit. Resume directly..."), an `isMeta` event that no one typed; it is not an operator reply and is noted in the run record. At the end of stage 2 the agent also saved two notes in the client's own memory folder, outside the delivery.
- The reading of the agent is written in both languages. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.151` — 2026-10-12 — The LEB-300-A aggregate adds a second run of GPT-6.1-sol at xhigh effort, and corrects a statement of 0.2.148 on bundled findings

- `results/2026/LEB-300-A/aggregate.json`: GPT-6.1-sol at xhigh goes from one run (800) to two (800 and 830). Fewer than three runs publish the lowest, so its score stays 800 (Gold, 6th, 2/3); the run list shows the second run. No other agent changes.
- **Run 2** (Codex CLI): 830 (Gold). It fixed 33 flaws and reported 22, with no false positive, no compatibility violation and no penalty; its report was rated 40 of 50 (45 in run 1). The agent used 49 minutes of session (52 minutes less the 3.2 that the operator took between the two stages) and 5.1 million tokens; Codex keeps no cost. Security, architecture, bugs and performance score the same as in run 1; clean code goes from 29 to 64. Mechanical evaluation: 36 probes, characterization 70 of 70.
- **Judged under the rule as written.** The matching judge of this run had the standing prompt, which since 0.2.148 carries the paragraph restating MATRIX §5 item 1 (a report finding maps to at most one matrix row). Two findings of the report each name several planted flaws; each was credited to its single most specific row, and the five flaws they also mention are counted as fixes without a report (no C1/C2/R1/R2). Credited separately and in full, those five rows would add up to 874, which is a bound and not a judgment. Run 1 of this agent was judged before the paragraph existed; none of its findings was credited to more than one row.
- **Correction to 0.2.148.** That entry said that every other judge, in that round and the earlier ones, had applied the one-row rule. That was wrong: a scan of every verdict of the instance shows four runs in which a judge credited one finding to several rows, each time as a recorded hesitation, reading the finding as stating distinct problems: GLM-5.3-FlashX at high run 1 (published 648; 639 to 642 when the rule is applied strictly), Moonshot Kimi K3 at high run 2 (published 712 as the lower of two; 692 to 699), Claude Sonnet 5.5 at xhigh run 2 (911; 872 to 884; the published 860 is run 1) and Claude Haiku 5.5 at xhigh run 1 (708; 685 to 688; the published 732 is run 2). The ranges come from re-scoring the verdict with each possible choice of the one row a finding keeps. Those scores are not changed by this release.
- The reading of the agent is rewritten for two runs. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.150` — 2026-10-12 — The LEB-300-A aggregate adds a third run of Claude Sonnet 5.5 at medium effort, which makes the agent official

- `results/2026/LEB-300-A/aggregate.json`: Claude Sonnet 5.5 at medium goes from two runs (659 and 717, published 659) to three (659, 717 and 734) and becomes official (3/3, PROTOCOL §4). Its score is the median, 717 (Silver), which puts it 10th instead of 13th: it ties GPT-6-astra at 717 and is listed ahead of it by agent id, so GPT-6-astra, Moonshot Kimi K3 and GLM-5.3 each move down one place. The first nine places do not change. No run score changed: the published score moves because it is now the median of three, and the categories, cost, session time and tokens shown for the agent are those of the median run (run 2).
- **Run 3** (Claude Code, one of three): 734 (Silver). It fixed 27 flaws and reported 25, with no false positive, no compatibility violation and no penalty; its report was rated 42 of 50. The agent used 21 minutes of session (27 minutes less 6.6 that the operator took between the two stages), US$ 2.25 and 4.82 million tokens. Mechanical evaluation: 36 probes, characterization 70 of 70.
- Judged by two fresh Opus 5.5 subagents with the same prompts as runs 1 and 2 of this agent (matching and blind EXPL). The check for bundled findings (one finding credited to more than one row, MATRIX §5 item 1) found none. The compatibility call that costs 30 points in run 2 (the Idempotency-Key fingerprint now includes the user) is the one disclosed in 0.2.148; run 3 has no such change.
- The reading of the agent is rewritten for three runs. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.149` — 2026-10-12 — The LEB-300-A aggregate carries the tokens of each agent and of each run

- `tools/export-results.py` writes `tokens` (`input`, `output`, `cache`, `total`) on every agent of `results/2026/LEB-300-A/aggregate.json` and on every line of its `runs`, next to the cost and the session time it already had. The agent's figures are those of the run its score is, as its cost and time already were. They are the client's own counts, as `run.json` keeps them (SCORING §9.3): `input` is what was not served from the cache, `output` includes the reasoning tokens, `cache` is what was read from or written to it, and `total` is their sum. A run whose record lacks any of the four publishes `null`, never a partial figure.
- `scoring/publicacao-agregada.schema.json` gains the field, on the agent and on the run line; nothing else in the schema moves. All twenty-five agents and their forty-one runs have the four figures, so the file grows by that field only: no score, grade, category, cost or time changes, and `results/README.md` is the same.
- The LEB-100 runs already published their `cost_time.tokens` in `results/results.json` (all ninety-two have them); this release changes no LEB-100 file. The two sites show them from their next sync.
- One new test; 142 in all. Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, weight, penalty or public scoring rule changes.

### `0.2.148` — 2026-10-12 — The LEB-300-A aggregate adds GPT-6.1-sol at high effort, Claude Sonnet 5.5 at medium effort and GLM-5.3-Flash at high effort

- `results/2026/LEB-300-A/aggregate.json` goes from twenty-two agents to twenty-five. Three agents are new, none official (below 3 runs the published score is the lowest run's, PROTOCOL §4). Agents below 779 move down one place, those below 659 two and those below 568 three; the first seven places do not change.
  - **GPT-6.1-sol at high** (Codex CLI, one run of three): 779 of 1000 (Gold), 8th. It fixed 30 flaws and reported 21, with no false positive, no compatibility violation and no penalty. **It was judged twice.** The first matching pass credited ten fixes that sit inside bundled findings (one finding naming several flaws) as if each had been reported on its own, which gave 851.
    MATRIX §5 item 1 maps a finding to at most one row, and every other judge, in this round and the earlier ones, had applied it that way, so a fresh matching judge was run on the same anonymized delivery with the standard prompt plus one paragraph restating that rule and asking for the bundles to be listed in the notes. That second pass is the score, 779; the first pass stays in the private archive for audit.
    Re-scoring the first pass under every strict choice of the surviving row gives 761 to 779. The blind EXPL judge (42 of 50) was not repeated: it reads only the report.
  - **Claude Sonnet 5.5 at medium** (two runs, 659 and 717): published 659, the lower, 13th (2/3). Run 1 fixed 23 flaws and run 2 fixed 28. Run 2 took 30 compatibility points (COMP-003: the Idempotency-Key fingerprint now includes the user, so another user of the same company repeating the request gets a 422 instead of the original answer; 747 without it).
    The matching judge of the Sonnet 5.5 high run 3, in the previous round, classified the same effect as COMP-008 (25 points) instead of COMP-003 (30); the difference of 5 points between the two readings is left as judged.
  - **GLM-5.3-Flash at high** (OpenCode through Z.ai, one run of three): 568 (Bronze), 17th. It fixed 17 flaws and reported 20, with no false positive and no compatibility violation.
- Each run was judged by two fresh Opus 5.5 subagents, as always: the matching judge on the anonymized delivery and a blind judge of the report alone. The standard prompt is the one of the previous round (read confined to the judge's folder and the matrix file); the paragraph on bundled findings was added only to the second pass of the GPT-6.1-sol run.
- The operator sent the stage 2 message to the four VMs at the same moment, 116 to 130 minutes after each agent had finished stage 1; that wait (129.8, 122.4, 127.2 and 116.2 minutes) is left out of the session time, as before. The two Sonnet runs used 14 and 29 minutes of the agent's time.
- `trazer-rodada.py` (a private tool) now starts a Claude session at the first message to the agent instead of the first event of the transcript: in this round the `/model`, `/effort` and `/login` of the operator came 3.3 minutes before it, and counting them would have inflated the first run's time and its first stage; earlier runs differ by seconds.
  The client's totals of the two Sonnet runs include a few calls of the client itself after the last turn (an away summary in one, a 1,202-token Haiku 5.5 call in both); they are noted in each run record.
- Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.147` — 2026-10-11 — The LEB-300-A aggregate adds GPT-6.1-sol at ultra effort and more runs of Claude Sonnet 5.5 (xhigh and high) and Claude Haiku 5.5 (xhigh)

- `results/2026/LEB-300-A/aggregate.json` goes from twenty-one agents to twenty-two. **GPT-6.1-sol at ultra effort** is new (Codex CLI, one run of three, so not official): 797 of 1000 (Gold), 7th. Three agents get another run, each from the same freeze and matrix as before.
  - **Claude Sonnet 5.5 at xhigh**: run 2 scored 911 (Platinum), the highest single run of any LEB-300-A agent so far; the published score stays 860, the lower of two runs, and the agent stays 1st (2/3). Run 2 took 25 compatibility points (COMP-008: after ten failed attempts the login answers 429 even to a correct password, where the manifest says 200);
    the matching judge noted that a lenient reading would record no violation, which would give 936.
  - **Claude Sonnet 5.5 at high**: run 3 scored 855 (Gold). With 897, 763 and 855 the agent has three runs and its score is the median, 855, up from 763 (2nd, was 6th); it is official. Run 3 took 25 compatibility points (COMP-008: a fix made another user of the same company get an error, not the original response, when repeating an idempotency key)
    and no PEN-001 (runs 1 and 2 took 15 points each). The judge hesitated on three architecture items described inside bundled findings; the strict reading is kept (10 raw points).
  - **Claude Haiku 5.5 at xhigh**: run 3 scored 824 (Gold), the best of its three. With 708, 732 and 824 the score is the median, 732, up from 708 (8th, was 9th); it is official. The agent spawned one subagent, so the run took 99 minutes and US$ 5.16 against about 38 minutes and US$ 1.7 for the first two. Run 3 took 25 compatibility points (COMP-008: login can answer 429 from a static concurrency limit); a stricter judge could add COMP-003 for the login lockout and a title limit.
  - **GPT-6.1-sol at ultra**: 797, no compatibility violation and no penalty. The agent spread its work over three subagent threads of its own; the four Codex threads together used about 28.4 million tokens (10.4 million in the main one).
- Each run was judged by two fresh Opus 5.5 subagents, as always: the matching judge on the anonymized delivery and a blind judge of the report alone. The matching prompt gained one sentence confining reads and searches to the judge's own folder and the matrix file (after an incident in the previous round); it changes no rule or score.
- `trazer-codex.py` and `medir-espera.py` (private tools) now accept a Codex session with subagent threads: tokens are summed over all threads, the stage counts are the main thread's, and the operator's wait is measured on the main thread. After their last turn the two Sonnet sessions received two calls of the client itself (an away summary and an automatic compaction); their cost is inside the client's totals and is noted in each run record. The operator's wait between the stages (3.0, 2.9, 20.2 and 20.9 minutes) is left out of the session time, as before.
- Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. No protocol text, public tool, weight, penalty or test changes.

### `0.2.146` — 2026-10-11 — The LEB-300-A aggregate adds more runs of Claude Opus 5.5 (high), GPT-6-astra, GPT-5.6-luna and GPT-5.6-terra

- `results/2026/LEB-300-A/aggregate.json` keeps its twenty-one agents; four of them get another run, each from the same freeze and matrix as before. Below 3 runs the published score is the lowest run's, as before (PROTOCOL §4).
  - **Claude Opus 5.5 at high effort**: run 2 scored 866 (Gold); the published score stays 848, the lower of two runs, 2nd on the board (2/3).
  - **GPT-6-astra at xhigh**: run 2 scored 729 (Silver); published 717, the lower of two, 7th (2/3). Both runs broke the same characterization test (the customer's ticket history no longer came most recent first, PEN-002, −20 each).
  - **GPT-5.6-luna at xhigh**: run 2 scored 512 (Bronze), 126 below its first (638), so the published score falls from 638 to 512 and the agent from 13th to 14th (2/3). Run 2 took a 20-point penalty for a characterization test (a company administrator could now switch a company on and off) and 25 compatibility points
    for the same edit to the company routes (COMP-008: the platform role lost the write access the route table gives it). The matching judge hesitated between COMP-008 and COMP-003 because the manifest is not consistent about that role; the call is kept as judged and is worth 25 points (537 without it).
  - **GPT-5.6-terra at xhigh**: run 3 scored 657 (Silver). With 625, 574 and 657 the agent now has three runs and its score is the median, 625, up from 574 (13th, was 14th). Run 3 took no PEN-001 (runs 1 and 2 took 15 points each for a fix that introduced a new bug); the matching judge noted
    a risk it left uncharged (the token now stored for new sessions still works as a bearer token), and charging it would give 642, which leaves the median at 625.
- Each run was judged by two fresh Opus 5.5 subagents, as always: the matching judge on the anonymized delivery and a blind judge of the report alone. One matching judge's recursive search printed a few lines of published LEB-100-A scorecards; it opened no file there and saw nothing of LEB-300-A, and it recorded the incident in its own notes. The verdict was kept.
- The operator's wait between the stages (1.0 to 6.8 minutes) is left out of the session time, as before. The VM of the Terra run still held a 12-second login-check session from before the run; it is not part of the run and is not counted.
- Aggregate only: no delivery, verdict, mechanical report or flaw list enters `results/`. The top five of the board is unchanged (Claude Sonnet 5.5 at xhigh, 860, still leads). No protocol text, tool, weight, penalty or test changes.

### `0.2.145` — 2026-10-10 — The LEB-300-A aggregate lists MiniMax-M2.7

- `results/2026/LEB-300-A/aggregate.json` goes from twenty agents to twenty-one. **MiniMax-M2.7** (OpenCode, MiniMax's own API, no effort setting, so "default") is new, with one run of three, so it is not official: 202 of 1000 (Reprovada), 21st, below
  Gemma 4 26B (232). It is the standard-speed model of the same series as MiniMax-M2.7-highspeed (291, added in 0.2.144); the two are separate agents because the session records two model ids, and each has one run. MiniMax publishes no knowledge cutoff
  for the M2.7 series; its release date, 2026-03-18, bounds it.
- It fixed 3 of the 36 planted flaws and reported 5 (two of those left unfixed), with one false positive and one extra finding. One COMP violation was charged, kept as judged: the login rate limiter it added answers 401 even to the right password
  after five attempts in 15 minutes from the same non-local address (COMP-008, compatibility 75 of 100). No penalty. Its report was rated 34 of 50. The session took 15 minutes and cost US$ 0.44.
- That run's VM also held four short "who are you?" sessions the operator opened in OpenCode to check the logins before the run. They are separate sessions: only the run's own session is read for time, counts, tokens and cost.
- The operator's wait between the stages (6.8 minutes) is left out of the session time, as before.
- The top five of the board is unchanged (Claude Sonnet 5.5 at xhigh, 860, still leads).
- No protocol text, tool, weight, penalty or test changes.

### `0.2.144` — 2026-10-10 — The LEB-300-A aggregate lists GPT-6-luna at xhigh effort and MiniMax-M2.7-highspeed

- `results/2026/LEB-300-A/aggregate.json` goes from eighteen agents to twenty. Both are new, with one run of three each, so neither is official and the published score is that run's. Neither had a model block in LEB-100-A: the owner approved the data below
  before the runs were scored.
  - **GPT-6-luna at xhigh effort** (Codex CLI): 471 of 1000 (Bronze), 15th, between GPT-5.6-terra (574) and GLM-5.2 (390). It is a different model from the GPT-5.6-luna already on the board (638); OpenAI's model page gives a knowledge cutoff of
    2026-05-18. It fixed 22 of the 36 planted flaws and reported 21, with two false positives, neither a decoy. Two COMP violations were charged, kept as judged (COMP-008, 25 points, and COMP-003, 30 points), and two penalties took 35 points:
    one characterization test broke (PEN-002) and a login limiter it added keyed its block on the connection address (PEN-001). Its report was rated 40 of 50. The session took 35 minutes and used about 9.4 million tokens; Codex keeps no cost.
  - **MiniMax-M2.7-highspeed** (OpenCode, MiniMax's own API, no effort setting, so "default"): 291 of 1000 (Reprovada), 18th, between MiniMax-M3 (388) and Claude Haiku 4.5 (242). MiniMax publishes no knowledge cutoff for the M2.7 series; its release date,
    2026-03-18, bounds it, and its guide describes highspeed as the same series served faster. It fixed 7 of the 36 planted flaws and reported 8, with one false positive, no COMP violation and no penalty. Its report was rated 30 of 50. The session took
    10 minutes and cost US$ 0.35. The agent wrote RELATORIO.md and achados.json inside code/ instead of the project root; the owner accepted the delivery as made, and the judges worked on a copy with the two files at the root.
- The operator's wait between the stages (1.2 and 6.7 minutes in these two runs) is left out of the session time, as before.
- The top five of the board is unchanged (Claude Sonnet 5.5 at xhigh, 860, still leads).
- No protocol text, tool, weight, penalty or test changes.

### `0.2.143` — 2026-10-10 — The LEB-300-A aggregate lists GPT-6.1-sol and GPT-6-astra at xhigh effort

- `results/2026/LEB-300-A/aggregate.json` goes from sixteen agents to eighteen. Both are Codex CLI runs, with one run of three each, so neither is official and the published score is that run's.
  - **GPT-6.1-sol at xhigh effort**: 800 of 1000 (Gold), 5th, between Claude Opus 5.5 at xhigh (822) and Claude Sonnet 5.5 at high (763). It fixed 31 of the 36 planted flaws and reported 22, with one false positive, no COMP violation and no penalty. Its report
    was rated 45 of 50. The session took 50 minutes and used about 7.4 million tokens; Codex keeps no cost.
  - **GPT-6-astra at xhigh effort**: 717 of 1000 (Silver), 7th, between Claude Sonnet 5.5 at high (763) and Kimi K3 at high (712). It fixed 32 of the 36 and reported 22, with no false positive. One characterization test broke (the customer's ticket
    history no longer came most recent first), which costs 20 points as PEN-002 and is not charged a second time as a COMP violation. Its report was rated 41 of 50. The session took 28 minutes and used about 7.3 million tokens.
- Both agents already had an LEB-100-A model block, so their model data is copied from it. The top four are unchanged (Claude Sonnet 5.5 at xhigh, 860, still leads).
- The operator's wait between the stages (4.0 and 1.8 minutes in these two runs) is left out of the session time, as before.
- No protocol text, tool, weight, penalty or test changes.

### `0.2.142` — 2026-10-10 — The LEB-300-A aggregate lists GLM-5.3-FlashX at high effort, and two agents gain a second run

- `results/2026/LEB-300-A/aggregate.json` goes from fifteen agents to sixteen. **GLM-5.3-FlashX at high effort** is new, with one run of three: 648 of 1000 (Silver), 10th, between DeepSeek V4.1 Flash (649) and GPT-5.6-luna (638). It is an OpenCode run
  through Z.ai's own API. It reported 22 of the 36 planted flaws with no false positive, and its report was rated 40 of 50. One COMP violation was charged, kept as judged: the login lockout it added (10 failed attempts for an e-mail in 15 minutes, from
  anyone) answers 401 even to the right password (COMP-008, compatibility 75 of 100). The session took 47 minutes and cost US$ 0.96.
- **Two published scores change**, because each agent gained a second run and the published score of an agent with fewer than three runs is the lower of its runs (PROTOCOL section 4); neither agent is official yet.
  - **Claude Opus 5.5 at xhigh effort** goes from 886 to **822** and from 1st to 4th, so the board is now led by Claude Sonnet 5.5 at xhigh (860). The second run reported 27 of 36 with no false positive and its report was rated 42 of 50. Both runs
    took the same 30-point deduction (COMP-003: the idempotency fingerprint now includes the user), kept as judged in both. The second run cost US$ 6.77 in 48 minutes of work, against US$ 13.03 in 82.
  - **Kimi K3 at high effort** goes from 737 to **712** and stays 6th. The first run went through Novita AI; the second went through Moonshot's own API, as in LEB-100, and the reading on the page says so. It reported 26 of 36 with no false positive
    and no COMP violation; it cost US$ 4.67 in 71 minutes.
- That run's VM also held four short "who are you?" sessions the operator opened in OpenCode to check the logins before the run. They are separate sessions: only the run's own session is read for time, counts, tokens and cost.
- The operator's wait between the stages (0.4 to 1.9 minutes in these three runs) is left out of the session time, as before.
- No protocol text, tool, weight, penalty or test changes (141 tests).

### `0.2.141` — 2026-10-10 — The LEB-300-A aggregate lists GPT-5.6-luna at xhigh effort

- `results/2026/LEB-300-A/aggregate.json` goes from fourteen agents to fifteen. **GPT-5.6-luna at xhigh effort** is new, with one run of three: 638 of 1000 (Silver), without a penalty, between DeepSeek V4.1 Flash (649) and GPT-5.6-terra (574). It is a Codex run
  (the model is the default of the client's configuration on that VM; the session records it for both turns). It fixed 22 of the 36 planted flaws mechanically, reported 20 of them with no false positive, and its report was rated 38 of 50.
- Judge calls worth knowing, kept as judged: no COMP violation (the migration deletes duplicate rows keeping the lowest id, which the manifest prescribes when a unique index is blocked) and no PEN-001 (a slowdown on the quadratic path that PERF-012 plants would
  have punished the same fact twice). The session took 43 minutes, 1.4 of them the operator's wait between the two stages, which the page leaves out; Codex keeps no cost, so none is published.
- Nothing published before is changed. No protocol text, tool, weight, penalty or test changes (141 tests).

### `0.2.140` — 2026-10-10 — The 0.2.139 entry no longer claims two "firsts" it had not checked

- The entry of `0.2.139` said Gemma 4 26B A4B was the first open-weights model of Google's family in the lineup and the first Novita AI run through OpenCode with a published training cutoff. Neither was checked against the other runs, so both sentences are replaced by what was
  checked: it is an open-weights model from Google DeepMind (Apache 2.0), served by Novita AI through OpenCode, and Google publishes a cutoff for the family. No result, number, tool or test changes.

### `0.2.139` — 2026-10-10 — The LEB-300-A aggregate lists Gemma 4 26B A4B at high effort

- `results/2026/LEB-300-A/aggregate.json` goes from thirteen agents to fourteen. **Gemma 4 26B A4B at high effort** is new, with one run of three: 232 of 1000 (Reprovada), last in the lineup, just below Claude Haiku 4.5 (242). It is an open-weights
  model from Google DeepMind (Apache 2.0), served by Novita AI through OpenCode, and Google publishes a training cutoff for the Gemma 4 family (January 2025, from its model card). The contract stayed whole
  (compatibility 100); it fixed 3 of the 36 planted flaws mechanically and reported 5, all real, with no false positive.
- Judge calls and instrument notes worth knowing. The first mechanical evaluation of this delivery lacked one probe (BUG-011.b); a second evaluation of the same delivery returned all 36 with identical results for the other 35, and the second is the record
  (the first is kept in the run's logs). The only earlier report with 35 probes is the second run of Claude Haiku 4.5 (SEC-014.a missing), published at 218 with that flaw decided by the judge alone; nothing published is changed here.
- The first message of this session was sent three times: the first two went to model ids the host did not know (404, no tokens, no output) and are left out of the session time and of the counts, as the run note says. Session times leave out the operator's wait between
  the stages (6.9 minutes in this run). No protocol text, tool, weight, penalty or test changes (141 tests).

### `0.2.138` — 2026-10-10 — The LEB-300-A aggregate lists Claude Fable 5.1 at high effort

- `results/2026/LEB-300-A/aggregate.json` goes from twelve agents to thirteen. **Claude Fable 5.1 at high effort** is new, with one run of three: 836 of 1000 (Gold), without a penalty, fourth behind Claude Opus 5.5 at xhigh (886), Claude Sonnet 5.5 at xhigh (860)
  and Claude Opus 5.5 at high (848). It fixed 33 of the 36 planted flaws mechanically and its report was rated 44 of 50. It lost 25 compatibility points (COMP-008) for a security change the manifest does not allow: after 10 failed logins for an
  e-mail the login answers 429, a status the manifest does not list, even to the right password.
- It is a Claude Code run on the same package as the others. The session took 70 minutes, 18.2 of them the operator's wait between the two stages, which the page leaves out; it cost US$ 15.81, the highest of the lineup so far.
- Judge calls worth knowing, kept as judged: COMP-008 for the lockout (a judge who read it as hardening would leave compatibility at 100, so 836 would be 861), and no PEN-001 or PEN-003. No protocol text, tool, weight, penalty or test changes (141 tests).

### `0.2.137` — 2026-10-10 — The LEB-300-A aggregate lists Claude Opus 5.5 at high effort and adds a second run to Claude Sonnet 5.5 at high effort and to GPT-5.6-terra

- `results/2026/LEB-300-A/aggregate.json` goes from eleven agents to twelve. **Claude Opus 5.5 at high effort** is new, with one run of three: 848 of 1000 (Gold), without a penalty, against 886 for the same model at xhigh. It lost 25 compatibility points
  (COMP-008) for a security change that makes a repeated idempotency key from another user answer 422 where the manifest says the original response comes back.
- **Claude Sonnet 5.5 at high effort** now has two runs, 897 and 763, and is published at the lower one: 763 (Gold, 2 of 3, not official), which moves it from first to fourth, behind Opus 5.5 at high effort (848). **GPT-5.6-terra at xhigh effort** also has two
  runs, 625 and 574, and is published at 574 (Bronze), below DeepSeek V4.1 Flash (649). The rule is the one in PROTOCOL §4 and the one already used for Claude Haiku 5.5 and MiniMax-M3: with fewer than three runs the published score is the lower.
- Judge calls worth knowing, both kept as judged: PEN-001 (−15) was applied to the second Sonnet run for a login-failure counter that is never evicted, and to the second GPT run for removing the only two server-side error records; the first run of each took the same
  penalty for other changes. The run on a third VM with an open-weights model was discarded by the operator before scoring, because that model has no model block in the LEB-100-A runs to copy.
- The written readings of the two agents with a second run were rewritten, and the new agent has one. Session times leave out the operator's wait between the stages (0.6, 0.2 and 1.4 minutes in these runs). No protocol text, tool, weight, penalty or test changes (141 tests).

### `0.2.136` — 2026-10-09 — The LEB-300-A aggregate lists Claude Haiku 5.5 and Claude Sonnet 5.5 at high effort, and says who each agent is

- `results/2026/LEB-300-A/aggregate.json` goes from nine agents to eleven. Claude Sonnet 5.5 at **high** effort leads with 897 of 1000 (Gold) on one run of three, ahead of Claude Opus 5.5 (886) and of the same model at xhigh (860). Claude Haiku 5.5 at xhigh effort,
  new in the lineup, has two runs (708 and 732) and is published at the lower one, 708 (Silver, 2 of 3, not official), between Kimi K3 (737) and GLM-5.3 (688). All four are Claude Code runs, now on version 2.1.295; the earlier Claude Code
  runs were made on 2.1.285, and each run record carries the version that really ran.
- Two judge calls worth knowing, both kept as judged: PEN-001 (−15) was applied to Claude Sonnet 5.5 at high effort for a security change that makes a user with a very long password fail to log in after the first login (912 without it),
  and COMP-008 or COMP-003 was counted once on each of the three other new runs, for a security change that altered a behaviour the manifest contracts (compatibility 70 to 75).
- **New in the data:** each agent of the aggregate now carries `model` (name, id, provider, effort), the same public block its run record has, because the sites named an agent by finding it in the LEB-100 runs and the two new agents have none there.
  The schema gains the optional field and the exporter writes it for every agent; 1 new test (141 in all).
- The written readings no longer say "second", "third" or "the most" about the board, which went stale every round: they speak of the agent's own numbers, and compare only where the comparison is stable. Session times leave out the operator's
  wait between the stages (11.9, 5.3 and 0.7 minutes in these runs), as of 0.2.134. No protocol text, tool, weight or penalty changes.

### `0.2.135` — 2026-10-09 — The LEB-300-A aggregate lists Claude Opus 5.5, GLM-5.3 and GLM-5.2

- `results/2026/LEB-300-A/aggregate.json` goes from six agents to nine. Claude Opus 5.5 at xhigh effort leads with 886 of 1000 (Gold) on **one** run of three, in Claude Code, ahead of Claude Sonnet 5.5 (860). GLM-5.3 at high effort is
  fourth with 688 (Silver, one run, OpenCode, served by OpenRouter) and GLM-5.2 at high effort seventh with 390 (Reprovada, one run, OpenCode, served by Novita AI), one point above MiniMax-M3 (388, two runs). A line on fewer than
  three runs is not official and says so.
- The three agents have the ids they have in LEB-100 (`claude-opus-5.5-xhigh`, `glm-5.3-high`, `z.ai-glm-5.2-high`) and the same model blocks, with the host written in the run record of the two GLM runs. The session times leave out the
  operator's wait between the stages, as of 0.2.134 (1.3, 0.9 and 2.3 minutes).
- Two judge calls worth knowing, both kept as judged: COMP-003 was counted once on Claude Opus 5.5 (compatibility 70) for a change in how a contracted rule treats the users of one company, and GLM-5.2 has two COMP violations (compatibility 45),
  one of them a change in an error code of the manifest's table; seven of GLM-5.3's findings were halved only for filing a security or architecture problem under another category.
- Every run was judged with the same two prompts by independent Claude Opus 5.5 judges, step 4 on the anonymized delivery and EXPL blind to everything else. Five readings were rewritten, and three written, because the ranks and
  the costs they quoted changed; none names a flaw, a count of flaws or a location. `results/README.md` and `results/results.json` are regenerated. No protocol text, tool, weight, penalty or schema changes. The 140 tests are unchanged.

### `0.2.134` — 2026-10-09 — The session time of a two-stage run leaves out the operator's wait between the stages

- The operator's wait between the end of stage 1 and the stage 2 message is not the agent's time, and the session times published for LEB-300-A had it inside them: 20 minutes in GPT-5.6-terra's, 10 in Kimi K3's, 13 in one
  of MiniMax-M3's runs and 13 in Claude Sonnet 5.5's. `tools/export-results.py` now reads `operator_wait_minutes` of a run's `run.json` and subtracts it from the minutes between the first message and the end of the session.
  Only that wait is taken out: an idle stretch inside a stage (a slow provider, a long test run) is the agent's session, and no operator reply exists inside a stage (`operator_replies` is 0). A run without the field is unchanged,
  so no LEB-100 number moves (they are single-stage).
- The wait was measured for all nine LEB-300-A runs from their own logs (Claude Code transcript, OpenCode database, Codex rollout log), from the end of the agent's last message of stage 1 to the stage 2 message: from 0.1 minute
  (Claude Haiku 4.5's second run) to 20.1 (GPT-5.6-terra's). The session times change accordingly: Claude Sonnet 5.5 42.1 minutes (was 54.8), Kimi K3 69.4 (79.8), DeepSeek V4.1 Flash 35.6 (38.6), GPT-5.6-terra 32.1 (52.2),
  MiniMax-M3 42.3 and 53.1 (43.7 and 66.1) and Claude Haiku 4.5 12.4, 43.9 and 27.9 (13.4, 44.0 and 28.4). Scores, grades and costs do not move.
- The written readings that quoted those times are corrected. 1 new test (140 in all). `results/README.md` is unchanged: it carries no time.

### `0.2.133` — 2026-10-08 — The LEB-300-A aggregate lists Kimi K3

- `results/2026/LEB-300-A/aggregate.json` goes from five agents to six. Kimi K3 at high effort is second with 737 of 1000 (Silver) on **one** run of three, in OpenCode: after Claude Sonnet 5.5 (860) and ahead of DeepSeek V4.1 Flash
  (649), GPT-5.6-terra (625), MiniMax-M3 (388, two runs) and Claude Haiku 4.5 (242, three). It has the agent id it has in LEB-100 (`moonshot-kimi-k3-high`) and the same model block, **except for the host**: the LEB-100 runs of
  this agent used Moonshot's own API and this one was served by Novita AI (opencode provider `novita-ai`), which its run record says. The operator took 10 minutes between its two stages, and its session time (80 minutes) includes it.
- Judged with the same two prompts as every run, by independent Claude Opus 5.5 judges. Four readings were rewritten, and one written, because "second", "third", "the longest runs" and "more than five times any other line"
  stopped being true; none names a flaw, a count of flaws or a location. `results/README.md` and `results/results.json` are regenerated. No protocol text, tool, weight, penalty or schema changes. The 139 tests are unchanged.

### `0.2.132` — 2026-10-08 — The LEB-300-A aggregate lists Claude Sonnet 5.5 and GPT-5.6-terra

- `results/2026/LEB-300-A/aggregate.json` goes from three agents to five. Claude Sonnet 5.5 at xhigh effort leads with 860 of 1000 (Gold) on **one** run of three, in Claude Code; GPT-5.6-terra at xhigh is third with 625
  (Silver, one run, in Codex CLI), ahead of MiniMax-M3 (388, two runs) and Claude Haiku 4.5 (242, three), and DeepSeek V4.1 Flash (649, one run) is second. A line on fewer than three runs is not official and says so.
- Both new agents have the agent ids they have in LEB-100 (`claude-sonnet-5.5-xhigh`, `gpt-5.6-terra-xhigh`) and the same model blocks. Codex CLI keeps no cost, so the GPT-5.6-terra line carries none (`cost_usd` null); the operator
  took 20 minutes between its two stages, and its session time (52 minutes, 32 of work) includes that wait, as the definition of session time says.
- Two judge calls worth knowing, both kept as judged: PEN-001 (−15) was applied to GPT-5.6-terra for a new, recoverable concurrency fault in one of its fixes (640 without it), and COMP-008 was counted once on Claude Sonnet 5.5
  (compatibility 75) for a change in how a contracted header behaves between users of one company.
- Every run was judged with the same two prompts by independent Claude Opus 5.5 judges, step 4 on the anonymized delivery and EXPL blind to everything else. Three readings were rewritten, and two written, because "the strongest
  result so far" and "the most expensive run" stopped being true; none names a flaw, a count of flaws or a location. `results/README.md` and `results/results.json` are regenerated. No protocol text, tool, weight, penalty or
  schema changes. The 139 tests are unchanged.

### `0.2.131` — 2026-10-08 — The LEB-300-A aggregate carries each agent's runs and a short written reading

- `scoring/publicacao-agregada.schema.json` gains two optional fields per agent: `runs` (the total, the cost and the session time of each run, by run number, which the published score is the lower median of) and `comment` (a short
  reading in English and Brazilian Portuguese, up to 1200 characters each). Nothing about a flaw gets a field: the schema still has none.
- `tools/export-results.py --publish-aggregate` reads the readings from `<private root>/<instance>/comments.json`, refuses one that names an agent that does not exist, and runs the existing leak guard over them like over the
  rest of the aggregate: a flaw id or a long text of the matrix in a reading stops the publication and writes nothing. The README line of an agent with more than one run now shows its totals, as the open instances do.
- `results/2026/LEB-300-A/aggregate.json` carries the three readings (DeepSeek V4.1 Flash, MiniMax-M3, Claude Haiku 4.5). They speak of categories, scores, runs, cost and time, and name no flaw, no count of flaws or decoys and
  no location. The sites show each as the comment of its card, the way LEB-100 does.
- 5 new tests (139 in all): the runs line by line, a reading in both languages and only for its agent, a reading that names a flaw or quotes the matrix, an unknown agent, and a reading in one language or too long.

### `0.2.130` — 2026-10-08 — The LEB-300-A aggregate lists DeepSeek V4.1 Flash and MiniMax-M3, with the runs each rests on

- `results/2026/LEB-300-A/aggregate.json` goes from one agent to three. DeepSeek V4.1 Flash at high effort leads with 649 of 1000 (Silver) on **one** run of three; MiniMax-M3 with its thinking variant has 388 (Reprovada) on **two**
  runs of three (388 and 398, the published score being the lower of two); Claude Haiku 4.5 keeps its 242 on three. A line that rests on fewer than three runs is not official and says so: the same rule as the LEB-100 table.
- Both new agents are the same agent ids as in LEB-100 (`deepseek-v4.1-flash-high`, `minimax-m3-thinking`), each on its provider's own API, and they ran in OpenCode 1.18.33 while Claude Haiku 4.5 ran in Claude Code. OpenCode keeps
  no model time, so those lines carry the cost and the session time of the session record and nothing else.
- Every run was judged with the same two prompts by independent Claude Opus 5.5 judges, step 4 on the anonymized delivery and EXPL blind to everything else. No flaw, verdict, delivery or mechanical report is published: the
  instance is active and its matrix declares `publication: aggregate`.
- What the record still does not have: no checkpoint was taken between the two stages of task 1.2.0 in any run (`etapas.py` reads only the Claude Code transcript layout, so the OpenCode runs could not have one), so their
  conformity is inconclusive; and the task text the agents read names the previous matrix hash (`3331a107`) while the matrix they are scored against (`c42c8287`) differs from it only by the header field `publication`.
- `results/README.md` and `results/results.json` are regenerated from it. No protocol text, tool, weight, penalty or schema changes. The 134 tests are unchanged.

### `0.2.129` — 2026-10-08 — The LEB-300-A aggregate rests on three runs of its first agent

- `results/2026/LEB-300-A/aggregate.json` now holds the three runs of Claude Haiku 4.5 at its default effort: totals 242, 218 and 272. The published score is the lower median, 242 of 1000, grade Reprovada, with
  the categories, the US$0.96 and the 13.4 minutes of the run it belongs to. Three runs is what an official score needs; the instance itself is still an exploratory pilot, and its difficulty has not been homologated.
- The three runs were judged with the same two prompts by independent Claude Opus 5.5 judges, step 4 on the anonymized delivery and EXPL blind to everything else. No flaw, verdict, delivery or mechanical report is
  published: the instance is active and its matrix declares `publication: aggregate`.
- What the record still does not have, because the aggregate cannot carry notes: no checkpoint was taken between the two stages of task 1.2.0 in any of the runs, so their conformity is inconclusive; and the task text the
  agents read names the previous matrix hash (`3331a107`) while the matrix they are scored against (`c42c8287`) differs from it only by the header field `publication`. The scoring is the same.
- `results/README.md` and `results/results.json` are regenerated from it. No protocol text, tool, weight, penalty or schema changes. The 134 tests are unchanged.

### `0.2.128` — 2026-10-08 — The first LEB-300-A result is published, as an aggregate

- New `results/2026/LEB-300-A/aggregate.json`, written by `tools/export-results.py --publish-aggregate`: one agent, Claude Haiku 4.5 at its default effort, one run of the three an official
  score needs, total 227 of 1000, grade Reprovada, the score of each category, US$0.96 and 13.4 minutes as the client reported them. It is an exploratory pilot, not an official result.
  No flaw, verdict, delivery or mechanical report is published: the instance is active, and its matrix declares `publication: aggregate`.
- `results/README.md` and `results/results.json` are regenerated from it, and the instance index lists LEB-300-A as an exploratory pilot.
- What the record does not have, stated here because the aggregate cannot carry notes: no checkpoint was taken between the two stages of task 1.2.0, so the conformity of the run is inconclusive;
  and the task text the agent read names the previous matrix hash (`3331a107`), while the matrix the run is scored against (`c42c8287`) differs from it only by the header field `publication`.
  The scoring is the same.
- One test is rewritten: it said the published `results.json` had no `aggregate_instances` key, which was true only until the first aggregate. It is now two guards: an aggregate must
  validate against its schema, name no flaw and have no per-flaw entry, and the folder of an aggregate instance holds only its `aggregate.json`. 134 tests pass.
- No protocol text, tool, weight, penalty or schema changes.

### `0.2.127` — 2026-10-08 — docs/candidate-vm.md describes the final layout of the candidate VM

- The agent works directly in the package folder, which the runner owns; there is no copy to make for each run. The earlier text made that folder read-only for the runner, and
  the first runs showed what follows: the agents copied the package to `/tmp` and delivered there. The page now says so, and lists it among what went wrong.
- The test database, the offline Maven cache and a proof that they work are made once, before the snapshot, so a run is: restore, open the client, send the first message.
  The proof runs on a throwaway copy, never in the work folder. The `inicio` marker is gone, since a restored VM holds only the transcripts of its run.
- The Claude Code Artifact tool is turned off, because it publishes to the logged-in account and an agent used it to publish its report; OpenCode's sharing is disabled.
- A new section describes the read-only check of a VM, and the page states that `tools/etapas.py` reads the Claude Code transcript layout only: OpenCode (SQLite) and Codex
  (another `.jsonl` layout) need their evidence kept by hand.
- Documentation only: no protocol text, instance, tool, weight, penalty or schema changes. The 133 tests are unchanged.

### `0.2.126` — 2026-10-08 — docs/candidate-vm.md covers OpenCode, the model pin and the Codex sandbox

- `docs/candidate-vm.md` now lists OpenCode among the clients (version, the state that is cleaned, and why `model.json` matters), says how the work folder is made trusted in
  Claude Code and Codex, and tells the reader to pass the model on the command line because a default can sit in one VM's settings.
- It records a measurement: Codex's default sandbox writes to the work folder but cannot connect to the local PostgreSQL, so a run needs hand-approved escalations or the
  bypass flag. Four rows are added to the list of what went wrong.
- Documentation only: no protocol text, instance, tool, weight, penalty or schema changes. The 133 tests are unchanged.

### `0.2.125` — 2026-10-08 — docs/candidate-vm.md says how to rebuild the VM that runs the agents

- New `docs/candidate-vm.md`: the reference build of the three candidate VMs (hardware, OS, toolchain, locale), the accounts and the `/srv` layout (pristine package,
  work copy, operator messages kept unreadable to the runner), the four items sent to the VM and how they are checked, what is done before every run, the client profile,
  the snapshot routine, how to check a VM before the first run, how to run `tools/etapas.py` on it, the mistakes found while preparing it with their fixes, and the limits.
- The client profile is written down as current practice: each client at its factory default with no equalization, the web not restricted, and the few deviations that keep
  the operator's own account content (synced skills and connectors) and the state of earlier sessions out of a run.
- README: a row in the Documents table and a pointer from "Execution environment". The GitHub layers described there are unchanged.
- It is a record of practice, not a rule: no text of the protocol, no instance, no tool, no weight, penalty or schema changes. The 133 tests are unchanged.

### `0.2.124` — 2026-10-07 — Task 1.2.0 puts a run in two stages, with a checkpoint that keeps the evidence

- New task `protocol/tasks/TAREFA-1.2.0.md`, derived from 1.1.0: stage 1 covers architecture, bugs, performance, quality and compatibility and
  leaves the specific investigation of security vulnerabilities to stage 2. It never asks to keep a vulnerability, and an incidental improvement
  stays. One budget serves both stages. The canonical statement is byte for byte the 1.1.0 one, and `TAREFA.md` (1.0.0) and `TAREFA-1.1.0.md`
  are unchanged, pinned by hash in a test. The two operator messages are versioned in `protocol/tasks/mensagens-1.2.0.json`.
- New `tools/etapas.py` (one file, standard library, copyable to the execution VM): `checkpoint` keeps a read-only copy of the stage 1 delivery
  and the position reached in every transcript, and refuses while a transcript is still moving; `finalize` keeps the final delivery and writes the
  verdict and the budget per stage; `check` gives the verdict without writing; `perfis` shows the checkpoint and the final scorecards side by
  side with no combined total.
- The verdict is conforming, non-conforming or inconclusive, from the whole transcripts, subagents included, against the recorded boundary.
  Missing records are inconclusive, never a proof of a single model. A switch after the checkpoint does not invalidate the run. The attribution
  names the sequence observed and no cause.
- `protocol/PROTOCOL.md §3.1` states the protocol: messages, checkpoint, shared budget, fallback on in the pilot's candidate profile, conformity,
  scoring (official on the final delivery, the checkpoint informative, no mixing) and wording. It is separate from runs that require one model.
- Nothing about scoring changes: no weight, penalty, rubric or schema. `score.py` is untouched.
- Tests: `tests/test_etapas.py` and `tests/test_tarefa_1_2_0.py`, on synthetic transcripts, deliveries and scorecards (133 tests in all).
  Also run against a real finished session with subagents (conforming) and against a live one (refused as not ready).

### `0.2.123` — 2026-10-07 — A read-only tool says which model answered, and when it changed

- New `tools/modelos-usados.py`. It reads Claude Code transcripts (a file, or a directory searched for `*.jsonl`, which takes in the
  subagents' files) and reports, per file, each model that wrote an assistant message with its messages, tool calls and first and last
  instant, and every point where the model changed. It exits 1 when a switch is found.
- `--not-before <instant>` allows a switch only at or after that instant. It is meant for a run in stages: pass the moment the first
  stage's delivery was saved, and the exit code says whether everything before it was written by a single model.
- Until now a switch (`PROTOCOL §3`, *One model per run*) was found by reading the session log by hand. The tool changes no rule and no
  score. It does not know other clients, nor which model was requested, only the one that answered.
- New `tests/test_modelos_usados.py` (7 cases, synthetic transcripts): one model, a switch with its instant, ignored synthetic and damaged
  lines, subagent files, the `--not-before` boundary, unreadable input, and that it writes nothing. The suite is 104 tests.

### `0.2.122` — 2026-10-07 — The text now states how C4 and PEN-002 are applied, with no score changed

- `SPEC.md §6.2` and `scoring/SCORING.md §2` now say what `harness/score.py` does today: C4 is one boolean for the whole delivery, so one
  broken characterization test turns C4 to `none` for every attempted Template C flaw (unless the verdict gives an explicit C4), and PEN-002
  is charged on top, −20 per broken test. The localized reading in the §6.2 disambiguation is not what the assembler applies, and the same
  broken test is charged twice. The 1.4.0 release note no longer points at a separate proposal.
- `results/2026/LEB-100-A/README.md`, *Before quoting a number*: five of the 92 runs broke characterization tests, two of them define an
  official score (DeepSeek V4 Flash at `high`, Nex N2.5 Pro at `high`), and none of the 1,196 published per-flaw verdict entries gives an
  explicit C4. Nothing was recomputed.
- Text only. No code, schema, weight, penalty or result changed, and the spec version stays 1.4.0 because only descriptive text was added.
  Whether the code or the text should change is left as a separate decision. Checked: the 97 tests pass, which includes the 95 published
  scorecards reproduced by `score.py`, and `tools/export-results.py --check` is up to date (92 runs).

### `0.2.121` — 2026-10-07 — PEN-003 has a ruler for task 1.1.0 or later, checked by ten cases

- `scoring/JUDGE.md` gets a section on PEN-003 when the task is 1.1.0 or later. The judge decides it from six elements taken together (public
  surface, contracted behavior, declared stack, changes tied to concrete problems, proportionality, no indiscriminate replacement) and never
  from the number of classes, files or lines changed. Internal reorganization needed to fix an architecture defect is explicitly allowed.
  PEN-003 is registered as 0 or 1 per run. Which of COMP or PEN-002 takes a contract fact follows how it was detected.
- It applies only to task 1.1.0 or later. Every published LEB-100-A run is on task 1.0.0 and is judged as before. No weight, penalty or
  value changes, and `SPEC §6.2` is untouched.
- New `scoring/pen003-cases.md`: ten fictional deliveries with the expected outcome of each, kept apart from the descriptions. Two blind judges
  (Sonnet 5.5 and Opus 5.5) took the PEN-003 decision the same way in all ten in the first round; their secondary labels differed in two
  cases, which exposed a gap in the text, fixed with one sentence. In the second round both matched the table in all ten. The limits of that
  proof are written in the file.
- New `tests/test_pen003_casos.py` (7 cases): the cases file keeps a closed set of outcomes, covers both sides of the boundary, can be given
  to a judge without the answers, and the section names the six elements. The suite is 97 tests.

### `0.2.119` — 2026-10-06 — Spec 1.4.0: the extensions are documented, and nothing about scoring changes

- `SPEC.md` is version 1.4.0, with release notes for every extension of `0.2.110` to `0.2.118` and a new invariant (§9.8: an active instance
  publishes an aggregate only, and not even its retirement releases anything). The note on version 1.4.0 states that the §6.2 text on
  C4 and the way `score.py` applies C4 and PEN-002 are still two different rules, documented in a separate proposal and not touched here.
- `matrix/MATRIX.md`: the optional entry fields (`dimensions`, `decoy_kind`, `informative_affected`), the optional header fields
  (`task_version`, `publication`, `pack_allow_paths`), the rule that `evidence` is written as the symptom, and §4.6 on active instances.
- `harness/README.md`: the package leak guard, the per-instance runner, and the test and tool commands. `README.md` and `README_br.md`
  carry spec 1.4.0.
- The comments, schema descriptions and the `scoring/JUDGE.md` paragraph added in `0.2.110` to `0.2.118` are in English (US).
- Verified after the extensions, against the real LEB-100-A: the legacy mechanical run is identical before and after (timings aside),
  the 95 scorecards are reproduced, the package hash is the published one and `export-results.py --check` is green.

### `0.2.118` — 2026-10-06 — A read-only tool says whether an instance still tells agents apart

- New `tools/saturacao.py`. For one instance it prints, per category, the share of runs at the category maximum, the mean, standard
  deviation and minimum, and per planted flaw the rate of found (C1/R1 full or half), fixed completely (C3/R3 full) and points earned.
  It declares its base first: by default one run per agent, the representative run that counts toward the score (the lower median,
  `counts_in_score`), so 37 of the 92 LEB-100-A runs; `--all-runs` uses every run.
- Two sources, never mixed: a published instance from `results/runs.csv` and `results/flaws.csv`, or an ACTIVE instance from the private
  archive (`--private ROOT --instance NAME`, which prints a PRIVATE REPORT notice because it names flaws). It writes nothing and changes no score.
- Demonstrated on LEB-100-A before any use: at the category maximum PERF 28 of 37, SEC 3, BUG 3, ARCH 0, CLN 4, COMP 24, EXPL 0, total
  min 282, mean 579, max 809 — the numbers measured in the F0 study, now reproduced by the tool and pinned by a test.
- Tests: `tests/test_saturacao.py` (8 cases: published demonstration, declared base, nothing written, active mode on a synthetic archive).

### `0.2.117` — 2026-10-06 — An active instance is published as an aggregate, by an explicit command, and nothing else

- A matrix header may declare `"publication": "aggregate"` (an ACTIVE instance). `tools/export-results.py --publish-aggregate <instance>
  [--edition <year>]` is then the only way anything about it reaches `results/`: it reads the private archive (`LEB_PRIVATE_RESULTS`, else
  `LEB_RUNS_DIR`, laid out `<root>/<instance>/<agent>/run-<n>/`) and writes `results/<edition>/<instance>/aggregate.json`
  (`scoring/publicacao-agregada.schema.json`): hashes, protocol, and per agent the score of the representative run (lower median),
  grade, category scores, cost and time. There is no field for a flaw, a verdict, a delivery or a test name.
- Before writing, the aggregate must validate against the schema and must not contain any flaw id or any long text of the matrix. A normal
  run never reads the private archive: it reads the `aggregate.json` files already published, adds them to the leaderboard
  (`README.md`, a section "aggregate only") and to `results.json` as an additive `aggregate_instances` key, and writes no `flaws.csv` or
  `runs.csv` row and no scorecard for them.
- The folder of an aggregate instance (published, or declared so by a matrix the exporter can see) holds nothing but `aggregate.json`;
  a normal run and `--check` fail otherwise. Retiring an instance publishes nothing: the exporter has no release or retire flag.
- Without the declaration everything is as before: `export-results.py --check` still passes on the 92 published LEB-100-A runs and
  `results.json` has no new key. New: `harness/jsonschema_lite.py` (the schema validator, shared by the exporter and the tests) and the
  header properties `task_version`, `publication` and `pack_allow_paths` in `matrix/matrix.schema.json`.
- Tests: `tests/test_agregado.py` (13 cases on a synthetic tree: public instance, active instance, private archive).

### `0.2.116` — 2026-10-06 — The package leak guard reads content, matches whole words, and proves it can see before it looks

- `harness/pack.py`: the path patterns (`matrix`, `matriz`, `private`, `verify`, `characterization`, `probes`) now match **whole words** of
  a path segment instead of substrings: `VerifyToken.java` and `MatrixController.java` no longer refuse a package, `matrix_x.json`,
  a `verify/` directory or `Probes.php` still do. An instance whose code has a legitimate name equal to a pattern lists the path in
  `pack_allow_paths` in the matrix header; the list exempts a path (never a word, never content).
- New content scan of every text file in the package for markers derived from the matrix: the texts of `evidence`, `expected_fix`,
  `location` and `notes` with 20 or more characters, and the paths of the private destination (the instance's `private/`, `LEB_PRIVATE_RESULTS`,
  `LEB_RUNS_DIR`). Comment leaders and line wrapping do not hide a text. The public matrix hash in the header of `TAREFA.md` is not a marker.
  An `evidence` that is a verbatim line of the delivered code would match the code itself: the packaging fails safe and the fix is to write
  the evidence as the symptom.
- Positive control on every packaging run: the guard first scans a temporary copy with a planted `private/` folder and a planted
  marker and must report both, or the packaging aborts. A guard that has not shown it can see does not count.
- The LEB-100-A package passes the new guard and its published `package_sha256` is unchanged (tested); none of the 34 long texts of its
  matrix occurs in its code, so the content scan is not vacuous there either.
- Tests: `tests/test_guarda_vazamento.py` (14 cases, synthetic instances).

### `0.2.115` — 2026-10-06 — Informative fields on the matrix reach the scorecard and the exports, and never the points

- A matrix entry may carry `dimensions` (`concorrencia`, `consistencia`, `resiliencia`), `decoy_kind` (`diagnostico`, `intervencao`; decoys
  only) and `informative_affected` (tests and contracts a fix touches). `harness/score.py` copies them to the finding and, only when they
  exist, adds `dimension_breakdown` (planted, detected, corrected per dimension) and `decoy_breakdown` (decoys and reported, per kind) to
  the scorecard. A mechanical report may also carry `informative_affected` per flaw.
- `tools/export-results.py` adds `dimensions` to the instance's flaws and `decoy_kinds` to `results.json`, and appends a `dimensions`
  column at the end of `flaws.csv`, all only when the matrix declares them. Without the fields the outputs are byte for byte what they
  were: `export-results.py --check` still passes on the 92 published LEB-100-A runs.
- No weight, point or penalty reads these fields (tested: same totals, categories, penalties and criteria with and without them).
  Schemas: `matrix/matrix.schema.json` and `scoring/scorecard.schema.json` gain the optional properties.
- Tests: `tests/test_informativos.py` (7 cases) and a synthetic published-tree fixture in `tests/helpers.py`.

### `0.2.114` — 2026-10-06 — Structural evidence can cap the refactoring criterion of Template R, and never raise it

- `harness/score.py`: a probe that carries `result` (`full|half|none`) **and** `proves` naming `R3` is evidence for the R3 of an
  ARCH or CLN flaw. `R3_final = min(R3_judge, evidence)` (order `none < half < full`; a judge that omitted R3 counts as `none`).
  Evidence never raises R3 and never fills R1, R2 or R4; the existing rule that R4 only counts when a refactoring was attempted
  applies to the lower R3. `proves` naming anything but `C3` or `R3`, or `R3` without `result`, makes the assembler refuse the report.
- The scorecard gets `evidence: {R3, judge_R3, applied_R3}` for each flaw that had evidence, and no key otherwise.
- The legacy boolean `corrigida` is still ignored for Template R and still decides C3 for Template C. Reports without `proves` are
  scored exactly as before: the 95 published scorecards are still reproduced (test) and the LEB-100-A results are untouched.
- `scoring/probe-result.schema.json` (new), `evidence` in `scoring/scorecard.schema.json` (optional), and one additive paragraph in
  `scoring/JUDGE.md`. No existing score or penalty changes.
- Tests: `tests/test_score_evidencia.py` (13 cases: the eight prototype cases plus half/half, schema checks and C3 control).

### `0.2.113` — 2026-10-06 — An instance can declare its own runner, and an unreadable measurement is inconclusive, never a pass

- `harness/leb_harness.py` reads `private/runner.json` (`runner.caracterizacao.cmd`, `runner.verificacao.cmd`, optional `timeout_s`).
  Each command runs with the instance's `private/` as cwd and gets `LEB_ENTREGA_DIR`, `LEB_INSTANCIA_DIR` and `LEB_RUN_DIR`;
  stdout carries one JSON object (`{"passed", "failed"}` for the characterization, `{"probes": [...]}` for the verification).
  A probe says `corrigida` (boolean) and/or `result` (`full|half|none`), may carry `unobserved`, `affected` and `proves`
  (`C3`/`R3` only). The exit code of a command never decides regression: the report does (submission fails more than baseline).
- Output that cannot be read, an unknown or repeated probe id, a malformed probe, a crash or a timeout (the whole process group
  is killed) make the report `inconclusive` and the harness exit 3. `leb` prints it as INCONCLUSIVO and `harness/score.py`
  refuses to score such a report. Planted flaws with no probe are listed in `unverified`.
- An instance without `runner.json` takes exactly the old docker/PHP path: a real LEB-100-A mechanical run before and after
  gives identical reports once the timing fields are ignored. No scoring, penalty or published result changes.
- Tests: `tests/test_runner.py` (17 cases, synthetic instance and synthetic runner scripts).

### `0.2.112` — 2026-10-06 — One resolver finds an instance in either layout, wherever it lives

- New `harness/instances.py`, used by `leb`, `harness/pack.py`, `harness/leb_harness.py` and `tools/export-results.py`.
  It understands the legacy layout (`instances/<id>/{code, manifest.md, private/}`) and a split one
  (`instances/<id>/{public/{code, manifest.md}, private/}`, where `public/` means "meant for the candidate", not "publishable").
- `LEB_INSTANCES_PATH` (a `:`-separated list of roots that hold `instances/`) is searched first, so an instance can live
  outside this repository; `LEB_RUNS_DIR` moves the run areas and packages out of the tree too. Without them nothing changes,
  and LEB-100-A resolves to the same files (tested).
- `./leb pacote`, `./leb scorecard` and `./leb instancias` work end to end on a split instance kept outside the repository
  (tested with a synthetic one). No scoring, penalty or published result changes.

### `0.2.111` — 2026-10-06 — Each instance declares the version of the canonical task it is evaluated against

- `protocol/TAREFA.md` stays the task 1.0.0, byte for byte, and LEB-100-A stays on it. Later versions live in
  `protocol/tasks/TAREFA-<version>.md`; the new `protocol/tasks/TAREFA-1.1.0.md` lets the agent create, split, move and
  rename files while keeping the manifest, and says that not rewriting the system does not mean keeping its internal
  organization. The quoted canonical statement is identical in both.
- The matrix header may carry `task_version` (absent means 1.0.0). `harness/pack.py` picks the template by it, refuses an
  unknown version and refuses a template whose version differs from the declared one.
- `PROTOCOL §2.2` and `SPEC §9.4` now say the task is the same across instances that declare the same version, and that
  comparisons need the same task version. No scoring, penalty or published result changes; the LEB-100-A package hash
  is still the published one (tested).

### `0.2.110` — 2026-10-06 — The tooling has tests that prove the published LEB-100-A results are still reproduced, and a workflow that runs them

- New `tests/` (standard library only, no network, no Docker, synthetic fixtures and the published results only):
  every published `scorecard.json` is reproduced by `harness/score.py` from its own `mecanico.json` and `veredito.json`
  (95 of 95); the LEB-100-A package rebuilt in the published protocol (mode A, 30 turns) has the published SHA-256;
  `tools/export-results.py --check` finds nothing to change; and the suffix `.a`/`.b` that MATRIX §2 already allows for two
  occurrences of one taxonomy type scores each occurrence independently and normalizes the category over both.
- New `.github/workflows/tests.yml` runs them on every push and pull request.
- No scoring, penalty, identifier or published result changes.

### `0.2.109` — 2026-10-06 — Nex N2.5 Pro's void record names the operator by role, not by pronoun

- `nex-n2.5-pro-high/void-1/VOID.md` said "his side" and "he judged"; it now says the operator's side and that the
  operator judged it too slow to repeat.

### `0.2.108` — 2026-10-06 — Nex N2.5 Pro's third run is voided for an operator connection failure and not repeated

- `nex-n2.5-pro-high/void-1/`: the bench3 run, 21+ hours and US$ 6.05, aborted when the operator's connection
  failed and again after the operator's "contnua"; voided before any judge saw it. The operator will not repeat
  it, the model being too slow (runs of 10h 03min, 19h 35min and 21+ hours): the agent stays at two runs.
- Instance notes: one bullet. `comments.json`: Nex's comment says it is too slow to finish three runs.

### `0.2.107` — 2026-10-06 — results/ adds Nex N2.5 Pro's run 2 (428); it publishes the lower, 317, still 35th

- `nex-n2.5-pro-high/run-2` (CR): 428, Bronze, on bench2 alongside run 1, 19h 35min, US$ 4.87; the session ended on
  the output-length limit. 10 flaws fixed; 3 characterization checks fail on a new headers_sent() guard in the
  export (PEN-002 and COMP-005), COMP-003 for the SLA average, PEN-001 for the tab before '-'; explanation 33/50.
- Instance notes: row CR, the count to seventy-two, one bullet. `comments.json`: Nex's comment covers both runs.

### `0.2.106` — 2026-10-06 — The instance notes no longer say every delivery from Y on avoided the web

- 0.2.105 extended "all seventy ran … used no web tool and made no request to GitHub" to seventy-one, but CQ
  (Nex N2.5 Pro) read OWASP's page, searched Google and tried GitHub. The sentence now excepts CQ and points to
  its bullet, and says the operator sent nothing after the fixed message, which holds for all seventy-one.

### `0.2.105` — 2026-10-06 — results/ adds Nex N2.5 Pro at high as a new agent: run 1 scores 317, below the pass line

- `nex-n2.5-pro-high/run-1` (CQ): 317, Failed, 35th, in opencode through OpenRouter on bench1, 10h 03min, US$ 2.80.
  Every public function returns nothing without a session: 8 of 22 characterization checks fail (PEN-002, −160);
  one COMP-003 (the SLA average scoped to each client); explanation 31/50. Released 2026-09-08 with no published
  cutoff: dagger.
- Instance notes: table row CQ, the count to seventy-one, one bullet. `comments.json`: its comment.

### `0.2.104` — 2026-10-05 — The multi-agent Sonnet's run times are quoted from the session record, in hours and minutes

- Instance notes: run 1 of Sonnet 5.5 in multi-agent mode took 7h 55min by its session record (13:05 to
  21:00), not the 8.1 hours quoted before; runs 2 and 3 are 2h and 3h 34min, and model time is 16h 24min,
  15h 54min and 8h 28min. Times are written as hours and minutes, not decimal hours.
- `comments.json`: the multi-agent Sonnet's range is 2h to 7h 55min; Gemini 3.8 Flash at medium took 4min 34s
  (it said 4.5 minutes).

### `0.2.103` — 2026-10-05 — results.json gives each run its wall-clock minutes, from the session's start and end

- `tools/export-results.py`: each run in `results.json` carries `wall_minutes`, the minutes from the
  session's first message to its end as `run.json` records them (`session.started` / `session.ended`),
  null when either is missing. All 90 runs have both. `runs.csv` already had the same value; both now
  come from one function.
- The results pages show it run by run in each card's sheet.

### `0.2.102` — 2026-10-05 — results/ adds a written comment for each of the 36 agents, exported into results.json

- `results/2026/LEB-100-A/comments.json`: one to three sentences per agent, in English and Brazilian
  Portuguese (the PT copy is end-user text for the results pages), drawn from the scorecards and verdicts.
- `tools/export-results.py` copies it into each entry as `comment` (null when absent) and refuses an id
  that names no agent.
- Instance notes: a short "Agent comments" section saying what the comments are and are not.

### `0.2.101` — 2026-10-05 — results/ adds GPT-5.5's run 4 (536); with three counted runs it is official again at 558

- `gpt-5.5-xhigh/run-4` (CP): 536, Bronze, in Codex CLI. 7 flaws fixed, compatibility 70 (the SLA average
  scoped to each client), explanation 32/50. Numbered run-4 because the withdrawn run 1 keeps its number.
- With runs 2 to 4 (558, 568, 536) GPT-5.5 is official at 558, 26th; twenty-six agents are official.
- Instance notes: table row CP, one bullet, the official count and list.

### `0.2.100` — 2026-10-05 — results/ adds DeepSeek V4 Flash at xhigh as a new agent: run 1 scores 617, 18th

- `deepseek-v4-flash-xhigh/run-1` (CO): 617, Silver, 18th, through Novita AI for US$ 0.18. 7 flaws fixed,
  compatibility 100, explanation 31/50. It is separate from DeepSeek V4 Flash at high (282).
- Instance notes: table row CO and one bullet.

### `0.2.99` — 2026-10-05 — results/ adds GPT-6-astra at ultra's third run (651); the agent is official at 666, the strongest official GPT agent

- `gpt-6-astra-ultra/run-3` (CN): 651, Silver, in Codex CLI with two subagents. 8 flaws fixed,
  compatibility 70, explanation 45/50.
- With three runs (668, 666, 651) GPT-6-astra at ultra is official at 666, 6th, above GPT-6.1-sol (661).
  Twenty-five agents are official.
- Instance notes: table row CN, one bullet, the official count and list.

### `0.2.98` — 2026-10-05 — results/ adds GPT-6-astra at ultra's second run (666); it publishes 666, still 6th

- `gpt-6-astra-ultra/run-2` (CM): 666, Silver, in Codex CLI with three subagents. 9 flaws fixed,
  compatibility 70 (the SLA average scoped to each client), explanation 43/50.
- Instance notes: table row CM and one bullet, including the separate gpt-6-luna session before the run.

### `0.2.97` — 2026-10-05 — results/ adds Grok 4.7 at xhigh's second and third runs (617, 631); the agent is official at 631

- `grok-4.7-xhigh/run-2` (CK) 617 and `run-3` (CL) 631, Silver, run at the same time on xAI's API.
- With three runs (640, 617, 631) Grok 4.7 at xhigh is official at 631, 11th; twenty-four agents are
  official.
- Instance notes: table rows CK and CL, one bullet, the official count and list.

### `0.2.96` — 2026-10-05 — results/ adds Grok 4.7 at xhigh as a new agent: run 1 scores 640, 9th

- `grok-4.7-xhigh/run-1` (CJ): 640, Silver, 9th, on xAI's API for US$ 3.82. 8 flaws fixed, the CSV formula
  injection among them, compatibility 100, no penalty, explanation 44/50.
- Instance notes: table row CJ and one bullet.

### `0.2.95` — 2026-10-05 — results/ keeps a fourth Gemini 3.7 Flash run unscored

- `gemini-3.7-flash-high/void-1/`: started 13 seconds after run 3 and filed after it. It was set aside under
  `PROTOCOL §4` before judging, and kept with its delivery and mechanical report.

### `0.2.94` — 2026-10-05 — results/ adds Gemini 3.7 Flash's third run at high (556); the agent is official at 587

- `gemini-3.7-flash-high/run-3` (CI): 556, Bronze, on `bench3` for US$ 0.49. The same 7 flaws fixed,
  compatibility 70 ("Desconhecido" fallback), explanation 26/50.
- With three runs (587, 587, 556) Gemini 3.7 Flash is official at 587, 23rd; twenty-three agents are
  official.
- Instance notes: table row CI, one bullet, the official count and list.

### `0.2.93` — 2026-10-05 — results/ adds Gemini 3.7 Flash's second run at high (587, the same total as run 1)

- `gemini-3.7-flash-high/run-2` (CH): 587, Bronze, on `bench1` for US$ 0.60. The same 7 flaws fixed,
  compatibility 100, explanation 27/50. The agent publishes 587, 23rd.
- Instance notes: table row CH and one bullet.

### `0.2.92` — 2026-10-05 — results/ adds Gemini 3.7 Flash at high as a new agent: run 1 scores 587

- `gemini-3.7-flash-high/run-1` (CG): 587, Bronze, 23rd, in opencode through OpenRouter for US$ 0.72.
  7 flaws fixed, compatibility 100, explanation 27/50.
- Review change: SEC-014's C1 went from half to full. The flaw is filed under the wrong category, but it is
  also named in the non-changes list.
- Instance notes: table row CG and one bullet.

### `0.2.91` — 2026-10-05 — results/ adds Kimi K2.7 Code highspeed as a new agent with three runs (402, 363, 402), official at 402

- `kimi-k2.7-code-highspeed/run-1..3` (CD, CE, CF): 402, 363 and 402, all three run at the same time on
  2026-10-05. Official at 402, 30th. It is a separate Moonshot model id from `kimi-k2.7-code`.
- Review change: CE's two findings that called the contracted `formatarStatus` and priority-4 labels bugs
  moved from false positives to extra findings, as in BM. The label changes stay charged as COMP-003.
- Twenty-two agents are official. Instance notes: table rows CD to CF, one bullet, the official count and
  list.

### `0.2.90` — 2026-10-05 — A model's release date bounds its training cutoff when the provider publishes none; four agents lose the dagger

- `PROTOCOL §3`: when the provider publishes no training cutoff but does publish the model's release date
  (`model.release_date`, `release_date_source`), the date bounds the cutoff. A release before the answer key
  went public counts as `before`.
- `tools/export-results.py`: `key_exposure` applies the rule, and the scorecard's "Training cutoff" row cites
  the release and its source.
- `run.json` records the provider's release date for Qwen3 Coder Next (2026-02-03), MiniMax-M3 (2026-06-01),
  Kimi K2.7 Code (2026-06-12) and GLM-5.2 (2026-06-16), so those four lose the dagger.
- DeepSeek V4 Pro and V4 Flash keep it. Their 2026-04-24 release was a preview, and same-name updates came
  after the key (2026-07-31 and 2026-08-13).
- Instance notes: a section *Release dates bound the training cutoff*, and the older dagger sentences
  marked as superseded.

### `0.2.89` — 2026-10-05 — results/ adds the third runs of Kimi K3 at high (574) and Kimi K2.7 Code (512); both are official, at 629 and 415

- `moonshot-kimi-k3-high/run-3` (CB): 574, Bronze. Official at 629, the median of 629, 634 and 574, 11th.
- `kimi-k2.7-code-default/run-3` (CC): 512, Bronze. Official at 415, the median of 415, 415 and 512, 28th.
- Twenty-one agents are official. Instance notes: table rows CB and CC, two bullets, the official count
  and list.

### `0.2.88` — 2026-10-05 — results/ adds Kimi K2.7 Code's second run (415, the same total as run 1)

- `kimi-k2.7-code-default/run-2` (CA): 415, Bronze, on `bench2` in 4 minutes for US$ 0.25. 6 flaws fixed,
  compatibility 100, one false positive (SQL injection in the int-typed `verChamado`), explanation 26/50.
- Instance notes: table row CA and one bullet.

### `0.2.87` — 2026-10-05 — results/ adds Kimi K3's second run at high (634); its published score stays 629

- `moonshot-kimi-k3-high/run-2` (BZ): 634, Silver, on `bench2` in 21 minutes for US$ 1.02. 9 flaws fixed,
  compatibility 70 (the CSV 403 again), explanation 40/50. The agent publishes 629, 11th.
- Instance notes: table row BZ and one bullet.

### `0.2.86` — 2026-10-05 — results/ notes give Kimi K3's run 1 the 8 fixed flaws its scorecard has, not 9

- The BY bullet said 9 flaws were fixed; the scorecard fixes 8 (SEC-001, SEC-003, SEC-013, SEC-015, SEC-017,
  BUG-001, BUG-004, PERF-001).

### `0.2.85` — 2026-10-05 — results/ keeps two void attempts: a Kimi K3 run lost to a VM restore and a logged-out Fable 5.1 attempt

- `moonshot-kimi-k3-high/void-1/VOID.md`: a finished run lost when `bench3` was restored mid-copy, with its
  first message sent five times after Kimi Code plan key errors.
- `claude-fable-5.1-xhigh/void-3/VOID.md`: an expired login answered the first message locally.

### `0.2.84` — 2026-10-05 — results/ adds Kimi K3 at high as a new agent: run 1 scores 629

- `moonshot-kimi-k3-high/run-1` (BY): 629, Silver, 11th, in opencode on Moonshot's API. 9 flaws fixed,
  compatibility 70 (HTTP 403 on the CSV for every non-technician), explanation 36/50.
- Replaces the withdrawn default-effort Kimi K3 run with a recorded one; a new agent, since the effort
  differs.
- Instance notes: table row BY and one bullet.

### `0.2.83` — 2026-10-04 — results/ notes no longer call GPT-6-astra ultra the only report to catch the quoted CSV header

- The BX bullet said it was the only report to catch that `fputcsv` quotes the `Aberto em` header; the EXPL
  judge said only that most reports missed it. It now says "one of the few".

### `0.2.82` — 2026-10-04 — results/ keeps a void Grok 4.7 xhigh attempt whose VM was restored before its delivery was copied

- `grok-4.7-xhigh/void-1/VOID.md`: a finished run (40 responses, US$ 3.00) lost when `bench3` was restored
  mid-copy. Nothing was judged, so the new agent runs again from run 1.

### `0.2.81` — 2026-10-04 — results/ adds GPT-6-astra at ultra as a new agent: run 1 scores 668, the highest GPT run

- `gpt-6-astra-ultra/run-1` (BX): 668, Silver, 6th, in 11 minutes in Codex CLI with three subagents.
  SEC 228, BUG 150, PERF 150, compatibility 70 (the SLA average scoped to each client), explanation 45/50.
- Filed as a new agent, as GPT-6.1-sol at ultra was; GPT-6-astra at xhigh stays official at 628.
- Instance notes: table row BX and one bullet.

### `0.2.80` — 2026-10-04 — results/ adds Grok 4.6's third run (620); the agent is official at 633

- `grok-4.6/run-3` (BW): 620, Silver, in 5 minutes for US$ 0.28 on xAI's API. 7 flaws fixed,
  compatibility 100, explanation 35/50.
- With three runs (633, 640, 620) Grok 4.6 is official at 633, 9th; nineteen agents are official.
- Instance notes: table row BW, one bullet, the official count and list.

### `0.2.79` — 2026-10-04 — .continue/ queues the next steps from the comparison with Akita's v4 and LiveBench

- `.continue/proximos-passos-pos-comparacao.md` (new, in the queue's language): what LEB-100-A lacks next
  to Akita's LLM Benchmark v4 (39 models) and LiveBench Coding (66 models). That is presentation (cost
  and time per card, a cost × score chart, the flaws never fixed, a client filter, an open-weights mark),
  six new agents present in both lists, and a second instance. Closing the pending runs comes first and
  is tracked outside the document.

### `0.2.78` — 2026-10-04 — results/ withdraws the three runs with no recorded client: GPT-5.5 run 1, MiniMax-M3 and Kimi K3

- `gpt-5.5-xhigh/run-1` (601), `minimax-m3/run-1` (460) and `moonshot-kimi-k3-default/run-1` (528) move to
  `withdrawn-1/`, each with a `WITHDRAWN.md`. They are the only runs that fail `PROTOCOL §4` item 5, all
  filed in the first batch before session logs were archived.
- MiniMax-M3 at its default and Kimi K3 leave the leaderboard. GPT-5.5 keeps runs 2 and 3, publishes 558
  and is no longer official.
- The leaderboard has thirty agents and eighteen official scores.
- Instance notes: a section on the withdrawal, and the "Before quoting" caveats updated.

### `0.2.77` — 2026-10-04 — PROTOCOL §4 withdraws a run whose client, first message, session log or cost was not recorded

- `protocol/PROTOCOL.md` §4 item 5: a run counts only with its record complete. A run filed without it is
  withdrawn into `withdrawn-<n>/` with a `WITHDRAWN.md`, kept for audit, not published and not counted
  toward the three. The criterion is the record, never the result, and it applies to every failing run
  at once.
- `tools/export-results.py`: run numbers may skip a number that a `withdrawn-<n>` folder holds, so the
  published runs keep their numbers.

### `0.2.76` — 2026-10-04 — results/ adds Claude Sonnet 5.5's third multi-agent max run (759); the agent is official at 774

- `claude-sonnet-5.5-max-ultracode/run-3` (BT): 759, Gold, in 3.6 h and US$ 119.07. SEC 233, compatibility
  100, no penalty. Explanation 47/50 is the highest on LEB-100-A. SEC-008 was neutralised only in a new
  web export, so its probe on `exportarCsv()` counts it as not fixed. Seven subagents fell back to
  `claude-sonnet-5`; none of the delivery came from them.
- With three runs (774, 820, 759) the agent is official at 774, 3rd; nineteen agents are now official.
- BT was copied and judged on 2026-10-03, while the client was still open, and merged once it closed and
  its cost could be read. A duplicate matching judge started on 2026-10-04 was stopped before it wrote,
  and the first verdict stands.
- Instance notes: table row BT, one bullet, the official count and list.

### `0.2.75` — 2026-10-03 — results/ adds the third runs of GLM-5.3 (604) and Gemini 3.8 Flash at high (100); both now official, at 621 and 588

- `glm-5.3-high/run-3` (BU): 604, delivered in 9 minutes. It is official at 621, the median of 629, 621
  and 604.
- `gemini-3.8-flash-high/run-3` (BV): 100, with no report and its code untouched. It is official at 588,
  the median of 687, 588 and 100.
- Both sessions stopped on a shell command the agent itself started: a server whose output stayed on
  the tool's pipe. Before any judge saw them, the operator decided to score both as delivered.
- Instance notes: table rows BU and BV, one bullet per run, and eighteen official agents.

### `0.2.74` — 2026-10-03 — tools/export-results.py accepts a delivery without a RELATORIO.md

- The check that rejects two runs filed with the same report read every delivery's RELATORIO.md and
  stopped when one was missing. A delivery can lack it: Gemini 3.8 Flash's run 3 stopped before writing
  it, and scores as delivered. The check now skips a delivery with no report, which cannot be a resend
  of another run's report.

### `0.2.73` — 2026-10-03 — results/ adds Claude Sonnet 5.5's second multi-agent max run (820); its published score stays 774

- `claude-sonnet-5.5-max-ultracode/run-2` (BS): 820, Gold, in 2.0 h and US$ 171.18 (run 1: 8.1 h,
  US$ 233.08). SEC 250/250, compatibility 100, no penalty, explanation 45/50. Eight subagents fell back
  to `claude-sonnet-5`; none of the delivery came from them.
- Instance notes: table row BS and one bullet.

### `0.2.72` — 2026-10-03 — results/ keeps a sixth GPT-6.1-sol ultra run unscored and a void ultracode attempt that never reached the model

- `gpt-6.1-sol-ultra/void-3/`: a run started after the agent had its three runs; set aside under
  `PROTOCOL §4` before judging, kept with its delivery and mechanical report.
- `claude-sonnet-5.5-max-ultracode/void-2/`: the client's login had expired, so the first message was
  answered locally and the session was closed to log in again. Only a `VOID.md`; nothing was delivered.

### `0.2.71` — 2026-10-03 — results/ adds MiniMax-M3 thinking runs 2 and 3 (616, 434; official at 462), Grok 4.6 run 2 (640) and DeepSeek V4 Flash run 2 (282)

- `minimax-m3-thinking/run-2` (BO) and `run-3` (BP): with three runs the agent is official at 462,
  the median of 462, 616 and 434. Run 3 breaks the login on the contracted schema (PEN-002, −80;
  COMP-003). It was scored through the runners hardened in 0.2.70.
- `grok-4.6/run-2` (BQ): 640; the published score stays 633, the lower of two.
- `deepseek-v4-flash-high/run-2` (BR): 282, below the pass line. Its SQL-injection fix throws on every
  search, and the client CSV is written on one line. The published score drops from 612 to 282.
- Review changes: BO's BUG-004 C1 went from half to none, and BR's SEC-001 C5 from full to none.
  Both are recorded in the verdicts' notes.
- Instance notes: table rows BO–BR, one bullet per run, and the official count is now sixteen.

### `0.2.70` — 2026-10-03 — The characterization and probe runners go on when a delivery throws, instead of stopping uncounted

`instances/LEB-100-A/characterization/run.php` (with `_bootstrap.php`) and
`instances/LEB-100-A/private/verify/probes.php` now catch an exception from the delivery's code: the
check or probe that called it fails, with the error in its message, and the rest still run. Before,
the exception ended the run: the characterization printed no summary, so the harness counted no
broken check (no PEN-002) for a delivery that breaks login, and an unreadable probe report stopped
the harness. Two new deliveries do exactly that (a login that throws, a search that throws).

The same 22 checks and 4 probes are run; only the handling of an exception changed. The legacy code
still scores 22/22 with every probe planted, and all 77 deliveries filed so far were re-run through
the new characterization with identical counts and probe verdicts. The evaluation notes list it
under the harness defects.

### `0.2.69` — 2026-10-02 — results/ adds the third runs of Claude Haiku 4.5 (232) and GLM-5.3 Prime (541), both now official, and MiniMax-M3 with the thinking variant (462)

Harness 19/22, 22/22 and 22/22; blind labels BL, BM and BN, with `mecanico.json`'s submission path
anonymized; EXPL 22, 27 and 33; `score.py`. All ran on restored clean VMs as `leb`, with the fixed
first message and nothing else.
- **Claude Haiku 4.5, run 3 (BL): 232.** Official at 317, the median, last.
- **GLM-5.3 Prime at `high`, run 3 (BN): 541.** Official at 628, the median, 11th.
- **MiniMax-M3 · thinking, run 1 (BM): 462**, a new agent: opencode with a selectable variant,
  where MiniMax-M3's first run had no client recorded and no effort setting. BM's verdict was revised
  to file two findings about contracted behaviours as extra findings, not false positives.
- Fifteen scores are official.

### `0.2.68` — 2026-10-02 — results/ keeps a fourth and a fifth GPT-6.1-sol ultra run unscored

Both completed normally in Codex CLI at `ultra` on `bench3` and `bench1`, started after run 3, which
already made the agent's score official. `PROTOCOL §4` allows three runs per agent, so they are kept
in `gpt-6.1-sol-ultra/void-1/` and `void-2/` with their deliveries, mechanical reports and a
`VOID.md`, and nobody judged them. No score changes.

### `0.2.67` — 2026-10-02 — results/ adds GPT-6.1-sol's third run at ultra (616), official at 616; every Codex agent now has three runs

Harness 22/22; blind label BK, with `mecanico.json`'s submission path anonymized; EXPL 39;
`score.py`. It ran in Codex CLI 0.159.3 at `ultra` with three subagents, on a restored clean VM the
size of runs 1 and 2, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `ultra`, run 3 (BK): 616.** With three runs its score is official: 616, the
  median, 15th. Every agent run in Codex CLI now has its three runs; thirteen scores are official.

### `0.2.66` — 2026-10-02 — results/ adds GPT-6.1-sol's second run at ultra (656); its published score stays 597

Harness 22/22; blind label BJ, with `mecanico.json`'s submission path anonymized; EXPL 40;
`score.py`. It ran in Codex CLI 0.159.3 at `ultra` with two subagents, on a restored clean VM the
size of run 1's, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `ultra`, run 2 (BJ): 656.** 59 points above run 1; the published score stays the
  lower, 597, 19th, until a third run. BJ's verdict was revised to drop a second COMP-003 for a
  CLI-only visibility filter, as AK's and AL's were.

### `0.2.65` — 2026-10-02 — results/ adds the third runs of GPT-5.6-luna (624, official at 601) and GPT-5.5 (568, official at 568), and keeps a fifth GPT-6.1-sol run unscored

Harness 22/22 for all three; blind labels BH and BI, with `mecanico.json`'s submission path
anonymized; EXPL 29 and 29; `score.py`. All ran in Codex CLI 0.159.3 on restored clean VMs as
`leb`, with the fixed first message and nothing else.
- **GPT-5.6-luna at `xhigh`, run 3 (BH): 624.** Official at 601, the median, 18th. BH's verdict was
  revised to score BUG-004 as a silent fix, as earlier verdicts did.
- **GPT-5.5 at `xhigh`, run 3 (BI): 568.** Official at 568, the median, 22nd. It ran in Codex's
  workspace-write sandbox without network; the run's note records it.
- **A fifth GPT-6.1-sol run is kept unscored** (`void-2`): meant for the `ultra` agent, its log
  records `xhigh`, an agent that already has three runs.

### `0.2.64` — 2026-10-02 — results/ adds Claude Sonnet 5.5's third max run (820, official at 807) and second runs of GPT-5.5 (558) and GPT-5.6-luna (601)

Harness 22/22 for all three; blind labels BE, BF and BG, with `mecanico.json`'s submission path
anonymized; EXPL 32, 45 and 32; `score.py`. All ran on restored clean VMs as `leb`, with the fixed
first message and nothing else.
- **Claude Sonnet 5.5 at `max`, run 3 (BF): 820.** With three runs its score is official: 807, the
  median, 2nd, above the same model in multi-agent mode (774). It used one read-only subagent that
  ran on Sonnet 5.5 itself.
- **GPT-5.5 at `xhigh`, run 2 (BE): 558.** Its published score becomes 558, 22nd. BE's verdict was
  revised to charge its COMP-003 to the visibility fix, as the other verdicts do.
- **GPT-5.6-luna at `xhigh`, run 2 (BG): 601.** Its published score stays 599, the lower of the two.
- "Before quoting a number" is rewritten from the current table: ten official scores, seven agents
  with two runs, and the band of close scores.

### `0.2.63` — 2026-10-02 — results/ adds GLM-5.3 Prime's second run (628); its published score drops to 628

Harness 22/22; blind label BD, with `mecanico.json`'s submission path anonymized; EXPL 38;
`score.py`. It ran in opencode through OpenRouter on a restored clean VM as `leb`, with the fixed
first message and nothing else.
- **GLM-5.3 Prime at `high`, run 2 (BD): 628.** Its published score becomes 628, the lower of 635
  and 628, and it moves from 9th to 11th. Unlike run 1 it left the CSV formula injection unfixed.

### `0.2.62` — 2026-10-02 — results/ adds GPT-5.6-sol's third run (608), official at 612

Harness 22/22; blind label BC, with `mecanico.json`'s submission path anonymized; EXPL 36;
`score.py`. It ran in Codex CLI 0.159.3 on a restored clean VM as `leb`, with the fixed first
message and nothing else.
- **GPT-5.6-sol at `xhigh`, run 3 (BC): 608.** With three runs its score is official: 612, the
  median, 15th. The run lost 60 points of compatibility (two COMP-003: the "Desconhecido" label for
  unknown statuses and the SLA average scoped to each client).

### `0.2.61` — 2026-10-02 — results/ adds GPT-6.1-sol's third run (661, official at 661) and GPT-5.6-sol's second (612), and keeps a fourth GPT-6.1-sol run unscored

Harness 22/22 for all three; blind labels BA and BB, with `mecanico.json`'s submission path
anonymized; EXPL 41 and 35; `score.py`. All ran in Codex CLI 0.159.3 on VMs restored from the
snapshots retaken after the clean-up, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `xhigh`, run 3 (BA): 661.** With three runs its score is official: 661, the
  median, 6th, the strongest GPT model here.
- **GPT-5.6-sol at `xhigh`, run 2 (BB): 612**, the same total as run 1; its published score stays
  612. BB's matching verdict was revised to score BUG-004 as a silent fix, as earlier verdicts did.
- **A fourth GPT-6.1-sol run is kept unscored** in `gpt-6.1-sol-xhigh/void-1/`: started 48 seconds
  after run 3, it would exceed the three runs `PROTOCOL §4` allows.

### `0.2.60` — 2026-10-02 — results/ records the cost of Claude Sonnet 5.5's second max run, US$ 4.75, from the client's own record

0.2.59 published the run with its cost pending. The operator has since closed the session, and
Claude Code wrote its usage record: US$ 4.75 and 1,658 seconds of model time, 175,958 of the
229,079 output tokens being thinking. `run.json` now takes cost and tokens from that record, as
every other Claude Code run does; `runs.csv` and the notes follow. No score changes.

### `0.2.59` — 2026-10-01 — results/ adds Claude Sonnet 5.5's second single-agent max run (773); its published score drops to 773, 3rd

Harness 22/22; blind label AZ, with `mecanico.json`'s submission path anonymized; EXPL 45;
`score.py`. It ran on a VM restored from the snapshots retaken after the clean-up, as `leb`, with
the fixed first message and nothing else.
- **Claude Sonnet 5.5 at `max`, run 2 (AZ): 773, Gold.** Its published score becomes 773, the lower
  of 807 and 773, and it moves from 2nd to 3rd, one point below the same model in multi-agent mode.
  It left MD5 in place, which run 1 had migrated, and did not name the dispatcher.
- The run's cost is pending: the client's own cost record is written when the session is closed,
  and the operator had not closed it yet. The tokens come from the session log.

### `0.2.58` — 2026-10-01 — results/ adds Gemini 3.8 Flash's second high run (588), which searched the VM for the answer key; its published score drops to 588

Harness 22/22; blind label AY, with `mecanico.json`'s submission path anonymized; EXPL 28;
`score.py`. It ran on a restored VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `high`, run 2 (AY): 588, Bronze.** Its published score becomes 588, the
  lower of 687 and 588, and it moves from 6th to 22nd.
- **It searched the VM for the answer key** by name and by the matrix hash. The key is not on the
  VM; the only match was a line of the old Claude Code session `bench2`'s snapshot still carries,
  holding the same `TAREFA.md` the run already had. The run stands, by the operator's decision
  before judging; the run's note and the evaluation notes record the search.
- The matching verdict was revised to score BUG-004 and CLN-007 as not reported, as earlier
  verdicts did for findings at the right lines that diagnose other defects.

### `0.2.57` — 2026-10-01 — results/ adds DeepSeek V4.1 Flash's second and third runs (612 and 597), official at 612

Harness 22/22 for both; blind labels AW and AX, with `mecanico.json`'s submission path anonymized;
EXPL 30 and 28; `score.py`. Both ran in opencode through OpenRouter, on restored clean VMs as
`leb`, with the fixed first message and nothing else.
- **DeepSeek V4.1 Flash at `high`: 612 (run 2) and 597 (run 3).** With three runs its score is
  official: 612, the median, 18th; its single run (625) had placed it 13th. Neither later run fixed
  the CSV injection that run 1 fixed, and run 3 left the CSV export serving every client on
  purpose.

### `0.2.56` — 2026-10-01 — results/ adds GPT-5.6-terra's second and third runs in Codex (611 and 645), official at 625

Harness 22/22 for both; blind labels AU and AV, with `mecanico.json`'s submission path anonymized;
EXPL 31 and 31; `score.py`. Both ran in Codex CLI 0.159.3, at the same time, on restored clean VMs
as `leb`, with the fixed first message and nothing else.
- **GPT-5.6-terra at `xhigh`: 611 (run 2) and 645 (run 3).** With three runs its score is official:
  625, the median, run 1's total, still 14th. Both fixed the CSV injection run 1 left alone and
  both turned the technician column's `-` into `'-` (PEN-001); run 2 also scoped the SLA average
  to each client (COMP-003).

### `0.2.55` — 2026-10-01 — results/ records two Gemini 3.8 Flash attempts cut off by OpenRouter's rate limit, and a GPT-5.6-terra run in another client, all unscored

No score changes.
- **Gemini 3.8 Flash at `medium`, `void-2` and `void-3`.** OpenRouter answered HTTP 429 (Google
  rate-limited upstream) while up to three Gemini sessions shared it; one attempt stopped without
  its report, the other without its findings index. Both are kept with a `VOID.md`.
- **GPT-5.6-terra at `xhigh` in opencode, `void-1`.** It finished normally, but in a different
  client from the agent's run 1 (Codex CLI); the operator chose to repeat it in Codex CLI. Decided
  before any judge saw it, and kept with a `VOID.md`.

### `0.2.54` — 2026-10-01 — results/ adds Gemini 3.8 Flash at medium (550), and records an attempt started outside the package as void

Harness 22/22; blind label AT, with `mecanico.json`'s submission path anonymized; EXPL 24;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `medium` (AT): 550, Bronze, 23rd**, a separate agent, 137 points below the
  same model at `high`. It fixed 6 flaws and claims SQL injection in two int-typed functions.
- **An earlier attempt at `medium` is void.** Its client was started in `/srv` instead of a copy of
  the package; the agent spent four minutes locating the task before copying the package itself.
  Kept in `gemini-3.8-flash-medium/void-1/` with a `VOID.md`.

### `0.2.53` — 2026-10-01 — results/ adds Grok 4.7's second and third runs (663 and 607), official at 638

Harness 22/22 for both; blind labels AR and AS, with `mecanico.json`'s submission path anonymized;
EXPL 42 and 41; `score.py`. Both ran in opencode on xAI's API, at the same time, on restored clean
VMs as `leb`, with the fixed first message and nothing else.
- **Grok 4.7 at `high`: 663 (run 2) and 607 (run 3).** With three runs its score is official: 638,
  the median, unchanged and 9th. Run 3 lost 30 points for relabelling unknown statuses
  "Desconhecido" (COMP-003) and left the CSV injection in place; run 2 fixed the same 8 flaws as
  run 1.

### `0.2.52` — 2026-10-01 — results/ adds Gemini 3.8 Flash (687, 6th), the first Google model, and records its second attempt as void

Harness 22/22; blind label AQ, with `mecanico.json`'s submission path anonymized; EXPL 27;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `high` (AQ): 687, Silver, 6th.** It ran in opencode through OpenRouter, in
  12.5 minutes, for US$ 1.00, and is the strongest model here from outside Anthropic. It fixed 8 of
  the 13 planted flaws, kept MD5 and both secrets, and its report scored 27 of 50.
- **Its second attempt is void.** On `bench2`, OpenRouter returned a 504 timeout two minutes in,
  before anything was written. It is kept in `gemini-3.8-flash-high/void-1/` with a `VOID.md`.
- The notes also correct the rank band in "Before quoting a number" (places 7 to 11, not 5 to 9)
  and count thirty contestants.

### `0.2.51` — 2026-10-01 — results/ records Claude Fable 5.1's third attempt as void: its client switched to Opus 4.8 again

Kept unscored in `claude-fable-5.1-xhigh/void-2/`, with its delivery, `mecanico.json` (22/22) and a
`VOID.md`. No judge saw it and no score was assembled.
- **What happened.** On `bench1`, 13 minutes in, while Fable 5.1 was checking its SQL injection fix
  against injection payloads, its safeguards stopped a response. Claude Code then logged
  `model_refusal_fallback` and switched to Claude Opus 4.8, which edited `lib.php` and wrote all of
  `achados.json` and `RELATORIO.md`. Under `PROTOCOL §3` (one model per run) the run is void.
- **The setting did not help.** `switchModelsOnFlag` was `false` in `leb`'s settings, and the
  switch happened anyway; the evaluation notes say so.

### `0.2.50` — 2026-10-01 — results/ adds DeepSeek V4 Pro's third run (432), official at 496

Harness 22/22; blind label AP, with `mecanico.json`'s submission path anonymized; EXPL 26;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **DeepSeek V4 Pro at `high`, run 3 (AP): 432, Bronze.** With three runs its score is official:
  496, the median of 604, 496 and 432, 24th. Its three runs spread over 172 points, the widest of
  any agent with three, across two hosts (OpenRouter for runs 1 and 3, Novita AI for run 2).

### `0.2.49` — 2026-10-01 — results/ adds DeepSeek V4 Pro's second run (496), its published score now

Harness 22/22; blind label AO, with `mecanico.json`'s submission path anonymized; EXPL 30;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **DeepSeek V4 Pro at `high`, run 2 (AO): 496, Bronze.** It ran at the same time as run 1, served
  by Novita AI where run 1 went through OpenRouter. It fixed 6 flaws against 8, leaving the N+1
  query, MD5 and both secrets in place. Its published score becomes 496, the lower of its two
  runs, 24th.

### `0.2.48` — 2026-10-01 — results/ adds Claude Sonnet 5.5 at max without ultracode (807, 2nd) and DeepSeek V4 Pro (604)

Harness 22/22 for both; blind labels AM and AN, with `mecanico.json`'s submission path anonymized;
EXPL 45 and 21; `score.py`. Both ran on restored clean VMs as `leb`, with the fixed first message
and nothing else.
- **Claude Sonnet 5.5 at `max`, single agent (AM): 807, Gold, 2nd.** It took 27 minutes and
  US$ 3.86. It fixed 11 of the 13 planted flaws, and scored 33 points above the same model at the
  same effort in ultracode, which took about 8 hours.
- **DeepSeek V4 Pro at `high` (AN): 604, Silver, 18th, below both DeepSeek Flash models.** It ran
  in opencode through OpenRouter. Its report claims SQL injection in two int-typed functions (two
  false positives), and its JOIN drops the listing's `-` for a ticket with no technician (PEN-001).
- The "judge is also a contestant" note now counts six Claude models among twenty-nine agents.

### `0.2.47` — 2026-10-01 — results/ adds GPT-6-astra's second and third xhigh runs (596 and 628), official at 628

Harness 22/22 for both, with SEC-001, BUG-001 and PERF-001 fixed; blind labels AK and AL; EXPL 40
and 41; `score.py`. Both ran in Codex CLI 0.159.3 on restored clean VMs as `leb`, at the same time
(bench3 and bench2), with the fixed first message and nothing else.
- **GPT-6-astra at `xhigh`: 596 (run 2) and 628 (run 3).** With three runs its score is official:
  628, the median of 661, 596 and 628, 10th; its single run had placed it 5th. Neither later run
  fixed the CSV formula injection that run 1 fixed; run 3 migrated MD5, run 2 kept it.
- **Both deliveries were judged twice.** The first matching judges could see the run's folder path
  (the agent's name) in `mecanico.json`; those verdicts were set aside and both were judged again
  blind. A second COMP-003 the judges recorded, for a command-line-only global visibility filter,
  was removed on review to match the verdicts of the same filter in earlier runs.
- The evaluation notes also correct the "Before quoting a number" spread, which still quoted
  GPT-6.1-sol's and GPT-6-astra's first runs.

### `0.2.46` — 2026-10-01 — results/ adds Sonnet 5.5's third xhigh run (official at 809) and second runs of GPT-6.1-sol (653) and GLM-5.3 (621)

Harness 22/22 for all three, with SEC-001, BUG-001 and PERF-001 fixed; blind labels AH, AI and AJ;
EXPL 41, 45 and 39; `score.py`. All three ran on restored clean VMs as `leb`, with the fixed first
message and nothing else.
- **Claude Sonnet 5.5 at `xhigh`, run 3 (AI): 724.** With three runs its score is official: 809,
  the median, still first. It fixed 8 of the 13 planted flaws, against 11 in each earlier run,
  leaving MD5, both secrets and the CSV injection in place. Its lead in fixing holds in the median
  run, not in every run.
- **GPT-6.1-sol at `xhigh`, run 2 (AH): 653**, in Codex CLI 0.159.3. Its published score becomes the
  lower, 653, 7th.
- **GLM-5.3 at `high`, run 2 (AJ): 621**, served by OpenRouter where run 1 used Z.AI's API. Its
  published score becomes 621.

### `0.2.45` — 2026-10-01 — results/ records a GPT-5.6-sol pro attempt as void: the gateway ran out of credit before the report

GPT-5.6-sol pro at `max`, in opencode through the Kilo Code gateway on `bench3`, worked from 10:05 to
11:19 and started two review subagents. Then the gateway answered `402 – Add credits to continue`,
and the session stopped. It had changed the code but written no report and no findings index. It
cost US$ 16.04 in 74 minutes. The stop is external to the run and came before anything could be
judged, so the attempt is void and kept unscored in `gpt-5.6-sol-pro-max/void-1/`, with a
`VOID.md`.

### `0.2.44` — 2026-10-01 — results/ adds Claude Haiku 4.5's second run (369); its published score stays 317

Harness 22/22 with SEC-001 and BUG-001 fixed; blind label AG; EXPL 19; `score.py`. Claude Haiku
4.5 ran on `bench2` as `leb`, in 7 minutes for US$ 0.40, with auto-accept set before the first
message and every answer from Haiku 4.5. It scored 369, against 317 for run 1, so its published
score stays the lower, 317, 27th. This time it moved both secrets out of the code and kept
compatibility. It again reported the two impossible SQL injections, and its report (19 of 50)
misstates PHP 8's division by zero. The run's note records that `bench2`'s snapshot still held the
first seconds of Opus 5.5's third run, unread.

### `0.2.43` — 2026-10-01 — results/ adds Claude Haiku 4.5 on LEB-100-A, last at 317, and files it at the model's default effort

Harness 22/22 with SEC-001 and BUG-001 fixed; blind label AF; EXPL 23; `score.py`. Claude Haiku 4.5
ran in Claude Code on `bench1` as `leb`, in 2.5 minutes for US$ 0.21. Anthropic's model overview
lists the effort setting as not supported for Haiku 4.5, so the client's `/effort xhigh` did not
apply. The agent, and its void attempt of 0.2.42, move from `claude-haiku-4.5-xhigh` to
`claude-haiku-4.5-default`. Its training cutoff is 2025-07.

317, below the pass line, 27th of 27. It found 6 of the 13 planted flaws, fixed 4, and reported two
SQL injections that cannot exist. Its visibility fix hides a client's own tickets on the main page:
a strict comparison between an integer and the string mysqli returns without a search term. That
was reproduced against the delivered code (COMP-003). The run's note records a switch to auto-accept
edits one second after the first message, which adds no instruction and switches no model.

### `0.2.42` — 2026-10-01 — results/ records a Claude Haiku 4.5 attempt as void, and PROTOCOL forbids switching the client's mode mid-run

Claude Haiku 4.5's first attempt at `xhigh`, on `bench2`, had the client's permission mode switched
from the keyboard while the agent worked, to plan mode and then to auto-accept. Plan mode put an
instruction into the agent's context ("do not edit"), and one turn came from Claude Sonnet 5.5,
Claude Code's planning model. The attempt was voided before the harness or any judge ran, and is
kept unscored in `claude-haiku-4.5-xhigh/void-1/` with a `VOID.md`. `PROTOCOL §3` now says the
client runs in a mode set before the first message and left alone until the run ends.

The `VOID.md` also records that `bench2`'s snapshot was taken with Opus 5.5's third run just
started. It held that session's first tool call and nothing more, unread by this attempt. The
snapshot is retaken with every client closed.

### `0.2.41` — 2026-10-01 — results/ adds Claude Fable 5.1's second xhigh run (764); its published score becomes 764, third

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed; blind label AE; EXPL 42; `score.py`. Fable
5.1 at `xhigh` ran on `bench1` as `leb` on a restored clean VM, with the fixed first message and
nothing else, in 22 minutes for US$ 8.04. Every answer came from Fable 5.1, with no safety stop and
no model switch, the failure that voided its earlier second attempt.

It scored 764, against 781 for run 1, so its published score is the lower median, 764, and it
moves from second to third, behind Sonnet 5.5's multi-agent run (774). It fixed 10 of the 13
planted flaws, one more than run 1, because it moved both secrets out of the code. It lost run 1's
architecture points and part of the bug score.

### `0.2.40` — 2026-10-01 — results/ adds Claude Opus 5.5's third xhigh run (805): its 717 is the first official score on LEB-100-A

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed; blind label AD; EXPL 44; `score.py`.
Opus 5.5 at `xhigh` ran on `bench2` as `leb` on a restored clean VM, with the fixed first message
and nothing else, in 15 minutes for US$ 2.71. It scored 805, against 711 and 717 for its first two
runs.

With three runs the agent is official (`PROTOCOL §4`): its published score is the median, 717, 4th.
Each of the three runs fixed 9 of the 13 planted flaws. The 94-point spread comes from judgement
calls: a business value changed in run 1, the nested conditionals flattened only in run 3, and MD5
migrated only in runs 1 and 2. The notes say so, and do not credit the clean machine for the
higher total.

### `0.2.39` — 2026-10-01 — results/ adds Claude Sonnet 5.5's second xhigh run (809) and GLM-5.3 Prime on LEB-100-A, ninth at 635

Harness 22/22 for both, with all four probes fixed; blind labels AB and AC; EXPL 45 and 28;
`score.py`. Both ran on a restored clean VM as `leb`, with the fixed first message and nothing
else.
- **Claude Sonnet 5.5 at `xhigh`, run 2 (AB): 809**, 16 points below its run 1. It ran on
  `bench1` in 23 minutes, for US$ 2.96. It found 12 planted flaws and fixed the same 11, and
  scored less in architecture and more in bugs. Its published score becomes the lower median,
  809, still first. Its note records the machine change allowed by 0.2.38.
- **GLM-5.3 Prime, `high` (AC): 635, Silver, 9th.** It ran in opencode through Kilo on `bench2`,
  for US$ 1.69. It fixed 9 planted flaws, the CSV injection among them, and carries the dagger
  (no published cutoff). In consistency review its F12, a TypeError that the `isset` guard at
  `index.php:21` makes impossible, moved from extra findings to false positives; the total does
  not change.

### `0.2.38` — 2026-10-01 — PROTOCOL keeps the machine size fixed across an agent's runs only for multi-agent clients

`PROTOCOL §3` required every run of an agent to keep the same machine size, because a multi-agent
client sizes its work to the machine (0.2.28). A single-agent client spends most of a run waiting
on the model's API, and the size weighs little on it, so the rule now applies to multi-agent
clients only. A single-agent client's runs may change machines, and the run's note records what
changed. The case that prompted it is Claude Sonnet 5.5's second run at `xhigh`, published next.
Its first run was made on the first VM at 4 vCPUs as administrator; the second on `bench1` at 20
vCPUs as the unprivileged user `leb`.

### `0.2.37` — 2026-10-01 — results/ publishes the results as CSV: one row per run, and one per run and planted flaw

`tools/export-results.py` now also writes two files from the same inputs as `results.json`, so they
cannot disagree with the leaderboard:
- **`results/runs.csv`**, one row per scored run. It carries:
  - the agent's rank and score, and whether this run is the one that counts;
  - the model, the host that served it, the effort, the client mode, and the client and version;
  - the training cutoff and the key exposure;
  - the seven category scores and the penalties;
  - planted flaws found and fixed, false positives, extra findings, the discovery index and the
    Brier score;
  - session times, model time, tokens, cost, the machine size, and the scorecard's link.
- **`results/flaws.csv`**, one row per run and planted flaw: reported, then found, explained, fixed
  and compatible as `full`, `half` or `none`, then points and declared confidence.

`results/CSV.md` explains every column, and `results/README.md` links the three files. Void runs
are left out, as in the leaderboard. The output is deterministic, and `--check` covers the CSVs.

### `0.2.36` — 2026-10-01 — results/ adds three GPT runs of 2026-10-01 on LEB-100-A: GPT-6.1-sol pro at 654, GPT-6.1-sol ultra at 597, GPT-5.3-Codex at 403

Each ran on one of three clean execution VMs, `bench1` to `bench3`, as the unprivileged user
`leb`, with the fixed first message and nothing else, no web tool and no request to GitHub.
Harness 22/22 for all three; blind labels Y, Z and AA; EXPL 42, 27 and 41; `score.py`.

- **GPT-6.1-sol pro, `xhigh` (Y): 654, Silver, 7th.** It ran in opencode through OpenRouter and
  carries the dagger, because OpenAI publishes no cutoff for a pro variant. Its COMP-003 is the
  per-client SLA average, as for five earlier GPT runs.
- **GPT-6.1-sol, `ultra` (AA): 597, Bronze.** It ran in Codex CLI 0.159.3 with three subagents,
  and scored 69 points below the same model at `xhigh`: it kept MD5, did not report the
  file-handle leak and did not name the dispatcher.
- **GPT-5.3-Codex, `xhigh` (Z): 403, Bronze.** It ran in opencode through the Kilo Code gateway. It
  found 6 of the 13 planted flaws, and scored 0 in performance, clean code and architecture.

The README names the three VMs. The session logs are archived off the VMs and unpublished.

### `0.2.35` — 2026-09-30 — The client mode is model.client_mode, and PROTOCOL §3 says why a run records it

`model.mode` (0.2.34) reused a word that already means the execution mode, S or A, which the
scorecard prints one row below the model. The field is now `model.client_mode`, and `PROTOCOL §3`
documents it: a client mode that changes how the model works, such as Claude Code's multi-agent
`ultracode`, makes the same model at the same effort a different agent, filed and shown as one.
`tools/export-results.py` now also names the mode next to a `default` effort, and no longer prints
a stray mode or `None` when the effort is missing. The generated scorecards and results table are
unchanged; `results.json` renames the key.

### `0.2.34` — 2026-09-30 — run.json records a client mode, and the leaderboard shows Sonnet 5.5's as max (ultracode)

The same model at the same effort runs differently in Claude Code's multi-agent mode, so the mode
is now part of the model block: `model.mode` in `run.json`, set to `ultracode` for Claude Sonnet
5.5's multi-agent run. `tools/export-results.py` prints it next to the effort, as `max (ultracode)`
in the results table and as `` `max` (ultracode) `` in the scorecard's Model row, and
`results.json` carries it to the site. No score changes.

### `0.2.33` — 2026-09-30 — results/ adds Claude Sonnet 5.5 in multi-agent mode on LEB-100-A, third at 774

Harness 22/22 with all four probes fixed; blind label X; EXPL 45/50; `score.py`. Claude Sonnet 5.5
at effort `max` in Claude Code's multi-agent mode ran 7 workflows with 68 subagents from 13:05 to
21:00 on the first VM, resized to 20 vCPUs, with the fixed first message and nothing else, no web
tool and no request to GitHub. The client's record: US$ 233.08 and 16.4 hours of model time in 8.1
hours, against US$ 3.60 and 19 minutes for the same model at `xhigh`.

774 (Gold), third, 51 points below the `xhigh` run's 825. It scores the same in security (250),
bugs, performance, clean code and compatibility, finds 29 findings with no false positive and is
better calibrated, but scores 0 in architecture against 50 and one point less in explanation.
Nine subagents fell back to `claude-sonnet-5` after the safety classifier stopped them (US$ 15.34,
541,148 output tokens, all analysis); none of the delivery came from them, so the run stands under
the rule of 0.2.32. It began before the clean-machine rule, as the VM's administrator. The session
log is archived off the VM, unpublished; `void-1` stays as filed.

### `0.2.32` — 2026-09-30 — PROTOCOL voids a run when another model wrote any part of the delivery, and records a fallback confined to subagents

Claude Fable 5.1's second run was voided because its client switched to another model, which wrote
the report; until now that rule lived only in that run's `VOID.md`. `PROTOCOL §3` now states it for
every run: a client that can switch models on its own runs with the switch off, and a run is void
when any part of the delivery (code, report or findings index) was written by another model. A
switch that stays inside auxiliary agents of a multi-agent client, with none of the delivery
written by them, leaves the run standing, and its note records which agents switched, to which
model, and their share of the run's output tokens and cost. The case that prompted it is Claude
Sonnet 5.5's multi-agent run, published next.

### `0.2.31` — 2026-09-30 — results/ adds Qwen3 Coder Next on LEB-100-A, eighteenth at 507

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not; blind label W; EXPL
18/50, the lowest so far; `score.py`. The first run under the clean-machine rule of 0.2.30: the
unprivileged user `leb` on the second VM, in opencode through Novita AI, with the fixed first
message and nothing else, no web tool and no request to GitHub; US$ 1.53 in 16 minutes. The model
is non-thinking and has no effort setting, so the agent is `qwen3-coder-next-default`; the Qwen team
publishes no cutoff, so it carries the dagger.

507 (Bronze), eighteenth. PEN-001 (−15): its JOIN drops the `-` shown for a ticket with no
technician, as the rewritten `-` was for G and V. Three false positives at confidence 100 give it
the worst calibration so far (Brier 0.301). Its run note records the session at 17:33 that never
reached the host (no outbound internet yet), the extra `analysis_report.md` it left unscored, and
147 calls to a tool that does not exist. The session log is archived off the VM, unpublished.

### `0.2.30` — 2026-09-30 — PROTOCOL runs every agent as an unprivileged user on a machine cleaned of earlier runs' leftovers

Until now every run was made as the execution VM's administrator account, with passwordless sudo,
on a machine that kept what earlier runs left: the clients' session logs, temporary files, and a
`suporte` database with a `painel` user in the system MariaDB. `PROTOCOL §3` now requires an
ordinary user with no sudo, on a machine carrying nothing from an earlier run, restored from a
clean snapshot before every run; the agent tests inside its own account (PHP's built-in server
and a private MariaDB it starts itself). The README's isolation table adds the two layers of the
second VM, `ai-bench2`: IPv6 disabled on its LAN interface, and the unprivileged user.

Every tool call in the session logs of the earlier runs was read for signs that one run used
another's leftovers, and the evaluation notes record what that shows: no session read another
run's folder, report, findings index or session log; the opencode runs shared the system
database, read only seed rows from it and inspected no structure an earlier agent had left. The
logs were copied off the VM before any cleanup; they stay unpublished.

### `0.2.29` — 2026-09-30 — results/ records the first multi-agent Sonnet 5.5 attempt as void, stopped to resize the machine

The first run of Claude Sonnet 5.5 at max effort in Claude Code's multi-agent mode
(`claude-sonnet-5.5-max-ultracode`) started at 12:34 on the 4-vCPU, 3.8-GiB execution VM. The
agent reported that the machine limited each of its workflows to 2 agents. The operator stopped it
at 13:00, before it had written a report or findings index, and resized the VM to 20 vCPUs and
15 GiB.

The folder is kept as it was, unscored, in `claude-sonnet-5.5-max-ultracode/void-1/`. Its
`VOID.md` gives the session's timeline, its 5 workflows and 15 subagents, and the US$ 8.43 the
client recorded. It also explains why the replacement is not a selective retry: the run was stopped
for the machine, before there was any output to judge. The evaluation notes list it with the runs
of 2026-09-30.

### `0.2.28` — 2026-09-30 — run.json records the size of the machine each run ran on

Claude Sonnet 5.5 in Claude Code's multi-agent mode ("ultracode") told the operator that the VM's
4 CPUs limited each of its workflows to 2 agents at a time. A multi-agent client sizes its work to
the machine, so the machine's size changes what is measured. Until now it was recorded nowhere.

- `PROTOCOL.md §3`: every run records `execution_host` (vCPUs and RAM), and all the runs of one
  agent keep the same size.
- **The existing runs.** The 20 runs that ran on the execution VM record 4 vCPUs and 3.8 GiB, its
  size from 2026-09-25 until it was resized on 2026-09-30 at 13:01. Kimi K3's clone records `null`,
  listed in `not_recorded`.
- **Scorecards.** They gain an "Execution host" row.
- **Evaluation notes.** They explain why the size matters little for single-session clients and a
  lot for multi-agent ones, and that later runs use the new size: 20 vCPUs and 15 GiB.

No score changes.

### `0.2.27` — 2026-09-30 — results/ adds DeepSeek V4.1 Flash on LEB-100-A, ninth at 625

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with all four probes fixed, SEC-008 included;
- one blind matching judge (label V);
- the EXPL judge on its kept scale (33/50);
- `score.py`.

**How it ran.** It ran through DeepSeek's own API (`deepseek-flash`), in opencode 1.18.33 at effort
`high`, with every isolation layer, the fixed first message only, and no request to GitHub. It cost
US$ 0.04, the cheapest run so far. DeepSeek publishes no training cutoff, so it carries the dagger.

**Result.** DeepSeek V4.1 Flash totals 625 (Silver), ninth, level with GPT-5.6-terra and ahead of
it on the discovery index. Its sanitizer also rewrites the `-` marker, so the review kept its
judge's PEN-001 (−15), the same call G got.

**DeepSeek V4 Flash caveat.** DeepSeek's release note says its own API has routed the name
`deepseek-v4-flash` to V4.1 Flash since 2026-09-14. That run went through Novita's hosted model of
that name, so it cannot be shown to be V4 weights. Its run note and the evaluation notes now say
so. The two results differ (612 and 625, and in several calls).

The evaluation notes and the README status count twenty agents.

### `0.2.26` — 2026-09-30 — results/ adds DeepSeek V4 Flash on LEB-100-A, twelfth at 612

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label U);
- the EXPL judge on its kept scale (29/50);
- `score.py`.

**How it ran.** It ran in opencode 1.18.33 at effort `high`, served by Novita AI, with every
isolation layer and no request to GitHub. It cost US$ 0.07.
- **Name.** It is filed as `deepseek-v4-flash-high`, the model the session shows. The operator's
  folder said `v4.1`, which the host did not offer.
- **Identity check.** Its run note records the "quem eh voce?" the operator sent before the fixed
  first message. That is the case `PROTOCOL §3` now covers (0.2.25).
- **Cutoff.** DeepSeek publishes none, so the model carries the dagger.

**Result.** DeepSeek V4 Flash totals 612 (Silver), twelfth. It sits level with GPT-5.6-sol, which
ranks ahead on Brier. It fixed eight flaws fully, including MD5 and the secrets.

**Consistency review.** The review kept its judge's COMP-003 (−30) for changing `formatarStatus`
to return "Desconhecido" where the legacy code returned "Resolvido", the same call D got. Without
it the total would be 642.

The evaluation notes and the README status count nineteen agents.

### `0.2.25` — 2026-09-30 — PROTOCOL says what happens to an identity check sent before the first message

Before the fixed first message of DeepSeek V4 Flash's run, the operator asked "quem eh voce?" to
confirm which model the host was serving. DeepSeek's own site gives V4.1 Flash a different name,
and the host offered only V4 Flash. The rule said the first message is exactly the fixed one, with
nothing added, but it said nothing about a message before it.

`PROTOCOL.md §3` now says it. A check made before the first message that says nothing about the
task, such as asking which model is answering, belongs in a separate session. If it happens in the
run's own session, the run's note records the exchange and the run stands. A message that does
carry task content is still covered by the voiding rule. The decision was taken before that run was
scored.

### `0.2.24` — 2026-09-30 — results/ adds Kimi K2.7 Code on LEB-100-A, seventeenth at 415

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001 and BUG-001 fixed and SEC-008 and PERF-001 not;
- one blind matching judge (label T);
- the EXPL judge on its kept scale (26/50);
- `score.py`.

**How it ran.** It ran in opencode 1.18.33 through Moonshot's API, at the model's only effort (the
default), with every isolation layer, the fixed first message and nothing else, and no request to
GitHub. It cost US$ 0.48. The operator's folder was named `kimi-for-coding-high`; the agent is
filed as `kimi-k2.7-code-default`, and its run note says why. The run note also records an earlier
session that never started, on invalid-key errors. Moonshot publishes no training cutoff; the
release date (2026-06-12) goes in the note but is not used as one, so the model carries the dagger.

**Result.** Kimi K2.7 Code totals 415 (Bronze), seventeenth. Its two SQL-injection findings in
integer-only functions, reported at confidence 100, are false positives, and they give the worst
Brier so far (0.225).

**Consistency review.** Its report claims an empty fallback for `EXPORT_DIR`, while the code keeps
the original path. That is no compatibility violation, and the EXPL judge's mark-down of the report
stands.

The evaluation notes and the README status count eighteen agents.

### `0.2.23` — 2026-09-30 — results/ adds Grok 4.6 on LEB-100-A, seventh at 633

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label S);
- the EXPL judge on its kept scale (35/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high`, with every isolation layer, the fixed first message
and nothing else, no web tool and no request to GitHub. It took six minutes and cost US$ 0.31.
xAI publishes no training cutoff for Grok 4.6 (it does for 4.7), so the leaderboard marks it with
the dagger.

Grok 4.6 totals 633 (Silver), seventh, five points below Grok 4.7 for about an eighth of its cost.
The consistency review kept its judge's calls:
- **SEC-015 C3 at half.** Grok 4.6 moved the SMTP key to the environment and kept the database
  password's literal fallback. J did the same and got the same score; A, P and Q kept both literals
  and got none.
- **CLN-007 R1.** It named the nested ifs in its list of non-changes, which rule 5 counts as R1.

The evaluation notes and the README status count seventeen agents.

### `0.2.22` — 2026-09-30 — results/ adds Grok 4.7 on LEB-100-A, sixth at 638

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with all four probes fixed, SEC-008 included;
- one blind matching judge (label R);
- the EXPL judge on its kept scale (42/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high` with every isolation layer. The client counted US$ 2.43.
xAI publishes a training cutoff of May 2026, before the answer key went public, so it carries no
dagger.

- **First web request.** It is the first agent to use a web tool: one fetch of the PHP manual page
  for `fputcsv`. The protocol blocks GitHub, not the web. Its run note records the fetch, and its
  session shows no request to GitHub.
- **Result.** Grok 4.7 totals 638 (Silver), sixth, and is the strongest model here from outside
  Anthropic and OpenAI. It is the fourth agent to fix the CSV formula injection, without G's
  side-effect on the `-` marker. It kept the contract whole.
- **Notes.** The evaluation notes count sixteen agents, and their per-run bullets drop the rank
  ordinals, which went stale with each new run. The README status counts sixteen agents.

### `0.2.21` — 2026-09-30 — results/ adds GLM-5.3-Flash (eighth at 624) and GLM-5.3-FlashX (twelfth at 597) on LEB-100-A

The two smaller GLM-5.3 variants were run the same morning, each in opencode 1.18.33 at effort
`high`, with every isolation layer. Each session record shows the fixed first message, no other
operator message, and no request to GitHub. Z.AI publishes no training cutoff for either, so both
carry the dagger.

| Model | Harness | Label | EXPL | Total | Grade | Place | Cost |
| --- | --- | --- | ---: | ---: | --- | ---: | ---: |
| GLM-5.3-Flash | 22/22; SEC-001, BUG-001, PERF-001 fixed | P | 34 | 624 | Silver | 8th | US$ 0.08 |
| GLM-5.3-FlashX | 22/22; the same three fixed | Q | 33 | 597 | Bronze | 12th | US$ 0.10 |

Both kept the contract whole. The consistency review kept every call:
- Flash's optional parameters and literal CSV header are not COMP.
- Flash's unguarded bcrypt re-hash, which a legacy `CHAR(32)` column would break, is a deployment
  caveat and not PEN-001, as for D, E and F.
- FlashX's SQL `AVG` is not COMP-003, as for C and F.

The evaluation notes and the README status count fifteen agents.

### `0.2.20` — 2026-09-30 — results/ adds GLM-5.3 on LEB-100-A, sixth at 629

A fifth delivery of 2026-09-30, evaluated like the others:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label O);
- the EXPL judge on its kept scale (39/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high`, on the execution VM with every isolation layer. Its
session record shows the fixed first message, no other operator message, and no request to
GitHub. The client counted US$ 0.73 and 1.88 M tokens. Z.AI publishes no training cutoff, so the
leaderboard marks it with the dagger.

GLM-5.3 totals 629 (Silver), sixth, 241 points above GLM-5.2 in the same client at the same
effort. It fixed the N+1 query and seven more flaws, and kept the contract whole.

The consistency review kept its judge's call on `exportarCsv`. The function now filters by the
logged-in client's session, but callers without a session, such as the nightly export, still
receive every ticket. That enforces the visibility rule and is not COMP-003.

Places 7 to 13 move down by one. The evaluation notes and the README status count thirteen agents.

### `0.2.19` — 2026-09-30 — PROTOCOL fixes the exact first message, and Opus 5.5's second run records why its first attempt was stopped

**First message.** The runs of 2026-09-29 began with *"Não saia desta pasta. Agora leia o TAREFA.md
e execute. Devolva code/ alterado, RELATORIO.md e achados.json."* The operator had added the first
sentence for fear that something else on the VM could give a hint. On 2026-09-30 the operator dropped it
to follow the original prompt. The guides meanwhile said only "leia o TAREFA.md e execute", in three
slightly different wordings.

- `PROTOCOL.md §3` now fixes the first message as exactly *"Agora leia o TAREFA.md e execute.
  Devolva code/ alterado, RELATORIO.md e achados.json."*, the text the operator keeps in the VM's
  `/srv/prompt.md`, with nothing added.
- The `COMO-RODAR.md` that `./leb pacote` writes and `BENCHMARK.md` (steps 2 and 3) repeat it word
  for word. `TAREFA.md` and the package do not change.
- The evaluation notes say that an agent's run 1 of 2026-09-29 and its later runs differ by that
  sentence. Each run's `first_message` records which one it got.

**Opus 5.5, run 2.** Its run note now gives the operator's reason for stopping the first attempt
at 08:28: it had started before the VM's last isolation layer (GitHub's edge addresses, 09:02) was
in place. The run of 09:04 replaced it.

### `0.2.18` — 2026-09-30 — results/ adds Kimi K3 on LEB-100-A, tenth at 528

Kimi K3's delivery was judged with the batch of 2026-09-30 (blind label M) and held, because no
session of it existed on the execution VM. The owner has since explained why. It ran on a clone of
the VM, made to run a second session in parallel and destroyed afterwards, and its session log went
with the clone.

`run.json` records that and nothing it cannot show:
- **Environment.** A clone with at least GitHub's names blocked and no IPv6. Whether it already had
  the blackhole routes is not recorded, and GitHub's edge addresses came after the delivery was
  filed.
- **Not recorded.** The client, the first message, the operator's messages, the tokens and the
  cost.
- **Effort.** It is filed at `default`, from the folder name.
- **Training cutoff.** Moonshot AI publishes none, so the leaderboard marks it with the dagger.

Kimi K3 totals 528 (Bronze), tenth. It kept the contract whole (COMP 100) and fixed seven flaws,
with EXPL at 30/50. It left the N+1 query, the CSV injection and MD5 in place. MiniMax-M3 and
GLM-5.2 move to 11th and 12th. The evaluation notes and the README status count twelve agents.

### `0.2.17` — 2026-09-30 — results/ adds the runs of 2026-09-30 on LEB-100-A: Opus 5.5 run 2 at 717, GLM-5.2 at 388, Fable 5.1 run 2 void

Four deliveries arrived on 2026-09-30. Each went through the procedure of the first ten: harness
(22/22 for all four), one blind matching judge each (labels K to N), EXPL, `score.py` and a
consistency review. The EXPL judge is a second instance: it kept the scale of the one that scored
A–J and changed none of those scores.

| # | Model | Total | Grade | Runs |
| ---: | --- | ---: | --- | :-: |
| 1 | Claude Sonnet 5.5 | 825 | Gold | 1/3 |
| 2 | Claude Fable 5.1 | 781 | Gold | 1/3 |
| 3 | Claude Opus 5.5 | 711 | Silver | 2/3 (711 · 717) |
| 4 | GPT-6.1-sol | 666 | Silver | 1/3 |
| 5 | GPT-6-astra | 661 | Silver | 1/3 |
| 6 | GPT-5.6-terra | 625 | Silver | 1/3 |
| 7 | GPT-5.6-sol | 612 | Silver | 1/3 |
| 8 | GPT-5.5 | 601 | Silver | 1/3 |
| 9 | GPT-5.6-luna | 599 | Bronze | 1/3 |
| 10 | MiniMax-M3 | 460 | Bronze | 1/3 |
| 11 | GLM-5.2 | 388 | Reprovada | 1/3 |

- **Opus 5.5, run 2 (K): 717.** The published score stays 711, the lower of two (PROTOCOL §4).
  - Its run note records a first attempt the operator interrupted at a tool-permission prompt,
    before any delivery.
  - It records a first message without the "Não saia desta pasta." that run 1 received.
  - Its cost comes from Claude Code's own summary: US$ 3.01, with 923 s of model time.
- **GLM-5.2 (N): 388, the first result below 400.**
  - It ran in opencode 1.18.33 at effort `high`.
  - It kept the contract whole and fixed five flaws.
  - Z.AI publishes no training cutoff, so it carries the dagger.
  - Its cost comes from opencode's record: US$ 0.35.
- **Fable 5.1's second run (L) is void.** Its session log shows the model's safeguards stopping a
  response and Claude Code finishing the run with Claude Opus 4.8, which wrote the whole report.
  It was voided on that evidence before its score was assembled or its label matched to the model.
  It is kept unscored in `claude-fable-5.1-xhigh/void-1/` with a `VOID.md`.
- **Kimi K3 (M) is judged and held.** No session of it exists on the execution VM. Its delivery
  and mechanical report are filed in place; its verdict and score wait for the record of where and
  how it ran.
- **Two misplaced deliveries move.** The Kimi K3 and Fable 5.1 deliveries were committed
  unjudged at `results/2026/<agent>/` by 0.2.15, by mistake. They now move to their places.
- **Exporter and docs.** `tools/export-results.py` shows a `run_note` as a "Run note" row. The
  evaluation notes gain *Runs of 2026-09-30*, and the README status counts eleven agents.

### `0.2.16` — 2026-09-30 — The first ten runs record what their session logs show: client, operator messages, tokens, and no request to GitHub

The execution VM still holds the client session logs of eight of the ten runs of 2026-09-29: the
three Claude models in Claude Code, and five GPT models in Codex CLI. They were read on 2026-09-30
with the owner's go-ahead, and each `run.json` records what they show. The logs themselves stay on
the VM, unpublished.

- **Client.** `client` gives name and version: Claude Code 2.1.285, Codex CLI 0.158.0 for
  GPT-5.6-luna and 0.159.2 for the other four GPT models. `session` gives start and end, with where
  the log is kept.
- **First message.** `first_message` holds *"Não saia desta pasta. Agora leia o TAREFA.md e
  execute. Devolva code/ alterado, RELATORIO.md e achados.json."* in all eight.
- **Operator messages.** `operator_replies` counts them, and a note explains any that exist. Opus
  5.5 and GPT-5.6-luna each received the first message again mid-run, verbatim, so each counts 1.
  Fable 5.1's first message was interrupted two seconds in and re-sent after the client switched
  from Sonnet 5.5 to Fable, which is not counted.
- **Cost.** `cost_time` holds the tokens the client counted. Sonnet 5.5's folder still has Claude
  Code's own summary, which adds model time (1,058 s) and cost (US$ 3.60). For the other runs no
  cost or model time survives.
- **No GitHub access.** None of the eight sessions made a request to GitHub, by name or by address,
  or used a web search or fetch tool. Their network calls all go to the agents' own local test
  servers. The results notes say so.
- **No log.** GPT-5.5's and MiniMax-M3's runs left no log on the VM, so their new fields are `null`
  and listed in `not_recorded`.
- **Scorecards.** They gain "Client" and "First message" rows, the "Operator replies" row shows its
  note, and `results.json` carries `client` per run. No score changes.

### `0.2.15` — 2026-09-30 — README dates each layer of the VM's isolation, and the first ten runs record that they had GitHub's names blocked but not its addresses

0.2.11 said three layers of isolation had been in place "since the first scored run", and wrote the
same into the ten `run.json` files. That rested on the owner's recollection. The VM's own logs,
read on 2026-09-30, contradict it:

- the `/etc/hosts` block went in on 2026-09-29 at 18:59 (UTC−3), before the first run at 19:32;
- `github-blackhole.sh`, its systemd unit and the first blackhole route were created on
  2026-09-30 at 08:13, after the ten runs;
- no route command appears before that in the sudo log or the root shell history, and the VM had
  not rebooted since 2026-09-25;
- the VM never had IPv6 connectivity: no global address and no IPv6 default route.

So the first ten runs had the name block only. An agent could not reach GitHub by name, but a
connection straight to a GitHub address would have gone through.

The same check found that the edge address `20.201.28.151` (São Paulo) answered HTTP 200 from the
VM. At 09:02 the blackhole script gained 71 edge addresses from `api.github.com/meta` (web, api,
git, packages). Afterwards `github.com`, `140.82.112.3` and that edge all fail, while a site
outside GitHub answers.

- `README.md`, *Execution environment*: a table dates each layer and names the runs it covers,
  and a paragraph says the first ten runs had the name block only. The routes now come from
  `tools/github-blackhole.sh`, new here: the VM's script, with the same commands and English
  comments, installed at boot by a oneshot systemd unit. The limit about unblocked edges gives way
  to one about the address list ageing.
- The ten `run.json` files: `execution_environment` states the name block and says addresses were
  not blocked yet.
- `MATRIX.md §4` item 5 and the results notes say the same.
- The version 0.2.11 entry above stays as written; this entry corrects it.

### `0.2.14` — 2026-09-30 — cost_time comes from the client's own usage summary and records the model's working time, never the wall-clock

SPEC §8.3 and SCORING §9.3 already had an informative `cost_time` block, with its time defined as
wall-clock. In mode A the wall-clock includes every wait for the operator (0.2.13), so it measures
the operator as much as the model. It was also never recorded: all ten runs have `cost_time: null`.

- **Source.** The numbers are copied from the client's own usage summary at the end of the
  session, for example Claude Code's `/cost`. The summary is kept as printed, as `custo.txt` next
  to `entrega/`, and `cost_time.source` names it. The agent's own account of its time is not used,
  since no one can check it.
- **Time.** `elapsed_seconds` is the model's working time as the client reports it, for example
  "Total duration (API)", and never the wall-clock. It is `null` when a client reports only
  wall-clock.
- **Best comparison.** Tokens compare best across providers. Time also depends on the provider's
  load.
- **Where it is written.** SPEC §8.3 and SCORING §9.3 (rewritten in English), the `cost_time` block
  of `scorecard.schema.json` (`source` added, `elapsed_seconds` redefined), `BENCHMARK.md` and the
  generated `COMO-RODAR.md`, which gains the step and a checklist row. The package is unchanged.
- **Where it shows.** Each scorecard's informative metrics gain a "Cost and time" line, and
  `results.json` carries `cost_time` per run. The ten existing runs read "not recorded". The
  generated `results/README.md` lists `custo.txt` among a run's files.

### `0.2.13` — 2026-09-30 — PROTOCOL fixes the one reply an operator may send an agent that stops to wait, and run.json counts it

In mode A an agent sometimes stops before its delivery is complete to ask a question, ask for
confirmation, or report progress and pause. Until now the protocol did not say what the operator
answers. A "go ahead" to one model and a sentence with context to another changes the run, and
nothing recorded it.

`PROTOCOL.md §3` now defines the reply. The operator's first message is turn 1 of the budget. Every
pause gets exactly *"Não há ninguém para responder. Decida com o seu próprio critério e continue."*,
the same for every agent, and each reply is one more turn. `run.json` records the count as
`operator_replies`.

- **Voiding.** Any other content voids the run: an answer, a hint, a correction. A voided run may be
  replaced only while its delivery is unscored, so the rule cannot become a selective retry.
- **Not a reply.** Approving a client's tool-permission prompt carries no content and is not counted.
- **Budget.** When the budget runs out, the delivery is whatever the agent has written.

The rule reaches the operator in three places: `BENCHMARK.md` step 3, and the `COMO-RODAR.md` that
`./leb pacote` writes, which gains a section with the reply and an "operator replies" row. The
generated guide is now in English; the lines sent to the agent stay in Portuguese, like the task.
`TAREFA.md` does not change, so the package keeps its SHA-256 (`34e38bc5…`, checked by rebuilding
it) and later runs stay comparable with the first ten.

For the ten existing runs `operator_replies` is `null` and listed in `not_recorded`: the reply was
written after them. Scorecards of mode-A runs gain an "Operator replies" row, and `results.json`
carries the field per run.

### `0.2.12` — 2026-09-30 — README publishes the execution VM's blackhole script, IPv6 prefixes included

In 0.2.11 the blackhole layer was reconstructed from a list of GitHub's announced prefixes, as four
`ip route add` lines, and the README said GitHub's IPv6 prefixes needed no route. The owner then
shared the script the VM actually runs, `github-blackhole.sh`. It installs the same four IPv4
prefixes and also three IPv6 prefixes (2a0a:a440::/29, 2620:112:3000::/44 and 2606:50c0::/32, the
nine announced ones collapsed). It uses `ip route replace`, so it can run any number of times, and
`del` removes the routes.

The README now publishes that script as the recipe to reproduce the setup, with its comments in
English. Layer 2 counts both families. Layer 3 says the IPv6 blackholes are a second lock, since the
VM has no IPv6 at all. The limits are unchanged.

### `0.2.11` — 2026-09-30 — README documents the execution VM's three layers of isolation from GitHub

The *Execution environment* section described only the block by name, and said a connection made
straight to an IP address still went through. That understated the VM. As the owner confirmed,
three layers have been in place since the first scored run:

1. GitHub's names resolve to loopback in `/etc/hosts`.
2. GitHub's IPv4 prefixes are blackhole routes. The 26 routed prefixes collapse to 140.82.112.0/20,
   143.55.64.0/20, 185.199.108.0/22 and 192.30.252.0/22, and the section publishes them as
   `ip route add blackhole` lines so the setup can be reproduced.
3. The VM has no IPv6.

The limits are rewritten to what is still true. `api.github.com/meta` lists regional edge
addresses for `web`, `api` and `git`, mostly on Azure (such as `20.201.28.151`), that fall outside
those prefixes, so a direct connection to one of them is not stopped. Copies of the repository
outside GitHub are out of scope. Training exposure is handled by the cutoff each run records.

`execution_environment` in the ten LEB-100-A `run.json` files said "GitHub blocked by name". It now
states the three layers, which is what those runs actually had. No scorecard or `results.json`
changes.

### `0.2.10` — 2026-09-30 — MATRIX §4 writes down the exception that keeps LEB-100-A current despite its published answer key

LEB-100-A's `private/` has been in this public repository since 2026-07-13, against MATRIX §4
item 1. The documents disagreed about what that meant. The results notes and the site said the
instance "should be retired for new runs". The cover README's open item asked for official runs
"on an instance whose answer key has not been published". Meanwhile, second and third runs of the
same models are about to be filed.

The owner's decision is now written down as MATRIX §4, item 5. LEB-100-A stays a current instance,
and three runs of it are official, under two conditions: every run executes on a machine that
cannot reach GitHub, and every run records the model's training cutoff as its provider publishes
it (0.2.9). A later or unpublished cutoff does not disqualify a run; it is flagged. Item 3 still
decides retirement, with the recorded cutoffs as the evidence.

- `README.md`: the open item on official runs and the note on matrices point at the exception.
- `results/2026/LEB-100-A/README.md`: the public-key caveat is rewritten. The instance stays
  current, nine of ten cutoffs precede the key, and MiniMax-M3's is unpublished. A new section,
  "Second and third runs", says how a repeated run is filed and judged: the same folder is the same
  model and effort, every run is filed, blind labels continue from K, the EXPL judge keeps its
  scale, and the consistency review covers every delivery.

The *Execution environment* section, which still describes only the block by name, is not changed
here. It waits for the exact IPv4 ranges the execution VM routes to blackhole.

### `0.2.9` — 2026-09-30 — run.json records the model's training cutoff, and the scorecard says whether the model could have trained on the answer key

LEB-100-A's answer key has been in this public repository since 2026-07-13. The execution VM keeps
it out of reach during a run; whether a model could have met it in training depends on how far that
model's training data reaches. That is now recorded instead of left as a blanket caveat.

`run.json` gains `model.training_cutoff` and `model.training_cutoff_source`: the cutoff the provider
publishes, with the page it comes from, and `null` when the provider publishes none (never a
third-party estimate; PROTOCOL §3). The ten runs on LEB-100-A are filled from the providers' pages,
checked on 2026-09-30:

| Model | Published cutoff | Source |
| --- | --- | --- |
| Claude Sonnet 5.5 · Fable 5.1 · Opus 5.5 | 2026-06 (training data cutoff) | Anthropic models overview |
| GPT-6.1-sol · GPT-6-astra | 2026-04-30 (knowledge cutoff) | OpenAI model pages |
| GPT-5.6-terra · sol · luna | 2026-02-16 (knowledge cutoff) | OpenAI model pages |
| GPT-5.5 | 2025-12-01 (knowledge cutoff) | OpenAI model page |
| MiniMax-M3 | not published | MiniMax model page and Hugging Face card |

`tools/export-results.py` holds the day each answer key became public (`KEY_PUBLISHED`; never in
`matrix.json`, whose hash is the published commitment) and derives `key_exposure` for each entry:
`before`, `after` (a month-precision cutoff counts to the month's last day) or `unknown`. Each
scorecard gains a "Training cutoff" row with that verdict; the leaderboard marks `after` and
`unknown` with † and names them below the table. Today nine are `before` and MiniMax-M3 is
`unknown`. `results.json` gains `key_exposure` per entry and `key_published_on` per instance; no
score moves.

### `0.2.8` — 2026-09-30 — tools/export-results.py publishes repeated runs: the lower median is the score, and the details are that run's

Second and third runs of the models already on LEB-100-A are about to arrive. The exporter already
found `run-<n>` folders on its own, but it published the median total next to the grade, the
categories, the flaw-by-flaw and the scorecard link of the *best* run — two runs would have put
numbers from different runs side by side.

The score is now `statistics.median_low` of the totals: the only total with one run, the lower of
two, the median with three (PROTOCOL §4). It is always the total of a run that exists, and that
run — `representative_run` in `results.json` — supplies everything shown next to it, including the
tie-breaking discovery index and Brier. Each entry also carries `totals`, in run order, and the
leaderboard's Runs column reads `2/3 (711 · 770)`.

The export now stops, before writing anything, when an agent has a gap in its run numbers, more than
three runs (a fourth is the retry §4 forbids), a `run.json` whose `run` disagrees with its folder,
or two runs with the same `RELATORIO.md` (one delivery filed twice). `PROTOCOL.md §4` gains item 4
with the publication rule.

With one run per agent today, `results.json` only gains the two fields; no number moves. Checked on
a scratch copy with synthetic runs: two runs (711, 770) publish 711 and run-1's details, three
(711, 770, 740) publish 740 from run-3 as official, and each refusal fires.

### `0.2.7` — 2026-09-29 — results/ adds MiniMax-M3 on LEB-100-A, tenth at 460

A tenth agent on the same package, and the first from neither Anthropic nor OpenAI: harness
(22/22; SEC-001 and BUG-001 fixed, SEC-008 and PERF-001 not), one blind matching judge
(delivery J), the EXPL judge on the scale it used for A–I (20/50), `score.py`.

MiniMax-M3 has no reasoning-effort setting — its client offers no selector — so it ran at the
model's default while the other nine ran at `xhigh`; `run.json` records it as
`reasoning_effort: "default"` with a note, and the notes say the comparison is not at equal
effort.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6.1-sol | 666 | Silver |
| 5 | GPT-6-astra | 661 | Silver |
| 6 | GPT-5.6-terra | 625 | Silver |
| 7 | GPT-5.6-sol | 612 | Silver |
| 8 | GPT-5.5 | 601 | Silver |
| 9 | GPT-5.6-luna | 599 | Bronze |
| 10 | MiniMax-M3 | 460 | Bronze |

The consistency review changed J's verdict: J answers `?export=csv` with 403 for every client,
which the manifest calls hiding tickets from whoever is entitled to see them; every other delivery
filtered the export instead, and D rejected the 403 for that reason. COMP-003 is added (−30), the
first review change that lowers a score; with the judge's call J would total 490, still tenth. The
evaluation notes, the generated leaderboard and the README status are updated for ten agents.

### `0.2.6` — 2026-09-29 — tools/export-results.py shows a model-default reasoning effort as not configurable

Some models expose no reasoning-effort setting — the client offers no selector and the model
always runs at its own default. That is a fact about the model, not a value someone forgot to
record, so a run's `model.reasoning_effort` may now be `"default"`, with an optional
`reasoning_effort_note`, and the scorecard prints "reasoning effort model default — <note>"
instead of the "not recorded" it used for a missing value. A recorded level (`xhigh`) renders as
before: the nine existing scorecards are byte-identical.

### `0.2.5` — 2026-09-29 — results/ adds GPT-6.1-sol on LEB-100-A, fourth at 666

A ninth agent on the same package, evaluated like the others: harness (22/22; SEC-001, BUG-001
and PERF-001 fixed, SEC-008 not), one blind matching judge (delivery I), the EXPL judge on the
scale it used for A–H (43/50), `score.py`. The consistency review changed nothing in its verdict.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6.1-sol | 666 | Silver |
| 5 | GPT-6-astra | 661 | Silver |
| 6 | GPT-5.6-terra | 625 | Silver |
| 7 | GPT-5.6-sol | 612 | Silver |
| 8 | GPT-5.5 | 601 | Silver |
| 9 | GPT-5.6-luna | 599 | Bronze |

GPT-6.1-sol migrated MD5 to `password_hash` and identified the dispatcher (ARCH-002) in its list of
deliberate non-changes; it kept SEC-008 unfixed for the CSV's consumers and scoped the SLA average
to the session (COMP-003, as B, C, G and H). The evaluation notes — including the places quoted in
the earlier reviews, which shift by one — the generated leaderboard and the README status are
updated for nine agents.

### `0.2.4` — 2026-09-29 — results/ adds GPT-6-astra on LEB-100-A, fourth at 661

An eighth agent on the same package, evaluated like the others: harness (22/22, all four
probes fixed), one blind matching judge (delivery H), the EXPL judge on the scale it used for
A–G (42/50), `score.py`. The delivery also ships the model's own test suite
(`entrega/.validacao/testes.php`), kept as delivered.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6-astra | 661 | Silver |
| 5 | GPT-5.6-terra | 625 | Silver |
| 6 | GPT-5.6-sol | 612 | Silver |
| 7 | GPT-5.5 | 601 | Silver |
| 8 | GPT-5.6-luna | 599 | Bronze |

GPT-6-astra is the third agent to fix SEC-008 and the strongest GPT model here; it left MD5 in
place on purpose and scoped the SLA average to the session (COMP-003, as B, C and G).

The consistency review changed H's verdict: the report names, in its list of deliberate
non-changes, the indentation of `rotuloPrioridade` — the nested ifs of CLN-007 — which is how A's
ARCH-002 was credited R1. H gets R1 for CLN-007 and nothing else; with the judge's call it would
total 636, still fourth. The evaluation notes, the generated leaderboard and the README status are
updated for eight agents.

### `0.2.3` — 2026-09-29 — results/ adds the GPT-5.6-terra and GPT-5.6-sol runs on LEB-100-A

Two more agents on the same package, evaluated like the first five: harness (22/22 for both),
one blind matching judge each (deliveries F and G), the EXPL judge on the scale it used for A–E,
`score.py`.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-5.6-terra | 625 | Silver |
| 5 | GPT-5.6-sol | 612 | Silver |
| 6 | GPT-5.5 | 601 | Silver |
| 7 | GPT-5.6-luna | 599 | Bronze |

GPT-5.6-sol is the second agent to fix SEC-008; its sanitizer also rewrites the `-` placeholder
of the technician column (PEN-001, kept). GPT-5.6-terra keeps compatibility at 100.

The consistency review changed F's verdict: its judge counted `AVG`'s 4-decimal precision in
`mediaResposta()` as COMP-003, which C's judge had ruled inside the characterization tolerance;
the violation meant — and charged to B, C and G — is scoping the average to the client. The rule
as worded in the brief for F and G was imprecise and is corrected in the notes. This change moves
the ranking: with the judge's call F would total 584 (Bronze, 7th). The evaluation notes, the
generated leaderboard and the README status are updated for seven agents.

### `0.2.2` — 2026-09-29 — results/ adds Claude Sonnet 5.5 on LEB-100-A, first at 825

A fifth agent on the same package, evaluated like the first four: harness, one blind matching
judge (delivery E), the EXPL judge on the scale it used for A–D, `score.py`.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-5.5 | 601 | Silver |
| 5 | GPT-5.6-luna | 599 | Bronze |

Sonnet 5.5 is the only agent to fix SEC-008 (formula injection in the CSV) and the only one at
SEC 250/250; it fixed BUG-004 silently, so that fix scores without the finding.

The consistency review changed one verdict: E's judge counted the login timing side channel of
the md5 → `password_hash` migration as PEN-001 (−15), while D's judge had ruled the same class of
channel inherent to the migration the matrix expects, and A's had not counted it either. PEN-001
is 0 for E, recorded in its `veredito.json` and scorecard; with it E would total 810, still
first. The evaluation notes, the generated leaderboard and the README status are updated for
five agents.

### `0.2.1` — 2026-09-29 — scorecard.md shows the consistency review that changed a verdict

A verdict can be changed after its judge wrote it, when a review across the deliveries of an
instance finds one rule applied two ways. The change lives in the verdict's `review` list
(what changed, by whom, why), and `tools/export-results.py` now renders it as a "Consistency
review" section at the end of the scorecard — where the score is read, not only in the json.

### `0.2.0` — 2026-09-29 — results/ publishes the first scored runs: four agents on LEB-100-A

First results of the benchmark: Claude Fable 5.1, Claude Opus 5.5, GPT-5.5 and GPT-5.6-luna,
all at reasoning effort xhigh, one run each on LEB-100-A v1.1 in mode A (30 turns), against
the same package (`34e38bc5…`). Each run folder keeps the delivery exactly as handed back, its
parameters, the mechanical report, the judge's verdict and the scorecard.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Fable 5.1 | 781 | Gold |
| 2 | Claude Opus 5.5 | 711 | Silver |
| 3 | GPT-5.5 | 601 | Silver |
| 4 | GPT-5.6-luna | 599 | Bronze |

Not official: an official score is the median of three runs. The judges were Claude Opus 5.5
subagents working on anonymized deliveries (one matching judge per delivery, one EXPL judge
blind to the answer key and to every other score) — and Claude Opus 5.5 is also a contestant.
Exact model versions, temperature, tokens and logs were not recorded and are `null`.

`results/2026/LEB-100-A/README.md` states how the runs were evaluated, the two harness defects
fixed before scoring (0.1.14, 0.1.15), and that the answer key of LEB-100-A has been in this
public repository since 2026-07-13, so the instance should be retired for new runs. The README
status moves the reference runs from pending to a first, unofficial set.

### `0.1.16` — 2026-09-29 — tools/export-results.py renders the scorecards and the results file from evaluated runs

`harness/score.py` stops at `scorecard.json`; PROTOCOL §7 asks for the scorecard in `.md` and
`.json`, and a public results page needs one file to read. The new tool walks
`results/<edition>/<instance>/<agent>/run-<n>/` (each holding `run.json`, `mecanico.json`,
`veredito.json` and `scorecard.json`) and writes:

- `scorecard.md` next to each `scorecard.json`: parameters, total and categories, criterion by
  criterion per flaw, the judge's rationale, COMP, EXPL with its justification, penalties,
  false positives, extra findings, calibration, difficulty and the mechanical evidence;
- `results/results.json`, the aggregate the site reads;
- `results/README.md`, the leaderboard, ranked by median total and then by the informative
  metrics (SCORING §9).

It imports the criterion table from `score.py` instead of copying it, reads a `half` from the
verdict (half of a 1-point criterion floors to 0 points but is still a half), and is
deterministic — `--check` exits 1 when an output would change.

### `0.1.15` — 2026-09-29 — The CSV checks read the export from the output the manifest promises

The manifest of LEB-100-A says `exportarCsv(mysqli $db): void` "writes the CSV to the output";
it says nothing about a file on disk. The characterization suite (`run.php`) and the SEC-008
probe (`private/verify/probes.php`) nevertheless discarded the output and read
`EXPORT_DIR/chamados.csv` — an implementation detail of the legacy code.

A delivery that streams the CSV to `php://output`, or writes a uniquely named temp file and
deletes it, put the same bytes on the output and was still charged three broken tests
(PEN-002, −60), lost C4 on every fix, and failed SEC-008 on an empty cell. Both checks now read
what `exportarCsv()` wrote to the output buffer. For the legacy code that is the same content
(it ends in `readfile()`), so the baseline stays 22/22 and SEC-008 stays PLANTADA; a patched
copy flips it to CORRIGIDA with and without the temp file.

The matrix is untouched (`matrix.json` hash unchanged). Found while scoring the first runs.

### `0.1.14` — 2026-09-29 — The characterization loader splits SQL the way the mysql client does

`statementsSql()` in `instances/LEB-100-A/characterization/_bootstrap.php` dropped only
whole-line `--` comments and then split the file on every `;`. A trailing comment that
contains a semicolon — `senha VARCHAR(255) NOT NULL, -- password_hash(); …`, valid SQL the
mysql client loads — cut the `CREATE TABLE` in two, and every probe and characterization
check of that delivery crashed before running.

The loader now walks the file the way the client does: a `;` ends a statement only outside
quotes and outside `-- `, `#` and block comments. On the legacy `schema.sql`/`seed.sql` and on
every delivery evaluated so far it yields the same statements as before, except where the old
split was wrong. The legacy code still scores 22/22 with every probe PLANTADA.

Found while scoring the first runs (see `results/2026/LEB-100-A/README.md`).

### `0.1.13` — 2026-09-29 — README documents that benchmark runs happen on a VM with no GitHub access

The cover README gains an "Execution environment" section: the VM that runs the
benchmark resolves `github.com` and the GitHub content hosts to loopback, so an
agent under test cannot open, clone or download this repository (or anything else
on GitHub) during a run. The section publishes the `/etc/hosts` block so the setup
can be reproduced, and states the limits: the block is by name (a direct connection
to an IP address still opens), it says nothing about training data, and it does not
replace keeping an answer key out of every place a model can reach.

No hostnames or addresses of the execution VM are published.

### `0.1.3` — 2026-09-02 — Agent doc: Releases rule and the English-only language rule

Marked echo of the single source at samirhvbr/repodocs. Two rules land here:

1. The `version.md` of the default branch ON GITHUB is what the GitHub Releases
   show, and a commit that bumps it is not finished until that version has a
   tag, a Release and the `Latest` badge — same push, not "later".
2. Everything in this repository is English (US): documents, commit messages,
   pull requests, issues, code comments. The only carve-out is end-user-facing
   product strings. History is not rewritten.

Delimited by a marker, so re-running replaces instead of duplicating.

### `0.1.2` — 2026-09-02 — Regra de Releases no doc de agente: bump e Release sao um ato so

Eco marcado da norma unica em samirhvbr/repodocs (docs/versioning.md). O
`version.md` da branch padrao NO GITHUB e o que as Releases no GitHub mostram, e
um commit que bumpa o `version.md` nao esta terminado ate aquela versao ter tag,
Release e o badge `Latest`.

Bloco delimitado por marcador: rodar de novo substitui, nao duplica.

### `0.1.1` — 2026-09-02 — Releases automaticas: o version.md da master vira tag e Release

O GitHub nao deduz versao de mensagem de commit: sem tag, o numero e string no
`git log` e `git diff` entre versoes nao existe. Entram o
`.github/workflows/release.yml` e o `tools/release.sh`.

**A regra:** o `version.md` da branch padrao **no GitHub** e o que as Releases
**no GitHub** refletem. Checkout local nao entra na conta. Um PR nao publica
nada; no merge, o push do `version.md` dispara o workflow e a Release vira
aquela versao.

Tag e titulo = a versao pura, sem prefixo `v`. Norma:
[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md).

### `0.1.0` — 2026-07-30 — Adota o versionamento da casa

Passa a seguir o padrão dos demais repositórios: `version.md` como fonte da verdade,
commits no formato `X.Y.Z - Descrição em português` e changelog como registro de
entrega.

O gatilho foi prático: o repo já participava da skill **COMMITTER**, mas sem
`version.md` não existia o formato da casa — o ciclo reportava e **não commitava**.
Com este arquivo, a skill passa a operar aqui pelo caminho determinístico (sem custo
de modelo), lendo a mensagem da entrada de changelog.

_Gatilhos:_ adoção de infraestrutura de versionamento.
