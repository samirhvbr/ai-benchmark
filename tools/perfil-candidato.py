#!/usr/bin/env python3
"""perfil-candidato.py — the candidate profile of the next series (docs/new-series-design.md §6.1): the managed policy that pins
the requested model, and a read-only preflight that checks a machine against it before the first message.

    python3 tools/perfil-candidato.py policy --requested claude-fable-5-1 --effort xhigh > managed-settings.json
    sudo install -o root -g root -m 644 managed-settings.json /etc/claude-code/managed-settings.json
    python3 tools/perfil-candidato.py check --requested claude-fable-5-1 --effort xhigh --home /home/leb --workdir /srv/LEB-X \\
        --client-version 2.1.285 [--env] [--json]

`policy` prints the managed settings of the profile. Each key, from Claude Code's settings reference (October 2026):

- `availableModels` [requested, --allow ...] with `availableModelsMatch` "exact" (managed only, v2.1.283 or later): no other model
  may answer, in the main conversation, a subagent, a skill or the advisor. When the model a safety flag would fall back to is not
  allowed, no fallback happens: the flagged request ends with the refusal and the model stays. Exact matching, because the default
  prefix matching lets an id permit the later versions that extend it.
- `enforceAvailableModels` and `model`: the session starts on the requested model, with no /model.
- No `deniedModels`. An id without a minor version blocks the later minor versions too (claude-sonnet-5 would block
  claude-sonnet-5-5), and the exact allowlist already denies every other model. `check` refuses a denied entry that would block the
  requested model.
- No `fallbackModel`: that chain takes over a request when the model is overloaded.
- The advisor off, through CLAUDE_CODE_DISABLE_ADVISOR_TOOL in the policy's `env`, which the client merges from every managed source.
- `switchModelsOnFlag` false: should the pin ever fail, the main conversation pauses instead of switching. Subagents do not pause;
  the audit (tools/auditar-tentativa.py) sees them.
- `syncClaudeAiSkills` and `syncClaudeAiPlugins` false, `disableClaudeAiConnectors` true, `enableArtifact` false: the account's
  own content stays out of the session.
- `effortLevel`: the declared effort, low to xhigh. Max is not a settings value; it is passed at launch (`--effort max`).
- `requiredMinimumVersion`: a client too old for exact matching refuses to start.

`check` reads and changes nothing:

- the managed file and its drop-ins (managed-settings.d/*.json, merged as the client merges them): owned by root, writable by
  root only, readable by the runner (a policy the runner cannot read is skipped without notice), and holding the profile;
  no managed-mcp.json with servers;
- the runner's home: no cached policy of the account's organization (~/.claude/remote-settings.json), no model, effort, advisor,
  hook, plugin or output-style override in its settings, no CLAUDE.md, skills, agents or commands, no MCP server in ~/.claude.json,
  the work folder trusted, and no transcript or memory of an earlier session in it;
- the work folder: no project settings, CLAUDE.md, .mcp.json, skills, agents or commands;
- with --env, this process's environment, as the launcher passes it to the client: no variable that picks a model, a provider or
  an API address, or an effort other than the declared one;
- with --client-version, the client's version against the policy's minimum.

What a file check cannot see: a policy that the account's organization delivers from the claude.ai console (Team and Enterprise
plans) outranks this file and, by default, replaces it whole. In the lab, `/status` must show `Enterprise managed settings (file)` as
the setting source, with no `Skipped sources`, and `claude doctor` must report no server-managed settings. The audit of the
transcripts is what shows that the pin held.

Exit 0: every check holds. Exit 1: a check failed. Exit 2: a path or an argument could not be used. Stdlib only.
"""

import argparse
import json
import os
import re
import sys

MIN_VERSION = "2.1.283"
EFFORTS = ("low", "medium", "high", "xhigh", "max")
SETTINGS_EFFORTS = ("low", "medium", "high", "xhigh")
FAMILIES = ("fable", "opus", "sonnet", "haiku")
# Keys that take another value whole instead of merging, when two managed files set them.
WHOLE = ("fallbackModel", "modelPicker")
MODEL_ENV = re.compile(r"^(ANTHROPIC_MODEL|ANTHROPIC_DEFAULT_[A-Z0-9_]*MODEL|ANTHROPIC_SMALL_FAST_MODEL|ANTHROPIC_BASE_URL|"
                       r"CLAUDE_CODE_SUBAGENT_MODEL|CLAUDE_CODE_USE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR)$")
CUSTOM_HOME = ("CLAUDE.md", "skills", "agents", "commands", "output-styles")
CUSTOM_WORKDIR = (".claude/settings.json", ".claude/settings.local.json", ".mcp.json", "CLAUDE.md", "CLAUDE.local.md",
                  ".claude/CLAUDE.md", ".claude/skills", ".claude/agents", ".claude/commands")


