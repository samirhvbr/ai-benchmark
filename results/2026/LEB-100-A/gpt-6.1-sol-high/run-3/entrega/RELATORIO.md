# Relatório técnico — LEB-100-A

## Resumo

Foram identificados 12 achados: 11 corrigidos no código e uma pendência de segurança explicitamente mantida (MD5, F6). As mudanças se concentram no isolamento de clientes, tratamento da busca, escape HTML, sessão, exportação e consultas. Não houve troca de stack, reescrita nem alteração de assinatura pública. `code/config.php`, `code/index.php` e `code/lib.php` foram alterados no lugar; `schema.sql` e `seed.sql` foram preservados.

Todas as referências de linha abaixo e no JSON usam a **numeração original recebida**, não a numeração após as correções. Os achados estão na ordem de prioridade de correção. A confiança mede a existência do problema, não a probabilidade de exploração ou de sucesso de uma recomendação operacional.

## Achados

### F1 — Ausência de isolamento dos chamados por cliente

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** listarChamados e verChamado consultam chamados sem usuario_id; exportarCsv (linha 132) e mediaResposta (109) também consultam toda a tabela. index.php lê uid/papel nas linhas 38–39, mas não os usa nas consultas. Ana consegue abrir ?ver=104, ver títulos de Bruno e baixar seus registros; o indicador também revela dados agregados alheios.

**Impacto:** Um cliente autenticado lê chamados e descrições de outros clientes, contrariando a regra de visibilidade do manifesto.

**Ação e justificativa:** Filtro de visibilidade comum às quatro consultas: cliente recebe somente c.usuario_id igual ao uid da sessão, técnico mantém visão completa e papel inválido não recebe registros. Detalhe não autorizado retorna null e a mesma mensagem de inexistência. Scripts internos sem identidade de sessão conservam a visão global; index.php autentica antes de qualquer consulta de chamados.

**Evidência e limites:** A regressão testa os quatro usuários: Ana vê 101/102/105, Bruno 103/104, Carla e Diego todos; inclui detalhe, busca, média e CSV. A restrição fica no SQL, antes de materializar os dados.

### F2 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** O valor de busca vindo de GET é concatenado entre aspas no LIKE. A entrada %' OR 1=1 -- encerra o literal e altera o predicado SQL; o restante pode ser comentado. A consulta roda com os privilégios da conexão do painel.

**Impacto:** Um usuário autenticado pode alterar a consulta e, conforme os privilégios do banco, extrair dados por UNION ou causar consultas caras. Escapar apenas o HTML não resolveria a interpretação pelo banco.

**Ação e justificativa:** SELECT preparado mysqli com LIKE ? e padrão vinculado por bind_param. O filtro de dono é adicionado separadamente e não contém entrada textual do usuário. Mantidos os curingas % e _ que a busca legada já aceitava.

**Evidência e limites:** Testes de biblioteca e HTTP enviam a tentativa de injeção e exigem resultado vazio; busca comum e curinga continuam funcionando. Não há alegação de execução de múltiplas instruções via mysqli::query.

### F3 — XSS refletido na busca e no atributo do formulário

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** A busca é interpolada diretamente tanto em value="..." quanto no parágrafo de resultados. Um valor como "><script>alert(1)</script> encerra o atributo e insere marcação executável; no parágrafo nem é necessário encerrar atributo.

**Impacto:** Um link preparado pode executar JavaScript na origem do painel quando aberto por um usuário autenticado, permitindo ler dados que essa vítima pode acessar.

**Ação e justificativa:** Escape HTML em ambos os pontos com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 explícito, aplicado também aos campos textuais já escapados das telas.

**Evidência e limites:** Testes HTTP verificam que o payload não aparece como marcação e que a versão escapada aparece. O valor usado na consulta continua sendo o original, sem misturar escape HTML e SQL.

### F4 — Credenciais de produção embutidas na configuração

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 95/100. **Corrigido:** sim.

**Mecanismo:** DB_PASS usa uma senha literal quando o ambiente não fornece valor, e SMTP_API_KEY é sempre uma chave literal. Uma cópia do código entrega esses valores a quem obtiver o pacote. O comentário identifica o fallback como senha de produção; não foi tentado acesso a serviços com essas credenciais.

**Impacto:** Se os valores ainda forem válidos, acesso indevido ao banco ou ao serviço de e-mail. A retirada do código não revoga cópias anteriores.

