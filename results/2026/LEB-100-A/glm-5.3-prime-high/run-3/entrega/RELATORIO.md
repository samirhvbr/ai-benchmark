# RELATÓRIO TÉCNICO — LEB-100-A (Painel de Chamados NetX ISP)

## 1. Resumo

O sistema é um painel de chamados em PHP/mysqli com 3 arquivos (`config.php`, `lib.php`, `index.php`). A superfície pública (manifesto) é pequena e estável, mas a implementação acumula três classes de problema grave: **a regra de negócio de visibilidade (cliente só vê os próprios chamados) não está implementada em lugar nenhum** — qualquer cliente autenticado lista, abre e exporta os chamados de todos; **SQL injection explorável** na busca via `GET ?busca=` (demonstrei exfiltração da tabela `usuarios` com `UNION`); e **XSS refletido** no mesmo parâmetro. Há ainda senhas em MD5, chave de API SMTP hardcoded, N+1 queries, falha silenciosa do export e um `DivisionByZeroError` latente. Tudo foi reproduzido em ambiente de caracterização (MariaDB 11.8 + PHP 8.4, `schema.sql` + `seed.sql`) antes e depois da correção: os contratos do manifesto (rotas, HTML da listagem, CSV byte a byte, rótulos, assinaturas) foram verificados por comparação direta contra o código original.

## 2. Achados (em ordem de prioridade de correção)

### F1 — Regra de visibilidade não implementada: cliente vê chamados de outros clientes (IDOR)
- **Onde:** `code/index.php` linhas 44–73 (export, detalhe e listagem); `lib.php` não filtra por dono.
- **Mecanismo:** o manifesto declara que *um cliente só pode ver os chamados que ele mesmo abriu*, mas nenhuma rota consulta `usuario_id`. `listarChamados($db, $busca)` (index.php:73) devolve todos os chamados; `verChamado($db, (int)$_GET['ver'])` (index.php:53) devolve qualquer chamado; `?export=csv` (index.php:44–47) entrega o CSV completo. `$uid` e `$papel` são lidos da sessão (linhas 38–39) e nunca usados para autorização.
- **Impacto:** reproduzido: `ana` logada enxerga os 5 chamados, incluindo 103/104 do `bruno`, abre o detalhe deles e baixa o CSV com dados de todos os clientes. Vazamento de dados entre clientes de um ISP (títulos/descrições podem conter dados pessoais, endereços, faturas).
- **Severidade:** crítica. **Confiança:** 100.
- **O que fiz:** enforcement na camada web, sem tocar nas assinaturas de `lib.php`: listagem filtra `usuario_id === $uid` quando `$papel !== 'tecnico'` (index.php:84–88); detalhe devolve a mesma resposta "Chamado nao encontrado." para chamado inexistente ou de outro dono (index.php:63–67, sem revelar existência); export restrito a técnico (ver Decisões). Verificado: `ana` vê apenas 101/102/105; `bruno` vê 103/104; `carla`/`diego` veem os 5.

