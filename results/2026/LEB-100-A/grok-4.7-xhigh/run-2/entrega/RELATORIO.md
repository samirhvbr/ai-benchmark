# Relatório técnico — LEB-100-A

## Resumo

Painel de chamados em PHP/mysqli (2013) com contrato estável: assinaturas em `lib.php`, rotas `busca`/`ver`/`export`, rótulos de status e a tabela `tabela-chamados`. O código autentica, lista, detalha e exporta, mas não aplica a regra de visibilidade, interpola a busca no SQL, reflete `busca` sem escape e grava o CSV num arquivo fixo sob `/var/www`. No PHP 8.4 (runtime deste ambiente) a conexão e a média de SLA ainda caem por exceção antes do tratamento que o código imagina ter.

Não reescrevi o sistema. As assinaturas públicas, o HTML da listagem, os rótulos de `formatarStatus` e a lógica de `rotuloPrioridade` ficaram. A restrição de cliente usa a sessão já aberta pelo `index.php`; chamada sem sessão de cliente (rotina noturna, relatório gerencial em CLI) continua vendo a base inteira.

## F1 — Injeção de SQL na busca

- Onde: `code/lib.php:82` (entrada em `code/index.php:72`).
- Severidade: **crítica**. Confiança: **98**.
- Mecanismo: `listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` e executa com `$db->query`. `$busca` é `$_GET['busca']`, sem escape. A aspa de `' OR '1'='1` fecha o literal; `UNION SELECT` pode projetar `usuarios.senha`. O `LIKE` também trata `%` e `_` digitados como curinga.
- Impacto: qualquer cliente autenticado lê chamados de terceiros, hashes de senha e demais tabelas visíveis ao usuário `painel`. A visibilidade do manifesto não segura essa consulta.
- Correção: predicado `LIKE ?` com `ESCAPE`, termo passado por `bind_param`. `%`, `_` e `\` do termo viram literais. `verChamado` e `tecnicoNome` deixaram de concatenar o id (o `int` já impedia injeção; o prepared statement alinha o acesso).

## F2 — Cliente vê chamado de outro cliente

- Onde: `code/lib.php:80-101` e `code/lib.php:132`; o controlador carrega identidade e não a usa em `code/index.php:39-53`.
- Severidade: **crítica**. Confiança: **97**.
- Mecanismo: depois do login, `$uid` e `$papel` são gravados na sessão e relidos, mas nenhuma consulta os aplica. `listarChamados` faz `SELECT * FROM chamados`. `verChamado` faz `WHERE id = ` + id, então `index.php?ver=104` (fatura do Bruno) abre para a Ana. `exportarCsv` seleciona todos os chamados `ORDER BY id` e devolve o arquivo a qualquer sessão. Técnico e cliente passam pelo mesmo caminho.
- Impacto: quebra a regra do manifesto. Cliente lê título, descrição e técnico de chamados que não abriu, inclusive via CSV. O inverso (esconder chamado de técnico) não ocorria e não foi introduzido.
- Correção: se a sessão ativa tem `papel === 'cliente'`, as três consultas ganham `usuario_id = ?`. Sem sessão, ou com `tecnico`, o filtro não entra — a exportação noturna e o relatório gerencial, que chamam a lib sem sessão de cliente, seguem recebendo a base inteira. O `index.php` repete o filtro na listagem e no detalhe. Chamado alheio cai no mesmo texto `Chamado nao encontrado.` (não revela existência). CSV de cliente continua uma linha por chamado visível, `id` crescente, mesmos rótulos.

## F3 — XSS refletido em `busca`

- Onde: `code/index.php:79` e `code/index.php:82`.
- Severidade: **alta**. Confiança: **99**.
- Mecanismo: `$busca` entra cru em `value="..."` e em `Resultados para:`. `"><script>` ou `"><img src=x onerror=...>` fecha o atributo. Título e descrição já passavam por `htmlspecialchars`; a busca não. O cookie de sessão nascia sem `HttpOnly` (F7), então o script refletido podia ler o id de sessão.
- Impacto: vítima autenticada que abre um link `?busca=` executa HTML no painel, no contexto dos chamados que a sessão enxerga.
- Correção: `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. O critério de exibir "Resultados para" continua sendo a string bruta, não o HTML.

## F4 — CSV em arquivo fixo sob `/var/www`

