# LEB-D-300-A — CondoDesk, Default track

> **Status: in authoring, not runnable.** The planted tree (`public/code/`), the verifiers, the matrix, the variant cases and the
> task statement are written, and `./leb pacote` builds the package. What is left before a first run (the evaluator of the new
> series and a bench snapshot holding the package) is in [`systems/300-A/README.md §9`](../../systems/300-A/README.md#9-authoring-status). Every file of this instance is committed here as it is
> written, the answer key included, and the instance is complete before its first run
> ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1 and 2). The folder exists from the start so that its whole history is
> public.

The **Default** instance of system A of the next series ([`docs/new-series-design.md`](../../docs/new-series-design.md),
`PROPOSED`): everyday maintenance of CondoDesk, a condominium management application in Java 21 with Maven and PostgreSQL.

| | |
| --- | --- |
| Track | LEB Default: architecture, bugs, performance, code quality and compatibility; no planted vulnerability and no security work in the task |
| Level | 300 |
| Built from | [`systems/300-A/`](../../systems/300-A/): the clean reference, its characterization tests and its manifest |
| Planting profile | 27 maintenance flaws of [`taxonomy/`](../../taxonomy/) (ARCH 8, BUG 8, PERF 7, CLN 4) and 4 decoys, catalogued in [`private/CATALOG.md`](private/CATALOG.md) before any planting code |
| Sibling | [`LEB-S-300-A`](../LEB-S-300-A/), the Security track on the same system |

The reference itself is described as *no known vulnerabilities within the reviewed scope*: its defenses are checked by
`SecurityBehaviorTest`, which stays part of this instance's public tests, so a maintenance change must keep them.

## Before the first run

This instance cannot run yet. What it still needs, from its own files to the evaluator and the task statement of its track, is
kept in one place: [`systems/300-A/README.md §9`](../../systems/300-A/README.md#9-authoring-status).

## Layout

```text
public/      code/ (the legacy tree: the reference with this profile planted) and manifest.md (the system's)
private/     CATALOG.md, plant/ (the planting modules), build_public.py, removed-from-public-tests.txt, verify/ (the
             verifiers), matrix_rows.py with matrix.json and matrix.md (the key), variants/ (the control cases); then the
             runner contract
```

`public/` is derived, never edited by hand. `private/build_public.py` plants the reference with `private/plant/`
(`tools/plant.py`), removes `.gitignore`, trims the one public test that `private/removed-from-public-tests.txt` names, adds the
system's package files (`systems/300-A/package/`: `ENVIRONMENT.md`, `check-environment.sh`) and copies the system's manifest:

```sh
python3 instances/LEB-D-300-A/private/build_public.py            # rewrites public/
python3 instances/LEB-D-300-A/private/build_public.py --check    # a fresh build must equal public/, byte for byte
```

`tests/test_leb_d_300_a.py` runs the check. The public suite (70 tests) passes on `public/code/`, `SecurityBehaviorTest`
included.

## Verifiers

`private/verify/` holds one JUnit class per group of rows (`V01_RulesTest` … `V07_ResourcesTest`): each method writes the verdict
of its row, `full` or `none` for a Template C row, a ceiling `full`, `half` or `none` for a Template R row, and the decoys say
whether their property holds. The Java evaluator runs them on any delivery with the instance's own build, schema and public tests:

```sh
harness/java/dev-db.sh up
harness/java/evaluate.sh verify instances/LEB-D-300-A <the delivery's code folder> [selector]
```

On `public/code/` every row comes out not fixed and every decoy holds; on the reference, with a migration that adds the payment
index (a delivery may change the schema only through `migrations/`), every row comes out fixed and every decoy holds.

## The matrix

`private/matrix_rows.py` declares each row (severity, difficulty, template, an anchor in the planted file, the symptom, the
expected fix and the notes), and `tools/make_matrix.py` writes `matrix.json` and `matrix.md` from it: the location of each row is
the line of `public/code/` its anchor matches, and its verifier is the test method that writes its verdict. 27 flaws, 4 decoys,
204 base points (ARCH 70, BUG 59, PERF 47, CLN 28). `tests/test_make_matrix.py` checks that the committed matrix is what the rows
and the files give, so a change to the code or to a verifier that moves a row cannot leave the key behind.

```sh
python3 tools/make_matrix.py instances/LEB-D-300-A            # writes private/matrix.json and matrix.md
python3 tools/make_matrix.py instances/LEB-D-300-A --check    # the committed matrix is up to date
```

## Variant cases

A verifier that has only seen the planted tree and the reference has not shown that it can tell a fix from a near miss.
`private/variants/` holds 77 control cases, each a few exact edits on `public/code/` (or on the reference): fixes written another
way, which the verifier of their row must accept; partial and wrong fixes, which it must reject; and changes to the decoys, which
keep their property or break it. `tools/variants.py` builds each case, runs its row's verifier through the Java evaluator and
compares the verdict; with `--characterize` it also runs the public tests on every case that should pass, so an "alternative" that
breaks the contract does not count as one. `private/variants/results.tsv` is the last full run.

```sh
python3 tools/variants.py instances/LEB-D-300-A --parallel 4 --characterize   # every case; rewrites results.tsv
python3 tools/variants.py instances/LEB-D-300-A --only BUG-011                  # one row's cases
```

One case records a limit of a verifier rather than its strength, and says so in its note: a lock inside the JVM passes BUG-011's
probe, which runs one JVM (the matrix's notes send it to the judge). Another limit has no case, because its outcome depends on the
machine: PERF-012's coverage names the quadratic loop whose constant is too small to show at the sizes it measures (one that stops
at the first match gave a ratio of 4.8, under the limit of 8).

## The package

```sh
./leb pacote LEB-D-300-A      # runs/LEB-D-300-A/pacote/: code/, manifest.md, TASK.md
```

Everything in it is in English, the system's language: the code, the manifest, the environment notes in `code/`, and `TASK.md`,
rendered from [`protocol/tasks/TASK-D-1.0.0.md`](../../protocol/tasks/TASK-D-1.0.0.md) and bound to the matrix by its SHA-256. The
package is deterministic (55 files); the leak guard finds no text of the matrix in it. A run sends one message,
[`protocol/tasks/messages-D-1.0.0.json`](../../protocol/tasks/messages-D-1.0.0.json), through the lab's unattended driver.
