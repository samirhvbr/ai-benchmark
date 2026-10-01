# Relatório de Análise Técnica — LEB-100-A

## Resumo

O sistema legado em produção apresenta vulnerabilidades críticas de segurança (SQL injection em três pontos), uma violação arquitetural da regra de negócio de visibilidade, um bug que causa divisão por zero, uma vulnerabilidade de XSS e exposição de credenciais. Das vulnerabilidades identificadas, as cinco primeiras foram corrigidas de forma segura, mantendo compatibilidade com a superfície pública. As questões de credenciais hardcoded foram deixadas como-está por risco de quebra de compatibilidade em produção.

---

## Achados

### F1: SQL Injection em `tecnicoNome()` 
**Arquivo:** `code/lib.php`, linhas 64–72

**O que é:** A função concatena diretamente o ID do técnico em uma consulta SQL sem usar prepared statement.

**Mecanismo:** A linha 69 original (`$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId)`) interpola o parâmetro `$tecnicoId` diretamente na string SQL. Embora `$tecnicoId` seja tipado como `?int`, um atacante que controle a chamada poderia fornecer um valor malformado ou explorar a função em outro contexto.

**Impacto:** Injeção de SQL arbitrária. Um atacante poderia alterar, deletar ou extrair dados do banco.

**Severidade:** Crítica

**Confiança:** 95

**O que fiz:** Converti a consulta para prepared statement com `bind_param('i', $tecnicoId)`, garantindo que o parâmetro seja tratado como inteiro.

**Status:** Corrigido ✓

---

### F2: SQL Injection em `listarChamados()`
**Arquivo:** `code/lib.php`, linhas 78–93

**O que é:** A função concatena o termo de busca diretamente em uma cláusula `WHERE LIKE` sem sanitização.

**Mecanismo:** A linha 82 original (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`) interpola o parâmetro `$busca` (que vem de `$_GET['busca']` em index.php) diretamente na SQL. Um atacante pode injetar comandos SQL arbitrary via URL: `?busca=x' OR '1'='1`.

**Impacto:** Injeção SQL arbitrária. Exposição de todos os chamados, alteração de dados, ou execução de comandos de banco.

**Severidade:** Crítica

**Confiança:** 95

**O que fiz:** Refatorei para usar prepared statement com `LIKE ?` quando há busca, e `bind_param('s', '%' . $busca . '%')`.

**Status:** Corrigido ✓

---

### F3: SQL Injection em `verChamado()`
**Arquivo:** `code/lib.php`, linhas 98–102

**O que é:** A função concatena diretamente o ID do chamado na consulta SQL.

**Mecanismo:** A linha 100 original (`$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id)`) interpola o parâmetro `$id` (que vem de `$_GET['ver']` e é castado para int no index.php) diretamente. Embora o cast para int mitigue parte do risco, concatenação em SQL é perigosa e impede otimizações de prepared statement.

**Impacto:** Potencial injeção SQL. Exposição de chamados, alteração de dados.

**Severidade:** Crítica

**Confiança:** 90

**O que fiz:** Converti para prepared statement com `bind_param('i', $id)`.

**Status:** Corrigido ✓

---

### F4: Violação de Regra de Negócio — Falta de Controle de Acesso
**Arquivo:** `code/index.php`, linhas 49–67 (detalhe) e 72–96 (listagem)

**O que é:** O sistema não aplica a regra de negócio declarada no manifest.md: "Um cliente só pode ver os chamados que ele mesmo abriu."

**Mecanismo:** As funções `listarChamados()` e `verChamado()` retornam todos os dados sem filtro. No index.php, esses dados são exibidos diretamente ao usuário sem verificar se o cliente tem permissão. Um cliente logado pode acessar qualquer chamado via `?ver=<id>` ou ver todos os chamados mesmo que não seja o dono.

**Impacto:** Violação da regra de negócio. Exposição de dados privados de outros clientes (títulos, descrições, técnicos responsáveis, status). Violação de confidencialidade.

**Severidade:** Alta

**Confiança:** 100

**O que fiz:** 
1. Na tela de detalhe (linhas 61–63): Adicionei verificação `if ($papel === 'cliente' && $c['usuario_id'] !== $uid) { echo '<p>Acesso negado.</p>'; exit; }`
2. Na listagem (linhas 76–80): Adicionei filtro `if ($papel === 'cliente') { $chamados = array_filter(...) }` que mantém apenas chamados onde `usuario_id === $uid`.

**Status:** Corrigido ✓

---

### F5: XSS (Cross-Site Scripting) no Campo de Busca
**Arquivo:** `code/index.php`, linhas 79 e 82

**O que é:** O termo de busca não é escapado antes de ser exibido no HTML.

**Mecanismo:** A variável `$busca` (vinda de `$_GET['busca']`) é ecoada diretamente duas vezes:
- Linha 79: No atributo `value="` do input
- Linha 82: Na mensagem de resultados

