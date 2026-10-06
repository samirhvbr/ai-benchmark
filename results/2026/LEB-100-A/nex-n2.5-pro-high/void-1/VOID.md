# Unscored run: Nex N2.5 Pro at high, 2026-10-05 13:15 to 2026-10-06 14:30 (UTC−3)

This delivery is **not scored**: the operator voided it for a connection failure on his side, before any judge
saw it, and chose not to repeat it.

## What the session log shows

- opencode 1.18.33 on `bench3` (100.64.100.115), as the unprivileged user `leb`, on a machine restored to its
  clean snapshot, in `/srv/run`, with `nex-agi/nex-n2.5-pro` through OpenRouter at the `high` variant, the
  same agent as runs 1 and 2, started an hour after them.
- Session `ses_ef32697dcffeGJ3RuO5b7cfkOY`. It got the fixed first message at 13:15:37, made 925 model
  responses with ten subagents, and recorded a cost of US$ 6.0528. The client compacted the context 34 times.
- At 10:43:57 on 2026-10-06 a response was aborted (`MessageAbortedError`) when the operator's connection
  failed. At 14:30:06 the operator typed "contnua" (sic), and that response was aborted too.
- `RELATORIO.md` and `achados.json` were last written at 19:02 on 2026-10-05; `code/` kept changing after that.

## Why it is not scored

The interruption came from the operator's connection, not from the model or its client, and the operator's
message after it breaks the fixed-message rule (`PROTOCOL §4`). Either is a result-independent reason, and the
run was set aside on it before any judge saw it. The operator decided not to run a third attempt: each Nex
N2.5 Pro run took between 10 and more than 25 hours, which he judged too slow to repeat. The agent therefore
keeps two runs and publishes the lower. The delivery (`entrega/`) is kept here as filed; it has no
`scorecard.json`, so `tools/export-results.py` does not publish it.
