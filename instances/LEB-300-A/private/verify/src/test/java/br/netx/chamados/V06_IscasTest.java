package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.sql.SQLException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

/**
 * Iscas: trechos que parecem falha e não são. Cada verificador prova a propriedade que o trecho garante; quem "corrige" a isca e quebra a
 * propriedade perde pontos (intervenção) e quem a aponta como falha perde pontos (diagnóstico). O legado e a referência devem passar todos.
 */
class V06_IscasTest extends VBase {

    @Test
    @Verifica({"D1"})
    void d1_aOrdemDeListagemPorPrioridadeContinuaCerta() throws Exception {
        String t = token(ATENDENTE_A);
        for (String p : List.of("BAIXA", "CRITICA", "NORMAL", "ALTA")) abrirChamado(t, cliA, "p " + p, p);
        R r = get("/chamados", t);
        List<String> ordem = new ArrayList<>();
        for (var item : r.j("itens")) ordem.add(item.get("prioridade").asText());
        boolean ok = r.status() == 200 && ordem.equals(List.of("CRITICA", "ALTA", "NORMAL", "BAIXA"));
        Resultado.isca("D1", ok, "ordem da listagem: " + ordem + " (esperado CRITICA, ALTA, NORMAL, BAIXA)");
    }

    @Test
    @Verifica({"D2"})
    void d2_aChaveDeIdempotenciaContinuaDistinguindoOCorpo() throws Exception {
        String t = token(ATENDENTE_A);
        Map<String, Object> corpo = Map.of("cliente_id", cliA, "titulo", "uma vez");
        R primeira = post("/chamados", t, corpo, "Idempotency-Key", "d2-k");
        R igual = post("/chamados", t, corpo, "Idempotency-Key", "d2-k");
        R outro = post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "corpo diferente"), "Idempotency-Key", "d2-k");
        boolean ok = primeira.status() == 201 && igual.status() == 201 && igual.bruto().equals(primeira.bruto()) && outro.status() == 422
                && contar("select count(*) from chamado") == 1;
        Resultado.isca("D2", ok, "primeira=" + primeira.status() + ", repetição=" + igual.status() + " (mesma resposta=" + igual.bruto().equals(primeira.bruto()) + "), corpo diferente=" + outro.status() + ", chamados=" + contar("select count(*) from chamado"));
    }

    @Test
    @Verifica({"D3"})
    void d3_osPrazosPadraoDoManifestoContinuamValendo() throws Exception {
        String t = token(ATENDENTE_A);
        R alta = post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "alta", "prioridade", "ALTA"));
        R critica = post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "critica", "prioridade", "CRITICA"));
        boolean ok = alta.status() == 201 && "2026-03-04T16:00:00Z".equals(alta.j("prazo_resposta").asText()) && "2026-03-05T12:00:00Z".equals(alta.j("prazo_resolucao").asText())
                && critica.status() == 201 && "2026-03-04T13:00:00Z".equals(critica.j("prazo_resposta").asText()) && "2026-03-04T20:00:00Z".equals(critica.j("prazo_resolucao").asText());
        Resultado.isca("D3", ok, "ALTA: " + alta.j("prazo_resposta") + " / " + alta.j("prazo_resolucao") + "; CRITICA: " + critica.j("prazo_resposta") + " / " + critica.j("prazo_resolucao"));
    }

    @Test
    @Verifica({"I1"})
    void i1_duasExecucoesDoLancamentoNuncaLancamDuasVezesAMesmaOrdem() throws Exception {
        final int ordens = 250;
        ordensConcluidas(ordens);
        Encontro enc = new Encontro(instr, ds, 2, sql -> sql.contains("from ordem_servico"), 60_000);
        instr.gancho = enc::aoExecutar;
        ExecutorService pool = Executors.newFixedThreadPool(2);
        try {
            Future<?> a = pool.submit(() -> { try { app.jobs().executarUmaVez(); } finally { enc.saiu(); } });
            Future<?> b = pool.submit(() -> { try { app.jobs().executarUmaVez(); } finally { enc.saiu(); } });
            a.get(120, TimeUnit.SECONDS);
            b.get(120, TimeUnit.SECONDS);
        } finally {
            instr.gancho = null;
            pool.shutdownNow();
        }
        if (enc.estourou) { Resultado.inconclusivo("I1", "o encontro estourou o teto"); return; }
        long total = contar("select count(*) from lancamento");
        long distintas = contar("select count(distinct ordem_servico_id) from lancamento");
        boolean ok = total == ordens && distintas == ordens;
        String msg = "duas execuções simultâneas numa JVM lançaram " + total + " vezes " + distintas + " ordens distintas (esperado " + ordens + ")";
        if (ok) {
            // várias instâncias em processos independentes sobre o mesmo banco: o contrato admite; a trava ou a unicidade precisa valer entre elas
            reiniciar();
            ordensConcluidas(ordens);
            EntreProcessos ep = duasInstanciasRodamOsProcessamentos("from ordem_servico");
            if (ep.ok() == null) { Resultado.inconclusivo("I1", "o cenário entre duas JVMs não concluiu: " + ep.texto()); return; }
            long total2 = contar("select count(*) from lancamento");
            long distintas2 = contar("select count(distinct ordem_servico_id) from lancamento");
            ok = total2 == ordens && distintas2 == ordens;
            msg += "; entre duas JVMs: " + total2 + " lançamentos, " + distintas2 + " ordens distintas";
        }
        Resultado.isca("I1", ok, msg);
    }

    @Test
    @Verifica({"I2"})
    void i2_oEventoEAsNotificacoesSoExistemJuntos() throws Exception {
        String t = token(ATENDENTE_A);
        // O commit falha, uma vez na conexão que gravou o chamado e outra na que gravou a notificação. Em qualquer dos dois casos tudo ou nada.
        List<String> relato = new ArrayList<>();
        boolean tudoOuNada = true;
        for (String gatilho : List.of("insert into chamado ", "insert into notificacao")) {
            Set<Long> marcadas = Collections.synchronizedSet(new HashSet<>());
            instr.gancho = (c, sql, args) -> { if (sql.toLowerCase().startsWith(gatilho)) marcadas.add(c.id); };
            instr.aoCommitar = c -> { if (marcadas.contains(c.id)) throw new SQLException("falha forçada no commit"); };
            R r = post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "vai falhar"));
            instr.gancho = null;
            instr.aoCommitar = null;
            if (marcadas.isEmpty()) { Resultado.inconclusivo("I2", "o cenário não foi montado: nenhum comando casou com '" + gatilho + "'"); return; }
            long chamados = contar("select count(*) from chamado");
            long eventos = contar("select count(*) from evento_chamado");
            long notificacoes = contar("select count(*) from notificacao");
            boolean zero = r.status() >= 500 && chamados == 0 && eventos == 0 && notificacoes == 0;
            if (!zero) tudoOuNada = false;
            relato.add("falha no commit da conexão de '" + gatilho.strip() + "': resposta " + r.status() + ", chamados=" + chamados + ", eventos=" + eventos + ", notificações=" + notificacoes);
            if (!zero) break;
        }
        Resultado.isca("I2", tudoOuNada, String.join("; ", relato) + " (esperado tudo ou nada)");
    }
}
