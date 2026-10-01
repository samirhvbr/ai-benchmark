# RELATORIO — LEB-100-A (Painel de Chamados, NetX ISP)

| | |
| --- | --- |
| Instância | LEB-100-A (nível 100, versão 1.1) · LEB spec 1.1.0 · tarefa 1.0.0 |
| Escopo | `code/config.php`, `code/lib.php`, `code/index.php` (alterados) · `code/schema.sql`, `code/seed.sql` (inalterados) |
| Índice estruturado | `achados.json` (mesmos ids, títulos e valores deste relatório) |

## 1. Resumo

O painel funciona, mas **a regra de visibilidade do manifesto não existe no código**, e a busca tem injeção SQL e XSS. Na prática, qualquer cliente logado lê os chamados de todos os outros (detalhe, listagem e CSV) e, pela injeção SQL, qualquer tabela que o usuário do banco enxergue, inclusive os hashes MD5 das senhas. Somam-se a isso: credenciais de produção no código-fonte, senhas em MD5 sem sal, um export por arquivo fixo que sai truncado sob concorrência (47 de 96 exports simultâneos), um N+1 que custa 4.006 consultas por abertura da página com 4 mil chamados e uma divisão por zero que derruba a página inicial.

Reportei **21 achados**. Corrigi **14** (F1, F2, F3, F4, F5, F8, F9, F10, F11, F15, F16, F17, F20, F21) com mudanças pequenas e localizadas em três arquivos, sem renomear nem mover nada e sem tocar em `schema.sql`/`seed.sql`. Os **7** que não corrigi (F6, F7, F12, F13, F14, F18, F19) dependem de ações fora do código (rotacionar credenciais, migrar a coluna de senha) ou de decisão de negócio e dos consumidores (CSV, SLA, paginação, status). Cada um explica o porquê.

A superfície pública do manifesto foi preservada: as 7 assinaturas, as rotas, o formato do CSV, a estrutura HTML e a regra de visibilidade (agora de fato aplicada). Acrescentei 6 funções novas em `lib.php`; nenhuma função existente mudou de assinatura.

## 2. Como verifiquei

Subi o código original e o alterado lado a lado (PHP 8.4.26, servidor embutido) contra um MariaDB 11.8 descartável carregado com `schema.sql` + `seed.sql`, e rodei o mesmo roteiro HTTP nos dois:

- **Técnicos** (carla, diego): listagem, detalhe de todos os chamados, buscas e CSV **byte a byte idênticos** ao original, inclusive com 4.005 chamados (listagem de 650 KB, CSV de 336 KB, buscas e detalhe: hashes SHA-256 iguais).
- **Clientes**: ana vê 101/102/105 e bruno 103/104, na listagem, no detalhe e no CSV; o id de terceiro responde igual ao id inexistente.
- **Funções públicas** chamadas por CLI no original e no alterado e comparadas com `var_export` (valores **e tipos**): 382 entradas idênticas (autenticar, formatarStatus, rotuloPrioridade em 64 combinações, tecnicoNome, listarChamados com 8 buscas, verChamado, mediaResposta, exportarCsv). Foi esse teste que mostrou que o prepared statement mudava os tipos das linhas (int em vez de string); corrigi antes de entregar.
- **Problemas reproduzidos no original e confirmados como resolvidos no alterado:** SQLi (UNION devolve hashes), XSS, IDOR, fixação de sessão, `busca[]`, `login[]`, `O'Brien`, divisão por zero, falha de conexão, 96 exports concorrentes e contagem de SELECTs.
- O servidor alterado rodou com `E_ALL` e `display_errors=1`: nenhum warning, notice ou deprecated.

**Limites da verificação:** não testei em MySQL 8 (o esquema declara MySQL 8; usei MariaDB) nem em PHP anterior ao 8.4. O código novo usa só sintaxe de PHP ≥ 7.1, e a opção `cookie_samesite` só é aplicada em PHP ≥ 7.3. Não acrescentei scripts de teste a `code/` para não alterar a estrutura entregue.


## 3. Achados (na ordem em que eu priorizaria a correção)

As linhas citadas seguem a **numeração original** dos arquivos recebidos (antes das minhas alterações).

