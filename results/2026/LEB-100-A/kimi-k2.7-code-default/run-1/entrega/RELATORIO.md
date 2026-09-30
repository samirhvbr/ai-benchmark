# RELATORIO.md — LEB-100-A

## Resumo

O Painel de Chamados NetX ISP é um sistema PHP/mysqli legado que funcionalmente atende ao contrato descrito no `manifest.md`, mas apresentava falhas críticas de segurança e um bug operacional. As principais correções foram:

- Eliminação de injeções SQL em três funções (`listarChamados`, `verChamado`, `tecnicoNome`).
- Implementação da regra de visibilidade cliente/técnico na listagem, detalhe e exportação CSV.
- Correção de divisão por zero no indicador de SLA.
- Sanitização do parâmetro `busca` contra XSS.
- Remoção de credenciais hardcoded.
- Troca da geração de CSV em disco por stream direto para `php://output`.
- Adição de `session_regenerate_id` após login.

A superfície pública (`lib.php`, rotas GET, HTML e CSV) foi preservada conforme o manifesto.

---

## Achados

### F1 — Injeção SQL em `listarChamados` (lib.php:82)

- **Onde:** `code/lib.php`, linha 82 (código original).
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** O termo de busca do usuário é concatenado literalmente na cláusula `WHERE titulo LIKE '%...%'`. Como não há prepared statement, um payload como `busca=' OR '1'='1` transforma a query em `WHERE titulo LIKE '%' OR '1'='1%'`, ignorando qualquer restrição de visibilidade e retornando todos os chamados.
- **Impacto:** Vazamento em massa de chamados, bypass de visibilidade, potencial para exfiltração de dados sensíveis de clientes.
- **O que foi feito:** Reescrevi `listarChamados` para usar prepared statement com `bind_param`, mantendo a busca por substring (`%termo%`). Adicionei uma função auxiliar `listarChamadosComVisibilidade` para aplicar a restrição de dono sem alterar a assinatura pública.

### F2 — Injeção SQL em `verChamado` (lib.php:100)

- **Onde:** `code/lib.php`, linha 100 (código original).
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A função concatena o `$id` diretamente em `SELECT * FROM chamados WHERE id = ' . $id`. Embora `index.php` faça cast para `int`, a própria função pública expõe uma query dinâmica. Consumidores futuros ou scripts internos podem passar dados não confiáveis.
- **Impacto:** Acesso arbitrário a chamados e, em cenários de chamada direta, injeção SQL.
- **O que foi feito:** Substituí a query por prepared statement com `bind_param('i', $id)`.

### F3 — Injeção SQL em `tecnicoNome` (lib.php:69)

- **Onde:** `code/lib.php`, linha 69 (código original).
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** O `tecnico_id` é concatenado em `SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`. Todos os chamadores atuais passam `int`, mas a função é pública e usada em laços de listagem/exportação.
- **Impacto:** Vetor adicional de injeção SQL, especialmente perigoso porque é chamada uma vez por chamado.
- **O que foi feito:** Prepared statement com `bind_param('i', $tecnicoId)`.

### F4 — Regra de visibilidade não implementada (index.php:53,73)

- **Onde:** `code/index.php`, linhas 53 e 73 (código original); indiretamente `code/lib.php`.
- **Categoria:** seguranca
- **Severidade:** critica
- **Confiança:** 100
- **Mecanismo:** A listagem chamava `listarChamados($db, $busca)` sem nenhum filtro de `usuario_id`, e o detalhe chamava `verChamado` sem verificar se o chamado pertence ao cliente logado. Qualquer cliente autenticado via `index.php` enxergava e acessava chamados de outros clientes.
- **Impacto:** Violação grave de privacidade: um cliente podia ler títulos, descrições e status de chamados de terceiros.
- **O que foi feito:** Criei `listarChamadosComVisibilidade` e `exportarCsvComVisibilidade` no `lib.php`. Em `index.php`, a listagem e o CSV usam essas funções passando `$uid` e `$papel`. A tela de detalhe verifica `if ($papel === 'cliente' && (int) $c['usuario_id'] !== $uid)` e retorna "não encontrado". As funções públicas originais (`listarChamados`, `exportarCsv`) continuam sem filtro para scripts externos do ISP.

