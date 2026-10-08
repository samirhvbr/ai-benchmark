# The candidate VM

How the machine that runs the agents is built, laid out and kept, so the runs can be reproduced. It describes the three reference VMs (`bench1` to `bench3`) as of
2026-10-08.

This is a record of current practice, not a rule of the protocol: the normative text is [`protocol/PROTOCOL.md`](../protocol/PROTOCOL.md). How the VM is kept away from
GitHub, so an agent cannot read this repository or an answer key, is in the README ([Execution environment](../README.md#execution-environment)) and is not repeated here.

## To replicate it, in short

1. Build a Linux VM with the toolchain of §2, an admin account with sudo and an unprivileged runner account (§3).
2. Create the folders of §3 and copy in the four items of §4, checking their hashes.
3. Leave the VM ready once: the test database, the offline Maven cache, and a proof that they work on a throwaway copy (§5).
4. Put each client at its factory default, with the few deviations of §6, and clean the runner's client folders.
5. Apply the GitHub layers of the README.
6. Power the VM off and take the clean snapshot (§7). A run is then: restore it, open the client in the package folder, send the first message (§9).

## 1. Two machines, one direction

| Machine | What it does | What it holds |
| --- | --- | --- |
| Candidate VM | Runs the client (Claude Code, Codex, OpenCode, ...) and the model's tools on the package | The package, the offline dependency cache, `tools/etapas.py`. **No answer key, matrix, verifier or reference solution, no GitHub, no copy of this repository** |
| Evaluator workstation | Builds the package, evaluates the delivery (Docker, disposable database), scores it | Everything private |

Only four things go to the VM: the package folder (`code/`, `manifest.md`, the task), the Maven cache tarball and its SHA-256 list, and `tools/etapas.py`. What comes back is the
delivery, the transcripts and the evidence of the run.

## 2. Reference build

| Item | Reference | How to check |
| --- | --- | --- |
| Hypervisor | Proxmox; VM snapshots | |
| CPU | 20 vCPU (QEMU virtual CPU), one thread per core | `nproc`, `lscpu` |
| Memory | 15.5 GiB RAM, 3.8 GiB swap | `/proc/meminfo` |
| Disk | 17 GB root, a separate 3.9 GB `/tmp`, and an XFS volume for `/srv`; 9 to 11 GB free on root after setup | `df -h` |
| OS | Debian 13 (trixie), kernel 6.12 | `/etc/os-release` |
| Clock | NTP synchronized | `timedatectl` |
| Locale | `pt_BR.UTF-8` (see §10) | `locale` |
| JDK and Maven | OpenJDK 21.0.12 and Maven 3.9.9, both from the distribution (`openjdk-21-jdk-headless`, `maven`) | `java -version`, `mvn -v` |
| PostgreSQL | 17.11 from the distribution, on `127.0.0.1:5432`. The packages accept 16 or newer; the evaluator runs on 16 and the package says so | `pg_isready`, `psql --version` |
| Other | Python 3.13 (for `etapas.py`), GNU tar and coreutils, curl | |

The three VMs are identical in all of this. Each instance's own `code/AMBIENTE.md` lists the toolchain it needs; the table is what is installed. The versions come from the
distribution and drift, so record them for every run with a read-only diagnostic: identity and clock, CPU, memory and disk, toolchain versions, the local PostgreSQL, Maven
settings and cache, and whether Maven Central is reachable. It writes only to standard output and needs no sudo.

## 3. Accounts and folders

- **Admin account** (sudo): installs, copies files, takes snapshots.
- **Runner account** (`leb`): runs the client. No sudo and no group but its own.

```text
/srv/<INSTANCE>/      the package, one folder per instance, and the agent's work folder. Owned by the runner, folders 775, files 664
/srv/prompt.md        the operator's messages (task messages and the fixed reply). Owned by the admin, mode 600: the runner cannot read it
~leb/leb/             operator material: etapas.py, the cache tarball and list, a copy of the package, runs/<model>-<n>/ (the evidence)
```

- **The agent works directly in `/srv/<INSTANCE>`, so the runner must own it.** Restoring the snapshot is what gives the pristine package back. The package's
  `.leb-pacote.sha256` lists what it was: `sha256sum -c` shows the files an agent changed, and a `find` shows the ones it added.
- Keep **one** instance folder in `/srv` at run time: delete the other, so the agent does not see it.
- The evidence of a run stays outside the work folder.
- `/srv/prompt.md` holds the second-stage message of task 1.2.0. If the agent could read it during the first stage, the staged design would be spoiled; keep it unreadable
  to the runner.

## 4. What goes to the VM, and how it is checked

The freeze keeps the package files read-only (mode 400), and a plain copy carries that into the work folder, where the agent could not edit `code/`. Copy with the modes
normalized, and send nothing else:

```bash
# on the workstation, from the folder that holds the four items
tar -c --mode='u+rwX,go-rwx' pacote maven-cache-<INSTANCE>.tar.gz cache-manifest.sha256 etapas.py \
  | ssh <admin>@<vm> 'sudo -Hu leb bash -c "mkdir -p ~/leb && tar -x --no-same-owner -C ~/leb"'

# on the VM, as the runner
cd ~/leb
sha256sum pacote/.leb-pacote.sha256                     # equals the package hash recorded for the freeze
(cd pacote && LC_ALL=C sha256sum -c .leb-pacote.sha256) # every line must say OK
sha256sum maven-cache-<INSTANCE>.tar.gz                 # equals the cache hash recorded for the freeze
```

Then make the work folder, owned by the runner:

```bash
mkdir /srv/<INSTANCE>
sudo tar -C ~leb/leb/pacote -cf - . | tar -C /srv/<INSTANCE> -xf - --no-same-owner --no-same-permissions
find /srv/<INSTANCE> -type d -exec chmod 775 {} + ; find /srv/<INSTANCE> -type f -exec chmod 664 {} +
sudo chown -R leb:leb /srv/<INSTANCE>
```

## 5. Leave the VM ready, once

The test database and the offline cache are the same at the start of every run, and the agent sees the same state whether they are made after the restore or before the
snapshot. So they are made once, before the snapshot. Steps 2 and 3 are for an instance that needs Maven and PostgreSQL; an instance on another stack replaces them with what
its own environment note asks.

1. Check that no client is running and that `~/.m2` does not exist yet.
2. The database and the role, with fictitious credentials. The role needs to create and drop schemas, nothing else:

   ```sql
   CREATE ROLE leb_dev LOGIN PASSWORD 'leb_dev_pw';
   CREATE DATABASE leb_dev OWNER leb_dev;
   ```

3. The dependencies, from the offline cache (Maven Central is reachable, and Maven still runs with `-o` because the evaluator builds offline):

   ```bash
   mkdir -p ~/.m2/repository && tar -xzf ~/leb/maven-cache-<INSTANCE>.tar.gz -C ~/.m2/repository
   cp ~/leb/cache-manifest.sha256 ~/.m2/repository/
   (cd ~/.m2/repository && LC_ALL=C sha256sum -c cache-manifest.sha256 | grep -vc ': OK$')   # must print 0
   ```

4. **Prove it on a throwaway copy, never in the work folder.** Copy the package to a temporary folder, run the check shipped in the package (it must say the environment is ready)
   and the package's public tests offline, in the VM's own language, then delete the copy. Building in `/srv/<INSTANCE>` would leave a `target/` folder in what the agent receives.
5. Check that the tests left no schema in the database, and clean what the JVM left in `/tmp`.

## 6. The client profile

**Each client runs at its factory default, with no equalization.** A client that has web search or fetch by default has it; one that does not, does not. The run is what a
person gets by installing the client, logging in, choosing the model and sending the message. So a result is reported as a run of the agent or product, not of the model alone.

The deviations, the same on every VM, keep the operator's own account and content out of the run, and the run's output out of the operator's account:

- Claude Code, in the runner's user settings: `"syncClaudeAiSkills": false` and `"disableClaudeAiConnectors": true`, so the account's synced skills and connectors are not
  loaded; and `"enableArtifact": false`, because the Artifact tool publishes pages to the logged-in account. On the first runs an agent used it to publish its report there.
- OpenCode: `share` set to `disabled` (sessions are never shared) and `autoupdate` off, in its `opencode.json`.
- The work folder (`/srv/<INSTANCE>`) is trusted beforehand in each client, so no trust dialog opens: a `projects` entry with `hasTrustDialogAccepted` in `~/.claude.json` for
  Claude Code, and `[projects."/srv/<INSTANCE>"]` with `trust_level = "trusted"` in Codex's `config.toml`.
- Tools are pre-approved and one permission mode is set before the first message and never changed. Fallback is on through the client's own setting; the conformity rules of
  task 1.2.0 apply. Codex's default sandbox (`workspace-write`) writes to the work folder but cannot connect to the local PostgreSQL, which the tests need (measured with
  `codex sandbox`), so either every escalation is approved by hand or the run starts with the bypass flag (`--dangerously-bypass-approvals-and-sandbox`; Claude Code's
  equivalent is `--dangerously-skip-permissions`). The VM is disposable. Record which one a run used.
- **Pin the model on the command line.** A default model can sit in one VM's client settings (on one reference VM both Claude Code and Codex had one, the others none), so a
  bare `claude` or `codex` would not start the same model everywhere. Pass the model every time and record the exact id.

**Clean state.** The runner's client folders hold credentials and configuration, and nothing from earlier sessions: no transcripts, sessions, history, memory or state
databases, and no skills or plugins. On the reference VMs that meant removing `~/.claude/{projects,sessions,backups}/*` and `~/.claude/history.jsonl`, and in `~/.codex` the
`sessions` and `shell_snapshots` folders, `history.jsonl` and the `memories`, `goals`, `queue`, `thread_history`, `logs` and `state` SQLite files, and for OpenCode
`~/.local/share/opencode/{opencode.db*,log/*,repos/*}` plus `prompt-history.jsonl` and `model.json` in `~/.local/state/opencode` (the last one remembers the last model and would
change the next default). Claude Code also keeps figures of earlier sessions per project in `~/.claude.json`; keep only the trust entry of the work folder. Client versions on
the reference VMs: Claude Code 2.1.285, Codex CLI 0.159.3 and OpenCode 1.18.33.

**Network.** It is not restricted: using the web is part of what is measured, and the package must not say otherwise. The one block is the GitHub layers of the README.

## 7. Snapshots

Take the clean snapshot at the end of preparation, before the first client session, with the VM powered off (`sudo systemctl poweroff`) and without the RAM state, so PostgreSQL
is shut down cleanly and the guest boots clean. It holds: the toolchain; the four items; `/srv/<INSTANCE>` untouched and owned by the runner; the database and the role; the
extracted cache; the client folders clean; no run folder. Restore it before every run, so a run never starts on the leftovers of another. A change to the package is a new package
hash: copy it again, repeat §5 and take a new snapshot. After a restore, check the clock (`timedatectl`).

A snapshot taken after even a short client session carries its history, a transcript and the trust entry it created. Take it before any client is opened, and check the state
after every restore (§8).

## 8. Check the VM

A read-only check says, with a count at the end, whether a VM is in the state of the clean snapshot: toolchain and services; the database and the offline cache in place; the
package untouched and owned by the runner; the GitHub block; the runner without sudo and unable to enter the admin's home; no client process and nothing left in `/tmp`; and the
client profile of §6. It changes nothing. Run it after a restore, and before taking a snapshot. If any line fails, do not run.

## 9. Running the agent

1. Restore the snapshot.
2. As the runner, `cd /srv/<INSTANCE>`, open the client with the model (and effort) chosen explicitly, and send the first message. The messages are in `/srv/prompt.md`, which only
   the admin reads.
3. At the end of the first stage, before the second message, keep the evidence ([`PROTOCOL.md` §3.1](../protocol/PROTOCOL.md), tool [`tools/etapas.py`](../tools/etapas.py)):

   ```bash
   cd ~/leb
   TX=$(find ~/.claude* ~/.codex -name '*.jsonl' 2>/dev/null)   # a restored VM holds only this run's transcripts
   python3 etapas.py checkpoint --entrega /srv/<INSTANCE> --transcript $TX --out ~/leb/runs/<model>-<n>/etapas \
     --requested-model <exact id> --fallback on --client "<client and version>" --cost-usd <so far> --cost-kind measured
   ```

- Pass `--transcript` **once**, followed by every path. Repeating it for each file keeps only the last one, and the command still exits 0.
- Before the second message, list what the checkpoint recorded; it must include the main session (`role` is `main`).
- To bring a run back, keep the folder structure of the transcripts (`cp --parents`): `etapas.py` tells a subagent from the main session by the `subagents/` folder in the path.
- **`etapas.py` reads the Claude Code transcript layout only.** OpenCode keeps its sessions in SQLite, with no `.jsonl`, and the tool fails with "no transcript found"; Codex writes
  `.jsonl` in another layout, and the verdict is expected to be inconclusive (not tested on a real file). For those clients keep the evidence by hand (a copy of the folder at the
  end of the first stage and the client's own export of the session); the conformity of the run is then inconclusive, which the protocol already allows.

## 10. What went wrong, and the fix

| What happened | Fix |
| --- | --- |
| In `pt_BR`, `sha256sum -c` prints `SUCESSO` and `FALHOU`, not `OK`, so a check that counts `: OK$` calls intact files corrupt | Run every `sha256sum -c` with `LC_ALL=C` |
| The frozen package is mode 400 and a plain copy keeps that | Normalize the modes at the copy (§4) |
| **The work folder was a read-only copy for the runner**, so the agents copied the package to `/tmp`, wrote their deliveries there, and the folder stayed untouched | The runner owns the work folder (§3) |
| The agent published its report to the logged-in account through the Artifact tool | `"enableArtifact": false` (§6) |
| `--transcript` repeated per file kept one transcript | Pass it once (§9) |
| Copying transcripts without their folders turned subagents into main sessions | `cp --parents` (§9) |
| The client loaded the account's skills and connectors | The settings of §6 |
| Memories, history and databases of earlier sessions were still in the runner's home | Remove them (§6) |
| The database is newer than the evaluator's (17 against 16) | Accept 16 or newer and tell the candidate which version grades it |
| A candidate-facing note said there was no outbound network | It was false and discouraged the behavior under test; the note now says what is true |
| PostgreSQL answers in the VM's language (`pt_BR`) | No code decides by message text; the text only reaches the agent as it is |
| One VM had a default model in the Claude Code and Codex settings, the others none | Pass the model on the command line (§6) |
| Codex's default sandbox blocked the connection to the local PostgreSQL | Approve each escalation by hand, or start with the bypass flag (§6) |
| A trust entry existed on one VM only, so the other two opened a trust dialog each run | Write the entry on every VM before the snapshot (§6) |
| OpenCode and Claude Code kept the last model and per-project figures outside the folders that were cleaned | Clean them too (§6) |
| A snapshot taken after a short client session brought back its history and a transcript | Snapshot before any client is opened, and check after each restore (§7, §8) |
| The environment check run inside the work folder leaves a `target/` there | Prove the environment on a throwaway copy (§5) |

## 11. Limits

- **Process-level isolation only.** The runner can read what its user can read and reach what the network lets it reach, apart from the GitHub layers. The protection is that
  the VM holds no answer key.
- **Versions drift.** The packages come from the distribution; record the versions of every run.
- **Copies elsewhere are not blocked**, as in the README.
- **The staged evidence works for one client.** Today `etapas.py` understands Claude Code only (§9).
- **This is one reference build.** The protocol does not require Proxmox, Debian or these sizes; it requires a machine restored to a clean state before each run.
