"""E3: the instance declares the version of the canonical task it is evaluated against.

1.0.0 stays at protocol/TAREFA.md, untouched; later versions live in protocol/tasks/.
"""

import os
import re
import tempfile
import unittest

import helpers
from helpers import ROOT, make_instance, read_text, run_py, synthetic_matrix, entry

CLAUSE = ("Não reescrever o sistema não significa preservar sua organização interna. É permitido\n"
          "   extrair responsabilidades, reorganizar componentes e dependências e manter fachadas compatíveis,\n"
          "   desde que os contratos públicos sejam preservados e as mudanças resolvam problemas concretos.")


def pack(inst, out, *extra):
    return run_py(helpers.PACK, "--instance", inst, "--out", out, "--mode", "A", "--turnos", "30", *extra)


class TaskVersions(unittest.TestCase):
    def test_task_1_0_0_file_keeps_its_marker(self):
        head = read_text(ROOT, "protocol", "TAREFA.md").splitlines()[0]
        self.assertIn("versao=1.0.0", head)

    def test_task_1_1_0_carries_the_approved_clause_and_the_freedom_to_move_files(self):
        text = read_text(ROOT, "protocol", "tasks", "TAREFA-1.1.0.md")
        self.assertIn("versao=1.1.0", text.splitlines()[0])
        self.assertIn(CLAUSE, text)
        self.assertIn("Você pode criar, dividir, mover e renomear arquivos", text)
        self.assertNotIn("Não renomeie nem mova arquivos", text)
        # The canonical statement (the quoted block) does not change between versions.
        old = read_text(ROOT, "protocol", "TAREFA.md")
        quote = lambda t: t[t.index("> Você é responsável"):t.index("> Não reescreva o sistema. Evolua-o.")]
        self.assertEqual(quote(old), quote(text))

    def test_an_instance_without_task_version_gets_1_0_0(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t)
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertEqual(r.returncode, 0, r.stderr)
            tarefa = read_text(t, "pkg", "TAREFA.md")
            self.assertIn("versao=1.0.0", tarefa.splitlines()[0])
            self.assertIn("tarefa 1.0.0", tarefa)

    def test_an_instance_declaring_1_1_0_gets_the_1_1_0_task(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("SEC-001")], task_version="1.1.0"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertEqual(r.returncode, 0, r.stderr)
            tarefa = read_text(t, "pkg", "TAREFA.md")
            self.assertIn("versao=1.1.0", tarefa.splitlines()[0])
            self.assertIn("tarefa 1.1.0", tarefa)
            self.assertIn(CLAUSE, tarefa)
            self.assertEqual(re.findall(r"\{\{[A-Z_]+\}\}", tarefa), [], "a placeholder was left unresolved")

    def test_an_instance_with_private_but_no_matrix_is_refused_as_still_being_authored(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, layout="split")
            os.remove(os.path.join(inst, "private", "matrix.json"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("still being authored", r.stderr)
            self.assertFalse(os.path.exists(os.path.join(t, "pkg", "TAREFA.md")))
            # An explicit override still packages a public copy, as before.
            r = pack(inst, os.path.join(t, "pkg"), "--force", "--matrix-sha", "0" * 64)
            self.assertEqual(r.returncode, 0, r.stderr)

    def test_a_public_copy_without_private_still_packages_with_a_warning(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, layout="split")
            import shutil
            shutil.rmtree(os.path.join(inst, "private"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertIn("matrix.json ausente", r.stderr)

    def test_an_unknown_version_is_refused(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("SEC-001")], task_version="9.9.9"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("9.9.9", r.stderr)

    def test_a_template_of_another_version_than_declared_is_refused(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("SEC-001")], task_version="1.1.0"))
            r = pack(inst, os.path.join(t, "pkg"), "--tarefa", os.path.join(ROOT, "protocol", "TAREFA.md"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("1.1.0", r.stderr)



class TrackTasks(unittest.TestCase):
    """The next series' track statements: English templates, named TASK.md in the package (protocol/tasks/TASK-D-1.0.0.md)."""

    def test_an_instance_of_the_default_track_gets_the_english_task_as_task_md(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("BUG-001")], task_version="D-1.0.0"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertEqual(r.returncode, 0, r.stderr)
            self.assertFalse(os.path.exists(os.path.join(t, "pkg", "TAREFA.md")))
            task = read_text(t, "pkg", "TASK.md")
            self.assertIn("version=D-1.0.0", task.splitlines()[0])
            self.assertIn("| Instance | **LEB-TEST-A** (level LEB-300, version 1.0) |", task)
            self.assertIn("task D-1.0.0", task)
            self.assertIn('"instance": "LEB-TEST-A"', task)
            self.assertEqual(re.findall(r"\{\{[A-Z_]+\}\}", task), [], "a placeholder was left unresolved")

    def test_the_default_statement_asks_for_no_security_work(self):
        task = read_text(ROOT, "protocol", "tasks", "TASK-D-1.0.0.md").lower()
        for word in ("security", "vulnerab", "exploit", "attack", "secure"):
            self.assertNotIn(word, task)
        self.assertIn("architecture, bugs, performance and code quality", task)

    def test_a_track_version_without_its_template_is_refused_and_leaves_no_folder(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("BUG-001")], task_version="S-9.9.9"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("TASK-S-9.9.9.md", r.stderr)
            self.assertFalse(os.path.exists(os.path.join(t, "pkg")))

    def test_a_malformed_track_version_is_refused(self):
        with tempfile.TemporaryDirectory() as t:
            inst = make_instance(t, matrix=synthetic_matrix([entry("BUG-001")], task_version="X-1.0.0"))
            r = pack(inst, os.path.join(t, "pkg"))
            self.assertNotEqual(r.returncode, 0)
            self.assertIn("invalid task_version", r.stderr)


if __name__ == "__main__":
    unittest.main()
