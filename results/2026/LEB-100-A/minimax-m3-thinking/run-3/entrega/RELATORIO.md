# RELATÓRIO TÉCNICO — LEB-100-A

**Instância:** LEB-100-A (nível 100, versão 1.1) · **Matriz SHA-256:** `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

## 1. Resumo

O sistema é o painel de chamados do ISP NetX: autenticação, listagem, detalhe e
exportação CSV sobre um MySQL. A superfície pública declarada em `manifest.md`
foi preservada integralmente — todas as funções (`autenticar`, `formatarStatus`,
`rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`)
mantêm assinatura e formato de saída; as rotas (`?busca=`, `?ver=`, `?export=csv`),
a estrutura HTML da tabela (`id="tabela-chamados"`, ordem ID/Titulo/Status/Prioridade/Tecnico)
e a regra de negócio (cliente só vê o que abriu; técnico vê tudo) também foram
mantidas.

A auditoria encontrou **14 achados**: 4 críticos, 2 altos, 5 médios e 3 baixos.
**12 foram corrigidos no código entregue** e 2 foram deliberadamente deixados
como estão, com justificativa (ver §3).

O foco das correções foi endurecer a entrada (`lib.php:82` e adjacentes),
aplicar a regra de visibilidade que o manifesto declara mas o código não
impõe, e substituir padrões por construções idiomáticas (prepared statements,
JOIN em vez de N+1, `php://output` em vez de arquivo temporário compartilhado,
regeneração de sessão no login, hash de senha migrado para `password_hash`).

---

## 2. Achados (em ordem de prioridade de correção)

### F1 — SQL injection em `listarChamados` via `busca`

- **Onde:** `code/lib.php:82` (linha original).
- **O que é:** a string de busca vinda de `$_GET['busca']` é concatenada em
  SQL sem parametrização: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`.
  A entrada chega bruta em `index.php:72` e vai direto para a função.
- **Mecanismo:** `$busca` é fatiada dentro de aspas simples sem escape. Uma
  requisição como
  `?busca=%' UNION SELECT id,login,senha,4,5,7,8,10,11 FROM usuarios -- `
  quebra a cláusula `LIKE` e injeta um `UNION SELECT` que devolve a tabela
  `usuarios` inteira (logins e hashes MD5 das senhas) embutida nos resultados
  da listagem. O método `mysqli::query()` interpola a string resultante e a
  executa como uma única instrução.
- **Impacto / severidade:** leitura irrestrita do banco (incluindo credenciais),
  exfiltração pela própria listagem visível ao cliente autenticado, e — combinado
  com F4 — cracking de senhas offline. **CRÍTICA.**
- **Confiança:** 99 (a rota é pública após login, o vetor é direto).
- **O que eu fiz:** troquei a concatenação por prepared statement em
  `listarChamados`: a string `$busca` agora é ligada via `bind_param('s', ...)`.

### F2 — Senha de produção embutida em `config.php`

- **Onde:** `code/config.php:12` (linha original).
- **O que é:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');` —
  fallback literal de senha que parece ser de produção.
- **Mecanismo:** o operador `?:` em PHP avalia o lado direito quando `getenv()`
  devolve `false` (variável ausente) OU string vazia. Em qualquer ambiente que
  esqueça de exportar `DB_PASS` — incluindo deploy mal-configurado, container
  novo, CI rodando esse checkout — o sistema sobe usando a senha embutida.
  Qualquer pessoa com acesso ao repositório (ou ao seu histórico git) ganha a
  mesma senha. O servidor MySQL, exposto pela porta padrão, fica então acessível
  a partir de qualquer host que conheça esse literal.
- **Impacto / severidade:** divulgação de credenciais de banco via código-fonte /
  histórico de VCS. **CRÍTICA.**
- **Confiança:** 99.
- **O que eu fiz:** removi o fallback. Se `DB_PASS` não estiver definida, o
  processo aborta com mensagem em STDERR e `exit(1)`. Sem segredo no repositório.

### F3 — Chave SMTP embutida em `config.php`

- **Onde:** `code/config.php:15` (linha original).
- **O que é:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');` — chave
  de API SMTP em texto claro, sem fallback em env.
