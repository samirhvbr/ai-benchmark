#!/usr/bin/env python3
"""
score_v2.py — the scorer of the new series (docs/new-series-evaluator.md), for one delivery.

Deterministic, like harness/score.py, and built on it: it reads the same three documents (the key, the mechanical report and the settled
verdict) and does arithmetic, no judgment. What differs from the full LEB's scorer:

  * a regression is a GROUP of characterization tests that guard one contract (instances/<ID>/private/regression_groups.json), and it is
    charged ONCE: on the C4/R4 of the row whose fix broke it, when the verdict attributes it to an attempted row that earns something in
    that criterion, and otherwise as PEN-002 once per group. A broken test no longer turns C4 to `none` for every flaw and costs −20 as well;
  * the category weights come from the track (scoring/tracks/<track>.json), not from the full LEB's table;
  * the scorecard says which scorer, which track and which outcome (a blocked or exhausted attempt is scored on the folder it left).

Usage:
    python3 harness/score_v2.py --track default \\
        --matrix instances/LEB-D-300-A/private/matrix.json \\
        --groups instances/LEB-D-300-A/private/regression_groups.json \\
        --mechanical mechanical_report.json --judge settled_verdict.json \\
        [--outcome completed] [--cost cost_time.json] [--out scorecard.json]
"""
import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import score as v1  # noqa: E402  the full LEB's scorer: the base points, the COMP table and the informative blocks are shared
import verdict_check  # noqa: E402  the checks on the inputs: identity, settled review, structure
from jsonschema_lite import validate  # noqa: E402

SCORER = {"name": "score_v2", "version": "1.0.0"}
OUTCOMES = ("completed", "blocked", "budget_exhausted")
TRACKS_DIR = os.path.join(os.path.dirname(HERE), "scoring", "tracks")


def die(message):
    sys.exit("[score_v2] " + message)


def load_track(name):
    path = os.path.join(TRACKS_DIR, name + ".json")
    if not os.path.exists(path):
        die("there is no track %r (scoring/tracks/)" % name)
    track = v1.load(path)
    weights = track["weights"]
    if sum(weights.values()) != 1000:
        die("the weights of track %s add up to %d, not 1000" % (name, sum(weights.values())))
    return track


def newly_failed(base, sub):
    """The test names that fail in the delivery and not in the pristine instance, or None when the runner gave no names. The comparison is
    by NAME, never by count: a delivery that fixes one failing test of the baseline and breaks another has the same count and a regression."""
    names = sub.get("failed_tests")
    if names is None:
        return None
    if not (isinstance(names, list) and all(isinstance(n, str) for n in names)):
        die("the delivery's failed_tests is not a list of test names")
    return sorted(set(names) - set(base.get("failed_tests") or []))


def broken_groups(mech, groups_doc):
    """{group id: [failed test names]} of the groups with a test that failed in the delivery and not in the pristine instance.
    A delivery that does not build is one regression, `BUILD`, never one per test."""
    char = mech.get("characterization") or {}
    sub, base = char.get("submission") or {}, char.get("baseline") or {}
    if sub.get("build_failed"):
        return {"BUILD": list(sub.get("failed_tests") or [])}
    failed = sub.get("failed") or 0
    new = newly_failed(base, sub)
    if new is None:
        if failed:
            die("a characterization test failed and the runner did not name it: the one-charge rule needs the names (failed_tests)")
        return {}
    if failed and not sub["failed_tests"]:
        die("the runner counts %d failed characterization test(s) and names none: the one-charge rule needs the names (failed_tests)" % failed)
    if not failed and sub["failed_tests"]:
        die("the runner names failed characterization tests but counts none (failed = 0): the report contradicts itself")
    group_of = {t: g["id"] for g in groups_doc["groups"] for t in g["tests"]}
    out = {}
    for name in new:
        if name not in group_of:
            die("the failed test %s is in no regression group of the instance" % name)
        out.setdefault(group_of[name], []).append(name)
    return out