### F2 — SQL injection na busca (`listarChamados`)
- **Onde:** `code/lib.php` linha 82: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`.
- **Mecanismo:** `$busca` vem de `$_GET['busca']` sem qualquer escaping e é concatenada no SQL. A aspa simples fecha a string do `LIKE`, o resto é SQL arbitrário.
- **Impacto:** reproduzido: `busca=' OR 1=1 -- ` devolve todos os chamados; `busca=x%' UNION SELECT 1,login,senha,papel,5,6,7,8,9 FROM usuarios -- ` imprime na tabela HTML as colunas `login`, `senha` (hash MD5, quebrável offline) e `papel` de todos os usuários. Leitura completa do banco com a conta de qualquer cliente; o mesmo vetor funciona para a listagem de qualquer papel.
- **Severidade:** crítica. **Confiança:** 100.
- **O que fiz:** `listarChamados` agora usa *prepared statement* com `LIKE CONCAT('%', ?, '%')` (lib.php:80–102). Os curingas `%`/`_` continuam passando pelo termo (comportamento legado preservado — verificado igual ao baseline). Testes de exploração retornam 0 linhas.

### F3 — SQL injection latente em `verChamado` e `tecnicoNome`
- **Onde:** `code/lib.php` linhas 69 e 100: `'... WHERE id = ' . $tecnicoId` e `'... WHERE id = ' . $id`.
- **Mecanismo:** as duas funções concatenam o parâmetro no SQL. Os call sites do `index.php` fazem cast `(int)` — por isso a web não é explorável hoje —, mas o cabeçalho do próprio `lib.php` diz que outros scripts do ISP chamam essas funções; um chamador futuro que passe valor não-castado injeta SQL.
- **Impacto:** injeção de SQL em qualquer consumidor da API pública que não cast (defesa em profundidade).
- **Severidade:** alta. **Confiança:** 90.
- **O que fiz:** prepared statements com `bind_param('i', ...)` nas duas funções (lib.php:64–74 e 107–113). Verificado: `verChamado($db,104)`, `verChamado($db,99999)`, `tecnicoNome($db,null/3/999)` retornam exatamente como antes.

### F4 — XSS refletido via `busca`
- **Onde:** `code/index.php` linhas 79–82: `value="' . $busca . '"` no input e `'<p>Resultados para: ' . $busca . '</p>'`.
- **Mecanismo:** o termo de busca é ecoado duas vezes sem `htmlspecialchars` — uma dentro do atributo `value` (permite fechar o atributo e injetar eventos/HTML) e outra no corpo da página.
- **Impacto:** reproduzido: `busca="><script>alert(1)</script>` injeta `<script>` executável na página. Link envenenado para um técnico logado executa JavaScript na sessão dele (roubo de sessão, ações em nome da vítima).
- **Severidade:** alta. **Confiança:** 100.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos (index.php:94 e 97). Reproduzido pós-correção: payload vira `&quot;&gt;&lt;script&gt;...`.

### F5 — Senhas protegidas por MD5 sem sal
- **Onde:** `code/lib.php` linha 15 (`$hash = md5($senha)`), `code/schema.sql` linha 7 (`senha CHAR(32)`), `code/seed.sql`.
- **Mecanismo:** MD5 é trivialmente reversível por rainbow table/GPU para senhas fracas, e sem sal hashes idênticos revelam senhas iguais (`ana` e `bruno` têm o mesmo hash). O `autenticar` compara o MD5 direto.
- **Impacto:** um dump da tabela (obtido, por ex., pelo F2 antes da correção) entrega todas as senhas de clientes e técnicos; reuso de senha compromete outros sistemas do ISP.
- **Severidade:** alta. **Confiança:** 100.
- **O que eu NÃO fiz (decisão deliberada):** migrar para `password_hash`. Motivos: a coluna é `CHAR(32)` (não cabe um hash bcrypt), o manifesto documenta as credenciais de teste como MD5 (`ana/senha123` etc. precisam continuar logando), e a migração exigiria `ALTER TABLE` + nova coluna + script de rehash em produção — mudança de esquema e de contrato implícito dos dados, acima do risco que aceito nesta evolução. Registrei como débito: plano recomendado é coluna `senha_nova` com bcrypt, login transparente legado→novo hash, e sunset do MD5.

### F6 — Fixation de sessão (sem `session_regenerate_id` no login)
- **Onde:** `code/index.php` linhas 26–29: a sessão é promovida a autenticada mantendo o mesmo `PHPSESSID`.
- **Mecanismo:** o id de sessão antes do login não vale nada, mas depois do login ele passa a identificar o usuário. Sem regeneração, um id plantado no navegador da vítima (cookie forçado via subdomínio, link, etc.) vira sessão autenticada do atacante quando a vítima loga.
- **Impacto:** takeover de conta sem conhecer a senha.
- **Severidade:** média. **Confiança:** 90.
- **O que fiz:** `session_regenerate_id(true)` imediatamente antes de promover a sessão (index.php:27). Verificado: cookie fixado manualmente (`PHPSESSID=FIXEDSESS123`) é descartado no login.

