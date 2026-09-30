# Relatório técnico — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

**Estado encontrado.** O painel funciona nos casos felizes, mas tinha, na prática, **três falhas que qualquer cliente logado consegue explorar**: SQL injection na busca (dava para ler a tabela de usuários com os hashes), XSS refletido no mesmo campo, e **nenhuma aplicação da regra de visibilidade** — a cliente `ana` via, abria e exportava os chamados do `bruno`. Somam-se a isso senhas em md5 sem sal, credenciais de produção escritas no `config.php`, sessão sem proteção contra fixação, um arquivo de exportação de nome fixo compartilhado entre requisições, uma divisão por zero que derruba a tela principal e um N+1 no nome do técnico. Nada disso aparece no manifesto, que só descreve o contrato.

**O que foi entregue.** 22 achados (17 corrigidos, 5 só reportados por decisão explícita). Correções feitas **no lugar**, em `lib.php`, `index.php`, `config.php` e `schema.sql`: consultas parametrizadas, escopo por dono, escape de saída, hash de senha moderno com migração no login, segredos só por ambiente, sessão endurecida, exportação sem arquivo em disco. **As 7 assinaturas públicas ficaram idênticas** (conferido linha a linha); a visibilidade entrou como 3 funções novas. Para os técnicos, todas as páginas e o CSV são **idênticos byte a byte** ao original.

| Severidade | Qtde | | Categoria | Qtde |
| --- | --- | --- | --- | --- |
| crítica | 1 | | segurança | 11 |
| alta | 6 | | bug | 6 |
| média | 6 | | performance | 3 |
| baixa | 9 | | qualidade | 1 |
| | | | arquitetura | 1 |

**Como verifiquei.** Rodei o original e o código novo lado a lado (PHP 8.4.26 + MariaDB 11.8.6 numa instância privada e descartada; o schema pede MySQL 8, não testei nele) e reproduzi cada ataque no original antes de corrigir e de novo depois. Detalhes na seção 5.

### Antes de subir em produção (nesta ordem)

1. **Definir `DB_PASS` (e `SMTP_API_KEY`) no ambiente do PHP** (`SetEnv`/`env[]` do php-fpm). Sem isso o painel não conecta — de propósito (F6).
2. **Rotacionar a senha do banco e a chave SMTP.** Estiveram em texto claro no código e continuam válidas.
3. Rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` (uma vez). Antes disso o login funciona normalmente em md5; depois, cada usuário migra para bcrypt no próprio login (F7). É seguro antes ou depois do deploy do código.
4. Conferir se a **rotina noturna de exportação** lia o arquivo `chamados.csv` do `EXPORT_DIR` (efeito colateral que o manifesto não declara). O CSV agora só sai pela saída padrão (F8).
5. PHP >= 7.4 (o teste foi em 8.4). O bcrypt do PHP 8.4 usa custo 12: cerca de 0,3 s por login nesta máquina.

## 2. Achados (na ordem em que eu priorizaria a correção)

| ID | Severidade | Conf. | Categoria | Achado | Corrigido |
| --- | --- | --- | --- | --- | --- |
| F1 | critica | 99 | seguranca | SQL injection na busca: o termo é concatenado dentro do LIKE | sim |
| F2 | alta | 97 | seguranca | Detalhe do chamado sem verificação de dono (IDOR) | sim |
| F3 | alta | 97 | seguranca | Listagem devolve os chamados de todos os clientes a qualquer usuário logado | sim |
| F4 | alta | 96 | seguranca | Exportação CSV entrega todos os chamados a qualquer usuário logado | sim |
| F5 | alta | 98 | seguranca | XSS refletido: o termo de busca é impresso sem escape (atributo e texto) | sim |
| F6 | alta | 99 | seguranca | Credenciais de produção embutidas no código-fonte | sim |
| F7 | alta | 95 | seguranca | Senhas guardadas como md5 sem sal | sim |
| F8 | media | 85 | seguranca | Exportação grava num arquivo de nome fixo, compartilhado entre requisições e deixado em disco | sim |
| F9 | media | 90 | seguranca | Fixação de sessão e cookie de sessão sem flags de proteção | sim |
| F10 | media | 98 | bug | mediaResposta() divide por zero quando nenhum chamado tem resposta | sim |
| F11 | media | 97 | performance | Uma consulta por chamado para obter o nome do técnico (N+1) | sim |
| F12 | media | 65 | seguranca | Título entra no CSV sem neutralizar fórmulas (CSV injection) | sim |
| F13 | media | 70 | seguranca | Login sem limite de tentativas | não |
| F14 | baixa | 95 | bug | Parâmetros em array derrubam a página com TypeError e stack trace | sim |
| F15 | baixa | 85 | bug | Tratamento de falha de conexão nunca executa no PHP 8.1+ | sim |
| F16 | baixa | 85 | bug | fputcsv() sem o parâmetro $escape: Deprecated no PHP 8.4 e CSV lido errado com \" | sim |
| F17 | baixa | 90 | performance | mediaResposta() traz todas as linhas para o PHP só para somar | sim |
| F18 | baixa | 90 | qualidade | Condicionais aninhadas em rotuloPrioridade() e cadeia else-if em formatarStatus(); limite de SLA como número solto | sim |
| F19 | baixa | 30 | bug | Chamado crítico sem primeira resposta aparece como 'Aguardando 1a resposta' | não |
| F20 | baixa | 40 | bug | formatarStatus() rotula como 'Resolvido' qualquer status diferente de 1 e 2 | não |
| F21 | baixa | 60 | performance | Listagem e exportação carregam todos os chamados, sem paginação | não |
| F22 | baixa | 70 | arquitetura | Camada de dados acoplada a HTTP e API 'irrestrita' convivendo com a 'segura' | não |

### F1 — SQL injection na busca: o termo é concatenado dentro do LIKE

- **Onde:** `code/lib.php:82-85` — chamado por `code/index.php:72-73` com `$_GET['busca']` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **critica** · **99/100**
- **Mecanismo:** listarChamados() monta `WHERE titulo LIKE '%<busca>%'` concatenando $busca (que vem direto de $_GET['busca'], index.php:72) e executa com $db->query() (lib.php:82-85). Uma aspa no termo fecha o literal e o resto vira SQL. Reproduzido no original, logado como a cliente ana: `busca=zzz' UNION SELECT id,1,1,senha,1,1,1,1,NOW() FROM usuarios -- ` devolveu os hashes md5 dos técnicos na coluna Título; `busca=' OR '1'='1` devolveu os 5 chamados; e uma busca legítima como `O'Brien` derruba a página com mysqli_sql_exception.
- **Impacto:** Qualquer usuário autenticado, inclusive um cliente, lê qualquer tabela que o usuário do banco alcance (usuarios com logins e hashes md5, chamados de todos os clientes) e passa por cima da regra de visibilidade. Bônus involuntário: é impossível buscar um título com apóstrofo.
- **O que fiz:** Consulta parametrizada (prepare/bind_param) em consultarChamadosBase(): `c.titulo LIKE ?` com '%'.$busca.'%' como parâmetro. Reteste: UNION → 0 linhas; `' OR '1'='1` → 0 linhas; `O'Brien` → 200 sem erro. — **corrigido**.

