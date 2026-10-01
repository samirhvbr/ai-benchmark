# Relatório técnico: Painel de Chamados (NetX ISP), LEB-100-A

## Resumo

O painel é pequeno (3 arquivos PHP, 2 tabelas). A regra de negócio central, "cliente só vê os próprios chamados", **não estava implementada em nenhuma rota**: listagem, detalhe (`?ver=`) e exportação CSV mostravam tudo a qualquer usuário logado. Por cima disso havia uma **injeção de SQL** na busca, explorável por qualquer cliente: confirmei que um `UNION` devolve `login:md5` de todos os usuários, e o MD5 sem sal de `senha123` se quebra em qualquer tabela pública. Ou seja, um cliente comum chegava à conta de um técnico em minutos. Também havia XSS refletido na busca, segredos de produção no código-fonte, o CSV exportado gravado num arquivo fixo dentro da árvore web, fixação de sessão e uma divisão por zero que derruba a página inicial.

Corrigi 15 dos 22 achados, sem tocar na superfície pública. As 7 funções do manifesto mantêm nome, parâmetros, tipos e formato de retorno, e as rotas `busca`/`ver`/`export`, o CSV e a tabela HTML continuam iguais. Os outros 7 ficaram só reportados (F21 parcialmente atacado), com o motivo na seção **Decisões**. O mais importante deles é o hash MD5 das senhas, que exige migração de schema.

### Como verifiquei

Subi um MariaDB 11.8 descartável com `schema.sql` e `seed.sql` e rodei o código **original** e o **corrigido** no PHP 8.4, com o mesmo roteiro de requisições para os 4 usuários do seed:

- **Técnicos (carla, diego):** todas as páginas (listagem, busca, `?ver=` de 101 a 105 e 999) saem **idênticas byte a byte**. O CSV também é idêntico, tirando os avisos de depreciação que o original injetava nele (F11).
- **Clientes:** ana vê 101, 102 e 105; bruno vê 103 e 104. Nos chamados alheios, `?ver=` devolve exatamente a mesma página de "Chamado nao encontrado" do id 999, e o CSV traz só as linhas do próprio cliente, com o mesmo cabeçalho e ordem.
- **Funções públicas chamadas direto (71 casos):** `serialize()` da saída idêntico entre original e corrigido. Isso cobre `listarChamados` com 7 termos (inclusive `%` e `_`), `verChamado`, `mediaResposta` (valor **e** tipo), `formatarStatus` de −1 a 99, `rotuloPrioridade` em 7×7 combinações, `autenticar` e `exportarCsv`. A comparação inclui tipos PHP e ordem das chaves.
- **Ataques que funcionavam no original e deixaram de funcionar:** SQLi por `UNION`, XSS na busca, `?busca[]=` (erro fatal), fixação de sessão com ID plantado, `mediaResposta` com base sem respostas (`DivisionByZeroError` passou a devolver `0.0`).

---

## Achados (em ordem de prioridade de correção)

Os números de linha seguem a **numeração original** dos arquivos recebidos.

### F1. Injeção de SQL na busca da listagem
- **Onde:** `code/lib.php:80-85` (`listarChamados`), alcançável por `index.php?busca=`.
- **Mecanismo:** `$busca` vem de `$_GET['busca']` e é concatenado entre aspas simples em `" WHERE titulo LIKE '%" . $busca . "%'"`, depois executado com `$db->query()`. Basta um `'` para sair do literal. Como o `SELECT *` devolve 9 colunas e o resultado vai direto para a tabela HTML, um `UNION SELECT` de 9 colunas aparece na tela. Reproduzi com `zzz' UNION SELECT id,id,NULL,CONCAT(login,':',senha),'x',1,1,NULL,NOW() FROM usuarios -- `: a coluna "Titulo" exibiu `ana:e7d80ffe…`, `carla:2b21aaa0…` e assim por diante.
- **Impacto / severidade:** **crítica**. Qualquer cliente autenticado lê qualquer tabela do banco. Combinado com F8 (MD5 sem sal), recupera a senha dos técnicos e assume a conta deles.
- **Confiança:** 99 (explorado no teste).
- **O que fiz:** troquei por prepared statement (`LIKE ?`, com o `%…%` montado no PHP). Detalhe de compatibilidade: com prepared statement, o mysqlnd devolve colunas `INT` como `int`, enquanto a consulta antiga devolvia `string`. Para não mudar o que os consumidores recebem (comparações estritas, `json_encode` do relatório), os valores não nulos são convertidos de volta para `string`. O teste de serialize confirma retorno idêntico. `%` e `_` continuam funcionando como curinga, como antes.

