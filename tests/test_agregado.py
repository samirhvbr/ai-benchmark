"""E7: aggregate-only publication of an active instance. Synthetic trees only: a public tree with one normal instance, one
instance that declares `publication: aggregate`, and a private archive of runs kept outside it."""

import contextlib
import io
import json
import os
import re
import shutil
import sys
import tempfile
import unittest

from helpers import (ROOT, entry, judged, load_exporter, make_published_tree, read_json, read_text, score_files, synthetic_mech, synthetic_matrix, synthetic_verdict,
                     validate, write_json)

SECRET_FIX = "close the race by taking the row lock before reading the balance"


def active_matrix(**header):
    return synthetic_matrix([
        entry("BUG-041", "Alta", "Difícil", evidence="the second writer overwrites the first one silently",
              expected_fix=SECRET_FIX, location="code/src/main/java/app/Ledger.java:40-77"),
        entry("SEC-042", "Média", "Moderada", evidence="the token is compared with equals in a loop of requests",
              expected_fix="compare in constant time", location="code/src/main/java/app/Auth.java:12-30"),
        entry("PERF-043", exists=False),
    ], instance="LEB-TEST-A", level=300, publication="aggregate", task_version="1.1.0", **header)


def open_matrix():
    return synthetic_matrix([entry("SEC-901", "Alta", "Fácil")], instance="LEB-TEST-B", level=100)


def verdict_of(c3):
    return synthetic_verdict([judged("BUG-041", C1="full", C2="full", C3=c3, C5="full"),
                              judged("SEC-042", C1="full", C2="full", C3="full", C5="full")])


def open_verdict():
    return synthetic_verdict([judged("SEC-901", C1="full", C2="full", C3="full", C5="full")])


