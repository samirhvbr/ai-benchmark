package br.netx.chamados;

import java.util.regex.Pattern;

/**
 * Decide de quem é a culpa quando o processo-filho do verificador de memória não termina bem. Três origens, e só a primeira vira reprovação:
 * (1) a falta de memória que a condição prevista provoca no código avaliado; (2) uma falha do avaliador ou do ambiente (o filho nem chegou a rodar o nosso
 * código, foi morto, perdeu o banco); (3) qualquer outra coisa, cuja origem não está estabelecida: não se atribui ao candidato nem se absolve.
 */
final class ClassificaFilho {
    enum Origem { CONCLUIU, MEMORIA_DO_CODIGO_AVALIADO, AMBIENTE_OU_AVALIADOR, NAO_ESTABELECIDA }

    record Resultado(Origem origem, String detalhe) {}

    private static final Pattern MEMORIA = Pattern.compile("(?is).*(OutOfMemoryError|Java heap space|GC overhead limit|Ran out of memory).*");
    private static final Pattern AMBIENTE = Pattern.compile("(?is).*(connection refused|connection attempt failed|too many clients|terminating connection|no route to host|"
            + "the connection has been closed|timed out waiting|too many open files|unable to create native thread|cannot allocate memory).*");

    private ClassificaFilho() {}

    /** @param terminou o processo terminou sozinho (false = estourou o prazo do avaliador e foi encerrado). */
    static Resultado classificar(boolean terminou, int codigo, String saida) {
        if (terminou && codigo == 0 && saida.contains("FILHO-OK")) return new Resultado(Origem.CONCLUIU, "");
        if (!terminou) return new Resultado(Origem.AMBIENTE_OU_AVALIADOR, "o avaliador encerrou o processo-filho por prazo");
        if (!saida.contains("FILHO-FALHOU")) {
            // o nosso código não chegou a dizer nada: a JVM não subiu, foi morta (137/143) ou caiu por conta própria
            return new Resultado(Origem.AMBIENTE_OU_AVALIADOR, "o processo-filho terminou com código " + codigo + " sem relatar nada: " + resumo(saida));
        }
        String falha = saida.substring(saida.indexOf("FILHO-FALHOU"));
        // o ambiente primeiro: "unable to create native thread" também é um OutOfMemoryError, mas é o limite de processos do contêiner, não o heap do código avaliado
        if (AMBIENTE.matcher(falha).matches()) return new Resultado(Origem.AMBIENTE_OU_AVALIADOR, resumo(falha));
        if (MEMORIA.matcher(falha).matches()) return new Resultado(Origem.MEMORIA_DO_CODIGO_AVALIADO, resumo(falha));
        return new Resultado(Origem.NAO_ESTABELECIDA, resumo(falha));
    }

    private static String resumo(String s) {
        String limpo = s.replaceAll("\\s+", " ").strip();
        return limpo.length() > 1800 ? limpo.substring(0, 1800) + "…" : limpo;
    }
}