### F2 — Detalhe do chamado sem verificação de dono (IDOR)

- **Onde:** `code/lib.php:98-102` — chamado por `code/index.php:52-53` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **97/100**
- **Mecanismo:** index.php:52-53 faz verChamado($db, (int) $_GET['ver']) e verChamado() consulta só por id (lib.php:100); nunca compara chamados.usuario_id com o usuário da sessão ($uid e $papel são lidos em index.php:38-39 mas não são usados em lugar nenhum). Reproduzido: logada como ana (cliente), `index.php?ver=103` (chamado do bruno) devolveu título, status, prioridade e descrição. Os IDs são sequenciais (101-105), então enumerar é trivial. A concatenação `WHERE id = ' . $id` só é segura hoje por causa do type hint `int`.
- **Impacto:** Um cliente lê o conteúdo (descrição, com dados de cobrança e contato) dos chamados de todos os outros. Viola a regra de visibilidade do manifesto.
- **O que fiz:** Nova verChamadoVisivel($db, $donoId, $id): acrescenta `AND usuario_id = ?` quando $donoId não é null. index.php passa null para técnico e $uid para qualquer outro papel (falha fechada). verChamado() virou wrapper sem restrição, com a assinatura intacta. Chamado alheio devolve exatamente a resposta de chamado inexistente ('Chamado nao encontrado.'), sem 403, para não confirmar que o id existe. Testado: ana→103 e 104 = não encontrado; ana→101 idêntico ao original; técnicos→todos idênticos ao original. — **corrigido**.

### F3 — Listagem devolve os chamados de todos os clientes a qualquer usuário logado

