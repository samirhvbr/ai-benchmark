# RELATÓRIO TÉCNICO — LEB-100-A (Painel de Chamados, NetX ISP)

## Resumo

O sistema é um painel de chamados em PHP + mysqli (4 arquivos de aplicação). Funciona,
mas carrega falhas graves de segurança e dois defeitos que derrubam o painel inteiro em
produção. As duas mais sérias são exploráveis por qualquer usuário autenticado — inclusive
os clientes de teste `ana`/`bruno`:

- **injeção de SQL** no filtro de busca, que permite ler a tabela `usuarios` (logins e
  hashes de senha) via `UNION`;
- **falha de controle de acesso**: a regra de visibilidade do manifesto (cliente só vê o que
  abriu; técnico vê tudo) **não era aplicada** — qualquer cliente via, listava, exportava e
  abria os chamados de todos os outros.

Além dessas, há segredos de produção versionados no `config.php`, senhas em `md5` sem sal,
XSS refletido na busca, e uma divisão por zero que transforma o dia em que ninguém respondeu
um chamado em erro fatal na tela.

Corrigi os treze achados abaixo **preservando a superfície pública** do `manifest.md`:
assinaturas, rótulos, rotas, o `id="tabela-chamados"` e a ordem das colunas, o cabeçalho e os
bytes do CSV. Validei com uma bateria de caracterização que compara a saída das funções
públicas antes e depois: fora as duas mudanças deliberadas descritas na seção **Decisões**, a
saída é idêntica (o CSV é byte a byte igual ao original). A regra de negócio de visibilidade
passou a ser **aplicada** na camada web sem alterar a assinatura das funções do contrato.

Ambiente de verificação: PHP 8.4.26 + MariaDB 11.8, banco carregado com `schema.sql`+`seed.sql`.

---

## Achados (na ordem em que eu priorizaria a correção)

### F1 — Injeção de SQL no filtro de busca (`listarChamados`)
- **Onde:** `code/lib.php`, linhas 78–93 (concatenação na linha 82); superfície de entrada em
  `code/index.php` linhas 72–73 (`$_GET['busca']`).
- **Mecanismo:** `listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` concatenando o
  termo cru na SQL. `index.php` passa `$_GET['busca']` sem tratamento. Um `'` fecha o literal e
  o resto do parâmetro vira SQL. Reproduzido: `busca=' UNION SELECT id,login,senha,nome,papel,
  1,1,1,NOW() FROM usuarios -- ` fez a listagem devolver as quatro contas com seus hashes `md5`
  na coluna "Titulo". A coluna `chamados` tem 9 campos, então o `UNION` casa e os dados saem na
  própria tabela HTML.
- **Impacto:** leitura arbitrária do banco por qualquer usuário logado (exfiltração de
  credenciais, enumeração de dados de outros clientes); com o mesmo vetor dá para inferir
  escrita conforme privilégios do usuário `painel`. **Severidade: crítica.**
- **Confiança: 100.** Exploração reproduzida ponta a ponta.
- **O que fiz:** a montagem da consulta virou `consultarChamados`, com prepared statement e
  `bind_param`. O termo vai como parâmetro `LIKE ? ESCAPE '!'` e os curingas `% _ !` são
  escapados (ver F-relacionado sobre semântica de curinga em **Decisões**). O `UNION` agora
  retorna vazio (é tratado como texto de busca).

### F2 — Falha de controle de acesso: cliente vê chamados de todos (viola a regra do manifesto)
- **Onde:** `code/index.php` — listagem (72–73, 88–96), detalhe (52–67), export (44–47); a
  origem é que `listarChamados`/`verChamado`/`exportarCsv` em `code/lib.php` não filtram por dono.
- **Mecanismo:** nenhum ponto do fluxo compara o dono do chamado (`usuario_id`) com o usuário
  da sessão (`$_SESSION['uid']`). `verChamado($db, (int)$_GET['ver'])` carrega qualquer id;
  a listagem e o CSV trazem todas as linhas. Reproduzido: logado como `ana` (cliente, id 1) a
  listagem trouxe os 5 chamados, o CSV trouxe os 5, e `index.php?ver=103` (chamado do `bruno`)
  abriu normalmente. O manifesto define explicitamente o contrário como comportamento
  pretendido do produto.