### F2. Listagem mostra a cliente os chamados de todos os clientes
- **Onde:** `code/index.php:72-73` (e `code/lib.php:78-93`).
- **Mecanismo:** `listarChamados($db, $busca)` devolve a tabela inteira, e `index.php` imprime tudo sem olhar `$uid`/`$papel`, que são lidos da sessão mas nunca usados. No seed, a ana via na página inicial os chamados 103 e 104 do bruno (inclusive "Fatura em duplicidade… cartão").
- **Impacto / severidade:** **crítica**. Viola diretamente a regra de visibilidade do manifesto e expõe dados de todos os clientes do ISP a qualquer cliente (vazamento de dados pessoais).
- **Confiança:** 97.
- **O que fiz:** criei em `lib.php` uma função **nova** (fora da lista pública do manifesto), `podeVerChamado(array $chamado, int $uid, string $papel): bool`. Ela é o único lugar onde a regra é escrita: técnico vê tudo; caso contrário, só se `usuario_id === uid`. Papel desconhecido cai na regra mais restrita. `index.php` filtra a listagem com ela. **Não** coloquei o filtro dentro de `listarChamados`, porque a função não recebe o usuário (mudar a assinatura quebra o contrato) e o relatório gerencial depende dela devolvendo todos os chamados. Técnicos continuam vendo exatamente a mesma listagem.

### F3. IDOR no detalhe: `index.php?ver=<id>` abre qualquer chamado
- **Onde:** `code/index.php:52-58`.
- **Mecanismo:** `verChamado($db, (int) $_GET['ver'])` busca pelo id e a página é renderizada sem comparar `usuario_id` com o usuário da sessão. Os ids são sequenciais (101, 102…), então basta incrementar. A ana abria `?ver=104` (chamado do bruno) com título e descrição.
- **Impacto / severidade:** **crítica**. Mesma quebra da regra de visibilidade, agora com a descrição completa do chamado.
- **Confiança:** 98.
- **O que fiz:** o detalhe só é exibido se `podeVerChamado()` aprovar. Caso contrário, a resposta é **idêntica** à de id inexistente ("Chamado nao encontrado."), para não virar um oráculo que diga quais ids existem. Mantive o status 200 que a página de "não encontrado" já usava.

### F4. Exportação CSV entrega a base inteira a qualquer cliente
- **Onde:** `code/index.php:44-47`.
- **Mecanismo:** a rota `export=csv` chama `exportarCsv($db)` logo após o login, sem verificar papel. O link "Exportar CSV" aparece para todos.
- **Impacto / severidade:** **alta**. É o mesmo vazamento de F2 num formato pronto para extração em massa.
- **Confiança:** 97.
- **O que fiz:** separei a geração do CSV numa função interna nova, `exportarCsvFiltrado(mysqli $db, ?callable $filtro)`. `exportarCsv($db)` virou apenas `exportarCsvFiltrado($db, null)`, com assinatura e saída intactas para a rotina noturna. A rota web passa `podeVerChamado` como filtro: técnico recebe o CSV completo como antes; cliente recebe o mesmo formato (cabeçalho exato, ordem por `id`, rótulos de `formatarStatus`) só com os próprios chamados. Preferi filtrar a bloquear, porque bloquear esconderia do cliente chamados que ele tem direito de ver.

### F5. XSS refletido no termo de busca
- **Onde:** `code/index.php:79` (atributo `value`) e `code/index.php:82` ("Resultados para:").
- **Mecanismo:** `$busca` é impresso cru duas vezes. `?busca="><script>…</script>` fecha o atributo e injeta script. Confirmei no HTML gerado. O cookie de sessão não tinha `HttpOnly` (F9), então o script lê `document.cookie`.
- **Impacto / severidade:** **alta**. Um link enviado a um técnico rouba a sessão dele, e com ela todos os chamados.
- **Confiança:** 98.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. A estrutura HTML não mudou.

