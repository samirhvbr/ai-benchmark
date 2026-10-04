# Withdrawn run: GPT-5.5 at `xhigh`, run 1 (601)

This run was scored and published, and is **withdrawn** on 2026-10-04 under `PROTOCOL §4` item 5. A run counts only with
its record complete: the client and its version, the first message, the session log and the cost.

## What is missing

`run.json` lists as not recorded: `cost_time`, `logs`, `operator_replies`, `client`, `first_message`, `session`. It was filed in LEB-100-A's first batch, on the first VM,
before the session logs were archived, so it cannot be told which client ran it, what the operator sent or what it cost.

## Why withdrawn, and why now

The operator decided on 2026-10-04 to keep only runs made by the standard method. The criterion is the record, not the result,
and it was applied at once to every run that fails it: this one and two others (MiniMax-M3 run 1, Kimi K3 run 1). One of the three, GPT-5.5's, was its
agent's best total, so the rule does not pick bad runs.

## What it changes

Runs 2 and 3 (558 and 568) were made in Codex CLI 0.159.3 with their sessions archived, and stay published. With two runs the agent publishes the lower, 558, and is no longer official; a further run, filed as `run-4`, would give it three.

The delivery, mechanical report, verdict and scorecard are kept here as they were scored. `tools/export-results.py` reads only
`run-<n>` folders, so nothing here is published, and the run's number stays taken so the agent's other runs keep theirs.
