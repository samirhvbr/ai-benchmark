# Void run — Claude Fable 5.1, 2026-10-01 12:44–13:11 (UTC−3)

This was meant to be Claude Fable 5.1's third run on LEB-100-A, the one its official score needs. It
is **void**, for the same reason as `void-1`: the model under test did not finish it.

## What the client's session log shows

- The run was made in Claude Code 2.1.285 on `bench1` (100.64.100.113), as the unprivileged user
  `leb`, on a machine restored to its clean snapshot. The model and effort were set before the
  first message, which was the fixed text and nothing else.
- **Fable 5.1 did the first 13 minutes.** It read the package, characterized the legacy code
  against its own MariaDB, and rewrote `config.php`, `lib.php` and `index.php` (12:55–12:56).
- **12:57:44 — the safeguards stopped Fable 5.1.** One of its responses was stopped ("Fable 5.1's
  safeguards stopped the response above · continuing once with that noted").
- **13:01:13 — the client switched models.** It logged `model_refusal_fallback` ("Switched to Opus
  4.8") and handed the rest of the session to Claude Opus 4.8. This happened although `leb`'s
  `~/.claude/settings.json` had `"switchModelsOnFlag": false`: that setting did not stop this
  fallback.
- **Opus 4.8 finished the run.** Its 33 responses, from 13:02:21 on, include three edits to
  `code/lib.php`, the whole of `achados.json` (13:08:35) and the whole of `RELATORIO.md`
  (13:10:30). Fable 5.1 had 46 responses before the switch.
- **The client's own cost record confirms it.** For this session (`c2bbd6a6`) it bills US$ 5.68 to
  `claude-fable-5-1`, US$ 2.34 to `claude-opus-4-8` and US$ 0.001 to the session-title call to
  Claude Haiku 4.5; US$ 8.03 in all.

Part of the code, and every word of the report and the findings index, came from Opus 4.8. Under
`PROTOCOL §3` (one model per run) the run is void.

## Why it is kept and not scored

It was voided on the evidence of the log, before any judge saw it and before a score was
assembled, so no score shaped the decision and replacing it is not the selective retry
`PROTOCOL §4` forbids. The delivery (`entrega/`) and its mechanical report (`mecanico.json`, 22/22
characterization; SEC-001, BUG-001 and PERF-001 fixed) are kept here as filed. It has no
`scorecard.json`, so `tools/export-results.py` does not publish it. Fable 5.1 still has two runs;
its next one is its run 3.

The stop came in the security part of the task. Just before it, Fable 5.1 had re-run its test
battery against the fixed code and was reading the results of its own SQL injection payloads
(`busca SQLi OR`) to confirm the fix held. The operator saw the same pattern in `void-1`: Fable
falls back to Opus 4.8 once its work reaches the security flaws.
