# Withdrawn run: Kimi K3 at its default, run 1 (528)

This run was scored and published, and is **withdrawn** on 2026-10-04 under `PROTOCOL §4` item 5. A run counts only with
its record complete: the client and its version, the first message, the session log and the cost.

## What is missing

`run.json` lists as not recorded: `client`, `first_message`, `session`, `operator_replies`, `cost_time`, `logs`, `execution_host`. It was filed in LEB-100-A's first batch, on a clone of the first VM,
before the session logs were archived, so it cannot be told which client ran it, what the operator sent or what it cost.

## Why withdrawn, and why now

The operator decided on 2026-10-04 to keep only runs made by the standard method. The criterion is the record, not the result,
and it was applied at once to every run that fails it: this one and two others (GPT-5.5 run 1, MiniMax-M3 run 1). One of the three, GPT-5.5's, was its
agent's best total, so the rule does not pick bad runs.

## What it changes

This was the agent's only run, so the agent leaves the leaderboard. It ran on a clone of the execution VM that was destroyed with its session log.

The delivery, mechanical report, verdict and scorecard are kept here as they were scored. `tools/export-results.py` reads only
`run-<n>` folders, so nothing here is published, and the run's number stays taken so the agent's other runs keep theirs.
