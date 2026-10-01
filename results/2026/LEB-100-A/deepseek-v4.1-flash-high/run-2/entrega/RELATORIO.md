# RELATORIO.md — LEB-100-A

## Resumo

Sistema legado de painel de chamados (PHP + mysqli, front-controller único `index.php`,
biblioteca `lib.php`, config em `config.php`). O código funciona para o "caminho feliz",
mas concentra falhas graves: **injeção de SQL** na busca, **ausência total de controle de
visibilidade** (a regra de negócio central do produto — cliente só vê os próprios chamados —
simplesmente não é aplicada em listagem, detalhe ou exportação), **XSS refletido**, um
**fatal de divisão por zero** quando não há respostas registradas, e **segredos de produção
embutidos** no repositório. Corrigi o conjunto sem trocar a stack, sem renomear arquivos e
preservando as assinaturas e formatos públicos do `manifest.md`. Mantive deliberadamente o
esquema de senha legado (MD5) por incompatibilidade de esquema/contrato — ver *Decisões*.

Os arquivos `code/lib.php`, `code/index.php` e `code/config.php` foram alterados in-place.
`code/schema.sql` e `code/seed.sql` não foram tocados.

---

## Achados (na ordem de prioridade de correção)

### F1 — Injeção de SQL no filtro `busca` (crítica, confiança 98)

- **Onde:** `code/lib.php:81-83` (função `listarChamados`).
- **Mecanismo:** `$busca` vem de `$_GET['busca']` (`index.php:75`) e é concatenado cru na
  cláusula `LIKE`: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`. Não há escaping nem
  prepared statement. Fechando a aspa com um payload como `' OR 1=1 -- ` o atacante controla
  a consulta inteira; `mysqli::query` executa múltiplos efeitos conforme o modo do driver.
- **Impacto:** leitura de toda a base (inclusive hashes de `usuarios`) e, dependendo do
  privilégio do usuário `painel`, alteração/destruição de dados. É explorável por qualquer
  usuário autenticado (inclusive um cliente).
- **O que fiz:** reescrevi `listarChamados` com **prepared statement** e `bind_param`,
  mantendo a assinatura e o comportamento da busca (`LIKE %termo%`), o `ORDER BY criado_em DESC`
  e a chave `tecnico_nome`.

### F2 — IDOR no detalhe: cliente lê chamado de qualquer dono (alta, confiança 95)

- **Onde:** `code/index.php:52-58` / `code/lib.php:98-102` (`verChamado`).
- **Mecanismo:** a rota `index.php?ver=<id>` chama `verChamado($db, (int)$id)` e devolve o
  registro **sem comparar `usuario_id` com o usuário da sessão**. A regra de negócio do
  manifesto ("um cliente só pode ver os chamados que ele mesmo abriu") não é executada em
  lugar nenhum. Basta incrementar o `id` na URL para ler título, status, prioridade e
  descrição de chamados de outros clientes.
- **Impacto:** vazamento de dados de suporte de terceiros para qualquer cliente autenticado.
- **O que fiz:** em `index.php`, após `verChamado`, neguei o acesso quando `papel === 'cliente'`
  e `usuario_id !== $uid`, devolvendo a **mesma** mensagem de "não encontrado" (evita
  oráculo de existência). A assinatura de `verChamado` foi preservada.

### F3 — Listagem ignora a regra de visibilidade (alta, confiança 95)

- **Onde:** `code/index.php:73` / `code/lib.php:78-93`.
- **Mecanismo:** `listarChamados($db, $busca)` monta `SELECT * FROM chamados` sem qualquer
  predicado por dono. A tela principal exibe **todos** os chamados para **todos** os papéis,
  inclusive para clientes. É a mesma regra de negócio de F2, aqui na rota de listagem.
- **Impacto:** um cliente enxerga o nome, título e técnico dos chamados de outros clientes.
- **O que fiz:** adicionei a `listarChamados` um terceiro parâmetro **opcional**
  `?int $usuarioIdVisivel = null`. Com `null` (default) o comportamento é idêntico ao antigo —
  preserva a rotina noturna/relatórios que chamam a função só com `$db` e `$busca`. O
  `index.php` passa `$somenteDono` (o `uid` quando o papel é `cliente`, `null` para técnico).

### F4 — Exportação CSV ignora a regra de visibilidade (alta, confiança 90)

- **Onde:** `code/lib.php:123-150` / `code/index.php:44-47`.
- **Mecanismo:** `exportarCsv($db)` consulta `SELECT * FROM chamados ORDER BY id` sem filtro
  de dono e é disparada pela rota `index.php?export=csv`, acessível a qualquer sessão
  autenticada. Um cliente baixa o cadastro completo de chamados de todos os clientes. O link
  "Exportar CSV" aparece na própria listagem para todos os papéis.
