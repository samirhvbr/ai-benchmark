# RELATÓRIO TÉCNICO: LEB-100-A (Painel de Chamados NetX ISP)

## 1. Resumo

O painel é pequeno (index.php + lib.php) e funciona, mas tinha falhas graves de segurança em produção.

- **Qualquer cliente logado lia o banco inteiro.** Isso acontecia de três jeitos: por SQL injection na busca (F1), por acesso direto a chamados alheios via `?ver=` (F2), e pela listagem e pela exportação, que não aplicavam a regra de visibilidade do manifesto (F3, F4).
- **XSS refletido na busca** (F5).
- **Segredos de produção dentro do código** (F6).
- **Senhas em md5 sem salt** (F7).
- **CSV completo gravado em disco** num diretório sob `/var/www` (F8).
- **Bugs de disponibilidade e desempenho:** divisão por zero que derruba a home (F9) e consultas N+1 (F10).

Corrigi 15 dos 19 achados sem alterar nenhum item do manifesto. Os outros quatro ficaram só reportados porque corrigi-los mexeria em contrato ou exigiria infraestrutura; o motivo de cada um está nos blocos e na seção Decisões.

**Como verifiquei.** Subi um MariaDB descartável com `schema.sql` + `seed.sql` e rodei a versão original e a corrigida lado a lado (`php -S`), comparando as saídas:

- **Técnicos (carla, diego):** listagem, busca, detalhe de 101/103/104/999 e CSV saíram **byte a byte idênticos** ao original.
- **Funções públicas chamadas direto:** mesmo resultado em 52 casos — `rotuloPrioridade` em 35 combinações, `formatarStatus` de 0 a 4, `mediaResposta`, `autenticar` (válido, senha errada, usuário inexistente), `listarChamados` com e sem busca (incluindo os **tipos** dos valores), `verChamado` e o CSV de `exportarCsv`.
- **Clientes (ana, bruno):** passaram a ver só os próprios chamados na listagem, no detalhe e no CSV.
- **Ataques e casos de borda:**
  - O payload de UNION que listava login/hash no original não devolve nada.
  - O XSS sai escapado.
  - A base sem `minutos_resposta` não derruba mais a página.
  - O login md5 funciona antes e depois do `ALTER`, e é migrado para bcrypt depois dele.
  - O id de sessão muda no login.

**Atenção para o deploy:**

1. `DB_PASS` e `SMTP_API_KEY` passam a vir **só** de variáveis de ambiente. Configure-as antes de publicar e **rotacione** os dois segredos que estavam no código.
2. Rode `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` para ativar a migração de senhas. Sem ele o código continua funcionando com md5, só não migra.

## 2. Achados (em ordem de prioridade de correção)

### F1: SQL injection no parâmetro busca de listarChamados

- **Onde:** `code/lib.php`, linha 82–85 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **critica** / **98**
- **Mecanismo:** O valor de $_GET['busca'] chega sem tratamento a listarChamados() (index.php:72-73) e é concatenado dentro de LIKE '%...%'. Uma aspa fecha o literal e o resto vira SQL; ex.: busca=' UNION SELECT 1,1,1,login,senha,1,1,1,NOW() FROM usuarios -- devolve login e hash md5 de todos os usuários na própria tabela da listagem (reproduzido no banco de teste).
- **Impacto:** Qualquer usuário autenticado, inclusive cliente, lê qualquer tabela do banco (hashes md5 de senha, chamados de terceiros) e, conforme os privilégios do usuário 'painel', pode alterar dados.
- **O que fiz:** listarChamados() passa a delegar para listarChamadosDoDono(), que usa prepared statement com o padrão '%termo%' ligado como parâmetro. Assinatura, ordenação e chaves devolvidas, inclusive os tipos string das colunas, ficaram iguais.
- **Corrigido:** sim

### F2: IDOR no detalhe: cliente abre qualquer chamado via ?ver=<id>

