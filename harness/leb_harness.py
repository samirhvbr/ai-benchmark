#!/usr/bin/env python3
"""
leb_harness.py — orquestrador mecânico do LEB (SPEC PROTOCOL.md §5, passos 1-3 e 6).

Roda a parte **mecânica** e reprodutível da avaliação de uma entrega e emite um
relatório JSON. Os passos que exigem juiz (4 matching relatório×matriz, 5 rubrica
EXPL) NÃO entram aqui — são a próxima etapa (juiz LLM/humano). Ver PROTOCOL.md §5.

Só-stdlib, agnóstico de instância: usa o docker-compose e os .php da própria
instância como subprocessos. A linguagem da instância pode ser qualquer uma; o
orquestrador só precisa de docker + do contrato de saída (run.php sai != 0 se
houver regressão; probes.php com LEB_PROBE_JSON=1 emite JSON).

Uso:
    python3 harness/leb_harness.py \
        --instance instances/LEB-100-A \
        [--submission /caminho/para/code_entregue] \
        [--out relatorio.json] [--keep-db]

Sem --submission, avalia o próprio code/ legado (baseline: tudo PLANTADA, sem
regressão) — é o autoteste do harness.
"""
import argparse
import json
import hashlib
import os
import re
import subprocess
import sys
import time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import instances  # noqa: E402

ANSI = re.compile(r"\x1b\[[0-9;]*m")
RESUMO = re.compile(r"(\d+)\s+verifica\S+\s+ok,\s+(\d+)\s+falharam")


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()


def compose(compose_dir, args, timeout=300):
    """Roda `docker compose <args>` em compose_dir; devolve (rc, stdout, stderr, elapsed_s)."""
    t0 = time.monotonic()
    p = subprocess.run(
        ["docker", "compose", *args],
        cwd=compose_dir,
        capture_output=True,
        text=True,
        timeout=timeout,
    )
    return p.returncode, p.stdout, p.stderr, round(time.monotonic() - t0, 1)


def run_characterization(compose_dir, code_dir_container, mount=None):
    args = ["run", "--rm"]
    if mount:
        args += ["-v", f"{mount}:/submission:ro"]
    args += ["-e", f"LEB_CODE_DIR={code_dir_container}", "php", "php", "characterization/run.php"]
    rc, out, err, dt = compose(compose_dir, args)
    m = RESUMO.search(ANSI.sub("", out))
    passed = int(m.group(1)) if m else None
    failed = int(m.group(2)) if m else None
    return {"passed": passed, "failed": failed, "ok": rc == 0, "elapsed_s": dt, "_stderr": err.strip()[-400:] if rc not in (0, 1) else ""}


def run_probes(compose_dir, code_dir_container, mount=None):
    args = ["run", "--rm"]
    if mount:
        args += ["-v", f"{mount}:/submission:ro"]
    args += ["-e", f"LEB_CODE_DIR={code_dir_container}", "-e", "LEB_PROBE_JSON=1",
             "php", "php", "private/verify/probes.php", "all"]
    rc, out, err, dt = compose(compose_dir, args)
    clean = ANSI.sub("", out)
    start, end = clean.find("{"), clean.rfind("}")
    if start < 0 or end < 0:
        raise RuntimeError(f"probes não emitiu JSON (rc={rc}).\nstdout:\n{out[-800:]}\nstderr:\n{err[-800:]}")
    data = json.loads(clean[start:end + 1])
    data["_elapsed_s"] = dt
    return data


def load_matrix(inst):
    mpath = inst.matrix_path
    with open(mpath, encoding="utf-8") as f:
        matrix = json.load(f)
    # probe-id (ex.: "sec-001") -> {matrix_id, difficulty} a partir do campo verify
    probe_map = {}
    for e in matrix["entries"]:
        v = e.get("verify", "")
        m = re.search(r"probes\.php\s+([a-z]+-\d+)", v)
        if e.get("exists") and m:
            probe_map[m.group(1)] = {"matrix_id": e["id"], "difficulty": e.get("difficulty")}
    return matrix, mpath, probe_map


