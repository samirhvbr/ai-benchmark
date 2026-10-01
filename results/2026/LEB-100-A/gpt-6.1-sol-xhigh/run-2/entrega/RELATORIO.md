# Relatório técnico — LEB-100-A

Instância: **LEB-100-A**, versão **1.1**. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel tinha falhas comprovadas de injeção SQL, autorização, escape de HTML, cálculo e custo de consultas, além de fragilidades de credenciais, sessão e exportação. Foram identificados **13 achados**, com **12 corrigidos no código entregue** e **1 reportado sem alteração de conteúdo por compatibilidade (F8)**. Os cinco arquivos de `code/` foram alterados no lugar; `TAREFA.md` e `manifest.md` permanecem intactos. A stack continua PHP/MySQL com mysqli.

As referências abaixo usam exclusivamente a **numeração original dos arquivos recebidos**, inclusive para código que foi substituído. A severidade avalia o impacto no painel e em seus consumidores; a confiança mede se o mecanismo descrito é real, não a probabilidade de um incidente já ter ocorrido.

## Achados, em ordem de prioridade

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** `seguranca`. **Severidade:** `critica`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** listarChamados concatena busca dentro de LIKE sem parametrização. O termo "' OR 1=1 -- " (com espaço depois de --) encerra a string, acrescenta uma condição verdadeira e comenta o restante da consulta. No banco original, essa entrada retornou os cinco chamados, mesmo sem ser um título existente. A consulta usa query, não multi_query: o mecanismo demonstrado não depende de executar instruções SQL empilhadas.

**Impacto:** O usuário autenticado pode modificar o predicado da consulta, consultar dados fora da busca e explorar outras expressões ou UNION conforme as permissões do banco. Não foi demonstrada execução arbitrária de múltiplas instruções.

**Ação/decisão:** A busca passou a usar mysqli::prepare, LIKE ? e bind_param. O filtro de visibilidade é combinado com AND. Foram preservados a busca parcial e os curingas % e _ já aceitos pelo LIKE.

### F2 — Ausência de autorização por proprietário nas consultas

**Local original:** `code/lib.php:98–101`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** verChamado consulta somente id. listarChamados (linhas 78–92), mediaResposta (107–116) e exportarCsv (123–144) também consultam todos os proprietários. index.php lê uid e papel nas linhas 38–39, mas não os aplica. No original, Bruno, dono apenas de 103 e 104, recebeu a listagem inteira, conseguiu ver 101 e recebeu a média 77/3 das respostas dos chamados de Ana.

**Impacto:** Qualquer cliente autenticado pode ler títulos, descrições e dados de outros clientes e baixar seus chamados. O indicador de SLA também incorpora dados aos quais esse cliente não tem direito.

**Ação/decisão:** Um filtro comum restringe listagem, detalhe, CSV e média a c.usuario_id para sessões de cliente. Técnicos continuam vendo todos os chamados; sessões com papel ou identificador inválido recebem conjunto vazio. O detalhe não autorizado retorna null e a mensagem já existente de chamado não encontrado. Consumidores internos confiáveis sem sessão autenticada conservam o acesso global previsto para relatórios.

### F3 — XSS refletido em duas saídas da busca

**Local original:** `code/index.php:79–82`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** busca é interpolada sem escape tanto no atributo value delimitado por aspas quanto no parágrafo de resultados. Uma entrada com aspas pode sair do atributo, e uma entrada com marcação pode introduzir script ou elementos HTML. Escapar os títulos dos chamados não protege essas duas interpolações.

**Impacto:** Um link de busca preparado por um adversário pode executar JavaScript na origem do painel quando aberto por uma pessoa autenticada, inclusive um técnico.

**Ação/decisão:** A busca é escapada com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 nas duas saídas. Títulos, descrição e nomes também recebem opções explícitas; IDs numéricos são renderizados como inteiros. A estrutura e a ordem das colunas da tabela foram mantidas.

### F4 — Credenciais de banco e SMTP incorporadas ao código

**Local original:** `code/config.php:11–15`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **95/100**. **Corrigido:** sim.

**Mecanismo:** DB_PASS contém um fallback identificado pelo comentário como senha de produção, e SMTP_API_KEY é um literal. A leitura do pacote ou do histórico revela ambos. O operador ?: também substitui valores explícitos vazios ou iguais a 0 pelo segredo embutido. A exposição no código é comprovada; a validade atual dessas credenciais nos serviços externos não foi verificada.

**Impacto:** Quem obtiver o código poderá tentar autenticar no banco ou no serviço de e-mail com os valores publicados. Mesmo uma variável DB_PASS explicitamente configurada como 0 era ignorada.

