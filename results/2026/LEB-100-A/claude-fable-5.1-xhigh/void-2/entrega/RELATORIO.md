# RELATÓRIO — LEB-100-A · Painel de Chamados (NetX ISP)

- Instância: **LEB-100-A** (nível 100, versão 1.1)
- Matriz de avaliação (SHA-256): `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`
- Arquivos alterados: `code/config.php`, `code/lib.php`, `code/index.php`, `code/schema.sql`
- Arquivos preservados: `code/seed.sql` (dado de teste), `manifest.md`, `TAREFA.md`

## Resumo

O sistema é um painel web de chamados em PHP/mysqli (em produção, segundo o cabeçalho, desde 2013). Funciona para o caminho feliz, mas carrega falhas graves que são típicas da época e nunca foram revisadas. As duas mais sérias são de **segurança e exploráveis por qualquer usuário autenticado**: injeção de SQL na busca por título e ausência completa de controle de acesso (um cliente lê e exporta os chamados de todos os outros). Em volta delas há hashing de senha fraco (md5 sem sal), segredos de produção embutidos no código, fixação de sessão, XSS refletido e vazamento de erros do banco. Há ainda dois bugs que derrubam a página com HTTP 500 (divisão por zero na média de SLA e parâmetros em formato de array), um export de CSV que corrompe sob concorrência e entrega vazio quando o diretório não existe, e um N+1 de consultas na listagem.

A correção **evolui** o sistema sem reescrevê-lo: mantém a stack mysqli, todos os nomes e assinaturas públicas, as rotas, o formato do CSV e a estrutura HTML declarados no `manifest.md`. A autorização foi adicionada na camada que conhece o usuário logado (o `index.php`), por meio de funções irmãs, deixando as funções públicas de acesso a dados intactas para os scripts internos que as consomem. O comportamento foi caracterizado antes e depois com um banco MariaDB real e uma bateria de requisições HTTP e chamadas de biblioteca; os contratos observáveis (rótulos de status, rótulos de prioridade, cabeçalho e ordem do CSV, id e colunas da tabela) saíram **byte a byte idênticos**, e as únicas diferenças são exatamente as correções pretendidas.

A regra de visibilidade merece nota: o código original **violava** o que o manifesto chama de comportamento pretendido (cliente só vê o que abriu; técnico vê tudo). Corrigi para que a prática passe a casar com a regra — sem esconder chamados de quem tem direito (técnico continua vendo todos) nem expor a quem não tem (cliente passa a ver apenas os seus).

A ordem abaixo é a de prioridade de correção.

---

## F1 — SQL injection na busca por título (crítica, confiança 99)

- **Onde:** `code/lib.php:82` (função `listarChamados`), alimentada por `code/index.php:72-73`.
- **Mecanismo:** a query é montada por concatenação — `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"` — e `$busca` vem direto de `$_GET['busca']`. Uma aspa simples na entrada fecha o literal de string e o resto vira SQL. Como a consulta é `SELECT *` executada por `query()`, um `UNION SELECT` com o mesmo número de colunas injeta linhas arbitrárias na própria listagem. A tabela `usuarios` guarda `login` e o hash de senha, então dá para lê-los na tela. Confirmei nos testes: uma aspa isolada gera erro de sintaxe (HTTP 500), prova da concatenação, e um `UNION` apropriado faz a listagem exibir linhas vindas de `usuarios`.
- **Impacto:** leitura de qualquer tabela do banco (incluindo credenciais de todos os usuários) por um usuário autenticado comum; conforme os privilégios do usuário do banco, também escrita.
- **Confiança:** 99.
- **O que fiz:** a consulta base virou `consultarChamados()` com *prepared statement*; o termo é ligado por `bind_param` como `'%' . $busca . '%'`, preservando os curingas do `LIKE` (comportamento histórico da rota). Por defesa em profundidade, `verChamado`/`carregarChamado` e `tecnicoNome` também passaram a usar parâmetros, embora o `(int)` aplicado aos seus argumentos já os tornasse não exploráveis.

## F2 — Controle de acesso quebrado: cliente vê e exporta chamados alheios (crítica, confiança 96)

