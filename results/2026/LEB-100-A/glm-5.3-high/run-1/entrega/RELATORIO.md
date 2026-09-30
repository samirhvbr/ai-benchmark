# RELATORIO.md — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP 8 + mysqli com duas classes de usuário
(cliente e técnico). A regra de negócio declarada no manifesto — **cliente só vê os
próprios chamados; técnico vê todos** — **não está implementada em lugar nenhum**:
qualquer cliente autenticado lista, abre e exporta **todos** os chamados de todos os
clientes. Somam-se a isso uma **injeção de SQL explorável** no parâmetro `busca`
(confirmed via UNION extraindo a tabela `usuarios`), **XSS refletido** no mesmo
parâmetro, segredos de produção versionados em `config.php`, senhas em MD5 sem salt,
uma divisão por zero que derruba a página inicial com HTTP 500 e um N+1 de consultas
na listagem.

Todos os achados foram **reproduzidos em ambiente real** (MariaDB 11 + PHP 8.4 +
`php -S`, banco subido de `schema.sql`/`seed.sql`) antes e depois da correção; a
seção de testes no fim resume a verificação. As linhas citadas nos achados seguem a
**numeração original** dos arquivos recebidos.

Corrigi 10 dos 14 achados. O que ficou de fora (MD5, injeção de fórmula em CSV e
dois pontos menores) tem justificativa explícita na seção **Decisões** — em geral
porque a correção exigiria migração de esquema ou mudaria conteúdo consumido por
integrações externas.

---

## 2. Achados (em ordem de prioridade de correção)

### F1 — Regra de visibilidade não aplicada: cliente vê chamados de outros clientes (IDOR)

- **Onde:** `code/index.php:52-74` — bloco `?ver=` (linhas 52–58) e listagem (linhas 72–74).
- **Mecanismo:** `listarChamados()` retorna todos os chamados do banco e `verChamado()`
  carrega qualquer id; `index.php` tem `$uid` e `$papel` disponíveis (linhas 38–39) e
  **nunca os consulta**. Nenhuma camada — nem a web, nem a lib — filtra por
  `usuario_id`. Logado como `ana`, a listagem mostra 101–105 (dois deles do `bruno`) e
  `?ver=103` abre o chamado alheio com descrição completa. Reproduzido no baseline.
- **Impacto:** qualquer cliente do ISP lê títulos, descrições (que costumam conter
  endereço, CPF, dados de fatura) e histórico de outros clientes. Viola diretamente a
  regra de negócio do manifesto.
- **Severidade:** crítica · **Confiança:** 98 (reproduzido).
- **O que fiz:** aplicação da regra na camada web, onde a sessão existe:
  - listagem: após `listarChamados()`, se `$papel === 'cliente'`, mantém apenas os
    chamados com `usuario_id == $uid` (`index.php` novo, linhas 80–90);
  - detalhe: `?ver=<id>` de outro cliente devolve a **mesma** mensagem "Chamado nao
    encontrado." de um id inexistente, sem vazar a existência do registro.
  - Não mudei as funções de `lib.php` (`listarChamados`/`verChamado` continuam
    retornando tudo) porque elas são consumidas por scripts internos do ISP sem
    contexto de sessão — a decisão de visibilidade fica no ponto de entrada web.
    O caso do CSV, que emite saída direto da lib, está no F4.

### F2 — Injeção de SQL no parâmetro `busca`

- **Onde:** `code/lib.php:82-85` — `listarChamados()` monta
  `" WHERE titulo LIKE '%" . $busca . "%'"` e executa com `$db->query()`.
- **Mecanismo:** o valor chega bruto de `$_GET['busca']` (`index.php:72`) e é
  concatenado. Com `busca=x' UNION SELECT 1,login,senha,'v','v',1,1,1,'2026-01-01'
  FROM usuarios -- ` a consulta retorna as linhas da tabela `usuarios` renderizadas
  na tabela HTML — **reproduzido no baseline** (nomes e hashes visíveis). `busca=' OR
  1=1 -- ` derruba o filtro. Qualquer usuário autenticado extrai as senhas MD5 de
  todos
  (combinando com F5) e, via UNION/read de `information_schema`, mapeia o banco todo.
- **Impacto:** vazamento completo do banco, incluindo hashes de senhas; base para
  escalada quando combinado com MD5 sem salt (F5).