### F5 — Divisão por zero em `mediaResposta` (lib.php:116)

- **Onde:** `code/lib.php`, linha 116 (código original).
- **Categoria:** bug
- **Severidade:** alta
- **Confiança:** 95
- **Mecanismo:** `return $soma / $qtd;` é executado mesmo quando nenhum chamado tem `minutos_resposta IS NOT NULL`. Nesse caso `$qtd = 0`, resultando em warning `Division by zero` e retorno `INF`.
- **Impacto:** Quebra visual do painel (“Tempo médio: INF min”) e poluição de logs; em instalações sem histórico de SLA, a página fica sem o indicador principal.
- **O que foi feito:** Retorno condicional: `$qtd > 0 ? $soma / $qtd : 0.0`.

### F6 — XSS refletido no parâmetro `busca` (index.php:79,82)

- **Onde:** `code/index.php`, linhas 79 e 82 (código original).
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** O valor de `$_GET['busca']` era reimpresso no atributo `value` do input de busca e no parágrafo “Resultados para:” sem escaping. Um link como `index.php?busca=<script>alert(document.cookie)</script>` executaria JavaScript no contexto da sessão do usuário.
- **Impacto:** Roubo de sessão, ações em nome do usuário, defacement parcial.
- **O que foi feito:** Apliquei `htmlspecialchars()` em todos os pontos onde `$busca` é renderizado.

### F7 — Credenciais sensíveis hardcoded em `config.php` (config.php:12,15)

- **Onde:** `code/config.php`, linhas 12 e 15 (código original).
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** A senha do banco de dados de produção (`N3tX@2013!prod`) e a chave da API SMTP (`netx-smtp-9f83e2c1a7b64d05`) estavam literais no arquivo-fonte, como fallback de `getenv`.
- **Impacto:** Exposição permanente de credenciais no repositório; qualquer pessoa com acesso ao código pode conectar-se ao banco de produção ou usar a API de e-mail.
- **O que foi feito:** Removi os fallbacks com valores reais. `DB_PASS` e `SMTP_API_KEY` agora dependem exclusivamente de variáveis de ambiente. Mantive fallbacks inofensivos para host/nome/usuário, com comentários alertando sobre o risco.

### F8 — `exportarCsv` grava arquivo temporário em disco compartilhado (lib.php:125-150)

- **Onde:** `code/lib.php`, linhas 125-150 (código original).
- **Categoria:** seguranca / arquitetura
- **Severidade:** media
- **Confiança:** 90
- **Mecanismo:** A função gravava `/var/www/painel/tmp/chamados.csv` com nome fixo, fechava o handle, enviava headers e depois usava `readfile`. Nome fixo permite condição de corrida entre requisições e possível leitura não autorizada se o diretório `tmp` for servido pelo web server.
- **Impacto:** Um cliente poderia, em teoria, capturar o CSV gerado para outro usuário; falha silenciosa (`return`) se o diretório não existir.
- **O que foi feito:** Reescrevi a exportação para escrever diretamente em `php://output`, sem persistir arquivo em disco. O cabeçalho exato exigido pelo manifesto (`ID,Titulo,Status,Tecnico,Aberto em`) é escrito como string literal, pois `fputcsv` escaparia `Aberto em` com aspas.

### F9 — Senhas armazenadas com MD5 sem salt (lib.php:15)

- **Onde:** `code/lib.php`, linha 15 (código original).
- **Categoria:** seguranca
- **Severidade:** alta
- **Confiança:** 100
- **Mecanismo:** `md5($senha)` é computacionalmente barato e vulnerável a rainbow tables/ataques de força bruta. O schema.sql também confirma `CHAR(32)` para hash MD5.
- **Impacto:** Comprometimento em massa de contas caso a tabela `usuarios` vaze.
- **O que foi feito:** Não alterado. Ver seção **Decisões**.

