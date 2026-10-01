# RELATORIO — LEB-100-A

## 1. Resumo

O sistema é um painel de chamados de suporte (NetX ISP), escrito em PHP com MySQL/mysqli, em produção desde 2013. A análise revelou problemas críticos: **injeção SQL**, **quebra de autorização** (clientes acessam chamados de outros clientes), **XSS refletido**, **segredos hardcoded**, **MD5 para hash de senhas** e **divisão por zero**. Foram corrigidos 9 dos 14 achados. Os 5 não corrigidos envolvem migração de banco (MD5), infraestrutura de sessão (fixação, CSRF) ou risco baixo de quebra de contrato (fallthrough do `formatarStatus`).

---

## 2. Achados

### F1 — SQL Injection em `listarChamados` (crítica, corrigida)

- **Onde:** `code/lib.php:82` (original)
- **Mecanismo:** O parâmetro `$busca` vindo de `$_GET['busca']` é concatenado diretamente na cláusula WHERE:
  ```php
  $sql .= " WHERE titulo LIKE '%" . $busca . "%'";
  ```
  Nenhuma sanitização ou _prepared statement_. Um adversário pode injetar `' UNION SELECT ... --` e extrair todo o banco (usuários, senhas, etc.), ou usar `SLEEP()` para _blind injection_.
- **Impacto:** Vazamento completo dos dados do banco, potencial escalada para o sistema de arquivos via `INTO OUTFILE`.
- **Severidade:** crítica
- **Confiança:** 100
- **Correção:** Substituída a concatenação por _prepared statement_ com `CONCAT('%', ?, '%')`, mantendo a mesma semântica de busca (`code/lib.php:84,88-92`).

---

### F2 — SQL Injection em `tecnicoNome` (média, corrigida)

- **Onde:** `code/lib.php:69` (original)
- **Mecanismo:** Concatenação de `$tecnicoId` na query:
  ```php
  $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
  ```
  O parâmetro é tipado `?int` e os callers internos fazem cast explícito, o que mitiga a via direta. Porém a função é chamada com valores da coluna `tecnico_id` da tabela `chamados` — se esse campo fosse corrompido (ex.: por outro vetor de SQL injection), haveria _segunda ordem_. Além disso, a assinatura `?int` não é enforcement em runtime — PHP aceitaria string se o caller ignorasse o tipo.
- **Impacto:** Vazamento condicional via dados corrompidos no banco.
- **Severidade:** média
- **Confiança:** 70
- **Correção:** Substituída por _prepared statement_ (`code/lib.php:69-73`).

---

### F3 — SQL Injection em `verChamado` (baixa, corrigida)

- **Onde:** `code/lib.php:100` (original)
- **Mecanismo:** Concatenação de `$id` na query:
  ```php
  $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
  ```
  O parâmetro é tipado `int` e o caller (`index.php:53`) faz `(int) $_GET['ver']`. Em PHP, a coerção de tipo para `int` e o cast explícito tornam a injeção inviável pela rota web. Mas o padrão é frágil: se um script futuro chamar `verChamado` sem cast prévio, a injeção se abre. A correção por _prepared statement_ elimina a classe inteira de risco.
- **Impacto:** Nenhum pela rota atual; risco futuro.
- **Severidade:** baixa
- **Confiança:** 60
- **Correção:** Substituída por _prepared statement_ (`code/lib.php:110-113`).

---

### F4 — XSS Refletido via parâmetro `busca` (alta, corrigida)

- **Onde:** `code/index.php:79,82` (original)
- **Mecanismo:** O valor de `$_GET['busca']` é ecoado sem escape em dois pontos:
  ```php
  value="' . $busca . '"                           // linha 79
  echo '<p>Resultados para: ' . $busca . '</p>';   // linha 82
  ```
  Um atacante envia `?busca=<script>alert(1)</script>` e o payload executa no navegador da vítima.
