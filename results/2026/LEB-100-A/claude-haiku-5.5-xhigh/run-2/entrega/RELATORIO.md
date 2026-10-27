# Relatório técnico — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

O sistema é um painel PHP com mysqli, em produção desde 2013, com três telas (login, listagem/busca com detalhe e exportação CSV). Encontrei **17 achados**. Os quatro mais graves são exploráveis por qualquer usuário, inclusive sem login ou com uma conta de cliente comum: credenciais de produção no código (F1), SQL injection na busca (F2), regra de visibilidade não implementada (F3) e XSS refletido (F5).

Corrigi **12 achados** no código. Os outros **5** estão só relatados (F10, F11, F15, F16, F17), por exigirem decisão de produto, mudança de schema ou risco de quebra de consumidores que não estão no pacote. A superfície pública do `manifest.md` foi preservada: assinaturas, rotas, nomes de parâmetro, rótulos de status, cabeçalho e ordenação do CSV, estrutura HTML e a regra de visibilidade (agora implementada, como o manifesto pede).

Duas ações estão fora do código e são obrigatórias: **rotacionar a senha do banco e a chave SMTP** (estiveram no código entregue) e **definir `DB_PASS` no ambiente de produção** antes do deploy. Sem isso o painel não conecta (falha fechada, por design). Detalhes na seção 5.

Severidade e confiança são minhas. A confiança é a probabilidade de que o problema seja real.

---

## 2. Achados, na ordem em que priorizaria a correção

### F1: senha de produção e chave SMTP literais em `config.php`

- **Onde:** `code/config.php`, linhas 12 a 15 (original).
- **Mecanismo:** `DB_PASS` usa `getenv('DB_PASS') ?: '<senha literal, removida>'`. Sem a variável de ambiente, o código cai no literal de produção. `SMTP_API_KEY` é literal, sem fallback de ambiente. Quem tem o código, o histórico do repositório ou um backup tem as duas credenciais.
- **Impacto e severidade:** acesso direto ao banco de chamados, com dados de clientes e hashes de senha. A chave SMTP permite enviar e-mails em nome da central. **Crítica.**
- **Confiança:** 99%.
- **O que fiz:** removi os dois literais. `DB_PASS` e `SMTP_API_KEY` vêm só de `getenv()`, sem fallback secreto. **Não basta isto: as credenciais precisam ser rotacionadas** (seção 5).

### F2: SQL injection na busca por título

