"""tools/judge_review.py: two judges' verdicts, their disagreements, and the settled verdict only once a person has decided each one
(docs/new-series-evaluator.md section 4). Synthetic verdicts; the last test feeds a settled verdict to the new scorer."""

import copy
import json
import os
import tempfile
import unittest

from helpers import ROOT, read_json, run_py, validate, write_json
from test_score_v2 import GROUPS, mech, score2, verdict

REVIEW = os.path.join(ROOT, "tools", "judge_review.py")
JUDGE_SCHEMA = os.path.join(ROOT, "scoring", "judge.schema.json")


def judge(name, **changes):
    v = verdict([{"group": "RG-01", "caused_by": "BUG-001"}])
    v["judge"] = {"type": "llm", "id": name}
    v.update(changes)
    return v


def row(v, fid):
    return next(p for p in v["planted"] if p["id"] == fid)


class Run:
    """judge_review.py on in-memory verdicts."""

    def __init__(self):
        self.dir = tempfile.TemporaryDirectory()

    def path(self, name):
        return os.path.join(self.dir.name, name)

    def call(self, cmd, a, b, decisions=None, out=None):
        write_json(self.path("a.json"), a)
        write_json(self.path("b.json"), b)
        args = [cmd, self.path("a.json"), self.path("b.json")]
        if decisions is not None:
            write_json(self.path("decisions.json"), decisions)
            args += ["--decisions", self.path("decisions.json")]
        out = self.path(out or ("settled.json" if cmd == "settle" else "disagreements.json"))
        args += ["--out", out]
        r = run_py(REVIEW, *args)
        return (read_json(out) if r.returncode == 0 and os.path.exists(out) else None), r.returncode, r.stdout, r.stderr


class ReviewCase(unittest.TestCase):
    def setUp(self):
        self.run_ = Run()
        self.addCleanup(self.run_.dir.cleanup)

    def compare(self, a, b):
        doc, rc, out, err = self.run_.call("compare", a, b)
        self.assertEqual(rc, 0, err)
        return doc["disagreements"]

    def settle(self, a, b, decisions=None):
        return self.run_.call("settle", a, b, decisions)

    @staticmethod
    def decision(item, value, reason="the report names the cause", by="the owner"):
        return {"item": item, "decision": value, "reason": reason, "decided_by": by}


class Agreement(ReviewCase):
    def test_identical_verdicts_have_no_disagreement_and_settle_without_a_decision(self):
        a, b = judge("a"), judge("b")
        self.assertEqual(self.compare(a, b), [])
        settled, rc, _, err = self.settle(a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["review"], {"judges": 2, "disagreements": []})
        self.assertEqual(validate(settled, read_json(JUDGE_SCHEMA)), [])
        self.assertEqual(settled["judge"], {"type": "panel", "id": "a + b"})
        self.assertEqual(settled["regressions"], [{"group": "RG-01", "caused_by": "BUG-001"}])
        self.assertEqual({p["id"]: p["criteria"] for p in settled["planted"]}, {p["id"]: p["criteria"] for p in a["planted"]})
        self.assertEqual(settled["expl_rubric"], a["expl_rubric"])

    def test_a_row_one_judge_left_out_counts_as_not_reported_and_none(self):
        a, b = judge("a"), judge("b")
        b["planted"] = [p for p in b["planted"] if p["id"] != "CLN-001"]
        items = {d["item"] for d in self.compare(a, b)}
        self.assertIn("CLN-001.reported", items)
        self.assertIn("CLN-001.R3", items)