- **Onde:** `code/index.php`, linha 52–58 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **critica** / **97**
- **Mecanismo:** A rota ?ver chama verChamado($db, (int)$_GET['ver']) e mostra o resultado sem comparar chamados.usuario_id com $_SESSION['uid'], embora $papel e $uid já estejam carregados. Os ids são sequenciais (101..105), então dá para enumerar todos.
- **Impacto:** Cliente (ex.: ana) lê título e descrição de chamados de outros clientes (ex.: 103 e 104, que são do bruno). Viola a regra de visibilidade do manifesto.
- **O que fiz:** index.php responde 'Chamado nao encontrado.' quando o papel não é técnico e usuario_id é diferente do usuário logado. Assim o sistema não confirma nem a existência do chamado. Técnico continua vendo qualquer chamado.
- **Corrigido:** sim

### F3: Listagem mostra todos os chamados a clientes

- **Onde:** `code/index.php`, linha 72–73 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **97**
- **Mecanismo:** A listagem chama listarChamados($db, $busca), que não tem filtro por dono, para qualquer papel. Um cliente vê a lista inteira, com título, status e técnico de todos os clientes.
- **Impacto:** Vazamento de dados entre clientes do ISP; descumpre a regra 'cliente só vê os chamados que ele mesmo abriu'.
- **O que fiz:** index.php calcula $dono = donoVisivel($papel, $uid): null para técnico, uid para os demais papéis (negação por padrão). Em seguida chama listarChamadosDoDono($db, $busca, $dono), que filtra no SQL. listarChamados() continua sem filtro para os scripts internos.
- **Corrigido:** sim

### F4: Exportação CSV entrega a base inteira a qualquer cliente

- **Onde:** `code/index.php`, linha 44–47 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **95**
- **Mecanismo:** ?export=csv chama exportarCsv($db) para qualquer sessão. A função exporta SELECT * FROM chamados sem filtro, e o link 'Exportar CSV' aparece para todos.
- **Impacto:** Qualquer cliente baixa num clique todos os chamados (título, status, técnico, data) de todos os clientes.
- **O que fiz:** index.php chama exportarCsvDoDono($db, $dono): técnico recebe o CSV completo (byte a byte igual ao anterior) e cliente recebe só os próprios chamados, com o mesmo cabeçalho e o mesmo formato. exportarCsv($db) continua exportando tudo para a rotina noturna.
- **Corrigido:** sim

### F5: XSS refletido no termo de busca

- **Onde:** `code/index.php`, linha 79–82 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **98**
- **Mecanismo:** $busca é ecoado cru duas vezes: no atributo value="..." do input (linha 79) e em 'Resultados para:' (linha 82). Com busca="><script>...</script> o HTML é quebrado e o script roda na origem do painel.
- **Impacto:** Basta um link para o técnico (index.php?busca=...) para executar JavaScript na sessão dele: ler toda a base pela interface, agir em nome dele. Também quebra o HTML por acidente com termos que tenham aspas ou '<'.
- **O que fiz:** Aplicado htmlspecialchars() nas duas saídas. A rota e o parâmetro busca não mudaram.
- **Corrigido:** sim

### F6: Credenciais de produção embutidas no código

- **Onde:** `code/config.php`, linha 12–15 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **95**
- **Mecanismo:** DB_PASS tem fallback literal 'N3tX@2013!prod' e SMTP_API_KEY é uma constante literal. Os dois segredos ficam em qualquer cópia do código (repositório, backup, este pacote). Como o fallback é usado em silêncio, ninguém nota se a variável de ambiente faltar.
- **Impacto:** Quem tem acesso ao código tem a senha do banco de produção e a chave da API de e-mail (permite enviar e-mail como a NetX, útil para phishing contra clientes).
- **O que fiz:** Os dois valores agora vêm só do ambiente (getenv), sem fallback, e as constantes continuam definidas com os mesmos nomes. Os segredos antigos têm de ser ROTACIONADOS: tirar do código não desfaz o vazamento.
- **Corrigido:** sim

### F7: Senhas com md5 sem salt e comparação fora de tempo constante

- **Onde:** `code/lib.php`, linha 15–17 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **95**
- **Mecanismo:** autenticar() grava e compara md5($senha) puro (schema.sql:7, CHAR(32)). md5 sem salt se inverte por tabela pré-computada. Nos dados de exemplo, ana e bruno têm o mesmo hash, e carla e diego também: um vazamento (ver F1) revela senhas e quais contas as compartilham.
- **Impacto:** Combinado com F1, um cliente recupera as senhas reais dos técnicos em segundos e passa a ter acesso total.
- **O que fiz:** autenticar() busca o usuário pelo login e verifica a senha com password_verify(). Hashes md5 antigos ainda são aceitos, comparados com hash_equals(), e regravados com password_hash() no login seguinte. Essa regravação só acontece se a coluna já tiver 255 caracteres; com CHAR(32), truncaria o hash e trancaria o usuário. schema.sql passou a declarar VARCHAR(255) e traz o ALTER TABLE de migração. Assinatura e retorno ['id','nome','papel'] não mudaram.
- **Corrigido:** sim

