#!/usr/bin/env python3
"""etapas.py — the evidence of a run in two stages (task 1.2.0, PROTOCOL §3.1). One file, standard library only, so the operator can copy
it to the execution VM. It reads transcripts and deliveries and never touches the client's configuration.

    etapas.py checkpoint --entrega DIR --transcript PATH... --out DIR --requested-model ID [--fallback on|off|unknown] [--client TEXT]
                         [--cost-usd N --cost-kind measured|estimated] [--quiet-seconds 30]
        Run when stage 1 is over and BEFORE the second message. Refuses (exit 4) while a transcript is still being written or the main
        session has not ended its turn. Keeps a read-only copy of the delivery (OUT/etapa1) with a SHA-256 manifest and writes
        OUT/checkpoint.json: UTC instant, the position reached in every transcript (lines, bytes, hash of everything up to there, last
        timestamp), the models seen, the subagent launches against the subagent transcripts found, the budget consumed so far, the requested
        model, the declared fallback setting and the tool version.

    etapas.py finalize   (the same options)
        Run when stage 2 is over. Keeps the final delivery (OUT/etapa2), re-reads every transcript, and writes OUT/final.json and
        OUT/etapas.json: the conformity of the run, the sequence of models observed, the budget per stage and the attribution text.

    etapas.py check --out DIR --transcript PATH...      read-only; the same verdict without writing anything
    etapas.py perfis --checkpoint SCORECARD --final SCORECARD [--json]
        The two profiles side by side. The final one is the official score; the checkpoint one is informative. No combined total is made.

Conformity of a run to the staged protocol (the transcripts are examined whole, subagents included; the boundary is the recorded position):
    conforming       no model switch before the checkpoint in any transcript, and every model seen before it is the requested one
    non_conforming   a switch before the checkpoint, or a model other than the requested one answered before it
    inconclusive     the records do not allow a verdict (a transcript is missing or changed before the position, subagent launches without
                     their transcripts, no assistant message in the main session before the checkpoint, timestamps after the checkpoint)
Missing evidence is never read as proof of a single model.

Exit codes: 0 conforming / ok, 1 non_conforming, 2 usage or unreadable input, 3 inconclusive, 4 not ready (transcripts still moving).
A model switch after the checkpoint does not change the verdict; it is reported as the sequence observed. The tool names no cause for a
switch and does not rank the models. No effect on any score.
"""

import argparse
import datetime
import hashlib
import json
import os
import shutil
import stat
import sys
import time

TOOL_VERSION = "1"
AGENT_TOOLS = ("Agent", "Task")
IDLE_GAP_SECONDS = 300          # a gap longer than this between two events is not counted as the agent working
CLOCK_TOLERANCE_SECONDS = 5


# ------------------------------------------------------------------ small helpers

def sha256_bytes(data):
    return hashlib.sha256(data).hexdigest()


def parse_ts(text):
    t = datetime.datetime.fromisoformat(str(text).replace("Z", "+00:00"))
    return t if t.tzinfo else t.replace(tzinfo=datetime.timezone.utc)


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)


def role_of(path):
    return "subagent" if "subagents" in os.path.abspath(path).split(os.sep) else "main"


def find_transcripts(paths):
    found = {}
    for p in paths:
        if os.path.isdir(p):
            for base, _, names in os.walk(p):
                for n in names:
                    if n.endswith(".jsonl"):
                        full = os.path.join(base, n)
                        found[os.path.realpath(full)] = full
        elif os.path.isfile(p):
            found[os.path.realpath(p)] = p
        else:
            raise OSError("not found: %s" % p)
    return [found[k] for k in sorted(found)]


def split_lines(data):
    """The lines of a JSON Lines file, split on b"\\n" only. str.splitlines() would also split inside a JSON string that holds U+2028 or
    similar, and that would move the position of the checkpoint."""
    lines = data.split(b"\n")
    if lines and lines[-1] == b"":
        lines.pop()
    return lines


def events_of(data):
    """(line number, event) for every readable JSON object line. Line numbers count every line, readable or not."""
    for number, line in enumerate(split_lines(data), 1):
        try:
            e = json.loads(line.decode("utf-8", "replace"))
        except ValueError:
            continue
        if isinstance(e, dict):
            yield number, e


