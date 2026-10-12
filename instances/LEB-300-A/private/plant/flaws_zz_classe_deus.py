"""ARCH-001: five cohesive services fused into one class. Done mechanically, after every other edit, so no behavior changes."""
import re
from plantar import SRC

NOMES = ["OrdemService", "NotificacaoService", "Idempotencia", "SlaService"]
ALVO = "ChamadoService"
CAM = SRC + "servico/"

FLAWS = [("ARCH-001", [])]


def _fim_do_bloco(texto, abre):
    """Índice da chave que fecha a que abre em `abre`, ignorando strings, caracteres e comentários."""
    prof, i = 0, abre
    while i < len(texto):
        if texto.startswith("//", i):
            i = texto.index("\n", i); continue
        if texto.startswith("/*", i):
            i = texto.index("*/", i) + 2; continue
        c = texto[i]
        if c in "\"'":
            i += 1
            while texto[i] != c:
                i += 2 if texto[i] == "\\" else 1
            i += 1; continue
        if c == "{":
            prof += 1
        elif c == "}":
            prof -= 1
            if prof == 0:
                return i
        i += 1
    raise AssertionError("chave não fechada")


def _partes(texto, nome):
    ini = texto.index("public final class " + nome)
    abre = texto.index("{", ini)
    fim = _fim_do_bloco(texto, abre)
    imports = re.findall(r"^import [^\n]+;$", texto, re.M)
    return imports, texto[abre + 1:fim]


def _sem_campos_e_construtor(corpo, nome):
    corpo = re.sub(r"^    private final \w+(?:<[^>]*>)? \w+;\n", "", corpo, flags=re.M)
    corpo = re.sub(r"    public %s\([^)]*\) \{\n(?:        [^\n]*\n)*    \}\n\n?" % nome, "", corpo)
    return corpo.strip("\n")


def _uma_vez(texto, velho, novo, onde):
    assert texto.count(velho) == 1, "%s: %d ocorrências de %r" % (onde, texto.count(velho), velho[:70])
    return texto.replace(velho, novo, 1)


