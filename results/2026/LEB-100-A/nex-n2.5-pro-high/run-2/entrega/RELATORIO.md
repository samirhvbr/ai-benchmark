# Relatório técnico — LEB-100-A

## Resumo

Foi analisado o pacote original em `/srv/LEB-100-A/code/` e o sistema foi evoluído em `/srv/run/code/`. O `manifest.md` e sua superfície pública foram preservados.

Foram identificados 10 achados. As correções entregues cobrem credenciais fixas, autenticação MD5, falhas de execução, injeção SQL, XSS refletido, fixação de sessão, autorização entre clientes, injeção de fórmula em CSV, exportação por arquivo compartilhado e inconsistências do schema. As consultas passaram a usar prepared statements, a sessão passou a ser validada contra o banco, a listagem/detalhe/métrica/exportação passaram a observar o papel do usuário e o CSV passou a ser emitido diretamente na resposta.

O código mantém compatibilidade para consumidores legados: as assinaturas públicas, rotas, rótulos, estrutura HTML e formato do CSV permanecem os mesmos. Em chamadas diretas sem sessão, as funções públicas ainda retornam o conjunto completo para não quebrar scripts externos; essa exceção é descrita nas decisões abaixo.

## Referência e critérios

As linhas citadas neste relatório e em `achados.json` são as linhas da numeração original recebida em `/srv/LEB-100-A/code/`, não as linhas após as alterações. A severidade considera o impacto no painel e nos dados do ISP. A confiança é a estimativa de que o mecanismo descrito é real no código recebido.

## Achados

### F1 — Credenciais e chave de e-mail fixas no código

- **Localização:** `code/config.php`, linhas originais 11–18.
- **Mecanismo:** a senha do banco e a chave SMTP estavam literais no fonte, com fallback embutido para a senha de produção. O diretório de exportação também era um caminho fixo conhecido.
- **Impacto:** qualquer pessoa com acesso ao repositório, imagem, backup ou artefato de deploy poderia obter credenciais do banco e da central de e-mail, além de conhecer o local dos exports.
- **Severidade:** alta. **Confiança:** 100/100.
- **Correção:** `DB_PASS`, `SMTP_API_KEY` e `EXPORT_DIR` passaram a vir de variáveis de ambiente. O código não contém mais os segredos literais; valores não secretos mantêm defaults operacionais e segredos ausentes ficam vazios para falhar de forma explícita no deploy.

### F2 — Autenticação MD5 e coluna incompatível com hash moderno

- **Localização:** `code/lib.php`, linhas originais 13–20; referências complementares em `code/schema.sql` linha 7 e `code/seed.sql` linhas 2–8.
- **Mecanismo:** `autenticar()` calculava `md5($senha)` e comparava esse valor com a coluna. MD5 é rápido, não possui salt e é inadequado para senhas; além disso, `senha CHAR(32)` não comporta um hash `password_hash()`/bcrypt. O seed também populava apenas hashes MD5.
- **Impacto:** um vazamento da tabela permite ataques de dicionário e rainbow tables com custo muito menor, possibilitando takeover de contas e acesso aos chamados.
- **Severidade:** alta. **Confiança:** 100/100.
- **Correção:** a autenticação passou a usar `password_verify()`, com fallback somente para hashes MD5 legítimos já existentes. Quando a coluna comporta 60 ou mais caracteres, um login bem-sucedido com hash antigo gera rehash bcrypt. O schema moderno usa `VARCHAR(255)` e o seed contém hashes bcrypt.

### F3 — Falhas de mysqli, sentinela e invariantes numéricas não tratadas

- **Localização:** `code/lib.php`, linhas originais 15–20, 69–71, 85–91, 98–116 e 125–150; `code/index.php`, linhas originais 9–13.
- **Mecanismo:** o código assumia que `prepare()`, `bind_param()`, `execute()`, `get_result()`, `query()`, `fetch_assoc()`, `fopen()` e `readfile()` sempre teriam sucesso. Um resultado falso era tratado como objeto e podia gerar warning ou erro fatal. `mediaResposta()` dividia por zero quando não havia respostas; falhas de conexão e de charset também não eram tratadas de forma uniforme.
- **Impacto:** falhas transitórias do banco, um ID inválido para o helper de técnico ou uma falha de disco podiam derrubar a página, produzir saída parcial ou retornar dados inconsistentes.
- **Severidade:** media. **Confiança:** 99/100.
- **Correção:** foi adicionado binding auxiliado por `bindParametros()`, verificações de resultado, fechamento de statements/results e tratamento de `mysqli_sql_exception`. A média usa `COALESCE(AVG(...), 0)`, conexões e charset têm tratamento explícito, e os caminhos de exportação fecham recursos mesmo em erro.

