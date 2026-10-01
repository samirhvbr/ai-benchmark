# Relatório técnico — LEB-100-A

## Resumo

O painel mantinha dados de todos os clientes acessíveis a qualquer usuário autenticado, concatenava a busca no SQL e no HTML e tinha falhas reproduzíveis de sessão, cálculo e serialização. Foram registrados **13 achados: 11 corrigidos no código e 2 reportados sem correção** (F4 e F7). A retirada dos segredos do código ainda exige rotação operacional dos valores antigos.

A evolução preserva as sete assinaturas públicas, mysqli, as rotas e os nomes dos arquivos. A tabela HTML e o CSV mantêm as colunas e os rótulos declarados. A visibilidade por proprietário passa a cumprir a regra pretendida do manifesto. Não houve reescrita nem instalação de dependências na aplicação.

**Referências:** todas as linhas dos achados, inclusive as indicadas em `achados.json`, usam a numeração **original**, anterior às alterações. Os achados abaixo estão na ordem de prioridade proposta. Instância 1.1; matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Achados

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados concatena busca entre aspas em LIKE. O termo ' OR 1=1 -- encerra o literal e transforma o restante em SQL; uma requisição autenticada controla a condição da consulta e pode construir UNION compatível com as colunas retornadas.

**Impacto.** Leitura de chamados fora do filtro e possível extração de outros dados acessíveis ao usuário do banco, inclusive hashes de autenticação. Não é necessário nem foi pressuposto suporte a múltiplas instruções SQL.

**Decisão e correção.** Substituí a interpolação por prepare/bind_param. O padrão %termo% é um valor vinculado; aspas não alteram a consulta. Preservei os curingas LIKE % e _ já aceitos pela busca.

**Verificação/evidência.** No original, o payload retornou os cinco chamados; na versão corrigida, retornou lista vazia. A busca normal por Roteador continuou retornando 105 e o curinga % manteve a semântica anterior dentro do escopo autorizado.

### F2 — Ausência de autorização por proprietário nas consultas

**Local original:** `code/lib.php:78–116`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados, verChamado e mediaResposta consultam todos os registros; exportarCsv repete a falha nas linhas originais 132–144. index.php lê uid/papel nas linhas 38–39, mas não os aplica. Trocar ver=101 por ver=103 permite a Ana ler o chamado de Bruno; busca e CSV também ignoram o dono.

**Impacto.** Um cliente autenticado lê títulos, descrições e dados de outros clientes. O indicador de resposta ainda inclui dados de terceiros, mesmo se apenas a tabela fosse filtrada.

**Decisão e correção.** Centralizei um predicado de visibilidade usado nas quatro consultas: cliente recebe apenas c.usuario_id igual ao uid da sessão; técnico recebe todos. Sessões web ausentes ou inválidas negam acesso. CLI sem identidade preserva o uso privilegiado por rotinas internas; a decisão e seu limite estão descritos abaixo.

**Verificação/evidência.** Ana recebeu 105,102,101 na listagem e 101,102,105 no CSV; Bruno recebeu 104,103 e CSV 103,104; Carla recebeu todos. O detalhe 103 foi negado a Ana e o detalhe 101 permaneceu disponível. Sessões incompletas ou com papel desconhecido não retornaram dados.

### F3 — XSS refletido nos dois usos HTML da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O parâmetro GET busca entra sem escape tanto no atributo value do input quanto no conteúdo do parágrafo. Uma aspa dupla encerra o atributo, e uma tag inserida no termo cria marcação ativa no parágrafo.

**Impacto.** Ao abrir um link preparado por um adversário, um usuário autenticado pode executar JavaScript na origem do painel, permitindo leitura dos dados disponíveis à sessão e ações em seu nome.

**Decisão e correção.** Apliquei htmlspecialchars com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 antes de ambos os usos. O termo original continua sendo usado na consulta, evitando misturar escape HTML com dados de busca.

**Verificação/evidência.** Requisições HTTP com tag img/onerror e com quebra de atributo reproduziram a inclusão literal no original. Os mesmos payloads ficaram escapados na versão corrigida. A verificação inspecionou o HTML; não executou JavaScript em um navegador.

