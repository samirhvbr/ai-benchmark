# Relatório técnico — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

O sistema é um painel PHP + MySQL com login, listagem com busca, detalhe de chamado e exportação CSV. Os três arquivos PHP (`config.php`, `lib.php`, `index.php`) usam mysqli e não têm camada de autorização nem tratamento de entrada.

Encontrei **17 achados**: 3 críticos, 3 altos, 6 médios e 5 baixos. Corrigi **14**. Três ficaram sem correção de propósito (F12, F16, F17), por exigirem decisão de política ou de negócio. Os achados mais graves permitem a um cliente comum ler o banco inteiro (F1), ver os chamados de outros clientes (F3) e encontrar a senha de produção no código (F2). Tudo o que está em `manifest.md` foi preservado: assinaturas, rótulos, rotas, formato do CSV e estrutura HTML. A visibilidade declarada no manifest, que o código original não aplicava, passou a ser aplicada.

**Antes de publicar, são obrigatórios estes passos fora do código:**

1. Rotacionar a senha do usuário `painel` no MySQL e a chave SMTP (F2). Ambas estão no código original e neste pacote.
2. Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP-FPM ou do Apache. Sem `DB_PASS`, o painel não conecta mais.
3. Rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` (F5). Sem isso o login continua funcionando com md5, mas as senhas não migram.
4. Confirmar que a rotina noturna não lê `/var/www/painel/tmp/chamados.csv` (F6). Se lê, ela precisa mudar.

## 2. Tabela de achados (ordem de prioridade)

| ID | Severidade | Confiança | Categoria | Título | Corrigido |
| --- | --- | --- | --- | --- | --- |
| F1 | crítica | 99 | seguranca | SQL injection na busca por título | sim |
| F2 | crítica | 98 | seguranca | Senha de produção e chave SMTP no código | sim (exige rotação) |
| F3 | crítica | 97 | seguranca | Cliente vê todos os chamados | sim |
| F4 | alta | 99 | seguranca | XSS refletido na busca | sim |
| F5 | alta | 95 | seguranca | Senhas em MD5 sem sal | sim (exige ALTER) |
| F6 | alta | 80 | seguranca | CSV gravado em arquivo fixo e nunca removido | sim |
| F7 | média | 95 | seguranca | Sessão não regenerada no login | sim |
| F8 | média | 65 | seguranca | CSV injection em títulos | sim |
| F9 | média | 92 | bug | `formatarStatus` trata valor inválido como Resolvido | sim |
| F10 | média | 98 | bug | `mediaResposta` divide por zero | sim |
| F11 | média | 95 | performance | N+1 consultas para nome do técnico | sim |
| F12 | média | 85 | seguranca | Login sem limite de tentativas | não |
| F13 | baixa | 95 | bug | Busca trata `%` e `_` como curingas | sim |
| F14 | baixa | 90 | bug | Cabeçalho do CSV com aspas, fora do manifest | sim |
| F15 | baixa | 90 | bug | `connect_errno` é código morto no PHP 8.1+ | sim |
| F16 | baixa | 80 | seguranca | Painel sem logout | não |
| F17 | baixa | 35 | bug | Rótulos de prioridade ambíguos | não |

Severidade é a que atribuo ao impacto em produção. Confiança é a probabilidade de o problema ser real. Os itens com confiança abaixo de 90 têm a incerteza explicada no bloco.

---

## 3. Achados

### F1 — SQL injection na busca por título

- **Onde:** `code/lib.php:81-83`, função `listarChamados`, chamada em `index.php:73`.
- **O que é:** a busca é concatenada na consulta SQL, sem escape e sem prepared statement.
- **Mecanismo:** `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"` (linha 82). `$busca` vem direto de `$_GET['busca']`. Um termo como `zzz' UNION SELECT 9,9,9,senha,login,9,9,9,NOW() FROM usuarios-- -` fecha o literal e faz a listagem devolver os hashes de senha de todos os usuários no campo Título. Confirmei isso no código original.
- **Impacto:** qualquer usuário logado, inclusive cliente, lê o banco inteiro. Isso inclui hashes de senha e chamados de outros clientes.
- **Severidade:** crítica. **Confiança:** 99.
- **O que fiz:** `consultarChamados()` usa consulta preparada. O termo passa por `escaparLike()` (barra invertida, `%` e `_`) e entra como parâmetro. A assinatura de `listarChamados` foi mantida.

