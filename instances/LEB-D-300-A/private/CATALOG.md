# LEB-D-300-A catalog (the planting profile on paper)

27 planted flaws + 4 decoys: ARCH 8, BUG 8, PERF 7, CLN 4, no SEC. These are LEB-300-A's counts without its security
rows, the size at which no agent of the LEB-300-A aggregate reached the maximum of ARCH, BUG, PERF or CLN
([`docs/new-series-design.md`](../../../docs/new-series-design.md) §1 item 2). Decoys: 4 of 31 rows, 12.9 %, inside the 10–20 % of
[`matrix/MATRIX.md §3`](../../../matrix/MATRIX.md).

This catalog is written before any planting code. The planting modules (`plant/`), the matrix and the verifiers are built from it;
where they have to depart from it, this file changes in the same commit.

- **Site** is the class and method in the reference layout ([`systems/300-A/reference/`](../../../systems/300-A/reference/),
  `src/main/java/com/condodesk/`). The candidate may move code; the verifiers do not depend on names. Rows whose site is in
  `BillingService`, `PaymentService` or `Charges` end up in the merged class of ARCH-001, which is planted last.
- **Template C** is behavioral: a probe decides C3. **Template R** is structural: the property decides R3, and a behavioral probe,
  where one is given, is extra evidence.
- **Ids** are taxonomy ids only, decoys included. A `.b` suffix marks a decoy that shares the class of a planted row. LEB-300-A's
  catalog named its decoys D1–I2 and drifted from its matrix on exactly that, and on three sites.
- **Points** follow the matrix generator: Template C 10/8/6/5 and Template R 12/10/8/6 for Crítica/Alta/Média/Baixa.

## 1. Constraints every row obeys

1. **No row weakens a defense.** The reference's `SecurityBehaviorTest` (20 tests) stays in this instance's public suite and passes
   on the planted tree. No row touches an escaping, a CSRF or session check, an access check, an allowlist, the XML parser's
   configuration, the password storage, the throttle or the upload checks. ARCH-014 is not planted, because every setting of
   `Config` is either a secret, a security control or a value the tests depend on; its id serves a decoy (section 6).
2. **One line, one flaw** ([`MATRIX.md §3`](../../../matrix/MATRIX.md), rule 5). No row's fix also fixes another row. Where two rows
   meet (section 7), each probe is built so that it decides its own row only.
3. **Each row comes with its probe or its property**, so that its verifier can be written. The PERF probes count the database
   commands and rows through the hook `TestBase.forTheApp(DataSource)`. The race and lock probes hold a transaction at a gate
   on a named statement, never by sleeping. No public test counts queries today.
4. **A public test that sees a flaw leaves the public suite**, with its reason, in `private/removed-from-public-tests.txt`. One
   test does (section 8).
5. **The reference's own borderline patterns are decided before any run** (section 9): each one is either fixed in the
   reference, or accepted with its reason, so that a report of it is judged by a rule fixed in advance.

## 2. BUG (Template C)