**Ação/decisão:** Os literais foram removidos. DB_PASS vem obrigatoriamente do ambiente, com distinção entre ausência, string vazia e 0; SMTP_API_KEY vem do ambiente e fica vazia se ausente. A entrada web responde 503 de forma controlada quando falta DB_PASS. A rotação dos valores anteriormente expostos e a revisão do histórico ainda precisam ser feitas pelo operador dos serviços.

### F5 — Senhas protegidas somente por MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** autenticar calcula md5 da senha e a compara no banco. schema.sql:7 limita senha a CHAR(32), e seed.sql:5–8 grava MD5. O hash é rápido, determinístico e sem salt: a obtenção dessa coluna permite testar dicionários fora do sistema, sem passar pelo login.

**Impacto:** Uma exposição do banco facilita a descoberta das senhas, especialmente de contas com senhas comuns, e a tomada das respectivas contas.

**Ação/decisão:** A autenticação aceita os MD5 existentes e os atualiza após login válido usando password_hash/PASSWORD_DEFAULT; hashes atuais são verificados com password_verify e atualizados com password_needs_rehash. O UPDATE compara também o hash anterior, evitando sobrescrever uma alteração concorrente. A coluna passou a VARCHAR(255) e o seed usa hashes atuais mantendo os quatro logins e senhas do manifesto. Senhas legadas maiores que 72 bytes ou contendo NUL usam SHA-256 seguido de password_hash, identificado pelo prefixo sha256:, para não sofrer truncamento pelo bcrypt. É obrigatória a migração da coluna antes de implantar o código; contas MD5 que ainda não fizerem login continuam em transição.

### F6 — Sessão não é renovada após autenticação

**Local original:** `code/index.php:15–26`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **98/100**. **Corrigido:** sim.

**Mecanismo:** session_start retoma uma sessão e o login apenas acrescenta uid e papel, mantendo o identificador anterior. Se um adversário conhecer ou conseguir fixar uma sessão pré-login, esse mesmo identificador passa a representar a vítima após o login. O arquivo também não define explicitamente HttpOnly ou SameSite; os valores efetivos dependiam do php.ini.

**Impacto:** Uma sessão previamente conhecida pelo adversário pode tornar-se uma sessão autenticada de cliente ou técnico sem troca do identificador.

**Ação/decisão:** O login renova e invalida o ID anterior com session_regenerate_id(true) antes de armazenar a identidade. Foram definidos modo estrito, HttpOnly, SameSite=Lax e Secure quando HTTPS é informado pelo servidor, preservando Secure previamente configurado. Testes HTTP confirmaram troca do ID e ausência de autenticação usando o ID antigo.

### F7 — Exportações compartilham um arquivo fixo e recursos sem limpeza

**Local original:** `code/lib.php:125–150`. **Categoria:** `seguranca`. **Severidade:** `alta`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** Toda requisição abre EXPORT_DIR/chamados.csv com modo w e depois faz readfile nesse mesmo nome, sem exclusão mútua. Uma segunda requisição pode truncar, substituir ou intercalar o arquivo entre a escrita e a leitura da primeira. O CSV permanece no disco; se o diretório tmp estiver servido pela aplicação, ele também pode ficar acessível sem o fluxo de login. Se a consulta falhar com mysqli em modo não estrito, o return da linha 134 deixa o descritor aberto. A existência de acesso HTTP direto ao diretório não foi verificada.

**Impacto:** Downloads podem conter dados misturados, incompletos ou pertencentes a outra exportação. Com filtros por cliente, uma corrida nesse arquivo poderia violar o isolamento. A exportação também depende de um diretório fixo existir e permitir escrita, caso contrário retorna silenciosamente.

**Ação/decisão:** O CSV é preparado em php://temp, um stream exclusivo de cada chamada, e transmitido após a consulta e a escrita bem-sucedidas. finally fecha o stream e libera o resultado. O conteúdo deixa de depender de EXPORT_DIR e de persistir em um caminho compartilhado. Foram verificados 60 downloads simultâneos com quatro usuários e conjuntos de dados distintos.

### F8 — Campos CSV podem ser interpretados como fórmulas em planilhas

**Local original:** `code/lib.php:138–143`. **Categoria:** `seguranca`. **Severidade:** `media`. **Confiança:** **90/100**. **Corrigido:** não.

**Mecanismo:** O título e o nome do técnico são enviados ao CSV com seu conteúdo original. Um valor como =1+1 permanece uma fórmula potencial ao abrir o arquivo em uma planilha que reconheça esse conteúdo. As aspas e o escape CSV resolvem separadores e aspas internas, mas não impõem o tipo textual da célula. O pacote não contém uma rota de criação desses dados; a exploração depende de uma fonte externa gravar esse conteúdo e de um consumidor abri-lo em uma planilha com interpretação de fórmulas.

