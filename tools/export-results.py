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

An instance that declares `"publication": "aggregate"` in its matrix header is ACTIVE: its deliveries, verdicts, mechanical
reports and per-flaw scorecards stay in the private archive (LEB_PRIVATE_RESULTS, else LEB_RUNS_DIR) and never enter results/.
The only thing published for it is results/<edition>/<instance>/aggregate.json (scoring/publicacao-agregada.schema.json), written
by an explicit command and only by it. Per agent it carries the score, the grade, the category scores, the total, cost and time of each
run and, optionally, a short written reading in two languages (<private root>/<instance>/comments.json); no flaw id and no long text of
the matrix may appear in any of it:

    python3 tools/export-results.py --publish-aggregate LEB-300-A [--edition 2026]

A normal run never reads the private archive. It reads the aggregate.json files that were already published, and `--check` fails
if the folder of an aggregate instance holds anything else.
"""

import argparse
import calendar
import glob
import hashlib
import importlib.util
import json
import os
import re
import statistics
import sys
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "harness"))
import instances as instances_lib  # noqa: E402  (the resolver shared with leb, pack.py and leb_harness.py)
import jsonschema_lite  # noqa: E402
import pack as pack_lib  # noqa: E402  (the leak-marker rules of the package guard)

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

# PROTOCOL §4: an official result is three independent runs. A fourth would be the
# retry that §4 item 3 forbids, so it is refused rather than published.
MAX_RUNS = 3

# The day each instance's answer key became public (MATRIX §4). It lives here and never
# in matrix.json, whose SHA-256 is the published commitment and must not change.
KEY_PUBLISHED = {"LEB-100-A": "2026-07-13"}


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


def effort_label(model):
    """`default` means the model has no effort setting at all — a fact about the model, not a
    value someone forgot to write down, so it must not read as "not recorded". A client mode
    that changes how the model works (`client_mode`, e.g. Claude Code's multi-agent `ultracode`)
    is named next to the effort, since the same model at the same effort runs differently in it."""
    effort = model.get("reasoning_effort")
    mode = model.get("client_mode")
    if effort == "default":
        label = "model default — %s" % (model.get("reasoning_effort_note") or "not configurable")
        return "%s (%s)" % (label, mode) if mode else label
    return "`%s`%s" % (na(effort), " (%s)" % mode if mode and effort else "")


def effort_short(model):
    """The effort as the leaderboard prints it: `xhigh`, or `max (ultracode)` with a client mode."""
    effort = model.get("reasoning_effort") or ""
    mode = model.get("client_mode")
    return "%s (%s)" % (effort, mode) if mode and effort else effort


def key_exposure(model, instance):
    """Could the model have trained on the instance's answer key? `before` when the provider's
    published cutoff is earlier than the day the key went public, `after` when it is not,
    `unknown` when the provider publishes no cutoff; None when the key was never published.

    Without a published cutoff, the provider's own release date bounds it: a model cannot
    have trained on data that appeared after it was released (PROTOCOL §3, 2026-10-05). A
    release on or after the key's date says nothing, so it stays `unknown`."""
    published = KEY_PUBLISHED.get(instance)
    if published is None:
        return None
    cutoff = model.get("training_cutoff")
    if not cutoff:
        released = model.get("release_date")
        return "before" if released and released < published else "unknown"
    if len(cutoff) == 7:  # YYYY-MM: the data may run to the month's last day
        year, month = int(cutoff[:4]), int(cutoff[5:])
        cutoff = "%s-%02d" % (cutoff, calendar.monthrange(year, month)[1])
    return "before" if cutoff < published else "after"


def cutoff_label(model, instance):
    cutoff = model.get("training_cutoff")
    source = model.get("training_cutoff_source")
    text = cutoff or ("not published by the provider" + (": " + model["training_cutoff_note"] if model.get("training_cutoff_note") else ""))
    if source:
        text += " ([source](%s))" % source
    exposure = key_exposure(model, instance)
    if exposure == "before" and not cutoff:
        text += (" — released on %s%s, before the answer key was published (%s): a model cannot train on data"
                 " that appeared after its release" % (model["release_date"],
                 " ([source](%s))" % model["release_date_source"] if model.get("release_date_source") else "",
                 KEY_PUBLISHED[instance]))
    elif exposure == "before":
        text += " — before the answer key was published (%s): by its provider's own cutoff, the model did not train on it" % KEY_PUBLISHED[instance]
    elif exposure is not None:
        text += " — the answer key has been public since %s: the model may have trained on it" % KEY_PUBLISHED[instance]
    return text


def cost_label(ct):
    """From the client's own usage summary (SCORING §9.3); the time is the model's working time,
    never the wall-clock, which would include every wait for the operator."""
    if not ct:
        return "not recorded"
    parts = []
    tokens = ct.get("tokens") or {}
    if tokens:
        split = " · ".join("%s %s" % (k, "{:,}".format(tokens[k])) for k in ("input", "output", "cache")
                           if tokens.get(k) is not None)
        total = "{:,}".format(tokens["total"]) if tokens.get("total") is not None else "?"
        parts.append("%s tokens%s" % (total, " (%s)" % split if split else ""))
    if ct.get("elapsed_seconds") is not None:
        parts.append("model working time %ds" % round(ct["elapsed_seconds"]))
    if ct.get("usd_estimate") is not None:
        parts.append("≈ US$ %.2f" % ct["usd_estimate"])
    return "%s — from %s" % (" · ".join(parts) or "no figures", ct.get("source") or "an unnamed source")


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
    w("| Model | %s (`%s`, %s) · reasoning effort %s · exact version: %s |" % (
        model["name"], model["id"], model["provider"], effort_label(model), na(model.get("exact_version"))))
    w("| Training cutoff | %s |" % cutoff_label(model, r["instance"]))
    client, session = meta.get("client"), meta.get("session")
    w("| Client | %s |" % ("not recorded" if not client else "%s %s%s" % (
        client["name"], client.get("version") or "", "" if not session else
        " · session %s → %s" % (session["started"][:16].replace("T", " "), session["ended"][11:16]))))
    host = meta.get("execution_host")
    w("| Execution host | %s |" % ("not recorded" if not host else "%s vCPU · %s GiB RAM" % (host["vcpus"], host["ram_gib"])))
    if meta.get("first_message"):
        w("| First message | %s |" % meta["first_message"])
    w("| Instance | %s · level %s |" % (card["instance"], card["instance"].split("-")[1]))
    w("| Matrix (SHA-256) | `%s` |" % card["matrix_sha256"])
    w("| Package (SHA-256) | `%s` |" % meta["package_sha256"])
    w("| Mode | %s (budget: %s turns) |" % (meta["mode"], na(meta.get("turn_budget"))))
    if meta["mode"] == "A":
        replies = meta.get("operator_replies")
        note = meta.get("operator_replies_note")
        w("| Operator replies | %s |" % ("not recorded" if replies is None else
                                         (str(replies) + (" — " + note if note else ", each the fixed reply of PROTOCOL §3" if replies else ""))))
    w("| Temperature | %s |" % na(meta.get("temperature")))
    w("| Delivery filed | %s · evaluated %s |" % (meta["filed_on"], meta["evaluated_on"]))
    w("| Run | %d — an official score is the median of 3 runs (PROTOCOL §4); one run alone is not official |" % r["run"])
    if meta.get("run_note"):
        w("| Run note | %s |" % meta["run_note"])
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
    w("- Cost and time: %s" % cost_label(meta.get("cost_time")))
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
    # A verdict changed after the fact must say so where the score is read,
    # not only in the json.
    if verdict.get("review"):
        w("## Consistency review")
        w("")
        w("Changes made to the matching judge's verdict after it was written, so that one rule")
        w("applies to every delivery of this instance:")
        w("")
        for rv in verdict["review"]:
            w("- **%s** (%s). %s" % (rv["change"], rv["by"], rv["why"]))
        w("")
    return "\n".join(out).rstrip() + "\n"


# ------------------------------------------------------------------ aggregate

def wall_minutes(meta):
    """Minutes from the session's first message to its end, as run.json records them."""
    session = meta.get("session") or {}
    if not (session.get("started") and session.get("ended")):
        return None
    return round((datetime.fromisoformat(session["ended"]) - datetime.fromisoformat(session["started"])).total_seconds() / 60, 1)


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
        "operator_replies": r["meta"].get("operator_replies"),
        "client": r["meta"].get("client"),
        "cost_time": r["meta"].get("cost_time"),
        "wall_minutes": wall_minutes(r["meta"]),
        "scorecard_url": "%s/blob/master/%s" % (REPO_URL, rel(os.path.join(r["dir"], "scorecard.md"))),
    }