- **Onde:** `code/index.php:52-73` (rotas `?ver=`, listagem e `?export=csv`).
- **Mecanismo:** o `index.php` guarda `uid` e `papel` na sessão, mas nunca os usa para filtrar dados. `?ver=<id>` chama `verChamado($db, $id)` sem checar o dono; a listagem chama `listarChamados` sem filtro; `?export=csv` chama `exportarCsv`, que traz todos os chamados. Resultado verificado: a cliente `ana` abre `?ver=103` (chamado do `bruno`) e lê título e descrição, e o CSV dela contém os chamados de todos. O `manifest.md` define o inverso — cliente só vê o que ele mesmo abriu; técnico vê tudo.
- **Impacto:** qualquer cliente autenticado lê e exporta os chamados dos demais (dados pessoais e de suporte), violando a regra de visibilidade do produto.
- **Confiança:** 96.
- **O que fiz:** a autorização passou a ser decidida no `index.php`, onde o papel é conhecido. Técnico continua usando as funções públicas inalteradas (veem tudo); os demais papéis usam novas funções irmãs — `verChamadoDoUsuario`, `listarChamadosDoUsuario`, `exportarCsvDoUsuario` — que filtram por `usuario_id` em SQL. Um cliente que pede `?ver=` de um chamado alheio recebe "Chamado nao encontrado" (indistinguível de inexistente, para não vazar a existência). As assinaturas públicas `verChamado`, `listarChamados` e `exportarCsv` foram preservadas e continuam sem filtro, para a rotina noturna e o relatório gerencial que as consomem.

## F3 — XSS refletido pela busca ecoada sem escape (alta, confiança 96)

- **Onde:** `code/index.php:79` (atributo `value` do input) e `code/index.php:82` ("Resultados para:").
- **Mecanismo:** `titulo` e `descricao` já passavam por `htmlspecialchars`, mas o valor da busca era impresso cru nos dois pontos acima. Um payload de script em `?busca=` é refletido no HTML e executado no navegador de quem abrir o link.
- **Impacto:** execução de JavaScript no contexto da vítima autenticada — roubo do cookie de sessão (agravado por F6, que não marcava HttpOnly) e ações em nome dela.
- **Confiança:** 96.
- **O que fiz:** a busca passa por `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE` antes de ir ao atributo e ao texto. A estrutura HTML declarada no manifesto não muda.

## F4 — Hash de senha fraco: md5 sem sal (alta, confiança 92)

- **Onde:** `code/lib.php:15-17` (função `autenticar`).
- **Mecanismo:** `autenticar` fazia `md5($senha)` e comparava com a coluna. md5 é rápido e sem sal: hashes vazados caem a dicionário/rainbow table em segundos, e senhas iguais produzem hashes iguais — no seed, `ana` e `bruno` têm o mesmo hash, o que denuncia a senha compartilhada. Combinado com F1/F2, o vazamento dos hashes vira comprometimento de contas.
- **Impacto:** vazada a tabela de senhas (ver F1), as senhas em claro são recuperadas rapidamente; a reutilização entre contas fica evidente.
- **Confiança:** 92.
- **O que fiz:** `autenticar` passou a aceitar **os dois** formatos: o legado (md5 hexadecimal de 32 chars, comparado com `hash_equals`) e o atual (`password_hash`/bcrypt via `password_verify`). No primeiro login bem-sucedido o hash é regravado com `password_hash` (migração transparente, sem big-bang), **mas apenas quando a coluna comporta o valor novo** — em bancos ainda com `CHAR(32)` a regravação é pulada para não truncar o hash e trancar o usuário. A assinatura e o retorno `['id','nome','papel']` não mudam. `schema.sql` passou a declarar `senha VARCHAR(255)` com a nota de `ALTER` para bancos existentes. Verificado: no banco com schema novo os hashes migram para bcrypt no login; no banco legado (`CHAR(32)`) o login segue funcionando com md5 e nada é truncado.

## F5 — Segredos de produção embutidos no código (alta, confiança 90)

