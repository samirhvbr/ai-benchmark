# Relatório técnico — LEB-100-A · Painel de Chamados (NetX ISP)

Instância **LEB-100-A** (nível 100, versão 1.1) · LEB spec 1.1.0 · tarefa 1.0.0
Matriz de avaliação: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

Os identificadores `F1`…`F17` abaixo são os mesmos de `achados.json`. Linhas citadas referem-se à
numeração **original** dos arquivos recebidos.

## 1. Resumo

Sistema pequeno (PHP + mysqli, cinco arquivos), em produção desde 2013, sem camada de autorização.
A superfície pública do `manifest.md` foi preservada integralmente: assinaturas, rótulos de status,
rotas e parâmetros, cabeçalho/ordem do CSV, estrutura HTML da listagem e os tipos devolvidos pelas
funções (valores como string, `id` de `autenticar` como inteiro, exatamente como antes).

Estado encontrado:

- **Dois achados críticos** que, combinados, entregam o banco inteiro a qualquer cliente autenticado:
  injeção de SQL pela busca por título (um `UNION` despeja a tabela `usuarios` com os hashes de senha —
  confirmado) e ausência total da regra de visibilidade do manifesto (cliente lista, abre e exporta
  chamados de outros clientes — confirmado).
- **Quatro achados altos:** XSS refletido na busca; senha do banco de produção e chave SMTP embutidas em
  `config.php`; senhas de usuários em md5 sem sal; exportação CSV por arquivo fixo em disco, compartilhado
  entre requisições e possivelmente servido pela web.
- Um bug de disponibilidade (divisão por zero derruba a listagem para todos quando não há primeira
  resposta registrada), fixação de sessão, consultas N+1 e pontos de qualidade.

Corrigi **13 dos 17 achados** no código entregue. Quatro ficaram apenas reportados porque exigem decisão
de produto ou mudança de formato que o contrato não me permite tomar sozinho (§3).

**Como verifiquei.** Subi um MariaDB 11.8 efêmero e isolado com `schema.sql` + `seed.sql`, rodei o
`index.php` original e o corrigido no servidor embutido do PHP 8.4 e comparei as respostas HTTP (login,
listagem, busca, detalhe, export, para cliente e técnico) e as saídas das funções de `lib.php` antes e
depois. A tabela-verdade de `formatarStatus` e `rotuloPrioridade` (50 combinações) é idêntica; para
entradas legítimas, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` produzem saída
byte a byte igual à original, inclusive os tipos dos valores.

## 2. Achados, na ordem em que eu corrigiria

### F1 — Injeção de SQL na busca por título
`code/lib.php:82` · segurança · **crítica** · confiança **100** · corrigido

**O que é.** `listarChamados()` concatena `$busca` direto em `WHERE titulo LIKE '%...%'`; `index.php:72-73`
passa `$_GET['busca']` sem tratamento.

**Mecanismo.** Qualquer cliente autenticado controla o SQL. No banco de teste,
`index.php?busca=x' UNION SELECT id,login,senha,nome,papel,1,1,1,NOW() FROM usuarios -- ` devolve a
tabela `usuarios` renderizada como se fossem chamados (login no Titulo, hash md5 na Descricao, papel).
Uma aspa simples sozinha (`busca='`) derruba a página com `mysqli_sql_exception` e stack trace com SQL e
caminhos (PHP ≥ 8.1 lança exceção por padrão).

**Impacto.** Leitura de todo o banco, inclusive hashes de senha (que por F5 são md5 sem sal, quebráveis
offline), bypass de qualquer regra de visibilidade e negação de serviço trivial. Com F5, leva ao
comprometimento das contas técnicas, que veem tudo.

**O que fiz.** `consultarChamados()` monta a consulta com prepared statement (`c.titulo LIKE ?`, com
`%busca%` como parâmetro). O comportamento legítimo é idêntico: mesma cláusula, mesma ordenação, e `%`/`_`
continuam agindo como curingas, como hoje. Os payloads acima passaram a devolver lista vazia.

### F2 — Regra de visibilidade do manifesto não é aplicada (IDOR em listagem, detalhe e CSV)
`code/index.php:38-73` (consome `lib.php:78-93`, `98-102`, `123-151`) · segurança · **crítica** · confiança **100** · corrigido

