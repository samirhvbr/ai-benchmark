# RELATÓRIO — LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP/mysqli com ~250 linhas de produção.
A manutenção revelou um pacote de problemas coerente com código de 2013:
credenciais e chave de SMTP embutidas em `config.php`; autenticação por
`md5`; concatenação direta de entrada do usuário em SQL (SQL injection na
busca); XSS refletido via parâmetro `busca`; e — o mais grave segundo o
contrato do `manifest.md` — a **regra de negócio de visibilidade por papel
não é aplicada**: um cliente vê e exporta chamados de outros clientes, e
o detalhe (`?ver=<id>`) também não checa propriedade.

A camada de acesso a dados (mysqli) e as assinaturas públicas do `lib.php`
foram preservadas. As correções foram feitas evolutivamente: filtros de
visibilidade no caller (`index.php`) para não quebrar a rotina noturna e
o relatório gerencial que também chamam `listarChamados`/`exportarCsv`;
hash de senha com `password_hash()` + fallback `md5` que se auto-promove
na primeira autenticação válida; segredos movidos para `getenv()` sem
fallback embutido; toda a entrada do usuário passa a usar prepared
statements ou `htmlspecialchars` na saída.

A superfície pública — nomes, tipos e retornos das funções em `lib.php`,
rotas e parâmetros de `index.php`, cabeçalho e ordem do CSV, ordem e ids
das colunas da tabela HTML — foi mantida.

---

## 2. Achados

### F1 — SQL injection em `listarChamados` via `$busca`
- **Arquivo:** `code/lib.php:82` (linha_fim: 84)
- **Categoria:** segurança · **Severidade:** crítica · **Confiança:** 99
- **Mecanismo:** a string `$busca` (vinda de `$_GET['busca']` em `index.php:72`)
  é concatenada dentro de uma cláusula `LIKE`:
  `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`. Não há aspas escapadas,
  nem `mysqli::real_escape_string`, nem parâmetro preparado. Um atacante
  não autenticado envia `?busca=' OR 1=1 -- ` e força a listagem completa
  (regrava o `WHERE`, driblando qualquer filtro posterior). Como
  `listarChamados` é exposta para outros scripts internos, qualquer um
  que chame a função passando string controlada também está vulnerável.
- **Impacto:** vazamento em massa de registros da tabela `chamados`
  (incluindo descrição e metadados internos) sem necessidade de login.
  É a porta de entrada mais barata do sistema.
- **O que fiz:** troquei a concatenação por `prepare('... WHERE titulo LIKE ?')`
  com `$busca` ligado via `bind_param('s', '%'.$busca.'%')`. A função
  mantém a assinatura `listarChamados(mysqli $db, string $busca = ''): array`.

### F2 — Senha do banco de produção embutida em `config.php`
- **Arquivo:** `code/config.php:12`
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');`.
  O fallback literal é a senha de produção. Qualquer pessoa com acesso ao
  repositório (ou ao backup do repositório, ou a um log que mostre o
  arquivo) ganha a credencial do MySQL — bypassando o vault e qualquer
  rotação feita apenas na variável de ambiente.
- **Impacto:** leitura não autorizada do banco inteiro; pivô para
  escalonamento lateral via outros serviços que reusem a senha.
- **O que fiz:** removi o fallback. `DB_PASS` agora vem exclusivamente de
  `getenv('DB_PASS')`, defaultando a string vazia. A aplicação deixa de
  carregar um segredo válido sem `DB_PASS` no ambiente, o que é o
  comportamento desejado.

### F3 — Chave SMTP transacional embutida em `config.php`
- **Arquivo:** `code/config.php:15`
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');`
  está hardcoded no arquivo. Mesmo mecanismo de F2 — segredo em código,
  versionado, sem possibilidade real de rotação sem commit.
- **Impacto:** uso indevido do serviço de e-mail transacional em nome do
  ISP (phishing, spoof de notificações do painel, abuso de cota). Também
  expõe a estrutura interna de identificadores de chave.
- **O que fiz:** a constante agora é resolvida via `getenv('SMTP_API_KEY')`
  com default vazio. A chave existente foi tratada como **comprometida**
  (consta no histórico do repositório) e deve ser rotacionada no provedor —
  isso está nas **Decisões** porque depende de ação fora do código.