def line_count(data):
    return len(split_lines(data))


def answers_of(data, first_line=1):
    """The assistant messages that name a model, from `first_line` on: line, timestamp, model, tool calls, stop reason, usage."""
    out = []
    for number, e in events_of(data):
        if number < first_line or e.get("type") != "assistant" or not e.get("timestamp"):
            continue
        msg = e.get("message") or {}
        model = msg.get("model") or ""
        if not model or model == "<synthetic>":
            continue
        content = msg.get("content") if isinstance(msg.get("content"), list) else []
        tools = [c.get("name") for c in content if isinstance(c, dict) and c.get("type") == "tool_use"]
        out.append({"line": number, "at": e["timestamp"], "model": model, "tools": tools,
                    "stop": msg.get("stop_reason"), "usage": msg.get("usage") if isinstance(msg.get("usage"), dict) else {}})
    return out


def switches_in(answers, previous=None):
    found = []
    for a in answers:
        if previous is not None and a["model"] != previous:
            found.append({"line": a["line"], "at": a["at"], "from": previous, "to": a["model"]})
        previous = a["model"]
    return found


def model_table(answers):
    table = {}
    for a in answers:
        m = table.setdefault(a["model"], {"messages": 0, "tool_calls": 0, "first": a["at"], "last": a["at"],
                                          "first_line": a["line"], "last_line": a["line"]})
        m["messages"] += 1
        m["tool_calls"] += len(a["tools"])
        m["last"], m["last_line"] = a["at"], a["line"]
    return table


def is_operator_message(e):
    if e.get("type") != "user":
        return False
    content = (e.get("message") or {}).get("content")
    if isinstance(content, str):
        return True
    if isinstance(content, list):
        kinds = [c.get("type") for c in content if isinstance(c, dict)]
        return "text" in kinds and "tool_result" not in kinds
    return False


def describe(path):
    """Everything the checkpoint keeps about one transcript, as it is now."""
    with open(path, "rb") as f:
        data = f.read()
    answers = answers_of(data)
    stamps = []
    operator = 0
    for _, e in events_of(data):
        if e.get("timestamp"):
            try:
                stamps.append(parse_ts(e["timestamp"]))
            except ValueError:
                pass
        if is_operator_message(e):
            operator += 1
    active = 0.0
    for earlier, later in zip(stamps, stamps[1:]):
        gap = (later - earlier).total_seconds()
        if 0 < gap <= IDLE_GAP_SECONDS:
            active += gap
    usage = {"input_tokens": 0, "output_tokens": 0, "cache_read_input_tokens": 0, "cache_creation_input_tokens": 0}
    credit = 0
    for a in answers:
        for k in usage:
            v = a["usage"].get(k)
            usage[k] += v if isinstance(v, int) else 0
        if a["usage"].get("fallback_credit") is not None:
            credit += 1
    role = role_of(path)
    return {
        "name": os.path.basename(path), "role": role, "bytes": len(data), "lines": line_count(data), "prefix_sha256": sha256_bytes(data),
        "first_timestamp": stamps[0].isoformat() if stamps else None, "last_timestamp": stamps[-1].isoformat() if stamps else None,
        "models": model_table(answers), "last_model": answers[-1]["model"] if answers else None,
        "switches": switches_in(answers), "assistant_messages": len(answers), "tool_calls": sum(len(a["tools"]) for a in answers),
        "agent_launches": sum(1 for a in answers for t in a["tools"] if t in AGENT_TOOLS) if role == "main" else 0,
        "operator_messages": operator if role == "main" else 0, "last_stop_reason": answers[-1]["stop"] if answers else None,
        "usage": usage, "usage_fallback_credit_nonnull": credit,
        "wall_seconds": (stamps[-1] - stamps[0]).total_seconds() if len(stamps) > 1 else 0,
        "active_seconds": round(active),
    }