- **Severidade:** crítica · **Confiança:** 98 (reproduzido).
- **O que fiz:** `listarChamados()` agora usa `prepare`/`bind_param` com o LIKE como
  parâmetro (`lib.php` novo, linhas 86–99). A busca legítima continua idêntica
  (`%termo%`); payloads são tratados como texto literal (verificado: UNION e OR 1=1
  retornam zero resultados, busca "Lentidao" continua achando o chamado 102).

### F3 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79` (`value="' . $busca . '"` no input) e `:82`
  (`Resultados para: ' . $busca`).
- **Mecanismo:** o mesmo valor de `$_GET['busca']` é ecoado duas vezes sem
  `htmlspecialchars`. Com `busca=<img src=x onerror=alert(1)>` o HTML injetado chega
  crú à resposta — **reproduzido no baseline**. O link com o payload pode ser enviado
  a um técnico logado; o cookie de sessão não tinha `HttpOnly` (F10), permitindo
  roubo de sessão.
- **Impacto:** execução de script no navegador da vítima com a sessão dela;
  sequestro de sessão de técnico.
- **Severidade:** alta · **Confiança:** 95 (reproduzido).
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos de eco
  (`index.php` novo, linhas 93 e 96). Verificado: payload é renderizado como texto.

### F4 — Exportação CSV entrega todos os chamados a clientes

- **Onde:** `code/lib.php:123-150` (`exportarCsv`), acionada por `index.php:44-47`.
- **Mecanismo:** `exportarCsv()` faz `SELECT * FROM chamados ORDER BY id` sem qualquer
  filtro de dono. Corrigir só a listagem/detalhe (F1) deixaria o buraco aberto: o link
  "Exportar CSV" está na própria tela e entregaria em arquivo tudo que a listagem
  agora esconde. Reproduzido no baseline: o CSV da `ana` incluía os chamados 103/104
  do `bruno`.
- **Impacto:** extração em massa de dados de todos os clientes — o mesmo vazamento do
  F1, em formato pronto para máquina.
- **Severidade:** alta · **Confiança:** 90.
- **O que fiz:** `exportarCsv()` agora consulta `$_SESSION`: se a sessão atual é de
  `cliente`, o SELECT filtra `WHERE c.usuario_id = ?`; sem sessão (rotina noturna de
  exportação, integração de faturamento, que rodam via CLI) o comportamento é o
  anterior — todos os chamados (`lib.php` novo, linhas 148–161). Cabeçalho, ordem por
  `id` e formato das células são byte-a-byte idênticos ao original (verificado por
  `diff` com o baseline para sessão de técnico). A decisão de filtrar é a leitura do
  manifesto: a regra de visibilidade é obrigatória e vale para qualquer forma de
  "ver" chamados; o formato do CSV (cabeçalho exato, uma linha por chamado visível,
  ordenado por id) está preservado.

### F5 — Senhas armazenadas em MD5 sem salt

- **Onde:** `code/lib.php:15` (`$hash = md5($senha)`), `code/schema.sql:7`
  (`senha CHAR(32)`), senhas fracas no seed.
- **Mecanismo:** MD5 é rápido e sem salt: hashes idênticos para senhas iguais (o seed
  mostra `ana` e `bruno` com o mesmo hash) caem em rainbow tables; combinado com F2,
  um atacante extrai a tabela inteira e quebra offline em segundos.
- **Impacto:** comprometimento de contas (incluindo técnicos) a partir de qualquer
  vazamento do banco.
- **Severidade:** alta · **Confiança:** 95.
- **O que fiz:** **nada nesta entrega** — ver Decisões. Exige migração de esquema
  (coluna `CHAR(32)` → `VARCHAR(255)`) e rehash coordenado; fazer isso inline aqui
  ou quebraria o login de todos os usuários existentes (contrato do seed) ou deixaria
  as duas metades inconsistentes. Caminho recomendado documentado no relatório:
  coluna nova com `password_hash`, verificação com fallback MD5→rehash transparente
  no login, drop da coluna legada após janela de migração.

### F6 — Segredos de produção versionados em `config.php`

- **Onde:** `code/config.php:12` (fallback `N3tX@2013!prod` da senha do banco) e
  `:15` (`SMTP_API_KEY` com valor real embutido).
- **Mecanismo:** qualquer pessoa com acesso ao repositório/pacote obtém a senha do
  banco de produção e a chave da central de e-mail transacional. O próprio fallback
  existe para "funcionar sem ambiente", o que garante que o segredo circule em cada
  deploy e backup de código.
- **Impacto:** acesso direto ao banco de produção e uso da chave SMTP para envio
  abusivo/phishing em nome do ISP.
