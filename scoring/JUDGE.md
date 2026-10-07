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
