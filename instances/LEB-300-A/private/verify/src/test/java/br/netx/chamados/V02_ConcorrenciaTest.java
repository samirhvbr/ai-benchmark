package br.netx.chamados;

import org.junit.jupiter.api.Test;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;

/** Concorrência e resiliência. O entrelaçamento é forçado por estado (Encontro), nunca por tempo, e a interrupção vale em cada comando. */
class V02_ConcorrenciaTest extends VBase {
    private static final long TETO_MS = 60_000;

    @Test
    @Verifica({"BUG-011.a"})
    void bug011a_duasAlteracoesEmCamposDiferentesValemAmbas() throws Exception {
        String t = token(ATENDENTE_A);
        // As duas alterações leem a mesma linha antes de qualquer uma gravar; depois uma grava e termina, e só então a outra grava.
        // Roda nas duas ordens, porque um conserto que protege só uma direção da perda passa se a ordem for a que ele protege.
        String falha = null;
        for (int ordem = 0; ordem < 2 && falha == null; ordem++) {
            long id = abrirChamado(t, cliA, "original", "NORMAL");
            Map<String, Object> primeiro = ordem == 0 ? Map.of("titulo", "novo titulo") : Map.of("prioridade", "ALTA");
            Map<String, Object> segundo = ordem == 0 ? Map.of("prioridade", "ALTA") : Map.of("titulo", "novo titulo");
            Portao portao = new Portao(instr, ds, sql -> sql.contains("update chamado"), TETO_MS);
            instr.gancho = portao::aoExecutar;
            ExecutorService pool = Executors.newFixedThreadPool(2);
            R ra, rb;
            try {
                Future<R> a = pool.submit(() -> patch("/chamados/" + id, t, primeiro));
                portao.esperar(1);
                Future<R> b = pool.submit(() -> patch("/chamados/" + id, t, segundo));
                portao.esperar(2);
                portao.soltarPrimeira();
                ra = a.get(2 * TETO_MS, TimeUnit.MILLISECONDS);
                portao.soltarSegunda();
                rb = b.get(2 * TETO_MS, TimeUnit.MILLISECONDS);
            } finally {
                instr.gancho = null;
                pool.shutdownNow();
            }
            if (portao.estourou) { Resultado.inconclusivo("BUG-011.a", "o portão estourou o teto"); return; }
            Map<String, Object> linha = consultar("select titulo, prioridade from chamado where id = ?", id).get(0);
            boolean ok = ra.status() == 200 && rb.status() == 200 && "novo titulo".equals(linha.get("titulo")) && "ALTA".equals(linha.get("prioridade"));
            if (!ok) falha = "com " + primeiro.keySet() + " gravando antes de " + segundo.keySet() + ": título=" + linha.get("titulo") + ", prioridade=" + linha.get("prioridade") + " (esperado novo titulo e ALTA)";
        }
        if (falha == null) {
            // O contrato admite várias instâncias, em processos independentes, sobre o mesmo banco: a mesma prova com duas JVMs.
            reiniciar();
            EntreProcessos ep = duasInstanciasAlteramCamposDiferentes();
            if (ep.ok() == null) { Resultado.inconclusivo("BUG-011.a", "o cenário entre duas JVMs não concluiu: " + ep.texto()); return; }
            if (!ep.ok()) falha = ep.texto();
        }
        Resultado.c("BUG-011.a", falha == null, falha == null ? "as duas alterações valem, nas duas ordens de gravação, numa JVM e entre duas JVMs" : falha);
    }

