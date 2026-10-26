# Relatório técnico — LEB-100-A

## Resumo

Foram identificados 11 achados: nove corrigidos e dois mantidos abertos com justificativa (MD5 e interpretação de fórmulas por planilhas). As falhas de busca, autorização e saída HTML permitiam exposição de dados entre clientes e execução de conteúdo no navegador. A exportação também reutilizava um arquivo compartilhado, a média falhava sem respostas e havia consultas por registro.

Foram alterados `code/config.php`, `code/index.php` e `code/lib.php`, nos mesmos caminhos. `schema.sql` e `seed.sql` permanecem idênticos. As assinaturas públicas, mysqli, as rotas, os rótulos, as colunas HTML e o contrato de CSV foram preservados. O cabeçalho CSV é emitido literalmente como `ID,Titulo,Status,Tecnico,Aberto em`; as linhas continuam em ordem crescente de ID dentro do escopo autorizado.

As localizações abaixo usam **a numeração original recebida**, assim como `achados.json`. A ordem corresponde à prioridade de tratamento. A confiança indica a probabilidade de o problema ser real, não a probabilidade de exploração.

## Achados

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:78–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados concatena busca entre aspas no LIKE. Uma aspa fecha o literal e permite acrescentar OR ou UNION; o comentário SQL elimina o restante da expressão. A conexão da aplicação pode ler usuarios, portanto a falha alcança também hashes de senha.

**Impacto.** Um cliente autenticado consegue ler dados fora dos resultados da busca e extrair dados da tabela de usuários com as permissões do usuário do banco.

**Decisão e ação.** Substituí a concatenação por prepare/bind_param no LIKE. O filtro de visibilidade é aplicado separadamente. Mantive os curingas % e _ do LIKE para preservar o comportamento de busca.

**Evidência.** Na cópia original, uma busca inexistente retornou zero registros; uma UNION com nove colunas recuperou os hashes dos quatro usuários de teste. Na versão corrigida, o payload retorna zero registros e títulos com apóstrofo continuam pesquisáveis.

### F2 — Ausência de isolamento entre clientes nas leituras de chamados

**Local original:** `code/lib.php:78–151`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados, verChamado, mediaResposta e exportarCsv consultam chamados sem restringir usuario_id. index.php lê uid/papel nas linhas originais 38–39, mas não os usa para autorizar as consultas. Alterar ver permite consultar diretamente um chamado de outro cliente; listagem e CSV já expõem todos.

**Impacto.** Clientes conseguem ler títulos, descrições, atribuições e datas de outros clientes, inclusive por download. A média também inclui registros fora do escopo autorizado.

**Decisão e ação.** Centralizei o predicado de visibilidade na biblioteca e o apliquei às quatro leituras: cliente recebe somente seu usuario_id; técnico recebe todos; identidade incompleta ou papel inválido recebe nenhum. Detalhe não autorizado retorna null, com a mesma mensagem de chamado inexistente. Chamadas internas sem identidade de sessão conservam o escopo global legado; index.php continua exigindo login antes de qualquer rota.

**Evidência.** No original, Ana recebeu cinco chamados, o detalhe 103 de Bruno e cinco linhas no CSV. Agora Ana recebe 101/102/105 e Bruno 103/104; ambos os técnicos recebem os cinco. Também foram testados filtro textual, média, papel inválido e chamadas internas sem sessão.

### F3 — XSS refletido em dois contextos da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O parâmetro busca é concatenado sem escape no atributo value delimitado por aspas duplas e no texto do parágrafo de resultados. Aspas podem encerrar o atributo; tags inseridas no parâmetro tornam-se HTML executável.

**Impacto.** Um link de busca preparado por terceiro pode executar JavaScript na origem do painel quando aberto por um usuário autenticado, permitindo ler dados e realizar requisições com sua sessão.

**Decisão e ação.** Codifiquei busca com htmlspecialchars usando ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 nos dois pontos de saída. Tornei explícitos os mesmos parâmetros nas saídas textuais de título, descrição e técnico. A consulta recebe o texto original, sem escape HTML.

**Evidência.** O servidor com o código original refletiu literalmente o payload contendo fechamento de atributo e <script>. Na correção, as requisições HTTP retornam &quot; e &lt;script&gt;, sem a tag executável.

### F4 — Credenciais identificadas como produção embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** DB_PASS possui fallback literal descrito como senha de produção e SMTP_API_KEY é uma constante literal. Uma cópia do pacote ou acesso ao repositório revela esses valores, e a aplicação reutiliza o fallback quando a variável de senha não está configurada.

**Impacto.** Se os valores ainda forem válidos, quem obtiver o código pode tentar acessar o banco ou utilizar a integração SMTP. A validade externa dessas credenciais não foi testada.

