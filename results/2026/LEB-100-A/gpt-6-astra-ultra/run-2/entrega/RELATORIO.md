# Relatório técnico — LEB-100-A

Instância **1.1** · matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel tinha falhas confirmadas de isolamento entre clientes, entrada SQL, saída HTML, sessão e exportação, além de problemas de disponibilidade e custo de consultas. Foram registrados **11 achados**, com alterações para **10** deles; o uso de **MD5 permanece pendente** de migração coordenada. A proteção de fórmulas CSV é uma mitigação com limitações de importador descritas em F7.

Alterados no lugar: `code/lib.php`, `code/index.php` e `code/config.php`. `code/schema.sql` e `code/seed.sql` foram preservados. Nenhum arquivo de `code/` foi movido ou renomeado, e nenhuma dependência foi adicionada.

Foram aprovadas **557 verificações funcionais** (365 da biblioteca e 192 HTTP), além da análise sintática dos três arquivos PHP. A validação usou PHP 8.4.26 e MariaDB 11.8.6 em uma instância temporária; não equivale a uma execução em MySQL 8 de produção.

## Achados, em ordem de prioridade

Todas as referências abaixo e em `achados.json` usam a **numeração original dos arquivos recebidos**. Os identificadores F1–F11 são os mesmos nos dois artefatos. A confiança expressa a probabilidade de o problema descrito ser real, e não a certeza de exploração em produção.

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** listarChamados concatenava busca dentro de titulo LIKE '%...%'. Um valor como ' OR 1=1 -- seguido de espaço fecha a string e altera o predicado SQL; um apóstrofo isolado também pode invalidar a consulta. O termo vem de GET busca em index.php:72–73.

**Impacto.** Um usuário autenticado consegue modificar a seleção e pode extrair dados adicionais por SQL, conforme os privilégios da conexão. A busca também falha para títulos legítimos com apóstrofo.

**Decisão e implementação.** Consulta preparada mysqli com padrão LIKE vinculado por bind_param. O filtro de visibilidade é separado do texto pesquisado. Mantidos os curingas % e _ e a ordenação original.

### F2 — Ausência de autorização por proprietário dos chamados

**Local original:** `code/lib.php:78–109`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** As consultas de listarChamados, verChamado e mediaResposta não consideravam usuario_id nem papel. exportarCsv repetia a seleção irrestrita em lib.php:132. index.php:38–45 lia uid/papel, mas não os aplicava; as rotas de detalhe e lista também chamavam consultas irrestritas. Ana podia abrir ver=103, pertencente a Bruno, além de obter seus chamados na lista e no CSV.

**Impacto.** Clientes acessavam títulos, descrições, técnicos e estatísticas de outros clientes. A regra explícita do manifesto era violada em todas as vias de leitura.

**Decisão e implementação.** Predicado interno compartilhado nas consultas de lista, busca, detalhe, média e exportação. Cliente autenticado fica limitado a c.usuario_id; técnico válido conserva acesso a todos. Contexto parcial ou inválido retorna nenhum chamado. Detalhe alheio retorna null, usando a mesma resposta de inexistente. Ausência de identidade de sessão preserva chamadas de rotinas internas confiáveis; index.php continua exigindo login antes das consultas.

### F3 — XSS refletido no formulário e no resultado da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** O valor GET busca era concatenado sem codificação tanto no atributo value quanto no parágrafo Resultados para. Aspas podiam encerrar o atributo, e marcação como <img src=x onerror=alert(1)> era interpretada como HTML. A consulta SQL segura, sozinha, não protege esses destinos de saída.

**Impacto.** Um link preparado pode executar JavaScript na origem do painel quando aberto por uma vítima autenticada, permitindo leitura de dados e ações com sua sessão.

**Decisão e implementação.** Codificação de busca com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 em ambos os destinos. Também explicitados os mesmos parâmetros nos textos de título, descrição e técnico já escapados.

### F4 — Senha de banco e chave SMTP embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** DB_PASS usava uma senha identificada no comentário como de produção quando a variável de ambiente faltava; SMTP_API_KEY continha uma chave literal. Qualquer cópia do código carregava esses valores, independentemente de permissões de acesso ao ambiente de implantação.

**Impacto.** Quem obtivesse os arquivos poderia tentar acessar o banco ou consumir o serviço SMTP com essas credenciais. A validade atual das credenciais e a conectividade externa não foram presumidas nem testadas.

