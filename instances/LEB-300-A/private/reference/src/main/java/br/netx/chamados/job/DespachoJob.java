package br.netx.chamados.job;

import br.netx.chamados.Config;
import br.netx.chamados.db.Db;
import br.netx.chamados.db.Db.Tx;
import br.netx.chamados.repo.NotificacaoRepo;
import br.netx.chamados.repo.NotificacaoRepo.Pendente;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.io.IOException;
import java.io.UncheckedIOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.time.Clock;
import java.time.Instant;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/** Entrega as notificações pendentes numa linha JSON por destinatário, no máximo uma vez cada, e agenda nova tentativa se falhar. */
public final class DespachoJob {
    private static final int LOTE = 50;
    private static final int MAX_TENTATIVAS = 5;

    private final Db db;
    private final NotificacaoRepo repo;
    private final Config config;
    private final Clock clock;
    private final ObjectMapper json = new ObjectMapper();

    public DespachoJob(Db db, NotificacaoRepo repo, Config config, Clock clock) {
        this.db = db;
        this.repo = repo;
        this.config = config;
        this.clock = clock;
    }

    public int executar() {
        Instant agora = clock.instant();
        Map<Long, Set<String>> jaEscritas = new HashMap<>();
        int enviadas = 0;
        int doLote;
        do {
            doLote = db.tx(tx -> despacharLote(tx, agora, jaEscritas));
            enviadas += doLote;
        } while (doLote > 0);
        return enviadas;
    }

    /** Devolve quantas notificações foram reivindicadas, entregues ou não. A linha só vai ao arquivo se ainda não estiver lá. */
    private int despacharLote(Tx tx, Instant agora, Map<Long, Set<String>> jaEscritas) {
        List<Pendente> lote = repo.reivindicar(tx, agora, LOTE);
        for (Pendente n : lote) {
            try {
                escrever(n, agora, jaEscritas.computeIfAbsent(n.empresaId(), this::lerChaves));
                repo.marcarEnviada(tx, n.id(), agora);
            } catch (IOException | UncheckedIOException e) {
                int tentativas = n.tentativas() + 1;
                Instant proxima = agora.plusSeconds(60L * (1L << (tentativas - 1)));
                repo.registrarFalha(tx, n.id(), tentativas, proxima, tentativas >= MAX_TENTATIVAS);
            }
        }
        return lote.size();
    }

    private Path arquivo(long empresaId) {
        return config.notificacaoDir().resolve(empresaId + ".jsonl");
    }

    private Set<String> lerChaves(long empresaId) {
        Set<String> chaves = new HashSet<>();
        Path arquivo = arquivo(empresaId);
        if (!Files.exists(arquivo)) return chaves;
        try {
            for (String linha : Files.readAllLines(arquivo, StandardCharsets.UTF_8)) {
                if (linha.isBlank()) continue;
                JsonNode no = json.readTree(linha);
                chaves.add(no.path("chave").asText() + "|" + no.path("destinatario").asText());
            }
        } catch (IOException e) {
            throw new UncheckedIOException(e);
        }
        return chaves;
    }

    private void escrever(Pendente n, Instant agora, Set<String> jaEscritas) throws IOException {
        Files.createDirectories(config.notificacaoDir());
        if (jaEscritas.contains(n.chaveEvento() + "|" + n.destinatario())) return;
        Map<String, Object> linha = new LinkedHashMap<>();
        linha.put("chave", n.chaveEvento());
        linha.put("destinatario", n.destinatario());
        linha.put("assunto", n.assunto());
        linha.put("corpo", n.corpo());
        linha.put("enviada_em", agora.toString());
        Files.writeString(arquivo(n.empresaId()), json.writeValueAsString(linha) + "\n", StandardCharsets.UTF_8,
                StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        jaEscritas.add(n.chaveEvento() + "|" + n.destinatario());
    }
}
