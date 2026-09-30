# RELATORIO.md — LEB-100-A

## Resumo

O Painel de Chamados (NetX ISP) é uma aplicação PHP/mysqli pequena e legada, com
autenticação por sessão, listagem/detalhe de chamados, busca por título e exportação
CSV. O código em produção apresentava **três falhas críticas de segurança já
exploráveis no fluxo normal**: injeção de SQL na busca, XSS refletido via parâmetro
`busca` e quebra total da regra de visibilidade (clientes conseguiam ver/exportar
chamados de outros clientes e de toda a base). Havia ainda segredos de produção
embutidos no fonte, divisão por zero num indicador de SLA e consultas montadas por
concatenação. Corrigi todos os itens exploráveis preservando a superfície pública do
`manifest.md` (assinaturas, rotas, formato CSV, rótulos e regras de negócio). O único
achado que **não** alterei foi o hashing de senha em MD5 — por exigir migração de
schema/dados e quebrar a autenticação existente, foge do escopo de "evoluir, não
reescrever".

Os achados abaixo estão ordenados por prioridade de correção. Os IDs (`F1`…`F7`)
correspondem exatamente a `achados.json`.

---

## F1 — Injeção de SQL na busca de chamados

- **Onde:** `code/lib.php:82` (função `listarChamados`), linha original
  `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`.
- **Mecanismo:** `$busca` vem direto de `$_GET['busca']` (`index.php:73`) e é
  concatenado na SQL sem escape nem placeholder. Um atacante enviando
  `busca=' OR '1'='1` monta `WHERE titulo LIKE '%' OR '1'='1%'`, forçando o
  `LIKE` a casar com todas as linhas (e permitindo `UNION`/subconsultas para
  ler tabelas arbitrárias, já que o usuário do banco tem acesso de leitura
  geral). É injeção de SQL clássica e trivial.
- **Impacto:** vazamento de todos os chamados (incluindo de outros clientes e
  descrições), e potencial leitura de outras tabelas via `UNION SELECT`.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que fiz:** reescrevi `listarChamados` com `prepare` + `bind_param('s',
  $padrao)` usando `titulo LIKE ?` e o padrão `'%'.$busca.'%'` como valor
  vinculado. A assinatura `listarChamados(mysqli $db, string $busca = ''): array`
  e o retorno (lista com `tecnico_nome`) foram preservados.

## F2 — XSS refletido via parâmetro `busca`

- **Onde:** `code/index.php:79` (`value="' . $busca . '"`) e `code/index.php:82`
  (`'<p>Resultados para: ' . $busca . '</p>'`).
- **Mecanismo:** `$busca` é impressa no atributo `value` do `<input>` e no corpo
  da página sem `htmlspecialchars`. Um link como
  `index.php?busca="><script>...` injeta marcação/JS executado no navegador de
  quem clicar (cliente autenticado). No `value` basta `"` para fechar o atributo;
  no corpo, `<script>` roda direto.
- **Impacto:** roubo de sessão (o cookie de sessão não tem flag `HttpOnly`),
  execução de ações em nome do usuário, defacement. Vetor realista via
  phishing/mensagem.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que fiz:** apliquei `htmlspecialchars($busca, ENT_QUOTES)` em ambos os
  pontos. Preservei o contrato HTML da listagem (`id="tabela-chamados"`,
  colunas ID/Titulo/Status/Prioridade/Tecnico, link `index.php?ver=<id>`).

## F3 — Quebra da regra de visibilidade (cliente vê todos os chamados)

- **Onde:** `code/index.php:73` (listagem), `code/index.php:53` (detalhe) e
  `code/index.php:45` (exportação CSV) — todos chamam `listarChamados`/`verChamado`/
  `exportarCsv` sem qualquer filtro por `papel` ou `usuario_id`.
- **Mecanismo:** o `manifest.md` (§Regra de negócio) estabelece que **um cliente só
  pode ver os chamados que ele mesmo abriu** e que um técnico vê qualquer um. O
  código ignora `$_SESSION['papel']`/`$_SESSION['uid']` após o login: qualquer
  cliente autenticado recebe a listagem completa, pode abrir
  `index.php?ver=<id_alheio>` e baixar `index.php?export=csv` com todos os
  chamados da base. É tanto violação de contrato (regra de negócio obrigatória)
  quanto exposição de dados sensíveis de terceiros.
