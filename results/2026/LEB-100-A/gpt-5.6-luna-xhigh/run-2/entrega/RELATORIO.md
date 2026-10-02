# Relatório técnico — LEB-100-A

## Resumo

O sistema tinha falhas de autorização, injeção SQL, exposição de segredos, armazenamento fraco de senhas e XSS refletido. Também havia uma divisão por zero quando não existiam respostas, consultas repetidas para buscar técnicos e uma exportação CSV baseada em arquivo temporário previsível.

As correções preservam as assinaturas de `lib.php`, as rotas, os rótulos de status, a estrutura da tabela HTML e o cabeçalho/ordenação do CSV. A visibilidade agora é aplicada dentro das consultas: clientes recebem somente seus chamados e técnicos continuam vendo todos. Hashes MD5 existentes continuam funcionando durante a migração e são atualizados para `password_hash()` no primeiro login bem-sucedido.

As linhas citadas abaixo são as linhas do código original recebido, conforme exigido por `TAREFA.md`.

## F1 — Cliente conseguia acessar chamados de outros clientes

- **Onde:** `code/lib.php`, linhas 78–101; exposto pelas rotas de listagem, detalhe e exportação de `code/index.php`.
- **Mecanismo:** `listarChamados()` consultava todos os registros, `verChamado()` buscava qualquer id recebido e `exportarCsv()` exportava a tabela inteira. Nenhuma dessas consultas relacionava o `usuario_id` do chamado ao usuário autenticado. Assim, um cliente autenticado podia abrir `?ver=101`, ver registros de outro cliente na listagem ou baixar o CSV completo.
- **Impacto:** exposição de títulos, descrições, status e dados operacionais de outros clientes. **Severidade: crítica. Confiança: 100.**
- **Correção:** as quatro consultas agora usam o papel e o id da sessão: técnicos não recebem filtro; demais usuários autenticados recebem `usuario_id = ?`. A média do painel também respeita o mesmo escopo para não revelar um indicador calculado sobre chamados alheios.

## F2 — Injeção SQL na busca por título

- **Onde:** `code/lib.php`, linhas 80–85.
- **Mecanismo:** o valor controlado por `busca` era concatenado diretamente em `LIKE '%...%'`. Um termo com aspas e operadores SQL podia alterar o `WHERE` e retornar registros fora da busca; dependendo da consulta e do servidor, também podia ser usado para explorar a leitura da tabela.
- **Impacto:** leitura indevida de chamados e bypass do filtro de busca. **Severidade: alta. Confiança: 99.**
- **Correção:** a busca passou a usar `LIKE ?` em uma instrução preparada, com o termo encapsulado como parâmetro. Os filtros de visibilidade também são parametrizados.

## F3 — Credenciais e chave de API no código-fonte

- **Onde:** `code/config.php`, linhas 11–15.
- **Mecanismo:** o fallback de `DB_PASS` continha a senha de produção e `SMTP_API_KEY` continha uma chave utilizável. Qualquer cópia do pacote, backup ou acesso de leitura ao código expunha esses segredos, independentemente de a aplicação estar usando a chave de e-mail naquele fluxo.
- **Impacto:** acesso potencial ao banco e uso indevido da integração transacional. **Severidade: alta. Confiança: 100.**
- **Correção:** os valores agora vêm exclusivamente de `DB_PASS` e `SMTP_API_KEY` no ambiente; na ausência deles, ficam vazios em vez de revelar um segredo embutido. A implantação deve fornecer `DB_PASS` antes de conectar ao banco.

## F4 — Senhas verificadas somente com MD5

- **Onde:** `code/lib.php`, linhas 13–20 do código original; o formato legado também era declarado em `code/schema.sql`, linha 7.
- **Mecanismo:** a senha recebida era transformada em MD5, um hash rápido e sem salt, e comparada diretamente. Em caso de vazamento da tabela, senhas fracas poderiam ser recuperadas por dicionários e tabelas pré-computadas.
- **Impacto:** comprometimento de contas após vazamento do banco, com risco de reutilização das senhas em outros serviços. **Severidade: alta. Confiança: 100.**
- **Correção:** `autenticar()` agora aceita tanto `password_hash()` quanto o MD5 legado; quando o login legado é válido, grava imediatamente um hash moderno. `usuarios.senha` passou de `CHAR(32)` para `VARCHAR(255)` para comportar o hash novo. O `seed.sql` continua usando MD5 para manter os dados de teste e a compatibilidade durante a transição.

