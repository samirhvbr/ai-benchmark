# LEB — Protocolo de Execução

Normativo. Define como rodar o benchmark de forma justa e reproduzível. Resultado que não declara o protocolo usado **não é** um resultado LEB.

---

## 1. O que o modelo recebe

O **pacote público**, e só ele — três itens, montados por [`../harness/pack.py`](../harness/pack.py):

1. O código da instância (`code/`) e o manifesto de superfície pública (`manifest.md`); **nada de `private/`**.
2. `TAREFA.md` — o enunciado canônico (§2) mais o contrato de entrega (§2.1), renderizado de [`TAREFA.md`](TAREFA.md) e **vinculado à instância** (§2.2).
3. Nos níveis 300+, acesso de leitura ao repositório via ferramentas (listar/ler arquivos), registrado em log.

```sh
./leb pacote LEB-100-A                      # → runs/LEB-100-A/pacote/  (modo S)
./leb pacote LEB-100-A --mode A --turnos 20 # modo agêntico
```

O empacotador aborta se qualquer arquivo de gabarito cair no pacote, e imprime o `package_sha256`
— o pacote é determinístico (mesma instância + mesmo modo ⇒ bytes idênticos), então esse hash
identifica exatamente o que o modelo recebeu e **DEVERIA** ser registrado no run.

O modelo **NÃO** recebe: a matriz, os testes de `private/verify/`, dicas de categoria ("procure SQL injection"), nem contagem de falhas.

## 2. Enunciado canônico

O texto abaixo é fixo (traduzível, mas não parafraseável). Ele é deliberadamente **neutro**: menciona os eixos de avaliação sem apontar falha alguma.

> Você é responsável pela manutenção deste sistema legado em produção. Ele possui consumidores externos que dependem do comportamento atual, conforme o manifesto de superfície pública anexo.
>
> Sua tarefa:
> 1. Analise o código e reporte os problemas que encontrar (segurança, arquitetura, bugs, performance, qualidade), explicando cada um, sua severidade e uma **confiança de 0 a 100** de que o problema é real.
> 2. Corrija o que julgar que deve ser corrigido, preservando a compatibilidade declarada no manifesto.
> 3. Entregue: (a) o relatório técnico; (b) o código alterado (diff ou arquivos completos); (c) a justificativa das decisões, incluindo o que você decidiu NÃO mudar e por quê.
>
> Não reescreva o sistema. Evolua-o.

A confiança por achado (item 1) é **neutra** — não revela nada da matriz — e alimenta a métrica de calibração (`../scoring/SCORING.md §9.1`), que **não** afeta os 1000 pontos.

### 2.1 Contrato de entrega

O enunciado diz *o que* fazer; o contrato de entrega diz *em que formato devolver*, para que o
passo 4 do pipeline (§5) não dependa de adivinhar a estrutura da resposta. Ele vive na mesma
[`TAREFA.md`](TAREFA.md) e pede três artefatos:

| Artefato | Papel na avaliação |
| --- | --- |
| `code/` alterado in-place | passos 1–3 (mecânicos): COMP, caracterização, probes |
| `RELATORIO.md` | passos 4–5: mecanismo por achado (C2/R2) e rubrica EXPL |
| `achados.json` | passo 4: índice estruturado (arquivo, linha, categoria, severidade, confiança) que torna o matching localizável — formato em `../scoring/achados.schema.json` |

O contrato é **descritivo, não pontuável**: ele padroniza a forma da entrega e não cria critério,
bônus nem penalidade. Entrega sem `achados.json` continua válida — o juiz faz o matching a partir
do `RELATORIO.md`, com mais trabalho e mais variância. O contrato também **NÃO DEVE** mencionar
iscas, falsos positivos, contagem de falhas ou qualquer consequência de pontuação: isso mudaria o
comportamento do modelo e vazaria a estratégia anti-gaming (§6).

### 2.2 Vínculo com a instância

A `TAREFA.md` entregue abre com um cabeçalho que amarra a tarefa ao caso avaliado: instância,
nível, versão da instância, versão da spec, **SHA-256 da matriz** e modo de execução. O empacotador
tira esses dados do cabeçalho de `private/matrix.json` (nunca das falhas). O modelo copia o mesmo
bloco para dentro do `achados.json` — entrega cujo vínculo diverge foi feita contra outra versão da
instância e **não é** comparável às demais.

