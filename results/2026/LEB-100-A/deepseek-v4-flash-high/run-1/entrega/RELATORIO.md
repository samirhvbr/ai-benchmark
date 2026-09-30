# RELATORIO — LEB-100-A

## 1. Resumo

O pacote é um painel de chamados em PHP + mysqli (legado desde 2013): sete funções
públicas em `code/lib.php`, um ponto de entrada web (`code/index.php`), configuração
(`code/config.php`) e schema/seed do banco. A superfície pública do manifesto
(assinaturas, rotas, formato do CSV, estrutura da tabela HTML e a regra de visibilidade)
foi preservada integralmente.

O sistema tem problemas reais e graves: injeção SQL na busca, XSS refletido, senhas com
MD5, controle de acesso inexistente (um cliente consegue ver chamados de outros clientes,
inclusive pelo CSV) e credenciais de produção hardcoded. Há também bugs de robustez
(divisão por zero), vazamento por arquivo temporário e consultas N+1.

Todas as correções mantêm as assinaturas e os contratos declarados no manifesto. A
validação foi feita com `php -l` e com teste funcional real (MySQL 8/MariaDB + schema +
seed): login com migração de hash, visibilidade por papel, IDOR bloqueado, CSV por papel e
payload de injeção neutralizado.

> As referências de linha abaixo usam a **numeração original** dos arquivos entregues
> (o mesmo critério exigido para `achados.json`).

## 2. Achados (por ordem de prioridade de correção)

### F1 — Injeção SQL na busca de chamados — critica, confiança 100

- **Onde**: `code/lib.php` (original: linhas 82–85, em `listarChamados`).
- **Mecanismo**: `$busca` (vinda de `$_GET['busca']`) é concatenada diretamente em
  `WHERE titulo LIKE '%" . $busca . "%'`. Não há prepared statement nem escape. Um valor
  como `%' OR '1'='1' -- ` ou `%'; DROP TABLE chamados; --` quebra a query e executa SQL
  arbitrário com a conexão do aplicativo.
- **Impacto**: leitura, alteração ou destruição de dados arbitrários do banco por
  qualquer usuário autenticado — comprometimento total do banco.
- **Ação**: trocado para prepared statement (`WHERE c.titulo LIKE ?`) com escape de
  `%`, `_` e `\`. Teste real: payload `" OR 1=1 -- ` retorna 0 linhas (antes, a busca
  crua permitiria injeção).

### F2 — SQL montado por concatenação em `verChamado`/`tecnicoNome` — media, confiança 85

- **Onde**: `code/lib.php` (original: linha 100 em `verChamado`; linha 69 em `tecnicoNome`).
- **Mecanismo**: `SELECT * FROM chamados WHERE id = ' . $id` e `SELECT nome ... WHERE id = ' . $tecnicoId`.
  As assinaturas tipam `int`, então hoje a coerção do PHP mitiga a injeção, mas é um padrão
  latente e frágil: basta um widening de tipo ou um chamador não tipado para reintroduzir a
  vulnerabilidade; com `strict_types` o mesmo código quebra por TypeError.
- **Impacto**: injeção SQL potencial se a entrada não for inteira; padrão inseguro de
  construção de query.
- **Ação**: ambas migradas para prepared statements (`bind_param('i', ...)`).

### F3 — XSS refletido no termo de busca — alta, confiança 100

- **Onde**: `code/index.php` (original: linhas 79 e 82).
- **Mecanismo**: `$busca` é ecoada crua no atributo `value` do input
  (`value="' . $busca . '"`) e em `Resultados para: ' . $busca`. Como o mesmo parâmetro
  alimenta a SQL sem saneamento, a string chega intacta do cliente e volta ao HTML sem
  `htmlspecialchars`.
- **Impacto**: execução de script arbitrário no navegador da vítima (roubo de sessão,
  ações em nome do usuário); também injeção de atributo HTML.
