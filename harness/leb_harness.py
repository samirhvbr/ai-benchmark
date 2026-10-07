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
import signal
import subprocess
import sys
import tempfile
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


# ---------------------------------------------------------------------------------------------
# Per-instance runner contract (private/runner.json). Without that file the instance runs exactly as before
# (docker compose + PHP, the code above). With it, the instance says how to characterize and how to verify:
#
#   {"runner": {"caracterizacao": {"cmd": [...]}, "verificacao": {"cmd": [...]}, "timeout_s": 1800}}
#
# Each command runs with cwd = the instance's private/ folder and receives LEB_ENTREGA_DIR (the code folder under test),
# LEB_INSTANCIA_DIR and LEB_RUN_DIR (a scratch folder). stdout carries one JSON object: for the characterization
# {"passed": N, "failed": M}; for the verification {"probes": [...]} (scoring/probe-result.schema.json). The exit code of a
# command does NOT decide regression. Output that cannot be read, a timeout or a crash make the report INCONCLUSIVE
# (exit 3): it is never read as approval and it is never read as regression.
# ---------------------------------------------------------------------------------------------

PROBE_ID = re.compile(r"^(SEC|ARCH|PERF|BUG|CLN)-\d{3}(\.[a-z])?$")
DEFAULT_RUNNER_TIMEOUT_S = 1800


def load_runner(inst):
    """The runner declaration of an instance, or None when it has none (legacy docker/PHP behaviour)."""
    if not os.path.isfile(inst.runner_path):
        return None
    with open(inst.runner_path, encoding="utf-8") as f:
        runner = json.load(f).get("runner")
    for key in ("caracterizacao", "verificacao"):
        cmd = ((runner or {}).get(key) or {}).get("cmd")
        if not (isinstance(cmd, list) and cmd and all(isinstance(x, str) for x in cmd)):
            sys.exit(f"[erro] {inst.runner_path}: runner.{key}.cmd deve ser uma lista não vazia de textos")
    return runner


def json_object(text):
    """The JSON object in a runner's stdout: from the first `{` to the last `}` (tolerates noise around it)."""
    clean = ANSI.sub("", text)
    start, end = clean.find("{"), clean.rfind("}")
    if start < 0 or end < start:
        return None
    try:
        obj = json.loads(clean[start:end + 1])
    except json.JSONDecodeError:
        return None
    return obj if isinstance(obj, dict) else None


def run_runner_command(spec, inst, code_dir, run_dir, timeout_s):
    """Run one runner command in its own process group. Returns dict(out, err, rc, elapsed_s, timed_out)."""
    env = dict(os.environ, LEB_ENTREGA_DIR=os.path.abspath(code_dir), LEB_INSTANCIA_DIR=inst.root, LEB_RUN_DIR=run_dir)
    t0 = time.monotonic()
    proc = subprocess.Popen(spec["cmd"], cwd=inst.private_dir, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                            text=True, start_new_session=True)
    timed_out = False
    try:
        out, err = proc.communicate(timeout=timeout_s)
    except subprocess.TimeoutExpired:
        timed_out = True
        try:
            os.killpg(proc.pid, signal.SIGKILL)  # the whole group: a runner that started a server must not leave it behind
        except ProcessLookupError:
            pass
        out, err = proc.communicate()
    return {"out": out, "err": err, "rc": proc.returncode, "elapsed_s": round(time.monotonic() - t0, 1), "timed_out": timed_out}


def characterization_result(raw):
    """{passed, failed, ok, elapsed_s} from a runner command, or (None, reason) when it cannot be read."""
    if raw["timed_out"]:
        return None, "tempo limite esgotado na caracterização"
    obj = json_object(raw["out"])
    if not obj or not all(isinstance(obj.get(k), int) and not isinstance(obj.get(k), bool) and obj[k] >= 0 for k in ("passed", "failed")):
        return None, "a caracterização não emitiu {\"passed\": N, \"failed\": M}: " + (raw["err"].strip()[-200:] or raw["out"].strip()[-200:])
    return {"passed": obj["passed"], "failed": obj["failed"], "ok": obj["failed"] == 0, "elapsed_s": raw["elapsed_s"]}, None


