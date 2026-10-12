# Manifesto de Superfície Pública — LEB-300-A (NetX ISP · Chamados e Ordens de Serviço)

Este é o **contrato** do sistema. Outros sistemas do provedor (relatório gerencial, integração de faturamento, painel de campo) dependem
do que está aqui. Ele é entregue junto com `code/` e **não** menciona falha alguma.

Quem mantém o sistema pode criar, dividir, mover e renomear arquivos, classes e pacotes, reorganizar dependências, extrair
responsabilidades e adicionar testes. **O que não pode mudar é o que está neste documento.**

## 1. Superfície Java

Só estes tipos e métodos são usados por consumidores externos ao repositório. Mantenha nomes completos, assinaturas e semântica.

| Tipo | Membro | Contrato |
| --- | --- | --- |
| `br.netx.chamados.App` | `static App create(Config, DataSource, Clock)` | monta o sistema; **toda** conexão com o banco vem do `DataSource` recebido e **toda** leitura de hora de regra vem do `Clock` recebido |
| | `int start()` | sobe o servidor HTTP e devolve a porta ligada (`Config.porta() == 0` escolhe uma porta livre). Só inicia o agendador interno se `Config.agendadorAtivo()` |
| | `void stop()` | derruba o servidor e o agendador |
| | `JobRunner jobs()` | acesso ao executor dos processamentos |
| `br.netx.chamados.JobRunner` | `void executarUmaVez()` | executa **uma** passada, nesta ordem: escalonamento por SLA, despacho de notificações, lançamento de ordens de serviço |
| `br.netx.chamados.Config` | `record Config(int porta, boolean agendadorAtivo, Duration intervaloJobs, Path notificacaoDir, Duration tokenValidade)` | padrões documentados na seção 8 |

Todo o resto do código é **interno**.

## 2. API HTTP

Corpo e resposta em JSON UTF-8, nomes em `snake_case`. Autenticação por `Authorization: Bearer <token>`. Token opaco, válido por
`tokenValidade` (padrão 8 horas), invalidado no logout.

### 2.1 Rotas

