#!/usr/bin/env python3
"""Rebuilds LEB-S-300-A's public material from the system's reference and this instance's planting profile, so that what the
candidate receives is always derived, never edited by hand:

    public/code/      tools/plant.py with private/plant/ on systems/300-A/reference, without .gitignore, with the public
                      tests that see a planted flaw removed or trimmed (private/removed-from-public-tests.txt keeps their text), plus the
                      system's package files (systems/300-A/package/: ENVIRONMENT.md and check-environment.sh)
    public/manifest.md  systems/300-A/manifest.md

    python3 instances/LEB-S-300-A/private/build_public.py            rewrites public/ and the record of the removed tests
    python3 instances/LEB-S-300-A/private/build_public.py --check    rebuilds in a temporary folder and compares, byte for byte
    python3 instances/LEB-S-300-A/private/build_public.py --clean OUT   writes the instance's clean tree (the reference plus its corrections), the one the verifiers accept

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
REMOVED_RECORD = os.path.join(HERE, "removed-from-public-tests.txt")

# A public test that sees a planted flaw leaves the public suite (CATALOG.md section 6). Each entry is the test class file, the method, and the rows whose planting it sees.
# A whole method goes: the class SecurityBehaviorTest has one test per class of the taxonomy, and the planted tree fails the test of every class it plants.
REMOVED_TESTS = [
    (TESTS + "SecurityBehaviorTest.java", "sec016ResidentsCannotDoTheManagersWork", "SEC-016, SEC-016.b"),
    (TESTS + "SecurityBehaviorTest.java", "sec017ThingsOfOtherUnitsAndBuildingsAreHidden", "SEC-017, SEC-017.b"),
    (TESTS + "BillingTest.java", "residentsDoNotSeeOtherUnitsInvoices", "SEC-017"),
    (TESTS + "SecurityBehaviorTest.java", "sec012AfterLoginOnlyLocalPathsAreFollowed", "SEC-012"),
    (TESTS + "SecurityBehaviorTest.java", "sec013LoginStartsANewSession", "SEC-013"),
    (TESTS + "SecurityBehaviorTest.java", "sec015TheWebhookSecretComesFromTheConfiguration", "SEC-015"),
    (TESTS + "SecurityBehaviorTest.java", "sec019FailedLoginsLockTheAccountAndTheAddress", "SEC-019"),
    (TESTS + "SecurityBehaviorTest.java", "sec020ErrorsAndResponsesShowNoInternals", "SEC-020"),
    (TESTS + "SecurityBehaviorTest.java", "sec002StoredTextIsEscaped", "SEC-002, SEC-002.b"),
    (TESTS + "SecurityBehaviorTest.java", "sec003ReflectedTextIsEscaped", "SEC-003"),
    (TESTS + "SecurityBehaviorTest.java", "sec004TheScriptOnlyWritesText", "SEC-004"),
    (TESTS + "SecurityBehaviorTest.java", "sec005AFormWithoutItsTokenIsRefused", "SEC-005"),
    (TESTS + "SecurityBehaviorTest.java", "sec008CsvCellsThatLookLikeFormulasAreNeutralized", "SEC-008"),
    (TESTS + "SecurityBehaviorTest.java", "sec001TheSortAndTheFiltersAreNeverSql", "SEC-001, SEC-001.b"),
    (TESTS + "SecurityBehaviorTest.java", "sec009TheArchiveLabelNeverReachesAShell", "SEC-009"),
    (TESTS + "SecurityBehaviorTest.java", "sec010AStatementWithADoctypeIsRefused", "SEC-010"),
    (TESTS + "SecurityBehaviorTest.java", "sec011DocumentsComeOnlyFromApprovedHosts", "SEC-011"),
    (TESTS + "SecurityBehaviorTest.java", "sec014PasswordsAreStoredSaltedAndSlow", "SEC-014"),
    (TESTS + "SecurityBehaviorTest.java", "sec018UploadsAreCheckedAndServedAsAttachments", "SEC-018"),
]

# A characterization test that holds a security assertion beside its own behavior loses only that part. Each trim is an exact edit that must match once.
TRIMS = [
    (TESTS + "BillingTest.java",
     """        Browser resident = loggedIn(OWNER_A101);
        resident.get("/invoices/" + id);
        assertEquals(403, resident.post("/invoices/" + id + "/payments", "amount", "1.00", "paid_on", "2026-03-05").status());