- **Ação**: `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos de saída.

### F4 — Senhas com MD5 — critica, confiança 100

- **Onde**: `code/lib.php` (original: linha 15) e `code/schema.sql` (original: linha 7).
- **Mecanismo**: `autenticar` compara `md5($senha)` contra a coluna `senha CHAR(32)`.
  MD5 sem salt é quebrável por força bruta/rainbow table em horas após um dump do banco; a
  coluna `CHAR(32)` ainda impede armazenar hashes modernos (bcrypt tem ~60 chars) sem
  alterar o schema.
- **Impacto**: comprometimento de credenciais em massa; senhas reutilizadas afetam outros
  sistemas.
- **Ação**: `autenticar` lê o hash armazenado, verifica com `password_verify` se for hash
  moderno (prefixo `$`) ou com `hash_equals`/`md5` se for legado, e re-hash de forma
  transparente no primeiro login válido. `schema.sql` ampliado para `VARCHAR(255)`. Contas
  existentes (seed MD5) continuam logando e migram automaticamente — verificado: ana logou,
  hash migrou para `$2y$...` e relogou com sucesso.

### F5 — Controle de acesso inexistente: cliente vê chamados de outros clientes — critica, confiança 100

- **Onde**: `code/index.php` (original: linhas 52–67 no detalhe; 72–73 na listagem) e
  `code/lib.php` (`listarChamados`, `verChamado`).
- **Mecanismo**: o manifesto define a regra "cliente só vê os chamados que abriu; técnico
  vê todos". Nenhum ponto a aplica: `listarChamados` retorna todos os chamados e
  `verChamado` devolve qualquer id. Qualquer cliente logado lista os títulos de todos e
  abre qualquer detalhe passando `?ver=<id>` (IDOR).
- **Impacto**: vazamento integral da base de chamados entre clientes (concorrentes
  enxergam incidentes e faturas uns dos outros).
- **Ação**: a regra passou a ser aplicada na camada de roteamento, sem alterar assinaturas
  públicas: na listagem e no CSV, clientes são filtrados por `usuario_id === uid`; no
  detalhe, cliente que não é dono recebe "não encontrado" (sem revelar existência).
  Verificado: ana vê 3 chamados, bruno vê 2, ana não acessa o chamado 103 do bruno.

### F6 — Rota `?export=csv` vaza todos os chamados para clientes — alta, confiança 90

- **Onde**: `code/index.php` (original: linhas 44–47).
- **Mecanismo**: a rota chama `exportarCsv($db)`, que despeja todos os chamados sem
  verificar papel. Um cliente logado baixa a base inteira.
- **Impacto**: exfiltração completa de dados via CSV, contrariando a regra de visibilidade.
- **Ação**: para `papel === 'cliente'`, a rota gera o CSV com apenas os chamados do
  usuário, mesma ordem (id crescente) e mesmo cabeçalho, através do helper
  `escreverCsvSaida`. O técnico mantém `exportarCsv($db)` intacto (full-dump, como a rotina
  noturna espera).

### F7 — Divisão por zero em `mediaResposta` — media, confiança 95

- **Onde**: `code/lib.php` (original: linha 116).
- **Mecanismo**: sem nenhum chamado com `minutos_resposta` preenchido, `$qtd === 0` e
  `$soma / $qtd` dispara `DivisionByZeroError` (PHP 8) — a tela inteira quebra.
- **Impacto**: painel inutilizável em banco vazio ou sem respostas registradas.
- **Ação**: retorno de `0.0` quando `$qtd === 0`; assinatura `float` preservada.

### F8 — Credenciais de produção hardcoded no código-fonte — alta, confiança 100

- **Onde**: `code/config.php` (original: linhas 12 e 15).
- **Mecanismo**: `DB_PASS` tem fallback com a senha real de produção (`N3tX@2013!prod`) e
  `SMTP_API_KEY` é a chave real da central de e-mail. Quem acessa o repositório obtém
  acesso ao banco e ao serviço transacional.
- **Impacto**: comprometimento total caso o código vaze; risco contínuo de exfiltração de
  segredos versionados.
- **Ação**: ambos passam a vir exclusivamente de variáveis de ambiente (`getenv`), sem
  fallback sensível (vazio se ausente). A constante `SMTP_API_KEY` continua definida para
  não quebrar scripts que a referenciem.

### F9 — CSV temporário em disco com dados sensíveis + leak de recursos — media, confiança 85

- **Onde**: `code/lib.php` (original: linhas 125–150).
- **Mecanismo**: `exportarCsv` grava `/var/www/painel/tmp/chamados.csv` (a base inteira) e
  só então faz `readfile`. O arquivo permanece no disco após o download; se o diretório for
  servido, vira exfiltração passiva. Além disso, se a query falhar após o `fopen`, o handler
  é abandonado e o arquivo fica meio escrito.
- **Impacto**: exposição de dados sensíveis em disco + arquivos órfãos.
- **Ação**: o CSV é emitido direto via `php://output` (sem arquivo intermediário); a query
  é validada antes de abrir a saída.

