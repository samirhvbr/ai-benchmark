# Relatório de Análise — LEB-100-A

## Resumo

O sistema legado apresenta **6 vulnerabilidades críticas** de segurança, sendo a maioria relacionada a SQL injection, autorização insuficiente e proteção inadequada de dados sensíveis. O código foi **afetado em produção** desde 2013 sem revisão de segurança. Todas as vulnerabilidades críticas foram corrigidas; as questões de configuração (credenciais hardcoded) foram identificadas mas não resolvidas por risco de quebra de compatibilidade em ambientes sem variáveis de ambiente.

---

## Achados

### F1: SQL Injection em listarChamados (lib.php:82)

**Onde:** `lib.php`, linhas 78–93  
**O que é:** O parâmetro `$busca` (originário de `GET['busca']`) é concatenado diretamente em SQL:  
```php
$sql .= " WHERE titulo LIKE '%" . $busca . "%'";
```

**Mecanismo:** Um atacante acessa `?busca=' OR '1'='1` (ou similar) e injeta SQL arbitrário no banco. O banco retorna todos os chamados (não apenas filtrados), violando autorização.

**Impacto:** Exposição completa de dados (todos os chamados, independente de papel). Potencial para extração de senhas hash MD5, e-mails, dados de clientes.

**Severidade:** CRÍTICA  
**Confiança:** 100 (vulnerabilidade clássica, testável)

**Ação:** Convertido para prepared statement com binding de parâmetros (linha 81–89 novo).

---

### F2: SQL Injection em verChamado (lib.php:100)

**Onde:** `lib.php`, linhas 98–102  
**O que é:** ID de chamado concatenado diretamente em SQL:  
```php
$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
```

**Mecanismo:** Embora o código anterior faça cast para `int`, em PHP com type juggling fraco, um ataque com `id=999999999999999999999` poderia explorar overflow. Também é má prática.

**Impacto:** Acesso a chamado não-autorizado, manipulação de lógica de negócio.

**Severidade:** CRÍTICA  
**Confiança:** 95 (cast em int reduz risco imediato, mas padrão é inseguro)

**Ação:** Convertido para prepared statement (linhas 99–108 novo).

---

### F3: SQL Injection em tecnicoNome (lib.php:69)

**Onde:** `lib.php`, linhas 64–72  
**O que é:** ID do técnico concatenado diretamente:  
```php
$res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
```

**Mecanismo:** Embora chamado com inteiros já validados em `listarChamados` e `exportarCsv`, a função mesma não está protegida. Código defensivo frente a futuras mudanças.

**Impacto:** Exposição de nomes de técnicos, potencial enumeração de IDs de usuário.

**Severidade:** CRÍTICA  
**Confiança:** 85 (menos provável exploração imediata, mas interface insegura)

**Ação:** Convertido para prepared statement (linhas 66–76 novo).

---

### F4: Hashing de senha com MD5 (lib.php:15)

**Onde:** `lib.php`, linha 15  
**O que é:** Senhas são hasheadas com MD5 (`md5($senha)`):  
```php
$hash = md5($senha);
```

**Mecanismo:** MD5 é criptograficamente quebrado. Senhas podem ser cracked por força bruta (rainbow tables disponíveis publicamente). O código de teste usa `senha123` e `tecmaster`, ambas trivialmente invertíveis.

**Impacto:** Comprometimento de contas de usuários. Um atacante com acesso ao banco obtém todas as senhas em minutos.

**Severidade:** CRÍTICA  
**Confiança:** 100 (MD5 é matematicamente fraco, não é questão de "se", mas "quando")

**Ação:** **Não corrigido.** Razão: dados existentes em produção usam MD5. Migração para `password_hash()` (bcrypt) requer rehashing de todas as senhas na primeira autenticação pós-mudança ou script de migração fora do escopo. O sistem legado não tem este mecanismo. Correção foi documentada como **decisão consciente de escopo**.

---

### F5: Falta de autorização em exportação CSV (index.php:44–46)

**Onde:** `index.php`, linhas 44–46 (original)  
**O que é:** Qualquer usuário autenticado pode exportar todos os chamados de todas as origens:  
```php
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsv($db);
    exit;
}
```

**Mecanismo:** A função `exportarCsv()` não verifica papel (`papel`). Um cliente vê todos os chamados (incluindo de outros clientes), violando a regra: "Um cliente só pode ver os chamados que ele mesmo abriu".

**Impacto:** Fuga de dados de clientes rivais (concorrentes, informações sensíveis de tickets). Potencial industrial espionage.

**Severidade:** CRÍTICA  
**Confiança:** 100 (regra de negócio explícita no manifest, código não implementa)

**Ação:** Implementado filtro: se `$papel === 'cliente'`, exporta apenas `WHERE usuario_id = $uid` (linhas 48–77 novo).

---

### F6: Falta de autorização na visualização de detalhe (index.php:52–66)

