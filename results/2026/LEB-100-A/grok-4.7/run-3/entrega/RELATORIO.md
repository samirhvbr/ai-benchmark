# Relatório — Painel de Chamados (LEB-100-A)

## Resumo

O painel lista, detalha e exporta chamados sobre mysqli. As assinaturas públicas e o HTML contratado da tabela estão intactos. O código que chegou mistura três falhas que se reforçam: a busca concatena SQL, o papel do usuário é lido e ignorado, e o CSV de todos os clientes era gravado num caminho fixo sob `/var/www`. No PHP 8.4 a média de SLA derruba a listagem quando não há primeira resposta, e `fputcsv` sem o argumento de escape avisa no meio da saída. Corrigi o que quebra o contrato ou vaza dado de cliente. Não migrei o MD5: a coluna `senha` é `CHAR(32)` e um `password_hash` truncado trancaria o login.

As linhas citadas são as do código original, como pede o enunciado.

## F1 — Injeção de SQL na busca

Onde: `code/lib.php:82-83`, alimentado por `code/index.php:72`.

`listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` e manda a string para `$db->query`. `$busca` é `$_GET['busca']`, sem filtro. Um cliente logado envia `busca=' OR '1'='1` e a condição vira tautologia; com `UNION` lê `usuarios.senha` (MD5) ou usa subconsulta para extração cega. O mysqlnd não executa stacked queries, mas não precisa: uma consulta já devolve o banco. `verChamado` (linha 100) e `tecnicoNome` (linha 69) também concatenam, porém o parâmetro é `int` e o PHP 8 rejeita string não numérica — não são exploráveis hoje.

Severidade: crítica. Confiança: 98.

Correção: `LIKE ?` com `bind_param` e `ESCAPE '\\'`. `%` e `_` do termo viram literal, porque a função já coloca os curingas nas pontas; o contrato é busca por título, não padrão informado pelo cliente. `verChamado` e `tecnicoNome` passaram a prepared statement no mesmo movimento.

## F2 — Cliente vê chamado dos outros

Onde: `code/index.php:39-73`. O papel é gravado na linha 39 e não entra em nenhuma decisão. A listagem chama `listarChamados` sem `usuario_id`. `ver=<id>` chama `verChamado`, que faz `SELECT * FROM chamados WHERE id = ...` sem dono. `export=csv` exporta a tabela inteira.

Ana (`id` 1) recebe os chamados 103 e 104 de Bruno, inclusive a descrição de fatura duplicada, pelo HTML, pelo `?ver=104` e pelo CSV. O schema tem `usuario_id` e `idx_usuario`; o painel não usa nenhum dos dois. Técnico e cliente caem no mesmo caminho.

Severidade: crítica. Confiança: 99.

Correção: a cada request o papel é relido em `usuarios` pelo `uid` da sessão, não pelo valor deixado no login. Quem não é `tecnico` só lista e exporta `usuario_id = uid`. Detalhe de chamado alheio devolve o mesmo texto já existente, `Chamado nao encontrado.`, para não confirmar que o id existe. `listarChamados`, `verChamado` e `exportarCsv` continuam devolvendo o conjunto completo: a rotina noturna e o relatório gerencial chamam essas funções direto e o manifesto não lhes passa o usuário. O filtro do produto web ficou em `index.php` (`buscarChamados` / `emitirCsv`).

## F3 — CSV em arquivo fixo no document root

Onde: `code/lib.php:125-150`. `EXPORT_DIR` é `/var/www/painel/tmp`. `exportarCsv` abre `chamados.csv` em modo `w`, escreve, manda os headers e faz `readfile`.

Se o diretório não existe, `fopen` falha e a função retorna sem corpo: a rota `index.php?export=csv` do contrato fica em branco. Se existe e o servidor entrega estático, a URL previsível baixa o CSV de todos os clientes sem sessão. Duas requisições truncam o mesmo arquivo; `readfile` pode ler a escrita do outro. Se a query falha depois do `fopen`, o handle não fecha e o parcial fica no disco.

