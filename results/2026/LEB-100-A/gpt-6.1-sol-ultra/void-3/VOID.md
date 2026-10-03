# Unscored run — GPT-6.1-sol at ultra, 2026-10-02 19:49:43–20:02:39 (UTC−3)

This delivery is **not scored**: it would be the agent's sixth run.

## What the session log shows

- Codex CLI 0.159.3 on `bench1` (100.64.100.113), as the unprivileged user `leb`, on a machine
  restored to its clean snapshot, in `/srv/run`, with `gpt-6.1-sol` at `ultra`, the fixed first
  message (sent at 19:49:43) and nothing else. It spawned 2 subagents and completed normally.
  Main rollout: `rollout-2026-10-02T19-48-45-01a0fece-442c-7740-bd7d-abf245114ef1.jsonl`.
- GPT-6.1-sol at `ultra` already had its three runs (597, 656 and 616, official at 616), and two
  more kept unscored in `void-1` and `void-2`.

## Why it is not scored

`PROTOCOL §4` allows at most three runs per agent. It was set aside on that count before any judge
saw it. Its delivery (`entrega/`) and mechanical report (`mecanico.json`, 22/22 characterization)
are kept here as filed. It has no `scorecard.json`, so `tools/export-results.py` does not publish it.
