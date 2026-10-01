# Relatório técnico — LEB-100-A

## Resumo

O painel tinha exposições diretas de dados de chamados, injeção na busca e segredos de produção no código. Também usava MD5 para senhas, gerava CSV em um arquivo compartilhado e falhava quando não havia nenhuma resposta registrada. Corrigi esses pontos sem alterar as assinaturas públicas de `lib.php`, os nomes das rotas, o layout contratado da tabela ou o formato lógico do CSV.

## F1 — Credenciais de produção embutidas no repositório

**Onde (linhas originais):** `code/config.php:12` e `code/config.php:15`.

`DB_PASS` tinha uma senha de banco de produção como fallback e `SMTP_API_KEY` tinha uma chave de serviço literal. Qualquer pessoa que pudesse ler este arquivo, um artefato de deploy ou o histórico do repositório recebia credenciais utilizáveis, sem precisar explorar a aplicação. A senha do banco permite conexão como o usuário configurado; a chave SMTP permite agir perante o serviço de notificações.

**Severidade:** crítica. **Confiança:** 100/100.

Removi os valores literais e preservei as constantes, agora alimentadas por `DB_PASS` e `SMTP_API_KEY` do ambiente. O deploy precisa provisionar ambas as variáveis e revogar/rotacionar os valores já expostos.

## F2 — Clientes conseguiam acessar chamados e métricas de outros clientes

**Onde (linhas originais):** `code/index.php:45–74`.

Após o login, a rota CSV chamava `exportarCsv()` sem considerar o papel, o detalhe buscava qualquer `id` e a listagem chamava uma consulta global. Assim, por exemplo, uma sessão de `ana` podia requisitar `index.php?ver=103` ou `index.php?export=csv` e receber dados de `bruno`. A média no topo também era calculada sobre toda a base. Isso contradiz diretamente a regra de visibilidade do manifesto.

**Severidade:** alta. **Confiança:** 100/100.

Para a rota web, clientes agora recebem uma consulta, CSV e média restritos a `usuario_id`; técnicos continuam vendo todos os chamados. O detalhe não autorizado devolve a mesma resposta de chamado inexistente, sem confirmar a existência do registro. A função pública `exportarCsv(mysqli $db)` permanece global para os consumidores internos contratados; a rota usa a nova função interna com escopo de usuário.

## F3 — Injeção SQL no termo de busca

**Onde (linhas originais):** `code/lib.php:80–85`.

`listarChamados()` concatenava `busca` dentro de `titulo LIKE '%...%'`. O parâmetro GET chegava a essa função sem escapar; uma aspa seguida de condição e comentário SQL podia encerrar o literal e alterar o predicado executado. Mesmo sem consultas múltiplas, isso permite mudar a busca e expor linhas além do filtro pretendido.

**Severidade:** alta. **Confiança:** 100/100.

Substituí a concatenação por `mysqli::prepare()` e `bind_param()`. A busca continua aceitando o mesmo parâmetro e preserva a semântica de `LIKE`, inclusive curingas que já eram aceitos, mas o conteúdo agora é sempre valor ligado ao placeholder.

## F4 — Senhas eram verificadas somente com MD5

**Onde (linhas originais):** `code/lib.php:15–20` e `code/schema.sql:7`.

O código calculava `md5($senha)` e comparava diretamente com uma coluna `CHAR(32)`. MD5 é rápido e sem salt, permitindo que um vazamento da tabela seja atacado eficientemente com listas e GPU; os próprios hashes são a única barreira depois de um acesso ao banco.

**Severidade:** alta. **Confiança:** 100/100.

`autenticar()` agora lê o hash da conta, aceita hashes MD5 apenas para uma migração compatível e, depois de uma autenticação legada bem-sucedida, grava `password_hash()` antes de devolver exatamente `id`, `nome` e `papel`. Hashes modernos são verificados com `password_verify()`. A coluna foi ampliada para `VARCHAR(255)` e o `schema.sql` contém o `ALTER` idempotente necessário às instalações existentes. Senhas já presentes no `seed.sql` continuam válidas no primeiro login.

## F5 — XSS refletido na busca

**Onde (linhas originais):** `code/index.php:79–82`.

O valor de `$_GET['busca']` era inserido literalmente tanto no atributo `value` quanto no texto “Resultados para”. Um link como `?busca=\"><script>...</script>` fechava o atributo e criava marcação controlada pelo atacante no navegador de qualquer usuário autenticado que o abrisse.

**Severidade:** média. **Confiança:** 100/100.

Normalizo o parâmetro para string e o renderizo com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois contextos. Também apliquei a mesma configuração explícita aos campos de chamado já escapados.

## F6 — CSV era produzido em arquivo compartilhado no diretório web

**Onde (linhas originais):** `code/lib.php:125–150`.

Todas as exportações gravavam em `EXPORT_DIR/chamados.csv` e só então faziam `readfile()`. Duas requisições concorrentes podiam intercalar escrita e leitura do mesmo nome: uma resposta podia conter o CSV da outra ou um arquivo parcial. O arquivo também permanecia em um diretório associado à aplicação, ampliando a superfície caso o diretório fosse servido ou tivesse permissões inadequadas.