def budget_of(descs, cost_usd, cost_kind):
    main = [d for d in descs if d["role"] == "main"]
    total_usage = {k: sum(d["usage"][k] for d in descs) for k in ("input_tokens", "output_tokens", "cache_read_input_tokens", "cache_creation_input_tokens")}
    return {
        "assistant_messages": sum(d["assistant_messages"] for d in descs), "tool_calls": sum(d["tool_calls"] for d in descs),
        "operator_messages": sum(d["operator_messages"] for d in main), "tokens": total_usage,
        "wall_seconds": max([d["wall_seconds"] for d in main] or [0]), "active_seconds": sum(d["active_seconds"] for d in descs),
        "active_seconds_method": "sum of the gaps between consecutive events that are at most %d s, over every transcript" % IDLE_GAP_SECONDS,
        "cost_usd": cost_usd, "cost_kind": cost_kind if cost_usd is not None else "not_recorded",
    }


# ------------------------------------------------------------------ delivery snapshot

def tree_manifest(root):
    entries = []
    for base, dirs, names in os.walk(root):
        dirs.sort()
        for n in sorted(names):
            full = os.path.join(base, n)
            rel = os.path.relpath(full, root)
            if os.path.islink(full):
                entries.append({"path": rel, "link": os.readlink(full)})
            else:
                with open(full, "rb") as f:
                    entries.append({"path": rel, "sha256": sha256_bytes(f.read()), "bytes": os.path.getsize(full)})
    digest = hashlib.sha256()
    for e in entries:
        digest.update(("%s\0%s\n" % (e["path"], e.get("sha256") or "->" + e["link"])).encode("utf-8"))
    return {"files": entries, "tree_sha256": digest.hexdigest()}


def make_read_only(root):
    for base, dirs, names in os.walk(root, topdown=False):
        for n in names:
            full = os.path.join(base, n)
            if not os.path.islink(full):
                mode = os.stat(full).st_mode
                os.chmod(full, (mode & ~(stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH)))
        os.chmod(base, os.stat(base).st_mode & ~(stat.S_IWUSR | stat.S_IWGRP | stat.S_IWOTH))


def keep_snapshot(src, dest):
    if os.path.exists(dest):
        raise FileExistsError("refusing to overwrite %s" % dest)
    shutil.copytree(src, dest, symlinks=True)
    manifest = tree_manifest(dest)
    make_read_only(dest)
    return manifest


# ------------------------------------------------------------------ readiness and verdict

def not_ready(paths, descs, quiet_seconds):
    problems = []
    now = time.time()
    for p in paths:
        age = now - os.path.getmtime(p)
        if age < quiet_seconds:
            problems.append("%s was written %.0f s ago (wait for at least %d s of silence)" % (os.path.basename(p), age, quiet_seconds))
    mains = [d for d in descs if d["role"] == "main" and d["assistant_messages"]]
    for d in mains:
        if d["last_stop_reason"] != "end_turn":
            problems.append("%s: the last assistant message ended with %r, not 'end_turn': the main session is still working" % (d["name"], d["last_stop_reason"]))
    return problems


