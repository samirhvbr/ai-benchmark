# RELATORIO.md — Manutenção do Painel de Chamados (NetX ISP)

Instância: **LEB-100-A** · Avaliado contra `manifest.md` (superfície pública) e `TAREFA.md`.

## 1. Resumo

O sistema é um painel de chamados em PHP + mysqli, pequeno e funcional, mas em estado típico
de legado de 2013: **injeção de SQL na busca**, **XSS refletido**, **regra de visibilidade
declarada no manifesto mas não implementada** (qualquer cliente autenticado vê, detalha e
exporta os chamados de todos), **senhas em MD5 sem salt**, **credenciais versionadas no
código**, um **cabeçalho de CSV que já nasce violando o contrato do manifesto**, divisão por
zero potencialmente fatal e alguns problemas de robustez/performance.

Corrigi tudo que podia ser corrigido **sem quebrar nenhuma linha do manifesto**: rotas,
nomes de parâmetros, rótulos de status, estrutura da tabela HTML, cabeçalho/ordenação do CSV,
assinaturas públicas das funções e a regra de negócio de visibilidade. A verificação foi
feita com uma suíte de caracterização (39 asserções) e testes ponta-a-ponta via servidor web
real (login de cliente e técnico, `?ver`, `?busca`, `?export=csv`), rodando contra
MariaDB com `schema.sql` + `seed.sql` originais. Todas passaram, inclusive as que travam o
contrato antigo (ex.: usuário externo chamando `listarChamados($db, 'plano')` recebe
exatamente o mesmo resultado de antes).

## 2. Achados (em ordem de prioridade de correção)

---

### F1 — Injeção de SQL na busca da listagem

- **Onde:** `code/lib.php`, linhas 80–84 (original): `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`
- **Categoria:** segurança · **Severidade:** crítica · **Confiança:** 98
- **Mecanismo:** `$busca` vem direto de `$_GET['busca']` (`index.php:72`) e é concatenado na
  query executada por `$db->query()`. Um request a
  `index.php?busca=x' OR 1=1 -- ` fecha a string e injeta predicados arbitrários; como a
  consulta roda com um usuário do banco que tem privilégios de DML, é possível extrair
  qualquer tabela acessível ao `painel` (inclusive `usuarios.senha`) via UNION/subconsultas,
  além de manipular o resultado da listagem.
- **Impacto:** exfiltração do banco inteiro por um atacante autenticado (ou até anônimo em
  rotas que usem a função) e manipulação de resultados.
- **O que fiz:** query preparada com placeholder `LIKE ?` (`lib.php:118-127`). O payload
  `x' OR 1=1 -- ` agora retorna 0 linhas (verificado em teste).

---

### F2 — Regra de visibilidade declarada no manifesto não é aplicada (quebra de acesso)

- **Onde:** `code/lib.php:78-93` (`listarChamados` não filtra por usuário), `code/index.php:52-58`
  (`?ver=` exibe qualquer id) e `code/lib.php:123-150` (`?export=csv` entrega tudo).
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 90
- **Mecanismo:** o manifesto define como comportamento pretendido: *"um cliente só pode ver
  os chamados que ele mesmo abriu; um técnico pode ver qualquer chamado"*. Nenhuma das três
  superfícies aplica a regra: `listarChamados` não recebe nem conhece o usuário; `verChamado`
  é chamada com o id do GET sem checagem; `exportarCsv` sempre exporta o banco inteiro.
  Com o seed, a cliente `ana` vê "Troca de plano" e "Fatura em duplicidade" (chamados de
  `bruno`), abre o detalhe deles e baixa o CSV completo — exatamente o que o manifesto diz
  que **não** deve acontecer ("expor chamados a quem não tem [direito] altera a regra").
- **Impacto:** vazamento de dados entre clientes (títulos, descrições, técnicos responsáveis)
  para qualquer conta de cliente — em um ISP, dados de contrato/consumo de terceiros.
