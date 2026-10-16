"""tools/auditar-codex.py: who answered in one attempt's Codex CLI session, its permissions, what ended it, and the outcome.

Synthetic rollout logs only, built with the shapes Codex 0.159.3 wrote in a real session of the LEB-300-A pilot: a `session_meta`, a `turn_context`
per turn (model, effort, approval policy, sandbox), `task_started` / `task_complete` events, `item_completed` user messages and `token_count`
events. Error, abort and reroute events have not been seen in a real log yet; the tests pin the names the tool recognizes them by.
"""

import json
import os
import tempfile
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "auditar-codex.py")
REQ = "gpt-6.1-sol"
MAIN = "00000000-0000-0000-0000-00000000000a"
MESSAGE = "Read TASK.md and carry it out."


def at(minute, second=0):
    return "2026-10-16T10:%02d:%02d.000Z" % (minute, second)


def meta(sid=MAIN, source="exec", ts=None):
    return {"timestamp": ts or at(0), "type": "session_meta", "payload": {"id": sid, "session_id": sid, "source": source, "cli_version": "0.159.3", "originator": "codex_exec"}}


def turn(minute, model=REQ, effort="xhigh", approval="never", sandbox="danger-full-access", done=True, message=None, tokens=1000, error=None):
    ev = [{"timestamp": at(minute), "type": "event_msg", "payload": {"type": "task_started", "turn_id": "t%d" % minute}}]
    if message is not None:
        ev.append({"timestamp": at(minute), "type": "event_msg", "payload": {"type": "item_completed", "item": {"type": "UserMessage", "content": [{"type": "Text", "text": message}]}}})
    ev.append({"timestamp": at(minute, 1), "type": "turn_context", "payload": {"model": model, "effort": effort, "approval_policy": approval, "sandbox_policy": {"type": sandbox}}})
    ev.append({"timestamp": at(minute, 30), "type": "event_msg", "payload": {"type": "token_count", "info": {"total_token_usage": {
        "input_tokens": tokens, "cached_input_tokens": tokens // 2, "output_tokens": 100, "reasoning_output_tokens": 40, "total_tokens": tokens + 100}}}})
    if done:
        ev.append({"timestamp": at(minute, 40), "type": "event_msg", "payload": {"type": "task_complete", "turn_id": "t%d" % minute, "last_agent_message": None if error else "done",
                                                                                  **({"error": {"message": error}} if error else {})}})
    return ev


def event(minute, kind, **payload):
    return [{"timestamp": at(minute, 45), "type": "event_msg", "payload": dict({"type": kind}, **payload)}]


class Case(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = self._tmp.name
        self.msg = os.path.join(self.dir, "msg.txt")
        with open(self.msg, "w", encoding="utf-8") as f:
            f.write(MESSAGE)

    def tearDown(self):
        self._tmp.cleanup()

    def log(self, name, events):
        p = os.path.join(self.dir, "sessions", "2026", "10", "16", "rollout-%s.jsonl" % name)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, "w", encoding="utf-8") as f:
            for e in events:
                f.write(json.dumps(e) + "\n")
        return p

    def audit(self, *args, requested=REQ):
        r = run_py(TOOL, os.path.join(self.dir, "sessions"), "--requested", requested, "--json", *args)
        return r, (json.loads(r.stdout) if r.stdout.strip() else None)


class Outcomes(Case):
    def completed(self):
        return [meta()] + turn(1, message=MESSAGE)

    def test_a_session_that_ended_by_itself_on_the_requested_model_is_completed(self):
        self.log("main", self.completed())
        r, rep = self.audit("--effort", "xhigh", "--permissions", "bypass", "--message", self.msg)
        self.assertEqual((r.returncode, rep["outcome"]), (0, "completed"), r.stderr)
        self.assertEqual(rep["threads"][0]["models"], [REQ])
        self.assertEqual(rep["tokens"]["total_tokens"], 1100)
        self.assertEqual(rep["operator"], {"user_messages": 1, "declared": 1, "interruptions": 0})

    def test_the_text_report_names_the_outcome(self):
        self.log("main", self.completed())
        r = run_py(TOOL, os.path.join(self.dir, "sessions"), "--requested", REQ)
        self.assertEqual(r.returncode, 0)
        self.assertIn("OUTCOME    COMPLETED", r.stdout)

    def test_a_session_that_started_on_another_model_is_an_operator_error_and_a_switch(self):
        self.log("main", [meta()] + turn(1, model="gpt-5.6-terra", message=MESSAGE))
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "operator error"))
        self.assertIn("switched", [f["outcome"] for f in rep["findings"]])

    def test_an_effort_other_than_the_declared_one_is_an_operator_error(self):
        self.log("main", [meta()] + turn(1, effort="high", message=MESSAGE))
        r, rep = self.audit("--effort", "xhigh")
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("effort high", rep["detail"])

    def test_permissions_other_than_the_declared_ones_are_an_operator_error(self):
        self.log("main", [meta()] + turn(1, approval="on-request", sandbox="workspace-write", message=MESSAGE))
        r, rep = self.audit("--permissions", "bypass")
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("on-request/workspace-write", rep["detail"])

    def test_a_second_message_or_a_changed_one_is_an_operator_error(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE) + turn(2, message="Do it again."))
        r, rep = self.audit("--message", self.msg)
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("2 user messages, 1 declared", rep["detail"])
        self.log("main", [meta()] + turn(1, message="Something else."))
        r, rep = self.audit("--message", self.msg)
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("differs", rep["detail"])

    def test_an_interrupted_turn_is_an_operator_error(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE) + event(1, "turn_aborted", reason="interrupted"))
        r, rep = self.audit()
        self.assertEqual(rep["outcome"], "operator error")
        self.assertEqual(rep["operator"]["interruptions"], 1)

    def test_a_thread_the_agent_spawned_on_another_model_is_a_switch(self):
        self.log("main", self.completed())
        sub = {"subagent": {"thread_spawn": {"parent_thread_id": MAIN}}}
        self.log("sub", [meta(sid="00000000-0000-0000-0000-00000000000b", source=sub)] + turn(2, model="gpt-5.6-luna"))
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "switched"))
        self.assertIn("a spawned thread", rep["detail"])
        r, rep = self.audit("--allow", "gpt-5.6-luna")
        self.assertEqual(rep["outcome"], "completed")
        self.assertEqual([t["subagent"] for t in rep["threads"]], [False, True])

    def test_a_reroute_event_is_a_switch(self):
        self.log("main", self.completed() + event(1, "model_reroute", from_model=REQ, to_model="gpt-5.6-luna"))
        r, rep = self.audit()
        self.assertEqual(rep["outcome"], "switched")

    def test_an_error_that_ended_the_session_is_a_lab_failure(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE, done=False) + event(1, "error", message="stream closed before response.completed"))
        r, rep = self.audit()
        self.assertEqual(rep["outcome"], "lab failure")
        self.assertIn("stream closed", rep["detail"])

    def test_a_turn_that_completed_with_an_error_is_a_lab_failure(self):
        # seen in a real log: the API refused the model and the turn still ended in a task_complete, with no last message and an `error`
        refusal = '{"type":"error","status":400,"error":{"type":"invalid_request_error","message":"The model is not supported when using Codex with a ChatGPT account."}}'
        self.log("main", [meta()] + turn(1, message=MESSAGE, error=refusal))
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "lab failure"))
        self.assertIn("not supported", rep["detail"])
        self.log("main", [meta()] + turn(1, message=MESSAGE, error=refusal) + turn(2, message=None))
        r, rep = self.audit()
        self.assertEqual(rep["outcome"], "completed", "a later turn that completes cleanly ends the session well")

    def test_a_safeguard_event_with_no_turn_after_it_is_blocked(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE, done=False) + event(1, "safety_check", detail="flagged"))
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "blocked"))

    def test_the_lab_stopping_the_client_says_why(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE, done=False))
        self.assertEqual(self.audit("--stopped-by", "budget")[1]["outcome"], "budget exhausted")
        self.assertEqual(self.audit("--stopped-by", "lab")[1]["outcome"], "lab failure")

    def test_a_session_cut_mid_work_with_no_reason_is_inconclusive(self):
        self.log("main", [meta()] + turn(1, message=MESSAGE, done=False))
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (2, "inconclusive"))

    def test_a_session_with_no_turn_is_inconclusive(self):
        self.log("main", [meta()])
        r, rep = self.audit()
        self.assertEqual((r.returncode, rep["outcome"]), (2, "inconclusive"))

    def test_events_the_tool_does_not_know_are_counted_and_change_nothing(self):
        self.log("main", self.completed() + event(1, "something_new") + event(1, "something_new"))
        r, rep = self.audit()
        self.assertEqual(rep["outcome"], "completed")
        self.assertEqual(rep["threads"][0]["other_events"], {"something_new": 2})

    def test_two_main_threads_cannot_be_audited_as_one_session(self):
        self.log("a", self.completed())
        self.log("b", [meta(sid="00000000-0000-0000-0000-00000000000c")] + turn(3))
        r = run_py(TOOL, os.path.join(self.dir, "sessions"), "--requested", REQ)
        self.assertEqual(r.returncode, 2)
        self.assertIn("expected one main rollout log", r.stderr)

    def test_a_folder_with_no_log_is_unusable(self):
        os.makedirs(os.path.join(self.dir, "sessions"))
        r = run_py(TOOL, os.path.join(self.dir, "sessions"), "--requested", REQ)
        self.assertEqual(r.returncode, 2)

    def test_the_tool_writes_nothing(self):
        p = self.log("main", self.completed())
        with open(p, "rb") as f:
            before = f.read()
        self.audit("--message", self.msg)
        with open(p, "rb") as f:
            self.assertEqual(f.read(), before)
        self.assertEqual(sorted(os.listdir(self.dir)), ["msg.txt", "sessions"])


if __name__ == "__main__":
    unittest.main()
