# RELATORIO.md — Manutenção do Painel de Chamados (LEB-100-A)

## Resumo

O sistema é um painel de chamados em PHP/mysqli, pequeno e funcional, mas com dois
problemas graves: **SQL injection na busca** e a **regra de visibilidade do manifesto
(cliente só vê os próprios chamados) simplesmente não implementada** — qualquer cliente
logado listava, detalhava e exportava os chamados de todos. Há ainda XSS refletido,
divisão por zero potencial, N+1 de consultas, senhas em MD5 e segredos versionados no
código. Corriji tudo o que podia ser corrigido **sem tocar nas assinaturas públicas, nas
rotas, no formato do CSV, na estrutura do HTML da listagem e nos rótulos de status**.
Todas as correções foram verificadas com o sistema rodando (PHP built-in server +
MariaDB com `schema.sql`/`seed.sql`), exercitando login, listagem, busca, detalhe e
exportação como cliente e como técnico.

## Achados (em ordem de prioridade)

### F1 — SQL injection na busca da listagem
- **Onde:** `code/lib.php:82` (`listarChamados`).
- **Mecanismo:** `$busca` vem de `$_GET['busca']` em `index.php:72` e é concatenada
  diretamente na query: `WHERE titulo LIKE '%" . $busca . "%'`, executada por
  `$db->query()`. Uma requisição `?busca=' OR 1=1 -- ` fecha a literal e injeta SQL;
  em MySQL com `mysqli::query` (sem `MULTI_STATEMENTS`) não há segunda instrução, mas a
  cláusula pode ser reescrita para vazar/alterar o filtro — e é uma porta de entrada
  trivial para enumeração.
- **Severidade:** crítica · **Confiança:** 100.
- **O que fiz:** substituí por prepared statement (`LIKE ?` com `%termo%` no bind).
  Verificado: `?busca=x' OR 1=1 -- ` agora retorna HTTP 200 sem erro e sem resultados
  indevidos.

### F2 — Regra de visibilidade não implementada (cliente vê tudo)
- **Onde:** `code/index.php:52-73` (rotas `ver` e listagem); consulta em `code/lib.php:80`.
- **Mecanismo:** o manifesto define que cliente só vê chamados que ele mesmo abriu.
  Porém `listarChamados` faz `SELECT * FROM chamados` sem filtro de dono, e a rota
  `ver` chama `verChamado($db, $id)` sem conferir `usuario_id`. O `index.php` conhece
  `$_SESSION['uid']`/`$_SESSION['papel']`, mas nunca os usa para restringir nada. Com
  login de `ana` (cliente), a listagem exibia os chamados 101–105, incluindo os de
  `bruno`.
- **Severidade:** alta · **Confiança:** 98.
- **O que fiz:** o filtro foi aplicado na camada web, sem mudar assinaturas: na
  listagem, `index.php` filtra o resultado por `usuario_id == uid` quando `papel ==
  'cliente'`; na rota `ver`, chamado de outro cliente responde "Chamado nao encontrado"
  (mesma mensagem do id inexistente, sem revelar existência). Mantive as funções de
  `lib.php` genéricas de propósito — os scripts internos do ISP (rotina noturna,
  relatório gerencial) também as chamam e precisam do conjunto completo. Verificado:
  `ana` vê só 101/102/105; `carla` (técnica) vê todos.

### F3 — XSS refletido no parâmetro `busca`
- **Onde:** `code/index.php:79` (atributo `value` do formulário) e `code/index.php:82`
  (texto "Resultados para:").
- **Mecanismo:** `$busca` é ecoada no HTML sem escape. `?busca="><script>alert(1)</script>`
  fecha o atributo `value` e executa script no navegador da vítima (link de busca
  envenenado, por exemplo). Os demais ecos do sistema já usavam `htmlspecialchars`.
- **Severidade:** alta · **Confiança:** 100.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos. Verificado: o
  payload vem escapado (`&lt;script&gt;`).

### F4 — Exportação CSV expõe todos os chamados ao cliente
- **Onde:** `code/lib.php:132` (`exportarCsv`, rota `index.php?export=csv`).
- **Mecanismo:** a rota de exportação é acessível a qualquer usuário logado e o
  `exportarCsv` sempre executa `SELECT * FROM chamados ORDER BY id` — inclusive para um
  cliente, entregando em arquivo o que a F2 entregava em tela. Como a função também é
  consumida por scripts internos (que rodam em CLI, sem sessão), o filtro não podia ser
  incondicional.
