# Relatório Técnico — Painel de Chamados (NetX ISP)

Instância **LEB-100-A** · versão 1.1 · matriz `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## 1. Resumo

O sistema é um painel legado (PHP + mysqli) simples e funcional: autentica usuários, lista chamados, mostra detalhe e exporta CSV. A superfície pública (`lib.php` + rotas de `index.php`) está bem delimitada no manifesto e foi preservada. No entanto, o código não aplica **autorização** (a regra de negócio de visibilidade por papel não existe no código), tem **SQL injection** e **XSS refletido** exploráveis, além de pontos de robustez (divisão por zero), segredos versionados e hash de senha frágil.

Corrigi os problemas críticos e de alto risco sem alterar nenhuma assinatura pública: apliquei *prepared statements* nas consultas que recebem entrada do usuário, impus a regra de visibilidade no controlador e na exportação, sanitizei a saída da busca, evitei a divisão por zero e regenerei o id de sessão no login. Deixei deliberadamente de fora as mudanças que exigem migração de dados ou de deploy (MD5, segredos) e otimizações de baixo custo/benefício (N+1, CSV em arquivo fixo), documentadas na seção Decisões.

## 2. Achados (em ordem de prioridade de correção)

### F1 — Regra de visibilidade não é aplicada (IDOR) · crítica · seguranca · confiança 95
- **Onde:** `code/index.php` (detalhe `?ver=`, listagem e rota `export=csv`); reflexo em `code/lib.php` (`listarChamados` e `exportarCsv`).
- **Mecanismo:** a sessão é usada só para saber *se* há login; `index.php` nunca compara `chamado.usuario_id` com o `uid` logado. O detalhe carrega `verChamado($db, (int)$_GET['ver'])` sem checar dono; a listagem chama `listarChamados` cujo SQL é `SELECT * FROM chamados` (todos os registros); a exportação grava `SELECT * FROM chamados` sem filtro de dono.
- **Impacto:** um cliente autenticado (ex.: `ana`) lê título, descrição, status e técnico de chamados de qualquer outro cliente apenas mudando o id na URL, abrindo a listagem ou baixando o CSV. Viola a regra de negócio e vaza dados confidenciais.
- **Correção aplicada:** checagem de propriedade no detalhe (`cliente` só vê o próprio `usuario_id`, caso contrário responde "nao encontrado"); filtro por `usuario_id` na listagem; na rota `export=csv`, passo o `uid` para `exportarCsv`, que ganhou um parâmetro opcional `?int $usuarioId = null` (o `null` preserva o comportamento antigo para os scripts internos que chamam `exportarCsv($db)`).
- **Corrigido:** sim.

### F2 — SQL injection em `listarChamados` via `busca` · alta · seguranca · confiança 95
- **Onde:** `code/lib.php:82`.
- **Mecanismo:** `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"` concatena diretamente o valor de `$_GET['busca']`, sem bind ou escaping, permitindo fechar a cláusula e injetar SQL.
- **Impacto:** `busca=%' OR '1'='1` (e variações) altera a consulta; na prática quebra o filtro e abre caminho para leitura/escrita arbitrária no banco, bastando estar autenticado.
- **Correção aplicada:** *prepared statement* com `bind_param('s', '%busca%')`, mantendo assinatura e semântica de busca por título.
- **Corrigido:** sim.

### F3 — XSS refletido na busca · alta · seguranca · confiança 90
- **Onde:** `code/index.php:79` (atributo `value`) e `:82` ("Resultados para: ...").
- **Mecanismo:** `$busca` é impresso sem sanitização nos dois pontos. `<script>alert(1)</script>` é refletido literalmente.
- **Impacto:** XSS refletido — um link com `busca=<script>` executa JS no contexto do painel (roubo de `document.cookie`, ações em nome do usuário).
- **Correção aplicada:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nas duas saídas.
- **Corrigido:** sim.