class Disagreements(ReviewCase):
    def test_a_criterion_that_differs_is_listed_with_both_values(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C2"] = "half"
        self.assertEqual(self.compare(a, b), [{"item": "BUG-001.C2", "judge_a": "full", "judge_b": "half", "decision": None,
                                               "reason": "", "decided_by": ""}])

    def test_settling_is_refused_until_a_person_has_decided_and_never_averages(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C2"] = "half"
        settled, rc, _, err = self.settle(a, b)
        self.assertNotEqual(rc, 0)
        self.assertIsNone(settled)
        self.assertIn("no decision for BUG-001.C2", err)
        settled, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("BUG-001.C2", "half", reason="the report says it only for the happy path")]})
        self.assertEqual(rc, 0, err)
        self.assertEqual(row(settled, "BUG-001")["criteria"]["C2"], "half")
        self.assertEqual(settled["review"]["disagreements"], [{
            "item": "BUG-001.C2", "judge_a": "full", "judge_b": "half", "decision": "half",
            "reason": "the report says it only for the happy path", "decided_by": "the owner"}])

    def test_a_decision_without_a_reason_or_a_name_is_not_a_decision(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C2"] = "half"
        for bad in (self.decision("BUG-001.C2", "full", reason=""), self.decision("BUG-001.C2", "full", by=" "),
                    {"item": "BUG-001.C2", "reason": "x", "decided_by": "y"}):
            _, rc, _, err = self.settle(a, b, {"disagreements": [bad]})
            self.assertNotEqual(rc, 0)
            self.assertIn("no decision for BUG-001.C2", err)

    def test_a_decision_about_something_the_judges_agreed_on_is_refused(self):
        a, b = judge("a"), judge("b")
        _, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("BUG-001.C2", "none")]})
        self.assertNotEqual(rc, 0)
        self.assertIn("not a disagreement", err)

    def test_a_decision_of_the_wrong_shape_is_refused(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C2"] = "half"
        row(b, "BUG-002")["confidence"] = 40
        for item, value, needle in (("BUG-001.C2", "maybe", "full, half or none"), ("BUG-002.confidence", 140, "0 to 100")):
            _, rc, _, err = self.settle(a, b, {"disagreements": [self.decision(item, value)]})
            self.assertNotEqual(rc, 0)
            self.assertIn(needle, err)

    def test_the_reported_flag_and_the_confidence_are_decided_like_any_criterion(self):
        a, b = judge("a"), judge("b")
        row(b, "CLN-001")["reported"] = False
        row(b, "BUG-002")["confidence"] = 60
        items = {d["item"]: d for d in self.compare(a, b)}
        self.assertEqual((items["CLN-001.reported"]["judge_a"], items["CLN-001.reported"]["judge_b"]), (True, False))
        self.assertEqual((items["BUG-002.confidence"]["judge_a"], items["BUG-002.confidence"]["judge_b"]), (80, 60))
        settled, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("CLN-001.reported", True), self.decision("BUG-002.confidence", 80)]})
        self.assertEqual(rc, 0, err)
        self.assertTrue(row(settled, "CLN-001")["reported"])
        self.assertEqual(row(settled, "BUG-002")["confidence"], 80)

    def test_comp_penalties_and_false_positives_are_compared(self):
        a, b = judge("a"), judge("b")
        b["comp_violations"] = [{"id": "COMP-001", "count": 2, "detail": "changed a route"}]
        b["penalties"] = {"PEN-003": 1}
        a["false_positives"] = [{"reported_as": "BUG-900", "is_isca": True, "confidence": 70}]
        items = {d["item"]: (d["judge_a"], d["judge_b"]) for d in self.compare(a, b)}
        self.assertEqual(items["comp:COMP-001"], (0, 2))
        self.assertEqual(items["PEN-003"], (0, 1))
        self.assertEqual(items["false_positive:BUG-900"], ({"is_isca": True, "confidence": 70}, None))
        settled, rc, _, err = self.settle(a, b, {"disagreements": [
            self.decision("comp:COMP-001", 1), self.decision("PEN-003", 0), self.decision("false_positive:BUG-900", None)]})
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["comp_violations"], [{"id": "COMP-001", "count": 1, "detail": "changed a route"}])
        self.assertEqual((settled["penalties"], settled["false_positives"]), ({}, []))

    def test_a_false_positive_both_judges_found_is_kept_as_it_is(self):
        a, b = judge("a"), judge("b")
        for v in (a, b):
            v["false_positives"] = [{"reported_as": "BUG-900", "is_isca": True, "confidence": 70}]
        settled, rc, _, err = self.settle(a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["false_positives"], [{"reported_as": "BUG-900", "is_isca": True, "confidence": 70}])

    def test_a_regression_attributed_differently_is_a_disagreement_and_an_absent_one_means_no_attribution(self):
        a, b = judge("a"), judge("b")
        b["regressions"] = []
        items = {d["item"]: (d["judge_a"], d["judge_b"]) for d in self.compare(a, b)}
        self.assertEqual(items, {"regression:RG-01": ("BUG-001", None)})
        settled, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("regression:RG-01", None, reason="the change was outside the matrix")]})
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["regressions"], [{"group": "RG-01", "caused_by": None}])
        _, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("regression:RG-01", "bug-1")]})
        self.assertNotEqual(rc, 0)