### F4 — Senhas armazenadas como MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** não.

**Mecanismo.** autenticar calcula md5 da senha e compara esse valor diretamente no banco. schema.sql:7 limita senha a CHAR(32), e seed.sql:5–8 gera os mesmos hashes para usuários com a mesma senha. Não existe salt individual nem fator de trabalho.

**Impacto.** Quem obtiver a tabela usuarios pode testar grandes quantidades de candidatos fora do sistema, reaproveitando resultados entre usuários e bases. A proteção depende sobretudo da força da senha escolhida.

**Decisão e correção.** Reportado, sem alterar autenticar, schema ou seed nesta entrega. A migração deve ampliar senha para VARCHAR(255), aceitar temporariamente hashes antigos, verificar hashes modernos com password_verify e converter após login correto usando password_hash. Contas inativas precisam de redefinição; só mudar md5 no PHP bloquearia os usuários existentes.

**Verificação/evidência.** Inspeção do armazenamento e da comparação; os quatro pares de credenciais fornecidos continuam funcionando. Não foi realizada quebra de senhas nem migração de banco de produção.

### F5 — Credenciais de banco e SMTP embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** DB_PASS usa uma senha literal de produção como fallback, e SMTP_API_KEY é um literal fixo. Quem recebe o código também recebe esses segredos; omitir DB_PASS ativa silenciosamente a credencial embutida.

**Impacto.** Exposição de credenciais a leitores do repositório ou pacote, com possibilidade de acesso ao banco ou abuso do serviço de e-mail se os valores ainda forem válidos e os serviços alcançáveis.

**Decisão e correção.** Removi os dois segredos do código e li DB_PASS e SMTP_API_KEY do ambiente, preservando os nomes das constantes. Ausência resulta em string vazia, sem fallback de produção. A implantação precisa fornecer as credenciais; os valores antigos devem ser rotacionados fora deste pacote.

**Verificação/evidência.** Diff e inspeção das constantes. Não tentei autenticar em serviços de produção. corrigido=true refere-se à retirada dos literais no código entregue, não à revogação de credenciais já divulgadas.

### F6 — Login conserva o identificador da sessão anterior

**Local original:** `code/index.php:15–25`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Depois de autenticar, index.php grava uid e papel na sessão existente sem trocar seu identificador. Um adversário que consiga fazer a vítima usar uma sessão conhecida pode reutilizar esse identificador depois do login.

**Impacto.** Sequestro da sessão sob a condição de o adversário conseguir fixar ou conhecer previamente seu identificador. A falta de rotação não implica, sozinha, que qualquer visitante já controle uma sessão alheia.

**Decisão e correção.** Regenero o identificador com descarte do anterior no login bem-sucedido. Ativei modo estrito, uso apenas por cookies, HttpOnly e SameSite=Lax; Secure é ativado quando o servidor informa HTTPS. Libero o lock da sessão após a leitura para não serializar exportações do mesmo usuário.

**Verificação/evidência.** Via HTTP, o identificador antes e depois do login era igual no original e mudou na versão corrigida. Verifiquei HttpOnly e SameSite=Lax no cookie emitido.

### F7 — CSV pode transportar fórmulas para planilhas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Corrigido:** não.

**Mecanismo.** Titulo e nome do técnico são passados diretamente ao fputcsv. Esse escape protege a estrutura CSV, mas não transforma conteúdo iniciado por =, +, - ou @ em texto para todos os programas de planilha. Se um produtor de dados gravar uma fórmula, ela chega intacta à exportação.

**Impacto.** Um operador que importe o CSV em uma planilha que interprete fórmulas pode executar cálculos ou ações suportadas pelo programa a partir de dados manipulados. Depende da capacidade de inserir esses dados e do comportamento da planilha; o pacote não contém a rota de criação de chamados.

**Decisão e correção.** Reportado, sem prefixar ou modificar os campos nesta exportação usada por integração de faturamento. Recomendo um modo explícito de exportação para planilhas ou importação das colunas como texto; alterar silenciosamente o título com apóstrofo mudaria dados consumidos por integrações.

