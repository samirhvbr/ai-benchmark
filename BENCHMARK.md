# LEB — Guia de Execução (BENCHMARK)

> **Guia prático do operador.** Como rodar um modelo no LEB, passo a passo, sem
> vazar o gabarito. Complementa os documentos normativos — quando houver conflito,
> vale o normativo:
> [`SPEC.md`](SPEC.md) · [`protocol/PROTOCOL.md`](protocol/PROTOCOL.md) · [`scoring/SCORING.md`](scoring/SCORING.md) · [`scoring/JUDGE.md`](scoring/JUDGE.md).

---

## Não quero ler este guia (o caminho curto)

```sh
./leb pacote LEB-100-A
```

Ele monta `runs/LEB-100-A/pacote/` — **essa** é a pasta que você manda para o agente, com o
código, o manifesto e a `TAREFA.md` dentro. E escreve `runs/LEB-100-A/COMO-RODAR.md`: a receita
do run com os caminhos já preenchidos, o que anotar e os comandos seguintes. Enquanto o agente
trabalha, é esse arquivo que você lê — não este guia.

Depois que ele devolver, em `runs/LEB-100-A/<modelo>-1/entrega/`:

```sh
./leb avaliar   LEB-100-A runs/LEB-100-A/<modelo>-1/entrega   # harness mecânico
./leb scorecard LEB-100-A <modelo>-1 --veredito veredito.json # depois do juiz
./leb estado                                                  # onde cada run parou
```

O resto deste documento explica **por quê** cada passo existe — leitura de uma vez só, não de
toda vez.

---

## TL;DR — o ciclo em uma frase

Você entrega ao modelo **só o código legado + o manifesto público + a tarefa
canônica**; ele devolve **relatório + código alterado + achados estruturados**; você
mede essa entrega contra um **gabarito secreto** (a Matriz) com o harness + juiz e sai
um **scorecard de 0 a 1000**. Oficial = **mediana de 3 execuções**.

```text
   VOCÊ ENTREGA                    O MODELO DEVOLVE               VOCÊ AVALIA
┌────────────────────┐        ┌────────────────────────┐     ┌──────────────────┐
│ code/              │        │ (a) RELATORIO.md       │     │ harness (mecânico)│
│ manifest.md        │  ───►  │ (b) code/ alterado     │ ──► │ juiz (matriz+EXPL)│
│ TAREFA.md          │        │ (c) achados.json       │     │ score.py → 1000   │
└────────────────────┘        └────────────────────────┘     └──────────────────┘
 ./leb pacote monta                                              usa private/ (gabarito)
```

---

## A regra de ouro: o "plano" nunca vai junto

O que você chama de **"o plano"** é a **Matriz Oficial de Falhas** — o gabarito que
diz exatamente quais falhas existem, onde, com que severidade, e quais linhas são
**iscas** (falhas plausíveis que **não** existem). Ela vive em `private/` e é **o
coração do benchmark** ([SPEC §5](SPEC.md)). Se o modelo vê a matriz, o resultado
não vale nada.

Por isso a separação **público × privado** ([instances/README.md](instances/README.md)):

| Pasta / arquivo | Vai para o modelo? | Papel |
| --- | :---: | --- |
| `code/` | ✅ **SIM** | o sistema legado com as falhas plantadas |
| `manifest.md` | ✅ **SIM** | a superfície pública (o contrato que não pode quebrar) |
| `TAREFA.md` (de [protocol/TAREFA.md](protocol/TAREFA.md)) | ✅ **SIM** | a tarefa: enunciado neutro e fixo + o formato da entrega |
| `characterization/` | ❌ **NÃO** | testes de compatibilidade — só o **avaliador** usa |
| `private/matrix.json` · `matrix.md` | ❌ **NUNCA** | **o gabarito** (a matriz) |
| `private/verify/` (probes/exploits) | ❌ **NUNCA** | roteiro de verificação por falha |

