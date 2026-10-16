#!/usr/bin/env python3
"""auditar-opencode.py — audit one attempt's OpenCode session: who answered, which agent and effort, what ended it, and the outcome.
Read-only: it prints a report and writes nothing (it reads a copy of the database). The OpenCode twin of tools/auditar-codex.py and
tools/auditar-tentativa.py, with the same outcomes.

OpenCode keeps its record in one SQLite database, ~/.local/share/opencode/opencode.db, and a log folder beside it. The `session` table holds one row
per conversation (a session another session spawned has a `parent_id`: it is the agent's own work, so its model is audited too), the `message` table
one row per message (`data` is JSON: an assistant message carries the provider, the model, the effort variant, the agent, why it finished and, when it
failed, the error), and the `part` table the text of the user's messages. The log names the model of every request the client made, the client's own
auxiliary calls included (the title of the session, `small=true`), which the database does not keep. This reads those and reports:

- the provider, model, effort and agent of every assistant message, per session, and the model of every request in the log;
- the operator's activity: the user messages of the root session (compared with the declared text) and the interrupted turns;
- every error, the permission decisions the log wrote (allow, ask, deny) and how the root session ended;
- the tokens and the cost the client recorded (a price-table estimate: OpenCode keeps no model time, so there is none here).

The outcome is the first of these that applies:

  operator error    the procedure was not the declared one: the session started on another provider or model than the requested one, an effort
                    (--effort) or an agent (--agent) other than the declared one, more user messages than declared or a message that differs
                    from the declared text, an interrupted turn the lab did not cause
  switched          a model outside the allowed set answered a message, in the root session or in a session it spawned, or made a request
                    the log records (the auxiliary model of the title included)
  lab failure       the root session ended on an error (a refused key, an unpaid balance, an unknown model, an unreachable host), or the lab
                    stopped the client for a reason of its own (--stopped-by lab)
  blocked           the root session ended on a content-filter stop and no message followed
  budget exhausted  the lab stopped the client when the declared budget ran out (--stopped-by budget)
  inconclusive      no assistant message at all, or the session was cut mid-work or ended on the output limit and the lab gave no reason
  completed         the root session's last assistant message finished by itself (`stop`) with no error, and nothing above applies

    python3 tools/auditar-opencode.py RECORD --provider deepseek --requested deepseek-flash --effort high --message msg.txt --json

RECORD is opencode.db, or a folder holding exactly one (the runner's ~/.local/share/opencode, or the tree collected from it). A model matches an
allowed `provider/model` exactly. A model with no effort setting is declared with --effort default (its messages then carry no variant, or the client's own name `default`).

Exit 0: completed. Exit 1: any other outcome. Exit 2: inconclusive, or a path or an argument could not be used.

What it does not know, and says so: how the client writes a content-filter stop or a retried request has not been seen in a real record of this lab.
The first is read from the message's `finish` value (`content-filter`), the second is not looked at (a request the client retried and won leaves
no trace in the message table). Without the log folder the auxiliary requests cannot be audited, and the report says so. No effect on any score.
Standard library only.
"""

import argparse
import glob
import json
import os
import re
import shutil
import sqlite3
import sys
import tempfile

OUTCOMES = ("operator error", "switched", "lab failure", "blocked", "budget exhausted", "inconclusive")
ABORT = "MessageAbortedError"
STREAM = re.compile(r"message=stream\b.*?\bproviderID=(\S+)\s+modelID=(\S+)(?:\s+session\.id=(\S+))?(.*)$")
PERMISSION = re.compile(r"message=evaluated\b.*?\bpermission=(\S+).*?\baction\.action=(\w+)")


class Unusable(Exception):
    """The record cannot be audited as given."""


def normalized(text):
    return re.sub(r"\s+", " ", text or "").strip()


def find_database(path):
    if os.path.isfile(path):
        return path
    # os.walk, not glob: the collected tree keeps the database under .local/, a hidden folder that "**" does not enter
    hits = sorted(os.path.join(d, "opencode.db") for d, _, files in os.walk(path) if "opencode.db" in files)
    if not hits:
        raise Unusable("no opencode.db under %s" % path)
    if len(hits) > 1:
        raise Unusable("%d databases under %s; pass the one to audit" % (len(hits), path))
    return hits[0]


def open_copy(db_path, tmp):
    """A read-only handle on a copy of the database and its write-ahead log, so that reading never touches the evidence."""
    for suffix in ("", "-wal", "-shm"):
        if os.path.exists(db_path + suffix):
            shutil.copy2(db_path + suffix, os.path.join(tmp, "opencode.db" + suffix))
    con = sqlite3.connect("file:%s?mode=ro" % os.path.join(tmp, "opencode.db"), uri=True)
    con.row_factory = sqlite3.Row
    return con


