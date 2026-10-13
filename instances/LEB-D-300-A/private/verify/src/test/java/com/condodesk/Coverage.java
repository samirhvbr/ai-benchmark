package com.condodesk;

import java.util.Map;
import java.util.Set;

/** What each mechanical verdict proves, and what it leaves to the judge. */
final class Coverage {
    private Coverage() {}

    record Item(String proves, String[] doesNotProve) {}

    static final Set<String> DECOYS = Set.of("BUG-013.b", "ARCH-014", "BUG-010.b", "PERF-015.b");

    static boolean isDecoy(String id) {
        return DECOYS.contains(id);
    }

    static final Map<String, Item> OF = Map.ofEntries(
        Map.entry("BUG-011", new Item("two requests of one unit for two areas, held together at the insert, leave the unit at its limit of two upcoming bookings, with one 201 and one 409",
                new String[] {"three or more requests at once", "a unit with no upcoming booking yet", "requests served by two independent JVMs"})),
        Map.entry("BUG-014", new Item("a statement with a matched line then a line without FITID answers 400 and records nothing, one with a matched line then a line the database refuses answers an error and records nothing, and the matched line alone still settles",
                new String[] {"a failure in the middle of a long statement", "a statement imported while another runs"})),
        Map.entry("BUG-006", new Item("the 30-day block is counted on the building's calendar around midnight in São Paulo, with the JVM in Tokyo, both ways (30 days books, 31 days does not)",
                new String[] {"buildings in other time zones", "daylight saving changes"})),
        Map.entry("BUG-013", new Item("53 days late give 311.30 and 53 days on the invoice page, the API and the delinquency CSV",
                new String[] {"other lengths of delay", "the resident's dashboard, which is ARCH-004's copy of the rule"})),
        Map.entry("BUG-003", new Item("50 reads refused inside their work (404) close every connection they opened",
                new String[] {"reads that fail with a database error", "other resources (streams, statements)"})),
        Map.entry("BUG-010", new Item("an archive with a stored file missing is not answered 200, and a whole one still is",
                new String[] {"other failures of tar (disk full, a file unreadable)"})),
        Map.entry("BUG-007", new Item("a single payment above the amount due settles the invoice, and two payments that together exceed it settle it on the second",
                new String[] {"payments by statement and by hand (the same settlement, other entry points)"})),
        Map.entry("BUG-015", new Item("the record that carries a 500's incident id carries the exception and its stack too (or the process output carries all three), and the response stays generic",
                new String[] {"log destinations the evaluator does not capture (a file, a structured logger)", "other internal errors than a missing stored file"})),
        Map.entry("PERF-001", new Item("the delinquency report runs as many commands with 30 late invoices as with 3, each of a unit with its own owner",
                new String[] {"other sizes and the CSV route"})),
        Map.entry("PERF-003", new Item("the first and the second page of invoices read as many rows with 400 matching invoices as one page with 40, and the second page holds 20 other invoices",
                new String[] {"pages after the second", "filters and sorts"})),
        Map.entry("PERF-010", new Item("the admin's dashboard shows the open invoices of a billing run made after it was first shown",
                new String[] {"whether a cache with an expiry, if any, is acceptable (the judge reads it)"})),
        Map.entry("PERF-006", new Item("an index leads on payment(invoice_id) after schema.sql and the migrations",
                new String[] {"that the planner uses it", "other useful indexes"})),
        Map.entry("PERF-012", new Item("the processor time of the user list grows by less than 8 times from 4,000 to 16,000 accounts",
                new String[] {"absolute time", "other quadratic code"})),
        Map.entry("PERF-015", new Item("a bank event held inside its transaction does not stop an event for another invoice from answering",
                new String[] {"events for the same invoice, which its row lock orders", "statement imports at the same time"})),
        Map.entry("PERF-009", new Item("the manager's dashboard reads as many rows with 300 overdue invoices as with 3, and still counts them",
                new String[] {"other dashboards"})),
        Map.entry("ARCH-001", new Item("the code that inserts the invoices of a billing run and the code that settles an invoice share no class outside the HTTP layer, the data access and the database helper, and the class that floors the shares of a budget has no database access",
                new String[] {"whether what is left together is one responsibility", "the quality of the split chosen"})),
        Map.entry("ARCH-013", new Item("two applications in one JVM, on two schemas, each log in and write against their own database, and no static field that is not final holds a data source, a database helper or a clock",
                new String[] {"other shared static state"})),
        Map.entry("ARCH-008", new Item("no cycle between the packages under the root, with at least as many packages as the legacy",
                new String[] {"whether the dependency was undone or only moved"})),
        Map.entry("ARCH-002", new Item("no class of the HTTP layer references java.sql, javax.sql or the database helper",
                new String[] {"whether the logic moved to a fitting layer"})),
        Map.entry("ARCH-004", new Item("the late fee and the interest are computed (a division next to a call named for them) in one class each",
                new String[] {"a copy written with other names for the rates"})),
        Map.entry("ARCH-003", new Item("no class holds both SQL on the notice table and the notice limits",
                new String[] {"whether the SQL moved to a fitting repository"})),
        Map.entry("ARCH-010", new Item("every top-level class of the main code is referenced by another class of it",
                new String[] {"unused methods inside used classes"})),
        Map.entry("ARCH-009", new Item("the classes that hold the notice limits use none of 200, 10000 or 5 as a bare number and repeat none in a message",
                new String[] {"whether the names say what the numbers mean (the judge's rubric)"})),
        Map.entry("CLN-001", new Item("no method has more than 45 useful lines or 45 statements",
                new String[] {"whether the split into steps reads well"})),
        Map.entry("CLN-007", new Item("no chain of nested decisions is deeper than 3",
                new String[] {"nested loops and the readability of the main flow"})),
        Map.entry("CLN-002", new Item("no method body is copied in another, even with renamed identifiers",
                new String[] {"partial copies, or copies shorter than a method"})),
        Map.entry("CLN-005", new Item("no comment line, of line or block, looks like code",
                new String[] {"dead code that is not commented out"}))
    );

    static Item of(String id) {
        return OF.get(id);
    }
}
