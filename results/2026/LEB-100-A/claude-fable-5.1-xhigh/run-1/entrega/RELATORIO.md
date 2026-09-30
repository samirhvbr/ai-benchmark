# Relatório técnico — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

O sistema é um painel PHP/mysqli de ~270 linhas (login, listagem com busca, detalhe, export CSV).
Está funcional, mas em produção ele **não aplica a regra de negócio central do manifesto**: qualquer
cliente autenticado vê, abre e exporta os chamados de todos os outros clientes. Somado a isso, a busca
tem injeção de SQL (confirmei o despejo de `login`/`senha` de `usuarios` via `UNION`), XSS refletido,
senhas em MD5 sem salt e credenciais de produção versionadas no código. Há ainda um crash determinístico
(divisão por zero na média de SLA) e um export que grava um arquivo compartilhado de forma não atômica.

Corrigi tudo que era corrigível dentro do contrato do `manifest.md` (assinaturas, rotas, CSV, HTML e regra
de visibilidade preservados — verificado por caracterização HTTP antes/depois, ver §2). Deixei de fora, e
explico por quê em §4, o que exige ação operacional (rotação de segredos, ALTER em produção, vhost) ou
alteraria um comportamento que consumidores externos podem depender.

Todas as linhas citadas abaixo são da **numeração original** dos arquivos recebidos.

## 2. Como verifiquei

- Subi um MariaDB isolado com `code/schema.sql` + `code/seed.sql` e rodei o sistema original e o corrigido
  no `php -S` (PHP 8.4.26), logando com os quatro usuários do manifesto e exercitando `index.php`,
  `?busca=`, `?ver=`, `?export=csv` e payloads de injeção/XSS. Comparei as respostas byte a byte:
  a listagem, o detalhe e o CSV do **técnico** são idênticos ao original; só mudou o que os achados abaixo
  descrevem.
- Testes de unidade/integração (35 asserções) sobre as funções públicas: `listarChamados` continua
  devolvendo todos os chamados com as mesmas chaves, na mesma ordem e com os mesmos tipos;
  `rotuloPrioridade` refatorado foi comparado com a versão original em 856 combinações; `exportarCsv`
  em CLI produz saída idêntica ao legado e publica `EXPORT_DIR/chamados.csv`.

## 3. Achados (em ordem de prioridade de correção)

### F1 · Regra de visibilidade do manifesto não é aplicada (cliente vê/abre/exporta chamados alheios)

- **Onde:** `code/index.php:44-46` (export), `:52-53` (detalhe), `:72-73` (listagem).
- **Mecanismo:** `index.php` guarda `uid`/`papel` na sessão (`:38-39`) e depois nunca os usa. A listagem
  chama `listarChamados($db, $busca)` que faz `SELECT * FROM chamados`; o detalhe chama `verChamado($db, $id)`
  sem comparar `usuario_id` com o usuário logado; o export chama `exportarCsv($db)` que despeja a tabela
  inteira. Confirmado: logada como `ana` (cliente), a listagem mostra #103/#104 (do `bruno`), `?ver=104`
  abre a "Fatura em duplicidade" dele e `?export=csv` devolve os 5 chamados.
- **Impacto:** qualquer cliente lê títulos, descrições e situação de chamados de outros clientes
  (dados pessoais/comerciais); enumeração trivial por `?ver=<id>` sequencial.
- **Severidade:** crítica · **Confiança:** 98.
- **O que fiz:** `index.php` deriva `$donoId` da sessão (`null` para `tecnico`, o próprio `uid` para
  qualquer outro papel — *fail closed*). A listagem passa a usar `consultarChamados($db, $busca, $donoId)`
  (nova função interna que aplica `WHERE c.usuario_id = ?` quando há dono); o detalhe responde "Chamado nao
  encontrado" tanto para id inexistente quanto para chamado de outro cliente (mesma resposta, sem revelar
  existência); o export de cliente usa `gerarCsvChamados($db, $donoId)` (mesmo formato, só os dele, e **não**
  sobrescreve o arquivo global de `EXPORT_DIR`). `listarChamados`, `verChamado` e `exportarCsv` mantêm
  assinatura e comportamento (devolvem tudo) porque são consumidos pela rotina noturna e pelo relatório
  gerencial, que não são "clientes".