**O que é.** `index.php` sabe quem está logado (`$uid`, `$papel`, linhas 38-39) e nunca usa isso. A listagem
chama `listarChamados()` (todos), o detalhe chama `verChamado($id)` para qualquer id, o export chama
`exportarCsv()` (todos). Nenhuma função de `lib.php` filtra por `usuario_id`.

**Mecanismo.** Confirmado: logada como `ana` (cliente), a listagem mostra 105, 104, 103, 102 e 101 — os de
`bruno` (103 e 104) inclusive; `index.php?ver=103` exibe título e descrição do chamado de `bruno`;
`?export=csv` baixa tudo. Ids são sequenciais, enumerar é trivial.

**Impacto.** Vazamento de dados entre clientes (descrição contém relato do problema, cobrança, bairro).
Viola a regra de negócio explícita do manifesto.

**O que fiz.** Mantive as funções do manifesto com assinatura e comportamento globais (a exportação noturna
e o relatório gerencial precisam do conjunto completo) e apliquei a regra na camada web, onde a identidade
existe:

- novas funções em `lib.php`: `listarChamadosDoUsuario(mysqli, int $usuarioId, string $busca = '')`,
  `exportarCsvDoUsuario(mysqli, int $usuarioId)` e `podeVerChamado(array $chamado, int $uid, string $papel)`.
  As duas primeiras compartilham a consulta das versões globais com um `WHERE c.usuario_id = ?` a mais;
- `index.php`: técnico usa as funções globais; qualquer outro papel usa as variantes filtradas (papel
  desconhecido cai na regra mais restritiva). No detalhe, chamado de outro cliente responde exatamente como
  inexistente (`404` + "Chamado nao encontrado."), para não confirmar a existência de ids alheios.

Técnico continua vendo tudo: com `carla`, listagem, `ver=103` e CSV (5 linhas) saem idênticos ao original.
Cliente vê só o que abriu: `ana` recebe 105/102/101, `404` em `ver=103` e CSV com suas 3 linhas.

### F3 — XSS refletido no parâmetro `busca`
`code/index.php:79-82` · segurança · **alta** · confiança **100** · corrigido

**Mecanismo.** `$busca` é impresso cru dentro de `value="..."` e em `<p>Resultados para: ...</p>`. Confirmado:
`?busca="><script>alert(1)</script>` fecha o atributo e injeta o script. Como o cookie de sessão não tinha
`HttpOnly` (F8), o script lia o `PHPSESSID`.

**Impacto.** Roubo de sessão por link enviado a um técnico (que vê todos os chamados) ou ações no contexto da
vítima. Títulos, descrições e nomes já eram escapados; só a busca não era.

**O que fiz.** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos. O HTML da
listagem para buscas legítimas é o mesmo.

### F4 — Senha do banco de produção e chave de API embutidas no código
`code/config.php:12-15` · segurança · **alta** · confiança **95** · corrigido (exige rotação dos segredos)

**Mecanismo.** `DB_PASS` tem fallback literal (`N3tX@2013!prod`) e `SMTP_API_KEY` é constante literal. O arquivo
é versionado e circula com o pacote (chegou até mim). Quem tiver acesso ao repositório, a um backup ou a
este pacote tem a senha do usuário de produção do banco e a chave da central de e-mail.

**Impacto.** Acesso direto ao banco por fora da aplicação (sem log de aplicação) e envio de e-mail em nome
do ISP. Confiança 95: não posso confirmar daqui que o valor ainda é o vigente, mas o próprio comentário o
chama de "senha do usuário de produção".

**O que fiz.** Removi os literais. `DB_PASS` vem só do ambiente; se a variável não existir, o sistema para no
boot com mensagem clara (sem revelar nada) em vez de subir com um segredo vazado. `SMTP_API_KEY` vem do
ambiente (vazio se ausente; nada neste código a usa). **Ação operacional obrigatória:** rotacionar a senha do
usuário `painel` e a chave SMTP, e definir `DB_PASS`/`SMTP_API_KEY` no ambiente antes do deploy — ver §4.

### F5 — Senhas de usuários em md5 sem sal
`code/lib.php:15` (coluna em `code/schema.sql:7`) · segurança · **alta** · confiança **100** · corrigido

