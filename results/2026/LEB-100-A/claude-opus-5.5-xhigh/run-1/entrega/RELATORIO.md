# Relatório técnico — Painel de Chamados (NetX ISP) · LEB-100-A

## Resumo

O painel funciona, mas **não aplica a regra de visibilidade em lugar nenhum**: `index.php` lê
`$uid` e `$papel` da sessão e nunca os usa. Resultado: qualquer cliente logado vê a listagem
completa, abre o detalhe de qualquer chamado e exporta todos em CSV. Além disso:

- a busca é concatenada no SQL (**injeção de SQL**) e ecoada sem escape no HTML (**XSS**);
- as senhas são **md5 sem salt**;
- a senha de produção do banco e a chave SMTP estão **escritas no código**;
- o cookie de sessão não tem `HttpOnly` e o id não muda no login;
- a página inicial **quebra por divisão por zero** quando nenhum chamado foi respondido;
- listagem e export fazem **uma consulta por linha** (N+1).

Corrigi os achados de segurança e os bugs **sem mudar nenhuma assinatura, rota, parâmetro,
formato de saída ou rótulo do manifesto**. A regra de visibilidade agora existe num único ponto
(`donoVisivel()` em `lib.php`) e a tela web usa três funções novas, `listarChamadosVisiveis`,
`verChamadoVisivel` e `exportarCsvVisivel`. As funções públicas do manifesto continuam
devolvendo tudo, porque a rotina noturna e o relatório gerencial dependem disso.

### Como verifiquei

Subi um MariaDB local com `schema.sql` + `seed.sql` e comparei o código original com o novo:

- **Caracterização da superfície pública.** Rodei as 7 funções do manifesto com vários logins
  (válidos, inválidos, com maiúsculas), os 3 status, 16 combinações de prioridade/minutos,
  6 buscas, 3 ids, a média e o export. A saída do código novo é **idêntica à do original,
  inclusive os tipos** (ids continuam string) e os bytes do CSV, tanto no schema legado
  (`CHAR(32)`) quanto no novo (`VARCHAR(255)`, com migração de hash ativa).
- **HTTP ponta a ponta** (`php -S`), original e novo lado a lado:
  - para o técnico, o HTML da listagem, da busca e do detalhe e o CSV saem byte a byte iguais
    ao original;
  - para a cliente `ana`, a listagem passa de 5 para 3 chamados (101, 102, 105), o detalhe
    de um chamado de outro cliente responde "Chamado nao encontrado." e o CSV traz só os
    chamados dela;
  - o termo de busca sai escapado no HTML;
  - a busca com metacaracteres de SQL não altera mais a consulta;
  - parâmetros enviados como array não derrubam a página;
  - o id de sessão muda no login e o cookie sai com `HttpOnly; SameSite=Lax`.
- **Casos de borda.** Com nenhum chamado respondido, o original lança `DivisionByZeroError` e o
  novo devolve `0.0`. Status fora de 1..3 deixa de virar "Resolvido". `%` e `_` na busca passam
  a ser texto literal.

---

## Achados (em ordem de prioridade de correção)

As linhas seguem a **numeração original** dos arquivos recebidos.

### F1 — Injeção de SQL na busca da listagem
- **Onde:** `code/lib.php:82` (`listarChamados`, 78–93); entrada em `code/index.php:72-73`.
- **Mecanismo:** o valor de `$_GET['busca']` é concatenado dentro do literal
  `LIKE '%…%'` sem escape nem parâmetro. Uma aspa no termo fecha o literal e o restante do
  termo passa a ser SQL. Como o resultado da consulta é exibido na própria tabela da
  listagem, o conteúdo de outras tabelas (inclusive `usuarios.senha`) pode aparecer na tela.
  Confirmei no ambiente de teste que o original aceita SQL arbitrário por esse parâmetro. Uma
  aspa isolada já produz erro de sintaxe, que no PHP ≥ 8.1 vira exceção não tratada.
- **Impacto / severidade:** **crítica**. Qualquer usuário logado, inclusive cliente, lê o banco
  inteiro. Somado a F6, isso leva a credenciais de técnico.
