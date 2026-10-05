# RELATÓRIO TÉCNICO — LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP/MySQL (mysqli) com autenticação simples, listagem, detalhe e exportação CSV. O código funcionalmente está próximo do esperado, mas contém **três injeções de SQL críticas**, **quebra da regra de visibilidade cliente/técnico** e **divisão por zero** no indicador de SLA, além de outros problemas de qualidade e segurança. As correções foram feitas de forma a preservar as assinaturas, rotas, nomes de parâmetros, formatos de CSV e estrutura HTML declarados no `manifest.md`.

## 2. Achados

### F1 — SQL Injection em `listarChamados` via parâmetro `busca`

- **Arquivo/linha (original):** `code/lib.php`, linha 84
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A string `$busca` é concatenada diretamente na consulta SQL (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`). Um usuário autenticado pode enviar `busca=' OR '1'='1` e listar todos os chamados, ou usar payloads de exfiltração/escrita.
- **Impacto:** Vazamento total da base de chamados, possível elevação de privilégio e RCE dependendo da configuração do MySQL (`INTO OUTFILE`, `LOAD DATA`).
- **O que foi feito:** Reescrevi a consulta com prepared statement (`LIKE ?`) e `bind_param('s', $termo)`, preservando o comportamento de busca por substring.

### F2 — SQL Injection em `verChamado` via parâmetro `id`

- **Arquivo/linha (original):** `code/lib.php`, linha 100
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** `$id` é concatenado em `SELECT * FROM chamados WHERE id = ' . $id`. Um usuário pode passar `?ver=1 OR 1=1` ou `?ver=1 UNION SELECT ...`.
- **Impacto:** Leitura arbitrária de qualquer chamado e, potencialmente, da tabela `usuarios` (senhas MD5 incluídas).
- **O que foi feito:** Uso de prepared statement com `bind_param('i', $id)`.

### F3 — SQL Injection em `tecnicoNome`

- **Arquivo/linha (original):** `code/lib.php`, linha 69
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `$tecnicoId` é concatenado diretamente na query. O valor vem do banco, mas a função é pública e pode ser chamada com qualquer inteiro — incluindo um manipulado por outro fluxo.
- **Impacto:** Ataque de segundo ordem; embora o chamada direta exija autenticação, a função é usada em loop em `listarChamados` e `exportarCsv`, ampliando a superfície de ataque.
- **O que foi feito:** Prepared statement com `bind_param('i', $tecnicoId)`.

### F4 — Quebra da regra de visibilidade cliente/técnico

- **Arquivo/linha (original):** `code/index.php`, linhas 44-96
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** Tanto a listagem quanto o detalhe chamam `listarChamados($db, $busca)` e `verChamado($db, $id)` sem verificar `usuario_id` ou `papel`. Um cliente autenticado consegue ver chamados de outros clientes apenas trocando `?ver=...` ou usando `?busca=...`. O export CSV também expunha tudo.
- **Impacto:** Violação de privacidade e confidencialidade dos chamados; cliente A lê/exports chamados do cliente B.
- **O que foi feito:**
  - Na listagem, após obter os chamados, filtro no PHP para clientes (`array_filter` por `usuario_id === $uid`).
  - No detalhe, após carregar o chamado, verifico se o papel é `cliente` e se `usuario_id` pertence a ele.
  - No CSV, criei `exportarCsvDoUsuario` em `lib.php`: técnicos exportam tudo; clientes exportam apenas seus chamados. O cabeçalho e formato de CSV continuam idênticos.

### F5 — Divisão por zero em `mediaResposta`

- **Arquivo/linha (original):** `code/lib.php`, linha 116
- **Categoria:** bug
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** Se não houver chamados com `minutos_resposta IS NOT NULL`, `$qtd` é zero e `$soma / $qtd` gera warning e retorna `INF`/`NaN`, quebrando o indicador "Tempo médio de 1ª resposta".
- **Impacto:** Painel exibe valor inválido ou gera warning visível/ logado; comportamento instável quando o banco está vazio ou sem respostas.
- **O que foi feito:** Retorno condicional: `$qtd > 0 ? $soma / $qtd : 0.0`.

### F6 — XSS refletido no parâmetro `busca`

- **Arquivo/linha (original):** `code/index.php`, linhas 79 e 82
- **Categoria:** seguranca
- **Severidade:** media
- **Confiança:** 100
- **Mecanismo:** O valor de `$_GET['busca']` é reimpresso diretamente no HTML do input (`value="$busca"`) e no parágrafo "Resultados para: $busca". Um link como `index.php?busca=<script>alert(1)</script>` executa JavaScript na sessão da vítima.
- **Impacto:** Roubo de sessão, redirecionamento, defacement.
- **O que foi feito:** Apliquei `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em ambos os pontos.

### F7 — `formatarStatus` retorna rótulo errado para status inválidos

- **Arquivo/linha (original):** `code/lib.php`, linhas 28-35
- **Categoria:** bug
- **Severidade:** media
- **Confiança:** 95
- **Mecanismo:** O `else` final retorna `'Resolvido'` para qualquer valor diferente de 1 e 2. Portanto, status `0`, `4`, `99` etc. são rotulados como "Resolvido", distorcendo relatórios gerenciais que fazem matching exato por texto.
- **Impacto:** Dados gerenciais incorretos; chamados com status corrompido ou futuro aparecem como resolvidos.
- **O que foi feito:** Troquei a cadeia `if/else` por `switch` e retorno `'Desconhecido'` para valores fora do contrato `{1,2,3}`. Os três valores contratuais continuam retornando exatamente os rótulos esperados.

### F8 — `rotuloPrioridade` classifica chamado crítico dentro do SLA como "Alto"

- **Arquivo/linha (original):** `code/lib.php`, linhas 40-59
- **Categoria:** bug
- **Severidade:** media
- **Confiança:** 90
- **Mecanismo:** Quando `prioridade == 4` e `minutos <= 30`, o código cai no ramo `else` do `if ($minutos > 30)` e retorna `'Alto - dentro do SLA'`, em vez de identificar o chamado como crítico.
- **Impacto:** Operadores subestimam chamados de prioridade 4 que ainda estão dentro do SLA.
- **O que foi feito:** Adicionei ramo específico para `prioridade == 4` dentro do SLA, retornando `'CRITICO - dentro do SLA'`. O comportamento para as demais prioridades permaneceu inalterado.

### F9 — `exportarCsv` grava arquivo temporário em disco antes de devolver ao navegador

- **Arquivo/linha (original):** `code/lib.php`, linhas 125-150
- **Categoria:** qualidade
- **Severidade:** media
- **Confiança:** 95
- **Mecanismo:** O CSV é escrito em `EXPORT_DIR . '/chamados.csv'`, depois `readfile` devolve o conteúdo. Isso depende de diretório configurado e permissões, deixa arquivo residual no servidor e pode vazar dados entre requisições se houver concorrência.
- **Impacto:** Falha silenciosa se o diretório não existir/ não for gravável; vazamento de dados entre sessões concorrentes; lixo em disco.
- **O que foi feito:** Stream direto para `php://output`, sem arquivo temporário. Extraí a formatação de linha para `_escreverLinhaCsv` para reaproveitar na nova função `exportarCsvDoUsuario`.

### F10 — Senhas armazenadas e validadas com MD5

- **Arquivo/linha (original):** `code/lib.php`, linha 15
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `autenticar` calcula `md5($senha)` e compara com a coluna `senha CHAR(32)` do banco. MD5 é rápido, reversível por tabela rainbow e não possui salt.
- **Impacto:** Em caso de vazamento do banco, as senhas são facilmente quebradas; além disso, a função de hash rápido facilita ataques de força bruta online.
- **O que foi feito:** Não alterei. A mudança para `password_hash`/`password_verify` exige migração da coluna `senha` (tamanho insuficiente para bcrypt) e rehash de todos os usuários. Isso quebraria os scripts de teste e a integração com a rotina noturna até que a base fosse migrada. Registro como débito técnico a ser tratado em janela de manutenção.

### F11 — Segredos hardcoded em `config.php`

- **Arquivo/linha (original):** `code/config.php`, linhas 12 e 15
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `DB_PASS` possui fallback com senha de produção em texto plano (`N3tX@2013!prod`) e `SMTP_API_KEY` está totalmente hardcoded (`netx-smtp-9f83e2c1a7b64d05`).
- **Impacto:** Vazamento de credenciais no repositório; qualquer pessoa com acesso ao código conecta no banco de produção ou usa a chave SMTP.
- **O que foi feito:** Não alterei. Remover os fallbacks pode quebrar ambientes que ainda não configuraram as variáveis de ambiente (`DB_HOST`, `DB_USER`, `DB_PASS`, etc.). A correção correta é operacional: remover os valores do repositório, injetar via variáveis de ambiente/secrets manager e rotacionar as credenciais. Registro como débito técnico.

## 3. Decisões — o que deliberadamente não mudei e por quê

- **MD5 de senhas (F10):** Não mudei porque exige alteração de schema (`senha CHAR(32)` não comporta bcrypt) e rehash de todos os usuários. A assinatura `autenticar` pode ser preservada, mas os dados de teste do `seed.sql` usam MD5. Alterar isso fugiría do escopo de "evoluir, não reescrever".
- **Segredos em `config.php` (F11):** Não removi os fallbacks porque a infraestrutura de teste/implantação pode depender deles. A solução é operacional (env vars + rotação), não apenas editar o arquivo.
- **Não adicionei CSRF tokens, rate-limiting, headers de segurança, etc.:** Fora do escopo mínimo necessário para corrigir os problemas reais sem expandir a superfície de teste. O foco foi SQLi, quebra de regra de negócio, XSS e bugs concretos.
- **Não mudei a stack de mysqli:** Conforme `manifest.md`, as funções públicas recebem `mysqli`. Mantive prepared statements via `mysqli`.
- **Não renomeei nem movi arquivos:** Todos os caminhos originais foram preservados (`code/config.php`, `code/index.php`, `code/lib.php`, `code/schema.sql`, `code/seed.sql`).
- **Não alterei `schema.sql` nem `seed.sql`:** Necessário para manter compatibilidade com os dados de teste e scripts externos.

## 4. Validação realizada

- `php -l code/lib.php` — sem erros.
- `php -l code/index.php` — sem erros.
- As assinaturas das funções públicas do `manifest.md` foram preservadas (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`).
- Rotas e parâmetros GET (`busca`, `ver`, `export`) preservados.
- Formato do CSV e cabeçalho preservados.
- Estrutura HTML da listagem preservada (`id="tabela-chamados"`, colunas na ordem correta, links `index.php?ver=<id>`).
