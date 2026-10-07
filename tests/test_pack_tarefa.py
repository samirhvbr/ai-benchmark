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


if __name__ == "__main__":
    unittest.main()