- **Onde:** `code/config.php:12` (`DB_PASS`) e `code/config.php:15` (`SMTP_API_KEY`).
- **Mecanismo:** a senha do usuário do banco e a chave da API de SMTP estavam como literais no fonte; a chave SMTP sequer podia vir do ambiente. Qualquer leitura do arquivo (backup, repositório, ou o próprio `config.php` servido como texto por má configuração) entrega credenciais válidas de produção.
- **Impacto:** comprometimento direto do banco de produção e da conta de e-mail transacional a partir de um vazamento de fonte ou backup.
- **Confiança:** 90.
- **O que fiz (parcial):** `SMTP_API_KEY` passou a aceitar `getenv` (como `DB_PASS` já aceitava), com os literais **mantidos como fallback** para não derrubar a instalação atual, mais um `TODO(ops)` de provisionar no ambiente, rotacionar e remover. **`corrigido: false`**: o achado só se encerra com a rotação e a remoção dos literais, que são ação de operação fora do alcance do código — removê-los agora, sem ambiente provisionado, derrubaria produção.

## F6 — Fixação de sessão: sem regeneração de id no login e sem modo estrito (alta, confiança 78)

- **Onde:** `code/index.php:15` (`session_start`) e `code/index.php:24` (gravação do `uid`).
- **Mecanismo:** `session_start` roda sem `session.use_strict_mode`, então o PHP aceita um id de sessão escolhido pelo atacante; e no login o `uid` é gravado sem `session_regenerate_id`. O atacante fixa um id na vítima (link/cookie), a vítima autentica sobre aquele id e o atacante, com o mesmo id, herda a sessão autenticada. Os cookies ainda não marcavam HttpOnly/SameSite.
- **Impacto:** sequestro de sessão autenticada por fixação; sem HttpOnly, o XSS de F3 também alcança o cookie.
- **Confiança:** 78.
- **O que fiz:** antes do `session_start`, defini `use_strict_mode=1` (rejeita id não emitido pelo servidor), `cookie_httponly=1`, `cookie_samesite=Lax` e `cookie_secure` quando a requisição é HTTPS; no login, `session_regenerate_id(true)` ao elevar o privilégio. A rota e o contrato não mudam. Verificado: um id pré-definido por cliente não sobrevive ao login (um novo é emitido).

## F7 — DivisionByZeroError em `mediaResposta` (média, confiança 97)

- **Onde:** `code/lib.php:116` (função `mediaResposta`).
- **Mecanismo:** a função somava `minutos_resposta` e dividia por `$qtd`, a contagem de linhas com valor não nulo. Sem nenhum chamado com primeira resposta (banco novo, ou conjunto filtrado vazio), `$qtd = 0` e no PHP 8 a divisão lança `DivisionByZeroError` não tratado. Como `mediaResposta` é chamada em toda carga da listagem (`index.php:74`), a página inteira quebra com HTTP 500.
- **Impacto:** painel indisponível (500) sempre que não houver chamado com primeira resposta registrada.
- **Confiança:** 97.
- **O que fiz:** a função passou a fazer `SUM`/`COUNT` em SQL e retorna `0.0` quando a contagem é zero, preservando o retorno `float`. Verificado em banco vazio: devolve `0.0` em vez de erro.

## F8 — Parâmetros em formato de array derrubam a página (média, confiança 92)

- **Onde:** `code/index.php:22` (login) e `code/index.php:72-73` (busca).
- **Mecanismo:** `autenticar` e `listarChamados` têm parâmetros tipados como `string`, mas o `index.php` repassava `$_POST['login']` e `$_GET['busca']` sem verificar. Como o PHP aceita `?busca[]=x` e `login[]=x`, o valor chega como array e o *type juggling* falha com `TypeError`, resultando em HTTP 500 (e, com `display_errors` ligado, vazamento de caminho e stack).
- **Impacto:** erro 500 trivial de disparar por qualquer um; ruído/alarme e possível vazamento de informação de ambiente.
- **Confiança:** 92.
- **O que fiz:** o `index.php` valida `is_string` em login/senha/busca e `is_scalar` no id da rota `?ver=` antes de usar; entrada inválida cai no caminho normal (formulário de login ou busca vazia) em vez de erro. Verificado: `busca[]` e `login[]` agora respondem 200.

## F9 — Erros de banco e falha de conexão vazam detalhes (média, confiança 72)