def evaluate(record, paths):
    """The conformity of a run, from the checkpoint record and the transcripts as they are now."""
    requested = record["requested_model"]
    non, inc = [], []
    current = {os.path.basename(p): p for p in paths}
    models_before, models_after = set(), set()
    switches_before, switches_after = [], []
    recorded_names = set()
    checkpoint_at = parse_ts(record["utc"])

    for r in record["transcripts"]:
        recorded_names.add(r["name"])
        models_before |= set(r["models"])
        for s in r["switches"]:
            switches_before.append(dict(s, file=r["name"], role=r["role"]))
        if r["last_timestamp"] and parse_ts(r["last_timestamp"]) > checkpoint_at + datetime.timedelta(seconds=CLOCK_TOLERANCE_SECONDS):
            inc.append("%s: its last event before the recorded position is later than the checkpoint instant" % r["name"])
        path = current.get(r["name"])
        if path is None:
            inc.append("%s was recorded at the checkpoint and is not among the transcripts given" % r["name"])
            continue
        with open(path, "rb") as f:
            data = f.read()
        if len(data) < r["bytes"] or sha256_bytes(data[:r["bytes"]]) != r["prefix_sha256"]:
            inc.append("%s is not the transcript recorded at the checkpoint: its first %d bytes changed" % (r["name"], r["bytes"]))
            continue
        after = answers_of(data, first_line=r["lines"] + 1)
        models_after |= {a["model"] for a in after}
        for s in switches_in(after, previous=r["last_model"]):
            switches_after.append(dict(s, file=r["name"], role=r["role"]))

    for name, path in current.items():          # transcripts that did not exist at the checkpoint
        if name in recorded_names:
            continue
        with open(path, "rb") as f:
            after = answers_of(f.read())
        models_after |= {a["model"] for a in after}
        for s in switches_in(after):
            switches_after.append(dict(s, file=name, role=role_of(path)))

    mains = [r for r in record["transcripts"] if r["role"] == "main"]
    if not mains:
        inc.append("no main transcript was recorded at the checkpoint")
    elif not any(r["models"] for r in mains):
        inc.append("the main session has no assistant message that names a model before the checkpoint")
    if record["subagent_launches_main"] > record["subagent_files"]:
        inc.append("the main session launched %d subagent(s) before the checkpoint but only %d subagent transcript(s) were recorded"
                   % (record["subagent_launches_main"], record["subagent_files"]))
    if switches_before:
        non.append("the model changed %d time(s) before the checkpoint" % len(switches_before))
    wrong = sorted(m for m in models_before if m != requested)
    if wrong:
        non.append("before the checkpoint a model other than the requested one answered: %s" % ", ".join(wrong))

    verdict = "non_conforming" if non else ("inconclusive" if inc else "conforming")
    fallback_observed = bool(switches_after) or any(m != requested for m in models_after)
    return {"verdict": verdict, "reasons_non_conforming": non, "reasons_inconclusive": inc, "requested_model": requested,
            "models_before_checkpoint": sorted(models_before), "models_after_checkpoint": sorted(models_after),
            "switches_before_checkpoint": switches_before, "switches_after_checkpoint": switches_after,
            "fallback_observed": fallback_observed}


def attribution(ev, files_examined):
    """The sentence a result must carry. It states the observed sequence and nothing about the cause."""
    if ev["verdict"] == "non_conforming":
        return ("NOT CONFORMING to the staged protocol: " + "; ".join(ev["reasons_non_conforming"]) +
                ". The run is recorded with its artifacts, cost and reason, and counts in the statistics.")
    prefix = "INCONCLUSIVE records (" + "; ".join(ev["reasons_inconclusive"]) + "). " if ev["verdict"] == "inconclusive" else ""
    if ev["fallback_observed"]:
        steps = "; ".join("%s -> %s at line %d of %s (%s, %s)" % (s["from"], s["to"], s["line"], s["file"], s["role"], s["at"])
                          for s in ev["switches_after_checkpoint"]) or "models other than the requested one answered after the checkpoint"
        return (prefix + "Run of the agent or product with fallback. The requested model was %s; it answered the whole of stage 1. After the "
                "checkpoint the sequence observed was: %s. The result is not the work of the initial model alone." % (ev["requested_model"], steps))
    return (prefix + "No model switch was observed in the %d transcript file(s) examined. This is an observation over those records, "
            "not a proof that a single model did all the work." % files_examined)


# ------------------------------------------------------------------ commands

def load_checkpoint(out):
    path = os.path.join(out, "checkpoint.json")
    if not os.path.isfile(path):
        raise OSError("no checkpoint.json in %s" % out)
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def write_json(path, data, read_only=False):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
        f.write("\n")
    if read_only:
        os.chmod(path, 0o444)


def stage_record(a, paths, descs, stage):
    launches = sum(d["agent_launches"] for d in descs)
    return {
        "tool_version": TOOL_VERSION, "task_version": a.task_version, "stage": stage, "utc": utc_now().isoformat(),
        "requested_model": a.requested_model, "fallback": {"declared": a.fallback, "note": "declared by the operator; the tool does not read the client's settings"},
        "client": a.client, "transcripts": descs, "subagent_launches_main": launches,
        "subagent_files": sum(1 for d in descs if d["role"] == "subagent"), "budget": budget_of(descs, a.cost_usd, a.cost_kind),
    }


