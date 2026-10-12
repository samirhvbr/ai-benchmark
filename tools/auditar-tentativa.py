#!/usr/bin/env python3
"""auditar-tentativa.py — audit one attempt's Claude Code session: who answered, every safeguard stop, and the outcome. Read-only: it
prints a report and writes nothing.

The next series files an attempt under the requested model only when everything in it, in the main conversation and in every
subagent, was written by that model or by a model the lab allows explicitly (docs/new-series-design.md §6 and §7). This reads one
session (the main transcript and the subagent transcripts under <session-id>/subagents/) and reports:

- each model that answered, per transcript, with its responses and tool calls. A response is one API message: the client writes each
  content block of a message on its own line, so lines are grouped by message id;
- every safeguard stop (a response that ended with stop_reason "refusal", or that the client's own note says was stopped), with its
  category, the number of responses before it and the tool calls the allowed models made after it on their own, in the same
  transcript and before the operator's next message; every safeguard notice and every model fallback event;
- the operator's activity (messages, slash commands, interruptions), the API errors the client wrote, the type of each subagent
  and the model the agent that launched it asked for, if any (from the <agent>.meta.json next to its transcript, or the Agent
  tool call), and how and when the session ended. A non-interactive session (claude -p) writes no turn records, so its length is
  the span from the first message to the end.
- why a subagent ran on a model outside the allowed set: the agent asked for it, the client changed it after a stop, or neither
  is in the record (unexplained). Any of the three is a switch: under the candidate profile none of them can happen.

The outcome is the first of these that applies:

  operator error    the procedure was not the declared one: the session started on a model not allowed, or the operator switched to
                    one; the client was asked for a model its managed settings restrict (it then answers with an allowed one and
                    says so in the transcript); responses at an effort other than the declared one (--effort); a slash command
                    not declared with --allow-command (one after the last response cannot touch the attempt and is only listed);
                    an interruption; more operator messages than declared, or a message that differs from the declared text
  switched          a model outside the allowed set answered, in the main conversation or in a subagent
  lab failure       the session ended on an API error, or the lab stopped the client for a reason of its own (--stopped-by lab)
  blocked           the main conversation hit a safeguard stop and the allowed models made no tool call after the last one
  budget exhausted  the lab stopped the client when the declared budget ran out (--stopped-by budget)
  inconclusive      no response at all; the session was cut mid-work and the lab gave no reason; or a response carries work of a
                    kind this audit does not know (a server-side tool, an advisor call), which may be another model's
  completed         the model ended the session by itself; stops it recovered from on its own (it went on calling tools, with no
                    substitute) are counted, not fatal

    python3 tools/auditar-tentativa.py SESSION --requested claude-fable-5-1
    python3 tools/auditar-tentativa.py SESSION --requested claude-fable-5-1 --allow claude-haiku-4-5 --message msg.txt --json

SESSION is the main transcript (<project>/<session-id>.jsonl), or a project folder holding exactly one session with an operator
message or a response (one where the operator only ran a command, a /login say, is not an attempt). A model matches an allowed id
exactly, or as that id plus a date suffix (claude-haiku-4-5 allows claude-haiku-4-5-20251001); it never matches a longer version
(claude-sonnet-5 does not allow claude-sonnet-5-5).

Exit 0: completed. Exit 1: any other outcome; the report says which and why. Exit 2: inconclusive, or a path or an argument could not
be used.

What it does not know: clients other than Claude Code; which files the agent wrote (the snapshot of the work folder is that record);
whether a budget ran out (the lab says so with --stopped-by). Messages the client marks `<synthetic>` are not responses; the ones that
carry an API error are reported as such. Stdlib only. No effect on any score.
"""

import argparse
import datetime
import hashlib
import json
import os
import re
import sys

