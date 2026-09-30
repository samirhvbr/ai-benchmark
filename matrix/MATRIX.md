# LEB — Matriz Oficial de Falhas

Normativo. **O coração do benchmark.**

A matriz é o **gabarito oculto** de uma instância LEB: a lista exata de falhas plantadas (e iscas), com severidade, pontos, localização e correção esperada. Ela transforma "avaliar código" — atividade subjetiva — em **conferência objetiva**: quais falhas o modelo encontrou, quais corrigiu corretamente, quais ignorou e quais **inventou**. É o que torna o LEB reproduzível e comparável entre LLMs.

---

## 1. Estrutura de uma instância

```text
LEB-200-A/                     ← instância "A" do nível 200
├── code/                      ← o sistema legado entregue ao modelo (público)
├── manifest.md                ← superfície pública declarada (público)
├── characterization/          ← testes de caracterização (público p/ avaliação; ver §5)
└── private/                   ← NUNCA publicado junto do código
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

## 4. Ocultação e integridade (anti-contaminação)

1. `private/` **NÃO DEVE** ser publicado no mesmo repositório do código da instância.
2. No lançamento da instância, publica-se o **SHA-256 de `matrix.json`** (commit assinado). Quando a instância for aposentada, a matriz é revelada e qualquer terceiro confere o hash — prova de que o gabarito não mudou depois dos resultados.
3. Instâncias **expiram**: uma vez públicas por tempo suficiente para entrar em corpus de treino, aposenta-se a instância (resultados antigos permanecem válidos, novos runs exigem instância vigente).
4. Runs oficiais **DEVEM** usar instância vigente e declarar seu hash no scorecard.
5. **Exception — LEB-100-A.** Its `private/` has been in the public repository since 2026-07-13,
   against item 1. It stays a current instance, and three runs of it are official, under two
   conditions:
   - every run executes on the isolated VM (README, *Execution environment*) and records which of
     its layers were in place. The first ten runs (2026-09-29) had GitHub's names blocked but not
     its addresses; runs from 2026-09-30 have both;
   - every run records the model's training cutoff as its provider publishes it (PROTOCOL §3).

   A cutoff after 2026-07-13, or none published, does not disqualify a run: its scorecard and the
   leaderboard say that the model may have trained on the key. Item 3 still decides when LEB-100-A
   is retired, and the cutoffs recorded run by run are the evidence for that decision.

## 5. Correspondência (matching) relatório × matriz

1. O avaliador mapeia cada achado do relatório do modelo para no máximo **uma** linha da matriz (a mais específica).
2. Localização confere com tolerância de ±10 linhas ou mesma função/método.
3. Achado real fora da matriz: 0 pontos, 0 penalidade, registrado para a próxima versão (`SCORING.md §3`).
4. Correções silenciosas (código corrigido sem constar no relatório) pontuam C3/C4/C5, mas **não** C1/C2 — o LEB exige que engenheira *comunique* o que fez.
5. Os testes de caracterização rodam **antes** (devem passar no legado intocado) e **depois** (medem regressão/compatibilidade); os testes de `private/verify/` conferem cada correção individual.