## F5 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php`, linhas 72–83.
- **Mecanismo:** o valor de `$_GET['busca']` era inserido sem escape tanto no atributo `value` do formulário quanto no texto de “Resultados para”. Um link com HTML/JavaScript no termo podia executar script no navegador de um usuário autenticado.
- **Impacto:** execução de JavaScript na origem do painel, com possibilidade de ler a sessão acessível ao navegador e agir em nome do usuário. **Severidade: alta. Confiança: 100.**
- **Correção:** o valor é aceito somente como string e é renderizado com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` em todos os pontos da busca. Os demais campos exibidos também usam escape explícito.

## F6 — Sessão não era renovada após login

- **Onde:** `code/index.php`, linhas 15 e 20–27.
- **Mecanismo:** a sessão era iniciada e, após autenticação, o mesmo identificador recebia `uid` e `papel`. Sem modo estrito e sem regeneração, um identificador de sessão previamente fixado poderia ser reutilizado depois que a vítima fizesse login.
- **Impacto:** possibilidade de sequestro da sessão autenticada em cenário de fixation. **Severidade: alta. Confiança: 95.**
- **Correção:** o cookie passou a usar `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS; o modo estrito é habilitado e `session_regenerate_id(true)` é chamado após autenticação bem-sucedida.

## F7 — CSV era gravado em arquivo compartilhado e previsível

- **Onde:** `code/lib.php`, linhas 123–150.
- **Mecanismo:** toda exportação usava o mesmo caminho `EXPORT_DIR/chamados.csv`, truncava o arquivo e só depois o lia para a resposta. Requisições concorrentes podiam misturar ou substituir o resultado; se o diretório fosse servido pelo web server, o arquivo persistente também poderia ser acessado fora do fluxo autenticado e permanecer com dados da última exportação.
- **Impacto:** exposição ou corrupção do resultado da exportação e estado residual no servidor. **Severidade: média. Confiança: 95.**
- **Correção:** a consulta é feita uma vez e as linhas são escritas diretamente em `php://output`, mantendo o cabeçalho e a ordenação contratados. Nenhum arquivo compartilhado é criado.

## F8 — Média de respostas causava divisão por zero

- **Onde:** `code/lib.php`, linhas 107–116.
- **Mecanismo:** quando todos os chamados tinham `minutos_resposta IS NULL`, o laço terminava com `$qtd = 0` e a expressão `$soma / $qtd` falhava em PHP 8. O erro ocorria ao abrir a listagem, antes de qualquer chamado ser exibido.
- **Impacto:** erro fatal na tela principal em uma base nova ou durante período sem respostas. **Severidade: média. Confiança: 100.**
- **Correção:** a média agora é calculada com `AVG()` e retorna `0.0` quando não há valor.

## F9 — Padrão N+1 ao buscar o técnico

- **Onde:** `code/lib.php`, linhas 80–90 e 132–143.
- **Mecanismo:** depois da consulta dos chamados, cada item chamava `tecnicoNome()`, que fazia uma nova consulta por id de técnico. A exportação repetia o mesmo padrão. O custo crescia com o número de chamados, mesmo quando vários compartilhavam o mesmo técnico.
- **Impacto:** mais round-trips ao banco, maior latência e carga desnecessária em listagens e exportações grandes. **Severidade: média. Confiança: 99.**
- **Correção:** listagem e exportação usam `LEFT JOIN usuarios` e retornam o nome em uma única consulta. `tecnicoNome()` foi mantida para consumidores legados, mas também passou a usar instrução preparada.

## Decisões

- Mantive as assinaturas públicas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`, além das rotas, dos nomes dos parâmetros, da tabela HTML, dos rótulos e do cabeçalho do CSV.
- Mantive as expressões MD5 no `seed.sql` porque os dados fornecidos são parte da caracterização do sistema. A autenticação faz a migração oportunista; instalações existentes precisam aplicar a alteração compatível de coluna representada em `schema.sql` antes de armazenar hashes novos.
- Mantive `EXPORT_DIR` em `config.php` para não remover uma constante de configuração que pode ser lida por consumidores externos, embora a exportação corrigida não grave mais nesse diretório.
- Não adicionei limitação de tentativas de login: isso exigiria estado e política operacional que não existem no contrato recebido. A verificação continua parametrizada e a migração de hash reduz o risco de armazenamento; rate limiting deve ser tratado na camada de aplicação/infraestrutura responsável por esse controle.
- Mantive o fallback de `formatarStatus()` para valores fora de 1–3. O manifesto só define esses três valores e alterar o texto de entradas inválidas poderia quebrar o relatório gerencial sem corrigir um caminho contratual.

## Validação

- `php -l code/config.php`, `php -l code/index.php` e `php -l code/lib.php`: sem erros de sintaxe.
- Smoke test das funções de rótulo: aprovado, incluindo os três valores de status contratados.
- Reflection do PHP confirmou as sete assinaturas e retornos públicos; não há servidor MySQL ativo no ambiente para executar um teste integrado com `schema.sql` e `seed.sql`.