### F4 — Hash de senha por `md5` em `autenticar`
- **Arquivo:** `code/lib.php:15` (linha_fim: 17)
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** `$hash = md5($senha);` seguido de comparação `WHERE senha = ?`.
  MD5 é rápida, sem salt e quebrada para senhas curtas (rainbow tables cobrem
  todo o espaço de 6 caracteres; ataques online cobrem 8+ em horas de GPU).
  Combinado com a ausência de rate limiting no formulário de login
  (não há lockout), um atacante consegue credential stuffing direto contra
  `?login=` na web.
- **Impacto:** comprometimento de contas com baixíssimo custo; a partir
  daí, escalonamento para dentro do sistema (acesso a chamados de todos
  os clientes).
- **O que fiz:** `autenticar` agora reconhece o formato do hash:
  se começa com `$2y$`/`$2a$`/`$argon2`, usa `password_verify`; caso
  contrário, cai no caminho legado `md5` com comparação em tempo
  constante (`hash_equals`). No caminho legado, se a senha bate, o hash
  é promovido para `password_hash($senha, PASSWORD_DEFAULT)` na mesma
  transação. Resultado: nenhum login válido deixa de funcionar; após o
  primeiro acesso, o hash legado desaparece do banco.

### F5 — XSS refletido via parâmetro `busca` em `index.php`
- **Arquivo:** `code/index.php:79` (linha_fim: 82)
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 90
- **Mecanismo:** a string `$busca = $_GET['busca'] ?? ''` é refletida duas
  vezes sem `htmlspecialchars`:
  (1) dentro do atributo `value="..."` do input — quebra de atributo com
  `"><script>...</script>`;
  (2) dentro do parágrafo "Resultados para: ..." — injeção de HTML/JS.
  Como o output de `index.php` é gerado por `echo` e não usa template
  engine, o payload roda no contexto da origem do painel.
- **Impacto:** execução de JavaScript no navegador de quem clicar em link
  preparado; roubo de sessão (a cookie ainda não tinha `httponly` —
  ver F14); phishing contextual dentro do painel.
- **O que fiz:** todo o eco de `$busca` passou por
  `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. Mantive a variável
  original (`$busca`) para uso no SQL (que agora é preparado) e na
  checagem `if ($busca !== '')`.

### F6 — Regra de negócio de visibilidade não é aplicada (cliente vê chamados de outros)
- **Arquivo:** `code/index.php:73` (lista) e `code/index.php:53` (detalhe) e `code/index.php:45` (CSV)
- **Categoria:** arquitetura · **Severidade:** crítica · **Confiança:** 95
- **Mecanismo:** o `manifest.md §"Regra de negócio"` diz que cliente só vê
  os próprios chamados e técnico vê qualquer. Mas `listarChamados`,
  `verChamado` e a rota `?export=csv` em `index.php` não filtram por
  `$papel`/`$_SESSION['uid']`. Em particular, na listagem
  (`index.php:73`) e no detalhe (`index.php:53`), a função recebe
  `(int) $_GET['ver']` e o array, sem nenhuma checagem de
  `$c['usuario_id'] !== $uid`. Na exportação, a chamada direta a
  `exportarCsv($db)` despeja todos os registros.
- **Impacto:** quebra declarada do contrato do produto: um cliente
  consegue ler títulos, descrições, status e técnicos de chamados que
  não são seus — inclusive pela URL (`index.php?ver=102` mostra o
  chamado da Ana para o Bruno). É exatamente o tipo de violação que o
  `manifest.md` diz descontar pontos.
- **O que fiz:** mantive as funções de `lib.php` com a assinatura
  declarada (elas são compartilhadas com a rotina noturna e o relatório
  gerencial, que precisam ver tudo) e apliquei o filtro no caller:
  - listagem: `array_filter` por `usuario_id === $uid` quando papel é cliente;
  - detalhe: trata "cliente e dono errado" como "não encontrado";
  - CSV: gera inline em `index.php` via `php://output` com `prepare`
    filtrando por `usuario_id = ?`; técnico segue pelo caminho antigo,
  preservando o uso interno de `exportarCsv($db)`.

