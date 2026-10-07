"""E2: one resolver for the instance (legacy and split layouts, LEB_INSTANCES_PATH, LEB_RUNS_DIR).

Without the environment variables nothing changes for LEB-100-A.
"""

import os
import sys
import tempfile
import unittest

import helpers
from helpers import (ROOT, entry, judged, make_instance, read_json, run_py, synthetic_matrix, synthetic_mech,
                     synthetic_verdict, write_json)

sys.path.insert(0, os.path.join(ROOT, "harness"))
import instances  # noqa: E402

LEB = os.path.join(ROOT, "leb")


class Resolver(unittest.TestCase):
    def setUp(self):
        self._old = {k: os.environ.pop(k, None) for k in ("LEB_INSTANCES_PATH", "LEB_RUNS_DIR")}

    def tearDown(self):
        for k, v in self._old.items():
            if v is not None:
                os.environ[k] = v
            else:
                os.environ.pop(k, None)

    def test_leb_100_a_resolves_to_the_same_places_as_before(self):
        i = instances.resolve("LEB-100-A", ROOT)
        base = os.path.join(ROOT, "instances", "LEB-100-A")
        self.assertEqual((i.layout, i.name), ("legacy", "LEB-100-A"))
        self.assertEqual(i.public_dir, base)
        self.assertEqual(i.private_dir, os.path.join(base, "private"))
        self.assertEqual(i.code_dir, os.path.join(base, "code"))
        self.assertEqual(i.manifest_path, os.path.join(base, "manifest.md"))
        self.assertEqual(i.matrix_path, os.path.join(base, "private", "matrix.json"))
        # A path works as well as a name, as it always did.
        self.assertEqual(instances.resolve(os.path.join("instances", "LEB-100-A"), ROOT).root, base)

    def test_split_layout_found_through_the_search_path(self):
        with tempfile.TemporaryDirectory() as t:
            make_instance(t, "LEB-TEST-A", layout="split")
            os.environ["LEB_INSTANCES_PATH"] = t
            i = instances.resolve("LEB-TEST-A", ROOT)
            self.assertEqual(i.layout, "split")
            self.assertEqual(i.public_dir, os.path.join(t, "instances", "LEB-TEST-A", "public"))
            self.assertEqual(i.private_dir, os.path.join(t, "instances", "LEB-TEST-A", "private"))
            self.assertEqual(i.code_dir, os.path.join(i.public_dir, "code"))
            self.assertEqual([x.name for x in instances.list_instances(ROOT)], ["LEB-100-A", "LEB-TEST-A"])

    def test_the_search_path_comes_before_the_repository(self):
        with tempfile.TemporaryDirectory() as t:
            make_instance(t, "LEB-100-A", layout="split")
            os.environ["LEB_INSTANCES_PATH"] = t
            self.assertEqual(instances.resolve("LEB-100-A", ROOT).layout, "split")
            del os.environ["LEB_INSTANCES_PATH"]
            self.assertEqual(instances.resolve("LEB-100-A", ROOT).layout, "legacy")

    def test_an_unknown_instance_is_refused(self):
        r = run_py(LEB, "pacote", "LEB-NAO-EXISTE")
        self.assertNotEqual(r.returncode, 0)
        self.assertIn("LEB-NAO-EXISTE", r.stderr)

    def test_pack_and_leb_work_end_to_end_on_a_split_instance_outside_the_repository(self):
        with tempfile.TemporaryDirectory() as t:
            private_root, runs = os.path.join(t, "private-root"), os.path.join(t, "runs")
            make_instance(private_root, "LEB-TEST-A", layout="split",
                          matrix=synthetic_matrix([entry("BUG-011.a", "Crítica"), entry("SEC-001")], task_version="1.1.0"))
            env = {"LEB_INSTANCES_PATH": private_root, "LEB_RUNS_DIR": runs}

            r = run_py(LEB, "pacote", "LEB-TEST-A", "--mode", "A", "--turnos", "30", env=env)
            self.assertEqual(r.returncode, 0, r.stderr)
            pkg = os.path.join(runs, "LEB-TEST-A", "pacote")
            self.assertEqual(sorted(os.listdir(pkg)), [".leb-pacote.sha256", "TAREFA.md", "code", "manifest.md"])
            self.assertTrue(os.path.isfile(os.path.join(runs, "LEB-TEST-A", "COMO-RODAR.md")))
            self.assertIn("versao=1.1.0", helpers.read_text(pkg, "TAREFA.md").splitlines()[0])
            # Nothing of the evaluator side travels with the package.
            self.assertFalse(os.path.exists(os.path.join(pkg, "private")))

            # leb scorecard reads the matrix from the private side of the split layout.
            run_dir = os.path.join(runs, "LEB-TEST-A", "run-1")
            os.makedirs(run_dir)
            full = dict(C1="full", C2="full", C3="full", C4="full", C5="full")
            write_json(os.path.join(run_dir, "mecanico.json"),
                       synthetic_mech(probes=[{"id": "BUG-011.a", "corrigida": True, "msg": ""}, {"id": "SEC-001", "corrigida": True, "msg": ""}]))
            veredito = os.path.join(t, "veredito.json")
            write_json(veredito, synthetic_verdict([judged("BUG-011.a", **full), judged("SEC-001", **full)]))
            r = run_py(LEB, "scorecard", "LEB-TEST-A", "run-1", "--veredito", veredito, env=env)
            self.assertEqual(r.returncode, 0, r.stderr)
            card = read_json(os.path.join(run_dir, "scorecard.json"))
            self.assertEqual(card["categories"]["BUG"]["score"], 150)


if __name__ == "__main__":
    unittest.main()