### F4 — Injeção SQL no filtro de busca

- **Localização:** `code/lib.php`, linhas originais 81–85; o valor é recebido em `code/index.php`, linhas originais 72–73.
- **Mecanismo:** o parâmetro GET `busca` era concatenado diretamente na cláusula `LIKE`. Um termo com aspas pode alterar a expressão SQL e escapar do filtro pretendido.
- **Impacto:** um usuário autenticado poderia ampliar ou modificar a consulta, expor registros fora do filtro ou provocar erro/indisponibilidade conforme os privilégios da conta do banco.
- **Severidade:** alta. **Confiança:** 100/100.
- **Correção:** a busca agora usa um statement preparado com `LIKE CONCAT('%', ?, '%')`. O valor continua sendo uma string, preservando o comportamento textual da rota, mas deixa de compor a SQL.

### F5 — XSS refletido no campo de busca

- **Localização:** `code/index.php`, linhas originais 72–82.
- **Mecanismo:** o valor bruto de `$_GET['busca']` era impresso no atributo `value` do formulário e no texto de resultados. HTML e JavaScript fornecidos pelo requisitante eram interpretados no contexto do painel.
- **Impacto:** um link malicioso poderia executar script com os privilégios do usuário, roubar a sessão ou induzir ações não desejadas.
- **Severidade:** alta. **Confiança:** 100/100.
- **Correção:** `getTexto()` normaliza o parâmetro para string e `e()` aplica `htmlspecialchars()` com `ENT_QUOTES`, substituição de caracteres inválidos e HTML5. A mesma proteção foi aplicada aos demais dados exibidos no HTML.

### F6 — Fixação de sessão e cookies sem atributos de proteção

- **Localização:** `code/index.php`, linhas originais 15–27.
- **Mecanismo:** a sessão era iniciada antes da autenticação e o identificador não era renovado após o login. Os parâmetros do cookie usavam os padrões do runtime, sem política explícita de `HttpOnly`, `SameSite` ou `Secure`.
- **Impacto:** uma sessão previamente fixada por um atacante poderia ser associada à conta após o login; cookies também ficavam mais expostos a leitura por script ou envio em contextos inadequados.
- **Severidade:** media. **Confiança:** 99/100.
- **Correção:** o modo estrito de sessão foi habilitado, os atributos do cookie foram declarados explicitamente e `session_regenerate_id(true)` passou a ser chamado após autenticação bem-sucedida. O papel armazenado na sessão é conferido com o banco antes de autorizar consultas.

### F7 — Ausência de autorização por dono ou papel (IDOR)

- **Localização:** `code/lib.php`, linhas originais 78–101 e 123–150; `code/index.php`, linhas originais 52–54.
- **Mecanismo:** listagem, detalhe e exportação consultavam `chamados` sem usar `usuario_id`. O `uid` e o `papel` armazenados na sessão não eram usados para restringir os dados. Um cliente autenticado podia alterar o ID da URL ou usar a rota de export para acessar chamados de outro cliente.
- **Impacto:** vazamento cruzado de chamados, descrições, status, prioridades e dados de outros clientes; a regra de negócio declarada no manifesto também não era cumprida.
- **Severidade:** alta. **Confiança:** 100/100.
- **Correção:** `contextoSessao()` valida o usuário e o papel no banco. Consultas de cliente incluem `c.usuario_id = ?`; técnicos continuam vendo todos os chamados. A restrição foi aplicada à listagem, ao detalhe, à média e ao CSV.

### F8 — Injeção de fórmula em CSV

- **Localização:** `code/lib.php`, linhas originais 123–150.
- **Mecanismo:** título e nome do técnico, que podem conter dados fornecidos por usuários, eram escritos diretamente no CSV. Valores iniciados por `=`, `+`, `@` ou `-` podem ser interpretados como fórmula quando abertos em planilhas.
- **Impacto:** uma planilha aberta por um operador poderia executar uma fórmula, exfiltrar dados locais ou provocar ações inesperadas no computador do usuário.
- **Severidade:** media. **Confiança:** 98/100.
- **Correção:** `valorCsvSeguro()` remove espaços e caracteres de controle iniciais usados para ocultar o prefixo e antepõe uma tabulação aos valores com prefixo perigoso. O cabeçalho, a ordem das colunas e a ordenação por ID foram mantidos.

### F9 — Exportação por arquivo compartilhado e consultas N+1