    @Test
    @Verifica({"BUG-011.b"})
    void bug011b_duasExecucoesNaoEntregamDuasVezes() throws Exception {
        String atendente = token(ATENDENTE_A);
        for (int i = 0; i < 3; i++) abrirChamado(atendente, cliA, "aviso " + i, "NORMAL");   // 3 chamados x 2 destinatários = 6 notificações
        long esperadas = contar("select count(*) from notificacao where estado = 'PENDENTE'");
        Encontro enc = new Encontro(instr, ds, 2, sql -> sql.contains("update notificacao"), TETO_MS);
        instr.gancho = enc::aoExecutar;
        ExecutorService pool = Executors.newFixedThreadPool(2);
        try {
            Future<?> a = pool.submit(() -> { try { app.jobs().executarUmaVez(); } finally { enc.saiu(); } });
            Future<?> b = pool.submit(() -> { try { app.jobs().executarUmaVez(); } finally { enc.saiu(); } });
            a.get(2 * TETO_MS, TimeUnit.MILLISECONDS);
            b.get(2 * TETO_MS, TimeUnit.MILLISECONDS);
            instr.gancho = null;
            if (enc.estourou) { Resultado.inconclusivo("BUG-011.b", "o encontro estourou o teto"); return; }
            List<String> linhas = Files.exists(notificacoes.resolve(empA + ".jsonl")) ? Files.readAllLines(notificacoes.resolve(empA + ".jsonl")) : List.of();
            Set<String> chaves = new HashSet<>();
            for (String l : linhas) chaves.add(JSON.readTree(l).get("chave").asText() + "|" + JSON.readTree(l).get("destinatario").asText());
            boolean ok = linhas.size() == esperadas && chaves.size() == esperadas;
            String msg = "duas execuções simultâneas numa JVM: " + linhas.size() + " linhas, " + chaves.size() + " distintas, " + esperadas + " notificações";
            if (ok) {
                // O contrato admite várias instâncias, em processos independentes, sobre o mesmo banco: a mesma prova com duas JVMs.
                reiniciar();
                String atendente2 = token(ATENDENTE_A);
                for (int i = 0; i < 3; i++) abrirChamado(atendente2, cliA, "aviso " + i, "NORMAL");
                long esperadas2 = contar("select count(*) from notificacao where estado = 'PENDENTE'");
                EntreProcessos ep = duasInstanciasRodamOsProcessamentos("update notificacao");
                if (ep.ok() == null) { Resultado.inconclusivo("BUG-011.b", "o cenário entre duas JVMs não concluiu: " + ep.texto()); return; }
                List<String> linhas2 = Files.exists(notificacoes.resolve(empA + ".jsonl")) ? Files.readAllLines(notificacoes.resolve(empA + ".jsonl")) : List.of();
                Set<String> chaves2 = new HashSet<>();
                for (String l : linhas2) chaves2.add(JSON.readTree(l).get("chave").asText() + "|" + JSON.readTree(l).get("destinatario").asText());
                ok = linhas2.size() == esperadas2 && chaves2.size() == esperadas2;
                msg += "; entre duas JVMs: " + linhas2.size() + " linhas, " + chaves2.size() + " distintas, " + esperadas2 + " notificações (" + ep.texto() + ")";
            }
            Resultado.c("BUG-011.b", ok, msg);
        } finally {
            pool.shutdownNow();
        }
    }

    @Test
    @Verifica({"BUG-014"})
    void bug014_umaInterrupcaoEmQualquerComandoNaoPulaNemDuplicaOrdens() throws Exception {
        final int ordens = 250;           // 3 lotes de 100
        ordensConcluidas(ordens);
        instr.zerar();
        app.jobs().executarUmaVez();
        long comandos = instr.comandos.get();
        if (contar("select count(*) from lancamento") != ordens) { Resultado.inconclusivo("BUG-014", "a passada sem interrupção não lançou as " + ordens + " ordens"); return; }
        String falha = null;
        for (long k = 1; k <= comandos && falha == null; k++) {
            reiniciar();
            ordensConcluidas(ordens);
            long ponto = k;
            long[] vistos = {0};
            instr.gancho = (c, sql, args) -> {
                if (++vistos[0] == ponto) throw new Error("interrompido antes do comando " + ponto + ": " + sql.strip());
            };
            try {
                app.jobs().executarUmaVez();
            } catch (Throwable esperado) {
                // a interrupção é o cenário
            }
            instr.gancho = null;
            app.jobs().executarUmaVez();                       // a retomada
            long total = contar("select count(*) from lancamento");
            long distintos = contar("select count(distinct ordem_servico_id) from lancamento");
            if (total != ordens || distintos != ordens) falha = "interrompido no comando " + k + " de " + comandos + ": " + total + " lançamentos, " + distintos + " ordens distintas (esperado " + ordens + ")";
        }
        Resultado.c("BUG-014", falha == null, falha == null ? "interrupção testada em cada um dos " + comandos + " comandos: nenhuma ordem pulada ou duplicada" : falha);
    }
}
