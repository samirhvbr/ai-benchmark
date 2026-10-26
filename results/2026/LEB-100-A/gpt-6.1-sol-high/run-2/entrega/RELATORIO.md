# Relatório técnico — LEB-100-A

## Resumo

O painel apresenta falhas concretas de isolamento entre clientes, SQL injection, XSS, gestão de segredos/sessão e exportação, além de erros de disponibilidade e custos evitáveis de consulta. Foram identificados 14 achados: 12 corrigidos no código e dois reportados como pendentes (MD5 e fórmulas em CSV). A revogação das credenciais que já circularam também depende da operação.

Alterados somente `code/lib.php`, `code/index.php` e `code/config.php`, nos mesmos caminhos. `schema.sql`, `seed.sql`, `manifest.md` e `TAREFA.md` foram preservados. Nenhuma dependência foi adicionada; permanecem PHP e mysqli. As assinaturas públicas, rotas, colunas HTML e rótulos do manifesto foram preservados.

Instância **LEB-100-A**, versão **1.1**; matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`. Todas as localizações abaixo usam a **numeração original**, anterior às correções; intervalos que abrangem uma função não significam que toda linha contém a falha. O JSON contém exatamente os mesmos achados, na mesma ordem de prioridade.

## Achados

### F1 — Ausência de autorização por dono em todas as consultas de chamados

**Local original:** `code/lib.php:78–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** listarChamados consulta todos os registros, verChamado restringe somente por id e exportarCsv seleciona toda a tabela. index.php verifica apenas a existência de uid; o papel e o dono não participam das consultas. mediaResposta também agrega dados alheios. Um cliente autenticado pode trocar o parâmetro ver ou baixar o CSV para contornar qualquer filtro visual.

**Impacto:** Exposição de títulos, descrições, responsáveis e datas de outros clientes, além de estatísticas fora do escopo permitido pelo manifesto.

**Ação/decisão:** Adicionado um filtro SQL compartilhado: cliente recebe c.usuario_id = uid, técnico recebe todos, sessão web ausente/inválida não recebe registros. Aplicado na listagem, detalhe, média e CSV, antes de recuperar os dados. Detalhes sem permissão retornam null como os inexistentes. CLI sem sessão mantém acesso global para as integrações internas confiáveis.

**Evidência e limites:** No original, a sessão de Ana listou 105,104,103,102,101 e leu o chamado 103 de Bruno. Na entrega, Ana vê 101/102/105, Bruno 103/104 e Carla/Diego todos, por biblioteca e HTTP. O ponto de entrada relacionado é index.php:20–46,52–74 (linhas originais).

### F2 — SQL injection no termo de busca

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** O texto de GET busca chega a listarChamados e é concatenado entre aspas no predicado titulo LIKE. O valor apostrofo seguido de OR 1=1 e comentário fecha a string e transforma o filtro em uma condição sempre verdadeira. UNION e subconsultas também passam a ser sintaxe SQL executada com os privilégios da conexão.

**Impacto:** Manipulação da consulta, leitura indevida de dados acessíveis ao usuário do banco e consultas dispendiosas. Não se pressupõe execução de múltiplas instruções: mysqli::query não é multi_query.

**Ação/decisão:** Substituída a concatenação do valor por LIKE ? e bind_param, inclusive ao combinar busca e autorização. Mantidas a busca por substring e a interpretação legada dos curingas % e _.

**Evidência e limites:** O payload `' OR 1=1 -- ` retornou todos os cinco registros no original; na entrega retornou zero registros, sem erro SQL, tanto pela biblioteca quanto pela rota de busca.

### F3 — XSS refletido na busca, em atributo e no corpo HTML

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** busca é inserida diretamente no atributo value delimitado por aspas duplas e novamente em um parágrafo. Um termo como `"><script>alert(1)</script>` sai do atributo e injeta marcação executável na página de um usuário autenticado.

