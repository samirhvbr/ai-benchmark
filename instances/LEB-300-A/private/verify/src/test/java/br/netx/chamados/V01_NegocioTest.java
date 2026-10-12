package br.netx.chamados;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.time.Instant;
import java.util.Map;
import java.util.TimeZone;

/** Falhas de regra de negócio: máquina de estados, fronteiras do SLA, arredondamento e fuso. */
class V01_NegocioTest extends TestBase {
    private TimeZone fusoOriginal;

    @BeforeEach
    void fusoDaJvmLongeDaEmpresa() {
        fusoOriginal = TimeZone.getDefault();
        TimeZone.setDefault(TimeZone.getTimeZone("Asia/Tokyo"));   // a empresa está em São Paulo: nenhuma regra pode depender do fuso da JVM
    }

    @AfterEach
    void restaurarFuso() {
        TimeZone.setDefault(fusoOriginal);
    }

    @Test
    @Verifica({"BUG-009"})
    void bug009_aReaberturaDeUmChamadoResolvidoELimpaAResolucao() throws Exception {
        String t = token(ATENDENTE_A);
        long id = abrirChamado(t, cliA, "reabrir", "NORMAL");
        post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO"));
        post("/chamados/" + id + "/transicoes", t, Map.of("para", "RESOLVIDO"));
        R r = post("/chamados/" + id + "/transicoes", t, Map.of("para", "EM_ATENDIMENTO"));
        boolean ok = r.status() == 200 && "EM_ATENDIMENTO".equals(r.j("status").asText()) && r.j("resolvido_em").isNull();
        Resultado.c("BUG-009", ok, ok ? "RESOLVIDO -> EM_ATENDIMENTO aceito e resolvido_em limpo"
                : "a reabertura devolveu " + r.status() + " e resolvido_em=" + (r.corpo() == null ? "?" : r.j("resolvido_em")));
    }

    @Test
    @Verifica({"BUG-007"})
    void bug007_asFronteirasDoSla() throws Exception {
        // (a) resolvido exatamente no prazo: SLA cumprido (inclusive)
        relogio.agora = Instant.parse("2026-03-10T15:00:00Z");
        Instant prazo = Instant.parse("2026-03-05T12:00:00Z");
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-04T12:00:00Z"), prazo, prazo);
        R relatorio = get("/relatorios/sla?mes=2026-03", token(ADMIN_A));
        boolean noPrazo = relatorio.status() == 200 && relatorio.j("cumpridos").asInt() == 1;
        // (b) prazo que cai exatamente no fim do expediente é esse instante, não a abertura do dia seguinte
        relogio.agora = Instant.parse("2026-03-04T13:00:00Z");    // quarta 10:00 em São Paulo; 8 h de expediente terminam às 18:00
        R aberto = post("/chamados", token(ATENDENTE_A), Map.of("cliente_id", cliA, "titulo", "fronteira"));
        boolean noFim = aberto.status() == 201 && "2026-03-04T21:00:00Z".equals(aberto.j("prazo_resposta").asText());
        Resultado.c("BUG-007", noPrazo && noFim, "resolvido no prazo conta=" + noPrazo + "; prazo no fim do expediente mantido=" + noFim);
    }

