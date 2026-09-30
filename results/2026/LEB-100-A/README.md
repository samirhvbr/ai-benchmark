# LEB-100-A · 2026 — evaluation notes

Written by hand; the leaderboard itself is generated into [`../../README.md`](../../README.md)
by `tools/export-results.py`. This page says how these numbers were produced and what to keep
in mind before quoting them.

## What was evaluated

Five agents, one run each, against the same package: LEB-100-A v1.1 (spec 1.1.0, task 1.0.0),
mode **A** (agentic, 30-turn budget), matrix `68088abd…c8625`, package `34e38bc5…a15f`. Every
`entrega/.leb-pacote.sha256` is byte-identical, and every `achados.json` carries the right binding
(`PROTOCOL §2.2`), so the five deliveries are comparable.

| Agent folder | Model | Reasoning effort |
| --- | --- | --- |
| `claude-fable-5.1-xhigh` | Claude Fable 5.1 (Anthropic) | xhigh |
| `claude-opus-5.5-xhigh` | Claude Opus 5.5 (Anthropic) | xhigh |
| `claude-sonnet-5.5-xhigh` | Claude Sonnet 5.5 (Anthropic) | xhigh |
| `gpt-5.5-xhigh` | GPT-5.5 (OpenAI) | xhigh |
| `gpt-5.6-luna-xhigh` | GPT-5.6-luna (OpenAI) | xhigh |

Not recorded for these runs, and marked `null` in each `run.json` rather than guessed: the exact
model version, the temperature, token counts and cost, and the full logs (`PROTOCOL §4.2` asks for
logs; they were not kept). The deliveries were filed and evaluated on 2026-09-29.

## How it was evaluated

1. **Mechanical** — `harness/leb_harness.py` on each `entrega/code`: characterization before and
   after (22 checks) and the four fix probes (SEC-001, SEC-008, BUG-001, PERF-001).
2. **Matching (step 4)** — one judge per delivery, following `scoring/JUDGE.md`, with the matrix,
   the legacy code, the delivery and its mechanical report.
3. **Explanation (step 5)** — one judge for all five reports, which read only the reports, the
   task, the manifest and the legacy code: never the matrix, the findings index, the mechanical
   reports or any verdict.
4. **Scorecard (step 7)** — `harness/score.py`, then `tools/export-results.py` for the `.md`.

Every judge was a **Claude Opus 5.5** subagent, and every delivery was **anonymized** before
judging: copied without its folder name, with identity-bearing paths removed from the mechanical
report, and checked for model names (none of the deliveries names its author). The labels were
drawn at random and revealed only after all verdicts were in: **A** = Claude Fable 5.1,
**B** = GPT-5.6-luna, **C** = GPT-5.5, **D** = Claude Opus 5.5, **E** = Claude Sonnet 5.5.
Deliveries D and E arrived after the others; the EXPL judge scored each on the scale it had
already used and changed nothing in the earlier scores. The matching judge of E was told the two
rules below, which the earlier judges had applied, so that it would apply them the same way.

The matching verdicts were then reviewed for consistency across the five judges. Two rules could
have been applied unevenly and were not: C1 at half for a flaw filed under the wrong category
(BUG-004 filed as `qualidade` by B and as `seguranca` by D, as `bug` by A and C), and COMP-003
for changing the SLA average (B and C scope `mediaResposta` to the client; A, D and E keep it
global).

One verdict was changed. E's judge counted a login timing side channel as a new bug (PEN-001,
−15): E runs a dummy bcrypt for unknown logins, while a legacy md5 account is rejected in
microseconds. A and D open the same class of channel with the same md5 → `password_hash`
migration (unknown and md5 logins fast, bcrypt logins slow), and D's judge ruled it inherent to
the migration the matrix expects; A's did not count it either. One rule for all five: PEN-001 was
set to 0 for E, and the change is recorded in its `veredito.json` (`review`) and scorecard. With
the penalty, E would total 810 and still rank first.