OUTCOMES = ("operator error", "switched", "lab failure", "blocked", "budget exhausted", "inconclusive")
ENDED_BY_MODEL = ("end_turn", "stop_sequence")
NOT_OPERATOR = ("<local-command-stdout>", "<local-command-stderr>", "<local-command-caveat>", "<bash-stdout>", "<bash-stderr>",
                "<task-notification>", "<agent-message")
INTERRUPTION = "[Request interrupted by user"
# The note the client adds after a stopped response, in the main conversation and in subagents alike. In subagents the stopped
# response's own lines can lack stop_reason "refusal", and this note is then the only trace of the stop.
STOP_NOTE = "Your response above was stopped by a safety classifier"
KNOWN_BLOCKS = ("text", "thinking", "redacted_thinking", "tool_use")
# What the client writes when it is asked for a model its managed settings do not allow: it uses an allowed one instead, with no
# error (seen on a bench VM under the candidate profile, 2026-10-12).
RESTRICTED = re.compile(r'Model "(?P<asked>[^"]+)" is restricted by your organization.*?Using (?P<used>\S+?) instead', re.S)


class Unusable(Exception):
    """The record cannot be audited as given."""


def allowed(model, allow):
    return any(model == a or re.fullmatch(re.escape(a) + r"-\d{8}", model) for a in allow)


def parse_time(text):
    t = datetime.datetime.fromisoformat(text.replace("Z", "+00:00"))
    return t if t.tzinfo else t.replace(tzinfo=datetime.timezone.utc)


def seconds_between(start, end):
    try:
        return round((parse_time(end) - parse_time(start)).total_seconds(), 1)
    except (TypeError, ValueError, AttributeError):
        return None


def normalized(text):
    return " ".join(text.split())


def read_events(path):
    """(line number, entry) for every entry of a JSON Lines file, counting from 1. A damaged line is skipped."""
    events = []
    with open(path, encoding="utf-8") as f:
        for n, line in enumerate(f, 1):
            try:
                e = json.loads(line)
            except ValueError:
                continue
            if isinstance(e, dict):
                events.append((n, e))
    return events


def user_text(content):
    """The text of a user entry: a string, or the text blocks of a list. None when it carries a tool result."""
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        if any(isinstance(c, dict) and c.get("type") == "tool_result" for c in content):
            return None
        return "\n".join(c.get("text", "") for c in content if isinstance(c, dict) and c.get("type") == "text")
    return None


def first_text(message):
    content = message.get("content")
    if isinstance(content, str):
        return content
    for c in content if isinstance(content, list) else []:
        if isinstance(c, dict) and c.get("type") == "text":
            return c.get("text", "")
    return ""