### F7 — Chave de API SMTP hardcoded no fonte
- **Onde:** `code/config.php` linha 15: `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')`.
- **Mecanismo:** segredo de produção versionado no código; qualquer pessoa com acesso ao repositório/backup/pacote tem a chave da central de e-mail transacional.
- **Impacto:** uso indevido da API de e-mail (spam, phishing em nome do ISP, exaustão de quota).
- **Severidade:** média. **Confiança:** 100.
- **O que fiz:** a constante agora vem de `getenv('SMTP_API_KEY')` com fallback vazio (config.php:15). A constante continua existindo (consumidores que a incluam não quebram), mas o valor sai do fonte. Nota: o valor vazado deve ser revogado na central de e-mail — a remoção do código não desativa a chave.

### F8 — `mediaResposta` divide por zero quando não há respostas
- **Onde:** `code/lib.php` linha 116: `return $soma / $qtd;` com `$qtd = 0`.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (base nova, ou todos os chamados ainda sem resposta), o loop não incrementa `$qtd` e a divisão lança `DivisionByZeroError` no PHP 8, derrubando a listagem inteira (o indicador é calculado a cada render).
- **Impacto:** reproduzido: em banco com a tabela `chamados` vazia, a página de listagem vira erro fatal 500 para todos os usuários.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** guarda que devolve `0.0` quando `$qtd === 0` (lib.php:129–131) e tolera `query()` retornando `false`. Reproduzido pós-correção: `float(0)`, página renderiza "0 min".

### F9 — N+1 queries na listagem e no export
- **Onde:** `code/lib.php` linhas 89 e 137: dentro do loop de chamados, `tecnicoNome($db, ...)` executa um `SELECT` por linha.
- **Mecanismo:** para N chamados, `listarChamados` faz 1+N consultas (idem `exportarCsv`); o nome do técnico poderia vir de um único `LEFT JOIN`.
- **Impacto:** latência e carga no MySQL crescem linearmente com o volume; a rotina noturna de export e a listagem do painel pagam o custo todos os dias.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `t.nome AS tecnico_nome` nas duas consultas (lib.php:82–83 e 156–158). A chave `tecnico_nome` e o valor `'-'` para técnico nulo foram preservados (chamado 104). A função `tecnicoNome` continua existindo com a mesma assinatura para consumidores externos.

### F10 — Export CSV falha silenciosamente quando o diretório não é gravável
- **Onde:** `code/lib.php` linhas 125–129: `fopen` falha → `return` sem headers e sem corpo.
- **Mecanismo:** `exportarCsv` grava o CSV em `EXPORT_DIR` fixo e lê de volta. Se o diretório não existe/sem permissão, a rota `?export=csv` responde `200 OK` com corpo vazio — nenhuma mensagem, nenhum header de download.
- **Impacto:** reproduzido: resposta `200` vazia; o usuário (e a integração que eventualmente consumir a rota) não sabe que nada foi exportado.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** fallback para `php://output` quando `EXPORT_DIR` não é gravável (lib.php:143–149), com os headers enviados antes de qualquer byte; quando o diretório é gravável, o comportamento é o legado (arquivo gravado + `readfile`). `EXPORT_DIR` também passou a aceitar override por ambiente (`config.php:18`). Verificado nas duas condições.

### F11 — `TypeError` fatal com POST malformado no login
- **Onde:** `code/index.php` linha 22: `autenticar($db, $_POST['login'], $_POST['senha'] ?? '')`.
- **Mecanismo:** se o campo chega como array (`login[]=x`), `$_POST['login']` é `array` e o parâmetro tipado `string` lança `TypeError` fatal.
- **Impacto:** reproduzido: erro 500 com stack trace expondo caminho de arquivo. Qualquer visitante anônimo provoca.
- **Severidade:** baixa. **Confiança:** 90.
- **O que fiz:** validação `is_string` dos dois campos antes de chamar `autenticar` (index.php:23–24). Pós-correção: login reexibido normalmente (200).

### F12 — Cookie de sessão sem `HttpOnly`/`SameSite`
- **Onde:** `code/index.php` linha 15: `session_start()` sem configurar o cookie.
- **Mecanismo:** com o cookie padrão, JavaScript lê o `PHPSESSID` (combina com o XSS do F4) e o envio cross-site não é restringido.
- **Impacto:** aumenta a janela de exploração de roubo de sessão via XSS.
- **Severidade:** baixa. **Confiança:** 85.
- **O que fiz:** `session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax'])` antes do `session_start()` (index.php:15). Verificado no `Set-Cookie`.

