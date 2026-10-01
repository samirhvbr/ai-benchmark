# RELATORIO — LEB-100-A (Painel de Chamados, NetX ISP)

| | |
| --- | --- |
| Instância | LEB-100-A (versão 1.1) |
| Matriz (SHA-256) | `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625` |
| Entregáveis | `code/` alterado no lugar · este relatório · `achados.json` |
| Ambiente de teste | PHP 8.4.26 (CLI e servidor embutido), mysqli/mysqlnd, **MariaDB 11.8.6** (não MySQL 8), `schema.sql` + `seed.sql` do pacote |

## 1. Resumo

O sistema são ~300 linhas de PHP/mysqli e, como veio, **qualquer usuário logado, inclusive um cliente, conseguia** (reproduzido contra o código original, não só lido; no XSS, conferi a injeção no HTML devolvido, sem executar um navegador):
extrair `login:md5` de todos os usuários por SQL injection na busca (F1); ver, listar e baixar em CSV os chamados de outros clientes, porque a regra de visibilidade do manifesto não era aplicada em lugar
nenhum (F2, F3, F4); e executar JavaScript no navegador de outro usuário por XSS refletido (F5). A senha do banco de produção e a chave SMTP estavam no código (F6).
Fora da segurança: a tela principal cai com divisão por zero enquanto não existir um chamado respondido (F12); listagem e CSV fazem uma consulta por linha (F13); e o CSV passa por um arquivo
compartilhado em disco que **corrompe a exportação sob concorrência** (reproduzido: 10 571 linhas em vez de 20 006) e deixa dados pessoais no servidor (F9, F10).

Foram **22 achados**: 1 crítico, 6 altos, 8 médios e 7 baixos. **19 estão corrigidos no código**; 3 só reportados, com justificativa
(F7, F15, F20: MD5, limite de tentativas de login e paginação), porque exigem mudança de schema, estado persistente ou alteram o contrato.

A correção foi cirúrgica: mesmos arquivos, mesmos nomes, **todas as assinaturas públicas, rotas, parâmetros, HTML e CSV preservados**. A regra de visibilidade foi implementada por funções *novas* (`podeVerChamado`,
`listarChamadosVisiveis`, `exportarCsvVisiveis`), sem tocar nas assinaturas do manifesto. Para técnico, as respostas são idênticas byte a byte às do original (listagem, três buscas, detalhes dos chamados 101, 103 e 104, id inexistente, `ver[]` e CSV).

**Duas ações de vocês antes de implantar** (detalhes em F6): definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP, e **rotacionar** essas duas credenciais, que seguem no histórico e nos backups.
Sem `DB_PASS` o painel responde 500 "Falha ao conectar ao banco.".

## 2. Achados (na ordem em que eu corrigiria)

### F1 — SQL injection em listarChamados(): o parâmetro `busca` é concatenado no SQL

- **Onde:** `code/lib.php` L80–85 (`listarChamados`; entrada em `index.php` L72–73)
- **Categoria · severidade · confiança:** segurança · **crítica** · **99/100**
- **Mecanismo:** `$_GET['busca']` chega a `listarChamados()` (index.php L72–73) e é concatenado cru no SQL (`... WHERE titulo LIKE '%" . $busca . "%'`, lib.php L82), executado por `$db->query()` (L85); uma aspa sai do literal. Reproduzido no original: `busca=zzz' UNION SELECT 1,2,3,CONCAT(login,':',senha),5,1,1,1,NOW() FROM usuarios -- ` devolveu `login:md5` dos 4 usuários na coluna Título, autenticado como cliente (`ana`); `busca='` gera erro de sintaxe SQL; `a' OR '1'='1` é executado (o OR injetado altera o resultado).
- **Impacto:** Qualquer usuário autenticado, inclusive cliente, lê qualquer tabela que o usuário `painel` do banco alcance. Os hashes MD5 dos técnicos (F7) permitem escalar para técnico. `query()` não aceita várias instruções, então não há escrita direta por aqui; o alcance real depende dos privilégios do usuário do banco, que não vi.
- **O que fiz:** O termo virou parâmetro de consulta preparada (`c.titulo LIKE ?` com `bind_param('s')`, em `consultarChamados()`); a assinatura `listarChamados(mysqli, string = '')` não mudou. A semântica do LIKE foi preservada (`%` e `_` continuam curingas, ver Decisões). Verificado: os três payloads acima retornam 0 linhas e nenhum erro; buscas normais retornam exatamente o que retornavam (comparação por `var_export`, incluindo tipos e ordem das chaves). _(corrigido no código: sim)_

### F2 — Detalhe do chamado (`?ver=`) não confere o dono: cliente lê chamados de outros clientes

