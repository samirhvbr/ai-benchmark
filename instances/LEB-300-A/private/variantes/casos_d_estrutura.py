"""Control variants for the structure and cleanliness flaws (Template R)."""
import re
from motor import Caso, INST

CS = "servico/ChamadoService.java"
DESP = "job/DespachoJob.java"
AUTH = "auth/Auth.java"
APP = "App.java"
DB = "db/Db.java"
NOT = "repo/NotificacaoRepo.java"
ESC = "job/EscalonamentoJob.java"
REL = "relatorio/RelatorioService.java"
CSV = "relatorio/CsvExportador.java"
RH = "http/RelatorioHandlers.java"
CH = "http/ChamadoHandlers.java"
CAD = "http/CadastroHandlers.java"


def regex(padrao, novo, flags=re.S):
    def f(texto):
        r, n = re.subn(padrao, lambda m: novo, texto, count=1, flags=flags)
        assert n == 1, padrao[:60]
        return r
    return f


EQUIPE_DUPLICADA = '''        List<String> equipe = new ArrayList<>(tx.list("select email from usuario where empresa_id = ? and ativo and papel in ('ATENDENTE', 'ADMIN') order by email",
                rs -> rs.getString(1), c.empresaId()));
        if (c.tecnicoId() != null) {
            tx.one("select u.email from tecnico t join usuario u on u.id = t.usuario_id where t.id = ? and u.ativo", rs -> rs.getString(1), c.tecnicoId())
                    .ifPresent(email -> {
                        if (!equipe.contains(email)) equipe.add(email);
                    });
        }
        notificacoes.criar(tx, c.empresaId(), "escalonado", id, evento, equipe, "Chamado " + id + " escalonado", c.titulo());'''
EQUIPE_UMA_VEZ = '''        notificacoes.criar(tx, c.empresaId(), "escalonado", id, evento, notificacoes.equipeDoChamado(tx, c.empresaId(), c.tecnicoId()), "Chamado " + id + " escalonado", c.titulo());'''
EQUIPE_REFORMATADA = EQUIPE_DUPLICADA.replace("select email from usuario where empresa_id = ? and ativo and papel in ('ATENDENTE', 'ADMIN') order by email",
                                              "SELECT email\\n  FROM usuario WHERE empresa_id = ? AND ativo AND papel IN ('ATENDENTE', 'ADMIN') ORDER BY email")

CONTAGEM_NO_HANDLER = re.compile(r"            Map<String, Object> contagem = db\.tx\(tx -> \{.*?\n            \}\);\n            return Resp\.json\(200, contagem\);", re.S)
CONTAGEM_CLASSE_VIZINHA = '''package br.netx.chamados.http;

import br.netx.chamados.db.Db;
import br.netx.chamados.dominio.Dominio.StatusChamado;

import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.util.LinkedHashMap;
import java.util.Map;

final class ContagemPorStatus {
    private ContagemPorStatus() {}

    static Map<String, Object> de(Db db, long empresa) {
        return db.tx(tx -> {
            Map<String, Object> m = new LinkedHashMap<>();
            for (StatusChamado st : StatusChamado.values()) m.put(st.name(), 0L);
            try (PreparedStatement ps = tx.connection().prepareStatement("select status, count(*) from chamado where empresa_id = ? group by status")) {
                ps.setLong(1, empresa);
                try (ResultSet rs = ps.executeQuery()) {
                    while (rs.next()) m.put(rs.getString(1), rs.getLong(2));
                }
            } catch (SQLException e) {
                throw new Db.DbException(e);
            }
            return m;
        });
    }
}
'''

CONTEXTO = '''package br.netx.chamados.db;

import javax.sql.DataSource;

/** O DataSource da composição mais recente, para quem acessa o banco sem recebê-lo. */
final class Ambiente {
    private static volatile DataSource dataSource;

    private Ambiente() {}

    static void definir(DataSource ds) {
        dataSource = ds;
    }

    static DataSource dataSource() {
        return dataSource;
    }
}
'''


def csv_de_referencia(_texto):
    ref = (INST / "reference" / "src/main/java/br/netx/chamados/relatorio/CsvExportador.java").read_text(encoding="utf-8")
    return ref.replace("SlaService", "ChamadoService")


def transicionar_de_referencia(texto):
    ref = (INST / "plant" / "flaws_b_desempenho_limpeza_arquitetura.py").read_text(encoding="utf-8")
    m = re.search(r"TRANSICIONAR_REF = '''(.*?)'''", ref, re.S)
    novo = m.group(1)
    return regex(r"    public Visao transicionar\(.*?\n(?=    public Evento registrarEvento\()", novo + "\n")(texto)