**Ação e justificativa:** Removidos os dois segredos literais. DB_PASS e SMTP_API_KEY passam a vir do ambiente, mantendo os nomes das constantes e as demais opções. Rotação/revogação das credenciais antigas e provisionamento dos novos valores precisam ser feitos pelo operador; não foram executados neste ambiente.

**Evidência e limites:** A correção do código está concluída; a validade atual dos segredos e sua revogação externa não foram verificadas. Sem DB_PASS definido, o valor é vazio, nunca a senha antiga; o ambiente de produção deve definir sua credencial explicitamente.

### F5 — Login conserva o identificador da sessão anterior

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** session_start abre a sessão e o login apenas grava uid/papel nela. Não existe session_regenerate_id na transição para autenticado. Se um atacante conseguir fixar um identificador de sessão conhecido no navegador da vítima, ele continua identificando a sessão após o login.

**Impacto:** Sequestro de sessão autenticada sob a condição de fixação prévia. A possibilidade de fixar o cookie depende do ambiente; a ausência de renovação é diretamente observável.

**Ação e justificativa:** Renovação do identificador com descarte do anterior antes de gravar a identidade. Ativados modo estrito, somente cookies, HttpOnly, SameSite=Lax e Secure quando HTTPS é informado pelo servidor. Sessões com identidade inválida voltam ao login.

**Evidência e limites:** Teste HTTP comprova ID diferente após login, ID anterior sem acesso autenticado e atributos HttpOnly/SameSite. Não foi simulada terminação TLS; atrás de proxy, o servidor deve informar HTTPS corretamente.

### F6 — Senhas armazenadas com MD5 sem sal

**Local original:** `code/lib.php:15–16`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** não.

**Mecanismo:** autenticar calcula md5 da senha e compara diretamente com usuarios.senha. schema.sql linha 7 limita essa coluna a CHAR(32), e seed.sql grava MD5. Hashes iguais revelam senhas iguais; após uma cópia do banco, tentativas de senha não precisam passar pelo login e são muito baratas.

**Impacto:** Quebra offline de senhas fracas e reaproveitamento dessas credenciais em contas do painel ou outros serviços.

**Ação e justificativa:** Não corrigido nesta entrega. Proposta: migrar usuarios.senha para VARCHAR(255), aceitar temporariamente MD5 legado com comparação constante, verificar hashes modernos com password_verify e atualizar para password_hash após login bem-sucedido. Exige migração e implantação coordenadas com os escritores de usuários, que não estão no pacote; simplesmente trocar md5 ou truncar um hash moderno bloquearia contas existentes.

**Evidência e limites:** A pendência é explícita e mantém risco alto. Não há como derivar um hash moderno equivalente a partir do MD5 sem conhecer a senha; seria necessária migração progressiva ou redefinição de senhas. Nenhum usuário de teste ou formato de banco foi alterado.

### F7 — CSV usa arquivo compartilhado e depende de diretório fixo

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Toda exportação abre EXPORT_DIR/chamados.csv em modo w, escreve, fecha e depois faz readfile do mesmo caminho. Duas requisições podem truncar ou substituir o arquivo entre essas etapas. Se o diretório não existir ou não for gravável, a função retorna sem CSV; na falha da consulta, retorna sem fechar o arquivo explicitamente.

**Impacto:** Download vazio, parcial ou pertencente a outra execução; persistência desnecessária de dados de chamados no disco. Acesso estático ao arquivo dependeria da configuração do servidor web e não foi presumido.

**Ação e justificativa:** Escrita direta em php://output com recurso local à requisição e fechamento em finally. Consulta executada antes dos cabeçalhos, eliminando arquivo temporário, leitura posterior e dependência de diretório gravável. Nome do download, MIME, cabeçalho e ordem crescente de IDs preservados.

**Evidência e limites:** CSV foi verificado por chamada direta e por HTTP, sem criar EXPORT_DIR. O mecanismo da corrida foi eliminado por construção; não foi feito ensaio de carga simultânea.

### F8 — Texto do CSV pode ser interpretado como fórmula

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Corrigido:** sim.

**Mecanismo:** Titulo e nome do técnico são passados diretamente a fputcsv. Esse escape protege a estrutura do CSV, mas uma célula começando por =, +, - ou @ ainda pode ser interpretada como fórmula por programas de planilha. O pacote não contém criação de chamados: exploração exige que um valor desse tipo chegue ao banco por outro fluxo e que alguém abra o CSV em uma planilha que execute fórmulas.

**Impacto:** Interpretação de conteúdo importado como fórmula, podendo induzir links ou requisições externas, conforme o aplicativo e suas políticas.

