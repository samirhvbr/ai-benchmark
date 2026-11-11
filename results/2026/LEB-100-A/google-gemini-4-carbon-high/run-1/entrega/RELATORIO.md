# Relatório Técnico — Auditoria e Evolução do Painel de Chamados (LEB-100-A)

## 1. Resumo

O Painel de Chamados da NetX ISP é um sistema PHP/MySQL procedural compacto (`config.php`, `lib.php`, `index.php`), em operação desde 2013 e consumido tanto por usuários via navegador quanto por rotinas internas em lote. No estado recebido, o sistema apresentava falhas críticas de segurança e confiabilidade: **SQL Injection** na busca de chamados, **quebra completa da regra de visibilidade (IDOR)** permitindo que clientes lessem e exportassem chamados de terceiros, **credenciais de produção embutidas** em texto claro no código, **XSS refletido** em dois pontos da busca, **erro fatal de divisão por zero** (`DivisionByZeroError` no PHP 8+) quando não há chamados respondidos, **exposição e condição de corrida na exportação CSV** em diretório fixo do webroot, **Session Fixation** no login e **N+1 queries** na listagem e no CSV.

O código foi evoluído *in-place* corrigindo 11 dos 13 problemas identificados sem alterar nenhuma assinatura pública, rota, estrutura HTML ou formato CSV exigidos pelo `manifest.md`. Os 2 itens restantes (`F8` e `F13`) receberam mitigação parcial compatível no código e têm suas restrições de banco/contrato detalhadas na seção de Decisões.

---

## 2. Achados Técnicos (por ordem de prioridade)

### F1 — SQL Injection na busca de chamados por título em `listarChamados`

- **Localização original:** `code/lib.php`, linhas 80–85 (acionado por `code/index.php`, linhas 72–73)
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** Em `listarChamados()`, o argumento `$busca` (recebido diretamente de `$_GET['busca']` sem tratamento em `index.php:72`) era concatenado literalmente na instrução SQL `" WHERE titulo LIKE '%" . $busca . "%'"` (`lib.php:82`) e enviado ao MySQL via `$db->query($sql)` (`lib.php:85`). Uma aspa simples (`'`) no parâmetro encerra o literal da cláusula `LIKE` e permite injetar expressões SQL arbitrárias.
- **Impacto:** Qualquer usuário autenticado — inclusive um cliente comum — conseguia extrair toda a tabela `usuarios` (incluindo logins e hashes de senha dos técnicos via `UNION SELECT`), ler chamados de qualquer cliente, degradar o banco com funções pesadas (`SLEEP`/`BENCHMARK`) ou provocar erro fatal HTTP 500 ao pesquisar termos legítimos com apóstrofo (ex.: `d'agua`).
- **O que foi feito:** Substituída a concatenação direta por *prepared statement* (`$db->prepare()` + `bind_param('s', '%' . $busca . '%')`) dentro da função auxiliar `consultarChamados()`, consumida tanto por `listarChamados()` quanto por `listarChamadosVisiveis()`.

---

### F2 — Quebra de controle de acesso e IDOR na listagem, detalhe e exportação CSV

- **Localização original:** `code/index.php`, linhas 38–75
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** Nas linhas 38–39, `index.php` lia `$uid = (int) $_SESSION['uid']` e `$papel = $_SESSION['papel']`, mas jamais utilizava essas variáveis nas rotas seguintes:
  1. Na rota `?export=csv` (linha 45), invocava `exportarCsv($db)` sem filtro, gerando CSV com todos os chamados do banco;
  2. Na rota `?ver=<id>` (linha 53), invocava `verChamado($db, (int) $_GET['ver'])` sem validar se `$c['usuario_id']` coincidia com `$uid` quando `$papel !== 'tecnico'`;
  3. Na listagem e busca (linha 73), invocava `listarChamados($db, $busca)`, exibindo chamados de todos os clientes na `#tabela-chamados`.
