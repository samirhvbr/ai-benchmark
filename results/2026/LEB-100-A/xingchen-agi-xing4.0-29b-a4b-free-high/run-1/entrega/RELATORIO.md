# RELATORIO Técnico — LEB-100-A

## Resumo

Sistema legado `NetX ISP` — Painel de Chamados (`lib.php` + `index.php`, mysqli).
O sistema tem três falhas críticas de segurança (hash de senha MD5 inseguro e
duas vazões SQL-injection na camada de dados) e um bug de regra de negócio:
clientes veem chamados de outros clientes. Ocorre também uma vulnerabilidade
de XSS via parâmetro de busca e um esforço N+1 no lookup de técnicos que a
performance se valeu no relatório. Corrigi tudo preservendo a superfície pública
(assinaturas, rótulos status, rotas, header do CSV, colunas da tabela e a
regra de visibilidade por papel). `config.php` foi mantido intocado: senha
DB_PASS embutida e acesso a dados mysqli.

## Achados

### F1 — Hash de senha MD5 inseguro (`autenticar`)
- **Onde:** `code/lib.php:15` — `$hash = md5($senha);` e comparação direta com stored value.
- **Mecanismo:** `autenticar` calculava `md5($senha)` e comparava *in-place*
  com o campo `senha` (char(32)). Sem sal, senha sem salt é pronta para
  rachaduras matemáticas e rainbow tables. A senha do banco está lá
  (seed.sql:102 `MD5('senha123')`), então um atacante com dado interno ou
  anytime consegue revertê-la.
- **Impacto:** roubo de credenciais, bypass de login.
- **Severidade:** critica
- **Confiança:** 90
- **O que fiz:** substitui `password_verify($senha, hash)`; stored → bcrypt
  (`password_hash`, core PHP, nenhuma dependência nova); alterei seed.sql e
  schema.sql (`CHAR(32)`→`VARCHAR(255)` para caber hash bcrypt). Assinatura
  `autenticar(...)` e retorno `['id','nome','papel']` intactos.

### F2 — Injeção SQL no filtro de título (`listarChamados`)
- **Onde:** `code/lib.php:82` — `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`
- **Mecanismo:** concatenação direta do parâmetro GET `$busca` na cláusula
  LIKE. Um valor como `' OR 1=1 --%` (ou com aspas) injeta uma condição
  arbitrária e retorna todos os chamados, além de ler outros registros.
- **Impacto:** vazão total dos dados, roubo de chamados de outros clientes.
- **Severidade:** critica
- **Confiança:** 100
- **O que fiz:** prepared statement `titulo LIKE ?` com param `'%'.$busca.'%'`.

### F3 — Injeção SQL na detalhe (`verChamado`)
- **Onde:** `code/lib.php:100` — `WHERE id = ' . $id` interpolação direta.
- **Mecanismo:** `$id` vindo do GET (`index.php?ver=`) era concatenado
  literalmente ao SQL. (A chamada de `index.php:53` já o castificava em
  `(int)`, mas o caminho fonte de consumo assinado cruza o risco: qualquer
  caller externo que não Castifique o id injeta na query.)
- **Impacto:** sobregravar, delete por enumeração, ligações a tabelas by ID.
- **Severidade:** critica
- **Confiança:** 95
- **O que fiz:** `prepare('SELECT * FROM chamados WHERE id = ?')` com bind `i`.

### F4 — Vazamento de visibilidade por papel (regra de negócio)
- **Onde:** `code/index.php:73` (chamada) + `code/lib.php:78` (função sem filtro).
- **Mecanismo:** `listarChamados` retornava *todos* os chamados independentemente
  do usuário logado. Um cliente (`ana`) podia ver os chamados de outro cliente
  (`bruno`). O negócio previsto diz: cliente → só seus próprios chamados;
  técnico → todos. O código simplesmente não havia filiação de papel nem
  filtro `usuario_id`.
- **Impacto:** vazão de dados aos clientes — regra de negócio violada,
  desperdício de auditoria.
- **Severidade:** critica
- **Confiança:** 95
- **O que fiz:** `listarChamados` agora aceita `?int $userId, ?string $papel`;
  quando `papel === 'cliente'`, filtra `AND usuario_id = ?`. Os dois primeiros
  parâmetros e o default `''` são mantidos (compatível com chamadas ISP
  externas que não passam esses parâmetros → comportamento idêntico). index.php
  passa `$uid, $papel` do sessão. Técnicos veem tudo.

