# System 300-A — CondoDesk (condominium management)

> **Status: the reference, its 70 characterization tests and its manifest are written; the planting profiles are next.** The
> instances [`LEB-D-300-A`](../../instances/LEB-D-300-A/) and [`LEB-S-300-A`](../../instances/LEB-S-300-A/) exist as folders in
> authoring, with no package, key or run; §9 lists what stands between them and a first run. This page is the specification the
> reference was written against; [`manifest.md`](manifest.md) is the contract and wins over this page wherever the two differ.

CondoDesk is the back office of a property-management company that runs several condominium buildings for their owners'
associations. It bills each unit its share of the building's monthly budget, takes payments from the bank, books the common
areas, publishes notices and keeps the building's documents. Residents use it from the browser and from a mobile app.

Decided by the owner on 2026-10-12 ([`docs/new-series-design.md §2`](../../docs/new-series-design.md)): level 300, Java 21 with
Maven and PostgreSQL, code, comments and screens in English.

## 1. Constraints

- **Toolchain:** Java 21, Maven 3.9, PostgreSQL 16 or newer. The bench VMs already carry it.
- **Dependencies:** exactly LEB-300-A's, at the same versions: `org.postgresql:postgresql` 42.7.13,
  `com.fasterxml.jackson.core:jackson-databind` 2.22.3, and for the tests `org.junit.jupiter:junit-jupiter` 5.14.4 and
  `com.tngtech.archunit:archunit-junit5` 1.5.1, with the same plugin versions. The offline Maven cache the bench VMs hold for
  LEB-300-A then serves this system unchanged. Nothing else: the HTTP server is the JDK's (`com.sun.net.httpserver`), XML is the
  JDK's, hashing is the JDK's (PBKDF2), and HTML is built by the code itself.
- **Size:** level 300 ([`levels/LEVELS.md`](../../levels/LEVELS.md)), so that flaws cross files and the agent has to navigate.
  The reference has 3,908 lines of main code (3,406 not blank) in 41 files, above the level's 3,000: it has two faces, the
  browser pages and the mobile API, and the security classes need both.
- **Determinism:** the clock is injected (`java.time.Clock`), every building has a time zone (`America/Sao_Paulo` in the
  fixtures), and nothing depends on the machine's locale or zone.
- **The clean reference is clean on both axes.** It is reviewed for the maintenance categories and for every class of
  [`taxonomy/SEC.md`](../../taxonomy/SEC.md) it exposes. Its description is *no known vulnerabilities within the reviewed scope*,
  never a claim of absence (design §4.1).

## 2. Users and roles

| Role | Scope | Can |
| --- | --- | --- |
| `ADMIN` | the company: every building | manage users, run the monthly billing, import bank statements, archive a building's documents, everything a manager can |
| `MANAGER` | one building | publish notices, upload and fetch documents, confirm bookings, read the building's reports and residents |
| `RESIDENT` | the units they own or rent, in one building | see and pay their invoices, upload payment proofs, book common areas, read notices and the building's documents |

## 3. Data

| Table | Holds |
| --- | --- |
| `building` | name, address, time zone, due day of the month, late fee (%), monthly interest (%) |
| `unit` | building, label (`Apt 101`), ideal fraction (the fractions of a building sum to 1) |
| `app_user`, `residency` | users with role and building; which units a resident owns or rents |
| `user_session`, `api_token`, `login_throttle` | browser sessions (hash of the cookie, CSRF token), mobile tokens (hash), failed-login counters |
| `billing_run`, `invoice` | one run per building and month with its budget; one invoice per unit and month, with its *our number* |
| `payment` | payments from statements, the bank webhook or by hand; matched to an invoice or left unmatched |
| `common_area`, `booking` | areas with opening hours, maximum length, capacity and fee; bookings and their status |
| `notice`, `document` | notices of a building; documents (minutes, rules, statements, payment proofs) and where they came from |
| `audit_log`, `webhook_event` | who did what; the bank events already processed |

## 4. Behavior

- **Billing run** (admin): for a building and a month, the budget is split by ideal fraction. Shares are computed in cents,
  and the cents left by rounding go to the units with the largest remainders, so the invoices add up to the budget exactly. A
  month is billed once. Each invoice is due on the building's due day of the next month, in the building's time zone.
