# Void attempt: Claude Fable 5.1 at xhigh, 2026-10-04 10:12 (UTC−3)

This was meant to be Claude Fable 5.1's third run. It is **void**: the client's login had expired, so the
first message never reached the model. This is the same case as the multi-agent Sonnet attempt in
`claude-sonnet-5.5-max-ultracode/void-2/`.

## What the session log shows

- Claude Code on `bench1` (100.64.100.113), as the unprivileged user `leb`, in `/srv/run`. The session is
  `40a27ea1-40fa-4601-a098-0132f02b9535`.
- At 10:11:45 the operator set the model to Fable 5.1, and at 10:12:04 the effort to `xhigh`. At 10:12:10
  the fixed first message of `PROTOCOL §3` was sent.
- The client answered it locally a second later with "Login expired · Please run /login" (`<synthetic>`).
  No request reached the model, and there is no tool call.
- The file was found on 2026-10-05 inside the VM's snapshot. `/srv/run` held only the package when the
  next run started that day.

## Why it is void

Nothing was delivered and the model never saw the task. The cause, an expired login, does not depend on
any result. Fable 5.1 still needs its third run.