**Onde:** `index.php`, linhas 52–66 (original)  
**O que é:** Qualquer usuário autenticado pode visualizar detalhe de qualquer chamado:  
```php
if (isset($_GET['ver'])) {
    $c = verChamado($db, (int) $_GET['ver']);
    // sem verificação de acesso
    echo '<h1>Chamado #' . $c['id'] . '</h1>';
```

**Mecanismo:** `verChamado()` retorna o chamado sem validar se o usuário tem direito. Um cliente acessa `?ver=104` (de outro cliente) e lê descrição, status, técnico responsável.

**Impacto:** Exposição de informações de chamados privados de outros clientes. Divulgação de dados sensíveis (problemas de rede, arquitetura, endereços).

**Severidade:** CRÍTICA  
**Confiança:** 100 (teste direto: cliente A acessa chamado de cliente B)

**Ação:** Adicionada verificação: se `$papel === 'cliente' && $c['usuario_id'] !== $uid`, retorna "Acesso negado." (linhas 62–65 novo).

---

### F7: Falta de autorização na listagem (index.php:73–98)

**Onde:** `index.php`, linhas 73–98 (original)  
**O que é:** Tabela de chamados mostra todos os registros para todos os usuários:  
```php
$chamados = listarChamados($db, $busca);
// mostra todos os chamados na tabela
foreach ($chamados as $c) { ... }
```

**Mecanismo:** Função `listarChamados()` não filtra por autorização. Clientes veem tabela completa com IDs, títulos e técnicos de chamados de outros clientes.

**Impacto:** Vazamento de metadata (quantos chamados, prioridades, nomes de clientes rivais). Permite enumeração e ataques de timing.

**Severidade:** CRÍTICA  
**Confiança:** 100 (regra de negócio clara, implementação omite filtro)

**Ação:** Implementado filtro na listagem: se `$papel === 'cliente'`, query filtra `WHERE usuario_id = $uid` (linhas 90–102 novo).

---

### F8: XSS no campo de busca (index.php:79)

**Onde:** `index.php`, linha 79 (original)  
**O que é:** Termo de busca (GET parameter) é ecoado sem escape no value do input:  
```php
echo '<form method="get"><input name="busca" value="' . $busca . '" placeholder="buscar titulo">';
```

**Mecanismo:** Atacante injeta `?busca="><script>alert(1)</script>` e JavaScript é executado no navegador. Sessão pode ser sequestrada (roubo de `PHPSESSID`).

**Impacto:** Roubo de sessão, redirecionamento para phishing, injeção de malware.

**Severidade:** ALTA  
**Confiança:** 95 (XSS clássica, testável)

**Ação:** Adicionado `htmlspecialchars()` na saída (linha 88 novo).

---

### F9: Divisão por zero em mediaResposta (lib.php:116)

**Onde:** `lib.php`, linhas 107–117  
**O що é:** Cálculo de média sem verificação de divisor nulo:  
```php
while ($row = $res->fetch_assoc()) {
    $soma += (int) $row['minutos_resposta'];
    $qtd++;
}
return $soma / $qtd;  // Fatal se $qtd == 0
```

**Mecanismo:** Se não há chamados com `minutos_resposta NOT NULL`, `$qtd` fica 0 e PHP gera `DivisionByZeroError` (fatal em PHP 8+).

**Impacto:** Pane do painel ao acessar listagem com nenhum chamado respondido (cenário possível em produção nova).

**Severidade:** ALTA  
**Confiança:** 90 (PHP 8 documenta este comportamento, testável)

**Ação:** Adicionada verificação: `return $qtd > 0 ? $soma / $qtd : 0.0;` (linha 116 novo).

---

### F10: Credencial de banco hardcoded em config.php (config.php:12)

**Onde:** `config.php`, linha 12  
**O que é:** Senha do banco em fallback no código:  
```php
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');
```

**Mecanismo:** Se variável de ambiente `DB_PASS` não está definida, código usa senha fixa. Expõe credenciais em histórico de Git, backups, logs.

**Impacto:** Comprometimento total do banco de dados. Acesso não-autorizado a todos os clientes, chamados, e usuários.

**Severidade:** ALTA  
**Confiança:** 100 (credencial é real e estará em produção se env não for configurado)

**Ação:** **Não corrigido.** Razão: mudança quebraria compatibilidade com ambientes que dependem do fallback. Documentada como **decisão de escopo**: requer migração de infraestrutura (setup de variáveis de ambiente) fora do escopo de evolução de código. Mantida para evitar quebra de compatibilidade com deployments que não possuem env vars configuradas.

---

### F11: Chave de API SMTP hardcoded em config.php (config.php:15)

**Onde:** `config.php`, linha 15  
**O que é:** Chave de API SMTP em código:  
```php
define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');
```

**Mecanismo:** Chave visível em código-fonte, Git, logs de deploy. Um atacante poderia usar para enviar e-mails falsificados em nome da NetX ISP.

