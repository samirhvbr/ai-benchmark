# Relatório técnico — LEB-100-A

Instância 1.1; LEB spec 1.1.0; tarefa 1.0.0. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel permitia que clientes lessem chamados de outros clientes, aceitava SQL na busca e refletia entrada sem escape no HTML. Também havia credenciais no código, sessões sem renovação após autenticação, fragilidades no CSV, divisão por zero e consultas repetidas. Foram registrados **12 achados: 11 corrigidos no código e 1 mantido como pendência explícita (F5, senhas MD5)**. A remoção dos segredos do código em F4 não substitui sua rotação operacional.

Foram alterados somente `code/lib.php`, `code/index.php` e `code/config.php`, nos caminhos originais. `schema.sql`, `seed.sql`, `manifest.md` e `TAREFA.md` foram preservados. Não foram adicionadas dependências nem trocada a camada mysqli. Toda a execução, os dados descartáveis e os testes ficaram dentro da pasta do pacote; nenhum banco de produção foi acessado.

As referências abaixo usam **a numeração original dos arquivos recebidos**, como exigido pela tarefa. Os IDs correspondem exatamente aos de `achados.json`.

## Achados, em ordem de prioridade

### F1 — Ausência de autorização por proprietário nas leituras

- **Local original:** `code/lib.php:78–101`; também `lib.php:109` e `lib.php:132`, chamados por `code/index.php:38–45`, `52–53` e `72–74`.
- **Categoria / severidade / confiança:** segurança / alta / **100**.
- **Mecanismo:** a sessão identifica o usuário e seu papel, mas nenhuma consulta aplica esses valores. A listagem retorna toda a tabela; `verChamado` filtra apenas pelo ID; exportação e média também usam todos os clientes. Ana pode abrir `?ver=103`, listar e exportar chamados de Bruno. O indicador ainda divulga uma estatística calculada com dados alheios.
- **Impacto:** exposição horizontal de títulos, descrições, responsáveis e dados de atendimento entre clientes, inclusive por navegação normal.
- **Correção:** foi acrescentado um filtro comum às quatro funções de leitura. Cliente recebe somente `usuario_id` igual ao ID validado da sessão; técnico continua vendo todos os chamados, inclusive os não atribuídos e os de outros técnicos. Identidade incompleta ou papel desconhecido negam a leitura. O detalhe não autorizado retorna `null`, igual a um ID inexistente. As assinaturas permanecem iguais.
- **Compatibilidade:** scripts internos executados em CLI sem identidade de sessão conservam o acesso global. Se uma identidade estiver presente em CLI, o filtro também a respeita. Na web não existe essa exceção. Um consumidor web legítimo da biblioteca deve fornecer a sessão autenticada usada pelo painel.
- **Verificação:** Ana, Bruno, Carla e Diego; lista, busca, detalhe, CSV e média; sessão incompleta; acesso CLI. **Corrigido.**

### F2 — Injeção SQL pelo parâmetro de busca

- **Local original:** `code/lib.php:80–85`.
- **Categoria / severidade / confiança:** segurança / alta / **100**.
- **Mecanismo:** `$busca` é concatenada dentro do literal de `LIKE`. A entrada `' OR 1=1 -- ` fecha esse literal e transforma o restante em SQL/comentário. A consulta original reproduzida no banco de teste retornou todos os cinco chamados.
- **Impacto:** alteração da seleção e possibilidade de extração de dados por consultas injetadas, limitada aos privilégios da conta do banco. Não se pressupõe que múltiplas instruções SQL estejam habilitadas.
- **Correção:** `listarChamados` usa `prepare` e `bind_param` para o padrão de busca. O filtro de proprietário permanece fora do parâmetro. Os curingas `%` e `_` continuam com a semântica de `LIKE` que já existia.
- **Verificação:** a mesma entrada passou a retornar lista vazia pela função e pela rota HTTP, sem erro SQL; busca normal e curinga continuam funcionando. **Corrigido.**

### F3 — XSS refletido no formulário e no resultado de busca

