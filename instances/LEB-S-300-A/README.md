# LEB-S-300-A — CondoDesk, Security track

> **Status: built, not frozen, no run yet.** The instance is complete as authored: its planting profile (24 vulnerabilities and 6 decoys), the answer key, the exploit
> verifiers, the control cases, the task statement and the package. It is not frozen, and what stands before its first run is listed under [Before the first run](#before-the-first-run),
> in the order it is done. Every file of this instance is committed here as it is written, the answer key included
> ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1 and 2), so that its whole history is public.

The **Security** instance of system A of the next series ([`docs/new-series-design.md`](../../docs/new-series-design.md),
`PROPOSED`): finding and fixing vulnerabilities in CondoDesk, a condominium management application in Java 21 with Maven and
PostgreSQL, without breaking it.

| | |
| --- | --- |
| Track | LEB Security: a fix counts only when the reference attack and its variations no longer work, legitimate users keep the function, and the manifest's contracts hold |
| Level | 300 |
| Built from | [`systems/300-A/`](../../systems/300-A/): the clean reference, its characterization tests and its manifest |
| Planting profile | 24 vulnerabilities of [`taxonomy/SEC.md`](../../taxonomy/SEC.md) in 19 of its 20 classes (Crítica 9, Alta 10, Média 5) and 6 decoys, catalogued in [`private/CATALOG.md`](private/CATALOG.md) before any planting code; SEC-007 is not plantable on this stack (the catalog says why) and stays a decoy |
| Weights | SEC 850 · COMP 100 · EXPL 50 ([`scoring/tracks/security.json`](../../scoring/tracks/security.json)), a draft until the owner confirms them |
| Sibling | [`LEB-D-300-A`](../LEB-D-300-A/), the Default track on the same system |

The exploit checks are the evaluator's: the reference's `SecurityBehaviorTest` is where they start, and it does not travel with
this instance's package.

## Where it stands

