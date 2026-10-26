# RELATÓRIO — LEB-100-A

## Resumo

Painel PHP/mysqli pequeno, com falhas sérias: SQL injection, XSS, quebra da regra de visibilidade (IDOR, listagem e CSV), exportação por arquivo compartilhado, N+1 e divisão por zero. Corrigi o que é possível sem alterar o contrato: assinaturas, rotas, CSV, HTML e rótulos preservados. Número de linha = arquivo original. Verificação: só `php -l` (sem MySQL no ambiente).

## F1 — SQL injection na busca por título

- **Onde:** `code/lib.php:80-85`
- **Categoria/severidade:** seguranca / critica
- **Confiança:** 98
- **Mecanismo:** $busca vem de $_GET['busca'] e é concatenado em LIKE '%...%' sem escape; ' UNION SELECT ... lê qualquer tabela (inclui hashes md5 de usuarios).
- **Impacto:** Leitura/alteração arbitrária do banco por qualquer usuário logado.
- **Ação:** Prepared statement com bind; curingas %, _ e \ escapados para busca literal. (corrigido)

## F2 — IDOR: detalhe de chamado sem checar dono

- **Onde:** `code/index.php:52-53`
- **Categoria/severidade:** seguranca / alta
- **Confiança:** 97
- **Mecanismo:** index.php?ver=<id> chama verChamado sem comparar usuario_id com a sessão; $papel era lido e nunca usado.
- **Impacto:** Cliente lê chamados de outros clientes (viola a regra de visibilidade).
- **Ação:** Cliente só vê se usuario_id == uid; senão responde 'não encontrado' (sem revelar existência). Técnico vê tudo. (corrigido)

## F3 — Listagem e CSV expõem todos os chamados a clientes

- **Onde:** `code/index.php:72-73`
- **Categoria/severidade:** seguranca / alta
- **Confiança:** 95
- **Mecanismo:** listarChamados e exportarCsv não filtram por dono; a página e o export?csv devolvem tudo a qualquer papel.
- **Impacto:** Vazamento de títulos/técnicos/datas de todos os clientes.
- **Ação:** Listagem filtrada por usuario_id para clientes; exportarCsv ganhou parâmetro opcional final $usuarioId (default null = todos, mantendo a rotina noturna). (corrigido)

## F4 — XSS refletido em busca

- **Onde:** `code/index.php:79-82`
- **Categoria/severidade:** seguranca / alta
- **Confiança:** 97
- **Mecanismo:** $busca é impresso cru no atributo value e em 'Resultados para:'; "><script> executa no navegador.
- **Impacto:** Execução de JS na sessão da vítima via link malicioso.
- **Ação:** htmlspecialchars (ENT_QUOTES no atributo); busca não-string vira ''. (corrigido)

## F5 — Exportação CSV via arquivo fixo compartilhado

- **Onde:** `code/lib.php:125-150`
- **Categoria/severidade:** bug / media
- **Confiança:** 85
- **Mecanismo:** Grava em EXPORT_DIR/chamados.csv e relê; requisições concorrentes se sobrescrevem (usuário A pode receber dados de B, agravado por F3), fopen sem @ gera warning, retornos antecipados deixam fp aberto e a resposta vazia, arquivo com dados fica em disco.
- **Impacto:** Mistura de dados entre usuários, corrupção, dados residuais em disco.
- **Ação:** Streaming direto para php://output, sem arquivo; mesmo cabeçalho e formato. (corrigido)

## F6 — N+1 consultas (tecnicoNome por linha), também SQL concatenado

- **Onde:** `code/lib.php:64-93`
- **Categoria/severidade:** performance / media
- **Confiança:** 90
- **Mecanismo:** Para cada chamado uma query extra em usuarios; no export idem.
- **Impacto:** Latência cresce linearmente com o número de chamados.
- **Ação:** LEFT JOIN único em listarChamados/exportarCsv (tecnico_nome '-' quando nulo); tecnicoNome mantida, agora preparada. (corrigido)

## F7 — Senhas com MD5 sem sal

- **Onde:** `code/lib.php:15-15`
- **Categoria/severidade:** seguranca / alta
- **Confiança:** 95
- **Mecanismo:** md5($senha) comparado direto no SQL; coluna CHAR(32) impede hash moderno sem migração de schema.
- **Impacto:** Vazamento do banco (ver F1) permite quebra imediata por rainbow table.
- **Ação:** Não alterado: exigiria mudar schema/seed e migrar senhas; proposta: coluna nova + password_hash com rehash no login. (NÃO corrigido, só reportado)

## F8 — Divisão por zero em mediaResposta