def check_agent_runs(where, agent_runs, base=None):
    """The runs of one agent, sorted by number, before anything is published from them.

    A number may be missing only where a withdrawn-<n> folder holds it (PROTOCOL §4 item 5):
    the run keeps its number, so the published runs keep theirs, and it does not count
    toward the limit.
    """
    numbers = [x["run"] for x in agent_runs]
    withdrawn = {int(m.group(1)) for m in (re.fullmatch(r"withdrawn-(\d+)", d)
                                           for d in os.listdir(os.path.join(base or RESULTS, where))) if m}
    expected = [n for n in range(1, len(numbers) + len(withdrawn) + 1) if n not in withdrawn]
    if numbers != expected:
        sys.exit("[export] %s has %s: runs are numbered run-1 to run-N with no gap, "
                 "except where a withdrawn-<n> folder holds the number"
                 % (where, ", ".join("run-%d" % n for n in numbers)))
    if len(numbers) > MAX_RUNS:
        sys.exit("[export] %s has %d runs; PROTOCOL §4 stops at %d (a further run is a retry)"
                 % (where, len(numbers), MAX_RUNS))
    reports = {}
    for x in agent_runs:
        if x["meta"].get("run") != x["run"]:
            sys.exit("[export] %s/run-%d: run.json says run %s" % (where, x["run"], x["meta"].get("run")))
        # Two runs with the same report are one delivery filed twice, not two runs.
        # A delivery can lack the report (an agent that stopped before writing it);
        # it scores as delivered, and there is no report to compare.
        report = os.path.join(x["dir"], "entrega", "RELATORIO.md")
        if not os.path.exists(report):
            continue
        with open(report, "rb") as f:
            digest = hashlib.sha256(f.read()).hexdigest()
        if digest in reports:
            sys.exit("[export] %s: run-%d has the same RELATORIO.md as run-%d — a resend, not a new run"
                     % (where, x["run"], reports[digest]))
        reports[digest] = x["run"]


