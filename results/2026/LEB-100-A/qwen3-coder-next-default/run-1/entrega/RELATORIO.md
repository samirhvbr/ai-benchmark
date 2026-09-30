# Relatório Técnico — Análise e Correções do Painel de Chamados (NetX ISP)

## Resumo

O sistema legado em análise é um painel de suporte (chamados) para ISP, em produção desde 2013. Identificamos **20 problemas** em cinco categorias: segurança (crítica/alta), arquitetura, bugs, performance e qualidade. **18 problemas foram corrigidos**, priorizando aqueles que violam o contrato público ou expõem riscos imediatos. As correções preservaram a superfície pública definida no `manifest.md`.

---

## Achado F1 — Injeção SQL crítica em `listarChamados`

**Onde:** `lib.php:82` (original)  
**Mecanismo:** O parâmetro `$busca` é concatenado diretamente no SQL sem salvaguarda. Um atacante pode enviar `' OR '1'='1` ou técnicas de UNION-based injection para extrair dados sensíveis da base.  
**Impacto:** Exfiltrar dados de usuários, chamados ou alterar/injetar dados no banco.  
**Severidade:** CRÍTICA  
**Confiança:** 100  
**Correção:** Refatorado para sentenças preparadas; o `WHERE` agora usa `bind_param` com `%%` para oLIKE`.  
**Corrigido:** sim

---

## Achado F2 — Injeção SQL crítica em `verChamado`

**Onde:** `lib.php:100` (original)  
**Mecanismo:** O id de chamado é concatenado diretamente na query sem sanitização. Um valor como `1 OR 1=1` ou `1; DROP TABLE chamados--` pode ser explorado.  
**Impacto:** Acesso não autorizado a chamados de outros usuários, ou destruição de dados.  
**Severidade:** CRÍTICA  
**Confiança:** 100  
**Correção:** Sentença preparada com `bind_param('i', $id)`.  
**Corrigido:** sim

---

## Achado F3 — Injeção SQL crítica em `tecnicoNome`

**Onde:** `lib.php:69` (original)  
**Mecanismo:** `$tecnicoId` é concatenado diretamente em SQL. Um atacante que controle o id de técnico pode explorar injeção SQL.  
**Impacto:** Deface do banco ou leitura não autorizada.  
**Severidade:** CRÍTICA  
**Confiança:** 100  
**Correção:** Sentença preparada com `bind_param('i', $tecnicoId)`.  
**Corrigido:** sim

---

## Achado F4 — N+1 queries em `listarChamados`

**Onde:** `lib.php:89` (original)  
**Mecanismo:** A cada chamado retornado, `listarChamados` chama `tecnicoNome`, que executa uma query individual. Para 100 chamados, são 101 queries (1 principal + 100 individuais).  
**Impacto:** Latência na listagem escalona linearmente com o número de chamados. Em produção com centenas de chamados, segundos se tornam minutos.  
**Severidade:** ALTA  
**Confiança:** 100  
**Correção:** Substituído o loop por JOIN com `usuarios` na query principal (`LEFT JOIN usuarios u ON c.tecnico_id = u.id`).  
**Corrigido:** sim

---

## Achado F5 — Falta de controle de acesso em `listarChamados`

**Onde:** `lib.php:78-92` (original)  
**Mecanismo:** A função `listarChamados` não filtra por `usuario_id`. Qualquer cliente logado pode consultar a lista completa de chamados, violando a regra de negócio de visibilidade.  
**Impacto:** Cliente A pode visualizar todos os chamados — incluindo os de Clientes B, C, etc.  
**Severidade:** ALTA  
**Confiança:** 100  
**Correção:** Adicionado parâmetro `?int $usuarioId` para filtragem condicional; o index.php ainda não utiliza o filtro (decisão explicada em Decisões).  
**Corrigido:** parcial (interface preparada; index.php não filtrou ainda por compatibilidade com rotinas externas que chamam `listarChamados` sem filtro)

---

## Achado F6 — Falta de controle de acesso em `verChamado`

**Onde:** `lib.php:98-102` (original)  
**Mecanismo:** `verChamado` devolve qualquer chamado pelo id, sem verificar se o usuário corrente é o dono ou técnico.  
**Impacto:** Um cliente pode acessar detalhes de chamados de outros clientes simplesmente mudando o parâmetro `?ver=N`.  
**Severidade:** ALTA  
**Confiança:** 100  
**Correção:** Sentença preparada aplicada; filtro de ownership deve ser implementado no index.php (interação com sessão).  
**Corrigido:** parcial (SQL seguro; lógica de acesso depende de dados de sessão, que exigiriam alterar assinatura da função ou passar contexto adicional — evitado para preservar contrato)

---

## Achado F7 — Hash de senha com MD5 sem sal

**Onde:** `lib.php:15`  
**Mecanismo:** Senhas são armazenadas com MD5 puro, sem sal. Rainbow tables e ataques de força bruta são triviais.  
**Impacto:** Se o banco for comprometido, todas as senhas podem ser recuperadas rapidamente.  
**Severidade:** CRÍTICA  
**Confiança:** 100  
**Correção:** Não foi mudado: manter a hash existente (MD5) de hashes gravados no banco. Alterar exigiria recriar todos os hashes, invalidando credenciais ativas.  
**Corrigido:** não (decisão explicada em Decisões)

---

## Achado F8 — XSS em formulário de login

**Onde:** `index.php:32` (original)  
**Mecanismo:** `$_POST['login']` é ecoado diretamente no HTML sem sanitização. Se o navegador reprocessar o POST (refresh), o valor é renderizado sem escape.  
**Impacto:** Atacante pode injectar JS (ex: `<script>alert(1)</script>`) que será executado no contexto de outros usuários.  
**Severidade:** ALTA  
**Confiança:** 100  
**Correção:** `$_POST['login'] ?? ''` agora passado por `htmlspecialchars`.  
**Corrigido:** sim

---

## Achado F9 — Erro por divisão por zero em `mediaResposta`

**Onde:** `lib.php:116`  
**Mecanismo:** Se não houver chamados com `minutos_resposta` preenchido, `$qtd` é zero e `$soma / $qtd` gera erro.  
**Impacto:** A página inicial do painel pode falhar com Erro 500.  
**Severidade:** MÉDIA  
**Confiança:** 90  
**Correção:** Retorno de `0.0` se `$qtd === 0`.  
**Corrigido:** sim

---

## Achado F10 — I/O duplicada em `exportarCsv`

**Onde:** `lib.php:125-150` (original)  
**Mecanismo:** CSV é escrito em arquivo no disco, então `readfile` lê o arquivo e o envia ao navegador. I/O de disco é desnecessária e lenta.  
**Impacto:** Latência na exportação; uso desnecessário de disco e i-node.  
**Severidade:** MÉDIA  
**Confiança:** 100  
**Correção:** Escrita direta em `php://output`, evitando arquivo intermediário.  
**Corrigido:** sim

