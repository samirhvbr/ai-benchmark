"""Control cases added so that every verdict has at least one wrong fix that must fail (ARCH-011, PERF-011)."""
from motor import Caso

CSV = "relatorio/CsvExportador.java"
ESC = "job/EscalonamentoJob.java"
REPO = "repo/ChamadoRepo.java"


def jdbc_numa_classe_interna(texto):
    """Cosmetic: the JDBC code of the exporter moves into an inner class of the same top-level class."""
    abre = "    public String gerar(Tx tx, long empresaId, Instant de, Instant ate, ZoneId zona) {"
    assert texto.count(abre) == 1
    texto = texto.replace(abre, "    public String gerar(Tx tx, long empresaId, Instant de, Instant ate, ZoneId zona) {\n"
                                "        return new Consulta().gerar(tx, empresaId, de, ate, zona);\n    }\n\n"
                                "    private final class Consulta {\n"
                                "        String gerar(Tx tx, long empresaId, Instant de, Instant ate, ZoneId zona) {")
    marca = "    private static String data(Instant t, ZoneId zona) {"
    assert texto.count(marca) == 1
    return texto.replace(marca, "    }\n\n" + marca)


CASOS = [
    Caso("ARCH-011", "arch011-jdbc-numa-classe-interna", "mutante", "nao_corrigida", edits=[(CSV, jdbc_numa_classe_interna)]),
    Caso("PERF-011", "perf011-projecao-que-ainda-traz-a-descricao", "mutante", "nao_corrigida", edits=[
        (ESC, '        List<Chamado> candidatos = db.tx(chamados::emAndamento);\n        List<Long> devidos = new ArrayList<>();\n        for (Chamado c : candidatos) {',
         '        List<br.netx.chamados.repo.ChamadoRepo.Candidato> candidatos = db.tx(chamados::candidatosAoEscalonamento);\n        List<Long> devidos = new ArrayList<>();\n        for (br.netx.chamados.repo.ChamadoRepo.Candidato c : candidatos) {'),
        (REPO, "select id, aberto_em, prazo_resolucao from chamado where status in", "select id, aberto_em, prazo_resolucao, descricao from chamado where status in")]),
]


# ---- PERF-011: the three situations of a failing memory pass. The pass runs on the projection fix (so memory is fine) and fails for another reason on the large set.
PROJECAO = [
    (ESC, '        List<Chamado> candidatos = db.tx(chamados::emAndamento);\n        List<Long> devidos = new ArrayList<>();\n        for (Chamado c : candidatos) {',
     '        List<br.netx.chamados.repo.ChamadoRepo.Candidato> candidatos = db.tx(chamados::candidatosAoEscalonamento);\n        List<Long> devidos = new ArrayList<>();\n        for (br.netx.chamados.repo.ChamadoRepo.Candidato c : candidatos) {'),
]


def falha_com_muitos(instrucao):
    return (ESC, "        List<Long> devidos = new ArrayList<>();\n", "        List<Long> devidos = new ArrayList<>();\n        if (candidatos.size() > 15000) { " + instrucao + " }\n")


CASOS += [
    Caso("PERF-011", "perf011-origem-nao-estabelecida", "controle", "inconclusivo", edits=PROJECAO + [falha_com_muitos('throw new IllegalStateException("falha sintética do código avaliado");')],
         nota="the pass fails on the large set with an exception that is not memory: the origin is not established, so no verdict"),
    Caso("PERF-011", "perf011-filho-morto-sem-relato", "controle", "inconclusivo", edits=PROJECAO + [falha_com_muitos("Runtime.getRuntime().halt(137);")],
         nota="the child process dies without reporting anything: evaluator or environment, no verdict"),
]