- **Severidade:** alta · **Confiança:** 80.
- **O que fiz:** quando há sessão ativa com `papel == 'cliente'`, a consulta vira
  `SELECT * FROM chamados WHERE usuario_id = ?` (prepared). Em CLI/`session_status() !=
  ACTIVE` a exportação permanece integral, preservando o consumidor interno. Cabeçalho,
  rótulos de status e ordenação por `id` inalterados — verificados byte a byte contra o
  formato do manifesto.

### F5 — Senhas com MD5 puro (sem salt)
- **Onde:** `code/lib.php:15`; `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:** `md5($senha)` é rápido e não hasheado com custo — hashes vazados em
  bulk são revertidos por tabela-rainha em segundos. Trocar para `password_hash`
  exigiria `VARCHAR(255)` na coluna (hoje `CHAR(32)`), o que altera o schema usado por
  outros sistemas do ISP, e exigiria migração de dados dos usuários existentes.
- **Severidade:** alta · **Confiança:** 95.
- **O que fiz:** **nada, por enquanto** (ver Decisões). A correção correta é
  `password_verify` + rehash transparente no login, precedida de `ALTER TABLE` e
  coordenada com os outros consumidores do banco.

### F6 — Segredos hardcoded em `config.php`
- **Onde:** `code/config.php:12` (`DB_PASS` com fallback de produção embutido) e
  `code/config.php:15` (`SMTP_API_KEY` literal no código).
- **Mecanismo:** qualquer pessoa com acesso ao repositório obtém a senha do banco de
  produção e a chave SMTP transacional. A chave de e-mail não tem fallback de ambiente —
  está só no fonte — e não pode ser "removida" do histórico; precisa ser **rotacionada**.
- **Severidade:** alta · **Confiança:** 90.
- **O que fiz:** **nada**, deliberadamente (ver Decisões): remover o fallback sem
  confirmar o provisionamento de variáveis de ambiente derruba o painel; e a chave já
  comprometida exige rotação no provedor, fora do alcance de um patch de código.

### F7 — Sessão não regenerada após login (fixação de sessão)
- **Onde:** `code/index.php:23-27`.
- **Mecanismo:** ao autenticar, o sistema apenas grava `$_SESSION['uid']` mantendo o
  mesmo `session ID` pré-autenticação. Um atacante que consiga fixar um ID de sessão
  (link, subdomínio) herda a sessão autenticada da vítima.
- **Severidade:** média · **Confiança:** 85.
- **O que fiz:** `session_regenerate_id(true)` no login bem-sucedido.

### F8 — Divisão por zero em `mediaResposta`
- **Onde:** `code/lib.php:116`.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (banco recém-migrado, ou
  filtro vazio), `$qtd` vale 0 e `$soma / $qtd` lança `DivisionByZeroError` no PHP 8 —
  a listagem inteira quebra, não só o indicador. Além disso, a média era computada em
  PHP linha a linha.
- **Severidade:** média · **Confiança:** 95.
- **O que fiz:** substituí o loop por `SELECT AVG(minutos_resposta)` e retornei `0.0`
  quando não há linhas. Verificado: com todos os valores `NULL`, retorna `float(0)` sem
  erro; com o seed, retorna `25.67` (idêntico ao cálculo antigo).

### F9 — N+1 de consultas na listagem
- **Onde:** `code/lib.php:88-91` (loop de `listarChamados` chamando `tecnicoNome`).
- **Mecanismo:** para cada chamado, uma query extra `SELECT nome FROM usuarios WHERE id
  = …`. Com N chamados na página, são N+1 viagens ao banco por requisição — a tabela
  cresce e a listagem degrada linearmente.
- **Severidade:** média · **Confiança:** 90.
- **O que fiz:** `LEFT JOIN usuarios` com `IFNULL(u.nome, '-')` dentro da própria
  consulta, mantendo exatamente a chave `tecnico_nome` e o valor `'-'` nos mesmos casos
  de antes. A função `tecnicoNome` foi preservada (o export e possíveis consumidores a
  usam).

### F10 — Export escreve arquivo temporário e falha em silêncio
- **Onde:** `code/lib.php:125-129, 148-150` (`exportarCsv`).
- **Mecanismo:** o CSV era gravado em `EXPORT_DIR . '/chamados.csv'` e depois servido
  com `readfile`. Se o diretório não existe ou não é gravável, `fopen` falha e a função
  retorna sem enviar **nada** — o usuário recebe resposta vazia/quebrada, sem diagnóstico.
  Arquivo compartilhado em disco também permite condição de corrida entre exportações
  simultâneas.
- **Severidade:** baixa · **Confiança:** 85.
- **O que fiz:** streaming direto para `php://output` (headers enviados antes do corpo).
  O contrato (`void`, CSV na saída, cabeçalho exato) não muda; `EXPORT_DIR` segue
  definido em `config.php` para quem mais o use.