**Impacto:** Execução de JavaScript na origem do painel e leitura ou uso das operações acessíveis à vítima. A exploração depende de a vítima abrir o link preparado.

**Ação/decisão:** A busca é codificada com htmlspecialchars e ENT_QUOTES | ENT_SUBSTITUTE, explicitando UTF-8, nas duas saídas. A mesma política foi explicitada nas saídas existentes de título, descrição e técnico.

**Evidência e limites:** O teste HTTP enviou aspas e script: a resposta contém `&quot;` e `&lt;script&gt;`, sem elemento script literal, e preserva a tabela e o formulário.

### F4 — Credenciais de produção embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** DB_PASS usa uma senha literal quando a variável de ambiente está ausente ou é falsy; SMTP_API_KEY é sempre uma chave literal. Quem recebe o código recebe esses segredos, e uma instalação sem configuração tenta reutilizar a senha de produção. Uma senha válida igual a "0" também é ignorada pelo operador ?:.

**Impacto:** Possibilidade de acesso ao banco e ao provedor de e-mail se os segredos ainda forem válidos e os serviços forem alcançáveis; reutilização involuntária de credenciais entre ambientes.

**Ação/decisão:** Removidos os dois segredos literais. DB_PASS e SMTP_API_KEY vêm exclusivamente do ambiente, com comparação explícita a false para preservar valores como "0" e vazio. Sem configuração, os valores são vazios. Os responsáveis devem revogar/rotacionar as credenciais anteriormente distribuídas e atualizar o ambiente; essa operação externa não foi executada.

**Evidência e limites:** Testados ambiente sem as variáveis e ambiente com DB_PASS="0" e chave SMTP externa. Os literais originais não são reproduzidos neste relatório. Corrigido refere-se à presença no código entregue; remover do arquivo não revoga cópias ou histórico.

### F5 — Senhas armazenadas e verificadas com MD5 sem salt

