# LEB — Harness de avaliação

Ferramentas do ciclo: `pack.py` monta o que vai **para** o modelo; `leb_harness.py` e
`score.py` avaliam o que **volta**.

| Ferramenta | Papel | Pelo `leb` |
| --- | --- | --- |
| [`pack.py`](pack.py) | monta o pacote público (`PROTOCOL §1`): `code/` + `manifest.md` + `TAREFA.md` | `./leb pacote <ID>` |
| [`leb_harness.py`](leb_harness.py) | passos mecânicos do pipeline (`PROTOCOL §5`) → relatório JSON | `./leb avaliar <ID> <entrega>` |
| [`score.py`](score.py) | montador do scorecard de 1000 pontos | `./leb scorecard <ID> <run> --veredito …` |

No dia a dia use o [`../leb`](../leb) da raiz: ele resolve os caminhos, guarda tudo em
`runs/<ID>/` e diz qual é o próximo passo. Os scripts abaixo continuam sendo a interface
real — o `leb` só os chama.

## Empacotador (`pack.py`)

```sh
python3 harness/pack.py --instance instances/LEB-100-A                       # → runs/LEB-100-A/pacote
python3 harness/pack.py --instance instances/LEB-100-A --mode A --turnos 20  # modo agêntico
python3 harness/pack.py --instance instances/LEB-100-A --out /tmp/leb-pkg    # destino avulso
```

Sem `--out`, o destino é `runs/<instância>/pacote` e é **sempre refeito** (o pacote é derivado
da instância, nunca fonte). Um `--out` avulso só é sobrescrito com `--force`, ou se já for um
pacote montado por aqui.

O que ele faz:

1. Copia `code/` + `manifest.md` da instância — e nada mais. O destino é recriado do zero.
2. Renderiza `TAREFA.md` a partir de [`../protocol/TAREFA.md`](../protocol/TAREFA.md),
   preenchendo o **vínculo** (instância, nível, versão, spec, SHA-256 da matriz, modo). Os
   metadados vêm do *cabeçalho* de `private/matrix.json` — nunca das falhas. Sem `private/`
   à mão, informe por flag (`--instance-id`, `--matrix-sha`, …).
3. Scans the result (see *Package leak guard* below) and, if anything looks like the answer key,
   **deletes the folder** and exits with an error.
4. Escreve `.leb-pacote.sha256` (sha por arquivo) e imprime o `package_sha256` no stdout.

O pacote é **determinístico** — mesma instância + mesmo modo ⇒ bytes idênticos, sem timestamp
embutido. Dois avaliadores em máquinas diferentes chegam ao mesmo `package_sha256`, que é
parâmetro obrigatório do run (`PROTOCOL §3`). Sai **1** em erro (vazamento, instância inválida,
modo A sem `--turnos`, placeholder não resolvido), **0** em sucesso.

> Nunca edite a `TAREFA.md` de dentro do pacote: a tarefa é do padrão, não da instância
> (`../SPEC.md §9.4`). Ajuste sempre `protocol/TAREFA.md` e reempacote.

## Avaliação mecânica (`leb_harness.py`)

Roda os passos que **não** exigem juiz e emite um relatório JSON:

| Passo (PROTOCOL §5) | O que faz | Estado |
| --- | --- | --- |
| 1. diff da superfície pública → COMP | hoje surge como regressão na caracterização | parcial |
| 2. caracterização antes/depois → regressão (C4, PEN-002) | `characterization/run.php` no legado e na entrega | ✅ |
| 3. `private/verify/probes.php` → C3 corrigiu de fato | probes PLANTADA→CORRIGIDA | ✅ |
| 6. calibração + dificuldade | cobertura por dificuldade (corrigidas por probe) | ✅ parcial |
| 4–5. matching relatório×matriz + rubrica EXPL | **juiz** (LLM/humano) segue `../scoring/JUDGE.md` → veredito JSON | ✅ interface |
| 7. normalização final 1000 pts | `score.py` junta mecânico + veredito + matriz → scorecard | ✅ |

Filosofia: **só-stdlib, agnóstico de instância**. O orquestrador (Python) chama os
`.php` da própria instância como subprocessos dentro do docker dela — a linguagem da
instância pode ser qualquer uma; o harness só depende de dois contratos de saída:
`run.php` sai ≠ 0 se houver regressão, e `probes.php` com `LEB_PROBE_JSON=1` emite JSON.

## Pré-requisitos

- Docker (usa o `characterization/docker-compose.yml` da instância: MySQL 8 + PHP 8.4) — só para `leb_harness.py`; o `pack.py` não precisa
- Python 3 (stdlib apenas)

## Uso