### F2 · Injeção de SQL na busca por título

- **Onde:** `code/lib.php:82`.
- **Mecanismo:** `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"` concatena o parâmetro `busca` da URL
  direto no SQL. Com `busca=x' UNION SELECT 1,2,3,login,senha,6,7,8,9 FROM usuarios -- ` a listagem
  original devolveu quatro linhas com os logins na coluna Titulo (os hashes MD5 vieram junto, na coluna
  mapeada para `descricao`). `' OR 1=1 -- ` também funciona.
- **Impacto:** leitura de qualquer tabela acessível ao usuário `painel` (hashes MD5 quebráveis em segundos,
  ver F4); dependendo dos privilégios, escrita. Exploração exige apenas uma conta de cliente.
- **Severidade:** crítica · **Confiança:** 100.
- **O que fiz:** consulta parametrizada (`c.titulo LIKE ?` com `'%'.$busca.'%'` como parâmetro). Mantive a
  semântica de curingas (`%`/`_` dentro do termo continuam sendo curingas), pois é o comportamento
  observável atual e não é vetor de injeção — ver §4.

### F3 · XSS refletido pelo parâmetro `busca`

- **Onde:** `code/index.php:79` (atributo `value`) e `:82` (texto "Resultados para").
- **Mecanismo:** `$busca` é impresso cru dentro de `value="..."` e no `<p>`. Com
  `busca="><script>alert(1)</script>` o HTML devolvido fecha o `<input>` e executa o script (confirmado).
- **Impacto:** um link malicioso enviado a um técnico executa JS na origem do painel; com o cookie de
  sessão acessível a JS (F9) isso vira roubo de sessão de técnico — que vê tudo.
- **Severidade:** alta · **Confiança:** 100.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos. Os demais campos
  dinâmicos da página já eram escapados ou são inteiros do banco.

### F4 · Senhas armazenadas como MD5 puro, sem salt

