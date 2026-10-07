"""E6: the package leak guard. Whole path segments, content markers derived from the matrix, the instance's own allowlist, and a
positive control on every packaging run. Synthetic instances only."""

import os
import shutil
import sys
import tempfile
import unittest
from unittest import mock

import helpers
from helpers import ROOT, entry, make_instance, run_py, synthetic_matrix, write_json

sys.path.insert(0, os.path.join(ROOT, "harness"))
import pack  # noqa: E402

EVIDENCE_PROSE = "the retry loop swallows the timeout and reports success to the caller"
FIX_PROSE = "wrap the transfer in a single transaction and lock the account row first"
QUOTED = "synchronized (accounts) { balance -= amount; }"


def matrix(**header):
    entries = [
        entry("BUG-001", "Alta", "Moderada", evidence=EVIDENCE_PROSE, expected_fix=FIX_PROSE,
              location="code/src/main/java/app/Transfer.java:88-120"),
        entry("SEC-001", "Alta", "Fácil", evidence=QUOTED, expected_fix="short fix", location="code/x:1"),
    ]
    return synthetic_matrix(entries, **header)


class GuardCase(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.t = self._tmp.name
        self.inst = None

    def tearDown(self):
        self._tmp.cleanup()

    def build(self, files=None, manifest=None, **header):
        """A synthetic instance whose code/ holds `files` ({relative path: text or bytes})."""
        shutil.rmtree(os.path.join(self.t, "instances"), ignore_errors=True)
        self.inst = make_instance(self.t, matrix=matrix(**header))
        code = os.path.join(self.inst, "code")
        files = dict({"src/main/java/app/Transfer.java": "class Transfer { void run() { balance -= amount; } }\n"}, **(files or {}))
        for rel, content in files.items():
            path = os.path.join(code, rel)
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "wb" if isinstance(content, bytes) else "w", **({} if isinstance(content, bytes) else {"encoding": "utf-8"})) as f:
                f.write(content)
        if manifest is not None:
            with open(os.path.join(self.inst, "manifest.md"), "w", encoding="utf-8") as f:
                f.write(manifest)
        return self.inst

    def pack(self):
        out = os.path.join(self.t, "pkg")
        r = run_py(helpers.PACK, "--instance", self.inst, "--out", out, "--force", "--mode", "A", "--turnos", "30")
        return r, out

    def assert_refused(self, fragment):
        r, out = self.pack()
        self.assertNotEqual(r.returncode, 0, "the package was accepted")
        self.assertIn("VAZAMENTO", r.stderr)
        self.assertIn(fragment, r.stderr)
        self.assertFalse(os.path.exists(out), "the refused package was left on disk")

    def assert_accepted(self):
        r, out = self.pack()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertTrue(os.path.isdir(out))


class PathSegments(GuardCase):
    def test_a_name_that_merely_contains_a_pattern_is_accepted(self):
        self.build({"src/VerifyToken.java": "class VerifyToken {}\n", "src/MatrixController.java": "class M {}\n",
                    "src/probesAndMore.txt": "ok\n"})
        self.assert_accepted()

    def test_a_pattern_as_a_whole_word_is_refused_in_a_file_or_a_directory(self):
        cases = {"src/matrix_x.json": "matrix", "src/verify/Signature.java": "verify", "src/Probes.php": "probes",
                 "src/private-notes.md": "private", "characterization/run.txt": "characterization", "docs/matriz.md": "matriz"}
        for rel, word in cases.items():
            with self.subTest(path=rel):
                self.build({rel: "text\n"})
                self.assert_refused("path segment `%s`" % word)
                self._tmp.cleanup()
                self._tmp = tempfile.TemporaryDirectory()
                self.t = self._tmp.name

    def test_the_instance_allowlist_exempts_a_legitimate_name_but_only_by_path(self):
        self.build({"src/verify/Signature.java": "class Signature {}\n", "src/other/verify/X.java": "class X {}\n"},
                   pack_allow_paths=["code/src/verify"])
        self.assert_refused("src/other/verify")  # an allowlisted path is not an allowlisted word
        self.build({"src/verify/Signature.java": "class Signature {}\n"}, pack_allow_paths=["code/src/verify"])
        self.assert_accepted()


