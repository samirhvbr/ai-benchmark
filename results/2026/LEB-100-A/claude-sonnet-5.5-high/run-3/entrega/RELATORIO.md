# RELATORIO — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

Sistema pequeno (PHP + mysqli) com falhas sérias de segurança: injeção de SQL na busca, XSS refletido, e a regra de visibilidade do manifesto (cliente só vê os próprios chamados) não é aplicada em nenhum dos três pontos de leitura: listagem, detalhe e export CSV. Há também uma falha de disponibilidade (divisão por zero na média), credenciais de produção no código e MD5 sem sal. Corrigi 14 dos 17 achados, preservando nomes, assinaturas, rotas, cabeçalho e formato do CSV e a estrutura HTML da listagem. Deixei 3 só reportados (F9, F16, F17).

**O que foi e o que não foi verificado:** `php -l` passa em todos os arquivos e testei sem banco `rotuloPrioridade` (8 combinações, idêntico ao original), `formatarStatus` e `csvSeguro`. **Não consegui executar as queries nem as telas contra um banco**: o MariaDB local recusou o usuário do ambiente e não tentei outras credenciais. As consultas novas (JOIN + `bind_param` dinâmico + `fetch_all`) foram revisadas só por leitura e devem ser exercitadas contra `schema.sql`/`seed.sql` antes de ir para produção. Em particular: ana vê 101, 102, 105 e recebe 'nao encontrado' em `?ver=103`; carla vê os 5; o CSV de carla tem 5 linhas ordenadas por id.

**Mudança operacional:** `DB_PASS` agora tem de vir do ambiente (F8), e as credenciais antigas têm de ser rotacionadas.

Numeração de linhas abaixo = arquivos originais recebidos.

## 2. Achados (ordem de prioridade de correção)

### F1 — SQL injection na busca por título (listarChamados)
- **Onde:** `code/lib.php:82-84` · **Categoria:** seguranca · **Severidade:** critica · **Confiança:** 98
- **Mecanismo:** `$busca` (vem de `$_GET['busca']` sem tratamento, index.php:72) é concatenado dentro de `LIKE '%...%'`. A entrada `x%' UNION SELECT id,login,senha,nome,papel,0,0,0,NOW() FROM usuarios -- ` fecha a string e acrescenta um SELECT; as linhas voltam renderizadas na tabela. A conexão usa `query()` (não `multi_query`), então não há stacked queries, mas leitura arbitrária é possível. A busca é acessível a qualquer usuário logado, inclusive clientes.
- **Impacto:** Qualquer cliente lê qualquer tabela que o usuário `painel` enxergue (hashes MD5 de todos os usuários, que são quebráveis, descrições de todos os chamados) e pode usar funções/condições para inferir dados.
- **O que fiz:** Query passou a ser preparada (`consultarChamados`): o termo vai por `bind_param`, com `%`, `_` e `\` escapados para o LIKE ser literal. (corrigido)

### F2 — Cliente lista chamados de outros clientes
- **Onde:** `code/index.php:72-74` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** A listagem chama `listarChamados($db, $busca)` que faz `SELECT * FROM chamados` sem WHERE por dono; `$_SESSION['papel']` é lido (index.php:39) mas nunca usado. Logo ana (cliente) vê os chamados 103 e 104 de bruno. Viola a regra de visibilidade do manifesto.
- **Impacto:** Vazamento de títulos, status e técnico de todos os chamados de todos os clientes (dado pessoal e comercial, ex.: 'Fatura em duplicidade').
- **O que fiz:** Cliente usa o novo `listarChamadosDoUsuario($db,$uid,$busca)` (filtra `usuario_id`); técnico continua com `listarChamados`. A assinatura de `listarChamados` não mudou. (corrigido)

### F3 — IDOR: ?ver=<id> mostra qualquer chamado a qualquer logado
- **Onde:** `code/index.php:52-66` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 97
- **Mecanismo:** `verChamado((int)$_GET['ver'])` busca só por id e o index.php imprime título e descrição sem comparar `usuario_id` com `$_SESSION['uid']`. IDs são inteiros sequenciais (101..105), então ana abre `?ver=103` e lê o chamado de bruno, incluindo a descrição.
- **Impacto:** Leitura de chamados alheios, com enumeração trivial por id.
- **O que fiz:** Novo `chamadoVisivel($c,$uid,$papel)` (técnico vê tudo; cliente só o próprio; papel desconhecido = cliente). Negado responde a mesma mensagem de 'nao encontrado', para não permitir enumerar ids existentes. `verChamado` mantém a assinatura. (corrigido)

### F4 — CSV exporta todos os chamados a qualquer usuário e usa arquivo fixo compartilhado
- **Onde:** `code/lib.php:123-151` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 90
- **Mecanismo:** (a) `exportarCsv` seleciona todos os chamados sem filtro e o index.php chama sem checar papel: um cliente baixa o CSV completo. (b) O CSV é gravado sempre em `EXPORT_DIR/chamados.csv` e relido com `readfile`: duas exportações simultâneas truncam/misturam o mesmo arquivo (`fopen('w')`), então um usuário pode receber a exportação de outro, e o arquivo com dados pessoais fica em disco depois do request. (c) Se `fopen` ou a query falham a função retorna sem cabeçalho HTTP; se a query falha o handle não é fechado e o arquivo fica truncado.
- **Impacto:** Vazamento total de dados pelo export; condição de corrida com troca de conteúdo entre sessões; PII residual em /var/www/painel/tmp.
- **O que fiz:** Nova `exportarCsvDoUsuario($db, ?int $usuarioId)` consulta antes de enviar qualquer saída e escreve direto em `php://output` (sem arquivo). `exportarCsv($db)` mantém a assinatura e delega com `null` (tudo), para a rotina noturna. O index.php passa o `$uid` quando o papel não é técnico. Cabeçalho, ordem por id e rótulos de status idênticos. (corrigido)