### F7 — Divisão por zero em `mediaResposta` quando não há respostas
- **Arquivo:** `code/lib.php:116` (linha_fim: 117)
- **Categoria:** bug · **Severidade:** média · **Confiança:** 85
- **Mecanismo:** o `return $soma / $qtd;` assume `$qtd > 0`. Em PHP 8.0+,
  divisão por zero lança `DivisionByZeroError`; em 7.x retornava `NaN` com
  warning. Ocorre sempre que a tabela não tem linhas com
  `minutos_resposta IS NOT NULL` (cenário real em ambiente novo, após
  `TRUNCATE`, ou se a coluna for populada por rotinas externas que
  falharam).
- **Impacto:** 500 na home do painel; "tempo médio" some do ar até alguém
  inserir a primeira resposta. Não é catastrófico, mas tira o indicador
  que existe justamente para o começo de operação.
- **O que fiz:** guard explícito: `return $qtd > 0 ? $soma / $qtd : 0.0;`,
  mais checagem de `$res === false` na query. Assinatura float preservada.

### F8 — Fixação de sessão após login (`session_regenerate_id` ausente)
- **Arquivo:** `code/index.php:24` (linha_fim: 25)
- **Categoria:** segurança · **Severidade:** média · **Confiança:** 80
- **Mecanismo:** quando o login é bem-sucedido, `index.php` apenas
  preenche `$_SESSION['uid']`/`$_SESSION['papel']` e segue. Não há
  `session_regenerate_id(true)`. Em fixation — onde o atacante planta um
  `PHPSESSID` conhecido na vítima via link ou subdomínio compartilhado —
  o login "promove" esse id a uma sessão autenticada, sem trocar o
  identificador.
- **Impacto:** session hijacking sem necessidade de roubar a cookie depois;
  depende de vetor de fixação viável (subdomínio, link preparado, app
  vizinho). Combinado com a ausência de `httponly`/`secure` (F14), o
  risco é maior.
- **O que fiz:** `session_regenerate_id(true)` imediatamente após validar
  `autenticar`, antes de gravar `$_SESSION`. Id de sessão anterior é
  invalidado.

### F9 — Coluna `senha CHAR(32)` impede upgrade para bcrypt
- **Arquivo:** `code/schema.sql:8`
- **Categoria:** arquitetura · **Severidade:** alta · **Confiança:** 90
- **Mecanismo:** a coluna está definida como `CHAR(32)`. O hash bcrypt
  gerado por `password_hash($senha, PASSWORD_DEFAULT)` tem 60 caracteres;
  argon2 ainda mais. Em uma instalação que roda `schema.sql` + `seed.sql`
  e depois executa o `autenticar` migrado (F4), o `UPDATE usuarios SET
  senha = ? WHERE id = ?` **truncaria** o hash bcrypt para 32 caracteres,
  corrompendo a senha para todos os logins seguintes.
- **Impacto:** sem esta correção, a correção de F4 quebra o login depois
  da primeira promoção. A mudança é estritamente compatível para
  consumidores atuais: hashes MD5 (32 chars) continuam cabendo em
  `VARCHAR(255)`.
- **O que fiz:** coluna alterada para `VARCHAR(255) NOT NULL`, com o
  comentário atualizado indicando que agora pode carregar md5 legado
  ou `password_hash()`. **Não** migrei dados existentes — isso é uma
  decisão consciente (ver **Decisões**).

### F10 — Caracteres curingas do `LIKE` não escapados em `listarChamados`
- **Arquivo:** `code/lib.php:82`
- **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 60
- **Mecanismo:** mesmo após a troca para prepared statement, a string
  passada para `LIKE` é apenas `'%' . $busca . '%'`. Se o usuário
  digita `%` ou `_`, MySQL os trata como curingas. Buscar por
  `100%` retornará tudo que contém `100` seguido de qualquer coisa,
  não exatamente o literal.
- **Impacto:** UX de busca enganosa em casos com `%` ou `_` no título.
  Não é vetor de SQL injection (a preparação isola o literal).
