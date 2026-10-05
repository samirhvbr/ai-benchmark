# Relatório técnico — LEB-100-A

## Resumo

O painel apresentava SQL injection, XSS, falta de autorização por proprietário e exportação sujeita a corrida, além de problemas de sessão, configuração e tratamento de dados. Foram identificados 12 achados: 10 corrigidos no código e 2 explicitamente mantidos como pendências (F6 e F8). As alterações estão em `code/config.php`, `code/lib.php` e `code/index.php`; `schema.sql` e `seed.sql` foram preservados. Não houve troca de stack, renomeação de arquivos ou alteração das assinaturas públicas.

Instância **LEB-100-A**, versão **1.1**; matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

As localizações abaixo e no JSON usam exclusivamente a **numeração original** dos arquivos recebidos. Os achados estão em ordem de prioridade de correção; a confiança estima a existência do problema, não a probabilidade de exploração em uma implantação específica.

## Achados

### F1 — SQL injection na busca de chamados

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** critica. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** listarChamados concatena busca dentro de LIKE '%...%' e executa a string. Uma busca como ' OR 1=1 --  encerra o literal e altera a condição; UNION também permite consultar outras tabelas acessíveis à conta do banco. O parâmetro vem diretamente de GET em index.php:72–73, após login.

**Impacto:** Um usuário autenticado pode alterar a consulta e ler dados fora do filtro, inclusive dados sensíveis acessíveis ao usuário SQL da aplicação.

**Tratamento e justificativa:** A consulta usa prepare/bind_param para o padrão LIKE. O predicado de autorização fica separado do valor da busca. Mantive a semântica existente dos curingas % e _.

### F2 — Ausência de autorização por proprietário em todas as leituras

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** Listagem e detalhe consultam chamados sem usuario_id. O mesmo ocorre na média (lib.php:109) e no CSV (lib.php:132). index.php:38–39 lê uid/papel, mas nunca os utiliza. Ana pode listar, abrir por ID e exportar chamados de Bruno; a média também inclui dados alheios.

**Impacto:** Exposição de títulos, descrições, responsáveis, datas e indicadores de outros clientes, contrariando a regra de visibilidade do manifesto.

**Tratamento e justificativa:** Adicionei um único predicado interno, escopoChamados, usado em listagem, detalhe, estatística e exportação. Cliente recebe filtro por usuario_id; técnico vê todos. Sessão web ausente/inválida resulta em escopo vazio. Detalhe proibido retorna null, como chamado inexistente. Jobs PHP CLI sem sessão mantêm a visão global para preservar as rotinas internas.

### F3 — XSS refletido no formulário e no texto da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** busca é inserida diretamente entre aspas do atributo value e no corpo de um parágrafo. Uma entrada como "><script>alert(1)</script> cria marcação executável ao carregar a URL; não depende de gravação no banco.

**Impacto:** Execução de JavaScript na origem do painel, permitindo leitura do conteúdo exibido e requisições com a sessão da vítima.

**Tratamento e justificativa:** Escape de saída com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 nos dois contextos. Também explicitei flags e codificação dos escapes existentes em títulos, descrição e nome do técnico; o texto usado na consulta permanece original.

### F4 — Arquivo CSV compartilhado provoca corrida e vazamento entre downloads

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** Toda exportação abre EXPORT_DIR/chamados.csv em modo w, fecha e só depois lê o mesmo caminho. Outra requisição pode truncar ou substituir esse arquivo entre a escrita e readfile. Com o filtro por cliente, uma corrida entregaria dados de outro escopo. Sem diretório gravável, fopen falha e a função retorna sem CSV.

**Impacto:** Downloads incompletos ou misturados, possível exposição de chamados de outro cliente e dependência desnecessária de um diretório temporário persistente.

**Tratamento e justificativa:** Removi o arquivo intermediário. Cada requisição transmite pelo próprio php://output, com consulta não bufferizada e fechamento em finally. Mantive nome do download, Content-Type, rota e ordenação por id; a constante EXPORT_DIR foi preservada para compatibilidade com código externo.

