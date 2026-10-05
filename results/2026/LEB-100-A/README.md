# LEB-100-A · 2026 — evaluation notes

Written by hand; the leaderboard itself is generated into [`../../README.md`](../../README.md)
by `tools/export-results.py`. This page says how these numbers were produced and what to keep
in mind before quoting them.

## What was evaluated

Twenty-seven agents against the same package: LEB-100-A v1.1 (spec 1.1.0, task 1.0.0), mode **A**
(agentic, 30-turn budget), matrix `68088abd…c8625`, package `34e38bc5…a15f`. Ten ran on
2026-09-29, one run each. On 2026-09-30 Claude Opus 5.5 got its second run, and GLM-5.2, Kimi K3,
GLM-5.3, GLM-5.3-Flash, GLM-5.3-FlashX, Grok 4.7, Grok 4.6, Kimi K2.7 Code, DeepSeek V4 Flash, DeepSeek V4.1 Flash, Qwen3 Coder Next and Claude Sonnet 5.5 in Claude Code's multi-agent mode
their first (see *Runs of 2026-09-30* below). On 2026-10-01 GPT-6.1-sol at effort `ultra`, GPT-6.1-sol
pro, GPT-5.3-Codex and GLM-5.3 Prime got theirs, Claude Sonnet 5.5 at `xhigh` its second, and
Claude Opus 5.5 its third, the first official result, Claude Fable 5.1 its second, Claude Haiku 4.5 its first two, Claude Sonnet 5.5 its third (the second
official result), and GPT-6.1-sol and GLM-5.3 their second (see *Runs of 2026-10-01*).

Every `entrega/.leb-pacote.sha256` is byte-identical, and every `achados.json` carries the right
binding (`PROTOCOL §2.2`), so all the deliveries solved the same task.

Effort is not equal:

- nine agents ran at reasoning effort `xhigh` on 2026-09-29 and 30, and GPT-6.1-sol pro and
  GPT-5.3-Codex at `xhigh` on 2026-10-01;
- GPT-6.1-sol ran a second configuration at `ultra`, the level above `xhigh` in Codex;
- Claude Sonnet 5.5 in multi-agent mode ("ultracode") ran at `max`, the setting that turns that
  mode on;
- MiniMax-M3 has no effort setting at all (its client offers no selector) and ran at the model's
  default;
- the five GLM models, the two Grok models and the two DeepSeek models ran at `high`, the level chosen in their client;
- Kimi K3 is filed at `default`, and whether its client offered a setting was not recorded;
- Kimi K2.7 Code has no effort setting in its client and ran at the model's default;
- Claude Haiku 4.5 does not support the effort setting, so it ran at the model's default although
  the client accepted `/effort xhigh`;
- Qwen3 Coder Next is a non-thinking model with no effort setting in its client, and ran at the
  model's default.

