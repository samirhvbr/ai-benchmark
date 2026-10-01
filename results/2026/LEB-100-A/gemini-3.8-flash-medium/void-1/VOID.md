# Void attempt — Gemini 3.8 Flash at medium, 2026-10-01 14:39–15:11 (UTC−3)

This was meant to be Gemini 3.8 Flash's first run at `medium` on LEB-100-A. It is **void**, because
the run was not set up the way every other run is: the agent was not started in a copy of the
package.

## What the client's session record shows

- The attempt was made in opencode 1.18.33 on `bench3` (100.64.100.115), as the unprivileged user
  `leb`, with `google/gemini-3.8-flash` at `medium` through OpenRouter and the fixed first message
  (followed by a trailing space), and no other operator message.
- **The client was started in `/srv`, not in `/srv/run`.** No copy of the package had been made.
  `/srv` held the reference copy of the package (`/srv/LEB-100-A`, read-only to `leb`), an older
  copy (`/srv/leb/LEB-100-A`) and the operator's `prompt.md` with the first message.
- **The agent spent its first four minutes working out where it was.** It read both package copies
  and `prompt.md`, diffed the two copies, listed `/home/leb` and `/home/samir`, ran `ps aux`, `env`
  and `sudo -n -l` (refused: `leb` has no sudo), searched the disk for files named `*harness*` or
  `*protocol*` (none exist on the VM), and tested where it could write.
- At 14:43 it copied `code/`, `manifest.md` and `TAREFA.md` into `/srv` itself, without
  `.leb-pacote.sha256`, worked there, and wrote `RELATORIO.md` and `achados.json` in `/srv`
  (15:07–15:10). The session ended normally at 15:11, after 77 responses, for US$ 1.10.

## Why it is void and not scored

Every run receives the package as its working folder (`PROTOCOL §1`); this one received a shared
directory with two package copies and the operator's notes, and part of its budget went into
locating the task. The cause is the setup, not the model, and the attempt was voided on the
evidence of the session record before anything was judged, so replacing it is not the selective
retry `PROTOCOL §4` forbids. Its files (`entrega/`, copied from `/srv`) are kept as found. It has no
`scorecard.json`, so `tools/export-results.py` does not publish it.
