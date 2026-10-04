# RELATORIO.md — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP 8 + mysqli, simples e funcional, mas com
duas falhas **críticas exploráveis por qualquer usuário logado**: injeção de SQL na
busca e ausência total da regra de visibilidade cliente/técnico do manifesto — um
cliente vê (e exporta) os chamados de todos os outros, incluindo descrições. Há
ainda XSS refletido, um crash de página inteira (divisão por zero) quando nenhum
chamado foi respondido, uma rota contratada (`?export=csv`) que falha em silêncio
quando o diretório de export não está gravável, e fragilidades estruturais
(N+1 queries, concatenação SQL latente, sessão sem endurecimento). O legado de
credenciais embutidas e hash MD5 de senhas foi **reportado, não corrigido**, por
compatibilidade — justificativa na seção Decisões.

Tudo o que foi corrigido foi **verificado empiricamente** contra uma instância real
(PHP 8.4 + MariaDB, schema/seed do pacote): os ataques foram reproduzidos no código
original, e o código corrigido foi retestado, inclusive uma comparação
**byte-a-byte** do CSV e uma chamada direta às funções públicas no formato que os
consumidores externos usam.

**Superfície pública preservada**: as 7 assinaturas do manifesto estão intactas
(`exportarCsv` ganhou um parâmetro *opcional* com valor padrão — chamadas existentes
`exportarCsv($db)` continuam válidas e idênticas, verificado com `ReflectionFunction`:
1 parâmetro obrigatório, igual ao original). Rótulos de status, formato e ordem do CSV
(diff byte-a-byte), rotas/parâmetros GET e estrutura HTML (`id="tabela-chamados"`,
colunas ID, Titulo, Status, Prioridade, Tecnico, links `index.php?ver=<id>`)
confirmados iguais.

## 2. Achados (em ordem de prioridade de correção)

---

### F1 — Injeção de SQL em `listarChamados` via parâmetro `busca`

- **Onde**: `code/lib.php:81-85` (concatenação em `lib.php:82`).
- **O que é**: o termo de busca é concatenado direto no SQL:
  `" WHERE titulo LIKE '%" . $busca . "%'"`, executado com `$db->query()` sem
  preparação.
- **Mecanismo**: uma aspa simples em `busca` fecha a string literal e o resto do
  parâmetro vira SQL. Reproduzido no ambiente de teste: `?busca='` devolve HTTP 500
  (erro de sintaxe vaza como página de erro — oráculo de sondagem). Com `UNION`
  alinhando as 9 colunas de `chamados`, a injeção anexa linhas arbitrárias à
  listagem — comprovado extraindo a tabela `usuarios` inteira: o payload
  `' UNION SELECT u.id, 0, NULL, u.login, u.senha, 1, 2, NULL, '2026-01-01 00:00:00' FROM usuarios u ORDER BY 1 DESC -- `
  fez a tabela HTML renderizar `diego`, `carla`, `bruno`, `ana` como "títulos"; via
  `?ver=<id>` das linhas injetadas a coluna `descricao` exibe os **hashes MD5 das
  senhas**. Só é necessário estar logado (qualquer papel).
- **Impacto**: exfiltração de credenciais de todos os usuários (quebráveis por
  rainbow table, ver F8) e leitura de qualquer tabela acessível ao usuário do banco.
- **Severidade**: crítica. **Confiança**: 100.
- **Correção**: reescrita com *prepared statement* (`prepare` + `bind_param('s')`) e
  `LIKE ?` com o wildcard embutido no parâmetro vinculado. A semântica de busca foi
  preservada de propósito: coringas `%`/`_` continuam funcionando como antes
  (verificado: `busca=%` retorna as mesmas linhas que retornava), porque o manifesto
  documenta "listagem filtrada por título" e não restringe o comportamento de LIKE —
  mudar isso seria risco de compat sem ganho de segurança, já que a injeção morre com
  a vinculação de parâmetro. Requalificação: `?busca='` agora devolve 200 e o payload
  UNION retorna zero linhas (tratado como texto literal).

