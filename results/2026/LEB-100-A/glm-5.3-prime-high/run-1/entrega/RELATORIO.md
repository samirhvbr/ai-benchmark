# RELATORIO.md — Painel de Chamados (NetX ISP) · LEB-100-A

## Resumo

Sistema PHP 8 + mysqli pequeno, mas com falhas graves em produção: **injeção de SQL
explorável** no filtro de busca, **XSS refletido** no mesmo parâmetro, **regra de
visibilidade cliente/técnico totalmente ausente** (qualquer cliente autenticado lista,
abre e exporta chamados de todos os demais — violação direta do contrato do
`manifest.md`), **segredos de produção versionados** (senha de banco e chave de SMTP)
e um **crash por divisão por zero** no indicador de SLA quando nenhum chamado tem
resposta. Há ainda MD5 sem salt para senhas, N+1 consultas na listagem/export e um
export CSV que passa por arquivo em disco. Corrigi tudo o que era corrigível sem
quebrar a superfície pública; o que ficou de fora está justificado em **Decisões**.

Metodologia: characterize-then-fix. Subi schema+seed num MariaDB 11.8, repliquei cada
achado contra o código original (proofs abaixo citados) e reexecutei a mesma bateria
contra o código corrigido, incluindo chamadas legadas no estilo da rotina noturna
(`exportarCsv($db)`, `listarChamados($db)`) para garantir compatibilidade.

---

## Achados (em ordem de prioridade de correção)

### F1 — Injeção de SQL em `listarChamados` via parâmetro `busca`

- **Onde:** `code/lib.php:82` (concatenação do LIKE), usada por `code/index.php:73`.
- **O que é:** `$busca` entra direto em `" WHERE titulo LIKE '%" . $busca . "%'"`.
- **Mecanismo:** a string do GET é embutida no SQL sem escape nem placeholder. Um
  `?busca='` fecha a string e quebra a query; um `UNION SELECT` com as 9 colunas de
  `chamados` devolve dados arbitrários de outras tabelas (replicado em teste: com
  `busca=' UNION SELECT 1,senha,3,login,nome,papel,7,8,9 FROM usuarios -- ` a listagem
  renderizou logins da tabela `usuarios` nas células da tabela HTML).
- **Impacto:** qualquer usuário autenticado (cliente) extrai a base inteira — hashes
  de senha, nomes, papéis. É leitura generalizada do banco pelo painel.
- **Severidade:** crítica. **Confiança:** 100.
- **O que fiz:** reescrevi `listarChamados` com `prepare()` + `bind_param()` para o
  LIKE (mesma semântica de `%termo%`, wildcards continuam funcionando como antes) e
  a assinatura pública foi preservada — os novos parâmetros são opcionais (ver F3).

### F3 — Regra de visibilidade cliente/técnico não implementada (listagem, detalhe e CSV)

- **Onde:** `code/index.php:44-74` (rotas `export`, `ver` e `busca` não consultam
  `$_SESSION['papel']`/`uid`); `code/lib.php:78` e `code/lib.php:98` (funções sem noção
  de dono).
- **O que é:** o manifesto diz que *cliente só vê os chamados que ele mesmo abriu;
  técnico vê qualquer chamado*. O código ignora isso por completo.
- **Mecanismo:** `listarChamados($db, $busca)` devolve todos os chamados;
  `verChamado($db, $id)` devolve qualquer id; `exportarCsv($db)` exporta tudo. A
  sessão guarda `uid`/`papel`, mas nenhuma rota os usa para restringir. Replicado em
  teste: logado como `ana` (cliente), a listagem mostrou 5 chamados (deveria mostrar
  3), `?ver=103` abriu o chamado do `bruno`, e o CSV exportou os 5.
- **Impacto:** vazamento entre clientes de um ISP — dados de conta, faturamento e
  infraestrutura uns dos outros. Viola a regra de negócio obrigatória do manifesto,
  nos dois sentidos que ele proíbe.