def parse(path, main):
    """One transcript. Positions are line numbers, so "after" means later in the file."""
    t = {"responses": [], "api_errors": [], "notices": [], "fallbacks": [], "identities": [], "messages": [], "commands": [],
         "interruptions": [], "turns": [], "restrictions": [], "agent_calls": {}, "versions": set(), "sidechain": False,
         "last": None}
    by_id = {}
    for n, e in read_events(path):
        kind, ts = e.get("type"), e.get("timestamp")
        if isinstance(e.get("version"), str):
            t["versions"].add(e["version"])
        if e.get("isSidechain"):
            t["sidechain"] = True
        if kind == "assistant":
            m = e.get("message") if isinstance(e.get("message"), dict) else {}
            model = m.get("model") or ""
            if not model or model == "<synthetic>":
                if e.get("isApiErrorMessage"):
                    t["api_errors"].append({"at": ts, "line": n, "error": str(e.get("error") or ""), "text": first_text(m)[:160]})
                    t["last"] = ("api error", n)
                continue
            key = m.get("id") or e.get("requestId") or "line-%d" % n
            r = by_id.get(key)
            if r is None:
                r = by_id[key] = {"at": ts, "line": n, "end_line": n, "model": model, "tool_calls": 0, "stop_reason": None,
                                  "category": None, "stopped": False, "effort": e.get("effort"), "advisor": e.get("advisorModel"),
                                  "other_work": set()}
                t["responses"].append(r)
            r["end_line"] = n
            content = m.get("content") if isinstance(m.get("content"), list) else []
            r["tool_calls"] += sum(1 for c in content if isinstance(c, dict) and c.get("type") == "tool_use")
            # A subagent the agent launches may ask for its own model (the Agent tool's `model` parameter).
            for c in content:
                if isinstance(c, dict) and c.get("type") == "tool_use" and c.get("name") in ("Agent", "Task") and c.get("id"):
                    t["agent_calls"][c["id"]] = (c.get("input") or {}).get("model")
            # Work a response did besides its own generation (a server-side tool, an advisor call) shows as content blocks or
            # usage iterations of another kind. None was seen in the pilot's transcripts; any that appears is flagged.
            r["other_work"].update("block " + str(c.get("type")) for c in content
                                   if isinstance(c, dict) and c.get("type") not in KNOWN_BLOCKS)
            iterations = (m.get("usage") or {}).get("iterations")
            if isinstance(iterations, list):
                r["other_work"].update("iteration " + str(x.get("type")) for x in iterations
                                       if isinstance(x, dict) and x.get("type") != "message")
            if m.get("stop_reason"):
                r["stop_reason"] = m["stop_reason"]
            if m.get("stop_reason") == "refusal":
                r["stopped"] = True
                r["category"] = (m.get("stop_details") or {}).get("category") or r["category"]
            t["last"] = ("response", r)
        elif kind == "system":
            sub = e.get("subtype") or ""
            content = e.get("content") if isinstance(e.get("content"), str) else ""
            restricted = RESTRICTED.search(content)
            if sub == "model_refusal_fallback":
                t["fallbacks"].append({"at": ts, "line": n, "from": e.get("originalModel"), "to": e.get("fallbackModel"),
                                       "scope": e.get("scope"), "category": e.get("apiRefusalCategory")})
            elif restricted:
                t["restrictions"].append({"at": ts, "line": n, "asked": restricted.group("asked"), "used": restricted.group("used")})
            elif "refusal" in sub or "safeguard" in content.lower():
                t["notices"].append({"at": ts, "line": n, "subtype": sub, "text": content[:160]})
            elif sub == "turn_duration":
                t["turns"].append({"at": ts, "ms": e.get("durationMs") or 0})
        elif kind == "attachment":
            a = e.get("attachment") if isinstance(e.get("attachment"), dict) else {}
            if a.get("type") == "model":
                t["identities"].append({"at": ts, "line": n, "model": (a.get("identity") or {}).get("modelId")})
            elif (main and a.get("type") == "queued_command" and a.get("commandMode") == "prompt" and not a.get("isMeta")
                  and not e.get("isMeta") and (a.get("origin") or {}).get("kind") in (None, "user")):
                t["messages"].append({"at": ts, "line": n, "text": str(a.get("prompt") or ""), "queued": True})
                t["last"] = ("message", n)
        elif kind == "user":
            text = user_text((e.get("message") or {}).get("content"))
            if e.get("isMeta"):
                before = [r for r in t["responses"] if r["end_line"] < n]
                if text and text.lstrip().startswith(STOP_NOTE) and before:
                    max(before, key=lambda r: r["end_line"])["stopped"] = True
                continue
            if not main or e.get("isCompactSummary") or text is None:
                continue
            s = text.lstrip()
            if s.startswith("<command-name>"):
                name = re.search(r"<command-name>/?([^<]*)</command-name>", s)
                args = re.search(r"<command-args>([^<]*)</command-args>", s)
                t["commands"].append({"at": ts, "line": n, "name": name.group(1).strip() if name else "?",
                                      "args": args.group(1).strip()[:80] if args else ""})
            elif s.startswith("<bash-input>"):
                t["commands"].append({"at": ts, "line": n, "name": "!", "args": re.sub(r"</?bash-input>", "", s).strip()[:80]})
            elif s.startswith(NOT_OPERATOR):
                continue
            elif s.startswith(INTERRUPTION):
                t["interruptions"].append({"at": ts, "line": n})
                t["last"] = ("interrupted", n)
            else:
                t["messages"].append({"at": ts, "line": n, "text": text, "queued": False})
                t["last"] = ("message", n)
    return t