class Unusable(Exception):
    """A path or an argument cannot be used."""


def policy(requested, allow=(), effort=None):
    p = {
        "availableModels": [requested] + [a for a in allow if a != requested],
        "availableModelsMatch": "exact",
        "enforceAvailableModels": True,
        "model": requested,
        "switchModelsOnFlag": False,
        "syncClaudeAiSkills": False,
        "syncClaudeAiPlugins": False,
        "disableClaudeAiConnectors": True,
        "enableArtifact": False,
        "requiredMinimumVersion": MIN_VERSION,
        "env": {"CLAUDE_CODE_DISABLE_ADVISOR_TOOL": "1"},
    }
    if effort in SETTINGS_EFFORTS:
        p["effortLevel"] = effort
    return p


def version(text):
    m = re.match(r"\s*v?(\d+)\.(\d+)\.(\d+)", str(text))
    if not m:
        raise ValueError("not a version: %r" % text)
    return tuple(int(x) for x in m.groups())


def merge(base, add, top=True):
    """Two managed files as the client combines them: single values replace, lists join without duplicates, nested blocks merge
    key by key, and a few chains replace whole."""
    out = dict(base)
    for k, v in add.items():
        if top and k in WHOLE:
            out[k] = v
        elif isinstance(v, list) and isinstance(out.get(k), list):
            out[k] = out[k] + [x for x in v if x not in out[k]]
        elif isinstance(v, dict) and isinstance(out.get(k), dict):
            out[k] = merge(out[k], v, False)
        else:
            out[k] = v
    return out


def load_object(path):
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict):
        raise ValueError("%s: the top level is not an object" % path)
    return data


def slug(path):
    """The folder name the client gives a working directory under ~/.claude/projects."""
    return re.sub(r"[^A-Za-z0-9]", "-", os.path.abspath(path))


def truthy(value):
    return str(value).strip().lower() in ("1", "true", "yes", "on")


def blocks(entry, model):
    """Whether a deniedModels entry blocks the model: a family alias, the id itself, or an id it extends."""
    entry = str(entry)
    if entry in FAMILIES:
        return model.startswith("claude-%s-" % entry)
    return model == entry or model.startswith(entry + "-")


