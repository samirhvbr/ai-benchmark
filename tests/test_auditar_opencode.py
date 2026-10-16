"""tools/auditar-opencode.py: who answered in one attempt's OpenCode session, its effort and agent, what ended it, and the outcome.

Synthetic records only, built with the shapes OpenCode 1.18.33 wrote in the real sessions of the LEB-300-A pilot: a `session` row (agent, the selected model
as JSON, the totals), `message` rows whose JSON is a user message or an assistant one (provider, model, effort variant, agent, `finish`, `error`, tokens,
cost, times), `part` rows with the text of the user's message, and a log folder whose `message=stream` lines name the model of every request. The errors
are the two shapes seen for real (`APIError` with a status, `MessageAbortedError`); the others use the names OpenCode gives its error types.
"""

import json
import os
import sqlite3
import tempfile
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "auditar-opencode.py")
PROV, REQ, EFFORT = "deepseek", "deepseek-flash", "high"
MESSAGE = "Read TASK.md and carry it out."

SCHEMA = [
    "create table session (id text primary key, parent_id text, directory text, title text, version text, agent text, model text, cost real, tokens_input integer, "
    "tokens_output integer, tokens_reasoning integer, tokens_cache_read integer, tokens_cache_write integer, time_created integer, time_updated integer)",
    "create table message (id text primary key, session_id text, time_created integer, time_updated integer, data text)",
    "create table part (id text primary key, message_id text, session_id text, time_created integer, time_updated integer, data text)",
]


def assistant(n, provider=PROV, model=REQ, variant=EFFORT, agent="build", finish="tool-calls", error=None, completed=True):
    t0 = 1_000_000 + n * 10_000
    d = {"parentID": "msg_user", "role": "assistant", "mode": agent, "agent": agent, "path": {"cwd": "/srv/x", "root": "/"}, "cost": 0.001,
         "tokens": {"total": 110, "input": 60, "output": 20, "reasoning": 10, "cache": {"write": 0, "read": 20}}, "modelID": model, "providerID": provider,
         "time": {"created": t0, "completed": t0 + 5_000} if completed else {"created": t0}}
    if variant is not None:
        d["variant"] = variant
    if finish is not None:
        d["finish"] = finish
    if error is not None:
        d["error"] = error
    return d


def user(text=MESSAGE):
    return {"role": "user", "text": text}


def api_error(status=404, message="Not Found: model not found"):
    return {"name": "APIError", "data": {"message": message, "statusCode": status, "isRetryable": False, "responseHeaders": {"server": "cloudflare"}}}


def session(sid="ses_root", parent=None, messages=None, agent="build", model=None, cost=0.003, tokens=(180, 60, 30, 60, 0)):
    return {"id": sid, "parent": parent, "agent": agent, "cost": cost, "tokens": tokens,
            "model": model if model is not None else {"id": REQ, "providerID": PROV, "variant": EFFORT},
            "messages": messages if messages is not None else [user(), assistant(1), assistant(2, finish="stop")]}


