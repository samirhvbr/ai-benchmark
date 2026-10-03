# Relatório técnico — LEB-100-A (Painel de Chamados, NetX ISP)

Instância LEB-100-A (nível 100, versão 1.1) · matriz `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

## Resumo

O sistema é um painel PHP/mysqli pequeno (~270 linhas de PHP) que funciona nos casos felizes, mas estava aberto por vários lados. O defeito mais grave é uma **injeção de SQL na busca** (F1), que entrega o banco inteiro, hashes de senha incluídos, a qualquer cliente logado. Logo atrás vem a **regra de visibilidade do manifesto, que o código simplesmente não implementa**: o detalhe, a listagem e o CSV mostram todos os chamados a qualquer usuário (F2, F3, F4). Somam-se XSS refletido (F5), a senha do banco e a chave de e-mail em texto puro (F6, F8), md5 sem sal (F7) e fixação de sessão (F9). O CSV tem defeitos próprios: exportações simultâneas se corrompem em silêncio (F10) e um título pode forjar colunas e registros (F15). Em desempenho, a listagem e o CSV fazem 1 + N consultas (F13, F14); em robustez, a média de SLA divide por zero e derruba a página principal (F12) e entradas em array causam erro fatal (F19, F20).

Corrigi 23 dos 25 achados, **sem alterar nenhuma assinatura pública nem o contrato do manifesto**: as sete funções de lib.php mantêm nome, parâmetros, tipos e formato de retorno; rotas, parâmetros GET, tabela da listagem, cabeçalho e ordem do CSV e rótulos de status seguem iguais; a visibilidade entrou por funções novas e aditivas. O diff é uma evolução, não uma reescrita: todas as funções originais continuam onde estavam, com os mesmos nomes (lib.php passou de 151 para ~360 linhas, boa parte comentário e funções auxiliares pequenas). Ficaram sem correção dois achados, por contrato ou escopo: F24 (rótulo de status fora do domínio) e F25 (limite de tentativas de login). O que deliberadamente não mudei está em **Decisões**.

Validação (PHP 8.4.26 e MariaDB 11.8 numa instância privada, com o schema e o seed originais): roteiro HTTP de 109 cenários executado no original e no alterado, para os quatro usuários de teste e para o anônimo; teste diferencial de funções (756 linhas de `var_export`, que preserva os tipos int/string) em que a **única diferença** é F12 (de exceção para 0.0); para técnicos, o HTML da listagem e o CSV são idênticos aos do original nos cenários normais. Mais de 80 agentes independentes revisaram o código e atacaram a correção; o que eles reproduziram na minha primeira versão está corrigido e listado em **Como validei**.

**Antes de publicar, faça três coisas fora do código** (seção Implantação): definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP, rotacionar as duas credenciais (estiveram em texto puro) e, **só depois de a nova versão estar estável**, alargar a coluna de senha com o `ALTER TABLE` (é o ponto sem volta da migração para bcrypt).

## Achados, na ordem em que eu priorizaria a correção

Linhas se referem à numeração **original** dos arquivos recebidos. A confiança é a probabilidade, de 0 a 100, de o problema ser real.

### F1 — SQL injection na busca: o termo vai concatenado dentro do LIKE de listarChamados()

- **Onde:** `code/lib.php:82-85`
- **Categoria / severidade / confiança:** seguranca · critica · 99
- **Mecanismo:** listarChamados() monta `WHERE titulo LIKE '%<busca>%'` concatenando $busca (que index.php:72-73 recebe de $_GET['busca'] sem tratamento) entre aspas simples; uma aspa fecha o literal e o resto vira SQL. mysqli::query() não aceita instruções empilhadas, então o alcance é a leitura de qualquer tabela que o usuário do banco enxergue. Também quebra buscas legítimas: um título com apóstrofo (O'B, D'Avila) derruba a página com erro fatal de sintaxe.
- **Impacto:** Qualquer usuário autenticado (basta uma conta de cliente) lê o banco inteiro: os hashes md5 de todas as senhas (quebráveis em segundos, o que entrega contas de técnico), os chamados de todos os clientes e metadados do servidor. Anula também a regra de visibilidade do manifesto.
- **O que fiz:** consultarChamados() (nova, usada por listarChamados) passa a usar consulta preparada: `c.titulo LIKE ?` com bind_param e o valor `%termo%`. Os curingas % e _ digitados continuam valendo como sempre valeram; só muda a leitura da barra invertida (ver Decisões). A assinatura listarChamados(mysqli $db, string $busca = ''): array e o formato do retorno (colunas de chamados mais tecnico_nome, tudo string ou null) não mudaram.
- **Evidência:** No original, busca=zzz' UNION SELECT 900,1,NULL,CONCAT(login,':',senha),'x',1,2,NULL,NOW() FROM usuarios-- - devolve na tabela da listagem as linhas 'ana:<md5>', 'bruno:<md5>', 'carla:<md5>' e 'diego:<md5>'; busca=' e busca=O'B derrubam a página com mysqli_sql_exception. No alterado o UNION devolve tabela vazia, o apóstrofo funciona e, para as buscas comuns, o resultado é idêntico ao do original (16 termos comparados, inclusive com curingas).
- **Corrigido:** sim

### F2 — Detalhe do chamado (?ver=) não confere o dono: qualquer usuário logado lê o chamado de qualquer cliente

- **Onde:** `code/index.php:52-67`
- **Categoria / severidade / confiança:** seguranca · alta · 98
- **Mecanismo:** index.php:53 chama verChamado($db, (int) $_GET['ver']) (lib.php:98-102: `SELECT * FROM chamados WHERE id = N`, sem usuario_id) e imprime título, status, prioridade e descrição. $papel é lido da sessão na linha 39 e nunca mais usado; o uid da sessão não entra em consulta alguma. A regra do manifesto (cliente só vê o que abriu) simplesmente não existe no código.
- **Impacto:** Um cliente percorre ids sequenciais e lê todos os chamados dos demais (texto livre: problemas de cobrança, endereços, relatos). Viola a regra de negócio de visibilidade.
- **O que fiz:** Depois de verChamado(), index.php confere chamadoVisivel($c, $uid, $papel) (nova em lib.php): técnico vê qualquer chamado; cliente só se usuario_id == uid; qualquer outro papel é tratado como cliente. Sem permissão a resposta é idêntica à de id inexistente ('Chamado nao encontrado.', status 200 como antes), para não revelar quais ids existem. verChamado() ficou inalterada.
- **Evidência:** Original: ana (cliente) abre ?ver=103 e ?ver=104 e recebe 'Troca de plano' e 'Fatura em duplicidade / Cobranca repetida no cartao', chamados do bruno. Alterado, matriz 4 usuários x 6 ids: ana vê 101, 102, 105; bruno vê 103, 104; carla e diego veem todos; id 999 segue 'não encontrado' para todos. Verificadores independentes testaram formas estranhas de ?ver (0103, 103abc, 1e2, +103, arrays, parâmetros repetidos) e 1.261 combinações de usuário e id, sem achar desvio.
- **Corrigido:** sim

### F3 — A listagem e a busca mostram todos os chamados a qualquer usuário: a regra de visibilidade nunca é aplicada

- **Onde:** `code/index.php:72-74`
- **Categoria / severidade / confiança:** seguranca · alta · 97
- **Mecanismo:** index.php:73 chama listarChamados($db, $busca), cujo SQL (lib.php:80-84) é `SELECT * FROM chamados` sem filtro de dono. $uid e $papel existem (index.php:38-39), mas $papel nunca é lido e $uid não entra em consulta nenhuma.
- **Impacto:** Todo cliente vê id, título, status, prioridade e técnico dos chamados de todos os outros clientes, e os ids alimentam a enumeração do ?ver= (F2).
- **O que fiz:** listarChamadosVisiveis($db, $uid, $papel, $busca) (nova): técnico sem filtro; qualquer outro papel ganha `c.usuario_id = ?` no próprio SQL, combinado com a busca. index.php passa a usá-la. listarChamados() ficou igual (sem restrição) para os scripts internos (rotina noturna, relatório gerencial).
- **Evidência:** Original: ana e bruno recebem os 5 chamados (101 a 105). Alterado: ana vê 101, 102, 105; bruno vê 103, 104; técnicos veem os 5. Para técnicos o HTML da listagem é idêntico ao do original nos cenários normais.
- **Corrigido:** sim

### F4 — A exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php:44-47`
- **Categoria / severidade / confiança:** seguranca · alta · 96
- **Mecanismo:** index.php:44-47 chama exportarCsv($db) para qualquer sessão autenticada; a função (lib.php:132) faz `SELECT * FROM chamados ORDER BY id` sem filtro. É a terceira via pela qual a regra de visibilidade é ignorada, além de F2 e F3.
- **Impacto:** Com um clique um cliente baixa o conjunto completo de chamados, inclusive os de outros clientes e o nome do técnico de cada um.
- **O que fiz:** exportarCsvVisivel($db, $uid, $papel) (nova) aplica a mesma regra (escopoDeVisibilidade) da listagem: técnico baixa tudo, cliente só os seus. exportarCsv($db) continua igual, para todos os chamados, atendendo a rotina noturna e o relatório gerencial. O CSV do técnico é idêntico byte a byte ao do original.
- **Evidência:** Original: ana baixa 5 linhas (101 a 105). Alterado: ana recebe 101, 102, 105; bruno 103, 104; carla e diego 101 a 105. Cabeçalho, ordem por id e rótulos de status inalterados; com 60 mil chamados o CSV do alterado tem o mesmo md5 do original.
- **Corrigido:** sim