### F6. Senha do banco de produção e chave da API de e-mail no código-fonte
- **Onde:** `code/config.php:11-15`.
- **Mecanismo:** `DB_PASS` cai num literal (`'N3tX@2013!prod'`) quando a variável de ambiente não existe, e `SMTP_API_KEY` é literal puro. Os dois estão no repositório desde 2013, e portanto em todo clone, backup e histórico.
- **Impacto / severidade:** **alta**. Quem tem o código acessa o banco de produção (se a rede permitir) e envia e-mail em nome do ISP (phishing com remetente legítimo).
- **Confiança:** 95.
- **O que fiz:** `DB_PASS` e `SMTP_API_KEY` passam a vir **só** do ambiente. As constantes continuam definidas com os mesmos nomes, para os scripts que incluem `config.php`. **Pré-requisitos de deploy (fora do código):** (1) definir `DB_PASS` e `SMTP_API_KEY` no ambiente (`SetEnv` no Apache ou `env[...]` no pool do PHP-FPM) **antes** de publicar esta versão, senão o painel responde "Falha ao conectar ao banco."; (2) **rotacionar** as duas credenciais, porque continuam válidas no histórico do repositório.

### F7. CSV exportado fica gravado num arquivo fixo dentro da árvore web
- **Onde:** `code/lib.php:125-126` e `150` (com `code/config.php:18`).
- **Mecanismo:** cada exportação grava a base inteira em `/var/www/painel/tmp/chamados.csv` e o arquivo **fica lá** depois da requisição. Se a raiz do site for `/var/www/painel` (o que o caminho sugere), `GET /tmp/chamados.csv` serve o arquivo **sem login**. Reproduzi com a mesma disposição de diretórios: HTTP 200 com o CSV completo, sem cookie. Mesmo que o diretório não seja publicado, fica uma cópia de todos os chamados no disco, legível por outros processos (umask padrão 022).
- **Impacto / severidade:** **alta**. Vazamento total sem autenticação, desde que alguém tenha exportado ao menos uma vez.
- **Confiança:** 65. O código é inequívoco, mas a exposição depende da configuração do servidor web, que não recebi.
- **O que fiz:** o CSV agora é escrito direto em `php://output`, sem arquivo intermediário. A saída é byte a byte a mesma. A constante `EXPORT_DIR` foi mantida para outros scripts. Recomendo apagar o `chamados.csv` que já estiver no servidor.

