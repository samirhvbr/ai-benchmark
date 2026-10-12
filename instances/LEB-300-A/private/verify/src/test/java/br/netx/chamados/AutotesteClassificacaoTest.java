package br.netx.chamados;

import org.junit.jupiter.api.Test;

import static br.netx.chamados.ClassificaFilho.Origem.*;
import static org.junit.jupiter.api.Assertions.assertEquals;

/** Autoteste do avaliador (não emite veredito de falha nenhuma; roda por operador/autoteste-verificadores.sh). */
class AutotesteClassificacaoTest {
    @Test
    void concluiuEConcluiu() {
        assertEquals(CONCLUIU, ClassificaFilho.classificar(true, 0, "FILHO-OK\n").origem());
    }

    @Test
    void faltaDeMemoriaDoCodigoAvaliadoEDoCandidato() {
        assertEquals(MEMORIA_DO_CODIGO_AVALIADO, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU java.lang.OutOfMemoryError: Java heap space @ x <- ").origem());
        assertEquals(MEMORIA_DO_CODIGO_AVALIADO, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU br.netx.chamados.db.Db$DbException: Ran out of memory retrieving query results. <- org.postgresql.util.PSQLException").origem());
    }

    @Test
    void aMemoriaQueSeManifestaComoOutraExcecaoContaComoMemoriaQuandoACausaMostra() {
        // reproduzido no código legado: o try-with-resources recebe a mesma instância pré-alocada de OutOfMemoryError como erro principal e como erro do close()
        String cadeia = "FILHO-FALHOU java.lang.IllegalArgumentException: Self-suppression not permitted @ java.base/java.lang.Throwable.addSuppressed(Throwable.java:1096) "
                + "@ br.netx.chamados.db.Db$Tx.list(Db.java:77) <- java.lang.OutOfMemoryError: Java heap space <- ";
        assertEquals(MEMORIA_DO_CODIGO_AVALIADO, ClassificaFilho.classificar(true, 3, cadeia).origem());
        assertEquals(NAO_ESTABELECIDA, ClassificaFilho.classificar(true, 3, cadeia.replace("<- java.lang.OutOfMemoryError: Java heap space <- ", "")).origem(),
                "a mesma exceção sem a causa à vista não se atribui a ninguém");
    }

    @Test
    void falhaDoAmbienteNaoVira_reprovacao() {
        assertEquals(AMBIENTE_OU_AVALIADOR, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU org.postgresql.util.PSQLException: Connection refused").origem());
        assertEquals(AMBIENTE_OU_AVALIADOR, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU java.lang.OutOfMemoryError: unable to create native thread").origem(),
                "é o limite de processos do contêiner, não o heap do código avaliado");
        assertEquals(AMBIENTE_OU_AVALIADOR, ClassificaFilho.classificar(true, 137, "").origem(), "morto de fora, sem relato");
        assertEquals(AMBIENTE_OU_AVALIADOR, ClassificaFilho.classificar(true, 1, "Error: Could not find or load main class").origem());
        assertEquals(AMBIENTE_OU_AVALIADOR, ClassificaFilho.classificar(false, -1, "FILHO-OK").origem(), "prazo do avaliador");
    }

    @Test
    void origemNaoEstabelecidaNaoEAtribuidaNemAbsolvida() {
        assertEquals(NAO_ESTABELECIDA, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU java.lang.IllegalArgumentException: x @ a.B.c(B.java:1) <- ").origem());
        assertEquals(NAO_ESTABELECIDA, ClassificaFilho.classificar(true, 3, "FILHO-FALHOU java.lang.IllegalStateException: falha sintética <- ").origem());
    }

    @Test
    void soOSistemaQuebradoNoCenarioViraVeredito() {
        assertEquals(true, VerificaExtension.oSistemaRespondeuErroAoCenario(new IllegalStateException("login de admin@a.test falhou: {\"erro\":{}}")));
        assertEquals(true, VerificaExtension.oSistemaRespondeuErroAoCenario(new IllegalStateException("/chamados?tamanho=10 respondeu 500: erro")));
        assertEquals(false, VerificaExtension.oSistemaRespondeuErroAoCenario(new NullPointerException()), "defeito do próprio verificador");
        assertEquals(false, VerificaExtension.oSistemaRespondeuErroAoCenario(new java.io.UncheckedIOException(new java.io.IOException("reset"))), "rede");
        assertEquals(false, VerificaExtension.oSistemaRespondeuErroAoCenario(new IllegalStateException(new java.sql.SQLException("banco"))), "banco");
        assertEquals(false, VerificaExtension.oSistemaRespondeuErroAoCenario(new IllegalStateException("outra coisa")));
    }
}