- **Onde:** `code/index.php` L52–66 (rota `?ver=`; `verChamado()` em `lib.php` L98–102)
- **Categoria · severidade · confiança:** segurança · **alta** · **98/100**
- **Mecanismo:** A rota chama `verChamado($db, (int) $_GET['ver'])` e imprime o resultado sem comparar `usuario_id` com `$_SESSION['uid']`; `$papel` é lida na L39 e nunca usada. Reproduzido: `ana` (cliente) abriu `ver=103` e `ver=104`, chamados de `bruno`, e viu título, status, prioridade e descrição. Os ids são sequenciais, então a enumeração é trivial.
- **Impacto:** Um cliente lê chamados (inclusive a descrição, que pode ter dado pessoal/financeiro) de qualquer outro cliente. Viola a regra de visibilidade do manifesto.
- **O que fiz:** Nova `podeVerChamado(array $chamado, int $usuarioId, string $papel): bool` (técnico vê qualquer chamado; qualquer outro papel, só os que abriu; papel desconhecido é tratado como cliente), aplicada no index.php. Chamado de outro cliente recebe a mesma resposta de chamado inexistente ("Chamado nao encontrado."), para não confirmar que o id existe. `verChamado()` manteve assinatura e retorno (os scripts do ISP continuam sem filtro). Verificado: dono e técnico recebem a mesma página, byte a byte, que o original devolvia; terceiro recebe "nao encontrado". _(corrigido no código: sim)_

### F3 — Listagem e busca mostram os chamados de todos os clientes

