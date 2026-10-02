# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas demonstráveis de isolamento entre clientes, injeção SQL capaz de ler hashes de senha e XSS refletido. Autenticação, sessões, geração de CSV e tratamento de casos vazios também precisavam de correções. Foram registrados **13 achados: 12 corrigidos no código e um apenas reportado (F13)**. A correção é incremental: PHP e mysqli, funções públicas, rotas, HTML e formatos do manifesto foram mantidos.

As cinco unidades de `code/` foram alteradas no lugar; `manifest.md` e `TAREFA.md` não foram modificados. As localizações abaixo usam **a numeração original recebida**, também utilizada em `achados.json`. A ordem dos achados indica a prioridade de tratamento. “Corrigido” descreve o código entregue; não significa que este pacote já foi implantado ou que credenciais de serviços externos foram rotacionadas.

## Achados

### F1 — Clientes acessam chamados de outros clientes em todas as consultas

**Local original:** `code/lib.php:78–116`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados seleciona todos os chamados; verChamado filtra somente por id; mediaResposta agrega a tabela inteira. index.php lê uid e papel, mas não os usa para autorizar essas consultas. A rota export=csv também chama uma consulta global em exportarCsv (linhas originais 132–144). Assim Ana recebe os chamados 103 e 104 de Bruno e pode obter o detalhe 103 digitando ver=103.

**Impacto.** Exposição de títulos, descrições, técnicos e datas de outros clientes, inclusive por download; o indicador de SLA também incorpora dados fora do conjunto visível.

**Decisão e implementação.** Centralizei o escopo em escopoChamados: cliente recebe WHERE c.usuario_id = ?; técnico conserva acesso global; contexto de sessão inválido não recebe registros. Listagem, detalhe, média e CSV usam esse escopo. Chamado alheio devolve null e a mesma mensagem de chamado inexistente. O index continua exigindo login antes das rotas; chamadas internas sem sessão conservam o comportamento global anterior.

**Evidência.** No código original, Ana recebeu cinco chamados na listagem e no CSV e leu o detalhe 103. No entregue, Ana recebe 101/102/105; Bruno, 103/104; Carla e Diego, todos. Verificação nas funções e por HTTP.

### F2 — Busca concatenada permite injeção SQL e leitura de credenciais

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O termo de busca é interpolado entre aspas em titulo LIKE. ZZZ' OR 1=1 -- encerra o literal e substitui a condição. Um UNION com nove colunas compatíveis também seleciona usuarios.senha como titulo e usuarios.login como descricao, expondo dados de outra tabela através da listagem.

**Impacto.** Leitura de registros não relacionados à busca, inclusive hashes de senha e logins, e possibilidade de consultas custosas dentro dos privilégios da conexão. Não se pressupõe execução de múltiplas instruções SQL.

**Decisão e implementação.** Substituí a concatenação do termo por LIKE ? em prepared statement mysqli. Os filtros de visibilidade e o id do detalhe também são parâmetros. Mantive os curingas % e _ e a ordenação original da busca.

**Evidência.** OR 1=1 retornou os cinco chamados no original; UNION retornou quatro usuários e expôs um hash no campo título, sem reproduzir os valores no relatório. Os payloads usados contra a versão corrigida passaram a ser texto de busca e não produziram registros.

### F3 — Busca refletida sem escape permite XSS em texto e atributo HTML

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** busca entra diretamente no atributo value entre aspas duplas e no parágrafo Resultados para. Um termo contendo `<script>alert(1)</script>` não precisa retornar chamados: o parágrafo já o transforma em uma tag executável. Aspas duplas também permitem inserir atributos no input.

**Impacto.** Execução de JavaScript na origem do painel ao abrir uma URL preparada, com acesso às páginas e ações disponíveis na sessão da vítima.

**Decisão e implementação.** Escapei a busca nos dois pontos com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8. Tornei explícitos os mesmos parâmetros para título, descrição e nome do técnico e mantive os IDs numéricos nos links.

