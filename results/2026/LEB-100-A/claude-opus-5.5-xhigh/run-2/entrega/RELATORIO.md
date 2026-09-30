# Relatório técnico — Painel de Chamados (LEB-100-A)

## 1. Resumo

O painel funciona, mas **não aplica a regra de negócio central do manifesto**: qualquer cliente logado lê
todos os chamados de todos os clientes, pela listagem, pelo detalhe (`?ver=`) e pelo export CSV. Além
disso, a busca tem **injeção de SQL** (reproduzida extraindo login e hash de senha de todos os usuários) e
**XSS refletido**. As senhas estão em **md5 sem sal**, a senha do banco de produção e a chave SMTP estão
**no código**, o login permite **fixação de sessão**, e a página principal **cai com HTTP 500** quando
nenhum chamado tem tempo de resposta (divisão por zero).

Corrigi 16 dos 23 achados com mudanças pontuais em `lib.php`, `index.php`, `config.php` e `schema.sql`.
Assinaturas, rotas, parâmetros, formato do CSV, HTML e rótulos ficaram intactos. A saída das 7 funções
públicas é idêntica à original (comparada campo a campo) e, para um técnico, todas as rotas respondem
exatamente como antes. Os 7 que não corrigi estão justificados na seção 3.

Numeração de linhas: sempre a do arquivo **original** recebido.

## 2. Achados (em ordem de prioridade)

### F1 — Injeção de SQL pelo parâmetro `busca`
- **Onde:** `code/lib.php:82` (`listarChamados`), alimentado por `code/index.php:72`.
- **Mecanismo:** `$_GET['busca']` chega sem tratamento e é concatenado dentro de `LIKE '%...%'`. Uma aspa fecha
  o literal. Com `busca=zzz' UNION SELECT id,id,NULL,login,senha,1,1,NULL,NOW() FROM usuarios -- `, a
  listagem devolve login e hash de senha dos quatro usuários como se fossem chamados (reproduzido no
  original). Uma aspa legítima (`O'Brien`) gera `mysqli_sql_exception` e derruba a página.
- **Impacto:** qualquer usuário logado, inclusive cliente, lê qualquer tabela; com F7, os hashes viram senhas.
  **Severidade: crítica. Confiança: 99.**
- **O que fiz:** o termo passa por `$db->real_escape_string()` (a conexão está em utf8mb4 via `set_charset`).
  Não usei prepared statement **de propósito**: `get_result()` de prepared statement devolve colunas INT como
  `int` nativo, e o array retornado por `listarChamados`, consumido por outros scripts, deixaria de ter
  strings em `id`, `status` etc. Verificado: o payload UNION não retorna nada, `O'Brien` funciona e a saída é
  idêntica à original para 6 termos de busca.

### F2 — Cliente abre qualquer chamado por `?ver=<id>` (IDOR)
- **Onde:** `code/index.php:53-66`.
- **Mecanismo:** o detalhe chama `verChamado($db, (int) $_GET['ver'])` e imprime título e descrição sem
  comparar `usuario_id` com o usuário da sessão. Os ids são sequenciais. Reproduzido: `ana` (dona de 101, 102
  e 105) abre 103 e 104, que são do `bruno`.
- **Impacto:** vazamento das descrições (cobrança, cartão, endereço) entre clientes. **Alta. Confiança: 98.**
- **O que fiz:** depois de carregar o chamado, o `index.php` chama `podeVerChamado($c, $uid, $papel)`. Se o
  chamado não for visível, a resposta é **exatamente a de id inexistente** (`Chamado nao encontrado.`), então
  não revela que o id existe. `verChamado` continua sem filtro: não recebe usuário e outros scripts a usam.

### F3 — Listagem mostra os chamados de todos os clientes
- **Onde:** `code/index.php:73` → `code/lib.php:78-93`.
- **Mecanismo:** `listarChamados` faz `SELECT * FROM chamados` sem filtro de dono e é chamada para qualquer
  papel. A regra "cliente só vê os próprios" não está implementada em lugar nenhum, e a busca também percorre
  chamados alheios. É assim que o cliente descobre os ids para F2.