O modelo também **NÃO** recebe: dicas de categoria ("procure SQL injection"), a
**contagem** de falhas, nem qualquer coisa derivada da matriz ([PROTOCOL §1](protocol/PROTOCOL.md)).

---

## O que você manda para a IA (o ponto central)

São **duas coisas** — e só elas:

### 1. O pacote público (arquivos)

Um comando, a partir da raiz do repositório:

```sh
./leb pacote LEB-100-A                      # modo agêntico: --mode A --turnos 20
```

```text
[pack] instância LEB-100-A v1.1 · spec 1.1.0 · tarefa 1.0.0 · modo S
[pack] matriz  68088abd…c8625
[pack] pacote  6d97bda5…ca44c  (7 arquivos) → runs/LEB-100-A/pacote

Mande esta pasta para o agente:
    runs/LEB-100-A/pacote
```

A pasta é sempre a mesma para a instância (`runs/<ID>/pacote/`) e é **refeita** a cada
`./leb pacote` — não precisa lembrar de caminho nem limpar nada. É o **único** conteúdo de
arquivos que o modelo pode enxergar.

Por baixo, [`harness/pack.py`](harness/pack.py) copia `code/` + `manifest.md`, renderiza a
`TAREFA.md` vinculada à instância e varre o resultado: se qualquer arquivo de gabarito cair no
pacote, ele **apaga a pasta** e sai com erro — a rede de segurança que substitui o antigo
`cp -r` + `find` manual. O pacote é determinístico: mesma instância + mesmo modo ⇒ mesmo
`package_sha256`, em qualquer máquina. Guarde esse hash; ele entra no run (já vem preenchido
no `COMO-RODAR.md`).

### 2. A tarefa (já vai dentro do pacote)

Antes, o enunciado era colado à mão no chat — e cada operador colava um pouquinho diferente.
Agora ele viaja como `TAREFA.md` **dentro** do pacote, gerado de
[`protocol/TAREFA.md`](protocol/TAREFA.md): mesma tarefa para toda instância, sem retrabalho e
sem variação entre runs. A mensagem para o modelo pode ser só *"leia TAREFA.md e execute"*.

A `TAREFA.md` tem três partes:

**(a) O cabeçalho de vínculo** — instância, nível, versão, spec, hash da matriz e modo. É o que
prova contra qual versão do caso o modelo trabalhou ([PROTOCOL §2.2](protocol/PROTOCOL.md)).

**(b) O enunciado canônico** — fixo, **neutro** e não-parafraseável (traduzível, mas não
reescrevível); cita os eixos de avaliação **sem apontar nenhuma falha**
([PROTOCOL §2](protocol/PROTOCOL.md)):

> Você é responsável pela manutenção deste sistema legado em produção. Ele possui
> consumidores externos que dependem do comportamento atual, conforme o manifesto de
> superfície pública anexo.
>
> Sua tarefa:
> 1. Analise o código e reporte os problemas que encontrar (segurança, arquitetura,
>    bugs, performance, qualidade), explicando cada um, sua severidade e uma
>    **confiança de 0 a 100** de que o problema é real.
> 2. Corrija o que julgar que deve ser corrigido, preservando a compatibilidade
>    declarada no manifesto.
> 3. Entregue: (a) o relatório técnico; (b) o código alterado (diff ou arquivos
>    completos); (c) a justificativa das decisões, incluindo o que você decidiu NÃO
>    mudar e por quê.
>
> Não reescreva o sistema. Evolua-o.

Por que é neutro (e por que isso importa): mencionar "procure SQLi" ou "há 13 falhas"
seria **fishing de categoria** e vazaria a matriz — o enunciado neutro é uma das
defesas anti-gaming do padrão ([PROTOCOL §6](protocol/PROTOCOL.md)). A **confiança de
0 a 100** por achado é deliberadamente neutra (não revela nada da matriz) e só
alimenta a métrica de calibração, que **não** entra nos 1000 pontos ([SPEC §8.1](SPEC.md)).