Severidade: alta. Confiança: 90. O caminho sob `/var/www` está no código; o servidor entregar esse diretório como estático é o desenho do path, não algo que este pacote prova sozinho. A falha silenciosa quando o diretório falta é certa.

Correção: a consulta roda antes dos headers; o CSV vai para `php://output`. Não há arquivo compartilhado. `Content-Type` e `Content-Disposition: attachment; filename="chamados.csv"` foram mantidos. A constante `EXPORT_DIR` ficou, para script externo que a leia; `exportarCsv` não grava mais lá.

## F4 — Cabeçalho do CSV e aviso do `fputcsv` no PHP 8.4

Onde: `code/lib.php:130` e a segunda chamada na linha 138.

O manifesto exige a linha exata `ID,Titulo,Status,Tecnico,Aberto em`. `fputcsv` nesta versão cita campo com espaço, então a primeira linha sai `ID,Titulo,Status,Tecnico,"Aberto em"`. Além disso, omitir `$escape` emite `Deprecated` no PHP 8.4.26 (o default vai mudar). Com `display_errors=On`, o aviso cai em stdout antes das linhas. A rotina noturna que captura a saída de `exportarCsv` grava lixo para o faturamento. Confirmei os dois comportamentos neste interpretador.

Severidade: alta. Confiança: 94.

Correção: o cabeçalho é `fwrite` da string exata, com `\n` (o mesmo fim de linha que o `fputcsv` usa aqui). As linhas de dados continuam em `fputcsv`, com escape `\\` e eol `\n` explícitos, para o quoting de vírgula e aspas não mudar quando o default do PHP mudar. Status segue `formatarStatus`. Ordem segue `id` crescente.

## F5 — XSS refletido na busca

Onde: `code/index.php:79` e `82`.

`$busca` entra cru no atributo `value` e no parágrafo `Resultados para`. Título, descrição e nome do técnico passam por `htmlspecialchars`; a busca não. `?busca="><script>...` ou aspas no atributo executam no navegador de quem abrir o link. O `php.ini` deste host tem `session.cookie_httponly=Off`, então o script também lê `PHPSESSID`.

Severidade: alta. Confiança: 97.

Correção: `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos. `busca` que não é string (por exemplo `busca[]=`) vira vazio, em vez de estourar o type hint. O HTML da tabela, o `id="tabela-chamados"` e a ordem das colunas não mudaram. Cabeçalhos `Content-Security-Policy` (sem script), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` e cookie HttpOnly (F10) limitam o que um reflexo residual conseguiria.

## F6 — Senha de produção e chave SMTP no fonte

Onde: `code/config.php:12` e `15`.

`DB_PASS` usa `getenv('DB_PASS')` e, se a variável falta, o literal `N3tX@2013!prod`. O comentário diz que esse é o fallback de produção. `SMTP_API_KEY` é a string `netx-smtp-9f83e2c1a7b64d05`, sem leitura de ambiente, e nenhum arquivo deste pacote a usa. `config.php` fica ao lado de `index.php`. Cópia do repositório, backup ou vhost que sirva PHP como texto entrega a senha do MySQL e a chave de e-mail.

Severidade: alta. Confiança: 97.

Correção: os dois defines só existem se o ambiente os trouxer; ausente vira string vazia e a conexão falha com a mensagem genérica já existente. Host, nome do banco e usuário padrão ficaram — não são o segredo, e tirá-los quebraria o deploy que já aponta para `suporte`/`painel`. A senha que já vazou no fonte tem de ser rotacionada no MySQL. Isso é operação, não dá para fazer daqui.

## F7 — Senha armazenada como MD5 sem sal

Onde: `code/lib.php:15-17`. Coluna `usuarios.senha CHAR(32)` em `code/schema.sql:7`. Seed com `MD5('senha123')` e `MD5('tecmaster')`.