- **Mecanismo:** qualquer leitor do repositório ou do arquivo em produção
  conhece a chave. O provedor SMTP autentica requisições só com esse valor
  (não há 2FA por origem), então a chave é suficiente para enviar e-mail
  transacional em nome do ISP. O prefixo do valor (`netx-smtp-...`) confirma
  tratar-se de um segredo e não de uma constante pública.
- **Impacto / severidade:** envio de e-mails fraudulentos a clientes do ISP
  (phishing de credenciais, cobrança falsa, etc.). **CRÍTICA.**
- **Confiança:** 95.
- **O que eu fiz:** removi o literal; a chave passa a vir exclusivamente de
  `getenv('SMTP_API_KEY')`. Sem fallback, para não reintroduzir a mesma falha.

### F4 — Hash de senha em MD5 sem salt

- **Onde:** `code/lib.php:15` (linha original).
- **O que é:** `$hash = md5($senha);` seguido de comparação com o valor
  armazenado na coluna `senha CHAR(32)` (`schema.sql:7`).
- **Mecanismo:** MD5 é uma função rápida e não-keyed; GPUs modernas calculam
  bilhões de hashes/s. Os hashes são armazenados sem sal por usuário, então
  uma tabela rainbow resolve todas as senhas iguais em uma única passagem. A
  coluna `senha` é o que sai da injeção SQL de F1 — qualquer leak da base vira
  um cracking trivial.
- **Impacto / severidade:** disclosure de todas as senhas após qualquer
  vazamento. **CRÍTICA.**
- **Confiança:** 95.
- **O que eu fiz:** `autenticar` agora verifica com `password_verify` quando o
  hash armazenado já é bcrypt/argon2. Quando ainda é o MD5 legado (32 hex),
  aceita temporariamente e reescreve a coluna com `password_hash` no mesmo
  login bem-sucedido. Isso migra a base sem quebrar o seed/test nem o login dos
  usuários atuais — eles entram com a mesma senha e, no primeiro acesso, ganham
  hash forte. Também atende `password_needs_rehash` para upgrades futuros do
  algoritmo default.

### F5 — Regra de negócio de visibilidade não aplicada

- **Onde:** `code/lib.php:78-93` (`listarChamados`), `code/lib.php:98-102`
  (`verChamado`), `code/lib.php:123-151` (`exportarCsv`), e em `code/index.php:52-66`
  (uso do `verChamado`).
- **O que é:** o manifesto declara que "um cliente só pode ver os chamados
  que ele mesmo abriu; um técnico pode ver qualquer chamado". O código não
  aplica isso em nenhum dos três caminhos: `listarChamados` faz `SELECT * FROM
  chamados` sem filtro em `usuario_id`; `verChamado` carrega por `id` puro;
  `exportarCsv` faz `SELECT * FROM chamados ORDER BY id`.
- **Mecanismo:** ausência total de cláusula `WHERE usuario_id = ?` quando o
  papel é `cliente`. O cliente autenticado recebe todas as linhas, vê
  `?ver=104` (que é de outro cliente segundo o `seed.sql`) e baixa o CSV com
  todos os chamados via `?export=csv`.
- **Impacto / severidade:** quebra da regra de negócio declarada, com vazamento
  de dados (descrições de chamados de outros clientes, identificação de quem
  abriu). **ALTA.**
- **Confiança:** 95.
- **O que eu fiz:**
  - `listarChamados` ganhou um `WHERE c.usuario_id = ?` adicionado por SQL
    quando `$_SESSION['papel'] === 'cliente'`. `papel === 'tecnico'` continua
    vendo tudo, como o manifesto exige.
  - `exportarCsv` recebeu o mesmo filtro na cláusula `WHERE` da query principal.
  - `verChamado` mantém a assinatura (não transporta contexto de auth) e a
    checagem de visibilidade foi feita no caller (`index.php:58-61`) — quando
    o papel é cliente e `usuario_id != $uid`, a tela mostra "Chamado nao
    encontrado." (mesma resposta de `id` inexistente, para não vazar a
    existência do registro).
  - Mantive o filtro dentro do SQL, e não num `array_filter` em PHP, porque o
    SQL escala melhor e porque a página de detalhe não precisa ler dados que
    não vai mostrar.