**Evidência.** XSS refletido reproduzido por HTTP no original. Na versão entregue, testes com script, img/onerror e aspas/onfocus mantiveram o valor textual do input sem criar elementos ou atributos executáveis.

### F4 — Credenciais de banco e SMTP estão embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** DB_PASS usa uma senha identificada como de produção quando a variável de ambiente falta ou é vazia; SMTP_API_KEY contém uma chave literal incondicional. Cópias do pacote e acesso ao código expõem os dois valores, mesmo sem acesso ao processo em execução.

**Impacto.** Se ainda válidos, os valores possibilitam acesso ao banco ou ao serviço de e-mail. A validade operacional das credenciais não foi presumida nem testada.

**Decisão e implementação.** Removi os dois segredos do código. DB_PASS precisa existir no ambiente, admitindo valor vazio explícito; ausência gera falha controlada. SMTP_API_KEY vem do ambiente, ficando vazia quando não configurada, pois não existe envio de e-mail nos arquivos fornecidos. Preservei os nomes das constantes. Rotação dos valores anteriormente expostos continua sendo uma ação operacional necessária, não executada neste pacote.

**Evidência.** Inspeção dos literais na configuração original. Teste HTTP sem DB_PASS recebeu 500 com mensagem genérica, sem credenciais ou rastreamento de pilha.

### F5 — Login conserva o identificador da sessão anônima

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** session_start aceita a sessão e o login apenas preenche uid e papel. Não há regeneração do identificador na transição para autenticado. Se um atacante fizer a vítima usar uma sessão cujo identificador conhece, esse identificador continuará válido após a autenticação. O código também não define proteção dos cookies.

**Impacto.** Possível sequestro de sessão por fixação, condicionado à capacidade de impor ou conhecer o identificador anterior ao login; cookies sem HttpOnly ampliam o impacto de XSS.

**Decisão e implementação.** Ativei modo estrito, uso exclusivo de cookies, HttpOnly e SameSite=Lax, com Secure quando o servidor informa HTTPS. Depois de validar a senha, session_regenerate_id(true) ocorre antes de gravar uid/papel. Respostas usam no-store e o lock de sessão é liberado antes das consultas e da exportação.

**Evidência.** No original, o cookie de sessão antes e depois do login era o mesmo. Para os quatro usuários no código entregue, o identificador mudou e o antigo voltou a mostrar o formulário de login. HttpOnly/SameSite foram verificados por HTTP; Secure foi verificado com HTTPS sinalizado pelo servidor de teste, sem alegar teste de transporte TLS.

### F6 — Senhas usam MD5 sem salt e não podem armazenar hashes modernos

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** autenticar compara MD5(senha) com usuarios.senha. schema.sql:7 limita a coluna a CHAR(32), e seed.sql:5–8 gera MD5 para todos os usuários. O hash é rápido e determinístico, permitindo testar candidatos offline e identificar senhas iguais após uma leitura do banco; F2 demonstra uma via concreta de leitura.

**Impacto.** Recuperação de senhas por dicionário ou força bruta offline e comprometimento das contas; não há fator de custo configurável no MD5.

**Decisão e implementação.** Passei a verificar hashes modernos com password_verify e a reconhecer MD5 legado com comparação constante. Após senha válida, gero password_hash, preferindo Argon2id disponível na stack e usando PASSWORD_DEFAULT como fallback, com UPDATE condicionado ao hash anterior para evitar sobrescrita concorrente. A coluna passou a VARCHAR(255), com instrução de migração para bases existentes; os seeds contêm hashes modernos e conservam as credenciais do manifesto.

**Evidência.** Os quatro logins de exemplo continuam funcionando. Senha errada não migrou MD5; senha correta migrou e voltou a autenticar. Testei também uma senha legada de 100 bytes sem aceitação da versão truncada. A migração ALTER TABLE foi aplicada a uma cópia do banco original e o login antigo foi atualizado com sucesso.

