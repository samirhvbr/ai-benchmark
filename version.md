# Versão — AI-BENCHMARK

**Versão atual:** `0.2.115`

Padrão de avaliação de engenharia de software para LLMs (spec RFC, instâncias LEB, harness e scorecard).

> ⚠️ A **spec RFC tem versão própria** (hoje `1.2.0`, declarada no `README.md`), e as
> instâncias também (LEB-100-A `v1.1`). Este arquivo versiona o **repositório**, não a
> spec — os números são independentes de propósito.

> Este arquivo é a **fonte da verdade** da versão do projeto: quem precisar exibir ou
> reportar a versão extrai o **primeiro número semver (`X.Y.Z`)** encontrado aqui.
> Mantenha a linha **"Versão atual"** como a primeira ocorrência de um número.
>
> `0.1.0` marca o início do **versionamento**, não o início do projeto — o que veio
> antes continua no `git log`.

---

## 1. Convenção de Versionamento (`X.Y.Z`)

| Componente | Significado | Como sobe |
|---|---|---|
| **X** | Release estável | Manual |
| **Y** | Mudança estrutural — Nova seção do conjunto, mudança de escopo, reorganização estrutural. | Manual |
| **Z** | Incremento a cada entrega (ver gatilhos) | A cada entrega |

### Gatilhos de bump do `Z`

- Criar ou remover um **documento** do conjunto.
- Mudar uma **regra, decisão ou procedimento** já publicado.
- Alterar **estrutura ou formato** que outra ferramenta consome.
- Adicionar ou alterar **dado/exemplo** que serve de referência.

> Correção de texto, comentário e formatação **não** exigem bump.

---

## 2. Formato de Commit Obrigatório

```
X.Y.Z - Descrição curta em português
```

**Regras inegociáveis:**

1. A versão **sempre** vem deste `version.md` — bumpe **no mesmo commit** da mudança.
2. Mensagem em **português**, específica o suficiente para `git log --grep`.
3. **Proibido** Conventional Commits (`feat:`, `fix:`, `chore:`…) e mensagens vagas
   ("ajuste", "update", "wip").
4. Um objetivo por commit.

> **A skill COMMITTER commita por você neste repo** (existe `.committer.yml` na raiz).
> Escreva a entrada de changelog abaixo ao concluir a entrega: é **dali** que a
> mensagem do commit sai, sem custo de modelo. Sem a entrada, a skill cai num
> fallback que gasta tokens e descreve pior do que você. Detalhe no bloco PS do
> `CLAUDE.md`.

---

## 3. Changelog

> Ordem decrescente (mais recente no topo).

### `0.2.115` — 2026-10-06 — Informative fields on the matrix reach the scorecard and the exports, and never the points

- A matrix entry may carry `dimensions` (`concorrencia`, `consistencia`, `resiliencia`), `decoy_kind` (`diagnostico`, `intervencao`; decoys
  only) and `informative_affected` (tests and contracts a fix touches). `harness/score.py` copies them to the finding and, only when they
  exist, adds `dimension_breakdown` (planted, detected, corrected per dimension) and `decoy_breakdown` (decoys and reported, per kind) to
  the scorecard. A mechanical report may also carry `informative_affected` per flaw.
- `tools/export-results.py` adds `dimensions` to the instance's flaws and `decoy_kinds` to `results.json`, and appends a `dimensions`
  column at the end of `flaws.csv`, all only when the matrix declares them. Without the fields the outputs are byte for byte what they
  were: `export-results.py --check` still passes on the 92 published LEB-100-A runs.
- No weight, point or penalty reads these fields (tested: same totals, categories, penalties and criteria with and without them).
  Schemas: `matrix/matrix.schema.json` and `scoring/scorecard.schema.json` gain the optional properties.
- Tests: `tests/test_informativos.py` (7 cases) and a synthetic published-tree fixture in `tests/helpers.py`.

### `0.2.114` — 2026-10-06 — Structural evidence can cap the refactoring criterion of Template R, and never raise it

- `harness/score.py`: a probe that carries `result` (`full|half|none`) **and** `proves` naming `R3` is evidence for the R3 of an
  ARCH or CLN flaw. `R3_final = min(R3_judge, evidence)` (order `none < half < full`; a judge that omitted R3 counts as `none`).
  Evidence never raises R3 and never fills R1, R2 or R4; the existing rule that R4 only counts when a refactoring was attempted
  applies to the lower R3. `proves` naming anything but `C3` or `R3`, or `R3` without `result`, makes the assembler refuse the report.
- The scorecard gets `evidence: {R3, judge_R3, applied_R3}` for each flaw that had evidence, and no key otherwise.
- The legacy boolean `corrigida` is still ignored for Template R and still decides C3 for Template C. Reports without `proves` are
  scored exactly as before: the 95 published scorecards are still reproduced (test) and the LEB-100-A results are untouched.
- `scoring/probe-result.schema.json` (new), `evidence` in `scoring/scorecard.schema.json` (optional), and one additive paragraph in
  `scoring/JUDGE.md`. No existing score or penalty changes.
- Tests: `tests/test_score_evidencia.py` (13 cases: the eight prototype cases plus half/half, schema checks and C3 control).

### `0.2.113` — 2026-10-06 — An instance can declare its own runner, and an unreadable measurement is inconclusive, never a pass

- `harness/leb_harness.py` reads `private/runner.json` (`runner.caracterizacao.cmd`, `runner.verificacao.cmd`, optional `timeout_s`).
  Each command runs with the instance's `private/` as cwd and gets `LEB_ENTREGA_DIR`, `LEB_INSTANCIA_DIR` and `LEB_RUN_DIR`;
  stdout carries one JSON object (`{"passed", "failed"}` for the characterization, `{"probes": [...]}` for the verification).
  A probe says `corrigida` (boolean) and/or `result` (`full|half|none`), may carry `unobserved`, `affected` and `proves`
  (`C3`/`R3` only). The exit code of a command never decides regression: the report does (submission fails more than baseline).
- Output that cannot be read, an unknown or repeated probe id, a malformed probe, a crash or a timeout (the whole process group
  is killed) make the report `inconclusive` and the harness exit 3. `leb` prints it as INCONCLUSIVO and `harness/score.py`
  refuses to score such a report. Planted flaws with no probe are listed in `unverified`.
- An instance without `runner.json` takes exactly the old docker/PHP path: a real LEB-100-A mechanical run before and after
  gives identical reports once the timing fields are ignored. No scoring, penalty or published result changes.
- Tests: `tests/test_runner.py` (17 cases, synthetic instance and synthetic runner scripts).

### `0.2.112` — 2026-10-06 — One resolver finds an instance in either layout, wherever it lives

- New `harness/instances.py`, used by `leb`, `harness/pack.py`, `harness/leb_harness.py` and `tools/export-results.py`.
  It understands the legacy layout (`instances/<id>/{code, manifest.md, private/}`) and a split one
  (`instances/<id>/{public/{code, manifest.md}, private/}`, where `public/` means "meant for the candidate", not "publishable").
- `LEB_INSTANCES_PATH` (a `:`-separated list of roots that hold `instances/`) is searched first, so an instance can live
  outside this repository; `LEB_RUNS_DIR` moves the run areas and packages out of the tree too. Without them nothing changes,
  and LEB-100-A resolves to the same files (tested).
- `./leb pacote`, `./leb scorecard` and `./leb instancias` work end to end on a split instance kept outside the repository
  (tested with a synthetic one). No scoring, penalty or published result changes.

### `0.2.111` — 2026-10-06 — Each instance declares the version of the canonical task it is evaluated against

- `protocol/TAREFA.md` stays the task 1.0.0, byte for byte, and LEB-100-A stays on it. Later versions live in
  `protocol/tasks/TAREFA-<version>.md`; the new `protocol/tasks/TAREFA-1.1.0.md` lets the agent create, split, move and
  rename files while keeping the manifest, and says that not rewriting the system does not mean keeping its internal
  organization. The quoted canonical statement is identical in both.
- The matrix header may carry `task_version` (absent means 1.0.0). `harness/pack.py` picks the template by it, refuses an
  unknown version and refuses a template whose version differs from the declared one.
- `PROTOCOL §2.2` and `SPEC §9.4` now say the task is the same across instances that declare the same version, and that
  comparisons need the same task version. No scoring, penalty or published result changes; the LEB-100-A package hash
  is still the published one (tested).

### `0.2.110` — 2026-10-06 — The tooling has tests that prove the published LEB-100-A results are still reproduced, and a workflow that runs them

- New `tests/` (standard library only, no network, no Docker, synthetic fixtures and the published results only):
  every published `scorecard.json` is reproduced by `harness/score.py` from its own `mecanico.json` and `veredito.json`
  (95 of 95); the LEB-100-A package rebuilt in the published protocol (mode A, 30 turns) has the published SHA-256;
  `tools/export-results.py --check` finds nothing to change; and the suffix `.a`/`.b` that MATRIX §2 already allows for two
  occurrences of one taxonomy type scores each occurrence independently and normalizes the category over both.
- New `.github/workflows/tests.yml` runs them on every push and pull request.
- No scoring, penalty, identifier or published result changes.

### `0.2.109` — 2026-10-06 — Nex N2.5 Pro's void record names the operator by role, not by pronoun

- `nex-n2.5-pro-high/void-1/VOID.md` said "his side" and "he judged"; it now says the operator's side and that the
  operator judged it too slow to repeat.

### `0.2.108` — 2026-10-06 — Nex N2.5 Pro's third run is voided for an operator connection failure and not repeated

- `nex-n2.5-pro-high/void-1/`: the bench3 run, 21+ hours and US$ 6.05, aborted when the operator's connection
  failed and again after the operator's "contnua"; voided before any judge saw it. The operator will not repeat
  it, the model being too slow (runs of 10h 03min, 19h 35min and 21+ hours): the agent stays at two runs.
- Instance notes: one bullet. `comments.json`: Nex's comment says it is too slow to finish three runs.

### `0.2.107` — 2026-10-06 — results/ adds Nex N2.5 Pro's run 2 (428); it publishes the lower, 317, still 35th

- `nex-n2.5-pro-high/run-2` (CR): 428, Bronze, on bench2 alongside run 1, 19h 35min, US$ 4.87; the session ended on
  the output-length limit. 10 flaws fixed; 3 characterization checks fail on a new headers_sent() guard in the
  export (PEN-002 and COMP-005), COMP-003 for the SLA average, PEN-001 for the tab before '-'; explanation 33/50.
- Instance notes: row CR, the count to seventy-two, one bullet. `comments.json`: Nex's comment covers both runs.

### `0.2.106` — 2026-10-06 — The instance notes no longer say every delivery from Y on avoided the web

- 0.2.105 extended "all seventy ran … used no web tool and made no request to GitHub" to seventy-one, but CQ
  (Nex N2.5 Pro) read OWASP's page, searched Google and tried GitHub. The sentence now excepts CQ and points to
  its bullet, and says the operator sent nothing after the fixed message, which holds for all seventy-one.

### `0.2.105` — 2026-10-06 — results/ adds Nex N2.5 Pro at high as a new agent: run 1 scores 317, below the pass line

- `nex-n2.5-pro-high/run-1` (CQ): 317, Failed, 35th, in opencode through OpenRouter on bench1, 10h 03min, US$ 2.80.
  Every public function returns nothing without a session: 8 of 22 characterization checks fail (PEN-002, −160);
  one COMP-003 (the SLA average scoped to each client); explanation 31/50. Released 2026-09-08 with no published
  cutoff: dagger.
- Instance notes: table row CQ, the count to seventy-one, one bullet. `comments.json`: its comment.

### `0.2.104` — 2026-10-05 — The multi-agent Sonnet's run times are quoted from the session record, in hours and minutes

- Instance notes: run 1 of Sonnet 5.5 in multi-agent mode took 7h 55min by its session record (13:05 to
  21:00), not the 8.1 hours quoted before; runs 2 and 3 are 2h and 3h 34min, and model time is 16h 24min,
  15h 54min and 8h 28min. Times are written as hours and minutes, not decimal hours.
- `comments.json`: the multi-agent Sonnet's range is 2h to 7h 55min; Gemini 3.8 Flash at medium took 4min 34s
  (it said 4.5 minutes).

### `0.2.103` — 2026-10-05 — results.json gives each run its wall-clock minutes, from the session's start and end

- `tools/export-results.py`: each run in `results.json` carries `wall_minutes`, the minutes from the
  session's first message to its end as `run.json` records them (`session.started` / `session.ended`),
  null when either is missing. All 90 runs have both. `runs.csv` already had the same value; both now
  come from one function.
- The results pages show it run by run in each card's sheet.

### `0.2.102` — 2026-10-05 — results/ adds a written comment for each of the 36 agents, exported into results.json

- `results/2026/LEB-100-A/comments.json`: one to three sentences per agent, in English and Brazilian
  Portuguese (the PT copy is end-user text for the results pages), drawn from the scorecards and verdicts.
- `tools/export-results.py` copies it into each entry as `comment` (null when absent) and refuses an id
  that names no agent.
- Instance notes: a short "Agent comments" section saying what the comments are and are not.

### `0.2.101` — 2026-10-05 — results/ adds GPT-5.5's run 4 (536); with three counted runs it is official again at 558

- `gpt-5.5-xhigh/run-4` (CP): 536, Bronze, in Codex CLI. 7 flaws fixed, compatibility 70 (the SLA average
  scoped to each client), explanation 32/50. Numbered run-4 because the withdrawn run 1 keeps its number.