def parse_model(text):
    try:
        m = json.loads(text) if text else {}
    except ValueError:
        m = {}
    return {"provider": m.get("providerID"), "model": m.get("id") or m.get("modelID"), "variant": m.get("variant")}


def error_of(data):
    err = data.get("error")
    if not err:
        return None
    info = err.get("data") or {}
    return {"name": err.get("name"), "status": info.get("statusCode"), "message": str(info.get("message") or "")[:200], "retryable": info.get("isRetryable")}


def read_sessions(con):
    rows = con.execute("select * from session order by time_created").fetchall()
    sessions = []
    for r in rows:
        s = {"id": r["id"], "parent": r["parent_id"], "agent": r["agent"], "model": parse_model(r["model"]), "version": r["version"], "title": r["title"],
             "cost": r["cost"] or 0.0, "tokens": {"input": r["tokens_input"] or 0, "output": r["tokens_output"] or 0, "reasoning": r["tokens_reasoning"] or 0,
                                                  "cache_read": r["tokens_cache_read"] or 0, "cache_write": r["tokens_cache_write"] or 0},
             "users": [], "assistants": [], "created": r["time_created"]}
        for m in con.execute("select id, time_created, data from message where session_id = ? order by time_created, id", (r["id"],)):
            d = json.loads(m["data"])
            if d.get("role") == "user":
                parts = []
                for p in con.execute("select data from part where message_id = ? order by time_created, id", (m["id"],)):
                    pd = json.loads(p["data"])
                    if pd.get("type") == "text" and not pd.get("synthetic"):
                        parts.append(pd.get("text") or "")
                s["users"].append({"at": m["time_created"], "text": "".join(parts)})
            elif d.get("role") == "assistant":
                t = d.get("time") or {}
                s["assistants"].append({"provider": d.get("providerID"), "model": d.get("modelID"), "variant": d.get("variant"), "agent": d.get("agent"),
                                        "finish": d.get("finish"), "error": error_of(d), "created": t.get("created") or m["time_created"],
                                        "completed": t.get("completed")})
        sessions.append(s)
    return sessions


def read_log(db_path):
    """(streams, permissions) from the log folder beside the database, or (None, None) when there is none."""
    files = sorted(glob.glob(os.path.join(os.path.dirname(db_path), "log", "*.log")))
    if not files:
        return None, None
    streams, permissions = [], {"allow": 0, "ask": 0, "deny": 0}
    for f in files:
        with open(f, encoding="utf-8", errors="replace") as fh:
            for line in fh:
                m = STREAM.search(line)
                if m:
                    rest = m.group(4)
                    agent = re.search(r"\bagent=(\S+)", rest)
                    streams.append({"provider": m.group(1), "model": m.group(2), "session": m.group(3), "auxiliary": "small=true" in rest,
                                    "agent": agent.group(1) if agent else None})
                    continue
                p = PERMISSION.search(line)
                if p and p.group(2) in permissions:
                    permissions[p.group(2)] += 1
    return streams, permissions


def split_model(spec):
    """PROVIDER/MODEL, split at the first slash: a model id can hold slashes of its own (openrouter/deepseek/deepseek-v4.1-flash)."""
    if "/" not in spec:
        raise Unusable("--allow takes PROVIDER/MODEL, not %r" % spec)
    provider, model = spec.split("/", 1)
    return provider, model


