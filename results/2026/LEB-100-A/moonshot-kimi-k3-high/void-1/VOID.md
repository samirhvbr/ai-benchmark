# Void run: Kimi K3 at high, 2026-10-05 09:25–09:42 (UTC−3)

This was one of two Kimi K3 runs started at the same time on 2026-10-05. It is **void**: the VM was restored
to its clean snapshot while the delivery was being copied off it, as on 2026-10-04 with Grok 4.7 at xhigh,
so nothing of it survives to be scored.

## What was seen before it was lost

At 09:47–09:50, read-only checks of `bench3` (100.64.100.115) showed:

- opencode 1.18.33 with a single session, `ses_ef3f99c40ffekJDbDMyuZm0bp7`, in `/srv/run`, as the
  unprivileged user `leb`, with no client process left running.
- The fixed first message was sent **five times** in that session, at 09:25:07, 09:26:06, 09:26:48,
  09:27:12 and 09:28:07. The first four went to the Kimi Code plan providers (`kimi-code-plan-global`
  three times, `kimi-code-plan-cn` once). Each was rejected with "The API Key appears to be invalid or
  may have expired", so no model answered them. The fifth went to Moonshot's API (`moonshotai`,
  `kimi-k3`, variant `high`).
- From there, 35 responses, the last at 09:41:58, which finished with `stop` and no error. The session
  recorded a cost of US$ 0.7557.
- A complete delivery in `/srv/run`:
  - `RELATORIO.md` (15,312 bytes) and `achados.json` (11,772 bytes);
  - `config.php`, `index.php` and `lib.php` changed between 09:31:32 and 09:32:02;
  - `schema.sql` and `seed.sql` unchanged.

The copy reached `bench3` at about 09:51 and got "No route to host". When the VM came back, `/srv/run`
held the bare package and the opencode database had no session.

## Why it is void

Nothing was copied, no checksum was taken and no judge saw it. The cause, a restore before the copy,
does not depend on the result, which was never known.

Had the delivery survived, the repeated first message would still have needed a decision before judging.
The model probably saw the fixed message five times in its history, with no other content in it. That
question is now moot for this run, and it is recorded here for the next one.