### F8: Arquivo de exportação fixo em diretório sob /var/www

- **Onde:** `code/lib.php`, linha 125–150 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **alta** / **70**
- **Mecanismo:** exportarCsv() grava sempre o mesmo caminho EXPORT_DIR/chamados.csv (/var/www/painel/tmp, config.php:18) e depois faz readfile. O arquivo com todos os chamados fica em disco depois da requisição. Se tmp/ estiver sob o document root, alguém baixa /tmp/chamados.csv sem login. Duas exportações simultâneas também escrevem no mesmo arquivo, e uma pode servir o conteúdo da outra.
- **Impacto:** Vazamento da base de chamados sem autenticação, se o diretório for servido pela web. Com dois exports em paralelo, o CSV sai corrompido ou com o conteúdo do outro usuário.
- **O que fiz:** O CSV agora vai direto para php://output, sem arquivo intermediário, com os mesmos headers, cabeçalho, ordem e escaping (fputcsv com os mesmos delimitadores, escape '\\' explícito). EXPORT_DIR continua definido para quem mais o use. Confiança 70 porque não sei se tmp/ é servido pelo servidor web.
- **Corrigido:** sim

### F9: Divisão por zero em mediaResposta derruba a página inicial

- **Onde:** `code/lib.php`, linha 116 (numeração original)
- **Categoria / severidade / confiança:** bug / **media** / **95**
- **Mecanismo:** Se nenhum chamado tem minutos_resposta (base nova, ou após arquivar os respondidos), $qtd é 0 e $soma / $qtd lança DivisionByZeroError no PHP 8. Como index.php chama mediaResposta() em toda listagem, o erro é fatal antes de qualquer HTML (reproduzido no banco de teste).
- **Impacto:** A tela principal fica indisponível para todos os usuários, e o relatório gerencial que chama a função também quebra.
- **O que fiz:** Sem registros respondidos, a função devolve 0.0. Nos demais casos o float é idêntico ao anterior (SUM/COUNT no banco e divisão no PHP; não usei AVG(), que arredonda a 4 casas).
- **Corrigido:** sim

### F10: N+1 consultas para o nome do técnico

- **Onde:** `code/lib.php`, linha 89 (numeração original)
- **Categoria / severidade / confiança:** performance / **media** / **95**
- **Mecanismo:** listarChamados() e exportarCsv() (linha 137) chamam tecnicoNome() para cada linha, e cada chamada faz um SELECT. Com N chamados são N+1 idas ao banco; a exportação noturna da base toda faz milhares de consultas.
- **Impacto:** Latência e carga no banco crescem linearmente com o volume; a listagem sem paginação piora tudo.
- **O que fiz:** Troquei por LEFT JOIN usuarios com COALESCE(t.nome, '-'). O resultado é igual: '-' quando tecnico_id é NULL ou aponta para usuário inexistente. tecnicoNome() foi mantida, porque pode ter outros chamadores.
- **Corrigido:** sim

### F11: Exportação falha em silêncio e vaza handle

- **Onde:** `code/lib.php`, linha 126–135 (numeração original)
- **Categoria / severidade / confiança:** bug / **media** / **85**
- **Mecanismo:** Se fopen() falha (diretório ausente ou sem permissão), a função retorna sem headers nem conteúdo, e o navegador recebe 200 vazio. Se a query falha, retorna sem fclose() e deixa chamados.csv truncado só com o cabeçalho, porque o modo 'w' já truncou o export anterior.
- **Impacto:** Usuário e rotina noturna recebem um 'sucesso' vazio ou incompleto sem nenhum sinal de erro; a integração de faturamento pode processar um CSV vazio.
- **O que fiz:** Resolvido junto com F8: sem arquivo intermediário não há fopen de disco que falhe. Um erro de banco agora vira exceção mysqli (padrão do PHP 8.1+) em vez de resposta vazia.
- **Corrigido:** sim

