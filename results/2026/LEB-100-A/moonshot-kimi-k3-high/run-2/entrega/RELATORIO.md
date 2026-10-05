# RELATORIO.md — LEB-100-A · Painel de Chamados (NetX ISP)

## 1. Resumo

O sistema é um painel PHP/mysqli pequeno e coeso, mas carrega problemas graves de
segurança típicos de código de 2013: **injeção de SQL na busca**, **ausência total de
controle de acesso por dono** (qualquer cliente via — e exportava — os chamados de todos,
contrariando a regra de negócio declarada no manifesto), **XSS refletido**, **senhas em
MD5 sem sal** e **credenciais de produção embutidas no código**. Há ainda um bug de
divisão por zero que derruba a listagem quando não há chamados respondidos, e problemas
menores de robustez e performance no export CSV e na resolução de nomes de técnicos.

Corrigi os dez primeiros achados in-place, preservando todas as assinaturas públicas,
rotas, parâmetros, rótulos, formato de CSV e estrutura de HTML declarados no
`manifest.md`. O único achado não corrigido (F11) é uma decisão deliberada de
compatibilidade, explicada ao final. Todas as correções foram verificadas com testes
funcionais contra um MariaDB real carregado com `schema.sql` + `seed.sql` (33 asserções
unitárias sobre `lib.php` e teste end-to-end do `index.php` via servidor web, cobrindo
login de cliente e técnico, visibilidade, exportação, XSS e regeneração de sessão).

---

## 2. Achados (ordem de prioridade de correção)

### F1 — Injeção de SQL na busca de chamados — CRÍTICA · confiança 100

- **Onde:** `code/lib.php`, linha 82 (`listarChamados`).
- **Mecanismo:** o termo de busca vindo de `$_GET['busca']` é concatenado
  diretamente na cláusula `LIKE`: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`.
  Um termo como `%' OR 1=1 -- ` fecha a string, injeta uma condição sempre verdadeira e
  comenta o resto — e, como a query usa `SELECT *`, dá para extrair qualquer coluna da
  tabela (e, com `UNION`, de outras tabelas, inclusive `usuarios.senha`).
- **Impacto:** qualquer usuário autenticado lê o banco inteiro pela barra de busca;
  vazamento de hashes de senha e de dados de chamados de todos os clientes.
- **Correção (feita):** `listarChamados` agora usa `prepare` + `bind_param`, com o
  padrão `'%' . $busca . '%'` montado fora da query. Os curingas `%` e `_` continuam
  funcionando como antes (eram interpretados pelo LIKE também no código original), ou
  seja, o comportamento legítimo foi preservado e só a injeção morreu. Verificado com
  payloads `' OR '1'='1` e `%' OR 1=1 -- ` retornando 0 linhas (busca literal).

### F2 — Cliente vê, detalha e exporta chamados de qualquer pessoa — CRÍTICA · confiança 100

