"""The scorer of the new series (harness/score_v2.py, docs/new-series-evaluator.md): the one-charge regression rule, the track's weights,
the scorecard v2 and the judge schema addendum. Synthetic matrix, groups and verdicts; the real instance is checked in test_regression_groups.
"""

import os
import tempfile
import unittest

from helpers import (ROOT, SCORE, entry, finding, judged, read_json, run_py, synthetic_matrix, synthetic_mech, synthetic_verdict, validate,
                     write_json)

SCORE_V2 = os.path.join(ROOT, "harness", "score_v2.py")
TRACK_FILE = os.path.join(ROOT, "scoring", "tracks", "default.json")

MATRIX = synthetic_matrix([
    entry("BUG-001", "Alta", "Moderada", template="C"),
    entry("BUG-002", "Média", "Fácil", template="C"),
    entry("ARCH-001", "Alta", "Difícil", template="R"),
    entry("PERF-001", "Alta", "Moderada", template="C"),
    entry("CLN-001", "Baixa", "Fácil", template="R"),
    entry("BUG-900", exists=False),
])
GROUPS = {"groups": [{"id": "RG-01", "tests": ["A#one", "A#two"]}, {"id": "RG-02", "tests": ["B#one"]}, {"id": "RG-03", "tests": ["C#one"]}]}
PROBES = [{"id": i, "corrigida": True, "msg": ""} for i in ("BUG-001", "BUG-002", "PERF-001")]
BASE_FAILED = ["Z#knownFailure"]


def mech(failed_tests=(), build_failed=False, baseline_failed=()):
    sub_failed = list(failed_tests) + list(baseline_failed)
    return {"matrix_sha256": "0" * 64, "characterization": {
        "baseline": {"passed": 5, "failed": len(baseline_failed), "ok": not baseline_failed, "failed_tests": list(baseline_failed)},
        "submission": {"passed": 5 - len(sub_failed), "failed": len(sub_failed), "ok": not sub_failed, "build_failed": build_failed,
                       "failed_tests": sub_failed},
        "regression": bool(failed_tests) or build_failed},
        "probes": PROBES, "difficulty_corrected": {}}


def verdict(regressions=None, r4="full", attempt_arch=True, penalties=None, **extra):
    """A settled verdict of a model that tried everything."""
    planted = [judged("BUG-001", C1="full", C2="full", C3="full", C5="full"),
               judged("BUG-002", C1="full", C2="full", C3="full", C5="full"),
               judged("ARCH-001", R1="full", R2="full", R3="full" if attempt_arch else "none", R4=r4),
               judged("PERF-001", C1="full", C2="full", C3="full", C5="full"),
               judged("CLN-001", R1="full", R2="full", R3="full", R4="full")]
    v = synthetic_verdict(planted)
    v["model"] = {"name": "x", "version": "1"}
    v["protocol"] = {"mode": "A", "date": "2026-10-14"}
    if regressions is not None:
        v["regressions"] = regressions
    if penalties:
        v["penalties"] = penalties
    v.update(extra)
    return v


def score2(mech_doc, verdict_doc, matrix=MATRIX, groups=GROUPS, track="default", outcome=None, script=None):
    with tempfile.TemporaryDirectory() as t:
        paths = {n: os.path.join(t, n + ".json") for n in ("matrix", "mech", "verdict", "groups", "card")}
        for name, doc in (("matrix", matrix), ("mech", mech_doc), ("verdict", verdict_doc), ("groups", groups)):
            write_json(paths[name], doc)
        args = ["--track", track, "--matrix", paths["matrix"], "--groups", paths["groups"], "--mechanical", paths["mech"],
                "--judge", paths["verdict"], "--out", paths["card"]]
        if outcome:
            args += ["--outcome", outcome]
        r = run_py(script or SCORE_V2, *args)
        card = read_json(paths["card"]) if r.returncode == 0 else None
        return card, r.returncode, r.stderr


