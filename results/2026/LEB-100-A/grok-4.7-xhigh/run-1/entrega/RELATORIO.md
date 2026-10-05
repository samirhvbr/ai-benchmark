# Relatório técnico — Painel de Chamados (LEB-100-A)

## Resumo

Painel PHP/mysqli de chamados do ISP, em produção desde 2013. A superfície pública (assinaturas, rótulos de status, rotas `busca`/`ver`/`export`, tabela `tabela-chamados`, CSV) está estável e foi preservada. O código, porém, não implementa a regra de visibilidade do manifesto, concatena a busca no SQL, reflete `busca` sem escape e grava o CSV num path fixo sob `/var/www` que neste ambiente nem existe. Senhas continuam em MD5 e há segredo de produção no `config.php`; esses dois pontos não foram “corrigidos” no código porque a correção quebraria o contrato operacional (coluna `CHAR(32)` e constantes já consumidas).

Confirmei o comportamento contra o `seed.sql` em MariaDB 11.8 / PHP 8.4.26: `ana` vê só 101, 102 e 105; `bruno` vê 103 e 104; `carla` vê os cinco; o CSV de cliente segue a mesma regra; o cabeçalho sai exatamente `ID,Titulo,Status,Tecnico,Aberto em`; a média na listagem continua `26` min.

## Achados

### F1 — Cliente vê chamado alheio na lista, no detalhe e no CSV

Onde: `code/index.php:39-73` (contexto carregado e ignorado) e as queries em `code/lib.php:80`, `code/lib.php:100`, `code/lib.php:132`.

Mecanismo: depois do login, `index.php` grava `$uid` e `$papel` e não os consulta. `listarChamados` faz `SELECT * FROM chamados` (com `LIKE` opcional no título) e devolve todas as linhas. `verChamado` filtra só por `id`. `exportarCsv` faz `SELECT * FROM chamados ORDER BY id` e escreve o arquivo inteiro. Não há outro predicado em `usuario_id`. Com a sessão de `ana` (cliente, id 1), `index.php?ver=103` renderiza a descrição do chamado do `bruno` (“Deseja upgrade para 500MB.”); a tabela e o CSV trazem também 104 (“Fatura em duplicidade”). O manifesto exige o contrário: cliente só vê o que abriu; técnico vê qualquer um.

Impacto: vazamento de chamados de outros clientes (título, descrição, técnico, SLA) para qualquer conta `cliente`. Severidade **critica**.

Confiança: **99**.

O que fiz: a restrição vale quando há sessão ativa com `uid` e papel diferente de `tecnico` (`sessaoClienteId`). Sem sessão — caso da rotina noturna que chama `exportarCsv` / `listarChamados` direto — não há filtro, para não esconder chamado de quem tem direito no batch. Técnico continua vendo tudo. Cliente é filtrado na query (`usuario_id = ?`) e de novo em `index.php` antes de pintar a tabela ou o detalhe. Detalhe alheio devolve o mesmo texto já usado para id inexistente (`Chamado nao encontrado.`), sem dizer que o registro existe. CSV de cliente sai ordenado por `id`, com os mesmos rótulos. `Cache-Control: no-store, private` evita que um proxy compartilhe a listagem personalizada.

### F2 — Injeção SQL na busca por título

Onde: `code/lib.php:82`.

Mecanismo: `index.php:72` passa `$_GET['busca']` a `listarChamados`, que concatena o valor em `WHERE titulo LIKE '%" . $busca . "%'`. A aspas fecha a string. Um cliente autenticado manda `busca=' OR 1=1 -- ` e derruba o filtro; com `UNION SELECT` de 9 colunas (o `SELECT *` de `chamados`) lê `usuarios.senha`. O `autenticar` já usa prepared statement; esta query não. Filtrar o array em PHP depois da query não fecha o buraco: o payload escolhe as colunas que voltam.

Impacto: leitura da tabela de usuários (hashes MD5 quebráveis) e de todos os chamados, contornando a regra de visibilidade. Severidade **critica**.

Confiança: **98**.

O que fiz: `titulo LIKE ? ESCAPE '!'`, com `%` e `_` escapados. Curinga literal deixa de significar “tudo”. `verChamado` e `tecnicoNome` também passaram a prepared statement. O `id` desses dois já era `int` (não era injeção explorável); a mudança é o mesmo caminho, não um achado separado.

### F3 — XSS refletido em `busca`

Onde: `code/index.php:79` e `code/index.php:82`.

Mecanismo: o termo entra cru no atributo `value="..."` e no texto `Resultados para:`. Título, descrição e nome do técnico já passam por `htmlspecialchars`; a busca não. `?busca=" onfocus=alert(1) autofocus="` quebra o atributo. O cookie de sessão nasce sem `HttpOnly` (F5), então o script lê `PHPSESSID`.