- **Impacto:** quebra de confidencialidade entre clientes (IDOR). Um cliente lê título,
  descrição, status e histórico de qualquer outro, inclusive dados sensíveis de faturamento
  (ex.: chamado 104, "Fatura em duplicidade"). **Severidade: crítica.**
- **Confiança: 95.** O comportamento é reproduzível; a "confiança < 100" reflete só que a
  gravidade depende de o produto realmente tratar clientes como inquilinos distintos — o que o
  manifesto afirma.
- **O que fiz:** adicionei `listarChamadosVisiveis`, `exportarCsvVisiveis` e `podeVerChamado`,
  usadas pelo `index.php`, que filtram por `usuario_id` quando o papel é cliente e não filtram
  para técnico. O filtro é aplicado **no SQL**. O detalhe de um chamado de outro cliente passa a
  responder "Chamado nao encontrado." (mesma resposta de id inexistente, para não confirmar a
  existência do id). **As funções públicas do contrato (`listarChamados`, `verChamado`,
  `exportarCsv`) continuam sem filtrar** — os scripts internos (exportação noturna, relatório
  gerencial, faturamento) dependem de ver tudo. A regra é imposta na borda web, não no contrato.

### F3 — Segredos de produção embutidos no código (`config.php`)
- **Onde:** `code/config.php`, linha 12 (`DB_PASS` fallback `N3tX@2013!prod`) e linha 15
  (`SMTP_API_KEY` fixa).
- **Mecanismo:** a senha do banco de produção e a chave da API de e-mail estão em texto no
  arquivo. `DB_PASS` tem `getenv() ?: '<senha>'`, então mesmo sem variável de ambiente o
  binário embarca a senha; `SMTP_API_KEY` é literal, sem nem olhar o ambiente. Qualquer pessoa
  com leitura do repositório, um backup, ou uma exposição de arquivo `.php` como texto obtém as
  duas.
- **Impacto:** comprometimento direto do banco e da conta de envio de e-mail. **Severidade: alta.**
- **Confiança: 100.** Os segredos estão literalmente no arquivo entregue.
- **O que fiz:** removi os literais. As duas constantes passam a vir só de `getenv()`. Sem
  `DB_PASS` no ambiente a conexão falha de forma controlada (ver F10). **Estas credenciais devem
  ser tratadas como vazadas e rotacionadas** — trocar o código não invalida a senha que já
  circulou.

### F4 — Senhas com `md5` sem sal
- **Onde:** `code/lib.php` linha 15 (`md5($senha)`); `code/schema.sql` linha 7 (`senha CHAR(32)`);
  `code/seed.sql` (hashes `MD5(...)`).
- **Mecanismo:** a autenticação compara `md5($senha)` com a coluna. `md5` é rápido e sem sal:
  um vazamento da tabela (ex.: via F1) permite quebra por rainbow table/força bruta quase
  imediata. As duas senhas do seed (`senha123`, `tecmaster`) caem em segundos.
- **Impacto:** com o hash em mãos, contas são recuperadas trivialmente. **Severidade: alta.**
- **Confiança: 100.**
- **O que fiz:** `autenticar` agora aceita os dois formatos e **migra de forma transparente**.
  Se o hash guardado é `md5` (32 hex), valida com `hash_equals` e, no sucesso, regrava com
  `password_hash()` (bcrypt). Se já é `password_hash`, usa `password_verify` e refaz o hash
  quando o custo muda. A regravação só ocorre se a coluna comportar 60+ caracteres — por isso
  ampliei `schema.sql` para `VARCHAR(255)` e deixei o `ALTER TABLE` documentado para bancos
  existentes. **Enquanto a coluna seguir `CHAR(32)`, o login continua funcionando em `md5`** (a
  migração fica adiada, sem travar ninguém). Verifiquei: com a coluna `CHAR(32)` os hashes
  permanecem `md5`; após o `ALTER`, o primeiro login de `ana`/`carla` os converteu para `$2y$`,
  e o segundo login e a senha errada seguem corretos. A assinatura de `autenticar` e o retorno
  `['id','nome','papel']` não mudaram.

