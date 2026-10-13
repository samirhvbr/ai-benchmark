#!/usr/bin/env python3
"""make_matrix.py — writes an instance's answer key, private/matrix.json and its readable private/matrix.md, from the rows the
instance declares and from its own files, so that no location and no verifier path is typed by hand.

The instance declares, in private/matrix_rows.py:

    HEADER          the matrix header (leb_spec, instance, level, version, language, task_version, ...)
    SRC             the prefix of the main sources inside code/ (e.g. "src/main/java/com/condodesk/")
    FLAWS           [dict(id, severity, difficulty, template, file, anchor, evidence, fix, notes="", **extra)]
    DECOYS          [dict(id, kind, file, anchor, looks, why)]
    SYMBOLS, ARTIFACTS    the Java surface and the produced files of the manifest, as text
    ROUTE_PATTERN   a regex whose two groups are the method and the path of a route, read in code/'s main sources

`file` is relative to SRC, or to code/ when it starts with "/" (e.g. "/schema.sql"). `anchor` is a regex: the location is the
first line of the file in public/code/ that matches it. The verifier of each id is the test method that writes its verdict
(`Verdict.fixed|structure|decoy|inconclusive("ID", ...)`) in private/verify/. Points follow the severity and the template
(Template C 10/8/6/5 and Template R 12/10/8/6 for Crítica/Alta/Média/Baixa, as LEB-300-A's generator).

    python3 tools/make_matrix.py INSTANCE_DIR            writes private/matrix.json and private/matrix.md
    python3 tools/make_matrix.py INSTANCE_DIR --check    builds them in memory and compares with the files

Generalized from LEB-300-A's own generator (instances/LEB-300-A/private/gerar_matriz.py), which stays with that instance as it
was. Stdlib only."""

import argparse
import glob
import importlib.util
import json
import os
import re
import sys

POINTS = {"C": {"Crítica": 10, "Alta": 8, "Média": 6, "Baixa": 5}, "R": {"Crítica": 12, "Alta": 10, "Média": 8, "Baixa": 6}}
VERDICT = re.compile(r'Verdict\.(fixed|structure|decoy|inconclusive)\("([A-Z]+-\d{3}(?:\.[a-z])?)"')
METHOD = re.compile(r"^\s+void (\w+)\(\)")