def check(requested, allow=(), effort=None, home=None, workdir=None, managed_dir="/etc/claude-code", owner_uid=0,
          client_version=None, environ=None):
    results = []

    def add(area, ok, detail):
        results.append({"area": area, "ok": bool(ok), "detail": detail})

    wanted = [requested] + [a for a in allow if a != requested]

    # The managed policy: the file and its drop-ins, merged.
    main_file = os.path.join(managed_dir, "managed-settings.json")
    dropins_dir = os.path.join(managed_dir, "managed-settings.d")
    files = [main_file] if os.path.isfile(main_file) else []
    if os.path.isdir(dropins_dir):
        files += [os.path.join(dropins_dir, n) for n in sorted(os.listdir(dropins_dir)) if n.endswith(".json") and not n.startswith(".")]
    merged = {}
    if not files:
        add("managed", False, "no managed settings in %s" % managed_dir)
    for p in files:
        st = os.stat(p)
        mode = st.st_mode & 0o777
        add("managed", st.st_uid == owner_uid and not mode & 0o022 and mode & 0o004,
            "%s: owner uid %d, mode %03o (needs uid %d, writable by the owner only, readable by every user)"
            % (p, st.st_uid, mode, owner_uid))
        try:
            merged = merge(merged, load_object(p))
        except ValueError as e:
            add("managed", False, "%s does not parse: the client refuses to start (%s)" % (p, e))
    if files:
        m = merged
        add("managed", m.get("availableModels") == wanted,
            "availableModels %s (the profile: %s, the requested model first)" % (json.dumps(m.get("availableModels")), json.dumps(wanted)))
        add("managed", m.get("availableModelsMatch") == "exact", "availableModelsMatch %s (the profile: exact)" % json.dumps(m.get("availableModelsMatch")))
        add("managed", m.get("enforceAvailableModels") is True, "enforceAvailableModels %s" % json.dumps(m.get("enforceAvailableModels")))
        add("managed", m.get("model") == requested, "model %s" % json.dumps(m.get("model")))
        denied = [d for d in m.get("deniedModels") or [] if blocks(d, requested)]
        add("managed", not denied, "deniedModels %s%s" % (json.dumps(m.get("deniedModels")),
                                                         "; %s also blocks %s" % (json.dumps(denied), requested) if denied else ""))
        add("managed", not m.get("fallbackModel"), "fallbackModel %s (the profile: none)" % json.dumps(m.get("fallbackModel")))
        add("managed", "advisorModel" not in m and truthy((m.get("env") or {}).get("CLAUDE_CODE_DISABLE_ADVISOR_TOOL")),
            "advisor: advisorModel %s, CLAUDE_CODE_DISABLE_ADVISOR_TOOL %s" % (
                json.dumps(m.get("advisorModel")), json.dumps((m.get("env") or {}).get("CLAUDE_CODE_DISABLE_ADVISOR_TOOL"))))
        add("managed", m.get("switchModelsOnFlag") is False, "switchModelsOnFlag %s" % json.dumps(m.get("switchModelsOnFlag")))
        for key, value in (("syncClaudeAiSkills", False), ("syncClaudeAiPlugins", False), ("disableClaudeAiConnectors", True),
                           ("enableArtifact", False)):
            add("managed", m.get(key) is value, "%s %s" % (key, json.dumps(m.get(key))))
        if effort in SETTINGS_EFFORTS:
            add("managed", m.get("effortLevel") == effort, "effortLevel %s (declared %s)" % (json.dumps(m.get("effortLevel")), effort))
        elif effort == "max":
            add("managed", m.get("effortLevel") is None, "effortLevel %s (declared max: no settings value; launch with --effort max)"
                % json.dumps(m.get("effortLevel")))
        minimum = m.get("requiredMinimumVersion")
        try:
            ok = minimum is not None and version(minimum) >= version(MIN_VERSION)
        except ValueError:
            ok = False
        add("managed", ok, "requiredMinimumVersion %s (at least %s, for exact matching)" % (json.dumps(minimum), MIN_VERSION))
    mcp = os.path.join(managed_dir, "managed-mcp.json")
    if os.path.isfile(mcp):
        try:
            servers = load_object(mcp).get("mcpServers") or {}
            add("managed", not servers, "managed-mcp.json declares %d MCP server(s)" % len(servers))
        except ValueError as e:
            add("managed", False, "%s does not parse (%s)" % (mcp, e))

    def read(path, area):
        """A JSON object the client reads, or {} when the file is absent; a file that does not parse is a failed check."""
        if not os.path.isfile(path):
            return {}
        try:
            return load_object(path)
        except ValueError as e:
            add(area, False, "%s does not parse (%s)" % (path, e))
            return {}

    # The runner's home.
    if home:
        cfg = os.path.join(home, ".claude")
        remote = os.path.join(cfg, "remote-settings.json")
        cached = read(remote, "home")
        add("home", not cached, "cached server-managed policy: %s" % (
            "none" if not cached else "%s holds %d key(s); it outranks the managed file" % (remote, len(cached))))
        user = os.path.join(cfg, "settings.json")
        s = read(user, "home")
        bad = [k for k in ("model", "fallbackModel", "advisorModel", "hooks", "outputStyle") if s.get(k)]
        bad += ["enabledPlugins.%s" % k for k, v in (s.get("enabledPlugins") or {}).items() if v]
        bad += ["env.%s" % k for k in (s.get("env") or {}) if MODEL_ENV.match(k) or k == "CLAUDE_CODE_EFFORT_LEVEL"]
        add("home", not bad, "user settings %s: %s" % (user, "no override" if not bad else "overrides " + ", ".join(bad)))
        if effort is not None:
            # A per-model effort the runner saved with /effort is outranked by the policy's effortLevel and by --effort at
            # launch (a bench VM showed the managed level winning, 2026-10-12): reported, not failed, unless neither is in force.
            stale = ["effortLevel=%s" % s["effortLevel"]] if s.get("effortLevel") not in (None, effort) else []
            stale += ["modelSettings.%s=%s" % (k, v.get("effortLevel")) for k, v in (s.get("modelSettings") or {}).items()
                      if k in wanted and isinstance(v, dict) and v.get("effortLevel") not in (None, effort)]
            outranked = effort == "max" or merged.get("effortLevel") == effort
            if stale:
                add("home", outranked, "user effort %s, outranked by %s" % (", ".join(stale), "--effort max at launch" if effort == "max"
                    else "the managed effortLevel" if outranked else "nothing: the policy sets no effortLevel"))
        present = [n for n in CUSTOM_HOME if os.path.isfile(os.path.join(cfg, n))
                   or (os.path.isdir(os.path.join(cfg, n)) and os.listdir(os.path.join(cfg, n)))]
        add("home", not present, "customizations in %s: %s" % (cfg, ", ".join(present) or "none"))
        glob_cfg = os.path.join(home, ".claude.json")
        g = read(glob_cfg, "home")
        add("home", not g.get("mcpServers"), "MCP servers in %s: %d" % (glob_cfg, len(g.get("mcpServers") or {})))
        if workdir:
            entry = (g.get("projects") or {}).get(os.path.abspath(workdir)) or {}
            add("home", entry.get("hasTrustDialogAccepted") is True, "%s trusted: %s" % (workdir, json.dumps(entry.get("hasTrustDialogAccepted"))))
            add("home", not entry.get("mcpServers"), "MCP servers for %s: %d" % (workdir, len(entry.get("mcpServers") or {})))
            leftovers = os.path.join(cfg, "projects", slug(workdir))
            left = sorted(os.listdir(leftovers)) if os.path.isdir(leftovers) else []
            add("home", not left, "earlier sessions in %s: %s" % (leftovers, ", ".join(left[:5]) + (" ..." if len(left) > 5 else "") if left else "none"))

    # The work folder.
    if workdir:
        found = [n for n in CUSTOM_WORKDIR if os.path.exists(os.path.join(workdir, n))]
        add("workdir", not found, "project customizations in %s: %s" % (workdir, ", ".join(found) or "none"))

    # The launcher's environment.
    if environ is not None:
        bad = sorted(k for k, v in environ.items() if v and MODEL_ENV.match(k))
        if environ.get("CLAUDE_CODE_EFFORT_LEVEL") not in (None, "", effort):
            bad.append("CLAUDE_CODE_EFFORT_LEVEL")
        add("env", not bad, "variables that pick a model, a provider, an address or another effort: %s" % (", ".join(bad) or "none"))

    if client_version:
        floor = version(MIN_VERSION)
        try:
            floor = max(floor, version(merged.get("requiredMinimumVersion") or MIN_VERSION))
        except ValueError:
            pass
        add("client", version(client_version) >= floor, "client %s (at least %s)" % (client_version, ".".join(map(str, floor))))

    return {"requested": requested, "allowed": wanted, "effort": effort, "managed_files": files, "checks": results,
            "ok": all(r["ok"] for r in results),
            "note": "A policy of the account's organization from the claude.ai console outranks the managed file: confirm in /status "
                    "that the setting source is `Enterprise managed settings (file)` with no `Skipped sources`."}


