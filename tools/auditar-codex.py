#!/usr/bin/env python3
"""auditar-codex.py — audit one attempt's Codex CLI session: who answered, how the permissions were set, what ended it, and the outcome.
Read-only: it prints a report and writes nothing. The Codex twin of tools/auditar-tentativa.py (Claude Code), with the same outcomes.

Codex keeps one rollout log per thread under ~/.codex/sessions/<year>/<month>/<day>/rollout-*.jsonl. The main thread is the one no other thread
spawned; a thread the agent spawned says so in its session_meta (`source.subagent`), and it is the agent's work, so its model is audited too.
Each turn writes a `turn_context` event with the model, the effort, the approval policy and the sandbox it ran under, and `task_started` /
`task_complete` events around it. This reads those and reports:

- the model and effort of every turn, per thread, and the permission mode (approval policy and sandbox) of every turn;
- the operator's activity: the user messages of the main thread (compared with the declared text), the interrupted turns;
- every error, every aborted turn, every model reroute and every safeguard event the log writes, by type;
- the tokens of each thread (the last `token_count` event) and how the session ended.

The outcome is the first of these that applies:

  operator error    the procedure was not the declared one: a turn on a model other than the requested one in the main thread at the start,
                    a turn at an effort other than the declared one (--effort), permissions other than the declared ones
                    (--permissions), more user messages than declared or a message that differs from the declared text, an interrupted turn
  switched          a model outside the allowed set answered a turn, in the main thread or in a thread it spawned, or the log says the
                    client rerouted a request to another model
  lab failure       the session ended on an error, or the lab stopped the client for a reason of its own (--stopped-by lab)
  blocked           the main thread ended on a safeguard event and no turn followed
  budget exhausted  the lab stopped the client when the declared budget ran out (--stopped-by budget)
  inconclusive      no turn at all, or the session was cut mid-work and the lab gave no reason
  completed         the main thread's last turn completed (a `task_complete` with no `error`) and nothing above applies

    python3 tools/auditar-codex.py SESSIONS --requested gpt-6.1-sol --effort xhigh --permissions bypass --message msg.txt --json

SESSIONS is the main rollout log, or a folder (the runner's ~/.codex, its sessions folder, or a copy) holding the rollout logs of exactly one
session. A model matches an allowed id exactly, or as that id plus a date suffix; never a longer version.

Exit 0: completed. Exit 1: any other outcome. Exit 2: inconclusive, or a path or an argument could not be used.

What it does not know, and says so: the shape of an error, a reroute or a safeguard event has not been seen in a real log of this lab yet, so
those are recognized by their type name (`error`, `stream_error`, `turn_aborted`, a name with `reroute`, `refus` or `safety` in it) and listed
with the type they were written under; anything else the client writes is counted as `other events` and never changes the outcome. The log
has no cost and no model time: only tokens. No effect on any score. Standard library only.
"""

import argparse
import datetime
import glob
import hashlib
import json
import os
import re
import sys

OUTCOMES = ("operator error", "switched", "lab failure", "blocked", "budget exhausted", "inconclusive")
KNOWN_EVENTS = {
    "task_started", "task_complete", "token_count", "item_completed", "thread_settings_applied", "user_message", "agent_message",
    "agent_reasoning", "agent_reasoning_raw_content", "exec_command_begin", "exec_command_output_delta", "exec_command_end",
    "patch_apply_begin", "patch_apply_end", "turn_diff", "web_search_begin", "web_search_end", "mcp_tool_call_begin", "mcp_tool_call_end",
    "context_compacted", "plan_update", "background_event", "shutdown_complete", "session_configured",
}
ERROR_EVENTS = ("error", "stream_error")
ABORT_EVENTS = ("turn_aborted",)
KEYWORDS = re.compile(r"reroute|refus|safety", re.I)
PERMISSIONS = {
    "bypass": ("never", "danger-full-access"),
    "workspace": ("never", "workspace-write"),
}


class Unusable(Exception):
    """The record cannot be audited as given."""


def allowed(model, allow):
    return any(model == a or re.fullmatch(re.escape(a) + r"-\d{8}", model) for a in allow)


def parse_time(text):
    return datetime.datetime.fromisoformat(text.replace("Z", "+00:00"))


def normalized(text):
    return re.sub(r"\s+", " ", text or "").strip()


