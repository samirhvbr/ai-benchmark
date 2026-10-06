# Relatório técnico — LEB-100-A

## Resumo

O sistema foi evoluído sem alterar as assinaturas públicas, rotas, rótulos, estrutura HTML declarada, formato do CSV ou regras de visibilidade do `manifest.md`. As correções principais cobrem autenticação, sessão, autorização, SQL, XSS, senhas, CSV e o cálculo de média.

O código entregue mantém compatibilidade de login com hashes MD5 existentes, mas passa a usar `password_verify()`, hashes bcrypt nos dados de teste e rehash progressivo quando a coluna comporta o novo hash. A visibilidade agora é aplicada também à listagem, detalhe, média e exportação.

O banco MariaDB real em `127.0.0.1:3307` esteve disponível nesta execução. A validação combinou `php -l`, Reflection, verificações de hashes, mocks de `mysqli` e integração real para login, visibilidade, IDOR, busca com tentativa de SQL injection, CSV, média e compatibilidade MD5/rehash.

## Achados

### F1 — Segredos de produção embutidos

- **Localização original:** `code/config.php:11-18`.
- **Mecanismo:** a senha do banco estava no fallback de `DB_PASS` e uma chave SMTP estava diretamente no fonte. O fallback fazia com que a aplicação funcionasse mesmo sem configuração externa, espalhando credenciais de produção em qualquer cópia do pacote.
- **Impacto e severidade:** posse do fonte permite conexão ao banco e uso da central de e-mail; severidade **alta**.
- **Confiança:** 100/100.
- **Correção:** `config.php` passou a exigir `DB_PASS` via ambiente e os segredos/constantes não utilizados foram removidos. A implantação precisa fornecer a variável de ambiente.

### F2 — SQL montado por concatenação na busca

- **Localização original:** `code/lib.php:80-85`; a busca chega por `code/index.php:72-74`.
- **Mecanismo:** `listarChamados()` inseria `$busca` diretamente no `LIKE`, permitindo alterar o predicado SQL a partir de `index.php?busca=...`. As consultas de detalhe e técnico também concatenavam valores, embora seus parâmetros públicos fossem tipados como inteiros; elas foram convertidas para a mesma camada preparada como defesa em profundidade.
- **Impacto e severidade:** um atacante podia alterar filtros e ampliar resultados da busca, explorando os privilégios do usuário do banco; severidade **alta**.
- **Confiança:** 100/100.
- **Correção:** as consultas passaram a usar parâmetros preparados por `executarPreparada()`, inclusive na validação de sessão, detalhe, média, técnico e exportação (`code/lib.php:10-35`, `241-304`, `310-360`).

### F3 — XSS refletido e escape HTML inconsistente

- **Localização original:** `code/index.php:53-64` e `code/index.php:72-95`.
- **Mecanismo:** o termo de busca era impresso diretamente no atributo `value`; os demais valores usavam `htmlspecialchars()` sem flags nem UTF-8 explícitos, deixando a proteção dependente do padrão do runtime. Um termo com aspas ou markup podia sair do atributo.
- **Impacto e severidade:** execução de script no contexto autenticado, com risco de roubo de sessão e alteração da visão do usuário; severidade **alta**.
- **Confiança:** 100/100.
- **Correção:** foi adicionado `e()` com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8, aplicado a busca, título, descrição e técnico; IDs passaram a ser tratados como inteiros (`code/index.php:9-12`, `97-132`).

### F4 — Sessão fixável e confiança excessiva nos dados da sessão