- **Severidade:** alta · **Confiança:** 90.
- **O que fiz:** ambos os valores passam a vir exclusivamente de variáveis de
  ambiente, com string vazia como fallback (`config.php` novo, linhas 11–15). Sem
  `DB_PASS` no ambiente a conexão falha com a mensagem existente ("Falha ao conectar
  ao banco."), que é o comportamento correto para credencial ausente. Os testes
  rodam exatamente assim (`DB_PASS=test123` no ambiente). Recomendação operacional:
  rotacionar a senha e a chave SMTP, pois já estiveram expostas.

### F7 — Divisão por zero em `mediaResposta` derruba a página inicial

- **Onde:** `code/lib.php:116` — `return $soma / $qtd;`.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` preenchido (banco novo ou
  tabela zerada), `$qtd` é 0 e PHP 8 lança `DivisionByZeroError` → HTTP 500.
  **Reproduzido no baseline**: `UPDATE chamados SET minutos_resposta = NULL` +
  reload → 500 com erro em `lib.php:116`. A listagem e o detalhe continuam
  funcionando, mas a rota principal morre.
- **Impacto:** indisponibilidade total do painel para um estado de dados perfeitamente
  possível (todos os chamados aguardando primeira resposta).
- **Severidade:** média · **Confiança:** 95 (reproduzido).
- **O que fiz:** guarda `if ($qtd === 0) return 0.0;` (`lib.php` novo, linhas
  136–138). Verificado: a página devolve 200 com "0 min"; com dados, a média segue
  idêntica (25,67 → "26 min").

### F8 — Exportação CSV via arquivo em caminho fixo (corrida e falha silenciosa)

- **Onde:** `code/lib.php:125-126` — grava em `EXPORT_DIR . '/chamados.csv'` e depois
  faz `readfile`.
- **Mecanismo:** (a) dois exports simultâneos escrevem o **mesmo** arquivo: um
  `fopen('w')` trunca o arquivo que o outro está lendo/gravando → CSV truncado ou
  misturado entregue ao usuário; (b) se `EXPORT_DIR` não existe, `fopen` falha e a
  função retorna **sem saída nem header** — o usuário recebe uma página vazia com
  HTTP 200, sem diagnóstico; (c) o arquivo fica no disco com dados de todos os
  clientes, legível por qualquer processo/usuário com acesso ao diretório web.
- **Impacto:** CSV corrompido para integrações, export "mudo" em ambientes sem o
  diretório, cópia residual de dados sensíveis em disco.
- **Severidade:** média · **Confiança:** 85.
- **O que fiz:** a função escreve direto em `php://output` com os headers enviados
  antes da primeira linha (`lib.php` novo, linhas 173–190) — sem arquivo, sem
  corrida, sem dependência de diretório. O manifesto define `exportarCsv` como
  "escreve o CSV na saída", que é exatamente o novo comportamento; o arquivo em
  disco era detalhe de implementação. `EXPORT_DIR` continua definido em `config.php`
  para não quebrar quem usa a constante (ver Decisões).

### F9 — N+1 consultas na listagem (`listarChamados`)

- **Onde:** `code/lib.php:88-91` — para cada chamado, `tecnicoNome()` (linha 69) faz
  um `SELECT` adicional.
- **Mecanismo:** a listagem executa 1 + N consultas (uma por linha da tabela). Com o
  `chamados` de um ISP real (dezenas de milhares de linhas), cada reload do painel
  dispara dezenas de milhares de queries round-trip ao MySQL, além de abrir caminho
  para o padrão se replicar em outros relatórios internos.
- **Impacto:** degradação progressiva da página principal conforme o volume cresce;
  carga evitável no banco.
- **Severidade:** média · **Confiança:** 95.
- **O que fiz:** `listarChamados()` e `exportarCsv()` passaram a resolver o nome do
  técnico com `LEFT JOIN usuarios` na própria consulta (`lib.php` novo, linhas 82–83
  e 155–157). A chave `tecnico_nome` continua presente em cada item, com `'-'` para
  técnico ausente — mesmo valor de antes (verificado contra o CSV do baseline,
  byte-a-byte). `tecnicoNome()` foi mantida como função (não está no manifesto, mas
  remover seria risco gratuito) e corrigida no F11.

### F10 — Sessão: fixação no login e cookie sem `HttpOnly`/`SameSite`

- **Onde:** `code/index.php:15` (`session_start()` sem parâmetros) e `:23-28`
  (login sem `session_regenerate_id`).
- **Mecanismo:** o cookie `PHPSESSID` era emitido sem `HttpOnly` (script injetado via
  F3 lia o cookie) e sem `SameSite`. No login, o id de sessão não é rotacionado, então
  um id fixado antes do login (link malicioso, acesso compartilhado) sobrevive
  autenticado — sessão fixação clássica.
- **Impacto:** roubo/sequestro de sessão encadeado com o XSS (F3).
- **Severidade:** média · **Confiança:** 85.
- **O que fiz:** `session_set_cookie_params(['httponly' => true, 'samesite' =>
  'Lax'])` antes do `session_start()` e `session_regenerate_id(true)` no login
  bem-sucedido (`index.php` novo, linhas 15–16 e 29). Verificado no header
  `Set-Cookie`. `secure` não foi ativado — ver Decisões.

### F11 — SQL concatenado em `tecnicoNome` e `verChamado`

- **Onde:** `code/lib.php:69` (`'... WHERE id = ' . $tecnicoId`) e `:100`
  (`'... WHERE id = ' . $id`).
- **Mecanismo:** hoje não é explorável — os parâmetros são tipados `?int`/`int` e o
  único call site externo já faz `(int) $_GET['ver']` — mas o padrão é uma bomba
  relógio: qualquer evolução que passe a receber string (um filtro novo, um script
  legado) vira injeção, e o código continua ensinando a prática ruim a quem o ler.
- **Impacto:** nenhum hoje; risco de regressão grave amanhã.
- **Severidade:** baixa · **Confiança:** 80.
- **O que fiz:** ambas converteram-se para prepared statements (`lib.php` novo,
  linhas 64–74 e 116–122). Comportamento idêntico (verificado: `tecnicoNome(3)` →
  "Carla Tecnica", `null` → "-", inexistente → "-").

### F12 — Injeção de fórmula em CSV (Excel/Calc)

- **Onde:** `code/lib.php:138` — `fputcsv(... $c['titulo'] ...)` escreve o título
  vindo do banco sem neutralizar prefixos de fórmula.
- **Mecanismo:** um título de chamado que comece com `=`, `+`, `-` ou `@` vira
  fórmula ao abrir o CSV no Excel/LibreOffice (ex.: `=HYPERLINK(...)` ou
  `=cmd|'/c ...'`), executando conteúdo na estação do funcionário que abre o
  export. O título é texto livre criado por clientes.
- **Impacto:** execução de fórmula/host na máquina de quem abre o arquivo
  (funcionário do ISP ou o próprio fluxo de faturamento).
- **Severidade:** baixa · **Confiança:** 70.
- **O que fiz:** **nada nesta entrega** — ver Decisões. Neutralizar (prefixar `'`
  ou espaço) altera o conteúdo da coluna `Titulo` exatamente como consumido pela
  integração de faturamento que o manifesto cita; a mudança precisa de acordo com os
  consumidores. Registrado para decisão coordenada.

### F13 — `formatarStatus` rotula status desconhecido como "Resolvido"

- **Onde:** `code/lib.php:26-35` — o `else` final devolve `'Resolvido'` para
  qualquer valor que não seja 1 ou 2.
- **Mecanismo:** o contrato define rótulos apenas para 1, 2 e 3. Se algum dia entrar
  status 4 (ou um TINYINT corrompido), o painel e o CSV o exibem como "Resolvido" —
  e o relatório gerencial, que casa por texto, contabiliza errado. Hoje o seed só usa
  1–3, então não há sintoma ativo.
- **Impacto:** classificação incorreta silenciosa em cenário de dados fora do
  esperado.
- **Severidade:** baixa · **Confiança:** 55.
- **O que fiz:** nada — o contrato só especifica 1/2/3 e qualquer fallback novo
  (ex.: string vazia) pode quebrar a correspondência por texto do relatório
  gerencial. Registrado como risco conhecido.

### F14 — Parâmetros de request em array causam HTTP 500

- **Onde:** `code/index.php:22` (`autenticar($db, $_POST['login'], ...)`) e `:72`
  (`$busca = $_GET['busca'] ?? ''` passado a `listarChamados(string)`).
- **Mecanismo:** `?busca[]=x` ou `POST login[]=x` fazem um `array` chegar a
  parâmetro tipado `string` → `TypeError` fatal. **Reproduzido no baseline**: ambos
  retornam 500 no código original (a página vira um erro crú, com caminho de arquivo
  no display_errors do servidor de teste).
- **Impacto:** negação de serviço trivial de página única e vazamento de detalhes de
  erro, sem qualquer privilégio.
- **Severidade:** baixa · **Confiança:** 95 (reproduzido).
- **O que fiz:** validação `is_string()` nos pontos de entrada do request
  (`index.php` novo, linhas 23–26 e 81); valores malformados são tratados como busca
  vazia/login inválido → 200. Verificado.

---

## 3. Decisões — o que deliberadamente NÃO mudei

1. **MD5 sem salt (F5).** Trocar para `password_hash` exige: nova coluna, dupla
   verificação durante a migração, rehash transparente no login e coordenação com
   qualquer consumidor que leia `usuarios.senha` (a "integração de faturamento" do
   manifesto pode estar entre eles). Fazer metade disso aqui quebraria o login das
   contas existentes — inclusive o contrato dos dados de teste (`ana`/`senha123`
   precisa autenticar). É a correção mais importante pendente, mas é uma migração,
   não um patch; deixei o caminho descrito no achado.
2. **Injeção de fórmula no CSV (F12).** Escapar os prefixos muda o conteúdo da
   coluna `Titulo` entregue à integração de faturamento. O manifesto manda preservar
   formatos; a correção correta é acordar o escaping com os consumidores (ou migrar
   o export para um formato que distinga texto). Registrei e não arrisquei.
3. **`mediaResposta` segue calculando a média global** inclusive para clientes. É
   um agregado (indicador de SLA do painel), não exposição de chamado individual;
   filtrá-lo por papel exigiria nova função ou quebraria a assinatura pública.
4. **CSRF token e rate limiting no login:** não adicionei. O formulário de login não
   tem estado sensível pré-sessão, não há outras ações POST no sistema e o manifesto
   descreve os parâmetros GET como contrato. `SameSite=Lax` (F10) já corta o vetor
   CSRF relevante para as rotas existentes. Recomendo token + limite de tentativas
   numa evolução maior.
5. **Cookie sem flag `secure`:** o painel pode ser servido em HTTP interno (o
   próprio `EXPORT_DIR` sugere infraestrutura própria); forçar `secure` logaria
   todos esses usuários. A ativação deve acompanhar a garantia de HTTPS ponta a
   ponta.
6. **`EXPORT_DIR` mantido em `config.php`:** `exportarCsv` não usa mais o diretório,
   mas a constante pode ser referenciada por outros scripts do ISP que incluem o
   config. Remover seria risco sem benefício.
7. **Curingas de LIKE (`%`, `_`) continuam operando na busca.** Escapá-los mudaria a
   semântica de busca que usuários podem estar usando (ex.: buscar "100%" e receber
   resultados). A injeção foi resolvida no parâmetro, que é o problema real.
8. **`formatarStatus` (F13) mantido como está**, pelo contrato textual do relatório
   gerencial.
9. **Nenhuma função, arquivo ou dependência foi criada/renomeada/removida**; a stack
   continua PHP + mysqli, `tecnicoNome` (interna) foi preservada. O diff total é de
   ~112 linhas em 3 arquivos.

---

## 4. Verificação (resumo dos testes executados)

Ambiente: MariaDB 11.8 + PHP 8.4 (`php -S`), banco recriado de `schema.sql` +
`seed.sql`. Baseline capturado **antes** das mudanças; todos os cenários re-executados
**depois**:

| Cenário | Original | Corrigido |
| --- | --- | --- |
| `ana` (cliente) na listagem | via 101–105 | vê só 101, 102, 105 |
| `ana` em `?ver=103` (do bruno) | abre o chamado | "Chamado nao encontrado." |
| `ana` em `?ver=101` (dela) | abre | abre (inalterado) |
| `ana` em `?export=csv` | todos os 5 | só 101/102/105, mesmo cabeçalho |
| `carla` (técnica) listagem/ver/csv | tudo | tudo (inalterado; CSV byte-a-byte idêntico ao baseline) |
| `busca=' UNION SELECT ... FROM usuarios --` | vaza `usuarios` | 0 resultados |
| `busca=<img src=x onerror=...>` | HTML injetado | escapado como texto |
| `busca=Lentidao` (legítima) | 102 | 102 |
| `mediaResposta` sem dados | HTTP 500 | 200, "0 min" |
| `mediaResposta` com dados | 26 min | 26 min |
| `busca[]=x` / `login[]=x` | HTTP 500 | HTTP 200 |
| CSV via CLI sem sessão (rotina noturna) | todos | todos (inalterado) |
| Header `Set-Cookie` | sem flags | `HttpOnly; SameSite=Lax` |
| Estrutura `id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico, links `?ver=` | — | preservada |
| `autenticar`/`formatarStatus`/`rotuloPrioridade`/`tecnicoNome` | — | retornos idênticos ao contrato |