**Verificação/evidência.** Análise do caminho entre campos do banco e células CSV. Nenhuma aplicação de planilha foi executada; a confiança menor expressa a dependência do produtor de dados e do consumidor.

### F8 — Exportação usa arquivo temporário único e compartilhado

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Toda exportação abre EXPORT_DIR/chamados.csv com modo w e depois o reabre por readfile. Uma segunda requisição pode truncar ou sobrescrever o arquivo antes ou durante a leitura da primeira. O resultado também depende de existir um diretório gravável; falhas retornam silenciosamente e o arquivo fica persistido.

**Impacto.** CSV vazio, truncado ou misturado entre requisições e dependência desnecessária de disco/permissões. Com visibilidade por cliente, manter esse compartilhamento também poderia trocar o conteúdo entre usuários.

**Decisão e correção.** Escrevo diretamente em php://output, sem arquivo comum ou dados persistidos. A consulta ocorre antes dos cabeçalhos, o resultado é consumido sem buffer de todos os registros e recursos são fechados em finally. EXPORT_DIR permanece definido para não remover uma constante existente.

**Verificação/evidência.** 24 downloads HTTP concorrentes, alternando Ana, Bruno e Carla, retornaram exatamente os IDs esperados. Não foi necessário criar o diretório de exportação original. O entrelaçamento problemático do original é demonstrado pelo mecanismo de truncamento; não atribuo a ele um teste concorrente que não executei.

### F9 — Média divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** A consulta exclui minutos_resposta NULL. Em uma base vazia ou com todos os chamados ainda sem primeira resposta, qtd fica zero e soma/qtd divide por zero. A página chama essa função mesmo quando a listagem está vazia.

**Impacto.** Interrupção da página por DivisionByZeroError no PHP testado. O caso passa a ocorrer também para um cliente cujos chamados ainda não receberam resposta quando a autorização é corretamente aplicada.

**Decisão e correção.** Agrego SUM e COUNT de minutos_resposta no banco e retorno 0.0 quando a contagem é zero. NULL continua excluído e zero minutos continua incluído. A divisão final em PHP preserva a precisão da média anterior; não uso AVG de inteiro sujeito à escala decimal do banco. A transferência passa de uma linha por resposta para uma linha agregada.

**Verificação/evidência.** Reproduzi DivisionByZeroError no original com todos os minutos NULL. Na correção, base vazia, todos NULL e Bruno sem respostas retornaram 0.0; valores 0 e 10 resultaram em 5.0; o seed manteve 77/3 com tolerância de 1e-12.

### F10 — Escape padrão do CSV corrompe campos com barra e aspas

**Local original:** `code/lib.php:138–144`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** fputcsv é chamado com o escape padrão de barra invertida. Quando um valor contém barra imediatamente antes de aspas, a saída pode não duplicar essas aspas como um leitor CSV convencional espera, confundindo limites de campos e de registros.

**Impacto.** Títulos com determinadas combinações de barras, aspas, vírgulas e quebras de linha não sobrevivem ao ciclo de exportação e leitura e podem deslocar outras colunas.

**Decisão e correção.** Especifiquei delimitador vírgula, aspas duplas e escape vazio em fputcsv, fazendo o escape por duplicação de aspas. Gravei o cabeçalho literal ID,Titulo,Status,Tecnico,Aberto em, sem BOM, e mantive as cinco colunas, rótulos e ordenação por ID.

**Verificação/evidência.** Um título contendo vírgula, aspas, barra antes de aspas, quebra de linha e UTF-8 falhou no ciclo de leitura do CSV original e foi recuperado exatamente no CSV corrigido. Também conferi o cabeçalho literal.

### F11 — Listagem e CSV fazem uma consulta adicional por técnico