def main():
    ap = argparse.ArgumentParser(description="Harness mecânico do LEB")
    ap.add_argument("--instance", required=True, help="nome ou pasta da instância (ex.: LEB-100-A ou instances/LEB-100-A)")
    ap.add_argument("--submission", help="pasta code/ entregue pelo modelo (default: o legado da instância)")
    ap.add_argument("--out", help="arquivo JSON de saída (default: stdout)")
    ap.add_argument("--keep-db", action="store_true", help="não derrubar o MySQL ao final")
    a = ap.parse_args()

    inst = instances.resolve(a.instance, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    instance_dir = inst.root
    compose_dir = os.path.join(instance_dir, "characterization")
    if not os.path.isfile(os.path.join(compose_dir, "docker-compose.yml")):
        sys.exit(f"[erro] não achei characterization/docker-compose.yml em {instance_dir}")

    matrix, mpath, probe_map = load_matrix(inst)
    pristine_code = inst.code_dir
    submission = os.path.abspath(a.submission) if a.submission else pristine_code
    is_pristine = os.path.abspath(submission) == os.path.abspath(pristine_code)
    mount = None if is_pristine else submission
    code_dir_container = "/app/code" if is_pristine else "/submission"

    t0 = time.monotonic()
    report = {
        "leb_spec": matrix.get("leb_spec"),
        "instance": f'{matrix.get("instance")} v{matrix.get("version")}',
        "matrix_sha256": sha256(mpath),
        "submission": "code/ legado (baseline/autoteste)" if is_pristine else submission,
        "generated_by": "leb_harness.py — pipeline mecânico (PROTOCOL §5 passos 1-3,6)",
    }
    print(f"[harness] instância {report['instance']}  matriz {report['matrix_sha256'][:12]}…", file=sys.stderr)
    print(f"[harness] subindo MySQL e rodando caracterização baseline (legado)…", file=sys.stderr)

    baseline = run_characterization(compose_dir, "/app/code")
    print(f"[harness] baseline: {baseline['passed']} ok / {baseline['failed']} falhas ({baseline['elapsed_s']}s)", file=sys.stderr)

    print(f"[harness] caracterização da entrega…", file=sys.stderr)
    sub_char = run_characterization(compose_dir, code_dir_container, mount)
    print(f"[harness] entrega: {sub_char['passed']} ok / {sub_char['failed']} falhas ({sub_char['elapsed_s']}s)", file=sys.stderr)

    print(f"[harness] probes de correção…", file=sys.stderr)
    probes_raw = run_probes(compose_dir, code_dir_container, mount)

    # regressão: a entrega quebrou algo que o legado passava
    base_fail = baseline["failed"] or 0
    sub_fail = sub_char["failed"] if sub_char["failed"] is not None else 999
    regression = sub_char["ok"] is False or sub_fail > base_fail

    # probes -> por falha da matriz, com dificuldade
    probes = []
    for p in probes_raw["probes"]:
        info = probe_map.get(p["id"], {})
        probes.append({
            "id": info.get("matrix_id", p["id"].upper()),
            "difficulty": info.get("difficulty"),
            "corrigida": bool(p["corrigida"]),
            "msg": p["msg"],
        })

    # eixo de dificuldade (só sobre falhas cobertas por probe; SCORING §9.2)
    diff = {}
    for p in probes:
        d = p["difficulty"] or "?"
        diff.setdefault(d, {"probed": 0, "corrected": 0})
        diff[d]["probed"] += 1
        if p["corrigida"]:
            diff[d]["corrected"] += 1

    # critérios mecânicos por falha coberta: C3 (corrigiu) e C4 (sem regressão global)
    mech = [{"id": p["id"], "C3_corrigiu": p["corrigida"], "C4_sem_regressao": not regression} for p in probes]

    report.update({
        "timing_s": {
            "characterization_baseline": baseline["elapsed_s"],
            "characterization_submission": sub_char["elapsed_s"],
            "probes": probes_raw["_elapsed_s"],
            "total": round(time.monotonic() - t0, 1),
        },
        "characterization": {"baseline": baseline, "submission": sub_char, "regression": regression},
        "probes": probes,
        "difficulty_corrected": diff,
        "mechanical_criteria": mech,
        "pending_judge": [
            "passo 4: matching relatório×matriz (C1/C2 achou/explicou, iscas→PEN-004)",
            "passo 5: rubrica EXPL às cegas",
            "COMP: atribuição fina de violação de superfície (hoje surge como regressão na caracterização)",
            "calibração (Brier) — depende dos achados reportados (passo 4)",
            "normalização final e TOTAL/1000 (SCORING §4/§7)",
        ],
    })

    if not a.keep_db:
        print("[harness] derrubando MySQL…", file=sys.stderr)
        compose(compose_dir, ["down", "-v"])

    out = json.dumps(report, ensure_ascii=False, indent=2)
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(out + "\n")
        print(f"[harness] relatório mecânico → {a.out}", file=sys.stderr)
    else:
        print(out)

    # código de saída: 0 se a entrega não regrediu; 2 se regrediu (sinal p/ CI)
    sys.exit(2 if regression else 0)


if __name__ == "__main__":
    main()
