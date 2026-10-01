# Void attempt — Gemini 3.8 Flash at medium, 2026-10-01 15:26–15:29 (UTC−3)

This attempt is **void**: the provider stopped serving the model before the delivery was complete.

## What the client's session record shows

- opencode 1.18.33 on `bench1` (100.64.100.113), as the unprivileged user `leb`, on a restored
  clean snapshot, in `/srv/run`, with `google/gemini-3.8-flash` at `medium` through OpenRouter and
  the fixed first message (followed by a trailing space), sent at 15:26:29. `void-2` was still
  running on `bench2` through the same gateway.
- The agent edited `code/lib.php` and `code/index.php` and wrote `RELATORIO.md` (15:29:27); it had
  not written `achados.json`.
- **15:29:27 — the request failed.** opencode recorded `APIError` with HTTP 429 from OpenRouter:
  "[Google] google/gemini-3.8-flash is temporarily rate-limited upstream". The session stopped
  there, after 21 responses and US$ 0.12.

## Why it is void and not scored

The attempt ended on the provider's rate limit, a cause outside the model, before its findings
index was written. It was voided on the evidence of the session record, before any judge saw it, so
replacing it is not the selective retry `PROTOCOL §4` forbids. The partial delivery (`entrega/`)
and its mechanical report (`mecanico.json`) are kept as filed. It has no `scorecard.json`, so
`tools/export-results.py` does not publish it.
