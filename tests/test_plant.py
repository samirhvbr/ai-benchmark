"""tools/plant.py — the generic planting engine. Synthetic profiles for each kind of edit and each refusal, and the proof that it
plants LEB-300-A's legacy tree byte for byte from that instance's reference and profile."""

import filecmp
import os
import tempfile
import textwrap
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "plant.py")
LEB_300_A = os.path.join(ROOT, "instances", "LEB-300-A", "private")


def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(text)


def read(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


class Synthetic(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.ref = os.path.join(self.tmp.name, "ref")
        self.profile = os.path.join(self.tmp.name, "profile")
        self.out = os.path.join(self.tmp.name, "out")
        write(os.path.join(self.ref, "src", "A.java"), "class A {\n    int x = 1;\n}\n")
        write(os.path.join(self.ref, "src", "B.java"), "class B {}\n")
        write(os.path.join(self.ref, "src", "C.java"), "class C {}\n")
        write(os.path.join(self.ref, "target", "A.class"), "compiled\n")
        write(os.path.join(self.profile, "paths.py"), 'SRC = "src/"\n')

    def tearDown(self):
        self.tmp.cleanup()

    def profile_module(self, name, body):
        write(os.path.join(self.profile, name), textwrap.dedent(body))

    def plant(self, *extra):
        return run_py(TOOL, self.profile, self.ref, *extra)

    def test_each_kind_of_edit_and_a_post_hook(self):
        self.profile_module("flaws_a.py", """
            from paths import SRC
            FLAWS = [
                ("BUG-001", [(SRC + "A.java", "int x = 1;", "int x = 0;")]),
                ("ARCH-001", [(SRC + "D.java", None, "class D {}\\n"), (SRC + "B.java", "*", "class B { int y; }\\n")]),
                ("CLN-001", [(SRC + "C.java", "class C {}", None)]),
            ]
            def mark(files, read):
                files[SRC + "A.java"] = read(SRC + "A.java").replace("class A", "class A /* legacy */")
            POS = [("BUG-001", mark)]
            """)
        r = self.plant(self.out)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertEqual(read(os.path.join(self.out, "src", "A.java")), "class A /* legacy */ {\n    int x = 0;\n}\n")
        self.assertEqual(read(os.path.join(self.out, "src", "B.java")), "class B { int y; }\n")
        self.assertEqual(read(os.path.join(self.out, "src", "D.java")), "class D {}\n")
        self.assertFalse(os.path.exists(os.path.join(self.out, "src", "C.java")))
        self.assertFalse(os.path.exists(os.path.join(self.out, "target")))
        self.assertEqual(read(os.path.join(self.ref, "src", "A.java")), "class A {\n    int x = 1;\n}\n")

    def test_modules_apply_in_name_order_on_the_text_the_earlier_edits_left(self):
        self.profile_module("flaws_b.py", 'FLAWS = [("BUG-002", [("src/A.java", "int x = 2;", "int x = 3;")])]\n')
        self.profile_module("flaws_a.py", 'FLAWS = [("BUG-001", [("src/A.java", "int x = 1;", "int x = 2;")])]\n')
        r = self.plant(self.out)
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("int x = 3;", read(os.path.join(self.out, "src", "A.java")))

    def test_check_and_only_write_nothing_and_plant_a_subset(self):
        self.profile_module("flaws_a.py", """
            FLAWS = [
                ("BUG-001", [("src/A.java", "int x = 1;", "int x = 0;")]),
                ("BUG-002", [("src/B.java", "class B {}", "class B { }")]),
            ]
            """)
        r = self.plant("--check")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("2 flaws", r.stdout)
        self.assertFalse(os.path.exists(self.out))
        r = self.plant(self.out, "--only", "BUG-002")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("int x = 1;", read(os.path.join(self.out, "src", "A.java")))
        self.assertEqual(read(os.path.join(self.out, "src", "B.java")), "class B { }\n")

    def assert_refused(self, module_body, *args, says):
        self.profile_module("flaws_a.py", module_body)
        r = self.plant(*(args or (self.out,)))
        self.assertNotEqual(r.returncode, 0)
        self.assertIn(says, r.stdout + r.stderr)
        if not args:
            self.assertFalse(os.path.exists(self.out))

    def test_an_old_text_that_is_missing_is_refused(self):
        self.assert_refused('FLAWS = [("BUG-001", [("src/A.java", "int z = 9;", "x")])]\n', says="0 occurrence(s)")

    def test_an_old_text_that_occurs_twice_is_refused(self):
        write(os.path.join(self.ref, "src", "E.java"), "int a;\nint a;\n")
        self.assert_refused('FLAWS = [("BUG-001", [("src/E.java", "int a;", "int b;")])]\n', says="2 occurrence(s)")

    def test_a_flaw_defined_twice_is_refused(self):
        self.assert_refused('FLAWS = [("BUG-001", []), ("BUG-001", [])]\n', says="flaw defined twice: BUG-001")

    def test_creating_a_file_that_exists_and_editing_a_deleted_one_are_refused(self):
        self.assert_refused('FLAWS = [("BUG-001", [("src/B.java", None, "x")])]\n', says="already exists")
        self.assert_refused('FLAWS = [("BUG-001", [("src/C.java", "*", None), ("src/C.java", "class", "x")])]\n',
                            says="was deleted by an earlier edit")

    def test_an_only_that_names_an_unknown_flaw_is_refused(self):
        self.assert_refused('FLAWS = [("BUG-001", [])]\n', "--check", "--only", "BUG-009", says="BUG-009")

    def test_an_out_dir_that_exists_is_refused(self):
        os.makedirs(self.out)
        self.assert_refused('FLAWS = [("BUG-001", [])]\n', self.out, says="already exists")


class LEB300A(unittest.TestCase):
    def test_it_plants_the_published_legacy_tree_of_leb_300_a_byte_for_byte(self):
        with tempfile.TemporaryDirectory() as tmp:
            out = os.path.join(tmp, "legacy")
            r = run_py(TOOL, os.path.join(LEB_300_A, "plant"), os.path.join(LEB_300_A, "reference"), out)
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
            expected = os.path.join(LEB_300_A, "build", "legado", "src")
            mismatches = []

            def compare(cmp, rel=""):
                mismatches.extend(os.path.join(rel, n) for n in cmp.left_only + cmp.right_only + cmp.funny_files)
                _, diff, errors = filecmp.cmpfiles(cmp.left, cmp.right, cmp.common_files, shallow=False)
                mismatches.extend(os.path.join(rel, n) for n in diff + errors)
                for name, sub in cmp.subdirs.items():
                    compare(sub, os.path.join(rel, name))

            compare(filecmp.dircmp(os.path.join(out, "src"), expected))
            self.assertEqual(mismatches, [])


if __name__ == "__main__":
    unittest.main()