- **Onde:** `code/lib.php`, linha 82 (original), dentro de `listarChamados()`.
- **Mecanismo:** `$busca` vem de `$_GET['busca']` (`index.php:73`) e é concatenado cru em `WHERE titulo LIKE '%...%'`. Testei com `busca = x' UNION SELECT 1,login,senha,nome,papel,1,1,1,NOW() FROM usuarios-- -` contra o servidor original de teste: a listagem exibe login e hash de senha de todos os usuários na coluna Título. Além disso, `%` e `_` funcionavam como curingas, então a busca não era literal.
- **Impacto e severidade:** qualquer conta autenticada, inclusive de cliente, lê o banco inteiro. **Crítica.**
- **Confiança:** 98%.
- **O que fiz:** o termo é escapado com `addcslashes` (para `%`, `_` e `\`) e depois com `real_escape_string`. A consulta foi centralizada em `consultarChamados()`. O mesmo payload, no código novo, não retorna nada e não vaza dados.

### F3: regra de visibilidade não implementada

- **Onde:** `code/index.php`, linhas 38 a 73 (original). `$uid` e `$papel` são gravados na sessão (linhas 38-39) e nunca usados. Listagem (73), detalhe (52-67) e CSV (44-47) não filtram nada.
- **Mecanismo:** o manifesto diz que um cliente só vê os chamados que abriu, e um técnico vê qualquer um. O código não aplica isso em nenhum dos três caminhos. Testado: a cliente `ana` via os 5 chamados, abria `?ver=103` (de `bruno`) e baixava o CSV completo.
- **Impacto e severidade:** clientes leem títulos e descrições de atendimento de outros clientes, por listagem, por id incremental ou por exportação. Viola a regra de negócio e expõe dados pessoais. **Crítica.**
- **Confiança:** 97%.
- **O que fiz:** a regra ficou em `papelVeTodos()` e `usuarioPodeVer()`. Técnico vê tudo; qualquer outro papel vê só o próprio `uid` (fail-closed). A listagem passou a usar `listarChamadosVisiveis()`. Detalhe de chamado alheio responde `Chamado nao encontrado.`, a mesma mensagem de id inexistente, para não revelar que ele existe. O export do painel usa `exportarCsvVisivel()`. `listarChamados()` e `exportarCsv()` continuam sem filtro, porque são usados pelos relatórios internos, como antes. Verificado por HTTP: `ana` vê 101, 102 e 105; `carla` (técnica) vê os 5.

### F4: senhas em MD5 sem sal

- **Onde:** `code/lib.php`, linhas 15 a 17 (original); `code/schema.sql`, linha 7 (`senha CHAR(32)`).
- **Mecanismo:** `autenticar()` compara `md5($senha)` com a coluna. MD5 sem sal cai em tabelas precomputadas e em força bruta com GPU. Os hashes do seed são de senhas fracas (`senha123`, `tecmaster`).
- **Impacto e severidade:** quem obtém o banco (backup, dump, ou a leitura de F2) recupera senhas de clientes e técnicos em segundos e pode reutilizá-las em outros sistemas. **Alta.**
- **Confiança:** 95%.
- **O que fiz:**
  - senhas novas são gravadas com `password_hash()` e verificadas com `password_verify()`;
  - o hash md5 legado continua aceito, e a conta é regravada com `password_hash()` no primeiro login bem-sucedido;
  - a regravação só acontece se a coluna couber o hash (`atualizarHashSenha()` consulta `information_schema`), então nunca trunca e nunca tranca a conta;
  - `schema.sql` passa a `VARCHAR(255)`.
  - Para ativar em produção: `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`. Sem o ALTER, o login legado continua funcionando e nada quebra.
  - Verificado: base com `CHAR(32)` mantém o md5 e continua logando; base com `VARCHAR(255)` migra para `$2y$` e continua logando nas tentativas seguintes.

### F5: XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php`, linhas 79 e 82 (original).
- **Mecanismo:** `$busca` entra sem escape no atributo `value="..."` e em `Resultados para: ...`. O título é escapado; a busca não era. Um link `index.php?busca="><script>...` executa script na sessão de quem clicar. Também: `busca[]=x` (array) gerava `TypeError` e página 500.
- **Impacto e severidade:** execução de script no navegador de técnicos e clientes, com ações em nome da vítima e leitura de cookie de sessão (ver F9). **Alta.**
- **Confiança:** 96%.
- **O que fiz:** `htmlspecialchars()` nos dois pontos. `$busca` só aceita string (`is_string`); caso contrário vira `''`. Verificado: o payload aparece escapado, e `busca[]=x` responde 200 (antes 500).

### F6: exportação grava o CSV completo em arquivo fixo compartilhado

- **Onde:** `code/lib.php`, linhas 125 a 150 (original), em `exportarCsv()`; `config.php:18` (`EXPORT_DIR`).
- **Mecanismo:** o CSV de todos os chamados é gravado em `EXPORT_DIR/chamados.csv`, com nome fixo, e só depois lido com `readfile()`. Há dois efeitos:
  - exportações concorrentes disputam o mesmo arquivo: o `fopen('w')` trunca enquanto outra requisição lê, e a resposta pode sair truncada ou com conteúdo misturado;
  - o CSV completo, com dados de todos os clientes, fica em disco. Se `/var/www/painel/tmp` estiver sob a raiz web, o arquivo fica acessível por URL sem login. **Não verifiquei a configuração de produção.**
  - No caminho de erro da query, o arquivo ficava aberto sem `fclose`.
- **Impacto e severidade:** dados de todos os clientes em arquivo potencialmente público, e exportações com conteúdo de outra requisição. **Média.**
- **Confiança:** 65%.
- **O que fiz:** o painel web não usa mais o arquivo. `exportarCsvVisivel()` gera o CSV direto em `php://output`, filtrado pelo papel, sem tocar em disco. `exportarCsv()` continua gravando o arquivo, só para a rotina noturna, que pode depender disso. Recomendo manter `EXPORT_DIR` fora da raiz web (item de deploy).

### F7: divisão por zero em `mediaResposta()`

- **Onde:** `code/lib.php`, linha 116 (original).
- **Mecanismo:** `$soma / $qtd` sem checar `$qtd == 0`. Sem nenhum chamado com `minutos_resposta` preenchido (base nova, ou só chamados abertos), o PHP 8 lança `DivisionByZeroError` (verificado). `index.php` chama `mediaResposta()` em toda listagem (linha 74), então a página principal vira erro 500.
- **Impacto e severidade:** painel principal indisponível em base sem respostas registradas. **Média.**
- **Confiança:** 95%.
- **O que fiz:** `SUM` e `COUNT` em uma consulta; se `COUNT` for zero, devolve `0.0`. A conta é a mesma do original (`SUM / COUNT` em PHP), então os valores são idênticos: para os dados de teste, 25.666… nos dois. Efeito aceito: sem dados, a tela mostra `0 min`. Se preferir "sem dados", é mudança de tela.

### F8: consulta N+1 para o nome do técnico

- **Onde:** `code/lib.php`, linhas 64 a 72 (original); chamadas nas linhas 89 (listagem) e 137 (export).
- **Mecanismo:** `tecnicoNome()` faz uma consulta por chamado, e as duas funções a chamam dentro do loop. Com N chamados, são N+1 consultas por página e por exportação. A consulta também concatena o id na SQL; hoje é `int`, então não é injetável, mas o padrão é frágil.
- **Impacto e severidade:** listagem e export degradam com o crescimento da base. **Média** (performance).
- **Confiança:** 95%.
- **O que fiz:** `consultarChamados()` traz `tecnico_nome` num `LEFT JOIN` com `COALESCE(u.nome, '-')`: uma consulta, mesmo resultado (sem técnico, `-`). `tecnicoNome()` foi mantida, com prepared statement, para scripts externos que a usem.

### F9: sessão sem regeneração no login e cookie sem `HttpOnly`/`SameSite`

- **Onde:** `code/index.php`, linhas 15 a 25 (original).
- **Mecanismo:** o login não chama `session_regenerate_id()`, então o identificador anterior ao login continua válido depois dele (fixação de sessão, se alguém conseguir colocar o cookie da vítima antes). O `PHPSESSID` também não tem `HttpOnly` nem `SameSite`, então um script injetado (F5) lê o identificador.
- **Impacto e severidade:** sessão fixada ou roubada por script. **Média.**
- **Confiança:** 50%. A fixação exige um vetor para plantar o cookie, que não vi no código; o `HttpOnly` é a mitigação clara.
- **O que fiz:** `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax'])` antes de `session_start()`, e `session_regenerate_id(true)` no login. Verificado: o `Set-Cookie` sai com `HttpOnly; SameSite=Lax`. **Não testei a fixação de ponta a ponta.**

### F10: login sem limite de tentativas

- **Onde:** `code/index.php`, linhas 20 a 36 (original).
- **Mecanismo:** tentativas ilimitadas. Não há contador de falhas, atraso, bloqueio nem CAPTCHA, por conta ou por IP. Com logins conhecidos (`ana`, `bruno`, `carla`, `diego`), senhas fracas podem ser testadas à vontade.
- **Impacto e severidade:** adivinhação de senha online por força bruta ou dicionário. **Média.**
- **Confiança:** 80%.
- **O que fiz:** **nada.** Um limite confiável precisa de estado persistente (tabela ou cache), o que muda schema e operação. Proposta: contador por login e IP, com bloqueio temporário após N falhas.

### F11: CSV sem neutralização de fórmulas

- **Onde:** `code/lib.php`, linhas 138 a 144 (original).
- **Mecanismo:** `titulo` vai para o CSV sem neutralizar caracteres de fórmula. Um título que começa com `=`, `+`, `-` ou `@` vira fórmula ao abrir no Excel ou no LibreOffice (ex.: `=HYPERLINK(...)`). O título é texto livre do cliente.
- **Impacto e severidade:** fórmula ou link executado na máquina de quem abre o export (um técnico); risco de phishing ou exfiltração. **Média.**
- **Confiança:** 45%. Depende de quem consome o export e de como o abre.
- **O que fiz:** **nada.** Prefixar apóstrofo nas células com esses caracteres muda o conteúdo que a rotina noturna e o relatório gerencial consomem. Ver seção 3.

### F12: erro do driver exibido na tela quando a conexão falha

- **Onde:** `code/index.php`, linhas 9 a 12 (original).
- **Mecanismo:** desde o PHP 8.1, o mysqli lança `mysqli_sql_exception` na conexão, então a checagem de `connect_errno` (linha 10) nunca roda. A exceção não capturada imprime a mensagem do driver quando `display_errors` está ligado. Verificado: `Access denied for user 'painel'@'localhost' (using password: YES)`, que revela usuário e host do banco.
- **Impacto e severidade:** detalhes da conexão para quem provoca a falha. **Baixa.**
- **Confiança:** 55%. Depende de `display_errors` em produção.
- **O que fiz:** `try/catch` em volta de `new mysqli()`. O erro vai para `error_log`, e a tela mostra só `Falha ao conectar ao banco.`. Verificado nos dois caminhos.

### F13: `formatarStatus()` devolve "Resolvido" para qualquer valor fora de 1 e 2

- **Onde:** `code/lib.php`, linhas 26 a 35 (original).
- **Mecanismo:** o `else` final cobre qualquer valor. Status 0, 4, 9 ou um valor corrompido aparece como "Resolvido" na tela e no CSV, e pode entrar assim no relatório gerencial, que casa por texto.
- **Impacto e severidade:** chamado com status inválido conta como resolvido e distorce indicadores. **Baixa.**
- **Confiança:** 70%.
- **O que fiz:** `3` vira `Resolvido` explicitamente; qualquer outro valor vira `Desconhecido`. Os rótulos 1, 2 e 3 do contrato não mudam. Verificado: a única diferença de saída na comparação com o original é o valor para status 0, 4 e 9.

### F14: cabeçalho do CSV com aspas em `"Aberto em"`

- **Onde:** `code/lib.php`, linha 130 (original).
- **Mecanismo:** o cabeçalho é escrito por `fputcsv()`, que põe aspas em `Aberto em`. O manifesto exige o cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em`. Um parser CSV lê o mesmo valor nos dois casos, mas comparação de texto ou importador simples não.
- **Impacto e severidade:** consumidores que comparam o cabeçalho como texto tratam a linha de forma diferente. **Baixa.**
- **Confiança:** 60%.
- **O que fiz:** cabeçalho escrito literalmente, igual ao manifesto. Verificado: `fgetcsv` lê as mesmas cinco colunas, e a linha bruta é `ID,Titulo,Status,Tecnico,Aberto em`.

### F15: prioridade crítica dentro do SLA aparece como "Alto"

- **Onde:** `code/lib.php`, linhas 43 a 52 (original), em `rotuloPrioridade()`.
- **Mecanismo:** prioridade 4 (crítica, segundo `schema.sql`) com SLA até 30 min cai no ramo `Alto - dentro do SLA` (linha 51), o mesmo rótulo da prioridade 3. Só a prioridade 4 estourada recebe `CRITICO`. Pode ser intencional. O código não permite afirmar.
- **Impacto e severidade:** chamado crítico dentro do SLA aparece como "Alto" no painel. **Baixa.**
- **Confiança:** 35%.
- **O que fiz:** **nada.** É regra de negócio que o código não define. Precisa de confirmação de quem opera o SLA.

### F16: `lib.php` emite cabeçalhos HTTP e faz `readfile()`

- **Onde:** `code/lib.php`, linhas 148 a 150 (original), em `exportarCsv()`.
- **Mecanismo:** a biblioteca de dados chama `header()` e `readfile()`. A regra e o acesso a dados ficam presos ao contexto HTTP: não dá para reusar a exportação em CLI sem efeitos colaterais, e testar a regra exige um servidor web. `exportarCsvVisivel()` repete o padrão.
- **Impacto e severidade:** manutenção e testes mais difíceis. **Baixa.**
- **Confiança:** 60%.
- **O que fiz:** **nada.** `exportarCsv()` tem o contrato "escreve o CSV na saída" e é usada pela rotina noturna. Separar exigiria mudar a assinatura ou criar uma camada nova. Proposto para a próxima versão.

### F17: listagem sem paginação

- **Onde:** `code/lib.php`, linhas 78 a 93 (original), em `listarChamados()`.
- **Mecanismo:** `SELECT` sem `LIMIT`, e a tela monta a tabela inteira. Memória e tempo crescem linearmente com a base.
- **Impacto e severidade:** degradação com o crescimento da base. Hoje o volume é pequeno. **Baixa** (performance).
- **Confiança:** 45%.
- **O que fiz:** **nada.** Paginação muda a tela e a assinatura pública. Registrado para decisão de produto.

---

## 3. Decisões: o que deliberadamente não mudei, e por quê

- **Limite de tentativas no login (F10).** Exige estado persistente, ou seja, schema e operação novos. Não cabe numa correção pontual.
- **Neutralização de fórmulas no CSV (F11).** O conteúdo de célula é consumido pela rotina noturna e pelo relatório gerencial. Mudar o valor de título que começa com `=`, `+`, `-` ou `@` pode quebrar um consumidor que não está no pacote. Decisão com os donos desses consumidores; a proposta é prefixar apóstrofo só nesses casos.
- **Regra de `rotuloPrioridade()` para prioridade crítica dentro do SLA (F15).** Não sei se "Alto" ali é intencional. Mudar seria inventar regra de negócio.
- **Separação da saída HTTP de `lib.php` (F16).** `exportarCsv()` é contrato e é usada pela rotina noturna. Separar muda assinatura ou cria camada nova.
- **Paginação (F17).** Muda tela e assinatura, e o volume atual não justifica.
- **`mediaResposta()` continua global.** O indicador de SLA no topo do painel é um agregado, não identifica chamados, e a assinatura não recebe usuário. Mantive para cliente e técnico. **Se a regra de visibilidade do manifesto também cobrir indicadores, é decisão de produto**, e exige mudar tela e assinatura.
- **Consultas de listagem com `query()` e literais escapados, não prepared statements.** Esta foi uma decisão consciente. O original devolvia `id`, `status` e demais campos numéricos como string. Prepared statements do mysqlnd devolvem `int`, o que mudaria o formato de retorno que os consumidores recebem. A segurança fica garantida pelo escape, com o conector em `utf8mb4`. Se o contrato puder mudar, a alternativa é prepared statement com conversão para string.
- **MD5 legado não é desativado à força (F4).** Contas que nunca logaram continuam em md5 até o próximo login ou um reset. Forçar reset exige processo com o ISP.
- **`tecnicoNome()` mantida.** Não aparece no manifesto como pública, mas pode ser usada por scripts externos. Foi só trocada a consulta por prepared statement.
- **`SMTP_API_KEY` mantida como constante** (agora vazia por padrão). Scripts externos podem lê-la.
- **`exportarCsv()` continua gravando `EXPORT_DIR/chamados.csv`.** A rotina noturna pode depender do arquivo. O painel web deixou de usá-lo (F6).
- **Logout, expiração de sessão e token CSRF no login** não existem no sistema e não estão no manifesto. Seriam funcionalidade nova, fora desta correção.
- **`seed.sql` não foi alterado.** Os usuários de teste continuam com md5 e migram no primeiro login, como qualquer conta legada.
- **Nada foi feito em produção.** Não usei as credenciais do `config.php` original em nenhum teste.

---

## 4. Verificação

Ambiente: MariaDB 11.8 privado e descartável, em socket próprio, criado só para os testes (o servidor local já existente não foi tocado). Cópias do código original e do novo, com `EXPORT_DIR` redirecionado para diretório temporário. PHP 8.4 CLI.

- **Regressão das funções públicas** (`listarChamados`, `verChamado`, `mediaResposta`, `formatarStatus`, `rotuloPrioridade`, `exportarCsv`, `autenticar`): saídas idênticas ao original, exceto nos pontos intencionais: `formatarStatus` para valores 0, 4 e 9 (F13) e a linha de cabeçalho do CSV (F14). Os valores das linhas do CSV não mudaram.
- **Autenticação:** credenciais válidas e inválidas, base com `CHAR(32)` (sem migração) e base com `VARCHAR(255)` (migração e login posterior).
- **HTTP ponta a ponta**, original × novo, com cliente `ana` e técnica `carla`: visibilidade (listagem, detalhe, CSV), XSS, SQL injection por UNION, busca literal com `%`, cookie de sessão, `busca[]=`.
- **Erros:** conexão com senha errada (original vaza a mensagem do driver; novo não), `mediaResposta` sem dados (original lança exceção; novo devolve `0.0`).
- **Sintaxe:** `php -l` em todos os arquivos alterados.

**Não verificado:**
- rotina noturna e relatório gerencial (não estão no pacote);
- versão de PHP de produção: o código usa `session_set_cookie_params` com array, que exige PHP 7.3 ou superior; testei em 8.4;
- configuração real de `EXPORT_DIR` e da raiz web em produção (F6);
- fixação de sessão de ponta a ponta (F9).

---

## 5. Passos fora do código (antes do deploy)

1. **Rotacionar** a senha do usuário `painel` do banco e a `SMTP_API_KEY`. Estiveram no código entregue e podem estar no histórico do repositório e em backups. Trocar só o arquivo não basta.
2. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente** de produção. Sem `DB_PASS`, o painel responde `Falha ao conectar ao banco.` (falha fechada).
3. **Ampliar a coluna de senha:** `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`. Não é obrigatório para o deploy (sem ele, o login legado funciona), mas é o que ativa a migração para `password_hash`.
4. **Confirmar que `EXPORT_DIR` não está sob a raiz web** (F6).
5. **Avisar os donos da rotina noturna e do relatório gerencial** sobre o cabeçalho do CSV (F14), que agora é exatamente o do manifesto, e sobre o status `Desconhecido` (F13).