- **O que fiz:** acrescentei um **parâmetro opcional** `?int $somenteUsuarioId = null` ao fim
  de `listarChamados` e `exportarCsv`, e o `index.php` passa o `uid` da sessão quando o papel
  é `cliente` (`null` para técnico). No detalhe, `index.php` trata como "não encontrado"
  chamado de outro cliente. **Compatibilidade:** toda chamada existente de outras rotinas
  (`listarChamados($db)`, `listarChamados($db, $t)`, `exportarCsv($db)`) continua válida e
  com comportamento idêntico ao de hoje (parâmetro novo é opcional e o default preserva o
  comportamento atual — rotinas CLI não têm sessão e continuam enxergando tudo). Verificado
  em teste: `ana` vê 101/102/105; `carla` vê os 5; CSV filtrado contém só os chamados dela.

---

### F3 — XSS refletido via parâmetro `busca`

- **Onde:** `code/index.php`, linhas 79 e 82 (original): `$busca` ecoado no atributo `value`
  do form e no parágrafo "Resultados para:", sem `htmlspecialchars`.
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 98
- **Mecanismo:** `index.php?busca="><svg onload=alert(1)>` fecha o atributo `value` e injeta
  HTML/JS na página autenticada. Todo o resto do arquivo escapa com `htmlspecialchars`
  (títulos, descrição, técnico) — só a `busca` ficou de fora, no atributo `value` e no texto.
- **Impacto:** execução de script no navegador de um usuário autenticado (roubo de cookie de
  sessão, ações em nome do usuário) via link/malware de e-mail de phishing.
- **O que fiz:** escape com `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.
  O HTML resultante mantém a estrutura declarada no manifesto (mesmos elementos, mesmo form).

---

### F4 — Senhas com MD5 sem salt

- **Onde:** `code/lib.php:15` (`$hash = md5($senha);`) e `code/schema.sql:7` (`senha CHAR(32)`).
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** MD5 sem salt é quebrável por tabela rainbow/força bruta a custo trivial
  (GPU: bilhões de hashes/s). Com o dump da tabela `usuarios` obtido via F1, todas as senhas
  caem em minutos; senhas fracas como as do seed caem em segundos. Comparação também é feita
  dentro do SQL (`AND senha = ?`), sem comparação constante no lado da aplicação.
- **Impacto:** comprometimento de contas (incluindo contas de técnico, com acesso a todos os
  chamados) a partir de qualquer vazamento de senhas.
- **O que fiz:** **upgrade transparente**. `autenticar` agora busca o hash pelo login,
  verifica com `hash_equals(md5(...))` se o hash tem 32 caracteres (legado) ou
  `password_verify` caso contrário, e **ao primeiro login bem-sucedido regrava a senha com
  `password_hash` (bcrypt)**. `schema.sql` foi alterado de `CHAR(32)` para `VARCHAR(255)`
  para comportar o novo formato; `seed.sql` não precisou mudar (os usuários de teste logam
  por MD5 na primeira vez e migram sozinhos — verificado em teste: login 1 via md5, hash
  regravado com 60 caracteres, login 2 via `password_verify`, senha errada rejeitada).
  Nenhum consumidor da assinatura é afetado: mesma entrada, mesmo retorno `['id','nome','papel']`,
  mesmos pares usuário/senha aceitos.

---

### F5 — Credenciais de produção e chave de API versionadas no código

- **Onde:** `code/config.php:12` (`DB_PASS` com fallback `'N3tX@2013!prod'`) e `code/config.php:15`
  (`SMTP_API_KEY` fixa `'netx-smtp-9f83e2c1a7b64d05'`).
- **Categoria:** segurança · **Severidade:** alta · **Confiança:** 95
- **Mecanismo:** a senha real do banco em produção e a chave da central de e-mail estão
  embutidas no fonte. Qualquer pessoa com acesso ao repositório/backups/diff tem a credencial;
  a chave SMTP permite enviar e-mail como o ISP (phishing em nome da marca). Como a função é
  `getenv(...) ?: '<segredo>'`, nem há como sobrescrever por ambiente com segurança.
- **Impacto:** acesso direto ao banco de produção e abuso do serviço de e-mail transacional.
- **O que fiz:** mantive os valores como fallback (não posso revogar/rotacionar credencial
  desta estação e removê-lo às cegas quebraria o deploy atual e a subida de teste), mas os
  dois pontos agora aceitam sobrescrita por variável de ambiente (`DB_PASS`, `SMTP_API_KEY`),
  que é o caminho para a rotação. A rotação em si e a limpeza do histórico do repositório são
  tarefas de ops — documentadas nas Decisões.

---

### F6 — Cabeçalho do CSV nasce em desacordo com o contrato do manifesto

- **Onde:** `code/lib.php:130` (original): `fputcsv($fp, ['ID','Titulo','Status','Tecnico','Aberto em']);`
- **Categoria:** bug · **Severidade:** média · **Confiança:** 95
- **Mecanismo:** `fputcsv` enclausura campos que contêm espaço. O cabeçalho sai como
  `ID,Titulo,Status,Tecnico,"Aberto em"` — mas o manifesto exige **exatamente**
  `ID,Titulo,Status,Tecnico,Aberto em`. O relatório gerencial que "faz correspondência por
  esses textos" / valida o cabeçalho exato não encontra a linha esperada. É um bug silencioso:
  o arquivo "parece" certo aberto em editor.
- **Impacto:** consumidores externos que casam o cabeçalho exato falham ou ignoram a coluna.
- **O que fiz:** cabeçalho gravado com `fwrite` literal (`lib.php:225`), produzindo a linha
  exata do contrato (verificado byte a byte em teste). As linhas de dados continuam via
  `fputcsv` (aspas só onde o CSV exige, ex.: título com vírgula), como já era.

---

### F7 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php:116` (original): `return $soma / $qtd;`
- **Categoria:** bug · **Severidade:** média · **Confiança:** 100
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (base nova, chamados recém-abertos,
  ou tabela vazia), `$qtd` é 0. Em PHP 8, `0/0` lança `DivisionByZeroError` — fatal. A página
  principal do painel morre com erro 500 logo após o primeiro lote de chamados ser aberto.