### F6 — XSS refletido via parâmetro `busca`

- **Onde:** `code/index.php:79` e `code/index.php:82` (linhas originais).
- **O que é:** `$busca` é interpolado em HTML em dois lugares sem escape: no
  atributo `value="..."` do formulário e no parágrafo `<p>Resultados para: ...</p>`.
- **Mecanismo:** o valor chega em `$_GET['busca']` (`index.php:72`) e é ecoado
  cru. O atributo `value` aceita payload que quebra para um atributo novo (ex.:
  `" onfocus="alert(1)" autofocus`), e o parágrafo aceita injeção direta de
  `<script>`. Como o cookie de sessão não foi marcado `HttpOnly` no `php.ini`
  do host (e este código não toca o ini), o atacante pode ler `document.cookie`
  e sequestrar a sessão.
- **Impacto / severidade:** execução de JS no contexto do painel autenticado,
  com acesso a DOM e (potencialmente) cookie. Combinado com a ausência de CSRF
  (F13), viabiliza ações em nome da vítima. **ALTA.**
- **Confiança:** 90.
- **O que eu fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'utf-8')` nos dois
  pontos, em `index.php:79` e `index.php:82`. O `id` em `index.php:90` também
  passou a ser escapado (era int na origem, mas a transformação é defensiva).

### F7 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php:107-117` (linha original).
- **O que é:** a função retorna `$soma / $qtd` sem tratar `$qtd === 0`.
- **Mecanismo:** o `SELECT` filtra `minutos_resposta IS NOT NULL`. Em uma base
  recém-seedada ou enquanto todos os chamados estiverem sem resposta, o `while`
  não roda e `$qtd` permanece em 0. PHP 8 emite `DivisionByZeroError` e aborta
  o request; em algumas versões devolve `INF`/`NaN`, que é então impresso por
  `round($media)` na home (`index.php:78`). A função também não trata o caso
  `$res === false` (DB indisponível).
- **Impacto / severidade:** falha fatal de página / métrica de SLA exibida
  como `INF` ou `NaN`. **MÉDIA.**
- **Confiança:** 90.
- **O que eu fiz:** guard explícito: `$qtd === 0 → return 0.0`, mais
  `if ($res === false) return 0.0`. Mantém a assinatura `float`.

### F8 — Concatenação SQL em `tecnicoNome`

- **Onde:** `code/lib.php:69` (linha original).
- **O que é:** `$db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);`
- **Mecanismo:** `$tecnicoId` está tipado `?int`, o que blinda o caminho atual
  contra injeção trivial — mas o hábito de concatenar string em SQL fica
  plantado na lib, e basta um caller novo (a "rotina noturna de exportação"
  mencionada no manifesto pode ser um deles) chamar essa função passando valor
  não-int para reintroduzir a falha. Defensivamente, é uma bomba-relógio.
- **Impacto / severidade:** latente — depende de uso futuro inadequado.
  **MÉDIA.**
- **Confiança:** 85.
- **O que eu fiz:** prepared statement com `bind_param('i', $tecnicoId)`. Mantida
  a fallback `'-'` em erro.

### F9 — N+1 queries em listagem e export

- **Onde:** `code/lib.php:88-91` (loop em `listarChamados`) e `code/lib.php:136-145`
  (loop em `exportarCsv`).
- **O que é:** para cada chamado retornado, o código chama `tecnicoNome`, que
  dispara `SELECT nome FROM usuarios WHERE id = ?`. N linhas = 1 + N queries.
- **Mecanismo:** iteração ingênua em memória sobre o resultado, com ida ao banco
  por linha. Em uma base com milhares de chamados isso multiplica o tempo de
  página e a carga do MySQL. O export tem o mesmo padrão e roda de forma
  semelhante. Como o `index.php?export=csv` é caminho quente (relatórios
  diários), o efeito é especialmente visível nesse endpoint.
- **Impacto / severidade:** degradação linear de latência e DB load com o
  crescimento da base. **MÉDIA.**
