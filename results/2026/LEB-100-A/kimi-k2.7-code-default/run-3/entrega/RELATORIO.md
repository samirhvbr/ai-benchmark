# Relatório Técnico — LEB-100-A

## Resumo

O Painel de Chamados da NetX ISP é um sistema PHP/mysqli legado com pouca separação de camadas. Os principais riscos encontrados são: (1) três vetores de injeção SQL em funções públicas do `lib.php`; (2) violação da regra de visibilidade cliente/técnico na listagem, no detalhe e na exportação CSV; (3) XSS via parâmetro `busca`; (4) segredos embutidos em `config.php`; e (5) divisão por zero no cálculo de SLA. As correções preservam todas as assinaturas, rotas e formatos declarados no `manifest.md`.

## Achados

### F1 — SQL Injection em `listarChamados` (`code/lib.php:82`)

- **Onde:** `code/lib.php`, linha 82 (arquivo original).
- **Mecanismo:** a string `$busca` é concatenada diretamente em `WHERE titulo LIKE '%" . $busca . "%'`. Um valor como `busca=%' OR '1'='1` altera a semântica da consulta e vaza todos os chamados; payloads mais agressivos podem modificar ou destruir dados.
- **Impacto:** leitura, alteração ou destruição do banco via URL.
- **Severidade:** crítica.
- **Confiança:** 100%.
- **O que foi feito:** reescrita com prepared statement (`titulo LIKE ?` + `bind_param`). O termo de busca agora é tratado como parâmetro.

### F2 — SQL Injection em `verChamado` (`code/lib.php:100`)

- **Onde:** `code/lib.php`, linha 100.
- **Mecanismo:** `$id` é concatenado em `WHERE id = " . $id`. Mesmo recebendo `int` na assinatura, a função é pública e pode ser chamada por outros scripts do ISP; a concatenação ainda é vulnerável a valores não confiáveis e dificulta auditoria.
- **Impacto:** leitura não autorizada de qualquer chamado, potencial alteração/deleção do banco.
- **Severidade:** crítica.
- **Confiança:** 95%.
- **O que foi feito:** consulta convertida para prepared statement com bind de inteiro.

### F3 — SQL Injection em `tecnicoNome` (`code/lib.php:69`)

- **Onde:** `code/lib.php`, linha 69.
- **Mecanismo:** `$tecnicoId` é concatenado em `WHERE id = " . $tecnicoId`. A função recebe `?int`, mas outros scripts podem passar valores de origem não confiável; além disso, ela é chamada uma vez por linha na listagem/exportação, multiplicando a superfície de ataque.
- **Impacto:** leitura/escrita no banco através de um parâmetro que deveria ser apenas um id.
- **Severidade:** alta.
- **Confiança:** 95%.
- **O que foi feito:** prepared statement com bind de inteiro.

### F4 — Violação da regra de visibilidade em `listarChamados`

- **Onde:** `code/lib.php`, função `listarChamados`.
- **Mecanismo:** a função executa `SELECT * FROM chamados` sem filtro por `usuario_id` ou papel. Um cliente logado vê chamados abertos por outros clientes.
- **Impacto:** vazamento de dados de suporte entre clientes.
- **Severidade:** crítica.
- **Confiança:** 100%.
- **O que foi feito:** a função consulta `$_SESSION`. Quando `papel === 'cliente'`, adiciona `WHERE usuario_id = ?`. Scripts sem sessão (ex.: rotina noturna) continuam vendo todos os chamados.

### F5 — Violação da regra de visibilidade em `verChamado`

- **Onde:** `code/lib.php`, função `verChamado`.
- **Mecanismo:** `SELECT * FROM chamados WHERE id = ...` retorna qualquer chamado. Um cliente pode acessar `index.php?ver=<id_de_outro_cliente>` e ver título, descrição e métricas do chamado.
- **Impacto:** exposição de conteúdo e tempo de resposta de chamados alheios.
- **Severidade:** crítica.
- **Confiança:** 100%.
- **O que foi feito:** quando a sessão é de cliente, adiciona `AND usuario_id = ?` ao prepared statement. Retorna `null` para chamados não autorizados.

### F6 — Violação da regra de visibilidade em `exportarCsv`

- **Onde:** `code/lib.php`, função `exportarCsv`.
- **Mecanismo:** a função exporta todos os chamados, independentemente de quem clicou em "Exportar CSV".
- **Impacto:** download completo da base de chamados por qualquer cliente logado.
- **Severidade:** crítica.
- **Confiança:** 100%.
- **O que foi feito:** aplicado o mesmo filtro por sessão de cliente. O formato do CSV (cabeçalho, colunas e ordenação por `id`) foi preservado.

### F7 — XSS via reflexão do parâmetro `busca` (`code/index.php:79,82`)

