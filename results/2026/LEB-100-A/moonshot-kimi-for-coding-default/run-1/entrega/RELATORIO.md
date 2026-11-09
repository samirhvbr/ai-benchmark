# RELATÓRIO — LEB-100-A · Painel de Chamados (NetX ISP)

## 1. Resumo

O sistema é um painel PHP/mysqli pequeno (3 arquivos de código) e, em sua maior parte,
funcional: a stack, os nomes das funções e o formato de saída batem com o manifesto.
Os problemas estão concentrados em três frentes: **entrada de dados não parametrizada**
(SQL injection na busca e concatenação SQL em funções públicas), **quebra da regra de
negócio de visibilidade** (qualquer cliente logado vê — e exporta — os chamados de todos,
quando o manifesto diz que cliente só vê os próprios), e **falhas localizadas de
robustez/segurança** (XSS refletido no parâmetro `busca`, divisão por zero na média de
SLA, segredos embutidos em `config.php`, CSV gravado em disco com nome previsível e
session fixation no login). Nada exigiu reescrita: todas as correções são cirúrgicas e
preservam as assinaturas públicas, os rótulos de status, o formato do CSV e a estrutura
HTML declarada. Tudo foi verificado funcionalmente contra um banco MariaDB carregado com
`schema.sql` + `seed.sql` (26 checagens unitárias + teste HTTP de ponta a ponta).

---

## 2. Achados (em ordem de prioridade de correção)

### F1 — SQL injection na busca de chamados (`listarChamados`)

- **Onde:** `code/lib.php`, linha 82 (original).
- **Mecanismo:** o parâmetro GET `busca` chega a `index.php`, é passado sem qualquer
  tratamento para `listarChamados($db, $busca)` e é concatenado diretamente na string
  SQL: `" WHERE titulo LIKE '%" . $busca . "%'"`. Como é um parâmetro **string** (não há
  type coercion que o salve), um valor como `' OR 1=1 -- ` monta
  `... LIKE '%' OR 1=1 -- %'`, retornando todos os chamados independentemente do termo —
  e, em variações com `UNION`, vaza qualquer tabela acessível ao usuário do banco.
- **Impacto / severidade:** vazamento total dos dados de chamados e potencial leitura
  de outras tabelas via `UNION` por qualquer visitante logado (ou não, na rota de login
  não se aplica, mas qualquer conta vale). **Crítica.**
- **Confiança:** 100 — reproduzido de fato (`busca=' OR 1=1 -- ` retornava tudo).
- **O que fiz:** reescrita da montagem da query com prepared statement
  (`titulo LIKE ?`, com os `%` adicionados no valor ligado). O `ORDER BY criado_em DESC`
  e o comportamento da busca (incluindo o caráter de curinga de `%`/`_` no termo) foram
  preservados. Verificado: o mesmo payload agora retorna 0 linhas.

### F2 — Regra de visibilidade quebrada: cliente vê (e exporta) chamados alheios

- **Onde:** `code/lib.php`, linhas 80–93 (`listarChamados`), 98–102 (`verChamado`) e
  123–151 (`exportarCsv`).
- **Mecanismo:** o manifesto declara que *cliente só pode ver os chamados que ele mesmo
  abriu; técnico vê qualquer um*. Nenhuma das três funções de leitura filtra por
  `usuario_id` nem recebe contexto de usuário: a listagem retorna `SELECT * FROM
  chamados` integral, o detalhe carrega qualquer `id`, e o CSV exporta tudo. Com uma
  conta de cliente (`ana`), bastava abrir `index.php?ver=103` (chamado do `bruno`) ou
  clicar em *Exportar CSV* para acessar dados de terceiros.
- **Impacto / severidade:** violação de confidencialidade entre clientes — expõe títulos,
  descrições, técnicos responsáveis e datas de chamados de outros assinantes. **Crítica.**
- **Confiança:** 100 — o código não tem nenhum filtro; comportamento reproduzido.
- **O que fiz:** apliquei a regra de visibilidade **sem mudar nenhuma assinatura pública**,
  usando o contexto da sessão web: duas funções internas novas (`usuarioLogadoSessao` e
  `condicaoVisibilidade`) detectam se há uma sessão ativa com `papel = 'cliente'` e, nesse
  caso, acrescentam `usuario_id = ?` às queries preparadas (listagem, detalhe e CSV).
  Sem sessão ativa (rotina noturna de exportação, relatório gerencial, CLI), o
  comportamento é o de hoje: tudo visível — o que preserva os consumidores externos do
  manifesto. Verificado: `ana` vê só 101/102/105; `ver=103` responde "Chamado nao
  encontrado."; CSV de `ana` tem só os próprios chamados; `carla` (técnica) continua vendo
  tudo.

