# RELATORIO — Painel de Chamados (NetX ISP) · instância LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP 8 + MySQL (`mysqli`), com login por
sessão, listagem com busca por título, tela de detalhe e exportação em CSV.
Todo o SQL é montado por concatenação de strings e não há nenhum tratamento de
erro de banco; a autenticação usa MD5 sem sal; e a **regra de visibilidade
declarada no manifesto (cliente só vê os chamados que abriu) não existia no
código** — a listagem, o detalhe e o CSV mostravam a carteira inteira para
qualquer usuário logado.

O que entreguei:

- **Superfície pública preservada**: as 7 funções declaradas no manifesto
  continuam com nome, parâmetros, tipos e formato de retorno idênticos; as
  rotas (`busca`, `ver`, `export=csv`), os rótulos de `formatarStatus`, o
  cabeçalho/ordem do CSV e a estrutura HTML da tabela (`id="tabela-chamados"`,
  colunas ID, Titulo, Status, Prioridade, Tecnico, ID como link) seguem
  valendo. Nenhum arquivo foi renomeado ou movido; a camada de acesso a dados
  continua `mysqli`.
- **Correções**: SQL parametrizado em todas as consultas, hash de senha em
  bcrypt (com migração do MD5 legado), regra de visibilidade aplicada na
  listagem, no detalhe e na exportação, segredos removidos do repositório,
  XSS refletido corrigido, erro fatal de divisão por zero eliminado, N+1
  removido, arquivo de export gerado em temporário com permissão restrita,
  sessão endurecida, validação de entrada e tratamento de erro do mysqli.
- **16 achados** (2 críticos/altos de segurança com impacto imediato, o resto
  de severidade média/baixa) — todos com mecanismo concreto neste código,
  severidade e confiança de 0 a 100. 15 foram corrigidos; 1 foi mantido
  (ver §4).
- **Verificação**: montei um MariaDB local com `code/schema.sql` + `code/seed.sql`
  e rodei 70 asserções (38 de biblioteca e 32 de fluxo HTTP real com
  `php -S` + `curl`), incluindo login, visibilidade por papel, XSS, tentativas
  de SQLi, formato do CSV e migração de hash. Detalhes em §5.

## 2. Achados (ordem de prioridade de correção)

> Convenção: o campo **Onde** cita a numeração **original** do arquivo
> recebido (é a mesma usada em `achados.json`); o bloco **O que fiz** cita a
> numeração do **código entregue** (`code/`).

### F1 — Injeção de SQL na busca por título
**Onde:** `code/lib.php:80-85` (concatenação em `code/lib.php:82`), alcançado
por `code/index.php:72-73`.

**Mecanismo:** `listarChamados()` montava o WHERE por concatenação —
`" WHERE titulo LIKE '%" . $busca . "%'"` — e o valor de `$busca` vinha
direto de `$_GET['busca']` (`index.php:72`), sem `htmlspecialchars`, sem
escaping e sem prepared statement. O `LIKE` ainda dava ao atacante o wildcard
`%`, que ele controlava livremente. O `mysqli::query()` é uma única
interpretação: o texto do usuário vira sintaxe SQL. Um payload como
`' UNION SELECT id,nome,papel,senha,... FROM usuarios -- ` devolve colunas de
`usuarios` (inclusive o hash de senha) na resposta HTML; `'; DROP TABLE
chamados; --` altera o schema. A rota exige uma sessão autenticada, mas
qualquer papel logado (inclusive um cliente) já basta para explorar a falha.

**Impacto:** leitura arbitrária do banco (hashes de senha, nomes de cliente),
alteração e destruição de dados. **Severidade: crítica. Confiança: 100.**

**O que fiz:** reescrevi a consulta como prepared statement
(`code/lib.php:221-273`, função `listarChamadosVisiveis`): o termo vai como
parâmetro `?`, e os curingas `%`, `_` e o próprio caractere de escape `!` são
escapados por `escaparLike()` (`code/lib.php:275-277`) com `ESCAPE '!'` no SQL.
Verificado: `busca="' OR '1'='1"` devolve 0 linhas (antes devolvia a tabela
inteira), `busca="'; DROP TABLE chamados; --"` não altera nada e a tabela
continua intacta, `busca=%` agora é procurado literalmente.

---

### F2 — Regra de visibilidade (cliente x chamado) não era aplicada
**Onde:** `code/index.php:73` (listagem), `code/index.php:52-53` (detalhe),
`code/index.php:44-47` (exportação CSV). A consulta base é
`code/lib.php:80` (`SELECT * FROM chamados`, sem filtro de dono).

