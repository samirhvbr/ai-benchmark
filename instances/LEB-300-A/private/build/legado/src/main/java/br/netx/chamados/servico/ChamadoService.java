package br.netx.chamados.servico;

import br.netx.chamados.auth.Auth;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Evento;
import br.netx.chamados.dominio.Dominio.Ordem;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.SlaPolitica;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.dominio.Dominio.StatusOrdem;
import br.netx.chamados.dominio.Dominio.Tecnico;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.ChamadoRepo.Filtro;
import br.netx.chamados.repo.ChamadoRepo.Visao;
import br.netx.chamados.repo.ChamadoRepo;
import br.netx.chamados.repo.IdempotenciaRepo.Chave;
import br.netx.chamados.repo.IdempotenciaRepo;
import br.netx.chamados.repo.NotificacaoRepo.Registro;
import br.netx.chamados.repo.NotificacaoRepo;
import br.netx.chamados.repo.OrdemRepo;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.time.Clock;
import java.time.DayOfWeek;
import java.time.Duration;
import java.time.Instant;
import java.time.LocalDate;
import java.time.ZoneId;
import java.time.ZonedDateTime;
import java.time.format.DateTimeParseException;
import java.util.ArrayList;
import java.util.Collection;
import java.util.EnumMap;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Objects;
import java.util.Optional;
import java.util.function.Function;

/** Chamados, ordens de serviço, notificações, chaves de idempotência e prazos de SLA. */
public final class ChamadoService {
    private final ChamadoRepo chamados;
    private final CadastroRepo cadastros;
    private final OrdemRepo ordens;
    private final NotificacaoRepo notificacaoRepo;
    private final IdempotenciaRepo idempotenciaRepo;
    private final Db db;
    private final Clock clock;

    public ChamadoService(ChamadoRepo chamados, CadastroRepo cadastros, OrdemRepo ordens, NotificacaoRepo notificacaoRepo,
                          IdempotenciaRepo idempotenciaRepo, Db db, Clock clock) {
        this.chamados = chamados;
        this.cadastros = cadastros;
        this.ordens = ordens;
        this.notificacaoRepo = notificacaoRepo;
        this.idempotenciaRepo = idempotenciaRepo;
        this.db = db;
        this.clock = clock;
    }

    public Visao abrir(Tx tx, Principal p, long clienteId, String titulo, String descricao, Prioridade prioridade) {
        long empresaId = p.empresaId();
        cadastros.cliente(tx, clienteId, empresaId).orElseThrow(() -> Erro.invalido("cliente inexistente nesta empresa"));
        Empresa empresa = cadastros.empresa(tx, empresaId).orElseThrow(Erro::naoEncontrado);
        Instant agora = clock.instant();
        Prazos prazos = prazos(empresa, cadastros.politicas(tx, empresaId), prioridade, agora);
        long id = chamados.inserir(tx, empresaId, clienteId, titulo, descricao, prioridade, agora, prazos.resposta(), prazos.resolucao(), p.usuarioId());
        long evento = chamados.inserirEvento(tx, id, "ABERTURA", p.usuarioId(), "chamado aberto", agora);
        criar(tx, empresaId, "aberto", id, evento, atendentesEAdmins(tx, empresaId),
                "Chamado " + id + " aberto", titulo);
        return chamados.visao(tx, id, empresaId).orElseThrow();
    }

    public Pagina<Visao> listar(Tx tx, Principal p, Long empresaIdPedida, String status, String prioridade, Long tecnicoId, int pagina, int tamanho) {
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
        m.put("resolucao_cumprida", c.status().resolvido() ? cumprido(c) : null);
        m.put("segundos_para_vencer", c.status().resolvido() ? null : Duration.between(agora, c.prazoResolucao()).getSeconds());
        m.put("em_risco", c.status().emAndamento() && !agora.isBefore(limiteDeEscalonamento(c.abertoEm(), c.prazoResolucao())));
        return m;
    }

