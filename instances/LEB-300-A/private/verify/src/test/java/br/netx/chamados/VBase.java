package br.netx.chamados;

import javax.sql.DataSource;
import java.util.Map;

/** Base dos verificadores que precisam observar ou interferir nas conexões da aplicação. */
abstract class VBase extends TestBase {
    Instrumento instr;

    @Override
    DataSource paraAApp(DataSource base) {
        instr = new Instrumento(base);
        return instr.dataSource();
    }

    /** Recomeça com um esquema novo, um App novo e o cenário padrão. */
    void reiniciar() throws Exception {
        encerrar();
        preparar();
    }

    /** Insere n ordens concluídas com valor num chamado só, direto no banco: o único trabalho que a passada terá. */
    long ordensConcluidas(int n) throws Exception {
        long chamado = chamadoNoBanco(empA, cliA, atdA, "NORMAL", "RESOLVIDO", java.time.Instant.parse("2026-03-04T12:00:00Z"),
                java.time.Instant.parse("2026-03-12T12:00:00Z"), java.time.Instant.parse("2026-03-04T13:00:00Z"));
        atualizar("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para, iniciada_em, concluida_em, valor_cents) "
                + "select ?, ?, ?, 'V', 'CONCLUIDA', now(), now(), now(), 100 + g from generate_series(1, ?) g", empA, chamado, tecA, n);
        return chamado;
    }

    /** Insere n chamados abertos da empresa A direto no banco, metade com técnico, com descrição do tamanho pedido. */
    void chamadosEmMassa(int n, int tamanhoDescricao) throws Exception {
        chamadosEmMassa(n, tamanhoDescricao, false);
    }

    /** Com {@code distintos}, cada chamado tem o seu cliente e o seu técnico: nenhum cache por requisição disfarça uma consulta por linha. */
    void chamadosEmMassa(int n, int tamanhoDescricao, boolean distintos) throws Exception {
        if (distintos) {
            atualizar("insert into cliente (empresa_id, nome) select ?, 'Massa ' || g from generate_series(1, ?) g", empA, n);
            atualizar("insert into tecnico (empresa_id, nome) select ?, 'Massa ' || g from generate_series(1, ?) g", empA, n);
            atualizar("with cl as (select id, row_number() over (order by id) rn from cliente where empresa_id = ? and nome like 'Massa %'), "
                    + "tc as (select id, row_number() over (order by id) rn from tecnico where empresa_id = ? and nome like 'Massa %') "
                    + "insert into chamado (empresa_id, cliente_id, tecnico_id, titulo, descricao, prioridade, status, aberto_em, prazo_resposta, prazo_resolucao, criado_por) "
                    + "select ?, cl.id, case when g % 2 = 0 then tc.id end, 'Chamado ' || g, repeat('x', ?), 'NORMAL', 'ABERTO', ?::timestamptz, ?::timestamptz, ?::timestamptz, ? "
                    + "from generate_series(1, ?) g join cl on cl.rn = g join tc on tc.rn = g",
                    empA, empA, empA, tamanhoDescricao, "2026-03-04T12:00:00Z", "2026-03-04T14:00:00Z", "2026-03-09T12:00:00Z", atdA, n);
            return;
        }
        atualizar("insert into chamado (empresa_id, cliente_id, tecnico_id, titulo, descricao, prioridade, status, aberto_em, prazo_resposta, prazo_resolucao, criado_por) "
                + "select ?, ?, case when g % 2 = 0 then ? end, 'Chamado ' || g, repeat('x', ?), 'NORMAL', 'ABERTO', ?::timestamptz, ?::timestamptz, ?::timestamptz, ? "
                + "from generate_series(1, ?) g",
                empA, cliA, tecA, tamanhoDescricao, "2026-03-04T12:00:00Z", "2026-03-04T14:00:00Z", "2026-03-09T12:00:00Z", atdA, n);
    }

    /** Insere n ordens de serviço agendadas de um chamado, direto no banco. */
    void ordensAgendadas(long chamado, int n) throws Exception {
        atualizar("insert into ordem_servico (empresa_id, chamado_id, tecnico_id, tipo, status, agendada_para) "
                + "select ?, ?, ?, 'VISITA', 'AGENDADA', now() + g * interval '1 minute' from generate_series(1, ?) g", empA, chamado, tecA, n);
    }

    /** Insere n notificações pendentes da empresa A, todas devidas. */
    void notificacoesPendentes(int n) throws Exception {
        atualizar("insert into notificacao (empresa_id, chave_evento, destinatario, assunto, corpo, estado, tentativas, proxima_tentativa_em) "
                + "select ?, 'chave-' || g, 'dest' || g || '@a.test', 'assunto', 'corpo', 'PENDENTE', 0, ?::timestamptz from generate_series(1, ?) g",
                empA, "2026-03-04T12:00:00Z", n);
    }

    /** Comandos que a aplicação enviou ao banco para atender uma chamada. */
    long comandosDe(String caminho, String token) throws Exception {
        instr.zerar();
        R r = get(caminho, token);
        if (r.status() != 200) throw new IllegalStateException(caminho + " respondeu " + r.status() + ": " + r.bruto());
        return instr.comandos.get();
    }

    // ---- instâncias em processos (JVMs) independentes sobre o mesmo banco

    /** Resultado de um cenário entre processos: aprovado, reprovado, ou sem conclusão (com o motivo). */
    record EntreProcessos(Boolean ok, String texto) {
        static EntreProcessos inconclusivo(String motivo) {
            return new EntreProcessos(null, motivo);
        }
    }

    private boolean filhoFalhou(Processos pr, int i) {
        return pr.saida(i).contains("FILHO-FALHOU");
    }