**Mecanismo:** o papel do usuário (`$_SESSION['papel']`) era lido em
`index.php:39` e **nunca usado para filtrar nada**. `listarChamados()`
(`lib.php:78-93`) fazia `SELECT * FROM chamados` sem condição de
`usuario_id`; `verChamado()` (`lib.php:98-102`) buscava por id, sem conferir
dono; e `exportarCsv()` despejava todos os chamados. O manifesto define como
comportamento *pretendido* que "um cliente só pode ver os chamados que ele
mesmo abriu" e "um técnico pode ver qualquer chamado" — nada disso era
implementado, logo a regra estava quebrada para o papel `cliente`.

**Impacto:** qualquer cliente logado (`ana`, `bruno`) via a rota
`index.php?ver=<id>` abria o chamado de outro cliente pelo id (IDOR
seqüencial, `101`…`105`) e via `index.php?export=csv` baixava a carteira
inteira. Exposição de títulos, status, técnico responsável e data de abertura
de clientes alheios. **Severidade: alta. Confiança: 98.**

**O que fiz:** apliquei a regra nas três superfícies web:
`index.php:51-59` lê o papel do usuário **do banco** (nova função
`papelDoUsuario()`, fonte de verdade — assim um técnico rebaixado a cliente
perde o acesso amplo na sessão já aberta) e calcula
`$filtroUsuarioId = $uid` para o cliente; qualquer papel diferente de
`tecnico` (inclusive papel ausente ou ilegível) vale como cliente, ou seja, o
sistema **restringe** em vez de expor;
a listagem usa `listarChamadosVisiveis($db, $busca, $filtroUsuarioId)`
(`index.php:97`), que adiciona `c.usuario_id = ?`;
o detalhe (`index.php:75-81`) compara `(int)$c['usuario_id'] !== $uid` e
responde a mesma mensagem "Chamado nao encontrado" usada para id inexistente
(não confirma existência);
o CSV (`index.php:68`) usa `exportarCsvVisivel($db, $filtroUsuarioId)`.
As assinaturas públicas não mudaram: `listarChamados()` e `exportarCsv()`
continuam existindo com a mesma assinatura e **sem** filtro (a rotina noturna
do ISP e o relatório gerencial seguem recebendo tudo); a filtragem está em
funções novas (`listarChamadosVisiveis`, `exportarCsvVisivel`). Verificado
por HTTP: `ana` vê 3 chamados (101, 102, 105), `carla` vê os 5, `ana` recebe
"Chamado nao encontrado" em `?ver=103` e o CSV de `ana` tem só 3 linhas; com
o papel de `carla` alterado no banco durante a sessão, a listagem dela passa
de 5 para 0 chamados.

---

### F3 — Senhas guardadas em MD5 sem sal
**Onde:** `code/lib.php:15` (`$hash = md5($senha);`) e o esquema
`code/schema.sql:7` (`senha CHAR(32) NOT NULL, -- hash md5 (legado)`).

**Mecanismo:** a autenticação comparava o MD5 da senha enviada com o MD5
armazenado, num `WHERE senha = ?`. MD5 é rápido e sem sal: um dump do banco
(forçável via F1) cai em rainbow tables/dicionários instantaneamente, e duas
contas com a mesma senha têm o mesmo hash (o seed tem `ana` e `bruno` com
senha idêntica → mesmo hash). Também não há reuso de salt nem custo ajustável.

**Impacto:** comprometimento em massa de credenciais de clientes e técnicos a
partir de qualquer vazamento do banco; as senhas de seed (`senha123`,
`tecmaster`) são dicionário trivial. **Severidade: alta. Confiança: 100.**

**O que fiz:** `autenticar()` (`code/lib.php:26-75`) agora seleciona o hash e
verifica com `password_verify()`/`password_hash()` (bcrypt, `PASSWORD_DEFAULT`),
via `verificarSenha()` (`code/lib.php:116-128`). Para não derrubar a
produção, hashes MD5 legados ainda são aceitos e **migram sozinhos** no
primeiro login bem-sucedido (`migrarHashParaBcrypt`, `code/lib.php:77-114`).
A migração tem rede de segurança: se o banco ainda tiver a coluna `CHAR(32)`,
o `UPDATE` falha (modo estrito) ou trunca (modo não estrito) — nos dois casos
o `catch`/a conferência pós-`UPDATE` restauram o hash MD5, de modo que
**ninguém fica trancado fora do sistema** (verificado nos dois modos do
MariaDB). `schema.sql` passou a `senha VARCHAR(255)` e o `seed.sql` usa
hashes bcrypt — as credenciais do manifesto (`ana`/`senha123`,
`carla`/`tecmaster`) continuam valendo. **Ação de infraestrutura necessária
antes do deploy:** `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`
(sem isso o `UPDATE` de migração não entra — o login continua funcionando,
mas os hashes não migram).