`autenticar` calcula `md5($senha)` e compara na query. Não há sal. Os dois segredos do manifesto são pré-imagem pública; um dump (F1, ou o próprio `seed.sql` se o document root servir `.sql`) vira login na hora. Não há bloqueio de tentativa.

Severidade: alta. Confiança: 99.

Não corrigi o algoritmo. `password_hash` não cabe em `CHAR(32)`. Um `UPDATE` no login truncaria o hash (ou falharia em strict mode) e Ana, Bruno, Carla e Diego deixariam de entrar. `autenticar` precisa continuar aceitando as senhas do seed. Não criei tabela de tentativas: o manifesto não prevê esse esquema, e um contador só na sessão se ignora não enviando cookie.

## F8 — Divisão por zero na média de SLA

Onde: `code/lib.php:116`, chamada em `code/index.php:74` antes de qualquer linha da tabela.

O laço só incrementa `$qtd` para linhas com `minutos_resposta` não nulo. Tabela vazia, ou todos os chamados ainda sem primeira resposta, deixa `$qtd` em 0. No PHP 8.4 `$soma / $qtd` lança `DivisionByZeroError` — confirmei neste binário. A listagem inteira, inclusive a do técnico, vira 500. Com o seed (12, 40 e 25) a página sobe; o bug aparece no primeiro dia sem resposta medida.

Severidade: alta. Confiança: 96.

Correção: `SUM` e `COUNT` numa query. `COUNT` da coluna ignora NULL, como o `WHERE` antigo. Se `qtd` é 0, retorna `0.0`. Com dados, a divisão dos inteiros em PHP é a mesma do laço (`77/3`). `round($media)` na listagem não muda para o seed.

## F9 — Uma query por técnico na listagem e no CSV

Onde: `code/lib.php:89-90` e, no export, a linha 137. A query unitária está em `tecnicoNome`, linha 69.

Cada chamado dispara `SELECT nome FROM usuarios WHERE id = ...`. N chamados são 1+N idas ao banco na listagem e outras 1+N no CSV. O índice da PK evita varredura, não o round-trip. Com o volume de um ISP isso segura a página e a exportação noturna.

Severidade: média. Confiança: 95.

Correção: `LEFT JOIN usuarios` e `COALESCE(u.nome, '-') AS tecnico_nome`. Técnico nulo ou id órfão continua `-`, como o `?:` antigo. `listarChamados` ainda devolve `c.*` mais `tecnico_nome` (a chave extra do contrato) e ordena por `criado_em DESC`. `tecnicoNome` permanece, com prepared statement, porque relatório interno pode chamá-la.

## F10 — Sessão fixável e cookie sem HttpOnly

Onde: `code/index.php:15` (`session_start`) e `24-26` (grava `uid` sem trocar o id).

O `php.ini` deste host tem `session.cookie_httponly=Off`, `session.cookie_samesite` vazio e `session.use_strict_mode=Off`. `use_only_cookies` já está On, então fixação por URL não funciona; fixação por cookie plantado funciona. Quem coloca `PHPSESSID` no navegador da vítima antes do POST continua na sessão depois do login, porque o id não é regenerado. Sem HttpOnly, o XSS de F5 lê o cookie. Sem SameSite, um site externo consegue fazer POST de login.

Severidade: média. Confiança: 86. O código não regenera o id — isso é fato. Explorar a fixação ainda exige plantar o cookie (HTTP sem TLS, ou o XSS).

Correção: HttpOnly, SameSite=Lax, Secure só quando `HTTPS` ou porta 443, `use_strict_mode` e `use_only_cookies`, `session_regenerate_id(true)` no login que deu certo. Path e domain do cookie ficaram os do padrão, para não alargar o escopo num host com mais de um sistema. POST de login continua só `login` e `senha`.

## F11 — Falha de conexão vaza o usuário do banco

Onde: `code/index.php:9-11`.