Impacto: sessão de quem abre o link enquanto está logado. Severidade **alta**.

Confiança: **99**.

O que fiz: `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. Termo ASCII (o caso do contrato) sai idêntico.

### F4 — Export CSV grava arquivo fixo sob `/var/www` e falha em silêncio

Onde: `code/lib.php:125-150`.

Mecanismo: o destino é `EXPORT_DIR . '/chamados.csv'`, isto é `/var/www/painel/tmp/chamados.csv`. Neste host o diretório não existe: `fopen` devolve `false` e a função `return` sem cabeçalho e sem corpo — `index.php?export=csv` entrega página vazia. Se o diretório for criado, o arquivo fica num path previsível dentro de `/var/www`, com o umask do processo (em geral legível por outros usuários) e o mesmo nome para todo mundo. Dois downloads ao mesmo tempo se atropelam: o `readfile` do cliente pode ler o CSV completo que o técnico acabou de gravar. No meio do caminho, query falsa faz `return` sem `fclose` (linhas 133-134).

Impacto: export contratado quebrado; quando o path existe, vazamento do CSV inteiro e condição de corrida entre sessões. Severidade **alta**.

Confiança: **96**.

O que fiz: a query roda antes de qualquer byte, o corpo vai para `php://output` (o contrato é “escreve o CSV na saída”, não “deixa arquivo no docroot”). Sem arquivo compartilhado, a corrida acaba. `EXPORT_DIR` permanece em `config.php` para script que leia a constante. Falha de prepare/execute devolve HTTP 500 em vez de 200 vazio.

### F5 — Sessão fixável e cookie sem HttpOnly

Onde: `code/index.php:15-26`.

Mecanismo: `session_start()` aceita o id que o cliente apresentar (`session.use_strict_mode=0` neste PHP). O login grava `uid` e `papel` nesse mesmo id, sem `session_regenerate_id`. `session.cookie_httponly` está vazio, `cookie_secure` é 0, SameSite não é definido. Fixation: o atacante escolhe o id, a vítima autentica, o id continua válido. Combinado com F3, o JavaScript lê o cookie.

Impacto: sequestro de sessão de técnico ou cliente. Severidade **alta**.

Confiança: **93**.

O que fiz: antes do `session_start`, `cookie_httponly=1`, `SameSite=Lax`, `use_strict_mode=1`, `use_only_cookies=1`, e `cookie_secure` só se `HTTPS` estiver on (forçar Secure em HTTP puro, que é como este serviço escuta na porta 80, faria o browser descartar o cookie e o login entrar em loop). No sucesso, `session_regenerate_id(true)` e a sessão nova só guarda `uid` e `papel`. O formulário de login não mudou.

### F6 — Senha de banco e chave SMTP no fonte

Onde: `code/config.php:12-15`.

Mecanismo: `DB_PASS` cai em `N3tX@2013!prod` quando a variável de ambiente não existe. `SMTP_API_KEY` é a literal `netx-smtp-9f83e2c1a7b64d05`, sem `getenv`. Quem lê o repositório, um backup ou o arquivo no servidor obtém a senha do usuário `painel` e a chave da central de e-mail. O `.php` não vaza via HTTP se o interpretador estiver ativo, mas o segredo já está versionado.

Impacto: acesso ao banco de produção e envio de e-mail transacional em nome do ISP. Severidade **alta**.

Confiança: **98**.

O que fiz: nada no arquivo. Zerar o fallback de `DB_PASS` derruba o painel em todo deploy que depende desse default (o próprio comentário do arquivo o descreve como senha de produção). Apagar ou rotacionar `SMTP_API_KEY` quebra o script de notificação que faz `require` de `config.php` e lê a constante — ela não é usada neste pacote, mas o comentário e o manifesto apontam consumidores externos. Rotação é operação de deploy, não patch cego.

### F7 — Senha armazenada como MD5 sem sal

Onde: `code/lib.php:15` e `code/schema.sql:7`.

Mecanismo: `autenticar` calcula `md5($senha)` e compara com `usuarios.senha CHAR(32)`. O seed grava `MD5('senha123')` e `MD5('tecmaster')`. Não há sal. `password_hash` (bcrypt, 60 caracteres) não cabe na coluna. Um `ALTER` em `schema.sql` não migra o banco que já está no ar, e um script de faturamento que insere `MD5(...)` nessa coluna passaria a gravar lixo.

Impacto: vazada a tabela (F2, backup, F6), as senhas caem em rainbow table. Online, o hash é barato de testar (F12). Severidade **alta**.

Confiança: **99**.