**Decisão e ação.** Removi os dois valores do código: DB_PASS e SMTP_API_KEY vêm do ambiente, mantendo os nomes das constantes. DB_PASS distingue variável ausente de senha vazia explicitamente configurada. A conexão também trata mysqli_sql_exception com resposta genérica. A implantação deve fornecer as credenciais e revogar/rotacionar as antigas; remoção do fonte não revoga segredos já divulgados.

**Evidência.** Inspeção direta do original confirmou os dois literais. O sistema corrigido foi testado com credenciais locais fornecidas pelo ambiente, sem usar os valores entregues no pacote.

### F5 — Senhas armazenadas e verificadas com MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** não.

**Mecanismo.** autenticar calcula md5 da senha e compara diretamente com usuarios.senha. schema.sql:7 limita a coluna a CHAR(32), e seed.sql armazena MD5 das senhas. Não há salt individual nem custo adaptativo; a mesma senha produz o mesmo hash e permite tentativas offline rápidas.

**Impacto.** Uma cópia do banco, inclusive obtida por F1 no original, permite testar dicionários de senha sem limitação pelo servidor e pode resultar em tomada de contas.

**Decisão e ação.** Não alterado nesta entrega. Proponho ampliar a coluna para VARCHAR(255), usar password_hash/password_verify e manter verificação MD5 apenas na transição, com rehash após login válido ou redefinição de senha. Isso exige migração coordenada do banco já existente e política para contas que não voltam a entrar; trocar somente a função ou schema de instalação não migra produção.

**Evidência.** O código e os dados de teste confirmam hashes de 32 caracteres. Os quatro logins do manifesto permanecem funcionais. A correção de F1 bloqueia aquele vetor de extração, mas não torna o armazenamento MD5 seguro.

### F6 — Identificador de sessão mantido após autenticação

**Local original:** `code/index.php:15–25`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O login preenche uid e papel na sessão iniciada antes da autenticação sem renovar seu identificador. Um identificador válido previamente conhecido por um atacante continua identificando a sessão depois que a vítima entra. O código também depende dos padrões externos para proteção do cookie.

**Impacto.** Se o atacante conseguir fixar um identificador conhecido no navegador da vítima, pode reutilizá-lo após o login para acessar a conta. A ausência de flags explícitas enfraquece a proteção do cookie em instalações com padrões permissivos.

**Decisão e ação.** Adicionei session_regenerate_id(true) antes de gravar a identidade, modo estrito, somente cookies, HttpOnly e SameSite=Lax. Secure é ativado quando o servidor informa HTTPS, preservando execução HTTP local. Em terminação TLS por proxy, o servidor deve informar HTTPS corretamente.

**Evidência.** No servidor original, o mesmo PHPSESSID permaneceu após o login. Nos quatro usuários da versão corrigida, o identificador mudou; o identificador antigo voltou ao formulário de entrada. HttpOnly e SameSite=Lax foram conferidos nas respostas HTTP.

### F7 — Exportações compartilham arquivo previsível e persistente

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Toda chamada abre EXPORT_DIR/chamados.csv com modo w e só depois o relê com readfile. Duas requisições podem truncar ou sobrescrever o mesmo caminho entre gravação e leitura, produzindo conteúdo misturado ou de outra requisição. O arquivo persiste após a resposta em um diretório sob /var/www/painel; eventual acesso estático depende da configuração do servidor.

**Impacto.** Downloads podem ficar truncados ou entregar dados de outra exportação. Com escopos por cliente, a corrida pode furar o isolamento. Se o diretório estiver publicado, o arquivo persistente ainda pode ser obtido fora do fluxo autenticado.

**Decisão e ação.** Usei php://temp exclusivo por chamada, com limite de 2 MiB em memória e transbordo para temporário privado. A resposta é enviada pelo próprio stream, sem caminho estável; finally libera resultado e stream, e falhas de preparação/gravação são sinalizadas. A consulta do CSV usa resultado não bufferizado. EXPORT_DIR foi mantida como constante, mas não é mais usada pelo exportador.

**Evidência.** 48 exportações concorrentes, alternando Ana e Bruno em oito processos, retornaram somente os IDs autorizados. Também exportei 16.387 chamados em 3.888.876 bytes, ultrapassando o limite em memória. A corrida do original foi identificada pelo caminho compartilhado; não foi medida sua frequência. Arquivos históricos já existentes no servidor de produção precisam ser removidos pela implantação.

### F8 — Valores de CSV podem ser interpretados como fórmulas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Corrigido:** não.

