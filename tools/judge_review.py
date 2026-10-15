#!/usr/bin/env python3
"""judge_review.py — two judges' verdicts in, their disagreements out, and one settled verdict once a person has decided each one
(docs/new-series-evaluator.md section 4).

Each delivery is judged twice, independently, by judges that never saw each other's verdict. What both say is kept as it is. Every
place where they differ is listed, and the settled verdict is built only when a person has decided each of them, with the reason:
a disagreement never takes the average and an agreement is never reopened. The one exception is an EXPL dimension (a 0-10 rubric)
at most two points apart: that is noise on an ordinal scale, not a disagreement, and the settled value is the mean rounded down.

    python3 tools/judge_review.py compare A.json B.json [--out disagreements.json]
        lists the disagreements and writes them with empty decision slots, and the SHA-256 of the two verdict files; the person fills
        `decision`, `reason`, `decided_by` and leaves everything else as it is (a decision is bound to those two files and to the values
        the judges gave: a file made for other verdicts, or edited since, settles nothing)
    python3 tools/judge_review.py settle A.json B.json [--decisions disagreements.json] --out settled.json
        refuses while a disagreement has no decision (or the decisions name an item that is not a disagreement); otherwise writes the
        settled verdict, validated against scoring/judge.schema.json, with the `review` block that scorecard v2 carries

The items a disagreement can be about, and the shape of the decision for each:

    <ROW>.reported                        true | false
    <ROW>.confidence                      0-100 | null
    <ROW>.C1 ... <ROW>.R4                 "full" | "half" | "none"
    false_positive:<reported_as>          null (not a false positive) | {"is_isca": bool, "confidence": 0-100 | null}
    comp:COMP-001                         the number of occurrences (0 = no violation)
    PEN-001, PEN-003                      the count
    regression:<group>                    the planted row whose fix broke the group, or null (no fix did)
    EXPL.clareza ... EXPL.trade_offs      0-10

Standard library only."""

import argparse
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "harness"))
from jsonschema_lite import validate  # noqa: E402
import verdict_check  # noqa: E402

SCHEMA = os.path.join(ROOT, "scoring", "judge.schema.json")
VERDICTS = ("full", "half", "none")
RUBRIC = ("clareza", "precisao", "causa_raiz", "priorizacao", "trade_offs")
EXPL_TOLERANCE = 2
ROW_ID = re.compile(r"^(SEC|ARCH|PERF|BUG|CLN)-\d{3}(\.[a-z])?$")


def die(message):
    sys.exit("[judge_review] " + message)


def load_verdict(path):
    with open(path, encoding="utf-8") as f:
        verdict = json.load(f)
    with open(SCHEMA, encoding="utf-8") as f:
        errors = validate(verdict, json.load(f))
    if errors:
        die("%s is not a valid verdict: %s" % (path, "; ".join(errors[:5])))
    problems = verdict_check.structure_problems(verdict)
    if problems:
        die("%s is not a valid verdict: %s" % (path, "; ".join(problems[:5])))
    return verdict


def criterion_key(k):
    return (k[0], int(k[1:]))


# ---- the positions of one verdict: item -> value ------------------------------------------------------------------------------

def positions(verdict):
    """Everything a judge decided, as {item: value}. What a judge left out is the neutral value (not reported, `none`, no violation,
    no attribution), so a row or a criterion one judge skipped is compared, not ignored."""
    pos = {}
    for p in verdict.get("planted", []):
        fid = p["id"]
        pos[fid + ".reported"] = bool(p.get("reported"))
        pos[fid + ".confidence"] = p.get("confidence")
        for k, v in (p.get("criteria") or {}).items():
            pos[fid + "." + k] = v
    for f in verdict.get("false_positives", []):
        pos["false_positive:" + f["reported_as"]] = {"is_isca": bool(f.get("is_isca")), "confidence": f.get("confidence")}
    counts = {}
    for v in verdict.get("comp_violations", []):
        counts[v["id"]] = counts.get(v["id"], 0) + int(v.get("count", 1))
    for cid, n in counts.items():
        pos["comp:" + cid] = n
    for pid in ("PEN-001", "PEN-003"):
        pos[pid] = int((verdict.get("penalties") or {}).get(pid, 0))
    for r in verdict.get("regressions", []):
        pos["regression:" + r["group"]] = r.get("caused_by")
    for k, v in (verdict.get("expl_rubric") or {}).items():
        pos["EXPL." + k] = int(v)
    return pos


