#!/usr/bin/env python3
"""estatisticas.py — the figures of docs/new-series-design.md sections 9 and 11, from the attempts of a configuration.

An attempt record is one scheduled attempt of one configuration on one system of one track:

    {"configuration": "claude-haiku-5.5-xhigh", "track": "default", "system": "A", "run": 3,
     "outcome": "completed", "blocked_at": null, "score": 812}

`outcome` is one of completed, blocked, budget_exhausted (scheduled, counted), switched (a lab defect, left out and reported) and
lab_failure / operator_error (rerun under the rule of design 6.2, so left out and reported as pending). `blocked_at` is `start` or
`work` and only for `blocked`. `score` is the TOTAL of the attempt's scorecard v2: every counted attempt has one, a blocked or
exhausted attempt being scored on the folder it left.

    python3 tools/estatisticas.py collect ROOT --out attempts.jsonl
        reads ROOT/<configuration>/<system>/run-<n>/scorecard.json (scorecard v2) and an optional attempt.json beside it
        ({"outcome": ..., "blocked_at": ...}, required when there is no scorecard or the outcome is not the scorecard's)
    python3 tools/estatisticas.py report attempts.jsonl [--track default] [--configuration NAME] [--target 25] [--runs 5]
        per configuration and track: the quality of the evaluable deliveries, the completion rate and its consistency (the share of
        systems whose attempts all completed, pass^k), the expected score per scheduled attempt, the outcomes counted separately,
        and the variance split between systems and runs with the sizing it implies
    python3 tools/estatisticas.py compare attempts.jsonl A B [--track default]
        the paired difference A - B of the expected score per scheduled attempt (same system, same run number), with its interval

Intervals are percentile bootstraps over systems and, inside each system, over its attempts (design section 11). The generator is
seeded, so the same attempts give the same figures. Standard library only."""

import argparse
import collections
import json
import math
import os
import random
import re
import sys

COUNTED = ("completed", "blocked", "budget_exhausted")
LEFT_OUT = ("switched",)
RERUN = ("lab_failure", "operator_error")
OUTCOMES = COUNTED + LEFT_OUT + RERUN
Z95 = 1.96


def die(message):
    sys.exit("[estatisticas] " + message)


# ---- the attempts --------------------------------------------------------------------------------------------------------------

def check_attempt(a, where):
    for key in ("configuration", "track", "system", "run", "outcome"):
        if a.get(key) in (None, ""):
            die("%s: the attempt has no %s" % (where, key))
    if a["outcome"] not in OUTCOMES:
        die("%s: unknown outcome %r (one of %s)" % (where, a["outcome"], ", ".join(OUTCOMES)))
    if a["outcome"] == "blocked" and a.get("blocked_at") not in ("start", "work"):
        die("%s: a blocked attempt says where it fell: blocked_at is `start` or `work`, not %r" % (where, a.get("blocked_at")))
    if a["outcome"] != "blocked" and a.get("blocked_at") is not None:
        die("%s: blocked_at belongs to blocked attempts only" % where)
    if a["outcome"] in COUNTED:
        score = a.get("score")
        if isinstance(score, bool) or not isinstance(score, (int, float)) or not 0 <= score <= 1000:
            die("%s: a counted attempt (%s) has a score from 0 to 1000, not %r: a blocked or exhausted attempt is scored on the folder it left"
                % (where, a["outcome"], score))
    return a


def load_attempts(path):
    with open(path, encoding="utf-8") as f:
        text = f.read()
    rows = json.loads(text) if text.lstrip().startswith("[") else [json.loads(line) for line in text.splitlines() if line.strip()]
    seen = set()
    for i, a in enumerate(rows, 1):
        check_attempt(a, "%s #%d" % (path, i))
        key = (a["configuration"], a["track"], a["system"], a["run"])
        if key in seen:
            die("%s #%d: the attempt %s appears twice" % (path, i, "/".join(map(str, key))))
        seen.add(key)
    return rows