def audit(record, provider, requested, allow_more=(), messages=(), effort=None, agent="build", stopped_by=None):
    db_path = find_database(record)
    with tempfile.TemporaryDirectory() as tmp:
        con = None
        try:
            con = open_copy(db_path, tmp)
            sessions = read_sessions(con)
        except (sqlite3.Error, ValueError) as e:
            raise Unusable("%s cannot be read: %s" % (db_path, e))
        finally:
            if con is not None:
                con.close()
    roots = [s for s in sessions if not s["parent"]]
    if len(roots) != 1:
        raise Unusable("expected one root session, found %d of %d" % (len(roots), len(sessions)))
    root = roots[0]
    allow = [(provider, requested)] + [split_model(a) for a in allow_more]
    declared = [normalized(m) for m in messages]
    streams, permissions = read_log(db_path)
    findings = []

    def find(outcome, detail):
        findings.append((outcome, detail))

    asst = root["assistants"]
    if not asst:
        find("inconclusive", "the root session has no assistant message")
    else:
        first = asst[0]
        chosen = (root["model"]["provider"], root["model"]["model"])
        if (first["provider"], first["model"]) != (provider, requested):
            find("operator error", "the session started on %s/%s, not on the requested %s/%s" % (first["provider"], first["model"], provider, requested))
        elif chosen != (provider, requested):
            find("operator error", "the session was created on %s/%s, not on the requested %s/%s" % (chosen[0], chosen[1], provider, requested))
        want = None if effort in (None, "default") else effort
        # a model that offers no setting records no variant, or the client's own name for it ("default"): both are the declared `default`
        same = (lambda v: v in (None, "default")) if want is None else (lambda v: v == want)
        if effort is not None:
            wrong = sorted({str(a["variant"]) for a in asst if not same(a["variant"])} | ({str(root["model"]["variant"])} if not same(root["model"]["variant"]) else set()))
            if wrong:
                find("operator error", "effort %s, not the declared %s" % (", ".join(wrong), effort))
        if agent:
            wrong = sorted({str(a["agent"]) for a in asst if a["agent"] != agent})
            if wrong:
                find("operator error", "agent %s, not the declared %s" % (", ".join(wrong), agent))
    users = [normalized(u["text"]) for u in root["users"] if normalized(u["text"])]
    if declared and len(users) > len(declared):
        find("operator error", "%d user messages, %d declared" % (len(users), len(declared)))
    elif declared:
        for i, text in enumerate(users):
            if text != declared[i]:
                find("operator error", "user message %d differs from the declared text" % (i + 1))
    aborted = [a for a in asst if a["error"] and a["error"]["name"] == ABORT]
    if aborted and not stopped_by:
        find("operator error", "%d interrupted turn(s)" % len(aborted))
    for s in sessions:
        out = sorted({"%s/%s" % (a["provider"], a["model"]) for a in s["assistants"] if (a["provider"], a["model"]) not in allow})
        if out:
            find("switched", "%s answered in %s" % (", ".join(out), "a spawned session" if s["parent"] else "the root session"))
    if streams is not None:
        out = sorted({"%s/%s%s" % (x["provider"], x["model"], " (auxiliary, agent %s)" % x["agent"] if x["auxiliary"] else "")
                      for x in streams if (x["provider"], x["model"]) not in allow})
        if out:
            find("switched", "the client made requests to %s" % ", ".join(out))
    last = asst[-1] if asst else None
    last_error = last["error"] if last and last["error"] and last["error"]["name"] != ABORT else None
    if stopped_by == "lab":
        find("lab failure", "the lab stopped the client for a reason of its own")
    elif last_error:
        find("lab failure", "the session ended on an error: %s%s %s" % (last_error["name"], " %s" % last_error["status"] if last_error["status"] else "", last_error["message"]))
    if last and last["finish"] == "content-filter" and not last["error"] and not stopped_by:
        find("blocked", "the root session ended on a content-filter stop")
    if stopped_by == "budget":
        find("budget exhausted", "the lab stopped the client when the budget ran out")
    if last and not stopped_by and not last_error and not (aborted and last in aborted):
        if last["finish"] == "length":
            find("inconclusive", "the root session ended on the output limit")
        elif last["finish"] in (None, "tool-calls", "unknown", "other") or not last["completed"]:
            find("inconclusive", "the session was cut mid-work and the lab gave no reason")
    outcome, detail = next(((o, d) for o in OUTCOMES for (oo, d) in findings if oo == o), ("completed", "the root session's last assistant message finished by itself"))

    tokens = {k: sum(s["tokens"][k] for s in sessions) for k in ("input", "output", "reasoning", "cache_read", "cache_write")}
    tokens["total"] = sum(tokens.values())
    stamps = [u["at"] for u in root["users"]]
    starts = min(stamps) if stamps else root["created"]
    ends = max([a["completed"] or a["created"] for a in asst] or [starts])
    finishes = {}
    for a in asst:
        finishes[str(a["finish"])] = finishes.get(str(a["finish"]), 0) + 1
    report = {
        "client": "opencode", "client_version": root["version"], "provider": provider, "requested": requested,
        "allowed": ["%s/%s" % a for a in allow], "effort": effort, "agent": agent,
        "outcome": outcome, "detail": detail, "findings": [{"outcome": o, "detail": d} for o, d in findings],
        "sessions": [{"id": s["id"], "spawned": bool(s["parent"]), "agent": s["agent"], "assistant_messages": len(s["assistants"]),
                      "user_messages": len(s["users"]), "models": sorted({"%s/%s" % (a["provider"], a["model"]) for a in s["assistants"]}),
                      "efforts": sorted({str(a["variant"]) for a in s["assistants"]}), "agents": sorted({str(a["agent"]) for a in s["assistants"]}),
                      "finishes": {k: v for k, v in sorted(finishes.items())} if s is root else None,
                      "errors": [a["error"] for a in s["assistants"] if a["error"]]} for s in sessions],
        "requests_in_log": None if streams is None else {"total": len(streams), "auxiliary": [{"model": "%s/%s" % (x["provider"], x["model"]), "agent": x["agent"]} for x in streams if x["auxiliary"]]},
        "permission_decisions": permissions,
        "operator": {"user_messages": len(users), "declared": len(declared), "interruptions": len(aborted)},
        "tokens": tokens, "cost_usd": round(sum(s["cost"] for s in sessions), 6),
        "started_ms": starts, "ended_ms": ends, "span_seconds": round((ends - starts) / 1000, 1),
    }
    if streams is None:
        report["notes"] = ["no log folder beside the database: the client's auxiliary requests were not audited"]
    return report


