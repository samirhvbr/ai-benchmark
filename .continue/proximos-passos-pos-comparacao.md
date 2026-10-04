# Próximos passos — depois da comparação com Akita v4 e LiveBench (2026-10-04)

> **Registrado em:** 2026-10-04 · **Origem:** comparação do LEB-100-A com o
> [LLM Benchmark v4 do Akita](https://akitaonrails.com/en/2026/09/15/new-llm-benchmark-v4-retesting-39-llms-part-2/)
> (39 modelos) e com o [LiveBench, categoria Coding](https://livebench.ai/#/?cats=Coding)
> (release 2026-06-25, 66 modelos).
> **Comparação anterior (julho, antes dos runs):** [`comparativo-leb-vs-akita.md`](./comparativo-leb-vs-akita.md)
> e [`retomada.md`](./retomada.md).
> **Ordem combinada com o Samir:** (1) fechar os runs pendentes — em andamento, fora
> deste documento; depois os itens abaixo, nesta ordem.

Este item sai da fila quando cada parte abaixo **existir** (no site, no repo ou no
placar), não quando for reescrito ou movido.

---

## Onde já estamos à frente

- **3 runs por agente com mediana.** O Akita roda 1 vez por modelo; o LiveBench
  publica uma nota por release.
- **Julgamento cego**, harness de caracterização (22 checagens) e probes mecânicas.
- **Registro de cada run** (cliente, mensagem, sessão, custo) — e, desde a 0.2.78,
  run sem registro completo é retirado (`PROTOCOL §4` item 5).

## 2. Apresentação — copiar o que os dois mostram e nós não

Só código; não depende de VM. Os dados já estão no `results.json` (`cost_time`,
`client`, flaws por run).

- [ ] **Custo e tempo em cada card** do placar (samirhv.com.br e shvia.org).
      Atenção ao aviso do Akita: custo não se compara entre clientes/harness
      diferentes (assinatura vs. API vs. local) — mostrar o cliente junto.
- [ ] **Gráfico custo × nota** (bolha = tempo), como o do Akita. Eixo de custo em
      escala log. Runs sem custo registrado ficam fora do gráfico, com nota.
- [ ] **"Nunca corrigiu"** por agente: lista das falhas plantadas que o run
      publicado deixou abertas, para os 30 — hoje a tabela falha-a-falha mostra
      só o top 10.
- [ ] **Filtro por cliente** (Claude Code / Codex CLI / opencode), ao lado do
      filtro por fornecedor (samirhv 1.0.83 / shvia 0.8.9).
- [ ] **Marca "pesos abertos"** (DeepSeek, GLM, Qwen, Kimi, MiniMax), como o
      LiveBench faz.

## 3. Cobertura — agentes novos (3 runs cada, método padrão)

Aparecem **nas duas listas** e faltam aqui, em ordem de prioridade:

| # | Modelo | Akita v4 | LiveBench Coding | Observação |
|---|---|---|---|---|
| 1 | Kimi K3 | #26 (85.0, cliente `kimi`) | 81.4 | Foi retirado na 0.2.78 por falta de registro; refazer com 3 runs completos |
| 2 | Muse Spark 1.3 | #17 (88.75) | 81.1 (xhigh) | |
| 3 | Claude Opus 4.8 | #23 (86.0) | 81.8 (max) | É o modelo para o qual o Fable cai na segurança |
| 4 | Claude Sonnet 5 | #14 (91.0) | 80.7 (xhigh) | Mostra o ganho da geração 5 → 5.5 |
| 5 | Gemini 3.7 Flash | #35 (75.5, high) | 78.9 (high) | Geração anterior do Gemini que já temos |
| 6 | Qwen (3.6 Plus / 3.7 Max / 3.8 Flash) | #21 e #33 | 78.2 (3.6 Plus) | Hoje só temos o Qwen3 Coder Next; escolher um |

Só no Akita (avaliar depois): Claude Opus 5, Claude Fable 5, Sakana Fugu Ultra v2,
Nex N2.5 Pro, MiMo V2.5 Pro, Step 3.7 Flash, Grok 4.5, Mistral Large 3,
DeepSeek V4 Pro 0813, e dois locais (Qwen 3.8 27B, GLM-4.7-Flash).

Regras que valem para todos: não rodar dois do mesmo provedor ao mesmo tempo
(rate limit, ver os void do Gemini); sessão com registro completo; `/exit` no fim.

## 4. Escopo — uma segunda instância

Os dois testam mais de um cenário (Akita: 7 sprints; LiveBench: várias tarefas).
O LEB tem uma instância (LEB-100-A). Uma segunda instância é o salto de
credibilidade, e o item mais caro. Fica para depois que o placar fechar
(itens 1 a 3).
