# LEB — LLM Engineering Benchmark

**Especificação Técnica** · Versão **1.4.0** · Status: **Rascunho normativo**

---

## 0. Resumo

O LEB é um padrão de avaliação da capacidade de uma LLM de atuar como **Engenheira de Software** na manutenção, evolução e refatoração de **sistemas legados**, preservando compatibilidade e aplicando boas práticas de arquitetura.

O benchmark **não mede** quem escreve o código mais bonito. Ele mede **quem consegue evoluir um sistema existente sem quebrá-lo**.

As palavras-chave **DEVE**, **NÃO DEVE**, **DEVERIA** e **PODE** neste documento seguem o espírito da RFC 2119.

---

## 1. Filosofia

O LEB privilegia, nesta ordem de valores:

1. **Engenharia** — decisões fundamentadas, não estética.
2. **Compatibilidade** — o código existente tem clientes; quebrá-los é a falha mais grave.
3. **Arquitetura** — evolução estrutural sem big-bang rewrite.
4. **Segurança** — vulnerabilidades reais, corrigidas sem regressão.
5. **Performance** — gargalos identificados com evidência, não superstição.
6. **Maturidade técnica** — saber explicar *por que*, priorizar e reconhecer trade-offs.

Corolário central: **reescrever tudo do zero não é engenharia, é fuga** — e o LEB penaliza isso explicitamente (§6).

---

## 2. Documentos do padrão

| Documento | Papel |
| --- | --- |
| `SPEC.md` (este) | Núcleo normativo: pontuação, regras, definições |
| [`taxonomy/`](taxonomy/) | Catálogo oficial das falhas (SEC, ARCH, PERF, BUG, CLN, COMP) |
| [`scoring/SCORING.md`](scoring/SCORING.md) | Modelo de pontuação detalhado, critérios e normalização |
| [`matrix/MATRIX.md`](matrix/MATRIX.md) | **Matriz Oficial de Falhas** — o gabarito oculto (coração do benchmark) |
| [`levels/LEVELS.md`](levels/LEVELS.md) | Níveis LEB-100 a LEB-500 |
| [`protocol/PROTOCOL.md`](protocol/PROTOCOL.md) | Protocolo de execução e reprodutibilidade |
| [`protocol/TAREFA.md`](protocol/TAREFA.md) | **Tarefa canônica** entregue ao modelo: enunciado neutro + contrato de entrega, igual em toda instância |
| [`scoring/JUDGE.md`](scoring/JUDGE.md) | Protocolo do juiz (passos 4–5): matching e rubrica EXPL |
| `scoring/achados.schema.json` | Formato do índice estruturado de achados que acompanha a entrega |
| [`harness/`](harness/) | Ferramentas: `pack.py` (pacote público) + `leb_harness.py` (mecânico) + `score.py` (montador do scorecard) |
| `leb` | Comando do operador: `pacote` · `avaliar` · `scorecard` · `estado`; orquestra o `harness/` e organiza tudo em `runs/<instância>/` |
| `scoring/scorecard-template.md` | Scorecard oficial de resultado |

---

## 3. Pontuação geral

Nota máxima: **1000 pontos**, distribuídos assim:

| Categoria | Prefixo | Peso |
| --- | --- | ---: |
| Segurança | `SEC` | **250** |
| Arquitetura | `ARCH` | **200** |
| Bugs | `BUG` | **150** |
| Performance | `PERF` | **150** |
| Clean Code | `CLN` | **100** |
| Compatibilidade | `COMP` | **100** |
| Explicação Técnica | `EXPL` | **50** |
| **Total** | | **1000** |

- As categorias SEC, ARCH, BUG, PERF e CLN são pontuadas contra a **Matriz Oficial de Falhas** da instância (§5) e **normalizadas** para o peso da categoria (ver `scoring/SCORING.md §4`).
- **Compatibilidade** é uma categoria de **conduta**: começa em 100 e cada violação `COMP-*` desconta (piso 0). Violações geram **desconto, não bônus**.
- **Explicação Técnica** é avaliada por rubrica (5 dimensões × 10 pontos).

---

## 4. Como pontuar cada falha

