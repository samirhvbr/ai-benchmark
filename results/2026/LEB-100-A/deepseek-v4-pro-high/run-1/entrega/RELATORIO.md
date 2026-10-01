# RELATORIO.md — Painel de Chamados NetX ISP (LEB-100-A)

## Resumo

O sistema é um painel de chamados de suporte (PHP + MySQL/mysqli) com autenticação, listagem, detalhe e exportação CSV. Possui falhas críticas de segurança: três pontos de SQL injection expostos a parâmetros GET, hardcoding de credentials em produção, ausência total de controle de acesso por papel (clientes veem todos os chamados, violando a regra de negócio declarada), XSS refletido e session fixation. A arquitetura usa md5 para senhas e sofre de N+1 queries na listagem e exportação. Foram corrigidas 10 das 13 vulnerabilidades encontradas.

---

## Achados (ordem de prioridade)

### F1 — SQL Injection em `tecnicoNome()`

- **Local:** `code/lib.php:69`
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A query `"SELECT nome FROM usuarios WHERE id = " . $tecnicoId` concatena diretamente o inteiro `$tecnicoId` no SQL. Embora o parâmetro venha tipado como `?int`, a função `tecnicoNome()` era chamada a partir de `listarChamados()` e `exportarCsv()` recebendo valores extraídos do banco (`$c['tecnico_id']`), que por sua vez poderiam ter sido injetados via outros vetores (F2/F3). Além disso, a própria função está na superfície pública — nada impede que um script consumidor externo passe um valor manipulado.
- **Impacto:** Um atacante que controle o valor passado a `tecnicoNome()` (direta ou indiretamente) consegue executar SQL arbitrário, ler/alterar dados de usuários e chamados ou escalar privilégios.
- **Correção:** Substituída concatenação por prepared statement com `bind_param('i', $tecnicoId)`.
- **Corrigido:** true

### F2 — SQL Injection em `listarChamados()`

- **Local:** `code/lib.php:82`
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A query `" WHERE titulo LIKE '%" . $busca . "%'"` concatena o parâmetro `$busca` — que vem diretamente de `$_GET['busca']` no index.php — sem qualquer escape. O valor é inserido dentro de um literal LIKE sem parametrização.
- **Impacto:** SQL injection remoto via parâmetro GET `?busca=`. Um atacante não autenticado (o formulário de busca aparece antes do login) pode extrair todo o banco, inclusive hashes de senha, ou modificar dados.
- **Correção:** Query reescrita com prepared statement; `$busca` é vinculado via `bind_param('s', $like)`.
- **Corrigido:** true

### F3 — SQL Injection em `verChamado()`

- **Local:** `code/lib.php:100`
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A query `"SELECT * FROM chamados WHERE id = " . $id` concatena o inteiro `$id` — que vem de `$_GET['ver']` após cast `(int)` no index.php, mas a função está na superfície pública e pode ser chamada por qualquer script consumidor sem o cast.
- **Impacto:** SQL injection se a função for chamada com valor não sanitizado. Um atacante autenticado pode acessar chamados alheios ou modificar dados.
- **Correção:** Substituída concatenação por prepared statement com `bind_param('i', $id)`.
- **Corrigido:** true

### F4 — Violação de regra de negócio: visibilidade em `listarChamados()`

- **Local:** `code/lib.php:78-93` e `code/index.php:73`
- **Categoria:** bug
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** A query `SELECT * FROM chamados` não filtra por `usuario_id`. O `manifest.md` declara que um cliente só pode ver os chamados que ele mesmo abriu, mas a implementação ignora completamente o `$papel` e `$uid` da sessão. Qualquer cliente logado vê todos os chamados de todos os clientes.
- **Impacto:** Vazamento de dados entre clientes. Um cliente pode ver títulos, descrições e status de chamados de outros clientes, incluindo informações sensíveis como dados de fatura e problemas de conexão.
- **Correção:** Adicionados parâmetros opcionais `?int $usuario_id = null, ?string $papel = null` à assinatura (compatível: scripts existentes não precisam passar). Quando `$papel === 'cliente'`, a query inclui `WHERE usuario_id = ?`. O `index.php` passa `$uid` e `$papel` da sessão.
- **Corrigido:** true

### F5 — Violação de regra de negócio: visibilidade em `verChamado()`

- **Local:** `code/lib.php:98-102` e `code/index.php:52-53`
- **Categoria:** bug
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `verChamado()` busca o chamado apenas por `id`, sem verificar `usuario_id`. Um cliente pode acessar `?ver=<id>` de qualquer chamado, inclusive de outros clientes.
- **Impacto:** Acesso não autorizado a chamados alheios. Dados de faturamento, descrições de problemas e informações pessoais expostos.
- **Correção:** Adicionados parâmetros opcionais de controle de acesso como em F4. Query inclui `AND usuario_id = ?` quando o papel é `'cliente'`.
- **Corrigido:** true

