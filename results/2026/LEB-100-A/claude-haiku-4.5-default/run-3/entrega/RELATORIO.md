# Relatório Técnico — LEB-100-A

## Resumo

O sistema de painel de chamados possui uma arquitetura legada simples (3 arquivos PHP + MySQL), mas com **problemas críticos de segurança** que expõem o sistema a injeção SQL, XSS e exposição de credenciais. Além disso, há uma **violação clara da regra de negócio** de visibilidade: clientes conseguem acessar todos os chamados do sistema, não apenas os seus. Foi também encontrado um bug de division by zero em caso de banco vazio. Todos os problemas foram corrigidos preservando a compatibilidade declarada no manifesto.

---

## Achados

### F1 — SQL Injection em `tecnicoNome()`

**Local:** `code/lib.php`, linha 69

**O que é:** A função concatena o ID diretamente na query SQL sem uso de prepared statements:
```php
$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
```

**Mecanismo:** Um atacante não pode explorar isso diretamente (a função é interna e recebe um int), mas se um valor malicioso chegasse até aqui via camada superior, a query estaria vulnerável. O padrão é perigoso — invita erros na evolução do código.

**Impacto:** Potencial leitura de dados não autorizados (nomes de técnicos, ou via `UNION SELECT`, dados de outras tabelas).

**Severidade:** Alta

**Confiança:** 100 — é concatenação clara de entrada em SQL.

**O que foi feito:** Convertido para prepared statement com `bind_param('i', $tecnicoId)`. A assinatura e comportamento da função foram preservados.

---

### F2 — SQL Injection em `listarChamados()`

**Local:** `code/lib.php`, linha 82

**O que é:** A função aplica filtro de busca por título concatenando a entrada do usuário diretamente:
```php
$sql .= " WHERE titulo LIKE '%" . $busca . "%'";
```

**Mecanismo:** Um usuário envia `?busca=' OR '1'='1` (ou qualquer variação) e consegue contornar o filtro. Exemplo: `SELECT * FROM chamados WHERE titulo LIKE '%' OR '1'='1%'` retorna todos os chamados, não apenas os filtrados.

**Impacto:** Leitura não autorizada de chamados que não pertencem ao usuário. Isso é agravado pelo fato de que a função já não respeita a regra de visibilidade (ver F6).

**Severidade:** Alta

**Confiança:** 100 — é injeção SQL clássica.

**O que foi feito:** Refatorado para usar prepared statement. A busca agora é parametrizada com `bind_param('s', $busca_param)` onde `$busca_param = '%' . $busca . '%'`.

---

### F3 — SQL Injection em `verChamado()`

**Local:** `code/lib.php`, linha 100

**O que é:** Concatenação direta do ID na query:
```php
$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
```

**Mecanismo:** Embora o `index.php` faça cast para `(int) $_GET['ver']` antes de chamar a função, o problema está na função em si. Se consumidores externos (rotina noturna, relatório gerencial, mencionados no manifesto) a chamarem com entrada não validada, ficariam vulneráveis.

**Impacto:** Leitura de dados fora do escopo esperado; em combinação com UNION SELECT, acesso a outras tabelas.

**Severidade:** Alta

**Confiança:** 100 — concatenação direta.

**O que foi feito:** Converted to `prepare() + bind_param('i', $id)`. A assinatura e retorno foram mantidos.

---

### F4 — Credenciais Hardcoded em `config.php`

**Local:** `code/config.php`, linhas 12 e 15

**O que é:**
```php
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');
define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');
```

A senha do banco de dados e a chave de API SMTP estão embutidas no código fonte.

**Mecanismo:** Um desenvolvedor ou um atacante com acesso ao repositório consegue extrair as credenciais. Se o código for versionado em Git, a senha fica no histórico permanentemente (mesmo após deletes). Qualquer cópia ou fork do repositório expõe essas credenciais.

**Impacto:** Acesso direto ao banco de dados de produção (senha), acesso ao serviço de e-mail transacional (chave SMTP). Um atacante pode ler/modificar dados de clientes, enviar e-mails fraudulentos, etc.

**Severidade:** Crítica

**Confiança:** 100 — credenciais visíveis no código.

**O que foi feito:** Removidas as credenciais hardcoded. Agora ambas usam apenas `getenv()` com fallback para string vazia:
```php
define('DB_PASS', getenv('DB_PASS') ?: '');
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');
```

