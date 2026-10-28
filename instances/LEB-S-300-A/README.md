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
| Variant (control) cases | done, 2026-10-25: 69, all with the expected verdict (35 fixes written another way accepted, 19 partial and 8 wrong fixes rejected, 7 changes to decoys) |
| Task statement, message and runner contract | done, 2026-10-25: [`TASK-S-1.0.0.md`](../../protocol/tasks/TASK-S-1.0.0.md), [`messages-S-1.0.0.json`](../../protocol/tasks/messages-S-1.0.0.json), `private/runner.json` |
| Package | builds clean: `./leb pacote LEB-S-300-A` gives 56 files and the leak guard passes |
| The owner's review of the catalog | **open**: five points are still proposals (the one class kept only as a decoy, the weights, the routes of the first case, five accepted borderline patterns of the reference, and the name of the findings file in the run's message) |
| External review of the key | **open**: before the freeze, as version 1.1 of LEB-D-300-A had |
| Proof on a bench VM | **open**: [`docs/candidate-vm.md`](../../docs/candidate-vm.md) §5, step 4 |
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

The hashes `./leb pacote LEB-S-300-A` gives today are not frozen and change with any edit of the instance: the matrix `3cd514d72cbcf9116d06b95605d7da30890584a60175d462cc62e22bec1df01d` and the package
`2e6af14d8544193f513a1dc99f15c824ee2715fc977eb0d3fbded5d9af7d663a` (56 files, mode A, 60 turns).

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