- **Impacto:** cliente vê título, status, prioridade e técnico da base toda. **Alta. Confiança: 98.**
- **O que fiz:** criei `listarChamadosVisiveis($db, $uid, $papel, $busca)`. Técnico recebe exatamente
  `listarChamados`; qualquer outro papel (**negação por padrão**) recebe a mesma consulta com
  `WHERE c.usuario_id = <uid>`. Verificado: a saída de `carla` é idêntica; `ana` vê só 105, 102 e 101.

### F4 — Export CSV entrega a base inteira a qualquer cliente
- **Onde:** `code/index.php:45`.
- **Mecanismo:** `?export=csv` chama `exportarCsv($db)`, que exporta todos os chamados para qualquer sessão
  válida. O link aparece também para clientes.
- **Impacto:** um clique entrega ao cliente todos os chamados de todos os clientes. **Alta. Confiança: 97.**
- **O que fiz:** criei `exportarCsvVisiveis($db, $uid, $papel)`, com o mesmo formato (cabeçalho, ordem por
  `id`, rótulos de `formatarStatus`) e filtro por dono para clientes. Técnico recebe byte a byte o que
  recebia. `exportarCsv` não mudou de comportamento, porque a rotina noturna precisa de tudo. Essa correção
  **depende de F8**: com o arquivo compartilhado, um cliente podia receber o export completo de um técnico
  que exportasse ao mesmo tempo.

### F5 — XSS refletido pelo parâmetro `busca`
- **Onde:** `code/index.php:79` (dentro de `value="..."`) e `code/index.php:82` (`Resultados para:`).
- **Mecanismo:** `$busca` é impresso sem escape. `?busca="><script>alert(1)</script>` fecha o atributo e
  executa script (reproduzido). O parâmetro vai na URL e os links externos fazem parte do contrato, então
  basta mandar um link a um técnico.
- **Impacto:** com o cookie sem HttpOnly (F10), roubo da sessão de um técnico e acesso a toda a base; também é
  o vetor natural para plantar o id de F9. **Alta. Confiança: 97.**
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos.

### F6 — Senha do banco de produção e chave SMTP no código
- **Onde:** `code/config.php:12` e `code/config.php:15`.
- **Mecanismo:** `DB_PASS` cai no literal `N3tX@2013!prod` sempre que a variável de ambiente não existe (o
  comentário diz que é a senha de produção), e `SMTP_API_KEY` é um literal. Qualquer cópia do código
  (repositório, backup, este próprio pacote) carrega credenciais válidas.
- **Impacto:** acesso ao banco de produção (se a rede permitir) e envio de e-mail em nome do ISP, útil para
  phishing contra clientes. **Alta. Confiança: 95.**
- **O que fiz:** as duas constantes (mesmos nomes) agora vêm só do ambiente. Sem `DB_PASS`, a conexão falha
  com a mensagem genérica de sempre e o motivo vai para o log. **As credenciais precisam ser trocadas**,
  porque já vazaram. Isso tem pré-requisito de deploy (seção 4).

### F7 — Senhas em md5 sem sal
- **Onde:** `code/lib.php:15-16`; coluna `senha CHAR(32)` em `code/schema.sql:7`.
- **Mecanismo:** md5 não tem sal e é rápido. Senhas iguais geram o mesmo hash (`ana` e `bruno` têm
  `e7d80ffe…`, que é `md5('senha123')`), e hashes extraídos via F1 se recuperam em segundos com tabela
  pronta ou GPU.
- **Impacto:** vazar a tabela `usuarios` equivale a vazar as senhas em claro, que os clientes reaproveitam em
  outros serviços. **Alta. Confiança: 95.**
- **O que fiz:** migração transparente e **condicionada ao schema**:
  - `autenticar` busca pelo login e verifica. Se o hash for de `password_hash`, usa `password_verify`; senão,
    compara com md5 via `hash_equals`, sem diferenciar maiúsculas, como a comparação no SQL fazia.
  - Com a senha md5 conferida, regrava com `password_hash` **só se** a coluna já tiver 255 caracteres. O
    `ALTER` está documentado em `schema.sql`, que também foi atualizado. **Sem o ALTER, nada é regravado** e
    o comportamento é o de antes.
  - Erro na regravação (por exemplo, usuário do banco sem permissão de UPDATE) vai para o log e **não impede o
    login**. Testei com um usuário só com SELECT.
  - Retorno idêntico nos dois schemas (testado).
  - **Resíduo:** quem não fizer login continua com md5 (ver Decisões). Depois do bcrypt, login inexistente
    responde mais rápido que login existente (enumeração por tempo, baixo risco).