- With runs 2 to 4 (558, 568, 536) GPT-5.5 is official at 558, 26th; twenty-six agents are official.
- Instance notes: table row CP, one bullet, the official count and list.

### `0.2.100` — 2026-10-05 — results/ adds DeepSeek V4 Flash at xhigh as a new agent: run 1 scores 617, 18th

- `deepseek-v4-flash-xhigh/run-1` (CO): 617, Silver, 18th, through Novita AI for US$ 0.18. 7 flaws fixed,
  compatibility 100, explanation 31/50. It is separate from DeepSeek V4 Flash at high (282).
- Instance notes: table row CO and one bullet.

### `0.2.99` — 2026-10-05 — results/ adds GPT-6-astra at ultra's third run (651); the agent is official at 666, the strongest official GPT agent

- `gpt-6-astra-ultra/run-3` (CN): 651, Silver, in Codex CLI with two subagents. 8 flaws fixed,
  compatibility 70, explanation 45/50.
- With three runs (668, 666, 651) GPT-6-astra at ultra is official at 666, 6th, above GPT-6.1-sol (661).
  Twenty-five agents are official.
- Instance notes: table row CN, one bullet, the official count and list.

### `0.2.98` — 2026-10-05 — results/ adds GPT-6-astra at ultra's second run (666); it publishes 666, still 6th

- `gpt-6-astra-ultra/run-2` (CM): 666, Silver, in Codex CLI with three subagents. 9 flaws fixed,
  compatibility 70 (the SLA average scoped to each client), explanation 43/50.
- Instance notes: table row CM and one bullet, including the separate gpt-6-luna session before the run.

### `0.2.97` — 2026-10-05 — results/ adds Grok 4.7 at xhigh's second and third runs (617, 631); the agent is official at 631

- `grok-4.7-xhigh/run-2` (CK) 617 and `run-3` (CL) 631, Silver, run at the same time on xAI's API.
- With three runs (640, 617, 631) Grok 4.7 at xhigh is official at 631, 11th; twenty-four agents are
  official.
- Instance notes: table rows CK and CL, one bullet, the official count and list.

### `0.2.96` — 2026-10-05 — results/ adds Grok 4.7 at xhigh as a new agent: run 1 scores 640, 9th

- `grok-4.7-xhigh/run-1` (CJ): 640, Silver, 9th, on xAI's API for US$ 3.82. 8 flaws fixed, the CSV formula
  injection among them, compatibility 100, no penalty, explanation 44/50.
- Instance notes: table row CJ and one bullet.

### `0.2.95` — 2026-10-05 — results/ keeps a fourth Gemini 3.7 Flash run unscored

- `gemini-3.7-flash-high/void-1/`: started 13 seconds after run 3 and filed after it. It was set aside under
  `PROTOCOL §4` before judging, and kept with its delivery and mechanical report.

### `0.2.94` — 2026-10-05 — results/ adds Gemini 3.7 Flash's third run at high (556); the agent is official at 587

- `gemini-3.7-flash-high/run-3` (CI): 556, Bronze, on `bench3` for US$ 0.49. The same 7 flaws fixed,
  compatibility 70 ("Desconhecido" fallback), explanation 26/50.
- With three runs (587, 587, 556) Gemini 3.7 Flash is official at 587, 23rd; twenty-three agents are
  official.
- Instance notes: table row CI, one bullet, the official count and list.

### `0.2.93` — 2026-10-05 — results/ adds Gemini 3.7 Flash's second run at high (587, the same total as run 1)

- `gemini-3.7-flash-high/run-2` (CH): 587, Bronze, on `bench1` for US$ 0.60. The same 7 flaws fixed,
  compatibility 100, explanation 27/50. The agent publishes 587, 23rd.
- Instance notes: table row CH and one bullet.

### `0.2.92` — 2026-10-05 — results/ adds Gemini 3.7 Flash at high as a new agent: run 1 scores 587

- `gemini-3.7-flash-high/run-1` (CG): 587, Bronze, 23rd, in opencode through OpenRouter for US$ 0.72.
  7 flaws fixed, compatibility 100, explanation 27/50.
- Review change: SEC-014's C1 went from half to full. The flaw is filed under the wrong category, but it is
  also named in the non-changes list.
- Instance notes: table row CG and one bullet.

### `0.2.91` — 2026-10-05 — results/ adds Kimi K2.7 Code highspeed as a new agent with three runs (402, 363, 402), official at 402

- `kimi-k2.7-code-highspeed/run-1..3` (CD, CE, CF): 402, 363 and 402, all three run at the same time on
  2026-10-05. Official at 402, 30th. It is a separate Moonshot model id from `kimi-k2.7-code`.
- Review change: CE's two findings that called the contracted `formatarStatus` and priority-4 labels bugs
  moved from false positives to extra findings, as in BM. The label changes stay charged as COMP-003.
- Twenty-two agents are official. Instance notes: table rows CD to CF, one bullet, the official count and
  list.

### `0.2.90` — 2026-10-05 — A model's release date bounds its training cutoff when the provider publishes none; four agents lose the dagger

- `PROTOCOL §3`: when the provider publishes no training cutoff but does publish the model's release date
  (`model.release_date`, `release_date_source`), the date bounds the cutoff. A release before the answer key
  went public counts as `before`.
- `tools/export-results.py`: `key_exposure` applies the rule, and the scorecard's "Training cutoff" row cites
  the release and its source.
- `run.json` records the provider's release date for Qwen3 Coder Next (2026-02-03), MiniMax-M3 (2026-06-01),
  Kimi K2.7 Code (2026-06-12) and GLM-5.2 (2026-06-16), so those four lose the dagger.
- DeepSeek V4 Pro and V4 Flash keep it. Their 2026-04-24 release was a preview, and same-name updates came
  after the key (2026-07-31 and 2026-08-13).
- Instance notes: a section *Release dates bound the training cutoff*, and the older dagger sentences
  marked as superseded.

### `0.2.89` — 2026-10-05 — results/ adds the third runs of Kimi K3 at high (574) and Kimi K2.7 Code (512); both are official, at 629 and 415

- `moonshot-kimi-k3-high/run-3` (CB): 574, Bronze. Official at 629, the median of 629, 634 and 574, 11th.
- `kimi-k2.7-code-default/run-3` (CC): 512, Bronze. Official at 415, the median of 415, 415 and 512, 28th.
- Twenty-one agents are official. Instance notes: table rows CB and CC, two bullets, the official count
  and list.

### `0.2.88` — 2026-10-05 — results/ adds Kimi K2.7 Code's second run (415, the same total as run 1)

- `kimi-k2.7-code-default/run-2` (CA): 415, Bronze, on `bench2` in 4 minutes for US$ 0.25. 6 flaws fixed,
  compatibility 100, one false positive (SQL injection in the int-typed `verChamado`), explanation 26/50.
- Instance notes: table row CA and one bullet.

### `0.2.87` — 2026-10-05 — results/ adds Kimi K3's second run at high (634); its published score stays 629

- `moonshot-kimi-k3-high/run-2` (BZ): 634, Silver, on `bench2` in 21 minutes for US$ 1.02. 9 flaws fixed,
  compatibility 70 (the CSV 403 again), explanation 40/50. The agent publishes 629, 11th.
- Instance notes: table row BZ and one bullet.

### `0.2.86` — 2026-10-05 — results/ notes give Kimi K3's run 1 the 8 fixed flaws its scorecard has, not 9

- The BY bullet said 9 flaws were fixed; the scorecard fixes 8 (SEC-001, SEC-003, SEC-013, SEC-015, SEC-017,
  BUG-001, BUG-004, PERF-001).

### `0.2.85` — 2026-10-05 — results/ keeps two void attempts: a Kimi K3 run lost to a VM restore and a logged-out Fable 5.1 attempt

- `moonshot-kimi-k3-high/void-1/VOID.md`: a finished run lost when `bench3` was restored mid-copy, with its
  first message sent five times after Kimi Code plan key errors.
- `claude-fable-5.1-xhigh/void-3/VOID.md`: an expired login answered the first message locally.

### `0.2.84` — 2026-10-05 — results/ adds Kimi K3 at high as a new agent: run 1 scores 629

- `moonshot-kimi-k3-high/run-1` (BY): 629, Silver, 11th, in opencode on Moonshot's API. 9 flaws fixed,
  compatibility 70 (HTTP 403 on the CSV for every non-technician), explanation 36/50.
- Replaces the withdrawn default-effort Kimi K3 run with a recorded one; a new agent, since the effort
  differs.
- Instance notes: table row BY and one bullet.

### `0.2.83` — 2026-10-04 — results/ notes no longer call GPT-6-astra ultra the only report to catch the quoted CSV header

- The BX bullet said it was the only report to catch that `fputcsv` quotes the `Aberto em` header; the EXPL
  judge said only that most reports missed it. It now says "one of the few".

### `0.2.82` — 2026-10-04 — results/ keeps a void Grok 4.7 xhigh attempt whose VM was restored before its delivery was copied

- `grok-4.7-xhigh/void-1/VOID.md`: a finished run (40 responses, US$ 3.00) lost when `bench3` was restored
  mid-copy. Nothing was judged, so the new agent runs again from run 1.

### `0.2.81` — 2026-10-04 — results/ adds GPT-6-astra at ultra as a new agent: run 1 scores 668, the highest GPT run

- `gpt-6-astra-ultra/run-1` (BX): 668, Silver, 6th, in 11 minutes in Codex CLI with three subagents.
  SEC 228, BUG 150, PERF 150, compatibility 70 (the SLA average scoped to each client), explanation 45/50.
- Filed as a new agent, as GPT-6.1-sol at ultra was; GPT-6-astra at xhigh stays official at 628.
- Instance notes: table row BX and one bullet.

### `0.2.80` — 2026-10-04 — results/ adds Grok 4.6's third run (620); the agent is official at 633

- `grok-4.6/run-3` (BW): 620, Silver, in 5 minutes for US$ 0.28 on xAI's API. 7 flaws fixed,
  compatibility 100, explanation 35/50.
- With three runs (633, 640, 620) Grok 4.6 is official at 633, 9th; nineteen agents are official.
- Instance notes: table row BW, one bullet, the official count and list.

### `0.2.79` — 2026-10-04 — .continue/ queues the next steps from the comparison with Akita's v4 and LiveBench

- `.continue/proximos-passos-pos-comparacao.md` (new, in the queue's language): what LEB-100-A lacks next
  to Akita's LLM Benchmark v4 (39 models) and LiveBench Coding (66 models). That is presentation (cost
  and time per card, a cost × score chart, the flaws never fixed, a client filter, an open-weights mark),
  six new agents present in both lists, and a second instance. Closing the pending runs comes first and
  is tracked outside the document.

### `0.2.78` — 2026-10-04 — results/ withdraws the three runs with no recorded client: GPT-5.5 run 1, MiniMax-M3 and Kimi K3

- `gpt-5.5-xhigh/run-1` (601), `minimax-m3/run-1` (460) and `moonshot-kimi-k3-default/run-1` (528) move to
  `withdrawn-1/`, each with a `WITHDRAWN.md`. They are the only runs that fail `PROTOCOL §4` item 5, all
  filed in the first batch before session logs were archived.
- MiniMax-M3 at its default and Kimi K3 leave the leaderboard. GPT-5.5 keeps runs 2 and 3, publishes 558
  and is no longer official.
- The leaderboard has thirty agents and eighteen official scores.
- Instance notes: a section on the withdrawal, and the "Before quoting" caveats updated.

### `0.2.77` — 2026-10-04 — PROTOCOL §4 withdraws a run whose client, first message, session log or cost was not recorded

- `protocol/PROTOCOL.md` §4 item 5: a run counts only with its record complete. A run filed without it is
  withdrawn into `withdrawn-<n>/` with a `WITHDRAWN.md`, kept for audit, not published and not counted
  toward the three. The criterion is the record, never the result, and it applies to every failing run
  at once.
- `tools/export-results.py`: run numbers may skip a number that a `withdrawn-<n>` folder holds, so the
  published runs keep their numbers.

### `0.2.76` — 2026-10-04 — results/ adds Claude Sonnet 5.5's third multi-agent max run (759); the agent is official at 774

- `claude-sonnet-5.5-max-ultracode/run-3` (BT): 759, Gold, in 3.6 h and US$ 119.07. SEC 233, compatibility
  100, no penalty. Explanation 47/50 is the highest on LEB-100-A. SEC-008 was neutralised only in a new
  web export, so its probe on `exportarCsv()` counts it as not fixed. Seven subagents fell back to
  `claude-sonnet-5`; none of the delivery came from them.
- With three runs (774, 820, 759) the agent is official at 774, 3rd; nineteen agents are now official.
- BT was copied and judged on 2026-10-03, while the client was still open, and merged once it closed and
  its cost could be read. A duplicate matching judge started on 2026-10-04 was stopped before it wrote,
  and the first verdict stands.
- Instance notes: table row BT, one bullet, the official count and list.

### `0.2.75` — 2026-10-03 — results/ adds the third runs of GLM-5.3 (604) and Gemini 3.8 Flash at high (100); both now official, at 621 and 588

- `glm-5.3-high/run-3` (BU): 604, delivered in 9 minutes. It is official at 621, the median of 629, 621
  and 604.