**(c) O contrato de entrega** — os nomes e o formato dos artefatos que você espera de volta:
`code/` alterado in-place, `RELATORIO.md` (prosa, com mecanismo e confiança por achado) e
`achados.json` (índice estruturado: arquivo, linha, categoria, severidade, confiança). Isso
existe para o **passo 5** não virar arqueologia: com linha e arquivo declarados, o matching
contra a matriz é direto ([PROTOCOL §2.1](protocol/PROTOCOL.md)).

O contrato é **descritivo**: não cria critério, bônus nem penalidade, e não menciona iscas,
falsos positivos nem contagem de falhas — mencionar mudaria o comportamento do modelo e
vazaria a estratégia anti-gaming. Entrega sem `achados.json` continua válida; só dá mais
trabalho ao juiz.

### 3. Nada mais

Sem system prompt que injete pistas, sem "dica de amigo", sem link para este
repositório (o repo tem a taxonomia e o formato da matriz). Se o modelo tiver
ferramentas de leitura (modo agêntico), aponte-as **só** para `runs/<ID>/pacote/` — nunca
para a árvore da instância, senão ele pode `ler private/`.

---

## Passo a passo completo

### Passo 0 — Pré-requisitos

- `git pull` (o repo pede isso no topo do README).
- **Docker** (a caracterização sobe MySQL 8 + PHP 8.4 via `characterization/docker-compose.yml`).
- **Python 3** (stdlib apenas; o harness não tem dependências externas).
- Escolha o modo ([PROTOCOL §3](protocol/PROTOCOL.md)):
  - **Modo S** (turno único: 1 prompt → 1 resposta) — para LEB-100/200. É o sugerido
    para a instância de referência **LEB-100-A**, que cabe numa janela de contexto.
  - **Modo A** (agêntico: multi-turno com ferramentas de leitura/execução, orçamento
    de N turnos declarado) — para LEB-300+.

### Passo 1 — Escolher a instância

Use uma instância **ativa** (não aposentada). A de referência é
[`instances/LEB-100-A`](instances/LEB-100-A/) — painel PHP legado, 13 falhas + 2 iscas,
modo S. Anote o **hash SHA-256 da matriz** do README da instância: ele entra no
resultado publicado e prova qual gabarito foi usado.

### Passo 2 — Montar o pacote público

```sh
./leb pacote LEB-100-A
```

Sai em `runs/LEB-100-A/pacote/`, junto de um `COMO-RODAR.md` com a receita deste run
(caminhos, o que anotar, comandos seguintes) — daqui em diante é ele que você lê.

### Passo 3 — Entregar ao modelo e coletar a entrega

Entregue a pasta `pacote/` — a tarefa já está dentro dela, como `TAREFA.md`. A mensagem pode
ser só *"leia o TAREFA.md e execute"*. Registre os parâmetros obrigatórios do run
([PROTOCOL §3](protocol/PROTOCOL.md)): modelo + versão exata, temperatura (oficial = a default
do provedor, registrada), modo S/A, orçamento de turnos/tokens, data, instância + versão +
hash da matriz + `package_sha256` — a tabela do `COMO-RODAR.md` já vem com os três últimos
preenchidos.

**If the agent stops and waits for you** (mode A), send only the fixed reply of
[PROTOCOL §3](protocol/PROTOCOL.md), verbatim, every time, until the delivery is complete:
*"Não há ninguém para responder. Decida com o seu próprio critério e continue."* Count how many times you sent it; `run.json` records the count as `operator_replies`.
Anything else voids the run: answering the question, a hint, a correction. Approving a
tool-permission prompt is not a reply.

**When the session ends**, before closing the client, save its usage summary as printed
(Claude Code: `/cost`) to `custo.txt` next to `entrega/`. Tokens, the model's working time and
the cost come from there ([SCORING §9.3](scoring/SCORING.md)), never from a wall-clock you
measure: that one includes every wait for your reply.

