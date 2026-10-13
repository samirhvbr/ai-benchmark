"""LEB-D-300-A — the BUG rows of ../CATALOG.md §2 (Template C)."""

from paths import SRC

FLAWS = [
    # The unit's row is no longer locked: the limit of two upcoming bookings becomes check-then-act.
    ("BUG-011", [
        (SRC + "booking/BookingService.java",
         "     * other and cannot both pass the overlap check; the unit's row is locked for its limit of upcoming bookings.\n",
         "     * other and cannot both pass the overlap check.\n"),
        (SRC + "booking/BookingService.java",
         "            buildings.lockUnit(c, unit.id());\n",
         ""),
        (SRC + "repo/BuildingRepository.java",
         "    /** Locks the unit's row until the transaction ends. */\n"
         "    public void lockUnit(Connection c, long unitId) throws SQLException {\n"
         "        Db.one(c, \"select id from unit where id = ? for update\", rs -> rs.getLong(\"id\"), unitId);\n"
         "    }\n\n",
         ""),
    ]),
    # "Overdue for more than thirty days" is counted on the clock's zone (UTC in production), not on the building's calendar.
    ("BUG-006", [
        (SRC + "booking/BookingService.java",
         "            if (finance.hasOpenDueBefore(c, unit.id(), b.dateAt(now).minusDays(OVERDUE_DAYS_BLOCKING))) {",
         "            if (finance.hasOpenDueBefore(c, unit.id(), LocalDate.now(clock).minusDays(OVERDUE_DAYS_BLOCKING))) {"),
    ]),
    # The days late are the day component of a Period, not its length in days.
    ("BUG-013", [
        (SRC + "billing/Charges.java",
         "import java.time.LocalDate;\nimport java.time.temporal.ChronoUnit;\n",
         "import java.time.LocalDate;\nimport java.time.Period;\n"),
        (SRC + "billing/Charges.java",
         "        return on.isAfter(dueDate) ? ChronoUnit.DAYS.between(dueDate, on) : 0;",
         "        return on.isAfter(dueDate) ? Period.between(dueDate, on).getDays() : 0;"),
    ]),
    # Each statement line settles in a transaction of its own: a later bad line leaves the earlier ones recorded.
    ("BUG-014", [
        (SRC + "billing/PaymentService.java",
         "    /** Imports the credits of a statement, all or nothing. Returns how many lines had each outcome. */\n"
         "    public Map<Outcome, Integer> importStatement(Principal p, byte[] ofx) {\n"
         "        p.requireAdmin();\n"
         "        List<OfxParser.Entry> entries = OfxParser.parse(ofx);\n"
         "        return db.tx(c -> {\n"
         "            Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);\n"
         "            for (OfxParser.Entry e : entries) {\n"
         "                if (!e.type().equals(\"CREDIT\") || e.amount().signum() <= 0) continue;\n"
         "                if (e.fitId().isEmpty()) throw AppException.invalid(\"A statement line has no FITID.\");\n"
         "                Outcome o = settle(c, PaymentSource.STATEMENT, \"ofx:\" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo());\n"
         "                counts.merge(o, 1, Integer::sum);\n"
         "            }\n"
         "            audit.record(c, p.userId(), \"statement_import\", counts.toString(), clock.instant());\n"
         "            return counts;\n"
         "        });\n"
         "    }\n",
         "    /** Imports the credits of a statement. Returns how many lines had each outcome. */\n"
         "    public Map<Outcome, Integer> importStatement(Principal p, byte[] ofx) {\n"
         "        p.requireAdmin();\n"
         "        List<OfxParser.Entry> entries = OfxParser.parse(ofx);\n"
         "        Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);\n"
         "        for (OfxParser.Entry e : entries) {\n"
         "            if (!e.type().equals(\"CREDIT\") || e.amount().signum() <= 0) continue;\n"
         "            if (e.fitId().isEmpty()) throw AppException.invalid(\"A statement line has no FITID.\");\n"
         "            Outcome o = db.tx(c -> settle(c, PaymentSource.STATEMENT, \"ofx:\" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo()));\n"
         "            counts.merge(o, 1, Integer::sum);\n"
         "        }\n"
         "        db.tx(c -> {\n"
         "            audit.record(c, p.userId(), \"statement_import\", counts.toString(), clock.instant());\n"
         "            return null;\n"
         "        });\n"
         "        return counts;\n"
         "    }\n"),
    ]),
    # "The payments cover the amount due" compares with equals: only an exact total settles.
    ("BUG-007", [
        (SRC + "billing/PaymentService.java",
         "        if (finance.paidTotal(c, i.id()).compareTo(due) < 0) return Outcome.PARTIAL;",
         "        if (!finance.paidTotal(c, i.id()).equals(due)) return Outcome.PARTIAL;"),
    ]),
    # The connection of a read is closed only when the work returns.
    ("BUG-003", [
        (SRC + "db/Db.java",
         "    public <T> T read(Work<T> work) {\n"
         "        try (Connection c = dataSource.getConnection()) {\n"
         "            return work.run(c);\n"
         "        } catch (SQLException e) {",
         "    public <T> T read(Work<T> work) {\n"
         "        try {\n"
         "            Connection c = dataSource.getConnection();\n"
         "            T result = work.run(c);\n"
         "            c.close();\n"
         "            return result;\n"
         "        } catch (SQLException e) {"),
    ]),
    # The exit status of tar is not checked.
    ("BUG-010", [
        (SRC + "docs/Archiver.java",
         "            if (proc.exitValue() != 0) throw new IOException(\"tar exited with \" + proc.exitValue());\n",
         ""),
    ]),
    # An internal failure is logged without its exception.
    ("BUG-015", [
        (SRC + "web/Server.java",
         "import java.util.logging.Level;\n",
         ""),
        (SRC + "web/Server.java",
         "            LOG.log(Level.SEVERE, \"incident \" + incident + \" on \" + ex.getRequestMethod() + \" \" + path, e);",
         "            LOG.severe(\"incident \" + incident + \" on \" + ex.getRequestMethod() + \" \" + path);"),
    ]),
]