| Id | Sev. | Conf. | Categoria | Onde | Corrigido |
| --- | --- | --- | --- | --- | --- |
| F1 | critica | 98 | seguranca | `code/lib.php:80-85` | sim |
| F2 | alta | 97 | seguranca | `code/index.php:52-67` | sim |
| F3 | alta | 95 | seguranca | `code/index.php:73` | sim |
| F4 | alta | 95 | seguranca | `code/index.php:44-47` | sim |
| F5 | alta | 97 | seguranca | `code/index.php:79-82` | sim |
| F6 | alta | 95 | seguranca | `code/config.php:11-12` | **não** |
| F7 | alta | 95 | seguranca | `code/lib.php:15-16` | **não** |
| F8 | media | 95 | seguranca | `code/lib.php:125-150` | sim |
| F9 | media | 85 | seguranca | `code/index.php:15-27` | sim |
| F10 | media | 95 | performance | `code/lib.php:69-89` | sim |
| F11 | media | 92 | bug | `code/lib.php:116` | sim |
| F12 | media | 85 | seguranca | `code/config.php:15` | **não** |
| F13 | media | 50 | seguranca | `code/lib.php:138-144` | **não** |
| F14 | media | 45 | bug | `code/lib.php:56-58` | **não** |
| F15 | baixa | 95 | bug | `code/index.php:72-73` | sim |
| F16 | baixa | 85 | bug | `code/lib.php:130-144` | sim |
| F17 | baixa | 90 | performance | `code/lib.php:109-115` | sim |
| F18 | baixa | 70 | performance | `code/lib.php:80-93` | **não** |
| F19 | baixa | 55 | bug | `code/lib.php:28-34` | **não** |
| F20 | baixa | 70 | qualidade | `code/index.php:9-13` | sim |
| F21 | baixa | 85 | qualidade | `code/lib.php:40-59` | sim |

### F1 — SQL injection na busca por título: listarChamados concatena $busca no LIKE

- **Onde:** `code/lib.php:80-85`
- **Categoria · severidade · confiança:** seguranca · **critica** · **98/100**
- **Mecanismo:** lib.php:82 monta `WHERE titulo LIKE '%<busca>%'` concatenando o parâmetro GET `busca` (index.php:72) e executa com `$db->query()`. Uma aspa em `busca` fecha o literal e o resto vira SQL. Reproduzido com a conta de cliente `ana`: `zzz%' UNION SELECT id,1,NULL,senha,login,1,1,NULL,NOW() FROM usuarios -- -` devolveu os hashes MD5 dos 4 usuários dentro da tabela de chamados. O mesmo defeito quebra buscas legítimas: `?busca=O'Brien` termina em mysqli_sql_exception (tela de erro fatal). `mysqli::query` não executa várias instruções, então o alcance é leitura arbitrária (UNION/subselect) com os privilégios do usuário `painel`, não escrita empilhada.
- **Impacto:** Qualquer usuário autenticado, inclusive cliente, lê qualquer tabela que o usuário do banco enxergue: hashes de senha (MD5 sem sal, ver F7) e chamados/descrições de outros clientes, contornando a regra de visibilidade (F2–F4). Também derruba a página com buscas legítimas que tenham aspas.
- **O que fiz:** **Corrigido.** consultarChamados() (lib.php) passa a usar prepared statement: `titulo LIKE ?` com `'%'.$busca.'%'` vinculado como string. A assinatura de listarChamados() não mudou. Mantive a semântica do LIKE (`%` e `_` digitados pelo usuário continuam valendo como curinga) e o formato das linhas devolvidas (valores como string, NULL como null: o prepared statement devolveria inteiros nativos e eu normalizo para o formato antigo, porque consumidores externos podem comparar tipos ou serializar em JSON). Verificado: UNION não retorna mais nada; `O'Brien` busca normalmente.

### F2 — Detalhe do chamado (?ver=) sem checagem de dono: qualquer usuário logado lê qualquer chamado

- **Onde:** `code/index.php:52-67`
- **Categoria · severidade · confiança:** seguranca · **alta** · **97/100**
- **Mecanismo:** index.php:53 chama verChamado($db, (int) $_GET['ver']) e imprime título, status, prioridade e descrição sem comparar o `usuario_id` do chamado com `$_SESSION['uid']`/`papel`. Os ids são sequenciais e a rota é um GET. Reproduzido: `ana` (cliente) abriu `?ver=103` e `?ver=104`, chamados de `bruno`, e recebeu HTTP 200 com título e descrição completos. A regra do manifesto (cliente só vê o que abriu, técnico vê tudo) não está implementada em lugar nenhum: `$papel` é lido na linha 39 e nunca usado.
- **Impacto:** Vazamento de dados de outros clientes (descrições de chamado costumam ter endereço, cobrança, contato) por simples enumeração de ids.
- **O que fiz:** **Corrigido.** Nova função chamadoVisivelPara($chamado, $uid, $papel) em lib.php, usada no index.php: técnico vê tudo; qualquer outro papel só vê chamado com `usuario_id` igual ao seu (falha fechada). Chamado de terceiros responde exatamente como chamado inexistente (`Chamado nao encontrado.`, mesmo HTTP 200), para não revelar quais ids existem. verChamado() manteve assinatura e retorno, pois é chamada por scripts que rodam sem sessão. Verificado: ana vê 101/102/105, bruno 103/104, técnicos todos; a resposta do proibido é idêntica à do inexistente.

### F3 — Listagem mostra todos os chamados a qualquer usuário logado, inclusive clientes

