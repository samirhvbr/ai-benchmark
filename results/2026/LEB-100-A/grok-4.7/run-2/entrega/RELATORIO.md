# Relatório técnico — Painel de Chamados (LEB-100-A)

## Resumo

O painel autentica com consulta preparada, mas a listagem concatena a busca no SQL, e o papel gravado na sessão nunca é consultado: qualquer cliente autenticado lê, busca e exporta o chamado de qualquer outro. A exportação ainda depende de um arquivo fixo sob `/var/www` que o código não cria, então a rota `export=csv` responde vazio quando o diretório não existe e, quando existe, deixa PII num caminho previsível. Há XSS refletido na busca, média de SLA que derruba a listagem quando não há respostas, sessão fixável e segredos/hashes fracos que não dá para trocar sem quebrar o que já está em produção.

A superfície do `manifest.md` foi preservada: assinaturas, rótulos de status, parâmetros `busca`/`ver`/`export`, colunas e `id="tabela-chamados"`, CSV ordenado por `id` com o cabeçalho exato. A visibilidade (cliente só vê o que abriu; técnico vê tudo) foi aplicada na borda web, não dentro de `listarChamados`/`verChamado`/`exportarCsv`, para os relatórios internos continuarem enxergando a base inteira.

## Achados

### F1 — SQL injection na busca de chamados

- Onde: `code/lib.php:82` (consumo em `code/index.php:72`).
- Mecanismo: `listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` e passa a string a `mysqli::query`. `$busca` é `$_GET['busca']`, sem filtro. O valor entra entre aspas simples; um cliente logado envia `%' OR '1'='1` ou `%' UNION SELECT ...` e o banco executa o SQL dele. `mysqli::query` não empilha comandos, então não há `DROP` por esse caminho, mas há leitura: a união pode devolver `usuarios.senha` (MD5) e `chamados.descricao` nas colunas que a listagem imprime. Filtrar `usuario_id` depois, no PHP, não fecha isso — o `UNION` pode repetir o id do atacante na coluna do dono e a linha passa no filtro.
- Impacto: qualquer conta de cliente extrai a base (títulos, descrições, hashes de senha). Severidade **crítica**.
- Confiança: **98**.
- Correção: a busca passou a `prepare`/`bind_param` no `LIKE ?`. Termo benigno (`Centro`) continua casando por substring. Curinga `%` no termo ainda é curinga, como antes. `verChamado` e `tecnicoNome` também foram parametrizados; esses dois não eram exploráveis (o parâmetro já é `int` e a chamada faz cast), foi endurecimento, não o furo.

### F2 — Cliente vê e exporta chamado de outro cliente

- Onde: `code/index.php:38`–`73`. `$uid` e `$papel` são lidos na 38–39 e não voltam a ser usados.
- Mecanismo: a regra do manifesto não está no código. `listarChamados` faz `SELECT` sem `usuario_id`. `verChamado` filtra só por `id`. `exportarCsv` escreve todos os chamados. Com o seed, `ana` (cliente, id 1) abre `index.php?ver=104` e lê "Fatura em duplicidade" do Bruno, e `index.php?export=csv` devolve os cinco chamados. O mesmo URL de export é `GET` e não variava `Cache-Control`, então um cache compartilhado podia servir o CSV de um usuário a outro.
- Impacto: quebra da regra de visibilidade; vazamento de descrição de chamado (fatura, endereço, relato) entre clientes. Severidade **crítica**.
- Confiança: **99**.
- Correção: na borda web, papel diferente de `tecnico` só recebe linhas com `usuario_id` igual ao `uid` (listagem, detalhe e CSV). Técnico continua vendo tudo. Detalhe alheio cai no mesmo "Chamado nao encontrado." para não confirmar que o id existe. `listarChamados`, `verChamado` e `exportarCsv` sem sessão de cliente continuam devolvendo a base inteira — é o que o relatório gerencial e a rotina noturna chamam. A rota `?export=csv` de um cliente chama `escreverCsv($db, $uid)` em vez de `exportarCsv`, com o mesmo cabeçalho, a mesma ordem por `id` e os mesmos rótulos. Respostas autenticadas e o CSV saem com `Cache-Control: private, no-store`.

### F3 — XSS refletido no termo de busca

