# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas que permitiam a um cliente alcançar chamados de outros clientes, alterar a consulta de busca e executar JavaScript no navegador de quem visualizasse uma busca maliciosa. Também mantinha segredos e hashes de senha MD5 no código. As correções preservam as assinaturas públicas, as rotas, os rótulos de status, o formato e a ordenação do CSV e a estrutura exigida da tabela HTML.

## F1 — Falta de autorização por papel e proprietário nas rotas de dados

**Local original:** `code/index.php`, linhas 44–73. **Categoria:** segurança. **Severidade:** alta. **Confiança:** 99/100.

Depois de qualquer login, a rota de exportação chamava `exportarCsv()` para a base inteira, o detalhe carregava qualquer `ver=<id>`, e a listagem chamava `listarChamados()` sem considerar o usuário da sessão. Assim, Ana podia abrir `?ver=103`, consultar chamados de Bruno e baixar todos os registros no CSV. Isso viola diretamente a regra de visibilidade do manifesto.

Implementei a checagem no ponto de entrada: técnicos mantêm acesso integral; os demais papéis recebem listagem e CSV filtrados por `usuario_id`, e um detalhe que não pertence ao cliente devolve HTTP 403 sem exibir os campos. As funções públicas originais foram mantidas, pois rotinas internas declaradas no manifesto ainda podem precisar da listagem e exportação completas.

## F2 — Injeção SQL na busca por título

**Local original:** `code/lib.php`, linhas 80–85. **Categoria:** segurança. **Severidade:** alta. **Confiança:** 99/100.

`listarChamados()` concatenava `busca` dentro de `LIKE '%...%'`. Um termo como `' OR 1=1 -- ` deixava de ser texto e passava a compor a cláusula SQL, permitindo contornar o filtro ou produzir consultas diferentes da pretendida.

Substituí a montagem por `mysqli::prepare()` e `bind_param()`, incluindo o `%` no valor ligado. A mesma consulta interna recebe, quando necessário, o filtro de proprietário. Não houve mudança de assinatura em `listarChamados`.

## F3 — XSS refletido pela busca

**Local original:** `code/index.php`, linhas 79–82. **Categoria:** segurança. **Severidade:** alta. **Confiança:** 97/100.

O valor de `busca` era inserido sem escape tanto no atributo `value` quanto em `Resultados para`. Uma URL com HTML, por exemplo uma tag com manipulador de evento, era refletida na página autenticada e executava no contexto do painel.

O parâmetro agora é aceito somente como string e é codificado com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` em ambos os pontos. Os campos de título, descrição e técnico também passaram a usar a codificação explícita UTF-8.

## F4 — Segredos de produção embutidos no repositório

**Local original:** `code/config.php`, linhas 12–15. **Categoria:** segurança. **Severidade:** alta. **Confiança:** 100/100.

O arquivo distribuía uma senha de banco como fallback e uma chave de API SMTP literal. Qualquer pessoa com acesso ao código, a um backup ou a um log de revisão recebia credenciais potencialmente válidas fora do controle de acesso da infraestrutura.

Removi os valores literais. `DB_PASS` e `SMTP_API_KEY` são lidos exclusivamente do ambiente; sem configuração o valor fica vazio, tornando a falha de configuração visível em vez de reutilizar uma credencial exposta. A credencial real deve ser rotacionada fora deste pacote.

## F5 — Senhas verificadas com MD5 rápido e sem salt

**Local original:** `code/lib.php`, linha 15; `code/schema.sql`, linha 7. **Categoria:** segurança. **Severidade:** alta. **Confiança:** 99/100.

`autenticar()` calculava MD5 da senha e fazia a comparação no SQL. Os hashes de 32 caracteres no banco podem ser atacados rapidamente com listas e GPU, e senhas iguais geram o mesmo hash.

O login agora busca o hash armazenado, usa `password_verify()` para hashes modernos e comparação constante para o formato MD5 legado. Após uma autenticação legada válida, grava `password_hash()` para aquele usuário. A coluna nova é `VARCHAR(255)` e `code/migrate_password_hashes.sql` traz a alteração necessária para instalações já existentes. O retorno de `autenticar` continua contendo apenas `id`, `nome` e `papel`.

## F6 — Fixação de sessão após autenticação

**Local original:** `code/index.php`, linhas 24–26. **Categoria:** segurança. **Severidade:** média. **Confiança:** 88/100.

O código atribuía identidade e papel à sessão existente sem trocar seu identificador. Se um invasor induzisse a vítima a usar um ID de sessão conhecido antes do login, ele poderia reutilizá-lo depois da autenticação.

Após credenciais válidas, o código agora chama `session_regenerate_id(true)` antes de gravar `uid` e `papel`.

## F7 — Injeção de fórmula em células do CSV

**Local original:** `code/lib.php`, linhas 138–143. **Categoria:** segurança. **Severidade:** média. **Confiança:** 90/100.

O título do chamado e o nome do técnico eram emitidos diretamente no CSV. Caso um título controlado por usuário começasse com `=`, `+`, `-`, `@`, tabulação ou retorno de carro, planilhas poderiam interpretá-lo como fórmula ao abrir o download, permitindo fórmulas e links controlados pelo conteúdo do chamado.

Os textos exportados com esses prefixos recebem apóstrofo antes de `fputcsv`, forçando interpretação textual em programas de planilha. Cabeçalho, ordem por `id` e rótulos de status permanecem exatamente como contratados.

## F8 — Arquivo temporário compartilhado na exportação

**Local original:** `code/lib.php`, linhas 125–150. **Categoria:** segurança. **Severidade:** média. **Confiança:** 93/100.

Toda exportação sobrescrevia o mesmo caminho `EXPORT_DIR/chamados.csv` e só depois o enviava. Exportações simultâneas podem misturar dados ou entregar o arquivo de outra requisição; além disso, um diretório exposto pelo servidor web poderia tornar o arquivo persistente acessível por URL.

O CSV agora é gerado em streaming em `php://output`. Não há arquivo compartilhado, janela de corrida nem resíduo de dados no diretório temporário.

