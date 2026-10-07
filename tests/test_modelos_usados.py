"""tools/modelos-usados.py: which model answered in a Claude Code transcript, and when it changed. Synthetic transcripts only."""

import json
import os
import tempfile
import unittest

from helpers import ROOT, run_py

TOOL = os.path.join(ROOT, "tools", "modelos-usados.py")


def answer(ts, model, tools=0):
    return {"type": "assistant", "timestamp": ts, "message": {"model": model, "content": [{"type": "tool_use"}] * tools}}


def write(path, events, extra_lines=()):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        for e in events:
            f.write(json.dumps(e) + "\n")
        for line in extra_lines:
            f.write(line + "\n")


def tree(path):
    return sorted(os.path.join(b, n) for b, _, names in os.walk(path) for n in names)


class Models(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def run_tool(self, *args):
        r = run_py(TOOL, "--json", *args)
        return r.returncode, (json.loads(r.stdout) if r.stdout.strip() else None), r.stderr

    def test_one_model_is_ok_and_counts_messages_and_tool_calls(self):
        f = os.path.join(self.dir, "main.jsonl")
        write(f, [answer("2026-10-07T10:00:00Z", "model-a", 1), answer("2026-10-07T10:01:00Z", "model-a", 2)])
        code, rep, _ = self.run_tool(f)
        self.assertEqual(code, 0)
        self.assertTrue(rep["ok"])
        self.assertEqual(rep["files"][0]["models"]["model-a"]["messages"], 2)
        self.assertEqual(rep["files"][0]["models"]["model-a"]["tool_calls"], 3)
        self.assertEqual(rep["files"][0]["switches"], [])

    def test_a_switch_is_found_with_its_instant_and_exits_1(self):
        f = os.path.join(self.dir, "main.jsonl")
        write(f, [answer("2026-10-07T10:00:00Z", "model-a"), answer("2026-10-07T10:09:00Z", "model-b", 2)])
        code, rep, _ = self.run_tool(f)
        self.assertEqual(code, 1)
        self.assertEqual(rep["files"][0]["switches"], [{"at": "2026-10-07T10:09:00Z", "from": "model-a", "to": "model-b", "allowed": False}])

    def test_synthetic_messages_other_events_and_damaged_lines_are_ignored(self):
        f = os.path.join(self.dir, "main.jsonl")
        write(f, [answer("2026-10-07T10:00:00Z", "model-a"), answer("2026-10-07T10:01:00Z", "<synthetic>"),
                  {"type": "user", "timestamp": "2026-10-07T10:02:00Z", "message": {"model": "model-z"}},
                  answer("2026-10-07T10:03:00Z", "model-a")], extra_lines=["{ not json", ""])
        code, rep, _ = self.run_tool(f)
        self.assertEqual(code, 0)
        self.assertEqual(list(rep["files"][0]["models"]), ["model-a"])

    def test_a_directory_is_searched_including_subagent_files(self):
        write(os.path.join(self.dir, "s", "main.jsonl"), [answer("2026-10-07T10:00:00Z", "model-a")])
        write(os.path.join(self.dir, "s", "subagents", "agent-1.jsonl"),
              [answer("2026-10-07T10:00:00Z", "model-a"), answer("2026-10-07T10:04:00Z", "model-b")])
        code, rep, _ = self.run_tool(self.dir)
        self.assertEqual(code, 1)
        self.assertEqual(len(rep["files"]), 2)
        self.assertEqual([len(x["switches"]) for x in rep["files"]], [0, 1])

    def test_not_before_allows_a_switch_at_or_after_the_instant_only(self):
        f = os.path.join(self.dir, "main.jsonl")
        write(f, [answer("2026-10-07T10:00:00Z", "model-a"), answer("2026-10-07T10:30:00Z", "model-b")])
        code, rep, _ = self.run_tool(f, "--not-before", "2026-10-07T10:10:00Z")
        self.assertEqual(code, 0)
        self.assertTrue(rep["files"][0]["switches"][0]["allowed"])
        code, rep, _ = self.run_tool(f, "--not-before", "2026-10-07T10:30:00Z")
        self.assertEqual(code, 0, "a switch exactly at the instant is allowed")
        code, rep, _ = self.run_tool(f, "--not-before", "2026-10-07T10:31:00Z")
        self.assertEqual(code, 1, "the switch came before the instant")
        self.assertFalse(rep["files"][0]["switches"][0]["allowed"])

    def test_an_unreadable_path_or_a_bad_instant_exits_2(self):
        code, _, err = self.run_tool(os.path.join(self.dir, "missing.jsonl"))
        self.assertEqual(code, 2)
        self.assertIn("not found", err)
        f = os.path.join(self.dir, "main.jsonl")
        write(f, [answer("2026-10-07T10:00:00Z", "model-a")])
        self.assertEqual(self.run_tool(f, "--not-before", "yesterday")[0], 2)

    def test_it_writes_nothing(self):
        write(os.path.join(self.dir, "main.jsonl"), [answer("2026-10-07T10:00:00Z", "model-a"), answer("2026-10-07T10:09:00Z", "model-b")])
        before = [(p, os.path.getsize(p), os.path.getmtime(p)) for p in tree(self.dir)]
        self.run_tool(self.dir)
        run_py(TOOL, self.dir)
        self.assertEqual(before, [(p, os.path.getsize(p), os.path.getmtime(p)) for p in tree(self.dir)])


if __name__ == "__main__":
    unittest.main()
