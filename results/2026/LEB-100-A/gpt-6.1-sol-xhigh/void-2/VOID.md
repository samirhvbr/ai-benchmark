# Unscored run — GPT-6.1-sol at xhigh, 2026-10-02 16:16–16:34 (UTC−3)

This delivery is **not scored**: it would be the agent's fifth run, after three scored runs and a
fourth kept unscored in `void-1`.

## What the session log shows

- Codex CLI 0.159.3 on `bench2` (100.64.100.114), as the unprivileged user `leb`, on a machine
  restored to its clean snapshot, in `/srv/run`, with the fixed first message and nothing else. The
  session completed normally, with `code/`, `RELATORIO.md` and `achados.json` written.
- The operator meant to run GPT-6.1-sol at `ultra`, the agent that needs two more runs. The log's
  `turn_context` records `effort: xhigh` for every turn; run 1 of the `ultra` agent records
  `effort: ultra`. So this session is GPT-6.1-sol at `xhigh`, an agent that already has its three runs
  (666, 653 and 661, official at 661).

## Why it is not scored

`PROTOCOL §4` allows at most three runs per agent. It was set aside on the effort its own log
records, before any judge saw it. Its delivery (`entrega/`) and mechanical report (`mecanico.json`,
22/22 characterization) are kept here as filed. It has no `scorecard.json`, so
`tools/export-results.py` does not publish it. GPT-6.1-sol at `ultra` still has one run.