def neutral(item):
    if item.endswith(".reported"):
        return False
    if item.endswith(".confidence") or item.startswith(("false_positive:", "regression:")):
        return None
    if item.startswith(("comp:", "PEN-")):
        return 0
    if item.startswith("EXPL."):
        return 0
    return "none"


def row_and_field(item):
    """'ARCH-001.C3' -> ('ARCH-001', 'C3'); 'BUG-011.a.reported' -> ('BUG-011.a', 'reported'); anything else -> ('', item)."""
    row, _, field = item.rpartition(".")
    return (row, field) if ROW_ID.match(row) else ("", item)


def item_order(item):
    """A stable, readable order: the rows first (by id; reported, confidence, then the criteria), then the rest by kind."""
    row, field = row_and_field(item)
    if row:
        rank = (0, 0) if field == "reported" else (1, 0) if field == "confidence" else (2,) + criterion_key(field)
        return (0, row, rank)
    kind = ("false_positive:", "comp:", "PEN-", "regression:", "EXPL.")
    return (1 + next((i for i, k in enumerate(kind) if item.startswith(k)), len(kind)), item, (0, 0))


def compare(a, b):
    """The disagreements of two verdicts: [{item, judge_a, judge_b}] in a stable order, and the agreed positions {item: value}."""
    pa, pb = positions(a), positions(b)
    disagreements, agreed = [], {}
    for item in sorted(set(pa) | set(pb), key=item_order):
        va, vb = pa.get(item, neutral(item)), pb.get(item, neutral(item))
        if item.startswith("EXPL."):
            if abs(va - vb) > EXPL_TOLERANCE:
                disagreements.append({"item": item, "judge_a": va, "judge_b": vb})
            else:
                agreed[item] = (va + vb) // 2
        elif va == vb:
            agreed[item] = va
        else:
            disagreements.append({"item": item, "judge_a": va, "judge_b": vb})
    return disagreements, agreed


# ---- decisions --------------------------------------------------------------------------------------------------------------

def check_decision(item, value):
    """Return an error string, or None when `value` is a possible decision for `item`."""
    def is_int(x, lo=0, hi=None):
        return isinstance(x, int) and not isinstance(x, bool) and x >= lo and (hi is None or x <= hi)
    if item.startswith("EXPL."):
        return None if is_int(value, 0, 10) else "an EXPL dimension is an integer from 0 to 10"
    if item.startswith("false_positive:"):
        if value is None:
            return None
        ok = isinstance(value, dict) and isinstance(value.get("is_isca"), bool) and (value.get("confidence") is None or is_int(value["confidence"], 0, 100))
        return None if ok else 'null, or {"is_isca": bool, "confidence": 0-100 | null}'
    if item.startswith(("comp:", "PEN-")):
        return None if is_int(value) else "a count (an integer from 0)"
    if item.startswith("regression:"):
        return None if value is None or (isinstance(value, str) and ROW_ID.match(value)) else "a planted row id, or null"
    field = row_and_field(item)[1]
    if field == "reported":
        return None if isinstance(value, bool) else "true or false"
    if field == "confidence":
        return None if value is None or is_int(value, 0, 100) else "an integer from 0 to 100, or null"
    return None if value in VERDICTS else "full, half or none"


def decided(entry):
    return bool(str(entry.get("reason") or "").strip()) and bool(str(entry.get("decided_by") or "").strip())


