# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas confirmadas de isolamento entre clientes, SQL injection na busca e XSS refletido, além de credenciais embutidas, autenticação por MD5 e sessão não renovada após o login. A exportação dependia de um arquivo compartilhado, e o indicador de SLA falhava quando não havia respostas. Também foram encontrados desperdícios de consultas e tratamento inadequado de entradas e falhas de conexão.

Foram registrados **14 achados: 13 corrigidos e 1 reportado sem alteração**. O código foi evoluído nos cinco arquivos existentes de `code/`, sem trocar a stack ou mysqli. As sete assinaturas públicas, as rotas, os rótulos de status, a estrutura da tabela HTML e os campos e ordenação do CSV foram preservados. A visibilidade agora segue o manifesto: clientes acessam seus próprios chamados; técnicos acessam todos.

Todas as localizações dos achados abaixo usam a **numeração original recebida**, conforme a tarefa. IDs, severidades, confiança e decisões correspondem a `achados.json`.

## Achados, em ordem de prioridade

### F1 — Falta de autorização por proprietário nas rotas de chamados

- **Local:** `code/index.php:38–74`; consultas relacionadas em `code/lib.php:80–85`, `100`, `109` e `132`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** o controlador lê `$uid` e `$papel`, mas não os usa para limitar consultas. A listagem, o detalhe e o CSV consultam chamados de todos os usuários. Assim, Ana pode acessar diretamente `?ver=103`, consultar a listagem de Bruno ou baixar seus chamados por `?export=csv`. A média global também revela informação de chamados alheios.
- **Impacto:** exposição de títulos, descrições, situação e demais dados de outros clientes, contrariando a regra explícita de visibilidade.
- **Correção:** criada uma condição interna comum às quatro consultas. Para sessão de cliente, o banco restringe por `c.usuario_id`; para técnico, mantém acesso global; papéis inválidos com UID não recebem registros. O detalhe alheio retorna `null`, usando a mesma resposta de chamado inexistente. O filtro é aplicado antes da seleção, inclusive na busca, no CSV e na média.
- **Compatibilidade:** relatórios internos sem UID de sessão continuam com acesso global, pois suas assinaturas não recebem identidade de usuário. A entrada web continua exigindo login antes de chamar essas funções. Os testes cobriram os quatro usuários em cada rota.
- **Estado:** corrigido.

### F2 — SQL injection na busca por título

- **Local:** `code/lib.php:80–85`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** `$busca` era concatenada diretamente entre aspas no `LIKE`. Um termo como `%' OR 1=1 -- ` fecha a expressão e transforma dados recebidos por GET em sintaxe SQL. O uso de `query()` nessa função não oferece a proteção dos parâmetros presentes no login.
- **Impacto:** manipulação da consulta e leitura indevida de dados; consultas construídas com `UNION` podem projetar conteúdo de outras tabelas acessíveis à conta do banco.
- **Correção:** a listagem agora usa `prepare()` e `bind_param()` para o padrão de busca. A condição de proprietário é independente do termo. Foram preservados o filtro por título, a ordenação decrescente por criação e o comportamento preexistente dos curingas `%` e `_` do `LIKE`.
- **Estado:** corrigido.

### F3 — XSS refletido no termo de busca

- **Local:** `code/index.php:79–82`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** o GET `busca` era inserido sem escape tanto no atributo `value="..."` quanto no parágrafo de resultados. Aspas podem encerrar o atributo, e uma tag `<script>` ou `<img onerror=...>` no termo é interpretada pelo navegador.
- **Impacto:** execução de JavaScript na origem do painel quando um usuário autenticado abre um link preparado, permitindo leitura do conteúdo visível e ações com sua sessão.
- **Correção:** o termo recebe `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos de saída. Títulos, descrições e nomes já escapados passaram a usar explicitamente os mesmos parâmetros. O texto original continua sendo usado na consulta; não foi codificado nem adulterado no banco.
- **Estado:** corrigido.

### F4 — Credenciais de infraestrutura embutidas no código

- **Local:** `code/config.php:11–15`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** a senha de produção do banco é fallback literal, e a chave SMTP é uma constante literal. Distribuir o fonte distribui os segredos; a ausência de variável de ambiente também seleciona silenciosamente uma senha de produção.
- **Impacto:** quem obtém o pacote pode tentar acesso ao banco ou usar o serviço SMTP, conforme a validade e a exposição desses serviços. A presença dos segredos é confirmada; sua validade externa não foi testada.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` passam a vir do ambiente, sem segredo embutido. Os nomes das constantes foram mantidos, e valores de ambiente como a string `0` não são descartados por teste de truthiness.
- **Pendência operacional:** fornecer as variáveis e rotacionar os valores anteriormente expostos. Remover o literal do código não revoga uma credencial já distribuída.
- **Estado:** corrigido no código.