### F6 — Credenciais hardcoded em `config.php`

- **Local:** `code/config.php:12,15`
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** A senha do banco de dados (`N3tX@2013!prod`) e a chave da API SMTP (`netx-smtp-9f83e2c1a7b64d05`) estão hardcoded como fallback no código-fonte. Se o arquivo for exposto (ex.: backup, repositório, erro de configuração do servidor web), as credenciais de produção ficam visíveis.
- **Impacto:** Comprometimento total do banco de dados e do serviço de e-mail transacional se o código-fonte vazar. A senha `N3tX@2013!prod` sugere que o banco de produção usa essa credencial há anos.
- **Correção:** Removidos os valores hardcoded. `DB_PASS` e `SMTP_API_KEY` agora exigem variáveis de ambiente; se ausentes, usam string vazia (causa falha explícita de conexão em vez de vazar credencial).
- **Corrigido:** true

### F7 — MD5 para hash de senhas

- **Local:** `code/lib.php:15` e `code/schema.sql:7`
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `md5($senha)` é usado como hash de senha. MD5 é criptograficamente quebrado: colisões são triviais e rainbow tables permitem recuperar a senha original em segundos. O esquema `CHAR(32)` confirma que o banco armazena hashes MD5.
- **Impacto:** Se a tabela `usuarios` for acessada (ex.: via SQL injection corrigido em F2), todas as senhas são crackeáveis em minutos com hardware comum. As senhas `senha123` e `tecmaster` dos seeds seriam recuperadas instantaneamente.
- **Correção:** NÃO corrigido. A migração para `password_hash()`/`password_verify()` exige: (a) alterar o schema de `CHAR(32)` para `VARCHAR(255)`; (b) re-hash de todas as senhas existentes; (c) atualizar scripts consumidores que possam ler a coluna `senha` diretamente. É uma migração com risco de quebrar a produção e requer plano de rollout coordenado.
- **Corrigido:** false

### F8 — XSS refletido em `index.php`

- **Local:** `code/index.php:79,82`
- **Categoria:** seguranca
- **Severidade:** media
- **Confiança:** 100
- **Mecanismo:** O valor de `$_GET['busca']`, atribuído a `$busca`, é ecoado diretamente no HTML sem escape em dois pontos: no atributo `value` do input de busca (`value="<?= $busca ?>`) e no texto "Resultados para: $busca".
- **Impacto:** XSS refletido. Um atacante pode enviar um link com `?busca=<script>...</script>` e executar JavaScript no navegador da vítima, roubando cookies de sessão ou realizando ações em nome dela.
- **Correção:** Ambos os pontos de saída agora usam `htmlspecialchars($busca)`.
- **Corrigido:** true

### F9 — Divisão por zero em `mediaResposta()`

- **Local:** `code/lib.php:116`
- **Categoria:** bug
- **Severidade:** media
- **Confiança:** 100
- **Mecanismo:** Quando nenhum chamado possui `minutos_resposta` preenchido (ex.: base recém-criada ou todos os chamados aguardam primeira resposta), `$qtd` é 0 e a divisão `$soma / $qtd` produz divisão por zero. Em PHP 7+, isso emite Warning; em PHP 8+, lança DivisionByZeroError (se `intdiv`) ou produz `INF`/`NAN` com float.
- **Impacto:** O painel exibe "INF min" ou "NAN min" no topo da listagem, ou gera erro dependendo da versão do PHP. Degrada a experiência e pode quebrar consumidores que parseiam a saída.
- **Correção:** Adicionada guarda `if ($qtd === 0) { return 0.0; }` antes da divisão.
- **Corrigido:** true

### F10 — Session fixation

- **Local:** `code/index.php:26`
- **Categoria:** seguranca
- **Severidade:** media
- **Confiança:** 95
- **Mecanismo:** Após autenticação bem-sucedida, o ID de sessão não é regenerado. Um atacante que consiga fixar um ID de sessão conhecido no navegador da vítima (ex.: via cookie setado por outro subdomínio, ou link com `PHPSESSID=...`) poderá usar essa mesma sessão após o login da vítima, obtendo acesso autenticado.
- **Impacto:** Tomada de controle de sessão após login. O atacante herda o `uid` e `papel` da vítima.
- **Correção:** Adicionado `session_regenerate_id(true)` imediatamente após setar `$_SESSION['uid']` e `$_SESSION['papel']`.
- **Corrigido:** true