### F5 — Credenciais de banco e SMTP embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** DB_PASS contém um fallback identificado como senha de produção e SMTP_API_KEY contém um literal secreto. Qualquer pessoa com acesso ao pacote, cópias ou histórico pode recuperar esses valores; DB_PASS ainda selecionava o fallback quando a variável de ambiente era vazia.

**Impacto:** Possível acesso indevido ao banco ou uso da conta de e-mail caso essas credenciais continuem válidas. A validade externa não foi testada.

**Tratamento e justificativa:** Retirei os segredos literais e carreguei ambos do ambiente, preservando as constantes. Uma variável ausente produz string vazia, e DB_PASS explicitamente vazio ou igual a 0 é respeitado. É necessário configurar o ambiente de implantação e revogar/rotacionar as credenciais antigas; esta entrega corrige a exposição no código, mas não executa rotação em serviços externos.

### F6 — Senhas armazenadas como MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Não corrigido; pendência documentada.

**Mecanismo:** autenticar calcula md5(senha) e compara o resultado no banco. schema.sql:7 limita senha a CHAR(32), e seed.sql:5–8 usa MD5. O mesmo texto gera o mesmo hash para todos os usuários, e o cálculo rápido facilita tentativas offline após vazamento do banco.

**Impacto:** Recuperação de senhas por dicionário e identificação de usuários com a mesma senha após acesso aos hashes.

**Tratamento e justificativa:** Não alterado nesta entrega. Corrigir exige ampliar a coluna para VARCHAR(255), ler o hash pelo login, verificar hashes modernos com password_verify e atualizar MD5 após autenticação válida com password_hash. A migração deve incluir os demais gravadores de senha, ausentes do pacote, e recuperação das contas que não voltarem a autenticar. Alterar apenas o PHP ou o CREATE TABLE fornecido não migra uma base de produção existente.

### F7 — Identificador de sessão permanece igual após autenticação

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** session_start cria/retoma uma sessão anônima e o login apenas grava uid/papel nela. Se um atacante conseguir fazer a vítima usar um identificador conhecido, esse mesmo identificador ganha privilégios após o login. A exploração depende da possibilidade de fixar ou obter a sessão prévia.

**Impacto:** Possível sequestro de sessão autenticada por fixação do identificador.

**Tratamento e justificativa:** Regenero o identificador com exclusão da sessão anterior após login válido. Habilitei modo estrito e uso somente de cookies, com HttpOnly, SameSite=Lax e Secure quando HTTPS é informado pelo servidor. As credenciais e o redirecionamento de login permanecem iguais.

### F8 — Campos CSV podem ser interpretados como fórmulas em planilhas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Estado:** Não corrigido; pendência documentada.

**Mecanismo:** Título e nome do técnico são exportados como texto sem neutralizar prefixos de fórmula. fputcsv protege delimitadores, mas uma planilha pode executar/interpretar uma célula iniciada por =, +, - ou @. A condição de exploração é existir conteúdo controlado por terceiro no banco e o destinatário abrir o CSV em um programa que avalie esse conteúdo; o pacote não fornece a rota que grava esses campos.

**Impacto:** Possível execução de fórmulas ou referências externas no aplicativo de planilha do destinatário, conforme suas configurações. Não é execução de PHP nem SQL no servidor.

**Tratamento e justificativa:** Não alterado: prefixar apóstrofo modificaria Titulo/Tecnico para consumidores automáticos. O CSV contratado mantém os valores armazenados. Recomendo importação como texto e, numa evolução acordada, uma exportação específica para planilhas com neutralização; não acrescentei uma nova rota nesta entrega.

### F9 — Média de resposta divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** A consulta exclui NULL, mas soma e qtd iniciam em zero. Se não houver linhas respondidas, retorna soma/qtd com divisor zero. Isso ocorre em uma base vazia e, após corrigir a autorização, no conjunto de chamados de Bruno no seed.

**Impacto:** Falha ao renderizar a listagem sem respostas; no PHP 8 ocorre DivisionByZeroError.

**Tratamento e justificativa:** SUM e COUNT são calculados no banco sobre o escopo permitido; sem respostas, retorna 0.0. Para contagem positiva, a divisão continua em PHP para preservar a precisão do retorno float: AVG(INT) no banco arredondaria 77/3 para 25.6667. A agregação transfere apenas uma linha.