- **Onde:** `code/index.php` L72–73 (e o laço L88–96)
- **Categoria · severidade · confiança:** segurança · **alta** · **98/100**
- **Mecanismo:** A listagem usa `listarChamados($db, $busca)`, que devolve a tabela inteira, sem filtrar pelo usuário da sessão. Reproduzido: `ana` listou os 5 chamados (101–105) e `bruno` também. A busca `?busca=` varre os títulos alheios.
- **Impacto:** Vazamento de títulos, status, prioridade e técnico dos chamados de todos os clientes; mesma regra violada de F2.
- **O que fiz:** Nova `listarChamadosVisiveis($db, $usuarioId, $papel, $busca)`: cliente recebe `WHERE c.usuario_id = ?` aplicado no SQL (não no PHP); técnico, sem filtro. O index.php passa a usá-la. `listarChamados()` continua devolvendo tudo (uso interno e relatórios), e o docblock avisa. Verificado: `ana` vê 101, 102, 105; `bruno` vê 103, 104; `carla` e `diego` veem os 5, com HTML idêntico ao original. _(corrigido no código: sim)_

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php` L44–47 (rota `?export=csv`; consulta sem escopo em `lib.php` L132)
- **Categoria · severidade · confiança:** segurança · **alta** · **95/100**
- **Mecanismo:** `?export=csv` chama `exportarCsv($db)`, que faz `SELECT * FROM chamados` sem escopo, para qualquer papel. Reproduzido: `ana` baixou o CSV com os 5 chamados, inclusive os de `bruno` (título, técnico, data).
- **Impacto:** Vazamento em massa numa única requisição. Contorna qualquer correção feita só em listagem e detalhe, por isso é um achado separado de F3 e F2.
- **O que fiz:** Nova `exportarCsvVisiveis($db, $usuarioId, $papel)`: cliente exporta só os próprios chamados; técnico, todos. Rota, cabeçalho e formato não mudaram. `exportarCsv($db)` foi mantida com a mesma assinatura e o conteúdo completo, para a rotina noturna. Escolhi escopar (e não negar) para não remover a funcionalidade do cliente (ver Decisões). Verificado: `ana` recebe 101, 102, 105; `bruno`, 103 e 104; `carla` recebe o CSV completo, idêntico byte a byte ao do original. _(corrigido no código: sim)_

### F5 — XSS refletido: `busca` é impressa sem escape no atributo `value` e no texto da página

- **Onde:** `code/index.php` L79 (atributo) e L82 (texto)
- **Categoria · severidade · confiança:** segurança · **alta** · **98/100**
- **Mecanismo:** `$busca` (`$_GET['busca']`) é concatenada sem `htmlspecialchars` em dois contextos: `value="' . $busca . '"` (L79) e `Resultados para: ' . $busca` (L82). Reproduzido: `busca="><script>alert(1)</script>` fecha o atributo e injeta `<script>` no HTML devolvido; `<img src=x onerror=alert(2)>` entra no texto (conferi o HTML da resposta; não executei um navegador). Os demais campos (título, descrição, técnico) já eram escapados.
- **Impacto:** Um link com a busca maliciosa (o painel tem "links e favoritos externos") executa JavaScript no navegador do técnico ou cliente logado: lê chamados, age em nome dele e, como o cookie de sessão não tinha HttpOnly (F8), rouba a sessão.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos (`$buscaHtml`). Em profundidade, cabeçalhos `Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, `X-Content-Type-Options: nosniff` e `X-Frame-Options: DENY` (a página não usa script, estilo nem recurso externo). Verificado: os payloads saem como `&quot;&gt;&lt;script&gt;...`; buscas normais geram HTML idêntico. Efeito colateral intencional: o painel não pode mais ser embutido em `<iframe>`. _(corrigido no código: sim)_

### F6 — Senha do banco de produção e chave SMTP embutidas no código

- **Onde:** `code/config.php` L12 (`DB_PASS`) e L15 (`SMTP_API_KEY`)
- **Categoria · severidade · confiança:** segurança · **alta** · **99/100**
- **Mecanismo:** `DB_PASS` cai em `'N3tX@2013!prod'` sempre que a variável de ambiente não existe (L12), e `SMTP_API_KEY` é um literal (L15). Quem lê o repositório, um backup ou o histórico tem a senha do banco de produção e a chave do provedor de e-mail. O fallback ainda esconde a configuração errada: o sistema "funciona" sem a variável.
- **Impacto:** Acesso direto ao banco de produção e uso indevido da conta de e-mail transacional por qualquer pessoa com acesso ao código.
- **O que fiz:** Os dois valores passam a vir só do ambiente (`getenv`). Sem `DB_PASS` o banco recusa a conexão e o index.php responde 500 "Falha ao conectar ao banco.", com o detalhe só no log (sem o segredo). **Ação de vocês, fora do código:** (1) definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP antes de implantar esta versão; (2) **rotacionar as duas credenciais**, porque continuam no histórico e nos backups. Não consegui verificar como a produção injeta `DB_PASS` hoje; se ela depende do fallback, a implantação sem a variável derruba o painel. _(corrigido no código: sim)_

### F7 — Senhas guardadas como MD5 sem sal

- **Onde:** `code/lib.php` L15–17 (`autenticar`); coluna `senha CHAR(32)` em `code/schema.sql` L7
- **Categoria · severidade · confiança:** segurança · **alta** · **95/100**
- **Mecanismo:** `autenticar()` compara `md5($senha)` com `usuarios.senha`. MD5 é rápido (bilhões de tentativas por segundo) e sem sal: a mesma senha gera o mesmo hash (no seed, `ana` e `bruno` têm o mesmo `e7d80ffe...`, `carla` e `diego` também). Com o hash em mãos (F1 permitia isso a qualquer usuário), a senha cai por tabela pré-computada ou força bruta em segundos. A consulta em si é parametrizada: o problema é só o algoritmo.
- **Impacto:** Comprometimento de contas, inclusive de técnicos, após qualquer vazamento do banco ou de backup.
- **O que fiz:** **Não alterei o armazenamento (decisão consciente).** A coluna é CHAR(32) (um hash moderno não cabe), a tabela provavelmente é lida por outros sistemas do ISP que podem validar MD5 e o seed e os usuários de teste dependem dele; trocar o hash de forma unilateral trancaria usuários. Só deixei um comentário no código. Plano proposto: (1) `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`; (2) migração em lote "embrulhando" o hash atual: `senha = password_hash(md5_atual)`; (3) `autenticar()` aceita os dois formatos (`password_verify(md5($senha), $h)` para o embrulhado) e, a cada login bem-sucedido, regrava com `password_hash($senha)`; (4) após um ciclo, forçar a troca de senha de quem ainda estiver em MD5. _(corrigido no código: não — só reportado)_

### F8 — Fixação de sessão: ID não é renovado no login e o cookie sai sem HttpOnly/SameSite

- **Onde:** `code/index.php` L24–27 (login sem `session_regenerate_id`) e L15 (`session_start()` sem parâmetros do cookie)
- **Categoria · severidade · confiança:** segurança · **média** · **90/100**
- **Mecanismo:** O ID de sessão não é trocado depois do login e o PHP roda com `session.use_strict_mode=0` (padrão), então aceita um ID proposto pelo cliente. Reproduzido: login da `carla` enviando `PHPSESSID=fixado...` escolhido pelo "atacante"; depois, uma requisição apenas com esse cookie recebeu a listagem autenticada. O cookie era emitido como `PHPSESSID=...; path=/` (sem HttpOnly, sem SameSite).
- **Impacto:** Quem consegue plantar um cookie na vítima (o XSS de F5, um subdomínio comprometido, uma rede sem TLS) assume a sessão assim que ela faz login; sem HttpOnly, um XSS também lê o cookie.
- **O que fiz:** `session_regenerate_id(true)` ao autenticar; `session.use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax` e `cookie_secure=1` quando a requisição é HTTPS (via `ini_set`, que funciona também em PHP < 7.3). Verificado: o SID do atacante deixa de dar acesso e o novo cookie sai com `HttpOnly; SameSite=Lax`. Lax (e não Strict) para não quebrar os links e favoritos externos. _(corrigido no código: sim)_

### F9 — Exportação grava num arquivo compartilhado de nome fixo: duas exportações sobrepostas corrompem o CSV

- **Onde:** `code/lib.php` L125–126 (caminho fixo e `fopen(..., "w")`) e L150 (`readfile`)
- **Categoria · severidade · confiança:** bug · **média** · **92/100**
- **Mecanismo:** `exportarCsv()` grava sempre em `EXPORT_DIR/chamados.csv` (L125) com `fopen('w')`, que trunca (L126), fecha e só depois relê com `readfile()` (L150), sem lock nem nome único. A segunda exportação trunca o que a primeira já escreveu, e a primeira devolve um CSV com buraco. Reproduzido com 20 mil chamados (4 workers): com a 2ª requisição iniciada a 60% e a 75% da duração da 1ª, a 1ª recebeu 15 883 e 10 571 linhas (esperado: 20 006), com 295 KB e 675 KB de bytes NUL no meio. Exportações simultâneas e escalonadas de 1,2 s (a 2ª entra bem no começo da 1ª) saíram íntegras, por isso o defeito é intermitente e difícil de perceber.
- **Impacto:** CSV truncado entregue como válido (HTTP 200) a quem consome a exportação (relatórios, faturamento). E um arquivo único é incompatível com exportar por usuário (F4): um técnico e um cliente exportando ao mesmo tempo trocariam conteúdo.
- **O que fiz:** O CSV é escrito direto na saída (`php://output`), linha a linha, sem arquivo intermediário (`escreverCsv()`): não há estado compartilhado. Verificado com 6 exportações simultâneas e 6 escalonadas: todas idênticas e completas, e byte a byte iguais às do original para o técnico. _(corrigido no código: sim)_

