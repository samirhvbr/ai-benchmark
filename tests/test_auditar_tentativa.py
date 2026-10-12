"""tools/auditar-tentativa.py: who answered in one attempt's Claude Code session, its safeguard stops, and the outcome.

Synthetic sessions only, built with the shapes the client writes: one line per content block of a response (all carrying the
message id), the client's note after a stopped response, the informational notice, the fallback event, the model identity
attachment, slash command echoes and an API error written as a `<synthetic>` message.
"""

import json
import os
import tempfile
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "auditar-tentativa.py")
REQ = "claude-fable-5-1"
SID = "11111111-2222-3333-4444-555555555555"


def at(minute, second=0):
    return "2026-10-12T10:%02d:%02d.000Z" % (minute, second)


def response(mid, ts, model=REQ, stop="tool_use", tools=1, effort="high", category=None, extra=(), iterations=None):
    """One API message: a thinking block, any extra blocks, then the tool calls (or a text block), one line each."""
    blocks = [{"type": "thinking", "thinking": ""}] + list(extra)
    blocks += [{"type": "tool_use", "name": "Bash", "input": {}}] * tools if tools else [{"type": "text", "text": "done"}]
    lines = []
    for b in blocks:
        msg = {"id": mid, "model": model, "role": "assistant", "stop_reason": stop, "content": [b],
               "usage": {"iterations": iterations or [{"type": "message"}]}}
        if category:
            msg["stop_details"] = {"type": "refusal", "category": category, "explanation": "blocked"}
        lines.append({"type": "assistant", "timestamp": ts, "requestId": "req_" + mid, "effort": effort,
                      "advisorModel": model, "version": "2.1.285", "message": msg})
    return lines


def user(ts, content, **extra):
    return [dict({"type": "user", "timestamp": ts, "message": {"role": "user", "content": content}}, **extra)]


def operator(ts, text="Read TAREFA.md and do it."):
    return user(ts, text)


def command(ts, name, out="Done"):
    return (user(ts, "<local-command-caveat>Caveat: the command below was run by the user.</local-command-caveat>", isMeta=True)
            + user(ts, "<command-name>/%s</command-name>\n<command-message>%s</command-message>\n<command-args></command-args>"
                   % (name, name))
            + user(ts, "<local-command-stdout>%s</local-command-stdout>" % out))


def tool_result(ts):
    return user(ts, [{"type": "tool_result", "tool_use_id": "t", "content": "ok"}], toolUseResult={"stdout": "ok"})


def identity(ts, model=REQ):
    return [{"type": "attachment", "timestamp": ts, "attachment": {"type": "model", "identity": {"modelId": model}}}]


def stop_note(ts):
    return user(ts, "Your response above was stopped by a safety classifier — this is not a tool or API error.", isMeta=True)


def notice(ts):
    return [{"type": "system", "subtype": "informational", "level": "notice", "timestamp": ts,
             "content": "Fable 5.1's safeguards stopped the response above · continuing once with that noted"}]


def fallback(ts, to="claude-opus-4-8"):
    return [{"type": "system", "subtype": "model_refusal_fallback", "level": "warning", "timestamp": ts, "content": "Switched",
             "trigger": "refusal", "direction": "retry", "scope": "session", "originalModel": REQ, "fallbackModel": to,
             "apiRefusalCategory": "cyber"}]


def api_error(ts, error="authentication_failed"):
    return [{"type": "assistant", "timestamp": ts, "isApiErrorMessage": True, "error": error,
             "message": {"model": "<synthetic>", "role": "assistant", "stop_reason": "stop_sequence",
                         "content": [{"type": "text", "text": "Login expired · Please run /login"}]}}]


def turn_end(ts, ms=120000):
    return [{"type": "system", "subtype": "turn_duration", "timestamp": ts, "durationMs": ms, "messageCount": 9}]


def interruption(ts):
    return user(ts, [{"type": "text", "text": "[Request interrupted by user]"}])


def start(model=REQ):
    return identity(at(1), model) + operator(at(1))


def finish(minute=9, model=REQ, effort="high"):
    return response("m-end", at(minute), model=model, stop="end_turn", tools=0, effort=effort) + turn_end(at(minute, 1))


def clean(model=REQ, effort="high"):
    return (start(model) + response("m1", at(2), model=model, effort=effort) + tool_result(at(2, 5))
            + finish(model=model, effort=effort))


def write(path, events):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        for e in events:
            f.write(json.dumps(e, ensure_ascii=False) + "\n")