**Decisão e implementação.** Removidos os dois valores embutidos; DB_PASS e SMTP_API_KEY são lidos do ambiente. Nomes das constantes e padrões não secretos preservados. A implantação deve fornecer as variáveis e rotacionar/revogar as credenciais anteriores; essa operação externa não foi executada.

### F5 — Armazenamento de senhas com MD5 sem sal

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** não corrigido.

**Mecanismo.** autenticar calcula md5 da senha e compara diretamente com usuarios.senha. schema.sql:7 limita o campo a CHAR(32), e seed.sql:5–8 armazena MD5 de senhas repetidas. O algoritmo rápido e sem sal permite testar candidatos offline e identificar usuários com a mesma senha.

**Impacto.** Um vazamento da tabela de usuários permite ataques de dicionário baratos contra senhas de clientes e técnicos. Não implica recuperação imediata de toda senha nem quebra matemática universal do hash.

**Decisão e implementação.** Não alterado nesta entrega. A migração proposta amplia senha para VARCHAR(255), passa a consultar por login, verifica hashes modernos com password_verify e aceita MD5 legado temporariamente com comparação constante; após login válido, grava password_hash por UPDATE condicional, com password_needs_rehash para manutenção. Contas inativas precisam de redefinição e prazo de retirada do legado. Isso exige migração coordenada da base existente e dos gravadores de senha; alterar somente schema.sql ou o algoritmo de autenticação quebraria os usuários existentes.

### F6 — Login mantinha o identificador de sessão anterior

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 99/100. **Estado:** corrigido no código.

**Mecanismo.** session_start aceitava a sessão antes do login e o sucesso de autenticação apenas preenchia uid e papel. Sem regeneração, quem já tivesse conseguido fixar ou obter esse identificador poderia reutilizá-lo depois da autenticação da vítima. A exploração depende dessa etapa prévia.

**Impacto.** Apropriação de uma sessão autenticada e acesso aos chamados permitidos à vítima.

**Decisão e implementação.** session_regenerate_id(true) antes de gravar a identidade, com interrupção em caso de falha. Sessão configurada com modo estrito, transporte apenas por cookie, HttpOnly e SameSite=Lax; também tratada falha de abertura da sessão.

### F7 — Texto do CSV pode ser interpretado como fórmula

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Estado:** corrigido no código (mitigação com limites).

**Mecanismo.** Título e nome de técnico eram passados diretamente a fputcsv. O escape de delimitadores CSV não impede que uma planilha interprete um título como =1+1 como fórmula. O pacote não contém a rota de gravação desses campos; a exploração depende de conseguir inserir o conteúdo no banco por uma integração e de abrir o arquivo em um importador que avalie fórmulas.

**Impacto.** Alteração da interpretação das células e, conforme a planilha e suas permissões, avaliação de fórmulas ou referências externas ao abrir a exportação.

**Decisão e implementação.** Adicionada mitigação na exportação: prefixo de apóstrofo em título/nome que comece por =, +, - ou @, inclusive após espaço/controle ASCII; também cobre tabulação, CR e LF iniciais. O marcador isolado '-' permanece intacto. Não altera dados no banco. A eficácia depende do importador; não há garantia universal após importação/reexportação, e esses valores textuais perigosos passam a conter o prefixo no CSV.

### F8 — Exportações compartilham arquivo sujeito a corrida e truncamento

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** Todas as requisições abriam EXPORT_DIR/chamados.csv em modo w, escreviam, fechavam e depois liam o mesmo caminho com readfile. Uma segunda requisição podia truncar ou sobrescrever o arquivo entre essas etapas. A exportação também retornava silenciosamente se o diretório fixo não pudesse ser escrito.

**Impacto.** Downloads incompletos, misturados ou vazios. Depois de aplicar filtros por cliente, manter esse arquivo compartilhado ainda poderia entregar dados do exportador concorrente.

**Decisão e implementação.** CSV escrito diretamente em php://output por requisição, após preparar a consulta, com fechamento do stream e liberação do resultado em finally. Eliminada a dependência de arquivo compartilhado e de permissão de escrita em EXPORT_DIR. Mantidos nome de download, MIME, cinco colunas e ordenação crescente por id.

