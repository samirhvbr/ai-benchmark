"""Control variants for ARCH-001 (the god class): a real split built differently from the reference, and two cosmetic ones."""
import re
from motor import Caso, INST

CS = "servico/ChamadoService.java"
CORTE = "    private static final Duration VALIDADE = Duration.ofHours(24);"


def corta_o_fim(texto):
    return texto[:texto.index(CORTE)].rstrip() + "\n}\n"


def troca_todas(velho, novo):
    return lambda t: t.replace(velho, novo)


def heranca_servico(texto):
    """Cosmetic split by inheritance: the fields, the constructor, the idempotency and the SLA code go to a base class the god class extends."""
    cabecalho, resto = texto.split("/** Chamados, ordens de serviço, notificações, chaves de idempotência e prazos de SLA. */\npublic final class ChamadoService {\n", 1)
    campos, resto = resto.split("\n    public ChamadoService(", 1)
    ctor, corpo = resto.split("\n    }\n", 1)
    meio, cauda = corpo.split(CORTE, 1)
    params = ctor.split(") {", 1)[0]
    atribuicoes = ctor.split(") {", 1)[1]
    nomes = ", ".join(p.split()[-1] for p in params.replace("\n", " ").split(","))
    base = cabecalho + "/** Estado e regras herdados pelo serviço de chamados. */\npublic abstract class ServicoBase {\n" + campos.replace("private final", "protected final") \
        + "\n    protected ServicoBase(" + params + ") {" + atribuicoes + "\n    }\n\n" + CORTE + cauda.rstrip()
    if not base.rstrip().endswith("}"):
        base += "\n"
    novo = cabecalho + "/** Chamados, ordens de serviço, notificações, chaves de idempotência e prazos de SLA. */\npublic final class ChamadoService extends ServicoBase {\n" \
        + "    public ChamadoService(" + params + ") {\n        super(" + nomes + ");\n    }\n" + meio.rstrip() + "\n}\n"
    return novo, base


LEGADO_CS = (INST / "build/legado/src/main/java/br/netx/chamados/servico/ChamadoService.java").read_text(encoding="utf-8")
NOVO_CS, BASE_CS = heranca_servico(LEGADO_CS)

def compacta(texto):
    """Reformat only: drop every comment and blank line and join the lines of the class body. The line count collapses; nothing is separated."""
    marca = "public final class ChamadoService {"
    cabeca, corpo = texto.split(marca, 1)
    corpo = re.sub(r"/\*.*?\*/", "", corpo, flags=re.S)
    corpo = re.sub(r"(?m)^\s*//.*$", "", corpo)
    corpo = re.sub(r"\s*\n\s*", " ", corpo).strip()
    return cabeca + marca + "\n    " + corpo + "\n"


def expande(texto):
    """Reformat the other way (Allman braces: every opening brace on its own line). The class gets longer; nothing is joined or separated."""
    saida, i, n, prof = [], 0, len(texto), 0
    while i < n:
        c = texto[i]
        if texto.startswith("//", i):
            j = texto.find("\n", i); j = n if j < 0 else j
            saida.append(texto[i:j]); i = j; continue
        if texto.startswith("/*", i):
            j = texto.index("*/", i) + 2
            saida.append(texto[i:j]); i = j; continue
        if c in "\"'":
            j = i + 1
            while texto[j] != c:
                j += 2 if texto[j] == "\\" else 1
            saida.append(texto[i:j + 1]); i = j + 1; continue
        if c == "(":
            prof += 1
        elif c == ")":
            prof -= 1
        if prof == 0 and c == "{":
            saida.append("\n")
        saida.append(c)
        if prof == 0 and c in ";{}":
            saida.append("\n")
        i += 1
    return "".join(saida)


