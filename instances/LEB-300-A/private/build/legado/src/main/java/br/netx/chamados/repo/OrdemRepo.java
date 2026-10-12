package br.netx.chamados.repo;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Ordem;
import br.netx.chamados.dominio.Dominio.Pagina;
import br.netx.chamados.dominio.Dominio.StatusOrdem;

import java.math.BigDecimal;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Optional;

/** Ordens de serviço, os lançamentos que elas geram e o ponto de retomada do lançamento. */
public final class OrdemRepo {
    private static final String COLUNAS = "o.id, o.empresa_id, o.chamado_id, o.tecnico_id, o.tipo, o.status, o.agendada_para, o.iniciada_em, "
            + "o.concluida_em, o.valor_cents, o.observacoes";

    public record Produtividade(long tecnicoId, String nome, long concluidas, long somaCents, BigDecimal mediaHoras) {}

    public static Ordem ordem(ResultSet rs) throws SQLException {
        return new Ordem(rs.getLong("id"), rs.getLong("empresa_id"), rs.getLong("chamado_id"), rs.getLong("tecnico_id"), rs.getString("tipo"),
                StatusOrdem.valueOf(rs.getString("status")), Db.instante(rs, "agendada_para"), Db.instante(rs, "iniciada_em"),
                Db.instante(rs, "concluida_em"), Db.longOuNulo(rs, "valor_cents"), rs.getString("observacoes"));
    }

    public long inserir(Tx tx, long empresaId, long chamadoId, long tecnicoId, String tipo, Instant agendadaPara) {
        return tx.insert("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para) values (?, ?, ?, ?, 'AGENDADA', ?) returning id",
                empresaId, chamadoId, tecnicoId, tipo, agendadaPara);
    }

    public Optional<Ordem> porId(Tx tx, long id, Long empresaId) {
        return empresaId == null ? tx.one("select " + COLUNAS + " from ordem_servico o where o.id = ?", OrdemRepo::ordem, id)
                : tx.one("select " + COLUNAS + " from ordem_servico o where o.id = ? and o.empresa_id = ?", OrdemRepo::ordem, id, empresaId);
    }

    public Optional<Ordem> travar(Tx tx, long id, Long empresaId) {
        return empresaId == null ? tx.one("select " + COLUNAS + " from ordem_servico o where o.id = ? for update", OrdemRepo::ordem, id)
                : tx.one("select " + COLUNAS + " from ordem_servico o where o.id = ? and o.empresa_id = ? for update", OrdemRepo::ordem, id, empresaId);
    }

    public Pagina<Ordem> listar(Tx tx, Long empresaId, StatusOrdem status, Long tecnicoId, Long tecnicoUsuarioId, int pagina, int tamanho) {
        StringBuilder onde = new StringBuilder(" where true");
        List<Object> args = new ArrayList<>();
        if (empresaId != null) { onde.append(" and o.empresa_id = ?"); args.add(empresaId); }
        if (status != null) { onde.append(" and o.status = ?"); args.add(status); }
        if (tecnicoId != null) { onde.append(" and o.tecnico_id = ?"); args.add(tecnicoId); }
        if (tecnicoUsuarioId != null) { onde.append(" and t.usuario_id = ?"); args.add(tecnicoUsuarioId); }
        String de = " from ordem_servico o join tecnico t on t.id = o.tecnico_id" + onde;
        List<Ordem> todas = tx.list("select " + COLUNAS + de + " order by o.agendada_para, o.id", OrdemRepo::ordem, args.toArray());
        int inicio = Math.min((pagina - 1) * tamanho, todas.size());
        List<Ordem> itens = new ArrayList<>(todas.subList(inicio, Math.min(inicio + tamanho, todas.size())));
        return new Pagina<>(itens, pagina, tamanho, todas.size());
    }

    public List<Ordem> doChamado(Tx tx, long chamadoId, long empresaId) {
        return tx.list("select " + COLUNAS + " from ordem_servico o where o.chamado_id = ? and o.empresa_id = ? order by o.agendada_para, o.id",
                OrdemRepo::ordem, chamadoId, empresaId);
    }

    /** As ordens ainda não canceladas do técnico agendadas em [de, ate), por horário. */
    public List<Ordem> daAgenda(Tx tx, long empresaId, long tecnicoId, Instant de, Instant ate) {
        return tx.list("select " + COLUNAS + " from ordem_servico o where o.empresa_id = ? and o.tecnico_id = ? and o.status <> 'CANCELADA' "
                + "and o.agendada_para >= ? and o.agendada_para < ? order by o.agendada_para, o.id", OrdemRepo::ordem, empresaId, tecnicoId, de, ate);
    }