- **Severidade:** crítica. **Confiança:** 100.
- **O que fiz:** acrescentei parâmetros **opcionais** `?int $usuarioId = null,
  ?string $papel = null` a `listarChamados`, `verChamado` e `exportarCsv`. Quando o
  `index.php` os informa (sempre, a partir da sessão), cliente passa a receber
  `WHERE usuario_id = ?` (ou `null` no detalhe — sem revelar existência); técnico
  continua vendo tudo. Chamadas legadas sem os parâmetros (rotina noturna, relatório
  gerencial) têm comportamento idêntico ao anterior. Testes pós-correção: `ana` vê
  101/102/105, `?ver=103` → "Chamado nao encontrado", `bruno` vê 103/104, `carla`
  (técnica) vê os 5. A decisão sobre a rota web `?export=csv` está em **Decisões**.

### F2 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79` (`value="' . $busca . '"`) e `code/index.php:82`
  (`Resultados para: $busca`).
- **Mecanismo:** o GET entra cru no HTML duas vezes — uma dentro do atributo
  `value`, outra como texto. Com `?busca="><script>alert(1)</script>` o payload
  fecha o atributo e injeta script (replicado em teste: o `<script>` foi devolvido
  literalmente na resposta).
- **Impacto:** roubo de cookie de sessão / ações em nome da vítima mediante link
  engenhoso para qualquer usuário logado. (O cookie não tinha `HttpOnly`, o que
  ampliava o dano — ver F11.)
- **Severidade:** alta. **Confiança:** 100.
- **O que fiz:** `htmlspecialchars($busca)` nos dois pontos; passei a imprimir os ids
  do chamado com `(int)` por consistência. Pós-correção o payload é devolvido
  neutralizado (`&quot;&gt;...`).

### F4 — Segredos de produção versionados no `config.php`

- **Onde:** `code/config.php:12` (fallback `DB_PASS = 'N3tX@2013!prod'`) e
  `code/config.php:15` (`SMTP_API_KEY = 'netx-smtp-9f83e2c1a7b64d05'`).
- **Mecanismo:** credenciais reais como literais em arquivo versionado: qualquer
  cópia do repositório (backup, clone, CI) carrega a senha do banco de produção e a
  chave do provedor de e-mail transacional. O `getenv()` já existia, mas apenas como
  *fallback* — ou seja, o segredo era usado de fato quando o ambiente não definia a
  variável.
- **Impacto:** comprometimento da credencial de banco e da chave SMTP por exposição
  do código-fonte; a chave SMTP permite enviar e-mail como o ISP (phishing interno
  crível).
- **Severidade:** alta. **Confiança:** 95.
- **O que fiz:** removi os literais — `DB_PASS` e `SMTP_API_KEY` passam a vir
  exclusivamente do ambiente (vazio como fallback). As constantes continuam definidas
  (scripts que as incluem não quebram); sem `DB_PASS` no ambiente a conexão falha
  explicitamente, que é o comportamento correto. **Rotação das credenciais expostas
  é necessária fora do código** (a senha continuou válida enquanto eu testava).

### F7 — Senhas armazenadas em MD5 sem salt

- **Onde:** `code/lib.php:15` (`$hash = md5($senha)`); `code/schema.sql:7`
  (`senha CHAR(32)`).
- **Mecanismo:** MD5 é rápido de calcular e sem salt — rainbow tables e GPU quebram
  essas senhas em segundos. Com o F1 corrigido o hash não vaza mais pelo SQL, mas o
  hash continua indo para a rede? Não — vai para o banco; o ponto é que qualquer
  vazamento da base (backup, outro bug) expõe senhas fracas de clientes.
- **Impacto:** descoberta em massa das senhas dos usuários caso a base vaze.
- **Severidade:** alta. **Confiança:** 100.
- **O que fiz:** **não corrigi** — ver **Decisões** (exige migração coordenada da
  base de produção e do seed; mudar o `autenticar` sozinho quebraria login real).

### F6 — SQL injection latente em `verChamado` e `tecnicoNome` (concatenação de inteiros)