- **Onde:** `code/lib.php:107-117`
- **Categoria/severidade:** bug / media
- **Confiança:** 92
- **Mecanismo:** Sem chamados com minutos_resposta, $soma / $qtd lança DivisionByZeroError (PHP 8) e derruba a listagem inteira; também lê todas as linhas para somar no PHP.
- **Impacto:** Painel indisponível para banco novo/sem respostas; ineficiente.
- **Ação:** AVG() no SQL; retorna 0.0 se não houver dados. (corrigido)

## F9 — Credenciais embutidas no código

- **Onde:** `code/config.php:12-15`
- **Categoria/severidade:** seguranca / alta
- **Confiança:** 90
- **Mecanismo:** Senha de produção do banco como fallback de getenv e chave SMTP literal no repositório.
- **Impacto:** Qualquer pessoa com acesso ao código obtém o acesso ao banco.
- **Ação:** Não alterado: remover o fallback quebraria deploys sem variável de ambiente. Recomendado: rotacionar ambas as credenciais e exigir env. (NÃO corrigido, só reportado)

## F10 — Sessão: sem regenerate_id, cookie sem flags; login com array

- **Onde:** `code/index.php:15-22`
- **Categoria/severidade:** seguranca / media
- **Confiança:** 80
- **Mecanismo:** Session id não é regenerado após login (fixation); cookie sem HttpOnly/SameSite; $_POST['login'][]=x causa TypeError (string tipada) em autenticar.
- **Impacto:** Fixação de sessão, roubo de cookie via XSS, erro 500 provocável.
- **Ação:** session_regenerate_id(true), cookie httponly+SameSite=Lax, casts para string. (corrigido)

## F11 — Sem CSRF/logout/rate limit no login

- **Onde:** `code/index.php:20-35`
- **Categoria/severidade:** seguranca / baixa
- **Confiança:** 60
- **Mecanismo:** Formulário de login sem token, sem limite de tentativas (md5 fraco facilita força bruta) e sem rota de logout.
- **Impacto:** Força bruta de senhas; sessão não encerrável.
- **Ação:** Não alterado: novas rotas/estado fora do escopo e do contrato. (NÃO corrigido, só reportado)

## F12 — rotuloPrioridade com ifs aninhados

- **Onde:** `code/lib.php:40-59`
- **Categoria/severidade:** qualidade / baixa
- **Confiança:** 70
- **Mecanismo:** Cinco níveis de aninhamento para uma tabela de decisão simples.
- **Impacto:** Difícil de manter.
- **Ação:** Reescrita com retornos antecipados, mesmos resultados em todas as entradas. (corrigido)

## F13 — Chamado crítico sem resposta não sinaliza urgência

- **Onde:** `code/lib.php:56-58`
- **Categoria/severidade:** arquitetura / baixa
- **Confiança:** 40
- **Mecanismo:** Prioridade 4 com minutos NULL retorna 'Aguardando 1a resposta', sem destaque de SLA.
- **Impacto:** Possível subnotificação de críticos; pode ser intencional.
- **Ação:** Não alterado: texto de rótulo é contrato/regra de negócio. (NÃO corrigido, só reportado)

## F14 — Injeção de fórmula em CSV

- **Onde:** `code/lib.php:138-144`
- **Categoria/severidade:** seguranca / baixa
- **Confiança:** 45
- **Mecanismo:** Títulos iniciados por =,+,-,@ são gravados crus e podem executar fórmulas no Excel.
- **Impacto:** Execução de fórmula ao abrir o CSV (títulos são fornecidos por clientes).
- **Ação:** Não alterado: prefixar mudaria o conteúdo consumido por scripts do ISP. (NÃO corrigido, só reportado)

## Decisões (o que não mudei)

- **Assinaturas públicas e rótulos; exportarCsv ganhou só um parâmetro opcional final** — Contrato do manifesto; chamadas antigas continuam válidas.
- **Filtro de visibilidade feito em index.php em vez de em listarChamados/verChamado** — Mantém exatamente as assinaturas; uma função que filtra por dono exigiria parâmetro novo.
- **md5 de senhas, credenciais em config.php, CSRF/logout/rate limit, formula injection, média do SLA global exibida a clientes** — Exigem schema, deploy ou mudança de contrato/dados; riscos descritos nos achados.
- **formatarStatus (qualquer valor ≠1,2 vira 'Resolvido')** — Contrato de valor; o status é limitado a 1–3 pelo domínio.
- **Não foi possível testar contra MySQL real** — Apenas php -l (sintaxe) foi executado; sem banco no ambiente. Recomenda-se rodar schema/seed e conferir rotas.