    /**
     * Duas instâncias da aplicação, em duas JVMs, rodam uma passada dos processamentos ao mesmo tempo. Cada uma para no primeiro comando que casa com o gatilho
     * até a outra chegar também (ou estar provadamente esperando uma trava do servidor). Uma trava que só existe dentro de uma JVM não faz a segunda esperar.
     */
    EntreProcessos duasInstanciasRodamOsProcessamentos(String gatilho) throws Exception {
        try (Processos pr = new Processos(ds, schema, notificacoes)) {
            pr.rodada(0);
            Process a = pr.iniciar("jobs", "A", gatilho);
            if (!pr.esperarChegada("A-0", () -> !a.isAlive(), false)) return EntreProcessos.inconclusivo("a primeira instância não chegou ao gatilho dentro do teto");
            Process b = pr.iniciar("jobs", "B", gatilho);
            if (!pr.esperarChegada("B-0", () -> !b.isAlive(), true)) return EntreProcessos.inconclusivo("a segunda instância não chegou ao gatilho nem esperou uma trava dentro do teto");
            pr.soltar("A-0");
            pr.soltar("B-0");
            if (!a.waitFor(Processos.TETO_MS, java.util.concurrent.TimeUnit.MILLISECONDS) || !b.waitFor(Processos.TETO_MS, java.util.concurrent.TimeUnit.MILLISECONDS) || pr.estourou()) {
                return EntreProcessos.inconclusivo("uma instância não terminou dentro do teto");
            }
            if (filhoFalhou(pr, 0) || filhoFalhou(pr, 1)) return EntreProcessos.inconclusivo("uma instância falhou ao rodar: " + pr.saida(0) + " | " + pr.saida(1));
            return new EntreProcessos(true, "saídas: " + pr.saida(0) + " | " + pr.saida(1));
        }
    }

    /**
     * Duas instâncias com servidor HTTP, em duas JVMs, recebem uma alteração parcial cada, de campos diferentes do mesmo chamado. Cada uma lê a linha e para
     * antes de gravar; uma grava e termina, e só então a outra grava. Nas duas ordens.
     */
    EntreProcessos duasInstanciasAlteramCamposDiferentes() throws Exception {
        String t = token(ATENDENTE_A);
        try (Processos pr = new Processos(ds, schema, notificacoes)) {
            pr.rodada(0);
            pr.iniciar("http", "A", "update chamado");
            pr.iniciar("http", "B", "update chamado");
            int portaA = pr.porta("A"), portaB = pr.porta("B");
            if (portaA < 0 || portaB < 0) return EntreProcessos.inconclusivo("uma instância não subiu o servidor: " + pr.saida(0) + " | " + pr.saida(1));
            java.util.concurrent.ExecutorService pool = java.util.concurrent.Executors.newFixedThreadPool(2);
            try {
                for (int ordem = 0; ordem < 2; ordem++) {
                    pr.rodada(ordem);
                    long id = abrirChamado(t, cliA, "original", "NORMAL");
                    Map<String, Object> primeiro = ordem == 0 ? Map.of("titulo", "novo titulo") : Map.of("prioridade", "ALTA");
                    Map<String, Object> segundo = ordem == 0 ? Map.of("prioridade", "ALTA") : Map.of("titulo", "novo titulo");
                    java.util.concurrent.Future<Integer> x = pool.submit(() -> patchEm("http://localhost:" + portaA, id, t, primeiro));
                    if (!pr.esperarChegada("A-" + ordem, x::isDone, false)) return EntreProcessos.inconclusivo("a primeira alteração não chegou à gravação dentro do teto");
                    java.util.concurrent.Future<Integer> y = pool.submit(() -> patchEm("http://localhost:" + portaB, id, t, segundo));
                    if (!pr.esperarChegada("B-" + ordem, y::isDone, true)) return EntreProcessos.inconclusivo("a segunda alteração não chegou à gravação nem esperou uma trava dentro do teto");
                    pr.soltar("A-" + ordem);
                    int sa = x.get(Processos.TETO_MS, java.util.concurrent.TimeUnit.MILLISECONDS);
                    pr.soltar("B-" + ordem);
                    int sb = y.get(Processos.TETO_MS, java.util.concurrent.TimeUnit.MILLISECONDS);
                    if (pr.estourou()) return EntreProcessos.inconclusivo("uma instância estourou o teto de espera");
                    Map<String, Object> linha = consultar("select titulo, prioridade from chamado where id = ?", id).get(0);
                    boolean ok = sa == 200 && sb == 200 && "novo titulo".equals(linha.get("titulo")) && "ALTA".equals(linha.get("prioridade"));
                    if (!ok) return new EntreProcessos(false, "em duas JVMs, com " + primeiro.keySet() + " gravando antes de " + segundo.keySet() + ": título=" + linha.get("titulo")
                            + ", prioridade=" + linha.get("prioridade") + " (esperado novo titulo e ALTA)");
                }
            } finally {
                pool.shutdownNow();
            }
            return new EntreProcessos(true, "");
        }
    }

    private int patchEm(String base, long id, String token, Map<String, Object> corpo) throws Exception {
        java.net.http.HttpRequest rq = java.net.http.HttpRequest.newBuilder(java.net.URI.create(base + "/chamados/" + id)).header("Authorization", "Bearer " + token)
                .header("Content-Type", "application/json").method("PATCH", java.net.http.HttpRequest.BodyPublishers.ofString(JSON.writeValueAsString(corpo))).build();
        return http.send(rq, java.net.http.HttpResponse.BodyHandlers.ofString()).statusCode();
    }
}
