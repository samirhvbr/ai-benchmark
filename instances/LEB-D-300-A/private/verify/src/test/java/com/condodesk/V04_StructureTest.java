package com.condodesk;

import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.TreeSet;
import java.util.regex.Pattern;

/**
 * Architecture and code quality (Template R). Each property is measured on the source and the bytecode, without names: the verdict
 * is a ceiling for R3 (full, half or none), never a grade. The limits are calibrated on the reference and the legacy.
 */
class V04_StructureTest {
    static Structure s;

    @BeforeAll
    static void read() {
        s = new Structure(Path.of("src/main/java"), Path.of("target/classes"));
    }

    private static List<String> simple(Set<String> types) {
        return types.stream().map(Structure::simple).toList();
    }

    @Test
    @Verifies({"ARCH-002"})
    void arch002_theHttpLayerDoesNotTalkToTheDatabase() {
        Set<String> http = s.httpLayer();
        Set<String> database = new TreeSet<>(s.referencing("java.sql.", "javax.sql."));
        Set<String> helpers = s.databaseHelpers();
        for (var e : s.dependenciesByClass().entrySet()) if (e.getValue().stream().anyMatch(helpers::contains)) database.add(e.getKey());
        Set<String> violating = new TreeSet<>(http);
        violating.retainAll(database);
        Verdict.structure("ARCH-002", violating.isEmpty() ? "full" : "none", violating.isEmpty()
                ? "no class of the HTTP layer (" + http.size() + " classes) references java.sql, javax.sql or the database helper " + simple(helpers)
                : "classes of the HTTP layer that reach the database directly: " + simple(violating));
    }

    private static final Pattern NOTICE_SQL = Pattern.compile("(?is).*\\b(from|into|update|join)\\s+notice\\b.*");

    @Test
    @Verifies({"ARCH-003"})
    void arch003_theNoticeRuleAndTheNoticeSqlLiveApart() {
        Set<String> sql = new TreeSet<>();
        for (Structure.Text t : s.texts) if (NOTICE_SQL.matcher(t.value()).matches()) sql.add(t.type());
        Set<String> rule = new TreeSet<>();
        for (var e : s.allNumbers.entrySet()) if (e.getValue().containsAll(Set.of("200", "10000"))) rule.add(e.getKey());
        if (sql.isEmpty() || rule.isEmpty()) {
            Verdict.inconclusive("ARCH-003", "no class holds SQL on the notice table (" + simple(sql) + ") or the notice limits 200 and 10000 (" + simple(rule) + ")");
            return;
        }
        Set<String> both = new TreeSet<>(sql);
        both.retainAll(rule);
        Verdict.structure("ARCH-003", both.isEmpty() ? "full" : "none", both.isEmpty()
                ? "the notice SQL lives in " + simple(sql) + " and the notice limits in " + simple(rule)
                : "classes that hold both SQL on the notice table and the notice limits: " + simple(both));
    }

    @Test
    @Verifies({"ARCH-004"})
    void arch004_theAmountDueRuleIsWrittenOnce() {
        Set<String> fee = s.computes.getOrDefault("fee", Set.of()), interest = s.computes.getOrDefault("interest", Set.of());
        if (fee.isEmpty() && interest.isEmpty()) {
            Verdict.inconclusive("ARCH-004", "no method divides with the late fee or the interest rate");
            return;
        }
        boolean once = fee.size() <= 1 && interest.size() <= 1;
        Verdict.structure("ARCH-004", once ? "full" : "none", "the late fee is computed in " + simple(fee) + " and the interest in " + simple(interest)
                + (once ? "" : ": the rule is written more than once"));
    }

    /** The sub-packages of the legacy, the root excluded: merging packages does not break a cycle. */
    static final int MIN_PACKAGES = 10;

    @Test
    @Verifies({"ARCH-008"})
    void arch008_noCycleBetweenPackages() {
        Map<String, Set<String>> graph = new TreeMap<>();
        for (var p : s.dependenciesByPackage().entrySet()) {
            if (p.getKey().equals(Structure.ROOT)) continue;          // the composition root knows everyone, and the configuration lives there
            Set<String> targets = new TreeSet<>(p.getValue());
            targets.remove(Structure.ROOT);
            graph.put(p.getKey(), targets);
        }
        List<List<String>> cycles = Structure.cycles(graph);
        int packages = graph.size();
        boolean ok = cycles.isEmpty() && packages >= MIN_PACKAGES;
        Verdict.structure("ARCH-008", ok ? "full" : "none", cycles.isEmpty()
                ? packages + " packages under the root, with no cycle" + (packages < MIN_PACKAGES ? " (but the minimum is " + MIN_PACKAGES + ": merging packages does not break a cycle)" : "")
                : "cycles between packages: " + cycles.stream().map(c -> c.stream().map(x -> x.replace(Structure.ROOT + ".", "")).sorted().toList()).toList());
    }

    private static final Set<String> NOTICE_LIMITS = Set.of("200", "10000", "5");
    private static final Pattern LIMIT_IN_TEXT = Pattern.compile("(?s).*(\\b200\\b|\\b10,?000\\b|\\b5\\b).*");