def read_events(path):
    out = []
    with open(path, encoding="utf-8") as f:
        for n, line in enumerate(f, 1):
            line = line.strip()
            if not line.startswith("{"):
                continue
            try:
                out.append(json.loads(line))
            except ValueError:
                raise Unusable("%s line %d is not JSON" % (path, n))
    return out


def resolve(path):
    """The rollout logs of one session: a list of files."""
    if os.path.isfile(path):
        base = os.path.dirname(path)
        files = sorted(glob.glob(os.path.join(base, "**", "rollout-*.jsonl"), recursive=True)) or [path]
        main_id = session_id(path)
        chosen = [f for f in files if f == path or belongs(f, main_id)]
        return chosen or [path]
    files = sorted(glob.glob(os.path.join(path, "**", "rollout-*.jsonl"), recursive=True))
    if not files:
        raise Unusable("no rollout log under %s" % path)
    return files


def session_id(path):
    for e in read_events(path):
        if e.get("type") == "session_meta":
            return (e.get("payload") or {}).get("session_id") or (e.get("payload") or {}).get("id")
    return None


def belongs(path, main_id):
    for e in read_events(path):
        if e.get("type") == "session_meta":
            p = e.get("payload") or {}
            src = p.get("source")
            if p.get("session_id") == main_id or p.get("id") == main_id:
                return True
            if isinstance(src, dict) and "subagent" in src:
                parent = json.dumps(src)
                return main_id is not None and main_id in parent
            return False
    return False


def thread_of(path):
    events = read_events(path)
    meta = next((e.get("payload") or {} for e in events if e.get("type") == "session_meta"), None)
    if meta is None:
        raise Unusable("%s has no session_meta" % path)
    src = meta.get("source")
    sub = isinstance(src, dict) and "subagent" in src
    t = {"file": os.path.basename(path), "id": meta.get("id") or meta.get("session_id"), "subagent": sub, "client": meta.get("cli_version"),
         "originator": meta.get("originator"), "turns": [], "users": [], "errors": [], "aborts": [], "flags": [], "other": {}, "tokens": None,
         "started": None, "ended": None, "last_event": None, "completed": 0, "started_turns": 0}
    for e in events:
        kind, p, ts = e.get("type"), e.get("payload") or {}, e.get("timestamp")
        if ts:
            t["started"] = t["started"] or ts
            t["ended"] = ts
        t["last_event"] = (kind, p.get("type"))
        if kind == "turn_context":
            t["turns"].append({"model": p.get("model"), "effort": p.get("effort"), "approval": p.get("approval_policy"),
                               "sandbox": (p.get("sandbox_policy") or {}).get("type"), "at": ts})
        elif kind == "event_msg":
            et = p.get("type") or ""
            if et == "token_count":
                info = p.get("info") or {}
                if info.get("total_token_usage"):
                    t["tokens"] = info["total_token_usage"]
            elif et == "task_started":
                t["started_turns"] += 1
                t["last_turn_error"] = None
            elif et == "task_complete":
                t["completed"] += 1
                # A turn the client could not run still ends in a task_complete, with `last_agent_message` null and an `error` (seen on 2026-10-16: the API refused the
                # model, "not supported when using Codex with a ChatGPT account"): it is a session that ended on an error, not one that completed.
                if p.get("error"):
                    err = p["error"]
                    msg = (err.get("message") if isinstance(err, dict) else str(err)) or ""
                    t["last_turn_error"] = {"type": "task_complete", "at": ts, "message": str(msg)[:200]}
                    t["errors"].append(t["last_turn_error"])
                else:
                    t["last_turn_error"] = None
            elif et == "item_completed":
                item = p.get("item") or {}
                if item.get("type") == "UserMessage":
                    text = "".join(c.get("text", "") for c in item.get("content", []) if isinstance(c, dict) and c.get("type") in ("Text", "text", "input_text"))
                    t["users"].append(text)
            elif et == "user_message":
                t["users"].append(p.get("message") or "")
            if et in ERROR_EVENTS:
                t["errors"].append({"type": et, "at": ts, "message": str(p.get("message") or p.get("error") or "")[:200]})
            elif et in ABORT_EVENTS:
                t["aborts"].append({"type": et, "at": ts, "reason": p.get("reason")})
            elif KEYWORDS.search(et):
                t["flags"].append({"type": et, "at": ts, "detail": json.dumps(p, ensure_ascii=False)[:200]})
            elif et not in KNOWN_EVENTS:
                t["other"][et] = t["other"].get(et, 0) + 1
    return t


