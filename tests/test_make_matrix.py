"""tools/make_matrix.py — an instance's answer key from its declared rows: locations from anchors, verifier paths from the
verifier sources. Synthetic instances for each refusal, and the check that LEB-D-300-A's committed matrix is what its rows give."""

import json
import os
import tempfile
import textwrap
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "make_matrix.py")
LEB_D_300_A = os.path.join(ROOT, "instances", "LEB-D-300-A")


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)


ROWS = """
HEADER = {"leb_spec": "1.5.1", "instance": "LEB-X", "level": 300, "version": "1.0"}
SRC = "src/main/java/x/"
ROUTE_PATTERN = r'route\\("([A-Z]+)", "([^"]+)"'
SYMBOLS = ["x.App.create():App"]
ARTIFACTS = []
FLAWS = [
    dict(id="BUG-001", severity="Alta", difficulty="Fácil", template="C", file="A.java", anchor=r"int x = 0;",
         evidence="the counter starts wrong", fix="start it right", notes="", extra={"dimensions": ["resiliencia"]}),
    dict(id="ARCH-001", severity="Média", difficulty="Difícil", template="R", file="/schema.sql", anchor=r"create table t",
         evidence="a table with no key", fix="add a key", notes="any key", extra={}),
]
DECOYS = [dict(id="PERF-001.b", kind="intervencao", file="A.java", anchor=r"synchronized", looks="needless blocking", why="It guards x.")]
"""

VERIFY = """
class V01Test {
    @Test
    void bug001_counts() throws Exception {
        Verdict.fixed("BUG-001", true, "ok");
    }

    @Test
    void arch001_keys() {
        Verdict.structure("ARCH-001", "full", "ok");
    }

    @Test
    void perf001b_guards() throws Exception {
        if (false) { Verdict.inconclusive("PERF-001.b", "no scenario"); return; }
        Verdict.decoy("PERF-001.b", true, "ok");
    }
}
"""


class Synthetic(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.inst = os.path.join(self.tmp.name, "LEB-X")
        write(os.path.join(self.inst, "public", "code", "src", "main", "java", "x", "A.java"),
              'class A {\n    synchronized void f() {}\n    int x = 0;\n    void g() { s.route("GET", "/items"); }\n}\n')
        write(os.path.join(self.inst, "public", "code", "schema.sql"), "-- schema\ncreate table t (\n    id bigint,\n    name text\n);\n")
        write(os.path.join(self.inst, "private", "matrix_rows.py"), textwrap.dedent(ROWS))
        write(os.path.join(self.inst, "private", "verify", "src", "test", "java", "x", "V01Test.java"), VERIFY)

    def tearDown(self):
        self.tmp.cleanup()

    def make(self, *extra):
        return run_py(TOOL, self.inst, *extra)

    def test_locations_points_verifiers_and_surface_come_from_the_files(self):
        r = self.make()
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        with open(os.path.join(self.inst, "private", "matrix.json"), encoding="utf-8") as f:
            m = json.load(f)
        bug, arch, decoy = m["entries"]
        self.assertEqual(bug["location"], "code/src/main/java/x/A.java:3")
        self.assertEqual((bug["points"], bug["proves"], bug["dimensions"]), (8, ["C3"], ["resiliencia"]))
        self.assertEqual(bug["verify"], "verify/src/test/java/x/V01Test.java#bug001_counts")
        self.assertEqual((arch["location"], arch["points"], arch["proves"]), ("code/schema.sql:2", 8, ["R3"]))
        self.assertEqual((decoy["exists"], decoy["decoy_kind"], decoy["location"]), (False, "intervencao", "code/src/main/java/x/A.java:2"))
        self.assertTrue(decoy["notes"].startswith("DECOY. Looks like needless blocking. It guards x."))
        self.assertEqual(m["public_surface"]["routes"], ["GET /items"])
        self.assertEqual(m["public_surface"]["db_schema"], ["t(id,name)"])
        self.assertEqual(self.make("--check").returncode, 0)

    def test_check_fails_when_the_matrix_is_stale(self):
        self.make()
        write(os.path.join(self.inst, "public", "code", "schema.sql"), "\n-- moved\ncreate table t (\n    id bigint\n);\n")
        r = self.make("--check")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("matrix.json", r.stderr)

    def refused(self, says):
        r = self.make()
        self.assertNotEqual(r.returncode, 0)
        self.assertIn(says, r.stderr)

    def test_an_anchor_that_matches_nothing_is_refused(self):
        rows = os.path.join(self.inst, "private", "matrix_rows.py")
        write(rows, textwrap.dedent(ROWS).replace(r'anchor=r"int x = 0;"', r'anchor=r"int y = 0;"'))
        self.refused("anchor not found")

    def test_a_row_without_a_verifier_is_refused(self):
        path = os.path.join(self.inst, "private", "verify", "src", "test", "java", "x", "V01Test.java")
        write(path, VERIFY.replace('Verdict.fixed("BUG-001", true, "ok");', ""))
        self.refused("no verifier writes a verdict for BUG-001")

    def test_a_verdict_for_an_undeclared_id_is_refused(self):
        path = os.path.join(self.inst, "private", "verify", "src", "test", "java", "x", "V02Test.java")
        write(path, 'class V02Test {\n    @Test\n    void other() {\n        Verdict.fixed("BUG-009", true, "x");\n    }\n}\n')
        self.refused("BUG-009")

    def test_one_id_written_by_two_methods_is_refused(self):
        path = os.path.join(self.inst, "private", "verify", "src", "test", "java", "x", "V02Test.java")
        write(path, 'class V02Test {\n    @Test\n    void again() {\n        Verdict.fixed("BUG-001", true, "x");\n    }\n}\n')
        self.refused("two methods")


class LebD300A(unittest.TestCase):
    def test_the_committed_matrix_is_what_its_rows_and_files_give(self):
        r = run_py(TOOL, LEB_D_300_A, "--check")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == "__main__":
    unittest.main()
