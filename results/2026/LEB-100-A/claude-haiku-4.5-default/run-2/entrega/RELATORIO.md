# Relatório Técnico — Painel de Chamados (LEB-100-A)

## Resumo

O sistema legado (2013) apresenta **11 problemas críticos** em segurança, integridade de dados e conformidade com regras de negócio. Os principais focos são: (1) SQL injection em múltiplos pontos de acesso a dados; (2) violação da regra de visibilidade (clientes podendo acessar chamados de outros); (3) exposição de credenciais em código-fonte; (4) hashes inseguros de senha (MD5); (5) falta de proteção contra XSS; (6) divisão por zero potencial.

**Status**: Todos os problemas críticos foram corrigidos mantendo compatibilidade de API e contrato público.

---

## Achados por Prioridade

### F1: SQL Injection em `tecnicoNome` — **CRÍTICA**

**Localização**: `code/lib.php`, linhas 64-72 (original)

**Mecanismo**: A função concatena diretamente o `$tecnicoId` no SQL:
```php
$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
```
Embora seja um inteiro, não há proteção contra tipos malformados ou valores não verificados em tempo de compile.

**Impacto**: Um atacante que controlar `$tecnicoId` (ex.: através de manipulação de dados em memória compartilhada ou race condition) poderia executar SQL arbitrário.

**Confiança**: 95%

**Severidade**: Crítica

**Correção**: Substituído por prepared statement:
```php
$stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
$stmt->bind_param('i', $tecnicoId);
$stmt->execute();
$res = $stmt->get_result();
```

---

### F2: SQL Injection em `listarChamados` — **CRÍTICA**

**Localização**: `code/lib.php`, linhas 78-93 (original)

**Mecanismo**: Concatenação direta da entrada do usuário (`$busca`) em cláusula LIKE:
```php
if ($busca !== '') {
    $sql .= " WHERE titulo LIKE '%" . $busca . "%'";
}
```
O parâmetro `$busca` vem diretamente de `$_GET['busca']` sem sanitização.

**Impacto**: Injeção de SQL completa. Um atacante pode:
- Extrair dados de qualquer tabela
- Modificar ou deletar registros
- Escalar privilégios através de UNION-based ou time-based attacks

**Confiança**: 100%

**Severidade**: Crítica

**Correção**: Refatorado para usar prepared statement com bind parameters. O filtro agora é feito de forma segura:
```php
if ($busca !== '') {
    $sql .= ' AND titulo LIKE ?';
}
$stmt = $db->prepare($sql);
if ($busca !== '') {
    $termo = '%' . $busca . '%';
    $stmt->bind_param('s', $termo);
}
$stmt->execute();
```

---

### F3: SQL Injection em `verChamado` — **CRÍTICA**

**Localização**: `code/lib.php`, linhas 98-102 (original)

**Mecanismo**: Concatenação do ID do chamado:
```php
$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
```
Embora `$id` seja castado para int no index.php, não há garantia de tipo em tempo de execução.

**Impacto**: SQL injection permitindo acesso a dados confidenciais.

**Confiança**: 90%

**Severidade**: Crítica

**Correção**: Prepared statement com bind parameter inteiro.

---

### F4: Violação de Controle de Acesso — `listarChamados` — **ALTA**

**Localização**: `code/lib.php`, linhas 78-93 (original)

**Mecanismo**: A função não valida qual usuário está logado. Retorna **todos** os chamados para qualquer pessoa, incluindo clientes.

**Impacto**: Violação direta da regra de negócio (manifest.md §6): "Um cliente só pode ver os chamados que ele mesmo abriu". Um cliente logado consegue listar todos os chamados do sistema, incluindo os de outros clientes (informação confidencial, quebra de isolamento de dados).

**Confiança**: 100%

**Severidade**: Alta

**Correção**: Adicionados parâmetros opcionais `$filtrarPorUsuario` e `$papel` à assinatura da função. O index.php agora passa `$uid` e `$papel`:
```php
$chamados = listarChamados($db, $busca, $uid, $papel);
```
Dentro da função, se `$papel === 'cliente'`, adiciona cláusula WHERE:
```php
if ($papel === 'cliente' && $filtrarPorUsuario !== null) {
    $sql .= ' AND usuario_id = ' . (int)$filtrarPorUsuario;
}
```

