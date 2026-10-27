# Model safeguards: Claude Fable 5.1 stops some of the benchmark's tasks

Claude Fable 5.1 (`claude-fable-5-1`) runs behind safeguards of its own that flag some coding and security work. On the tasks of this
repository they stop part of its attempts, some before the model has answered at all. This page records what happens, how the
benchmark counts it, and the number of attempts affected so far, so that a missing or thin Fable row is not read as a score.

## What happens

- **Blocked at the start.** About two seconds after the first message the client ends the session with an API error whose
  `stop_reason` is `refusal`: the message says that Fable 5.1's safeguards flagged it, points to Anthropic's usage policy and adds
  that the safeguards are intentionally broad and can flag legitimate coding and cybersecurity tasks. The model writes nothing and
  changes nothing: the work folder is identical to the package, and the client's estimate of the attempt is about US$ 0.32 at list
  price (the package is read into the prompt before the stop).
- **Stopped during the work.** The same safeguard can stop one response in the middle of a session. The client then retries once, or
  switches to another model; with the model pinned in the client's policy the switch cannot happen in this benchmark's lab, and a
  stop the requested model recovers from is counted in the stop rate and the delivery is evaluated (`docs/new-series-design.md`, §6.1 and §6.2).
  Before the lab pinned the model, two LEB-100-A runs of Fable 5.1 at `xhigh` were voided because the client finished them with
  another model after such a stop (see `results/2026/LEB-100-A/README.md`).

The benchmark does not change a task, a message or a client setting to get past the safeguard (PROTOCOL §3: nothing is added to the
two messages), and it does not treat the stop as a fault of the lab.

## How the benchmark counts it

- **LEB-D-300-A.** A stop that leaves the requested model doing no further work is the outcome *blocked*, published as *blocked at
  the start* when the work folder equals the package (`docs/new-series-design.md`, §6.2). It is not a lab failure and the design does
  not rerun it; it enters the expected score per scheduled attempt (§9), never the quality score. For Fable, on the owner's request, the
  lab has also repeated the same message after a block, never reworded, up to a fixed number of attempts per run; how those extra
  attempts count in the statistics is a decision still open.
- **LEB-300-A and LEB-100-A.** The protocol has no *blocked* outcome: a configuration is on the leaderboard once it has completed
  runs. A configuration whose every attempt is blocked has no run and no row, and a missing row says that the task trips the
  safeguard for that configuration, not that the model scores low.

## The record

Counts of the lab's own attempts, as of 2026-10-27 (UTC−3). The evidence of each attempt stays in the private lab records.

| Instance | Effort | Attempts | Completed | Blocked at the start | Other |
| --- | --- | ---: | ---: | ---: | --- |
| LEB-300-A | `medium` | 60 | 0 | 60 | none |
| LEB-100-A | `medium` | 8 | 0 | 8 | none |
| LEB-D-300-A | `high` | 11 | 1 | 8 | 2 lab failures (the API credit ran out) |
| LEB-D-300-A | `medium` | 8 | 5 | 3 | none |
| LEB-D-300-A | `xhigh` | 2 | 0 | 1 | 1 lab failure |

- **LEB-300-A at `medium`: 60 of 60 attempts blocked.** 36 attempts on 2026-10-23 through an Anthropic API key (12 for each of three
  runs) and 24 on 2026-10-27 through a Claude subscription token (8 for each of three runs). The route does not matter: the stop
  comes from the model. The message is the instance's fixed first message, the same for every agent.
- **LEB-300-A at `high`:** one completed run is published (836).
- **LEB-D-300-A:** the safeguard stops Fable 5.1 on the Default track in a part of the attempts at every effort tried: 8 of 11 at
  `high`, 3 of 8 at `medium`, and the second attempt at `xhigh` (13 seconds after the first message, 2026-10-16).
- **LEB-100-A at `medium`: 8 of 8 attempts blocked**, all on 2026-10-27 through a Claude subscription token, with the instance's fixed first message each time; the queue dropped the run after the eighth and made no
  other. Nothing was brought and there is no row.
- **LEB-100-A at `xhigh`:** the published Fable 5.1 rows are at `xhigh` (two completed runs, 781 and 764). The two void runs above were hand runs of 2026-09-30 and 2026-10-01, before the lab pinned the model.

## What is not known

- Which part of the task trips the safeguard. The same first message is answered by every other model of the benchmark, and by
  Fable 5.1 itself at `high`, which completed one LEB-300-A run, so the stop is not a property of the message alone.
- Whether the safeguard changes over time. The counts above are a record of the dates shown, not a rate.

Update this table when a batch adds attempts, and say so in `version.md`.