- **Impacto:** exfiltração em massa dos chamados (dados de contato/descrição) por um cliente.
- **O que fiz:** adicionei o parâmetro opcional `?int $usuarioIdVisivel = null` a
  `exportarCsv`. Default `null` = todos (mantém a rotina noturna, que roda por CLI sem
  sessão); o `index.php` passa o dono quando for cliente. O **cabeçalho e o formato do CSV
  foram mantidos exatamente** (`ID,Titulo,Status,Tecnico,Aberto em`, ordenado por `id`).

### F5 — XSS refletido no parâmetro `busca` (alta, confiança 96)

- **Onde:** `code/index.php:79` e `code/index.php:82` (originais).
- **Mecanismo:** `value="' . $busca . '"'` e `<p>Resultados para: ' . $busca . '</p>'`
  imprimem o GET sem `htmlspecialchars`. Um link
  `index.php?busca="><script>...</script>` executa script no navegador da vítima logada.
- **Impacto:** roubo de sessão/ações em nome da vítima; combinado com o painel autenticado, é
  grave.
- **O que fiz:** apliquei `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` na `value` do input
  e no texto de resultados. As demais saídas de dados já usavam `htmlspecialchars`.

### F6 — Divisão por zero em `mediaResposta` (alta, confiança 92)

- **Onde:** `code/lib.php:107-117` (retorno na linha 116).
- **Mecanismo:** se **nenhum** chamado tiver `minutos_resposta` preenchido, o `while` não
  itera, `$qtd` permanece `0` e `return $soma / $qtd;` lança `DivisionByZeroError`. Em PHP 8
  isso é um erro fatal, não um warning: o painel inteiro deixa de renderizar. Acontece em
  banco recém-criado, base sem respostas, ou se todos os chamados tiverem SLA nulo.
- **Impacto:** indisponibilidade total da listagem (DoS por estado de dados legítimo).
- **O que fiz:** troquei o laço por `AVG(minutos_resposta)` no SQL e retornei `0.0` quando o
  resultado é `NULL`. Mantém o retorno `float` e a semântica de média.

### F7 — Segredos de produção embutidos no código (alta, confiança 90)

- **Onde:** `code/config.php:12` e `code/config.php:15` (originais).
- **Mecanismo:** a senha do banco de produção (`'N3tX@2013!prod'`) e a chave da API SMTP
  (`'netx-smtp-...'`) estão hardcoded como fallback/default no repositório. Quem obtém o
  código (vazamento, cópia, histórico) tem credencial válida de produção.
- **Impacto:** comprometimento do banco e do canal transacional de e-mail.
- **O que fiz:** removi os literais; as constantes passaram a ler **apenas** `getenv()` com
  fallback vazio. Mantive os nomes/`define` para não quebrar `index.php`. Se o ambiente não
  fornecer a variável, a conexão falha — o que é preferível a vazar segredo.

### F8 — Senhas com MD5 sem salt (alta, confiança 97) — **não corrigido**

- **Onde:** `code/lib.php:15` (`autenticar`) e `code/schema.sql:7`.
- **Mecanismo:** `md5($senha)` sem salt, armazenado em `CHAR(32)`. MD5 é rápido e reversível
  por rainbow tables; qualquer vazamento do `usuarios.senha` (facilitado por F1) expõe senhas
  em minutos.
- **Impacto:** comprometimento de credenciais dos usuários.
- **O que fiz:** **reportei, não alterei.** Trocar para `password_hash`/bcrypt exige mudar o
  tipo/ tamanho da coluna `senha` (`CHAR(32)` não comporta 60 chars), migrar todos os hashes e
  quebrar qualquer consumidor que gere/valide hashes MD5 (incluindo `seed.sql` e a suposição
  documentada no repositório). É mudança de contrato de dados, com risco de bloquear logins
  em produção. Deixei como recomendação prioritária de roadmap.

### F9 — Fixação de sessão no login (média, confiança 75)

- **Onde:** `code/index.php:20-29` (originais).
- **Mecanismo:** a sessão é criada com `session_start()` e o id **não** é regenerado após
  autenticar; só se gravam `uid`/`papel`. Um atacante que fixe previamente o `PHPSESSID` da
  vítima passa a usar a sessão autenticada dela após o login.
- **Impacto:** sequestro de sessão autenticada.
- **O que fiz:** adicionei `session_regenerate_id(true)` imediatamente após autenticar com
  sucesso e antes de gravar os dados de sessão.

### F10 — Exportação grava em arquivo fixo compartilhado (média, confiança 88)

