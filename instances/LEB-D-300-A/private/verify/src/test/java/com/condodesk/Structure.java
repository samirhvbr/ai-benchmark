package com.condodesk;

import com.sun.source.tree.BlockTree;
import com.sun.source.tree.ClassTree;
import com.sun.source.tree.CompilationUnitTree;
import com.sun.source.tree.IfTree;
import com.sun.source.tree.LiteralTree;
import com.sun.source.tree.MemberSelectTree;
import com.sun.source.tree.MethodInvocationTree;
import com.sun.source.tree.MethodTree;
import com.sun.source.tree.StatementTree;
import com.sun.source.tree.SwitchTree;
import com.sun.source.tree.Tree;
import com.sun.source.tree.VariableTree;
import com.sun.source.util.JavacTask;
import com.sun.source.util.SourcePositions;
import com.sun.source.util.TreeScanner;
import com.sun.source.util.Trees;
import com.tngtech.archunit.core.domain.Dependency;
import com.tngtech.archunit.core.domain.JavaClass;
import com.tngtech.archunit.core.domain.JavaClasses;
import com.tngtech.archunit.core.importer.ClassFileImporter;

import javax.lang.model.element.Modifier;
import javax.tools.JavaCompiler;
import javax.tools.JavaFileObject;
import javax.tools.StandardJavaFileManager;
import javax.tools.ToolProvider;
import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.TreeSet;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Stream;

/**
 * A structural reading of the code under evaluation that does not depend on names: javac's syntax tree (methods, nesting,
 * literals, calls) and the dependency graph of the bytecode (ArchUnit). It measures what the code is, not where it is.
 */
final class Structure {
    static final String ROOT = "com.condodesk";

    /**
     * A method, with what the reading measures. {@code nesting} is the depth of chained decisions (if/switch); an "else if" does not
     * deepen it. A constructor is named {@code <init>}.
     */
    record Method(String type, String name, int lines, int nesting, int statements, String canonicalBody) {}

    /** A string literal, with the top-level class it is written in. */
    record Text(String type, String value) {}

    final List<Method> methods = new ArrayList<>();
    final List<Text> texts = new ArrayList<>();
    /** Numeric literals outside constant declarations, by top-level class. */
    final Map<String, List<String>> bareNumbers = new TreeMap<>();
    /** Every numeric literal, constant declarations included, by top-level class. */
    final Map<String, Set<String>> allNumbers = new TreeMap<>();
    /** Top-level classes with a method that calls {@code divide} and a method whose name has the word, by word (fee, interest). */
    final Map<String, Set<String>> computes = new TreeMap<>();
    /** Top-level classes that name {@code RoundingMode.FLOOR}: the floor of a share, as a budget split does. */
    final Set<String> floors = new TreeSet<>();
    /** Comment lines that look like code. */
    final List<String> commentedCode = new ArrayList<>();

    final JavaClasses bytecode;

    Structure(Path sources, Path classes) {
        parse(sources);
        bytecode = new ClassFileImporter().importPath(classes);
    }

    static String simple(String type) {
        return type.replaceAll(".*\\.", "");
    }

    static String top(String name) {
        int i = name.indexOf('$');
        return i < 0 ? name : name.substring(0, i);
    }

    static String packageOf(String type) {
        return type.substring(0, type.lastIndexOf('.'));
    }

    static boolean inRoot(String type) {
        return packageOf(type).equals(ROOT);
    }

    static boolean ofTheApplication(String name) {
        return name.startsWith(ROOT + ".");
    }

    // ---- bytecode

