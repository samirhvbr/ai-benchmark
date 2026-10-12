package br.netx.chamados;

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

/**
 * Falhas de estrutura e de limpeza (Template R). Cada propriedade é medida no código e no bytecode, sem nomes: o veredito é um teto
 * para a nota de R3 (full, half ou none), nunca uma nota. Os limiares foram calibrados contra o código de referência e o legado.
 */
class V04_EstruturaTest {
    static Estrutura e;
    static String raiz;

    @BeforeAll
    static void ler() {
        e = new Estrutura(Path.of("src/main/java"), Path.of("target/classes"));
        raiz = Estrutura.RAIZ;
    }

    /** Camada HTTP, por dependência: quem usa o servidor do JDK ou quem usa uma classe que o usa. A raiz de composição fica de fora. */
    private static Set<String> camadaHttp() {
        Set<String> servidor = e.quemReferencia("com.sun.net.httpserver.");
        Set<String> camada = new TreeSet<>(servidor);
        Map<String, Set<String>> dep = e.dependenciasPorClasse();
        for (var c : dep.entrySet()) if (!servidor.contains(c.getKey()) && c.getValue().stream().anyMatch(servidor::contains)) camada.add(c.getKey());
        camada.removeIf(V04_EstruturaTest::naRaiz);
        // a camada é também tudo o que mora nos mesmos pacotes: mover o banco para uma classe vizinha do manipulador não o tira da camada
        Set<String> pacotes = new TreeSet<>();
        for (String c : camada) pacotes.add(c.substring(0, c.lastIndexOf('.')));
        for (String c : dep.keySet()) if (pacotes.contains(c.substring(0, c.lastIndexOf('.')))) camada.add(c);
        return camada;
    }

    private static boolean naRaiz(String classe) {
        return classe.substring(0, classe.lastIndexOf('.')).equals(raiz);
    }

    /**
     * O defeito é uma classe que concentra domínios sem relação. Medido pelo que o código é, não por quantas linhas tem: (1) a classe, vista com o que herda,
     * tem dois ou mais grupos de métodos desconectados (nenhum campo de colaborador e nenhuma chamada em comum), cada um com trabalho de verdade; (2) a classe
     * depende de muitas classes de acesso a dados diferentes. Os dois juntos são "none"; um só é "half", para o juiz decidir; nenhum é "full".
     * Linhas e quantidade de métodos entram só na mensagem, como informação: nem uma classe longa e coesa é rejeitada por isso, nem reformatar ou
     * encurtar linhas aprova uma classe que continua misturando domínios.
     */
    @Test
    @Verifica({"ARCH-001"})
    void arch001_nenhumaClasseMisturaDominiosSemRelacao() {
        List<Estrutura.Coesao> todas = e.coesoes();
        List<String> ambos = new ArrayList<>(), so1 = new ArrayList<>(), so2 = new ArrayList<>();
        for (Estrutura.Coesao c : todas) {
            if (naRaiz(c.classe())) continue;          // a raiz de composição conhece todo mundo por definição
            boolean varios = c.variosDominios(), largo = c.largura() >= LARGURA_DE_ACESSO_A_DADOS;
            String nome = c.classe().replaceAll(".*\\.", "");
            String detalhe = nome + " (" + c.dominios().size() + " domínios: " + c.dominios().stream().map(g -> g.size() + " métodos").toList() + "; acessa " + c.largura() + " classes de dados: " + c.acessoADados() + ")";
            if (varios && largo) ambos.add(detalhe);
            else if (varios) so1.add(detalhe);
            else if (largo) so2.add(detalhe);
        }
        var maior = e.linhasDaClasse.entrySet().stream().max(Map.Entry.comparingByValue()).orElseThrow();
        String info = " [informativo: a maior classe tem " + maior.getValue() + " linhas úteis (" + maior.getKey().replaceAll(".*\\.", "") + ")]";
        String resultado = !ambos.isEmpty() ? "none" : !so1.isEmpty() || !so2.isEmpty() ? "half" : "full";
        String msg = !ambos.isEmpty() ? "classe que mistura domínios sem relação e depende de muitas classes de dados: " + ambos
                : !so1.isEmpty() ? "classe com domínios desconectados, mas poucas classes de dados: " + so1
                : !so2.isEmpty() ? "classe sem domínios desconectados, mas que depende de muitas classes de dados: " + so2
                : "nenhuma classe mistura domínios sem relação";
        Resultado.r("ARCH-001", resultado, msg + info);
    }

