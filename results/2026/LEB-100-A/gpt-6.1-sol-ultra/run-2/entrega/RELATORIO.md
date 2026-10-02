# Relatório técnico — LEB-100-A, versão 1.1

## Resumo

O painel original viola a visibilidade de clientes e contém injeção SQL, XSS refletido, credenciais embutidas e autenticação baseada em MD5. Também há falhas na sessão, no cálculo de SLA e na exportação, além de consultas N+1. Identifiquei 12 achados e apliquei as correções no código, mantendo PHP/mysqli e a superfície declarada. A retirada de segredos não equivale à sua revogação; hashes de contas existentes migram gradualmente e a coluna de senha precisa ser ampliada antes da publicação.

Todos os locais abaixo usam a **numeração original** dos arquivos recebidos, não as linhas do código após edição. A severidade considera o painel acessível por clientes autenticados; a confiança mede se o mecanismo é real. Os achados foram priorizados por exposição de dados, segurança da autenticação, disponibilidade e compatibilidade.

## Achados

### F1 — Clientes acessam chamados de outros clientes

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** listarChamados consulta todos os chamados; verChamado filtra apenas id; exportarCsv (linha 132) exporta todos. index.php usa essas funções sem comparar usuario_id com a sessão, embora carregue uid e papel. mediaResposta também agrega registros de terceiros. Ana consegue consultar o chamado 103 de Bruno e exportar seus dados.

**Impacto.** Exposição de títulos, descrições, responsáveis, datas e dados operacionais de outros clientes; descumprimento da regra de visibilidade do manifesto.

**Correção e limites.** Centralizei o escopo por sessão em escopoChamados e apliquei usuario_id parametrizado à listagem, detalhe, CSV e indicador. Técnicos continuam com acesso global; detalhe fora do escopo retorna null, como um chamado inexistente. Sessões inválidas não recebem dados. CLI sem identidade conserva acesso global para relatórios internos.

### F2 — Busca permite injeção SQL

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** O conteúdo de busca é concatenado entre aspas em titulo LIKE. O termo ' OR 1=1 -- encerra a string e altera o predicado SQL; no original retornou os cinco chamados. UNION ou subconsultas também podem alcançar dados que a conta do banco tenha autorização para ler.

**Impacto.** Leitura indevida de dados do banco, alteração do filtro e possibilidade de consultas custosas. O alcance depende dos privilégios da conta mysqli.

**Correção e limites.** Passei a busca como parâmetro de statement mysqli preparado. As partes estruturais do SQL são internas. Preservei a busca por substring e os curingas LIKE % e _ já aceitos.

### F3 — Busca é refletida como HTML executável

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** A busca é interpolada sem escape no atributo value e no parágrafo de resultados. Um termo contendo aspas e uma tag com evento, como "><svg/onload=alert(1)>, fecha o atributo e introduz marcação executável. O mesmo termo também aparece cru no contexto de texto.

**Impacto.** Execução de JavaScript na origem do painel ao abrir um link de busca preparado por um atacante; ações e leitura de informações disponíveis à vítima.

**Correção e limites.** Apliquei htmlspecialchars com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 nos dois pontos e explicitei os mesmos argumentos nas saídas de título, descrição e técnico que já eram escapadas. A estrutura declarada da tabela permanece.

### F4 — Credenciais de produção estão embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** DB_PASS possui fallback literal identificado pelo próprio comentário como senha de produção; SMTP_API_KEY é definida por literal. Uma cópia do fonte ou de um backup revela os dois valores. Remover apenas o fallback do banco deixaria a chave SMTP exposta.

**Impacto.** Possível acesso ao banco e ao serviço SMTP por quem obtiver o código, condicionado à validade das credenciais e ao alcance de rede.

**Correção e limites.** Removi os valores embutidos e passei ambos os segredos a getenv. Mantive os nomes das constantes, os demais defaults de configuração e o suporte a DB_PASS explicitamente vazio. Não reproduzi os valores no relatório. Provisionar o ambiente e revogar/rotacionar os segredos antigos ainda são ações operacionais necessárias.

### F5 — Senhas usam MD5 sem salt e coluna limitada a 32 caracteres

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** autenticar calcula md5(senha) e compara diretamente ao banco. O esquema code/schema.sql:7 só comporta CHAR(32), e code/seed.sql:5–8 também gera MD5. Após obter a tabela de usuários, um atacante pode testar candidatos rapidamente e reconhecer senhas iguais entre contas.