def is_attempt(path):
    """A session with an operator message or a response. One where the operator only ran a command (a /login) is not one."""
    t = parse(path, True)
    return bool(t["messages"] or t["responses"] or t["api_errors"])


def resolve(path):
    """The main transcript of the session, and its subagent transcripts."""
    if os.path.isdir(path):
        mains = [os.path.join(path, n) for n in sorted(os.listdir(path)) if n.endswith(".jsonl")]
        mains = [p for p in mains if os.path.isfile(p) and is_attempt(p)]
        if not mains:
            raise Unusable("no session transcript in %s" % path)
        if len(mains) > 1:
            raise Unusable("%d session transcripts in %s; pass the attempt's main transcript" % (len(mains), path))
        path = mains[0]
    elif not os.path.isfile(path):
        raise OSError("not found: %s" % path)
    root = os.path.join(os.path.dirname(path), os.path.splitext(os.path.basename(path))[0], "subagents")
    subs = []
    for base, _, names in os.walk(root):
        subs += [os.path.join(base, n) for n in names if n.endswith(".jsonl") and n != "journal.jsonl"]
    return path, sorted(subs)


def model_table(responses, allow):
    models = {}
    for r in responses:
        m = models.setdefault(r["model"], {"responses": 0, "tool_calls": 0, "first": r["at"], "last": r["at"],
                                           "allowed": allowed(r["model"], allow), "efforts": {}})
        m["responses"] += 1
        m["tool_calls"] += r["tool_calls"]
        m["last"] = r["at"]
        effort = r["effort"] or "unset"
        m["efforts"][effort] = m["efforts"].get(effort, 0) + 1
    return models


def agent_meta(path):
    """The <agent>.meta.json the client writes next to a subagent's transcript: its type (Explore, say), the model the agent
    that launched it asked for, if any, and the id of that Agent tool call. {} when there is none."""
    try:
        with open(os.path.splitext(path)[0] + ".meta.json", encoding="utf-8") as f:
            meta = json.load(f)
        return meta if isinstance(meta, dict) else {}
    except (OSError, ValueError):
        return {}


def other_work(responses):
    """The kinds of work besides the response's own generation, with how many responses carry each."""
    kinds = {}
    for r in responses:
        for k in r["other_work"]:
            kinds[k] = kinds.get(k, 0) + 1
    return kinds


def stops_of(t, allow, start):
    """Each stop, and the tool calls the allowed models made after it on their own: before the operator's next message or
    interruption, which is what brought the model back when it had stopped."""
    operator_lines = sorted([m["line"] for m in t["messages"]] + [x["line"] for x in t["interruptions"]])
    out = []
    for i, r in enumerate(t["responses"]):
        if not r["stopped"]:
            continue
        until = next((n for n in operator_lines if n > r["end_line"]), float("inf"))
        after = sum(x["tool_calls"] for x in t["responses"]
                    if r["end_line"] < x["line"] < until and allowed(x["model"], allow))
        out.append({"at": r["at"], "model": r["model"], "category": r["category"], "responses_before": i,
                    "seen_as": "stop_reason" if r["stop_reason"] == "refusal" else "client note",
                    "seconds_from_first_message": seconds_between(start, r["at"]), "tool_calls_after": after,
                    "recovered": after > 0})
    return out