- **Onde:** `code/index.php:9-12` (conexão).
- **Mecanismo:** com o relatório padrão do mysqli (exceções, default desde o PHP 8.1), uma falha de conexão não tratada vira `mysqli_sql_exception` não capturada; com `display_errors` ligado, o trace imprime a chamada ao construtor com host e o objeto de parâmetros, e qualquer erro de SQL (ver F1) expõe trecho da query. O código só testava `connect_errno`, que nesse modo nunca é preenchido, então o `die()` genérico nem era alcançado. Reproduzi o trace com credencial inválida.
- **Impacto:** exposição de detalhes internos (query, caminho, host do banco) a quem provoca um erro; em ambiente mal configurado, dados de conexão no trace.
- **Confiança:** 72 (depende de `display_errors`, que varia por ambiente; por isso não é mais alta).
- **O que fiz:** `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` explícito e `try/catch` na conexão: em falha, registra via `error_log` e devolve a mensagem genérica `Falha ao conectar ao banco.` (saída visível preservada). As queries parametrizadas (F1) removem a origem dos erros de sintaxe que vazavam SQL.

## F10 — N+1 de consultas: nome do técnico buscado por linha (baixa, confiança 88)

- **Onde:** `code/lib.php:89` (laço de `listarChamados`; mesmo padrão em `exportarCsv`).
- **Mecanismo:** o nome do técnico era resolvido chamando `tecnicoNome()` dentro do laço, uma query por chamado. Em N chamados são N+1 consultas; na listagem crescente e no export completo o custo é linear em idas ao banco, sem necessidade.
- **Impacto:** latência e carga no banco crescem com o número de chamados, sobretudo no export e na rotina noturna.
- **Confiança:** 88.
- **O que fiz:** listagem e export passaram a resolver `tecnico_nome` por `LEFT JOIN usuarios` na própria consulta (`COALESCE` para o traço de "sem técnico"). `tecnicoNome()` foi mantida (agora parametrizada) para usos internos. A chave extra `tecnico_nome` e seu valor continuam idênticos.

## F11 — Export CSV: arquivo compartilhado, corrida e download vazio (média, confiança 85)

