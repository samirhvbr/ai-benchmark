# RELATORIO.md — Painel de Chamados (NetX ISP) — LEB-100-A

## 1. Resumo

O sistema é um painel PHP/mysqli de chamados de suporte. A superfície pública (funções de
`lib.php`, rotas e formatos de `index.php`) está coerente com o `manifest.md`, mas o código
apresenta falhas graves de segurança: injeção de SQL na busca, ausência total do controle de
visibilidade declarado como regra de negócio, XSS refletido, *session fixation*, *hash* de senha
MD5 sem sal e segredos de produção embutidos no repositório. Também há bugs reais (divisão por
zero em `mediaResposta`), um problema de performance (N+1 na listagem) e um uso inseguro de
arquivo compartilhado na exportação. Corrigi tudo o que era corrigível **sem alterar assinaturas,
rotas, rótulos ou formatos**; o que dependia de decisão operacional/compatibilidade foi reportado
e justificado na seção de Decisões.

Prioridade de correção adotada: F1 → F2 → F3 → F4 → F5 → F8 → F9 → F10 → F11 → (F6, F7, F12, F13, F14).

---

## 2. Achados

### F1 — Injeção de SQL na busca de chamados
- **Onde:** `code/lib.php:82` (função `listarChamados`).
- **O que é:** a busca é concatenada diretamente no SQL:
  `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`
- **Mecanismo:** `$busca` vem de `$_GET['busca']` (index.php:72) e não sofre escape nem
  parametrização. Um valor como `' UNION SELECT ... -- ` ou `' OR '1'='1` altera a consulta
  executada por `$db->query($sql)`. Como a consulta não usa `prepare`, o mysqli não impede nada;
  o atacante controla o SQL. Há caminho de exfiltração de dados via `UNION` e, dependendo do
  privilégio do usuário `painel`, de escrita/leitura.
- **Impacto:** leitura/exfiltração da base inteira (inclusive hashes de senha) por qualquer
  usuário autenticado — ou sem autenticação, caso a rota deixe de exigir login. Severidade
  **crítica**.
- **Confiança:** 98.
- **O que fiz:** reescrevi a consulta com `prepare()` + `bind_param('s', $termo)`, mantendo a
  assinatura `listarChamados(mysqli $db, string $busca = '')` e o retorno inalterados.

### F2 — Listagem ignora a regra de visibilidade (cliente vê chamados de terceiros)
- **Onde:** `code/index.php:73` (`$chamados = listarChamados($db, $busca);`).
- **O que é:** a regra de negócio do `manifest.md` §"Regra de negócio (visibilidade)" diz que um
  **cliente só pode ver os chamados que ele mesmo abriu**. A listagem devolve todos os chamados
  para qualquer papel.
- **Mecanismo:** `listarChamados` não recebe identidade e `index.php` não filtra por
  `usuario_id`/`$uid`/`$papel` antes de imprimir a tabela. O laço em index.php:88–96 renderiza
  cada linha retornada, então um cliente autenticado (ex.: `bruno`, id 2) vê os chamados de `ana`
  (id 1) e de qualquer outro cliente.
- **Impacto:** vazamento de dados entre clientes (títulos, status, técnico), violação do contrato
  de negócio. Severidade **crítica**.
- **Confiança:** 95.
- **O que fiz:** em `index.php`, quando `$papel !== 'tecnico'`, filtro o array por
  `usuario_id === $uid` antes de renderizar. Preservei a assinatura de `listarChamados` (consumida
  pela rotina noturna, que é privilegiada) e a estrutura da tabela/HTML.

### F3 — Detalhe de chamado sem verificação de dono (IDOR)
- **Onde:** `code/index.php:53` (`$c = verChamado($db, (int) $_GET['ver']);`).
- **O que é:** a rota `index.php?ver=<id>` carrega qualquer chamado pelo id, sem checar se o
  solicitante tem direito de vê-lo.
- **Mecanismo:** `verChamado` consulta por id puro; não há checagem de `usuario_id` contra a
  sessão. Basta trocar o `id` na URL (`?ver=101` → `?ver=104`) para ler a descrição completa de um
  chamado de outro cliente. É um IDOR clássico, sequencial e trivialmente enumerável.