    /** Top-level classes of the application that each top-level class references. */
    Map<String, Set<String>> dependenciesByClass() {
        Map<String, Set<String>> out = new TreeMap<>();
        for (JavaClass c : bytecode) {
            if (!ofTheApplication(c.getName())) continue;
            Set<String> targets = out.computeIfAbsent(top(c.getName()), k -> new TreeSet<>());
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                String t = top(d.getTargetClass().getName());
                if (ofTheApplication(t) && !t.equals(top(c.getName()))) targets.add(t);
            }
        }
        return out;
    }

    /** Packages of the application and, for each, the packages it references. */
    Map<String, Set<String>> dependenciesByPackage() {
        Map<String, Set<String>> out = new TreeMap<>();
        for (JavaClass c : bytecode) {
            if (!ofTheApplication(c.getName())) continue;
            Set<String> targets = out.computeIfAbsent(c.getPackageName(), k -> new TreeSet<>());
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                JavaClass t = d.getTargetClass();
                if (ofTheApplication(t.getName()) && !t.getPackageName().equals(c.getPackageName())) targets.add(t.getPackageName());
            }
        }
        return out;
    }

    /** Top-level classes that reference a type whose name starts with one of the prefixes. */
    Set<String> referencing(String... prefixes) {
        Set<String> out = new TreeSet<>();
        for (JavaClass c : bytecode) {
            if (!ofTheApplication(c.getName())) continue;
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                for (String p : prefixes) if (d.getTargetClass().getName().startsWith(p)) out.add(top(c.getName()));
            }
        }
        return out;
    }

    /** The database helpers: classes that hold a {@code javax.sql.DataSource}, the one place connections come from. */
    Set<String> databaseHelpers() {
        return referencing("javax.sql.DataSource");
    }

    /** Classes that write SQL and use the JDBC API: the data access. */
    Set<String> dataAccess() {
        Set<String> sql = new TreeSet<>();
        for (Text t : texts) if (t.value().matches("(?is)^\\s*(select|insert\\s+into|update|delete\\s+from|with)\\b.*")) sql.add(t.type());
        sql.retainAll(referencing("java.sql.", "javax.sql."));
        return sql;
    }

    /** Classes with database access: they use the JDBC API, or depend on the database helper or on a data access class. */
    Set<String> withDatabaseAccess() {
        Set<String> out = new TreeSet<>(referencing("java.sql.", "javax.sql."));
        Set<String> reach = new TreeSet<>(databaseHelpers());
        reach.addAll(dataAccess());
        for (var e : dependenciesByClass().entrySet()) if (e.getValue().stream().anyMatch(reach::contains)) out.add(e.getKey());
        return out;
    }

    /**
     * The HTTP layer, by dependency: whoever uses the JDK's HTTP server or a class that does, and every class of the same packages
     * (moving the database into a neighbor of the handler does not take it out of the layer). The root package is outside it.
     */
    Set<String> httpLayer() {
        Set<String> server = referencing("com.sun.net.httpserver.");
        Set<String> layer = new TreeSet<>(server);
        Map<String, Set<String>> dep = dependenciesByClass();
        for (var c : dep.entrySet()) if (c.getValue().stream().anyMatch(server::contains)) layer.add(c.getKey());
        layer.removeIf(Structure::inRoot);
        Set<String> packages = new TreeSet<>();
        for (String c : layer) packages.add(packageOf(c));
        for (String c : dep.keySet()) if (packages.contains(packageOf(c))) layer.add(c);
        return layer;
    }

    /** Strongly connected components of more than one node (Tarjan): the cycles. */
    static List<List<String>> cycles(Map<String, Set<String>> graph) {
        List<List<String>> out = new ArrayList<>();
        Map<String, Integer> index = new TreeMap<>(), low = new TreeMap<>();
        java.util.Deque<String> stack = new java.util.ArrayDeque<>();
        Set<String> onStack = new TreeSet<>();
        int[] counter = {0};
        for (String v : graph.keySet()) if (!index.containsKey(v)) connect(v, graph, index, low, stack, onStack, counter, out);
        return out;
    }

    private static void connect(String v, Map<String, Set<String>> g, Map<String, Integer> index, Map<String, Integer> low,
                                java.util.Deque<String> stack, Set<String> onStack, int[] counter, List<List<String>> out) {
        index.put(v, counter[0]);
        low.put(v, counter[0]++);
        stack.push(v);
        onStack.add(v);
        for (String w : g.getOrDefault(v, Set.of())) {
            if (!index.containsKey(w)) {
                connect(w, g, index, low, stack, onStack, counter, out);
                low.put(v, Math.min(low.get(v), low.get(w)));
            } else if (onStack.contains(w)) {
                low.put(v, Math.min(low.get(v), index.get(w)));
            }
        }
        if (low.get(v).equals(index.get(v))) {
            List<String> component = new ArrayList<>();
            String w;
            do {
                w = stack.pop();
                onStack.remove(w);
                component.add(w);
            } while (!w.equals(v));
            if (component.size() > 1) out.add(component);
        }
    }

    // ---- sources

    private void parse(Path sources) {
        List<Path> files;
        try (Stream<Path> s = Files.walk(sources)) {
            files = s.filter(p -> p.toString().endsWith(".java")).sorted().toList();
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        JavaCompiler jc = ToolProvider.getSystemJavaCompiler();
        try (StandardJavaFileManager fm = jc.getStandardFileManager(null, null, StandardCharsets.UTF_8)) {
            Iterable<? extends JavaFileObject> objects = fm.getJavaFileObjectsFromPaths(files);
            JavacTask task = (JavacTask) jc.getTask(null, fm, d -> {}, List.of("-proc:none"), null, objects);
            SourcePositions sp = Trees.instance(task).getSourcePositions();
            for (CompilationUnitTree cu : task.parse()) {
                String text = cu.getSourceFile().getCharContent(true).toString();
                String pkg = cu.getPackageName() == null ? "" : cu.getPackageName().toString();
                for (Tree t : cu.getTypeDecls()) {
                    if (!(t instanceof ClassTree ct)) continue;
                    String type = (pkg.isEmpty() ? "" : pkg + ".") + ct.getSimpleName();
                    new Reader(cu, sp, text, type).scan(ct, null);
                    commentsThatLookLikeCode(type, text);
                }
            }
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
    }

    private static final Set<String> KEYWORDS = Set.of("abstract", "assert", "boolean", "break", "byte", "case", "catch", "char", "class", "continue", "default",
            "do", "double", "else", "enum", "extends", "final", "finally", "float", "for", "if", "implements", "import", "instanceof", "int", "interface", "long",
            "new", "package", "private", "protected", "public", "return", "short", "static", "super", "switch", "synchronized", "this", "throw", "throws", "try",
            "var", "void", "volatile", "while", "true", "false", "null", "record", "yield");
    private static final Pattern TOKEN = Pattern.compile("\"(?:[^\"\\\\]|\\\\.)*\"|'(?:[^'\\\\]|\\\\.)*'|[A-Za-z_$][A-Za-z0-9_$]*|\\s+|.", Pattern.DOTALL);

    /** The body without spaces and with every identifier turned into I: what is left is the shape, so renaming variables hides no copy. */
    static String canonical(String body) {
        StringBuilder b = new StringBuilder();
        Matcher m = TOKEN.matcher(body);
        while (m.find()) {
            String p = m.group();
            if (p.isBlank()) continue;
            b.append(Character.isJavaIdentifierStart(p.charAt(0)) && !KEYWORDS.contains(p) ? "I" : p);
        }
        return b.toString();
    }

    static int usefulLines(String fragment) {
        int n = 0;
        boolean inBlock = false;
        for (String raw : fragment.split("\n", -1)) {
            String l = raw.strip();
            if (inBlock) {
                if (l.contains("*/")) inBlock = false;
                continue;
            }
            if (l.isEmpty() || l.startsWith("//")) continue;
            if (l.startsWith("/*")) {
                if (!l.contains("*/")) inBlock = true;
                continue;
            }
            n++;
        }
        return n;
    }

    private static boolean looksLikeCode(String content) {
        return content.endsWith(";") || content.endsWith("{") || content.endsWith("}") || content.matches("^[\\w.]+\\s*\\(.*\\)\\s*[;{]?$");
    }

    private void commentsThatLookLikeCode(String type, String text) {
        Matcher block = Pattern.compile("/\\*(?!\\*)(.*?)\\*/", Pattern.DOTALL).matcher(text);
        while (block.find()) {
            for (String l : block.group(1).split("\n")) {
                String content = l.strip().replaceFirst("^\\*\\s*", "");
                if (looksLikeCode(content)) commentedCode.add(simple(type) + " (block) " + content);
            }
        }
        int n = 0;
        for (String l : text.split("\n", -1)) {
            n++;
            String s = l.strip();
            if (!s.startsWith("//") || s.startsWith("///")) continue;
            String content = s.substring(2).strip();
            if (looksLikeCode(content)) commentedCode.add(simple(type) + ":" + n + " " + content);
        }
    }

    /** Walks one top-level class measuring methods, nesting, literals and calls. */
    private final class Reader extends TreeScanner<Void, Void> {
        private final CompilationUnitTree cu;
        private final SourcePositions sp;
        private final String text;
        private final String type;
        private int depth;
        private int deepest;
        private int statements;
        private boolean inConstant;
        private final Set<String> calls = new HashSet<>();
        private final Set<LiteralTree> ignored = new HashSet<>();

        Reader(CompilationUnitTree cu, SourcePositions sp, String text, String type) {
            this.cu = cu;
            this.sp = sp;
            this.text = text;
            this.type = type;
        }

        /** Parameters of methods, lambdas and catch clauses: declarations, not statements. */
        private final Set<Tree> parameters = new HashSet<>();

        /** Counts each statement (not the blocks) of a method's body, nested ones and lambdas included: joining lines does not lower it. */
        @Override
        public Void scan(Tree t, Void v) {
            if (t instanceof StatementTree && !(t instanceof BlockTree) && !parameters.contains(t)) statements++;
            return super.scan(t, v);
        }

        @Override
        public Void visitLambdaExpression(com.sun.source.tree.LambdaExpressionTree t, Void v) {
            parameters.addAll(t.getParameters());
            return super.visitLambdaExpression(t, v);
        }

        @Override
        public Void visitCatch(com.sun.source.tree.CatchTree t, Void v) {
            parameters.add(t.getParameter());
            return super.visitCatch(t, v);
        }

        @Override
        public Void visitMethod(MethodTree m, Void v) {
            if (m.getBody() == null) return super.visitMethod(m, v);
            parameters.addAll(m.getParameters());
            int savedDepth = depth, savedDeepest = deepest, savedStatements = statements;
            Set<String> savedCalls = new HashSet<>(calls);
            depth = 0;
            deepest = 0;
            statements = 0;
            calls.clear();
            super.visitMethod(m, v);
            long start = sp.getStartPosition(cu, m.getBody()), end = sp.getEndPosition(cu, m.getBody());
            String body = text.substring((int) start, (int) end);
            methods.add(new Method(type, m.getName().toString(), usefulLines(body), deepest, statements, canonical(body)));
            if (calls.contains("divide")) {
                for (String word : List.of("fee", "interest")) {
                    if (calls.stream().anyMatch(c -> c.toLowerCase().contains(word))) computes.computeIfAbsent(word, k -> new TreeSet<>()).add(type);
                }
            }
            depth = savedDepth;
            deepest = savedDeepest;
            statements = savedStatements;
            calls.clear();
            calls.addAll(savedCalls);
            return null;
        }

        @Override
        public Void visitVariable(VariableTree t, Void v) {
            boolean before = inConstant;
            var mods = t.getModifiers().getFlags();
            if (mods.contains(Modifier.STATIC) && mods.contains(Modifier.FINAL)) inConstant = true;
            super.visitVariable(t, v);
            inConstant = before;
            return null;
        }

        @Override
        public Void visitBlock(BlockTree t, Void v) {
            boolean before = inConstant;
            if (t.isStatic()) inConstant = true;
            super.visitBlock(t, v);
            inConstant = before;
            return null;
        }

        @Override
        public Void visitMethodInvocation(MethodInvocationTree t, Void v) {
            String name = t.getMethodSelect() instanceof MemberSelectTree m ? m.getIdentifier().toString() : t.getMethodSelect().toString();
            calls.add(name);
            // the index of a JDBC parameter or column is not a business number
            if (name.matches("(set|get|update)[A-Z]\\w*") && !t.getArguments().isEmpty() && t.getArguments().get(0) instanceof LiteralTree index) ignored.add(index);
            return super.visitMethodInvocation(t, v);
        }

        @Override
        public Void visitMemberSelect(MemberSelectTree t, Void v) {
            if (t.getIdentifier().contentEquals("FLOOR") && t.getExpression().toString().endsWith("RoundingMode")) floors.add(type);
            return super.visitMemberSelect(t, v);
        }

        @Override
        public Void visitIdentifier(com.sun.source.tree.IdentifierTree t, Void v) {
            if (t.getName().contentEquals("FLOOR")) floors.add(type);    // a static import of RoundingMode.FLOOR
            return super.visitIdentifier(t, v);
        }

        @Override
        public Void visitLiteral(LiteralTree t, Void v) {
            Object value = t.getValue();
            if (value instanceof String s) {
                texts.add(new Text(type, s));
            } else if (value instanceof Number n) {
                String repr = String.valueOf(n);
                allNumbers.computeIfAbsent(type, k -> new TreeSet<>()).add(repr);
                if (!inConstant && !ignored.contains(t) && !Set.of("0", "1", "2", "-1", "0.0", "1.0").contains(repr)) {
                    bareNumbers.computeIfAbsent(type, k -> new ArrayList<>()).add(repr);
                }
            }
            return null;
        }

        private void enter(Runnable body) {
            depth++;
            deepest = Math.max(deepest, depth);
            body.run();
            depth--;
        }

        @Override
        public Void visitIf(IfTree t, Void v) {
            scan(t.getCondition(), v);
            enter(() -> scan(t.getThenStatement(), v));
            StatementTree e = t.getElseStatement();
            if (e instanceof IfTree other) visitIf(other, v);        // "else if" stays on the same level
            else if (e != null) enter(() -> scan(e, v));
            return null;
        }

        @Override
        public Void visitSwitch(SwitchTree t, Void v) {
            enter(() -> super.visitSwitch(t, v));
            return null;
        }
    }
}