- Onde: `code/index.php:79` e `code/index.php:82`.
- Mecanismo: `$busca` entra cru em `value="..."` e em `Resultados para:`. Título, descrição e nome do técnico passam por `htmlspecialchars`; a busca não. `"><script>...` ou `'" onfocus=alert(1) autofocus="'` fecha o atributo. O cookie de sessão, neste PHP, não é HttpOnly (`session.cookie_httponly` vazio), então o script lê `PHPSESSID`.
- Impacto: sessão roubada de quem clica num link de busca; a partir daí o atacante age como a vítima, inclusive como técnico se a vítima for técnica. Severidade **alta**.
- Confiança: **96**.
- Correção: `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. O nome do parâmetro `busca` não mudou.

### F4 — CSV gravado em caminho fixo sob `/var/www`, com falha silenciosa

- Onde: `code/lib.php:125`–`150`.
- Mecanismo: `exportarCsv` faz `fopen(EXPORT_DIR . '/chamados.csv')` com `EXPORT_DIR = /var/www/painel/tmp`. O código não cria o diretório. Se ele não existe, `fopen` falha, a função retorna sem `header` e sem corpo, e `index.php` dá `exit`: `?export=csv` responde vazio. Se o diretório existe e o docroot é `/var/www/painel`, o arquivo é `GET /tmp/chamados.csv`, estático, sem passar pelo login. Dois downloads usam o mesmo path: `readfile` de um pode ler o `fwrite` do outro. O `return` da linha 133, quando a query falha, ainda deixa o handle aberto e o arquivo parcial no disco.
- Impacto: exportação contratada fora do ar sem o diretório; com o diretório, PII de todos os chamados em URL previsível e corrida entre sessões. Severidade **alta**.
- Confiança: **92**. O defeito no código é certo; se o Apache/nginx serve esse path depende do docroot, por isso não marquei crítica.
- Correção: o CSV é gerado por consulta (com `JOIN`, sem N+1) e escrito em `php://output`, depois de a query ter sucesso. Cabeçalhos HTTP só então. Não há mais arquivo compartilhado. `exportarCsv` continua emitindo todos os chamados na saída, que é o contrato. Quem lia o arquivo em disco em vez da saída precisa capturar o stdout — o manifesto descreve a saída, não o path.

### F5 — Divisão por zero em `mediaResposta` derruba a listagem

- Onde: `code/lib.php:116`.
- Mecanismo: a função soma `minutos_resposta` não nulos e retorna `$soma / $qtd`, com `$qtd` começando em 0. Não há `return` antes da divisão. No PHP 8 isso é `DivisionByZeroError`, não um warning. `index.php:74` chama a função em toda listagem. Com o seed (12, 40 e 25) a média é 77/3 e a página abre; com tabela vazia, ou só com os chamados 103 e 104 (`minutos_resposta` NULL), a listagem inteira cai antes do HTML.
- Impacto: painel indisponível no cenário sem primeira resposta, que é justamente o começo da operação. Severidade **alta**.
- Confiança: **97**.
- Correção: `SUM`/`COUNT` numa query só. Com `qtd = 0`, retorna `0.0`. Com o seed, o float continua `25.666666666666668` (77/3), então `round($media)` na tela não muda. Query falha também retorna `0.0` em vez de fatal em `fetch_assoc` de `false`.

### F6 — Senha de banco e chave SMTP no fonte

- Onde: `code/config.php:12` e `code/config.php:15`.
- Mecanismo: `DB_PASS` cai em `N3tX@2013!prod` se `getenv('DB_PASS')` for falso. `SMTP_API_KEY` é o literal `netx-smtp-9f83e2c1a7b64d05`, sem leitura de ambiente. Os dois estão no arquivo que todo deploy e todo backup do código carregam. A chave SMTP não é usada por `index.php`/`lib.php`, mas a constante existe para os outros scripts do ISP que dão `require` em `config.php`.
- Impacto: leitura do repositório entrega a senha do MySQL de produção e a credencial de e-mail. Severidade **alta**.
- Confiança: **99**.
- Correção: **não alterei**. Tirar o fallback derruba o painel em todo ambiente que ainda não injeta `DB_PASS` — e o comentário do arquivo diz que esse fallback é a senha de produção. Apagar `SMTP_API_KEY` quebra o consumidor que inclui `config.php` e usa a constante. O conserto operacional (variável de ambiente, rotação, remoção do literal) precisa de um deploy coordenado; não há cofre de segredo nesta stack. Mexer no valor aqui ou trava o sistema ou só finge que o segredo saiu.

### F7 — Senha armazenada como MD5 sem sal

