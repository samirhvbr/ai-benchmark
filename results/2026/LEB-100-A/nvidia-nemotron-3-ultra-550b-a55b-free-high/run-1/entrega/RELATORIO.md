# Relatório Técnico — LEB-100-A

## Resumo

O sistema é um painel de chamados legado (PHP + MySQL/mysqli) em produção desde 2013. O código apresenta **falhas críticas de segurança** (injeção SQL em três funções, credenciais hardcoded, hash MD5), **violação da regra de negócio de visibilidade** (clientes viam chamados de outros clientes), **bugs** (divisão por zero, race condition no CSV), e **problemas de performance** (N+1 queries). As correções aplicadas preservam a superfície pública definida no `manifest.md`: assinaturas de funções, rotas, formatos de saída e regras de negócio.

---

## Achados

### F1 — Injeção SQL em `listarChamados` (code/lib.php:82 original)
**Categoria:** segurança | **Severidade:** critica | **Confiança:** 100

**Mecanismo:** A variável `$busca` (vinda de `$_GET['busca']`) era concatenada diretamente na query:  
`$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`  
Isso permite que um atacante injete SQL arbitrário via parâmetro `busca` (ex.: `%' UNION SELECT senha FROM usuarios--`).

**Impacto:** Leitura/escrita total no banco, bypass de autenticação, exfiltração de senhas (hashes MD5).

**Correção:** Reescrita com prepared statements e `bind_param`. A busca usa `LIKE ?` com parâmetro `%termo%`. Adicionado JOIN com `usuarios` para trazer `tecnico_nome` em uma única query (resolve também o N+1). Mantida a assinatura original com parâmetros opcionais para compatibilidade.

**Corrigido:** true

---

### F2 — Injeção SQL em `verChamado` (code/lib.php:100 original)
**Categoria:** segurança | **Severidade:** critica | **Confiança:** 100

**Mecanismo:** O `$id` (vindo de `$_GET['ver']`) era concatenado:  
`$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);`  
Embora venha de cast `(int)` no `index.php`, o padrão de concatenação é perigoso e a função podia ser chamada internamente sem cast.

**Impacto:** Injeção SQL se a função for chamada sem validação prévia; vazamento de chamados alheios.

**Correção:** Prepared statement com `bind_param('i', $id)`. Adicionada verificação de visibilidade (ver F5).

**Corrigido:** true

---

### F3 — Injeção SQL em `tecnicoNome` (code/lib.php:69 original)
**Categoria:** segurança | **Severidade:** alta | **Confiança:** 95

**Mecanismo:** Concatenação direta do `$tecnicoId`:  
`$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);`  
O ID vem do banco (coluna `tecnico_id`), mas o padrão é inseguro e a função é pública.

**Impacto:** Se um atacante conseguir corromper `tecnico_id` no banco (via outra injeção), executa SQL arbitrário.

**Correção:** Prepared statement com `bind_param('i', $tecnicoId)`.

**Corrigido:** true

---

### F4 — Credencial de banco hardcoded em `config.php` (linha 12 original)
**Categoria:** segurança | **Severidade:** alta | **Confiança:** 100

**Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');` — senha de produção no código-fonte.

**Impacto:** Vazamento de credencial em repositório, backups, logs, diffs. Qualquer pessoa com acesso ao código tem a senha do banco de produção.

**Correção:** Removido o fallback; agora `define('DB_PASS', getenv('DB_PASS') ?: '');`. A senha **deve** vir de variável de ambiente.

**Corrigido:** true

---

### F5 — Chave de API SMTP hardcoded em `config.php` (linha 15 original)
**Categoria:** segurança | **Severidade:** alta | **Confiança:** 100

**Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');` — segredo em texto plano no código.

**Impacto:** Uso indevido da conta de e-mail transacional, reputação de IP, custos financeiros.

**Correção:** Removido o valor hardcoded; agora `define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');`.

**Corrigido:** true

---