### F2 — Senha de produção e chave SMTP no código

- **Onde:** `code/config.php:11-15`.
- **O que é:** credenciais em texto no código.
- **Mecanismo:** `DB_PASS` tem como fallback o literal `'N3tX@2013!prod'` (linha 12). Se a variável de ambiente não existir, o código usa essa senha de produção. `SMTP_API_KEY` é literal na linha 15. As duas estão no arquivo entregue e, presumivelmente, no controle de versão.
- **Impacto:** quem tem o código tem acesso direto ao MySQL de produção e à central de e-mail, sem passar pelo painel.
- **Severidade:** crítica. **Confiança:** 98.
- **O que fiz:** removi os literais. `DB_PASS` e `SMTP_API_KEY` vêm só do ambiente, com default vazio. **Não basta alterar o código**: a senha atual precisa ser rotacionada, pois já está exposta.

### F3 — Cliente vê todos os chamados

- **Onde:** `code/index.php:44-73`, rotas `?export=csv`, `?ver=` e listagem. Também `code/lib.php:98-102` e `123-151`.
- **O que é:** a regra de visibilidade do manifest não é aplicada em nenhuma rota.
- **Mecanismo:** o manifest diz que cliente só vê os chamados que abriu (`usuario_id`). `listarChamados($db, $busca)` (linha 73) não recebe o papel. `verChamado` (linha 53) devolve qualquer id. `exportarCsv` (linha 45) exporta a tabela inteira. O papel fica na sessão (linhas 25 e 39) e nunca é consultado. Confirmei que `ana` (cliente) lista 101 a 105 e abre `?ver=103`, que é de `bruno`.
- **Impacto:** qualquer cliente lê título, descrição e técnico de chamados de outros clientes e baixa o CSV completo. Isso viola uma regra que o manifest declara obrigatória.
- **Severidade:** crítica. **Confiança:** 97.
- **O que fiz:** a regra ficou em `podeVer()`. `listarChamadosVisiveis()` e `exportarCsvVisivel()` filtram por `usuario_id` quando o papel não é `tecnico`. Qualquer papel fora de `tecnico` é tratado como cliente (fail-closed). Chamado alheio responde igual a chamado inexistente, sem enumeração. Técnico continua vendo tudo, e as páginas dele são idênticas às do original.

### F4 — XSS refletido na busca

- **Onde:** `code/index.php:79` (campo de busca) e `82` (texto "Resultados para").
- **O que é:** `$busca` é impresso sem escape.
- **Mecanismo:** `value="' . $busca . '"` e `'Resultados para: ' . $busca`. Uma busca com `"><script>...</script>` sai como HTML executável. Confirmei que o `<script>` volta cru na resposta do original.
- **Impacto:** script injetado roda na sessão de quem abrir o link de busca, por exemplo um técnico. Com a sessão, o atacante lê chamados ou age em nome da vítima.
- **Severidade:** alta. **Confiança:** 99.
- **O que fiz:** `htmlspecialchars()` nos dois pontos. A estrutura do formulário não muda.

### F5 — Senhas em MD5 sem sal

- **Onde:** `code/lib.php:15-17` (`autenticar`) e `code/schema.sql:7`.
- **O que é:** o login compara `md5($senha)` com a coluna `CHAR(32) senha`.
- **Mecanismo:** MD5 é rápido e sem sal. A mesma senha gera o mesmo hash em toda a tabela, e senhas comuns caem em segundos por dicionário. O próprio `schema.sql` marca o hash como legado.
- **Impacto:** um dump do banco, ou a injeção do F1, entrega as senhas de todos os usuários, inclusive as de técnicos, que veem tudo.
- **Severidade:** alta. **Confiança:** 95.
- **O que fiz:** o login aceita o md5 legado e regrava em `password_hash` (bcrypt) no primeiro acesso bem-sucedido, em `regravarSenha()`. A regravação só ocorre se a coluna já tiver sido ampliada para `VARCHAR(255)`, o que é checado em `information_schema`. Sem essa checagem, um bcrypt de 60 caracteres gravado em `CHAR(32)` seria truncado e trancaria o usuário. O `schema.sql` foi ampliado e traz o `ALTER` como comentário. A assinatura e o retorno de `autenticar` continuam `['id','nome','papel']`.
- **Pendência:** o `ALTER` precisa rodar antes da migração. Sem ele, o login funciona com md5, mas nenhuma senha é migrada.

