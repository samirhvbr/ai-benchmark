# Unscored attempt — GPT-5.6-terra at xhigh in opencode, 2026-10-01 15:28–15:34 (UTC−3)

This attempt is **not scored**: it ran in a different client from the agent it would have joined.

## What the session record shows

- opencode 1.18.33 on `bench3` (100.64.100.115), as the unprivileged user `leb`, on a restored
  clean snapshot, in `/srv/run`, with `openai/gpt-5.6-terra` at `xhigh` through OpenRouter and the
  fixed first message (followed by a trailing space). It finished normally at 15:34 after 27
  responses, for US$ 0.65, with `code/`, `RELATORIO.md` and `achados.json` written.
- GPT-5.6-terra's run 1 was made in Codex CLI 0.159.2 on OpenAI's own API.

## Why it is not scored

A client brings its own system prompt and tools, so the same model in Codex CLI and in opencode is
not the same agent. Filing this as `gpt-5.6-terra-xhigh` run 2 would mix two clients in one score,
and filing it as a new agent was not wanted: the operator decided to repeat the run in Codex CLI.
That decision was made on the client alone, before any judge saw the delivery, so it is not the
selective retry `PROTOCOL §4` forbids. The delivery (`entrega/`) and its mechanical report
(`mecanico.json`, 22/22 characterization) are kept here as filed. It has no `scorecard.json`, so
`tools/export-results.py` does not publish it.
