"""verdict_check.py — what the new series' scorer (harness/score_v2.py) and the review tool (tools/judge_review.py) check on their inputs
before they do any arithmetic. The scorer is a deterministic function of three documents; a wrong document must stop it, because a score
computed from the wrong inputs looks exactly like a right one.

Three groups of checks, each returning a list of problems (an empty list is a pass):

  identity    the key (matrix.json), the mechanical report and the verdict are about the same matrix: the report's and the verdict's
              `matrix_sha256` are the SHA-256 of the matrix file the scorer was given, and the instance names agree;
  settled     the verdict went through the review (docs/new-series-evaluator.md section 4): two judges, and every disagreement decided by
              a person, with the reason. A single judge's verdict is never a score;
  structure   what the schema leaves open: criteria named C1..C5 / R1..R4 of the row's own template, no `half` on a binary criterion
              (C4, C5, R4), no row or group twice, no COMP rule the SPEC does not know.

Standard library only."""

import hashlib
import re

ROW_ID = re.compile(r"^(SEC|ARCH|PERF|BUG|CLN)-\d{3}(\.[a-z])?$")
CRITERIA = {"C": ("C1", "C2", "C3", "C4", "C5"), "R": ("R1", "R2", "R3", "R4")}
BINARY = ("C4", "C5", "R4")
VALUES = ("full", "half", "none")
COMP_RULES = tuple("COMP-%03d" % n for n in range(1, 11))  # SPEC section 6.1


def file_sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def instance_label(matrix):
    return "%s v%s" % (matrix.get("instance"), matrix.get("version"))


def identity_problems(matrix, matrix_sha256, mech, verdict, groups_doc=None):
    """The three documents belong to the matrix file the scorer was given. `matrix_sha256` is that file's SHA-256."""
    problems = []
    for label, doc, required in (("mechanical report", mech, True), ("verdict", verdict, True)):
        got = doc.get("matrix_sha256")
        if got is None:
            if required:
                problems.append("the %s carries no matrix_sha256: it cannot be tied to this matrix" % label)
        elif got != matrix_sha256:
            problems.append("the %s was made against another matrix (its matrix_sha256 %s…, this matrix %s…)" % (label, str(got)[:12], matrix_sha256[:12]))
    expected = instance_label(matrix)
    for label, doc in (("mechanical report", mech), ("verdict", verdict)):
        got = doc.get("instance")  # optional: the judges write the bare name or nothing; the hash above is the binding check
        if got is not None and got not in (expected, matrix.get("instance")):
            problems.append("the %s is about %r, the matrix is %r" % (label, got, expected))
    if groups_doc is not None:
        group_instance = "%s v%s" % (groups_doc.get("instance"), groups_doc.get("version"))
        if groups_doc.get("instance") is not None and group_instance != expected:
            problems.append("the regression groups are those of %r, the matrix is %r" % (group_instance, expected))
    return problems


def settled_problems(verdict):
    """The verdict is the output of the review: two judges at least, every disagreement decided by a person with a reason."""
    review = verdict.get("review")
    if not isinstance(review, dict):
        return ["the verdict was not settled: it has no `review` block (tools/judge_review.py settle builds it from two judges' verdicts, "
                "and a person decides every disagreement)"]
    problems = []
    if not (isinstance(review.get("judges"), int) and review["judges"] >= 2):
        problems.append("the review names fewer than two judges")
    disagreements = review.get("disagreements")
    if not isinstance(disagreements, list):
        return problems + ["the review has no list of disagreements"]
    for d in disagreements:
        item = d.get("item", "?") if isinstance(d, dict) else "?"
        if not isinstance(d, dict) or "decision" not in d or d.get("decision") is None and not _may_be_null(item):
            problems.append("the disagreement %s has no decision" % item)
        elif not str(d.get("reason") or "").strip() or not str(d.get("decided_by") or "").strip():
            problems.append("the disagreement %s was not decided by a person with a reason" % item)
    return problems


def _may_be_null(item):
    """`null` is a decision for some items (no attribution, not a false positive, no confidence)."""
    return item.startswith(("false_positive:", "regression:")) or item.endswith(".confidence")


def structure_problems(verdict, matrix=None):
    """What the schema does not say. With the matrix, rows must be planted rows and criteria must belong to the row's template."""
    problems = []
    planted_entries = {}
    if matrix is not None:
        planted_entries = {e["id"]: e for e in matrix["entries"] if e.get("exists")}
    seen = set()
    for p in verdict.get("planted", []):
        fid = p.get("id")
        if fid in seen:
            problems.append("the row %s appears twice in the verdict" % fid)
        seen.add(fid)
        if matrix is not None and fid not in planted_entries:
            problems.append("the verdict judges %s, which is not a planted row of the matrix" % fid)
        template = planted_entries.get(fid, {}).get("template")
        allowed = CRITERIA.get(template) if template else CRITERIA["C"] + CRITERIA["R"]
        criteria = p.get("criteria") or {}
        for name, value in criteria.items():
            if name not in allowed:
                problems.append("%s has the criterion %r, which its template does not have (%s)" % (fid, name, ", ".join(allowed)))
            elif value not in VALUES:
                problems.append("%s.%s is %r: full, half or none" % (fid, name, value))
            elif name in BINARY and value == "half":
                problems.append("%s.%s is binary (full or none), not half" % (fid, name))
        if template is None and criteria and not (set(criteria) <= set(CRITERIA["C"]) or set(criteria) <= set(CRITERIA["R"])):
            problems.append("%s mixes C and R criteria" % fid)
    for key, label, field in (("false_positives", "false positive", "reported_as"), ("regressions", "regression group", "group")):
        names = [x.get(field) for x in verdict.get(key, [])]
        for n in sorted({n for n in names if names.count(n) > 1}, key=str):
            problems.append("the %s %s appears twice in the verdict" % (label, n))
    for v in verdict.get("comp_violations", []):
        if v.get("id") not in COMP_RULES:
            problems.append("the COMP rule %r is not one of the SPEC's (%s…%s)" % (v.get("id"), COMP_RULES[0], COMP_RULES[-1]))
    extras = verdict.get("extra_findings", [])
    if not (isinstance(extras, list) and all(isinstance(x, str) and x.strip() for x in extras)):
        problems.append("extra_findings must be a list of non-empty texts")
    return problems