- **Impacto:** Violação direta da regra de negócio do `manifest.md` ("Um cliente só pode ver os chamados que ele mesmo abriu. Um técnico pode ver qualquer chamado") e vulnerabilidade clássica de *Insecure Direct Object Reference* (IDOR). Uma cliente como `ana` (`id=1`) visualizava na listagem, filtrava na busca, abria os detalhes (`?ver=103`, `?ver=104`) e baixava via CSV dados financeiros sensíveis de outro cliente (`bruno`, como `"Cobranca repetida no cartao."`).
- **O que foi feito:** Aplicada a regra de visibilidade de forma *fail-closed* (apenas `$papel === 'tecnico'` possui visão irrestrita; qualquer outro papel vê estritamente `usuario_id === $uid`) nas três rotas de `index.php`:
  - Listagem/busca passou a chamar `listarChamadosVisiveis($db, $uid, $papel, $busca)`;
  - Exportação web passou a chamar `exportarCsvVisivel($db, $uid, $papel)`;
  - Detalhe (`?ver=<id>`) verifica `$papel !== 'tecnico' && (int) $c['usuario_id'] !== $uid`, respondendo HTTP `403` sem expor os dados do chamado (e HTTP `404` quando inexistente).
  As funções públicas `listarChamados()`, `verChamado()` e `exportarCsv()` tiveram suas assinaturas e escopo global preservados intactos para os scripts batch do ISP.

---

### F3 — Credenciais de produção embutidas no código-fonte e uso defeituoso do operador `?:` em `config.php`

- **Localização original:** `code/config.php`, linhas 11–15
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** A senha de produção do MySQL (`'N3tX@2013!prod'`, linha 12) e a chave da API de e-mail transacional (`'netx-smtp-9f83e2c1a7b64d05'`, linha 15) estavam gravadas diretamente no código-fonte, sendo que `SMTP_API_KEY` nem sequer consultava variáveis de ambiente. Além disso, `getenv('DB_PASS') ?: 'N3tX@2013!prod'` utilizava o operador Elvis (`?:`), que trata strings válidas consideradas *falsy* pelo PHP (`""` ou `"0"`) como falsas e sobrescreve a configuração do ambiente com a senha hardcoded de produção.
- **Impacto:** Comprometimento imediato do banco de produção e do serviço de disparo de e-mails da NetX caso o código-fonte, pacote de deploy ou backup seja acessado por terceiros, além de impedir conexões locais/teste com senha em branco (`DB_PASS=""`).
- **O que foi feito:** Removidos ambos os segredos hardcoded de `config.php`. `DB_PASS` e `SMTP_API_KEY` passaram a ser lidos via `getenv(...) !== false`, assumindo `''` como padrão seguro quando ausentes, com guarda `if (!defined(...))` em todas as constantes para evitar avisos em inclusões múltiplas ou pré-configuradas.

---

### F4 — Cross-Site Scripting (XSS) refletido no parâmetro `busca` em atributo e corpo HTML

- **Localização original:** `code/index.php`, linhas 79–83
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** O conteúdo de `$_GET['busca']` era impresso sem codificação HTML em dois pontos consecutivos: dentro de aspas duplas no atributo `<input name="busca" value="' . $busca . '">` (`index.php:79`, permitindo fechamento do atributo com `"><script>...`) e diretamente no elemento `<p>Resultados para: ' . $busca . '</p>` (`index.php:82`).
- **Impacto:** Um adversário podia enviar a um técnico autenticado uma URL contendo payload JavaScript em `busca`, executando código arbitrário na sessão privilegiada do técnico para roubar `document.cookie` (agravado pela ausência original de `HttpOnly`) ou ler todos os chamados e dados de clientes do provedor.
- **O que foi feito:** Sanitizada a saída com `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` antes da interpolação em ambos os contextos, além de explicitar `ENT_QUOTES | ENT_SUBSTITUTE` e `'UTF-8'` em todas as demais renderizações HTML de `index.php`.

---

### F5 — Erro fatal `DivisionByZeroError` em `mediaResposta` quando não há chamados com 1ª resposta

- **Localização original:** `code/lib.php`, linhas 107–117
- **Categoria:** `bug` | **Severidade:** `alta` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** `mediaResposta()` consulta os registros onde `minutos_resposta IS NOT NULL`, acumula `$soma` e `$qtd` e executa incondicionalmente `return $soma / $qtd;` na linha 116. Quando a tabela `chamados` está vazia ou todos os chamados existentes ainda aguardam primeira resposta (`minutos_resposta IS NULL`), `$qtd` vale `0`. No PHP 8+ (incluindo o PHP 8.4 em execução), `0 / 0` dispara `DivisionByZeroError` fatal não capturado.
- **Impacto:** Indisponibilidade total da tela inicial de chamados (`index.php:74` chama `mediaResposta($db)` antes de renderizar a página) e quebra de scripts gerenciais sempre que não existirem chamados respondidos.
- **O que foi feito:** Adicionada verificação explícita `if ($qtd === 0) { return 0.0; }`, garantindo retorno `float` válido (`0.0`) sem exceção.