- **Impacto:** cliente A lê chamados/descrições de cliente B e todo o histórico
  operacional; vazamento massivo via CSV. Viola explicitamente o manifesto.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que fiz:** preservei as assinaturas públicas (`listarChamados`,
  `verChamado`, `exportarCsv` continuam retornando todos os chamados — o que é
  necessário para a rotina noturna e o relatório gerencial, que rodam em contexto
  técnico) e passei a filtrar **na camada web** (`index.php`):
  - **Listagem:** após `listarChamados`, se `$papel === 'cliente'`, filtro o array
    por `usuario_id === $uid`.
  - **Detalhe:** após `verChamado`, se cliente e `usuario_id !== $uid`, trato como
    "não encontrado" (não revelo existência).
  - **CSV:** técnico continua chamando `exportarCsv($db)` (todos, no formato
    exato do contrato); cliente recebe um CSV no **mesmo formato** (mesmo
    cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, ordenado por `id` crescente,
    coluna Status via `formatarStatus`) contendo apenas seus próprios chamados,
    escrito direto em `php://output`.
- A regra pretendida do produto foi restaurada sem alterar qualquer assinatura,
  rota, parâmetro GET ou formato de saída.

## F4 — Segredos de produção embutidos no fonte

- **Onde:** `code/config.php:12` (`DB_PASS` com fallback `'N3tX@2013!prod'`) e
  `code/config.php:15` (`SMTP_API_KEY` com valor fixo `'netx-smtp-9f83e2c1a7b64d05'`).
- **Mecanismo:** o controle de versão deste pacote carrega uma senha real de banco
  de produção e uma chave de API de SMTP como literais. Quem tiver acesso ao
  repositório (ou a um dump do filesystem) obtém credenciais válidas diretamente,
  sem nenhum esforço. O `SMTP_API_KEY` nem é usado pelo código do painel, mas
  permanece exposto.
- **Impacto:** comprometimento do banco e do serviço de e-mail transacional do ISP
  a partir do vazamento do fonte.
- **Severidade:** alta.
- **Confiança:** 95.
- **O que fiz:** ambas as constantes passam a vir exclusivamente do ambiente
  (`getenv(...) ?: ''`). Removi os literais. A constante `SMTP_API_KEY` foi
  mantida (só que vazia por padrão) para não quebrar scripts externos que por
 ventura a leiam — a correção é no valor, não no símbolo.

## F5 — Divisão por zero no indicador de SLA (`mediaResposta`)

- **Onde:** `code/lib.php:116` (original), `return $soma / $qtd;`.
- **Mecanismo:** se nenhum chamado tiver `minutos_resposta` preenchido (banco
  recém-instalado, ou todos os chamados ainda sem 1ª resposta), `$qtd` permanece
  `0` e `$soma / 0` lança um `DivisionByZeroError` fatal no PHP 8. A tela de
  listagem sempre chama `mediaResposta` (`index.php:74`), então a home do painel
  passa a ser uma tela branca para todo usuário.
- **Impacto:** indisponibilidade total da listagem em determinado estado de dados.
- **Severidade:** média.
- **Confiança:** 95.
- **O que fiz:** adicionei `if ($qtd === 0) { return 0.0; }` antes da divisão. A
  assinatura `mediaResposta(mysqli $db): float` e o tipo de retorno foram
  preservados; `0.0` é um valor coerente para "sem dados".

## F6 — Consultas montadas por concatenação (`tecnicoNome`, `verChamado`)

- **Onde:** `code/lib.php:69` (`'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`)
  e `code/lib.php:100` (`'SELECT * FROM chamados WHERE id = ' . $id`).
- **Mecanismo:** embora os parâmetros sejam tipados (`?int`/`int`) e portanto não
  sejam hoje injetáveis, o padrão de concat SQL é frágil: uma futura chamada que
  passe a receber dado não-tipado, ou uma "correção" relaxando o tipo, abre
  injeção. É defesa em profundidade e consistência com `autenticar` (que já usa
  `prepare`).