- **Confiança:** 99.
- **O que fiz:**
  - Troquei a concatenação por consulta preparada (`bind_param`), via o helper interno
    `consultarChamados()`.
  - Os curingas `%` e `_` do termo agora são escapados (`LIKE ? ESCAPE '!'`, que funciona
    também com `NO_BACKSLASH_ESCAPES`), para que a busca seja pelo texto literal.
  - O protocolo binário do prepared statement devolve colunas `INT` como `int`, e os
    consumidores de `listarChamados` sempre receberam string. Por isso converto os valores de
    volta para string. A caracterização confirma que o retorno é idêntico.

### F2 — Detalhe de chamado sem controle de acesso (IDOR em `?ver=`)
- **Onde:** `code/index.php:52-53`, chamando `verChamado()` (`code/lib.php:98-102`).
- **Mecanismo:** o detalhe carrega o chamado só pelo id recebido na URL. `$uid` e `$papel`
  (linhas 38–39) nunca são consultados. Os ids são sequenciais, então um cliente que troca o
  número na URL lê chamados de outros clientes, com descrição completa. Confirmei com a cliente
  `ana` abrindo o chamado 103, que é de `bruno`.
- **Impacto / severidade:** **crítica**. Viola diretamente a regra de negócio do manifesto e
  expõe dados de terceiros (ex.: problemas de cobrança no cartão).
- **Confiança:** 97.
- **O que fiz:**
  - Criei `verChamadoVisivel($db, $id, $uid, $papel)`. Quando o chamado não pertence ao
    cliente, ela devolve `null` e a tela mostra o mesmo "Chamado nao encontrado." de um id
    inexistente, sem revelar quais ids existem.
  - O técnico continua vendo qualquer chamado.
  - `verChamado()` não mudou.

### F3 — Listagem mostra a cliente os chamados de todos
- **Onde:** `code/index.php:72-73` e `code/lib.php:78-93`.
- **Mecanismo:** `listarChamados()` não filtra por dono e a tela a usa diretamente. No seed,
  `ana` via os chamados 101–105, inclusive os de `bruno`.
- **Impacto / severidade:** **alta**. Vazam títulos, status e técnico responsável de todos os
  chamados.
- **Confiança:** 97.
- **O que fiz:**
  - Criei `listarChamadosVisiveis()`. Para cliente, o filtro `c.usuario_id = ?` vai no SQL e
    usa o índice `idx_usuario`, em vez de trazer tudo e filtrar no PHP.
  - Para técnico, o HTML ficou byte a byte igual ao original.

### F4 — Exportação CSV entrega todos os chamados a qualquer sessão
- **Onde:** `code/index.php:44-45` → `code/lib.php:123-151`.
- **Mecanismo:** a rota `?export=csv` só exige sessão e chama `exportarCsv()`, que exporta a
  tabela inteira.
- **Impacto / severidade:** **alta**. É a mesma exposição de F3, já num arquivo pronto para
  levar.
- **Confiança:** 95.
- **O que fiz:**
  - Criei `exportarCsvVisivel()`. O cliente recebe **os próprios chamados**, com o mesmo
    cabeçalho, formato e ordenação por id. O técnico recebe o CSV completo, byte a byte igual
    ao original.
  - Filtrei em vez de negar a rota ao cliente: negar esconderia dele chamados que ele tem
    direito de ver, e o manifesto proíbe isso.
  - `exportarCsv()` continua exportando tudo, porque a rotina noturna depende disso.

### F5 — XSS refletido no termo de busca
- **Onde:** `code/index.php:79` (atributo `value` do campo) e `code/index.php:82` (parágrafo
  "Resultados para").
- **Mecanismo:** `$busca` é impresso sem `htmlspecialchars` nos dois pontos. Um link para a
  listagem com um termo de busca preparado injeta marcação e script na página de quem clicar,
  na origem do painel. Como o cookie de sessão não tinha `HttpOnly` (F9), o script consegue
  ler o id de sessão da vítima. Confirmei que o original reflete o termo sem escape.
