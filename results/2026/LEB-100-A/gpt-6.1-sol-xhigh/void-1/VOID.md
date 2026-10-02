# Unscored run — GPT-6.1-sol at xhigh, 2026-10-02 13:27–13:50 (UTC−3)

This delivery is **not scored**: it would be the agent's fourth run.

## What the session log shows

- Codex CLI 0.159.3 on `bench2` (100.64.100.114), as the unprivileged user `leb`, on a machine
  restored to its clean snapshot, in `/srv/run`, with `gpt-6.1-sol` at `xhigh` and the fixed first
  message, sent at 13:27:58. The session completed normally at 13:50:30, with `code/`,
  `RELATORIO.md` and `achados.json` written (`.codex/sessions/2026/10/02/rollout-2026-10-02T13-27-20-…`).
- GPT-6.1-sol at `xhigh` already had two runs (666 and 653). The same agent was started on `bench1`
  48 seconds earlier, at 13:27:09; that session is the agent's run 3.

## Why it is not scored

`PROTOCOL §4` allows at most three runs per agent: a fourth would let the published score be chosen
among more runs than the rule permits, the selective retry item 3 forbids. Of the two sessions
started together, the one whose first message went out first is run 3; this one was set aside on
that ordering alone, before any judge saw either delivery. Its delivery (`entrega/`) and mechanical
report (`mecanico.json`, 22/22 characterization) are kept here as filed. It has no
`scorecard.json`, so `tools/export-results.py` does not publish it.