### F5 — XSS refletido: o termo da busca é impresso sem escape no atributo value e no parágrafo 'Resultados para'

- **Onde:** `code/index.php:79-82`
- **Categoria / severidade / confiança:** seguranca · alta · 97
- **Mecanismo:** index.php:79 concatena $busca dentro de value="..." e index.php:82 dentro de <p>...</p>, sem htmlspecialchars (os demais campos da página são escapados; estes dois ficaram de fora). A rota é GET, então basta um link.
- **Impacto:** Quem clicar num link com busca="><script>...</script> executa script na origem do painel com a sessão da vítima: lê qualquer página, baixa o CSV e, como o cookie de sessão do PHP não é HttpOnly por padrão, pode roubá-lo.
- **O que fiz:** O termo é escapado uma vez, com htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), e esse valor é usado nos dois pontos. Para termos comuns o HTML é idêntico ao anterior. O cookie de sessão passou a sair com HttpOnly e SameSite=Lax (F9).
- **Evidência:** Original, busca="><script>alert(1)</script>: a resposta contém `value=""><script>alert(1)</script>"` e `<p>Resultados para: "><script>alert(1)</script></p>`. Alterado: `value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"`. Um verificador testou também onfocus, </p><img onerror>, <svg/onload>, entidades e bytes inválidos: tudo escapado.
- **Corrigido:** sim

### F6 — Senha do usuário de produção do banco embutida como fallback de DB_PASS

- **Onde:** `code/config.php:11-12`
- **Categoria / severidade / confiança:** seguranca · alta · 97
- **Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: '<literal>')`: se a variável de ambiente não existir (ambiente novo, container, esquecimento no deploy) o sistema conecta com a senha do usuário de produção que está no código-fonte. O `?:` também trata variável vazia ou '0' como ausente. A credencial viaja com qualquer cópia, backup, repositório ou pacote deste código, em produção desde 2013.
- **Impacto:** Quem tem acesso de leitura ao código obtém a credencial de leitura e escrita do banco de produção; a senha deve ser considerada comprometida. O fallback ainda faz ambientes de teste falarem com o banco de produção sem aviso.
- **O que fiz:** Removido o literal: DB_PASS vem só de getenv e, sem a variável, fica vazio (a conexão falha fechada; o teste é `!== false`, então senha vazia ou '0' vale). Ação obrigatória fora do código: definir DB_PASS no ambiente do PHP e ROTACIONAR a senha do usuário do banco, porque ela já vazou para o histórico.
- **Evidência:** Conferido no alterado: nenhum literal de segredo resta em code/. Sem DB_PASS a conexão é recusada e o painel responde 500 com 'Falha ao conectar ao banco.' (F17).
- **Corrigido:** sim

### F7 — Senhas guardadas como md5 puro, sem sal (coluna CHAR(32))

