"""Shared helpers for the tooling tests. Standard library only; synthetic fixtures only."""

import json
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCORE = os.path.join(ROOT, "harness", "score.py")
PACK = os.path.join(ROOT, "harness", "pack.py")


def run_py(script, *args, cwd=None, env=None, check=False):
    """Run a repository script with the current interpreter and return the CompletedProcess."""
    full_env = dict(os.environ)
    if env:
        full_env.update(env)
    r = subprocess.run([sys.executable, script, *args], cwd=cwd or ROOT, env=full_env, capture_output=True, text=True)
    if check and r.returncode != 0:
        raise AssertionError("%s %s exited %d\n%s" % (script, " ".join(args), r.returncode, r.stderr))
    return r


def write_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)


def read_text(*parts):
    with open(os.path.join(*parts), encoding="utf-8") as f:
        return f.read()


def read_json(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def score_files(matrix, mech, verdict, score_script=None):
    """Assemble a scorecard with the real score.py from three in-memory documents; return (card, rc, stderr)."""
    with tempfile.TemporaryDirectory() as t:
        paths = {n: os.path.join(t, n + ".json") for n in ("matrix", "mech", "verdict", "card")}
        write_json(paths["matrix"], matrix)
        write_json(paths["mech"], mech)
        write_json(paths["verdict"], verdict)
        r = run_py(score_script or SCORE, "--matrix", paths["matrix"], "--mechanical", paths["mech"],
                   "--judge", paths["verdict"], "--out", paths["card"])
        card = read_json(paths["card"]) if r.returncode == 0 else None
        return card, r.returncode, r.stderr


# ---- synthetic documents --------------------------------------------------------------

def synthetic_matrix(entries, **header):
    base = {"leb_spec": "1.3.0", "instance": "LEB-TEST-A", "level": "LEB-300", "version": "1.0",
            "language": "java21", "public_surface": {}, "entries": entries}
    base.update(header)
    return base


def entry(fid, severity="Alta", difficulty="Moderada", template="C", exists=True, **extra):
    e = {"id": fid, "exists": exists}
    if exists:
        e.update({"severity": severity, "difficulty": difficulty, "template": template})
    e.update(extra)
    return e


def synthetic_mech(failed=0, probes=()):
    return {"characterization": {"baseline": {"passed": 22, "failed": 0, "ok": True},
                                 "submission": {"passed": 22 - failed, "failed": failed, "ok": failed == 0},
                                 "regression": failed > 0},
            "probes": list(probes), "difficulty_corrected": {}}


def synthetic_verdict(planted, false_positives=()):
    return {"instance": "LEB-TEST-A", "matrix_sha256": "0" * 64, "model": {"name": "x"}, "protocol": {"mode": "A"},
            "judge": {"type": "llm", "id": "t"}, "blind_label": "x", "planted": list(planted), "false_positives": list(false_positives),
            "extra_findings": [], "comp_violations": [], "penalties": {},
            "expl_rubric": {"clareza": 5, "precisao": 5, "causa_raiz": 5, "priorizacao": 5, "trade_offs": 5}}


def judged(fid, reported=True, confidence=80, **criteria):
    return {"id": fid, "reported": reported, "confidence": confidence, "criteria": criteria}


def finding(card, fid):
    return next(f for f in card["findings"] if f["id"] == fid)


# ---- the schema validator lives in harness/jsonschema_lite.py (shared with the exporter) ----------------------

sys.path.insert(0, os.path.join(ROOT, "harness"))
from jsonschema_lite import validate  # noqa: E402,F401


def make_instance(parent, name="LEB-TEST-A", layout="legacy", matrix=None):
    """Create a synthetic instance on disk and return its path.

    legacy: <name>/{code, manifest.md, private/matrix.json}
    split:  <name>/{public/{code, manifest.md}, private/matrix.json}
    """
    inst = os.path.join(parent, "instances", name)
    pub = inst if layout == "legacy" else os.path.join(inst, "public")
    os.makedirs(os.path.join(pub, "code"))
    os.makedirs(os.path.join(inst, "private"))
    with open(os.path.join(pub, "code", "main.txt"), "w", encoding="utf-8") as f:
        f.write("synthetic application file\n")
    with open(os.path.join(pub, "manifest.md"), "w", encoding="utf-8") as f:
        f.write("# Synthetic manifest\n")
    write_json(os.path.join(inst, "private", "matrix.json"),
               matrix or synthetic_matrix([entry("SEC-001", "Alta")], instance=name))
    return inst


# ---- a synthetic published tree (instance + evaluated runs) for the exporter ---------------------------

def load_exporter(root):
    """The exporter module pointed at `root` (its ROOT and RESULTS are module globals)."""
    import importlib.util
    spec = importlib.util.spec_from_file_location("leb_export_under_test", os.path.join(ROOT, "tools", "export-results.py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    mod.ROOT = root
    mod.RESULTS = os.path.join(root, "results")
    return mod


def make_published_tree(parent, matrix, runs, name="LEB-TEST-A", edition="2026", layout="legacy", failed=0, run_base=None):
    """Instance (`make_instance`) plus evaluated runs under results/<edition>/<name>/<agent>/run-<n>/.

    `runs` is a list of (agent, run_number, verdict) built with `synthetic_verdict`; the scorecard of each is produced by the real
    score.py from the matrix, a synthetic mechanical report and that verdict. With `run_base` the run folders go to
    <run_base>/<agent>/run-<n> instead (the private archive of an active instance) and the instance itself is not created.
    Returns the tree root."""
    if run_base is None:
        make_instance(parent, name, layout=layout, matrix=matrix)
    for agent, number, verdict in runs:
        d = os.path.join(run_base, agent, "run-%d" % number) if run_base else os.path.join(parent, "results", edition, name, agent, "run-%d" % number)
        os.makedirs(os.path.join(d, "entrega"))
        with open(os.path.join(d, "entrega", "RELATORIO.md"), "w", encoding="utf-8") as f:
            f.write("report of %s run %d\n" % (agent, number))
        mech = synthetic_mech(failed=failed)
        mech["matrix_sha256"] = "a" * 64
        card, rc, err = score_files(matrix, mech, verdict)
        if rc != 0:
            raise AssertionError(err)
        write_json(os.path.join(d, "mecanico.json"), mech)
        write_json(os.path.join(d, "veredito.json"), verdict)
        write_json(os.path.join(d, "scorecard.json"), card)
        write_json(os.path.join(d, "run.json"), {
            "agent": agent, "model": {"name": agent, "id": agent, "provider": "Test", "reasoning_effort": "high"},
            "run": number, "instance": name, "instance_version": matrix.get("version"), "leb_spec": matrix.get("leb_spec"),
            "task_version": "1.0.0", "matrix_sha256": "a" * 64, "package_sha256": "b" * 64, "mode": "A", "turn_budget": 30,
            "operator_replies": 0, "filed_on": "2026-10-06", "evaluated_on": "2026-10-06",
            "client": {"name": "test", "version": "0"}, "cost_time": {"usd_estimate": 1.5, "tokens": {"total": 10}}})
    return parent