- Onde: `code/lib.php:15`; coluna em `code/schema.sql:7`.
- Mecanismo: `autenticar` faz `md5($senha)` e compara com `usuarios.senha CHAR(32)`. Sem sal e sem custo. O seed grava `MD5('senha123')` e `MD5('tecmaster')`. Não há limite de tentativa no login; o hash é rápido o bastante para brute force online e, com um dump (F1 ou backup), rainbow table resolve offline.
- Impacto: comprometimento de conta a partir do banco, ou chute online sem freio. Severidade **alta**.
- Confiança: **99**.
- Correção: **não alterei o algoritmo**. `password_hash` (bcrypt/argon) não cabe em `CHAR(32)`. Trocar o hash sem migração faz `autenticar` devolver `null` para ana, bruno, carla, diego e para todo usuário já em produção — quebra o login, que os outros scripts e o manifesto dependem de continuar funcionando. Uma migração real precisaria alargar a coluna e aceitar os dois formatos na verificação; isso muda o schema que o ISP já tem populado e não cabe num patch que não reescreve a autenticação. O que fiz em `autenticar` foi só não fatalar se `prepare` falhar (devolve `null`, mesma resposta de senha errada).

### F8 — Sessão fixável e cookie sem HttpOnly

- Onde: `code/index.php:15` e `code/index.php:24`–`26`.
- Mecanismo: `session_start()` usa o default deste PHP: `session.use_strict_mode=0`, `session.cookie_httponly` vazio, `session.cookie_secure=0`, `SameSite` vazio. No login bem-sucedido o código grava `uid` e `papel` no id que o cliente já trouxe, sem `session_regenerate_id`. Fixação: o atacante escolhe um `PHPSESSID`, faz a vítima entrar com esse id, e reusa o cookie. Junto com F3, o XSS lê o cookie porque ele não é HttpOnly.
- Impacto: sequestro de sessão de cliente ou técnico. Severidade **média** (exige induzir a vítima, ou o XSS do F3).
- Confiança: **92**.
- Correção: antes de `session_start`, `use_strict_mode=1`, `use_only_cookies=1` (já era o default, reforcei), cookie `HttpOnly`, `SameSite=Lax`, `Secure` só se a requisição for HTTPS — painel legado em HTTP puro continua logando. No sucesso do login, `session_regenerate_id(true)` e só então `uid`/`papel`. Path e domain do cookie foram os que o PHP já usava.

### F9 — N+1 na resolução do nome do técnico

- Onde: `code/lib.php:89` (e o mesmo padrão em `code/lib.php:137`); a query unitária está em `code/lib.php:69`.
- Mecanismo: para cada chamado, `tecnicoNome` abre outra ida ao banco (`SELECT nome FROM usuarios WHERE id = ...`). Listagem e CSV fazem 1 + N queries. Com o seed são cinco; a tela cresce linear com `chamados`.
- Impacto: listagem e exportação ficam lentas conforme a tabela cresce; não corrompe dado. Severidade **média**.
- Confiança: **96**.
- Correção: `listarChamados` e o CSV fazem `LEFT JOIN usuarios` e preenchem `tecnico_nome` na mesma query. Técnico ausente continua `'-'`, não string vazia (o chamado 104 do seed). `tecnicoNome` ficou no arquivo, com `prepare`, porque outro script do ISP pode chamá-la; a assinatura não mudou.

### F10 — Cabeçalho do CSV não é o texto exato do contrato

- Onde: `code/lib.php:130`.
- Mecanismo: o cabeçalho sai por `fputcsv`. Neste PHP 8.4, campo com espaço é citado: a primeira linha vira `ID,Titulo,Status,Tecnico,"Aberto em"`, não `ID,Titulo,Status,Tecnico,Aberto em`. O manifesto pede o cabeçalho exato; a integração de faturamento que compara a linha byte a byte rejeita o arquivo. Um parser CSV (`fgetcsv`) leria o mesmo nome de coluna nos dois formatos — o furo é o contrato textual, não o significado da coluna.
- Impacto: exportação rejeitada por consumidor que casa a primeira linha. Severidade **média**.
- Confiança: **86**.
- Correção: a primeira linha é escrita com `fwrite` no texto exato, terminada em `\n` (o mesmo fim de linha do `fputcsv` neste PHP). As linhas de dados continuam em `fputcsv`, então título com vírgula não desloca coluna — o comportamento de dados do código original. Conferido com o seed: ordem 101..105, status pelos rótulos, técnico `-` no 104, cabeçalho sem aspas.

### F11 — Injeção de fórmula no CSV