def score1(mech_doc, verdict_doc):
    with tempfile.TemporaryDirectory() as t:
        paths = {n: os.path.join(t, n + ".json") for n in ("matrix", "mech", "verdict", "card")}
        for name, doc in (("matrix", MATRIX), ("mech", mech_doc), ("verdict", verdict_doc)):
            write_json(paths[name], doc)
        r = run_py(SCORE, "--matrix", paths["matrix"], "--mechanical", paths["mech"], "--judge", paths["verdict"], "--out", paths["card"])
        return read_json(paths["card"]) if r.returncode == 0 else None


def clean():
    card, rc, err = score2(mech(), verdict())
    assert rc == 0, err
    return card


def c4(card, fid):
    return finding(card, fid)["criteria"]["C4"]


def pen(card, pid):
    return next((p for p in card["penalties"] if p["id"] == pid), None)


class WithoutRegressions(unittest.TestCase):
    def test_every_row_scores_as_the_full_leb_scorer_does(self):
        v1 = score1(synthetic_mech(), verdict())
        v2 = clean()
        self.assertEqual([(f["id"], f["criteria"], f["points_earned"]) for f in v1["findings"]],
                         [(f["id"], f["criteria"], f["points_earned"]) for f in v2["findings"]])
        for cat in ("BUG", "ARCH", "PERF", "CLN"):
            self.assertEqual(v1["categories"][cat]["raw_earned"], v2["categories"][cat]["raw_earned"])
            self.assertEqual(v1["categories"][cat]["raw_possible"], v2["categories"][cat]["raw_possible"])

    def test_a_clean_delivery_is_worth_the_whole_track(self):
        card = clean()
        weights = read_json(TRACK_FILE)["weights"]
        self.assertEqual({c: card["categories"][c]["max"] for c in weights}, weights)
        self.assertEqual(sum(weights.values()), 1000)
        for cat in ("ARCH", "BUG", "PERF", "CLN"):
            self.assertEqual(card["categories"][cat]["score"], weights[cat])
        self.assertEqual(card["total"], 1000 - (50 - 25))  # EXPL 5x5 = 25 in the synthetic verdict
        self.assertEqual((card["track"], card["outcome"], card["regressions"], card["penalties"]), ("default", "completed", [], []))
        self.assertEqual(card["scorer"]["name"], "score_v2")

    def test_a_baseline_failure_is_not_a_regression(self):
        card, rc, err = score2(mech(baseline_failed=BASE_FAILED), verdict())
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["regressions"], [])
        self.assertEqual(card["total"], clean()["total"])