- **Onde:** `code/index.php:73`
- **Categoria · severidade · confiança:** seguranca · **alta** · **95/100**
- **Mecanismo:** index.php:73 chama listarChamados($db, $busca), que faz `SELECT * FROM chamados` sem nenhum filtro de dono (lib.php:80-85). Reproduzido: com `ana`, a tabela `#tabela-chamados` listou os 5 chamados, inclusive os de `bruno`, com título, status e técnico responsável; a busca por título também varre chamados alheios.
- **Impacto:** Exposição de títulos, status e técnicos de todos os clientes; serve também para descobrir ids a explorar no F2.
- **O que fiz:** **Corrigido.** Nova listarChamadosVisiveis($db, $busca, $uid, $papel), usada pelo index.php: técnico sem restrição; cliente com `usuario_id = ?` (usa o índice idx_usuario). listarChamados() manteve assinatura e continua sem filtro, porque os scripts internos (relatório gerencial, rotina noturna) rodam sem sessão e precisam enxergar tudo. Verificado: ana lista 101/102/105, bruno 104/103, técnicos os 5; a HTML dos técnicos ficou byte a byte igual à original.

### F4 — Export CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php:44-47`
- **Categoria · severidade · confiança:** seguranca · **alta** · **95/100**
- **Mecanismo:** index.php:44-47 chama exportarCsv($db) para qualquer sessão válida, e exportarCsv faz `SELECT * FROM chamados ORDER BY id` (lib.php:132) sem filtro. Reproduzido: `ana`, em `?export=csv`, recebeu os 5 chamados, inclusive os de `bruno`.
- **Impacto:** Um cliente extrai todos os chamados de todos os clientes com uma única requisição, sem precisar enumerar ids.
- **O que fiz:** **Corrigido.** Nova exportarCsvVisivel($db, $uid, $papel): técnico exporta tudo, cliente exporta só os próprios (verificado: ana 3 linhas, bruno 2, técnicos 5). exportarCsv($db) manteve assinatura e o comportamento 'tudo' para a rotina noturna e o relatório. Optei por filtrar, e não por negar o export ao cliente, porque o manifesto dá ao cliente o direito de ver os próprios chamados; negar esconderia chamados de quem tem direito a vê-los.

### F5 — XSS refletido: o termo de busca é impresso sem escape no atributo value e no parágrafo de resultados

- **Onde:** `code/index.php:79-82`
- **Categoria · severidade · confiança:** seguranca · **alta** · **97/100**
- **Mecanismo:** index.php:79 concatena `$busca` dentro de `value="..."` e index.php:82 dentro de `<p>Resultados para: ...</p>`, sem htmlspecialchars (todos os outros campos impressos na página são escapados; estes dois ficaram de fora). Reproduzido: `?busca="><script>alert(1)</script>` voltou como HTML executável. O cookie de sessão não tinha HttpOnly (F9), então o script consegue lê-lo.
- **Impacto:** Um link malicioso enviado a um técnico (que enxerga todos os chamados) executa JavaScript na sessão dele: leitura de dados, disparo do export, roubo de sessão. Basta a vítima estar logada e clicar.
- **O que fiz:** **Corrigido.** `$buscaHtml = htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` usado nos dois pontos. Para termos sem caracteres especiais a HTML ficou byte a byte igual. Os htmlspecialchars que já existiam (título, descrição, nome do técnico) ficaram como estavam. Verificado: o payload agora sai como `&quot;&gt;&lt;script&gt;...`.

### F6 — Senha do banco de produção embutida no código como fallback

- **Onde:** `code/config.php:11-12`
- **Categoria · severidade · confiança:** seguranca · **alta** · **95/100**
- **Mecanismo:** config.php:12 faz `getenv('DB_PASS') ?: 'N3tX@2013!prod'`. Se a variável de ambiente não existir, o sistema conecta com a senha literal, que viaja em todo checkout, backup e cópia do código (inclusive este pacote). O comentário da linha 11 confirma que é a senha do usuário de produção, mantida por legado desde 2013.
- **Impacto:** Quem tiver acesso de leitura ao código (repositório, backup, outro prestador) obtém a credencial de produção do banco. Combinada com F1, amplia o dano. A senha deve ser considerada comprometida.
- **O que fiz:** **Não corrigido (só reportado).** NÃO removi o fallback. Se a produção hoje depende dele (variável DB_PASS não definida), removê-lo derrubaria o painel no deploy, e eu não consigo verificar o ambiente. Plano em duas etapas: (1) ops provisionam DB_PASS (e DB_HOST/DB_USER, se precisarem) no ambiente e rotacionam a senha, que é a correção de fato, já que a atual está exposta; (2) só então trocar a linha por `getenv('DB_PASS')` sem literal e falhar com mensagem clara se vier vazio. Deixei um TODO no config.php apontando para este achado.

### F7 — Senhas armazenadas e verificadas com MD5 sem sal