def aggregate(runs):
    by_instance = {}
    for r in runs:
        by_instance.setdefault((r["edition"], r["instance"]), []).append(r)

    instances = []
    for (edition, instance), items in sorted(by_instance.items()):
        first = items[0]
        matrix = load(instances_lib.resolve(instance, ROOT).matrix_path)
        planted = [e for e in matrix["entries"] if e.get("exists")]
        agents = {}
        for r in sorted(items, key=lambda x: (x["agent"], x["run"])):
            agents.setdefault(r["agent"], []).append(r)
        # A short written reading of each agent, in the site's two languages, kept next to
        # the instance notes. Optional per agent; an id that matches no agent is a typo.
        comments_path = os.path.join(RESULTS, edition, instance, "comments.json")
        comments = load(comments_path) if os.path.exists(comments_path) else {}
        unknown = sorted(set(comments) - set(agents))
        if unknown:
            sys.exit("[export] %s names no agent: %s" % (rel(comments_path), ", ".join(unknown)))
        entries = []
        for agent, agent_runs in agents.items():
            check_agent_runs("%s/%s/%s" % (edition, instance, agent), agent_runs)
            totals = [x["card"]["total"] for x in agent_runs]
            summaries = [run_summary(x) for x in agent_runs]
            # The lower median is always the total of a run that exists — the only one, the
            # lower of two, the middle of three (PROTOCOL §4) — so the grade, categories and
            # flaws published next to the score are that run's, never a mix of runs.
            score = statistics.median_low(totals)
            representative = next(x for x in summaries if x["total"] == score)
            entries.append({
                "agent": agent,
                "model": agent_runs[0]["meta"]["model"],
                "runs_count": len(agent_runs),
                "official": len(agent_runs) >= MAX_RUNS,
                "score": score,
                "totals": totals,
                "representative_run": representative["run"],
                "key_exposure": key_exposure(agent_runs[0]["meta"]["model"], instance),
                "discovery_index": representative["discovery_index"],
                "brier": representative["brier"],
                "comment": comments.get(agent),
                "runs": summaries,
            })
        # Score first; the informative metrics break ties (SCORING §9).
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
            "key_published_on": KEY_PUBLISHED.get(instance),
            "matrix_sha256": first["card"]["matrix_sha256"],
            "package_sha256": first["meta"]["package_sha256"],
            "mode": first["meta"]["mode"],
            "turn_budget": first["meta"].get("turn_budget"),
            "judge": first["verdict"].get("judge"),
            "evaluated_on": max(x["meta"]["evaluated_on"] for x in items),
            "category_max": {c: (CATEGORY_WEIGHT.get(c) or {"COMP": 100, "EXPL": 50}[c]) for c in CATEGORIES},
            "flaws": [{"id": e["id"], "category": e["id"].split("-")[0], "severity": e["severity"],
                       "difficulty": e.get("difficulty"), "template": e["template"],
                       "points_possible": sum(CRIT[e["template"]][e["severity"]].values()),
                       **({"dimensions": e["dimensions"]} if e.get("dimensions") else {})} for e in planted],
            "decoys": [e["id"] for e in matrix["entries"] if not e.get("exists")],
            # Informative (E9): the key exists only when the matrix declares the kind of at least one decoy.
            **({"decoy_kinds": {e["id"]: e["decoy_kind"] for e in matrix["entries"] if not e.get("exists") and e.get("decoy_kind")}}
               if any(e.get("decoy_kind") for e in matrix["entries"] if not e.get("exists")) else {}),
            "notes_url": "%s/blob/master/results/%s/%s/README.md" % (REPO_URL, edition, instance),
            "entries": entries,
        })
    return {
        "schema": 1,
        "generated_by": "tools/export-results.py",
        "source": "%s/tree/master/results" % REPO_URL,
        "instances": instances,
    }