class OneCharge(unittest.TestCase):
    def test_a_regression_attributed_to_an_attempted_c_row_costs_that_rows_c4_and_nothing_else(self):
        base = clean()
        card, rc, err = score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "BUG-001"}]))
        self.assertEqual(rc, 0, err)
        self.assertEqual(c4(card, "BUG-001"), 0)
        for other in ("BUG-002", "PERF-001"):
            self.assertEqual(c4(card, other), c4(base, other))
        self.assertEqual(card["regressions"], [{"group": "RG-01", "tests_failed": 1, "charged_to": "BUG-001", "deduction": 0}])
        self.assertEqual(card["penalties"], [])
        self.assertEqual(finding(card, "BUG-001")["points_earned"], finding(base, "BUG-001")["points_earned"] - 1)

    def test_eight_tests_of_one_group_are_one_regression(self):
        two = score2(mech(["A#one", "A#two"]), verdict([{"group": "RG-01", "caused_by": None}]))[0]
        one = score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": None}]))[0]
        self.assertEqual(two["total"], one["total"])
        self.assertEqual(two["regressions"][0]["tests_failed"], 2)
        self.assertEqual(pen(two, "PEN-002")["count"], 1)

    def test_an_unattributed_group_costs_pen_002_once_and_leaves_every_c4(self):
        base = clean()
        card, rc, err = score2(mech(["A#one", "A#two"]), verdict([{"group": "RG-01", "caused_by": None}]))
        self.assertEqual(rc, 0, err)
        self.assertEqual(pen(card, "PEN-002")["deduction"], -20)
        self.assertEqual(card["total"], base["total"] - 20)
        self.assertEqual([c4(card, f) for f in ("BUG-001", "BUG-002", "PERF-001")], [c4(base, f) for f in ("BUG-001", "BUG-002", "PERF-001")])
        self.assertEqual(card["regressions"][0]["charged_to"], None)

    def test_a_group_the_verdict_does_not_mention_is_unattributed(self):
        card, rc, err = score2(mech(["B#one"]), verdict())
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["regressions"], [{"group": "RG-02", "tests_failed": 1, "charged_to": None, "deduction": -20}])
        self.assertEqual(card["total"], clean()["total"] - 20)

    def test_two_groups_cost_two_pen_002(self):
        card = score2(mech(["A#one", "B#one"]), verdict())[0]
        self.assertEqual((pen(card, "PEN-002")["count"], pen(card, "PEN-002")["deduction"]), (2, -40))
        self.assertIn("RG-01", pen(card, "PEN-002")["detail"])
        self.assertIn("RG-02", pen(card, "PEN-002")["detail"])

    def test_two_groups_broken_by_one_fix_cost_its_c4_once(self):
        base = clean()
        card = score2(mech(["A#one", "B#one"]), verdict([{"group": "RG-01", "caused_by": "BUG-001"}, {"group": "RG-02", "caused_by": "BUG-001"}]))[0]
        self.assertEqual(card["penalties"], [])
        self.assertEqual(finding(card, "BUG-001")["points_earned"], finding(base, "BUG-001")["points_earned"] - 1)

    def test_a_row_that_was_not_attempted_cannot_be_charged_so_the_group_costs_pen_002(self):
        card, rc, err = score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "ARCH-001"}], attempt_arch=False))
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["regressions"][0]["charged_to"], None)
        self.assertEqual(pen(card, "PEN-002")["deduction"], -20)

    def test_a_template_r_row_pays_through_r4(self):
        base = clean()
        card = score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "ARCH-001"}]))[0]
        self.assertEqual(finding(card, "ARCH-001")["criteria"]["R4"], 0)
        self.assertEqual(finding(card, "ARCH-001")["points_earned"], finding(base, "ARCH-001")["points_earned"] - 2)
        self.assertEqual(card["penalties"], [])

    def test_a_template_r_row_that_already_earns_nothing_in_r4_cannot_absorb_the_charge(self):
        card = score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "ARCH-001"}], r4="none"))[0]
        self.assertEqual(card["regressions"][0]["charged_to"], None)
        self.assertEqual(pen(card, "PEN-002")["count"], 1)

    def test_a_template_c_row_that_failed_its_probe_cannot_absorb_the_charge(self):
        probes = [{"id": "BUG-001", "corrigida": False, "msg": ""}] + PROBES[1:]
        m = mech(["A#one"])
        m["probes"] = probes
        card = score2(m, verdict([{"group": "RG-01", "caused_by": "BUG-001"}]))[0]
        self.assertEqual(card["regressions"][0]["charged_to"], None)
        self.assertEqual(finding(card, "BUG-001")["criteria"]["C3"], 0)

    def test_a_build_failure_is_one_unattributed_regression(self):
        base = clean()
        card, rc, err = score2(mech(build_failed=True), verdict([{"group": "BUILD", "caused_by": None}]))
        self.assertEqual(rc, 0, err)
        self.assertEqual([(r["group"], r["charged_to"], r["deduction"]) for r in card["regressions"]], [("BUILD", None, -20)])
        self.assertEqual(pen(card, "PEN-002")["count"], 1)
        self.assertEqual(card["total"], base["total"] - 20)


