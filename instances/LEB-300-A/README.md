# LEB-300-A — NetX ISP · Tickets and Service Orders

The first **LEB-300** instance: a ticket and service-order system of an internet provider, written as legacy Java 21 with Maven
and PostgreSQL, with flaws planted from the [taxonomy](../../taxonomy/) and decoys among them. Its contract with the systems
that depend on it is `public/manifest.md`.

| | |
| --- | --- |
| Version | **v1.0** · spec **1.4.0** · task **1.2.0** (two stages, [`protocol/tasks/TAREFA-1.2.0.md`](../../protocol/tasks/TAREFA-1.2.0.md)) |
| Level | LEB-300 |
| Stack | Java 21 · Maven · PostgreSQL |
| Matrix | 41 rows: **36** planted flaws (SEC 9 · ARCH 8 · BUG 8 · PERF 7 · CLN 4) and **5** decoys |
| Matrix (SHA-256) | `c42c82878d2cf3fe081d7f15e633d043c27701ea98c8f179f1527ac86f543181`, the hash every LEB-300-A result cites |
| Published runs | mode **A**, 60 turns; the package they received is `7e35825a…` (the `package_sha256` of [`results/2026/LEB-300-A/aggregate.json`](../../results/2026/LEB-300-A/aggregate.json)); see *Two hashes* below |
| Status | **active**: its runs are published as a per-agent aggregate only ([`MATRIX §4`](../../matrix/MATRIX.md), item 7) |

## Layout (split)

```text
public/                    ← what the agent receives, inside the package
  code/                       the legacy system (Maven project)
  manifest.md                 the public surface: what a fix must not break
private/                   ← the key and the evaluator's material; never in the package
  matrix.json · matrix.md     the Official Matrix and its readable version
  CATALOG.md                  the authoring catalogue of what was planted and where
  runner.json                 the per-instance runner contract (SPEC §10, release notes 1.4.0)
  ambiente/                   the characterization and verification commands the runner calls
  build/legado/               the legacy tree the matrix's locations are anchored in
  verify/                     the probes that check each fix
  plant/ · gerar_matriz.py    how the flaws were planted, and how matrix.json and matrix.md are generated from the legacy tree
  reference/                  the clean reference implementation the flaws were planted into
  variantes/                  the variant cases each verifier is checked against
  removed-from-public-tests.txt  the tests moved out of the public characterization suite, because they describe the visible
                              symptom of a planted flaw
```

The harness resolves this split layout from the repository like any other instance (`harness/instances.py`).

## Two hashes, one key

The package names the matrix it will be graded against, inside `TAREFA.md`. The runs received the package of the pilot's second
freeze, `7e35825aa91d0eaeae01f86a12f75698ce3276fa5ea6b969ed58f282f61e5ef7`, whose `TAREFA.md` names the matrix as
`3331a107db50c78fc2dcf6cd61559e9a65e5fe2ae14ddac622fd2cf013e483bb`. The third freeze, on 2026-10-08 (15:37 UTC), changed one
thing: it added `"publication": "aggregate"` to the matrix header. That gives the matrix here, `c42c8287…3181`, which the results
cite, and the package `./leb pacote LEB-300-A --mode A --turnos 60` builds today, `910bc0ae1c3708df99aad67af522b6e95d83704acbcf04faa26b7208f3ecc821`,
which differs from the runs' package only in the two lines of `TAREFA.md` that carry the matrix hash.

To check that nothing else in the key changed, drop that header key and write the matrix back the way `private/gerar_matriz.py`
writes it; the result is the hash the runs' package names:

```sh
python3 -c 'import json,hashlib; m=json.load(open("instances/LEB-300-A/private/matrix.json")); m.pop("publication"); print(hashlib.sha256((json.dumps(m, ensure_ascii=False, indent=2) + "\n").encode()).hexdigest())'
# 3331a107db50c78fc2dcf6cd61559e9a65e5fe2ae14ddac622fd2cf013e483bb
```

## Publication

This instance and its key were committed here on 2026-10-12, by the owner's order, after its runs had started; the rule that an
instance and its key are public **before** the first run ([`MATRIX §4`](../../matrix/MATRIX.md), items 1 and 2) came with them.
What that means for its results (item 8): those published after 0.2.128 (2026-10-08) are covered by the public hash above, which
the key here matches; the runs before that commit are covered only by the operator's private freeze record. A model trained
after 2026-10-12 may have seen the key: every run records its model's training cutoff.

Not published: the operator's validation folder (`private/validacao/`), which records the operator's own machine, and build
outputs (`target/`, compiled classes, Python caches).
