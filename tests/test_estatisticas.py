"""tools/estatisticas.py: the figures of design sections 9 and 11 from synthetic attempts, with known answers."""

import json
import os
import tempfile
import unittest

from helpers import ROOT, run_py, write_json

TOOL = os.path.join(ROOT, "tools", "estatisticas.py")


def attempt(configuration, system, run, score, outcome="completed", track="default", blocked_at=None):
    return {"configuration": configuration, "track": track, "system": system, "run": run, "outcome": outcome,
            "blocked_at": blocked_at, "score": score}


def grid(configuration, scores, systems="ABC", runs=5, **extra):
    """scores: {system: [score per run]}"""
    return [attempt(configuration, s, r + 1, scores[s][r], **extra) for s in systems for r in range(runs)]


def run_tool(*args):
    r = run_py(TOOL, *args)
    return (json.loads(r.stdout) if r.returncode == 0 and r.stdout.strip().startswith(("{", "[")) else None), r.returncode, r.stderr


def report(rows, *args):
    with tempfile.TemporaryDirectory() as t:
        path = os.path.join(t, "attempts.jsonl")
        with open(path, "w", encoding="utf-8") as f:
            f.write("\n".join(json.dumps(r) for r in rows))
        return run_tool("report", path, *args)


class Validation(unittest.TestCase):
    def assert_refused(self, rows, needle):
        doc, rc, err = report(rows)
        self.assertNotEqual(rc, 0)
        self.assertIn(needle, err)

    def test_the_records_are_checked(self):
        ok = attempt("m", "A", 1, 800)
        self.assert_refused([dict(ok, outcome="vanished")], "unknown outcome")
        self.assert_refused([dict(ok, system=None)], "no system")
        self.assert_refused([dict(ok, outcome="blocked", score=300)], "blocked_at")
        self.assert_refused([dict(ok, blocked_at="start")], "blocked_at belongs")
        self.assert_refused([dict(ok, score=None)], "scored on the folder it left")
        self.assert_refused([dict(ok, score=1200)], "from 0 to 1000")
        self.assert_refused([ok, ok], "twice")

    def test_a_lab_failure_needs_no_score(self):
        doc, rc, err = report([attempt("m", "A", 1, None, outcome="lab_failure"), attempt("m", "A", 2, 700)])
        self.assertEqual(rc, 0, err)
        self.assertEqual(doc[0]["outcomes"]["lab_failure_to_rerun"], 1)
        self.assertEqual(doc[0]["scheduled"], 1)