### F10 — O CSV com todos os chamados fica em disco, em caminho previsível e legível por todos

- **Onde:** `code/lib.php` L125–126 e L146–150 (arquivo nunca removido)
- **Categoria · severidade · confiança:** segurança · **média** · **70/100**
- **Mecanismo:** Depois da requisição, `/var/www/painel/tmp/chamados.csv` permanece com título, técnico e data de todos os chamados, com modo `rw-rw-r--` (observado no teste) e sem remoção. Se `tmp/` estiver sob a raiz web (o caminho sugere, mas não pude verificar a configuração do servidor), `GET /tmp/chamados.csv` entrega tudo sem autenticação. No teste, o arquivo continuou com 12 linhas, 6 delas de chamados de uma transação de teste já revertida: o dado sobrevive até ao banco.
- **Impacto:** Exposição de dados de clientes fora do controle de acesso do painel e retenção indefinida.
- **O que fiz:** Sem arquivo em disco (ver F9). `EXPORT_DIR` continua definida em config.php, mas o `exportarCsv()` não a usa mais. Recomendo apagar o `chamados.csv` que já existe no servidor e negar acesso web a `tmp/`. Confiança menor porque a exposição por HTTP depende de configuração que não vi. _(corrigido no código: sim)_

### F11 — exportarCsv() falha em silêncio: sem arquivo ou sem consulta, devolve resposta vazia como se tivesse dado certo

- **Onde:** `code/lib.php` L126–129 (`fopen` falhou) e L132–135 (consulta falhou)
- **Categoria · severidade · confiança:** bug · **média** · **95/100**
- **Mecanismo:** Se `fopen` falha (diretório inexistente ou sem permissão), a função faz `return;` (L127–129) sem enviar cabeçalho nem corpo; se a consulta falha (L133–135) também retorna calada, deixando o arquivo só com o cabeçalho e o `$fp` aberto. O index.php dá `exit` normalmente. Reproduzido: com `EXPORT_DIR` inexistente (o caso deste ambiente) a rota responde 200 sem CSV algum (só o warning do PHP; com `display_errors=0`, corpo vazio).
- **Impacto:** A rotina noturna e o usuário recebem "sucesso" com saída vazia: a exportação se perde sem alarme.
- **O que fiz:** Não há mais abertura de arquivo. Falha de consulta lança exceção (`consultaPreparada()`), que o index.php registra em log e converte em HTTP 500 genérico, e que um script de cron enxerga como erro. Os cabeçalhos só são enviados depois que a consulta funcionou. _(corrigido no código: sim)_

### F12 — mediaResposta() divide por zero quando nenhum chamado tem 1ª resposta e derruba a tela principal

- **Onde:** `code/lib.php` L116 (função L107–117; chamada em `index.php` L74)
- **Categoria · severidade · confiança:** bug · **média** · **98/100**
- **Mecanismo:** `$soma / $qtd` com `$qtd = 0` quando nenhuma linha tem `minutos_resposta` (instalação nova, base expurgada, só chamados sem resposta). No PHP 8 é `DivisionByZeroError` não tratada (no PHP 7, aviso e NAN). A função roda em toda renderização da listagem. Reproduzido: com todos os `minutos_resposta` NULL, `mediaResposta()` lança `DivisionByZeroError`.
- **Impacto:** A página principal inteira cai com erro fatal (500) enquanto não existir um chamado respondido.
- **O que fiz:** Retorna `0.0` quando não há dado (a tela mostra "0 min", com o mesmo texto de antes). Com dados o valor é idêntico ao original (25.666666666666668 no seed). _(corrigido no código: sim)_

### F13 — N+1: uma consulta por chamado para buscar o nome do técnico (listagem e CSV)

