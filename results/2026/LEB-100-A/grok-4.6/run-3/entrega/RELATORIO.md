# Relatório técnico — LEB-100-A

## Resumo

Painel PHP/mysqli de chamados (NetX ISP, ~2013). A superfície pública (assinaturas em `lib.php`, rotas GET, CSV, tabela HTML e rótulos de status) está preservada. O código original autenticava, mas **não aplicava a regra de visibilidade** no painel: qualquer cliente autenticado listava, abria e exportava chamados de outros clientes. Havia SQL injection no filtro `busca`, XSS refletido no mesmo termo, N+1 ao resolver o técnico, divisão por zero na média de SLA e exportação CSV via arquivo compartilhado em disco. Corrigi esses pontos no painel e nas funções internas, sem reescrever a stack nem alterar o contrato dos consumidores (rotina noturna, relatório gerencial).

---

## Achados

### F1 — SQL injection em `listarChamados` via `busca`

- **Onde:** `code/lib.php:81–83` (original). `index.php` passa `$_GET['busca']` sem sanitizar.
- **Mecanismo:** o termo é concatenado em `'WHERE titulo LIKE \'%' . $busca . '%\''` e executado com `$db->query()`. Um valor como `' OR '1'='1` fecha o LIKE, torna o predicado verdadeiro e devolve todos os chamados; um UNION ou quebra de aspas altera a consulta. `mysqli::query` não é multi-query, mas a injeção na cláusula WHERE é suficiente.
- **Impacto / severidade:** **crítica**. Qualquer usuário autenticado (e, em cadeia com F2, um cliente) lê ou distorce a listagem; combinado com F2, amplia o vazamento entre clientes.
- **Confiança:** 98
- **O que fiz:** prepared statement com `LIKE ?`, bind de string, e escape de `\`, `%` e `_` no termo para não virarem curingas.

### F2 — Painel ignora a regra de visibilidade (IDOR)

- **Onde:** `code/index.php:44–46` (export), `52–53` (detalhe), `72–73` (listagem). `$uid` e `$papel` são lidos e nunca usados para autorizar.
- **Mecanismo:** `listarChamados` faz `SELECT * FROM chamados` sem `usuario_id`. `verChamado` carrega qualquer `id`. `exportarCsv` exporta a tabela inteira. Um cliente (`ana`) em `index.php?ver=103` vê o chamado de `bruno`; a listagem e o CSV mostram títulos, status e técnico de todos. A regra do manifesto (“cliente só vê os que ele abriu; técnico vê qualquer um”) não era aplicada na UI.
- **Impacto / severidade:** **crítica**. Quebra de isolamento entre clientes (LGPD / sigilo de atendimento).
- **Confiança:** 100
- **O que fiz:** filtro no **painel** (`index.php`): se `papel !== 'tecnico'`, listagem e CSV web ficam restritos a `usuario_id === uid`; detalhe inexistente ou de outro cliente responde “Chamado nao encontrado” (não vaza existência com 403). **Não** filtrei `listarChamados` / `verChamado` / `exportarCsv` em `lib.php`: a rotina noturna e o relatório gerencial dependem do conjunto completo. Técnico continua vendo tudo.

### F3 — XSS refletido no termo de busca

- **Onde:** `code/index.php:79` (atributo `value`) e `82` (texto “Resultados para:”).
- **Mecanismo:** `$busca = $_GET['busca']` é interpolado cru no HTML. Título e descrição usam `htmlspecialchars`, mas o termo de busca não. `value="' . $busca . '"` quebra o atributo com `"`; o parágrafo de resultados interpreta tags. Payload típico: `"><script>…` ou `<img onerror=…>`.
- **Impacto / severidade:** **alta**. Sessão do técnico/cliente no painel (sessão PHP) pode ser alvo de script no origem.
- **Confiança:** 95
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.

### F4 — Senhas em MD5 sem salt

- **Onde:** `code/lib.php:15`; coluna `usuarios.senha CHAR(32)` em `schema.sql`.
- **Mecanismo:** `autenticar` faz `$hash = md5($senha)` e compara no SQL. MD5 é rápido e sem salt: dump da tabela (ou SQLi) permite cracking em massa; o CHAR(32) amarra o formato.
- **Impacto / severidade:** **alta**. Comprometimento das contas (`ana`/`bruno`/`carla`/`diego` e produção).
- **Confiança:** 100
- **O que fiz:** **não migrei**. Trocar para `password_hash` exige coluna maior, dual-read dos hashes atuais e mexida no seed/schema. A assinatura de `autenticar` se manteria, mas a base em produção quebraria. Fica como dívida explícita.

### F5 — Segredos de produção no código

- **Onde:** `code/config.php:12` (`DB_PASS` fallback `N3tX@2013!prod`) e `15` (`SMTP_API_KEY` literal).
- **Mecanismo:** quem lê o repositório ou este pacote obtém senha real de MySQL e chave SMTP. O fallback de `DB_PASS` ainda é usado se o ambiente não define a variável.
- **Impacto / severidade:** **alta**. Acesso ao banco e à API de e-mail.
- **Confiança:** 100
- **O que fiz:** **não alterei** `config.php`. Remover o fallback derruba o legado se o processo ainda depende dele; a constante `SMTP_API_KEY` pode ser lida por outros scripts do ISP que incluem `config.php`. Rotação das chaves é operacional, fora deste pacote.

### F6 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php:107–116` (divisão na 116).
- **Mecanismo:** soma `minutos_resposta` em PHP e faz `$soma / $qtd`. Se nenhum chamado tem valor (tabela vazia ou só NULLs), `$qtd === 0`. Em PHP 8 isso é `DivisionByZeroError` e derruba o painel na listagem (`index.php:74`).
- **Impacto / severidade:** **média**. Indisponibilidade da tela principal num estado válido do banco.
- **Confiança:** 92
- **O que fiz:** `AVG(minutos_resposta)` no SQL; se o resultado é NULL, retorno `0.0`. Mesmo tipo `float`, mesmo indicador.

