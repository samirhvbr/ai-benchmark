# Relatório técnico — LEB-100-A

## Resumo

Painel PHP/mysqli de chamados do ISP. O contrato público (assinaturas, rótulos de status, rotas, CSV, tabela HTML) continua válido. O código em produção, porém, concatenava a busca no SQL, não aplicava a regra de visibilidade cliente/técnico, refletia `busca` sem escape, gravava o CSV num arquivo fixo sob `/var/www` e derrubava a listagem quando não havia tempo de resposta. Corrigi esses pontos no lugar, sem trocar a stack nem as assinaturas. Senha MD5 e segredos de `config.php` ficaram de pé de propósito: mexer neles aqui derruba login ou o banco sem uma rotação coordenada.

## F1 — Injeção SQL na busca de chamados

- Onde: `code/lib.php:82`–`83`, disparada por `code/index.php:72`–`73` (`$_GET['busca']`).
- Mecanismo: `listarChamados` monta `SELECT * FROM chamados WHERE titulo LIKE '%` + `$busca` + `%'`. O valor não passa por `prepare`. Um cliente autenticado manda `busca=%' OR '1'='1` ou um `UNION` e a consulta deixa de ser um filtro de título. O usuário do banco é o da aplicação (`painel`), então a injeção lê `usuarios` (hashes MD5 inclusive) e o restante do schema que esse usuário alcança. `%` e `_` também viram curinga do `LIKE`, então uma busca por `%` devolve todos os títulos.
- Impacto: vazamento e, se a conta tiver DML, alteração da base, a partir de qualquer sessão de cliente. Severidade **crítica**. Confiança **99**.
- O que fiz: `prepare` + `bind_param`. O termo entra só como dado. `%`, `_` e o escape `!` são escapados (`ESCAPE '!'`) para a busca continuar sendo substring literal. Sem busca, o SQL não tem placeholder. Ordem `criado_em DESC` e o formato da linha (colunas do chamado + `tecnico_nome`) foram mantidos.

## F2 — Cliente vê e exporta chamado de outro cliente

- Onde: `code/index.php:38`–`39` (identidade guardada e não usada), rotas em `44`–`46`, `52`–`66` e `72`–`73`.
- Mecanismo: depois do login o script grava `$_SESSION['uid']` e `$_SESSION['papel']`, copia para `$uid`/`$papel` e não consulta nenhum dos dois. `listarChamados` faz `SELECT * FROM chamados` (filtro só de título). `verChamado` carrega qualquer `id`. `exportarCsv` percorre a tabela inteira. Com a sessão de `ana` (cliente, id 1) a listagem inclui 103 e 104, abertos por `bruno`; `index.php?ver=103` devolve título e descrição; `index.php?export=csv` baixa o conjunto completo. O schema define `usuario_id` como quem abriu o chamado. O manifesto exige o contrário: cliente só vê o que abriu; técnico vê tudo.
- Impacto: qualquer conta de cliente lê PII e texto de faturamento dos demais e leva isso no CSV. Severidade **crítica**. Confiança **98**.
- O que fiz, só na borda HTTP: se `papel !== 'tecnico'`, a listagem fica restrita a `usuario_id === uid` (a ordem de `listarChamados` é preservada); no detalhe, chamado de outro dono recebe o mesmo HTML de inexistente (`Chamado nao encontrado.`), sem distinguir “não existe” de “não é seu”; no export, técnico continua chamando `exportarCsv` (todos, `id` crescente) e cliente chama `escreverCsv($db, $uid)`. Não acrescentei parâmetro às assinaturas do manifesto. `listarChamados`, `verChamado` e `exportarCsv` seguem devolvendo o conjunto completo quando chamados pelos scripts internos (exportação noturna, relatório gerencial, faturamento), que não têm sessão de cliente. Esconder chamado de técnico, ou filtrar essas funções sempre, quebraria a outra metade da regra e o contrato dessas assinaturas.

## F3 — XSS refletido em `busca`

