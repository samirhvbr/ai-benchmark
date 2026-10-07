# LEB — Protocolo do Juiz (passos 4–5)

Normativo. Operacionaliza os passos **com julgamento** do pipeline (`../protocol/PROTOCOL.md §5`):
o que o harness mecânico **não** decide sozinho. A saída é um **veredito** JSON
(`judge.schema.json`) que o montador [`../harness/score.py`](../harness/score.py) transforma no
scorecard oficial. Juiz = humano **ou** LLM com esta rubrica; em ambos os casos o veredito é
auditável (full-disclosure).

O juiz recebe: o **relatório do modelo** (`RELATORIO.md`, prosa), o **`achados.json`** (índice
estruturado da mesma entrega, `achados.schema.json`), o **código entregue**, a **matriz**
(gabarito) e o **relatório mecânico** do harness (o que já foi verificado: C3 por probe, C4
regressão). O juiz **não** re-verifica o que é mecânico — só preenche o que exige julgamento.

## Passo 4 — matching relatório × matriz

Regras de correspondência em `../matrix/MATRIX.md §5`. Para cada achado do relatório do modelo,
mapeie para **no máximo uma** linha da matriz (a mais específica), com tolerância de localização
de ±10 linhas ou mesma função. Depois, para cada falha PLANTADA, preencha os critérios:

| Critério | full | half | none |
| --- | --- | --- | --- |
| **C1/R1** achou/identificou | ID certo + trecho certo | classe errada mas trecho certo | não reportou / lugar errado |
| **C2/R2** explicou | mecanismo real da falha | — (use full/none) | genérico/boilerplate/errado |
| **C3** corrigiu *(falha com probe: vem do harness)* | eliminou a falha | mitigou, não eliminou | não corrigiu |
| **R3** refatorou | estrutura-alvo alcançada | parcial genuíno | cosmético/nada |
| **C4** sem regressão *(vem do harness)* | caracterização verde | — | quebrou o entorno |
| **C5/R4** manteve compat | sem violação COMP atribuível | — | violou contrato |

- **Correção silenciosa** (código corrigido mas não citado no relatório): pontua C3/C4/C5, **não** C1/C2 (`MATRIX §5.4`).
- **C4/C5 (e R4) só contam se houve correção** (C3/R3 tentado). Sem conserto não há regressão nem compat a premiar — o montador zera isso automaticamente.
- Registre a **confiança** que o modelo declarou por achado (enunciado pede 0–100) → alimenta a calibração.
- **Structural evidence on R3** *(only when the mechanical report carries `result` and `proves: ["R3"]` for the flaw)*: it is the **ceiling** of R3.
  You may **lower** R3, and you must justify it when the evidence is `full` and the change looks like a merely cosmetic transfer of code.
  You may not raise R3 above the evidence, and the evidence does not replace R1, R2 or R4. The assembler applies `min(judge, evidence)` and
  records `evidence` in the scorecard. Without that field in the report, nothing changes.

### Como usar o `achados.json`

O JSON é **índice, não veredito**: ele diz *onde* o modelo aponta cada achado; quem decide se
casa com a matriz é você.

- **Localização** (`arquivo` + `linha`) → aplica-se direto a tolerância de ±10 linhas / mesma
  função. É o que torna o matching verificável por terceiros em vez de interpretativo.
- **Categoria** → taxonomia: `seguranca`→SEC, `arquitetura`→ARCH, `bug`→BUG,
  `performance`→PERF, `qualidade`→CLN. Categoria errada com trecho certo é **C1 pela metade**.
- **Mecanismo** → pontue **C2 pelo `RELATORIO.md`**, não pelo campo do JSON: o JSON é resumo, a
  prosa é a explicação. Em divergência entre os dois, a prosa manda no conteúdo e o JSON manda na
  localização.
- **`corrigido`** → é declaração do modelo. A evidência mecânica (probe) prevalece sempre.
- **`confianca`** → alimenta a calibração; se faltar no JSON, use a declarada na prosa.
- **`leb`** → confira o vínculo contra o pacote entregue (`PROTOCOL §2.2`). Divergente = entrega
  feita contra outra versão da instância; registre no veredito, pois o run não é comparável.
- **Entrega sem `achados.json`** → válida. Faça o matching a partir do relatório, do mesmo jeito
  que antes; o formato é descritivo e **não pontua nem penaliza** (`../SPEC.md §9.5`).
- Achado que existe só no JSON ou só na prosa → trate como reportado (o conteúdo é o que vale) e
  registre a inconsistência nas notas do veredito.

### Falsos positivos e achados extra

- Achado que casa uma **isca** (`exists:false`) → `false_positives` com `is_isca:true` → **PEN-004** (−5, teto −25).
- Achado **inexistente** que não é isca declarada → `false_positives` (`is_isca:false`): 0 penalidade, mas conta como erro na **calibração**.
- Achado **real fora da matriz** → `extra_findings`: 0 ponto, 0 penalidade, vira candidato à próxima versão da instância.