- **Localização original:** `code/index.php:15`, `code/index.php:20-26` e `code/index.php:38-39`.
- **Mecanismo:** o login não regenerava o ID da sessão, não habilitava modo estrito e não definia cookies `HttpOnly`, `Secure` e `SameSite`. Depois do login, o papel era usado diretamente de `$_SESSION`, sem confirmar que o usuário ainda existia no banco.
- **Impacto e severidade:** fixação ou roubo de sessão podia preservar acesso e permitir uso de papel manipulado; severidade **alta**.
- **Confiança:** 98/100.
- **Correção:** o cookie passou a ter `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição indica HTTPS; o login regenera o ID e `usuarioSessaoValido()` valida ID e papel contra o banco (`code/index.php:25-71`, `code/lib.php:38-84`).

### F5 — Falha de autorização após login (IDOR)

- **Localização original:** `code/index.php:20-53`, `code/index.php:72-74` e `code/lib.php:78-150`.
- **Mecanismo:** o índice web possuía um gate de login, mas após o login o papel vinha da sessão sem validação e não havia predicado `usuario_id` para clientes; as funções públicas também não recebiam contexto de usuário. O CSV exportava todos os registros para qualquer sessão autenticada.
- **Impacto e severidade:** qualquer cliente autenticado podia enumerar chamados, abrir detalhes de outros clientes e baixar o CSV completo; severidade **crítica**.
- **Confiança:** 100/100.
- **Correção:** o entrypoint web passou a rejeitar sessão ausente ou inválida; clientes recebem apenas `c.usuario_id = ?`, enquanto técnicos mantêm acesso a todos os chamados. A exportação e a média usam a mesma regra (`code/lib.php:241-360`).

### F6 — Senhas armazenadas com MD5

- **Localização original:** `code/lib.php:13-20`, `code/schema.sql:7` e `code/seed.sql:2-8`.
- **Mecanismo:** a autenticação calculava `md5($senha)` e comparava o resultado com o banco. MD5 é rápido, sem sal, e permite recuperação offline das senhas se o banco vazar.
- **Impacto e severidade:** comprometimento das contas de clientes e técnicos após vazamento; severidade **alta**.
- **Confiança:** 100/100.
- **Correção:** o schema passou a usar `VARCHAR(255)`, o seed usa bcrypt e `autenticar()` usa `password_verify()`. MD5 continua apenas como fallback de migração; quando a coluna comporta o hash, o login faz rehash progressivo (`code/lib.php:115-178`, `code/schema.sql:7`).

### F7 — Injeção de fórmula via CSV

- **Localização original:** `code/lib.php:123-146`.
- **Mecanismo:** título e técnico eram enviados crus ao `fputcsv()`. Campos iniciados por `=`, `+`, `-` ou `@` podem ser interpretados como fórmula ou DDE quando o CSV é aberto em uma planilha.
- **Impacto e severidade:** execução de ação no contexto do usuário que abre o arquivo, incluindo leitura ou envio de dados dependendo da aplicação; severidade **média**, pois depende do cliente de planilha.
- **Confiança:** 95/100.
- **Correção:** `valorCsvSeguro()` remove BOM, espaços e caracteres Unicode de formato antes da checagem e prefixa operadores perigosos; a exportação aplica a função a título e técnico (`code/lib.php:86-109`, `372-380`). O hífen isolado é preservado para não alterar desnecessariamente o valor de ausência `-`.

### F8 — Divisão por zero na média de resposta

- **Localização original:** `code/lib.php:107-116`.
- **Mecanismo:** quando não havia nenhuma linha com `minutos_resposta`, `$qtd` permanecia zero e `$soma / $qtd` gerava aviso e resultado inválido.
- **Impacto e severidade:** erro visível no painel, poluição de logs e indicador de SLA incorreto; severidade **média**.
- **Confiança:** 100/100.
- **Correção:** a média passou a usar `AVG()` no banco e retorna `0.0` quando não há resultado ou a média é nula (`code/lib.php:310-333`).

### F9 — Consultas sem paginação e com varredura completa

- **Localização original:** `code/lib.php:78-92` e `code/lib.php:107-116`; o comportamento permanece em `code/lib.php:241-276` e `code/lib.php:310-333`.
- **Mecanismo:** a listagem buscava todos os chamados e usava `LIKE '%termo%'`, que não aproveita um índice comum de título; a média varria novamente as linhas a cada renderização. A exportação também precisa percorrer tudo, conforme o contrato.
- **Impacto e severidade:** latência, uso de memória e carga no MySQL crescem com o volume, especialmente para técnicos; severidade **média**.
- **Confiança:** 90/100.
- **Correção:** não alterei a paginação nem removi a exportação completa porque o manifesto exige a listagem e o CSV sem parâmetros de limite. A evolução recomendada é paginação compatível, busca indexada/normalizada e cache do indicador.

### F10 — Falhas de banco e exportação silenciosas

- **Localização original:** `code/lib.php:13-20`, `code/lib.php:64-72` e `code/lib.php:123-150`; a mesma classe de comportamento aparece em `code/lib.php:10-35`, `115-178`, `218-235` e `340-384`.
- **Mecanismo:** falhas de `prepare`, `execute`, `query` ou abertura da saída são convertidas em `null`, lista vazia, `0.0` ou retorno sem cabeçalhos, sem log estruturado. O operador não distingue autorização, dado inexistente e indisponibilidade.
- **Impacto e severidade:** indisponibilidade silenciosa e diagnóstico lento; uma falha de segurança ou de dados pode passar despercebida; severidade **média**.
- **Confiança:** 95/100.
- **Correção:** mantive os retornos públicos para não quebrar consumidores. A melhoria recomendada é registrar erros internamente e separar erro de operação de resultado vazio sem mudar as assinaturas.

### F11 — Controles de autenticação dependentes de infraestrutura

- **Localização original:** `code/index.php:19-32` e `code/index.php:42-58`; o ponto permanece em `code/index.php:19-58`.
- **Mecanismo:** não há limite de tentativas, bloqueio progressivo ou integração com WAF/rate limiter. O cookie só é marcado como `Secure` quando `HTTPS` ou `X-Forwarded-Proto` indica HTTPS; não há redirecionamento explícito nem validação de proxy confiável no código.
- **Impacto e severidade:** credenciais fracas ficam expostas a tentativas repetidas e uma implantação HTTP ou configuração incorreta de proxy pode não proteger o cookie; severidade **média**, condicionada ao ambiente.
- **Confiança:** 85/100.
- **Correção:** não implementei throttling no código porque não há armazenamento ou contrato para isso e a política depende da infraestrutura. TLS, confiança no proxy e limite de login devem ser aplicados no servidor/WAF.

## Decisões

- Mantive todas as assinaturas de `lib.php`, rotas e parâmetros `busca`, `ver` e `export`, os rótulos de status, a ordem e o cabeçalho do CSV, a tabela `id="tabela-chamados"` e as credenciais do manifesto.
- Mantive o fallback MD5 apenas para não bloquear bases legadas. A migração de uma instalação existente precisa alterar a coluna para `VARCHAR(255)` antes de esperar rehash; não incluí uma migration automática porque o pacote não possui um mecanismo de migração e a alteração forçada poderia indisponibilizar o login legado.
- Removi `SMTP_API_KEY` e `EXPORT_DIR` em vez de criar placeholders secretos. O manifesto promete CSV na saída, não a criação de `/var/www/painel/tmp/chamados.csv`; consumidores que dependiam dessa constante ou efeito colateral precisam ser migrados.
- Não adicionei paginação, rate limiting, redirecionamento HTTPS, logging centralizado ou tratamento de status/prioridade inválidos nesta etapa. São melhorias reais, mas dependem de contrato, infraestrutura ou mudança de comportamento de consumidores existentes.
- Mantive `tecnicoNome()` com a mesma assinatura pública, mesmo usando a nova camada preparada internamente.

## Verificação

- `php -l` passou em `code/config.php`, `code/index.php` e `code/lib.php`.
- Reflection confirmou as assinaturas públicas do manifesto.
- Os mocks passaram para sessão ausente, cliente, técnico, listagem, detalhe, média, exportação, autenticação bcrypt e fallback MD5.
- `formatarStatus(1/2/3)` retornou exatamente `Aberto`, `Em atendimento` e `Resolvido`.
- As credenciais do `seed.sql` foram verificadas com `password_verify()`.
- A integração real com MariaDB validou login dos quatro usuários do seed, visibilidade por papel, rejeição de IDOR de cliente, busca com tentativa de SQL injection, CSV restrito ao cliente, média sem divisão por zero, autenticação MD5 legada e rehash progressivo em coluna `VARCHAR(255)`.
