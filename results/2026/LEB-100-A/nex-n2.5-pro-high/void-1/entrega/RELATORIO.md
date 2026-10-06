# Relatório técnico — LEB-100-A

## Resumo

Foi feita uma revisão do código recebido em `code/`, comparando-o com a baseline e com o contrato de `manifest.md`. O sistema tinha riscos de segurança relevantes em autenticação, autorização, sessão, saída HTML, CSV e configuração, além de bugs de robustez e consultas ineficientes.

A evolução entregue preserva as funções públicas, rotas, rótulos, estrutura declarada da listagem e formato lógico do CSV. As consultas passaram a ser preparadas, o acesso de cliente passou a ser filtrado pelo dono do chamado, a autenticação passou a usar bcrypt com fallback controlado para MD5 durante a migração, e a exportação passou a ser feita diretamente na resposta HTTP.

Foram executados lint PHP, testes de contrato por Reflection, testes de integração em MariaDB isolado com schema atual e schema legado `CHAR(32)`, testes de sessão/visibilidade/CSV e um smoke test HTTP. O banco principal não estava disponível para teste direto; a validação usou o banco temporário descrito abaixo.

## F1 — Credenciais fixas no código

- **Local original:** `code/config.php:11-15`.
- **Mecanismo:** a senha de produção do banco e a chave SMTP estavam literais no repositório. Qualquer pessoa com acesso ao código, backup, imagem ou log de revisão poderia obter credenciais válidas sem precisar explorar uma falha na aplicação.
- **Impacto e severidade:** comprometimento direto do banco de suporte e possível uso da conta de e-mail transacional. Severidade **alta**.
- **Confiança:** 100.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` passaram a vir do ambiente, com fallback vazio quando a variável não existe. O valor `"0"` continua sendo preservado. A configuração de implantação precisa passar a fornecer essas variáveis.

## F2 — Senhas armazenadas com MD5 e coluna incompatível com bcrypt

- **Locais originais:** `code/lib.php:13-20`, `code/schema.sql:7` e `code/seed.sql:2,5-8`.
- **Mecanismo:** o login calculava `md5($senha)` e comparava o resultado no SQL. O MD5 não é adequado para senhas, não tem custo adaptativo e pode ser atacado offline se a tabela for copiada. A coluna `CHAR(32)` impedia armazenar um hash `password_hash` comum, que precisa de 60 ou mais caracteres.
- **Impacto e severidade:** exposição de credenciais reais e risco de reutilização das mesmas senhas em outros serviços. Severidade **crítica**.
- **Confiança:** 100.
- **Correção:** `autenticar()` agora usa `password_verify()`, aceita um hash MD5 apenas como caminho temporário de migração, compara-o com `hash_equals()` e tenta gravar bcrypt quando a coluna comporta o novo formato. O schema novo usa `VARCHAR(255)` e o seed usa hashes bcrypt.

## F3 — Cliente podia listar, detalhar, medir e exportar chamados de outros clientes

- **Locais originais:** `code/lib.php:78-101,107-116,123-150` e `code/index.php:44-54,72-74`.
- **Mecanismo:** as consultas de listagem, detalhe, média e exportação não recebiam o usuário da sessão e não filtravam por `chamados.usuario_id`. O ID enviado em `ver` era usado apenas para escolher um registro, sem verificar o dono.
- **Impacto e severidade:** um cliente autenticado podia enumerar dados, descrições, responsáveis e métricas de todos os clientes; a exportação ampliava o vazamento para um arquivo completo. Severidade **crítica**.
- **Confiança:** 100.
- **Correção:** a sessão validada gera um identificador de acesso: cliente recebe o próprio UID e técnico recebe acesso irrestrito. As consultas internas passaram a acrescentar `c.usuario_id = ?` quando o contexto é de cliente. As funções públicas continuam com as mesmas assinaturas.

## F4 — Injeção SQL na busca

- **Local original:** `code/lib.php:80-85`.
- **Mecanismo:** o termo de busca era concatenado diretamente em `LIKE '%...%'`. Um valor como `%\' OR 1=1--` podia alterar a condição da consulta e, dependendo da configuração do MySQL, ampliar ou combinar resultados.
- **Impacto e severidade:** leitura não autorizada de registros e possível erro ou manipulação adicional da consulta. Severidade **alta**.
- **Confiança:** 100.
- **Correção:** a busca agora usa um statement preparado e o termo é passado como parâmetro. O filtro continua sendo por título e mantém o comportamento de busca parcial.

## F5 — XSS refletido no campo de busca

- **Locais originais:** `code/index.php:79-82`.
- **Mecanismo:** o valor de `$_GET['busca']` era impresso sem escape no atributo `value` e no texto de resultados. Uma aspa ou marcação HTML fornecida pelo usuário podia sair do atributo e injetar conteúdo na página.
- **Impacto e severidade:** execução de script no contexto do painel, com possibilidade de roubo de sessão ou alteração da interface para o usuário. Severidade **alta**.
- **Confiança:** 100.
- **Correção:** foi criado um helper de escape com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8; o termo de busca e os campos dinâmicos da listagem usam esse escape.