### F5 — XSS refletido no parâmetro de busca
- **Onde:** `code/index.php` linha 79 (`value='" . $busca . "'`) e linha 82
  (`<p>Resultados para: ' . $busca`).
- **Mecanismo:** o termo de busca é ecoado sem escape, dentro de um atributo `value` e no corpo.
  Reproduzido: `busca=<script>alert(1)</script>"onfocus="x` fechou o atributo e injetou markup
  ativo. Só a coluna de título já era escapada com `htmlspecialchars`; a caixa de busca e a
  linha "Resultados para" não.
- **Impacto:** execução de script no navegador da vítima (roubo de sessão via link, ações em
  nome do usuário). Como o cookie de sessão não era `HttpOnly` (F8), o roubo era direto.
  **Severidade: alta.**
- **Confiança: 100.** Injeção reproduzida.
- **O que fiz:** o termo é escapado uma vez com `htmlspecialchars($busca, ENT_QUOTES |
  ENT_SUBSTITUTE, 'UTF-8')` e o valor escapado é usado nos dois pontos. Confirmado: a carga sai
  como entidades HTML inertes.

### F6 — Divisão por zero em `mediaResposta` derruba o painel
- **Onde:** `code/lib.php` linhas 107–117 (linha 116, `return $soma / $qtd`).
- **Mecanismo:** a média divide por `$qtd`, o número de chamados com `minutos_resposta` não
  nulo. Se nenhum chamado tem 1ª resposta registrada (banco novo, ou um período sem respostas),
  `$qtd == 0` e o PHP 8 lança `DivisionByZeroError`. Como `mediaResposta` é chamada no topo da
  listagem (`index.php` linha 74), a **listagem inteira** vira erro fatal — não só o indicador.
  Reproduzido com um banco cujos chamados têm `minutos_resposta IS NULL`: HTTP com
  `Fatal error: Uncaught DivisionByZeroError`.
- **Impacto:** indisponibilidade da tela principal para todos os usuários numa condição de dados
  perfeitamente normal. **Severidade: alta.**
- **Confiança: 100.** Crash reproduzido.
- **O que fiz:** a função passou a somar e contar via SQL e a devolver `0.0` quando não há
  nenhuma resposta. Continua `float` (a assinatura pede `float`); com dados de teste devolve o
  mesmo `25.666…` de antes.

### F7 — CSV gravado em arquivo compartilhado sob a raiz web (exposição + corrida)
- **Onde:** `code/lib.php` linhas 123–151 (caminho `EXPORT_DIR . '/chamados.csv'`, linhas
  125–126; `readfile`, linha 150); `code/config.php` linha 18 (`EXPORT_DIR =
  '/var/www/painel/tmp'`).
- **Mecanismo:** a exportação escreve sempre no **mesmo arquivo fixo** dentro de
  `/var/www/painel/tmp` e depois faz `readfile`. Dois problemas concretos: (a) o caminho está
  sob `/var/www/painel`, isto é, provavelmente servido pelo webserver — `/tmp/chamados.csv`
  fica baixável por URL direta, **sem login e ignorando a regra de visibilidade**; (b) como o
  nome é fixo e global, duas exportações concorrentes escrevem/leem o mesmo arquivo, e um
  usuário pode baixar o conteúdo gerado por outro (ou um CSV parcial). Após F2, o arquivo em
  disco seria a via de fuga que anula o filtro por dono.
- **Impacto:** vazamento de todos os chamados a quem souber a URL do arquivo; corrida entre
  exportações simultâneas. **Severidade: alta.**
- **Confiança: 75.** O vazamento por URL depende de `/var/www/painel/tmp` estar sob a raiz
  servida — muito provável pelo caminho, mas não verificável só pelo código; a corrida no arquivo
  fixo é certa.