- **Impacto:** Roubo de sessão (a sessão não tem flags `HttpOnly` nem `Secure`), defacement, _phishing_ no contexto do painel.
- **Severidade:** alta
- **Confiança:** 100
- **Correção:** Ambos os pontos passaram a usar `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` (`code/index.php:90,93`).

---

### F5 — Quebra de autorização: clientes veem todos os chamados na listagem (crítica, corrigida)

- **Onde:** `code/index.php:73-77` e `code/lib.php:78-93` (originais)
- **Mecanismo:** A regra de negócio (`manifest.md §Regra de negócio`) diz que cliente só vê chamados próprios. `listarChamados` retorna **todos** os chamados, e `index.php` os exibe sem filtrar por `$_SESSION['papel']`. Um cliente autenticado vê chamados de qualquer outro cliente na tabela.
- **Impacto:** Violação de confidencialidade — um cliente vê títulos, status, prioridades e técnico de chamados de terceiros, expondo dados de negócio e privacidade.
- **Severidade:** crítica
- **Confiança:** 100
- **Correção:** Adicionado filtro em `index.php:79-83` que, para `papel === 'cliente'`, reduz o array a chamados cujo `usuario_id` coincide com `$_SESSION['uid']`. `listarChamados` não foi alterado para preservar o contrato com scripts externos (rotina noturna, relatório gerencial) que precisam da visão completa.

---

### F6 — Quebra de autorização: clientes acessam detalhe de qualquer chamado (crítica, corrigida)

- **Onde:** `code/index.php:52-58` (original)
- **Mecanismo:** `index.php?ver=<id>` chama `verChamado` e exibe o resultado sem verificar se o chamado pertence ao cliente logado. Basta enumerar IDs.
- **Impacto:** Violação de confidencialidade — cliente lê descrição completa, status e prioridade de chamados alheios.
- **Severidade:** crítica
- **Confiança:** 100
- **Correção:** Adicionada verificação em `index.php:59-62`: se `papel === 'cliente'` e `usuario_id` do chamado difere de `$uid`, exibe "Chamado nao encontrado." (mesma mensagem de ID inexistente, para não vazar existência).

---

### F7 — Quebra de autorização: exportação CSV sem filtro de visibilidade (alta, corrigida)

- **Onde:** `code/lib.php:125-151` (original)
- **Mecanismo:** `exportarCsv` faz `SELECT * FROM chamados` sem qualquer filtro por usuário, e é acessível via `index.php?export=csv` por qualquer usuário autenticado. Cliente baixa CSV com chamados de todos.
- **Impacto:** Violação de confidencialidade em lote — cliente obtém arquivo estruturado com todos os chamados do sistema.
- **Severidade:** alta
- **Confiança:** 100
- **Correção:** `exportarCsv` recebeu parâmetros opcionais `?int $usuarioId = null, ?string $papel = null` que, quando `papel === 'cliente'`, adicionam `WHERE usuario_id = ?` à query. O caller em `index.php:45` passou `$uid` e `$papel`. Scripts externos que chamarem `exportarCsv($db)` sem os novos parâmetros mantêm o comportamento original (visão completa), preservando compatibilidade. Também migrado de escrita em arquivo fixo (`EXPORT_DIR`) para `php://temp`, eliminando acúmulo de CSVs em disco.

---

### F8 — Divisão por zero em `mediaResposta` (média, corrigida)

- **Onde:** `code/lib.php:116` (original)
- **Mecanismo:** Se a tabela `chamados` não tiver nenhuma linha com `minutos_resposta IS NOT NULL`, `$qtd` fica 0 e a divisão `$soma / $qtd` produz `DivisionByZeroError` (PHP 8) ou warning + `INF` (PHP 7).
- **Impacto:** Página quebra (PHP 8) ou exibe `INF` no topo do painel, degradando a experiência.
- **Severidade:** média
- **Confiança:** 100
- **Correção:** Adicionada guarda `$qtd > 0 ? $soma / $qtd : 0.0` (`code/lib.php:128`).