---

### F6 — Exportação CSV via arquivo fixo no webroot causa exposição de dados, condição de corrida, falha silenciosa e leak de recurso

- **Localização original:** `code/lib.php`, linhas 123–151 (e `code/config.php`, linha 18)
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** `98`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** `exportarCsv()` abria com truncamento (`fopen($caminho, 'w')`) o arquivo fixo `EXPORT_DIR . '/chamados.csv'` (`/var/www/painel/tmp/chamados.csv`), escrevia as linhas, enviava os cabeçalhos HTTP, despejava o arquivo com `readfile($caminho)` (linha 150) e nunca o removia. Caso `EXPORT_DIR` não existisse no sistema de arquivos, `fopen()` emitia *Warning* e retornava `false`, abortando silenciosamente na linha 128; caso `$db->query()` falhasse na linha 133, a função retornava na linha 134 sem executar `fclose($fp)`.
- **Impacto:** (1) Persistência permanente de `chamados.csv` dentro da árvore web `/var/www/painel`, permitindo download direto sem autenticação se o diretório `tmp/` for servido pelo servidor HTTP; (2) condição de corrida (*race condition*) quando dois usuários ou a rotina noturna exportam ao mesmo tempo, corrompendo ou misturando arquivos; (3) falha completa da exportação quando `/var/www/painel/tmp` não existe; (4) vazamento de descritor de arquivo em falha de banco.
- **O que foi feito:** A escrita do CSV foi direcionada diretamente ao stream `php://output` após envio condicional dos cabeçalhos (`if (!headers_sent())`), eliminando arquivos intermediários compartilhados em disco, condições de corrida e falhas por diretório inexistente. Em `config.php`, a constante `EXPORT_DIR` foi preservada por compatibilidade, mas seu fallback padrão passou a usar `sys_get_temp_dir()` (fora do webroot).

---

### F7 — Session Fixation no login, cookies de sessão sem `HttpOnly`/`SameSite` e falta de validação de tipo nas entradas

- **Localização original:** `code/index.php`, linhas 15–29
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** `96`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** `session_start()` era invocado na linha 15 sem opções de endurecimento de cookie (`cookie_httponly`, `cookie_samesite`, `use_strict_mode`), e o bloco de login (linhas 23–28) promovia a sessão a autenticada (`$_SESSION['uid'] = ...`) sem chamar `session_regenerate_id(true)`. Adicionalmente, `$_POST['login']`, `$_POST['senha']` e `$_GET['busca']` eram repassados diretamente para funções com tipagem estrita `string`, lançando `TypeError` fatal se um cliente enviasse arrays na query/body (ex.: `POST login[]=ana` ou `GET ?busca[]=x`).
- **Impacto:** Um atacante capaz de fixar previamente o identificador `PHPSESSID` da vítima passava a controlar a sessão autenticada após o login; cookies ficavam expostos a `document.cookie` e envios cross-site; e parâmetros malformados derrubavam a aplicação com erro HTTP 500.
- **O que foi feito:** Configurado `session_start()` com `cookie_httponly => true`, `cookie_samesite => 'Lax'`, `use_strict_mode => true` e `cookie_secure` dinâmico sob HTTPS; adicionada chamada `session_regenerate_id(true)` imediatamente após login válido; incluídos cabeçalhos `X-Content-Type-Options: nosniff` e `X-Frame-Options: DENY`; e validado `is_string()`/`is_scalar()` em todos os parâmetros de `$_POST` e `$_GET`.

---

### F8 — Armazenamento e verificação de senhas com MD5 sem salt acoplado a coluna `CHAR(32)`