def audit(session, requested, allow_more=(), messages=(), allow_commands=(), effort=None, stopped_by=None):
    main_path, sub_paths = resolve(session)
    allow = [requested] + [a for a in allow_more if a != requested]
    main = parse(main_path, True)
    if main["sidechain"]:
        raise Unusable("%s is a subagent transcript, not a main session" % main_path)
    findings = []

    def find(outcome, detail):
        findings.append({"outcome": outcome, "detail": detail})

    start = main["messages"][0]["at"] if main["messages"] else None
    ended_how, ended_at = "no response", None
    if main["last"]:
        what, ref = main["last"]
        if what == "response":
            ended_how = "refusal" if ref["stopped"] else "model" if ref["stop_reason"] in ENDED_BY_MODEL else "cut"
            ended_at = ref["at"]
        else:
            ended_how = {"api error": "api error", "interrupted": "interrupted", "message": "cut"}[what]
            ended_at = next((x["at"] for k in ("api_errors", "interruptions", "messages") for x in main[k] if x["line"] == ref), None)

    # The operator: the declared messages, the declared commands, no interruption, and a session that starts on an allowed model.
    declared = [normalized(m) for m in messages]
    ops = []
    for i, m in enumerate(main["messages"]):
        text = normalized(m["text"])
        ops.append({"at": m["at"], "chars": len(text), "sha256": hashlib.sha256(text.encode("utf-8")).hexdigest(),
                    "head": text[:60], "queued": m["queued"], "declared": (text == declared[i]) if i < len(declared) else None})
    if len(ops) > max(1, len(declared)):
        find("operator error", "%d operator messages; the procedure declares %d" % (len(ops), max(1, len(declared))))
    for i, o in enumerate(ops):
        if o["declared"] is False:
            find("operator error", "operator message %d differs from the declared text" % (i + 1))
    # A command after the last response cannot touch the attempt (an /exit, say); any other must be declared.
    last_response = max((r["end_line"] for r in main["responses"]), default=-1)
    for c in main["commands"]:
        c["after_end"] = c["line"] > last_response >= 0
        if c["name"] not in allow_commands and not c["after_end"]:
            find("operator error", "the operator ran /%s at %s; the procedure does not declare it" % (c["name"], c["at"]))
    lab_stop = stopped_by is not None and main["last"] is not None and main["last"][0] == "interrupted"
    for i, x in enumerate(main["interruptions"]):
        if not (lab_stop and i == len(main["interruptions"]) - 1):
            find("operator error", "the operator interrupted the model at %s" % x["at"])
    start_model = main["identities"][0]["model"] if main["identities"] else (main["responses"][0]["model"] if main["responses"] else None)
    if start_model and not allowed(start_model, allow):
        find("operator error", "the session started on %s, not on an allowed model" % start_model)
    if effort is not None:
        own = [r for r in main["responses"] if allowed(r["model"], allow)]
        off = {}
        for r in own:
            if (r["effort"] or "unset") != effort:
                off[r["effort"] or "unset"] = off.get(r["effort"] or "unset", 0) + 1
        for level, n in sorted(off.items()):
            find("operator error", "%d of %d responses in the main conversation ran at effort %s; the attempt declares %s"
                 % (n, len(own), level, effort))
    # A model the launcher or a setting asked for and the managed policy refused: the client answered with an allowed one, so
    # the work stays attributable, but the procedure was not the declared one.
    for x in main["restrictions"]:
        find("operator error", 'the client was asked for "%s", which its managed settings restrict, and used %s instead (%s)'
             % (x["asked"], x["used"], x["at"]))

    # Who answered in the main conversation, and why a model outside the allowed set did.
    transcripts = []
    main_models = model_table(main["responses"], allow)
    for model, m in main_models.items():
        if m["allowed"]:
            continue
        first = next(r for r in main["responses"] if r["model"] == model)
        if any(f["line"] < first["line"] and f["to"] == model for f in main["fallbacks"]):
            m["cause"] = "fallback"
            find("switched", "%s answered %d responses in the main conversation after a model fallback" % (model, m["responses"]))
        elif model == start_model:
            m["cause"] = "session start"
        elif any(c["line"] < first["line"] and c["name"] == "model" for c in main["commands"]):
            m["cause"] = "operator command"
            find("operator error", "%s answered after the operator changed the model with /model" % model)
        else:
            m["cause"] = "unexplained"
            find("switched", "%s answered %d responses in the main conversation" % (model, m["responses"]))
    main_stops = stops_of(main, allow, start)
    transcripts.append({"path": main_path, "kind": "main", "models": main_models, "stops": main_stops, "notices": main["notices"],
                        "fallbacks": main["fallbacks"], "api_errors": main["api_errors"], "restrictions": main["restrictions"],
                        "other_work": other_work(main["responses"])})

    # Subagents: any model outside the allowed set is a switch, whether the client changed it after a stop or the agent itself
    # launched the subagent asking for that model (the Agent tool's `model`); the cause says which.
    subs = [(p, parse(p, False)) for p in sub_paths]
    calls = dict(main["agent_calls"])
    for _, s in subs:
        calls.update(s["agent_calls"])
    for p, s in subs:
        meta = agent_meta(p)
        asked = meta.get("model") or calls.get(meta.get("toolUseId"))
        models = model_table(s["responses"], allow)
        stops = stops_of(s, allow, start)
        rel = os.path.relpath(p, os.path.dirname(main_path))
        for model, m in models.items():
            if not m["allowed"]:
                first = next(r for r in s["responses"] if r["model"] == model)
                if asked and first is s["responses"][0]:
                    m["cause"] = 'requested by the agent ("%s")' % asked
                elif any(r["stopped"] and r["line"] < first["line"] for r in s["responses"]):
                    m["cause"] = "after a stop"
                else:
                    m["cause"] = "unexplained"
                find("switched", "%s answered %d responses in subagent %s (%s)" % (model, m["responses"], rel, m["cause"]))
        transcripts.append({"path": rel, "kind": "subagent", "agent_type": meta.get("agentType"), "asked_model": asked,
                            "models": models, "stops": stops, "notices": s["notices"], "fallbacks": s["fallbacks"],
                            "api_errors": s["api_errors"], "restrictions": s["restrictions"],
                            "other_work": other_work(s["responses"])})
    for t in transcripts:
        for kind, n in sorted(t["other_work"].items()):
            find("inconclusive", "%d responses in %s carry %s, which this audit does not know: check whether another model "
                 "contributed" % (n, "the main conversation" if t["kind"] == "main" else "subagent " + t["path"], kind))

    if ended_how == "api error":
        err = main["api_errors"][-1]
        find("lab failure", "the session ended on an API error (%s) at %s" % (err["error"] or "no code", err["at"]))
    if stopped_by == "lab":
        find("lab failure", "the lab stopped the client")
    if main_stops and not main_stops[-1]["recovered"]:
        find("blocked", "a safeguard stop at %s (%s) ended the work: no tool call by an allowed model after it"
             % (main_stops[-1]["at"], main_stops[-1]["category"] or "no category"))
    if stopped_by == "budget":
        find("budget exhausted", "the lab stopped the client when the declared budget ran out")
    if stopped_by is None and ended_how in ("cut", "no response"):
        find("inconclusive", "the session has no response" if ended_how == "no response" else
             "the session was cut mid-work at %s and the lab gave no reason (--stopped-by)" % ended_at)

    by_model, total = {}, 0
    for t in transcripts:
        for model, m in t["models"].items():
            b = by_model.setdefault(model, {"responses": 0, "tool_calls": 0, "allowed": m["allowed"]})
            b["responses"] += m["responses"]
            b["tool_calls"] += m["tool_calls"]
            total += m["responses"]
    for b in by_model.values():
        b["share"] = round(b["responses"] / total, 4) if total else 0.0
    all_stops = [s for t in transcripts for s in t["stops"]]
    outcome = next((o for o in OUTCOMES if any(f["outcome"] == o for f in findings)), "completed")
    return {
        "session": main_path, "requested": requested, "allowed": allow, "allowed_commands": sorted(allow_commands),
        "declared_effort": effort, "stopped_by": stopped_by, "client_versions": sorted(main["versions"]),
        "advisors_configured": sorted({r["advisor"] for r in main["responses"] if r["advisor"]}), "start_model": start_model,
        "first_message_at": start, "ended": {"how": ended_how, "at": ended_at},
        "span_seconds": seconds_between(start, ended_at) if start and ended_at else None,
        "turns": len(main["turns"]), "agent_seconds": round(sum(x["ms"] for x in main["turns"]) / 1000, 1),
        "operator": {"messages": ops, "declared_messages": max(1, len(declared)), "commands": main["commands"],
                     "interruptions": [x["at"] for x in main["interruptions"]]},
        "identities": [{"at": x["at"], "model": x["model"]} for x in main["identities"]],
        "summary": {"subagents": len(sub_paths), "responses": total, "by_model": by_model, "stops": len(all_stops),
                    "stops_recovered": sum(1 for s in all_stops if s["recovered"]), "stops_main": len(main_stops),
                    "fallbacks": sum(len(t["fallbacks"]) for t in transcripts),
                    "api_errors": sum(len(t["api_errors"]) for t in transcripts)},
        "transcripts": transcripts, "findings": findings, "outcome": outcome,
    }