- **Onde:** `code/lib.php:15-16`; `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:** `autenticar` faz `md5($senha)` e compara em SQL. MD5 sem salt é reversível por tabela/GPU
  em segundos para senhas comuns (`senha123`, `tecmaster`), e a comparação em SQL usa collation
  case-insensitive. Combinado com F2, um cliente extrai e quebra as senhas dos técnicos sem sair do painel.
- **Impacto:** comprometimento de contas (inclusive técnicas) a partir de qualquer vazamento do banco.
- **Severidade:** alta · **Confiança:** 95.
- **O que fiz:** `autenticar` passa a buscar o usuário pelo login e verificar a senha em PHP: hashes que
  começam com `$` vão por `password_verify`; hashes de 32 hex (legado) por `hash_equals(md5)`. Quando um
  login legado dá certo, `migrarSenhaLegada` regrava com `password_hash(PASSWORD_DEFAULT)` **somente se**
  `information_schema` mostrar que a coluna já comporta o hash — em bancos ainda com `CHAR(32)` nada é
  gravado (gravar truncaria o hash e trancaria o usuário). Qualquer erro na migração é engolido; o login já
  foi validado. `schema.sql` passa a criar `senha VARCHAR(255)` e traz o `ALTER` para bancos existentes.
  O contrato `['id','nome','papel']`/`null` é o mesmo (testado com os 4 usuários e senhas erradas, em
  coluna `CHAR(32)` e `VARCHAR(255)`).

### F5 · Credenciais de produção versionadas no código

- **Onde:** `code/config.php:12` (`DB_PASS` com fallback `N3tX@2013!prod`), `:15` (`SMTP_API_KEY` fixa).
- **Mecanismo:** o fallback da senha do banco e a chave SMTP inteira estão literalmente no fonte. Qualquer
  pessoa com acesso ao repositório, a um backup ou ao pacote de deploy tem a senha do banco de produção e a
  chave da central de e-mail. O `getenv` em `DB_PASS` não ajuda: se a variável faltar, o segredo do fonte é
  usado sem aviso.
- **Impacto:** acesso direto ao banco (todos os chamados, todos os hashes) e envio de e-mail em nome do ISP.
- **Severidade:** alta · **Confiança:** 95.
- **O que fiz (parcial — `corrigido: false`):** `SMTP_API_KEY` passou a ler do ambiente antes do fallback e
  deixei um `TODO(ops)` explícito. **Não removi os fallbacks** porque isso derruba a produção num deploy
  sem as variáveis, e o fix real (rotacionar as duas credenciais, configurar o ambiente e aí sim remover o
  fallback) é operacional, não de código. As credenciais atuais devem ser tratadas como vazadas.

### F6 · `mediaResposta` divide por zero e derruba a listagem

- **Onde:** `code/lib.php:116`.
- **Mecanismo:** `return $soma / $qtd;` sem tratar `$qtd == 0`. Quando nenhum chamado tem
  `minutos_resposta` (instalação nova, base limpa, filtro futuro) o PHP 8 lança `DivisionByZeroError`,
  e como `index.php:74` chama a função antes de qualquer saída, a listagem inteira cai com erro 500 para
  todos os usuários. Reproduzido com `UPDATE chamados SET minutos_resposta = NULL`. De quebra, a função
  transfere todas as linhas para o PHP só para somar.
- **Impacto:** indisponibilidade total do painel numa condição de dados legítima.
- **Severidade:** média · **Confiança:** 100.
- **O que fiz:** `SELECT SUM(...) , COUNT(...)` e divisão em PHP (mesmo resultado float do cálculo original:
  `25.666…` no seed); devolve `0.0` quando não há amostras. Assinatura `mediaResposta(mysqli): float` intacta.

### F7 · `exportarCsv` grava arquivo compartilhado de forma não atômica e falha em silêncio

- **Onde:** `code/lib.php:125-150`.
- **Mecanismo:** cada export abre `EXPORT_DIR/chamados.csv` com `fopen('w')` (trunca), escreve linha a
  linha e só então faz `readfile` do mesmo caminho. Dois exports simultâneos (ou o export web durante a
  leitura da rotina noturna) truncam/entrelaçam o mesmo arquivo — quem lê pega um CSV parcial ou misto.
  Se `fopen` falha (diretório inexistente/sem permissão) a função retorna sem nada e `index.php` faz `exit`:
  o usuário recebe uma página em branco com HTTP 200. Se a query falha, retorna com o `$fp` aberto e o
  arquivo só com cabeçalho.
- **Impacto:** CSV corrompido para a integração de faturamento/rotina noturna; export "morto" sem
  diagnóstico.
- **Severidade:** média · **Confiança:** 90.
- **O que fiz:** `gerarCsvChamados` monta o CSV completo em `php://temp`, envia ao cliente e, só na
  exportação completa, `publicarArquivoExport` grava em arquivo temporário no mesmo diretório e faz
  `rename()` (atômico) sobre `chamados.csv`, com as mesmas permissões que `fopen('w')` daria. A entrega ao
  navegador/stdout não depende mais do arquivo. Saída verificada byte a byte igual ao legado.

### F8 · Arquivo com todos os chamados persistido sob `/var/www` (docroot)

- **Onde:** `code/config.php:18`; uso em `code/lib.php:125`.
- **Mecanismo:** `EXPORT_DIR = /var/www/painel/tmp` fica dentro da árvore do servidor web. Se o vhost
  serve `/var/www/painel` (o padrão para um painel em `/var/www/painel/index.php`), o arquivo
  `tmp/chamados.csv` — que contém todos os chamados após qualquer export — é baixável sem login em
  `/tmp/chamados.csv`. Não tenho o vhost para confirmar, por isso a confiança é moderada.
- **Impacto:** vazamento de toda a base de chamados sem autenticação.
- **Severidade:** média · **Confiança:** 60.
- **O que fiz (`corrigido: false`):** tornei `EXPORT_DIR` configurável por ambiente e documentei no
  `config.php` que o diretório deve ficar fora do docroot ou ser bloqueado no servidor web. Não mudei o
  caminho padrão porque a rotina noturna provavelmente lê dele.

