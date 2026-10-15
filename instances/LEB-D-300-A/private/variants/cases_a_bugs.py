"""BUG rows: fixes written another way are accepted; partial and wrong ones are not."""

BOOKING = "booking/BookingService.java"
FINANCE = "billing/FinanceService.java"
DB = "db/Db.java"
ARCHIVER = "docs/Archiver.java"
SERVER = "web/Server.java"

UPCOMING = "            if (bookings.upcoming(c, unit.id(), now) >= MAX_UPCOMING_PER_UNIT) {"
IMPORT_LOOP = '''        Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);
        for (OfxParser.Entry e : entries) {
            if (!e.type().equals("CREDIT") || e.amount().signum() <= 0) continue;
            if (e.fitId().isEmpty()) throw AppException.invalid("A statement line has no FITID.");
            Outcome o = db.tx(c -> settle(c, PaymentSource.STATEMENT, "ofx:" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo()));
            counts.merge(o, 1, Integer::sum);
        }
        db.tx(c -> {
            audit.record(c, p.userId(), "statement_import", counts.toString(), clock.instant());
            return null;
        });
        return counts;'''
READ = '''        try {
            Connection c = dataSource.getConnection();
            T result = work.run(c);
            c.close();
            return result;
        } catch (SQLException e) {
            throw new DataAccessException(e);
        }'''
TIMEOUT = '''                    throw new IOException("tar did not finish in " + TIMEOUT_SECONDS + " seconds");
                }
'''
INCIDENT = '            LOG.severe("incident " + incident + " on " + ex.getRequestMethod() + " " + path);'

