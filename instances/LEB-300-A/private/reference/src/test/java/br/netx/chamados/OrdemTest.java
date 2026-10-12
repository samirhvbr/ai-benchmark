package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.time.Duration;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class OrdemTest extends TestBase {

    private long chamadoComTecnico(String atendente) throws Exception {
        long id = abrirChamado(atendente, cliA, "visita técnica", "NORMAL");
        patch("/chamados/" + id, atendente, Map.of("tecnico_id", tecA));
        return id;
    }

    @Test
    void criarOrdemParaOChamado() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = chamadoComTecnico(atendente);
        R r = post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "VISITA", "agendada_para", "2026-03-05T13:00:00Z"));
        assertEquals(201, r.status());
        assertEquals("AGENDADA", r.j("status").asText());
        assertEquals(chamado, r.j("chamado_id").asLong());
        assertEquals("2026-03-05T13:00:00Z", r.j("agendada_para").asText());
    }

    @Test
    void criarOrdemExigeTecnicoDaEmpresaEChamadoEmAberto() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = abrirChamado(atendente, cliA, "x", "NORMAL");
        long tecB = inserir("insert into tecnico (empresa_id, nome) values (?, 'Tecnico B') returning id", empB);
        assertEquals(422, post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecB, "tipo", "VISITA", "agendada_para", "2026-03-05T13:00:00Z")).status());
        assertEquals(422, post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "VISITA", "agendada_para", "ontem")).status());
        atualizar("update chamado set status = 'FECHADO' where id = ?", chamado);
        assertEquals(409, post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "VISITA", "agendada_para", "2026-03-05T13:00:00Z")).status());
    }

    @Test
    void cicloCompletoDaOrdemResolveOChamado() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long chamado = chamadoComTecnico(atendente);
        long ordem = criarOrdem(atendente, chamado, tecA);

        assertEquals(409, post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 5000)).status(), "não se conclui o que não foi iniciado");
        relogio.avancar(Duration.ofHours(1));
        R iniciada = post("/ordens/" + ordem + "/iniciar", tecnico, null);
        assertEquals(200, iniciada.status());
        assertEquals("EM_EXECUCAO", iniciada.j("status").asText());
        assertEquals("2026-03-04T13:00:00Z", iniciada.j("iniciada_em").asText());

        relogio.avancar(Duration.ofMinutes(90));
        R concluida = post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 15000, "observacoes", "troca de roteador"));
        assertEquals(200, concluida.status());
        assertEquals("CONCLUIDA", concluida.j("status").asText());
        assertEquals(15000, concluida.j("valor_cents").asLong());
        assertEquals("2026-03-04T14:30:00Z", concluida.j("concluida_em").asText());

        R c = get("/chamados/" + chamado, atendente);
        assertEquals("RESOLVIDO", c.j("status").asText());
        assertEquals("2026-03-04T14:30:00Z", c.j("resolvido_em").asText(), "igual à conclusão da última ordem");
        assertEquals(1, contar("select count(*) from notificacao where chave_evento like ? and destinatario = ?", "ordem_concluida:" + chamado + ":%", ATENDENTE_A),
                "o criador do chamado é avisado");
    }

    @Test
    void ordemSemValorNegativoESoDoTecnicoAtribuido() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long ordem = criarOrdem(atendente, chamadoComTecnico(atendente), tecA);
        post("/ordens/" + ordem + "/iniciar", tecnico, null);
        assertEquals(422, post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", -1)).status());
        assertEquals(403, post("/ordens/" + ordem + "/iniciar", atendente, null).status());
        long outroUsuario = usuario(empA, "outro@a.test", "TECNICO");
        inserir("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, 'Outro') returning id", empA, outroUsuario);
        assertEquals(404, post("/ordens/" + ordem + "/concluir", token("outro@a.test"), Map.of("valor_cents", 100)).status());
    }

    @Test
    void cancelarUmaOrdemSoComOMotivoEEmEstadoAberto() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long chamado = chamadoComTecnico(atendente);
        long ordem = criarOrdem(atendente, chamado, tecA);
        assertEquals(422, post("/ordens/" + ordem + "/cancelar", atendente, Map.of()).status());
        R r = post("/ordens/" + ordem + "/cancelar", atendente, Map.of("motivo", "cliente desistiu"));
        assertEquals("CANCELADA", r.j("status").asText());
        assertEquals(409, post("/ordens/" + ordem + "/cancelar", atendente, Map.of("motivo", "de novo")).status());
        assertEquals(409, post("/ordens/" + ordem + "/iniciar", tecnico, null).status());
        assertEquals("ABERTO", get("/chamados/" + chamado, atendente).j("status").asText(), "só ordens canceladas não resolvem o chamado");
    }

    @Test
    void oChamadoSoResolveQuandoTodasAsOrdensTerminaram() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long chamado = chamadoComTecnico(atendente);
        long primeira = criarOrdem(atendente, chamado, tecA);
        long segunda = criarOrdem(atendente, chamado, tecA);
        post("/ordens/" + primeira + "/iniciar", tecnico, null);
        post("/ordens/" + primeira + "/concluir", tecnico, Map.of("valor_cents", 100));
        assertEquals("ABERTO", get("/chamados/" + chamado, atendente).j("status").asText(), "ainda há uma ordem agendada");
        post("/ordens/" + segunda + "/cancelar", atendente, Map.of("motivo", "não será necessária"));
        assertEquals("RESOLVIDO", get("/chamados/" + chamado, atendente).j("status").asText());
    }

    @Test
    void listarOrdensPorAgendamentoEPorTecnico() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = chamadoComTecnico(atendente);
        post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "B", "agendada_para", "2026-03-06T13:00:00Z"));
        post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "A", "agendada_para", "2026-03-05T13:00:00Z"));
        R r = get("/ordens", atendente);
        List<String> tipos = new ArrayList<>();
        r.j("itens").forEach(n -> tipos.add(n.get("tipo").asText()));
        assertEquals(List.of("A", "B"), tipos);
        assertEquals(2, r.j("total").asInt());
        assertEquals(1, get("/ordens?tamanho=1&pagina=2", atendente).j("itens").size());
        assertEquals(2, get("/ordens?status=AGENDADA", atendente).j("total").asInt());
        assertEquals(0, get("/ordens?status=CONCLUIDA", atendente).j("total").asInt());
        String tecnico = token(TECNICO_A);
        assertEquals(2, get("/ordens", tecnico).j("total").asInt());
        long semOrdens = usuario(empA, "livre@a.test", "TECNICO");
        inserir("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, 'Livre') returning id", empA, semOrdens);
        assertEquals(0, get("/ordens", token("livre@a.test")).j("total").asInt());
    }

    @Test
    void asOrdensDeUmChamadoEOHistoricoDoCliente() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = chamadoComTecnico(atendente);
        long segunda = post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "B", "agendada_para", "2026-03-06T13:00:00Z")).j("id").asLong();
        long primeira = post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "A", "agendada_para", "2026-03-05T13:00:00Z")).j("id").asLong();
        R r = get("/chamados/" + chamado + "/ordens", atendente);
        assertEquals(200, r.status());
        assertEquals(primeira, r.j("itens").get(0).get("id").asLong());
        assertEquals(segunda, r.j("itens").get(1).get("id").asLong());
        assertEquals(2, get("/chamados/" + chamado + "/ordens", token(TECNICO_A)).j("itens").size());
        assertEquals(404, get("/chamados/" + chamado + "/ordens", token(ADMIN_B)).status());
        assertEquals(404, get("/chamados/99999999/ordens", atendente).status());

        abrirChamado(atendente, cliA, "outro do mesmo cliente", "BAIXA");
        R historico = get("/clientes/" + cliA + "/chamados", atendente);
        assertEquals(200, historico.status());
        assertEquals(2, historico.j("total").asInt());
        assertEquals("outro do mesmo cliente", historico.j("itens").get(0).get("titulo").asText(), "o mais recente primeiro");
        assertEquals(404, get("/clientes/" + cliA + "/chamados", token(ADMIN_B)).status());
        assertEquals(403, get("/clientes/" + cliA + "/chamados", token(TECNICO_A)).status());
    }

    @Test
    void aAgendaDoTecnicoEODiaNoFusoDaEmpresa() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = chamadoComTecnico(atendente);
        // 2026-03-05 em São Paulo vai de 03:00Z a 03:00Z do dia seguinte
        post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "tarde", "agendada_para", "2026-03-05T20:00:00Z"));
        post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "manha", "agendada_para", "2026-03-05T11:00:00Z"));
        post("/chamados/" + chamado + "/ordens", atendente, Map.of("tecnico_id", tecA, "tipo", "noite do dia anterior", "agendada_para", "2026-03-05T02:30:00Z"));
        long cancelada = criarOrdem(atendente, chamado, tecA);
        post("/ordens/" + cancelada + "/cancelar", atendente, Map.of("motivo", "x"));
        R r = get("/tecnicos/" + tecA + "/agenda?dia=2026-03-05", atendente);
        assertEquals(200, r.status());
        assertEquals(2, r.j("itens").size(), "a ordem de 02:30Z é do dia 4 em São Paulo; a de 13:00Z cancelada não conta");
        assertEquals("manha", r.j("itens").get(0).get("tipo").asText());
        assertEquals("tarde", r.j("itens").get(1).get("tipo").asText());
        assertEquals(200, get("/tecnicos/" + tecA + "/agenda?dia=2026-03-05", token(TECNICO_A)).status());
        assertEquals(422, get("/tecnicos/" + tecA + "/agenda?dia=hoje", atendente).status());
        assertEquals(422, get("/tecnicos/" + tecA + "/agenda", atendente).status());
        assertEquals(404, get("/tecnicos/" + tecA + "/agenda?dia=2026-03-05", token(ADMIN_B)).status());
        long outro = usuario(empA, "agenda@a.test", "TECNICO");
        inserir("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, 'Outro') returning id", empA, outro);
        assertEquals(404, get("/tecnicos/" + tecA + "/agenda?dia=2026-03-05", token("agenda@a.test")).status(), "o técnico só vê a própria agenda");
    }

    @Test
    void reagendarSoUmaOrdemAgendadaEGravaEvento() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long chamado = chamadoComTecnico(atendente);
        long ordem = criarOrdem(atendente, chamado, tecA);
        long outroUsuario = usuario(empA, "reserva@a.test", "TECNICO");
        long reserva = inserir("insert into tecnico (empresa_id, usuario_id, nome) values (?, ?, 'Reserva') returning id", empA, outroUsuario);
        R r = chamar("PATCH", "/ordens/" + ordem, atendente, Map.of("agendada_para", "2026-03-09T15:00:00Z", "tecnico_id", reserva));
        assertEquals(200, r.status());
        assertEquals("2026-03-09T15:00:00Z", r.j("agendada_para").asText());
        assertEquals(reserva, r.j("tecnico_id").asLong());
        assertEquals(1, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'REAGENDAMENTO'", chamado));
        R soData = chamar("PATCH", "/ordens/" + ordem, atendente, Map.of("agendada_para", "2026-03-10T15:00:00Z"));
        assertEquals(reserva, soData.j("tecnico_id").asLong(), "sem tecnico_id o técnico não muda");
        assertEquals(422, chamar("PATCH", "/ordens/" + ordem, atendente, Map.of("agendada_para", "2026-03-10T15:00:00Z", "tecnico_id", 999999)).status());
        assertEquals(403, chamar("PATCH", "/ordens/" + ordem, tecnico, Map.of("agendada_para", "2026-03-10T15:00:00Z")).status());
        assertEquals(404, chamar("PATCH", "/ordens/" + ordem, token(ADMIN_B), Map.of("agendada_para", "2026-03-10T15:00:00Z")).status());
        post("/ordens/" + ordem + "/cancelar", atendente, Map.of("motivo", "x"));
        assertEquals(409, chamar("PATCH", "/ordens/" + ordem, atendente, Map.of("agendada_para", "2026-03-11T15:00:00Z")).status());
    }

    @Test
    void concluirComAMesmaChaveNaoRepeteOEfeito() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long ordem = criarOrdem(atendente, chamadoComTecnico(atendente), tecA);
        post("/ordens/" + ordem + "/iniciar", tecnico, null);
        R primeira = post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 700), "Idempotency-Key", "c-1");
        relogio.avancar(Duration.ofMinutes(5));
        R segunda = post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 700), "Idempotency-Key", "c-1");
        assertEquals(200, primeira.status());
        assertEquals(200, segunda.status());
        assertEquals(primeira.bruto(), segunda.bruto());
        assertEquals(409, post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 700)).status(), "sem a chave a segunda conclusão é um conflito");
        assertTrue(primeira.bruto().contains("\"valor_cents\":700"));
    }
}