def collect(root):
    """The attempts under ROOT/<configuration>/<system>/run-<n>/ (scorecard.json and/or attempt.json)."""
    out = []
    for configuration in sorted(os.listdir(root)):
        for system in sorted(os.listdir(os.path.join(root, configuration))):
            sdir = os.path.join(root, configuration, system)
            for run in sorted(os.listdir(sdir)):
                m = re.fullmatch(r"run-(\d+)", run)
                folder = os.path.join(sdir, run)
                if not m or not os.path.isdir(folder):
                    continue
                card = note = None
                if os.path.exists(os.path.join(folder, "scorecard.json")):
                    with open(os.path.join(folder, "scorecard.json"), encoding="utf-8") as f:
                        card = json.load(f)
                if os.path.exists(os.path.join(folder, "attempt.json")):
                    with open(os.path.join(folder, "attempt.json"), encoding="utf-8") as f:
                        note = json.load(f)
                if card is None and note is None:
                    die("%s has neither scorecard.json nor attempt.json" % folder)
                outcome = (note or {}).get("outcome") or (card or {}).get("outcome")
                out.append({"configuration": configuration, "track": (card or {}).get("track") or (note or {}).get("track"), "system": system,
                            "run": int(m.group(1)), "outcome": outcome, "blocked_at": (note or {}).get("blocked_at"),
                            "score": card["total"] if card else None})
    for i, a in enumerate(out, 1):
        check_attempt(a, "collected #%d" % i)
    return out


# ---- the arithmetic ------------------------------------------------------------------------------------------------------------

def mean(values):
    return sum(values) / len(values)


def bootstrap(groups, draws, rng):
    """Percentile 95% interval of the mean of `groups` (a list of lists, one per system), resampling systems and then the
    attempts inside each sampled system. Returns (low, high) or None when there is nothing to resample."""
    groups = [g for g in groups if g]
    if not groups:
        return None
    means = []
    for _ in range(draws):
        picked = [groups[rng.randrange(len(groups))] for _ in range(len(groups))]
        values = [g[rng.randrange(len(g))] for g in picked for _ in range(len(g))]
        means.append(mean(values))
    means.sort()
    return means[int(0.025 * draws)], means[min(draws - 1, int(0.975 * draws))]


def estimate(groups, draws, seed):
    """Mean with its interval; each estimate has its own seeded stream, so identical data always gets the identical interval."""
    values = [v for g in groups for v in g]
    if not values:
        return None
    interval = bootstrap(groups, draws, random.Random(seed))
    return {"n": len(values), "systems": len([g for g in groups if g]), "mean": round(mean(values), 1),
            "low": round(interval[0], 1), "high": round(interval[1], 1)}


def variance_components(groups):
    """One-way random effects over systems, unbalanced: (variance between systems, variance between runs of a system), or None
    when there are fewer than two systems or no system with two attempts."""
    groups = [g for g in groups if g]
    a, n = len(groups), sum(len(g) for g in groups)
    if a < 2 or n <= a:
        return None
    grand = sum(sum(g) for g in groups) / n
    ssb = sum(len(g) * (mean(g) - grand) ** 2 for g in groups)
    ssw = sum((v - mean(g)) ** 2 for g in groups for v in g)
    msb, msw = ssb / (a - 1), ssw / (n - a)
    n0 = (n - sum(len(g) ** 2 for g in groups) / n) / (a - 1)
    return {"system": round(max(0.0, (msb - msw) / n0), 1), "run": round(msw, 1)}


def sizing(components, target, runs):
    """Systems needed for a 95% half-width of `target` points on a track score with `runs` attempts per system."""
    if not components or target <= 0:
        return None
    per_system = components["system"] + components["run"] / runs
    return {"target_half_width": target, "runs_per_system": runs, "systems": max(2, math.ceil(Z95 ** 2 * per_system / target ** 2))}


def by_system(attempts, value):
    groups = collections.defaultdict(list)
    for a in attempts:
        v = value(a)
        if v is not None:
            groups[a["system"]].append(v)
    return [groups[s] for s in sorted(groups)]


def report_for(attempts, draws, seed, target, runs):
    counted = [a for a in attempts if a["outcome"] in COUNTED]
    completed = [a for a in counted if a["outcome"] == "completed"]
    systems = sorted({a["system"] for a in counted})
    all_completed = [s for s in systems if all(a["outcome"] == "completed" for a in counted if a["system"] == s)]
    per_attempt = by_system(counted, lambda a: a["score"])
    outcomes = collections.Counter(a["outcome"] for a in attempts)
    blocked = collections.Counter(a["blocked_at"] for a in attempts if a["outcome"] == "blocked")
    components = variance_components(per_attempt)
    return {
        "scheduled": len(counted),
        "quality_of_evaluable_deliveries": estimate(by_system(completed, lambda a: a["score"]), draws, seed),
        "completion": {"rate": round(len(completed) / len(counted), 3) if counted else None, "completed": len(completed), "scheduled": len(counted),
                       "all_attempts_completed_on": {"systems": len(all_completed), "of": len(systems), "share": round(len(all_completed) / len(systems), 3)
                                                     if systems else None}},
        "expected_score_per_scheduled_attempt": estimate(per_attempt, draws, seed),
        "outcomes": {"completed": outcomes["completed"], "blocked": outcomes["blocked"],
                     "blocked_at_the_start": blocked["start"], "blocked_during_the_work": blocked["work"],
                     "budget_exhausted": outcomes["budget_exhausted"], "switched": outcomes["switched"],
                     "lab_failure_to_rerun": outcomes["lab_failure"], "operator_error_to_rerun": outcomes["operator_error"]},
        "variance": components,
        "sizing": sizing(components, target, runs),
    }