- **Amount due:** after the due date, a late fee (2% in the fixtures) plus interest pro rata die (1% a month), computed on the
  building's local date and rounded half-up to the cent.
- **Payments:** an OFX statement (XML) is imported by an admin; each credit is matched to an invoice by the *our number* in its
  memo. An event from the bank's webhook does the same, signed with HMAC-SHA256 and a secret from the environment. A payment
  that covers the amount due on its date pays the invoice; a smaller one is recorded and the invoice stays open; an unmatched
  one is recorded without an invoice. Statements and events are idempotent by the bank's id.
- **Bookings:** within the area's opening hours, up to its maximum length, no overlap with another active booking of the same
  area, at most two future bookings per unit, and none for a unit with an invoice overdue for more than 30 days. Bookings of
  areas with a fee wait for the manager's confirmation. A resident can cancel up to 48 hours before the start.
- **Notices:** plain text with line breaks and `**bold**`; search by words in the title and body.
- **Documents:** upload of PDF, PNG and JPEG up to 5 MB, checked by content; download by id with the original name; fetch from
  a URL whose host is on the configured allowlist; the building's manager or an admin can download its documents as one
  `tar.gz`, named by an optional label.
- **Reports:** delinquency by building (HTML and CSV), the collection of a year by month, and a dashboard per role.
- **Mobile API** (JSON, bearer tokens): the resident's invoices and bookings, and the bank webhook.

## 5. Where each security class meets the reference

The reference defends at each of these places; the Security profile plants its vulnerabilities there, and its decoys too.

| Class | Place | The reference's defense |
| --- | --- | --- |
| SEC-001 SQL injection | invoice list filters and sort; notice search | bound parameters; sort column from an allowlist |
| SEC-002 Stored XSS | notices, names, document names | escaping at output; the markup is applied after escaping |
| SEC-003 Reflected XSS | search term, login error | escaping at output |
| SEC-004 DOM XSS | `static/app.js`: the fragment that highlights a notice, the flash message | `textContent` and an id pattern |
| SEC-005 CSRF | every state-changing form | a per-session token checked on every POST of the browser side |
| SEC-006 Path traversal | document storage and download | stored names are generated; the path is resolved and checked against the storage root |
| SEC-007 Header injection | download `Content-Disposition`; redirects | names reduced to a safe set; CR and LF rejected |
| SEC-008 CSV injection | delinquency CSV | formula-leading cells neutralized, the rest of the format unchanged |
| SEC-009 Command injection | building archive (`tar`) and its label | an argument vector, no shell, file names after `--`, the label checked against a pattern |
| SEC-010 XXE | OFX import | DTDs and external entities disabled in the JDK parser |
| SEC-011 SSRF | fetch a document from a URL | host and port on an allowlist, no redirects, size and type limits |
| SEC-012 Open redirect | `next` after login | only local paths |
| SEC-013 Session fixation | login | a new session id at login; the anonymous one is dropped |
| SEC-014 Weak password | storage and policy | PBKDF2 with salt and cost; accounts imported from the previous system (unsalted SHA-256) upgraded at their next login; a minimum length and a list of common passwords |
| SEC-015 Secrets hardcoded | bank webhook secret, database password | read from the environment |
| SEC-016 Missing authorization | manager and admin actions | a role check on every such route |
| SEC-017 IDOR | invoices, documents, bookings by id | ownership or building scope checked on every access |
| SEC-018 Insecure upload | payment proofs and documents | type by content, size limit, stored outside any served path, served as an attachment with `nosniff` |
| SEC-019 Missing rate limit | browser login and mobile token | failures counted per account and per address; 429 while locked |
| SEC-020 Sensitive information exposure | errors and API responses | a generic error with an incident id; no hash or internal detail in any response |

## 6. Hosts for the Default profile

The maintenance flaws need places where they are natural, and the reference has them: the money arithmetic of the billing run
and of the amount due (rounding, time zone, division), the transaction of the billing run and of the statement import
(rollback, silent errors), the booking rule under concurrent requests (the row lock on the area), the reports (one query per
unit, pagination, statistics recomputed per request), the repositories and services (where an architecture flaw can merge or
couple them), and the resources the code opens (connections, streams, processes).

