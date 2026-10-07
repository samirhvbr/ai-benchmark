# LEB — LLM Engineering Benchmark

> ⚠️ **Antes de mexer neste repositório: `git pull`.**

🇺🇸 [English version](README.md)

**Um padrão de avaliação de Engenharia de Software para LLMs.** Versão da spec: **1.4.0**.

O LEB não é um benchmark de prompts e não mede quem escreve o código mais bonito. Ele mede **quem consegue evoluir um sistema legado sem quebrá-lo** — encontrando falhas reais, corrigindo-as, preservando compatibilidade e explicando as decisões como uma engenheira sênior.

## Por que outro benchmark?

Benchmarks existentes medem geração de código do zero ou resolução de issues isoladas. Nenhum mede o trabalho que domina a engenharia da vida real: **manutenção de legado com consumidores dependendo do comportamento atual**. O LEB pontua engenharia, compatibilidade, arquitetura, segurança, performance e maturidade técnica — e **desconta** pontos de quem reescreve tudo, troca tecnologia sem necessidade ou quebra contrato público.

## Como funciona (60 segundos)

1. O modelo recebe um **sistema legado real** (com falhas plantadas) + a [tarefa canônica](protocol/TAREFA.md) — enunciado neutro e formato de entrega, iguais para toda instância.
2. Ele reporta, corrige e justifica — sem saber quais nem quantas falhas existem.
3. A entrega é conferida contra a **[Matriz Oficial de Falhas](matrix/MATRIX.md)** — um gabarito oculto que declara exatamente o que existe (e o que é isca). É isso que torna a avaliação objetiva, reproduzível e comparável entre LLMs: verifica-se exatamente quais falhas o modelo encontrou, corrigiu, ignorou — e quais *inventou*.
4. Sai um **[scorecard](scoring/scorecard-template.md)** de 0 a 1000.

| Categoria | Peso |
| --- | ---: |
| Segurança (SEC) | 250 |
| Arquitetura (ARCH) | 200 |
| Bugs (BUG) | 150 |
| Performance (PERF) | 150 |
| Clean Code (CLN) | 100 |
| Compatibilidade (COMP) | 100 |
| Explicação Técnica (EXPL) | 50 |

Compatibilidade é **conduta**: começa em 100 e cada violação desconta (mysqli→PDO sem necessidade: **−20**; mudar assinatura pública: **−30**). Penalidades gerais (novo bug, regressão, rewrite desnecessário, falso positivo contra iscas) descontam do total — o modelo não "ganha" jogando tudo fora e começando do zero.

## Modelo de pontuação

Cada falha plantada é pontuada por critérios independentes e cumulativos. Dois templates:

- **Template C — Correção** (SEC, PERF, BUG): Encontrou / Explicou / Corrigiu / Sem regressão / Manteve compatibilidade (ex.: falha Crítica: 2+2+3+2+1 = 10 pts).
- **Template R — Refatoração** (ARCH, CLN): Identificou / Explicou / Refatorou / Compatível (ex.: Crítica: 3+2+5+2 = 12 pts).

Os pontos brutos por categoria são normalizados para os pesos oficiais — **toda instância vale exatamente 1000**. Detalhes: [scoring/SCORING.md](scoring/SCORING.md).

## Níveis

| Nível | Linhas | Objetivo |
| --- | ---: | --- |
| LEB-100 | ~300 | Refatoração simples |
| LEB-200 | ~1.000 | Sistema legado pequeno |
| LEB-300 | ~3.000 | Múltiplos arquivos |
| LEB-400 | ~8.000 | Projeto empresarial |
| LEB-500 | ~20.000 | Sistema corporativo completo |

## Documentos