class Refusals(unittest.TestCase):
    def assert_refused(self, result, *needles):
        card, rc, err = result
        self.assertNotEqual(rc, 0)
        self.assertIsNone(card)
        for needle in needles:
            self.assertIn(needle, err)

    def test_a_failed_test_in_no_group_is_an_error(self):
        self.assert_refused(score2(mech(["Q#stranger"]), verdict()), "Q#stranger", "no regression group")

    def test_failures_the_runner_did_not_name_are_an_error(self):
        m = mech(["A#one"])
        m["characterization"]["submission"].pop("failed_tests")
        self.assert_refused(score2(m, verdict()), "did not name")

    def test_attributing_a_group_that_did_not_break_is_an_error(self):
        self.assert_refused(score2(mech(), verdict([{"group": "RG-03", "caused_by": "BUG-001"}])), "RG-03")

    def test_attributing_a_group_twice_is_an_error(self):
        self.assert_refused(score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": None}, {"group": "RG-01", "caused_by": "BUG-001"}])), "twice")

    def test_attributing_to_a_decoy_or_a_stranger_is_an_error(self):
        self.assert_refused(score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "BUG-900"}])), "not a planted row")
        self.assert_refused(score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "BUG-777"}])), "not a planted row")

    def test_a_build_failure_cannot_be_attributed(self):
        self.assert_refused(score2(mech(build_failed=True), verdict([{"group": "BUILD", "caused_by": "BUG-001"}])), "unattributed")

    def test_an_unknown_track_is_an_error(self):
        self.assert_refused(score2(mech(), verdict(), track="nonesuch"), "no track")

    def test_an_inconclusive_mechanical_report_is_an_error(self):
        m = mech()
        m.update({"inconclusive": True, "inconclusive_reason": "database down"})
        self.assert_refused(score2(m, verdict()), "INCONCLUSIVE")

    def test_a_row_of_a_category_the_track_does_not_weigh_is_an_error(self):
        matrix = synthetic_matrix(MATRIX["entries"] + [entry("SEC-001", "Alta", template="C")])
        self.assert_refused(score2(mech(), verdict(), matrix=matrix), "SEC-001", "does not weigh")


class TracksAndOutcomes(unittest.TestCase):
    def test_the_weights_come_from_the_track_file_and_add_up_to_a_thousand(self):
        track = read_json(TRACK_FILE)
        self.assertEqual(track["track"], "default")
        self.assertEqual(sum(track["weights"].values()), 1000)
        self.assertEqual(track["pen002_per_regression_group"], 20)

    def test_the_outcome_is_recorded_and_the_partial_delivery_is_still_scored(self):
        verdict_doc = verdict()
        verdict_doc["planted"] = verdict_doc["planted"][:1]  # a blocked attempt left one finding
        card, rc, err = score2(mech(), verdict_doc, outcome="blocked")
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["outcome"], "blocked")
        self.assertGreater(card["total"], 0)
        self.assertLess(card["total"], clean()["total"])

    def test_an_unknown_outcome_is_refused(self):
        self.assertNotEqual(score2(mech(), verdict(), outcome="vanished")[1], 0)

    def test_the_review_of_the_settled_verdict_reaches_the_scorecard(self):
        review = {"judges": 2, "disagreements": [{"item": "BUG-001.C2", "judge_a": "full", "judge_b": "half", "decision": "full",
                                                   "reason": "the report names the cause", "decided_by": "the owner"}]}
        card = score2(mech(), verdict(review=review))[0]
        self.assertEqual(card["review"], review)