## F6 — Sessão sem regeneração e cookies sem proteções

- **Locais originais:** `code/index.php:15,20-27` e ausência de política equivalente em `code/config.php`.
- **Mecanismo:** o ID de sessão era mantido antes e depois do login, permitindo fixation. Não havia configuração explícita de `HttpOnly`, `Secure`, `SameSite`, modo estrito ou restrição a cookies, aumentando o risco de captura e reuso da sessão.
- **Impacto e severidade:** roubo ou fixação de sessão autenticada. Severidade **alta**.
- **Confiança:** 100.
- **Correção:** o ID é regenerado após o login; o cookie passou a usar `HttpOnly`, `SameSite=Lax` e `Secure` quando o ambiente é HTTPS ou `SESSION_COOKIE_SECURE` está habilitado. Também foram ativados `use_strict_mode` e `use_only_cookies`, e o papel/UID da sessão passaram a ser validados antes de consultar dados.

## F7 — Injeção de fórmula pelo CSV

- **Local original:** `code/lib.php:136-144`.
- **Mecanismo:** títulos e nomes de técnicos eram enviados crus para `fputcsv()`. Uma célula que começa com `=`, `+`, `@` ou `-` pode ser interpretada como fórmula quando o arquivo é aberto em uma planilha.
- **Impacto e severidade:** execução de fórmula no computador de quem abre o CSV, com risco de leitura, modificação ou exfiltração de dados locais. Severidade **média**.
- **Confiança:** 95.
- **Correção:** `celulaCsvSegura()` prefixa com apóstrofo valores potencialmente ativos, inclusive quando há espaços ou caracteres de controle antes do sinal. O sentinel `-` continua exatamente `-`, conforme o contrato de dados.

## F8 — Exportação por arquivo fixo compartilhado

- **Locais originais:** `code/lib.php:125-150`.
- **Mecanismo:** toda requisição gravava `EXPORT_DIR/chamados.csv` e depois lia o mesmo arquivo. Exportações concorrentes podiam ler conteúdo parcial ou sobrescrever o arquivo de outra requisição; o arquivo persistente também ampliava a superfície de leitura e dependia de permissões do diretório.
- **Impacto e severidade:** vazamento entre exportações, dados incompletos e falhas dependentes do filesystem. Severidade **alta**.
- **Confiança:** 90.
- **Correção:** a exportação agora consulta os dados autorizados e escreve diretamente em `php://output`, com headers CSV antes do conteúdo. O formato lógico, a ordem por ID e o nome do download permanecem os mesmos. `EXPORT_DIR` foi mantido como constante para consumidores externos existentes.

## F9 — Consulta N+1 na listagem e na exportação

- **Locais originais:** `code/lib.php:88-90,136-143`.
- **Mecanismo:** para cada chamado, o código executava outra consulta em `tecnicoNome()`. Uma página ou CSV com N registros fazia N+1 consultas ao banco.
- **Impacto e severidade:** latência e carga desnecessárias no banco, especialmente em exportações grandes. Severidade **média**.
- **Confiança:** 100.
- **Correção:** listagem e exportação passaram a usar `LEFT JOIN` para obter `tecnico_nome` na consulta principal.

## F10 — Divisão por zero na média de resposta

- **Local original:** `code/lib.php:109-116`.
- **Mecanismo:** quando não havia nenhum `minutos_resposta`, `$qtd` permanecia zero e a expressão `$soma / $qtd` gerava divisão por zero, em vez de devolver um valor numérico estável.
- **Impacto e severidade:** warning, possível `NAN` e quebra do contrato `float` da função. Severidade **média**.
- **Confiança:** 100.
- **Correção:** a média passou a ser calculada com `AVG()` no banco; quando não há linhas ou ocorre falha, a função retorna `0.0`.

## F11 — Falhas mysqli tratadas como objetos válidos

- **Locais originais:** `code/index.php:13` e `code/lib.php:16-20,69-71,85,100-101,109,132-134`.
- **Mecanismo:** vários retornos de `prepare()`, `bind_param()`, `execute()`, `query()`, `get_result()` e `set_charset()` não eram verificados antes de chamar métodos ou percorrer resultados. Uma falha de conexão, SQL ou driver podia virar erro fatal ou uma resposta inconsistente.
- **Impacto e severidade:** indisponibilidade e possível exposição de detalhes internos em ambiente com exibição de erros. Severidade **média**.
- **Confiança:** 100.
- **Correção:** os caminhos críticos agora verificam os retornos e retornam `null`, `[]` ou `0.0` conforme o contrato da função. A configuração de charset também é verificada antes de iniciar a sessão.

## F12 — Instalações existentes continuam em MD5 até receberem migration