- **Onde:** `code/lib.php:78-93` — usada por `code/index.php:72-73` para qualquer papel *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **97/100**
- **Mecanismo:** listarChamados() faz `SELECT * FROM chamados` sem nenhum filtro por dono (lib.php:80-84) e index.php:73 a chama do mesmo jeito para cliente e técnico. Reproduzido: ana (dona de 101, 102 e 105) recebeu os 5 chamados, inclusive 103 e 104 do bruno; a busca também atravessa clientes (`busca=Lent` logado como bruno acha o chamado da ana).
- **Impacto:** Vazamento horizontal: título, status, prioridade e técnico de todos os chamados de terceiros.
- **O que fiz:** Nova listarChamadosVisiveis($db, $donoId, $busca) filtra por `c.usuario_id = ?` quando $donoId não é null; index.php escolhe o escopo pelo papel (só 'tecnico' vê tudo). Resultado: ana→105,102,101; bruno→104,103; técnicos→todos, HTML byte a byte igual ao original. listarChamados() permanece com a assinatura original, sem restrição, para os scripts internos. — **corrigido**.

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/lib.php:132-145` — acionada em `code/index.php:44-47`, antes de qualquer checagem de papel *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **96/100**
- **Mecanismo:** exportarCsv() lê `SELECT * FROM chamados ORDER BY id` (lib.php:132) sem filtro e index.php:44-46 a chama para qualquer sessão. Reproduzido: ana baixou um CSV com os 5 chamados, inclusive os do bruno.
- **Impacto:** Extração em lote de toda a base de chamados com um único GET.
- **O que fiz:** Nova exportarCsvVisivel($db, $donoId) com o mesmo escopo; exportarCsv() virou wrapper sem restrição para a rotina noturna. ana→101,102,105; bruno→103,104; para técnicos o CSV é idêntico byte a byte ao original (mesmo cabeçalho, ordem por id, rótulos de status). — **corrigido**.

### F5 — XSS refletido: o termo de busca é impresso sem escape (atributo e texto)

- **Onde:** `code/index.php:79-82` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **98/100**
- **Mecanismo:** index.php:79 escreve `value="' . $busca . '"` e index.php:82 escreve `'Resultados para: ' . $busca`, ambos sem htmlspecialchars, enquanto os demais campos da página são escapados. Reproduzido: `busca="><script>alert(1)</script>` voltou como HTML executável nos dois pontos. Como é GET, o link pode ser enviado a um técnico já logado.
- **Impacto:** Execução de JavaScript na sessão autenticada da vítima (ler o painel, exportar o CSV, agir em nome dela). Combinado com o cookie sem HttpOnly (F9), o próprio ID de sessão ficava ao alcance do script.
- **O que fiz:** Helper h() = htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') aplicado à busca (atributo e texto) e uniformizado nos demais campos; ids impressos com (int). Reteste: a saída passa a ser `&quot;&gt;&lt;script&gt;…`. — **corrigido**.

### F6 — Credenciais de produção embutidas no código-fonte

- **Onde:** `code/config.php:12-15` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **99/100**
- **Mecanismo:** DB_PASS tem um fallback literal com a senha do usuário de produção (config.php:12): basta a variável de ambiente faltar para o sistema conectar com a senha que está no código. SMTP_API_KEY é uma constante literal (config.php:15) — e nem é usada em code/. As duas ficam no repositório, no histórico e em qualquer cópia ou backup do código.
- **Impacto:** Quem tiver o código-fonte (desenvolvedor, terceirizado, backup vazado) tem acesso ao banco de produção e à conta de e-mail transacional.
- **O que fiz:** Segredos só via getenv(): DB_PASS = getenv('DB_PASS') ?: '' (sem a variável a conexão falha e o motivo vai para o log, em vez de cair numa senha embutida) e SMTP_API_KEY = getenv('SMTP_API_KEY') ?: ''. Nenhum literal restante em code/ (verificado com grep). PENDENTE FORA DO CÓDIGO: definir as duas variáveis no ambiente do PHP antes do deploy e ROTACIONAR a senha do banco e a chave SMTP — ambas já estiveram em texto claro e continuam válidas. — **corrigido**.

### F7 — Senhas guardadas como md5 sem sal

- **Onde:** `code/lib.php:15-20` — coluna `code/schema.sql:7` (`CHAR(32)`); dados em `code/seed.sql:4-8` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **alta** · **95/100**
- **Mecanismo:** autenticar() compara md5($senha) com usuarios.senha (lib.php:15-17): hash rápido, sem sal e sem custo. seed.sql mostra o efeito: ana e bruno têm o mesmo hash porque usam a mesma senha, o que já revela a reutilização. Um hash extraído (F1) ou um dump de banco é revertido por tabela/GPU em segundos.
- **Impacto:** Com F1 ou qualquer vazamento do banco, todas as senhas (técnicas inclusive) são recuperáveis, e são reaproveitáveis em outros serviços dos mesmos usuários.
- **O que fiz:** autenticar() agora busca a linha pelo login e verifica em PHP: hash password_hash() via password_verify(); hash legado (32 hex) via hash_equals(md5). Um login legado válido migra o hash para password_hash() (`UPDATE … WHERE id = ? AND senha = <antigo>`), mas só se a coluna comportar o hash novo; o schema.sql entregue passa a VARCHAR(255) e, para bancos existentes, basta `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` (documentado no cabeçalho do schema.sql; enquanto não rodar, o login continua funcionando em md5 e nada é gravado). Quando o login não existe, um hash falso é verificado para igualar o tempo de resposta (275 ms contra 280 ms medidos). Retorno idêntico ao original: ['id','nome','papel'], id inteiro. RESSALVA: a senha de quem nunca mais logar continua md5 no banco; seed.sql mantém MD5() para preservar os usuários de teste do manifesto. — **corrigido**.

