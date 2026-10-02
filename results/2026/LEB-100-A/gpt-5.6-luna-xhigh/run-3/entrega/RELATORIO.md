# Relatório técnico

## Resumo

O sistema mantinha a superfície pública descrita em `manifest.md`, mas permitia
vazamento entre clientes, interpolava entrada do usuário em SQL e HTML, usava
segredos no código-fonte e armazenava/exportava dados de forma insegura. Também
havia uma falha determinística quando não existiam respostas para calcular a
média e custo desnecessário de consultas por chamado.

As correções preservam os nomes e assinaturas das funções, as rotas, os rótulos
de status, o `id` da tabela, a ordem das colunas e o cabeçalho do CSV. A base
continua aceitando hashes MD5 existentes durante a migração, enquanto novas
instalações usam `password_hash`; um login válido de uma conta MD5 promove o
hash automaticamente quando a coluna já foi ampliada para `VARCHAR(255)`.

## Achados

### F1 — Segredos de produção embutidos no código

**Local:** `code/config.php`, linhas originais 11–15. **Categoria:** segurança.
**Severidade:** crítica. **Confiança:** 100/100.

`DB_PASS` recebia uma senha de produção quando `DB_PASS` não estava definido, e
`SMTP_API_KEY` era sempre uma chave literal. Assim, uma cópia do pacote, um
vazamento de código ou um erro de log poderia fornecer credenciais reutilizáveis
para o banco e para o serviço de e-mail. A correção remove os valores secretos
do fonte e deixa ambos dependentes do ambiente; sem configuração, o banco falha
de forma explícita em vez de usar uma credencial conhecida.

### F2 — Senhas armazenadas com MD5 rápido e sem salt

**Local:** `code/lib.php`, linhas originais 15–20; a definição correspondente
estava em `code/schema.sql`, linha original 7, e no seed, linhas originais 5–8.
**Categoria:** segurança. **Severidade:** alta. **Confiança:** 100/100.

`autenticar` calculava `md5($senha)` e comparava o resultado diretamente com a
coluna `senha`. MD5 é rápido e não tem salt por usuário, portanto um vazamento da
tabela permite testar grandes dicionários offline. A coluna foi ampliada para
`VARCHAR(255)`, o seed passou a usar hashes bcrypt, e a autenticação verifica
`password_hash`. Hashes MD5 antigos ainda são aceitos para não bloquear a base
existente; depois de um login correto, o código tenta promovê-los, mas somente
quando consulta o schema e confirma que a coluna comporta um hash longo. Para
instalações já existentes, a migração indicada no `schema.sql` precisa ser
aplicada antes dessa promoção.

### F3 — Falha de autorização entre clientes

**Local:** `code/lib.php`, linhas originais 80–101 e 132–150, acionadas pelas
rotas de listagem, detalhe e exportação em `code/index.php`, linhas originais
44–53. **Categoria:** segurança. **Severidade:** crítica. **Confiança:** 100/100.

As consultas originais não usavam `$_SESSION['uid']` nem o papel do usuário.
Assim, Ana recebia chamados de Bruno na listagem, podia abrir diretamente
`index.php?ver=104` e podia exportar a base inteira. As consultas agora aplicam
`usuario_id = ?` quando a sessão é de cliente; técnicos continuam vendo todos os
chamados. O mesmo contexto é aplicado à média exibida e ao CSV, evitando que o
indicador ou a exportação revelem dados de outro cliente.

### F4 — Injeção SQL na busca por título

**Local:** `code/lib.php`, linhas originais 80–85. **Categoria:** segurança.
**Severidade:** alta. **Confiança:** 100/100.

O valor de `busca` era concatenado dentro de `LIKE '%...%'`. Uma entrada com
aspas podia alterar a expressão SQL e, dependendo das permissões do usuário do
banco, ler ou modificar dados. A consulta passou a usar `LIKE ?` com o termo
embrulhado em `%`, mantendo a semântica de busca e eliminando a interpretação da
entrada como SQL.

### F5 — XSS refletido no parâmetro `busca`

**Local:** `code/index.php`, linhas originais 79–83. **Categoria:** segurança.
**Severidade:** média. **Confiança:** 100/100.

O mesmo valor de busca era impresso sem escape no atributo `value` e no texto
"Resultados para". Um link com HTML ou JavaScript no parâmetro poderia executar
no navegador de quem o abrisse. A busca é validada como string e escapada com
`ENT_QUOTES`, `ENT_SUBSTITUTE` e UTF-8; os campos de título, descrição e técnico
também passaram a usar o mesmo escape explícito.

### F6 — Divisão por zero na média sem respostas

**Local:** `code/lib.php`, linhas originais 109–116. **Categoria:** bug.
**Severidade:** média. **Confiança:** 100/100.

Quando todos os chamados tinham `minutos_resposta` nulo, o laço deixava `$qtd`
em zero e a expressão `$soma / $qtd` gerava erro fatal em PHP 8. A média agora
usa `AVG` e retorna `0.0` quando o banco não tem valor, preservando o retorno
`float` da assinatura pública.