### F9 · Sessão: id não regenerado no login; cookie sem `HttpOnly`/`SameSite`

- **Onde:** `code/index.php:15` (`session_start`), `:24-26` (login sem `session_regenerate_id`).
- **Mecanismo:** o `PHPSESSID` criado antes do login é o mesmo usado depois. Um atacante que fixe um id
  na vítima (link/cookie injetado) passa a compartilhar a sessão autenticada dela. O cookie sai apenas com
  `path=/` (confirmado nos headers): acessível a JS (o XSS de F3 rouba a sessão) e enviado em requisições
  cross-site.
- **Impacto:** sequestro de sessão, sobretudo de técnicos.
- **Severidade:** média · **Confiança:** 85.
- **O que fiz:** `session_regenerate_id(true)` após autenticar (testado: cookie pré-fixado `fixado123`
  é substituído), `session.cookie_httponly=1`, `session.cookie_samesite=Lax` e `cookie_secure` quando a
  requisição chega por HTTPS. Usei `ini_set` (funciona em qualquer PHP 7+) em vez de
  `session_set_cookie_params` com array.

### F10 · N+1 consultas na listagem e no export (`tecnicoNome` por linha)

- **Onde:** `code/lib.php:88-91` e `:136-137` (chamando `:64-72`).
- **Mecanismo:** para cada chamado, uma `SELECT nome FROM usuarios WHERE id = …` extra. A listagem de N
  chamados custa N+1 round-trips ao banco; o export completo (rotina noturna) também. Com o crescimento da
  base desde 2013 isso escala linearmente a cada carregamento da página inicial.
- **Impacto:** latência e carga no banco proporcionais ao número de chamados.
- **Severidade:** média · **Confiança:** 100.
- **O que fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-') AS tecnico_nome`
  nas duas consultas — uma query em vez de N+1, mesmo valor `'-'` para chamado sem técnico ou técnico
  inexistente. `tecnicoNome` foi mantida (relatórios internos podem chamá-la), agora parametrizada.

### F11 · Entradas não-string derrubam a página com stack trace

- **Onde:** `code/index.php:22` (`$_POST['login']`), `:53` (`$_GET['ver']`), `:72-73` (`$_GET['busca']`).
- **Mecanismo:** `?busca[]=x` faz o PHP entregar um array; `listarChamados(mysqli, string)` lança
  `TypeError` não capturado → erro 500 com stack trace (caminhos do servidor) quando `display_errors` está
  ligado — reproduzido. `login[]=x` faz o mesmo em `autenticar`. `(int) $_GET['ver']` com array vira 0/1
  silenciosamente.
- **Impacto:** negação de serviço trivial por URL e vazamento de caminhos internos.
- **Severidade:** baixa · **Confiança:** 95.
- **O que fiz:** `is_string`/`is_scalar` nos três pontos, com fallback para vazio/0 (mesmo efeito de
  "sem parâmetro").

### F12 · `fputcsv` sem `$escape` explícito polui o CSV em PHP ≥ 8.4

- **Onde:** `code/lib.php:130` e `:138`.
- **Mecanismo:** PHP 8.4 emite `Deprecated: fputcsv(): the $escape parameter must be provided` a cada
  chamada. Com `display_errors` ligado o aviso é escrito **antes** do cabeçalho — observei o CSV do
  original começar com seis blocos `<b>Deprecated</b>` em vez de `ID,Titulo,Status,Tecnico,Aberto em`,
  quebrando o "cabeçalho exato" do manifesto. Em produção com `display_errors=Off` vira só ruído de log.
- **Impacto:** CSV inválido para a integração em ambientes com `display_errors`; logs inflados.
- **Severidade:** baixa · **Confiança:** 90.
- **O que fiz:** passo `',', '"', '\\'` explicitamente — exatamente o default atual, saída idêntica, sem
  aviso.

### F13 · SQL montado por concatenação em `verChamado` e `tecnicoNome`

