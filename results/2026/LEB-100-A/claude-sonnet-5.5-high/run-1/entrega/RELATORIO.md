# RELATORIO — LEB-100-A

## 1. Resumo

O sistema é um painel de chamados em PHP/mysqli de ~250 linhas. Não havia **nenhum controle de autorização**: a regra "cliente só vê os próprios chamados" do manifesto não era aplicada em lugar algum (detalhe, listagem e CSV), havia **SQL injection** na busca, **XSS refletido**, credenciais de produção no código e senhas em MD5 sem sal. Também havia um bug que derruba a página inicial (divisão por zero), N+1 consultas e um CSV gravado em arquivo fixo compartilhado.

Foram corrigidos 13 dos 18 achados, mantendo nomes, assinaturas (apenas parâmetros opcionais finais adicionados), rotas, formato do CSV, HTML da tabela e rótulos. Os demais estão documentados com motivo para não mudar. Testei contra um MariaDB local isolado com os dados de seed, comparando original e nova versão: a listagem de técnico é idêntica byte a byte, o CSV mantém cabeçalho/ordem/rótulos, e cliente só vê/exporta o que abriu.

## 2. Achados (em ordem de prioridade de correção)

### F1 — SQL injection na busca por título

- **Onde:** `code/lib.php` linhas 80-85 (numeração original)
- **Categoria / severidade / confiança:** seguranca / critica / 98
- **Mecanismo:** listarChamados concatena $busca (vinda de $_GET['busca'] em index.php:72) direto em "WHERE titulo LIKE '%...%'" e executa com $db->query(). Um valor como %' UNION SELECT ... -- fecha o literal e injeta SQL; mesmo uma aspa simples ('O'Brien') quebra a consulta. A rota é acessível a qualquer usuário logado, inclusive cliente.
- **Impacto:** Leitura de qualquer tabela (inclusive usuarios.senha, hashes md5 sem sal, quebráveis), e dependendo dos privilégios do usuário 'painel', alteração/remoção de dados.
- **O que fiz:** Consulta passou a usar prepared statement (LIKE ? com bind_param). Assinatura mantida; adicionado apenas parâmetro opcional final. (corrigido)

### F2 — Chamados de outros clientes visíveis no detalhe (IDOR / quebra da regra de visibilidade)

- **Onde:** `code/lib.php` linhas 98-102 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 97
- **Mecanismo:** index.php:53 chama verChamado($db,(int)$_GET['ver']) e verChamado faz SELECT * FROM chamados WHERE id = N sem considerar o usuário da sessão. $_SESSION['papel'] é guardado no login mas nunca consultado. Qualquer cliente logado abre index.php?ver=103 e lê título e descrição de chamado de outro cliente (confirmado em teste: ana vê o chamado 103 do bruno).
- **Impacto:** Vazamento de dados de outros clientes (descrição, status, SLA) por enumeração de ids sequenciais.
- **O que fiz:** verChamado ganhou parâmetro opcional ?int $usuarioId = null (retrocompatível: omitido = sem filtro, como as rotinas internas usam). index.php passa o uid quando o papel não é 'tecnico'; chamado alheio devolve null e a tela mostra 'Chamado nao encontrado' (não revela existência). Técnico continua vendo tudo. (corrigido)

### F3 — Listagem mostra chamados de todos os clientes

- **Onde:** `code/lib.php` linhas 78-93 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 97
- **Mecanismo:** listarChamados usa SELECT * FROM chamados sem filtro de dono e index.php:73 não restringe por papel. Todo cliente vê título, status, prioridade e técnico de todos os chamados na tabela #tabela-chamados (confirmado: ana e bruno listavam os 5 chamados).
- **Impacto:** Exposição ampla de dados de clientes a qualquer cliente autenticado; contraria a regra de negócio do manifesto.
- **O que fiz:** Parâmetro opcional ?int $usuarioId = null em listarChamados; index.php filtra por uid para papel diferente de 'tecnico' (falha fechada). Colunas, ordem e HTML da tabela inalterados; saída para técnico é idêntica byte a byte à original. (corrigido)

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário e usa arquivo compartilhado em disco

- **Onde:** `code/lib.php` linhas 123-151 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 92
- **Mecanismo:** (a) exportarCsv exporta SELECT * FROM chamados sem filtro e index.php:44-46 o chama para qualquer papel: um cliente baixa todos os chamados. (b) grava sempre em EXPORT_DIR/chamados.csv (nome fixo, /var/www/painel/tmp, possivelmente dentro do docroot) e depois faz readfile: requisições concorrentes sobrescrevem o mesmo arquivo (usuário A pode receber o CSV do B, ou arquivo truncado) e os dados ficam persistidos em disco, legíveis por URL se o diretório for servido. (c) se fopen/query falham a função retorna sem saída ou deixa $fp aberto e arquivo parcial.
- **Impacto:** Vazamento total da base de chamados por um cliente; condição de corrida entre downloads; dados pessoais permanecendo em /tmp do painel.
- **O que fiz:** CSV agora é escrito direto em php://output (sem arquivo intermediário), com a consulta executada antes dos headers. Parâmetro opcional ?int $usuarioId = null; index.php o passa para clientes. Cabeçalho, ordenação por id, rótulos de status e formato permanecem (verificado em teste). (corrigido)