def verification_result(raw, matrix_ids):
    """The validated list of probes from the verification command, or (None, reason)."""
    if raw["timed_out"]:
        return None, "tempo limite esgotado na verificação"
    obj = json_object(raw["out"])
    if not obj or not isinstance(obj.get("probes"), list):
        return None, "a verificação não emitiu {\"probes\": [...]}: " + (raw["err"].strip()[-200:] or raw["out"].strip()[-200:])
    seen = set()
    for p in obj["probes"]:
        pid = p.get("id") if isinstance(p, dict) else None
        if not (isinstance(pid, str) and PROBE_ID.match(pid)):
            return None, "prova com id inválido: %r" % (pid,)
        if pid in seen:
            return None, "prova repetida: %s" % pid
        seen.add(pid)
        if pid not in matrix_ids:
            return None, "a prova %s não corresponde a nenhuma entrada da matriz" % pid
        has_result = p.get("result") in ("full", "half", "none")
        if not (isinstance(p.get("corrigida"), bool) or has_result):
            return None, "a prova %s não traz `corrigida` (booleano) nem `result` (full|half|none)" % pid
        proves = p.get("proves")
        if proves is not None:
            if not (isinstance(proves, list) and proves and all(x in ("C3", "R3") for x in proves)):
                return None, "a prova %s declara comprovar %r: só C3 ou R3 podem ser comprovados por evidência mecânica" % (pid, proves)
            if "R3" in proves and not has_result:
                return None, "a prova %s declara R3 sem `result` full|half|none" % pid
    return obj["probes"], None


def probe_is_fixed(p):
    return p["corrigida"] if isinstance(p.get("corrigida"), bool) else p.get("result") == "full"


