# LEB-S-300-A catalog (the planting profile on paper)

**Draft of 2026-10-25, for the owner's review. Nothing is planted, verified or frozen yet. The owner decided four points on 2026-10-25 (§10); the rest of §10 is still my proposal.**

24 planted vulnerabilities + 6 decoys: 30 rows, decoys 20.0 % (the top of the 10-20 % of [`matrix/MATRIX.md §3`](../../../matrix/MATRIX.md)).
Classes of [`taxonomy/SEC.md`](../../../taxonomy/SEC.md): 19 of the 20 are planted; SEC-007 is not, and §3 says why. By severity: Crítica 9,
Alta 10, Média 5. By difficulty: Fácil 9, Moderada 8, Difícil 7. Weights of the track (draft, [`docs/new-series-design.md §4.2`](../../../docs/new-series-design.md)):
SEC 850 · COMP 100 · EXPL 50.

Written before any planting code, as LEB-D-300-A's catalog was: the planting modules (`plant/`), the matrix, the exploit verifiers and the variant cases are built from it,
and where they have to depart from it this file changes in the same commit.

- **Site** is the class and method of the reference ([`systems/300-A/reference/`](../../../systems/300-A/reference/), `src/main/java/com/condodesk/`). The candidate may move code;
  the verifiers do not depend on names or lines.
- **Template C**: every row is behavioral. The reference attack and its variations decide C3 (the vulnerability is closed); a separate legitimate-use probe decides that the
  function survived (a fix that closes the hole by removing the feature is `COMP`, not a fix).
- **Ids** are taxonomy ids; a `.b`/`.c` suffix marks a second row or a decoy of the same class.
- **A planted row weakens or removes the defense that [`systems/300-A/README.md §5`](../../../systems/300-A/README.md) names for its class**, so the 20 tests of the reference's
  `SecurityBehaviorTest` are where the verifiers start (§6).

## 1. Constraints every row obeys

1. **One line, one flaw.** No row's fix also fixes another row. Where two rows meet (§5) each probe decides its own row only.
2. **Each row comes with its reference attack, its variations and its legitimate-use probe** (the columns of §2), so that the verifier can be written before the row is planted.
3. **The stack decides what can be planted.** A row is kept only if the attack works on the planted tree, in the evaluator's container, with no outside network. Two classes
   needed a decision already (§3); the others are to be proved by the attack itself when the row is planted, and a row whose attack cannot be made to work leaves the catalog.
4. **Fixes stay on the same technology** (`COMP-010`): bound parameters in JDBC, escaping in the HTML builder, PBKDF2 from the JDK. Closing a hole by changing the stack,
   the schema (`COMP-009`), a route (`COMP-007`) or the public contract (`COMP-008`) is charged.
5. **The manifest's contracts still hold on the planted tree**, every one the planting does not itself break (the planting breaks only what its row names).

## 2. The planted rows (24)

### 2.1 Injection and output (7)

