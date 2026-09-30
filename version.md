# Versão — AI-BENCHMARK

**Versão atual:** `0.2.2`

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
