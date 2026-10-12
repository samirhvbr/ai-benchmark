#!/usr/bin/env python3
"""Plants the flaws of LEB-300-A on a copy of the reference application.

The reference is the correct state. Every flaw is a list of exact edits (path, old text, new text); each `old` must occur exactly once in the
file as it is after the earlier edits, or the run stops. Output is the legacy tree: what the candidate receives, before packaging.

usage: plantar.py REFERENCE_DIR OUT_DIR [--only ID,ID] [--check]
  --check   verify every edit applies cleanly and write nothing
"""
import importlib.util, os, shutil, sys, glob

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = "src/main/java/br/netx/chamados/"


def carregar():
    flaws, ordem = {}, []
    carregar.pos = []
    for caminho in sorted(glob.glob(os.path.join(HERE, "flaws_*.py"))):
        spec = importlib.util.spec_from_file_location(os.path.basename(caminho)[:-3], caminho)
        m = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(m)
        carregar.pos.extend(getattr(m, "POS", []))
        for fid, edits in m.FLAWS:
            if fid in flaws:
                sys.exit("falha repetida: " + fid)
            flaws[fid] = edits
            ordem.append(fid)
    return flaws, ordem


def aplicar(raiz, flaws, ordem, apenas=None):
    erros = []
    arquivos = {}
    for fid in ordem:
        if apenas and fid not in apenas:
            continue
        for edicao in flaws[fid]:
            caminho, velho, novo = edicao[0], edicao[1], edicao[2]
            alvo = os.path.join(raiz, caminho)
            if velho is None:                                   # create a new file
                if os.path.exists(alvo) or caminho in arquivos:
                    erros.append("%s: %s já existe" % (fid, caminho)); continue
                arquivos[caminho] = novo
                continue
            if caminho not in arquivos:
                if not os.path.exists(alvo):
                    erros.append("%s: arquivo ausente %s" % (fid, caminho)); continue
                arquivos[caminho] = open(alvo, encoding="utf-8").read()
            if velho == "*":                                    # replace the whole file
                arquivos[caminho] = novo
                continue
            if novo is None:                                    # delete the file
                arquivos[caminho] = None
                continue
            texto = arquivos[caminho]
            if texto is None:
                erros.append("%s: %s foi removido antes" % (fid, caminho)); continue
            n = texto.count(velho)
            if n != 1:
                erros.append("%s: %d ocorrência(s) em %s de: %r" % (fid, n, caminho, velho[:90]))
                continue
            arquivos[caminho] = texto.replace(velho, novo, 1)
    return arquivos, erros


def main():
    args = sys.argv[1:]
    check = "--check" in args
    apenas = None
    if "--only" in args:
        apenas = set(args[args.index("--only") + 1].split(","))
    pos = [a for i, a in enumerate(args) if not a.startswith("--") and (i == 0 or args[i - 1] != "--only")]
    ref = pos[0]
    flaws, ordem = carregar()
    arquivos, erros = aplicar(ref, flaws, ordem, apenas)
    if erros:
        print("\n".join(erros)); sys.exit(1)

    def ler(caminho):
        if caminho not in arquivos:
            arquivos[caminho] = open(os.path.join(ref, caminho), encoding="utf-8").read()
        return arquivos[caminho]

    for fid, funcao in carregar.pos:
        if apenas and fid not in apenas:
            continue
        try:
            funcao(arquivos, ler)
        except AssertionError as e:
            print("%s: %s" % (fid, e)); sys.exit(1)
    print("%d falhas, %d arquivos tocados, todas as edições casam exatamente uma vez" % (len(apenas or ordem), len(arquivos)))
    if check:
        return
    out = pos[1]
    if os.path.exists(out):
        sys.exit("já existe: " + out)
    shutil.copytree(ref, out, ignore=shutil.ignore_patterns("target", ".git"))
    for caminho, texto in arquivos.items():
        alvo = os.path.join(out, caminho)
        if texto is None:
            os.remove(alvo)
        else:
            os.makedirs(os.path.dirname(alvo), exist_ok=True)
            with open(alvo, "w", encoding="utf-8") as f:
                f.write(texto)
    print("escrito em", out)


if __name__ == "__main__":
    main()