- `gemini-3.8-flash-high/run-3` (BV): 100, with no report and its code untouched. It is official at 588,
  the median of 687, 588 and 100.
- Both sessions stopped on a shell command the agent itself started: a server whose output stayed on
  the tool's pipe. Before any judge saw them, the operator decided to score both as delivered.
- Instance notes: table rows BU and BV, one bullet per run, and eighteen official agents.

### `0.2.74` — 2026-10-03 — tools/export-results.py accepts a delivery without a RELATORIO.md

- The check that rejects two runs filed with the same report read every delivery's RELATORIO.md and
  stopped when one was missing. A delivery can lack it: Gemini 3.8 Flash's run 3 stopped before writing
  it, and scores as delivered. The check now skips a delivery with no report, which cannot be a resend
  of another run's report.

### `0.2.73` — 2026-10-03 — results/ adds Claude Sonnet 5.5's second multi-agent max run (820); its published score stays 774

- `claude-sonnet-5.5-max-ultracode/run-2` (BS): 820, Gold, in 2.0 h and US$ 171.18 (run 1: 8.1 h,
  US$ 233.08). SEC 250/250, compatibility 100, no penalty, explanation 45/50. Eight subagents fell back
  to `claude-sonnet-5`; none of the delivery came from them.
- Instance notes: table row BS and one bullet.

### `0.2.72` — 2026-10-03 — results/ keeps a sixth GPT-6.1-sol ultra run unscored and a void ultracode attempt that never reached the model

- `gpt-6.1-sol-ultra/void-3/`: a run started after the agent had its three runs; set aside under
  `PROTOCOL §4` before judging, kept with its delivery and mechanical report.
- `claude-sonnet-5.5-max-ultracode/void-2/`: the client's login had expired, so the first message was
  answered locally and the session was closed to log in again. Only a `VOID.md`; nothing was delivered.

### `0.2.71` — 2026-10-03 — results/ adds MiniMax-M3 thinking runs 2 and 3 (616, 434; official at 462), Grok 4.6 run 2 (640) and DeepSeek V4 Flash run 2 (282)

- `minimax-m3-thinking/run-2` (BO) and `run-3` (BP): with three runs the agent is official at 462,
  the median of 462, 616 and 434. Run 3 breaks the login on the contracted schema (PEN-002, −80;
  COMP-003). It was scored through the runners hardened in 0.2.70.
- `grok-4.6/run-2` (BQ): 640; the published score stays 633, the lower of two.
- `deepseek-v4-flash-high/run-2` (BR): 282, below the pass line. Its SQL-injection fix throws on every
  search, and the client CSV is written on one line. The published score drops from 612 to 282.
- Review changes: BO's BUG-004 C1 went from half to none, and BR's SEC-001 C5 from full to none.
  Both are recorded in the verdicts' notes.
- Instance notes: table rows BO–BR, one bullet per run, and the official count is now sixteen.

### `0.2.70` — 2026-10-03 — The characterization and probe runners go on when a delivery throws, instead of stopping uncounted

`instances/LEB-100-A/characterization/run.php` (with `_bootstrap.php`) and
`instances/LEB-100-A/private/verify/probes.php` now catch an exception from the delivery's code: the
check or probe that called it fails, with the error in its message, and the rest still run. Before,
the exception ended the run: the characterization printed no summary, so the harness counted no
broken check (no PEN-002) for a delivery that breaks login, and an unreadable probe report stopped
the harness. Two new deliveries do exactly that (a login that throws, a search that throws).

The same 22 checks and 4 probes are run; only the handling of an exception changed. The legacy code
still scores 22/22 with every probe planted, and all 77 deliveries filed so far were re-run through
the new characterization with identical counts and probe verdicts. The evaluation notes list it
under the harness defects.

### `0.2.69` — 2026-10-02 — results/ adds the third runs of Claude Haiku 4.5 (232) and GLM-5.3 Prime (541), both now official, and MiniMax-M3 with the thinking variant (462)

Harness 19/22, 22/22 and 22/22; blind labels BL, BM and BN, with `mecanico.json`'s submission path
anonymized; EXPL 22, 27 and 33; `score.py`. All ran on restored clean VMs as `leb`, with the fixed
first message and nothing else.
- **Claude Haiku 4.5, run 3 (BL): 232.** Official at 317, the median, last.
- **GLM-5.3 Prime at `high`, run 3 (BN): 541.** Official at 628, the median, 11th.
- **MiniMax-M3 · thinking, run 1 (BM): 462**, a new agent: opencode with a selectable variant,
  where MiniMax-M3's first run had no client recorded and no effort setting. BM's verdict was revised
  to file two findings about contracted behaviours as extra findings, not false positives.
- Fifteen scores are official.

### `0.2.68` — 2026-10-02 — results/ keeps a fourth and a fifth GPT-6.1-sol ultra run unscored

Both completed normally in Codex CLI at `ultra` on `bench3` and `bench1`, started after run 3, which
already made the agent's score official. `PROTOCOL §4` allows three runs per agent, so they are kept
in `gpt-6.1-sol-ultra/void-1/` and `void-2/` with their deliveries, mechanical reports and a
`VOID.md`, and nobody judged them. No score changes.

### `0.2.67` — 2026-10-02 — results/ adds GPT-6.1-sol's third run at ultra (616), official at 616; every Codex agent now has three runs

Harness 22/22; blind label BK, with `mecanico.json`'s submission path anonymized; EXPL 39;
`score.py`. It ran in Codex CLI 0.159.3 at `ultra` with three subagents, on a restored clean VM the
size of runs 1 and 2, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `ultra`, run 3 (BK): 616.** With three runs its score is official: 616, the
  median, 15th. Every agent run in Codex CLI now has its three runs; thirteen scores are official.

### `0.2.66` — 2026-10-02 — results/ adds GPT-6.1-sol's second run at ultra (656); its published score stays 597

Harness 22/22; blind label BJ, with `mecanico.json`'s submission path anonymized; EXPL 40;
`score.py`. It ran in Codex CLI 0.159.3 at `ultra` with two subagents, on a restored clean VM the
size of run 1's, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `ultra`, run 2 (BJ): 656.** 59 points above run 1; the published score stays the
  lower, 597, 19th, until a third run. BJ's verdict was revised to drop a second COMP-003 for a
  CLI-only visibility filter, as AK's and AL's were.

### `0.2.65` — 2026-10-02 — results/ adds the third runs of GPT-5.6-luna (624, official at 601) and GPT-5.5 (568, official at 568), and keeps a fifth GPT-6.1-sol run unscored

Harness 22/22 for all three; blind labels BH and BI, with `mecanico.json`'s submission path
anonymized; EXPL 29 and 29; `score.py`. All ran in Codex CLI 0.159.3 on restored clean VMs as
`leb`, with the fixed first message and nothing else.
- **GPT-5.6-luna at `xhigh`, run 3 (BH): 624.** Official at 601, the median, 18th. BH's verdict was
  revised to score BUG-004 as a silent fix, as earlier verdicts did.
- **GPT-5.5 at `xhigh`, run 3 (BI): 568.** Official at 568, the median, 22nd. It ran in Codex's
  workspace-write sandbox without network; the run's note records it.
- **A fifth GPT-6.1-sol run is kept unscored** (`void-2`): meant for the `ultra` agent, its log
  records `xhigh`, an agent that already has three runs.

### `0.2.64` — 2026-10-02 — results/ adds Claude Sonnet 5.5's third max run (820, official at 807) and second runs of GPT-5.5 (558) and GPT-5.6-luna (601)

Harness 22/22 for all three; blind labels BE, BF and BG, with `mecanico.json`'s submission path
anonymized; EXPL 32, 45 and 32; `score.py`. All ran on restored clean VMs as `leb`, with the fixed
first message and nothing else.
- **Claude Sonnet 5.5 at `max`, run 3 (BF): 820.** With three runs its score is official: 807, the
  median, 2nd, above the same model in multi-agent mode (774). It used one read-only subagent that
  ran on Sonnet 5.5 itself.
- **GPT-5.5 at `xhigh`, run 2 (BE): 558.** Its published score becomes 558, 22nd. BE's verdict was
  revised to charge its COMP-003 to the visibility fix, as the other verdicts do.
- **GPT-5.6-luna at `xhigh`, run 2 (BG): 601.** Its published score stays 599, the lower of the two.
- "Before quoting a number" is rewritten from the current table: ten official scores, seven agents
  with two runs, and the band of close scores.

### `0.2.63` — 2026-10-02 — results/ adds GLM-5.3 Prime's second run (628); its published score drops to 628

Harness 22/22; blind label BD, with `mecanico.json`'s submission path anonymized; EXPL 38;
`score.py`. It ran in opencode through OpenRouter on a restored clean VM as `leb`, with the fixed
first message and nothing else.
- **GLM-5.3 Prime at `high`, run 2 (BD): 628.** Its published score becomes 628, the lower of 635
  and 628, and it moves from 9th to 11th. Unlike run 1 it left the CSV formula injection unfixed.

### `0.2.62` — 2026-10-02 — results/ adds GPT-5.6-sol's third run (608), official at 612

Harness 22/22; blind label BC, with `mecanico.json`'s submission path anonymized; EXPL 36;
`score.py`. It ran in Codex CLI 0.159.3 on a restored clean VM as `leb`, with the fixed first
message and nothing else.
- **GPT-5.6-sol at `xhigh`, run 3 (BC): 608.** With three runs its score is official: 612, the
  median, 15th. The run lost 60 points of compatibility (two COMP-003: the "Desconhecido" label for
  unknown statuses and the SLA average scoped to each client).

### `0.2.61` — 2026-10-02 — results/ adds GPT-6.1-sol's third run (661, official at 661) and GPT-5.6-sol's second (612), and keeps a fourth GPT-6.1-sol run unscored

Harness 22/22 for all three; blind labels BA and BB, with `mecanico.json`'s submission path
anonymized; EXPL 41 and 35; `score.py`. All ran in Codex CLI 0.159.3 on VMs restored from the
snapshots retaken after the clean-up, as `leb`, with the fixed first message and nothing else.
- **GPT-6.1-sol at `xhigh`, run 3 (BA): 661.** With three runs its score is official: 661, the
  median, 6th, the strongest GPT model here.
- **GPT-5.6-sol at `xhigh`, run 2 (BB): 612**, the same total as run 1; its published score stays
  612. BB's matching verdict was revised to score BUG-004 as a silent fix, as earlier verdicts did.
- **A fourth GPT-6.1-sol run is kept unscored** in `gpt-6.1-sol-xhigh/void-1/`: started 48 seconds
  after run 3, it would exceed the three runs `PROTOCOL §4` allows.

### `0.2.60` — 2026-10-02 — results/ records the cost of Claude Sonnet 5.5's second max run, US$ 4.75, from the client's own record

0.2.59 published the run with its cost pending. The operator has since closed the session, and
Claude Code wrote its usage record: US$ 4.75 and 1,658 seconds of model time, 175,958 of the
229,079 output tokens being thinking. `run.json` now takes cost and tokens from that record, as
every other Claude Code run does; `runs.csv` and the notes follow. No score changes.

### `0.2.59` — 2026-10-01 — results/ adds Claude Sonnet 5.5's second single-agent max run (773); its published score drops to 773, 3rd

Harness 22/22; blind label AZ, with `mecanico.json`'s submission path anonymized; EXPL 45;
`score.py`. It ran on a VM restored from the snapshots retaken after the clean-up, as `leb`, with
the fixed first message and nothing else.
- **Claude Sonnet 5.5 at `max`, run 2 (AZ): 773, Gold.** Its published score becomes 773, the lower
  of 807 and 773, and it moves from 2nd to 3rd, one point below the same model in multi-agent mode.
  It left MD5 in place, which run 1 had migrated, and did not name the dispatcher.
- The run's cost is pending: the client's own cost record is written when the session is closed,
  and the operator had not closed it yet. The tokens come from the session log.

### `0.2.58` — 2026-10-01 — results/ adds Gemini 3.8 Flash's second high run (588), which searched the VM for the answer key; its published score drops to 588

Harness 22/22; blind label AY, with `mecanico.json`'s submission path anonymized; EXPL 28;
`score.py`. It ran on a restored VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `high`, run 2 (AY): 588, Bronze.** Its published score becomes 588, the
  lower of 687 and 588, and it moves from 6th to 22nd.
- **It searched the VM for the answer key** by name and by the matrix hash. The key is not on the
  VM; the only match was a line of the old Claude Code session `bench2`'s snapshot still carries,
  holding the same `TAREFA.md` the run already had. The run stands, by the operator's decision
  before judging; the run's note and the evaluation notes record the search.
- The matching verdict was revised to score BUG-004 and CLN-007 as not reported, as earlier
  verdicts did for findings at the right lines that diagnose other defects.

### `0.2.57` — 2026-10-01 — results/ adds DeepSeek V4.1 Flash's second and third runs (612 and 597), official at 612

Harness 22/22 for both; blind labels AW and AX, with `mecanico.json`'s submission path anonymized;
EXPL 30 and 28; `score.py`. Both ran in opencode through OpenRouter, on restored clean VMs as
`leb`, with the fixed first message and nothing else.
- **DeepSeek V4.1 Flash at `high`: 612 (run 2) and 597 (run 3).** With three runs its score is
  official: 612, the median, 18th; its single run (625) had placed it 13th. Neither later run fixed
  the CSV injection that run 1 fixed, and run 3 left the CSV export serving every client on
  purpose.