def render(rep):
    out = []
    w = out.append
    s = rep["summary"]
    w("session    %s" % rep["session"])
    w("client     %s" % (", ".join(rep["client_versions"]) or "unknown"))
    w("requested  %s%s   allowed: %s" % (rep["requested"], " at effort %s" % rep["declared_effort"] if rep["declared_effort"] else "",
                                         ", ".join(rep["allowed"])))
    op = rep["operator"]
    w("operator   %d message(s), %d declared; commands: %s; interruptions: %d" % (
        len(op["messages"]), op["declared_messages"],
        " ".join("/" + c["name"] + (" (after the end)" if c.get("after_end") else "") for c in op["commands"]) or "none",
        len(op["interruptions"])))
    w("started on %s; first message at %s; advisor configured: %s" % (
        rep["start_model"] or "unknown", rep["first_message_at"] or "never", ", ".join(rep["advisors_configured"]) or "none"))

    def block(t, label):
        w("%-10s %s" % (label, "%s (%s%s)" % (t["path"], t.get("agent_type") or "type unknown",
                                               ', asked for "%s"' % t["asked_model"] if t.get("asked_model") else "")
                        if t["kind"] == "subagent" else "conversation"))
        for model, m in sorted(t["models"].items(), key=lambda kv: kv[1]["first"]):
            note = "" if m["allowed"] else "   NOT ALLOWED (%s)" % m.get("cause", "")
            efforts = ", ".join("%s %d" % kv for kv in sorted(m["efforts"].items()))
            w("   %-28s %5d responses %5d tool calls  effort %s  %s .. %s%s" % (
                model, m["responses"], m["tool_calls"], efforts, m["first"], m["last"], note))
        for x in t["stops"]:
            w("   stop at %s (%s, seen as %s) after %d responses; %d tool calls by allowed models after it%s" % (
                x["at"], x["category"] or "category unknown", x["seen_as"], x["responses_before"], x["tool_calls_after"],
                "" if x["recovered"] else "  (not recovered)"))
        for x in t["notices"]:
            w("   notice at %s: %s" % (x["at"], x["text"]))
        for x in t["fallbacks"]:
            w("   fallback at %s: %s -> %s (%s)" % (x["at"], x["from"], x["to"], x["scope"]))
        for x in t["api_errors"]:
            w("   API error at %s: %s %s" % (x["at"], x["error"], x["text"]))
        for x in t["restrictions"]:
            w('   restricted at %s: asked for "%s", used %s' % (x["at"], x["asked"], x["used"]))

    block(rep["transcripts"][0], "main")
    subs = rep["transcripts"][1:]
    odd = [t for t in subs if t["stops"] or t["fallbacks"] or t["api_errors"] or t["restrictions"]
           or not all(m["allowed"] for m in t["models"].values())]
    kinds = {}
    for t in subs:
        kinds[t.get("agent_type") or "type unknown"] = kinds.get(t.get("agent_type") or "type unknown", 0) + 1
    w("subagents  %d%s (%d with a stop, a model not allowed, a restricted model or an API error)" % (
        len(subs), " [%s]" % ", ".join("%s %d" % kv for kv in sorted(kinds.items())) if subs else "", len(odd)))
    for t in odd:
        block(t, "  subagent")
    w("models     " + "; ".join("%s %d responses (%.1f%%)%s" % (m, b["responses"], 100 * b["share"], "" if b["allowed"] else " NOT ALLOWED")
                                for m, b in sorted(s["by_model"].items(), key=lambda kv: -kv[1]["responses"])))
    w("stops      %d (%d in the main conversation, %d recovered); fallbacks %d; API errors %d" % (
        s["stops"], s["stops_main"], s["stops_recovered"], s["fallbacks"], s["api_errors"]))
    how = {"model": "the model ended its turn", "refusal": "on a safeguard stop", "api error": "on an API error",
           "interrupted": "on an interruption", "cut": "cut mid-work", "no response": "with no response"}[rep["ended"]["how"]]
    span = "%.1f min after the first message" % (rep["span_seconds"] / 60) if rep["span_seconds"] is not None else "no span"
    timing = ("%d turn%s, %.1f min of agent time" % (rep["turns"], "" if rep["turns"] == 1 else "s", rep["agent_seconds"] / 60)
              if rep["turns"] else "no turn records, as in a non-interactive session")
    w("ended      %s%s (%s; %s)" % (how, " at %s" % rep["ended"]["at"] if rep["ended"]["at"] else "", span, timing))
    w("OUTCOME    %s" % rep["outcome"].upper())
    for f in rep["findings"]:
        w("   - %s: %s" % (f["outcome"], f["detail"]))
    return "\n".join(out) + "\n"