**Impacto:** O consumidor pode calcular uma expressão inserida no conteúdo exportado; fórmulas com recursos externos podem ter impacto adicional conforme o aplicativo e suas permissões. Não foi demonstrada execução de comandos no cliente.

**Ação/decisão:** Reportado, sem alteração de conteúdo dos campos. Prefixar apóstrofo ou tabulação mudaria os títulos e nomes entregues aos consumidores CSV, o que não foi autorizado pelo contrato. A mitigação proposta é configurar a importação das colunas textuais como texto, ou negociar uma saída específica para planilhas. O teste confirmou a preservação exata de um título iniciado por = com vírgula, aspas e UTF-8.

### F9 — Média divide por zero quando não há primeira resposta

**Local original:** `code/lib.php:109–116`. **Categoria:** `bug`. **Severidade:** `media`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** A consulta ignora minutos_resposta NULL. Em uma base vazia ou com todos os chamados sem resposta, o laço não incrementa qtd e a divisão soma/qtd ocorre com denominador zero. No PHP 8.4 utilizado, o original lançou DivisionByZeroError. Após aplicar corretamente a visibilidade, Bruno já apresenta esse caso com os dados fornecidos.

**Impacto:** A página de listagem deixa de responder quando nenhum chamado do conjunto visível recebeu primeira resposta.

**Ação/decisão:** A contagem de respostas é verificada antes da divisão; sem respostas, a função retorna 0.0, mantendo seu tipo float. Foram testados banco vazio, todos os valores NULL e o conjunto de Bruno. Para conjuntos não vazios, a divisão conserva a precisão da implementação original.

### F10 — Exceções de conexão mysqli escapam do tratamento existente

**Local original:** `code/index.php:9–13`. **Categoria:** `bug`. **Severidade:** `media`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** A entrada cria mysqli e só depois consulta connect_errno. No modo estrito usado por padrão pelo PHP 8.4 disponível, uma conexão malsucedida lança mysqli_sql_exception durante o construtor, antes de alcançar esse teste. As consultas também não têm um limite de tratamento de exceções na entrada web. A execução do original com socket indisponível confirmou a exceção não tratada.

**Impacto:** Falhas operacionais de banco produzem uma requisição abortada em vez da resposta genérica planejada; a exibição de detalhes ao navegador depende da configuração display_errors.

**Ação/decisão:** A entrada web define explicitamente o modo estrito de mysqli e trata RuntimeException, incluindo mysqli_sql_exception, em um limite comum. Configuração ausente e banco indisponível geram HTTP 503 com mensagem genérica, enquanto os detalhes seguem para o log do servidor. As consultas da listagem são concluídas antes da emissão do HTML.

### F11 — Uma consulta adicional por técnico na listagem e no CSV

**Local original:** `code/lib.php:64–90`. **Categoria:** `performance`. **Severidade:** `media`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** tecnicoNome executa SELECT por id em cada chamada. O laço da listagem na linha 89 e o da exportação na linha 137 o chamam para cada chamado com técnico. Para N chamados atribuídos, cada operação faz 1+N consultas, inclusive quando vários chamados têm o mesmo técnico.

**Impacto:** A quantidade de viagens ao banco e a latência crescem com a listagem e com o CSV, aumentando a carga sem necessidade.

**Ação/decisão:** Listagem e exportação usam LEFT JOIN usuarios e COALESCE para obter tecnico_nome na consulta principal. O LEFT JOIN mantém chamados não atribuídos e o marcador -. A função tecnicoNome continua disponível com a assinatura e o comportamento anteriores. Os contadores do banco confirmaram uma única SELECT para cada listagem e exportação.

### F12 — Indicador de resposta transfere todas as linhas para o PHP

**Local original:** `code/lib.php:109–115`. **Categoria:** `performance`. **Severidade:** `baixa`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** mediaResposta seleciona cada minutos_resposta não nulo e soma em PHP. A API mysqli usada fornece um resultado bufferizado, de modo que o indicador transfere e mantém o conjunto de respostas para produzir um único número.

**Impacto:** Cada acesso à listagem consome tráfego, memória do processo e tempo de iteração proporcionais ao número de chamados respondidos. O banco ainda precisa examinar o conjunto; a melhoria não elimina esse custo de leitura.