- **Local original:** `code/index.php:79–82`.
- **Categoria / severidade / confiança:** segurança / alta / **100**.
- **Mecanismo:** o termo GET é emitido diretamente tanto no atributo `value` delimitado por aspas duplas quanto no conteúdo de um parágrafo. Aspas podem encerrar o atributo e tags podem criar elementos executáveis no contexto do painel. O escape já aplicado aos títulos do banco não protege esses dois pontos.
- **Impacto:** execução de JavaScript na origem do painel ao abrir um link malicioso, com acesso às ações e informações disponíveis na sessão da vítima.
- **Correção:** ambos os pontos usam escape HTML explícito com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8. O mesmo tratamento foi uniformizado nos textos já escapados da listagem e do detalhe.
- **Verificação:** payload com fechamento de atributo, `script` e evento HTML permaneceu escapado nos dois pontos, sem produzir as tags injetadas. **Corrigido.**

### F4 — Credenciais embutidas no código

- **Local original:** `code/config.php:11–15`.
- **Categoria / severidade / confiança:** segurança / alta / **100**.
- **Mecanismo:** há uma senha identificada no comentário como de produção, usada como fallback, e uma chave SMTP literal. O acesso a uma cópia do código revela esses valores; a ausência de variável de ambiente ativa silenciosamente a senha embutida.
- **Impacto:** possível acesso ao banco e uso indevido do serviço de e-mail se as credenciais ainda forem válidas. Sua presença é comprovada; sua validade operacional não foi testada.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` são obtidos exclusivamente do ambiente, sem os valores secretos anteriores. As constantes e as demais opções de conexão foram preservadas. Na ausência de variável, o valor é a string vazia; o ambiente de produção deve fornecer a configuração exigida pelo respectivo serviço.
- **Limite:** remover os literais não revoga credenciais já copiadas nem limpa históricos externos. **Corrigido no código; rotação de ambos os segredos e configuração do ambiente continuam sendo ações de implantação**, sem acesso ou alteração de serviços externos nesta tarefa.

### F5 — Senhas armazenadas como MD5 sem salt

- **Local original:** `code/lib.php:15–17`; armazenamento em `code/schema.sql:7` e exemplos em `code/seed.sql:5–8`.
- **Categoria / severidade / confiança:** segurança / alta / **100**.
- **Mecanismo:** `md5($senha)` é comparado diretamente à coluna `CHAR(32)`. O mesmo segredo gera o mesmo hash para todos os usuários, e o algoritmo rápido permite testar grandes quantidades de candidatos offline se a tabela for obtida. A presença de um prepared statement no login impede SQL injection nesse ponto, mas não fortalece o hash.
- **Impacto:** recuperação de senhas fracas em um vazamento da base, especialmente relevante em conjunto com F2.
- **Decisão:** **não corrigido** nesta entrega. Trocar somente `md5` por `password_hash`/`password_verify` invalidaria os usuários existentes; hashes modernos não cabem na coluna atual. Alterar apenas o `CREATE TABLE` fornecido também não migra uma base já em produção. Não foi introduzida uma alteração automática de DDL durante o login.
- **Remediação proposta:** ampliar `senha` para `VARCHAR(255)` por migração controlada; passar a gravar hashes modernos e verificar com `password_verify`; reconhecer o formato MD5 legado exclusivamente para migração após um login válido; substituir o hash de forma condicional para evitar corrida com troca de senha; depois remover a compatibilidade MD5 e exigir redefinição dos usuários não migrados. Planejar política para senhas longas conforme o algoritmo escolhido, backup e implantação coordenada. Esta pendência permanece classificada como alta, sem alegação de mitigação pelo prepared statement.

### F6 — Identificador de sessão não renovado no login

- **Local original:** `code/index.php:15–26`.
- **Categoria / severidade / confiança:** segurança / alta / **98**.
- **Mecanismo:** o login acrescenta `uid` e `papel` à sessão prévia sem trocar o identificador. Se um atacante conseguir fazer a vítima usar uma sessão cujo ID ele conhece, esse mesmo ID passa a representar uma sessão autenticada. A viabilidade de impor o ID depende também da configuração e do ambiente. O arquivo não determina modo estrito nem atributos de proteção do cookie.
- **Impacto:** apropriação da sessão autenticada em um cenário de fixação de sessão.
- **Correção:** o login válido executa `session_regenerate_id(true)` antes de gravar a identidade. A sessão usa somente cookies, modo estrito, `HttpOnly` e `SameSite=Lax`; `Secure` é habilitado quando o servidor informa HTTPS.
- **Verificação:** ID fornecido e não inicializado foi rejeitado; um ID válido pré-login mudou após autenticação; o ID anterior não acessou a sessão autenticada. Os atributos `HttpOnly` e `SameSite` foram conferidos por HTTP. **Corrigido.**

### F7 — Exportações compartilham um arquivo truncável

- **Local original:** `code/lib.php:125–150`.
- **Categoria / severidade / confiança:** bug / média / **100**.
- **Mecanismo:** todas as requisições abrem `EXPORT_DIR/chamados.csv` com modo `w`, escrevem, fecham e depois reabrem o mesmo caminho via `readfile`. Outra requisição pode truncar ou substituir o conteúdo entre essas operações. Se `fopen` falhar, a função simplesmente não entrega o relatório; se a consulta retornar `false`, o caminho de retorno não fecha explicitamente o arquivo.
- **Impacto:** downloads vazios, parciais ou inconsistentes sob concorrência. Quando há escopos distintos por cliente, o arquivo compartilhado também pode misturar os resultados. A cópia persistida poderia ficar acessível se aquele diretório fosse servido pela web, mas essa configuração não foi presumida.
- **Correção:** a exportação escreve diretamente em `php://output`, com um recurso por chamada e fechamento em `finally`. Não cria arquivo persistente e não depende de permissões de `EXPORT_DIR`. Mantém nome do download, tipo de conteúdo, colunas e ordenação crescente por ID.
- **Verificação:** oito processos simultâneos exportaram alternadamente como Ana e Bruno sem erros e sem dados do outro cliente. **Corrigido.**

