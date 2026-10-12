package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;

/** Desempenho: o que se mede é quantidade de trabalho (comandos, linhas lidas, memória, crescimento), nunca um tempo absoluto. */
class V03_DesempenhoTest extends VBase {

    @Test
    @Verifica({"PERF-001"})
    void perf001_aListagemDeChamadosNaoFazUmaConsultaPorLinha() throws Exception {
        chamadosEmMassa(120, 10, true);
        String t = token(ADMIN_A);
        get("/chamados?tamanho=10", t);                                   // aquece caches da aplicação, se houver
        long pequena = comandosDe("/chamados?tamanho=10", t);
        long grande = comandosDe("/chamados?tamanho=100", t);
        Resultado.c("PERF-001", grande - pequena <= 1, "comandos por requisição: " + pequena + " com 10 linhas e " + grande + " com 100 (devem ser iguais)");
    }

    @Test
    @Verifica({"PERF-003"})
    void perf003_aPaginaDeOrdensNaoLeATabelaInteira() throws Exception {
        long chamado = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "ABERTO", java.time.Instant.parse("2026-03-04T12:00:00Z"),
                java.time.Instant.parse("2026-03-09T12:00:00Z"), null);
        ordensAgendadas(chamado, 200);
        String t = token(ADMIN_A);
        get("/ordens?tamanho=10", t);
        instr.zerar();
        get("/ordens?tamanho=10", t);
        long pequena = instr.linhas.get();
        ordensAgendadas(chamado, 1800);
        instr.zerar();
        get("/ordens?tamanho=10", t);
        long grande = instr.linhas.get();
        Resultado.c("PERF-003", grande - pequena <= 2, "linhas lidas para uma página de 10: " + pequena + " com 200 ordens e " + grande + " com 2.000 (devem ser iguais)");
    }

    @Test
    @Verifica({"PERF-005"})
    void perf005_oRelatorioDeTecnicosNaoFazUmaConsultaPorTecnico() throws Exception {
        for (int i = 0; i < 4; i++) inserir("insert into tecnico (empresa_id, nome) values (?, ?) returning id", empA, "Tecnico extra " + i);
        String t = token(ADMIN_A);
        get("/relatorios/tecnicos?mes=2026-03", t);
        long poucos = comandosDe("/relatorios/tecnicos?mes=2026-03", t);
        atualizar("insert into tecnico (empresa_id, nome) select ?, 'Tecnico em massa ' || g from generate_series(1, 45) g", empA);
        long muitos = comandosDe("/relatorios/tecnicos?mes=2026-03", t);
        Resultado.c("PERF-005", muitos - poucos <= 1, "comandos por requisição: " + poucos + " com 5 técnicos e " + muitos + " com 50 (devem ser iguais)");
    }

    @Test
    @Verifica({"PERF-006"})
    void perf006_asColunasFiltradasTemIndice() throws Exception {
        boolean chamadoPorEmpresa = lidera("chamado", "empresa_id");
        boolean ordemPorChamado = lidera("ordem_servico", "chamado_id");
        Resultado.c("PERF-006", chamadoPorEmpresa && ordemPorChamado,
                "índice liderado por chamado(empresa_id)=" + chamadoPorEmpresa + "; por ordem_servico(chamado_id)=" + ordemPorChamado);
    }

    /** Existe um índice (não a chave primária) cuja primeira coluna é a pedida? */
    private boolean lidera(String tabela, String coluna) throws Exception {
        return contar("select count(*) from pg_index i join pg_class t on t.oid = i.indrelid join pg_namespace n on n.oid = t.relnamespace "
                + "join pg_attribute a on a.attrelid = t.oid and a.attnum = i.indkey[0] "
                + "where n.nspname = ? and t.relname = ? and a.attname = ? and not i.indisprimary", schema, tabela, coluna) > 0;
    }

    @Test
    @Verifica({"PERF-011"})
    void perf011_oEscalonamentoNaoCarregaTodosOsChamadosNaMemoria() throws Exception {
        // Controle: o mesmo código, no mesmo heap, sobre 100 chamados pequenos. Se ele não conclui, nada que acontecer depois pode ser atribuído ao tamanho.
        chamadosEmMassa(100, 100);
        Filho controle = filho("48m");
        ClassificaFilho.Resultado c = ClassificaFilho.classificar(controle.terminou, controle.codigo, controle.saida);
        if (c.origem() != ClassificaFilho.Origem.CONCLUIU) {
            Resultado.inconclusivo("PERF-011", "o controle com 100 chamados pequenos não concluiu (" + c.origem() + "): " + c.detalhe());
            return;
        }
        atualizar("truncate chamado, evento_chamado, notificacao cascade");
        chamadosEmMassa(20_000, 5_000);
        Filho f = filho("48m");
        ClassificaFilho.Resultado r = ClassificaFilho.classificar(f.terminou, f.codigo, f.saida);
        switch (r.origem()) {
            case CONCLUIU -> Resultado.c("PERF-011", true, "passada sobre 20.000 chamados de 5 KB concluída com -Xmx48m");
            case MEMORIA_DO_CODIGO_AVALIADO -> Resultado.c("PERF-011", false, "a passada sobre 20.000 chamados de 5 KB estourou a memória com -Xmx48m: " + r.detalhe());
            case AMBIENTE_OU_AVALIADOR -> Resultado.inconclusivo("PERF-011", "falha do ambiente ou do avaliador, não atribuída ao código avaliado: " + r.detalhe());
            default -> Resultado.inconclusivo("PERF-011", "o processo-filho falhou sobre os 20.000 chamados e a origem não está estabelecida (o controle pequeno concluiu): " + r.detalhe());
        }
    }

    private record Filho(boolean terminou, int codigo, String saida) {}

    private Filho filho(String heap) throws Exception {
        String jvm = ProcessHandle.current().info().command().orElse("java");
        Process p = new ProcessBuilder(jvm, "-Xmx" + heap, "-cp", System.getProperty("java.class.path"), "br.netx.chamados.V03Filho", schema)
                .redirectErrorStream(true).start();
        StringBuilder out = new StringBuilder();
        Thread leitor = new Thread(() -> {
            try (BufferedReader r = new BufferedReader(new InputStreamReader(p.getInputStream(), StandardCharsets.UTF_8))) {
                String linha;
                while ((linha = r.readLine()) != null) out.append(linha).append('\n');
            } catch (java.io.IOException ignorado) {
                // o processo foi encerrado
            }
        });
        leitor.start();
        boolean terminou = p.waitFor(15, java.util.concurrent.TimeUnit.MINUTES);
        if (!terminou) p.destroyForcibly().waitFor();
        leitor.join(5_000);
        return new Filho(terminou, terminou ? p.exitValue() : -1, out.toString().strip());
    }

    @Test
    @Verifica({"PERF-012"})
    void perf012_oDespachoCresceEmLinhaReta() throws Exception {
        entregasJaGravadasNoArquivo(500);
        app.jobs().executarUmaVez();                                       // aquece a JVM
        entregasJaGravadasNoArquivo(4_000);
        long a = cronometrar();
        entregasJaGravadasNoArquivo(16_000);
        long b = cronometrar();
        double razao = (double) b / a;
        Resultado.c("PERF-012", razao <= LIMITE_RAZAO_DESPACHO, String.format("passada com 4.000 notificações: %d ms de processador; com 16.000: %d ms; razão %.1f (linear seria 4)", a, b, razao));
    }

    static final double LIMITE_RAZAO_DESPACHO = 6.0;

    /**
     * Refaz o cenário de uma queda entre gravar a linha e confirmar a transação: n notificações pendentes cujas linhas já estão no arquivo da empresa.
     * Cada uma só precisa ser reconhecida como já entregue e marcada; o que cresce com n é o custo de reconhecer.
     */
    private void entregasJaGravadasNoArquivo(int n) throws Exception {
        atualizar("delete from notificacao");
        String prefixo = "evento-" + "x".repeat(3000) + "-";   // prefixo comum longo: comparar duas chaves custa o comprimento todo
        atualizar("insert into notificacao (empresa_id, chave_evento, destinatario, assunto, corpo, estado, tentativas, proxima_tentativa_em) "
                + "select ?, ? || g, 'dest' || g || '@a.test', 'assunto', 'corpo', 'PENDENTE', 0, ?::timestamptz from generate_series(1, ?) g",
                empA, prefixo, "2026-03-04T12:00:00Z", n);
        StringBuilder linhas = new StringBuilder();
        for (int g = 1; g <= n; g++) {
            linhas.append("{\"chave\":\"").append(prefixo).append(g).append("\",\"destinatario\":\"dest").append(g).append("@a.test\",\"assunto\":\"assunto\",\"corpo\":\"corpo\",\"enviada_em\":\"2026-03-04T12:00:00Z\"}\n");
        }
        java.nio.file.Files.writeString(notificacoes.resolve(empA + ".jsonl"), linhas.toString());
    }

    /** Tempo de processador da thread que executa a passada: o que o servidor de banco gasta (varreduras, travas) não entra. */
    private long cronometrar() throws Exception {
        java.lang.management.ThreadMXBean mx = java.lang.management.ManagementFactory.getThreadMXBean();
        long cpu = mx.getCurrentThreadCpuTime();
        long parede = System.nanoTime();
        app.jobs().executarUmaVez();
        System.out.println("[perf012] parede " + (System.nanoTime() - parede) / 1_000_000L + " ms, cpu " + (mx.getCurrentThreadCpuTime() - cpu) / 1_000_000L + " ms");
        return (mx.getCurrentThreadCpuTime() - cpu) / 1_000_000L;
    }

    @Test
    @Verifica({"PERF-013"})
    void perf013_umaRequisicaoNaoRepeteAMesmaLeitura() throws Exception {
        String atendente = token(ATENDENTE_A);
        long chamado = abrirChamado(atendente, cliA, "visita", "NORMAL");
        patch("/chamados/" + chamado, atendente, Map.of("tecnico_id", tecA));
        long ordem = criarOrdem(atendente, chamado, tecA);
        String tecnico = token(TECNICO_A);
        post("/ordens/" + ordem + "/iniciar", tecnico, null);
        instr.zerar();
        R r = post("/ordens/" + ordem + "/concluir", tecnico, Map.of("valor_cents", 15000));
        if (r.status() != 200) { Resultado.inconclusivo("PERF-013", "concluir respondeu " + r.status() + ": " + r.bruto()); return; }
        // a mesma leitura é a mesma tabela com os mesmos argumentos, ainda que o texto mude (com ou sem "for update", com outra lista de colunas),
        // e só repete se nada foi escrito nessa tabela entre as duas: reler depois de gravar é necessário
        List<String> repetidas = instr.leiturasRepetidas();
        Resultado.c("PERF-013", repetidas.isEmpty(), repetidas.isEmpty() ? "nenhuma leitura repetida ao concluir a ordem" : "leituras repetidas ao concluir a ordem: " + repetidas);
    }
}