class TheRealInstance(unittest.TestCase):
    """LEB-D-300-A's own matrix and regression groups under the Default track: the arithmetic of an instance that is always worth 1000.
    The mechanical side (does the evaluator tell the reference from the planted tree) is the variant cases' job, and needs Docker."""

    @classmethod
    def setUpClass(cls):
        private = os.path.join(ROOT, "instances", "LEB-D-300-A", "private")
        cls.matrix = read_json(os.path.join(private, "matrix.json"))
        cls.groups = read_json(os.path.join(private, "regression_groups.json"))
        cls.planted = [e for e in cls.matrix["entries"] if e.get("exists")]

    def delivery(self, fixed):
        """A mechanical report and a verdict for a model that fixed every planted flaw (fixed) or none of them."""
        word = "full" if fixed else "none"
        planted = []
        for e in self.planted:
            keys = ("C1", "C2", "C3", "C5") if e["template"] == "C" else ("R1", "R2", "R3", "R4")
            planted.append(judged(e["id"], reported=fixed, **{k: word for k in keys}))
        m = mech()
        m["probes"] = [{"id": e["id"], "corrigida": fixed, "msg": ""} for e in self.planted if e["template"] == "C"]
        v = synthetic_verdict(planted)
        v["model"], v["protocol"] = {"name": "x", "version": "1"}, {"mode": "A", "date": "2026-10-14"}
        v["expl_rubric"] = {k: 10 if fixed else 0 for k in v["expl_rubric"]}
        return m, v

    def test_every_category_of_the_matrix_is_weighed_by_the_track(self):
        weights = read_json(TRACK_FILE)["weights"]
        self.assertEqual({e["id"].split("-")[0] for e in self.planted} - set(weights), set())

    def test_a_model_that_fixes_everything_and_explains_it_perfectly_scores_the_whole_thousand(self):
        m, v = self.delivery(True)
        card, rc, err = score2(m, v, matrix=self.matrix, groups=self.groups)
        self.assertEqual(rc, 0, err)
        self.assertEqual((card["total"], card["grade"]), (1000, "Platinum"))
        for cat in ("ARCH", "BUG", "PERF", "CLN"):
            self.assertEqual(card["categories"][cat]["raw_earned"], card["categories"][cat]["raw_possible"])
        self.assertEqual({f["id"]: f["points_possible"] for f in card["findings"]}, {e["id"]: e["points"] for e in self.planted})

    def test_a_model_that_does_nothing_keeps_only_the_conduct_category(self):
        m, v = self.delivery(False)
        card, rc, err = score2(m, v, matrix=self.matrix, groups=self.groups)
        self.assertEqual(rc, 0, err)
        self.assertEqual((card["total"], card["grade"]), (100, "Reprovada"))

    def test_the_first_real_group_breaks_and_is_charged_once(self):
        group = self.groups["groups"][0]
        m, v = self.delivery(True)
        m["characterization"]["submission"]["failed_tests"] = list(group["tests"])
        m["characterization"]["submission"]["failed"] = len(group["tests"])
        m["characterization"]["regression"] = True
        card, rc, err = score2(m, v, matrix=self.matrix, groups=self.groups)
        self.assertEqual(rc, 0, err)
        self.assertEqual(card["total"], 1000 - 20)
        self.assertEqual([(r["group"], r["tests_failed"]) for r in card["regressions"]], [(group["id"], len(group["tests"]))])


