"""PERF rows: fixes written another way are accepted; partial and wrong ones are not."""

import os

REPORTS = "reports/ReportService.java"
REPORT_REPO = "repo/ReportRepository.java"
FINANCE_REPO = "repo/FinanceRepository.java"
FINANCE = "billing/FinanceService.java"
USERS = "repo/UserRepository.java"

with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public", "code", "schema.sql"), encoding="utf-8") as f:
    SCHEMA = f.read()

OWNERS_DOC = "    /** The owners' names of a unit, joined with a comma. */"
SEARCH_TAIL = '''        List<Invoice> all = Db.list(c, INVOICE + where + " order by " + order, FinanceRepository::invoice, args.toArray());
        int from = (int) Math.min((long) (page - 1) * size, all.size());
        List<Invoice> items = all.subList(from, Math.min(from + size, all.size()));
        return new Page<>(items, page, size, all.size());'''
NESTED = '''        List<Listed> out = new ArrayList<>();
        for (User u : found) {
            List<String> labels = new ArrayList<>();
            for (Residence r : residences) {
                if (r.userId() == u.id()) labels.add(r.label());
            }'''
WEBHOOK = "    public synchronized Outcome webhook(byte[] body, String signature) {"
SETTLE_HOOK = '        return db.tx(c -> settle(c, PaymentSource.WEBHOOK, "hook:" + eventId, ourNumber, amount, paidOn, null));'

