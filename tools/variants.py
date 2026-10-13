#!/usr/bin/env python3
"""variants.py — checks an instance's verifiers against control variants: alternative fixes must be accepted, partial or wrong
fixes rejected, decoys kept or broken as each case says. A verifier that only knows the planted tree and the reference has not
shown it can tell a fix from a near miss.

The instance declares, in private/variants/:

    config.py       REFERENCE (the reference's folder, relative to the repository), REFERENCE_ADDS ({path: text} added to it, for
                    what a fix must carry that the reference keeps elsewhere, e.g. a migration), SRC (the prefix of the main
                    sources), SERIAL (ids whose verifiers measure time: never run in parallel), and EVALUATE (the evaluator, relative
                    to the repository, called as `EVALUATE verify INSTANCE TREE SELECTOR`)
    cases_*.py      CASES = [dict(...)], each case:
        target      the matrix id whose verifier judges it
        name        a short name, unique
        kind        "alternative" (a fix done another way), "partial" or "wrong" (not a fix), "decoy" (a change to a decoy)
        expect      fixed | not_fixed | half | holds | broken
        base        "legacy" (public/code, the default) or "reference"
        edits       [(path, old, new)] exact, `old` once; or [(path, fn)] with fn(text) -> text. A path is under SRC, or under the
                    tree's root when it starts with "/"
        files       {path under the root: text}, new files
        delete      [path under SRC], removed
        note        why the case exists

    python3 tools/variants.py INSTANCE                    every case; writes private/variants/results.tsv
    python3 tools/variants.py INSTANCE --only ID|NAME ... some cases; prints only
    python3 tools/variants.py INSTANCE --list             the cases, without running them
    options: --parallel N (default 1), --characterize (also run the public tests on every alternative that should pass)

Generalized from LEB-300-A's own engine (instances/LEB-300-A/private/variantes/motor.py), which stays with that instance. Stdlib
only; the evaluator needs Docker."""

import argparse
import concurrent.futures
import glob
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXPECTATIONS = {"fixed", "not_fixed", "half", "holds", "broken"}


def load(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_cases(instance):
    folder = os.path.join(instance, "private", "variants")
    config = load(os.path.join(folder, "config.py"), "variants_config")
    cases, names = [], set()
    for path in sorted(glob.glob(os.path.join(folder, "cases_*.py"))):
        for c in load(path, os.path.basename(path)[:-3]).CASES:
            c = dict({"base": "legacy", "edits": [], "files": {}, "delete": [], "note": ""}, **c)
            if c["name"] in names:
                sys.exit("[variants] case named twice: %s" % c["name"])
            if c["expect"] not in EXPECTATIONS:
                sys.exit("[variants] %s: unknown expectation %r" % (c["name"], c["expect"]))
            names.add(c["name"])
            cases.append(c)
    return config, cases


def selector(matrix, target):
    for e in matrix["entries"]:
        if e["id"] == target:
            path, method = e["verify"].split("#")
            return "%s#%s" % (os.path.basename(path)[:-5], method)
    sys.exit("[variants] %s is not in the matrix" % target)


def resolve(config, path):
    return path[1:] if path.startswith("/") else config.SRC + path


def assemble(instance, config, case, tree):
    """The case's code folder: src/ and migrations/ of its base, with its edits."""
    if case["base"] == "legacy":
        base = os.path.join(instance, "public", "code")
    else:
        base = os.path.join(ROOT, config.REFERENCE)
    shutil.copytree(os.path.join(base, "src"), os.path.join(tree, "src"))
    if os.path.isdir(os.path.join(base, "migrations")):
        shutil.copytree(os.path.join(base, "migrations"), os.path.join(tree, "migrations"))
    if case["base"] == "reference":
        for path, text in config.REFERENCE_ADDS.items():
            write(os.path.join(tree, path), text)
    for edit in case["edits"]:
        full = os.path.join(tree, resolve(config, edit[0]))
        with open(full, encoding="utf-8") as f:
            text = f.read()
        if callable(edit[1]):
            new = edit[1](text)
            if new == text:
                raise ValueError("%s: the edit of %s changed nothing" % (case["name"], edit[0]))
        else:
            if text.count(edit[1]) != 1:
                raise ValueError("%s: %s: expected one match, found %d of %r" % (case["name"], edit[0], text.count(edit[1]), edit[1][:70]))
            new = text.replace(edit[1], edit[2])
        with open(full, "w", encoding="utf-8") as f:
            f.write(new)
    for path in case["delete"]:
        os.remove(os.path.join(tree, resolve(config, path)))
    for path, text in case["files"].items():
        write(os.path.join(tree, path), text)


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)