- Onde: `code/index.php:79` e `82`.
- Mecanismo: `$busca` entra cru no atributo `value="..."` e no texto `Resultados para:`. Título e descrição passam por `htmlspecialchars`; a busca não. `busca="><script>alert(1)</script>` quebra o atributo e executa no browser de quem abrir o link. A vítima já está autenticada (a rota só existe depois do login). Sem `HttpOnly` no cookie (F6), o script lê `PHPSESSID`.
- Impacto: execução no origem do painel, roubo de sessão ou download do CSV com o privilégio da vítima (técnico inclusive). Severidade **alta**. Confiança **98**.
- O que fiz: `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. A consulta continua recebendo a string crua, via placeholder (F1), não o HTML escapado. Termo sem metacaractere HTML sai idêntico ao de antes.

## F4 — CSV em arquivo fixo, compartilhado e muitas vezes inexistente

- Onde: `code/lib.php:125`–`150`.
- Mecanismo: `exportarCsv` abre `EXPORT_DIR . '/chamados.csv'`, isto é `/var/www/painel/tmp/chamados.csv`. Se o diretório não existe, `fopen` falha, a função retorna e `index.php` dá `exit`: o navegador recebe corpo vazio, sem os headers de download. Se o arquivo existe, todo export concorrente escreve e lê o mesmo path. Um cliente pode `readfile` o dump completo de um técnico no meio da escrita, e o arquivo permanece no disco, sob `/var/www`, com todos os chamados. O manifesto pede que a função escreva o CSV na saída, não nesse path.
- Impacto: export quebrado neste ambiente e vazamento do CSV entre sessões / via arquivo largado no disco. Severidade **alta**. Confiança **96**.
- O que fiz: a consulta roda primeiro; em seguida os mesmos headers (`text/csv; charset=utf-8` e `attachment; filename="chamados.csv"`) e o corpo vai para `php://output`. Não há mais arquivo compartilhado. `EXPORT_DIR` continua definido para quem já lê a constante. `exportarCsv(mysqli $db): void` segue exportando todos os chamados, ordenados por `id` crescente, com `formatarStatus` na coluna Status.

## F5 — `mediaResposta` derruba a listagem

- Onde: `code/lib.php:116`.
- Mecanismo: a função soma `minutos_resposta` em PHP e retorna `$soma / $qtd`. Se a tabela está vazia, ou se todo chamado ainda não teve primeira resposta (`minutos_resposta` NULL, estado normal no schema), `$qtd` fica 0. No PHP 8 isso não é warning: é `DivisionByZeroError` não capturado. `index.php` chama `mediaResposta` antes de emitir o HTML da listagem, então a tela principal morre inteira. Com o seed (12, 40 e 25) a média é 77/3 e a página mostra 26; o bug só aparece fora desse seed.
- Impacto: painel indisponível num estado de dados válido. Severidade **alta**. Confiança **97**.
- O que fiz: uma agregação `SUM`/`COUNT` (COUNT ignora NULL, como o `WHERE` antigo) e `0.0` quando `qtd` é 0. Para os três valores do seed a divisão em float continua igual à soma em PHP, então `round($media)` segue 26. A frase do topo não mudou; sem dados ela mostra `0 min` em vez de 500.

## F6 — Fixação de sessão e cookie sem HttpOnly

- Onde: `code/index.php:15` e `24`–`27`.
- Mecanismo: `session_start()` sem `use_strict_mode`, sem `HttpOnly` e sem `SameSite`. No login bem-sucedido o script grava `uid` e `papel` no id que o browser já apresentou. Quem plantou `PHPSESSID` antes do POST de login fica com a sessão autenticada. O cookie também é legível por script, o que completa o XSS de F3.
- Impacto: sequestro de sessão de cliente ou técnico. Severidade **média** (exige plantar o cookie ou ter XSS). Confiança **93**.
- O que fiz: antes de `session_start`, cookie `HttpOnly`, `SameSite=Lax`, `use_strict_mode` e `use_only_cookies`. `secure` só entra se a requisição já for HTTPS — forçar `secure` no HTTP da porta 80 faria o login “não pegar”. Depois da senha correta, `session_regenerate_id(true)` e só então `uid`/`papel`. O `Location: index.php` e o formulário de login não mudaram. Se a sessão já estiver ativa (script que inclui `index.php` com sessão aberta), não chamo `session_start` de novo.

## F7 — Falha de conexão ignora o `die` e vaza o usuário do banco

- Onde: `code/index.php:9`–`11`.
- Mecanismo: o script confia em `$db->connect_errno`. No PHP 8.1+ o mysqli nasce com `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`, e `new mysqli(...)` com senha errada lança `mysqli_sql_exception` (“Access denied for user 'painel'@'localhost'”) antes do `if`. O `die('Falha ao conectar ao banco.')` não roda. Com `display_errors` ligado, a página mostra usuário e host do banco; com ele desligado, vira 500 mudo em vez da mensagem que o código pretendia.
- Impacto: mensagem de erro controlada não acontece; vazamento do usuário MySQL se o display de erros estiver aberto. Severidade **média**. Confiança **96**.
- O que fiz: `try/catch` em volta do construtor, com o mesmo `die`. A exceção não é impressa. O modo estrito das consultas seguintes permanece: SQL inválido continua falhando alto, não em silêncio.