CASES = [
    # ---- PERF-001: one query for the owners of the whole report
    dict(target="PERF-001", name="perf001-owners-at-once", kind="alternative", expect="fixed",
         edits=[(REPORT_REPO, OWNERS_DOC, '''    /** The owners' names of each unit, joined with a comma, in one query. */
    public java.util.Map<Long, String> ownersOfUnits(Connection c, java.util.Collection<Long> unitIds) throws SQLException {
        java.util.Map<Long, String> out = new java.util.HashMap<>();
        for (String[] r : Db.list(c, "select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r join app_user u on u.id = r.user_id "
                + "where r.unit_id = any(?) and r.kind = 'OWNER' group by r.unit_id", rs -> new String[] {rs.getString("unit_id"), rs.getString("names")},
                Db.ids(unitIds))) {
            out.put(Long.parseLong(r[0]), r[1]);
        }
        return out;
    }

''' + OWNERS_DOC),
                (REPORTS, "            List<Delinquent> rows = new ArrayList<>();",
                 "            java.util.Map<Long, String> owners = reports.ownersOfUnits(c, late.stream().map(Invoice::unitId).toList());\n"
                 "            List<Delinquent> rows = new ArrayList<>();"),
                (REPORTS, "reports.ownersOf(c, i.unitId())", 'owners.getOrDefault(i.unitId(), "")')]),
    dict(target="PERF-001", name="perf001-cache-per-request", kind="wrong", expect="not_fixed",
         edits=[(REPORTS, "reports.ownersOf(c, i.unitId())", "owner"),
                (REPORTS, "            List<Delinquent> rows = new ArrayList<>();",
                 "            java.util.Map<Long, String> owners = new java.util.HashMap<>();\n            List<Delinquent> rows = new ArrayList<>();"),
                (REPORTS, "                rows.add(new Delinquent(", '''                String owner = owners.get(i.unitId());
                if (owner == null) {
                    owner = reports.ownersOf(c, i.unitId());
                    owners.put(i.unitId(), owner);
                }
                rows.add(new Delinquent(''')],
         note="a cache keyed by unit still runs one query per distinct unit"),

    # ---- PERF-003: the page in the query
    dict(target="PERF-003", name="perf003-limit-offset-and-count", kind="alternative", expect="fixed",
         edits=[(FINANCE_REPO, SEARCH_TAIL, '''        long total = Db.one(c, "select count(*) as n from invoice i join unit u on u.id = i.unit_id" + where, rs -> rs.getLong("n"), args.toArray()).orElse(0L);
        args.add(size);
        args.add((long) (page - 1) * size);
        List<Invoice> items = Db.list(c, INVOICE + where + " order by " + order + " limit ? offset ?", FinanceRepository::invoice, args.toArray());
        return new Page<>(items, page, size, total);''')]),
    dict(target="PERF-003", name="perf003-limit-without-offset", kind="wrong", expect="not_fixed",
         edits=[(FINANCE_REPO, SEARCH_TAIL, '''        long total = Db.one(c, "select count(*) as n from invoice i join unit u on u.id = i.unit_id" + where, rs -> rs.getLong("n"), args.toArray()).orElse(0L);
        List<Invoice> items = Db.list(c, INVOICE + where + " order by " + order + " limit " + size, FinanceRepository::invoice, args.toArray());
        return new Page<>(items, page, size, total);''')],
         note="every page shows the first one"),

    # ---- PERF-010: the admin's totals of today
    dict(target="PERF-010", name="perf010-computed-per-request", kind="alternative", expect="fixed",
         edits=[(REPORTS, "        if (buildingTotals == null) buildingTotals = reports.buildingTotals(c);\n        return buildingTotals;",
                 "        return reports.buildingTotals(c);")]),
    dict(target="PERF-010", name="perf010-cache-that-expires", kind="wrong", expect="not_fixed",
         edits=[(REPORTS, "        if (buildingTotals == null) buildingTotals = reports.buildingTotals(c);",
                 "        if (buildingTotals == null || totalsAt.plusSeconds(60).isBefore(clock.instant())) {\n"
                 "            buildingTotals = reports.buildingTotals(c);\n            totalsAt = clock.instant();\n        }"),
                (REPORTS, "    private List<BuildingTotals> buildingTotals;",
                 "    private List<BuildingTotals> buildingTotals;\n    private java.time.Instant totalsAt;")],
         note="right a minute later, wrong until then"),

    # ---- PERF-006: the payment's invoice indexed, through a migration
    dict(target="PERF-006", name="perf006-migration", kind="alternative", expect="fixed",
         files={"migrations/001_payment_invoice.sql": "create index payment_invoice on payment (invoice_id);\n"}),
    dict(target="PERF-006", name="perf006-composite-index", kind="alternative", expect="fixed",
         files={"migrations/001_payment_invoice_paid.sql": "create index payment_invoice_paid on payment (invoice_id, paid_on);\n"}),
    dict(target="PERF-006", name="perf006-index-not-leading", kind="wrong", expect="not_fixed",
         files={"migrations/001_payment_paid_invoice.sql": "create index payment_paid_invoice on payment (paid_on, invoice_id);\n"}),
    dict(target="PERF-006", name="perf006-schema-file-edited", kind="wrong", expect="not_fixed",
         files={"schema.sql": SCHEMA + "create index payment_invoice on payment (invoice_id);\n"},
         note="the schema file is the contract; the evaluator applies the instance's own, and only migrations count"),

    # ---- PERF-012: the user list in a straight line
    dict(target="PERF-012", name="perf012-residencies-by-user", kind="alternative", expect="fixed",
         edits=[(USERS, NESTED, '''        java.util.Map<Long, List<String>> byUser = new java.util.HashMap<>();
        for (Residence r : residences) byUser.computeIfAbsent(r.userId(), k -> new ArrayList<>()).add(r.label());
        List<Listed> out = new ArrayList<>();
        for (User u : found) {
            List<String> labels = byUser.getOrDefault(u.id(), List.of());''')]),
    dict(target="PERF-012", name="perf012-stream-per-user", kind="wrong", expect="not_fixed",
         edits=[(USERS, NESTED, '''        List<Listed> out = new ArrayList<>();
        for (User u : found) {
            List<String> labels = residences.stream().filter(r -> r.userId() == u.id()).map(Residence::label).toList();''')],
         note="the same scan of every residency for every user, written as a stream"),

    # ---- PERF-015: events of different invoices at the same time
    dict(target="PERF-015", name="perf015-no-application-lock", kind="alternative", expect="fixed",
         edits=[(FINANCE, WEBHOOK, "    public Outcome webhook(byte[] body, String signature) {")]),
    dict(target="PERF-015", name="perf015-lock-object", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, WEBHOOK, '''    private static final java.util.concurrent.locks.ReentrantLock EVENTS = new java.util.concurrent.locks.ReentrantLock();

    public Outcome webhook(byte[] body, String signature) {
        EVENTS.lock();
        try {
            return handle(body, signature);
        } finally {
            EVENTS.unlock();
        }
    }

    private Outcome handle(byte[] body, String signature) {''')],
         note="the same serialization, through a lock of java.util.concurrent"),
    dict(target="PERF-015", name="perf015-global-database-lock", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, WEBHOOK, "    public Outcome webhook(byte[] body, String signature) {"),
                (FINANCE, SETTLE_HOOK, '''        return db.tx(c -> {
            Db.one(c, "select pg_advisory_xact_lock(42)", rs -> true);
            return settle(c, PaymentSource.WEBHOOK, "hook:" + eventId, ourNumber, amount, paidOn, null);
        });''')],
         note="the serialization moved to the database"),

    # ---- PERF-009: the manager's count
    dict(target="PERF-009", name="perf009-count-query", kind="alternative", expect="fixed",
         edits=[(FINANCE_REPO, "    /** The open invoices of a building that were due before the date, by unit and month. */",
                 '''    /** How many open invoices of a building were due before the date. */
    public int countOpenDueBefore(Connection c, long buildingId, LocalDate date) throws SQLException {
        return Db.one(c, "select count(*) as n from invoice i join unit u on u.id = i.unit_id where u.building_id = ? and i.status = 'OPEN' and i.due_date < ?",
                rs -> rs.getInt("n"), buildingId, date).orElse(0);
    }

    /** The open invoices of a building that were due before the date, by unit and month. */'''),
                (REPORTS, "int overdue = finance.openDueBefore(c, buildingId, today).size();", "int overdue = finance.countOpenDueBefore(c, buildingId, today);")]),
    dict(target="PERF-009", name="perf009-stream-count", kind="wrong", expect="not_fixed",
         edits=[(REPORTS, "int overdue = finance.openDueBefore(c, buildingId, today).size();",
                 "int overdue = (int) finance.openDueBefore(c, buildingId, today).stream().count();")]),
]
