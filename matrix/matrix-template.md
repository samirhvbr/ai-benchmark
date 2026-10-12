# Matriz Oficial de Falhas — <INSTÂNCIA> v<VERSÃO>

> **The answer key.** This file lives in `private/`: it never goes into the package the agent receives, and it is committed to the public repository with the rest of the instance when the instance is created, before its first run (MATRIX.md §4, items 1 and 2).

| Spec LEB | Instância | Nível | Versão | Stack | Hash publicado em |
| --- | --- | --- | --- | --- | --- |
| 1.1.0 | LEB-___-_ | ___ | 1.0 | ______ | <commit/URL> |

## Falhas plantadas

`Dif.` = discoverability (Fácil/Moderada/Difícil/Especialista), ortogonal à Sev.; não pontua (SCORING §9.2).

| ID | Existe | Sev. | Dif. | Pts | Tpl | Localização | Evidência | Correção esperada | Verify |
| --- | --- | --- | --- | ---: | --- | --- | --- | --- | --- |
| SEC-001 | Sim | Crítica | Fácil | 10 | C | `code/src/relatorio.php:88` | `"WHERE id=" . $_GET['id']` | prepared statement via mysqli | `verify/sec-001_exploit.sh` |
| PERF-004 | Sim | Média | Moderada | 6 | C | `code/src/dashboard.php:31` | mesma query 4× por request | executar 1×, reutilizar | `verify/perf-004_querylog.sh` |
| BUG-003 | Sim | Alta | Difícil | 8 | C | `code/src/export.php:112` | conexão aberta sem close no caminho de erro | liberar em todos os caminhos | `verify/bug-003_leak.sh` |
| ARCH-002 | Sim | Alta | Fácil | 10 | R | `code/src/pedidos.php:1-240` | controller com SQL + regra + HTML | extrair serviço + consulta | revisão estrutural |
| … | | | | | | | | | |

## Iscas (exists: false)

Reportar qualquer uma → PEN-004 (−5, teto −25).

| ID | Por que é plausível (mas não existe) |
| --- | --- |
| ARCH-006 | módulos trocam dados, mas só via interface pública — não há acoplamento interno |
| SEC-011 | há chamada HTTP de saída, mas o host é fixo em config |
| … | |

## Totais por categoria (pré-normalização)

| Categoria | Falhas | Pontos-base |
| --- | ---: | ---: |
| SEC | _ | _ |
| ARCH | _ | _ |
| PERF | _ | _ |
| BUG | _ | _ |
| CLN | _ | _ |
| **Iscas** | _ | — |

## Notas de avaliação

- Correções alternativas aceitas: <listar por ID>
- Sobreposições conhecidas e como atribuir: <…>
- Ordem de execução dos verifies: <…>