class Explanation(ReviewCase):
    def test_dimensions_within_two_points_are_one_value_the_mean_rounded_down(self):
        a, b = judge("a"), judge("b")
        a["expl_rubric"] = {"clareza": 8, "precisao": 5, "causa_raiz": 6, "priorizacao": 7, "trade_offs": 4}
        b["expl_rubric"] = {"clareza": 7, "precisao": 5, "causa_raiz": 8, "priorizacao": 7, "trade_offs": 4}
        self.assertEqual(self.compare(a, b), [])
        settled, rc, _, err = self.settle(a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["expl_rubric"], {"clareza": 7, "precisao": 5, "causa_raiz": 7, "priorizacao": 7, "trade_offs": 4})

    def test_a_dimension_more_than_two_points_apart_is_a_disagreement_a_person_decides(self):
        a, b = judge("a"), judge("b")
        b["expl_rubric"] = dict(a["expl_rubric"], causa_raiz=9)  # a has 5
        self.assertEqual([(d["item"], d["judge_a"], d["judge_b"]) for d in self.compare(a, b)], [("EXPL.causa_raiz", 5, 9)])
        settled, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("EXPL.causa_raiz", 7)]})
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["expl_rubric"]["causa_raiz"], 7)
        _, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("EXPL.causa_raiz", 11)]})
        self.assertNotEqual(rc, 0)


class SameDelivery(ReviewCase):
    def test_verdicts_about_different_deliveries_cannot_be_settled_together(self):
        for field, value in (("instance", "LEB-OTHER-A"), ("matrix_sha256", "1" * 64), ("model", {"name": "y", "version": "2"})):
            a, b = judge("a"), judge("b")
            b[field] = value
            _, rc, _, err = self.run_.call("compare", a, b)
            self.assertNotEqual(rc, 0, field)
            self.assertIn(field if field != "model" else "different models", err)

    def test_an_invalid_verdict_is_refused(self):
        a, b = judge("a"), judge("b")
        del b["expl_rubric"]
        _, rc, _, err = self.run_.call("compare", a, b)
        self.assertNotEqual(rc, 0)
        self.assertIn("not a valid verdict", err)


class Roundtrip(ReviewCase):
    def test_the_compare_file_filled_in_by_the_person_is_what_settle_reads_and_the_scorer_takes_the_result(self):
        a, b = judge("a"), judge("b")
        b["regressions"] = [{"group": "RG-01", "caused_by": None}]
        row(b, "PERF-001")["criteria"]["C2"] = "none"
        doc, rc, _, err = self.run_.call("compare", a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual([d["item"] for d in doc["disagreements"]], ["PERF-001.C2", "regression:RG-01"])
        filled = copy.deepcopy(doc)
        filled["disagreements"][0].update(decision="full", reason="the report states the hazard", decided_by="the owner")
        filled["disagreements"][1].update(decision="BUG-001", reason="the retry loop of BUG-001 broke it", decided_by="the owner")
        settled, rc, _, err = self.run_.call("settle", a, b, filled)
        self.assertEqual(rc, 0, err)
        card, rc, err = score2(mech(["A#one"]), settled)
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["regressions"], [{"group": "RG-01", "tests_failed": 1, "charged_to": "BUG-001", "deduction": 0}])
        self.assertEqual([d["item"] for d in card["review"]["disagreements"]], ["PERF-001.C2", "regression:RG-01"])
        self.assertEqual(card["review"]["disagreements"][1]["decided_by"], "the owner")


if __name__ == "__main__":
    unittest.main()