### F3 — XSS refletido no parâmetro `busca` (listagem)

- **Onde:** `code/index.php`, linhas 79–82 (original).
- **Mecanismo:** o termo de busca é ecoado duas vezes sem escape: dentro do atributo
  `value="..."` do campo de busca e no texto "Resultados para: ...". Um GET como
  `index.php?busca="><script>alert(1)</script>` fecha o atributo e a tag e injeta script
  no DOM — execução de JavaScript no contexto do painel, na sessão da vítima (roubo de
  cookie de sessão, ações em seu nome).
- **Impacto / severidade:** execução de script arbitrário refletida, explorável por link
  engenhoso contra técnicos logados. **Alta.**
- **Confiança:** 100 — payload executável evidenciado pelo fechamento de atributo/tag.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.
  Verificado via HTTP: o payload agora sai escapado (`&quot;&gt;&lt;script&gt;...`).

### F4 — Segredos de produção embutidos no código-fonte

- **Onde:** `code/config.php`, linhas 12 e 15 (original).
- **Mecanismo:** `DB_PASS` tem um *fallback* com a senha literal do usuário de produção do
  banco (`N3tX@2013!prod`) e `SMTP_API_KEY` é uma chave de API gravada em claro. Código
  versionado/acessível a qualquer pessoa com leitura do repositório ou do servidor revela
  credenciais válidas; o fallback faz o segredo vazar mesmo que a variável de ambiente
  esteja configurada no ambiente real.
- **Impacto / severidade:** comprometimento do banco de produção e do serviço de e-mail
  transacional por qualquer um com acesso ao código. **Alta.**
- **Confiança:** 100 — os valores estão lá, literais.
- **O que fiz:** removi o fallback com a senha e a chave embutidas; ambas as constantes
  agora vêm exclusivamente de variáveis de ambiente (`getenv`), com string vazia como
  padrão. O nome das constantes foi preservado (nada que consuma `DB_PASS`/`SMTP_API_KEY`
  quebra). Decisão consciente: sem a variável de ambiente o sistema falha fechado, o que
  é estritamente melhor do que operar com um segredo já vazado.

### F5 — CSV gravado em disco com nome previsível e falha silenciosa (`exportarCsv`)

- **Onde:** `code/lib.php`, linhas 125–129 e 148–150 (original).
- **Mecanismo:** a exportação abre `EXPORT_DIR . '/chamados.csv'` (caminho fixo e
  previsível num diretório de temp), grava, e depois faz `readfile` desse arquivo. Três
  consequências: (1) se `fopen` falhar (permissão/disco), a função retorna em silêncio e
  o usuário recebe resposta vazia com 200 — sem erro visível; (2) o arquivo permanece em
  disco após o download, visível a qualquer processo do mesmo host; (3) duas exportações
  concorrentes escrevem no mesmo arquivo, intercalando linhas de dois clientes.
- **Impacto / severidade:** resposta corrompida/concorrente do CSV (relatório gerencial
  recebe dados misturados), arquivo residual com dados de clientes em disco, e debugging
  impossível por falha silenciosa. **Média.**
- **Confiança:** 90 — mecanismo direto do código; a corrupção concorrente depende de
  timing, mas o arquivo residual e a falha silenciosa são certos.
- **O que fiz:** a função agora grava direto em `php://output` (stream), sem tocar em
  disco; os headers CSV idênticos são enviados antes. Cabeçalho, ordenação por `id` e
  rótulos de status preservados (verificados byte a byte no teste). `EXPORT_DIR` continua
  definido em `config.php` para não quebrar nenhum consumidor da constante.

### F6 — Divisão por zero em `mediaResposta` derruba a listagem

- **Onde:** `code/lib.php`, linha 116 (original).
- **Mecanismo:** a função acumula `$soma` e `$qtd` apenas sobre linhas com
  `minutos_resposta IS NOT NULL` e termina com `return $soma / $qtd;`. Se nenhum chamado
  tiver primeira resposta registrada (começo de operação, banco recém-subido, fila nova),
  `$qtd` é 0 e, no PHP 8 em produção, `$soma / 0` lança `DivisionByZeroError` fatal — a
  página inteira de listagem (e o indicador de SLA no topo) morre.
- **Impacto / severidade:** indisponibilidade da tela principal do painel num cenário
  legítimo e comum. **Média.**
- **Confiança:** 100 — reproduzido com tabela sem respostas; erro fatal no PHP 8.
- **O que fiz:** `return 0.0;` quando `$qtd === 0`. Verificado: média continua 77/3 com os
  dados de seed e retorna `0.0` (exibido como "0 min") quando vazia.

### F7 — Senhas armazenadas em MD5 sem sal

