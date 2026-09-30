#!/usr/bin/env python3
"""export-results.py — turns the evaluated runs under results/ into what gets published.

A run folder is results/<edition>/<instance>/<agent>/run-<n>/ and, once evaluated, holds:

    entrega/         what the agent handed back (code/ + RELATORIO.md + achados.json)
    run.json         the run parameters (PROTOCOL §3); unknown values are null, never guessed
    mecanico.json    leb_harness.py output (steps 1-3, 6)
    veredito.json    the judge's verdict (steps 4-5, scoring/judge.schema.json)
    scorecard.json   score.py output (step 7)

From every such folder this writes:

    <run>/scorecard.md      the human-readable scorecard, next to its json (PROTOCOL §7)
    results/results.json    one aggregate file — the input of the public results page
    results/README.md       the leaderboard, regenerated

Stdlib only and deterministic: no timestamps, sorted keys and folders, so re-running on
the same inputs produces the same bytes and a diff shows only what really changed.

    python3 tools/export-results.py            # write
    python3 tools/export-results.py --check    # exit 1 if anything written would change
"""

import argparse
import glob
import importlib.util
import json
import os
import statistics
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RESULTS = os.path.join(ROOT, "results")
REPO_URL = "https://github.com/samirhvbr/ai-benchmark"

# The per-criterion base points live in score.py; import them instead of copying,
# so a scoring change cannot leave the rendered symbols disagreeing with the total.
_spec = importlib.util.spec_from_file_location("leb_score", os.path.join(ROOT, "harness", "score.py"))
_score = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_score)
CRIT = _score.CRIT
CATEGORY_WEIGHT = _score.CATEGORY_WEIGHT

CATEGORIES = ["SEC", "ARCH", "BUG", "PERF", "CLN", "COMP", "EXPL"]
CATEGORY_NAME = {
    "SEC": "Security", "ARCH": "Architecture", "BUG": "Bugs", "PERF": "Performance",
    "CLN": "Clean Code", "COMP": "Compatibility", "EXPL": "Technical Explanation",
}
EXPL_DIMS = ["clareza", "precisao", "causa_raiz", "priorizacao", "trade_offs"]
EXPL_NAME = {"clareza": "Clarity", "precisao": "Technical precision", "causa_raiz": "Root cause",
             "priorizacao": "Prioritization", "trade_offs": "Trade-offs"}
SEVERITY_EN = {"Crítica": "Critical", "Alta": "High", "Média": "Medium", "Baixa": "Low"}
DIFFICULTY_EN = {"Fácil": "Easy", "Moderada": "Moderate", "Difícil": "Hard", "Especialista": "Expert"}
DIFFICULTY_ORDER = ["Fácil", "Moderada", "Difícil", "Especialista"]