def load_rows(instance):
    path = os.path.join(instance, "private", "matrix_rows.py")
    if not os.path.isfile(path):
        sys.exit("[make_matrix] %s does not exist" % path)
    spec = importlib.util.spec_from_file_location("matrix_rows", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def verifiers(instance):
    """{id: "verify/<path>#<method>"}: the test method that writes each id's verdict. An id written by two methods is refused."""
    root = os.path.join(instance, "private")
    out = {}
    for path in sorted(glob.glob(os.path.join(root, "verify", "**", "*.java"), recursive=True)):
        method = None
        with open(path, encoding="utf-8") as f:
            for line in f:
                m = METHOD.match(line)
                if m:
                    method = m.group(1)
                for _, vid in VERDICT.findall(line):
                    where = "%s#%s" % (os.path.relpath(path, root), method)
                    if out.get(vid, where) != where:
                        sys.exit("[make_matrix] %s has its verdict written in two methods: %s and %s" % (vid, out[vid], where))
                    out[vid] = where
    return out


def code_path(rows, file):
    return file[1:] if file.startswith("/") else rows.SRC + file


def line_of(code, rows, file, anchor):
    path = os.path.join(code, code_path(rows, file))
    if not os.path.isfile(path):
        sys.exit("[make_matrix] no such file in public/code/: %s" % code_path(rows, file))
    with open(path, encoding="utf-8") as f:
        for n, line in enumerate(f.read().split("\n"), 1):
            if re.search(anchor, line):
                return n
    sys.exit("[make_matrix] anchor not found: %s /%s/" % (code_path(rows, file), anchor))


def public_surface(code, rows):
    sources = sorted(glob.glob(os.path.join(code, rows.SRC, "**", "*.java"), recursive=True))
    text = "".join(open(p, encoding="utf-8").read() for p in sources)
    routes = sorted({"%s %s" % m for m in re.findall(rows.ROUTE_PATTERN, text)})
    with open(os.path.join(code, "schema.sql"), encoding="utf-8") as f:
        schema = f.read()
    tables = []
    for name, body in re.findall(r"create table (\w+) \((.*?)\n\);", schema, re.S):
        columns = [l.split()[0] for l in body.strip().split("\n")
                   if l.strip() and not l.strip().startswith(("--", "primary", "unique", "foreign", "check"))]
        tables.append("%s(%s)" % (name, ",".join(columns)))
    return {"symbols": list(rows.SYMBOLS), "routes": routes, "db_schema": tables, "artifacts": list(rows.ARTIFACTS)}


def build(instance):
    rows = load_rows(instance)
    code = os.path.join(instance, "public", "code")
    verify = verifiers(instance)
    entries, seen = [], set()
    for f in rows.FLAWS:
        if f["id"] in seen:
            sys.exit("[make_matrix] id declared twice: %s" % f["id"])
        seen.add(f["id"])
        if f["id"] not in verify:
            sys.exit("[make_matrix] no verifier writes a verdict for %s" % f["id"])
        e = {"id": f["id"], "exists": True, "severity": f["severity"], "difficulty": f["difficulty"],
             "points": POINTS[f["template"]][f["severity"]], "template": f["template"],
             "location": "code/%s:%d" % (code_path(rows, f["file"]), line_of(code, rows, f["file"], f["anchor"])),
             "evidence": f["evidence"], "expected_fix": f["fix"], "verify": verify[f["id"]],
             "proves": ["C3"] if f["template"] == "C" else ["R3"]}
        if f.get("notes"):
            e["notes"] = f["notes"]
        e.update(f.get("extra", {}))
        entries.append(e)
    for d in rows.DECOYS:
        if d["id"] in seen:
            sys.exit("[make_matrix] id declared twice: %s" % d["id"])
        seen.add(d["id"])
        if d["id"] not in verify:
            sys.exit("[make_matrix] no verifier writes a verdict for %s" % d["id"])
        entries.append({"id": d["id"], "exists": False, "decoy_kind": d["kind"], "verify": verify[d["id"]],
                        "location": "code/%s:%d" % (code_path(rows, d["file"]), line_of(code, rows, d["file"], d["anchor"])),
                        "notes": "DECOY. Looks like %s. %s Reporting it is PEN-004; changing it so that the property breaks is a "
                                 "regression." % (d["looks"], d["why"])})
    extra = sorted(set(verify) - seen)
    if extra:
        sys.exit("[make_matrix] verifiers write verdicts for ids the rows do not declare: %s" % ", ".join(extra))
    matrix = dict(rows.HEADER)
    matrix["public_surface"] = public_surface(code, rows)
    matrix["entries"] = entries
    return matrix, readable(matrix)


def readable(matrix):
    planted = [e for e in matrix["entries"] if e["exists"]]
    decoys = [e for e in matrix["entries"] if not e["exists"]]
    lines = ["# %s matrix (private)" % matrix["instance"], "",
             "%d planted flaws, %d decoys. Generated by `tools/make_matrix.py` from `matrix_rows.py`; do not edit." % (len(planted), len(decoys)), "",
             "| ID | Exists | Severity | Difficulty | Points | Template | Location | Verifier |", "|---|---|---|---|---:|---|---|---|"]
    for e in matrix["entries"]:
        lines.append("| %s | %s | %s | %s | %d | %s | `%s` | `%s` |" % (
            e["id"], "yes" if e["exists"] else "**no (decoy)**", e.get("severity", "-"), e.get("difficulty", "-"), e.get("points", 0),
            e.get("template", "-"), e["location"], e["verify"]))
    by_category = {}
    for e in planted:
        by_category[e["id"].split("-")[0]] = by_category.get(e["id"].split("-")[0], 0) + e["points"]
    lines += ["", "Base points per category: " + ", ".join("%s %d" % kv for kv in sorted(by_category.items())), ""]
    return "\n".join(lines)


def main():
    ap = argparse.ArgumentParser(description="writes an instance's matrix from its declared rows and its own files")
    ap.add_argument("instance")
    ap.add_argument("--check", action="store_true", help="compare with private/matrix.json and matrix.md instead of writing them")
    a = ap.parse_args()
    instance = os.path.abspath(a.instance)
    matrix, md = build(instance)
    text = json.dumps(matrix, ensure_ascii=False, indent=2) + "\n"
    paths = (os.path.join(instance, "private", "matrix.json"), os.path.join(instance, "private", "matrix.md"))
    if a.check:
        stale = [os.path.basename(p) for p, want in zip(paths, (text, md))
                 if not os.path.isfile(p) or open(p, encoding="utf-8").read() != want]
        if stale:
            sys.exit("[make_matrix] out of date: %s (run tools/make_matrix.py %s)" % (", ".join(stale), a.instance))
        print("[make_matrix] %s: the matrix is what its rows and files give" % matrix["instance"])
        return
    for p, content in zip(paths, (text, md)):
        with open(p, "w", encoding="utf-8") as f:
            f.write(content)
    planted = [e for e in matrix["entries"] if e["exists"]]
    print("[make_matrix] %s: %d flaws, %d decoys, %d points" % (matrix["instance"], len(planted), len(matrix["entries"]) - len(planted),
                                                               sum(e["points"] for e in planted)))


if __name__ == "__main__":
    main()