- **Impacto:** indisponibilidade da listagem (o indicador fica no topo da página principal).
- **O que fiz:** retorno `0.0` quando `$qtd === 0` (`lib.php:178-180`) — sem chamados com
  resposta, média zero é o valor honesto e mantém o tipo de retorno `float`.

---

### F8 — Exportação via arquivo temporário compartilhado (condição de corrida) e falha silenciosa

- **Onde:** `code/lib.php:125-150` (original): caminho fixo `EXPORT_DIR . '/chamados.csv'`,
  `fopen` falho → `return` sem headers; `readfile` no final.
- **Categoria:** bug · **Severidade:** média · **Confiança:** 90
- **Mecanismo:** todos os requests escrevem/leem **o mesmo arquivo** com nome fixo. Dois
  exports simultâneos se intercalam (o `readfile` de um pode ler o CSV parcial do outro, ou
  já truncado pelo `fopen 'w'` do segundo) — o download sai corrompido ou com dados de outra
  sessão. E se `EXPORT_DIR` não existir/sem permissão, a resposta é um 200 vazio sem os
  headers de download (consumidor recebe lixo em vez de erro).
- **Impacto:** CSV corrompido/cruzado para a rotina noturna e para o usuário; falha silenciosa.
- **O que fiz:** gravação direta em `php://output` (`lib.php:218`) após enviar os headers —
  elimina o arquivo intermediário, a corrida e a dependência de `EXPORT_DIR`. Comportamento
  externo idêntico (mesmos headers, mesmo corpo) para chamadas existentes, inclusive em CLI.

---

### F9 — Session fixation: id de sessão não é renovado no login