def build(path, sessions):
    con = sqlite3.connect(os.path.join(path, "opencode.db"))
    for ddl in SCHEMA:
        con.execute(ddl)
    n = 0
    for s in sessions:
        ti, to, tr, tcr, tcw = s["tokens"]
        con.execute("insert into session values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    (s["id"], s["parent"], "/srv/x", "a title", "1.18.33", s["agent"], json.dumps(s["model"]), s["cost"], ti, to, tr, tcr, tcw, 1_000_000, 1_000_001))
        for m in s["messages"]:
            n += 1
            mid = "msg_%d" % n
            if m.get("role") == "user":
                created = 1_000_000 + n
                con.execute("insert into message values (?,?,?,?,?)", (mid, s["id"], created, created,
                                                                       json.dumps({"role": "user", "time": {"created": created}, "agent": "build",
                                                                                   "model": {"providerID": PROV, "modelID": REQ, "variant": EFFORT}})))
                con.execute("insert into part values (?,?,?,?,?,?)", ("prt_%d" % n, mid, s["id"], created, created, json.dumps({"type": "text", "text": m["text"]})))
            else:
                created = m["time"]["created"]
                con.execute("insert into message values (?,?,?,?,?)", (mid, s["id"], created, created, json.dumps(m)))
    con.commit()
    con.close()


def log(path, lines):
    os.makedirs(os.path.join(path, "log"), exist_ok=True)
    with open(os.path.join(path, "log", "opencode.log"), "w", encoding="utf-8") as f:
        for line in lines:
            f.write("timestamp=2026-10-16T14:00:00.000Z level=INFO run=ab12cd34 " + line + "\n")


def stream(provider=PROV, model=REQ, small=False, agent=None):
    return "message=stream providerID=%s modelID=%s session.id=ses_root%s%s mode=primary" % (provider, model, " small=true" if small else "", " agent=%s" % agent if agent else "")


class Case(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.dir = self._tmp.name
        self.msg = os.path.join(self.dir, "msg.txt")
        with open(self.msg, "w", encoding="utf-8") as f:
            f.write(MESSAGE)
        self.rec = os.path.join(self.dir, ".local", "share", "opencode")
        os.makedirs(self.rec)

    def tearDown(self):
        self._tmp.cleanup()

    def audit(self, *args, provider=PROV, requested=REQ):
        r = run_py(TOOL, self.rec, "--provider", provider, "--requested", requested, "--json", *args)
        return r, (json.loads(r.stdout) if r.stdout.strip() else None)

    def standard(self, *args):
        return self.audit("--effort", EFFORT, "--message", self.msg, *args)


class Outcomes(Case):
    def test_a_session_that_ended_by_itself_on_the_requested_model_is_completed(self):
        build(self.rec, [session()])
        log(self.rec, [stream(), stream(small=True, agent="title")])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (0, "completed"), r.stderr)
        self.assertEqual(rep["sessions"][0]["models"], ["deepseek/deepseek-flash"])
        self.assertEqual(rep["sessions"][0]["finishes"], {"stop": 1, "tool-calls": 1})
        self.assertEqual(rep["tokens"], {"input": 180, "output": 60, "reasoning": 30, "cache_read": 60, "cache_write": 0, "total": 330})
        self.assertEqual(rep["cost_usd"], 0.003)
        self.assertEqual(rep["operator"], {"user_messages": 1, "declared": 1, "interruptions": 0})
        self.assertEqual(rep["requests_in_log"]["total"], 2)
        self.assertEqual(rep["client_version"], "1.18.33")

    def test_the_text_report_names_the_outcome(self):
        build(self.rec, [session()])
        r = run_py(TOOL, self.rec, "--provider", PROV, "--requested", REQ, "--effort", EFFORT)
        self.assertIn("OUTCOME    COMPLETED", r.stdout)

    def test_a_folder_or_the_database_itself_can_be_given(self):
        build(self.rec, [session()])
        r = run_py(TOOL, os.path.join(self.rec, "opencode.db"), "--provider", PROV, "--requested", REQ, "--json")
        self.assertEqual(json.loads(r.stdout)["outcome"], "completed")
        r = run_py(TOOL, self.dir, "--provider", PROV, "--requested", REQ, "--json")
        self.assertEqual(json.loads(r.stdout)["outcome"], "completed")

    def test_a_session_that_started_on_another_model_is_an_operator_error_and_a_switch(self):
        build(self.rec, [session(messages=[user(), assistant(1, model="deepseek-pro"), assistant(2, finish="stop")])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "operator error"))
        self.assertIn("switched", [f["outcome"] for f in rep["findings"]])

    def test_a_session_created_on_another_model_than_the_one_that_answered_is_an_operator_error(self):
        build(self.rec, [session(model={"id": "deepseek-pro", "providerID": PROV, "variant": EFFORT})])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "operator error")

    def test_the_same_model_id_under_another_provider_is_not_the_requested_model(self):
        build(self.rec, [session(messages=[user(), assistant(1, provider="openrouter"), assistant(2, provider="openrouter", finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "operator error")

    def test_an_effort_other_than_the_declared_one_is_an_operator_error(self):
        build(self.rec, [session(messages=[user(), assistant(1, variant="max"), assistant(2, variant="max", finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("max", rep["detail"])

    def test_a_model_with_no_effort_setting_is_declared_with_default(self):
        build(self.rec, [session(model={"id": REQ, "providerID": PROV},
                                 messages=[user(), assistant(1, variant=None), assistant(2, variant=None, finish="stop")])])
        r, rep = self.audit("--effort", "default", "--message", self.msg)
        self.assertEqual(rep["outcome"], "completed", rep)
        r, rep = self.audit("--effort", "high", "--message", self.msg)
        self.assertEqual(rep["outcome"], "operator error")

    def test_another_agent_than_the_declared_one_is_an_operator_error(self):
        build(self.rec, [session(messages=[user(), assistant(1, agent="plan"), assistant(2, agent="plan", finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("plan", rep["detail"])

    def test_a_second_message_or_a_changed_one_is_an_operator_error(self):
        build(self.rec, [session(messages=[user(), assistant(1, finish="stop"), user("Also do this."), assistant(2, finish="stop")])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "operator error"))
        self.assertEqual(rep["operator"]["user_messages"], 2)

    def test_a_changed_message_is_an_operator_error(self):
        build(self.rec, [session(messages=[user("Read TASK.md and do something else."), assistant(1, finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "operator error")
        self.assertIn("differs", rep["detail"])

    def test_whitespace_in_the_message_does_not_matter(self):
        build(self.rec, [session(messages=[user("Read  TASK.md\nand carry it out.\n"), assistant(1, finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "completed")

    def test_an_interrupted_turn_the_lab_did_not_cause_is_an_operator_error(self):
        aborted = assistant(2, finish=None, error={"name": "MessageAbortedError", "data": {"message": "Aborted"}})
        build(self.rec, [session(messages=[user(), assistant(1), aborted])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "operator error"))
        self.assertEqual(rep["operator"]["interruptions"], 1)

    def test_the_budget_stop_that_aborted_the_turn_is_budget_exhausted(self):
        aborted = assistant(2, finish=None, error={"name": "MessageAbortedError", "data": {"message": "Aborted"}})
        build(self.rec, [session(messages=[user(), assistant(1), aborted])])
        r, rep = self.standard("--stopped-by", "budget")
        self.assertEqual((r.returncode, rep["outcome"]), (1, "budget exhausted"))

    def test_a_budget_stop_that_left_the_last_message_open_is_budget_exhausted(self):
        build(self.rec, [session(messages=[user(), assistant(1), assistant(2, finish=None, completed=False)])])
        r, rep = self.standard("--stopped-by", "budget")
        self.assertEqual(rep["outcome"], "budget exhausted")

    def test_a_spawned_session_on_another_model_is_a_switch(self):
        child = session("ses_child", parent="ses_root", agent="general", messages=[user("sub task"), assistant(5, model="deepseek-pro", agent="general", finish="stop")])
        build(self.rec, [session(), child])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "switched"))
        self.assertIn("a spawned session", rep["detail"])
        self.assertTrue(any(s["spawned"] for s in rep["sessions"]))

    def test_a_spawned_session_on_the_requested_model_changes_nothing(self):
        child = session("ses_child", parent="ses_root", agent="general", messages=[user("sub task"), assistant(5, agent="general", finish="stop")])
        build(self.rec, [session(), child])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "completed")
        self.assertEqual(rep["tokens"]["total"], 660)

    def test_a_request_to_another_model_in_the_log_is_a_switch_even_when_it_is_the_auxiliary_one(self):
        build(self.rec, [session()])
        log(self.rec, [stream(), stream(model="deepseek-lite", small=True, agent="title")])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "switched"))
        self.assertIn("auxiliary", rep["detail"])
        r, rep = self.standard("--allow", "deepseek/deepseek-lite")
        self.assertEqual(rep["outcome"], "completed")

    def test_a_model_id_with_slashes_is_allowed_by_its_provider(self):
        build(self.rec, [session(model={"id": "deepseek/deepseek-v4.1-flash", "providerID": "openrouter", "variant": EFFORT},
                                 messages=[user(), assistant(1, provider="openrouter", model="deepseek/deepseek-v4.1-flash", finish="stop")])])
        log(self.rec, [stream("openrouter", "deepseek/deepseek-v4.1-flash"), stream("openrouter", "google/gemini-3.8-flash", small=True, agent="title")])
        r, rep = self.audit("--provider", "openrouter", "--effort", EFFORT, "--message", self.msg, "--allow", "openrouter/google/gemini-3.8-flash",
                            provider="openrouter", requested="deepseek/deepseek-v4.1-flash")
        self.assertEqual(rep["outcome"], "completed", rep)

    def test_a_refused_model_is_a_lab_failure(self):
        build(self.rec, [session(messages=[user(), assistant(1, finish=None, error=api_error(404))])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "lab failure"))
        self.assertIn("APIError 404", rep["detail"])

    def test_a_refused_key_is_a_lab_failure(self):
        err = {"name": "ProviderAuthError", "data": {"providerID": PROV, "message": "Authentication Fails"}}
        build(self.rec, [session(messages=[user(), assistant(1, finish=None, error=err)])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "lab failure")
        self.assertIn("ProviderAuthError", rep["detail"])

    def test_an_error_in_the_middle_that_the_session_recovered_from_does_not_end_it(self):
        build(self.rec, [session(messages=[user(), assistant(1, finish=None, error=api_error(503, "overloaded")), assistant(2, finish="stop")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "completed")
        self.assertEqual(rep["sessions"][0]["errors"][0]["status"], 503)

    def test_the_lab_stopping_the_client_says_why(self):
        build(self.rec, [session()])
        r, rep = self.standard("--stopped-by", "lab")
        self.assertEqual(rep["outcome"], "lab failure")

    def test_a_content_filter_stop_at_the_end_is_blocked(self):
        build(self.rec, [session(messages=[user(), assistant(1), assistant(2, finish="content-filter")])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (1, "blocked"))

    def test_a_session_cut_mid_work_with_no_reason_is_inconclusive(self):
        build(self.rec, [session(messages=[user(), assistant(1), assistant(2, finish=None, completed=False)])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (2, "inconclusive"))

    def test_a_last_message_that_ended_on_the_output_limit_is_inconclusive(self):
        build(self.rec, [session(messages=[user(), assistant(1), assistant(2, finish="length")])])
        r, rep = self.standard()
        self.assertEqual(rep["outcome"], "inconclusive")
        self.assertIn("output limit", rep["detail"])

    def test_a_session_with_no_assistant_message_is_inconclusive(self):
        build(self.rec, [session(messages=[user()])])
        r, rep = self.standard()
        self.assertEqual((r.returncode, rep["outcome"]), (2, "inconclusive"))

    def test_the_permission_decisions_of_the_log_are_counted(self):
        build(self.rec, [session()])
        log(self.rec, [stream(),
                       "message=evaluated permission=read pattern=srv/x/a action.permission=read action.pattern=* action.action=allow",
                       "message=evaluated permission=bash pattern=\"rm -rf x\" action.permission=bash action.pattern=* action.action=ask",
                       "message=evaluated permission=edit pattern=srv/x/b action.permission=edit action.pattern=* action.action=deny"])
        r, rep = self.standard()
        self.assertEqual(rep["permission_decisions"], {"allow": 1, "ask": 1, "deny": 1})

    def test_without_a_log_the_report_says_the_auxiliary_requests_were_not_audited(self):
        build(self.rec, [session()])
        r, rep = self.standard()
        self.assertIsNone(rep["requests_in_log"])
        self.assertTrue(any("auxiliary" in n for n in rep["notes"]))


class Unusable(Case):
    def test_two_root_sessions_cannot_be_audited_as_one(self):
        build(self.rec, [session("ses_a"), session("ses_b")])
        r, rep = self.standard()
        self.assertEqual(r.returncode, 2)
        self.assertIn("one root session", r.stderr)

    def test_a_folder_with_no_database_is_unusable(self):
        r = run_py(TOOL, self.rec, "--provider", PROV, "--requested", REQ)
        self.assertEqual(r.returncode, 2)
        self.assertIn("no opencode.db", r.stderr)

    def test_a_file_that_is_not_a_database_is_unusable(self):
        with open(os.path.join(self.rec, "opencode.db"), "w", encoding="utf-8") as f:
            f.write("not a database")
        r = run_py(TOOL, self.rec, "--provider", PROV, "--requested", REQ)
        self.assertEqual(r.returncode, 2)

    def test_an_allowed_model_needs_its_provider(self):
        build(self.rec, [session()])
        r = run_py(TOOL, self.rec, "--provider", PROV, "--requested", REQ, "--allow", "deepseek-lite")
        self.assertEqual(r.returncode, 2)
        self.assertIn("PROVIDER/MODEL", r.stderr)

    def test_the_tool_writes_nothing(self):
        build(self.rec, [session()])
        log(self.rec, [stream()])

        def snapshot():
            out = {}
            for d, _, fs in os.walk(self.dir):
                for f in fs:
                    with open(os.path.join(d, f), "rb") as fh:
                        out[os.path.relpath(os.path.join(d, f), self.dir)] = fh.read()
            return out

        before = snapshot()
        self.audit("--effort", EFFORT, "--message", self.msg)
        self.assertEqual(before, snapshot())


if __name__ == "__main__":
    unittest.main()