No PHP 8.1+ o mysqli nasce em `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`. `new mysqli()` com senha recusada lança `mysqli_sql_exception` antes do `if ($db->connect_errno)`. Reproduzi: a mensagem é `Access denied for user 'painel'@'localhost'`. O `die('Falha ao conectar ao banco.')` não roda. Com `display_errors` ligado, o cliente vê o usuário do banco. O mesmo modo torna morto o `$res === false` de `exportarCsv`: query que falha vira exceção, e a mensagem inclui o SQL.

Severidade: média. Confiança: 95.

Correção: a conexão é capturada e responde com o texto original. `display_errors` fica desligado no painel (o `die` continua visível). Exceção mysqli no resto do request vira `Falha ao consultar o banco.`, sem o SQL. Quem faz `require` de `lib.php` num script CLI não herda o handler.

## F12 — Status desconhecido rotulado como Resolvido

Onde: `code/lib.php:32-34`.

`formatarStatus` devolve Aberto para 1, Em atendimento para 2, e o `else` devolve Resolvido para todo o resto. O 3 acerta por cair no `else`. A coluna é `TINYINT` sem `CHECK`. Um 0, 4 ou lixo gravado por outro sistema entra no CSV e no relatório gerencial como Resolvido. O relatório casa pelo texto, então o chamado some da fila de abertos.

Severidade: baixa. Confiança: 82. O ramo é esse; este pacote não grava status fora de 1–3, então o impacto depende de dado sujo que não está no seed.

Correção: 1, 2 e 3 mantêm os rótulos do contrato. Qualquer outro inteiro devolve `Desconhecido`, que não casa com Resolvido. `rotuloPrioridade` não foi mexida.

## Decisões

Não migrei MD5 nem alterei `schema.sql` / `seed.sql`. Ver F7. Trocar o hash sem alargar a coluna derruba o login que `autenticar` tem de continuar fazendo.

Não filtrei dentro de `listarChamados`, `verChamado` nem `exportarCsv`. Essas funções não recebem o usuário, e a rotina noturna e o relatório gerencial dependem do conjunto completo. Esconder chamado de quem chama a função direto violaria o contrato da assinatura. A regra de visibilidade do produto está no `index.php`, que é por onde o cliente vê dado.

Não mudei `rotuloPrioridade`. Os rótulos não estão no manifesto, mas a função é pública e o relatório pode casar `CRITICO - SLA estourado` e os outros textos. Prioridade 4 com `minutos_resposta` nulo continua `Aguardando 1a resposta`. Sem spec que diga o contrário, mudar isso é chute.

`mediaResposta` continua a média global. A assinatura não recebe usuário, e o texto do painel é o indicador de SLA do ISP, não a média do cliente logado. Um agregado não identifica chamado alheio.

Não paginei a tabela. O HTML contratado lista os chamados visíveis; uma página esconderia linha de quem tem direito. Não criei `FULLTEXT`: a busca é substring (`LIKE '%termo%'`), e fulltext mudaria stopword e tamanho mínimo de palavra.

Não pus token CSRF no formulário de login. SameSite=Lax já recusa POST cross-site, e um token quebraria integração que envia só `login` e `senha`. Não acrescentei link de Sair: a estrutura HTML declarada é a tabela, e não quis inserir controle novo na página.

Não prefixei célula de CSV contra formula injection. O faturamento consome o título literal. Este código não insere chamado; o caminho de escrita não está aqui.

Não mudei o valor de `EXPORT_DIR`. Script externo pode usar a constante. Parei de gravar `chamados.csv` nesse path (F3), que era o vazamento.

Não forcei cookie `Secure` em HTTP. O painel legado atende sem TLS; `Secure` incondicional impediria o login. `Secure` só entra com HTTPS ou porta 443.

Não troquei mysqli, não renomeei arquivo, não reescrevi em camada ou framework. `buscarChamados` e `emitirCsv` são apoio interno; as sete assinaturas do manifesto continuam com o mesmo nome, parâmetros e formato de retorno.