---

### F4 — Segredos de produção dentro do repositório
**Onde:** `code/config.php:12` (`define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');`)
e `code/config.php:15` (`define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');`).

**Mecanismo:** o arquivo trazia a senha do usuário do banco como *fallback*
embutido (o próprio comentário admite: "senha do usuário de produção
(fallback embutido — legado)") e a chave da central de e-mail transacional
escrita em texto claro. O primeiro é pior que um segredo exposto: é o
**caminho padrão** — se a variável de ambiente não existir, a aplicação
autentica no banco com a senha que está no Git; o segundo é uma credencial
válida de terceiro que qualquer pessoa com leitura do repositório usa.

**Impacto:** acesso ao banco de produção e uso indevido da credencial SMTP a
partir de qualquer cópia do código (backup, git, imagem de container, dump).
**Severidade: alta. Confiança: 100.**

**O que fiz:** `config.php` (`code/config.php:8-35`) não embute mais nenhum
segredo: `DB_PASS` e `SMTP_API_KEY` vêm do ambiente e, como alternativa
operacional, de arquivos **fora** do repositório (`/etc/painel/db.pass`,
`/etc/painel/smtp.key`, via `lerSegredo()`). Os nomes das constantes foram
preservados, então os outros scripts do ISP continuam compilar. **Ação de
infraestrutura necessária:** exportar `DB_PASS`/`SMTP_API_KEY` no serviço e
**rotacionar** os dois valores (o da senha do banco e a chave SMTP) — eles
estão no histórico do repositório e considerararquilos "removidos" do arquivo
não os torna válidos.

---

### F5 — XSS refletido no parâmetro `busca`
**Onde:** `code/index.php:79` (atributo `value="..."` do formulário) e
`code/index.php:82` (`<p>Resultados para: ...`).

**Mecanismo:** o termo de busca era ecoado cru: `'... value="' . $busca . '" ...'`
e `'Resultados para: ' . $busca`. Qualquer pessoa que clicar num link com
`?busca=<script>...` executa JavaScript no contexto do painel, com o cookie
de sessão do usuário logado (o cookie não tinha `HttpOnly`, ver F13) e com
acesso às rotas autenticadas. Note que o próprio código usa
`htmlspecialchars()` nos títulos e descrições — a falha era só nos dois
lugares alimentados pela entrada.

**Impacto:** roubo de sessão, ações em nome do usuário, alteração da página
(phishing interno). **Severidade: alta. Confiança: 97.**

**O que fiz:** os dois pontos passaram a usar
`htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` (`index.php:99,102`), com
`ENT_QUOTES` explícito para não depender do default do PHP. Verificado por
HTTP: `?busca=<script>alert(1)</script>` devolve o termo escapado
(`&lt;script&gt;...`) e não há `<script>` executável na resposta.

---

### F6 — Divisão por zero no indicador de SLA
**Onde:** `code/lib.php:107-117`, especificamente `return $soma / $qtd;`
(`code/lib.php:116`).

**Mecanismo:** `mediaResposta()` somava e contava as respostas em PHP e
dividia sem verificar o denominador. Em PHP 8, `x / 0` lança
`DivisionByZeroError` (não é mais warning com valor `INF`/`NAN` como no PHP 5).
Se nenhum chamado tiver `minutos_resposta` — exatamente o caso de uma base
nova, de um seed recém-importado ou de uma base com todos os chamados
aguardando 1ª resposta — a painel inteiro quebra com erro fatal 500. O
`index.php:74-78` chama essa função **antes** de qualquer saída, ou seja, a
falha derruba a listagem, a busca e a exportação para todos os usuários.

**Impacto:** indisponibilidade total do painel em uma condição de dados
perfectamente normal (nenhuma resposta registrada ainda). **Severidade:
alta. Confiança: 92.**

**O que fiz:** a média passou a ser calculada no banco com
`AVG(minutos_resposta)` e a função devolve `0.0` quando não há amostra
(`code/lib.php:309-327`) — sem divisão em PHP. Verificado: zerando todos os
`minutos_resposta`, `mediaResposta()` devolve `0.0` e o painel continua
respondendo 200.

---

### F7 — N+1 na listagem e na exportação (uma consulta por linha)
**Onde:** `code/lib.php:89` (`tecnicoNome($db, ...)` dentro do `while` da
listagem) e `code/lib.php:137` (mesma chamada dentro do `while` do export).

**Mecanismo:** para cada chamado retornado, `tecnicoNome()` abria uma nova
consulta (`SELECT nome FROM usuarios WHERE id = N`). Com 5 chamados eram 6
queries; com a base real (milhares de chamados) a listagem dispara milhares
de round-trips ao banco — a consulta da listagem fica `N+1`. O mesmo valia
para o CSV.

**Impacto:** latência e carga de banco desproporcionais ao volume; o painel
fica lento exatamente quando há mais chamados (quando mais importa).
**Severidade: média. Confiança: 97.**

**O que fiz:** o nome do técnico passou a vir por `LEFT JOIN usuarios` na
própria consulta (`code/lib.php:224-225` e `code/lib.php:347-349`), com o
`'-'` para chamado sem técnico preservado (o `LEFT JOIN` devolve `NULL` e o
código converte para `'-'`, como antes). A listagem e o export passaram a
fazer **uma** consulta cada. `tecnicoNome()` continua existindo (mesma
assinatura) para os scripts que a usarem.

---

### F8 — Arquivo de exportação previsível dentro da árvore web
**Onde:** `code/lib.php:125-150` — `$caminho = EXPORT_DIR . '/chamados.csv';`,
com `EXPORT_DIR = '/var/www/painel/tmp'` (`code/config.php:18`).

**Mecanismo:** o CSV era gravado num nome fixo e previsível dentro de um
diretório que, pelo próprio caminho, está sob a raiz publicada do site
(`/var/www/painel`). O arquivo ficava lá depois do download, com as permissões
padrão do `fopen` (644) e sem qualquer `LOCK`: (a) qualquer visitante
anônimo podia baixar `http://<host>/tmp/chamados.csv` e ler a carteira
inteira **sem passar pelo login** — ou seja, o controle de acesso do painel
era contornado por fora dele; (b) duas exportações simultâneas escreviam no
mesmo arquivo, podendo misturar linhas de dois usuários ou entregar um CSV
truncado; (c) se o `fopen` falhasse, o `return` silencioso entregava uma
resposta 200 vazia ao cliente.

**Impacto:** vazamento dos dados de todos os chamados sem autenticação;
CSV corrompido em concorrência. **Severidade: média. Confiança: 75** (a
exposição via HTTP depende da configuração do servidor web para aquele
diretório — de `AllowOverride` a `Require all denied` — mas o caminho e o
nome fixo estavam no código, e a corrida não dependia de configuração).

**O que fiz:** o CSV é gerado em arquivo temporário de nome aleatório
(`tempnam`), com permissão `0600` e `flock(LOCK_EX)`, e **removido logo após
o envio** (`code/lib.php:379-430`) — não fica mais nenhum
`chamados.csv` jogável na árvore do site. O `Content-Type` e o
`Content-Disposition: attachment; filename="chamados.csv"` (que é o contrato
do navegador) foram preservados. O diretório agora é configurável por
variável de ambiente (`EXPORT_DIR`), o que permite à infraestrutura apontá-lo
para fora do docroot; deixei o padrão atual intacto por compatibilidade (ver §4).

---

### F9 — SQL por concatenação em `tecnicoNome()` e `verChamado()`
**Onde:** `code/lib.php:69` (`'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`)
e `code/lib.php:100` (`'SELECT * FROM chamados WHERE id = ' . $id`).

**Mecanismo:** as duas funções montam SQL por concatenação. Hoje não são
exploráveis: `$tecnicoId` é `?int` e `$id` é `int` (o type hint do PHP
coage o valor para inteiro) e `index.php:53` ainda faz `(int)` antes de
chamar. Mas a proteção é **acidental** — depende do type hint, não da
construção da query. Qualquer refactor futuro que relaxe o tipo (ou uma
chamada com `bind_param`/string) reabre a injeção num ponto onde hoje há
`prepare()`.

**Impacto:** risco latente (não explorável no código atual); se materializado,
leitura arbitrária de dados. **Severidade: baixa. Confiança: 90.**

**O que fiz:** as duas passaram a usar prepared statement
(`code/lib.php:174-205` e `code/lib.php:283-307`), mantendo assinaturas e
comportamento idênticos.

---

### F10 — `rotuloPrioridade()` rotula chamado crítico como "Alto"
**Onde:** `code/lib.php:40-59` (branch `else` de `code/lib.php:51`).

**Mecanismo:** a classificação só olhava a prioridade dentro do ramo
"atrasado": com `minutos <= 30` (dentro do SLA) *qualquer* prioridade `>= 3`
caía em `'Alto - dentro do SLA'` — inclusive a prioridade 4 (crítica), que
passava a ser rotulada "Alto". Um chamado crítico dentro do SLA é justamente
o que o técnico precisa ver como crítico, e o relatório gerencial usa esse
rótulo. A lógica "SLA só se aplica a prioridade >= 3" foi mantida (ver §4).

**Impacto:** painel e relatório subestimam a gravidade de chamados críticos.
**Severidade: baixa. Confiança: 60** (a intenção original pode ter sido
mostrar "Alto" enquanto o SLA não estourou; o manifesto não fixa os rótulos
desta função, ao contrário dos rótulos de status, que são contrato de valor).

**O que fiz:** a prioridade 4 dentro do SLA passou a sair como
`'CRITICO - dentro do SLA'` (`code/lib.php:156-172`). Todos os rótulos
existentes ('CRITICO - SLA estourado', 'Alto - atrasado', 'Alto - dentro do
SLA', 'Normal', 'Aguardando 1a resposta') foram mantidos byte a byte — só
foi acrescentado um rótulo, para não quebrar quem casa texto.

---

### F11 — Falha do export não chega ao cliente
**Onde:** `code/lib.php:126-129` (`if ($fp === false) { return; }`) e
`code/lib.php:132-135` (`if ($res === false) { return; }`).

**Mecanismo:** se o `fopen` falhasse (diretório inexistente, sem permissão) ou
a consulta falhasse, a função simplesmente retornava. O navegador recebia
HTTP 200 com corpo vazio, sem cabeçalho de download — o cliente acreditava
ter baixado um CSV vazio. No caso do `fopen` falhar, o `$fp` nem era fechado.

**Impacto:** relatório deailed ausente sem sinal de erro (decisão errada
baseada em dados faltantes); vazamento de descritor de arquivo.
**Severidade: média. Confiança: 90.**

**O que fiz:** existe agora um caminho único de erro
(`responderErroExportacao()`, `code/lib.php:436-443`) que devolve HTTP 500
com mensagem texto e registra a causa no `error_log`; o diretório é criado
quando falta e, se não for gravável, o export cai para `sys_get_temp_dir()`;
o handle é sempre fechado e o temporário removido.

---

### F12 — Entrada de usuário sem validação de tipo (500 por `TypeError`)
**Onde:** `code/index.php:22` (`autenticar($db, $_POST['login'], ...)`),
`code/index.php:52-53` (`(int) $_GET['ver']`) e `code/index.php:72`
(`listarChamados($db, $busca)`).

**Mecanismo:** o PHP só garante o tipo quando o valor é escalar; um parâmetro
de query como `?busca[]=x` ou `?ver[]=1` entrega um **array**, e
`autenticar(mysqli, string, string)` / `listarChamados(mysqli, string)`
com type hint `string` lançam `TypeError` → erro fatal 500 com stack trace
no PHP 8 (o `??`/`(int)` do código original protegia só o `ver`, e mesmo
assim `(int) $_GET['ver']` num array gera aviso). É um vetor de negação de
serviço trivial e um vazamento de information disclosure (caminho do código,
versão do PHP).

**Impacto:** 500 fácil de provocar por qualquer visitante e exposição de
detalhes internos do PHP. **Severidade: baixa. Confiança: 85.**

**O que fiz:** toda entrada externa passa por `is_string()` antes de chegar
às funções tipadas (`index.php:32-33`, `index.php:72`, `index.php:92`), e
o `index.php` envolve o fluxo em `try/catch (Throwable)` (`index.php:29`,
`index.php:118-125`), registrando no log e devolvendo mensagem genérica, sem
stack trace. Verificado: `?busca[]=x`, `?ver=abc`, `?ver[]=1` e
`?export=csv&ver[]=2` respondem 200 sem erro fatal.

---

### F13 — Sessão sem `HttpOnly`/`SameSite` e sem rotação no login
**Onde:** `code/index.php:15` (`session_start()`) e `code/index.php:24-26`
(gravação de `$_SESSION['uid']`).

**Mecanismo:** `session_start()` foi chamado sem parametrizar o cookie: no
PHP 8 o default é `httponly=0`, ou seja, **JavaScript da página lê o cookie
de sessão** (o que, junto com F5, dá roubo de sessão trivial). Também não
havia `session_regenerate_id()` no login: o identificador de sessão anterior
(anônimo, antes do login) continuava válido depois da autenticação —
fixação de sessão, se um atacante conseguir plantar um id de sessão na
vítima (por exemplo via `?busca=` com link malicioso).

**Impacto:** roubo de sessão e fixação de sessão. **Severidade: média.
Confiança: 85.**

**O que fiz:** o cookie é criado com `httponly=true`, `samesite=Lax` e
`secure` conforme o esquema (`index.php:16-24`), e o login passa a chamar
`session_regenerate_id(true)` antes de gravar a sessão (`index.php:36`).
Verificado: o cookie emitido traz `HttpOnly`.

---

### F14 — Erro do mysqli vira stack trace (comportamento do PHP 8.1+)
**Onde:** todas as consultas de `code/lib.php` (antes: `lib.php:16-20`,
`lib.php:69`, `lib.php:100`, `lib.php:109`, `lib.php:132`).

**Mecanismo:** a partir do PHP 8.1, `mysqli` reporta erros por **exceção**
(`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` é o default) e
não mais por `false`. O código legado checava `if ($res === false)`, condição
que nunca é alcançada: um erro de SQL (tabela fora do ar, deadlock, coluna
inexistente) estoura `mysqli_sql_exception` não capturada → 500 com stack
trace e trecho de SQL na resposta. Verifiquei isso no ambiente: um `INSERT`
duplicado na base de teste lançou a exceção sem tratamento.

**Impacto:** indisponibilidade não graceful + exposição de estrutura do banco
e de caminhos do sistema em erro. **Severidade: média. Confiança: 90.**

**O que fiz:** cada acesso ao banco nas funções de `lib.php` está dentro de
`try { ... } catch (mysqli_sql_exception $e)`, que registra o erro no
`error_log` e devolve o valor neutro da função (`null`, `[]`, `0.0` ou erro
de export 500). O `index.php` também tem um `catch (Throwable)` final. O modo
de relatório do mysqli **não** foi alterado globalmente (para não mudar o
comportamento de erro dos outros scripts do ISP que include `lib.php`).

---

### F15 — Cabeçalho do CSV divergia do cabeçalho exato declarado
**Onde:** `code/lib.php:130` (`fputcsv($fp, ['ID','Titulo','Status','Tecnico','Aberto em'])`).

**Mecanismo:** o manifesto declara o cabeçalho **exato**
`ID,Titulo,Status,Tecnico,Aberto em`, mas `fputcsv()` coloca aspas em
campos que contêm espaço, e "Aberto em" contém espaço: o arquivo saía com
`ID,Titulo,Status,Tecnico,"Aberto em"`. É o mesmo conjunto de campos e
qualquer leitor de CSV interpreta igual, mas o texto não é o que o manifesto
declara — quem casa o cabeçalho por string (rotina noturna, relatório
gerencial, `grep`, `awk -F,` em campo 5) vê a diferença.

**Impacto:** divergência entre o artefato produzido e o contrato declarado;
falha de consumers que comparam o cabeçalho por string. **Severidade: baixa.
Confiança: 85.**

**O que fiz:** o cabeçalho é escrito literalmente
(`fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n")`,
`code/lib.php:403-406`), e as linhas de dados continuam com `fputcsv()`
(necessário para títulos com vírgula, aspas ou quebras de linha). Verificado:
primeira linha do CSV = `ID,Titulo,Status,Tecnico,Aberto em`; 5 linhas de
dados ordenadas por id crescente (101…105) para o técnico, 3 para o cliente.

---

### F16 — Busca com `LIKE '%termo%'` não usa índice (mantido)
**Onde:** `code/lib.php:82` (na versão original) / `code/lib.php:255-258`
(atual).

**Mecanismo:** o padrão `LIKE '%termo%'` (wildcard à esquerda) não pode usar
índice B-tree; com muitos chamados a busca vira varredura da tabela. A
alternativa correta é índice FULLTEXT (ou uma coluna de busca gerenciada),
que exige mudança de schema e reindexação.

**Impacto:** latência crescente na busca em bases grandes. **Severidade:
baixa. Confiança: 90.**

**O que fiz:** **não** corrigi (ver §4). A query continua um `LIKE`
parametrizado, sem a injeção e sem o wildcard indevido (F1).

## 3. Resumo dos achados

| id | título | arquivo | linha | categoria | severidade | confiança | corrigido |
| --- | --- | --- | --- | --- | --- | --- | --- |
| F1 | Injeção de SQL na busca por título | `code/lib.php` | 82 | seguranca | critica | 100 | sim |
| F2 | Regra de visibilidade (cliente x chamado) não aplicada | `code/index.php` | 73 | seguranca | alta | 98 | sim |
| F3 | Senhas em MD5 sem sal | `code/lib.php` | 15 | seguranca | alta | 100 | sim |
| F4 | Segredos de produção no repositório | `code/config.php` | 12 | seguranca | alta | 100 | sim |
| F5 | XSS refletido no parâmetro `busca` | `code/index.php` | 79 | seguranca | alta | 97 | sim |
| F6 | Divisão por zero no indicador de SLA | `code/lib.php` | 116 | bug | alta | 92 | sim |
| F7 | N+1 na listagem e na exportação | `code/lib.php` | 89 | performance | media | 97 | sim |
| F8 | Arquivo de exportação previsível dentro da árvore web | `code/lib.php` | 125 | seguranca | media | 75 | sim |
| F9 | SQL por concatenação em `tecnicoNome()` e `verChamado()` | `code/lib.php` | 69 | seguranca | baixa | 90 | sim |
| F10 | `rotuloPrioridade()` rotula crítico como "Alto" | `code/lib.php` | 44 | bug | baixa | 60 | sim |
| F11 | Falha do export não chega ao cliente | `code/lib.php` | 126 | bug | media | 90 | sim |
| F12 | Entrada de usuário sem validação de tipo (500 por `TypeError`) | `code/index.php` | 22 | bug | baixa | 85 | sim |
| F13 | Sessão sem `HttpOnly`/`SameSite` e sem rotação no login | `code/index.php` | 15 | seguranca | media | 85 | sim |
| F14 | Erro do mysqli vira stack trace (PHP 8.1+) | `code/lib.php` | 16 | qualidade | media | 90 | sim |
| F15 | Cabeçalho do CSV divergia do cabeçalho exato declarado | `code/lib.php` | 130 | bug | baixa | 85 | sim |
| F16 | Busca com `LIKE '%termo%'` não usa índice | `code/lib.php` | 82 | performance | baixa | 90 | não |

## 4. Decisões — o que eu deliberadamente NÃO mudei

1. **Não alterei as assinaturas públicas.** `autenticar`, `formatarStatus`,
   `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e
   `exportarCsv` mantêm nome, parâmetros, tipos e retorno idênticos — o
   manifesto é explícito quanto a isso, e os scripts do ISP chamam essas
   funções. Em vez de acrescentar um parâmetro `?int $usuarioId` a
   `listarChamados`/`exportarCsv` (o que mudaria a assinatura pública), criei
   funções novas (`listarChamadosVisiveis`, `exportarCsvVisivel`) e mantive as
   públicas delegando para elas **sem filtro** — assim a rotina noturna de
   exportação e o relatório gerencial continuam recebendo o conjunto
   completo, enquanto a interface web aplica a regra de visibilidade.
2. **Não apliquei o filtro de dono a `mediaResposta()`.** O indicador "Tempo
   medio de 1a resposta" é uma média agregada do painel (uma linha de KPI),
   não a listagem de chamados; filtrá-lo por usuário mudaria o número do KPI
   do painel e a leitura da equipe, e não há conteúdo de chamado em ele. A
   assinatura pública também é `mediaResposta(mysqli $db): float`, sem lugar
   para um filtro. Decisão: manter global e documentada.
3. **Não troquei o CSV do cliente pelo CSV completo.** A rota
   `index.php?export=csv` agora respeita a visibilidade: um cliente baixa
   apenas os próprios chamados. Isso é exigência do manifesto ("uma correção
   que exponha chamados a quem não tem altera a regra de negócio"), e o
   formato do arquivo (cabeçalho, ordem por `id`, rótulos de status) segue
   idêntico. A rotina interna que chama `exportarCsv($db)` diretamente
   continua recebendo todos os chamados — foi por isso que mantive a função
   pública sem filtro.
4. **Não deixei mais o arquivo `chamados.csv` no disco** depois do download
   (F8). É uma mudança de comportamento observável: se a rotina noturna do
   ISP coleta o arquivo de `/var/www/painel/tmp/chamados.csv` em vez de
   consumir a saída do script, ela precisa passar a ler o arquivo temporário
   ou a rota. Em compensação, `EXPORT_DIR` passou a ser configurável por
   variável de ambiente, para a infraestrutura apontá-lo para fora da
   árvore publicada — não mudei o valor padrão para não alterar o ambiente de
   quem não pediu.
5. **Não mudei a pilha nem a camada de dados.** Continua PHP + `mysqli` +
   MySQL, sem ORM, sem framework, sem dependência nova. `composer.json`,
   `package.json` etc. não foram criados — o projeto não tem.
6. **Não reescrevi o sistema nem troquei o HTML.** A listagem continua
   emitting as mesmas tags, na mesma ordem, com `id="tabela-chamados"` e o ID
   como link para `index.php?ver=<id>`; a rota de detalhe e a de login
   continuam com os mesmos parâmetros (`busca`, `ver`, `export`). As
   mudanças são pontuais, dentro do código existente.
7. **Não implementei token CSRF.** O painel é somente leitura: não existe
   nenhuma operação de escrita (criar/editar/excluir chamado), logo não há
   estado no servidor que um CSRF possa alterar. O único POST é o login. Um
   token exigiria estado de sessão adicional sem proteger nada hoje — e
   quebraria clientes que autenticam por formulário automatizado. Se um dia
   houver escrita, é o primeiro item a entrar.
8. **Não adicionei CSP/X-Frame-Options/HSTS.** O painel não usa JavaScript
   nem frames, e uma CSP rígida pode quebrar a página legada (inclusive os
   links de `favicon`/estilos externos que não estão no pacote) sem
   trazer benefício mensável aqui. É uma decisão de infraestrutura de
   front-end, não de correção de código.
9. **Não troquei `LIKE '%termo%'` por FULLTEXT** (F16): exige migração de
   schema, reindexação e muda a semântica da busca (tokenização, tolerância
   a erro de digitação), com risco de resultado diferente do que os usuários
   esperam hoje. Fica como recomendação.
10. **Não alterei a semântica do SLA de 30 minutos** para prioridades
    inferiores a 3 (F10): o código original — e o desenho do produto — trata
    o SLA como aplicável às prioridades alta/crítica. Só corrigi o
    rotulamento claramente incorreto (crítico dentro do SLA aparecendo como
    "Alto"), preservando todos os rótulos já existentes.
11. **Não declarei `strict_types=1` nem mudei `mysqli_report()`.** Ambos
    alteram o comportamento de coerção de tipos e de erro para *todos* os
    scripts do ISP que include `lib.php` — um risco de compatibilidade maior
    que o benefício, e fora do escopo dos problemas encontrados.
12. **Não troquei o formato do `SELECT *`** de `verChamado()` nem de
    `listarChamados()`: os consumidores podem confiar no conjunto de colunas
    devolvido (o manifesto exige a chave `tecnico_nome` em cada item da
    listagem). A listagem passou a incluir o `LEFT JOIN` do técnico, e a
    chave `tecnico_nome` continua presente e com o mesmo valor (`'-'` para
    chamado sem técnico).

## 5. Verificação executada

Ambiente: PHP 8.4.26 com `mysqli`, MariaDB 11.8 local (instância isolada),
`code/schema.sql` + `code/seed.sql` carregados.

- `php -l` em `config.php`, `index.php`, `lib.php`: sem erro de sintaxe.
- **38 asserções de biblioteca** (`/tmp/lebtest/test_lib.php`): login com
  bcrypt e com hash MD5 legado, senha errada, usuário inexistente, migração
  MD5→bcrypt, rótulos de status (incluindo valor fora da faixa), rótulos de
  prioridade, listagem completa e com busca, `tecnico_nome` (inclusive
  `'-'`), payloads de SQLi inertes, `%` e `_` tratados como literais, filtro
  de visibilidade por dono (3/2/5 chamados), detalhe de chamado existente e
  inexistente, `mediaResposta` com e sem amostra.
- **Fluxo HTTP real, 32 asserções** (`php -S` + `curl`, `/tmp/lebtest/test_http.sh`):
  login com redirecionamento e cookie `HttpOnly`, listagem com
  `id="tabela-chamados"` e colunas na ordem do manifesto, cliente vendo
  somente os próprios chamados (e não vendo os alheios, nem na listagem, nem
  no detalhe, nem no CSV), técnico vendo todos, XSS refletido escapado, SQLi
  sem vazamento, parâmetros quebrados (`busca[]`, `ver[]`, `ver=abc`) sem
  erro fatal, CSV com cabeçalho exato, 5 linhas ordenadas por `id` para o
  técnico e 3 para o cliente, `Content-Type`/`Content-Disposition`
  preservados e nenhum arquivo de exportação deixado no disco.
- **Cenário de migração em base legada**: base com a coluna antiga
  `CHAR(32)` e hashes MD5 — em modo estrito e em modo não estrito o login
  continua funcionando e o hash MD5 é preservado (sem trancar o usuário);
  com a coluna já em `VARCHAR(255)`, o hash migra para bcrypt no primeiro
  login.
- **Papel lido do banco**: rebaixando `carla` de `tecnico` para `cliente` no
  banco durante uma sessão ativa, a listagem dela passa de 5 para 0 chamados
  (e volta a 5 ao restaurar o papel) — a regra de visibilidade não depende
  de dado guardado na sessão.

## 6. Passos de implantação recomendados

1. `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` (necessário
   para a migração de hash; o login funciona mesmo sem isso).
2. Exportar `DB_PASS` e `SMTP_API_KEY` no serviço do painel (ou gravar
   `/etc/painel/db.pass` e `/etc/painel/smtp.key`) e **rotacionar** os dois
   valores — os antigos estão no histórico do repositório.
3. (Opcional) Apontar `EXPORT_DIR` para fora da árvore publicada.
4. Recarregar o `seed.sql` apenas em ambientes de teste (a base de produção
   mantém os hashes MD5, migrados no próximo login de cada usuário).