- **Local original:** `code/schema.sql:7`; mecanismo complementar em `code/lib.php:95-115` da versão entregue.
- **Mecanismo:** alterar `schema.sql` não modifica uma tabela já criada em produção. Para evitar `Data too long` em uma coluna `CHAR(32)`, o código detecta a largura e não grava bcrypt nela; assim, usuários legados continuam autenticando com MD5 até a coluna ser ampliada e o login ser executado novamente.
- **Impacto e severidade:** o risco de senhas MD5 persiste após o deploy se a migration não for aplicada. Severidade **alta**.
- **Confiança:** 100.
- **Correção proposta:** executar, em janela controlada e após backup, a alteração da coluna para `VARCHAR(255) NOT NULL`; depois, incentivar novo login ou fazer backfill seguro. Esta migration não foi automatizada no código para não alterar implicitamente um banco de produção durante uma requisição.

## F13 — HTTP não é recusado explicitamente

- **Local original:** `code/index.php:1-97`.
- **Mecanismo:** o cookie pode ser marcado como Secure em HTTPS, mas a aplicação não redireciona requisições HTTP nem verifica a camada de transporte. Se a porta 80 estiver exposta, credenciais e a requisição de login trafegam sem criptografia.
- **Impacto e severidade:** interceptação de credenciais ou sessão em uma exposição HTTP. Severidade **média**.
- **Confiança:** 90.
- **Correção:** não foi adicionado redirecionamento forçado. A terminação TLS e o comportamento de proxies reversos são específicos da infraestrutura; impor HTTPS no entrypoint poderia quebrar health checks e consumidores internos. A recomendação é bloquear/redirecionar HTTP no edge e confiar apenas em proxy que normalize `HTTPS`/`X-Forwarded-Proto` de forma segura.

## F14 — Status fora do domínio é apresentado como Resolvido

- **Local original:** `code/lib.php:26-34`.
- **Mecanismo:** qualquer valor diferente de `1` ou `2` cai no `else` e vira `Resolvido`. O schema usa `TINYINT` sem uma restrição que impeça valores inválidos inseridos por importação ou acesso direto ao banco.
- **Impacto e severidade:** relatórios e tela podem classificar erroneamente um chamado. Severidade **baixa**.
- **Confiança:** 90.
- **Correção:** o comportamento foi mantido para preservar o contrato de rótulos e evitar mudar registros legados sem uma política de dados. A validação deve ser aplicada na origem que escreve `chamados.status`.

## Decisões

- **Não reescrevi a aplicação nem troquei mysqli, rotas ou nomes de funções.** As mudanças foram incrementais e as assinaturas públicas foram verificadas por Reflection.
- **Mantive o fallback MD5 apenas durante a migração.** Removê-lo imediatamente bloquearia usuários de instalações legadas; o fallback é usado somente quando o hash tem formato MD5 e a coluna comporta bcrypt.
- **Não automatizei o `ALTER TABLE` nem o backfill.** Alterar schema e dados de produção durante um login é mais arriscado que uma migration explícita, com backup e janela operacional.
- **Mantive `EXPORT_DIR` e `SMTP_API_KEY` como constantes carregadas do ambiente.** Scripts externos podem consultá-las mesmo sem estarem no manifesto; a rota CSV deixou de depender do arquivo fixo.
- **Não adicionei redirect HTTPS, rate limit, CSRF ou reset de senha.** A aplicação atual não possui operações de escrita no painel, e TLS/limite de tentativas normalmente pertencem ao edge ou à infraestrutura. Essas medidas continuam sendo recomendações de operação.
- **Não alterei os rótulos de status/prioridade nem a ordem declarada da listagem e do CSV.** Isso preserva consumidores que fazem correspondência textual e scripts noturnos.
- **Removi tipos de união internos incompatíveis com PHP anterior a 8.0** e usei a forma antiga de `session_set_cookie_params()`, mantendo compatibilidade com instalações legadas sem mudar a superfície pública.

## Validação executada

- `php -l` em `config.php`, `index.php` e `lib.php`: sem erros.
- Reflection das funções públicas: nomes, parâmetros e retornos conforme `manifest.md`.
- MariaDB 11.8.6 isolado: 52 verificações aprovadas, cobrindo bcrypt, MD5 legado, migration em `VARCHAR(255)`, ausência de rehash em `CHAR(32)`, visibilidade cliente/técnico, busca, detalhe, média, CSV, sentinel e consultas preparadas.
- Smoke test HTTP: login com redirecionamento, listagem de cliente, exportação CSV e tentativa de detalhe fora da visibilidade.
- Teste das variáveis de ambiente, incluindo preservação de valores `"0"` e comportamento de `SESSION_COOKIE_SECURE`.

A conexão ao MariaDB principal não estava disponível (`root` e `painel` retornaram acesso negado), portanto não foi executada uma validação contra os dados reais de produção.