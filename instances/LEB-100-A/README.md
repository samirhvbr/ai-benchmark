# LEB-100-A — Painel de Chamados (NetX ISP)

Instância de **referência** do LEB, nível **LEB-100** (~300 linhas). Um painel de chamados de suporte de um provedor de internet, escrito em PHP legado (estilo 2013): funções de acesso a dados + um `index.php` que roteia, autoriza e monta HTML.

| | |
| --- | --- |
| Versão | **v1.1** · spec **1.1.0** |
| Nível | LEB-100 (~300 linhas) |
| Stack | PHP 8 + mysqli + MySQL 8 |
| Falhas plantadas | **13** (SEC ×7, BUG ×2, PERF ×1, ARCH ×2, CLN ×1) |
| Dificuldade | Fácil ×6 · Moderada ×3 · Difícil ×4 · Especialista ×0 |
| Iscas | **2** (SEC-009, PERF-006) |
| Modo sugerido | **S** (turno único) — cabe numa janela de contexto |
| Published runs | mode **A**, 30 turns: `./leb pacote LEB-100-A --mode A --turnos 30` rebuilds their package byte for byte (SHA-256 `34e38bc50ede0134395ed893aecff56fe82930210578bcdac8c9f0830832a15f`, as in `results/results.json`) |
| Matriz (SHA-256) | `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625` |

## Estrutura

```text
code/                  ← entregue ao modelo
  config.php               credenciais e configuração
  lib.php                  autenticação, listagem, export, estatística
  index.php                dispatcher web + HTML
  schema.sql / seed.sql    banco (contexto + dados de teste)
manifest.md            ← entregue ao modelo: a superfície pública (contrato)
characterization/      ← testes de compatibilidade + docker (avaliador)
private/               ← GABARITO — nunca entregar
  matrix.json / matrix.md    a Matriz Oficial de Falhas
  verify/                    probes automatizados + roteiro de verificação
```

## O que entregar ao modelo

Apenas o **pacote público**: `code/` + `manifest.md` + a `TAREFA.md` gerada de
[`protocol/TAREFA.md`](../../protocol/TAREFA.md) — a tarefa vai **dentro** do pacote, não é
colada à mão. Nada de `characterization/` nem `private/`.

```sh
# da raiz do repo
./leb pacote LEB-100-A
# → runs/LEB-100-A/pacote/        ← mande esta pasta, e só ela
# → runs/LEB-100-A/COMO-RODAR.md  ← a receita do run, com os hashes já preenchidos
```

A `TAREFA.md` do pacote nasce vinculada a esta instância (id, nível, versão, spec, hash da
matriz, modo) e o modelo repete esse bloco no `achados.json` que devolve — é assim que se prova
contra qual versão do caso ele trabalhou ([`PROTOCOL §2.2`](../../protocol/PROTOCOL.md)).

## Como esta instância foi validada

Rodada de aceitação (ver [`characterization/README.md`](characterization/README.md) e [`private/verify/README.md`](private/verify/README.md)):

| | Caracterização | Probes automatizados |
| --- | --- | --- |
| **Código legado** | 22/22 ✅ | 4/4 **PLANTADA** ✅ |
| **Código corrigido** | 22/22 ✅ (sem regressão) | 4/4 **CORRIGIDA** ✅ |

Ou seja: a caracterização trava os contratos sem congelar as falhas, e os probes detectam tanto a presença quanto a correção de cada falha que cobrem.

## Destaques de avaliação

- **Pegadinha de compatibilidade:** migrar `mysqli → PDO` para "corrigir" a SQLi dispara `COMP-010` **e** `COMP-001` (muda a assinatura de todas as funções públicas de `lib.php`). A correção certa vive dentro do mysqli.
- **IDOR sem quebrar contrato:** a correção de `SEC-017` deve autorizar no dispatcher, preservando `verChamado(mysqli,int)` — passar `$uid` para a função é `COMP-001`.
- **Iscas:** `SEC-009` (não há `exec`/`shell`) e `PERF-006` (o schema já tem índices; a busca é `LIKE '%x%'`, não acelerável por índice). Reportá-las é `PEN-004`.

## Regenerar o hash da matriz

```sh
sha256sum private/matrix.json
# v1.1 → 68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625
```

Publica-se **apenas** esse hash no lançamento; a matriz é revelada quando a instância é aposentada (`../../matrix/MATRIX.md §4`). O hash da **v1.0** (`0de41e0…7971`) permanece válido para resultados publicados contra a v1.0 — a v1.1 só acrescentou o metadado de dificuldade (ver changelog em `private/matrix.md`).
