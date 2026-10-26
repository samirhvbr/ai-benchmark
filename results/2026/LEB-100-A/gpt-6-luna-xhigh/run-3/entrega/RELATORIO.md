# Relatório técnico

## Resumo

O painel tinha falhas de autorização entre clientes, SQL injection na busca, XSS refletido, segredos no código e autenticação baseada somente em MD5. Também podia falhar sem respostas registradas, fazia uma consulta adicional por chamado e exportava células que uma planilha poderia interpretar como fórmulas. Corrigi esses caminhos preservando as assinaturas públicas, os rótulos de status, as rotas e o formato geral do CSV.

As referências de linha abaixo apontam para a numeração dos arquivos **originais**, conforme solicitado. O schema entregue agora aceita hashes de até 255 caracteres. Para bancos já implantados, aplicar antes da atualização de senhas:

```sql
ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
```

## Achados

### F1 — Clientes conseguiam acessar chamados de outras contas

- **Onde:** `code/index.php`, linhas originais 44–74 (exportação, detalhe e preparação da listagem).
- **Mecanismo:** as rotas chamavam `exportarCsv`, `verChamado` e `listarChamados` sem restringir consultas a `usuario_id`. Qualquer cliente autenticado podia listar, abrir pelo parâmetro `ver` e exportar chamados de outros clientes.
- **Impacto / severidade:** exposição de títulos e descrições de suporte e de dados operacionais entre clientes; **alta**.
- **Confiança:** **100/100**.
- **Correção:** as rotas usam consultas de listagem, detalhe e CSV que limitam clientes ao próprio `uid`; técnicos continuam vendo todos os chamados. Chamados alheios e inexistentes têm a mesma resposta de detalhe.

### F2 — SQL injection na busca por título

- **Onde:** `code/lib.php`, linhas originais 80–85.
- **Mecanismo:** o conteúdo de `busca` era interpolado dentro do SQL `LIKE '%...%'`. Uma entrada com aspas podia alterar a consulta executada pelo usuário do banco.
- **Impacto / severidade:** leitura ou alteração do resultado da consulta e possível exposição de dados além da busca prevista; **alta**.
- **Confiança:** **99/100**.
- **Correção:** a consulta usa prepared statement e vincula o padrão de busca como parâmetro. A pesquisa continua sendo por trecho do título.

### F3 — Credenciais de produção embutidas na configuração

- **Onde:** `code/config.php`, linhas originais 11–15.
- **Mecanismo:** a senha de fallback do banco e uma chave de API SMTP estavam gravadas no arquivo do aplicativo, que é versionado e distribuído junto ao código.
- **Impacto / severidade:** quem obtivesse o repositório poderia tentar acessar serviços com essas credenciais; **alta**.
- **Confiança:** **100/100**.
- **Correção:** os valores agora vêm de `DB_PASS` e `SMTP_API_KEY` no ambiente, sem segredo de fallback. As credenciais que já estiveram no repositório devem ser rotacionadas no banco e no provedor SMTP.

### F4 — Senhas armazenadas com MD5

- **Onde:** `code/lib.php`, linhas originais 13–20; `code/schema.sql`, linha original 7.
- **Mecanismo:** autenticação comparava MD5 da senha com o valor persistido. MD5 é rápido e permite testar muitas senhas por segundo caso a tabela seja obtida. A coluna `CHAR(32)` também não comportava hashes de senha modernos.
- **Impacto / severidade:** recuperação mais fácil de senhas reutilizadas após vazamento do banco; **alta**.
- **Confiança:** **100/100**.
- **Correção:** a autenticação verifica hashes de `password_hash`; hashes MD5 legados continuam aceitos e são atualizados após login válido se a coluna comportar o novo hash. O schema foi ampliado para `VARCHAR(255)`. Bases existentes precisam da migração SQL acima; enquanto não migrarem, o código evita gravar um hash truncado.

### F5 — XSS refletido no termo de busca

