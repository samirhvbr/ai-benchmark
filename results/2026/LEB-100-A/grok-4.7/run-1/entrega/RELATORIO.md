# Relatório — LEB-100-A

## Resumo

Painel de chamados em PHP + mysqli, em produção desde 2013. A superfície pública (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`, rotas `busca`/`ver`/`export`, HTML da tabela e rótulos de status) continua válida. O código entregue não troca a stack nem as assinaturas.

O estado original misturava dois contratos: as funções de `lib.php` são API de dados para a rotina noturna, o relatório gerencial e o faturamento (precisam ver tudo); a regra de negócio do produto diz que cliente só vê chamado que ele abriu e técnico vê qualquer um. A borda HTTP ignorava `uid` e `papel`. Além disso havia injeção de SQL na busca, XSS refletido, dump de CSV num arquivo fixo sob `/var/www`, divisão por zero no indicador de SLA e sessão sem regeneração.

Corrigi o que quebra dado, disponibilidade ou o contrato, sem filtrar dentro das funções que os scripts em lote chamam. Não reescrevi o sistema.

## Achados

### F1 — Injeção de SQL na busca de chamados

- Onde: `code/lib.php:82` (entrada em `code/index.php:72`).
- Severidade: crítica. Confiança: 99.
- Mecanismo: `listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` e `index.php` passa `$_GET['busca']` sem tratamento. O fecho da aspas e o `ORDER BY criado_em DESC` vêm depois da concatenação, então um `--` os anula. Reproduzi contra o banco de seed: `' UNION SELECT id, login, senha, nome, papel, 1, 1, 1, 1 FROM usuarios -- ` devolveu 9 linhas (5 chamados + 4 usuários). `SELECT *` tem 9 colunas; `senha` cai na posição de `tecnico_id`. `' OR 1=1 -- ` devolve todos os chamados. `mysqli::query` não executa múltiplos statements, então não há stacked query — a extração por UNION basta para levar os hashes MD5 e o texto dos chamados. `%` e `_` também entram como curingas de LIKE: buscar `%` casava com todos os títulos.
- Impacto: qualquer cliente autenticado lê a tabela `usuarios` (login e senha) e todos os chamados, ou altera o filtro da listagem.
- Correção: `prepare` + `LIKE ? ESCAPE '\\'`, com o padrão montado por `addcslashes($busca, "%_\\")`. O mesmo UNION passa a devolver 0 linhas. `Lentidao` e `conexao` continuam casando. A chave `tecnico_nome` e a ordem `criado_em DESC` foram preservadas.

### F2 — Cliente vê chamado de outro cliente

- Onde: `code/index.php:39`–`73` (rotas em 44, 52 e 72).
- Severidade: crítica. Confiança: 98.
- Mecanismo: depois do login o script grava `$uid` e `$papel` e não usa nenhum dos dois. `?export=csv` chama `exportarCsv`, que faz `SELECT * FROM chamados`. `?ver=<id>` chama `verChamado` só com o id. A listagem usa `listarChamados` sem dono. Ana (cliente, id 1) recebia também os chamados 103 e 104 de Bruno, inclusive a descrição «Cobranca repetida no cartao», e o CSV completo. A regra do manifesto é outra: cliente só vê o que ele abriu (`usuario_id`); técnico vê qualquer um.
- Impacto: quebra de confidencialidade entre clientes e violação direta da regra de negócio. Os ids são sequenciais, então enumerar `ver` percorre a base.
- Correção: o filtro ficou na borda HTTP, não dentro das funções públicas. Técnico continua chamando `exportarCsv` / `listarChamados` / `verChamado` e vê tudo — é o que a rotina noturna e o relatório gerencial precisam. Cliente recebe só linhas com `usuario_id` igual à sessão. `ver` de chamado alheio devolve o mesmo texto «Chamado nao encontrado.» (não confirma existência). O CSV do cliente usa o mesmo cabeçalho e as mesmas colunas, ordenado por `id`. Verifiquei: Ana vê 101, 102, 105; Bruno vê 103, 104; Carla e Diego vêem os cinco; export não autenticado cai no formulário de login.

### F3 — XSS refletido no parâmetro `busca`

- Onde: `code/index.php:79`–`82`.
- Severidade: alta. Confiança: 99.
- Mecanismo: `$busca` entra cru no atributo `value` e no parágrafo «Resultados para:». `?busca="><script>alert(1)</script>` fecha o atributo e injeta HTML na página já autenticada. Título, descrição e nome do técnico já passavam por `htmlspecialchars`; só o eco da busca não. O cookie de sessão nascia sem `HttpOnly` (F6), então o script lia `document.cookie`.
- Impacto: execução de script no navegador de quem abre o link, com a sessão do painel.
- Correção: `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. A consulta continua recebendo a string crua, agora parametrizada. O payload sai como `&quot;&gt;&lt;script&gt;...`. Texto sem aspas (o caso normal) não muda.

### F4 — CSV gravado em caminho fixo e gravável

- Onde: `code/lib.php:125`–`150`.
- Severidade: alta. Confiança: 96.
- Mecanismo: `exportarCsv` abre `EXPORT_DIR . '/chamados.csv'`, isto é `/var/www/painel/tmp/chamados.csv`. O diretório existe e está `0777`, sob o prefixo usual de document root. Dois exports simultâneos compartilham o mesmo arquivo: `readfile` pode entregar o dump do outro pedido (um cliente pode baixar o CSV completo do técnico). O arquivo fica no disco depois do `readfile` — não há `unlink`. Se `fopen` falha, a função volta sem corpo. Se a query falha depois do `fopen`, o handle vaza e os headers nunca saem. Os headers só são enviados depois da escrita, então qualquer aviso durante o `fputcsv` (F12) chega antes e `header()` falha com `display_errors` ligado.
- Impacto: vazamento de todos os chamados para quem ler o arquivo, e resposta de download corrompida ou cruzada.
- Correção: a query roda primeiro; o CSV vai para `php://output` com os mesmos dois headers (`Content-Type: text/csv; charset=utf-8` e `Content-Disposition: attachment; filename="chamados.csv"`). `exportarCsv` continua escrevendo o CSV na saída, que é o contrato, e continua listando todos os chamados para os scripts em lote. O efeito colateral do arquivo não está no manifesto e foi removido. A constante `EXPORT_DIR` permanece em `config.php` para quem já a lê.

### F5 — Divisão por zero em `mediaResposta`

- Onde: `code/lib.php:116`.
- Severidade: alta. Confiança: 97.
- Mecanismo: a função soma `minutos_resposta` em PHP e retorna `$soma / $qtd`. Se nenhum chamado tem primeira resposta, `$qtd` é 0. No PHP 8 isso lança `DivisionByZeroError` (confirmei a classe). `index.php` chama a função em toda listagem, então o painel inteiro cai com 500. O seed esconde o caminho: há três valores (12, 40, 25), média `25.666666666667`, e `round` mostra 26.
- Impacto: indisponibilidade da listagem quando não há chamado respondido — o caso de um painel novo ou de uma base só com chamados abertos.
- Correção: `SUM`/`COUNT` no SQL e `0.0` quando a contagem é 0. A assinatura continua `float`. Com o seed a string do float segue `25.666666666667` e o painel segue mostrando 26. Numa transação com a tabela vazia (rollback em seguida) a função devolveu `0.0` sem exceção.

### F6 — Sessão fixável e cookie sem HttpOnly

- Onde: `code/index.php:15`–`26`.
- Severidade: média. Confiança: 90.
- Mecanismo: `session_start()` usa o default do runtime: `session.cookie_httponly` vazio, `cookie_samesite` vazio, `use_strict_mode` 0. No login bem-sucedido o código grava `uid` e `papel` no id de sessão que já veio no cookie e redireciona, sem `session_regenerate_id`. Quem planta o id antes da vítima autenticar permanece com esse id. Sem `HttpOnly`, o XSS de F3 lê o cookie.
- Impacto: sequestro de sessão por fixação ou por script injetado.
- Correção: cookie `HttpOnly`, `SameSite=Lax`, `Secure` só se `HTTPS` estiver ligado (não forcei Secure em HTTP, senão o harness e o painel em claro perdem a sessão), `session.use_strict_mode=1` e `session_regenerate_id(true)` depois da senha correta, antes de gravar `uid`/`papel`. O `Set-Cookie` do login passou a ser outro id, com `HttpOnly` e `SameSite=Lax`.

### F7 — Cabeçalho do CSV não é o texto do contrato

- Onde: `code/lib.php:130`.
- Severidade: média. Confiança: 93.
- Mecanismo: o cabeçalho sai por `fputcsv`. No PHP 8.4.26 campo com espaço é citado, então a primeira linha era `ID,Titulo,Status,Tecnico,"Aberto em"` (capturei os bytes). O manifesto exige o texto exato `ID,Titulo,Status,Tecnico,Aberto em`. Quem compara a primeira linha como string — o faturamento depende desse cabeçalho — rejeita o arquivo. Um parser CSV aceitaria os dois; o contrato pede o literal.
- Impacto: quebra de compatibilidade do export, mesmo com as colunas corretas.
- Correção: a primeira linha é escrita com `fwrite` exatamente como o manifesto. As linhas de dados continuam em `fputcsv` (título com vírgula precisa de aspas; as linhas do seed ficaram byte a byte iguais). O export do técnico, chamado via `exportarCsv`, confere com esse arquivo.

### F8 — `fputcsv` sem `$escape` quebra o download no PHP 8.4

- Onde: `code/lib.php:130` e `138`.
- Severidade: média. Confiança: 88.
- Mecanismo: no PHP 8.4 omitir `$escape` em `fputcsv` emite `Deprecated`. Rodei o `exportarCsv` original com `display_errors=1`: o aviso saía na linha 130, antes dos `header()` das linhas 148–149, e o runtime respondia «Cannot modify header information - headers already sent». O download perdia `Content-Disposition`. Com `display_errors` desligado o aviso ainda vai para o log, uma vez por linha. A produção de 2013 pode estar num PHP antigo em que isso é silêncio; no runtime em que este pacote roda (8.4.26) o aviso é real.
- Impacto: export corrompido ou sem attachment quando avisos vão para a saída; ruído em todo download.
- Correção: `$escape` explícito `'\\'` (o default histórico, para não mudar o escape das linhas do seed) e `$eol` `"\n"`.

### F9 — Fórmula em célula do CSV

- Onde: `code/lib.php:138`–`144`.
- Severidade: média. Confiança: 82.
- Mecanismo: `fputcsv` grava `titulo` e o nome do técnico como vieram do banco. Um título `=cmd|' /C calc'!A1`, ou começando com `+`, `-`, `@`, ou espaço seguido desses, vira fórmula quando o técnico abre o CSV no Excel ou no LibreOffice. Este pacote não tem tela de abertura de chamado; o payload precisa já estar em `chamados.titulo`, escrito por outro sistema do ISP. Os títulos do seed não começam com esses caracteres, então as linhas de dados do seed não mudam. O marcador `-` de técnico ausente (chamado 104) foi deixado intacto: prefixar apóstrofo transformaria `,-,` em `,'-,` e quebraria quem trata esse hífen como «sem técnico».
- Impacto: execução de fórmula na máquina de quem abre o export, se houver título envenenado.
- Correção: campo que casa `^\s*[=+\-@]` ganha um `'` na frente, exceto o literal `-`.

### F10 — N+1 em `tecnicoNome`

- Onde: `code/lib.php:89` e `137` (a query está na linha 69).
- Severidade: média. Confiança: 95.
- Mecanismo: cada linha de `listarChamados` e de `exportarCsv` chama `tecnicoNome`, que executa `SELECT nome FROM usuarios WHERE id = ...`. Cinco chamados são cinco idas extras ao banco; em produção é uma query por linha, e o nome pode mudar entre a listagem e o lookup. O contrato da listagem só exige a chave `tecnico_nome`.
- Impacto: latência linear no número de chamados e carga desnecessária no MySQL a cada abertura do painel e a cada export.
- Correção: `LEFT JOIN usuarios` com `COALESCE(u.nome, '-')`. As chaves do `SELECT *` mais `tecnico_nome` permanecem; o chamado 104 continua com `-`. `tecnicoNome` ficou no arquivo, com a mesma assinatura, para os scripts que já a chamam — a query passou a ser preparada.

### F11 — Senha em MD5 sem sal

- Onde: `code/lib.php:15` (coluna em `code/schema.sql:7`).
- Severidade: alta. Confiança: 99.
- Mecanismo: `autenticar` compara `md5($senha)` com `usuarios.senha CHAR(32)`. Não há sal. MD5 é rápido; tabela arco-íris cobre `senha123` e `tecmaster`. O seed e o comentário do schema documentam o MD5. Não há fluxo de troca de senha neste pacote, então um rehash no login não consegue migrar a base, e outros scripts do ISP podem gravar ou comparar o mesmo hash.
- Impacto: vazamento da tabela `usuarios` (F1, antes da correção, ou um backup) vira senha em claro em segundos.
- Correção: não alterei. Trocar o algoritmo tranca Ana, Bruno, Carla, Diego e qualquer usuário de produção. A comparação continua no SQL, com o mesmo retorno `['id','nome','papel']`.

### F12 — Segredos no fonte

- Onde: `code/config.php:12` e `15`.
- Severidade: alta. Confiança: 99.
- Mecanismo: `DB_PASS` cai em `N3tX@2013!prod` quando a variável de ambiente não existe, e `SMTP_API_KEY` é a literal `netx-smtp-9f83e2c1a7b64d05`. Quem tem o fonte — este pacote, um backup, um servidor que entrega PHP como texto — tem a senha do banco. Confirmei: esse fallback autentica em `suporte` como `painel`. `SMTP_API_KEY` não é lida por nenhum arquivo deste pacote, mas a constante é o contrato de configuração da central de e-mail.
- Impacto: comprometimento do MySQL de produção e da chave de SMTP se o fonte vazar. A senha já está no repositório; o certo operacionalmente seria rotacioná-la fora daqui.
- Correção: não alterei. Remover o fallback derruba o painel em todo ambiente que não define `DB_PASS` — é assim que esta instância conecta. Remover `SMTP_API_KEY` quebra o script de e-mail que faz `require` de `config.php` e espera a constante.

### F13 — Parâmetro HTTP não escalar derruba a rota

- Onde: `code/index.php:22`, `53` e `72`.
- Severidade: baixa. Confiança: 90.
- Mecanismo: `$_POST['login']` vai para `autenticar(..., string)`. `$_GET['busca']` vai para `listarChamados(..., string)`. `$_GET['ver']` é convertido com `(int)`. No PHP 8, `login[]=`, `busca[]=` ou `ver[]=` é array: o type hint de string lança `TypeError`, e `(int)` de array também. Erro não capturado, HTTP 500, e stack trace se `display_errors` estiver ligado. Não é bypass de autenticação — é queda da rota.
- Impacto: indisponibilidade pontual da tela de login, da listagem ou do detalhe.
- Correção: login/senha que não são string viram tentativa falha e o formulário reaparece, igual senha errada. `busca` que não é string vira string vazia e a listagem segue, com o filtro de visibilidade. `ver` que não é escalar vira «Chamado nao encontrado.». Os três casos respondem 200 com o HTML que já existia, sem `TypeError`.

## Decisões

Não filtrei dentro de `listarChamados`, `verChamado` nem `exportarCsv`. Essas assinaturas não recebem usuário, e a rotina noturna, o relatório gerencial e o faturamento dependem delas para ver a base inteira. Empurrar a sessão para dentro da lib esconderia chamado de quem tem direito quando o script em lote herda um cookie, ou exigiria parâmetro novo — isso muda a assinatura. A regra de visibilidade vale no produto, isto é, nas rotas de `index.php`. O custo aceito: um request de cliente ainda lê as linhas no servidor antes de descartar as que não são dele; elas não saem na resposta.

Não mudei `formatarStatus` nem `rotuloPrioridade`. Os rótulos `Aberto`, `Em atendimento` e `Resolvido` são contrato do relatório gerencial. O `else` que devolve `Resolvido` para qualquer outro inteiro é o comportamento atual; o contrato só define 1, 2 e 3, e inverter o `else` pode reclassificar dado legado. Os textos de prioridade (`CRITICO - SLA estourado`, `Alto - atrasado`, `Alto - dentro do SLA`, `Normal`, `Aguardando 1a resposta`) não estão listados no manifesto, mas a listagem do seed os exibe e um consumidor pode casar a string. Prioridade 1 e 2 compartilham `Normal`, e prioridade 4 sem `minutos_resposta` cai em `Aguardando 1a resposta` porque o `null` é testado antes da prioridade. Pode ser simplificação de produto; não tenho spec que diga o contrário, então não mexi.

Não troquei a média global por uma média só dos chamados do cliente. `mediaResposta` não recebe usuário e o número do topo é indicador de SLA da operação, o mesmo para técnico e para os scripts de relatório. A regra de visibilidade fala de chamado, não do agregado. Ana e Carla continuam vendo «26 min».

Não revalidei `papel` no banco a cada request. A sessão guarda o papel do login. Um rebaixamento no meio da sessão só vale no próximo login. Consultar `usuarios` em toda página acoplaria a borda a mais uma query e mudaria o comportamento de quem injeta a sessão nos testes. O papel gravado no login só é aceito como técnico se for exatamente `tecnico`; qualquer outro valor falha fechado (vê só os próprios chamados).

Não adicionei CSRF, logout, limite de tentativa nem cabeçalhos extras (`CSP`, `X-Frame-Options`). O único POST é o login; um token quebraria um monitor que posta `login` e `senha` sem campo novo. Logout e links novos alterariam o HTML em volta da tabela. A estrutura declarada ficou: `id="tabela-chamados"`, colunas ID, Titulo, Status, Prioridade, Tecnico, nesta ordem, id com link `index.php?ver=<id>`.

Não troquei mysqli, não criei framework, não movi arquivo, não alterei `schema.sql` nem `seed.sql`, não criei índice FULLTEXT. `LIKE '%termo%'` não usa o índice de `titulo`; FULLTEXT mudaria a semântica da busca (acento, palavra parcial) e o schema que os testes sobem.

Parametrizei `verChamado` e `tecnicoNome` mesmo sem contá-los como injeção explorável. O type hint `int` / `?int` já impede string no HTTP. É endurecimento, não achado separado. `autenticar` ganhou checagem de `prepare`/`execute` e `close` do statement: senha errada e usuário inexistente continuam devolvendo `null`, sem distinguir os dois casos. O MD5 e o formato de retorno não mudaram.

`emitirCsv` e `campoCsv` são auxiliares novos. Não substituem assinatura documentada. `exportarCsv(mysqli $db): void` segue exportando todos os chamados para a saída.
