package br.netx.chamados.repo;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Chamado;
import br.netx.chamados.dominio.Dominio.Evento;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.Prioridade;
import br.netx.chamados.dominio.Dominio.StatusChamado;

import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.Set;

/** Chamados e a trilha de eventos. */
public final class ChamadoRepo {
    private static final String COLUNAS = "c.id, c.empresa_id, c.cliente_id, c.tecnico_id, c.titulo, c.descricao, c.prioridade, c.status, c.aberto_em, "
            + "c.prazo_resposta, c.prazo_resolucao, c.primeira_resposta_em, c.resolvido_em, c.criado_por";
    private static final Set<String> CAMPOS = Set.of("titulo", "descricao", "prioridade", "tecnico_id", "prazo_resposta", "prazo_resolucao",
            "status", "primeira_resposta_em", "resolvido_em");

    /** Chamado com os nomes que as listas e o detalhe mostram. */
    public record Visao(Chamado chamado, String clienteNome, String tecnicoNome) {}

    /** Critérios de listagem. Campo nulo = sem filtro. */
    public record Filtro(Long empresaId, String status, String prioridade, Long tecnicoId, Long tecnicoUsuarioId, int pagina, int tamanho) {}

    public record Candidato(long id, Instant abertoEm, Instant prazoResolucao) {}

    public record LinhaExportacao(long id, String titulo, String clienteNome, Prioridade prioridade, StatusChamado status, Instant abertoEm,
                                  Instant prazoResolucao, Instant resolvidoEm, String tecnicoNome) {}

    public static Chamado chamado(ResultSet rs) throws SQLException {
        return new Chamado(rs.getLong("id"), rs.getLong("empresa_id"), rs.getLong("cliente_id"), Db.longOuNulo(rs, "tecnico_id"), rs.getString("titulo"),
                rs.getString("descricao"), Prioridade.valueOf(rs.getString("prioridade")), StatusChamado.valueOf(rs.getString("status")),
                Db.instante(rs, "aberto_em"), Db.instante(rs, "prazo_resposta"), Db.instante(rs, "prazo_resolucao"),
                Db.instante(rs, "primeira_resposta_em"), Db.instante(rs, "resolvido_em"), rs.getLong("criado_por"));
    }

    public long inserir(Tx tx, long empresaId, long clienteId, String titulo, String descricao, Prioridade prioridade, Instant abertoEm,
                        Instant prazoResposta, Instant prazoResolucao, long criadoPor) {
        return tx.insert("insert into chamado (empresa_id, cliente_id, titulo, descricao, prioridade, status, aberto_em, prazo_resposta, prazo_resolucao, criado_por) "
                + "values (?, ?, ?, ?, ?, 'ABERTO', ?, ?, ?, ?) returning id",
                empresaId, clienteId, titulo, descricao, prioridade, abertoEm, prazoResposta, prazoResolucao, criadoPor);
    }

    public Optional<Chamado> porId(Tx tx, long id, Long empresaId) {
        return empresaId == null ? tx.one("select " + COLUNAS + " from chamado c where c.id = ?", ChamadoRepo::chamado, id)
                : tx.one("select " + COLUNAS + " from chamado c where c.id = ? and c.empresa_id = ?", ChamadoRepo::chamado, id, empresaId);
    }

    public Optional<Chamado> travar(Tx tx, long id, Long empresaId) {
        return empresaId == null ? tx.one("select " + COLUNAS + " from chamado c where c.id = ? for update", ChamadoRepo::chamado, id)
                : tx.one("select " + COLUNAS + " from chamado c where c.id = ? and c.empresa_id = ? for update", ChamadoRepo::chamado, id, empresaId);
    }

    public Optional<Visao> visao(Tx tx, long id, Long empresaId) {
        String onde = empresaId == null ? "c.id = ?" : "c.id = ? and c.empresa_id = " + empresaId;
        return tx.one(SELECAO + " where " + onde, ChamadoRepo::visao, id);
    }

    private static final String SELECAO = "select " + COLUNAS + ", cl.nome as cliente_nome, t.nome as tecnico_nome from chamado c "
            + "join cliente cl on cl.id = c.cliente_id left join tecnico t on t.id = c.tecnico_id";

    private static Visao visao(ResultSet rs) throws SQLException {
        return new Visao(chamado(rs), rs.getString("cliente_nome"), rs.getString("tecnico_nome"));
    }

    /** Prioridade decrescente, pelo texto de cada nível do enum. */
    private static String ordenacaoPorPrioridade() {
        StringBuilder sb = new StringBuilder("case c.prioridade");
        for (Prioridade p : Prioridade.values()) sb.append(" when '").append(p.name()).append("' then ").append(p.ordinal());
        return sb.append(" end desc").toString();
    }

    public Pagina<Visao> listar(Tx tx, Filtro f) {
        StringBuilder onde = new StringBuilder(" where true");
        List<Object> args = new ArrayList<>();
        if (f.empresaId() != null) { onde.append(" and c.empresa_id = ?"); args.add(f.empresaId()); }
        if (f.status() != null) onde.append(" and c.status = '" + f.status() + "'");
        if (f.prioridade() != null) onde.append(" and c.prioridade = '" + f.prioridade() + "'");
        if (f.tecnicoId() != null) { onde.append(" and c.tecnico_id = ?"); args.add(f.tecnicoId()); }
        if (f.tecnicoUsuarioId() != null) { onde.append(" and t.usuario_id = ?"); args.add(f.tecnicoUsuarioId()); }
        long total = tx.count("select count(*) from chamado c left join tecnico t on t.id = c.tecnico_id" + onde, args.toArray());
        args.add(f.tamanho());
        args.add((f.pagina() - 1) * f.tamanho());
        List<Chamado> basicos = tx.list("select " + COLUNAS + " from chamado c left join tecnico t on t.id = c.tecnico_id" + onde
                + " order by " + ordenacaoPorPrioridade() + ", c.prazo_resolucao, c.id limit ? offset ?", ChamadoRepo::chamado, args.toArray());
        List<Visao> itens = new ArrayList<>();
        for (Chamado c : basicos) {
            String cliente = tx.one("select nome from cliente where id = ?", rs -> rs.getString(1), c.clienteId()).orElse(null);
            String tecnico = c.tecnicoId() == null ? null : tx.one("select nome from tecnico where id = ?", rs -> rs.getString(1), c.tecnicoId()).orElse(null);
            itens.add(new Visao(c, cliente, tecnico));
        }
        return new Pagina<>(itens, f.pagina(), f.tamanho(), total);
    }