### F5 — XSS refletido no campo e na mensagem de busca

- **Onde:** `code/index.php` linhas 79-82 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 97
- **Mecanismo:** $busca = $_GET['busca'] é impresso sem escape em value="..." (linha 79) e em 'Resultados para: ...' (linha 82). Um link index.php?busca="><script>...</script> executa JS no contexto da sessão de quem clicar (GET, sem CSRF/token, fácil de distribuir).
- **Impacto:** Execução de script na sessão do usuário (técnico ou cliente), leitura de dados do painel e ações em seu nome.
- **O que fiz:** Saída escapada com helper h() (htmlspecialchars com ENT_QUOTES|ENT_SUBSTITUTE, UTF-8), também aplicado às demais saídas já escapadas. (corrigido)

### F6 — Segredos embutidos no código (senha de produção do banco e chave SMTP)

- **Onde:** `code/config.php` linhas 11-15 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 95
- **Mecanismo:** DB_PASS tem fallback literal 'N3tX@2013!prod' (getenv(...) ?: '...') e SMTP_API_KEY é constante literal. Quem tiver acesso ao código/pacote/backup/VCS obtém credenciais de produção; o fallback também mascara a ausência da variável de ambiente.
- **Impacto:** Comprometimento do banco de produção e do serviço de e-mail a partir de qualquer cópia do código.
- **O que fiz:** Removidos os valores literais: DB_PASS e SMTP_API_KEY lêem apenas do ambiente (log de erro se DB_PASS faltar). As credenciais antigas devem ser consideradas vazadas e rotacionadas; é pré-requisito de deploy que DB_PASS esteja definido. (corrigido)

### F7 — Senhas armazenadas como MD5 sem sal

- **Onde:** `code/lib.php` linhas 15-20 (numeração original)
- **Categoria / severidade / confiança:** seguranca / alta / 95
- **Mecanismo:** autenticar calcula md5($senha) e compara na consulta; a coluna usuarios.senha é CHAR(32) (schema.sql:7). MD5 rápido e sem sal: senhas iguais geram hashes iguais (ana e bruno têm o mesmo hash) e um vazamento (p.ex. via SQLi do F1) é revertido por rainbow table/GPU em segundos. Não há limite de tentativas de login.
- **Impacto:** Recuperação em massa das senhas após qualquer leitura da tabela; reuso de credenciais em outros sistemas.
- **O que fiz:** Não alterado: exige migração de schema (CHAR(32) → VARCHAR(255)) e rehash progressivo no login, a coordenar com a operação. Proposta: ALTER TABLE, autenticar tenta password_verify e, se o hash for md5 legado, valida e regrava com password_hash (try/catch), mantendo a assinatura. (não corrigido — apenas reportado)

### F8 — Sessão: sem regenerar id no login e cookie sem flags

- **Onde:** `code/index.php` linhas 15-26 (numeração original)
- **Categoria / severidade / confiança:** seguranca / media / 75
- **Mecanismo:** session_start() usa os defaults do PHP e, após autenticar (linhas 24-25), o id de sessão não é regenerado: um id fixado previamente pelo atacante passa a ser autenticado (fixation). O cookie não era HttpOnly/SameSite/Secure explicitamente, dependendo do php.ini.
- **Impacto:** Sequestro de sessão por fixação ou roubo do cookie via XSS.
- **O que fiz:** session_set_cookie_params (httponly, samesite=Lax, secure quando HTTPS) antes do session_start e session_regenerate_id(true) após login válido. (corrigido)

### F9 — Divisão por zero em mediaResposta derruba a listagem

- **Onde:** `code/lib.php` linhas 116-116 (numeração original)
- **Categoria / severidade / confiança:** bug / media / 92
- **Mecanismo:** return $soma / $qtd com $qtd == 0 quando nenhum chamado tem minutos_resposta. No PHP 8 isso lança DivisionByZeroError; index.php:74 chama mediaResposta antes de qualquer saída, então a página inicial retorna 500 (reproduzido: com todos os minutos_resposta NULL a original devolve 500).
- **Impacto:** Painel inteiro indisponível em um banco novo ou após limpeza de dados, mesmo para técnicos.
- **O que fiz:** Média calculada por AVG() no banco; devolve 0.0 quando não há dados. Mesmo valor nos dados de teste (26 min). (corrigido)