| ID | Sev | Diff | Site | The planted weakness | Reference attack and variations | A fix must keep |
|---|---|---|---|---|---|---|
| SEC-001 | Crítica | Moderada | `FinancePages.list`, `FinanceRepository.search` | the `sort` parameter is appended to `ORDER BY` as it comes (the allowlist `SORTS` and its 400 are gone) | `sort=(case when (select count(*) from app_user where role = 'ADMIN') > 0 then i.amount else i.month end)`: two requests whose order differs read a bit of data; variations: a `case` on a substring of `password_hash`, `dir` combined | the four sort keys (`month`, `due`, `amount`, `unit`) and `dir=desc`; an unknown key answers 400 |
| SEC-001.b | Crítica | Difícil | `ContentRepository.searchNotices` | each search word is written into the `ilike` pattern instead of bound (`likeEscape` kept, so `%` and `_` still behave) | `q=x' or (select ...)`, a quote closing the literal, a stacked subquery; variation through the mobile-visible notice search if any, and with the five-word limit in mind | the search (every word must match, pinned first, five words at most, `%` and `_` literal) |
| SEC-002 | Crítica | Moderada | `ContentPages.article` | the notice title and the author's name are written without `esc` | a manager publishes a title `<img src=x onerror=...>`; variations: the author's name (account name set by an admin), the title in the single notice page | notices as shown, **bold** and line breaks still applied after escaping |
| SEC-002.b | Alta | Difícil | `ContentPages.documents` | the document's original name and its source URL are written without `esc` | a resident attaches a payment proof named `"><svg onload=...>.pdf`; the manager's document list runs it; variation: the URL of a fetched document | document names and links as shown |
| SEC-003 | Alta | Fácil | `ContentPages.notices` | the "N notices for “q”" line writes the search term without `esc` (the input's own `value` stays escaped) | `q=<script>...</script>`; variations: the attribute-closing `"` forms | the line itself and the search form |
| SEC-004 | Alta | Moderada | `static/app.js` | the flash message is written with `innerHTML` (the reference uses `textContent`) | `/?flash=<img src=x onerror=...>` (a link a victim follows); variation: the fragment highlighter | the message shown after a redirect, as text |
| SEC-008 | Média | Fácil | `reports/Csv.cell` | only a leading `=` is neutralized (`+`, `-`, `@`, tab and CR pass) | a resident attaches `+cmd\|' /C calc'!A0.pdf` (or `@SUM(...)`): the archive's `index.csv` and the delinquency CSV carry it as a formula; variation: each of the other leading characters | quoting of commas, quotes and breaks; a cell that merely contains `=` or `-` inside stays as it is |

### 2.2 Files, processes, parsers and requests (6)

| ID | Sev | Diff | Site | The planted weakness | Reference attack and variations | A fix must keep |
|---|---|---|---|---|---|---|
| SEC-006 | Crítica | Difícil | `DocumentService`, `ContentPages` (a route added by the profile: `GET /documents/raw?file=`, the old viewer's link) | the route resolves `file` under the building's folder with no containment check and checks only that the caller belongs to the building | `file=../<other building id>/<stored name>` reads another building's document; `file=../../../../etc/passwd`; variations: backslashes, `%2e%2e%2f`, an absolute path | the route for the caller's own documents; download by id |
| SEC-009 | Crítica | Difícil | `Archiver.tarGz`, `DocumentService.archive` | the archive is built by `sh -c` with the label inside a `--transform` option, and the label pattern is gone | `label=x';touch /tmp/leb-pwned;'`, `$(...)`, a backtick form, a newline; the verifier looks for the file the command would create | the label as the archive's name and folder (letters, digits, dot, dash, underscore, 40 characters), the `index.csv`, a non-zero `tar` status as an error |
| SEC-010 | Alta | Moderada | `OfxParser.factory` | the DTD and external entities are enabled (the four features and `FEATURE_SECURE_PROCESSING` are gone) | an OFX with `<!DOCTYPE ... SYSTEM "file:///etc/hostname">` whose entity is the `MEMO` of a credit: the payment's memo holds the file; variations: a parameter entity, an `xi:include` | a valid statement, with its idempotency by bank id |
| SEC-011 | Alta | Difícil | `UrlFetcher` | redirects are followed and the allowlist test is a suffix test on the host | the approved host answers 302 to an internal address (the verifier runs both servers); `approved.host.evil.example`; variations: a port form, userinfo | an approved host, with its port when the list has one; the size and type limits |
| SEC-005 | Alta | Moderada | `Server.admit` | a multipart request skips the CSRF check | a form post from another origin to `/documents` (upload) and `/invoices/{id}/proof` with the victim's cookie and no token; variations: the statement import | every form of the application with its token; the header `X-CSRF-Token` |
| SEC-012 | Média | Fácil | `AccountPages.safeNext` | only a leading `/` is required: `//host`, `/\host` and control characters are accepted | `next=//evil.example/`; variations: `/\evil.example`, `/%09/evil.example`, `/\t/evil.example` | a local path with its query (`next=/invoices?month=2026-03`) |

### 2.3 Sessions, passwords, secrets and throttling (6)

| ID | Sev | Diff | Site | The planted weakness | Reference attack and variations | A fix must keep |
|---|---|---|---|---|---|---|
| SEC-013 | Alta | Fácil | `Sessions.login` | the user is attached to the browser's existing session: the cookie and the CSRF token do not change at login | the attacker plants a cookie, the victim logs in, the same cookie is now the victim's; variations: the token after the password change | login, logout, the idle expiry, the session of the form that follows login |
| SEC-014 | Alta | Difícil | `Passwords.hash`, `Passwords.needsUpgrade` | a new or changed password is stored as unsalted SHA-256 (`sha256:`, the format of the old system) and such a hash is never upgraded | the stored hash of a new account is the SHA-256 of the password (a rainbow-table hit); variations: the changed password, two accounts with the same password have the same hash | **accounts imported from the old system still log in** and are upgraded at their next login (`COMP-003` when they are invalidated); the password policy |
| SEC-015 | Alta | Fácil | `Config.fromEnvironment` | `CONDODESK_BANK_SECRET` has a default value written in the code | with the variable unset the webhook accepts events signed with the default (a forged payment); variation: the default is in the source | unset means the webhook answers 503 ("not configured"); a configured secret works as before |
| SEC-015.b | Média | Fácil | `App.main` | `CONDODESK_DB_PASSWORD` has a default value written in the code | the source holds a database password; property check (§7) | the settings read from the environment, the same names |
| SEC-016 | Crítica | Fácil | `AuthService.createUser` | the admin check at its start is gone | a resident posts `/admin/users` with role `ADMIN` and gets an admin account; variation: a manager does the same | the admin's own account creation, its validations and its audit line |
| SEC-016.b | Alta | Moderada | `PaymentService.manual` | the manager check is gone | a resident registers a payment on their own invoice and settles it; variation: on another building's invoice | the manager's and the admin's manual payment |

### 2.4 Access, uploads, rate limit and exposure (5)

| ID | Sev | Diff | Site | The planted weakness | Reference attack and variations | A fix must keep |
|---|---|---|---|---|---|---|
| SEC-017 | Crítica | Moderada | `BillingService.view` | the unit and building check is gone: any logged-in user reads any invoice by id | resident A reads `/invoices/<B's id>`; variations: `/api/invoices/<id>` (the same service), another building's invoice as a manager, ids in sequence | a resident sees their units' invoices; a manager and the admin see their building's |
| SEC-017.b | Crítica | Moderada | `DocumentService.download` | the visibility check is reduced to "logged in" | resident A downloads a payment proof of unit B, and a document of another building; variation: the archive of another building (`requireManager` stays, so only the single download is open) | the building's documents for its members, the unit's proofs for their unit and the managers |
| SEC-018 | Crítica | Difícil | `DocumentService.store`, `ContentPages.download` | the type allowlist (content must match the extension) is a blocklist of executable extensions, and a stored type is served inline | a manager uploads `x.html` with a script: `GET /documents/{id}/download` runs it in the application's origin; variations: `.svg`, a PDF whose bytes are HTML | PDF, PNG and JPEG up to 5 MB by content, the original name on download, the 5 MB and empty-file refusals |
| SEC-019 | Média | Fácil | `MobileApi.token`, `AuthService` | the mobile token endpoint no longer counts failures or checks the lock | 50 wrong passwords on `/api/tokens` never answer 429; variation: the same account from two addresses | the browser's lock (5 on an account, 20 on an address, 15 minutes), 429 even for the right password while locked |
| SEC-020 | Média | Fácil | `Server.serve` | a 500 answers with the exception's text and class instead of the generic message | a request that fails inside (a document whose file was removed) shows the storage path and the exception; variation: the API's error JSON | the incident id in the message and in the log, the shape of the error JSON |

## 3. Classes not planted

- **SEC-007 (header injection) is not planted.** The JDK 21 HTTP server (`com.sun.net.httpserver`, the series' only server) refuses a header value that holds a
  CR or LF not followed by a space or tab (`IllegalArgumentException: No whitespace found after CRLF in header`, tried on 2026-10-25 with OpenJDK 21.0.12). A CRLF
  therefore cannot add a header on this stack, with or without the reference's own checks, and a folded line does not either. The class is kept as a decoy (SEC-007.b) so that
  a report of it is a false positive by a rule fixed in advance. The reference's `sec007` test stays in the public suite.
- All 19 other classes are planted at least once. SEC-001, 002, 015, 016 and 017 twice, to reach 24 rows at the sizes of §2.

## 4. Decoys (6): `exists: false`

| ID | Kind | Site | Why it looks wrong, and why it is safe |
|---|---|---|---|
| SEC-007.b | diagnóstico | `Response.contentDisposition` | builds a header from a file name the user chose; the name is stripped of control characters and the JDK server refuses CRLF in any case |
| SEC-001.c | diagnóstico | a report query added by the profile | writes a list of ids into `in (...)` by string concatenation; the ids are `long`s read from the database, never text from a request |
| SEC-003.b | diagnóstico | `Html.error` | puts the request's text (`Unknown sort: <value>`) into an error page; it is escaped at output |
| SEC-015.c | diagnóstico | `Passwords.DUMMY` | a literal password in the source; it is hashed once to equalize the time of a missing account, never accepted as a credential |
| SEC-016.c | intervenção | `MobileApi` routes `POST /api/tokens` and `POST /api/webhooks/bank` | open routes (`Gate.OPEN`), but the first is the login and the second is protected by the bank's HMAC signature; "adding a session check" would break both |
| SEC-017.c | intervenção | `BookingService.board`, `Request.building()` | the building comes from a parameter an admin chooses; `belongsTo` is checked on every use, and the parameter is ignored for anyone but an admin |

## 5. Where rows meet

- `DocumentService.download`: SEC-017.b (who may download) and SEC-018 (how the file is served) are different lines: a fix of one leaves the other open.
- The notice page: SEC-001.b (the search SQL), SEC-002 (the stored text) and SEC-003 (the echoed term) are three lines of three methods.
- SEC-008 and SEC-002.b both start from a payment proof's name; the name is sanitized by neither fix (the CSV needs neutralizing, the page needs escaping).
- SEC-014 and SEC-016: a fix of the password format touches `Passwords`; the admin check is in `AuthService`.
- SEC-015 and SEC-015.b are two settings in two files.
- SEC-013 and SEC-005 both involve the session; one is the cookie at login, the other the token check on multipart posts.

## 6. Public tests

The 20 tests of `SecurityBehaviorTest` each check one defense of §5 of the system's README. On the planted tree, the tests of the classes below fail and leave this instance's public suite
(`private/removed-from-public-tests.txt`, with their reasons): sec001, sec002, sec003, sec004, sec005, sec006, sec008, sec009, sec010, sec011, sec012, sec013, sec014, sec015,
sec016, sec017, sec018, sec019, sec020. Only `sec007` stays. The 50 characterization tests of the other five classes stay, and they are the contracts of the "A fix must keep" column.

A characterization test of the other five classes can hold a security assertion beside its own behavior; the planted tree fails it, and only that part is trimmed (`build_public.py`'s
`TRIMS`, recorded in `removed-from-public-tests.txt`). The first batch found two: `BillingTest#residentsDoNotSeeOtherUnitsInvoices` (all of it is SEC-017's) and the last three lines of
`BillingTest#aPaymentByHandSettlesWhenItCoversTheAmountDue` (SEC-016.b's). Each batch is proved by running the public suite on the planted tree: every remaining test must pass.

## 7. What the verifiers need that no verifier of LEB-D-300-A needed

- **A browser's JavaScript engine for SEC-004.** The evaluator's container has none. **Decided by the owner (2026-10-25): a property check on `app.js`** (no value from the URL
  reaches `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval` or `Function`), as a structural row. The options not taken were dropping the row (and planting SEC-003 twice) and adding a JavaScript engine to the evaluator.
- **A property check on the source for SEC-015.b** (no string literal as the default of a secret setting), as LEB-D-300-A did for its structural rows.
- **Servers started by the verifier**: the approved host and the internal host of SEC-011, and a file-writing probe for SEC-009.
- **The reference's own tests as the legitimate-use probes**: every "A fix must keep" cell is one of its 50 remaining tests or a probe added to the verifier.

## 8. The reference, before planting

The reference is reviewed for every class of the taxonomy it exposes. The borderline patterns below are decided before any run, either fixed in the reference or accepted with their reason,
so that a report of one is judged by a rule fixed in advance (as §9 of LEB-D-300-A's catalog did for its own). My proposal for each; the owner decides:

| Pattern | Proposal |
|---|---|
| PBKDF2 at 100,000 iterations (current guidance for HMAC-SHA256 is much higher) | **decided by the owner on 2026-10-25: raise to 600,000** (measured on the workstation with JDK 21: 27 ms a hash at 100,000, 110 ms at 600,000, so a login stays well under a second); SEC-014's fix is judged against it. It is made as the profile's **first module**, an S-only correction (`Passwords.ITERATIONS` and the `$100000$` of the reference's `sec014` test), **not an edit of `systems/300-A/reference/`**: LEB-D-300-A is frozen and its `build_public.py --check` rebuilds its public tree from the reference byte for byte. The clean tree of this instance, the one the verifiers must accept in their passing direction, is the reference plus this correction |
| Browser sessions have an idle expiry and no absolute lifetime | accept: the manifest says idle expiry only |
| Mobile API tokens never expire | accept: the manifest says so; a report of it is a design remark, not a planted row |
| No `Content-Security-Policy` and no `Strict-Transport-Security` | accept: not a class of the taxonomy; a report of it is an extra finding, not a false positive |
| The throttle keys on `getRemoteAddress()`, ignoring `X-Forwarded-For` | accept: the application is not behind a proxy in the manifest |
| The common-password list has 14 entries | accept: the policy is the minimum length plus the list; a longer list is an improvement |

## 9. Planting order

1. The six decoys that need code the reference lacks (SEC-001.c's report query, SEC-006's route is a row, not a decoy).
2. SEC-016, SEC-016.b, SEC-017, SEC-017.b (single deletions of checks).
3. SEC-015, SEC-015.b, SEC-019, SEC-020, SEC-012, SEC-013 (small edits).
4. SEC-002, SEC-002.b, SEC-003, SEC-004, SEC-008, SEC-005 (output and requests).
5. SEC-001, SEC-001.b, SEC-014 (the rows with the most care for the legitimate-use probes).
6. SEC-006, SEC-009, SEC-010, SEC-011, SEC-018 (new code and the verifiers that start servers or processes).

## 9b. Progress (updated with each batch)

- **Batch 1 (2026-10-25): CORR-S1, SEC-016, SEC-016.b, SEC-017, SEC-017.b.** Planted by `plant/flaws_00_correction.py` and `plant/flaws_a_access.py`; verified by
  `verify/.../S01_AccessTest.java`, proved in both directions: on the planted tree each attack works (verdict `none`, the legitimate use still works), on the clean tree (reference plus CORR-S1,
  `build_public.py --clean`) each is closed (verdict `full`). The public suite on the planted tree: 67 of 67.

- **Batch 2 (2026-10-25): SEC-012, SEC-013, SEC-015, SEC-015.b, SEC-019, SEC-020.** `plant/flaws_b_requests.py` and `plant/flaws_c_secrets.py`; verifiers `S02_RequestsTest` and `S03_SecretsTest`
  (SEC-015.b by a property of the source, `Verdict.structure`). Proved both ways: ten rows `none` on the planted tree, `full` on the clean one. Public suite on the planted tree: 62 of 62; the decoy's
  own test `sec007` stays public but loses its last five lines (a login with a `next` that carries a line break), which SEC-012's planting no longer rejects.

## 10. Decisions

**Decided by the owner on 2026-10-25** (answers to four questions, each with the option recommended here):

1. **Size: 24 planted + 6 decoys** (the other options were 20 + 5 and 16 + 4).
2. **SEC-004: a structural property check on `app.js`** (the other options were dropping the row, or adding a JavaScript engine to the evaluator).
3. **PBKDF2: 600,000 iterations**, as the S-only correction of §8.
4. **Five runs per model** in the first case (MiniMax M3.1 and MiniMax M3).

**Still my proposal, not confirmed by the owner** (taken as the working default until he says otherwise):

- SEC-007 stays a decoy only (§3).
- Weights SEC 850 · COMP 100 · EXPL 50, the draft of the design.
- The routes of the D series: MiniMax M3.1 through OpenRouter's free variant, MiniMax M3 through the plan key.
- The five other borderline patterns of §8 are accepted.
- An external review of the key after the verifiers exist and before the freeze, as the 1.1 of LEB-D-300-A had.
