"""LEB-S-300-A: its public material is what the reference and the planting profile give, byte for byte, and its matrix is what its rows
and files give, so nothing the candidate receives, and no row of the key, was edited by hand."""

import fnmatch
import json
import os
import unittest

from helpers import ROOT, run_py

BUILD = os.path.join(ROOT, "instances", "LEB-S-300-A", "private", "build_public.py")
MATRIX = os.path.join(ROOT, "tools", "make_matrix.py")
INSTANCE = os.path.join(ROOT, "instances", "LEB-S-300-A")


class PublicMaterial(unittest.TestCase):
    def test_public_is_a_fresh_build_of_the_reference_and_the_profile(self):
        r = run_py(BUILD, "--check")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)

    def test_matrix_is_what_its_rows_and_its_files_give(self):
        r = run_py(MATRIX, INSTANCE, "--check")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


class RunnerContract(unittest.TestCase):
    def test_the_verification_command_selects_every_verifier_class(self):
        """evaluate.sh runs `V0*Test` when it is given no selector, and this instance's verifiers are `S0*Test`: a runner that passes none runs no verifier,
        every row ends without evidence and the judges decide alone. The selector has to be in the runner contract, and has to match each verifier class."""
        with open(os.path.join(INSTANCE, "private", "runner.json"), encoding="utf-8") as f:
            cmd = json.load(f)["runner"]["verificacao"]["cmd"]
        self.assertEqual(cmd[1:3], ["../../../harness/java/evaluate.sh", "verify"], cmd)
        self.assertEqual(len(cmd), 6, "the instance and delivery folders stay empty (the runner's environment gives them), then the selector: %r" % cmd)
        selector = cmd[5]
        folder = os.path.join(INSTANCE, "private", "verify", "src", "test", "java", "com", "condodesk")
        classes = sorted(n[:-5] for n in os.listdir(folder) if n.endswith("Test.java"))
        self.assertTrue(classes)
        for name in classes:
            self.assertTrue(fnmatch.fnmatchcase(name, selector), "%s is not selected by %s" % (name, selector))


if __name__ == "__main__":
    unittest.main()