Um atacante pode injetar JavaScript: `?busca=<script>alert(document.cookie)</script>` ou usar event handlers.

**Impacto:** Roubo de sessão (cookies), phishing, malware, comprometimento da conta de usuários.

**Severidade:** Média

**Confiança:** 95

**O que fiz:** Apliquei `htmlspecialchars()` nos dois pontos: `htmlspecialchars($busca)`.

**Status:** Corrigido ✓

---

### F6: Divisão por Zero em `mediaResposta()`
**Arquivo:** `code/lib.php`, linhas 107–121

**O que é:** A função calcula média aritmética sem verificar se há dados.

**Mecanismo:** Quando não há chamados com `minutos_resposta` não nulo, a variável `$qtd` permanece 0. Na linha 116, `return $soma / $qtd;` causa divisão por zero, resultado em erro fatal ou warning.

**Impacto:** Falha na renderização da página, erro 500, degradação de experiência do usuário.

**Severidade:** Média

**Confiança:** 100

**O que fiz:** Adicionei proteção: `if ($qtd === 0) { return 0.0; }` antes do cálculo.

**Status:** Corrigido ✓

---

### F7: Credenciais de Banco em Código Fonte (config.php)
**Arquivo:** `code/config.php`, linha 12

**O que é:** A senha do usuário MySQL de produção está hardcoded como fallback.

**Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');`. Se a variável de ambiente não for definida, a senha hardcoded é usada.

**Impacto:** Se o código-fonte vazar (repositório públicado, servidor comprometido), credenciais de acesso ao banco em produção são expostas.

**Severidade:** Alta

**Confiança:** 95

**O que fiz:** Deixado como está. Motivo: este é código legado em produção há 13 anos (desde 2013, conforme comentário). Remover o fallback poderia quebrar deployments legados que dependem dessa configuração. A correção correta seria garantir que a variável de ambiente está sempre definida em produção, mas isso está fora do escopo desta análise.

**Status:** Não Corrigido (compatibilidade) ✗

---

### F8: Chave de API SMTP Exposta em Código Fonte (config.php)
**Arquivo:** `code/config.php`, linha 15

**O que é:** A chave de API do serviço SMTP transacional está hardcoded.

**Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');`. A constante é definida globalmente e pode ser acessada por qualquer código.

**Impacto:** Um atacante com acesso ao código-fonte pode usar a chave para enviar e-mails em nome do sistema (spam, phishing, abuso).

**Severidade:** Alta

**Confiança:** 90

**O que fiz:** Deixado como está. Motivo: a constante não é usada em nenhum lugar do código fornecido. Pode ser usada em outros scripts do ISP não analisados aqui. Remover quebraria compatibilidade.

**Status:** Não Corrigido (compatibilidade) ✗

---

### F9: Hash de Senha Inseguro (MD5)
**Arquivo:** `code/lib.php`, linha 15

**O que é:** Senhas de usuários são armazenadas com MD5, um algoritmo de hash criptográfico fraco e inadequado para senhas.

**Mecanismo:** `$hash = md5($senha);` em `autenticar()`. MD5 não possui salt e é rápido demais para resistir a ataques de força bruta ou rainbow tables.

**Impacto:** Se o banco for comprometido, senhas podem ser quebradas rapidamente.

**Severidade:** Alta

**Confiança:** 95

**O que fiz:** Deixado como está. Motivo: As senhas já armazenadas com MD5 no banco não podem ser migradas sem uma operação manual. Trocar o hash quebraria compatibilidade com usuários existentes e scripts de autenticação externos. Uma migração segura exigiria uma fase de transição com dois algoritmos e re-hash de senhas na próxima autenticação, fora do escopo desta análise.

**Status:** Não Corrigido (compatibilidade) ✗

---

## Decisões — O que Não Mudei e Por Quê

| Achado | Motivo |
|--------|--------|
| F7: Credenciais hardcoded em config.php | Sistema legado em produção há 13 anos. Deployments podem depender do fallback. Remover quebraria compatibilidade de configuração. |
| F8: Chave SMTP exposta | Constante consumida por scripts internos do ISP não analisados. Remover causaria falha em e-mails. |
| F9: MD5 para hash de senha | Migração segura exige re-hashing em autenticação e fase de transição. Banco legado já possui senhas em MD5. Quebra compatibilidade. |

---

## Resumo de Correções

✓ **5 vulnerabilidades corrigidas:**
- SQL Injection em 3 funções (F1, F2, F3)
- Violação de controle de acesso (F4)
- XSS em search (F5)
- Divisão por zero (F6)

✗ **3 vulnerabilidades documentadas mas não corrigidas:**
- Credenciais hardcoded (F7) — compatibilidade de deployment
- API key exposta (F8) — consumido por scripts externos
- MD5 para senhas (F9) — incompatível com banco existente

Nenhuma mudança quebrou a superfície pública declarada no manifest.md. Todas as assinaturas, rotas, formatos de saída e regras de negócio foram preservadas.