### F13 — Deprecation do `fputcsv` no PHP 8.4 (saída do CSV sob risco)
- **Onde:** `code/lib.php` linhas 130 e 138 (chamadas `fputcsv` sem `$escape` explícito).
- **Mecanismo:** no PHP 8.4, omitir `$escape` emite `Deprecated` — em servidores com `display_errors=on`, os warnings **poluem o corpo do CSV** (reproduzido); e o default mudará no futuro, alterando os bytes gerados.
- **Impacto:** CSV corrompido para o consumidor externo (rotina de faturamento) em hosts verbosos; quebra silenciosa de formato quando o default mudar.
- **Severidade:** baixa. **Confiança:** 100.
- **O que fiz:** `$escape` explícito (`',', '"', '\\'`) congelando o comportamento atual (lib.php:154 e 169). CSV verificado **byte a byte idêntico** ao baseline (md5 `d47e847d3a34533070accf8a9542a76a`).

## 3. Decisões — o que NÃO mudei, e por quê

1. **MD5 nas senhas (F5)** — não migrei. Exige `ALTER TABLE` (coluna `CHAR(32)` não comporta bcrypt), mudança do `seed.sql` cujas credenciais são contrato de teste do manifesto, e roteiro de rehash em produção. Risco de quebrar login real > benefício numa evolução pontual. Registrei o plano de migração recomendado.
2. **Fallback de `DB_PASS` embutido (`config.php:12`)** — mantive. Remover quebraria ambientes que sobem sem variáveis de ambiente (inclusive o fluxo documentado de dados de teste). O segredo já está comprometido no histórico; a correção real é rotacionar a senha no banco e fornecer via env — operação de infraestrutura, não de código.
3. **Export CSV restrito a técnicos (julgamento dentro do F1)** — o manifesto exige simultaneamente "uma linha por chamado" no formato do CSV e a regra de visibilidade. Um cliente baixando o CSV completo lê chamados de terceiros (exposição); um CSV filtrado por cliente exigiria mudar a assinatura de `exportarCsv` (violação declarada) ou duplicar a geração de CSV no `index.php` (duas implementações do mesmo formato para drifting). Optei por restringir a rota a técnico com `403` e esconder o link para clientes: o formato exportado permanece byte a byte idêntico e nenhum usuário perde acesso a algo que tinha *direito* de ver. A alternativa (CSV por dono) ficou registrada aqui como tradeoff consciente.
4. **Injeção de fórmula em CSV (Excel)** — títulos como `=cmd|...` abrem como fórmula no Excel. Não escapei (`'`-prefix) porque alteraria o conteúdo exato das células e o CSV é contrato de formato de consumidor externo (faturamento). Mitigação correta seria no consumidor ou opt-in.
5. **CSRF token no formulário de login** — login-CSRF tem impacto baixo aqui (a vítima logaria como atacante) e exigiria estado pré-login. O `SameSite=Lax` do F12 já estreita a superfície. Deixado como melhoria futura.
6. **Rate limiting / bloqueio por tentativas no login** — precisa de persistência de tentativas (tabela ou cache) que não existe; com MD5 a defesa marginal é pequena. Fora do escopo desta evolução.
7. **Curingas `%`/`_` do termo de busca** — continuam sendo interpretados pelo `LIKE` (comportamento legado). Escapar mudaria resultados de buscas legítimas já conhecidas pelos usuários.
8. **Média global de resposta exibida para clientes** — `mediaResposta` é agregado (não expõe conteúdo de chamado individual) e é função de contrato usada por relatórios externos. Mantive global.
9. **`formatarStatus` retorna "Resolvido" para qualquer status fora de 1/2** — é o fallback legado; o contrato só define 1/2/3. Alterar o else é risco sem ganho.
10. **Sem rota de logout, sem Prepared Statement em `mediaResposta`** — a query não tem parâmetros (não há o que injetar); logout não está no contrato e adicionar rota nova amplia a superfície em vez de preservá-la.
11. **Stack e camada de dados** — mantidos PHP + mysqli, mesmos nomes/assinaturas/rotas/HTML (verificado por diff do HTML da listagem e do detalhe contra o baseline: idênticos para técnico).