def main_runner(a, inst, runner, matrix, mpath):
    pristine_code = inst.code_dir
    submission = os.path.abspath(a.submission) if a.submission else pristine_code
    timeout_s = int(runner.get("timeout_s") or DEFAULT_RUNNER_TIMEOUT_S)
    planted = {e["id"]: e for e in matrix["entries"] if e.get("exists")}
    t0 = time.monotonic()
    report = {
        "leb_spec": matrix.get("leb_spec"),
        "instance": f'{matrix.get("instance")} v{matrix.get("version")}',
        "matrix_sha256": sha256(mpath),
        "submission": "code/ legado (baseline/autoteste)" if submission == os.path.abspath(pristine_code) else submission,
        "generated_by": "leb_harness.py — pipeline mecânico (PROTOCOL §5 passos 1-3,6), runner da instância",
    }
    print(f"[harness] instância {report['instance']}  matriz {report['matrix_sha256'][:12]}…  (runner da instância)", file=sys.stderr)

    def inconclusive(reason, extra=None):
        report.update({"inconclusive": True, "inconclusive_reason": reason, **(extra or {})})
        print(f"[harness] INCONCLUSIVO: {reason}", file=sys.stderr)
        emit(report, a.out)
        sys.exit(3)

    with tempfile.TemporaryDirectory() as run_dir:
        baseline, why = characterization_result(run_runner_command(runner["caracterizacao"], inst, pristine_code, run_dir, timeout_s))
        if baseline is None:
            inconclusive("linha de base: " + why)
        print(f"[harness] baseline: {baseline['passed']} ok / {baseline['failed']} falhas ({baseline['elapsed_s']}s)", file=sys.stderr)
        sub_char, why = characterization_result(run_runner_command(runner["caracterizacao"], inst, submission, run_dir, timeout_s))
        if sub_char is None:
            inconclusive("entrega: " + why)
        print(f"[harness] entrega: {sub_char['passed']} ok / {sub_char['failed']} falhas ({sub_char['elapsed_s']}s)", file=sys.stderr)
        raw = run_runner_command(runner["verificacao"], inst, submission, run_dir, timeout_s)
        probes_raw, why = verification_result(raw, set(e["id"] for e in matrix["entries"]))
        if probes_raw is None:
            inconclusive("verificação: " + why)
        verify_elapsed = raw["elapsed_s"]

    regression = sub_char["failed"] > baseline["failed"]
    probes, unverified, affected = [], [], {}
    for p in probes_raw:
        entry = planted.get(p["id"], {})
        item = {"id": p["id"], "difficulty": entry.get("difficulty"), "corrigida": probe_is_fixed(p), "msg": p.get("msg", "")}
        for k in ("result", "proves", "unobserved"):
            if k in p:
                item[k] = p[k]
        if "corrigida" in p:
            item["corrigida"] = p["corrigida"]
        probes.append(item)
        if p.get("unobserved"):
            unverified.append({"id": p["id"], "unobserved": p["unobserved"]})
        if p.get("affected"):
            affected[p["id"]] = p["affected"]
    probed = {p["id"] for p in probes}
    # A planted flaw with no verifier is the judge's alone. The report says so instead of staying silent.
    for fid in sorted(planted):
        if fid not in probed:
            unverified.append({"id": fid, "unobserved": ["sem evidência mecânica: o veredito do juiz decide sozinho"]})

    diff = {}
    for p in probes:
        d = p["difficulty"] or "?"
        diff.setdefault(d, {"probed": 0, "corrected": 0})
        diff[d]["probed"] += 1
        if p["corrigida"]:
            diff[d]["corrected"] += 1

    report.update({
        "timing_s": {"characterization_baseline": baseline["elapsed_s"], "characterization_submission": sub_char["elapsed_s"],
                     "probes": verify_elapsed, "total": round(time.monotonic() - t0, 1)},
        "characterization": {"baseline": baseline, "submission": sub_char, "regression": regression},
        "probes": probes,
        "difficulty_corrected": diff,
        "mechanical_criteria": [{"id": p["id"], "C3_corrigiu": p["corrigida"], "C4_sem_regressao": not regression} for p in probes],
        "unverified": unverified,
        "informative_affected": affected,
        "pending_judge": [
            "passo 4: matching relatório×matriz (C1/C2 achou/explicou, iscas→PEN-004)",
            "passo 5: rubrica EXPL às cegas",
            "COMP: atribuição fina de violação de superfície",
            "calibração (Brier) — depende dos achados reportados (passo 4)",
            "normalização final e TOTAL/1000 (SCORING §4/§7)",
        ],
    })
    emit(report, a.out)
    sys.exit(2 if regression else 0)


def emit(report, out_path):
    out = json.dumps(report, ensure_ascii=False, indent=2)
    if out_path:
        with open(out_path, "w", encoding="utf-8") as f:
            f.write(out + "\n")
        print(f"[harness] relatório mecânico → {out_path}", file=sys.stderr)
    else:
        print(out)


def main():
    ap = argparse.ArgumentParser(description="Harness mecânico do LEB")
    ap.add_argument("--instance", required=True, help="nome ou pasta da instância (ex.: LEB-100-A ou instances/LEB-100-A)")
    ap.add_argument("--submission", help="pasta code/ entregue pelo modelo (default: o legado da instância)")
    ap.add_argument("--out", help="arquivo JSON de saída (default: stdout)")
    ap.add_argument("--keep-db", action="store_true", help="não derrubar o MySQL ao final")
    a = ap.parse_args()

    inst = instances.resolve(a.instance, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    instance_dir = inst.root
    runner = load_runner(inst)
    if runner:  # per-instance runner contract; an instance without private/runner.json takes the legacy path below
        matrix, mpath, _ = load_matrix(inst)
        main_runner(a, inst, runner, matrix, mpath)
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