- **Onde:** `code/lib.php:15-17`
- **Categoria / severidade / confiança:** seguranca · alta · 93
- **Mecanismo:** autenticar() calcula md5($senha) e compara no SQL (`... AND senha = ?`). md5 é rápido e sem sal: o hash de 'senha123' é o mesmo para ana e bruno, o de 'tecmaster' é o mesmo para carla e diego (visível em seed.sql), e tabelas de arco-íris ou força bruta recuperam senhas fracas em segundos. A coluna CHAR(32) (schema.sql:7) nem comporta um hash moderno.
- **Impacto:** Qualquer vazamento da tabela usuarios (a própria F1 entrega essa tabela) vira senhas em texto puro, com reaproveitamento em outros sistemas e acesso a contas de técnico.
- **O que fiz:** autenticar() busca a linha por login e confere em PHP: hash legado de 32 hex com hash_equals(md5), qualquer outro com password_verify. No primeiro login correto de uma conta legada, migrarHashDeSenha() troca o md5 por password_hash(), mas só se a coluna comportar (60 caracteres; consulta information_schema ANTES de calcular o hash, para não truncar nem gastar CPU em vão com CHAR(32)), nunca para senha com NUL ou com 72 bytes ou mais (o bcrypt as truncaria; essas contas ficam em md5, que confere a senha inteira) e sempre em try/catch, sem poder impedir o login. Como o bcrypt custa ~0,28 s de CPU (custo 12 do PHP 8.4), toda falha de login passa a gastar o mesmo tempo quando já pode haver contas migradas (recusarLogin); sem isso a demora revelaria quais logins existem. schema.sql declara VARCHAR(255). O retorno ['id','nome','papel'] não mudou e o hash nunca sai da função; as contas de teste continuam entrando. O efeito pleno exige o ALTER TABLE da seção Implantação; até lá nada é gravado nem corrompido.
- **Evidência:** Schema original CHAR(32): hashes intactos após o roteiro, login correto em ~3 ms (a 1ª versão gastava ~280 ms por hash descartado). Schema VARCHAR(255): os usuários migram para bcrypt no 1º login e o 2º login funciona; medido em tempo de CPU, todas as classes testadas (login inexistente, md5 errado, bcrypt errado, bcrypt certo) ficam em 274-282 ms, sem diferença que revele a existência da conta. Senhas com NUL ou de 72 bytes ou mais não migram e continuam conferindo inteiras; 'senha123' + NUL + lixo é recusada em conta migrada. Diferencial de funções idêntico ao original, nos dois schemas (meu teste com 10 pares de credenciais; um verificador com 31).
- **Corrigido:** sim

### F8 — Chave da API de e-mail transacional embutida em texto puro no código

- **Onde:** `code/config.php:15`
- **Categoria / severidade / confiança:** seguranca · alta · 90
- **Mecanismo:** `define('SMTP_API_KEY', '<literal>')` sem sequer o getenv do padrão das outras constantes. index.php e lib.php não referenciam a constante, então no código recebido ela só serve para expor a chave; qualquer script do ISP que a use herda o segredo do repositório.
- **Impacto:** Quem lê o código pode enviar e-mail pela conta transacional da NetX (phishing em nome do ISP, consumo de cota, reputação do domínio).
- **O que fiz:** A constante passa a vir de getenv('SMTP_API_KEY') (padrão vazio). Fora do código: definir a variável no ambiente dos scripts que enviam e-mail e ROTACIONAR a chave.
- **Evidência:** Nenhum literal de segredo resta em code/config.php.
- **Corrigido:** sim

### F9 — Sem regeneração do id de sessão no login: fixação de sessão, e cookie sem HttpOnly/SameSite

- **Onde:** `code/index.php:15-28`
- **Categoria / severidade / confiança:** seguranca · media · 95
- **Mecanismo:** index.php:15 chama session_start() com o padrão do PHP (session.use_strict_mode=0), que adota qualquer PHPSESSID enviado pelo cliente, e o bloco de login (linhas 23-28) grava uid/papel na sessão sem session_regenerate_id(). O id que o atacante plantou passa a ser a sessão autenticada da vítima.
- **Impacto:** Quem consegue plantar um cookie na vítima (link com o id, subdomínio, XSS como o de F5) sequestra a sessão de um técnico depois que ele entra. Sem HttpOnly, um XSS também lê o cookie.
- **O que fiz:** session_start() passa a receber as opções use_strict_mode, cookie_httponly, cookie_samesite=Lax (a partir do PHP 7.3) e cookie_secure quando a requisição é HTTPS, em vez de ini_set (que pode estar em disable_functions). Depois de autenticar, session_regenerate_id(true).
- **Evidência:** Original: login da vítima com PHPSESSID forjado pelo atacante e, em seguida, o atacante reutiliza o id forjado e entra como carla (técnica). Alterado: o id forjado é descartado, o id muda no login (pré e pós-login diferentes) e o atacante não entra. Set-Cookie passa de `PHPSESSID=<id>; path=/` para `...; HttpOnly; SameSite=Lax` (e Secure quando $_SERVER['HTTPS'] está ligado). Sessões abertas pelo código original continuam válidas.
- **Corrigido:** sim

### F10 — Exportações simultâneas se corrompem: todas gravam e relêem o mesmo arquivo fixo (resposta truncada e cheia de bytes NUL, com HTTP 200)

- **Onde:** `code/lib.php:125-150`
- **Categoria / severidade / confiança:** bug · media · 95
- **Mecanismo:** exportarCsv() grava em EXPORT_DIR . '/chamados.csv' (caminho fixo, aberto com 'w', que trunca), fecha e só então faz readfile() do mesmo caminho para o navegador. Se outra exportação abre o arquivo no meio, ela o trunca; a primeira continua gravando no deslocamento antigo e deixa um buraco de NULs, e o seu readfile() entrega o arquivo furado (ou vazio) com HTTP 200 e Content-Type text/csv. A disputa acontece entre sessões diferentes; na mesma sessão o lock de sessão do PHP serializa as requisições.
- **Impacto:** O relatório gerencial, a rotina noturna e quem baixa o CSV recebem linhas faltando ou bytes NUL sem nenhum erro, isto é, dados de chamados perdidos em silêncio. A janela cresce com o volume (a exportação demora, por causa do 1 + N consultas) e com o número de pessoas exportando.
- **O que fiz:** O CSV é escrito direto em php://output, linha a linha, por escreverCsv(), sem arquivo intermediário: não existe mais recurso compartilhado entre requisições. exportarCsv() e exportarCsvVisivel() só diferem na regra de visibilidade, e a consulta roda antes do primeiro cabeçalho HTTP.
- **Evidência:** Reproduzido no original, com sessões distintas: com 4.005 chamados e duas exportações sobrepostas (a 2ª começa 1,5 s depois da 1ª), 12 de 12 respostas saíram corrompidas (um exemplo: 5.947 linhas e 3.194.910 bytes NUL); com o seed de 5 chamados e 6 sessões exportando ao mesmo tempo, 719 de 720 respostas divergiram (2 vazias, 717 com NUL). Alterado: 0 de 12 e 0 de 720. Dois revisores independentes obtiveram o mesmo efeito. Minha primeira tentativa usou uma sessão só e não reproduziu, porque o lock de sessão serializou as requisições.
- **Corrigido:** sim

### F11 — Cada exportação deixa em disco, em caminho previsível, uma cópia de todos os chamados

- **Onde:** `code/lib.php:125-126`
- **Categoria / severidade / confiança:** seguranca · media · 55
- **Mecanismo:** O caminho fixo EXPORT_DIR/chamados.csv (config.php:18, /var/www/painel/tmp, dentro da árvore do aplicativo) nunca é apagado depois do readfile() (lib.php:150): o arquivo, com permissão 0664, mantém título, status, técnico e data de todos os clientes até a próxima exportação. Se o diretório for servido pelo web server a cópia é baixável por URL sem login; se não, é legível por qualquer usuário local do sistema.
- **Impacto:** Cópia residual dos dados de todos os clientes, fora do login e da regra de visibilidade. Quando a exportação passa a depender do usuário (o que a correção de F4 exige), um arquivo compartilhado ainda misturaria exportações de clientes diferentes.
- **O que fiz:** Sem arquivo intermediário (F10): exportarCsv() não grava mais nada em disco. A constante EXPORT_DIR continua em config.php, mas lib.php não a usa. Ação operacional: apagar o chamados.csv antigo.
- **Evidência:** Medido: depois de uma exportação o original deixa chamados.csv (-rw-rw-r--, ~148 KB com 2 mil chamados) no diretório; o alterado não grava nada. Não sei dizer se o diretório de produção é servido por URL, por isso a confiança é 55.
- **Corrigido:** sim