- **Onde:** `code/index.php`, linhas originais 79–82.
- **Mecanismo:** o parâmetro `busca` era inserido sem escape tanto no atributo `value` do campo quanto no texto “Resultados para”. Um link preparado podia executar HTML/JavaScript no navegador de um usuário autenticado.
- **Impacto / severidade:** execução de script na origem do painel e acesso às ações e dados disponíveis à vítima; **média**.
- **Confiança:** **99/100**.
- **Correção:** o termo é escapado com `htmlspecialchars` para UTF-8 e aspas em ambos os contextos; dados dinâmicos das demais saídas HTML também usam escape explícito.

### F6 — Sessão não era renovada após autenticação

- **Onde:** `code/index.php`, linhas originais 20–27.
- **Mecanismo:** depois de um login bem-sucedido, o identificador da sessão existente era mantido. Se um atacante conseguisse fixar esse identificador antes do login, poderia reutilizá-lo após a vítima autenticar.
- **Impacto / severidade:** sequestro da sessão autenticada em cenário de fixação de sessão; **média**.
- **Confiança:** **95/100**.
- **Correção:** o ID é regenerado e o anterior invalidado no login. O cookie também usa `HttpOnly`, `SameSite=Lax` e `Secure` quando a conexão é HTTPS.

### F7 — Fórmulas CSV podiam ser executadas em planilhas

- **Onde:** `code/lib.php`, linhas originais 138–140.
- **Mecanismo:** títulos e nomes eram gravados como células CSV sem neutralização. Ao abrir o arquivo em um aplicativo de planilha, valores iniciados por `=`, `+`, `-` ou `@` podiam ser interpretados como fórmulas.
- **Impacto / severidade:** conteúdo controlado por dados poderia executar fórmulas ou links na estação de quem abrisse o relatório; **média**.
- **Confiança:** **90/100**.
- **Correção:** apenas valores textuais que começam com caracteres de fórmula (inclusive após espaços/controles) recebem um apóstrofo inicial. Cabeçalho, colunas, ordenação e rótulos de status permanecem iguais.

### F8 — Média causava divisão por zero sem respostas registradas

- **Onde:** `code/lib.php`, linhas originais 109–117.
- **Mecanismo:** quando a consulta não retornava nenhum `minutos_resposta`, `$qtd` permanecia zero e a expressão `$soma / $qtd` falhava em PHP 8.
- **Impacto / severidade:** erro fatal na página inicial para uma base nova ou sem chamados respondidos; **média**.
- **Confiança:** **100/100**.
- **Correção:** a média é calculada no banco e retorna `0.0` quando não há valores.

### F9 — Listagem e exportação faziam uma consulta por técnico

- **Onde:** `code/lib.php`, linhas originais 88–90 (e o loop equivalente da exportação).
- **Mecanismo:** cada chamado carregado disparava outra consulta a `tecnicoNome`; uma listagem de N chamados executava N consultas adicionais.
- **Impacto / severidade:** aumento linear de consultas e latência conforme a base cresce; **baixa**.
- **Confiança:** **100/100**.
- **Correção:** listagem e exportação agora obtêm o nome do técnico com `LEFT JOIN`, mantendo a chave `tecnico_nome` no retorno público.

## Decisões

- Mantive nomes e assinaturas das funções públicas, rótulos de status, parâmetros das rotas, colunas da tabela HTML e formato/ordenação do CSV. A regra de visibilidade fica nas rotas web porque as funções públicas de listagem, detalhe e exportação não recebem identidade do usuário e são usadas por consumidores internos.
- Mantive `formatarStatus`, `rotuloPrioridade` e as regras de negócio de prioridade sem alterações: o manifesto define seus valores e consumidores externos dependem deles.
- Mantive os hashes MD5 dos usuários no `seed.sql` para que os logins de teste continuem funcionando; o primeiro login válido os atualiza quando o schema comporta o hash novo.
- Não alterei a API nem os valores normais do CSV. Para preservar a segurança ao abrir planilhas, títulos ou nomes que tenham prefixo de fórmula recebem apóstrofo no arquivo exportado.
- Não rotacionei as credenciais externas: isso requer acesso ao banco e ao provedor SMTP, que não fazem parte dos arquivos entregues. O código deixou de conter os valores e a rotação dos valores já expostos é necessária.