### F5 — Armazenamento de senhas em MD5 sem salt

- **Local:** `code/lib.php:15–16`; suporte ao problema em `code/schema.sql:7` e `code/seed.sql:2–8`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** a autenticação calcula `md5($senha)` e compara com uma coluna `CHAR(32)`. O hash rápido, determinístico e sem salt permite tentativas offline em grande escala quando a tabela é exposta, inclusive pela falha F2. A coluna original não comporta os hashes de senha modernos.
- **Impacto:** recuperação facilitada das senhas e reutilização das credenciais em sessões legítimas.
- **Correção:** a função busca o usuário pelo login e valida hashes modernos com `password_verify()`. MD5 existente é aceito apenas como transição, comparado com `hash_equals()` e substituído por `password_hash()` depois de login válido. `password_needs_rehash()` permite evolução posterior. O UPDATE exige também o hash anterior, evitando sobrescrever uma alteração concorrente. A coluna passa a `VARCHAR(255)`, e o seed usa hashes modernos mantendo as quatro credenciais do manifesto. O retorno continua contendo somente `id`, `nome` e `papel`.
- **Implantação:** ampliar a coluna do banco existente **antes** de publicar a nova autenticação. Hashes legados de contas que não efetuarem login continuarão existentes até autenticação válida ou troca operacional de senha; não foi imposto reset para preservar o acesso atual.
- **Estado:** corrigido, com migração compatível entregue.

### F6 — Sessão não renovada após autenticação

- **Local:** `code/index.php:15–26`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** a sessão é iniciada antes do login, e o sucesso apenas grava UID e papel no mesmo identificador. Quem consegue fazer a vítima usar uma sessão anônima conhecida conserva esse identificador quando ela autentica. O código também não define atributos protetores do cookie nem modo estrito de sessão.
- **Impacto:** possibilidade de sequestro da sessão autenticada em um cenário de fixação do identificador.
- **Correção:** `session_regenerate_id(true)` no login bem-sucedido, modo estrito e uso exclusivo de cookies. O cookie recebe `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição é HTTPS. Não se confia em cabeçalhos encaminhados arbitrários para detectar HTTPS.
- **Estado:** corrigido. Os testes HTTP confirmaram a troca do identificador e os atributos aplicáveis em HTTP.

### F7 — Exportação usa arquivo compartilhado entre requisições

- **Local:** `code/lib.php:125–150`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Mecanismo:** toda exportação abre o mesmo `EXPORT_DIR/chamados.csv` com modo `w`, escreve, fecha e só então executa `readfile()`. Uma segunda requisição pode truncar ou substituir esse arquivo entre a escrita e a leitura da primeira. Depois de corrigida a visibilidade, isso ainda misturaria exportações de clientes diferentes. Se o diretório não existe ou não é gravável, a função retorna sem conteúdo; a falha de consulta também abandona a finalização explícita do arquivo.
- **Impacto:** downloads vazios, parciais ou de outra requisição e exposição entre clientes; dependência desnecessária de diretório fixo e de um arquivo persistente contendo dados.
- **Correção:** cada chamada usa seu próprio stream `php://temp`, sem nome compartilhado. O CSV é preparado antes dos headers, rebobinado e enviado com `fpassthru()`. `finally` fecha o stream e libera o resultado mesmo em falhas. A consulta não bufferiza todos os registros no cliente mysqli, e não são feitas consultas adicionais durante sua leitura. As falhas de preparação e escrita deixam de ser retornos silenciosos.
- **Compatibilidade:** mantidos MIME, nome de download, cinco campos e ordem crescente de ID. `EXPORT_DIR` permanece definido para consumidores externos, mas esta função não depende dele. A análise do isolamento por stream é estática; não foi realizado teste de carga concorrente.
- **Estado:** corrigido.