    @Test
    @Verifies({"ARCH-009"})
    void arch009_eachNoticeLimitIsNamedOnce() {
        Set<String> holders = new TreeSet<>();
        for (var e : s.allNumbers.entrySet()) if (e.getValue().contains("10000")) holders.add(e.getKey());
        if (holders.isEmpty()) {
            Verdict.inconclusive("ARCH-009", "no class holds the notice text limit 10000");
            return;
        }
        List<String> bare = new ArrayList<>(), repeated = new ArrayList<>();
        for (String h : holders) {
            for (String n : s.bareNumbers.getOrDefault(h, List.of())) if (NOTICE_LIMITS.contains(n)) bare.add(Structure.simple(h) + ":" + n);
            for (Structure.Text t : s.texts) if (t.type().equals(h) && LIMIT_IN_TEXT.matcher(t.value()).matches()) repeated.add(Structure.simple(h) + ": \"" + t.value() + "\"");
        }
        String result = bare.isEmpty() && repeated.isEmpty() ? "full" : bare.isEmpty() || repeated.isEmpty() ? "half" : "none";
        Verdict.structure("ARCH-009", result, "in the classes that hold the notice limits " + simple(holders) + ": bare limits " + bare
                + ", messages that repeat a limit " + repeated);
    }

    @Test
    @Verifies({"ARCH-010"})
    void arch010_everyClassIsUsed() {
        Map<String, Set<String>> dep = s.dependenciesByClass();
        Set<String> referenced = new TreeSet<>();
        for (Set<String> targets : dep.values()) referenced.addAll(targets);
        List<String> unused = dep.keySet().stream().filter(c -> !referenced.contains(c) && !c.equals(Structure.ROOT + ".App")).map(Structure::simple).toList();
        Verdict.structure("ARCH-010", unused.isEmpty() ? "full" : "none", unused.isEmpty()
                ? "every top-level class of the main code is referenced by another (" + dep.size() + " classes)"
                : "top-level classes that no other class of the main code references: " + unused);
    }

    static final int MAX_METHOD_LINES = 45;
    static final int MAX_METHOD_STATEMENTS = 45;

    @Test
    @Verifies({"CLN-001"})
    void cln001_noMethodIsTooLong() {
        var longest = s.methods.stream().max(Comparator.comparingInt(Structure.Method::lines)).orElseThrow();
        var most = s.methods.stream().max(Comparator.comparingInt(Structure.Method::statements)).orElseThrow();
        boolean ok = longest.lines() <= MAX_METHOD_LINES && most.statements() <= MAX_METHOD_STATEMENTS;
        Verdict.structure("CLN-001", ok ? "full" : "none", "the longest method has " + longest.lines() + " useful lines (" + Structure.simple(longest.type()) + "."
                + longest.name() + "; limit " + MAX_METHOD_LINES + ") and the one with most statements has " + most.statements() + " (" + Structure.simple(most.type())
                + "." + most.name() + "; limit " + MAX_METHOD_STATEMENTS + ")");
    }

    /**
     * A body this small (a getter, a delegation) may repeat by coincidence; from three statements on, a repeat is a copy.
     * Constructors that only keep their arguments all look alike and are left out.
     */
    static final int MIN_COPIED_STATEMENTS = 3;

    @Test
    @Verifies({"CLN-002"})
    void cln002_noMethodBodyIsCopied() {
        Map<String, List<String>> bodies = new TreeMap<>();
        for (Structure.Method m : s.methods) {
            if (m.statements() >= MIN_COPIED_STATEMENTS && !m.name().equals("<init>")) bodies.computeIfAbsent(m.canonicalBody(), k -> new ArrayList<>()).add(Structure.simple(m.type()) + "." + m.name());
        }
        List<List<String>> copies = bodies.values().stream().filter(l -> l.size() > 1).toList();
        Verdict.structure("CLN-002", copies.isEmpty() ? "full" : "none", copies.isEmpty() ? "no method body is repeated in another" : "repeated bodies: " + copies);
    }

    @Test
    @Verifies({"CLN-005"})
    void cln005_noCommentedOutCode() {
        Verdict.structure("CLN-005", s.commentedCode.isEmpty() ? "full" : "none", s.commentedCode.isEmpty()
                ? "no comment line looks like code" : s.commentedCode.size() + " comment lines look like code: " + s.commentedCode);
    }

    static final int MAX_NESTING = 3;

    @Test
    @Verifies({"CLN-007"})
    void cln007_noDecisionIsNestedTooDeep() {
        var deepest = s.methods.stream().max(Comparator.comparingInt(Structure.Method::nesting)).orElseThrow();
        Verdict.structure("CLN-007", deepest.nesting() <= MAX_NESTING ? "full" : "none", "the deepest chain of nested decisions has " + deepest.nesting()
                + " levels (" + Structure.simple(deepest.type()) + "." + deepest.name() + "); the limit is " + MAX_NESTING);
    }
}