- **Impacto / severidade:** **alta**. Com um clique de um técnico, o atacante sequestra a
  sessão dele e passa a ver todos os chamados.
- **Confiança:** 98.
- **O que fiz:**
  - Apliquei `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.
  - Os demais ecos da página já escapavam (título, descrição, técnico) ou imprimem valores
    fixos ou inteiros vindos do banco.

### F6 — Senhas em md5 sem salt
- **Onde:** `code/lib.php:15-16` (`autenticar`) e `code/schema.sql:7` (`senha CHAR(32)`).
- **Mecanismo:** a senha é guardada como `md5()` puro. Sem salt, senhas iguais geram hashes
  iguais (no seed, `ana` e `bruno` têm o mesmo hash). md5 é rápido demais para esse uso, e
  senhas comuns aparecem em tabelas pré-computadas. Qualquer vazamento da tabela, como pelo F1,
  vira vazamento de senhas.
- **Impacto / severidade:** **alta**. As senhas dos clientes podem ser reaproveitadas em outros
  serviços, e as dos técnicos dão acesso total ao painel.
- **Confiança:** 95.
- **O que fiz:** uma migração transparente, sem mudar a assinatura.
  - `autenticar()` busca o usuário pelo login e aceita os dois formatos de hash:
    - md5 legado, comparado com `hash_equals`, sem diferenciar maiúsculas, como a comparação
      em SQL fazia;
    - hashes de `password_hash`, verificados com `password_verify`.
  - No primeiro login válido com md5, a senha é regravada com `password_hash` (nova função
    `atualizarHashSenha`).
  - A regravação **só acontece se a coluna já comporta o hash novo**, o que é conferido em
    `information_schema`. Na coluna `CHAR(32)`, o valor seria truncado ou o `UPDATE`
    falharia, e o usuário ficaria trancado para fora.
  - Uma falha na regravação só gera log e nunca impede o login.
  - Na prática, o `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` (documentado no
    topo de `schema.sql`) é a chave de ativação. Sem ele, nada é gravado.
  - `schema.sql` já cria a coluna com 255, e o seed continua válido.
  - **Verificado:** no schema legado, nenhuma escrita. No novo, `ana` e `carla` migram para
    `$2y$…`, o login seguinte funciona e o retorno é idêntico (`['id' => int, 'nome', 'papel']`).

### F7 — Senha de produção do banco e chave SMTP no código-fonte
- **Onde:** `code/config.php:12` (`DB_PASS` com valor de *fallback*) e `code/config.php:15`
  (`SMTP_API_KEY` literal).
- **Mecanismo:** os segredos estão em texto puro num arquivo versionado. Quem tem acesso ao
  repositório, a um backup ou a uma leitura indevida de arquivo no servidor obtém acesso direto
  ao banco de produção e ao envio de e-mail transacional. Por causa do *fallback*, o valor
  embutido é usado sempre que a variável de ambiente falta, então ninguém percebe que a
  configuração por ambiente não está em uso.
- **Impacto / severidade:** **alta**. A credencial de produção está comprometida, e a chave
  SMTP permite enviar e-mails em nome do ISP.
- **Confiança:** 90.
- **O que fiz:**
  - Os dois valores agora vêm **só do ambiente** (`getenv`), e as constantes continuam
    definidas com os mesmos nomes.
  - Isso não resolve sozinho: os valores já vazaram (histórico, backups) e **precisam ser
    rotacionados** pela operação.
  - Pré-requisito de implantação: configurar `env[DB_PASS]` e `env[SMTP_API_KEY]` no pool do
    PHP-FPM (ou equivalente). Sem eles, o painel responde "Falha ao conectar ao banco." com
    503, ou seja, falha fechado.

### F8 — Export grava num arquivo fixo em `EXPORT_DIR` (exposição, corrida, falha silenciosa)
- **Onde:** `code/lib.php:125-150`.
- **Mecanismo:**
  - **(a) Exposição:** todo export grava `EXPORT_DIR/chamados.csv`
    (`/var/www/painel/tmp`, dentro da árvore do painel) e deixa o arquivo lá. Se esse diretório
    for servido pelo servidor web, o export completo fica acessível sem login.
  - **(b) Corrida:** requisições simultâneas escrevem no mesmo caminho entre `fopen` e
    `readfile`. Com o export filtrado por usuário (F4), isso faria um cliente receber o arquivo
    gerado para outro.
  - **(c) Falha silenciosa:** se `fopen` ou a consulta falham, a função só faz `return`. A
    resposta sai 200, vazia ou com o *warning* do PHP no corpo, e no caso da consulta o
    arquivo fica aberto. Reproduzi isso: sem o diretório, o original devolve o HTML do
    *warning*.
  - **(d) PHP 8.4:** `fputcsv` sem o parâmetro `$escape` emite `E_DEPRECATED`. Com
    `display_errors` ligado, esse aviso sai no meio do CSV.
- **Impacto / severidade:** **média**. A exposição depende da configuração do servidor, que eu
  não tenho.
- **Confiança:** 75.
- **O que fiz:**
  - O CSV agora é escrito direto em `php://output` (novo helper `escreverCsvChamados`).
  - A consulta roda antes de qualquer header, então uma falha não gera um "CSV" vazio.
  - `fputcsv` recebe separador, aspas e escape explícitos, com os mesmos valores padrão de
    antes, o que mantém a saída idêntica e elimina o aviso.
  - A constante `EXPORT_DIR` continua definida em `config.php`.

