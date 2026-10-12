package br.netx.chamados.http;

import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Evento;
import br.netx.chamados.dominio.Dominio.Ordem;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Papel;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.StatusChamado;
import br.netx.chamados.dominio.Dominio.StatusOrdem;
import br.netx.chamados.http.Servidor.Req;
import br.netx.chamados.http.Servidor.Resp;
import br.netx.chamados.repo.ChamadoRepo.Visao;
import br.netx.chamados.repo.NotificacaoRepo;
import br.netx.chamados.servico.ChamadoService;
import br.netx.chamados.servico.ChamadoService.Resultado;
import com.fasterxml.jackson.databind.JsonNode;

import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.function.Function;

/** Rotas de chamados, eventos, transições e ordens de serviço. */
public final class ChamadoHandlers {
    private final Db db;
    private final ChamadoService chamados;
    private final ChamadoService ordens;
    private final ChamadoService idempotencia;
    private final ChamadoService notificacoes;

    public ChamadoHandlers(Db db, ChamadoService chamados, ChamadoService ordens, ChamadoService idempotencia, ChamadoService notificacoes) {
        this.db = db;
        this.chamados = chamados;
        this.ordens = ordens;
        this.idempotencia = idempotencia;
        this.notificacoes = notificacoes;
    }

    public void registrar(Servidor s) {
        s.rota("POST", "/chamados", false, this::abrir);
        s.rota("GET", "/chamados", false, this::listar);
        s.rota("GET", "/chamados/{id}", false, r -> Resp.json(200, chamadoJson(db.tx(tx -> chamados.detalhar(tx, leitor(r), r.id())))));
        s.rota("GET", "/chamados/{id}/eventos", false, this::eventos);
        s.rota("PATCH", "/chamados/{id}", false, this::atualizar);
        s.rota("POST", "/chamados/{id}/transicoes", false, this::transicionar);
        s.rota("POST", "/chamados/{id}/eventos", false, this::registrarEvento);
        s.rota("POST", "/chamados/{id}/ordens", false, this::criarOrdem);
        s.rota("GET", "/chamados/{id}/ordens", false, this::ordensDoChamado);
        s.rota("GET", "/chamados/{id}/sla", false, this::resumoDeSla);
        s.rota("GET", "/tecnicos/{id}/agenda", false, this::agenda);
        s.rota("GET", "/clientes/{id}/chamados", false, this::historicoDoCliente);
        s.rota("GET", "/notificacoes", false, this::notificacoes);
        s.rota("PATCH", "/ordens/{id}", false, this::reagendar);
        s.rota("GET", "/ordens", false, this::listarOrdens);
        s.rota("GET", "/ordens/{id}", false, r -> Resp.json(200, ordemJson(db.tx(tx -> ordens.detalharOrdem(tx, leitor(r), r.id())))));
        s.rota("POST", "/ordens/{id}/iniciar", false, this::iniciar);
        s.rota("POST", "/ordens/{id}/concluir", false, this::concluir);
        s.rota("POST", "/ordens/{id}/cancelar", false, this::cancelar);
    }

    /** Quem pode ler: todos os papéis. A empresa e, para o técnico, a atribuição são aplicadas nos serviços. */
    private static Principal leitor(Req r) {
        return r.principal();
    }

    private static Principal escritor(Req r, Papel... aceitos) {
        r.principal().exigir(aceitos);
        return r.principal();
    }

    private Resp abrir(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        long cliente = r.longo("cliente_id");
        String titulo = r.texto("titulo");
        String descricao = r.textoOuPadrao("descricao", "");
        Prioridade prioridade = r.enumeracaoOuPadrao("prioridade", Prioridade.class, Prioridade.NORMAL);
        Resultado res = idempotencia.executar(p, r.metodo() + " " + r.caminho(), r.cabecalho("Idempotency-Key"), r.corpo(), 201,
                tx -> chamadoJson(chamados.abrir(tx, p, cliente, titulo, descricao, prioridade)));
        return Resp.bruto(res.status(), res.corpo());
    }

    private Resp listar(Req r) {
        Principal p = leitor(r);
        String status = r.consulta("status");
        String prioridade = r.consulta("prioridade");
        Long tecnico = r.consulta("tecnico_id") == null ? null : numero(r.consulta("tecnico_id"), "tecnico_id");
        Pagina<Visao> pagina = db.tx(tx -> chamados.listar(tx, p, r.empresaIdPedida(), status, prioridade, tecnico, pagina(r), tamanho(r)));
        return Resp.json(200, pagina(pagina, ChamadoHandlers::chamadoJson));
    }

    private Resp eventos(Req r) {
        List<Evento> eventos = db.tx(tx -> chamados.eventos(tx, leitor(r), r.id()));
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("itens", eventos.stream().map(ChamadoHandlers::eventoJson).toList());
        return Resp.json(200, m);
    }