""",
     "", "SEC-016.b"),
    # SEC-001: the list no longer refuses a sort key it does not know.
    (TESTS + "BillingTest.java",
     """        assertEquals(400, b.get("/invoices?sort=password_hash").status());
""",
     "", "SEC-001"),
    # SEC-018: the upload check is a blocklist, so a text file, a PNG that is a PDF and a page are accepted, and so is the fetch of a .txt.
    (TESTS + "ContentTest.java",
     """        assertEquals(415, m.upload("/documents", "file", "notes.pdf", "just text".getBytes(StandardCharsets.UTF_8), "kind", "OTHER").status());
        assertEquals(415, m.upload("/documents", "file", "photo.png", PDF, "kind", "OTHER").status());
        assertEquals(415, m.upload("/documents", "file", "page.html", "<html></html>".getBytes(StandardCharsets.UTF_8), "kind", "OTHER").status());
""",
     "", "SEC-018"),
    (TESTS + "ContentTest.java",
     """        assertEquals(415, m.post("/documents/fetch", "kind", "MINUTES", "url", "http://" + docsHost + "/files/notes.txt").status());
""",
     "", "SEC-018"),
    # sec007 stays public (SEC-007 is a decoy), but its second half logs in with a next that carries a line break, and SEC-012's planting no longer rejects control characters there.
    (TESTS + "SecurityBehaviorTest.java",
     """        Browser b = browser();
        b.get("/login");
        R r = b.post("/login", "email", OWNER_A101, "password", PASSWORD, "next", "/invoices\\r\\nX-Injected: 1");
        assertEquals("/", r.location());
        assertNull(r.header("X-Injected"));
""",
     "", "SEC-012"),
]


# The system's manifest, plus what this instance adds to the public surface (CORR-S2's route).
MANIFEST_EDITS = [
    ("| `GET /documents/{id}/download` | who sees the document | — | 200 the file, as an attachment with its original name |\n",
     "| `GET /documents/{id}/download` | who sees the document | — | 200 the file, as an attachment with its original name |\n"
     "| `GET /documents/raw` | the building's users, admin (`building` for an admin) | `file`: a stored file name of the building | 200 the file, as an attachment; 404 for a name that is not in the building's folder |\n"),
]


def method_span(text, name):
    """(start, end) of the test method `name` with its annotations and the blank line after it, found by scanning Java tokens (strings, chars and comments skipped)."""
    sig = text.find("void %s(" % name)
    if sig < 0 or text.count("void %s(" % name) != 1:
        sys.exit("[build_public] the test method %s occurs %d times, expected once" % (name, text.count("void %s(" % name)))
    start = text.rfind("\n", 0, sig) + 1
    while True:                                   # take the annotation lines above the signature
        prev = text.rfind("\n", 0, start - 1) + 1
        if text[prev:start].strip().startswith("@"):
            start = prev
        else:
            break
    i = text.index("{", sig)
    depth, n = 0, len(text)
    while i < n:
        c = text[i]
        if c == "/" and text[i:i + 2] == "//":
            i = text.index("\n", i)
        elif c == "/" and text[i:i + 2] == "/*":
            i = text.index("*/", i) + 2
        elif c == '"':
            if text[i:i + 3] == '"""':
                i = text.index('"""', i + 3) + 3
            else:
                i += 1
                while text[i] != '"':
                    i += 2 if text[i] == "\\" else 1
                i += 1
        elif c == "'":
            i += 1
            while text[i] != "'":
                i += 2 if text[i] == "\\" else 1
            i += 1
        elif c == "{":
            depth += 1
            i += 1
        elif c == "}":
            depth -= 1
            i += 1
            if depth == 0:
                break
        else:
            i += 1
    end = i
    while end < n and text[end] in " \t":
        end += 1
    if text[end:end + 1] == "\n":
        end += 1
    if text[end:end + 1] == "\n":                 # the blank line that separated it from the next method
        end += 1
    return start, end