Consequência para quem escreve instâncias novas: o enunciado e o contrato de entrega **NÃO DEVEM**
ser reescritos dentro da instância. A instância declara o seu contrato de sistema no `manifest.md`;
a tarefa é do padrão, e é a mesma em toda instância que declara a mesma versão dela (`task_version` no
cabeçalho da matriz; ausente é a 1.0.0, que vive em `protocol/TAREFA.md`; as seguintes ficam em
`protocol/tasks/TAREFA-<versão>.md`). Um enunciado por instância destruiria a comparabilidade entre modelos
e entre casos.

## 3. Modos de execução

| Modo | Descrição | Uso |
| --- | --- | --- |
| **S** (single-turn) | 1 prompt → 1 resposta | LEB-100/200; mede capacidade bruta |
| **A** (agêntico) | multi-turno com ferramentas de leitura/execução, orçamento de N turnos declarado | LEB-300+; mede engenharia de verdade |

Parâmetros obrigatórios do run: modelo + versão exata, temperatura (oficial: a default do provedor, registrada), modo S/A, orçamento de turnos/tokens, data, instância + versão + hash da matriz + `package_sha256` do pacote entregue (§1).

Every run also records the client mode, when the client has one that changes how the model works (`model.client_mode`; Claude Code's multi-agent mode is `ultracode`): the same model at the same effort is a different agent in it, and is filed and shown as one.

Every run also records the size of the machine it ran on (`execution_host`: vCPUs and RAM). A client that runs several agents in parallel sizes its work to the machine, so for a multi-agent client the size is part of the run and all the runs of one agent keep the same size. A single-agent client spends most of a run waiting on the model's API; its runs may change machines, and the run's note records what changed.

**Clean machine, unprivileged user.** The agent runs as an ordinary user with no sudo, on a machine that carries nothing from an earlier run: no other run's folder, session log, temporary file, database or database user. The clean state is kept as a VM snapshot and restored before every run, and the delivery and the client's session log are copied off the machine before the next restore. Whatever the agent needs to test runs inside its own account: PHP's built-in server, and a private MariaDB it starts itself (the system database gives it no account). Runs made before this rule, on 2026-09-30, ran as the VM's administrator account with passwordless sudo, on a machine that kept earlier runs' leftovers; the evaluation notes say which runs those are and what the leftovers were.

**One model per run.** A run measures the model it is filed under. A client that can switch to another model on its own — after a safety classifier stops a response, on a rate limit, when a model is unavailable — runs with that switch turned off. If a switch happens anyway, the run is void when any part of the delivery (code, report or findings index) was written by the other model; Claude Fable 5.1's second run is the case. When the switch stayed inside auxiliary agents of a multi-agent client, and none of the delivery was written by them, the run stands: its note records which agents switched, to which model, and their share of the run's output tokens and cost.

Every run also records the model's training cutoff as its provider publishes it (`model.training_cutoff`, with `training_cutoff_source` pointing at the provider's page). It is `null` when the provider publishes none, never a third-party estimate. Against the day an instance's answer key became public, it says whether the model could have trained on that key (MATRIX §4). When the provider publishes no cutoff but does publish the model's release date (`model.release_date`, with `release_date_source` pointing at the provider's own announcement, release notes or model card), that date bounds the cutoff: a model cannot have trained on data that appeared after it was released. A release before the key went public therefore counts as `before`; a release on or after it says nothing, and the run stays `unknown`. Decided on 2026-10-05; until then a release date was not accepted in place of a cutoff.

**Operator replies (mode A).** The operator's first message is exactly *"Agora leia o TAREFA.md e execute. Devolva code/ alterado, RELATORIO.md e achados.json."*, the same for every run, with nothing added (`COMO-RODAR.md` repeats it). It is turn 1 of the declared budget. A check made before it that says nothing about the task, such as asking which model is answering, belongs in a separate session. If it happens in the run's own session, the run's note records the exchange and the run stands. Sometimes the agent stops and waits for a human before its delivery is complete: a question, a request for confirmation, a progress report that ends in a pause. Every time, the operator sends exactly this reply, the same to every agent:

> Não há ninguém para responder. Decida com o seu próprio critério e continue.

Each reply is one more turn of the budget, and `run.json` records how many were sent (`operator_replies`). Approving a client's tool-permission prompt is not a reply and is not counted, since it carries no content. The client should run with its tools pre-approved inside the VM, in a mode set before the first message and left alone until the run ends: switching a mode mid-run (Claude Code's plan mode, for one) puts instructions into the agent's context, and may hand turns to another model. A message with any other content voids the run: an answer, a hint, a correction. The evaluation notes record the voided run and why. A voided run may be replaced only while its delivery is unscored; replacing it after scoring is the selective retry §4 item 3 forbids. When the budget runs out, the delivery is whatever the agent has written by then.