## F9 — Divisão por zero quando não há primeira resposta

**Local original:** `code/lib.php`, linha 116. **Categoria:** bug. **Severidade:** média. **Confiança:** 99/100.

Quando a tabela não possui nenhum `minutos_resposta` não nulo — cenário normal numa instalação nova ou antes do atendimento inicial — `qtd` fica em zero e `mediaResposta()` executa `$soma / $qtd`, causando `DivisionByZeroError` e interrompendo a listagem.

Passei o cálculo para `COALESCE(AVG(minutos_resposta), 0)`, retornando `0.0` no conjunto vazio e mantendo o retorno `float` declarado.

## F10 — Consulta N+1 para obter técnicos

**Local original:** `code/lib.php`, linha 89. **Categoria:** performance. **Severidade:** média. **Confiança:** 98/100.

Para cada chamado retornado, a listagem fazia uma nova consulta em `tecnicoNome()`. Com N chamados, isso produzia N+1 viagens ao banco, aumentando a latência linearmente e pressionando o banco em uma tela usada com frequência.

A consulta de listagem e a de CSV agora usam `LEFT JOIN usuarios` e retornam `tecnico_nome` na mesma consulta. A função pública `tecnicoNome()` foi preservada para consumidores externos.

## Decisões

- Mantive as assinaturas e o comportamento completo de `listarChamados()` e `exportarCsv()`. Elas são superfície pública para rotinas internas; o filtro de cliente é aplicado pela rota web usando auxiliares internos.
- Não alterei os rótulos conhecidos de `formatarStatus` nem a estrutura da tabela HTML, pois ambos são contrato explícito. Também preservei a ordenação do CSV por `id` crescente.
- Não incluí paginação nem uma mudança de índice para busca por substring. Paginação alteraria a listagem que consumidores e a tela recebem, e um índice B-tree comum não acelera `LIKE '%termo%'`; a eliminação de N+1 resolve o custo evitável sem ampliar o escopo.
- Não executei DDL automaticamente durante login. A migração de senha está em arquivo separado para ser aplicada de forma controlada antes do deploy, sem exigir que o usuário da aplicação tenha permissão de alterar tabelas.
- A migração de MD5 é progressiva porque não há neste sistema um fluxo público de redefinição de senha. Forçar uma troca global sem esse fluxo bloquearia usuários legítimos e mudaria o contrato operacional.

## Validação

Executei `php -l` nos três arquivos PHP alterados e um teste direto dos três rótulos contratuais de `formatarStatus` e da neutralização de células CSV. O cliente MySQL local não ofereceu uma conta administrativa utilizável, portanto não foi possível subir `schema.sql` e executar o fluxo HTTP integrado neste ambiente.
