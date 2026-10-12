package br.netx.chamados.job;

import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Empresa;
import br.netx.chamados.dominio.Dominio.Ordem;
import br.netx.chamados.repo.CadastroRepo;
import br.netx.chamados.repo.OrdemRepo;

import java.time.Clock;
import java.time.Instant;
import java.time.YearMonth;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/** Lança, uma vez cada, as ordens concluídas com valor. O trabalho e o ponto de retomada vão na mesma transação. */
public final class LancamentoJob {
    private static final int LOTE = 100;
    /** Uma só passada por vez no banco: a regra é exatamente um lançamento por ordem, mesmo com dois executores. */
    private static final long TRAVA = 3_000_001L;

    private final Db db;
    private final OrdemRepo ordens;
    private final CadastroRepo cadastros;
    private final Clock clock;

    public LancamentoJob(Db db, OrdemRepo ordens, CadastroRepo cadastros, Clock clock) {
        this.db = db;
        this.ordens = ordens;
        this.cadastros = cadastros;
        this.clock = clock;
    }

    public int executar() {
        Instant agora = clock.instant();
        int lancados = 0;
        int doLote;
        do {
            doLote = db.tx(tx -> lancarLote(tx, agora));
            lancados += doLote;
        } while (doLote > 0);
        return lancados;
    }

    private int lancarLote(Tx tx, Instant agora) {
        tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);
        List<Ordem> lote = ordens.semLancamento(tx, LOTE);
        Map<Long, Empresa> empresas = new HashMap<>();
        int lancados = 0;
        for (Ordem o : lote) {
            Empresa e = empresas.computeIfAbsent(o.empresaId(), id -> cadastros.empresa(tx, id).orElseThrow());
            String competencia = YearMonth.from(o.concluidaEm().atZone(e.zona())).toString();
            lancados += ordens.lancar(tx, o, competencia, agora);
        }
        if (!lote.isEmpty()) ordens.gravarPontoDeRetomada(tx, lote.get(lote.size() - 1).id());
        return lote.size();
    }
}