**Local original:** `code/lib.php:13–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** não.

**Mecanismo:** autenticar calcula md5 da senha e compara o resultado diretamente com usuarios.senha. schema.sql:7 restringe a coluna a CHAR(32), e seed.sql:5–8 usa MD5. O mesmo segredo sempre gera o mesmo valor, e não existe custo adaptativo de derivação.

**Impacto:** Um vazamento da tabela permite testar muitas senhas offline e reaproveitar tabelas de hashes; hashes iguais revelam senhas iguais. Isso não implica bypass direto do login nem torna autenticar vulnerável a SQL injection, pois ele já usa parâmetros.

**Ação/decisão:** Não alterado nesta entrega. A correção proposta exige primeiro migrar usuarios.senha para VARCHAR(255), depois usar password_hash/password_verify, com reconhecimento temporário e rehash dos MD5 após login válido, ou redefinição de senha. Apenas trocar md5 no PHP bloquearia os quatro usuários existentes; apenas editar CREATE TABLE não migraria o banco em produção.

**Evidência e limites:** A presença do algoritmo e da coluna de 32 caracteres é verificável diretamente. Os quatro logins originais e as recusas de senha incorreta foram preservados e testados. A transição precisa de uma migração de dados e de um plano de retirada dos hashes legados.

### F6 — Sessão não é renovada após autenticação

**Local original:** `code/index.php:15–27`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** session_start aceita o identificador apresentado antes do login, e o sucesso só escreve uid e papel nessa mesma sessão. Se um atacante conseguir fixar ou conhecer um identificador prévio aceito, ele continuará válido após a vítima autenticar. O código também não estabelece os atributos de proteção do cookie, dependendo dos padrões do servidor.

**Impacto:** Sequestro da sessão autenticada quando houver um caminho para fixar ou compartilhar o identificador. A pré-condição de fixação é necessária; a ausência de regeneração não dá acesso remoto sozinha.

**Ação/decisão:** Habilitados session.use_strict_mode e session.use_only_cookies; session_regenerate_id(true) antes de gravar a identidade. Cookie com HttpOnly, SameSite=Lax e Secure quando o servidor sinaliza HTTPS.

**Evidência e limites:** Em HTTP, um SID inventado foi rejeitado, o cookie incluiu HttpOnly/SameSite e cada um dos quatro logins trocou o SID prévio. Secure depende do indicador HTTPS do servidor; terminação TLS em proxy deve configurar esse indicador corretamente.

### F7 — Exportação usa arquivo compartilhado e pode misturar downloads

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Todas as requisições abrem EXPORT_DIR/chamados.csv com modo w, escrevem, fecham e só depois fazem readfile do mesmo caminho. Outra requisição pode truncar ou substituir o arquivo entre essas etapas. O diretório precisa existir e ser gravável; falhas de fopen retornam silenciosamente, e a saída antecipada após erro de query não fecha explicitamente o descritor. O arquivo permanece em disco após o download.

**Impacto:** CSV vazio, parcial ou pertencente a outra requisição; após aplicar visibilidade por cliente, a corrida também poderia expor dados de outro dono. A entrega do CSV depende desnecessariamente de diretório e permissões no servidor.

**Ação/decisão:** Exportação direta para php://output, sem arquivo compartilhado ou persistente, com fechamento do stream e do statement em finally. Consulta executada antes de enviar cabeçalhos, resultado percorrido com bind_result/fetch e somente as colunas necessárias selecionadas. Mantidos nome do download, tipo e ordem crescente de id.

**Evidência e limites:** Downloads de cada perfil foram verificados por HTTP, incluindo 20 requisições distribuídas entre quatro clientes HTTP. O servidor de teste usa um único worker, portanto isso verifica isolamento das respostas, não reproduz uma corrida simultânea entre processos. A remoção do caminho compartilhado elimina o mecanismo da corrida; EXPORT_DIR foi mantida como constante legada.

### F8 — Campos CSV podem ser interpretados como fórmulas por planilhas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Corrigido:** não.

**Mecanismo:** titulo e o nome do técnico são passados a fputcsv como dados sem política de neutralização de fórmulas. Um título válido como =1+1 continua começando por = no CSV; aspas CSV delimitam a célula, mas não obrigam um programa de planilha a tratá-la como texto. Não existe uma rota de escrita de títulos neste pacote: a origem de um valor malicioso seria uma integração ou cadastro externo.

**Impacto:** Se uma pessoa abrir o arquivo em uma planilha que interprete fórmulas e um produtor externo puder inserir esses campos, o conteúdo pode executar fórmulas ou acionar funções/links admitidos pela planilha. O efeito exato depende do aplicativo e de suas proteções.

**Ação/decisão:** Não neutralizado automaticamente: prefixar apóstrofo ou remover caracteres mudaria títulos e nomes consumidos pela exportação de faturamento. Recomenda-se importar essas colunas explicitamente como texto e acordar um modo separado para planilhas ou uma política de escape com os consumidores antes de alterar os valores.

**Evidência e limites:** Em transação de teste, um título =1+1 foi exportado e lido de volta exatamente como =1+1. Não foi aberta planilha nem demonstrada execução nela; a confiança reflete a condição externa necessária. O valor original foi restaurado por rollback.

### F9 — Divisão por zero quando não há primeira resposta

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** qtd começa em zero e só aumenta para linhas com minutos_resposta não nulo. Com tabela vazia ou todos os tempos nulos, a divisão soma/qtd tenta dividir por zero. Como index.php calcula a média antes de imprimir a listagem, a página inteira falha.

**Impacto:** Indisponibilidade da listagem em uma instalação nova ou sem respostas registradas; em PHP 8 ocorre DivisionByZeroError.

**Ação/decisão:** A média passa a usar SUM e COUNT de valores não nulos no SQL; se COUNT for zero, retorna 0.0. Com dados, a divisão continua em PHP para preservar a precisão do resultado legado.

**Evidência e limites:** Reproduzido DivisionByZeroError no original após tornar os tempos nulos. Testados na entrega banco vazio, todos os tempos nulos e Bruno sem tempos preenchidos; todos retornam 0.0 e a página continua renderizando.

### F10 — Cabeçalho CSV difere do literal exigido no manifesto

**Local original:** `code/lib.php:130–130`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** fputcsv coloca aspas em campos que contêm espaço. Por isso o cabeçalho produzido termina com ,"Aberto em", enquanto o manifesto exige literalmente ,Aberto em. Os cinco campos são equivalentes para um parser CSV, mas os bytes não satisfazem o contrato declarado.

**Impacto:** Consumidores que comparam a primeira linha literalmente podem rejeitar um relatório válido para outros parsers.

**Ação/decisão:** Escrito o cabeçalho fixo ID,Titulo,Status,Tecnico,Aberto em seguido de newline; os registros continuam usando fputcsv com escape explícito vazio para manter roundtrip de aspas, vírgulas, barras e quebras de linha.

**Evidência e limites:** O cabeçalho original foi capturado e confirmou as aspas em Aberto em. A primeira linha literal, as cinco colunas e a ordenação por id foram verificadas na biblioteca e por HTTP, inclusive para CSV vazio.

### F11 — Parâmetros HTTP em forma de array causam erros ou coerção de id

**Local original:** `code/index.php:20–73`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** PHP aceita parâmetros como login[]=ana, senha[]=x e busca[]=x e os entrega como arrays. Os valores são repassados às funções com parâmetros string, provocando TypeError. ver[]=101 é convertido para inteiro, interpretando a estrutura como outro id em vez de rejeitá-la; strings não numéricas também eram convertidas silenciosamente.

**Impacto:** Respostas 500 e consultas a um id diferente do informado para requisições malformadas, com possível exposição de stack trace se display_errors estiver habilitado.

**Ação/decisão:** Validação is_string antes de autenticar e listar; ver exige string que represente inteiro positivo com FILTER_VALIDATE_INT. Entradas inválidas retornam 400 com mensagem genérica. Os parâmetros e os caminhos válidos mantêm seus nomes e comportamento.

**Evidência e limites:** Testados login[], senha[], busca[], ver[], ver=abc, ver=-1 e ver=101abc. Todos retornam 400; os quatro logins, filtros normais e os detalhes com ids válidos continuam funcionando.

### F12 — Consultas repetidas por técnico na listagem e no CSV

**Local original:** `code/lib.php:85–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Cada chamado recuperado chama tecnicoNome, que faz SELECT separado quando tecnico_id não é nulo. A exportação repete o mesmo padrão em lib.php:132–137. Com A chamados atribuídos são 1+A consultas por operação, inclusive quando vários chamados têm o mesmo técnico.