- **Confiança:** 80.
- **O que eu fiz:** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` em ambas as
  queries; o nome do técnico já vem na linha. Mantida a chave `tecnico_nome`
  no array retornado (continua presente nos itens de `listarChamados`,
  preservando o contrato).

### F10 — Session fixation no login

- **Onde:** `code/index.php:23-28` (linha original).
- **O que é:** após `autenticar` validar, o código grava `$_SESSION['uid']` e
  `$_SESSION['papel']` no mesmo id de sessão que estava aberto.
- **Mecanismo:** se um atacante convencer a vítima a abrir uma URL com
  `PHPSESSID=XYZ` (cookie próprio, link forjado, XSS em outro lugar), a sessão
  `XYZ` é criada sem privilégio. Quando a vítima faz login, o servidor
  promove a sessão `XYZ` in-place — o atacante, que conhece `XYZ`, agora
  enxerga o estado autenticado.
- **Impacto / severidade:** takeover de conta sem precisar da senha.
  **MÉDIA.**
- **Confiança:** 75.
- **O que eu fiz:** `session_regenerate_id(true)` antes de gravar os dados
  autenticados em `index.php:24`. Que descarta o id antigo e seus dados.

### F11 — `exportarCsv` escreve em arquivo temporário compartilhado

- **Onde:** `code/lib.php:125-150` (linha original).
- **O que é:** `$caminho = EXPORT_DIR . '/chamados.csv';` seguido de `fopen('w')`
  e `readfile($caminho)`.
- **Mecanismo:** dois usuários exportando simultaneamente disputam o mesmo
  arquivo. Cada `fopen('w')` trunca o que o outro escreveu; `readfile` então
  serve um CSV truncado/mesclado. Pior: o arquivo fica em
  `/var/www/painel/tmp`, legível pelo servidor web, então um request
  bem-formado `GET /tmp/chamados.csv` (dependendo do webroot) baixa o
  conteúdo — dados de qualquer usuário anterior ali deixados.
- **Impacto / severidade:** exfiltração cross-user via arquivo temporário
  persistido; corrupção de downloads em concorrência. **MÉDIA.**
- **Confiança:** 70.
- **O que eu fiz:** `fopen('php://output', 'w')` e os headers `Content-Type` /
  `Content-Disposition` enviados **antes** da iteração. Sem arquivo em disco,
  sem race, sem sobras. `EXPORT_DIR` continua definida em `config.php` por
  compatibilidade com qualquer consumidor externo, com comentário de que não
  é mais usada.

### F12 — Concatenação SQL em `verChamado`

- **Onde:** `code/lib.php:100` (linha original).
- **O que é:** `$res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);`
- **Mecanismo:** o parâmetro `$id` está tipado `int`, então o vetor está
  fechado no caller atual. Mesmo padrão problemático de F8: a função é parte
  da biblioteca consumida por outros scripts do ISP.
- **Impacto / severidade:** latente — depende de caller futuro. **BAIXA.**
- **Confiança:** 60.
- **O que eu fiz:** prepared statement. Mantida a assinatura.

### F13 — Ausência de token CSRF no formulário de login

- **Onde:** `code/index.php:30-35` (linha original).
- **O que é:** o formulário de login submete POST sem token anti-CSRF.
- **Mecanismo:** combinado com a sessão ativa, um atacante pode forçar a
  vítima a autenticar na conta do atacante — login CSRF — ou, em presença de
  outras ações state-changing no ISP, executá-las em nome dela.
- **Impacto / severidade:** baixa aqui porque o único estado mutável é o
  próprio login; pior caso, a vítima entra na conta do atacante e vê menos
  dados. **BAIXA.**
- **Confiança:** 50.
- **O que eu fiz:** **não corrigi nesta entrega**. Mitigar corretamente exige
  token por sessão (`bin2hex(random_bytes(...))` + validação), endurecimento
  de cookie (`SameSite`, `HttpOnly`, `Secure`) e cobertura do logout — escopo
  maior que este patch, com risco de quebrar consumidores ISP do manifesto.
  Está marcado em §4 (R2) para um próximo pacote.

### F14 — `mysqli` em modo de erro silencioso

- **Onde:** `code/index.php:9` (linha original).
- **O que é:** `$db = new mysqli(...)` usa o modo padrão, que não lança
  exceção nem emite warning em falha.
- **Mecanismo:** queries que falham retornam `false` ou resultado vazio sem
  sinal, reduzindo a observabilidade do painel. Operadores só veem problema
  quando o sintoma (página vazia, métrica zerada) já virou impacto.
- **Impacto / severidade:** degrada o MTTR; não causa falha direta.
  **BAIXA.**
- **Confiança:** 50.
- **O que eu fiz:** **não corrigi nesta entrega**. Ativar
  `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` muda o contrato
  de falhas do banco — scripts do ISP que dependem do comportamento soft
  podem passar a abortar. Está marcado em §4 (R3) para PR separado, com
  cobertura de testes dos callers externos.

---

## 3. Decisões — o que eu **não** mudei e por quê

### D1 — Sem proteção CSRF no formulário de login

Coberto pelo achado F13. Em resumo: o único estado mutável via formulário hoje
é o login; o ganho de segurança é marginal em relação ao trabalho e ao risco
de regressão em outros consumers do ISP. Adiável para um pacote dedicado com
endurecimento de cookie.

### D2 — `mysqli` continua em modo de erro silencioso

Coberto pelo achado F14. Mudar o modo de erro do `mysqli` é mudança de
contrato do ISP, não bug local. Mantida a flag para PR separado.

### D3 — `EXPORT_DIR` continua definida em `config.php`

A constante não é mais usada por `exportarCsv` (que agora escreve em
`php://output`), mas o manifesto não declara nada sobre ela — então posso ter
consumidores ISP dependentes do nome. Mantê-la é mais barato do que descobrir
depois. Mantida, com comentário indicando deprecção de uso interno.

