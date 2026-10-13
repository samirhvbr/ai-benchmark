#!/usr/bin/env python3
"""plant.py — plants an instance's flaws on a copy of its reference system. Generic: the profile names its own paths.

The reference is the correct state. A planting profile is a folder of `flaws_*.py` modules, loaded in name order, each with

    FLAWS = [(id, [(path, old, new), ...]), ...]    exact edits, applied in order
    POS   = [(id, fn(files, read)), ...]            optional; runs after every edit, may change `files` (path -> text)

and an edit means:

    old = "text"   `old` must occur exactly once in the file as the earlier edits left it, or the run stops
    old = None     create the file `path` with `new` (it must not exist)
    old = "*"      replace the whole file with `new`
    new = None     delete the file (with any `old` other than None)

The profile folder is put on sys.path, so its modules can share their paths through a module of their own. The output is the
legacy tree: the reference without `target/` and `.git/`, with the edits applied.

    python3 tools/plant.py PROFILE_DIR REFERENCE_DIR OUT_DIR [--only ID,ID]
    python3 tools/plant.py PROFILE_DIR REFERENCE_DIR --check [--only ID,ID]    # every edit applies; nothing is written

Generalized from LEB-300-A's own engine (instances/LEB-300-A/private/plant/plantar.py), which stays with that instance as it
was; tests/test_plant.py checks that this one plants LEB-300-A's legacy tree byte for byte. Stdlib only.
"""

import argparse
import glob
import importlib.util
import os
import shutil
import sys


def load(profile_dir):
    """The flaws in profile order, as {id: edits} and the list of ids, plus the post-hooks."""
    profile_dir = os.path.abspath(profile_dir)
    if profile_dir not in sys.path:
        sys.path.insert(0, profile_dir)
    flaws, order, post = {}, [], []
    modules = sorted(glob.glob(os.path.join(profile_dir, "flaws_*.py")))
    if not modules:
        sys.exit("[plant] no flaws_*.py in %s" % profile_dir)
    for path in modules:
        spec = importlib.util.spec_from_file_location(os.path.basename(path)[:-3], path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        post.extend(getattr(module, "POS", []))
        for fid, edits in module.FLAWS:
            if fid in flaws:
                sys.exit("[plant] flaw defined twice: %s" % fid)
            flaws[fid] = edits
            order.append(fid)
    return flaws, order, post


def apply(ref, flaws, order, only=None):
    """The edited files (path -> text, or None for a deleted file) and the list of edits that did not apply."""
    errors, files = [], {}
    for fid in order:
        if only and fid not in only:
            continue
        for path, old, new in (edit[:3] for edit in flaws[fid]):
            target = os.path.join(ref, path)
            if old is None:
                if os.path.exists(target) or path in files:
                    errors.append("%s: %s already exists" % (fid, path))
                else:
                    files[path] = new
                continue
            if path not in files:
                if not os.path.exists(target):
                    errors.append("%s: missing file %s" % (fid, path))
                    continue
                with open(target, encoding="utf-8") as f:
                    files[path] = f.read()
            if old == "*":
                files[path] = new
                continue
            if new is None:
                files[path] = None
                continue
            text = files[path]
            if text is None:
                errors.append("%s: %s was deleted by an earlier edit" % (fid, path))
                continue
            n = text.count(old)
            if n != 1:
                errors.append("%s: %d occurrence(s) in %s of: %r" % (fid, n, path, old[:90]))
                continue
            files[path] = text.replace(old, new, 1)
    return files, errors


def main():
    ap = argparse.ArgumentParser(description="Plant an instance's flaws on a copy of its reference system.")
    ap.add_argument("profile_dir", help="folder with the flaws_*.py modules")
    ap.add_argument("reference_dir", help="the clean reference system")
    ap.add_argument("out_dir", nargs="?", help="where the legacy tree is written; must not exist")
    ap.add_argument("--only", help="plant only these flaw ids (comma-separated)")
    ap.add_argument("--check", action="store_true", help="check that every edit applies, and write nothing")
    a = ap.parse_args()
    if not a.check and not a.out_dir:
        ap.error("OUT_DIR is required unless --check is given")

    only = set(a.only.split(",")) if a.only else None
    flaws, order, post = load(a.profile_dir)
    if only and only - set(order):
        sys.exit("[plant] --only names flaws the profile does not define: %s" % ", ".join(sorted(only - set(order))))
    files, errors = apply(a.reference_dir, flaws, order, only)
    if errors:
        print("\n".join(errors))
        sys.exit(1)

    def read(path):
        if path not in files:
            with open(os.path.join(a.reference_dir, path), encoding="utf-8") as f:
                files[path] = f.read()
        return files[path]

    for fid, fn in post:
        if only and fid not in only:
            continue
        try:
            fn(files, read)
        except AssertionError as e:
            print("%s: %s" % (fid, e))
            sys.exit(1)
    print("[plant] %d flaws, %d files touched, every edit matched exactly once" % (len(only or order), len(files)))
    if a.check:
        return
    if os.path.exists(a.out_dir):
        sys.exit("[plant] already exists: %s" % a.out_dir)
    shutil.copytree(a.reference_dir, a.out_dir, ignore=shutil.ignore_patterns("target", ".git"))
    for path, text in files.items():
        target = os.path.join(a.out_dir, path)
        if text is None:
            os.remove(target)
        else:
            os.makedirs(os.path.dirname(target), exist_ok=True)
            with open(target, "w", encoding="utf-8") as f:
                f.write(text)
    print("[plant] written to %s" % a.out_dir)


if __name__ == "__main__":
    main()