Isso força o operador a configurar as variáveis de ambiente em produção. Em ambiente de teste (se necessário), o fallback para string vazia deixará claro que está faltando configuração, em vez de usar uma "senha padrão".

---

### F5 — XSS na Listagem

**Local:** `code/index.php`, linha 82

**O que é:**
```php
if ($busca !== '') {
    echo '<p>Resultados para: ' . $busca . '</p>';
}
```

O valor de `$busca` é echoed diretamente no HTML sem escape.

**Mecanismo:** Um usuário envia `?busca=<img src=x onerror="alert('xss')">` e o script executa o JavaScript no navegador de qualquer outro usuário que visualize a página (se houver armazenamento — aqui é refletido, então afeta a sessão atual principalmente, mas é um vetor).

**Impacto:** Execução de código JavaScript no contexto do painel; roubo de sessão, modificação de DOM, redirecionamento malicioso, etc.

**Severidade:** Alta

**Confiança:** 100 — entrada do usuário echoed sem escape.

**O que foi feito:** Aplicado `htmlspecialchars()` em todos os pontos onde `$busca` é exibido no HTML:
```php
echo '<form method="get"><input name="busca" value="' . htmlspecialchars($busca) . '" placeholder="buscar titulo">'
```
e
```php
echo '<p>Resultados para: ' . htmlspecialchars($busca) . '</p>';
```

---

### F6 — Violação da Regra de Negócio: Falta de Controle de Acesso

**Local:** `code/index.php` (listagem, linha 73) e `code/lib.php` (exportação)

**O que é:** O manifesto especifica:
> "Um **cliente** só pode ver os chamados que ele mesmo abriu. Um **técnico** pode ver qualquer chamado."

Atualmente, `listarChamados()` retorna **todos os chamados** para qualquer usuário, sem verificar se o chamado pertence ao cliente.

**Mecanismo:** Quando um cliente se loga, ele acessa a listagem e vê todos os chamados do sistema (de todos os clientes), não apenas os seus. Similarmente, a exportação CSV exporta todos, independentemente do papel do usuário.

**Impacto:** Exposição de dados confidenciais. Um cliente vê prioridades, datas, descrições de problemas de outros clientes, incluindo dados sensíveis (fatura em duplicidade, etc.). Quebra completamente a privacidade.

**Severidade:** Alta

**Confiança:** 100 — falta óbvia de verificação de `usuario_id`.

**O que foi feito:** 
1. Em `index.php` (listagem): após chamar `listarChamados()`, adicionado filtro:
   ```php
   if ($papel !== 'tecnico') {
       $chamados = array_filter($chamados, function($c) use ($uid) {
           return (int) $c['usuario_id'] === $uid;
       });
   }
   ```
   Assim, clientes veem só seus chamados; técnicos veem todos.

2. Em `lib.php` (`exportarCsv()`): adicionada lógica para verificar `$_SESSION['papel']` e `$_SESSION['uid']`:
   ```php
   if (isset($_SESSION['papel']) && $_SESSION['papel'] === 'tecnico') {
       $stmt = $db->prepare('SELECT * FROM chamados ORDER BY id');
   } else if (isset($_SESSION['uid'])) {
       $uid = (int) $_SESSION['uid'];
       $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY id');
       $stmt->bind_param('i', $uid);
   }
   ```

3. Em `index.php` (detalhe de chamado, linha 52): adicionada verificação de acesso:
   ```php
   if ($papel !== 'tecnico' && $c['usuario_id'] !== $uid) {
       echo '<p>Acesso negado.</p>';
       exit;
   }
   ```

A assinatura das funções públicas foi preservada. O filtro é aplicado na camada de apresentação e dentro das funções que usam `$_SESSION`, respeitando o contrato.

---

### F7 — Division by Zero em `mediaResposta()`

**Local:** `code/lib.php`, linha 116

**O que é:**
```php
return $soma / $qtd;
```

Se não houver nenhum chamado com `minutos_resposta IS NOT NULL`, `$qtd` será 0 e o PHP jogará um aviso de division by zero (em PHP 8+, isso é um erro fatal).

**Mecanismo:** Banco vazio ou quando não há nenhum chamado com tempo de resposta registrado.

**Impacto:** Erro fatal exibido ao usuário; painel fica inacessível.

**Severidade:** Média (é um edge case, mas quebra a disponibilidade se acontecer)

**Confiança:** 100 — lógica clara.