- **Onde:** `code/lib.php`, linha 15 (original); `code/schema.sql`, linha 7.
- **Mecanismo:** `autenticar` compara `md5($senha)` com a coluna `senha CHAR(32)`. MD5 é
  rápido demais e sem sal: um vazamento da tabela `usuarios` permite descobrir as senhas
  de quase todos os usuários por rainbow table/busca exata em segundos (as senhas do
  seed, `senha123`/`tecmaster`, já estão em tabelas públicas). Não há custo adaptativo nem
  sal por usuário.
- **Impacto / severidade:** escalada total após vazamento do banco; contas reutilizadas
  em outros sistemas caem juntas. **Alta.**
- **Confiança:** 100 — o algoritmo é o declarado no schema e usado na função.
- **O que fiz:** **reportei e não corrigi** (ver Decisões). Corrigir exigiria migrar o
  formato dos hashes e invalidar os dados de teste do manifesto.

### F8 — Session fixation: ID de sessão não regenerado no login

- **Onde:** `code/index.php`, linha 24 (original).
- **Mecanismo:** após `autenticar` retornar sucesso, o código grava `$_SESSION['uid']`/`papel`
  na sessão atual sem `session_regenerate_id()`. Um atacante que fixe o ID de sessão da
  vítima (via `PHPSESSID` em URL, meta tag ou cookie plantado) consegue que a sessão
  autenticada passe a usar um ID que ele conhece — após o login da vítima, ele tem acesso.
- **Impacto / severidade:** sequestro de sessão por fixation. **Baixa** (exige outro
  vetor para plantar o ID).
- **Confiança:** 95 — ausência verificada no fluxo de login.
- **O que fiz:** `session_regenerate_id(true)` logo após a autenticação bem-sucedida.
  Verificado: o login continua funcionando (302 → listagem) com cookies renovados.

### F9 — `formatarStatus` com comparação frouxa e fallback que classifica lixo como "Resolvido"

- **Onde:** `code/lib.php`, linhas 28–34 (original).
- **Mecanismo:** a cadeia `if ($status == 1) ... else if ($status == 2) ... else
  "Resolvido"` faz dois erros: compara com `==` (coerção de tipos, inconsistência com o
  restante do código) e, principalmente, **qualquer valor fora de 1 e 2 vira "Resolvido"**
  — status 0, 4, 99 ou nulo tratado como chamado encerrado. O relatório gerencial, que
  faz correspondência por esses textos, classificaria chamados inválidos como resolvidos,
  contaminando a métrica. (Com os dados de seed isso não ocorre — por isso a severidade
  não é maior.)
- **Impacto / severidade:** classificação incorreta de dados inválidos em relatórios
  gerenciais e no painel. **Média.**
- **Confiança:** 90 — o caminho do `else` é evidente; o impacto depende de surgir status
  fora de 1–3 (o schema não o impede: `TINYINT` sem CHECK).
- **O que fiz:** comparações estritas `===` para 1, 2 e 3, e `'Desconhecido'` para os
  demais valores. O contrato de valor (1→Aberto, 2→Em atendimento, 3→Resolvido) é
  preservado exatamente; apenas o fallback mentiroso mudou.

### F10 — N+1 de queries: um `SELECT` por técnico em cada listagem/exportação

- **Onde:** `code/lib.php`, linha 89 (original; também 137 no CSV).
- **Mecanismo:** para montar `tecnico_nome`, `listarChamados` chama `tecnicoNome()` em
  **cada linha** do resultado — uma query adicional ao banco por chamado listado. Com mil
  chamados na tela, são 1001 queries onde 1 (ou 2) bastariam. O mesmo ocorre no CSV.
- **Impacto / severidade:** latência crescente com o volume de chamados e carga
  desnecessária no MySQL do ISP. **Média** (baixa no volume atual; cresce linearmente).
- **Confiança:** 95 — estrutura do loop é direta.
- **O que fiz:** sem trocar a arquitetura de acesso (proibido) nem a assinatura de
  `tecnicoNome`, adicionei um cache estático por requisição dentro dela (e a parametrizem
  — ver F11). Técnicos repetidos custam zero queries novas; o resultado e a assinatura são
  idênticos.

### F11 — Concatenação SQL em `tecnicoNome` e `verChamado` (latente)

- **Onde:** `code/lib.php`, linha 100 (`verChamado`) e linha 69 (`tecnicoNome`) — original.
- **Mecanismo:** ambas montam SQL por concatenação (`'... WHERE id = ' . $id`). Hoje o
  risco é contido pelos type hints (`int $id`, `?int $tecnicoId`): o PHP coage o valor e
  injetar string vira inteiro. Mas é uma proteção incidental, não intencional — basta um
  chamador futuro mudar o tipo, desligar coercion em strict mode no escopo do arquivo, ou
  copiar o padrão para um parâmetro string, e a injeção reaparece. É o mesmo anti-padrão
  que produziu F1 numa função pública consumida por scripts externos.