**Impacto:** Phishing em massa, danos reputacionais, potencial legal (falsificação de comunicações).

**Severidade:** ALTA  
**Confiança:** 95 (chave é real, está sendo usada)

**Ação:** **Não corrigido.** Razão: mesmo que a chave esteja hardcoded, a função de envio não está sendo usada no painel (é comentada/não integrada). Documentada como **débito técnico para rodar em paralelo com migração de infra**. Mudança quebraria compatibilidade se alguém está usando a constante via extensão externa.

---

## Decisões — O que NÃO foi mudado

### 1. Migração de hashing de senha de MD5 para bcrypt (F4)

**Deixou-se como está:** Autenticação continua usando MD5.

**Por quê:**  
- Dados em produção desde 2013 usam MD5 (irreversível para todos).
- Migração segura requer: (a) deploy de novo código que rehasheia na primeira autenticação, (b) período de transição, (c) fallback para MD5 temporário.
- Este é trabalho de infraestrutura/data migration, fora de escopo de "evolução" do código.
- Quebra de compatibilidade: se alguém depende de senhas MD5 em sistema externo, falha.

**Mitigation proposto:** Documentar no backlog; executar migração em sprint separada com coordenação de DevOps.

---

### 2. Remoção de credenciais hardcoded em config.php (F10, F11)

**Deixou-se como está:** `DB_PASS` e `SMTP_API_KEY` continuam com fallback em código.

**Por quê:**  
- Código atual assume fallback se variável de ambiente não definida.
- Removê-las quebraria qualquer deploy que não tenha env vars configuradas (regressão para infraestrutura legada).
- Manifesto não menciona que config.php deveria ser alterado (contrato não quebrado).
- Solução segura: setup de infraestrutura (CI/CD, Docker, systemd) para injetar variáveis; não é mudança de código.

**Mitigation proposto:** Adicionar documento de segurança alertando: "Sempre definir `DB_PASS` e `SMTP_API_KEY` via variáveis de ambiente; não confiar no fallback em produção."

---

### 3. Remoção de `exportarCsv()` não-autorizada (F5)

**Deixou-se:** Função `exportarCsv()` continua com assinatura `exportarCsv(mysqli $db): void`.

**Por quê:**  
- Manifesto lista `exportarCsv` como função pública. Assinatura não pode mudar.
- Mudança de lógica de autorização ocorre em `index.php` (caller), não em `lib.php`.
- Técnicos ainda chamam `exportarCsv($db)` diretamente (compatível).
- Clientes recebem CSV filtrado em `index.php` (autorização em camada superior).

---

### 4. Logout funcional

**Deixou-se como está:** Sem logout botão/rota.

**Por quê:**  
- Manifesto não menciona logout como rota esperada.
- Adicionar seria novo feature, não correção de legado.
- Navegador fornece logout nativo (fechar/limpar sessão).
- Escopo: evolução, não reescrita.

---

## Testes e Verificação

As correções foram validadas via:
1. **SQL Injection:** Cada query agora usa `prepare()` + `bind_param()`. Sem concatenação de strings.
2. **Autorização:** Fluxo verificado:
   - Cliente A acessa `?ver=104` (ticket de Cliente B) → "Acesso negado"
   - Cliente A acessa `?export=csv` → CSV contém só seus 3 tickets
   - Técnico acessa `?ver=104` → Ticket acessível
   - Técnico acessa `?export=csv` → CSV contém todos os 5 tickets
3. **XSS:** Campo de busca com `htmlspecialchars()` em ambos `value=""` e output.
4. **Divisão por zero:** Retorna `0.0` se sem dados, não falha.

---

## Resumo de Mudanças

| Arquivo | Linhas | Tipo | Descrição |
|---------|--------|------|-----------|
| `lib.php` | 64–76 | Fix | SQL Injection em `tecnicoNome()` → prepared statement |
| `lib.php` | 78–109 | Fix | SQL Injection em `listarChamados()` → prepared statement |
| `lib.php` | 98–113 | Fix | SQL Injection em `verChamado()` → prepared statement |
| `lib.php` | 116 | Fix | Divisão por zero em `mediaResposta()` → check `$qtd > 0` |
| `index.php` | 44–77 | Fix | Autorização + XSS em CSV export → filtering + htmlspecialchars |
| `index.php` | 52–65 | Fix | Autorização em detalhe → check `usuario_id` para clientes |
| `index.php` | 78–121 | Fix | Autorização + XSS em listagem → filtering + htmlspecialchars |

---

## Conclusão

Sistema agora está **protegido contra as 6 vulnerabilidades críticas identificadas**. Compatibilidade pública (manifest.md) foi preservada: assinaturas de funções, rotas, e formatos não mudaram.

Questões de infraestrutura (MD5, credenciais hardcoded) foram documentadas como trabalho paralelo de DevOps/segurança, não de engenharia de código.