- **Onde:** `code/lib.php:15-16`
- **Categoria · severidade · confiança:** seguranca · **alta** · **95/100**
- **Mecanismo:** autenticar() calcula `md5($senha)` (lib.php:15) e compara com `usuarios.senha CHAR(32)` (schema.sql:7; seed.sql usa MD5()). MD5 é rápido e sem sal: senhas iguais geram hashes iguais (ana e bruno, ambos `senha123`, têm o mesmo `e7d80ffe…`, visível no dump do F1; carla e diego idem) e senhas comuns caem em segundos por tabela ou GPU. A consulta em si é parametrizada e correta; o problema é o algoritmo.
- **Impacto:** Qualquer vazamento da tabela (o F1 já o permite a um cliente logado) equivale a vazar as senhas em texto claro; reuso de senha expõe outros sistemas dos usuários.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei. A migração correta (password_hash/bcrypt com rehash no login) exige ALTER na coluna (CHAR(32) não comporta o hash novo) e a ordem do deploy importa: código novo gravando hash longo em coluna antiga trunca ou falha e tranca usuários. Além disso, não sei se outros sistemas do ISP autenticam contra `usuarios.senha` em MD5; regravar o hash no login quebraria o login deles. Plano: (1) inventariar leitores de usuarios.senha; (2) `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`; (3) autenticar() passa a aceitar MD5 legado e bcrypt, regravando em bcrypt no primeiro login válido; (4) depois de um prazo, forçar redefinição para quem sobrar.

### F8 — Export grava arquivo fixo e compartilhado (EXPORT_DIR/chamados.csv) e o devolve com readfile: exports simultâneos se corrompem

- **Onde:** `code/lib.php:125-150`
- **Categoria · severidade · confiança:** seguranca · **media** · **95/100**
- **Mecanismo:** exportarCsv() abre sempre `EXPORT_DIR/chamados.csv` em modo 'w' (lib.php:125-126), escreve, fecha e só então faz readfile (150). Duas requisições concorrentes truncam e reescrevem o mesmo arquivo, e uma delas lê conteúdo parcial. Reproduzido com 96 exports simultâneos de 4.005 chamados: 47 respostas vieram incompletas (de 46 a 2.386 linhas em vez de 4.006), todas com HTTP 200. Além disso: o arquivo com todos os chamados fica em disco (modo 0664 pelo umask) em `/var/www/painel/tmp`; se esse diretório estiver sob o document root, é baixável sem login (não verifiquei o servidor web, ponto incerto). Se o fopen falha (diretório inexistente ou sem permissão) a função retorna em silêncio e a resposta sai vazia com HTTP 200 (visto neste sandbox, onde o diretório não existe); no ramo `$res === false` ainda vaza o descritor.
- **Impacto:** CSV incompleto entregue como se estivesse íntegro (relatório e faturamento com dados faltando, sem erro algum). E, depois da correção do F4, a mesma corrida poderia fazer um cliente ler o arquivo que um técnico acabou de gravar (export completo), ou o contrário, reabrindo o vazamento.
- **O que fiz:** **Corrigido.** exportarCsv() e exportarCsvVisivel() escrevem direto em php://output (mesmos cabeçalhos HTTP, mesmo conteúdo, byte a byte). Não há mais arquivo em disco nem dependência de EXPORT_DIR (a constante foi mantida no config.php). Verificado: 96 de 96 exports simultâneos íntegros e CSV idêntico ao original, inclusive com 4.005 linhas. ATENÇÃO: se algum script externo lê `EXPORT_DIR/chamados.csv` (o manifesto só declara a saída), ele deixa de receber o arquivo; ver 'Riscos e verificações antes do deploy'.

### F9 — Sessão: o SID não é renovado no login (fixação de sessão) e o cookie sai sem HttpOnly/SameSite

- **Onde:** `code/index.php:15-27`
- **Categoria · severidade · confiança:** seguranca · **media** · **85/100**
- **Mecanismo:** `session_start()` sem opções (index.php:15) e, no login (linhas 24-27), `$_SESSION['uid']` é gravado sem session_regenerate_id(). Verificado: o PHPSESSID de uma visita anônima continuou idêntico depois do login. Um atacante que consiga plantar um SID conhecido na vítima (cookie injection por subdomínio, por exemplo) herda a sessão autenticada; e sem HttpOnly o F5 permite ler o cookie.
- **Impacto:** Sequestro de sessão de técnico (acesso a todos os chamados). Exige um vetor para fixar ou ler o cookie, por isso severidade média.
- **O que fiz:** **Corrigido.** session_regenerate_id(true) ao autenticar; session_start() com cookie_httponly, use_strict_mode e SameSite=Lax (Lax mantém funcionando links e favoritos externos para `?ver=`). Secure só quando a requisição já é HTTPS (não forcei para não quebrar instalação servida em HTTP). Verificado: `Set-Cookie: ...; HttpOnly; SameSite=Lax` e SID novo após o login.

### F10 — N+1: uma consulta por chamado para buscar o nome do técnico (listagem e export)

