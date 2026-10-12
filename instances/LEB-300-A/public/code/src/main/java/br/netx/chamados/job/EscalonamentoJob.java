package br.netx.chamados.job;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.ChamadoRepo.Candidato;
import br.netx.chamados.servico.ChamadoService;
import br.netx.chamados.servico.ChamadoService.Prazos;

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
    private final ChamadoService sla;
    private final ChamadoService notificacoes;
    private final Clock clock;

    public EscalonamentoJob(Db db, ChamadoRepo chamados, CadastroRepo cadastros, ChamadoService sla, ChamadoService notificacoes, Clock clock) {
        this.db = db;
        this.chamados = chamados;
        this.cadastros = cadastros;
        this.sla = sla;
        this.notificacoes = notificacoes;
        this.clock = clock;
    }

    public int executar() {
        Instant agora = clock.instant();
        List<Chamado> candidatos = db.tx(chamados::emAndamento);
        List<Long> devidos = new ArrayList<>();
        for (Chamado c : candidatos) {
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
        List<String> equipe = new ArrayList<>(tx.list("select email from usuario where empresa_id = ? and ativo and papel in ('ATENDENTE', 'ADMIN') order by email",
                rs -> rs.getString(1), c.empresaId()));
        if (c.tecnicoId() != null) {
            tx.one("select u.email from tecnico t join usuario u on u.id = t.usuario_id where t.id = ? and u.ativo", rs -> rs.getString(1), c.tecnicoId())
                    .ifPresent(email -> {
                        if (!equipe.contains(email)) equipe.add(email);
                    });
        }
        notificacoes.criar(tx, c.empresaId(), "escalonado", id, evento, equipe, "Chamado " + id + " escalonado", c.titulo());
        return true;
    }
}