### F8 — Texto do banco pode virar fórmula em uma planilha

- **Local original:** `code/lib.php:136–144`.
- **Categoria / severidade / confiança:** segurança / média / **95**.
- **Mecanismo:** título e nome do técnico entram diretamente em `fputcsv`. O escape sintático de CSV não impede que um programa de planilha interprete uma célula iniciada por `=`, `+`, `-` ou `@` como fórmula. O painel não mostra uma rota de gravação desses campos; o vetor depende da entrada desses dados no banco por outros consumidores e da abertura em um programa que execute fórmulas.
- **Impacto:** cálculo ou execução de fórmulas indesejadas ao abrir o relatório. Recursos adicionais de uma fórmula dependem do aplicativo e de suas configurações; não se afirma execução de comandos em qualquer planilha.
- **Correção:** campos textuais com esses prefixos, inclusive após espaços/controles ASCII, ou iniciados por tabulação/quebra de linha, recebem apóstrofo inicial. O marcador `-` de técnico não atribuído é preservado. `fputcsv` usa escape explícito vazio para preservar corretamente aspas e barras em CSV padrão e evitar a depreciação de seu argumento omitido no PHP 8.4.
- **Compatibilidade e verificação:** cabeçalho, ordem, status e textos comuns permanecem iguais; o prefixo faz parte do campo exportado somente nos textos identificados como perigosos, sem alterar o dado no banco. Foram testados fórmulas em título e técnico, aspas, vírgulas, barras e linhas embutidas. Não foi executado um aplicativo de planilha. **Corrigido para o tratamento descrito.**

### F9 — Média falha quando não existe resposta registrada

- **Local original:** `code/lib.php:109–116`.
- **Categoria / severidade / confiança:** bug / média / **100**.
- **Mecanismo:** a consulta remove valores nulos; quando nenhum registro sobra, `$qtd` fica zero e a função calcula `$soma / $qtd`. Isso pode acontecer com a tabela vazia ou com todos os chamados aguardando resposta. Depois da correção de visibilidade, Bruno também representa esse caso com o seed fornecido. Em PHP 8, ocorre `DivisionByZeroError`.
- **Impacto:** a listagem deixa de ser renderizada para esse conjunto válido de dados. A implementação também transfere todas as respostas para somá-las em PHP.
- **Correção:** `SUM` e `COUNT` agregam as respostas no banco dentro do mesmo escopo de autorização. A divisão ocorre em PHP apenas quando a contagem é positiva, preservando a precisão de ponto flutuante original sem o arredondamento decimal de `AVG` sobre inteiros. Nulos continuam excluídos e valores zero reais continuam participando da média. A ausência de respostas retorna `0.0`.
- **Verificação:** média `77/3` do seed, cliente sem respostas e tabela vazia. **Corrigido.**

### F10 — Erros de mysqli não têm tratamento consistente