### F7 — Exportações compartilham arquivo truncável e dependem de diretório fixo

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Todas as execuções abrem EXPORT_DIR/chamados.csv com modo w, fecham e só depois chamam readfile pelo mesmo nome. Uma segunda execução pode truncar esse arquivo entre fclose e readfile da primeira, fazendo a primeira ler conteúdo vazio ou parcial. Se os dados mudarem entre consultas, pode ler o arquivo de outra execução. Se o diretório não existir ou não for gravável, a função simplesmente retorna sem CSV. Uma cópia persistente fica sob /var/www/painel/tmp; acesso HTTP direto depende da configuração do servidor, não fornecida.

**Impacto.** Downloads vazios, parciais ou substituídos por outra execução, falha silenciosa em instalações sem o diretório e retenção desnecessária de dados sensíveis em disco.

**Decisão e implementação.** Usei php://temp exclusivo da chamada para gerar o CSV antes de enviar cabeçalhos e conteúdo. Não há mais nome compartilhado nem dependência de EXPORT_DIR. Resultados e fluxo são liberados em finally; falhas de abertura, escrita ou leitura geram exceção controlada. Mantive nome do download, cabeçalhos e ordenação.

**Evidência.** A versão entregue exportou sem criar o diretório de produção; oito exportações em processos PHP paralelos, com clientes e técnicos diferentes, produziram somente os respectivos IDs e nenhum erro. O interleaving do arquivo original foi identificado pelo código; não foi alegada reprodução determinística da corrida original.

### F8 — Média divide por zero quando não há primeira resposta

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** A consulta descarta tempos NULL e qtd começa em zero. Em banco vazio ou com todos os minutos_resposta nulos, o loop não incrementa qtd e a expressão soma/qtd divide por zero. Isso derruba a listagem porque index.php chama mediaResposta antes de produzir o HTML.

**Impacto.** Painel indisponível justamente quando ainda não há chamados respondidos; também ocorreria para um cliente sem respostas após aplicar a visibilidade correta.

**Decisão e implementação.** Calculei AVG no banco, respeitando o mesmo escopo de visibilidade, e usei COALESCE para devolver 0.0 sem amostras. A coerção numérica para double evita o arredondamento decimal de AVG(INT) e conserva a média anterior. Apenas o agregado atravessa a conexão.

**Evidência.** DivisionByZeroError reproduzido no original ao tornar todos os tempos NULL. No entregue, banco vazio, todos os tempos NULL e os chamados de Bruno retornaram 0.0; a média global dos seeds permaneceu 77/3.

### F9 — Arrays em parâmetros HTTP causam erros de tipo

**Local original:** `code/index.php:20–22`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** PHP permite login[]=ana e senha[]=x em POST, e busca[]=x em GET. O código passa esses arrays a autenticar ou listarChamados, cujos parâmetros são string, causando TypeError. Na linha original 53, ver[]=101 também é convertido de array para int em vez de representar um id textual válido.

**Impacto.** Requisições malformadas geram erro 500 ou coerção inesperada, em vez de rejeição clara; tornam o comportamento dependente de detalhes do parser e do tratamento de erros do servidor.

**Decisão e implementação.** Validei que busca/ver/export no GET e login/senha no POST são strings antes de usar as funções tipadas. Arrays recebem HTTP 400 e mensagem curta; os nomes de parâmetros e a coerção histórica de ver textual foram preservados.

**Evidência.** busca[]=x gerou 500 no original. Na versão entregue, três parâmetros GET e os dois POST em formato de array receberam 400, sem TypeError exposto.

### F10 — Falhas mysqli ficam sem tratamento consistente na entrada web

**Local original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O construtor mysqli pode lançar mysqli_sql_exception no modo estrito antes de chegar à verificação connect_errno. Consultas e prepared statements da biblioteca tampouco têm um tratamento no ponto de entrada; em modo não estrito vários caminhos dereferenciam false. set_charset não é verificado. Assim uma credencial inválida ou tabela ausente interrompe a resposta; detalhes podem aparecer se display_errors estiver habilitado.

**Impacto.** Falhas de conexão ou consulta resultam em resposta incompleta e diagnóstico dependente da configuração global, com exposição potencial de detalhes internos.