def fundir(arquivos, ler):
    classes = {n: ler(CAM + n + ".java") for n in [ALVO] + NOMES}
    imports, corpos = set(), {}
    for nome, texto in classes.items():
        imps, corpo = _partes(texto, nome)
        imports |= {i for i in imps if not i.startswith("import br.netx.chamados.servico.")}
        corpos[nome] = _sem_campos_e_construtor(corpo, nome)

    c = corpos[ALVO]
    c = re.sub(r"\bsla\.", "", c)
    c = re.sub(r"\bnotificacoes\.", "", c)
    o = corpos["OrdemService"]
    o = re.sub(r"\bnotificacoes\.", "", o)
    for velho, novo in [("Ordem criar(", "Ordem criarOrdem("), ("Pagina<Ordem> listar(", "Pagina<Ordem> listarOrdens("),
                        ("Ordem detalhar(", "Ordem detalharOrdem("), ("Ordem iniciar(", "Ordem iniciarOrdem("),
                        ("Ordem concluir(", "Ordem concluirOrdem("), ("Ordem cancelar(", "Ordem cancelarOrdem("),
                        ("Ordem reagendar(", "Ordem reagendarOrdem(")]:
        o = _uma_vez(o, velho, novo, "OrdemService")
    n = re.sub(r"\brepo\.", "notificacaoRepo.", corpos["NotificacaoService"])
    i = re.sub(r"\brepo\.", "idempotenciaRepo.", corpos["Idempotencia"])
    s = corpos["SlaService"]

    imports |= {"import br.netx.chamados.db.Db;", "import br.netx.chamados.repo.ChamadoRepo;", "import br.netx.chamados.repo.CadastroRepo;",
                "import br.netx.chamados.repo.OrdemRepo;", "import br.netx.chamados.repo.NotificacaoRepo;", "import br.netx.chamados.repo.IdempotenciaRepo;",
                "import java.time.Clock;"}
    cab = "package br.netx.chamados.servico;\n\n" + "\n".join(sorted(imports)) + "\n\n"
    cab += "/** Chamados, ordens de serviço, notificações, chaves de idempotência e prazos de SLA. */\npublic final class ChamadoService {\n"
    cab += ("    private final ChamadoRepo chamados;\n    private final CadastroRepo cadastros;\n    private final OrdemRepo ordens;\n"
            "    private final NotificacaoRepo notificacaoRepo;\n    private final IdempotenciaRepo idempotenciaRepo;\n    private final Db db;\n"
            "    private final Clock clock;\n\n"
            "    public ChamadoService(ChamadoRepo chamados, CadastroRepo cadastros, OrdemRepo ordens, NotificacaoRepo notificacaoRepo,\n"
            "                          IdempotenciaRepo idempotenciaRepo, Db db, Clock clock) {\n"
            "        this.chamados = chamados;\n        this.cadastros = cadastros;\n        this.ordens = ordens;\n"
            "        this.notificacaoRepo = notificacaoRepo;\n        this.idempotenciaRepo = idempotenciaRepo;\n        this.db = db;\n        this.clock = clock;\n    }\n\n")
    arquivos[CAM + ALVO + ".java"] = cab + "\n\n".join([c, o, n, i, s]) + "\n}\n"
    for nome in NOMES:
        arquivos[CAM + nome + ".java"] = None

    # referências nos outros arquivos
    def editar(caminho, trocas):
        t = ler(caminho)
        for velho, novo in trocas:
            t = _uma_vez(t, velho, novo, caminho)
        arquivos[caminho] = t

    editar(SRC + "http/ChamadoHandlers.java", [
        ("import br.netx.chamados.servico.Idempotencia;\nimport br.netx.chamados.servico.Idempotencia.Resultado;\n", "import br.netx.chamados.servico.ChamadoService.Resultado;\n"),
        ("import br.netx.chamados.servico.OrdemService;\n", ""),
        ("import br.netx.chamados.servico.NotificacaoService;\n", ""),
        ("    private final OrdemService ordens;\n    private final Idempotencia idempotencia;\n    private final NotificacaoService notificacoes;",
         "    private final ChamadoService ordens;\n    private final ChamadoService idempotencia;\n    private final ChamadoService notificacoes;"),
        ("ChamadoService chamados, OrdemService ordens, Idempotencia idempotencia, NotificacaoService notificacoes)",
         "ChamadoService chamados, ChamadoService ordens, ChamadoService idempotencia, ChamadoService notificacoes)"),
        ("ordens.ordensDoChamado(", "ordens.ordensDoChamado("), ("ordens.reagendar(", "ordens.reagendarOrdem("),
        ("ordens.criar(", "ordens.criarOrdem("), ("ordens.listar(", "ordens.listarOrdens("), ("ordens.detalhar(", "ordens.detalharOrdem("),
        ("ordens.iniciar(", "ordens.iniciarOrdem("), ("ordens.concluir(", "ordens.concluirOrdem("), ("ordens.cancelar(", "ordens.cancelarOrdem("),
    ])
    editar(SRC + "relatorio/RelatorioService.java", [
        ("import br.netx.chamados.servico.SlaService;", "import br.netx.chamados.servico.ChamadoService;"),
        ("    private final SlaService sla;", "    private final ChamadoService sla;"),
        ("SlaService sla, CsvExportador csv", "ChamadoService sla, CsvExportador csv"),
    ])
    editar(SRC + "relatorio/CsvExportador.java", [
        ("import br.netx.chamados.servico.SlaService;", "import br.netx.chamados.servico.ChamadoService;"),
        ("    private final SlaService sla;", "    private final ChamadoService sla;"),
        ("public CsvExportador(SlaService sla)", "public CsvExportador(ChamadoService sla)"),
    ])
    editar(SRC + "job/EscalonamentoJob.java", [
        ("import br.netx.chamados.servico.NotificacaoService;\nimport br.netx.chamados.servico.SlaService;\nimport br.netx.chamados.servico.SlaService.Prazos;",
         "import br.netx.chamados.servico.ChamadoService;\nimport br.netx.chamados.servico.ChamadoService.Prazos;"),
        ("    private final SlaService sla;\n    private final NotificacaoService notificacoes;", "    private final ChamadoService sla;\n    private final ChamadoService notificacoes;"),
        ("SlaService sla, NotificacaoService notificacoes, Clock clock)", "ChamadoService sla, ChamadoService notificacoes, Clock clock)"),
    ])
    editar(SRC + "repo/NotificacaoRepo.java", [
        ("import br.netx.chamados.servico.NotificacaoService;", "import br.netx.chamados.servico.ChamadoService;"),
        ("NotificacaoService.chave(", "ChamadoService.chave("),
    ])
    editar(SRC + "App.java", [
        ("import br.netx.chamados.servico.Idempotencia;\n", ""),
        ("import br.netx.chamados.servico.NotificacaoService;\n", ""),
        ("import br.netx.chamados.servico.OrdemService;\n", ""),
        ("import br.netx.chamados.servico.SlaService;\n", ""),
        ("        SlaService sla = new SlaService();\n        NotificacaoService notificacoes = new NotificacaoService(notificacaoRepo, clock);\n", ""),
        ("        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, sla, notificacoes, clock);\n"
         "        OrdemService ordens = new OrdemService(ordensRepo, chamadosRepo, cadastros, notificacoes, clock);\n"
         "        Idempotencia idempotencia = new Idempotencia(db, new IdempotenciaRepo(), clock);\n",
         "        ChamadoService chamados = new ChamadoService(chamadosRepo, cadastros, ordensRepo, notificacaoRepo, new IdempotenciaRepo(), db, clock);\n"),
        ("ordensRepo, sla, new CsvExportador(sla), clock)", "ordensRepo, chamados, new CsvExportador(chamados), clock)"),
        ("new ChamadoHandlers(db, chamados, ordens, idempotencia, notificacoes)", "new ChamadoHandlers(db, chamados, chamados, chamados, chamados)"),
        ("new EscalonamentoJob(db, chamadosRepo, cadastros, sla, notificacoes, clock)", "new EscalonamentoJob(db, chamadosRepo, cadastros, chamados, chamados, clock)"),
    ])
    for caminho, texto in arquivos.items():
        if texto is None or caminho.endswith("ChamadoService.java"):
            continue
        for nome in NOMES:
            assert not re.search(r"\b%s\b" % nome, texto), "%s ainda cita %s" % (caminho, nome)


POS = [("ARCH-001", fundir)]
