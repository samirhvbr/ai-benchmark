package br.netx.chamados.servico;

import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Evento;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.ChamadoRepo.Filtro;
import br.netx.chamados.repo.ChamadoRepo.Visao;
import br.netx.chamados.servico.SlaService.Prazos;

import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Abertura, consulta, alteração e mudança de estado dos chamados. */
public final class ChamadoService {
    private final ChamadoRepo chamados;
    private final CadastroRepo cadastros;
    private final SlaService sla;
    private final NotificacaoService notificacoes;
    private final Clock clock;

    public ChamadoService(ChamadoRepo chamados, CadastroRepo cadastros, SlaService sla, NotificacaoService notificacoes, Clock clock) {
        this.chamados = chamados;
        this.cadastros = cadastros;
        this.sla = sla;
        this.notificacoes = notificacoes;
        this.clock = clock;
    }

    public Visao abrir(Tx tx, Principal p, long clienteId, String titulo, String descricao, Prioridade prioridade) {
        long empresaId = p.empresaId();
        cadastros.cliente(tx, clienteId, empresaId).orElseThrow(() -> Erro.invalido("cliente inexistente nesta empresa"));
        Empresa empresa = cadastros.empresa(tx, empresaId).orElseThrow(Erro::naoEncontrado);
        Instant agora = clock.instant();
        Prazos prazos = sla.prazos(empresa, cadastros.politicas(tx, empresaId), prioridade, agora);
        long id = chamados.inserir(tx, empresaId, clienteId, titulo, descricao, prioridade, agora, prazos.resposta(), prazos.resolucao(), p.usuarioId());
        long evento = chamados.inserirEvento(tx, id, "ABERTURA", p.usuarioId(), "chamado aberto", agora);
        notificacoes.criar(tx, empresaId, "aberto", id, evento, notificacoes.atendentesEAdmins(tx, empresaId),
                "Chamado " + id + " aberto", titulo);
        return chamados.visao(tx, id, empresaId).orElseThrow();
    }

    public Pagina<Visao> listar(Tx tx, Principal p, Long empresaIdPedida, StatusChamado status, Prioridade prioridade, Long tecnicoId, int pagina, int tamanho) {
        Long empresa = p.plataforma() ? empresaIdPedida : p.empresaId();
        Long soDoTecnico = p.papel() == Papel.TECNICO ? p.usuarioId() : null;
        return chamados.listar(tx, new Filtro(empresa, status, prioridade, tecnicoId, soDoTecnico, pagina, tamanho));
    }

    public Visao detalhar(Tx tx, Principal p, long id) {
        Visao v = chamados.visao(tx, id, p.plataforma() ? null : p.empresaId()).orElseThrow(Erro::naoEncontrado);
        visivelParaTecnico(tx, p, v.chamado());
        return v;
    }

    /** Situação do chamado frente aos prazos, no instante do pedido. */
    public Map<String, Object> resumoDeSla(Tx tx, Principal p, long id) {
        Chamado c = detalhar(tx, p, id).chamado();
        Instant agora = clock.instant();
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("prazo_resposta", c.prazoResposta());
        m.put("prazo_resolucao", c.prazoResolucao());
        m.put("resposta_cumprida", c.primeiraRespostaEm() == null ? null : !c.primeiraRespostaEm().isAfter(c.prazoResposta()));
        m.put("resolucao_cumprida", c.status().resolvido() ? sla.cumprido(c) : null);
        m.put("segundos_para_vencer", c.status().resolvido() ? null : Duration.between(agora, c.prazoResolucao()).getSeconds());
        m.put("em_risco", c.status().emAndamento() && !agora.isBefore(sla.limiteDeEscalonamento(c.abertoEm(), c.prazoResolucao())));
        return m;
    }

