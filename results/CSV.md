# The results as CSV

`tools/export-results.py` writes two files next to `results.json`, from the same inputs, so they
cannot disagree with the leaderboard. Both are UTF-8 and comma-separated, with a header row and
`\n` line ends. An empty cell means unknown or not recorded, never zero. Void runs are not
included, just as they are not in the leaderboard.

## `runs.csv` — one row per scored run

| Column | Meaning |
| --- | --- |
| `edition`, `instance`, `agent`, `run` | Where the run lives: `results/<edition>/<instance>/<agent>/run-<run>/`. |
| `counts_in_score` | `true` for the run whose total is the agent's published score: the lower median of its runs (`PROTOCOL §4`). |
| `agent_rank`, `agent_score` | The agent's place and published score. They repeat on every run of that agent. |
| `total`, `grade` | This run's total out of 1000 and its grade. |
| `model`, `model_id`, `provider` | The model as its provider names it. |
| `served_by` | The host or gateway that served the model, when it was not the provider's own API (OpenRouter, Novita AI, …). |
| `reasoning_effort` | The effort setting: `xhigh`, `high`, `max`, `ultra`, or `default` for a model with no setting. |
| `client_mode` | A client mode that changes how the model works, such as `ultracode`, Claude Code's multi-agent mode. |
| `client`, `client_version` | The agent client the run was made in. |
| `training_cutoff` | The cutoff the provider publishes; empty when it publishes none. |
| `key_exposure` | `before` when that cutoff predates the public answer key, `after` or `unknown` otherwise (the dagger on the leaderboard). |
| `SEC` … `EXPL` | Category scores; the maximum for each is 250, 200, 150, 150, 100, 100 and 50. |
| `penalties` | The total deducted for penalties, as a negative number or 0. |
| `flaws_found`, `flaws_fixed`, `flaws_planted` | Planted flaws found (identified at least in part), fully fixed, and planted in the instance. |
| `false_positives`, `extra_findings` | Reported flaws that do not exist, and real findings outside the answer key (scored 0). |
| `discovery_index`, `brier` | Difficulty-weighted share of planted flaws found, and the calibration error of the declared confidences. Both are informative and do not enter the total. |
| `characterization_passed` | Characterization checks that passed, out of 22. |
| `operator_replies` | Operator messages after the first one; since 2026-09-30 only the fixed reply of `PROTOCOL §3` is allowed. |
| `session_started`, `session_ended`, `wall_minutes` | The session's start and end (UTC−3) and the minutes between them. |
| `model_seconds` | The model's working time, summed across parallel agents, where the client keeps it. |
| `tokens_*`, `cost_usd` | The usage and the cost the client recorded; empty where it records none. |
| `vcpus`, `ram_gib` | The size of the machine. |
| `filed_on`, `scorecard_url` | The day the delivery was filed, and its scorecard. |

## `flaws.csv` — one row per run and planted flaw

| Column | Meaning |
| --- | --- |
| `edition`, `instance`, `agent`, `run`, `counts_in_score` | As in `runs.csv`. |
| `flaw`, `category`, `severity`, `difficulty` | The planted flaw, from the instance's answer key. |
| `reported` | Whether the delivery's report names it. |
| `found`, `explained`, `fixed`, `compatible` | The judge's criteria as `full`, `half` or `none`: C1 to C3 and C5 for a flaw, R1 to R4 for a refactor (SCORING §2). A fix that broke compatibility has `compatible` `none`. |
| `points_earned`, `points_possible` | The flaw's points in this run. |
| `confidence` | The confidence the model declared for it, 0 to 100; empty when it did not report it. |
