"""The published LEB-100-A results are the regression baseline of every tooling change.

Nothing here edits history: the tests read what is published and prove the tooling still produces it.
"""

import glob
import os
import tempfile
import unittest

import helpers
from helpers import ROOT, read_json, run_py

INSTANCE = os.path.join(ROOT, "instances", "LEB-100-A")
RESULTS = os.path.join(ROOT, "results")


class PublishedHistory(unittest.TestCase):
    def test_every_published_scorecard_is_reproduced_by_score_py(self):
        """score.py applied to a run's own mechanical report and verdict gives the published scorecard.json
        (compared as parsed JSON; `cost_time` is attached by the operator from the client's own summary)."""
        matrix = os.path.join(INSTANCE, "private", "matrix.json")
        cards = sorted(glob.glob(os.path.join(RESULTS, "2026", "LEB-100-A", "*", "*", "scorecard.json")))
        self.assertGreater(len(cards), 90)
        bad = []
        with tempfile.TemporaryDirectory() as t:
            for card in cards:
                d = os.path.dirname(card)
                out = os.path.join(t, "card.json")
                r = run_py(helpers.SCORE, "--matrix", matrix, "--mechanical", os.path.join(d, "mecanico.json"),
                           "--judge", os.path.join(d, "veredito.json"), "--out", out)
                if r.returncode != 0:
                    bad.append((d, r.stderr[-100:]))
                    continue
                new, old = read_json(out), read_json(card)
                new.pop("cost_time", None)
                old.pop("cost_time", None)
                if new != old:
                    bad.append((os.path.relpath(d, ROOT), "differs"))
        self.assertEqual(bad, [], "scorecards not reproduced: %s" % bad[:5])

    def test_package_hash_is_the_published_one(self):
        """The LEB-100-A package, rebuilt in the published protocol (mode A, 30 turns), has the published SHA-256."""
        published = read_json(os.path.join(RESULTS, "results.json"))["instances"][0]
        self.assertEqual((published["id"], published["mode"], published["turn_budget"]), ("LEB-100-A", "A", 30))
        with tempfile.TemporaryDirectory() as t:
            r = run_py(helpers.PACK, "--instance", INSTANCE, "--out", os.path.join(t, "pacote"), "--mode", "A", "--turnos", "30")
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertEqual(r.stdout.strip(), published["package_sha256"])

    def test_export_check_finds_nothing_to_change(self):
        """tools/export-results.py --check exits 0: the published results match their own inputs."""
        r = run_py(os.path.join(ROOT, "tools", "export-results.py"), "--check")
        self.assertEqual(r.returncode, 0, r.stderr)


if __name__ == "__main__":
    unittest.main()
