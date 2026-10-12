"""tools/perfil-candidato.py: the managed policy of the candidate profile, and the read-only preflight against it.

Synthetic machines only: a managed settings folder, a runner's home and a work folder, built in a temporary directory.
"""

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

from helpers import ROOT

TOOL = os.path.join(ROOT, "tools", "perfil-candidato.py")
REQ = "claude-fable-5-1"
UID = os.getuid()


def run(*args, env=None):
    """The tool, with the caller's variables that steer Claude Code removed, plus `env`."""
    clean = {k: v for k, v in os.environ.items() if not re.match(r"^(ANTHROPIC_|CLAUDE_CODE_|CLAUDE_CONFIG_DIR$)", k)}
    clean.update(env or {})
    return subprocess.run([sys.executable, TOOL, *args], cwd=ROOT, env=clean, capture_output=True, text=True)


def write_json(path, data, mode=0o644):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(data if isinstance(data, str) else json.dumps(data))
    os.chmod(path, mode)


class Profile(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        t = self.tmp.name
        self.managed, self.home, self.workdir = (os.path.join(t, "etc"), os.path.join(t, "home"), os.path.join(t, "srv", "pkg"))
        for d in (self.managed, os.path.join(self.home, ".claude"), self.workdir):
            os.makedirs(d)
        self.policy = self.make_policy()
        write_json(os.path.join(self.managed, "managed-settings.json"), self.policy)
        write_json(os.path.join(self.home, ".claude.json"),
                   {"projects": {self.workdir: {"hasTrustDialogAccepted": True, "mcpServers": {}}}})

    def tearDown(self):
        self.tmp.cleanup()

    def make_policy(self, *args):
        r = run("policy", "--requested", REQ, "--effort", "xhigh", *args)
        self.assertEqual(r.returncode, 0, r.stderr)
        return json.loads(r.stdout)

    def check(self, *args, env=None, owner=UID):
        r = run("check", "--requested", REQ, "--effort", "xhigh", "--home", self.home, "--workdir", self.workdir,
                "--managed-dir", self.managed, "--owner-uid", str(owner), "--json", *args, env=env)
        return r.returncode, (json.loads(r.stdout) if r.stdout.strip() else None), r.stderr

    def failed(self, rep):
        return [c["detail"] for c in rep["checks"] if not c["ok"]]

    def with_policy(self, **changes):
        p = dict(self.policy)
        for k, v in changes.items():
            if v is None:
                p.pop(k, None)
            else:
                p[k] = v
        write_json(os.path.join(self.managed, "managed-settings.json"), p)

    def test_the_policy_pins_the_model_exactly_and_turns_the_substitutes_off(self):
        p = self.policy
        self.assertEqual((p["availableModels"], p["availableModelsMatch"], p["enforceAvailableModels"], p["model"]),
                         ([REQ], "exact", True, REQ))
        self.assertNotIn("deniedModels", p, "an id without a minor version would block later minor versions too")
        self.assertNotIn("fallbackModel", p)
        self.assertNotIn("advisorModel", p)
        self.assertEqual(p["env"], {"CLAUDE_CODE_DISABLE_ADVISOR_TOOL": "1"})
        self.assertEqual((p["switchModelsOnFlag"], p["syncClaudeAiSkills"], p["syncClaudeAiPlugins"],
                          p["disableClaudeAiConnectors"], p["enableArtifact"]), (False, False, False, True, False))
        self.assertEqual((p["effortLevel"], p["requiredMinimumVersion"]), ("xhigh", "2.1.283"))
        self.assertEqual(self.make_policy("--allow", "claude-haiku-4-5")["availableModels"], [REQ, "claude-haiku-4-5"])
        r = run("policy", "--requested", REQ, "--effort", "max")
        self.assertNotIn("effortLevel", json.loads(r.stdout), "max is not a settings value")
        self.assertIn("--effort max", r.stderr)

    def test_a_machine_set_up_with_the_policy_passes(self):
        code, rep, _ = self.check("--client-version", "2.1.285", "--env")
        self.assertEqual((code, self.failed(rep)), (0, []))
        self.assertEqual(rep["managed_files"], [os.path.join(self.managed, "managed-settings.json")])

    def test_each_key_of_the_profile_is_checked(self):
        cases = {
            "availableModels": dict(availableModels=[REQ, "claude-opus-4-8"]),
            "availableModelsMatch": dict(availableModelsMatch="prefix"),
            "enforceAvailableModels": dict(enforceAvailableModels=None),
            "model": dict(model="claude-opus-5-5"),
            "also blocks": dict(deniedModels=["claude-fable-5"]),
            "fallbackModel": dict(fallbackModel=["claude-opus-4-8"]),
            "advisorModel null, CLAUDE_CODE_DISABLE_ADVISOR_TOOL null": dict(env={}),
            "advisorModel \"claude-opus-5-5\"": dict(advisorModel="claude-opus-5-5"),
            "switchModelsOnFlag": dict(switchModelsOnFlag=True),
            "syncClaudeAiSkills": dict(syncClaudeAiSkills=None),
            "syncClaudeAiPlugins": dict(syncClaudeAiPlugins=True),
            "disableClaudeAiConnectors": dict(disableClaudeAiConnectors=False),
            "enableArtifact": dict(enableArtifact=None),
            "effortLevel": dict(effortLevel="high"),
            "requiredMinimumVersion": dict(requiredMinimumVersion="2.1.200"),
        }
        for needle, change in cases.items():
            with self.subTest(needle):
                self.with_policy(**change)
                code, rep, _ = self.check()
                self.assertEqual(code, 1)
                self.assertTrue(any(needle in d for d in self.failed(rep)), self.failed(rep))
        self.with_policy(deniedModels=["claude-opus-4-8", "sonnet"])
        self.assertEqual(self.check()[0], 0, "a denied entry that cannot block the requested model is fine")

    def test_drop_ins_merge_as_the_client_merges_them(self):
        dropins = os.path.join(self.managed, "managed-settings.d")
        write_json(os.path.join(dropins, "10-other.json"), {"cleanupPeriodDays": 7})
        self.assertEqual(self.check()[0], 0)
        write_json(os.path.join(dropins, "20-models.json"), {"availableModels": ["claude-opus-4-8"]})
        code, rep, _ = self.check()
        self.assertEqual(code, 1)
        self.assertIn('availableModels ["claude-fable-5-1", "claude-opus-4-8"]', " ".join(self.failed(rep)), "lists join")
        os.remove(os.path.join(dropins, "20-models.json"))
        write_json(os.path.join(dropins, ".hidden.json"), {"model": "claude-opus-4-8"})
        self.assertEqual(self.check()[0], 0, "hidden files are not read")

    def test_the_managed_file_must_be_root_owned_and_readable_by_the_runner(self):
        path = os.path.join(self.managed, "managed-settings.json")
        for mode in (0o600, 0o664, 0o646):
            with self.subTest(mode=oct(mode)):
                os.chmod(path, mode)
                self.assertEqual(self.check()[0], 1)
        os.chmod(path, 0o644)
        code, rep, _ = self.check(owner=UID + 1)
        self.assertEqual(code, 1)
        self.assertIn("owner uid %d" % UID, self.failed(rep)[0])
        write_json(path, "{ not json")
        code, rep, _ = self.check()
        self.assertEqual(code, 1)
        self.assertTrue(any("does not parse" in d for d in self.failed(rep)))
        os.remove(path)
        code, rep, _ = self.check()
        self.assertEqual(code, 1)
        self.assertIn("no managed settings", self.failed(rep)[0])

    def test_the_runners_home_must_carry_no_override_and_no_earlier_session(self):
        cfg = os.path.join(self.home, ".claude")
        user = os.path.join(cfg, "settings.json")
        overrides = [{"model": "claude-opus-5-5"}, {"advisorModel": "opus"}, {"fallbackModel": ["claude-opus-4-8"]},
                     {"hooks": {"Stop": []}}, {"enabledPlugins": {"x@y": True}}, {"env": {"ANTHROPIC_MODEL": "opus"}},
                     {"effortLevel": "high"}, {"modelSettings": {REQ: {"effortLevel": "high"}}}, "{ broken"]
        for s in overrides:
            with self.subTest(settings=s):
                write_json(user, s)
                self.assertEqual(self.check()[0], 1)
        write_json(user, {"modelSettings": {"claude-opus-5-5": {"effortLevel": "high"}}, "enabledPlugins": {"x@y": False}})
        self.assertEqual(self.check()[0], 0, "another model's effort and a disabled plugin do not touch the attempt")
        os.makedirs(os.path.join(cfg, "skills"))
        self.assertEqual(self.check()[0], 0, "an empty folder is no customization")
        write_json(os.path.join(cfg, "skills", "x", "SKILL.md"), "x")
        self.assertEqual(self.check()[0], 1)
        shutil.rmtree(os.path.join(cfg, "skills"))
        write_json(os.path.join(cfg, "remote-settings.json"), {"permissions": {"deny": []}})
        code, rep, _ = self.check()
        self.assertEqual(code, 1)
        self.assertIn("outranks the managed file", self.failed(rep)[0])
        os.remove(os.path.join(cfg, "remote-settings.json"))
        slug = re.sub(r"[^A-Za-z0-9]", "-", self.workdir)
        write_json(os.path.join(cfg, "projects", slug, "memory", "MEMORY.md"), "notes")
        self.assertEqual(self.check()[0], 1, "memory of an earlier session")
        shutil.rmtree(os.path.join(cfg, "projects"))
        write_json(os.path.join(self.home, ".claude.json"), {"mcpServers": {"x": {}}, "projects": {}})
        code, rep, _ = self.check()
        self.assertEqual(code, 1)
        self.assertEqual(len(self.failed(rep)), 2, "an MCP server, and the work folder not trusted")

    def test_the_work_folder_must_carry_no_project_customization(self):
        for name in (".mcp.json", "CLAUDE.md", ".claude/settings.local.json"):
            with self.subTest(name):
                path = os.path.join(self.workdir, name)
                write_json(path, {})
                code, rep, _ = self.check()
                self.assertEqual(code, 1)
                self.assertIn(name, self.failed(rep)[0])
                os.remove(path)

    def test_the_launcher_environment(self):
        self.assertEqual(self.check("--env")[0], 0)
        self.assertEqual(self.check("--env", env={"CLAUDE_CODE_EFFORT_LEVEL": "xhigh"})[0], 0)
        for env in ({"ANTHROPIC_MODEL": "opus"}, {"ANTHROPIC_DEFAULT_OPUS_MODEL": "claude-opus-4-8"}, {"ANTHROPIC_BASE_URL": "http://x"},
                    {"CLAUDE_CODE_USE_BEDROCK": "1"}, {"CLAUDE_CODE_EFFORT_LEVEL": "high"}, {"CLAUDE_CODE_SUBAGENT_MODEL": "haiku"}):
            with self.subTest(env=env):
                code, rep, _ = self.check("--env", env=env)
                self.assertEqual(code, 1)
                self.assertIn(list(env)[0], self.failed(rep)[0])
        self.assertEqual(self.check(env={"ANTHROPIC_MODEL": "opus"})[0], 0, "the environment is read only with --env")

    def test_the_client_version(self):
        self.assertEqual(self.check("--client-version", "2.1.283")[0], 0)
        self.assertEqual(self.check("--client-version", "2.1.282 (Claude Code)")[0], 1)
        self.with_policy(requiredMinimumVersion="2.1.290")
        self.assertEqual(self.check("--client-version", "2.1.285")[0], 1, "the policy's own minimum counts too")
        self.assertEqual(self.check("--client-version", "unknown")[0], 2)

    def test_unusable_input_exits_2_and_it_writes_nothing(self):
        code, _, err = self.check("--home", os.path.join(self.tmp.name, "missing"))
        self.assertEqual((code, "not a folder" in err), (2, True))
        before = sorted((os.path.join(b, n), os.path.getmtime(os.path.join(b, n)))
                        for b, _, names in os.walk(self.tmp.name) for n in names)
        self.check("--env", "--client-version", "2.1.285")
        r = run("check", "--requested", REQ, "--home", self.home, "--workdir", self.workdir, "--managed-dir", self.managed,
                "--owner-uid", str(UID))
        self.assertIn("RESULT  every check holds", r.stdout)
        self.assertEqual(before, sorted((os.path.join(b, n), os.path.getmtime(os.path.join(b, n)))
                                        for b, _, names in os.walk(self.tmp.name) for n in names))


if __name__ == "__main__":
    unittest.main()
