"""LEB-D-300-A: its public material is what the reference and the planting profile give, byte for byte, so nothing the candidate
receives was edited by hand."""

import os
import unittest

from helpers import ROOT, run_py

BUILD = os.path.join(ROOT, "instances", "LEB-D-300-A", "private", "build_public.py")


class PublicMaterial(unittest.TestCase):
    def test_public_is_a_fresh_build_of_the_reference_and_the_profile(self):
        r = run_py(BUILD, "--check")
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)


if __name__ == "__main__":
    unittest.main()