### F12 — mediaResposta() divide por zero quando nenhum chamado tem tempo de 1a resposta e derruba a listagem

- **Onde:** `code/lib.php:116`
- **Categoria / severidade / confiança:** bug · media · 97
- **Mecanismo:** `return $soma / $qtd;` com $qtd = 0 quando nenhuma linha tem minutos_resposta (instalação nova, limpeza de dados, todos aguardando 1a resposta). Em PHP 8 isso lança DivisionByZeroError, não tratado; index.php:74 chama a função em toda carga da listagem.
- **Impacto:** A página principal inteira (a que todos usam) fica indisponível, e não só o indicador de SLA, enquanto não houver ao menos um chamado respondido.
- **O que fiz:** Com $qtd = 0 devolve 0.0 (tipo de retorno float preservado) em vez de dividir; a divisão com dados continua igual.
- **Evidência:** Original: com UPDATE chamados SET minutos_resposta = NULL, mediaResposta() lança 'EXC DivisionByZeroError: Division by zero' (e a página dá 500 vazio); o alterado devolve 0.0 e a página mostra '0 min'. É a única diferença em 756 linhas do teste diferencial de funções.
- **Corrigido:** sim

### F13 — N+1 na listagem: tecnicoNome() faz uma consulta por chamado dentro do laço

- **Onde:** `code/lib.php:88-91`
- **Categoria / severidade / confiança:** performance · media · 99
- **Mecanismo:** Para cada linha de `SELECT * FROM chamados`, listarChamados() chama tecnicoNome() (lib.php:64-72, que executa `SELECT nome FROM usuarios WHERE id = N` na linha 69), mais uma ida ao banco por chamado com técnico. O custo cresce linearmente com o número de chamados e a página não tem paginação.
- **Impacto:** Cada carga da listagem faz 1 + N consultas; a 20 mil chamados são ~15 mil consultas por visualização, o que satura o servidor com poucos usuários simultâneos.
- **O que fiz:** consultarChamados() faz uma única consulta com LEFT JOIN usuarios, trazendo tecnico_nome (COALESCE para '-', como antes). tecnicoNome() foi mantida, marcada como deprecated, para quem a chame de fora. Chaves e valores do retorno idênticos aos do original, inclusive os tipos (strings ou null).
- **Evidência:** Medido com 2.005 chamados: original 1.505 consultas e ~740 ms para listarChamados(); alterado 1 consulta e ~31 ms. Um teste diferencial com 315 e depois 1.515 chamados e centenas de termos comparou conteúdo e tipos (===) com o original sem diferença.
- **Corrigido:** sim

### F14 — N+1 na exportação: o mesmo tecnicoNome() por linha dentro do laço do CSV

- **Onde:** `code/lib.php:136-145`
- **Categoria / severidade / confiança:** performance · media · 99
- **Mecanismo:** O laço de exportarCsv() chama tecnicoNome() para cada chamado (lib.php:137), e antes ainda grava um arquivo em disco (F11). Mesma causa de F13, em outro caminho de código (e para outro consumidor: a exportação roda no horário noturno com todo o volume).
- **Impacto:** A exportação com todos os chamados custa 1 + N consultas e segura uma conexão por vários segundos; é também o que alarga a janela da corrida de F10.
- **O que fiz:** O CSV usa a mesma consulta com JOIN (só as cinco colunas de que precisa, ORDER BY id) e escreve direto na saída, linha a linha, sem montar a lista inteira em memória.
- **Evidência:** Medido com 2.005 chamados: original 1.505 consultas e ~825 ms; alterado 1 consulta e ~32 ms. Com 60 mil chamados: original 27,8 s e 24 MB de pico de memória; alterado 0,7 s e 6 MB, com o CSV byte a byte idêntico (mesmo md5, 4.630.505 bytes).
- **Corrigido:** sim

### F15 — Título com \" quebra a estrutura do CSV: o escape padrão do fputcsv deixa o cliente forjar colunas e registros

- **Onde:** `code/lib.php:130-144`
- **Categoria / severidade / confiança:** seguranca · media · 92
- **Mecanismo:** fputcsv() usa por padrão a barra invertida como caractere de escape, e com ele uma aspa precedida de barra NÃO é duplicada: o título `a\",=cmd|...,x` sai como `"a\",=cmd|...,x"`, o campo fecha antes da hora e um leitor RFC 4180 (planilha, Python csv, LOAD DATA) lê 7 colunas. Um título com `\"` seguido de quebra de linha e `999,Falso,...` forja um registro inteiro. O título é texto livre do cliente. Títulos legítimos como Pasta "C:\Users\" nao abre também saem corrompidos.
- **Impacto:** Um cliente injeta colunas e linhas no relatório que a equipe abre (registro falso no CSV, fórmula fora do alcance da neutralização de F16); e títulos legítimos com barra seguida de aspas corrompem a leitura do arquivo.
- **O que fiz:** O escape passa a ser '' (RFC 4180) por PAINEL_CSV_ESCAPE, com `'\\'` como reserva no PHP < 7.4, que não aceita escape vazio. Isso também elimina o aviso de depreciação do PHP 8.4 (F22). A saída é idêntica à anterior para qualquer título sem barra seguida de aspas; os que têm passam a ser codificados como o padrão manda.
- **Evidência:** Com títulos hostis no banco, um leitor RFC estrito (Python csv, strict) rejeita o arquivo inteiro do original (`',' expected after '"'`). Alterado: 20 registros lidos, todos com 5 colunas, nenhum registro 999, títulos com \" preservados. Três verificadores independentes reproduziram o defeito e confirmaram a correção. Leitores PHP que usam fgetcsv() com o escape padrão devem passar '' como escape ao ler títulos com barra e aspas.
- **Corrigido:** sim

### F16 — Injeção de fórmula no CSV: títulos escritos pelo cliente que começam com = + - @ são executados pela planilha

