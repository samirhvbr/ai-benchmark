package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.time.Duration;
import java.time.Instant;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

class RelatorioTest extends TestBase {
    static final Instant MARCO = Instant.parse("2026-03-10T15:00:00Z");

    private void cenarioDeSla() throws Exception {
        relogio.agora = MARCO;
        // aberto em março no fuso de São Paulo; o prazo e a resolução estão escolhidos à mão
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-02T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-03T12:00:00Z"));
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "FECHADO", Instant.parse("2026-03-02T13:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-05T12:00:00Z"));
        chamadoNoBanco(empA, cliA, atdA, "ALTA", "EM_ATENDIMENTO", Instant.parse("2026-03-03T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), null);
        chamadoNoBanco(empA, cliA, atdA, "BAIXA", "ABERTO", Instant.parse("2026-03-09T12:00:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        // fora do mês, e de outra empresa
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", Instant.parse("2026-02-27T12:00:00Z"), Instant.parse("2026-03-01T12:00:00Z"), Instant.parse("2026-02-28T12:00:00Z"));
        chamadoNoBanco(empB, cliB, admB, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-02T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-03T12:00:00Z"));
    }

    @Test
    void relatorioDeSlaDoMes() throws Exception {
        cenarioDeSla();
        R r = get("/relatorios/sla?mes=2026-03", token(ADMIN_A));
        assertEquals(200, r.status());
        assertEquals("2026-03", r.j("mes").asText());
        assertEquals(4, r.j("abertos").asInt());
        assertEquals(2, r.j("resolvidos").asInt());
        assertEquals(1, r.j("cumpridos").asInt());
        assertEquals(1, r.j("vencidos_em_aberto").asInt(), "a ALTA em atendimento já passou do prazo; a BAIXA ainda não");
        assertEquals(50.0, r.j("percentual_cumprido").asDouble());
        JsonNodeAssert.porPrioridade(r);
    }

    @Test
    void semResolvidosOPercentualENulo() throws Exception {
        relogio.agora = MARCO;
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", Instant.parse("2026-03-09T12:00:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        R r = get("/relatorios/sla?mes=2026-03", token(ADMIN_A));
        assertTrue(r.j("percentual_cumprido").isNull());
    }

    @Test
    void novosDadosApareceDepoisDeTrintaSegundos() throws Exception {
        cenarioDeSla();
        String t = token(ADMIN_A);
        assertEquals(4, get("/relatorios/sla?mes=2026-03", t).j("abertos").asInt());
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", Instant.parse("2026-03-09T13:00:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        relogio.avancar(Duration.ofSeconds(31));
        assertEquals(5, get("/relatorios/sla?mes=2026-03", t).j("abertos").asInt());
    }

    @Test
    void relatorioDeStatusTrazTodosOsStatusNaOrdemDoEnum() throws Exception {
        cenarioDeSla();
        R r = get("/relatorios/status", token(ATENDENTE_A));
        assertEquals(200, r.status());
        List<String> chaves = new java.util.ArrayList<>();
        r.corpo().fieldNames().forEachRemaining(chaves::add);
        assertEquals(List.of("ABERTO", "EM_ATENDIMENTO", "AGUARDANDO_CLIENTE", "RESOLVIDO", "FECHADO"), chaves);
        assertEquals(1, r.j("ABERTO").asInt());
        assertEquals(1, r.j("EM_ATENDIMENTO").asInt());
        assertEquals(0, r.j("AGUARDANDO_CLIENTE").asInt());
        assertEquals(2, r.j("RESOLVIDO").asInt());
        assertEquals(1, r.j("FECHADO").asInt());
    }

    @Test
    void produtividadeDosTecnicosNoMes() throws Exception {
        relogio.agora = MARCO;
        long ocioso = inserir("insert into tecnico (empresa_id, nome) values (?, 'Ana Ociosa') returning id", empA);
        long chamado = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-02T12:00:00Z"), Instant.parse("2026-03-10T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 1000) returning id", empA, chamado, tecA, Instant.parse("2026-03-03T12:00:00Z"), Instant.parse("2026-03-03T12:00:00Z"), Instant.parse("2026-03-03T13:00:00Z"));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 2500) returning id", empA, chamado, tecA, Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-04T14:00:00Z"));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CANCELADA', ?, null, null) returning id", empA, chamado, tecA, Instant.parse("2026-03-05T12:00:00Z"));
        R r = get("/relatorios/tecnicos?mes=2026-03", token(ADMIN_A));
        assertEquals(200, r.status());
        assertEquals(2, r.j("itens").size());
        assertEquals("Tecnico A", r.j("itens").get(0).get("nome").asText(), "mais concluídas primeiro");
        assertEquals(2, r.j("itens").get(0).get("concluidas").asInt());
        assertEquals(3500, r.j("itens").get(0).get("valor_cents").asLong());
        assertEquals(1.5, r.j("itens").get(0).get("media_horas").asDouble());
        assertEquals(ocioso, r.j("itens").get(1).get("tecnico_id").asLong());
        assertEquals(0, r.j("itens").get(1).get("concluidas").asInt());
        assertTrue(r.j("itens").get(1).get("media_horas").isNull());
    }

    @Test
    void exportacaoCsvTemOCabecalhoExatoEOsCamposDoChamado() throws Exception {
        relogio.agora = MARCO;
        long ok = chamadoNoBanco(empA, cliA, atdA, "ALTA", "RESOLVIDO", Instant.parse("2026-03-02T12:00:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-03T12:00:00Z"));
        long atrasado = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "FECHADO", Instant.parse("2026-03-02T13:30:00Z"), Instant.parse("2026-03-04T12:00:00Z"), Instant.parse("2026-03-05T12:00:00Z"));
        chamadoNoBanco(empA, cliA, atdA, "BAIXA", "ABERTO", Instant.parse("2026-03-09T12:00:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        atualizar("update chamado set tecnico_id = ? where id = ?", tecA, ok);
        atualizar("update chamado set titulo = 'Roteador; \"novo\" modelo' where id = ?", atrasado);
        R r = get("/relatorios/exportacao.csv?mes=2026-03", token(ADMIN_A));
        assertEquals(200, r.status());
        assertTrue(r.cabecalhos().firstValue("Content-Type").orElse("").startsWith("text/csv"));
        String[] linhas = r.bruto().split("\n", -1);
        assertEquals("ID;Titulo;Cliente;Prioridade;Status;Aberto em;Prazo resolucao;Resolvido em;Tecnico;SLA cumprido", linhas[0]);
        assertEquals(ok + ";Chamado direto;Cliente A;ALTA;RESOLVIDO;2026-03-02 09:00;2026-03-04 09:00;2026-03-03 09:00;Tecnico A;SIM", linhas[1]);
        assertEquals(atrasado + ";\"Roteador; \"\"novo\"\" modelo\";Cliente A;NORMAL;FECHADO;2026-03-02 10:30;2026-03-04 09:00;2026-03-05 09:00;;NAO", linhas[2]);
        assertTrue(linhas[3].endsWith("2026-03-20 09:00;;;"), "chamado em aberto: sem resolução, técnico nem veredito de SLA");
        assertEquals("", linhas[4], "o arquivo termina com quebra de linha");
        assertTrue(!r.bruto().startsWith("﻿"), "UTF-8 sem BOM");
    }

    @Test
    void quemPodeVerCadaRelatorio() throws Exception {
        String admin = token(ADMIN_A);
        String atendente = token(ATENDENTE_A);
        String tecnico = token(TECNICO_A);
        assertEquals(200, get("/relatorios/sla?mes=2026-03", atendente).status());
        assertEquals(200, get("/relatorios/status", atendente).status());
        assertEquals(403, get("/relatorios/tecnicos?mes=2026-03", atendente).status());
        assertEquals(403, get("/relatorios/exportacao.csv?mes=2026-03", atendente).status());
        assertEquals(403, get("/relatorios/sla?mes=2026-03", tecnico).status());
        assertEquals(200, get("/relatorios/tecnicos?mes=2026-03", admin).status());
        String plataforma = token(PLATAFORMA);
        assertEquals(422, get("/relatorios/status", plataforma).status(), "a plataforma precisa dizer a empresa");
        assertEquals(200, get("/relatorios/status?empresa_id=" + empA, plataforma).status());
    }

    @Test
    void mesAusenteOuMalFormadoE422() throws Exception {
        String admin = token(ADMIN_A);
        assertEquals(422, get("/relatorios/sla", admin).status());
        assertEquals(422, get("/relatorios/sla?mes=março", admin).status());
        assertEquals(422, get("/relatorios/exportacao.csv?mes=2026-13", admin).status());
    }

    /** Verificações do relatório por prioridade, separadas para a leitura. */
    static final class JsonNodeAssert {
        static void porPrioridade(R r) {
            var p = r.j("por_prioridade");
            List<String> chaves = new java.util.ArrayList<>();
            p.fieldNames().forEachRemaining(chaves::add);
            assertEquals(List.of("BAIXA", "NORMAL", "ALTA", "CRITICA"), chaves);
            assertEquals(2, p.get("NORMAL").get("abertos").asInt());
            assertEquals(2, p.get("NORMAL").get("resolvidos").asInt());
            assertEquals(1, p.get("NORMAL").get("cumpridos").asInt());
            assertEquals(1, p.get("ALTA").get("abertos").asInt());
            assertEquals(1, p.get("ALTA").get("vencidos_em_aberto").asInt());
            assertEquals(0, p.get("CRITICA").get("abertos").asInt());
            assertTrue(p.get("CRITICA").get("percentual_cumprido").isNull());
        }
    }
}
