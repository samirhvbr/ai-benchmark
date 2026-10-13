package com.condodesk;

import com.tngtech.archunit.core.domain.JavaClass;
import com.tngtech.archunit.core.domain.JavaField;
import com.tngtech.archunit.core.domain.JavaModifier;
import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.Statement;
import java.time.Duration;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeSet;
import java.util.stream.Stream;

/** Architecture seen while the system runs: who does the work of a request, and two applications in one JVM. */
class V05_ArchitectureAtRunTimeTest extends VBase {

    /** The classes of the code under evaluation on the current stack: lambdas count as the class that writes them. */
    private static Set<String> mainClassesOnTheStack() {
        Set<String> out = new TreeSet<>();
        for (StackTraceElement f : Thread.currentThread().getStackTrace()) {
            if (Rendezvous.isMainClass(f.getClassName())) out.add(Structure.top(f.getClassName()));
        }
        return out;
    }

    /**
     * The defect is one class that bills the month and settles the payments, with the money rules inside a class that reaches the
     * database. Seen by what runs, not by names: (1) the stack under the insert of an invoice during a billing run and the stack
     * under the update of an invoice during a bank event share no class besides the HTTP layer, the data access and the database
     * helper; (2) the class that floors the shares of a budget has no database access.
     */
    @Test
    @Verifies({"ARCH-001"})
    void arch001_theBillingRunAndTheSettlementAreNotOneClass() throws Exception {
        Structure st = new Structure(Path.of("src/main/java"), Path.of("target/classes"));
        Map<String, Set<String>> seen = new java.util.concurrent.ConcurrentHashMap<>();
        instr.hook = (c, sql, args) -> {
            String low = sql.strip().toLowerCase();
            if (low.startsWith("insert into invoice")) seen.putIfAbsent("billing", mainClassesOnTheStack());
            if (low.startsWith("update invoice")) seen.putIfAbsent("settlement", mainClassesOnTheStack());
        };
        try {
            Browser a = loggedIn(ADMIN);
            ok(a, "/admin/billing");
            R run = a.post("/admin/billing", "building", String.valueOf(buildingA), "month", "2026-04", "budget", "900.00");
            if (run.status() != 303) throw refused("POST /admin/billing", run);
            long id = invoice(unitA102, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
            R paid = webhook("ev-arch", ourNumberOf(id), "300.00", "2026-03-04");
            if (paid.status() != 200) throw refused("the webhook", paid);
        } finally {
            instr.hook = null;
        }
        if (!seen.containsKey("billing") || !seen.containsKey("settlement")) {
            Verdict.inconclusive("ARCH-001", "the billing run inserted no invoice or the bank event updated no invoice: " + seen.keySet());
            return;
        }
        Set<String> infrastructure = new TreeSet<>(st.httpLayer());
        infrastructure.addAll(st.dataAccess());
        infrastructure.addAll(st.databaseHelpers());
        Set<String> billing = new TreeSet<>(seen.get("billing")), settlement = new TreeSet<>(seen.get("settlement"));
        billing.removeIf(c -> infrastructure.contains(c) || Structure.inRoot(c));
        settlement.removeIf(c -> infrastructure.contains(c) || Structure.inRoot(c));
        Set<String> shared = new TreeSet<>(billing);
        shared.retainAll(settlement);
        boolean apart = shared.isEmpty() && !billing.isEmpty() && !settlement.isEmpty();
        Set<String> withDb = st.withDatabaseAccess();
        List<String> floorsWithDb = st.floors.stream().filter(withDb::contains).map(Structure::simple).toList();
        boolean observed = !st.floors.isEmpty();
        boolean pure = observed && floorsWithDb.isEmpty();
        String result = apart && (pure || !observed) ? "full" : apart || pure ? "half" : "none";
        String msg = "the billing run is done by " + simple(billing) + " and the settlement by " + simple(settlement)
                + (shared.isEmpty() ? "" : ", sharing " + simple(shared)) + "; "
                + (observed ? "the budget split (RoundingMode.FLOOR) is in " + simple(st.floors) + (floorsWithDb.isEmpty() ? ", with no database access" : ", and " + floorsWithDb + " reach the database")
                : "no class floors with RoundingMode.FLOOR, so the purity of the split is not observed");
        if (observed) Verdict.structure("ARCH-001", result, msg);
        else Verdict.structure("ARCH-001", result, msg, "whether the budget split has database access: no class floors with RoundingMode.FLOOR");
    }

    private static List<String> simple(Set<String> types) {
        return types.stream().map(Structure::simple).toList();
    }

    @Test
    @Verifies({"ARCH-013"})
    void arch013_twoApplicationsInOneJvmKeepTheirOwnDatabases() throws Exception {
        Structure st = new Structure(Path.of("src/main/java"), Path.of("target/classes"));
        Set<String> holders = st.databaseHelpers();
        List<String> staticHolders = new ArrayList<>();
        for (JavaClass c : st.bytecode) {
            if (!Structure.ofTheApplication(c.getName())) continue;
            for (JavaField f : c.getFields()) {
                String t = f.getRawType().getName();
                boolean holdsOne = t.equals("javax.sql.DataSource") || t.equals("java.time.Clock") || holders.contains(t);
                if (holdsOne && f.getModifiers().contains(JavaModifier.STATIC) && !f.getModifiers().contains(JavaModifier.FINAL)) {
                    staticHolders.add(Structure.simple(c.getName()) + "." + f.getName());
                }
            }
        }
        String second = schema + "b";
        try (Connection c = DriverManager.getConnection(DB_URL, DB_USER, DB_PASSWORD); Statement s = c.createStatement()) {
            s.execute("create schema " + second);
        }
        App app2 = null;
        Path storage2 = Files.createTempDirectory("condodesk-docs-2");
        String first = base;
        try {
            SchemaDataSource ds2 = new SchemaDataSource(second);
            try (Connection c = ds2.getConnection(); Statement s = c.createStatement()) {
                s.execute(Files.readString(Path.of("schema.sql")));
                Path migrations = Path.of("migrations");
                if (Files.isDirectory(migrations)) {
                    try (Stream<Path> files = Files.list(migrations)) {
                        for (Path p : files.filter(x -> x.toString().endsWith(".sql")).sorted().toList()) s.execute(Files.readString(p));
                    }
                }
            }
            app2 = App.create(new Config(0, storage2, false, Duration.ofMinutes(30), Set.of(), BANK_SECRET, Config.DEFAULT_MAX_UPLOAD), ds2, clock);
            String other = "http://127.0.0.1:" + app2.start();
            long tokensBefore = count("select count(*) from api_token");
            R inFirst = api("POST", "/api/tokens", null, Map.of("email", OWNER_A101, "password", PASSWORD));
            base = other;
            R inSecond = api("POST", "/api/tokens", null, Map.of("email", OWNER_A101, "password", PASSWORD));
            base = first;
            long tokensAfter = count("select count(*) from api_token");
            boolean apart = inFirst.status() == 201 && inSecond.status() == 401 && tokensAfter == tokensBefore + 1;
            String result = !apart ? "none" : staticHolders.isEmpty() ? "full" : "half";
            Verdict.structure("ARCH-013", result, "with a second application on an empty database in the same JVM, a resident of the first logs in to the first: "
                    + inFirst.status() + " (expected 201, and its token in the first database: " + (tokensAfter == tokensBefore + 1) + "), and to the second: "
                    + inSecond.status() + " (expected 401); static fields that hold a data source, a database helper or a clock: " + staticHolders);
        } finally {
            base = first;
            if (app2 != null) app2.stop();
            try (Connection c = DriverManager.getConnection(DB_URL, DB_USER, DB_PASSWORD); Statement s = c.createStatement()) {
                s.execute("drop schema " + second + " cascade");
            }
            try (Stream<Path> files = Files.walk(storage2)) {
                for (Path p : files.sorted(java.util.Comparator.reverseOrder()).toList()) Files.deleteIfExists(p);
            }
        }
    }
}