class ExportCase(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.t = self._tmp.name
        self.priv = os.path.join(self.t, "private-archive")
        self._old = {k: os.environ.pop(k, None) for k in ("LEB_PRIVATE_RESULTS", "LEB_RUNS_DIR", "LEB_INSTANCES_PATH")}
        os.environ["LEB_PRIVATE_RESULTS"] = self.priv

    def tearDown(self):
        for k, v in self._old.items():
            os.environ.pop(k, None) if v is None else os.environ.__setitem__(k, v)
        self._tmp.cleanup()

    def build(self, agents=(("agent-one", ("full", "none", "full")), ("agent-two", ("none",)))):
        """The public tree (a normal instance B and the declaration of the active instance A) and A's private runs."""
        make_published_tree(self.t, open_matrix(), [("open-agent", 1, open_verdict())], name="LEB-TEST-B")
        from helpers import make_instance
        make_instance(self.t, "LEB-TEST-A", matrix=active_matrix())
        for agent, results in agents:
            make_published_tree(self.t, active_matrix(), [(agent, i + 1, verdict_of(c3)) for i, c3 in enumerate(results)],
                                name="LEB-TEST-A", run_base=os.path.join(self.priv, "LEB-TEST-A"))
        return self.t

    def export(self, *args):
        mod = load_exporter(self.t)
        err = io.StringIO()
        old_argv = sys.argv
        sys.argv = ["export-results.py", *args]
        code = 0
        try:
            with contextlib.redirect_stderr(err):
                mod.main()
        except SystemExit as e:
            code = e.code if isinstance(e.code, int) else 1
            if isinstance(e.code, str):
                err.write(e.code)
        finally:
            sys.argv = old_argv
        return code, err.getvalue(), mod

    def results(self, *parts):
        return os.path.join(self.t, "results", *parts)

    def tree_text(self):
        out = {}
        for root, _, files in os.walk(self.results()):
            for name in files:
                with open(os.path.join(root, name), encoding="utf-8") as f:
                    out[os.path.relpath(os.path.join(root, name), self.results())] = f.read()
        return out


class NothingByDefault(ExportCase):
    def test_a_normal_run_never_reads_the_private_archive_or_writes_anything_for_the_active_instance(self):
        self.build()
        code, err, _ = self.export()
        self.assertEqual(code, 0, err)
        self.assertFalse(os.path.exists(self.results("2026", "LEB-TEST-A")))
        data = read_json(self.results("results.json"))
        self.assertNotIn("aggregate_instances", data)
        self.assertEqual([i["id"] for i in data["instances"]], ["LEB-TEST-B"])
        generated = {k: v for k, v in self.tree_text().items() if k in ("README.md", "results.json", "runs.csv", "flaws.csv")}
        self.assertEqual(len(generated), 4)
        self.assertFalse(any("LEB-TEST-A" in text for text in generated.values()))

    def test_retiring_the_instance_publishes_nothing_there_is_no_command_for_it(self):
        # The aggregate is written only by --publish-aggregate; the exporter has no release, retire or detail flag at all.
        src = read_text(ROOT, "tools", "export-results.py")
        self.assertNotRegex(src, r"--(retire|release|publish-detail|publish-flaws)")
        self.assertEqual(re.findall(r'add_argument\("(--[a-z-]+)"', src), ["--check", "--publish-aggregate", "--edition"])


class Publishing(ExportCase):
    def publish(self):
        self.build()
        code, err, _ = self.export("--publish-aggregate", "LEB-TEST-A")
        self.assertEqual(code, 0, err)
        return read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))

    def test_the_aggregate_has_the_lower_median_run_of_each_agent_and_validates(self):
        agg = self.publish()
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])
        self.assertEqual((agg["instance"], agg["edition"], agg["level"], agg["task_version"], agg["mode"]),
                         ("LEB-TEST-A", "2026", 300, "1.1.0", "A"))
        by = {e["agent"]: e for e in agg["agents"]}
        self.assertEqual(by["agent-one"]["runs_count"], 3)
        self.assertEqual(by["agent-two"]["runs_count"], 1)
        # a C3 of "none" on the BUG flaw is the lower total, the other two runs tie: the lower median is the tied total
        total = lambda c3: score_files(active_matrix(), synthetic_mech(), verdict_of(c3))[0]["total"]
        self.assertEqual(by["agent-one"]["score"], total("full"))  # runs: full, none, full -> the lower median is a "full" run
        self.assertEqual(by["agent-two"]["score"], total("none"))
        self.assertGreater(total("full"), total("none"))
        self.assertEqual(list(by["agent-one"]["categories"]), ["SEC", "ARCH", "BUG", "PERF", "CLN", "COMP", "EXPL"])

    def test_nothing_about_a_flaw_reaches_results(self):
        self.publish()
        self.assertEqual(sorted(os.listdir(self.results("2026", "LEB-TEST-A"))), ["aggregate.json"])
        texts = self.tree_text()
        readme = texts["README.md"]
        section = readme[readme.index("— aggregate only"):]
        mine = {"aggregate.json": texts[os.path.join("2026", "LEB-TEST-A", "aggregate.json")], "README section": section,
                "results.json aggregate": json.dumps(json.loads(texts["results.json"])["aggregate_instances"])}
        for where, text in mine.items():
            for needle in ("BUG-041", "SEC-042", "PERF-043", SECRET_FIX, "Ledger.java", "Auth.java", "veredito", "entrega", "scorecard"):
                self.assertTrue(needle not in text, "%r appears in %s" % (needle, where))
        self.assertNotIn("LEB-TEST-A", texts["flaws.csv"])
        self.assertNotIn("LEB-TEST-A", texts["runs.csv"])

    def test_the_leaderboard_and_results_json_carry_the_aggregate_additively(self):
        agg = self.publish()
        text = self.tree_text()
        self.assertIn("LEB-TEST-A v1.0 (mode A, 30 turns) — aggregate only", text["README.md"])
        data = json.loads(text["results.json"])
        self.assertEqual(data["aggregate_instances"], [agg])
        self.assertEqual([i["id"] for i in data["instances"]], ["LEB-TEST-B"])  # the old shape is untouched

    def test_publishing_again_is_idempotent_and_check_passes(self):
        self.publish()
        self.assertEqual(self.export("--check")[0], 0)
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A", "--check")[0], 0)

    def test_a_publish_check_notices_that_the_private_runs_moved_on(self):
        self.publish()
        make_published_tree(self.t, active_matrix(), [("agent-three", 1, verdict_of("full"))], name="LEB-TEST-A",
                            run_base=os.path.join(self.priv, "LEB-TEST-A"))
        code, err, _ = self.export("--publish-aggregate", "LEB-TEST-A", "--check")
        self.assertEqual(code, 1)
        self.assertIn("aggregate.json", err)

    def test_an_instance_that_is_not_aggregate_cannot_use_the_command(self):
        self.build()
        code, err, _ = self.export("--publish-aggregate", "LEB-TEST-B")
        self.assertNotEqual(code, 0)
        self.assertIn("does not declare publication: aggregate", err)

    def test_an_agent_id_that_names_a_flaw_is_refused_and_nothing_is_written(self):
        self.build(agents=(("model-BUG-041", ("full",)),))
        code, err, _ = self.export("--publish-aggregate", "LEB-TEST-A")
        self.assertNotEqual(code, 0)
        self.assertIn("BUG-041", err)
        self.assertFalse(os.path.exists(self.results("2026", "LEB-TEST-A")))