- **Onde:** `code/lib.php:138-144`
- **Categoria / severidade / confiança:** seguranca · media · 68
- **Mecanismo:** O Titulo (texto livre do cliente) e o nome do técnico vão para fputcsv() sem tratamento. Um título como =HYPERLINK(...) ou @SUM(...) é gravado literalmente e uma planilha que abra o arquivo o interpreta como fórmula. O CSV é aberto por técnicos e pelo relatório gerencial.
- **Impacto:** Execução de fórmula ou vazamento de dados na máquina de quem abre a exportação (precisa de interação e de um leitor que avalie fórmulas, por isso a severidade média).
- **O que fiz:** neutralizarFormula() antepõe um apóstrofo aos valores de Titulo e de Tecnico que começam com = + - @ TAB ou CR; o marcador '-' de 'sem técnico' sai como sempre e os demais valores não mudam.
- **Evidência:** Efeito colateral aceito e documentado: um título legítimo que comece com '-' ou '+' (por exemplo '-5 dBm de sinal') passa a sair com apóstrofo no CSV, também em exportarCsv(). Não havia planilha neste ambiente para ver a fórmula ser avaliada; o ponto foi comprovado lendo o arquivo com leitores RFC.
- **Corrigido:** sim

### F17 — Falha de conexão com o banco não tem tratamento efetivo em PHP >= 8.1 e vaza detalhes quando display_errors está ligado

- **Onde:** `code/index.php:9-12`
- **Categoria / severidade / confiança:** bug · baixa · 75
- **Mecanismo:** A mensagem amigável de index.php:10-12 depende de $db->connect_errno, mas desde o PHP 8.1 new mysqli() lança mysqli_sql_exception em vez de preencher connect_errno: o ramo é código morto e a exceção sobe sem tratamento.
- **Impacto:** Com display_errors ligado a resposta expõe o usuário do banco, a mensagem do servidor e o caminho absoluto do script; com display_errors desligado o usuário recebe uma página 500 vazia, sem a mensagem pretendida. Em versões do PHP anteriores à 8.2 o argumento da senha poderia aparecer no stack trace; no 8.4 o PHP o oculta (SensitiveParameterValue), então a senha não vaza neste ambiente.
- **O que fiz:** new mysqli() passa por try/catch: a exceção vai para o error_log e o usuário recebe 'Falha ao conectar ao banco.' (HTTP 500). O ramo connect_errno foi mantido para modos sem exceção. Um set_exception_handler em index.php devolve resposta genérica (HTTP 500) para qualquer exceção não tratada e registra no log a mensagem, o local, a rota e a pilha de chamadas SEM os argumentos (um login falho teria a senha).
- **Evidência:** Testado com senha errada: original com display_errors=1 devolve 'Fatal error: Uncaught mysqli_sql_exception: Access denied for user ...' com o caminho absoluto (status 200 no servidor embutido); com display_errors=0 devolve 500 sem corpo. Alterado: 500 e 'Falha ao conectar ao banco.' nos dois casos, e tabela ausente ou DB_PASS vazio também dão 500 genérico. O handler não captura erros fatais (por exemplo estouro de memória).
- **Corrigido:** sim

### F18 — exportarCsv() falha em silêncio: sem EXPORT_DIR devolve 0 byte (ou só um warning), e um erro de consulta destrói o último export bom

- **Onde:** `code/lib.php:126-135`
- **Categoria / severidade / confiança:** bug · baixa · 85
- **Mecanismo:** Se fopen() falha (diretório inexistente, sem permissão, disco cheio) a função dá `return` antes de qualquer cabeçalho: o cliente recebe 200 com corpo vazio, e com display_errors ligado a mensagem do warning mostra o caminho. A linha 126 abre o arquivo com 'w' (trunca) ANTES de consultar; no ramo `$res === false` (linha 133) o `return` deixa $fp aberto e o arquivo reduzido ao cabeçalho, e com o mysqli lançando exceção (PHP >= 8.1) esse ramo nunca roda.
- **Impacto:** A exportação some sem aviso (a rotina noturna não percebe) ou entrega um arquivo parcial, e o último arquivo bom é destruído por um erro passageiro de banco.
- **O que fiz:** Sem arquivo intermediário o primeiro modo de falha deixa de existir; a consulta roda antes de qualquer cabeçalho, de modo que uma falha de banco vira o erro 500 genérico em vez de um CSV truncado.
- **Evidência:** Original sem /var/www/painel/tmp: 200 e 0 byte com display_errors=0; com display_errors=1, 200 e 'Warning: fopen(/var/www/painel/tmp/chamados.csv): Failed to open stream'. Um revisor mostrou o arquivo caindo de 399 B para 37 B (só o cabeçalho) quando a consulta falha.
- **Corrigido:** sim

### F19 — login[] ou senha[] derrubam a página de login com TypeError não tratado

- **Onde:** `code/index.php:22`
- **Categoria / severidade / confiança:** bug · baixa · 95
- **Mecanismo:** index.php:22 passa $_POST['login'] direto para autenticar(mysqli, string, string). Com login[]=x ou senha[]=x o PHP entrega um array e a chamada lança TypeError, sem autenticação prévia.
- **Impacto:** Qualquer pessoa (anônima) provoca erro fatal na tela de login; com display_errors ligado, o stack trace aparece na resposta.
- **O que fiz:** Só valores string são repassados; array vira string vazia, e o resultado é o mesmo de uma credencial inválida (formulário de novo).
- **Evidência:** Original: 'Fatal error: Uncaught TypeError: autenticar(): Argument #2 ($usuario) must be of type string, array given'. Alterado: formulário de login.
- **Corrigido:** sim

### F20 — busca[] derruba a listagem com TypeError não tratado

- **Onde:** `code/index.php:72-73`
- **Categoria / severidade / confiança:** bug · baixa · 95
- **Mecanismo:** index.php:72 lê $_GET['busca'] sem validar o tipo e a linha 73 a passa a listarChamados(mysqli, string); busca[]=x entrega um array e lança TypeError.
- **Impacto:** Um usuário autenticado derruba a página principal com um parâmetro malformado (link ou favorito corrompido).
- **O que fiz:** Valor que não seja string é tratado como busca vazia.
- **Evidência:** Original: fatal error de TypeError. Alterado: listagem normal.
- **Corrigido:** sim

### F21 — mediaResposta() traz todas as linhas respondidas para o PHP só para calcular uma média

- **Onde:** `code/lib.php:109-115`
- **Categoria / severidade / confiança:** performance · baixa · 85
- **Mecanismo:** `SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL` e laço em PHP somando linha a linha, a cada carga da listagem. O banco devolve O(n) linhas para produzir dois números.
- **Impacto:** Tráfego e tempo proporcionais ao número de chamados respondidos em toda visualização do painel.
- **O que fiz:** Uma consulta agregada: COUNT(minutos_resposta) e COALESCE(SUM(minutos_resposta), 0); a divisão continua em PHP, então o resultado é o mesmo número de antes (e 0.0 sem dados, ver F12).
- **Evidência:** Mesmo valor que o original no teste diferencial (inclusive o 26 mostrado na página com os dados de teste).
- **Corrigido:** sim

### F22 — fputcsv() sem o argumento $escape: em PHP 8.4 emite Deprecated e pode sujar o corpo do CSV