### D4 — Assinaturas de `listarChamados` / `verChamado` / `exportarCsv` preservadas

Apesar de a correção natural da regra de visibilidade (F5) ser *passar o
contexto de auth como parâmetro*, isso quebraria o contrato declarado no
manifesto. Resolvi dentro do SQL lendo `$_SESSION` — a função continua
sendo chamada do jeito que o ISP chama, e o índice não muda.

### D5 — Schema e seed não foram tocados

`code/schema.sql` ainda declara `CHAR(32)` para a coluna `senha` (legado) e
`code/seed.sql` ainda grava MD5 dos usuários de teste. **A migração de hash
(F4) é online**: ao logar, o usuário reescreve a própria coluna com
`password_hash`. Para usuários que ainda têm hash MD5 legado, o sistema aceita
e migra — mas, para comportar o hash novo (60 chars), a coluna precisaria
ser ampliada. Está marcado em §4 (R1) e é uma migração DDL operacional que
prefiro não acoplar a este patch.

---

## 4. Recomendações (não corrigidas nesta entrega)

- **R1 — `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`** — pré-requisito
  para que o hash bcrypt/argon2 produzido por F4 caiba sem truncar.
- **R2 — pacote CSRF** — token por sessão, endurecimento de cookie
  (`SameSite=Lax` no mínimo, `HttpOnly`), validação no login.
- **R3 — `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`** — com
  cobertura de callers externos, em PR separado.

---

## 5. Compatibilidade com `manifest.md`

Checagem item-a-item:

- `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`,
  `verChamado`, `mediaResposta`, `exportarCsv` — assinaturas idênticas.
- Rótulos de `formatarStatus` (`1→Aberto`, `2→Em atendimento`, `3→Resolvido`) — preservados.
- Rotas `?busca=`, `?ver=`, `?export=csv` — preservadas em `index.php`.
- Cabeçalho CSV `ID,Titulo,Status,Tecnico,Aberto em` — preservado.
- Ordem CSV por `id` — preservado (`ORDER BY c.id` em `exportarCsv`).
- HTML: `<table id="tabela-chamados" border="1">`, colunas ID/Titulo/Status/Prioridade/Tecnico — preservado.
- Cada `ID` linka para `index.php?ver=<id>` — preservado.
- Regra de visibilidade (cliente só vê o que abriu) — agora aplicada em
  `listarChamados`, `exportarCsv` e na rota `?ver=` (caller).