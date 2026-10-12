package br.netx.chamados.servico;

import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Ordem;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.dominio.Dominio.StatusOrdem;
import br.netx.chamados.dominio.Dominio.Tecnico;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.OrdemRepo;

import java.time.Clock;
import java.time.Instant;
import java.time.LocalDate;
import java.time.format.DateTimeParseException;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Objects;

/** Criação e ciclo de vida das ordens de serviço. Fechar a última ordem resolve o chamado. */
public final class OrdemService {
    private final OrdemRepo ordens;
    private final ChamadoRepo chamados;
    private final CadastroRepo cadastros;
    private final NotificacaoService notificacoes;
    private final Clock clock;

    public OrdemService(OrdemRepo ordens, ChamadoRepo chamados, CadastroRepo cadastros, NotificacaoService notificacoes, Clock clock) {
        this.ordens = ordens;
        this.chamados = chamados;
        this.cadastros = cadastros;
        this.notificacoes = notificacoes;
        this.clock = clock;
    }

    public Ordem criar(Tx tx, Principal p, long chamadoId, long tecnicoId, String tipo, Instant agendadaPara) {
        Chamado c = chamados.porId(tx, chamadoId, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (c.status().resolvido()) throw Erro.conflito("o chamado já está " + c.status());
        cadastros.tecnico(tx, tecnicoId, c.empresaId()).filter(Tecnico::ativo).orElseThrow(() -> Erro.invalido("técnico inexistente nesta empresa"));
        long id = ordens.inserir(tx, c.empresaId(), chamadoId, tecnicoId, tipo, agendadaPara);
        return ordens.porId(tx, id, c.empresaId()).orElseThrow();
    }

    public Pagina<Ordem> listar(Tx tx, Principal p, Long empresaIdPedida, StatusOrdem status, Long tecnicoId, int pagina, int tamanho) {
        Long empresa = p.plataforma() ? empresaIdPedida : p.empresaId();
        Long soDoTecnico = p.papel() == Papel.TECNICO ? p.usuarioId() : null;
        return ordens.listar(tx, empresa, status, tecnicoId, soDoTecnico, pagina, tamanho);
    }

    public Ordem detalhar(Tx tx, Principal p, long id) {
        Ordem o = ordens.porId(tx, id, p.plataforma() ? null : p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (p.papel() == Papel.TECNICO) atribuidaA(tx, p, o);
        return o;
    }

    /** As ordens do chamado; o técnico só vê as suas. */
    public List<Ordem> ordensDoChamado(Tx tx, Principal p, long chamadoId) {
        Chamado c = chamados.porId(tx, chamadoId, p.plataforma() ? null : p.empresaId()).orElseThrow(Erro::naoEncontrado);
        List<Ordem> doChamado = ordens.doChamado(tx, c.id(), c.empresaId());
        if (p.papel() != Papel.TECNICO) return doChamado;
        return cadastros.tecnicoDoUsuario(tx, p.usuarioId())
                .map(t -> doChamado.stream().filter(o -> o.tecnicoId() == t.id()).toList()).orElse(List.of());
    }

    /** O dia do técnico no fuso da empresa. O técnico só vê a própria agenda. */
    public List<Ordem> agenda(Tx tx, Principal p, long tecnicoId, String dia) {
        Tecnico t = cadastros.tecnico(tx, tecnicoId, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (p.papel() == Papel.TECNICO && !java.util.Objects.equals(t.usuarioId(), p.usuarioId())) throw Erro.naoEncontrado();
        LocalDate data;
        try {
            data = LocalDate.parse(dia);
        } catch (DateTimeParseException | NullPointerException e) {
            throw Erro.invalido("dia deve ser AAAA-MM-DD");
        }
        var zona = cadastros.empresa(tx, t.empresaId()).orElseThrow().zona();
        return ordens.daAgenda(tx, t.empresaId(), tecnicoId, data.atStartOfDay(zona).toInstant(), data.plusDays(1).atStartOfDay(zona).toInstant());
    }

    /** Só ordem AGENDADA muda de data ou de técnico. */
    public Ordem reagendar(Tx tx, Principal p, long id, Long tecnicoId, Instant agendadaPara) {
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (o.status() != StatusOrdem.AGENDADA) throw Erro.conflito("só uma ordem AGENDADA pode ser reagendada");
        if (tecnicoId != null) cadastros.tecnico(tx, tecnicoId, o.empresaId()).filter(Tecnico::ativo).orElseThrow(() -> Erro.invalido("técnico inexistente nesta empresa"));
        ordens.reagendar(tx, id, tecnicoId, agendadaPara);
        chamados.inserirEvento(tx, o.chamadoId(), "REAGENDAMENTO", p.usuarioId(), "ordem " + id + " para " + agendadaPara, clock.instant());
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem iniciar(Tx tx, Principal p, long id) {
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        atribuidaA(tx, p, o);
        if (o.status() != StatusOrdem.AGENDADA) throw Erro.conflito("só uma ordem AGENDADA pode ser iniciada");
        ordens.iniciar(tx, id, clock.instant());
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem concluir(Tx tx, Principal p, long id, long valorCents, String observacoes) {
        if (valorCents < 0) throw Erro.invalido("valor_cents não pode ser negativo");
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        atribuidaA(tx, p, o);
        if (o.status() != StatusOrdem.EM_EXECUCAO) throw Erro.conflito("só uma ordem EM_EXECUCAO pode ser concluída");
        Instant agora = clock.instant();
        ordens.concluir(tx, id, agora, valorCents, observacoes);
        Chamado c = chamados.travar(tx, o.chamadoId(), o.empresaId()).orElseThrow();
        long evento = chamados.inserirEvento(tx, c.id(), "ORDEM_CONCLUIDA", p.usuarioId(), "ordem " + id, agora);
        notificacoes.criar(tx, o.empresaId(), "ordem_concluida", c.id(), evento, notificacoes.doUsuario(tx, c.criadoPor()),
                "Ordem " + id + " concluída", c.titulo());
        resolverSeTerminou(tx, c, agora, p);
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem cancelar(Tx tx, Principal p, long id, String motivo) {
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (p.papel() == Papel.TECNICO) atribuidaA(tx, p, o);
        if (o.status() != StatusOrdem.AGENDADA && o.status() != StatusOrdem.EM_EXECUCAO) throw Erro.conflito("a ordem já está " + o.status());
        ordens.cancelar(tx, id, motivo);
        Chamado c = chamados.travar(tx, o.chamadoId(), o.empresaId()).orElseThrow();
        resolverSeTerminou(tx, c, clock.instant(), p);
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    /** Todas as ordens concluídas ou canceladas, com ao menos uma concluída: o chamado em andamento passa a RESOLVIDO. */
    private void resolverSeTerminou(Tx tx, Chamado c, Instant agora, Principal p) {
        if (!c.status().emAndamento()) return;
        List<StatusOrdem> todas = ordens.statusDoChamado(tx, c.id());
        boolean terminou = todas.stream().allMatch(s -> s == StatusOrdem.CONCLUIDA || s == StatusOrdem.CANCELADA);
        if (!terminou || !todas.contains(StatusOrdem.CONCLUIDA)) return;
        Map<String, Object> campos = new LinkedHashMap<>();
        campos.put("status", StatusChamado.RESOLVIDO);
        campos.put("resolvido_em", agora);
        chamados.atualizar(tx, c.id(), campos);
        chamados.inserirEvento(tx, c.id(), "TRANSICAO", p.usuarioId(), c.status() + " -> RESOLVIDO (todas as ordens encerradas)", agora);
    }

    private void atribuidaA(Tx tx, Principal p, Ordem o) {
        boolean minha = cadastros.tecnicoDoUsuario(tx, p.usuarioId()).map(t -> Objects.equals(t.id(), o.tecnicoId())).orElse(false);
        if (!minha) throw Erro.naoEncontrado();
    }
}