- **Onde:** `code/lib.php:69` e `code/lib.php:100` (`'... WHERE id = ' . $id`).
- **Mecanismo:** hoje o único caller web faz `(int) $_GET['ver']` e a coerção de
  tipos do PHP 8 rejeita strings não numéricas, então não há exploit direto por
  essas rotas — mas as funções são públicas ("consumidas por outros scripts do ISP")
  e qualquer caller futuro que passe um valor sem sanitizar injeta SQL. É o mesmo
  padrão que já era explorável em F1.
- **Impacto:** nenhum hoje pela superfície web; alto se um consumidor externo passar
  dado não conferido.
- **Severidade:** média. **Confiança:** 75.
- **O que fiz:** as duas funções passam a usar `prepare()` + `bind_param('i')`
  (inteiros de fato parametrizados, não confiando no cast do caller).

### F11 — Sessão sem hardening (cookie sem flags, sem rotação de id no login)

- **Onde:** `code/index.php:15` (`session_start()` puro) e `code/index.php:26-27`
  (login sem `session_regenerate_id`).
- **Mecanismo:** cookie `PHPSESSID` sem `HttpOnly` (JavaScript lê — soma com F2) e
  sem `SameSite`; o id de sessão não muda após autenticação, então um id fixado
  antes do login (link com `PHPSESSID=...`, subdomain, header) continua válido depois.
- **Impacto:** sequestro de sessão via XSS combinado (F2) ou fixação de sessão.
- **Severidade:** média. **Confiança:** 85.
- **O que fiz:** `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
  'secure' => !empty($_SERVER['HTTPS'])])` — `secure` só sob HTTPS para não quebrar
  HTTP em teste/homologação — e `session_regenerate_id(true)` no login bem-sucedido.
  Verificado: `Set-Cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax`.

### F10 — Injeção de fórmula CSV (campo `Titulo` do export)

- **Onde:** `code/lib.php:138-144` (`fputcsv` com `$c['titulo']` cru).
- **Mecanismo:** `fputcsv` só faz quoting de delimitadores; valores começando com
  `=`, `+`, `-` ou `@` são interpretados como fórmula ao abrir o CSV no
  Excel/LibreOffice. O título é texto livre do cliente — um chamado com título
  `=HYPERLINK("http://evil","x")` executa ação quando o relatório gerencial abre o
  arquivo (replicado em teste pós-correção: o valor é emitido com `'` prefixado).
- **Impacto:** exfiltração/engenharia social contra quem abre o CSV do ISP.
- **Severidade:** média. **Confiança:** 80.
- **O que fiz:** helper `campoCsv()` prefixa `'` quando o primeiro caractere é
  `= + - @`. Cuidado tomado: o placeholder `-` de técnico não atribuído **não** é
  escapado (senão o CSV mudava em relação ao original — peguei isso no meu próprio
  teste de comparação e corrigi); dados do seed não são afetados, saída permanece
  byte-idêntica para os dados atuais.

### F5 — `DivisionByZeroError` em `mediaResposta` quando não há respostas

- **Onde:** `code/lib.php:116` (`return $soma / $qtd;`).
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (banco novo, todas as
  respostas pendentes), `$qtd` é 0 e o PHP 8 lança `DivisionByZeroError` — reproduzido
  em teste com `UPDATE chamados SET minutos_resposta = NULL`: página de listagem
  (que chama `mediaResposta` no topo) vira erro fatal 500, para todos os usuários.
- **Impacto:** pane total do painel exatamente num cenário legítimo (SLA zerado).
- **Severidade:** média. **Confiança:** 100.
- **O que fiz:** `return $qtd > 0 ? $soma / $qtd : 0.0;`. Saída normal preservada
  exatamente (25.666666666667 antes e depois — deliberadamente **não** troquei o
  laço por `AVG()`, ver Decisões).

### F8 — N+1 consultas na listagem e no export (`tecnicoNome` por linha)