def tree(path):
    return sorted(os.path.join(b, n) for b, _, names in os.walk(path) for n in names)


class Audit(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.project = os.path.join(self.tmp.name, "project")

    def tearDown(self):
        self.tmp.cleanup()

    def session(self, events, subagents=None, sid=SID):
        path = os.path.join(self.project, sid + ".jsonl")
        write(path, events)
        for rel, sub in (subagents or {}).items():
            write(os.path.join(self.project, sid, "subagents", rel), [dict(e, isSidechain=True) for e in sub])
        return path

    def audit(self, path, *args, requested=REQ):
        r = run_py(TOOL, path, "--requested", requested, "--json", *args)
        return r.returncode, (json.loads(r.stdout) if r.stdout.strip() else None), r.stderr

    def findings(self, rep, outcome):
        return [f["detail"] for f in rep["findings"] if f["outcome"] == outcome]

    def test_a_clean_session_is_completed_and_counts_responses_not_lines(self):
        code, rep, _ = self.audit(self.session(clean()))
        self.assertEqual((code, rep["outcome"]), (0, "completed"))
        main = rep["transcripts"][0]["models"][REQ]
        self.assertEqual((main["responses"], main["tool_calls"]), (2, 1), "four lines, two responses")
        self.assertEqual(rep["ended"]["how"], "model")
        self.assertEqual(rep["summary"]["stops"], 0)

    def test_a_fallback_in_the_main_conversation_is_switched(self):
        events = (start() + response("m1", at(2)) + tool_result(at(2, 5))
                  + response("m2", at(3), stop="refusal", tools=0, category="cyber") + notice(at(3, 1)) + stop_note(at(3, 1))
                  + fallback(at(4)) + identity(at(4, 30), "claude-opus-4-8")
                  + response("m3", at(5), model="claude-opus-4-8") + tool_result(at(5, 5)) + finish(model="claude-opus-4-8"))
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (1, "switched"))
        self.assertEqual(rep["transcripts"][0]["models"]["claude-opus-4-8"]["cause"], "fallback")
        stop = rep["transcripts"][0]["stops"][0]
        self.assertEqual((stop["category"], stop["seen_as"], stop["recovered"]), ("cyber", "stop_reason", False))
        self.assertEqual(len(rep["transcripts"][0]["fallbacks"]), 1)
        self.assertEqual(len(rep["transcripts"][0]["notices"]), 1)

    def test_a_subagent_that_changes_model_after_a_noted_stop_is_switched(self):
        sub = (user(at(2), "Investigate the export.") + response("s1", at(2, 10), model="claude-sonnet-5-5") + tool_result(at(2, 20))
               + stop_note(at(2, 21)) + response("s2", at(2, 30), model="claude-sonnet-5") + tool_result(at(2, 40))
               + response("s3", at(3), model="claude-sonnet-5", stop="end_turn", tools=0))
        path = self.session(clean("claude-sonnet-5-5"), {"workflows/wf_1/agent-a1.jsonl": sub,
                                                        "workflows/wf_1/journal.jsonl": [{"type": "started"}]})
        code, rep, _ = self.audit(path, requested="claude-sonnet-5-5")
        self.assertEqual((code, rep["outcome"]), (1, "switched"))
        self.assertEqual(rep["summary"]["subagents"], 1, "the workflow journal is not a transcript")
        t = rep["transcripts"][1]
        self.assertEqual(t["models"]["claude-sonnet-5"]["cause"], "after a stop")
        self.assertEqual(t["stops"][0]["seen_as"], "client note", "the stopped lines carry no refusal; the note marks them")
        self.assertEqual(rep["summary"]["by_model"]["claude-sonnet-5"]["responses"], 2)

    def test_a_stop_the_model_recovers_from_on_its_own_is_counted_not_fatal(self):
        events = (start() + response("m1", at(2), stop="refusal", tools=0, category="cyber") + notice(at(2, 1)) + stop_note(at(2, 1))
                  + response("m2", at(3), tools=2) + tool_result(at(3, 5)) + finish())
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (0, "completed"))
        self.assertEqual((rep["summary"]["stops"], rep["summary"]["stops_recovered"]), (1, 1))
        self.assertEqual(rep["transcripts"][0]["stops"][0]["tool_calls_after"], 2)

    def test_a_stop_after_which_the_model_does_no_more_work_is_blocked(self):
        # The stopped response itself carries a tool call that ran; it is not work done after the stop.
        stopped = (start() + response("m1", at(2)) + tool_result(at(2, 5))
                   + response("m2", at(3), stop="refusal", tools=1, category="cyber") + tool_result(at(3, 5))
                   + notice(at(3, 6)) + stop_note(at(3, 6)) + finish(4))
        code, rep, _ = self.audit(self.session(stopped))
        self.assertEqual((code, rep["outcome"]), (1, "blocked"))
        self.assertEqual(rep["ended"]["how"], "model")
        # The operator sending the message again is what brings the model back: that work is not a recovery.
        code, rep, _ = self.audit(self.session(stopped[:-2] + finish(4)[:-1] + operator(at(5)) + response("m4", at(6), tools=3)
                                               + tool_result(at(6, 5)) + finish()))
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertTrue(self.findings(rep, "blocked"), "the stop still ended the work of the first message")

    def test_a_session_that_ends_on_the_stopped_response_is_blocked(self):
        events = start() + response("m1", at(2)) + tool_result(at(2, 5)) + response("m2", at(3), stop="refusal", tools=0)
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"], rep["ended"]["how"]), (1, "blocked", "refusal"))
        self.assertIsNone(rep["transcripts"][0]["stops"][0]["category"])

    def test_an_allowed_id_matches_exactly_or_with_a_date_suffix_only(self):
        sub = user(at(2), "Find the files.") + response("s1", at(2, 10), model="claude-haiku-4-5-20251001", stop="end_turn", tools=0)
        path = self.session(clean(), {"agent-x.jsonl": sub})
        self.assertEqual(self.audit(path, "--allow", "claude-haiku-4-5")[:2][0], 0)
        code, rep, _ = self.audit(path)
        self.assertEqual((code, rep["outcome"]), (1, "switched"))
        self.assertEqual(rep["transcripts"][1]["models"]["claude-haiku-4-5-20251001"]["cause"], "unexplained")
        # A longer version is another model: claude-sonnet-5 does not allow claude-sonnet-5-5.
        code, rep, _ = self.audit(self.session(clean("claude-sonnet-5-5")), requested="claude-sonnet-5")
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("started on claude-sonnet-5-5", self.findings(rep, "operator error")[0])

    def test_messages_must_be_the_declared_ones(self):
        msg = os.path.join(self.tmp.name, "msg.txt")
        with open(msg, "w", encoding="utf-8") as f:
            f.write("Read TAREFA.md   and do it.\n")
        self.assertEqual(self.audit(self.session(clean()), "--message", msg)[0], 0, "whitespace does not count")
        with open(msg, "w", encoding="utf-8") as f:
            f.write("Read TAREFA.md and do it now.")
        code, rep, _ = self.audit(self.session(clean()), "--message", msg)
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("differs", self.findings(rep, "operator error")[0])
        code, rep, _ = self.audit(self.session(clean()[:-2] + operator(at(9, 30), "continue") + finish(10)))
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("2 operator messages; the procedure declares 1", self.findings(rep, "operator error")[0])
        self.assertEqual(rep["operator"]["messages"][0]["head"], "Read TAREFA.md and do it.")

    def test_commands_must_be_declared_unless_they_come_after_the_last_response(self):
        events = command(at(0), "model", "Set model to `Fable 5.1`") + clean()
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("/model", self.findings(rep, "operator error")[0])
        self.assertEqual(self.audit(self.session(events), "--allow-command", "/model")[0], 0)
        self.assertEqual(rep["operator"]["messages"][0]["head"], "Read TAREFA.md and do it.", "command echoes are not messages")
        code, rep, _ = self.audit(self.session(clean() + command(at(12), "exit", "Goodbye!")))
        self.assertEqual((code, rep["outcome"]), (0, "completed"))
        self.assertTrue(rep["operator"]["commands"][0]["after_end"])

    def test_an_interruption_or_a_wrong_start_model_is_an_operator_error(self):
        events = start() + response("m1", at(2)) + interruption(at(2, 30)) + response("m2", at(3)) + tool_result(at(3, 5)) + finish()
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("interrupted", self.findings(rep, "operator error")[0])
        events = (identity(at(1), "claude-sonnet-5-5") + operator(at(1)) + response("m0", at(1, 30), model="claude-sonnet-5-5")
                  + command(at(1, 40), "model", "Set model to `Fable 5.1`") + identity(at(1, 50)) + clean()[1:])
        code, rep, _ = self.audit(self.session(events), "--allow-command", "model")
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertEqual(rep["transcripts"][0]["models"]["claude-sonnet-5-5"]["cause"], "session start")
        self.assertFalse(self.findings(rep, "switched"), "a wrong start is the operator's, not a switch")

    def test_the_effort_must_be_the_declared_one(self):
        path = self.session(clean(effort="medium"))
        code, rep, _ = self.audit(path, "--effort", "high")
        self.assertEqual((code, rep["outcome"]), (1, "operator error"))
        self.assertIn("2 of 2 responses in the main conversation ran at effort medium; the attempt declares high",
                      self.findings(rep, "operator error"))
        self.assertEqual(self.audit(path, "--effort", "medium")[0], 0)
        self.assertEqual(self.audit(path)[0], 0, "without --effort the effort is reported, not checked")
        self.assertEqual(rep["transcripts"][0]["models"][REQ]["efforts"], {"medium": 2})

    def test_an_api_error_at_the_end_is_a_lab_failure(self):
        code, rep, _ = self.audit(self.session(start() + api_error(at(1, 5)) + turn_end(at(1, 6), 700)))
        self.assertEqual((code, rep["outcome"]), (1, "lab failure"))
        self.assertIn("authentication_failed", self.findings(rep, "lab failure")[0])
        self.assertEqual(rep["summary"]["responses"], 0, "a <synthetic> message is not a response")

    def test_a_session_cut_mid_work_needs_the_reason_from_the_lab(self):
        cut = start() + response("m1", at(2)) + tool_result(at(2, 5)) + response("m2", at(3))
        code, rep, _ = self.audit(self.session(cut))
        self.assertEqual((code, rep["outcome"], rep["ended"]["how"]), (2, "inconclusive", "cut"))
        code, rep, _ = self.audit(self.session(cut), "--stopped-by", "budget")
        self.assertEqual((code, rep["outcome"]), (1, "budget exhausted"))
        code, rep, _ = self.audit(self.session(cut), "--stopped-by", "lab")
        self.assertEqual((code, rep["outcome"]), (1, "lab failure"))
        code, rep, _ = self.audit(self.session(cut + interruption(at(3, 30))), "--stopped-by", "budget")
        self.assertEqual((code, rep["outcome"]), (1, "budget exhausted"), "the lab's own interruption is not the operator's")

    def test_work_of_an_unknown_kind_inside_a_response_is_inconclusive(self):
        events = (start() + response("m1", at(2), extra=[{"type": "server_tool_use", "name": "advisor"}]) + tool_result(at(2, 5))
                  + finish())
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (2, "inconclusive"))
        self.assertIn("block server_tool_use", self.findings(rep, "inconclusive")[0])
        events = start() + response("m1", at(2), iterations=[{"type": "message"}, {"type": "advisor_message"}]) + finish()
        code, rep, _ = self.audit(self.session(events))
        self.assertEqual((code, rep["outcome"]), (2, "inconclusive"))
        self.assertIn("iteration advisor_message", self.findings(rep, "inconclusive")[0])

    def test_a_folder_must_hold_exactly_one_attempt(self):
        self.session(clean())
        self.session(command(at(0), "login", "Login successful"), sid="99999999-0000-0000-0000-000000000000")
        code, rep, _ = self.audit(self.project)
        self.assertEqual((code, rep["outcome"]), (0, "completed"), "a session with only a /login is not an attempt")
        self.session(clean(), sid="88888888-0000-0000-0000-000000000000")
        code, _, err = self.audit(self.project)
        self.assertEqual(code, 2)
        self.assertIn("2 session transcripts", err)
        code, _, err = self.audit(os.path.join(self.tmp.name, "missing.jsonl"))
        self.assertEqual((code, "not found" in err), (2, True))
        sub = os.path.join(self.tmp.name, "agent-x.jsonl")
        write(sub, [dict(e, isSidechain=True) for e in clean()])
        code, _, err = self.audit(sub)
        self.assertEqual((code, "subagent transcript" in err), (2, True))

    def test_the_text_report_names_the_outcome_and_it_writes_nothing(self):
        path = self.session(clean() + command(at(12), "exit"))
        before = [(p, os.path.getsize(p), os.path.getmtime(p)) for p in tree(self.tmp.name)]
        r = run_py(TOOL, path, "--requested", REQ)
        self.assertEqual(r.returncode, 0)
        self.assertIn("OUTCOME    COMPLETED", r.stdout)
        self.assertIn("/exit (after the end)", r.stdout)
        self.audit(path)
        self.assertEqual(before, [(p, os.path.getsize(p), os.path.getmtime(p)) for p in tree(self.tmp.name)])


if __name__ == "__main__":
    unittest.main()
