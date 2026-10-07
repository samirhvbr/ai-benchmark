"""tools/etapas.py: the evidence of a run in two stages. Synthetic transcripts, deliveries and scorecards only; no client, no network.

Timestamps are built from the clock, because the checkpoint records the real UTC instant and the tool compares the two.
"""

import datetime
import hashlib
import json
import os
import stat
import tempfile
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "etapas.py")
MODEL_A, MODEL_B = "model-a", "model-b"
BASE = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0) - datetime.timedelta(hours=2)


def at(minutes):
    return (BASE + datetime.timedelta(minutes=minutes)).isoformat().replace("+00:00", "Z")


def assistant(minutes, model, tools=(), stop="tool_use", text="work"):
    content = [{"type": "text", "text": text}] + [{"type": "tool_use", "name": n} for n in tools]
    return {"type": "assistant", "timestamp": at(minutes), "message": {"model": model, "content": content, "stop_reason": stop,
                                                                       "usage": {"input_tokens": 10, "output_tokens": 5, "fallback_credit": None}}}


def operator(minutes, text="Agora leia o TAREFA.md e execute."):
    return {"type": "user", "timestamp": at(minutes), "message": {"content": text}}


def tool_result(minutes):
    return {"type": "user", "timestamp": at(minutes), "message": {"content": [{"type": "tool_result", "content": "ok"}]}}


def write_lines(path, events, raw=False):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "wb") as f:
        for e in events:
            f.write((json.dumps(e, ensure_ascii=False) if not raw else e).encode("utf-8") + b"\n")


def read_file(path):
    with open(path, encoding="utf-8") as f:
        return f.read()


def load_json(path):
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def tree_hashes(root):
    found = {}
    for base, _, names in os.walk(root):
        for n in names:
            p = os.path.join(base, n)
            with open(p, "rb") as f:
                found[os.path.relpath(p, root)] = hashlib.sha256(f.read()).hexdigest()
    return found


def writable(root):
    for base, dirs, names in os.walk(root):
        os.chmod(base, os.stat(base).st_mode | stat.S_IWUSR | stat.S_IXUSR)
        for n in names:
            p = os.path.join(base, n)
            if not os.path.islink(p):
                os.chmod(p, os.stat(p).st_mode | stat.S_IWUSR)