**Ação/decisão:** A consulta retorna somente SUM e COUNT do conjunto visível. A divisão fica no PHP para preservar o valor 77/3 sem o arredondamento decimal que AVG pode introduzir. O resultado transferido passa a ter uma linha, mantendo a exclusão de NULL e contando respostas de zero minuto.

### F13 — Parâmetros HTTP em formato de array causam erros de tipo

**Local original:** `code/index.php:22`. **Categoria:** `bug`. **Severidade:** `baixa`. **Confiança:** **100/100**. **Corrigido:** sim.

**Mecanismo:** O PHP aceita parâmetros como login[]=ana, senha[]=x e busca[]=x como arrays. A linha 22 passa POST diretamente a autenticar, que exige string; as linhas 72–73 fazem o mesmo com busca e listarChamados. Isso causa TypeError em vez de tratar uma requisição malformada. O cast de ver na linha 53 também podia converter um array ou texto inválido em um ID sem validação.

**Impacto:** Requisições controladas pelo cliente podem provocar falha da requisição e, se display_errors estiver ativo, exposição de detalhes de execução. Não foi demonstrada indisponibilidade de todo o serviço.

**Ação/decisão:** login e senha, além de busca, ver e export, são verificados como strings na entrada web. ver exige dígitos e valor positivo. Entradas malformadas recebem HTTP 400. IDs válidos com zeros iniciais continuam aceitos; as assinaturas e os nomes dos parâmetros não foram alterados.

## Verificação executada

Os testes usaram PHP **8.4.26**, mysqli/mysqlnd e **MariaDB 11.8.6** em um banco temporário próprio, acessível apenas por socket local. O serviço web de teste usou quatro workers PHP. Não houve conexão ou modificação de serviços de produção. MySQL 8, citado no schema, não estava disponível para um teste independente; os comandos usados são compatíveis com essa camada, mas esta entrega não afirma validação executada nesse servidor.

- Os três arquivos PHP passaram por `php -l`.
- **121 verificações de funções** passaram, com avisos PHP convertidos em exceções: assinaturas comparadas por reflexão com o original; campos e tipos de listagem/detalhe comparados estritamente; retorno exato de autenticação; quatro credenciais; senha incorreta; status; 30 combinações de prioridade/SLA; busca parcial e curingas; autorização dos quatro perfis; papel desconhecido; banco vazio; somente NULL; CSV e dados especiais; migração MD5; senhas longas e com NUL; contagem de consultas.
- **153 verificações HTTP** passaram: login/redirecionamento, renovação de sessão, sessão antiga sem autenticação, cookies HttpOnly/SameSite, rotas, HTML declarado, isolamento de detalhe/listagem/CSV, média de Bruno igual a zero, payload de XSS escapado, injeção SQL sem resultados e parâmetros de array rejeitados com 400. Desse total, **60** foram downloads concorrentes, verificados por usuário; os 60 passaram.
- O CSV foi lido de volta, preservando título com `=`, vírgula, aspas, barra e UTF-8, além de técnico nulo como `-`. O risco de interpretação como fórmula foi mantido conscientemente em F8. O cabeçalho literal é `ID,Titulo,Status,Tecnico,Aberto em`, sem BOM, e cada chamado autorizado ocupa um registro em ordem crescente de ID.
- Contadores `Com_select` confirmaram uma SELECT por listagem, exportação e cálculo de média. Não foi feito benchmark com volume real; o resultado comprovado é a redução de consultas e de linhas transferidas para o indicador.
- Um banco criado com o schema e seed originais foi migrado com o ALTER indicado abaixo. Os quatro logins originais continuaram funcionando e os quatro MD5 foram substituídos.
- DB_PASS ausente e conexão indisponível produziram 503 com mensagem genérica. DB_PASS igual a `0` foi preservado.

A caracterização anterior reproduziu o payload SQL retornando todos os IDs, Bruno vendo o chamado 101 e a média de Ana, e DivisionByZeroError com todas as respostas NULL. Também confirmou que o teste connect_errno do original não trata a exceção lançada no construtor. Os testes e cópias de caracterização foram mantidos fora de `code/`, sem adicionar arquivos à aplicação.

## Implantação e limites operacionais

Antes de disponibilizar o PHP corrigido em uma base existente, faça backup e amplie a coluna, preservando os hashes atuais:

```sql
ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
```

Não execute o `CREATE TABLE` de `schema.sql` nem reaplique `seed.sql` sobre a produção: esses arquivos atendem a instalações novas. O ALTER foi verificado sobre uma cópia local do banco legado. O usuário usado pela autenticação precisa poder atualizar `usuarios.senha`; a migração oportunista não deve ser implantada enquanto a coluna ainda for CHAR(32), pois o hash atual não cabe nela.