def main():
    ap = argparse.ArgumentParser(description="Scorer of the new series (docs/new-series-evaluator.md)")
    ap.add_argument("--track", required=True)
    ap.add_argument("--matrix", required=True)
    ap.add_argument("--groups", required=True)
    ap.add_argument("--mechanical", required=True)
    ap.add_argument("--judge", required=True, help="the settled verdict (scoring/judge.schema.json)")
    ap.add_argument("--outcome", default="completed", choices=OUTCOMES)
    ap.add_argument("--cost", help="JSON with the cost_time block (optional)")
    ap.add_argument("--out")
    a = ap.parse_args()

    track = load_track(a.track)
    weights = track["weights"]
    categories_scored = [c for c in weights if c not in ("COMP", "EXPL")]
    matrix, mech, judge, groups_doc = v1.load(a.matrix), v1.load(a.mechanical), v1.load(a.judge), v1.load(a.groups)
    if mech.get("inconclusive"):
        die("the mechanical report is INCONCLUSIVE (%s): there is no measurement to score" % mech.get("inconclusive_reason", "no reason"))

    # ---- the inputs, before any arithmetic: a score from the wrong documents looks exactly like a right one
    with open(os.path.join(os.path.dirname(HERE), "scoring", "judge.schema.json"), encoding="utf-8") as f:
        schema_errors = validate(judge, json.load(f))
    problems = (verdict_check.identity_problems(matrix, verdict_check.file_sha256(a.matrix), mech, judge, groups_doc)
                + verdict_check.settled_problems(judge)
                + ["the verdict does not follow scoring/judge.schema.json: " + e for e in schema_errors]
                + verdict_check.structure_problems(judge, matrix))
    if problems:
        die("the inputs cannot be scored:\n  - " + "\n  - ".join(problems))

    planted = {e["id"]: e for e in matrix["entries"] if e.get("exists")}
    iscas = {e["id"] for e in matrix["entries"] if not e.get("exists")}
    for fid in planted:
        if v1.prefix(fid) not in categories_scored:
            die("the row %s is of a category the track %s does not weigh (%s)" % (fid, a.track, ", ".join(categories_scored)))

    # ---- the mechanical evidence (as in score.py): probes decide C3, structural evidence is a ceiling for R3
    probe_c3 = {p["id"]: bool(p["corrigida"]) for p in mech.get("probes", []) if "corrigida" in p}
    probe_evidence_r3 = {}
    for p in mech.get("probes", []):
        proves = p.get("proves")
        if proves is None:
            continue
        bad = [x for x in proves if x not in ("C3", "R3")]
        if bad:
            die("the probe %s claims to prove %s: mechanical evidence can only prove C3 or R3" % (p["id"], bad))
        if "R3" in proves:
            if p.get("result") not in ("full", "half", "none"):
                die("the probe %s claims R3 without a `result` of full|half|none" % p["id"])
            probe_evidence_r3[p["id"]] = p["result"]
    informative_affected = mech.get("informative_affected") or {}
    judge_planted = {p["id"]: p for p in judge.get("planted", [])}

    # ---- stage A: the verdict of each criterion of each row, before any regression is charged
    rows = {}
    for fid, entry in planted.items():
        tpl, sev = entry["template"], entry["severity"]
        crit = dict((judge_planted.get(fid) or {}).get("criteria", {}))
        if tpl == "C" and fid in probe_c3:
            crit["C3"] = "full" if probe_c3[fid] else "none"
        evidence = None
        if tpl == "R" and fid in probe_evidence_r3:
            order = {"none": 0, "half": 1, "full": 2}
            judged_r3 = crit.get("R3", "none")
            applied = min(judged_r3, probe_evidence_r3[fid], key=order.get)
            evidence = {"R3": probe_evidence_r3[fid], "judge_R3": judged_r3, "applied_R3": applied}
            crit["R3"] = applied
        fix_key = "C3" if tpl == "C" else "R3"
        rows[fid] = {"template": tpl, "severity": sev, "crit": crit, "evidence": evidence, "attempted": crit.get(fix_key) in ("full", "half"),
                     "base": v1.CRIT[tpl][sev]}

    # ---- stage B: the regressions, charged once each
    broken = broken_groups(mech, groups_doc)
    attribution = {}
    for r in judge.get("regressions", []):
        if r["group"] not in broken:
            die("the verdict attributes a regression to the group %s, which the mechanical stage did not find broken" % r["group"])
        if r["group"] in attribution:
            die("the verdict attributes the group %s twice" % r["group"])
        if r.get("caused_by") is not None and r["caused_by"] not in planted:
            die("the verdict attributes the group %s to %s, which is not a planted row" % (r["group"], r["caused_by"]))
        if r["group"] == "BUILD" and r.get("caused_by") is not None:
            die("a build failure is one unattributed regression (docs/new-series-evaluator.md section 5): the verdict attributes BUILD to %s" % r["caused_by"])
        attribution[r["group"]] = r.get("caused_by")
    regressions, unattributed, charged_rows = [], [], set()
    amount = track["pen002_per_regression_group"]
    for gid in sorted(broken):
        cause = attribution.get(gid)
        row = rows.get(cause)
        # Attributed only when the row's fix was attempted and its quality criterion (C4 mechanical, R4 judged) would otherwise earn points:
        # a charge that falls on nothing is no charge.
        earns = row is not None and row["attempted"] and (row["template"] == "C" or row["crit"].get("R4", "none") != "none")
        if earns:
            charged_rows.add(cause)
            regressions.append({"group": gid, "tests_failed": len(broken[gid]), "charged_to": cause, "deduction": 0})
        else:
            unattributed.append(gid)
            regressions.append({"group": gid, "tests_failed": len(broken[gid]), "charged_to": None, "deduction": -amount})

    # ---- stage C: points, row by row
    findings, cat_earned, cat_possible = [], {c: 0 for c in categories_scored}, {c: 0 for c in categories_scored}
    diff_stat, dim_stat, calib = {}, {}, []
    for fid, entry in planted.items():
        row, tpl = rows[fid], rows[fid]["template"]
        crit = row["crit"]
        cat, diff = v1.prefix(fid), entry.get("difficulty", "?")
        possible = sum(row["base"].values())
        cat_possible[cat] += possible
        diff_stat.setdefault(diff, {"planted": 0, "detected": 0, "corrected": 0})["planted"] += 1
        if tpl == "C":
            crit["C4"] = "full" if (row["attempted"] and fid not in charged_rows) else "none"
            if not row["attempted"]:
                crit["C5"] = "none"
        else:
            if not row["attempted"] or fid in charged_rows:
                crit["R4"] = "none"
        crit_pts = {k: v1.verdict_points(b, crit.get(k, "none")) for k, b in row["base"].items()}
        earned = sum(crit_pts.values())
        cat_earned[cat] += earned
        jv = judge_planted.get(fid, {})
        found_key, fix_key = ("C1", "C3") if tpl == "C" else ("R1", "R3")
        detected, corrected = crit.get(found_key) in ("full", "half"), crit.get(fix_key) == "full"
        diff_stat[diff]["detected"] += 1 if detected else 0
        diff_stat[diff]["corrected"] += 1 if corrected else 0
        for dim in entry.get("dimensions", []):
            d = dim_stat.setdefault(dim, {"planted": 0, "detected": 0, "corrected": 0})
            d["planted"] += 1
            d["detected"] += 1 if detected else 0
            d["corrected"] += 1 if corrected else 0
        if jv.get("reported") and jv.get("confidence") is not None:
            calib.append((jv["confidence"], 1))
        findings.append({
            "id": fid, "template": tpl, "severity": row["severity"], "difficulty": diff, "criteria": crit_pts,
            "points_earned": earned, "points_possible": possible, "confidence": jv.get("confidence"), "reported": bool(jv.get("reported")),
            **({"evidence": row["evidence"]} if row["evidence"] else {}),
            **({"dimensions": entry["dimensions"]} if entry.get("dimensions") else {}),
            **({"informative_affected": entry.get("informative_affected") or informative_affected[fid]}
               if (entry.get("informative_affected") or informative_affected.get(fid)) else {}),
        })

    fp = judge.get("false_positives", [])
    pen004 = sum(1 for f in fp if f.get("is_isca") or f.get("reported_as") in iscas)
    decoy_kind = {e["id"]: e["decoy_kind"] for e in matrix["entries"] if not e.get("exists") and e.get("decoy_kind")}
    for f in fp:
        if f.get("confidence") is not None:
            calib.append((f["confidence"], 0))

    categories = {}
    for c in categories_scored:
        poss, earn, w = cat_possible[c], cat_earned[c], weights[c]
        categories[c] = {"raw_earned": earn, "raw_possible": poss, "score": round(earn / poss * w) if poss else 0, "max": w}
    comp_v = judge.get("comp_violations", [])
    comp_ded = sum(v1.COMP_DEDUCTION.get(v["id"], 0) * int(v.get("count", 1)) for v in comp_v)
    categories["COMP"] = {"score": max(0, weights["COMP"] - comp_ded), "max": weights["COMP"],
                          "violations": [{"id": v["id"], "count": int(v.get("count", 1)),
                                          "deduction": -v1.COMP_DEDUCTION.get(v["id"], 0) * int(v.get("count", 1)), "detail": v.get("detail", "")}
                                         for v in comp_v]}
    rub = judge.get("expl_rubric", {})
    dims = ("clareza", "precisao", "causa_raiz", "priorizacao", "trade_offs")
    expl = sum(int(rub.get(k, 0)) for k in dims)
    categories["EXPL"] = {"score": expl, "max": weights["EXPL"], "rubric": {k: int(rub.get(k, 0)) for k in dims}}

    jp = judge.get("penalties", {})
    penalties = []
    if unattributed:
        penalties.append({"id": "PEN-002", "count": len(unattributed), "deduction": -amount * len(unattributed),
                          "detail": "regression group(s) no fix of a planted row caused: " + ", ".join(unattributed)})
    for pid, per in (("PEN-001", 15), ("PEN-003", 25)):
        n = int(jp.get(pid, 0))
        if n:
            penalties.append({"id": pid, "count": n, "deduction": -per * n})
    if pen004:
        penalties.append({"id": "PEN-004", "count": pen004, "deduction": -min(25, 5 * pen004), "detail": "decoy(s) reported"})
    total = sum(categories[c]["score"] for c in categories) + sum(p["deduction"] for p in penalties)
    total = max(0, min(1000, total))
    grade = ("Platinum" if total >= 900 else "Gold" if total >= 750 else "Silver" if total >= 600 else "Bronze" if total >= 400 else "Reprovada")

    card = {
        "leb_spec": matrix.get("leb_spec"), "scorer": SCORER, "track": track["track"], "outcome": a.outcome,
        "model": judge.get("model", {"name": "?", "version": "?"}),
        "instance": f'{matrix.get("instance")} v{matrix.get("version")}', "matrix_sha256": mech.get("matrix_sha256"),
        "protocol": judge.get("protocol", {"mode": "A", "date": judge.get("date", "?"), "runs": [total, total, total]}),
        "findings": findings, "categories": categories, "regressions": regressions, "penalties": penalties,
        "extra_findings": judge.get("extra_findings", []), "total": total, "grade": grade,
    }
    calibration = v1.calibration_block(calib)
    if calibration:
        card["calibration"] = calibration
    card["difficulty_breakdown"] = v1.difficulty_block(diff_stat)
    if dim_stat:
        card["dimension_breakdown"] = [{"dimension": d, **dim_stat[d]} for d in sorted(dim_stat)]
    if decoy_kind:
        reported = {f.get("reported_as") for f in fp if f.get("reported_as") in iscas}
        card["decoy_breakdown"] = [{"kind": k, "decoys": sum(1 for v in decoy_kind.values() if v == k),
                                    "reported": sum(1 for i, v in decoy_kind.items() if v == k and i in reported)} for k in sorted(set(decoy_kind.values()))]
    if judge.get("review"):
        card["review"] = judge["review"]
    if a.cost:
        card["cost_time"] = v1.load(a.cost)
    elif judge.get("cost_time"):
        card["cost_time"] = judge["cost_time"]

    out = json.dumps(card, ensure_ascii=False, indent=2)
    if a.out:
        with open(a.out, "w", encoding="utf-8") as f:
            f.write(out + "\n")
        print(f"[score_v2] scorecard → {a.out}  (TOTAL {total}/1000 · {grade} · track {track['track']} · {len(broken)} regression group(s))", file=sys.stderr)
    else:
        print(out)


if __name__ == "__main__":
    main()