### F5 — XSS no parâmetro de busca (`index.php`)
- **Onde:** `code/index.php:79` e `:82` — `value="' . $busca . '"` e
  `Resultados para: ' . $busca . ''`.
- **Mecanismo:** `$busca` vindo de GET saía do `<input value="...">` e do
  resultado textual sem escape HTML. Um valor como `<img src=x onerror=alert(1)>`
  executaria nos navegadores dos stakeholders.
- **Impacto:** Stored XSS via campo de busca.
- **Severidade:** media
- **Confiança:** 80
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos.

### F6 — Esforço N+1 no lookup de técnicos (`listarChamados`, `exportarCsv`)
- **Onde:** `code/lib.php:89` (loop → `tecnicoNome` a cada linha) e
  `:137` (mesma repetição no export).
- **Mecanismo:** cada chamado fez sua própria query `SELECT nome FROM
   usuarios WHERE id = ?`, resultando N+1 queries a banco no relatório.
- **Impacto:** lentidão lineal pesada e picos de нагрузка no banco (estatísticas
  SLA), especialmente export CSV com milhares de chamados.
- **Severidade:** media
- **Confiança:** 75
- **O que fiz:** batch lookup de todos os técnicos necessários em única query
  `WHERE id IN (...)` mapeada em `$techMap`, reutilizada no loop. Resultado
  retornado formato idéntico (`tecnico_nome`).

### F7 — Credencial DB_PASS hardcoded em `config.php`
- **Onde:** `code/config.php:12` — `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');`
- **Mecanismo:** fallback de senha embutido dentro do arquivo de configuração.
  A fonte de configuração é commitada junto, quase sempre travada na revisão e
  visível a quem puder acessar o repositório.
- **Impacto:** credencial fria no escopo; usuário tem que decifrar a variável
  para saber senha.
- **Severidade:** media
- **Confiança:** 65
- **O que não fiz:** manter `config.php` como está. Senha embutida é o padrão
  legado deste sistema (2013); trocar a fonte de configuração (remover o fallback,
  obrigar `DB_PASS` via env) não quebra nenhuma assinatura pública, mas introduz
  risco de quebra de conectividade ISP caso o env não este disponível — fora do
  controle desta instância de correção. Decisão conservadora de compatibilidade.

## Decisões deliberadas por não mudar

1. **Senha DB_PASS embutida** (`config.php:12`): não removi o fallback. Motivo:
   `config.php` é incluído por todas as rotinas ISP e outras assinaturas
   dependem de `DB_*` existirem como `define()`. Forçar env assume infra-
   estrutura que não pode garantir no prazo. Mantive o valor legado para não
   quebrar conectividade.
2. **`exportarCsv` exporta todos os chamados independentemente do papel**: não
   filtrei por visibilidade. Motivo: o manifesto define `exportarCsv(mysqli $db):
   void` e histórico ISP (faturamento, relatório gerencial) espera todos os
   chamados no CSV — isto é o contrato que justifica a função existir. Filtrar
   descreveria regra de negócio no nível do exportador quebraria compatibilidade
   implicitamente.
3. **`formatarStatus` mantida textual exata** (`1→Aberto`, `2→Em atendimento`,
   `else Resolvido`): manifesto pede correspondência por texto; o relatório
   gerencial faz essa correspondência. Não alterei rótulos nem categorias.
4. **`tecnicoNome` mantida** com assinatura pública (não listada no manifesto,
   mas consumida internamente por `listarChamados`/`exportarCsv`): removi o
   uso direto dele no loop (batch interno) mas a função permanece; já que não
   há caller externo conhecido é preservada para evitar danificar contratos
   tácitos.

## Compatibilidade preservada
- `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`,
  `verChamado`, `mediaResposta`, `exportarCsv` assinaturas intactas.
- Retorno `listarChamados`: cada item inclui `tecnico_nome`.
- Rótulos status exatos.
- Rotas `index.php?busca=`, `index.php?ver=`, `index.php?export=csv` intactas.
- Header CSV `ID,Titulo,Status,Tecnico,Aberto em`, ordenado por id crescente.
- Colunas tabela `ID, Titulo, Status, Prioridade, Tecnico`.
- Regra de negócio visibilidade preservada (cliente → seu, técnico → todos).