### F10 — N+1 consultas ao buscar nome do técnico

- **Onde:** `code/lib.php` linhas 64-93 (numeração original)
- **Categoria / severidade / confiança:** performance / media / 90
- **Mecanismo:** listarChamados (linha 89) e exportarCsv (linha 137) chamam tecnicoNome() por linha, executando um SELECT em usuarios para cada chamado: 1+N consultas por listagem/export, concatenando o id na SQL.
- **Impacto:** Latência da listagem e do export cresce linearmente com o número de chamados.
- **O que fiz:** LEFT JOIN usuarios com COALESCE(nome,'-') em listarChamados e exportarCsv (chave tecnico_nome preservada, '-' para sem técnico). tecnicoNome foi mantida (prepared statement) por compatibilidade. (corrigido)

### F11 — mediaResposta traz todas as linhas para o PHP para somar

- **Onde:** `code/lib.php` linhas 109-115 (numeração original)
- **Categoria / severidade / confiança:** performance / baixa / 85
- **Mecanismo:** SELECT minutos_resposta de todos os chamados com resposta e loop em PHP, quando o banco calcula AVG diretamente.
- **Impacto:** Transferência e memória desnecessárias a cada carga da página inicial.
- **O que fiz:** Substituído por SELECT AVG(...) (junto com F9). (corrigido)

### F12 — TypeError (500) com parâmetros em forma de array

- **Onde:** `code/index.php` linhas 22-22 (numeração original)
- **Categoria / severidade / confiança:** bug / baixa / 85
- **Mecanismo:** autenticar() e listarChamados() têm tipo string; POST login[]=x ou GET busca[]=x passam array e disparam TypeError não tratado, resultando em 500 por entrada trivial.
- **Impacto:** Erro 500 provocado por qualquer cliente; ruído em logs.
- **O que fiz:** index.php valida is_string() em login, senha e busca (valor inválido tratado como vazio). (corrigido)

### F13 — Falha de conexão pode vazar detalhes por exceção não tratada

- **Onde:** `code/index.php` linhas 9-12 (numeração original)
- **Categoria / severidade / confiança:** seguranca / baixa / 50
- **Mecanismo:** Em PHP >= 8.1 o mysqli lança exceção em falha de conexão; o if ($db->connect_errno) nunca é alcançado e a stack trace (com host/usuário, e dependendo do ini, argumentos) pode ir para o cliente se display_errors estiver ligado.
- **Impacto:** Divulgação de informação de infraestrutura quando o banco cai.
- **O que fiz:** new mysqli envolvido em try/catch; resposta 500 com mensagem genérica. (corrigido)

### F14 — CSV sujeito a injeção de fórmula (títulos controlados por cliente)

- **Onde:** `code/lib.php` linhas 138-144 (numeração original)
- **Categoria / severidade / confiança:** seguranca / media / 60
- **Mecanismo:** O título do chamado, digitado pelo cliente, vai para o CSV sem neutralizar valores iniciados em =, +, -, @. Um técnico que abra o arquivo no Excel pode executar fórmulas (ex.: =HYPERLINK/DDE).
- **Impacto:** Execução de fórmula na estação de quem abre o export.
- **O que fiz:** Não alterado: prefixar com apóstrofo altera os valores do CSV, que é contrato consumido por scripts de exportação/relatório. Recomenda-se tratar no consumidor (planilha) ou combinar a mudança com os donos desses scripts. (não corrigido — apenas reportado)

### F15 — Sem limitação de tentativas de login

- **Onde:** `code/index.php` linhas 20-35 (numeração original)
- **Categoria / severidade / confiança:** seguranca / media / 70
- **Mecanismo:** O login aceita tentativas ilimitadas e não dá feedback; combinado com MD5 (F7) e senhas fracas de exemplo ('senha123'), força bruta é barata.
- **Impacto:** Adivinhação de senhas.
- **O que fiz:** Não alterado: exige estado compartilhado (tabela/cache/WAF) fora do escopo desta evolução; recomendação de rate limit no proxy ou tabela de tentativas. (não corrigido — apenas reportado)

### F16 — Listagem sem paginação e busca LIKE '%x%' sem índice

- **Onde:** `code/lib.php` linhas 78-93 (numeração original)
- **Categoria / severidade / confiança:** performance / baixa / 60
- **Mecanismo:** listarChamados devolve todas as linhas e index.php imprime a tabela inteira; LIKE com % inicial força full scan em chamados. Com anos de histórico o payload e o tempo crescem sem limite.
- **Impacto:** Lentidão da página inicial em bases grandes.
- **O que fiz:** Não alterado: paginar muda o contrato da listagem (tabela completa) e do retorno de listarChamados. (não corrigido — apenas reportado)

### F17 — rotuloPrioridade com ifs aninhados em 4 níveis