- **Onde:** `code/lib.php:69-89`
- **Categoria · severidade · confiança:** performance · **media** · **95/100**
- **Mecanismo:** listarChamados() chama tecnicoNome() dentro do while (lib.php:89) e exportarCsv() faz o mesmo (lib.php:137); cada chamada executa `SELECT nome FROM usuarios WHERE id = ...` (lib.php:69). Medido com 4.005 chamados: 4.006 SELECTs e 1,6 s para a listagem; 4.005 SELECTs e 1,8 s para o CSV.
- **Impacto:** Custo linear no histórico (o sistema roda desde 2013): cada abertura do painel e cada export martela o banco com milhares de round-trips.
- **O que fiz:** **Corrigido.** Uma única consulta com `LEFT JOIN usuarios u ON u.id = c.tecnico_id` e `COALESCE(u.nome, '-')`, mesma regra de tecnicoNome() ('-' sem técnico ou técnico inexistente). Medido: 2 SELECTs e 57 ms na listagem; 1 SELECT e 40 ms no CSV; respostas de mesmo tamanho e byte a byte iguais às originais. tecnicoNome() ficou no arquivo (é pública e pode ser chamada por scripts externos).

### F11 — mediaResposta divide por zero quando nenhum chamado tem minutos_resposta e derruba a listagem inteira

- **Onde:** `code/lib.php:116`
- **Categoria · severidade · confiança:** bug · **media** · **92/100**
- **Mecanismo:** `return $soma / $qtd;` (lib.php:116) com `$qtd = 0` lança DivisionByZeroError no PHP 8. Reproduzido: zerando minutos_resposta, `index.php` morre com Fatal error em index.php:74. Acontece sempre que não há chamado respondido (base nova, ou todos os respondidos reclassificados).
- **Impacto:** Painel inteiro indisponível (a listagem é a página inicial) por causa de um dado ausente.
- **O que fiz:** **Corrigido.** Retorna 0.0 quando não há amostra, o valor neutro compatível com o retorno `float`. Efeito colateral aceito: a tela mostra '0 min', ambíguo com 'sem dado' (ver Decisões).

### F12 — Chave de API do serviço de e-mail transacional embutida no código

- **Onde:** `code/config.php:15`
- **Categoria · severidade · confiança:** seguranca · **media** · **85/100**
- **Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')` (config.php:15) é um segredo literal, sem nenhuma opção de configuração por ambiente. Não é referenciada em code/ (verifiquei com grep), então provavelmente é usada por scripts externos que incluem config.php; por isso não posso apagá-la sem risco de quebrá-los.
- **Impacto:** Quem lê o código pode enviar e-mail em nome da NetX pelo provedor (phishing com remetente legítimo, consumo de cota). A chave deve ser considerada comprometida.
- **O que fiz:** **Não corrigido (só reportado).** Passei a ler `getenv('SMTP_API_KEY')` com o literal apenas como fallback, o que permite rotacionar a chave sem deploy. O literal continua no arquivo, por isso corrigido=false: depois de provisionar a variável e rotacionar a chave, apagar o fallback (TODO deixado no config.php).

### F13 — CSV sem neutralização de fórmulas: o título, controlado por cliente, é gravado como veio

- **Onde:** `code/lib.php:138-144`
- **Categoria · severidade · confiança:** seguranca · **media** · **50/100**
- **Mecanismo:** O título (`$c['titulo']`) vai ao CSV exatamente como está no banco. Se começar com `=`, `+`, `-` ou `@` (por exemplo `=HYPERLINK(...)`), Excel e LibreOffice o interpretam como fórmula ao abrir o arquivo. O título é texto livre de cliente e o export é aberto por técnicos. Confiança moderada porque não há tela de criação de chamado neste código (não sei quem grava títulos) e o efeito depende de como o CSV é aberto.
- **Impacto:** Fórmula/DDE ou exfiltração por hyperlink na estação do técnico que abrir o CSV em planilha (exige abertura em planilha e aceitar os avisos do Excel).
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: a mitigação padrão (prefixar `'` nas células iniciadas por = + - @) muda o conteúdo da coluna Titulo, e o CSV alimenta a integração de faturamento e a rotina noturna (manifesto), que podem comparar ou parsear o título; títulos legítimos começando com `+55…` ou `-` são comuns em suporte de provedor. É decisão a tomar com os donos desses consumidores, ou aplicar o prefixo só num export 'para humanos'.

### F14 — Chamado nunca respondido aparece como 'Aguardando 1a resposta' mesmo se crítico: o SLA só é avaliado depois da primeira resposta

- **Onde:** `code/lib.php:56-58`
- **Categoria · severidade · confiança:** bug · **media** · **45/100**
- **Mecanismo:** rotuloPrioridade() só avalia atraso quando `$minutos !== null` (tempo até a 1ª resposta). Sem resposta (null) devolve 'Aguardando 1a resposta' para qualquer prioridade. Nos dados de exemplo, o chamado 104 (prioridade 4, crítica, 'Fatura em duplicidade', aberto em 2026-06-04, sem técnico) aparece como 'Aguardando 1a resposta', enquanto um crítico respondido em 31 min vira 'CRITICO - SLA estourado'. O pior caso (crítico que ninguém atendeu) é o único que não é sinalizado.
- **Impacto:** Chamados críticos sem atendimento ficam invisíveis no indicador de SLA da tela. Pode ser intencional (o rótulo descreveria o estado, não o SLA), por isso a confiança é baixa.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: corrigir exige a regra de SLA do negócio (limite de espera sem resposta por prioridade) e a hora corrente como nova entrada, e os rótulos desta função são saída consumida (contrato de valor). Sugestão: rótulo próprio para prioridade >= 3, sem resposta e com `criado_em` mais antigo que o limite, definido com o suporte.