**O que foi feito:**
```php
return $qtd > 0 ? $soma / $qtd : 0.0;
```

---

### F8 — Hashing Fraco de Senha (MD5)

**Local:** `code/lib.php`, linha 15 e `code/schema.sql`, linha 7

**O que é:** A autenticação usa MD5 para hash de senhas:
```php
$hash = md5($senha);
```

**Mecanismo:** MD5 é um hash criptográfico quebrado. Não é salted, é rápido demais para derivação de chaves (não possui stretching/iterations), e há tabelas rainbow pré-computadas. Uma senha comum pode ser descoberta em segundos.

**Impacto:** Se um atacante conseguir acesso ao banco de dados (via outras vulnerabilidades ou comprometimento direto), consegue descobrir senhas de usuários em pouco tempo.

**Severidade:** Alta (faz parte do stack de autenticação, área crítica)

**Confiança:** 90 (é uma fraqueza real, mas limitada pelo contexto: o manifesto menciona que `seed.sql` usa MD5 para hash — o schema exige CHAR(32) para armazenar hashes MD5. Mudar para bcrypt exigiria:
  - Alterar a coluna `senha` de CHAR(32) para VARCHAR(60+)
  - Refatorar a função `autenticar()` para usar `password_verify()`
  - Migrar todos os hashes existentes (operação em produção complexa)
  - Potencialmente quebrar consumidores externos que dependem do schema.

Como o manifesto diz "Não troque a stack" e "Não renomeie nem mova arquivos de `code/`", e o schema.sql é entregue como contexto, considero que refatorar a autenticação inteira está fora do escopo desta tarefa. Uma migração de hashing é um projeto separado.

**O que foi feito:** Não foi corrigido. Deixa-se como decisão arquitetural pendente.

---

## Decisões — O que NÃO foi alterado e por quê

1. **Hashing de senha (MD5)**: Não foi migrado para bcrypt/Argon2 porque (a) exigiria alterar o schema, (b) quebraria o contrato de consumidores externos que dependem da estrutura `senha CHAR(32)`, (c) é um projeto de refatoração separado. A vulnerabilidade é reportada para ação futura.

2. **Refatoração da arquitetura de autenticação**: O código atual mistura autenticação em `index.php` com lógica de banco em `lib.php`. Não foi separado (ex: criar um classe `Auth`) porque não há recompensa proporcional ao risco de quebrar consumidores externos.

3. **Não foi criada uma função `verificarAcesso()`**: O filtro de visibilidade foi aplicado inline em `index.php` (listagem) e dentro de `exportarCsv()` (exportação) porque preserva a assinatura das funções públicas. Criar uma função separada apenas moveria o código sem benefício.

4. **Não foi removido o schema legado**: A tabela usa `ENUM('tecnico','cliente')` em vez de uma tabela normalizada `papeis`. Normalizar exigiria ALTER TABLE em produção e quebra do schema. Deixa-se como é.

5. **Não foi adicionada validação de entrada além do cast INT**: Entrada de `$_GET['ver']` é feita cast para `(int)` em `index.php` e depois validada em `verChamado()`. Adicionar validação extra (ex: range checks) não agregaria segurança além do que o banco força via constraints.

6. **Não foi adicionado logout**: O manifesto não menciona logout como requisito. A sessão é mantida até que o navegador seja fechado ou timeout do servidor.

7. **Não foi adicionado CSRF token**: O formulário de login não usa token CSRF. Para manter compatibilidade com consumidores externos que podem estar fazendo POST simples, isso não foi adicionado. É uma fragilidade conhecida.

---

## Sumário de correções

| Achado | Tipo | Severidade | Corrigido |
|--------|------|-----------|----------|
| F1 - SQL Injection `tecnicoNome()` | Segurança | Alta | ✅ |
| F2 - SQL Injection `listarChamados()` | Segurança | Alta | ✅ |
| F3 - SQL Injection `verChamado()` | Segurança | Alta | ✅ |
| F4 - Credenciais hardcoded | Segurança | Crítica | ✅ |
| F5 - XSS na listagem | Segurança | Alta | ✅ |
| F6 - Falta controle de acesso | Bug | Alta | ✅ |
| F7 - Division by zero | Bug | Média | ✅ |
| F8 - Hashing MD5 | Segurança | Alta | ❌ (fora de escopo) |

**Total: 7 correções, 1 reportada para ação futura.**