**Impacto.** Descoberta offline de senhas e comprometimento de contas quando os hashes vazam; o esquema original impede armazenar um hash moderno completo.

**Correção e limites.** Ampliei senha para VARCHAR(255), substituí o seed por password_hash com salt próprio por conta e uso password_verify. Hashes MD5 existentes são reconhecidos e verificados com hash_equals; após login válido são substituídos por Argon2id quando disponível, ou PASSWORD_DEFAULT. A atualização condiciona id e hash anterior. O retorno continua apenas id/nome/papel. A migração da coluna deve preceder a publicação do PHP.

### F6 — Login não troca o identificador de sessão

**Local original:** `code/index.php:15–27`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 95/100. **Estado:** corrigido no código entregue.

**Mecanismo.** session_start abre a sessão e o login grava uid/papel sem regenerar seu ID. Os testes HTTP confirmaram que o cookie anônimo permanecia idêntico após autenticar. Se um atacante conseguir induzir a vítima a usar uma sessão cujo ID conhece, esse mesmo ID passa a autorizar consultas.

**Impacto.** Sequestro da sessão autenticada por fixação, dependendo da possibilidade de compartilhar ou fixar o identificador antes do login.

**Correção e limites.** Regenero o ID com session_regenerate_id(true) antes de gravar a identidade e trato falha na regeneração. Ativei use_strict_mode, HttpOnly, SameSite=Lax e Secure quando a conexão é identificada como HTTPS pelo servidor.

### F7 — Exportações compartilham um arquivo e corrompem downloads

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** Cada exportação abre EXPORT_DIR/chamados.csv em modo w, truncando o mesmo arquivo, e depois readfile lê o caminho novamente. Duas requisições podem sobrescrever os dados uma da outra entre escrita e leitura. Sem o diretório fixo, fopen falha e a função retorna sem CSV. Se a consulta falhar após abrir o arquivo, o handle também não é fechado nesse caminho.

**Impacto.** Downloads vazios, incompletos ou com linhas de outra execução; dependência indevida do diretório de instalação. No ensaio original concorrente, 9 de 10 downloads apresentaram contaminação ou perda de linhas.

**Correção e limites.** A exportação usa tmpfile exclusivo por chamada, escreve antes de enviar os headers, rebobina e transmite o mesmo handle. finally fecha o stream e libera o resultado mesmo em falhas. Eliminada a dependência de EXPORT_DIR e preservados nome do download, colunas e ordem crescente de id.

### F8 — Média sem respostas provoca divisão por zero

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** A consulta elimina minutos_resposta NULL; quando não há chamados respondidos, o laço deixa soma e qtd em zero e executa 0/0. Em PHP 8.4 isso lança DivisionByZeroError. index.php:74 chama a função antes de renderizar a listagem.

**Impacto.** Indisponibilidade da listagem em banco vazio ou com todos os chamados sem resposta. Após aplicar visibilidade, esse caso também é comum para um cliente como Bruno no seed.

**Correção e limites.** Calculo SUM e COUNT no banco dentro do escopo e retorno 0.0 quando a contagem é zero. Faço a divisão em PHP para manter a precisão float anterior, evitando arredondamento de AVG sobre inteiros no banco. A transferência deixa de crescer com o número de respostas.

### F9 — CSV permite interpretação de títulos e nomes como fórmulas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Estado:** corrigido no código entregue.

**Mecanismo.** fputcsv recebe titulo e nome do técnico diretamente. Aspas de CSV delimitam campos, mas não neutralizam seu significado em uma planilha: células começando por =, +, - ou @, ou certos controles, podem ser tratadas como fórmulas. Não há rota de criação neste pacote; o risco pressupõe que esses campos recebam dados controlados por usuários ou integrações.

**Impacto.** Interpretação de conteúdo como fórmula ao abrir o relatório em uma planilha; efeitos adicionais dependem do aplicativo e suas permissões.

**Correção e limites.** Adicionei textoCsvSeguro para prefixar apóstrofo em células textuais com esses prefixos, inclusive após espaços iniciais, ou iniciadas por tabulação/quebra de linha. O placeholder literal - permanece igual. Títulos e nomes comuns não mudam; textos potencialmente ativos ganham um prefixo deliberado de segurança.

### F10 — Listagem e CSV executam uma consulta de técnico por chamado

**Local original:** `code/lib.php:64–89`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** tecnicoNome consulta usuarios para cada tecnico_id não nulo. listarChamados chama a função dentro do laço em linha 89, e exportarCsv faz o mesmo em linha 137. No seed, são uma consulta de chamados e quatro consultas de técnicos, mesmo quando o responsável se repete.

