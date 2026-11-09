# Relatório técnico — LEB-100-A

## Resumo

O painel preservava corretamente parte da superfície pública, mas aplicava praticamente nenhuma restrição de visibilidade depois do login: qualquer cliente autenticado podia listar, abrir e exportar chamados de outros clientes. Também havia injeção de SQL na busca, XSS refletido, segredos no código-fonte, hashes de senha obsoletos e riscos na geração do CSV.

Foram feitas correções localizadas em `config.php`, `index.php`, `lib.php` e `schema.sql`. As sete funções públicas, os parâmetros GET, os rótulos, a estrutura da tabela e o formato do CSV declarados no manifesto foram preservados. As rotas web agora usam variantes com escopo de usuário; as funções públicas originais continuam disponíveis para os consumidores internos já existentes.

## Achados

### F1 — Cliente autenticado podia ler e exportar chamados de qualquer pessoa

- **Onde:** originalmente em `code/index.php`, linhas 44–45, 52–53 e 72–73, em conjunto com `code/lib.php`, linhas 78–100 e 123–145.
- **Mecanismo:** depois de obter `uid` e `papel` da sessão, o front controller chamava `listarChamados`, `verChamado` e `exportarCsv` sem informar o usuário. As consultas dessas funções não tinham condição sobre `chamados.usuario_id`. Assim, o login apenas impedia acesso anônimo; não impedia Ana de abrir diretamente `index.php?ver=103`, pertencente a Bruno, nem de baixar todos os chamados no CSV.
- **Impacto e severidade:** exposição de títulos, descrições, situação e dados de atendimento entre clientes, além de exportação em massa. **Severidade alta.**
- **Confiança:** **100**.
- **Correção:** criadas as funções internas `listarChamadosParaUsuario`, `verChamadoParaUsuario` e `exportarCsvParaUsuario`. Para `papel === 'cliente'`, elas restringem os resultados a `usuario_id = uid`; para técnicos, mantêm acesso integral. O detalhe de um chamado alheio passa a responder como não encontrado. As três rotas foram ligadas a essas variantes. As assinaturas públicas originais não foram alteradas.

### F2 — Injeção de SQL no filtro de título

- **Onde:** `code/lib.php`, linhas originais 80–85.
- **Mecanismo:** o valor de `busca`, vindo diretamente de `GET`, era concatenado entre aspas em `WHERE titulo LIKE '%...%'`. Um cliente podia fechar a cadeia e alterar a consulta, inclusive usando uma expressão `UNION` compatível com as colunas de `chamados`; o resultado era devolvido à listagem. Não havia escape nem parâmetro vinculado nesse caminho.
- **Impacto e severidade:** leitura não autorizada de dados acessíveis ao usuário do banco e manipulação da consulta, mesmo com credenciais de cliente de baixo privilégio. **Severidade alta.**
- **Confiança:** **100**.
- **Correção:** a busca passou a usar `prepare` e `bind_param`; o termo é vinculado como `%termo%`. A combinação de busca e restrição de proprietário também usa parâmetros separados. A semântica de busca parcial com `LIKE` e a ordenação foram mantidas.

### F3 — XSS refletido pelo parâmetro `busca`

- **Onde:** `code/index.php`, linhas originais 79–82.
- **Mecanismo:** `$busca` era inserido sem codificação tanto no atributo `value` do formulário quanto no parágrafo “Resultados para”. Um endereço contendo marcação HTML ou uma aspa seguida de manipulador de evento era reproduzido no documento de um usuário autenticado, permitindo execução de JavaScript no contexto do painel.
- **Impacto e severidade:** ações em nome da vítima, leitura do conteúdo exibido e redirecionamento para conteúdo malicioso, condicionados à abertura do link por um usuário autenticado. **Severidade média.**
- **Confiança:** **100**.
- **Correção:** os dois pontos de saída passaram por `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')`. O nome do parâmetro e o formulário GET não mudaram.

### F4 — Senhas protegidas apenas por MD5