- **Onde:** `code/lib.php:130-144`
- **Categoria / severidade / confiança:** bug · baixa · 85
- **Mecanismo:** PHP 8.4 passou a emitir E_DEPRECATED quando fputcsv() é chamada sem $escape (linhas 130 e 138). Se a configuração exibir deprecações, o texto 'Deprecated: fputcsv()...' é impresso na saída antes do CSV.
- **Impacto:** Com display_errors e E_DEPRECATED ligados o arquivo baixado começa com HTML de erro e quebra a leitura do CSV (cabeçalho exato é parte do contrato). Em produção com deprecações desligadas só enche o log.
- **O que fiz:** O $escape é passado explicitamente (PAINEL_CSV_ESCAPE, ver F15), o que elimina o aviso.
- **Evidência:** Original sob error_reporting=-1: o corpo do CSV começa com várias linhas 'Deprecated: fputcsv(): the $escape parameter must be provided...'. Alterado: nenhuma. O php.ini padrão deste ambiente já mascara E_DEPRECATED.
- **Corrigido:** sim

### F23 — rotuloPrioridade() com quatro níveis de if aninhado e números mágicos (3, 4, 30)

- **Onde:** `code/lib.php:40-59`
- **Categoria / severidade / confiança:** qualidade · baixa · 90
- **Mecanismo:** Cinco returns espalhados em quatro níveis de if/else; o SLA (30 minutos) e os níveis 3 e 4 de prioridade aparecem como literais. Difícil de ler e de alterar sem erro (a diferença entre `> 30` e `>= 30` fica enterrada no meio).
- **Impacto:** Manutenção arriscada de uma regra de negócio (o rótulo do SLA) lida pela tela e possivelmente pelo relatório gerencial.
- **O que fiz:** Guardas em sequência (sem resposta, prioridade abaixo de alta, dentro do SLA, crítica/atrasada), com constantes PAINEL_PRIORIDADE_ALTA, PAINEL_PRIORIDADE_CRITICA e PAINEL_SLA_MINUTOS. As saídas são as mesmas para toda combinação.
- **Evidência:** Bateria de 64 combinações (prioridade -1..6 x minutos null,-5,0,1,29,30,31,100) idêntica ao original, incluindo o limite de 30 minutos; um verificador repetiu com 350 combinações e coerções, também idêntico.
- **Corrigido:** sim

### F24 — formatarStatus() devolve 'Resolvido' para qualquer valor diferente de 1 e 2

- **Onde:** `code/lib.php:26-35`
- **Categoria / severidade / confiança:** bug · baixa · 45
- **Mecanismo:** O último else não testa o 3: um status 0, 4 ou 99 (fora do domínio 1..3 do schema, mas sem CHECK/ENUM no banco) sai como 'Resolvido' na tela e no CSV, em vez de acusar o dado inesperado.
- **Impacto:** Latente: um status novo, ou lixo vindo de importação, seria contado como resolvido pelo relatório gerencial sem nenhum alarme.
- **O que fiz:** Não alterei o comportamento: só a forma (tabela de rótulos 1 e 2, restante 'Resolvido'), com comentário explicando. Ver Decisões: não sei se há linhas fora do domínio em produção e o relatório gerencial casa por esses textos.
- **Evidência:** Diferencial de funções: formatarStatus(-2..6 e 99) idêntico ao original (620 entradas no fuzz de um verificador).
- **Corrigido:** não

### F25 — Sem limitação de tentativas de login (força bruta) e sem como encerrar a sessão

- **Onde:** `code/index.php:20-29`
- **Categoria / severidade / confiança:** seguranca · media · 60
- **Mecanismo:** O bloco de login aceita tentativas ilimitadas, sem atraso, bloqueio ou contador, contra um hash que (até migrar) é md5. Não existe rota de logout: a sessão só termina por expiração do PHP.
- **Impacto:** Adivinhação de senha de contas de técnico limitada só pela rede; em máquina compartilhada a sessão não pode ser encerrada pelo usuário. Depois da migração para bcrypt cada tentativa custa ~0,28 s de CPU do servidor (custo 12 do PHP 8.4), então a falta de limite também vira um jeito barato de ocupar os workers: 4 workers saturam com ~15 logins por segundo.
- **O que fiz:** Não corrigido: exige estado (tabela ou cache de tentativas) e uma rota nova, fora do contrato de rotas do manifesto. Recomendado como próximo passo, e como passo da implantação: limite de POST de login por IP no proxy ou WAF, antes de alargar a coluna de senha (F7).
- **Evidência:** Revisão de código; nenhuma contagem de tentativas nem rota de saída existe no original. A amplificação de CPU do bcrypt foi medida por três verificadores (GET legítimo de 34 ms para ~1,5 s sob 40 logins errados simultâneos).
- **Corrigido:** não

## Decisões — o que não mudei e por quê

Primeiro, as **mudanças de comportamento deliberadas** que o diff introduz fora do contrato do manifesto (todas pequenas; nenhuma toca assinatura, rota, cabeçalho do CSV, rótulo de status ou estrutura HTML). Depois, o que deixei como está.

- **Busca**: `%` e `_` continuam valendo como curingas do LIKE, como sempre valeram (preservado de propósito: o contrato manda manter o comportamento, e curinga não é vetor de injeção). O que muda: um apóstrofo na busca deixa de dar erro fatal, e a barra invertida deixa de ser lida pelo parser de strings do SQL (antes `\n` virava quebra de linha, `\t` virava TAB, `\a` virava `a`); agora vale pelas regras do LIKE.
- **Ordem da listagem**: empates em `criado_em` desempatam por `id` crescente, que é como o original os devolvia na prática (a ordem era indefinida, mas observada assim); passa a ser explícita.
- **CSV**: títulos que começam com `=`, `+`, `-`, `@`, TAB ou CR saem com um apóstrofo na frente, no Titulo e no Tecnico (o marcador `-` de "sem técnico" não muda) (F16); o escape do `fputcsv` passa a ser vazio (RFC 4180), então um título com barra seguida de aspas sai codificado como o padrão manda (F15). Leitores PHP que lêem o arquivo com `fgetcsv()` e o escape padrão devem passar `''` para esse caso raro.
- **`exportarCsv()` não grava mais `EXPORT_DIR/chamados.csv`**: o CSV só vai para a saída, como o manifesto descreve ("escreve o CSV na saída"). Se algum script externo lê esse arquivo, ele precisa capturar a saída da função. É o risco de compatibilidade mais concreto desta entrega; escolhi assumi-lo porque o arquivo era a origem de F10, F11 e F18.
- **Falhas**: falha de conexão e exceção não tratada respondem HTTP 500 com texto genérico (antes: tela de erro do PHP ou página vazia); login e busca em formato de array são tratados como vazios.
- **Login**: depois do `ALTER TABLE`, uma tentativa errada custa ~0,28 s de CPU (bcrypt custo 12 do PHP 8.4; ~0,07 s no PHP <= 8.3, custo 10), igual para todos os casos de falha de propósito. Antes do `ALTER` nada muda (falha custa ~3 ms).
- **Sessão**: modo estrito (id forjado é descartado), `HttpOnly`, `SameSite=Lax` (PHP >= 7.3) e `Secure` quando a requisição é HTTPS (atrás de proxy que termina o TLS é preciso repassar `HTTPS` ao PHP; `X-Forwarded-Proto` sozinho não liga o `Secure`). Sessões abertas pelo código antigo continuam válidas.
- **Requisitos**: `listarChamados()` e `exportarCsv()` agora usam `get_result()` (extensão mysqlnd), que antes só `autenticar()` exigia; lib.php ganhou funções globais novas (`consultarChamados`, `abrirConsultaChamados`, `escreverCsv`, `neutralizarFormula`, `migrarHashDeSenha`, `recusarLogin`, `larguraColunaSenha`, `conexaoDevolveTiposNativos`, além das quatro de visibilidade) que colidiriam com funções de mesmo nome em scripts externos.
- **Funções antigas**: `listarChamados()` e `exportarCsv()` seguem devolvendo todos os chamados, para os scripts internos.

