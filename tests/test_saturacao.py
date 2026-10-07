"""E10: tools/saturacao.py — read-only saturation and dispersion. Demonstrated on the published LEB-100-A numbers first, and on a
synthetic private archive for the active-instance mode."""

import hashlib
import json
import os
import tempfile
import unittest

from helpers import ROOT, entry, judged, make_published_tree, read_json, run_py, synthetic_matrix, synthetic_verdict

TOOL = os.path.join(ROOT, "tools", "saturacao.py")
RESULTS = os.path.join(ROOT, "results")


def digest(path):
    with open(path, "rb") as f:
        return hashlib.sha256(f.read()).hexdigest()


class Published(unittest.TestCase):
    def report(self, *args):
        r = run_py(TOOL, "--json", *args)
        self.assertEqual(r.returncode, 0, r.stderr)
        return json.loads(r.stdout)

    def test_it_reproduces_the_numbers_measured_on_the_published_leb_100_a(self):
        report = self.report()
        at_max = {c: x["at_max"] for c, x in report["categories"].items()}
        self.assertEqual(at_max, {"SEC": 3, "ARCH": 0, "BUG": 3, "PERF": 28, "CLN": 4, "COMP": 24, "EXPL": 0})
        self.assertEqual({c: x["planted_flaws"] for c, x in report["categories"].items()},
                         {"SEC": 7, "ARCH": 2, "BUG": 2, "PERF": 1, "CLN": 1, "COMP": 0, "EXPL": 0})
        self.assertEqual((report["total"]["min"], report["total"]["max"], round(report["total"]["mean"])), (282, 809, 579))
        self.assertEqual({c: x["category_max"] for c, x in report["categories"].items()},
                         {"SEC": 250, "ARCH": 200, "BUG": 150, "PERF": 150, "CLN": 100, "COMP": 100, "EXPL": 50})

    def test_the_base_is_declared_and_it_is_one_run_per_agent(self):
        base = self.report()["base"]
        self.assertEqual((base["runs_in_base"], base["runs_total"], base["agents"]), (37, 92, 37))
        self.assertIn("representative run of each agent", base["scope"])
        everything = self.report("--all-runs")["base"]
        self.assertEqual((everything["runs_in_base"], everything["scope"]), (92, "all runs"))

    def test_the_text_report_states_the_base_before_any_number(self):
        r = run_py(TOOL)
        lines = r.stdout.splitlines()
        self.assertTrue(lines[1].startswith("base: representative run of each agent"))
        self.assertIn("37 of 92 runs, 37 agents", lines[1])

    def test_per_flaw_rates_match_the_published_flaws(self):
        by = {f["flaw"]: f for f in self.report()["flaws"]}
        self.assertEqual(len(by), 13)
        self.assertEqual((by["BUG-001"]["found_rate"], by["BUG-001"]["fixed_rate"]), (1.0, 1.0))
        self.assertEqual(by["ARCH-002"]["fixed_rate"], 0.0)
        self.assertEqual(round(100 * by["SEC-014"]["fixed_rate"]), 41)

    def test_it_writes_nothing(self):
        before = {n: digest(os.path.join(RESULTS, n)) for n in ("runs.csv", "flaws.csv", "results.json", "README.md")}
        with tempfile.TemporaryDirectory() as cwd:
            run_py(TOOL, cwd=cwd)
            run_py(TOOL, "--json", "--all-runs", cwd=cwd)
            self.assertEqual(os.listdir(cwd), [])
        self.assertEqual(before, {n: digest(os.path.join(RESULTS, n)) for n in before})

    def test_a_results_folder_with_several_instances_needs_the_choice(self):
        with tempfile.TemporaryDirectory() as t:
            for name, text in (("runs.csv", "instance,agent,run,counts_in_score,total,SEC,ARCH,BUG,PERF,CLN,COMP,EXPL\n"
                                            "A,x,1,true,10,1,1,1,1,1,1,1\nB,x,1,true,10,1,1,1,1,1,1,1\n"),
                               ("flaws.csv", "instance,agent,run,counts_in_score,flaw,category,severity,difficulty,found,fixed,"
                                             "points_earned,points_possible\n")):
                with open(os.path.join(t, name), "w", encoding="utf-8") as f:
                    f.write(text)
            r = run_py(TOOL, "--results", t)
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("--instance", r.stderr)
            self.assertEqual(run_py(TOOL, "--results", t, "--instance", "A").returncode, 0)


class Private(unittest.TestCase):
    def build(self, t):
        matrix = synthetic_matrix([entry("BUG-041", "Alta", "Difícil"), entry("SEC-042", "Alta", "Fácil")], instance="LEB-TEST-A", level=300)
        priv = os.path.join(t, "archive", "LEB-TEST-A")

        def verdict(c3):
            return synthetic_verdict([judged("BUG-041", C1="full", C2="full", C3=c3, C5="full"),
                                      judged("SEC-042", C1="full", C2="full", C3="full", C5="full")])
        make_published_tree(t, matrix, [("a", 1, verdict("full")), ("a", 2, verdict("none")), ("a", 3, verdict("full")),
                                        ("b", 1, verdict("none"))], name="LEB-TEST-A", run_base=priv)
        return os.path.join(t, "archive")

    def test_active_mode_reads_the_private_archive_with_the_representative_run_of_each_agent(self):
        with tempfile.TemporaryDirectory() as t:
            r = run_py(TOOL, "--private", self.build(t), "--instance", "LEB-TEST-A", "--json")
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertIn("PRIVATE REPORT", r.stderr)
            report = json.loads(r.stdout)
            self.assertTrue(report["private"])
            self.assertEqual(report["base"], {"scope": report["base"]["scope"], "runs_in_base": 2, "runs_total": 4, "agents": 2})
            by = {f["flaw"]: f for f in report["flaws"]}
            # agent a: runs full, none, full -> the representative run is a "full" one; agent b: one "none" run
            self.assertEqual((by["BUG-041"]["n"], by["BUG-041"]["fixed_rate"], by["SEC-042"]["fixed_rate"]), (2, 0.5, 1.0))
            self.assertEqual(report["categories"]["BUG"]["planted_flaws"], 1)

    def test_active_mode_needs_the_instance_and_an_existing_archive(self):
        with tempfile.TemporaryDirectory() as t:
            self.assertNotEqual(run_py(TOOL, "--private", t).returncode, 0)
            r = run_py(TOOL, "--private", t, "--instance", "LEB-TEST-A")
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("no evaluated run", r.stderr)


if __name__ == "__main__":
    unittest.main()
