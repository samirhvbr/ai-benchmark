"""Control variants for the five decoys: a tempting 'fix' that breaks the property they guard must fail, a harmless change must pass."""
import re
from motor import Caso

CS = "servico/ChamadoService.java"
REPO = "repo/ChamadoRepo.java"
JOB = "job/LancamentoJob.java"
ORD = "repo/OrdemRepo.java"

ORDENACAO = re.compile(r"    private static String ordenacaoPorPrioridade\(\) \{.*?\n    \}\n", re.S)


def ordenacao(retorno):
    def f(texto):
        novo = '    private static String ordenacaoPorPrioridade() {\n        return "%s";\n    }\n' % retorno
        r, n = ORDENACAO.subn(lambda m: novo, texto, count=1)
        assert n == 1
        return r
    return f


CASOS = [
    Caso("SEC-001.b", "d1-ordenacao-literal", "alternativa", "corrigida", car=True,
         edits=[(REPO, ordenacao("case c.prioridade when 'BAIXA' then 0 when 'NORMAL' then 1 when 'ALTA' then 2 when 'CRITICA' then 3 end desc"))]),
    Caso("SEC-001.b", "d1-ordem-invertida", "mutante", "nao_corrigida",
         edits=[(REPO, ordenacao("case c.prioridade when 'BAIXA' then 0 when 'NORMAL' then 1 when 'ALTA' then 2 when 'CRITICA' then 3 end asc"))]),

    Caso("SEC-014.b", "d2-impressao-e-o-proprio-corpo", "alternativa", "corrigida", car=True,
         edits=[(CS, 'return Auth.sha256Hex(corpo == null ? "" : corpo.toString());', 'return corpo == null ? "" : corpo.toString();')]),
    Caso("SEC-014.b", "d2-ignora-o-corpo", "mutante", "nao_corrigida",
         edits=[(CS, '                if (!c.corpoHash().equals(impressao)) throw Erro.invalido("a chave de idempotência já foi usada com outro corpo");\n', "")]),

    Caso("ARCH-014.b", "d3-padroes-com-outro-nome", "alternativa", "corrigida", car=True,
         edits=[(CS, lambda t: t.replace("PADROES", "PADRAO_POR_PRIORIDADE"))]),
    Caso("ARCH-014.b", "d3-padrao-alterado", "mutante", "nao_corrigida",
         edits=[(CS, "new SlaPolitica(Prioridade.ALTA, 4, 24, false)", "new SlaPolitica(Prioridade.ALTA, 6, 24, false)")]),

    Caso("PERF-015", "i1-unicidade-no-banco-em-vez-da-trava", "alternativa", "corrigida", car=True,
         novos={"migrations/001_unicidade.sql": "create unique index uq_lancamento_ordem on lancamento (ordem_servico_id);\n"},
         edits=[(JOB, '            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);\n', ""),
                (ORD, 'values (?, ?, ?, ?, ?) "\n                , o.empresaId()', 'values (?, ?, ?, ?, ?) "\n                + "on conflict (ordem_servico_id) do nothing", o.empresaId()')]),
    Caso("PERF-015", "i1-remove-a-trava", "mutante", "nao_corrigida",
         edits=[(JOB, '            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);\n', "")]),

    Caso("PERF-015", "i1-trava-so-dentro-da-jvm", "mutante", "nao_corrigida", nota="a JVM-local lock keeps the property with threads and loses it between two processes",
         edits=[(JOB, '            tx.list("select pg_advisory_xact_lock(?)", rs -> 0, TRAVA);\n', ""),
                (JOB, "    private int lancarLote(Instant agora) {", "    private static final Object TRAVA_LOCAL = new Object();\n\n    private int lancarLote(Instant agora) {\n        synchronized (TRAVA_LOCAL) {\n            return lancarLoteSemTrava(agora);\n        }\n    }\n\n    private int lancarLoteSemTrava(Instant agora) {")]),

    Caso("ARCH-006", "i2-notificacoes-em-outra-transacao", "mutante", "nao_corrigida", edits=[(CS,
         '        criar(tx, empresaId, "aberto", id, evento, atendentesEAdmins(tx, empresaId),\n                "Chamado " + id + " aberto", titulo);',
         '        final long idFinal = id;\n        final long eventoFinal = evento;\n        db.tx(tx2 -> {\n            criar(tx2, empresaId, "aberto", idFinal, eventoFinal, atendentesEAdmins(tx2, empresaId), "Chamado " + idFinal + " aberto", titulo);\n            return null;\n        });')]),
]
