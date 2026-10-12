package br.netx.chamados;

import javax.sql.DataSource;
import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.function.Predicate;

/**
 * Segura as duas primeiras conexões que chegam a um comando e as solta na ordem que o teste escolhe. Quem espera espera por estado (a outra chegou,
 * ou está provadamente bloqueada numa trava do servidor), nunca por tempo; o teto de segurança produz resultado inconclusivo, nunca aprovação.
 */
final class Portao {
    private final Instrumento instrumento;
    private final DataSource monitor;
    private final Predicate<String> gatilho;
    private final long tetoMs;
    private final CountDownLatch primeira = new CountDownLatch(1);
    private final CountDownLatch segunda = new CountDownLatch(1);
    private final AtomicInteger chegadas = new AtomicInteger();
    volatile boolean estourou;

    Portao(Instrumento instrumento, DataSource monitor, Predicate<String> gatilho, long tetoMs) {
        this.instrumento = instrumento;
        this.monitor = monitor;
        this.gatilho = gatilho;
        this.tetoMs = tetoMs;
    }

    /** Para usar como Instrumento.Gancho: a primeira conexão a chegar espera a primeira liberação; as demais, a segunda. */
    void aoExecutar(Instrumento.Conexao c, String sql, List<Object> args) {
        if (c.chegou || !gatilho.test(sql.toLowerCase())) return;
        c.chegou = true;
        CountDownLatch minha = chegadas.incrementAndGet() == 1 ? primeira : segunda;
        try {
            if (!minha.await(tetoMs, TimeUnit.MILLISECONDS)) estourou = true;
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    /** Espera até que n conexões tenham chegado ou, para n > 1, até que alguma conexão aberta que não chegou esteja bloqueada numa trava. */
    boolean esperar(int n) throws InterruptedException {
        long limite = System.nanoTime() + tetoMs * 1_000_000L;
        while (System.nanoTime() < limite) {
            if (chegadas.get() >= n) return true;
            if (n > 1 && Encontro.alguemBloqueado(instrumento, monitor)) return true;
            Thread.sleep(2);   // polling: não decide nada, só espera o estado mudar
        }
        estourou = true;
        return false;
    }

    void soltarPrimeira() {
        primeira.countDown();
    }

    void soltarSegunda() {
        segunda.countDown();
    }
}