| Step | State |
| --- | --- |
| Catalog of the profile | done, 2026-10-25 (four points decided by the owner that day; five were still proposals) |
| Planting: 24 vulnerabilities and the decoy that needs code | done, 2026-10-25; each row proved both ways (not fixed on the planted tree, fixed on the clean tree) |
| Matrix and exploit verifiers | done, 2026-10-25: 200 base points (Crítica 9 × 10, Alta 10 × 8, Média 5 × 6); `tools/make_matrix.py --check` passes |
| Variant (control) cases | 90 (69 of 2026-10-25, 20 from the two reviews and 1 from the rehearsal, `private/variants/cases_g_review.py`), run as a whole on 2026-10-29: 0 unexpected; every row is proved both ways again (planted tree: 24 not fixed; clean tree: 24 fixed; the 6 decoys hold on both) |
| Task statement, message and runner contract | done, 2026-10-25: [`TASK-S-1.0.0.md`](../../protocol/tasks/TASK-S-1.0.0.md), [`messages-S-1.0.0.json`](../../protocol/tasks/messages-S-1.0.0.json), `private/runner.json`; the runner passed no selector for its verifiers and an evaluation ran none of them, fixed on 2026-10-29 (0.2.280) and now covered by a test |
| Package | builds clean: `./leb pacote LEB-S-300-A` gives 56 files and the leak guard passes |
| The owner's review of the catalog | **open**: five points are still proposals (the one class kept only as a decoy, the weights, the routes of the first case, five accepted borderline patterns of the reference, and the name of the findings file in the run's message) |
| Review of the key | done on 2026-10-29 by two independent reviewers (the owner: two internal reviews suffice); every finding is fixed in 0.2.280 to 0.2.285 or recorded as a limit under [Review of 2026-10-29](#review-of-2026-10-29), each reproduced as a control case first |
| Proof on a bench VM | done on BENCH4 on 2026-10-29 for the 0.2.279 package (57 files with its hash list, `check-environment.sh` ready, 51 public tests offline, no schema left, `/tmp` clean) by `operador/provar-pacote.sh`; **to be repeated on the package that is frozen**, because the public tree changed since |
| Freeze | **open**: it records the hashes and is committed and pushed before any run |

This table is updated in the commit of each step.

## Before the first run

In this order, because a review can change the key and the hashes are recorded after its last change:

1. **The owner's review of the catalog.** What it can change: a row (replant, verifier, control cases and the matrix follow), the weights, the wording of the run's message.
2. **The external review of the key.** LEB-D-300-A froze its version 1.0 first and made 1.1 after the review ([its README](../LEB-D-300-A/README.md#versions)); this instance takes
   the review first, so that its first version is the one the evaluation cites.
3. **The proof on a bench VM**, on a throwaway copy of the package: the package's files match their hash list, `check-environment.sh` says the environment is ready, and the public
   suite passes offline in the VM's own language. The bench snapshot of the environment (`leb-d-300-a`) holds the same toolchain, test database and offline Maven cache, because the system is the same.
4. **The freeze.** The matrix and package hashes below are replaced by the frozen ones, the commit is pushed, and from then on any change is a new version of the instance.

The hashes `./leb pacote LEB-S-300-A` gives today are not frozen and change with any edit of the instance: the matrix `a563e8b4cdcf03c84e7f64f8e2e5d02879406383e0bc55e61f55e38ae422ed8a` and the package
`566bb6798e1a0f76bef0ba7ec7ad166de82debebf44ec720249a62131ae8b45c` (56 files, mode A, 60 turns; at 0.2.286).

## Review of 2026-10-29

Two independent reviewers (read-only, one for each half of the verifiers and the package) read the key at 0.2.279 with the brief of an external review. Both found the same critical defect, so each finding was
reproduced as a control case before any verifier changed. Fixed in 0.2.280 to 0.2.285: the runner's missing selector; the account-creation and manual-payment rows (checks scoped to one role passed); the page-script row (decides only
what it can read, the rest is for the judges); the search-term, spreadsheet and upload rows; the stored-hash, internal-error and bank-secret rows; the database-password row (now judged by running the application); the
remote-fetch row (it counts what the internal site received, not what was stored); the notice-search row (it no longer reads a line another row's fix may reword); the refusals of preparatory steps (they end as a refusal, not as no
verdict); the planting of the upload row (it no longer widens the stored extension, so it does not share a fix with the archive row); the public security test's class comment (it named the taxonomy and the sibling track); and
the catalog's descriptions that disagreed with the code. The whole control set, 89 cases, ran after the last change with no unexpected verdict.

Limits kept on purpose, so that a score is read with them:

- **Archive label.** The row proves that no label runs a command and that a plain label still names the archive. A delivery that replaces the shell by an argument vector and leaves the manifest's label rule unchecked is accepted,
  because the effect of a label such as `../x` on where the archive is written is not probed.
- **Remote fetch and loopback.** The approved site of an evaluation sits on the loopback address, so a delivery that also refuses loopback and private addresses refuses the approved site and fails the row; the manifest keeps
  the fetch from an allowed host.
- **What the package still shows of the planting.** Unused constants (`TYPES`, `LABEL`) and comments that describe the reference (the CSV writer's, the document service's) stay, as legacy code would have them; the two security
  tests that remain keep their names, because the matrix's regression groups are bound to them.
- **The judges read what the verifiers cannot decide.** The page-script row is inconclusive for any script that is not the weakness itself or plainly closed.

## Rehearsal of 2026-10-29

One delivery was produced on BENCH4 before the freeze, to run the whole path once: the lab's driver, OpenCode 1.18.33 with DeepSeek V4.1 Flash at `high` through DeepSeek's own API (US$ 0.07 recorded by the client, 26
minutes, one message, 53 assistant messages, no operator interruption), the audit, the collection, and the mechanical stage through the instance's runner. **It is not a run of the instance: it counts for nothing, it is
not published and it does not enter any aggregate**, because the package was not frozen. Its evidence is in the private repository's `piloto-s-1/ensaio-deepseek-flash-high/` (not committed).

What it showed: the path works end to end; the delivery changes 19 files and the public suite passes (51 of 51, no regression); every row is fixed (24 of 24) and the 6 decoys hold. It also found one fault of the key: the
database-password verifier crashed on a delivery whose default password is the empty string (no secret, the program just sends an empty one), so that row ended with no mechanical verdict. The verifier now treats an empty
password as no secret, with a control case for it (0.2.286). The change comes from a crash, not from a score, and the rehearsal's delivery is not used again. A perfect mechanical result for a small, cheap model says the
rows are fixable by the reference's own fixes; it does not say the verifiers are lenient, since the 89 control cases reject the partial and wrong fixes, but the first real cases will show whether the track separates models.

Two weaknesses of the reference that are not planted are recorded for the next version: the old viewer's route lets any user of a building read any stored file of it, and an imported account answers a wrong password faster than a missing one.

## Layout

```text
public/      code/ (the legacy tree: the reference with this profile planted) and manifest.md (the system's, with the profile's route)
private/     CATALOG.md, plant/ (the planting modules), build_public.py, removed-from-public-tests.txt, verify/ (the exploit
             verifiers, S01 to S08), matrix_rows.py with matrix.json and matrix.md (the key), regression_groups.json, variants/
             (the control cases), and runner.json (how the harness evaluates a delivery)
```

`public/` is derived, never edited by hand. `private/build_public.py` plants the reference with `private/plant/` and removes the public tests the planting makes fail
(`private/removed-from-public-tests.txt`). The clean tree the verifiers must accept in their passing direction is the reference plus two corrections that belong to this instance only (CORR-S1,
the 600,000-iteration PBKDF2, and CORR-S2, the old viewer's route with its containment check); neither touches `systems/300-A/reference/`, because LEB-D-300-A is frozen on it:

```sh
python3 instances/LEB-S-300-A/private/build_public.py                  # rewrites public/
python3 instances/LEB-S-300-A/private/build_public.py --check          # a fresh build must equal public/, byte for byte
python3 instances/LEB-S-300-A/private/build_public.py --clean <dir>    # the clean tree, for the passing direction
```

## The matrix and the control cases

```sh
python3 tools/make_matrix.py instances/LEB-S-300-A --check            # the committed matrix is what the rows and files give
python3 tools/variants.py instances/LEB-S-300-A --parallel 4           # every control case; rewrites private/variants/results.tsv
```

`tests/test_leb_s_300_a.py` checks that the public tree and the matrix are what the profile gives. The control cases are described in the catalog's section 9b.

## The package

```sh
./leb pacote LEB-S-300-A --mode A --turnos 60     # runs/LEB-S-300-A/pacote/: code/, manifest.md, TASK.md
```

Everything in it is in English, the system's language, and `TASK.md` is rendered from [`protocol/tasks/TASK-S-1.0.0.md`](../../protocol/tasks/TASK-S-1.0.0.md) and bound to the matrix by its SHA-256.
A run sends one message, [`protocol/tasks/messages-S-1.0.0.json`](../../protocol/tasks/messages-S-1.0.0.json), through the lab's unattended driver.
