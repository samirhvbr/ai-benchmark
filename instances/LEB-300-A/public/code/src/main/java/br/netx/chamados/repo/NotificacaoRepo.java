package br.netx.chamados.repo;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.servico.ChamadoService;
import br.netx.chamados.dominio.Dominio.Pagina;

import java.sql.SQLException;
import java.time.Instant;
import java.util.Arrays;
import java.util.List;
import java.util.Optional;

/** Notificações pendentes e os destinatários de cada evento. */
public final class NotificacaoRepo {

    public record Pendente(long id, long empresaId, String chaveEvento, String destinatario, String assunto, String corpo, int tentativas) {}

    public void inserir(Tx tx, long empresaId, String tipo, long chamadoId, long eventoId, String destinatario, String assunto, String corpo, Instant agora) {
        tx.update("insert into notificacao (empresa_id, chave_evento, destinatario, assunto, corpo, estado, tentativas, proxima_tentativa_em) "
                + "values (?, ?, ?, ?, ?, 'PENDENTE', 0, ?)",
                empresaId, ChamadoService.chave(tipo, chamadoId, eventoId), destinatario, assunto, corpo, agora);
    }

    /** Pega as pendentes que já podem sair. */
    public List<Pendente> reivindicar(Tx tx, Instant agora, int limite) {
        return tx.list("select id, empresa_id, chave_evento, destinatario, assunto, corpo, tentativas from notificacao "
                + "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ?",
                rs -> new Pendente(rs.getLong("id"), rs.getLong("empresa_id"), rs.getString("chave_evento"), rs.getString("destinatario"),
                        rs.getString("assunto"), rs.getString("corpo"), rs.getInt("tentativas")), agora, limite);
    }

    public record Registro(long id, String chaveEvento, String destinatario, String assunto, String estado, int tentativas, Instant proximaTentativaEm, Instant enviadaEm) {}

    public Pagina<Registro> listar(Tx tx, long empresaId, String estado, int pagina, int tamanho) {
        String filtro = estado == null ? "" : " and estado = ?";
        Object[] base = estado == null ? new Object[] {empresaId} : new Object[] {empresaId, estado};
        long total = tx.count("select count(*) from notificacao where empresa_id = ?" + filtro, base);
        Object[] args = Arrays.copyOf(base, base.length + 2);
        args[base.length] = tamanho;
        args[base.length + 1] = (pagina - 1) * tamanho;
        List<Registro> itens = tx.list("select id, chave_evento, destinatario, assunto, estado, tentativas, proxima_tentativa_em, enviada_em from notificacao "
                + "where empresa_id = ?" + filtro + " order by id desc limit ? offset ?",
                rs -> new Registro(rs.getLong("id"), rs.getString("chave_evento"), rs.getString("destinatario"), rs.getString("assunto"), rs.getString("estado"),
                        rs.getInt("tentativas"), Db.instante(rs, "proxima_tentativa_em"), Db.instante(rs, "enviada_em")), args);
        return new Pagina<>(itens, pagina, tamanho, total);
    }

    public void marcarEnviada(Tx tx, long id, Instant agora) {
        tx.update("update notificacao set estado = 'ENVIADA', enviada_em = ? where id = ?", agora, id);
    }

    public void registrarFalha(Tx tx, long id, int tentativas, Instant proxima, boolean definitiva) {
        tx.update("update notificacao set estado = ?, tentativas = ?, proxima_tentativa_em = ? where id = ?",
                definitiva ? "FALHA" : "PENDENTE", tentativas, proxima, id);
    }

    public List<String> emailsAtendentesEAdmins(Tx tx, long empresaId) {
        return tx.list("select email from usuario where empresa_id = ? and ativo and papel in ('ATENDENTE', 'ADMIN') order by email",
                rs -> rs.getString(1), empresaId);
    }

    public Optional<String> emailDoTecnico(Tx tx, long tecnicoId) {
        return tx.one("select u.email from tecnico t join usuario u on u.id = t.usuario_id where t.id = ? and u.ativo", rs -> rs.getString(1), tecnicoId);
    }

    public Optional<String> emailDoUsuario(Tx tx, long usuarioId) {
        return tx.one("select email from usuario where id = ? and ativo", rs -> rs.getString(1), usuarioId);
    }
}