**Ação e justificativa:** Prefixo apóstrofo nos campos textuais com prefixos de fórmula, inclusive após espaços e controles ASCII. O marcador isolado - para técnico ausente é preservado. As cinco colunas, rótulos, delimitador e ordenação continuam iguais; somente textos perigosos recebem neutralização.

**Evidência e limites:** Teste insere título com fórmula, aspas, vírgula e quebra de linha e valida a leitura de volta em cinco colunas. Não houve execução de Excel/LibreOffice; variantes de interpretação de planilhas não foram exaustivamente testadas.

### F9 — Média divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** mediaResposta soma somente linhas com minutos_resposta não nulo e divide por qtd sem verificar zero. Banco vazio ou somente respostas nulas deixa qtd = 0; no PHP 8 isso gera DivisionByZeroError e interrompe a listagem. Todos os valores ainda eram transferidos ao PHP para calcular uma única média.

**Impacto:** Painel indisponível para um conjunto legítimo de dados sem primeiras respostas; trabalho e transferência desnecessários com o crescimento da tabela.

**Ação e justificativa:** AVG no banco, que ignora NULL, com COALESCE(..., 0) e conversão explícita para float. O agregado respeita a visibilidade do usuário e retorna 0.0 para conjunto sem respostas.

**Evidência e limites:** Verificados média global 77/3, cliente Bruno sem respostas, todos os tempos nulos e tabela vazia. Zero é a convenção adotada para ausência de amostras, dentro do retorno float contratado.

### F10 — Consultas N+1 para nomes dos técnicos

