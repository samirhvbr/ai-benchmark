# LEB — Instâncias

Uma **instância** é um caso concreto do benchmark: um sistema legado real, com falhas plantadas, pronto para ser entregue a uma LLM. A especificação vive em [`../SPEC.md`](../SPEC.md); aqui ficam os casos que a implementam.

## Anatomia de uma instância

```text
LEB-<nível>-<letra>/
├── README.md              → o que é, stack, como rodar, ciclo público/privado
├── manifest.md            → SUPERFÍCIE PÚBLICA declarada (o contrato que não pode quebrar)
├── code/                  → o sistema legado entregue ao modelo  ........... PÚBLICO
│   ├── *.php / *.java ...     código com as falhas plantadas
│   ├── schema.sql            estrutura do banco (contexto p/ o modelo)
│   └── seed.sql              dados de exemplo
├── characterization/      → testes de caracterização (compatibilidade) ...... PÚBLICO
│   ├── run.php               harness executável
│   └── docker-compose.yml    ambiente reprodutível
└── private/               → GABARITO — nunca vai junto do code/ ............. PRIVADO
    ├── matrix.json           a Matriz Oficial (schema em ../../matrix/)
    ├── matrix.md             versão legível + notas de avaliação
    └── verify/               roteiro de verificação por falha (exploits, probes)
```

Público × privado (MATRIX.md §4): o pacote que a IA recebe é **apenas** `code/` + `manifest.md` + a `TAREFA.md` gerada — montado por [`../harness/pack.py`](../harness/pack.py). `characterization/` é público mas usado pelo avaliador. `private/` **nunca** é entregue nem publicado no mesmo lugar do código — só o SHA-256 de `matrix.json` é divulgado no lançamento.

> **A instância não escreve a tarefa.** Repare que não há enunciado nesta árvore: a tarefa
> (enunciado canônico + contrato de entrega) vive só em [`../protocol/TAREFA.md`](../protocol/TAREFA.md)
> e é idêntica em todas as instâncias — é o que mantém os resultados comparáveis entre casos e
> entre modelos ([`SPEC.md §9.4`](../SPEC.md)). O que a instância declara é o contrato **do
> sistema**, no `manifest.md`. Instância nova não ganha enunciado próprio, nem "só um parágrafo
> extra de instrução": se algo falta para todo mundo, muda-se `protocol/TAREFA.md` (e sobe a
> versão da tarefa).

## Roteiro — construir uma instância

1. **Escolher domínio e stack** legados plausíveis (ex.: painel PHP+mysqli anos 2010).
2. **Escrever o código** de forma que pareça manutenção real — não um campo minado óbvio.
3. **Plantar as falhas** da [taxonomia](../taxonomy/), cada uma atribuível a uma linha, sem sobreposição (MATRIX.md §3).
4. **Adicionar iscas** (10–20% da matriz): falhas *plausíveis* que **não** existem.
5. **Declarar a superfície pública** no `manifest.md` (o que corrigir não pode quebrar) — só o contrato do sistema; nada de instrução de tarefa, que é do padrão.
6. **Escrever a caracterização**: trava contratos públicos, **não** congela as falhas.
7. **Escrever a matriz** (`private/`) com localização, severidade, correção esperada e `verify`.
8. **Validar**: o código roda, a caracterização passa no legado intocado, cada `verify` reproduz sua falha.
9. **Empacotar de teste**: `./leb pacote <ID>` tem de sair sem erro — é o que prova que a instância monta um pacote limpo e que o vínculo (id, nível, versão, spec, hash) está correto na matriz.
10. **Congelar e versionar**: publicar o hash de `matrix.json`; instância vira imutável.

## Roteiro — avaliar um modelo numa instância

Segue o pipeline do [`PROTOCOL.md §5`](../protocol/PROTOCOL.md):

```text
1. ./leb pacote <ID> → runs/<ID>/pacote/                (nunca private/)
2. Coletar a entrega: RELATORIO.md + code/ + achados.json
3. diff da superfície pública ........► violações COMP-*         (mecânico)
4. characterization/ antes vs depois .► regressão C4 / PEN-002    (mecânico)
5. private/verify/ por falha .........► corrigiu de fato C3/R3    (mecânico)
6. matching relatório × matrix.json ..► achou/explicou, iscas     (avaliador)
7. rubrica de explicação (às cegas) ..► EXPL 0–50                 (juiz)
8. cálculo .............................► scorecard .md + .json
```

## Índice de instâncias

| Instância | Nível | Stack | Falhas | Iscas | Estado |
| --- | --- | --- | ---: | ---: | --- |
| [LEB-100-A](LEB-100-A/) | 100 (~300 linhas) | PHP 8 + mysqli | 13 | 2 | 🟢 referência |
| LEB-200-A | 200 (~1.000 linhas) | — | — | — | ⬜ planejada |
| LEB-300-A | 300 (~3.000 linhas) | — | — | — | 🟡 exploratory pilot (only the aggregate is published) |

> `LEB-100-A` é a **instância de referência**: didática, cabe em turno único (modo S), exercita 5 das 6 categorias pontuáveis + as penalidades de compatibilidade. Use-a como modelo para as demais.