**Mecanismo.** `autenticar()` compara `md5($senha)` com `senha CHAR(32)`. md5 sem sal é quebrado offline a
bilhões de tentativas por segundo; `senha123` e `tecmaster` caem em segundos com dicionário, e hashes iguais
denunciam senhas repetidas (`ana` e `bruno` têm o mesmo hash). Com F1, qualquer cliente extrai os hashes.

**Impacto.** Tomada das contas técnicas a partir de qualquer vazamento da tabela.

**O que fiz.** `autenticar()` aceita os dois formatos: md5 legado (comparado com `hash_equals`, em
caixa-insensível como o MySQL fazia) e `password_hash()`. Em login bem-sucedido com hash legado, regrava com
`password_hash()` (bcrypt) **somente** se a coluna comporta o tamanho (consulta `information_schema`) e o
`UPDATE` for permitido; qualquer falha é silenciosa e o login prossegue — nunca há risco de truncar o hash
e bloquear o usuário. `schema.sql` passa a `VARCHAR(255)` e traz o `ALTER TABLE` para bancos existentes.
Assinatura e retorno (`['id','nome','papel']`, `id` inteiro) inalterados. Quando o login não existe, verifico
contra um hash fictício para que o tempo de resposta não revele logins válidos (medido: 277 ms contra 276 ms).

Verificado: com a coluna `CHAR(32)` original o md5 fica intacto e o login funciona; com `VARCHAR(255)` o
primeiro login migra para bcrypt e os seguintes autenticam pelo bcrypt; `UPDATE` bloqueado (simulado por
trigger) não impede o login; md5 em maiúsculas continua aceito; os quatro usuários do `seed.sql` entram.

### F6 — Exportação CSV passa por arquivo fixo em disco, compartilhado entre requisições
`code/lib.php:125-150` · segurança · **alta** · confiança **85** · corrigido

**Mecanismo.** `exportarCsv()` grava `EXPORT_DIR/chamados.csv` — o mesmo caminho para toda requisição — e
depois faz `readfile`. (a) Duas exportações simultâneas escrevem no mesmo arquivo: uma pode servir ao outro
usuário um arquivo truncado ou misturado; com F2 corrigido isso viraria vazamento cruzado (cliente A
receberia linhas do cliente B). (b) `/var/www/painel/tmp` está sob `/var/www/painel`; se esse for o docroot, o
CSV completo fica acessível por URL direta, sem login, até a próxima exportação. (c) Se `fopen` falha
(diretório inexistente, como no meu ambiente), a função retorna sem cabeçalho nem corpo: página em branco
com HTTP 200 (confirmado). (d) Se a consulta falha, `$fp` fica aberto e o arquivo parcial permanece.

**Impacto.** Exposição de todos os chamados e condição de corrida. Confiança 85 porque a exposição por URL
depende do docroot real; a corrida e a falha silenciosa são certas.