### `0.2.56` — 2026-10-01 — results/ adds GPT-5.6-terra's second and third runs in Codex (611 and 645), official at 625

Harness 22/22 for both; blind labels AU and AV, with `mecanico.json`'s submission path anonymized;
EXPL 31 and 31; `score.py`. Both ran in Codex CLI 0.159.3, at the same time, on restored clean VMs
as `leb`, with the fixed first message and nothing else.
- **GPT-5.6-terra at `xhigh`: 611 (run 2) and 645 (run 3).** With three runs its score is official:
  625, the median, run 1's total, still 14th. Both fixed the CSV injection run 1 left alone and
  both turned the technician column's `-` into `'-` (PEN-001); run 2 also scoped the SLA average
  to each client (COMP-003).

### `0.2.55` — 2026-10-01 — results/ records two Gemini 3.8 Flash attempts cut off by OpenRouter's rate limit, and a GPT-5.6-terra run in another client, all unscored

No score changes.
- **Gemini 3.8 Flash at `medium`, `void-2` and `void-3`.** OpenRouter answered HTTP 429 (Google
  rate-limited upstream) while up to three Gemini sessions shared it; one attempt stopped without
  its report, the other without its findings index. Both are kept with a `VOID.md`.
- **GPT-5.6-terra at `xhigh` in opencode, `void-1`.** It finished normally, but in a different
  client from the agent's run 1 (Codex CLI); the operator chose to repeat it in Codex CLI. Decided
  before any judge saw it, and kept with a `VOID.md`.

### `0.2.54` — 2026-10-01 — results/ adds Gemini 3.8 Flash at medium (550), and records an attempt started outside the package as void

Harness 22/22; blind label AT, with `mecanico.json`'s submission path anonymized; EXPL 24;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `medium` (AT): 550, Bronze, 23rd**, a separate agent, 137 points below the
  same model at `high`. It fixed 6 flaws and claims SQL injection in two int-typed functions.
- **An earlier attempt at `medium` is void.** Its client was started in `/srv` instead of a copy of
  the package; the agent spent four minutes locating the task before copying the package itself.
  Kept in `gemini-3.8-flash-medium/void-1/` with a `VOID.md`.

### `0.2.53` — 2026-10-01 — results/ adds Grok 4.7's second and third runs (663 and 607), official at 638

Harness 22/22 for both; blind labels AR and AS, with `mecanico.json`'s submission path anonymized;
EXPL 42 and 41; `score.py`. Both ran in opencode on xAI's API, at the same time, on restored clean
VMs as `leb`, with the fixed first message and nothing else.
- **Grok 4.7 at `high`: 663 (run 2) and 607 (run 3).** With three runs its score is official: 638,
  the median, unchanged and 9th. Run 3 lost 30 points for relabelling unknown statuses
  "Desconhecido" (COMP-003) and left the CSV injection in place; run 2 fixed the same 8 flaws as
  run 1.

### `0.2.52` — 2026-10-01 — results/ adds Gemini 3.8 Flash (687, 6th), the first Google model, and records its second attempt as void

Harness 22/22; blind label AQ, with `mecanico.json`'s submission path anonymized; EXPL 27;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **Gemini 3.8 Flash at `high` (AQ): 687, Silver, 6th.** It ran in opencode through OpenRouter, in
  12.5 minutes, for US$ 1.00, and is the strongest model here from outside Anthropic. It fixed 8 of
  the 13 planted flaws, kept MD5 and both secrets, and its report scored 27 of 50.
- **Its second attempt is void.** On `bench2`, OpenRouter returned a 504 timeout two minutes in,
  before anything was written. It is kept in `gemini-3.8-flash-high/void-1/` with a `VOID.md`.
- The notes also correct the rank band in "Before quoting a number" (places 7 to 11, not 5 to 9)
  and count thirty contestants.

### `0.2.51` — 2026-10-01 — results/ records Claude Fable 5.1's third attempt as void: its client switched to Opus 4.8 again

Kept unscored in `claude-fable-5.1-xhigh/void-2/`, with its delivery, `mecanico.json` (22/22) and a
`VOID.md`. No judge saw it and no score was assembled.
- **What happened.** On `bench1`, 13 minutes in, while Fable 5.1 was checking its SQL injection fix
  against injection payloads, its safeguards stopped a response. Claude Code then logged
  `model_refusal_fallback` and switched to Claude Opus 4.8, which edited `lib.php` and wrote all of
  `achados.json` and `RELATORIO.md`. Under `PROTOCOL §3` (one model per run) the run is void.
- **The setting did not help.** `switchModelsOnFlag` was `false` in `leb`'s settings, and the
  switch happened anyway; the evaluation notes say so.

### `0.2.50` — 2026-10-01 — results/ adds DeepSeek V4 Pro's third run (432), official at 496

Harness 22/22; blind label AP, with `mecanico.json`'s submission path anonymized; EXPL 26;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **DeepSeek V4 Pro at `high`, run 3 (AP): 432, Bronze.** With three runs its score is official:
  496, the median of 604, 496 and 432, 24th. Its three runs spread over 172 points, the widest of
  any agent with three, across two hosts (OpenRouter for runs 1 and 3, Novita AI for run 2).

### `0.2.49` — 2026-10-01 — results/ adds DeepSeek V4 Pro's second run (496), its published score now

Harness 22/22; blind label AO, with `mecanico.json`'s submission path anonymized; EXPL 30;
`score.py`. It ran on a restored clean VM as `leb`, with the fixed first message and nothing else.
- **DeepSeek V4 Pro at `high`, run 2 (AO): 496, Bronze.** It ran at the same time as run 1, served
  by Novita AI where run 1 went through OpenRouter. It fixed 6 flaws against 8, leaving the N+1
  query, MD5 and both secrets in place. Its published score becomes 496, the lower of its two
  runs, 24th.

### `0.2.48` — 2026-10-01 — results/ adds Claude Sonnet 5.5 at max without ultracode (807, 2nd) and DeepSeek V4 Pro (604)

Harness 22/22 for both; blind labels AM and AN, with `mecanico.json`'s submission path anonymized;
EXPL 45 and 21; `score.py`. Both ran on restored clean VMs as `leb`, with the fixed first message
and nothing else.
- **Claude Sonnet 5.5 at `max`, single agent (AM): 807, Gold, 2nd.** It took 27 minutes and
  US$ 3.86. It fixed 11 of the 13 planted flaws, and scored 33 points above the same model at the
  same effort in ultracode, which took about 8 hours.
- **DeepSeek V4 Pro at `high` (AN): 604, Silver, 18th, below both DeepSeek Flash models.** It ran
  in opencode through OpenRouter. Its report claims SQL injection in two int-typed functions (two
  false positives), and its JOIN drops the listing's `-` for a ticket with no technician (PEN-001).
- The "judge is also a contestant" note now counts six Claude models among twenty-nine agents.

### `0.2.47` — 2026-10-01 — results/ adds GPT-6-astra's second and third xhigh runs (596 and 628), official at 628

Harness 22/22 for both, with SEC-001, BUG-001 and PERF-001 fixed; blind labels AK and AL; EXPL 40
and 41; `score.py`. Both ran in Codex CLI 0.159.3 on restored clean VMs as `leb`, at the same time
(bench3 and bench2), with the fixed first message and nothing else.
- **GPT-6-astra at `xhigh`: 596 (run 2) and 628 (run 3).** With three runs its score is official:
  628, the median of 661, 596 and 628, 10th; its single run had placed it 5th. Neither later run
  fixed the CSV formula injection that run 1 fixed; run 3 migrated MD5, run 2 kept it.
- **Both deliveries were judged twice.** The first matching judges could see the run's folder path
  (the agent's name) in `mecanico.json`; those verdicts were set aside and both were judged again
  blind. A second COMP-003 the judges recorded, for a command-line-only global visibility filter,
  was removed on review to match the verdicts of the same filter in earlier runs.
- The evaluation notes also correct the "Before quoting a number" spread, which still quoted
  GPT-6.1-sol's and GPT-6-astra's first runs.

### `0.2.46` — 2026-10-01 — results/ adds Sonnet 5.5's third xhigh run (official at 809) and second runs of GPT-6.1-sol (653) and GLM-5.3 (621)

Harness 22/22 for all three, with SEC-001, BUG-001 and PERF-001 fixed; blind labels AH, AI and AJ;
EXPL 41, 45 and 39; `score.py`. All three ran on restored clean VMs as `leb`, with the fixed first
message and nothing else.
- **Claude Sonnet 5.5 at `xhigh`, run 3 (AI): 724.** With three runs its score is official: 809,
  the median, still first. It fixed 8 of the 13 planted flaws, against 11 in each earlier run,
  leaving MD5, both secrets and the CSV injection in place. Its lead in fixing holds in the median
  run, not in every run.
- **GPT-6.1-sol at `xhigh`, run 2 (AH): 653**, in Codex CLI 0.159.3. Its published score becomes the
  lower, 653, 7th.
- **GLM-5.3 at `high`, run 2 (AJ): 621**, served by OpenRouter where run 1 used Z.AI's API. Its
  published score becomes 621.

### `0.2.45` — 2026-10-01 — results/ records a GPT-5.6-sol pro attempt as void: the gateway ran out of credit before the report

GPT-5.6-sol pro at `max`, in opencode through the Kilo Code gateway on `bench3`, worked from 10:05 to
11:19 and started two review subagents. Then the gateway answered `402 – Add credits to continue`,
and the session stopped. It had changed the code but written no report and no findings index. It
cost US$ 16.04 in 74 minutes. The stop is external to the run and came before anything could be
judged, so the attempt is void and kept unscored in `gpt-5.6-sol-pro-max/void-1/`, with a
`VOID.md`.

### `0.2.44` — 2026-10-01 — results/ adds Claude Haiku 4.5's second run (369); its published score stays 317

Harness 22/22 with SEC-001 and BUG-001 fixed; blind label AG; EXPL 19; `score.py`. Claude Haiku
4.5 ran on `bench2` as `leb`, in 7 minutes for US$ 0.40, with auto-accept set before the first
message and every answer from Haiku 4.5. It scored 369, against 317 for run 1, so its published
score stays the lower, 317, 27th. This time it moved both secrets out of the code and kept
compatibility. It again reported the two impossible SQL injections, and its report (19 of 50)
misstates PHP 8's division by zero. The run's note records that `bench2`'s snapshot still held the
first seconds of Opus 5.5's third run, unread.

### `0.2.43` — 2026-10-01 — results/ adds Claude Haiku 4.5 on LEB-100-A, last at 317, and files it at the model's default effort

Harness 22/22 with SEC-001 and BUG-001 fixed; blind label AF; EXPL 23; `score.py`. Claude Haiku 4.5
ran in Claude Code on `bench1` as `leb`, in 2.5 minutes for US$ 0.21. Anthropic's model overview
lists the effort setting as not supported for Haiku 4.5, so the client's `/effort xhigh` did not
apply. The agent, and its void attempt of 0.2.42, move from `claude-haiku-4.5-xhigh` to
`claude-haiku-4.5-default`. Its training cutoff is 2025-07.

317, below the pass line, 27th of 27. It found 6 of the 13 planted flaws, fixed 4, and reported two
SQL injections that cannot exist. Its visibility fix hides a client's own tickets on the main page:
a strict comparison between an integer and the string mysqli returns without a search term. That
was reproduced against the delivered code (COMP-003). The run's note records a switch to auto-accept
edits one second after the first message, which adds no instruction and switches no model.

### `0.2.42` — 2026-10-01 — results/ records a Claude Haiku 4.5 attempt as void, and PROTOCOL forbids switching the client's mode mid-run

Claude Haiku 4.5's first attempt at `xhigh`, on `bench2`, had the client's permission mode switched
from the keyboard while the agent worked, to plan mode and then to auto-accept. Plan mode put an
instruction into the agent's context ("do not edit"), and one turn came from Claude Sonnet 5.5,
Claude Code's planning model. The attempt was voided before the harness or any judge ran, and is
kept unscored in `claude-haiku-4.5-xhigh/void-1/` with a `VOID.md`. `PROTOCOL §3` now says the
client runs in a mode set before the first message and left alone until the run ends.

The `VOID.md` also records that `bench2`'s snapshot was taken with Opus 5.5's third run just
started. It held that session's first tool call and nothing more, unread by this attempt. The
snapshot is retaken with every client closed.

### `0.2.41` — 2026-10-01 — results/ adds Claude Fable 5.1's second xhigh run (764); its published score becomes 764, third

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed; blind label AE; EXPL 42; `score.py`. Fable
5.1 at `xhigh` ran on `bench1` as `leb` on a restored clean VM, with the fixed first message and
nothing else, in 22 minutes for US$ 8.04. Every answer came from Fable 5.1, with no safety stop and
no model switch, the failure that voided its earlier second attempt.

It scored 764, against 781 for run 1, so its published score is the lower median, 764, and it
moves from second to third, behind Sonnet 5.5's multi-agent run (774). It fixed 10 of the 13
planted flaws, one more than run 1, because it moved both secrets out of the code. It lost run 1's
architecture points and part of the bug score.

### `0.2.40` — 2026-10-01 — results/ adds Claude Opus 5.5's third xhigh run (805): its 717 is the first official score on LEB-100-A

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed; blind label AD; EXPL 44; `score.py`.
Opus 5.5 at `xhigh` ran on `bench2` as `leb` on a restored clean VM, with the fixed first message
and nothing else, in 15 minutes for US$ 2.71. It scored 805, against 711 and 717 for its first two
runs.

With three runs the agent is official (`PROTOCOL §4`): its published score is the median, 717, 4th.
Each of the three runs fixed 9 of the 13 planted flaws. The 94-point spread comes from judgement
calls: a business value changed in run 1, the nested conditionals flattened only in run 3, and MD5
migrated only in runs 1 and 2. The notes say so, and do not credit the clean machine for the
higher total.

### `0.2.39` — 2026-10-01 — results/ adds Claude Sonnet 5.5's second xhigh run (809) and GLM-5.3 Prime on LEB-100-A, ninth at 635

Harness 22/22 for both, with all four probes fixed; blind labels AB and AC; EXPL 45 and 28;
`score.py`. Both ran on a restored clean VM as `leb`, with the fixed first message and nothing
else.
- **Claude Sonnet 5.5 at `xhigh`, run 2 (AB): 809**, 16 points below its run 1. It ran on
  `bench1` in 23 minutes, for US$ 2.96. It found 12 planted flaws and fixed the same 11, and
  scored less in architecture and more in bugs. Its published score becomes the lower median,
  809, still first. Its note records the machine change allowed by 0.2.38.
- **GLM-5.3 Prime, `high` (AC): 635, Silver, 9th.** It ran in opencode through Kilo on `bench2`,
  for US$ 1.69. It fixed 9 planted flaws, the CSV injection among them, and carries the dagger
  (no published cutoff). In consistency review its F12, a TypeError that the `isset` guard at
  `index.php:21` makes impossible, moved from extra findings to false positives; the total does
  not change.

### `0.2.38` — 2026-10-01 — PROTOCOL keeps the machine size fixed across an agent's runs only for multi-agent clients

`PROTOCOL §3` required every run of an agent to keep the same machine size, because a multi-agent
client sizes its work to the machine (0.2.28). A single-agent client spends most of a run waiting
on the model's API, and the size weighs little on it, so the rule now applies to multi-agent
clients only. A single-agent client's runs may change machines, and the run's note records what
changed. The case that prompted it is Claude Sonnet 5.5's second run at `xhigh`, published next.
Its first run was made on the first VM at 4 vCPUs as administrator; the second on `bench1` at 20
vCPUs as the unprivileged user `leb`.

### `0.2.37` — 2026-10-01 — results/ publishes the results as CSV: one row per run, and one per run and planted flaw

`tools/export-results.py` now also writes two files from the same inputs as `results.json`, so they
cannot disagree with the leaderboard:
- **`results/runs.csv`**, one row per scored run. It carries:
  - the agent's rank and score, and whether this run is the one that counts;
  - the model, the host that served it, the effort, the client mode, and the client and version;
  - the training cutoff and the key exposure;
  - the seven category scores and the penalties;
  - planted flaws found and fixed, false positives, extra findings, the discovery index and the
    Brier score;
  - session times, model time, tokens, cost, the machine size, and the scorecard's link.
- **`results/flaws.csv`**, one row per run and planted flaw: reported, then found, explained, fixed
  and compatible as `full`, `half` or `none`, then points and declared confidence.

`results/CSV.md` explains every column, and `results/README.md` links the three files. Void runs
are left out, as in the leaderboard. The output is deterministic, and `--check` covers the CSVs.

### `0.2.36` — 2026-10-01 — results/ adds three GPT runs of 2026-10-01 on LEB-100-A: GPT-6.1-sol pro at 654, GPT-6.1-sol ultra at 597, GPT-5.3-Codex at 403

Each ran on one of three clean execution VMs, `bench1` to `bench3`, as the unprivileged user
`leb`, with the fixed first message and nothing else, no web tool and no request to GitHub.
Harness 22/22 for all three; blind labels Y, Z and AA; EXPL 42, 27 and 41; `score.py`.

- **GPT-6.1-sol pro, `xhigh` (Y): 654, Silver, 7th.** It ran in opencode through OpenRouter and
  carries the dagger, because OpenAI publishes no cutoff for a pro variant. Its COMP-003 is the
  per-client SLA average, as for five earlier GPT runs.
- **GPT-6.1-sol, `ultra` (AA): 597, Bronze.** It ran in Codex CLI 0.159.3 with three subagents,
  and scored 69 points below the same model at `xhigh`: it kept MD5, did not report the
  file-handle leak and did not name the dispatcher.
- **GPT-5.3-Codex, `xhigh` (Z): 403, Bronze.** It ran in opencode through the Kilo Code gateway. It
  found 6 of the 13 planted flaws, and scored 0 in performance, clean code and architecture.

The README names the three VMs. The session logs are archived off the VMs and unpublished.

### `0.2.35` — 2026-09-30 — The client mode is model.client_mode, and PROTOCOL §3 says why a run records it

`model.mode` (0.2.34) reused a word that already means the execution mode, S or A, which the
scorecard prints one row below the model. The field is now `model.client_mode`, and `PROTOCOL §3`
documents it: a client mode that changes how the model works, such as Claude Code's multi-agent
`ultracode`, makes the same model at the same effort a different agent, filed and shown as one.
`tools/export-results.py` now also names the mode next to a `default` effort, and no longer prints
a stray mode or `None` when the effort is missing. The generated scorecards and results table are
unchanged; `results.json` renames the key.

### `0.2.34` — 2026-09-30 — run.json records a client mode, and the leaderboard shows Sonnet 5.5's as max (ultracode)

The same model at the same effort runs differently in Claude Code's multi-agent mode, so the mode
is now part of the model block: `model.mode` in `run.json`, set to `ultracode` for Claude Sonnet
5.5's multi-agent run. `tools/export-results.py` prints it next to the effort, as `max (ultracode)`
in the results table and as `` `max` (ultracode) `` in the scorecard's Model row, and
`results.json` carries it to the site. No score changes.

### `0.2.33` — 2026-09-30 — results/ adds Claude Sonnet 5.5 in multi-agent mode on LEB-100-A, third at 774

Harness 22/22 with all four probes fixed; blind label X; EXPL 45/50; `score.py`. Claude Sonnet 5.5
at effort `max` in Claude Code's multi-agent mode ran 7 workflows with 68 subagents from 13:05 to
21:00 on the first VM, resized to 20 vCPUs, with the fixed first message and nothing else, no web
tool and no request to GitHub. The client's record: US$ 233.08 and 16.4 hours of model time in 8.1
hours, against US$ 3.60 and 19 minutes for the same model at `xhigh`.

774 (Gold), third, 51 points below the `xhigh` run's 825. It scores the same in security (250),
bugs, performance, clean code and compatibility, finds 29 findings with no false positive and is
better calibrated, but scores 0 in architecture against 50 and one point less in explanation.
Nine subagents fell back to `claude-sonnet-5` after the safety classifier stopped them (US$ 15.34,
541,148 output tokens, all analysis); none of the delivery came from them, so the run stands under
the rule of 0.2.32. It began before the clean-machine rule, as the VM's administrator. The session
log is archived off the VM, unpublished; `void-1` stays as filed.

### `0.2.32` — 2026-09-30 — PROTOCOL voids a run when another model wrote any part of the delivery, and records a fallback confined to subagents

Claude Fable 5.1's second run was voided because its client switched to another model, which wrote
the report; until now that rule lived only in that run's `VOID.md`. `PROTOCOL §3` now states it for
every run: a client that can switch models on its own runs with the switch off, and a run is void
when any part of the delivery (code, report or findings index) was written by another model. A
switch that stays inside auxiliary agents of a multi-agent client, with none of the delivery
written by them, leaves the run standing, and its note records which agents switched, to which
model, and their share of the run's output tokens and cost. The case that prompted it is Claude
Sonnet 5.5's multi-agent run, published next.

### `0.2.31` — 2026-09-30 — results/ adds Qwen3 Coder Next on LEB-100-A, eighteenth at 507

Harness 22/22 with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not; blind label W; EXPL
18/50, the lowest so far; `score.py`. The first run under the clean-machine rule of 0.2.30: the
unprivileged user `leb` on the second VM, in opencode through Novita AI, with the fixed first
message and nothing else, no web tool and no request to GitHub; US$ 1.53 in 16 minutes. The model
is non-thinking and has no effort setting, so the agent is `qwen3-coder-next-default`; the Qwen team
publishes no cutoff, so it carries the dagger.

507 (Bronze), eighteenth. PEN-001 (−15): its JOIN drops the `-` shown for a ticket with no
technician, as the rewritten `-` was for G and V. Three false positives at confidence 100 give it
the worst calibration so far (Brier 0.301). Its run note records the session at 17:33 that never
reached the host (no outbound internet yet), the extra `analysis_report.md` it left unscored, and
147 calls to a tool that does not exist. The session log is archived off the VM, unpublished.

### `0.2.30` — 2026-09-30 — PROTOCOL runs every agent as an unprivileged user on a machine cleaned of earlier runs' leftovers

Until now every run was made as the execution VM's administrator account, with passwordless sudo,
on a machine that kept what earlier runs left: the clients' session logs, temporary files, and a
`suporte` database with a `painel` user in the system MariaDB. `PROTOCOL §3` now requires an
ordinary user with no sudo, on a machine carrying nothing from an earlier run, restored from a
clean snapshot before every run; the agent tests inside its own account (PHP's built-in server
and a private MariaDB it starts itself). The README's isolation table adds the two layers of the
second VM, `ai-bench2`: IPv6 disabled on its LAN interface, and the unprivileged user.

Every tool call in the session logs of the earlier runs was read for signs that one run used
another's leftovers, and the evaluation notes record what that shows: no session read another
run's folder, report, findings index or session log; the opencode runs shared the system
database, read only seed rows from it and inspected no structure an earlier agent had left. The
logs were copied off the VM before any cleanup; they stay unpublished.

### `0.2.29` — 2026-09-30 — results/ records the first multi-agent Sonnet 5.5 attempt as void, stopped to resize the machine

The first run of Claude Sonnet 5.5 at max effort in Claude Code's multi-agent mode
(`claude-sonnet-5.5-max-ultracode`) started at 12:34 on the 4-vCPU, 3.8-GiB execution VM. The
agent reported that the machine limited each of its workflows to 2 agents. The operator stopped it
at 13:00, before it had written a report or findings index, and resized the VM to 20 vCPUs and
15 GiB.

The folder is kept as it was, unscored, in `claude-sonnet-5.5-max-ultracode/void-1/`. Its
`VOID.md` gives the session's timeline, its 5 workflows and 15 subagents, and the US$ 8.43 the
client recorded. It also explains why the replacement is not a selective retry: the run was stopped
for the machine, before there was any output to judge. The evaluation notes list it with the runs
of 2026-09-30.

### `0.2.28` — 2026-09-30 — run.json records the size of the machine each run ran on

Claude Sonnet 5.5 in Claude Code's multi-agent mode ("ultracode") told the operator that the VM's
4 CPUs limited each of its workflows to 2 agents at a time. A multi-agent client sizes its work to
the machine, so the machine's size changes what is measured. Until now it was recorded nowhere.

- `PROTOCOL.md §3`: every run records `execution_host` (vCPUs and RAM), and all the runs of one
  agent keep the same size.
- **The existing runs.** The 20 runs that ran on the execution VM record 4 vCPUs and 3.8 GiB, its
  size from 2026-09-25 until it was resized on 2026-09-30 at 13:01. Kimi K3's clone records `null`,
  listed in `not_recorded`.
- **Scorecards.** They gain an "Execution host" row.
- **Evaluation notes.** They explain why the size matters little for single-session clients and a
  lot for multi-agent ones, and that later runs use the new size: 20 vCPUs and 15 GiB.

No score changes.

### `0.2.27` — 2026-09-30 — results/ adds DeepSeek V4.1 Flash on LEB-100-A, ninth at 625

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with all four probes fixed, SEC-008 included;
- one blind matching judge (label V);
- the EXPL judge on its kept scale (33/50);
- `score.py`.

**How it ran.** It ran through DeepSeek's own API (`deepseek-flash`), in opencode 1.18.33 at effort
`high`, with every isolation layer, the fixed first message only, and no request to GitHub. It cost
US$ 0.04, the cheapest run so far. DeepSeek publishes no training cutoff, so it carries the dagger.

**Result.** DeepSeek V4.1 Flash totals 625 (Silver), ninth, level with GPT-5.6-terra and ahead of
it on the discovery index. Its sanitizer also rewrites the `-` marker, so the review kept its
judge's PEN-001 (−15), the same call G got.

**DeepSeek V4 Flash caveat.** DeepSeek's release note says its own API has routed the name
`deepseek-v4-flash` to V4.1 Flash since 2026-09-14. That run went through Novita's hosted model of
that name, so it cannot be shown to be V4 weights. Its run note and the evaluation notes now say
so. The two results differ (612 and 625, and in several calls).

The evaluation notes and the README status count twenty agents.

### `0.2.26` — 2026-09-30 — results/ adds DeepSeek V4 Flash on LEB-100-A, twelfth at 612

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label U);
- the EXPL judge on its kept scale (29/50);
- `score.py`.

**How it ran.** It ran in opencode 1.18.33 at effort `high`, served by Novita AI, with every
isolation layer and no request to GitHub. It cost US$ 0.07.
- **Name.** It is filed as `deepseek-v4-flash-high`, the model the session shows. The operator's
  folder said `v4.1`, which the host did not offer.
- **Identity check.** Its run note records the "quem eh voce?" the operator sent before the fixed
  first message. That is the case `PROTOCOL §3` now covers (0.2.25).
- **Cutoff.** DeepSeek publishes none, so the model carries the dagger.

**Result.** DeepSeek V4 Flash totals 612 (Silver), twelfth. It sits level with GPT-5.6-sol, which
ranks ahead on Brier. It fixed eight flaws fully, including MD5 and the secrets.

**Consistency review.** The review kept its judge's COMP-003 (−30) for changing `formatarStatus`
to return "Desconhecido" where the legacy code returned "Resolvido", the same call D got. Without
it the total would be 642.

The evaluation notes and the README status count nineteen agents.

### `0.2.25` — 2026-09-30 — PROTOCOL says what happens to an identity check sent before the first message

Before the fixed first message of DeepSeek V4 Flash's run, the operator asked "quem eh voce?" to
confirm which model the host was serving. DeepSeek's own site gives V4.1 Flash a different name,
and the host offered only V4 Flash. The rule said the first message is exactly the fixed one, with
nothing added, but it said nothing about a message before it.

`PROTOCOL.md §3` now says it. A check made before the first message that says nothing about the
task, such as asking which model is answering, belongs in a separate session. If it happens in the
run's own session, the run's note records the exchange and the run stands. A message that does
carry task content is still covered by the voiding rule. The decision was taken before that run was
scored.

### `0.2.24` — 2026-09-30 — results/ adds Kimi K2.7 Code on LEB-100-A, seventeenth at 415

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001 and BUG-001 fixed and SEC-008 and PERF-001 not;
- one blind matching judge (label T);
- the EXPL judge on its kept scale (26/50);
- `score.py`.

**How it ran.** It ran in opencode 1.18.33 through Moonshot's API, at the model's only effort (the
default), with every isolation layer, the fixed first message and nothing else, and no request to
GitHub. It cost US$ 0.48. The operator's folder was named `kimi-for-coding-high`; the agent is
filed as `kimi-k2.7-code-default`, and its run note says why. The run note also records an earlier
session that never started, on invalid-key errors. Moonshot publishes no training cutoff; the
release date (2026-06-12) goes in the note but is not used as one, so the model carries the dagger.

**Result.** Kimi K2.7 Code totals 415 (Bronze), seventeenth. Its two SQL-injection findings in
integer-only functions, reported at confidence 100, are false positives, and they give the worst
Brier so far (0.225).

**Consistency review.** Its report claims an empty fallback for `EXPORT_DIR`, while the code keeps
the original path. That is no compatibility violation, and the EXPL judge's mark-down of the report
stands.

The evaluation notes and the README status count eighteen agents.

### `0.2.23` — 2026-09-30 — results/ adds Grok 4.6 on LEB-100-A, seventh at 633

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label S);
- the EXPL judge on its kept scale (35/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high`, with every isolation layer, the fixed first message
and nothing else, no web tool and no request to GitHub. It took six minutes and cost US$ 0.31.
xAI publishes no training cutoff for Grok 4.6 (it does for 4.7), so the leaderboard marks it with
the dagger.

