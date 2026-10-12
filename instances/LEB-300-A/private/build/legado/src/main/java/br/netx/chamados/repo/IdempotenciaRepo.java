package br.netx.chamados.repo;

import br.netx.chamados.db.Db.Tx;

import java.sql.SQLException;
import java.time.Instant;
import java.util.Optional;

/** Chaves de idempotência: uma por empresa, rota e chave, válidas por 24 horas. */
public final class IdempotenciaRepo {

    public record Chave(long id, String corpoHash, Integer status, String resposta) {}

    public void expirar(Tx tx, long empresaId, String rota, String chave, Instant limite) {
        tx.update("delete from chave_idempotencia where empresa_id = ? and rota = ? and chave = ? and criado_em < ?", empresaId, rota, chave, limite);
    }

    /** Tenta reservar a chave. Devolve false se ela já existe (outro pedido a reservou). */
    public boolean reservar(Tx tx, long empresaId, String rota, String chave, String corpoHash, Instant agora) {
        tx.list("select pg_advisory_xact_lock(hashtext(?))", rs -> 0, empresaId + "|" + rota + "|" + chave);
        if (buscar(tx, empresaId, rota, chave).isPresent()) return false;
        return tx.update("insert into chave_idempotencia (empresa_id, rota, chave, corpo_hash, criado_em) values (?, ?, ?, ?, ?)",
                empresaId, rota, chave, corpoHash, agora) == 1;
    }

    public Optional<Chave> buscar(Tx tx, long empresaId, String rota, String chave) {
        return tx.one("select id, corpo_hash, status, resposta from chave_idempotencia where empresa_id = ? and rota = ? and chave = ?",
                rs -> new Chave(rs.getLong("id"), rs.getString("corpo_hash"), (Integer) rs.getObject("status"), rs.getString("resposta")),
                empresaId, rota, chave);
    }

    public void concluir(Tx tx, long empresaId, String rota, String chave, int status, String resposta) {
        tx.update("update chave_idempotencia set status = ?, resposta = ? where empresa_id = ? and rota = ? and chave = ?",
                status, resposta, empresaId, rota, chave);
    }
}