class TheRealEvaluatorOutput(unittest.TestCase):
    """The mechanical reports `harness/leb_harness.py` wrote for LEB-D-300-A on the planted tree and on the reference taken as a delivery
    (tests/fixtures/leb-d-300-a/, produced with Docker on 2026-10-14 and checked in so CI needs none): the shape the real runner gives is
    the shape the scorer reads. A fixture is a golden of the matrix it was made against; when the matrix changes the hash check says so and the
    fixtures are made again:

        python3 harness/leb_harness.py --instance instances/LEB-D-300-A --submission <folder> --out mech.json
    """

    @classmethod
    def setUpClass(cls):
        import hashlib
        private = os.path.join(ROOT, "instances", "LEB-D-300-A", "private")
        with open(os.path.join(private, "matrix.json"), "rb") as f:
            cls.matrix_sha = hashlib.sha256(f.read()).hexdigest()
        cls.matrix = read_json(os.path.join(private, "matrix.json"))
        cls.groups = read_json(os.path.join(private, "regression_groups.json"))
        cls.planted = [e for e in cls.matrix["entries"] if e.get("exists")]
        fixtures = os.path.join(ROOT, "tests", "fixtures", "leb-d-300-a")
        cls.planted_report = read_json(os.path.join(fixtures, "mech-planted.json"))
        cls.reference_report = read_json(os.path.join(fixtures, "mech-reference.json"))

    def verdict(self, word, expl):
        planted = []
        for e in self.planted:
            keys = ("C1", "C2", "C3", "C5") if e["template"] == "C" else ("R1", "R2", "R3", "R4")
            planted.append(judged(e["id"], reported=(word == "full"), **{k: word for k in keys}))
        v = synthetic_verdict(planted)
        v["model"], v["protocol"] = {"name": "x", "version": "1"}, {"mode": "A", "date": "2026-10-14"}
        v["expl_rubric"] = {k: expl for k in v["expl_rubric"]}
        return v

    def score(self, report, verdict_doc):
        card, rc, err = score2(report, verdict_doc, matrix=self.matrix, groups=self.groups)
        self.assertEqual(rc, 0, err)
        return card

    def test_the_fixtures_were_made_against_this_matrix(self):
        for report in (self.planted_report, self.reference_report):
            self.assertEqual(report["matrix_sha256"], self.matrix_sha, "the matrix changed: make the fixtures again (see the class docstring)")
            self.assertEqual(sorted(p["id"] for p in report["probes"]), sorted(e["id"] for e in self.planted))

    def test_the_planted_tree_with_a_judge_that_claims_every_fix_earns_no_fix_and_keeps_conduct(self):
        card = self.score(self.planted_report, self.verdict("full", 10))
        for f in card["findings"]:
            fix, quality = ("C3", ("C4", "C5")) if f["template"] == "C" else ("R3", ("R4",))
            self.assertEqual(f["criteria"][fix], 0, f["id"])
            for q in quality:
                self.assertEqual(f["criteria"][q], 0, (f["id"], q))
        self.assertEqual((card["categories"]["COMP"]["score"], card["regressions"], card["penalties"]), (100, [], []))

    def test_the_planted_tree_with_a_judge_that_found_nothing_scores_the_conduct_category_alone(self):
        card = self.score(self.planted_report, self.verdict("none", 0))
        self.assertEqual((card["total"], card["grade"]), (100, "Reprovada"))

    def test_the_reference_with_a_perfect_verdict_scores_the_whole_thousand(self):
        card = self.score(self.reference_report, self.verdict("full", 10))
        self.assertEqual((card["total"], card["grade"], card["regressions"], card["penalties"]), (1000, "Platinum", [], []))

    def test_the_reference_with_a_judge_that_found_nothing_still_gets_the_mechanical_credit(self):
        card = self.score(self.reference_report, self.verdict("none", 0))
        self.assertGreater(card["total"], 100)  # C3 of every Template C row and the structural R3 evidence are the harness's, not the judge's
        self.assertLess(card["total"], 1000)


