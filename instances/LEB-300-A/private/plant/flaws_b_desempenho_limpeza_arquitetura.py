"""PERF, CLN and ARCH flaws. SEC flaws come last, in flaws_z_seguranca.py."""
from plantar import SRC

DESPACHO = '''package br.netx.chamados.job;

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
                            Files.writeString(arquivo, json.writeValueAsString(linha) + "\\n", StandardCharsets.UTF_8,
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
'''

TRANSICIONAR_REF = '''    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {
        Chamado c = chamados.travar(tx, id, p.empresaId()).orElseThrow(Erro::naoEncontrado);
        if (!c.status().pode(para)) throw Erro.conflito("transição " + c.status() + " -> " + para + " não é permitida");
        Instant agora = clock.instant();
        Map<String, Object> campos = new LinkedHashMap<>();
        campos.put("status", para);
        if (para == StatusChamado.EM_ATENDIMENTO && c.primeiraRespostaEm() == null) campos.put("primeira_resposta_em", agora);
        if (para == StatusChamado.RESOLVIDO) campos.put("resolvido_em", agora);
        if (c.status() == StatusChamado.RESOLVIDO && para == StatusChamado.EM_ATENDIMENTO) campos.put("resolvido_em", null);
        chamados.atualizar(tx, id, campos);
        chamados.inserirEvento(tx, id, "TRANSICAO", p.usuarioId(), c.status() + " -> " + para, agora);
        return chamados.visao(tx, id, c.empresaId()).orElseThrow();
    }
'''

TRANSICIONAR_LEGADO = '''    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {
        Optional<Chamado> encontrado = chamados.travar(tx, id, p.empresaId());
        if (encontrado.isPresent()) {
            Chamado c = encontrado.get();
            if (p.empresaId() != null) {
                if (c.status().pode(para)) {
                    Instant agora = clock.instant();
                    Map<String, Object> campos = new LinkedHashMap<>();
                    campos.put("status", para);
                    if (para == StatusChamado.EM_ATENDIMENTO) {
                        if (c.primeiraRespostaEm() == null) {
                            campos.put("primeira_resposta_em", agora);
                        }
                    } else {
                        if (para == StatusChamado.RESOLVIDO) {
                            campos.put("resolvido_em", agora);
                        }
                    }
                    if (c.status() == StatusChamado.RESOLVIDO) {
                        if (para == StatusChamado.EM_ATENDIMENTO) {
                            campos.put("resolvido_em", null);
                        }
                    }
                    chamados.atualizar(tx, id, campos);
                    chamados.inserirEvento(tx, id, "TRANSICAO", p.usuarioId(), c.status() + " -> " + para, agora);
                    return chamados.visao(tx, id, c.empresaId()).orElseThrow();
                } else {
                    throw Erro.conflito("transição " + c.status() + " -> " + para + " não é permitida");
                }
            } else {
                throw Erro.proibido();
            }
        } else {
            throw Erro.naoEncontrado();
        }
    }
'''

PAGINA = '''    private static int pagina(Req r) {
        String v = r.consulta("pagina");
        if (v == null) return 1;
        try {
            int n = Integer.parseInt(v);
            if (n < 1 || n > Integer.MAX_VALUE) throw new NumberFormatException();
            return n;
        } catch (NumberFormatException e) {
            throw Erro.invalido("pagina deve ser um inteiro entre 1 e " + Integer.MAX_VALUE);
        }
    }

    private static int tamanho(Req r) {
        String v = r.consulta("tamanho");
        if (v == null) return 20;
        try {
            int n = Integer.parseInt(v);
            if (n < 1 || n > 100) throw new NumberFormatException();
            return n;
        } catch (NumberFormatException e) {
            throw Erro.invalido("tamanho deve ser um inteiro entre 1 e 100");
        }
    }

'''