| ID | Sev | Diff | Site | Mechanism | Probe |
|---|---|---|---|---|---|
| BUG-011 | Crítica | Especialista | `BookingService.book` | the unit's row is no longer locked, so the limit of two upcoming bookings is check-then-act: two requests of one unit for two different areas both count one upcoming booking and both insert | a unit holding one upcoming booking sends two requests for two different areas, held at a gate after both counted: the unit ends with two upcoming bookings and one request gets 409 |
| BUG-014 | Alta | Difícil | `PaymentService.importStatement` | each statement line settles in a transaction of its own; a later line that fails (a credit without FITID) leaves the earlier lines recorded, against manifest §4.3 ("whole or not at all") | a statement with a matched credit then a credit without FITID answers 400, records no payment, and leaves the invoice OPEN |
| BUG-006 | Alta | Difícil | `BookingService.book` | the date that decides "an invoice overdue for more than thirty days" is `LocalDate.now(clock)`, the clock's zone (UTC in production), not the building's calendar | at 2026-03-04T01:00Z (March 3, 22:00 in São Paulo), a unit whose only open invoice is due 2026-02-01 books (201): 30 days late on the building's calendar is not "more than thirty" |
| BUG-013 | Alta | Moderada | `Charges.daysLate` | the days late are `Period.between(due, on).getDays()`, the day component of the period, not its length in days: from one month late on, the interest and the "days late" restart from zero | an invoice of 300.00 due 2026-01-10, read on 2026-03-04 (53 days late): amount due 311.30 and `days_late` 53, on the invoice page, the API and the delinquency report |
| BUG-003 | Alta | Difícil | `Db.read` | the connection is closed after the work returns, not in a `finally`: every read that throws (a 404 decided inside the work, a database error) leaks its connection | 50 requests for another unit's invoice (404) leave no connection open: the wrapped `DataSource` sees as many closes as opens |
| BUG-010 | Média | Moderada | `Archiver.tarGz` | the exit status of `tar` is not checked: when a stored file is missing, `tar` exits with 2 and the user downloads an archive without it, as if it were whole | with one stored file deleted from the storage folder, `POST /documents/archive` does not answer 200 (the reference answers 500 with an incident) |
| BUG-007 | Média | Moderada | `PaymentService.settle` | "the payments cover the amount due" is written `paidTotal.equals(due)`: only an exact total settles, and a payment above the amount due leaves the invoice OPEN for good | a webhook payment of 310.00 for an invoice of 300.00 not yet due answers `PAID`, and the invoice is PAID |
| BUG-015 | Baixa | Moderada | `Server.serve` | an internal failure is logged with its incident id but without the exception: the cause and the stack trace of every 500 are lost | a request that fails inside (a document whose file is gone) answers 500, and the log record that carries its incident id also carries the exception |

## 3. PERF (Template C)

| ID | Sev | Diff | Site | Mechanism | Probe |
|---|---|---|---|---|---|
| PERF-001 | Alta | Moderada | `ReportService.delinquency` | the owners of each late invoice's unit are read by one query per invoice, instead of one query for the whole list | database commands per delinquency request are the same with 3 and with 30 late invoices |
| PERF-003 | Alta | Fácil | `FinanceRepository.search` | the invoice list reads every matching row and keeps one page in memory | the rows read for one page of 20 do not grow between 40 and 400 matching invoices |
| PERF-010 | Alta | Difícil | `ReportService.dashboard` (admin) | the admin's building totals are computed once and kept in a field for the life of the application: open invoices never change on the dashboard | the admin's dashboard, then a billing run of 3 units, then the dashboard again: the building's open invoices go from 0 to 3 |
| PERF-006 | Média | Moderada | `schema.sql` | no index on `payment(invoice_id)`, which every settlement (`paidTotal`), every invoice page and the collection report filter or join by | after `schema.sql` and the candidate's `migrations/`, an index leads on `payment(invoice_id)` |
| PERF-012 | Média | Especialista | `UserRepository.list` | the users and the residencies are read separately and joined in memory with two nested loops: the admin's user list is quadratic in the number of accounts | the user list with 16,000 accounts takes less than 8 times as long as with 4,000 (a child JVM, the same machine) |
| PERF-015 | Média | Moderada | `PaymentService.webhook` | the webhook handler is `synchronized` on the service: every bank event, of every building, waits for the one before, although the invoice's row lock already orders the events of one invoice | an event held at a gate inside its transaction does not stop an event for another invoice from answering |
| PERF-009 | Baixa | Fácil | `ReportService.dashboard` (manager) | the manager's count of overdue invoices loads every overdue invoice of the building and takes the list's size | the rows read for the manager's dashboard do not grow between 3 and 300 overdue invoices |

## 4. ARCH (Template R)