## F8 — Senha de produção e chave SMTP no fonte

- Onde: `code/config.php:12` e `15`.
- Mecanismo: `DB_PASS` usa `getenv('DB_PASS')`, mas o fallback é a senha literal do usuário `painel`. `SMTP_API_KEY` nem tem override: a chave transacional está só na constante. Quem lê este pacote, um backup ou o repositório obtém os dois, junto com host, nome do banco e usuário default no mesmo arquivo. A chave SMTP não é referenciada por `index.php`/`lib.php`, mas o comentário do arquivo a destina aos scripts de notificação que incluem `config.php`.
- Impacto: acesso ao MySQL da aplicação e envio de e-mail em nome do ISP, sem explorar o painel. Severidade **alta**. Confiança **99**.
- O que fiz: **não alterei**. Apagar o fallback de `DB_PASS` derruba o painel em todo deploy que ainda não injeta a variável — e este arquivo é o único lugar, aqui, onde a senha existe. Apagar `SMTP_API_KEY` apaga a única cópia da chave que os outros scripts leem. Rotacionar exige alterar o usuário no MariaDB e a credencial no provedor de e-mail no mesmo passo; daqui não há como fazer essa rotação sem dessincronizar o código do que está em produção. O caminho já previsto (`DB_PASS` no ambiente) deve ser ligado fora deste diff; o literal só sai num deploy seguinte, depois da rotação. `corrigido: false`.

## F9 — Senha armazenada como MD5 sem sal

- Onde: `code/lib.php:15`; coluna em `code/schema.sql:7`; carga em `code/seed.sql:5`–`8`.
- Mecanismo: `autenticar` faz `md5($senha)` e compara com `usuarios.senha` no SQL. A coluna é `CHAR(32)`. O seed grava `MD5('senha123')` e `MD5('tecmaster')`. Não há sal nem custo. Com um vazamento da tabela (o F1 fazia isso), as senhas do manifesto caem por tabela arco-íris na hora, e o mesmo vale para qualquer senha fraca de produção.
- Impacto: recuperação offline das senhas se a tabela vazar; brute force online barato. Severidade **alta**. Confiança **99**.
- O que fiz: **não alterei** `autenticar`, o schema nem o seed. `password_hash` não cabe em `CHAR(32)`. Alargar a coluna no `schema.sql` não migra o banco que já está no ar; um `UPDATE` para bcrypt no login seguinte falharia ou truncaria e trancaria todo mundo. Outros scripts podem inserir hash MD5 de 32 caracteres. Trocar o algoritmo aqui quebra o login dos quatro usuários de teste e de quem já está em produção. A troca exige `ALTER`, rehash na autenticação bem-sucedida e janela em que o MD5 antigo ainda é aceito — fora deste pacote. `corrigido: false`.

## F10 — Cabeçalho do CSV não é o texto exigido

- Onde: `code/lib.php:130`.
- Mecanismo: o cabeçalho ia por `fputcsv`. O campo `Aberto em` tem espaço, então o PHP 8.4 emite `ID,Titulo,Status,Tecnico,"Aberto em"`. O manifesto exige a linha exata `ID,Titulo,Status,Tecnico,Aberto em`. Um consumidor que compara a primeira linha byte a byte (faturamento, rotina noturna) não reconhece o arquivo. Um parser CSV de verdade aceita os dois; o contrato não.
- Impacto: rejeição do export por quem casa o cabeçalho literal. Severidade **média**. Confiança **88**.
- O que fiz: a primeira linha é escrita com `fwrite` exatamente como o manifesto, terminada em `\n` (o mesmo fim de linha do `fputcsv` neste PHP). As linhas de dados continuam em `fputcsv`, para título com vírgula ou aspas não deslocar coluna. Status segue `formatarStatus`. Ordem `id ASC`.

## F11 — N+1 ao resolver o nome do técnico