### F6 — Hash de senha em MD5 (code/lib.php:15)
**Categoria:** segurança | **Severidade:** alta | **Confiança:** 100

**Mecanismo:** `md5($senha)` usado para verificar credenciais. MD5 é colidível, rápido e sem salt — permite rainbow tables, força bruta e colisões.

**Impacto:** Se o banco vazar, senhas são recuperadas em segundos. Não há salt, então usuários com a mesma senha têm o mesmo hash.

**Decisão:** **Não corrigido**. A alteração quebraria a autenticação de todos os usuários existentes (seed.sql usa MD5). Requer migração planejada (rehash no login, coluna nova, etc.), fora do escopo de "evoluir sem reescrever". Reportado como achado para planejamento futuro.

**Corrigido:** false

---

### F7 — Violação da regra de visibilidade: cliente vê chamados de outros (code/lib.php:78-93 original, index.php:72-73 original)
**Categoria:** bug | **Severidade:** alta | **Confiança:** 100

**Mecanismo:** `listarChamados` e `verChamado` não filtravam por `usuario_id` quando o papel era `cliente`. Um cliente logado via `?busca=` ou `?ver=` acessava chamados de qualquer `usuario_id`.

**Impacto:** Vazamento de dados sensíveis entre clientes (títulos, descrições, status). Violação de LGPD/contrato.

**Correção:** Adicionados parâmetros opcionais `$usuarioId` e `$papel` em ambas as funções. Quando `$papel === 'cliente'`, a query inclui `AND c.usuario_id = ?`. O `index.php` passa `$uid` e `$papel` da sessão. Assinaturas originais preservadas (parâmetros com default `null`).

**Corrigido:** true

---

### F8 — Divisão por zero em `mediaResposta` (code/lib.php:116 original)
**Categoria:** bug | **Severidade:** media | **Confiança:** 100

**Mecanismo:** `return $soma / $qtd;` sem checar `$qtd > 0`. Se não houver chamados com `minutos_resposta`, `$qtd = 0` → `DivisionByZeroError` (PHP 8+) ou `INF` (PHP 7).

**Impacto:** Erro 500 na listagem ou valor `INF` exibido no painel.

**Correção:** `return $qtd > 0 ? $soma / $qtd : 0.0;`

**Corrigido:** true

---

### F9 — N+1 queries em `listarChamados` (code/lib.php:88-90 original)
**Categoria:** performance | **Severidade:** media | **Confiança:** 100

**Mecanismo:** Loop executava `tecnicoNome($db, ...)` para cada chamado → 1 query principal + N queries de técnico.

**Impacto:** Latência linear no número de chamados. Com 1000 chamados = 1001 queries.

**Correção:** JOIN `LEFT JOIN usuarios u ON c.tecnico_id = u.id` na query principal. `tecnico_nome` vem no result set.

**Corrigido:** true

---

### F10 — N+1 queries em `exportarCsv` (code/lib.php:136-137 original)
**Categoria:** performance | **Severidade:** media | **Confiança:** 100

**Mecanismo:** Mesmo padrão: loop chamando `tecnicoNome` por linha.

**Impacto:** Exportação lenta e carga desnecessária no banco.

**Correção:** Mesma estratégia: JOIN na query única. `tecnico_nome` processado no loop sem query extra.

**Corrigido:** true

---

### F11 — Race condition e exposição de arquivo temporário em `exportarCsv` (code/lib.php:125-150 original)
**Categoria:** bug | **Severidade:** media | **Confiança:** 90

**Mecanismo:** Escrita em `/var/www/painel/tmp/chamados.csv` (caminho fixo) → `readfile()`. Duas requisições simultâneas corrompem o arquivo uma da outra; o arquivo fica acessível via web se o diretório for público.

**Impacto:** CSV corrompido, vazamento de dados entre usuários, possível exposição via URL direta.

**Correção:** Streaming direto para `php://output` (stdout do PHP). Sem arquivo temporário, sem race, sem exposição. Headers enviados antes da escrita.

**Corrigido:** true