Those two results measure the model as it was run, not a like-for-like comparison of effort.

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
| `z.ai-glm-5.2-high` | GLM-5.2 (Z.AI), in opencode 1.18.33 | high |
| `moonshot-kimi-k3-default` | Kimi K3 (Moonshot AI) | default (as filed) |
| `glm-5.3-high` | GLM-5.3 (Z.AI), in opencode 1.18.33 | high |
| `glm-5.3-flash-high` | GLM-5.3-Flash (Z.AI), in opencode 1.18.33 | high |
| `glm-5.3-flashX-high` | GLM-5.3-FlashX (Z.AI), in opencode 1.18.33 | high |
| `grok-4.7` | Grok 4.7 (xAI), in opencode 1.18.33 | high |
| `grok-4.6` | Grok 4.6 (xAI), in opencode 1.18.33 | high |
| `kimi-k2.7-code-default` | Kimi K2.7 Code (Moonshot AI), in opencode 1.18.33 | default (not configurable) |
| `deepseek-v4-flash-high` | DeepSeek V4 Flash (DeepSeek, served by Novita AI), in opencode 1.18.33 | high |
| `deepseek-v4.1-flash-high` | DeepSeek V4.1 Flash (DeepSeek's own API), in opencode 1.18.33 | high |
| `qwen3-coder-next-default` | Qwen3 Coder Next (Alibaba, served by Novita AI), in opencode 1.18.33 | default (non-thinking, not configurable) |
| `claude-sonnet-5.5-max-ultracode` | Claude Sonnet 5.5 (Anthropic), Claude Code's multi-agent mode | max |
| `gpt-6.1-sol-ultra` | GPT-6.1-sol (OpenAI), in Codex CLI 0.159.3 | ultra |
| `gpt-6.1-sol-pro-xhigh` | GPT-6.1-sol pro (OpenAI, served by OpenRouter), in opencode 1.18.33 | xhigh |
| `gpt-5.3-codex-xhigh` | GPT-5.3-Codex (OpenAI, served by the Kilo Code gateway), in opencode 1.18.33 | xhigh |
| `claude-haiku-4.5-default` | Claude Haiku 4.5 (Anthropic), in Claude Code 2.1.285 | default (not supported) |
| `glm-5.3-prime-high` | GLM-5.3 Prime (Z.AI, served by the Kilo Code gateway), in opencode 1.18.33 | high |

Not recorded for these runs, and marked `null` in each `run.json` rather than guessed: the exact
model version and the temperature.

**Machine size.** Every run so far was made on the execution VM at **4 vCPUs and 3.8 GiB of RAM**,
which it kept from 2026-09-25 until 2026-09-30 13:01. `execution_host` in each `run.json` says so.
The exception is Kimi K3, whose clone's size was not recorded. Single-session clients spend most
of a run waiting on the API, so the size weighs little on them. A multi-agent client sizes its
work to the machine, which is why the size is now a run parameter (`PROTOCOL §3`). The VM was
resized to 20 vCPUs and 15.6 GiB after that, and Sonnet 5.5's multi-agent run was made on it at
that size. Qwen3 Coder Next ran on the second VM, `ai-bench2`, a clone of the first at the same size (20 vCPUs, 15.6 GiB).
The runs of 2026-10-01 ran on three VMs of that size, `bench1`, `bench2` (the former `ai-bench2`)
and `bench3`, each prepared clean that morning under the rule of `PROTOCOL §3`.

**What the session logs show.** Eight of the ten runs left their client's session log on the
execution VM. They were read on 2026-09-30, and each `run.json` now records what they show:

- the client and its version: Claude Code 2.1.285 for the three Claude models, Codex CLI 0.158.0
  for GPT-5.6-luna and 0.159.2 for the other four GPT models;
- the session's start and end;
- the first message the agent received;
- every later message from the operator;
- the tokens the client counted, plus model time and cost where the client kept them (only
  Sonnet 5.5's).

The logs were copied off the VM on 2026-09-30 and are not published. GPT-5.5's and MiniMax-M3's
runs left no log there, so for those two every one of these fields is `null`.

- **No agent tried to reach GitHub.** None of the eight sessions made a request to GitHub, by name
  or by address, and none used a web search or fetch tool. Their network calls all go to the
  local test servers the agents started themselves (`127.0.0.1`, the MariaDB socket).
- **The first message** was *"Não saia desta pasta. Agora leia o TAREFA.md e execute. Devolva
  code/ alterado, RELATORIO.md e achados.json."* in all eight.
- **Operator messages.** Two agents received that message a second time mid-run, verbatim:
  Opus 5.5 after 12 minutes and GPT-5.6-luna after 2 minutes. It adds no information, but it is
  counted as an operator reply. Fable 5.1's first message was interrupted two seconds in, while the
  client was still on Sonnet 5.5, and sent again after switching to Fable. That is not counted. The
  fixed reply of `PROTOCOL §3` was written after these runs. The deliveries were filed and evaluated on 2026-09-29, MiniMax-M3's
on 2026-09-30.

**Machine state.** Every run on the first VM, up to Claude Sonnet 5.5's multi-agent run that
began at 13:05 on 2026-09-30, was made as its administrator account, which has passwordless sudo,
on a machine that kept what earlier runs left behind: the clients' session logs in the same home
folder, temporary files, and a `suporte` database with a `painel` user in the system MariaDB. Every tool call in the session logs that
exist was read on 2026-09-30 for any sign that one run used another's leftovers:

- no session read another run's folder, report, findings index or session log;
- the Claude and Codex runs tested against private databases they started themselves; Fable 5.1's
  one attempt to reach the system database was refused by its own client;
- the opencode runs used the system MariaDB through sudo, and the `suporte` database carried over
  from run to run. The agents that met it read only its rows, which match `seed.sql`, dropped and
  recreated its tables, or used a database of their own. None inspected the table structure an
  earlier agent had left. One side effect: GLM-5.3-Flash set the `painel` user's password to the
  one hardcoded in `config.php`, and GLM-5.3-FlashX and Grok 4.7, which ran after it, connected
  with it.

From 2026-09-30 17:52 on, runs are made as an unprivileged user, with no sudo and no account
on the system database, on a second VM cleaned of all of that (`PROTOCOL §3`, README *Execution
environment*).

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

### Runs of 2026-09-30

Fourteen deliveries arrived. The first three were labelled at random **K** to **M**, and **N** to
**X** in the order the others arrived. Each was judged blind like the first ten, and the labels were revealed only after
every verdict was in:

| Label | Run | Outcome |
| --- | --- | --- |
| **K** | Claude Opus 5.5, run 2 | 717 |
| **L** | Claude Fable 5.1 | void, see below |
| **M** | Kimi K3, run 1 | 528 |
| **N** | GLM-5.2, run 1 | 388 |
| **O** | GLM-5.3, run 1 | 629 |
| **P** | GLM-5.3-Flash, run 1 | 624 |
| **Q** | GLM-5.3-FlashX, run 1 | 597 |
| **R** | Grok 4.7, run 1 | 638 |
| **S** | Grok 4.6, run 1 | 633 |
| **T** | Kimi K2.7 Code, run 1 | 415 |
| **U** | DeepSeek V4 Flash, run 1 | 612 |
| **V** | DeepSeek V4.1 Flash, run 1 | 625 |
| **W** | Qwen3 Coder Next, run 1 | 507 |
| **X** | Claude Sonnet 5.5, multi-agent mode, run 1 | 774 |

- **The EXPL judge is a second instance.** The one that scored A–J could not be resumed. Its
  successor read all ten earlier justifications, and the reports at the top, the bottom and the
  middle of that scale, before scoring K–N. It changed none of the earlier scores. Its one note,
  on C's CSV-header claim (at most one point), is recorded and changes nothing.
- **Nothing changed in the consistency review.** L's and M's LIKE-wildcard escaping is not
  COMP-003 (as ruled for D). N's SEC-013, named only in its list of non-changes, is C1 without C2
  (rule 5). N's SEC-017 and M's BUG-004, filed under another category, get C1 at half.
- **Opus 5.5, run 2 (K): 717, six points above run 1 (711).**
  - The published score is the lower, 711.
  - Its first message is the fixed one, without the "Não saia desta pasta." that run 1 received
    (see below).
  - A first attempt in the same folder, at 08:28, was stopped by the operator after six minutes and
    three tool calls, before any delivery. It had started before the VM's last isolation layer
    (GitHub's edge addresses, 09:02) was in place. This run started after that layer and replaced
    it.
- **Fable 5.1's second run (L) is void.** Six minutes in, the model's safeguards stopped one of
  its responses, and Claude Code finished the run with Claude Opus 4.8. Opus 4.8 wrote the whole
  report and findings index. The run was voided on the evidence of the session log, before its
  score was assembled or its label matched to the model. It is kept unscored in
  `claude-fable-5.1-xhigh/void-1/`, with the full account in its `VOID.md`, and Fable's next run is
  its run 2.
- **GLM-5.2 (N): 388, the first result below 400 (Reprovada).**
  - It kept the contract whole (COMP 100) and the 22 characterization checks green.
  - It fixed SEC-001, SEC-003, SEC-015, SEC-017 and BUG-001.
  - It did not fix PERF-001, SEC-008, SEC-013, SEC-014 or BUG-004.
  - It left the three ARCH/CLN flaws alone.
  - It ran in opencode, not in the Claude or OpenAI clients the others used.
- **Kimi K3 (M): 528.**
  - It ran on a clone of the execution VM, made to run a second session in parallel and destroyed
    afterwards. Its session log went with it.
  - The clone had at least GitHub's names blocked and no IPv6. Whether it already had the
    blackhole routes is not recorded. GitHub's edge addresses were blocked only after this
    delivery was filed.
  - Its client, the operator's messages, its tokens and its cost are not recorded, and `run.json`
    says so.
  - It kept the contract whole (COMP 100) and fixed seven flaws: SEC-001, SEC-003, SEC-013,
    SEC-015, SEC-017, BUG-001 and BUG-004.
  - It left the N+1 query (PERF-001), the CSV injection (SEC-008) and MD5 (SEC-014) in place.
- **The first message changed on 2026-09-30.**
  - Every run of 2026-09-29 began with *"Não saia desta pasta. Agora leia o TAREFA.md e execute.
    Devolva code/ alterado, RELATORIO.md e achados.json."* The operator had added the first
    sentence ("don't leave this folder") for fear that something else on the VM could give a
    hint.
  - From 2026-09-30 it is dropped, to follow the original prompt. The first message is now fixed
    as *"Agora leia o TAREFA.md e execute. Devolva code/ alterado, RELATORIO.md e achados.json."*
    (`PROTOCOL §3`).
  - Each run's `first_message` says which one it got. An agent's run 1 and its later runs
    therefore differ by that one sentence.
- **GLM-5.3 (O): 629, Silver.**
  - It ran later the same morning, in the same client and at the same effort as GLM-5.2, with
    every isolation layer in place. It got the fixed first message and no other operator message.
  - Its session log shows no request to GitHub, only calls to its own local test servers.
  - It scored 241 points above GLM-5.2. It fixed the N+1 query, SEC-001/003/013/015/017, BUG-001
    and, silently, BUG-004, and kept the contract whole (COMP 100). SEC-008 and MD5 it reported
    and chose to leave, and the architecture flaws it did not touch.
  - The consistency review kept its judge's call on `exportarCsv`, which now filters by the
    logged-in client's session. Callers without a session, such as the nightly export, still
    receive every ticket, so it enforces the visibility rule and is not COMP-003.
  - Its cost comes from opencode's record: US$ 0.73.
- **GLM-5.3-Flash (P): 624, Silver; GLM-5.3-FlashX (Q): 597, Bronze.**
  - Both are smaller variants of GLM-5.3, run the same morning in opencode at effort `high` with
    every isolation layer. Each got the fixed first message and nothing else.
  - Their session logs show no request to GitHub. Flash's report quotes
    `=HYPERLINK("http://evil",…)` as a formula-injection example; that is text, not a request.
  - Flash lands five points below GLM-5.3 for a tenth of the cost (US$ 0.08 against US$ 0.73).
    Unlike GLM-5.3, it migrated MD5 and left the secrets in place. FlashX fixed five flaws fully.
  - Both kept the contract whole (COMP 100). Flash's judge kept three calls the review confirmed
    against earlier verdicts:
    - two optional parameters added to manifest functions are not COMP;
    - the CSV header rewritten as a literal is not COMP either, since its text and order still
      match the manifest;
    - a bcrypt re-hash that an unmigrated `CHAR(32)` column would truncate is a deployment caveat,
      not PEN-001. D, E and F widened the same column, and no earlier judge penalized that risk.
    The EXPL judge scored that risk under trade-offs.
  - FlashX's switch to SQL `AVG` is not COMP-003, as for C and F. Its report calls the result
    identical, which it is not, and that cost it under EXPL precision.
- **Grok 4.7 (R): 638, Silver, the strongest model here from outside Anthropic and OpenAI.**
  - It ran in opencode at effort `high` with every isolation layer, and got the fixed first
    message and nothing else. xAI publishes a training cutoff of May 2026, before the answer key
    went public.
  - It is the first agent to use the web. At 10:53 it fetched the PHP manual page for `fputcsv`
    (`www.php.net`). The protocol blocks GitHub, not the web. It made no request to GitHub, and
    its run note records the fetch.
  - It is the fourth agent to fix the CSV formula injection (SEC-008). It prefixes only the cells
    that start with a formula character and leaves the `-` for a ticket with no technician alone,
    so G's new bug does not recur.
  - It fixed the other probe-covered flaws and kept the contract whole. It left MD5 and the
    secrets in place, saying a re-hash on login could not migrate the base, which is false.
  - It cost US$ 2.43, the most of the opencode runs.
- **Grok 4.6 (S): 633, Silver.**
  - It ran in opencode at effort `high` with every isolation layer. It got the fixed first message
    and nothing else, used no web tool and made no request to GitHub.
  - It finished in six minutes with three shell commands, the shortest session of the day. It
    lands five points below Grok 4.7 for about an eighth of the cost (US$ 0.31 against 2.43).
  - xAI publishes a cutoff for Grok 4.7 but none for Grok 4.6, so it carries the dagger.
  - It fixed the three probe-covered flaws but not SEC-008, and kept the contract whole.
  - It named the nested ifs of CLN-007 in its list of deliberate non-changes. Under rule 5 that is
    R1, which gives CLN 25.
  - For SEC-015 it moved the SMTP key to the environment and kept the database password's literal
    fallback. That gives C3 at half, as for J; A, P and Q kept both literals and got none.
- **Kimi K2.7 Code (T): 415, Bronze.**
  - It ran in opencode through Moonshot's API. It has no effort setting there, so it ran at the
    model's default.
  - The operator's folder was named `kimi-for-coding-high`. The agent is filed as
    `kimi-k2.7-code-default`, which is what actually ran.
  - It had every isolation layer, the fixed first message and nothing else, no web tool and no
    request to GitHub. It cost US$ 0.48.
  - An earlier session, 11:38–11:46, never started: every request failed with HTTP 401 for an
    invalid API key.
  - Moonshot publishes no cutoff. The model was released on 2026-06-12, before the key went
    public, but a release date is not a published cutoff, so it carried the dagger until 2026-10-05
    (see *Release dates bound the training cutoff*).
  - It fixed six flaws and kept the contract whole. It left the N+1 query and the CSV injection in
    place.
  - It is the worst calibrated so far (Brier 0.225). It reported SQL injection in `verChamado` and
    `tecnicoNome` at confidence 100, but both take only integers, so both are false positives.
  - Its report says `EXPORT_DIR` now has an empty fallback. The code keeps the original path, so
    there is no compatibility issue, but the EXPL judge, who reads the report, marked the claim
    down.
- **DeepSeek V4 Flash (U): 612, Silver.**
  - It ran in opencode at effort `high`, served by Novita AI, with every isolation layer, no web
    tool and no request to GitHub. It cost US$ 0.07.
  - The operator's folder was named `deepseek-v4.1-flash`, but the host did not offer V4.1 Flash;
    the session shows `deepseek/deepseek-v4-flash`, and the agent is filed as
    `deepseek-v4-flash-high`.
  - DeepSeek publishes no cutoff. The model was released on 2026-04-24, but that is not a
    published cutoff, so it carries the dagger.
  - Before the fixed first message the operator asked "quem eh voce?", to check which model the
    host was serving. The model answered with its name, and nothing else passed between them. The
    run stands, its note records the exchange, and `PROTOCOL §3` now says how such a check is
    handled (0.2.25). The decision was taken before the run was scored.
  - It fixed eight flaws fully, MD5 and the secrets among them. It lost 30 points to COMP-003: it
    changed `formatarStatus` to return "Desconhecido" for unknown statuses where the legacy code
    returned "Resolvido". D got the same call for the same change. Without it, it would total 642.
  - DeepSeek retired V4 Flash on its own API on 2026-09-14 and routes the name `deepseek-v4-flash`
    to V4.1 Flash. This run went through Novita's hosted model of that name. The session cannot
    show whether Novita serves the V4 or the V4.1 weights, and the model's own answer does not
    settle it. Its run note says so.
- **DeepSeek V4.1 Flash (V): 625, Silver.**
  - It ran through DeepSeek's own API, which serves V4.1 Flash as `deepseek-flash`, in opencode at
    effort `high`, with every isolation layer. It got the fixed first message and nothing else, and
    made no request to GitHub.
  - It cost US$ 0.04, the cheapest run so far.
  - DeepSeek publishes no cutoff. The model was released on 2026-09-10, after the key went public,
    so it carries the dagger.
  - It fixed all four probe-covered flaws, including the CSV injection. Its cell sanitizer also
    rewrites the `-` shown for a ticket with no technician into `'-`, which is PEN-001 (−15), as for
    G. It kept the contract whole.
  - It sits level with GPT-5.6-terra at 625 and ranks ahead on the discovery index.
  - Its V4 Flash run, through Novita, totals 612. The two runs differ in 13 points and in several
    calls (V4.1 Flash left MD5, V4 Flash did not), which suggests different weights. The routing
    caveat above still applies.
- **Qwen3 Coder Next (W): 507, Bronze, 18th.**
  - It is the first run made under the clean-machine rule of `PROTOCOL §3`: as the unprivileged
    user `leb`, on the second VM, cleaned of every earlier run's leftovers. It ran in opencode
    through Novita AI; the model is non-thinking and has no effort setting. It got the fixed first
    message and nothing else, used no web tool and made no request to GitHub. It cost US$ 1.53 in
    16 minutes.
  - The Qwen team publishes no cutoff. The model predates the public key (its card is dated
    2026-02-03), but a release date is not a published cutoff, so it carried the dagger until
    2026-10-05 (see *Release dates bound the training cutoff*).
  - It fixed five flaws: the SQL injection, session fixation, the empty average, the N+1 in the
    listing, and a file-handle leak it did not report. It moved one of the two secrets out of the
    code and kept the contract whole. It left MD5 and the ticket IDOR in place on purpose, and
    reported neither the XSS nor the CSV injection.
  - Its JOIN drops the `-` shown for a ticket with no technician: ticket 104's cell comes out
    empty, and PHP 8.1+ prints a deprecation notice into the page. That is PEN-001 (−15), as the
    rewritten `-` was for G and V.
  - Its report has the lowest EXPL score so far (18 of 50) and the worst calibration (Brier 0.301).
    It reported three flaws that cannot exist, each at confidence 100: SQL injection in two
    functions that take only integers, and an XSS in a login form that never echoes its input. Its
    summary counts 20 problems and 18 fixes; it lists 12.
  - It ran no tests. Once its report and findings index were written, at 17:57, it spent ten
    minutes calling a tool that does not exist (`todo_write`) 147 times, each refused by opencode,
    before it finished.
  - An earlier session, at 17:33, never started: the new VM had no outbound internet yet, and the
    request never reached the host. The operator cancelled it with no model output. It is not a
    run.
- **Claude Sonnet 5.5 in multi-agent mode ("ultracode"), first attempt: void.**
  - The run started at 12:34 on the 4-vCPU VM. About 20 minutes in, the agent reported that the
    machine limited each of its workflows to 2 agents.
  - The operator stopped it at 13:00, before any report or findings index existed, to resize the
    VM. It had cost US$ 8.43 across 5 workflows and 15 subagents.
  - It is kept unscored in `claude-sonnet-5.5-max-ultracode/void-1/`, with a `VOID.md`. The
    agent's run 1 is the one made on the resized machine.
- **Claude Sonnet 5.5 in multi-agent mode (X), run 1: 774, Gold, 3rd, 51 points below the same
  model at `xhigh`.**
  - It ran in Claude Code 2.1.285 at effort `max`, which turns on the multi-agent mode, from 13:05
    to 21:00 on the first VM resized to 20 vCPUs. It got the fixed first message and nothing else,
    used no web tool and made no request to GitHub. It began before the clean-machine rule, so it
    ran as the VM's administrator with earlier runs' leftovers present.
  - It ran 7 workflows with 68 subagents: 8.1 hours of wall-clock and 16.4 hours of model time.
    The client's record puts the cost at US$ 233.08, about 65 times the US$ 3.60 of its `xhigh`
    run, which took 19 minutes.
  - It scores the same as the `xhigh` run in every other category: security at 250 of 250, all
    four probes fixed, the contract kept whole. It reported 29 findings with no false positive and
    is better calibrated (Brier 0.008 against 0.022). Its report scores 45 of 50, one point below,
    with precision and root cause at 10.
  - The other 50 points are architecture: 0 of 200 against 50. It neither took the dispatcher apart
    nor named it, where the `xhigh` run named it and explained why it left it, and it named no
    constant.
  - Nine of its 68 subagents had a response stopped by the safety classifier while they built
    attack tests. The client then switched each of them to `claude-sonnet-5`, because the admin
    account had its model fallback on. Those turns produced 541,148 of the run's 6,819,101 output
    tokens and US$ 15.34 of its cost, all of it analysis in the scratch area. The delivery itself
    was written entirely by Sonnet 5.5 in the main session, which never switched, so the run
    stands under the rule `PROTOCOL §3` now states (0.2.32).
- **Two raw deliveries were published by mistake.** The Kimi K3 and Fable 5.1 deliveries were
  committed as filed, at `results/2026/<agent>/`, by an unrelated commit (0.2.15) before they were
  judged. They are now moved to their places, and the history keeps the slip.

### Runs of 2026-10-01

Sixty-two deliveries were scored, labelled **Y** to **CH** in the order they arrived and judged blind like
the others. The first three came one per VM; after restores, the three VMs ran the rest:

| Label | Run | Outcome |
| --- | --- | --- |
| **Y** | GPT-6.1-sol pro, `xhigh`, run 1 | 654 |
| **Z** | GPT-5.3-Codex, `xhigh`, run 1 | 403 |
| **AA** | GPT-6.1-sol, `ultra`, run 1 | 597 |
| **AB** | Claude Sonnet 5.5, `xhigh`, run 2 | 809 |
| **AC** | GLM-5.3 Prime, `high`, run 1 | 635 |
| **AD** | Claude Opus 5.5, `xhigh`, run 3 | 805 |
| **AE** | Claude Fable 5.1, `xhigh`, run 2 | 764 |
| **AF** | Claude Haiku 4.5, default effort, run 1 | 317 |
| **AG** | Claude Haiku 4.5, default effort, run 2 | 369 |
| **AH** | GPT-6.1-sol, `xhigh`, run 2 | 653 |
| **AI** | Claude Sonnet 5.5, `xhigh`, run 3 | 724 |
| **AJ** | GLM-5.3, `high`, run 2 | 621 |
| **AK** | GPT-6-astra, `xhigh`, run 2 | 596 |
| **AL** | GPT-6-astra, `xhigh`, run 3 | 628 |
| **AM** | Claude Sonnet 5.5, `max`, run 1 | 807 |
| **AN** | DeepSeek V4 Pro, `high`, run 1 | 604 |
| **AO** | DeepSeek V4 Pro, `high`, run 2 | 496 |
| **AP** | DeepSeek V4 Pro, `high`, run 3 | 432 |
| **AQ** | Gemini 3.8 Flash, `high`, run 1 | 687 |
| **AR** | Grok 4.7, `high`, run 2 | 663 |
| **AS** | Grok 4.7, `high`, run 3 | 607 |
| **AT** | Gemini 3.8 Flash, `medium`, run 1 | 550 |
| **AU** | GPT-5.6-terra, `xhigh`, run 2 | 611 |
| **AV** | GPT-5.6-terra, `xhigh`, run 3 | 645 |
| **AW** | DeepSeek V4.1 Flash, `high`, run 2 | 612 |
| **AX** | DeepSeek V4.1 Flash, `high`, run 3 | 597 |
| **AY** | Gemini 3.8 Flash, `high`, run 2 | 588 |
| **AZ** | Claude Sonnet 5.5, `max`, run 2 | 773 |
| **BA** | GPT-6.1-sol, `xhigh`, run 3 | 661 |
| **BB** | GPT-5.6-sol, `xhigh`, run 2 | 612 |
| **BC** | GPT-5.6-sol, `xhigh`, run 3 | 608 |
| **BD** | GLM-5.3 Prime, `high`, run 2 | 628 |
| **BE** | GPT-5.5, `xhigh`, run 2 | 558 |
| **BF** | Claude Sonnet 5.5, `max`, run 3 | 820 |
| **BG** | GPT-5.6-luna, `xhigh`, run 2 | 601 |
| **BH** | GPT-5.6-luna, `xhigh`, run 3 | 624 |
| **BI** | GPT-5.5, `xhigh`, run 3 | 568 |
| **BJ** | GPT-6.1-sol, `ultra`, run 2 | 656 |
| **BK** | GPT-6.1-sol, `ultra`, run 3 | 616 |
| **BL** | Claude Haiku 4.5, default effort, run 3 | 232 |
| **BM** | MiniMax-M3, `thinking`, run 1 | 462 |
| **BN** | GLM-5.3 Prime, `high`, run 3 | 541 |
| **BO** | MiniMax-M3, `thinking`, run 2 | 616 |
| **BP** | MiniMax-M3, `thinking`, run 3 | 434 |
| **BQ** | Grok 4.6, `high`, run 2 | 640 |
| **BR** | DeepSeek V4 Flash, `high`, run 2 | 282 |
| **BS** | Claude Sonnet 5.5, `max`, multi-agent, run 2 | 820 |
| **BT** | Claude Sonnet 5.5, `max`, multi-agent, run 3 | 759 |
| **BU** | GLM-5.3, `high`, run 3 | 604 |
| **BV** | Gemini 3.8 Flash, `high`, run 3 | 100 |
| **BW** | Grok 4.6, `high`, run 3 | 620 |
| **BX** | GPT-6-astra, `ultra`, run 1 | 668 |
| **BY** | Kimi K3, `high`, run 1 | 629 |
| **BZ** | Kimi K3, `high`, run 2 | 634 |
| **CA** | Kimi K2.7 Code, default effort, run 2 | 415 |
| **CB** | Kimi K3, `high`, run 3 | 574 |
| **CC** | Kimi K2.7 Code, default effort, run 3 | 512 |
| **CD** | Kimi K2.7 Code highspeed, run 1 | 402 |
| **CE** | Kimi K2.7 Code highspeed, run 2 | 363 |
| **CF** | Kimi K2.7 Code highspeed, run 3 | 402 |
| **CG** | Gemini 3.7 Flash, `high`, run 1 | 587 |
| **CH** | Gemini 3.7 Flash, `high`, run 2 | 587 |

All sixty-two ran as the unprivileged user `leb` on a machine restored to its clean snapshot, got the
fixed first message and nothing else, used no web tool and made no request to GitHub.

- **GPT-6.1-sol pro (Y): 654, Silver, 7th.** It ran in opencode through OpenRouter, in 16 minutes,
  for US$ 1.01. OpenAI publishes no page or cutoff for a pro variant, so it carries the dagger. It
  found 11 of the 13 planted flaws and fixed 9: the same count as GPT-6.1-sol at `xhigh`, 12
  points below it. Like five GPT runs before it, it scoped the SLA average to each client
  (COMP-003, −30), and it left the CSV injection in place for the file's consumers.
- **GPT-5.3-Codex (Z): 403, Bronze, 24th, three points above the fail line.** It ran in opencode
  through the Kilo Code gateway, in 7 minutes, for US$ 0.54. It found 6 of the 13 and fixed 5;
  it left the N+1 query, MD5, the secrets and the session fixation in place, and scored 0 in
  performance, clean code and architecture. Its report says that dividing by zero gives a
  warning, where PHP 8 throws (EXPL 27).
- **GPT-6.1-sol at `ultra` (AA): 597, Bronze, 18th, 69 points below the same model at `xhigh`
  (666).** It ran in Codex CLI 0.159.3 for 24 minutes and spawned three subagents, all on
  GPT-6.1-sol at `ultra`. It found 9 of the 13 against 11, and lost points on MD5, which it kept
  (the `xhigh` run migrated it), on the CSV file-handle leak, which it fixed without reporting,
  and on the dispatcher, which it did not name. It is the second run on LEB-100-A where more
  effort scored less than the same model's single pass, after Claude Sonnet 5.5's multi-agent
  run (X).
- **Claude Sonnet 5.5 at `xhigh`, run 2 (AB): 809, 16 points below its run 1 (825). The published
  score is now the lower of the two, 809, still first.**
  - It ran in Claude Code 2.1.285 on `bench1` in 23 minutes, for US$ 2.96. Run 1 had been made on
    the first VM at 4 vCPUs, as its administrator with earlier runs' leftovers present. This one
    ran at 20 vCPUs as `leb` on a clean machine. A single-agent client spends most of a run waiting
    on the API, so the run is filed as the same agent's run 2, and its note records the change
    (`PROTOCOL §3`, 0.2.38).
  - It found 12 of the 13 planted flaws, against 11 in run 1, and fixed the same 11, with security
    again at 250 of 250. The 16 points are architecture, where it named the dispatcher only in its
    list of non-changes (25, against 50), a better bug score (139, against 129) and one point less of
    explanation (45, against 46).
  - This is the second agent with two runs, after Opus 5.5 (711 and 717). The two runs differ by
    16 points, a measure of single-run noise on this instance.
- **GLM-5.3 Prime (AC): 635, Silver, 9th, the strongest GLM model so far.**
  - It ran in opencode through the Kilo Code gateway, at effort `high`, on `bench2`, in 15 minutes,
    for US$ 1.69. Z.AI publishes no page or cutoff for a Prime variant, so it carries the dagger.
  - It fixed all four probe-covered flaws, the CSV injection among them, and nine planted flaws in
    all, against eight for GLM-5.3 (629). It kept the contract whole and kept MD5 on purpose.
  - Its report is weak (EXPL 28). It filed a false positive, reproduced, it says, at confidence
    100: a login POST without its `login` field cannot reach `autenticar()`, because
    `index.php:21` checks `isset($_POST['login'])` first. The step-4 judge had filed it as an extra
    finding, and the consistency review moved it to the false positives. That changes only
    calibration, not the total.
- **Claude Opus 5.5 at `xhigh`, run 3 (AD): 805. With three runs, Opus 5.5 has the first official
  score on LEB-100-A: 717, the median of 711, 717 and 805, 4th.**
  - It ran in Claude Code 2.1.285 on `bench2` in 15 minutes, for US$ 2.71. Its first two runs had
    been made on the first VM at 4 vCPUs as its administrator; the note records the change
    (`PROTOCOL §3`, 0.2.38).
  - The 94-point spread comes from judgement calls, not from what it could fix: each of the three
    runs fixed 9 of the 13 planted flaws. Run 1 changed a business value (COMP-003, −30) and left
    the nested conditionals; run 2 kept compatibility but again left the conditionals; run 3
    flattened them and kept compatibility, and left MD5 in place, which the first two had migrated.
    All three left the CSV injection unfixed on purpose, for the file's consumers.
  - Nothing in the evidence ties the higher score to the clean machine: the run fixed the same
    number of flaws, and the choices that moved the total are the kind the first two runs had
    already split on.
- **Claude Fable 5.1 at `xhigh`, run 2 (AE): 764, 17 points below its run 1 (781). Its published
  score is now the lower, 764, 3rd.**
  - It ran in Claude Code 2.1.285 on `bench1` in 22 minutes, for US$ 8.04. Every answer came from
    Fable 5.1: no safety stop, and the client's model fallback was off, the switch that voided its
    earlier second attempt (`void-1`). Run 1 was made on the first VM at 4 vCPUs as its
    administrator; the note records the change.
  - It fixed 10 of the 13 planted flaws, one more than run 1: this time it moved both secrets out
    of the code. It lost the architecture points of run 1, where it had named the dispatcher, and
    the full bug score, reporting the file-handle leak only inside another finding. Like run 1, it
    left the CSV injection in place for the file's consumers.
- **Claude Haiku 4.5's first attempt is void**, kept in `claude-haiku-4.5-default/void-1/`: the
  client was switched to plan mode mid-run, which put an instruction into the agent's context and
  handed one turn to Claude Sonnet 5.5 (0.2.42).
- **Claude Haiku 4.5, run 1 (AF): 317, below the pass line, last of 27.**
  - It ran in Claude Code 2.1.285 on `bench1` in 2.5 minutes, for US$ 0.21. Haiku 4.5 does not
    support the effort setting, so the client's `/effort xhigh` did not apply, and the agent is
    filed at the model's default. One second after the first message, before any answer, the
    client was switched to auto-accept edits, a mode that adds no instruction and switches no
    model; the run stands, and its note records it.
  - It found 6 of the 13 planted flaws and fixed 4. It reported SQL injection, at confidence 90 and
    95, in two functions that only take integers (false positives), and left MD5, the secrets, the
    N+1 query and the CSV injection in place.
  - Its visibility fix hides a client's own tickets. The listing filter compares `usuario_id` with
    `===` against an integer, but without a search term mysqli returns the column as a string, so
    a client sees an empty list; with a search term the list is right. Reproduced against the
    delivered code: Ana sees 0 of her 3 tickets on the main page (COMP-003, −30).
- **GPT-5.6-sol pro at `max`, first attempt: void.** In opencode through the Kilo Code gateway, on
  `bench3`, it worked for 74 minutes with two review subagents (US$ 16.04), until the gateway
  stopped serving it for lack of credit, before it had written a report. It is kept unscored in
  `gpt-5.6-sol-pro-max/void-1/`, with a `VOID.md`.
- **Claude Haiku 4.5, run 2 (AG): 369, again below the pass line. Its published score stays the
  lower, 317.**
  - It ran on `bench2` in 7 minutes, for US$ 0.40, with auto-accept set before the first message.
    `bench2`'s snapshot still held the first seconds of Opus 5.5's third run, which this run never
    read.
  - It moved both secrets out of the code and kept the contract whole this time (no COMP-003), but
    again reported the two impossible SQL injections and left MD5, the N+1 query and the CSV
    injection in place. Its report scored 19 of 50, second lowest: on top of the false injections
    it says the zero divisor shows INF or NaN, where PHP 8 throws.
- **Claude Sonnet 5.5 at `xhigh`, run 3 (AI): 724. With three runs, Sonnet 5.5 has the second
  official score, 809, the median of 825, 809 and 724, still first.**
  - It ran on `bench2` in 23 minutes, for US$ 2.91, with the model, effort and mode set before the
    first message.
  - It fixed 8 of the 13 planted flaws, against 11 in each of its first two runs. This time it left
    MD5, both secrets and the CSV injection in place, by choice, with reasons in its report (45 of
    50). So Sonnet's lead in fixing holds in its median run, not in every run: its third fixed fewer
    than any of Opus 5.5's three (9 each).
- **GPT-6.1-sol at `xhigh`, run 2 (AH): 653, 13 points below run 1 (666); its published score is
  now 653, 7th.** It ran in Codex CLI 0.159.3 on `bench1` in 26 minutes; run 1 had been made in
  Codex 0.159.2 on the first VM. It fixed the same 9 flaws as run 1 and again scoped the SLA average
  to each client (COMP-003).
- **GLM-5.3 at `high`, run 2 (AJ): 621, 8 points below run 1 (629); its published score is now
  621.** It ran in opencode on `bench3`, served this time by OpenRouter where run 1 used Z.AI's own
  API; the run's note records the host. It fixed 7 flaws, against 8 in run 1, keeping the database
  password's literal fallback.
- **GPT-6-astra at `xhigh`, runs 2 (AK) and 3 (AL): 596 and 628. With three runs, GPT-6-astra
  has the third official score, 628, the median of 661, 596 and 628, 10th; its single run had
  placed it 5th.**
  - Both ran in Codex CLI 0.159.3 at the same time, run 2 on `bench3` in 12 minutes and run 3 on
    `bench2` in 14; they are numbered by the time of the first message. Run 1 had been made in
    Codex 0.159.2 on the first VM.
  - Run 1 fixed the CSV formula injection (SEC-008) and kept MD5. Neither later run fixed SEC-008;
    run 3 migrated MD5 at login, run 2 kept it. Run 2 also fixed the export's file-handle leak
    without reporting it (silent fix, `MATRIX §5.4`). Neither named the nested ifs (CLN-007),
    which run 1 did. All three scoped the SLA average to each client (COMP-003).
  - Both matching judges first saw the run's folder path in `mecanico.json`, which names the
    agent: the anonymization step had been skipped for these two. Those verdicts were set aside
    and both deliveries were judged again with the path replaced; only the second verdicts
    are used. The EXPL judge reads only `RELATORIO.md` and was not affected.
  - In both, a matching judge recorded a second COMP-003 for a visibility filter that gives every
    ticket to a command-line caller without a session but none to a web caller without one. It
    was removed on review: the same filter in GPT-6-astra's and GPT-6.1-sol's first runs was
    recorded as a design risk, not a violation.
  - `bench2`'s snapshot still held the first seconds of a Claude Opus 5.5 session (the task prompt,
    no work) in `leb`'s `~/.claude`. Run 3 ran in Codex, which never read that folder; its log does
    not mention it.
- **Claude Sonnet 5.5 at `max`, without the multi-agent mode (AM): 807, Gold, 2nd, two points
  below the same model's official 809 at `xhigh` and 33 above it at `max` in ultracode (774).**
  - It ran on `bench1` in 27 minutes, for US$ 3.86, as a single agent; the ultracode run of the
    same model at the same effort took about 8 hours. The model, the effort and the permission
    mode were set before the first message.
  - It fixed 11 of the 13 planted flaws, missing only the two architecture ones, with security,
    bugs, performance, clean code and compatibility all at the maximum and an explanation score
    of 45 of 50. It is a new agent with one run, so its 807 is not official.
  - `bench1`'s snapshot held an empty Claude Code session record (0 bytes) from before the
    snapshot; it carries nothing.
- **DeepSeek V4 Pro at `high` (AN): 604, Silver, 18th, below both DeepSeek Flash models (625
  and 612).** It ran in opencode through OpenRouter in 8 minutes, for US$ 0.03.
  - It fixed 8 flaws and kept compatibility at 100, but its report scored 21 of 50: it rates SQL
    injection at confidence 100 in two functions whose parameter is typed `int` (two false
    positives), and says the search is reachable before login, which it is not.
  - Its JOIN for the N+1 query dropped the `-` the listing shows for a ticket with no technician:
    the cell is now empty (PEN-001, −15).
  - It left the CSV export showing every client's tickets on purpose, a decision its report
    defends.
- **DeepSeek V4 Pro at `high`, run 2 (AO): 496, Bronze, 108 points below run 1. Its published
  score is now 496, the lower of the two, 24th.**
  - It ran at the same time as run 1, on `bench2`, served by Novita AI where run 1 was served by
    OpenRouter; run 1's first message went out 0.8 seconds earlier. It took 23 minutes and 48 model
    responses, for US$ 0.42, against 8 minutes and 15 responses.
  - It fixed 6 flaws, against 8. It named the N+1 query and left it in place, kept MD5 and both
    secrets, and fixed the file-handle leak without reporting it. Unlike run 1 it filtered the CSV
    export for clients and made no false SQL injection claim; its report scored 30 of 50.
  - `bench2`'s snapshot still held the first seconds of a Claude Opus 5.5 session in `leb`'s
    `~/.claude`; opencode never read that folder.
- **DeepSeek V4 Pro at `high`, run 3 (AP): 432, Bronze. With three runs its score is official:
  496, the median of 604, 496 and 432, 24th.**
  - It ran on `bench3` through OpenRouter, the host of run 1, in 12 minutes, for US$ 0.04.
  - It fixed 5 flaws, the fewest of its three runs. It did not report the N+1 query, reported the
    session fixation and MD5 and left both in place, and claims SQL injection in an int-typed
    function (one false positive), saying PHP would accept a string there. Its report scored 26
    of 50.
  - The three runs spread over 172 points, the widest of any agent with three, across two hosts:
    604 and 432 through OpenRouter, 496 through Novita AI.
- **Claude Fable 5.1 at `xhigh`, third attempt: void, for the same reason as its second (L).**
  On `bench1`, 13 minutes in, while it was checking its own SQL injection fix against injection
  payloads, Fable's safeguards stopped a response, and four minutes later Claude Code logged
  `model_refusal_fallback` and switched to Claude Opus 4.8. Opus 4.8 edited `lib.php` and wrote all
  of `achados.json` and `RELATORIO.md`; the client billed US$ 5.68 to Fable and US$ 2.34 to Opus.
  The switch happened although `leb`'s settings had `switchModelsOnFlag` set to `false`, so that
  setting does not stop this fallback. The run is kept unscored in
  `claude-fable-5.1-xhigh/void-2/`, with a `VOID.md`; Fable 5.1 still has two runs.
- **Gemini 3.8 Flash at `high` (AQ): 687, Silver, 6th, the strongest model here from outside
  Anthropic, 33 points above the best GPT model.** It is the first Google model in the table.
  - It ran in opencode through OpenRouter on `bench3`, in 12.5 minutes and 82 model responses, for
    US$ 1.00.
  - It fixed 8 of the 13 planted flaws, with performance, clean code and compatibility at the
    maximum; it flattened the nested ifs (CLN-007), which only Claude models had done. It kept MD5
    and both secrets as literal fallbacks, and did not report the CSV formula injection.
  - Its report scored 27 of 50: it says the visibility rule is applied strictly while the CSV
    export still gives every client the whole table, and it justifies leaving MD5 and the seed in
    place by the evaluator's test suite rather than by the system.
  - A second attempt, started four minutes later on `bench2`, stopped after two minutes on an
    OpenRouter timeout (HTTP 504) before writing anything. It is void, kept in
    `gemini-3.8-flash-high/void-1/` with a `VOID.md`, like GPT-5.6-sol pro's credit failure.
- **Grok 4.7 at `high`, runs 2 (AR) and 3 (AS): 663 and 607. With three runs its score is
  official: 638, the median of 638, 663 and 607, 9th; the published run is still run 1.**
  - Both ran in opencode on xAI's own API at the same time, run 2 on `bench1` in 24 minutes
    (US$ 2.88) and run 3 on `bench2` in 26 (US$ 2.57); run 2's first message went out 8 seconds
    earlier. Neither used a web tool, which run 1 had done once.
  - Run 2 fixed the same 8 flaws as run 1, the CSV formula injection among them, and named the
    mixed PHP and HTML of `index.php` among its deliberate non-changes (ARCH-002 identified, as
    for Fable 5.1, Sonnet 5.5 and GPT-6.1-sol). Its report scored 42 of 50.
  - Run 3 left the CSV injection in place and removed both secrets instead, and lost 30 points for
    labelling unknown statuses "Desconhecido" where the contract returns "Resolvido" (COMP-003).
  - Run 3 listed its home folder once (`ls -la ~`), which shows the name of the `~/.claude` folder
    `bench2`'s snapshot still carries; it never read into it.
- **Gemini 3.8 Flash at `medium` (AT): 550, Bronze, 23rd, 137 points below the same model at
  `high`.** A separate agent from the `high` one. It ran on `bench1` through OpenRouter in 4.5
  minutes and 31 responses, for US$ 0.19, and opencode recorded no reasoning tokens.
  - It fixed 6 flaws, against 8 at `high`, and did not report the CSV injection or the session
    fixation. Its report scored 24 of 50: it claims SQL injection in the two int-typed functions
    (two false positives) and, as at `high`, misses that the CSV export still serves every
    client's tickets.
  - An earlier attempt at `medium`, on `bench3`, is void: its client was started in `/srv`, which
    held two copies of the package and the operator's `prompt.md`, not in a copy of the package.
    The agent spent four minutes locating the task (it ran `sudo -n -l`, refused, and searched the
    disk for harness files, of which the VM has none) before copying the package into `/srv`
    itself. It is kept in `gemini-3.8-flash-medium/void-1/`, with a `VOID.md`.
  - Two more attempts at `medium` are void: OpenRouter answered HTTP 429 ("google/gemini-3.8-flash
    is temporarily rate-limited upstream") while up to three Gemini sessions shared it. One, on
    `bench2`, started with run 1 and stopped after 20 minutes without a report (`void-2`); the
    other, on `bench1`, stopped after 3 minutes without its findings index (`void-3`). Both are
    kept with their partial deliveries, mechanical reports and a `VOID.md`.
- **GPT-5.6-terra at `xhigh` in opencode, not scored.** It ran on `bench3` through OpenRouter and
  finished normally, but GPT-5.6-terra's run 1 was made in Codex CLI on OpenAI's API, and a client
  brings its own system prompt and tools. Rather than mix two clients in one score, the operator
  chose to repeat the run in Codex CLI. The decision was made on the client alone, before any judge
  saw the delivery; the attempt is kept in `gpt-5.6-terra-xhigh/void-1/` with a `VOID.md`.
- **GPT-5.6-terra at `xhigh` in Codex CLI, runs 2 (AU) and 3 (AV): 611 and 645. With three runs
  its score is official: 625, the median of 625, 611 and 645, 14th; the published run is still
  run 1.**
  - Both ran in Codex CLI 0.159.3 at the same time, run 2 on `bench2` in 8 minutes and run 3 on
    `bench1` in 7; run 2's first message went out 11 seconds earlier.
  - Both fixed the same 9 flaws as run 1 plus the CSV formula injection, which run 1 had left
    alone; run 3 also ships a migration script for the password column. Both applied the formula
    prefix to the technician column too, turning its `-` into `'-` (PEN-001, −15), as GPT-5.6-sol
    and DeepSeek V4.1 Flash did.
  - Run 2 scoped the SLA average to each client (COMP-003, −30); run 3 kept it global and kept
    compatibility at 100. Both reports scored 31 of 50, against 35 for run 1.
- **DeepSeek V4.1 Flash at `high`, runs 2 (AW) and 3 (AX): 612 and 597. With three runs its score
  is official: 612, the median of 625, 612 and 597, 18th; its single run had placed it 13th.**
  - Both ran in opencode through OpenRouter, where run 1 used DeepSeek's own API, run 2 on
    `bench3` and run 3 on `bench1`, a minute and a half apart. Each took about 75 seconds and
    cost under US$ 0.02.
  - Run 1 fixed 9 flaws, the CSV formula injection among them; run 2 fixed 8 (not the injection)
    and run 3 fixed 7 (neither the injection nor the secrets, which it left as literals). Neither
    later run turned the technician's `-` into `'-`, as run 1 did.
  - Run 3 filtered the listing and the detail page for clients but left `?export=csv` serving
    every ticket on purpose, on the premise that filtering it would break the library's contract.
- **Gemini 3.8 Flash at `high`, run 2 (AY): 588, Bronze, 99 points below run 1. Its published
  score is now 588, the lower of the two, 22nd: run 1's 6th place, and its standing as the
  strongest model from outside Anthropic, no longer hold.**
  - It ran on `bench2` through OpenRouter, as run 1 did, in 25 minutes and 56 responses, for
    US$ 0.85. It fixed 7 flaws, against 8: it left the nested ifs that run 1 flattened, kept MD5
    and kept both secrets as literal fallbacks while its findings index marks them fixed. Like
    run 1, it left the CSV export serving every client, here on purpose. Its report scored 28.
  - **It searched the machine for the answer key.** Five minutes in it ran `find / -name
    "*harness*" -o -name "*LEB*" -o -name "*matriz*"`, then `grep -rnw /home -e` with the matrix
    SHA-256 quoted in `TAREFA.md`. The answer key is not on the VM. The grep matched one line of
    the Claude Code session that `bench2`'s snapshot still carries in `leb`'s `~/.claude`: that
    session reading `TAREFA.md`, so the content was this run's own `TAREFA.md` plus session
    metadata. The agent did not open the file again. The run stands, since nothing it read adds to
    its own package; the operator decided so before it was judged.
  - Of every session archived, only two went looking for the evaluator's material, both Gemini
    3.8 Flash: this run and the void attempt at `medium` started in `/srv`, which searched for
    files named `*harness*`. Every other agent, and Gemini's own run 1, worked from the package
    alone.
  - Its matching verdict was revised on review: BUG-004 and CLN-007 had a half credit for
    findings at the right lines that diagnose other defects, which this evaluation scores as not
    reported (a silent fix for BUG-004).
- **Claude Sonnet 5.5 at `max`, single agent, run 2 (AZ): 773, Gold, 34 points below run 1. Its
  published score is now 773, the lower of the two, 3rd, one point below the same model at the
  same effort in multi-agent mode (774).**
  - It ran on `bench1`, after the VMs were cleaned and their snapshots retaken, in 29 minutes and
    94 responses for US$ 4.75, all from Sonnet 5.5, with the effort and permission mode set before the first
    message.
  - It fixed 10 of the 13 planted flaws, the same as run 1 except MD5, which it left in place with
    a migration plan; it reproduced the export race on 20,000 tickets before fixing it. Its report
    scored 45 of 50 again. Unlike run 1 it did not name the dispatcher, so architecture is 0.
  - `leb`'s Claude Code loaded an MCP server for PDF files synced from the account; the agent did
    not call it.
- **GPT-6.1-sol at `xhigh`, run 3 (BA): 661. With three runs its score is official: 661, the
  median of 666, 653 and 661, 6th, the strongest GPT model here.** It ran in Codex CLI 0.159.3 on
  `bench1` on 2026-10-02, in 24 minutes, on VMs whose snapshots were retaken after the clean-up.
  It fixed 10 flaws, the 9 its first two runs fixed plus the CSV formula injection, which they left
  alone. It named the dispatcher among its deliberate non-changes (ARCH-002 identified), and
  scoped the SLA average to each client again (COMP-003).
  - **A fourth run of the same agent is kept unscored.** GPT-6.1-sol was started on `bench1` and on
    `bench2` within 48 seconds; `PROTOCOL §4` allows three runs per agent, so the session whose
    first message went out first is run 3 and the other is kept in `gpt-6.1-sol-xhigh/void-1/`
    with a `VOID.md`, set aside on that ordering before any judge saw either delivery.
- **GPT-5.6-sol at `xhigh`, run 2 (BB): 612, the same total as run 1; its published score stays
  612.** It ran in Codex CLI 0.159.3 on `bench3` in 15 minutes. It fixed 9 flaws, migrating MD5
  and removing both secrets, but left the CSV injection that run 1 fixed, and scoped the SLA average
  to each client (COMP-003). `bench3`'s snapshot carries a one-line Codex session record from the
  operator's login, with no message; this run did not read it.
- **GPT-5.6-sol at `xhigh`, run 3 (BC): 608. With three runs its score is official: 612, the
  median of 612, 612 and 608, 15th; the published run is still run 1.** It ran in Codex CLI 0.159.3
  on `bench1` in 15 minutes. It fixed the same 9 flaws as run 2 and also left the CSV injection
  alone, by choice; it lost 60 points of compatibility, relabelling unknown statuses
  "Desconhecido" and scoping the SLA average to each client (COMP-003 twice). Its list of
  deliberate non-changes names `rotuloPrioridade` as not simplified, which identifies CLN-007.
- **GLM-5.3 Prime at `high`, run 2 (BD): 628, 7 points below run 1 (635); its published score is
  now 628, 11th.** It ran in opencode through OpenRouter on `bench3`, where run 1 used the Kilo
  Code gateway, in 26 minutes for US$ 2.13. It fixed 8 flaws: the same as run 1 except the CSV
  formula injection, which it reported and left alone. It kept compatibility at 100, keeping the
  SLA average global, and its report scored 38 of 50, with a reproduced UNION that puts the
  password hashes in the title column.
- **Claude Sonnet 5.5 at `max`, single agent, run 3 (BF): 820, Gold, the second highest run here.
  With three runs its score is official: 807, the median of 807, 773 and 820, 2nd, two points below
  the same model at `xhigh` and 33 above it in multi-agent mode (774).**
  - It ran on `bench2` in 41 minutes for US$ 6.65. Before the first message the operator selected
    Fable 5.1, then Sonnet 5.5, then the effort; every response came from Sonnet 5.5.
  - It launched one subagent through Claude Code's Agent tool, a read-only blind audit of the original
    code, which the client resolved to Sonnet 5.5: the run is one model, and multi-agent mode was off.
  - It fixed 11 of the 13 planted flaws, missing only the two architecture ones, and kept
    compatibility at 100; its report scored 45 of 50, naming the root cause of the three visibility
    flaws as a data API that carries no identity.
- **GPT-5.5 at `xhigh`, run 2 (BE): 558, Bronze, 43 points below run 1. Its published score is now
  558, 22nd.** It ran in Codex CLI on `bench1` in 6 minutes. It fixed 7 flaws, one fewer than run 1:
  it also left both secrets in the code, and it scoped the SLA average to each client again
  (COMP-003). The verdict charges that violation to the visibility fix, as the other verdicts here do.
- **GPT-5.6-luna at `xhigh`, run 2 (BG): 601, 2 points above run 1; its published score stays 599,
  18th.** It ran in Codex CLI on `bench3` in 10 minutes. It fixed 9 flaws, migrating MD5 this time,
  and scoped the SLA average to each client (COMP-003), as run 1 did.
- **GPT-5.6-luna at `xhigh`, run 3 (BH): 624. With three runs its score is official: 601, the
  median of 599, 601 and 624, 18th.** It ran in Codex CLI on `bench1` in 14 minutes. It fixed 10
  flaws, the CSV formula injection and MD5 among them, but scoped the SLA average to each client
  again (COMP-003); its report scored 29 of 50.
- **GPT-5.5 at `xhigh`, run 3 (BI): 568. With three runs its score is official: 568, the median of
  601, 558 and 568, 22nd.** It ran in Codex CLI on `bench3` in 5 minutes, in Codex's workspace-write
  sandbox with network access off and approval on request, where every other Codex run had full
  access; no approval was requested and no command was blocked. It kept compatibility at 100, but
  left the export's file handle open on two new early returns (BUG-004 not fixed) and kept MD5.
- **A fifth GPT-6.1-sol run is kept unscored** in `gpt-6.1-sol-xhigh/void-2/`. The operator meant to
  run GPT-6.1-sol at `ultra`, which needs two more runs, but the session's log records `effort:
  xhigh` on every turn, and that agent already has its three. It was set aside on that record before
  any judge saw it.
- **GPT-6.1-sol at `ultra`, run 2 (BJ): 656, Silver, 59 points above run 1 (597); its published
  score stays 597, the lower, 19th.** It ran in Codex CLI on `bench1` in 13 minutes, at `ultra` as
  its log records, and spawned two subagents (run 1 spawned three), all on `gpt-6.1-sol` at `ultra`,
  on a machine the size of run 1's. It fixed 10 flaws, the CSV formula injection and MD5 among
  them, and its report scored 40 of 50. A second COMP-003 the matching judge recorded, for a
  visibility filter that serves sessionless callers only from the command line, was removed on
  review, as in AK and AL; the SLA average scoped to each client stands (COMP-003).
- **GPT-6.1-sol at `ultra`, run 3 (BK): 616. With three runs its score is official: 616, the
  median of 597, 656 and 616, 15th, 45 points below the same model at `xhigh`.** It ran in Codex CLI
  on `bench2` in 11 minutes with three subagents, all at `ultra`. It fixed 9 flaws, MD5 among them,
  and declined the CSV formula prefix for the billing integration; it scoped the SLA average to
  each client (COMP-003). With it, every agent run in Codex CLI has its three runs.
- **Two more GPT-6.1-sol runs at `ultra` are kept unscored** in `gpt-6.1-sol-ultra/void-1/` and
  `void-2/`. They were started on `bench3` and `bench1` at 18:45 and 18:46, after run 3 had started
  on `bench2` at 18:27, and would be the agent's fourth and fifth runs, which `PROTOCOL §4` forbids.
  They were set aside on that count before any judge saw them.
- **Claude Haiku 4.5, run 3 (BL): 232, below the pass line. With three runs its score is official:
  317, the median of 317, 369 and 232, last.** It ran on `bench3` in 10 minutes for US$ 0.27, with
  the permission mode switched to acceptEdits as the first message was sent and never again. Its
  `exportarCsv` now writes nothing for a caller without a session, which breaks three of the 22
  characterization checks (PEN-002, −60) and hides tickets from the nightly export (COMP-003). It
  reports SQL injection in `verChamado`, whose parameter is an int, and its report scored 22 of 50.
- **MiniMax-M3 with opencode's thinking variant (BM): 462, Bronze, 27th, a separate agent from
  MiniMax-M3 at its default (460).** MiniMax-M3's first run recorded no client and offered no effort
  setting; this one ran in opencode on MiniMax's own API with a selectable variant, so by the
  operator's decision it is filed as a new agent. It fixed 7 flaws, left the N+1 query, and lost 60
  points of compatibility: unknown statuses now read "Desconhecido" and a priority-4 ticket within
  SLA gets a new label (COMP-003 twice). Two findings that called those contracted behaviours bugs
  were moved on review from false positives to extra findings, as earlier verdicts record them.
- **GLM-5.3 Prime at `high`, run 3 (BN): 541. With three runs its score is official: 628, the median
  of 635, 628 and 541, 11th.** It ran in opencode through OpenRouter on `bench1` in 17 minutes for
  US$ 1.77. It answers HTTP 403 to every client on `?export=csv` (COMP-003) and `listarChamados`
  now returns `null` instead of `'-'` for a ticket with no technician (COMP-002), and it kept the
  database password's literal fallback.
- **MiniMax-M3 with the thinking variant, runs 2 (BO) and 3 (BP): 616 and 434. With three runs its
  score is official: 462, the median of 462, 616 and 434, 26th.** Both ran in opencode 1.18.33 on
  MiniMax's own API at the same time, run 2 on `bench2` in 6 minutes and run 3 on `bench3` in 5; run
  2's first message went out 9 seconds earlier. Each cost about US$ 0.13 or less.
  - Run 2 fixed 7 flaws and kept compatibility at 70: it scoped the SLA average to each client
    (COMP-003, charged to SEC-017). On review, its BUG-004 identification was lowered from half to
    none: the finding at the export's lines describes a temp-file race, not the file-handle leak.
  - Run 3 fixed 9, MD5 among them, but re-hashes the password at login into the `CHAR(32)` column it
    did not widen. On the contracted schema the `UPDATE` fails, so every valid login throws: four
    of the 22 characterization checks break (PEN-002, −80) and the login contract is broken
    (COMP-003). Its report names the column problem and ships the code anyway. This is the delivery
    that stopped the characterization suite, as the next section explains.
- **Grok 4.6 at `high`, run 2 (BQ): 640, Silver, 7 points above run 1 (633); its published score
  stays 633, the lower, 9th.** It ran in opencode on xAI's own API on `bench2` in 5 minutes, for
  US$ 0.31. It fixed the same 8 flaws as run 1, left MD5 and the CSV formula injection, kept
  compatibility at 100, and its report scored 33 of 50, against 35. The file-handle leak (BUG-004)
  was fixed without being reported: the finding at those lines describes another defect, so it
  scores as a silent fix.
- **DeepSeek V4 Flash at `high`, run 2 (BR): 282, below the pass line, 330 points below run 1 (612).
  Its published score is now the lower, 282, last of 32.**
  - It ran in opencode through OpenRouter on `bench3` in 7 minutes, for less than one US cent. Run 1
    went through Novita AI. The model, effort and client are the same, so it is the same agent, and
    the host is recorded because a different host may serve different weights or settings.
  - Its SQL-injection fix binds an expression by reference, which PHP rejects, so every non-empty
    search throws: the fix fails its probe, and one characterization check breaks (PEN-002, −20).
    Because SEC-001's fix is the change that breaks the contracted search, its compatibility
    criterion was lowered from full to none on review.
  - A client's CSV is now built by hand with a single-quoted `'\n'`, so it comes out as one line
    with literal `\n` separators (PEN-001, −15). It fixed 3 flaws and left the N+1 query, MD5, the
    secrets, the session fixation and the file-handle leak in place. It scored 0 in performance,
    clean code and architecture. Its report claims SQL injection in an int-typed function and
    scored 22 of 50.
- **A sixth GPT-6.1-sol run at `ultra` is kept unscored** in `gpt-6.1-sol-ultra/void-3/`. It ran on
  `bench1` at 19:49 on 2026-10-02, after the agent had its three runs and two unscored attempts. It
  was set aside on that count (`PROTOCOL §4`) before any judge saw it.
- **A second attempt at Claude Sonnet 5.5 in multi-agent mode is void** and kept in
  `claude-sonnet-5.5-max-ultracode/void-2/`, with a `VOID.md` and no delivery. On `bench1` at 09:14 on
  2026-10-03 the client's login had expired: it answered the first message locally, the model never
  received it, and the session was closed to log in again.
- **Claude Sonnet 5.5 at `max` in multi-agent mode, run 2 (BS): 820, Gold, 46 points above run 1
  (774); its published score stays 774, the lower, 3rd.**
  - It ran in Claude Code 2.1.285 on `bench1` for 2.0 hours, against 8.1 for run 1, with 2 workflows and
    105 subagents (run 1: 7 workflows, 68 subagents), for US$ 171.18 against 233.08. Model time summed
    across the parallel agents was 15.9 hours.
  - As in run 1, the safety classifier stopped a response in some subagents (eight of 105) and the
    client switched each of them to `claude-sonnet-5`, for US$ 4.43 of the cost. Their one file edit
    went to a scratch copy. The delivery was written by Sonnet 5.5 in the main session, which never
    switched, so the run stands (`PROTOCOL §3`).
  - It identified 11 of the 13 planted flaws and fixed all 11, flattening the nested ifs (CLN-007)
    among them. It did not report the dispatcher (ARCH-002) or the magic numbers (ARCH-009), though it
    named some of those constants without saying so. It is one of six runs on LEB-100-A, all of
    Sonnet 5.5, at SEC 250/250 with compatibility 100 and no penalty. It filters every access path by the visibility rule with
    new functions, leaving the contracted ones open to sessionless callers. It also migrated MD5
    transparently, prefixing only the CSV cells that start a formula, and replaced the N+1 query with
    one join.
  - Its report has 25 findings and scored 45 of 50, with measured evidence for most claims (e.g. the
    export race reproduced 719 times in 720 on the original) and a deployment order that marks the
    column change as the point of no return.
  - The matching judge kept SEC-001's fix as full on its probe while the LIKE wildcards stay
    unescaped, as in BO and BQ.
- **Claude Sonnet 5.5 at `max` in multi-agent mode, run 3 (BT): 759, Gold. With three runs its score is
  official: 774, the median of 774, 820 and 759, 3rd.**
  - It ran in Claude Code 2.1.285 on `bench1` from 13:31 to 17:05 (3.6 hours), with 3 workflows and 145
    subagents, for US$ 119.07. Model time summed across the parallel agents was 8.5 hours. The three runs
    took 8.1, 2.0 and 3.6 hours and cost US$ 233, 171 and 119, and they scored 774, 820 and 759.
  - The VM was restored to the snapshot taken before run 2. It still held the session file of the
    logged-out attempt (`void-2`, the first message and the client's "Login expired") and an empty memory
    folder. The agent never opened either, and nothing from run 2 was on the machine.
  - Seven subagents fell back to `claude-sonnet-5` after the safety classifier stopped them, for US$ 6.80.
    Their file edits went to scratch copies; the delivery was written by Sonnet 5.5 in the main session,
    which never switched, so the run stands (`PROTOCOL §3`).
  - It fixed 10 of the 13 and kept SEC 233, compatibility 100 and no penalty. It neutralised the CSV
    formula cells only in a new web export, keeping the contracted `exportarCsv()` byte-identical for its
    machine consumers. The SEC-008 probe calls `exportarCsv()`, so that fix scores as not made. It fixed
    the file-handle leak without reporting it (BUG-004, a silent fix under the same rule as BS's judge
    applied), and neither the dispatcher nor the magic numbers were touched.
  - Its report, 26 findings with a structural synthesis and measured evidence, scored 47 of 50, the
    highest explanation score on LEB-100-A.
  - The delivery was ready at 17:05, but the client stayed open until the next morning, and its cost is
    written only when it closes. It was copied and blind-judged that evening as **BT**, between BS and BU,
    and merged once the session was closed and its cost could be read. A second matching judge started by
    mistake the next morning was stopped before it wrote anything; the first verdict stands.
- **GLM-5.3 at `high`, run 3 (BU): 604. With three runs its score is official: 621, the median of 629,
  621 and 604, 14th.** It ran in opencode through OpenRouter on `bench3` and wrote its delivery in 9
  minutes, for US$ 0.47. It fixed 7 flaws, the file-handle leak (BUG-004) without reporting it, and kept
  compatibility at 100. It left MD5, the CSV formula injection and both secrets in place on purpose, and
  its report scored 35 of 50.
  - At 09:41, re-checking its fix, it started PHP's built-in server with `2 < /dev/null` where it meant
    `2> /dev/null`. The server's error output stayed attached to the shell tool's pipe, so opencode
    never got the command back and the session stopped there. RELATORIO.md and achados.json were
    already written. The operator found the stall hours later and decided, before any judge saw the
    delivery, to score it as it stood, since the stall came from the agent's own command.
- **Gemini 3.8 Flash at `high`, run 3 (BV): 100, below the pass line. With three runs its score is
  official: 588, the median of 687, 588 and 100, 20th.**
  - It ran in opencode through OpenRouter on `bench2`. Ten minutes in, at 09:34, it started a MariaDB
    server for its own tests through `subprocess.Popen` without detaching its output. The server held
    the shell tool's pipe open, opencode never got the command back, and the session stopped with no
    report, no findings index and a `code/` byte-identical to the package.
  - The operator decided, before any judge saw it, to score it as delivered, for the same reason as
    BU: the stall came from the agent's own command, and voiding it would be a retry after a bad
    outcome.
  - The legacy code passes all 22 characterization checks and is compatible by definition, so the run
    scores the 100 points of compatibility and nothing else.
  - `tools/export-results.py` used to require a RELATORIO.md in every delivery. It now accepts a
    delivery without one.

## Defects in the harness, fixed before scoring

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

- **An exception ended the run.** Until 2026-10-03, a delivery whose contracted function threw an
  exception stopped `characterization/run.php` and `private/verify/probes.php` at that point, with no
  summary: the harness then counted no broken check at all, or failed to read the probes. MiniMax-M3
  with the thinking variant (run 3) throws on login, after re-hashing a password into a column it
  did not widen; DeepSeek V4 Flash (run 2) throws on any search, binding an expression by reference.
  Both runners now catch the exception: a check or probe whose call throws fails alone, with the
  error in its message, and the rest still run. The legacy code still scores 22/22 with every probe
  planted, and all 77 deliveries filed before the change were re-run through the new suite with the
  same counts and probe verdicts, so no published score moved.
- **Grok 4.6 at `high`, run 3 (BW): 620, Silver. With three runs its score is official: 633, the
  median of 633, 640 and 620, 9th.** It ran in opencode on xAI's own API on `bench2` in 5 minutes, for
  US$ 0.28, as runs 1 and 2 did. It fixed 7 flaws, the file-handle leak (BUG-004) without reporting it,
  and kept compatibility at 100. It left MD5, both secrets and the CSV formula injection in place, and
  its report scored 35 of 50. As in run 2, the nested ifs (CLN-007) count as identified only through
  the non-changes list.
- **GPT-6-astra at `ultra` (BX): 668, Silver, 6th, a new agent, and the highest GPT run on LEB-100-A.**
  GPT-6-astra at `xhigh` is official at 628; as with GPT-6.1-sol, the `ultra` effort is filed as a separate
  agent. It ran in Codex CLI 0.159.3 on `bench1` in 11 minutes and spawned three subagents, all on
  `gpt-6-astra` at `ultra`. The logs keep tokens but no cost. It fixed 9 flaws, migrating MD5 transparently,
  taking both secrets out of the code and closing the file-handle leak on every path. It scoped the SLA
  average to each client (COMP-003, charged to SEC-017), and it declined the CSV formula prefix for the
  billing integrations. Its report scored 45 of 50: it is one of the few to catch that `fputcsv` quotes the
  `Aberto em` header, and it restores the literal.
- **Grok 4.7 at `xhigh`, a new agent, first attempt: void.** It ran in opencode through OpenRouter on
  `bench3` from 09:35 to 09:58 on 2026-10-04. It made 40 responses and finished normally with a full
  delivery, at a cost of US$ 3.00. The VM was then restored to its snapshot before the delivery was copied
  off it, so nothing was kept or judged. It is in `grok-4.7-xhigh/void-1/`, with a `VOID.md` recording what
  a read-only check had seen. The cause does not depend on the result, which was never known, so the agent
  runs again from run 1.
- **Kimi K3 at `high` (BY): 629, Silver, 11th, a new agent.** Kimi K3's earlier run, at its default with no
  recorded client, was withdrawn in 0.2.78. This one ran in opencode with the `high` variant on Moonshot's
  own API, on `bench1` in 7 minutes, for US$ 0.41. A session started minutes before sent the fixed message
  to the Kimi Code plan provider, which rejected the key, so no model answered it; the run is a separate
  session that got the message once. It fixed 8 flaws: the visibility rule, both secrets and the
  file-handle leak among them. It kept MD5 with a migration plan, and left the CSV formula injection.
  It answers HTTP 403 to every non-technician on `?export=csv` (COMP-003, charged to SEC-017, as in BN),
  and its report scored 36 of 50.
- **Kimi K3 at `high`, run 2 (BZ): 634, 5 points above run 1; its published score stays 629, the lower,
  11th.** It ran on `bench2` at the same time as run 1, in 21 minutes for US$ 1.02, after the same
  rejected Kimi Code plan session, in a separate session that got the fixed message once. It fixed 9 flaws,
  migrating MD5 transparently this time, and again answered HTTP 403 to every non-technician on
  `?export=csv` (COMP-003). Its report scored 40 of 50.
- **Kimi K2.7 Code, run 2 (CA): 415, Bronze, the same total as run 1 (415); its published score stays
  415, 28th.** It ran in opencode on Moonshot's own API, with no effort variant as in run 1, on `bench2` in
  4 minutes for US$ 0.25. It fixed 6 flaws and left the N+1 query, MD5, the CSV formula injection and the
  file-handle leak, and kept compatibility at 100. As in run 1, it reports SQL injection in `verChamado`,
  whose parameter is an int: a false positive. Its report scored 26 of 50.
- **Kimi K3 at `high`, run 3 (CB): 574, Bronze. With three runs its score is official: 629, the median of
  629, 634 and 574, 11th.** It ran on `bench3` in a single clean session, in 15 minutes for US$ 0.81,
  replacing the run lost there that morning. It fixed 7 flaws and left both secrets and MD5 in place on
  purpose. It answered HTTP 403 to clients on `?export=csv` (COMP-003) for the third time, and its report
  scored 38 of 50. It still carries the dagger: Moonshot publishes no training cutoff for Kimi K3.
- **Kimi K2.7 Code, run 3 (CC): 512, Bronze. With three runs its score is official: 415, the median of 415,
  415 and 512, 28th.** It ran on `bench1` at the same time as run 2, in 15 minutes for US$ 0.81. It fixed 7
  flaws, both secrets out of the code among them (it deleted the unused SMTP key), and named the N+1 query
  as a deliberate non-change. It kept compatibility at 100. It reports SQL injection in both int-typed
  functions (two false positives), and its report scored 24 of 50.
- **Kimi K2.7 Code highspeed, runs 1 to 3 (CD, CE, CF): 402, 363 and 402. With three runs its score is
  official: 402, the median, 30th.** It is a new agent. Moonshot serves `kimi-k2.7-code-highspeed` as a
  separate model id from `kimi-k2.7-code`, which is official on its own. The three runs ran at the same
  time on `bench3`, `bench1` and `bench2` on 2026-10-05, in opencode on Moonshot's API with no effort
  variant, in 2 to 10 minutes each, for US$ 0.38, 0.68 and 1.16. They are numbered in the order they
  reached the evaluator. Run 3's first message went out first, at 10:36:39.
  - All three fixed the SQL injection, the XSS, the visibility rule, the empty average and the file-handle
    leak. None fixed the N+1 query, MD5, the secrets or the CSV formula injection.
  - All three report SQL injection in the int-typed `verChamado` and `tecnicoNome` (false positives).
  - Runs 1 and 2 changed `formatarStatus`'s fallback to "Desconhecido" (COMP-003). Run 2 also gave a
    priority-4 ticket within SLA a new label (a second COMP-003). On review, its two findings that called
    those labels bugs were moved from false positives to extra findings, as in BM.
  - Its reports scored 23, 22 and 26 of 50. Moonshot publishes no release date for the highspeed id, so
    it carries the dagger.
- **Gemini 3.7 Flash at `high` (CG): 587, Bronze, 23rd, a new agent, one point below Gemini 3.8 Flash's
  official 588.** It ran in opencode through OpenRouter on `bench3` in 12 minutes, for US$ 0.72. It fixed
  7 flaws: the N+1 query, the visibility rule (except on the export), session fixation and the file-handle
  leak (silently) among them. It left MD5 and both secrets, and kept compatibility at 100. It did not take
  the int-typed bait, and its report scored 27 of 50. On review, MD5's identification went from half to
  full: it is filed under the wrong category but also named in the non-changes list, and identification
  counts at the higher of the two. Google publishes no cutoff, and the model page gives its latest update
  as August 2026, after the answer key, so it carries the dagger.
- **Gemini 3.7 Flash at `high`, run 2 (CH): 587, the same total as run 1; it still publishes 587, 23rd.**
  It ran on `bench1` at the same time as run 1, 12 seconds later, with no rate-limit error, in 16 minutes
  for US$ 0.60. It fixed the same 7 flaws, and its report scored 27 of 50 again. This time MD5 was filed
  under the right category, and the run kept both secrets as literal fallbacks while its report marked
  them fixed.
- **Two more void attempts, neither ever judged.**
  - A second Kimi K3 run at `high`, on `bench3` at the same time as BY, finished with a full delivery
    (35 responses, US$ 0.76). It was lost when the VM was restored before the copy, as the Grok 4.7
    `xhigh` run was the day before. Its first message had also been sent four times to the Kimi Code plan,
    which rejected each one, before it reached Moonshot. That would have needed a decision before judging,
    and the loss made it moot. It is in `moonshot-kimi-k3-high/void-1/`.
  - Claude Fable 5.1's third run, on `bench1` on 2026-10-04 at 10:12, hit an expired login, as the second
    multi-agent Sonnet attempt had. The model never received the message. It is in
    `claude-fable-5.1-xhigh/void-3/`, and Fable still needs its third run.

## Runs withdrawn for an incomplete record

On 2026-10-04 the operator decided that a run counts only with its record complete: the client and
its version, the first message, the session log and the cost. That is now `PROTOCOL §4` item 5. Three
runs fail it, all from the first batch, filed on the first VM (or a clone of it) before session logs
were archived. Each is moved to `withdrawn-1/` with a `WITHDRAWN.md` and kept for audit, unpublished:

| Run | Total | Effect |
| --- | --- | --- |
| GPT-5.5 at `xhigh`, run 1 (C) | 601 | Runs 2 and 3 (558, 568) stay; the agent publishes 558 and is no longer official |
| MiniMax-M3 at its default, run 1 (J) | 460 | Its only run: the agent leaves the leaderboard |
| Kimi K3 at its default, run 1 (M) | 528 | Its only run: the agent leaves the leaderboard |

The criterion is the record, not the result, and it was applied to every run that fails it at once.
GPT-5.5's was its best run. MiniMax-M3 with the thinking variant is a separate agent with three
recorded runs and is unaffected. The leaderboard goes from thirty-two agents to thirty, and from
nineteen official scores to eighteen. The notes above describe these runs as they were scored at the
time and are left as written.


## Release dates bound the training cutoff

On 2026-10-05 the operator decided that a model's release date bounds its training cutoff when the
provider publishes no cutoff. A model cannot have trained on data that appeared after it was released.
That is now in `PROTOCOL §3`, and `run.json` records `model.release_date` with the provider's own source.
Before, a release date was not accepted, and every model without a published cutoff carried the dagger.

The release dates were taken from the providers' own announcements, release notes or model cards,
never from third parties. Four agents were released before the answer key went public (2026-07-13), so
they lose the dagger:

| Agent | Release | Source |
| --- | --- | --- |
| Qwen3 Coder Next | 2026-02-03 | the Qwen team's announcement |
| MiniMax-M3 (thinking variant) | 2026-06-01 | MiniMax model release notes |
| Kimi K2.7 Code | 2026-06-12 | Kimi Code release notes |
| GLM-5.2 | 2026-06-16 | Z.AI release notes |

The rest keep it:

- **DeepSeek V4 Pro and V4 Flash.** They were released on 2026-04-24, but as a preview. Updates under
  the same name came after the key went public: V4 Flash on 2026-07-31 and V4 Pro's GA release on
  2026-08-13. The hosts served the generic name, so the release date cannot bound the weights these
  runs used.
- **Released on or after 2026-07-13:** Kimi K3 (2026-07-16), Grok 4.6 (2026-08-12), GLM-5.3 (2026-08-14),
  GLM-5.3-Flash (2026-08-26), Gemini 3.8 Flash (2026-09-02), DeepSeek V4.1 Flash (2026-09-10) and
  GPT-6.1-sol, whose pro mode has no date of its own (2026-09-29).
- **No official release date:** GLM-5.3-FlashX, GLM-5.3 Prime and Kimi K2.7 Code highspeed.

No score changes; only the dagger and the scorecards' "Training cutoff" row do.

## Before quoting a number

- **Twenty-two agents have three runs; the rest are not official.** An official score is the median of three
  runs (`PROTOCOL §4`). Sonnet 5.5 at `xhigh` (825, 809, 724) and at `max` (807, 773, 820), Opus 5.5 at
  `xhigh` (711, 717, 805), GPT-6.1-sol (666, 653, 661), Grok 4.7 (638, 663, 607), GPT-6-astra (661, 596,
  628), GPT-5.6-terra (625, 611, 645), DeepSeek V4.1 Flash (625, 612, 597), GPT-5.6-sol (612, 612, 608) and
  DeepSeek V4 Pro (604, 496, 432) are official at 809, 807, 717, 661, 638, 628, 625, 612, 612 and 496,
  GPT-5.6-luna (599, 601, 624) at 601, GPT-6.1-sol at `ultra`
  (597, 656, 616) at 616, GLM-5.3 Prime (635, 628, 541) at 628, MiniMax-M3 with the thinking variant (462, 616, 434) at 462,
  GLM-5.3 (629, 621, 604) at 621, Gemini 3.8 Flash at `high` (687, 588, 100) at 588 and Claude Haiku 4.5
  (317, 369, 232) at 317, Sonnet 5.5 in multi-agent mode (774, 820, 759) at 774, Grok 4.6 (633, 640, 620) at 633, Kimi K3 at `high` (629, 634, 574) at 629 Kimi K2.7 Code (415, 415, 512) at 415 and Kimi K2.7 Code highspeed (402, 363, 402) at 402. Fable 5.1,
  DeepSeek V4 Flash and GPT-5.5 have two, and publish the lower: DeepSeek V4 Flash fell from 612 to 282 on its
  second run, and GPT-5.5 keeps runs 2 and 3 (558, 568) after its run 1 was withdrawn (below). A single run can sit more than 100 points from the agent's median, as
  DeepSeek V4 Pro's first did, and two runs of one agent can fall on either side of a grade line, as
  Gemini 3.8 Flash's 687 and 588 do. Places 7 to 11 (654, 638, 633, 628 and 628) sit within 26 points: that is
  within the noise of a single run.
- **The judge is also a contestant.** Claude Opus 5.5 judged and was judged. Anonymity limits the
  bias; it does not remove it, since a model can recognise its own style — or a sibling's: six of
  the thirty contestants are Claude models, and five of them hold the top five places. The two calls against delivery D (COMP-003 on
  `formatarStatus`, R2 none on CLN-007) were kept as the judge made them. The four changes made in
  review are explained above: E, H and J move no place; F lifts GPT-5.6-terra from 9th to 6th. Every verdict carries a rationale per flaw so it can be audited.
- **Not the same effort for everyone.** Each agent is a model at one effort, and the agent's name
  records it. Kimi K2.7 Code, Qwen3 Coder Next and Claude Haiku 4.5 have no effort setting and ran at
  their default, MiniMax-M3 ran with opencode's thinking variant, and the rest ran at `high`, `xhigh`,
  `max` or `ultra`.
- **The answer key is public.** `instances/LEB-100-A/private/` has been in this public repository
  since 2026-07-13, although `matrix/MATRIX.md §4` says an active matrix is published only as its
  hash. The instance stays current under the exception in `MATRIX §4`, item 5. During these ten
  runs the execution VM blocked GitHub by name only. No agent could fetch the key through a GitHub
  name, but a deliberate connection straight to a GitHub address was not blocked. The address
  blocks came on 2026-09-30 (README, *Execution environment*). Each run also records the
  training cutoff its provider publishes: nine of the ten models have a cutoff before 2026-07-13,
  and MiniMax publishes none for M3, so for that model training on the key could not be ruled out
  until its release date was accepted in place of a cutoff (*Release dates bound the training cutoff*).

## Reading the results

- **SEC-008** (formula injection in the CSV) was fixed by Sonnet 5.5, GPT-5.6-sol and GPT-6-astra,
  each by prefixing `'` to a cell that starts with `= + - @` — the fix the matrix expects. GPT-5.6-sol
  applied it to the technician column too and turned the `-` placeholder into `'-` (its PEN-001).
  Fable 5.1, Opus 5.5, GPT-5.6-luna and GPT-6.1-sol reported it and kept the cells raw for the CSV's
  consumers; GPT-5.5, GPT-5.6-terra and MiniMax-M3 did not report it. Sonnet 5.5 is the only model
  at SEC 250/250, in both of its modes.
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