## 7. How the instances are built

The machinery of LEB-300-A, made generic and public. LEB-300-A's operator scripts, the ones that compile and run its tests and
verifiers, never reached this repository, and a replicator cannot evaluate its runs without writing them again; this series
publishes its scripts with the instances.

1. `reference/`: the clean system, with its characterization tests (public) and its schema.
2. `manifest.md`: the public contract both instances ship, and `package/`: the environment notes (`ENVIRONMENT.md`) and the
   check script (`check-environment.sh`) that go into both packages' `code/`.
3. Per instance, a planting profile: exact-match edits applied to a copy of the reference, which yield the legacy tree and
   `public/code/`.
4. Per instance, the matrix generated from the legacy tree, the verifiers, and the variant cases that check each verifier in
   both directions (a right fix is accepted, a partial or wrong one is rejected).
5. The evaluator's scripts, public and shared by every Java system of the series, in [`harness/java/`](../../harness/java/):
   `mvn.sh` runs Maven offline in a pinned container, `dev-db.sh` keeps a disposable PostgreSQL on an internal network, with
   names of its own so that it never touches another evaluation, and `evaluate.sh` characterizes and verifies a delivery with the
   instance's own build file, schema and public tests ([`harness/README.md`](../../harness/README.md)).

## 8. Running the tests

From the repository root, with Docker:

```sh
harness/java/mvn.sh systems/300-A/reference test -Dtest=StaticFilesTest       # once, online: fills the Maven cache (~20 MB)
harness/java/dev-db.sh up                                                     # the disposable database
LEB_DB=1 LEB_M2_MODE=ro harness/java/mvn.sh systems/300-A/reference -o test   # offline, as an evaluation runs
```

The first command uses the one test that needs no database, so that Maven fetches everything a test run needs, the JUnit
provider of Surefire included; with an empty cache, the offline run fails without it. The suite has six classes. `AccountTest`,
`BillingTest`, `BookingTest`, `ContentTest` and `StaticFilesTest` characterize what the system does.
`SecurityBehaviorTest` checks the reference's defense at each place of section 5. In the Default track those checks are part of
the contract a change must keep. In the Security track they are where its verifiers start. A test that would point at a
planted flaw leaves that instance's public suite, as LEB-300-A did (`removed-from-public-tests.txt`).

Three defects were planted by hand in a copy of the reference, to check that the suite sees them. Each one made its test fail:
the area lock removed (three bookings of one slot, in three runs out of three), a notice shown without escaping, and the budget
split rounding each share.

## 9. Authoring status

- [x] Specification (this page)
- [x] Reference: platform (configuration, database, HTTP, sessions, CSRF, throttle, passwords)
- [x] Reference: billing, payments, statements and webhook
- [x] Reference: bookings, notices, documents, archive, reports, mobile API
- [x] Characterization tests (70, all passing) and `manifest.md`
- [x] Evaluator scripts: build, database, characterization and verification (`harness/java/`)
- [x] The Default track's task statement, in English like the system: [`protocol/tasks/TASK-D-1.0.0.md`](../../protocol/tasks/TASK-D-1.0.0.md)
      (design §4.1). It asks for no security work, and the packager writes it into the package as `TASK.md`
- [x] The Security track's task statement, in English like the system: [`protocol/tasks/TASK-S-1.0.0.md`](../../protocol/tasks/TASK-S-1.0.0.md)
      (design §4.2), with its one-message file `messages-S-1.0.0.json` and the track's weights in `scoring/tracks/security.json` (a draft). The packager writes it into the package as `TASK.md`
