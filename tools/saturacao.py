#!/usr/bin/env python3
"""saturacao.py — is an instance still telling agents apart? Read-only: it prints a report and writes nothing.

For one instance it measures, per scoring category, how much of the range the agents already use:

  saturation   the share of runs that are AT the category maximum (a category everyone maxes out no longer separates anyone)
  dispersion   mean, standard deviation, minimum and maximum of the category score and of the total
  per flaw     how often each planted flaw is found (C1/R1 full or half, as the scorecard counts it), fixed completely
               (C3/R3 at full), and how much of its points are earned

The base is declared in the report, because it changes the numbers: by default ONE run per agent, the representative one that
counts toward the score (`counts_in_score`: the lower median of the agent's runs, PROTOCOL §4); `--all-runs` uses every run.

Two sources, never mixed:

    python3 tools/saturacao.py                            # a published instance, from results/runs.csv and results/flaws.csv
    python3 tools/saturacao.py --private ROOT --instance LEB-300-A
                                                          # an ACTIVE instance, from the private archive ROOT/<instance>/<agent>/run-<n>/

The private report names flaws: it is for the operator and is never to be copied into the public repository.
Stdlib only. No effect on any score.
"""

import argparse
import csv
import glob
import importlib.util
import json
import os
import statistics
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def _module(name, filename):
    spec = importlib.util.spec_from_file_location(name, os.path.join(ROOT, *filename.split("/")))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


_score = _module("leb_score", "harness/score.py")
CATEGORIES = ["SEC", "ARCH", "BUG", "PERF", "CLN", "COMP", "EXPL"]
CATEGORY_MAX = {**_score.CATEGORY_WEIGHT, "COMP": 100, "EXPL": 50}


