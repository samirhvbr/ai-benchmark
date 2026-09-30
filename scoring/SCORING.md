# LEB — Modelo de Pontuação

Normativo. Complementa `../SPEC.md` §3–§6.

---

## 1. Severidades e valor-base por falha

Cada falha plantada recebe uma severidade na matriz da instância, que define seu valor-base:

### Template C — Correção (SEC, PERF, BUG)

| Critério | Crítica (10) | Alta (8) | Média (6) | Baixa (5) |
| --- | ---: | ---: | ---: | ---: |
| C1 Encontrou | 2 | 2 | 1 | 1 |
| C2 Explicou corretamente | 2 | 1 | 1 | 1 |
| C3 Corrigiu | 3 | 3 | 2 | 1 |
| C4 Não introduziu regressão | 2 | 1 | 1 | 1 |
| C5 Manteve compatibilidade | 1 | 1 | 1 | 1 |

### Template R — Refatoração (ARCH, CLN)

| Critério | Crítica (12) | Alta (10) | Média (8) | Baixa (6) |
| --- | ---: | ---: | ---: | ---: |
| R1 Identificou | 3 | 2 | 2 | 1 |
| R2 Explicou | 2 | 2 | 1 | 1 |
| R3 Refatorou | 5 | 4 | 4 | 3 |
| R4 Compatível | 2 | 2 | 1 | 1 |

---

## 2. Definição operacional de cada critério

**C1/R1 — Encontrou/Identificou.** O relatório do modelo aponta a falha certa no lugar certo (mesmo ID lógico + arquivo; tolerância de ±10 linhas ou mesma função). Nomear a classe da falha errada mas apontar o trecho certo = metade dos pontos do critério (arredonda p/ baixo).

**C2/R2 — Explicou.** A explicação descreve o mecanismo real da falha (por que é explorável / por que degrada / por que quebra), não uma descrição genérica da categoria. Explicação incorreta ou boilerplate = 0.

**C3 — Corrigiu.** A correção elimina a falha no ponto plantado **e** resiste ao teste de verificação da instância (exploit deixa de funcionar, query deixa de ser N+1, etc.). Correção parcial (mitiga mas não elimina) = metade, arredonda p/ baixo.

**R3 — Refatorou.** A estrutura-alvo declarada na matriz foi alcançada (ex.: God Object decomposto com responsabilidades separadas). Refatoração cosmética (renomear, mover sem separar responsabilidade) = 0. Parcial genuíno = metade.

**C4 — Não introduziu regressão.** Os testes de caracterização que cobrem o entorno da correção continuam verdes. Binário: qualquer teste do entorno quebrado = 0 no critério.

**C5/R4 — Manteve compatibilidade.** A correção/refatoração não disparou nenhuma violação `COMP-*` *atribuível a ela*. Binário. (A violação COMP em si também desconta da categoria COMP — o fato é um só, mas fere dois contratos distintos: o item deixa de ser "correção completa" e a conduta global fica manchada. Isso é intencional e documentado.)

---

## 3. Falhas não plantadas (iscas) e falsos positivos

- Reportar falha marcada "Existe: Não" na matriz → **PEN-004** (−5 cada, teto −25 por run).
- Reportar falha **real porém fora da matriz** (achado legítimo que os autores não plantaram): 0 pontos, 0 penalidade — e DEVERIA ser registrada para a próxima versão da instância.
- O avaliador decide "isca vs achado legítimo" **antes** de olhar o scorecard, para não enviesar.

---

## 4. Normalização por categoria

Os valores-base (§1) são pesos relativos **dentro** da categoria. A nota da categoria é:

```text
nota_categoria = round( (pontos_obtidos / pontos_possíveis_na_matriz) × peso_categoria )
```

Exemplo: instância LEB-200 planta falhas SEC somando 100 pontos-base; o modelo obtém 84 → `84/100 × 250 = 210/250`.

Isso garante que **toda instância vale exatamente 1000**, independentemente de quantas falhas foram plantadas, e mantém scorecards comparáveis entre instâncias do mesmo nível.

## 5. Compatibilidade (categoria de conduta)

```text
nota_COMP = max(0, 100 − Σ descontos COMP-* )
```

Cada violação conta **por ocorrência** (mudar a assinatura de 3 métodos públicos = 3 × −30, piso 0). Tabela de descontos em `../SPEC.md §6.1` e definições em `../taxonomy/COMP.md`.

## 6. Explicação Técnica (50 pontos)

Rubrica de 5 dimensões × 10 pontos, avaliada sobre o relatório final do modelo:

| Dimensão | 0 | 4 | 7 | 10 |
| --- | --- | --- | --- | --- |
| Clareza | incompreensível | desorganizado | legível | impecável, direto |
| Precisão técnica | erros graves | imprecisões | correto | correto e profundo |
| Causa-raiz | só sintomas | superficial | causa real | causa + mecanismo |
| Priorização | nenhuma | lista plana | por severidade | severidade × esforço × risco |
| Trade-offs | ausentes | menção vaga | reais | alternativas comparadas |

Avaliação por humano ou LLM-juíza **com esta rubrica e âncoras**; o juiz NÃO vê o scorecard das outras categorias.

## 7. Total

```text
TOTAL = Σ notas_categorias + nota_COMP + nota_EXPL − Σ PEN-*      (piso 0, teto 1000)
```

## 8. Classificação final (informativa)