### F12: formatarStatus rotula qualquer status desconhecido como 'Resolvido'

- **Onde:** `code/lib.php`, linha 32–34 (numeração original)
- **Categoria / severidade / confiança:** bug / **media** / **75**
- **Mecanismo:** O else final cobre tudo que não é 1 ou 2. status é TINYINT sem CHECK (schema.sql:18), então 0, 4 (um futuro 'cancelado') ou dado corrompido viram 'Resolvido'.
- **Impacto:** O relatório gerencial, que conta pelos textos, contaria como resolvidos chamados que não foram, e o painel mostraria chamado aberto como resolvido.
- **Por que não corrigi / proposta:** Não alterado. O manifesto fixa o contrato de valor em 1/2/3, e mudar o rótulo de valores fora disso altera o que o relatório gerencial recebe hoje. Proposta: CHECK (status IN (1,2,3)) no banco e, combinado com os consumidores, um rótulo explícito para valores inválidos.
- **Corrigido:** não

### F13: Injeção de fórmula no CSV exportado

- **Onde:** `code/lib.php`, linha 138–144 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **media** / **65**
- **Mecanismo:** Títulos são texto livre e vão para o CSV sem neutralização. Um título que começa com =, +, - ou @ (ex.: =HYPERLINK(...)) é interpretado como fórmula quando o gerente abre o arquivo no Excel ou LibreOffice.
- **Impacto:** Quem cria um chamado pode executar fórmulas na planilha de quem abre o export (exfiltração por HYPERLINK, avisos de DDE).
- **Por que não corrigi / proposta:** Não alterado. Prefixar ' mudaria o valor da coluna Titulo, que a integração de faturamento consome como dado. Pedir confirmação aos consumidores antes.
- **Corrigido:** não

### F14: Sessão não é regenerada no login e cookie sem HttpOnly/SameSite

- **Onde:** `code/index.php`, linha 15–24 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **media** / **85**
- **Mecanismo:** O login grava uid e papel na sessão que já existia, sem session_regenerate_id(). Um id de sessão plantado antes do login (fixação) passa a valer como sessão autenticada. O cookie sai sem HttpOnly, e um XSS (F5) consegue lê-lo.
- **Impacto:** Sequestro da sessão de um técnico.
- **O que fiz:** session_regenerate_id(true) após autenticar e session_set_cookie_params com httponly e samesite=Lax antes de session_start(). Verificado: o PHPSESSID muda no login. O flag 'secure' não foi ligado; ver Decisões.
- **Corrigido:** sim

### F15: Login sem limite de tentativas

- **Onde:** `code/index.php`, linha 20–29 (numeração original)
- **Categoria / severidade / confiança:** seguranca / **media** / **80**
- **Mecanismo:** O POST de login aceita tentativas ilimitadas, sem atraso, bloqueio ou registro. Com md5 e senhas fracas e repetidas (seed: senha123, tecmaster), força bruta online é viável.
- **Impacto:** Comprometimento de contas por adivinhação de senha.
- **Por que não corrigi / proposta:** Não alterado. Exige estado persistente (tabela de tentativas) ou infraestrutura (fail2ban/WAF), o que muda o schema ou depende de quem opera. Recomendado como próximo passo.
- **Corrigido:** não

### F16: Parâmetros em formato de array derrubam a página (TypeError)

- **Onde:** `code/index.php`, linha 22–72 (numeração original)
- **Categoria / severidade / confiança:** bug / **baixa** / **90**
- **Mecanismo:** busca[]=x faz $busca virar array: listarChamados(string) lança TypeError, e 'Resultados para: ' . $busca também falha. login[]=x faz o mesmo em autenticar(). O resultado é erro fatal 500, com caminho do arquivo se display_errors estiver ligado.
- **Impacto:** Erro 500 trivial de provocar, possível vazamento de caminhos e ruído nos logs.
- **O que fiz:** Valores que não são string passam a ser tratados como busca vazia ou login inválido.
- **Corrigido:** sim

### F17: Autorização só no index.php; funções da lib não têm contexto de usuário

