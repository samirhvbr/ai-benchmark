package br.netx.chamados;

import br.netx.chamados.job.DespachoJob;
import br.netx.chamados.job.EscalonamentoJob;
import br.netx.chamados.job.LancamentoJob;

/** Executa os processamentos em segundo plano: escalonamento por SLA, despacho de notificações e lançamento de ordens. */
public final class JobRunner {
    private final EscalonamentoJob escalonamento;
    private final DespachoJob despacho;
    private final LancamentoJob lancamento;

    JobRunner(EscalonamentoJob escalonamento, DespachoJob despacho, LancamentoJob lancamento) {
        this.escalonamento = escalonamento;
        this.despacho = despacho;
        this.lancamento = lancamento;
    }

    /** Uma passada, nesta ordem. */
    public void executarUmaVez() {
        escalonamento.executar();
        despacho.executar();
        lancamento.executar();
    }
}