- Onde: `code/lib.php:138`–`144`.
- Mecanismo: `fputcsv` grava `titulo` e o nome do técnico como vieram do banco. Este painel não cria chamado, mas outros sistemas do ISP gravam `titulo`. Um valor que começa com `=`, `+`, `-`, `@`, tab ou CR vira fórmula quando um técnico abre `chamados.csv` no Excel (`=cmd|...`). O placeholder de técnico ausente é o caractere `-`; prefixar todo campo que começa com `-` mudaria o chamado 104 de `-` para `'-` e quebraria quem lê essa coluna.
- Impacto: execução de fórmula na máquina de quem abre o export, se um título malicioso entrar por outro sistema. Severidade **média**.
- Confiança: **70**. O caminho no código é real; não há formulário de abertura de chamado aqui, então o payload precisa nascer fora deste arquivo.
- Correção: `celulaCsv` prefixa `'` só nesses casos, e deixa intactos `''` e o `'-'` exato. Títulos do seed não mudam.

### F12 — Parâmetro array derruba o painel com TypeError

- Onde: `code/index.php:22` (`login`/`senha`); o mesmo padrão em `code/index.php:72` (`busca`). `ver` em array caía em `(int)` = 1.
- Mecanismo: `autenticar` e `listarChamados` exigem `string`. No PHP 8, `POST login[]=x` ou `GET busca[]=x` não vira string: é `TypeError` antes de qualquer HTML, resposta 500. `(int)` de `ver[]=x` vale 1, então a URL abria o chamado 1 em vez de "não encontrado".
- Impacto: negação de serviço barata na tela de login e na listagem; no detalhe, id errado. Severidade **baixa**.
- Confiança: **95**.
- Correção: login e senha só seguem se forem string; busca que não é string vira `''`; `ver` que não é string vira id 0 e cai em "não encontrado". String numérica (`ver=101`) continua sendo `(int)`, como antes.

## Decisões

Não reescrevi o painel e não troquei mysqli. As sete assinaturas do manifesto continuam iguais, inclusive `exportarCsv(mysqli $db): void` devolvendo o CSV na saída.

Não coloquei o filtro de cliente dentro de `listarChamados` nem de `verChamado`. Esses dois são a API dos relatórios internos; passar a esconder chamado quando não há sessão de cliente quebraria o relatório gerencial. A regra de visibilidade vale para quem entra pelo `index.php`. `exportarCsv()` sem filtro continua exportando todos — a rota web é que escolhe `escreverCsv($db, $uid)` para cliente. Esconder chamado de técnico, ou o próprio chamado do cliente, seria violação do manifesto; o critério é `papel === 'tecnico'` para ver tudo, e qualquer outro papel autenticado fica restrito ao `uid` (falha fechada).

Não migrei o MD5 e não apaguei os literais de `config.php`. Os dois são falhas reais; o remendo local ou impede o login de todo mundo ou tira do ar o banco/SMTP de quem ainda depende do fallback. `EXPORT_DIR` permanece definido: outro script pode usar a constante, mesmo este painel não gravando mais o CSV ali.

Não paginei a listagem. Um `LIMIT` esconderia chamado de quem espera a lista completa (tela do técnico e `listarChamados`). Não acrescentei `declare(strict_types=1)`: chamador externo que passa id numérico em string passaria a tomar `TypeError`.

Não mexi nos textos de `formatarStatus` nem de `rotuloPrioridade`. O `else` que devolve "Resolvido" é o que faz o status 3 cumprir o contrato; um rótulo novo para código inesperado mudaria o relatório gerencial se houver lixo na coluna. `rotuloPrioridade` não recebe `criado_em`; não dá para marcar SLA de chamado ainda sem primeira resposta sem mudar a assinatura. A média no topo continua global, também para cliente: `mediaResposta` é o indicador do sistema, não por usuário, e o número não lista chamado alheio.

Não escapei `%` e `_` dentro do `LIKE`. Isso mudaria a busca de quem já usa curinga; a injeção de SQL não dependia disso.

Não pus token CSRF no login. A única escrita deste código é o próprio login; não há abertura nem edição de chamado. Um token obrigatório quebraria automação que faz `POST login/senha`, e o ganho aqui é pequeno (login CSRF não grava dado da vítima em lugar nenhum). Não pus atraso nem tabela de tentativas: `sleep` no processo vira DoS de workers, e não há store de rate limit na stack.

Não adicionei rota de logout, BOM no CSV, nem separei o PHP do HTML. Logout não está no contrato de rotas; BOM estragaria o cabeçalho exato; um MVC seria reescrita, não evolução.
