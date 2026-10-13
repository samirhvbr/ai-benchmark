# LEB — Matriz Oficial de Falhas

Normativo. **O coração do benchmark.**

The matrix is the **answer key** of a LEB instance: the exact list of planted flaws (and decoys), with severity, points,
location and expected fix. It turns "evaluating code", a subjective activity, into an **objective check**: which flaws the model
found, which it fixed correctly, which it ignored and which it **invented**. That is what makes LEB reproducible and comparable
across LLMs.

The key is public. It is committed with its instance before the first run (§4, items 1 and 2), and so is every key to come.
What keeps it from the agent under test is the run itself: the bench machines cannot reach GitHub.

---

## 1. Estrutura de uma instância

```text
LEB-200-A/                     ← instância "A" do nível 200
├── code/                      ← o sistema legado entregue ao modelo (público)
├── manifest.md                ← superfície pública declarada (público)
├── characterization/          ← testes de caracterização (público p/ avaliação; ver §5)
└── private/                   ← the key: never in the package; committed with the instance, before the first run (§4)
    ├── matrix.json            ← a Matriz Oficial (schema: matrix.schema.json)
    ├── matrix.md              ← versão legível da matriz
    └── verify/                ← testes de verificação por falha (exploits, probes de perf)
```

## 2. Campos da matriz (por linha)

| Campo | Descrição |
| --- | --- |
| `id` | ID da taxonomia (`SEC-001`…) — pode repetir com sufixo de instância (`SEC-001.a`, `SEC-001.b`) quando a mesma classe é plantada 2× |
| `exists` | `true` = plantada · `false` = **isca** (reportá-la é PEN-004) |
| `severity` | Crítica / Alta / Média / Baixa → define pontos-base (`SCORING.md §1`) |
| `points` | Pontos-base (derivado da severidade; registrado explícito) |
| `location` | `arquivo:linha` (ou faixa) do plantio |
| `evidence` | Trecho/sintoma que caracteriza a falha |
| `expected_fix` | Correção/estrutura-alvo canônica (o que o critério C3/R3 cobra) |
| `verify` | Como conferir mecanicamente (teste em `private/verify/`, quando aplicável) |
| `notes` | Armadilhas de avaliação (ex.: correções alternativas aceitas) |
| `dimensions` | *Optional, informative.* Cross-cutting properties the flaw exercises (`concorrencia`, `consistencia`, `resiliencia`). The scorecard summarizes planted, detected and corrected per dimension. Never changes points. |
| `decoy_kind` | *Optional, informative, decoys only.* `diagnostico` (tempts the report) or `intervencao` (tempts a change to the code). Never changes points or PEN-004. |
| `informative_affected` | *Optional, informative.* Tests and contracts a fix touches. No effect on points. |

The header of the matrix (next to `instance`, `level`, `version`, `leb_spec`) may also carry three optional fields:

| Field | Meaning |
| --- | --- |
| `task_version` | Version of the canonical task the instance is evaluated against (`protocol/tasks/TAREFA-<version>.md`); absent means 1.0.0 (SPEC §9.4). The next series' tracks carry a prefix, `D-` (Default) or `S-` (Security), and their statements live in `protocol/tasks/TASK-<version>.md` ([`docs/new-series-design.md`](../docs/new-series-design.md) §4). |
| `publication` | `"aggregate"` marks an **active** instance: only a per-agent aggregate of its runs is published (§4, item 7); the key itself is public from the instance's creation (§4, item 1). Absent means full publication, as always. |
| `pack_allow_paths` | Package paths (relative to the package root, e.g. `code/src/verify`) that the leak guard's path-word check lets through. It exempts a path, never content (`harness/README.md`, *Package leak guard*). |

The texts of `evidence`, `expected_fix`, `location` and `notes` with 20 or more characters are also the markers the leak guard
looks for inside the package. Write `evidence` as the **symptom**, not as a verbatim line of the code the model receives: a verbatim
line matches the code itself and the packaging refuses it (fail-safe).

Exemplo legível:

| ID | Existe | Severidade | Pontos | Localização |
| --- | --- | --- | ---: | --- |
| SEC-001 | Sim | Crítica | 10 | `code/src/relatorio.php:88` |
| PERF-004 | Sim | Média | 6 | `code/src/dashboard.php:31` |
| BUG-003 | Sim | Alta | 8 | `code/src/export.php:112` |
| ARCH-006 | **Não** | — | 0 | — (isca) |

## 3. Regras de construção