**Impacto:** Latência e carga no banco crescem com o número de chamados; o export mantém esse custo mesmo para técnicos repetidos.

**Ação/decisão:** LEFT JOIN de usuarios pela chave primária em ambas as consultas e COALESCE do nome para o rótulo original -. A listagem mantém tecnico_nome e os demais campos; tecnicoNome continua disponível para consumidores existentes.

**Evidência e limites:** Com o seed, o original fez cinco consultas para listar cinco chamados, quatro deles atribuídos. A entrega faz uma consulta para listar e uma para exportar. A contagem usa SHOW SESSION STATUS e desconta a própria consulta de medição; nomes, nulls e o rótulo - foram testados.

### F13 — Média transfere todos os tempos para somar no PHP

**Local original:** `code/lib.php:109–115`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Cada carregamento da listagem seleciona e transfere uma linha por minutos_resposta não nulo, depois percorre todo o resultado em PHP para obter somente soma e quantidade. O result set de mysqli::query é normalmente bufferizado, ampliando também a memória da aplicação.

**Impacto:** Tráfego, memória no PHP e trabalho na aplicação crescem com o número de respostas em uma operação que precisa apenas de dois agregados.

**Ação/decisão:** Calculados SUM e COUNT no banco e transferida uma única linha, com o mesmo filtro de visibilidade das demais consultas. A soma e a contagem são divididas em PHP: AVG sobre INT arredondou em comparação exploratória e não foi mantido. A leitura das linhas no banco continua necessária; não se afirma tempo total constante.