def quebra_argumentos(texto):
    """Long, balanced lines get one argument per line (a common style). Only whitespace changes."""
    saida = []
    for linha in texto.split("\n"):
        if len(linha) <= 70 or linha.lstrip().startswith(("//", "*", "/*")):
            saida.append(linha); continue
        prof, i, aberto, partes, ini, ok = 0, 0, 0, [], 0, True
        recuo = linha[:len(linha) - len(linha.lstrip())] + "        "
        while i < len(linha):
            c = linha[i]
            if c in "\"'":
                j = i + 1
                while j < len(linha) and linha[j] != c:
                    j += 2 if linha[j] == "\\" else 1
                i = j + 1; continue
            if c == "(":
                prof += 1; aberto += 1
            elif c == ")":
                prof -= 1
            elif c == "," and prof == 1:
                partes.append(linha[ini:i + 1]); ini = i + 1
            i += 1
        if prof != 0 or len(partes) < 2:
            saida.append(linha); continue
        partes.append(linha[ini:])
        saida.append(partes[0])
        saida.extend(recuo + p.strip() for p in partes[1:])
    return "\n".join(saida)


def expande_tudo(texto):
    return quebra_argumentos(expande(texto))


IDEMP_FIM = "    /** Padrões do manifesto, seção 3.4, para a prioridade sem linha em sla_politica. */"


def corta_so_a_idempotencia(texto):
    ini = texto.index(CORTE)
    fim = texto.index(IDEMP_FIM)
    return texto[:ini] + texto[fim:]