**Impacto.** Quantidade de viagens ao banco e latência crescem com o número de chamados atribuídos, tanto na listagem quanto no download.

**Correção e limites.** Substituí as chamadas no laço por LEFT JOIN usuarios com COALESCE(t.nome, '-') AS tecnico_nome. Cada operação faz um SELECT. Chamados sem técnico continuam presentes e a chave tecnico_nome permanece. Mantive tecnicoNome para eventuais chamadas externas existentes.

### F11 — Parâmetros HTTP em formato de array causam erros de tipo

**Local original:** `code/index.php:20–22`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** PHP interpreta login[]=x e busca[]=x como arrays. O código passa esses valores diretamente a autenticar(string) em linha 22 e listarChamados(string) em linhas 72–73, causando TypeError; senha[]=x tem o mesmo efeito. ver[]=x também é convertido indevidamente em um ID pelo cast.

**Impacto.** Respostas de erro em requisições malformadas e possível exposição de detalhes se display_errors estiver habilitado; interpretação inadequada de um parâmetro de detalhe.

**Correção e limites.** Valido os tipos dos parâmetros públicos GET busca/ver/export e POST login/senha antes de usá-los. Arrays recebem HTTP 400 com mensagem simples. Mantive o comportamento das entradas escalares válidas e os nomes dos parâmetros.

### F12 — Cabeçalho bruto do CSV difere do texto exato do manifesto

**Local original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** corrigido no código entregue.

**Mecanismo.** fputcsv aplica aspas a campos com espaço e produz ID,Titulo,Status,Tecnico,"Aberto em". O manifesto exige literalmente ID,Titulo,Status,Tecnico,Aberto em. As duas formas são equivalentes para um parser CSV, mas diferentes para consumidores que verificam a primeira linha como texto.

**Impacto.** Incompatibilidade com consumidores que dependem do cabeçalho bruto exato declarado.

**Correção e limites.** Escrevo o cabeçalho literal exigido, seguido de LF. As linhas de dados seguem com fputcsv, separador vírgula, aspas duplas e escape vazio explícito para representar aspas pelo padrão de duplicação e preservar barras invertidas.

## Validação

Executei 56 grupos de verificações reais com PHP 8.4.26, mysqli/mysqlnd e um banco MariaDB 11.8.6 temporário isolado, sem alterar o banco já existente no ambiente. Todos passaram. O SQL continua direcionado à stack MySQL do pacote; não foi testado neste ambiente contra um servidor MySQL 8 propriamente dito.

- Os três arquivos PHP passaram em `php -l`.
- 18/18 grupos CLI passaram: sete assinaturas e valores padrão, rótulos de status, 25 combinações de prioridade/SLA, autenticação, contratos de retorno/ordenação, busca normal e curingas, SQL injection, visibilidade, média e migração MD5.
- 14/14 grupos HTTP passaram: listagens por perfil, tabela e colunas, detalhe próprio/terceiro, CSV de clientes/técnicos, busca com XSS e injeção, termo normal e médias por escopo. O cookie de sessão mudou após login.
- 10/10 exportações concorrentes passaram em cinco pares de processos, com 1.500 registros próprios por processo e transações isoladas: nenhuma contaminação e nenhuma linha perdida. O original falhou em 9/10 downloads no mesmo ensaio.
- 6/6 grupos adicionais passaram: cabeçalho CSV literal; roundtrip de cinco textos com aspas, barra invertida, vírgula, quebra de linha e UTF-8; dez casos de fórmulas/placeholders; quatro logins do seed; migração de senha MD5 longa e contendo NUL em Argon2id; três sessões inválidas negadas.
- 7/7 grupos HTTP adicionais passaram: HttpOnly/SameSite, cinco parâmetros enviados como arrays retornando 400 e exportação anônima exigindo login.
- 1/1 comparação estrita dos resultados completos de listagem e detalhe passou: valores, tipos escalares e NULL coincidem com o original no acesso global. Após a última alteração do seed, os quatro hashes e logins foram conferidos novamente.

A contagem medida de SELECTs na listagem e CSV caiu de cinco para um no seed. O valor global de SLA permaneceu exatamente `25.666666666666668` como float, e o caso sem respostas retorna `0.0`. Não houve warnings, erros fatais ou depreciações no log HTTP final. As reproduções antes/depois e os scripts de teste ficaram em `/tmp/leb-tests/`; não constituem novos arquivos do sistema nem dependências da entrega.

