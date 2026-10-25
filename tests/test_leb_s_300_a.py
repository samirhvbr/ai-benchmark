"""LEB-S-300-A: its public material is what the reference and the planting profile give, byte for byte, and its matrix is what its rows
and files give, so nothing the candidate receives, and no row of the key, was edited by hand."""

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


if __name__ == "__main__":
    unittest.main()