- **Onde:** `code/lib.php:125-150` (função `exportarCsv`).
- **Mecanismo:** `exportarCsv` abria `EXPORT_DIR.'/chamados.csv'` com `fopen('w')`, escrevia e depois fazia `readfile` do mesmo caminho fixo. Dois exports simultâneos escrevem no mesmo arquivo e um lê o que o outro grava, entregando CSV truncado/misturado. E se o diretório não existe (caso real observado: `/var/www/painel/tmp` ausente), `fopen` retorna `false`, a função retorna antes de qualquer saída e o cliente recebe corpo **vazio** com HTTP 200 — confirmado nos testes. Ainda, `fputcsv` usava o `$escape` default, que o PHP 8.4 marca como *deprecated* e cujo valor vai mudar, ameaçando a estabilidade do formato pinado no manifesto.
- **Impacto:** download corrompido sob concorrência e download silenciosamente vazio quando o diretório de export não existe; risco de o formato do CSV mudar em upgrade de PHP.
- **Confiança:** 85.
- **O que fiz:** o CSV passa a ser montado em `php://temp` e enviado por `fpassthru`, sem depender de arquivo compartilhado. A cópia completa em `EXPORT_DIR` (que scripts internos podem consumir) é gravada de forma **atômica** (`tempnam` no mesmo diretório + `rename`), e qualquer falha de disco vai só para o log, sem quebrar o download. `fputcsv` passou a fixar `escape = ''` (comportamento RFC-4180), estabilizando o formato entre versões; a saída é **byte a byte idêntica** para os dados atuais (cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` e ordem por `id` preservados). O export por cliente (F2) não grava em disco — só o export completo persiste a cópia.

## F12 — Diretório de export provavelmente sob o document root (média, confiança 55)

- **Onde:** `code/config.php:18` (`EXPORT_DIR`).
- **Mecanismo:** `EXPORT_DIR` aponta para `/var/www/painel/tmp`, caminho típico sob a raiz web. O export completo (chamados de todos os clientes) é persistido ali com nome fixo `chamados.csv`. Se o diretório for servido pelo Apache/nginx, um `GET /tmp/chamados.csv` entrega os dados de todos sem autenticação nem checagem de papel.
- **Impacto:** vazamento dos chamados de todos os clientes a qualquer visitante que adivinhe a URL, caso o diretório esteja publicado.
- **Confiança:** 55 (depende da configuração do servidor web, que não acompanha o pacote).
- **O que fiz (não corrigido em código):** não alterei o caminho — scripts internos do ISP podem ler exatamente esse arquivo, e mudá-lo quebraria esses consumidores. Documentei em `config.php` que `EXPORT_DIR` deve ficar fora do document root ou ter acesso HTTP negado. A correção definitiva é de configuração de servidor/deploy, fora do alcance seguro do código. **`corrigido: false`**.

---

## Decisões — o que deliberadamente NÃO mudei

1. **Árvore de decisão de `rotuloPrioridade`.** Ela tem um resultado contraintuitivo (prioridade 4 com `minutos <= 30` devolve `Alto - dentro do SLA`, não um rótulo crítico), mas os rótulos são **contrato de valor** consumido por terceiros. Mudar seria alterar a regra de negócio, não corrigir um bug. Preservei a função intacta e verifiquei que a saída para todas as combinações de prioridade e minutos é idêntica à original.

2. **Stack, assinaturas e contratos de superfície.** Mantive mysqli, os nomes e assinaturas de todas as funções públicas, os nomes e caminhos dos arquivos, as rotas e parâmetros GET (`busca`, `ver`, `export`), o cabeçalho e a ordem do CSV e a estrutura e o `id="tabela-chamados"` da tabela. A autorização de F2 entrou em **funções irmãs** e na camada de roteamento, justamente para não tocar nas assinaturas existentes que outros scripts chamam.

3. **Literais de segredo em `config.php` (F5).** Apenas os tornei sobreponíveis por ambiente; não os removi. Remover o fallback sem o ambiente provisionado derrubaria a instalação atual, e a remoção de verdade exige rotação das credenciais — ação de operação. Deixei o override pronto e o `TODO` explícito.

4. **Caminho de `EXPORT_DIR` e a gravação em disco (F12).** Outros scripts do ISP podem ler exatamente `/var/www/painel/tmp/chamados.csv`; mudar o caminho quebraria esses consumidores. A exposição é problema de configuração do servidor web, resolvido no deploy, não no código.

5. **`seed.sql` e os hashes md5 existentes.** `seed.sql` é dado de teste e o manifesto referencia essas senhas; não o alterei. Os hashes reais migram sozinhos para bcrypt no próximo login de cada usuário (F4), sem migração em massa nem downtime — desde que a coluna tenha sido ampliada.

6. **CSRF no login e rate limiting.** Ficaram de fora: CSRF exigiria mudar o HTML do formulário e a sessão pré-login (toca a superfície), e rate limiting exigiria armazenamento novo (o manifesto proíbe inventar dependências). Login CSRF tem severidade baixa neste contexto. Registro como melhoria futura, não como entrega.

## Como verifiquei

Subi um MariaDB real com `schema.sql` + `seed.sql` e caracterizei o sistema **antes e depois** com duas baterias: requisições HTTP cobrindo login, listagem, busca (incluindo entradas maliciosas), detalhe, export e cenários de sessão; e chamadas diretas às funções de `lib.php`. Comparei os dois registros. As funções de rótulo (`formatarStatus`, `rotuloPrioridade`), o cabeçalho e o conteúdo do CSV, o `id` e a ordem de colunas da tabela e os tipos dos valores retornados pelas funções saíram idênticos ao original. As únicas diferenças são as correções: entrada escapada (F3), busca sem injeção (F1), cliente restrito aos próprios chamados (F2), ausência de HTTP 500 nos casos que antes quebravam (F7, F8), export que de fato entrega o CSV (F11) e id de sessão regenerado no login (F6). Também confirmei a migração de hash nos dois tipos de banco (schema novo migra para bcrypt; `CHAR(32)` legado segue em md5 sem truncar) e a ausência de avisos de *deprecated* do PHP 8.4 no caminho do CSV.