| Doc | Conteúdo |
| --- | --- |
| **[SPEC.md](SPEC.md)** | Núcleo normativo (estilo RFC): pontuação, penalidades, invariantes |
| [taxonomy/](taxonomy/) | As 85 falhas oficiais: [SEC](taxonomy/SEC.md) · [ARCH](taxonomy/ARCH.md) · [PERF](taxonomy/PERF.md) · [BUG](taxonomy/BUG.md) · [CLN](taxonomy/CLN.md) · [COMP](taxonomy/COMP.md) |
| [scoring/SCORING.md](scoring/SCORING.md) | Critérios por severidade, normalização, rubrica de explicação, selos |
| [matrix/MATRIX.md](matrix/MATRIX.md) | **Matriz Oficial de Falhas** — construção, iscas, ocultação por hash |
| [levels/LEVELS.md](levels/LEVELS.md) | LEB-100 (~300 linhas) → LEB-500 (~20.000 linhas) |
| [protocol/PROTOCOL.md](protocol/PROTOCOL.md) | Enunciado canônico, modos S/A, 3 runs → mediana, anti-gaming |
| [protocol/TAREFA.md](protocol/TAREFA.md) | A tarefa entregue ao modelo: enunciado neutro + contrato de entrega, igual em toda instância |
| [`leb`](leb) + [harness/](harness/) | O comando do operador (`pacote` · `avaliar` · `scorecard` · `estado`) e as ferramentas que ele chama |
| `*/**.schema.json` | Formatos machine-readable de matriz e scorecard |

## Como rodar

1. Escolha uma instância vigente (não aposentada) e seu nível.
2. `./leb pacote <ID>` monta `runs/<ID>/pacote/` — `code/` + `manifest.md` + a [tarefa canônica](protocol/TAREFA.md), nunca nada de `private/`. Mande **essa pasta** ao agente; a receita do run sai pronta em `runs/<ID>/COMO-RODAR.md`.
3. Execute 3 rodadas independentes (`./leb avaliar` + `./leb scorecard` em cada uma); a nota oficial é a mediana do total.
4. Avalie: diff da superfície pública → testes de caracterização → verifies por falha → matching relatório×matriz → rubrica de explicação → scorecard (`.md` + `.json`), mais as métricas informativas de **calibração** (confiança por achado) e cobertura por **dificuldade**, que não tocam nos 1000 pontos.

## Estado

- [x] Especificação 1.3.0 (este repositório) — tarefa canônica no pacote + contrato de entrega (`PROTOCOL §2.1–2.2`), calibração + eixo de dificuldade (§8.1–8.2), bloco de custo/tempo (§8.3), sem pontuar
- [x] **Empacotador** ([`harness/pack.py`](harness/pack.py)) — monta `code/` + `manifest.md` + [`TAREFA.md`](protocol/TAREFA.md) vinculada à instância, com varredura anti-vazamento e `package_sha256` determinístico
- [x] Primeira instância: **[LEB-100-A](instances/LEB-100-A/)** v1.1 — código PHP legado, 13 falhas plantadas + 2 iscas, matriz privada, caracterização + probes de verificação (validada ao vivo: caracterização 22/22 verde no código legado e no corrigido; probes viram PLANTADA→CORRIGIDA)
- [x] **Harness** de avaliação ([`harness/`](harness/)) — pipeline mecânico (caracterização antes/depois + probes + cobertura por dificuldade → JSON), só-stdlib, agnóstico de instância; validado nos dois sentidos na LEB-100-A
- [x] **Montador** do scorecard ([`harness/score.py`](harness/score.py)) + **interface do juiz** ([`scoring/JUDGE.md`](scoring/JUDGE.md), `judge.schema.json`) — scorecard determinístico de 1000 pts a partir do mecânico + veredito; validado de ponta a ponta (inclusive a reescrita mysqli→PDO tirando 0/Reprovada e um run Gold em 860)
- [ ] Runs de referência — executar modelos de verdade + rodar o juiz LLM para gerar os scorecards oficiais

## Licença e contribuição

IDs da taxonomia são imutáveis (SPEC §9). Propostas de novas falhas/níveis: issue com o caso real que a motive. Matrizes de instâncias **vigentes** nunca entram neste repositório público — apenas seus hashes SHA-256; a matriz é revelada quando a instância é aposentada.