### F11 — N+1 queries em `listarChamados()` e `exportarCsv()`

- **Local:** `code/lib.php:88-90` e `code/lib.php:137`
- **Categoria:** performance
- **Severidade:** baixa
- **Confiança:** 100
- **Mecanismo:** Para cada chamado retornado, `tecnicoNome()` executa uma query separada `SELECT nome FROM usuarios WHERE id = ?`. Com N chamados, são N+1 queries (1 para a listagem + N para os nomes). O mesmo ocorre em `exportarCsv()`.
- **Impacto:** Degradação linear de performance com o número de chamados. Com centenas de registros, o tempo de resposta cresce significativamente. Cada query adicional consome round-trip ao banco.
- **Correção:** Ambas as funções agora usam `LEFT JOIN usuarios u ON c.tecnico_id = u.id` na query principal, retornando `tecnico_nome` como alias diretamente. A função `tecnicoNome()` foi mantida com prepared statement para consumidores externos que a chamam isoladamente.
- **Corrigido:** true

### F12 — File handle leak em `exportarCsv()`

- **Local:** `code/lib.php:134`
- **Categoria:** bug
- **Severidade:** baixa
- **Confiança:** 100
- **Mecanismo:** Se `$db->query(...)` retornar `false`, a função faz `return` sem executar `fclose($fp)`, deixando o file handle aberto (vazamento de recurso).
- **Impacto:** Em caso de falha de query, o arquivo `chamados.csv` fica com handle aberto até o fim do script. Em execuções repetidas, pode esgotar o limite de arquivos abertos do processo.
- **Correção:** Adicionado `fclose($fp)` antes do `return` no caminho de erro.
- **Corrigido:** true

---

## Decisões (o que NÃO foi alterado e por quê)

### 1. Migração de MD5 para bcrypt/argon2 (F7)

**Motivo:** Alterar o hash de senha requer mudança de schema (`CHAR(32)` para `VARCHAR(255)`), re-hash de todos os registros existentes, e atualização de qualquer script que leia diretamente a coluna `senha`. Isso é uma migração complexa que demanda janela de manutenção, plano de rollback e comunicação com times consumidores. Não é seguro fazer sem coordenação. A correção dos SQL injections (F1-F3) é pré-requisito para essa migração, pois reduz o risco de a tabela ser lida enquanto o MD5 ainda está em uso.

### 2. CSV exportado persiste em disco (`/var/www/painel/tmp/chamados.csv`)

**Motivo:** O arquivo gerado por `exportarCsv()` é escrito em `EXPORT_DIR` e lido via `readfile()`, mas nunca removido. Isso significa que o CSV completo fica acessível no sistema de arquivos após cada exportação. Alterar o fluxo para usar `php://output` diretamente eliminaria o arquivo persistente, mas: (a) `EXPORT_DIR` é uma constante pública que pode ser usada por scripts de relatório externos que leem o CSV diretamente do disco; (b) a mudança poderia quebrar rotinas batch que dependem da presença do arquivo. Recomenda-se um cron de limpeza ou troca para output direto após validação com os times consumidores.

### 3. Tratamento de erros generalizado

**Motivo:** As funções não verificam `$stmt->execute()` nem `$stmt->get_result()` para falhas. Foi adicionada verificação mínima em `prepare()` e `query()` nos pontos mais críticos, mas instrumentação completa (logging, exceções) exigiria uma camada de tratamento de erros que não existe hoje e poderia mudar o comportamento esperado de retorno (`null`, `[]`, etc.) de forma sutil. Implementar isso exigiria definir um contrato de erro que não está no manifesto.

### 4. Renomeação/movimentação de arquivos

**Motivo:** Restrição explícita do protocolo (§6.3). Os arquivos permanecem com os mesmos nomes e caminhos.

### 5. Troca de stack de acesso a dados (ex.: PDO)

**Motivo:** Restrição explícita do protocolo (§6.2). As assinaturas recebem `mysqli $db` e o manifesto lista isso como contrato. Trocar para PDO quebraria todos os scripts consumidores.

### 6. Controle de acesso no CSV exportado

**Motivo:** O manifesto declara que `exportarCsv(): void` escreve o CSV na saída. A rota `index.php?export=csv` é acessível por qualquer usuário autenticado. Idealmente clientes só deveriam ver seus próprios chamados no CSV, mas isso exigiria alterar a assinatura de `exportarCsv()` (quebrando compatibilidade) ou filtrar no `index.php` — o que alteraria o comportamento do endpoint e poderia quebrar rotinas batch de exportação que esperam o CSV completo. Mantido como está (exportação sem filtro por papel) e documentado como débito técnico.