Grok 4.6 totals 633 (Silver), seventh, five points below Grok 4.7 for about an eighth of its cost.
The consistency review kept its judge's calls:
- **SEC-015 C3 at half.** Grok 4.6 moved the SMTP key to the environment and kept the database
  password's literal fallback. J did the same and got the same score; A, P and Q kept both literals
  and got none.
- **CLN-007 R1.** It named the nested ifs in its list of non-changes, which rule 5 counts as R1.

The evaluation notes and the README status count seventeen agents.

### `0.2.22` — 2026-09-30 — results/ adds Grok 4.7 on LEB-100-A, sixth at 638

Another run of 2026-09-30, evaluated like the rest:
- harness 22/22, with all four probes fixed, SEC-008 included;
- one blind matching judge (label R);
- the EXPL judge on its kept scale (42/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high` with every isolation layer. The client counted US$ 2.43.
xAI publishes a training cutoff of May 2026, before the answer key went public, so it carries no
dagger.

- **First web request.** It is the first agent to use a web tool: one fetch of the PHP manual page
  for `fputcsv`. The protocol blocks GitHub, not the web. Its run note records the fetch, and its
  session shows no request to GitHub.
- **Result.** Grok 4.7 totals 638 (Silver), sixth, and is the strongest model here from outside
  Anthropic and OpenAI. It is the fourth agent to fix the CSV formula injection, without G's
  side-effect on the `-` marker. It kept the contract whole.
- **Notes.** The evaluation notes count sixteen agents, and their per-run bullets drop the rank
  ordinals, which went stale with each new run. The README status counts sixteen agents.

### `0.2.21` — 2026-09-30 — results/ adds GLM-5.3-Flash (eighth at 624) and GLM-5.3-FlashX (twelfth at 597) on LEB-100-A

The two smaller GLM-5.3 variants were run the same morning, each in opencode 1.18.33 at effort
`high`, with every isolation layer. Each session record shows the fixed first message, no other
operator message, and no request to GitHub. Z.AI publishes no training cutoff for either, so both
carry the dagger.

| Model | Harness | Label | EXPL | Total | Grade | Place | Cost |
| --- | --- | --- | ---: | ---: | --- | ---: | ---: |
| GLM-5.3-Flash | 22/22; SEC-001, BUG-001, PERF-001 fixed | P | 34 | 624 | Silver | 8th | US$ 0.08 |
| GLM-5.3-FlashX | 22/22; the same three fixed | Q | 33 | 597 | Bronze | 12th | US$ 0.10 |

Both kept the contract whole. The consistency review kept every call:
- Flash's optional parameters and literal CSV header are not COMP.
- Flash's unguarded bcrypt re-hash, which a legacy `CHAR(32)` column would break, is a deployment
  caveat and not PEN-001, as for D, E and F.
- FlashX's SQL `AVG` is not COMP-003, as for C and F.

The evaluation notes and the README status count fifteen agents.

### `0.2.20` — 2026-09-30 — results/ adds GLM-5.3 on LEB-100-A, sixth at 629

A fifth delivery of 2026-09-30, evaluated like the others:
- harness 22/22, with SEC-001, BUG-001 and PERF-001 fixed and SEC-008 not;
- one blind matching judge (label O);
- the EXPL judge on its kept scale (39/50);
- `score.py`.

It ran in opencode 1.18.33 at effort `high`, on the execution VM with every isolation layer. Its
session record shows the fixed first message, no other operator message, and no request to
GitHub. The client counted US$ 0.73 and 1.88 M tokens. Z.AI publishes no training cutoff, so the
leaderboard marks it with the dagger.

GLM-5.3 totals 629 (Silver), sixth, 241 points above GLM-5.2 in the same client at the same
effort. It fixed the N+1 query and seven more flaws, and kept the contract whole.

The consistency review kept its judge's call on `exportarCsv`. The function now filters by the
logged-in client's session, but callers without a session, such as the nightly export, still
receive every ticket. That enforces the visibility rule and is not COMP-003.

Places 7 to 13 move down by one. The evaluation notes and the README status count thirteen agents.

### `0.2.19` — 2026-09-30 — PROTOCOL fixes the exact first message, and Opus 5.5's second run records why its first attempt was stopped

**First message.** The runs of 2026-09-29 began with *"Não saia desta pasta. Agora leia o TAREFA.md
e execute. Devolva code/ alterado, RELATORIO.md e achados.json."* The operator had added the first
sentence for fear that something else on the VM could give a hint. On 2026-09-30 the operator dropped it
to follow the original prompt. The guides meanwhile said only "leia o TAREFA.md e execute", in three
slightly different wordings.

- `PROTOCOL.md §3` now fixes the first message as exactly *"Agora leia o TAREFA.md e execute.
  Devolva code/ alterado, RELATORIO.md e achados.json."*, the text the operator keeps in the VM's
  `/srv/prompt.md`, with nothing added.
- The `COMO-RODAR.md` that `./leb pacote` writes and `BENCHMARK.md` (steps 2 and 3) repeat it word
  for word. `TAREFA.md` and the package do not change.
- The evaluation notes say that an agent's run 1 of 2026-09-29 and its later runs differ by that
  sentence. Each run's `first_message` records which one it got.

**Opus 5.5, run 2.** Its run note now gives the operator's reason for stopping the first attempt
at 08:28: it had started before the VM's last isolation layer (GitHub's edge addresses, 09:02) was
in place. The run of 09:04 replaced it.

### `0.2.18` — 2026-09-30 — results/ adds Kimi K3 on LEB-100-A, tenth at 528

Kimi K3's delivery was judged with the batch of 2026-09-30 (blind label M) and held, because no
session of it existed on the execution VM. The owner has since explained why. It ran on a clone of
the VM, made to run a second session in parallel and destroyed afterwards, and its session log went
with the clone.

`run.json` records that and nothing it cannot show:
- **Environment.** A clone with at least GitHub's names blocked and no IPv6. Whether it already had
  the blackhole routes is not recorded, and GitHub's edge addresses came after the delivery was
  filed.
- **Not recorded.** The client, the first message, the operator's messages, the tokens and the
  cost.
- **Effort.** It is filed at `default`, from the folder name.
- **Training cutoff.** Moonshot AI publishes none, so the leaderboard marks it with the dagger.

Kimi K3 totals 528 (Bronze), tenth. It kept the contract whole (COMP 100) and fixed seven flaws,
with EXPL at 30/50. It left the N+1 query, the CSV injection and MD5 in place. MiniMax-M3 and
GLM-5.2 move to 11th and 12th. The evaluation notes and the README status count twelve agents.

### `0.2.17` — 2026-09-30 — results/ adds the runs of 2026-09-30 on LEB-100-A: Opus 5.5 run 2 at 717, GLM-5.2 at 388, Fable 5.1 run 2 void

Four deliveries arrived on 2026-09-30. Each went through the procedure of the first ten: harness
(22/22 for all four), one blind matching judge each (labels K to N), EXPL, `score.py` and a
consistency review. The EXPL judge is a second instance: it kept the scale of the one that scored
A–J and changed none of those scores.

| # | Model | Total | Grade | Runs |
| ---: | --- | ---: | --- | :-: |
| 1 | Claude Sonnet 5.5 | 825 | Gold | 1/3 |
| 2 | Claude Fable 5.1 | 781 | Gold | 1/3 |
| 3 | Claude Opus 5.5 | 711 | Silver | 2/3 (711 · 717) |
| 4 | GPT-6.1-sol | 666 | Silver | 1/3 |
| 5 | GPT-6-astra | 661 | Silver | 1/3 |
| 6 | GPT-5.6-terra | 625 | Silver | 1/3 |
| 7 | GPT-5.6-sol | 612 | Silver | 1/3 |
| 8 | GPT-5.5 | 601 | Silver | 1/3 |
| 9 | GPT-5.6-luna | 599 | Bronze | 1/3 |
| 10 | MiniMax-M3 | 460 | Bronze | 1/3 |
| 11 | GLM-5.2 | 388 | Reprovada | 1/3 |

- **Opus 5.5, run 2 (K): 717.** The published score stays 711, the lower of two (PROTOCOL §4).
  - Its run note records a first attempt the operator interrupted at a tool-permission prompt,
    before any delivery.
  - It records a first message without the "Não saia desta pasta." that run 1 received.
  - Its cost comes from Claude Code's own summary: US$ 3.01, with 923 s of model time.
- **GLM-5.2 (N): 388, the first result below 400.**
  - It ran in opencode 1.18.33 at effort `high`.
  - It kept the contract whole and fixed five flaws.
  - Z.AI publishes no training cutoff, so it carries the dagger.
  - Its cost comes from opencode's record: US$ 0.35.
- **Fable 5.1's second run (L) is void.** Its session log shows the model's safeguards stopping a
  response and Claude Code finishing the run with Claude Opus 4.8, which wrote the whole report.
  It was voided on that evidence before its score was assembled or its label matched to the model.
  It is kept unscored in `claude-fable-5.1-xhigh/void-1/` with a `VOID.md`.
- **Kimi K3 (M) is judged and held.** No session of it exists on the execution VM. Its delivery
  and mechanical report are filed in place; its verdict and score wait for the record of where and
  how it ran.
- **Two misplaced deliveries move.** The Kimi K3 and Fable 5.1 deliveries were committed
  unjudged at `results/2026/<agent>/` by 0.2.15, by mistake. They now move to their places.
- **Exporter and docs.** `tools/export-results.py` shows a `run_note` as a "Run note" row. The
  evaluation notes gain *Runs of 2026-09-30*, and the README status counts eleven agents.

### `0.2.16` — 2026-09-30 — The first ten runs record what their session logs show: client, operator messages, tokens, and no request to GitHub

The execution VM still holds the client session logs of eight of the ten runs of 2026-09-29: the
three Claude models in Claude Code, and five GPT models in Codex CLI. They were read on 2026-09-30
with the owner's go-ahead, and each `run.json` records what they show. The logs themselves stay on
the VM, unpublished.

- **Client.** `client` gives name and version: Claude Code 2.1.285, Codex CLI 0.158.0 for
  GPT-5.6-luna and 0.159.2 for the other four GPT models. `session` gives start and end, with where
  the log is kept.
- **First message.** `first_message` holds *"Não saia desta pasta. Agora leia o TAREFA.md e
  execute. Devolva code/ alterado, RELATORIO.md e achados.json."* in all eight.
- **Operator messages.** `operator_replies` counts them, and a note explains any that exist. Opus
  5.5 and GPT-5.6-luna each received the first message again mid-run, verbatim, so each counts 1.
  Fable 5.1's first message was interrupted two seconds in and re-sent after the client switched
  from Sonnet 5.5 to Fable, which is not counted.
- **Cost.** `cost_time` holds the tokens the client counted. Sonnet 5.5's folder still has Claude
  Code's own summary, which adds model time (1,058 s) and cost (US$ 3.60). For the other runs no
  cost or model time survives.
- **No GitHub access.** None of the eight sessions made a request to GitHub, by name or by address,
  or used a web search or fetch tool. Their network calls all go to the agents' own local test
  servers. The results notes say so.
- **No log.** GPT-5.5's and MiniMax-M3's runs left no log on the VM, so their new fields are `null`
  and listed in `not_recorded`.
- **Scorecards.** They gain "Client" and "First message" rows, the "Operator replies" row shows its
  note, and `results.json` carries `client` per run. No score changes.

### `0.2.15` — 2026-09-30 — README dates each layer of the VM's isolation, and the first ten runs record that they had GitHub's names blocked but not its addresses

0.2.11 said three layers of isolation had been in place "since the first scored run", and wrote the
same into the ten `run.json` files. That rested on the owner's recollection. The VM's own logs,
read on 2026-09-30, contradict it:

- the `/etc/hosts` block went in on 2026-09-29 at 18:59 (UTC−3), before the first run at 19:32;
- `github-blackhole.sh`, its systemd unit and the first blackhole route were created on
  2026-09-30 at 08:13, after the ten runs;
- no route command appears before that in the sudo log or the root shell history, and the VM had
  not rebooted since 2026-09-25;
- the VM never had IPv6 connectivity: no global address and no IPv6 default route.

So the first ten runs had the name block only. An agent could not reach GitHub by name, but a
connection straight to a GitHub address would have gone through.

The same check found that the edge address `20.201.28.151` (São Paulo) answered HTTP 200 from the
VM. At 09:02 the blackhole script gained 71 edge addresses from `api.github.com/meta` (web, api,
git, packages). Afterwards `github.com`, `140.82.112.3` and that edge all fail, while a site
outside GitHub answers.

- `README.md`, *Execution environment*: a table dates each layer and names the runs it covers,
  and a paragraph says the first ten runs had the name block only. The routes now come from
  `tools/github-blackhole.sh`, new here: the VM's script, with the same commands and English
  comments, installed at boot by a oneshot systemd unit. The limit about unblocked edges gives way
  to one about the address list ageing.
- The ten `run.json` files: `execution_environment` states the name block and says addresses were
  not blocked yet.
- `MATRIX.md §4` item 5 and the results notes say the same.
- The version 0.2.11 entry above stays as written; this entry corrects it.

### `0.2.14` — 2026-09-30 — cost_time comes from the client's own usage summary and records the model's working time, never the wall-clock

SPEC §8.3 and SCORING §9.3 already had an informative `cost_time` block, with its time defined as
wall-clock. In mode A the wall-clock includes every wait for the operator (0.2.13), so it measures
the operator as much as the model. It was also never recorded: all ten runs have `cost_time: null`.

- **Source.** The numbers are copied from the client's own usage summary at the end of the
  session, for example Claude Code's `/cost`. The summary is kept as printed, as `custo.txt` next
  to `entrega/`, and `cost_time.source` names it. The agent's own account of its time is not used,
  since no one can check it.
- **Time.** `elapsed_seconds` is the model's working time as the client reports it, for example
  "Total duration (API)", and never the wall-clock. It is `null` when a client reports only
  wall-clock.
- **Best comparison.** Tokens compare best across providers. Time also depends on the provider's
  load.
- **Where it is written.** SPEC §8.3 and SCORING §9.3 (rewritten in English), the `cost_time` block
  of `scorecard.schema.json` (`source` added, `elapsed_seconds` redefined), `BENCHMARK.md` and the
  generated `COMO-RODAR.md`, which gains the step and a checklist row. The package is unchanged.
- **Where it shows.** Each scorecard's informative metrics gain a "Cost and time" line, and
  `results.json` carries `cost_time` per run. The ten existing runs read "not recorded". The
  generated `results/README.md` lists `custo.txt` among a run's files.

### `0.2.13` — 2026-09-30 — PROTOCOL fixes the one reply an operator may send an agent that stops to wait, and run.json counts it

In mode A an agent sometimes stops before its delivery is complete to ask a question, ask for
confirmation, or report progress and pause. Until now the protocol did not say what the operator
answers. A "go ahead" to one model and a sentence with context to another changes the run, and
nothing recorded it.

`PROTOCOL.md §3` now defines the reply. The operator's first message is turn 1 of the budget. Every
pause gets exactly *"Não há ninguém para responder. Decida com o seu próprio critério e continue."*,
the same for every agent, and each reply is one more turn. `run.json` records the count as
`operator_replies`.

- **Voiding.** Any other content voids the run: an answer, a hint, a correction. A voided run may be
  replaced only while its delivery is unscored, so the rule cannot become a selective retry.
- **Not a reply.** Approving a client's tool-permission prompt carries no content and is not counted.
- **Budget.** When the budget runs out, the delivery is whatever the agent has written.

The rule reaches the operator in three places: `BENCHMARK.md` step 3, and the `COMO-RODAR.md` that
`./leb pacote` writes, which gains a section with the reply and an "operator replies" row. The
generated guide is now in English; the lines sent to the agent stay in Portuguese, like the task.
`TAREFA.md` does not change, so the package keeps its SHA-256 (`34e38bc5…`, checked by rebuilding
it) and later runs stay comparable with the first ten.

For the ten existing runs `operator_replies` is `null` and listed in `not_recorded`: the reply was
written after them. Scorecards of mode-A runs gain an "Operator replies" row, and `results.json`
carries the field per run.

### `0.2.12` — 2026-09-30 — README publishes the execution VM's blackhole script, IPv6 prefixes included

In 0.2.11 the blackhole layer was reconstructed from a list of GitHub's announced prefixes, as four
`ip route add` lines, and the README said GitHub's IPv6 prefixes needed no route. The owner then
shared the script the VM actually runs, `github-blackhole.sh`. It installs the same four IPv4
prefixes and also three IPv6 prefixes (2a0a:a440::/29, 2620:112:3000::/44 and 2606:50c0::/32, the
nine announced ones collapsed). It uses `ip route replace`, so it can run any number of times, and
`del` removes the routes.

The README now publishes that script as the recipe to reproduce the setup, with its comments in
English. Layer 2 counts both families. Layer 3 says the IPv6 blackholes are a second lock, since the
VM has no IPv6 at all. The limits are unchanged.

### `0.2.11` — 2026-09-30 — README documents the execution VM's three layers of isolation from GitHub

The *Execution environment* section described only the block by name, and said a connection made
straight to an IP address still went through. That understated the VM. As the owner confirmed,
three layers have been in place since the first scored run:

1. GitHub's names resolve to loopback in `/etc/hosts`.
2. GitHub's IPv4 prefixes are blackhole routes. The 26 routed prefixes collapse to 140.82.112.0/20,
   143.55.64.0/20, 185.199.108.0/22 and 192.30.252.0/22, and the section publishes them as
   `ip route add blackhole` lines so the setup can be reproduced.
3. The VM has no IPv6.

The limits are rewritten to what is still true. `api.github.com/meta` lists regional edge
addresses for `web`, `api` and `git`, mostly on Azure (such as `20.201.28.151`), that fall outside
those prefixes, so a direct connection to one of them is not stopped. Copies of the repository
outside GitHub are out of scope. Training exposure is handled by the cutoff each run records.

`execution_environment` in the ten LEB-100-A `run.json` files said "GitHub blocked by name". It now
states the three layers, which is what those runs actually had. No scorecard or `results.json`
changes.

### `0.2.10` — 2026-09-30 — MATRIX §4 writes down the exception that keeps LEB-100-A current despite its published answer key

LEB-100-A's `private/` has been in this public repository since 2026-07-13, against MATRIX §4
item 1. The documents disagreed about what that meant. The results notes and the site said the
instance "should be retired for new runs". The cover README's open item asked for official runs
"on an instance whose answer key has not been published". Meanwhile, second and third runs of the
same models are about to be filed.

The owner's decision is now written down as MATRIX §4, item 5. LEB-100-A stays a current instance,
and three runs of it are official, under two conditions: every run executes on a machine that
cannot reach GitHub, and every run records the model's training cutoff as its provider publishes
it (0.2.9). A later or unpublished cutoff does not disqualify a run; it is flagged. Item 3 still
decides retirement, with the recorded cutoffs as the evidence.

- `README.md`: the open item on official runs and the note on matrices point at the exception.
- `results/2026/LEB-100-A/README.md`: the public-key caveat is rewritten. The instance stays
  current, nine of ten cutoffs precede the key, and MiniMax-M3's is unpublished. A new section,
  "Second and third runs", says how a repeated run is filed and judged: the same folder is the same
  model and effort, every run is filed, blind labels continue from K, the EXPL judge keeps its
  scale, and the consistency review covers every delivery.

The *Execution environment* section, which still describes only the block by name, is not changed
here. It waits for the exact IPv4 ranges the execution VM routes to blackhole.

### `0.2.9` — 2026-09-30 — run.json records the model's training cutoff, and the scorecard says whether the model could have trained on the answer key

LEB-100-A's answer key has been in this public repository since 2026-07-13. The execution VM keeps
it out of reach during a run; whether a model could have met it in training depends on how far that
model's training data reaches. That is now recorded instead of left as a blanket caveat.

`run.json` gains `model.training_cutoff` and `model.training_cutoff_source`: the cutoff the provider
publishes, with the page it comes from, and `null` when the provider publishes none (never a
third-party estimate; PROTOCOL §3). The ten runs on LEB-100-A are filled from the providers' pages,
checked on 2026-09-30:

| Model | Published cutoff | Source |
| --- | --- | --- |
| Claude Sonnet 5.5 · Fable 5.1 · Opus 5.5 | 2026-06 (training data cutoff) | Anthropic models overview |
| GPT-6.1-sol · GPT-6-astra | 2026-04-30 (knowledge cutoff) | OpenAI model pages |
| GPT-5.6-terra · sol · luna | 2026-02-16 (knowledge cutoff) | OpenAI model pages |
| GPT-5.5 | 2025-12-01 (knowledge cutoff) | OpenAI model page |
| MiniMax-M3 | not published | MiniMax model page and Hugging Face card |

`tools/export-results.py` holds the day each answer key became public (`KEY_PUBLISHED`; never in
`matrix.json`, whose hash is the published commitment) and derives `key_exposure` for each entry:
`before`, `after` (a month-precision cutoff counts to the month's last day) or `unknown`. Each
scorecard gains a "Training cutoff" row with that verdict; the leaderboard marks `after` and
`unknown` with † and names them below the table. Today nine are `before` and MiniMax-M3 is
`unknown`. `results.json` gains `key_exposure` per entry and `key_published_on` per instance; no
score moves.

### `0.2.8` — 2026-09-30 — tools/export-results.py publishes repeated runs: the lower median is the score, and the details are that run's

Second and third runs of the models already on LEB-100-A are about to arrive. The exporter already
found `run-<n>` folders on its own, but it published the median total next to the grade, the
categories, the flaw-by-flaw and the scorecard link of the *best* run — two runs would have put
numbers from different runs side by side.

The score is now `statistics.median_low` of the totals: the only total with one run, the lower of
two, the median with three (PROTOCOL §4). It is always the total of a run that exists, and that
run — `representative_run` in `results.json` — supplies everything shown next to it, including the
tie-breaking discovery index and Brier. Each entry also carries `totals`, in run order, and the
leaderboard's Runs column reads `2/3 (711 · 770)`.

The export now stops, before writing anything, when an agent has a gap in its run numbers, more than
three runs (a fourth is the retry §4 forbids), a `run.json` whose `run` disagrees with its folder,
or two runs with the same `RELATORIO.md` (one delivery filed twice). `PROTOCOL.md §4` gains item 4
with the publication rule.

With one run per agent today, `results.json` only gains the two fields; no number moves. Checked on
a scratch copy with synthetic runs: two runs (711, 770) publish 711 and run-1's details, three
(711, 770, 740) publish 740 from run-3 as official, and each refusal fires.

### `0.2.7` — 2026-09-29 — results/ adds MiniMax-M3 on LEB-100-A, tenth at 460

A tenth agent on the same package, and the first from neither Anthropic nor OpenAI: harness
(22/22; SEC-001 and BUG-001 fixed, SEC-008 and PERF-001 not), one blind matching judge
(delivery J), the EXPL judge on the scale it used for A–I (20/50), `score.py`.

MiniMax-M3 has no reasoning-effort setting — its client offers no selector — so it ran at the
model's default while the other nine ran at `xhigh`; `run.json` records it as
`reasoning_effort: "default"` with a note, and the notes say the comparison is not at equal
effort.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6.1-sol | 666 | Silver |
| 5 | GPT-6-astra | 661 | Silver |
| 6 | GPT-5.6-terra | 625 | Silver |
| 7 | GPT-5.6-sol | 612 | Silver |
| 8 | GPT-5.5 | 601 | Silver |
| 9 | GPT-5.6-luna | 599 | Bronze |
| 10 | MiniMax-M3 | 460 | Bronze |

The consistency review changed J's verdict: J answers `?export=csv` with 403 for every client,
which the manifest calls hiding tickets from whoever is entitled to see them; every other delivery
filtered the export instead, and D rejected the 403 for that reason. COMP-003 is added (−30), the
first review change that lowers a score; with the judge's call J would total 490, still tenth. The
evaluation notes, the generated leaderboard and the README status are updated for ten agents.

### `0.2.6` — 2026-09-29 — tools/export-results.py shows a model-default reasoning effort as not configurable

Some models expose no reasoning-effort setting — the client offers no selector and the model
always runs at its own default. That is a fact about the model, not a value someone forgot to
record, so a run's `model.reasoning_effort` may now be `"default"`, with an optional
`reasoning_effort_note`, and the scorecard prints "reasoning effort model default — <note>"
instead of the "not recorded" it used for a missing value. A recorded level (`xhigh`) renders as
before: the nine existing scorecards are byte-identical.

### `0.2.5` — 2026-09-29 — results/ adds GPT-6.1-sol on LEB-100-A, fourth at 666

A ninth agent on the same package, evaluated like the others: harness (22/22; SEC-001, BUG-001
and PERF-001 fixed, SEC-008 not), one blind matching judge (delivery I), the EXPL judge on the
scale it used for A–H (43/50), `score.py`. The consistency review changed nothing in its verdict.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6.1-sol | 666 | Silver |
| 5 | GPT-6-astra | 661 | Silver |
| 6 | GPT-5.6-terra | 625 | Silver |
| 7 | GPT-5.6-sol | 612 | Silver |
| 8 | GPT-5.5 | 601 | Silver |
| 9 | GPT-5.6-luna | 599 | Bronze |

GPT-6.1-sol migrated MD5 to `password_hash` and identified the dispatcher (ARCH-002) in its list of
deliberate non-changes; it kept SEC-008 unfixed for the CSV's consumers and scoped the SLA average
to the session (COMP-003, as B, C, G and H). The evaluation notes — including the places quoted in
the earlier reviews, which shift by one — the generated leaderboard and the README status are
updated for nine agents.

### `0.2.4` — 2026-09-29 — results/ adds GPT-6-astra on LEB-100-A, fourth at 661

An eighth agent on the same package, evaluated like the others: harness (22/22, all four
probes fixed), one blind matching judge (delivery H), the EXPL judge on the scale it used for
A–G (42/50), `score.py`. The delivery also ships the model's own test suite
(`entrega/.validacao/testes.php`), kept as delivered.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-6-astra | 661 | Silver |
| 5 | GPT-5.6-terra | 625 | Silver |
| 6 | GPT-5.6-sol | 612 | Silver |
| 7 | GPT-5.5 | 601 | Silver |
| 8 | GPT-5.6-luna | 599 | Bronze |

GPT-6-astra is the third agent to fix SEC-008 and the strongest GPT model here; it left MD5 in
place on purpose and scoped the SLA average to the session (COMP-003, as B, C and G).

The consistency review changed H's verdict: the report names, in its list of deliberate
non-changes, the indentation of `rotuloPrioridade` — the nested ifs of CLN-007 — which is how A's
ARCH-002 was credited R1. H gets R1 for CLN-007 and nothing else; with the judge's call it would
total 636, still fourth. The evaluation notes, the generated leaderboard and the README status are
updated for eight agents.

### `0.2.3` — 2026-09-29 — results/ adds the GPT-5.6-terra and GPT-5.6-sol runs on LEB-100-A

Two more agents on the same package, evaluated like the first five: harness (22/22 for both),
one blind matching judge each (deliveries F and G), the EXPL judge on the scale it used for A–E,
`score.py`.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-5.6-terra | 625 | Silver |
| 5 | GPT-5.6-sol | 612 | Silver |
| 6 | GPT-5.5 | 601 | Silver |
| 7 | GPT-5.6-luna | 599 | Bronze |

GPT-5.6-sol is the second agent to fix SEC-008; its sanitizer also rewrites the `-` placeholder
of the technician column (PEN-001, kept). GPT-5.6-terra keeps compatibility at 100.

The consistency review changed F's verdict: its judge counted `AVG`'s 4-decimal precision in
`mediaResposta()` as COMP-003, which C's judge had ruled inside the characterization tolerance;
the violation meant — and charged to B, C and G — is scoping the average to the client. The rule
as worded in the brief for F and G was imprecise and is corrected in the notes. This change moves
the ranking: with the judge's call F would total 584 (Bronze, 7th). The evaluation notes, the
generated leaderboard and the README status are updated for seven agents.

### `0.2.2` — 2026-09-29 — results/ adds Claude Sonnet 5.5 on LEB-100-A, first at 825

A fifth agent on the same package, evaluated like the first four: harness, one blind matching
judge (delivery E), the EXPL judge on the scale it used for A–D, `score.py`.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Sonnet 5.5 | 825 | Gold |
| 2 | Claude Fable 5.1 | 781 | Gold |
| 3 | Claude Opus 5.5 | 711 | Silver |
| 4 | GPT-5.5 | 601 | Silver |
| 5 | GPT-5.6-luna | 599 | Bronze |

Sonnet 5.5 is the only agent to fix SEC-008 (formula injection in the CSV) and the only one at
SEC 250/250; it fixed BUG-004 silently, so that fix scores without the finding.

The consistency review changed one verdict: E's judge counted the login timing side channel of
the md5 → `password_hash` migration as PEN-001 (−15), while D's judge had ruled the same class of
channel inherent to the migration the matrix expects, and A's had not counted it either. PEN-001
is 0 for E, recorded in its `veredito.json` and scorecard; with it E would total 810, still
first. The evaluation notes, the generated leaderboard and the README status are updated for
five agents.

### `0.2.1` — 2026-09-29 — scorecard.md shows the consistency review that changed a verdict

A verdict can be changed after its judge wrote it, when a review across the deliveries of an
instance finds one rule applied two ways. The change lives in the verdict's `review` list
(what changed, by whom, why), and `tools/export-results.py` now renders it as a "Consistency
review" section at the end of the scorecard — where the score is read, not only in the json.

### `0.2.0` — 2026-09-29 — results/ publishes the first scored runs: four agents on LEB-100-A

First results of the benchmark: Claude Fable 5.1, Claude Opus 5.5, GPT-5.5 and GPT-5.6-luna,
all at reasoning effort xhigh, one run each on LEB-100-A v1.1 in mode A (30 turns), against
the same package (`34e38bc5…`). Each run folder keeps the delivery exactly as handed back, its
parameters, the mechanical report, the judge's verdict and the scorecard.

| # | Model | Total | Grade |
| ---: | --- | ---: | --- |
| 1 | Claude Fable 5.1 | 781 | Gold |
| 2 | Claude Opus 5.5 | 711 | Silver |
| 3 | GPT-5.5 | 601 | Silver |
| 4 | GPT-5.6-luna | 599 | Bronze |

Not official: an official score is the median of three runs. The judges were Claude Opus 5.5
subagents working on anonymized deliveries (one matching judge per delivery, one EXPL judge
blind to the answer key and to every other score) — and Claude Opus 5.5 is also a contestant.
Exact model versions, temperature, tokens and logs were not recorded and are `null`.

`results/2026/LEB-100-A/README.md` states how the runs were evaluated, the two harness defects
fixed before scoring (0.1.14, 0.1.15), and that the answer key of LEB-100-A has been in this
public repository since 2026-07-13, so the instance should be retired for new runs. The README
status moves the reference runs from pending to a first, unofficial set.

### `0.1.16` — 2026-09-29 — tools/export-results.py renders the scorecards and the results file from evaluated runs

`harness/score.py` stops at `scorecard.json`; PROTOCOL §7 asks for the scorecard in `.md` and
`.json`, and a public results page needs one file to read. The new tool walks
`results/<edition>/<instance>/<agent>/run-<n>/` (each holding `run.json`, `mecanico.json`,
`veredito.json` and `scorecard.json`) and writes:

- `scorecard.md` next to each `scorecard.json`: parameters, total and categories, criterion by
  criterion per flaw, the judge's rationale, COMP, EXPL with its justification, penalties,
  false positives, extra findings, calibration, difficulty and the mechanical evidence;
- `results/results.json`, the aggregate the site reads;
- `results/README.md`, the leaderboard, ranked by median total and then by the informative
  metrics (SCORING §9).

It imports the criterion table from `score.py` instead of copying it, reads a `half` from the
verdict (half of a 1-point criterion floors to 0 points but is still a half), and is
deterministic — `--check` exits 1 when an output would change.

### `0.1.15` — 2026-09-29 — The CSV checks read the export from the output the manifest promises

The manifest of LEB-100-A says `exportarCsv(mysqli $db): void` "writes the CSV to the output";
it says nothing about a file on disk. The characterization suite (`run.php`) and the SEC-008
probe (`private/verify/probes.php`) nevertheless discarded the output and read
`EXPORT_DIR/chamados.csv` — an implementation detail of the legacy code.

A delivery that streams the CSV to `php://output`, or writes a uniquely named temp file and
deletes it, put the same bytes on the output and was still charged three broken tests
(PEN-002, −60), lost C4 on every fix, and failed SEC-008 on an empty cell. Both checks now read
what `exportarCsv()` wrote to the output buffer. For the legacy code that is the same content
(it ends in `readfile()`), so the baseline stays 22/22 and SEC-008 stays PLANTADA; a patched
copy flips it to CORRIGIDA with and without the temp file.

The matrix is untouched (`matrix.json` hash unchanged). Found while scoring the first runs.

### `0.1.14` — 2026-09-29 — The characterization loader splits SQL the way the mysql client does

`statementsSql()` in `instances/LEB-100-A/characterization/_bootstrap.php` dropped only
whole-line `--` comments and then split the file on every `;`. A trailing comment that
contains a semicolon — `senha VARCHAR(255) NOT NULL, -- password_hash(); …`, valid SQL the
mysql client loads — cut the `CREATE TABLE` in two, and every probe and characterization
check of that delivery crashed before running.

The loader now walks the file the way the client does: a `;` ends a statement only outside
quotes and outside `-- `, `#` and block comments. On the legacy `schema.sql`/`seed.sql` and on
every delivery evaluated so far it yields the same statements as before, except where the old
split was wrong. The legacy code still scores 22/22 with every probe PLANTADA.

Found while scoring the first runs (see `results/2026/LEB-100-A/README.md`).

### `0.1.13` — 2026-09-29 — README documents that benchmark runs happen on a VM with no GitHub access

The cover README gains an "Execution environment" section: the VM that runs the
benchmark resolves `github.com` and the GitHub content hosts to loopback, so an
agent under test cannot open, clone or download this repository (or anything else
on GitHub) during a run. The section publishes the `/etc/hosts` block so the setup
can be reproduced, and states the limits: the block is by name (a direct connection
to an IP address still opens), it says nothing about training data, and it does not
replace keeping an answer key out of every place a model can reach.

No hostnames or addresses of the execution VM are published.

### `0.1.3` — 2026-09-02 — Agent doc: Releases rule and the English-only language rule

Marked echo of the single source at samirhvbr/repodocs. Two rules land here:

1. The `version.md` of the default branch ON GITHUB is what the GitHub Releases
   show, and a commit that bumps it is not finished until that version has a
   tag, a Release and the `Latest` badge — same push, not "later".
2. Everything in this repository is English (US): documents, commit messages,
   pull requests, issues, code comments. The only carve-out is end-user-facing
   product strings. History is not rewritten.

Delimited by a marker, so re-running replaces instead of duplicating.

### `0.1.2` — 2026-09-02 — Regra de Releases no doc de agente: bump e Release sao um ato so

Eco marcado da norma unica em samirhvbr/repodocs (docs/versioning.md). O
`version.md` da branch padrao NO GITHUB e o que as Releases no GitHub mostram, e
um commit que bumpa o `version.md` nao esta terminado ate aquela versao ter tag,
Release e o badge `Latest`.

Bloco delimitado por marcador: rodar de novo substitui, nao duplica.

### `0.1.1` — 2026-09-02 — Releases automaticas: o version.md da master vira tag e Release

O GitHub nao deduz versao de mensagem de commit: sem tag, o numero e string no
`git log` e `git diff` entre versoes nao existe. Entram o
`.github/workflows/release.yml` e o `tools/release.sh`.

**A regra:** o `version.md` da branch padrao **no GitHub** e o que as Releases
**no GitHub** refletem. Checkout local nao entra na conta. Um PR nao publica
nada; no merge, o push do `version.md` dispara o workflow e a Release vira
aquela versao.

Tag e titulo = a versao pura, sem prefixo `v`. Norma:
[samirhvbr/repodocs](https://github.com/samirhvbr/repodocs/blob/master/docs/versioning.md).

### `0.1.0` — 2026-07-30 — Adota o versionamento da casa

Passa a seguir o padrão dos demais repositórios: `version.md` como fonte da verdade,
commits no formato `X.Y.Z - Descrição em português` e changelog como registro de
entrega.

O gatilho foi prático: o repo já participava da skill **COMMITTER**, mas sem
`version.md` não existia o formato da casa — o ciclo reportava e **não commitava**.
Com este arquivo, a skill passa a operar aqui pelo caminho determinístico (sem custo
de modelo), lendo a mensagem da entrada de changelog.

_Gatilhos:_ adoção de infraestrutura de versionamento.