- **O que fiz:** a exportação escreve direto em `php://output` (streaming), sem tocar o disco.
  Some o arquivo compartilhado, some a corrida e não há cópia alcançável pela web. O CSV enviado
  ao navegador é **byte a byte idêntico** ao original (verificado com `cmp`).

### F8 — Sessão sem regeneração de ID e cookie sem flags de segurança
- **Onde:** `code/index.php` linha 15 (`session_start()` sem configuração) e linhas 20–29
  (login bem-sucedido sem `session_regenerate_id`).
- **Mecanismo:** o ID de sessão não é regenerado após o login, o que permite **fixação de
  sessão** (um atacante fixa um `PHPSESSID` conhecido na vítima antes do login e o herda depois).
  O cookie também saía sem `HttpOnly`, `SameSite` nem `Secure`, então o XSS de F5 conseguia lê-lo
  e havia exposição a CSRF/roubo em texto claro.
- **Impacto:** sequestro de sessão autenticada. **Severidade: média.**
- **Confiança: 80.**
- **O que fiz:** defini `session.use_strict_mode`, `cookie_httponly`, `cookie_samesite=Lax` e
  `cookie_secure` (quando HTTPS) antes do `session_start()`, e chamei `session_regenerate_id(true)`
  ao autenticar. Confirmado no `Set-Cookie`: `HttpOnly; SameSite=Lax`.

### F9 — Parâmetro em forma de array causa erro fatal e vaza caminho do servidor
- **Onde:** `code/index.php` linhas 22 (`autenticar(... $_POST['login'] ...)`), 53
  (`verChamado(... $_GET['ver'] ...)`) e 72–73 (`$_GET['busca']` → `listarChamados`).
- **Mecanismo:** as funções declaram `string`/`int`, mas os parâmetros HTTP podem chegar como
  array (`?busca[]=x`, `login[]=x`). O PHP então lança `TypeError` não tratado. Reproduzido:
  `?busca[]=x` e `login[]=x` retornaram `Fatal error: Uncaught TypeError` com **caminho absoluto
  do arquivo** no corpo da resposta (quando `display_errors` está ligado).
- **Impacto:** negação de serviço trivial em qualquer rota e divulgação de estrutura interna
  (caminhos) que ajuda outros ataques. **Severidade: média.**
- **Confiança: 100.** Reproduzido.
- **O que fiz:** um helper `paramString()` lê cada parâmetro externo como string e devolve `''`
  para qualquer outro tipo. Aplicado a `login`, `senha`, `ver` e `busca`. `?busca[]=x` agora cai
  para busca vazia (lista normal) e `login[]=x` cai para "usuario ou senha invalidos".

### F10 — Falha de conexão vaza exceção e stack trace; checagem morta
- **Onde:** `code/index.php` linhas 9–12.
- **Mecanismo:** desde o PHP 8.1 o mysqli reporta erros como exceção por padrão
  (`MYSQLI_REPORT_ERROR|STRICT`). Logo, `new mysqli(...)` **lança** antes de chegar no
  `if ($db->connect_errno)` — a checagem é código morto e o `die('Falha ao conectar...')` nunca
  roda nesse caminho. A exceção não tratada expõe usuário e host do banco e o caminho absoluto.
  Reproduzido com senha de banco errada: `Uncaught mysqli_sql_exception: Access denied for user
  'painel'@'127.0.0.1'` + caminho.
- **Impacto:** divulgação de informação sensível de infraestrutura na tela do usuário.
  **Severidade: média.**
- **Confiança: 90.** Reproduzido; a nota reflete que depende de `display_errors`.
- **O que fiz:** deixei o modo de exceção explícito e envolvi a conexão em `try/catch`. O detalhe
  vai para `error_log`; o usuário vê apenas "Falha ao conectar ao banco." (a mesma mensagem que
  o código sempre pretendeu). O `set_charset('utf8mb4')` foi preservado.