- Onde: `code/lib.php:125-150`; constante em `code/config.php:18`.
- Severidade: **alta**. Confiança: **93**.
- Mecanismo: `exportarCsv` abre sempre `EXPORT_DIR . '/chamados.csv'` (`/var/www/painel/tmp/chamados.csv`), escreve, depois manda `readfile`. Dois pedidos concorrentes compartilham o mesmo path: o `readfile` de um pode ler o arquivo do outro (corrida de PII entre Ana e Carla). O diretório está sob `/var/www`; se o docroot alcança `painel/tmp`, o CSV fica baixável sem sessão. Se o diretório não existe, `fopen` falha e a função retorna sem corpo — neste ambiente `/var/www/painel/tmp` não existe, então `index.php?export=csv` não entrega arquivo. No caminho de erro da query o handle também vazava e o cliente recebia resposta vazia.
- Impacto: vazamento em massa dos chamados, download cruzado entre usuários, ou rota de exportação muda.
- Correção: o CSV é montado em `php://temp` e escrito na saída, que é o contrato (`exportarCsv` "escreve o CSV na saída"). Não depende de `EXPORT_DIR` e não deixa arquivo compartilhado. `EXPORT_DIR` permanece definido para outros scripts. Cabeçalhos de download iguais (`text/csv`, `filename="chamados.csv"`), omitidos só no SAPI `cli` para a rotina noturna capturar o corpo limpo.

## F5 — Segredos no fonte

- Onde: `code/config.php:12` e `code/config.php:15`.
- Severidade: **alta**. Confiança: **96**.
- Mecanismo: `DB_PASS` tem fallback literal quando `getenv('DB_PASS')` é vazio. `SMTP_API_KEY` é uma chave fixa, não lida de ambiente. Quem obtém o fonte (repositório, backup, este pacote) obtém credencial do MySQL de produção e a chave do e-mail transacional. A chave SMTP não é referenciada neste pacote, mas a constante está no arquivo que outros scripts incluem.
- Impacto: acesso direto ao banco `suporte` e envio de e-mail em nome do ISP, sem explorar o painel.
- Correção: **não alterei**. O código já prefere `DB_PASS` do ambiente; apagar o fallback derruba o deploy que sobe sem a variável. Apagar ou esvaziar `SMTP_API_KEY` quebra a notificação que inclui `config.php` e espera o valor. Rotação tem de ser fora deste diff (env no servidor, revogar a chave).

## F6 — Senha em MD5 sem salt

- Onde: `code/lib.php:15`; coluna em `code/schema.sql:7`.
- Severidade: **alta**. Confiança: **99**.
- Mecanismo: `autenticar` faz `md5($senha)` e compara com `usuarios.senha CHAR(32)`. Não há salt nem `password_hash`. O seed documenta senhas curtas (`senha123`, `tecmaster`). MD5 é rápido: com dump (o F1 entregava isso) os hashes caem em rainbow table. A consulta em si já era parametrizada; o problema é o algoritmo e o tamanho da coluna.
- Impacto: comprometimento de conta de cliente e de técnico se o banco vazar, e brute force online barato (não há limite de tentativas).
- Correção: **não alterei o algoritmo**. `password_hash` não cabe em `CHAR(32)`. Não há tela de troca de senha para rehash na próxima entrada. Trocar o hash invalida Ana, Bruno, Carla, Diego e a base de produção, e muda o comportamento de `autenticar` de que o faturamento e o painel dependem. Endureci só a falha de `prepare` (retorna `null` em vez de fatal), o que o chamador já trata como login recusado.

## F7 — Sessão fixável e cookie sem HttpOnly/SameSite

- Onde: `code/index.php:15-26` (numeração original: `session_start` na linha 15, gravação de `uid`/`papel` nas linhas 24-25).
- Severidade: **média**. Confiança: **90**.
- Mecanismo: `session_start()` sem opções. O id emitido antes do login continua válido depois que `$_SESSION['uid']` é preenchido — fixação clássica. O cookie segue o default do PHP: sem `HttpOnly`, sem `SameSite`. Combinado com o F3, JavaScript da página lia o id. `use_strict_mode` estava desligado, então um id não inicializado era aceito.
- Impacto: quem planta o cookie da vítima (ou rouba via XSS) reutiliza a sessão já autenticada.
- Correção: `session_start` com `use_strict_mode`, `use_only_cookies`, `cookie_httponly` e `cookie_samesite=Lax`. `cookie_secure` só se a requisição já é HTTPS — forçar Secure na porta 80 (este host escuta 80) impediria o login. `session_regenerate_id(true)` só no login bem-sucedido, antes de gravar `uid`/`papel`. Cabeçalhos `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` e `Referrer-Policy: no-referrer` nas respostas não-CLI. Não forcei timeout novo: o limiter da sessão já manda `Cache-Control: no-store`.