class Report(unittest.TestCase):
    SCORES = {"A": [800, 820, 780, 810, 790], "B": [600, 620, 580, 610, 590], "C": [700, 720, 680, 710, 690]}

    def test_all_completed_the_quality_is_the_mean_and_the_interval_surrounds_it(self):
        doc, rc, err = report(grid("m", self.SCORES))
        self.assertEqual(rc, 0, err)
        r = doc[0]
        self.assertEqual((r["configuration"], r["track"], r["scheduled"]), ("m", "default", 15))
        q = r["quality_of_evaluable_deliveries"]
        self.assertEqual((q["n"], q["systems"], q["mean"]), (15, 3, 700.0))
        self.assertLessEqual(q["low"], 700.0)
        self.assertGreaterEqual(q["high"], 700.0)
        self.assertLess(q["low"], q["high"])
        self.assertEqual(r["completion"]["rate"], 1.0)
        self.assertEqual(r["completion"]["all_attempts_completed_on"], {"systems": 3, "of": 3, "share": 1.0})
        self.assertEqual(r["expected_score_per_scheduled_attempt"], q)

    def test_blocked_and_exhausted_attempts_count_in_the_expected_score_and_not_in_the_quality(self):
        rows = grid("m", self.SCORES)
        rows[-1] = attempt("m", "C", 5, 100, outcome="blocked", blocked_at="work")
        rows[-2] = attempt("m", "C", 4, 120, outcome="blocked", blocked_at="start")
        rows[0] = attempt("m", "A", 1, 300, outcome="budget_exhausted")
        doc, rc, err = report(rows)
        self.assertEqual(rc, 0, err)
        r = doc[0]
        self.assertEqual(r["completion"]["completed"], 12)
        self.assertEqual(r["completion"]["rate"], 0.8)
        self.assertEqual(r["completion"]["all_attempts_completed_on"], {"systems": 1, "of": 3, "share": 0.333})
        self.assertEqual(r["quality_of_evaluable_deliveries"]["n"], 12)
        self.assertEqual(r["expected_score_per_scheduled_attempt"]["n"], 15)
        self.assertLess(r["expected_score_per_scheduled_attempt"]["mean"], r["quality_of_evaluable_deliveries"]["mean"])
        self.assertEqual({k: r["outcomes"][k] for k in ("completed", "blocked", "blocked_at_the_start", "blocked_during_the_work", "budget_exhausted")},
                         {"completed": 12, "blocked": 2, "blocked_at_the_start": 1, "blocked_during_the_work": 1, "budget_exhausted": 1})
        exact = (sum(sum(v) for v in self.SCORES.values()) - 800 - 690 - 710 + 300 + 100 + 120) / 15
        self.assertAlmostEqual(r["expected_score_per_scheduled_attempt"]["mean"], round(exact, 1), places=1)

    def test_switched_attempts_are_left_out_and_counted(self):
        rows = grid("m", self.SCORES)
        rows[3] = attempt("m", "A", 4, None, outcome="switched")
        doc, rc, err = report(rows)
        self.assertEqual(rc, 0, err)
        self.assertEqual((doc[0]["scheduled"], doc[0]["outcomes"]["switched"]), (14, 1))

    def test_the_same_attempts_and_seed_give_the_same_figures_and_another_seed_moves_the_interval_only(self):
        rows = grid("m", self.SCORES)
        first, _, _ = report(rows)
        again, _, _ = report(rows)
        other, _, _ = report(rows, "--seed", "7")
        self.assertEqual(first, again)
        self.assertEqual(first[0]["quality_of_evaluable_deliveries"]["mean"], other[0]["quality_of_evaluable_deliveries"]["mean"])

    def test_tracks_and_configurations_are_reported_apart(self):
        rows = grid("m", self.SCORES) + grid("m", self.SCORES, track="security") + grid("n", self.SCORES)
        doc, rc, err = report(rows)
        self.assertEqual([(r["configuration"], r["track"]) for r in doc], [("m", "default"), ("m", "security"), ("n", "default")])
        only, _, _ = report(rows, "--track", "security", "--configuration", "m")
        self.assertEqual([(r["configuration"], r["track"]) for r in only], [("m", "security")])

    def test_the_variance_is_split_between_systems_and_runs(self):
        systems_only = {s: [v] * 5 for s, v in (("A", 800), ("B", 600), ("C", 700))}
        doc, _, _ = report(grid("m", systems_only))
        self.assertEqual(doc[0]["variance"]["run"], 0.0)
        self.assertGreater(doc[0]["variance"]["system"], 5000)
        runs_only = {s: [700, 740, 660, 720, 680] for s in "ABC"}
        doc, _, _ = report(grid("m", runs_only))
        self.assertEqual(doc[0]["variance"]["system"], 0.0)
        self.assertGreater(doc[0]["variance"]["run"], 0)

    def test_the_sizing_asks_for_more_systems_when_the_target_is_tighter(self):
        small, _, _ = report(grid("m", self.SCORES), "--target", "50")
        tight, _, _ = report(grid("m", self.SCORES), "--target", "10")
        self.assertGreater(tight[0]["sizing"]["systems"], small[0]["sizing"]["systems"])
        self.assertEqual(tight[0]["sizing"]["runs_per_system"], 5)

    def test_one_system_has_no_variance_components_and_no_sizing(self):
        doc, rc, err = report(grid("m", self.SCORES, systems="A"))
        self.assertEqual(rc, 0, err)
        self.assertIsNone(doc[0]["variance"])
        self.assertIsNone(doc[0]["sizing"])


