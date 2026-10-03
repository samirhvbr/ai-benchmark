# Relatório técnico — LEB-100-A

## Resumo

Painel PHP/mysqli de chamados (NetX ISP) com superfície pública estável (`lib.php` + rotas GET de `index.php`), mas com falhas reais de injeção SQL, quebra da regra de visibilidade cliente/técnico, XSS refletido e segredos de produção no código. A autenticação por MD5 e o esquema `CHAR(32)` foram deixados como estão para não quebrar logins existentes. As correções evoluem o código no lugar: prepared statements, autorização na camada web, CSV direto na saída, média à prova de conjunto vazio.

## Achados

### F1 — Injeção SQL em `listarChamados` via `busca`

- **Onde:** `code/lib.php` linha 82 (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`), alimentado por `index.php?busca=`.
- **Mecanismo:** `$busca` entra na query por concatenação, sem escape nem placeholder. Um termo como `' OR 1=1 -- ` altera o predicado; um UNION ou stacked query (conforme `mysqli`/config) lê `usuarios` (hashes MD5 inclusive) ou interfere em outras tabelas. `autenticar` já usava prepared statement; esta função não.
- **Impacto / severidade:** crítica. Qualquer sessão autenticada (e, na prática, o parâmetro GET) controla o SQL.
- **Confiança:** 100.
- **O que fiz:** `WHERE c.titulo LIKE ?` com `bind_param('s', $like)`. Assinatura `listarChamados(mysqli, string $busca = ''): array` inalterada.

### F2 — Cliente vê (e exporta) chamados de outros

- **Onde:** `code/index.php` linhas 44–46 (CSV), 52–53 (`ver`) e 72–73 (listagem). `listarChamados` / `verChamado` / `exportarCsv` não recebem usuário e devolvem a base inteira; a UI nunca filtra por `$uid`/`$papel`.
- **Mecanismo:** Ana (`usuario_id=1`) autenticada acessa `index.php` e recebe os 5 chamados do seed, inclusive 103 e 104 de Bruno. `index.php?ver=103` abre o detalhe com descrição. `index.php?export=csv` baixa o CSV gerencial completo. O manifesto exige: cliente só os que abriu; técnico qualquer um.
- **Impacto / severidade:** crítica. Quebra a regra de negócio e vaza dados entre clientes.
- **Confiança:** 100.
- **O que fiz:** filtro na camada web, sem mudar as assinaturas de `lib.php` (relatório gerencial e rotina noturna continuam vendo tudo ao chamar as funções). Listagem e detalhe: não-técnico só `usuario_id === uid`; detalhe alheio responde como “nao encontrado”. CSV da rota web: técnico segue em `exportarCsv` (todos, `ORDER BY id`); cliente recebe o mesmo cabeçalho/colunas, só as linhas dele, também por `id` crescente.

### F3 — XSS refletido no termo de busca

- **Onde:** `code/index.php` linhas 79 e 82.
- **Mecanismo:** `$_GET['busca']` é interpolado cru no `value="..."` do input e em `Resultados para: ...`. Um valor `"><script>alert(1)</script>` quebra o atributo e executa no navegador da vítima (link com `busca=`). Título/descrição já passavam por `htmlspecialchars`; o termo de busca não.
- **Impacto / severidade:** alta. Roubo de sessão do painel.
- **Confiança:** 100.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.

### F4 — Senha de produção e API key no fonte

- **Onde:** `code/config.php` linhas 12 e 15.
- **Mecanismo:** `DB_PASS` cai em `'N3tX@2013!prod'` quando o env está vazio (`?:` trata `''` como ausente). `SMTP_API_KEY` é literal `'netx-smtp-9f83e2c1a7b64d05'`, sem getenv. Qualquer clone do repositório, backup ou `var_dump` de constantes leva credencial real de 2013 e chave SMTP.
- **Impacto / severidade:** alta. Acesso ao MySQL de produção e à central de e-mail.
- **Confiança:** 100.
- **O que fiz:** ambos só via `getenv`, fallback vazio. `DB_HOST`/`DB_NAME`/`DB_USER` (não secretos) permanecem com default local.

### F5 — Senha armazenada e comparada com MD5

- **Onde:** `code/lib.php` linha 15 (`$hash = md5($senha)`); espelhado em `code/schema.sql` linha 7 (`CHAR(32)`).
- **Mecanismo:** o hash vai no `WHERE senha = ?`. MD5 é rápido e sem salt: rainbow table quebra `senha123`/`tecmaster` (seed) e qualquer senha comum. Trocar para `password_hash` exige coluna maior que 32 caracteres e invalida as senhas atuais — `autenticar` deixaria de autenticar Ana/Bruno/Carla/Diego.
- **Impacto / severidade:** alta (credenciais reversíveis), mas correção quebra o contrato de login.
- **Confiança:** 100.
- **O que fiz:** não alterei. Ver Decisões.

### F6 — `exportarCsv` depende de path de produção e arquivo compartilhado

- **Onde:** `code/lib.php` linhas 125–128 e 148–150.
- **Mecanismo:** grava `/var/www/painel/tmp/chamados.csv`. Se o diretório não existe (qualquer host que não seja esse path de 2013), `fopen` falha e a função `return` sem header e sem CSV — a rota `export=csv` entrega corpo vazio. Dois pedidos simultâneos sobrescrevem o mesmo arquivo; `readfile` pode servir CSV pela metade. O manifesto pede escrever o CSV **na saída**.
- **Impacto / severidade:** média. Export quebrado fora da árvore original; condição de corrida no tmp.
- **Confiança:** 95.
- **O que fiz:** headers primeiro e `fopen('php://output')`. JOIN no lugar do N+1 de `tecnicoNome`. Constante `EXPORT_DIR` mantida no config (outros scripts podem referenciá-la).

### F7 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php` linha 116 (`return $soma / $qtd`).
- **Mecanismo:** só entram linhas com `minutos_resposta IS NOT NULL`. Banco sem 1ª resposta (`$qtd === 0`) dispara divisão por zero (PHP 8: `DivisionByZeroError`; 7: `INF`/warning). O seed mascara o bug (três valores preenchidos).
- **Impacto / severidade:** média. Painel cai na listagem, que sempre chama `mediaResposta`.
- **Confiança:** 95.
- **O que fiz:** `SELECT AVG(minutos_resposta)` e `0.0` se o agregado for `NULL`. Retorno `float` preservado.

### F8 — N+1 em `listarChamados` / `tecnicoNome`

- **Onde:** `code/lib.php` linhas 88–90, consulta em 69.
- **Mecanismo:** cada linha da listagem dispara `SELECT nome FROM usuarios WHERE id = ...`. Cinco chamados no seed viram 6 queries; em produção cresce linearmente com a fila.
- **Impacto / severidade:** média (performance).
- **Confiança:** 100.
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(u.nome, '-') AS tecnico_nome`, mesmo formato de chave. `tecnicoNome` permanece (assinatura usada internamente) e passou a prepared statement.

### F9 — Sessão não regenerada após login

- **Onde:** `code/index.php` linhas 23–26.
- **Mecanismo:** `$_SESSION['uid']` e `papel` são gravados no id de sessão que o cliente já trazia. Atacante que plantou `PHPSESSID` (cookie fixo, subdomínio, log) herda a sessão autenticada.
- **Impacto / severidade:** média.
- **Confiança:** 80.
- **O que fiz:** `session_regenerate_id(true)` imediatamente antes de gravar `uid`/`papel`.

## Decisões

- **MD5 / coluna `senha CHAR(32)`:** não migrei. O seed e os consumidores de `autenticar` dependem do hash atual; bcrypt não cabe em 32 caracteres.
- **Assinaturas de `lib.php`:** não acrescentei `$uid`/`$papel` em `listarChamados`, `verChamado` nem `exportarCsv`. Relatório gerencial e export noturno chamam essas funções sem contexto HTTP e precisam da base inteira. A visibilidade é aplicada só em `index.php`.
- **`formatarStatus`:** o `else` continua devolvendo `"Resolvido"` para qualquer valor ≠ 1 e ≠ 2. O contrato só fixa 1/2/3; o relatório gerencial casa por esses textos. Estreitar o `else` poderia rotular status legado diferente de 3 com outra string e quebrar correspondência.
- **`rotuloPrioridade` e HTML do painel:** lógica aninhada e markup mínimo não violam contrato; reescrever seria cosmética.
- **Stack mysqli, nomes de arquivo, rotas `busca`/`ver`/`export`, cabeçalho CSV, `id="tabela-chamados"` e ordem das colunas:** intocados.
- **CSRF no POST de login, rate limit, cookie `HttpOnly`/`Secure`:** não há token legado nem camada de middleware; introduzir CSRF mudaria o formulário que automações de login possam postar. Ficou fora de escopo.
- **`schema.sql` / `seed.sql`:** não alterei. São a base de caracterização.
- **`EXPORT_DIR`:** a constante permanece; só a implementação de `exportarCsv` deixou de depender dela para cumprir “escreve o CSV na saída”.