## 4. Reprodutibilidade

1. Run oficial = **3 execuções independentes**; o scorecard oficial é a **mediana do TOTAL** (registrando as 3).
2. Logs completos (prompts, respostas, chamadas de ferramenta) arquivados junto do resultado.
3. Nenhum retry seletivo: descartar uma execução ruim e rodar de novo invalida o run.
4. Runs are published as they are scored, each in its own `run-<n>` folder, and never more than
   three per agent: a fourth would be the retry item 3 forbids. The published score is the
   **lower median** of the totals so far (`median_low`: the only total with one run, the lower of
   two, the median of item 1 with three). It is always the total of a run that exists, so the
   grade, categories and flaw-by-flaw published next to it are that run's, never a mix of runs.
   Fewer than three runs is not official.
5. A run counts only with its record complete: the client and its version, the first message,
   the session log and the cost (item 2). A run filed without them is **withdrawn**: its folder
   becomes `withdrawn-<n>`, with a `WITHDRAWN.md` that says what is missing, and it is kept for
   audit but neither published nor counted toward the three of item 4, so its agent may run again.
   The criterion is the record, never the result, and it applies to every run that fails it at
   once. A withdrawn run that held an agent's best total is withdrawn all the same, so this is not
   the selective retry of item 3. The three runs of LEB-100-A's first batch that were filed before
   session logs were kept are the case (2026-10-04).

## 5. Pipeline de avaliação

```text
entrega do modelo  (code/ + RELATORIO.md + achados.json — §2.1)
   │
   ├─ 1. diff da superfície pública ──────────► violações COMP-* (mecânico)
   ├─ 2. testes de caracterização (antes/depois) ► C4 regressão, PEN-002 (mecânico)
   ├─ 3. private/verify por falha ─────────────► C3/R3 corrigiu de fato (mecânico)
   ├─ 4. matching relatório × matriz ──────────► C1/C2, confiança por achado, iscas → PEN-004 (avaliador)
   ├─ 5. rubrica EXPL (juiz às cegas) ─────────► 0–50
   ├─ 6. calibração + dificuldade ─────────────► Brier, discovery_index (mecânico, informativo — SCORING §9)
   └─ 7. cálculo (SCORING.md) ─────────────────► scorecard .md + .json
```

O avaliador humano (ou LLM-juíza com rubrica) só atua nos passos 4–5; todo o resto é mecânico e re-executável por terceiros. O passo 6 (calibração e cobertura por dificuldade) é derivado dos passos 3–4 e **não** entra no TOTAL.

**Ferramentas:** o pacote entregue ao modelo sai de [`../harness/pack.py`](../harness/pack.py) (§1); os passos mecânicos 1–3 e 6 rodam em [`../harness/leb_harness.py`](../harness/leb_harness.py) (relatório mecânico JSON); os passos 4–5 seguem [`../scoring/JUDGE.md`](../scoring/JUDGE.md) e produzem um veredito (`../scoring/judge.schema.json`); o passo 7 é o montador [`../harness/score.py`](../harness/score.py), que junta mecânico + veredito + matriz e emite o scorecard oficial.

## 6. Anti-gaming

1. **Iscas** na matriz punem checklist recitado sem leitura (PEN-004).
2. **Enunciado neutro** impede fishing de categoria.
3. **Caracterização** pune o modelo que "conserta" reescrevendo (PEN-002/003, COMP-*).
4. Instâncias **expiram** ao virar provável corpus de treino (MATRIX.md §4).
5. O relatório precisa **explicar** (C2/R2, EXPL): acertar por sorte não escala pontos.

## 7. Publicação de resultados

Resultado publicado DEVE conter: scorecard (md+json), parâmetros do §3, hash da matriz, logs, e a versão da spec. Formato do scorecard: `../scoring/scorecard-template.md`.