def apply_decisions(disagreements, decisions_doc, verdict_hashes=None):
    """Join the disagreements with the person's decisions. Returns (review entries, settled positions, problems).

    A decision is bound to what it decided: the decisions file must say which two verdict files it was made for (their SHA-256, as `compare`
    wrote them), and each decision must carry the values the two judges gave for that item. A file made for other verdicts, or edited since,
    cannot settle these: matching by the name of the item alone would apply a decision to a disagreement it never saw."""
    by_item = {d["item"]: d for d in (decisions_doc or {}).get("disagreements", [])}
    problems = []
    if decisions_doc is not None and verdict_hashes is not None and decisions_doc.get("verdicts_sha256") != verdict_hashes:
        problems.append("the decisions file was not made for these two verdicts (its verdicts_sha256 is %s, these files are %s): "
                        "run `compare --out` again and decide on its output" % (json.dumps(decisions_doc.get("verdicts_sha256")), json.dumps(verdict_hashes)))
    for item in by_item:
        if item not in {d["item"] for d in disagreements}:
            problems.append("the decisions name %s, which is not a disagreement of these two verdicts" % item)
    entries, settled = [], {}
    for d in disagreements:
        given = by_item.get(d["item"])
        if not given or not decided(given) or "decision" not in given:
            problems.append("no decision for %s (judge A: %s, judge B: %s)" % (d["item"], json.dumps(d["judge_a"]), json.dumps(d["judge_b"])))
            continue
        if "judge_a" not in given or "judge_b" not in given or given["judge_a"] != d["judge_a"] or given["judge_b"] != d["judge_b"]:
            problems.append("the decision for %s was made for other judge values (A: %s, B: %s); these verdicts say A: %s, B: %s" % (
                d["item"], json.dumps(given.get("judge_a")), json.dumps(given.get("judge_b")), json.dumps(d["judge_a"]), json.dumps(d["judge_b"])))
            continue
        error = check_decision(d["item"], given["decision"])
        if error:
            problems.append("the decision for %s is %s: it must be %s" % (d["item"], json.dumps(given["decision"]), error))
            continue
        entries.append({"item": d["item"], "judge_a": d["judge_a"], "judge_b": d["judge_b"], "decision": given["decision"],
                        "reason": given["reason"].strip(), "decided_by": given["decided_by"].strip()})
        settled[d["item"]] = given["decision"]
    return entries, settled, problems


# ---- the settled verdict ----------------------------------------------------------------------------------------------------

def first(a, b, key):
    return a.get(key) if a.get(key) is not None else b.get(key)


def excerpt(a, b, fid):
    for v in (a, b):
        for p in v.get("planted", []):
            if p["id"] == fid and p.get("report_excerpt"):
                return p["report_excerpt"]
    return None


def merge_extras(a, b, false_positives):
    """The real findings outside the matrix that either judge listed, once each (same words, any spacing or case), minus any the settled
    verdict calls a false positive: one finding is never both a thing that exists and a thing that does not."""
    def norm(text):
        return " ".join(text.split()).casefold()
    rejected = {norm(f["reported_as"]) for f in false_positives}
    out, seen = [], set()
    for text in list(a.get("extra_findings", [])) + list(b.get("extra_findings", [])):
        key = norm(text)
        if key not in seen and key not in rejected:
            seen.add(key)
            out.append(text)
    return out


def build(a, b, values, review):
    """The settled verdict from the settled position of every item."""
    rows = sorted({row_and_field(i)[0] for i in values if row_and_field(i)[0]}, key=lambda r: item_order(r + ".reported"))
    planted = []
    for fid in rows:
        reported = values.get(fid + ".reported", False)
        criteria = {field: v for i, v in values.items() for r, field in [row_and_field(i)]
                    if r == fid and field not in ("reported", "confidence")}
        row = {"id": fid, "reported": reported}
        if reported and values.get(fid + ".confidence") is not None:
            row["confidence"] = values[fid + ".confidence"]
        row["criteria"] = dict(sorted(criteria.items(), key=lambda kv: criterion_key(kv[0])))
        text = excerpt(a, b, fid)
        if text:
            row["report_excerpt"] = text
        planted.append(row)
    false_positives = []
    for item in sorted(i for i in values if i.startswith("false_positive:")):
        if values[item] is not None:
            fp = {"reported_as": item.split(":", 1)[1], "is_isca": values[item]["is_isca"]}
            if values[item]["confidence"] is not None:
                fp["confidence"] = values[item]["confidence"]
            false_positives.append(fp)
    comp = []
    for item in sorted(i for i in values if i.startswith("comp:")):
        if values[item] > 0:
            detail = next((v.get("detail") for src in (a, b) for v in src.get("comp_violations", []) if v["id"] == item[5:] and v.get("detail")), "")
            comp.append({"id": item[5:], "count": values[item], **({"detail": detail} if detail else {})})
    regressions = [{"group": i.split(":", 1)[1], "caused_by": values[i]} for i in sorted(values) if i.startswith("regression:")]
    extra = merge_extras(a, b, false_positives)
    settled = {
        "instance": first(a, b, "instance"), "matrix_sha256": first(a, b, "matrix_sha256"), "model": first(a, b, "model"),
        "protocol": first(a, b, "protocol"),
        "judge": {"type": "panel", "id": "%s + %s" % ((a.get("judge") or {}).get("id", "A"), (b.get("judge") or {}).get("id", "B"))},
        "planted": planted, "false_positives": false_positives, "extra_findings": extra, "comp_violations": comp,
        "penalties": {p: values[p] for p in ("PEN-001", "PEN-003") if values.get(p)},
        "regressions": regressions,
        "expl_rubric": {k: values.get("EXPL." + k, 0) for k in RUBRIC},
        "review": review,
    }
    for key in ("cost_time", "blind_label"):
        if first(a, b, key) is not None:
            settled[key] = first(a, b, key)
    return {k: v for k, v in settled.items() if v is not None}


