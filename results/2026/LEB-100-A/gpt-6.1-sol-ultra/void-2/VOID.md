# Unscored run — GPT-6.1-sol at ultra, 2026-10-02 18:46:13–18:58:03 (UTC−3)

This delivery is **not scored**: it would be the agent's fifth run.

## What the session log shows

- Codex CLI 0.159.3 on `bench1 (100.64.100.113)`, as the unprivileged user `leb`, on a machine restored to its
  clean snapshot, in `/srv/run`, with `gpt-6.1-sol` at `ultra` (every turn's `turn_context`), the fixed
  first message (sent at 18:46:13) and nothing else. It spawned 3 subagents and completed
  normally, with `code/`, `RELATORIO.md` and `achados.json` written. Main rollout:
  `rollout-2026-10-02T18-46-01-01a0fe94-d40e-76d1-ab80-0162fc3b954d.jsonl`.
- GPT-6.1-sol at `ultra` already had three runs: 597, 656 and the run 3 started on `bench2` at
  18:27:28, before this one, and published at 616, official.

## Why it is not scored

`PROTOCOL §4` allows at most three runs per agent. It was set aside on that count, and on the
start times its own logs record, before any judge saw it. Its delivery (`entrega/`) and mechanical
report (`mecanico.json`, 22/22 characterization) are kept here as filed. It has no
`scorecard.json`, so `tools/export-results.py` does not publish it.