### F8. Senhas armazenadas como MD5 sem sal
- **Onde:** `code/lib.php:15-16` (`autenticar`) e `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:** `md5($senha)` é comparado direto no `WHERE`. MD5 é rápido (bilhões por segundo em GPU) e, sem sal, senhas iguais geram hashes iguais. No seed, ana e bruno têm o mesmo hash, e `e7d80ffeefa212b7c5c55700e4f7193e` (= `senha123`) está em qualquer tabela pública.
- **Impacto / severidade:** **alta**. Qualquer vazamento da tabela (por F1, backup ou acesso ao banco) vira senha em texto claro, e os clientes provavelmente reutilizam essas senhas em outros serviços.
- **Confiança:** 95.
- **O que fiz:** **não corrigi** (ver Decisões D1). Exige migração de schema e um deploy coordenado. Deixo o plano descrito.

### F9. Fixação de sessão e cookie de sessão sem `HttpOnly`/`SameSite`
- **Onde:** `code/index.php:15` e `code/index.php:23-27`.
- **Mecanismo:** o login grava `uid`/`papel` na sessão **existente**, sem `session_regenerate_id()`, e o PHP aceita IDs escolhidos pelo cliente (`session.use_strict_mode` vem desligado por padrão). Quem planta um `PHPSESSID` conhecido no navegador da vítima fica autenticado como ela depois que ela entra. Reproduzi: o ID plantado passou a abrir a listagem. O cookie também não tinha `HttpOnly`, o que tornava F5 um roubo direto de sessão.
- **Impacto / severidade:** **média**. Sequestro de sessão; exige plantar o cookie (subdomínio, rede compartilhada ou XSS).
- **Confiança:** 85.
- **O que fiz:** `session_regenerate_id(true)` no login bem-sucedido. Antes do `session_start()`, via `ini_set` (funciona em qualquer versão do PHP e não quebra as mais antigas): `use_strict_mode=1`, `cookie_httponly=1`, `cookie_samesite=Lax` e `cookie_secure` quando a requisição chega por HTTPS. `Lax` mantém funcionando os links e favoritos externos (GET de navegação). Sessões já abertas continuam válidas.

### F10. Exportação por arquivo fixo: condição de corrida e falha silenciosa
- **Onde:** `code/lib.php:125-135`.
- **Mecanismo:** todas as exportações usam o **mesmo** caminho. Se duas rodam ao mesmo tempo (dois técnicos, ou a rotina noturna e um usuário), o `fopen($caminho, 'w')` de uma trunca o arquivo enquanto o `readfile()` da outra ainda lê, e o download sai truncado ou misturado. Com o filtro por cliente de F4, essa corrida faria um cliente receber o arquivo completo de um técnico. Além disso, se `EXPORT_DIR` não existir ou não for gravável, `fopen` falha e a função retorna **sem nada** (HTTP 200 vazio), e a rotina noturna não tem como perceber. Se a consulta falhar, o handle fica aberto.
- **Impacto / severidade:** **média**. CSVs corrompidos sem nenhum aviso.
- **Confiança:** 75.
- **O que fiz:** resolvido pela mesma mudança de F7 (escrita direta em `php://output`, sem estado compartilhado). Era também pré-requisito para fazer F4 com segurança.

### F11. `fputcsv` sem `$escape` explícito: aviso de depreciação do PHP 8.4 dentro do CSV
- **Onde:** `code/lib.php:130` e `code/lib.php:138-144`.
- **Mecanismo:** no PHP 8.4, não passar `$escape` gera um `Deprecated` em **cada** chamada (1 + uma por chamado). Com `display_errors=On`, o aviso é impresso no corpo da resposta, no meio do CSV. Observei isso no teste: 6 blocos `<br /><b>Deprecated</b>…` antes do cabeçalho. Com `display_errors=Off`, o efeito é log inchado (uma linha por chamado exportado).
- **Impacto / severidade:** **baixa**. CSV inválido para a integração de faturamento em ambientes com erros visíveis; ruído de log nos demais.
- **Confiança:** 55. Depende da versão do PHP e do `display_errors` em produção, que não conheço.
- **O que fiz:** passo `',', '"', '\\'` explicitamente, que são os mesmos valores padrão, então a saída é idêntica. Também funciona em versões antigas do PHP.

### F12. N+1 consultas para obter o nome do técnico
- **Onde:** `code/lib.php:89` (listagem) e `code/lib.php:137` (exportação), via `tecnicoNome()` em `lib.php:64-72`.
- **Mecanismo:** para cada chamado é feito um `SELECT nome FROM usuarios WHERE id = …` separado. A listagem e o CSV fazem 1 + N idas ao banco; com dezenas de milhares de chamados (a listagem não pagina), são dezenas de milhares de round-trips por carregamento da página inicial.
- **Impacto / severidade:** **média**. Latência linear no número de chamados, na página mais acessada e na rotina noturna.
- **Confiança:** 95.
- **O que fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-') AS tecnico_nome`, que reproduz exatamente o `'-'` de `tecnicoNome` para técnico nulo ou inexistente. A chave `tecnico_nome` continua sendo a última do array, como antes. `tecnicoNome()` foi mantida (ver D6).

### F13. `mediaResposta()` divide por zero e derruba a página inicial
- **Onde:** `code/lib.php:116`.
- **Mecanismo:** `return $soma / $qtd;` com `$qtd = 0` quando nenhum chamado tem `minutos_resposta` (base nova, homologação, após expurgo ou num período sem respostas). No PHP 8, isso lança `DivisionByZeroError`, que não é tratado. Como `index.php` chama `mediaResposta` em toda listagem, a página inicial vira erro fatal para todos. Reproduzi: o original lança `DivisionByZeroError`; o corrigido devolve `float(0)`.
- **Impacto / severidade:** **média**. Indisponibilidade total da tela principal nessas condições.
- **Confiança:** 95.
- **O que fiz:** com zero chamados respondidos, retorna `0.0` (o retorno continua `float`). Nos demais casos o valor é idêntico (ver F14).