    /** Atualiza só as colunas recebidas. */
    public int atualizar(Tx tx, long id, Map<String, Object> campos) {
        List<String> sets = new ArrayList<>();
        List<Object> valores = new ArrayList<>();
        for (Map.Entry<String, Object> e : campos.entrySet()) {
            if (!CAMPOS.contains(e.getKey())) throw new IllegalArgumentException("coluna não permitida: " + e.getKey());
            sets.add(e.getKey() + " = ?");
            valores.add(e.getValue());
        }
        if (sets.isEmpty()) return 0;
        valores.add(id);
        return tx.update("update chamado set " + String.join(", ", sets) + " where id = ?", valores.toArray());
    }

    public Pagina<Visao> doCliente(Tx tx, long empresaId, long clienteId, int pagina, int tamanho) {
        long total = tx.count("select count(*) from chamado c where c.empresa_id = ? and c.cliente_id = ?", empresaId, clienteId);
        List<Visao> itens = tx.list(SELECAO + " where c.empresa_id = ? and c.cliente_id = ? order by c.aberto_em desc, c.id desc limit ? offset ?",
                ChamadoRepo::visao, empresaId, clienteId, tamanho, (pagina - 1) * tamanho);
        return new Pagina<>(itens, pagina, tamanho, total);
    }

    public long inserirEvento(Tx tx, long chamadoId, String tipo, Long usuarioId, String detalhe, Instant quando) {
        return tx.insert("insert into evento_chamado (chamado_id, tipo, usuario_id, detalhe, ocorrido_em) values (?, ?, ?, ?, ?) returning id",
                chamadoId, tipo, usuarioId, detalhe, quando);
    }

    public List<Evento> eventos(Tx tx, long chamadoId) {
        return tx.list("select id, chamado_id, tipo, usuario_id, detalhe, ocorrido_em from evento_chamado where chamado_id = ? order by id",
                rs -> new Evento(rs.getLong("id"), rs.getLong("chamado_id"), rs.getString("tipo"), Db.longOuNulo(rs, "usuario_id"),
                        rs.getString("detalhe"), Db.instante(rs, "ocorrido_em")), chamadoId);
    }

    public List<Chamado> emAndamento(Tx tx) {
        return tx.list("select " + COLUNAS + " from chamado c where c.status in ('ABERTO', 'EM_ATENDIMENTO', 'AGUARDANDO_CLIENTE') "
                + "and c.prioridade <> 'CRITICA' order by c.id", ChamadoRepo::chamado);
    }

    /** Chamados em andamento que ainda não estão na prioridade máxima. */
    public List<Candidato> candidatosAoEscalonamento(Tx tx) {
        return tx.list("select id, aberto_em, prazo_resolucao from chamado where status in ('ABERTO', 'EM_ATENDIMENTO', 'AGUARDANDO_CLIENTE') "
                + "and prioridade <> 'CRITICA' order by id",
                rs -> new Candidato(rs.getLong("id"), Db.instante(rs, "aberto_em"), Db.instante(rs, "prazo_resolucao")));
    }

    // ---- relatórios

    public List<Chamado> abertosNoPeriodo(Tx tx, long empresaId, Instant de, Instant ate) {
        return tx.list("select " + COLUNAS + " from chamado c where c.empresa_id = ? and c.aberto_em >= ? and c.aberto_em < ? order by c.id",
                ChamadoRepo::chamado, empresaId, de, ate);
    }

    public Map<StatusChamado, Long> contagemPorStatus(Tx tx, long empresaId) {
        Map<StatusChamado, Long> contagem = new java.util.EnumMap<>(StatusChamado.class);
        tx.list("select status, count(*) as n from chamado where empresa_id = ? group by status",
                rs -> contagem.put(StatusChamado.valueOf(rs.getString("status")), rs.getLong("n")), empresaId);
        return contagem;
    }

    public List<LinhaExportacao> paraExportacao(Tx tx, long empresaId, Instant de, Instant ate) {
        return tx.list("select c.id, c.titulo, cl.nome as cliente_nome, c.prioridade, c.status, c.aberto_em, c.prazo_resolucao, c.resolvido_em, t.nome as tecnico_nome "
                + "from chamado c join cliente cl on cl.id = c.cliente_id left join tecnico t on t.id = c.tecnico_id "
                + "where c.empresa_id = ? and c.aberto_em >= ? and c.aberto_em < ? order by c.aberto_em, c.id",
                rs -> new LinhaExportacao(rs.getLong("id"), rs.getString("titulo"), rs.getString("cliente_nome"), Prioridade.valueOf(rs.getString("prioridade")),
                        StatusChamado.valueOf(rs.getString("status")), Db.instante(rs, "aberto_em"), Db.instante(rs, "prazo_resolucao"),
                        Db.instante(rs, "resolvido_em"), rs.getString("tecnico_nome")), empresaId, de, ate);
    }
}