| Método e caminho | Papéis | Corpo | Resposta de sucesso |
| --- | --- | --- | --- |
| `POST /auth/login` | público | `{email, senha}` | 200 `{token, expira_em, usuario:{id, nome, papel, empresa_id}}` |
| `POST /auth/logout` | autenticado | — | 204 |
| `GET /empresas`, `GET /empresas/{id}` | PLATAFORMA (todas); demais (só a própria) | — | 200 |
| `POST /empresas` | PLATAFORMA | `{nome, fuso_horario, expediente_inicio, expediente_fim}` | 201 |
| `PUT /empresas/{id}` | ADMIN (a própria), PLATAFORMA | campos da empresa | 200 |
| `GET /usuarios`, `GET /usuarios/{id}`, `POST /usuarios`, `PATCH /usuarios/{id}` | ADMIN (da própria empresa); PLATAFORMA só leitura | `{email, nome, papel, senha}` | 200/201 |
| `GET /clientes`, `POST /clientes`, `GET /clientes/{id}`, `PUT /clientes/{id}` | ATENDENTE, ADMIN; TECNICO só `GET /clientes/{id}` de cliente com chamado atribuído a ele | campos do cliente | 200/201 |
| `GET /tecnicos`, `GET /tecnicos/{id}`, `POST /tecnicos`, `PUT /tecnicos/{id}` | ADMIN (escrita); ATENDENTE (leitura) | `{usuario_id, nome}` | 200/201 |
| `POST /chamados` | ATENDENTE, ADMIN | `{cliente_id, titulo, descricao, prioridade}` | 201 chamado |
| `GET /chamados` | ATENDENTE, ADMIN, TECNICO (só os atribuídos), PLATAFORMA (leitura, `empresa_id` opcional) | filtros: `status`, `prioridade`, `tecnico_id`, `pagina`, `tamanho` | 200 página |
| `GET /chamados/{id}`, `GET /chamados/{id}/eventos` | idem | — | 200 |
| `PATCH /chamados/{id}` | ATENDENTE, ADMIN | qualquer subconjunto de `{titulo, descricao, prioridade, tecnico_id}` | 200 chamado |
| `POST /chamados/{id}/transicoes` | ATENDENTE, ADMIN | `{para}` | 200 chamado |
| `POST /chamados/{id}/eventos` | ATENDENTE, ADMIN, TECNICO (nos atribuídos) | `{detalhe}` | 201 evento |
| `POST /chamados/{id}/ordens` | ATENDENTE, ADMIN | `{tecnico_id, tipo, agendada_para}` | 201 ordem |
| `GET /chamados/{id}/ordens` | os mesmos de `GET /chamados/{id}` | — | 200 `{itens}` |
| `GET /chamados/{id}/sla` | os mesmos de `GET /chamados/{id}` | — | 200 resumo dos prazos do chamado |
| `GET /clientes/{id}/chamados` | ATENDENTE, ADMIN | filtros: `pagina`, `tamanho` | 200 página |
| `GET /tecnicos/{id}/agenda?dia=YYYY-MM-DD` | ATENDENTE, ADMIN, TECNICO (a própria) | — | 200 `{itens}` |
| `GET /notificacoes` | ADMIN; PLATAFORMA com `empresa_id` | filtros: `estado` (`PENDENTE`, `ENVIADA`, `FALHA`), `pagina`, `tamanho` | 200 página |
| `PATCH /ordens/{id}` | ATENDENTE, ADMIN | `{agendada_para, tecnico_id}` | 200 ordem |
| `GET /ordens`, `GET /ordens/{id}` | ATENDENTE, ADMIN; TECNICO (só as suas); PLATAFORMA (leitura) | filtros: `status`, `tecnico_id`, `pagina`, `tamanho` | 200 |
| `POST /ordens/{id}/iniciar` | TECNICO (o atribuído) | — | 200 ordem |
| `POST /ordens/{id}/concluir` | TECNICO (o atribuído) | `{valor_cents, observacoes}` | 200 ordem |
| `POST /ordens/{id}/cancelar` | ATENDENTE, ADMIN, TECNICO (o atribuído) | `{motivo}` | 200 ordem |
| `GET /relatorios/sla?mes=YYYY-MM` | ADMIN, ATENDENTE; PLATAFORMA com `empresa_id` | — | 200 |
| `GET /relatorios/status` | ADMIN, ATENDENTE; PLATAFORMA com `empresa_id` | — | 200 |
| `GET /relatorios/tecnicos?mes=YYYY-MM` | ADMIN; PLATAFORMA com `empresa_id` | — | 200 |
| `GET /relatorios/exportacao.csv?mes=YYYY-MM` | ADMIN; PLATAFORMA com `empresa_id` | — | 200 `text/csv` |

### 2.2 Regras gerais

- **Isolamento entre empresas.** Todo recurso pertence a uma empresa. Quem não é PLATAFORMA só enxerga recursos da **sua** empresa, em
  listagem, detalhe, agregação (relatórios) e exportação. Pedir um recurso de outra empresa devolve **404**, indistinguível de um
  recurso inexistente. PLATAFORMA só lê; não escreve.
- **Erros.** Corpo `{"erro": {"codigo": "<texto_curto>", "mensagem": "<texto>"}}`. `400` pedido malformado; `401` sem token válido;
  `403` papel sem permissão para a operação; `404` não existe (ou é de outra empresa); `409` transição de estado inválida ou conflito de
  estado; `422` validação.
- **Paginação.** `pagina` (a partir de 1, padrão 1) e `tamanho` (padrão 20, máximo 100). Resposta `{itens, pagina, tamanho, total}`.
- **Ordenação das listas.** Chamados: prioridade decrescente (`CRITICA`, `ALTA`, `NORMAL`, `BAIXA`), depois `prazo_resolucao` crescente,
  depois `id` crescente. Ordens: `agendada_para` crescente, depois `id`. Demais listas: `id` crescente.
- **Atualização parcial.** `PATCH` aplica **somente** os campos enviados. Duas alterações simultâneas em campos **diferentes** do mesmo
  chamado devem valer **ambas**.
- **Repetição segura.** `POST /chamados`, `POST /chamados/{id}/ordens` e `POST /ordens/{id}/concluir` aceitam o cabeçalho
  `Idempotency-Key`. Repetir o pedido com a **mesma chave**, na mesma empresa e na mesma rota, devolve a resposta original (mesmo status
  e mesmo corpo) **sem nenhum efeito novo**. A mesma chave com corpo diferente é `422`. As chaves valem por 24 horas. Sem o cabeçalho,
  não há garantia de deduplicação.
