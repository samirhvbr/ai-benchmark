package br.netx.chamados;

import com.sun.source.tree.ClassTree;
import com.sun.source.tree.CompilationUnitTree;
import com.sun.source.tree.BlockTree;
import com.sun.source.tree.MemberSelectTree;
import com.sun.source.tree.MethodInvocationTree;
import com.sun.source.tree.IfTree;
import com.sun.source.tree.LiteralTree;
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
import com.tngtech.archunit.core.domain.JavaField;
import com.tngtech.archunit.core.domain.JavaFieldAccess;
import com.tngtech.archunit.core.domain.JavaMethod;
import com.tngtech.archunit.core.domain.JavaMethodCall;
import com.tngtech.archunit.core.domain.JavaModifier;
import com.tngtech.archunit.core.importer.ClassFileImporter;

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
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.TreeSet;
import java.util.regex.Pattern;
import java.util.stream.Stream;

/**
 * Leitura estrutural do código sob avaliação, sem depender de nomes: a árvore sintática do javac (métodos, aninhamento, literais) e o grafo de
 * dependências do bytecode (ArchUnit). Mede o que o código é, não onde ele está.
 */
final class Estrutura {
    static final String RAIZ = "br.netx.chamados";

    /** Um método, com o que a leitura mede. */
    /** {@code aninhamento} é a profundidade de decisões (if/switch) encadeadas; um "else if" não aprofunda. */
    record Metodo(String classe, String nome, int linhas, int aninhamento, String corpoNormalizado, int instrucoes, String corpoCanonico) {}

    /** Um literal de texto, com a classe de topo onde está escrito. */
    record Texto(String classe, String valor) {}

    final List<Metodo> metodos = new ArrayList<>();
    final List<Texto> textos = new ArrayList<>();
    /** Literais numéricos soltos (fora de declarações de constante), por classe de topo. */
    final Map<String, List<String>> numeros = new TreeMap<>();
    /** Linhas úteis do arquivo de cada classe de topo. */
    final Map<String, Integer> linhasDaClasse = new TreeMap<>();
    /** Métodos e construtores escritos em cada classe de topo, incluindo as aninhadas. */
    final Map<String, Integer> metodosDaClasse = new TreeMap<>();
    /** Linhas de comentário que parecem código. */
    final List<String> codigoComentado = new ArrayList<>();

    final JavaClasses bytecode;

    Estrutura(Path fontes, Path classes) {
        parse(fontes);
        bytecode = new ClassFileImporter().importPath(classes);
    }

    // ---- bytecode

    static String topo(String nome) {
        int i = nome.indexOf('$');
        return i < 0 ? nome : nome.substring(0, i);
    }

    boolean daAplicacao(String nome) {
        return nome.startsWith(RAIZ + ".") || nome.equals(RAIZ);
    }

