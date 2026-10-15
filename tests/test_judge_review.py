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

    def settle(self, a, b, decisions=None, bind=True):
        """Settle with the person's decisions. A person decides on what `compare --out` wrote, which already carries the verdicts' hashes and the
        judges' values; the tests write only the decision, so `bind` copies the rest from a real compare (a test of the binding says bind=False)."""
        if decisions is not None and bind:
            doc, rc, _, err = self.run_.call("compare", a, b)
            self.assertEqual(rc, 0, err)
            current = {d["item"]: d for d in doc["disagreements"]}
            decisions = copy.deepcopy(decisions)
            decisions.setdefault("verdicts_sha256", doc["verdicts_sha256"])
            for entry in decisions.get("disagreements", []):
                if entry.get("item") in current:
                    entry.setdefault("judge_a", current[entry["item"]]["judge_a"])
                    entry.setdefault("judge_b", current[entry["item"]]["judge_b"])
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


class DecisionsAreBound(ReviewCase):
    """A decision belongs to the two verdict files and the judge values it was made for: matching by the name of the item alone would apply
    it to a disagreement it never saw."""

    def disagreeing(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C2"] = "half"
        return a, b

    def test_compare_writes_the_hash_of_both_verdicts(self):
        a, b = self.disagreeing()
        doc, rc, _, err = self.run_.call("compare", a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(set(doc["verdicts_sha256"]), {"judge_a", "judge_b"})
        for h in doc["verdicts_sha256"].values():
            self.assertRegex(h, "^[a-f0-9]{64}$")

    def test_a_decision_made_for_other_judge_values_is_refused(self):
        a, b = self.disagreeing()
        stale = dict(self.decision("BUG-001.C2", "full"), judge_a="none", judge_b="half")
        _, rc, _, err = self.settle(a, b, {"disagreements": [stale]})
        self.assertNotEqual(rc, 0)
        self.assertIn("other judge values", err)

    def test_a_decision_that_does_not_say_which_values_it_judged_is_refused(self):
        a, b = self.disagreeing()
        _, rc, _, err = self.settle(a, b, {"disagreements": [self.decision("BUG-001.C2", "full")]}, bind=False)
        self.assertNotEqual(rc, 0)
        self.assertIn("verdicts_sha256", err)
        doc, _, _, _ = self.run_.call("compare", a, b)
        bare = {"verdicts_sha256": doc["verdicts_sha256"], "disagreements": [self.decision("BUG-001.C2", "full")]}
        _, rc, _, err = self.settle(a, b, bare, bind=False)
        self.assertNotEqual(rc, 0)
        self.assertIn("other judge values", err)

    def test_decisions_made_for_two_other_verdict_files_are_refused_even_when_the_item_name_matches(self):
        a, b = self.disagreeing()
        doc, rc, _, err = self.run_.call("compare", a, b)
        doc["disagreements"][0].update(decision="full", reason="the report names the cause", decided_by="the owner")
        b2 = copy.deepcopy(b)
        b2["judge"] = {"type": "llm", "id": "another judge"}   # the same disagreement, another file
        _, rc, _, err = self.run_.call("settle", a, b2, doc)
        self.assertNotEqual(rc, 0)
        self.assertIn("not made for these two verdicts", err)

    def test_the_file_compare_wrote_and_the_person_filled_in_settles(self):
        a, b = self.disagreeing()
        doc, rc, _, err = self.run_.call("compare", a, b)
        doc["disagreements"][0].update(decision="half", reason="only for the happy path", decided_by="the owner")
        settled, rc, _, err = self.run_.call("settle", a, b, doc)
        self.assertEqual(rc, 0, err)
        self.assertEqual(row(settled, "BUG-001")["criteria"]["C2"], "half")


class ExtraFindings(ReviewCase):
    def test_the_extras_of_both_judges_are_listed_once_each_whatever_the_spacing_or_case(self):
        a, b = judge("a"), judge("b")
        a["extra_findings"] = ["The OFX parser accepts any root element", "Billing references are fixed width"]
        b["extra_findings"] = ["the ofx  parser accepts any root element", "A third thing"]
        settled, rc, _, err = self.settle(a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["extra_findings"], ["The OFX parser accepts any root element", "Billing references are fixed width", "A third thing"])

    def test_a_finding_the_settled_verdict_calls_a_false_positive_is_not_also_an_extra(self):
        a, b = judge("a"), judge("b")
        for v in (a, b):
            v["false_positives"] = [{"reported_as": "Billing references are fixed width", "is_isca": False}]
        a["extra_findings"] = ["billing references are  fixed width", "The OFX parser accepts any root element"]
        settled, rc, _, err = self.settle(a, b)
        self.assertEqual(rc, 0, err)
        self.assertEqual(settled["extra_findings"], ["The OFX parser accepts any root element"])
        self.assertEqual([f["reported_as"] for f in settled["false_positives"]], ["Billing references are fixed width"])


class StructureAtTheDoor(ReviewCase):
    def test_a_verdict_with_half_on_a_binary_criterion_or_a_repeated_row_is_refused_at_compare(self):
        a, b = judge("a"), judge("b")
        row(b, "BUG-001")["criteria"]["C5"] = "half"
        _, rc, _, err = self.run_.call("compare", a, b)
        self.assertNotEqual(rc, 0)
        self.assertIn("binary", err)
        b = judge("b")
        b["planted"].append(copy.deepcopy(row(b, "BUG-001")))
        _, rc, _, err = self.run_.call("compare", a, b)
        self.assertNotEqual(rc, 0)
        self.assertIn("appears twice", err)


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