**Local original:** `code/lib.php:87–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Cada linha listada chama tecnicoNome, que executa outra consulta em lib.php linha 69 quando tecnico_id não é nulo. A exportação repete o padrão na linha 137. Com N chamados atribuídos, cada operação usa uma consulta principal e N consultas de técnico, inclusive para o mesmo técnico repetido.

**Impacto:** Latência e carga no banco crescem por número de chamados, com repetidas viagens de rede para dados que podem vir na consulta principal.

**Ação e justificativa:** LEFT JOIN usuarios nas consultas de listagem e exportação e COALESCE para manter o rótulo - nos chamados sem técnico. A função tecnicoNome continua disponível para consumidores legados.

**Evidência e limites:** Contador Com_select da própria conexão confirma uma SELECT em cada listagem e em cada exportação para todos os papéis. Não foram atribuídos ganhos em milissegundos sem benchmark de produção.

### F11 — Parâmetros estruturados provocam erro de tipo

**Local original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** PHP aceita login[]=ana, senha[]=x e busca[]=x como arrays. O controlador passa esses valores a funções cujos parâmetros são string, causando TypeError em vez de tratar a requisição. O cast direto de ver em array também pode produzir um ID inteiro inesperado.

**Impacto:** Erro HTTP em requisição malformada e comportamento inesperado na escolha do detalhe; não implica queda de todo o servidor.

**Ação e justificativa:** Validação is_string na fronteira HTTP. Login com campos estruturados não autentica, busca estruturada equivale à busca vazia e ver estruturado usa ID 0 e retorna inexistente. Valores escalares válidos e assinaturas das funções foram preservados.

**Evidência e limites:** Também ocorre na busca original em index.php linhas 72–73 e no detalhe linha 53. Testes HTTP cobrem login[], senha[], busca[] e ver[], sem fatal, warning ou depreciação.

### F12 — Aninhamento excessivo na classificação de prioridade

**Local original:** `code/lib.php:42–58`. **Categoria:** qualidade. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** A combinação de ausência de resposta, prioridade alta, limite de 30 minutos e prioridade 4 fica distribuída por quatro níveis de if/else. A leitura de um retorno exige acompanhar todos os ramos externos, dificultando revisar os limites e futuras alterações de SLA.

**Impacto:** Custo de manutenção e risco de regressão na classificação. Não foi identificado erro atual nos rótulos ou nos limites; este achado é de qualidade, não de regra de negócio incorreta.

**Ação e justificativa:** Retornos antecipados para ausência de resposta, prioridade normal e SLA dentro do prazo, seguidos da distinção entre crítico e alto atrasado, mantendo os textos e decisões existentes.

**Evidência e limites:** 42 casos explícitos cobrem prioridades de 0 a 5 e tempos null, -1, 0, 29, 30, 31 e 90. Prioridade 4 dentro de 30 minutos continua Alto - dentro do SLA, e não recebeu regra nova.

## Decisões

- **MD5, coluna CHAR(32) e dados de usuários existentes (F6):** A migração exige coordenar schema, autenticação e escritores de usuários não fornecidos. Mantive as contas funcionando e documentei a evolução necessária; não considero o risco resolvido.

- **Assinaturas públicas, mysqli, caminhos, rotas e HTML contratado:** São consumidos externamente. Nenhum arquivo original foi renomeado ou movido; schema.sql e seed.sql ficaram idênticos. Os testes novos estão em tests/ fora da aplicação.

- **Visão global da biblioteca sem identidade de sessão:** Scripts internos de exportação e relatório não recebem identidade por parâmetro. Preservá-los evita quebrar as assinaturas; essa modalidade é confiável e não constitui autorização para um novo endpoint público. index.php exige autenticação antes de usar as funções.

- **Curingas LIKE, ordem por criado_em e regras/rótulos de status e prioridade:** Mantidos os comportamentos existentes, inclusive % e _ na busca, o marcador - de técnico ausente e a classificação da prioridade 4 dentro do SLA. Não inventei novos estados, critérios ou desempate de datas iguais.

- **Paginação, cache, framework, novas dependências e índices adicionais:** A API deve continuar devolvendo a lista completa e o CSV todas as linhas visíveis. JOIN e agregação resolvem os custos concretos encontrados sem reestruturação ou alterações especulativas de banco.

- **Constantes de configuração legadas e integração SMTP:** Mantidos nomes e padrões não secretos. SMTP_API_KEY não é usada no fluxo fornecido; não foi criada uma integração nova. EXPORT_DIR continua definida para scripts antigos, embora o download não grave mais arquivo.

- **Rotação externa de credenciais e configuração do servidor:** A entrega remove os segredos do código, mas não acessa contas de produção nem revoga chaves. Antes da implantação, o operador deve rotacionar valores antigos, definir DB_PASS/SMTP_API_KEY e garantir HTTPS e configuração segura do PHP no ambiente real.

A neutralização de fórmulas acrescenta apóstrofo somente a títulos/nomes perigosos. Esse ajuste é visível a leitores automáticos nesses valores específicos, necessário para impedir a interpretação de texto como fórmula; os dados de exemplo e a estrutura contratada do CSV permanecem iguais. A ausência de respostas passa a produzir `0.0`, evitando uma exceção sem introduzir outro tipo de retorno. Não foram adicionados parâmetros obrigatórios nem alterados os rótulos públicos.

## Validação executada

Ambiente: PHP 8.4.26 com mysqli/mysqlnd e MariaDB 11.8.6, em instância temporária separada do serviço existente, usando integralmente `code/schema.sql` e `code/seed.sql`. **Não foi executado MySQL 8**, citado no schema; a SQL usada é comum às duas implementações, mas isso não substitui validação operacional no banco de produção. Não houve acesso a banco ou e-mail de produção.

- `php -l` em todos os arquivos PHP entregues: sem erros de sintaxe.
- `tests/regressao.php`: **121 verificações**, com banco real; contas e retornos de autenticação, rótulos, todos os papéis, filtro e detalhe, SQL injection, uma SELECT por listagem/exportação, CSV com caracteres especiais, conjunto vazio e respostas nulas. Mutações de dados de teste são revertidas em transação.
- `tests/test_http.py`: **63 verificações**, iniciando servidor PHP local e isolando arquivos de sessão temporários; rotas públicas, autenticação, renovação de ID, sessão antiga inválida, cookies, isolamento entre clientes, permissões dos dois técnicos, tabela e links, CSV e cabeçalhos, XSS e parâmetros estruturados. Log das requisições sem fatal, warning ou depreciação.
- Conferência final de JSON, IDs correspondentes no relatório, linhas originais, assinaturas públicas e arquivos preservados. Não houve benchmark de produção, teste de planilhas reais nem ensaio de carga concorrente.

Para reproduzir, crie **um banco descartável**, carregue `code/schema.sql` e depois `code/seed.sql`, e exporte `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` apontando para ele (sem credenciais de produção). Execute na raiz:

```sh
php -l code/config.php
php -l code/lib.php
php -l code/index.php
php -l tests/regressao.php
php tests/regressao.php
python3 tests/test_http.py
```

Os testes exigem os dados de exemplo originais e acesso de leitura/escrita ao banco descartável. Python usa somente a biblioteca padrão e não é dependência da aplicação. O teste HTTP inicia e encerra seu próprio servidor; o teste PHP mantém a saída CSV em buffer para validá-la.