CASOS = [
    Caso("ARCH-001", "arch001-reformata-e-encurta-sem-separar", "mutante", "nao_corrigida", edits=[(CS, compacta)]),
    Caso("ARCH-001", "arch001-classe-coesa-acima-do-limiar-antigo", "alternativa", "corrigida", base="referencia", car=True, edits=[("http/CadastroHandlers.java", expande_tudo)]),
    Caso("ARCH-001", "arch001-extrai-so-a-idempotencia", "alternativa", "corrigida", car=True, de_referencia=["servico/Idempotencia.java"], edits=[
        (CS, corta_so_a_idempotencia),
        (CS, "NotificacaoRepo notificacaoRepo,\n                          IdempotenciaRepo idempotenciaRepo, Db db, Clock clock)", "NotificacaoRepo notificacaoRepo,\n                          Db db, Clock clock)"),
        (CS, "    private final IdempotenciaRepo idempotenciaRepo;\n", ""),
        (CS, "        this.idempotenciaRepo = idempotenciaRepo;\n", ""),
        ("http/ChamadoHandlers.java", "import br.netx.chamados.servico.ChamadoService.Resultado;\n", "import br.netx.chamados.servico.Idempotencia;\nimport br.netx.chamados.servico.Idempotencia.Resultado;\n"),
        ("http/ChamadoHandlers.java", "    private final ChamadoService idempotencia;", "    private final Idempotencia idempotencia;"),
        ("http/ChamadoHandlers.java", "ChamadoService idempotencia, ChamadoService notificacoes)", "Idempotencia idempotencia, ChamadoService notificacoes)"),
        ("App.java", "import br.netx.chamados.servico.ChamadoService;\n", "import br.netx.chamados.servico.ChamadoService;\nimport br.netx.chamados.servico.Idempotencia;\n"),
        ("App.java", "        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, new IdempotenciaRepo(), db, clock);\n",
         "        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, db, clock);\n        Idempotencia idempotencia = new Idempotencia(db, new IdempotenciaRepo(), clock);\n"),
        ("App.java", "new ChamadoHandlers(db, chamados, chamados, chamados, chamados)", "new ChamadoHandlers(db, chamados, chamados, idempotencia, chamados)")]),

    Caso("ARCH-001", "arch001-divide-por-heranca", "mutante", "nao_corrigida", novos={"src/main/java/br/netx/chamados/servico/ServicoBase.java": BASE_CS}, edits=[
        (CS, lambda _t: NOVO_CS),
        ("http/ChamadoHandlers.java", "import br.netx.chamados.servico.ChamadoService.Resultado;", "import br.netx.chamados.servico.ServicoBase.Resultado;"),
        ("job/EscalonamentoJob.java", "import br.netx.chamados.servico.ChamadoService.Prazos;", "import br.netx.chamados.servico.ServicoBase.Prazos;")]),
    Caso("ARCH-001", "arch001-extrai-sla-e-idempotencia", "alternativa", "corrigida", car=True, de_referencia=["servico/SlaService.java", "servico/Idempotencia.java"], edits=[
        (CS, corta_o_fim),
        (CS, "    private final IdempotenciaRepo idempotenciaRepo;\n", "    private final SlaService sla;\n"),
        (CS, "IdempotenciaRepo idempotenciaRepo, Db db, Clock clock) {", "SlaService sla, Db db, Clock clock) {"),
        (CS, "        this.idempotenciaRepo = idempotenciaRepo;\n", "        this.sla = sla;\n"),
        (CS, troca_todas("Prazos prazos = prazos(", "Prazos prazos = sla.prazos(")),
        (CS, "? cumprido(c) : null", "? sla.cumprido(c) : null"),
        (CS, "!agora.isBefore(limiteDeEscalonamento(", "!agora.isBefore(sla.limiteDeEscalonamento("),
        (CS, "package br.netx.chamados.servico;\n", "package br.netx.chamados.servico;\n\nimport br.netx.chamados.servico.SlaService.Prazos;\n"),
        ("http/ChamadoHandlers.java", "import br.netx.chamados.servico.ChamadoService.Resultado;\n", "import br.netx.chamados.servico.Idempotencia;\nimport br.netx.chamados.servico.Idempotencia.Resultado;\n"),
        ("http/ChamadoHandlers.java", "    private final ChamadoService idempotencia;", "    private final Idempotencia idempotencia;"),
        ("http/ChamadoHandlers.java", "ChamadoService idempotencia, ChamadoService notificacoes)", "Idempotencia idempotencia, ChamadoService notificacoes)"),
        ("job/EscalonamentoJob.java", "import br.netx.chamados.servico.ChamadoService;\nimport br.netx.chamados.servico.ChamadoService.Prazos;",
         "import br.netx.chamados.servico.ChamadoService;\nimport br.netx.chamados.servico.SlaService;\nimport br.netx.chamados.servico.SlaService.Prazos;"),
        ("job/EscalonamentoJob.java", "    private final ChamadoService sla;", "    private final SlaService sla;"),
        ("job/EscalonamentoJob.java", "ChamadoService sla, ChamadoService notificacoes, Clock clock)", "SlaService sla, ChamadoService notificacoes, Clock clock)"),
        ("relatorio/RelatorioService.java", "import br.netx.chamados.servico.ChamadoService;", "import br.netx.chamados.servico.SlaService;"),
        ("relatorio/RelatorioService.java", "    private final ChamadoService sla;", "    private final SlaService sla;"),
        ("relatorio/RelatorioService.java", "ChamadoService sla, CsvExportador csv", "SlaService sla, CsvExportador csv"),
        ("relatorio/CsvExportador.java", "import br.netx.chamados.servico.ChamadoService;", "import br.netx.chamados.servico.SlaService;"),
        ("relatorio/CsvExportador.java", "    private final ChamadoService sla;", "    private final SlaService sla;"),
        ("relatorio/CsvExportador.java", "public CsvExportador(ChamadoService sla)", "public CsvExportador(SlaService sla)"),
        ("App.java", "import br.netx.chamados.servico.ChamadoService;\n", "import br.netx.chamados.servico.ChamadoService;\nimport br.netx.chamados.servico.Idempotencia;\nimport br.netx.chamados.servico.SlaService;\n"),
        ("App.java", "        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, new IdempotenciaRepo(), db, clock);\n",
         "        SlaService sla = new SlaService();\n        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, sla, db, clock);\n        Idempotencia idempotencia = new Idempotencia(db, new IdempotenciaRepo(), clock);\n"),
        ("App.java", "ordensRepo, chamados, new CsvExportador(chamados), clock)", "ordensRepo, sla, new CsvExportador(sla), clock)"),
        ("App.java", "new ChamadoHandlers(db, chamados, chamados, chamados, chamados)", "new ChamadoHandlers(db, chamados, chamados, idempotencia, chamados)"),
        ("App.java", "new EscalonamentoJob(db, chamadosRepo, cadastros, chamados, chamados, clock)", "new EscalonamentoJob(db, chamadosRepo, cadastros, sla, chamados, clock)")]),
]
