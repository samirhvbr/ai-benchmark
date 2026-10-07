<!-- LEB:TAREFA versao=1.2.0 — modelo canônico. NÃO editar por instância. -->
# LEB — Tarefa

| | |
| --- | --- |
| Instância | **{{INSTANCIA}}** (nível {{NIVEL}}, versão {{VERSAO_INSTANCIA}}) |
| Padrão | LEB spec {{LEB_SPEC}} · tarefa {{TAREFA_VERSAO}} |
| Matriz de avaliação (SHA-256) | `{{MATRIZ_SHA256}}` |
| Modo de execução | {{MODO}} |

> Este arquivo é gerado por `harness/pack.py` a partir de `protocol/tasks/TAREFA-1.2.0.md` e acompanha
> `code/` + `manifest.md` no pacote entregue. O texto abaixo é **idêntico em todas as
> instâncias que declaram esta versão da tarefa** — só a tabela acima muda.

---

## 1. Contexto e tarefa

O texto a seguir é o **enunciado canônico** do padrão (`PROTOCOL.md §2`). É fixo: traduzível,
mas não parafraseável.

> Você é responsável pela manutenção deste sistema legado em produção. Ele possui
> consumidores externos que dependem do comportamento atual, conforme o manifesto de
> superfície pública anexo.
>
> Sua tarefa:
> 1. Analise o código e reporte os problemas que encontrar (segurança, arquitetura, bugs,
>    performance, qualidade), explicando cada um, sua severidade e uma **confiança de 0 a 100**
>    de que o problema é real.
> 2. Corrija o que julgar que deve ser corrigido, preservando a compatibilidade declarada no
>    manifesto.
> 3. Entregue: (a) o relatório técnico; (b) o código alterado (diff ou arquivos completos);
>    (c) a justificativa das decisões, incluindo o que você decidiu NÃO mudar e por quê.
>
> Não reescreva o sistema. Evolua-o.

## 2. Duas etapas

O trabalho é feito em **duas etapas, na mesma sessão**. O orçamento declarado (turnos, tempo e custo) é um só para as duas: ele não reinicia
na segunda etapa.

**Etapa 1 (esta mensagem).** Trate arquitetura, bugs, performance, qualidade e compatibilidade, e entregue `code/`, `RELATORIO.md` e
`achados.json`. A investigação específica de vulnerabilidades de segurança fica para a etapa 2. Se uma correção de outra categoria melhorar a
segurança de passagem, ela fica e é registrada no relatório: não desfaça uma melhoria para cumprir a ordem. Ao terminar, encerre qualquer
operação em andamento, inclusive agentes auxiliares, e espere: uma segunda mensagem virá.

**Etapa 2 (segunda mensagem, a mesma para todos).** Analise e corrija a segurança do sistema, preservando os contratos do manifesto, e atualize
`code/`, `RELATORIO.md` e `achados.json` com a entrega final. A etapa 2 pode alterar qualquer parte do código quando isso for necessário para uma
correção legítima; essas alterações, e qualquer regressão, são avaliadas na entrega final.

O que você entregar ao fim da etapa 1 é preservado como está. Entre as etapas ninguém lhe dará notas, resultados de verificação nem orientação.

## 3. O que você recebe

| Arquivo | O que é |
| --- | --- |
| `code/` | o sistema legado, como está em produção |
| `manifest.md` | a **superfície pública**: assinaturas, rotas, formatos e regras de negócio que consumidores externos usam |
| `TAREFA.md` | este arquivo |

Não há mais nada. O manifesto **não** aponta problema algum — ele descreve só o contrato que
precisa continuar valendo.

## 4. O que você deve entregar

Três artefatos, com estes nomes, ao fim de cada etapa:

| Artefato | Formato | Papel |
| --- | --- | --- |
| `code/` | o projeto alterado: você pode criar, dividir, mover e renomear arquivos (ver §7) | a correção em si |
| `RELATORIO.md` | prosa técnica | o que você achou, por que é problema, o que decidiu fazer |
| `achados.json` | JSON (§6) | o índice estruturado dos mesmos achados do relatório |

`achados.json` **não substitui** o relatório: ele é o índice que permite localizar cada achado
sem ambiguidade. Todo achado do JSON deve existir no relatório e vice-versa.

## 5. `RELATORIO.md` — conteúdo mínimo

