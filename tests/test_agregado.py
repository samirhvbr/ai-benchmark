"""E7: aggregate-only publication of an active instance. Synthetic trees only: a public tree with one normal instance, one
instance that declares `publication: aggregate`, and a private archive of runs kept outside it."""

import contextlib
import io
import json
import os
import re
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