**Evidência e limites:** A média global permanece 77/3 (25.666666666666668) no comparativo com o legado, e a de Bruno é 0.0. O teste usa tolerância inferior a 1e-12 e cobre também tabela vazia e tempos todos nulos.

### F14 — Tratamento de falha de conexão não alcança exceções do mysqli

**Local original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** O construtor mysqli e set_charset podem lançar mysqli_sql_exception quando o modo strict está ativo, como no padrão das versões PHP recentes usadas no teste. O if connect_errno só é executado após o construtor, por isso não trata essa falha.

**Impacto:** Falha não controlada em indisponibilidade ou erro de credencial; pode gerar stack trace ou detalhes internos dependendo da configuração de erros do PHP.

**Ação/decisão:** Modo de erros mysqli definido explicitamente; conexão e charset dentro de try/catch de mysqli_sql_exception, retornando HTTP 503 e a mensagem genérica legada, sem divulgar exceção ou senha.

**Evidência e limites:** Um servidor HTTP isolado foi iniciado com senha de banco incorreta e display_errors=1. A resposta foi exatamente Falha ao conectar ao banco., com status 503 e sem stack trace. Falhas posteriores de consultas ainda dependem do tratamento/logging da implantação; não foi instalado um capturador global.

## Validação

Executada em PHP **8.4.26** com mysqli/mysqlnd e MariaDB **11.8.6**, em banco isolado criado a partir de `code/schema.sql` e `code/seed.sql`. O schema declara MySQL 8; não havia um servidor MySQL 8 isolado disponível e não se afirma ter validado especificamente esse motor. O SQL adicionado usa SELECT, parâmetros preparados, LEFT JOIN, COALESCE, SUM e COUNT. Nenhum banco de produção foi usado.

- `php -l` nos três arquivos PHP: sintaxe válida.
- **101 verificações PHP** com banco real: assinaturas e retornos, quatro logins, senha incorreta, status e prioridades/limites de SLA, filtro por dono e papel, CLI confiável, sessão parcial/inválida, tentativas de injection, CSV literal/ordenação/dados especiais, média precisa e cenários vazios, e contagem de consultas.
- **100 verificações HTTP**: login, troca de SID, cookies, anônimo sem acesso a detalhe/CSV, clientes e técnicos nas rotas, estrutura da tabela, XSS, busca SQL maliciosa, entradas em array ou ids inválidos, cabeçalhos de download e respostas de exportação repetidas. Foram usadas requisições em threads, mas o servidor PHP tinha um worker; a validação não é ensaio de corrida real entre processos.
- **4 verificações adicionais**: ausência de segredos padrão, leitura de variáveis com valores falsy legítimos e conexão inválida respondendo 503 sem detalhes mesmo com display_errors ativo. Total: **205 verificações de regressão**.
- Comparação diferencial com cópia do código original: listagem global e detalhe iguais, incluindo strings numéricas, nulls, campos e nomes; média global igual a 77/3. A mudança intencional de visibilidade atende à regra expressa do manifesto. O cabeçalho CSV agora corresponde ao literal declarado.
- Reprodução no original: Ana lê chamado de Bruno, busca com OR retorna toda a base, listagem executa cinco consultas, cabeçalho tem aspas extras e tempos todos nulos causam DivisionByZeroError. Alterações de dados usadas nos casos extremos foram revertidas por transação.