### F5 — XSS refletido na busca
- **Onde:** `code/index.php:79-82` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 97
- **Mecanismo:** `$busca` é impresso cru em `value="..."` e em `<p>Resultados para: ...</p>`. `?busca="><script>...` executa no navegador de quem abrir o link, com sessão do painel. Os outros campos já usam htmlspecialchars; estes dois ficaram de fora.
- **Impacto:** Execução de JS na sessão da vítima (técnico ou cliente) via link: roubo de dados da página, ações em nome dela.
- **O que fiz:** Helper `h()` (htmlspecialchars com ENT_QUOTES e UTF-8) aplicado a busca, título, descrição e nome do técnico. (corrigido)

### F6 — mediaResposta: divisão por zero quando nenhum chamado tem resposta
- **Onde:** `code/lib.php:107-117` · **Categoria:** bug · **Severidade:** alta · **Confiança:** 90
- **Mecanismo:** `$soma / $qtd` com `$qtd == 0` lança `DivisionByZeroError` no PHP 8 (ambiente tem 8.4). Acontece quando todos os `minutos_resposta` são NULL (base nova, ou só chamados novos). Como `mediaResposta` roda em toda renderização da listagem, a página inteira cai em erro fatal. Também lê todas as linhas para somar no PHP.
- **Impacto:** Painel indisponível para todos os usuários nesse estado de dados.
- **O que fiz:** Usa `AVG(minutos_resposta)` no SQL e devolve 0.0 se não há dados. Mesmo valor no caso normal. (corrigido)

### F7 — N+1 consultas ao buscar o nome do técnico
- **Onde:** `code/lib.php:64-93` · **Categoria:** performance · **Severidade:** media · **Confiança:** 92
- **Mecanismo:** `listarChamados` e `exportarCsv` chamam `tecnicoNome()` por linha, que faz uma query a mais por chamado: N+1 consultas por página/export. Além disso a query é concatenada com o id (`'... WHERE id = ' . $tecnicoId`); é seguro só porque o tipo é `?int`, o que é frágil.
- **Impacto:** Latência cresce linearmente com o número de chamados; o export noturno faz milhares de round-trips.
- **O que fiz:** `LEFT JOIN usuarios t` com `COALESCE(t.nome,'-')` como `tecnico_nome` numa única query (chave e valor '-' preservados). `tecnicoNome()` continua existindo, agora preparado; `verChamado` também. (corrigido)