### F9 — Média divide por zero quando não existem respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** O contador começa em zero e só aumenta para minutos_resposta não nulo; o retorno divide soma por qtd incondicionalmente. Em uma base vazia ou somente com respostas NULL, a divisão falha. Após respeitar a visibilidade, os chamados de Bruno no seed também acionam esse caso.

**Impacto.** A listagem deixa de renderizar por erro de divisão em PHP atual. O caso é comum para um cliente que ainda não recebeu a primeira resposta.

**Decisão e implementação.** SUM e COUNT de minutos_resposta no banco, no mesmo escopo de autorização, retornando 0.0 se a contagem for zero. A divisão permanece em PHP para preservar o float anterior: 77/3 no seed, sem arredondamento introduzido por AVG SQL. Além da correção, apenas uma linha agregada é transferida, em vez de todos os tempos de resposta.

### F10 — Parâmetros HTTP em arrays causam falhas de tipo

**Local original:** `code/index.php:22`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** PHP aceita login[]=x e senha[]=x como arrays, mas autenticar exige string. O mesmo ocorre com busca[]=x em index.php:72–73. Essas entradas causam TypeError; ver[]=x em index.php:53 era convertido em inteiro 1, consultando um id diferente do pretendido.

**Impacto.** Requisições malformadas encerram a página com erro ou consultam um id inesperado. Isso não constitui, por si só, injeção SQL nem indisponibilidade global do servidor.

**Decisão e implementação.** Validado que login/senha e os parâmetros GET busca/ver/export são strings antes de uso. Arrays recebem HTTP 400, sem encaminhamento à função tipada ou conversão em identificador. Rotas e comportamento dos valores escalares preservados.

### F11 — Uma consulta adicional por técnico em cada chamado