### F8 — Divisão por zero quando não há primeira resposta

- **Local:** `code/lib.php:109–116`.
- **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100.
- **Mecanismo:** a consulta pode não devolver nenhum `minutos_resposta` não nulo; nesse caso `$qtd` permanece zero e a função executa `$soma / $qtd`. Em PHP 8 isso gera `DivisionByZeroError`, interrompendo a listagem. O cliente Bruno do seed tem somente tempos nulos, tornando o caso especialmente relevante após o isolamento correto.
- **Impacto:** painel indisponível quando não há chamados respondidos no escopo, seja banco vazio, todos os tempos nulos ou cliente sem respostas.
- **Correção:** a agregação usa `COALESCE(AVG(...), 0)` e retorna `float`. Ausência de observações produz `0.0`; tempos nulos continuam fora da média. A coerção SQL para ponto flutuante evita o arredondamento decimal padrão do `AVG` sobre inteiros, preservando a precisão do cálculo original para dados não vazios.
- **Estado:** corrigido. Testados banco vazio, todos os tempos nulos e o escopo de Bruno.

### F9 — Parâmetros HTTP em formato de array provocam falhas ou IDs incorretos

- **Local:** `code/index.php:21–22`; outros pontos em `53` e `72–73`.
- **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100.
- **Mecanismo:** PHP aceita parâmetros como `busca[]=x`, `login[]=ana` e `senha[]=x`. O controlador passa os arrays a funções que exigem `string`, gerando `TypeError`. Já `(int) $_GET['ver']` converte um array não vazio para `1`, selecionando um ID que não corresponde à entrada recebida.
- **Impacto:** erro 500 reproduzível por requisição malformada e seleção incorreta de detalhe. É falha por requisição, não evidência de queda de todo o serviço.
- **Correção:** validar que login, senha e busca são strings; validar `ver` como inteiro positivo antes da chamada. Formatos inválidos recebem HTTP 400 e mensagem simples. Entradas válidas e nomes dos parâmetros foram mantidos.
- **Estado:** corrigido.

### F10 — Campos do CSV podem ser interpretados como fórmulas por planilhas

- **Local:** `code/lib.php:138–144`.
- **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100.
- **Mecanismo:** título e nome do técnico são gravados literalmente. `fputcsv()` escapa a estrutura CSV, mas não impede que uma planilha interprete uma célula iniciada por `=`, `+`, `-` ou `@` como fórmula. Por exemplo, um título `=1+1` pode ser avaliado quando o CSV é aberto nesse tipo de aplicativo. O pacote não contém a rota que cadastra esses campos, portanto não foi presumido um canal específico de escrita pelo adversário.
- **Impacto:** avaliação involuntária de fórmulas e, conforme aplicativo e política da planilha, requisições externas ou exposição de dados. Isso depende do uso do CSV em planilhas; não é execução de código no PHP.
- **Decisão:** não alterado. Prefixar apóstrofos ou substituir os campos modificaria os valores usados pela exportação noturna e integração de faturamento. O contrato entregue não define uma transformação para células de planilha. A correção exige definir esse comportamento com os consumidores antes de modificar o CSV; nesta entrega os valores textuais foram preservados.
- **Estado:** reportado, não corrigido.

### F11 — Exceção de conexão contorna a mensagem de erro prevista

- **Local:** `code/index.php:9–13`.
- **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100.
- **Mecanismo:** o código espera construir `mysqli` e depois verificar `connect_errno`. Em PHP 8.1 ou superior, o modo padrão de mysqli lança `mysqli_sql_exception` quando a conexão falha, antes desse teste. Assim, a mensagem prevista não é emitida e a inicialização termina por exceção não tratada. Com exibição de erros habilitada, detalhes técnicos também podem aparecer na resposta.
- **Impacto:** resposta inesperada em indisponibilidade ou configuração incorreta do banco, com possível exposição de diagnóstico conforme a configuração PHP.
- **Correção:** definir explicitamente o modo de erros mysqli e envolver conexão e configuração de charset em `try/catch`. Falha de inicialização recebe HTTP 503 e apenas `Falha ao conectar ao banco.`.
- **Estado:** corrigido. Verificado com banco inexistente e `display_errors=1`, sem stack trace na resposta.