# ------------------------------------------------------------------ aggregate-only publication of an active instance

AGGREGATE_SCHEMA = os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json")
FLAW_ID = re.compile(r"\b(?:SEC|ARCH|PERF|BUG|CLN)-\d{3}(?:\.[a-z])?\b")


def private_results_root():
    """Where the evaluated runs of active instances are kept: <root>/<instance>/<agent>/run-<n>/."""
    return os.path.abspath(os.environ.get("LEB_PRIVATE_RESULTS") or instances_lib.runs_dir(ROOT))


def is_aggregate(matrix):
    return (matrix or {}).get("publication") == "aggregate"


def discover_private_runs(instance, root):
    runs = []
    for card in sorted(glob.glob(os.path.join(root, instance, "*", "run-*", "scorecard.json"))):
        run_dir = os.path.dirname(card)
        missing = [n for n in ("run.json", "mecanico.json", "veredito.json") if not os.path.exists(os.path.join(run_dir, n))]
        if missing:
            sys.exit("[export] %s is missing %s" % (run_dir, ", ".join(missing)))
        runs.append({
            "dir": run_dir, "instance": instance, "agent": os.path.basename(os.path.dirname(run_dir)),
            "run": int(os.path.basename(run_dir).split("-", 1)[1]),
            "meta": load(os.path.join(run_dir, "run.json")), "mech": load(os.path.join(run_dir, "mecanico.json")),
            "verdict": load(os.path.join(run_dir, "veredito.json")), "card": load(card),
        })
    return runs