    /** Classes de topo da aplicação que a classe de topo dada referencia. */
    Map<String, Set<String>> dependenciasPorClasse() {
        Map<String, Set<String>> mapa = new TreeMap<>();
        for (JavaClass c : bytecode) {
            if (!daAplicacao(c.getName())) continue;
            Set<String> alvos = mapa.computeIfAbsent(topo(c.getName()), k -> new TreeSet<>());
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                String alvo = topo(d.getTargetClass().getName());
                if (daAplicacao(alvo) && !alvo.equals(topo(c.getName()))) alvos.add(alvo);
            }
        }
        return mapa;
    }

    /** Pacotes da aplicação e, para cada um, os pacotes que ele referencia. */
    Map<String, Set<String>> dependenciasPorPacote() {
        Map<String, Set<String>> mapa = new TreeMap<>();
        for (JavaClass c : bytecode) {
            if (!daAplicacao(c.getName())) continue;
            Set<String> alvos = mapa.computeIfAbsent(c.getPackageName(), k -> new TreeSet<>());
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                JavaClass alvo = d.getTargetClass();
                if (daAplicacao(alvo.getName()) && !alvo.getPackageName().equals(c.getPackageName())) alvos.add(alvo.getPackageName());
            }
        }
        return mapa;
    }

    /** Classes de topo que referenciam algum tipo cujo nome começa por um dos prefixos. */
    Set<String> quemReferencia(String... prefixos) {
        Set<String> quem = new TreeSet<>();
        for (JavaClass c : bytecode) {
            if (!daAplicacao(c.getName())) continue;
            for (Dependency d : c.getDirectDependenciesFromSelf()) {
                for (String p : prefixos) if (d.getTargetClass().getName().startsWith(p)) quem.add(topo(c.getName()));
            }
        }
        return quem;
    }

    /** Componentes fortemente conexos com mais de um pacote (Tarjan): os ciclos de pacote. */
    static List<List<String>> ciclos(Map<String, Set<String>> grafo) {
        List<List<String>> saida = new ArrayList<>();
        Map<String, Integer> indice = new TreeMap<>(), baixo = new TreeMap<>();
        java.util.Deque<String> pilha = new java.util.ArrayDeque<>();
        Set<String> naPilha = new TreeSet<>();
        int[] contador = {0};
        for (String v : grafo.keySet()) if (!indice.containsKey(v)) conectar(v, grafo, indice, baixo, pilha, naPilha, contador, saida);
        return saida;
    }

    private static void conectar(String v, Map<String, Set<String>> g, Map<String, Integer> indice, Map<String, Integer> baixo,
                                 java.util.Deque<String> pilha, Set<String> naPilha, int[] contador, List<List<String>> saida) {
        indice.put(v, contador[0]);
        baixo.put(v, contador[0]++);
        pilha.push(v);
        naPilha.add(v);
        for (String w : g.getOrDefault(v, Set.of())) {
            if (!indice.containsKey(w)) {
                conectar(w, g, indice, baixo, pilha, naPilha, contador, saida);
                baixo.put(v, Math.min(baixo.get(v), baixo.get(w)));
            } else if (naPilha.contains(w)) {
                baixo.put(v, Math.min(baixo.get(v), indice.get(w)));
            }
        }
        if (baixo.get(v).equals(indice.get(v))) {
            List<String> componente = new ArrayList<>();
            String w;
            do {
                w = pilha.pop();
                naPilha.remove(w);
                componente.add(w);
            } while (!w.equals(v));
            if (componente.size() > 1) saida.add(componente);
        }
    }


    // ---- coesão: domínios sem relação dentro de uma mesma classe

    /** O que se sabe de uma classe de topo da aplicação, incluindo o que ela herda de outras classes da aplicação. */
    record Coesao(String classe, int metodos, int instrucoes, List<List<String>> dominios, int largura, List<String> acessoADados) {
        /** Há mais de um grupo de métodos desconectados, cada um com trabalho de verdade? */
        boolean variosDominios() {
            return dominios.size() >= 2;
        }
    }

    static final int INSTRUCOES_DE_UM_DOMINIO = 15;
    static final int METODOS_DE_UM_DOMINIO = 2;

    /** Classes da aplicação que escrevem SQL e usam a API JDBC: o acesso a dados. Quem só repassa uma consulta por uma camada própria não entra. */
    Set<String> classesDeAcessoADados() {
        Set<String> comSql = new TreeSet<>();
        for (Texto t : textos) if (t.valor().matches("(?is)^\\s*(select|insert\\s+into|update|delete\\s+from)\\b.*")) comSql.add(t.classe());
        comSql.retainAll(quemReferencia("java.sql.", "javax.sql."));
        return comSql;
    }

    private static String doMetodo(String nome) {
        String n = nome;
        while (n.startsWith("lambda$")) n = n.substring(7).replaceFirst("\\$\\d+$", "");
        return n;
    }

    /**
     * Dois métodos são do mesmo domínio se usam o mesmo estado (um campo de um tipo da aplicação, ou um campo estático) ou se um chama o outro. A classe é
     * vista como um todo com o que herda, então dividir por herança não separa nada. Um grupo só conta como domínio se tem trabalho de verdade.
     */
    List<Coesao> coesoes() {
        Set<String> sql = classesDeAcessoADados();
        Map<String, Set<String>> dep = dependenciasPorClasse();
        List<Coesao> saida = new ArrayList<>();
        for (JavaClass c : bytecode) {
            if (!daAplicacao(c.getName()) || c.getName().contains("$") || c.isInterface() || c.isEnum() || c.isRecord()) continue;
            List<JavaClass> ef = new ArrayList<>(List.of(c));
            for (JavaClass s = c.getRawSuperclass().orElse(null); s != null && daAplicacao(s.getName()); s = s.getRawSuperclass().orElse(null)) ef.add(s);
            Map<String, Set<String>> usa = new TreeMap<>();
            Map<String, Set<String>> chama = new TreeMap<>();
            Map<String, Integer> instrucoes = new TreeMap<>();
            for (JavaClass k : ef) {
                for (JavaMethod m : k.getMethods()) {
                    String nome = doMetodo(m.getName());
                    if (nome.startsWith("access$") || nome.equals("<clinit>")) continue;
                    usa.computeIfAbsent(nome, x -> new TreeSet<>());
                    for (JavaFieldAccess a : m.getFieldAccesses()) {
                        JavaField f = a.getTarget().resolveMember().orElse(null);
                        if (f == null || !ef.contains(f.getOwner())) continue;
                        if (daAplicacao(f.getRawType().getName()) || f.getModifiers().contains(JavaModifier.STATIC)) usa.get(nome).add(f.getName());
                    }
                    for (JavaMethodCall ch : m.getMethodCallsFromSelf()) {
                        if (ef.contains(ch.getTargetOwner())) chama.computeIfAbsent(nome, x -> new TreeSet<>()).add(doMetodo(ch.getTarget().getName()));
                    }
                }
                for (Metodo mt : metodos) if (mt.classe().equals(k.getName())) instrucoes.merge(mt.nome(), mt.instrucoes(), Integer::sum);
            }
            usa.keySet().removeIf(n -> n.equals("<init>"));
            Map<String, String> pai = new HashMap<>();
            for (String m : usa.keySet()) pai.put(m, m);
            java.util.function.UnaryOperator<String> raiz = x -> {
                while (!pai.get(x).equals(x)) x = pai.get(x);
                return x;
            };
            Map<String, String> donoDoCampo = new HashMap<>();
            for (var en : usa.entrySet()) {
                for (String f : en.getValue()) {
                    String outro = donoDoCampo.putIfAbsent(f, en.getKey());
                    if (outro != null) pai.put(raiz.apply(en.getKey()), raiz.apply(outro));
                }
            }
            for (var en : chama.entrySet()) {
                if (!pai.containsKey(en.getKey())) continue;
                for (String d : en.getValue()) if (pai.containsKey(d)) pai.put(raiz.apply(en.getKey()), raiz.apply(d));
            }
            Map<String, List<String>> grupos = new TreeMap<>();
            for (String m : usa.keySet()) grupos.computeIfAbsent(raiz.apply(m), x -> new ArrayList<>()).add(m);
            List<List<String>> dominios = new ArrayList<>();
            for (List<String> g : grupos.values()) {
                int trabalho = g.stream().mapToInt(n -> instrucoes.getOrDefault(n, 0)).sum();
                if (g.size() >= METODOS_DE_UM_DOMINIO && trabalho >= INSTRUCOES_DE_UM_DOMINIO) dominios.add(g);
            }
            Set<String> acesso = new TreeSet<>();
            for (JavaClass k : ef) for (String d : dep.getOrDefault(k.getName(), Set.of())) {
                if (sql.contains(d) && ef.stream().noneMatch(x -> x.getName().equals(d))) acesso.add(d.substring(d.lastIndexOf('.') + 1));
            }
            int linhas = ef.stream().mapToInt(k -> metodosDaClasse.getOrDefault(k.getName(), 0)).sum();
            int totalInstr = instrucoes.values().stream().mapToInt(Integer::intValue).sum();
            saida.add(new Coesao(c.getName(), linhas, totalInstr, dominios, acesso.size(), new ArrayList<>(acesso)));
        }
        return saida;
    }

    // ---- fontes

    private void parse(Path fontes) {
        List<Path> arquivos;
        try (Stream<Path> s = Files.walk(fontes)) {
            arquivos = s.filter(p -> p.toString().endsWith(".java")).sorted().toList();
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        JavaCompiler jc = ToolProvider.getSystemJavaCompiler();
        try (StandardJavaFileManager fm = jc.getStandardFileManager(null, null, StandardCharsets.UTF_8)) {
            Iterable<? extends JavaFileObject> objetos = fm.getJavaFileObjectsFromPaths(arquivos);
            JavacTask task = (JavacTask) jc.getTask(null, fm, d -> {}, List.of("-proc:none"), null, objetos);
            SourcePositions sp = Trees.instance(task).getSourcePositions();
            for (CompilationUnitTree cu : task.parse()) {
                String texto = cu.getSourceFile().getCharContent(true).toString();
                String pacote = cu.getPackageName() == null ? "" : cu.getPackageName().toString();
                for (Tree t : cu.getTypeDecls()) {
                    if (!(t instanceof ClassTree ct)) continue;
                    String classe = (pacote.isEmpty() ? "" : pacote + ".") + ct.getSimpleName();
                    linhasDaClasse.put(classe, linhasUteis(texto));
                    new Leitor(cu, sp, texto, classe).scan(ct, null);
                    comentariosQueParecemCodigo(classe, texto);
                }
            }
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
    }

    private static final Set<String> PALAVRAS = Set.of("abstract", "assert", "boolean", "break", "byte", "case", "catch", "char", "class", "continue", "default", "do",
            "double", "else", "enum", "extends", "final", "finally", "float", "for", "if", "implements", "import", "instanceof", "int", "interface", "long", "new", "package",
            "private", "protected", "public", "return", "short", "static", "super", "switch", "synchronized", "this", "throw", "throws", "try", "var", "void", "volatile",
            "while", "true", "false", "null", "record", "yield");
    private static final Pattern PEDACO = Pattern.compile("\"(?:[^\"\\\\]|\\\\.)*\"|'(?:[^'\\\\]|\\\\.)*'|[A-Za-z_$][A-Za-z0-9_$]*|\\s+|.", Pattern.DOTALL);

    /** O texto do corpo sem espaços e com todo identificador trocado por I: o que sobra é a forma, então renomear variáveis não esconde uma cópia. */
    static String canonico(String corpo) {
        StringBuilder b = new StringBuilder();
        java.util.regex.Matcher m = PEDACO.matcher(corpo);
        while (m.find()) {
            String p = m.group();
            if (p.isBlank()) continue;
            b.append(Character.isJavaIdentifierStart(p.charAt(0)) && !PALAVRAS.contains(p) ? "I" : p);
        }
        return b.toString();
    }

    static int linhasUteis(String trecho) {
        int n = 0;
        boolean emBloco = false;
        for (String bruta : trecho.split("\n", -1)) {
            String l = bruta.strip();
            if (emBloco) {
                if (l.contains("*/")) emBloco = false;
                continue;
            }
            if (l.isEmpty() || l.startsWith("//")) continue;
            if (l.startsWith("/*")) {
                if (!l.contains("*/")) emBloco = true;
                continue;
            }
            n++;
        }
        return n;
    }

    private static final Pattern PARECE_CODIGO = Pattern.compile("^\\s*//\\s*(.*[;{}]\\s*$|[\\w.]+\\s*=\\s*.+|[\\w.]+\\([^)]*\\).*)");

    private void comentariosQueParecemCodigo(String classe, String texto) {
        java.util.regex.Matcher bloco = Pattern.compile("/\\*(?!\\*)(.*?)\\*/", Pattern.DOTALL).matcher(texto);
        while (bloco.find()) {
            for (String l : bloco.group(1).split("\n")) {
                String conteudo = l.strip().replaceFirst("^\\*\\s*", "");
                if (conteudo.endsWith(";") || conteudo.endsWith("{") || conteudo.endsWith("}") || conteudo.matches("^[\\w.]+\\s*\\(.*\\)\\s*[;{]?$")) {
                    codigoComentado.add(classe + " (bloco) " + conteudo);
                }
            }
        }
        int n = 0;
        for (String l : texto.split("\n", -1)) {
            n++;
            if (!l.strip().startsWith("//") || l.strip().startsWith("///")) continue;
            String conteudo = l.strip().substring(2).strip();
            if (conteudo.endsWith(";") || conteudo.endsWith("{") || conteudo.endsWith("}") || conteudo.matches("^[\\w.]+\\s*\\(.*\\)\\s*[;{]?$")) {
                codigoComentado.add(classe + ":" + n + " " + conteudo);
            }
        }
    }

    /** Percorre uma classe de topo medindo métodos, aninhamento e literais. */
    private final class Leitor extends TreeScanner<Void, Void> {
        private final CompilationUnitTree cu;
        private final SourcePositions sp;
        private final String texto;
        private final String classe;
        private int profundidade;
        private int maior;
        private boolean emConstante;

        Leitor(CompilationUnitTree cu, SourcePositions sp, String texto, String classe) {
            this.cu = cu;
            this.sp = sp;
            this.texto = texto;
            this.classe = classe;
        }

        private int nivelClasse;
        private int instrucoes;

        /** Conta cada instrução (não os blocos) do corpo do método, aninhadas e em lambdas incluídas: juntar linhas não a diminui. */
        @Override
        public Void scan(Tree t, Void v) {
            if (t instanceof StatementTree && !(t instanceof BlockTree)) instrucoes++;
            return super.scan(t, v);
        }

        @Override
        public Void visitClass(ClassTree t, Void v) {
            nivelClasse++;
            try {
                return super.visitClass(t, v);
            } finally {
                nivelClasse--;
            }
        }

        @Override
        public Void visitMethod(MethodTree m, Void v) {
            if (nivelClasse == 1) metodosDaClasse.merge(classe, 1, Integer::sum);
            if (m.getBody() == null) return super.visitMethod(m, v);
            int salvoProf = profundidade, salvoMaior = maior, salvasInstrucoes = instrucoes;
            profundidade = 0;
            maior = 0;
            instrucoes = 0;
            super.visitMethod(m, v);
            int dessaVez = instrucoes;
            instrucoes = salvasInstrucoes;
            long ini = sp.getStartPosition(cu, m.getBody()), fim = sp.getEndPosition(cu, m.getBody());
            String corpo = texto.substring((int) ini, (int) fim);
            String nome = m.getName().contentEquals("<init>") ? "<init>" : m.getName().toString();
            metodos.add(new Metodo(classe, nome, linhasUteis(corpo), maior, corpo.replaceAll("\\s+", ""), dessaVez, canonico(corpo)));
            profundidade = salvoProf;
            maior = salvoMaior;
            return null;
        }

        @Override
        public Void visitVariable(VariableTree t, Void v) {
            boolean antes = emConstante;
            var mods = t.getModifiers().getFlags();
            if (mods.contains(javax.lang.model.element.Modifier.STATIC) && mods.contains(javax.lang.model.element.Modifier.FINAL)) emConstante = true;
            super.visitVariable(t, v);
            emConstante = antes;
            return null;
        }

        @Override
        public Void visitBlock(BlockTree t, Void v) {
            boolean antes = emConstante;
            if (t.isStatic()) emConstante = true;
            super.visitBlock(t, v);
            emConstante = antes;
            return null;
        }

        @Override
        public Void visitMethodInvocation(MethodInvocationTree t, Void v) {
            String nome = t.getMethodSelect() instanceof MemberSelectTree m ? m.getIdentifier().toString() : t.getMethodSelect().toString();
            if (nome.matches("(set|get|update)[A-Z]\\w*") && !t.getArguments().isEmpty() && t.getArguments().get(0) instanceof LiteralTree indice) ignorados.add(indice);
            return super.visitMethodInvocation(t, v);
        }

        private final Set<LiteralTree> ignorados = new java.util.HashSet<>();

        @Override
        public Void visitLiteral(LiteralTree t, Void v) {
            Object valor = t.getValue();
            if (valor instanceof String s) {
                textos.add(new Texto(classe, s));
            } else if (valor instanceof Number n && !emConstante && !ignorados.contains(t)) {
                String repr = String.valueOf(n);
                if (!Set.of("0", "1", "2", "-1", "0.0", "1.0").contains(repr)) numeros.computeIfAbsent(classe, k -> new ArrayList<>()).add(repr);
            }
            return null;
        }

        private void entrar(Runnable corpo) {
            profundidade++;
            maior = Math.max(maior, profundidade);
            corpo.run();
            profundidade--;
        }

        @Override
        public Void visitIf(IfTree t, Void v) {
            scan(t.getCondition(), v);
            entrar(() -> scan(t.getThenStatement(), v));
            StatementTree e = t.getElseStatement();
            if (e instanceof IfTree outro) visitIf(outro, v);        // "else if" fica no mesmo nível
            else if (e != null) entrar(() -> scan(e, v));
            return null;
        }

        @Override
        public Void visitSwitch(SwitchTree t, Void v) {
            entrar(() -> super.visitSwitch(t, v));
            return null;
        }

    }
}