- **Onde:** `code/lib.php` linhas 40-59 (numeração original)
- **Categoria / severidade / confiança:** qualidade / baixa / 80
- **Mecanismo:** Quatro níveis de if/else aninhados escondem a árvore de decisão; difícil de revisar a regra de SLA.
- **Impacto:** Risco de erro em manutenção futura da regra.
- **O que fiz:** Reescrita com guard clauses; verificado equivalente para prioridades -1..5 × minutos {null,-5,0,29,30,31,1000} (0 diferenças). (corrigido)

### F18 — Camada de apresentação e acesso a dados misturados em index.php

- **Onde:** `code/index.php` linhas 1-97 (numeração original)
- **Categoria / severidade / confiança:** arquitetura / baixa / 60
- **Mecanismo:** index.php faz conexão, sessão, autorização, roteamento e HTML com echo; só lib.php está separado, o que facilitou esquecer a autorização (F2-F4).
- **Impacto:** Manutenção difícil; autorização espalhada.
- **O que fiz:** Não alterado: reestruturar seria reescrita. A regra de visibilidade foi centralizada em $filtroUsuario e passada às funções de lib.php. (não corrigido — apenas reportado)

## 3. Decisões — o que não mudei e por quê

- **Assinaturas públicas de lib.php:** Somente foram adicionados parâmetros opcionais finais (?int $usuarioId = null) em listarChamados, verChamado e exportarCsv; chamadas existentes (rotina noturna, relatório gerencial) continuam válidas e sem filtro.
- **Migração de MD5 para password_hash (F7):** Requer ALTER TABLE em produção e rollout de rehash no login; fazer sem a migração poderia truncar hashes em CHAR(32). Reportado com plano.
- **formatarStatus devolve 'Resolvido' para qualquer valor fora de 1 e 2:** O manifesto fixa apenas 1/2/3; mudar o default alteraria rótulos consumidos pelo relatório gerencial.
- **rotuloPrioridade: chamado crítico sem 1a resposta aparece como 'Aguardando 1a resposta' e crítico dentro de 30 min como 'Alto - dentro do SLA':** Pode ser decisão de negócio e o texto é consumido; apenas reestruturei o código sem alterar nenhuma saída.
- **Média de resposta no topo continua global para clientes:** É um agregado, não expõe chamados individuais; escopá-la mudaria o indicador mostrado. Avaliar com o produto.
- **CSV continua com 'Aberto em' entre aspas e mesmo formato do fputcsv:** Comportamento idêntico ao original; apenas passei o parâmetro $escape explicitamente para evitar o aviso de depreciação do PHP 8.4 sem mudar a saída.
- **Curingas % e _ em busca continuam funcionando como curingas LIKE:** Comportamento atual; escapar mudaria resultados de buscas existentes e não tem impacto de segurança.
- **Status HTTP 200 para 'Chamado nao encontrado':** Manter o comportamento observável por consumidores/favoritos; mudar para 404 é opcional.
- **Papel em $_SESSION['papel'] não é revalidado a cada requisição:** Rebaixar um usuário só vale no próximo login; exigiria consulta por requisição. Falha fechada: papel diferente de 'tecnico' é tratado como cliente.
- **EXPORT_DIR permanece definido em config.php:** Pode ser lido por outros scripts; exportarCsv deixou de gravar em disco, mas a constante foi mantida. Se a rotina noturna dependia do arquivo chamados.csv gerado como efeito colateral, ela precisará capturar a saída da função (o manifesto só garante a escrita na saída).
- **schema.sql e seed.sql:** Sem alteração: dados de teste e contrato do banco preservados.

## 4. Pré-requisitos de deploy

1. Definir `DB_PASS` (e `SMTP_API_KEY`, se usada) no ambiente do PHP; sem isso a conexão falha com 500 e mensagem genérica.
2. Rotacionar a senha do banco e a chave SMTP que estavam no código.
3. Se alguma rotina lia `EXPORT_DIR/chamados.csv`, ajustá-la para consumir a saída de `exportarCsv` (ver Decisões).
4. Planejar a migração de hashes (F7) e rate limit de login (F15).

## 5. Verificação realizada

MariaDB isolado com `schema.sql` + `seed.sql`; servidor embutido do PHP 8.4 rodando original e corrigido lado a lado. Confirmei: IDOR no original (ana lê chamado 103; ana/bruno listam 5 chamados), corrigido (ana vê 101/102/105, `ver=103` → "não encontrado"); técnico inalterado; CSV de técnico com 5 linhas e de cliente só com os seus; escape de XSS e busca com aspas funcionando; `busca[]`/`login[]` sem 500; 500 do original sem dados de SLA vs. 200 no corrigido; rotuloPrioridade equivalente em 49 combinações; média igual (26 min). Não executei teste de injeção UNION em larga escala: a correção é por prepared statement. Não há suíte de testes no pacote.
