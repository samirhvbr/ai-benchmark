"""Several occurrences of one taxonomy type use the suffix already normative in MATRIX §2 (`BUG-011.a`, `BUG-011.b`).

The type is the part before the dot; each occurrence is scored on its own and the category is normalized
over all of them (SCORING §4). No new identifier field exists or is needed.
"""

import unittest

import helpers
from helpers import entry, finding, judged, score_files, synthetic_matrix, synthetic_mech, synthetic_verdict

FULL = dict(C1="full", C2="full", C3="full", C4="full", C5="full")


def probe(fid, fixed):
    return {"id": fid, "difficulty": "Moderada", "corrigida": fixed, "msg": ""}


class SuffixedOccurrences(unittest.TestCase):
    def setUp(self):
        self.matrix = synthetic_matrix([entry("BUG-011.a", "Crítica", "Especialista"), entry("BUG-011.b", "Alta", "Difícil"),
                                        entry("SEC-001", "Alta")])

    def card(self, probes, verdict_for_b):
        v = synthetic_verdict([judged("BUG-011.a", **FULL), judged("BUG-011.b", **verdict_for_b), judged("SEC-001", **FULL)])
        card, rc, err = score_files(self.matrix, synthetic_mech(probes=probes), v)
        self.assertEqual(rc, 0, err)
        return card

    def test_occurrences_are_scored_independently(self):
        both = self.card([probe("BUG-011.a", True), probe("BUG-011.b", True), probe("SEC-001", True)], FULL)
        only_a = self.card([probe("BUG-011.a", True), probe("BUG-011.b", False), probe("SEC-001", True)],
                           dict(C1="full", C2="full", C3="none"))
        self.assertEqual(finding(both, "BUG-011.a")["points_earned"], 10)
        self.assertEqual(finding(both, "BUG-011.b")["points_earned"], 8)
        # Not fixing the second occurrence leaves the first untouched.
        self.assertEqual(finding(only_a, "BUG-011.a")["points_earned"], 10)
        self.assertLess(finding(only_a, "BUG-011.b")["points_earned"], 8)

    def test_category_is_normalized_over_all_occurrences(self):
        card = self.card([probe("BUG-011.a", True), probe("BUG-011.b", False), probe("SEC-001", True)],
                         dict(C1="full", C2="full", C3="none"))
        a, b = finding(card, "BUG-011.a"), finding(card, "BUG-011.b")
        possible = a["points_possible"] + b["points_possible"]
        earned = a["points_earned"] + b["points_earned"]
        self.assertEqual(card["categories"]["BUG"]["score"], round(earned / possible * 150))

    def test_the_type_before_the_dot_picks_the_category(self):
        card = self.card([probe("BUG-011.a", True), probe("BUG-011.b", True), probe("SEC-001", True)], FULL)
        self.assertEqual(card["categories"]["BUG"]["raw_possible"], 18)
        self.assertEqual(card["categories"]["SEC"]["raw_possible"], 8)

    def test_a_matrix_without_suffixes_is_unchanged(self):
        matrix = synthetic_matrix([entry("BUG-011", "Crítica"), entry("SEC-001", "Alta")])
        v = synthetic_verdict([judged("BUG-011", **FULL), judged("SEC-001", **FULL)])
        card, rc, err = score_files(matrix, synthetic_mech(probes=[probe("BUG-011", True), probe("SEC-001", True)]), v)
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["categories"]["BUG"]["score"], 150)


if __name__ == "__main__":
    unittest.main()
