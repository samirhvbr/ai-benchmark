# LEB-100-A · 2026 — evaluation notes

Written by hand; the leaderboard itself is generated into [`../../README.md`](../../README.md)
by `tools/export-results.py`. This page says how these numbers were produced and what to keep
in mind before quoting them.

## What was evaluated

Ten agents, one run each, against the same package: LEB-100-A v1.1 (spec 1.1.0, task 1.0.0),
mode **A** (agentic, 30-turn budget), matrix `68088abd…c8625`, package `34e38bc5…a15f`. Every
`entrega/.leb-pacote.sha256` is byte-identical, and every `achados.json` carries the right binding
(`PROTOCOL §2.2`), so the ten deliveries solved the same task. One thing is not equal: nine
agents ran at reasoning effort `xhigh`, while MiniMax-M3 has no effort setting at all — its client
offers no selector — and ran at the model's default. Its result measures the model as it can be
run, not a like-for-like comparison of effort.

| Agent folder | Model | Reasoning effort |
| --- | --- | --- |
| `claude-fable-5.1-xhigh` | Claude Fable 5.1 (Anthropic) | xhigh |
| `claude-opus-5.5-xhigh` | Claude Opus 5.5 (Anthropic) | xhigh |
| `claude-sonnet-5.5-xhigh` | Claude Sonnet 5.5 (Anthropic) | xhigh |
| `gpt-5.5-xhigh` | GPT-5.5 (OpenAI) | xhigh |
| `gpt-5.6-luna-xhigh` | GPT-5.6-luna (OpenAI) | xhigh |
| `gpt-5.6-sol-xhigh` | GPT-5.6-sol (OpenAI) | xhigh |
| `gpt-5.6-terra-xhigh` | GPT-5.6-terra (OpenAI) | xhigh |
| `gpt-6-astra-xhigh` | GPT-6-astra (OpenAI) | xhigh |
| `gpt-6.1-sol-xhigh` | GPT-6.1-sol (OpenAI) | xhigh |
| `minimax-m3` | MiniMax-M3 (MiniMax) | model default (not configurable) |

Not recorded for these runs, and marked `null` in each `run.json` rather than guessed: the exact
model version, the temperature, token counts and cost, and the full logs (`PROTOCOL §4.2` asks for
logs; they were not kept). Nor was how often the operator replied to an agent that stopped to wait,
or with what: the fixed reply of `PROTOCOL §3` was written after these runs. The deliveries were filed and evaluated on 2026-09-29, MiniMax-M3's
on 2026-09-30.

## How it was evaluated

1. **Mechanical** — `harness/leb_harness.py` on each `entrega/code`: characterization before and
   after (22 checks) and the four fix probes (SEC-001, SEC-008, BUG-001, PERF-001).
2. **Matching (step 4)** — one judge per delivery, following `scoring/JUDGE.md`, with the matrix,
   the legacy code, the delivery and its mechanical report.
3. **Explanation (step 5)** — one judge for all ten reports, which read only the reports, the
   task, the manifest and the legacy code: never the matrix, the findings index, the mechanical
   reports or any verdict.
4. **Scorecard (step 7)** — `harness/score.py`, then `tools/export-results.py` for the `.md`.

Every judge was a **Claude Opus 5.5** subagent, and every delivery was **anonymized** before
judging: copied without its folder name, with identity-bearing paths removed from the mechanical
report, and checked for model names (none of the deliveries names its author). The labels were
drawn at random and revealed only after all verdicts were in: **A** = Claude Fable 5.1,
**B** = GPT-5.6-luna, **C** = GPT-5.5, **D** = Claude Opus 5.5, **E** = Claude Sonnet 5.5,
**F** = GPT-5.6-terra, **G** = GPT-5.6-sol (F and G drawn at random between the two),
**H** = GPT-6-astra, **I** = GPT-6.1-sol, **J** = MiniMax-M3. Deliveries D to J arrived after the
first three; the EXPL judge scored each on the scale it had already used and changed nothing in
the earlier scores. The matching judges of E to J were told the rules below, which the earlier
judges had applied, so that they would apply them the same way; H's to J's judges got them in the
corrected wording, and I's and J's also got the rule that came out of H's review. GPT-6-astra's delivery
also ships its own test suite (`entrega/.validacao/testes.php`, 119 checks), kept as delivered.

The matching verdicts were then reviewed for consistency across the ten judges. Two rules could
have been applied unevenly and were not: C1 at half for a flaw filed under the wrong category
(BUG-004 filed as `qualidade` by B and as `seguranca` by D, as `bug` by A and C), and COMP-003
for scoping the SLA average to the client (B, C, G, H and I do it; A, D, E and F keep it global).