**Severidade:** média. **Confiança:** 95/100.

O CSV agora é consultado e transmitido diretamente por `php://output`; não há mais arquivo temporário compartilhado. Cabeçalho, ordenação crescente por `id`, colunas e rótulos de status permanecem os declarados no manifesto.

## F7 — Valores do CSV podiam executar fórmulas em planilhas

**Onde (linhas originais):** `code/lib.php:138–143`.

Título e nome do técnico eram enviados sem transformação a `fputcsv()`. `fputcsv()` protege a estrutura do arquivo, mas não impede que programas como Excel ou LibreOffice interpretem uma célula iniciada por `=`, `+`, `-` ou `@` como fórmula quando um operador abre o download. Um chamado criado por outra integração poderia, portanto, executar fórmula no computador de quem exporta.

**Severidade:** média. **Confiança:** 90/100.

Campos textuais que começam com caracteres de fórmula, tabulação ou retorno de carro recebem apóstrofo antes da serialização. Os demais valores, o cabeçalho e os status continuam inalterados.

## F8 — A sessão autenticada mantinha o identificador anterior ao login

**Onde (linhas originais):** `code/index.php:15` e `code/index.php:24–27`.

O código iniciava a sessão e, após credenciais válidas, apenas acrescentava `uid` e `papel` ao mesmo identificador. Se um invasor conseguisse induzir uma vítima a usar um ID de sessão que ele conhece antes do login, esse ID passaria a estar autenticado. Não havia renovação de ID no ponto de elevação de privilégio.

**Severidade:** média. **Confiança:** 95/100.

O login bem-sucedido executa `session_regenerate_id(true)` antes de gravar a identidade. A configuração do cookie também passou a usar `HttpOnly` e `SameSite=Lax`, com `Secure` quando a requisição chega por HTTPS.

## F9 — A média de resposta gerava divisão por zero

**Onde (linhas originais):** `code/lib.php:109–116`.

Quando não existia chamado com `minutos_resposta` preenchido, o laço terminava com `$qtd === 0` e a expressão `$soma / $qtd` lançava `DivisionByZeroError` no PHP atual. Isso interrompe a página inicial justamente em instalações novas ou períodos sem primeira resposta.

**Severidade:** média. **Confiança:** 100/100.

Troquei a acumulação em PHP por `COALESCE(AVG(...), 0)`, que retorna `0.0` no conjunto vazio e conserva a média como `float` para os casos com dados. A mesma regra é usada para a média restrita do cliente.

## F10 — Listagem e exportação sofriam consultas N+1 para técnicos

**Onde (linhas originais):** `code/lib.php:69–71` e `code/lib.php:85–90`.

Depois de buscar os chamados, o laço chamava `tecnicoNome()` para cada linha, emitindo uma nova consulta por técnico. Com N chamados, a listagem fazia N+1 idas ao banco, inclusive repetindo o mesmo técnico em diversas linhas. A exportação repetia o padrão.

**Severidade:** média. **Confiança:** 100/100.

As consultas de listagem e CSV agora usam `LEFT JOIN usuarios` e retornam `tecnico_nome` na mesma consulta. Mantive a chave adicional exigida pelo manifesto e o valor `-` para chamados sem técnico.

## Decisões

- Mantive todas as assinaturas públicas de `lib.php`. As funções auxiliares de escopo e transmissão de CSV são internas, para que os relatórios e a rotina noturna que usam `listarChamados`, `mediaResposta` e `exportarCsv` não percam o comportamento global contratado.
- Mantive os textos e o fallback de `formatarStatus` para valores fora de `1–3`. O manifesto fixa os três valores válidos e consumidores podem depender do fallback legado para dados históricos inconsistentes; alterar esse rótulo ampliaria o escopo sem uma regra de negócio para o estado inválido.
- Não incluí limitação de tentativas de login. Um bloqueio seguro exigiria armazenamento e política operacional de expiração/recuperação que não existem nesta stack; implementar um contador local seria fácil de contornar ou poderia bloquear clientes legítimos.
- Mantive `EXPORT_DIR` definido em `config.php`, embora a exportação web não o use mais, para não quebrar scripts internos fora da superfície documentada que possam consultar essa constante.
- Não adicionei uma página de migração separada nem novas dependências. A alteração de coluna fica no próprio `schema.sql`, e a migração de cada hash ocorre no login bem-sucedido, preservando as senhas legadas até esse momento.

## Validação

Executei `php -l` em `config.php`, `lib.php` e `index.php`, sem erros. Também executei verificações comportamentais com subclasses simuladas de `mysqli`: autenticação MD5 seguida de rehash, retorno público da autenticação, consulta de cliente com termo de injeção, média vazia, ordenação/escape do CSV e os três rótulos de status. Todas passaram. O MySQL local recusou a autenticação das credenciais disponíveis, portanto não foi possível executar esse conjunto contra um servidor real nesta área de trabalho.