### F8 — Credenciais embutidas no código (senha de produção do banco e chave SMTP)
- **Onde:** `code/config.php:11-15` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 92
- **Mecanismo:** `DB_PASS` tem fallback com a senha de produção em texto e `SMTP_API_KEY` é literal. Quem tem acesso ao repositório/backup/pacote tem as duas; o fallback também faz o sistema 'funcionar' em qualquer ambiente com a senha de produção sem ninguém decidir isso.
- **Impacto:** Comprometimento do banco de produção e da conta de e-mail transacional a partir do código-fonte.
- **O que fiz:** Ambas lidas apenas do ambiente (`DB_PASS`, `SMTP_API_KEY`), sem valor padrão. As credenciais antigas já estão expostas e PRECISAM ser rotacionadas: isso não se resolve só no código. Pré-requisito de deploy: definir DB_PASS no ambiente. (corrigido)

### F9 — Senhas guardadas como MD5 sem sal
- **Onde:** `code/lib.php:15-16` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 88
- **Mecanismo:** `autenticar` compara `md5($senha)` com a coluna `senha CHAR(32)`. MD5 sem sal é rápido e usuários com a mesma senha têm o mesmo hash (ana e bruno: `senha123`, carla e diego: `tecmaster`, idênticos no seed). Combinado com F1, um dump é quebrado em segundos.
- **Impacto:** Vazamento do banco equivale a vazamento das senhas.
- **O que fiz:** Não alterado: migrar para `password_hash` exige alargar a coluna (CHAR(32)) e migrar a base em produção, o que muda esquema e o seed/dados de teste do contrato. Proposta: ALTER para VARCHAR(255), aceitar os dois formatos no login e regravar com `password_hash` no primeiro login bem-sucedido. (NÃO corrigido, só reportado)

### F10 — Sessão: sem regenerar id no login e cookie sem flags
- **Onde:** `code/index.php:15-27` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 75
- **Mecanismo:** `session_start()` com parâmetros padrão e `$_SESSION['uid']` é definido sem `session_regenerate_id`. Um id de sessão plantado antes do login (fixação) continua válido depois dele. Cookie sem HttpOnly/SameSite.
- **Impacto:** Sequestro de sessão por fixação; cookie legível por JS (agrava F5) e enviado em requests cross-site.
- **O que fiz:** `session_set_cookie_params` (httponly, samesite=Lax, secure quando HTTPS) e `session_regenerate_id(true)` após autenticar. (corrigido)

### F11 — TypeError (fatal) com parâmetros em formato de array
- **Onde:** `code/index.php:22-73` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 85
- **Mecanismo:** `?busca[]=x` ou `login[]=x` fazem `$_GET['busca']`/`$_POST['login']` virarem array e o PHP 8 lança `TypeError` ao passar para parâmetros `string` de `listarChamados`/`autenticar`: erro 500 com stack trace se display_errors estiver ligado.
- **Impacto:** Qualquer um derruba a requisição à vontade; possível vazamento de caminho em mensagem de erro.
- **O que fiz:** Entradas que não são string são tratadas como vazias (login falha; busca vazia). (corrigido)

### F12 — Injeção de fórmula (CSV injection) no export
- **Onde:** `code/lib.php:138-144` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 55
- **Mecanismo:** O título do chamado é texto livre digitado pelo cliente e vai cru para o CSV. Um título `=HYPERLINK(...)` ou `=cmd|...` é interpretado como fórmula quando o técnico abre o arquivo no Excel/Calc.
- **Impacto:** Execução de fórmulas/exfiltração na máquina do técnico ou do gerente que abre o export.
- **O que fiz:** `csvSeguro()` prefixa com apóstrofo valores que começam com `= + - @ TAB CR` (título e nome do técnico). Efeito colateral aceito: um título que começa com '-' ou '+' sai com o apóstrofo. (corrigido)