**Deixado como está:**

- **Assinaturas, retorno e formato de dados das funções públicas de lib.php (autenticar, formatarStatus, rotuloPrioridade, listarChamados, verChamado, mediaResposta, exportarCsv) e a função auxiliar tecnicoNome().** O manifesto congela nome, parâmetros, tipos e retorno e há consumidores externos (rotina noturna, relatório gerencial, faturamento). A regra de visibilidade foi implementada por funções NOVAS e aditivas (escopoDeVisibilidade, chamadoVisivel, listarChamadosVisiveis, exportarCsvVisivel), em vez de parâmetros opcionais nas existentes. Quem chama as funções antigas continua recebendo todos os chamados, que é o esperado de scripts internos. tecnicoNome() fica porque não há como saber se alguém externo a chama. Custo dessa escolha, apontado por um cético: passam a existir duas famílias de funções (as antigas, abertas por contrato, e as *Visivel*); quem escrever uma tela nova precisa usar as *Visivel*, o que os comentários de lib.php dizem.
- **verChamado() (lib.php:100) e tecnicoNome() (lib.php:69) concatenam um inteiro na consulta SQL.** Não é injeção: os parâmetros são tipados (int, ?int) e o PHP converte ou lança TypeError antes de a string existir. Trocar por prepared statements seria só consistência e mudaria os tipos devolvidos (o protocolo binário devolve int onde query() devolve string). Deixei como está, com um comentário em verChamado(); os pontos realmente injetáveis (busca) foram corrigidos.
- **Comportamento de formatarStatus() para valores fora de 1..3 (continua 'Resolvido') e dos rótulos de rotuloPrioridade, inclusive o chamado crítico ainda sem resposta aparecer como 'Aguardando 1a resposta'.** O relatório gerencial casa por esses textos e não sei se existem linhas fora do domínio em produção. Mudar o rótulo mudaria contagens de um consumidor que não vejo. É decisão de produto, registrada em F24; a ação segura é consultar `SELECT status, COUNT(*) FROM chamados GROUP BY status`, depois fechar o domínio no banco (CHECK ou ENUM) e só então trocar o rótulo.
- **mediaResposta() continua global, sem filtro por usuário, e o painel mostra a mesma média para clientes e técnicos; sem nenhuma resposta registrada a tela mostra '0 min'.** É um indicador agregado de SLA do painel (manifesto: 'média de minutos') e a função tem assinatura fixa. Reconheço um canal lateral fraco: o cliente vê a média de todos os chamados e, se só houver um chamado respondido no banco, ela iguala o valor dele (só o valor arredondado é exibido). Filtrar por cliente mudaria o significado do indicador e mostraria '0 min' a quem só tem chamados sem resposta. O '0 min' sem dados é cosmético (antes a página caía); mudar o texto da tela é mudança de formato que ninguém pediu.
- **A listagem continua sem paginação e o CSV continua trazendo todos os chamados; mantive SELECT c.* na listagem (todas as colunas do chamado).** O contrato é uma listagem completa e um CSV completo, e o retorno de listarChamados() é a linha inteira (consumidores podem usar qualquer coluna). Paginar muda a tela e o comportamento esperado. O N+1 já saiu e o custo agora é 1 consulta; o CSV passou a trazer só as cinco colunas que usa. Paginação fica como evolução com acordo dos consumidores.
- **Migração em massa de senhas md5 para bcrypt, limite de tentativas de login, CSRF no login, rota de logout, cabeçalhos de segurança (CSP, X-Frame-Options), atualização do papel a cada requisição, liberação do lock de sessão durante a exportação (session_write_close) e contramedida contra CPU amplificada por logins falhos.** Cada item precisa de estado novo, rota nova, mudança de UX ou do plaintext de cada usuário (impossível migrar md5 em lote). Entreguei a migração oportunista no login (F7) e o resto fica como recomendação, sendo o limite de tentativas no proxy parte da implantação. O papel segue guardado na sessão: rebaixamento só vale no próximo login. O login continua insensível a maiúsculas e a acento pela collation do banco ('ANA' entra como 'ana'), como no original.
- **A constante EXPORT_DIR continua definida em config.php, embora lib.php não a use mais; o status HTTP de 'chamado não encontrado' continua 200; a estrutura HTML das páginas (sem <html>/<body>), o link 'Exportar CSV' e a coerção frouxa de ?ver (101abc abre o 101) não foram tocados.** Scripts externos podem referenciar EXPORT_DIR; mudar o status ou o HTML de páginas existentes é quebra de contrato sem ganho de segurança. O 'não encontrado' é o mesmo para id inexistente e para id de outro cliente justamente para não revelar a existência do chamado. Links e favoritos externos com ?ver malformado continuam funcionando.
- **Não rotacionei nem posso rotacionar a senha do banco e a chave de e-mail; não troquei mysqli por PDO nem a stack; não adicionei dependências.** A rotação é ato operacional fora do código e é obrigatória: os dois segredos já estiveram em texto puro. mysqli faz parte do contrato (as assinaturas recebem mysqli); consultas preparadas já existem nessa camada. Só uso o que a stack já tem (password_hash, hash_equals, mysqlnd).
- **Os htmlspecialchars já existentes (titulo, descricao, tecnico_nome) e o echo de $c['id'] sem escape.** Estão corretos: o escape cobre o contexto de corpo de elemento e o id é a chave inteira do banco. Os pontos sem escape eram só os dois da busca (F5).

## Implantação (fora do código)

A ordem importa: a coluna de senha só deve ser alargada **depois** de a nova versão estar estável, porque a partir daí cada conta que fizer login vira bcrypt e o código antigo passa a recusá-la.

