# Versão — AI-BENCHMARK

**Versão atual:** `0.2.33`

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
