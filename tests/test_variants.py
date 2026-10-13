"""tools/variants.py — the engine that checks verifiers against control variants. A synthetic instance and a fake evaluator stand
in for Docker and Maven: the evaluator reads the assembled tree and answers as a verifier would."""

import json
import os
import sys
import tempfile
import textwrap
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "variants.py")

# A verifier in miniature: BUG-001 is fixed when A.java says "fixed", ARCH-001 is half when it says "half", the decoy breaks when
# it says "broken", and a tree with "syntax error" does not compile. It also checks that the migration of the reference arrived.
FAKE_EVALUATOR = textwrap.dedent('''\
    #!%s
    import json, os, sys
    mode, instance, tree = sys.argv[1], sys.argv[2], sys.argv[3]
    text = open(os.path.join(tree, "src", "main", "java", "x", "A.java")).read()
    if "syntax error" in text:
        print(json.dumps({"probes": [{"id": "BUG-001", "result": "none", "proves": ["C3"]}], "decoys": [], "inconclusive": [], "build_failed": True}))
        sys.exit(0)
    if mode == "characterize":
        print(json.dumps({"passed": 9 if "breaks a test" in text else 10, "failed": 1 if "breaks a test" in text else 0}))
        sys.exit(0)
    migrated = os.path.exists(os.path.join(tree, "migrations", "001_index.sql"))
    print(json.dumps({
        "probes": [{"id": "BUG-001", "result": "full" if "fixed" in text or migrated else "none", "proves": ["C3"], "msg": "bug"},
                   {"id": "ARCH-001", "result": "half" if "half" in text else "none", "proves": ["R3"], "msg": "arch"}],
        "decoys": [{"id": "PERF-001.b", "holds": "broken" not in text, "msg": "decoy"}],
        "inconclusive": []}))
''') % sys.executable

MATRIX = {"instance": "LEB-X", "entries": [
    {"id": "BUG-001", "exists": True, "verify": "verify/src/test/java/x/V01Test.java#bug001"},
    {"id": "ARCH-001", "exists": True, "verify": "verify/src/test/java/x/V01Test.java#arch001"},
    {"id": "PERF-001.b", "exists": False, "verify": "verify/src/test/java/x/V01Test.java#perf001b"}]}


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)


class Engine(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        t = self.tmp.name
        self.inst = os.path.join(t, "LEB-X")
        self.evaluator = os.path.join(t, "fake_evaluate.py")
        write(self.evaluator, FAKE_EVALUATOR)
        os.chmod(self.evaluator, 0o755)
        write(os.path.join(self.inst, "public", "code", "src", "main", "java", "x", "A.java"), "class A { int x = 0; }\n")
        write(os.path.join(t, "ref", "src", "main", "java", "x", "A.java"), "class A { int x = 1; }\n")
        write(os.path.join(self.inst, "private", "matrix.json"), json.dumps(MATRIX))
        write(os.path.join(self.inst, "private", "variants", "config.py"), textwrap.dedent('''\
            REFERENCE = %r
            REFERENCE_ADDS = {"migrations/001_index.sql": "create index i on t (c);"}
            SRC = "src/main/java/x/"
            SERIAL = {"ARCH-001"}
            EVALUATE = %r
            ''') % (os.path.join(t, "ref"), self.evaluator))

    def tearDown(self):
        self.tmp.cleanup()

    def cases(self, body):
        write(os.path.join(self.inst, "private", "variants", "cases_a.py"), textwrap.dedent(body))

    def run_tool(self, *extra):
        return run_py(TOOL, self.inst, *extra)

    def test_every_expectation_is_compared_and_the_results_are_written(self):
        self.cases('''\
            CASES = [
                dict(target="BUG-001", name="fix", kind="alternative", expect="fixed", edits=[("A.java", "int x = 0;", "int x = 0; // fixed")]),
                dict(target="BUG-001", name="miss", kind="wrong", expect="not_fixed", edits=[("A.java", "int x = 0;", "int x = 2;")]),
                dict(target="BUG-001", name="from-the-reference", kind="alternative", expect="fixed", base="reference"),
                dict(target="ARCH-001", name="half", kind="partial", expect="half", edits=[("A.java", lambda t: t.replace("{", "{ /* half */"))]),
                dict(target="PERF-001.b", name="kept", kind="decoy", expect="holds"),
                dict(target="PERF-001.b", name="broken", kind="decoy", expect="broken", files={"src/main/java/x/B.java": "x"},
                     edits=[("A.java", "int x = 0;", "int x = 0; // broken")]),
            ]
            ''')
        r = self.run_tool("--parallel", "2")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("6 cases, 0 unexpected", r.stdout)
        with open(os.path.join(self.inst, "private", "variants", "results.tsv"), encoding="utf-8") as f:
            rows = [l.split("\t") for l in f.read().strip().split("\n")]
        self.assertEqual([row[2] for row in rows[1:]], ["fix", "miss", "from-the-reference", "half", "kept", "broken"])
        self.assertTrue(all(row[5] == "ok" for row in rows[1:]))

    def test_a_case_that_does_not_compile_proves_nothing(self):
        self.cases('''\
            CASES = [dict(target="BUG-001", name="no-build", kind="wrong", expect="not_fixed", edits=[("A.java", "int x = 0;", "syntax error")])]
            ''')
        r = self.run_tool()
        self.assertEqual(r.returncode, 1)
        self.assertIn("observed=does not compile", r.stdout)

    def test_an_edit_that_does_not_match_once_fails_the_case(self):
        self.cases('''\
            CASES = [dict(target="BUG-001", name="stale", kind="alternative", expect="fixed", edits=[("A.java", "int y = 0;", "int y = 1;")])]
            ''')
        r = self.run_tool()
        self.assertEqual(r.returncode, 1)
        self.assertIn("case does not apply", r.stdout)

    def test_an_alternative_that_breaks_a_public_test_is_not_accepted(self):
        self.cases('''\
            CASES = [dict(target="BUG-001", name="fix-that-breaks", kind="alternative", expect="fixed",
                          edits=[("A.java", "int x = 0;", "int x = 0; // fixed, breaks a test")])]
            ''')
        self.assertEqual(self.run_tool().returncode, 0)
        r = self.run_tool("--characterize")
        self.assertEqual(r.returncode, 1)
        self.assertIn("public tests 9 passed, 1 failed", r.stdout)

    def test_only_and_list_select_cases_without_writing_results(self):
        self.cases('''\
            CASES = [dict(target="BUG-001", name="fix", kind="alternative", expect="fixed", edits=[("A.java", "int x = 0;", "int x = 0; // fixed")]),
                     dict(target="ARCH-001", name="none", kind="wrong", expect="not_fixed")]
            ''')
        r = self.run_tool("--list")
        self.assertIn("ARCH-001", r.stdout)
        r = self.run_tool("--only", "ARCH-001")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("1 cases, 0 unexpected", r.stdout)
        self.assertFalse(os.path.exists(os.path.join(self.inst, "private", "variants", "results.tsv")))

    def test_a_case_name_used_twice_is_refused(self):
        self.cases('''\
            CASES = [dict(target="BUG-001", name="same", kind="wrong", expect="not_fixed"),
                     dict(target="BUG-001", name="same", kind="wrong", expect="not_fixed")]
            ''')
        r = self.run_tool("--list")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("case named twice", r.stderr)


if __name__ == "__main__":
    unittest.main()