### F14. `mediaResposta()` transfere todas as linhas para somar em PHP
- **Onde:** `code/lib.php:109-115`.
- **Mecanismo:** traz uma linha por chamado respondido só para somar e contar no PHP, em toda carga da página inicial.
- **Impacto / severidade:** **baixa**. Tráfego e memória proporcionais ao histórico.
- **Confiança:** 80.
- **O que fiz:** `SELECT SUM(minutos_resposta), COUNT(minutos_resposta)` (uma linha só), com a **divisão ainda feita no PHP**. Evitei `AVG()` de propósito: no MySQL, ele devolve `DECIMAL` com 4 casas (`25.6667`), enquanto a função sempre devolveu o float do PHP (`25.666666666666668`), e o relatório gerencial consome esse valor. Confirmei valor e tipo idênticos.

### F15. Parâmetro em forma de array derruba a página com `TypeError`
- **Onde:** `code/index.php:72-73` (`?busca[]=x`) e `code/index.php:22` (`login[]=` / `senha[]=` no POST).
- **Mecanismo:** o PHP transforma `busca[]=x` num array, que chega a `listarChamados(..., string $busca)` e provoca um `TypeError` fatal. O mesmo vale para `autenticar(..., string $usuario, string $senha)`. Com `display_errors=On`, o erro expõe caminhos absolutos do servidor (observado).
- **Impacto / severidade:** **baixa**. Erro 500 por requisição e vazamento de caminhos internos.
- **Confiança:** 95.
- **O que fiz:** `is_string()` antes de usar. Busca inválida é tratada como vazia; login inválido mostra o formulário de novo, como numa falha de login.

### F16. `formatarStatus()` rotula como "Resolvido" qualquer status que não seja 1 nem 2
- **Onde:** `code/lib.php:32-33`.
- **Mecanismo:** o `else` final cobre 3 **e** qualquer outro valor (0, 4, 99…). A coluna é `TINYINT` sem `CHECK`, então um status novo ou corrompido aparece como "Resolvido" na tela, no CSV e no relatório gerencial, que casa pelo texto.
- **Impacto / severidade:** **média**. Chamados em estado desconhecido somem das filas de atendimento e são contados como resolvidos nos indicadores.
- **Confiança:** 65. É um defeito latente e o seed só usa 1, 2 e 3; não sei se existem outros valores em produção.
- **O que fiz:** **não alterei** (ver D2).

### F17. Injeção de fórmula no CSV exportado
- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** o `titulo` é escrito no CSV sem tratamento e é texto livre do cliente que abre o chamado. Um título como `=HYPERLINK("http://…";"clique")` ou `=cmd|…` é interpretado como fórmula quando um técnico ou o gerente abre o CSV no Excel ou LibreOffice.
- **Impacto / severidade:** **média**. Um cliente consegue executar fórmulas na máquina de quem abre o relatório (exfiltração por link, engenharia social).
- **Confiança:** 55. Depende de o CSV ser aberto em planilha, o que é provável para o relatório gerencial mas não está documentado.
- **O que fiz:** **não alterei** (ver D3).

### F18. Login sem limite de tentativas
- **Onde:** `code/index.php:20-29`.
- **Mecanismo:** cada POST faz uma tentativa, sem atraso, bloqueio, contagem ou CAPTCHA. As senhas do seed (`senha123`, `tecmaster`) mostram o padrão: força bruta por dicionário contra logins previsíveis (primeiro nome) é viável.
- **Impacto / severidade:** **média**. Tomada de contas, inclusive de técnicos.
- **Confiança:** 80.
- **O que fiz:** **não alterei** (ver D4).