## F8 — Cabeçalho do CSV não é o do contrato

- Onde: `code/lib.php:130`.
- Severidade: **média**. Confiança: **90**.
- Mecanismo: o cabeçalho era `fputcsv(['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'])`. `fputcsv` cerca campo que contém espaço, então a primeira linha sai `ID,Titulo,Status,Tecnico,"Aberto em"`. O manifesto exige a string exata `ID,Titulo,Status,Tecnico,Aberto em`. Quem compara a linha ou faz `explode(',')` recebe aspas no quinto campo. No PHP 8.4.26, omitir `$escape` emite `Deprecated`; com `display_errors` o aviso sai antes do corpo e, no fluxo antigo, antes de `header()`, o que impede `Content-Disposition`.
- Impacto: exportação noturna e integração de faturamento que ancoram no cabeçalho documentado não reconhecem o arquivo; aviso de depreciação corrompe o download.
- Correção: a primeira linha é escrita literalmente, com `\n` (mesmo fim de linha do `fputcsv`). As linhas de dados continuam em `fputcsv` com separador `,`, aspas e escape `\`, para título com vírgula ou aspas permanecer no formato que um parser CSV já consumia. `$escape` vai explícito, sem depreciação. Rótulo de status continua saindo de `formatarStatus`. Ordem `ORDER BY c.id` (equivalente ao `ORDER BY id` da consulta de uma tabela só; o qualificador evita ambiguidade com `usuarios.id` depois do JOIN).

## F9 — Divisão por zero na média de SLA

- Onde: `code/lib.php:116`.
- Severidade: **média**. Confiança: **98**.
- Mecanismo: `mediaResposta` soma `minutos_resposta` não nulos e retorna `$soma / $qtd`. Se não há nenhuma 1ª resposta, `$qtd` é 0. No PHP 8 isso é `DivisionByZeroError` (confirmado neste 8.4.26), não um warning. `index.php` chama a função antes de qualquer HTML da listagem, então a página inteira morre. O seed atual tem 12, 40 e 25, então não dispara; um painel só com chamados sem resposta (ou tabela vazia) dispara. A média global do seed é 77/3; `round` na view continua 26.
- Impacto: indisponibilidade da listagem num estado de dados válido.
- Correção: `$qtd === 0` retorna `0.0`. O laço e o `(int)` por linha foram mantidos para o float coincidir com o cálculo antigo quando há dados (não troquei por `AVG()` do MySQL, que devolve `DECIMAL` e mudaria o valor). Falha de query também retorna `0.0` em vez de chamar método em `false`. A média **não** foi filtrada por cliente: é o KPI da função pública; ver Decisões.

## F10 — Tratamento de conexão morto no PHP 8

- Onde: `code/index.php:9-12`.
- Severidade: **média**. Confiança: **94**.
- Mecanismo: a partir do PHP 8.1 o construtor `mysqli` lança `mysqli_sql_exception` por default. O `if ($db->connect_errno) die('Falha ao conectar ao banco.')` não roda. A exceção inclui o usuário e o host (`Access denied for user ...@...`). Verificado neste PHP 8.4.26: sem `mysqli_report(MYSQLI_REPORT_OFF)` o `new mysqli` com credencial inválida estoura antes do `errno`.
- Impacto: em falha de banco o painel mostra stack/mensagem interna em vez do texto previsto; o nome do usuário SQL vaza se `display_errors` estiver ligado.
- Correção: `mysqli_report(MYSQLI_REPORT_OFF)` só no `index.php`, antes do construtor, para o `connect_errno` voltar a valer e as checagens `=== false` da lib funcionarem no request web. Mensagem do `die` preservada. Status HTTP 500 nesse caminho, fora do CLI. Não liguei o report mode dentro de `lib.php`: script interno que depende da exceção default do PHP 8 continua podendo recebê-la.

## F11 — N+1 ao resolver o técnico

- Onde: `code/lib.php:89` chamando `code/lib.php:69`.
- Severidade: **média**. Confiança: **96**.
- Mecanismo: cada linha de `listarChamados` chama `tecnicoNome`, que faz `SELECT nome FROM usuarios WHERE id = ...`. O export repete o padrão. N chamados geram 1+N idas ao banco na listagem e outras 1+N no CSV. O nome é função só de `tecnico_id`, e `usuarios.id` é PK.
- Impacto: latência linear com o volume de chamados em toda abertura do painel e em toda exportação. Com o seed (5 linhas) é pequeno; em produção cresce com a tabela.
- Correção: `LEFT JOIN usuarios` nas duas consultas, `COALESCE(u.nome, '-') AS tecnico_nome`. Dono ausente continua `-`, igual a `tecnicoNome(null)` e a id sem linha. `tecnicoNome` permanece, com a mesma assinatura, para quem já a chama; a query interna virou prepared statement. `SELECT c.*` preserva as colunas que o retorno de `listarChamados` já expunha, com `tecnico_nome` no fim.

## F12 — TypeError com parâmetro array

- Onde: `code/index.php:22` e `code/index.php:52` (busca na linha 72).
- Severidade: **baixa**. Confiança: **88**.
- Mecanismo: no PHP 8, `(int)` sobre array e passar array a parâmetro `string` lançam `TypeError`. `?ver[]=1`, `?busca[]=x` e `login[]=` derrubam o request antes de qualquer HTML. Não é injeção; é queda da página com pedido malformado.
- Impacto: 500 fácil, sem autenticação no caso de `ver`/`busca` só depois do login — o `ver` e a `busca` estão atrás da sessão; o `login[]` está na tela pública.
- Correção: array/objeto em `ver` vira "não encontrado"; `busca` não-string vira string vazia; `login`/`senha` não-string não chamam `autenticar`. `ver=101` e `ver=101abc` continuam no `(int)` de antes (`101`).

## Decisões — o que não mudei

- **Algoritmo MD5 e `schema.sql` / `seed.sql`.** Ver F6. Migrar hash exige coluna maior e um login que regrave a senha. Não há esse fluxo. Alterar o schema muda o que a integração já gravou.
- **Literais de `config.php`.** Ver F5. Prefiro um painel que ainda conecta, com o achado explícito, a um diff que apaga a única senha do deploy legado.
- **`formatarStatus` para status fora de 1 e 2.** O `else` devolve `Resolvido`, e é assim que o 3 chega ao rótulo contratado. Um quarto texto quebraria o relatório gerencial, que casa por "Aberto", "Em atendimento" e "Resolvido".
- **`rotuloPrioridade`.** Limiar `> 30`, prioridade 1–2 sempre "Normal", crítica só com SLA estourado. O manifesto não fixa esses textos, mas outros scripts podem compará-los. Não há evidência de que a árvore esteja errada; mudei zero retornos.
- **`mediaResposta` global.** A função pública é o indicador de SLA, não a lista de chamados. Filtrá-la na sessão de cliente mudaria o número que o relatório gerencial obtém se rodar dentro de um request com sessão, e faria a média do Bruno (seed sem `minutos_resposta`) divergir do KPI. A regra de visibilidade fala dos chamados, não do agregado.
- **Paginação e `ORDER BY criado_em DESC`.** `listarChamados` precisa continuar devolvendo a lista completa, nessa ordem. Paginar esconderia chamado de quem tem direito.
- **CSRF no login e lockout.** O único POST é o login. Exigir token quebraria cliente que envia só `login` e `senha`. `SameSite=Lax` corta POST cross-site daqui pra frente. Lockout sem store compartilhado trava usuário legítimo e teste automatizado.
- **Injeção de fórmula no CSV (`=`, `+`, `@`).** Prefixar o título alteraria o valor que faturamento e exportação leem. `fputcsv` já cerca vírgula e aspas; não alterei o conteúdo das células.
- **Logout, `strict_types`, namespace, stack.** Logout seria rota nova, fora do contrato. `strict_types` mudaria coerção nas assinaturas públicas. Trocar mysqli está proibido pelo manifesto. Não reescrevi o front controller.
- **Arquivo em `EXPORT_DIR`.** Parei de gravar `chamados.csv` porque esse side effect é o vazamento do F4 e não está no contrato (o contrato é a saída). Quem lia o path fixo depois da chamada precisa passar a ler a saída da função. A constante `EXPORT_DIR` ficou.
- **Revalidar `papel` no banco a cada request.** Evitaria papel obsoleto na sessão, ao custo de uma query e de derrubar a sessão se o usuário sumir. Não há tela de administração neste pacote; deixei o papel gravado no login.