### F8 — Exportação grava num arquivo de nome fixo, compartilhado entre requisições e deixado em disco

- **Onde:** `code/lib.php:125-150` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **media** · **85/100**
- **Mecanismo:** exportarCsv() abre EXPORT_DIR/chamados.csv com modo 'w' (lib.php:125-126, trunca), escreve, fecha e só então faz readfile (146-150). Duas exportações simultâneas escrevem e truncam o mesmo arquivo: uma requisição pode devolver o conteúdo (ou metade do conteúdo) da outra. Com o escopo por dono (F4) isso passaria a entregar a um cliente o CSV completo gerado por um técnico no mesmo instante. Além disso o arquivo com todos os chamados fica para sempre no diretório (verificado no original: depois da exportação de um cliente ficou lá o arquivo com os 5 chamados, 399 bytes); se /var/www/painel/tmp estiver dentro do docroot ele é baixável sem login. Se o diretório não existir ou não for gravável, fopen falha (127-129) e o usuário recebe uma página em branco, sem erro.
- **Impacto:** Vazamento cruzado entre usuários sob concorrência, dado sensível em repouso e falha silenciosa quando o diretório está ausente.
- **O que fiz:** exportarCsvVisivel() escreve direto em php://output; nenhum arquivo é criado (verificado: pasta de exportação vazia depois dos testes). A constante EXPORT_DIR continua definida no config.php, sem uso pelo painel. — **corrigido**.

### F9 — Fixação de sessão e cookie de sessão sem flags de proteção

- **Onde:** `code/index.php:15-25` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **media** · **90/100**
- **Mecanismo:** session_start() (index.php:15) roda antes do login e o ID não é trocado ao autenticar (24-25 só gravam uid e papel). Reproduzido: PHPSESSID antes do login = PHPSESSID depois. Um atacante que faça a vítima usar um ID que ele conhece vira a própria vítima logada. Nenhuma flag do cookie é definida (o original respondeu `Set-Cookie: PHPSESSID=…; path=/`, sem HttpOnly, SameSite ou Secure) e o modo estrito não está ligado, então o servidor aceita IDs inventados.
- **Impacto:** Sequestro de sessão de técnico ou cliente; o cookie também fica legível por JavaScript (agrava F5).
- **O que fiz:** session.use_strict_mode=1, cookie HttpOnly + SameSite=Lax (+ Secure quando a requisição é HTTPS) e session_regenerate_id(true) logo após autenticar. Reteste: o ID muda no login, o antigo deixa de valer, um ID inventado é descartado e o Set-Cookie sai com `HttpOnly; SameSite=Lax`. Atrás de um proxy TLS que não repasse $_SERVER['HTTPS'] o flag Secure não é ligado (o cookie continua funcionando). — **corrigido**.

### F10 — mediaResposta() divide por zero quando nenhum chamado tem resposta

- **Onde:** `code/lib.php:116` — chamada em toda listagem, `code/index.php:74` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **media** · **98/100**
- **Mecanismo:** `return $soma / $qtd;` sem tratar $qtd = 0 (lib.php:116), e index.php:74 chama mediaResposta() em todo carregamento da listagem. Reproduzido: com minutos_resposta NULL em todos os chamados a página inicial devolve `DivisionByZeroError` (Fatal, 500). Acontece com base recém-criada, depois de limpeza de dados ou num período em que ninguém foi respondido.
- **Impacto:** A tela principal inteira fica indisponível, para todos os usuários, até algum chamado receber a primeira resposta.
- **O que fiz:** Devolve 0.0 quando não há nenhuma resposta (a tela mostra '0 min'; o original não tinha saída nesse estado, então não há formato a preservar). Testado sem nenhum minutos_resposta e com a tabela vazia. — **corrigido**.

### F11 — Uma consulta por chamado para obter o nome do técnico (N+1)

- **Onde:** `code/lib.php:88-91` — mesmo padrão em `code/lib.php:136-137` (exportarCsv); a função está em `lib.php:64-72` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** performance · **media** · **97/100**
- **Mecanismo:** listarChamados() (lib.php:88-91) e exportarCsv() (136-137) chamam tecnicoNome() dentro do loop, e ela executa `SELECT nome FROM usuarios WHERE id = …` (linha 69) uma vez por chamado: N+1 consultas por página e por exportação. tecnicoNome() também concatena o id na SQL (seguro só pelo type hint ?int).
- **Impacto:** O custo cresce linearmente com o histórico: uma página ou exportação com 5.000 chamados dispara 5.001 consultas ao banco.
- **O que fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-')` em consultarChamadosBase(): uma consulta só, mesma chave `tecnico_nome`, mesmo '-' para chamado sem técnico (comparado com o original: idêntico). tecnicoNome() foi mantida (é pública, mesmo fora do manifesto) e parametrizada. — **corrigido**.