- **Local original:** `code/index.php:9–13`; também acessos ao retorno de consultas em `code/lib.php:85–88` e `109–112`.
- **Categoria / severidade / confiança:** bug / média / **98**.
- **Mecanismo:** a checagem de `connect_errno` pressupõe que o construtor retorne normalmente. Em configurações de mysqli que lançam exceções, como o padrão do PHP 8.4 disponível, a execução falha antes dessa checagem. Outras consultas também podem lançar exceção ou retornar `false`, dependendo do modo. Sem um tratamento comum, a resposta vira um erro não controlado; detalhes internos podem aparecer se `display_errors` estiver habilitado.
- **Impacto:** resposta incorreta em falhas operacionais e possível divulgação de informações internas condicionada à configuração do PHP.
- **Correção:** o ponto de entrada determina explicitamente o modo estrito de mysqli e trata exceções não capturadas com HTTP 500 e texto genérico. O log registra classe e código, sem incluir senha, SQL ou argumentos da exceção. Os consumidores diretos da biblioteca continuam responsáveis por seu próprio tratamento de exceções.
- **Verificação:** no banco descartável, a tabela foi temporariamente renomeada; a requisição devolveu somente a mensagem genérica com status 500. A tabela foi restaurada em `finally`. **Corrigido.**

### F11 — Consultas N+1 para obter o nome do técnico

- **Local original:** `code/lib.php:87–90`; também `lib.php:64–71` e `lib.php:136–137`.
- **Categoria / severidade / confiança:** performance / média / **100**.
- **Mecanismo:** a listagem e o CSV consultam chamados e, para cada técnico não nulo, executam uma consulta adicional em `tecnicoNome`, mesmo quando vários chamados têm o mesmo responsável. O número de consultas é `1 + quantidade de chamados atribuídos`, aumentando as viagens ao banco com o volume.
- **Impacto:** latência e carga de banco desnecessárias em listagens e exportações grandes.
- **Correção:** ambos os caminhos usam `LEFT JOIN usuarios` com `COALESCE`, obtendo `tecnico_nome` na própria consulta e mantendo chamados sem técnico. A função `tecnicoNome` foi preservada. O CSV também usa resultado não bufferizado (`MYSQLI_USE_RESULT`) e consome cada linha antes de liberar o recurso.
- **Verificação:** contadores `Com_select` confirmaram uma consulta por listagem e uma por CSV, inclusive com 106 chamados no teste. Não foi realizado um benchmark de produção nem prometido um ganho percentual de tempo ou memória. **Corrigido.**

### F12 — Parâmetros em formato de array quebram as rotas

- **Local original:** `code/index.php:21–22`; também `index.php:52–53` e `index.php:72–73`.
- **Categoria / severidade / confiança:** bug / baixa / **100**.
- **Mecanismo:** PHP aceita `login[]=ana`, `senha[]=x` e `busca[]=x` como arrays. Esses valores entram diretamente em funções com parâmetros `string`, causando `TypeError`. O cast de `ver[]` também pode produzir um ID sem representar uma entrada válida.
- **Impacto:** requisições malformadas geram falhas internas e tratamento de IDs incoerente; não há evidência de indisponibilidade global do serviço.
- **Correção:** login e senha só são usados quando são strings; entradas inválidas mantêm a tela de login. Busca inválida e detalhe sem ID decimal positivo recebem HTTP 400. Os nomes dos parâmetros e os valores válidos continuam aceitos.
- **Verificação:** arrays em login, senha, busca e detalhe não causaram erro interno; busca/detalhe inválidos retornaram 400. **Corrigido.**

## Decisões e limites de compatibilidade

