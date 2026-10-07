#!/usr/bin/env python3
"""modelos-usados.py — which model answered, and when it changed. Read-only: it prints a report and writes nothing.

PROTOCOL §3 says a run measures the model it is filed under, and that a client can switch models on its own (after a safety classifier
stops a response, on a rate limit, when a model is unavailable). Until now a switch was found by reading the session log by hand. This
reads Claude Code transcripts (JSON Lines; the main session and every subagent have their own file) and reports, per file, each model that
wrote an assistant message, how many messages and tool calls it wrote, when it first and last answered, and every point where the model
changed.

    python3 tools/modelos-usados.py PATH [PATH ...]                        # files, or directories searched for *.jsonl
    python3 tools/modelos-usados.py PATH --not-before 2026-10-07T18:00:00Z # a switch is allowed only at or after that instant
    python3 tools/modelos-usados.py PATH --json

Exit 0: no file has a switch, or (with --not-before) every switch is at or after the instant. Exit 1: a switch was found (before the
instant, when one is given). Exit 2: a path could not be read.

`--not-before` is for a run in stages: pass the moment the first stage's delivery was saved, and the exit code says whether everything
before it was written by one model.

What it does not know: clients other than Claude Code, which model was requested (only the one that answered), and whether a message
written after a switch changed anything that matters. Messages marked `<synthetic>` by the client are ignored. Stdlib only. No effect on
any score.
"""

import argparse
import datetime
import json
import os
import sys


def parse_time(text):
    """An ISO 8601 instant as an aware datetime (naive input is taken as UTC)."""
    t = datetime.datetime.fromisoformat(text.replace("Z", "+00:00"))
    return t if t.tzinfo else t.replace(tzinfo=datetime.timezone.utc)


def find_files(paths):
    files = []
    for p in paths:
        if os.path.isdir(p):
            for base, _, names in os.walk(p):
                files += [os.path.join(base, n) for n in names if n.endswith(".jsonl")]
        elif os.path.isfile(p):
            files.append(p)
        else:
            raise OSError("not found: %s" % p)
    return sorted(files)


def read_answers(path):
    """The (timestamp, model, tool_calls) of every assistant message, in file order. A damaged line is skipped."""
    answers = []
    with open(path, encoding="utf-8") as f:
        for line in f:
            try:
                e = json.loads(line)
            except ValueError:
                continue
            if not isinstance(e, dict) or e.get("type") != "assistant":
                continue
            msg = e.get("message") or {}
            model = msg.get("model") or ""
            if not model or model == "<synthetic>" or not e.get("timestamp"):
                continue
            content = msg.get("content") if isinstance(msg.get("content"), list) else []
            tools = sum(1 for c in content if isinstance(c, dict) and c.get("type") == "tool_use")
            answers.append((e["timestamp"], model, tools))
    return answers


def analyze_file(path, not_before):
    models = {}
    switches = []
    previous = None
    for ts, model, tools in read_answers(path):
        m = models.setdefault(model, {"messages": 0, "tool_calls": 0, "first": ts, "last": ts})
        m["messages"] += 1
        m["tool_calls"] += tools
        m["last"] = ts
        if previous is not None and model != previous:
            allowed = not_before is not None and parse_time(ts) >= not_before
            switches.append({"at": ts, "from": previous, "to": model, "allowed": allowed})
        previous = model
    return {"path": path, "models": models, "switches": switches}


def analyze(paths, not_before=None):
    files = [analyze_file(p, not_before) for p in find_files(paths)]
    ok = all(s["allowed"] for f in files for s in f["switches"])
    return {"not_before": not_before.isoformat() if not_before else None, "files": files, "ok": ok}


def render(report):
    out = []
    w = out.append
    if report["not_before"]:
        w("a switch is allowed only at or after %s" % report["not_before"])
    if not report["files"]:
        w("no transcript found")
    for f in report["files"]:
        w("== %s" % f["path"])
        for model, m in sorted(f["models"].items(), key=lambda kv: kv[1]["first"]):
            w("   %-28s %5d messages %5d tool calls  %s .. %s" % (model, m["messages"], m["tool_calls"], m["first"], m["last"]))
        for s in f["switches"]:
            w("   switch at %s: %s -> %s%s" % (s["at"], s["from"], s["to"], "  (allowed)" if s["allowed"] else "  (NOT allowed)"))
    w("OK: no switch that is not allowed." if report["ok"] else "SWITCH: the model changed inside a transcript; what came after is not the model the run is filed under.")
    return "\n".join(out) + "\n"


def main():
    ap = argparse.ArgumentParser(description="Which model answered in Claude Code transcripts, and when it changed (read-only)")
    ap.add_argument("paths", nargs="+", metavar="PATH", help="transcript files, or directories searched for *.jsonl")
    ap.add_argument("--not-before", metavar="ISO8601", help="a switch is allowed only at or after this instant")
    ap.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()
    try:
        not_before = parse_time(a.not_before) if a.not_before else None
        report = analyze(a.paths, not_before)
    except (OSError, ValueError) as e:
        print("[modelos-usados] %s" % e, file=sys.stderr)
        sys.exit(2)
    sys.stdout.write(json.dumps(report, ensure_ascii=False, indent=2) + "\n" if a.json else render(report))
    sys.exit(0 if report["ok"] else 1)


if __name__ == "__main__":
    main()
