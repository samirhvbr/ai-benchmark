"""E1: the per-instance runner contract (private/runner.json).

Synthetic instance, synthetic runner scripts. No Docker and no instance content: the runner commands are tiny Python
programs written to a temporary directory. The legacy docker/PHP path is covered by the LEB-100-A baseline run.
"""

import json
import os
import sys
import tempfile
import textwrap
import time
import unittest

from helpers import (ROOT, entry, make_instance, read_json, read_text, run_py, score_files, synthetic_matrix, synthetic_verdict,
                     write_json)

HARNESS = os.path.join(ROOT, "harness", "leb_harness.py")
LEB = os.path.join(ROOT, "leb")


def char_script(passed=22, failed=0):
    """A characterization runner: prints the counters it is told to, independent of the code under test."""
    return "import json\nprint(json.dumps({'passed': %d, 'failed': %d}))\n" % (passed, failed)


def char_by_code(fail_when_marker):
    """Fails 3 tests when LEB_ENTREGA_DIR/<marker> exists, so the baseline and the submission can differ."""
    return textwrap.dedent("""
        import json, os
        broken = os.path.exists(os.path.join(os.environ['LEB_ENTREGA_DIR'], %r))
        print('noise before'); print(json.dumps({'passed': 19 if broken else 22, 'failed': 3 if broken else 0})); print('noise after')
    """) % fail_when_marker


def verify_script(probes):
    return "import json\nprint(json.dumps({'probes': json.loads(%r)}))\n" % json.dumps(probes)