    @Test
    @Verifica({"BUG-013"})
    void bug013_oArredondamentoEHalfUp() throws Exception {
        relogio.agora = Instant.parse("2026-03-10T15:00:00Z");
        Instant prazo = Instant.parse("2026-03-05T12:00:00Z");
        Instant aberto = Instant.parse("2026-03-04T12:00:00Z");
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", aberto, prazo, Instant.parse("2026-03-05T11:00:00Z"));
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", aberto, prazo, Instant.parse("2026-03-05T11:30:00Z"));
        long tarde = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", aberto, prazo, Instant.parse("2026-03-05T13:00:00Z"));
        String admin = token(ADMIN_A);
        double pct = get("/relatorios/sla?mes=2026-03", admin).j("percentual_cumprido").asDouble();      // 2 de 3 = 66,666...
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 100) returning id", empA, tarde, tecA, aberto, aberto, aberto.plusSeconds(3600));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 100) returning id", empA, tarde, tecA, aberto, aberto, aberto.plusSeconds(5400));
        double media = get("/relatorios/tecnicos?mes=2026-03", admin).j("itens").get(0).get("media_horas").asDouble();   // (1 h + 1,5 h) / 2 = 1,25
        // outra empresa, com números que separam o arredondamento certo de "sempre para cima": 1 de 3 = 33,33 e média de 1,21 h
        Instant prazoB = Instant.parse("2026-03-05T12:00:00Z");
        chamadoNoBanco(empB, cliB, admB, "NORMAL", "RESOLVIDO", aberto, prazoB, Instant.parse("2026-03-05T11:00:00Z"));
        chamadoNoBanco(empB, cliB, admB, "NORMAL", "RESOLVIDO", aberto, prazoB, Instant.parse("2026-03-05T13:00:00Z"));
        long tardeB = chamadoNoBanco(empB, cliB, admB, "NORMAL", "RESOLVIDO", aberto, prazoB, Instant.parse("2026-03-05T14:00:00Z"));
        long tecB = inserir("insert into tecnico (empresa_id, nome) values (?, 'Tecnico B') returning id", empB);
        for (long segundos : new long[] {3600, 5112}) {      // média = 4356 s = 1,21 h
            inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                    + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 100) returning id", empB, tardeB, tecB, aberto, aberto, aberto.plusSeconds(segundos));
        }
        relogio.agora = relogio.agora.plusSeconds(120);      // o relatório de SLA pode vir de um cache de 30 s: outra falha, não a medida aqui
        String adminB = token(ADMIN_B);
        double pctB = get("/relatorios/sla?mes=2026-03", adminB).j("percentual_cumprido").asDouble();
        double mediaB = get("/relatorios/tecnicos?mes=2026-03", adminB).j("itens").get(0).get("media_horas").asDouble();
        boolean ok = pct == 66.7 && media == 1.3 && pctB == 33.3 && mediaB == 1.2;
        Resultado.c("BUG-013", ok, "percentual=" + pct + " (esperado 66.7), media_horas=" + media + " (esperado 1.3); outra empresa: percentual=" + pctB + " (esperado 33.3), media_horas=" + mediaB + " (esperado 1.2)");
    }

    @Test
    @Verifica({"BUG-006"})
    void bug006_oFusoEODaEmpresaENaoODaJvm() throws Exception {
        relogio.agora = Instant.parse("2026-05-02T12:00:00Z");
        // 2026-03-01T01:00Z ainda é 28 de fevereiro às 22:00 em São Paulo
        chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", Instant.parse("2026-03-01T01:00:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        String admin = token(ADMIN_A);
        int fev = get("/relatorios/sla?mes=2026-02", admin).j("abertos").asInt();
        int mar = get("/relatorios/sla?mes=2026-03", admin).j("abertos").asInt();
        // competência: concluída em 31/03 às 23:30 em São Paulo (= 01/04 02:30 UTC = 01/04 11:30 em Tóquio)
        long chamado = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-30T12:00:00Z"), Instant.parse("2026-04-05T12:00:00Z"), Instant.parse("2026-04-01T02:30:00Z"));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 900) returning id", empA, chamado, tecA, Instant.parse("2026-03-31T20:00:00Z"), Instant.parse("2026-04-01T01:00:00Z"), Instant.parse("2026-04-01T02:30:00Z"));
        app.jobs().executarUmaVez();
        String competencia = String.valueOf(consultar("select competencia from lancamento where empresa_id = ?", empA).get(0).get("competencia"));
        // uma empresa em Manaus (UTC-4): 2026-03-01T03:30Z ainda é 28 de fevereiro às 23:30 lá, mas já é março em São Paulo e em Tóquio
        long empM = inserir("insert into empresa (nome, fuso_horario) values ('Manaus', 'America/Manaus') returning id");
        long admM = usuario(empM, "admin@manaus.test", "ADMIN");
        long cliM = inserir("insert into cliente (empresa_id, nome) values (?, 'Cliente M') returning id", empM);
        long tecM = inserir("insert into tecnico (empresa_id, nome) values (?, 'Tecnico M') returning id", empM);
        chamadoNoBanco(empM, cliM, admM, "NORMAL", "ABERTO", Instant.parse("2026-03-01T03:30:00Z"), Instant.parse("2026-03-20T12:00:00Z"), null);
        long chamadoM = chamadoNoBanco(empM, cliM, admM, "NORMAL", "RESOLVIDO", Instant.parse("2026-03-30T12:00:00Z"), Instant.parse("2026-04-05T12:00:00Z"), Instant.parse("2026-04-01T03:30:00Z"));
        inserir("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "values (?, ?, ?, 'V', 'CONCLUIDA', ?, ?, ?, 900) returning id", empM, chamadoM, tecM, Instant.parse("2026-03-31T20:00:00Z"), Instant.parse("2026-04-01T01:00:00Z"), Instant.parse("2026-04-01T03:30:00Z"));
        relogio.agora = relogio.agora.plusSeconds(120);      // idem: a leitura da empresa de Manaus não pode vir do cache da outra
        String adminM = token("admin@manaus.test");
        int fevM = get("/relatorios/sla?mes=2026-02", adminM).j("abertos").asInt();
        int marM = get("/relatorios/sla?mes=2026-03", adminM).j("abertos").asInt();
        app.jobs().executarUmaVez();
        String competenciaM = String.valueOf(consultar("select competencia from lancamento where empresa_id = ?", empM).get(0).get("competencia"));
        boolean ok = fev == 1 && mar == 0 && "2026-03".equals(competencia) && fevM == 1 && marM == 1 && "2026-03".equals(competenciaM);
        Resultado.c("BUG-006", ok, "São Paulo: fevereiro=" + fev + " março=" + mar + " competência=" + competencia + " (esperado 1, 0, 2026-03); Manaus: fevereiro=" + fevM + " março=" + marM
                + " competência=" + competenciaM + " (esperado 1, 1, 2026-03); com a JVM em Tóquio");
    }
}