### F9 — Sessão: fixação e cookie sem `HttpOnly`/`SameSite`
- **Onde:** `code/index.php:15` (`session_start` com os padrões) e `code/index.php:24` (login
  sem trocar o id de sessão).
- **Mecanismo:**
  - O id de sessão anterior ao login continua valendo depois dele. Com
    `session.use_strict_mode` desligado, o PHP ainda aceita um id escolhido pelo cliente.
    Quem consegue plantar um id no navegador da vítima herda a sessão autenticada.
  - O cookie sem `HttpOnly` fica legível por script, o que agrava o F5.
- **Impacto / severidade:** **média**.
- **Confiança:** 80.
- **O que fiz:**
  - Liguei `session.use_strict_mode`, `session.cookie_httponly` e `SameSite=Lax`, e o cookie
    passa a ser `secure` quando a requisição chega por HTTPS.
  - O login agora chama `session_regenerate_id(true)`.
  - `Lax` mantém funcionando os links e favoritos externos, que são navegações GET de nível
    superior.

### F10 — `mediaResposta()` divide por zero e derruba a página inicial
- **Onde:** `code/lib.php:116`, chamada em `code/index.php:74`.
- **Mecanismo:** quando nenhum chamado tem `minutos_resposta` (banco novo, ou todos aguardando
  a primeira resposta), `$qtd` é 0. No PHP 8, `$soma / $qtd` lança `DivisionByZeroError`, e a
  listagem, que calcula a média antes de montar o HTML, não abre para ninguém. O relatório
  gerencial, que chama a mesma função, também quebra. Reproduzi no original.
- **Impacto / severidade:** **média**. É indisponibilidade da tela principal num estado de dados
  plausível.
- **Confiança:** 90.
- **O que fiz:** sem dados, a função devolve `0.0`, respeitando o retorno `float` do contrato.
  Com dados, o valor é exatamente o mesmo de antes (ex.: `25.666666666666668` no seed).

### F11 — N+1 consultas para o nome do técnico
- **Onde:** `code/lib.php:89` (listagem) e `code/lib.php:137` (export), via `tecnicoNome()`
  (`code/lib.php:64-72`).
- **Mecanismo:** para cada chamado retornado, o código faz mais um `SELECT` em `usuarios`. Uma
  listagem ou export de N chamados gera 1 + N idas ao banco. O export noturno da base inteira
  multiplica a latência de rede pelo número de chamados.