### F12 — Título entra no CSV sem neutralizar fórmulas (CSV injection)

- **Onde:** `code/lib.php:140` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **media** · **65/100**
- **Mecanismo:** O título é texto livre digitado pelo cliente e vai para a célula do CSV como está (lib.php:140). fputcsv() só cuida de aspas e vírgulas; um título que comece com `=`, `+`, `-` ou `@` (por exemplo `=HYPERLINK(...)`) é executado como fórmula quando a equipe abre o export no Excel ou Calc.
- **Impacto:** Execução de fórmula ou exfiltração de dados na máquina de quem abre o CSV. A confiança é 65 porque depende de como o arquivo é aberto e de quem pode cadastrar títulos (o cadastro não está neste código).
- **O que fiz:** neutralizarFormulaCsv() prefixa `'` em títulos que começam com = + - @ TAB ou CR (recomendação OWASP). Aplicado só à coluna Titulo: o '-' de 'sem técnico' e as demais colunas saem idênticos ao original. — **corrigido**.

### F13 — Login sem limite de tentativas

- **Onde:** `code/index.php:20-29` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** seguranca · **media** · **70/100**
- **Mecanismo:** autenticar() é chamado a cada POST (index.php:21-22) sem contador de falhas, atraso ou bloqueio por conta ou por IP, e a resposta é sempre o mesmo formulário. Com senhas do tipo `senha123`/`tecmaster` e md5 (F7) o custo de adivinhar é baixo.
- **Impacto:** Força bruta e credential stuffing contra os logins do painel sem nenhum freio.
- **O que fiz:** Não alterei: exige estado novo (tabela, cache ou proxy) e política de bloqueio que é decisão do dono do produto. Recomendo limite por IP/conta no proxy ou WAF. O bcrypt (~0,27 s por tentativa medido em PHP 8.4) reduz a taxa, mas não substitui o limite. — **não corrigido** (só reportado).

### F14 — Parâmetros em array derrubam a página com TypeError e stack trace

- **Onde:** `code/index.php:22` — mesmo problema com `$_GET['busca']` em `code/index.php:72-73` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **baixa** · **95/100**
- **Mecanismo:** index.php:22 repassa $_POST['login'] e $_POST['senha'] direto a autenticar(string, string), e 72-73 repassa $_GET['busca'] a listarChamados(string). Com `login[]=x`, `senha[]=x` ou `busca[]=x` o PHP entrega um array e lança TypeError não tratado. Reproduzido nos três casos: HTTP 500 com `Uncaught TypeError` (registrado no log) e, com display_errors ligado, caminhos do servidor e stack trace na própria resposta. O caso do login nem exige sessão.
- **Impacto:** Qualquer visitante provoca HTTP 500 à vontade, e vaza caminhos internos quando display_errors está ligado.
- **O que fiz:** Só valores string são aceitos: login/senha não-string mostram o formulário de login; busca não-string vira ''; ver não-string vira 0. Reteste sem erros no log. — **corrigido**.

### F15 — Tratamento de falha de conexão nunca executa no PHP 8.1+

- **Onde:** `code/index.php:9-12` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **baixa** · **85/100**
- **Mecanismo:** Desde o PHP 8.1 `new mysqli()` lança mysqli_sql_exception quando a conexão falha, então o `if ($db->connect_errno) die('Falha ao conectar ao banco.')` (index.php:10-12) é código morto. Reproduzido com senha errada: o usuário recebe `Uncaught mysqli_sql_exception` com o caminho do arquivo, usuário e host do banco e o stack trace (o PHP 8.4 mascara o argumento da senha; em versões anteriores o argumento pode aparecer no trace, conforme zend.exception_ignore_args).
- **Impacto:** Vazamento de informação de infraestrutura e página de erro no lugar da mensagem prevista quando o banco está fora do ar.
- **O que fiz:** try/catch de mysqli_sql_exception: o detalhe vai para error_log e o navegador recebe 'Falha ao conectar ao banco.'. O teste de connect_errno foi mantido para PHP antigo. — **corrigido**.

### F16 — fputcsv() sem o parâmetro $escape: Deprecated no PHP 8.4 e CSV lido errado com \"

- **Onde:** `code/lib.php:130-138` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **baixa** · **85/100**
- **Mecanismo:** fputcsv() sem $escape (lib.php:130 e 138) emite E_DEPRECATED no PHP 8.4 a cada linha; reproduzido: com display_errors ligado os avisos HTML entram no corpo do CSV, antes do cabeçalho de colunas. Além disso o escape padrão `\` faz um título com `\"` ser gravado de um jeito que um leitor RFC 4180 lê errado: testado com o módulo csv do Python, `Titulo com \" aspas` voltou como `Titulo com \ aspas"` (a aspa migrou para o fim do campo).
- **Impacto:** Export corrompido em ambientes com display_errors ligado e títulos com barra invertida seguida de aspas lidos incorretamente por Excel/Python/integração de faturamento.
- **O que fiz:** Os 5 argumentos passam explícitos, com escape '' (RFC 4180). A saída é byte a byte idêntica para dados que não têm `\"`; e requer PHP >= 7.4 (testado em 8.4.26). — **corrigido**.