---

### F9 — Segredos hardcoded em `config.php` (alta, corrigida)

- **Onde:** `code/config.php:12,15` (original)
- **Mecanismo:** Senha do banco (`N3tX@2013!prod`) e chave de API SMTP (`netx-smtp-9f83e2c1a7b64d05`) estavam no código-fonte como fallback. Qualquer pessoa com acesso ao repositório (ou a um backup do código) obtém credenciais de produção.
- **Impacto:** Acesso total ao banco de produção e capacidade de enviar e-mails como o sistema.
- **Severidade:** alta
- **Confiança:** 100
- **Correção:** `DB_PASS` agora exige a variável de ambiente (`getenv`) e aborta com mensagem clara se não definida (`code/config.php:11-15`). `SMTP_API_KEY` também lida de `getenv`, com fallback para string vazia (`code/config.php:18-19`).

---

### F10 — Senhas armazenadas com MD5 (alta, NÃO corrigida)

- **Onde:** `code/lib.php:15`, `code/schema.sql:7`, toda a lógica de `autenticar`
- **Mecanismo:** `autenticar` computa `md5($senha)` e compara com a coluna `senha CHAR(32)`. MD5 é criptograficamente quebrado, sem salt, extremamente rápido (bilhões de hashes/segundo em GPU). Um vazamento da tabela `usuarios` expõe senhas em texto claro via rainbow tables ou brute-force trivial.
- **Impacto:** Comprometimento de todas as contas em caso de vazamento do banco.
- **Severidade:** alta
- **Confiança:** 100
- **Decisão:** Não corrigido. Migrar para `password_hash`/`password_verify` exigiria: (a) alterar `senha CHAR(32)` para `VARCHAR(255)` no schema; (b) recalcular hashes no `seed.sql`; (c) reescrever `autenticar` para usar `password_verify`. Isso altera o schema (manifest proíbe trocar camada de dados), muda o seed de teste e requer coordenação com a operação para regenerar senhas de todos os usuários. Recomenda-se um plano de migração faseada: coluna nova `senha_hash`, período de coexistência, e remoção da coluna MD5.

---

### F11 — Sem regeneração de sessão no login (média, NÃO corrigida)

- **Onde:** `code/index.php:24`
- **Mecanismo:** Após `autenticar` retornar sucesso, a sessão mantém o mesmo ID. Um atacante que fixar o ID de sessão antes do login (ex.: via cookie plantado) herda a sessão autenticada da vítima (_session fixation_).
- **Impacto:** Tomada de conta pós-login.
- **Severidade:** média
- **Confiança:** 90
- **Decisão:** Não corrigido. `session_regenerate_id(true)` resolveria, mas é recomendável combiná-lo com flags `HttpOnly`, `Secure` e `SameSite` no cookie de sessão, além de configurar `session.cookie_*` no `php.ini` ou `config.php`. O escopo desta correção é maior que o código do sistema (envolve infraestrutura de deploy), e o sistema não tem suporte atual a HTTPS forçado (depende do proxy reverso do ISP).

---

### F12 — Sem proteção CSRF no formulário de login (baixa, NÃO corrigida)

- **Onde:** `code/index.php:32`
- **Mecanismo:** O formulário de login não inclui token CSRF. Um atacante pode forjar um POST cross-origin que autentica a vítima como um usuário controlado pelo atacante (_login CSRF_), potencialmente levando a vítima a operar no painel com a conta do atacante e revelar dados sem perceber.
- **Impacto:** Engenharia social / confusão de identidade.
- **Severidade:** baixa
- **Confiança:** 85
- **Decisão:** Não corrigido. A mitigação requer token vinculado à sessão, armazenamento server-side e validação no POST. O investimento é desproporcional ao risco neste sistema. O login CSRF é um vetor de baixo impacto comparado aos demais achados.

---

### F13 — `formatarStatus` fallthrough silencioso para "Resolvido" (baixa, NÃO corrigida)