    private Resp atualizar(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        JsonNode corpo = r.objeto();
        Map<String, Object> patch = new LinkedHashMap<>();
        if (corpo.has("titulo")) patch.put("titulo", r.texto("titulo"));
        if (corpo.has("descricao")) patch.put("descricao", r.textoOuPadrao("descricao", ""));
        if (corpo.has("prioridade")) patch.put("prioridade", r.enumeracao("prioridade", Prioridade.class));
        if (corpo.has("tecnico_id")) patch.put("tecnico_id", corpo.get("tecnico_id").isNull() ? null : (Object) r.longo("tecnico_id"));
        return Resp.json(200, chamadoJson(db.tx(tx -> chamados.atualizar(tx, p, r.id(), patch))));
    }

    private Resp transicionar(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        StatusChamado para = r.enumeracao("para", StatusChamado.class);
        return Resp.json(200, chamadoJson(db.tx(tx -> chamados.transicionar(tx, p, r.id(), para))));
    }

    private Resp registrarEvento(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN, Papel.TECNICO);
        String detalhe = r.texto("detalhe");
        return Resp.json(201, eventoJson(db.tx(tx -> chamados.registrarEvento(tx, p, r.id(), detalhe))));
    }

    private Resp criarOrdem(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        long chamado = r.id();
        long tecnico = r.longo("tecnico_id");
        String tipo = r.texto("tipo");
        var agendada = r.instante("agendada_para");
        Resultado res = idempotencia.executar(p, r.metodo() + " " + r.caminho(), r.cabecalho("Idempotency-Key"), r.corpo(), 201,
                tx -> ordemJson(ordens.criarOrdem(tx, p, chamado, tecnico, tipo, agendada)));
        return Resp.bruto(res.status(), res.corpo());
    }

    private Resp ordensDoChamado(Req r) {
        List<Ordem> lista = db.tx(tx -> ordens.ordensDoChamado(tx, leitor(r), r.id()));
        return Resp.json(200, Map.of("itens", lista.stream().map(ChamadoHandlers::ordemJson).toList()));
    }

    private Resp resumoDeSla(Req r) {
        Map<String, Object> m = db.tx(tx -> chamados.resumoDeSla(tx, leitor(r), r.id()));
        m.computeIfPresent("prazo_resposta", (k, v) -> Servidor.iso((Instant) v));
        m.computeIfPresent("prazo_resolucao", (k, v) -> Servidor.iso((Instant) v));
        return Resp.json(200, m);
    }

    private Resp agenda(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN, Papel.TECNICO);
        List<Ordem> lista = db.tx(tx -> ordens.agenda(tx, p, r.id(), r.consulta("dia")));
        return Resp.json(200, Map.of("itens", lista.stream().map(ChamadoHandlers::ordemJson).toList()));
    }

    private Resp historicoDoCliente(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        Pagina<Visao> pagina = db.tx(tx -> chamados.doCliente(tx, p, r.id(), r.pagina(), r.tamanho()));
        return Resp.json(200, pagina(pagina, ChamadoHandlers::chamadoJson));
    }

    private Resp notificacoes(Req r) {
        Principal p = r.principal();
        if (!p.plataforma()) p.exigir(Papel.ADMIN);
        Long empresa = p.plataforma() ? r.empresaIdPedida() : p.empresaId();
        if (empresa == null) throw Erro.invalido("empresa_id é obrigatório");
        String estado = r.consulta("estado");
        if (estado != null && !List.of("PENDENTE", "ENVIADA", "FALHA").contains(estado)) throw Erro.invalido("estado inválido: " + estado);
        Pagina<NotificacaoRepo.Registro> pagina = db.tx(tx -> notificacoes.listarNotificacoes(tx, empresa, estado, r.pagina(), r.tamanho()));
        return Resp.json(200, pagina(pagina, ChamadoHandlers::notificacaoJson));
    }

    private Resp reagendar(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN);
        JsonNode corpo = r.objeto();
        Long tecnico = corpo.has("tecnico_id") && !corpo.get("tecnico_id").isNull() ? (Long) r.longo("tecnico_id") : null;
        var agendada = r.instante("agendada_para");
        return Resp.json(200, ordemJson(db.tx(tx -> ordens.reagendarOrdem(tx, p, r.id(), tecnico, agendada))));
    }

    private Resp listarOrdens(Req r) {
        Principal p = leitor(r);
        StatusOrdem status = r.enumeracaoDaConsulta("status", StatusOrdem.class);
        Long tecnico = r.consulta("tecnico_id") == null ? null : numero(r.consulta("tecnico_id"), "tecnico_id");
        Pagina<Ordem> pagina = db.tx(tx -> ordens.listarOrdens(tx, p, r.empresaIdPedida(), status, tecnico, pagina(r), tamanho(r)));
        return Resp.json(200, pagina(pagina, ChamadoHandlers::ordemJson));
    }

    private Resp iniciar(Req r) {
        Principal p = escritor(r, Papel.TECNICO);
        return Resp.json(200, ordemJson(db.tx(tx -> ordens.iniciarOrdem(tx, p, r.id()))));
    }

    private Resp concluir(Req r) {
        Principal p = escritor(r, Papel.TECNICO);
        long id = r.id();
        long valor = r.longo("valor_cents");
        String observacoes = r.textoOuPadrao("observacoes", null);
        Resultado res = idempotencia.executar(p, r.metodo() + " " + r.caminho(), r.cabecalho("Idempotency-Key"), r.corpo(), 200,
                tx -> ordemJson(ordens.concluirOrdem(tx, p, id, valor, observacoes)));
        return Resp.bruto(res.status(), res.corpo());
    }

    private Resp cancelar(Req r) {
        Principal p = escritor(r, Papel.ATENDENTE, Papel.ADMIN, Papel.TECNICO);
        String motivo = r.texto("motivo");
        return Resp.json(200, ordemJson(db.tx(tx -> ordens.cancelarOrdem(tx, p, r.id(), motivo))));
    }

    private static int pagina(Req r) {
        String v = r.consulta("pagina");
        if (v == null) return 1;
        try {
            int n = Integer.parseInt(v);
            if (n < 1 || n > Integer.MAX_VALUE) throw new NumberFormatException();
            return n;
        } catch (NumberFormatException e) {
            throw Erro.invalido("pagina deve ser um inteiro entre 1 e " + Integer.MAX_VALUE);
        }
    }

    private static int tamanho(Req r) {
        String v = r.consulta("tamanho");
        if (v == null) return 20;
        try {
            int n = Integer.parseInt(v);
            if (n < 1 || n > 100) throw new NumberFormatException();
            return n;
        } catch (NumberFormatException e) {
            throw Erro.invalido("tamanho deve ser um inteiro entre 1 e 100");
        }
    }

    private static long numero(String texto, String nome) {
        try {
            return Long.parseLong(texto);
        } catch (NumberFormatException e) {
            throw Erro.invalido(nome + " deve ser um número");
        }
    }

    static <T> Map<String, Object> pagina(Pagina<T> p, Function<T, Map<String, Object>> conversor) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("itens", p.itens().stream().map(conversor).toList());
        m.put("pagina", p.pagina());
        m.put("tamanho", p.tamanho());
        m.put("total", p.total());
        return m;
    }

    static Map<String, Object> chamadoJson(Visao v) {
        var c = v.chamado();
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", c.id());
        m.put("empresa_id", c.empresaId());
        m.put("cliente_id", c.clienteId());
        m.put("cliente_nome", v.clienteNome());
        m.put("tecnico_id", c.tecnicoId());
        m.put("tecnico_nome", v.tecnicoNome());
        m.put("titulo", c.titulo());
        m.put("descricao", c.descricao());
        m.put("prioridade", c.prioridade().name());
        m.put("status", c.status().name());
        m.put("aberto_em", Servidor.iso(c.abertoEm()));
        m.put("prazo_resposta", Servidor.iso(c.prazoResposta()));
        m.put("prazo_resolucao", Servidor.iso(c.prazoResolucao()));
        m.put("primeira_resposta_em", Servidor.iso(c.primeiraRespostaEm()));
        m.put("resolvido_em", Servidor.iso(c.resolvidoEm()));
        m.put("criado_por", c.criadoPor());
        return m;
    }

    static Map<String, Object> notificacaoJson(NotificacaoRepo.Registro n) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", n.id());
        m.put("chave_evento", n.chaveEvento());
        m.put("destinatario", n.destinatario());
        m.put("assunto", n.assunto());
        m.put("estado", n.estado());
        m.put("tentativas", n.tentativas());
        m.put("proxima_tentativa_em", Servidor.iso(n.proximaTentativaEm()));
        m.put("enviada_em", Servidor.iso(n.enviadaEm()));
        return m;
    }

    static Map<String, Object> eventoJson(Evento e) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", e.id());
        m.put("chamado_id", e.chamadoId());
        m.put("tipo", e.tipo());
        m.put("usuario_id", e.usuarioId());
        m.put("detalhe", e.detalhe());
        m.put("ocorrido_em", Servidor.iso(e.ocorridoEm()));
        return m;
    }

    static Map<String, Object> ordemJson(Ordem o) {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("id", o.id());
        m.put("empresa_id", o.empresaId());
        m.put("chamado_id", o.chamadoId());
        m.put("tecnico_id", o.tecnicoId());
        m.put("tipo", o.tipo());
        m.put("status", o.status().name());
        m.put("agendada_para", Servidor.iso(o.agendadaPara()));
        m.put("iniciada_em", Servidor.iso(o.iniciadaEm()));
        m.put("concluida_em", Servidor.iso(o.concluidaEm()));
        m.put("valor_cents", o.valorCents());
        m.put("observacoes", o.observacoes());
        return m;
    }
}