def same_delivery(a, b):
    for key in ("instance", "matrix_sha256"):
        if a.get(key) != b.get(key):
            die("the two verdicts are not about the same delivery: %s differs (%r against %r)" % (key, a.get(key), b.get(key)))
    if (a.get("model") or {}) != (b.get("model") or {}):
        die("the two verdicts name different models: %r against %r" % (a.get("model"), b.get("model")))


def main():
    ap = argparse.ArgumentParser(description="Two judges' verdicts to one settled verdict (docs/new-series-evaluator.md section 4)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    for name in ("compare", "settle"):
        p = sub.add_parser(name)
        p.add_argument("judge_a")
        p.add_argument("judge_b")
        if name == "settle":
            p.add_argument("--decisions", help="the disagreements file, with the person's decision, reason and name filled in")
            p.add_argument("--out", required=True)
        else:
            p.add_argument("--out")
    a = ap.parse_args()

    va, vb = load_verdict(a.judge_a), load_verdict(a.judge_b)
    same_delivery(va, vb)
    disagreements, agreed = compare(va, vb)
    verdict_hashes = {"judge_a": verdict_check.file_sha256(a.judge_a), "judge_b": verdict_check.file_sha256(a.judge_b)}

    if a.cmd == "compare":
        doc = {"judges": 2, "verdicts_sha256": verdict_hashes,
               "disagreements": [dict(d, decision=None, reason="", decided_by="") for d in disagreements]}
        if a.out:
            with open(a.out, "w", encoding="utf-8") as f:
                json.dump(doc, f, ensure_ascii=False, indent=2)
                f.write("\n")
        for d in disagreements:
            print("%-28s A: %-12s B: %s" % (d["item"], json.dumps(d["judge_a"], ensure_ascii=False), json.dumps(d["judge_b"], ensure_ascii=False)))
        print("[judge_review] %d disagreement(s)%s" % (len(disagreements), (" → " + a.out) if a.out else ""), file=sys.stderr)
        return

    decisions = None
    if a.decisions:
        with open(a.decisions, encoding="utf-8") as f:
            decisions = json.load(f)
    entries, settled_positions, problems = apply_decisions(disagreements, decisions, verdict_hashes if decisions is not None else None)
    if problems:
        die("the verdict cannot be settled yet:\n  - " + "\n  - ".join(problems))
    values = dict(agreed)
    values.update(settled_positions)
    settled = build(va, vb, values, {"judges": 2, "disagreements": entries})
    with open(SCHEMA, encoding="utf-8") as f:
        errors = validate(settled, json.load(f))
    if errors:
        die("the settled verdict does not validate: " + "; ".join(errors[:5]))
    with open(a.out, "w", encoding="utf-8") as f:
        json.dump(settled, f, ensure_ascii=False, indent=2)
        f.write("\n")
    print("[judge_review] settled verdict → %s (%d disagreement(s) decided by a person)" % (a.out, len(entries)), file=sys.stderr)


if __name__ == "__main__":
    main()