    public void reagendar(Tx tx, long id, Long tecnicoId, Instant agendadaPara) {
        tx.update("update ordem_servico set agendada_para = ?, tecnico_id = coalesce(?, tecnico_id) where id = ?", agendadaPara, tecnicoId, id);
    }

    public void iniciar(Tx tx, long id, Instant agora) {
        tx.update("update ordem_servico set status = 'EM_EXECUCAO', iniciada_em = ? where id = ?", agora, id);
    }

    public void concluir(Tx tx, long id, Instant agora, long valorCents, String observacoes) {
        tx.update("update ordem_servico set status = 'CONCLUIDA', concluida_em = ?, valor_cents = ?, observacoes = ? where id = ?", agora, valorCents, observacoes, id);
    }

    public void cancelar(Tx tx, long id, String motivo) {
        tx.update("update ordem_servico set status = 'CANCELADA', observacoes = ? where id = ?", motivo, id);
    }

    public List<StatusOrdem> statusDoChamado(Tx tx, long chamadoId) {
        return tx.list("select status from ordem_servico where chamado_id = ?", rs -> StatusOrdem.valueOf(rs.getString(1)), chamadoId);
    }

    // ---- lançamento

    /** Ordens concluídas com valor e ainda sem lançamento, por id. */
    public List<Ordem> semLancamento(Tx tx, int limite) {
        return tx.list("select " + COLUNAS + " from ordem_servico o where o.status = 'CONCLUIDA' and o.valor_cents > 0 "
                + "and o.id > coalesce((select ultimo_id from job_checkpoint where nome = 'lancamento'), 0) order by o.id limit ?", OrdemRepo::ordem, limite);
    }

    public int lancar(Tx tx, Ordem o, String competencia, Instant agora) {
        return tx.update("insert into lancamento (empresa_id, ordem_servico_id, valor_cents, competencia, criado_em) values (?, ?, ?, ?, ?) "
                , o.empresaId(), o.id(), o.valorCents(), competencia, agora);
    }

    public void gravarPontoDeRetomada(Tx tx, long ultimoId) {
        tx.update("insert into job_checkpoint (nome, ultimo_id) values ('lancamento', ?) "
                + "on conflict (nome) do update set ultimo_id = greatest(job_checkpoint.ultimo_id, excluded.ultimo_id)", ultimoId);
    }

    /** Todos os técnicos ativos da empresa, com as ordens concluídas no período. */
    public List<Produtividade> produtividade(Tx tx, long empresaId, Instant de, Instant ate) {
        return tx.list("select t.id, t.nome, count(o.id) as concluidas, coalesce(sum(o.valor_cents), 0) as soma, "
                + "avg(extract(epoch from (o.concluida_em - o.iniciada_em)) / 3600.0) as media "
                + "from tecnico t left join ordem_servico o on o.tecnico_id = t.id and o.status = 'CONCLUIDA' and o.concluida_em >= ? and o.concluida_em < ? "
                + "where t.empresa_id = ? and t.ativo group by t.id, t.nome order by concluidas desc, t.nome",
                rs -> new Produtividade(rs.getLong("id"), rs.getString("nome"), rs.getLong("concluidas"), rs.getLong("soma"), rs.getBigDecimal("media")),
                de, ate, empresaId);
    }

    public List<long[]> tecnicosAtivos(Tx tx, long empresaId) {
        return tx.list("select id from tecnico where empresa_id = ? and ativo order by id", rs -> new long[] {rs.getLong(1)}, empresaId);
    }

    public Produtividade produtividadeDo(Tx tx, long tecnicoId, Instant de, Instant ate) {
        return tx.one("select t.id, t.nome, count(o.id) as concluidas, coalesce(sum(o.valor_cents), 0) as soma, "
                + "avg(extract(epoch from (o.concluida_em - o.iniciada_em)) / 3600.0) as media "
                + "from tecnico t left join ordem_servico o on o.tecnico_id = t.id and o.status = 'CONCLUIDA' and o.concluida_em >= ? and o.concluida_em < ? "
                + "where t.id = ? group by t.id, t.nome",
                rs -> new Produtividade(rs.getLong("id"), rs.getString("nome"), rs.getLong("concluidas"), rs.getLong("soma"), rs.getBigDecimal("media")),
                de, ate, tecnicoId).orElseThrow();
    }
}
