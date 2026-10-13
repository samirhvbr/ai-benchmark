# LEB-S-300-A — CondoDesk, Security track

> **Status: in authoring.** No package, no key and no run yet. Every file of this instance is committed here as it is written, the
> answer key included, and the instance is complete before its first run ([`matrix/MATRIX.md §4`](../../matrix/MATRIX.md), items 1
> and 2). The folder exists from the start so that its whole history is public.

The **Security** instance of system A of the next series ([`docs/new-series-design.md`](../../docs/new-series-design.md),
`PROPOSED`): finding and fixing vulnerabilities in CondoDesk, a condominium management application in Java 21 with Maven and
PostgreSQL, without breaking it.

| | |
| --- | --- |
| Track | LEB Security: a fix counts only when the reference attack and its variations no longer work, legitimate users keep the function, and the manifest's contracts hold |
| Level | 300 |
| Built from | [`systems/300-A/`](../../systems/300-A/): the clean reference, its characterization tests and its manifest |
| Planting profile | vulnerabilities of [`taxonomy/SEC.md`](../../taxonomy/SEC.md), spread over its classes and difficulties, with decoys; [`systems/300-A/README.md §5`](../../systems/300-A/README.md) lists where each class meets the reference |
| Sibling | [`LEB-D-300-A`](../LEB-D-300-A/), the Default track on the same system |

The exploit checks are the evaluator's: the reference's `SecurityBehaviorTest` is where they start, and it does not travel with
this instance's package.

## Before the first run

This instance cannot run yet. What it still needs, from its own files to the evaluator and the task statement of its track, is
kept in one place: [`systems/300-A/README.md §9`](../../systems/300-A/README.md#9-authoring-status).

## Layout, once built

```text
public/      code/ (the legacy tree: the reference with this profile planted) and manifest.md (the system's)
private/     the planting profile, the matrix, the exploit verifiers, the variant cases and the runner contract
```