### F11 — CSV injection (fórmulas) nos campos exportados
- **Onde:** `code/lib.php:138-144` (linhas do `exportarCsv`).
- **Mecanismo:** `titulo` é texto livre do usuário. Um chamado intitulado `=HYPERLINK(...)` ou
  `=cmd|' /C calc'!A1` é aberto no Excel/LibreOffice como fórmula por quem exporta —
  tipicamente um técnico com privilégios na estação.
- **Severidade:** média · **Confiança:** 70.
- **O que fiz:** **nada** (ver Decisões): neutralizar com prefixo `'` alteraria o
  conteúdo das células, e o CSV é consumido por integração de faturamento e relatório
  gerencial que fazem parse do arquivo.

### F12 — Formulário de login sem CSRF
- **Onde:** `code/index.php:32-34`.
- **Mecanismo:** o POST de login não valida token; um site externo pode submeter o
  formulário com as credenciais do atacante (login CSRF), logando a vítima numa sessão
  controlada por ele.
- **Severidade:** baixa · **Confiança:** 60.
- **O que fiz:** **nada** — impacto restrito, e a mudança exigiria tocar no formulário
  e em todos os pontos que o reproduzem; com o orçamento da manutenção, priorizei os
  achados acima.

## Decisões — o que deliberadamente NÃO mudei

1. **MD5 das senhas (F5).** A migração para `password_hash` exige `ALTER TABLE` numa
   coluna compartilhada com outros sistemas do ISP e rehash/migração dos usuários
   existentes. Fazer isso "por fora" travaria o login de todo mundo. O caminho seguro
   (verificar MD5 no login, rehashar com `password_hash` e alargar a coluna) é uma
   mudança de schema + operação coordenada — proposta no relatório, fora do patch.
2. **Segredos em `config.php` (F6).** Remover o fallback de `DB_PASS` sem confirmar que
   o ambiente de produção define a variável derruba o painel ao deployar. E
   `SMTP_API_KEY` já está comprometida no histórico do repositório: o remédio real é
   **rotacionar a chave e provisioná-la via ambiente**, ação de operações, não de código.
3. **Assinaturas e camada de acesso a dados.** `listarChamados`, `verChamado`,
   `mediaResposta`, `exportarCsv`, `autenticar`, `formatarStatus` e `rotuloPrioridade`
   mantiveram nome, parâmetros e retorno; a stack segue mysqli (sem PDO/ORM). Scripts
   internos que chamam essas funções não percebem a diferença — inclusive `tecnicoNome`,
   que deixei intacta (consulta com `int` tipado, sem injeção real).
4. **Comportamento de `formatarStatus` para valores fora de 1–3.** A função devolve
   "Resolvido" para qualquer valor não-1/2. O contrato só define 1–3; endurecer o `else`
   mudaria a saída hoje produzida para valores inválidos, e o relatório gerencial faz
   correspondência por texto. Risco de compatibilidade maior que o benefício.
5. **CSV injection (F11).** Qualquer escape muda o conteúdo das células e o faturamento
   consome esse arquivo. Deve ser decidido em conjunto com quem mantém o parser.
6. **CSRF no login (F12).** Baixo impacto e a correção toque na superfície do
   formulário; documentei em vez de consertar.
7. **Estrutura HTML e rotas.** `id="tabela-chamados"`, ordem das colunas
   (ID, Titulo, Status, Prioridade, Tecnico), links `?ver=<id>`, parâmetros
   `busca`/`ver`/`export` e a mensagem "Chamado nao encontrado." foram preservados tal
   como estavam.
8. **Consultas de `verChamado`/`tecnicoNome` por concatenação.** Ambas recebem `int`
   tipado pela assinatura, então não há injeção explorável; convertê-las a prepared
   statements seria só higienização estética. Deixei como está para minimizar o diff.

## Verificação

- `php -l` nos três arquivos PHP: sem erros de sintaxe.
- Servidor PHP + MariaDB com `schema.sql`/`seed.sql`:
  - `ana` (cliente): lista só 101/102/105; `?ver=103` → "Chamado nao encontrado.";
    `?export=csv` contém só os chamados dela, com cabeçalho
    `ID,Titulo,Status,Tecnico,"Aberto em"` e rótulos `Aberto`/`Em atendimento`/`Resolvido`.
  - `carla` (técnica): lista e exporta todos os 5 chamados; `?ver=104` funciona.
  - `?busca=Lentidao` retorna só o 102; `?busca=' OR 1=1 -- ` retorna 200 sem erro.
  - `?busca=<script>alert(1)</script>` vem escapado no HTML.
  - `mediaResposta` = 25.67 com o seed; 0.0 com todos os valores `NULL` (antes: erro fatal).
  - Login válido → 302; login inválido → formulário novamente.