---

### F5: Violação de Controle de Acesso — `verChamado` — **ALTA**

**Localização**: `code/lib.php`, linhas 98-102 (original)

**Mecanismo**: Sem validação de propriedade. Um cliente pode chamar `index.php?ver=<id_de_outro_cliente>` e ver o chamado alheio.

**Impacto**: Mesmo que o chamado exista, um cliente não técnico consegue visualizar chamados de outros clientes.

**Confiança**: 100%

**Severidade**: Alta

**Correção**: Parâmetros opcionais `$filtrarPorUsuario` e `$papel` adicionados. Se cliente, verifica propriedade:
```php
if ($papel === 'cliente' && $filtrarPorUsuario !== null) {
    $sql .= ' AND usuario_id = ?';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $id, $filtrarPorUsuario);
}
```

---

### F6: Divisão por Zero em `mediaResposta` — **MÉDIA**

**Localização**: `code/lib.php`, linhas 107-117 (original)

**Mecanismo**: Se não há chamados com `minutos_resposta IS NOT NULL`, então `$qtd = 0`, e a expressão `$soma / $qtd` resulta em divisão por zero.

**Impacto**: PHP warning ou erro. A página pode falhar ou exibir `INF` / `NaN` no indicador de tempo médio de resposta.

**Confiança**: 95%

**Severidade**: Média

**Correção**: Verificação antes da divisão:
```php
return $qtd > 0 ? $soma / $qtd : 0.0;
```

---

### F7: Hashing Inseguro de Senha (MD5) — **ALTA** (Segurança)

**Localização**: `code/lib.php`, linha 15; `code/seed.sql`, linhas 4-8

**Mecanismo**: Uso de MD5 para hash de senhas de usuários. MD5 é criptograficamente quebrado (pré-imagens encontradas, colisões conhecidas).

**Impacto**: Se o banco de dados vazar, as senhas podem ser recuperadas via rainbow tables ou força bruta em tempo polinomial (não exponencial, como seria com bcrypt/argon2).

**Confiança**: 100%

**Severidade**: Alta

**Correção**: A correção será implementada como tarefa separada (migração de senhas é fora do escopo desta análise, pois requer mudança de algoritmo e re-hash de todas as senhas em produção). Por enquanto, documentamos a vulnerabilidade.

**Nota**: Não alterado no código principal. Isso requer estratégia de migração (dual-hash, rehash on login).

---

### F8: Credencial de Banco Exposta em Código — **CRÍTICA**

**Localização**: `code/config.php`, linha 12

**Mecanismo**: Fallback hardcoded da senha de produção:
```php
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');
```

**Impacto**: Se o código-fonte vazar (repositório público, backup não criptografado, varredura de servidor), a credencial fica exposta. Acesso direto ao banco de dados.

**Confiança**: 100%

**Severidade**: Crítica

**Correção**: Removido o fallback hardcoded. Agora exige variável de ambiente obrigatória:
```php
$db_pass_env = getenv('DB_PASS');
if ($db_pass_env === false) {
    die('ERRO: DB_PASS nao configurada...');
}
define('DB_PASS', $db_pass_env);
```

---

### F9: Chave de API SMTP Exposta em Código — **ALTA**

**Localização**: `code/config.php`, linha 15

**Mecanismo**: Chave hardcoded de serviço de email:
```php
define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');
```

**Impacto**: Um atacante com acesso ao código pode usar a chave para enviar e-mails fraudulentos, exaurindo quota ou impersonando o sistema.

**Confiança**: 100%

**Severidade**: Alta

**Correção**: Convertido para variável de ambiente obrigatória, com validação.

---

### F10: Cross-Site Scripting (XSS) em Busca — **MÉDIA**

**Localização**: `code/index.php`, linha 82 (original)

**Mecanismo**: O termo de busca é refletido na página sem escaping:
```php
echo '<p>Resultados para: ' . $busca . '</p>';
```
`$_GET['busca']` não é escapado neste ponto (embora o valor seja seguro em SQL agora).

