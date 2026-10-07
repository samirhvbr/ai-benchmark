#!/usr/bin/env python3
"""LEB — empacotador do pacote público (PROTOCOL.md §1).

Monta a pasta que vai para o modelo: code/ + manifest.md + TAREFA.md, com a TAREFA
renderizada a partir de protocol/TAREFA.md e **vinculada** à instância (id, nível,
versão, spec, SHA-256 da matriz). Faz a varredura anti-vazamento antes de terminar.

    python3 harness/pack.py --instance instances/LEB-100-A --out /tmp/leb-pkg

O pacote é determinístico: mesma instância + mesmo modo ⇒ bytes idênticos, logo o
`package_sha256` impresso no fim identifica exatamente o que o modelo recebeu.

Só-stdlib, agnóstico de instância — como o resto do harness.
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import instances  # noqa: E402

# Words that must NEVER be a path segment of a file in the package (PROTOCOL §1, BENCHMARK "golden rule").
# They match whole words: a segment is cut into words at everything that is not a letter or a digit (`matrix_x.json` has the word
# `matrix`; `VerifyToken.java` has the word `verifytoken`, which is not a pattern). An instance whose code has a legitimate name equal
# to a pattern (a `verify` package) declares it in `pack_allow_paths` in the matrix header; the list exempts the path, never the content.
LEAK_PATTERNS = ("matrix", "matriz", "private", "verify", "characterization", "probes")

# Texts of the matrix that must not appear in the content of any text file of the package, once they are this long
MARKER_FIELDS = ("evidence", "expected_fix", "location", "notes")
MIN_MARKER_LEN = 20

DEFAULT_TASK_VERSION = "1.0.0"

PLACEHOLDERS = (
    "INSTANCIA", "NIVEL", "VERSAO_INSTANCIA", "LEB_SPEC",
    "MATRIZ_SHA256", "MODO", "TAREFA_VERSAO",
)


def sha256_file(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def instance_metadata(inst, args):
    """Metadados de vínculo. Vêm da matriz (privada, lado do avaliador) quando existe.

    Da matriz sai só o *cabeçalho* (id, nível, versão, spec) e o hash do arquivo —
    nunca as falhas. Os overrides de linha de comando existem para quem tem apenas
    o pacote público em mãos.
    """
    # (chave no template, campo da matriz, override da CLI)
    fields = (("INSTANCIA", "instance", args.instance_id),
              ("NIVEL", "level", args.level),
              ("VERSAO_INSTANCIA", "version", args.instance_version),
              ("LEB_SPEC", "leb_spec", args.leb_spec))
    meta = {
        "INSTANCIA": args.instance_id or inst.name,
        "NIVEL": args.level or "n/d",
        "VERSAO_INSTANCIA": args.instance_version or "n/d",
        "LEB_SPEC": args.leb_spec or "n/d",
        "MATRIZ_SHA256": args.matrix_sha or "n/d",
        "TASK_VERSION": args.task_version or DEFAULT_TASK_VERSION,
    }
    mpath = inst.matrix_path
    if os.path.exists(mpath):
        with open(mpath, encoding="utf-8") as f:
            m = json.load(f)
        meta["MATRIZ_SHA256"] = args.matrix_sha or sha256_file(mpath)
        for key, field, override in fields:
            if not override and m.get(field) is not None:
                meta[key] = str(m[field])
        # The instance declares which version of the canonical task it is evaluated against (absent = 1.0.0).
        if not args.task_version and m.get("task_version") is not None:
            meta["TASK_VERSION"] = str(m["task_version"])
    elif meta["MATRIZ_SHA256"] == "n/d":
        print("[pack] aviso: private/matrix.json ausente — sem hash de matriz no vínculo "
              "(use --matrix-sha para informá-lo)", file=sys.stderr)
    return meta


def task_template(here, task_version):
    """The canonical task file for a version. 1.0.0 stays at protocol/TAREFA.md, untouched since the
    first published run; later versions live in protocol/tasks/TAREFA-<version>.md."""
    if not re.fullmatch(r"\d+\.\d+\.\d+", task_version):
        sys.exit("[pack] task_version inválida: %r (esperado X.Y.Z)" % task_version)
    if task_version == DEFAULT_TASK_VERSION:
        path = os.path.join(here, "protocol", "TAREFA.md")
    else:
        path = os.path.join(here, "protocol", "tasks", "TAREFA-%s.md" % task_version)
    if not os.path.isfile(path):
        sys.exit("[pack] a instância declara a tarefa %s, mas %s não existe" % (task_version, os.path.relpath(path, here)))
    return path


def render_tarefa(template_path, meta, mode_label):
    with open(template_path, encoding="utf-8") as f:
        src = f.read()
    m = re.search(r"<!--\s*LEB:TAREFA\s+versao=([0-9.]+)", src)
    if not m:
        sys.exit("[pack] protocol/TAREFA.md sem marcador de versão <!-- LEB:TAREFA versao=X -->")
    values = dict(meta, MODO=mode_label, TAREFA_VERSAO=m.group(1))
    out = src
    for key in PLACEHOLDERS:
        out = out.replace("{{%s}}" % key, values[key])
    leftover = re.findall(r"\{\{([A-Z_]+)\}\}", out)
    if leftover:
        sys.exit("[pack] placeholder não resolvido em TAREFA.md: %s" % ", ".join(sorted(set(leftover))))
    return out, m.group(1)


COMMENT_LEADER = re.compile(r"^\s*(?://+|/\*+|\*+/?|#+|--+|%+|;+)\s*")


def squash(text):
    """The text with comment leaders at the start of each line removed and whitespace collapsed to single spaces, so neither
    re-wrapping a leaked sentence nor putting it in a comment hides it."""
    return " ".join(" ".join(COMMENT_LEADER.sub("", line) for line in text.splitlines()).split())


def read_text_file(path):
    """The text of a file, or None for a binary one (a NUL byte in the first 8 KiB)."""
    with open(path, "rb") as f:
        raw = f.read()
    if b"\0" in raw[:8192]:
        return None
    return raw.decode("utf-8", errors="replace")


def leak_markers(matrix, extra_paths=()):
    """What must not appear inside the package, as a list of (marker, origin): the long texts of the matrix (`evidence`,
    `expected_fix`, `location`, `notes`) and the paths of the private destination. The public hash of the matrix in the header
    of TAREFA.md is public by design and is not a marker.

    An `evidence` that is a verbatim line of the delivered code would match the code itself and refuse every package of the
    instance. That fails safe, and the fix is in the matrix text: evidence is written as the symptom, not as a quote."""
    markers = []
    for e in (matrix or {}).get("entries", []):
        for field in MARKER_FIELDS:
            value = e.get(field)
            if isinstance(value, str) and len(squash(value)) >= MIN_MARKER_LEN:
                markers.append((squash(value), "%s.%s" % (e.get("id", "?"), field)))
    for path in extra_paths:
        if path and len(path) >= MIN_MARKER_LEN:
            markers.append((path, "private path"))
    return markers


def segment_words(segment):
    return [w for w in re.split(r"[^a-z0-9]+", segment.lower()) if w]


def allowed(rel, allow):
    parts = rel.replace(os.sep, "/").split("/")
    return any(parts[:len(a.split("/"))] == a.split("/") for a in allow)


def leak_scan(pkg_dir, markers=(), allow=()):
    """Everything in `pkg_dir` that looks like the answer key. Returns a list of (relative path, why)."""
    hits = []
    for root, dirs, files in os.walk(pkg_dir):
        dirs.sort()
        for name in sorted(list(dirs) + files):
            path = os.path.join(root, name)
            rel = os.path.relpath(path, pkg_dir)
            if not allowed(rel, allow):
                words = {w for seg in rel.replace(os.sep, "/").split("/") for w in segment_words(seg)}
                for pattern in LEAK_PATTERNS:
                    if pattern in words:
                        hits.append((rel, "path segment `%s`" % pattern))
                        break
            if markers and os.path.isfile(path):
                text = read_text_file(path)
                if text is None:
                    continue
                text = squash(text)
                for marker, origin in markers:
                    if marker in text:
                        hits.append((rel, "content: %s" % origin))
    return hits


def guard_with_positive_control(pkg_dir, markers, allow):
    """Scan the package, after proving that the guard can see. The scan first runs on a temporary copy that has a planted
    private path and a planted marker, and it must report both: a guard that has not shown it works is not a guard."""
    control = "LEB-CONTROL-MARKER-" + hashlib.sha256(os.urandom(16)).hexdigest()
    with tempfile.TemporaryDirectory() as t:
        copy = os.path.join(t, "pkg")
        shutil.copytree(pkg_dir, copy)
        os.makedirs(os.path.join(copy, "private"), exist_ok=True)
        with open(os.path.join(copy, "private", "planted.txt"), "w", encoding="utf-8") as f:
            f.write("planted: %s\n" % control)
        seen = leak_scan(copy, list(markers) + [(control, "control")], ())
        why = {h[1] for h in seen}
        if not ("path segment `private`" in why and "content: control" in why):
            sys.exit("[pack] o guarda anti-vazamento não acusou o controle positivo (%s): pacote descartado" % sorted(why))
    return leak_scan(pkg_dir, markers, allow)


def main():
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser(description="monta o pacote público de uma instância LEB")
    ap.add_argument("--instance", required=True,
                    help="nome ou raiz da instância (ex.: LEB-100-A ou instances/LEB-100-A); vê LEB_INSTANCES_PATH")
    ap.add_argument("--out", help="pasta de destino (default: <LEB_RUNS_DIR ou runs>/<instância>/pacote, recriada)")
    ap.add_argument("--mode", choices=["S", "A"], default="S", help="modo de execução (PROTOCOL §3)")
    ap.add_argument("--turnos", type=int, help="orçamento de turnos, obrigatório no modo A")
    ap.add_argument("--tarefa", default=None,
                    help="modelo canônico da tarefa (default: o da versão declarada pela instância, ver --task-version)")
    ap.add_argument("--task-version", help="versão da tarefa (X.Y.Z); default: a do cabeçalho da matriz, ou 1.0.0")
    ap.add_argument("--instance-id", help="override do vínculo (quando não há private/)")
    ap.add_argument("--level", help="override do vínculo")
    ap.add_argument("--instance-version", help="override do vínculo")
    ap.add_argument("--leb-spec", help="override do vínculo")
    ap.add_argument("--matrix-sha", help="hash da matriz, quando private/ não está disponível")
    ap.add_argument("--force", action="store_true", help="sobrescreve --out se já existir")
    a = ap.parse_args()

    inst = instances.resolve(a.instance, here)
    code_dir, manifest = inst.code_dir, inst.manifest_path
    for path in (code_dir, manifest):
        if not os.path.exists(path):
            sys.exit("[pack] instância inválida: %s não existe" % path)
    if a.mode == "A" and not a.turnos:
        sys.exit("[pack] modo A exige --turnos N (o orçamento é parâmetro obrigatório do run)")

    default_out = a.out is None
    out = os.path.abspath(a.out or os.path.join(instances.runs_dir(here), inst.name, "pacote"))
    if os.path.exists(out):
        # a pasta padrão (e qualquer pacote já montado por aqui) é sempre refeita: o
        # pacote é derivado da instância, nunca fonte de nada.
        remade = default_out or os.path.exists(os.path.join(out, ".leb-pacote.sha256"))
        if not (a.force or remade):
            sys.exit("[pack] %s já existe — use --force para sobrescrever" % out)
        shutil.rmtree(out)
    os.makedirs(out)

    mode_label = ("S (turno único: 1 prompt → 1 resposta)" if a.mode == "S"
                  else "A (agêntico · orçamento de %d turnos)" % a.turnos)
    meta = instance_metadata(inst, a)
    tarefa_path = a.tarefa or task_template(here, meta["TASK_VERSION"])
    tarefa, tarefa_version = render_tarefa(tarefa_path, meta, mode_label)
    if tarefa_version != meta["TASK_VERSION"]:
        sys.exit("[pack] a instância declara a tarefa %s, mas %s é a versão %s"
                 % (meta["TASK_VERSION"], os.path.relpath(tarefa_path, here), tarefa_version))

    shutil.copytree(code_dir, os.path.join(out, "code"))
    shutil.copy2(manifest, os.path.join(out, "manifest.md"))
    with open(os.path.join(out, "TAREFA.md"), "w", encoding="utf-8") as f:
        f.write(tarefa)

    matrix = None
    if os.path.exists(inst.matrix_path):
        with open(inst.matrix_path, encoding="utf-8") as f:
            matrix = json.load(f)
    private_paths = {os.path.realpath(inst.private_dir)}
    for var in ("LEB_PRIVATE_RESULTS", "LEB_RUNS_DIR"):
        if os.environ.get(var):
            private_paths.add(os.path.realpath(os.environ[var]))
    if inst.layout != "legacy":
        private_paths.add(os.path.realpath(inst.root))
    markers = leak_markers(matrix, sorted(private_paths))
    allow = [x.strip("/") for x in (matrix or {}).get("pack_allow_paths", [])]
    hits = guard_with_positive_control(out, markers, allow)
    if hits:
        shutil.rmtree(out)
        sys.exit("[pack] VAZAMENTO — pacote descartado. Suspeitos: %s" % "; ".join("%s (%s)" % h for h in hits))

    entries = []
    for root, dirs, files in os.walk(out):
        dirs.sort()
        for name in sorted(files):
            p = os.path.join(root, name)
            entries.append((os.path.relpath(p, out), sha256_file(p)))
    lines = ["%s  %s" % (sha, rel) for rel, sha in sorted(entries)]
    package_sha = hashlib.sha256(("\n".join(lines) + "\n").encode()).hexdigest()
    with open(os.path.join(out, ".leb-pacote.sha256"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")

    print("[pack] instância %s v%s · spec %s · tarefa %s · modo %s"
          % (meta["INSTANCIA"], meta["VERSAO_INSTANCIA"], meta["LEB_SPEC"], tarefa_version, a.mode),
          file=sys.stderr)
    print("[pack] matriz  %s" % meta["MATRIZ_SHA256"], file=sys.stderr)
    print("[pack] pacote  %s  (%d arquivos) → %s" % (package_sha, len(entries), out), file=sys.stderr)
    print(package_sha)


if __name__ == "__main__":
    main()