Guarde a **entrega** em `runs/<ID>/<modelo>-<n>/entrega/`, nos nomes que a tarefa pediu:
- **`RELATORIO.md`** — os achados explicados (mecanismo, severidade, confiança 0–100) e a
  seção de decisões, com o que ele resolveu **não** mudar;
- **`code/`** — o legado alterado *in-place*; você quer a pasta completa de volta;
- **`achados.json`** — o índice estruturado ([schema](scoring/achados.schema.json)). Confira o
  bloco `leb`: se o vínculo divergir do pacote que você mandou, o run não é comparável.

Uma pasta por execução (`-1`, `-2`, `-3`): são 3 execuções independentes, sem retry seletivo.
O `./leb avaliar` do passo seguinte já valida o `achados.json` e avisa se faltar relatório.

Guarde os **logs completos** (prompts, respostas, chamadas de ferramenta) junto do
resultado ([PROTOCOL §4](protocol/PROTOCOL.md)).

### Passo 4 — Avaliação mecânica (harness)

Roda os passos objetivos e re-executáveis do pipeline (caracterização antes/depois,
probes de correção, cobertura por dificuldade, timing) e cospe um relatório JSON:

```sh
./leb avaliar LEB-100-A runs/LEB-100-A/<modelo>-1/entrega
# → runs/LEB-100-A/<modelo>-1/mecanico.json
```

Equivale a `python3 harness/leb_harness.py --instance … --submission … --out …`, com os
caminhos resolvidos e a entrega conferida antes (JSON válido? tem relatório?).
A entrega é montada **read-only** no container; nada é copiado para dentro do repo.
**Exit 2** = a entrega regrediu (quebrou caracterização → sinal para CI); **exit 0** =
sem regressão. Detalhes e contrato de saída em [`harness/README.md`](harness/README.md).

> Autoteste opcional (sanidade): rodar sem `--submission` avalia o próprio legado —
> esperado tudo **PLANTADA**, sem regressão.

### Passo 5 — Juiz (matriz + explicação)

Os dois passos que precisam de julgamento (humano OU LLM-juíza) seguem
[`scoring/JUDGE.md`](scoring/JUDGE.md) e produzem um **veredito JSON**
(`scoring/judge.schema.json`):
- **matching relatório × matriz** — o que o modelo achou/explicou de verdade; iscas
  reportadas viram **PEN-004**; falsos positivos idem;
- **rubrica EXPL** — qualidade da explicação, avaliada **às cegas** (0–50).

O juiz **só** atua aqui; todo o resto é mecânico. A evidência mecânica tem prioridade
(C3 das falhas com probe e C4 de regressão sobrescrevem o juiz).

### Passo 6 — Montar o scorecard (score.py)

Determinístico: junta mecânico + veredito + matriz e aplica toda a aritmética do
[`SCORING.md`](scoring/SCORING.md) (pontos por critério, normalização por categoria,
COMP, penalidades, TOTAL, selo, Brier, eixo de dificuldade):

```sh
./leb scorecard LEB-100-A <modelo>-1 --veredito runs/LEB-100-A/<modelo>-1/veredito.json
# → runs/LEB-100-A/<modelo>-1/scorecard.json      (opcional: --custo cost_time.json)
```

Saída no formato de [`scoring/scorecard-template.md`](scoring/scorecard-template.md)
(+ JSON). Inclui os blocos **informativos** que não pontuam: calibração (Brier),
cobertura por dificuldade e `cost_time` (tokens, tok/s, US$/run, and the model's working time
as the client reports it, never the wall-clock).

### Passo 7 — Mediana de 3 (resultado oficial)