- **Impacto / severidade:** **média** (desempenho, cresce com a base).
- **Confiança:** 90.
- **O que fiz:**
  - Substituí as consultas por linha por um único `LEFT JOIN usuarios` em
    `consultarChamados()`. Técnico ausente continua saindo como `-`, e a chave `tecnico_nome`
    fica na mesma posição do array.
  - `tecnicoNome()` foi mantida, porque pode ser usada por outros scripts.

### F12 — `mediaResposta()` traz todas as linhas para somar no PHP
- **Onde:** `code/lib.php:109-115`.
- **Mecanismo:** para calcular uma média, a função transfere uma linha por chamado respondido e
  soma em laço no PHP. O custo cresce com a tabela, e a função roda **a cada carregamento** da
  listagem.
- **Impacto / severidade:** **baixa** (desempenho).
- **Confiança:** 85.
- **O que fiz:** troquei o laço por `SUM`/`COUNT` no banco, com a divisão no PHP. Usei essa
  forma em vez de `AVG()` porque `AVG` devolve `DECIMAL` com 4 casas e mudaria o valor retornado
  aos consumidores. Assim o `float` fica idêntico ao anterior.

### F13 — Injeção de fórmula no CSV
- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** o título do chamado é escrito cru na célula. Os títulos são redigidos pelos
  clientes. Um título que comece com `=`, `+`, `-` ou `@` é interpretado como fórmula quando o
  técnico abre o CSV numa planilha, e pode, por exemplo, gerar links ou referências a outras
  células.
- **Impacto / severidade:** **média**. Depende de o arquivo ser aberto em planilha, o que é
  plausível para quem exporta.
- **Confiança:** 60.
- **O que fiz:** **não corrigi.** A mitigação usual (prefixar `'` nessas células) altera o
  conteúdo da coluna *Titulo* consumida pela rotina noturna e pela integração de faturamento.
  Isso está fora do que posso mudar sem combinar com os consumidores. A recomendação está em
  **Decisões**.

### F14 — `formatarStatus()` rotula qualquer valor desconhecido como "Resolvido"
- **Onde:** `code/lib.php:32-34`.
- **Mecanismo:** o `else` final cobre tudo que não é 1 ou 2. Um status 0, um valor corrompido
  ou um status novo aparece como "Resolvido" na tela, no CSV e no relatório gerencial, e um
  chamado não resolvido pode ser ignorado.
- **Impacto / severidade:** **baixa**. O schema só define 1..3, mas não há `CHECK` que impeça
  outros valores.
- **Confiança:** 60.
- **O que fiz:** 1, 2 e 3 continuam exatamente como no contrato ("Aberto", "Em atendimento",
  "Resolvido"). Qualquer outro valor passa a devolver "Desconhecido".

### F15 — Parâmetros enviados como array derrubam as páginas
- **Onde:** `code/index.php:22` (`login`/`senha`) e `code/index.php:72-73` (`busca`).
- **Mecanismo:** PHP transforma `nome[]=…` em array. As funções tipadas `string` lançam
  `TypeError`, e a página cai com erro fatal. Com `display_errors` ligado, o erro mostra
  caminhos do servidor. Reproduzi na busca do original.
- **Impacto / severidade:** **baixa** (erro 500 e vazamento de caminho).
- **Confiança:** 90.
- **O que fiz:** valido com `is_string`. Busca inválida vira busca vazia, e login inválido é
  tratado como tentativa sem sucesso.

### F16 — Tratamento de falha de conexão morto no PHP ≥ 8.1
- **Onde:** `code/index.php:9-12`.
- **Mecanismo:** desde o PHP 8.1, o mysqli lança `mysqli_sql_exception` no construtor. O teste
  de `connect_errno` nunca chega a executar, a mensagem amigável não aparece, e o que sai é
  uma exceção não tratada, com detalhes da conexão se `display_errors` estiver ligado.
- **Impacto / severidade:** **baixa**.
- **Confiança:** 80.
- **O que fiz:** envolvi a conexão em `try/catch`, mantendo o teste de `connect_errno` para
  versões antigas. O erro vai para o log e a resposta é a mesma mensagem, agora com HTTP 503.