---

### F2 — Regra de visibilidade (cliente/técnico) não implementada

- **Onde**: `code/index.php:52-67` (detalhe), `code/index.php:73` (listagem) e
  `code/index.php:44-47` (export CSV); `code/lib.php` não filtra por usuário.
- **O que é**: o manifesto define como regra de negócio obrigatória: *cliente só vê
  os chamados que ele mesmo abriu; técnico vê qualquer um*. Nenhum código aplica
  isso — `listarChamados`, `verChamado` e `exportarCsv` operam sobre a tabela
  inteira para qualquer sessão autenticada.
- **Mecanismo**: a sessão carrega `uid`/`papel` (`index.php:38-39`) e esses valores
  simplesmente nunca são usados para restringir consulta alguma. Reproduzido: logado
  como `ana` (cliente, dona dos chamados 101/102/105), a listagem exibia **os 5
  chamados**, incluindo 103 e 104 do `bruno`; `index.php?ver=103` exibia o detalhe
  completo (inclusive `descricao`) do chamado de outro cliente; `?export=csv` baixava
  a base inteira.
- **Impacto**: exposição de dados de clientes entre si (títulos, descrições, status)
  — violação direta da regra de negócio contratada.
- **Severidade**: crítica. **Confiança**: 100 (a regra está explícita no
  `manifest.md`; o código não a implementa; comportamento confirmado em execução).
- **Correção**: aplicada na camada de roteamento (`index.php`), sem tocar as
  assinaturas públicas de `lib.php` — decisiva, porque `listarChamados(mysqli $db,
  string $busca = '')` não recebe usuário, e a rotina noturna/relatório gerencial
  dependem de receber a base completa nessas assinaturas. (a) Listagem:
  `array_filter` por `usuario_id === uid` quando o papel não é `tecnico`.
  (b) Detalhe: chamado de outro cliente devolve a mesma mensagem `"Chamado nao
  encontrado."` usada para id inexistente — sem oracle de existência. (c) Export:
  `exportarCsv` ganhou o parâmetro opcional `?int $usuarioId = null`; a rota web
  passa o `uid` de clientes e `null` para técnicos; consumidores externos que
  chamam `exportarCsv($db)` continuam exportando tudo (verificado). Resultado dos
  testes: `ana` vê 101/102/105; `bruno` vê 103/104; `carla` (técnica) vê todos;
  `ana?ver=103` → "Chamado nao encontrado."; `carla?ver=103` → detalhe normal.

---

### F3 — XSS refletido no parâmetro `busca`

- **Onde**: `code/index.php:79` (valor do campo do formulário) e `code/index.php:82`
  (linha "Resultados para").
- **O que é**: `$busca` entra no HTML duas vezes sem `htmlspecialchars`.
- **Mecanismo**: o valor sai de `$_GET` direto para dentro de um atributo `value="…"`
  e de texto. Reproduzido: `?busca="><script>alert(1)</script>` gerou
  `value=""><script>alert(1)</script>…` — a aspa fecha o atributo, a tag script
  entra no fluxo. Como o XSS fica na URL, serve para ataque dirigido a sessões
  autenticadas (roubo de sessão — agravado pelo cookie sem HttpOnly, F10).
- **Impacto**: execução de script no navegador de qualquer usuário que abrir o link;
  sequestro de sessão.