### F15 — Parâmetros busca[] (GET) e login[]/senha[] (POST) derrubam a página com TypeError

- **Onde:** `code/index.php:72-73`
- **Categoria · severidade · confiança:** bug · **baixa** · **95/100**
- **Mecanismo:** `$_GET['busca'] ?? ''` (index.php:72) pode ser array (`?busca[]=x`); listarChamados(mysqli, string $busca) recebe o array e lança TypeError sem tratamento (reproduzido: Fatal error na resposta). O mesmo vale para `$_POST['login']` e `['senha']` em autenticar(string, string) (index.php:21-22).
- **Impacto:** Qualquer requisição forjada gera tela de erro/500 (e, com display_errors ligado, expõe caminhos de arquivo). Não dá acesso a dados.
- **O que fiz:** **Corrigido.** Entradas que não são string passam a ser tratadas como vazias (busca) ou como credencial inválida (reexibe o formulário de login). Verificado com `busca[]=x`, `login[]=ana`, `senha[]=x`.

### F16 — fputcsv sem o argumento $escape: no PHP 8.4 emite Deprecated, que cai dentro do corpo do CSV

- **Onde:** `code/lib.php:130-144`
- **Categoria · severidade · confiança:** bug · **baixa** · **85/100**
- **Mecanismo:** O PHP 8.4 deprecia depender do valor padrão de `$escape` em fputcsv. As chamadas em lib.php:130 e 138 não passam o argumento; com display_errors ligado a mensagem `Deprecated: fputcsv(): the $escape parameter must be provided...` é impressa na resposta antes do CSV. Reproduzido: 6 mensagens `Deprecated` (uma por chamada a fputcsv) dentro do arquivo baixado, antes do cabeçalho `ID,Titulo,...`. Com display_errors desligado só vai para o log.
- **Impacto:** CSV inválido para quem o consome (cabeçalho deslocado) em PHP 8.4 com erros na tela; ruído no log nos demais casos.
- **O que fiz:** **Corrigido.** Passei `',', '"', '\\'` explicitamente (os valores padrão, então a saída é idêntica). Verificado com E_ALL e display_errors=1: nenhum aviso.

### F17 — mediaResposta traz todas as linhas respondidas para somar em PHP a cada abertura do painel

- **Onde:** `code/lib.php:109-115`
- **Categoria · severidade · confiança:** performance · **baixa** · **90/100**
- **Mecanismo:** `SELECT minutos_resposta ... WHERE minutos_resposta IS NOT NULL` devolve uma linha por chamado respondido e o PHP soma em loop (lib.php:109-115); a função roda em toda renderização da listagem (index.php:74). SUM/COUNT no banco entregam o mesmo resultado em uma linha.
- **Impacto:** Transferência e CPU proporcionais ao histórico a cada page view. Baixo isoladamente (são inteiros pequenos), mas soma com F10 e F18.
- **O que fiz:** **Corrigido.** `SELECT SUM(minutos_resposta), COUNT(minutos_resposta)` e divisão em PHP (mesmo cálculo inteiro/inteiro do original). Resultado idêntico conferido no teste de equivalência (valor e tipo).

### F18 — Listagem sem paginação, com SELECT * (inclui descricao TEXT) e LIKE '%termo%' sem uso de índice

- **Onde:** `code/lib.php:80-93`
- **Categoria · severidade · confiança:** performance · **baixa** · **70/100**
- **Mecanismo:** listarChamados() sempre devolve todos os chamados com todas as colunas (inclusive `descricao`, que a tabela HTML nem mostra) e o index.php renderiza todos (88-96); a busca `LIKE '%x%'` não usa índice (varredura completa). Medido com 4.005 chamados: resposta de 650 KB.
- **Impacto:** Páginas pesadas e lentas conforme o histórico cresce; mais memória no PHP.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: paginar muda a saída da listagem (contrato: tabela completa em `index.php`, links e favoritos externos) e o SELECT * é parte do retorno de listarChamados() consumido por outros scripts. Reduzi o custo por linha (F10), mas o volume segue. Recomendo limite/paginação opcional por parâmetro novo, combinado com os consumidores.

### F19 — formatarStatus classifica qualquer status diferente de 1 e 2 como 'Resolvido'