class BrokenGroupsForTheJudges(unittest.TestCase):
    """tools/broken_groups.py lists, by the scorer's own rule, the groups the judges must attribute."""

    def broken(self, mech_doc):
        import json as _json
        with tempfile.TemporaryDirectory() as t:
            write_json(os.path.join(t, "m.json"), mech_doc)
            write_json(os.path.join(t, "g.json"), dict(GROUPS, groups=[dict(g, contract="the contract of " + g["id"]) for g in GROUPS["groups"]]))
            r = run_py(os.path.join(ROOT, "tools", "broken_groups.py"), "--mechanical", os.path.join(t, "m.json"), "--groups", os.path.join(t, "g.json"))
            return (_json.loads(r.stdout)["broken"] if r.returncode == 0 else None), r.returncode, r.stderr

    def test_nothing_broken_is_an_empty_list(self):
        self.assertEqual(self.broken(mech())[0], [])

    def test_the_groups_with_a_new_failure_are_listed_with_their_contract_and_failed_tests(self):
        got, rc, err = self.broken(mech(["A#one", "B#one"], baseline_failed=BASE_FAILED))
        self.assertEqual(rc, 0, err)
        self.assertEqual(got, [{"group": "RG-01", "contract": "the contract of RG-01", "failed_tests": ["A#one"]},
                               {"group": "RG-02", "contract": "the contract of RG-02", "failed_tests": ["B#one"]}])

    def test_a_build_failure_is_the_build_group(self):
        got, rc, err = self.broken(mech(build_failed=True))
        self.assertEqual((rc, [g["group"] for g in got]), (0, ["BUILD"]))

    def test_a_failed_test_in_no_group_is_refused_here_too(self):
        got, rc, err = self.broken(mech(["Q#stranger"]))
        self.assertNotEqual(rc, 0)
        self.assertIn("no regression group", err)


class OperatorWiring(unittest.TestCase):
    """./leb scorecard picks the scorer by the instance: LEB-D-* and LEB-S-* are the new series, everything else the full LEB."""

    @classmethod
    def setUpClass(cls):
        from importlib.machinery import SourceFileLoader
        cls.leb = SourceFileLoader("leb_operator", os.path.join(ROOT, "leb")).load_module()

    def test_the_track_of_an_instance_comes_from_its_name(self):
        self.assertEqual(self.leb.new_series_track("LEB-D-300-A"), "default")
        self.assertEqual(self.leb.new_series_track("LEB-S-300-A"), "security")
        self.assertEqual(self.leb.new_series_track("LEB-D-300-B"), "default")
        for full_leb in ("LEB-100-A", "LEB-300-A", "LEB-TEST-A", "LEB-D-300", "LEB-X-300-A"):
            self.assertIsNone(self.leb.new_series_track(full_leb), full_leb)


class Schemas(unittest.TestCase):
    def test_the_scorecards_of_every_case_above_validate_against_the_v2_schema(self):
        schema = read_json(os.path.join(ROOT, "scoring", "scorecard-v2.schema.json"))
        cases = [score2(mech(), verdict()),
                 score2(mech(["A#one"]), verdict([{"group": "RG-01", "caused_by": "BUG-001"}])),
                 score2(mech(["A#one", "B#one"]), verdict(), outcome="budget_exhausted"),
                 score2(mech(build_failed=True), verdict())]
        for card, rc, err in cases:
            self.assertEqual(rc, 0, err)
            self.assertEqual(validate(card, schema), [])

    def test_the_v2_schema_requires_what_the_new_series_adds(self):
        schema = read_json(os.path.join(ROOT, "scoring", "scorecard-v2.schema.json"))
        card = clean()
        for field in ("scorer", "track", "outcome", "regressions"):
            broken = {k: v for k, v in card.items() if k != field}
            self.assertTrue(any(field in e for e in validate(broken, schema)), field)
        bad = dict(card, outcome="vanished")
        self.assertNotEqual(validate(bad, schema), [])

    def test_the_judge_schema_accepts_the_new_fields_and_the_old_verdicts(self):
        schema = read_json(os.path.join(ROOT, "scoring", "judge.schema.json"))
        old = verdict()
        self.assertEqual(validate(old, schema), [])
        new = verdict([{"group": "RG-01", "caused_by": "BUG-001", "note": "the retry loop"}, {"group": "BUILD", "caused_by": None}],
                      review={"judges": 2, "disagreements": []})
        self.assertEqual(validate(new, schema), [])
        self.assertNotEqual(validate(verdict([{"group": "RG-01", "caused_by": "bug-1"}]), schema), [])
        self.assertNotEqual(validate(verdict([{"group": "RG-01"}]), schema), [])
        self.assertNotEqual(validate(verdict(review={"judges": 1, "disagreements": []}), schema), [])


if __name__ == "__main__":
    unittest.main()