    /** Uma classe depende de quantas classes de acesso a dados diferentes para ser considerada larga. A referência chega a 3; o legado, a 6. */
    static final int LARGURA_DE_ACESSO_A_DADOS = 5;

    @Test
    @Verifica({"ARCH-002"})
    void arch002_aCamadaHttpNaoFalaComOBanco() {
        Set<String> http = camadaHttp();
        Set<String> comBanco = e.quemReferencia("java.sql.", "javax.sql.");
        List<String> violam = http.stream().filter(comBanco::contains).map(c -> c.replaceAll(".*\\.", "")).toList();
        Resultado.r("ARCH-002", violam.isEmpty() ? "full" : "none", violam.isEmpty() ? "nenhuma classe da camada HTTP (" + http.size() + ") referencia java.sql" : "classes da camada HTTP que referenciam java.sql: " + violam);
    }

    @Test
    @Verifica({"ARCH-004"})
    void arch004_aRegraDosDestinatariosNaoEstaEscritaDuasVezes() {
        Map<String, Set<String>> onde = new TreeMap<>();
        for (Estrutura.Texto t : e.textos) {
            String norma = t.valor().toLowerCase().replaceAll("\\s+", " ").strip();
            if (norma.length() >= 60 && norma.contains("select ") && norma.contains(" from ") && norma.contains("usuario")) onde.computeIfAbsent(norma, k -> new TreeSet<>()).add(t.classe().replaceAll(".*\\.", ""));
        }
        List<String> repetidas = onde.entrySet().stream().filter(x -> x.getValue().size() > 1).map(x -> x.getValue() + " <- " + x.getKey().substring(0, 60) + "…").toList();
        Resultado.r("ARCH-004", repetidas.isEmpty() ? "full" : "none", repetidas.isEmpty() ? "nenhuma consulta de destinatários escrita em mais de uma classe" : "consultas de destinatários escritas em mais de uma classe: " + repetidas);
    }

    @Test
    @Verifica({"ARCH-008"})
    void arch008_semCicloEntrePacotes() {
        Map<String, Set<String>> grafo = new TreeMap<>();
        for (var p : e.dependenciasPorPacote().entrySet()) {
            if (p.getKey().equals(raiz)) continue;                         // a raiz de composição conhece todos e a configuração mora nela
            Set<String> alvos = new TreeSet<>(p.getValue());
            alvos.remove(raiz);
            grafo.put(p.getKey(), alvos);
        }
        List<List<String>> ciclos = Estrutura.ciclos(grafo);
        int pacotes = e.dependenciasPorPacote().size();
        boolean ok = ciclos.isEmpty() && pacotes >= PACOTES_MINIMOS;
        Resultado.r("ARCH-008", ok ? "full" : "none", ciclos.isEmpty() ? pacotes + " pacotes, sem ciclo" + (pacotes < PACOTES_MINIMOS ? " (mas o mínimo é " + PACOTES_MINIMOS + ": juntar pacotes não resolve o ciclo)" : "")
                : "ciclo entre pacotes: " + ciclos.stream().map(c -> c.stream().map(x -> x.replace(raiz + ".", "")).sorted().toList()).toList());
    }

    static final int PACOTES_MINIMOS = 9;

    @Test
    @Verifica({"ARCH-009"})
    void arch009_osLimitesTemNome() {
        Set<String> http = camadaHttp();
        List<String> soltos = new ArrayList<>();
        for (var c : e.numeros.entrySet()) {
            if (http.contains(c.getKey()) || naRaiz(c.getKey())) continue;
            for (String n : c.getValue()) {
                boolean inteiro = n.matches("-?\\d+");
                if (inteiro && Math.abs(Long.parseLong(n)) >= 100 && Math.abs(Long.parseLong(n)) <= 599) continue;   // códigos de resposta e percentuais
                soltos.add(c.getKey().replaceAll(".*\\.", "") + ":" + n);
            }
        }
        // full: no máximo o que a referência tem; half: um nomeamento parcial deixa até 2 a mais; none: acima disso
        String resultado = soltos.size() <= BASE_DE_NUMEROS ? "full" : soltos.size() <= BASE_DE_NUMEROS + MARGEM_PARCIAL ? "half" : "none";
        Resultado.r("ARCH-009", resultado, soltos.size() + " números sem nome fora da camada HTTP e da raiz (a referência tem " + BASE_DE_NUMEROS + "; até +" + MARGEM_PARCIAL + " é nomeamento parcial): " + soltos);
    }