Cada falha plantada na instância possui critérios independentes. Existem dois templates de critérios (detalhes e tabelas por severidade em `scoring/SCORING.md`):

### Template C — Correção (SEC, PERF, BUG)

Exemplo — **SEC-001 SQL Injection**, severidade Crítica:

| Critério | Pontos |
| --- | ---: |
| Encontrou | 2 |
| Explicou corretamente | 2 |
| Corrigiu | 3 |
| Não introduziu regressão | 2 |
| Manteve compatibilidade | 1 |
| **Total** | **10** |

### Template R — Refatoração (ARCH, CLN)

Exemplo — **ARCH-001 God Object**, severidade Crítica:

| Critério | Pontos |
| --- | ---: |
| Identificou | 3 |
| Explicou | 2 |
| Refatorou | 5 |
| Compatível | 2 |
| **Total** | **12** |

Os critérios são **cumulativos e independentes**: um modelo pode encontrar e explicar uma falha sem corrigi-la (ganha parcial), ou corrigi-la introduzindo regressão (perde o critério de regressão).

---

## 5. Matriz Oficial de Falhas (gabarito oculto)

**Este é o coração do benchmark.** Cada instância LEB possui uma matriz secreta que declara, para cada ID da taxonomia:

| ID | Existe no código | Severidade | Pontos | Localização |
| --- | --- | ---: | ---: | --- |
| SEC-001 | Sim | Crítica | 10 | `src/relatorio.php:88` |
| PERF-004 | Sim | Média | 6 | `src/dashboard.php:31` |
| BUG-003 | Sim | Alta | 8 | `src/export.php:112` |
| ARCH-006 | **Não** | — | 0 | — |

A matriz torna a avaliação **objetiva e reproduzível**: verifica-se exatamente quais falhas o modelo **encontrou**, quais **corrigiu**, quais **ignorou** e quais **inventou** (falso positivo → penalidade, §6). Linhas "Existe: Não" funcionam como **iscas** contra modelos que listam falhas genéricas sem ler o código.

Cada falha plantada carrega também uma **dificuldade** (Fácil / Moderada / Difícil / Especialista) — quão difícil é *achar* a falha, ortogonal à severidade. Ela não altera pontos; alimenta só o eixo de descoberta do scorecard (§8.2).

Regras completas de construção, ocultação (hash público, gabarito privado) e correspondência em [`matrix/MATRIX.md`](matrix/MATRIX.md).

---

## 6. Penalidades

Diferencial do LEB: certas ações **descontam pontos automaticamente**. Isso impede que a IA "ganhe pontos" jogando tudo fora e começando do zero.

### 6.1 Violações de compatibilidade (descontam da categoria COMP, piso 0)

| ID | Ação | Desconto |
| --- | --- | ---: |
| COMP-001 | Mudou assinatura pública | −30 |
| COMP-002 | Mudou retorno | −20 |
| COMP-003 | Mudou comportamento / regra de negócio | −30 |
| COMP-004 | Removeu método | −20 |
| COMP-005 | Mudou exceção | −15 |
| COMP-006 | Mudou formato HTML | −10 |
| COMP-007 | Mudou rota | −20 |
| COMP-008 | Mudou contrato público (API) | −25 |
| COMP-009 | Mudou banco (schema/dados) | −25 |
| COMP-010 | Mudou tecnologia (ex.: mysqli → PDO) | −20 |

### 6.2 Penalidades gerais (descontam do TOTAL, piso 0)

| ID | Ação | Penalidade |
| --- | --- | ---: |
| PEN-001 | Introduziu novo bug | −15 (cada) |
| PEN-002 | Criou regressão (quebrou teste de caracterização) | −20 (cada) |
| PEN-003 | Reescreveu todo o projeto sem necessidade | −25 |
| PEN-004 | Falso positivo (reportou falha inexistente da matriz-isca) | −5 (cada, teto −25) |

Desambiguação (para não punir duas vezes o mesmo fato): regressão **localizada na correção de uma falha específica** → perde o critério "não introduziu regressão" daquela falha, sem PEN. Regressão **fora do escopo de qualquer falha** (detectada pelos testes de caracterização) → PEN-002. Defeito novo em código novo que não quebra teste existente → PEN-001.