Four verdicts were changed, each recorded in its `veredito.json` (`review`) and scorecard.

- **E, PEN-001 removed.** E's judge counted a login timing side channel as a new bug (PEN-001,
−15): E runs a dummy bcrypt for unknown logins, while a legacy md5 account is rejected in
microseconds. A and D open the same class of channel with the same md5 → `password_hash`
migration (unknown and md5 logins fast, bcrypt logins slow), and D's judge ruled it inherent to
the migration the matrix expects; A's did not count it either. One rule for all: PEN-001 is 0 for
  E. With the penalty, E would total 810 and still rank first.
- **F, COMP-003 removed.** F's judge counted as COMP-003 that `mediaResposta()` now uses `AVG`,
  which MySQL returns with 4 decimals (25.6667 instead of 25.666666666666668). C made the same
  change and its judge ruled it inside the characterization tolerance — the page shows the same
  rounded minutes. The rule as the brief worded it for F and G ("a calculated number such as the
  SLA average") was imprecise and invited that reading; the violation meant is changing *which*
  tickets the average covers. COMP-003 is removed and BUG-001 C5 restored for F. **This one moves
  the ranking:** with the judge's call F would total 584 (Bronze, 9th) instead of 625 (Silver, 6th).
- **H, CLN-007 identified.** H's judge did not count the report's decision 3 — it left
  `rotuloPrioridade` alone because "simplifying the indentation" did not justify a larger diff —
  as reporting the nested ifs. A's ARCH-002 was credited R1 from an entry of its own not-changed
  list that named the smell at the right place; this entry does the same, so H gets R1 (and
  nothing else: no mechanism, no refactor). With the judge's call H would total 636, still 5th.
- **J, COMP-003 added.** J answers the contracted route `index.php?export=csv` with HTTP 403 for
  every client, while the listing still offers them the "Exportar CSV" link. Every other delivery
  that closed the CSV visibility gap filtered the export to the client's own tickets — ruled
  enforcement, not a violation — and D explicitly rejected denying the route because it would hide
  tickets the client is entitled to see. The manifest makes that a business-rule change in both
  directions (exposing tickets to whoever is not entitled, hiding them from whoever is); J's judge
  flagged the call for a consistency check. COMP-003 is added, attributed to J's extra finding on
  CSV visibility, so no planted flaw's C5 changes. With the judge's call J would total 490, still
  10th — the only change in review that lowers a score.

I's verdict needed no change. Two calls were checked and kept: G's PEN-001 (its CSV sanitizer also rewrites the `-` shown for a
ticket with no technician into `'-`, a concrete defect nothing asked for), and G's claim of a
stored XSS that the legacy code does not have — part of the same finding that correctly reports
the reflected XSS (SEC-003), so it counts once as a hit in calibration and is penalized where it
belongs, in the EXPL precision score (5/10).

### Second and third runs

The ten agents above get up to two more runs each on the same package, so that each has the
three runs an official score needs (`PROTOCOL §4`). A run is filed in the agent's own folder as the
next `run-<n>`. The same folder name means the same model at the same effort; a changed model
version, effort or client is a different agent, with its own folder. Every run is filed and
published, the bad ones included, because discarding one and running again is the retry §4
forbids. A run is judged like the first ones:

- its own matching judge, on the anonymized delivery, with a new label that continues the letters
  (K, L, …) so that a judge cannot tell a second run from a first one;
- the EXPL judge on the scale it has already used, without changing an earlier score;
- the consistency review across every delivery of the instance, first runs included.

Until an agent has three runs, its published score is the lower of its totals so far, and every
detail shown with it comes from that same run (`PROTOCOL §4`, item 4).

## Two defects in the harness, fixed before scoring

Both were found by these deliveries, fixed in the instance's tooling, and applied identically to
all ten runs; the legacy code still scores 22/22 with every probe PLANTADA.

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
  without the temp file. (Opus 5.5, Sonnet 5.5, GPT-5.6-sol, GPT-5.6-terra, GPT-6-astra and GPT-6.1-sol,
  evaluated after the fix, also stream to `php://output`.)

## Before quoting a number

- **One run each.** An official score is the median of three runs (`PROTOCOL §4`). GPT-6.1-sol
  and GPT-6-astra (666 and 661) are five points apart; places 6 to 9 (625, 612, 601 and 599) sit
  within 26 points, and GPT-5.5 (601, Silver) and GPT-5.6-luna (599,
  Bronze) are two points apart across a grade line: that is within the noise of a single run.
- **The judge is also a contestant.** Claude Opus 5.5 judged and was judged. Anonymity limits the
  bias; it does not remove it, since a model can recognise its own style — or a sibling's: three of
  the ten contestants are Claude models, and they hold the top three places. The two calls against delivery D (COMP-003 on
  `formatarStatus`, R2 none on CLN-007) were kept as the judge made them. The four changes made in
  review are explained above: E, H and J move no place; F lifts GPT-5.6-terra from 9th to 6th. Every verdict carries a rationale per flaw so it can be audited.
- **Not the same effort for everyone.** MiniMax-M3 ran at its model's default — it has no effort
  setting — while the other nine ran at `xhigh`.
- **The answer key is public.** `instances/LEB-100-A/private/` has been in this public repository
  since 2026-07-13, although `matrix/MATRIX.md §4` says an active matrix is published only as its
  hash. The instance stays current under the exception in `MATRIX §4`, item 5. During these ten
  runs the execution VM blocked GitHub by name only. No agent could fetch the key through a GitHub
  name, but a deliberate connection straight to a GitHub address was not blocked. The address
  blocks came on 2026-09-30 (README, *Execution environment*). Each run also records the
  training cutoff its provider publishes: nine of the ten models have a cutoff before 2026-07-13,
  and MiniMax publishes none for M3, so for that model training on the key cannot be ruled out. It
  is marked † in the leaderboard, and its scorecard says so.

## Reading the results

- **SEC-008** (formula injection in the CSV) was fixed by Sonnet 5.5, GPT-5.6-sol and GPT-6-astra,
  each by prefixing `'` to a cell that starts with `= + - @` — the fix the matrix expects. GPT-5.6-sol
  applied it to the technician column too and turned the `-` placeholder into `'-` (its PEN-001).
  Fable 5.1, Opus 5.5, GPT-5.6-luna and GPT-6.1-sol reported it and kept the cells raw for the CSV's
  consumers; GPT-5.5, GPT-5.6-terra and MiniMax-M3 did not report it. Sonnet 5.5 is the only agent
  at SEC 250/250.
- **Architecture** is the weakest category for everyone: 50, 25 and 50 of 200 for the three
  Claude models, 25 for GPT-6.1-sol and 0 for the other six. Nobody refactored the dispatcher
  (ARCH-002) — Sonnet 5.5, Fable 5.1, Opus 5.5 and GPT-6.1-sol identified it and declined — and
  nobody named the magic numbers (ARCH-009). Only Sonnet 5.5 and Fable 5.1 flattened the nested
  ifs (CLN-007).
- Every agent stayed on mysqli, kept all 22 characterization checks green and reported no decoy.
  **Compatibility** is where they separated: Sonnet 5.5, Fable 5.1 and GPT-5.6-terra are at 100;
  the other seven each lost 30 for a changed business value (COMP-003).
- Six agents migrated the MD5 passwords to `password_hash` transparently at login; GPT-5.5,
  GPT-5.6-luna, GPT-6-astra and MiniMax-M3 left MD5 in place on purpose. Fable 5.1 left the
  secrets in `config.php` as literal fallbacks (SEC-015 not fixed), MiniMax-M3 removed the fallback
  but repeated the production password in a comment (half), and the other eight removed them.
- **GPT-6.1-sol and GPT-6-astra are the strongest GPT models here** (666 and 661, 4th and 5th):
  both identified 11 of the 13 planted flaws and fixed 9, have BUG at 150/150 and the best
  explanations among the GPT models (43 and 42 of 50; the Claude models scored 44–46). 6.1-sol
  migrated MD5 and left SEC-008 alone; astra did the opposite.
- **MiniMax-M3** (460, 10th — the only model from neither Anthropic nor OpenAI, and the only one at
  default effort) identified 9 planted flaws and fixed 5. It is the only agent that left the N+1
  query (PERF-001) in place, on the mistaken ground that a JOIN would change the listing's
  return shape; it escaped one of the two XSS sinks, and its report has the lowest explanation
  score (20/50), with several wrong mechanisms — a division by zero described as returning NAN,
  a search it said could be reached without logging in.
- **Silent fixes** score the fix but not the finding (`MATRIX §5.4`): Sonnet 5.5, GPT-5.6-sol and
  GPT-5.6-terra fixed the file-handle leak (BUG-004) without reporting it, and GPT-5.6-terra
  fixed the session fixation (SEC-013) the same way. GPT-5.6-terra reported the fewest planted
  flaws (discovery 45.8) and still ranks sixth, on compatibility and on what it did fix.
- The GPT models are the best **calibrated** (Brier 0.000–0.006): fewer findings, each stated with
  high confidence and each real.