| Faixa | Selo |
| ---: | --- |
| 900–1000 | **LEB Platinum** — pronta para legado crítico |
| 750–899 | **LEB Gold** — engenharia sólida |
| 600–749 | **LEB Silver** — útil com supervisão |
| 400–599 | **LEB Bronze** — requer revisão integral |
| < 400 | **Reprovada** — risco ao sistema |

## 9. Métricas informativas (NÃO entram no TOTAL)

As duas métricas abaixo enriquecem o scorecard sem alterar os 1000 pontos (§7). Elas descrevem *como* um modelo acerta — nunca inflam nem descontam a nota. Podem servir de **critério de desempate** entre modelos de TOTAL igual e como sinal de maturidade.

### 9.1 Calibração (confiança por achado)

O protocolo (`../protocol/PROTOCOL.md §2`) pede que o modelo atribua a **cada problema reportado** uma confiança inteira de **0 a 100**. Sobre o conjunto de achados reportados, define-se o *acerto* de cada um:

- casa uma falha plantada (`exists: true`) → acerto = 1 (verdadeiro-positivo);
- casa uma isca (`exists: false`) ou é invenção pura → acerto = 0 (falso-positivo).

Falhas plantadas que o modelo **não** reportou (falso-negativo) já valem 0 em C1/R1 e **não** entram no conjunto de calibração — calibração mede convicção sobre o que foi *afirmado*.

**Métrica principal — Brier score:**

```text
brier = média_i ( (confiança_i/100 − acerto_i)² )      # 0 = perfeito · 1 = péssimo
```

Um modelo bem calibrado dá confiança alta ao que acerta e baixa ao que erra. Chutar tudo com 99% e acertar metade ⇒ Brier alto.

**Diagrama de confiabilidade (complemento):** agrupar os achados por faixa de confiança (`0-20 · 21-40 · 41-60 · 61-80 · 81-100`) e comparar a confiança média de cada faixa com a taxa de acerto real. Sinal legível: `high_conf_false_positive_rate` = fração dos achados com confiança ≥ 80 que eram falso-positivo (mede excesso de confiança).

Não pontua. Um modelo que *sabe o que não sabe* é mais útil em produção que um que chuta com convicção — e isso passa despercebido no TOTAL.

### 9.2 Eixo de dificuldade (discoverability)

Cada falha plantada carrega, além da severidade, uma **dificuldade** (`../matrix/matrix.schema.json`): quão difícil é *achar* a falha — **ortogonal ao impacto**. Uma SQLi por concatenação é Crítica porém Fácil; um leak no caminho de erro é só Média, mas Difícil. Dificuldade **não** altera os pontos (esses vêm só da severidade, §1).

| Dificuldade | Âncora operacional |
| --- | --- |
| **Fácil** | visível na leitura direta de um trecho; padrão canônico que um linter básico ou dev júnior atento pega (concatenação SQL, `md5()` de senha, segredo literal) |
| **Moderada** | exige seguir o fluxo de dados ou reconhecer um caso-limite (N+1 num loop, divisão por conjunto vazio); dev pleno pega em revisão cuidadosa |
| **Difícil** | falha por *ausência* (algo que deveria existir e não está) ou caminho não óbvio (ramo de erro, autorização, sessão); passa batido em revisão normal (session fixation, IDOR, leak no erro, CSV injection) |
| **Especialista** | edge-case sutil, concorrência ou raciocínio sobre invariantes de domínio; a maioria dos revisores humanos erra (raro abaixo do LEB-300) |

O scorecard reporta, por dificuldade, **plantadas × detectadas (C1/R1 ≥ metade) × corrigidas (C3/R3)** — o "quantas difíceis achou". E um índice informativo:

```text
discovery_index = 100 × Σ(peso_d × detectadas_d) / Σ(peso_d × plantadas_d)
                  # pesos: Fácil 1 · Moderada 2 · Difícil 3 · Especialista 4
```

Dois modelos com o mesmo TOTAL mas `discovery_index` distintos têm perfis diferentes: um varreu o óbvio, o outro chegou nas armadilhas. Também não pontua.

### 9.3 Cost and time (of the model's run)

Each run records the **cost of producing the delivery** (`cost_time`, shaped by the block in `../scoring/scorecard.schema.json`): `tokens` (input/output/cache/total), `elapsed_seconds`, `output_tokens_per_second`, `finish_reason`/`status`, and a `usd_estimate` **derived** from tokens × the provider's price table. Cheap matters as much as correct: two models with the same TOTAL and 10× apart in US$ per run are not equivalent in practice.

- **Where the numbers come from.** They come from the client's own usage summary at the end of the session, for example Claude Code's `/cost`. That summary is saved as printed, as `custo.txt` next to `entrega/`, and `source` names it. The agent's own account of its time is not used, since no one can check it.
- **Which time.** `elapsed_seconds` is the model's working time as the client reports it, for example Claude Code's "Total duration (API)". It is **never the wall-clock**: in mode A the wall-clock includes every wait for the operator (PROTOCOL §3), which measures the operator, not the model. When a client reports only wall-clock, `elapsed_seconds` is `null`.
- **Which figure compares best.** Tokens. Time also depends on the provider's load and infrastructure, so across providers it is a rough figure.

It measures the **model's run**, not the harness: the harness report's `timing_s` is the time of the evaluation's docker phases, a different thing. It **does not enter the TOTAL**. It is informative and serves as a tie-breaker and a cost/benefit reading. A run that did not keep the summary records `cost_time: null`, never an estimate.