1. **Segredos**: definir `DB_PASS` (e `DB_HOST`, `DB_NAME`, `DB_USER` se diferirem dos padrões) e `SMTP_API_KEY` no ambiente do PHP (PHP-FPM `env[...]` com `clear_env = no`, `SetEnv` no Apache ou systemd). Sem `DB_PASS` o painel não conecta, por desenho.
2. **Rotacionar** a senha do usuário do banco e a chave da API de e-mail: os dois valores estiveram em texto puro no código e em qualquer cópia dele, inclusive este pacote.
3. **Publicar o código com a coluna ainda `CHAR(32)`**: nada é gravado em `usuarios`, as senhas seguem em md5 e o rollback é livre.
4. **Teste de fumaça** com `ana`, `bruno`, `carla` e `diego`: lista, detalhe de um chamado de outro cliente (o cliente deve receber "não encontrado"), busca e CSV.
5. **Arquivo residual**: apagar `EXPORT_DIR/chamados.csv` (cópia antiga de todos os chamados) e conferir se algum script depende dele (o novo `exportarCsv()` não o grava mais).
6. **Dados**: rodar `SELECT status, COUNT(*) FROM chamados GROUP BY status;` para confirmar que só existem 1, 2 e 3 (decisão sobre F24).
7. **Proxy**: limitar o POST de login por IP (nginx `limit_req` ou WAF) antes do passo seguinte (F25).
8. **Migração das senhas, com a versão estável**: `CREATE TABLE usuarios_senha_bkp AS SELECT id, senha FROM usuarios;` e depois `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. **Ponto sem volta**: a partir do primeiro login de cada conta o md5 deixa de existir no banco, e o código antigo (ou qualquer leitor que compare md5) recusa essa conta. Para voltar o código depois disso, restaurar os md5 do backup: `UPDATE usuarios u JOIN usuarios_senha_bkp b ON b.id = u.id SET u.senha = b.senha;` (não estreitar a coluna de volta para `CHAR(32)`: em modo estrito dá erro e fora dele trunca).

## Como validei

- **Ambiente**: PHP 8.4.26 CLI e uma instância MariaDB 11.8 privada (porta própria, isolada de qualquer outro banco da máquina), carregada com o `schema.sql` e o `seed.sql` originais. Mesmos dados para original e alterado.
- **Roteiro HTTP (109 cenários)** para ana, bruno, carla, diego e anônimo, com o código original e com o alterado: login válido, inválido, com array e com SQL; listagem; buscas (comuns, `%`, `_`, XSS, SQLi com `UNION`, aspa simples, array); detalhe dos ids 101 a 105, inexistente, 0, negativo e não numérico; CSV. Respostas comparadas em disco, nos dois schemas (`CHAR(32)` e `VARCHAR(255)`, sem diferença entre eles). Resultado: para técnicos, os cenários normais são idênticos ao original byte a byte; para clientes só mudam os que dependem de visibilidade; nenhuma resposta do alterado contém aviso, deprecação ou erro do PHP (o original tem 14).
- **Teste diferencial de funções**: ~100 chamadas (autenticar com 10 pares de credenciais, cada uma duas vezes; formatarStatus de -2 a 6 e 99; as 64 combinações de rotuloPrioridade; listarChamados com 12 termos; verChamado; mediaResposta; tecnicoNome; exportarCsv) executadas nos dois códigos e comparadas via `var_export`, que expõe diferenças de tipo (o prepared statement do mysqli devolve int onde `query()` devolve string; por isso consultarChamados() normaliza para string, e só o faz quando a conexão está em modo texto). Em 756 linhas, a única diferença é F12.
- **Reproduções e medições**: injeção por `UNION` e por apóstrofo (F1); contagem de consultas e tempo com 2.005 chamados (F13, F14); CSV com 60 mil chamados (mesmo md5, 24 MB e 27,8 s contra 6 MB e 0,7 s); corrida de exportações com sessões distintas (12 de 12 respostas corrompidas contra 0 de 12; 719 de 720 contra 0 de 720; F10); CSV com títulos e técnicos hostis lido por um leitor RFC estrito (F15, F16); fixação de sessão com id forjado, antes e depois (F9); falha de conexão com senha errada, com e sem `display_errors` (F17); tempo de CPU por classe de falha de login (F7); `disable_functions=ini_set`; log do handler sem a senha digitada.
- **Revisão independente**: sete revisores cegos (segurança, autenticação, controle de acesso, bugs, performance e arquitetura, qualidade e um advogado do diabo), cujos achados foram fundidos em 45 itens; os 15 mais graves foram atacados por céticos (29 veredictos, 28 confirmando, com severidade e confiança próximas das daqui; o único que não confirmou era um duplicado dos achados de visibilidade, descartado como item próprio). Em seguida cinco verificadores (red team, compatibilidade com fuzz diferencial, versões do PHP, casos de borda, revisão do diff) examinaram o código alterado, e 37 céticos tentaram refutar cada problema que eles levantaram (42 agentes). A fase de verificação da revisão cega foi interrompida (63 agentes iniciados, 47 concluídos; faltaram itens de baixa severidade e o crítico de completude) porque já havia coberto tudo o que importava para as decisões.
- **O que a verificação pegou na minha primeira versão (já corrigido)**: (1) `fputcsv` com escape `\\` deixava um título forjar colunas e registros (F15); (2) o bcrypt criou um oráculo de tempo (falha em conta migrada ~0,28 s contra ~3 ms em login inexistente) e o hash era calculado antes de checar a largura da coluna, o que custava ~0,28 s por login mesmo sem nada a migrar; (3) o CSV carregava todas as linhas, com a descrição, em memória: ~4x o pico do original, estourando o `memory_limit` de 128 MB acima de ~80 mil chamados; (4) o bcrypt só lê 72 bytes e para no NUL, o que fazia contas migradas aceitarem senhas diferentes da original; (5) `ini_set` na sessão derrubava toda requisição com `disable_functions=ini_set`; (6) `;` em comentários do `schema.sql` quebram carregadores ingênuos que dividem o arquivo por `;`; (7) a coluna Tecnico do CSV não era neutralizada; (8) o log do handler não trazia a pilha de chamadas; e, vindos da revisão cega, dois comportamentos legados que eu tinha mudado sem necessidade: (9) o escape de `%` e `_` na busca e (10) a ordem dos empates (o original desempata por id crescente).
- **Limites da validação**: só PHP 8.4 e MariaDB 11.8 (a compatibilidade com PHP 7.1 a 8.3 é por leitura e varredura de tokens, sem construções novas como `match`, funções-seta ou tipos união; não rodei MySQL 8, php-fpm nem Apache); não havia planilha para ver uma fórmula ser avaliada; atrás de proxy TLS o cookie só ganha `Secure` se `HTTPS` chegar ao PHP.