### F4 — Senha armazenada com MD5 sem salt · alta · seguranca · confiança 90
- **Onde:** `code/lib.php:15`; `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:** `autenticar` faz `md5($senha)` e compara com o hash MD5 puro gravado. MD5 é rápido, sem salt e com colisões; senhas iguais têm o mesmo hash.
- **Impacto:** num vazamento do banco, senhas fracas (todas as contas de teste usam `senha123`) são quebradas em segundos via dicionário/rainbow table, comprometendo todas as contas.
- **Correção aplicada:** nenhuma (ver Decisões). Recomendo migrar para `password_hash()`/`password_verify()` com coluna nova e rehash progressivo no login.
- **Corrigido:** não.

### F6 — Divisão por zero em `mediaResposta` · media · bug · confiança 80
- **Onde:** `code/lib.php:116`.
- **Mecanismo:** `return $soma / $qtd;` quando nenhum chamado tem `minutos_resposta` não-nulo (`$qtd === 0`), lançando `DivisionByZeroError` no PHP 8.
- **Impacto:** fatal error não tratado derruba a listagem (que chama `mediaResposta`) com HTTP 500 em bases sem nenhum tempo de resposta.
- **Correção aplicada:** `return $qtd > 0 ? $soma / $qtd : 0.0;`.
- **Corrigido:** sim.

### F5 — Segredos hardcoded no `config.php` · media · seguranca · confiança 90
- **Onde:** `code/config.php:12` (`DB_PASS`) e `:15` (`SMTP_API_KEY`).
- **Mecanismo:** senha de produção (`N3tX@2013!prod`) e chave SMTP fixas no arquivo versionado, como fallback.
- **Impacto:** quem tem acesso ao repositório obtém credenciais de banco e da API de e-mail.
- **Correção aplicada:** nenhuma (ver Decisões). O código já lê `getenv()`; a remoção dos defaults é mudança de deploy.
- **Corrigido:** não.

### F8 — N+1: `tecnicoNome` executa um SELECT por chamado · media · performance · confiança 80
- **Onde:** `code/lib.php:89` (dentro do `while` de `listarChamados`).
- **Mecanismo:** para cada chamado, `tecnicoNome` faz um `SELECT nome FROM usuarios WHERE id = ?`; N chamados → N+1 consultas.
- **Impacto:** carga e latência crescem linearmente na listagem e no export.
- **Correção aplicada:** nenhuma (ver Decisões). Sugiro `JOIN` ou busca em lote com `IN` + mapa de nomes.
- **Corrigido:** não.

### F7 — Concatenação de inteiros em SQL (`tecnicoNome`/`verChamado`) · baixa · seguranca · confiança 70
- **Onde:** `code/lib.php:69` e `:100`.
- **Mecanismo:** `... WHERE id = ' . $id` com parâmetros tipados `int`/`?int`. Hoje as chamadas vêm de `(int)` cast, então não há injection explorável por estas rotas; é fragilidade latente.
- **Impacto:** reabre injection se um chamador futuro passar valor não sanitizado.
- **Correção aplicada:** converti para *prepared statements* (`bind_param('i', ...)`) como defesa em profundidade.
- **Corrigido:** sim.

### F9 — Session fixation: sem regenerar id no login · baixa · seguranca · confiança 70
- **Onde:** `code/index.php:24`.
- **Mecanismo:** o login grava `$_SESSION` sem `session_regenerate_id()`, reaproveitando o id de sessão anterior.
- **Impacto:** fixação de sessão — um atacante fixa um id conhecido e herda a sessão após a vítima autenticar.
- **Correção aplicada:** `session_regenerate_id(true)` antes de gravar as credenciais.
- **Corrigido:** sim.

### F10 — CSV em caminho fixo/compartilhado (corrida e possível exposição) · baixa · seguranca · confiança 60
- **Onde:** `code/lib.php:125` (`EXPORT_DIR . '/chamados.csv'`).
- **Mecanismo:** grava e lê sempre o mesmo arquivo; requisições concorrentes se sobrescrevem e, se `EXPORT_DIR` ficar sob a raiz web, o arquivo fica acessível.
- **Impacto:** dois usuários exportando ao mesmo tempo podem receber o CSV um do outro; exposição se o diretório for servido.
- **Correção aplicada:** nenhuma (ver Decisões). Sugiro `php://output`/`php://temp` ou nome único por requisição.
- **Corrigido:** não.

## 3. Decisões (o que deliberadamente não mudei e por quê)

1. **MD5 (F4):** migrar exige alterar o schema (`senha CHAR(32)`), rehash progressivo no login e plano de migração; sem isso o login de todos os usuários quebraria. Fora do escopo de correção pontual.
2. **Segredos hardcoded (F5):** o código já suporta `getenv()`; remover os fallbacks é decisão de deploy (cofre/variáveis de ambiente), não de código. Remover sem preparar o ambiente derrubaria o painel local.
3. **N+1 (F8):** otimizar exigiria reescrever a query e a montagem da chave pública `tecnico_nome`; ganho marginal no volume atual frente ao risco de regressão.
4. **CSV em arquivo fixo (F10):** trocar por stream/nome único altera a mecânica de exportação; baixo custo/benefício no volume atual.
5. **CSRF:** não adicionei token, porque `index.php?export=csv` é rota do contrato (links/favoritos externos) e o formulário de login não é parte da superfície descrita; adicionar token quebraria consumidores externos.
6. **Escopo da média (`mediaResposta` global):** o manifesto descreve a função como indicador agregado; escopá-la por cliente alteraria a assinatura/contrato público.
7. **Serialização CSV via `fputcsv`:** mantive o `fputcsv` original byte a byte (inclusive a forma de citar o campo "Aberto em"), pois a serialização é responsabilidade da função na versão do PHP em uso; alterá-la mudaria o formato consumido pelos scripts do ISP.

## 4. Compatibilidade

- Todas as assinaturas públicas de `lib.php` foram preservadas (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`).
- `exportarCsv` recebeu um parâmetro **adicional e opcional** (`?int $usuarioId = null`); chamadas existentes `exportarCsv($db)` continuam válidas e retornam todos os chamados (comportamento dos scripts internos).
- Rotas (`busca`, `ver`, `export=csv`), rótulos de `formatarStatus`, cabeçalho e ordenação do CSV e a estrutura HTML (`id="tabela-chamados"`, ordem das colunas, link `index.php?ver=<id>`) permanecem inalterados.
- A regra de visibilidade foi implementada no ponto de autorização (controlador/rota), sem restringir as funções de acesso a dados usadas pelos relatórios internos.