- **Onde:** `code/lib.php` L88–91 e L137 (chamadas) e L64–72 (`tecnicoNome`, L69)
- **Categoria · severidade · confiança:** performance · **média** · **98/100**
- **Mecanismo:** Para cada chamado, a listagem (L89) e o CSV (L137) chamam `tecnicoNome()`, que executa `SELECT nome FROM usuarios WHERE id = ...` (L69). Como a listagem não é paginada (F20), o custo cresce com todo o histórico, a cada visualização. Medido por requisição: 5 chamados, 8 consultas; 305 chamados, 308 (o novo: 4 em ambos os casos). Exportar 20 mil chamados levou 4,3 s sozinho e 8–11 s com 6 exportações simultâneas no original, contra 0,1–0,4 s no novo.
- **Impacto:** Latência e carga no banco crescentes: a tela inicial e a exportação ficam lentas conforme o histórico acumulado desde 2013 cresce.
- **O que fiz:** `LEFT JOIN usuarios u ON u.id = c.tecnico_id` com `COALESCE(u.nome, '-')`: mesma chave `tecnico_nome`, mesmo "-" para chamado sem técnico, e um número constante de consultas. `tecnicoNome()` foi mantida (assinatura intacta) por causa de relatórios internos que eu não vejo, mas listagem e CSV deixaram de chamá-la. _(corrigido no código: sim)_

### F14 — CSV injection: título digitado pelo cliente vai ao CSV sem neutralizar `=`, `+`, `-`, `@`

- **Onde:** `code/lib.php` L138–144 (linha de dados do CSV)
- **Categoria · severidade · confiança:** segurança · **média** · **60/100**
- **Mecanismo:** `titulo` é texto livre do cliente. `fputcsv` cuida de aspas e vírgulas, mas uma célula iniciada por `=`, `+`, `-` ou `@` é interpretada como fórmula quando o técnico abre o arquivo em Excel ou LibreOffice (por exemplo `=HYPERLINK(...)` para exfiltrar outras células). Reproduzido: o título `=HYPERLINK("http://evil/","x")` sai literalmente como fórmula no CSV do original.
- **Impacto:** Um cliente mal-intencionado atinge a estação do técnico que abre a exportação. Exige abrir em planilha e aceitar os avisos do aplicativo, por isso severidade média e confiança 60.
- **O que fiz:** `celulaCsv()` prefixa `'` em Titulo e Tecnico que comecem por `=`, `+`, `-`, `@`, TAB ou CR (recomendação OWASP); o "-" de "sem técnico" não é afetado. Custo assumido: um título que realmente começa com `+` ou `-` (ex.: "+55 11...") aparece com `'` na frente, e um consumidor programático veria esse caractere. Se isso atrapalhar o faturamento, a alternativa é limitar o prefixo à exportação feita por humanos. _(corrigido no código: sim)_

### F15 — Login sem limite de tentativas

- **Onde:** `code/index.php` L21–29
- **Categoria · severidade · confiança:** segurança · **média** · **75/100**
- **Mecanismo:** O login aceita tentativas ilimitadas: não há contagem por usuário ou IP, atraso nem bloqueio (L21–29); cada tentativa é uma consulta barata, as senhas são MD5 (F7) e o seed mostra senhas do tipo `senha123`.
- **Impacto:** Adivinhação de senha online e credential stuffing contra contas de técnico. Confiança menor porque um WAF ou fail2ban à frente do painel pode já cobrir isso.
- **O que fiz:** **Não alterei.** Exige estado persistente (tabela de tentativas, APCu, Redis) ou controle no proxy; um limitador por sessão não serve, pois o atacante descarta o cookie. Recomendo rate-limit/fail2ban no servidor web, ou uma tabela `tentativas_login` na próxima mudança de schema. _(corrigido no código: não — só reportado)_

### F16 — fputcsv() sem o argumento `$escape`: no PHP 8.4 o aviso de depreciação cai dentro do CSV

- **Onde:** `code/lib.php` L130 e L138–144
- **Categoria · severidade · confiança:** qualidade · **baixa** · **85/100**
- **Mecanismo:** Sem `$escape`, o PHP 8.4 emite `Deprecated: fputcsv(): the $escape parameter must be provided as its default value will change` a cada chamada. Reproduzido com `display_errors=1`: seis avisos (cabeçalho e 5 linhas) saem no corpo da resposta, antes de `ID,Titulo,...`.
- **Impacto:** CSV inválido para o consumidor quando os avisos são exibidos; ruído de log em produção; vira erro numa versão futura do PHP.
- **O que fiz:** Passa `','`, `'"'` e `'\\'` explícitos, que são o comportamento histórico (bytes idênticos), sem o aviso. _(corrigido no código: sim)_

### F17 — Entradas que chegam como array (`login[]`, `senha[]`, `busca[]`) causam TypeError não tratado