### F17 — mediaResposta() traz todas as linhas para o PHP só para somar

- **Onde:** `code/lib.php:109-115` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** performance · **baixa** · **90/100**
- **Mecanismo:** `SELECT minutos_resposta … IS NOT NULL` seguido de um while que soma em PHP (lib.php:109-115), a cada carregamento da listagem (index.php:74): o tráfego é proporcional ao histórico inteiro para um número que o banco calcula numa passada.
- **Impacto:** Custo desnecessário por página que cresce com o histórico. Hoje sem efeito visível.
- **O que fiz:** `SELECT COUNT(minutos_resposta), SUM(minutos_resposta)` e a divisão continua em PHP: mesmo resultado numérico, 25.666666666666668 idêntico ao original (AVG() do banco arredondaria para 4 casas e mudaria o valor de retorno). — **corrigido**.

### F18 — Condicionais aninhadas em rotuloPrioridade() e cadeia else-if em formatarStatus(); limite de SLA como número solto

- **Onde:** `code/lib.php:40-59` — `formatarStatus()` em `code/lib.php:26-35` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** qualidade · **baixa** · **90/100**
- **Mecanismo:** rotuloPrioridade() tem 4 níveis de if/else aninhados (lib.php:42-58), com o limite de 30 minutos e os cortes de prioridade (>= 3, == 4) espalhados; é difícil enxergar que prioridade alta sem resposta cai no último ramo (ver F19) e é o tipo de função em que a próxima mudança de regra quebra outro ramo. formatarStatus() usa `==` frouxo numa cadeia else-if.
- **Impacto:** Custo de manutenção e risco de regressão numa regra de negócio que o relatório gerencial consome.
- **O que fiz:** Cláusulas de guarda com retorno antecipado e o limite de SLA numa variável nomeada; semântica inalterada. Verificado contra o original em 64 combinações (8 prioridades × 8 valores de minutos, incluindo null e a fronteira 30/31) e em 7 valores de status: saídas idênticas. — **corrigido**.

### F19 — Chamado crítico sem primeira resposta aparece como 'Aguardando 1a resposta'

- **Onde:** `code/lib.php:42-58` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **baixa** · **30/100**
- **Mecanismo:** rotuloPrioridade() testa `$minutos !== null` primeiro (lib.php:42 e 56-58): qualquer prioridade sem resposta vira 'Aguardando 1a resposta'. No seed, o chamado 104 (prioridade 4, crítica, aberto e sem resposta) aparece assim na coluna Prioridade, e a criticidade só surge depois da primeira resposta.
- **Impacto:** Se a intenção era destacar os críticos, os mais urgentes ficam sem destaque justamente enquanto ninguém respondeu. Se for intencional, não há defeito; por isso a confiança é baixa.
- **O que fiz:** Não alterei: o texto é regra de negócio consumida pelo relatório gerencial e o manifesto fixa o contrato. É uma decisão do dono do produto. — **não corrigido** (só reportado).

### F20 — formatarStatus() rotula como 'Resolvido' qualquer status diferente de 1 e 2

- **Onde:** `code/lib.php:32-34` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** bug · **baixa** · **40/100**
- **Mecanismo:** O `else` final (lib.php:32-33) captura 0, 4, 99 e qualquer outro valor. Hoje o schema só documenta 1 a 3, mas a coluna é TINYINT sem CHECK ou ENUM: um status novo (por exemplo 4 = cancelado) apareceria como 'Resolvido' na tela e no CSV, e o relatório gerencial, que casa por texto, o contaria como resolvido.
- **Impacto:** Risco latente de rotular errado e de distorcer indicadores quando surgir um novo status.
- **O que fiz:** Não alterei: só 1, 2 e 3 estão definidos no contrato e trocar o texto de outros valores seria uma mudança visível. Recomendo `CHECK (status BETWEEN 1 AND 3)` e um rótulo explícito para desconhecido quando o negócio definir. — **não corrigido** (só reportado).

### F21 — Listagem e exportação carregam todos os chamados, sem paginação

- **Onde:** `code/lib.php:80-92` — renderizado em `code/index.php:88-96` *(linhas da numeração original)*
- **Categoria · severidade · confiança:** performance · **baixa** · **60/100**
- **Mecanismo:** listarChamados() lê a tabela inteira com `SELECT *` (inclusive `descricao`, TEXT, que a tela não usa) e monta o array completo; index.php:88-96 renderiza todas as linhas numa única tabela. Não há LIMIT.
- **Impacto:** Memória, tempo e tamanho do HTML crescem com o histórico. Sem efeito com 5 linhas, mas é o próximo gargalo.
- **O que fiz:** Não alterei: paginar muda a listagem visível e o contrato ('uma linha por chamado'), e o `SELECT *` faz parte do formato de retorno de listarChamados(). No CSV, só as 5 colunas necessárias são lidas. — **não corrigido** (só reportado).