def prepare(a, out_must_have_checkpoint):
    out = os.path.abspath(a.out)
    entrega = os.path.abspath(a.entrega)
    if not os.path.isdir(entrega):
        raise OSError("not a directory: %s" % entrega)
    paths = find_transcripts(a.transcript)
    if not paths:
        raise OSError("no transcript (*.jsonl) found")
    if out_must_have_checkpoint:
        load_checkpoint(out)
    descs = [describe(p) for p in paths]
    return out, entrega, paths, descs


def cmd_checkpoint(a):
    out, entrega, paths, descs = prepare(a, False)
    if os.path.exists(os.path.join(out, "checkpoint.json")):
        raise FileExistsError("refusing to overwrite %s" % os.path.join(out, "checkpoint.json"))
    problems = not_ready(paths, descs, a.quiet_seconds)
    if problems:
        print("NOT READY: nothing was kept. Wait for stage 1 to end, then run it again.\n  - " + "\n  - ".join(problems), file=sys.stderr)
        return 4
    os.makedirs(out, exist_ok=True)
    record = stage_record(a, paths, descs, 1)
    record["snapshot"] = dict(keep_snapshot(entrega, os.path.join(out, "etapa1")), directory="etapa1")
    write_json(os.path.join(out, "checkpoint.json"), record, read_only=True)
    print("checkpoint kept at %s (tree %s...)\nsend the second message now, not before." % (record["utc"], record["snapshot"]["tree_sha256"][:16]))
    return 0


def cmd_finalize(a):
    out, entrega, paths, descs = prepare(a, True)
    if os.path.exists(os.path.join(out, "final.json")):
        raise FileExistsError("refusing to overwrite %s" % os.path.join(out, "final.json"))
    problems = not_ready(paths, descs, a.quiet_seconds)
    if problems:
        print("NOT READY: nothing was kept. Wait for stage 2 to end, then run it again.\n  - " + "\n  - ".join(problems), file=sys.stderr)
        return 4
    record = load_checkpoint(out)
    ev = evaluate(record, paths)
    final = stage_record(a, paths, descs, 2)
    final["snapshot"] = dict(keep_snapshot(entrega, os.path.join(out, "etapa2")), directory="etapa2")
    write_json(os.path.join(out, "final.json"), final, read_only=True)
    first, last = record["budget"], final["budget"]
    spent = {k: last[k] - first[k] for k in ("assistant_messages", "tool_calls", "operator_messages", "active_seconds")}
    spent["cost_usd"] = round(last["cost_usd"] - first["cost_usd"], 4) if last["cost_usd"] is not None and first["cost_usd"] is not None else None
    summary = {"tool_version": TOOL_VERSION, "task_version": record["task_version"], "conformity": ev,
               "attribution": attribution(ev, len(paths)), "fallback": final["fallback"], "client": final["client"],
               "budget": {"shared_pool": True, "at_checkpoint": first, "at_end": last, "stage_2_use": spent},
               "snapshots": {"stage_1": record["snapshot"]["tree_sha256"], "final": final["snapshot"]["tree_sha256"]}}
    write_json(os.path.join(out, "etapas.json"), summary)
    sys.stdout.write(render(ev, summary["attribution"]))
    return {"conforming": 0, "non_conforming": 1, "inconclusive": 3}[ev["verdict"]]


def cmd_check(a):
    out = os.path.abspath(a.out)
    record = load_checkpoint(out)
    paths = find_transcripts(a.transcript)
    ev = evaluate(record, paths)
    if a.json:
        sys.stdout.write(json.dumps(dict(ev, attribution=attribution(ev, len(paths))), ensure_ascii=False, indent=2) + "\n")
    else:
        sys.stdout.write(render(ev, attribution(ev, len(paths))))
    return {"conforming": 0, "non_conforming": 1, "inconclusive": 3}[ev["verdict"]]