Um **run oficial = 3 execuções independentes**; a nota oficial é a **mediana do
TOTAL** (registrando as 3). **Proibido retry seletivo**: descartar uma execução ruim e
rodar de novo invalida o run ([PROTOCOL §4](protocol/PROTOCOL.md)). Repita os passos
3–6 três vezes — `./leb estado` mostra onde cada uma parou:

```text
LEB-100-A  (runs/LEB-100-A)
  pacote     montado
  opus5-1    mecânico · scorecard  TOTAL 860
  opus5-2    mecânico · —
```

### Passo 8 — Publicar

O resultado publicado **DEVE** conter ([PROTOCOL §7](protocol/PROTOCOL.md)): scorecard
(`.md` + `.json`), os parâmetros do Passo 3, o **hash da matriz**, os logs e a **versão
da spec**. Comparações entre modelos só valem **na mesma instância e mesmo protocolo**.
A pasta `runs/` inteira é **gitignored** — pacotes, entregas e scorecards não sobem para o
repo público junto do código.

---

## Checklist anti-vazamento (antes de apertar "enviar")

- [ ] O pacote saiu do `./leb pacote` (sem erro) e tem **só** `code/` + `manifest.md` + `TAREFA.md`?
- [ ] Mandei a pasta `runs/<ID>/pacote/` — e **não** a pasta `runs/<ID>/`, que tem o `COMO-RODAR.md`?
- [ ] **Nenhum** arquivo `matrix*`, `private/`, `verify/` ou `characterization/` no que o modelo vê?
- [ ] A `TAREFA.md` do pacote é a gerada — **não** editei o texto para "ajudar" o modelo?
- [ ] Sem system prompt / contexto extra injetando pistas?
- [ ] Em modo agêntico: as ferramentas de leitura estão **presas ao pacote**, sem acesso à instância nem a este repo?
- [ ] Anotei modelo+versão, temperatura, modo, orçamento, data, **hash da matriz** e **`package_sha256`**?

---

## Armadilhas que o benchmark planta de propósito

Estas não são bugs do processo — são o teste funcionando. Não "ajude" o modelo a evitá-las.

- **mysqli → PDO** para "consertar" a SQLi: dispara `COMP-010` **e** `COMP-001` (muda a
  assinatura de todas as funções públicas de `lib.php`). A correção certa vive **dentro
  do mysqli**. No LEB-100-A essa reescrita pontua **0 / Reprovada**.
- **IDOR (SEC-017):** a correção deve autorizar **no dispatcher**, preservando
  `verChamado(mysqli, int)`. Passar `$uid` para a função é `COMP-001`.
- **Iscas** (`SEC-009`, `PERF-006` na LEB-100-A): não existem no código. Reportá-las com
  confiança alta é **PEN-004** e machuca a calibração. É exatamente o que pega o modelo
  que recita checklist sem ler o código.
- **Reescrever tudo** para "modernizar": `PEN-003` + provável enxame de `PEN-002`
  (regressões). O padrão penaliza fuga por reescrita — *evoluir* é o objetivo.

---

## Referência rápida de comandos

```sh
# 1) montar o pacote (code/ + manifest.md + TAREFA.md, com anti-vazamento embutido)
./leb pacote LEB-100-A                    # → runs/LEB-100-A/pacote/ + COMO-RODAR.md

# 2) (você manda runs/LEB-100-A/pacote/ ao agente — a tarefa está dentro — e salva a
#     entrega em runs/LEB-100-A/<modelo>-1/entrega/: code/ + RELATORIO.md + achados.json)

# 3) avaliação mecânica
./leb avaliar LEB-100-A runs/LEB-100-A/<modelo>-1/entrega

# 4) juiz → veredito JSON (segue scoring/JUDGE.md)

# 5) scorecard final de 1000 pontos
./leb scorecard LEB-100-A <modelo>-1 --veredito runs/LEB-100-A/<modelo>-1/veredito.json

# repetir 2–5 três vezes → nota oficial = mediana do TOTAL
./leb estado LEB-100-A
```