### F6 — CSV gravado em arquivo fixo e nunca removido

- **Onde:** `code/lib.php:125-150` (`exportarCsv`).
- **O que é:** o export grava `EXPORT_DIR/chamados.csv`, relê e nunca apaga o arquivo.
- **Mecanismo:** `fopen($caminho, 'w')` (linha 126), escrita da tabela inteira, `fclose`, e `readfile` (linha 150). `EXPORT_DIR` é `/var/www/painel/tmp`, na mesma árvore de `index.php` e `config.php`. Se essa árvore for a document root, o CSV de todos os chamados é baixável sem login. Há também corrida: duas requisições simultâneas disputam o mesmo arquivo, e um `fopen 'w'` trunca o arquivo enquanto a outra o relê. Confirmei que o arquivo permanece após o download.
- **Impacto:** exposição de todos os chamados por URL direta, sem autenticação, se a pasta for servida. Mesmo sem exposição, os dados ficam em disco indefinidamente.
- **Severidade:** alta. **Confiança:** 80. A incerteza é o caminho de deploy, que não conheço. A persistência do arquivo é certa.
- **O que fiz:** o CSV é escrito direto em `php://output`, com o cabeçalho antes do corpo. Nada é gravado em `EXPORT_DIR`. A constante continua em `config.php`.
- **Ponto de atenção:** se a rotina noturna lia o arquivo do disco, ela para de encontrá-lo. O manifest descreve `exportarCsv` como "escreve o CSV na saída", então eu não tratei o arquivo como contrato. Confirme antes do deploy.

### F7 — Sessão não regenerada no login

- **Onde:** `code/index.php:23-25`.
- **O que é:** fixação de sessão.
- **Mecanismo:** `session_start()` (linha 15) cria o ID antes do login. Depois de autenticar, o código grava `uid` e `papel` no mesmo ID, sem `session_regenerate_id`. Confirmei que o `PHPSESSID` é o mesmo antes e depois do login.
- **Impacto:** quem consegue fixar um ID de sessão na vítima (cookie injetado, subdomínio, sessão pré-definida) fica logado como ela depois que ela entra.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** `session_regenerate_id(true)` antes de gravar `uid` e `papel`. Confirmei que o ID muda no login.

### F8 — CSV injection em títulos

- **Onde:** `code/lib.php:138-144`, campo `titulo` (linha 140).
- **O que é:** `fputcsv` só escapa aspas e separadores. Um título com `=`, `+`, `-` ou `@` no início vira fórmula quando o CSV é aberto no Excel ou no LibreOffice.
- **Mecanismo:** o título é texto livre e vai ao CSV sem proteção. Um título como `=HYPERLINK(...)` é executado por quem abre o export.
- **Impacto:** técnico ou quem recebe o relatório pode disparar fórmula que envia dados para fora ou abre link malicioso.
- **Severidade:** média. **Confiança:** 65. O vetor é indireto: o painel não cria chamados, então o título precisa entrar por outro canal. Por isso a confiança é média.
- **O que fiz:** `protegerCelula()` prefixa apóstrofo quando o título começa com `= + - @`, TAB ou CR. Aplicado só ao título. O nome do técnico vem de cadastro interno e não recebe o prefixo, para não alterar o placeholder `-` do técnico ausente.

### F9 — `formatarStatus` trata valor inválido como Resolvido