---

### F12 — Ausência de `session_regenerate_id` no login (code/index.php:24 original)
**Categoria:** segurança | **Severidade:** media | **Confiança:** 100

**Mecanismo:** Após autenticação, a sessão mantinha o mesmo ID. Um atacante que force um SID conhecido (session fixation) assume a sessão da vítima após login.

**Impacto:** Tomada de conta (account takeover).

**Correção:** Adicionado `session_regenerate_id(true);` logo após validação das credenciais.

**Corrigido:** true

---

### F13 — Cookies de sessão sem flags de segurança (code/index.php:15 original)
**Categoria:** segurança | **Severidade:** media | **Confiança:** 100

**Mecanismo:** `session_start()` sem configuração prévia de `session.cookie_httponly`, `session.cookie_secure`, `session.use_strict_mode`.

**Impacto:** Sessão acessível via JavaScript (XSS → roubo de sessão); transmitida em HTTP claro; aceita SIDs não gerados pelo servidor.

**Correção:** Adicionado antes do `session_start()`:
```php
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
ini_set('session.use_strict_mode', '1');
```

**Corrigido:** true

---

### F14 — Uso de `mysqli::query` sem prepared statement em `mediaResposta` (code/lib.php:109 original)
**Categoria:** qualidade | **Severidade:** baixa | **Confiança:** 80

**Mecanismo:** Query estática sem parâmetros, mas usa `query()` em vez de `prepare()`. Inconsistência com o resto do código corrigido.

**Impacto:** Baixo risco prático (sem input do usuário), mas padrão inseguro e dificulta auditoria.

**Decisão:** **Não corrigido**. A query não recebe parâmetros externos; o custo de mudar (prepared statement + loop fetch) não justifica o benefício marginal. Mantido como está para minimizar diff.

**Corrigido:** false

---

### F15 — Lógica de `rotuloPrioridade` aninhada e difícil de manter (code/lib.php:40-59)
**Categoria:** qualidade | **Severidade:** baixa | **Confiança:** 70

**Mecanismo:** `if/else` encadeados 4 níveis. Funciona, mas propenso a erros em futuras alterações de SLA.

**Impacto:** Dívida técnica; risco de bug em mudança de regra.

**Decisão:** **Não corrigido**. A função é pura (sem side effects), testável isoladamente, e o contrato de saída (strings exatas) é consumido pelo relatório gerencial. Refatorar traria risco de regressão sem ganho funcional imediato.

**Corrigido:** false

---

## Decisões (o que NÃO foi alterado e por quê)

| Item | Motivo |
|------|--------|
| Hash MD5 em `autenticar` | Quebraria login de todos os usuários atuais. Requer migração escalonada (coluna nova, rehash no login, flag de versão), fora do escopo. |
| `mysqli::query` em `mediaResposta` | Query estática, sem parâmetros. Risco prático nulo. Mudança só por consistência aumentaria superfície de diff. |
| Lógica aninhada de `rotuloPrioridade` | Função pura, contrato de strings fixo (consumido por relatório externo). Refatoração traria risco de regressão sem valor imediato. |
| `EXPORT_DIR` em `config.php` | Não é mais usado (CSV agora streamado), mas mantido para não quebrar outros scripts que possam depender da constante. |
| Estrutura de tabelas HTML / IDs / classes | Contrato do `manifest.md` exige `id="tabela-chamados"`, ordem de colunas, links `index.php?ver=`. Preservados integralmente. |
| Nomes de parâmetros GET (`busca`, `ver`, `export`) | Contrato externo (favoritos, links, scripts de integração). Preservados. |
| Formato do CSV (cabeçalho, ordem, rótulos de status) | Contrato do `manifest.md`. Preservado — `formatarStatus` e `fputcsv` mantêm saída idêntica. |
| Assinaturas públicas das funções | Todos os `manifest.md` mantidos: parâmetros originais com defaults, tipos de retorno, nomes. Novos parâmetros são opcionais (`null` default). |