class Comparison(unittest.TestCase):
    def compare(self, rows, a="m", b="n", track="default"):
        with tempfile.TemporaryDirectory() as t:
            path = os.path.join(t, "attempts.jsonl")
            with open(path, "w", encoding="utf-8") as f:
                f.write("\n".join(json.dumps(r) for r in rows))
            return run_tool("compare", path, a, b, "--track", track)

    def test_a_constant_gap_is_found_exactly_and_excludes_zero(self):
        scores = {"A": [800, 820, 780, 810, 790], "B": [600, 620, 580, 610, 590], "C": [700, 720, 680, 710, 690]}
        weaker = {s: [v - 50 for v in vs] for s, vs in scores.items()}
        doc, rc, err = self.compare(grid("m", scores) + grid("n", weaker))
        self.assertEqual(rc, 0, err)
        d = doc["difference_a_minus_b"]
        self.assertEqual((d["mean"], d["low"], d["high"], d["pairs"], d["unpaired_attempts"]), (50.0, 50.0, 50.0, 15, 0))
        self.assertTrue(d["interval_excludes_zero"])

    def test_attempts_are_paired_by_system_and_run_and_the_rest_is_reported_unpaired(self):
        scores = {"A": [800] * 5, "B": [600] * 5, "C": [700] * 5}
        a = grid("m", scores)
        b = [x for x in grid("n", scores) if not (x["system"] == "C" and x["run"] > 3)]
        doc, rc, err = self.compare(a + b)
        self.assertEqual(rc, 0, err)
        self.assertEqual((doc["difference_a_minus_b"]["pairs"], doc["difference_a_minus_b"]["unpaired_attempts"]), (13, 2))

    def test_equal_configurations_do_not_exclude_zero(self):
        scores = {"A": [800, 700, 760, 650, 790], "B": [600, 620, 480, 610, 590], "C": [700, 720, 680, 710, 690]}
        flipped = {s: list(reversed(v)) for s, v in scores.items()}
        doc, rc, err = self.compare(grid("m", scores) + grid("n", flipped))
        self.assertEqual(rc, 0, err)
        self.assertFalse(doc["difference_a_minus_b"]["interval_excludes_zero"])

    def test_an_unknown_configuration_is_an_error(self):
        _, rc, err = self.compare(grid("m", {"A": [1] * 5}, systems="A"), a="m", b="ghost")
        self.assertNotEqual(rc, 0)
        self.assertIn("no attempt of ghost", err)


class Collect(unittest.TestCase):
    def test_the_attempts_are_read_from_the_run_folders(self):
        with tempfile.TemporaryDirectory() as t:
            def put(config, system, run, card=None, note=None):
                folder = os.path.join(t, "attempts", config, system, "run-%d" % run)
                os.makedirs(folder)
                if card:
                    write_json(os.path.join(folder, "scorecard.json"), card)
                if note:
                    write_json(os.path.join(folder, "attempt.json"), note)
            put("m", "A", 1, card={"track": "default", "outcome": "completed", "total": 812})
            put("m", "A", 2, card={"track": "default", "outcome": "blocked", "total": 140}, note={"outcome": "blocked", "blocked_at": "work"})
            put("m", "B", 1, note={"outcome": "lab_failure", "track": "default"})
            out = os.path.join(t, "attempts.jsonl")
            r = run_py(TOOL, "collect", os.path.join(t, "attempts"), "--out", out)
            self.assertEqual(r.returncode, 0, r.stderr)
            with open(out, encoding="utf-8") as f:
                rows = [json.loads(line) for line in f]
            self.assertEqual([(x["system"], x["run"], x["outcome"], x["blocked_at"], x["score"]) for x in rows],
                             [("A", 1, "completed", None, 812), ("A", 2, "blocked", "work", 140), ("B", 1, "lab_failure", None, None)])
            doc, rc, err = run_tool("report", out)
            self.assertEqual(rc, 0, err)
            self.assertEqual((doc[0]["scheduled"], doc[0]["outcomes"]["blocked_during_the_work"], doc[0]["outcomes"]["lab_failure_to_rerun"]), (2, 1, 1))

    def test_a_folder_with_nothing_in_it_is_an_error(self):
        with tempfile.TemporaryDirectory() as t:
            os.makedirs(os.path.join(t, "m", "A", "run-1"))
            r = run_py(TOOL, "collect", t, "--out", os.path.join(t, "x.jsonl"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("neither scorecard.json nor attempt.json", r.stderr)


if __name__ == "__main__":
    unittest.main()