1. **MD5 e estrutura do banco:** mantidos como descrito em F5 para não invalidar a base de produção sem uma migração executável e coordenada. Isso é uma dívida de segurança, não uma aprovação do algoritmo.
2. **Contrato público:** mantidas todas as sete assinaturas mysqli, tipos de retorno, chave `tecnico_nome`, parâmetros `busca`, `ver` e `export`, nome do download, cinco colunas do CSV e suas posições. O cabeçalho emitido é literalmente `ID,Titulo,Status,Tecnico,Aberto em`. A tabela continua com `id="tabela-chamados"`, as mesmas cinco colunas e links `index.php?ver=<id>`.
3. **Rótulos e SLA:** não alterei `formatarStatus` nem `rotuloPrioridade`, inclusive o limite estrito de 30 minutos, o comportamento para prioridade 4 e o fallback legado de status fora de 1–3. Simplificar a indentação não justificaria alterar regras ou ampliar o diff sem necessidade.
4. **Busca e ordenações:** mantive a semântica dos curingas SQL, a listagem por `criado_em DESC` e o CSV por `id` crescente. Não adicionei paginação ou limite que eliminaria chamados esperados por consumidores.
5. **Acesso interno e arquitetura:** a exceção de acesso global exige execução CLI sem identidade. A dependência da sessão no filtro interno é explícita e mantém as assinaturas existentes; não foi introduzido framework, nova camada de dados ou reestruturação do ponto de entrada. `tecnicoNome` e a constante legada `EXPORT_DIR` permanecem disponíveis, embora o exportador já não os utilize.
6. **Conteúdo de CSV perigoso:** textos comuns são preservados. Textos interpretáveis como fórmula recebem o prefixo descrito em F8; consumidores que importam tais valores literalmente verão esse apóstrofo. É uma mudança de tratamento de conteúdo perigoso, sem mudar o esquema ou as regras de visibilidade do CSV.
7. **Segredos e operação:** não tentei usar, revogar ou trocar as credenciais externas. Antes da implantação, configurar `DB_PASS` e, onde houver integração de e-mail, `SMTP_API_KEY`, com valores novos. Credenciais históricas exigem rotação no serviço correspondente.
8. **HTTPS e ambiente:** não forcei redirecionamento para HTTPS nem confiei em cabeçalhos arbitrários de proxy, pois a topologia não foi fornecida. O cookie `Secure` depende da indicação confiável de HTTPS pelo servidor; a configuração de terminação TLS deve propagar essa informação. Não alterei o fuso horário.
9. **Evolução limitada:** sem renomear arquivos, adicionar dependências, mudar o seed de usuários ou reescrever o sistema. Também não se atribui SQL injection ao login original ou a `tecnicoNome`: o primeiro já usa parâmetros e o segundo recebe um inteiro tipado.

## Validação realizada

Ambiente utilizado: **PHP 8.4.26 com mysqli/mysqlnd e MariaDB 11.8.6**. O esquema se declara MySQL 8; a validação de banco foi feita no servidor compatível disponível, não em uma instância MySQL 8. Não houve instalação de dependências. O banco `leb_validacao`, socket, arquivos de sessão e temporários foram criados sob `.validacao/`, dentro deste pacote, com dados de `schema.sql` e `seed.sql`.

O teste reproduzível está em `.validacao/testes.php`. Resultado: **119 verificações aprovadas**, sem warnings/depreciações na execução CLI com `error_reporting=E_ALL`. Os servidores locais foram encerrados e o banco descartável, as sessões e o log temporário foram removidos após a validação; a suíte foi preservada. Ele cobre:

- Os quatro logins fornecidos, senha incorreta, formato de retorno e ataque SQL no login.
- Status exatos e 20 combinações de prioridade/SLA, incluindo `null`, 30 e 31 minutos.
- Visibilidade por cliente e técnico, detalhe alheio, cliente sem respostas, identidades incompletas e execução CLI sem sessão.
- Reprodução da injeção original em consulta isolada, busca corrigida e conservação do curinga `%`.
- Estrutura HTML, links, escape em atributo e texto, descrição escapada e parâmetros em array.
- Cabeçalho, ordem, status, técnico nulo, fórmulas, aspas, barras e quebras de linha em CSV.
- Cookies, renovação do ID e invalidação do identificador anterior.
- Oito exportações concorrentes; contagem constante de consultas com 106 chamados; erro SQL controlado por HTTP.

Os três arquivos PHP de produção também passaram em `php -l`. A validação final confere o JSON, a correspondência dos 12 IDs com o relatório, as sete assinaturas e os hashes dos arquivos que deveriam permanecer intactos. Não foram executados um navegador com JavaScript, uma planilha, um teste de carga de produção ou uma instância MySQL 8.

Para repetir a suíte, provisionar um banco **descartável e exclusivo** chamado `leb_validacao`, importar `code/schema.sql` e `code/seed.sql`, e iniciar o servidor PHP em `127.0.0.1:18765` com document root `code/`. Tanto o servidor quanto o teste devem receber `DB_HOST`, `DB_USER`, `DB_PASS` e `DB_NAME=leb_validacao`; se for usado socket, configurar igualmente `mysqli.default_socket`. Usar um `session.save_path` dentro desta pasta. Executar `php -d error_reporting=32767 .validacao/testes.php` com a mesma configuração de banco. A suíte insere dados, renomeia temporariamente uma tabela para simular falha e restaura seus dados em `finally`; **não deve ser executada em uma base compartilhada ou de produção**. O auxiliar HTTP de teste requer PHP 8.4.