```sh
# autoteste — avalia o próprio legado (esperado: tudo PLANTADA, sem regressão)
python3 harness/leb_harness.py --instance instances/LEB-100-A

# avaliar a entrega de um modelo (a pasta code/ que ele devolveu)
python3 harness/leb_harness.py \
    --instance instances/LEB-100-A \
    --submission /caminho/para/code_entregue \
    --out runs/LEB-100-A/<modelo>-1/mecanico.json
```

A entrega (`--submission`) é uma pasta `code/` completa (o modelo edita o legado
in-place). É montada **read-only** em `/submission` no container e apontada por
`LEB_CODE_DIR`; nada é copiado para dentro do repositório.

## Saída

Um relatório JSON com: `characterization` (baseline vs. entrega, `regression`),
`probes` (por falha, com `difficulty`), `difficulty_corrected`, `mechanical_criteria`
(C3/C4 por falha coberta), `timing_s` e `pending_judge` (o que ainda falta do juiz).
Código de saída **2** se a entrega regrediu (sinal para CI), **0** caso contrário.

> O relatório mecânico **não é** o scorecard final de 1000 pontos — é a evidência
> objetiva sobre a qual o juiz (passos 4–5) monta o scorecard completo
> (`../scoring/scorecard-template.md`).

## Montar o scorecard final (`score.py`)

Com o relatório mecânico + o veredito do juiz (`../scoring/JUDGE.md`,
formato `../scoring/judge.schema.json`), o montador emite o scorecard de 1000 pontos:

```sh
python3 harness/score.py \
    --matrix     instances/LEB-100-A/private/matrix.json \
    --mechanical relatorio_mecanico.json \
    --judge      veredito.json \
    --out        scorecard.json
```

Determinístico: aplica toda a aritmética de `../scoring/SCORING.md` (pontos por critério,
normalização por categoria, COMP, penalidades, TOTAL, selo, Brier, eixo de dificuldade). A
evidência mecânica tem prioridade — C3 das falhas com probe e C4 (regressão) sobrescrevem o juiz.

## Custo / tempo

O `timing_s` do relatório mede o **harness** (fases docker), não o modelo. As
métricas de custo do *modelo* (tokens, US$/run, tok/s, wall-clock da inferência)
vivem no bloco `cost_time` do scorecard (`../scoring/scorecard.schema.json`,
informativo — não pontua) e são preenchidas quando o modelo é de fato executado.

## Package leak guard

`pack.py` scans the package before it finishes, and aborts (deleting the package) on:

- **a path word** — `matrix`, `matriz`, `private`, `verify`, `characterization` or `probes` as a whole word of any path segment
  (`matrix_x.json` and a `verify/` folder are refused; `VerifyToken.java` is not). An instance whose code has a legitimate name equal to
  one of them lists the path in `pack_allow_paths` in the matrix header. The list exempts a path, never content;
- **a content marker** — any text file containing a text of the matrix (`evidence`, `expected_fix`, `location`, `notes`, 20 characters
  or more; comment leaders and line wrapping do not hide it) or a path of the private destination (the instance's `private/`,
  `LEB_PRIVATE_RESULTS`, `LEB_RUNS_DIR`). The public matrix hash in `TAREFA.md` is not a marker.

Before every scan the guard runs on a temporary copy with a planted `private/` folder and a planted marker and must report both,
otherwise the packaging aborts: a guard that has not shown it can see does not count.

## Per-instance runner (`private/runner.json`)

Without the file, `leb_harness.py` runs as always (docker compose + PHP). With it, the instance says how to characterize and verify:

```json
{"runner": {"caracterizacao": {"cmd": ["..."]}, "verificacao": {"cmd": ["..."]}, "timeout_s": 1800}}
```

Each command runs with `private/` as its working directory and receives `LEB_ENTREGA_DIR` (the code under test), `LEB_INSTANCIA_DIR`
and `LEB_RUN_DIR` (a scratch folder the harness creates and removes). The characterization prints `{"passed": N, "failed": M}`; the verification prints
`{"probes": [...]}` (`../scoring/probe-result.schema.json`: `id`, `corrigida` and/or `result`, optional `proves`, `unobserved`,
`affected`). The exit code of a command never decides regression; the report does (the submission fails more than the baseline).
Anything unreadable (no JSON, an invalid or repeated probe id, a crash, a timeout — the whole process group is killed) makes the report
`inconclusive` and the harness exit **3**: `leb` says so and `score.py` refuses to score it. A planted flaw with no probe is listed
under `unverified`.

## Tests and tools

```sh
python3 tests/run_all.py                 # tooling tests: standard library, no network, no Docker, synthetic and public fixtures only
python3 tools/export-results.py --check  # the published LEB-100-A results are still what the tooling produces
python3 tools/saturacao.py               # saturation and dispersion of a published instance (read-only)
python3 tools/export-results.py --publish-aggregate <instance>   # active instances only: writes aggregate.json, nothing else
```
