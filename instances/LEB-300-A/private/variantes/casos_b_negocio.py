"""Control variants for the business-rule, concurrency and diagnosis flaws."""
import re
from motor import Caso

CS = "servico/ChamadoService.java"
REL = "relatorio/RelatorioService.java"
DOM = "dominio/Dominio.java"
JOB = "job/LancamentoJob.java"
DESP = "job/DespachoJob.java"
NOT = "repo/NotificacaoRepo.java"


def remove_linhas(*campos):
    """Removes the 'campos.putIfAbsent("x", ...)' write-backs of the listed fields."""
    def f(texto):
        for c in campos:
            texto, n = re.subn(r'        campos\.putIfAbsent\("%s", [^\n]*\n' % c, "", texto)
            assert n == 1, c
        return texto
    return f


def troca_no_metodo(assinatura, velho, novo):
    def f(texto):
        i = texto.index(assinatura)
        j = texto.index(velho, i)
        return texto[:j] + novo + texto[j + len(velho):]
    return f


def troca_todas(velho, novo):
    return lambda texto: texto.replace(velho, novo)


def troca_primeira(velho, novo):
    return lambda texto: texto.replace(velho, novo, 1)


LANCAR_LOTE = '''    private int lancarLote(Instant agora) {
        List<Ordem> lote = db.tx(tx -> {
            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);
            List<Ordem> proximas = ordens.semLancamento(tx, LOTE);
            if (!proximas.isEmpty()) ordens.gravarPontoDeRetomada(tx, proximas.get(proximas.size() - 1).id());
            return proximas;
        });
        return db.tx(tx -> lancar(tx, lote, agora));
    }
'''