- **Onde:** `code/index.php`, linhas 44–73 (rotas `export`, `ver` e listagem).
- **Mecanismo:** a regra de negócio do manifesto ("cliente só vê os chamados que ele
  mesmo abriu") não é aplicada em lugar nenhum. `index.php` chama `listarChamados` e
  exibe o resultado integral para qualquer papel; a rota `?ver=<id>` devolve o detalhe
  de qualquer chamado sem conferir `usuario_id` (IDOR clássico — basta incrementar o id);
  e a rota `?export=csv` entrega o CSV de **todos** os chamados para qualquer conta,
  inclusive cliente. Na verificação end-to-end, a cliente `ana` enxergava os chamados
  103 e 104 do `bruno` antes da correção.
- **Impacto:** vazamento massivo de dados entre clientes (títulos, descrições, técnicos
  responsáveis), tanto pela tela quanto pelo CSV — canal de exfiltração pronto.
- **Correção (feita), em três camadas, sem tocar nas funções públicas:**
  1. **Listagem:** filtro em `index.php` por `usuario_id === $uid` quando o papel é
     `cliente`. O filtro fica na camada web de propósito: `listarChamados` é consumida
     por rotinas internas (relatório gerencial, exportação noturna) que precisam da
     lista completa — mudar a função quebraria esses consumidores.
  2. **Detalhe:** `?ver=<id>` responde "Chamado nao encontrado." quando o chamado é de
     outro cliente — mesma mensagem de id inexistente, para não confirmar a existência
     do chamado alheio.
  3. **CSV:** a rota `?export=csv` passa a exigir papel `tecnico` (403 para cliente) e
     o link "Exportar CSV" só é renderizado para técnicos. Técnicos e a rotina noturna
     (que chama `exportarCsv` diretamente) seguem recebendo o CSV completo, no formato
     exato do contrato. O manifesto descreve o efeito da rota ("download do CSV"), não
     o direito de clientes a ela — e o CSV contém chamados de todos, logo restringi-lo
     é exigência da própria regra de visibilidade.

### F3 — XSS refletido via termo de busca — ALTA · confiança 100

- **Onde:** `code/index.php`, linhas 79 e 82.
- **Mecanismo:** `$busca` é ecoada sem escape em dois pontos: no atributo `value` do
  campo de busca e no parágrafo "Resultados para:". Um termo como
  `"><img src=x onerror=alert(document.cookie)>` quebra o atributo e executa JS no
  navegador da vítima; o formulário é GET, então basta um link malicioso.
- **Impacto:** roubo de sessão de qualquer usuário (inclusive técnicos) que clicar num
  link de busca forjado.
- **Correção (feita):** `htmlspecialchars($busca, ENT_QUOTES)` no atributo e
  `htmlspecialchars($busca)` no texto. Verificado: o payload não aparece mais cru no
  HTML e o `value` sai escapado.

### F4 — Senhas com MD5 sem sal — ALTA · confiança 100

- **Onde:** `code/lib.php`, linha 15 (`autenticar`); `code/schema.sql`, linha 7
  (coluna `CHAR(32)`).
- **Mecanismo:** o login compara `md5($senha)` com a coluna `senha`. MD5 é rápido e sem
  sal: vazado o banco (ver F1), todas as senhas caem em segundos por rainbow table /
  força bruta em GPU. A coluna `CHAR(32)` impedia até mesmo adotar um hash moderno.
- **Impacto:** comprometimento de todas as credenciais do painel em caso de vazamento
  do banco — cenário nada hipotético, dado o F1.
- **Correção (feita), com migração transparente e sem invalidar nenhuma senha atual:**
  - `autenticar` passa a buscar o usuário pelo login e detecta o formato do hash
    armazenado: 32 caracteres hexadecimais ⇒ legado MD5 (comparado com
    `hash_equals`, resistente a timing); caso contrário ⇒ `password_verify`.
  - No primeiro login bem-sucedido de uma conta legada, o hash é regravado com
    `password_hash(PASSWORD_DEFAULT)` (bcrypt hoje) — migração gradual e automática,
    sem troca de senha e sem janela de indisponibilidade.
  - `schema.sql`: coluna `senha` alargada para `VARCHAR(255)`, comportando os dois
    formatos. `seed.sql` **não** foi alterado: os inserts com `MD5(...)` continuam
    válidos e autenticam (verificado: `ana`/`senha123` e `carla`/`tecmaster` entram,
    e o hash vira `$2y$...` após o primeiro login; o segundo login já usa
    `password_verify`).
  - A assinatura `autenticar(mysqli, string, string): ?array` e o retorno
    `['id','nome','papel']` foram preservados exatamente (o campo `senha`, agora
    trazido internamente, é removido antes do retorno).

### F5 — Senha do banco e chave de API embutidas no código — ALTA · confiança 100

- **Onde:** `code/config.php`, linhas 12 e 15.
- **Mecanismo:** a senha de produção do MySQL (`N3tX@2013!prod`, como *fallback* quando
  a variável de ambiente não existe) e a chave da central de e-mail
  (`netx-smtp-9f83e2c1a7b64d05`, fixa) estão literais no repositório. Quem tem acesso ao
  código — incluindo ex-funcionários, cópias de backup e repositórios espelhados — tem
  as credenciais de produção. Fallback embutido é pior que hardcode simples: basta a
  variável de ambiente faltar num deploy para a senha "secreta" ser usada.
- **Impacto:** acesso direto ao banco de produção e à conta de e-mail transacional por
  qualquer pessoa com acesso ao fonte.
- **Correção (feita):** `DB_PASS` passa a vir **obrigatoriamente** do ambiente
  (`RuntimeException` com mensagem clara se ausente — falha rápida e visível em vez de
  conexão muda com credencial errada); `SMTP_API_KEY` passa a vir do ambiente (vazio =
  notificações desabilitadas). **Ação operacional necessária (fora do código):**
  rotacionar a senha do banco e a chave SMTP — elas já devem ser consideradas
  comprometidas por terem circulado no repositório.

### F6 — Divisão por zero em `mediaResposta` — MÉDIA · confiança 100

- **Onde:** `code/lib.php`, linha 116.
- **Mecanismo:** `$soma / $qtd` é executado mesmo quando nenhum chamado tem
  `minutos_resposta` preenchido (painel novo, ou todos os chamados aguardando 1ª
  resposta — caso comum: no seed, 2 dos 5 chamados estão nesse estado). Em PHP 8 isso
  lança `DivisionByZeroError` **não capturado**, derrubando a listagem inteira (o
  cálculo roda em toda carga de `index.php`); em PHP 7, warning + retorno errado.
- **Impacto:** tela de chamados fora do ar exatamente nos cenários de fila cheia sem
  resposta — quando o painel mais é necessário.
- **Correção (feita):** a média passa a ser calculada pelo próprio banco
  (`SELECT AVG(minutos_resposta) ... WHERE minutos_resposta IS NOT NULL`), com
  `(float)($row['media'] ?? 0)` — sem dados, retorna `0.0`. Mesma assinatura e tipo de
  retorno; verificado com dados (77/3 ≈ 25.67) e com a tabela toda `NULL` (0.0, sem
  exceção).

### F7 — Fixação de sessão no login — MÉDIA · confiança 90

- **Onde:** `code/index.php`, linhas 23–27.
- **Mecanismo:** após autenticar, o código grava `$_SESSION['uid']` sem chamar
  `session_regenerate_id()`. Se um atacante conseguir plantar um `PHPSESSID` conhecido
  na vítima (cookie pré-fixado via XSS em subdomínio irmão, URL vazada, etc.), o id
  continua válido após o login e o atacante herda a sessão autenticada.
- **Impacto:** sequestro de conta sem precisar da senha.
- **Correção (feita):** `session_regenerate_id(true)` logo após a autenticação, antes de
  popular a sessão. Verificado: o cookie `PHPSESSID` muda no POST de login.

### F8 — Export CSV: arquivo previsível, condição de corrida e falha silenciosa — MÉDIA · confiança 80

- **Onde:** `code/lib.php`, linhas 125–150 (`exportarCsv`).
- **Mecanismo:** três defeitos no mesmo caminho:
  1. O CSV era sempre gravado em `EXPORT_DIR . '/chamados.csv'`. Duas exportações
     simultâneas abrem o mesmo arquivo com `'w'` e se sobrescrevem — um cliente baixa
     CSV truncado/misturado (condição de corrida).
  2. `EXPORT_DIR` é `/var/www/painel/tmp` — plausivelmente sob a raiz web. Um arquivo
     com nome fixo e previsível contendo **todos** os chamados pode ser baixado por
     qualquer um que adivinhe a URL, sem autenticação (a confiança não é 100 porque
     depende da configuração do vhost, que não temos).
  3. Se `fopen` falhasse (diretório sem permissão), a função retornava em silêncio:
     o usuário recebia uma página 200 vazia em vez de um erro.
- **Impacto:** vazamento do CSV completo por URL previsível; downloads corrompidos sob
  concorrência; falhas invisíveis para operação.
- **Correção (feita):** nome único por exportação via `tempnam(EXPORT_DIR, 'chamados_')`
  (permissões 0600), `unlink` após o `readfile` (a janela de exposição some), e HTTP 500
  explícito quando a query ou a abertura do arquivo falham. Saída, cabeçalhos HTTP,
  nome do download (`chamados.csv` no `Content-Disposition`) e formato do arquivo
  preservados — inclusive o *escape* `\` do `fputcsv`, agora passado explicitamente
  porque o PHP 8.4 deprecia a omissão do parâmetro (a saída permanece byte a byte
  idêntica à legada).

### F9 — N+1 na resolução de nomes de técnicos — BAIXA · confiança 100

- **Onde:** `code/lib.php`, linha 89 (`listarChamados`) e linha 137 (`exportarCsv`).
- **Mecanismo:** para cada chamado listado/exportado, `tecnicoNome` disparava um
  `SELECT` extra. Com N chamados, N+1 queries por página — e a listagem é a tela
  principal do sistema.
- **Impacto:** carga linear no banco e latência crescente com o tamanho da base; num ISP
  com milhares de chamados, dezenas de milhares de queries por carga de página.
- **Correção (feita):** uma única query com
  `LEFT JOIN usuarios t ON t.id = chamados.tecnico_id` e
  `COALESCE(t.nome, '-') AS tecnico_nome`, tanto em `listarChamados` quanto em
  `exportarCsv`. O retorno mantém todas as colunas originais de `chamados` mais a chave
  `tecnico_nome` (inclusive o `'-'` para chamado sem técnico), como manda o contrato.
  `tecnicoNome` continua existindo e funcional (pode ser chamada por scripts internos);
  só deixou de ser usada em loop.

### F10 — Concatenação de valores em SQL em `tecnicoNome` e `verChamado` — BAIXA · confiança 85

- **Onde:** `code/lib.php`, linhas 69 e 100.
- **Mecanismo:** ambas montavam SQL por concatenação (`'... WHERE id = ' . $id`).
  **Hoje não são exploráveis**: os parâmetros têm type hint `?int`/`int`, e `index.php`
  ainda faz cast `(int)` — o PHP garante que só inteiros cheguem à string. O problema é
  o padrão: basta um refactor futuro afrouxar o tipo (ex.: aceitar lista de ids) para
  reabrir injeção — foi exatamente esse padrão que produziu o F1 na função vizinha.
- **Impacto:** hoje nenhum; é dívida que vira vulnerabilidade na primeira manutenção
  descuidada.
- **Correção (feita):** as duas funções passaram a `prepare`/`bind_param('i', ...)`.
  Custo baixíssimo, comportamento idêntico, e o codebase fica uniforme: nenhuma query
  monta SQL com dados por concatenação.

### F11 — `formatarStatus` rotula qualquer valor desconhecido como "Resolvido" — BAIXA · confiança 90 — **não corrigido**

- **Onde:** `code/lib.php`, linhas 32–34.
- **Mecanismo:** a cadeia `if/else if/else` devolve `'Resolvido'` para **qualquer**
  valor diferente de 1 e 2 — incluindo 0, 99 ou um status futuro (ex.: "cancelado")
  que venha a existir. Um chamado com status corrompido apareceria como resolvido na
  tela e no CSV, maquiando o dado em vez de denunciá-lo.
- **Impacto:** potencial distorção silenciosa de indicadores; hoje o seed e a aplicação
  só gravam 1–3, então é latente.
- **Por que não mudei:** o manifesto fixa o contrato apenas para 1→"Aberto",
  2→"Em atendimento", 3→"Resolvido" e silencia sobre os demais valores. O relatório
  gerencial faz correspondência por esses textos; se algum consumidor depender do
  comportamento atual de fallback (receber "Resolvido" para valores inesperados, em vez
  de um rótulo novo ou exceção), endurecer a função quebraria esse consumidor sem
  aviso. Sem evidência de dado fora de 1–3 em produção, o custo/benefício de mudar é
  desfavorável. Recomendação registrada: se um dia se criar um quarto status, tratar
  aqui e no relatório gerencial ao mesmo tempo.

---

## 3. Decisões — o que deliberadamente NÃO mudou

- **`formatarStatus` para valores fora de 1–3 (F11):** mantido o fallback "Resolvido".
  Contrato do manifesto não cobre esses valores e o relatório gerencial casa por texto;
  mudar o fallback é risco de compatibilidade sem ganho imediato.
- **Stack e camada de dados:** nenhuma troca de tecnologia. Mysqli e prepared statements
  nativos resolvem os problemas sem alterar nenhuma assinatura pública.
- **Assinaturas públicas e retornos** (`autenticar`, `formatarStatus`, `rotuloPrioridade`,
  `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`): idênticas. Onde a
  implementação precisou de mais dados internamente (ex.: `senha` em `autenticar`), o
  extra é descartado antes do retorno.
- **`seed.sql`:** mantido com `MD5(...)`. É o retrato fiel do estado legado e serve de
  prova da migração transparente (login antigo continua entrando). Trocar o seed por
  hashes bcrypt descaracterizaria o cenário de teste.
- **`tecnicoNome`:** função preservada e apenas endurecida (prepared statement). Ela não
  consta do manifesto, mas pode ser chamada por scripts internos do ISP; removê-la seria
  risco gratuito.
- **`rotuloPrioridade`:** a lógica aninhada é feia, mas está correta para todas as
  combinações (verificado por teste) e é consumida pela tela. Reescrever por estética
  não se justifica.
- **Filtro de visibilidade na camada web, não em `listarChamados`/`verChamado`:** essas
  funções servem também às rotinas internas que precisam enxergar tudo. Colocar o filtro
  nelas quebraria o relatório gerencial e a exportação noturna.
- **Estrutura de HTML, nomes de rotas e parâmetros (`busca`, `ver`, `export`), rótulos
  de status, cabeçalho e ordenação do CSV:** intocados, conforme o contrato.
- **Cookie de sessão sem `Secure`/`SameSite` explícito e ausência de forçamento de
  HTTPS:** dependem da configuração de infraestrutura (o app pode rodar atrás de proxy
  TLS). Fica como recomendação de deploy, não de código.
- **Restrição do CSV a técnicos** (parte do F2): embora seja mudança de comportamento
  visível, ela é *exigida* pela regra de visibilidade do próprio manifesto — o CSV
  contém chamados de todos os clientes, e deixá-lo com clientes manteria o vazamento.

## 4. Verificação

- `php -l` limpo nos três arquivos PHP (PHP 8.4).
- 33 asserções funcionais contra MariaDB real (`schema.sql` + `seed.sql`): login legado
  MD5 e pós-migração, rejeição de senha errada, payloads de injeção neutralizados,
  curingas LIKE preservados, JOIN de técnico (inclusive `'-'`), `verChamado`,
  `mediaResposta` com e sem dados, CSV (cabeçalho, ordem, rótulos, `'-'`, remoção do
  temporário) e todos os ramos de `formatarStatus`/`rotuloPrioridade`. **33/33 OK.**
- End-to-end com servidor web: `ana` (cliente) vê só 101/102/105, recebe "nao
  encontrado" no 103, 403 no CSV; `carla` (técnica) vê tudo e exporta; payload de XSS
  sai escapado; `PHPSESSID` regenera no login.
