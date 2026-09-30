# Void run — Claude Fable 5.1, 2026-09-30 07:55–08:25 (UTC−3)

This was meant to be Claude Fable 5.1's second run on LEB-100-A. It is **void**, because the model
under test did not finish it.

## What the client's session log shows

- The run was made in Claude Code 2.1.285, in the folder `/srv/claude-fable-5.1-xhigh`, with the first
  message *"Não saia desta pasta. Agora leia o TAREFA.md e execute. Devolva code/ alterado,
  RELATORIO.md e achados.json."*, and no other operator message.
- **Fable 5.1 did the first 20 minutes.** It read the package, planned, and rewrote `config.php`
  and `lib.php`.
- **08:15:06 — the safeguards stopped Fable 5.1.** Its safeguards stopped one of its responses
  ("Fable 5.1's safeguards stopped the response above · continuing once with that noted").
- **08:16:15 — the client switched models.** The client logged `model_refusal_fallback` and
  switched to Claude Opus 4.8 on its own.
- **Opus 4.8 finished the run.** Its 13 answers, from 08:17:45 on, include the final checks and
  the whole of `RELATORIO.md` (08:20:38) and `achados.json` (08:21:52).
- **The client's own cost record confirms it.** For this session it bills US$ 7.14 to
  `claude-fable-5-1` and US$ 1.33 to `claude-opus-4-8`.

The delivery is therefore part Fable 5.1 and part Opus 4.8, and every word of the report the judges
score came from Opus 4.8. It measures neither model.

## Why it is kept and not scored

It was voided on the evidence of the log, before its score was assembled and before its blind label
was matched to the model. So no score shaped the decision, and replacing it is not the selective
retry `PROTOCOL §4` forbids. The delivery (`entrega/`) and its mechanical report
(`mecanico.json`, 22/22 characterization; SEC-001, BUG-001 and PERF-001 fixed) are kept here as
filed. It has no `scorecard.json`, so `tools/export-results.py` does not publish it. Fable 5.1's next
run is its run 2.

Anyone re-running a model whose client can fall back to another model should turn that fallback
off. A run the client finishes with a different model is void.