O que fiz: não troquei o algoritmo. Login de `ana`/`senha123` e `carla`/`tecmaster` continua válido. Migrar exige coluna nova, janela de rehash e acordo com quem escreve `usuarios` — fora do que este pacote pode fazer sem quebrar assinatura e dados.

### F8 — `mediaResposta` divide por zero e derruba a listagem

Onde: `code/lib.php:116`, chamada em `code/index.php:74`.

Mecanismo: a função soma `minutos_resposta` não nulos e retorna `$soma / $qtd`. Com zero linhas, `$qtd` é 0. No PHP 8.4 isso é `DivisionByZeroError`, não um warning com `INF`. A listagem chama a função sempre, antes de pintar a tabela. Banco só com chamados ainda sem primeira resposta — estado normal de um painel novo, não caso de teste — devolve 500 na rota principal. O seed esconde o bug: 101, 102 e 105 têm minutos, e `(12+40+25)/3` arredonda para os `26` min que o HTML mostra.

Impacto: indisponibilidade da listagem. Severidade **alta**.

Confiança: **97**.

O que fiz: se a query falha ou `$qtd === 0`, retorno `0.0`. O ramo com dados não mudou (mesma soma em PHP, mesmo float). A média continua global: o relatório gerencial chama `mediaResposta($db)` e o manifesto não a restringe por usuário. Filtrá-la por sessão faria o mesmo número mudar conforme quem está logado.

### F9 — Cabeçalho do CSV não é o texto exato do contrato

Onde: `code/lib.php:130`.

Mecanismo: `fputcsv` cita campo com espaço. Neste PHP 8.4 a linha sai `ID,Titulo,Status,Tecnico,"Aberto em"`. O manifesto pede o cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em`. Split ingênuo por vírgula deixa aspas no nome da quinta coluna; comparação de string falha. No mesmo call, omitir `$escape` emite `Deprecated` quando `E_DEPRECATED` está ligado. Se `display_errors` estiver on, o aviso começa a saída antes dos `header()` das linhas 148-149 e o download deixa de ser CSV.

Impacto: rotina que confere o cabeçalho byte a byte, ou Excel/script que não tira aspas, não reconhece a coluna. Severidade **media**.

Confiança: **92**.

O que fiz: a primeira linha é `fwrite` do texto exato, com `\n` (o mesmo fim de linha do `fputcsv`). As linhas de dados continuam em `fputcsv` com escape explícito `'\\'`, para vírgula em título não quebrar coluna e para o aviso sumir sem mudar o quoting histórico das linhas. `Em atendimento` segue citado, como já saía.

### F10 — N+1 na listagem e no export

Onde: `code/lib.php:88-89` e a chamada irmã em `code/lib.php:137`.

Mecanismo: para cada chamado, `tecnicoNome` executa `SELECT nome FROM usuarios WHERE id = ...`. São 1+N idas ao banco. O nome só depende de `tecnico_id`, que já está na linha. Com o volume do seed não se nota; com a tabela crescendo, a listagem e o CSV escalam com o número de chamados, não com um join.

Impacto: latência e carga no MySQL na rota quente. Severidade **media**.

Confiança: **96**.

O que fiz: `LEFT JOIN usuarios` e `COALESCE(u.nome, '-')`. O `-` é o que `tecnicoNome` devolvia para `tecnico_id` NULL (chamado 104). A chave `tecnico_nome` continua no array, na mesma posição lógica (depois das colunas de `chamados`). `tecnicoNome` ficou no arquivo — não está no manifesto, mas outro script pode chamá-la — e a query interna agora é preparada. Prepared statement devolve inteiro nativo no mysqlnd; `query()` devolvia string. `linhaComoQuery` regrava `int`/`float` como string para o array de `listarChamados` e `verChamado` não mudar de tipo em relação ao `fetch_assoc` antigo.

### F11 — Falha de conexão escapa do `die` previsto

Onde: `code/index.php:9-12`.

Mecanismo: o código trata `connect_errno` e morre com `Falha ao conectar ao banco.` Neste PHP 8.4.26, `new mysqli` com host recusado lança `mysqli_sql_exception` antes dessa linha. O `die` não roda. Com `display_errors` off o cliente vê 500 vazio; com on, vê stack e a mensagem do driver (usuário e host). O handler que o arquivo escreveu está morto na versão em que o processo roda.

Impacto: mensagem operacional perdida e, se o display de erro estiver ligado, vazamento de detalhe de conexão. Severidade **media**.

Confiança: **95**.

O que fiz: `try/catch` de `mysqli_sql_exception` e, no modo antigo em que o construtor não lança, o `connect_errno` original. Os dois caminhos respondem 500 com o mesmo texto. O `@` no construtor só abafa o warning do modo não-estrito; não engole exceção.

### F12 — Login sem limite de tentativa

Onde: `code/index.php:21-22`.

Mecanismo: cada POST chama `autenticar`. A comparação é `md5` no MySQL, barata. Não há contador, atraso nem bloqueio. Dá para martelar `ana` na mesma conexão até acertar `senha123` ou outra senha fraca. A função não distingue usuário inexistente de senha errada (a query exige os dois), e isso eu mantive.

Impacto: adivinhação online de senha. Severidade **media**.

Confiança: **88**.

O que fiz: 15 falhas na mesma sessão, janela de 15 minutos; sucesso zera o contador junto com a sessão regenerada. Não há mensagem nova no HTML (não abre oráculo de lockout). Residual: sessão nova zera o contador. Limite compartilhado exigiria tabela; não alterei `schema.sql` por isso.

### F13 — Fórmula no CSV

Onde: `code/lib.php:138-144`.

Mecanismo: `fputcsv` grava `titulo` e o nome do técnico como estão no banco. Célula que começa com `=`, `+`, `@`, tab ou quebra de linha vira fórmula quando alguém abre `chamados.csv` no Excel. Este `index.php` não insere chamado, mas `titulo` é texto de cliente (o seed já tem “Fatura em duplicidade”). O status sai de `formatarStatus`, não do usuário.

Impacto: execução de fórmula na máquina de quem baixa o export. Severidade **media**.

Confiança: **80**.

O que fiz: prefixo `'` só nesses prefixos, em título e técnico. O `-` do técnico vazio não é tocado — senão a coluna Tecnico do chamado 104 mudaria. Títulos do seed não começam com esses caracteres; o CSV do seed permanece o mesmo nas células.