### F11 — Consultas N+1 na listagem e no export
- **Onde:** `code/lib.php` linhas 64–72 (`tecnicoNome`), 88–90 (laço de `listarChamados`) e 137
  (laço de `exportarCsv`).
- **Mecanismo:** para cada chamado, `tecnicoNome` faz um `SELECT` extra para achar o nome do
  técnico. Com N chamados são N+1 consultas por página e por export. Medido: `listarChamados` e
  `exportarCsv` faziam **5 consultas** cada com 5 chamados; a listagem cresce linearmente e vira
  gargalo/pressão de conexões quando a tabela cresce.
- **Impacto:** latência e carga no banco proporcionais ao número de chamados. **Severidade:
  média.**
- **Confiança: 95.**
- **O que fiz:** o nome do técnico vem por `LEFT JOIN usuarios` numa única consulta
  (`COALESCE(t.nome,'-')`). Medido: `listarChamados` e `exportarCsv` passaram a **1 consulta**
  cada. `tecnicoNome` foi mantida (com prepared statement) para quem a chame de fora, mas não é
  mais usada nos laços. A chave `tecnico_nome` e o valor `'-'` para técnico nulo foram preservados.

### F12 — `fputcsv` depreciado no PHP 8.4 pode corromper o CSV do consumidor
- **Onde:** `code/lib.php` linhas 130 e 138 (chamadas a `fputcsv` sem o 5º argumento).
- **Mecanismo:** no PHP 8.4, `fputcsv` sem o parâmetro `escape` emite `Deprecated`. Com
  `display_errors` ligado (como estava a export no ambiente de teste), esses avisos são
  **injetados na saída antes das linhas do CSV**, entregando ao consumidor (rotina noturna,
  faturamento) um arquivo com HTML de aviso no topo — CSV inválido. Reproduzido: a resposta de
  `?export=csv` vinha prefixada por vários blocos `Deprecated: fputcsv()...`.
- **Impacto:** quebra silenciosa das integrações que consomem o CSV; em versões futuras o
  comportamento do escape muda. **Severidade: média.**
- **Confiança: 85.** A contaminação visível depende de `display_errors`; a depreciação em si é
  certa.
- **O que fiz:** passei delimitador, enclausurador e escape explícitos (`',', '"', '\\'`),
  mantendo os bytes idênticos aos de antes e eliminando o aviso. Combinado com F7 (saída via
  `php://output`), o CSV sai limpo.

### F13 — Concatenação de id em SQL: injeção latente em `verChamado` e `tecnicoNome`
- **Onde:** `code/lib.php` linha 100 (`... WHERE id = ' . $id`) e linha 69
  (`... WHERE id = ' . $tecnicoId`).
- **Mecanismo:** os ids entram na SQL por concatenação. Hoje **não** são exploráveis porque
  todos os chamadores fazem cast `(int)` antes (`index.php` linha 53; `listarChamados`/
  `exportarCsv` fazem `(int)` no `tecnico_id`). Mas o padrão é frágil: qualquer novo chamador que
  passe o valor sem cast reabre injeção, e a assinatura `int` não protege contra
  `verChamado($db, $_GET['ver'])` sem cast em outro arquivo.
- **Impacto:** injeção de SQL potencial numa evolução futura; hoje, risco efetivo baixo.
  **Severidade: baixa.**
- **Confiança: 65.** É um defeito real de padrão; a incerteza é sobre exploração *atual*, que os
  casts hoje evitam.
- **O que fiz:** troquei por prepared statements com `bind_param('i', ...)`. Custo baixo, remove
  a classe de bug de vez.

---

## Decisões — o que deliberadamente não mudei (e o que mudei de propósito)