- **Severidade**: alta. **Confiança**: 100.
- **Correção**: `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos
  (`$buscaHtml`, usada em ambos os ecos). Verificado: o mesmo payload agora
  renderiza `value="&quot;&gt;&lt;script&gt;…"`. A estrutura HTML da listagem
  (tabela, colunas, links) permanece a contratada.

---

### F4 — Divisão por zero derruba a listagem inteira (`mediaResposta`)

- **Onde**: `code/lib.php:116` (`return $soma / $qtd;`).
- **O que é**: se nenhum chamado tiver `minutos_resposta`, `$qtd` é 0 e a divisão
  lança `DivisionByZeroError`.
- **Mecanismo**: `mediaResposta` é chamada incondicionalmente na montagem da
  listagem (`index.php:74`). A função soma em PHP linha a linha e divide sem guarda.
  Reproduzido: com `UPDATE chamados SET minutos_resposta = NULL`, a home do painel
  devolveu HTTP 500 com fatal em `lib.php:116` — não é uma tela de estatística
  opcional, é a página principal do produto. Basta um período sem primeiras
  respostas registradas (ex.: banco novo, mudança de processo) para o painel cair.
- **Impacto**: indisponibilidade completa da listagem para todos os usuários.
- **Severidade**: alta. **Confiança**: 100.
- **Correção**: média calculada no banco (`SELECT AVG(...) AS media ...`), que
  retorna `NULL` sem amostras; a função devolve `0.0` nesse caso, preservando o
  retorno `float`. Com dados, o valor exibido continuou `26 min` (idêntico ao
  original); sem dados, a página agora carrega mostrando `0 min`. De quebra, a
  estatística deixou de varrer a tabela inteira para o PHP somar (ver F7).

---

### F5 — Rota de exportação grava em arquivo fixo: falha silenciosa e corrida

- **Onde**: `code/lib.php:125-150` (`EXPORT_DIR . '/chamados.csv'`).
- **O que é**: o export escreve o CSV num caminho fixo em disco e depois faz
  `readfile`.
- **Mecanismo**: (a) se `/var/www/painel/tmp` não existe ou não é gravável,
  `fopen` devolve `false`, a função **retorna em silêncio** e a rota contratada
  `index.php?export=csv` responde corpo vazio — reproduzido no ambiente de teste
  (HTTP 200 sem bytes, sem headers de download): o consumidor recebe um arquivo
  corrompido/vazio sem qualquer sinal de erro. (b) Dois exports simultâneos
  compartilham o mesmo caminho: um `readfile` pode ler o arquivo enquanto outro
  processo o reescreve — CSV misturado/parcial entregue. (c) Dados de todos os
  clientes vão desnecessariamente para disco no caminho de request.
- **Impacto**: quebra intermitente de uma rota documentada no manifesto; risco de
  dados corrompidos na integração de faturamento; resíduo de dados no servidor.
- **Severidade**: alta. **Confiança**: 95.
- **Correção**: o CSV agora é transmitido direto para a saída
  (`fopen('php://output')` + `fputcsv`), sem arquivo temporário. Como o manifesto
  define `exportarCsv` como "escreve o CSV na saída", o contrato fica, se algo,
  mais fiel. **Equivalência comprovada por diff byte-a-byte** entre o CSV gerado
  pelo fluxo original (arquivo em disco, simulado) e o novo stream — cabeçalho,
  ordem por `id`, rótulos e escaping idênticos. Headers `Content-Type`/
  `Content-Disposition` verificados presentes. A constante `EXPORT_DIR` permanece
  definida em `config.php` para qualquer script externo que a referencie.

---

### F6 — MD5 sem salt como hash de senha

- **Onde**: `code/lib.php:15` (`$hash = md5($senha);`); `code/schema.sql:7`
  (coluna `CHAR(32)`); `code/seed.sql` (hashes MD5).
- **O que é**: senhas guardadas como MD5 não salgado — construído para colisão em
  GPU a taxas de bilhões/s, com rainbow tables públicas.
- **Mecanismo**: `autenticar` calcula `md5($senha)` e compara por igualdade no SQL.
  O F1 entrega o hash de qualquer usuário sem nem precisar quebrar; uma vez com o
  hash (32 hex), a senha cai por dicionário/rainbow table em segundos para senhas
  como `senha123`. Não há salt: usuários com a mesma senha têm o mesmo hash
  (visível no seed: ana e bruno, carla e diego).
- **Impacto**: comprometamento de credenciais em caso de qualquer vazamento do
  banco (ou via F1, hoje corrigido).
- **Severidade**: alta. **Confiança**: 100.
- **Correção**: **não aplicada nesta entrega** — e isso é deliberado. A troca para
  `password_hash`/bcrypt exige coluna nova (`VARCHAR(255)`), migração dos hashes
  existentes (impossível sem reemitir senhas ou manter dupla checagem com janela de
  migração no login) e alteração do `schema.sql`/`seed.sql` que caracterizam o banco
  em produção. Fechar isso em uma manutenção "sem reescrever o sistema" e sem poder
  testar o workflow de reemissão de senha era o tipo de risco que a tarefa manda
  não correr. Registrei como débito técnico prioritário: caminho proposto — coluna
  `senha_hash` + `password_verify` com fallback MD5 preenchendo o hash novo no
  primeiro login bem-sucedido, e remoção do fallback quando a migração completar.

---

### F7 — Segredos de produção embutidos no código-fonte

- **Onde**: `code/config.php:12` (fallback `DB_PASS` com a senha de produção) e
  `code/config.php:15` (`SMTP_API_KEY` literal).
- **O que é**: senha do banco de produção e chave de API de e-mail transacional
  versionadas no fonte.
- **Mecanismo**: qualquer pessoa com acesso ao repositório/pacote (como o próprio
  pacote desta tarefa demonstra) obtém a senha do banco e uma chave de serviço de
  e-mail prontas para uso. O fallback de `DB_PASS` existe justamente para o caso de
  a variável de ambiente não estar definida — ou seja, o segredo é o default do
  deploy.
- **Impacto**: acesso direto ao banco pela rede (se o usuário `painel` aceitar
  conexões externas) e abuso do serviço de SMTP (spam/phishing em nome do ISP).
- **Severidade**: alta. **Confiança**: 100 (os valores estão lá, em claro).
- **Correção**: **não aplicada** nesta entrega, por duas razões: (a) remover o
  fallback de `DB_PASS` muda o comportamento de todo deploy que hoje sobe sem
  variáveis de ambiente — quebra de compatibilidade operacional para ganho zero
  (o segredo já circula no histórico; a única remediação real é **rotacionar** a
  senha no banco e a chave no provedor, um passo operacional, não de código);
  (b) `SMTP_API_KEY` não é usada em nenhum ponto de `code/`, mas scripts externos
  podem requerer `config.php` e usá-la — zerá-la no fonte quebraria esses
  consumidores silenciosamente. Recomendação registrada: rotacionar ambos os
  segredos, passar a exigir `DB_PASS` por ambiente e remover a chave do fonte.

---

### F8 — Concatenação de SQL e resultado de query sem checagem (padrão latente)

- **Onde**: `code/lib.php:69` (`tecnicoNome`), `code/lib.php:100` (`verChamado`);
  resultados não verificados em `lib.php:85` e `lib.php:101`.
- **O que é**: funções montam SQL por concatenação de inteiros e usam o retorno de
  `$db->query()` sem checar `false`.
- **Mecanismo**: hoje os dois parâmetros são tipados `?int`/`int` e o PHP casta na
  fronteira, então não há exploração ativa por estas vias (o caminho explorável era
  o de F1). O problema é o padrão: um refactor futuro que relaxe o tipo (ou um
  chamador novo passando string) ativa a injeção numa função que "sempre funcionou".
  Pior: quando a query falha, `query()` devolve `false` e `$res->fetch_assoc()` na
  linha seguinte lança fatal — era exatamente o que transformava a SQLi de F1 em
  página 500 sem tratamento.
- **Impacto**: risco latente de injeção + erros de banco virando 500 sem diagnóstico.
- **Severidade**: média. **Confiança**: 90 (como defeito de robustez/padrão; a
  explorabilidade atual é nula pelos tipos).
- **Correção**: `tecnicoNome` e `verChamado` reescritos com *prepared statements*
  (`bind_param('i')`); `listarChamados` e `exportarCsv` agora checam
  `prepare()`/`get_result()` e degradam de forma controlada (lista vazia / CSV
  vazio com headers de erro evitados). `tecnicoNome` foi mantida como função pública
  (o cabeçalho do arquivo e o comentário dela indicam uso interno/externo), com a
  mesma assinatura.

---

### F9 — Consultas N+1 na listagem e no export

- **Onde**: `code/lib.php:88-91` (loop de `listarChamados` chamando `tecnicoNome`
  por linha) e `code/lib.php:136-137` (idem no export).
- **O que é**: para N chamados, o painel executa 1 query de listagem + N queries
  de nome de técnico.
- **Mecanismo**: `tecnicoNome` faz `SELECT nome FROM usuarios WHERE id=<id>` por
  chamado dentro do `while`. Com 5 chamados são 6 round-trips; a tendência de
  crescimento do painel multiplica linearmente — a query de técnico não usa índice
  implícito? Usa (PK), mas o custo dominante é o round-trip mysqli por linha, no
  caminho de request da página mais usada, mais a média (F4) que varria a tabela
  inteira para somar em PHP.
- **Impacto**: degradação proporcional ao volume de chamados nas telas mais usadas.
- **Severidade**: média. **Confiança**: 95.
- **Correção**: `listarChamados` e `exportarCsv` agora usam um único
  `LEFT JOIN usuarios` com `COALESCE(u.nome, '-') AS tecnico_nome` — mesma forma do
  resultado (todas as colunas de `chamados` + `tecnico_nome`, com `-` para técnico
  nulo, verificado contra o seed). `mediaResposta` usa `AVG()` no banco (ver F4).
  Saída da listagem conferida idêntica à anterior, incluindo o `-` do chamado 104.

---

### F10 — Sessão sem endurecimento (cookie e fixação)

- **Onde**: `code/index.php:15` (`session_start()` sem configuração de cookie) e
  `code/index.php:24-26` (login sem `session_regenerate_id`).
- **O que é**: o cookie de sessão sai sem `HttpOnly`/`SameSite`, e o id de sessão
  não é renovado no login.
- **Mecanismo**: sem `HttpOnly`, qualquer XSS (F3, hoje corrigido) lê o
  `PHPSESSID` e sequestra a sessão mesmo com o XSS morto no navegador; sem
  `SameSite`, requisições cross-site levam o cookie. Sem regeneração no login, um
  id de sessão fixado antes da autenticação (cookie setado por subdomínio
  comprometido, header manipulado, URL de sessão) sobrevive à autenticação —
  fixação de sessão clássica.
- **Impacto**: sequestro de sessão combinável com outros vetores.
- **Severidade**: média. **Confiança**: 85.
- **Correção**: `session_set_cookie_params(['httponly' => true, 'samesite' =>
  'Lax'])` antes de `session_start()`, e `session_regenerate_id(true)` imediatamente
  após autenticação válida. `Lax` (não `Strict`) porque o painel é navegado por
  links simples vindos de e-mail/portal; `secure` foi deixado de fora
  deliberadamente — forçá-lo quebraria deploys internos em HTTP (ver Decisões).
  Verificado: `Set-Cookie: PHPSESSID=…; path=/; HttpOnly; SameSite=Lax` e o login
  continua fluindo (302 → listagem).

---

### F11 — Login CSRF (sem token no POST)

- **Onde**: `code/index.php:21-29` e `code/index.php:32-34`.
- **O que é**: o POST de login não tem token anti-CSRF.
- **Mecanismo**: um site atacante pode postar credenciais dele no painel
  (login CSRF) logando a vítima na conta do atacante — ou forçar tentativas de
  login. O impacto real aqui é limitado (o painel não tem ações sensíveis além de
  leitura), mas o padrão abre a família de CSRF para qualquer POST futuro.
- **Impacto**: login forçado em conta alheia; confusão de auditoria.
- **Severidade**: baixa. **Confiança**: 75.
- **Correção**: **não aplicada**: exigir token quebraria automações externas que
  fazem POST direto `login`/`senha` — o próprio fluxo de verificação desta entrega
  (e, presumivelmente, rotinas do ISP) depende dele, e o manifesto não documenta
  campo de token no formulário. Junto com cookies `SameSite=Lax` (F10), o login
  cross-site via form de outro domínio já fica bloqueado pelos navegadores
  modernos, o que mitiga o cenário principal sem tocar o contrato.

---

### F12 — TypeError fatal com POST malformado (`login[]`)

- **Onde**: `code/index.php:22` (`autenticar($db, $_POST['login'], …)`).
- **O que é**: `$_POST['login']` é passado sem cast a um parâmetro `string`.
- **Mecanismo**: POST com `login[]=x` entrega um *array*; o PHP 8 lança
  `TypeError` ao bind do parâmetro tipado → HTTP 500. Reproduzido no original
  (500); qualquer visitante anônimo consegue derrubar o request.
- **Impacto**: negação de request pontual; ruído de erro nos logs.
- **Severidade**: baixa. **Confiança**: 95.
- **Correção**: casts `(string)` em `login` e `senha` na fronteira do POST
  (idem para `$_GET['busca']`). Verificado: o mesmo POST agora responde 200 com o
  formulário de login.

---

### F13 — Injeção de fórmula no CSV (Excel/LibreOffice)

- **Onde**: `code/lib.php:138-144` (células do CSV escritas sem escape).
- **O que é**: valores de `titulo` que começam com `=`, `+`, `-` ou `@` são
  interpretados como fórmula ao abrir o CSV em planilhas.
- **Mecanismo**: `fputcsv` escreve o valor bruto; o `titulo` é texto livre criado
  por clientes. Um chamado intitulado `=HYPERLINK("http://mal","x")` vira fórmula
  ativa no Excel do gerente que abre o export.
- **Impacto**: execução de fórmula/exfiltração via planilha do consumidor interno.
- **Severidade**: baixa. **Confiança**: 65 (depende do consumidor abrir em
  planilha sem sanitização).
- **Correção**: **não aplicada**: prefixar `'` escaparia o valor e **alteraria os
  bytes do arquivo** que a integração de faturamento consome — o manifesto contrata
  o conteúdo do CSV. Correto seria tratar na ingestão do consumidor (ou migrar o
  contrato para um formato escapado, com acordo). Registrado como recomendação.

