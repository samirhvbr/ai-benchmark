package br.netx.chamados.servico;

import br.netx.chamados.auth.Auth;
import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.dominio.Dominio.Erro;
import br.netx.chamados.dominio.Dominio.Principal;
import br.netx.chamados.repo.IdempotenciaRepo;
import br.netx.chamados.repo.IdempotenciaRepo.Chave;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.time.Clock;
import java.time.Duration;
import java.util.Optional;
import java.util.function.Function;

/** Repetir um pedido com a mesma chave devolve a resposta original, sem efeito novo. A chave e o efeito nascem na mesma transação. */
public final class Idempotencia {
    private static final Duration VALIDADE = Duration.ofHours(24);

    public record Resultado(int status, String corpo) {}

    private final Db db;
    private final IdempotenciaRepo repo;
    private final Clock clock;
    private final ObjectMapper json = new ObjectMapper();

    public Idempotencia(Db db, IdempotenciaRepo repo, Clock clock) {
        this.db = db;
        this.repo = repo;
        this.clock = clock;
    }

    public Resultado executar(Principal p, String rota, String chave, JsonNode corpo, int statusSucesso, Function<Tx, Object> operacao) {
        return db.tx(tx -> {
            if (chave == null) return resultado(statusSucesso, operacao.apply(tx));
            long empresa = p.empresaId();
            String impressao = impressao(corpo);
            repo.expirar(tx, empresa, rota, chave, clock.instant().minus(VALIDADE));
            if (!repo.reservar(tx, empresa, rota, chave, impressao, clock.instant())) {
                Optional<Chave> anterior = repo.buscar(tx, empresa, rota, chave);
                Chave c = anterior.orElseThrow(() -> Erro.conflito("chave de idempotência em uso"));
                if (!c.corpoHash().equals(impressao)) throw Erro.invalido("a chave de idempotência já foi usada com outro corpo");
                if (c.status() == null) throw Erro.conflito("pedido com esta chave ainda em andamento");
                return new Resultado(c.status(), c.resposta());
            }
            Resultado r = resultado(statusSucesso, operacao.apply(tx));
            repo.concluir(tx, empresa, rota, chave, r.status(), r.corpo());
            return r;
        });
    }

    private Resultado resultado(int status, Object corpo) {
        try {
            return new Resultado(status, json.writeValueAsString(corpo));
        } catch (JsonProcessingException e) {
            throw new IllegalStateException(e);
        }
    }

    /** Impressão digital do corpo do pedido, para reconhecer a mesma chave com corpo diferente. Não guarda senha. */
    private static String impressao(JsonNode corpo) {
        return Auth.sha256Hex(corpo == null ? "" : corpo.toString());
    }
}