CASES = [
    # ---- BUG-011: the unit's limit under two requests at once
    dict(target="BUG-011", name="bug011-unit-row-lock", kind="alternative", expect="fixed",
         edits=[(BOOKING, UPCOMING, '            Db.one(c, "select id from unit where id = ? for update", rs -> rs.getLong("id"), unit.id());\n' + UPCOMING)],
         note="the unit's row locked before counting, as the reference does it through a repository"),
    dict(target="BUG-011", name="bug011-advisory-lock-per-unit", kind="alternative", expect="fixed",
         edits=[(BOOKING, UPCOMING, '            Db.one(c, "select pg_advisory_xact_lock(?)", rs -> true, unit.id());\n' + UPCOMING)],
         note="a transaction-scoped advisory lock keyed by the unit holds across connections and JVMs"),
    dict(target="BUG-011", name="bug011-serializable-without-retry", kind="wrong", expect="not_fixed",
         edits=[(BOOKING, "            CommonArea area = buildings.lockArea(c, r.areaId())",
                 "            c.setTransactionIsolation(java.sql.Connection.TRANSACTION_SERIALIZABLE);\n            CommonArea area = buildings.lockArea(c, r.areaId())")],
         note="the database keeps the limit, but the losing request answers 500 instead of 409"),
    dict(target="BUG-011", name="bug011-lock-inside-the-jvm", kind="wrong", expect="fixed",
         edits=[(BOOKING, "    public Booking book(Principal p, BookingRequest r) {", "    public synchronized Booking book(Principal p, BookingRequest r) {")],
         note="a known limit of the probe, which runs one JVM: it accepts a lock that does not hold across instances; the matrix's notes "
              "send it to the judge, since the manifest allows several instances"),

    # ---- BUG-014: a statement whole or not at all
    dict(target="BUG-014", name="bug014-one-transaction", kind="alternative", expect="fixed",
         edits=[(FINANCE, IMPORT_LOOP, '''        return db.tx(c -> {
            Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);
            for (OfxParser.Entry e : entries) {
                if (!e.type().equals("CREDIT") || e.amount().signum() <= 0) continue;
                if (e.fitId().isEmpty()) throw AppException.invalid("A statement line has no FITID.");
                counts.merge(settle(c, PaymentSource.STATEMENT, "ofx:" + e.fitId(), ourNumberIn(e.memo()), e.amount(), e.posted(), e.memo()), 1, Integer::sum);
            }
            audit.record(c, p.userId(), "statement_import", counts.toString(), clock.instant());
            return counts;
        });''')]),
    dict(target="BUG-014", name="bug014-validate-first", kind="partial", expect="not_fixed",
         edits=[(FINANCE, "        Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);\n        for (OfxParser.Entry e : entries) {",
                 '        if (entries.stream().anyMatch(e -> e.type().equals("CREDIT") && e.amount().signum() > 0 && e.fitId().isEmpty())) {\n'
                 '            throw AppException.invalid("A statement line has no FITID.");\n        }\n'
                 "        Map<Outcome, Integer> counts = new EnumMap<>(Outcome.class);\n        for (OfxParser.Entry e : entries) {")],
         note="every line checked before any is recorded, but still one transaction per line: a line the database refuses leaves the earlier ones"),
    dict(target="BUG-014", name="bug014-skip-bad-lines", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, '            if (e.fitId().isEmpty()) throw AppException.invalid("A statement line has no FITID.");',
                 "            if (e.fitId().isEmpty()) continue;")]),

    # ---- BUG-006: the building's calendar
    dict(target="BUG-006", name="bug006-building-date", kind="alternative", expect="fixed",
         edits=[(BOOKING, "LocalDate.now(clock)", "b.dateAt(clock.instant())")]),
    dict(target="BUG-006", name="bug006-clock-in-the-buildings-zone", kind="alternative", expect="fixed",
         edits=[(BOOKING, "LocalDate.now(clock)", "LocalDate.now(clock.withZone(b.zone()))")],
         note="right with any Clock; it needed the test clock to honour withZone (0.2.197)"),
    dict(target="BUG-006", name="bug006-jvm-zone", kind="wrong", expect="not_fixed",
         edits=[(BOOKING, "LocalDate.now(clock)", "LocalDate.now()")]),
    dict(target="BUG-006", name="bug006-one-more-day", kind="wrong", expect="not_fixed",
         edits=[(BOOKING, "LocalDate.now(clock).minusDays(OVERDUE_DAYS_BLOCKING)", "LocalDate.now(clock).minusDays(OVERDUE_DAYS_BLOCKING + 1)")],
         note="right around midnight in São Paulo, wrong for the rest of the day"),

    # ---- BUG-013: the days late
    dict(target="BUG-013", name="bug013-chrono-days", kind="alternative", expect="fixed",
         edits=[(FINANCE, "Period.between(dueDate, on).getDays()", "java.time.temporal.ChronoUnit.DAYS.between(dueDate, on)")]),
    dict(target="BUG-013", name="bug013-until", kind="alternative", expect="fixed",
         edits=[(FINANCE, "Period.between(dueDate, on).getDays()", "dueDate.until(on, java.time.temporal.ChronoUnit.DAYS)")]),
    dict(target="BUG-013", name="bug013-thirty-day-months", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, "Period.between(dueDate, on).getDays()", "Period.between(dueDate, on).getMonths() * 30L + Period.between(dueDate, on).getDays()")]),

    # ---- BUG-003: connections given back
    dict(target="BUG-003", name="bug003-try-with-resources", kind="alternative", expect="fixed",
         edits=[(DB, READ, '''        try (Connection c = dataSource.getConnection()) {
            return work.run(c);
        } catch (SQLException e) {
            throw new DataAccessException(e);
        }''')]),
    dict(target="BUG-003", name="bug003-finally", kind="alternative", expect="fixed",
         edits=[(DB, READ, '''        try {
            Connection c = dataSource.getConnection();
            try {
                return work.run(c);
            } finally {
                c.close();
            }
        } catch (SQLException e) {
            throw new DataAccessException(e);
        }''')]),
    dict(target="BUG-003", name="bug003-closed-on-database-errors-only", kind="partial", expect="not_fixed",
         edits=[(DB, READ, '''        Connection c = null;
        try {
            c = dataSource.getConnection();
            T result = work.run(c);
            c.close();
            return result;
        } catch (SQLException e) {
            if (c != null) {
                try {
                    c.close();
                } catch (SQLException suppressed) {
                    e.addSuppressed(suppressed);
                }
            }
            throw new DataAccessException(e);
        }''')],
         note="a not-found thrown by the work is not an SQLException: those connections still leak"),

    # ---- BUG-010: the archiving command's failure
    dict(target="BUG-010", name="bug010-exit-status", kind="alternative", expect="fixed",
         edits=[(ARCHIVER, TIMEOUT, TIMEOUT + '            if (proc.exitValue() != 0) throw new IOException("tar exited with status " + proc.exitValue());\n')]),
    dict(target="BUG-010", name="bug010-files-checked-first", kind="alternative", expect="fixed",
         edits=[(ARCHIVER, "            cmd.addAll(fileNames);",
                 '            for (String f : fileNames) if (!Files.isRegularFile(dir.resolve(f))) throw new IOException("missing stored file " + f);\n'
                 "            cmd.addAll(fileNames);")]),
    dict(target="BUG-010", name="bug010-missing-files-left-out", kind="wrong", expect="not_fixed",
         edits=[(ARCHIVER, "            cmd.addAll(fileNames);", "            cmd.addAll(fileNames.stream().filter(f -> Files.exists(dir.resolve(f))).toList());")],
         note="an archive that silently lacks a document still looks whole"),

    # ---- BUG-007: what settles an invoice
    dict(target="BUG-007", name="bug007-at-least-the-amount-due", kind="alternative", expect="fixed",
         edits=[(FINANCE, "if (!finance.paidTotal(c, i.id()).equals(due)) return Outcome.PARTIAL;",
                 "if (finance.paidTotal(c, i.id()).compareTo(due) < 0) return Outcome.PARTIAL;")]),
    dict(target="BUG-007", name="bug007-compared-for-equality", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, "if (!finance.paidTotal(c, i.id()).equals(due)) return Outcome.PARTIAL;",
                 "if (finance.paidTotal(c, i.id()).compareTo(due) != 0) return Outcome.PARTIAL;")]),
    dict(target="BUG-007", name="bug007-this-payment-only", kind="wrong", expect="not_fixed",
         edits=[(FINANCE, "if (!finance.paidTotal(c, i.id()).equals(due)) return Outcome.PARTIAL;",
                 "if (amount.compareTo(due) < 0) return Outcome.PARTIAL;")],
         note="two payments that together cover the amount due never settle"),

    # ---- BUG-015: the trace of an internal error
    dict(target="BUG-015", name="bug015-logged-with-the-exception", kind="alternative", expect="fixed",
         edits=[(SERVER, INCIDENT, '            LOG.log(java.util.logging.Level.SEVERE, "incident " + incident + " on " + ex.getRequestMethod() + " " + path, e);')]),
    dict(target="BUG-015", name="bug015-printed-with-its-stack", kind="alternative", expect="fixed",
         edits=[(SERVER, INCIDENT, '            System.err.println("incident " + incident + " on " + ex.getRequestMethod() + " " + path);\n            e.printStackTrace();')]),
    dict(target="BUG-015", name="bug015-wrapper-with-the-cause", kind="alternative", expect="fixed",
         edits=[(SERVER, INCIDENT, '            LOG.log(java.util.logging.Level.SEVERE, "incident " + incident + " on " + ex.getRequestMethod() + " " + path, new IllegalStateException("request failed", e));')],
         note="the record carries a wrapper, and the missing file's exception is its cause"),
    dict(target="BUG-015", name="bug015-wrapper-without-the-cause", kind="wrong", expect="not_fixed",
         edits=[(SERVER, INCIDENT, '            LOG.log(java.util.logging.Level.SEVERE, "incident " + incident + " on " + ex.getRequestMethod() + " " + path, new IllegalStateException("request failed"));')],
         note="the record carries an exception and a stack, but not the failure's: the cause was dropped"),
    dict(target="BUG-015", name="bug015-message-only", kind="wrong", expect="not_fixed",
         edits=[(SERVER, INCIDENT, '            LOG.severe("incident " + incident + " on " + ex.getRequestMethod() + " " + path + ": " + e);')],
         note="the exception's text names the cause but carries no stack"),
]