- **Onde:** `code/index.php` L22 (login, antes da autenticação) e L72–73 (busca)
- **Categoria · severidade · confiança:** bug · **baixa** · **97/100**
- **Mecanismo:** `$_POST['login']`, `$_POST['senha']` e `$_GET['busca']` são repassados a funções com parâmetro `string`. `login[]=x` ou `busca[]=x` chegam como array e geram `TypeError` fatal. Reproduzido nos dois casos (com `display_errors=1` a resposta traz o stack trace e o caminho do arquivo); o do login ocorre sem estar autenticado.
- **Impacto:** Qualquer visitante provoca erro fatal e, conforme a configuração, vê caminhos internos.
- **O que fiz:** Verificação `is_string`: array em credencial é tratado como login inválido (mostra o formulário); `busca[]` vira busca vazia. Complementado pelo handler de exceções de F18. _(corrigido no código: sim)_

### F18 — Falha de conexão: o `die()` é inalcançável no PHP >= 8.1 e a exceção expõe detalhes com status 200

- **Onde:** `code/index.php` L9–13
- **Categoria · severidade · confiança:** bug · **baixa** · **85/100**
- **Mecanismo:** Desde o PHP 8.1 `new mysqli()` lança `mysqli_sql_exception` em vez de devolver erro, então o `if ($db->connect_errno) die(...)` (L10–12) nunca executa e a exceção sobe sem tratamento. Reproduzido (PHP 8.4, senha errada): resposta HTTP 200 com `Fatal error: Uncaught mysqli_sql_exception: Access denied for user 'painel'@'localhost' (using password: YES) in .../index.php:9` e stack trace: usuário, host, caminho e a informação de que uma senha foi enviada.
- **Impacto:** Divulgação de detalhes de infraestrutura quando `display_errors` está ligado; resposta 200 numa queda do banco confunde o monitoramento.
- **O que fiz:** `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` e `try/catch` na conexão: mensagem original "Falha ao conectar ao banco." com HTTP 500 e o detalhe só no log; um `set_exception_handler` responde "Erro interno." (500) para qualquer outra exceção, também só com log. _(corrigido no código: sim)_

### F19 — mediaResposta() traz uma linha por chamado respondido só para somar no PHP

- **Onde:** `code/lib.php` L109–115
- **Categoria · severidade · confiança:** performance · **baixa** · **85/100**
- **Mecanismo:** `SELECT minutos_resposta FROM chamados WHERE ... IS NOT NULL` transfere uma linha por chamado respondido e o laço das L112–115 soma em PHP, em toda carga da listagem.
- **Impacto:** Transferência e memória O(n) a cada visualização, por um número que o banco calcula em uma passada.
- **O que fiz:** `SELECT COUNT(minutos_resposta), SUM(minutos_resposta)` e a divisão em PHP. Não usei `AVG()` porque ele devolve DECIMAL com 4 casas e mudaria os últimos dígitos do `float` que a função sempre retornou. _(corrigido no código: sim)_

### F20 — Listagem sem paginação nem limite, com `SELECT *` (inclui a coluna TEXT `descricao`)

- **Onde:** `code/lib.php` L80–92 (e o laço de `index.php` L88–96)
- **Categoria · severidade · confiança:** performance · **baixa** · **60/100**
- **Mecanismo:** `listarChamados()` devolve todos os chamados num array em memória, com a descrição de cada um, e a tela renderiza tudo de uma vez; não há `LIMIT`.
- **Impacto:** Memória e tamanho da página crescem sem limite com o histórico. Confiança 60 porque não sei o volume real de produção.
- **O que fiz:** **Não alterei.** Paginar muda o conteúdo da rota `index.php` e o retorno de `listarChamados()` (contrato do manifesto), e o `SELECT *` fica porque consumidores externos podem usar qualquer coluna. Proposta: parâmetros opcionais novos (`pagina`, `limite`) sem mudar o comportamento padrão. _(corrigido no código: não — só reportado)_

### F21 — rotuloPrioridade(): quatro níveis de if/else aninhados com o limite de SLA embutido

- **Onde:** `code/lib.php` L40–59
- **Categoria · severidade · confiança:** qualidade · **baixa** · **65/100**
- **Mecanismo:** Quatro níveis de if/else para cinco saídas, com o limite de 30 minutos solto no meio; o rótulo "CRITICO" só aparece no ramo mais interno. Difícil de ler e fácil de errar ao mexer. Não há erro de comportamento hoje.
- **Impacto:** Custo de manutenção numa regra de negócio que alimenta a tela e o relatório gerencial.
- **O que fiz:** Reescrita com retornos antecipados e o limite numa variável nomeada, **sem mudar nenhuma saída**: 90 combinações (prioridades -1, 0 a 6 e 127; minutos null, -5, -1, 0, 1, 29, 30, 31, 100 e 100000) comparadas com a função original, todas idênticas. _(corrigido no código: sim)_

### F22 — SQL montado por interpolação em verChamado() e tecnicoNome() (não explorável hoje)