    /** Os chamados de um cliente da empresa, do mais recente para o mais antigo. */
    public Pagina<Visao> doCliente(Tx tx, Principal p, long clienteId, int pagina, int tamanho) {
        cadastros.cliente(tx, clienteId, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        return chamados.doCliente(tx, p.empresaId(), clienteId, pagina, tamanho);
    }

    public List<Evento> eventos(Tx tx, Principal p, long id) {
        detalhar(tx, p, id);
        return chamados.eventos(tx, id);
    }

    public Visao atualizar(Tx tx, Principal p, long id, Map<String, Object> patch) {
        Chamado atual = chamados.porId(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        Map<String, Object> campos = new LinkedHashMap<>();
        Instant agora = clock.instant();
        if (patch.containsKey("titulo")) campos.put("titulo", patch.get("titulo"));
        if (patch.containsKey("descricao")) campos.put("descricao", patch.get("descricao"));
        Prioridade nova = (Prioridade) patch.get("prioridade");
        boolean mudouPrioridade = nova != null && nova != atual.prioridade();
        if (mudouPrioridade) {
            Prazos prazos = sla.prazos(cadastros.empresa(tx, atual.empresaId()).orElseThrow(), cadastros.politicas(tx, atual.empresaId()), nova, atual.abertoEm());
            campos.put("prioridade", nova);
            campos.put("prazo_resposta", prazos.resposta());
            campos.put("prazo_resolucao", prazos.resolucao());
        }
        boolean mudouTecnico = patch.containsKey("tecnico_id") && !java.util.Objects.equals(patch.get("tecnico_id"), atual.tecnicoId());
        if (mudouTecnico) {
            Long tecnico = (Long) patch.get("tecnico_id");
            if (tecnico != null) cadastros.tecnico(tx, tecnico, atual.empresaId()).filter(t -> t.ativo()).orElseThrow(() -> Erro.invalido("técnico inexistente nesta empresa"));
            campos.put("tecnico_id", tecnico);
        }
        chamados.atualizar(tx, id, campos);
        if (mudouPrioridade) chamados.inserirEvento(tx, id, "PRIORIDADE", p.usuarioId(), atual.prioridade() + " -> " + nova, agora);
        if (mudouTecnico) {
            Long tecnico = (Long) campos.get("tecnico_id");
            long evento = chamados.inserirEvento(tx, id, "ATRIBUICAO", p.usuarioId(), "tecnico_id=" + tecnico, agora);
            notificacoes.criar(tx, atual.empresaId(), "atribuido", id, evento, notificacoes.doTecnico(tx, tecnico), "Chamado " + id + " atribuído a você", atual.titulo());
        }
        return chamados.visao(tx, id, atual.empresaId()).orElseThrow();
    }

    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {
        Chamado c = chamados.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (!c.status().pode(para)) throw Erro.conflito("transição " + c.status() + " -> " + para + " não é permitida");
        Instant agora = clock.instant();
        Map<String, Object> campos = new LinkedHashMap<>();
        campos.put("status", para);
        if (para == StatusChamado.EM_ATENDIMENTO && c.primeiraRespostaEm() == null) campos.put("primeira_resposta_em", agora);
        if (para == StatusChamado.RESOLVIDO) campos.put("resolvido_em", agora);
        if (c.status() == StatusChamado.RESOLVIDO && para == StatusChamado.EM_ATENDIMENTO) campos.put("resolvido_em", null);
        chamados.atualizar(tx, id, campos);
        chamados.inserirEvento(tx, id, "TRANSICAO", p.usuarioId(), c.status() + " -> " + para, agora);
        return chamados.visao(tx, id, c.empresaId()).orElseThrow();
    }

    public Evento registrarEvento(Tx tx, Principal p, long id, String detalhe) {
        Chamado c = detalhar(tx, p, id).chamado();
        long evento = chamados.inserirEvento(tx, id, "NOTA", p.usuarioId(), detalhe, clock.instant());
        return chamados.eventos(tx, c.id()).stream().filter(e -> e.id() == evento).findFirst().orElseThrow();
    }

    private void visivelParaTecnico(Tx tx, Principal p, Chamado c) {
        if (p.papel() != Papel.TECNICO) return;
        boolean meu = cadastros.tecnicoDoUsuario(tx, p.usuarioId()).map(t -> java.util.Objects.equals(t.id(), c.tecnicoId())).orElse(false);
        if (!meu) throw Erro.naoEncontrado();
    }
}