- **Onde:** `code/lib.php:32-34`.
- **O que é:** o `else` final devolve `'Resolvido'` sem testar o valor 3.
- **Mecanismo:** `formatarStatus(0)` e `formatarStatus(9)` devolvem `'Resolvido'` no original. A coluna é `TINYINT` sem `CHECK`, então um valor inválido entra no banco e aparece como resolvido na listagem e no CSV.
- **Impacto:** chamado com status corrompido conta como resolvido no relatório gerencial, distorcendo o indicador de atendimento.
- **Severidade:** média. **Confiança:** 92.
- **O que fiz:** teste explícito de 3 para `'Resolvido'`. Outros valores viram `'Desconhecido'`. Os rótulos 1, 2 e 3 do contrato não mudam.

### F10 — `mediaResposta` divide por zero

- **Onde:** `code/lib.php:116`.
- **O que é:** `return $soma / $qtd` com `$qtd = 0`.
- **Mecanismo:** quando nenhum `minutos_resposta` está preenchido, a consulta não devolve linhas, `$qtd` fica em zero e o PHP 8 lança `DivisionByZeroError`. Confirmei a exceção no original. A listagem inteira (`index.php:74`) quebra com erro 500.
- **Impacto:** a tela principal falha numa base sem nenhum chamado respondido, como base nova ou após limpeza do campo.
- **Severidade:** média. **Confiança:** 98.
- **O que fiz:** devolve `0.0` quando `$qtd` é zero. A assinatura e o tipo `float` foram mantidos.

### F11 — N+1 consultas para o nome do técnico

- **Onde:** `code/lib.php:89` (em `listarChamados`) e `137` (em `exportarCsv`). A causa está em `tecnicoNome` (linhas 64-72).
- **O que é:** uma consulta por chamado para buscar o nome do técnico.
- **Mecanismo:** `tecnicoNome()` roda dentro do `while`. Listar 5 chamados faz 6 consultas no original. No código novo, faz 2 (prepare e execute).
- **Impacto:** o tempo da listagem e do export cresce linearmente com a base. O export noturno faz uma consulta por linha.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** um único `SELECT` com `LEFT JOIN` em `usuarios` e `COALESCE(t.nome, '-')` produz o mesmo `tecnico_nome`. `tecnicoNome()` foi removida. Ela não consta no manifest e só existia para esse caminho.

### F12 — Login sem limite de tentativas

- **Onde:** `code/index.php:21-29`.
- **O que é:** o bloco de login chama `autenticar()` a cada POST, sem contador, atraso ou captcha.
- **Mecanismo:** não há nenhum controle por usuário ou por IP. Um script pode testar senhas indefinidamente contra qualquer conta.
- **Impacto:** ataque de dicionário online contra qualquer conta, inclusive técnicos. O risco é maior enquanto houver senhas md5 (F5).
- **Severidade:** média. **Confiança:** 85.
- **Não corrigido.** Exige decisão de política: limite, janela de bloqueio, captcha, e onde guardar os contadores. A sessão não serve, porque o atacante abre sessões novas. Recomendo como próximo passo.

### F13 — Busca trata `%` e `_` como curingas

- **Onde:** `code/lib.php:82`.
- **O que é:** o termo é colocado entre `%` sem escapar `%` e `_`.
- **Mecanismo:** confirmei que a busca `%` devolve os 5 chamados no original, e `_` também. O usuário espera busca literal por substring.
- **Impacto:** buscas por termos com esses caracteres (`50%`, `a_b`) devolvem resultados errados.
- **Severidade:** baixa. **Confiança:** 95.
- **O que fiz:** `escaparLike()` escapa barra invertida, `%` e `_`. Busca comum, sem esses caracteres, não muda.

### F14 — Cabeçalho do CSV com aspas, fora do manifest