## 3. Decisões — o que NÃO mudei, e por quê

1. **MD5 nas senhas (F6)** — não troquei. Migração correta exige reemissão de
   senhas ou janela de dupla checagem, coluna nova e mudança em `schema.sql`/
   `seed.sql`; fazer pela metada numa manutenção de compatibilidade arriscava
   quebrar o login de todos os usuários de produção (incluindo os de teste
   documentados no manifesto). Proposta de migração incremental registrada no
   achado.
2. **Segredos em `config.php` (F7)** — mantive os valores. A remediação real é
   rotação (processo operacional) + exigir variável de ambiente; remover o fallback
   de `DB_PASS` muda o comportamento de deploys sem env vars, e zerar
   `SMTP_API_KEY` quebraria consumidores que requerem o `config.php` — sem ganho
   de segurança real, já que os valores atuais já estão expostos no histórico.
3. **CSRF no login (F11)** — não introduzi token. Automática externa e o próprio
   fluxo documentado dependem do POST `login`/`senha` direto; exigir campo novo
   quebraria consumidor sem que o manifesto o preveja. A mitigação adotada foi
   `SameSite=Lax` (F10), que cobre o vetor clássico sem mudar contrato.
4. **Injeção de fórmula no CSV (F13)** — não escapei. O manifesto contrata o
   conteúdo exato do arquivo (cabeçalho, colunas, rótulos) e a integração de
   faturamento o consome; escapar títulos mudaria os bytes entregues. Tratei como
   problema do consumidor da planilha, com recomendação registrada.