**Local original:** `code/lib.php:85–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O loop da listagem chama tecnicoNome para cada chamado com tecnico_id. O CSV repete isso na linha original 137, inclusive quando vários chamados usam o mesmo técnico. Cada chamada executa outro SELECT de usuarios.

**Impacto.** O número de consultas cresce com os chamados atribuídos, aumentando latência e trabalho do banco. No seed, são cinco SELECTs para retornar cinco chamados, embora só existam dois técnicos distintos.

**Decisão e correção.** Usei LEFT JOIN usuarios e COALESCE(nome, '-') nas duas consultas. Chamados sem técnico permanecem presentes, tecnico_nome permanece na listagem, e tecnicoNome continua disponível com a assinatura anterior.

**Verificação/evidência.** A diferença de Com_select da sessão de banco caiu de 5 para 1 tanto na listagem quanto na exportação. Foram conferidos o técnico ausente, a ordenação e a contagem de chamados.

### F12 — Parâmetros HTTP em formato de array causam erros fatais

**Local original:** `code/index.php:20–22`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** PHP aceita login[]=ana e busca[]=x como arrays. index.php passa login/senha diretamente a autenticar na linha 22 e busca diretamente a listarChamados nas linhas 72–73, embora as assinaturas exijam string. Isso dispara TypeError antes de uma resposta controlada; ver[]=101 também era convertido de array para inteiro.

**Impacto.** Uma requisição malformada interrompe seu próprio processamento e pode expor caminho de arquivo e stack trace quando display_errors está ativo. Não foi demonstrada indisponibilidade global do serviço.

**Decisão e correção.** Valido que login, senha, busca e ver sejam strings antes de consumi-los e retorno HTTP 400 para arrays. Os parâmetros escalares, inclusive busca vazia e senha ausente, conservam o fluxo anterior.

**Verificação/evidência.** Os arrays de login e busca produziram TypeError no original; na correção, login[], busca[] e ver[] retornaram 400. Os logins e as rotas válidas continuaram funcionando.

### F13 — Falha de conexão escapa do tratamento em mysqli estrito

**Local original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O tratamento original verifica connect_errno apenas depois de new mysqli. Com o modo estrito ativo no PHP testado, o construtor lança mysqli_sql_exception e nunca chega ao if. A configuração do charset também não tem tratamento.

**Impacto.** Em erro de credencial ou conexão, a resposta termina em exceção não tratada, potencialmente com dados de conexão e caminhos internos quando display_errors está ativo, em vez da mensagem controlada prevista no código.

**Decisão e correção.** Configurei o modo estrito explicitamente no ponto de entrada e tratei mysqli_sql_exception ao conectar e configurar utf8mb4. O cliente recebe a mensagem existente com HTTP 503; o log recebe uma indicação genérica, sem credenciais.

**Verificação/evidência.** Apontando apenas os servidores de teste a uma base inacessível, reproduzi mysqli_sql_exception no original. A correção retornou 503 com apenas Falha ao conectar ao banco. Não desliguei nem alterei o banco do ambiente do usuário.

## Decisões

- **Hashes MD5 e esquema CHAR(32) existentes (F4).** A base em produção e seus escritores não acompanham uma troca de algoritmo feita só no PHP. Mantive o login dos quatro usuários e descrevi a migração coordenada; o risco permanece e deve ser priorizado.

- **Conteúdo textual das células do CSV, inclusive possíveis fórmulas (F7).** O CSV é também entrada de integrações externas. Prefixar valores silenciosamente mudaria os dados; falta um contrato de exportação específico para planilhas.

- **Assinaturas públicas, mysqli, arquivos e rotas.** São contratos explícitos. Não adicionei dependências, parâmetros de autenticação ou uma camada nova; config.php, index.php e lib.php foram alterados no lugar, schema.sql e seed.sql foram preservados.

- **Acesso global dos scripts CLI sem sessão.** O manifesto informa uso por rotinas internas e exportação noturna, sem parâmetro de usuário nas funções. Mantive esse contexto local como privilegiado apenas quando uid e papel estão ambos ausentes; sessões presentes obedecem à autorização, e web sem identidade nega acesso. Esses scripts devem continuar sob controle operacional confiável.

- **Rótulos e lógica de prioridade, status e curingas de busca.** Não há especificação que justifique mudar o comportamento dos limites de SLA, dos estados desconhecidos ou de LIKE. Mantive textos, NULL, limite de 30 minutos, % e _; evitei refatoração estética sem ganho funcional.

- **Paginação, índices adicionais e decomposição em novas camadas.** Paginação mudaria a lista integral consumida por scripts; novos índices precisam de volume e planos de execução representativos. O JOIN e a agregação resolvem os custos demonstrados sem reescrever a aplicação.

- **Constantes DB_HOST, DB_NAME, DB_USER e EXPORT_DIR; função tecnicoNome.** Preservei nomes já existentes e defaults não secretos. EXPORT_DIR deixou de ser necessário ao download, mas não removi a constante nem a função auxiliar potencialmente reutilizada.

- **Revogação externa das credenciais antigas e configuração de infraestrutura.** A entrega altera arquivos locais. A equipe de implantação deve rotacionar os segredos antigos, fornecer DB_PASS e SMTP_API_KEY e assegurar que HTTPS seja sinalizado corretamente ao PHP, inclusive atrás de proxy. Não alego que essas ações externas foram executadas.


## Validação realizada

Usei PHP **8.4.26**, mysqli/mysqlnd e um servidor **MariaDB 11.8.6** temporário, com socket privado e rede desabilitada. Carreguei `schema.sql` e `seed.sql` sem alterações. O MySQL 8 declarado no comentário do esquema não estava disponível como servidor isolado; portanto, não reivindico teste nessa versão nem nas versões antigas de PHP. Nenhum banco de produção foi modificado.

- Sintaxe dos três arquivos PHP aprovada por `php -l`.

- **62 verificações de caracterização no original** confirmaram comportamentos e reproduziram os defeitos selecionados; **71 verificações na biblioteca corrigida** passaram, incluindo todos os usuários, credenciais inválidas, chaves de retorno, status, 30 combinações de prioridade/SLA, visibilidade, consulta por título, tentativas de injeção, resultado ausente, médias vazias, NULL, zero e precisão fracionária.

- **35 verificações HTTP** passaram: login e troca do ID de sessão, cookies, rotas, estrutura da tabela, escape da busca, detalhe autorizado e negado, erros de entrada, falha de conexão, cabeçalhos e conteúdo CSV. Essa suíte incluiu **24 downloads concorrentes** entre três perfis e verificou seus conjuntos de IDs.

- A contagem de SELECTs, medida por `Com_select` na mesma conexão, caiu de **5 para 1** na listagem e no CSV do seed. Isso mede a redução de consultas; não é um benchmark de latência de produção.

- CSV validado por leitura de campos, com título contendo vírgula, aspas, barra antes de aspas, quebra de linha e acentos; o valor foi recuperado integralmente. Cabeçalho literal, nome do download, ordenação por ID e rótulos foram conferidos.

- Os testes e a cópia original foram mantidos em `/tmp/leb-100-a-tests/` e `/tmp/leb-100-a-original/`, fora de `code/`; os dados alterados nos testes unitários de integração foram revertidos por transação. Os testes usam apenas PHP e a biblioteca padrão do Python.


Comandos usados para sintaxe e execução das suítes neste ambiente:

```sh
php -l code/config.php
php -l code/index.php
php -l code/lib.php
php /tmp/leb-100-a-tests/verify.php /tmp/leb-100-a-original/code original
php /tmp/leb-100-a-tests/verify.php /srv/run/code
python3 /tmp/leb-100-a-tests/http_verify.py
```

As suítes de integração exigem o servidor temporário e a base de teste; seus caminhos em `/tmp` não são dependências da aplicação nem parte dos três artefatos solicitados. A compatibilidade foi verificada para o contrato fornecido e os cenários acima, sem presumir cobertura de consumidores externos não entregues.

## Aplicação da entrega

Publicar os três arquivos PHP alterados nos mesmos caminhos e fornecer `DB_PASS` e, se o serviço for utilizado, `SMTP_API_KEY` pelo ambiente. Não há alteração de esquema necessária para estas correções. A exportação não requer mais `/var/www/painel/tmp`. As correções passam a restringir clientes aos próprios chamados, conforme a regra explícita do manifesto. Antes da implantação, rotacionar as credenciais anteriormente embutidas; planejar a migração de senhas de F4 e o tratamento de planilhas de F7.