- **Onde:** `code/lib.php:130` (no original).
- **O que é:** o cabeçalho sai como `ID,Titulo,Status,Tecnico,"Aberto em"`.
- **Mecanismo:** `fputcsv` põe aspas em campos com espaço. O manifest exige o cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em`, e o original não cumpre isso.
- **Impacto:** consumidor que compara o cabeçalho literalmente não bate. Parsers CSV leem os dois formatos do mesmo jeito, então o impacto é baixo.
- **Severidade:** baixa. **Confiança:** 90.
- **O que fiz:** o cabeçalho é escrito como texto literal em `escreverCsv()`. O corpo das linhas não muda: comparei byte a byte com o original.

### F15 — `connect_errno` é código morto no PHP 8.1+

- **Onde:** `code/index.php:9-12`.
- **O que é:** a checagem `if ($db->connect_errno)` nunca é alcançada.
- **Mecanismo:** no PHP 8.1+, o mysqli lança `mysqli_sql_exception` por padrão. A mensagem "Falha ao conectar ao banco." nunca aparece, e a exceção sobe sem tratamento. Confirmei que senha errada gera a exceção `Access denied`.
- **Impacto:** falha de banco vira página de erro fatal. Com `display_errors` ligado, a mensagem expõe usuário e host do banco. Isso fica mais provável com a senha vindo só do ambiente (F2).
- **Severidade:** baixa. **Confiança:** 90.
- **O que fiz:** `try/catch` em torno da conexão, com o mesmo `die()`. A checagem de `connect_errno` foi mantida para PHP anterior a 8.1, que não lança exceção.

### F16 — Painel sem logout

- **Onde:** `code/index.php:15-20`.
- **O que é:** não existe rota de saída.
- **Mecanismo:** não há `session_destroy` nem expiração definida pela aplicação. A sessão só termina quando o cookie expira ou o servidor descarta o arquivo.
- **Impacto:** em computador compartilhado, quem chega depois continua logado com o papel de quem saiu.
- **Severidade:** baixa. **Confiança:** 80.
- **Não corrigido.** Logout é funcionalidade nova, e a política de expiração é decisão de produto e de infraestrutura. Recomendo implementar.

### F17 — Rótulos de prioridade ambíguos

- **Onde:** `code/lib.php:43-57` (`rotuloPrioridade`).
- **O que é:** a tabela de rótulos tem dois caminhos que parecem pouco coerentes.
- **Mecanismo:** com `minutos` definido, prioridade 4 e até 30 minutos, o caminho cai em `'Alto - dentro do SLA'` (linhas 50-51): um chamado crítico aparece como alto. Sem primeira resposta (linha 57), todo chamado vira `'Aguardando 1a resposta'`, inclusive o crítico, como o chamado 104 do seed. Pode ser intencional. O manifest não define a tabela de rótulos.
- **Impacto:** o operador vê "Alto" para um crítico dentro do prazo, ou "Aguardando" para um crítico sem resposta. Se a intenção era alertar, o sinal se perde.
- **Severidade:** baixa. **Confiança:** 35. Baixa de propósito: não sei se o comportamento é intencional.
- **Não corrigido.** Rótulos e regra de SLA são de negócio. Precisa de confirmação de quem define o SLA.

---

## 4. Decisões: o que não mudei, e por quê

- **`mediaResposta` continua global.** Cliente vê a média de todos os chamados. O manifest a descreve como indicador de SLA do painel, e a média não expõe conteúdo de chamado alheio. Restringir mudaria o número exibido e o significado da tela.
- **Regra de `rotuloPrioridade` mantida (F17).** O mapeamento de SLA e os rótulos não estão no manifest. Mudar sem confirmação pode trocar um alerta por outro.
- **Assinaturas públicas mantidas.** `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` têm os mesmos nomes, parâmetros e retornos. As telas passaram a usar `listarChamadosVisiveis` e `exportarCsvVisivel`, então os scripts internos continuam recebendo tudo.
- **`verChamado` mantido com concatenação.** O parâmetro é `int` antes de entrar na consulta, então não há injeção. A regra de acesso fica em `podeVer()`, na tela.
- **`tecnicoNome` removida.** Não consta no manifest e existia só para o N+1 (F11). Se algum script externo a chamar, basta reintroduzi-la, são cerca de dez linhas.
- **Arquivo `chamados.csv` deixou de ser gravado (F6).** O manifest não o descreve como contrato, mas a rotina noturna pode lê-lo. Confirme antes do deploy.
- **Prefixo `'` em títulos com `= + - @ TAB CR` (F8).** Altera o dado exportado para esses títulos. É a mitigação padrão. Nome de técnico não recebe o prefixo.
- **`Desconhecido` para status inválido (F9).** O relatório gerencial casa rótulos por texto. Valores inválidos deixam de contar como Resolvido. Se o relatório dependia disso, o indicador muda.
- **Fallback md5 mantido.** Necessário para quem ainda não logou desde a mudança. Pode sair depois de checar: `SELECT COUNT(*) FROM usuarios WHERE senha NOT LIKE '$%'` igual a zero.
- **Senhas não forçadas a reset.** A migração acontece no login. Quem não logar mantém md5 no banco até lá. Para eliminar md5 totalmente, o caminho é reset forçado, o que exige comunicação com os usuários. É decisão de operação.
- **Não implementei limite de login (F12) nem logout (F16).** Exigem decisões de política e, no caso do logout, funcionalidade nova.
- **`seed.sql` não alterado.** São dados de teste com md5 legado. O login migra essas senhas no primeiro acesso.
- **Stack e camada de dados mantidas.** mysqli, PHP puro, nenhuma dependência nova. `password_hash` e `hash_equals` são nativos.
- **`.leb-pacote.sha256` não atualizado.** É o registro do pacote original. Os hashes de `code/` diferem por design.
- **Não avaliei CSRF no login.** O painel tem um único formulário de escrita, o de login. Risco baixo. Não aprofundei.