class WrittenReading(ExportCase):
    """A short written reading of an agent travels inside the aggregate (comments.json of the private archive). It is free text, so it is
    the one place a flaw could leak: the same guard that reads the agent id reads it."""

    def publish_with(self, comments):
        if not getattr(self, "_built", False):
            self.build()
            self._built = True
        write_json(os.path.join(self.priv, "LEB-TEST-A", "comments.json"), comments)
        return self.export("--publish-aggregate", "LEB-TEST-A")

    def test_each_agent_lists_its_runs_and_the_score_is_one_of_their_totals(self):
        self.build()
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A")[0], 0)
        agg = read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))
        by = {e["agent"]: e for e in agg["agents"]}
        total = lambda c3: score_files(active_matrix(), synthetic_mech(), verdict_of(c3))[0]["total"]
        self.assertEqual([(r["run"], r["total"]) for r in by["agent-one"]["runs"]], [(1, total("full")), (2, total("none")), (3, total("full"))])
        self.assertEqual([r["run"] for r in by["agent-two"]["runs"]], [1])
        for e in agg["agents"]:
            self.assertIn(e["score"], [r["total"] for r in e["runs"]])
            self.assertEqual(len(e["runs"]), e["runs_count"])
        # the README shows the totals of an agent with more than one run, as the table of an open instance does
        readme = read_text(self.t, "results", "README.md")
        self.assertIn("3/3 (%d · %d · %d)" % (total("full"), total("none"), total("full")), readme)

    def test_each_agent_says_who_it_is_so_the_sites_need_no_other_instance_to_name_it(self):
        self.build()
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A")[0], 0)
        agg = read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))
        for e in agg["agents"]:
            self.assertEqual(e["model"], {"name": e["agent"], "id": e["agent"], "provider": "Test", "reasoning_effort": "high"})
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])

    def test_the_session_time_leaves_out_the_operators_wait_between_the_stages_and_nothing_else(self):
        self.build()
        for agent, wait in (("agent-one", 12.5), ("agent-two", None)):
            path = os.path.join(self.priv, "LEB-TEST-A", agent, "run-1", "run.json")
            meta = read_json(path)
            meta["session"] = {"started": "2026-10-08T10:00:00-03:00", "ended": "2026-10-08T11:00:00-03:00"}
            if wait is not None:
                meta["operator_wait_minutes"] = wait
            write_json(path, meta)
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A")[0], 0)
        by = {e["agent"]: e for e in read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))["agents"]}
        self.assertEqual(by["agent-one"]["runs"][0]["wall_minutes"], 47.5)   # 60 minutes of session, 12.5 of the operator's wait
        self.assertEqual(by["agent-two"]["runs"][0]["wall_minutes"], 60.0)   # no wait recorded: nothing is taken out
        self.assertIsNone(by["agent-one"]["runs"][1]["wall_minutes"])        # a run with no session record keeps none

    def test_the_tokens_of_each_run_travel_with_its_cost_and_a_partial_record_publishes_none(self):
        self.build()
        full = {"input": 1200, "output": 34000, "cache": 3400000, "total": 3435200}
        for agent, tokens in (("agent-one", full), ("agent-two", {"input": 5, "output": 6})):
            path = os.path.join(self.priv, "LEB-TEST-A", agent, "run-1", "run.json")
            meta = read_json(path)
            meta["cost_time"] = {**(meta.get("cost_time") or {}), "tokens": tokens}
            write_json(path, meta)
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A")[0], 0)
        agg = read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))
        by = {e["agent"]: e for e in agg["agents"]}
        self.assertEqual(by["agent-one"]["runs"][0]["tokens"], full)
        self.assertEqual(by["agent-one"]["tokens"], full)                    # the agent shows the tokens of the run its score is
        self.assertIsNone(by["agent-one"]["runs"][1]["tokens"])             # a run whose record has none keeps none
        self.assertIsNone(by["agent-two"]["tokens"])                        # input and output without cache and total: no partial figure
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])

    def test_a_reading_travels_in_the_aggregate_in_both_languages_and_only_for_its_agent(self):
        code, err, _ = self.publish_with({"agent-one": {"en": "Strong on security, weak on architecture.", "pt_BR": "Forte em segurança, fraco em arquitetura."}})
        self.assertEqual(code, 0, err)
        agg = read_json(self.results("2026", "LEB-TEST-A", "aggregate.json"))
        by = {e["agent"]: e for e in agg["agents"]}
        self.assertEqual(by["agent-one"]["comment"]["pt_BR"], "Forte em segurança, fraco em arquitetura.")
        self.assertNotIn("comment", by["agent-two"])
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A", "--check")[0], 0)

    def test_a_reading_that_names_a_flaw_or_quotes_the_matrix_is_refused_and_nothing_is_written(self):
        for text in ("It fixed BUG-041 before anything else.", "The fix was to " + SECRET_FIX + ".", "Left SEC-042.b alone."):
            with self.subTest(text=text):
                code, err, _ = self.publish_with({"agent-one": {"en": text, "pt_BR": "Texto."}})
                self.assertNotEqual(code, 0)
                self.assertIn("refused", err)
                self.assertFalse(os.path.exists(self.results("2026", "LEB-TEST-A")))

    def test_a_reading_for_an_agent_that_does_not_exist_is_refused(self):
        code, err, _ = self.publish_with({"agent-nine": {"en": "Text.", "pt_BR": "Texto."}})
        self.assertNotEqual(code, 0)
        self.assertIn("names no agent: agent-nine", err)

    def test_a_reading_in_one_language_or_too_long_does_not_match_the_schema(self):
        for comment in ({"en": "Only English."}, {"en": "x" * 1201, "pt_BR": "Texto."}, {"en": "Text.", "pt_BR": ""}):
            with self.subTest(comment=list(comment)):
                code, err, _ = self.publish_with({"agent-one": comment})
                self.assertNotEqual(code, 0)
                self.assertIn("does not match", err)
                self.assertFalse(os.path.exists(self.results("2026", "LEB-TEST-A")))