| ID | Sev | Diff | Site | Mechanism | Structural property (name-independent) |
|---|---|---|---|---|---|
| ARCH-001 | Crítica | Difícil | `BillingService` + `PaymentService` + `Charges` | merged into one finance class: the billing run, the invoice list and view, the statement import, the webhook and its signature, the settlement and the money rules (split, amount due, days late, settled share) | the class that splits a budget and computes the amount due has no database access; the class that runs the billing run is not the class that settles payments |
| ARCH-013 | Alta | Especialista | `Db`, `App.create` | the `DataSource` is kept in a static field of `Db`, set by every `App.create`: a second application in the same JVM takes over the first one's database | no static mutable field holds a `DataSource`, a `Db` or a `Clock`; evidence: two applications in one JVM, on two schemas, each write to their own |
| ARCH-008 | Alta | Difícil | `ReportRepository` ↔ `ReportService` | the record of the admin's building totals moves into the report service, and the repository builds it: `repo` depends on `reports`, which depends on `repo` | no dependency cycle among the sub-packages of `com.condodesk`, with at least as many sub-packages as the legacy (the root package, which wires everything, is outside the check: the reference already has `App` → `web` → `Config`) |
| ARCH-002 | Alta | Moderada | `ReportPages.collection` | the page runs the month totals SQL itself, computes the settled share and writes the HTML; the service method and the repository query are gone | no class of the package that holds the HTTP server references `java.sql`, `javax.sql` or the database helper |
| ARCH-004 | Média | Difícil | `ReportService` (resident's dashboard) | the amount-due rule is written a second time, with its own day count, which already diverges from `Charges.daysLate` (BUG-013) | the late fee and the interest (the division by 100 and by the 30-day month) are computed in one class only |
| ARCH-003 | Média | Moderada | `NoticeService.publish`, `NoticeService.get` | the service writes and reads the notice table with SQL of its own, beside the repository that keeps the search | no class that holds an SQL literal on the `notice` table also holds a notice rule (the length limits) |
| ARCH-010 | Baixa | Fácil | `billing/CnabParser` (new file) | a parser of the bank's old CNAB return file, which the OFX import replaced: nothing calls it | every top-level class of the main code is referenced by another class of the main code (`App` excepted) |
| ARCH-009 | Baixa | Fácil | `NoticeService` | the notice limits (200, 10,000, 5 words) are bare literals, each written twice: in the check and in its message | each of the three limits is written once, as a named constant, with the same value |

## 5. CLN (Template R)

| ID | Sev | Diff | Site | Mechanism |
|---|---|---|---|---|
| CLN-001 | Média | Moderada | `FinancePages.list` | one method of 80 non-blank lines: the filters, the sort, the table, the filter map and the pager, inlined (the limit declared in the matrix is 45; the reference's longest method has 35) |
| CLN-007 | Média | Moderada | `BookingService.cancel` | five nested levels instead of guard clauses, the main flow at the bottom (the reference's deepest nesting of `if` is 2) |
| CLN-002 | Baixa | Fácil | `MobileApi`, `ContentPages` | `FinancePages.enumOf` copied into both, with the same body (the reference has no two methods with the same body) |
| CLN-005 | Baixa | Fácil | `AdminPages` | a block of commented-out code: an old handler that deactivated an account |

## 6. Decoys (4): `exists: false`

| ID | Kind | Site | Looks like | Why it is not a flaw |
|---|---|---|---|---|
| BUG-013.b | diagnosis | `Charges.split` | money rounded half-even | the quotient at 12 decimals is an intermediate value: each share is floored and the leftover cents are handed out, so the invoices add up to the budget exactly. With fractions of at most 6 decimals, a quotient that is not a whole number of cents is at least 1/s away from one (s, the sum of the fractions times 10⁶), far more than the 5 × 10⁻¹³ that rounding the 12th decimal can move it, so the rounding never moves a floor |
| ARCH-014 | diagnosis | `BillingService.PAGE_SIZE`, `NoticeService.PAGE_SIZE` | hard-coded configuration | 20 invoices and 10 notices per page are the contract (manifest §2), not a setting |
| BUG-010.b | diagnosis | `OfxParser.SILENT` | an error handler that swallows errors | it ignores warnings only; `error` and `fatalError` rethrow, so a malformed statement is refused with 400 |
| PERF-015.b | intervention | `BookingService.book`, `BuildingRepository.lockArea` | needless blocking | the area's row lock is what decides two requests for one slot one after the other; without it both pass the overlap check (the public test `concurrentRequestsForTheSameSlotLeaveOneBooking` saw three bookings of one slot, in three runs out of three) |

12.9 % of the matrix lines: 3 of diagnosis and 1 of intervention.

## 7. Where rows meet

- **BUG-013 and ARCH-004.** The resident's dashboard has its own amount-due rule (ARCH-004) with a correct day count, so the two
  copies already disagree once an invoice is a month late. BUG-013's probe reads the invoice page, the API and the delinquency
  report, never the dashboard; ARCH-004 is decided by its property alone. Unifying the two copies without fixing BUG-013 spreads
  the wrong count to the dashboard; that is visible to BUG-013's probe only through the pages it already reads.
- **PERF-015 and the invoice lock.** The settlement keeps its row lock on the invoice, so the `synchronized` is redundant (PERF-015);
  it is not a guard that something else relies on. BUG-011 is planted on the booking side, so that no race probe depends on the
  webhook's lock.
- **PERF-001 and PERF-009, PERF-010, ARCH-004.** Four rows in `ReportService`, in four different methods: the delinquency loop,
  the manager's count, the admin's cache and the resident's dashboard.
- **ARCH-001 and the rows inside it.** BUG-013, BUG-014, BUG-007 and PERF-015 are planted in their original classes; the merge of
  ARCH-001 is applied last, by the module that runs after all the others (as LEB-300-A's `zz_classe_deus`), and the matrix reads
  their lines in the merged class.
- **BUG-003 and ARCH-013.** Both in `Db`, on different lines: the leak is in `read`, the static holder is a field and its setter.
  The leak probe goes through `forTheApp`, which reaches `Db` through `App.create` either way.
- **BUG-011 and the decoy PERF-015.b.** The unit lock is removed, the area lock stays. Removing the area lock as well is the
  intervention the decoy counts.

## 8. Public tests

- `BillingTest.aStatementWithABadLineRecordsNothing` loses its first part (a matched line then a line without FITID: 400 and no
  payment), which sees BUG-014. Its second part (a malformed file answers 400) stays.
- Every other test of the reference passes on the planted tree (checked when `public/code/` was written, 0.2.192: 70 of 70
  with the trimmed test, and the untrimmed one fails there and passes on the reference). No test of the reference counts
  queries, rows or connections, holds a transaction, reads the log, deletes a stored file, runs two applications or bills a
  unit more than a month late, which is where the probes above look.
- BUG-003 leaks a connection on each exception thrown inside a read. Sampled every half second while the public suite ran on
  the planted tree, the database never held more than 4 connections, against a limit of 200: the driver closes a leaked
  connection when it is collected. The probe does not rely on that; it counts the closes the wrapped `DataSource` sees.

## 9. The reference, before planting

Writing this catalog was also a review of the reference. These corrections were made to `systems/300-A/reference/` first, in a
commit of their own (0.2.185), with the public suite passing, and both instances are planted on the corrected reference. A
throwaway test, kept out of every suite, failed on the reference before the commit and passed after it for R1 and R2; R3
changes no output and R4 removes code nothing calls, so the compiler and the 70 tests are their check.

| # | Where | What was found | Correction |
|---|---|---|---|
| R1 | `ReportPages.collection`, `ReportPages.delinquencyCsv`, `ContentPages` | the default year of the collection report, the date in the CSV's file name, a notice's date and a document's "Added" date are taken in UTC, not on the building's calendar | taken on the building's calendar, as everything else |
| R2 | `MobileApi.book`, `BookingPages.book` | `guests` is narrowed from `long` to `int` with a cast: 4,294,967,297 guests become 1 and pass the capacity check | a value outside `int` answers 400 |
| R3 | `ReportService.manager` | the manager's count of overdue invoices loads them all to count them | a count query (the planted PERF-009 restores the load) |
| R4 | `Response.text`, `FinanceRepository.unmatched`, `BuildingRepository.all`, `BuildingRepository.area`, `Db.DataAccessException.sqlState` and `isUniqueViolation` | members nothing calls | removed |

Accepted as they are, with their reason (a report of one of them is judged on its merits, as an extra finding; none is a decoy,
because a careful reviewer may reasonably flag them):

- `BillingService.run` inserts one invoice per unit, inside one transaction, once a month per building: bounded by the building's
  units and invisible to every consumer.
- The admin's building totals and the resident's and manager's dashboards are recomputed on every request, by aggregate queries
  that each read one building's rows. The planted PERF-010 is a cache that makes them wrong, not faster.
- `PaymentService.settle` reads the invoice's building once per settled line, by primary key.
- A response is a `byte[]`: the building's archive is built in memory, bounded by the documents of one building (5 MiB each).

## 10. Planting order

`plant/` holds `paths.py` and one module per group, loaded in name order by [`tools/plant.py`](../../../tools/plant.py):
`flaws_a_bugs.py`, `flaws_b_perf.py`, `flaws_c_arch.py` (every ARCH row but ARCH-001), `flaws_d_cln.py`, and `flaws_z_god.py`,
the merge of ARCH-001, last, because it moves every row planted in the three classes it merges. A repository method that only
the reference's version of a row used is removed by that row, so that no row leaves dead code behind for ARCH-010's property
to find.
