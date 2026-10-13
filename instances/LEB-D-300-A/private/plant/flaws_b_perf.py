"""LEB-D-300-A — the PERF rows of ../CATALOG.md §3 (Template C)."""

from paths import SRC

FLAWS = [
    # The owners of each late invoice's unit are read by one query per invoice.
    ("PERF-001", [
        (SRC + "reports/ReportService.java",
         "import java.util.List;\nimport java.util.Map;\nimport java.util.stream.Collectors;\n",
         "import java.util.List;\n"),
        (SRC + "reports/ReportService.java",
         "            List<Invoice> late = finance.openDueBefore(c, buildingId, today);\n"
         "            Map<Long, String> owners = reports.ownersByUnit(c, late.stream().map(Invoice::unitId).collect(Collectors.toSet()));\n",
         "            List<Invoice> late = finance.openDueBefore(c, buildingId, today);\n"),
        (SRC + "reports/ReportService.java",
         "                rows.add(new Delinquent(i.unitLabel(), owners.getOrDefault(i.unitId(), \"\"), i.month(), i.dueDate(), i.amount(), due,",
         "                rows.add(new Delinquent(i.unitLabel(), reports.ownersOf(c, i.unitId()), i.month(), i.dueDate(), i.amount(), due,"),
        (SRC + "repo/ReportRepository.java",
         "import java.time.YearMonth;\nimport java.util.HashMap;\nimport java.util.List;\nimport java.util.Map;\nimport java.util.Set;\n",
         "import java.time.YearMonth;\nimport java.util.List;\n"),
        (SRC + "repo/ReportRepository.java",
         "    /** The owners' names of each unit, joined with a comma. */\n"
         "    public Map<Long, String> ownersByUnit(Connection c, Set<Long> unitIds) throws SQLException {\n"
         "        Map<Long, String> out = new HashMap<>();\n"
         "        if (unitIds.isEmpty()) return out;\n"
         "        for (Map.Entry<Long, String> row : Db.list(c, \"select r.unit_id, string_agg(u.name, ', ' order by u.name) as names from residency r \"\n"
         "                        + \"join app_user u on u.id = r.user_id where r.unit_id = any(?) and r.kind = 'OWNER' group by r.unit_id\",\n"
         "                rs -> Map.entry(rs.getLong(\"unit_id\"), rs.getString(\"names\")), Db.ids(unitIds))) {\n"
         "            out.put(row.getKey(), row.getValue());\n"
         "        }\n"
         "        return out;\n"
         "    }\n",
         "    /** The owners' names of a unit, joined with a comma. */\n"
         "    public String ownersOf(Connection c, long unitId) throws SQLException {\n"
         "        return Db.one(c, \"select string_agg(u.name, ', ' order by u.name) as names from residency r join app_user u on u.id = r.user_id \"\n"
         "                + \"where r.unit_id = ? and r.kind = 'OWNER'\", rs -> rs.getString(\"names\"), unitId).orElse(\"\");\n"
         "    }\n"),
    ]),
    # The invoice list reads every matching row and keeps one page in memory.
    ("PERF-003", [
        (SRC + "repo/FinanceRepository.java",
         "        long total = Db.one(c, \"select count(*) as n from invoice i join unit u on u.id = i.unit_id\" + where,\n"
         "                rs -> rs.getLong(\"n\"), args.toArray()).orElse(0L);\n",
         ""),
        (SRC + "repo/FinanceRepository.java",
         "        args.add(size);\n"
         "        args.add((long) (page - 1) * size);\n"
         "        List<Invoice> items = Db.list(c, INVOICE + where + \" order by \" + order + \" limit ? offset ?\", FinanceRepository::invoice, args.toArray());\n"
         "        return new Page<>(items, page, size, total);\n",
         "        List<Invoice> all = Db.list(c, INVOICE + where + \" order by \" + order, FinanceRepository::invoice, args.toArray());\n"
         "        int from = (int) Math.min((long) (page - 1) * size, all.size());\n"
         "        List<Invoice> items = all.subList(from, Math.min(from + size, all.size()));\n"
         "        return new Page<>(items, page, size, all.size());\n"),
    ]),
    # The admin's building totals are computed once and kept for the life of the application.
    ("PERF-010", [
        (SRC + "reports/ReportService.java",
         "    private final Clock clock;\n\n    public ReportService(",
         "    private final Clock clock;\n"
         "    /** The admin's building totals, read once: buildings are few and change little. */\n"
         "    private List<BuildingTotals> buildingTotals;\n\n"
         "    public ReportService("),
        (SRC + "reports/ReportService.java",
         "            case ADMIN -> new Dashboard(0, BigDecimal.ZERO, 0, 0, reports.buildingTotals(c));\n"
         "        });\n"
         "    }\n",
         "            case ADMIN -> new Dashboard(0, BigDecimal.ZERO, 0, 0, adminTotals(c));\n"
         "        });\n"
         "    }\n\n"
         "    private List<BuildingTotals> adminTotals(Connection c) throws SQLException {\n"
         "        if (buildingTotals == null) buildingTotals = reports.buildingTotals(c);\n"
         "        return buildingTotals;\n"
         "    }\n"),
    ]),
    # No index on payment(invoice_id).
    ("PERF-006", [
        ("schema.sql",
         "create index payment_invoice on payment (invoice_id);\n",
         ""),
    ]),
    # The admin's user list joins users and units in memory with two nested loops.
    ("PERF-012", [
        (SRC + "repo/UserRepository.java",
         "import java.time.Instant;\nimport java.util.HashSet;\n",
         "import java.time.Instant;\nimport java.util.ArrayList;\nimport java.util.HashSet;\n"),
        (SRC + "repo/UserRepository.java",
         "    /** A user as the admin's user list shows it. */\n"
         "    public record Listed(long id, String email, String name, Role role, Long buildingId, boolean active, String units) {}\n",
         "    /** A user as the admin's user list shows it. */\n"
         "    public record Listed(long id, String email, String name, Role role, Long buildingId, boolean active, String units) {}\n\n"
         "    private record Residence(long userId, String label) {}\n"),
        (SRC + "repo/UserRepository.java",
         "    public List<Listed> list(Connection c, Long buildingId) throws SQLException {\n"
         "        return Db.list(c, \"select u.id, u.email, u.name, u.role, u.building_id, u.active, \"\n"
         "                        + \"coalesce(string_agg(un.label, ', ' order by un.label), '') as units \"\n"
         "                        + \"from app_user u left join residency r on r.user_id = u.id left join unit un on un.id = r.unit_id \"\n"
         "                        + \"where (?::bigint is null or u.building_id = ?) group by u.id order by u.name, u.id\",\n"
         "                rs -> new Listed(rs.getLong(\"id\"), rs.getString(\"email\"), rs.getString(\"name\"), Role.valueOf(rs.getString(\"role\")),\n"
         "                        Db.nullableLong(rs, \"building_id\"), rs.getBoolean(\"active\"), rs.getString(\"units\")),\n"
         "                buildingId, buildingId);\n"
         "    }\n",
         "    public List<Listed> list(Connection c, Long buildingId) throws SQLException {\n"
         "        List<User> found = Db.list(c, \"select \" + COLUMNS + \" from app_user where (?::bigint is null or building_id = ?) order by name, id\",\n"
         "                UserRepository::user, buildingId, buildingId);\n"
         "        List<Residence> residences = Db.list(c, \"select r.user_id, un.label from residency r join unit un on un.id = r.unit_id order by un.label\",\n"
         "                rs -> new Residence(rs.getLong(\"user_id\"), rs.getString(\"label\")));\n"
         "        List<Listed> out = new ArrayList<>();\n"
         "        for (User u : found) {\n"
         "            List<String> labels = new ArrayList<>();\n"
         "            for (Residence r : residences) {\n"
         "                if (r.userId() == u.id()) labels.add(r.label());\n"
         "            }\n"
         "            out.add(new Listed(u.id(), u.email(), u.name(), u.role(), u.buildingId(), u.active(), String.join(\", \", labels)));\n"
         "        }\n"
         "        return out;\n"
         "    }\n"),
    ]),
    # The webhook is synchronized on the service, although the invoice's row lock already orders the events of one invoice.
    ("PERF-015", [
        (SRC + "billing/PaymentService.java",
         "    /** A payment the bank reports on its webhook, signed with HMAC-SHA256 of the raw body. */\n"
         "    public Outcome webhook(byte[] body, String signature) {",
         "    /** A payment the bank reports on its webhook, signed with HMAC-SHA256 of the raw body. Events are handled one at a time. */\n"
         "    public synchronized Outcome webhook(byte[] body, String signature) {"),
    ]),
    # The manager's count of overdue invoices loads them all.
    ("PERF-009", [
        (SRC + "reports/ReportService.java",
         "        int overdue = finance.countOpenDueBefore(c, buildingId, today);",
         "        int overdue = finance.openDueBefore(c, buildingId, today).size();"),
        (SRC + "repo/FinanceRepository.java",
         "    /** How many open invoices of a building were due before the date. */\n"
         "    public int countOpenDueBefore(Connection c, long buildingId, LocalDate date) throws SQLException {\n"
         "        return Db.one(c, \"select count(*) as n from invoice i join unit u on u.id = i.unit_id \"\n"
         "                        + \"where u.building_id = ? and i.status = 'OPEN' and i.due_date < ?\",\n"
         "                rs -> rs.getInt(\"n\"), buildingId, date).orElse(0);\n"
         "    }\n\n",
         ""),
    ]),
]