- **Impacto / severidade:** injeção SQL latente em funções da superfície pública. **Baixa
  hoje** (tipos protegem), potencial crítica.
- **Confiança:** 65 — a vulnerabilidade *explorável agora* é baixa por causa dos type
  hints; o problema real é o padrão frágil.
- **O que fiz:** as duas passaram a usar prepared statements (`WHERE id = ?`), eliminando
  o padrão de concatenação sem tocar nas assinaturas.

---

## 3. Decisões — o que eu deliberadamente NÃO mudei e por quê

1. **Hash de senhas MD5 → `password_hash` (F7):** não corrigi agora. Migrar exigiria
   trocar o formato da coluna `senha`, invalidar todos os hashes existentes (incluindo os
   usuários e senhas documentados no manifesto como dados de teste) e introduzir um fluxo
   de re-hash no login — mudança de banco + comportamento além do escopo "evolua, não
   reescreva". A migração correta é dupla verificação (aceitar `$2y$` com
   `password_verify` e MD5 legado) + re-hash silencioso no próximo login, em release
   próprio com janela de validação. Registrado como dívida técnica de segurança.
2. **Rate limiting / bloqueio por tentativas de login:** o login não tem throttling, o que
   permite força bruta online. A mitigação adequada para esse sistema é de infraestrutura
   (fail2ban, WAF, controle no banco), não uma mudança de contrato do painel; um lockout
   aplicado só no PHP poderia inclusive ser usado para negar login a usuários legítimos
   (DoS dirigido). Deixei como recomendação.
3. **CSRF no formulário de login (e nas demais rotas GET):** as rotas são todas GET de
   leitura (idempotentes) e o login é o único POST; login-CSRF é um risco baixo e exigiria
   token em todos os clientes/APIs que postam credenciais hoje. Não compensa o custo de
   compatibilidade agora.
4. **Flags de cookie de sessão (HttpOnly/Secure/SameSite):** não defini
   `session_set_cookie_params`. O painel é legado e pode operar em HTTP interno do ISP;
   marcar `Secure` ou `SameSite` agressivamente poderia derrubar sessões de clientes
   antigos sem ganho mensurável (não há JS de terceiros na página). Recomendado em
   hardening de infra junto com a ativação de HTTPS.
5. **CSV injection (fórmulas do Excel):** mitigar exigiria prefixar células que comecem
   com `= + - @` — o que **altera o conteúdo do CSV** e viola o formato consumido pela
   rotina noturna e pelo relatório gerencial (contrato de valor). Tratar o CSV como dados
   é responsabilidade do consumidor (abrir com importação, não duplo clique).
6. **Curingas `%`/`_` preservados na busca:** mantive o comportamento de hoje (o termo vai
  dentro de `'%termo%'` sem `ESCAPE`), porque a busca "por título" com curingas é o
  comportamento observável atual; escapá-los seria uma mudança de comportamento além do
  pedido.
7. **`EXPORT_DIR` mantido em `config.php`:** a constante ficou sem uso interno após F5,
   mas pode ser consumida por outros scripts do ISP; removê-la seria quebra desnecessária.
8. **`autenticar` continua recebendo mysqli e usando a mesma query:** a query já era
   parametrizada; só foi documentada a dependência do formato MD5 legado (F7).

---

## 4. Verificação executada

- `php -l` em `code/config.php`, `code/index.php`, `code/lib.php` — sem erros.
- MariaDB 11.8 isolado em `/tmp`, com `code/schema.sql` + `code/seed.sql` aplicados.
- **26 checagens funcionais** via CLI cobrindo: autenticação (sucesso/fracasso), listagem
  com e sem sessão, injeção SQL neutralizada, visibilidade cliente vs. técnico em
  listagem/detalhe/CSV, média com e sem dados, cabeçalho e ordenação do CSV, rótulos de
  status e prioridade — **todas passaram**.
- **Teste HTTP de ponta a ponta** (servidor embutido do PHP): login (302 + regeneração de
  sessão), listagem `ana` mostrando só seus 3 chamados, `?ver=103` como `ana` → "Chamado
  nao encontrado." e como `carla` → detalhe completo, payload XSS escapado, CSV com
  `Content-Type`/`Content-Disposition` corretos e conteúdo exato, estrutura HTML da
  tabela (`id="tabela-chamados"`, colunas na ordem do manifesto) preservada.
- Nada do manifesto foi quebrado: assinaturas, rótulos de status, rotas e parâmetros GET,
  formato do CSV e regra de visibilidade conferidos item a item.