- **Onde:** `code/lib.php`, linha 78–102 (numeração original)
- **Categoria / severidade / confiança:** arquitetura / **baixa** / **70**
- **Mecanismo:** listarChamados, verChamado e exportarCsv não recebem quem está pedindo. A regra de visibilidade, que faltava (F2-F4), só pode ser aplicada no chamador. Todo novo ponto de entrada precisa lembrar de reimplementá-la.
- **Impacto:** Uma nova tela ou script que use as funções públicas direto reabre o vazamento entre clientes.
- **Por que não corrigi / proposta:** Parcial: criei helpers internos (donoVisivel, listarChamadosDoDono, exportarCsvDoDono) que concentram a regra num só lugar. As funções públicas não mudaram, por contrato, e continuam sem filtro, que é o que as rotinas internas esperam. Por isso fica corrigido=false.
- **Corrigido:** não

### F18: rotuloPrioridade com quatro níveis de if/else aninhados

- **Onde:** `code/lib.php`, linha 40–59 (numeração original)
- **Categoria / severidade / confiança:** qualidade / **baixa** / **90**
- **Mecanismo:** A regra de SLA tem quatro níveis de aninhamento com else redundante após return, e fica difícil ver qual combinação gera qual rótulo (por exemplo, que prioridade 4 dentro do SLA sai como 'Alto - dentro do SLA').
- **Impacto:** Risco de regressão a cada ajuste de regra e revisão difícil.
- **O que fiz:** Reescrita com cláusulas de guarda e as mesmas saídas. Verifiquei as 35 combinações de prioridade (-1..5) × minutos (null, 0, 30, 31, 100) contra a versão original. As escolhas de rótulo que parecem duvidosas não foram mexidas; ver Decisões.
- **Corrigido:** sim

### F19: SQL montado por concatenação em tecnicoNome

- **Onde:** `code/lib.php`, linha 69 (numeração original)
- **Categoria / severidade / confiança:** qualidade / **baixa** / **40**
- **Mecanismo:** O id é concatenado ao SQL. Hoje não é explorável, porque o parâmetro é ?int; a segurança depende só do type hint, e uma mudança de tipo ou cópia do padrão vira injeção.
- **Impacto:** Risco latente; nada explorável hoje.
- **O que fiz:** Convertido para prepared statement; o retorno (string nome ou '-') é o mesmo.
- **Corrigido:** sim

## 3. Decisões

### O que fiz e por quê, em resumo

- **A regra de visibilidade fica no ponto de entrada web, não nas funções públicas.** As assinaturas de `listarChamados`, `verChamado` e `exportarCsv` são contrato e não têm parâmetro de usuário. A rotina noturna e o relatório gerencial chamam essas funções sem sessão e precisam da base inteira. Por isso criei helpers internos (`donoVisivel`, `listarChamadosDoDono`, `exportarCsvDoDono`), e as funções públicas viraram wrappers com o comportamento de antes. A regra segue o manifesto à risca:
  - técnico vê tudo, inclusive chamados sem técnico atribuído e atribuídos a outro técnico;
  - cliente vê só onde `usuario_id` é o próprio id;
  - papel desconhecido é tratado como cliente (negação por padrão).
- **Cliente que exporta CSV recebe os próprios chamados, sem 403.** A rota `?export=csv` é contrato e o link aparece para todos. Bloquear o cliente esconderia dados que ele tem direito de ver; entregar tudo expõe dados alheios. Filtrar mantém cabeçalho, formato e ordem.
- **Tipos de retorno preservados.** Os primeiros testes mostraram que o prepared statement (protocolo binário do mysqlnd) devolve `int` nativo onde o `query()` antigo devolvia `"105"`. Um consumidor que compare com `===` ou serialize em JSON quebraria. Por isso a listagem normaliza os valores para string, e `verChamado` (já seguro, com `int $id`) ficou como estava.
- **`mediaResposta` devolve o mesmo float.** Usei SUM/COUNT em vez de `AVG()`, porque o `AVG` do MySQL arredonda a 4 casas e mudaria o valor que o relatório gerencial recebe.
- **A migração de senha não pode trancar ninguém.** O hash novo só é gravado se a coluna já tiver 255 caracteres. Num servidor sem strict mode, gravar bcrypt em `CHAR(32)` truncaria o hash em silêncio e o usuário perderia o acesso.
- **O CSV não passa mais por disco.** O manifesto define que `exportarCsv` "escreve o CSV na saída", e isso continua valendo. A constante `EXPORT_DIR` foi mantida para outros scripts que incluam `config.php`. **Ponto a confirmar com a operação:** se algum processo externo lia `/var/www/painel/tmp/chamados.csv` como efeito colateral, ele deve passar a chamar `exportarCsv()` ou buscar a rota. Esse arquivo não faz parte do contrato publicado e era justamente o vetor de vazamento.