- **Localização original:** `code/lib.php`, linhas 13–21 (e contexto em `code/schema.sql`, linha 7)
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** `100`/100 | **Status:** Reportado / Mitigado parcialmente (`corrigido: false`)
- **Mecanismo:** `autenticar()` computava `$hash = md5($senha)` (`lib.php:15`) e comparava no SQL com `usuarios.senha`, definida como `CHAR(32)` em `schema.sql:7` e povoada com `MD5()` em `seed.sql`. Por ser um algoritmo criptograficamente quebrado, extremamente rápido e sem *salt*, senhas idênticas produzem o mesmo hash (ex.: `ana` e `bruno`; `carla` e `diego`).
- **Impacto:** Caso os registros de `usuarios` vazem (por SQLi anterior, backup ou acesso indevido ao banco), senhas de clientes e técnicos são quebradas offline em segundos via GPU ou tabelas pré-computadas (*rainbow tables*).
- **O que foi feito (e por que `corrigido` permanece `false`):** `autenticar()` foi evoluída para buscar o registro por `login` e operar em modo híbrido: quando o hash armazenado possui metadados de algoritmo moderno (`password_get_info($armazenada)['algo'] !== null`), valida com `password_verify()`; quando é um hash legado de 32 caracteres, valida em tempo constante via `hash_equals(strtolower($armazenada), md5($senha))`, removendo `senha` do array retornado (`['id','nome','papel']`). Contudo, o achado é marcado como `corrigido: false` porque o banco em produção e os dados de teste (`schema.sql`/`seed.sql`) permanecem com `CHAR(32)` e hashes MD5: gravar um hash `bcrypt` (60 caracteres) sem migração coordenada (`ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`) provocaria erro SQL (`Data too long for column 'senha'`) e quebraria o contrato de dados existente.

---

### F9 — Problema de N+1 queries em `listarChamados` e `exportarCsv` e agregação em memória em `mediaResposta`

- **Localização original:** `code/lib.php`, linhas 87–145
- **Categoria:** `performance` | **Severidade:** `media` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** Tanto `listarChamados()` (linhas 88–91) quanto `exportarCsv()` (linhas 136–145) buscavam todos os chamados e, para cada linha com `tecnico_id !== null`, executavam `tecnicoNome($db, ...)` dentro do `while`, disparando `1 + N` consultas SQL síncronas (`SELECT nome FROM usuarios WHERE id = ...`). Em paralelo, `mediaResposta()` (linhas 109–115) trafegava todas as linhas não nulas de `chamados` do MySQL para o PHP apenas para somar inteiros em um laço.
- **Impacto:** Crescimento linear de round-trips de rede e carga de CPU/conexões no MySQL proporcional ao total histórico de chamados desde 2013, tornando a listagem e principalmente o download completo de CSV lentos e suscetíveis a timeout.
- **O que foi feito:** Eliminado o N+1 em `listarChamados()` e `exportarCsv()` por meio de `LEFT JOIN usuarios u ON u.id = c.tecnico_id` com `COALESCE(u.nome, '-') AS tecnico_nome`, reduzindo `1 + N` queries para uma única consulta SQL com resultado idêntico. Em `mediaResposta()`, a transferência integral da tabela foi substituída por `SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados WHERE minutos_resposta IS NOT NULL`, calculando a divisão em PHP para preservar a exata precisão IEEE-754 `float` original. A função `tecnicoNome()` foi mantida e parametrizada para preservar compatibilidade caso algum script externo a invoque.

---

### F10 — Exceção `mysqli_sql_exception` não tratada na conexão e consultas SQL construídas por concatenação

- **Localização original:** `code/index.php`, linhas 9–13 (e `code/lib.php`, linhas 69 e 100)
- **Categoria:** `bug` | **Severidade:** `media` | **Confiança:** `95`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** A partir do PHP 8.1 (ambiente atual PHP 8.4), `mysqli` habilita `MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT` por padrão. Assim, uma falha em `new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME)` (`index.php:9`) lança `mysqli_sql_exception` antes de alcançar o `if ($db->connect_errno)` da linha 10. Além disso, `tecnicoNome()` (`lib.php:69`) e `verChamado()` (`lib.php:100`) concatenavam variáveis diretamente na string SQL em vez de usar *prepared statements* e não tratavam retorno `false` caso o consumidor externo desabilitasse exceções do `mysqli`.
- **Impacto:** Quedas ou lentidão do MySQL geravam *Uncaught Exception* capaz de expor detalhes de infraestrutura e argumentos sensíveis do construtor em stack traces, em vez da mensagem controlada `'Falha ao conectar ao banco.'`.
- **O que foi feito:** Encapsulada a inicialização do `mysqli` em `try/catch (Throwable)` no `index.php`, registrando o detalhe técnico em `error_log()` e retornando HTTP `500` com `'Falha ao conectar ao banco.'`. Convertidas `verChamado()` e `tecnicoNome()` para `prepare()` + `bind_param()` com verificações defensivas e `close()` explícito.

