# Unscored run: Gemini 3.7 Flash at high, 2026-10-05 11:16–11:25 (UTC−3)

This delivery is **not scored**: it would be the agent's fourth run.

## What the session log shows

- opencode 1.18.33 on `bench1` (100.64.100.113), as the unprivileged user `leb`, on a machine restored to
  its clean snapshot, in `/srv/run`, with `google/gemini-3.7-flash` through OpenRouter at the `high` variant.
- Session `ses_ef393ab55ffeZSIZ6LS37mynia`. It got the fixed first message at 11:16:28 and nothing else,
  made 46 model responses and finished normally at 11:25:54. The session recorded a cost of US$ 0.5047.
- Run 3 of the same agent had started on `bench3` 13 seconds earlier, at 11:16:15. It reached the evaluator
  first and is run 3. Runs 1 and 2 were already filed.

## Why it is not scored

`PROTOCOL §4` allows at most three runs per agent. The run was set aside on that count before any judge
saw it. Its delivery (`entrega/`) and mechanical report (`mecanico.json`) are kept here as filed. It has no
`scorecard.json`, so `tools/export-results.py` does not publish it.