def render(rep):
    out = []
    w = out.append
    w("client     opencode %s" % rep["client_version"])
    w("requested  %s/%s at effort %s   agent: %s   allowed: %s" % (rep["provider"], rep["requested"], rep["effort"] or "(not declared)", rep["agent"] or "(not declared)", ", ".join(rep["allowed"])))
    w("operator   %d user message(s), %d declared; interruptions: %d" % (rep["operator"]["user_messages"], rep["operator"]["declared"], rep["operator"]["interruptions"]))
    for s in rep["sessions"]:
        w("%s %s  %d assistant message(s)  models %s  effort %s  agent %s" % ("spawned" if s["spawned"] else "root   ", s["id"][:24], s["assistant_messages"], ", ".join(s["models"]) or "-",
                                                                           ", ".join(s["efforts"]) or "-", ", ".join(s["agents"]) or "-"))
        if s["finishes"]:
            w("    finished: %s" % ", ".join("%s x%d" % kv for kv in s["finishes"].items()))
        for e in s["errors"]:
            w("    error %s %s %s" % (e["name"], e["status"] or "", e["message"]))
    if rep["requests_in_log"] is not None:
        w("log        %d request(s); auxiliary: %s" % (rep["requests_in_log"]["total"], ", ".join("%s (%s)" % (x["model"], x["agent"]) for x in rep["requests_in_log"]["auxiliary"]) or "none"))
        w("permissions %s" % json.dumps(rep["permission_decisions"]))
    for n in rep.get("notes", []):
        w("NOTE       %s" % n)
    w("tokens     %s   cost recorded by the client: US$ %.4f" % (json.dumps(rep["tokens"]), rep["cost_usd"]))
    w("session    %s s" % rep["span_seconds"])
    for f in rep["findings"]:
        w("FINDING    %s: %s" % (f["outcome"], f["detail"]))
    w("OUTCOME    %s" % rep["outcome"].upper())
    return "\n".join(out)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("record", metavar="RECORD", help="opencode.db, or a folder holding exactly one")
    ap.add_argument("--provider", required=True, help="the OpenCode provider id the attempt is filed under (deepseek, openrouter, ...)")
    ap.add_argument("--requested", required=True, metavar="MODEL", help="the model id, as that provider names it")
    ap.add_argument("--allow", action="append", default=[], metavar="PROVIDER/MODEL", help="another model allowed to answer or to be requested (repeatable)")
    ap.add_argument("--message", action="append", default=[], metavar="FILE", help="a file with the text of one declared operator message (repeatable, in order)")
    ap.add_argument("--effort", metavar="LEVEL", help="the effort variant every message must carry; default = the model has none")
    ap.add_argument("--agent", default="build", help="the agent every message of the root session must come from (default: build, the client's own)")
    ap.add_argument("--stopped-by", choices=("budget", "lab"), help="the lab stopped the client: the budget ran out, or a lab reason")
    ap.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()
    try:
        messages = [open(m, encoding="utf-8").read() for m in a.message]
        rep = audit(a.record, a.provider, a.requested, a.allow, messages, a.effort, a.agent, a.stopped_by)
    except (Unusable, OSError) as e:
        print("auditar-opencode: %s" % e, file=sys.stderr)
        sys.exit(2)
    print(json.dumps(rep, ensure_ascii=False, indent=2) if a.json else render(rep))
    sys.exit(0 if rep["outcome"] == "completed" else 2 if rep["outcome"] == "inconclusive" else 1)


if __name__ == "__main__":
    main()
