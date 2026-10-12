package br.netx.chamados;

import com.fasterxml.jackson.databind.JsonNode;
import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.time.Duration;
import java.time.Instant;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class JobsTest extends TestBase {

    private long chamadoNormal(String atendente) throws Exception {
        long id = abrirChamado(atendente, cliA, "escalonável", "NORMAL");
        patch("/chamados/" + id, atendente, Map.of("tecnico_id", tecA));
        return id;
    }

    /** Entra de novo a cada consulta: o relógio dos testes salta dias, e o token vale 8 horas. */
    private R chamado(long id) throws Exception {
        return get("/chamados/" + id, token(ATENDENTE_A));
    }

    private String prioridadeDe(long id) throws Exception {
        return chamado(id).j("prioridade").asText();
    }

    // ---- escalonamento por SLA

    @Test
    void sobeUmaPrioridadeQuandoGasta80PorCentoDoPrazoDeResolucao() throws Exception {
        String atendente = token(ATENDENTE_A);
        long id = chamadoNormal(atendente);
        // prazo de resolução = 2026-03-10T20:00Z, 152 h depois da abertura; 80% = 121 h 36 min = 2026-03-09T13:36Z
        relogio.agora = Instant.parse("2026-03-09T13:35:59Z");
        app.jobs().executarUmaVez();
        assertEquals("NORMAL", prioridadeDe(id));
        relogio.agora = Instant.parse("2026-03-09T13:36:01Z");
        app.jobs().executarUmaVez();
        R c = chamado(id);
        assertEquals("ALTA", c.j("prioridade").asText());
        assertEquals("2026-03-05T12:00:00Z", c.j("prazo_resolucao").asText(), "prazos recalculados a partir da abertura, com a política ALTA");
        assertEquals(1, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'ESCALONAMENTO'", id));
    }

    @Test
    void cadaPassadaSobeNoMaximoUmNivelEParaNaCritica() throws Exception {
        String atendente = token(ATENDENTE_A);
        long id = chamadoNormal(atendente);
        relogio.agora = Instant.parse("2026-03-09T14:00:00Z");
        app.jobs().executarUmaVez();
        assertEquals("ALTA", prioridadeDe(id));
        app.jobs().executarUmaVez();
        assertEquals("CRITICA", prioridadeDe(id));
        app.jobs().executarUmaVez();
        assertEquals("CRITICA", prioridadeDe(id));
        assertEquals(2, contar("select count(*) from evento_chamado where chamado_id = ? and tipo = 'ESCALONAMENTO'", id));
    }

    @Test
    void escalonarNotificaAEquipeEIgnoraChamadosResolvidos() throws Exception {
        String atendente = token(ATENDENTE_A);
        long id = chamadoNormal(atendente);
        long resolvido = abrirChamado(atendente, cliA, "já resolvido", "NORMAL");
        atualizar("update chamado set status = 'RESOLVIDO', resolvido_em = ? where id = ?", relogio.agora, resolvido);
        relogio.agora = Instant.parse("2026-03-09T14:00:00Z");
        app.jobs().executarUmaVez();
        assertEquals("NORMAL", prioridadeDe(resolvido));
        assertEquals(3, contar("select count(*) from notificacao where chave_evento like ?", "escalonado:" + id + ":%"),
                "atendente, administrador e o técnico atribuído");
        assertEquals(1, contar("select count(*) from notificacao where chave_evento like ? and destinatario = ?", "escalonado:" + id + ":%", TECNICO_A));
    }

    // ---- despacho de notificações

    private List<String> linhas(long empresa) throws Exception {
        Path arquivo = notificacoes.resolve(empresa + ".jsonl");
        return Files.exists(arquivo) ? Files.readAllLines(arquivo) : List.of();
    }

    @Test
    void despachaUmaLinhaPorDestinatarioEMarcaComoEnviada() throws Exception {
        String atendente = token(ATENDENTE_A);
        long id = abrirChamado(atendente, cliA, "avise todos", "NORMAL");
        relogio.avancar(Duration.ofMinutes(5));
        app.jobs().executarUmaVez();
        List<String> linhas = linhas(empA);
        assertEquals(2, linhas.size());
        JsonNode primeira = JSON.readTree(linhas.get(0));
        assertTrue(primeira.get("chave").asText().startsWith("aberto:" + id + ":"));
        assertEquals("2026-03-04T12:05:00Z", primeira.get("enviada_em").asText());
        assertEquals("Chamado " + id + " aberto", primeira.get("assunto").asText());
        assertEquals(List.of(ADMIN_A, ATENDENTE_A), List.of(JSON.readTree(linhas.get(0)).get("destinatario").asText(), JSON.readTree(linhas.get(1)).get("destinatario").asText()).stream().sorted().toList());
        assertEquals(2, contar("select count(*) from notificacao where estado = 'ENVIADA' and enviada_em is not null"));
        assertEquals(0, linhas(empB).size());
    }

    @Test
    void repetirOProcessamentoNaoDuplicaAEntrega() throws Exception {
        abrirChamado(token(ATENDENTE_A), cliA, "uma vez", "NORMAL");
        app.jobs().executarUmaVez();
        app.jobs().executarUmaVez();
        app.jobs().executarUmaVez();
        assertEquals(2, linhas(empA).size());
    }

    @Test
    void umaNotificacaoJaNoArquivoNaoEEscritaDeNovo() throws Exception {
        long id = abrirChamado(token(ATENDENTE_A), cliA, "já entregue pela metade", "NORMAL");
        String chave = (String) consultar("select chave_evento from notificacao where destinatario = ?", ATENDENTE_A).get(0).get("chave_evento");
        Files.writeString(notificacoes.resolve(empA + ".jsonl"),
                JSON.writeValueAsString(Map.of("chave", chave, "destinatario", ATENDENTE_A, "assunto", "x", "corpo", "y", "enviada_em", "2026-03-04T11:00:00Z")) + "\n");
        app.jobs().executarUmaVez();
        assertEquals(2, linhas(empA).size(), "a linha que já estava lá não é repetida; só a do administrador entra");
        assertEquals(0, contar("select count(*) from notificacao where estado = 'PENDENTE' and chave_evento like ?", "aberto:" + id + ":%"));
    }

    @Test
    void falhaAgendaNovaTentativaCom1_2_4_8MinutosEDepoisFalha() throws Exception {
        abrirChamado(token(ATENDENTE_A), cliA, "vai falhar", "NORMAL");
        Files.createDirectory(notificacoes.resolve(empA + ".jsonl")); // não dá para escrever num diretório
        int[] esperas = {1, 2, 4, 8};
        for (int tentativa = 1; tentativa <= 4; tentativa++) {
            app.jobs().executarUmaVez();
            Map<String, Object> n = consultar("select estado, tentativas, proxima_tentativa_em from notificacao order by id").get(0);
            assertEquals("PENDENTE", n.get("estado"));
            assertEquals(tentativa, n.get("tentativas"));
            Instant proxima = ((java.sql.Timestamp) n.get("proxima_tentativa_em")).toInstant();
            assertEquals(relogio.agora.plus(Duration.ofMinutes(esperas[tentativa - 1])), proxima);
            app.jobs().executarUmaVez();
            assertEquals(tentativa, consultar("select tentativas from notificacao order by id").get(0).get("tentativas"), "antes da hora não tenta de novo");
            relogio.avancar(Duration.ofMinutes(esperas[tentativa - 1]));
        }
        app.jobs().executarUmaVez();
        Map<String, Object> ultima = consultar("select estado, tentativas from notificacao order by id").get(0);
        assertEquals("FALHA", ultima.get("estado"));
        assertEquals(5, ultima.get("tentativas"));
        relogio.avancar(Duration.ofDays(1));
        app.jobs().executarUmaVez();
        assertEquals(5, consultar("select tentativas from notificacao order by id").get(0).get("tentativas"), "FALHA é definitiva");
    }

    @Test
    void depoisDeUmaFalhaAEntregaSeRecuperaQuandoOProblemaPassa() throws Exception {
        abrirChamado(token(ATENDENTE_A), cliA, "recupera", "NORMAL");
        Path obstaculo = Files.createDirectory(notificacoes.resolve(empA + ".jsonl"));
        app.jobs().executarUmaVez();
        Files.delete(obstaculo);
        relogio.avancar(Duration.ofMinutes(1));
        app.jobs().executarUmaVez();
        assertEquals(2, linhas(empA).size());
        assertEquals(0, contar("select count(*) from notificacao where estado <> 'ENVIADA'"));
    }

    @Test
    void oAdminConsultaAsNotificacoesDaEmpresaPorEstado() throws Exception {
        abrirChamado(token(ATENDENTE_A), cliA, "para consultar", "NORMAL");
        String admin = token(ADMIN_A);
        R pendentes = get("/notificacoes?estado=PENDENTE", admin);
        assertEquals(200, pendentes.status());
        assertEquals(2, pendentes.j("total").asInt());
        assertEquals("PENDENTE", pendentes.j("itens").get(0).get("estado").asText());
        assertEquals(0, get("/notificacoes?estado=ENVIADA", admin).j("total").asInt());
        app.jobs().executarUmaVez();
        R enviadas = get("/notificacoes?estado=ENVIADA&tamanho=1", admin);
        assertEquals(2, enviadas.j("total").asInt());
        assertEquals(1, enviadas.j("itens").size());
        assertEquals(true, enviadas.j("itens").get(0).get("enviada_em").isTextual());
        assertEquals(422, get("/notificacoes?estado=TALVEZ", admin).status());
        assertEquals(403, get("/notificacoes", token(ATENDENTE_A)).status());
        assertEquals(0, get("/notificacoes", token(ADMIN_B)).j("total").asInt(), "cada empresa vê só as suas");
        assertEquals(422, get("/notificacoes", token(PLATAFORMA)).status());
        assertEquals(2, get("/notificacoes?empresa_id=" + empA + "&estado=ENVIADA", token(PLATAFORMA)).j("total").asInt());
    }

    // ---- lançamento das ordens concluídas

    private long ordemConcluida(String atendente, String tecnico, long valor) throws Exception {
        long chamado = abrirChamado(atendente, cliA, "para lançar", "NORMAL");
        long ordem = criarOrdem(atendente, chamado, tecA);
        post("/ordens/" + ordem + "/iniciar", tecnico, null);
        post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", valor));
        return ordem;
    }

    @Test
    void cadaOrdemConcluidaComValorGeraExatamenteUmLancamento() throws Exception {
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        long com = ordemConcluida(atendente, tecnico, 12345);
        ordemConcluida(atendente, tecnico, 0);
        app.jobs().executarUmaVez();
        List<Map<String, Object>> lancamentos = consultar("select ordem_servico_id, valor_cents, competencia, empresa_id from lancamento");
        assertEquals(1, lancamentos.size(), "ordem com valor zero não gera lançamento");
        assertEquals(com, ((Number) lancamentos.get(0).get("ordem_servico_id")).longValue());
        assertEquals(12345L, ((Number) lancamentos.get(0).get("valor_cents")).longValue());
        assertEquals("2026-03", lancamentos.get(0).get("competencia"));
        assertEquals(empA, ((Number) lancamentos.get(0).get("empresa_id")).longValue());
        app.jobs().executarUmaVez();
        assertEquals(1, contar("select count(*) from lancamento"), "repetir não duplica");
        ordemConcluida(atendente, tecnico, 800);
        app.jobs().executarUmaVez();
        assertEquals(2, contar("select count(*) from lancamento"), "a nova ordem entra na passada seguinte");
    }

    @Test
    void aCompetenciaEOMesDaConclusaoNoFusoDaEmpresa() throws Exception {
        atualizar("update empresa set fuso_horario = 'America/Sao_Paulo' where id = ?", empA);
        relogio.agora = Instant.parse("2026-03-20T15:00:00Z");
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        ordemConcluida(atendente, tecnico, 500);
        relogio.agora = Instant.parse("2026-05-02T10:00:00Z");
        app.jobs().executarUmaVez();
        assertEquals("2026-03", consultar("select competencia from lancamento").get(0).get("competencia"));
    }
}