- **Onde:** `code/lib.php:28-34`
- **Categoria · severidade · confiança:** bug · **baixa** · **55/100**
- **Mecanismo:** O `else` final devolve 'Resolvido' para 3, mas também para 0, 4, -1 ou qualquer outro valor (a coluna é TINYINT sem CHECK). Confirmado no teste de equivalência: formatarStatus(0), (4) e (9) devolvem 'Resolvido'. Um status inválido ou de um estado futuro aparece como concluído na listagem, no CSV e no relatório gerencial, que casa pelos textos.
- **Impacto:** Chamado com status corrompido (ou novo) some como 'Resolvido'. Hoje só existem 1 a 3, por isso severidade baixa.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: o manifesto fixa 1/2/3 e deixa os demais valores indefinidos; mudar o fallback altera a saída que o relatório gerencial consome. Se o negócio quiser, tornar o 3 explícito e usar um rótulo 'Desconhecido' para o resto, combinado com esse consumidor.

### F20 — Tratamento de falha de conexão é código morto no PHP >= 8.1 e a exceção vai para a tela

- **Onde:** `code/index.php:9-13`
- **Categoria · severidade · confiança:** qualidade · **baixa** · **70/100**
- **Mecanismo:** A partir do PHP 8.1 `new mysqli()` lança mysqli_sql_exception em vez de preencher connect_errno; o `die('Falha ao conectar ao banco.')` (index.php:10-12) nunca executa. Reproduzido com DB_PASS errada: `Fatal error: Uncaught mysqli_sql_exception: Access denied for user 'painel'@'localhost'` com o caminho do arquivo, na resposta. Neste PHP os argumentos do stack trace vêm omitidos, então a senha não vazou; com zend.exception_ignore_args=Off poderiam vir.
- **Impacto:** Baixo: usuário, host e caminhos expostos quando display_errors está ligado; a mensagem genérica prevista no código nunca é entregue.
- **O que fiz:** **Corrigido.** try/catch de mysqli_sql_exception: registra a causa no error_log e responde a mensagem genérica; o ramo antigo foi mantido para PHP < 8.1. Verificado com senha errada.

### F21 — rotuloPrioridade com quatro níveis de if/else aninhados

- **Onde:** `code/lib.php:40-59`
- **Categoria · severidade · confiança:** qualidade · **baixa** · **85/100**
- **Mecanismo:** Cinco retornos distribuídos em até quatro níveis de aninhamento (lib.php:42-58), com `else` depois de `return`. A regra real é uma sequência de guardas (sem resposta, normal, dentro do SLA, atrasado/crítico), e o aninhamento esconde que prioridade 5 ou mais cai em 'Alto - atrasado' e que exatamente 30 minutos ainda é 'dentro do SLA'. Funciona, mas é fácil errar ao evoluir (o F14 mexerá exatamente aqui).
- **Impacto:** Custo de manutenção e risco de regressão na função que implementa a regra de SLA.
- **O que fiz:** **Corrigido.** Reescrita com cláusulas de guarda, na mesma ordem de avaliação. Equivalência com o original verificada por teste exaustivo: 8 prioridades (-1, 0, 1, 2, 3, 4, 5, 9) × 8 tempos (null, -5, 0, 1, 29, 30, 31, 100) = 64 combinações, todas idênticas.

## 4. Decisões: o que deliberadamente não mudei, e por quê

Os achados **não corrigidos** (F6, F7, F12, F13, F14, F18, F19) têm a justificativa no próprio bloco. Além deles, estes pontos foram avaliados e deixados como estão:

1. **autenticar(): a consulta já é parametrizada e não é injetável; só o algoritmo é problema (F7).** Não tem defeito de injeção; o ponto fraco é o MD5, tratado no F7. Mexer aqui sem a migração de coluna e o inventário de outros leitores de usuarios.senha só traria risco de trancar usuários.
2. **tecnicoNome() e verChamado(): concatenam um inteiro no SQL (lib.php:69 e 100).** Não são injetáveis: o parâmetro é declarado `int`/`?int`, e o PHP converte ou rejeita o valor antes da concatenação (testei `?ver=abc`, `?ver[]=101`). É um padrão frágil, mas converter para prepared statement não tem ganho de segurança hoje e mudaria o tipo dos valores devolvidos (string para int) em verChamado(), consumida por outros scripts. tecnicoNome() deixou de ser usada em lib.php, mas é função pública e permaneceu.
3. **mediaResposta() continua global (todos os chamados), inclusive na tela do cliente.** É um agregado de SLA do topo do painel e a assinatura do manifesto (`mediaResposta(mysqli $db): float`) não recebe usuário; filtrar exigiria mudar a assinatura. Um agregado não expõe chamado individual. Registrei como decisão, não como achado.
4. **Exibição 'Tempo medio de 1a resposta: 0 min' quando não há nenhuma resposta (efeito do F11).** Distinguir 'sem dado' de '0 min' exigiria mudar o texto da tela ou a assinatura (float). Preferi o texto atual e a função total (sem exceção).
5. **`%` e `_` digitados na busca continuam funcionando como curinga do LIKE.** É o comportamento atual e a busca do manifesto ('filtrada por título') não os exclui. Escapá-los mudaria resultados de buscas existentes.
6. **Cabeçalho do CSV sai como `ID,Titulo,Status,Tecnico,"Aberto em"` (com aspas, por causa do espaço).** É o comportamento do fputcsv no original. Qualquer parser CSV lê o cabeçalho exato do manifesto; mantive a saída byte a byte para não quebrar comparação textual de quem consome.
7. **Constante EXPORT_DIR no config.php.** Deixou de ser usada por lib.php (F8), mas scripts externos que incluem config.php podem referenciá-la; remover causaria erro de constante indefinida no PHP 8.
8. **Limite de tentativas de login, CSRF no formulário de login, logout e cabeçalhos de segurança (CSP, X-Frame-Options, nosniff).** São funcionalidades/infra novas, não correção de defeito do código existente; rate limit exige armazenamento (tabela ou cache) e política do negócio, melhor feito no proxy reverso/WAF. Não há ações de escrita no painel, então CSRF só afetaria o próprio login. Recomendo tratar depois, fora desta manutenção.
9. **schema.sql e seed.sql.** Não alterei o esquema (F7 exigiria um ALTER planejado) nem os dados de exemplo. Registro apenas que o seed contém senhas triviais documentadas em comentário (`senha123`, `tecmaster`): nunca deve ser carregado em produção, nem ficar num diretório servido pelo web server.
10. **Ordenação (`criado_em DESC`, sem desempate) e códigos HTTP (200 para 'não encontrado'/proibido).** A ordenação e o status HTTP fazem parte do comportamento observável atual. Trocar por 404/403 mudaria o contrato e, no caso do 403, revelaria quais ids existem; por isso proibido responde exatamente como inexistente.
11. **htmlspecialchars já existentes (título, descrição, nome do técnico) sem ENT_QUOTES/ENT_SUBSTITUTE.** Estão corretos para contexto de texto HTML; mudar as flags alteraria a saída (aspas simples) em todas as páginas. Usei as flags estritas só no ponto novo, o atributo `value` da busca.
12. **uid e papel ficam na sessão e não são revalidados no banco a cada requisição.** Rebaixar ou desativar um usuário só vale no próximo login. É limitação do desenho atual (sem logout, sem expiração configurada), não defeito introduzido ou corrigível sem decisão de produto.

## 5. Riscos e verificações antes do deploy

1. **Quem lê o arquivo de export (F8).** `exportarCsv()` agora escreve só na saída e deixou de gravar `EXPORT_DIR/chamados.csv`. O manifesto só declara a saída, mas procure nos scripts do ISP por `chamados.csv` e `EXPORT_DIR` antes de publicar. Se houver leitor desse arquivo, a rotina noturna deve redirecionar a saída da função para um arquivo próprio (no CLI ela escreve em stdout).
2. **Credenciais (F6, F12).** Rotacionar a senha do banco e a chave SMTP, provisionar `DB_PASS` e `SMTP_API_KEY` no ambiente e só então apagar os fallbacks do `config.php`. Até lá o sistema se comporta como antes.
3. **Nomes novos em `lib.php`:** `escopoDono`, `chamadoVisivelPara`, `consultarChamados`, `listarChamadosVisiveis`, `exportarCsvVisivel`, `escreverCsv`. Se algum script externo que inclui `lib.php` definir função com o mesmo nome, o PHP acusará redeclaração; vale um grep.
4. **Scripts internos continuam enxergando tudo:** `listarChamados()` e `exportarCsv()` seguem sem filtro, de propósito (rodam sem sessão). Quem for chamá-las a partir de uma tela com usuário logado deve usar as variantes `*Visiveis`.
5. **Sessões já abertas** continuam válidas e só passam a ter `HttpOnly`/`SameSite` no próximo login.
6. **Monitorar** o `error_log` por `painel: falha ao conectar ao banco` (novo registro, F20).
7. **Próximos passos recomendados, fora desta manutenção:** migração de senhas (F7), decisão sobre neutralização de fórmulas no CSV (F13), regra de SLA para chamados nunca respondidos (F14) e paginação (F18).

## 6. Conferência com o manifesto

| Item do manifesto | Situação |
| --- | --- |
| `autenticar` | inalterada |
| `formatarStatus` | inalterada (1/2/3 → rótulos exatos) |
| `rotuloPrioridade` | mesma assinatura; corpo reestruturado; 64 combinações idênticas ao original |
| `listarChamados` | mesma assinatura; mesmo retorno, com `tecnico_nome`, mesmos valores e tipos |
| `verChamado` | inalterada |
| `mediaResposta` | mesma assinatura e mesmo valor; devolve 0.0 (em vez de exceção) sem amostra |
| `exportarCsv` | mesma assinatura; escreve o CSV na saída com conteúdo idêntico |
| Rotas `busca`, `ver`, `export` | inalteradas |
| CSV: cabeçalho, ordem por `id`, rótulos de status | idêntico byte a byte para técnico |
| HTML: `id="tabela-chamados"`, colunas e links `?ver=<id>` | idêntico byte a byte para técnico |
| Regra de visibilidade (cliente só o seu; técnico tudo) | **agora aplicada** em detalhe, listagem e CSV |