def sem_copia(nome_metodo):
    return regex(r"    private static int %s\(Req r\) \{.*?\n    \}\n\n?" % nome_metodo, "")


def chamadas(texto):
    return texto.replace("pagina(r)", "r.pagina()").replace("tamanho(r)", "r.tamanho()")


def renomeia_copia(texto):
    texto = texto.replace("private static int pagina(Req r) {\n        String v = r.consulta(\"pagina\");\n        if (v == null) return 1;\n        try {\n            int n = Integer.parseInt(v);\n            if (n < 1 || n > Integer.MAX_VALUE) throw new NumberFormatException();\n            return n;",
                          "private static int numeroDaPagina(Req r) {\n        String texto = r.consulta(\"pagina\");\n        if (texto == null) return 1;\n        try {\n            int valor = Integer.parseInt(texto);\n            if (valor < 1 || valor > Integer.MAX_VALUE) throw new NumberFormatException();\n            return valor;")
    texto = texto.replace("private static int tamanho(Req r) {\n        String v = r.consulta(\"tamanho\");\n        if (v == null) return 20;\n        try {\n            int n = Integer.parseInt(v);\n            if (n < 1 || n > 100) throw new NumberFormatException();\n            return n;",
                          "private static int tamanhoDaPagina(Req r) {\n        String texto = r.consulta(\"tamanho\");\n        if (texto == null) return 20;\n        try {\n            int valor = Integer.parseInt(texto);\n            if (valor < 1 || valor > 100) throw new NumberFormatException();\n            return valor;")
    return re.sub(r"\btamanho\(r\)", "tamanhoDaPagina(r)", re.sub(r"\bpagina\(r\)", "numeroDaPagina(r)", texto))