### F10 — Session fixation por falta de regeneração de ID (index.php:25)

- **Onde:** `code/index.php`, linha 25 (código original).
- **Categoria:** seguranca
- **Severidade:** media
- **Confiança:** 85
- **Mecanismo:** Após autenticação bem-sucedida, o session ID não era regenerado. Um atacante que conhecesse o SID antes do login poderia assumir a sessão autenticada.
- **Impacto:** Ataque de session fixation.
- **O que foi feito:** Adicionei `session_regenerate_id(true)` imediatamente após a autenticação.

### F11 — `formatarStatus` mapeia valores fora do contrato como "Resolvido" (lib.php:28-34)

- **Onde:** `code/lib.php`, linhas 28-34 (código original).
- **Categoria:** qualidade
- **Severidade:** baixa
- **Confiança:** 80
- **Mecanismo:** A função retorna `"Resolvido"` para qualquer valor diferente de 1 e 2. O manifesto define apenas os valores 1, 2 e 3, mas dados corrompidos ou futuros valores seriam mascarados como resolvidos.
- **Impacto:** Relatórios gerenciais podem classificar status inválidos como resolvidos.
- **O que foi feito:** Não alterado. Ver seção **Decisões**.

---

## Decisões — o que não mudei e por quê

1. **Não migrei de MD5 para bcrypt/Argon2.**  
   O schema do banco usa `CHAR(32)` para hashes MD5 e os dados de teste (`seed.sql`) dependem de senhas MD5. Trocar o algoritmo exigiria alterar o schema, os seeds e potencialmente invalidar logins de usuários existentes. Em produção isso deve ser feito em uma migração planejada (por exemplo, re-hash no primeiro login), não como correção pontual.

2. **Não adicionei rate limiting ou proteção contra brute force.**  
   Embora desejável, isso exige um mecanismo de armazenamento (memória, cache ou banco) e uma política de bloqueio que foge ao escopo de uma manutenção evolutiva. O login continua vulnerável a tentativas em massa.

3. **Não alterei `formatarStatus` para rejeitar valores inválidos.**  
   O manifesto descreve o comportamento esperado para 1, 2 e 3. Mudar o retorno para status 0, 4 etc. poderia quebrar relatórios gerenciais que já lidam com a string `"Resolvido"` para valores inesperados. Mantive o comportamento legado.

4. **Não troquei mysqli por PDO nem adicionei framework.**  
   O manifesto proíbe explicitamente trocar a camada de acesso a dados. Todas as correções usam `mysqli` e prepared statements.

5. **Não reescrevi a interface HTML.**  
   Preservada a estrutura exigida: tabela com `id="tabela-chamados"`, colunas na ordem correta, links `index.php?ver=<id>` e formulário de busca.

6. **Mantive a constante `EXPORT_DIR`.**  
   Embora `exportarCsv` não a utilize mais, removê-la poderia quebrar scripts externos que a referenciam. Deixei-a disponível, com fallback vazio via variável de ambiente.

7. **Não alterei a assinatura das funções públicas.**  
   `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` mantêm exatamente os parâmetros e tipos do manifesto. Adicionei funções auxiliares (`listarChamadosComVisibilidade`, `exportarCsvComVisibilidade`) para implementar a visibilidade sem mudar as funções consumidas externamente.

---

## Verificação

Foram executados testes automatizados cobrindo:

- Autenticação de clientes e técnicos.
- Listagem filtrada por visibilidade (cliente vê apenas seus chamados; técnico vê todos).
- Acesso ao detalhe de chamado próprio e alheio.
- Exportação CSV respeitando visibilidade e cabeçalho exato.
- Sanitização de XSS no parâmetro `busca`.
- Injeção SQL neutralizada (payload malicioso não quebra a visibilidade).
- Cálculo de média de resposta sem divisão por zero.

A execução foi feita com PHP 8.4 e MariaDB 11.8, usando os scripts `schema.sql` e `seed.sql` fornecidos.