### F10 — Consultas N+1 para nomes de técnicos na listagem e no CSV

**Local original:** `code/lib.php:88–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** Cada chamado com tecnico_id chama tecnicoNome, que faz outro SELECT (lib.php:64–71). O export repete o padrão em lib.php:136–137. No seed são cinco SELECTs por listagem/exportação: um para chamados e quatro para técnicos, inclusive repetindo Carla.

**Impacto:** A quantidade de idas ao banco cresce com o número de chamados, aumentando a latência e a carga para listagens e exportações grandes.

**Tratamento e justificativa:** Usei LEFT JOIN usuarios com COALESCE(t.nome, '-') nas duas consultas. Cada caminho faz um SELECT, conserva tecnico_nome e inclui chamados sem técnico. A função tecnicoNome foi mantida para consumidores externos; o CSV usa resultado não bufferizado.

### F11 — Parâmetros HTTP em formato de array causam TypeError

**Local original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** PHP decodifica login[]=x, senha[]=x e busca[]=x como arrays. O controlador repassa esses valores às funções que exigem string (login em 22 e busca em 72–73), gerando TypeError. ver[]=x ainda era convertido indevidamente para um número.

**Impacto:** Requisições malformadas derrubam a própria resposta e podem produzir ruído nos logs; mensagens detalhadas dependem da configuração do PHP.

**Tratamento e justificativa:** Validei tipos na fronteira HTTP: login/senha em arrays não autenticam, busca não textual vira busca vazia e detalhe em array resulta em não encontrado. Mantive o cast inteiro de IDs textuais para preservar links como ver=00101.

### F12 — Cabeçalho CSV diverge do literal exigido pelo manifesto

**Local original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** Corrigido no código entregue.

**Mecanismo:** fputcsv coloca aspas em Aberto em por conter espaço, emitindo ID,Titulo,Status,Tecnico,"Aberto em". É um CSV semanticamente válido, mas diverge do cabeçalho textual exato exigido no manifesto.

**Impacto:** Consumidores que comparam a primeira linha literalmente rejeitam o arquivo, embora leitores CSV comuns interpretem as mesmas cinco colunas.

**Tratamento e justificativa:** Escrevo o cabeçalho constante exato ID,Titulo,Status,Tecnico,Aberto em seguido de LF. Os registros continuam serializados por fputcsv, com delimitador, aspas e escape explicitados; escape vazio permite roundtrip de barras e aspas e evita a depreciação do escape implícito no PHP 8.4.

## Decisões

- **MD5, schema.sql e seed.sql:** F6 permanece aberto. A migração do formato de senha precisa abranger o banco já existente e os gravadores de senha externos. Mantive os logins de teste e não simulei uma migração apenas alterando CREATE TABLE.

- **Valores de títulos e nomes no CSV:** F8 permanece aberto. Neutralizar fórmulas com apóstrofo modifica valores usados por integrações; mantive a fidelidade dos dados e documentei a mitigação para leitores de planilha.

- **Assinaturas públicas e mysqli:** Todas as sete funções do manifesto mantêm nomes, parâmetros, defaults e tipos declarados de retorno. Não adicionei dependências nem troquei a camada de banco.

- **Visão global das rotinas internas CLI sem sessão:** Os scripts internos de exportação e relatório não recebem identidade nas assinaturas fornecidas. Tratei PHP CLI sem sessão como contexto confiável do operador; com identidade presente, o escopo é aplicado, e web sem identidade válida não ganha essa exceção. Integrações web devem iniciar uma sessão válida.

- **Regras de status, prioridade e SLA:** Mantive os rótulos e a lógica de rotuloPrioridade, inclusive limiar de 30 minutos, caso sem resposta e comportamento legado para valores fora do domínio. Não há evidência de erro de regra que autorize trocá-los; reduzir aninhamento não justifica ampliar o diff.

- **Rotas, estrutura HTML, ordenações e busca LIKE:** Mantive busca/ver/export, tabela-chamados, ordem e nomes das cinco colunas, links de detalhe, listagem por criado_em DESC e CSV por id crescente. Curingas LIKE continuam funcionando; não introduzi paginação, limite de resultados ou ordem adicional para empates.

- **tecnicoNome, EXPORT_DIR, arquivos e arquitetura básica:** Preservei a função auxiliar e a constante embora a exportação não dependa mais de arquivo em disco. Nenhum arquivo foi movido/renomeado; index.php continua como controlador e HTML, e lib.php concentra consultas/regras. Uma reestruturação não é necessária para estas correções.

- **Rotação de credenciais e dados de produção:** F5 remove os valores do código, mas revogação de credenciais requer operação nos serviços correspondentes. A implantação deve definir DB_PASS e, se usado, SMTP_API_KEY por ambiente. Usei somente banco isolado para testes, sem acessar ou alterar serviços de produção.

## Validação

Validação executada com PHP **8.4.26**, extensão mysqli, e **MariaDB 11.8.6** em instância temporária isolada. O schema e o seed entregues foram carregados nessa instância. Nenhum banco de produção foi utilizado. Não foi executada uma segunda bateria em MySQL 8; as consultas usam recursos compartilhados por ambos, mas essa é uma limitação do ambiente validado.

- `php -l code/config.php`, `php -l code/lib.php` e `php -l code/index.php`: sem erros de sintaxe.
- **API e contrato: 94/94 verificações passaram.** Reflexão das sete assinaturas e do argumento padrão de busca; retorno da autenticação para os quatro usuários; rótulos de status e prioridades nas fronteiras de SLA; listagem, detalhe, média e CSV por papel; rotina CLI sem sessão; busca parametrizada; consultas constantes para técnicos; base vazia e somente NULL; roundtrip CSV com UTF-8, emoji, vírgulas, aspas, barras e quebra de linha.
- **HTTP: 93/93 verificações passaram.** Login correto e incorreto; mudança do ID de sessão e HttpOnly; tabela, colunas e links; visibilidade de todos os cinco detalhes para cada um dos quatro usuários; busca por título; entradas SQLi e XSS; parâmetros em arrays; cabeçalho, ordem, cinco colunas, status, Content-Type e nome de download; ID com zeros iniciais; escape de conteúdo armazenado; média exibida por cliente.
- A bateria HTTP incluiu **40 exportações concorrentes**, alternando Ana e Bruno em servidor PHP com quatro workers. Todos os downloads continham apenas os IDs do respectivo cliente.
- Com o seed, Ana vê `[105, 102, 101]`, Bruno `[104, 103]`, e Carla/Diego `[105, 104, 103, 102, 101]` na listagem. O CSV usa a ordem crescente dos mesmos IDs. A média global e de Ana é `77/3`, verificada com tolerância inferior a `1e-9`; a de Bruno é `0.0`.
- Logs HTTP da bateria sem warnings/deprecations; fixtures adicionais revertidos após os testes.

Os scripts auxiliares de validação e os resultados estão em `/tmp/leb-validation/api.php`, `/tmp/leb-validation/http_check.py`, `/tmp/leb-validation/api-results.json` e `/tmp/leb-validation/http-results.json` neste ambiente. São evidências temporárias, não dependências da aplicação nem arquivos acrescentados a `code/`. As baterias cobrem os caminhos descritos, não constituem teste de carga de produção nem auditoria dos serviços externos.

Também foram conferidos o JSON válido, os metadados da instância, as linhas originais, a correspondência de IDs F1–F12 entre os dois artefatos e a preservação byte a byte de `manifest.md`, `TAREFA.md`, `schema.sql` e `seed.sql`.

## Implantação e limites

As correções não exigem migração de schema. Configure as credenciais por variáveis de ambiente antes de substituir os três PHP; não há mais fallback para segredos de produção. Revogue os valores antigos nos serviços respectivos. O servidor deve informar HTTPS corretamente para ativar o atributo Secure do cookie; TLS e configuração do proxy permanecem responsabilidade da implantação.

O contexto CLI global pressupõe execução por rotinas internas confiáveis, conforme o manifesto. O código não oferece um novo mecanismo de identidade para integrações externas. A limitação de senhas MD5 e o risco condicional de fórmulas em planilhas permanecem exatamente como indicado em F6 e F8.