def seconds_between(start, end):
    return round((parse_time(end) - parse_time(start)).total_seconds(), 1)


def audit(sessions, requested, allow_more=(), messages=(), effort=None, permissions=None, stopped_by=None):
    files = resolve(sessions)
    threads = [thread_of(f) for f in files]
    mains = [t for t in threads if not t["subagent"]]
    if len(mains) != 1:
        raise Unusable("expected one main rollout log, found %d of %d" % (len(mains), len(threads)))
    main = mains[0]
    allow = [requested] + list(allow_more)
    declared = [normalized(m) for m in messages]
    findings = []

    def find(outcome, detail):
        findings.append((outcome, detail))

    models = {}
    for t in threads:
        for turn in t["turns"]:
            key = (t["file"], turn["model"])
            models[key] = models.get(key, 0) + 1
    if not main["turns"]:
        find("inconclusive", "the main thread wrote no turn")
    else:
        if main["turns"][0]["model"] != requested and not allowed(main["turns"][0]["model"] or "", allow):
            find("operator error", "the session started on %s, not on the requested %s" % (main["turns"][0]["model"], requested))
        if effort:
            wrong = sorted({t["effort"] for t in main["turns"] if t["effort"] != effort})
            if wrong:
                find("operator error", "turns at effort %s, not the declared %s" % (", ".join(map(str, wrong)), effort))
        if permissions:
            want = PERMISSIONS[permissions]
            wrong = sorted({"%s/%s" % (t["approval"], t["sandbox"]) for t in main["turns"] if (t["approval"], t["sandbox"]) != want})
            if wrong:
                find("operator error", "permissions %s, not the declared %s (%s/%s)" % (", ".join(wrong), permissions, want[0], want[1]))
    users = [normalized(u) for u in main["users"]]
    if declared and len(users) > len(declared):
        find("operator error", "%d user messages, %d declared" % (len(users), len(declared)))
    elif declared:
        for i, text in enumerate(users):
            if text != declared[i]:
                find("operator error", "user message %d differs from the declared text" % (i + 1))
    if main["aborts"]:
        find("operator error", "%d interrupted or aborted turn(s)" % len(main["aborts"]))
    for t in threads:
        out = sorted({turn["model"] for turn in t["turns"] if not allowed(turn["model"] or "", allow)})
        if out:
            find("switched", "%s answered in %s" % (", ".join(map(str, out)), "a spawned thread" if t["subagent"] else "the main thread"))
    if any("reroute" in f["type"] for t in threads for f in t["flags"]):
        find("switched", "the client rerouted a request to another model")
    last = main["last_event"]
    ended_on_error = bool(main["errors"]) and (last is not None and (last[1] in ERROR_EVENTS or main["completed"] < main["started_turns"] or bool(main.get("last_turn_error"))))
    if stopped_by == "lab":
        find("lab failure", "the lab stopped the client for a reason of its own")
    elif ended_on_error:
        find("lab failure", "the session ended on an error: %s" % main["errors"][-1]["message"])
    safeguard = [f for f in main["flags"] if not f["type"].startswith("reroute") and "reroute" not in f["type"]]
    if safeguard and main["completed"] < main["started_turns"] and not stopped_by:
        find("blocked", "the main thread ended on a safeguard event (%s)" % safeguard[-1]["type"])
    if stopped_by == "budget":
        find("budget exhausted", "the lab stopped the client when the budget ran out")
    cut = main["turns"] and main["completed"] < main["started_turns"] and not stopped_by and not main["errors"]
    if cut:
        find("inconclusive", "the session was cut mid-work and the lab gave no reason")
    outcome, detail = next(((o, d) for o in OUTCOMES for (oo, d) in findings if oo == o), ("completed", "the main thread's last turn completed"))
    tokens = {t["file"]: t["tokens"] for t in threads}
    total = {k: sum((t["tokens"] or {}).get(k, 0) for t in threads) for k in ("input_tokens", "cached_input_tokens", "output_tokens", "reasoning_output_tokens", "total_tokens")}
    return {
        "client": "codex", "client_version": main["client"], "requested": requested, "allowed": allow, "effort": effort, "permissions": permissions,
        "outcome": outcome, "detail": detail, "findings": [{"outcome": o, "detail": d} for o, d in findings],
        "threads": [{"file": t["file"], "subagent": t["subagent"], "turns": len(t["turns"]), "models": sorted({x["model"] for x in t["turns"] if x["model"]}),
                     "efforts": sorted({x["effort"] for x in t["turns"] if x["effort"]}),
                     "permissions": sorted({"%s/%s" % (x["approval"], x["sandbox"]) for x in t["turns"]}), "tokens": t["tokens"],
                     "errors": t["errors"], "aborts": t["aborts"], "flags": t["flags"], "other_events": t["other"]} for t in threads],
        "turns_by_model": {"%s: %s" % k: v for k, v in sorted(models.items(), key=lambda kv: str(kv[0]))},
        "operator": {"user_messages": len(users), "declared": len(declared), "interruptions": len(main["aborts"])},
        "tokens": total,
        "started": main["started"], "ended": main["ended"],
        "span_seconds": seconds_between(main["started"], main["ended"]) if main["started"] and main["ended"] else None,
    }