- **Onde:** `code/lib.php:32-33`
- **Mecanismo:** A função cobre explicitamente status 1 ("Aberto") e 2 ("Em atendimento"), mas qualquer outro valor (0, 4, negativo) cai no `else` e retorna "Resolvido". Se um bug ou corrupção produzir um status inválido, o painel exibe "Resolvido" incorretamente.
- **Impacto:** Informação incorreta no painel e no relatório gerencial.
- **Severidade:** baixa
- **Confiança:** 95
- **Decisão:** Não corrigido. O contrato público (`manifest.md`) especifica exatamente os três rótulos (`1→Aberto, 2→Em atendimento, 3→Resolvido`). Alterar o fallback (ex.: retornar "Desconhecido" ou lançar exceção) quebraria consumidores que dependem do comportamento atual para o valor 3 e potencialmente exporia um rótulo não documentado. A correção correta seria adicionar constraint `CHECK` no banco e validar na entrada, não no renderizador.

---

### F14 — CSVs acumulados em disco (baixa, corrigida incidentalmente)

- **Onde:** `code/lib.php:125` (original)
- **Mecanismo:** `exportarCsv` escrevia em `EXPORT_DIR . '/chamados.csv'` e nunca removia o arquivo. Cada exportação sobrescrevia, mas se múltiplas instâncias PHP concorressem (ex.: sob `php-fpm`), havia condição de corrida. Além disso, `readfile` lia o arquivo do disco em vez de enviar o buffer diretamente.
- **Impacto:** Acúmulo de arquivos, condição de corrida entre workers.
- **Severidade:** baixa
- **Confiança:** 80
- **Correção:** Substituído por `fopen('php://temp', 'w+')` e `fpassthru`, eliminando I/O de disco e limpeza automática ao fechar o handle (`code/lib.php:162,181-183`).

---

## 3. Decisões (o que NÃO foi alterado e por quê)

| O que | Por que |
|---|---|
| **MD5 nas senhas** (F10) | Exige migração de schema (`CHAR(32)` → `VARCHAR(255)`), recalculo dos hashes no `seed.sql` e reescrita de `autenticar` com `password_verify`. Viola a restrição de não alterar a camada de dados e requer coordenação operacional com produção. Recomenda-se plano de migração faseada. |
| **Regeneração de sessão no login** (F11) | A correção completa (`session_regenerate_id` + flags `HttpOnly`/`Secure`/`SameSite`) depende de configuração do `php.ini` e do proxy reverso (HTTPS), que está fora do escopo de `code/`. Corrigir parcialmente (só `session_regenerate_id`) teria benefício limitado. |
| **CSRF no login** (F12) | Requer infraestrutura de token por sessão que o sistema não possui. Risco baixo (login CSRF não expõe dados existentes, apenas associa sessão a conta do atacante). |
| **Fallthrough do `formatarStatus`** (F13) | O contrato do manifesto especifica exatamente 1→Aberto, 2→Em atendimento, 3→Resolvido. Alterar o `else` quebraria a compatibilidade com o relatório gerencial. A correção adequada está na camada de validação de entrada, não no renderizador. |
| **Uso de `$_GET` sem validação** em `index.php` | O código depende de coerção de tipo (`(int)`) e `??` para segurança. Embora frágil, trocar por validação explícita seria refatoração cosmética sem corrigir vulnerabilidade — os vetores reais já foram endereçados (SQLi, XSS, autorização). |
| **`array_values` no filtro de clientes** (index.php:80) | Necessário para preservar índices numéricos contíguos no `foreach`, evitando comportamento inesperado com `foreach` em array associativo esparso. |
| **Assinatura de `exportarCsv`** | Parâmetros opcionais (`?int $usuarioId = null, ?string $papel = null`) preservam compatibilidade: scripts existentes que chamam `exportarCsv($db)` continuam funcionando com o comportamento original (sem filtro). |