### F22 — Camada de dados acoplada a HTTP e API 'irrestrita' convivendo com a 'segura'

- **Onde:** `code/lib.php:148-150` — e `code/index.php` inteiro (97 linhas) *(linhas da numeração original)*
- **Categoria · severidade · confiança:** arquitetura · **baixa** · **70/100**
- **Mecanismo:** exportarCsv() mistura consulta, formatação e resposta HTTP (`header()`, `readfile`, lib.php:148-150) e dependia da constante global EXPORT_DIR; index.php faz conexão, sessão, autenticação, autorização, roteamento e HTML com echo. Consequência concreta: como as assinaturas estão congeladas pelo manifesto, a regra de visibilidade não pôde entrar como parâmetro das funções antigas. Hoje o caminho seguro (`…Visiveis`) convive com o irrestrito (as 7 assinaturas originais), e quem chamar listarChamados() num contexto web reintroduz F3.
- **Impacto:** Manutenibilidade e um risco de regressão de segurança a cada novo consumidor da lib.
- **O que fiz:** Parcial e mantido assim de propósito: removi o arquivo e a dependência de EXPORT_DIR e concentrei a consulta em consultarChamadosBase(); header e saída direta permanecem porque o manifesto define exportarCsv() como 'escreve o CSV na saída'. Não separei camadas nem criei templates (fora de 'evoluir, não reescrever'). O aviso está no cabeçalho do lib.php. — **não corrigido** (só reportado).

## 3. Decisões — o que deliberadamente não mudei, e por quê

1. **As 7 assinaturas do manifesto (nomes, parâmetros, tipos, retorno).** É o contrato. A regra de visibilidade precisa saber quem é o usuário, então entrou como 3 funções NOVAS (listarChamadosVisiveis, verChamadoVisivel, exportarCsvVisivel) e as originais viraram wrappers sem restrição. Conferi mecanicamente que as 7 linhas `function …` são idênticas ao original. Custo assumido: as originais continuam 'irrestritas' para quem as chamar (ver F22).
2. **Rótulos de prioridade/SLA e de status (F19, F20).** São regra de negócio e o relatório gerencial casa por texto. Só reestruturei o código, sem trocar nenhuma saída (64 combinações + 7 status comparados com o original).
3. **mediaResposta() segue global, sem filtro por dono.** É um indicador agregado do painel, a assinatura não recebe usuário e o número não expõe nenhum chamado individual. Filtrar por dono mudaria o valor mostrado aos clientes e o relatório gerencial.
4. **Curingas do LIKE (`%`, `_`) não são escapados na busca.** Depois de parametrizada a consulta, deixa de ser vulnerabilidade: o curinga só alarga a busca dentro do que o usuário já pode ver. Escapar mudaria o comportamento atual de quem usa `%` em links e favoritos.
5. **Respostas HTTP: 'Chamado nao encontrado.' continua com status 200; chamado alheio dá a mesma resposta que inexistente.** Manter o contrato e não confirmar a existência de ids de outros clientes (sem 403). Trocar para 404 mudaria o comportamento observável.
6. **Estrutura HTML e formulário de login (sem token CSRF, sem mensagem de erro de login).** O manifesto fixa a estrutura da listagem. CSRF no login tem impacto baixo e o SameSite=Lax já cobre o caso comum; mensagem de erro é decisão de produto.
7. **`SELECT *` em listarChamados() e verChamado(), e retorno em texto (strings).** Consumidores podem usar qualquer coluna e já recebem tudo como string. Os prepared statements devolvem inteiros nativos, então normalizo para texto (linhaComoTexto) para manter o formato exato. autenticar() continua devolvendo id inteiro, como o original.
8. **tecnicoNome() mantida, marcada @deprecated.** Fora do manifesto, mas pública em lib.php e algum script interno pode chamá-la. Só a parametrizei.
9. **Constante EXPORT_DIR e o caminho /var/www/painel/tmp.** Mantidos no config.php porque scripts externos podem referenciá-los. RISCO A CONFERIR: se a rotina noturna dependia do arquivo `chamados.csv` que o original deixava no diretório (efeito colateral que o manifesto não declara — ele fala em 'escreve o CSV na saída'), passará a precisar capturar a saída do exportarCsv().
10. **seed.sql continua com MD5().** Precisa manter os usuários de teste do manifesto (ana/senha123 etc.). Eles migram para password_hash() no primeiro login, depois do ALTER.
11. **Paginação, limite de tentativas de login, rota de logout, revalidação do papel na sessão, cabeçalhos CSP/X-Frame-Options/HSTS (F13, F21).** Exigem estado novo, mudam o contrato da listagem ou pertencem ao proxy/servidor. A sessão guarda o papel do login e não o relê: rebaixar um técnico só vale no próximo login. Tudo isso merece tarefa própria.
12. **Rotação das credenciais vazadas e migração dos hashes de contas inativas.** São ações operacionais que o código não faz. A senha do banco e a chave SMTP precisam ser trocadas (F6) e contas que nunca mais logarem ficam em md5 até um reset (F7).
13. **display_errors / error_reporting do PHP.** É configuração do servidor, não do código. Recomendo display_errors=Off e log_errors=On em produção; várias das exposições de F14/F15 só aparecem com display_errors ligado.
14. **Stack, camada de acesso a dados e arquivos.** Segui as restrições: continua PHP + mysqli, nenhum arquivo foi renomeado ou movido, nenhuma dependência nova, nenhuma reescrita (8 funções pequenas foram acrescentadas ao lib.php; o resto é edição in-place).

