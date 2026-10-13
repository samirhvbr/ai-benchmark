# LEB-D-300-A — CondoDesk, Default track

> **Status: in authoring, not runnable.** The planted tree (`public/code/`), the verifiers and the matrix are written; the variant
> cases and the task statement of the track are not, so there is no run yet, and `./leb pacote` refuses the instance. Every file of this instance is committed here as it is
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
             verifiers), matrix_rows.py with matrix.json and matrix.md (the key); then the variant cases and the runner
             contract
```

`public/` is derived, never edited by hand. `private/build_public.py` plants the reference with `private/plant/`
(`tools/plant.py`), removes `.gitignore`, trims the one public test that `private/removed-from-public-tests.txt` names, and copies
the system's manifest:

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