class PublicTree(ExportCase):
    def test_a_per_flaw_file_in_the_folder_of_an_aggregate_instance_fails_every_run_and_check(self):
        self.build()
        self.assertEqual(self.export("--publish-aggregate", "LEB-TEST-A")[0], 0)
        with open(self.results("2026", "LEB-TEST-A", "veredito.json"), "w", encoding="utf-8") as f:
            f.write("{}")
        for args in (("--check",), ()):
            code, err, _ = self.export(*args)
            self.assertNotEqual(code, 0)
            self.assertIn("veredito.json", err)

    def test_runs_of_a_declared_aggregate_instance_cannot_be_filed_in_the_public_tree_before_it_is_published(self):
        self.build()
        make_published_tree(self.t, active_matrix(), [("agent-one", 1, verdict_of("full"))], name="LEB-TEST-A", edition="2026",
                            run_base=self.results("2026", "LEB-TEST-A"))
        code, err, _ = self.export("--check")
        self.assertNotEqual(code, 0)
        self.assertIn("agent-one", err)

    def test_the_schema_has_no_field_for_flaws(self):
        schema = read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))
        agg = {"publication": "aggregate", "edition": "2026", "instance": "X", "version": "1.0", "level": 300, "leb_spec": "1.4.0",
               "task_version": "1.1.0", "matrix_sha256": "a" * 64, "mode": "A", "turn_budget": 30,
               "agents": [{"agent": "a", "score": 500, "grade": "Silver", "runs_count": 1,
                           "categories": {c: 1 for c in ("SEC", "ARCH", "BUG", "PERF", "CLN", "COMP", "EXPL")}}]}
        self.assertEqual(validate(agg, schema), [])
        for extra in ({"flaws": []}, {"per_flaw": {}}, {"judge_rationale": "x"}):
            self.assertNotEqual(validate({**agg, **extra}, schema), [], extra)
        self.assertNotEqual(validate({**agg, "agents": [{**agg["agents"][0], "flaws": []}]}, schema), [])


