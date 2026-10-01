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
    public, but a release date is not a published cutoff, so it carries the dagger.
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
    2026-02-03), but a release date is not a published cutoff, so it carries the dagger.
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

Twenty-one deliveries were scored, labelled **Y** to **AS** in the order they arrived and judged blind like
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

All twenty-one ran as the unprivileged user `leb` on a machine prepared clean that morning, got the
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

- **One run each, except Opus 5.5 and Sonnet 5.5 at `xhigh`, GPT-6-astra, DeepSeek V4 Pro and Grok 4.7.** An official
  score is the median of three runs (`PROTOCOL §4`). Opus 5.5 and Sonnet 5.5 at `xhigh`, GPT-6-astra, DeepSeek V4 Pro
  and Grok 4.7 have three (711, 717, 805; 825, 809, 724; 661, 596, 628; 604, 496, 432; 638, 663, 607), so their 717,
  809, 628, 496 and 638 are official; Fable 5.1, Haiku 4.5, GPT-6.1-sol and GLM-5.3 have two. A single run can sit 90 points from the agent's median, as Opus's
  third did, and GPT-6-astra's first run sat 33 points above its median. Places 7 to 11 (654, 653,
  638, 635 and 633) sit within 21 points, and GPT-5.5 (601, Silver) and GPT-5.6-luna (599,
  Bronze) are two points apart across a grade line: that is within the noise of a single run.
- **The judge is also a contestant.** Claude Opus 5.5 judged and was judged. Anonymity limits the
  bias; it does not remove it, since a model can recognise its own style — or a sibling's: six of
  the thirty contestants are Claude models, and five of them hold the top five places. The two calls against delivery D (COMP-003 on
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