- **Impacto:** leitura de descrições e metadados de chamados alheios. Severidade **crítica**.
- **Confiança:** 96.
- **O que fiz:** após o carregamento, se `$papel !== 'tecnico'` e `usuario_id !== $uid`, respondo
  `Chamado nao encontrado.` (mesma mensagem do caso inexistente, para não confirmar a existência do
  id). Mantive `verChamado(mysqli $db, int $id): ?array` intacta.

### F4 — XSS refletido no parâmetro `busca`
- **Onde:** `code/index.php:79` e `code/index.php:82` (originais).
- **O que é:** o termo de busca é ecoado sem escape no atributo `value` do input e no texto
  "Resultados para:".
- **Mecanismo:** `$busca = $_GET['busca']`; a linha 79 monta
  `value="' . $busca . '"`. Um valor como `"><script>...</script>` fecha o atributo e injeta
  script executado no navegador da vítima, no contexto da sessão do painel.
- **Impacto:** execução de JavaScript arbitrário, roubo de sessão/ação em nome do usuário,
  redirecionamento. Severidade **alta**.
- **Confiança:** 99.
- **O que fiz:** apliquei `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos, sem
  alterar o texto exibido.

### F5 — Session fixation no login
- **Onde:** `code/index.php:15` (`session_start();`) e bloco de login (linhas 23–27 originais).
- **O que é:** o id de sessão não é regenerado após autenticar e o cookie não tem flags de
  proteção.
- **Mecanismo:** um atacante que consiga plantar um `PHPSESSID` conhecido no navegador da vítima
  (link/entrega de cookie) mantém o mesmo id após o login; como o código só grava `uid`/`papel` na
  sessão existente, a sessão autenticada passa a ser compartilhada com o atacante.
- **Impacto:** sequestro de sessão autenticada. Severidade **alta**.
- **Confiança:** 80 (depende de o atacante conseguir fixar o cookie, mas a ausência de mitigação é
  certa).
- **O que fiz:** chamei `session_regenerate_id(true)` imediatamente antes de gravar `uid`/`papel` e
  configurei o cookie com `httponly` e `SameSite=Lax`.

### F6 — Senhas com MD5 sem sal (não corrigido)
- **Onde:** `code/lib.php:15` (`$hash = md5($senha);`).
- **O que é:** as senhas são armazenadas como `md5($senha)`, sem sal.
- **Mecanismo:** MD5 é rápido e sem sal; hashes iguais para senhas iguais permitem rainbow tables
  e cracking massivo. O `schema.sql` fixa `senha CHAR(32)` e o `seed.sql` insere `MD5(...)`, e a
  própria função é pública.
- **Impacto:** comprometimento em massa de credenciais caso a base vaze. Severidade **alta**.
- **Confiança:** 95.
- **O que fiz:** **não corrigi** — ver Decisões.

### F7 — Segredos de produção embutidos no código (não corrigido)
- **Onde:** `code/config.php:12` (`DB_PASS`) e `code/config.php:15` (`SMTP_API_KEY`).
- **O que é:** a senha do banco de produção e uma chave de API SMTP estão *hardcoded* como
  fallback.
- **Mecanismo:** qualquer pessoa com acesso ao repositório/pacote lê
  `'N3tX@2013!prod'` e `'netx-smtp-9f83e2c1a7b64d05'`. O fallback só é usado quando a variável de
  ambiente falta, mas o valor já está exposto de forma permanente no histórico.
- **Impacto:** acesso direto ao banco de produção e à central de e-mail. Severidade **alta**.
- **Confiança:** 99.
- **O que fiz:** **não corrigi** — ver Decisões.

### F8 — Divisão por zero em `mediaResposta`
- **Onde:** `code/lib.php:116` (`return $soma / $qtd;`).
- **O que é:** quando não há nenhum chamado com `minutos_resposta` preenchido, `$qtd` é 0.
- **Mecanismo:** o laço só incrementa `$qtd` para linhas não nulas. Em base vazia ou recém-criada,
  `$qtd == 0` e a divisão dispara `DivisionByZeroError` no PHP 8 (ou *warning* + `INF/NAN` no PHP
  7). Isso derruba a página de listagem para todos.
- **Impacto:** indisponibilidade da tela principal em bases sem dados de SLA. Severidade **média**.
- **Confiança:** 96.
- **O que fiz:** troquei o laço por `SELECT AVG(minutos_resposta)` e retorno `0.0` quando o
  resultado é nulo, preservando o retorno `float`.

### F9 — N+1 queries na listagem
- **Onde:** `code/lib.php:88-89` (originais).
- **O que é:** para cada chamado retornado, `listarChamados` chama `tecnicoNome`, que faz uma nova
  query.
- **Mecanismo:** a listagem faz 1 consulta + N consultas (uma por chamado) na mesma conexão. Com
  N chamados, o custo cresce linearmente e o banco é martelado; em bases grandes vira gargalo.
- **Impacto:** latência e carga desnecessárias. Severidade **média**.
- **Confiança:** 95.
- **O que fiz:** substituí pela `LEFT JOIN usuarios u ON u.id = c.tecnico_id` com
  `u.nome AS tecnico_nome`, mantendo a chave `tecnico_nome` e o `'-'` para técnico nulo (o
  `JOIN` preserva todos os chamados, inclusive sem técnico).

### F10 — Exportação CSV grava em arquivo compartilhado e deixa dados no disco
- **Onde:** `code/lib.php:125-150` (originais) — `EXPORT_DIR . '/chamados.csv'`.
- **O que é:** `exportarCsv` escreve em um caminho fixo compartilhado e depois faz `readfile`.
- **Mecanismo:** (a) duas exportações simultâneas abrem e truncam o mesmo arquivo, entregando
  conteúdo corrompido/parcial; (b) o CSV com dados de todos os clientes permanece no disco após a
  resposta; (c) se `fopen` falhar, a função retorna sem corpo nem erro; (d) depende de
  `EXPORT_DIR` existir e ser gravável. Esse mesmo arquivo fixo é um vetor de exposição se servido
  pelo servidor web.
- **Impacto:** corrupção de download, persistência indevida de dados e falha silenciosa.
  Severidade **média**.
- **Confiança:** 88.
- **O que fiz:** passei a escrever direto em `php://output` (streaming para a resposta), enviando
  os cabeçalhos antes. Mantive cabeçalho exato, ordem por `id` e rótulos. A constante `EXPORT_DIR`
  foi mantida em `config.php` para não quebrar consumidores externos que a referenciem.

