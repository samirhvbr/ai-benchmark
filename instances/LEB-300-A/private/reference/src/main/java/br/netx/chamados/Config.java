package br.netx.chamados;

import java.nio.file.Path;
import java.time.Duration;

/** Parâmetros de execução. Os padrões estão no manifesto, seção 6. */
public record Config(int porta, boolean agendadorAtivo, Duration intervaloJobs, Path notificacaoDir, Duration tokenValidade) {

    public static Config padrao(Path notificacaoDir) {
        return new Config(8080, true, Duration.ofSeconds(60), notificacaoDir, Duration.ofHours(8));
    }
}