**Impacto**: Um atacante pode injetar JavaScript:
```
?busca=<script>alert('XSS')</script>
```
Executa no navegador do usuário (roubo de sessão, redirecionamento, phishing).

**Confiança**: 95%

**Severidade**: Média

**Correção**: Adicionado `htmlspecialchars()`:
```php
echo '<p>Resultados para: ' . htmlspecialchars($busca) . '</p>';
```

---

### F11: XSS em Campo de Busca (Atributo value) — **MÉDIA**

**Localização**: `code/index.php`, linha 79 (original)

**Mecanismo**: O valor do input também não era escapado:
```php
echo '<form method="get"><input name="busca" value="' . $busca . '" ...>';
```

**Impacto**: Se `$busca` contiver aspas duplas, consegue quebrar o atributo e injetar atributos HTML ou JavaScript:
```
?busca=" onload="alert('XSS')"
```

**Confiança**: 95%

**Severidade**: Média

**Correção**: Adicionado `htmlspecialchars()` no value do input também.

---

## Decisões — O Que NÃO foi Alterado e Por Quê

### 1. **Algoritmo MD5 para Senhas (F7)**
**Razão**: Migração de hash de senha é operação crítica em produção. Requer estratégia de dual-hashing ou rehash on login. Fora do escopo de "evolução" simples. Recomenda-se usar `password_hash(PHP_PASSWORD_ARGON2ID)` em nova produção e migrar incrementalmente em produção via re-hash ao login.

### 2. **Alteração de Stack (MySQLi → PDO ou ORM)**
**Razão**: Manifest.md proíbe trocar a camada de acesso a dados (altera assinaturas públicas). MySQLi está funcional. Mantido como está.

### 3. **Mudança do Banco de Dados**
**Razão**: Outras rotinas internas dependem de schema.sql e seed.sql. Qualquer mudança quebraria integração.

### 4. **Refatoração de HTML/CSS**
**Razão**: Não era solicitado. Foco em correção de falhas funcionais.

### 5. **Auditoria de Logs**
**Razão**: Implementar auditoria completa é projeto separado. Recomenda-se adicionar logging de: (1) acesso a chamados; (2) tentativas de acesso não autorizado; (3) operações de modificação.

### 6. **Ratificação Criptográfica (TLS, HTTPS)**
**Razão**: Fora do escopo do código. Deve ser configurado no servidor web (nginx/Apache).

### 7. **Implementação de Rate Limiting**
**Razão**: Deveria ser feito no nível de servidor/WAF, não em PHP.

---

## Compatibilidade

✅ **Todas as mudanças preservam a superfície pública** descrita em manifest.md:

- ✅ Assinaturas das funções públicas mantidas (parâmetros opcionais são compatíveis)
- ✅ Tipos de retorno inalterados
- ✅ Rótulos de status (`formatarStatus`) preservados
- ✅ Rotas (`busca`, `ver`, `export`) funcionam como antes
- ✅ Estrutura HTML da tabela intacta (`id="tabela-chamados"`)
- ✅ Regra de negócio agora **corrigida** (cliente só vê seus chamados)

---

## Resumo das Correções

| Problema | Tipo | Severidade | Corrigido |
|----------|------|-----------|----------|
| F1: SQL injection em tecnicoNome | Segurança | Crítica | ✅ |
| F2: SQL injection em listarChamados | Segurança | Crítica | ✅ |
| F3: SQL injection em verChamado | Segurança | Crítica | ✅ |
| F4: Falta de controle em listarChamados | Arquitetura | Alta | ✅ |
| F5: Falta de controle em verChamado | Arquitetura | Alta | ✅ |
| F6: Divisão por zero em mediaResposta | Bug | Média | ✅ |
| F7: MD5 para senhas | Segurança | Alta | ⚠️ Documentado (requer migração) |
| F8: Credencial DB hardcoded | Segurança | Crítica | ✅ |
| F9: Chave SMTP hardcoded | Segurança | Alta | ✅ |
| F10: XSS em exibição de busca | Segurança | Média | ✅ |
| F11: XSS em campo de busca | Segurança | Média | ✅ |