    static final int BASE_DE_NUMEROS = 6;
    static final int MARGEM_PARCIAL = 2;

    @Test
    @Verifica({"ARCH-011"})
    void arch011_aClasseQueFormataOCsvNaoTocaOBanco() {
        Set<String> comBanco = e.quemReferencia("java.sql.", "javax.sql.");
        Set<String> formatam = new TreeSet<>();
        for (Estrutura.Texto t : e.textos) if (t.valor().toLowerCase().contains("titulo;cliente;prioridade")) formatam.add(t.classe());
        if (formatam.isEmpty()) { Resultado.inconclusivo("ARCH-011", "nenhuma classe contém o cabeçalho do CSV"); return; }
        List<String> violam = formatam.stream().filter(comBanco::contains).map(c -> c.replaceAll(".*\\.", "")).toList();
        Resultado.r("ARCH-011", violam.isEmpty() ? "full" : "none", violam.isEmpty() ? "a classe que escreve o cabeçalho do CSV não referencia java.sql" : "a classe que escreve o cabeçalho do CSV referencia java.sql: " + violam);
    }

    @Test
    @Verifica({"CLN-001"})
    void cln001_nenhumMetodoLongoDemais() {
        var maior = e.metodos.stream().max(Comparator.comparingInt(Estrutura.Metodo::linhas)).orElseThrow();
        var maisInstrucoes = e.metodos.stream().max(Comparator.comparingInt(Estrutura.Metodo::instrucoes)).orElseThrow();
        boolean ok = maior.linhas() <= LIMITE_METODO && maisInstrucoes.instrucoes() <= LIMITE_INSTRUCOES;
        Resultado.r("CLN-001", ok ? "full" : "none",
                "o maior método tem " + maior.linhas() + " linhas úteis (" + maior.classe().replaceAll(".*\\.", "") + "." + maior.nome() + "; limite " + LIMITE_METODO + ") e o que tem mais instruções tem "
                        + maisInstrucoes.instrucoes() + " (" + maisInstrucoes.classe().replaceAll(".*\\.", "") + "." + maisInstrucoes.nome() + "; limite " + LIMITE_INSTRUCOES + ")");
    }

    static final int LIMITE_METODO = 40;
    static final int LIMITE_INSTRUCOES = 40;

    @Test
    @Verifica({"CLN-002"})
    void cln002_nenhumCorpoCopiado() {
        Map<String, List<String>> corpos = new TreeMap<>();
        for (Estrutura.Metodo m : e.metodos) if (m.corpoCanonico().length() >= 150) corpos.computeIfAbsent(m.corpoCanonico(), k -> new ArrayList<>()).add(m.classe().replaceAll(".*\\.", "") + "." + m.nome());
        List<List<String>> copiados = corpos.values().stream().filter(l -> l.size() > 1).toList();
        Resultado.r("CLN-002", copiados.isEmpty() ? "full" : "none", copiados.isEmpty() ? "nenhum corpo de método repetido em outro" : "corpos repetidos: " + copiados);
    }

    @Test
    @Verifica({"CLN-005"})
    void cln005_nenhumCodigoComentado() {
        Resultado.r("CLN-005", e.codigoComentado.isEmpty() ? "full" : "none",
                e.codigoComentado.isEmpty() ? "nenhuma linha de comentário parece código" : e.codigoComentado.size() + " linhas de comentário parecem código: " + e.codigoComentado);
    }

    @Test
    @Verifica({"CLN-007"})
    void cln007_nenhumaDecisaoAninhadaDemais() {
        var maior = e.metodos.stream().max(Comparator.comparingInt(Estrutura.Metodo::aninhamento)).orElseThrow();
        Resultado.r("CLN-007", maior.aninhamento() <= LIMITE_ANINHAMENTO ? "full" : "none",
                "a maior sequência de decisões aninhadas tem " + maior.aninhamento() + " níveis (" + maior.classe().replaceAll(".*\\.", "") + "." + maior.nome() + "); o limite é " + LIMITE_ANINHAMENTO);
    }

    static final int LIMITE_ANINHAMENTO = 4;
}