5. **Stack e camada de dados** — mysqli mantido, como o manifesto exige
   (assinaturas recebem `mysqli`). Nenhuma dependência nova foi introduzida: só
   `mysqli` (prepared statements), `session_*`, `fopen`/`fputcsv` — tudo já usado
   pelo sistema.
6. **Assinatura de `exportarCsv`** — ganhou um parâmetro **opcional** (`?int
   $usuarioId = null`, padrão que preserva o comportamento integral). Foi a única
   forma de aplicar a regra de visibilidade à rota `?export=csv` (F2) mantendo a
   rota, o formato e o uso por consumidores externos. Chamadas existentes
   `exportarCsv($db)` continuam idênticas (validado por chamada direta e
   reflexão: 1 parâmetro obrigatório, como no original). As demais 6 funções do
   manifesto têm assinaturas byte-a-byte idênticas.
7. **Semântica de LIKE na busca** — preservei coringas (`%`/`_`) funcionando como
   antes. Um escape de coringas mudaria resultados de buscas legítimas (ex.:
   `busca=100%`) sem acrescentar segurança, já que F1 mata a injeção na
   vinculação do parâmetro.
8. **Cookie sem flag `secure`** — mantive sem. Forçá-la quebra deploys internos
   em HTTP; a decisão correta exigiria saber a topologia (terminação TLS) do ISP.