- **Onde:** `code/lib.php` L100 (`verChamado`) e L69 (`tecnicoNome`)
- **Categoria · severidade · confiança:** qualidade · **baixa** · **12/100**
- **Mecanismo:** As duas funções concatenam o argumento no SQL. **Não é explorável hoje**: o parâmetro é `int`/`?int` e o index.php ainda faz `(int) $_GET['ver']`. Testei no original `ver=999 OR 1=1` e `ver=999 UNION SELECT ...`: ambos caem em "Chamado nao encontrado." porque o `(int)` reduz tudo a 999. É um padrão frágil: se alguém relaxar o tipo, vira injeção. A confiança é baixa (12) de propósito: é a probabilidade de ser uma vulnerabilidade real, e não deve ser confundida com F1.
- **Impacto:** Nenhum hoje; risco futuro de regressão.
- **O que fiz:** Convertidas para a consulta preparada (`consultaPreparada()`), por consistência e defesa em profundidade; mesmo retorno (strings e `null`), verificado por `var_export`. _(corrigido no código: sim)_

## 3. Decisões: o que deliberadamente não mudei, e por quê

- **Hash de senha MD5 (`autenticar()` e `usuarios.senha CHAR(32)`) — F7.** Trocar o algoritmo exige alterar a coluna, coordenar com outros sistemas que leem a mesma tabela e migrar usuários; o seed e os usuários de teste do manifesto dependem do MD5. Fazer isso de forma unilateral tranca pessoas fora. Plano de migração descrito em F7.
- **Assinaturas e retorno de `listarChamados`, `verChamado`, `exportarCsv` (continuam devolvendo tudo, sem filtro de usuário).** O manifesto congela nome, parâmetros e retorno, e a rotina noturna, o relatório gerencial e o faturamento precisam do conjunto completo. A visibilidade foi feita com funções novas (`podeVerChamado`, `listarChamadosVisiveis`, `exportarCsvVisiveis`) usadas pelo index.php. Contrapartida: um script novo que chame as funções antigas a partir de uma tela volta a expor tudo; os docblocks avisam.
- **A média de 1ª resposta (`mediaResposta`) continua global para o cliente.** É um agregado que não identifica nenhum chamado; a regra do manifesto trata de ver chamados. Escopar a média por cliente mudaria o número exibido: decisão de produto.
- **`formatarStatus`: qualquer valor diferente de 1 e 2 continua virando 'Resolvido'.** O manifesto só define 1, 2 e 3, e o relatório gerencial casa pelo texto; mudar o default introduz um texto novo ou muda a classificação. O correto é limitar o domínio no banco (`CHECK (status BETWEEN 1 AND 3)`, e prioridade de 1 a 4): migração de schema, fora do escopo.
- **Rótulos e limite de SLA de `rotuloPrioridade` (30 min, textos exatos).** Só reestruturei a função; as saídas são idênticas. Limitação de produto que deixei como está: chamado sem 1ª resposta nunca é marcado como 'SLA estourado' (o cálculo não usa o tempo decorrido desde `criado_em`), então o crítico 104 aparece como 'Aguardando 1a resposta'. Mexer nisso muda rótulos que consumidores usam.
- **Semântica do LIKE da busca (`%` e `_` continuam curingas; padrão `'%termo%'`).** Preservar o comportamento atual (links e favoritos externos). Não há risco de segurança: o filtro por dono é aplicado à parte, então curinga não amplia visibilidade. `%...%` não usa índice (varredura); FULLTEXT mudaria a semântica e exige schema.
- **Status HTTP 200 para 'Chamado nao encontrado.' (inclusive para chamado de outro cliente).** Manter o contrato atual; 404 seria semanticamente melhor, mas muda a resposta de quem já consome. Resposta idêntica para 'inexistente' e 'de outro cliente' evita enumeração de ids.
- **Estrutura HTML, colunas, `id="tabela-chamados"`, cabeçalho e formato do CSV, rótulos de status e ordenações.** É o contrato do manifesto. Para técnico, verificado byte a byte contra o original (listagem, buscas, detalhes e CSV).
- **`schema.sql` e `seed.sql` (nenhuma alteração).** Não foram necessários para as correções. As mudanças desejáveis (CHECK de status e prioridade, coluna de senha mais larga, tabela de tentativas de login) são migrações de produção que precisam ser planejadas, não editadas no script de criação.
- **Controles de login/sessão além do essencial: limite de tentativas, rota de logout, token CSRF no login, expiração própria por inatividade, releitura do papel a cada requisição.** Limite de tentativas precisa de estado persistente (F15). Logout seria uma rota nova na superfície pública. CSRF no login tem impacto baixo (não há outra ação que mude estado). O papel fica congelado na sessão até ela expirar: rebaixar um técnico só vale na próxima sessão; vale reler o papel por requisição numa próxima iteração.
- **A flag `Secure` do cookie só é ligada quando `$_SERVER['HTTPS']` está presente.** Forçar `Secure` quebraria o login se o painel rodar em HTTP interno. Se o TLS termina num proxy, defina `session.cookie_secure=1` no php.ini.
- **`EXPORT_DIR` em config.php e a função `tecnicoNome()`.** Mantidas por compatibilidade com scripts que eu não vejo, embora o painel não use mais nenhuma das duas. Premissa: o manifesto define a saída de `exportarCsv` como 'escreve o CSV na saída'. Se algum job lê `EXPORT_DIR/chamados.csv`, ele precisa passar a capturar a saída da função (ou pedir para eu reintroduzir um arquivo de nome único e escrita atômica).
- **Configuração do PHP (`display_errors`, `error_reporting`, `session.*` globais).** É configuração de ambiente. O código não depende mais dela (handler genérico e log), mas produção deveria rodar com `display_errors=Off`.
- **Rotação da senha do banco e da chave SMTP expostas (F6).** Operação, não código, e fora do meu alcance. Enquanto não forem rotacionadas, as credenciais antigas continuam válidas e presentes no histórico e nos backups.
- **Escape do `id` no HTML (`<h1>Chamado #id</h1>` e o link da listagem).** É INT AUTO_INCREMENT: não há como carregar HTML. O `htmlspecialchars` já existente no texto livre (título, descrição, técnico) estava correto e foi mantido.

