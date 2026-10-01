# Void run: Claude Haiku 4.5, `xhigh`, 2026-10-01 10:56–11:02 (UTC−3)

This was meant to be the first run of Claude Haiku 4.5 at effort `xhigh`. It is **void**, because
the operator changed the client's mode while the agent was working.

## What the session log shows

- Client: Claude Code 2.1.285, on `bench2`, as the unprivileged user `leb`, in `/srv/run`.
- Before the first message, the operator set the model to Haiku 4.5 (`/model`) and the effort to
  `xhigh` (`/effort`). The first message was the fixed one of `PROTOCOL §3`, and no other message
  followed.
- **About 1.5 minutes in, the client's permission mode was switched from the keyboard**, from
  `default` to `plan` and then to `acceptEdits`. No tool call of the agent asked for it.
- **Plan mode put an instruction into the agent's context**, telling it not to edit. The next turn
  answered *"Modo de planejamento ativo: não farei mais edições no código"* ("plan mode on: I will
  not edit the code any more"). In that mode Claude Code answers with its planning model, so that
  turn came from **Claude Sonnet 5.5**. It ran one read-only `ls` and cost US$ 0.22 of the session's
  US$ 0.57.
- After the switch to `acceptEdits`, Haiku 4.5 wrote the whole delivery (`RELATORIO.md`,
  `achados.json` and the code), and the session ended at 11:02.

## Why it is void and not scored

The fixed first message and the fixed reply are the only content an operator may put into a run
(`PROTOCOL §3`). Plan mode added an instruction, and one turn of another model, that no other run
received. None of the delivery came from Sonnet 5.5, but the instruction reached the model under
test. It was voided on the evidence of the log, before the harness or any judge ran, so replacing
it is not the selective retry `PROTOCOL §4` forbids. The agent's run 1 is the next one.

## Also found: the VM's snapshot was taken mid-run

`bench2`'s snapshot holds the first 15 seconds of Claude Opus 5.5's third run: a Claude Code session
log with its first tool call, reading `TAREFA.md`, and nothing more. This run never read it, and it
reveals nothing about the task. The snapshot is retaken with every client closed before the next run.