def observed(output, target):
    """What the evaluator said about the target: fixed | not_fixed | half | holds | broken | inconclusive | no verdict, or
    "does not compile", which no case expects: a case that does not build proves nothing about its verifier."""
    if output.get("build_failed"):
        return "does not compile", "the case's code does not compile with the instance's build and public tests"
    for p in output.get("probes", []):
        if p["id"] == target:
            return {"full": "fixed", "none": "not_fixed", "half": "half"}[p["result"]], p.get("msg", "")
    for d in output.get("decoys", []):
        if d["id"] == target:
            return ("holds" if d["holds"] else "broken"), d.get("msg", "")
    for i in output.get("inconclusive", []):
        if i["id"] == target:
            return "inconclusive", i.get("msg", "")
    return "no verdict", ""


def evaluate(config, mode, instance, tree, *extra):
    r = subprocess.run([os.path.join(ROOT, config.EVALUATE), mode, instance, tree, *extra], capture_output=True, text=True)
    try:
        return json.loads(r.stdout), r.stderr
    except ValueError:
        return None, r.stderr


def run(instance, config, matrix, case, characterize):
    with tempfile.TemporaryDirectory(prefix="leb-variant.") as tmp:
        tree = os.path.join(tmp, "code")
        try:
            assemble(instance, config, case, tree)
        except (ValueError, OSError) as e:
            return "case does not apply", str(e), None
        output, err = evaluate(config, "verify", instance, tree, selector(matrix, case["target"]))
        if output is None:
            return "evaluator failed", err.strip()[-300:], None
        seen, msg = observed(output, case["target"])
        public = None
        if characterize and case["expect"] in ("fixed", "holds"):
            c, _ = evaluate(config, "characterize", instance, tree)
            public = (-1, -1) if c is None or c.get("build_failed") else (c["passed"], c["failed"])
        return seen, msg, public


def main():
    ap = argparse.ArgumentParser(description="checks an instance's verifiers against control variants")
    ap.add_argument("instance")
    ap.add_argument("--only", nargs="+", help="matrix ids or case names")
    ap.add_argument("--list", action="store_true")
    ap.add_argument("--parallel", type=int, default=1)
    ap.add_argument("--characterize", action="store_true", help="also run the public tests on the cases that should pass")
    a = ap.parse_args()
    instance = os.path.abspath(a.instance)
    config, cases = load_cases(instance)
    with open(os.path.join(instance, "private", "matrix.json"), encoding="utf-8") as f:
        matrix = json.load(f)
    if a.only:
        cases = [c for c in cases if c["target"] in a.only or c["name"] in a.only]
    if a.list:
        for c in cases:
            print("%-11s %-12s %-10s %s" % (c["target"], c["kind"], c["expect"], c["name"]))
        return
    lock = threading.Lock()
    results = []

    def one(case):
        seen, msg, counts = run(instance, config, matrix, case, a.characterize)
        public = "" if counts is None else "unreadable" if counts[0] < 0 else "public tests %d passed, %d failed" % counts
        # an alternative that breaks the public tests is not an alternative fix: the case fails even if its verifier accepts it
        ok = seen == case["expect"] and (counts is None or (counts[0] >= 0 and counts[1] == 0))
        with lock:
            results.append((case, seen, ok, public, msg))
            print("%s %-11s %-12s expected=%-10s observed=%-12s %s%s" % ("ok" if ok else "XX", case["target"], case["kind"], case["expect"], seen,
                                                                     case["name"], "  [%s]" % public if public else ""), flush=True)
            if not ok:
                print("     " + msg[:400], flush=True)

    serial = [c for c in cases if c["target"] in config.SERIAL]
    others = [c for c in cases if c["target"] not in config.SERIAL]
    with concurrent.futures.ThreadPoolExecutor(max_workers=max(1, a.parallel)) as pool:
        list(pool.map(one, others))
    for c in serial:
        one(c)
    bad = [r for r in results if not r[2]]
    print("%d cases, %d unexpected" % (len(results), len(bad)))
    if not a.only:
        order = {c["name"]: i for i, c in enumerate(cases)}
        lines = ["target\tkind\tname\texpected\tobserved\tok\tpublic tests\tmessage"]
        for case, seen, ok, public, msg in sorted(results, key=lambda r: order[r[0]["name"]]):
            lines.append("\t".join([case["target"], case["kind"], case["name"], case["expect"], seen, "ok" if ok else "XX", public,
                                    msg.replace("\t", " ").replace("\n", " ")]))
        write(os.path.join(instance, "private", "variants", "results.tsv"), "\n".join(lines) + "\n")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