## 5. Compatibilidade com o manifest

| Item do manifest | Estado |
| --- | --- |
| Assinaturas das 7 funções públicas | mantidas |
| Rótulos de status 1, 2, 3 | mantidos. Outros valores agora são `Desconhecido` (F9) |
| Rotas `index.php`, `?busca=`, `?ver=`, `?export=csv` | mantidas, mesmos nomes de parâmetro |
| Cabeçalho do CSV | exato, sem aspas em `Aberto em` (F14, mudança pedida pelo manifest) |
| Ordem do CSV por `id` crescente | mantida |
| Coluna Status do CSV usa `formatarStatus` | mantida |
| `id="tabela-chamados"`, colunas ID, Titulo, Status, Prioridade, Tecnico, links `?ver=<id>` | mantidos. Página do técnico idêntica à original |
| Visibilidade: cliente só os seus, técnico todos | aplicada (F3). Era o comportamento pretendido, não aplicado |

## 6. Verificação

Testei em uma instância MariaDB 11.8 descartável, em `/tmp`, na porta 3399, e não no MariaDB de produção. O PHP usado foi o 8.4 CLI com servidor embutido. A base de teste foi criada a partir de `schema.sql` e `seed.sql`.

- **Lint:** `php -l` passou em todos os arquivos PHP alterados.
- **Suíte HTTP (42 checagens, todas passando):** login legado e migração de hash, senha errada, visibilidade por papel, detalhe de chamado alheio, XSS, SQL injection, curingas, sessão, download CSV, ausência de arquivo gravado, e login com schema ainda em `CHAR(32)`.
- **Regressão do técnico:** as páginas de listagem, `?ver=101`, `?ver=103`, `?ver=104` e `?ver=999` são idênticas às do original.
- **Export noturno (CLI):** o corpo do CSV é idêntico ao original. A única diferença é o cabeçalho, sem aspas, como pede o manifest.
- **Achados confirmados no original antes da correção:** F1, F3, F4, F6, F7, F9, F10, F11, F13, F14 e F15.
- **Testado no código novo, sem o original para comparar:** F2 (grep: nenhum literal restante), F5 (migração de hash), F8 (prefixo aplicado), F11 (contagem de consultas).
- **Não testado:** F12, F16 e F17 são ausência ou regra de negócio, e não têm teste. F6 depende da configuração de servidor, que não conheço.

**Limites da verificação:** testei no PHP 8.4 e no MariaDB 11.8. Não testei no MySQL 8 nem em PHP 7.x, embora o código use apenas recursos disponíveis desde o PHP 7.1. Não tive a rotina noturna, o relatório gerencial nem a configuração do servidor web.

**Incidente de teste a declarar:** numa execução de teste, feita sem variáveis de ambiente, o `config.php` usou o fallback de senha de produção. A conexão foi feita na porta 3399, com `mysqli.default_port=3399`, e foi recusada pela instância de teste. Não usei essa credencial no MariaDB de produção (porta 3306). Mesmo assim, a senha deve ser rotacionada (F2).