### F12 — Consultas N+1 para nomes de técnicos

- **Local:** `code/lib.php:88–89`; mesmo padrão em `137`, com consulta auxiliar em `69`.
- **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100.
- **Mecanismo:** após buscar os chamados, cada registro com técnico chama `tecnicoNome()`, executando outro SELECT, inclusive quando vários chamados têm o mesmo técnico. Listagem e exportação custam uma consulta base mais uma por chamado atribuído.
- **Impacto:** crescimento linear dos round-trips e da latência, além de carga desnecessária no banco para relatórios maiores.
- **Correção:** ambas as consultas usam `LEFT JOIN usuarios` e `COALESCE(t.nome, '-') AS tecnico_nome`. O JOIN externo mantém chamados sem técnico, e a chave pública `tecnico_nome` e o marcador `-` permanecem. A função auxiliar foi mantida para compatibilidade de consumidores legados.
- **Estado:** corrigido. Contadores `Com_select` confirmaram uma consulta na listagem e uma na exportação.

### F13 — Média de SLA transfere todos os tempos para o PHP

- **Local:** `code/lib.php:109–115`.
- **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100.
- **Mecanismo:** para mostrar um único número, o banco envia todos os tempos não nulos e o PHP percorre cada linha para somar e contar. O `query()` original ainda mantém o resultado bufferizado no cliente mysqli. Esse custo se repete a cada acesso à listagem.
- **Impacto:** transferência e processamento proporcionais ao número de chamados respondidos, com consumo de memória do resultado no cliente. A leitura dos dados no servidor continua necessária; não foi alegado que a agregação elimina esse trabalho.
- **Correção:** agregação `AVG` no banco, devolvendo uma única linha, com o mesmo escopo de visibilidade e tratamento de nulos. A expressão em ponto flutuante preserva, por exemplo, `77 / 3` sem arredondá-la para quatro casas decimais.
- **Estado:** corrigido. Este achado trata do custo de processamento; F8 trata da falha funcional com conjunto vazio.

### F14 — Cabeçalho CSV difere do texto literal declarado