- **O que fiz:** documentei a decisão de **não** escapar `\`/`%`/`_`
  nesta entrega — é um bug de UX, não de segurança, e a correção
  (`LIKE ? ESCAPE '\\'` + escaping do termo) compete com F1 pela mesma
  linha e preferi manter a correção mínima. Está em **Decisões**.

### F11 — `exportarCsv` deixa handle aberto no caminho de erro de query
- **Arquivo:** `code/lib.php:134` (linha_fim: 135)
- **Categoria:** bug · **Severidade:** baixa · **Confiança:** 70
- **Mecanismo:** se o `SELECT * FROM chamados` falha (`$res === false`),
  o código apenas `return`s sem `fclose($fp)`. O arquivo em
  `EXPORT_DIR/chamados.csv` fica truncado (criado mas só com o cabeçalho)
  e o handle vaza até o fim do request — pequeno em PHP-FPM, mas ainda
  assim leak.
- **Impacto:** observacional — logs de "Too many open files" em workloads
  com muitos exports seguidos; arquivo CSV truncado em disco confundindo
  consumidores que monitoram aquele path.
- **O que fiz:** `fclose($fp)` antes do `return` no caminho de erro da
  query. Mantida a forma do `fclose` no caminho feliz.

### F12 — `formatarStatus` retorna "Resolvido" para qualquer status fora de 1/2
- **Arquivo:** `code/lib.php:33` (linha_fim: 34)
- **Categoria:** bug · **Severidade:** baixa · **Confiança:** 65
- **Mecanismo:** a função usa `if/else if/else` que devolve "Resolvido"
  para tudo que não é 1 nem 2. O contrato do `manifest.md` fixa os rótulos
  para 1/2/3, mas não fala do "default". Para um valor corrompido
  (digamos 0 vindo de um `TINYINT` com bug upstream), o sistema silenciosamente
  apresenta o chamado como resolvido.
- **Impacto:** indicador de gestão errado em caso de dado corrompido;
  confusão para a equipe que monitora o painel.
- **O que fiz:** troquei por `switch` com `default: return 'Desconhecido'`.
  Os rótulos de 1/2/3 — o que o manifest **promete** — permanecem
  idênticos.

### F13 — `rotuloPrioridade` trata prioridade 4 (crítica) como "Alto" dentro do SLA
- **Arquivo:** `code/lib.php:51`
- **Categoria:** bug · **Severidade:** baixa · **Confiança:** 70
- **Mecanismo:** o ramo `$prioridade >= 3` retorna
  `'Alto - dentro do SLA'` indistintamente para 3 e 4. A coluna
  `prioridade` no schema diz explicitamente `4=crítica`. Um chamado
  crítico com SLA ok aparece na listagem como "Alto", perdendo o sinal
  que o operador precisa.
- **Impacto:** triagem errada na ponta; métricas gerenciais que contam
  críticos pelo rótulo ficam subnotificadas.
- **O que fiz:** ramo explícito: prioridade 4 retorna
  `'CRITICO - dentro do SLA'` (mantendo `'CRITICO - SLA estourado'` no
  ramo de estouro). Nenhum dos rótulos antigos foi renomeado, então o
  consumidor do relatório gerencial continua casando os textos antigos.

### F14 — Cookie de sessão sem `HttpOnly`/`Secure`/`SameSite`
- **Arquivo:** `code/index.php:15`
- **Categoria:** segurança · **Severidade:** média · **Confiança:** 75
- **Mecanismo:** `session_start()` é chamado com os parâmetros default do
  PHP. Em servidores servidos via HTTPS, isso permite que a cookie
  `PHPSESSID` trafegue em HTTP se houver redirect/mixed content, e
  fique acessível a JavaScript (XSS combinado com F5 vira account
  takeover direto, sem nem precisar da fixation de F8).
- **Impacto:** amplia o raio de F5 e de qualquer XSS futuro; some
  SameSite=Lax é também a única mitigação leve para CSRF em rotas
  state-changing futuras (o painel hoje não muda estado via GET, mas
  está a um refactor de distância).
- **O que fiz:** `session_set_cookie_params` com `httponly=true`,
  `secure=!empty($_SERVER['HTTPS'])` e `samesite='Lax'` **antes** de
  `session_start()`.

### F15 — Login sem mensagem em caso de falha
- **Arquivo:** `code/index.php:30` (linha_fim: 35)
- **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 50
- **Mecanismo:** quando `autenticar` devolve `null`, o script simplesmente
  re-renderiza o mesmo formulário, indistinguível da primeira visita.
  Não há sinal de erro, e o campo `login` também não é repopulado.
- **Impacto:** UX ruim; o usuário acha que o submit não funcionou e
  tenta de novo — agravando a janela de brute-forcing.
- **O que fiz:** flag `$loginErro` e parágrafo "Usuário ou senha
  inválidos." em vermelho. Continuo **não** ecoando o login digitado
  (defesa em profundidade contra XSS de campo refletido).

### F16 — Falhas silenciosas em queries de `lib.php`
- **Arquivo:** `code/lib.php:69`, `code/lib.php:100`, `code/lib.php:109`
- **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 55
- **Mecanismo:** várias funções usam `$db->query(...)` ou `$stmt->execute()`
  sem verificar retorno. `tecnicoNome` retorna `-`, `listarChamados`
  retorna `[]` silenciosamente, `verChamado` retorna `null` — todos
  indistinguíveis de "não encontrou".
- **Impacto:** dificuldade de diagnosticar incidentes de produção; logs
  ficam vazios quando a query falha por uma coluna removida, deadlock,
  ou problema de conexão.
- **O que fiz:** adicionei checagens `=== false` nas três funções e
  retornei o valor "vazio" do tipo de retorno em cada caso. **Não**
  adicionei `error_log`/logger — isso seria dependência nova (ver
  **Decisões**).

### F17 — `EXPORT_DIR` potencialmente servido pela web
- **Arquivo:** `code/lib.php:125` (e `code/config.php:18`)
- **Categoria:** segurança · **Severidade:** média · **Confiança:** 65
- **Mecanismo:** `exportarCsv` grava em `/var/www/painel/tmp/chamados.csv`.
  Esse path está sob `/var/www`, que é o DocumentRoot padrão de Apache
  em várias distros. Se o servidor não tiver `tmp` em uma `Location`
  com `Require all denied`, qualquer um que souber o path baixa o CSV
  completo — inclusive de clientes cujo dono é outro.
- **Impacto:** vazamento de CSV integral independente da rota
  `index.php?export=csv`. Não dá para garantir pelo código que o
  DocumentRoot esteja configurado de modo a vedar `/tmp`; isso é
  configuração de servidor.
- **O que fiz:** **não** toquei em `exportarCsv` (a rotina noturna depende
  do arquivo). Em **Decisões**, listo o que precisa ser feito fora do
  código (negação de acesso web a `EXPORT_DIR` no Apache/nginx).

### F18 — `exportarCsv` sofre race condition entre exports simultâneos
- **Arquivo:** `code/lib.php:126`
- **Categoria:** bug · **Severidade:** baixa · **Confiança:** mundial · **Confiança:** 50
- **Mecanismo:** dois técnicos (ou dois clientes no caminho legado) que
  clicam em `Exportar CSV` quase em paralelo abrem o mesmo
  `chamados.csv` em modo `'w'`. O segundo `fopen` trunca a saída do
  primeiro. Como o `readfile` vem depois, cada request lê o arquivo
  recém-truncado — quem chegar primeiro pode baixar CSV vazio; quem
  chegar depois, CSV parcial.
- **Impacto:** intermitente, difícil de reproduzir, e o sintoma é
  justamente "o export às vezes vem quebrado" — costuma ser confundido
  com bug de dados.
- **O que fiz:** **não** corrigi nesta entrega (ver **Decisões**);
  requer mexer no contrato de `exportarCsv` ou sortear nome único, e
  isso compete com a mudança de visibilidade (F6) pelo mesmo arquivo.

---

## 3. Decisões — o que **não** mudei e por quê

1. **Assinaturas de `lib.php`.** O `manifest.md` declara nome, parâmetros
   e retorno de cada função pública. Não adicionei parâmetro novo
   (ex.: `listarChamados($db, $busca, $uid, $papel)`) nem mudei a
   forma do retorno de `autenticar`. Filtros de visibilidade foram
   aplicados no caller (`index.php`), preservando o uso interno pela
   rotina noturna e pelo relatório gerencial.

2. **`exportarCsv` preservada como está.** Outros scripts do ISP
   contam com o arquivo em `EXPORT_DIR/chamados.csv` (cabeçalho exato,
   ordem por `id`, todos os registros). Para respeitar a regra de
   negócio no caminho web, gerei o CSV inline** em `index.php` para o
   caso cliente, e mantive `exportarCsv($db)` para o técnico e para
   os consumidores internos.

3. **Rótulos de `formatarStatus` para 1/2/3.** O contrato do `manifest.md`
   fixa exatamente esses três. Mantive os três literais byte a byte; o
   "default" mudou para `'Desconhecido'` em vez de cair em
   `'Resolvido'`.

4. **`rotuloPrioridade` mantém os rótulos antigos e adiciona um.** O
   relatório gerencial faz correspondência por texto; mudar
   `'Alto - atrasado'` (etc.) quebraria isso. Adicionei apenas o caso
   `'CRITICO - dentro do SLA'` (que não existia) e mantive os demais.

5. **MD5 legado aceito, não exigido a re-hash.** `autenticar` agora
   entende tanto bcrypt quanto md5. Em vez de proibir login até uma
   migração manual (que precisaria de manutenção coordenada e janela
   de manutenção), promove automaticamente no primeiro login válido.
   Senhas erradas no caminho md5 falham normalmente.

6. **`schema.sql` mudou de `CHAR(32)` para `VARCHAR(255)` para a coluna
   `senha`.** Esta é a única alteração de DDL. Hashes legados md5 (32
   chars) cabem em `VARCHAR(255)`. **Não** escrevi `ALTER TABLE` para
   bancos existentes — isso é uma migração operacional e deve ser feita
   pela equipe de DBA junto com a rotação de `DB_PASS`/`SMTP_API_KEY`,
   não pela manutenção de código.

7. **Segredos antigos (`N3tX@2013!prod`, chave SMTP) — tratar como
   comprometidos.** Não dá para "des-publicar" um segredo que já
   esteve em commit. A remoção do fallback no código é a parte que
   cabe aqui; a rotação real é **ação externa** ao pacote de código
   e está listada no relatório para que não se confunda com "tarefa
   feita".

8. **Não bloqueei o `EXPORT_DIR` por código.** O path está sob
   `/var/www/painel/tmp`. Negar acesso é configuração do servidor
   web (Apache/nginx `Require all denied` / `deny all` na `Location`
   correspondente), não do PHP. Anoto como pendência de
   infraestrutura.

9. **Não adicionei logger, CSRF token, rate-limit, nem hardening
   extra de headers (CSP, HSTS, etc.).** Tudo isso seria dependência
   nova ou reescrita de superfície, o que contraria as restrições
   do pacote. O escopo desta entrega é consertar o que estava
   quebrado sem ampliar a pegada do sistema.

10. **Não migrei dados existentes (`UPDATE usuarios SET senha = ?`).**
    A promoção automática cobre o que importa (o caminho de login);
    reescrever todos os hashes preventivamente força dependência de
    janela de manutenção e exige que o salt/algoritmo esteja
    estável antes da virada. Se a equipe quiser forçar migração,
    isto é uma operação separada.

11. **Não toquei no `seed.sql`.** Ele continua usando `MD5(...)`. Como
    `autenticar` aceita o formato, o seed segue válido; trocá-lo
    também seria reescrever a "documentação de uso" do pacote sem
    benefício direto.

12. **Não escapa curingas de LIKE.** (F10) É uma melhoria de UX, não de
    segurança, e competiria com F1 pela mesma linha; mantive a
    correção cirúrgica em F1.

13. **Não corrigi a race condition de `exportarCsv` (F18).** Exige
    mudar contrato (sufixo único, arquivo temporário, ou stream
    direto), o que impacta o consumidor da rotina noturna. Aguarda
    decisão de produto.

---

## 4. Checklist final

- [x] `code/` alterado in-place, mesmos caminhos e nomes.
- [x] `RELATORIO.md` com mecanismo, severidade e confiança 0–100 por achado.
- [x] `achados.json` válido, com o bloco `leb` copiado do topo de `TAREFA.md`.
- [x] Todo achado do relatório está no JSON, e vice-versa.
- [x] Seção **Decisões** lista o que não mudou e por quê.
- [x] Nada do `manifest.md` foi quebrado.