### F19. `rotuloPrioridade()`: condicionais aninhadas em 4 níveis
- **Onde:** `code/lib.php:40-59`.
- **Mecanismo:** o "if dentro de if" com `else` distante dificulta ver quais combinações levam a cada rótulo. Isso esconde as questões de regra de F20 e torna arriscado mexer na função.
- **Impacto / severidade:** **baixa** (qualidade).
- **Confiança:** 90.
- **O que fiz:** reescrevi com retornos antecipados (guard clauses), **sem mudar nenhum resultado**. Validei as 49 combinações de prioridade (−1 a 5) × minutos (`null`, −5, 0, 29, 30, 31, 500) contra o original, com saída idêntica.

### F20. `rotuloPrioridade()`: rótulos que escondem urgência
- **Onde:** `code/lib.php:56-57` (e `lib.php:51`).
- **Mecanismo:** (a) quando `minutos_resposta` é `NULL`, o rótulo é sempre "Aguardando 1a resposta", **qualquer que seja a prioridade**. Um chamado crítico (4) sem resposta há horas, exatamente o caso de SLA mais grave, aparece igual a um de prioridade baixa. (b) Prioridade 4 dentro do SLA aparece como "Alto - dentro do SLA". (c) Prioridade 1 (baixa) aparece como "Normal".
- **Impacto / severidade:** **baixa**. Triagem visual enganosa na listagem.
- **Confiança:** 35. Pode ser intencional; a função não recebe a data de abertura, então nem consegue calcular o tempo de espera.
- **O que fiz:** **não alterei** (ver D2).

### F21. Arquitetura: `index.php` concentra tudo e não há camada de autorização nem de saída
- **Onde:** `code/index.php:20-97`.
- **Mecanismo:** conexão, sessão, login, roteamento, consulta, autorização e HTML ficam no mesmo script, com `echo` concatenado. O escape é decidido campo a campo, e foi assim que `$busca` ficou sem escape (F5). Também não existia um ponto único de autorização, e foi assim que as três rotas ignoraram a regra de visibilidade (F2–F4).
- **Impacto / severidade:** **média**. Cada rota ou campo novo repete as mesmas decisões de segurança à mão, e a próxima falha do tipo F2–F5 é questão de tempo.
- **Confiança:** 85.
- **O que fiz:** parcialmente. A regra de visibilidade agora está num único lugar (`podeVerChamado`) e é aplicada pela mesma closure `$visivel` nas três rotas. Não separei camadas nem criei templates (ver D5).

### F22. Tratamento de erro do mysqli inconsistente
- **Onde:** `code/index.php:9-12` (e `code/lib.php:133-135`).
- **Mecanismo:** a partir do PHP 8.1, o mysqli lança `mysqli_sql_exception` por padrão. Por isso o `if ($db->connect_errno) die(...)` e os `if ($res === false)` nunca rodam: as falhas saem como exceção não tratada. Com `display_errors=On`, isso exibe stack trace, caminhos e, no original, a própria SQL injetada (facilitando a exploração de F1). Já `listarChamados`/`verChamado` (`lib.php:85`, `100`) não verificavam erro nenhum.
- **Impacto / severidade:** **baixa**. Vazamento de informação e mensagens de erro feias, dependendo do `php.ini`.
- **Confiança:** 60.
- **O que fiz:** **não alterei** (ver D5). Recomendo `display_errors=Off` e `log_errors=On` em produção.

---

## Decisões: o que deliberadamente **não** mudei

**D1. Hash de senha (F8).** Migrar para `password_hash()` exige `ALTER TABLE usuarios MODIFY senha VARCHAR(255)`. O `CHAR(32)` atual trunca o bcrypt (60 caracteres): em modo estrito o `UPDATE` falha; sem modo estrito, grava o hash truncado e **tranca o usuário para sempre**. Não sei se outros scripts do ISP leem ou gravam `usuarios.senha` (cadastro, reset de senha). Plano proposto: (1) migração da coluna; (2) `autenticar()` busca por `login`, verifica com `password_verify()` quando o hash for moderno e, senão, com `hash_equals(md5($senha), $hash)`; em caso de sucesso, regrava com `password_hash()` (rehash no login); (3) depois de N meses, invalidar os MD5 restantes e forçar reset. A assinatura de `autenticar` não muda.