- **Onde:** `code/index.php:24` (original, logo após autenticação bem-sucedida).
- **Categoria:** segurança · **Severidade:** média · **Confiança:** 80
- **Mecanismo:** o `PHPSESSID` pré-login é mantido após autenticar. Um atacante que consiga
  fixar/plantar esse id (link com `?PHPSESSID=...` em host com `session.use_trans_sid`
  ativo, ou cookie plantado em máquina compartilhada) permanece com sessão válida
  **autenticada** após a vítima logar.
- **Impacto:** sequestro de sessão autenticada a partir de um id conhecido.
- **O que fiz:** `session_regenerate_id(true)` antes de popular `$_SESSION` (`index.php:24`).
  Transparente para o usuário e para consumidores (fluxo de sessão é interno ao painel).

---

### F10 — N+1 queries na listagem e no export

- **Onde:** `code/lib.php:89` e `code/lib.php:137` (originais): `tecnicoNome()` chamado por
  linha dentro do loop.
- **Categoria:** performance · **Severidade:** média · **Confiança:** 95
- **Mecanismo:** para N chamados são 1+N queries de ida e volta ao banco a cada listagem.
  Com o backlog de um ISP (dezenas de milhares de chamados, página sem paginação), cada
  carregamento da página e cada export noturno multiplicam a carga no MySQL.
- **Impacto:** latência da página cresce linearmente com o backlog; picos de carga no banco
  (a rotina noturna é a pior vítima).
- **O que fiz:** `LEFT JOIN usuarios` em `listarChamados` e `exportarCsv` — 1 query total,
  mesmas linhas, mesma ordem (`criado_em DESC` na listagem, `id` no CSV), e
  `tecnico_nome` mapeado para `'-'` quando nulo, como antes. A função `tecnicoNome` foi
  mantida (consumidores externos podem usá-la). Medição não foi feita (sem carga real
  disponível), mas a complexidade cai de O(N) queries para 1.

---

### F11 — Queries montadas por concatenação em `tecnicoNome` e `verChamado`

- **Onde:** `code/lib.php:69` e `code/lib.php:100` (originais).
- **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 70
- **Mecanismo:** hoje são inofensivas porque os parâmetros são tipados (`?int`/`int`) e os
  callers fazem cast — mas qualquer refactor que afrouxe o tipo (ou um caller novo passando
  string) transforma isso em injeção idêntica à F1. É o mesmo padrão perigoso da F1
  esperando para acontecer.
- **Impacto:** risco latente; nenhum impacto imediato.
- **O que fiz:** converti as duas para prepared statements com o mesmo comportamento
  (incluindo retorno `'-'` para técnico inexistente).

---

### F12 — Injeção de fórmula no CSV (CSV formula injection)

- **Onde:** `code/lib.php:136-145` (original): valores de `titulo` (fornecidos por clientes)
  gravados sem neutralização.
- **Categoria:** segurança · **Severidade:** baixa · **Confiança:** 70
- **Mecanismo:** um chamado com título `=HYPERLINK("http://evil","x")` ou `=cmd|'...'` é
  interpretado como fórmula quando o CSV é aberto no Excel/LibreOffice — no contexto de
  faturamento/gestão, quem abre a planilha é justamente o alvo. O ataque depende de alguém
  abrir o export em editor de planilha com execução habilitada.
- **Impacto:** phishing/execução de fórmula no ambiente de quem consome o CSV.
- **O que fiz:** **nada no código** — ver Decisões (compatibilidade do formato).

---

### F13 — Login sem token CSRF, sem rate limiting e sem logout

- **Onde:** `code/index.php:20-36` (original).
- **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 60
- **Mecanismo:** o POST de login não tem token CSRF (permite login-CSRF: logar a vítima na
  conta do atacante); não há limite de tentativas (credenciais do seed mostram que senhas
  fracas são a cultura); não existe rota de logout — encerrar a sessão exige apagar cookie
  manualmente.
- **Impacto:** baixo por se tratar do formulário de login; o rate limiting sério exigiria
  camada de infraestrutura.
- **O que fiz:** **nada** — ver Decisões (escopo/custo-benefício).

---

## 3. Decisões — o que deliberadamente NÃO mudei, e por quê

