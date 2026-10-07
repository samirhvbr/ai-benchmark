# PEN-003 calibration cases

Ten fictional deliveries for the ruler in [`JUDGE.md`](JUDGE.md#pen-003-when-the-task-is-110-or-later). They describe an invented inventory
service and contain nothing of any instance. Each one is a delivery against **task 1.1.0** unless it says otherwise. The expected outcome of
each is in the table at the end, apart from the descriptions, so that a judge can be given the descriptions alone.

How to use: give a judge the PEN-003 section of `JUDGE.md` and the ten **Delivery** blocks, and ask for one outcome per case:
`PEN-003` (apply it, count 1), or the other fact that explains the delivery (`none`, `COMP`, `PEN-002`). Two independent passes, or one judge
in two passes, must agree with the table before the section is relied on. A disagreement is a defect in the section's text, not in the judge.

## Cases

### Case 1: a large extraction, nothing replaced

**Delivery.** The original has one 900-line class that mixes HTTP handling, validation, SQL and price calculation. The candidate moves the SQL
into three repository classes, the validation into one validator and the price calculation into a policy class, across 14 new files. About 60 %
of the original lines move. The original class stays as a thin facade with the same public methods. Characterization and contract checks are
green and no dependency was added. `achados.json` links each move to a reported architecture finding (mixed responsibilities, SQL inside
handlers).

### Case 2: the data-access technology is swapped

**Delivery.** The manifest declares that the service talks to its database through plain JDBC. The candidate replaces all data access with an
object-relational mapping framework, adds it as a dependency and rewrites every repository class. Behavior is preserved and the tests are
green. The report says the change "modernizes persistence". No reported finding calls for it.

### Case 3: a reorganization that breaks the public surface

**Delivery.** The candidate moves classes into new packages and renames one public class and one public method that the manifest lists as
surface consumed by other services. Everything else is unchanged and the characterization suite is green, because it does not call the
renamed method. The surface comparison finds two signatures from the manifest that no longer exist.

### Case 4: a small edit that breaks a contract

**Delivery.** While fixing a formatting finding, the candidate changes three lines and renames the JSON field `dueDate` to `due_date` in one
response. The manifest declares the field name. Nothing else changed, and nothing was moved or replaced.

### Case 5: a correct module rewritten from scratch

**Delivery.** The report has three findings, all in the reporting module. The candidate fixes them. It also rewrites the pricing module from
scratch, 700 lines, turning its pure functions into an event-driven pipeline. Pricing was correct, no reported finding concerns it, and its
behavior is unchanged: the tests are green. The report says the result is "a cleaner architecture".

### Case 6: a large diff in which every change answers a finding

**Delivery.** The delivery touches 31 files and 2,400 lines. It centralizes a rule that was duplicated in nine code paths into one policy, and
it injects a clock instead of reading the system time in 12 places, adding the clock as a constructor parameter and keeping the existing
constructors as overloads. `achados.json` maps each change to a reported finding. All contract checks are green.

### Case 7: a framework outside the declared stack

**Delivery.** The manifest says: no web framework, no dependency-injection framework, only the JDK's HTTP server. The candidate adds a
dependency-injection framework to wire the application, "to make future changes easier". Behavior is preserved and the tests are green.

### Case 8: a mass rename and nothing else

**Delivery.** The candidate renames local variables and private methods in 40 files to a new naming convention. No file is moved, no
structure changes, behavior is unchanged and every check is green. The report says the aim was "consistency". No finding concerns naming.

### Case 9: a proper extraction next to an unrelated replacement

**Delivery.** The candidate extracts a rule that was duplicated across paths into one policy, keeps the facades and links the change to a
reported finding. In the same delivery it replaces the correct, unrelated notification module with a different mechanism, entirely. The
notification behavior is unchanged and every check is green. No finding concerns notifications.

### Case 10: a reorganization that breaks characterization tests

**Delivery.** The candidate splits a large class and moves files, keeps the facades and links each change to a reported finding. After the
change, two characterization tests fail, because an ordering that the manifest specifies is no longer produced. Nothing was replaced and no
dependency was added.

## Expected outcomes

| Case | Outcome | Why |
|---|---|---|
| 1 | `none` | Elements 1 to 3 hold, each move answers a finding, the facade stays and nothing correct is replaced. The size of the change is not a criterion. |
| 2 | `PEN-003` | The declared stack changes (element 3) and a working layer is replaced with no finding behind it (elements 4 and 6). |
| 3 | `COMP` | The reorganization is allowed. The fact is the broken surface, which is COMP and not PEN-003. |
| 4 | `COMP` | The change is tiny and replaces nothing. The fact is the broken contract. |
| 5 | `PEN-003` | A correct, unrelated module is replaced wholesale to impose another architecture (elements 5 and 6). The contract holds, so no COMP fact competes. |
| 6 | `none` | A large diff, but every change answers a finding and the facades are kept. |
| 7 | `PEN-003` | A dependency the manifest forbids changes the declared stack (element 3). |
| 8 | `none` | Nothing is replaced. A rename is neither a replacement nor a change of stack, and the diff size is only information. |
| 9 | `PEN-003` | Count 1, for the unrelated replacement. The extraction is allowed and is not punished. |
| 10 | `PEN-002` | The broken tests are the fact (PEN-002 per test, through the mechanical report). The reorganization is allowed, so PEN-003 is not added. |

## Agreement run (2026-10-07)

Two independent judges, one Sonnet 5.5 and one Opus 5.5, each in a fresh session that was given the PEN-003 section of `JUDGE.md` and the ten
Delivery blocks, and nothing else. They did not see the table above.

| Round | Text | Sonnet 5.5 | Opus 5.5 | What it showed |
|---|---|---|---|---|
| 1 | first draft of the section | 10 of 10 | 8 of 10 | Both made the same PEN-003 decision in all ten cases. Opus labelled case 3 `PEN-002` and case 10 `COMP`, the reverse of the table. The text said a broken contract is COMP or PEN-002 and not which one. |
| 2 | with the sentence "how the fact was detected tells which of the two takes it" | 10 of 10 | 10 of 10 | Both match the table and each other in every case. |

The fix in round 2 states what `harness/score.py` already does, so it changes no score: PEN-002 comes from a characterization test that fails in
the mechanical report, and COMP comes from the judge's own list.

Limits. Both judges are Claude models, so a shared bias is not ruled out. The ten cases were written by the same hand as the ruler, so they test
that the text is unambiguous, not that it is right. No human judge has applied it yet. This is the minimum proof the proposal asked for, not
more. A published run on task 1.1.0 that disagrees with the section is a reason to correct the text, never the verdict after the fact.