## 4. Compatibilidade com o manifesto

| Item do manifesto | Situação |
| --- | --- |
| Assinaturas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv` | **Idênticas** ao original (comparadas linha a linha). Só foram *acrescentadas* funções. |
| `formatarStatus`: 1→Aberto, 2→Em atendimento, 3→Resolvido | Igual (saídas para −1..99 idênticas ao original). |
| `listarChamados` traz `tecnico_nome` | Igual: mesma chave, '-' quando não há técnico, valores em texto como antes. |
| Rotas `?busca`, `?ver`, `?export=csv` | Mesmos nomes de parâmetro. |
| CSV: cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, ordem por id, rótulos de status | Byte a byte igual para técnicos. Duas mudanças deliberadas e restritas: títulos que começam com `= + - @` ganham `'` na frente (F12) e o escape de `\"` segue RFC 4180 (F16). |
| HTML da listagem: `id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico, ID como link `?ver=<id>` | Idêntico. |
| Regra de visibilidade (cliente só os seus; técnico todos) | **Passou a ser aplicada** (F2-F4). Antes qualquer usuário via tudo. |
| Usuários de teste `ana`/`bruno`/`carla`/`diego` | Todos entram, também numa instalação limpa com o `schema.sql`+`seed.sql` entregues. |

## 5. O que executei

Ambiente: PHP 8.4.26, MariaDB 11.8.6 privado (porta própria, datadir dentro da pasta; um servidor que já escutava em 3306 **não foi tocado**), servidor embutido do PHP e `curl` com sessões reais.

| Verificação | Resultado |
| --- | --- |
| Regressão HTTP, roteiro de 55 respostas (4 usuários × lista/busca/CSV/8 detalhes + login) original × novo | 41 idênticas; as 14 restantes são as esperadas: chamados de outros clientes que deixam de aparecer para `ana`/`bruno` (lista, detalhes, CSV) e os cabeçalhos `Host`/`Date`. **Técnicos: 100% idêntico.** |
| Funções (retorno **e tipos** via `var_export`): autenticar (9 casos), formatarStatus (7), rotuloPrioridade (64), tecnicoNome, listarChamados (10 buscas), verChamado (8), mediaResposta, exportarCsv | Idênticos, **exceto**: `O'Brien` (original lançava exceção; agora `[]`) e `' OR '1'='1` (original devolvia os 5 chamados; agora `[]`). |
| Ataques reproduzidos no original e repetidos no novo | SQLi UNION/booleana: bloqueadas · XSS: escapado · `busca[]`, `login[]`, `senha[]`: sem erro · fixação de sessão: ID muda, antigo invalidado, ID inventado descartado · sem arquivo em `EXPORT_DIR` |
| Migração de senha | CHAR(32): login ok, hash intacto, sem custo de bcrypt (3 ms); com `sql_mode` não estrito também intacto (nada de truncar). Após o ALTER: migra no 1º login, o seguinte usa `password_verify`; senha errada nunca migra |
| Sem dados / vazio | `mediaResposta()` = 0.0 sem respostas e com tabela vazia; listagem e CSV ok |
| CSV hostil (fórmulas, aspas, vírgula, quebra de linha, acentos) | 11 linhas × 5 colunas ao ler com o csv do Python; fórmulas neutralizadas |
| Banco fora do ar | 'Falha ao conectar ao banco.' na tela, detalhe no log |
| Avisos PHP com `E_ALL` e `display_errors=1` no código novo | Nenhum (o original emitia Deprecated no CSV) |

**O que não verifiquei:** MySQL 8 propriamente dito; concorrência real de duas exportações simultâneas (a falha de F8 é demonstrada pela leitura do código e pelo arquivo persistente, não por uma corrida provocada); o comportamento atrás de proxy TLS; scripts externos do ISP (rotina noturna, relatório gerencial), que não estão no pacote.
