# LEB-D-300-A — CondoDesk, Default track

> **Status: in authoring.** No package, no key and no run yet. Every file of this instance is committed here as it is written, the
> answer key included, and the instance is complete before its first run ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1
> and 2). The folder exists from the start so that its whole history is public.

The **Default** instance of system A of the next series ([`docs/new-series-design.md`](../../docs/new-series-design.md),
`PROPOSED`): everyday maintenance of CondoDesk, a condominium management application in Java 21 with Maven and PostgreSQL.

| | |
| --- | --- |
| Track | LEB Default: architecture, bugs, performance, code quality and compatibility; no planted vulnerability and no security work in the task |
| Level | 300 |
| Built from | [`systems/300-A/`](../../systems/300-A/): the clean reference, its characterization tests and its manifest |
| Planting profile | maintenance flaws of [`taxonomy/`](../../taxonomy/) (ARCH, BUG, PERF, CLN), with decoys; the catalogue comes first, in `private/` |
| Sibling | [`LEB-S-300-A`](../LEB-S-300-A/), the Security track on the same system |

The reference itself is described as *no known vulnerabilities within the reviewed scope*: its defenses are checked by
`SecurityBehaviorTest`, which stays part of this instance's public tests, so a maintenance change must keep them.

## Layout, once built

```text
public/      code/ (the legacy tree: the reference with this profile planted) and manifest.md (the system's)
private/     the planting profile, the matrix, the verifiers, the variant cases and the runner contract
```