9. **`rotuloPrioridade`** — não toquei na lógica. Os rótulos não estão no
   manifesto (só a assinatura), não há especificação dos valores "corretos", e o
   comportamento atual (crítico com SLA estourado, aguardando 1ª resposta etc.) é
   plausível e usado pelos relatórios. Mudar seria adivinhar regra de negócio.
10. **Média global exibida a clientes** — a linha "Tempo medio de 1a resposta" na
    home continua agregada para todos. A regra do manifesto cobre *chamados*
    visíveis, não indicadores agregados; mudá-la alteraria o painel que o ISP
    conhece. Sem risco equivalente ao do F2 (não identifica chamado nem cliente).
11. **Rate limit / brute force no login** — identificado como lacuna, mas qualquer
    implementação real precisa de storage compartilhado ou camada de proxy, o que
    violaria a restrição de não introduzir dependências. Registrado como débito.
12. **`die('Falha ao conectar ao banco.')`** — mantido: a mensagem não vaza
    detalhes (host/credencial) e o comportamento é conhecido.

## 4. Como verifiquei

Ambiente: PHP 8.4.26 (mysqli) + MariaDB, banco `suporte` criado a partir de
`code/schema.sql` + `code/seed.sql`. O código original foi servido (PHP built-in
server) e todos os achados exploráveis foram **reproduzidos nele** (500 em `busca='`,
UNION extraindo `usuarios`, XSS renderizado, `ana` vendo chamados de `bruno`,
export vazio, 500 com `minutos_resposta` nulo, 500 com `login[]`). O código corrigido
foi então servido e retestado: mesmas rotas, logins dos 4 usuários, busca normal e
com coringa, detalhe próprio/alheio por papel, CSV para técnico (diff byte-a-byte
contra o fluxo original) e cliente, home com e sem dados de resposta, estrutura HTML
(id da tabela, colunas, links), headers do CSV, flags do cookie, e chamadas diretas
às 7 funções públicas nos formatos do manifesto. `php -l` limpo em todos os arquivos.