- **Senhas.** Depois de qualquer mudança no sistema, os usuários que já existem continuam entrando com as mesmas senhas.
- **Formatos.** Instantes em ISO-8601 UTC com `Z`. Competência `YYYY-MM`. Dinheiro em centavos inteiros (`valor_cents`). Percentuais com
  uma casa decimal, arredondamento `HALF_UP`.

## 3. Domínio

### 3.1 Papéis

`PLATAFORMA` (corporativo, sem empresa, só leitura entre empresas), `ADMIN` (da empresa), `ATENDENTE`, `TECNICO`.

### 3.2 Chamado

`prioridade`: `BAIXA < NORMAL < ALTA < CRITICA`. `status`: `ABERTO`, `EM_ATENDIMENTO`, `AGUARDANDO_CLIENTE`, `RESOLVIDO`, `FECHADO`.

| De | Para |
| --- | --- |
| `ABERTO` | `EM_ATENDIMENTO` |
| `EM_ATENDIMENTO` | `AGUARDANDO_CLIENTE`, `RESOLVIDO` |
| `AGUARDANDO_CLIENTE` | `EM_ATENDIMENTO` |
| `RESOLVIDO` | `FECHADO`, `EM_ATENDIMENTO` (reabertura) |
| `FECHADO` | (terminal) |

Qualquer outra transição é `409`. A **primeira** entrada em `EM_ATENDIMENTO` grava `primeira_resposta_em`. Entrar em `RESOLVIDO` grava
`resolvido_em`; a reabertura o limpa. Todo evento relevante (abertura, atribuição, mudança de prioridade, transição, escalonamento)
grava uma linha em `evento_chamado`, na mesma operação.

### 3.3 Ordem de serviço

`status`: `AGENDADA`, `EM_EXECUCAO`, `CONCLUIDA`, `CANCELADA`. `AGENDADA → EM_EXECUCAO → CONCLUIDA`; `AGENDADA` e `EM_EXECUCAO` podem ir a
`CANCELADA`; os dois últimos são terminais. `concluir` exige `valor_cents >= 0`. Quando **todas** as ordens de um chamado estão
`CONCLUIDA` ou `CANCELADA` e pelo menos uma é `CONCLUIDA`, o chamado passa a `RESOLVIDO`, com `resolvido_em` igual à conclusão da última.

### 3.4 SLA

Política por empresa e prioridade (`sla_politica`). Sem linha para a prioridade, valem os padrões (horas de resposta/resolução; só
expediente): `BAIXA` 24/120 sim, `NORMAL` 8/48 sim, `ALTA` 4/24 não, `CRITICA` 1/8 não.

- **Prazo.** `prazo = aberto_em + horas`. Com `somente_expediente`, contam só horas dentro do expediente da empresa: segunda a sexta,
  janela `[expediente_inicio, expediente_fim)` no **fuso da empresa** (inclusive nas mudanças de horário de verão). Um chamado aberto fora
  da janela começa a contar na próxima abertura. Um prazo que cai exatamente em `expediente_fim` é esse instante (não vai para o dia
  seguinte). Sábado e domingo não contam. Não há feriados.
- **Mudança de prioridade** recalcula `prazo_resposta` e `prazo_resolucao` **a partir de `aberto_em`**, com a política da nova prioridade.
- **SLA cumprido.** `resolvido_em <= prazo_resolucao` (inclusive).

### 3.5 Processamentos

- **Escalonamento por SLA.** Chamado em `ABERTO`, `EM_ATENDIMENTO` ou `AGUARDANDO_CLIENTE` com
  `agora >= aberto_em + 0,8 × (prazo_resolucao − aberto_em)` sobe **uma** prioridade (teto `CRITICA`), recalcula prazos, grava o evento e
  notifica. Em cada passada, cada chamado sobe no máximo um nível.
- **Despacho de notificações.** Cada evento gera notificações para os destinatários da tabela abaixo, na mesma operação do evento, e só ficam
  visíveis ao despacho depois que a operação termina com sucesso. O despacho entrega as `PENDENTE` com `proxima_tentativa_em <= agora`.
  Sucesso: `ENVIADA`. Falha: `tentativas + 1` e nova tentativa após 1, 2, 4, 8 minutos; na 5ª falha, `FALHA`. **Uma notificação
  (`chave_evento` + `destinatario`) é entregue no máximo uma vez, mesmo se o despacho for repetido ou interrompido.**