Configure `DB_HOST`, `DB_NAME`, `DB_USER` conforme a instalação e **DB_PASS** obrigatoriamente no ambiente. Uma string vazia explícita é aceita para preservar a possibilidade de instalações com outra forma de controle de acesso; não há fallback secreto. Configure **SMTP_API_KEY** se a central de notificações for usada; ela não é chamada pelos caminhos presentes neste pacote. Revogue/substitua os valores expostos anteriormente e remova CSVs antigos do servidor conforme a retenção do operador. Nenhum segredo antigo foi copiado para este relatório.

O hash MD5 de contas sem login ainda existe até autenticação válida ou reset administrado. O formato sha256: é usado somente para preservar senhas longas ou com NUL que não podem ir diretamente ao bcrypt; não cria campo no retorno público nem muda a senha do usuário. As instalações novas já recebem hashes atuais nos fixtures.

Se HTTPS terminar em um proxy, o servidor deve informar HTTPS corretamente ao PHP ou configurar `session.cookie_secure=1`. Não foi adicionado reconhecimento automático de cabeçalhos encaminhados, porque o pacote não define proxies confiáveis. Secure preexistente é preservado. O arquivo CSV novo usa stream por requisição e pode transbordar para armazenamento temporário administrado pelo PHP; não fica no caminho público fixo anterior.

O tratamento 503 é um limite da entrada web; a biblioteca continua propagando exceções a consumidores internos, que devem tratar seus próprios erros. O código não introduz middleware, framework, tabela de sessão, nova camada de banco ou dependência externa.

## Decisões — o que não alterei

- **Assinaturas públicas, mysqli e nomes/caminhos dos cinco arquivos originais.** As integrações do ISP dependem desse contrato. Foram mantidos parâmetros, tipos, defaults e formatos de retorno; os campos numéricos de listagem/detalhe foram normalizados como strings, conservando o retorno das consultas mysqli originais com a configuração padrão; não foi adicionada dependência ou adotada outra stack.

- **Rótulos de status e árvore de decisão de prioridade/SLA.** Os textos e os limites atuais fazem parte do comportamento consumido pelo produto. A aninhagem de rotuloPrioridade é pequena e não demonstra por si só uma falha. Inclusive o fallback Resolvido para status diferente de 1 e 2 foi preservado, sem inventar uma política para dados inválidos.

- **Curingas % e _ da busca e ordenação da listagem por criado_em decrescente.** Parametrizar elimina a injeção sem alterar a semântica existente de LIKE. Paginação, busca literal e busca full-text mudariam o conjunto ou a interpretação dos resultados.

- **Chamadas internas confiáveis sem sessão continuam com escopo global.** As assinaturas recebem apenas mysqli, sem identidade, e o manifesto prevê exportação noturna e relatório gerencial. Exigir sessão desses consumidores quebraria o contrato. Todo acesso HTTP pelos caminhos entregues continua passando pelo login; outro script web que use a biblioteca deve estabelecer a sessão autenticada antes das consultas.

- **Conteúdo textual exato do CSV, inclusive o risco de fórmulas F8.** Prefixar os campos para planilhas modificaria títulos e nomes recebidos pelas integrações. O CSV preserva cinco colunas, o cabeçalho requerido, valores de status e a ordem por id; a interpretação como texto deve ser definida pelo importador ou por um novo contrato acordado.

- **Aceitação transitória de MD5 já armazenado.** Rejeitar todos os hashes antigos impediria o login das contas existentes. Sem a senha original não é possível convertê-los diretamente para password_hash; a atualização ocorre em login válido. As contas inativas ainda exigem expiração/reset conduzido pelo operador.

- **Rotação real das credenciais expostas, limpeza do histórico e remoção de CSVs antigos do servidor.** O pacote não fornece acesso autorizado aos serviços de produção nem ao seu histórico. Os segredos saíram dos arquivos entregues, e novas exportações não usam o arquivo compartilhado; a revogação e a limpeza dos artefatos preexistentes são ações operacionais pendentes.

- **EXPORT_DIR, schema de chamados, índices, status/prioridade e estrutura geral de index.php.** EXPORT_DIR foi conservado como constante legada, embora a nova exportação não dependa dele. Não há evidência de que mudar os domínios ou índices resolveria um problema medido. Uma reorganização em framework/camadas ampliaria o risco e fugiria da evolução localizada solicitada.

- **Rotas, estrutura HTML, usuários e senhas de teste.** busca, ver e export, tabela-chamados, as cinco colunas e os links de ID permanecem. As quatro contas continuam usando as credenciais descritas no manifesto; só sua representação no banco novo mudou.