### F8 — Export grava a base inteira num arquivo fixo e compartilhado
- **Onde:** `code/lib.php:125-150`.
- **Mecanismo:** todo export escreve `EXPORT_DIR/chamados.csv`, com o mesmo nome para todos, e depois faz
  `readfile`.
  - (a) Com duas exportações simultâneas, o `fopen('w')` de uma trunca o arquivo enquanto a outra lê; o
    download sai truncado ou misturado.
  - (b) O dump completo fica em disco para sempre. `/var/www/painel/tmp` sugere pasta dentro da raiz web, e
    aí o arquivo seria baixável sem login em `/tmp/chamados.csv`. Essa parte depende do docroot, que não
    conheço, e por isso a confiança do achado é 85.
  - (c) Se o diretório não existe ou não tem permissão, `fopen` falha e a função retorna em silêncio: HTTP
    200 com corpo vazio (reproduzido). Se a query falha, retorna sem `fclose`.
- **Impacto:** exposição da base; export corrompido sob concorrência; export vazio sem erro.
  **Média. Confiança: 85.**
- **O que fiz:** o CSV vai direto para `php://output`, com os mesmos bytes. Quem captura a saída com
  `ob_start` continua funcionando (é assim que a caracterização compara). Os cabeçalhos HTTP só saem depois
  de a consulta dar certo. `EXPORT_DIR` continua definida.

### F9 — Fixação de sessão
- **Onde:** `code/index.php:23-27`.
- **Mecanismo:** no login, `uid` e `papel` são gravados na sessão que já existia, sem trocar o id. Com
  `session.use_strict_mode=0` (padrão do PHP e do ambiente testado), o PHP aceita um id escolhido pelo
  cliente. Quem planta `PHPSESSID=X` no navegador da vítima fica autenticado quando ela faz login.
  Reproduzido no original.
- **Impacto:** sequestro da conta de um técnico sem saber a senha. **Média. Confiança: 85.**
- **O que fiz:** `session.use_strict_mode=1` antes de `session_start()` e `session_regenerate_id(true)` logo
  após autenticar. Verificado: o id plantado é recusado e não fica autenticado.

### F10 — Cookie de sessão sem HttpOnly/SameSite/Secure
- **Onde:** `code/index.php:15`.
- **Mecanismo:** `session_start()` usa os padrões do php.ini (HttpOnly desligado, sem SameSite, sem Secure),
  então o script injetado via F5 lê `document.cookie`. Não recebi o php.ini de produção, daí a confiança
  menor.
- **Impacto:** transforma F5 em roubo de sessão. **Baixa. Confiança: 70.**
- **O que fiz:** HttpOnly, SameSite=**Lax** e Secure quando a requisição vier por HTTPS. Não usei Strict
  porque aí o navegador não envia o cookie ao seguir **links externos** para `?ver=`/`?busca=`, e o usuário
  apareceria deslogado (o contrato cita links e favoritos externos).

### F11 — Login sem limite de tentativas
- **Onde:** `code/index.php:21-29`.
- **Mecanismo:** tentativas ilimitadas, sem atraso, bloqueio ou registro. Com verificação md5 barata e senhas
  fracas (as do seed são `senha123`/`tecmaster`), força bruta online é viável.
- **Impacto:** adivinhar a senha de um técnico dá acesso a tudo. **Média. Confiança: 85.**
- **O que fiz:** **não corrigi.** Exige guardar tentativas por login/IP (tabela nova ou cache) ou fail2ban
  sobre um log de falhas; é mudança de schema e de operação que merece decisão própria.

### F12 — `mediaResposta` divide por zero e derruba a listagem
- **Onde:** `code/lib.php:116`.
- **Mecanismo:** sem nenhum chamado com `minutos_resposta` (base nova, ou todos aguardando), `$qtd` fica 0 e
  `$soma / $qtd` lança `DivisionByZeroError` no PHP 8. Como a listagem chama a função em toda carga
  (`index.php:74`), a **página principal inteira responde HTTP 500** (reproduzido). O relatório gerencial que
  usa a função também quebra.