**Curingas de `LIKE` na busca (mudança de comportamento intencional).** No código original,
`%` e `_` no termo de busca eram interpretados como curingas SQL (efeito colateral da injeção
de F1): `busca=%` devolvia tudo e `busca=Troca_de` casava "Troca de plano". Ao corrigir F1 eu
**escapo** `% _ !`, então esses caracteres passam a ser buscados literalmente. É uma mudança de
saída observável, mas: (a) o manifesto define a rota só como "listagem filtrada por título",
sem prometer semântica de curinga; (b) o comportamento antigo era inseparável da vulnerabilidade.
Considerei preservável e decidi que a correção de segurança prevalece. Usei `ESCAPE '!'` (e não
a barra) para o filtro funcionar mesmo sob `sql_mode=NO_BACKSLASH_ESCAPES` — testado nos dois
modos.

**Não apliquei a regra de visibilidade dentro das funções do contrato.** `listarChamados`,
`verChamado` e `exportarCsv` continuam retornando/exportando todos os chamados. O manifesto diz
que essas assinaturas são consumidas pela exportação noturna, pelo relatório gerencial e pela
integração de faturamento — que precisam ver tudo. Filtrar ali quebraria esses consumidores. A
regra foi imposta na camada web (`index.php`) via funções novas (`*Visiveis`, `podeVerChamado`),
sem tocar nas assinaturas públicas. É a leitura que concilia "aplicar a regra de negócio" com
"preservar o contrato".

**Não mudei `formatarStatus`.** Ela devolve "Resolvido" para qualquer status diferente de 1 e 2
(inclusive valores fora de 1–3). Poderia ser mais estrita, mas o manifesto fixa os três rótulos
exatos e o relatório gerencial casa por esses textos; mexer no ramo `else` arrisca o contrato de
valor sem resolver problema real (a coluna é `TINYINT` alimentada pela aplicação com 1–3). Mantida.

**Não mudei a assinatura nem o tipo de retorno de nenhuma função pública.** As colunas de
`listarChamados`/`verChamado` continuam saindo como `string|null` (protocolo de texto do mysqli).
Prepared statements usam protocolo binário e devolveriam `int` nas colunas numéricas; para não
mudar o formato visto por quem faz comparação estrita ou `json_encode`, normalizo as linhas de
volta para string (`normalizarLinha`). A caracterização confirma tipos idênticos aos originais.

**Preservei o cast case-insensitive no login legado.** A coluna `login`/`senha` usava collation
case-insensitive, então `md5` batia mesmo com maiúsculas no hash. Mantive isso comparando
`strtolower($hash)` com `md5($senha)`, para não rejeitar logins que antes funcionavam.

**Não troquei a stack, a camada mysqli, nem renomeei/movi arquivos.** Sem novas dependências
externas. `seed.sql` ficou inalterado (segue com `md5`, coerente com a migração transparente).
`schema.sql` teve só a coluna `senha` ampliada para `VARCHAR(255)` e um `ALTER` documentado —
necessário para F4 e compatível com os hashes `md5` existentes.

**O que sinalizo mas está fora do escopo de código:** as credenciais de F3 já circularam e
**precisam ser rotacionadas** na infraestrutura; e convém confirmar se `/var/www/painel/tmp`
(F7) esteve exposto pela web enquanto o arquivo era gerado — se sim, tratar como incidente, não
só como bug corrigido.

---

## Verificação (resumo)

- **Caracterização das 7 funções públicas** antes/depois: saída idêntica, exceto os dois casos
  de curinga de `LIKE` descritos em Decisões. CSV **byte a byte igual** (`cmp` ok).
- **Segurança:** `UNION` na busca agora retorna vazio; XSS sai como entidades; `ana` não vê
  chamados de `bruno` (lista, CSV e detalhe); parâmetros-array não derrubam mais nenhuma rota.
- **Robustez:** média com banco sem respostas devolve `0.0` e a listagem renderiza; falha de
  conexão mostra mensagem genérica e loga o detalhe.
- **Contrato HTML/CSV:** `id="tabela-chamados"`, ordem das colunas, links `?ver=<id>`, cabeçalho
  do CSV e rótulos de status/prioridade inalterados.
- **Performance:** listagem e export passaram de 5 para 1 consulta.
- **Migração de senha:** com coluna `CHAR(32)` permanece `md5`; após o `ALTER`, o login converte
  para bcrypt e segue autenticando.