def build_aggregate(inst, matrix, runs, root, edition=None):
    """The one thing published for an active instance: per agent, the score of the representative run (PROTOCOL §4), its grade and
    its category scores, the cost and the time. Nothing about any flaw."""
    if not runs:
        sys.exit("[export] no evaluated run of %s under %s" % (inst.name, root))
    first = runs[0]
    for r in runs:
        for what, got, want in (("matrix_sha256", r["card"].get("matrix_sha256"), first["card"].get("matrix_sha256")),
                                ("package_sha256", r["meta"].get("package_sha256"), first["meta"].get("package_sha256")),
                                ("mode", r["meta"].get("mode"), first["meta"].get("mode")),
                                ("turn_budget", r["meta"].get("turn_budget"), first["meta"].get("turn_budget"))):
            if got != want:
                sys.exit("[export] %s/%s/run-%d: %s is %r, the first run says %r — runs of one instance share them"
                         % (inst.name, r["agent"], r["run"], what, got, want))
    by_agent = {}
    for r in sorted(runs, key=lambda x: (x["agent"], x["run"])):
        by_agent.setdefault(r["agent"], []).append(r)
    # A short written reading of each agent, in the site's two languages, kept in the private archive next to the runs
    # (<root>/<instance>/comments.json) and published inside the aggregate, where the leak guard reads it like everything else.
    comments_path = os.path.join(root, inst.name, "comments.json")
    comments = load(comments_path) if os.path.exists(comments_path) else {}
    unknown = sorted(set(comments) - set(by_agent))
    if unknown:
        sys.exit("[export] %s names no agent: %s" % (comments_path, ", ".join(unknown)))
    agents = []
    for agent, agent_runs in by_agent.items():
        check_agent_runs("%s/%s" % (inst.name, agent), agent_runs, base=root)
        score = statistics.median_low([x["card"]["total"] for x in agent_runs])
        rep = next(x for x in agent_runs if x["card"]["total"] == score)
        cats = rep["card"]["categories"]
        entry = {"agent": agent, "score": score, "grade": rep["card"]["grade"], "runs_count": len(agent_runs),
                 "categories": {c: cats[c]["score"] for c in CATEGORIES},
                 "cost_usd": (rep["meta"].get("cost_time") or {}).get("usd_estimate"),
                 "wall_minutes": wall_minutes(rep["meta"]),
                 "runs": [{"run": x["run"], "total": x["card"]["total"], "cost_usd": (x["meta"].get("cost_time") or {}).get("usd_estimate"),
                           "wall_minutes": wall_minutes(x["meta"])} for x in agent_runs]}
        if agent in comments:
            entry["comment"] = comments[agent]
        agents.append(entry)
    agents.sort(key=lambda e: (-e["score"], e["agent"]))
    year = edition or max(r["meta"]["evaluated_on"] for r in runs)[:4]
    return {"publication": "aggregate", "edition": year, "instance": matrix["instance"], "version": str(matrix["version"]),
            "level": matrix["level"], "leb_spec": matrix["leb_spec"], "task_version": str(matrix.get("task_version") or "1.0.0"),
            "matrix_sha256": first["card"]["matrix_sha256"], "package_sha256": first["meta"]["package_sha256"],
            "mode": first["meta"]["mode"], "turn_budget": first["meta"].get("turn_budget"), "agents": agents}


def refuse_unclean_aggregate(agg, matrix, inst):
    """The schema has no field for a flaw, but a name can carry one: the agent id, or anything a future field allows. Before the
    aggregate is written, no flaw id and no long text of the matrix may appear in it, and it must validate."""
    errors = jsonschema_lite.validate(agg, load(AGGREGATE_SCHEMA))
    if errors:
        sys.exit("[export] the aggregate of %s does not match %s: %s" % (inst.name, rel(AGGREGATE_SCHEMA), "; ".join(errors[:5])))
    text = pack_lib.squash(dumps(agg))
    found = sorted(set(FLAW_ID.findall(text)))
    markers = [origin for marker, origin in pack_lib.leak_markers(matrix, [os.path.realpath(inst.private_dir)]) if marker in text]
    if found or markers:
        sys.exit("[export] aggregate of %s refused: it names %s" % (inst.name, ", ".join(found + markers)))


def load_aggregates():
    """The aggregate.json files already published under results/, validated and sorted."""
    schema = load(AGGREGATE_SCHEMA)
    found = []
    for path in sorted(glob.glob(os.path.join(RESULTS, "*", "*", "aggregate.json"))):
        agg = load(path)
        errors = jsonschema_lite.validate(agg, schema)
        instance_dir = os.path.dirname(path)
        if errors:
            sys.exit("[export] %s does not match %s: %s" % (rel(path), rel(AGGREGATE_SCHEMA), "; ".join(errors[:5])))
        if (agg["edition"], agg["instance"]) != (os.path.basename(os.path.dirname(instance_dir)), os.path.basename(instance_dir)):
            sys.exit("[export] %s says %s/%s: the folder and the file must agree" % (rel(path), agg["edition"], agg["instance"]))
        found.append(agg)
    return found


def check_public_tree():
    """An aggregate instance, published or merely declared so by its matrix, has nothing in results/ but its aggregate.json:
    no run folder, delivery, verdict, mechanical report, scorecard or note. Returns the problems found."""
    problems = []
    for instance_dir in sorted(glob.glob(os.path.join(RESULTS, "*", "*"))):
        if not os.path.isdir(instance_dir):
            continue
        inst = instances_lib.find(os.path.basename(instance_dir), ROOT)
        declared = inst is not None and os.path.exists(inst.matrix_path) and is_aggregate(load(inst.matrix_path))
        if declared or os.path.exists(os.path.join(instance_dir, "aggregate.json")):
            extra = sorted(set(os.listdir(instance_dir)) - {"aggregate.json"})
            if extra:
                problems.append("%s is an aggregate-only instance but holds %s" % (rel(instance_dir), ", ".join(extra)))
    return problems


