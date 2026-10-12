# Reference systems

The next LEB series ([`docs/new-series-design.md`](../docs/new-series-design.md), `PROPOSED`) measures two independent tracks,
**LEB Default** and **LEB Security**, on several **reference systems**. A reference system is a working application built
clean: its code, its manifest (the public contract), its characterization tests and its environment. Two planting profiles of
that same clean code produce its two instances:

| Instance | What is planted | Lives in |
| --- | --- | --- |
| `LEB-D-<level>-<letter>` | maintenance flaws: architecture, bugs, performance, code quality; no vulnerability | `instances/LEB-D-<level>-<letter>/` |
| `LEB-S-<level>-<letter>` | vulnerabilities from [`taxonomy/SEC.md`](../taxonomy/SEC.md); no maintenance flaw | `instances/LEB-S-<level>-<letter>/` |

The same letter is the same system, and both instance folders exist from the day the system is specified, so that every
step of their authoring is in the history. Everything here is public from the first commit, and each instance, answer key included,
is committed before its first run ([`matrix/MATRIX.md §4`](../matrix/MATRIX.md), items 1 and 2).

| System | Domain | Stack | Status |
| --- | --- | --- | --- |
| [`300-A`](300-A/) | Condominium management (CondoDesk) | Java 21 · Maven · PostgreSQL | reference, 70 tests and manifest written; planting profiles next |
