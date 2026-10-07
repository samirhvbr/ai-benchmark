"""E4: structural evidence for the refactoring criterion of Template R. The rule: evidence is a ceiling, never a floor.

Synthetic matrix, synthetic verdicts. Old instances (no probe with `proves`) are covered by test_historico (95 of 95).
"""

import os
import unittest

from helpers import (ROOT, entry, finding, judged, read_json, score_files, synthetic_matrix, synthetic_mech, synthetic_verdict,
                     validate)

MATRIX = synthetic_matrix([entry("ARCH-004", "Alta", "Moderada", template="R"),
                           entry("BUG-011.a", "Alta", "Dificil", template="C")])
BUG_OK = {"id": "BUG-011.a", "corrigida": True, "msg": ""}
POINTS = {"none": 0, "half": 2, "full": 4}  # Alta, Template R: R3 is worth 4


def ev(result, proves=("R3",)):
    return {"id": "ARCH-004", "result": result, "proves": list(proves)}


def verdict(r3):
    criteria = {"R1": "full", "R2": "full", "R4": "full"}
    if r3 is not None:
        criteria["R3"] = r3
    return synthetic_verdict([judged("ARCH-004", **criteria),
                              judged("BUG-011.a", C1="full", C2="full", C3="full", C5="full")])


def run(probes, r3):
    card, rc, err = score_files(MATRIX, synthetic_mech(probes=probes), verdict(r3))
    return (finding(card, "ARCH-004") if card else None), rc, err, card


class EvidenceIsACeiling(unittest.TestCase):
    def assert_r3(self, probes, judge_r3, expected):
        f, rc, err, _ = run(probes, judge_r3)
        self.assertEqual(rc, 0, err)
        self.assertEqual(f["criteria"]["R3"], expected)
        # Same verdict with no evidence at all: evidence may lower the score, never raise it, and never touch R1/R2.
        control, _, _, _ = run([BUG_OK], judge_r3)
        self.assertLessEqual(f["points_earned"], control["points_earned"])
        for k in ("R1", "R2"):
            self.assertEqual(f["criteria"][k], control["criteria"][k])
        return f

    def test_evidence_none_overrides_a_judge_full(self):
        f = self.assert_r3([BUG_OK, ev("none")], "full", 0)
        self.assertEqual(f["evidence"], {"R3": "none", "judge_R3": "full", "applied_R3": "none"})
        self.assertEqual(f["criteria"]["R4"], 0)  # the existing rule: R4 only counts if a refactoring was attempted

    def test_evidence_half_caps_a_judge_full(self):
        self.assert_r3([BUG_OK, ev("half")], "full", POINTS["half"])

    def test_evidence_full_does_not_raise_a_judge_none(self):
        self.assert_r3([BUG_OK, ev("full")], "none", 0)

    def test_evidence_full_and_judge_full_is_full(self):
        f = self.assert_r3([BUG_OK, ev("full")], "full", POINTS["full"])
        self.assertEqual(f["evidence"]["applied_R3"], "full")

    def test_a_judge_that_omitted_r3_counts_as_none(self):
        self.assert_r3([BUG_OK, ev("full")], None, 0)

    def test_evidence_half_does_not_raise_a_judge_half_or_none(self):
        self.assert_r3([BUG_OK, ev("half")], "half", POINTS["half"])
        self.assert_r3([BUG_OK, ev("half")], "none", 0)

    def test_without_evidence_nothing_changes_and_there_is_no_evidence_key(self):
        f, rc, err, _ = run([BUG_OK], "full")
        self.assertEqual(rc, 0, err)
        self.assertEqual(f["criteria"]["R3"], POINTS["full"])
        self.assertNotIn("evidence", f)

    def test_the_old_boolean_probe_is_still_ignored_for_template_r(self):
        f, rc, err, _ = run([BUG_OK, {"id": "ARCH-004", "corrigida": True, "msg": ""}], "none")
        self.assertEqual(rc, 0, err)
        self.assertEqual(f["criteria"]["R3"], 0)
        self.assertNotIn("evidence", f)

    def test_evidence_naming_another_criterion_is_refused(self):
        for proves in (["R1"], ["R3", "C4"], ["R2"]):
            with self.subTest(proves=proves):
                _, rc, err, _ = run([BUG_OK, ev("full", proves)], "full")
                self.assertNotEqual(rc, 0)
                self.assertIn("só pode comprovar C3 ou R3", err)

    def test_proving_r3_without_a_result_is_refused(self):
        _, rc, err, _ = run([BUG_OK, {"id": "ARCH-004", "proves": ["R3"]}], "full")
        self.assertNotEqual(rc, 0)
        self.assertIn("`result`", err)

    def test_c3_still_comes_from_the_boolean_for_template_c(self):
        _, rc, err, card = run([{"id": "BUG-011.a", "corrigida": False}, ev("full")], "full")
        self.assertEqual(rc, 0, err)
        self.assertEqual(finding(card, "BUG-011.a")["criteria"]["C3"], 0)


class Schemas(unittest.TestCase):
    def test_scorecard_with_evidence_validates_against_the_schema(self):
        _, rc, err, card = run([BUG_OK, ev("half")], "full")
        self.assertEqual(rc, 0, err)
        schema = read_json(os.path.join(ROOT, "scoring", "scorecard.schema.json"))
        self.assertEqual([e for e in validate(card, schema) if "evidence" in e], [])

    def test_probe_result_schema_accepts_old_and_new_forms_and_rejects_foreign_proof(self):
        schema = read_json(os.path.join(ROOT, "scoring", "probe-result.schema.json"))
        self.assertEqual(validate({"id": "SEC-001", "corrigida": True, "msg": ""}, schema), [])
        self.assertEqual(validate({"id": "ARCH-001.b", "result": "half", "proves": ["R3"], "unobserved": ["x"]}, schema), [])
        self.assertNotEqual(validate({"id": "ARCH-001", "result": "full", "proves": ["R1"]}, schema), [])
        self.assertNotEqual(validate({"id": "sec-1", "corrigida": True}, schema), [])


if __name__ == "__main__":
    unittest.main()