### O que deliberadamente NÃO mudei

- **Assinaturas, nomes e semântica das funções públicas (listarChamados, verChamado, exportarCsv continuam sem filtro por usuário)**: Contrato do manifesto. A rotina noturna e o relatório gerencial chamam sem sessão e esperam a base inteira. A regra de visibilidade ficou no ponto de entrada web, por helpers novos.
- **formatarStatus para valores fora de 1..3 (F12)**: O contrato de valor é consumido por correspondência de texto; mudar o rótulo de valores inválidos altera o que o relatório gerencial recebe. Proposta: CHECK no banco e combinar com os consumidores.
- **Semântica dos rótulos de rotuloPrioridade (prioridade 4 dentro do SLA sai como 'Alto - dentro do SLA'; prioridade 1 sai como 'Normal'; prioridade 4 sem resposta sai como 'Aguardando 1a resposta')**: Parece regra de negócio questionável, mas o rótulo é saída pública e não há especificação dizendo que está errado. Só refatorei a forma.
- **Neutralização de fórmulas no CSV (F13)**: Mudaria os valores da coluna Titulo consumidos pela integração de faturamento; precisa de acordo com os consumidores.
- **Rate limiting / bloqueio de login (F15)**: Exige tabela nova ou infraestrutura externa; fora do escopo de uma correção compatível.
- **verChamado continua com o id concatenado**: O parâmetro é int tipado, então não é explorável. Trocar por prepared statement mudaria os tipos das colunas devolvidas (o protocolo binário do mysqlnd dá int nativo em vez de string), o que verifiquei em teste; isso mudaria o formato de retorno do contrato.
- **Flag 'secure' no cookie de sessão e headers de segurança (CSP etc.)**: Não sei se o painel é servido só por HTTPS. Com secure ligado em HTTP o login para de funcionar. Recomendado ligar quando o HTTPS for confirmado.
- **Coringas % e _ do LIKE dentro do termo de busca**: Com prepared statement não são vulnerabilidade, e usuários podem usar % de propósito; escapá-los muda o resultado da busca.
- **Tempo médio de 1a resposta global exibido também para clientes**: É um agregado sem dados identificáveis, e restringir mudaria o indicador que o produto mostra hoje. Vale validar com o produto.
- **Papel guardado na sessão no login (não é relido a cada requisição); ausência de logout e de CSRF no formulário de login**: Riscos baixos. Logout seria rota nova e reler o papel adiciona consulta por requisição; deixei registrado como melhoria.
- **Estrutura do index.php (roteamento, autenticação e HTML num arquivo só, sem template) e falta de paginação na listagem**: Separar camadas ou paginar é reescrita ou mudança de contrato (estrutura HTML e rotas). A tarefa pede evolução, não reescrita.
- **Dados de seed.sql (senhas md5)**: São o caso legado que a migração transparente precisa continuar aceitando, e o manifesto documenta essas credenciais de teste.

### Arquivos alterados

| Arquivo | Mudança |
| --- | --- |
| `code/lib.php` | prepared statements; JOIN no lugar de N+1; helpers de visibilidade; export em streaming; senha com `password_verify` e migração; guarda de divisão por zero; refatoração de `rotuloPrioridade` |
| `code/index.php` | regra de visibilidade nas três rotas; `htmlspecialchars` na busca; `session_regenerate_id` e flags do cookie; validação de tipo dos parâmetros |
| `code/config.php` | segredos só via ambiente |
| `code/schema.sql` | `usuarios.senha` como `VARCHAR(255)`, com a instrução de migração |
| `code/seed.sql` | sem alteração |

Nenhum arquivo foi renomeado ou movido. Rotas (`busca`, `ver`, `export`), rótulos de status, cabeçalho e ordem do CSV, `id="tabela-chamados"`, ordem das colunas e links `index.php?ver=<id>` estão como antes.