Os ensaios não incluíram navegador com execução de JavaScript, aplicativo de planilha, TLS real, rotação de credenciais ou ambiente de produção. A prova de XSS na entrega verifica o escape na resposta HTML; a de sessão verifica cookies/SID por HTTP. As pendências não foram marcadas como corrigidas por esses testes. Scripts de ensaio foram criados fora de `code/` em `/tmp/leb-100-a-tests/`, sem adicionar endpoints de teste ao produto.

## Decisões

- **Migração dos hashes MD5 e alteração de schema.sql/seed.sql (F5).** A coluna atual CHAR(32) e o banco já instalado exigem migração coordenada e transição de credenciais. Não se simulou uma correção trocando somente o PHP. Mantidos os quatro usuários/senhas fornecidos; a pendência tem prioridade alta.

- **Neutralização automática de fórmulas nos valores CSV (F8).** A exportação tem consumidores de faturamento e a alteração de títulos/nomes precisa ser acordada. O risco condicional foi reportado explicitamente; delimitadores CSV não foram apresentados como proteção de planilha.

- **Revogação das credenciais expostas (F4).** Foram removidas do código, mas este pacote não fornece acesso administrativo aos serviços, ao histórico ou aos ambientes implantados. É necessário rotacionar DB_PASS e SMTP_API_KEY fora desta entrega.

- **Acesso global das integrações CLI sem sessão.** As assinaturas públicas não recebem identidade e os relatórios internos precisam continuar funcionando. O contexto CLI sem uid e papel é tratado como confiável; CLI com sessão de cliente é filtrado. Em web, ausência de sessão é negada também na biblioteca.

- **Rótulos de status, prioridade, regras de SLA e comportamento para status não mapeado.** Os rótulos e os limites são comportamentos existentes, sem evidência de regra alternativa. Mantidos inclusive >30 minutos, prioridade 4 e null; não se fez refatoração cosmética da árvore de condições.

- **Stack mysqli, funções existentes, rotas, HTML, pesquisa com curingas e retorno de arrays.** São contratos do manifesto ou comportamento legado útil. Nenhuma função existente foi removida, renomeada ou teve assinatura alterada; tecnicoNome também permanece. Strings numéricas de chamados foram normalizadas para preservar os tipos retornados pelo mysqli::query original, e nulls foram mantidos.

- **Paginação, mudança da busca para prefixo/full-text, índices adicionais e reestruturação em framework.** Paginar alteraria o conjunto de resultados; busca por prefixo alteraria a semântica. Não há medição que justifique novos índices com custo de escrita, e reescrever a arquitetura ultrapassaria o escopo. LEFT JOIN e agregação resolvem os custos demonstrados sem essas alterações.

- **Constantes DB_HOST, DB_NAME, DB_USER, EXPORT_DIR, timezone e arquivos SQL.** Mantidos nomes, padrões não secretos e estrutura existentes. EXPORT_DIR tornou-se desnecessária para o download, mas não foi retirada por poder ser lida por scripts internos. A correção não depende de um arquivo exportado persistente, que não integra o contrato.


## Implantação e pendências operacionais

Configurar `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` no ambiente de execução; definir `SMTP_API_KEY` se a integração de e-mail usar a constante. O usuário do banco deve ter os privilégios necessários, sem depender da senha removida. A autenticação normal foi testada com credenciais de banco locais fornecidas no ambiente. Na ausência de credenciais válidas, o ponto de entrada responde 503.

Revogar/rotacionar as duas credenciais anteriormente expostas; o código novo não revoga o que já foi distribuído. Programar a migração MD5 descrita no achado correspondente, incluindo alteração da coluna, verificação transitória, rehash e retirada do caminho legado. Acordar com os consumidores a política de fórmulas em CSV antes de alterar os valores exportados. Em instalações com proxy TLS, configurar o indicador HTTPS usado pelo PHP para assegurar o atributo Secure.

Não foram alterados serviços externos, aplicadas migrações ao banco existente, enviados e-mails ou realizados commits/publicações. A entrega consiste nos arquivos modificados em `code/`, neste relatório e em `achados.json`.