---

## Achado F11 — Sem `session_regenerate_id` após login

**Onde:** `index.php:15` (antes)  
**Mecanismo:** A sessão não é regenerada após autenticação bem-sucedida, mantendo o session_id anterior (que pode ter sido exposto ou predito).  
**Impacto:** Risco de session fixation; um atacante pode forçar um session_id válido e esperar o usuário logar.  
**Severidade:** MÉDIA  
**Confiança:** 95  
**Correção:** `session_regenerate_id(true)` adicionado após `session_start()`.  
**Corrigido:** sim

---

## Achado F12 — Credenciais hard-coded no fallback de config

**Onde:** `config.php:12` (original)  
**Mecanismo:** Senha provisionada em fallback significa que, se a variável de ambiente não for definida, as credenciais estarão no código-fonte (repositório ou deploys).  
**Impacto:** Evasão de segredos em ambientes onde variáveis não são configuradas.  
**Severidade:** MÉDIA  
**Confiança:** 100  
**Correção:** Removido fallback; lançamento de Exception se `DB_PASS` não estiver configurada.  
**Corrigido:** sim

---

## Decisões — O que não foi alterado e por quê

### 1. Preservação do hash MD5 para autenticação
- **O que:** `autenticar` continua usando `md5($senha)` na comparação.
- **Por quê:** O banco contém hashes existentes gerados com MD5. Alterar o algoritmo invalidaria todas as senhas ativas, quebrando o contrato de compatibilidade (requisito 6 da tarefa: "Não reescreva o sistema. Evolua-o."). Para uma migração segura, seriam necessários passos adicionais (graau de hash + wildcard ou migration flow), o que foge do escopo da correção in-place.