CASOS = [
    # ---- BUG-009
    Caso("BUG-009", "bug009-permite-reabrir", "alternativa", "corrigida", car=True, edits=[(DOM, "case RESOLVIDO -> para == FECHADO;", "case RESOLVIDO -> para == FECHADO || para == EM_ATENDIMENTO;")]),
    Caso("BUG-009", "bug009-reabre-para-aberto", "mutante", "nao_corrigida", edits=[(DOM, "case RESOLVIDO -> para == FECHADO;", "case RESOLVIDO -> para == FECHADO || para == ABERTO;")]),

    # ---- BUG-013
    Caso("BUG-013", "bug013-half-up", "alternativa", "corrigida", car=True, edits=[(REL, troca_todas("RoundingMode.DOWN", "RoundingMode.HALF_UP"))]),
    Caso("BUG-013", "bug013-so-o-percentual", "mutante", "nao_corrigida", edits=[(REL, troca_primeira("RoundingMode.DOWN", "RoundingMode.HALF_UP"))]),
    Caso("BUG-013", "bug013-half-even", "mutante", "nao_corrigida", edits=[(REL, troca_todas("RoundingMode.DOWN", "RoundingMode.HALF_EVEN"))]),
    Caso("BUG-013", "bug013-ceiling", "mutante", "nao_corrigida", edits=[(REL, troca_todas("RoundingMode.DOWN", "RoundingMode.CEILING"))]),

    # ---- BUG-007
    Caso("BUG-007", "bug007-os-dois-limites", "alternativa", "corrigida", car=True, edits=[
        (CS, "resolvidoEm != null && resolvidoEm.isBefore(prazoResolucao);", "resolvidoEm != null && !resolvidoEm.isAfter(prazoResolucao);"),
        (CS, "if (restante < disponivel) return t.plusSeconds(restante).toInstant();", "if (restante <= disponivel) return t.plusSeconds(restante).toInstant();")]),
    Caso("BUG-007", "bug007-so-o-prazo-inclusivo", "mutante", "nao_corrigida", edits=[
        (CS, "resolvidoEm != null && resolvidoEm.isBefore(prazoResolucao);", "resolvidoEm != null && !resolvidoEm.isAfter(prazoResolucao);")]),
    Caso("BUG-007", "bug007-so-a-janela", "mutante", "nao_corrigida", edits=[
        (CS, "if (restante < disponivel) return t.plusSeconds(restante).toInstant();", "if (restante <= disponivel) return t.plusSeconds(restante).toInstant();")]),

    # ---- BUG-006
    Caso("BUG-006", "bug006-fuso-da-empresa-nos-dois", "alternativa", "corrigida", car=True, edits=[
        (REL, troca_todas("ZoneId.systemDefault()", "e.zona()")), (JOB, "ZoneId.systemDefault()", "e.zona()")]),
    Caso("BUG-006", "bug006-so-o-relatorio", "mutante", "nao_corrigida", edits=[(REL, troca_todas("ZoneId.systemDefault()", "e.zona()"))]),
    Caso("BUG-006", "bug006-so-o-lancamento", "mutante", "nao_corrigida", edits=[(JOB, "ZoneId.systemDefault()", "e.zona()")]),
    Caso("BUG-006", "bug006-fuso-fixo-de-sao-paulo", "mutante", "nao_corrigida", edits=[
        (REL, troca_todas("ZoneId.systemDefault()", 'ZoneId.of("America/Sao_Paulo")')), (JOB, "ZoneId.systemDefault()", 'ZoneId.of("America/Sao_Paulo")')]),

    # ---- BUG-011.a
    Caso("BUG-011.a", "bug011a-so-os-campos-recebidos", "alternativa", "corrigida", car=True, edits=[
        (CS, remove_linhas("titulo", "descricao", "prioridade", "tecnico_id", "prazo_resposta", "prazo_resolucao"))]),
    Caso("BUG-011.a", "bug011a-trava-a-linha", "alternativa", "corrigida", car=True, edits=[
        (CS, troca_no_metodo("public Visao atualizar(", "chamados.porId(tx, id, p.empresaId())", "chamados.travar(tx, id, p.empresaId())"))]),
    Caso("BUG-011.a", "bug011a-so-a-prioridade-continua", "mutante", "nao_corrigida", edits=[
        (CS, remove_linhas("titulo", "descricao", "tecnico_id", "prazo_resposta", "prazo_resolucao"))]),
    Caso("BUG-011.a", "bug011a-so-o-titulo-continua", "mutante", "nao_corrigida", edits=[
        (CS, remove_linhas("descricao", "prioridade", "tecnico_id", "prazo_resposta", "prazo_resolucao"))]),
    Caso("BUG-011.a", "bug011a-trava-na-jvm", "mutante", "nao_corrigida", nota="serializes inside one JVM: passes with threads, fails with two independent JVMs", edits=[
        (CS, "    public Visao atualizar(Tx tx, Principal p, long id, Map<String, Object> patch) {",
         "    private static final Object TRAVA_DE_ATUALIZACAO = new Object();\n\n    public Visao atualizar(Tx tx, Principal p, long id, Map<String, Object> patch) {\n        synchronized (TRAVA_DE_ATUALIZACAO) {\n            return atualizarSemTrava(tx, p, id, patch);\n        }\n    }\n\n    private Visao atualizarSemTrava(Tx tx, Principal p, long id, Map<String, Object> patch) {")]),

    # ---- BUG-011.b
    Caso("BUG-011.b", "bug011b-pula-travadas", "alternativa", "corrigida", car=True, edits=[
        (NOT, "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ?\",", "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ? for update skip locked\",")]),
    Caso("BUG-011.b", "bug011b-trava-sem-pular", "alternativa", "corrigida", car=True, edits=[
        (NOT, "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ?\",", "where estado = 'PENDENTE' and proxima_tentativa_em <= ? order by id limit ? for update\",")]),
    Caso("BUG-011.b", "bug011b-atraso-aleatorio", "mutante", "nao_corrigida", edits=[
        (DESP, "    public int executar() {", "    public int executar() {\n        try {\n            Thread.sleep((long) (Math.random() * 50));\n        } catch (InterruptedException e) {\n            Thread.currentThread().interrupt();\n        }")]),
    Caso("BUG-011.b", "bug011b-trava-na-jvm", "mutante", "nao_corrigida", nota="serializes inside one JVM: passes with threads, fails with two independent JVMs", edits=[
        (DESP, "    public int executar() {", "    public int executar() {\n        synchronized (DespachoJob.class) {\n            return executarSemTrava();\n        }\n    }\n\n    private int executarSemTrava() {")]),

    # ---- BUG-014
    Caso("BUG-014", "bug014-ponto-de-retomada-na-mesma-transacao", "alternativa", "corrigida", car=True, edits=[(JOB, LANCAR_LOTE, '''    private int lancarLote(Instant agora) {
        return db.tx(tx -> {
            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);
            List<Ordem> lote = ordens.semLancamento(tx, LOTE);
            if (!lote.isEmpty()) ordens.gravarPontoDeRetomada(tx, lote.get(lote.size() - 1).id());
            return lancar(tx, lote, agora);
        });
    }
''')]),
    Caso("BUG-014", "bug014-ponto-de-retomada-depois-em-outra-transacao", "mutante", "nao_corrigida", edits=[(JOB, LANCAR_LOTE, '''    private int lancarLote(Instant agora) {
        List<Ordem> lote = db.tx(tx -> {
            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);
            return ordens.semLancamento(tx, LOTE);
        });
        int feitos = db.tx(tx -> lancar(tx, lote, agora));
        if (!lote.isEmpty()) db.tx(tx -> {
            ordens.gravarPontoDeRetomada(tx, lote.get(lote.size() - 1).id());
            return 0;
        });
        return feitos;
    }
''')]),
    Caso("BUG-014", "bug014-lote-de-um", "mutante", "nao_corrigida", edits=[(JOB, "private static final int LOTE = 100;", "private static final int LOTE = 1;")]),
    Caso("BUG-014", "bug014-engole-a-falha", "mutante", "nao_corrigida", edits=[(JOB, "            doLote = lancarLote(agora);", "            try {\n                doLote = lancarLote(agora);\n            } catch (RuntimeException e) {\n                doLote = 0;\n            }")]),

    # ---- BUG-015
    Caso("BUG-015", "bug015-guarda-a-causa", "alternativa", "corrigida", car=True, edits=[("db/Db.java", "super(causa.getMessage());", "super(causa.getMessage(), causa);")]),
    Caso("BUG-015", "bug015-registra-so-a-mensagem", "mutante", "nao_corrigida", edits=[
        ("http/Servidor.java", '            e.printStackTrace();\n            resp = erro(500, "erro_interno", "erro interno");',
         '            System.err.println("erro interno: " + e.getMessage());\n            resp = erro(500, "erro_interno", "erro interno");')]),
    Caso("BUG-015", "bug015-devolve-o-motivo-ao-cliente", "mutante", "nao_corrigida", edits=[
        ("db/Db.java", "super(causa.getMessage());", "super(causa.getMessage(), causa);"),
        ("http/Servidor.java", 'resp = erro(500, "erro_interno", "erro interno");', 'resp = erro(500, "erro_interno", String.valueOf(e));')]),
]