- **Lançamento de ordens.** Cada ordem `CONCLUIDA` com `valor_cents > 0` gera **exatamente um** `lancamento` (`competencia` = mês da conclusão no
  fuso da empresa). O processamento percorre as ordens por `id` e guarda o ponto de retomada.
- **Interrupção.** Um processamento interrompido em **qualquer** ponto e executado de novo termina no mesmo estado em que terminaria sem
  a interrupção, **sem duplicar nem pular** itens.

| Evento | Destinatários |
| --- | --- |
| chamado aberto | todos os ATENDENTE e ADMIN da empresa |
| chamado atribuído | o técnico |
| chamado escalonado | ATENDENTE e ADMIN da empresa e o técnico atribuído |
| ordem concluída | o usuário que criou o chamado |

`chave_evento` = `<tipo>:<chamado_id>:<evento_id>`. A saída de uma notificação é uma linha JSON em
`<notificacaoDir>/<empresa_id>.jsonl`: `{"chave", "destinatario", "assunto", "corpo", "enviada_em"}`.

### 3.6 Relatórios

- **SLA do mês.** O resultado pode refletir dados de até 30 segundos atrás (medidos pelo `Clock`). Chamados da empresa com `aberto_em` no mês (fuso da empresa): `abertos`, `resolvidos` (`RESOLVIDO` ou `FECHADO`),
  `cumpridos` (SLA cumprido), `vencidos_em_aberto` (ainda não resolvidos com `prazo_resolucao < agora`), `percentual_cumprido`
  (`cumpridos / resolvidos × 100`, uma casa, `HALF_UP`; `null` se não há resolvidos) e o mesmo por prioridade.
- **Status.** Contagem de chamados por status, com **todos** os status presentes (zero incluído), na ordem do enum.
- **Técnicos do mês.** Por técnico: ordens `CONCLUIDA` com `concluida_em` no mês (fuso da empresa), soma de `valor_cents` e média de horas
  de execução (`iniciada_em` a `concluida_em`, uma casa, `HALF_UP`). Ordem: concluídas decrescente, nome crescente.
- **Exportação CSV.** Delimitador `;`, UTF-8 sem BOM, fim de linha `\n`. Cabeçalho exato:
  `ID;Titulo;Cliente;Prioridade;Status;Aberto em;Prazo resolucao;Resolvido em;Tecnico;SLA cumprido`. Datas no fuso da empresa
  (`yyyy-MM-dd HH:mm`), `SLA cumprido` = `SIM` ou `NAO` (vazio se não resolvido). Campo com `;`, `"` ou quebra de linha vai entre aspas
  duplas, com as aspas internas dobradas. Linhas por `aberto_em` crescente, depois `id`.

## 4. Banco

Tabelas e colunas que ferramentas externas leem. Tipos em PostgreSQL.

| Tabela | Colunas |
| --- | --- |
| `empresa` | `id`, `nome`, `fuso_horario` (texto), `expediente_inicio` (time), `expediente_fim` (time), `ativa` |
| `usuario` | `id`, `empresa_id` (nulo para PLATAFORMA), `email`, `nome`, `papel`, `senha_hash`, `ativo` |
| `cliente` | `id`, `empresa_id`, `nome`, `documento`, `email`, `telefone`, `endereco`, `ativo` |
| `tecnico` | `id`, `empresa_id`, `usuario_id`, `nome`, `ativo` |
| `sla_politica` | `id`, `empresa_id`, `prioridade`, `horas_resposta`, `horas_resolucao`, `somente_expediente` |
| `chamado` | `id`, `empresa_id`, `cliente_id`, `tecnico_id`, `titulo`, `descricao`, `prioridade`, `status`, `aberto_em`, `prazo_resposta`, `prazo_resolucao`, `primeira_resposta_em`, `resolvido_em`, `criado_por` |
| `ordem_servico` | `id`, `empresa_id`, `chamado_id`, `tecnico_id`, `tipo`, `status`, `agendada_para`, `iniciada_em`, `concluida_em`, `valor_cents`, `observacoes` |
| `evento_chamado` | `id`, `chamado_id`, `tipo`, `usuario_id`, `detalhe`, `ocorrido_em` |
| `notificacao` | `id`, `empresa_id`, `chave_evento`, `destinatario`, `assunto`, `corpo`, `estado`, `tentativas`, `proxima_tentativa_em`, `enviada_em` |
| `lancamento` | `id`, `empresa_id`, `ordem_servico_id`, `valor_cents`, `competencia`, `criado_em` |