**Decisão e implementação.** Fixei o modo de erros mysqli como estrito na entrada web, verifiquei charset e início da sessão e protegi configuração, conexão e processamento com captura de exceções/erros. Falhas internas recebem 500 genérico; o log registra classe e código, sem SQL ou entradas. A biblioteca mantém mysqli e propaga falhas para o consumidor interno tratar.

**Evidência.** Testes HTTP com DB_PASS ausente, credencial inválida e tabela chamados ausente, tanto na listagem quanto no CSV, receberam somente a mensagem genérica com 500. A falha de consulta ocorreu antes de enviar cabeçalhos de download.

### F11 — Nome de técnico provoca consultas N+1 na listagem e no CSV

**Local original:** `code/lib.php:87–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Cada chamado com tecnico_id chama tecnicoNome, que consulta usuarios individualmente na linha original 69. O mesmo padrão é repetido em exportarCsv:136–137. Chamados do mesmo técnico não reaproveitam o resultado. Os seeds exigem uma consulta principal mais quatro buscas de nome em cada operação.

**Impacto.** Crescimento linear de consultas e viagens ao banco conforme o número de chamados, aumentando latência e carga em listagens e exportações grandes.

**Decisão e implementação.** Compartilhei a consulta da lista e do CSV com LEFT JOIN usuarios e COALESCE(t.nome, '-'). A chave tecnico_nome, o fallback do técnico ausente, todas as colunas de chamados e as ordenações foram mantidos. tecnicoNome continua disponível para consumidores existentes, embora essas duas operações não o chamem mais.

**Evidência.** A contagem de Com_stmt_execute aumentou em exatamente um para listarChamados e um para exportarCsv. Resultado global comparado com o original, inclusive nome e ausência de técnico.

### F12 — Escape implícito do CSV corrompe títulos com barra e aspas

**Local original:** `code/lib.php:138–144`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** fputcsv usa implicitamente barra invertida como escape. Quando o texto contém barra imediatamente antes de aspas, as aspas podem não ser duplicadas como requer um leitor CSV que trate a barra como dado. Um título contendo esse padrão e uma quebra de linha encerrou o campo antes do esperado no leitor sem escape proprietário.

**Impacto.** Alteração do título ao importar e perda da separação entre linhas e colunas; dados deixam de fazer round-trip entre exportação e consumidores CSV usuais.

**Decisão e implementação.** Especifiquei separador vírgula, delimitador aspas duplas e escape vazio em todos os fputcsv. Aspas internas são duplicadas; barras permanecem dados. Não acrescentei BOM, colunas nem novos formatos.

**Evidência.** Um título de teste com vírgula, barras antes de aspas, UTF-8 e quebra de linha voltou diferente e com duas colunas no original. Na versão entregue, voltou idêntico com cinco colunas. O CSV dos seeds permaneceu byte a byte igual ao original.

### F13 — CSV preserva valores que podem ser interpretados como fórmulas

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Corrigido:** não.

**Mecanismo.** Titulo e o nome do técnico vão diretamente para as células do CSV. fputcsv trata a sintaxe CSV, mas não torna um valor como =1+1 texto para um leitor de planilhas que avalie fórmulas. O esquema não restringe esses valores. O pacote fornecido não contém a rota de escrita dos títulos, portanto não se presume que um usuário externo consiga inserir esse conteúdo por esta interface.

**Impacto.** Se uma fonte capaz de gravar esses campos inserir fórmulas e o destinatário abrir o CSV em leitor que as avalie, a célula pode exibir cálculo ou link em vez do texto original. O efeito depende do leitor e de suas proteções; não se afirma execução automática de comandos do sistema.

**Decisão e implementação.** Não alterei os valores exportados: prefixar apóstrofo/tabulação muda o conteúdo recebido pelos consumidores e quebra o round-trip do título. Recomendo importar as colunas textuais como texto ou definir futuramente um modo separado de exportação para planilhas, com contrato próprio. Não foi acrescentada uma rota nova.

**Evidência.** Em transação de teste, um título =1+1 foi exportado exatamente assim. Não houve execução em software de planilha; a confiança considera essa condição e a ausência do caminho de escrita no pacote.

## Decisões — o que não alterei

- **Assinaturas mysqli, rotas busca/ver/export, retorno da autenticação e estrutura da tabela HTML.** São contrato explícito. Não substituí mysqli, não renomeei arquivos nem parâmetros, não removi tecnico_nome e não acrescentei paginação, campos ou colunas ao CSV.
- **Rótulos de status e toda a lógica de prioridade/SLA, incluindo fallback e limite de 30 minutos.** O manifesto exige os textos de status, e os demais rótulos foram caracterizados no original. Não há evidência de regra diferente que justifique alterar os textos ou refatorar a lógica aninhada nesta entrega.
- **Consultas globais da biblioteca quando não há contexto de sessão.** Relatórios e exportação noturna chamam as funções existentes sem parâmetro de usuário. O index.php continua obrigando login; com sessão de cliente o filtro é aplicado dentro da biblioteca. Outros pontos de entrada devem autenticar suas requisições antes de reutilizar a biblioteca.
- **MD5 de contas existentes antes do próximo login válido.** Não é possível gerar um hash forte a partir do MD5 sem conhecer a senha. A migração é gradual e conserva acesso. Se Argon2id não estiver disponível e a senha legada exceder 72 bytes, ela não é convertida para bcrypt truncado; permanece legada até um processo de troca/migração adequado.
- **Rotação de credenciais já expostas e alterações no banco real.** O pacote não fornece administração do serviço SMTP nem autorização operacional para modificar produção. Removi os literais; a operação deve provisionar novos valores, executar a migração do esquema e avaliar a remoção do CSV antigo. Os testes usaram somente uma instância e bancos isolados.
- **Valores textuais do CSV, inclusive valores potencialmente interpretáveis como fórmulas (F13).** Neutralizar fórmulas prefixando caracteres altera dados dos consumidores e o round-trip. Registrei o risco com suas condições; um formato específico para planilhas precisa de contrato próprio.
- **Wildcards % e _ na busca, ordenação por criado_em na lista e id no CSV.** São comportamentos existentes. Parametrização remove a injeção sem mudar o significado do filtro. Não acrescentei ordenação de desempate que pudesse modificar resultados de consumidores.
- **Arquitetura de entrada web em index.php, tabelas e índices existentes.** Os defeitos concretos foram corrigidos localmente. Uma separação completa de controlador/template, troca de camada de dados ou índice para busca por substring exigiria mais escopo e risco do que este pacote justifica. SELECT c.* preserva campos adicionais que consumidores já possam usar.
- **Constantes de configuração, credenciais de demonstração e caminhos dos cinco arquivos originais.** Preservei os nomes das constantes, os usuários/senhas publicados pelo manifesto e os mesmos arquivos. EXPORT_DIR permanece declarado para compatibilidade, mas não é mais usado pelo download; a persistência do CSV não faz parte do contrato.

## Compatibilidade e implantação

As sete funções do manifesto conservam nomes, parâmetros, tipos e retornos; `listarChamados` conserva a busca opcional vazia e a chave `tecnico_nome`. A normalização dos campos numéricos de chamados conserva os tipos textuais das consultas mysqli originais na configuração padrão, apesar de prepared statements usarem tipos nativos. A autenticação conserva somente `id`, `nome` e `papel`. Datas e demais campos não foram reformulados.

Os três status continuam exatamente `Aberto`, `Em atendimento` e `Resolvido`. A tabela continua `id="tabela-chamados"`, com ID, Titulo, Status, Prioridade e Tecnico nessa ordem e links `index.php?ver=<id>`. O CSV mantém as cinco colunas, rótulos, linhas por chamado visível, ordem crescente de id, nome `chamados.csv`, UTF-8 e os cabeçalhos de download. A serialização do cabeçalho original, incluindo aspas de `Aberto em`, foi preservada. Para técnicos e scripts internos sem sessão, o CSV dos seeds é byte a byte idêntico ao original. Para clientes, o conjunto de linhas agora cumpre a visibilidade exigida pelo manifesto.

Para um banco existente, executar **antes** de implantar o novo código:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

A alteração não apaga nem transforma os MD5 existentes. Não se deve executar novamente `schema.sql`/`seed.sql` sobre produção nem reimportar os dados de demonstração. A conta usada pela aplicação precisa poder atualizar `usuarios.senha` para a migração após login. Configurar `DB_PASS` no ambiente do processo; configurar `SMTP_API_KEY` para consumidores que enviem e-mail. Os antigos valores devem ser rotacionados pelos responsáveis pelos serviços. Não há fallback secreto embutido.

Os MD5 restantes serão atualizados ao autenticar; contas que não retornarem continuam exigindo troca de senha ou outra política operacional de migração. Se TLS terminar em um proxy, o servidor deve informar HTTPS corretamente ao PHP para emitir o cookie Secure; o código não confia em cabeçalhos de proxy arbitrários. O download não mantém mais um arquivo compartilhado de relatórios, e a operação deve avaliar a remoção de eventuais cópias antigas em `EXPORT_DIR`.

## Verificação executada

Ambiente: **PHP 8.4.26**, extensão **mysqli/mysqlnd**, **MariaDB 11.8.6**, charset utf8mb4. Criei uma instância de banco separada em `/tmp/leb-100-a-test`, com porta 3307, e cópias de bancos para o original e a versão alterada. O serviço e os dados preexistentes do ambiente não foram usados nem modificados. MariaDB foi o servidor disponível; **não houve execução direta em MySQL 8**. O SQL utilizado permanece compatível com o esquema MySQL declarado, mas essa execução é uma limitação da validação.

- Sintaxe: `php -l code/config.php`, `php -l code/lib.php` e `php -l code/index.php`, sem erros.
- **60 verificações de funções/contrato passaram**: reflexão das sete assinaturas e busca opcional, seis casos de status e 30 combinações de prioridade/SLA, lista/detalhe global com campos e tipos originais, CSV byte a byte, quatro logins, senhas incorretas, migração MD5, senha longa, consultas de clientes/técnicos, contexto inválido, busca parametrizada, wildcards, zeros/NULL, CSV vazio e round-trip de texto complexo. Contadores do banco confirmaram uma consulta na listagem e uma no CSV.
- **78 verificações HTTP passaram**: quatro usuários, listagem e estrutura HTML, detalhes próprios/alheios, escopo e cabeçalhos CSV, rotas sem login, busca normal e SQLi, três payloads XSS, parâmetros em array, regeneração de sessão e invalidação do identificador anterior, cookies e no-store. Incluem comparações que reproduziram a sessão não regenerada, o acesso cruzado, o XSS e o erro de array no original. As 12 requisições simultaneamente submetidas ao servidor PHP de teste foram verificadas quanto ao conteúdo, sem alegar paralelismo interno daquele servidor de um único processo.
- **13 verificações de infraestrutura e paralelismo passaram**: ausência de configuração, credencial incorreta, consulta sem tabela na listagem/CSV, cookie Secure com HTTPS sinalizado e oito exportações em processos PHP realmente paralelos. Cada processo recebeu somente o conjunto esperado para sua sessão.
- Sobre cópia do banco original, a migração de `CHAR(32)` para `VARCHAR(255)` preservou os dados e permitiu autenticar e substituir um MD5 por hash moderno.
- No original, reproduzi SQLi por condição e por UNION, falta de visibilidade e divisão por zero. Um CSV original com barra/aspas/quebra de linha perdeu o título e a estrutura de cinco colunas. Na versão entregue, o round-trip passou. Para F13, constatei somente a passagem literal de `=1+1`; não executei uma planilha nem uma fórmula.

Os verificadores locais ficaram em `/tmp/leb-100-a-test` durante a execução e não foram adicionados a `code/`; não são dependências da aplicação. Não foi realizado ensaio de carga, implantação em produção, rotação externa ou alteração de infraestrutura existente.