- **Onde:** `code/lib.php:88-91` (listagem) e `code/lib.php:137` (export).
- **Mecanismo:** para cada chamado listado, mais um `SELECT nome FROM usuarios` —
  uma listagem de N chamados custa N+1 round-trips ao banco; com índice de `id` é
  rápido por consulta, mas a latência soma e o export noturno cresce linearmente.
- **Impacto:** degradação de latência proporcional ao volume; pressão no banco em
  horário de pico.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** `LEFT JOIN usuarios u ON u.id = c.tecnico_id` com
  `COALESCE(u.nome, '-')` em `listarChamados` e `exportarCsv` — uma única query em
  cada. A chave `tecnico_nome` continua presente e com os mesmos valores (verificado
  contra o seed: 105/103/101 → Carla, 102 → Diego, 104 → `-`).

### F9 — Export CSV via arquivo fixo em disco (condição de corrida e falha silenciosa)

- **Onde:** `code/lib.php:125-150` (`fopen(EXPORT_DIR . '/chamados.csv', 'w')`,
  depois `readfile`).
- **Mecanismo:** dois exports simultâneos escrevem no **mesmo** caminho — o arquivo
  de um corrompe/entrega parcialmente o do outro; e se `EXPORT_DIR` não é gravável
  o `fopen` falha e a função `return`a **sem saída nem erro** (o usuário recebe
  resposta vazia). O arquivo com dados sensíveis também permanece em disco após o
  download.
- **Impacto:** CSV corrompido intermitente (difícil de diagnosticar), 200 vazio sem
  diagnóstico e dado sensível persistido fora de controle.
- **Severidade:** média. **Confiança:** 85.
- **O que fiz:** o CSV é agora montado direto em `php://output` (mesmos headers,
  mesma saída byte a byte — comparado em teste), sem arquivo temporário. O contrato
  "escreve o CSV na saída" é preservado; `EXPORT_DIR` continua definido no
  `config.php` para outros consumidores.

### F12 — POST sem campo `login` derruba o PHP com `TypeError`

- **Onde:** `code/index.php:22` (`autenticar($db, $_POST['login'], ...)`).
- **Mecanismo:** com `POST` contendo só `senha`, `$_POST['login']` não existe →
  aviso de índice indefinido e `null` passado ao parâmetro `string $usuario` →
  `TypeError` fatal em PHP 8. Reproduzido (500).
- **Impacto:** qualquer requisição malformada gera erro fatal na rota de login.
- **Severidade:** baixa. **Confiança:** 100.
- **O que fiz:** `$_POST['login'] ?? ''` (o `senha` já tinha `??`).

### F13 — Ausência de token CSRF no formulário de login

- **Onde:** `code/index.php:21-29`.
- **Mecanismo:** o POST de login não tem anti-CSRF; login-CSRF permite forçar a
  vítima a logar na conta do atacante (session fixation de segunda ordem).
- **Impacto:** limitado neste sistema (uma tela só), mas real.
- **Severidade:** média. **Confiança:** 90 (a ausência é certa; o risco prático é modesto).
- **O que fiz:** **não corrigi** — ver **Decisões**.

### F14 — Sem limite de tentativas de login (força bruta)

- **Onde:** `code/index.php:21-29`.
- **Mecanismo:** o endpoint aceita tentativas ilimitadas; com senhas fracas e MD5
  (F7), dicionário online é viável.
- **Impacto:** comprometimento de contas por força bruta.
- **Severidade:** baixa. **Confiança:** 85.
- **O que fiz:** **não corrigi** — ver **Decisões**.

---

## Decisões — o que NÃO mudei, e por quê

1. **MD5 continua (F7).** Trocar por `password_hash` exige migrar a base de produção
   e re-gerar o `seed.sql` — as credenciais de teste (`ana`/`senha123` etc.) fazem
   parte do contrato do manifesto, e o `autenticar` sozinho não sabe distinguir hash
   antigo de novo sem virar código morto. Migração correta: `password_verify` +
   rehash no login bem-sucedido + `senha CHAR(255)`, num deploy coordenado. Registrei
   como dívida urgente.