**Local original:** `code/lib.php:88–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Estado:** corrigido no código.

**Mecanismo.** O laço da listagem chama tecnicoNome para cada chamado atribuído; tecnicoNome executa SELECT por id em lib.php:69. A exportação repete o padrão em lib.php:132–137. Para N chamados com técnico, são 1+N consultas, mesmo quando vários chamados têm o mesmo técnico.

**Impacto.** Latência e carga do banco crescem com o número de chamados; o seed já executava cinco consultas para listar cinco chamados, pois quatro têm técnico.

**Decisão e implementação.** LEFT JOIN usuarios com COALESCE(nome, '-') na listagem e no CSV obtém os nomes na consulta principal. Preservados chamados sem técnico, todas as colunas c.*, a chave tecnico_nome e as ordenações. tecnicoNome continua disponível. Campos numéricos de listarChamados voltam a strings para conservar os retornos do mysqli padrão anterior.

## Decisões

- **Migração de MD5 e mudanças em schema.sql/seed.sql (F5).** Pendente de alteração coordenada de usuarios.senha e de todos os gravadores de credenciais. As contas e senhas fornecidas continuam funcionando. Nenhuma migração foi aplicada à base real; o roteiro está no achado F5.

- **Rotação externa de DB_PASS e SMTP_API_KEY (F4).** Os literais foram removidos, mas isso não revoga credenciais já copiadas. Cabe à implantação fornecer as variáveis e rotacionar os segredos. Não há operação de provedor ou credencial administrativa de rotação no pacote.

- **Assinaturas mysqli e chamadas internas sem identidade de sessão.** Relatórios e rotina noturna dependem das funções existentes e não recebem usuário como parâmetro. Ausência de identidade mantém acesso completo apenas no uso interno confiável; a entrada web autentica primeiro. Qualquer novo controlador web deve estabelecer a sessão autenticada antes de chamar a biblioteca. Não foram adicionados parâmetros obrigatórios, outra stack ou dependências.

- **Rótulos de status e prioridade e seus limites.** Não há evidência de erro de negócio nesses rótulos. Mantidos status 1/2/3, fallback original de status desconhecido e prioridade 4 dentro do SLA como Alto - dentro do SLA. Reorganizar a função aninhada não resolve defeito demonstrado e ampliaria o diff.

- **Curingas da busca, ordenação, HTML, rotas e tipos de retorno.** Os curingas % e _ do LIKE são preservados, assim como a ordenação da lista por criado_em DESC, exportação por id ASC, tabela tabela-chamados, colunas e links. Não acrescentei paginação nem limite silencioso que omitisse registros de consumidores. Escapes e HTTP 400 afetam apenas saídas inseguras ou parâmetros malformados.

- **Formato e limites da mitigação de fórmulas no CSV.** Cabeçalho literal exigido e cinco colunas preservados. Campos textuais potencialmente interpretados como fórmulas recebem apóstrofo; esse é um ajuste deliberado de segurança, sem alterar o banco. Valores normais e marcador '-' são idênticos. CSV não carrega tipos de célula, então não se promete proteção universal em todo importador ou após reexportação.

- **tecnicoNome e constante EXPORT_DIR.** Foram conservados para evitar remoção desnecessária de símbolos existentes. A listagem/exportação usam JOIN, e o CSV deixou de depender do diretório; não há consumidor do arquivo temporário declarado no manifesto.

- **Cookie Secure obrigatório e arquitetura do controlador.** O manifesto não estabelece HTTPS nem a configuração do proxy. Mantida a configuração Secure do ambiente, sem forçá-la em HTTP e impedir login. Não reorganizei o controlador em camadas nem introduzi framework para realizar correções locais.

- **Índices extras e mudança da semântica da busca.** Há índices de proprietário e criação no schema. Sem volume/plano de execução representativo, não se justifica impor novos índices; trocar LIKE com prefixo % por full-text ou busca apenas de prefixo mudaria os resultados contratados.

## Validação executada

O banco temporário foi criado com os arquivos `schema.sql` e `seed.sql` entregues. Alterações de fixtures durante a verificação da biblioteca usaram transações e rollback. O servidor PHP teve quatro workers; testes de concorrência usaram oito workers de cliente, com sessões independentes. Os processos temporários foram encerrados ao final. Nenhuma base de produção foi modificada.

| Verificação | Resultado e cobertura |
| --- | --- |
| Sintaxe PHP | `php -l` aprovado para config.php, index.php e lib.php. |
| Biblioteca | **365 verificações, zero falhas**: reflection das sete assinaturas, retornos, login dos quatro usuários, status, limites de prioridade e SLA, acesso interno sem sessão e 15 contextos válidos/inválidos. |
| SQL e dados | Payloads de injeção tratados como texto; curingas preservados; títulos com apóstrofos, aspas e barras; JOIN conserva técnico ausente; campos numéricos da lista continuam textuais. |
| Média | Seed mantém `77/3`; base vazia, somente NULL e cliente sem resposta retornam `0.0`; zero é contado como resposta. |
| Consultas | Contador `Com_select`: uma consulta na listagem e uma no CSV, tanto com cinco quanto com dez chamados. Não foi realizado benchmark de latência de produção. |
| HTTP | **192 verificações, zero falhas**: login, SID regenerado e SID anterior sem autenticação, cookies, quatro rotas, acesso por papel, XSS, SQLi, arrays HTTP 400, tabela/colunas/links e headers de download. |
| CSV concorrente | Dentro das 192 verificações HTTP, **48 exportações**, em 16 fluxos de Ana/Bruno: um cabeçalho, cinco colunas e somente os IDs autorizados em ordem. |
| CSV com conteúdo especial | Título e técnico com operadores de fórmula, controles, delimitadores, aspas, barra e quebras de linha; leitura de volta preserva registros/campos e prefixos esperados. Não foi automatizada a abertura em Excel/LibreOffice. |
| Configuração | Ambiente definido/ausente e senha sintética `0` verificados; não há fallback de segredo literal. |

Resultado de visibilidade com o seed: **Ana: 101, 102, 105; Bruno: 103, 104; Carla e Diego: todos os cinco**. Clientes recebem `null` para detalhes alheios, e CSV/lista seguem o mesmo escopo. A média de Bruno é zero; a dos demais contextos com respostas é `77/3`.

Os scripts auxiliares desta execução ficam em `/tmp/leb100-validation/lib_checks.php`, `http_checks.py` e `run_checks.py`; não integram a superfície entregue de `code/`. As contagens acima descrevem as verificações executadas, sem alegar cobertura exaustiva ou equivalência entre MariaDB e MySQL 8 em todos os casos.

## Implantação e pendências

Antes de implantar, configurar `DB_PASS` e `SMTP_API_KEY` no ambiente e rotacionar as credenciais antigas (F4). O código não contém seus valores anteriores. Programar a migração de senhas de F5; ela deve incluir banco existente, gravadores de credenciais, verificação de compatibilidade e retirada posterior do MD5. Confirmar as verificações também na versão MySQL 8 usada pelo ambiente real.