> **How `harness/score.py` applies this today** (documented here, nothing changed). The assembler does not apply the localized reading above.
> For a Template C flaw, the criterion C4 is `full` only when the flaw was attempted and the mechanical report shows no regression anywhere in
> the delivery, unless the judge's verdict gives an explicit C4 for that flaw, which the assembler respects. One broken characterization test
> therefore turns C4 to `none` for every attempted flaw. PEN-002 is charged in addition and always: −20 for each characterization test the
> delivery broke, whether or not the fix of some flaw explains it. The same broken test is thus charged twice. Every published score was
> produced this way: `score.py` reproduces the published scorecards (`tests/test_historico.py`), none of the 1,196 published per-flaw verdict
> entries gives an explicit C4, and no published result is recomputed. Whether the code or this text should change is a separate decision, made
> outside any instance. See also `scoring/SCORING.md §2` and the note *Before quoting a number* in `results/2026/LEB-100-A/README.md`.

---

## 7. Níveis do benchmark

| Nível | Linhas | Objetivo |
| --- | ---: | --- |
| LEB-100 | ~300 | Refatoração simples |
| LEB-200 | ~1.000 | Sistema legado pequeno |
| LEB-300 | ~3.000 | Múltiplos arquivos |
| LEB-400 | ~8.000 | Projeto empresarial |
| LEB-500 | ~20.000 | Sistema corporativo completo |

Composição de falhas por nível em [`levels/LEVELS.md`](levels/LEVELS.md).

---

## 8. Scorecard oficial

Todo run produz um scorecard no formato de `scoring/scorecard-template.md` (+ JSON machine-readable). Exemplo abreviado:

```text
Modelo: GPT-5.6
--------------------
Segurança        SEC-001 ✔  SEC-002 ✔  SEC-003 ✘  SEC-004 ✔ ...
                 210 / 250
Arquitetura      175 / 200
Performance      130 / 150
Bugs             140 / 150
Clean Code        90 / 100
Compatibilidade   95 / 100
Explicação        48 / 50
--------------------
TOTAL            888 / 1000
```

### 8.1 Calibração (confiança por achado) — informativa

O modelo atribui a **cada problema reportado** uma confiança de 0 a 100 (`protocol/PROTOCOL.md §2`). O scorecard reporta um **Brier score** e um diagrama de confiabilidade sobre os achados reportados (acerto = casou falha plantada; erro = isca ou invenção). Mede *calibração* — quem acerta com convicção vs. quem chuta com sorte. **Não entra nos 1000 pontos**; pode servir de desempate. Detalhe em `scoring/SCORING.md §9.1`.

### 8.2 Eixo de dificuldade — informativo

Cada falha plantada tem uma **dificuldade** (§5). O scorecard reporta, por dificuldade, quantas foram **detectadas** e **corrigidas**, mais um `discovery_index` ponderado — o "quantas difíceis o modelo achou". Ortogonal à severidade e **fora dos 1000 pontos**. Detalhe em `scoring/SCORING.md §9.2`.

### 8.3 Custo e tempo — informativo

Each run records what producing the delivery cost (`cost_time`): tokens, the model's working time and a derived `usd_estimate`. Cheap matters as much as correct: the same TOTAL at 10× the cost is not equivalent. The time is the model's working time as the client reports it, **never the wall-clock**: in mode A the wall-clock includes every wait for the operator. **Outside the 1000 points.** Detail in `scoring/SCORING.md §9.3`.

### 8.4 Harness de avaliação

O pipeline (`protocol/PROTOCOL.md §5`) é implementado em [`harness/`](harness/), só-stdlib e agnóstico de instância:
- `leb_harness.py` roda os passos **mecânicos** (1–3, 6) e emite um relatório JSON;
- os passos com **juiz** (4–5) seguem [`scoring/JUDGE.md`](scoring/JUDGE.md) e produzem um veredito (`scoring/judge.schema.json`) — humano ou LLM, sempre auditável;
- `score.py` (passo 7) junta mecânico + veredito + matriz e monta o **scorecard oficial de 1000 pontos** de forma determinística (toda a aritmética de `scoring/SCORING.md`).

