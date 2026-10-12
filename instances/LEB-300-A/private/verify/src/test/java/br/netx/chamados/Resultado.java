package br.netx.chamados;

import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;

/** Onde cada verificador deixa o seu veredito: um arquivo JSON por falha, no formato de scoring/probe-result.schema.json. */
final class Resultado {
    static final Path DIR = Path.of(System.getProperty("leb.resultados", "target/verificacao"));

    private Resultado() {}

    /** Falha de correção (Template C): a prova decide C3. */
    static void c(String id, boolean corrigida, String msg, String... naoObservado) {
        // só um veredito favorável precisa dizer até onde a prova chega; uma reprovação já diz o que foi visto
        String[] todos = corrigida ? juntar(id, naoObservado) : new String[0];
        escrever(id, "{\"id\":" + texto(id) + ",\"corrigida\":" + corrigida + ",\"proves\":[\"C3\"]" + naoObservado(todos) + ",\"msg\":" + texto(msg + (corrigida ? cobertura(id) : "")) + "}");
    }

    /** O que este veredito comprova, repetido ao fim da mensagem: "corrigida" quer dizer que a parte demonstrada vale, não que o requisito inteiro foi provado. */
    private static String cobertura(String id) {
        Cobertura.Item i = Cobertura.de(id);
        return i == null ? "" : " [comprovado: " + i.comprova() + "; não comprovado: " + String.join("; ", i.naoComprova()) + "]";
    }

    private static String[] juntar(String id, String[] extras) {
        Cobertura.Item i = Cobertura.de(id);
        java.util.LinkedHashSet<String> todos = new java.util.LinkedHashSet<>();
        if (i != null) todos.addAll(java.util.List.of(i.naoComprova()));
        todos.addAll(java.util.List.of(extras));
        return todos.toArray(new String[0]);
    }

    /** O que este verificador não consegue observar: o relatório mecânico o repete como "não verificado" e o juiz decide sozinho essa parte. */
    private static String naoObservado(String[] itens) {
        if (itens.length == 0) return "";
        StringBuilder b = new StringBuilder(",\"unobserved\":[");
        for (int i = 0; i < itens.length; i++) b.append(i > 0 ? "," : "").append(texto(itens[i]));
        return b.append("]").toString();
    }

    /** Falha estrutural (Template R): a evidência é o teto de R3. */
    static void r(String id, String resultado, String msg, String... naoObservado) {
        boolean favoravel = !resultado.equals("none");
        String[] todos = favoravel ? juntar(id, naoObservado) : new String[0];
        escrever(id, "{\"id\":" + texto(id) + ",\"result\":" + texto(resultado) + ",\"proves\":[\"R3\"]" + naoObservado(todos) + ",\"msg\":" + texto(msg + (favoravel ? cobertura(id) : "")) + "}");
    }

    /** A infraestrutura falhou ou o cenário não pôde ser montado: a prova é omitida e o motivo registrado. */
    static void inconclusivo(String id, String msg) {
        escrever(id, "{\"id\":" + texto(id) + ",\"inconclusivo\":true,\"msg\":" + texto(msg) + "}");
    }

    /** Isca: não há o que corrigir; o verificador só prova que o código a que ela se parece está certo. */
    static void isca(String id, boolean ok, String msg) {
        escrever("isca-" + id, "{\"id\":" + texto(id) + ",\"isca_passa\":" + ok + ",\"msg\":" + texto(msg) + "}");
    }

    /** Já existe veredito para este id (de verificação ou de isca)? */
    static boolean existe(String id) {
        return Files.exists(DIR.resolve(id + ".json")) || Files.exists(DIR.resolve("isca-" + id + ".json"));
    }

    /** O método do verificador falhou por culpa do sistema avaliado: o veredito é "não corrigida" (ou "none", ou "a isca quebrou"). */
    static void falhou(String id, Throwable causa) {
        String msg = "a verificação não pôde ser concluída porque o sistema avaliado falhou: " + causa;
        if (id.matches("D\\d|I\\d")) isca(id, false, msg);
        else if (id.startsWith("ARCH-") || id.startsWith("CLN-")) r(id, "none", msg);
        else c(id, false, msg);
    }

    private static void escrever(String nome, String json) {
        try {
            Files.createDirectories(DIR);
            Files.writeString(DIR.resolve(nome + ".json"), json + "\n", StandardCharsets.UTF_8);
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        System.out.println("[verificacao] " + json);
    }

    private static String texto(String s) {
        StringBuilder b = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"' -> b.append("\\\"");
                case '\\' -> b.append("\\\\");
                case '\n' -> b.append("\\n");
                case '\r' -> b.append("\\r");
                case '\t' -> b.append("\\t");
                default -> b.append(c < 0x20 ? String.format("\\u%04x", (int) c) : String.valueOf(c));
            }
        }
        return b.append('"').toString();
    }
}