**Mecanismo.** Título e nome de técnico são passados diretamente a fputcsv. O quoting CSV protege delimitadores, mas não muda o valor da célula: conteúdo como =1+1 permanece iniciando por = após a leitura do arquivo e pode ser interpretado como fórmula por uma planilha.

**Impacto.** Se alguém puder cadastrar conteúdo nesses campos e um operador abrir o download numa planilha que interprete fórmulas, pode induzir cálculo, links ou acesso externo conforme os recursos e configurações da planilha. Não há rota de edição desses campos no pacote; a exploração depende da origem dos dados e do consumidor.

**Decisão e ação.** Não alterado nesta entrega. Prefixar apóstrofo ou modificar células altera os valores entregues às integrações de CSV. É necessário definir política de consumo, como importação explícita de colunas como texto ou um modo separado para planilhas, antes de modificar o formato atual.

**Evidência.** Com título =1+1 inserido numa transação de teste, o CSV corrigido conserva esse valor. Não executei uma planilha nem afirmei execução de comandos no sistema operacional; a confiança de 95 considera a dependência do consumidor.

### F9 — Média divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** mediaResposta só incrementa qtd para registros com minutos_resposta não nulo, mas divide soma por qtd sem verificar se existe algum. Base vazia ou todos os tempos nulos deixam qtd em zero. Além disso, todos os tempos são transferidos ao PHP apenas para obter um agregado.

**Impacto.** No PHP 8, DivisionByZeroError interrompe a listagem. O mesmo caso ocorreria para Bruno após restringir corretamente o escopo, pois seus chamados de exemplo ainda não têm resposta. Em bases grandes, a implementação original também transfere e percorre dados desnecessários.

**Decisão e ação.** Substituí a acumulação por AVG no banco e converti resultado nulo para 0.0. Mantive a exclusão de tempos nulos e o escopo de visibilidade. A expressão usa um literal aproximado para preservar a precisão float da divisão anterior, evitando o arredondamento decimal de AVG sobre inteiros.

**Evidência.** O original lançou DivisionByZeroError com todos os tempos nulos. A versão corrigida retorna float zero tanto nesse caso quanto numa tabela vazia; para os tempos 12/40/25, conserva 77/3. O caso de Bruno também passa.

### F10 — Consultas adicionais por chamado para nome de técnico