### F10 — `formatarStatus` rotula status inválido como "Resolvido" — baixa, confiança 80

- **Onde**: `code/lib.php` (original: linha 33).
- **Mecanismo**: o `else` genérico devolve `Resolvido` para qualquer valor fora de 1/2,
  inclusive dados corrompidos (a coluna é `TINYINT` sem CHECK), falseando o relatório
  gerencial que casa por esses textos.
- **Impacto**: chamados inválidos contados como resolvidos em relatórios.
- **Ação**: mapeamento explícito 1/2/3; valores fora do contrato retornam `Desconhecido`.

### F11 — Consulta N+1 na listagem — baixa, confiança 95

- **Onde**: `code/lib.php` (original: linha 89).
- **Mecanismo**: para cada chamado, `tecnicoNome()` executa uma query extra (`1 + N`
  queries na listagem e no CSV). Com milhares de chamados, a latência cresce linearmente.
- **Impacto**: lentidão proporcional ao volume; relevante para exportações grandes.
- **Ação**: `listarChamados` usa `LEFT JOIN usuarios` com `COALESCE(u.nome, '-')`, mantendo
  a chave `tecnico_nome` e o `-` para chamados sem técnico. `exportarCsv` mantém
  `tecnicoNome()` (agora com prepared statement).

### F12 — Fixação de sessão — media, confiança 85

- **Onde**: `code/index.php` (original: linhas 24–28).
- **Mecanismo**: o login não regenera o id de sessão; o cookie é o mesmo antes e depois da
  autenticação, permitindo fixação se um adversário conhecer/forçar o id.
- **Impacto**: sequestro de sessão autenticada.
- **Ação**: `session_regenerate_id(true)` após login válido + `session_set_cookie_params`
  (`httponly`, `samesite=Lax`) antes de `session_start()`.

## 3. Decisões (o que deliberadamente NÃO mudei)

| Decisão | Motivo |
| --- | --- |
| `rotuloPrioridade` intocada | A lógica confere com o contrato textual do manifesto; nenhum defeito identificado. |
| Ordem da listagem (`criado_em DESC`) | O manifesto não fixa a ordem da listagem; alterar mudaria comportamento observável sem ganho. |
| CSRF no formulário de login | Risco marginal (login CSRF); exigiria token e mudaria o HTML público do login. Registrado, não corrigido. |
| Paginação da listagem | Fora do escopo ("evolua, não reescreva"); o manifesto não exige. |
| `tecnicoNome` mantida como função pública | Não consta no manifesto, mas outros scripts do ISP podem usá-la; removê-la seria risco não documentado. Foi saneada (prepared statement). |
| `SMTP_API_KEY` continua definida | Scripts externos podem referenciar a constante; mantive o `define`, agora lido de env. |
| HTTPS/`secure` no cookie de sessão | Depende da terminação TLS do servidor (fora do código); o que está sob controle do PHP (`httponly`, `samesite`) foi ajustado. |
| `seed.sql` intocado | Hashes MD5 continuam válidos via migração transparente no primeiro login; re-hashear o seed não é necessário. |
| Stack e camada de acesso a dados (mysqli) | Restrição explícita do manifesto; todas as correções continuam em mysqli. |
| Aspas do `fputcsv` no cabeçalho (`"Aberto em"`) | Comportamento idêntico ao legado (que já usava `fputcsv`); não é quebra de contrato. |
| `EXPORT_DIR` | Mantido definido por compatibilidade de configuração; deixou de ser usado pelo export. |