class SeriesPublishing(unittest.TestCase):
    """An instance of the new series (its scorecards carry a `track`): the aggregate is the estimate of the five runs with its interval, the
    completion and the outcomes (docs/new-series-design.md section 9), and a lab failure holds its run number without being scored."""

    INSTANCE = "LEB-TEST-D-300-A"
    SCORES = (524, 533, 580, 561, 536)
    MAXIMA = {"ARCH": 283, "BUG": 213, "PERF": 213, "CLN": 141, "COMP": 100, "EXPL": 50}

    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.t = self._tmp.name
        self.priv = os.path.join(self.t, "private-archive")
        self._old = {k: os.environ.pop(k, None) for k in ("LEB_PRIVATE_RESULTS", "LEB_RUNS_DIR", "LEB_INSTANCES_PATH")}
        os.environ["LEB_PRIVATE_RESULTS"] = self.priv
        from helpers import make_instance
        make_published_tree(self.t, open_matrix(), [("open-agent", 1, open_verdict())], name="LEB-TEST-B")
        make_instance(self.t, self.INSTANCE, matrix=synthetic_matrix([entry("BUG-041", "Alta", "Difícil")], instance=self.INSTANCE, level=300,
                                                                      publication="aggregate", task_version="D-1.0.0", leb_spec="1.5.1", version="1.1"))
        self.archive = os.path.join(self.priv, self.INSTANCE, "claude-test-high")

    def tearDown(self):
        for k, v in self._old.items():
            os.environ.pop(k, None) if v is None else os.environ.__setitem__(k, v)
        self._tmp.cleanup()

    def run_folder(self, number, total, report=None, package="b" * 64, outcome="completed", cost=1.0):
        d = os.path.join(self.archive, "run-%d" % number)
        os.makedirs(os.path.join(d, "entrega"))
        spread = {"ARCH": 0.3, "BUG": 0.4, "PERF": 0.2, "CLN": 0.0, "COMP": 0.0, "EXPL": 0.1}
        cats = {c: {"score": int(round(total * spread[c])) if c != "COMP" else 100, "max": m} for c, m in self.MAXIMA.items()}
        write_json(os.path.join(d, "scorecard.json"), {"track": "default", "outcome": outcome, "total": total, "matrix_sha256": "a" * 64,
                                                       "grade": "Bronze", "categories": cats})
        write_json(os.path.join(d, "run.json"), {
            "run": number, "mode": "A", "turn_budget": None, "budget_minutes": 90, "package_sha256": package, "evaluated_on": "2026-10-15",
            "outcome": outcome, "model": {"name": "Claude Test", "id": "claude-test", "provider": "Test", "reasoning_effort": "high"},
            "session": {"started": "2026-10-15T10:00:00+00:00", "ended": "2026-10-15T10:30:00+00:00"},
            "cost_time": {"usd_estimate": cost, "tokens": {"input": 100, "output": 1000, "cache": 9000, "total": 10100}}})
        write_json(os.path.join(d, "mecanico.json"), {})
        write_json(os.path.join(d, "veredito.json"), {})
        with open(os.path.join(d, "entrega", "REPORT.md"), "w", encoding="utf-8") as f:
            f.write(report or "report of run %d\n" % number)
        return d

    def build(self, numbers=(1, 3, 4, 5, 6), failed=(2,)):
        for n, total in zip(numbers, self.SCORES):
            self.run_folder(n, total)
        for n in failed:
            os.makedirs(os.path.join(self.archive, "lab-failure-%d" % n))

    def export(self, *args):
        mod = load_exporter(self.t)
        err = io.StringIO()
        old_argv = sys.argv
        sys.argv = ["export-results.py", *args]
        code = 0
        try:
            with contextlib.redirect_stderr(err):
                mod.main()
        except SystemExit as e:
            code = e.code if isinstance(e.code, int) else 1
            if isinstance(e.code, str):
                err.write(e.code)
        finally:
            sys.argv = old_argv
        return code, err.getvalue()

    def publish(self):
        self.build()
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertEqual(code, 0, err)
        return read_json(os.path.join(self.t, "results", "2026", self.INSTANCE, "aggregate.json"))

    def test_the_aggregate_is_the_estimate_of_the_runs_and_validates(self):
        agg = self.publish()
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])
        self.assertEqual((agg["track"], agg["task_version"], agg["budget_minutes"], agg["turn_budget"]), ("default", "1.0.0", 90, None))
        e = agg["agents"][0]
        self.assertEqual((e["score"], e["grade"], e["runs_count"]), (round(sum(self.SCORES) / 5), "Bronze", 5))
        st = e["statistics"]
        self.assertEqual((st["quality"]["mean"], st["quality"]["systems"], st["scheduled"]), (round(sum(self.SCORES) / 5, 1), 1, 5))
        self.assertLessEqual(st["quality"]["low"], st["quality"]["mean"])
        self.assertGreaterEqual(st["quality"]["high"], st["quality"]["mean"])
        self.assertEqual(st["quality"], st["expected_per_scheduled_attempt"])  # every attempt completed
        self.assertEqual((st["completion"]["rate"], st["completion"]["systems_all_completed"]), (1.0, 1))
        self.assertEqual((st["median"], st["min"], st["max"], st["lab_failures_rerun"]), (536, 524, 580, 1))
        self.assertEqual([r["run"] for r in e["runs"]], [1, 3, 4, 5, 6])
        self.assertEqual(e["category_max"], self.MAXIMA)
        self.assertEqual(list(e["categories"]), list(self.MAXIMA))  # no SEC on the Default track
        self.assertEqual(e["wall_minutes"], 30.0)
        self.assertEqual(e["tokens"]["total"], 10100)

    def test_the_readme_and_results_json_carry_the_series_additively(self):
        agg = self.publish()
        readme = read_text(self.t, "results", "README.md")
        self.assertIn("%s v1.1 (Default track, mode A, 90 minutes) — aggregate only" % self.INSTANCE, readme)
        self.assertIn("| 1 | `claude-test-high` | **%d** |" % agg["agents"][0]["score"], readme)
        self.assertEqual(read_json(os.path.join(self.t, "results", "results.json"))["aggregate_instances"], [agg])
        self.assertEqual(self.export("--check")[0], 0)
        self.assertEqual(self.export("--publish-aggregate", self.INSTANCE, "--check")[0], 0)

    def test_the_figures_are_the_ones_estatisticas_gives_for_the_same_attempts(self):
        agg = self.publish()
        mod = load_exporter(self.t)
        attempts = [{"configuration": "claude-test-high", "track": "default", "system": "A", "run": n, "outcome": "completed", "blocked_at": None, "score": s}
                    for n, s in zip((1, 3, 4, 5, 6), self.SCORES)]
        want = mod.estatisticas.report_for(attempts, 2000, 1, 25.0, 5)["quality_of_evaluable_deliveries"]
        self.assertEqual(agg["agents"][0]["statistics"]["quality"], want)

    def test_a_gap_in_the_run_numbers_needs_a_lab_failure_folder(self):
        self.build(failed=())
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertNotEqual(code, 0)
        self.assertIn("lab-failure", err)
        self.assertFalse(os.path.exists(os.path.join(self.t, "results", "2026", self.INSTANCE)))

    def test_a_sixth_run_is_refused(self):
        self.build(failed=())
        self.run_folder(2, 500)
        self.run_folder(7, 500)
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertNotEqual(code, 0)
        self.assertIn("5 scheduled runs", err)

    def test_the_same_report_filed_twice_is_one_delivery(self):
        self.build()
        shutil.copyfile(os.path.join(self.archive, "run-1", "entrega", "REPORT.md"), os.path.join(self.archive, "run-3", "entrega", "REPORT.md"))
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertNotEqual(code, 0)
        self.assertIn("same REPORT.md", err)

    def test_runs_of_one_case_share_the_package(self):
        self.build()
        write_json(os.path.join(self.archive, "run-4", "run.json"),
                   {**read_json(os.path.join(self.archive, "run-4", "run.json")), "package_sha256": "c" * 64})
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertNotEqual(code, 0)
        self.assertIn("package_sha256", err)

    def test_two_cases_may_differ_in_package_and_the_instance_then_names_none(self):
        self.build()
        first = self.archive
        self.archive = os.path.join(self.priv, self.INSTANCE, "claude-other-xhigh")
        for n, total in ((1, 700), (2, 720), (3, 740), (4, 760), (5, 780)):
            self.run_folder(n, total, package="c" * 64, report="other report of run %d\n" % n)
        self.archive = first
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertEqual(code, 0, err)
        agg = read_json(os.path.join(self.t, "results", "2026", self.INSTANCE, "aggregate.json"))
        self.assertEqual(validate(agg, read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))), [])
        self.assertNotIn("package_sha256", agg)
        self.assertEqual({e["agent"]: e["package_sha256"] for e in agg["agents"]}, {"claude-test-high": "b" * 64, "claude-other-xhigh": "c" * 64})
        self.assertEqual([e["agent"] for e in agg["agents"]], ["claude-other-xhigh", "claude-test-high"])  # 740 above 547
        self.assertIn("the package each configuration received", read_text(self.t, "results", "README.md"))
        self.assertEqual(self.export("--check")[0], 0)

    def test_a_blocked_attempt_enters_the_expected_score_and_not_the_quality(self):
        self.build()
        d = os.path.join(self.archive, "run-6")
        meta = read_json(os.path.join(d, "run.json"))
        card = read_json(os.path.join(d, "scorecard.json"))
        write_json(os.path.join(d, "run.json"), {**meta, "outcome": "blocked", "blocked_at": "work"})
        write_json(os.path.join(d, "scorecard.json"), {**card, "outcome": "blocked"})
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertEqual(code, 0, err)
        st = read_json(os.path.join(self.t, "results", "2026", self.INSTANCE, "aggregate.json"))["agents"][0]["statistics"]
        self.assertEqual((st["outcomes"]["completed"], st["outcomes"]["blocked"], st["outcomes"]["blocked_during_the_work"]), (4, 1, 1))
        self.assertEqual(st["quality"]["n"], 4)
        self.assertEqual(st["expected_per_scheduled_attempt"]["n"], 5)
        self.assertEqual(st["completion"]["rate"], 0.8)

    def test_a_flaw_id_in_the_notes_is_refused(self):
        self.build()
        write_json(os.path.join(self.priv, self.INSTANCE, "notes.json"), {"en": "the race of BUG-041 was not fixed", "pt_BR": "x"})
        code, err = self.export("--publish-aggregate", self.INSTANCE)
        self.assertNotEqual(code, 0)
        self.assertIn("BUG-041", err)


