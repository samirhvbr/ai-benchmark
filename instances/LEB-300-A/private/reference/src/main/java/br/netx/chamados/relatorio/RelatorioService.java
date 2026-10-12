package br.netx.chamados.relatorio;

import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.OrdemRepo;
import br.netx.chamados.repo.OrdemRepo.Produtividade;
import br.netx.chamados.servico.SlaService;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.YearMonth;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

/** SLA do mês, contagem por status, produtividade dos técnicos e a exportação. */
public final class RelatorioService {
    /** O relatório de SLA de uma empresa e mês é refeito, no máximo, a cada 30 segundos pelo Clock. */
    private static final Duration VALIDADE_SLA = Duration.ofSeconds(30);

    private record Guardado(Instant em, Map<String, Object> relatorio) {}

    private final Db db;
    private final CadastroRepo cadastros;
    private final ChamadoRepo chamados;
    private final OrdemRepo ordens;
    private final SlaService sla;
    private final CsvExportador csv;
    private final Clock clock;
    private final Map<String, Guardado> cacheSla = new ConcurrentHashMap<>();

    public RelatorioService(Db db, CadastroRepo cadastros, ChamadoRepo chamados, OrdemRepo ordens, SlaService sla, CsvExportador csv, Clock clock) {
        this.db = db;
        this.cadastros = cadastros;
        this.chamados = chamados;
        this.ordens = ordens;
        this.sla = sla;
        this.csv = csv;
        this.clock = clock;
    }

    public Map<String, Object> sla(Principal p, Long empresaPedida, String mes) {
        long empresaId = empresa(p, empresaPedida);
        String chave = empresaId + ":" + mes;
        Instant agora = clock.instant();
        Guardado g = cacheSla.get(chave);
        if (g != null && Duration.between(g.em(), agora).compareTo(VALIDADE_SLA) < 0) return g.relatorio();
        Map<String, Object> relatorio = db.tx(tx -> {
            Empresa e = cadastros.empresa(tx, empresaId).orElseThrow(Erro::naoEncontrado);
            Instant[] periodo = periodo(mes, e);
            List<Chamado> doMes = chamados.abertosNoPeriodo(tx, empresaId, periodo[0], periodo[1]);
            Map<String, Object> r = new LinkedHashMap<>();
            r.put("mes", mes);
            r.putAll(resumo(doMes, agora));
            Map<String, Object> porPrioridade = new LinkedHashMap<>();
            for (Prioridade pr : Prioridade.values()) porPrioridade.put(pr.name(), resumo(doMes.stream().filter(c -> c.prioridade() == pr).toList(), agora));
            r.put("por_prioridade", porPrioridade);
            return r;
        });
        cacheSla.put(chave, new Guardado(agora, relatorio));
        return relatorio;
    }

    private Map<String, Object> resumo(List<Chamado> lista, Instant agora) {
        long resolvidos = lista.stream().filter(c -> c.status().resolvido()).count();
        long cumpridos = lista.stream().filter(c -> c.status().resolvido() && sla.cumprido(c)).count();
        long vencidos = lista.stream().filter(c -> !c.status().resolvido() && c.prazoResolucao().isBefore(agora)).count();
        Map<String, Object> r = new LinkedHashMap<>();
        r.put("abertos", lista.size());
        r.put("resolvidos", resolvidos);
        r.put("cumpridos", cumpridos);
        r.put("vencidos_em_aberto", vencidos);
        r.put("percentual_cumprido", resolvidos == 0 ? null
                : BigDecimal.valueOf(cumpridos * 100).divide(BigDecimal.valueOf(resolvidos), 1, RoundingMode.HALF_UP));
        return r;
    }

    public Map<String, Object> status(Principal p, Long empresaPedida) {
        long empresaId = empresa(p, empresaPedida);
        Map<StatusChamado, Long> contagem = db.tx(tx -> chamados.contagemPorStatus(tx, empresaId));
        Map<String, Object> r = new LinkedHashMap<>();
        for (StatusChamado s : StatusChamado.values()) r.put(s.name(), contagem.getOrDefault(s, 0L));
        return r;
    }

    public List<Map<String, Object>> tecnicos(Principal p, Long empresaPedida, String mes) {
        long empresaId = empresa(p, empresaPedida);
        return db.tx(tx -> {
            Empresa e = cadastros.empresa(tx, empresaId).orElseThrow(Erro::naoEncontrado);
            Instant[] periodo = periodo(mes, e);
            List<Map<String, Object>> saida = new ArrayList<>();
            for (Produtividade t : ordens.produtividade(tx, empresaId, periodo[0], periodo[1])) {
                Map<String, Object> m = new LinkedHashMap<>();
                m.put("tecnico_id", t.tecnicoId());
                m.put("nome", t.nome());
                m.put("concluidas", t.concluidas());
                m.put("valor_cents", t.somaCents());
                m.put("media_horas", t.mediaHoras() == null ? null : t.mediaHoras().setScale(1, RoundingMode.HALF_UP));
                saida.add(m);
            }
            return saida;
        });
    }

    public String exportacao(Principal p, Long empresaPedida, String mes) {
        long empresaId = empresa(p, empresaPedida);
        return db.tx(tx -> {
            Empresa e = cadastros.empresa(tx, empresaId).orElseThrow(Erro::naoEncontrado);
            Instant[] periodo = periodo(mes, e);
            return csv.gerar(chamados.paraExportacao(tx, empresaId, periodo[0], periodo[1]), e.zona());
        });
    }

    private static long empresa(Principal p, Long pedida) {
        Long id = p.plataforma() ? pedida : p.empresaId();
        if (id == null) throw Erro.invalido("empresa_id é obrigatório");
        return id;
    }

    /** O mês (YYYY-MM) no fuso da empresa, como [início, fim) em instantes. */
    private static Instant[] periodo(String mes, Empresa e) {
        if (mes == null) throw Erro.invalido("mes é obrigatório (YYYY-MM)");
        try {
            YearMonth ym = YearMonth.parse(mes);
            return new Instant[] {ym.atDay(1).atStartOfDay(e.zona()).toInstant(), ym.plusMonths(1).atDay(1).atStartOfDay(e.zona()).toInstant()};
        } catch (DateTimeParseException ex) {
            throw Erro.invalido("mes deve ser YYYY-MM");
        }
    }
}