class RunnerCase(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.t = self._tmp.name
        self.matrix = synthetic_matrix([entry("SEC-001", "Alta", "Facil"), entry("BUG-001", "Media", "Dificil"),
                                        entry("ARCH-001", "Media", "Moderada", template="R"),
                                        entry("PERF-001", exists=False)])
        self.inst = make_instance(self.t, matrix=self.matrix)
        self.private = os.path.join(self.inst, "private")

    def tearDown(self):
        self._tmp.cleanup()

    def declare(self, char, verify, timeout_s=None):
        """Write the two runner scripts and the runner.json that points at them."""
        c, v = os.path.join(self.private, "char.py"), os.path.join(self.private, "verify.py")
        for path, body in ((c, char), (v, verify)):
            with open(path, "w", encoding="utf-8") as f:
                f.write(body)
        runner = {"caracterizacao": {"cmd": [sys.executable, c]}, "verificacao": {"cmd": [sys.executable, v]}}
        if timeout_s:
            runner["timeout_s"] = timeout_s
        write_json(os.path.join(self.private, "runner.json"), {"runner": runner})

    def harness(self, *extra):
        out = os.path.join(self.t, "mech.json")
        r = run_py(HARNESS, "--instance", self.inst, "--out", out, *extra)
        return (read_json(out) if os.path.exists(out) else None), r


class Contract(RunnerCase):
    def test_a_clean_run_reports_the_probes_and_exits_zero(self):
        self.declare(char_script(), verify_script([
            {"id": "SEC-001", "corrigida": True, "msg": "ok"},
            {"id": "BUG-001", "corrigida": False}]))
        mech, r = self.harness()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertFalse(mech["characterization"]["regression"])
        self.assertEqual({p["id"]: p["corrigida"] for p in mech["probes"]}, {"SEC-001": True, "BUG-001": False})
        self.assertEqual(mech["difficulty_corrected"], {"Facil": {"probed": 1, "corrected": 1},
                                                        "Dificil": {"probed": 1, "corrected": 0}})
        self.assertEqual(mech["mechanical_criteria"][0], {"id": "SEC-001", "C3_corrigiu": True, "C4_sem_regressao": True})

    def test_the_command_runs_with_the_documented_environment_and_cwd(self):
        probe = textwrap.dedent("""
            import json, os
            ok = (os.path.isdir(os.environ['LEB_ENTREGA_DIR']) and os.path.isdir(os.environ['LEB_INSTANCIA_DIR'])
                  and os.path.isdir(os.environ['LEB_RUN_DIR']) and os.path.basename(os.getcwd()) == 'private')
            print(json.dumps({'probes': [{'id': 'SEC-001', 'corrigida': ok}]}))
        """)
        self.declare(char_script(), probe)
        mech, r = self.harness()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertTrue(mech["probes"][0]["corrigida"])

    def test_stdout_noise_around_the_json_object_is_tolerated(self):
        self.declare(char_by_code("never"), verify_script([{"id": "SEC-001", "corrigida": True}]))
        mech, r = self.harness()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(mech["characterization"]["baseline"]["passed"], 22)

    def test_regression_is_the_submission_failing_more_than_the_baseline(self):
        sub = os.path.join(self.t, "sub")
        os.makedirs(sub)
        open(os.path.join(sub, "BREAK"), "w").close()
        self.declare(char_by_code("BREAK"), verify_script([{"id": "SEC-001", "corrigida": True}]))
        mech, r = self.harness("--submission", sub)
        self.assertEqual(r.returncode, 2, r.stderr)
        self.assertTrue(mech["characterization"]["regression"])
        self.assertFalse(mech["mechanical_criteria"][0]["C4_sem_regressao"])

    def test_the_exit_code_of_a_command_does_not_decide_regression(self):
        # A verifier that exits 1 after printing a valid report still counts; the report decides.
        self.declare(char_script(), verify_script([{"id": "SEC-001", "corrigida": True}]) + "raise SystemExit(1)\n")
        mech, r = self.harness()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertFalse(mech["characterization"]["regression"])

    def test_a_planted_flaw_without_a_verifier_is_listed_as_unverified(self):
        self.declare(char_script(), verify_script([{"id": "SEC-001", "corrigida": True}]))
        mech, _ = self.harness()
        unverified = {u["id"] for u in mech["unverified"]}
        self.assertEqual(unverified, {"BUG-001", "ARCH-001"})  # PERF-001 is a decoy: nothing to verify

    def test_unobserved_and_affected_are_carried_to_the_report(self):
        self.declare(char_script(), verify_script([
            {"id": "SEC-001", "corrigida": True, "unobserved": ["lock inside the JVM"], "affected": ["Other.method"]}]))
        mech, _ = self.harness()
        self.assertIn({"id": "SEC-001", "unobserved": ["lock inside the JVM"]}, mech["unverified"])
        self.assertEqual(mech["informative_affected"], {"SEC-001": ["Other.method"]})


class Inconclusive(RunnerCase):
    """Anything that is not a readable measurement exits 3 and says so: never approval, never regression."""

    def assert_inconclusive(self, mech, r, fragment):
        self.assertEqual(r.returncode, 3, r.stderr)
        self.assertTrue(mech["inconclusive"])
        self.assertIn(fragment, mech["inconclusive_reason"])
        self.assertNotIn("probes", mech)

    def test_unreadable_characterization_output(self):
        self.declare("print('boom')\n", verify_script([]))
        mech, r = self.harness()
        self.assert_inconclusive(mech, r, "linha de base")

    def test_a_crashing_verifier(self):
        self.declare(char_script(), "raise RuntimeError('verifier crashed')\n")
        mech, r = self.harness()
        self.assert_inconclusive(mech, r, "verificação")

    def test_a_probe_for_an_id_that_is_not_in_the_matrix(self):
        self.declare(char_script(), verify_script([{"id": "SEC-099", "corrigida": True}]))
        mech, r = self.harness()
        self.assert_inconclusive(mech, r, "SEC-099")

    def test_a_malformed_probe(self):
        for bad in ({"id": "sec-1", "corrigida": True}, {"id": "SEC-001"}, {"id": "SEC-001", "corrigida": "yes"},
                    {"id": "SEC-001", "corrigida": True, "proves": ["C4"]},
                    {"id": "ARCH-001", "corrigida": True, "proves": ["R3"]}):
            with self.subTest(probe=bad):
                self.declare(char_script(), verify_script([bad]))
                mech, r = self.harness()
                self.assertEqual(r.returncode, 3, r.stderr)

    def test_a_duplicated_probe(self):
        self.declare(char_script(), verify_script([{"id": "SEC-001", "corrigida": True}, {"id": "SEC-001", "corrigida": False}]))
        mech, r = self.harness()
        self.assert_inconclusive(mech, r, "repetida")

    def test_a_timeout_kills_the_whole_process_group(self):
        marker = os.path.join(self.t, "child-survived")
        slow = textwrap.dedent("""
            import subprocess, sys, time
            subprocess.Popen([sys.executable, '-c', "import time; time.sleep(3); open(%r, 'w').close()"])
            time.sleep(60)
        """) % marker
        self.declare(slow, verify_script([]), timeout_s=1)
        started = time.monotonic()
        mech, r = self.harness()
        self.assertLess(time.monotonic() - started, 20)
        self.assert_inconclusive(mech, r, "tempo limite")
        time.sleep(4)
        self.assertFalse(os.path.exists(marker), "a child of the timed-out runner survived")

    def test_an_invalid_runner_declaration_stops_before_running_anything(self):
        write_json(os.path.join(self.private, "runner.json"), {"runner": {"caracterizacao": {"cmd": []}}})
        mech, r = self.harness()
        self.assertNotEqual(r.returncode, 0)
        self.assertIsNone(mech)
        self.assertIn("runner.caracterizacao.cmd", r.stderr)

    def test_score_refuses_an_inconclusive_report(self):
        mech = {"inconclusive": True, "inconclusive_reason": "linha de base: x"}
        card, rc, err = score_files(self.matrix, mech, synthetic_verdict([]))
        self.assertNotEqual(rc, 0)
        self.assertIsNone(card)
        self.assertIn("INCONCLUSIVO", err)


class Legacy(unittest.TestCase):
    def test_an_instance_without_runner_json_keeps_the_legacy_path(self):
        # make_instance has no characterization/docker-compose.yml: the legacy path must be the one that complains.
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t)
            r = run_py(HARNESS, "--instance", inst, "--out", os.path.join(t, "o.json"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("docker-compose.yml", r.stderr)

    def test_the_cli_reports_exit_3_as_inconclusive(self):
        src = read_text(LEB)
        self.assertIn("(0, 2, 3)", src)
        self.assertIn("INCONCLUSIVO", src)


if __name__ == "__main__":
    unittest.main()
