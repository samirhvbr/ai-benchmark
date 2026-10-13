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
2. `manifest.md`: the public contract both instances ship.
3. Per instance, a planting profile: exact-match edits applied to a copy of the reference, which yield the legacy tree and
   `public/code/`.
4. Per instance, the matrix generated from the legacy tree, the verifiers, and the variant cases that check each verifier in
   both directions (a right fix is accepted, a partial or wrong one is rejected).
5. The evaluator's scripts (build, characterization, verification, a disposable database), public and shared by every Java
   system of the series, in [`harness/java/`](../../harness/java/). The first two are there: `mvn.sh` runs Maven offline in a
   pinned container, and `dev-db.sh` keeps a disposable PostgreSQL on an internal network, with names of its own so that it
   never touches another evaluation.

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
- [ ] Evaluator scripts (build and database done; characterization and verification to come)
- [ ] The task statement of each track (design §4.1 and §4.2). `protocol/TAREFA.md` and its versions ask one delivery for
      security and maintenance together; the Default statement must not ask for security work, and neither statement exists
- [ ] The evaluator of the new series (design §12 step 2): one charge per regression, blind judging with human review of the
      disagreements, and each track's weights
- [x] `LEB-D-300-A`: the catalog of its profile ([`private/CATALOG.md`](../../instances/LEB-D-300-A/private/CATALOG.md))
- [x] The four corrections to this reference that the catalog's review found (its §9), made before either instance is planted
- [x] `LEB-D-300-A`: the planting modules ([`private/plant/`](../../instances/LEB-D-300-A/private/plant/)) and the planted tree
      (`public/code/`, `public/manifest.md`), on which the public suite passes
- [ ] `LEB-D-300-A`: the matrix, verifiers, variant cases
- [ ] `LEB-S-300-A`: profile, matrix, exploit verifiers, variant cases

An instance's first run needs every item above, its package built clean by `./leb pacote`, the whole instance (key included)
committed and pushed ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1 and 2), and a bench snapshot that holds its
package.