Falta só **executar modelos de verdade** para gerar os primeiros scorecards de referência.

---

## 9. Invariantes do padrão

1. IDs da taxonomia são **imutáveis** — nunca renumerar; depreciar e criar novo ID.
2. A matriz de uma instância publicada **NÃO DEVE** mudar; erros exigem nova versão da instância.
3. O enunciado dado ao modelo é **fixo e neutro** (`protocol/PROTOCOL.md §2`) — não pode vazar dicas da matriz.
4. A tarefa (enunciado + contrato de entrega) é **do padrão, não da instância**: vive só em `protocol/TAREFA.md` e **NÃO DEVE** ser reescrita, parafraseada ou estendida dentro de uma instância (`protocol/PROTOCOL.md §2.2`). Instância declara contrato de sistema (`manifest.md`); padrão declara tarefa. O padrão pode ter **várias versões da tarefa**: a 1.0.0 continua em `protocol/TAREFA.md`, intacta; as seguintes vivem em `protocol/tasks/`; cada instância declara a sua em `task_version` no cabeçalho da matriz (ausente = 1.0.0), e `harness/pack.py` usa exatamente essa. Comparações entre modelos exigem a mesma instância, o mesmo protocolo **e a mesma versão da tarefa**.
5. O contrato de entrega é **descritivo**: padroniza a forma da resposta e não cria critério, bônus nem penalidade — mudar formato de entrega não muda pontuação.
6. Todo resultado publicado **DEVE** citar: versão da spec, ID+hash da instância, protocolo do run (turnos, ferramentas, temperatura) e scorecard JSON.
7. Comparações entre modelos só são válidas **na mesma instância e mesmo protocolo**.
8. Uma instância **ativa** (`publication: "aggregate"` no cabeçalho da matriz) publica apenas o agregado por agente; entregas, vereditos e resultados por falha ficam em arquivo privado, e nada é liberado sem ordem expressa do operador, caso a caso — nem pela aposentadoria da instância (`matrix/MATRIX.md §4.6`).

---

## 10. Versionamento

A spec segue **SemVer**: MAJOR muda pontuação/regras; MINOR adiciona falhas/níveis; PATCH corrige texto. Instâncias são versionadas separadamente (`LEB-200-A v1.2`).

### Release notes for version 1.4.0

No change to points, penalties, IDs or published matrices (hence MINOR; instances 1.1.0 to 1.3.0, and every published LEB-100-A result,
remain valid without change; the 95 published scorecards are still reproduced by `harness/score.py`). Everything below is additive and
opt-in per instance:

- **Task versions** (§9.4) — an instance declares `task_version`; `protocol/TAREFA.md` stays 1.0.0, later versions live in
  `protocol/tasks/`. Task 1.1.0 lets the agent create, split, move and rename files while keeping the manifest.
- **Instance resolution** — an instance may live outside the repository (`LEB_INSTANCES_PATH`) in a split layout (`public/` for what the
  candidate receives, `private/`), and runs and packages go to `LEB_RUNS_DIR`. `harness/instances.py` is the one resolver.
- **Runner contract** — an instance may declare `private/runner.json`: the harness runs its characterization and verification commands
  and reads one JSON object from each. A reading that cannot be made (no JSON, a crash, a timeout) is **inconclusive** (exit 3), never
  an approval and never a regression; `score.py` refuses it. An instance without `runner.json` runs as before.
- **Structural evidence for Template R** — a probe with `result` and `proves: ["R3"]` caps the judge's R3 (`R3_final = min(judge, evidence)`).
  Evidence is a ceiling, never a floor, and it never fills R1, R2 or R4. Evidence that names another criterion is refused.
- **Informative fields** — `dimensions`, `decoy_kind` and `informative_affected` on matrix entries reach the scorecard and the exports
  only when declared; no weight, point or penalty reads them.
- **Package leak guard** — whole-word path matching, a content scan for markers derived from the matrix and for the private destination,
  an instance-declared `pack_allow_paths`, and a positive control on every packaging run.
