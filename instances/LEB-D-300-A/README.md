# LEB-D-300-A — CondoDesk, Default track

> **Status: version 1.1, first case done.** Version 1.0 was frozen on 2026-10-14; version 1.1 revised the verifiers on 2026-10-15,
> before any delivery went through them (see [Versions](#versions)); the first case's aggregate is published (see [Results](#results)). Every file of this instance is committed here as it is
> written, the answer key included ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1 and 2), so that its whole history is
> public. What the instance needed to run, from its files to the evaluator and the bench snapshot, is ticked in
> [`systems/300-A/README.md §9`](../../systems/300-A/README.md#9-authoring-status).

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

## Versions

| Version | Date | `matrix_sha256` | What it is |
| --- | --- | --- | --- |
| 1.0 | 2026-10-14 | `45c09ae1fce8f91cef5f7070db5a74d957d787255f02094a8adf39a89fd294b9` | Frozen at commit `73eb0f8` (0.2.215), with `package_sha256` `71dfeb7c3f82c75a96f3134e4e050b4476436116e14402d483e9c5f5510955e4` |
| 1.1 | 2026-10-15 | `9bd0c169e420927d8e0aadd87f30789b4cf6e60a2db49999c450c7f86f2207ce` | The verifiers hardened; the package differs from 1.0 in four lines of `TASK.md` (below), `package_sha256` `3370984d863313e524b7e976eca416d043489fa8f67858e70dabd5998f4dba4e` |

An external review of the key, received before the first run, found places where a verifier judged a delivery by something the
manifest does not contract, or accepted less than it claimed. Its tooling findings were fixed in 0.2.216 to 0.2.220 without touching
the key. Version 1.1 is the rest, made **after the first deliveries were produced and before any of them went through the
verifiers**: the mechanical stage had not been run on any delivery when the key was changed, and each change below comes from the review and
from a control case, not from a delivery's score.

- **Markup.** `V03` and `V06` read the pages' HTML bytes (`<td><a href="/invoices/N">`, a dashboard row of `<td>` cells, a user-list
  row). The manifest says what a page shows, not its tags, and the public tests pin only a few (the notice article's id and class, a
  row of the monthly totals, a booking row), none of these. A delivery that restyled a table, or split the 80-line
  `FinancePages.list` (CLN-001) and rebuilt its rows, could have been judged on its markup. They now read where an invoice link points
  (any quotes, any attributes) and the text of a dashboard row or a user-list row.
- **PERF-010.** The dashboard of the admin is checked after a billing run (0 → 3) and now also after the manager registers the full
  payment of one of the invoices (3 → 2). A cache invalidated only by billing passed the first and fails the second.
- **BUG-015.** The log record must carry the missing file's exception itself, or as a cause somewhere down the chain of the exception
  that was logged. Any non-null exception used to pass, including a wrapper that dropped the cause.
- **BUG-013.b.** The catalog's proof that the 12th-decimal rounding is harmless argued the floors only. It now also covers the order of
  the leftover cents, and states its bound (the fractions of a building add up to about 1, the proof holds far beyond that).
- **Controls.** Six new cases in `private/variants/` (83 in all): the PERF-003 fix with restyled invoice links, a PERF-010 cache that
  follows billing only and one that follows the open invoices, a BUG-015 wrapper with and without its cause, and the ARCH-014 decoy
  with restyled invoice rows. Each was run against the verifiers of 1.0 as well, to see that the change is what tells it apart.
- **Left as they were.** ARCH-003 and ARCH-009 (an existing control already accepts named constants, and their mechanical result is a
  ceiling, so only a false rejection would matter); the extras the reference code brings (billing reference widths, the OFX root),
  which are known extras for the judges.

The package of 1.1 is the package of 1.0 with the instance version and the matrix hash rewritten in `TASK.md` (each appears twice, four lines
in all; the other 54 files are byte for byte the same): the planted tree, the manifest, the public tests and the task statement are the same, so a delivery produced on the 1.0 package is a delivery of 1.1's task and is
evaluated with 1.1's key.

## Results

While the instance is active only its aggregate is published ([`results/2026/LEB-D-300-A/aggregate.json`](../../results/2026/LEB-D-300-A/aggregate.json), and the
section of [`results/README.md`](../../results/README.md)): no per-flaw result, no verdict, no delivery. The first case is Claude Haiku 5.5 at effort `high`: five runs of 90
minutes at most, on the 1.0 package and evaluated with the 1.1 key, scored 524, 533, 580, 561 and 536 (mean 547, 95% interval 530 to 565, all Bronze), at about US$ 1.13 and 27
minutes a run. The interval comes from one system only, so it reflects the variation among that system's runs.

The next three cases ran on the package of version 1.1 (the task statement is the same, and the package differs from 1.0's only in the four lines that name the version and the key) and were evaluated with the same key,
five runs of 90 minutes at most each: Claude Sonnet 5.5 at effort `xhigh` scored 785, 752, 772, 836 and 781 (mean 785, 95% interval 764 to 812, all Gold, at about US$ 5.78 and 35 minutes a run; an attempt lost when the
operator stopped its machine was rerun and counts as lost); Claude Sonnet 5.5 at `high` scored 707, 734, 702, 694 and 730 (mean 713, interval 700 to 728, all Silver, about US$ 2.29 and 20 minutes); Claude Opus 5.5 at `high`
scored 716, 812, 625, 712 and 793 (mean 732, interval 677 to 789, two Gold and three Silver, about US$ 3.97 and 22 minutes). Every delivery was judged blind by two judges and a person settled the disagreements; the folders
the judges read carry no name of a model, an effort or a vendor.

Three Codex CLI cases are published as interim aggregates, three of their five runs each, on the package of version 1.1 and with the same key: GPT-6-astra at `xhigh` scored 672, 739 and 706 (mean 706, Silver), GPT-6.1-sol at
`xhigh` scored 685, 718 and 695 (mean 699, Silver) and GPT-5.6-terra at `xhigh` scored 418, 384 and 457 (mean 420, Bronze); two runs of each are still to be made. They ran with the client's approvals and sandbox bypassed,
the one exception the series makes to its rule against that (design section 6.1), recorded in every attempt and shown as the `permissions bypassed` mode of the model on the sites; the client's log keeps tokens and no
cost, so their cost is in tokens.

## Known limits of version 1.1

The external review that led to version 1.1 had 27 findings. Version 1.1 and the tooling versions before it dealt with the ones that could judge a delivery by something the manifest does not contract
or accept less than it claimed. The rest are verifier limits that the owner accepted as limits of this version on 2026-10-15; each is read by the judges, who take the mechanical result of an
architecture or clean-code row as a ceiling, and none changes without a new version of the instance (`matrix/MATRIX.md` section 4). They are kept here so that a score is read with them, and so that
version 1.2, which is for cases run after it and does not re-score the first case, starts from the list.

- **What the structure measures.** The measures read the main code under `com.condodesk` only, so a different root is not seen; a nested class counts as its outer class; a transactional helper used by
  two features counts as a responsibility they share; the second implementation of a rule counts when it is in another class, not when it is in another method of the same one; "every class is
  referenced by another" does not tell a used class from two dead ones that cite each other; two method bodies are equal when their types, names and comments are erased, which can flag a pair that
  differ only in a type and miss a copy with a changed comment; and a block of code turned into a Javadoc comment is not read as commented-out code.
- **What the performance checks measure.** The delinquency report is measured after an unmeasured request, so a cache could hide a query per owner; the second page of the invoice list is checked for
  20 distinct invoices, not for being the continuation of the first; the payment index is checked for its leading column, not for being a valid index over every row; the processor time of the user
  list is sampled from the threads alive before and after the request; the wait of a second webhook is correlated to any thread waiting, not to the one holding the lock; and the instrumentation that
  recognizes a booking's insert depends on how the SQL is written.
- **Constants in ARCH-003 and ARCH-009** (left as they were in version 1.1, see Versions): extracting the limits to a class makes the SQL and the numbers appear in different classes even when they are used together.

## Layout

```text
public/      code/ (the legacy tree: the reference with this profile planted) and manifest.md (the system's)
private/     CATALOG.md, plant/ (the planting modules), build_public.py, removed-from-public-tests.txt, verify/ (the
             verifiers), matrix_rows.py with matrix.json and matrix.md (the key), variants/ (the control cases), and
             runner.json (how the harness evaluates a delivery)
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
`private/variants/` holds 83 control cases, each a few exact edits on `public/code/` (or on the reference): fixes written another
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

## Evaluating a delivery

`private/runner.json` points the harness's two steps at the Java evaluator (`harness/java/evaluate.sh`), so the mechanical part of
an evaluation runs end to end:

```sh
harness/java/dev-db.sh up
python3 harness/leb_harness.py --instance LEB-D-300-A --submission <the delivery's code/> --out mechanical.json
```

About two and a half minutes: the public suite on the planted tree (the baseline) and on the delivery, then the verifiers. On the
planted tree itself, 0 of 27 rows are fixed; on the reference with the payment index migration, 27 of 27; on a delivery that does
not compile, all 70 public tests fail (a regression), no row is fixed and every decoy breaks. A Maven run that fails for any other
reason (Docker, the database, the offline cache) is not the delivery's verdict: the evaluation ends INCONCLUSIVE (exit status 3).

The judge's steps and the score are the new series' evaluator, described in
[`docs/new-series-evaluator.md`](../../docs/new-series-evaluator.md): two blind judges per delivery, a person settles every disagreement
(`tools/judge_review.py`), and `./leb scorecard LEB-D-300-A` scores the settled verdict (`harness/score_v2.py`, track `default`).
