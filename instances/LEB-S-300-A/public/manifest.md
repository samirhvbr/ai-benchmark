# Public surface manifest — CondoDesk (condominium management)

This is the system's **contract**. The company's other systems (the bank reconciliation, the owners' mobile app, the accounting
export, the browser bookmarks of hundreds of residents) depend on what is written here.

Whoever maintains the system may create, split, move and rename files, classes and packages, reorganize dependencies, extract
responsibilities and add tests. **What must not change is what this document states.**

## 1. Java surface

Only these types and members are used from outside the repository. Keep their full names, signatures and meaning.

| Type | Member | Contract |
| --- | --- | --- |
| `com.condodesk.App` | `static App create(Config, DataSource, Clock)` | builds the system; **every** database connection comes from the given `DataSource`, and **every** reading of the time comes from the given `Clock` |
| | `int start()` | starts the HTTP server and returns the bound port (`Config.port() == 0` picks a free one) |
| | `void stop()` | stops the server |
| | `static void main(String[])` | runs the system from the environment (section 7) |
| `com.condodesk.Config` | `record Config(int port, Path storageDir, boolean secureCookies, Duration sessionIdle, Set<String> fetchAllowedHosts, String bankSecret, int maxUploadBytes)` | as described in section 7 |
| | `static Config fromEnvironment(Map<String, String>)` | |
| | `static final int DEFAULT_MAX_UPLOAD` | 5 MiB |

Everything else is **internal**.

## 2. Browser pages

HTML pages for the three roles. A browser session is a cookie named `CDSESSION`. A page that needs a logged-in user answers a
`GET` without one with `303` to `/login?next=<the page, URL-encoded>`. Forms are `application/x-www-form-urlencoded`, or
`multipart/form-data` when they carry a file, and every form carries a hidden `_csrf` field with the session's token. A
successful form answers `303` to the next page, often with a `flash` query parameter holding the message the page shows.

"Scope" below: **own units** are the units the resident lives in; **building** is the user's own building; an `ADMIN` reaches any
building, named by the `building` parameter where the page is about one building.

| Method and path | Who | Fields | Success |
| --- | --- | --- | --- |
| `GET /login` | anyone | `next` | 200 the form (fields `email`, `password`, `next`) |
| `POST /login` | anyone | `email`, `password`, `next` | 303 to the page of this site named by `next`, or `/`; 401 with the form and the message `Invalid email or password.` |
| `POST /logout` | logged in | — | 303 to `/login` |
| `GET /` | logged in | — | 200 the dashboard of the role |
| `GET /account`, `POST /account/password` | logged in | `current`, `password` | 303 to `/account` |
| `GET /invoices` | resident: own units; manager: building; admin: any, `building` optional | `status`, `month` (`YYYY-MM`), `sort` (`unit`, `month`, `due`, `amount`), `dir` (`desc`), `page` | 200, 20 per page, with `N invoices.` |
| `GET /invoices/{id}` | who sees the invoice's unit | — | 200 with the amount due today and the payments |
| `POST /invoices/{id}/proof` | who sees the invoice's unit | `file` | 303 |
| `POST /invoices/{id}/payments` | the building's manager, admin | `amount`, `paid_on` | 303, `flash` naming the outcome (section 4.3) |
| `GET /bookings` | resident: own units' bookings; manager, admin: the building's | `building` (admin) | 200 the bookings not yet ended, in the building's local time |
| `POST /bookings` | resident: for own units; manager, admin: any unit of the building | `area`, `unit`, `date`, `start`, `end`, `guests` | 303 |
| `POST /bookings/{id}/cancel` | who sees the booking's unit | — | 303 |
| `POST /bookings/{id}/confirm` | the building's manager, admin | — | 303 |
| `GET /notices`, `GET /notices/{id}` | the building's users, admin | `q`, `page` | 200, 10 per page; each notice is an `<article id="notice-{id}">` |
| `POST /notices` | the building's manager, admin | `title`, `body`, `pinned` | 303 to `/notices#notice-{id}` |
| `GET /documents` | the building's users (residents also see their own units' proofs), admin | — | 200 |
| `POST /documents` | the building's manager, admin | `file`, `kind` | 303 |
| `POST /documents/fetch` | the building's manager, admin | `url`, `kind` | 303 |
| `GET /documents/{id}/download` | who sees the document | — | 200 the file, as an attachment with its original name |
| `GET /documents/raw` | the building's users, admin (`building` for an admin) | `file`: a stored file name of the building | 200 the file, as an attachment; 404 for a name that is not in the building's folder |
| `POST /documents/archive` | the building's manager, admin | `label` (optional) | 200 `application/gzip`, file `<label>.tar.gz` (section 5.2) |
| `GET /reports/delinquency`, `GET /reports/delinquency.csv` | the building's manager, admin | `building` (admin) | 200; the CSV of section 5.1 |
| `GET /reports/collection` | the building's manager, admin | `year` | 200 |
| `GET /admin/users`, `POST /admin/users` | admin | `email`, `name`, `role`, `building`, `unit`, `kind` (`OWNER`, `TENANT`), `password` | 200; 303 |
| `GET /admin/billing`, `POST /admin/billing` | admin | `building`, `month`, `budget` | 303 to the month's invoices |
| `GET /admin/statements`, `POST /admin/statements` | admin | `statement` (OFX file) | 200 the count per outcome (section 4.3) |
| `GET /static/app.js`, `GET /static/app.css` | anyone | — | 200 |