## Publicação e requisitos operacionais

Para um banco existente, executar **antes** de publicar o PHP: `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Essa orientação também está no início de `code/schema.sql`. Não executar o seed sobre dados de produção: ele continua sendo apenas dado de teste. O login migra hashes MD5 sem exigir troca imediata das senhas conhecidas; a redefinição das contas inativas completa a retirada do legado.

Provisionar `DB_PASS` e, se utilizada pela instalação, `SMTP_API_KEY` no ambiente. DB_HOST, DB_NAME e DB_USER continuam configuráveis como antes. Revogar/rotacionar as credenciais expostas; a remoção do fonte não remove cópias históricas. Na ausência de DB_PASS, não existe mais a senha de produção de fallback. O ambiente deve permitir temporários PHP, em vez de exigir o antigo diretório de exportação. Em instalações com proxy TLS, a configuração do servidor deve informar HTTPS corretamente para o atributo Secure do cookie.

## Decisões

**Stack PHP/mysqli, nomes e caminhos, assinaturas públicas, rotas e estrutura HTML declarada.** São contratos externos. Mantive os cinco arquivos nos mesmos caminhos, as sete assinaturas e retornos públicos, a tabela tabela-chamados, a ordem ID/Titulo/Status/Prioridade/Tecnico, links de detalhe e as rotas busca/ver/export. Normalizei os campos inteiros dos chamados para strings, preservando o comportamento padrão do query textual anterior; NULL permanece NULL e autenticar mantém seu id nativo.

**formatarStatus e rotuloPrioridade, inclusive comportamento fora dos valores documentados.** Não há evidência de regra diferente. Os rótulos 1/2/3, o limiar minutos > 30, o caso sem resposta e os textos existentes foram preservados. Não inventei validação de status nem uma nova matriz de SLA.

**Busca por substring, curingas LIKE e retorno completo das listagens/CSV.** Escapar %/_ alteraria o comportamento da busca; paginação, limites ou FULLTEXT mudariam resultados e exigiriam contrato adicional. A busca com %termo% ainda pode realizar varredura em volume grande; não há evidência de volume que justifique mudar sua semântica.

**Acesso global de relatórios CLI sem contexto de identidade.** O manifesto informa exportação noturna e relatórios internos que recebem somente mysqli. Exigir nova assinatura ou login web dessas rotinas quebraria seus consumidores. A exceção é exclusiva de PHP_SAPI cli sem uid/papel; no fluxo web e em CLI com identidade, os filtros e a negação de sessões inválidas se aplicam.

**Leitura transitória dos hashes MD5 de usuários existentes.** Não é possível converter MD5 em password_hash sem conhecer a senha. Preservei o login e a migração no primeiro sucesso; contas que nunca acessarem precisam de uma futura ação de redefinição. Se Argon2id não estiver disponível, senhas legadas maiores que 72 bytes ou com NUL permanecem MD5 para evitar truncamento/rejeição pelo bcrypt. No runtime testado, Argon2id está disponível e esses casos migraram.

**Esquema e dados de produção fora deste pacote; revogação de credenciais externas.** Não foram fornecidos acesso nem autorização operacional para alterar produção ou os provedores. A correção entregue inclui o esquema adequado e orientação de migração; não afirma que banco ou segredos em produção já foram alterados. Os segredos expostos devem ser rotacionados, mesmo após sua remoção do fonte.

**SMTP_API_KEY e EXPORT_DIR como constantes legadas.** Mantive seus nomes para evitar remoção desnecessária de configuração. SMTP passa a receber o segredo pelo ambiente; não há envio de e-mail no código fornecido. O download não usa mais o arquivo compartilhado, cujo caminho físico não integra o manifesto.

**Campos CSV perigosos como texto bruto idêntico ao banco.** Nesta entrega esses campos receberam uma mudança deliberada: um apóstrofo inicial neutraliza fórmulas. Cabeçalho, cinco colunas, linhas por chamado, status e ordem permanecem; dados comuns, inclusive o técnico -, são idênticos. Consumidores que precisam do texto original de uma célula ativa precisam considerar esse prefixo. A transformação foi limitada ao CSV, sem mudar banco ou HTML.

**Arquitetura MVC, ORM, dependências novas, logout e fluxos de alteração de chamados.** Uma reescrita ou funcionalidades adicionais excederiam o objetivo. Usei apenas recursos da stack existente, auxiliares pequenos para consulta/escopo e correções localizadas. Os testes e bancos temporários ficaram fora de code/.