- [x] The evaluator of the new series (design §12 step 2): one charge per regression, blind judging with human review of the
      disagreements, and each track's weights. Specified in [`docs/new-series-evaluator.md`](../../docs/new-series-evaluator.md)
      (`ACTIVE`: the owner confirmed its section 10 on 2026-10-14); built in the order of its section 9:
  - [x] the specification
  - [x] the failed test names in the mechanical report, and the regression groups of this system
  - [x] the scorer (`harness/score_v2.py`), the Default track's weights (`scoring/tracks/default.json`), the scorecard v2 schema and
        the verdict's `regressions` and `review` fields; `./leb scorecard` picks it for `LEB-D-*` and `LEB-S-*`
  - [x] two judges per delivery, the disagreement list and the review command (`tools/judge_review.py`, `scoring/JUDGE.md`)
  - [x] the tests that prove it: the one-charge cases, the arithmetic of this system's matrix, and the real evaluator's reports of the planted tree
        (100 with a judge that found nothing) and of the reference (1000 with a perfect verdict) kept as fixtures
- [x] `LEB-D-300-A`: the catalog of its profile ([`private/CATALOG.md`](../../instances/LEB-D-300-A/private/CATALOG.md))
- [x] The four corrections to this reference that the catalog's review found (its §9), made before either instance is planted
- [x] `LEB-D-300-A`: the planting modules ([`private/plant/`](../../instances/LEB-D-300-A/private/plant/)) and the planted tree
      (`public/code/`, `public/manifest.md`), on which the public suite passes
- [x] `LEB-D-300-A`: the verifiers (`private/verify/`), checked in both directions on the planted tree and on the reference
- [x] `LEB-D-300-A`: the matrix (`private/matrix_rows.py`, written out by `tools/make_matrix.py`)
- [x] `LEB-D-300-A`: the variant cases (`private/variants/`, run by `tools/variants.py`)
- [x] `LEB-D-300-A`: its runner contract (`private/runner.json`): `harness/leb_harness.py` characterizes and verifies a delivery
      end to end
- [x] `LEB-D-300-A`: its package, built clean by `./leb pacote` (`code/` with the environment notes, `manifest.md`, `TASK.md`)
- [x] `LEB-D-300-A`: its offline Maven cache. CondoDesk resolves to the **same 213 files** (identical SHA-256 list) as the cache the bench VMs
      already hold from the LEB-300-A pilot, so the VMs need no new cache. Rebuilt and proved on the workstation on 2026-10-14: `mvn -o clean verify` runs
      the 70 public tests in 58 s on a network with no route out, and an empty cache fails
- [x] the bench snapshot of the **environment**, `leb-d-300-a`, on the four bench VMs (2026-10-14): Claude Code 2.1.295, the test database and the offline Maven
      cache, no package, taken after `limpar-vm.sh` (the credentials untouched, hashed before and after) with every VM passing `conferir-vm.sh` with no `FAIL`;
      the lab's `snapshot` command refuses to shut a VM down unless it passes that check
- [x] `LEB-D-300-A`: the proof on a bench VM, on a throwaway copy of the package (`docs/candidate-vm.md` §5, step 4), on bench1 (2026-10-14): the package's 56 files
      match their hash list, `check-environment.sh` says `environment ready`, and `mvn -o test` passes **70 tests, 0 failures**, in **345 s** (the workstation takes 58 s).
      The copy and the tarball are removed, the tests leave no schema in `leb_dev`, and the VM still passes `conferir-vm.sh`. An agent that runs the whole suite
      spends about six minutes of its budget each time
- [x] `LEB-S-300-A`: the catalog of its profile ([`private/CATALOG.md`](../../instances/LEB-S-300-A/private/CATALOG.md)): 24 planted vulnerabilities in 19 classes and 6 decoys (2026-10-25)
- [x] `LEB-S-300-A`: the planting modules (`private/plant/`) and the planted tree (`public/code/`, `public/manifest.md`), on which the public suite passes (51 tests, after the trims
      of `private/removed-from-public-tests.txt`), with the two corrections that belong to this instance only (CORR-S1, CORR-S2); each row proved both ways