- **Impacto:** painel fora do ar. **Média. Confiança: 95.**
- **O que fiz:** retorna `0.0` quando não há chamados respondidos; nos demais casos o valor é idêntico.

### F13 — N+1 consultas para o nome do técnico
- **Onde:** `code/lib.php:89` e `code/lib.php:137` (chamando `tecnicoNome`, linhas 64-72).
- **Mecanismo:** um `SELECT` por chamado, ou seja, 1 + N consultas por listagem e por export. O export da base
  de produção (em uso desde 2013) faz uma ida ao banco por chamado.
- **Impacto:** latência e carga crescendo com o histórico. **Média. Confiança: 95.**
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(t.nome, '-')`, que dá o mesmo `'-'` para chamado sem técnico
  ou técnico inexistente. `tecnico_nome` continua na mesma posição e com o mesmo tipo. `tecnicoNome` foi
  mantida para outros scripts.

### F14 — `mediaResposta` traz todas as linhas para somar no PHP
- **Onde:** `code/lib.php:109-115`.
- **Mecanismo:** em toda carga da listagem, cada chamado respondido é transferido e somado num laço.
- **Impacto:** transferência e memória desnecessárias. **Baixa. Confiança: 90.**
- **O que fiz:** `SUM`/`COUNT` no SQL e divisão no PHP. O float resultante é idêntico ao anterior (comparado).

### F15 — Injeção de fórmula no CSV
- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** o título, texto livre do cliente, vai cru para o CSV. Um título começando com `=`, `+`, `-` ou
  `@` vira fórmula ao abrir o arquivo no Excel/LibreOffice (`=HYPERLINK(...)`, ou DDE em versões antigas).
- **Impacto:** fórmula ou link malicioso executado na máquina de quem abre o relatório.
  **Média. Confiança: 65.**
- **O que fiz:** **não corrigi.** Prefixar essas células muda o conteúdo que a integração de faturamento e a
  rotina noturna leem. Proposta: validar o título na abertura do chamado ou criar um export separado "para
  planilha".

### F16 — Falha de conexão no PHP 8.1+ vira erro fatal com stack trace
- **Onde:** `code/index.php:9-12`.
- **Mecanismo:** desde o PHP 8.1, `new mysqli` lança `mysqli_sql_exception` e o `if ($db->connect_errno)`
  nunca roda. O resultado é erro fatal com stack trace (host e usuário), visível antes do login se
  `display_errors` estiver ligado.
- **Impacto:** página em branco ou 500 em vez da mensagem prevista; detalhes de infraestrutura expostos.
  **Baixa. Confiança: 85.**
- **O que fiz:** `try/catch` com log e a mesma mensagem genérica. O `if` antigo ficou para PHP anterior ao 8.1.

### F17 — Parâmetro enviado como array derruba a página
- **Onde:** `code/index.php:22` (`login[]=x`, sem precisar de login) e `code/index.php:72` (`busca[]=x`).
- **Mecanismo:** um array chega a um parâmetro `string` de `autenticar`/`listarChamados` e gera `TypeError`
  não tratado: HTTP 500 com stack trace no log ou na tela.
- **Impacto:** 500 e ruído de log à vontade; caminhos internos expostos com `display_errors`.
  **Baixa. Confiança: 90.**
- **O que fiz:** `is_string()` antes de usar `login`, `senha` e `busca`; valor inválido é tratado como ausente.

### F18 — `fputcsv` sem `$escape` emite aviso de depreciação no PHP 8.4
- **Onde:** `code/lib.php:130` e `code/lib.php:138`.
- **Mecanismo:** no PHP 8.4 (a versão disponível aqui), omitir `$escape` gera um aviso "Deprecated" por linha.
  Com `display_errors` ligado, o aviso sai **antes** de `header()`, o PHP reclama de "headers already sent", e
  o CSV chega como `text/html` com o aviso no corpo. Com ele desligado, o log recebe uma linha por chamado.
- **Impacto:** export quebrado ou log inundado após atualizar o PHP. **Baixa. Confiança: 60.**
- **O que fiz:** passo `','`, `'"'` e `'\\'` explicitamente. São os valores padrão, então os bytes não mudam
  (verificado).

### F19 — `formatarStatus` rotula status desconhecido como "Resolvido"
- **Onde:** `code/lib.php:32-34`.
- **Mecanismo:** tudo que não é 1 nem 2 cai no `else`. `status` é TINYINT sem CHECK, então um valor inválido
  aparece como "Resolvido" na tela, no CSV e no relatório gerencial, que casa pelo texto.
- **Impacto:** chamado pendente com dado corrompido some da contagem de abertos. **Baixa. Confiança: 55.**
- **O que fiz:** **não corrigi.** Para 1, 2 e 3 a função cumpre o contrato; mudar a saída dos outros valores
  cria um rótulo que os consumidores não conhecem. Proposta: auditar com
  `SELECT status, COUNT(*) FROM chamados GROUP BY status` e então adicionar `CHECK (status IN (1,2,3))`.

### F20 — `rotuloPrioridade` não destaca chamado crítico sem resposta
- **Onde:** `code/lib.php:42-58`.
- **Mecanismo:** sem `minutos_resposta`, tudo vira "Aguardando 1a resposta", qualquer que seja a prioridade.
  O 104 (prioridade 4, sem técnico) aparece igual a um de prioridade baixa. Prioridade 4 dentro do SLA vira
  "Alto - dentro do SLA", e prioridade 1 vira "Normal".
- **Impacto:** o chamado mais urgente não se destaca. **Baixa. Confiança: 35** (pode ser intencional).
- **O que fiz:** **não corrigi.** Rótulo é saída consumida; mudá-lo é decisão de produto.

### F21 — Papel em cache na sessão e sem logout
- **Onde:** `code/index.php:25` e `code/index.php:39`.
- **Mecanismo:** o papel é gravado no login e vale até a sessão expirar. Rebaixar ou remover o usuário no banco
  não afeta sessões abertas, e não há rota para encerrar a sessão.
- **Impacto:** ex-técnico continua vendo tudo até a sessão expirar. **Baixa. Confiança: 60.**
- **O que fiz:** **não corrigi.** Exige uma consulta extra por requisição e uma rota nova fora do contrato.

### F22 — Listagem sem paginação e busca com varredura completa
- **Onde:** `code/lib.php:80-85`.
- **Mecanismo:** cada acesso traz a tabela inteira, e `LIKE '%termo%'` não usa índice.
- **Impacto:** página cada vez mais lenta para técnicos. **Baixa. Confiança: 50.**
- **O que fiz:** **não corrigi.** Paginar muda o que a listagem mostra, que é contrato. Para clientes, o novo
  filtro por `usuario_id` já usa `idx_usuario`.

### F23 — `index.php` concentra conexão, sessão, rotas, autorização e HTML concatenado
- **Onde:** `code/index.php:20-97`.
- **Mecanismo:** não há camada de autorização nem de template. Cada `echo` cuida do próprio escape (F5 nasceu
  disso), e a regra de visibilidade não existia em lugar nenhum (F2 a F4 nasceram disso).
- **Impacto:** cada tela nova repete os mesmos riscos. **Baixa. Confiança: 70.**
- **O que fiz:** não reestruturei, porque seria reescrita. O passo que dei: a regra de visibilidade agora está
  num só lugar (`donoVisivel`/`podeVerChamado` em `lib.php`) e as três rotas a usam.

## 3. Decisões — o que deliberadamente NÃO mudei

1. **Assinaturas e retornos das 7 funções públicas.** Nenhuma ganhou parâmetro de usuário. `listarChamados`,
   `verChamado` e `exportarCsv` continuam **sem filtro**, porque a rotina noturna e o relatório gerencial
   precisam da base inteira. Filtrar dentro delas esconderia chamados de quem tem direito, o que também muda
   a regra de negócio. O filtro fica no ponto de entrada, com funções **novas**: `listarChamadosVisiveis`,
   `exportarCsvVisiveis`, `podeVerChamado` e `donoVisivel`.
2. **Não usei prepared statements na listagem e no export.** Mudariam os tipos do array retornado (int em vez
   de string). O escape com `real_escape_string` numa conexão utf8mb4 é seguro. `autenticar` já usava
   prepared statement e continua usando, com os mesmos tipos de antes.
3. **Cabeçalho do CSV.** O que o sistema entrega hoje é `ID,Titulo,Status,Tecnico,"Aberto em"`: o `fputcsv`
   põe aspas por causa do espaço, e um parser CSV lê `Aberto em`. Mantive byte a byte.
4. **`formatarStatus` e `rotuloPrioridade`** (F19, F20): contrato de valor consumido pelo relatório gerencial.
5. **Injeção de fórmula no CSV** (F15): sanitizar altera o que a integração de faturamento lê.
6. **Limite de tentativas, logout, revalidação de papel e paginação** (F11, F21, F22): exigem schema, rota ou
   comportamento novos.
7. **Curingas `%` e `_` na busca** continuam funcionando como curingas, que é o comportamento atual.
8. **Tempo médio global também para clientes:** é o indicador do painel, agregado, e não expõe chamado algum.
9. **`EXPORT_DIR`** continua definida, porque outros scripts podem usá-la; só `exportarCsv` deixou de gravar
   nela. Se algum script lê `EXPORT_DIR/chamados.csv`, deve passar a redirecionar a saída
   (`php rotina.php > arquivo`).
10. **`tecnicoNome` e `verChamado`** continuam concatenando SQL: os parâmetros são `?int`/`int`, então é seguro.
11. **Resposta de chamado inexistente ou proibido** continua HTTP 200 com o mesmo corpo. Os dois casos ficam
    indistinguíveis, e mudar o status HTTP afetaria links e monitores sem ganho de segurança.
12. **Token CSRF no login e cabeçalhos CSP/X-Frame-Options:** não há ação de escrita além do login, e
    X-Frame-Options poderia quebrar a incorporação por algum sistema do ISP que não conheço.
13. **Hashes md5 de quem não fizer login após o ALTER:** convertê-los (por exemplo, `password_hash` sobre o
    md5) ou forçar reset de senha é operação de DBA, fora do código.

## 4. Pré-requisitos de deploy (operação)

1. **Antes de publicar**, definir `DB_PASS` e `SMTP_API_KEY` no ambiente do servidor web (no PHP-FPM:
   `env[DB_PASS]=…` no pool ou `clear_env = no`) **e no ambiente do cron** das rotinas que incluem
   `config.php`. Sem isso, o painel mostra "Falha ao conectar ao banco." e o log diz por quê.
2. **Trocar** a senha do usuário `painel` e a chave SMTP, porque as antigas circularam com o código.
3. **Apagar** `/var/www/painel/tmp/chamados.csv`, verificar se `tmp/` é servido pela web e, se for, revisar
   os logs de acesso a esse arquivo.
4. **Para ativar a migração de senha:** `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`, depois de
   confirmar que nenhum outro sistema autentica com md5 contra `usuarios.senha`. A regravação precisa de
   UPDATE nessa tabela; sem a permissão, o login continua funcionando e registra um aviso no log.

## 5. Como verifiquei

Ambiente: PHP 8.4 com mysqli/mysqlnd e MariaDB 11.8 local, carregado com `schema.sql` + `seed.sql`.

- **Caracterização das funções públicas:** chamei as 7 funções no código original e no novo e comparei a
  saída campo a campo, incluindo tipos: 8 logins, `formatarStatus` 0–4 e -1, `rotuloPrioridade` 6×5, 6
  buscas, 3 `verChamado`, `mediaResposta` e `exportarCsv` capturado com `ob_start`. O resultado foi
  **idêntico** tanto com o schema antigo (CHAR 32) quanto com o novo (VARCHAR 255, em que os hashes foram
  regravados em bcrypt e o segundo login passou).
- **HTTP, original × novo, com `php -S`:** para o técnico, as 7 rotas testadas (lista, 3 detalhes, busca,
  CSV, id inexistente) são **idênticas**. Para a cliente `ana`, a lista mostra só 101/102/105, `?ver=103` e
  `?ver=104` respondem "não encontrado", e o CSV tem só os chamados dela.
- **Ataques refeitos no código novo:** a injeção UNION não retorna nada; o XSS sai escapado; o id de sessão
  plantado é recusado; o cookie sai com `HttpOnly; SameSite=Lax`; `busca[]=x` não derruba a página; com
  nenhum chamado respondido, a listagem mostra "0 min" (o original dava HTTP 500); o login funciona com um
  usuário de banco sem UPDATE.