- **Impacto:** baixo hoje; eleva o risco de regressão futura.
- **Severidade:** baixa.
- **Confiança:** 80.
- **O que fiz:** converti ambas para `prepare` + `bind_param('i', ...)`.
  Comportamento, assinaturas e retorno são idênticos.

## F7 — Senhas armazenadas com MD5

- **Onde:** `code/lib.php:15` (`$hash = md5($senha)`), `code/schema.sql:7`
  (`senha CHAR(32) ... -- hash md5 (legado)`), `code/seed.sql` (`MD5('senha123')`).
- **Mecanismo:** MD5 é criptograficamente quebrado e sem sal; um dump da tabela
  `usuarios` permite quebrar todas as senhas em segundos com rainbow tables/GPU.
  Em produção desde 2013, é o ponto de maior risco caso o banco vaze.
- **Impacto:** comprometimento de todas as contas (e reuso de senha em outros
  sistemas) a partir de um vazamento da tabela.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que fiz:** **não alterei** — ver seção **Decisões**.

---

## Decisões (o que NÃO mudei e por quê)

- **MD5 → bcrypt/`password_hash` (F7).** Migrar exigiria: (a) alterar a coluna
  `senha` de `CHAR(32)` para `VARCHAR(255)` em `schema.sql`, quebrando o seed e a
  forma como a base existente foi populada; (b) um passo de rehash transparente no
  `autenticar` (re-hash no login bem-sucedido) e/ou um script de migração; (c)
  atualizar `seed.sql` para `password_hash`. Tudo isso muda a camada de dados e o
  contrato implícito de senhas, com risco real de deixar contas legadas
  indisponíveis. A regra do LEB é "evolua, não reescreva" e "não troque a camada
  de acesso a dados"; uma migração de hash mal feita é pior do que o problema
  original. Reportei com `corrigido: false` e recomendo tratar como trabalho
  separado, com migração offline e janela de manutenção.

- **`exportarCsv` continua gravando em arquivo temporário (`EXPORT_DIR`).` e `readfile`. Há
  risco teórico de corrida/concorrência no arquivo compartilhado
  `/var/www/painel/tmp/chamados.csv` se duas exportações acontecerem ao mesmo
  tempo. Não alterei porque: a função é chamada tanto pela rota web (técnico)
  quanto, presumivelmente, pela rotina noturna — mudar para `php://output`
  diretamente poderia mudar o comportamento esperado por esse consumidor interno
  (que talvez capture o arquivo). O risco é baixo (uso concorrente é raro) e a
  mudança extrapolaria o escopo seguro. Recomendo revisão separada.

- **Não adicionei `HttpOnly`/`Secure` ao cookie de sessão nem `session_regenerate_id`
  pós-login.** Seria correto, mas o `manifest.md` não menciona cookies e esses
  ajustes, embora bons, não são falhas "no código" que quebrem contrato — optei por
  não mexer no gerenciamento de sessão para evitar surpresas. Vale como débito
  técnico.

- **Não alterei a stack, nomes de arquivos, assinaturas, rotas GET, rótulos de
  status, formato/ordenação do CSV nem a estrutura HTML da listagem.** Tudo
  verificado contra o `manifest.md`. O `formatarStatus` continua devolvendo
  exatamente `Aberto`/`Em atendimento`/`Resolvido`; a tabela mantém
  `id="tabela-chamados"` e as colunas ID/Titulo/Status/Prioridade/Tecnico; os
  parâmetros `busca`/`ver`/`export` continuam os mesmos.

- **Verificação funcional realizada:** subi `schema.sql`+`seed.sql` em MySQL local
  e exerci (i) autenticação válida e inválida, (ii) tentativa de injeção na busca
  (`' OR '1'='1` → 0 resultados, antes devolvia todos), (iii) busca legítima,
  (iv) cliente bloqueado em `ver` de chamado alheio e liberado no próprio,
  (v) técnico liberado em qualquer chamado, (vi) `mediaResposta` com tabela sem
  respostas (retorna `0.0`, antes fataria), (vii) `mediaResposta` normal. Todos
  passaram. `php -l` sem erros nos três arquivos.
