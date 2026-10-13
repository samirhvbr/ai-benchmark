#!/usr/bin/env python3
"""Rebuilds LEB-D-300-A's public material from the system's reference and this instance's planting profile, so that what the
candidate receives is always derived, never edited by hand:

    public/code/      tools/plant.py with private/plant/ on systems/300-A/reference, without .gitignore, with the public
                      tests trimmed as private/removed-from-public-tests.txt records, plus the system's package files
                      (systems/300-A/package/: ENVIRONMENT.md and check-environment.sh)
    public/manifest.md  systems/300-A/manifest.md

    python3 instances/LEB-D-300-A/private/build_public.py            rewrites public/
    python3 instances/LEB-D-300-A/private/build_public.py --check    rebuilds in a temporary folder and compares, byte for byte

Stdlib only."""

import argparse
import filecmp
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
INSTANCE = os.path.dirname(HERE)
ROOT = os.path.dirname(os.path.dirname(INSTANCE))
SYSTEM = os.path.join(ROOT, "systems", "300-A")
TESTS = "src/test/java/com/condodesk/"

# The public test that sees a planted flaw loses that part (CATALOG.md §8); the removed text is kept in
# private/removed-from-public-tests.txt. Each trim is an exact edit that must match once.
TRIMS = [
    (TESTS + "BillingTest.java",
     """    void aStatementWithABadLineRecordsNothing() throws Exception {
        invoice(unitA101, "2026-02", LocalDate.parse("2026-03-10"), "300.00");
        byte[] statement = ofx(new String[]{"F1", "20260303", "300.00", "PIX " + ourNumberOf(unitA101, "2026-02")},
                new String[]{"", "20260303", "10.00", "no id"});
        Browser b = loggedIn(ADMIN);
        b.get("/admin/statements");
        assertEquals(400, b.upload("/admin/statements", "statement", "bad.ofx", statement).status());
        assertEquals(0, count("select count(*) from payment"));
        assertEquals(400, b.upload("/admin/statements", "statement", "bad.ofx", "<OFX><unclosed>".getBytes()).status());
    }""",
     """    void aMalformedStatementIsRefused() throws Exception {
        Browser b = loggedIn(ADMIN);
        b.get("/admin/statements");
        assertEquals(400, b.upload("/admin/statements", "statement", "bad.ofx", "<OFX><unclosed>".getBytes()).status());
        assertEquals(0, count("select count(*) from payment"));
    }"""),
]


def build(out):
    """Writes the public folder (code/ and manifest.md) into `out`."""
    code = os.path.join(out, "code")
    r = subprocess.run([sys.executable, os.path.join(ROOT, "tools", "plant.py"), os.path.join(HERE, "plant"),
                        os.path.join(SYSTEM, "reference"), code], capture_output=True, text=True)
    if r.returncode != 0:
        sys.exit("[build_public] planting failed:\n" + r.stdout + r.stderr)
    os.remove(os.path.join(code, ".gitignore"))
    for path, old, new in TRIMS:
        full = os.path.join(code, path)
        with open(full, encoding="utf-8") as f:
            text = f.read()
        if text.count(old) != 1:
            sys.exit("[build_public] %s: the trimmed test occurs %d times, expected once" % (path, text.count(old)))
        with open(full, "w", encoding="utf-8") as f:
            f.write(text.replace(old, new))
    for name in sorted(os.listdir(os.path.join(SYSTEM, "package"))):
        shutil.copy(os.path.join(SYSTEM, "package", name), os.path.join(code, name))
    shutil.copyfile(os.path.join(SYSTEM, "manifest.md"), os.path.join(out, "manifest.md"))


def differences(a, b):
    """Relative paths that differ between two trees (missing on either side, or different bytes)."""
    out = []

    def walk(cmp, rel):
        out.extend(os.path.join(rel, n) for n in cmp.left_only + cmp.right_only + cmp.funny_files)
        _, diff, errors = filecmp.cmpfiles(cmp.left, cmp.right, cmp.common_files, shallow=False)
        out.extend(os.path.join(rel, n) for n in diff + errors)
        for name, sub in cmp.subdirs.items():
            walk(sub, os.path.join(rel, name))

    walk(filecmp.dircmp(a, b), "")
    return sorted(out)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--check", action="store_true", help="compare a fresh build with public/ instead of writing it")
    a = ap.parse_args()
    public = os.path.join(INSTANCE, "public")
    with tempfile.TemporaryDirectory() as tmp:
        fresh = os.path.join(tmp, "public")
        os.makedirs(fresh)
        build(fresh)
        if a.check:
            diff = differences(fresh, public)
            if diff:
                sys.exit("[build_public] public/ differs from a fresh build: %s" % ", ".join(diff))
            print("[build_public] public/ is what the reference and the profile give")
            return
        shutil.rmtree(os.path.join(public, "code"), ignore_errors=True)
        shutil.copytree(os.path.join(fresh, "code"), os.path.join(public, "code"))
        shutil.copyfile(os.path.join(fresh, "manifest.md"), os.path.join(public, "manifest.md"))
        print("[build_public] public/ rewritten")


if __name__ == "__main__":
    main()