class Staged(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(lambda: (writable(self.tmp.name), self.tmp.cleanup()))
        self.t = self.tmp.name
        self.entrega = os.path.join(self.t, "entrega")
        self.out = os.path.join(self.t, "evidencia")
        self.logs = os.path.join(self.t, "logs")
        os.makedirs(os.path.join(self.entrega, "code"))
        for name, text in (("code/app.txt", "stage 1 code\n"), ("RELATORIO.md", "# report\n"), ("achados.json", "{}\n")):
            with open(os.path.join(self.entrega, name), "w") as f:
                f.write(text)
        self.main = os.path.join(self.logs, "main.jsonl")
        self.sub = os.path.join(self.logs, "subagents", "agent-1.jsonl")

    def stage_one_events(self, switch=False, wrong_model=False, launches=1, with_model=True):
        first = MODEL_B if wrong_model else MODEL_A
        events = [operator(0)]
        if with_model:
            events += [assistant(1, first, tools=["Bash"] + ["Agent"] * launches), tool_result(2),
                       assistant(3, MODEL_B if switch else first, tools=["Edit"]), tool_result(4)]
        events += [assistant(5, MODEL_B if (switch or wrong_model) else MODEL_A, stop="end_turn", text="stage 1 done")]
        return events

    def write_stage_one(self, **kw):
        write_lines(self.main, self.stage_one_events(**kw))
        if kw.get("launches", 1):
            write_lines(self.sub, [operator(1, "sub task"), assistant(2, MODEL_A, stop="end_turn")])

    def run_tool(self, *args):
        r = run_py(TOOL, *args)
        return r

    def checkpoint(self, *extra, quiet="0", model=MODEL_A):
        return self.run_tool("checkpoint", "--entrega", self.entrega, "--transcript", self.logs, "--out", self.out,
                             "--requested-model", model, "--fallback", "on", "--client", "synthetic client", "--quiet-seconds", quiet, *extra)

    def check(self, *extra):
        return self.run_tool("check", "--out", self.out, "--transcript", self.logs, "--json", *extra)

    def verdict(self):
        r = self.check()
        return r.returncode, json.loads(r.stdout)

    def append(self, events):
        with open(self.main, "ab") as f:
            for e in events:
                f.write(json.dumps(e).encode() + b"\n")

    # -------------------------------------------------------------------- checkpoint

    def test_a_checkpoint_keeps_a_read_only_copy_and_the_position_reached(self):
        self.write_stage_one()
        r = self.checkpoint("--cost-usd", "3.5", "--cost-kind", "estimated")
        self.assertEqual(r.returncode, 0, r.stderr)
        rec = load_json(os.path.join(self.out, "checkpoint.json"))
        self.assertEqual(rec["requested_model"], MODEL_A)
        self.assertEqual(rec["fallback"]["declared"], "on")
        self.assertEqual(rec["budget"]["cost_usd"], 3.5)
        self.assertEqual(rec["budget"]["cost_kind"], "estimated")
        self.assertEqual(rec["subagent_launches_main"], 1)
        self.assertEqual(rec["subagent_files"], 1)
        main = next(x for x in rec["transcripts"] if x["role"] == "main")
        with open(self.main, "rb") as fh:
            raw = fh.read()
        self.assertEqual((main["lines"], main["bytes"], main["prefix_sha256"]), (6, len(raw), hashlib.sha256(raw).hexdigest()))
        self.assertEqual(sorted(e["path"] for e in rec["snapshot"]["files"]), ["RELATORIO.md", "achados.json", os.path.join("code", "app.txt")])
        snap = os.path.join(self.out, "etapa1")
        self.assertEqual(tree_hashes(snap), tree_hashes(self.entrega))
        self.assertFalse(os.stat(os.path.join(snap, "RELATORIO.md")).st_mode & stat.S_IWUSR, "the copy is read-only")

    def test_it_refuses_while_the_transcripts_are_still_moving_and_keeps_nothing(self):
        self.write_stage_one()
        r = self.checkpoint(quiet="3600")
        self.assertEqual(r.returncode, 4)
        self.assertIn("NOT READY", r.stderr)
        self.assertFalse(os.path.exists(self.out))

    def test_it_refuses_while_the_main_session_has_not_ended_its_turn(self):
        write_lines(self.main, [operator(0), assistant(1, MODEL_A, tools=["Bash"], stop="tool_use")])
        r = self.checkpoint()
        self.assertEqual(r.returncode, 4)
        self.assertIn("end_turn", r.stderr)

    def test_it_never_overwrites_a_checkpoint(self):
        self.write_stage_one()
        self.assertEqual(self.checkpoint().returncode, 0)
        r = self.checkpoint()
        self.assertEqual(r.returncode, 2)
        self.assertIn("refusing to overwrite", r.stderr)

    def test_it_changes_nothing_it_reads(self):
        self.write_stage_one()
        before = (tree_hashes(self.entrega), tree_hashes(self.logs))
        self.checkpoint()
        self.check()
        self.assertEqual(before, (tree_hashes(self.entrega), tree_hashes(self.logs)))

    # -------------------------------------------------------------------- conformity

    def test_no_switch_is_conforming_and_says_only_what_was_observed(self):
        self.write_stage_one()
        self.checkpoint()
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (0, "conforming"))
        self.assertFalse(ev["fallback_observed"])
        self.assertIn("not a proof", ev["attribution"])

    def test_a_switch_after_the_checkpoint_keeps_the_run_conforming_and_reports_the_sequence(self):
        self.write_stage_one()
        self.checkpoint()
        self.append([operator(10, "Etapa 2"), assistant(11, MODEL_B, tools=["Edit"]), assistant(12, MODEL_B, stop="end_turn")])
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (0, "conforming"))
        self.assertTrue(ev["fallback_observed"])
        s = ev["switches_after_checkpoint"][0]
        self.assertEqual((s["from"], s["to"], s["file"], s["line"]), (MODEL_A, MODEL_B, "main.jsonl", 8))
        self.assertEqual(ev["models_before_checkpoint"], [MODEL_A])
        self.assertIn("Run of the agent or product with fallback", ev["attribution"])
        self.assertIn("line 8 of main.jsonl", ev["attribution"])

    def test_the_attribution_names_no_cause_and_ranks_no_model(self):
        self.write_stage_one()
        self.checkpoint()
        self.append([operator(10), assistant(11, MODEL_B, stop="end_turn")])
        text = self.verdict()[1]["attribution"].lower()
        for word in ("inferior", "worse", "weaker", "downgrade", "safety", "classifier", "refusal", "refus"):
            self.assertNotIn(word, text)

    def test_a_switch_before_the_checkpoint_is_not_conforming(self):
        self.write_stage_one(switch=True)
        self.checkpoint()
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (1, "non_conforming"))
        self.assertEqual(len(ev["switches_before_checkpoint"]), 1)
        self.assertIn("NOT CONFORMING", ev["attribution"])

    def test_a_model_other_than_the_requested_one_before_the_checkpoint_is_not_conforming(self):
        self.write_stage_one(wrong_model=True)
        self.checkpoint(model=MODEL_A)
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (1, "non_conforming"))
        self.assertTrue(any(MODEL_B in r for r in ev["reasons_non_conforming"]))

    def test_a_subagent_that_switched_before_the_checkpoint_counts(self):
        self.write_stage_one()
        write_lines(self.sub, [operator(1, "sub task"), assistant(2, MODEL_A), assistant(3, MODEL_B, stop="end_turn")])
        self.checkpoint()
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (1, "non_conforming"))
        self.assertEqual(ev["switches_before_checkpoint"][0]["role"], "subagent")

    # -------------------------------------------------------------------- insufficient records

    def test_a_missing_transcript_is_inconclusive_not_conforming(self):
        self.write_stage_one()
        self.checkpoint()
        os.remove(self.sub)
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (3, "inconclusive"))
        self.assertTrue(any("agent-1.jsonl" in r for r in ev["reasons_inconclusive"]))

    def test_a_transcript_changed_before_the_recorded_position_is_inconclusive(self):
        self.write_stage_one()
        self.checkpoint()
        with open(self.main, "rb") as fh:
            raw = fh.read().replace(b"stage 1 done", b"stage 1 DONE")
        with open(self.main, "wb") as fh:
            fh.write(raw)
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (3, "inconclusive"))
        self.assertTrue(any("changed" in r for r in ev["reasons_inconclusive"]))

    def test_subagent_launches_without_their_transcripts_are_inconclusive(self):
        write_lines(self.main, self.stage_one_events(launches=2))
        write_lines(self.sub, [operator(1, "sub task"), assistant(2, MODEL_A, stop="end_turn")])
        self.checkpoint()
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (3, "inconclusive"))
        self.assertTrue(any("launched 2 subagent" in r for r in ev["reasons_inconclusive"]))

    def test_no_model_in_the_main_session_before_the_checkpoint_is_inconclusive(self):
        write_lines(self.main, [operator(0), {"type": "assistant", "timestamp": at(1), "message": {"model": "<synthetic>", "content": [], "stop_reason": "end_turn"}}])
        self.checkpoint()
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (3, "inconclusive"))

    def test_the_instant_only_check_calls_clean_what_the_staged_check_does_not(self):
        """A subagent that switched before the checkpoint, whose transcript is no longer given at the end. The instant-only tool reads the
        directory it is handed and sees nothing wrong; the checkpoint kept the evidence, so the staged check does not call the run clean."""
        self.write_stage_one()
        write_lines(self.sub, [operator(1, "sub task"), assistant(2, MODEL_A), assistant(3, MODEL_B, stop="end_turn")])
        self.checkpoint()
        os.remove(self.sub)
        r = run_py(os.path.join(ROOT, "tools", "modelos-usados.py"), self.logs, "--not-before", at(60))
        self.assertEqual(r.returncode, 0, "the instant-only tool sees a clean directory")
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (1, "non_conforming"))
        self.assertEqual(ev["switches_before_checkpoint"][0]["role"], "subagent")
        self.assertTrue(any("agent-1.jsonl" in r for r in ev["reasons_inconclusive"]), "and the missing transcript is reported too")

    # -------------------------------------------------------------------- positions

    def test_a_line_holding_a_unicode_line_separator_does_not_move_the_boundary(self):
        write_lines(self.main, [operator(0), assistant(1, MODEL_A, tools=["Agent"], text="before after"), assistant(2, MODEL_A, stop="end_turn")])
        write_lines(self.sub, [operator(1, "sub"), assistant(2, MODEL_A, stop="end_turn")])
        self.checkpoint()
        rec = load_json(os.path.join(self.out, "checkpoint.json"))
        self.assertEqual(next(x for x in rec["transcripts"] if x["role"] == "main")["lines"], 3)
        self.append([operator(9), assistant(10, MODEL_B, stop="end_turn")])
        s = self.verdict()[1]["switches_after_checkpoint"][0]
        self.assertEqual(s["line"], 5)

    def test_the_first_answer_after_the_checkpoint_can_be_the_switch(self):
        self.write_stage_one()
        self.checkpoint()
        self.append([assistant(9, MODEL_B, stop="end_turn")])
        s = self.verdict()[1]["switches_after_checkpoint"][0]
        self.assertEqual((s["from"], s["to"], s["line"]), (MODEL_A, MODEL_B, 7))

    def test_a_subagent_started_after_the_checkpoint_is_read_as_stage_two(self):
        self.write_stage_one()
        self.checkpoint()
        write_lines(os.path.join(self.logs, "subagents", "agent-2.jsonl"), [operator(20, "later"), assistant(21, MODEL_B, stop="end_turn")])
        code, ev = self.verdict()
        self.assertEqual((code, ev["verdict"]), (0, "conforming"))
        self.assertIn(MODEL_B, ev["models_after_checkpoint"])
        self.assertTrue(ev["fallback_observed"])

    # -------------------------------------------------------------------- finalize and profiles

    def test_finalize_keeps_the_final_delivery_and_the_budget_per_stage(self):
        self.write_stage_one()
        self.checkpoint("--cost-usd", "2.0")
        self.append([operator(10, "Etapa 2"), assistant(11, MODEL_B, tools=["Edit", "Edit"]), assistant(12, MODEL_B, stop="end_turn")])
        with open(os.path.join(self.entrega, "code", "app.txt"), "w") as f:
            f.write("final code\n")
        r = self.run_tool("finalize", "--entrega", self.entrega, "--transcript", self.logs, "--out", self.out, "--requested-model", MODEL_A,
                          "--fallback", "on", "--quiet-seconds", "0", "--cost-usd", "5.5")
        self.assertEqual(r.returncode, 0, r.stderr)
        summary = load_json(os.path.join(self.out, "etapas.json"))
        self.assertEqual(summary["conformity"]["verdict"], "conforming")
        self.assertTrue(summary["budget"]["shared_pool"])
        self.assertEqual(summary["budget"]["stage_2_use"]["cost_usd"], 3.5)
        self.assertEqual(summary["budget"]["stage_2_use"]["tool_calls"], 2)
        self.assertNotEqual(summary["snapshots"]["stage_1"], summary["snapshots"]["final"])
        self.assertEqual(read_file(os.path.join(self.out, "etapa1", "code", "app.txt")), "stage 1 code\n", "the checkpoint stayed untouched")
        self.assertEqual(read_file(os.path.join(self.out, "etapa2", "code", "app.txt")), "final code\n")

    def test_finalize_exits_with_the_verdict(self):
        self.write_stage_one(switch=True)
        self.checkpoint()
        self.append([operator(10), assistant(11, MODEL_B, stop="end_turn")])
        r = self.run_tool("finalize", "--entrega", self.entrega, "--transcript", self.logs, "--out", self.out, "--requested-model", MODEL_A,
                          "--quiet-seconds", "0")
        self.assertEqual(r.returncode, 1)
        self.assertIn("conformity: non_conforming", r.stdout)

    def test_the_profiles_are_shown_side_by_side_with_no_combined_total(self):
        def card(path, total, cats):
            with open(path, "w") as f:
                json.dump({"total": total, "grade": "Silver", "categories": {k: {"score": v, "max": 100} for k, v in cats.items()}}, f)
        one, two = os.path.join(self.t, "one.json"), os.path.join(self.t, "two.json")
        card(one, 400, {"ARCH": 60, "SEC": 0})
        card(two, 520, {"ARCH": 50, "SEC": 90})
        r = self.run_tool("perfis", "--checkpoint", one, "--final", two, "--json")
        rep = json.loads(r.stdout)
        self.assertEqual(rep["official"]["total"], 520)
        self.assertEqual(rep["informative"]["total"], 400)
        self.assertIn("not an official score", rep["informative"]["note"])
        arch = next(x for x in rep["categories"] if x["category"] == "ARCH")
        self.assertEqual(arch["difference"], -10, "a regression in stage 2 shows as a negative difference")
        text = self.run_tool("perfis", "--checkpoint", one, "--final", two).stdout
        self.assertEqual(text.count("total"), 2)
        self.assertNotIn("920", text)


if __name__ == "__main__":
    unittest.main()