- **Onde:** `code/lib.php`, linhas originais 13–20; esquema correspondente em `code/schema.sql`, linha original 7.
- **Mecanismo:** a autenticação calculava `md5($senha)` e comparava esse valor diretamente com uma coluna `CHAR(32)`. MD5 é rápido e não possui sal por usuário; em caso de vazamento da tabela, senhas fracas podem ser testadas em grande volume e hashes iguais revelam senhas iguais.
- **Impacto e severidade:** recuperação offline de credenciais e possível reutilização em outros serviços após vazamento do banco. **Severidade alta.**
- **Confiança:** **100**.
- **Correção:** `autenticar` agora usa `password_verify` e `password_hash(PASSWORD_DEFAULT)`. Para não invalidar os usuários atuais nem os dados de teste, um hash hexadecimal de 32 caracteres ainda é aceito como legado após comparação com `hash_equals`; no primeiro login válido, ele é substituído pelo hash moderno. A coluna passou a `VARCHAR(255)` no esquema. O retorno continua contendo somente `id`, `nome` e `papel`.

### F5 — Senha de produção e chave SMTP embutidas no repositório

- **Onde:** `code/config.php`, linhas originais 8–15.
- **Mecanismo:** na ausência de `DB_PASS`, o código utilizava uma senha real identificada como sendo de produção. A chave SMTP era sempre uma constante literal. Qualquer pessoa com acesso ao pacote-fonte obtinha ambas, e trocar essas credenciais exigia alteração de código.
- **Impacto e severidade:** acesso ao banco ou ao serviço de e-mail, conforme os privilégios e a exposição desses serviços; o vazamento persiste em cópias e históricos do fonte. **Severidade alta.**
- **Confiança:** **100** quanto à presença e ao vazamento dos segredos no artefato; o acesso remoto efetivo depende da rede.
- **Correção:** removidos os valores literais. `DB_PASS` e `SMTP_API_KEY` agora são lidos do ambiente, sem segredo de fallback. As credenciais expostas devem ser revogadas/rotacionadas, pois retirá-las do arquivo não apaga cópias anteriores.

### F6 — Sessão não era renovada após autenticação

- **Onde:** `code/index.php`, linhas originais 20–26.
- **Mecanismo:** após validar a senha, o código gravava `uid` e `papel` na sessão preexistente sem chamar `session_regenerate_id`. Em instalações que aceitem um identificador de sessão fornecido previamente, quem induzisse a vítima a usar esse identificador poderia reutilizá-lo depois do login.
- **Impacto e severidade:** sequestro da sessão autenticada e acesso com o papel da vítima; a exploração depende das configurações de cookie/sessão do servidor. **Severidade média.**
- **Confiança:** **95**.
- **Correção:** adicionado `session_regenerate_id(true)` imediatamente após autenticação bem-sucedida e antes de popular os dados de autorização. O fluxo e o redirecionamento de login permanecem iguais.

### F7 — Campos do CSV podiam ser interpretados como fórmulas

- **Onde:** `code/lib.php`, linhas originais 136–144.
- **Mecanismo:** `fputcsv` trata delimitadores e aspas, mas não impede interpretação por planilhas. Um título ou nome iniciado por `=`, `+`, `-`, `@`, tabulação ou retorno de carro era exportado diretamente; ao abrir o arquivo em uma planilha, a célula podia ser avaliada como fórmula.
- **Impacto e severidade:** execução de fórmulas no aplicativo de planilha de quem abre a exportação, com risco de exfiltração ou comandos conforme os recursos e as proteções desse aplicativo. **Severidade média.**
- **Confiança:** **100**.
- **Correção:** textos de título e técnico com prefixos perigosos recebem um apóstrofo inicial antes de `fputcsv`; valores comuns não são alterados. Cabeçalho, ordem das colunas, rótulos de status e ordenação por ID permanecem conforme o contrato.

### F8 — Exportações concorrentes compartilhavam e sobrescreviam o mesmo arquivo

