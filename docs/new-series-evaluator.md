# The evaluator of the new series — specification

> **Status: `PROPOSED`.** It implements step 2 of [`new-series-design.md`](new-series-design.md) section 12 and section 8 of
> that page. Written on 2026-10-14, for the **Default** track first (LEB-D-300-A); the Security track gets its own section when
> its first instance is authored. Nothing here changes the full LEB: `harness/score.py`, `scoring/SCORING.md` and every
> published result stay as they are. The numbers marked **proposed** are the author's defaults with their reasons, listed in
> section 10 for the owner to confirm before the instance is frozen. The weights are the owner's draft of design 4.1.

## 1. What it is

A delivery goes in, one scorecard comes out, and the same delivery always gets the same scorecard from the same verdicts. The
evaluator has three stages, and only the middle one is not arithmetic:

1. **Mechanical** (`harness/java/evaluate.sh`, run through the instance's `private/runner.json`): the delivery's build, the
   public characterization suite, and the instance's verifiers. It decides what can be decided by running code.
2. **Judging**: two independent judges, blind to the agent, read the delivery's report and diff against the key. A person settles
   what they disagree on.
3. **Scoring** (`harness/score_v2.py`): arithmetic over the mechanical report, the settled verdict and the matrix. No judgment.

What differs from the full LEB's evaluator, and why (design section 1, problems 3 and 4):

| | Full LEB (`score.py`) | New series (`score_v2.py`) |
| --- | --- | --- |
| A broken characterization test | turns C4/R4 to `none` for **every** attempted flaw, **and** costs PEN-002 (−20 per test) | one charge per broken **behavior**, on the fix that broke it (section 5) |
| How many tests a defect splits into | changes the charge | does not |
| Judges | one, blind | two, blind, independent; a person settles disagreements |
| Weights | SEC 250 · ARCH 200 · BUG 150 · PERF 150 · CLN 100 · COMP 100 · EXPL 50 | per track (section 6) |
| Partial deliveries | not scored | scored like any other (section 8) |

## 2. Inputs

| Input | Where it comes from |
| --- | --- |
| The key | `instances/<ID>/private/matrix.json`, with the `template`, `severity`, `points`, `verify` and `dimensions` of each row |
| The regression groups | `instances/<ID>/private/regression_groups.json` (section 5), authored and frozen with the instance |
| The mechanical report | `harness/leb_harness.py` through the runner contract: per row `full`/`half`/`none` or a ceiling, decoys, characterization **with the name of every failed test** |
| The report and the findings index | the delivery's `REPORT.md` and `findings.json` ([`scoring/findings.schema.json`](../scoring/findings.schema.json)) |
| The traceability record | `caso.json` filed by the lab after `tools/auditar-tentativa.py` (outcome, models observed, cost, time) |

## 3. The mechanical stage

Unchanged in what it decides (the row verifiers, the decoy checks, the public suite), with one addition the regression rule
needs: `evaluate.sh characterize` reports **which tests failed**, not only how many.

```json
{"passed": 68, "failed": 2, "failed_tests": ["BillingTest#lateFeeRoundsHalfUp", "BillingTest#lateFeeIsCappedAtTwentyPercent"]}
```

A delivery that does not build has `build_failed: true`: every row is `none`, every public test counts as failed, and the
regression rule treats it as one regression (section 5), not as seventy.

## 4. Judging

Two judges per delivery, each given the same prompts and the same anonymized folder, each writing a verdict that validates
against [`scoring/judge.schema.json`](../scoring/judge.schema.json) (extended in section 7). They never see each other's
verdict, the scorecard, the agent's identity or the other categories.

- **Step 4, matching** (what the full LEB calls the matching judge): `planted` (one entry per row: reported or not, `C1/C2` or
  `R1/R2`, the finding that matches), `false_positives`, `extra_findings`, `comp_violations`, the PEN-001 and PEN-003 calls, and
  **`regressions`**: for each regression group the mechanical report found broken, the row whose fix caused it, or `null` when no
  fix did (section 5).
- **Step 5, EXPL**: the five-dimension rubric of `scoring/SCORING.md` section 6, on the report alone.

**A disagreement** is any of: a row where the two verdicts differ in any criterion; a `comp_violations` or a PEN call present in
one and not in the other; a regression attributed to different rows (or to a row by one and to none by the other); an EXPL
dimension more than two points apart. The settled verdict is built by `tools/judge_review.py`: it takes what both judges agree
on, lists the disagreements in a file, and writes the person's decision for each one, with the reason, into the settled verdict's
`review` block. **An agreement is never reopened** and a disagreement never takes the average: a person decides, or the delivery
is not scored.

## 5. The regression rule: one behavior, one charge

**Problem.** Today one broken test costs three things at once: C4 for every attempted flaw, the −20 of PEN-002, and nothing
ties the damage to the fix that did it. A defect the evaluator happened to split into eight tests costs eight times.

**Definition.** A **regression** is a *group* of characterization tests that guard one contract of the manifest, with at least
one test failing in the delivery. `regression_groups.json` declares the groups once, with the instance:

```json
{"groups": [
  {"id": "RG-01", "contract": "manifest 3.2 late fee on overdue charges", "tests": ["BillingTest#lateFee*"]},
  {"id": "RG-02", "contract": "manifest 5.1 booking overlap", "tests": ["BookingTest#overlap*", "BookingTest#adjacent*"]}
]}
```

Every public test belongs to exactly one group (a test in no group is an authoring error the instance's tests catch). Eight tests
of one group failing is **one** regression.

**Charge.**

1. *Attributed*: the judge names the row whose fix broke the group. The charge is that row's `C4` (Template C) or `R4`
   (Template R): `none`. No other row loses anything, and there is no PEN-002.
2. *Unattributed*: no fix of a planted row caused it (a change outside the matrix, an extra finding's fix, a rewrite). The charge
   is **PEN-002, −20, once per group**.
3. *A build failure* is one unattributed regression: PEN-002 once, and every row is `none` anyway.

So each regression is charged **once**, where it happened. The row-level `C4/R4` stays what its name says: this fix did not
break anything.

**COMP is not part of this rule.** A `COMP-*` violation keeps the intentional double charge of `scoring/SCORING.md` section 5
(the fix stops being "complete" through `C5/R4`, and the conduct is marked through the COMP category). The owner's complaint
(design problem 4) was about regressions; changing COMP too is a separate decision (section 10, item 3).

## 6. Scoring arithmetic

Per row, the criteria and their base points are those of `scoring/SCORING.md` section 1 (Template C and Template R by severity),
the verdicts `full`, `half` (rounded down) and `none`, with the mechanical evidence applied as it is today: a probe overrides the
judge's `C3`, and structural evidence is a ceiling for `R3`, never a floor. A row's quality criteria (`C4/C5`, `R4`) count only
if the fix was attempted.

Per category: `score = round(earned / possible × weight)`, `possible` being the points the instance's matrix plants in that
category, so an instance is always worth 1000.

**Weights of the Default track** (the owner's draft of design 4.1, to be calibrated on the development set and frozen before the
evaluation set runs):

| ARCH | BUG | PERF | CLN | COMP | EXPL | total |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 283 | 213 | 213 | 141 | 100 | 50 | 1000 |

- **COMP:** `max(0, 100 − Σ deductions)`, by occurrence, with the table of `SPEC.md` section 6.1 (unchanged).
- **EXPL:** the sum of the five dimensions, 0 to 50 (unchanged). A delivery with no `REPORT.md` has EXPL 0 and every row's
  `C1/C2` (`R1/R2`) `none`: what the agent did not say counts for nothing.
- **Penalties:** PEN-001 (a new defect in code the delivery changed, −15 each), PEN-003 (a rewrite where a localized fix sufficed,
  −25), PEN-004 (a decoy reported, −5 each, at most −25) as in `SCORING.md`; PEN-002 as in section 5.
- **Total:** `Σ category scores + COMP + EXPL − Σ penalties`, floor 0, ceiling 1000. The grade seals of `SCORING.md` section 8
  stay informative on a single delivery; the seals of a *configuration* are defined on its estimate with its interval (design
  section 9), in the pilot.

The informative metrics (calibration, discovery index, dimension and decoy breakdowns, cost and time) are computed as today and
never enter the total.

## 7. The scorecard v2 and the verdict

`scoring/scorecard-v2.schema.json` is the scorecard of `scoring/scorecard.schema.json` with these changes:

- `scorer`: `{"name": "score_v2", "version": "1.0.0"}`, and `track`: `"default"` (or `"security"`).
- `regressions`: `[{"group": "RG-01", "tests_failed": 2, "charged_to": "BUG-007" | null, "deduction": 0 | -20}]`.
- `penalties`: PEN-002 appears once per unattributed group, with the group in its `detail`.
- `outcome`: one of the outcomes of design 6.2 (`completed`, `blocked`, `budget_exhausted`), as filed by the lab.
- `review`: the settled verdict's disagreements and how each was decided (empty when the judges agreed on everything).

`scoring/judge.schema.json` gains an optional `regressions` array in the verdict and a `review` object in the settled verdict.
Both are optional there, so every published LEB-100-A and LEB-300-A verdict still validates.

## 8. Partial deliveries and outcomes

Every attempt that leaves a work folder is evaluated by the same stages, whatever its outcome. A *blocked* attempt, or one whose
budget ran out, is scored on the folder it left, and its score enters the **expected score per scheduled attempt** of design
section 9, never the quality score. The scorecard's `outcome` says which it is, so the two figures are computed from the same
files. An attempt filed as *lab failure* or *operator error* is not scored; it is rerun under the rule of design 6.2.

## 9. What is built, and where

| Piece | File | Notes |
| --- | --- | --- |
| Failed test names | `harness/java/evaluate.sh`, `harness/leb_harness.py` | the report gains `failed_tests` |
| Regression groups | `instances/LEB-D-300-A/private/regression_groups.json` | authored from the 70 tests and the manifest, checked by `tests/test_leb_d_300_a.py` |
| The scorer | `harness/score_v2.py`, `scoring/scorecard-v2.schema.json` | `harness/score.py` stays and still reproduces the 92 published LEB-100-A totals |
| Verdicts and review | `scoring/judge.schema.json`, `scoring/JUDGE.md` addendum, `tools/judge_review.py` | two judges, disagreements, the person's decisions |
| Statistics | `tools/estatisticas.py` (design sections 9 and 11) | written last, needed at the end of the pilot |
| Tests | `tests/test_score_v2.py` | golden scorecards from the variant cases, the planted tree as the baseline, the reference as full marks, the recomputation of the 92 LEB-100-A runs (old rule against new) |

The judges' prompts and the anonymizing step are lab material (they name the lab's folders), and live with the lab's private
tools; what they must produce is fixed here and in the schemas.

## 10. Decisions for the owner

Each has the author's proposal. Nothing below is frozen until it is confirmed, and none of it can change after the instance's first
run without a new version of the instance (`matrix/MATRIX.md` section 4).

1. **The regression group is a contract, not a test class.** Proposed: groups by manifest clause, about twenty for the 70 tests,
   authored with the instance. The alternative, one group per test class (six), is coarser than the owner's principle.
2. **Who attributes a regression to a fix.** Proposed: the judges (they see the diff), the mechanical stage only finds the
   groups; a disagreement goes to the person. The alternative, a mechanical attribution by reverting each fix, is not
   generally possible.
3. **COMP keeps its double charge.** Proposed: yes, as documented and intentional in `SCORING.md` section 5. Removing it is a
   scoring change of the same kind as problem 4 and deserves its own look at the data.
4. **PEN-002 is −20, once per unattributed group.** Proposed: the full LEB's amount, now charged once per behavior.
5. **A person settles every disagreement; the second judge costs one more judging pass per delivery.** Proposed: yes; the
   expected number of disagreements per delivery is measured in the pilot and reported with it.
6. **The Default weights are the draft of design 4.1**, unchanged until the development set has run.