**D2. Rótulos de `formatarStatus` (F16) e `rotuloPrioridade` (F20).** Os textos são contrato de valor: o relatório gerencial casa pelos textos. Criar um rótulo novo ("Desconhecido", "CRITICO - aguardando") faria esses chamados sumirem das contagens do relatório, que só conhece os rótulos atuais. É uma decisão de produto que precisa ser combinada com quem mantém o relatório. Até lá, uma salvaguarda sem quebra seria um `CHECK (status IN (1,2,3))` no banco, depois de conferir os dados existentes.

**D3. Injeção de fórmula no CSV (F17).** A correção usual (prefixar `'` em células que começam com `= + - @`) **altera o conteúdo** do CSV que a integração de faturamento e a rotina noturna consomem. Um título legítimo começando com "-" mudaria de valor. Precisa ser acordada com os consumidores ou feita só numa exportação "para planilha" separada.

**D4. Limite de tentativas de login (F18).** Fazer isso direito exige um estado compartilhado entre requisições e servidores (tabela nova ou cache), ou seja, schema novo ou infraestrutura que a stack não tem hoje. A mitigação de menor custo é no servidor web ou WAF (`mod_evasive`/`fail2ban` sobre o POST de `index.php`). Também não adicionei proteção CSRF no formulário de login nem rota de logout. São lacunas reais, mas de impacto menor que as corrigidas, e mexeriam no fluxo e no HTML.

**D5. Arquitetura e tratamento de erros (F21, F22).** Separar controller, view e templates com escape automático seria reescrever o sistema, o que a tarefa veda e que mexe na estrutura HTML declarada. Mudar o modo de erro do mysqli afeta outros scripts que reusam a mesma conexão ou o mesmo `php.ini`. Fiz só o mínimo estrutural que as correções pediam (`podeVerChamado`, `exportarCsvFiltrado`).

**D6. `tecnicoNome()` mantida.** Depois do `JOIN`, `lib.php` não a usa mais, mas a função está num arquivo consumido por outros scripts do ISP, então removê-la pode quebrar quem a chama. A concatenação `'... WHERE id = ' . $tecnicoId` **não é injetável**: o parâmetro é `?int` tipado e o PHP rejeita qualquer coisa que não seja inteiro. Pelo mesmo motivo, **`verChamado()` (`lib.php:100`) não é um achado de SQLi** e não foi alterada.

**D7. Filtro de visibilidade em PHP, não em SQL.** Para clientes, a listagem e o CSV ainda buscam todos os chamados e descartam os alheios. Empurrar o filtro para o SQL exigiria mudar a assinatura de `listarChamados` (contrato) ou duplicar a consulta. Como a página já carregava a tabela inteira para todos antes, não há regressão de desempenho, e a regra fica escrita num lugar só. Paginação, que é o ganho real em escala, mudaria o comportamento da listagem e fica para outra rodada.

**D8. Arquivo em `EXPORT_DIR` deixou de ser gravado (F7/F10).** O manifesto define `exportarCsv` como "escreve o CSV na saída", e isso continua valendo byte a byte. Se algum processo do ISP lia `/var/www/painel/tmp/chamados.csv` como efeito colateral (não documentado), ele precisa passar a capturar a saída de `exportarCsv()`. Mantive a constante `EXPORT_DIR` definida.

**D9. Média de SLA visível para clientes.** O indicador do topo continua sendo a média global de todos os chamados, inclusive para clientes. É um agregado que não identifica nenhum chamado, então não viola a regra de visibilidade. Restringi-lo mudaria o número exibido sem que o manifesto peça isso.

**D10. Busca com `LIKE '%termo%'`.** Faz varredura completa (o índice não ajuda com curinga à esquerda). Um `FULLTEXT` mudaria a semântica da busca (palavras inteiras, stopwords). `%` e `_` digitados continuam funcionando como curinga, como no original.

**D11. Pré-requisitos operacionais desta entrega:** definir `DB_PASS`/`SMTP_API_KEY` no ambiente antes do deploy; rotacionar as duas credenciais; apagar `/var/www/painel/tmp/chamados.csv` do servidor; manter `display_errors=Off` em produção.
