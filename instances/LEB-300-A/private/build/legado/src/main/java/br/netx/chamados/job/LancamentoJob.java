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
import java.time.ZoneId;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/** Lança, uma vez cada, as ordens concluídas com valor. */
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
            doLote = lancarLote(agora);
            lancados += doLote;
        } while (doLote > 0);
        return lancados;
    }

    private int lancarLote(Instant agora) {
        List<Ordem> lote = db.tx(tx -> {
            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);
            List<Ordem> proximas = ordens.semLancamento(tx, LOTE);
            if (!proximas.isEmpty()) ordens.gravarPontoDeRetomada(tx, proximas.get(proximas.size() - 1).id());
            return proximas;
        });
        return db.tx(tx -> lancar(tx, lote, agora));
    }

    private int lancar(Tx tx, List<Ordem> lote, Instant agora) {
        Map<Long, Empresa> empresas = new HashMap<>();
        int lancados = 0;
        for (Ordem o : lote) {
            Empresa e = empresas.computeIfAbsent(o.empresaId(), id -> cadastros.empresa(tx, id).orElseThrow());
            String competencia = YearMonth.from(o.concluidaEm().atZone(ZoneId.systemDefault())).toString();
            lancados += ordens.lancar(tx, o, competencia, agora);
        }
        return lote.size();
    }
}