def load_json(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def as_bool(text):
    return str(text).strip().lower() == "true"


# ------------------------------------------------------------------------------------ sources

def load_published(results_dir, instance):
    """(runs, flaws, instance) from results/runs.csv and results/flaws.csv."""
    with open(os.path.join(results_dir, "runs.csv"), encoding="utf-8", newline="") as f:
        run_rows = list(csv.DictReader(f))
    with open(os.path.join(results_dir, "flaws.csv"), encoding="utf-8", newline="") as f:
        flaw_rows = list(csv.DictReader(f))
    names = sorted({r["instance"] for r in run_rows})
    instance = instance or (names[0] if len(names) == 1 else None)
    if instance not in names:
        sys.exit("[saturacao] choose the instance with --instance (published: %s)" % ", ".join(names))
    runs = [{"agent": r["agent"], "run": int(r["run"]), "counts": as_bool(r["counts_in_score"]), "total": float(r["total"]),
             "cats": {c: float(r[c]) for c in CATEGORIES}} for r in run_rows if r["instance"] == instance]
    flaws = [{"agent": r["agent"], "run": int(r["run"]), "counts": as_bool(r["counts_in_score"]), "flaw": r["flaw"],
              "category": r["category"], "severity": r["severity"], "difficulty": r["difficulty"],
              "found": r["found"] in ("full", "half"), "fixed": r["fixed"] == "full",
              "earned": float(r["points_earned"]), "possible": float(r["points_possible"])}
             for r in flaw_rows if r["instance"] == instance]
    return runs, flaws, instance


def load_private(root, instance):
    """(runs, flaws, instance) from the private archive of an active instance."""
    if not instance:
        sys.exit("[saturacao] --private needs --instance")
    export = _module("leb_export", "tools/export-results.py")
    cards = sorted(glob.glob(os.path.join(root, instance, "*", "run-*", "scorecard.json")))
    if not cards:
        sys.exit("[saturacao] no evaluated run under %s" % os.path.join(root, instance))
    runs, flaws = [], []
    for card_path in cards:
        d = os.path.dirname(card_path)
        card = load_json(card_path)
        verdict = load_json(os.path.join(d, "veredito.json"))
        agent, number = os.path.basename(os.path.dirname(d)), int(os.path.basename(d).split("-", 1)[1])
        runs.append({"agent": agent, "run": number, "counts": False, "total": float(card["total"]),
                     "cats": {c: float(card["categories"][c]["score"]) for c in CATEGORIES}})
        given = {p["id"]: p.get("criteria", {}) for p in verdict.get("planted", [])}
        for f in card["findings"]:
            flaws.append({"agent": agent, "run": number, "counts": False, "flaw": f["id"], "category": f["id"].split("-")[0],
                          "severity": f["severity"], "difficulty": f.get("difficulty"),
                          "found": bool(export.detected(f, given.get(f["id"], {}))), "fixed": bool(export.fixed(f)),
                          "earned": float(f["points_earned"]), "possible": float(f["points_possible"])})
    mark_representative(runs, flaws)
    return runs, flaws, instance


def mark_representative(runs, flaws):
    """The run of each agent that counts toward the score: the one whose total is the lower median of the agent's totals."""
    by_agent = {}
    for r in runs:
        by_agent.setdefault(r["agent"], []).append(r)
    chosen = set()
    for agent, items in by_agent.items():
        middle = statistics.median_low([r["total"] for r in items])
        chosen.add((agent, min(r["run"] for r in items if r["total"] == middle)))
    for r in runs:
        r["counts"] = (r["agent"], r["run"]) in chosen
    for f in flaws:
        f["counts"] = (f["agent"], f["run"]) in chosen


# ------------------------------------------------------------------------------------ analysis

def spread(values):
    return {"mean": round(statistics.fmean(values), 1), "stdev": round(statistics.pstdev(values), 1),
            "min": min(values), "max": max(values)}


def analyze(runs, flaws, instance, all_runs=False):
    base_runs = [r for r in runs if all_runs or r["counts"]]
    keys = {(r["agent"], r["run"]) for r in base_runs}
    base_flaws = [f for f in flaws if (f["agent"], f["run"]) in keys]
    if not base_runs:
        sys.exit("[saturacao] the base is empty")
    planted = {}
    for f in base_flaws:
        planted.setdefault(f["category"], set()).add(f["flaw"])
    categories = {}
    for c in CATEGORIES:
        values = [r["cats"][c] for r in base_runs]
        at_max = sum(1 for v in values if v >= CATEGORY_MAX[c])
        categories[c] = {"category_max": CATEGORY_MAX[c], "at_max": at_max, "share_at_max": round(at_max / len(values), 4),
                         "planted_flaws": len(planted.get(c, ())), **spread(values)}
    per_flaw = {}
    for f in base_flaws:
        b = per_flaw.setdefault(f["flaw"], {"flaw": f["flaw"], "category": f["category"], "severity": f["severity"],
                                            "difficulty": f["difficulty"], "n": 0, "found": 0, "fixed": 0, "earned": 0.0, "possible": 0.0})
        b["n"] += 1
        b["found"] += f["found"]
        b["fixed"] += f["fixed"]
        b["earned"] += f["earned"]
        b["possible"] += f["possible"]
    flaw_rows = [{"flaw": b["flaw"], "category": b["category"], "severity": b["severity"], "difficulty": b["difficulty"],
                  "n": b["n"], "found_rate": round(b["found"] / b["n"], 4), "fixed_rate": round(b["fixed"] / b["n"], 4),
                  "points_rate": round(b["earned"] / b["possible"], 4) if b["possible"] else None}
                 for b in sorted(per_flaw.values(), key=lambda x: x["flaw"])]
    return {
        "instance": instance,
        "base": {"scope": "all runs" if all_runs else "representative run of each agent (counts_in_score)",
                 "runs_in_base": len(base_runs), "runs_total": len(runs), "agents": len({r["agent"] for r in runs})},
        "total": spread([r["total"] for r in base_runs]),
        "categories": categories,
        "flaws": flaw_rows,
    }


def render(report):
    out, w = [], None
    w = out.append
    b = report["base"]
    w("saturation of %s" % report["instance"])
    w("base: %s — %d of %d runs, %d agents" % (b["scope"], b["runs_in_base"], b["runs_total"], b["agents"]))
    t = report["total"]
    w("total: mean %.1f  stdev %.1f  min %.0f  max %.0f" % (t["mean"], t["stdev"], t["min"], t["max"]))
    w("")
    w("by category (at-max = runs at the category maximum)")
    for c in CATEGORIES:
        x = report["categories"][c]
        w("  %-5s max %3d  mean %6.1f  stdev %5.1f  min %4.0f  at-max %2d/%d  planted flaws: %d"
          % (c, x["category_max"], x["mean"], x["stdev"], x["min"], x["at_max"], b["runs_in_base"], x["planted_flaws"]))
    w("")
    w("by planted flaw (rates over the base; found = full or half, fixed = full)")
    for x in report["flaws"]:
        pts = "—" if x["points_rate"] is None else "%3.0f%%" % (100 * x["points_rate"])
        w("  %-9s %-5s %-8s %-9s n=%-3d found %3.0f%%  fixed %3.0f%%  points %s"
          % (x["flaw"], x["category"], x["severity"], x["difficulty"], x["n"], 100 * x["found_rate"], 100 * x["fixed_rate"], pts))
    return "\n".join(out) + "\n"


def main():
    ap = argparse.ArgumentParser(description="Saturation and dispersion of an instance (read-only)")
    ap.add_argument("--instance", help="instance id (needed when results/ holds more than one, and with --private)")
    ap.add_argument("--results", default=os.path.join(ROOT, "results"), help="a published results/ folder (default: this repository's)")
    ap.add_argument("--private", metavar="ROOT", help="read the private archive of an active instance instead: ROOT/<instance>/<agent>/run-<n>/")
    ap.add_argument("--all-runs", action="store_true", help="use every run instead of the representative run of each agent")
    ap.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()

    if a.private:
        runs, flaws, instance = load_private(os.path.abspath(a.private), a.instance)
    else:
        runs, flaws, instance = load_published(a.results, a.instance)
    report = analyze(runs, flaws, instance, a.all_runs)
    if a.private:
        report["private"] = True
        print("PRIVATE REPORT — names flaws; do not copy it into the public repository", file=sys.stderr)
    sys.stdout.write(json.dumps(report, ensure_ascii=False, indent=2) + "\n" if a.json else render(report))


if __name__ == "__main__":
    main()