- **Onde:** `code/lib.php:125-150` (originais).
- **Mecanismo:** `exportarCsv` grava sempre em `EXPORT_DIR . '/chamados.csv'` e depois faz
  `readfile`. Duas requisições concorrentes escrevem no mesmo caminho: uma pode servir o
  arquivo parcialmente escrito ou sobrescrito pela outra. Além disso, o arquivo persistente
  expõe os dados no diretório web/servidor.
- **Impacto:** CSV corrompido/incompleto ou trocado entre usuários; resíduo de dados em disco.
- **O que fiz:** passou a escrever direto em `php://output` (stream), eliminando o arquivo
  intermediário compartilhado. O formato e os cabeçalhos HTTP são idênticos. `EXPORT_DIR`
  continua definido em `config.php` para não quebrar outros consumidores.

### F11 — SQL concatenado em `tecnicoNome` e `verChamado` (baixa, confiança 70)

- **Onde:** `code/lib.php:69` e `code/lib.php:100` (originais).
- **Mecanismo:** `tecnicoNome` concatena `$tecnicoId` e `verChamado` concatena `$id`. Hoje os
  chamadores já fazem cast `(int)` e a assinatura de `verChamado` é `int`, então não consegui
  construir exploração real a partir da superfície atual. Ainda assim é um padrão frágil:
  qualquer novo chamador que passe string reintroduz injeção.
- **Impacto:** latente; defesa em profundidade.
- **O que fiz:** converti ambos para prepared statements com `bind_param`. `tecnicoNome` não
  está no manifesto (função interna), e `verChamado` manteve a assinatura.

### F12 — `SELECT *` + N+1 e média calculada em PHP (baixa, confiança 85)

- **Onde:** `code/lib.php:78-93` e `code/lib.php:107-117` (originais).
- **Mecanismo:** a listagem faz uma consulta por chamado para buscar o nome do técnico
  (N+1) e `mediaResposta` traz todas as linhas para somar em PHP, em vez de agregar no banco.
- **Impacto:** degradação linear com o volume de chamados.
- **O que fiz:** substituí o N+1 por um `LEFT JOIN usuarios` que devolve
  `COALESCE(u.nome,'-') AS tecnico_nome` (preserva o `'-'` do comportamento antigo) e a média
  por `AVG()` no SQL. A ordenação e as chaves retornadas foram preservadas.

---

## Decisões — o que deliberadamente **não** mudei

- **Esquema de senha MD5 (F8):** não migrei para `password_hash`. Exigiria alterar a coluna
  `senha` (`CHAR(32)`), migrar hashes e quebrar consumidores que assumem MD5 (incluindo
  `seed.sql`). Mudança de contrato de dados em produção, fora do escopo seguro desta tarefa;
  fica registrada como prioridade máxima de evolução.
- **Stack e camada de dados:** mantive **mysqli** e os nomes/assinaturas públicas do
  `manifest.md` (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`,
  `verChamado`, `mediaResposta`, `exportarCsv`). Não troquei ORM nem PDO.
- **Assinaturas públicas:** em `listarChamados` e `exportarCsv` adicionei apenas um **terceiro/
  segundo parâmetro opcional** com default `null`, que reproduz exatamente o comportamento
  antigo. Chamadas existentes dos consumidores internos (rotina noturna, relatório gerencial)
  continuam válidas sem alteração.
- **`formatarStatus` / `rotuloPrioridade`:** não toquei. Os rótulos (`Aberto`,
  `Em atendimento`, `Resolvido`, e as faixas de prioridade) são contrato de valor do relatório
  gerencial. O `else` de `formatarStatus` que trata qualquer valor não-1/2 como "Resolvido" foi
  mantido por compatibilidade, embora seja defensável validar a faixa 1–3.
- **`schema.sql` / `seed.sql`:** intactos. A tarefa proíbe renomear/mover arquivos e qualquer
  ajuste de esquema impactaria consumidores; não havia correção que exigisse migração.
- **Estrutura HTML / rotas / formato CSV:** preservados literalmente: `id="tabela-chamados"`,
  ordem das colunas, links `index.php?ver=<id>`, parâmetros `busca`/`ver`/`export` e o
  cabeçalho exato do CSV.
- **CSRF e rate limiting no login:** não implementei token CSRF nem bloqueio por tentativas.
  A única ação mutável é o próprio login (baixo impacto de CSRF clássico) e não há
  dependência disponível para throttling; adicionar isso mudaria o formulário/HTML. Registrado
  como recomendação, não como correção de risco imediato.
- **Tratamento de erros/logs:** não introduzi logging nem mensagens detalhadas de erro para não
  expor informação e para não alterar o comportamento de saída. A checagem de `connect_errno`
  e as mensagens originais foram mantidas.
- **`EXPORT_DIR`:** mantido em `config.php` mesmo sem uso direto após a correção de F10, para
  não quebrar outros scripts que possam referenciar a constante.