### F17 — Login sem limite de tentativas
- **Onde:** `code/index.php:20-29`.
- **Mecanismo:** não há contagem, atraso nem bloqueio após falhas. Senhas fracas, como as do
  seed, podem ser testadas indefinidamente.
- **Impacto / severidade:** **média**.
- **Confiança:** 70.
- **O que fiz:** **não corrigi.** Um controle confiável precisa de estado compartilhado (tabela
  nova ou infraestrutura como fail2ban ou WAF). Ver **Decisões**.

### F18 — Papel fixado na sessão e ausência de logout
- **Onde:** `code/index.php:24-25` e `code/index.php:38-39`.
- **Mecanismo:** o papel é lido do banco só no login. Um técnico rebaixado a cliente, ou um
  usuário removido, mantém o acesso anterior enquanto a sessão existir, e não há rota para
  encerrá-la.
- **Impacto / severidade:** **baixa**.
- **Confiança:** 55.
- **O que fiz:** **não corrigi.** Ver **Decisões**.

### F19 — Listagem sem paginação e busca que não usa índice
- **Onde:** `code/lib.php:80-85`, renderizada em `code/index.php:86-97`.
- **Mecanismo:** cada acesso carrega todos os chamados, inclusive a coluna `descricao` (TEXT), e
  renderiza tudo. `LIKE '%termo%'` não usa índice e faz varredura completa. O custo cresce
  linearmente com a base.
- **Impacto / severidade:** **baixa** hoje, crescente com o volume.
- **Confiança:** 60.
- **O que fiz:** **não corrigi.** Ver **Decisões**. O JOIN do F11 e o filtro de dono no SQL do F3
  já reduzem parte do custo.

### F20 — Regras de `rotuloPrioridade()` questionáveis e aninhamento profundo
- **Onde:** `code/lib.php:40-59`.
- **Mecanismo:** prioridade 4 (crítica) dentro do SLA aparece como "Alto - dentro do SLA".
  Prioridade 1 (baixa) aparece como "Normal". Um chamado crítico ainda sem primeira resposta
  aparece como "Aguardando 1a resposta", sem destaque, justamente o caso mais urgente (ex.:
  chamado 104 do seed). A leitura das regras é difícil por causa de quatro níveis de `if`.
- **Impacto / severidade:** **baixa**. Pode ser intencional.
- **Confiança:** 30.
- **O que fiz:** **não alterei.** Os rótulos são regra de negócio e não tenho evidência de que
  estejam errados. Registrei a dúvida para a área de produto.

### F21 — Arquitetura: autorização ausente de qualquer camada; `index.php` monolítico
- **Onde:** `code/index.php` (arquivo inteiro) e as funções de consulta de `code/lib.php`.
- **Mecanismo:** as funções de dados não sabem quem as chama, e cada chamador precisa lembrar
  de aplicar a regra de visibilidade. Nenhum chamador lembrava, e daí vêm F2, F3 e F4. O
  `index.php` mistura conexão, sessão, roteamento, regra e HTML via `echo`, com escape manual
  caso a caso, e daí vem F5.
- **Impacto / severidade:** **baixa** isoladamente, mas é a causa raiz dos achados mais graves.
- **Confiança:** 70.
- **O que fiz:** tratei parcialmente. A regra de visibilidade agora está em um só lugar
  (`donoVisivel`, com falha fechada: qualquer papel diferente de `tecnico` é tratado como
  cliente) e é consumida pelas funções `*Visivel`. Não reestruturei `index.php`: seria
  reescrita, e a tarefa pede evolução.

---

## Decisões — o que deliberadamente **não** mudei, e por quê

1. **As funções públicas `listarChamados`, `verChamado` e `exportarCsv` continuam sem filtro de
   visibilidade.** Elas não recebem usuário na assinatura, e a rotina noturna e o relatório
   gerencial precisam de todos os chamados. Adicionar parâmetros mudaria assinaturas
   contratuais. A regra foi para as novas funções `*Visivel`, usadas pela tela.
   **Risco residual:** outro script voltado a usuários finais que chame as funções antigas
   continua sem filtro e deve migrar para as `*Visivel`.