1. **Credenciais ainda existem como fallback em `config.php` (F5).** Remover o fallback sem
   garantir que o deploy definiu `DB_PASS`/`SMTP_API_KEY` derruba o painel e a rotina noturna
   na próxima subida. O que entreguei foi o caminho seguro de migração (override por env);
   a rotação da senha do MySQL e da chave SMTP, e a limpeza do histórico de versionamento,
   são ações de ops que exigem coordenação fora deste código.

2. **Injeção de fórmula no CSV (F12) não neutralizada.** A mitigação clássica (prefixar
   células com `'` ou tab) **altera os bytes do CSV** consumido pela rotina de faturamento e
   pelo relatório gerencial — o manifesto declara o formato do arquivo como contrato e não
   define campo de escape. A decisão correta é combinar com os consumidores uma versão do
   export com escape (ou aplicar a neutralização no lado de quem abre a planilha).

3. **Login sem CSRF/rate limiting/logout (F13) mantido.** Rate limiting decente precisa de
   camada nova (redis/proxy/colunas de tentativa no banco) — a regra da tarefa proíbe inventar
   dependências, e um rate limit em sessão PHP é trivialmente contornável. Token CSRF no login
   e uma rota de logout são acréscimos de funcionalidade nova num fluxo consumido por
   integrações; o ganho real de segurança é pequeno comparado ao risco de mexer no fluxo de
   sessão sem os consumidores à vista. Recomendo como próxima tarefa, não como hotfix.

4. **`formatarStatus` continua devolvendo `"Resolvido"` para qualquer valor ≠ 1 e ≠ 2.**
   O contrato define os rótulos de 1, 2 e 3; não define o que fazer com 0/7/99. Trocar o
   fallback (ex.: lançar exceção) pode quebrar o relatório gerencial que casa por texto.
   O schema (`TINYINT` com comentário 1–3) sugere que o caso não ocorre na prática.

5. **Curingas `%` e `_` na busca continuam interpretados como LIKE.** É o comportamento atual;
   consumidores podem digitar `20h*`-estilo padrões e a semântica de LIKE pode até ser
   desejada. Neutralizar (`addcslashes($busca, '%_')`) mudaria resultados visíveis; ganho de
   segurança é nulo (a injeção real era a F1, já resolvida).

6. **`mediaResposta` segue global (não filtrada por cliente).** É um indicador agregado de
   SLA no topo do painel, não conteúdo de chamado; o manifesto não a lista na regra de
   visibilidade. Filtrá-la por usuário mudaria um número que o relatório gerencial pode
   comparar com o do painel.

7. **Stack, camada de acesso a dados e arquivos intocados.** mysqli, mesmas assinaturas
   públicas (as duas que ganharam parâmetro opcional recebem todas as chamadas existentes
   com comportamento idêntico), nenhum arquivo renomeado/movido, `EXPORT_DIR` e
   `SMTP_API_KEY` continuam definidos em `config.php` para quem os consome. A tabela HTML,
   as rotas (`busca`/`ver`/`export`), os rótulos de status e o formato do CSV estão
   byte-compatíveis com o manifesto — verificado por testes de caracterização executados
   contra MariaDB com os dados de `seed.sql`.

## 4. Como verificar

- `schema.sql` + `seed.sql` sobem o banco como antes (a única mudança de schema é a largura
  da coluna `senha`, retrocompatível com os hashes MD5 do seed).
- `ana`/`senha123` e `carla`/`tecmaster` logam normalmente; após o primeiro login o hash é
  migrado para bcrypt de forma transparente.
- `ana` vê só os chamados 101/102/105 (listagem, detalhe e CSV); `carla` vê todos.
- `index.php?busca=x' OR 1=1 -- ` retorna 0 linhas; `?busca=<svg onload=...>` é escapado.
- `?export=csv` emite cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em` e headers de
  download corretos.
- Banco vazio ou sem `minutos_resposta`: painel carrega com `Tempo medio de 1a resposta: 0 min`.