class PublishedOutputs(unittest.TestCase):
    """The repository's own results.json. Until the first aggregate was published this test said the key was absent; it now says what the key may hold."""

    def test_an_aggregate_instance_is_published_as_its_aggregate_and_nothing_else(self):
        data = read_json(os.path.join(ROOT, "results", "results.json"))
        schema = read_json(os.path.join(ROOT, "scoring", "publicacao-agregada.schema.json"))
        per_flaw = {item["id"] for item in data["instances"]}
        for agg in data.get("aggregate_instances", []):
            self.assertEqual(validate(agg, schema), [], agg["instance"])
            self.assertEqual(agg["publication"], "aggregate")
            self.assertNotIn(agg["instance"], per_flaw, "an aggregate instance has no per-flaw entry")
            text = json.dumps(agg, ensure_ascii=False)
            self.assertEqual(re.findall(r"\b(?:SEC|ARCH|PERF|BUG|CLN)-\d{3}(?:\.[a-z])?\b", text), [], agg["instance"])

    def test_the_folder_of_each_aggregate_holds_only_its_aggregate(self):
        results = os.path.join(ROOT, "results")
        for agg in read_json(os.path.join(results, "results.json")).get("aggregate_instances", []):
            folder = os.path.join(results, agg["edition"], agg["instance"])
            self.assertEqual(os.listdir(folder), ["aggregate.json"])


if __name__ == "__main__":
    unittest.main()