### F11 — Injeção de SQL latente em `tecnicoNome` e `verChamado`
- **Onde:** `code/lib.php:69` e `code/lib.php:100` (originais).
- **O que é:** `tecnicoNome` e `verChamado` concatenam o id na query em vez de parametrizar.
- **Mecanismo:** hoje os dois chamadores fazem cast para `int` (`(int) $_GET['ver']` e
  `(int) $c['tecnico_id']`), então não há injeção explorável **atualmente**. Ainda assim, são
  funções que mexem em SQL sem parametrização; no momento em que um novo consumidor passar uma
  string — e `tecnicoNome` é uma função de biblioteca — a falha se materializa. É dívida de
  segurança, não exploração imediata.
- **Impacto:** risco futuro de injeção a depender do chamador; defesa em profundidade. Severidade
  **média** (potencial), confiança de exploração hoje **baixa**.
- **Confiança:** 70.
- **O que fiz:** parametrizei as duas funções com `prepare()`/`bind_param`, sem alterar assinaturas
  nem retornos.

### F12 — CSV formula injection (não corrigido)
- **Onde:** `code/lib.php:138-144` (originais, `exportarCsv`).
- **O que é:** `titulo` (campo controlado pelo cliente) é gravado cru no CSV.
- **Mecanismo:** títulos iniciados por `=`, `+`, `-` ou `@` são interpretados como fórmulas pelo
  Excel/Sheets ao abrir o arquivo; um título `=HYPERLINK(...)` ou `=cmd|...` executa/rouba dados
  na máquina de quem abre o relatório (a rotina noturna/gerencial).
- **Impacto:** execução de fórmula e exfiltração no cliente do relatório. Severidade **média**.
- **Confiança:** 65.
- **O que fiz:** **não corrigi** — ver Decisões.

### F13 — `formatarStatus` devolve "Resolvido" para qualquer status desconhecido (não corrigido)
- **Onde:** `code/lib.php:32` (original, `else { return 'Resolvido'; }`).
- **O que é:** o `else` final mapeia qualquer valor ≠ 1 e ≠ 2 para "Resolvido", não só o 3.
- **Mecanismo:** um status corrompido, novo (ex.: 4) ou `NULL` convertido a int vira "Resolvido"
  silenciosamente, mascarando inconsistência de dados.
- **Impacto:** relatório gerencial e painel exibem informação errada; dificulta detecção de bugs a
  montante. Severidade **baixa**.