def render(rep):
    out = ["%-5s %-8s %s" % ("ok" if r["ok"] else "FAIL", r["area"], r["detail"]) for r in rep["checks"]]
    failed = sum(1 for r in rep["checks"] if not r["ok"])
    out.append("RESULT  %s" % ("every check holds" if rep["ok"] else "%d check(s) failed" % failed))
    out.append("note: " + rep["note"])
    return "\n".join(out) + "\n"


def main():
    ap = argparse.ArgumentParser(description="The candidate profile of the next series: the managed policy, and a read-only preflight")
    sub = ap.add_subparsers(dest="cmd", required=True)
    for name in ("policy", "check"):
        p = sub.add_parser(name)
        p.add_argument("--requested", required=True, metavar="MODEL", help="the model id the attempt is filed under")
        p.add_argument("--allow", action="append", default=[], metavar="MODEL", help="another model the lab allows (repeatable)")
        p.add_argument("--effort", choices=EFFORTS, help="the effort the attempt is filed under")
    c = sub.choices["check"]
    c.add_argument("--home", metavar="DIR", help="the runner's home folder")
    c.add_argument("--workdir", metavar="DIR", help="the folder the client starts in")
    c.add_argument("--managed-dir", default="/etc/claude-code", metavar="DIR", help="where the managed settings live")
    c.add_argument("--owner-uid", type=int, default=0, metavar="UID", help="the owner the managed files must have (default root)")
    c.add_argument("--client-version", metavar="X.Y.Z", help="the version `claude --version` reports")
    c.add_argument("--env", action="store_true", help="also check this process's environment, as the launcher passes it on")
    c.add_argument("--json", action="store_true", help="print the report as JSON")
    a = ap.parse_args()
    if a.cmd == "policy":
        sys.stdout.write(json.dumps(policy(a.requested, a.allow, a.effort), indent=2) + "\n")
        if a.effort == "max":
            print("[perfil-candidato] max is not a settings value: launch the client with --effort max", file=sys.stderr)
        return
    try:
        for d in (a.home, a.workdir):
            if d and not os.path.isdir(d):
                raise Unusable("not a folder: %s" % d)
        rep = check(a.requested, a.allow, a.effort, a.home, a.workdir, a.managed_dir, a.owner_uid, a.client_version,
                    dict(os.environ) if a.env else None)
    except (OSError, ValueError, Unusable) as e:
        print("[perfil-candidato] %s" % e, file=sys.stderr)
        sys.exit(2)
    sys.stdout.write(json.dumps(rep, ensure_ascii=False, indent=2) + "\n" if a.json else render(rep))
    sys.exit(0 if rep["ok"] else 1)


if __name__ == "__main__":
    main()