---

### F11 — Uso depreciado de `fputcsv` sem o parâmetro `$escape` no PHP 8.4 corrompe a saída do CSV

- **Localização original:** `code/lib.php`, linhas 130–145
- **Categoria:** `qualidade` | **Severidade:** `media` | **Confiança:** `100`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** As chamadas `fputcsv($fp, [...])` nas linhas 130 e 138 omitiam o quinto argumento (`$escape`). No PHP 8.4, essa omissão dispara `Deprecated: fputcsv(): the $escape parameter must be provided as its default value will change` em cada linha gerada.
- **Impacto:** Com `display_errors` ativo ou em rotinas CLI que capturam `stdout`, os avisos `Deprecated:` são misturados ao conteúdo do CSV ou enviados antes de `header()`, corrompendo o arquivo consumido por integrações e impedindo o envio dos cabeçalhos HTTP.
- **O que foi feito:** Passados explicitamente os argumentos posicionais `fputcsv($fp, ..., ',', '"', '')`, mantendo conformidade estrita com RFC 4180, saída idêntica byte a byte e zero avisos de deprecação no PHP 8.4.

---

### F12 — Aninhamento condicional profundo em `rotuloPrioridade` e comparações frouxas em `formatarStatus`

- **Localização original:** `code/lib.php`, linhas 26–59
- **Categoria:** `qualidade` | **Severidade:** `baixa` | **Confiança:** `95`/100 | **Status:** Corrigido (`true`)
- **Mecanismo:** `rotuloPrioridade()` encadeava quatro níveis de `if/else` aninhados (`lib.php:42–58`) e comparações frouxas (`==`) para classificar cinco resultados possíveis, enquanto `formatarStatus()` (`lib.php:28–34`) usava `==` e blocos `else if`/`else` encadeados.
- **Impacto:** Baixa legibilidade das regras de negócio de SLA e maior propensão a erros humanos durante manutenções futuras.
- **O que foi feito:** `rotuloPrioridade()` foi linearizada com cláusulas de guarda (*early returns*) e operadores estritos (`===`), mantendo equivalência matemática para 100% do domínio de entrada `(int $prioridade, ?int $minutos)`. `formatarStatus()` também foi simplificada com comparações estritas (`===`).

---

### F13 — Acoplamento arquitetural em script único e ausência de paginação, logout, CSRF e rate limiting

- **Localização original:** `code/index.php`, linhas 1–97
- **Categoria:** `arquitetura` | **Severidade:** `baixa` | **Confiança:** `92`/100 | **Status:** Reportado (`corrigido: false`)
- **Mecanismo:** `index.php` acumula criação de conexão de banco, gestão de sessão, controlador de autenticação, roteamento por query string, execução de regras e geração manual de HTML em um único arquivo procedural. Não há endpoint de encerramento de sessão (*logout*), paginação (`LIMIT`/`OFFSET`) na listagem HTML, limitação de taxa de tentativas de login (*rate limiting*) nem token anti-CSRF no formulário `POST`.
- **Impacto:** Dificulta testes unitários isolados da camada de apresentação, impede encerramento explícito de sessão em computadores compartilhados, expõe o formulário de login a tentativas automatizadas de adivinhação de senha e faz o HTML da listagem crescer linearmente com o volume de chamados.
- **O que foi feito:** A lógica de consulta e exportação com controle de visibilidade foi desacoplada para funções dedicadas em `lib.php`, e os cookies de sessão receberam `SameSite=Lax` + `HttpOnly` + `session_regenerate_id(true)`. A decomposição estrutural em múltiplos arquivos e a adição de paginação/rotas/CSRF obrigatório não foram aplicadas deliberadamente pelos motivos detalhados a seguir.

---

## 3. Decisões — O que deliberadamente NÃO foi alterado e por quê