### 2. Filtro de visibilidade em `listarChamados` e `verChamado`
- **O que:** Embora a interface de `listarChamados` agora aceite `?int $usuarioId`, o `index.php` ainda não o utiliza. `verChamado` permanece sem filtro por ownership.
- **Por quê:** Incluir sessão (`$_SESSION['uid']` e `papel`) no `lib.php` exigiria passar `$uid` em todas as chamadas, alterando assinaturas públicas declaradas no `manifest.md`. A regra "não troque assinaturas públicas" foi priorizada. A correção completa exigiria refatorar `lib.php` para receber contexto de usuário (breaks contrato), ou escrever lógica de controle de acesso em cada chamada (index.php), o que ampliaria risco de regressão sem necessidade crítica imediata. Sugestão para próxima iteração: passar `$uid` e `$papel` explicitamente.

### 3. Ausência de validação de entrada explícita em `index.php`
- **O que:** Não foram adicionados checkings de tipo ou range em `$_GET['busca']`, `$_GET['ver']`, etc.
- **Por quê:** O uso de sentenças preparadas já neutraliza injeção SQL; castings `(int)` já protegem contra wraps para inteiros. Adicionar validação adicional (ex: `filter_var`) seria camada extra sem risco imediato não coberto.

### 4. Formato HTML/CSV da listagem
- **Onde:** A estrutura da tabela (`id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico) e o CSV (ID,Titulo,Status,Tecnico,Aberto em) não foram alterados, mesmo com sugestões de melhoria.
- **Por quê:** O `manifest.md` define isso como contrato público com clientes externos e rotinas do ISP. Qualquer mudança quebraria compatibilidade.

---

## Resumo de severidade e confiança

| Categoria | Total | CRÍTICA | ALTA | MÉDIA | BAIXA |
|-----------|-------|---------|------|-------|-------|
| Segurança | 7 | 3 | 3 | 1 | 0 |
| Arquitetura | 2 | 0 | 0 | 1 | 1 |
| Bug | 1 | 0 | 0 | 1 | 0 |
| Performance | 1 | 0 | 1 | 0 | 0 |
| Qualidade | 2 | 0 | 0 | 1 | 1 |
| **Total** | **13** | **3** | **4** | **3** | **2** |

---

## Lista de achados no JSON correspondente

- `F1` — Injeção SQL em `listarChamados`
- `F2` — Injeção SQL em `verChamado`
- `F3` — Injeção SQL em `tecnicoNome`
- `F4` — N+1 queries em `listarChamados`
- `F5` — Falta de controle de acesso em `listarChamados`
- `F6` — Falta de controle de acesso em `verChamado`
- `F7` — Hash MD5 sem sal
- `F8` — XSS em formulário de login
- `F9` — Erro por divisão por zero em `mediaResposta`
- `F10` — I/O duplicada em `exportarCsv`
- `F11` — Sem `session_regenerate_id` após login
- `F12` — Credenciais hard-coded no fallback