### Compatibilidade (COMP)

Liste em `comp_violations` cada violação de superfície pública atribuível às mudanças do modelo
(`../SPEC.md §6.1`), com `count` por ocorrência. Ex.: migrar mysqli→PDO = `COMP-010` **+** `COMP-001`
por assinatura pública alterada. (Até o diff de superfície virar mecânico no harness, isto é do juiz.)

### PEN-003 when the task is 1.1.0 or later

**Scope.** This section applies only to a delivery evaluated against task **1.1.0 or later** (`task_version` in `run.json`). A delivery on
task 1.0.0, which is every published LEB-100-A run, is judged as before, and nothing here changes its score. Task 1.0.0 forbids moving or
renaming files, so a delivery could hardly look like a rewrite. Task 1.1.0 allows creating, splitting, moving and renaming files and keeping
facades, so the size of a diff no longer tells a legitimate extraction from a substitution.

Rewriting is not changing many lines, creating many classes or reorganizing the internals. Decide PEN-003 from the **combination** of six
elements, on the delivery, and **without using** the number of classes, files created, lines changed or the size of the diff as a shortcut
(the mechanical report may print them as information):

1. **Public surface preserved.**
2. **Contracted behaviors preserved** (manifest, API, formats, database, rules). A broken contract is COMP-* or PEN-002, never PEN-003.
3. **Declared stack kept and declared restrictions respected.**
4. **Changes tied to concrete problems:** each relevant structural change answers a problem the delivery identified.
5. **Proportionality** of the change.
6. **No indiscriminate replacement** of correct parts only to impose another architecture.

The internal reorganization needed to fix an architecture defect is **explicitly allowed**. PEN-003 applies when the delivery **replaces** the
system or a whole module unnecessarily and out of scope (elements 5 and 6), or changes the declared stack (element 3). It does not apply to an
internal reorganization that preserves the contract and answers concrete problems, however large the diff. To avoid punishing one fact twice
(`../SPEC.md §6.2`): if the change also breaks a contract, the deduction is the COMP-* or PEN-002 one, and PEN-003 is not added for the same fact.
How the fact was detected tells which of the two takes it: a characterization test that fails is PEN-002 and comes from the mechanical report; a
surface or contract break that no characterization test catches is COMP and comes from your own list.

*Reorganization allowed* (examples): extracting a responsibility into another class; moving data access into its own component and making the
rules delegate to it; centralizing in one policy a rule duplicated across paths; injecting a dependency at the edge instead of reading global
state; splitting a long method; moving, renaming or creating files and packages while keeping the manifest's facades.

*Replacement not authorized* (examples): swapping the data-access layer for another technology, or the database for another; adding a web
framework, a dependency-injection framework or any dependency the manifest does not allow; changing routes, formats or the schema in an
incompatible way; rewriting a whole module with behavior different from the contracted one; changing the language or the platform.

**Evidence.** Elements 1 and 2 come from the mechanical result that already exists (characterization, contract checks, the evaluator's own
checks). Element 3 comes from what the evaluator builds: where it builds against its own dependency list, a new dependency fails the build,
and the type references in the bytecode show a swapped layer. Elements 4 to 6 come from reading the report and the diff, with the map the
candidate delivers in `achados.json` linking changes to findings. A structural change with no matching finding is not punished by itself;
only a **substitution** is.

**Recording.** Register PEN-003 as 0 or 1 per run, because `SPEC §6.2` lists one flat −25. When you apply it, put the six elements and a
justification in the verdict's `notes`. When the delivery changed many files and you do not apply it, put one line saying why.

**Calibration cases.** [`pen003-cases.md`](pen003-cases.md) holds ten fictional deliveries with the expected outcome of each. Two independent
passes agree with it before this section is relied on. No weight, penalty or value changes.

## Passo 5 — rubrica EXPL (às cegas)

Pontue a Explicação Técnica do relatório em 5 dimensões × 10 (âncoras em `SCORING.md §6`):
`clareza · precisao · causa_raiz · priorizacao · trade_offs`. **Às cegas**: o juiz de EXPL não vê
o scorecard das outras categorias, para não contaminar a nota.

## Montagem

```sh
python3 harness/score.py \
    --matrix    instances/LEB-100-A/private/matrix.json \
    --mechanical relatorio_mecanico.json   # saída do leb_harness.py \
    --judge      veredito.json             # este documento, no formato judge.schema.json \
    --out        scorecard.json
```

O montador aplica SCORING §1 (pontos por critério), §4 (normalização), §5 (COMP), §7 (TOTAL),
§8 (selo), §9.1 (Brier) e §9.2 (dificuldade) — tudo determinístico. PEN-002 (regressão) e o C4
saem do relatório mecânico; PEN-004 sai dos `false_positives`.