- Onde: `code/lib.php:89`–`90` e, no export, `136`–`137`, cada um chamando `tecnicoNome` (`69`).
- Mecanismo: para cada chamado, `tecnicoNome` abre outra ida ao banco (`SELECT nome FROM usuarios WHERE id = ...`). A listagem e o CSV fazem isso em loop. Com o seed são cinco queries extras; com o volume de um ISP, a listagem e o export crescem linearmente em round-trips. O índice em `usuarios.id` não elimina o custo de N execuções.
- Impacto: latência da listagem e do CSV proporcional ao número de chamados. Severidade **média**. Confiança **95**.
- O que fiz: `listarChamados` e o CSV fazem `LEFT JOIN usuarios` e `COALESCE(u.nome, '-')`. Chamado sem técnico (104 no seed) continua com `tecnico_nome` igual a `-`, não some da lista (por isso `LEFT`, não `INNER`). `SELECT c.*` preserva as colunas que os scripts já leem. `tecnicoNome` permanece, com a mesma assinatura e o mesmo retorno, para quem ainda a chama; a query interna passou a placeholder. O `id` é `int` na assinatura, então a concatenação antiga não era injeção explorável — não contei isso como SQLi.

## F12 — POST/GET não-string derruba o PHP 8

- Onde: `code/index.php:22` e, no mesmo padrão, `72`.
- Mecanismo: `autenticar` exige `string`. `login[]=ana` faz `$_POST['login']` ser array; o `isset` é verdadeiro e a chamada lança `TypeError` antes do formulário. `busca[]=x` faz o mesmo em `listarChamados`. No PHP 8 isso é erro fatal, não warning.
- Impacto: 500 com um request malformado, sem autenticar. Severidade **baixa**. Confiança **92**.
- O que fiz: login só chama `autenticar` se os dois campos forem string (senha ausente continua sendo `''`, como o `?? ''` antigo). `busca` que não é string vira `''` e cai na listagem sem filtro, em vez de estourar.

## Decisões — o que não mudei

- **Assinaturas de `lib.php`.** Nome, parâmetros e retorno de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` estão iguais. `escreverCsv` é auxiliar novo, fora do manifesto; `exportarCsv` delega a ele com `$usuarioId = null` e portanto continua exportando todos os chamados para a saída.
- **Visibilidade dentro das funções públicas.** Filtrar `listarChamados`/`verChamado`/`exportarCsv` por sessão esconderia chamado do relatório gerencial e do faturamento se esses scripts rodassem com sessão de cliente, e mudaria o retorno que hoje esses callers usam. A regra de visibilidade foi aplicada nas rotas de `index.php`, que são o produto. Técnico continua vendo tudo; cliente, só o que abriu.
- **MD5 e segredos em `config.php`.** Ver F9 e F8. Não há cofre neste pacote para onde movê-los sem apagar a única cópia ou invalidar o login.
- **Rótulos.** `formatarStatus` segue `1 → Aberto`, `2 → Em atendimento`, `3 → Resolvido`. O `else` também devolve `Resolvido` para status fora de 1–3; não separei isso porque o relatório gerencial casa esses textos e o contrato não define um quarto rótulo. `rotuloPrioridade` mantém as mesmas strings e o mesmo limiar de 30 minutos: o HTML da coluna Prioridade não está travado no manifesto, mas scrapers e o seed dependem do texto atual. Não “simplifiquei” o aninhamento.
- **HTML da listagem.** `id="tabela-chamados"`, colunas ID, Titulo, Status, Prioridade, Tecnico, link `index.php?ver=<id>`. Formulário `busca`, link `index.php?export=csv`, parâmetros `busca`/`ver`/`export`. Não acrescentei logout nem token de CSRF: o único POST é o login, não há ação de escrita de chamado, e exigir token quebraria quem já posta `login` e `senha`.
- **Média global no topo.** `mediaResposta` continua sendo a média de todos os chamados com primeira resposta, como a assinatura (sem ator) descreve. O número não lista chamado alheio. Restringi-lo ao cliente mudaria o indicador de SLA que o próprio comentário da função atribui ao painel.
- **Paginação e FULLTEXT.** A listagem e os scripts internos esperam o conjunto completo. `LIKE '%termo%'` não usa o índice de `titulo`; um FULLTEXT mudaria stopword e tamanho mínimo de token, ou seja, o que a busca encontra.
- **Injeção de fórmula no CSV.** Prefixar `=`, `+`, `-` ou `@` alteraria o título que o faturamento lê. O painel não grava chamado; o texto vem do banco. Deixei o valor cru, com o quoting normal do `fputcsv`.
- **`declare(strict_types=1)`.** Outros scripts podem passar id numérico como string. Strict types transformaria isso em `TypeError`.
- **`schema.sql` e `seed.sql`.** Fixtures e estrutura que os testes e os hashes MD5 assumem. Não movi arquivo nem troquei mysqli por outra API.
- **Concatenação em `verChamado` e `tecnicoNome`.** O parâmetro já era `int`. Troquei por placeholder ao editar as funções, sem tratar isso como falha de injeção.
