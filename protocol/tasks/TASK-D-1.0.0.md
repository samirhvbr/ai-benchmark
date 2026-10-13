<!-- LEB:TASK version=D-1.0.0 — canonical template of the Default track. Do NOT edit per instance. -->
# LEB Default — Task

| | |
| --- | --- |
| Instance | **{{INSTANCE}}** (level {{LEVEL}}, version {{INSTANCE_VERSION}}) |
| Standard | LEB spec {{LEB_SPEC}} · task {{TASK_VERSION}} |
| Evaluation matrix (SHA-256) | `{{MATRIX_SHA256}}` |

> `harness/pack.py` writes this file from `protocol/tasks/TASK-D-1.0.0.md`, next to `code/` and `manifest.md` in the package. The
> text below is the same in every instance that declares this version of the task; only the table above changes.

---

## 1. Context and task

> You are responsible for maintaining this legacy system in production. It has external consumers that depend on its current
> behavior, as stated in the attached public surface manifest.
>
> Your task:
> 1. Analyze the code and report the problems you find in its architecture, bugs, performance and code quality, explaining each
>    one, its severity and a **confidence from 0 to 100** that the problem is real.
> 2. Fix what you judge should be fixed, preserving the compatibility the manifest declares.
> 3. Deliver: (a) the technical report; (b) the changed code; (c) the reasons for your decisions, including what you decided NOT
>    to change and why.
>
> Do not rewrite the system. Evolve it.

This is everyday maintenance: architecture, bugs, performance, code quality, and compatibility with what the manifest declares.
The work is done in one session, with one budget. Before you finish, end anything you started, helper agents included. Nobody will
give you grades, test results or guidance during the work.

## 2. What you receive

| File | What it is |
| --- | --- |
| `code/` | the legacy system, as it runs in production, with `code/ENVIRONMENT.md` on how to build and test it |
| `manifest.md` | the **public surface**: the signatures, routes, formats and business rules that external consumers use |
| `TASK.md` | this file |

There is nothing else. The manifest does **not** point at any problem: it only describes the contract that must keep holding.

## 3. What you deliver

Three artifacts, with these names:

| Artifact | Format | Role |
| --- | --- | --- |
| `code/` | the changed project: you may create, split, move and rename files (see §6) | the fix itself |
| `REPORT.md` | technical prose | what you found, why it is a problem, what you decided to do |
| `findings.json` | JSON (§5) | the structured index of the same findings |

`findings.json` **does not replace** the report: it is the index that locates each finding without ambiguity. Every finding of the
JSON must be in the report, and the other way round.

## 4. `REPORT.md` — minimum content

1. **Summary**: the state of the system in a few lines.
2. **One block per finding**, in the order you would prioritize fixing them, with:
   - where it is (file and line) and what it is;
   - the **mechanism**: why it actually fails — not the generic category, the concrete path of the problem in this code;
   - the impact and the severity you assign;
   - the **confidence from 0 to 100** that the problem is real;
   - what you did (or why you did not).
3. **Decisions**: what you deliberately did **not** change, and why (scope, risk, compatibility, cost and benefit). This counts:
   an engineer who does not explain what was left out has not finished the work.

## 5. `findings.json` — format

```json
{
  "leb": {
    "instance": "{{INSTANCE}}",
    "instance_version": "{{INSTANCE_VERSION}}",
    "matrix_sha256": "{{MATRIX_SHA256}}"
  },
  "findings": [
    {
      "id": "F1",
      "title": "the problem in one line",
      "file": "code/<file>",
      "line": 123,
      "line_end": 130,
      "category": "bug",
      "severity": "high",
      "confidence": 85,
      "mechanism": "why it fails, concretely, in this code",
      "impact": "what a user, or the system's operation, suffers from it",
      "fix": "what you changed (or would change)",
      "fixed": true
    }
  ],
  "not_changed": [
    { "what": "what you left as it is", "why": "the reason for the decision" }
  ]
}
```

Rules of the fields:

| Field | Rule |
| --- | --- |
| `id` | your own identifier (`F1`, `F2`, …), unique in the file; use the **same** one in the report |
| `file` | path **relative to the package**, as delivered (e.g. `code/<path>`) |
| `line` / `line_end` | the line in the **original numbering** of the file you received; `line_end` is optional |
| `category` | exactly one of: `architecture` · `bug` · `performance` · `quality` · `other` |
| `severity` | exactly one of: `critical` · `high` · `medium` · `low` |
| `confidence` | an integer from 0 to 100 — your probability that the problem is **real** |
| `mechanism` | specific to this code; generic checklist text does not count |
| `fixed` | `true` if the delivered code already fixes the finding; `false` if you only reported it |

Copy the `leb` block **exactly** from the table at the top of this file: it ties your delivery to this instance.

## 6. Constraints

1. **Keep the public surface** that `manifest.md` describes: the names and signatures of functions, routes and parameters, the
   output formats, the declared structure of the HTML, and the business rules. Breaking the contract costs points, even when the
   change is technically better.
2. **Do not change the stack** or the data access layer: that changes the public signatures.
3. **You may create, split, move and rename files** of `code/`, as long as the public surface of the manifest is kept. The
   evaluation starts from the manifest and from behavior, not from the internal names of files and classes.
4. **Do not rewrite the system.** Evolve it. A full rewrite is treated as an escape, not as engineering. Not rewriting the system
   does not mean keeping its internal organization: extracting responsibilities, reorganizing components and dependencies and
   keeping compatible facades are allowed, as long as the public contracts are kept and the changes solve concrete problems.
5. Do not add external dependencies; work with what the stack already has.

## 7. Checklist before delivering

- [ ] `code/` with the changed project; the contracts of the manifest kept.
- [ ] `REPORT.md` with mechanism, severity and confidence 0–100 for each finding.
- [ ] `findings.json` valid, with the `leb` block copied from the top of this file.
- [ ] Every finding of the report is in the JSON, and the other way round.
- [ ] The **Decisions** section says what you did not change, and why.
- [ ] Nothing of `manifest.md` was broken.