class Content(GuardCase):
    def test_a_fix_text_planted_in_the_manifest_is_refused(self):
        self.build(manifest="# Manifest\n\nNote: %s.\n" % FIX_PROSE)
        self.assert_refused("content: BUG-001.expected_fix")

    def test_prose_evidence_planted_in_a_code_comment_is_refused_even_when_rewrapped(self):
        wrapped = EVIDENCE_PROSE.replace(" ", "\n   // ", 3)
        self.build({"src/Notes.java": "// " + wrapped + "\nclass Notes {}\n"})
        self.assert_refused("content: BUG-001.evidence")

    def test_the_location_of_a_flaw_is_a_marker(self):
        self.build({"README.txt": "see code/src/main/java/app/Transfer.java:88-120 for the problem\n"})
        self.assert_refused("content: BUG-001.location")

    def test_evidence_that_quotes_the_delivered_code_refuses_the_package_and_names_the_text(self):
        # Fails safe: the fix is to write the evidence as the symptom, not as a quote of the code.
        self.build({"src/main/java/app/Account.java": "class Account { void run() { %s } }\n" % QUOTED})
        self.assert_refused("content: SEC-001.evidence")

    def test_short_texts_and_binary_files_are_not_scanned(self):
        self.build({"data.bin": b"\0\1\2" + FIX_PROSE.encode() + b"\0"})
        self.assert_accepted()
        self.build(manifest="# Manifest\n\nshort fix\n")
        self.assert_accepted()

    def test_the_private_destination_path_is_a_marker(self):
        self.build()
        private = os.path.realpath(os.path.join(self.inst, "private"))
        with open(os.path.join(self.inst, "code", "oops.txt"), "w", encoding="utf-8") as f:
            f.write("results go to %s/runs\n" % private)
        self.assert_refused("content: private path")

    def test_the_public_matrix_hash_in_the_task_header_is_not_a_marker(self):
        self.build()
        r, out = self.pack()
        self.assertEqual(r.returncode, 0, r.stderr)
        task = helpers.read_text(out, "TAREFA.md")
        self.assertIn(helpers.read_json(os.path.join(self.inst, "private", "matrix.json"))["instance"], task)
        self.assertRegex(task, r"[0-9a-f]{64}")


class PositiveControl(GuardCase):
    def test_a_blind_guard_aborts_the_packaging(self):
        self.build()
        with tempfile.TemporaryDirectory() as t:
            pkg = os.path.join(t, "pkg")
            os.makedirs(pkg)
            with mock.patch.object(pack, "leak_scan", return_value=[]):
                with self.assertRaises(SystemExit) as cm:
                    pack.guard_with_positive_control(pkg, [], [])
            self.assertIn("controle positivo", str(cm.exception))

    def test_the_control_leaves_the_package_untouched(self):
        self.build()
        r, out = self.pack()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertFalse(os.path.exists(os.path.join(out, "private")))
        self.assertEqual(pack.leak_scan(out, [("LEB-CONTROL-MARKER", "control")], []), [])

    def test_the_guard_sees_the_two_kinds_of_leak_in_a_scan_of_a_planted_copy(self):
        with tempfile.TemporaryDirectory() as t:
            os.makedirs(os.path.join(t, "private"))
            with open(os.path.join(t, "private", "k.txt"), "w", encoding="utf-8") as f:
                f.write("planted SECRET-TEXT-OF-THE-KEY here\n")
            seen = {why for _, why in pack.leak_scan(t, [("SECRET-TEXT-OF-THE-KEY", "x")], [])}
            self.assertEqual(seen, {"path segment `private`", "content: x"})


class LebOneHundredA(unittest.TestCase):
    def test_the_published_package_still_passes_and_the_guard_is_not_vacuous(self):
        inst = os.path.join(ROOT, "instances", "LEB-100-A")
        m = helpers.read_json(os.path.join(inst, "private", "matrix.json"))
        markers = pack.leak_markers(m, [])
        self.assertGreater(len(markers), 0, "no marker was derived from the LEB-100-A matrix: the content scan would be empty")
        published = helpers.read_json(os.path.join(ROOT, "results", "results.json"))["instances"][0]["package_sha256"]
        with tempfile.TemporaryDirectory() as t:
            r = run_py(helpers.PACK, "--instance", inst, "--out", os.path.join(t, "p"), "--mode", "A", "--turnos", "30")
            self.assertEqual((r.returncode, r.stdout.strip()), (0, published), r.stderr)


if __name__ == "__main__":
    unittest.main()
