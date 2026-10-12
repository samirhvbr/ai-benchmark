"""Comments the planting leaves stale. Applied after every other edit, including the ARCH-001 merge.
Some comments in the reference describe the correct behavior of a place that the planting then breaks; left alone they would tell the reader
exactly where the flaw is and what the fix looks like. They become neutral descriptions of what the code does."""
from plantar import SRC

FLAWS = []

TROCAS = [
    (SRC + "job/LancamentoJob.java",
     "/** Lança, uma vez cada, as ordens concluídas com valor. O trabalho e o ponto de retomada vão na mesma transação. */",
     "/** Lança, uma vez cada, as ordens concluídas com valor. */"),
    (SRC + "repo/NotificacaoRepo.java",
     "/** Pega as pendentes que já podem sair, travando as linhas para que outro despacho não pegue as mesmas. */",
     "/** Pega as pendentes que já podem sair. */"),
    (SRC + "relatorio/CsvExportador.java",
     "/** Texto livre: neutraliza fórmula de planilha e protege o delimitador, as aspas e a quebra de linha. */",
     "/** Texto livre: protege o delimitador, as aspas e a quebra de linha. */"),
    (SRC + "relatorio/RelatorioService.java",
     "/** O relatório de SLA de uma empresa e mês é refeito, no máximo, a cada 30 segundos pelo Clock. */",
     "/** O relatório de SLA é refeito, no máximo, a cada 30 segundos pelo Clock. */"),
    (SRC + "relatorio/RelatorioService.java",
     "/** O mês (YYYY-MM) no fuso da empresa, como [início, fim) em instantes. */",
     "/** O mês (YYYY-MM) como [início, fim) em instantes. */"),
    (SRC + "servico/ChamadoService.java",
     "/** Cumprido quando resolvido até o prazo, inclusive. */",
     "/** Cumprido quando resolvido dentro do prazo. */"),
]


def neutralizar(arquivos, ler):
    for caminho, velho, novo in TROCAS:
        texto = ler(caminho)
        assert texto.count(velho) == 1, "%s: %d ocorrências de %r" % (caminho, texto.count(velho), velho[:60])
        arquivos[caminho] = texto.replace(velho, novo)


POS = [("COMENTARIOS", neutralizar)]