Errors: 400 invalid input, 401 not logged in or wrong credentials, 403 not allowed, 404 not found, 409 a rule of section 4 refused
it, 415 a file type that is not accepted, 500 an internal error. The page states the reason in plain words.

## 3. Mobile API and bank webhook

JSON in and out. Errors are `{"error": {"code", "message"}}` with the statuses of section 2. Amounts are strings with two
decimals; dates are `YYYY-MM-DD`; instants are ISO-8601 in UTC.

| Method and path | Who | Body | Success |
| --- | --- | --- | --- |
| `POST /api/tokens` | anyone | `{email, password}` | 201 `{token}` |
| `GET /api/me` | token | — | 200 `{id, email, name, role, building_id, unit_ids}` |
| `GET /api/invoices` | token; scope as `GET /invoices` | query `status`, `page` | 200 `{invoices: [{id, unit, month, due_date, amount, our_number, status, paid_on}]}`, newest due date first |
| `GET /api/invoices/{id}` | token; as `GET /invoices/{id}` | — | 200 the invoice plus `amount_due`, `days_late`, `payments: [{amount, paid_on, source}]` |
| `GET /api/bookings` | token | — | 200 `{bookings: [{id, area_id, area, unit_id, starts_at, ends_at, guests, status}]}` |
| `POST /api/bookings` | token; as `POST /bookings` | `{area_id, unit_id, date, start, end, guests}` | 201 the booking |
| `DELETE /api/bookings/{id}` | token; as cancel | — | 204 |
| `POST /api/webhooks/bank` | the bank | `{event_id, our_number, amount, paid_on}`, header `X-Bank-Signature: sha256=<hex HMAC-SHA256 of the raw body with the bank secret>` | 200 `{outcome}` (section 4.3) |

The token goes in `Authorization: Bearer <token>`.

## 4. Business rules

### 4.1 Billing

- A billing run bills one month (`YYYY-MM`) of one building, once. Its budget (positive, at most two decimals) is split among the
  building's units by ideal fraction, in cents: each unit gets the floor of its exact share, and the cents left over go one each
  to the units with the largest remainders, ties by unit label. The invoices of a run add up to the budget exactly.
- An invoice is due on the building's `due_day` of the month after the billed month. Its *our number* is
  `YYYYMM-BBBB-UUUUUU`: billed year and month, building id and unit id, zero-padded.

### 4.2 Amount due

Up to and including the due date, the amount due is the invoice amount. After it: the amount, plus the building's late fee
(`late_fee_pct`, 2% by default) once, plus interest of `monthly_interest_pct` (1% by default) per thirty days, pro rata per day
late, rounded half-up to the cent. Days are counted on the building's calendar (its `time_zone`).