def render(rep):
    out = []
    w = out.append
    w("client     codex %s" % rep["client_version"])
    w("requested  %s at effort %s   permissions: %s   allowed: %s" % (rep["requested"], rep["effort"] or "(not declared)", rep["permissions"] or "(not declared)", ", ".join(rep["allowed"])))
    w("operator   %d user message(s), %d declared; interruptions: %d" % (rep["operator"]["user_messages"], rep["operator"]["declared"], rep["operator"]["interruptions"]))
    for t in rep["threads"]:
        w("%s %s  %d turn(s)  models %s  effort %s  permissions %s  tokens %s" % ("spawned" if t["subagent"] else "main   ", t["file"][:40], t["turns"],
                                                                              ", ".join(t["models"]) or "-", ", ".join(t["efforts"]) or "-",
                                                                              ", ".join(t["permissions"]) or "-", (t["tokens"] or {}).get("total_tokens")))
        for e in t["errors"] + t["aborts"] + t["flags"]:
            w("    %s at %s %s" % (e["type"], e["at"], e.get("message") or e.get("reason") or e.get("detail") or ""))
        if t["other_events"]:
            w("    other events: %s" % ", ".join("%s x%d" % kv for kv in sorted(t["other_events"].items())))
    w("tokens     %s" % json.dumps(rep["tokens"]))
    w("session    %s .. %s (%s s)" % (rep["started"], rep["ended"], rep["span_seconds"]))
    for f in rep["findings"]:
        w("FINDING    %s: %s" % (f["outcome"], f["detail"]))
    w("OUTCOME    %s" % rep["outcome"].upper())
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("sessions", metavar="SESSIONS", help="the main rollout log, or a folder with the rollout logs of exactly one session")
    ap.add_argument("--requested", required=True, metavar="MODEL", help="the model id the attempt is filed under")
    ap.add_argument("--allow", action="append", default=[], metavar="MODEL", help="another model allowed to answer (repeatable)")
    ap.add_argument("--message", action="append", default=[], metavar="FILE", help="a file with the text of one declared operator message (repeatable, in order)")
    ap.add_argument("--effort", metavar="LEVEL", help="the effort every turn of the main thread must carry")
    ap.add_argument("--permissions", choices=sorted(PERMISSIONS), help="the permissions every turn must run under: bypass = approval never + danger-full-access")
    ap.add_argument("--stopped-by", choices=("budget", "lab"), help="the lab stopped the client: the budget ran out, or a lab reason")
    ap.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()
    try:
        messages = [open(m, encoding="utf-8").read() for m in a.message]
        rep = audit(a.sessions, a.requested, a.allow, messages, a.effort, a.permissions, a.stopped_by)
    except (Unusable, OSError) as e:
        print("auditar-codex: %s" % e, file=sys.stderr)
        sys.exit(2)
    print(json.dumps(rep, ensure_ascii=False, indent=2) if a.json else render(rep))
    sys.exit(0 if rep["outcome"] == "completed" else 2 if rep["outcome"] == "inconclusive" else 1)


if __name__ == "__main__":
    main()