## 4. Como verifiquei

Montei um MariaDB local com `schema.sql` + `seed.sql` e rodei o **código original** e o **novo** lado a lado (servidor embutido do PHP 8.4, via HTTP com sessões reais, e scripts CLI sobre as funções de `lib.php`).

| Verificação | Resultado |
| --- | --- |
| Técnico (`carla`, `diego`): listagem, buscas `conexao`/`zzzz`/`%`, detalhes 101/103/104/999, `ver[]=1` | idênticos byte a byte ao original |
| CSV do técnico | idêntico byte a byte ao do original (cabeçalho, ordem por id, rótulos, "-" sem técnico), menos o ruído `Deprecated` do original (F16) |
| Retornos de `lib.php`: `autenticar`, `formatarStatus` (-3..6), `listarChamados` (10 buscas), `verChamado`, `mediaResposta`, `tecnicoNome`, `exportarCsv` | idênticos por `var_export`, incluindo tipos (strings/NULL), chaves e ordem; só divergem onde o original quebra (F1, F12) |
| `rotuloPrioridade`: 9 prioridades x 10 valores de minutos | 90/90 idênticos (F21) |
| Cliente (`ana`, `bruno`): listagem, detalhe alheio, CSV | só os próprios chamados; chamado alheio responde como inexistente; o próprio detalhe é idêntico ao original |
| SQLi (`UNION`, `OR '1'='1`, aspa), XSS (atributo e texto), `busca[]`, `login[]` | sem vazamento, escapado, tratados sem erro |
| Fixação de sessão | SID escolhido pelo atacante não dá mais acesso; cookie com `HttpOnly; SameSite=Lax` |
| Concorrência no export (20 mil chamados) | original: corrompido quando a 2ª requisição entra a 60–75% da 1ª; novo: íntegro nos cenários testados (simultâneo e escalonado) e sem estado compartilhado por construção |
| Consultas por listagem / tempo do export | original 8 e 308 consultas (5 e 305 chamados), export de 20 mil linhas em 4,3 s isolado e 8–11 s com 6 simultâneos; novo 4 consultas constantes e 0,1–0,4 s |
| `display_errors` ligado e desligado; log de erros do PHP | respostas idênticas; 0 avisos ou deprecations emitidos pelo código novo |
| `schema.sql`, `seed.sql`, `TAREFA.md`, `manifest.md` | sem alteração (conferido com o `.leb-pacote.sha256`) |

**Limites desta verificação.** Testei em PHP 8.4 e MariaDB, não em MySQL 8 nem em PHP 7.x; escrevi com sintaxe compatível com PHP >= 7.1 (o original já usa tipos anuláveis e `void`), mas não executei nessas versões.
Não vi o ambiente de produção (variáveis de ambiente, privilégios do usuário do banco, configuração do servidor web e do `php.ini`), e as confianças acima já refletem isso. O código novo não tem testes automatizados no repositório; os scripts que usei ficaram fora de `code/`.

## 5. Compatibilidade com o manifesto

- **Funções de `lib.php`:** nomes, parâmetros, tipos e retornos de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` preservados (a chave `tecnico_nome` continua em cada item). Acrescentei funções; não removi nem renomeei nenhuma.
- **Rotas e parâmetros:** `index.php`, `?busca=`, `?ver=`, `?export=csv` iguais.
- **CSV:** cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, uma linha por chamado, ordem por `id`, rótulos de `formatarStatus`. Para quem não é técnico, só os chamados que ele abriu (regra de visibilidade).
- **HTML da listagem:** `id="tabela-chamados"`, colunas ID, Titulo, Status, Prioridade, Tecnico, ID como link `index.php?ver=<id>`.
- **Regra de negócio:** cliente só vê os próprios chamados; técnico vê todos, em listagem, detalhe e CSV.
- **Camada de acesso a dados:** continua mysqli.
- **Diferenças observáveis fora do contrato, todas intencionais:** cabeçalhos de segurança nas respostas (F5), cookie de sessão com flags (F8), HTTP 500 em falha de banco (F18), e o `exportarCsv()` não grava mais `EXPORT_DIR/chamados.csv` (F9, F10).