### F7 — CSV temporário previsível e sujeito a corrida

**Local:** `code/lib.php`, linhas originais 125–150. **Categoria:** segurança.
**Severidade:** média. **Confiança:** 90/100.

Toda exportação escrevia em `EXPORT_DIR . '/chamados.csv'`, um nome único e
previsível. Duas requisições simultâneas podiam sobrescrever o mesmo arquivo e
uma requisição podia ler o conteúdo produzido pela outra; se o diretório fosse
servido pelo web server, o arquivo também seria um alvo direto para leitura.
A exportação agora envia o CSV por `php://output`, sem arquivo compartilhado e
sem depender de permissões ou limpeza do diretório.

### F8 — Padrão N+1 ao buscar técnicos

**Local:** `code/lib.php`, linhas originais 88–89 e 136–137. **Categoria:**
performance. **Severidade:** média. **Confiança:** 100/100.

A listagem e o export faziam uma consulta adicional a `usuarios` para cada
chamado. Com N chamados, a operação exigia uma consulta principal mais N
consultas, aumentando latência e carga linearmente. Ambas as rotas agora usam
um `LEFT JOIN` único e continuam entregando a chave pública `tecnico_nome`.

### F9 — Fórmula injetável no CSV

**Local:** `code/lib.php`, linhas originais 138–144. **Categoria:** segurança.
**Severidade:** média. **Confiança:** 95/100.

Um título controlado por cliente que começasse por `=`, `+`, `-` ou `@` era
exportado sem proteção. Planilhas que interpretam fórmulas poderiam executar uma
expressão ao abrir o arquivo. O export prefixa esses valores com apóstrofo,
mantendo o CSV válido e o cabeçalho exigido.

### F10 — Sessão sem regeneração no login e sem atributos de cookie

**Local:** `code/index.php`, linhas originais 15–27. **Categoria:** segurança.
**Severidade:** alta. **Confiança:** 95/100.

O login reutilizava o identificador de sessão existente, permitindo fixação de
sessão se um atacante conseguisse fazer a vítima autenticar com um ID conhecido.
Os padrões do PHP também não garantiam `HttpOnly`, `Secure` ou `SameSite`. O
código configura esses atributos, usa `SameSite=Lax` e regenera o ID com descarte
da sessão anterior após autenticação bem-sucedida.

### F11 — Resultados de banco e arquivo tratados como sempre válidos

**Local:** `code/lib.php`, linhas originais 16–20, 69–71, 85, 100–101 e 132–135.
**Categoria:** qualidade. **Severidade:** média. **Confiança:** 100/100.

Falhas de `prepare`, `execute` ou `query` eram seguidas por chamadas como
`fetch_assoc()` em `false`, produzindo erro fatal em vez de uma resposta vazia ou
segura. Na exportação, uma falha de consulta também deixava o fluxo de arquivo
incompleto. As operações agora verificam preparação e execução, fecham statements
em todos os caminhos de erro e retornam `null`, `[]`, `0.0` ou encerram o
stream de acordo com a assinatura existente.

## Decisões

- Mantive todas as assinaturas de `lib.php`, os nomes de parâmetros de `index.php`,
  o `id="tabela-chamados"`, a ordem das colunas, os rótulos de status e o
  cabeçalho do CSV. Esses itens fazem parte do contrato externo.
- Mantive a aceitação temporária de MD5 e não forcei uma atualização que pudesse
  truncar hashes em uma instalação ainda presa a `CHAR(32)`. A promoção automática
  só ocorre após a confirmação do tamanho da coluna; a migração de schema está
  documentada no próprio `schema.sql`.
- Mantive `formatarStatus` devolvendo `Resolvido` para valores fora de 1–3. O
  schema e o manifesto só definem esses três valores, e trocar o fallback poderia
  quebrar o relatório gerencial que depende do comportamento legado.
- Mantive a lógica e os textos de `rotuloPrioridade`; não havia evidência de
  defeito no conjunto de regras descrito, e alterar os rótulos seria uma mudança
  de negócio.
- Mantive a constante `EXPORT_DIR` para não quebrar consumidores ou configuração
  externa que ainda a referenciem. O caminho deixou de ser usado pela exportação
  HTTP, que agora é transmitida diretamente.
- Não troquei a busca por `LIKE '%termo%'` por full-text. O manifesto define
  filtragem por título, e um índice full-text mudaria resultados para acentos,
  palavras e substrings; a correção do N+1 removeu o custo mais claro sem alterar
  essa semântica.
- A visibilidade foi aplicada dentro das funções existentes usando a sessão web,
  sem acrescentar um parâmetro incompatível. Chamadas internas fora de uma sessão
  continuam podendo consultar a base inteira, como exigido pelos consumidores
  internos já existentes.

## Validação

- `php -l code/config.php`
- `php -l code/index.php`
- `php -l code/lib.php`
- Verificação de `password_verify` para `senha123` e `tecmaster` com os hashes do
  novo seed.

Não foi possível executar um teste de integração contra o MariaDB local porque o
servidor está ativo, mas não há credenciais administrativas ou da aplicação
fornecidas no workspace.