## Two defects in the harness, fixed before scoring

Both were found by these deliveries, fixed in the instance's tooling, and applied identically to
all five runs; the legacy code still scores 22/22 with every probe PLANTADA.

- **SQL loader.** `characterization/_bootstrap.php` split `schema.sql` on every `;` after dropping
  only whole-line comments. The Fable 5.1 delivery wrote `-- password_hash(); …` as a trailing
  comment inside `CREATE TABLE`, valid SQL that the mysql client loads, and the loader cut the
  statement in two: every probe crashed. The Opus 5.5 and Sonnet 5.5 deliveries have the same kind
  of comment. The loader now splits the way the mysql client does.
- **CSV read from a temp file.** The characterization and the SEC-008 probe read
  `EXPORT_DIR/chamados.csv` from disk, while the manifest promises only that `exportarCsv()`
  "writes the CSV to the output". GPT-5.5 streams the CSV straight to `php://output`; GPT-5.6-luna
  writes a uniquely named temp file, sends it and deletes it. Both put the same bytes on the
  output and leave no `chamados.csv` behind, and both were charged three broken tests (PEN-002,
  −60) and lost C4 on every fix. Both checks now read the output. The SEC-008 probe was
  re-validated both ways: still planted on the legacy code, fixed on a patched copy, with and
  without the temp file. (Opus 5.5 and Sonnet 5.5, evaluated after the fix, also stream to
  `php://output`.)

## Before quoting a number

- **One run each.** An official score is the median of three runs (`PROTOCOL §4`). GPT-5.5 (601,
  Silver) and GPT-5.6-luna (599, Bronze) are two points apart across a grade line: that is
  within the noise of a single run.
- **The judge is also a contestant.** Claude Opus 5.5 judged and was judged. Anonymity limits the
  bias; it does not remove it, since a model can recognise its own style — or a sibling's: three of
  the five contestants are Claude models. The two calls against delivery D (COMP-003 on
  `formatarStatus`, R2 none on CLN-007) were kept as the judge made them; the one change made in
  review (PEN-001 on E) is explained above and does not change the ranking. Every verdict carries
  a rationale per flaw so it can be audited.
- **The answer key is public.** `instances/LEB-100-A/private/` has been in this public repository
  since 2026-07-13, although `matrix/MATRIX.md §4` says an active matrix is published only as its
  hash. The execution VM blocks GitHub by name, so the agents could not fetch it during the runs,
  but it may have reached training data. LEB-100-A should be retired for new runs (`MATRIX §4.3`).

## Reading the results

- Only Sonnet 5.5 fixed **SEC-008** (formula injection in the CSV), by prefixing `'` to a title
  that starts with `= + - @` — the fix the matrix expects — and it is the only one with SEC at
  250/250. Fable 5.1, Opus 5.5 and GPT-5.6-luna reported it and kept the cells raw for the CSV's
  consumers; GPT-5.5 did not report it.
- **Architecture** is the weakest category for all five (50, 25, 50, 0 and 0 of 200). Nobody
  refactored the dispatcher (ARCH-002) — Sonnet 5.5, Fable 5.1 and Opus 5.5 identified it and
  declined — and nobody named the magic numbers (ARCH-009).
- Every agent stayed on mysqli, kept all 22 characterization checks green and reported no decoy.
  **Compatibility** is where they separated: Sonnet 5.5 and Fable 5.1 are at 100; the other three
  each lost 30 for a changed business value (COMP-003).
- Sonnet 5.5, Fable 5.1 and Opus 5.5 migrated the MD5 passwords to `password_hash` transparently
  at login; both GPT models left MD5 in place on purpose. Fable 5.1, on the other hand, left the
  secrets in `config.php` as literal fallbacks (SEC-015 not fixed), which the other four removed.
- Sonnet 5.5 fixed the file-handle leak (BUG-004) without reporting it: a silent fix scores the
  fix but not the finding (`MATRIX §5.4`), which is why its BUG score is the lowest.
