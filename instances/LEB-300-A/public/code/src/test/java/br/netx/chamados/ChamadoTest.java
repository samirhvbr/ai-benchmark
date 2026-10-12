package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.time.Duration;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class ChamadoTest extends TestBase {

    @Test
    void abrirChamadoGravaPrazosEEventoENotifica() throws Exception {
        String atendente = token(ATENDENTE_A);
        R r = post("/chamados", atendente, Map.of("cliente_id", cliA, "titulo", "Sem internet", "descricao", "desde ontem"));
        assertEquals(201, r.status());
        assertEquals("ABERTO", r.j("status").asText());
        assertEquals("NORMAL", r.j("prioridade").asText(), "prioridade padrão");
        assertEquals("Cliente A", r.j("cliente_nome").asText());
        assertEquals("2026-03-04T12:00:00Z", r.j("aberto_em").asText());
        // NORMAL: 8 h de resposta e 48 h de resolução, só de expediente (seg a sex, 08:00 a 18:00 em São Paulo)
        assertEquals("2026-03-04T20:00:00Z", r.j("prazo_resposta").asText());
        assertEquals("2026-03-10T20:00:00Z", r.j("prazo_resolucao").asText());
        long id = r.j("id").asLong();
        assertEquals(1, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'ABERTURA'", id));
        assertEquals(2, contar("select count(*) from notificacao where chave_evento like ? and estado = 'PENDENTE'", "aberto:" + id + ":%"),
                "atendente e administrador da empresa");
    }

    @Test
    void chamadoAbertoNoSabadoComecaAContarNaSegunda() throws Exception {
        relogio.agora = Instant.parse("2026-03-07T15:00:00Z"); // sábado, 12:00 em São Paulo
        R r = post("/chamados", token(ATENDENTE_A), Map.of("cliente_id", cliA, "titulo", "Fim de semana"));
        assertEquals("2026-03-09T19:00:00Z", r.j("prazo_resposta").asText(), "segunda 08:00 + 8 h = 16:00 em São Paulo");
        assertEquals("2026-03-13T19:00:00Z", r.j("prazo_resolucao").asText(), "48 h de expediente a partir de segunda 08:00");
    }

    @Test
    void prioridadeAltaConta24HorasCorridas() throws Exception {
        R r = post("/chamados", token(ATENDENTE_A), Map.of("cliente_id", cliA, "titulo", "Urgente", "prioridade", "ALTA"));
        assertEquals("2026-03-04T16:00:00Z", r.j("prazo_resposta").asText());
        assertEquals("2026-03-05T12:00:00Z", r.j("prazo_resolucao").asText());
    }

    @Test
    void aPoliticaDaEmpresaSubstituiOPadrao() throws Exception {
        atualizar("insert into sla_politica (empresa_id, prioridade, horas_resposta, horas_resolucao, somente_expediente) values (?, 'CRITICA', 2, 6, false)", empA);
        R r = post("/chamados", token(ATENDENTE_A), Map.of("cliente_id", cliA, "titulo", "Queda geral", "prioridade", "CRITICA"));
        assertEquals("2026-03-04T14:00:00Z", r.j("prazo_resposta").asText());
        assertEquals("2026-03-04T18:00:00Z", r.j("prazo_resolucao").asText());
    }

    @Test
    void aberturaInvalidaE422() throws Exception {
        String t = token(ATENDENTE_A);
        assertEquals(422, post("/chamados", t, Map.of("cliente_id", cliA)).status(), "sem título");
        assertEquals(422, post("/chamados", t, Map.of("cliente_id", cliB, "titulo", "x")).status(), "cliente de outra empresa");
        assertEquals(422, post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "x", "prioridade", "URGENTISSIMA")).status());
        assertEquals(400, post("/chamados", t, "{ isto não é json").status());
    }

    @Test
    void listaPorPrioridadeDecrescenteDepoisPrazoDepoisId() throws Exception {
        String t = token(ATENDENTE_A);
        abrirChamado(t, cliA, "baixa", "BAIXA");
        abrirChamado(t, cliA, "critica", "CRITICA");
        abrirChamado(t, cliA, "normal", "NORMAL");
        abrirChamado(t, cliA, "alta", "ALTA");
        abrirChamado(t, cliA, "alta 2", "ALTA");
        R r = get("/chamados", t);
        List<String> titulos = new ArrayList<>();
        r.j("itens").forEach(n -> titulos.add(n.get("titulo").asText()));
        assertEquals(List.of("critica", "alta", "alta 2", "normal", "baixa"), titulos);
        assertEquals(5, r.j("total").asInt());
        assertEquals(1, r.j("pagina").asInt());
        assertEquals(20, r.j("tamanho").asInt());
    }

    @Test
    void filtrosEPaginacao() throws Exception {
        String t = token(ATENDENTE_A);
        for (int i = 1; i <= 5; i++) abrirChamado(t, cliA, "n" + i, "NORMAL");
        abrirChamado(t, cliA, "alta", "ALTA");
        assertEquals(1, get("/chamados?prioridade=ALTA", t).j("total").asInt());
        assertEquals(6, get("/chamados?status=ABERTO", t).j("total").asInt());
        assertEquals(0, get("/chamados?status=FECHADO", t).j("total").asInt());
        R pagina2 = get("/chamados?tamanho=2&pagina=2", t);
        assertEquals(2, pagina2.j("itens").size());
        assertEquals(6, pagina2.j("total").asInt());
        assertEquals(422, get("/chamados?tamanho=101", t).status());
        assertEquals(422, get("/chamados?pagina=0", t).status());
    }

    @Test
    void alterarSoOsCamposEnviados() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "original", "NORMAL");
        R r = patch("/chamados/" + id, t, Map.of("titulo", "novo titulo"));
        assertEquals(200, r.status());
        assertEquals("novo titulo", r.j("titulo").asText());
        assertEquals("NORMAL", r.j("prioridade").asText());
        assertEquals("2026-03-10T20:00:00Z", r.j("prazo_resolucao").asText());
    }

    @Test
    void mudarAPrioridadeRecalculaOsPrazosAPartirDaAbertura() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "vai subir", "NORMAL");
        relogio.avancar(Duration.ofHours(3));
        R r = patch("/chamados/" + id, t, Map.of("prioridade", "ALTA"));
        assertEquals("ALTA", r.j("prioridade").asText());
        assertEquals("2026-03-04T16:00:00Z", r.j("prazo_resposta").asText(), "a partir de aberto_em, não de agora");
        assertEquals("2026-03-05T12:00:00Z", r.j("prazo_resolucao").asText());
        assertEquals(1, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'PRIORIDADE'", id));
    }

    @Test
    void atribuirUmTecnicoGravaEventoENotificaOTecnico() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "visita", "NORMAL");
        R r = patch("/chamados/" + id, t, Map.of("tecnico_id", tecA));
        assertEquals(200, r.status());
        assertEquals(tecA, r.j("tecnico_id").asLong());
        assertEquals("Tecnico A", r.j("tecnico_nome").asText());
        assertEquals(1, contar("select count(*) from notificacao where chave_evento like ? and destinatario = ?", "atribuido:" + id + ":%", TECNICO_A));
        assertEquals(422, patch("/chamados/" + id, t, Map.of("tecnico_id", 999999)).status());
    }

    @Test
    void transicoesSeguemATabela() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "ciclo", "NORMAL");
        assertEquals(409, post("/chamados/" + id + "/transicoes", t, Map.of("para", "RESOLVIDO")).status(), "ABERTO não vai direto a RESOLVIDO");
        R emAtendimento = post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO"));
        assertEquals(200, emAtendimento.status());
        assertEquals("2026-03-04T12:00:00Z", emAtendimento.j("primeira_resposta_em").asText());
        relogio.avancar(Duration.ofHours(1));
        assertEquals(200, post("/chamados/" + id + "/transicoes", t, Map.of("para", "AGUARDANDO_CLIENTE")).status());
        R volta = post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO"));
        assertEquals("2026-03-04T12:00:00Z", volta.j("primeira_resposta_em").asText(), "a primeira resposta não muda");
        R resolvido = post("/chamados/" + id + "/transicoes", t, Map.of("para", "RESOLVIDO"));
        assertEquals("2026-03-04T13:00:00Z", resolvido.j("resolvido_em").asText());
        assertEquals(200, post("/chamados/" + id + "/transicoes", t, Map.of("para", "FECHADO")).status());
        assertEquals(409, post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO")).status(), "FECHADO é terminal");
        assertEquals(5, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'TRANSICAO'", id), "uma linha por transição aceita");
    }

    @Test
    void notasEOHistoricoDeEventos() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "com notas", "NORMAL");
        R nota = post("/chamados/" + id + "/eventos", t, Map.of("detalhe", "liguei para o cliente"));
        assertEquals(201, nota.status());
        assertEquals("NOTA", nota.j("tipo").asText());
        R eventos = get("/chamados/" + id + "/eventos", t);
        assertEquals(200, eventos.status());
        assertEquals("ABERTURA", eventos.j("itens").get(0).get("tipo").asText());
        assertEquals("NOTA", eventos.j("itens").get(1).get("tipo").asText());
    }

    @Test
    void oTecnicoSoEnxergaOsChamadosAtribuidosAEle() throws Exception {
        String atendente = token(ATENDENTE_A);
        long meu = abrirChamado(atendente, cliA, "meu", "NORMAL");
        long outro = abrirChamado(atendente, cliA, "de outro", "NORMAL");
        patch("/chamados/" + meu, atendente, Map.of("tecnico_id", tecA));
        String tecnico = token(TECNICO_A);
        R lista = get("/chamados", tecnico);
        assertEquals(1, lista.j("total").asInt());
        assertEquals(meu, lista.j("itens").get(0).get("id").asLong());
        assertEquals(200, get("/chamados/" + meu, tecnico).status());
        assertEquals(404, get("/chamados/" + outro, tecnico).status());
        assertEquals(201, post("/chamados/" + meu + "/eventos", tecnico, Map.of("detalhe", "cheguei ao local")).status());
        assertEquals(404, post("/chamados/" + outro + "/eventos", tecnico, Map.of("detalhe", "não é meu")).status());
        assertEquals(403, post("/chamados", tecnico, Map.of("cliente_id", cliA, "titulo", "x")).status());
    }

    @Test
    void repetirComAMesmaChaveDevolveARespostaOriginalSemEfeitoNovo() throws Exception {
        String t = token(ATENDENTE_A);
        Map<String, Object> corpo = Map.of("cliente_id", cliA, "titulo", "uma vez só");
        R primeira = post("/chamados", t, corpo, "Idempotency-Key", "k-1");
        R segunda = post("/chamados", t, corpo, "Idempotency-Key", "k-1");
        assertEquals(201, primeira.status());
        assertEquals(201, segunda.status());
        assertEquals(primeira.bruto(), segunda.bruto());
        assertEquals(1, contar("select count(*) from chamado where empresa_id = ?", empA));
        assertEquals(422, post("/chamados", t, Map.of("cliente_id", cliA, "titulo", "outro corpo"), "Idempotency-Key", "k-1").status());
        assertEquals(1, contar("select count(*) from chamado where empresa_id = ?", empA), "o corpo diferente não criou nada");
    }

    @Test
    void aChaveDeIdempotenciaValePor24Horas() throws Exception {
        String t = token(ATENDENTE_A);
        Map<String, Object> corpo = Map.of("cliente_id", cliA, "titulo", "depois de um dia");
        long primeira = post("/chamados", t, corpo, "Idempotency-Key", "k-dia").j("id").asLong();
        relogio.avancar(Duration.ofHours(25));
        String novo = token(ATENDENTE_A);
        long segunda = post("/chamados", novo, corpo, "Idempotency-Key", "k-dia").j("id").asLong();
        assertNotEquals(primeira, segunda);
    }

    @Test
    void oResumoDeSlaMostraOQueFaltaParaVencer() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "acompanhado", "ALTA");
        R r = get("/chamados/" + id + "/sla", t);
        assertEquals(200, r.status());
        assertEquals("2026-03-05T12:00:00Z", r.j("prazo_resolucao").asText());
        assertEquals(86400, r.j("segundos_para_vencer").asLong());
        assertTrue(r.j("resposta_cumprida").isNull());
        assertTrue(r.j("resolucao_cumprida").isNull());
        assertEquals(false, r.j("em_risco").asBoolean());
        relogio.avancar(Duration.ofHours(20));
        t = token(ATENDENTE_A);
        post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO"));
        R tarde = get("/chamados/" + id + "/sla", t);
        assertEquals(true, tarde.j("em_risco").asBoolean(), "passou de 80% do prazo");
        assertEquals(false, tarde.j("resposta_cumprida").asBoolean(), "a resposta veio depois das 4 h");
        assertEquals(4 * 3600, tarde.j("segundos_para_vencer").asLong());
        assertEquals(404, get("/chamados/" + id + "/sla", token(ADMIN_B)).status());
    }

    @Test
    void semOCabecalhoNaoHaDeduplicacao() throws Exception {
        String t = token(ATENDENTE_A);
        Map<String, Object> corpo = Map.of("cliente_id", cliA, "titulo", "igual");
        assertEquals(201, post("/chamados", t, corpo).status());
        assertEquals(201, post("/chamados", t, corpo).status());
        assertEquals(2, contar("select count(*) from chamado where empresa_id = ?", empA));
    }
}