- [x] `LEB-S-300-A`: the exploit verifiers (`private/verify/`), the matrix (`private/matrix_rows.py`, written out by `tools/make_matrix.py`: 200 base points) and its regression groups
- [x] `LEB-S-300-A`: the variant cases (`private/variants/`, run by `tools/variants.py`): 69, all with the expected verdict
- [x] `LEB-S-300-A`: its runner contract (`private/runner.json`) and its package, built clean by `./leb pacote` (56 files: `code/`, `manifest.md`, `TASK.md`)
- [ ] `LEB-S-300-A`: the owner's review of the catalog (five points are still proposals: [`instances/LEB-S-300-A/README.md`](../../instances/LEB-S-300-A/README.md#where-it-stands))
- [ ] `LEB-S-300-A`: the review of the key, before the freeze: two independent reviewers read it on 2026-10-29 (the owner: two internal reviews suffice) and the findings of their first group are fixed in
      0.2.280 to 0.2.282; the rest is listed in [`instances/LEB-S-300-A/README.md`](../../instances/LEB-S-300-A/README.md#review-of-2026-10-29), and the whole control set runs again before the freeze
- [ ] `LEB-S-300-A`: the proof on a bench VM, on a throwaway copy of the package (`docs/candidate-vm.md` §5, step 4); the bench snapshot of the environment, `leb-d-300-a`, serves this
      instance as it is (same system). Done on BENCH4 on 2026-10-29 for the 0.2.279 package by `operador/provar-pacote.sh` (57 files, 51 tests offline, nothing left behind); repeated on the frozen package
- [ ] `LEB-S-300-A` **frozen**: its `matrix_sha256` and `package_sha256` recorded, the whole instance committed and pushed
- [x] `LEB-D-300-A` **frozen on 2026-10-14**, at commit `73eb0f8` (0.2.214; nothing of the instance, harness, scoring or tools has changed since `2d19483`, 0.2.211):
      `matrix_sha256` `45c09ae1fce8f91cef5f7070db5a74d957d787255f02094a8adf39a89fd294b9` and `package_sha256`
      `71dfeb7c3f82c75a96f3134e4e050b4476436116e14402d483e9c5f5510955e4` (`./leb pacote LEB-D-300-A` rebuilds both). The first case is Claude Haiku 5.5 at
      effort `high`, 90 minutes, five runs, two blind judges per delivery. From the first run on, any change is a new version of the instance
- [x] `LEB-D-300-A` **version 1.1, 2026-10-15**: the verifiers hardened after an external review of the key, made after the first deliveries were produced and before
      any of them went through the verifiers (what changed and why: [`instances/LEB-D-300-A/README.md`](../../instances/LEB-D-300-A/README.md#versions)).
      `matrix_sha256` `9bd0c169e420927d8e0aadd87f30789b4cf6e60a2db49999c450c7f86f2207ce` and `package_sha256`
      `3370984d863313e524b7e976eca416d043489fa8f67858e70dabd5998f4dba4e` (`./leb pacote LEB-D-300-A` rebuilds both). The package differs from 1.0 in four lines of
      `TASK.md` (the instance version and the matrix hash, each twice) and nothing else. The harness, the scorer and the review tools changed between the two freezes
      (0.2.216 to 0.2.220: the Maven failure rule, regression by test name, the scorer's input checks, review decisions bound to the verdicts); the matrix changed
      only in its version and in the note of the BUG-013.b decoy, and the planted tree and the public tests did not change. All 83 control cases give their expected verdict, the planted tree has 0 of 27 rows fixed and the reference 27 of 27
- [x] `LEB-D-300-A` **first case, 2026-10-15**: Claude Haiku 5.5 at effort `high`, 90 minutes, five scheduled runs (a sixth attempt, the second, was a lab failure and was rerun).
      All five completed on the 1.0 package and were evaluated with the 1.1 key, judged blind by two judges each, and a person settled the disagreements. Published as an aggregate
      only ([`results/2026/LEB-D-300-A/aggregate.json`](../../results/2026/LEB-D-300-A/aggregate.json)): mean **547**, 95% interval 530 to 565, runs 524 to 580, all Bronze

An instance's first run needs every item above, its package built clean by `./leb pacote`, the whole instance (key included)
committed and pushed ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1 and 2), the decisions in
[`docs/new-series-evaluator.md`](../../docs/new-series-evaluator.md) section 10 (confirmed by the owner on 2026-10-14), and a bench snapshot that holds its **environment** (the
toolchain, the test database, the offline Maven cache): the lab puts the package in at each case.