- **Aggregate publication of an active instance** (§9.8, `matrix/MATRIX.md §4.6`).
- **Tooling tests** — `tests/run_all.py` (standard library, no network, no Docker) and a workflow that runs it on synthetic and public
  fixtures only. `tools/saturacao.py` measures saturation and dispersion, read-only.

Known and unchanged in this version: the §6.2 text on C4 as a global regression and the way `harness/score.py` applies C4 and PEN-002
are not the same rule; the difference is documented in §6.2 and in `scoring/SCORING.md §2`, and nothing is altered.

### Notas da versão 1.3.0

Sem mudança em pontos, IDs ou matrizes (por isso MINOR; instâncias 1.1.0/1.2.0 seguem válidas sem alteração):

- **Tarefa canônica** (`protocol/TAREFA.md`) — o enunciado neutro do `PROTOCOL §2` passa a viajar **dentro do pacote**, junto de `code/` + `manifest.md`, em vez de ser colado à mão pelo operador. Fonte única: o arquivo é do padrão e é o mesmo para todas as instâncias, presentes e futuras (§9.4).
- **Contrato de entrega** (`PROTOCOL §2.1`) — a tarefa declara os artefatos esperados: `code/` in-place, `RELATORIO.md` e o novo `achados.json` (`scoring/achados.schema.json`), que dá localização, categoria, severidade e confiança por achado. Descritivo, não pontuável (§9.5): torna o matching do passo 4 localizável sem mexer na aritmética do scorecard.
- **Vínculo do pacote** (`PROTOCOL §2.2`) — a `TAREFA.md` entregue carrega instância, versão, spec, hash da matriz e modo; o modelo repete o bloco no `achados.json`. Entrega com vínculo divergente não é comparável.
- **Empacotador** (`harness/pack.py`) — monta o pacote público, renderiza a tarefa, roda a varredura anti-vazamento e emite um `package_sha256` determinístico, agora entre os parâmetros do run (`PROTOCOL §3`).
- **Comando do operador** (`leb`) — um ciclo, quatro subcomandos, uma área de trabalho por instância (`runs/<ID>/`, com `pacote/` sendo a única pasta entregável). Conveniência: não altera nada de normativo.

### Notas da versão 1.2.0

Sem mudança em pontos, IDs ou matrizes (por isso MINOR; instâncias 1.1.0 seguem válidas sem alteração):
- **Custo e tempo** (§8.3) — bloco `cost_time` informativo no scorecard (`scoring/SCORING.md §9.3`).
- **Harness de avaliação** (§8.4) — `harness/leb_harness.py` implementa os passos mecânicos do pipeline (caracterização antes/depois, probes, eixo de dificuldade) e emite relatório JSON; `probes.php` ganhou saída JSON (`LEB_PROBE_JSON=1`). Os passos de juiz (4–5) e a normalização final continuam pendentes.

### Notas da versão 1.1.0

Duas métricas **informativas** que não alteram os 1000 pontos nem os IDs (por isso MINOR, retrocompatível):
- **Calibração** (§8.1) — confiança de 0–100 por achado no enunciado neutro; Brier score + diagrama de confiabilidade no scorecard (`scoring/SCORING.md §9.1`).
- **Eixo de dificuldade** (§8.2) — campo `difficulty` (Fácil/Moderada/Difícil/Especialista) por falha plantada, ortogonal à severidade; cobertura por dificuldade + `discovery_index` no scorecard (`scoring/SCORING.md §9.2`).

Instâncias que adotam a 1.1.0 passam a ratear `difficulty` em toda falha plantada (obrigatório no schema para `exists: true`). A instância de referência foi para **LEB-100-A v1.1** (só metadado de dificuldade; falhas, pontos e localizações intactos).

### Notas da versão 1.0.0

Formalizações além do rascunho original: templates C/R com tabelas por severidade (`scoring/SCORING.md §3`), normalização por categoria (§3), PEN-004 (falsos positivos contra iscas), desambiguação regressão local × global (§6.2), e protocolo de reprodutibilidade (mediana de 3 runs).