- **Localização:** `code/lib.php`, linhas originais 87–91 e 123–150; `code/config.php`, linhas originais 17–18.
- **Mecanismo:** cada exportação gravava sempre `EXPORT_DIR/chamados.csv` e depois fazia `readfile()`. Requisições concorrentes podiam sobrescrever o arquivo, consumir um arquivo parcialmente escrito ou vazar uma exportação anterior. O mesmo caminho executava uma consulta adicional por chamado para obter o técnico (N+1). Falhas de abertura/leitura eram silenciosas.
- **Impacto:** conteúdo incorreto ou incompleto, corrida de escrita, dependência desnecessária de disco e degradação proporcional ao número de chamados.
- **Severidade:** media. **Confiança:** 99/100.
- **Correção:** a exportação agora consulta os dados com JOIN em uma única consulta e escreve diretamente em `php://output`, sem arquivo compartilhado. Os headers são enviados antes dos dados, os recursos são liberados em todos os caminhos e a autorização é aplicada antes da consulta.

### F10 — Schema sem charset explícito e largura incompatível

- **Localização:** `code/schema.sql`, linhas originais 4–10 e 12–27.
- **Mecanismo:** as tabelas não declaravam charset/collation, deixando a codificação dependente de configuração do servidor, e a coluna de senha tinha apenas 32 caracteres. Isso é incompatível com hashes modernos e pode causar truncamento ou comparação inconsistente de texto.
- **Impacto:** falha ao migrar para bcrypt, corrupção ou comparação incorreta de acentos e comportamento diferente entre ambientes.
- **Severidade:** baixa. **Confiança:** 100/100.
- **Correção:** o schema passou a declarar `utf8mb4`/`utf8mb4_unicode_ci` nas duas tabelas e `senha VARCHAR(255)`. O seed foi atualizado para o mesmo formato.

## Decisões

1. **Não apliquei uma migration DDL automática em produção.** A alteração de `CHAR(32)` para `VARCHAR(255)` e a conversão dos hashes existentes exigem backup, janela e validação operacional. O código entregue suporta a coexistência durante a migração, mas não executa DDL ou rehash em massa implicitamente.
2. **Mantive o fallback MD5.** Removê-lo imediatamente tornaria as contas do banco legado inacessíveis. Ele só é aceito para um hash hexadecimal de 32 caracteres e é substituído por bcrypt no próximo login quando o schema permite.
3. **Mantive o comportamento público sem sessão.** O manifesto informa que scripts ISP chamam as funções de `lib.php` diretamente. Exigir sessão nessas chamadas quebraria consumidores legados; por isso, wrappers públicos sem sessão preservam o conjunto completo, enquanto o painel web autenticado aplica a regra de dono/papel.
4. **Não alterei assinaturas, rotas, parâmetros, rótulos, HTML contratual ou formato do CSV.** `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` continuam com os nomes e retornos declarados. O CSV continua com o cabeçalho exato, colunas na mesma ordem e ordenação por `id`.
5. **Mantive `EXPORT_DIR` como constante configurável.** A exportação web já não depende do arquivo, mas mantém a constante para compatibilidade com configurações e consumidores que ainda a leem.
6. **Não adicionei framework, ORM ou dependência externa.** A correção permanece na stack PHP/mysqli já existente, conforme a restrição da tarefa.
7. **Não forcei HTTPS nem implementei CSRF neste pacote.** O ambiente recebido não define um proxy ou uma política de terminação TLS; forçar `Secure` ou alterar o fluxo POST poderia quebrar instalações HTTP legadas. A sessão usa `Secure` quando o runtime indica HTTPS e `SameSite=Lax`; a configuração do proxy e a proteção CSRF devem ser tratadas no deployment.
8. **A mitigação de CSV é deliberadamente conservadora.** O prefixo com tabulação reduz a execução de fórmulas sem mudar o cabeçalho ou a estrutura do arquivo; clientes de planilha podem tratar caracteres de forma diferente, portanto a validação de origem dos dados continua necessária.

## Verificação realizada

- `php -l` passou em todos os arquivos PHP alterados.
- Suíte web passou para login, visibilidade de clientes (`101`, `102`, `105`), negação de `103`/`104`, detalhe cruzado, busca, XSS, tentativa de SQL injection e CSV.
- Testes diretos passaram para comportamento legado sem sessão, visibilidade por papel, sessão inválida, MD5/rehash, média sem respostas e proteção de fórmula CSV.
- O schema foi verificado com `senha VARCHAR(255)`, `utf8mb4`, e os quatro usuários do seed autenticaram com bcrypt.
- `phpunit` não estava disponível; foram usados testes manuais/diretos. Não há repositório Git neste ambiente para diff automático.