- **Onde:** `code/lib.php:69` e `:100`.
- **Mecanismo:** `'... WHERE id = ' . $id`. Hoje é seguro apenas porque os parâmetros são tipados `int` e
  `index.php` faz o cast; basta um consumidor futuro passar uma string com `strict_types` desligado, ou
  alguém "relaxar" o tipo, para reabrir F2 aqui.
- **Impacto:** dívida de segurança latente; sem exploração no estado atual.
- **Severidade:** baixa · **Confiança:** 80.
- **O que fiz:** prepared statements com `bind_param('i', …)`. Assinaturas e retornos inalterados.

### F14 · `rotuloPrioridade` com quatro níveis de `if/else` aninhados

- **Onde:** `code/lib.php:40-59`.
- **Mecanismo:** a regra (SLA → prioridade → minutos → prioridade) está enterrada em aninhamento, com o caso
  "sem resposta" no último `else`. É difícil de ler e de alterar sem quebrar um ramo — e os rótulos são
  contrato do relatório gerencial.
- **Impacto:** manutenibilidade; risco de regressão em alterações futuras.
- **Severidade:** baixa · **Confiança:** 85.
- **O que fiz:** reescrita com retornos antecipados. Equivalência verificada contra a função original em
  todas as combinações de prioridade −1..6 × minutos null/−5..100 (856 casos, zero diferenças).

### F15 · `formatarStatus` rotula qualquer valor desconhecido como "Resolvido"

- **Onde:** `code/lib.php:32-34`.
- **Mecanismo:** o `else` final devolve `'Resolvido'` para 3 **e para qualquer outro valor** (0, 4, 99, dado
  corrompido, status novo). O relatório gerencial casa por texto: um chamado com status inválido conta
  como resolvido em silêncio.
- **Impacto:** métricas gerenciais infladas sem sinal de erro; hoje o schema só produz 1–3 (TINYINT sem
  CHECK), então é latente.
- **Severidade:** baixa · **Confiança:** 70.
- **O que fiz (`corrigido: false`):** nada. O manifesto fixa só 1/2/3; o valor para fora da faixa não está
  no contrato e trocá-lo (ex.: "Desconhecido") pode quebrar um consumidor que hoje, por acidente, depende do
  fallback. Recomendo `CHECK (status IN (1,2,3))` no banco e um `default` explícito quando o contrato for
  revisado.

### F16 · Injeção de fórmula em planilha via `Titulo` do CSV

- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** títulos são escritos no CSV sem tratamento; um título iniciado por `=`, `+`, `-` ou `@`
  (ex.: `=HYPERLINK(...)`) é interpretado como fórmula pelo Excel/LibreOffice de quem abre o export.
  Títulos vêm de clientes (por outro sistema), então o vetor existe.
- **Impacto:** execução de fórmula/exfiltração na máquina do técnico que abre a planilha; depende das
  proteções do aplicativo.
- **Severidade:** baixa · **Confiança:** 60.
- **O que fiz (`corrigido: false`):** nada. A mitigação clássica (prefixar `'`) altera o conteúdo do
  campo, e o CSV é consumido por integração de faturamento — mudar o dado é quebra de contrato.

### F17 · Login sem limite de tentativas

- **Onde:** `code/index.php:21-22`.
- **Mecanismo:** não há contagem de falhas, atraso ou bloqueio; o custo de uma tentativa é uma query. Com
  senhas como as do seed, força bruta online é viável.
- **Impacto:** tomada de contas por adivinhação.
- **Severidade:** baixa · **Confiança:** 90.
- **O que fiz (`corrigido: false`):** nada no código. Rate limiting correto precisa de estado compartilhado
  (tabela/cache) ou do servidor web/WAF; um contador em `$_SESSION` é contornável e daria falsa sensação de
  segurança. Fica como recomendação operacional.

## 4. Decisões — o que deliberadamente não mudei