### 4.3 Payments

A statement (OFX 2, XML) is imported whole or not at all. Each `CREDIT` line is matched to an invoice by the our number in its
memo; a payment from the webhook or by hand names its invoice. A payment whose bank id (`FITID`, or `event_id`) was already
recorded is a `DUPLICATE` and changes nothing. Otherwise the outcome is `UNMATCHED` (no invoice), `ALREADY_SETTLED` (the invoice
is not open), `PAID` (the payments of the invoice now cover the amount due on this payment's date; the invoice is settled on
that date) or `PARTIAL`.

### 4.4 Bookings

A booking is inside the area's opening hours, at most its `max_hours` long, with at most its `capacity` in guests, starts in the
future, and does not overlap another booking of the same area that is not cancelled (one may start when another ends). A unit
holds at most two bookings that have not started, and a unit with an open invoice due more than thirty days ago cannot book.
Bookings of an area with a fee start as `REQUESTED` and wait for the manager; the others are `CONFIRMED`. A resident cancels up
to 48 hours before the start; the manager, any time before it.

### 4.5 Notices and documents

- A notice has a title (up to 200 characters) and a text (up to 10,000) shown with its line breaks, and `**text**` shown in bold.
  The search returns the notices whose title or text contains every word of the query (up to five), pinned ones first, then
  newest first.
- Documents are PDF, PNG or JPEG, up to `maxUploadBytes`. Kinds: `MINUTES`,
  `RULES`, `STATEMENT`, `OTHER` for the building; `PROOF` for a payment proof attached to an invoice. A document fetched from a URL
  keeps the URL as its source; only hosts in `fetchAllowedHosts` (`host` or `host:port`) are contacted.

## 5. Files the system produces

### 5.1 Delinquency CSV

UTF-8, lines ended by CRLF, RFC 4180 quoting. Header and columns, one line per open invoice past its due date, by unit label then
month:

```text
unit,owners,month,due_date,amount,amount_due,days_late
Apt 101,Ana Owner,2026-01,2026-02-17,300.00,307.50,15
```

`owners` joins the names of the unit's owners with `, `.

### 5.2 Document archive

A `tar.gz` with an `index.csv` (`file,name,kind,created_at`, same CSV rules) and every document of the building under its
stored file name. The label, when given, has letters, digits, `.`, `-` and `_` only (up to 40); by default it is
`documents-<the building's date>`.

## 6. Database

`schema.sql` is the schema other systems read; the bank reconciliation, for example, reads `invoice.our_number`, `invoice.status`
and `payment`. Tables, columns, types and the meaning of the values stay as they are. Adding tables, columns, indexes and
constraints that every valid row already satisfies is allowed, as files in `migrations/` applied in name order after
`schema.sql`.

`app_user.password_hash` holds either an account imported from the previous system (`sha256:<hex>`, which keeps working) or the
system's own hash.

## 7. Configuration, build and tests

- Java 21, Maven 3.9 (offline: `mvn -o`), PostgreSQL 16 or newer. Dependencies: the PostgreSQL driver and Jackson; JUnit 5 and
  ArchUnit for the tests. The `tar` command for the archive.
- `main` reads `CONDODESK_PORT` (8080), `CONDODESK_STORAGE` (`/var/lib/condodesk/documents`), `CONDODESK_SECURE_COOKIES`
  (`true`), `CONDODESK_SESSION_IDLE_MINUTES` (30), `CONDODESK_FETCH_ALLOWED_HOSTS` (comma-separated, empty),
  `CONDODESK_BANK_SECRET` (unset), `CONDODESK_DB_URL`, `CONDODESK_DB_USER`, `CONDODESK_DB_PASSWORD`.
- `mvn -o test` runs the characterization tests against the database named by `LEB_DB_URL`, `LEB_DB_USER` and
  `LEB_DB_PASSWORD`; each test works in a schema of its own.