def render_aggregate_sections(aggregates, w):
    for agg in aggregates:
        w("## %s · %s v%s (mode %s, %s turns) — aggregate only" % (agg["edition"], agg["instance"], agg["version"], agg["mode"], agg["turn_budget"]))
        w("")
        w("This instance is **active**. While it is, only the totals below are published: no per-flaw result, no verdict, no delivery.")
        w("")
        w("| # | Agent | Total | Grade | SEC | ARCH | BUG | PERF | CLN | COMP | EXPL | Runs |")
        w("| ---: | --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :-: |")
        for i, e in enumerate(agg["agents"], 1):
            totals = " (%s)" % " · ".join(str(r["total"]) for r in e["runs"]) if len(e.get("runs") or []) > 1 else ""
            w("| %d | `%s` | **%d** | %s | %s | %d/%d%s |" % (i, e["agent"], e["score"], e["grade"],
                                                            " | ".join(str(e["categories"][c]) for c in CATEGORIES), e["runs_count"], MAX_RUNS, totals))
        w("")
        w("Matrix SHA-256 `%s` · package SHA-256 `%s`." % (agg["matrix_sha256"], agg.get("package_sha256", "n/d")))
        w("")


def runs_cell(e):
    if e["runs_count"] == 1:
        return "1/%d" % MAX_RUNS
    return "%d/%d (%s)" % (e["runs_count"], MAX_RUNS, " · ".join(str(t) for t in e["totals"]))


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
    w("| `custo.txt` | the client's usage summary as printed, when kept; `run.json`'s `cost_time` is copied from it (SCORING §9.3) |")
    w("| `mecanico.json` | mechanical evidence: characterization before/after and the fix probes |")
    w("| `veredito.json` | the judge's verdict, with a rationale per flaw |")
    w("| `scorecard.json` · `scorecard.md` | the 1000-point scorecard |")
    w("")
    w("`results.json` aggregates all of them; it is what the public results page reads.")
    w("")
    w("The same data as spreadsheets: [`runs.csv`](runs.csv) has one row per scored run, and [`flaws.csv`](flaws.csv) one row per run and planted flaw. [`CSV.md`](CSV.md) explains every column.")
    w("")
    for inst in data["instances"]:
        w("## %s · %s v%s (mode %s, %s turns)" % (inst["edition"], inst["id"], inst["version"], inst["mode"], inst["turn_budget"]))
        w("")
        w("Notes on this evaluation: [`%s/%s/README.md`](%s/%s/README.md)." % (inst["edition"], inst["id"], inst["edition"], inst["id"]))
        w("")
        w("| # | Model | Total | Grade | SEC | ARCH | BUG | PERF | CLN | COMP | EXPL | Pen. | Discovery | Brier | Runs |")
        w("| ---: | --- | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :-: |")
        for e in inst["entries"]:
            r = next(x for x in e["runs"] if x["run"] == e["representative_run"])
            c = r["categories"]
            pen = sum(p["deduction"] for p in r["penalties"])
            link = "%s/%s/%s/run-%d/scorecard.md" % (inst["edition"], inst["id"], e["agent"], r["run"])
            mark = " †" if e["key_exposure"] in ("after", "unknown") else ""
            w("| %d | [%s](%s)%s · %s | **%d** | %s | %s | %d | %s | %s | %s |" % (
                e["rank"], e["model"]["name"], link, mark, effort_short(e["model"]), e["score"], r["grade"],
                " | ".join(str(c[k]["score"]) for k in CATEGORIES), pen,
                "%.1f" % e["discovery_index"], "—" if e["brier"] is None else "%.3f" % e["brier"],
                runs_cell(e)))
        w("")
        w("Maximum per column: SEC 250 · ARCH 200 · BUG 150 · PERF 150 · CLN 100 · COMP 100 · EXPL 50 → 1000.")
        w("Total is the lower median of the agent's runs — the middle of three, the lower of two — so it is")
        w("always the total of one run, and every other column and the link are that run's (PROTOCOL §4).")
        if not all(e["official"] for e in inst["entries"]):
            w("A score with fewer than %d runs is **not official**." % MAX_RUNS)
        if inst["key_published_on"]:
            exposed = [e for e in inst["entries"] if e["key_exposure"] in ("after", "unknown")]
            w("")
            w("The answer key of %s has been public since %s (MATRIX §4). Each run records the training" % (inst["id"], inst["key_published_on"]))
            w("cutoff its provider publishes, and the scorecard says whether the model could have trained on the key.")
            if exposed:
                w("† Cutoff after the key went public, or not published: %s." % ", ".join(
                    "%s (%s)" % (e["model"]["name"], e["model"].get("training_cutoff") or "not published") for e in exposed))
            else:
                w("Every agent above has a published cutoff earlier than that.")
        w("")
    render_aggregate_sections(data.get("aggregate_instances", []), w)
    return "\n".join(out).rstrip() + "\n"


