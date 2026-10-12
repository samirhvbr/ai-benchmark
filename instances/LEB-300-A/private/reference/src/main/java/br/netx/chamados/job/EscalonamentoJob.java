package br.netx.chamados.job;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.ChamadoRepo.Candidato;
import br.netx.chamados.servico.NotificacaoService;
import br.netx.chamados.servico.SlaService;
import br.netx.chamados.servico.SlaService.Prazos;

import java.time.Clock;
import java.time.Instant;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Chamado em andamento que gastou 80% do prazo de resolução sobe uma prioridade, no máximo uma por passada. */
public final class EscalonamentoJob {
    private final Db db;
    private final ChamadoRepo chamados;
    private final CadastroRepo cadastros;
    private final SlaService sla;
    private final NotificacaoService notificacoes;
    private final Clock clock;

    public EscalonamentoJob(Db db, ChamadoRepo chamados, CadastroRepo cadastros, SlaService sla, NotificacaoService notificacoes, Clock clock) {
        this.db = db;
        this.chamados = chamados;
        this.cadastros = cadastros;
        this.sla = sla;
        this.notificacoes = notificacoes;
        this.clock = clock;
    }

    public int executar() {
        Instant agora = clock.instant();
        List<Candidato> candidatos = db.tx(chamados::candidatosAoEscalonamento);
        List<Long> devidos = new ArrayList<>();
        for (Candidato c : candidatos) {
            if (!agora.isBefore(sla.limiteDeEscalonamento(c.abertoEm(), c.prazoResolucao()))) devidos.add(c.id());
        }
        int escalonados = 0;
        for (long id : devidos) {
            if (db.tx(tx -> escalonar(tx, id, agora))) escalonados++;
        }
        return escalonados;
    }

    private boolean escalonar(Tx tx, long id, Instant agora) {
        Chamado c = chamados.travar(tx, id, null).orElse(null);
        if (c == null || !c.status().emAndamento() || c.prioridade() == Prioridade.CRITICA) return false;
        if (agora.isBefore(sla.limiteDeEscalonamento(c.abertoEm(), c.prazoResolucao()))) return false;
        Prioridade nova = c.prioridade().acima();
        Prazos prazos = sla.prazos(cadastros.empresa(tx, c.empresaId()).orElseThrow(), cadastros.politicas(tx, c.empresaId()), nova, c.abertoEm());
        Map<String, Object> campos = new LinkedHashMap<>();
        campos.put("prioridade", nova);
        campos.put("prazo_resposta", prazos.resposta());
        campos.put("prazo_resolucao", prazos.resolucao());
        chamados.atualizar(tx, id, campos);
        long evento = chamados.inserirEvento(tx, id, "ESCALONAMENTO", null, c.prioridade() + " -> " + nova, agora);
        notificacoes.criar(tx, c.empresaId(), "escalonado", id, evento, notificacoes.equipeDoChamado(tx, c.empresaId(), c.tecnicoId()),
                "Chamado " + id + " escalonado", c.titulo());
        return true;
    }
}