FLAWS = [

    ("PERF-001", [
        (SRC + "repo/ChamadoRepo.java",
         "        List<Visao> itens = tx.list(SELECAO + onde + \" order by \" + ordenacaoPorPrioridade() + \", c.prazo_resolucao, c.id limit ? offset ?\",\n"
         "                ChamadoRepo::visao, args.toArray());",
         "        List<Chamado> basicos = tx.list(\"select \" + COLUNAS + \" from chamado c left join tecnico t on t.id = c.tecnico_id\" + onde\n"
         "                + \" order by \" + ordenacaoPorPrioridade() + \", c.prazo_resolucao, c.id limit ? offset ?\", ChamadoRepo::chamado, args.toArray());\n"
         "        List<Visao> itens = new ArrayList<>();\n"
         "        for (Chamado c : basicos) {\n"
         "            String cliente = tx.one(\"select nome from cliente where id = ?\", rs -> rs.getString(1), c.clienteId()).orElse(null);\n"
         "            String tecnico = c.tecnicoId() == null ? null : tx.one(\"select nome from tecnico where id = ?\", rs -> rs.getString(1), c.tecnicoId()).orElse(null);\n"
         "            itens.add(new Visao(c, cliente, tecnico));\n"
         "        }"),
    ]),

    ("PERF-003", [
        (SRC + "repo/OrdemRepo.java",
         "        long total = tx.count(\"select count(*)\" + de, args.toArray());\n"
         "        args.add(tamanho);\n"
         "        args.add((pagina - 1) * tamanho);\n"
         "        List<Ordem> itens = tx.list(\"select \" + COLUNAS + de + \" order by o.agendada_para, o.id limit ? offset ?\", OrdemRepo::ordem, args.toArray());\n"
         "        return new Pagina<>(itens, pagina, tamanho, total);",
         "        List<Ordem> todas = tx.list(\"select \" + COLUNAS + de + \" order by o.agendada_para, o.id\", OrdemRepo::ordem, args.toArray());\n"
         "        int inicio = Math.min((pagina - 1) * tamanho, todas.size());\n"
         "        List<Ordem> itens = new ArrayList<>(todas.subList(inicio, Math.min(inicio + tamanho, todas.size())));\n"
         "        return new Pagina<>(itens, pagina, tamanho, todas.size());"),
    ]),

    ("PERF-005", [
        (SRC + "relatorio/RelatorioService.java",
         "import java.util.ArrayList;",
         "import java.util.ArrayList;\nimport java.util.Comparator;"),
        (SRC + "relatorio/RelatorioService.java",
         "            for (Produtividade t : ordens.produtividade(tx, empresaId, periodo[0], periodo[1])) {",
         "            List<Produtividade> todos = new ArrayList<>();\n"
         "            for (long[] tecnico : ordens.tecnicosAtivos(tx, empresaId)) todos.add(ordens.produtividadeDo(tx, tecnico[0], periodo[0], periodo[1]));\n"
         "            todos.sort(Comparator.comparingLong(Produtividade::concluidas).reversed().thenComparing(Produtividade::nome));\n"
         "            for (Produtividade t : todos) {"),
        (SRC + "repo/OrdemRepo.java",
         "                de, ate, empresaId);\n    }\n}",
         "                de, ate, empresaId);\n    }\n\n"
         "    public List<long[]> tecnicosAtivos(Tx tx, long empresaId) {\n"
         "        return tx.list(\"select id from tecnico where empresa_id = ? and ativo order by id\", rs -> new long[] {rs.getLong(1)}, empresaId);\n"
         "    }\n\n"
         "    public Produtividade produtividadeDo(Tx tx, long tecnicoId, Instant de, Instant ate) {\n"
         "        return tx.one(\"select t.id, t.nome, count(o.id) as concluidas, coalesce(sum(o.valor_cents), 0) as soma, \"\n"
         "                + \"avg(extract(epoch from (o.concluida_em - o.iniciada_em)) / 3600.0) as media \"\n"
         "                + \"from tecnico t left join ordem_servico o on o.tecnico_id = t.id and o.status = 'CONCLUIDA' and o.concluida_em >= ? and o.concluida_em < ? \"\n"
         "                + \"where t.id = ? group by t.id, t.nome\",\n"
         "                rs -> new Produtividade(rs.getLong(\"id\"), rs.getString(\"nome\"), rs.getLong(\"concluidas\"), rs.getLong(\"soma\"), rs.getBigDecimal(\"media\")),\n"
         "                de, ate, tecnicoId).orElseThrow();\n"
         "    }\n}"),
    ]),

    ("PERF-011", [
        (SRC + "repo/ChamadoRepo.java",
         "    /** Chamados em andamento que ainda não estão na prioridade máxima. */",
         "    public List<Chamado> emAndamento(Tx tx) {\n"
         "        return tx.list(\"select \" + COLUNAS + \" from chamado c where c.status in ('ABERTO', 'EM_ATENDIMENTO', 'AGUARDANDO_CLIENTE') \"\n"
         "                + \"and c.prioridade <> 'CRITICA' order by c.id\", ChamadoRepo::chamado);\n"
         "    }\n\n"
         "    /** Chamados em andamento que ainda não estão na prioridade máxima. */"),
        (SRC + "job/EscalonamentoJob.java",
         "        List<Candidato> candidatos = db.tx(chamados::candidatosAoEscalonamento);\n"
         "        List<Long> devidos = new ArrayList<>();\n"
         "        for (Candidato c : candidatos) {",
         "        List<Chamado> candidatos = db.tx(chamados::emAndamento);\n"
         "        List<Long> devidos = new ArrayList<>();\n"
         "        for (Chamado c : candidatos) {"),
    ]),

    # one hunk shared by three flaws: the dispatcher written as a single long method (CLN-001), with the already-written keys in a List
    # searched for every notification (PERF-012) and the limits written as bare numbers (ARCH-009)
    ("CLN-001+PERF-012+ARCH-009", [
        (SRC + "job/DespachoJob.java", "*", DESPACHO),
        (SRC + "servico/SlaService.java",
         "    /** Fração do prazo de resolução a partir da qual o chamado sobe uma prioridade. */\n"
         "    public static final int LIMIAR_NUMERADOR = 8;\n"
         "    public static final int LIMIAR_DENOMINADOR = 10;\n\n",
         ""),
        (SRC + "servico/SlaService.java",
         "total.multipliedBy(LIMIAR_NUMERADOR).dividedBy(LIMIAR_DENOMINADOR)",
         "total.multipliedBy(8).dividedBy(10)"),
    ]),

    ("PERF-013", [
        (SRC + "servico/OrdemService.java",
         "    private void resolverSeTerminou(Tx tx, Chamado c, Instant agora, Principal p) {\n        if (!c.status().emAndamento()) return;",
         "    private void resolverSeTerminou(Tx tx, Chamado c, Instant agora, Principal p) {\n"
         "        c = chamados.travar(tx, c.id(), c.empresaId()).orElseThrow();\n"
         "        if (!c.status().emAndamento()) return;"),
    ]),

    ("CLN-007", [
        (SRC + "servico/ChamadoService.java", "import java.util.Map;", "import java.util.Map;\nimport java.util.Optional;"),
        (SRC + "servico/ChamadoService.java", TRANSICIONAR_REF, TRANSICIONAR_LEGADO),
    ]),

    ("CLN-005", [
        (SRC + "http/Servidor.java",
         "        servidor.setExecutor(Executors.newFixedThreadPool(8));",
         "        // servidor.setExecutor(Executors.newCachedThreadPool());\n"
         "        // servidor.setExecutor(null);\n"
         "        // System.out.println(\"porta \" + servidor.getAddress().getPort());\n"
         "        // servidor.createContext(\"/health\", ex -> ex.sendResponseHeaders(200, -1));\n"
         "        servidor.setExecutor(Executors.newFixedThreadPool(8));"),
    ]),

    ("CLN-002", [
        (SRC + "http/ChamadoHandlers.java",
         "chamados.listar(tx, p, r.empresaIdPedida(), status, prioridade, tecnico, r.pagina(), r.tamanho())",
         "chamados.listar(tx, p, r.empresaIdPedida(), status, prioridade, tecnico, pagina(r), tamanho(r))"),
        (SRC + "http/ChamadoHandlers.java",
         "ordens.listar(tx, p, r.empresaIdPedida(), status, tecnico, r.pagina(), r.tamanho())",
         "ordens.listar(tx, p, r.empresaIdPedida(), status, tecnico, pagina(r), tamanho(r))"),
        (SRC + "http/ChamadoHandlers.java", "    private static long numero(String texto, String nome) {", PAGINA + "    private static long numero(String texto, String nome) {"),
        (SRC + "http/CadastroHandlers.java",
         "repo.empresas(tx, so, r.tamanho(), (r.pagina() - 1) * r.tamanho()), r.pagina(), r.tamanho(), repo.totalEmpresas(tx, so)",
         "repo.empresas(tx, so, tamanho(r), (pagina(r) - 1) * tamanho(r)), pagina(r), tamanho(r), repo.totalEmpresas(tx, so)"),
        (SRC + "http/CadastroHandlers.java", "repo.usuarios(tx, empresa, r.pagina(), r.tamanho())", "repo.usuarios(tx, empresa, pagina(r), tamanho(r))"),
        (SRC + "http/CadastroHandlers.java", "repo.clientes(tx, p.empresaId(), r.pagina(), r.tamanho())", "repo.clientes(tx, p.empresaId(), pagina(r), tamanho(r))"),
        (SRC + "http/CadastroHandlers.java", "repo.tecnicos(tx, p.empresaId(), r.pagina(), r.tamanho())", "repo.tecnicos(tx, p.empresaId(), pagina(r), tamanho(r))"),
        (SRC + "http/CadastroHandlers.java", "    // ---- validações e formato\n", "    // ---- validações e formato\n\n" + PAGINA.rstrip("\n") + "\n"),
    ]),

    ("ARCH-014", [
        (SRC + "auth/Auth.java", "import java.time.Clock;\nimport java.time.Instant;", "import java.time.Clock;\nimport java.time.Duration;\nimport java.time.Instant;"),
        (SRC + "auth/Auth.java", "clock.instant().plus(config.tokenValidade())", "clock.instant().plus(Duration.ofHours(8))"),
        (SRC + "App.java", "            long intervalo = config.intervaloJobs().toMillis();", "            long intervalo = 60_000L;"),
    ]),

    ("ARCH-013", [
        (SRC + "db/Contexto.java", None,
         "package br.netx.chamados.db;\n\nimport javax.sql.DataSource;\n\n"
         "/** O DataSource da composição mais recente, para quem acessa o banco sem recebê-lo. */\n"
         "final class Contexto {\n"
         "    private static volatile DataSource dataSource;\n\n"
         "    private Contexto() {}\n\n"
         "    static void definir(DataSource ds) {\n        dataSource = ds;\n    }\n\n"
         "    static DataSource dataSource() {\n        return dataSource;\n    }\n}\n"),
        (SRC + "db/Db.java",
         "public final class Db {\n    private final DataSource ds;\n\n    public Db(DataSource ds) {\n        this.ds = ds;\n    }",
         "public final class Db {\n    public Db(DataSource ds) {\n        Contexto.definir(ds);\n    }"),
        (SRC + "db/Db.java", "        try (Connection c = ds.getConnection()) {", "        try (Connection c = Contexto.dataSource().getConnection()) {"),
    ]),

    ("ARCH-004", [
        (SRC + "job/EscalonamentoJob.java",
         "        notificacoes.criar(tx, c.empresaId(), \"escalonado\", id, evento, notificacoes.equipeDoChamado(tx, c.empresaId(), c.tecnicoId()),\n"
         "                \"Chamado \" + id + \" escalonado\", c.titulo());",
         "        List<String> equipe = new ArrayList<>(tx.list(\"select email from usuario where empresa_id = ? and ativo and papel in ('ATENDENTE', 'ADMIN') order by email\",\n"
         "                rs -> rs.getString(1), c.empresaId()));\n"
         "        if (c.tecnicoId() != null) {\n"
         "            tx.one(\"select u.email from tecnico t join usuario u on u.id = t.usuario_id where t.id = ? and u.ativo\", rs -> rs.getString(1), c.tecnicoId())\n"
         "                    .ifPresent(email -> {\n"
         "                        if (!equipe.contains(email)) equipe.add(email);\n"
         "                    });\n"
         "        }\n"
         "        notificacoes.criar(tx, c.empresaId(), \"escalonado\", id, evento, equipe, \"Chamado \" + id + \" escalonado\", c.titulo());"),
    ]),

    ("ARCH-008", [
        (SRC + "servico/NotificacaoService.java",
         "        String chave = tipo + \":\" + chamadoId + \":\" + eventoId;\n"
         "        for (String destinatario : new LinkedHashSet<>(destinatarios)) {\n"
         "            repo.inserir(tx, empresaId, chave, destinatario, assunto, corpo, clock.instant());\n"
         "        }",
         "        for (String destinatario : new LinkedHashSet<>(destinatarios)) {\n"
         "            repo.inserir(tx, empresaId, tipo, chamadoId, eventoId, destinatario, assunto, corpo, clock.instant());\n"
         "        }"),
        (SRC + "servico/NotificacaoService.java",
         "    public List<String> atendentesEAdmins(Tx tx, long empresaId) {",
         "    public static String chave(String tipo, long chamadoId, long eventoId) {\n"
         "        return tipo + \":\" + chamadoId + \":\" + eventoId;\n"
         "    }\n\n"
         "    public List<String> atendentesEAdmins(Tx tx, long empresaId) {"),
        (SRC + "repo/NotificacaoRepo.java", "import br.netx.chamados.db.Db.Tx;", "import br.netx.chamados.db.Db.Tx;\nimport br.netx.chamados.servico.NotificacaoService;"),
        (SRC + "repo/NotificacaoRepo.java",
         "    public void inserir(Tx tx, long empresaId, String chaveEvento, String destinatario, String assunto, String corpo, Instant agora) {",
         "    public void inserir(Tx tx, long empresaId, String tipo, long chamadoId, long eventoId, String destinatario, String assunto, String corpo, Instant agora) {"),
        (SRC + "repo/NotificacaoRepo.java",
         "                empresaId, chaveEvento, destinatario, assunto, corpo, agora);",
         "                empresaId, NotificacaoService.chave(tipo, chamadoId, eventoId), destinatario, assunto, corpo, agora);"),
    ]),

    ("ARCH-011", [
        (SRC + "db/Db.java",
         "        Tx(Connection c) {\n            this.c = c;\n        }",
         "        Tx(Connection c) {\n            this.c = c;\n        }\n\n        public Connection connection() {\n            return c;\n        }"),
        (SRC + "relatorio/CsvExportador.java",
         "import br.netx.chamados.repo.ChamadoRepo.LinhaExportacao;\nimport br.netx.chamados.servico.SlaService;\n\nimport java.time.Instant;\nimport java.time.ZoneId;\nimport java.time.format.DateTimeFormatter;\nimport java.util.List;",
         "import br.netx.chamados.db.Db;\nimport br.netx.chamados.db.Db.Tx;\nimport br.netx.chamados.servico.SlaService;\n\n"
         "import java.sql.PreparedStatement;\nimport java.sql.ResultSet;\nimport java.sql.SQLException;\nimport java.time.Instant;\nimport java.time.OffsetDateTime;\n"
         "import java.time.ZoneId;\nimport java.time.ZoneOffset;\nimport java.time.format.DateTimeFormatter;"),
        (SRC + "relatorio/CsvExportador.java",
         "    public String gerar(List<LinhaExportacao> linhas, ZoneId zona) {\n"
         "        StringBuilder csv = new StringBuilder(CABECALHO).append('\\n');\n"
         "        for (LinhaExportacao l : linhas) {\n"
         "            String cumprido = l.resolvidoEm() == null ? \"\" : sla.cumprido(l.resolvidoEm(), l.prazoResolucao()) ? \"SIM\" : \"NAO\";\n"
         "            csv.append(String.join(\";\",\n"
         "                    String.valueOf(l.id()), texto(l.titulo()), texto(l.clienteNome()), l.prioridade().name(), l.status().name(),\n"
         "                    data(l.abertoEm(), zona), data(l.prazoResolucao(), zona), data(l.resolvidoEm(), zona), texto(l.tecnicoNome()), cumprido)).append('\\n');\n"
         "        }\n"
         "        return csv.toString();\n"
         "    }",
         "    public String gerar(Tx tx, long empresaId, Instant de, Instant ate, ZoneId zona) {\n"
         "        StringBuilder csv = new StringBuilder(CABECALHO).append('\\n');\n"
         "        try (PreparedStatement ps = tx.connection().prepareStatement(\n"
         "                \"select c.id, c.titulo, cl.nome as cliente_nome, c.prioridade, c.status, c.aberto_em, c.prazo_resolucao, c.resolvido_em, t.nome as tecnico_nome \"\n"
         "                        + \"from chamado c join cliente cl on cl.id = c.cliente_id left join tecnico t on t.id = c.tecnico_id \"\n"
         "                        + \"where c.empresa_id = ? and c.aberto_em >= ? and c.aberto_em < ? order by c.aberto_em, c.id\")) {\n"
         "            ps.setLong(1, empresaId);\n"
         "            ps.setObject(2, OffsetDateTime.ofInstant(de, ZoneOffset.UTC));\n"
         "            ps.setObject(3, OffsetDateTime.ofInstant(ate, ZoneOffset.UTC));\n"
         "            try (ResultSet rs = ps.executeQuery()) {\n"
         "                while (rs.next()) {\n"
         "                    Instant prazo = Db.instante(rs, \"prazo_resolucao\");\n"
         "                    Instant resolvido = Db.instante(rs, \"resolvido_em\");\n"
         "                    String cumprido = resolvido == null ? \"\" : sla.cumprido(resolvido, prazo) ? \"SIM\" : \"NAO\";\n"
         "                    csv.append(String.join(\";\",\n"
         "                            String.valueOf(rs.getLong(\"id\")), texto(rs.getString(\"titulo\")), texto(rs.getString(\"cliente_nome\")),\n"
         "                            rs.getString(\"prioridade\"), rs.getString(\"status\"), data(Db.instante(rs, \"aberto_em\"), zona), data(prazo, zona),\n"
         "                            data(resolvido, zona), texto(rs.getString(\"tecnico_nome\")), cumprido)).append('\\n');\n"
         "                }\n"
         "            }\n"
         "        } catch (SQLException e) {\n"
         "            throw new Db.DbException(e);\n"
         "        }\n"
         "        return csv.toString();\n"
         "    }"),
        (SRC + "relatorio/RelatorioService.java",
         "            return csv.gerar(chamados.paraExportacao(tx, empresaId, periodo[0], periodo[1]), e.zona());",
         "            return csv.gerar(tx, empresaId, periodo[0], periodo[1], e.zona());"),
    ]),

    ("ARCH-002", [
        (SRC + "http/RelatorioHandlers.java",
         "import br.netx.chamados.dominio.Dominio.Papel;\nimport br.netx.chamados.dominio.Dominio.Principal;\nimport br.netx.chamados.http.Servidor.Req;\nimport br.netx.chamados.http.Servidor.Resp;\nimport br.netx.chamados.relatorio.RelatorioService;\n\nimport java.util.List;\nimport java.util.Map;",
         "import br.netx.chamados.db.Db;\nimport br.netx.chamados.dominio.Dominio.Erro;\nimport br.netx.chamados.dominio.Dominio.Papel;\nimport br.netx.chamados.dominio.Dominio.Principal;\n"
         "import br.netx.chamados.dominio.Dominio.StatusChamado;\nimport br.netx.chamados.http.Servidor.Req;\nimport br.netx.chamados.http.Servidor.Resp;\nimport br.netx.chamados.relatorio.RelatorioService;\n\n"
         "import java.sql.PreparedStatement;\nimport java.sql.ResultSet;\nimport java.sql.SQLException;\nimport java.util.LinkedHashMap;\nimport java.util.List;\nimport java.util.Map;"),
        (SRC + "http/RelatorioHandlers.java",
         "    private final RelatorioService relatorios;\n\n    public RelatorioHandlers(RelatorioService relatorios) {\n        this.relatorios = relatorios;\n    }",
         "    private final RelatorioService relatorios;\n    private final Db db;\n\n    public RelatorioHandlers(RelatorioService relatorios, Db db) {\n        this.relatorios = relatorios;\n        this.db = db;\n    }"),
        (SRC + "http/RelatorioHandlers.java",
         "        s.rota(\"GET\", \"/relatorios/status\", false, r -> {\n"
         "            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);\n"
         "            return Resp.json(200, relatorios.status(p, r.empresaIdPedida()));\n"
         "        });",
         "        s.rota(\"GET\", \"/relatorios/status\", false, r -> {\n"
         "            Principal p = quem(r, Papel.ADMIN, Papel.ATENDENTE);\n"
         "            Long empresa = p.plataforma() ? r.empresaIdPedida() : p.empresaId();\n"
         "            if (empresa == null) throw Erro.invalido(\"empresa_id é obrigatório\");\n"
         "            Map<String, Object> contagem = db.tx(tx -> {\n"
         "                Map<String, Object> m = new LinkedHashMap<>();\n"
         "                for (StatusChamado st : StatusChamado.values()) m.put(st.name(), 0L);\n"
         "                try (PreparedStatement ps = tx.connection().prepareStatement(\"select status, count(*) from chamado where empresa_id = ? group by status\")) {\n"
         "                    ps.setLong(1, empresa);\n"
         "                    try (ResultSet rs = ps.executeQuery()) {\n"
         "                        while (rs.next()) m.put(rs.getString(1), rs.getLong(2));\n"
         "                    }\n"
         "                } catch (SQLException e) {\n"
         "                    throw new Db.DbException(e);\n"
         "                }\n"
         "                return m;\n"
         "            });\n"
         "            return Resp.json(200, contagem);\n"
         "        });"),
        (SRC + "App.java", "new RelatorioHandlers(relatorios).registrar(servidor);", "new RelatorioHandlers(relatorios, db).registrar(servidor);"),
    ]),
]