2. **Cabeçalho do CSV byte a byte como em produção.** `fputcsv` emite `"Aberto em"` entre aspas
   por causa do espaço, enquanto o manifesto escreve o cabeçalho sem aspas. Para qualquer
   leitor de CSV o valor é o mesmo, e os consumidores atuais já recebem a versão com aspas.
   Mantive a saída idêntica à de produção em vez de "consertar" os bytes.
3. **O export não grava mais o arquivo em `EXPORT_DIR`.** O manifesto define que `exportarCsv`
   "escreve o CSV na saída", e isso foi preservado (inclusive na CLI, pela saída padrão). A
   constante `EXPORT_DIR` continua definida. Se a rotina noturna lia o arquivo do disco em vez
   da saída, basta redirecionar a saída do script para o caminho desejado. Vale confirmar com
   o time antes da implantação.
4. **Injeção de fórmula no CSV (F13) não foi mitigada no código.** Prefixar células mudaria os
   dados que o faturamento consome. Recomendação: combinar com os consumidores e aplicar o
   prefixo, ou importar o arquivo como texto na planilha.
5. **Rótulos de `rotuloPrioridade` (F20) intocados.** São regra de negócio visível ao usuário e
   não tenho evidência de que estejam errados. Também não refatorei o aninhamento: reescrever a
   função traz risco de mudar algum rótulo sem ganho funcional.
6. **Sem limite de tentativas de login (F17), sem logout e sem revalidar o papel (F18).**
   Exigiriam estado novo (tabela ou infraestrutura) ou rotas novas. Ficam como recomendação:
   limitar tentativas por login e IP, criar uma rota de saída e reler o papel a cada
   requisição.
7. **Sem paginação e sem índice de texto (F19).** Paginar muda a estrutura HTML declarada (a
   tabela lista todos os chamados). `FULLTEXT` muda a semântica da busca por substring.
8. **`verChamado` e `tecnicoNome` continuam concatenando o id.** Os parâmetros são tipados como
   `int`/`?int`, o que torna a injeção impossível. `tecnicoNome` foi mantida porque outros
   scripts podem usá-la, mesmo que a listagem e o export não dependam mais dela.
9. **`mediaResposta` continua global, inclusive para clientes.** É um indicador agregado, sem
   dados de chamados individuais, e o relatório gerencial depende do mesmo valor. Sem dados, a
   função devolve `0.0` em vez de falhar, porque o contrato exige `float`.
10. **Resposta 200 para "Chamado nao encontrado".** Mantive o código de status existente para
    não alterar o comportamento da rota. Chamado inexistente e chamado alheio recebem a mesma
    resposta.
11. **Sem token CSRF e sem cabeçalhos como CSP ou X-Frame-Options.** A única ação com efeito
    colateral é o login. Bloquear enquadramento poderia quebrar uma eventual incorporação do
    painel no portal interno, algo que não consigo verificar daqui.
12. **Diferença de tempo de resposta no login.** Usuário inexistente, hash md5 e hash bcrypt
    levam tempos diferentes, o que permite inferir quais logins existem. Aceito como risco
    baixo durante a migração.
13. **Mudança de comportamento deliberada na busca:** `%` e `_` passam a ser texto literal
    (F1). Antes funcionavam como curingas por acidente da concatenação. A intenção declarada,
    "filtrada por título", é busca por substring.

## Notas de implantação

1. Configurar `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP **antes** de publicar o código, e
   **rotacionar** as duas credenciais que estavam no código.
2. Publicar o código. Sem o `ALTER`, a autenticação continua em md5, sem nenhuma escrita.
3. Conferir que nenhum outro script valida senha direto em SQL com md5. Se houver, ele deve
   passar a usar `autenticar()`.
4. Aplicar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. A partir daí, cada
   login válido migra o hash daquele usuário.
5. Se a rotina noturna lia `EXPORT_DIR/chamados.csv` do disco, ajustá-la para ler a saída do
   export (Decisão 3) e apagar o arquivo antigo que ficou no diretório.
