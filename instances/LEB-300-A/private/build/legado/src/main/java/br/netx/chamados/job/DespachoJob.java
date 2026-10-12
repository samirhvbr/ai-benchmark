package br.netx.chamados.job;

import br.netx.chamados.Config;
import br.netx.chamados.db.Db;
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
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Entrega as notificações pendentes numa linha JSON por destinatário, no máximo uma vez cada, e agenda nova tentativa se falhar. */
public final class DespachoJob {
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
        Map<Long, List<String>> jaEscritas = new HashMap<>();
        int enviadas = 0;
        int doLote;
        do {
            doLote = db.tx(tx -> {
                List<Pendente> lote = repo.reivindicar(tx, agora, 50);
                for (Pendente n : lote) {
                    try {
                        Path arquivo = config.notificacaoDir().resolve(n.empresaId() + ".jsonl");
                        List<String> chaves = jaEscritas.get(n.empresaId());
                        if (chaves == null) {
                            chaves = new ArrayList<>();
                            if (Files.exists(arquivo)) {
                                for (String linha : Files.readAllLines(arquivo, StandardCharsets.UTF_8)) {
                                    if (linha.isBlank()) continue;
                                    JsonNode no = json.readTree(linha);
                                    chaves.add(no.path("chave").asText() + "|" + no.path("destinatario").asText());
                                }
                            }
                            jaEscritas.put(n.empresaId(), chaves);
                        }
                        Files.createDirectories(config.notificacaoDir());
                        String chave = n.chaveEvento() + "|" + n.destinatario();
                        if (!chaves.contains(chave)) {
                            Map<String, Object> linha = new LinkedHashMap<>();
                            linha.put("chave", n.chaveEvento());
                            linha.put("destinatario", n.destinatario());
                            linha.put("assunto", n.assunto());
                            linha.put("corpo", n.corpo());
                            linha.put("enviada_em", agora.toString());
                            Files.writeString(arquivo, json.writeValueAsString(linha) + "\n", StandardCharsets.UTF_8,
                                    StandardOpenOption.CREATE, StandardOpenOption.APPEND);
                            chaves.add(chave);
                        }
                        repo.marcarEnviada(tx, n.id(), agora);
                    } catch (IOException | UncheckedIOException e) {
                        int tentativas = n.tentativas() + 1;
                        Instant proxima = agora.plusSeconds(60L * (1L << (tentativas - 1)));
                        repo.registrarFalha(tx, n.id(), tentativas, proxima, tentativas >= 5);
                    }
                }
                return lote.size();
            });
            enviadas += doLote;
        } while (doLote > 0);
        return enviadas;
    }
}