- **Onde:** `code/lib.php`, linhas originais 125–150.
- **Mecanismo:** todas as requisições abriam `EXPORT_DIR . '/chamados.csv'` com modo `w`, gravavam e depois reliam esse caminho. Duas exportações simultâneas podiam truncar ou misturar o conteúdo antes do `readfile`; uma falha de consulta depois da abertura também deixava o arquivo aberto e incompleto. Além da indisponibilidade, o compartilhamento poderia devolver conteúdo produzido por outra requisição.
- **Impacto e severidade:** CSV corrompido, incompleto ou associado à requisição errada sob concorrência. **Severidade média.**
- **Confiança:** **95**.
- **Correção:** removida a etapa em disco. O CSV é escrito diretamente em `php://output`, com descritor próprio por requisição, mantendo os mesmos cabeçalhos HTTP e o nome de download.

### F9 — Base sem respostas provocava divisão por zero

- **Onde:** `code/lib.php`, linhas originais 107–116.
- **Mecanismo:** `mediaResposta` dividia `$soma` por `$qtd` incondicionalmente. Em uma instalação nova, ou quando todos os chamados ainda tinham `minutos_resposta IS NULL`, a consulta retornava zero linhas e `$qtd` permanecia zero; a listagem falhava em vez de exibir o painel.
- **Impacto e severidade:** indisponibilidade da listagem justamente no estado inicial ou quando nenhum chamado recebeu resposta. **Severidade média.**
- **Confiança:** **100**.
- **Correção:** a função retorna `0.0` quando não há amostras e conserva o cálculo anterior nos demais casos. O tipo de retorno continua `float`.

### F10 — Uma consulta extra por chamado na listagem e no CSV (N+1)

- **Onde:** `code/lib.php`, linhas originais 87–90 e 136–138.
- **Mecanismo:** depois de buscar todos os chamados, cada linha chamava `tecnicoNome`, que executava um novo `SELECT` pelo ID do técnico. Uma página com N chamados fazia N+1 consultas; a exportação repetia o mesmo padrão e ampliava o tempo de bloqueio e a carga no MySQL conforme o volume crescia.
- **Impacto e severidade:** latência e consumo desnecessários de banco em listagens e exportações grandes. **Severidade média.**
- **Confiança:** **100**.
- **Correção:** as duas consultas principais agora fazem `LEFT JOIN usuarios` pelo técnico e usam `COALESCE` para preservar `-` quando não há responsável. Cada operação passa a usar uma consulta, e a chave pública `tecnico_nome` continua presente.

## Decisões

- **Não alterei as assinaturas públicas nem o escopo das funções legadas usadas por rotinas internas.** `listarChamados`, `verChamado` e `exportarCsv` continuam com os parâmetros e retornos documentados. O controle por papel foi adicionado em variantes chamadas pelo `index.php`; assim, a correção da regra web não muda silenciosamente a exportação integral da rotina noturna.
- **Não removi `tecnicoNome` nem a constante `EXPORT_DIR`.** Embora os caminhos internos de listagem/exportação não precisem mais deles, sua retirada não é necessária para as correções e poderia afetar código fora do pacote.
- **Não alterei rótulos, regras de prioridade, rotas, ordenação da listagem, estrutura da tabela ou formato contratual do CSV.** A busca continua parcial via `LIKE`; o CSV continua com o cabeçalho exato e IDs crescentes.
- **Não mudei a média para um escopo por cliente.** O contrato restringe a visibilidade dos chamados, mas mantém `mediaResposta(mysqli $db): float` como indicador global consumido pelo painel/relatório; criar uma média diferente apenas para uma classe de usuário mudaria um comportamento não apontado como defeito.
- **Não troquei mysqli nem introduzi dependências.** Os prepared statements e joins usam a camada já existente.

## Implantação e validação

Para instalações que já estão em produção, a alteração de `schema.sql` precisa ser aplicada como migração antes do primeiro rehash: `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Também é necessário configurar `DB_PASS` e `SMTP_API_KEY` no ambiente e rotacionar os valores que estavam no fonte. Os hashes MD5 existentes continuam funcionando durante essa transição.

Foram executados `php -l` em `config.php`, `lib.php` e `index.php`, sem erros, e testes locais dos rótulos, das assinaturas públicas e da proteção de células CSV. Não foi possível executar uma integração real com MySQL neste ambiente porque o usuário disponível não tem acesso ao servidor de banco.