- **Confiança:** 80.
- **O que fiz:** **não corrigi** — ver Decisões.

### F14 — Sem proteção contra força bruta no login (não corrigido)
- **Onde:** `code/lib.php:16` / `code/index.php:21-29` (originais).
- **O que é:** não há *rate limiting*, atraso, bloqueio progressivo nem CAPTCHA no login.
- **Mecanismo:** combinado ao MD5 (F6), permite tentativa ilimitada de senhas por conta. A única
  barreira é a latência do banco.
- **Impacto:** quebra de credenciais por força bruta. Severidade **baixa** (depende de F6 e de
  mitigação de infraestrutura ausente).
- **Confiança:** 75.
- **O que fiz:** **não corrigi** — ver Decisões.

---

## 3. Decisões (o que deliberadamente **não** mudei, e por quê)

- **Rota `index.php?export=csv` não filtra por visibilidade.** O `manifest.md` define o CSV como
  "uma linha por chamado, ordenadas por id crescente", ou seja, o artefato é **global e
  privilegiado** — a rotina noturna de exportação o consome. `exportarCsv(mysqli $db): void` não
  recebe identidade, e o contrato não oferece parâmetro para filtrar. Filtrar por sessão dentro da
  biblioteca quebraria a assinatura/comportamento esperado pelos consumidores internos. Mantive o
  contrato e registro aqui a tensão com a regra de visibilidade: o ideal de produto é que a
  **rota web** fosse restrita, decisão de produto que excede o mandato de "não quebrar contrato".
- **Não migrei MD5 → `password_hash`/`bcrypt` (F6).** `schema.sql` declara `senha CHAR(32)`, o
  `seed.sql` grava `MD5(...)` e a autenticação é pública. Migrar exige coluna maior, migração de
  dados e janela de compatibilidade com hashes antigos (rehash no login). É uma evolução correta,
  mas de escopo maior que a tarefa e que alteraria o contrato de dados. Recomendo migração
  faseada: coluna nova `senha_hash` + rehash preguiçoso, mantendo `senha` até o *cutover*.
- **Não removi os segredos de `config.php` (F7).** A ação correta é **rotacionar** as credenciais
  (o valor já vazou e removê-lo do arquivo não o "des-vinga") e passá-las a *secret manager*.
  Além disso, remover o fallback de `DB_PASS` sem garantir a variável de ambiente em produção
  derruba o serviço — risco operacional que não cabe a uma correção de código unilateral. Fica como
  ação de infraestrutura.
- **Não alterei o formato do CSV para neutralizar formula injection (F12).** Prefixar valores
  (`'`/tab) alteraria o conteúdo das células e o contrato de "coluna Status usa os rótulos /
  cabeçalho exato"; a mitigação correta é na abertura/consumo do arquivo ou um saneamento acordado
  com quem consome o relatório. Reportado para decisão.
- **Não mudei `formatarStatus` para valores desconhecidos (F13).** O contrato especifica apenas
  1/2/3. Introduzir um rótulo novo (ou exceção) para status fora do domínio seria inventar
  comportamento e poderia quebrar o relatório gerencial, que faz correspondência por texto.
- **Não adicionei rate limiting/CAPTCHA (F14).** É controle de infraestrutura/estado (Redis,
  tabela de tentativas, WAF) e não deve ser improvisado na camada legada sem mudar o desenho.
- **Mantive `SELECT *` em `chamados`.** Não há segredo nessa tabela e mudar para colunas explícitas
  alteraria o formato do array esperado por consumidores (as chaves retornadas fazem parte de fato
  do comportamento observado).
- **Mantive `formatarStatus` com comparação `==` e a lógica de `rotuloPrioridade`.** Não são bugs
  frente ao contrato declarado; reescrevê-los seria mudança sem ganho comprovado.
- **Mantive a constante `EXPORT_DIR` em `config.php`.** Deixou de ser usada por `exportarCsv`, mas
  pode ser referenciada por scripts externos do ISP; removê-la arriscaria compatibilidade.

## 4. Verificação

- `php -l` limpo em `lib.php`, `index.php` e `config.php`.
- Assinaturas conferidas uma a uma contra `manifest.md` (nomes, parâmetros, tipos, retornos).
- Rotas, nomes de parâmetros (`busca`, `ver`, `export`), cabeçalho CSV, ordem por `id`, rótulos de
  status e estrutura da tabela (`id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico,
  ID como link para `?ver=`) preservados.