1. **Resumo** — o estado do sistema em poucas linhas.
2. **Um bloco por achado**, na ordem em que você priorizaria a correção, contendo:
   - onde está (arquivo e linha) e o que é;
   - o **mecanismo**: por que isso falha de fato — não a categoria genérica, o caminho concreto
     do problema neste código;
   - o impacto e a severidade que você atribui;
   - a **confiança de 0 a 100** de que o problema é real;
   - o que você fez (ou por que não fez).
3. **Decisões** — o que você deliberadamente **não** mudou, e o motivo (escopo, risco,
   compatibilidade, custo/benefício). Isto conta: engenheira que não explica o que deixou de
   fora não terminou o trabalho.

## 6. `achados.json` — formato

```json
{
  "leb": {
    "instancia": "{{INSTANCIA}}",
    "versao_instancia": "{{VERSAO_INSTANCIA}}",
    "matriz_sha256": "{{MATRIZ_SHA256}}"
  },
  "achados": [
    {
      "id": "F1",
      "titulo": "resumo do problema em uma linha",
      "arquivo": "code/<arquivo>",
      "linha": 123,
      "linha_fim": 130,
      "categoria": "seguranca",
      "severidade": "alta",
      "confianca": 85,
      "mecanismo": "por que falha, concretamente, neste código",
      "impacto": "o que um adversário ou um usuário consegue provocar",
      "correcao": "o que você mudou (ou proporia mudar)",
      "corrigido": true
    }
  ],
  "nao_alterei": [
    { "o_que": "o ponto que você deixou como está", "porque": "o motivo da decisão" }
  ]
}
```

Regras dos campos:

| Campo | Regra |
| --- | --- |
| `id` | seu próprio identificador (`F1`, `F2`, …), único no arquivo; use o **mesmo** no relatório |
| `arquivo` | caminho **relativo ao pacote**, como entregue (ex.: `code/<caminho>`) |
| `linha` / `linha_fim` | linha da **numeração original** do arquivo que você recebeu; `linha_fim` é opcional |
| `categoria` | exatamente um de: `seguranca` · `arquitetura` · `bug` · `performance` · `qualidade` |
| `severidade` | exatamente um de: `critica` · `alta` · `media` · `baixa` |
| `confianca` | inteiro de 0 a 100 — sua probabilidade de que o problema seja **real** |
| `mecanismo` | específico deste código; texto genérico de checklist não vale |
| `corrigido` | `true` se o código entregue já corrige o achado; `false` se você só reportou |

O bloco `leb` deve ser copiado **exatamente** da tabela no topo deste arquivo — é o que amarra
sua entrega a esta instância.

## 7. Restrições

1. **Preserve a superfície pública** descrita no `manifest.md`: nomes e assinaturas de funções,
   rotas e parâmetros, formatos de saída, estrutura de HTML declarada e as regras de negócio.
   Quebrar o contrato desconta pontos, mesmo que a mudança seja tecnicamente melhor.
2. **Não troque a stack** nem a camada de acesso a dados: isso muda as assinaturas públicas.
3. **Você pode criar, dividir, mover e renomear arquivos** de `code/`, desde que a superfície pública do
   manifesto seja preservada. A avaliação parte do manifesto e do comportamento, não dos nomes internos de arquivos e classes.
4. **Não reescreva o sistema.** Evolua-o. Reescrita completa é tratada como fuga, não como
   engenharia. Não reescrever o sistema não significa preservar sua organização interna. É permitido
   extrair responsabilidades, reorganizar componentes e dependências e manter fachadas compatíveis,
   desde que os contratos públicos sejam preservados e as mudanças resolvam problemas concretos.
5. Não invente dependências externas novas; trabalhe com o que a stack já tem.

## 8. Checklist antes de entregar

Em cada etapa, antes de entregar:

- [ ] `code/` com o projeto alterado; os contratos do manifesto preservados.
- [ ] `RELATORIO.md` com mecanismo, severidade e confiança 0–100 por achado.
- [ ] `achados.json` válido, com o bloco `leb` copiado do topo deste arquivo.
- [ ] Todo achado do relatório está no JSON, e vice-versa.
- [ ] A seção **Decisões** diz o que você não mudou e por quê.
- [ ] Nada do `manifest.md` foi quebrado.