### F13 — LIKE sem escape de curingas na busca
- **Onde:** `code/lib.php:82-82` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 50
- **Mecanismo:** `%` e `_` digitados pelo usuário atuam como curinga (`50%` casa tudo que contém '50'). A busca deveria ser por substring literal.
- **Impacto:** Resultados inesperados; busca '%' devolve tudo.
- **O que fiz:** Escape com `addcslashes` junto com a parametrização (F1). Mudança mínima de comportamento, só para quem digita `%`, `_` ou `\`. (corrigido)

### F14 — Exceção de conexão com o banco pode vazar host/usuário
- **Onde:** `code/index.php:9-12` · **Categoria:** seguranca · **Severidade:** baixa · **Confiança:** 50
- **Mecanismo:** Desde o PHP 8.1 `new mysqli()` lança `mysqli_sql_exception` em falha; o `if ($db->connect_errno)` nunca é alcançado e a exceção não tratada pode exibir host e usuário do banco se display_errors estiver ligado.
- **Impacto:** Divulgação de informações de infraestrutura quando o banco cai.
- **O que fiz:** try/catch que registra no log e mostra a mesma mensagem genérica de antes. (corrigido)

### F15 — rotuloPrioridade com if/else aninhado em 4 níveis
- **Onde:** `code/lib.php:40-59` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 70
- **Mecanismo:** A lógica de SLA está em quatro níveis de if/else, difícil de ler e de alterar.
- **Impacto:** Custo de manutenção e risco de erro em mudanças de regra.
- **O que fiz:** Reescrita com retornos antecipados, mesma tabela de saída (conferi 8 combinações contra a versão original: idênticas). (corrigido)

### F16 — Sem limite de tentativas de login nem proteção CSRF no formulário
- **Onde:** `code/index.php:20-35` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 60
- **Mecanismo:** O login aceita tentativas ilimitadas e o hash é MD5 (F9), então força bruta online/offline é barata. O formulário de login não tem token CSRF (login CSRF).
- **Impacto:** Adivinhação de senha por script; troca de sessão da vítima por uma do atacante.
- **O que fiz:** Não alterado: throttling precisa de estado persistente (tabela/cache) e o CSRF muda o HTML do formulário. Recomendado tratar no proxy/WAF ou numa evolução com tabela de tentativas. (NÃO corrigido, só reportado)

### F17 — formatarStatus devolve "Resolvido" para qualquer valor desconhecido
- **Onde:** `code/lib.php:26-35` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 30
- **Mecanismo:** O `else` final cobre 3 e também 0, 4, -1: um status inválido apareceria como 'Resolvido'. Hoje a coluna só tem 1..3, então é risco latente, não falha atual.
- **Impacto:** Chamado com status corrompido seria apresentado como encerrado.
- **O que fiz:** Não alterado: o contrato do manifesto fixa os 3 rótulos e o relatório gerencial casa pelo texto; mudar o default pode quebrar consumidores. (NÃO corrigido, só reportado)

## 3. Decisões — o que deliberadamente não mudei

- **Assinaturas públicas de lib.php (autenticar, formatarStatus, rotuloPrioridade, listarChamados, verChamado, mediaResposta, exportarCsv)** — Contrato do manifesto. Os controles de visibilidade foram feitos com funções novas (listarChamadosDoUsuario, exportarCsvDoUsuario, chamadoVisivel, consultarChamados) em vez de parâmetros novos, e as antigas continuam devolvendo tudo para a rotina noturna e o relatório gerencial.
- **Média de resposta global exibida também a clientes** — É um agregado sem identificar chamado; o manifesto não o restringe e o indicador é parte da tela. Se a diretoria considerar sensível, é decisão de produto.
- **Migração de MD5 para password_hash e alteração de schema.sql/seed.sql** — Exige ALTER na base de produção e migração de dados; fora do escopo de uma correção sem janela de manutenção. Proposta no F9.
- **Rate limiting e CSRF no login (F16), status inválido em formatarStatus (F17)** — Exigem estado novo ou mudam HTML/contrato; custo/risco maior que o benefício neste passo.
- **Status HTTP e HTML das telas (200 para 'nao encontrado', HTML sem template, sem logout)** — Fazem parte do comportamento observável por links/favoritos; não houve ganho de segurança em mudar.
- **SELECT * em verChamado e em listarChamados (agora c.*)** — Consumidores usam qualquer coluna do item retornado; reduzir colunas mudaria o formato de retorno.
- **Gravação do CSV em EXPORT_DIR/chamados.csv** — Removida de propósito (F4). O manifesto define só 'escreve o CSV na saída'. Se algum script externo lia esse arquivo em disco, ele precisa passar a capturar a saída; EXPORT_DIR continua definido em config.php para não quebrar require.
- **A chave SMTP e a senha do banco antigas** — Removidas do código mas continuam válidas: precisam ser rotacionadas por quem administra os serviços.

## 4. Arquivos alterados

- `code/lib.php` — consultas preparadas, JOIN, visibilidade, CSV em stream, média segura.
- `code/index.php` — escape de saída, checagem de papel/dono, sessão, tratamento de entradas.
- `code/config.php` — segredos só via ambiente.
- `schema.sql` e `seed.sql`: sem alteração.

Observação: `.leb-pacote.sha256` refere os arquivos originais; os hashes de `code/` mudam como esperado.