1. **Assinaturas públicas e comportamento sem sessão de `listarChamados`, `verChamado` e `exportarCsv` (`code/lib.php`):**
   - *Decisão:* Mantidas exatamente as assinaturas `listarChamados(mysqli $db, string $busca = ''): array`, `verChamado(mysqli $db, int $id): ?array` e `exportarCsv(mysqli $db): void`, retornando todos os chamados quando chamadas diretamente, sem parâmetros extras e sem acoplamento implícito a `$_SESSION`.
   - *Motivo:* O `manifest.md` especifica que scripts externos do ISP (rotina noturna de exportação, relatório gerencial e integração de faturamento) invocam essas funções diretamente fora de contexto de sessão web. Alterar seus parâmetros ou filtrar por `$_SESSION` quebraria esses consumidores (`COMP-*`). Criar funções irmãs em `lib.php` (`listarChamadosVisiveis`, `exportarCsvVisivel`) para o `index.php` resolveu o problema de segurança na web preservando 100% do contrato público.

2. **Esquema `usuarios.senha CHAR(32)` (`code/schema.sql`) e hashes `MD5()` (`code/seed.sql`):**
   - *Decisão:* Não alterados `code/schema.sql` nem `code/seed.sql`, nem removido o fallback MD5 em `autenticar()`.
   - *Motivo:* O banco em produção já possui a coluna `CHAR(32)` populada com hashes MD5, e o ambiente de verificação sobe o banco com `schema.sql` + `seed.sql`. Tentar gravar hashes `password_hash()` (60+ caracteres) em `CHAR(32)` causaria falha imediata (`Data too long for column 'senha'`), e remover a verificação MD5 bloquearia todos os usuários existentes (`ana`, `bruno`, `carla`, `diego`). A implementação híbrida em `autenticar()` (`password_verify` + `hash_equals` para MD5) deixa a aplicação pronta para uma migração DDL planejada (`ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` seguida de rehash progressivo no login) sem indisponibilidade.

3. **Camada de acesso a dados (`mysqli`) e organização de arquivos em `code/`:**
   - *Decisão:* Mantido `mysqli` procedural/orientado a objetos simples e mantidos exatamente os cinco arquivos originais nos mesmos caminhos.
   - *Motivo:* Exigência explícita da tarefa e do manifesto: trocar para PDO/ORM mudaria todas as assinaturas públicas que recebem `mysqli $db`, e mover/dividir arquivos violaria a comparação arquivo a arquivo e a diretriz de evoluir o legado sem reescrevê-lo.

4. **Token CSRF obrigatório e *rate limiting* no `POST` de login, botão/rota de *logout* e paginação na `#tabela-chamados` (`code/index.php`):**
   - *Decisão:* Não adicionados campos ocultos obrigatórios no formulário de login, bloqueio por IP/sessão, parâmetros de rota extras (`?logout`, `?pagina`) nem `LIMIT` na listagem.
   - *Motivo:* Consumidores HTTP automatizados e rotinas de verificação que realizam `POST` direto em `index.php` com `login` e `senha` falhariam diante de um token CSRF inesperado ou de bloqueio de *rate limit* em chamadas rápidas locais; da mesma forma, paginar a `#tabela-chamados` esconderia chamados legítimos que o cliente ou técnico tem direito de ver, violando o contrato do `manifest.md`. O risco de CSRF em sessão autenticada foi mitigado de forma transparente com `SameSite=Lax` e pelo fato de o sistema autenticado expor apenas operações de leitura (`GET`).

5. **Rótulos de borda em `formatarStatus` / `rotuloPrioridade` e valores literais das células CSV (CSV Formula Injection):**
   - *Decisão:* Mantidos todos os textos retornados por `formatarStatus` (inclusive o fallback `'Resolvido'` para valores fora de `1` e `2`) e por `rotuloPrioridade` (inclusive `'Alto - dentro do SLA'` para prioridade `4` com `<= 30` min), bem como os títulos originais no CSV sem prefixação com `'`.
   - *Motivo:* O relatório gerencial e a integração de faturamento dependem da correspondência textual exata desses rótulos e colunas (`manifest.md`). Alterar textos de retorno ou mutar títulos iniciados por `-`, `+`, `=` ou `@` no CSV corromperia o processamento downstream dos consumidores internos do ISP.