- **Local:** `code/lib.php:130`.
- **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100.
- **Mecanismo:** o cabeçalho era enviado a `fputcsv()`, que coloca aspas no campo contendo espaço. A primeira linha produzida é `ID,Titulo,Status,Tecnico,"Aberto em"`, enquanto o manifesto declara literalmente `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** divergência para consumidores que validam o cabeçalho literal exigido pelo manifesto. Um parser CSV correto reconhece os mesmos cinco campos, por isso a severidade é baixa.
- **Correção:** escrever o cabeçalho estático exatamente como declarado, mantendo `fputcsv()` nos dados. O escape dos dados é explícito, sem depender do parâmetro padrão depreciado no PHP 8.4.
- **Estado:** corrigido. A primeira linha foi comparada literalmente nos testes de função e HTTP.

## Decisões

1. **CSV com valores textuais originais:** não foi aplicada transformação antifórmula aos títulos ou nomes, pela compatibilidade dos consumidores de exportação descrita em F10. O risco permanece documentado.
2. **Senhas e contas existentes:** não forcei troca de senha nem removi imediatamente a leitura de MD5. Isso bloquearia contas existentes. A migração ocorre após validação de senha, e novas bases já usam hashes modernos. Contas inativas com hash legado precisam de tratamento operacional posterior.
3. **Relatórios internos:** não passei a exigir sessão web ou novo argumento de identidade em funções chamadas pela exportação noturna e relatórios gerenciais. Sem UID de sessão, conservam o comportamento global de rotina interna confiável; as rotas web estão protegidas por autenticação e escopo.
4. **Rótulos e regra de prioridade:** não mudei textos de status, limiar de SLA, mensagens de prioridade nem o comportamento de status fora de 1–3. O manifesto define os valores válidos, e não há evidência suficiente para alterar o tratamento dos demais. Evitei refatoração estética da árvore de prioridade.
5. **Semântica de busca e paginação:** mantive busca parcial, curingas SQL preexistentes e ordenação por criação. Não adicionei limites ou paginação que omitiriam registros da lista retornada pela API pública, nem índice especulativo para `LIKE '%termo%'`, que não se beneficia normalmente de índice B-tree de prefixo.
6. **Stack, estrutura e constantes:** mantidos mysqli, funções procedurais, caminhos dos cinco arquivos e `EXPORT_DIR`. Não introduzi framework, dependência, nova camada de dados ou reorganização extensa de `index.php`.
7. **Ações fora do pacote:** não efetuei rotação de credenciais em serviços externos, DDL no banco de produção ou reset de contas. Essas operações exigem os ambientes reais; os ajustes necessários à implantação estão explicitados abaixo. As bases e servidores usados na verificação foram temporários e isolados.

## Implantação da correção

Para banco já existente, executar **antes de publicar o código PHP**:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

Não reaplicar `seed.sql` em produção: ele é o conjunto de dados de teste do manifesto. Em base nova, `schema.sql` e `seed.sql` já produzem o formato atualizado. A expansão da coluna não altera IDs, papéis ou credenciais dos usuários.

Fornecer `DB_PASS` pelo ambiente e, para os consumidores que usam notificações, `SMTP_API_KEY`. Rotacionar as credenciais antes embutidas. `DB_HOST`, `DB_NAME` e `DB_USER` mantêm seus nomes e defaults não secretos. O timezone não mudou. Em implantação com terminação TLS em proxy, o servidor PHP deve receber a indicação HTTPS confiável para ativar o atributo `Secure`.

## Verificação realizada

- **Sintaxe:** `php -l code/config.php`, `php -l code/lib.php` e `php -l code/index.php`: todos aprovados.
- **Ambiente:** PHP **8.4.26**, extensões mysqli/mysqlnd e banco temporário **MariaDB 11.8.6**. O schema declara MySQL 8; essa versão específica do servidor não foi executada nesta máquina.
- **Testes de integração:** executado `python3 /tmp/opencode/run_leb_checks.py`, usando `/tmp/opencode/verify_leb.php`, banco dedicado e servidor PHP local temporário. **131 verificações de funções e 90 de HTTP/ambiente, total 221 aprovadas**, sem warnings ou deprecations do PHP. Os roteiros temporários não fazem parte dos arquivos de produção.
- **Contrato:** reflexão das sete assinaturas e default da busca; quatro credenciais do manifesto; retorno de autenticação; três textos de status; 24 combinações de prioridade/SLA; chave `tecnico_nome`; tabela `tabela-chamados`, ordem das cinco colunas e links de detalhe.
- **Segurança e correção funcional:** visibilidade de todos os cinco chamados para cada usuário em listagem, detalhe, CSV e média; busca que não revela outro cliente; payloads SQL tratados como dados; payloads HTML sem tags executáveis; entradas em array rejeitadas; rotação de sessão; erro de conexão controlado.
- **Migração e limites:** login moderno; MD5 válido convertido e senha inválida sem alteração; usuário e chamado inexistentes; sessão com escopo inválido; base vazia e todos os tempos nulos; precisão da média; CSV com UTF-8, aspas, vírgula, barra e quebra de linha, sem alterar o conteúdo dos campos.
- **Performance:** contagem SQL confirmou um SELECT por listagem e um por exportação. Não foi realizado benchmark de carga.
- **Artefatos:** `achados.json` validado estruturalmente, com IDs e blocos correspondentes neste relatório, referências dentro da numeração original e metadados exatos da instância.

## Entrega

- `code/config.php`, `code/index.php`, `code/lib.php`, `code/schema.sql` e `code/seed.sql` alterados no lugar.
- `RELATORIO.md`: este relatório.
- `achados.json`: índice estruturado dos mesmos 14 achados e das decisões.