def paired_difference(a_attempts, b_attempts, draws, seed):
    """A - B of the score per scheduled attempt, paired by (system, run)."""
    key = lambda x: (x["system"], x["run"])  # noqa: E731
    a_by = {key(x): x["score"] for x in a_attempts if x["outcome"] in COUNTED}
    b_by = {key(x): x["score"] for x in b_attempts if x["outcome"] in COUNTED}
    pairs = sorted(set(a_by) & set(b_by))
    groups = collections.defaultdict(list)
    for system, run in pairs:
        groups[system].append(a_by[(system, run)] - b_by[(system, run)])
    estimated = estimate([groups[s] for s in sorted(groups)], draws, seed)
    if estimated is None:
        return None
    estimated["pairs"] = estimated.pop("n")
    estimated["unpaired_attempts"] = (len(a_by) - len(pairs)) + (len(b_by) - len(pairs))
    estimated["interval_excludes_zero"] = estimated["low"] > 0 or estimated["high"] < 0
    return estimated


# ---- the command line ----------------------------------------------------------------------------------------------------------

def main():
    ap = argparse.ArgumentParser(description="Statistics of the new series (docs/new-series-design.md sections 9 and 11)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    p = sub.add_parser("collect")
    p.add_argument("root")
    p.add_argument("--out", required=True)
    p = sub.add_parser("report")
    p.add_argument("attempts")
    p.add_argument("--track")
    p.add_argument("--configuration")
    p.add_argument("--target", type=float, default=25.0, help="half-width of the interval to size for, in points (default 25)")
    p.add_argument("--runs", type=int, default=5, help="attempts per system for the sizing (default 5)")
    p.add_argument("--bootstrap", type=int, default=2000)
    p.add_argument("--seed", type=int, default=1)
    p.add_argument("--out")
    p = sub.add_parser("compare")
    p.add_argument("attempts")
    p.add_argument("a")
    p.add_argument("b")
    p.add_argument("--track", required=True)
    p.add_argument("--bootstrap", type=int, default=2000)
    p.add_argument("--seed", type=int, default=1)
    p.add_argument("--out")
    a = ap.parse_args()

    if a.cmd == "collect":
        rows = collect(a.root)
        with open(a.out, "w", encoding="utf-8") as f:
            for row in rows:
                f.write(json.dumps(row, ensure_ascii=False) + "\n")
        print("[estatisticas] %d attempt(s) → %s" % (len(rows), a.out), file=sys.stderr)
        return

    rows = load_attempts(a.attempts)
    if a.cmd == "report":
        keys = sorted({(r["configuration"], r["track"]) for r in rows if (not a.track or r["track"] == a.track)
                       and (not a.configuration or r["configuration"] == a.configuration)})
        if not keys:
            die("no attempt matches")
        result = [dict(configuration=c, track=t, **report_for([r for r in rows if (r["configuration"], r["track"]) == (c, t)],
                                                           a.bootstrap, a.seed, a.target, a.runs)) for c, t in keys]
    else:
        pick = lambda name: [r for r in rows if r["configuration"] == name and r["track"] == a.track]  # noqa: E731
        for name in (a.a, a.b):
            if not pick(name):
                die("no attempt of %s on the %s track" % (name, a.track))
        result = {"track": a.track, "a": a.a, "b": a.b, "difference_a_minus_b": paired_difference(pick(a.a), pick(a.b), a.bootstrap, a.seed)}
    text = json.dumps(result, ensure_ascii=False, indent=2)
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(text + "\n")
        print("[estatisticas] → %s" % a.out, file=sys.stderr)
    else:
        print(text)


if __name__ == "__main__":
    main()
