# Void run: Grok 4.7 at xhigh, 2026-10-04 09:35–09:58 (UTC−3)

This was meant to be the first run of Grok 4.7 at `xhigh` (a separate agent from Grok 4.7 at `high`). It is
**void**: the VM was restored to its clean snapshot before the delivery was copied off it, so nothing of
it survives to be scored.

## What was seen before it was lost

At 10:07, a read-only check of `bench3` (100.64.100.115) showed:

- opencode 1.18.33, session `ses_ef916716fffe7BXuYVOkgN585I` in `/srv/run`, as the unprivileged user `leb`,
  with no client process left running.
- One operator message, the fixed first message of `PROTOCOL §3` at 09:35:32, and nothing after it.
- 40 model responses, all from `x-ai/grok-4.7` with the `xhigh` variant through OpenRouter. The last one
  was at 09:58:15 and finished with `stop`, with no error. The session recorded a cost of US$ 3.0025.
- A complete delivery in `/srv/run`:
  - `RELATORIO.md` (17,982 bytes) and `achados.json` (12,872 bytes);
  - `config.php`, `index.php` and `lib.php` changed between 09:53:15 and 09:53:32;
  - `schema.sql` and `seed.sql` unchanged.

The copy started at about 10:08. vm1's delivery came through first. When the copy reached `bench3`, it
answered "No route to host": the VM was being restored. When it came back, `/srv/run` held the bare
package and the opencode database had no session.

## Why it is void

Nothing was copied, no checksum was taken and no judge saw it, so there is no delivery to score. The
cause, a restore before the copy, does not depend on the result, which was never known. The agent may run
again, and that run will be its run 1.