### F7 — N+1 ao resolver `tecnico_nome`

- **Onde:** `code/lib.php:88–90` (listagem) e `137` (CSV).
- **Mecanismo:** para cada linha de `chamados`, `tecnicoNome` dispara `SELECT nome FROM usuarios WHERE id = …`. Cinco chamados no seed = 5 queries extras; em produção cresce linearmente, inclusive no export noturno.
- **Impacto / severidade:** **média**. Latência e carga no MySQL na listagem e no CSV.
- **Confiança:** 95
- **O que fiz:** `LEFT JOIN usuarios` em `listarChamados` e `exportarCsv`. `tecnicoNome` permanece (assinatura interna já usada) e passou a prepared statement.

### F8 — CSV gravado em arquivo fixo compartilhado

- **Onde:** `code/lib.php:125–150`.
- **Mecanismo:** escreve `EXPORT_DIR . '/chamados.csv'` (`/var/www/painel/tmp/chamados.csv`) e depois `readfile`. Se o diretório não existe, `fopen` falha e a função retorna **sem CSV e sem erro**. Dois exports simultâneos se sobrescrevem. O contrato é “escreve o CSV na saída”, não “grava um arquivo global”.
- **Impacto / severidade:** **média**. Export mudo em ambiente sem esse path; condição de corrida; arquivo com dados de todos os chamados parado em disco.
- **Confiança:** 88
- **O que fiz:** `fopen('php://output')` após os headers. Cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, uma linha por chamado, `ORDER BY id`, status via `formatarStatus`. `EXPORT_DIR` permanece em `config.php` para outros scripts.

### F9 — Concatenação SQL em `verChamado` e `tecnicoNome`

- **Onde:** `code/lib.php:69` e `100`.
- **Mecanismo:** `'… id = ' . $tecnicoId` / `$id`. Os parâmetros são `int`/`?int`, então o type hint do PHP 8 já coerção e corta o vetor clássico de injeção. Continua sendo query montada por concatenação, inconsistente com `autenticar`.
- **Impacto / severidade:** **baixa** (exploração direta improvável com a type hint).
- **Confiança:** 70
- **O que fiz:** prepared statements com bind `i`. Comportamento de retorno inalterado.

### F10 — Session fixation no login

- **Onde:** `code/index.php:23–27`.
- **Mecanismo:** login bem-sucedido grava `uid`/`papel` na sessão já existente. Se a vítima autenticar com um cookie de sessão pré-plantado (link, subdomain), o atacante reutiliza o id.
- **Impacto / severidade:** **média**. Sequestro de sessão após o login.
- **Confiança:** 80
- **O que fiz:** `session_regenerate_id(true)` antes de gravar `$_SESSION`.

---

## Decisões (o que não mudei)

- **Hash MD5 e `schema.sql`/`seed.sql`:** ver F4. Migrar senha quebra CHAR(32) e as contas atuais.
- **`config.php` (senha DB e SMTP):** ver F5. Risco operacional e possíveis includes externos.
- **`formatarStatus` para valores ≠ 1,2,3:** o `else` devolve `"Resolvido"`. Corrigir com um quarto rótulo quebraria o relatório gerencial (contrato: só “Aberto” / “Em atendimento” / “Resolvido”). Status 3 já cai no else e permanece correto.
- **`rotuloPrioridade`:** prioridade 1–2 com SLA estourado continua “Normal”. Não há contrato de valores; não inventei regra.
- **`mediaResposta` global no topo, inclusive para cliente:** é KPI agregado, não lista de chamados. Filtrar por dono mudaria o número que o painel sempre mostrou e a função pública não recebe usuário.
- **`listarChamados` / `verChamado` / `exportarCsv` sem filtro de dono:** consumidores internos precisam do universo completo. A regra de visibilidade vale no painel.
- **Assinaturas, rotas (`busca`, `ver`, `export`), HTML `#tabela-chamados`, ordem das colunas, cabeçalho CSV.**
- **Stack mysqli, nomes de arquivo, ausência de CSRF/logout:** CSRF no login e logout não estavam no contrato; adicionar token mudaria o form sem ganho pedido. Não reescrevi o sistema.