**O que fiz.** `emitirCsvChamados()` escreve direto em `php://output`, com os cabeçalhos HTTP enviados antes;
nada toca o disco. Cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, ordem por `id`, rótulos de
`formatarStatus` e o escape do `fputcsv` (agora explícito, `\`, o mesmo de antes) geram bytes idênticos ao
original (comparado). `EXPORT_DIR` continua definido para scripts internos.

### F7 — Divisão por zero derruba a listagem para todos
`code/lib.php:116` · bug · **média** · confiança **100** · corrigido

**Mecanismo.** `mediaResposta()` faz `$soma / $qtd` sem checar `$qtd`. Se nenhum chamado tem
`minutos_resposta` (banco novo, expurgo, unidade recém-criada), o PHP 8 lança `DivisionByZeroError`; como
`index.php:74` chama a função em toda listagem, ninguém consegue abrir o painel. Confirmado zerando a coluna
no banco de teste.

**O que fiz.** Agregação no SQL (`SUM`/`COUNT`) e retorno `0.0` quando não há dados. Para dados existentes o
valor é o mesmo (`25.666666666666668` antes e depois). Deixa também de transferir todas as linhas para somar
em PHP.

### F8 — Fixação de sessão e cookie sem proteções
`code/index.php:15-25` · segurança · **média** · confiança **80** · corrigido

**Mecanismo.** `session_start()` com os padrões e, no login, o id de sessão não é regenerado: confirmado que
o `PHPSESSID` antes e depois do login é o mesmo. Quem fixar um id na vítima (link, subdomínio, o XSS de F3)
herda a sessão autenticada. O cookie saía sem `HttpOnly` nem `SameSite`, e `use_strict_mode` desligado faz o
servidor aceitar ids inventados pelo cliente.

**O que fiz.** `session_regenerate_id(true)` após autenticar; `cookie_httponly`, `cookie_samesite=Lax`,
`use_strict_mode` e `cookie_secure` quando a requisição veio por HTTPS. Confirmado: o id muda no login e o
cookie sai com `HttpOnly; SameSite=Lax`.

### F9 — N+1 consultas na listagem e no CSV
`code/lib.php:89` e `:137` · performance · **média** · confiança **100** · corrigido

**Mecanismo.** Para cada chamado, `tecnicoNome()` faz um `SELECT` em `usuarios`: N+1 round-trips em cada
carga da listagem e em cada exportação, e o export noturno percorre a tabela inteira.

**O que fiz.** `LEFT JOIN usuarios` com `COALESCE(u.nome, '-') AS tecnico_nome` numa só consulta, nas duas
funções. A chave `tecnico_nome`, o `-` para sem técnico ou técnico inexistente, a ordem das chaves e os tipos
(strings) são os mesmos. `tecnicoNome()` foi mantida para relatórios internos, agora com prepared statement.

### F10 — Parâmetros em formato de array derrubam a página
`code/index.php:22` e `:72-73` · bug · **baixa** · confiança **90** · corrigido

**Mecanismo.** `$_GET['busca']` e `$_POST['login']` vão direto para funções com parâmetro `string`.
`index.php?busca[]=1` ou `login[]=x` geram `TypeError` não tratado (confirmado: fatal com stack trace).
Não é exploração séria, mas é um 500 gratuito que, com `display_errors` ligado, revela caminhos.

**O que fiz.** `parametroTexto()` em `index.php` devolve `''` para parâmetro ausente ou não-string. Para
strings o comportamento é o de antes.

### F11 — Sem limite de tentativas de login
`code/index.php:20-29` · segurança · **média** · confiança **90** · não corrigido (apenas registro)

**Mecanismo.** O formulário aceita tentativas ilimitadas, sem atraso, bloqueio ou registro. Com senhas como as
do seed, um ataque de dicionário online é viável.

**O que fiz.** Cada falha passa a ir para `error_log` ("painel: falha de login para X de IP"), com o login
sanitizado contra injeção de linha, para que fail2ban/WAF possam agir. Não implementei bloqueio na aplicação:
fazê-lo bem exige armazenamento compartilhado (tabela ou cache) e uma política acordada; um contador por
sessão é inútil (basta descartar o cookie). Por isso marco como não corrigido.

### F12 — `formatarStatus` rotula qualquer valor fora de 1-3 como "Resolvido"
`code/lib.php:32-34` · bug · **baixa** · confiança **70** · não corrigido

**Mecanismo.** O `else` final cobre o 3 e também 0, 4, 99 e negativos. A coluna é `TINYINT` sem `CHECK`; se
outro sistema gravar um status novo (ex.: 4 = cancelado), o painel e o relatório gerencial (que casa por texto)
contarão como resolvido. Confiança 70: é latente, só se materializa com dados fora de 1-3.

**O que fiz.** Nada. O manifesto fixa 1/2/3 e nada diz sobre o resto; trocar o fallback (ex.: "Desconhecido")
muda a saída para valores que o relatório gerencial pode hoje estar tratando como resolvidos de propósito.
Proponho confirmar com quem mantém o relatório e então devolver um rótulo distinto fora de 1-3.

### F13 — `rotuloPrioridade` com quatro níveis de aninhamento
`code/lib.php:40-59` · qualidade · **baixa** · confiança **90** · corrigido (forma; semântica preservada)

**Mecanismo.** Cinco saídas em if/else aninhado escondem a tabela de decisão e uma assimetria: prioridade 4
(crítica) sem primeira resposta recebe o mesmo "Aguardando 1a resposta" de uma prioridade 1 (é o caso do
chamado 104 do seed), e crítica dentro do SLA recebe "Alto - dentro do SLA".

**O que fiz.** Linearizei em guard clauses preservando exatamente as mesmas saídas (tabela-verdade de 50
combinações idêntica). Não mudei a semântica: se "crítica sem resposta" merece destaque próprio, é decisão de
produto que altera textos que o relatório gerencial pode consumir.

### F14 — Consultas por concatenação em `verChamado` e `tecnicoNome`
`code/lib.php:69` e `:100` · qualidade · **baixa** · confiança **100** · corrigido

**Mecanismo.** Hoje não exploráveis — os parâmetros são tipados `int` e `index.php` faz `(int)` — mas é o
mesmo padrão que gerou F1; basta relaxar o tipo ou chamar com string de outro script para virar injeção.
Reportado como qualidade, não como vulnerabilidade.

**O que fiz.** Prepared statements nas duas. `normalizarLinha()` converte os valores nativos que o prepared
statement devolve (int) para string, como `query()` entregava, mantendo o formato de retorno.

### F15 — Tratamento da falha de conexão não funciona em PHP ≥ 8.1
`code/index.php:9-12` · qualidade · **baixa** · confiança **60** · corrigido

**Mecanismo.** Desde o PHP 8.1 o mysqli lança `mysqli_sql_exception` por padrão: com o banco fora do ar,
`new mysqli()` lança antes de `connect_errno` ser testado, a mensagem "Falha ao conectar ao banco." nunca
aparece e, com `display_errors` ligado, sai um fatal com host, usuário e caminhos. Confiança 60 porque depende
da versão do PHP e da configuração de erros em produção, que não conheço.

**O que fiz.** `try/catch` em volta da conexão e `http_response_code(500)` com a mesma mensagem. Não alterei o
modo global de reporte do mysqli (mudaria o comportamento de erro de todas as consultas). `display_errors=Off`
em produção continua obrigatório.

### F16 — Injeção de fórmula no CSV exportado
`code/lib.php:138-144` · segurança · **baixa** · confiança **60** · não corrigido

**Mecanismo.** `titulo` é controlado por quem abre o chamado. Um título começando com `=`, `+`, `-` ou `@` é
interpretado como fórmula pelo Excel/LibreOffice ao abrir o CSV exportado pela técnica (`=HYPERLINK(...)`, DDE
em versões antigas). Confiança 60: depende do cliente de planilha e de sua configuração.

**O que fiz.** Nada. A mitigação padrão (prefixar `'` ou tab) altera o conteúdo das células, e o manifesto diz
que a integração de faturamento consome esse CSV; mudar os valores quebra o contrato. Recomendo tratar no
consumidor ou acordar o escape com a integração.

### F17 — Listagem sem paginação e `LIKE '%termo%'` sem índice
`code/lib.php:80-84` · performance · **baixa** · confiança **80** · não corrigido

**Mecanismo.** A listagem carrega todos os chamados a cada acesso (todos os clientes, no caso do técnico), e o
`LIKE` com curinga à esquerda força varredura completa de `chamados` (o índice em `criado_em` só ajuda a
ordenação). Para um ISP em produção desde 2013, a tabela tende a dezenas de milhares de linhas.

**O que fiz.** Nada além de reduzir o custo por linha (F9). Paginação introduz parâmetro novo na rota e muda o
HTML (links de página); busca por índice FULLTEXT muda a semântica (substring vs. palavra). Ambos pedem acordo
com os consumidores.

## 3. Decisões — o que deliberadamente não mudei e por quê

| O quê | Por quê |
| --- | --- |
| Assinaturas, nomes e tipos de retorno das sete funções do manifesto | Contrato. A visibilidade entrou por funções **novas** (`listarChamadosDoUsuario`, `exportarCsvDoUsuario`, `podeVerChamado`), não por parâmetros extras. As funções do manifesto continuam globais porque a exportação noturna e o relatório gerencial precisam de tudo. |
| Tipagem dos valores devolvidos (strings em `listarChamados`/`verChamado`, `id` inteiro em `autenticar`) | Prepared statements devolvem int nativo; `normalizarLinha()` restaura o formato texto que os consumidores recebem hoje. Mudar tipos quebraria comparações estritas e JSON do relatório gerencial. |
| `mediaResposta` continua global, inclusive para cliente | É um agregado, não um chamado; a regra do manifesto fala de ver chamados. Filtrar por cliente mudaria o indicador e a assinatura. |
| Fallback "Resolvido" em `formatarStatus` (F12) | Fora de 1-3 o manifesto não define nada e o relatório gerencial casa por texto; mudar unilateralmente pode reclassificar dados. |
| Semântica de `rotuloPrioridade` (F13) | Só linearizei; trocar textos ou tratar "crítica sem resposta" é decisão de produto. |
| Curingas `%` e `_` na busca | Já funcionavam assim; escapá-los mudaria resultados de buscas legítimas que contenham esses caracteres. |
| Escape de fórmulas no CSV (F16) e caractere de escape `\` do `fputcsv` | Alterariam bytes do CSV consumido pela integração de faturamento. |
| Paginação / índice FULLTEXT (F17) | Mudam rota, HTML ou semântica da busca. |
| Bloqueio de tentativas de login (F11) | Exige armazenamento e política; entreguei só o registro para bloqueio externo. |
| Rota de logout, tempo de expiração de sessão, cabeçalhos CSP/X-Frame-Options | Rota nova está fora do manifesto; expiração e cabeçalhos de endurecimento pertencem à configuração do PHP/servidor web e eu não conheço o ambiente. |
| Modo global de reporte do mysqli | Forçá-lo mudaria como todas as consultas falham; tratei só a conexão (F15). |
| Empates em `ORDER BY criado_em DESC` | Ordem de empates é indefinida hoje; acrescentar critério secundário poderia reordenar o que consumidores veem. |
| `seed.sql` | Continua com `MD5()`: os usuários de teste entram pelo caminho legado e migram no primeiro login quando a coluna estiver alargada. |
| `tecnicoNome()` | Não está no manifesto, mas o comentário diz que relatórios internos a usam; mantive (com prepared statement). |
| Arquivo em `EXPORT_DIR` | A exportação web não grava mais em disco (F6). O manifesto define `exportarCsv` como "escreve o CSV na saída"; o arquivo era efeito colateral não contratado. Se a rotina noturna lia esse arquivo em vez de capturar a saída, precisa capturar a saída — ver §4. |

## 4. Notas de implantação (necessárias para o código entregue)

1. Definir `DB_PASS` (obrigatório; sem ela o sistema para no boot com mensagem clara) e `SMTP_API_KEY` no
   ambiente do PHP e dos scripts internos. **Rotacionar** a senha do usuário `painel` e a chave SMTP que
   estavam no código.
2. Executar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` (está comentado no fim de
   `schema.sql`). Até lá o login funciona com md5 e nada é migrado. Depois, cada login bem-sucedido migra o
   usuário para bcrypt; a migração também requer que o usuário do banco tenha `UPDATE` em `usuarios` (se não
   tiver, o login continua funcionando e nada é gravado).
3. Garantir `display_errors=Off` em produção (os stack traces observados vinham do padrão de desenvolvimento).
4. Se a rotina noturna dependia do arquivo `EXPORT_DIR/chamados.csv`, passar a capturar a saída de
   `exportarCsv()` (por exemplo com `ob_start()` ou redirecionamento do processo).
5. Custo do bcrypt: o `PASSWORD_DEFAULT` do PHP 8.4 usa custo 12 (~275 ms por verificação nesta máquina).
   Aceitável para um painel; se o servidor for muito mais lento, passar `['cost' => 10]` em `password_hash`.

## 5. Arquivos alterados

- `code/lib.php` — prepared statements em tudo; JOIN no lugar do N+1; `autenticar` com md5 legado + bcrypt e
  migração segura; `mediaResposta` sem divisão por zero; CSV direto na saída; novas funções
  `listarChamadosDoUsuario`, `exportarCsvDoUsuario`, `podeVerChamado`, `normalizarLinha` e auxiliares.
- `code/index.php` — regra de visibilidade por papel; escape da busca; regeneração de sessão e flags do cookie;
  parâmetros não-string tratados; conexão com `try/catch` e 500; registro de falhas de login; 404 no detalhe
  inexistente/alheio.
- `code/config.php` — segredos só do ambiente; falha explícita sem `DB_PASS`.
- `code/schema.sql` — `senha VARCHAR(255)` e `ALTER` de migração comentado.
- `code/seed.sql` — sem alteração.