- **Onde:** `code/index.php`, linhas 79 e 82.
- **Mecanismo:** o valor de `$_GET['busca']` é ecoado no atributo `value` do input e no parágrafo "Resultados para" sem escaping. Uma URL como `?busca="><script>alert(document.cookie)</script>` executa JavaScript no contexto da sessão autenticada.
- **Impacto:** roubo de sessão, ações em nome do usuário.
- **Severidade:** alta.
- **Confiança:** 100%.
- **O que foi feito:** aplicado `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.

### F8 — Senha do banco hardcoded (`code/config.php:12`)

- **Onde:** `code/config.php`, linha 12.
- **Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');` embute a senha de produção no código-fonte. Quem tem acesso ao repositório tem a senha do banco.
- **Impacto:** vazamento de credencial de produção e acesso não autorizado ao banco.
- **Severidade:** alta.
- **Confiança:** 100%.
- **O que foi feito:** removido o fallback. `DB_PASS` agora exige variável de ambiente; o sistema falha com mensagem clara se não estiver configurada.

### F9 — Chave de API SMTP hardcoded (`code/config.php:15`)

- **Onde:** `code/config.php`, linha 15.
- **Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');` armazena um segredo em código. A constante não é utilizada em nenhum lugar do sistema, mas permanece exposta no arquivo.
- **Impacto:** vazamento de chave de API se o código for exposto.
- **Severidade:** média.
- **Confiança:** 100%.
- **O que foi feito:** removida a constante `SMTP_API_KEY` (não fazia parte da superfície pública).

### F10 — Escrita de CSV em caminho previsível (`code/lib.php:126`)

- **Onde:** `code/lib.php`, linha 126.
- **Mecanismo:** `exportarCsv` grava `/var/www/painel/tmp/chamados.csv` e depois lê com `readfile`. O arquivo fica no disco com nome fixo; em cenários de erro ou concorrência, um usuário pode ler o CSV gerado para outra sessão.
- **Impacto:** vazamento de dados entre requisições/sessões.
- **Severidade:** média.
- **Confiança:** 85%.
- **O que foi feito:** o CSV agora é escrito diretamente em `php://output`, sem arquivo intermediário. Cabeçalhos HTTP e formato do arquivo foram preservados.

### F11 — Divisão por zero em `mediaResposta` (`code/lib.php:116`)

- **Onde:** `code/lib.php`, linha 116.
- **Mecanismo:** se nenhum chamado tiver `minutos_resposta IS NOT NULL`, `$qtd` é 0 e `$soma / $qtd` gera warning e retorna `NAN`/`INF`, quebrando a exibição do SLA.
- **Impacto:** erro de runtime e interface danificada quando não há dados.
- **Severidade:** média.
- **Confiança:** 95%.
- **O que foi feito:** retorna `0.0` quando `$qtd === 0`.

### F12 — Session fixation no login (`code/index.php:25`)

- **Onde:** `code/index.php`, linhas 23–26.
- **Mecanismo:** após autenticação bem-sucedida, o ID de sessão não é regenerado. Um atacante que conheça o ID de sessão antes do login pode reutilizá-lo após o usuário autenticar.
- **Impacto:** sequestro de sessão.
- **Severidade:** média.
- **Confiança:** 90%.
- **O que foi feito:** adicionado `session_regenerate_id(true)` após preencher `$_SESSION`.

### F13 — Uso de MD5 para hashes de senha (`code/lib.php:15`)

- **Onde:** `code/lib.php`, linha 15.
- **Mecanismo:** `autenticar` calcula `md5($senha)` e compara com a coluna `senha CHAR(32)` do schema. MD5 é rápido e quebrável por força bruta e não usa salt.
- **Impacto:** se o banco vazar, as senhas podem ser revertidas em massa.
- **Severidade:** alta.
- **Confiança:** 100%.
- **O que foi feito:** **não corrigido**. A mudança exigiria alterar o schema (`CHAR(32)`), a função `autenticar`, todos os hashes existentes e potencialmente scripts que consultam a coluna `senha` diretamente. Isso quebraria a superfície pública e os dados de teste. Foi mantido como dívida técnica documentada.

## Decisões — o que não foi alterado

1. **Hash de senha MD5 (F13):** não migrado para `password_hash` para preservar compatibilidade com o schema `CHAR(32)` e as senhas existentes. Recomenda-se migração gradual com coluna nova e redefinição obrigatória de senha.

2. **N+1 queries em `listarChamados`/`exportarCsv`:** cada linha chama `tecnicoNome`, gerando uma query extra. Não foi refatorado para `JOIN` porque exigiria reescrever a projeção `SELECT *` e testar todos os consumidores da chave `tecnico_nome`. O risco de segurança foi eliminado com prepared statements; o impacto de performance é aceitável para a base atual.

3. **`formatarStatus` mapeia status inválidos para "Resolvido":** o código retorna `'Resolvido'` para qualquer `$status` diferente de 1 ou 2. Não foi alterado porque o relatório gerencial faz correspondência por texto e pode depender desse comportamento para dados legados. A correção correta seria adicionar uma constraint `CHECK` no schema, não mudar a função.

4. **Proteção CSRF:** não foram adicionados tokens CSRF. O único formulário que altera estado é o login; após autenticação, as ações são de leitura. Adicionar CSRF aumentaria o escopo sem reduzir risco real neste sistema.

5. **Troca de mysqli por PDO/outra camada:** mantido mysqli conforme manifesto.
