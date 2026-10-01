# Void attempt — Gemini 3.8 Flash, 2026-10-01 13:45–13:47 (UTC−3)

This was meant to be one of Gemini 3.8 Flash's runs on LEB-100-A. It is **void**, because the
provider stopped serving it before the model had written anything.

## What the client's session record shows

- The attempt was made in opencode 1.18.33 on `bench2` (100.64.100.114), as the unprivileged user
  `leb`, on a machine restored to its clean snapshot, with `google/gemini-3.8-flash` at `high`
  through OpenRouter and the fixed first message (followed by a trailing space).
- In its first two minutes the model read the package: 11 responses, 314 output tokens, US$ 0.08.
- **13:47:41 — the request failed.** opencode recorded `UnknownError` with OpenRouter's
  `{"code":504,"message":"The operation was aborted","metadata":{"error_type":"timeout"}}`, and the
  session stopped there.
- **Nothing was written.** The folder copied off the machine (`entrega/`) is the package as
  delivered: `code/` is unchanged and `sha256sum -c .leb-pacote.sha256` passes; there is no
  `RELATORIO.md` and no `achados.json`.

## Why it is void and not scored

The attempt ended on a gateway timeout, a cause outside the model, before any delivery existed.
It was voided on the evidence of the session record, before anything was judged, so replacing it
is not the selective retry `PROTOCOL §4` forbids — the same case as `gpt-5.6-sol-pro-max/void-1`.
It has no `scorecard.json`, so `tools/export-results.py` does not publish it.