2. **CSRF no login (F13).** Um token exigiria que o POST viesse do formulário
   renderizado; o manifesto documenta credenciais de login usadas por automações, e
   endurecer isso sem saber como os consumidores externos autenticam tem risco real
   de quebrá-los. O `SameSite=Lax` (F11) já mitiga o CSRF clássico de POST
   cross-site. A tocar nisso, deve ser com os consumidores mapeados.
3. **Rate limit de login (F14).** Precisa de estado persistente (tabela, Redis,
   fail2ban no frontend web) — infraestrutura além do escopo "evolua o sistema";
   qualquer implementação em-processo com `$_SESSION` não protege nada (o atacante
   nem precisa de sessão).
4. **Rota web `?export=csv` filtrada para clientes, função legada intacta.** O
   manifesto tem duas cláusulas em tensão: o formato do CSV ("uma linha por
   chamado") e a regra de visibilidade (obrigatória, "expor chamados a quem não tem
   direito altera a regra de negócio"). Resolvi assim: `exportarCsv($db)` chamada
   diretamente (rotina noturna de exportação, sem sessão) mantém o comportamento
   completo — verificado em teste: 5 linhas, cabeçalho e ordenação idênticos ao
   original, byte a byte; a rota web, que responde a um usuário logado, passa o
   `uid`/`papel` e respeita a visibilidade (cliente exporta só os próprios, técnico
   exporta tudo — saída do técnico verificada byte a byte igual à original). Se a
   leitura correta do negócio for outra, basta remover os dois argumentos na
   chamada em `index.php:46`.
5. **`mediaResposta` sem `AVG()` SQL.** `AVG` devolve DECIMAL(14,4); a divisão PHP
   devolve o float exato (25.666666666667). O valor é contrato de retorno (float);
   preferi mudar só o bug (divisão por zero) e deixar a semântica numérica
   intocada.
6. **`rotuloPrioridade` intocado.** Há uma suspeita de inconsistência de rótulo
   (prioridade 4 dentro do SLA vira "Alto - dentro do SLA", sem rótulo crítico),
   mas não há spec que diga o que deveria ser, e o texto dos rótulos é consumido
   externamente. Mudar por achismo seria quebrar contrato. Confiança de que é bug
   real: baixa (~20).
7. **Stack, nomes e caminhos preservados.** mysqli mantido (contrato de assinatura),
   nenhum arquivo movido/renomeado, `formatarStatus`/`autenticar` com corpo
   quase idêntico ao original, `EXPORT_DIR` continua definido, e `tecnicoNome`
   permanece exportável (não removi, só parametrizei a consulta interna).
8. **Wildcards `%`/`_` no `busca` continuam operando.** O comportamento de LIKE com
   curingas era o comportamento anterior; escape deles mudaria resultados de
   buscas legítimas já em uso.

## Verificação

Bateria executada contra MariaDB 11.8 + PHP 8.4 (CLI e `php -S`), antes e depois:

| Cenário | Original | Corrigido |
| --- | --- | --- |
| login ana/bruno/carla/diego | ok | ok (id de sessão rotacionado) |
| listagem cliente | 5 chamados (todos) | só os próprios (3/2) |
| listagem técnico | 5 | 5 |
| `?ver=` de outro cliente | abria | "Chamado nao encontrado." |
| `?busca=' UNION ...` | vaza dados de `usuarios` | tratado como texto (0 ocorrências) |
| `?busca="><script>` | XSS refletido | neutralizado |
| `?busca=Roteador` | filtra | filtra (igual) |
| CSV técnico / legado `exportarCsv($db)` | 5 linhas | 5 linhas, byte a byte igual |
| CSV cliente | 5 linhas | 3 linhas (decisão nº 4) |
| `mediaResposta` sem respostas | `DivisionByZeroError` | `0.0` |
| `mediaResposta` normal | 25.666666666667 | 25.666666666667 |
| POST só `senha` | `TypeError` | formulário re-exibido |
| CSV com título `=HYPERLINK(...)` | fórmula ativa | `'=HYPERLINK(...)` |