CASOS = [
    # ---- ARCH-002
    Caso("ARCH-002", "arch002-usa-o-servico", "alternativa", "corrigida", car=True, de_referencia=[RH],
         edits=[(APP, "new RelatorioHandlers(relatorios, db).registrar(servidor);", "new RelatorioHandlers(relatorios).registrar(servidor);")]),
    Caso("ARCH-002", "arch002-classe-vizinha-no-pacote-http", "mutante", "nao_corrigida", novos={"src/main/java/br/netx/chamados/http/ContagemPorStatus.java": CONTAGEM_CLASSE_VIZINHA},
         edits=[(RH, regex(CONTAGEM_NO_HANDLER.pattern, "            return Resp.json(200, ContagemPorStatus.de(db, empresa));"))]),

    # ---- ARCH-004
    Caso("ARCH-004", "arch004-uma-regra", "alternativa", "corrigida", car=True, edits=[(ESC, EQUIPE_DUPLICADA, EQUIPE_UMA_VEZ)]),
    Caso("ARCH-004", "arch004-reformata-a-copia", "mutante", "nao_corrigida", edits=[(ESC, EQUIPE_DUPLICADA, EQUIPE_REFORMATADA)]),

    # ---- ARCH-008
    Caso("ARCH-008", "arch008-chave-vem-de-fora", "alternativa", "corrigida", car=True, edits=[
        (NOT, "import br.netx.chamados.servico.ChamadoService;\n", ""),
        (NOT, "public void inserir(Tx tx, long empresaId, String tipo, long chamadoId, long eventoId, String destinatario, String assunto, String corpo, Instant agora) {",
         "public void inserir(Tx tx, long empresaId, String chaveEvento, String destinatario, String assunto, String corpo, Instant agora) {"),
        (NOT, "empresaId, ChamadoService.chave(tipo, chamadoId, eventoId), destinatario, assunto, corpo, agora);", "empresaId, chaveEvento, destinatario, assunto, corpo, agora);"),
        (CS, "notificacaoRepo.inserir(tx, empresaId, tipo, chamadoId, eventoId, destinatario, assunto, corpo, clock.instant());",
         "notificacaoRepo.inserir(tx, empresaId, chave(tipo, chamadoId, eventoId), destinatario, assunto, corpo, clock.instant());")]),
    Caso("ARCH-008", "arch008-chave-no-dominio", "alternativa", "corrigida", car=True, edits=[
        ("dominio/Dominio.java", "    public static final class Erro extends RuntimeException {",
         "    public static String chaveDoEvento(String tipo, long chamadoId, long eventoId) {\n        return tipo + \":\" + chamadoId + \":\" + eventoId;\n    }\n\n    public static final class Erro extends RuntimeException {"),
        (NOT, "import br.netx.chamados.servico.ChamadoService;", "import br.netx.chamados.dominio.Dominio;"),
        (NOT, "ChamadoService.chave(tipo, chamadoId, eventoId)", "Dominio.chaveDoEvento(tipo, chamadoId, eventoId)")]),
    Caso("ARCH-008", "arch008-pacote-extra-vazio", "mutante", "nao_corrigida", novos={"src/main/java/br/netx/chamados/util/Nada.java": "package br.netx.chamados.util;\n\npublic final class Nada {\n    private Nada() {}\n}\n"}),

    # ---- ARCH-009
    Caso("ARCH-009", "arch009-constantes-nomeadas", "alternativa", "corrigida", car=True, edits=[
        (CS, "total.multipliedBy(8).dividedBy(10)", "total.multipliedBy(LIMIAR_NUMERADOR).dividedBy(LIMIAR_DENOMINADOR)"),
        (CS, "    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */",
         "    /** Fração do prazo de resolução a partir da qual o chamado sobe uma prioridade. */\n    public static final int LIMIAR_NUMERADOR = 8;\n    public static final int LIMIAR_DENOMINADOR = 10;\n\n    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */"),
        (DESP, "public final class DespachoJob {", "public final class DespachoJob {\n    private static final int LOTE = 50;\n    private static final int MAX_TENTATIVAS = 5;\n    private static final long ESPERA_BASE_SEGUNDOS = 60L;\n"),
        (DESP, "repo.reivindicar(tx, agora, 50)", "repo.reivindicar(tx, agora, LOTE)"),
        (DESP, "agora.plusSeconds(60L * (1L << (tentativas - 1)))", "agora.plusSeconds(ESPERA_BASE_SEGUNDOS * (1L << (tentativas - 1)))"),
        (DESP, "tentativas >= 5", "tentativas >= MAX_TENTATIVAS")]),
    Caso("ARCH-009", "arch009-so-o-limiar-do-sla", "mutante", "half", edits=[
        (CS, "total.multipliedBy(8).dividedBy(10)", "total.multipliedBy(LIMIAR_NUMERADOR).dividedBy(LIMIAR_DENOMINADOR)"),
        (CS, "    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */",
         "    public static final int LIMIAR_NUMERADOR = 8;\n    public static final int LIMIAR_DENOMINADOR = 10;\n\n    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */")]),
    Caso("ARCH-009", "arch009-nomes-sem-sentido", "limite", "corrigida", nota="the structure check passes; whether the names say anything is for the judge", edits=[
        (CS, "total.multipliedBy(8).dividedBy(10)", "total.multipliedBy(A).dividedBy(B)"),
        (CS, "    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */",
         "    public static final int A = 8;\n    public static final int B = 10;\n\n    /** Instante em que um chamado em andamento passa a merecer uma prioridade acima. */"),
        (DESP, "public final class DespachoJob {", "public final class DespachoJob {\n    private static final int C = 50;\n    private static final int D = 5;\n    private static final long E = 60L;\n"),
        (DESP, "repo.reivindicar(tx, agora, 50)", "repo.reivindicar(tx, agora, C)"),
        (DESP, "agora.plusSeconds(60L * (1L << (tentativas - 1)))", "agora.plusSeconds(E * (1L << (tentativas - 1)))"),
        (DESP, "tentativas >= 5", "tentativas >= D")]),

    # ---- ARCH-013
    Caso("ARCH-013", "arch013-data-source-na-instancia", "alternativa", "corrigida", car=True, remover=["db/Contexto.java"], edits=[
        (DB, "public final class Db {\n    public Db(DataSource ds) {\n        Contexto.definir(ds);\n    }", "public final class Db {\n    private final DataSource ds;\n\n    public Db(DataSource ds) {\n        this.ds = ds;\n    }"),
        (DB, "try (Connection c = Contexto.dataSource().getConnection()) {", "try (Connection c = ds.getConnection()) {")]),
    Caso("ARCH-013", "arch013-renomeia-o-singleton", "mutante", "nao_corrigida", remover=["db/Contexto.java"],
         novos={"src/main/java/br/netx/chamados/db/Ambiente.java": CONTEXTO}, edits=[
        (DB, "Contexto.definir(ds);", "Ambiente.definir(ds);"), (DB, "Contexto.dataSource().getConnection()", "Ambiente.dataSource().getConnection()")]),

    # ---- ARCH-014.a
    Caso("ARCH-014.a", "arch014a-os-dois", "alternativa", "corrigida", car=True, edits=[
        (AUTH, "clock.instant().plus(Duration.ofHours(8))", "clock.instant().plus(config.tokenValidade())"), (APP, "long intervalo = 60_000L;", "long intervalo = config.intervaloJobs().toMillis();")]),
    Caso("ARCH-014.a", "arch014a-so-o-token", "mutante", "half", edits=[(AUTH, "clock.instant().plus(Duration.ofHours(8))", "clock.instant().plus(config.tokenValidade())")]),
    Caso("ARCH-014.a", "arch014a-so-o-intervalo", "mutante", "half", edits=[(APP, "long intervalo = 60_000L;", "long intervalo = config.intervaloJobs().toMillis();")]),

    # ---- ARCH-011
    Caso("ARCH-011", "arch011-formata-linhas-recebidas", "alternativa", "corrigida", car=True, edits=[
        (CSV, csv_de_referencia),
        (REL, "return csv.gerar(tx, empresaId, periodo[0], periodo[1], e.zona());", "return csv.gerar(chamados.paraExportacao(tx, empresaId, periodo[0], periodo[1]), e.zona());")]),

    # ---- CLN-001
    Caso("CLN-001", "cln001-dividido-em-passos", "alternativa", "corrigida", car=True, de_referencia=[DESP]),
    Caso("CLN-001", "cln001-renomeia-sem-reduzir", "mutante", "nao_corrigida", edits=[
        (DESP, "    public int executar() {", "    public int executar() {\n        return executarTudo();\n    }\n\n    private int executarTudo() {")]),

    Caso("CLN-001", "cln001-junta-as-linhas", "mutante", "nao_corrigida", edits=[
        (DESP, lambda t: re.sub(r"(    public int executar\(\) \{\n)(.*?)(\n        return enviadas;\n    \})", lambda m: m.group(1) + re.sub(r"\n\s+", " ", m.group(2)) + m.group(3), t, count=1, flags=re.S))]),

    # ---- CLN-002
    Caso("CLN-002", "cln002-usa-o-que-a-requisicao-ja-oferece", "alternativa", "corrigida", car=True, edits=[
        (CH, sem_copia("pagina")), (CH, sem_copia("tamanho")), (CH, chamadas), (CAD, sem_copia("pagina")), (CAD, sem_copia("tamanho")), (CAD, chamadas)]),
    Caso("CLN-002", "cln002-uma-copia-compartilhada", "alternativa", "corrigida", car=True, edits=[
        (CH, "private static int pagina(Req r) {", "static int pagina(Req r) {"), (CH, "private static int tamanho(Req r) {", "static int tamanho(Req r) {"),
        (CAD, sem_copia("pagina")), (CAD, sem_copia("tamanho")),
        (CAD, lambda t: t.replace("(pagina(r)", "(ChamadoHandlers.pagina(r)").replace(" pagina(r)", " ChamadoHandlers.pagina(r)").replace(" tamanho(r)", " ChamadoHandlers.tamanho(r)").replace("(tamanho(r)", "(ChamadoHandlers.tamanho(r)"))]),
    Caso("CLN-002", "cln002-renomeia-a-copia", "mutante", "nao_corrigida", edits=[(CAD, renomeia_copia)]),

    # ---- CLN-005
    Caso("CLN-005", "cln005-remove", "alternativa", "corrigida", car=True, edits=[
        ("http/Servidor.java", regex(r"        // servidor\.setExecutor\(Executors\.newCachedThreadPool\(\)\);\n(?:        // [^\n]*\n){3}", ""))]),
    Caso("CLN-005", "cln005-bloco-comentado", "mutante", "nao_corrigida", edits=[
        ("http/Servidor.java", regex(r"        // servidor\.setExecutor\(Executors\.newCachedThreadPool\(\)\);\n(?:        // [^\n]*\n){3}",
         '        /*\n        servidor.setExecutor(Executors.newCachedThreadPool());\n        servidor.setExecutor(null);\n        System.out.println("porta " + servidor.getAddress().getPort());\n        servidor.createContext("/health", ex -> ex.sendResponseHeaders(200, -1));\n        */\n'))]),

    # ---- CLN-007
    Caso("CLN-007", "cln007-clausulas-de-guarda", "alternativa", "corrigida", car=True, edits=[(CS, transicionar_de_referencia)]),
    Caso("CLN-007", "cln007-move-o-aninhamento", "mutante", "nao_corrigida", edits=[
        (CS, "    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {",
         "    public Visao transicionar(Tx tx, Principal p, long id, StatusChamado para) {\n        return transicionarDeVerdade(tx, p, id, para);\n    }\n\n    private Visao transicionarDeVerdade(Tx tx, Principal p, long id, StatusChamado para) {")]),
]