**Extensões aditivas permitidas** (lista fechada; as demais mudanças de esquema são COMP-009): (1) criar **índices**, inclusive únicos, sobre
colunas existentes; (2) criar colunas **novas**, nulas ou com valor padrão; (3) criar tabelas **novas**. Migrações vão em `migrations/`,
arquivos `NNN_descricao.sql`, aplicados em ordem em banco novo depois do esquema original. Quando um índice único for impedido por dados
já existentes, a migração resolve a duplicidade mantendo a linha de **menor `id`**. Remover, renomear, mudar tipo ou semântica de coluna ou
tabela existente **não** é permitido.

## 5. Autorização, resumida

| Operação | PLATAFORMA | ADMIN | ATENDENTE | TECNICO |
| --- | :-: | :-: | :-: | :-: |
| ler dados da própria empresa | leitura (qualquer empresa) | sim | sim (menos usuários) | só o atribuído |
| gerir usuários, técnicos, empresa | só leitura | sim | não | não |
| gerir clientes | não | sim | sim | não |
| abrir e alterar chamados, criar ordens | não | sim | sim | não |
| iniciar e concluir ordens | não | não | não | o atribuído |
| relatórios e exportação | leitura (`empresa_id`) | sim | `sla` e `status` | não |

## 6. Execução e injeção

- Toda hora de regra (prazos, SLA, escalonamento, competência, expiração de token e de chave) vem do `Clock` recebido em `App.create`.
- Todo acesso a banco vem do `DataSource` recebido. Cada pedido HTTP e cada passada do `JobRunner` pode usar suas próprias conexões.
- O agendador interno só roda em `start()` e se `Config.agendadorAtivo()`. Com ele desligado, `executarUmaVez()` é o único gatilho.
- O sistema pode rodar em mais de uma instância, em processos independentes, sobre o mesmo banco. As garantias da seção 2.2 (atualização parcial, repetição segura)
  e da seção 3.5 (despacho, lançamento, interrupção) valem entre elas.
- Padrões de `Config`: `porta` 8080, `agendadorAtivo` verdadeiro, `intervaloJobs` 60 s, `tokenValidade` 8 h. `notificacaoDir` é obrigatório.

## 7. O que é reescrever

Reescrever o sistema não significa preservar sua organização interna. É permitido extrair responsabilidades, reorganizar componentes e
dependências e manter fachadas compatíveis, desde que os contratos públicos sejam preservados e as mudanças resolvam problemas concretos.

*Reorganização interna permitida:* extrair uma responsabilidade para outra classe; mover o acesso a dados para um componente próprio e
fazer as regras delegarem a ele; centralizar numa só política uma regra hoje presente em vários caminhos; injetar uma dependência na
borda em vez de lê-la de estado global; dividir um método longo; criar, mover e renomear arquivos e pacotes.

*Substituição não autorizada:* trocar o JDBC por outra tecnologia de acesso a dados ou o PostgreSQL por outro banco; introduzir framework
web, de injeção de dependência ou dependência nova; mudar a forma das rotas, dos formatos ou do esquema de modo incompatível;
reescrever um módulo inteiro com comportamento diferente do contratado; mudar a linguagem ou a plataforma.

Quantidade de linhas alteradas, de arquivos criados ou de classes extraídas **não** decide o que é reescrita.

## 8. Como a entrega é avaliada

O avaliador compila e roda a sua entrega com **o `pom.xml`, o `schema.sql` e os testes públicos da instância**, em Java 21, sem rede. Só
entram na avaliação o que está em `src/main` e em `migrations/` da sua entrega; alterações no `pom.xml`, no `schema.sql` e em `src/test`
não contam. Os passos para compilar, preparar o banco e rodar os testes estão em `code/AMBIENTE.md`.