**Local original:** `code/lib.php:85–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O laço de listarChamados chama tecnicoNome, que executa SELECT para cada tecnico_id não nulo, inclusive para IDs repetidos. exportarCsv repete o padrão nas linhas originais 136–137. São uma consulta principal mais uma por chamado atribuído.

**Impacto.** O número de viagens ao banco cresce com os chamados listados ou exportados, aumentando latência e carga mesmo quando poucos técnicos se repetem.

**Decisão e ação.** Usei LEFT JOIN com usuarios e COALESCE para resolver tecnico_nome na própria consulta de listagem e exportação. Mantive a chave tecnico_nome, o marcador - para ausência de técnico e a função tecnicoNome com sua assinatura existente.

**Evidência.** O contador Com_select registrou cinco SELECTs para a listagem original dos cinco chamados, dos quais quatro têm técnico. A versão corrigida usa um SELECT por listagem e um por exportação, com os mesmos nomes e marcador de ausência.

### F11 — Parâmetros em formato de array causam erro ou coerção indevida

**Local original:** `code/index.php:21–73`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** PHP permite parâmetros como login[]=ana e busca[]=x. index.php passa esses arrays diretamente às funções tipadas como string, causando TypeError. ver[]=101 também chega ao cast de inteiro de um array, que não representa o ID textual enviado.

**Impacto.** Requisições malformadas podem causar HTTP 500 em login ou listagem e comportamento inesperado no detalhe, em vez de uma rejeição controlada.

**Decisão e ação.** Validei que login/senha POST e busca/ver/export GET sejam strings quando presentes; outros tipos recebem HTTP 400 antes das chamadas. Os nomes e o comportamento dos parâmetros escalares foram preservados.

**Evidência.** Os testes HTTP verificaram HTTP 400 para busca[], ver[], export[], login[] e senha[]. Os logins e as quatro rotas normais continuam passando.

## Decisões

- **MD5 e coluna CHAR(32) não foram migrados (F5).** Uma migração segura exige DDL no banco existente, verificação dupla temporária e política de atualização/redefinição. Alterar só arquivos de instalação não atualiza a base em produção. O risco permanece explicitamente aberto.

- **Não prefixei valores de CSV para neutralizar fórmulas (F8).** A transformação altera o conteúdo consumido por integrações. A política de importação como texto ou de um modo específico para planilhas precisa ser definida; o risco permanece aberto.

- **Chamadas internas à biblioteca sem identidade de sessão mantêm acesso global.** As assinaturas não recebem usuário e são usadas por exportação noturna, relatório e faturamento. Preservar esse uso evita exigir uma sessão web artificial. index.php exige autenticação; sessões com identidade válida recebem escopo e identidades incompletas ou inválidas são negadas.

- **Assinaturas, mysqli, nomes e caminhos dos cinco arquivos, schema e seed foram preservados.** São a base de compatibilidade do pacote. A intervenção ficou nas consultas, validação, saída, sessão e configuração, sem reescrever a aplicação ou adicionar dependências.

- **Rótulos de status e prioridade, ordem das colunas HTML, rotas e curingas LIKE foram mantidos.** São valores ou comportamentos consumidos externamente. A complexidade de rotuloPrioridade não demonstra por si só uma falha; seus casos de fronteira foram caracterizados.

- **Não adicionei paginação, novas rotas, persistência do CSV nem uma nova camada de arquitetura.** Isso ampliaria o escopo e poderia mudar o conjunto de resultados ou a forma de integração. O caminho de arquivo persistente não faz parte do manifesto; a constante EXPORT_DIR foi conservada.

- **Não revoguei segredos em serviços externos nem removi arquivos históricos de produção.** O pacote contém somente código e dados de exemplo; não há acesso confirmado aos serviços ou ao diretório implantado. A correção remove o uso dos segredos embutidos e a geração do arquivo compartilhado, mas a implantação deve rotacionar credenciais e eliminar CSVs históricos.


## Validação e limites

A validação foi executada em PHP 8.4.26 com mysqli/mysqlnd e MariaDB 11.8.6 local, carregando `code/schema.sql` e `code/seed.sql` em uma base isolada. Não foi usado o banco de produção nem introduzida dependência no código entregue. O manifesto menciona MySQL 8: não havia essa versão no ambiente, portanto a execução em MySQL 8 continua uma limitação da validação; as consultas usam recursos compatíveis com essa stack.

- `php -l` passou nos três arquivos PHP.
- 111 verificações de PHP/SQL passaram: quatro logins e falha de senha, SQLi, assinaturas de retorno, rótulos, prioridades nos limites 30/31 e resposta nula, escopos dos quatro usuários, detalhe inexistente e não autorizado, média, CSV e buscas com curingas/apóstrofos. Também cobriram papel inválido, identidade incompleta, biblioteca sem sessão, ausência de técnico, tabela vazia e tempos todos nulos. Os cenários mutáveis usaram transação com rollback.
- 64 verificações HTTP passaram: login e renovação de sessão, invalidação do identificador antigo, flags de cookie, todas as rotas, tabela e colunas HTML exatas, links de detalhe, cabeçalhos e conteúdo do CSV, isolamento entre clientes, SQLi, escape de atributo/texto e rejeição de arrays com HTTP 400.
- 48 exportações concorrentes em oito processos passaram, alternando os dois clientes sem troca de conteúdo. Uma exportação de 16.387 chamados gerou 3.888.876 bytes e cobriu o transbordo do stream acima de 2 MiB.
- Aspas, vírgula, barra invertida, quebra de linha e UTF-8 em título foram preservados após parsing do CSV. O escape explícito de `fputcsv` evita o mecanismo de escape por barra invertida, mantendo o quoting CSV por duplicação de aspas.
- A consulta de listagem passou de cinco SELECTs no exemplo original para um; a exportação corrigida também usa um. A média é calculada no banco sem transferir cada tempo ao PHP.
- A cópia original foi usada para reproduzir UNION com extração dos quatro hashes de teste, leitura cruzada de chamados/CSV, reflexão literal do payload XSS, manutenção do ID de sessão e divisão por zero. Não foi medida a frequência da corrida de arquivo original, nem executada uma planilha para explorar F8.
- Foram comparadas as sete assinaturas do manifesto e também `tecnicoNome`; todas permanecem iguais. Foram conferidos o conjunto de nomes de arquivos, a identidade de schema/seed e a preservação de `TAREFA.md` e `manifest.md`.

Os testes foram scripts temporários de validação fora do pacote. As contagens descrevem verificações executadas, não uma garantia de cobertura total. O modo Secure do cookie foi implementado com base em HTTPS, mas o servidor de teste usa HTTP; o comportamento de um proxy TLS não foi exercitado.

Para implantação, configure `DB_PASS` e, se utilizada, `SMTP_API_KEY` no ambiente. A ausência de `DB_PASS` não recupera mais a senha embutida; uma instalação que dependia dela precisa receber a configuração antes de atualizar. A conexão falha com mensagem genérica quando credenciais não permitem acesso. Rotação de segredos e limpeza de CSVs antigos continuam ações operacionais; F5 e F8 permanecem riscos do código entregue.