1. Toda falha plantada **DEVE** ser real e verificável — exploit que funciona, N+1 mensurável, bug com cenário de reprodução. Nada de "cheiro" sem consequência.
2. Toda instância **DEVE** conter **iscas** (linhas `exists: false`): de 10% a 20% do total de linhas da matriz, escolhidas entre falhas *plausíveis* para aquele código. Iscas separam quem leu o código de quem recita checklists.
3. A soma de pontos por categoria é livre — a normalização (`SCORING.md §4`) converte para o peso oficial. Recomenda-se variedade de severidades.
4. `expected_fix` **DEVE** admitir equivalentes técnicos (registrados em `notes`); o critério cobra o *efeito*, não o texto exato da correção.
5. Falhas não podem se sobrepor no mesmo trecho a ponto de uma correção resolver duas sem o modelo saber — cada linha da matriz precisa ser individualmente atribuível.
6. A matriz de instância publicada é **imutável** (SPEC §9); erro descoberto → nova versão da instância (`LEB-200-A v1.1`) com changelog.

## 4. Publication and integrity (anti-contamination)

> Rewritten on 2026-10-12 by the owner's decision. Until then the key stayed private and only its SHA-256 was published, with
> the key to be revealed when the instance was retired; item 8 says why that changed. The earlier wording is in the history.

1. **An instance and its key are committed to this public repository when the instance is created**: `code/`, `manifest.md`,
   `characterization/` and `private/` (the matrix, its readable version and the verifiers). **No run starts before that commit
   is on GitHub.** Its public timestamp is what proves that every run used that instance and that key; a hash published later
   cannot prove it for the runs made before it (item 8).
2. **Every run names what it used by hash:** the package (`package_sha256`) and the matrix (`matrix_sha256`), recorded in the run
   and cited by every result. Anyone can check out the instance at the commit of item 1, rebuild the package with the run's mode
   and turns (`./leb pacote <ID> --mode … --turnos …`) and recompute both hashes. A change after the first run is a new version
   of the instance (§3, item 6), never an edit of the published one.
3. **No agent reads the key during a run.** The package never carries `private/` (the leak guard checks it, `harness/README.md`),
   and the bench machines cannot reach GitHub (README, *Execution environment*). Every run records which isolation layers were in
   place: the first ten LEB-100-A runs (2026-09-29) had GitHub's names blocked but not its addresses; runs from 2026-09-30 have
   both.
4. **What publication cannot prevent is a model trained after it having seen the key.** Every run records the model's training
   cutoff as its provider publishes it (PROTOCOL §3). A cutoff after the commit of item 1, or none published, does not disqualify
   the run: its scorecard and the leaderboard say that the model may have trained on the key.
5. **Instances expire.** Once an instance has been public long enough to be in training corpora, counted from the commit of
   item 1, it is retired: old results stay valid, and new runs need a current instance. The cutoffs recorded run by run are the
   evidence for that decision.
6. Official runs **MUST** use a current instance and declare its hashes in the scorecard.
7. **Active instances (`publication: "aggregate"` in the matrix header)** publish only a per-agent aggregate of their runs
   (`scoring/publicacao-agregada.schema.json`: hashes, protocol, score, grade, category scores, cost and time), written by
   `tools/export-results.py --publish-aggregate` and by nothing else. The deliveries, verdicts, mechanical reports and per-flaw
   results of their runs stay in a private archive; releasing any of them takes the owner's express order, case by case,
   recorded in the commit that does it. The key itself is public under item 1.
8. **Why items 1 and 2 exist.** LEB-100-A's key has been public since 2026-07-13, earlier than planned, and its runs record their
   models' cutoffs for that reason. LEB-300-A was launched under the earlier rule: its matrix hash reached GitHub in the same
   commit as its first result (0.2.128, 2026-10-08), not before. Results published after that commit are covered by the public
   hash; the runs made before it are covered only by the operator's private freeze record, which no one else can check.
   Committing the instance and its key before the first run closes that gap for every instance from now on. LEB-300-A's own
   instance and key go into `instances/LEB-300-A/`, and they must hash to the `matrix_sha256` its results already cite
   (`c42c82878d2cf3fe081d7f15e633d043c27701ea98c8f179f1527ac86f543181`).

## 5. Correspondência (matching) relatório × matriz

1. O avaliador mapeia cada achado do relatório do modelo para no máximo **uma** linha da matriz (a mais específica).
2. Localização confere com tolerância de ±10 linhas ou mesma função/método.
3. Achado real fora da matriz: 0 pontos, 0 penalidade, registrado para a próxima versão (`SCORING.md §3`).
4. Correções silenciosas (código corrigido sem constar no relatório) pontuam C3/C4/C5, mas **não** C1/C2 — o LEB exige que engenheira *comunique* o que fez.
5. Os testes de caracterização rodam **antes** (devem passar no legado intocado) e **depois** (medem regressão/compatibilidade); os testes de `private/verify/` conferem cada correção individual.