### F14 — `busca[]` ou `login[]` derrubam o processo

Onde: `code/index.php:22` e `code/index.php:72`.

Mecanismo: `?busca[]=x` faz `$_GET['busca']` ser array. `listarChamados` declara `string`. No PHP 8 isso é `TypeError` não capturado, 500 na listagem. POST com `login` array quebra do mesmo jeito em `autenticar`. `(int)` de array em `ver[]` não lança neste PHP (vira 1), mas não é um id enviado pelo usuário.

Impacto: um request malformado tira a página do ar. Severidade **baixa**.

Confiança: **96**.

O que fiz: busca que não é string vira `''`; `ver` não escalar vira “não encontrado”; login que não é string não chama `autenticar`. `export` só dispara se for a string `csv`.

## Decisões

Não reescrevi o painel, não troquei mysqli, não renomeei arquivo e não acrescentei dependência. As assinaturas do manifesto estão iguais, inclusive o default `string $busca = ''`.

Não alterei `formatarStatus`. O `else` devolve `Resolvido` para qualquer status que não seja 1 ou 2, inclusive lixo. O contrato só fixa 1, 2 e 3, e o relatório gerencial casa texto. Mudar o `else` alteraria saída que algum consumidor já trata como resolvido.

Não alterei `rotuloPrioridade`. Prioridade 4 dentro do SLA cai em `Alto - dentro do SLA`, e prioridade 1/2 nunca marca atraso. Pode ser assimetria, pode ser a regra (SLA de 30 min só acima de 3). Os literais não estão no manifesto, mas são contrato implícito de quem já compara essas strings. Sem spec, não mexi.

Não exigi token CSRF no login. Os consumidores autenticam com POST `login` e `senha`. Rejeitar POST sem token quebraria isso. O cookie agora é `SameSite=Lax`, o que já corta o POST cross-site clássico.

Não criei rota de logout nem link “Sair”. Não está no manifesto; um link novo muda o HTML da listagem sem pedido. Sessão regenerada no login e cookie `HttpOnly` cobrem o risco que eu consegui fechar sem superfície nova.

Não filtrei `mediaResposta` por cliente. A função não recebe usuário, e o relatório gerencial depende do número global. A regra de visibilidade fala de chamado, não do indicador agregado.

Não paginei a listagem e não mudei `ORDER BY criado_em DESC`. Limite esconderia chamado que o técnico tem direito de ver. Empate de `criado_em` continua estável como estava (sem desempate por id).

Não reli o papel no banco a cada request. Sessão adulterada no servidor já é comprometimento do host; a ida extra derruba todo mundo se o `SELECT` falhar. O papel usado na autorização é o gravado no login, vindo de `usuarios.papel`.

Não removi `X-Powered-By`. É do `php.ini` do host, não deste aplicativo.

O detalhe inexistente ou proibido continua HTTP 200 com `Chamado nao encontrado.` Status 404 mudaria o que um monitor que só olha o corpo já espera.

`tecnicoNome` continua exportada. A listagem não depende mais dela, mas um script externo pode.