def load(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def dumps(data):
    return json.dumps(data, ensure_ascii=False, indent=2, sort_keys=False) + "\n"


def rel(path):
    return os.path.relpath(path, ROOT)


def symbol(points, base, verdict=None):
    # A half on a 1-point criterion floors to 0 points; the verdict still was half.
    if verdict == "half" and points < base:
        return "◐"
    if points == base:
        return "✔"
    return "✘" if points == 0 else "◐"


def fixed(finding):
    """C3/R3 at full base: the flaw was eliminated, not merely mitigated."""
    key = "C3" if finding["template"] == "C" else "R3"
    return finding["criteria"].get(key, 0) == CRIT[finding["template"]][finding["severity"]][key]


def detected(finding, verdict_criteria):
    """C1/R1 at least half, as score.py counts it — read from the verdict, since
    half of a 1-point criterion floors to 0 points."""
    key = "C1" if finding["template"] == "C" else "R1"
    return verdict_criteria.get(key) in ("full", "half") or finding["criteria"].get(key, 0) > 0


def discover_runs():
    runs = []
    for card in sorted(glob.glob(os.path.join(RESULTS, "*", "*", "*", "run-*", "scorecard.json"))):
        run_dir = os.path.dirname(card)
        agent_dir = os.path.dirname(run_dir)
        instance_dir = os.path.dirname(agent_dir)
        edition_dir = os.path.dirname(instance_dir)
        missing = [n for n in ("run.json", "mecanico.json", "veredito.json") if not os.path.exists(os.path.join(run_dir, n))]
        if missing:
            sys.exit("[export] %s is missing %s" % (rel(run_dir), ", ".join(missing)))
        runs.append({
            "dir": run_dir,
            "edition": os.path.basename(edition_dir),
            "instance": os.path.basename(instance_dir),
            "agent": os.path.basename(agent_dir),
            "run": int(os.path.basename(run_dir).split("-", 1)[1]),
            "meta": load(os.path.join(run_dir, "run.json")),
            "mech": load(os.path.join(run_dir, "mecanico.json")),
            "verdict": load(os.path.join(run_dir, "veredito.json")),
            "card": load(card),
        })
    return runs


# ------------------------------------------------------------------ scorecard.md

def na(value):
    return "not recorded" if value in (None, "") else str(value)


def render_scorecard(r):
    meta, card, verdict, mech = r["meta"], r["card"], r["verdict"], r["mech"]
    model = meta["model"]
    cats = card["categories"]
    judge = verdict.get("judge", {})
    planted_verdict = {p["id"]: p for p in verdict.get("planted", [])}
    out = []
    w = out.append

    w("# LEB scorecard — %s · %s run %d" % (model["name"], card["instance"], r["run"]))
    w("")
    w("> Generated by `tools/export-results.py` from `scorecard.json`, `veredito.json`, `mecanico.json`")
    w("> and `run.json` in this folder. Do not edit by hand: change the inputs and re-run the tool.")
    w("")
    w("| Field | Value |")
    w("| --- | --- |")
    w("| Model | %s (`%s`, %s) · reasoning effort `%s` · exact version: %s |" % (
        model["name"], model["id"], model["provider"], na(model.get("reasoning_effort")), na(model.get("exact_version"))))
    w("| Instance | %s · level %s |" % (card["instance"], card["instance"].split("-")[1]))
    w("| Matrix (SHA-256) | `%s` |" % card["matrix_sha256"])
    w("| Package (SHA-256) | `%s` |" % meta["package_sha256"])
    w("| Mode | %s (budget: %s turns) |" % (meta["mode"], na(meta.get("turn_budget"))))
    w("| Temperature | %s |" % na(meta.get("temperature")))
    w("| Delivery filed | %s · evaluated %s |" % (meta["filed_on"], meta["evaluated_on"]))
    w("| Run | %d — an official score is the median of 3 runs (PROTOCOL §4); one run alone is not official |" % r["run"])
    w("| LEB spec | %s |" % card["leb_spec"])
    w("| Judge | %s `%s`, blind to the model's identity (anonymized as delivery %s) |" % (
        judge.get("type", "?"), judge.get("id", "?"), verdict.get("blind_label", "?")))
    w("")
    w("## Total: %d / 1000 — %s" % (card["total"], card["grade"]))
    w("")
    w("| Category | Score | Max |")
    w("| --- | ---: | ---: |")
    for c in CATEGORIES:
        w("| %s (%s) | %d | %d |" % (CATEGORY_NAME[c], c, cats[c]["score"], cats[c]["max"]))
    pen = sum(p["deduction"] for p in card["penalties"])
    w("| Penalties | %d | — |" % pen)
    w("| **Total** | **%d** | **1000** |" % card["total"])
    w("")

    w("## Per planted flaw")
    w("")
    w("✔ full · ◐ half · ✘ zero, per criterion (C1 found · C2 explained · C3 fixed · C4 no regression ·")
    w("C5 compatible; R1 identified · R2 explained · R3 refactored · R4 compatible). `Conf` is the")
    w("confidence the model declared (— = not reported); it feeds calibration, not points.")
    w("")
    for tpl, keys in (("C", ["C1", "C2", "C3", "C4", "C5"]), ("R", ["R1", "R2", "R3", "R4"])):
        rows = [f for f in card["findings"] if f["template"] == tpl]
        if not rows:
            continue
        w("| ID | Severity | Difficulty | %s | Points | Conf |" % " | ".join(keys))
        w("| --- | --- | --- | %s | ---: | ---: |" % " | ".join(":-:" for _ in keys))
        for f in rows:
            base = CRIT[tpl][f["severity"]]
            given = planted_verdict.get(f["id"], {}).get("criteria", {})
            marks = " | ".join(symbol(f["criteria"].get(k, 0), base[k], given.get(k)) for k in keys)
            conf = "—" if f.get("confidence") is None else str(f["confidence"])
            w("| %s | %s | %s | %s | %d/%d | %s |" % (
                f["id"], SEVERITY_EN.get(f["severity"], f["severity"]),
                DIFFICULTY_EN.get(f["difficulty"], f["difficulty"]), marks,
                f["points_earned"], f["points_possible"], conf))
        w("")
    w("Raw points by category: " + " · ".join(
        "%s %d/%d" % (c, cats[c]["raw_earned"], cats[c]["raw_possible"]) for c in CATEGORY_WEIGHT))
    w("")
    w("### Judge's rationale")
    w("")
    for f in card["findings"]:
        pv = planted_verdict.get(f["id"], {})
        why = pv.get("rationale", "").strip()
        if why:
            w("- **%s** — %s" % (f["id"], why))
    w("")

    w("## Compatibility — %d / 100" % cats["COMP"]["score"])
    w("")
    if cats["COMP"]["violations"]:
        w("| Violation | Count | Deduction | Detail |")
        w("| --- | ---: | ---: | --- |")
        for v in cats["COMP"]["violations"]:
            w("| %s | %d | %d | %s |" % (v["id"], v["count"], v["deduction"], v.get("detail", "")))
    else:
        w("No public-surface violation attributed to this delivery.")
    w("")

    w("## Technical Explanation — %d / 50" % cats["EXPL"]["score"])
    w("")
    w("Scored blind: the EXPL judge saw only the report, the task, the manifest and the legacy code —")
    w("never the answer key or the other categories.")
    w("")
    w("| Dimension | Score | Why |")
    w("| --- | ---: | --- |")
    just = verdict.get("expl_justification", {})
    for d in EXPL_DIMS:
        w("| %s | %d | %s |" % (EXPL_NAME[d], cats["EXPL"]["rubric"][d], just.get(d, "")))
    w("")

    w("## Penalties")
    w("")
    if card["penalties"]:
        for p in card["penalties"]:
            w("- %s × %d → %d%s" % (p["id"], p["count"], p["deduction"], (" — " + p["detail"]) if p.get("detail") else ""))
    else:
        w("None.")
    w("")

    w("## False positives and findings outside the matrix")
    w("")
    fps = verdict.get("false_positives", [])
    if fps:
        for fp in fps:
            kind = "decoy (PEN-004)" if fp.get("is_isca") else "not a real issue"
            w("- %s — %s, confidence %s. %s" % (fp["reported_as"], kind, na(fp.get("confidence")), fp.get("detail", "")))
    else:
        w("No false positive; neither decoy (SEC-009, PERF-006) was reported.")
    w("")
    extra = card.get("extra_findings", [])
    if extra:
        w("Real findings outside the matrix (0 points, 0 penalty, candidates for the next instance version):")
        w("")
        for e in extra:
            w("- %s" % e)
        w("")

    w("## Informative metrics (not part of the total)")
    w("")
    cal = card.get("calibration")
    if cal:
        w("- Calibration: Brier **%.3f** over %d reported findings (0 = perfect) · high-confidence false-positive rate %.3f" % (
            cal["brier"], cal["reported_count"], cal["high_conf_false_positive_rate"]))
    db = card["difficulty_breakdown"]
    w("- Discovery index: **%.1f** (difficulty-weighted share of planted flaws found)" % db["discovery_index"])
    levels = {l["difficulty"]: l for l in db["levels"]}
    for d in DIFFICULTY_ORDER:
        if d in levels:
            l = levels[d]
            w("  - %s: %d planted · %d found · %d fixed" % (DIFFICULTY_EN[d], l["planted"], l["detected"], l["corrected"]))
    w("")

    w("## Mechanical evidence")
    w("")
    ch = mech["characterization"]
    w("- Characterization: legacy %d/%d · delivery %d/%d → %s" % (
        ch["baseline"]["passed"], ch["baseline"]["passed"] + ch["baseline"]["failed"],
        ch["submission"]["passed"], ch["submission"]["passed"] + ch["submission"]["failed"],
        "regression" if ch["regression"] else "no regression"))
    for p in mech["probes"]:
        w("- Probe %s: %s — %s" % (p["id"], "FIXED" if p["corrigida"] else "still planted", p["msg"]))
    w("")
    if verdict.get("notes"):
        w("## Judge's notes")
        w("")
        w(verdict["notes"].strip())
        w("")
    return "\n".join(out).rstrip() + "\n"


# ------------------------------------------------------------------ aggregate

def run_summary(r):
    card, verdict, mech = r["card"], r["verdict"], r["mech"]
    cats = card["categories"]
    fps = verdict.get("false_positives", [])
    given = {p["id"]: p.get("criteria", {}) for p in verdict.get("planted", [])}
    ch = mech["characterization"]["submission"]
    return {
        "run": r["run"],
        "total": card["total"],
        "grade": card["grade"],
        "categories": {c: {"score": cats[c]["score"], "max": cats[c]["max"]} for c in CATEGORIES},
        "expl_rubric": cats["EXPL"]["rubric"],
        "penalties": [{"id": p["id"], "count": p["count"], "deduction": p["deduction"]} for p in card["penalties"]],
        "comp_violations": [{"id": v["id"], "count": v["count"], "deduction": v["deduction"]} for v in cats["COMP"]["violations"]],
        "flaws": {f["id"]: {"earned": f["points_earned"], "possible": f["points_possible"],
                            "found": detected(f, given.get(f["id"], {})), "fixed": fixed(f),
                            "confidence": f.get("confidence")}
                  for f in card["findings"]},
        "false_positives": len(fps),
        "decoys_reported": sum(1 for fp in fps if fp.get("is_isca")),
        "extra_findings": len(card.get("extra_findings", [])),
        "brier": (card.get("calibration") or {}).get("brier"),
        "discovery_index": card["difficulty_breakdown"]["discovery_index"],
        "characterization": {"passed": ch["passed"], "failed": ch["failed"]},
        "scorecard_url": "%s/blob/master/%s" % (REPO_URL, rel(os.path.join(r["dir"], "scorecard.md"))),
    }


def aggregate(runs):
    by_instance = {}
    for r in runs:
        by_instance.setdefault((r["edition"], r["instance"]), []).append(r)

    instances = []
    for (edition, instance), items in sorted(by_instance.items()):
        first = items[0]
        matrix = load(os.path.join(ROOT, "instances", instance, "private", "matrix.json"))
        planted = [e for e in matrix["entries"] if e.get("exists")]
        agents = {}
        for r in sorted(items, key=lambda x: (x["agent"], x["run"])):
            agents.setdefault(r["agent"], []).append(r)
        entries = []
        for agent, agent_runs in agents.items():
            totals = [x["card"]["total"] for x in agent_runs]
            summaries = [run_summary(x) for x in agent_runs]
            best = summaries[totals.index(max(totals))]
            entries.append({
                "agent": agent,
                "model": agent_runs[0]["meta"]["model"],
                "runs_count": len(agent_runs),
                "official": len(agent_runs) >= 3,
                "score": int(statistics.median(totals)),
                "discovery_index": best["discovery_index"],
                "brier": best["brier"],
                "runs": summaries,
            })
        # Median total first; the informative metrics break ties (SCORING §9).
        entries.sort(key=lambda e: (-e["score"], -e["discovery_index"], e["brier"] if e["brier"] is not None else 9, e["agent"]))
        for i, e in enumerate(entries, 1):
            e["rank"] = i
        instances.append({
            "edition": edition,
            "id": instance,
            "version": matrix["version"],
            "level": matrix["level"],
            "leb_spec": matrix["leb_spec"],
            "language": matrix.get("language"),
            "matrix_sha256": first["card"]["matrix_sha256"],
            "package_sha256": first["meta"]["package_sha256"],
            "mode": first["meta"]["mode"],
            "turn_budget": first["meta"].get("turn_budget"),
            "judge": first["verdict"].get("judge"),
            "evaluated_on": max(x["meta"]["evaluated_on"] for x in items),
            "category_max": {c: (CATEGORY_WEIGHT.get(c) or {"COMP": 100, "EXPL": 50}[c]) for c in CATEGORIES},
            "flaws": [{"id": e["id"], "category": e["id"].split("-")[0], "severity": e["severity"],
                       "difficulty": e.get("difficulty"), "template": e["template"],
                       "points_possible": sum(CRIT[e["template"]][e["severity"]].values())} for e in planted],
            "decoys": [e["id"] for e in matrix["entries"] if not e.get("exists")],
            "notes_url": "%s/blob/master/results/%s/%s/README.md" % (REPO_URL, edition, instance),
            "entries": entries,
        })
    return {
        "schema": 1,
        "generated_by": "tools/export-results.py",
        "source": "%s/tree/master/results" % REPO_URL,
        "instances": instances,
    }


def render_readme(data):
    out = []
    w = out.append
    w("# LEB results")
    w("")
    w("> Generated by `tools/export-results.py` — do not edit by hand. The notes on each")
    w("> evaluation (how it was run, what was fixed in the harness, what to keep in mind) live")
    w("> in the instance folder's own `README.md`, which is written by hand.")
    w("")
    w("Every evaluated delivery lives in `<edition>/<instance>/<agent>/run-<n>/`:")
    w("")
    w("| File | What it is |")
    w("| --- | --- |")
    w("| `entrega/` | exactly what the agent handed back: `code/`, `RELATORIO.md`, `achados.json` (and the package it received) |")
    w("| `run.json` | run parameters (PROTOCOL §3); what was not recorded is `null`, never guessed |")
    w("| `mecanico.json` | mechanical evidence: characterization before/after and the fix probes |")
    w("| `veredito.json` | the judge's verdict, with a rationale per flaw |")
    w("| `scorecard.json` · `scorecard.md` | the 1000-point scorecard |")
    w("")
    w("`results.json` aggregates all of them; it is what the public results page reads.")
    w("")
    for inst in data["instances"]:
        w("## %s · %s v%s (mode %s, %s turns)" % (inst["edition"], inst["id"], inst["version"], inst["mode"], inst["turn_budget"]))
        w("")
        w("Notes on this evaluation: [`%s/%s/README.md`](%s/%s/README.md)." % (inst["edition"], inst["id"], inst["edition"], inst["id"]))
        w("")
        w("| # | Model | Total | Grade | SEC | ARCH | BUG | PERF | CLN | COMP | EXPL | Pen. | Discovery | Brier | Runs |")
        w("| ---: | --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :-: |")
        for e in inst["entries"]:
            r = e["runs"][0] if e["runs_count"] == 1 else max(e["runs"], key=lambda x: x["total"])
            c = r["categories"]
            pen = sum(p["deduction"] for p in r["penalties"])
            link = "%s/%s/%s/run-%d/scorecard.md" % (inst["edition"], inst["id"], e["agent"], r["run"])
            w("| %d | [%s](%s) · %s | **%d** | %s | %s | %d | %s | %s | %s |" % (
                e["rank"], e["model"]["name"], link, e["model"].get("reasoning_effort", ""), e["score"], r["grade"],
                " | ".join(str(c[k]["score"]) for k in CATEGORIES), pen,
                "%.1f" % e["discovery_index"], "—" if e["brier"] is None else "%.3f" % e["brier"],
                "%d/3" % e["runs_count"]))
        w("")
        w("Maximum per column: SEC 250 · ARCH 200 · BUG 150 · PERF 150 · CLN 100 · COMP 100 · EXPL 50 → 1000.")
        if not all(e["official"] for e in inst["entries"]):
            w("A score with fewer than 3 runs is **not official** (PROTOCOL §4): it is the total of the runs so far.")
        w("")
    return "\n".join(out).rstrip() + "\n"


def main():
    ap = argparse.ArgumentParser(description="Publish the evaluated LEB runs under results/")
    ap.add_argument("--check", action="store_true", help="exit 1 if any output would change")
    a = ap.parse_args()

    runs = discover_runs()
    if not runs:
        sys.exit("[export] no evaluated run under results/ (a run needs scorecard.json)")

    outputs = {os.path.join(r["dir"], "scorecard.md"): render_scorecard(r) for r in runs}
    data = aggregate(runs)
    outputs[os.path.join(RESULTS, "results.json")] = dumps(data)
    outputs[os.path.join(RESULTS, "README.md")] = render_readme(data)

    stale = []
    for path, content in sorted(outputs.items()):
        current = open(path, encoding="utf-8").read() if os.path.exists(path) else None
        if current == content:
            continue
        stale.append(rel(path))
        if not a.check:
            with open(path, "w", encoding="utf-8") as f:
                f.write(content)

    if a.check:
        if stale:
            print("[export] out of date: %s" % ", ".join(stale), file=sys.stderr)
            sys.exit(1)
        print("[export] up to date (%d runs)" % len(runs), file=sys.stderr)
        return
    print("[export] %d runs · wrote %d file(s)%s" % (len(runs), len(stale), (": " + ", ".join(stale)) if stale else ""), file=sys.stderr)


if __name__ == "__main__":
    main()
