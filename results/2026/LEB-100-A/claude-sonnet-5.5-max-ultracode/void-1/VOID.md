# Void run: Claude Sonnet 5.5, max effort, multi-agent ("ultracode"), 2026-09-30 12:34–13:00 (UTC−3)

This was meant to be the first run of Claude Sonnet 5.5 in Claude Code's multi-agent mode
("ultracode"). It is **void**: the operator stopped it, before any delivery, to resize the machine
it was running on.

## What happened

- Client: Claude Code 2.1.285, working in `/srv/claude-sonnet-5.5-max-ultracode`. Model:
  `claude-sonnet-5-5` for every answer. First message: the fixed one of `PROTOCOL §3`, with no
  other operator message before the interruption.
- Machine: the execution VM at 4 vCPUs and 3.8 GiB of RAM, with every isolation layer in place.
- About 20 minutes in, the agent reported that the machine's 4 CPUs limited each of its workflows
  to 2 agents at a time. It then set out to run several workflows in parallel: discovery in three
  groups, plus an adversarial review of its own implementation.
- The operator judged that the machine, not the agent, was setting that limit. At 13:00:01 the
  operator interrupted the session, and the session's background workflows were stopped at 13:01,
  when the VM was shut down to be resized to 20 vCPUs and 15 GiB.
- By then it had started 5 workflows with 15 subagents. The client's own record puts the cost at
  US$ 8.43, with 2,705 s of model time across the parallel agents in 1,621 s of wall-clock.

## What is kept, and why it is not scored

The folder is kept as it was at the interruption (`entrega/`). The agent had rewritten
`config.php`, `index.php` and `lib.php` but had written no `RELATORIO.md` and no `achados.json`, so
there is no delivery to score. It was not run through the harness.

The run was stopped for the machine, not for anything it produced. The decision came before there
was any output to judge, so replacing it is not the selective retry `PROTOCOL §4` forbids. The
agent's run 1 is the run that followed, on the resized machine. Since then every run records the
machine's size (`execution_host`, `PROTOCOL §3`).