| O quê | Por quê |
| --- | --- |
| Assinaturas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv` | Contrato do manifesto. Adicionei funções **novas** (`consultarChamados`, `gerarCsvChamados`, `migrarSenhaLegada`, `publicarArquivoExport`) em vez de parâmetros opcionais nas existentes. |
| `listarChamados`/`exportarCsv` continuam devolvendo **todos** os chamados | São a interface da rotina noturna e do relatório gerencial. A regra de visibilidade é do *ator* web e foi aplicada em `index.php` (F1). |
| Curingas `%`/`_` no termo de busca | Comportamento observável atual (`?busca=%` lista tudo). Escapá-los seria mais "correto" mas muda o resultado de buscas existentes; não é vetor de injeção. |
| `formatarStatus` para valores fora de 1–3 (F15) | Fora do contrato; mudar pode quebrar consumidor que depende do fallback. |
| Fallbacks de `DB_PASS`/`SMTP_API_KEY` (F5) | Remover derruba produção sem as variáveis de ambiente; a correção real é rotação + configuração, operacional. |
| Caminho padrão de `EXPORT_DIR` (F8) | A rotina noturna provavelmente lê `/var/www/painel/tmp/chamados.csv`; tornei configurável e documentei, não movi. |
| Prefixar `'` em campos do CSV (F16) | Altera dados de um formato que é contrato de integração. |
| Rate limiting no login (F17) | Precisa de infraestrutura (estado compartilhado/WAF); solução em sessão seria cosmética. |
| Ordem da listagem (`criado_em DESC` sem desempate por `id`) | Ordem entre chamados com o mesmo instante é indefinida hoje; acrescentar desempate mudaria a ordem observada em bases reais. Não é bug funcional. |
| HTTP 404 para chamado inexistente / 403 para alheio | O original responde 200 com "Chamado nao encontrado"; mantive o status e a mensagem (favoritos/automação externa podem depender), e a mesma mensagem para os dois casos evita enumeração. |
| Estrutura de `index.php` (roteamento + SQL + HTML no mesmo arquivo), ausência de logout, CSRF no login | Reorganizar seria reescrita, não evolução; logout/CSRF são funcionalidades novas fora do escopo do enunciado. |
| `.leb-pacote.sha256` | É o manifesto de integridade do pacote recebido; não é meu para atualizar. |

## 5. Resumo das alterações por arquivo

- `code/lib.php` — `autenticar` (verificação em PHP, dual md5/password_hash, migração oportunista);
  `migrarSenhaLegada` (nova); `rotuloPrioridade` (achatado, equivalente); `tecnicoNome`, `verChamado`
  (prepared); `listarChamados` → delega para `consultarChamados` (nova; JOIN, prepared, filtro de dono);
  `mediaResposta` (agregado em SQL, sem divisão por zero); `exportarCsv` → delega para `gerarCsvChamados`
  (nova; JOIN, prepared, filtro de dono, `fputcsv` com escape explícito); `publicarArquivoExport` (nova;
  gravação atômica).
- `code/index.php` — flags do cookie de sessão, `session_regenerate_id` no login, saneamento de tipos de
  `login`/`senha`/`ver`/`busca`, `$donoId` e sua aplicação em export/detalhe/listagem, escape de `busca`.
- `code/config.php` — `SMTP_API_KEY` e `EXPORT_DIR` lidos do ambiente antes do fallback; avisos de rotação
  e de docroot.
- `code/schema.sql` — `senha VARCHAR(255)`; `ALTER` documentado para bancos existentes.
- `code/seed.sql` — inalterado (as senhas MD5 do seed continuam válidas e são migradas no primeiro login
  quando a coluna permite).

## 6. Compatibilidade com o manifesto (verificado)

- Funções públicas: nomes, parâmetros, tipos e retornos idênticos; `listarChamados` devolve as mesmas
  chaves na mesma ordem (`…, criado_em, tecnico_nome`) e os mesmos tipos nativos.
- `formatarStatus`: `1/2/3 → Aberto / Em atendimento / Resolvido` — não tocado.
- Rotas `busca`, `ver`, `export=csv` — nomes e efeitos preservados.
- CSV: cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, ordem por `id`, rótulos de `formatarStatus` —
  saída do técnico e da CLI byte a byte igual ao original.
- HTML: `id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico, link `index.php?ver=<id>` —
  listagem do técnico byte a byte igual ao original.
- Regra de visibilidade: técnico vê tudo; cliente vê só os seus — agora efetivamente aplicada.