    /** Os chamados de um cliente da empresa, do mais recente para o mais antigo. */
    public Pagina<Visao> doCliente(Tx tx, Principal p, long clienteId, int pagina, int tamanho) {
        cadastros.cliente(tx, clienteId, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        return chamados.doCliente(tx, p.empresaId(), clienteId, pagina, tamanho);
    }

    public List<Evento> eventos(Tx tx, Principal p, long id) {
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
            Prazos prazos = prazos(cadastros.empresa(tx, atual.empresaId()).orElseThrow(), cadastros.politicas(tx, atual.empresaId()), nova, atual.abertoEm());
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
        campos.putIfAbsent("titulo", atual.titulo());
        campos.putIfAbsent("descricao", atual.descricao());
        campos.putIfAbsent("prioridade", atual.prioridade());
        campos.putIfAbsent("tecnico_id", atual.tecnicoId());
        campos.putIfAbsent("prazo_resposta", atual.prazoResposta());
        campos.putIfAbsent("prazo_resolucao", atual.prazoResolucao());
        chamados.atualizar(tx, id, campos);
        if (mudouPrioridade) chamados.inserirEvento(tx, id, "PRIORIDADE", p.usuarioId(), atual.prioridade() + " -> " + nova, agora);
        if (mudouTecnico) {
            Long tecnico = (Long) campos.get("tecnico_id");
            long evento = chamados.inserirEvento(tx, id, "ATRIBUICAO", p.usuarioId(), "tecnico_id=" + tecnico, agora);
            criar(tx, atual.empresaId(), "atribuido", id, evento, doTecnico(tx, tecnico), "Chamado " + id + " atribuído a você", atual.titulo());
        }
        return chamados.visao(tx, id, atual.empresaId()).orElseThrow();
    }

    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {
        Optional<Chamado> encontrado = chamados.travar(tx, id, p.empresaId());
        if (encontrado.isPresent()) {
            Chamado c = encontrado.get();
            if (p.empresaId() != null) {
                if (c.status().pode(para)) {
                    Instant agora = clock.instant();
                    Map<String, Object> campos = new LinkedHashMap<>();
                    campos.put("status", para);
                    if (para == StatusChamado.EM_ATENDIMENTO) {
                        if (c.primeiraRespostaEm() == null) {
                            campos.put("primeira_resposta_em", agora);
                        }
                    } else {
                        if (para == StatusChamado.RESOLVIDO) {
                            campos.put("resolvido_em", agora);
                        }
                    }
                    if (c.status() == StatusChamado.RESOLVIDO) {
                        if (para == StatusChamado.EM_ATENDIMENTO) {
                            campos.put("resolvido_em", null);
                        }
                    }
                    chamados.atualizar(tx, id, campos);
                    chamados.inserirEvento(tx, id, "TRANSICAO", p.usuarioId(), c.status() + " -> " + para, agora);
                    return chamados.visao(tx, id, c.empresaId()).orElseThrow();
                } else {
                    throw Erro.conflito("transição " + c.status() + " -> " + para + " não é permitida");
                }
            } else {
                throw Erro.proibido();
            }
        } else {
            throw Erro.naoEncontrado();
        }
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

    public Ordem criarOrdem(Tx tx, Principal p, long chamadoId, long tecnicoId, String tipo, Instant agendadaPara) {
        Chamado c = chamados.porId(tx, chamadoId, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (c.status().resolvido()) throw Erro.conflito("o chamado já está " + c.status());
        cadastros.tecnico(tx, tecnicoId, c.empresaId()).filter(Tecnico::ativo).orElseThrow(() -> Erro.invalido("técnico inexistente nesta empresa"));
        long id = ordens.inserir(tx, c.empresaId(), chamadoId, tecnicoId, tipo, agendadaPara);
        return ordens.porId(tx, id, c.empresaId()).orElseThrow();
    }

    public Pagina<Ordem> listarOrdens(Tx tx, Principal p, Long empresaIdPedida, StatusOrdem status, Long tecnicoId, int pagina, int tamanho) {
        Long empresa = p.plataforma() ? empresaIdPedida : p.empresaId();
        Long soDoTecnico = p.papel() == Papel.TECNICO ? p.usuarioId() : null;
        return ordens.listar(tx, empresa, status, tecnicoId, soDoTecnico, pagina, tamanho);
    }

    public Ordem detalharOrdem(Tx tx, Principal p, long id) {
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
    public Ordem reagendarOrdem(Tx tx, Principal p, long id, Long tecnicoId, Instant agendadaPara) {
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (o.status() != StatusOrdem.AGENDADA) throw Erro.conflito("só uma ordem AGENDADA pode ser reagendada");
        if (tecnicoId != null) cadastros.tecnico(tx, tecnicoId, o.empresaId()).filter(Tecnico::ativo).orElseThrow(() -> Erro.invalido("técnico inexistente nesta empresa"));
        ordens.reagendar(tx, id, tecnicoId, agendadaPara);
        chamados.inserirEvento(tx, o.chamadoId(), "REAGENDAMENTO", p.usuarioId(), "ordem " + id + " para " + agendadaPara, clock.instant());
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem iniciarOrdem(Tx tx, Principal p, long id) {
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        atribuidaA(tx, p, o);
        if (o.status() != StatusOrdem.AGENDADA) throw Erro.conflito("só uma ordem AGENDADA pode ser iniciada");
        ordens.iniciar(tx, id, clock.instant());
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem concluirOrdem(Tx tx, Principal p, long id, long valorCents, String observacoes) {
        if (valorCents < 0) throw Erro.invalido("valor_cents não pode ser negativo");
        Ordem o = ordens.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        atribuidaA(tx, p, o);
        if (o.status() != StatusOrdem.EM_EXECUCAO) throw Erro.conflito("só uma ordem EM_EXECUCAO pode ser concluída");
        Instant agora = clock.instant();
        ordens.concluir(tx, id, agora, valorCents, observacoes);
        Chamado c = chamados.travar(tx, o.chamadoId(), o.empresaId()).orElseThrow();
        long evento = chamados.inserirEvento(tx, c.id(), "ORDEM_CONCLUIDA", p.usuarioId(), "ordem " + id, agora);
        criar(tx, o.empresaId(), "ordem_concluida", c.id(), evento, doUsuario(tx, c.criadoPor()),
                "Ordem " + id + " concluída", c.titulo());
        resolverSeTerminou(tx, c, agora, p);
        return ordens.porId(tx, id, o.empresaId()).orElseThrow();
    }

    public Ordem cancelarOrdem(Tx tx, Principal p, long id, String motivo) {
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
        c = chamados.travar(tx, c.id(), c.empresaId()).orElseThrow();
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

    public void criar(Tx tx, long empresaId, String tipo, long chamadoId, long eventoId, Collection<String> destinatarios, String assunto, String corpo) {
        for (String destinatario : new LinkedHashSet<>(destinatarios)) {
            notificacaoRepo.inserir(tx, empresaId, tipo, chamadoId, eventoId, destinatario, assunto, corpo, clock.instant());
        }
    }

    public Pagina<Registro> listarNotificacoes(Tx tx, long empresaId, String estado, int pagina, int tamanho) {
        return notificacaoRepo.listar(tx, empresaId, estado, pagina, tamanho);
    }

    public static String chave(String tipo, long chamadoId, long eventoId) {
        return tipo + ":" + chamadoId + ":" + eventoId;
    }

    public List<String> atendentesEAdmins(Tx tx, long empresaId) {
        return notificacaoRepo.emailsAtendentesEAdmins(tx, empresaId);
    }

    public List<String> doTecnico(Tx tx, Long tecnicoId) {
        return tecnicoId == null ? List.of() : notificacaoRepo.emailDoTecnico(tx, tecnicoId).map(List::of).orElse(List.of());
    }

    public List<String> doUsuario(Tx tx, long usuarioId) {
        return notificacaoRepo.emailDoUsuario(tx, usuarioId).map(List::of).orElse(List.of());
    }

    /** Atendentes, administradores e o técnico atribuído, sem repetir ninguém. */
    public List<String> equipeDoChamado(Tx tx, long empresaId, Long tecnicoId) {
        List<String> todos = new ArrayList<>(atendentesEAdmins(tx, empresaId));
        todos.addAll(doTecnico(tx, tecnicoId));
        return new ArrayList<>(new LinkedHashSet<>(todos));
    }

    private static final Duration VALIDADE = Duration.ofHours(24);

    public record Resultado(int status, String corpo) {}

    private final ObjectMapper json = new ObjectMapper();

    public Resultado executar(Principal p, String rota, String chave, JsonNode corpo, int statusSucesso, Function<Tx, Object> operacao) {
        return db.tx(tx -> {
            if (chave == null) return resultado(statusSucesso, operacao.apply(tx));
            long empresa = p.empresaId();
            String impressao = impressao(corpo);
            idempotenciaRepo.expirar(tx, empresa, rota, chave, clock.instant().minus(VALIDADE));
            if (!idempotenciaRepo.reservar(tx, empresa, rota, chave, impressao, clock.instant())) {
                Optional<Chave> anterior = idempotenciaRepo.buscar(tx, empresa, rota, chave);
                Chave c = anterior.orElseThrow(() -> Erro.conflito("chave de idempotência em uso"));
                if (!c.corpoHash().equals(impressao)) throw Erro.invalido("a chave de idempotência já foi usada com outro corpo");
                if (c.status() == null) throw Erro.conflito("pedido com esta chave ainda em andamento");
                return new Resultado(c.status(), c.resposta());
            }
            Resultado r = resultado(statusSucesso, operacao.apply(tx));
            idempotenciaRepo.concluir(tx, empresa, rota, chave, r.status(), r.corpo());
            return r;
        });
    }

    private Resultado resultado(int status, Object corpo) {
        try {
            return new Resultado(status, json.writeValueAsString(corpo));
        } catch (JsonProcessingException e) {
            throw new IllegalStateException(e);
        }
    }

    /** Impressão digital do corpo do pedido, para reconhecer a mesma chave com corpo diferente. Não guarda senha. */
    private static String impressao(JsonNode corpo) {
        return Auth.sha256Hex(corpo == null ? "" : corpo.toString());
    }

    /** Padrões do manifesto, seção 3.4, para a prioridade sem linha em sla_politica. */
    private static final Map<Prioridade, SlaPolitica> PADROES = new EnumMap<>(Prioridade.class);

    static {
        PADROES.put(Prioridade.BAIXA, new SlaPolitica(Prioridade.BAIXA, 24, 120, true));
        PADROES.put(Prioridade.NORMAL, new SlaPolitica(Prioridade.NORMAL, 8, 48, true));
        PADROES.put(Prioridade.ALTA, new SlaPolitica(Prioridade.ALTA, 4, 24, false));
        PADROES.put(Prioridade.CRITICA, new SlaPolitica(Prioridade.CRITICA, 1, 8, false));
    }

    public record Prazos(Instant resposta, Instant resolucao) {}

    public SlaPolitica politica(List<SlaPolitica> daEmpresa, Prioridade prioridade) {
        return daEmpresa.stream().filter(p -> p.prioridade() == prioridade).findFirst().orElse(PADROES.get(prioridade));
    }

    public Prazos prazos(Empresa empresa, List<SlaPolitica> daEmpresa, Prioridade prioridade, Instant abertoEm) {
        SlaPolitica p = politica(daEmpresa, prioridade);
        return new Prazos(somarHoras(abertoEm, p.horasResposta(), p.somenteExpediente(), empresa),
                somarHoras(abertoEm, p.horasResolucao(), p.somenteExpediente(), empresa));
    }

    /** Soma horas a partir de um instante. Com {@code somenteExpediente}, só contam seg a sex em [inicio, fim) no fuso da empresa. */
    static Instant somarHoras(Instant inicio, int horas, boolean somenteExpediente, Empresa empresa) {
        if (!somenteExpediente) return inicio.plus(Duration.ofHours(horas));
        ZoneId zona = empresa.zona();
        long restante = horas * 3600L;
        ZonedDateTime t = inicio.atZone(zona);
        while (true) {
            DayOfWeek dia = t.getDayOfWeek();
            ZonedDateTime abre = ZonedDateTime.of(t.toLocalDate(), empresa.expedienteInicio(), zona);
            ZonedDateTime fecha = ZonedDateTime.of(t.toLocalDate(), empresa.expedienteFim(), zona);
            if (dia == DayOfWeek.SATURDAY || dia == DayOfWeek.SUNDAY || !t.isBefore(fecha)) {
                t = ZonedDateTime.of(t.toLocalDate().plusDays(1), empresa.expedienteInicio(), zona);
                continue;
            }
            if (t.isBefore(abre)) t = abre;
            long disponivel = Duration.between(t, fecha).getSeconds();
            if (restante < disponivel) return t.plusSeconds(restante).toInstant();
            restante -= disponivel;
            t = ZonedDateTime.of(t.toLocalDate().plusDays(1), empresa.expedienteInicio(), zona);
        }
    }

    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */
    public Instant limiteDeEscalonamento(Instant abertoEm, Instant prazoResolucao) {
        Duration total = Duration.between(abertoEm, prazoResolucao);
        return abertoEm.plus(total.multipliedBy(8).dividedBy(10));
    }

    public boolean cumprido(Chamado c) {
        return cumprido(c.resolvidoEm(), c.prazoResolucao());
    }

    /** Cumprido quando resolvido dentro do prazo. */
    public boolean cumprido(Instant resolvidoEm, Instant prazoResolucao) {
        return resolvidoEm != null && resolvidoEm.isBefore(prazoResolucao);
    }
}