def main():
    ap = argparse.ArgumentParser(description="Audit one attempt's Claude Code session: who answered, the safeguard stops, the outcome")
    ap.add_argument("session", metavar="SESSION", help="the main transcript, or a project folder with exactly one session")
    ap.add_argument("--requested", required=True, metavar="MODEL", help="the model id the attempt is filed under")
    ap.add_argument("--allow", action="append", default=[], metavar="MODEL", help="another model allowed to answer (repeatable)")
    ap.add_argument("--message", action="append", default=[], metavar="FILE",
                    help="a file with an operator message the procedure sends, in order (repeatable; default: one message, any text)")
    ap.add_argument("--allow-command", action="append", default=[], metavar="NAME",
                    help="a slash command the procedure runs, without the slash (repeatable; default: none)")
    ap.add_argument("--effort", metavar="LEVEL", help="the effort every response of the main conversation must carry (the client "
                    "records it per response); omit it for a model the client sends no effort for")
    ap.add_argument("--stopped-by", choices=("budget", "lab"), help="the lab stopped the client: the budget ran out, or a lab reason")
    ap.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()
    try:
        texts = []
        for p in a.message:
            with open(p, encoding="utf-8") as f:
                texts.append(f.read())
        rep = audit(a.session, a.requested, a.allow, texts, [c.lstrip("/") for c in a.allow_command], a.effort, a.stopped_by)
    except (OSError, ValueError, Unusable) as e:
        print("[auditar-tentativa] %s" % e, file=sys.stderr)
        sys.exit(2)
    sys.stdout.write(json.dumps(rep, ensure_ascii=False, indent=2) + "\n" if a.json else render(rep))
    sys.exit(0 if rep["outcome"] == "completed" else 2 if rep["outcome"] == "inconclusive" else 1)


if __name__ == "__main__":
    main()