def render(ev, text):
    out = ["conformity: %s" % ev["verdict"], "requested model: %s" % ev["requested_model"],
           "models before the checkpoint: %s" % (", ".join(ev["models_before_checkpoint"]) or "none"),
           "models after the checkpoint:  %s" % (", ".join(ev["models_after_checkpoint"]) or "none")]
    for s in ev["switches_before_checkpoint"]:
        out.append("  switch BEFORE the checkpoint: %s -> %s at line %d of %s (%s)" % (s["from"], s["to"], s["line"], s["file"], s["at"]))
    for s in ev["switches_after_checkpoint"]:
        out.append("  switch after the checkpoint:  %s -> %s at line %d of %s (%s)" % (s["from"], s["to"], s["line"], s["file"], s["at"]))
    for r in ev["reasons_non_conforming"]:
        out.append("  not conforming: " + r)
    for r in ev["reasons_inconclusive"]:
        out.append("  inconclusive: " + r)
    out.append("attribution: " + text)
    return "\n".join(out) + "\n"


def cmd_perfis(a):
    def load(path):
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    first, last = load(a.checkpoint), load(a.final)
    rows = []
    for cat in sorted(set(first.get("categories", {})) | set(last.get("categories", {}))):
        x, y = first.get("categories", {}).get(cat), last.get("categories", {}).get(cat)
        rows.append({"category": cat, "max": (y or x).get("max"), "checkpoint": x.get("score") if x else None,
                     "final": y.get("score") if y else None,
                     "difference": (y["score"] - x["score"]) if x and y else None})
    report = {"official": {"profile": "final", "total": last.get("total"), "grade": last.get("grade")},
              "informative": {"profile": "checkpoint (stage 1)", "total": first.get("total"),
                              "note": "informative only; it is not an official score and is not added to anything"},
              "categories": rows}
    if a.json:
        sys.stdout.write(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
        return 0
    w = sys.stdout.write
    w("%-6s %6s %12s %8s %12s\n" % ("", "max", "checkpoint", "final", "difference"))
    for r in rows:
        cells = ["-" if r[k] is None else str(r[k]) for k in ("checkpoint", "final", "difference")]
        w("%-6s %6s %12s %8s %12s\n" % (r["category"], r["max"], *cells))
    w("official total (final delivery):       %s  %s\n" % (last.get("total"), last.get("grade") or ""))
    w("informative total (checkpoint only):   %s  (not an official score, not combined with anything)\n" % first.get("total"))
    return 0


def main():
    ap = argparse.ArgumentParser(description="Evidence for a run in two stages (task 1.2.0)")
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p, with_stage_options):
        p.add_argument("--transcript", nargs="+", required=True, metavar="PATH", help="transcript files, or directories searched for *.jsonl (subagents included)")
        p.add_argument("--out", required=True, metavar="DIR", help="where the evidence of the run is kept")
        if with_stage_options:
            p.add_argument("--entrega", required=True, metavar="DIR", help="the delivery as the candidate left it")
            p.add_argument("--requested-model", required=True, metavar="ID", help="the model id exactly as it appears in the transcripts")
            p.add_argument("--fallback", choices=("on", "off", "unknown"), default="unknown", help="the client's fallback setting, as declared by the operator")
            p.add_argument("--client", default=None, metavar="TEXT", help="client and version, for the record")
            p.add_argument("--task-version", default="1.2.0")
            p.add_argument("--cost-usd", type=float, default=None, help="cost so far in US$, from the client's own summary")
            p.add_argument("--cost-kind", choices=("measured", "estimated"), default="measured", help="how the cost was obtained")
            p.add_argument("--quiet-seconds", type=int, default=30, help="refuse while a transcript was written less than this long ago")

    p = sub.add_parser("checkpoint"); common(p, True)
    p = sub.add_parser("finalize"); common(p, True)
    p = sub.add_parser("check"); common(p, False); p.add_argument("--json", action="store_true")
    p = sub.add_parser("perfis")
    p.add_argument("--checkpoint", required=True, metavar="SCORECARD"); p.add_argument("--final", required=True, metavar="SCORECARD")
    p.add_argument("--json", action="store_true")

    a = ap.parse_args()
    handler = {"checkpoint": cmd_checkpoint, "finalize": cmd_finalize, "check": cmd_check, "perfis": cmd_perfis}[a.cmd]
    try:
        sys.exit(handler(a))
    except (OSError, ValueError, KeyError) as e:
        print("[etapas] %s" % e, file=sys.stderr)
        sys.exit(2)


if __name__ == "__main__":
    main()
