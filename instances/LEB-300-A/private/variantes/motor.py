#!/usr/bin/env python3
"""Runs the control variants against the verifiers: alternative fixes must be accepted, partial or wrong fixes rejected.
   usage: motor.py [--so ID_OU_NOME ...] [--car] [--lista]
A case is the legacy (or the reference) with a few exact-match edits. The targeted verifier runs on it and its verdict is compared with
what the case is supposed to get. --car also runs the characterization (public tests + decoy invariants) on accepted alternatives."""
import concurrent.futures, importlib, json, os, pathlib, shutil, subprocess, sys, tempfile, threading
from dataclasses import dataclass, field

AQUI = pathlib.Path(__file__).resolve().parent
INST = AQUI.parent
OPER = INST.parent.parent.parent / "operador"
sys.path.insert(0, str(AQUI))
import mapa  # noqa: E402

SRC = "src/main/java/br/netx/chamados/"
SENSIVEIS_AO_TEMPO = {"PERF-011", "PERF-012"}


@dataclass
class Caso:
    alvo: str                    # matrix id whose verifier judges the case
    nome: str
    tipo: str                    # alternativa | mutante
    esperado: str                # corrigida | nao_corrigida | half
    edits: list = field(default_factory=list)      # (path under br/netx/chamados/, old, new) exact, once; or (path, callable)
    novos: dict = field(default_factory=dict)      # path under the tree -> content (new files)
    remover: list = field(default_factory=list)    # paths under br/netx/chamados/ to delete
    de_referencia: list = field(default_factory=list)   # paths under br/netx/chamados/ replaced by the reference file
    base: str = "legado"
    car: bool = False
    nota: str = ""


def carregar():
    casos = []
    so = os.environ.get("LEB_CASOS", "")
    for arq in sorted(AQUI.glob("casos_*.py")):
        if so and not any(arq.stem.startswith("casos_" + x) for x in so.split(",")):
            continue
        casos += importlib.import_module(arq.stem).CASOS
    return casos


def montar(caso, destino):
    base = INST / ("build/legado" if caso.base == "legado" else "reference")
    shutil.copytree(base / "src" / "main", destino / "src" / "main")
    if (base / "migrations").is_dir():
        shutil.copytree(base / "migrations", destino / "migrations")
    for edit in caso.edits:
        arq = destino / SRC / edit[0]
        texto = arq.read_text(encoding="utf-8")
        if callable(edit[1]):
            novo = edit[1](texto)
        else:
            if texto.count(edit[1]) != 1:
                raise SystemExit(f"[{caso.nome}] {edit[0]}: expected exactly one match, found {texto.count(edit[1])} of {edit[1][:70]!r}")
            novo = texto.replace(edit[1], edit[2])
        arq.write_text(novo, encoding="utf-8")
    for p in caso.de_referencia:
        shutil.copyfile(INST / "reference" / SRC / p, destino / SRC / p)
    for p in caso.remover:
        (destino / SRC / p).unlink()
    for p, conteudo in caso.novos.items():
        alvo = destino / p
        alvo.parent.mkdir(parents=True, exist_ok=True)
        alvo.write_text(conteudo, encoding="utf-8")


def veredito(linhas, alvo):
    for l in linhas:
        if l.startswith("{"):
            d = json.loads(l)
            if d["id"] == alvo or (alvo in ("SEC-001.b", "SEC-014.b", "ARCH-014.b", "PERF-015", "ARCH-006") and d["id"] in mapa.DECOY_IDS):
                if d.get("inconclusivo"):
                    return "inconclusivo", d.get("msg", "")
                if "isca_passa" in d:
                    return ("corrigida" if d["isca_passa"] else "nao_corrigida"), d.get("msg", "")
                if "corrigida" in d:
                    return ("corrigida" if d["corrigida"] else "nao_corrigida"), d.get("msg", "")
                if "result" in d:
                    return {"full": "corrigida", "half": "half", "none": "nao_corrigida"}[d["result"]], d.get("msg", "")
    return "sem veredito", ""


def rodar(caso, com_car):
    verif = mapa.verificadores()[caso.alvo]
    seletor = f"{verif[0]}#{verif[1]}"
    with tempfile.TemporaryDirectory(prefix="leb-var.") as tmp:
        tree = pathlib.Path(tmp) / "tree"
        montar(caso, tree)
        r = subprocess.run([str(OPER / "verificar.sh"), str(tree), seletor], capture_output=True, text=True)
        obs, msg = veredito(r.stdout.splitlines(), caso.alvo)
        car = ""
        if (com_car or caso.car) and caso.tipo == "alternativa":
            c = subprocess.run([str(OPER / "avaliar.sh"), "caracterizar", str(tree)], capture_output=True, text=True)
            try:
                d = json.loads(c.stdout)
                car = f"{d['passed']} ok / {d['failed']} falhas"
            except ValueError:
                car = "ilegível"
        shutil.rmtree(tree, ignore_errors=True)
    return obs, msg, car


def main():
    args = sys.argv[1:]
    so = []
    if "--so" in args:
        so = args[args.index("--so") + 1:]
        so = [a for a in so if not a.startswith("--")]
    casos = [c for c in carregar() if not so or c.alvo in so or c.nome in so]
    if "--lista" in args:
        for c in casos:
            print(f"{c.alvo:11} {c.tipo:10} {c.esperado:13} {c.nome}")
        return
    saida = AQUI / "resultados.tsv"
    maus = [0]
    trava = threading.Lock()
    com_car = "--car" in args

    def um(c, f):
        obs, msg, car = rodar(c, com_car)
        ok = obs == c.esperado
        with trava:
            if not ok:
                maus[0] += 1
            print(f"{'ok ' if ok else 'XX '} {c.alvo:11} {c.tipo:10} esperado={c.esperado:13} observado={obs:13} {c.nome}" + (f"  [{car}]" if car else ""), flush=True)
            if not ok:
                print(f"     {msg[:300]}", flush=True)
            f.write("\t".join([c.alvo, c.tipo, c.nome, c.esperado, obs, "ok" if ok else "XX", car, msg.replace("\t", " ")]) + "\n")
            f.flush()

    n = int(args[args.index("--paralelo") + 1]) if "--paralelo" in args else 1
    sensiveis = [c for c in casos if c.alvo in SENSIVEIS_AO_TEMPO]      # medem tempo de processador ou memória: nunca em paralelo
    comuns = [c for c in casos if c.alvo not in SENSIVEIS_AO_TEMPO]
    with open(saida, "a", encoding="utf-8") as f:
        with concurrent.futures.ThreadPoolExecutor(max_workers=n) as pool:
            for _ in pool.map(lambda c: um(c, f), comuns):
                pass
        for c in sensiveis:
            um(c, f)
    print(f"{len(casos)} cases, {maus[0]} unexpected")
    sys.exit(1 if maus[0] else 0)


if __name__ == "__main__":
    main()