def plant(code, only=None):
    cmd = [sys.executable, os.path.join(ROOT, "tools", "plant.py"), os.path.join(HERE, "plant"), os.path.join(SYSTEM, "reference"), code]
    if only:
        cmd += ["--only", only]
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0:
        sys.exit("[build_public] planting failed:\n" + r.stdout + r.stderr)


def build(out):
    """Writes the public folder (code/ and manifest.md) into `out`. Returns the record of the removed tests."""
    code = os.path.join(out, "code")
    plant(code)
    os.remove(os.path.join(code, ".gitignore"))
    record = ["Public tests removed from LEB-S-300-A's public suite, because the planted tree fails them (CATALOG.md section 6).",
              "One block per method: the file, the method, the rows whose planting it sees, then its text as the reference has it (after CORR-S1).", ""]
    for path, name, rows in REMOVED_TESTS:
        full = os.path.join(code, path)
        with open(full, encoding="utf-8") as f:
            text = f.read()
        a, b = method_span(text, name)
        record += ["=== %s :: %s :: %s" % (path, name, rows), text[a:b].rstrip("\n"), ""]
        with open(full, "w", encoding="utf-8") as f:
            f.write(text[:a] + text[b:])
    for path, old, new, rows in TRIMS:
        full = os.path.join(code, path)
        with open(full, encoding="utf-8") as f:
            text = f.read()
        if text.count(old) != 1:
            sys.exit("[build_public] %s: the trimmed text occurs %d times, expected once" % (path, text.count(old)))
        record += ["=== %s :: trimmed :: %s" % (path, rows), old.rstrip("\n"), ""]
        with open(full, "w", encoding="utf-8") as f:
            f.write(text.replace(old, new))
    for name in sorted(os.listdir(os.path.join(SYSTEM, "package"))):
        shutil.copy(os.path.join(SYSTEM, "package", name), os.path.join(code, name))
    with open(os.path.join(SYSTEM, "manifest.md"), encoding="utf-8") as f:
        manifest = f.read()
    for old_text, new_text in MANIFEST_EDITS:
        if manifest.count(old_text) != 1:
            sys.exit("[build_public] the manifest line to extend occurs %d times, expected once" % manifest.count(old_text))
        manifest = manifest.replace(old_text, new_text)
    with open(os.path.join(out, "manifest.md"), "w", encoding="utf-8") as f:
        f.write(manifest)
    return "\n".join(record) + "\n"


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
    ap.add_argument("--clean", metavar="OUT", help="write the clean tree (reference + CORR-S1 and CORR-S2) into OUT")
    a = ap.parse_args()
    if a.clean:
        if os.path.exists(a.clean):
            sys.exit("[build_public] %s exists" % a.clean)
        plant(a.clean, only="CORR-S1,CORR-S2")
        os.remove(os.path.join(a.clean, ".gitignore"))
        print("[build_public] clean tree written to %s" % a.clean)
        return
    public = os.path.join(INSTANCE, "public")
    with tempfile.TemporaryDirectory() as tmp:
        fresh = os.path.join(tmp, "public")
        os.makedirs(fresh)
        record = build(fresh)
        if a.check:
            diff = differences(fresh, public)
            if os.path.exists(REMOVED_RECORD):
                with open(REMOVED_RECORD, encoding="utf-8") as f:
                    if f.read() != record:
                        diff.append("private/removed-from-public-tests.txt")
            else:
                diff.append("private/removed-from-public-tests.txt (missing)")
            if diff:
                sys.exit("[build_public] public/ differs from a fresh build: %s" % ", ".join(diff))
            print("[build_public] public/ is what the reference and the profile give")
            return
        os.makedirs(public, exist_ok=True)
        shutil.rmtree(os.path.join(public, "code"), ignore_errors=True)
        shutil.copytree(os.path.join(fresh, "code"), os.path.join(public, "code"))
        shutil.copyfile(os.path.join(fresh, "manifest.md"), os.path.join(public, "manifest.md"))
        with open(REMOVED_RECORD, "w", encoding="utf-8") as f:
            f.write(record)
        print("[build_public] public/ rewritten")


if __name__ == "__main__":
    main()