# ------------------------------------------------------------------ CSV downloads

RUN_COLUMNS = [
    "edition", "instance", "agent", "run", "counts_in_score", "agent_rank", "agent_score", "total", "grade",
    "model", "model_id", "provider", "served_by", "reasoning_effort", "client_mode", "client", "client_version",
    "training_cutoff", "key_exposure",
    "SEC", "ARCH", "BUG", "PERF", "CLN", "COMP", "EXPL", "penalties",
    "flaws_found", "flaws_fixed", "flaws_planted", "false_positives", "extra_findings", "discovery_index", "brier",
    "characterization_passed", "operator_replies",
    "session_started", "session_ended", "wall_minutes", "model_seconds",
    "tokens_input", "tokens_output", "tokens_cache", "tokens_total", "cost_usd",
    "vcpus", "ram_gib", "filed_on", "scorecard_url",
]
FLAW_COLUMNS = [
    "edition", "instance", "agent", "run", "counts_in_score", "flaw", "category", "severity", "difficulty",
    "reported", "found", "explained", "fixed", "compatible", "points_earned", "points_possible", "confidence",
]
# Appended after the fixed columns, and only for a publication whose matrices declare dimensions (E9).
OPTIONAL_FLAW_COLUMNS = ["dimensions"]


def csv_text(columns, rows):
    """Comma-separated, a header row, every value as text, empty for unknown; `\\n` line ends
    so the bytes are the same on every machine."""
    import csv
    import io
    buf = io.StringIO()
    wr = csv.writer(buf, lineterminator="\n")
    wr.writerow(columns)
    for row in rows:
        wr.writerow(["" if row.get(c) is None else (str(row[c]).lower() if isinstance(row[c], bool) else row[c]) for c in columns])
    return buf.getvalue()


def render_csvs(runs, data):
    """runs.csv: one row per scored run. flaws.csv: one row per run and planted flaw. Both are
    built from the same inputs as results.json, so they cannot disagree with the leaderboard."""
    entries = {(i["edition"], i["id"], e["agent"]): e for i in data["instances"] for e in i["entries"]}
    flaw_info = {(i["edition"], i["id"], f["id"]): f for i in data["instances"] for f in i["flaws"]}
    run_rows, flaw_rows = [], []
    for r in sorted(runs, key=lambda x: (x["edition"], x["instance"], x["agent"], x["run"])):
        e = entries[(r["edition"], r["instance"], r["agent"])]
        s = next(x for x in e["runs"] if x["run"] == r["run"])
        meta, model = r["meta"], r["meta"]["model"]
        session, ct = meta.get("session") or {}, meta.get("cost_time") or {}
        tokens, host = ct.get("tokens") or {}, meta.get("execution_host") or {}
        wall = wall_minutes(meta)
        counts = r["run"] == e["representative_run"]
        base = {"edition": r["edition"], "instance": r["instance"], "agent": r["agent"], "run": r["run"], "counts_in_score": counts}
        run_rows.append({**base,
            "agent_rank": e["rank"], "agent_score": e["score"], "total": s["total"], "grade": s["grade"],
            "model": model.get("name"), "model_id": model.get("id"), "provider": model.get("provider"),
            "served_by": model.get("served_by"), "reasoning_effort": model.get("reasoning_effort"),
            "client_mode": model.get("client_mode"), "client": (meta.get("client") or {}).get("name"),
            "client_version": (meta.get("client") or {}).get("version"),
            "training_cutoff": model.get("training_cutoff"), "key_exposure": e["key_exposure"],
            **{c: s["categories"][c]["score"] for c in CATEGORIES},
            "penalties": sum(p["deduction"] for p in s["penalties"]),
            "flaws_found": sum(1 for f in s["flaws"].values() if f["found"]),
            "flaws_fixed": sum(1 for f in s["flaws"].values() if f["fixed"]),
            "flaws_planted": len(s["flaws"]), "false_positives": s["false_positives"], "extra_findings": s["extra_findings"],
            "discovery_index": s["discovery_index"], "brier": s["brier"],
            "characterization_passed": s["characterization"]["passed"], "operator_replies": meta.get("operator_replies"),
            "session_started": session.get("started"), "session_ended": session.get("ended"), "wall_minutes": wall,
            "model_seconds": ct.get("elapsed_seconds"), "tokens_input": tokens.get("input"), "tokens_output": tokens.get("output"),
            "tokens_cache": tokens.get("cache"), "tokens_total": tokens.get("total"), "cost_usd": ct.get("usd_estimate"),
            "vcpus": host.get("vcpus"), "ram_gib": host.get("ram_gib"), "filed_on": meta.get("filed_on"),
            "scorecard_url": s["scorecard_url"]})
        given = {p["id"]: p for p in r["verdict"].get("planted", [])}
        for f in r["card"]["findings"]:
            v, info = given.get(f["id"], {}), flaw_info[(r["edition"], r["instance"], f["id"])]
            crit = v.get("criteria", {})
            c = (lambda k: crit.get(k)) if f["template"] == "C" else (lambda k: crit.get({"C1": "R1", "C2": "R2", "C3": "R3", "C5": "R4"}[k]))
            flaw_rows.append({**base, "flaw": f["id"], "category": info["category"], "severity": info["severity"],
                "difficulty": info["difficulty"], "reported": v.get("reported"), "found": c("C1"), "explained": c("C2"),
                "fixed": c("C3"), "compatible": c("C5"), "points_earned": f["points_earned"],
                "points_possible": f["points_possible"], "confidence": f.get("confidence"),
                "dimensions": "|".join(info["dimensions"]) if info.get("dimensions") else None})
    flaw_columns = FLAW_COLUMNS + [c for c in OPTIONAL_FLAW_COLUMNS if any(i.get(c) for i in flaw_info.values())]
    return csv_text(RUN_COLUMNS, run_rows), csv_text(flaw_columns, flaw_rows)


def main():
    ap = argparse.ArgumentParser(description="Publish the evaluated LEB runs under results/")
    ap.add_argument("--check", action="store_true", help="exit 1 if any output would change")
    ap.add_argument("--publish-aggregate", metavar="INSTANCE",
                    help="write results/<edition>/<INSTANCE>/aggregate.json from the private archive (an instance whose matrix "
                         "declares publication: aggregate); the only command that ever reads the private archive")
    ap.add_argument("--edition", help="edition folder for --publish-aggregate (default: the year of the latest evaluation)")
    a = ap.parse_args()

    problems = check_public_tree()
    if problems:
        sys.exit("[export] " + "\n[export] ".join(problems))

    runs = discover_runs()
    if not runs:
        sys.exit("[export] no evaluated run under results/ (a run needs scorecard.json)")

    outputs = {os.path.join(r["dir"], "scorecard.md"): render_scorecard(r) for r in runs}
    aggregates = load_aggregates()
    if a.publish_aggregate:
        inst = instances_lib.resolve(a.publish_aggregate, ROOT)
        matrix = load(inst.matrix_path)
        if not is_aggregate(matrix):
            sys.exit("[export] %s does not declare publication: aggregate; its results are published by a normal run" % inst.name)
        root = private_results_root()
        agg = build_aggregate(inst, matrix, discover_private_runs(inst.name, root), root, a.edition)
        refuse_unclean_aggregate(agg, matrix, inst)
        outputs[os.path.join(RESULTS, agg["edition"], inst.name, "aggregate.json")] = dumps(agg)
        aggregates = [x for x in aggregates if (x["edition"], x["instance"]) != (agg["edition"], agg["instance"])] + [agg]
    aggregates.sort(key=lambda x: (x["edition"], x["instance"]))
    data = aggregate(runs)
    if aggregates:  # additive: results.json has no such key until an active instance is published
        data["aggregate_instances"] = aggregates
    outputs[os.path.join(RESULTS, "results.json")] = dumps(data)
    outputs[os.path.join(RESULTS, "README.md")] = render_readme(data)
    outputs[os.path.join(RESULTS, "runs.csv")], outputs[os.path.join(RESULTS, "flaws.csv")] = render_csvs(runs, data)

    stale = []
    for path, content in sorted(outputs.items()):
        current = open(path, encoding="utf-8").read() if os.path.exists(path) else None
        if current == content:
            continue
        stale.append(rel(path))
        if not a.check:
            os.makedirs(os.path.dirname(path), exist_ok=True)
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
