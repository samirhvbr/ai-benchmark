# RELATORIO — LEB-100-A · Painel de Chamados (NetX ISP)

Instância **LEB-100-A** (nível 100, v1.1) · matriz de avaliação `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625` · modo A (agêntico).
Todos os números de linha são da **numeração original** dos arquivos recebidos. O `achados.json` é o índice estruturado destes mesmos achados (mesmos ids).

## 1. Resumo

O sistema é um painel PHP/mysqli de chamados de suporte, curto (cerca de 300 linhas) mas com problemas sérios. A regra de negócio mais importante do manifesto, *cliente só vê o que abriu*, **não estava implementada em lugar nenhum**: o `index.php` lê `$uid`/`$papel` da sessão (L38–39) e os descarta. Somada a uma SQL injection na busca, isso deixava qualquer cliente logado ler todos os chamados (pela listagem, pelo detalhe e pelo CSV) e até os hashes MD5 das senhas de todos os usuários, técnicos incluídos. Há ainda XSS refletido, credenciais de produção no código, fixação de sessão, uma exportação por arquivo fixo compartilhado que corrompe downloads concorrentes, uma divisão por zero que derruba a tela inicial e um N+1 que deixa listagem e CSV de 29 a 48 vezes mais lentos que o necessário.

**Resultado:** 24 achados (1 crítico, 7 altos, 7 médios, 9 baixos); 21 corrigidos no código e 3 apenas reportados, com justificativa. As correções são **evolução, não reescrita**: os mesmos 5 arquivos nos mesmos caminhos, mysqli mantido, nenhuma dependência nova, e as 7 funções do contrato (mais `tecnicoNome`) com o mesmo nome e a mesma assinatura. O `lib.php` passou de 151 para 326 linhas, em sua maior parte docblocks e funções auxiliares e variantes novas; o `index.php` mudou nas linhas estritamente necessárias.

Assinaturas, rotas, formato do CSV, HTML da listagem, rótulos de status e a regra de visibilidade do manifesto foram preservados e **verificados por execução** (seção 4): para técnicos, as respostas HTTP são idênticas byte a byte às do original, e o CSV do seed também (399 bytes). O que muda de comportamento é só o que precisava mudar: clientes deixam de ver o que é alheio e as entradas de ataque deixam de funcionar.

**Antes de publicar** (seção 5): `DB_PASS` passa a ser **obrigatório no ambiente** (sem ele o painel responde 503), e a senha do banco e a chave SMTP que estavam no código devem ser rotacionadas.

## 2. Achados

Ordem em que eu priorizaria a correção. A tabela é o índice; os blocos abaixo dela trazem o mecanismo de cada um.

| ID | Sev. | Conf. | Categoria | Onde | Achado | Corrigido |
| --- | --- | --- | --- | --- | --- | --- |
| F1 | critica | 99 | seguranca | `code/lib.php:81–85` | SQL injection em `listarChamados()`: o termo de busca é concatenado no LIKE | sim |
| F2 | alta | 99 | seguranca | `code/index.php:52–67` | IDOR em `index.php?ver=<id>`: cliente lê o chamado de outro cliente | sim |
| F3 | alta | 99 | seguranca | `code/index.php:72–73` | Listagem mostra todos os chamados a qualquer cliente | sim |
| F4 | alta | 98 | seguranca | `code/index.php:44–47` | Exportação CSV entrega todos os chamados a qualquer cliente | sim |
| F5 | media | 80 | arquitetura | `code/lib.php:78–102` | A regra de visibilidade não tem ponto de aplicação: API de dados sem identidade e `$uid`/`$papel` descartados | sim |
| F6 | alta | 98 | seguranca | `code/index.php:79–82` | XSS refletido: o termo de busca é impresso sem escape em dois pontos | sim |
| F7 | alta | 96 | seguranca | `code/config.php:11–12` | Senha de produção do banco embutida em `config.php` como fallback | sim |
| F8 | alta | 96 | seguranca | `code/lib.php:15–20` | Senhas guardadas como MD5 sem sal | sim |
| F9 | alta | 97 | bug | `code/lib.php:125–150` | Exportação grava num arquivo único compartilhado: downloads concorrentes saem corrompidos | sim |
| F10 | media | 90 | seguranca | `code/index.php:23–27` | Fixação de sessão: o id de sessão não é trocado ao autenticar | sim |
| F11 | media | 65 | seguranca | `code/index.php:20–36` | Login sem limite de tentativas (força bruta) | **não** |
| F12 | media | 88 | seguranca | `code/config.php:14–15` | Chave da API de e-mail embutida em `config.php` (e sem uso) | sim |
| F13 | media | 55 | seguranca | `code/config.php:17–18` | Cópia de todos os chamados fica em disco, com nome previsível, dentro da árvore da aplicação | sim |
| F14 | media | 97 | bug | `code/lib.php:116` | `mediaResposta()` divide por zero e derruba a tela inicial | sim |
| F15 | media | 98 | performance | `code/lib.php:64–91` | N+1: uma consulta por chamado só para buscar o nome do técnico | sim |
| F16 | baixa | 70 | bug | `code/index.php:9–12` | Tratamento de falha de conexão nunca executa no PHP 8.1+ | sim |
| F17 | baixa | 92 | bug | `code/index.php:22` | Parâmetros em formato de array derrubam a requisição (TypeError, HTTP 500) | sim |
| F18 | baixa | 75 | seguranca | `code/index.php:15` | Cookie de sessão sem HttpOnly e sem SameSite | sim |
| F19 | baixa | 55 | seguranca | `code/lib.php:138–144` | Injeção de fórmula no CSV: título controlado pelo cliente vai cru para a planilha | sim |
| F20 | baixa | 80 | performance | `code/lib.php:109–115` | `mediaResposta()` traz todas as linhas para somar em PHP | sim |
| F21 | baixa | 55 | performance | `code/lib.php:80–93` | Listagem sem paginação e com `SELECT *` (traz a coluna TEXT `descricao`) | **não** |
| F22 | baixa | 78 | qualidade | `code/lib.php:40–59` | `rotuloPrioridade()` com if/else aninhado em quatro níveis e números mágicos | sim |
| F23 | baixa | 45 | bug | `code/lib.php:130–138` | `fputcsv()` sem o parâmetro `$escape`: depreciado no PHP 8.4 | sim |
| F24 | baixa | 35 | qualidade | `code/lib.php:26–35` | `formatarStatus()` rotula qualquer valor desconhecido como 'Resolvido' | **não** |

### F1 — SQL injection em `listarChamados()`: o termo de busca é concatenado no LIKE

**Onde:** `code/lib.php:81–85` · **Categoria:** seguranca · **Severidade:** critica · **Confiança:** 99 · **Corrigido:** sim

- **Mecanismo.** `index.php:72` repassa `$_GET['busca']` sem validação e `lib.php:82` a concatena dentro de `LIKE '%...%'`; a string vai direto para `$db->query()` (L85). Um apóstrofo fecha o literal e o `SELECT *` de 9 colunas aceita `UNION SELECT id,1,NULL,senha,login,1,1,1,NOW() FROM usuarios`, cujo resultado aparece na coluna Titulo da tabela. Reproduzido logado como `ana` (cliente): a listagem exibiu os hashes MD5 dos 4 usuários; um apóstrofo isolado gerou HTTP 500 (`mysqli_sql_exception` não tratada).
- **Impacto.** Qualquer cliente autenticado lê qualquer tabela ou coluna que a conta do painel enxergue (hashes de senha dos técnicos, chamados de todos), anula a regra de visibilidade e provoca erros 500 à vontade. `query()` não executa múltiplos comandos, então o dano é de leitura, não de escrita.
- **O que fiz.** Consulta preparada: o termo vai como parâmetro de `LIKE ?` (`filtroDeChamados()` + `consultaPreparada()` em `lib.php`). O mesmo payload agora devolve 0 linhas com HTTP 200; buscas legítimas ficam inalteradas (os curingas `%` e `_` foram preservados de propósito, ver Decisões).

### F2 — IDOR em `index.php?ver=<id>`: cliente lê o chamado de outro cliente

**Onde:** `code/index.php:52–67` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 99 · **Corrigido:** sim

- **Mecanismo.** A L53 converte `$_GET['ver']` em inteiro e chama `verChamado()` (`lib.php:98-102`), que executa `SELECT * FROM chamados WHERE id = N` sem nenhuma condição de dono. `$uid` e `$papel` são lidos nas L38-39 e nunca usados: a regra do manifesto (cliente só vê o que abriu) não existe no código. Reproduzido: `ana` (cliente) abriu `?ver=103`, chamado do `bruno`, e recebeu o título 'Troca de plano' e a descrição completa; como os ids são sequenciais, basta iterar para varrer a base inteira.
- **Impacto.** Qualquer cliente logado lê título, status, prioridade e descrição de todos os chamados dos demais clientes (queixas de cobrança, falhas, dados pessoais citados nos textos).
- **O que fiz.** Nova `verChamadoVisivel($db,$id,$uid,$papel)`: técnico sem restrição; qualquer outro papel com `AND usuario_id = ?`. `index.php` passa a usá-la e chamado alheio recebe exatamente a resposta de chamado inexistente ('Chamado nao encontrado.'), sem confirmar que o id existe. `verChamado()` mantém assinatura e comportamento para as rotinas internas. Verificado: `ana` não vê o 103; `bruno` vê 103 e 104 e não vê o 101; os técnicos veem todos, com HTML idêntico ao original.

### F3 — Listagem mostra todos os chamados a qualquer cliente

**Onde:** `code/index.php:72–73` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 99 · **Corrigido:** sim

- **Mecanismo.** `listarChamados($db,$busca)` não recebe identidade (assinatura fixada pelo manifesto) e `index.php:73` também não filtra o resultado; o laço das L88-96 renderiza tudo. Reproduzido: `ana` viu os chamados 101 a 105, incluindo o 103 e o 104 do `bruno`, com título, status e técnico, cada um com link para o detalhe.
- **Impacto.** Exposição dos metadados de todos os clientes e porta de entrada para enumerar ids (F2); contraria a regra de visibilidade do manifesto.
- **O que fiz.** Nova `listarChamadosVisiveis($db,$uid,$papel,$busca)`: cliente com `WHERE c.usuario_id = ?` (o índice `idx_usuario` já existe), técnico sem restrição; a busca por título se combina com o filtro. Verificado com os 4 usuários do seed: `ana` vê 101, 102 e 105; `bruno` vê 103 e 104; `carla` e `diego` veem os 5. A `listarChamados()` original segue irrestrita (contrato).

### F4 — Exportação CSV entrega todos os chamados a qualquer cliente

**Onde:** `code/index.php:44–47` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 98 · **Corrigido:** sim

- **Mecanismo.** O handler das L44-47 chama `exportarCsv($db)`, que faz `SELECT * FROM chamados ORDER BY id` (`lib.php:132`) sem filtro, e não consulta o papel. Reproduzido: `ana` baixou um CSV com as 5 linhas, incluindo o 103 e o 104 do `bruno`.
- **Impacto.** Dump completo da base de chamados, com um único request, por qualquer cliente autenticado.
- **O que fiz.** Nova `exportarCsvVisivel($db,$uid,$papel)`: cliente exporta só os seus (`ana`: 101, 102, 105; `bruno`: 103, 104), técnico exporta todos. Cabeçalho, ordenação por id e rótulos de status seguem o manifesto; o CSV do técnico é byte a byte igual ao original. `exportarCsv($db)` continua exportando tudo (rotina noturna).

### F5 — A regra de visibilidade não tem ponto de aplicação: API de dados sem identidade e `$uid`/`$papel` descartados

**Onde:** `code/lib.php:78–102` · **Categoria:** arquitetura · **Severidade:** media · **Confiança:** 80 · **Corrigido:** sim

- **Mecanismo.** As funções públicas congeladas pelo manifesto (`listarChamados`, `verChamado`, `exportarCsv`) não recebem usuário nem papel, e a única camada que conhece a sessão (`index.php`) lê `$uid` e `$papel` (L38-39) e os descarta. A regra do manifesto existe só como texto: nenhuma camada do código a aplica, o que é a causa-raiz de F2, F3 e F4.
- **Impacto.** Qualquer rota nova ou rotina que reutilize essas funções repete o vazamento; a correção não cabe numa função isolada, exige uma API que carregue a identidade.
- **O que fiz.** `escopoDoUsuario($uid,$papel)` concentra a política num único lugar (técnico = sem restrição; qualquer outro valor = só o dono, ou seja, papel desconhecido cai no menor privilégio) e alimenta as variantes aditivas `listarChamadosVisiveis`, `verChamadoVisivel` e `exportarCsvVisivel`. As assinaturas do manifesto ficaram intactas (conferido por Reflection) e `index.php` só usa as variantes com escopo.

### F6 — XSS refletido: o termo de busca é impresso sem escape em dois pontos

**Onde:** `code/index.php:79–82` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 98 · **Corrigido:** sim

- **Mecanismo.** `$busca = $_GET['busca']` (L72) é concatenado cru no atributo `value` do campo (L79) e no parágrafo 'Resultados para:' (L82). Aspas duplas fecham o atributo e `<` entra direto no corpo. Reproduzido: um termo com aspas duplas e uma tag script voltou intacto na resposta, e um termo que fecha o atributo e acrescenta `onfocus` virou um manipulador de evento executável. O cookie de sessão não tinha HttpOnly (F18), então o script alcança `document.cookie`.
- **Impacto.** Um link preparado enviado a um técnico (ou cliente) executa JavaScript na sessão dele: roubo de cookie, leitura de todos os chamados e ações em nome do usuário.
- **O que fiz.** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos (valor calculado uma vez, em `$buscaHtml`). Verificado: nenhuma tag script crua na resposta e as aspas viram `&quot;`. Os `htmlspecialchars` que já existiam (título, descrição, nome do técnico) estavam corretos e não foram tocados.

### F7 — Senha de produção do banco embutida em `config.php` como fallback

**Onde:** `code/config.php:11–12` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 96 · **Corrigido:** sim

- **Mecanismo.** `define('DB_PASS', getenv('DB_PASS') ?: '<senha literal>')`: sem a variável de ambiente o processo conecta com a senha de produção escrita no código, que passa a viver em todo clone, cópia de segurança e no histórico do repositório, legível por quem ler o arquivo. (O valor não é reproduzido neste relatório de propósito.)
- **Impacto.** Quem tiver acesso ao repositório, a um backup ou ao arquivo obtém credenciais do banco com os privilégios da conta `painel`.
- **O que fiz.** Sem valor embutido: `getenv('DB_PASS') ?: ''` e um `error_log` quando ausente. Pré-requisito de implantação: definir `DB_PASS` no ambiente do PHP-FPM/Apache, senão o painel responde 503 (F16). A senha antiga deve ser tratada como comprometida e rotacionada: removê-la do código não a tira do histórico. Verificado que a string não aparece mais em `code/`.

### F8 — Senhas guardadas como MD5 sem sal

**Onde:** `code/lib.php:15–20` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 96 · **Corrigido:** sim

- **Mecanismo.** `autenticar()` calcula `md5($senha)` e compara em SQL (`WHERE login = ? AND senha = ?`); `schema.sql:7` define `senha CHAR(32)` ('hash md5 (legado)'). MD5 é rápido e sem sal: senhas iguais geram hashes iguais (no seed, `ana` e `bruno` têm o mesmo hash, `carla` e `diego` também) e senhas curtas caem em segundos por tabela ou GPU. Somado a F1, os hashes saíram pela própria listagem.
- **Impacto.** Qualquer vazamento da tabela (SQLi, backup) equivale a senhas em claro, inclusive as dos técnicos, que veem todos os chamados.
- **O que fiz.** `autenticar()` busca por login e verifica `password_verify()` para hashes novos, ou o MD5 legado com `hash_equals` (sem diferenciar maiúsculas, como a comparação em SQL fazia). No primeiro login bem-sucedido com MD5 regrava o hash com `password_hash()`, mas somente se a coluna comportar (consulta a `information_schema`): em modo SQL não estrito o `UPDATE` truncaria o hash e trancaria o usuário (demonstrado). `schema.sql` passa a `VARCHAR(255)`; em bancos existentes é preciso rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`, e sem ele nada muda (o MD5 segue valendo e nada é regravado). A migração é gradual, usuário a usuário. Verificado com coluna estreita e larga, em modo estrito e não estrito; um byte NUL na senha (o bcrypt recusa) deixa o login funcionando com o hash MD5 intacto; senhas com mais de 72 bytes passam a valer só pelos 72 primeiros depois de migradas (limite do bcrypt).

### F9 — Exportação grava num arquivo único compartilhado: downloads concorrentes saem corrompidos

**Onde:** `code/lib.php:125–150` · **Categoria:** bug · **Severidade:** alta · **Confiança:** 97 · **Corrigido:** sim

- **Mecanismo.** `exportarCsv()` abre `EXPORT_DIR/chamados.csv` (nome fixo) com `fopen('w')` (L125-126), escreve linha a linha (L136-145), fecha (L146) e depois devolve com `readfile()` do mesmo caminho (L150), sem trava e sem nome por requisição. Um segundo pedido que chegue no meio trunca o arquivo: o primeiro continua gravando no seu offset (deixando um buraco de bytes NUL) ou relê o que o outro escreveu. As falhas são silenciosas: se `fopen` falha (L127-129) ou a consulta falha (L133-135, que ainda deixa o handle aberto) a função retorna sem cabeçalho nem corpo. Reproduzido com 8 workers, 8 sessões, 5 exportações cada, início escalonado, 20.005 chamados: 15 de 40 respostas corrompidas (por exemplo, 1.585.176 bytes dos quais 1.583.112 eram NUL e só 27 linhas legíveis). Com início simultâneo todas vieram íntegras, porque no original todos exportam os mesmos bytes: a corrupção exige sobreposição escalonada.
- **Impacto.** CSV truncado ou zerado entregue como se fosse sucesso a quem consome (rotina noturna, faturamento, gerencial); e, assim que a visibilidade por papel é aplicada (F4), o mesmo arquivo passaria a vazar o CSV de um papel para outro.
- **O que fiz.** `emitirCsv()` envia o CSV direto em `php://output`: sem arquivo, sem estado compartilhado, com os mesmos cabeçalhos HTTP e os mesmos bytes (os 399 bytes do seed saem idênticos). Mesmo teste de carga no código novo: 40 de 40 respostas íntegras. `EXPORT_DIR` continua definida, mas o painel não a usa mais.

### F10 — Fixação de sessão: o id de sessão não é trocado ao autenticar

**Onde:** `code/index.php:23–27` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 90 · **Corrigido:** sim

- **Mecanismo.** Ao autenticar, o código grava `uid` e `papel` na sessão que já existia (L24-25) sem `session_regenerate_id()`, e `session.use_strict_mode` está Off, então o PHP aceita e cria uma sessão com qualquer id que o cliente apresente. Reproduzido: com o cookie PHPSESSID plantado, o login de `carla` (técnica) fez com que um segundo cliente, usando só aquele id, acessasse a listagem autenticado.
- **Impacto.** Quem conseguir plantar um id (via XSS F6, subdomínio ou rede) assume a sessão da vítima, inclusive de técnicos, sem saber a senha.
- **O que fiz.** `session_regenerate_id(true)` antes de gravar `uid`/`papel` e `session.use_strict_mode=1`. Reproduzido de novo: com o id plantado, o segundo cliente cai na tela de login.

### F11 — Login sem limite de tentativas (força bruta)

**Onde:** `code/index.php:20–36` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 65 · **Corrigido:** não (apenas reportado)

- **Mecanismo.** O login aceita POSTs ilimitados e `autenticar()` só responde sim ou não: não há contador, atraso, bloqueio nem captcha, e o hash por trás dele era MD5 (F8). Não houve teste de carga; é leitura do fluxo das L20-36.
- **Impacto.** Adivinhação online de senhas, em especial de contas com senhas fracas (o seed usa `senha123` e `tecmaster`).
- **O que fiz.** Não implementado: um contador por conta ou IP exige estado persistente (tabela nova ou cache), fora do que posso alterar sem DDL novo, e é melhor tratado no perímetro (proxy, WAF, fail2ban). Fica como recomendação.

### F12 — Chave da API de e-mail embutida em `config.php` (e sem uso)

**Onde:** `code/config.php:14–15` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 88 · **Corrigido:** sim

- **Mecanismo.** `SMTP_API_KEY` é um literal no código (L15) e nenhum arquivo de `code/` a usa (confirmado por leitura e por busca): é um segredo que viaja com o repositório sem cumprir função alguma aqui.
- **Impacto.** Uso indevido da conta de e-mail transacional (spam ou phishing em nome do ISP, custo) por quem ler o código.
- **O que fiz.** A constante continua definida, agora via `getenv('SMTP_API_KEY') ?: ''`, para não quebrar consumidores externos. A chave exposta deve ser rotacionada.

### F13 — Cópia de todos os chamados fica em disco, com nome previsível, dentro da árvore da aplicação

**Onde:** `code/config.php:17–18` · **Categoria:** seguranca · **Severidade:** media · **Confiança:** 55 · **Corrigido:** sim

- **Mecanismo.** `EXPORT_DIR` aponta para `/var/www/painel/tmp`, dentro do diretório da aplicação, e `exportarCsv()` nunca apaga o `chamados.csv` (`lib.php` L146-150). Reproduzido: depois de um único export o arquivo, com os chamados de todos os clientes, permaneceu no disco. Se o servidor web publicar `tmp/` (não dá para saber daqui; depende do vhost), `/tmp/chamados.csv` é baixável sem login.
- **Impacto.** Dados pessoais retidos além do necessário e possivelmente expostos sem autenticação, ou copiados em backups.
- **O que fiz.** O painel deixou de gravar arquivo (F9). `EXPORT_DIR` passa a aceitar override por variável de ambiente, para quem a usa apontá-la para fora do docroot. Recomenda-se apagar o `chamados.csv` residual dos servidores e confirmar que `tmp/` não é servido.

### F14 — `mediaResposta()` divide por zero e derruba a tela inicial

**Onde:** `code/lib.php:116` · **Categoria:** bug · **Severidade:** media · **Confiança:** 97 · **Corrigido:** sim

- **Mecanismo.** `$soma / $qtd` com `$qtd = 0` quando nenhum chamado tem `minutos_resposta` (tabela vazia ou todos aguardando a 1a resposta). No PHP 8 isso lança `DivisionByZeroError`; `index.php:74` chama a função em toda listagem. Reproduzido: com todos os `minutos_resposta` NULL a listagem respondeu HTTP 500 (log: `DivisionByZeroError: Division by zero`).
- **Impacto.** Tela principal indisponível para todos até que algum chamado receba resposta (instalação nova, expurgo de dados ou, se alguém escopar a média por cliente, qualquer cliente sem chamado respondido).
- **O que fiz.** Sem chamado respondido devolve `0.0` (a assinatura `: float` não admite null) e o painel mostra '0 min'. Com dados, o valor é bit a bit o mesmo (25.666666666666668 no seed).

### F15 — N+1: uma consulta por chamado só para buscar o nome do técnico

**Onde:** `code/lib.php:64–91` · **Categoria:** performance · **Severidade:** media · **Confiança:** 98 · **Corrigido:** sim

- **Mecanismo.** `listarChamados` (L88-91) e `exportarCsv` (L136-145) chamam `tecnicoNome()` a cada linha, e ela executa `SELECT nome FROM usuarios WHERE id = ...` (L69): N chamados geram 1+N consultas. Medido com 20.005 chamados: a listagem fez 20.005 consultas em 4,07 s e o CSV, 20.005 consultas em 3,87 s.
- **Impacto.** Latência e carga no banco crescem linearmente com o histórico; a tela e a rotina noturna degradam com o volume, e a janela da corrida de F9 aumenta.
- **O que fiz.** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome,'-') AS tecnico_nome`: 1 consulta. Mesma carga: listagem em 0,14 s (cerca de 29 vezes mais rápida) e CSV em 0,08 s (cerca de 48 vezes), com resultado idêntico (mesmas chaves, ordem e valores; '-' quando não há técnico). `tecnicoNome()` foi mantida só por compatibilidade.

### F16 — Tratamento de falha de conexão nunca executa no PHP 8.1+

**Onde:** `code/index.php:9–12` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 70 · **Corrigido:** sim

- **Mecanismo.** Desde o PHP 8.1 `new mysqli()` lança `mysqli_sql_exception` em vez de preencher `connect_errno`, então o `if`/`die` das L10-12 é código morto (o mesmo vale para o `$res === false` de `exportarCsv`, L133-135). Reproduzido com senha errada: HTTP 500 e 'Uncaught mysqli_sql_exception: Access denied...' no log.
- **Impacto.** Erro 500 em vez da mensagem prevista; o texto da exceção (usuário e host do banco) pode chegar ao navegador se `display_errors` estiver ligado. A senha em si não aparece, pois no PHP 8.2+ o parâmetro é marcado como sensível.
- **O que fiz.** `try`/`catch` de `mysqli_sql_exception`, `http_response_code(503)` e a mesma mensagem 'Falha ao conectar ao banco.'. Verificado: HTTP 503 com a mensagem genérica.

### F17 — Parâmetros em formato de array derrubam a requisição (TypeError, HTTP 500)

**Onde:** `code/index.php:22` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 92 · **Corrigido:** sim

- **Mecanismo.** `$_POST['login']`, `$_POST['senha']` (L22) e `$_GET['busca']` (L72-73) podem ser arrays (`login[]=x`) e são repassados a funções tipadas `string`, que no PHP 8 lançam `TypeError`. Reproduzido: `login[]=ana`, `senha[]=x` e `busca[]=x` resultaram em HTTP 500, o do login sem precisar de autenticação.
- **Impacto.** Qualquer visitante anônimo produz erros 500 e ruído de log; sem corrupção de dados.
- **O que fiz.** `is_string()` antes de usar: valor inválido é tratado como ausente (o login mostra o formulário, a busca fica vazia, `?ver` que não seja string vira 0). Verificado: HTTP 200 nos três casos.

### F18 — Cookie de sessão sem HttpOnly e sem SameSite

**Onde:** `code/index.php:15` · **Categoria:** seguranca · **Severidade:** baixa · **Confiança:** 75 · **Corrigido:** sim

- **Mecanismo.** `session_start()` (L15) usa os padrões do php.ini do ambiente (`session.cookie_httponly` Off, SameSite vazio): a resposta de login traz apenas `Set-Cookie: PHPSESSID=...; path=/`.
- **Impacto.** Um XSS (F6) lê o cookie por `document.cookie` e o cookie acompanha requisições cross-site; agrava F6 e F10.
- **O que fiz.** `ini_set` de `session.cookie_httponly=1`, `cookie_samesite=Lax`, `use_strict_mode=1` e `cookie_secure=1` quando a requisição é HTTPS. Verificado: `Set-Cookie: ...; path=/; HttpOnly; SameSite=Lax`. O ramo HTTPS (flag Secure) não foi exercitado nos testes.

### F19 — Injeção de fórmula no CSV: título controlado pelo cliente vai cru para a planilha

**Onde:** `code/lib.php:138–144` · **Categoria:** seguranca · **Severidade:** baixa · **Confiança:** 55 · **Corrigido:** sim

- **Mecanismo.** O título do chamado (texto livre do cliente) vai para a coluna Titulo (L140) sem neutralização, e células iniciadas por `=`, `+`, `-` ou `@` (ou TAB/CR) são tratadas como fórmula pelo Excel e pelo LibreOffice. Reproduzido: um título `=HYPERLINK(...)` saiu intacto no CSV.
- **Impacto.** Um cliente mal-intencionado atinge quem abre a planilha (técnico, financeiro): exfiltração de dados por HYPERLINK e, em versões antigas, DDE.
- **O que fiz.** `neutralizarCelulaCsv()` prefixa um apóstrofo nessas células, só na coluna Titulo (o '-' que representa 'sem técnico' e os demais campos saem idênticos). Efeito deliberado: títulos que começam por esses caracteres (por exemplo '+55 11...') passam a trazer o apóstrofo; ver Decisões.

### F20 — `mediaResposta()` traz todas as linhas para somar em PHP

**Onde:** `code/lib.php:109–115` · **Categoria:** performance · **Severidade:** baixa · **Confiança:** 80 · **Corrigido:** sim

- **Mecanismo.** A consulta devolve uma linha por chamado respondido só para o laço das L112-115 somar e contar, a cada visualização da listagem.
- **Impacto.** Tráfego proporcional ao número de chamados em cada page view por um indicador que o banco entrega numa linha; medido 13 ms contra 6 ms com 20 mil linhas, pouco hoje, linear com o volume.
- **O que fiz.** `COUNT(minutos_resposta)` e `COALESCE(SUM(minutos_resposta),0)` no SQL, com a divisão mantida em PHP para o valor sair bit a bit idêntico (o `AVG()` do banco arredondaria em 4 casas): 25.666666666666668 no seed e 44.96138224082984 na base grande, iguais aos do original.

### F21 — Listagem sem paginação e com `SELECT *` (traz a coluna TEXT `descricao`)

**Onde:** `code/lib.php:80–93` · **Categoria:** performance · **Severidade:** baixa · **Confiança:** 55 · **Corrigido:** não (apenas reportado)

- **Mecanismo.** `listarChamados()` devolve a tabela inteira, com todas as colunas, inclusive `descricao` (TEXT) que a tela não exibe, sem LIMIT, e `index.php:88-96` renderiza tudo numa única página.
- **Impacto.** Memória, tempo e tamanho do HTML crescem com o histórico (20 mil chamados = 20 mil linhas); hoje é pequeno, vira problema com a escala.
- **O que fiz.** Não alterado: o retorno completo (todas as colunas, sem limite) é parte do contrato que consumidores externos usam, e paginar mudaria o comportamento da rota. Só a consulta do CSV, que não precisa de `descricao`, passou a selecionar as 5 colunas usadas. Recomendação: paginação por parâmetro novo e opcional, em release combinado com os consumidores.

### F22 — `rotuloPrioridade()` com if/else aninhado em quatro níveis e números mágicos

**Onde:** `code/lib.php:40–59` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 78 · **Corrigido:** sim

- **Mecanismo.** Cinco resultados possíveis expressos em ifs aninhados em quatro níveis, com 3, 4 e 30 espalhados: difícil de ler e de estender (uma nova prioridade ou outro SLA mexe em vários níveis).
- **Impacto.** Manutenibilidade e risco de regressão; sem efeito funcional hoje.
- **O que fiz.** Guard clauses: minutos nulo devolve 'Aguardando 1a resposta'; prioridade abaixo de 3, 'Normal'; minutos até 30, 'Alto - dentro do SLA'; senão crítica ou atrasada. Equivalência conferida exaustivamente: prioridade de -1 a 6 por minutos em {null,-5,0,1,29,30,31,60}, 64 combinações idênticas ao original.

### F23 — `fputcsv()` sem o parâmetro `$escape`: depreciado no PHP 8.4

**Onde:** `code/lib.php:130–138` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 45 · **Corrigido:** sim

- **Mecanismo.** No PHP 8.4, depender do valor padrão de `$escape` emite `E_DEPRECATED`. Reproduzido (CLI com `error_reporting=-1`): um aviso por chamada de `fputcsv` (cabeçalho e cada linha). Com `display_errors` ligado e `E_DEPRECATED` ativo o texto sai antes dos `header()` (L148-149), corrompendo o download e impedindo os cabeçalhos; com o php.ini do ambiente (`display_errors` Off) fica só no log.
- **Impacto.** Depende da configuração: baixo com `display_errors` desligado, quebra o download quando ligado; e o valor padrão muda em versões futuras.
- **O que fiz.** Argumento `$escape` explícito (a barra invertida, que é o valor padrão histórico), então o CSV continua byte a byte igual e o aviso some.

### F24 — `formatarStatus()` rotula qualquer valor desconhecido como 'Resolvido'

**Onde:** `code/lib.php:26–35` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 35 · **Corrigido:** não (apenas reportado)

- **Mecanismo.** O `else` final captura 0, 4, -1 etc.; `status` é TINYINT sem CHECK (`schema.sql`) e o código não valida o domínio 1 a 3.
- **Impacto.** Um status novo ou inválido (por exemplo 4 = reaberto) apareceria como 'Resolvido' para técnicos e no relatório gerencial, escondendo trabalho pendente. Hoje é hipotético: o seed só usa 1 a 3.
- **O que fiz.** Não alterado: o manifesto fixa só 1, 2 e 3 e o relatório gerencial faz correspondência por texto; um rótulo novo para o `else` mudaria uma saída que consumidores externos observam. Decisão para o dono do relatório (ideal: CHECK de 1 a 3 no banco e um rótulo 'Desconhecido').


## 3. Decisões

O que deliberadamente **não** mudei (ou mudei com ressalva), e o motivo.

- **`listarChamados()`, `verChamado()` e `exportarCsv()` continuam com visão irrestrita.** São o contrato do manifesto e são usadas por rotinas internas (exportação noturna, relatório gerencial) que não têm usuário logado; filtrar dentro delas quebraria esses consumidores. A camada web usa as variantes `*Visiveis`/`*Visivel`. Risco residual: quem chamar as originais a partir de código web volta a vazar; isso está documentado no cabeçalho de `lib.php`.
- **Curingas `%` e `_` na busca por título.** O comportamento atual (`busca=%` lista tudo) pode ser usado por links e favoritos externos; a correção de segurança é parametrizar, não mudar a semântica. Escapar os curingas é possível depois, como decisão de produto.
- **HTTP 200 com 'Chamado nao encontrado.' tanto para chamado inexistente quanto para o de outro cliente.** Preserva o comportamento observável de hoje e evita diferença entre 'não existe' e 'não é seu', que permitiria enumerar ids. Migrar para 404 é recomendável, mas muda o que monitoramento e clientes veem atualmente.
- **`mediaResposta()` continua global (inclui chamados de outros clientes) e devolve 0.0 sem dados.** É um indicador agregado de SLA do painel e não expõe chamado algum; escopá-la por cliente mudaria a semântica do manifesto. Se o produto considerar o indicador sensível para clientes, ocultá-lo é decisão de produto. 0.0 é o único valor compatível com o tipo `float` da assinatura.
- **Rótulo de chamado crítico ainda sem 1ª resposta em `rotuloPrioridade()`.** A função devolve 'Aguardando 1a resposta' para qualquer prioridade enquanto não há resposta, inclusive o crítico #104 do seed. Pode ser uma lacuna de regra (o pior caso aparece com o rótulo menos urgente), mas é saída observada por consumidores e não dá para afirmar que é erro; mantido para validação com o produto. A refatoração (F22) preserva exatamente esse comportamento.
- **Itens reportados e não corrigidos: F11 (limite de tentativas de login), F21 (paginação) e F24 (`formatarStatus`).** Cada um tem o motivo no próprio achado: exigem estado novo, mudam o contrato de retorno ou alteram saída observada por consumidores externos.
- **Proteção contra CSRF no login, logout, cabeçalhos de segurança (CSP, X-Frame-Options) e revalidação do papel a cada requisição.** Exigem estado novo (tokens, tabelas) ou rotas e UX novas, e não há ação de escrita além do login, então o ganho de CSRF e clickjacking é pequeno aqui. Não são correção de defeito e aumentariam a superfície alterada num legado com consumidores externos. O papel segue vindo da sessão, como antes; papel desconhecido é tratado como cliente.
- **Trava de sessão durante exports longos (`session_write_close`).** Era relevante com o export de cerca de 4 s do original; com o CSV direto em 0,08 s deixa de importar. Não alterei o ciclo da sessão sem necessidade.
- **Estrutura do `index.php` (script único com login, rotas, consulta e HTML por concatenação; sem `<html>` nem `<body>`).** Reescrever ou separar camadas está vedado ('Evolua-o'), e a estrutura HTML da listagem é contrato. Só foram tocadas as linhas necessárias; o HTML do técnico é idêntico byte a byte.
- **`tecnicoNome()` mantida (sem uso interno agora) e a concatenação de `$tecnicoId` no SQL dela.** O parâmetro é tipado `?int`, então o valor concatenado é sempre um inteiro: não é SQL injection. A função é pública no arquivo e outro script pode chamá-la.
- **`seed.sql` e os usuários de teste.** Inalterado (MD5 legado, senhas fracas): são dados de teste e demonstram o caminho de migração. Em produção, senhas fracas se tratam com política de senha.
- **DDL além da coluna `senha`: CHECK de status e prioridade, ENGINE/CHARSET explícitos, validação de papel técnico em `chamados.tecnico_id`.** Mudanças de DDL em produção estão fora do escopo; só alarguei a coluna `senha`, necessária para F8.
- **A migração MD5 para `password_hash()` é opt-in e gradual.** Não executei DDL nem rotina em massa; usuários que nunca mais entrarem seguem com MD5 até um reset. Antes do ALTER, confirmar que nenhum outro sistema valida MD5 direto em `usuarios.senha` (eles deixariam de autenticar os usuários migrados) e planejar o rollback: depois que usuários migram, o código antigo não autentica mais esses usuários. Limites do bcrypt: só 72 bytes contam, e senha com byte NUL não migra.
- **Prefixo `'` nos títulos do CSV que começam por `=`, `+`, `-`, `@`, TAB ou CR (F19).** É mudança de dado de saída tomada por segurança; pode ser revertida numa só função (`neutralizarCelulaCsv`) se algum consumidor depender do título cru.
- **`EXPORT_DIR` e o `chamados.csv` antigo.** A constante segue definida porque outro script pode usá-la; apagar o arquivo residual e garantir que o diretório não é servido é ação de operação, não de código.
- **Tipos dos valores devolvidos por `listarChamados()` e `verChamado()`.** Consultas preparadas devolveriam inteiros nativos; converti de volta para string (o formato do modo texto anterior) para não alterar o formato de retorno que os consumidores recebem. Quem usasse `MYSQLI_OPT_INT_AND_FLOAT_NATIVE` passa a receber strings.
- **Rotação da senha do banco e da chave SMTP que estavam no código.** Não é possível fazê-la a partir do código; fica com a operação (ver a seção Implantação do relatório).
- **Pontos que parecem problema e não são (verificados, por isso não reportados): consulta preparada de `autenticar()`, `htmlspecialchars` já existentes, concatenação de inteiros tipados em `verChamado()`/`tecnicoNome()`, `$c['id']` sem escape, `header('Location: index.php')`.** `autenticar()` já usava consulta preparada (o defeito ali é o MD5); os `htmlspecialchars` de título, descrição e técnico estão corretos; `verChamado()` recebe `int` tipado, e `$c['id']` é inteiro do banco; o redirecionamento é relativo e fixo, sem open redirect.

### 3.1 Pontos que parecem problema e não são

Verificados e, por isso, **não** reportados como falha:

- `autenticar()` já usava consulta preparada: não há injeção por login ou senha; o defeito ali é o MD5 (F8).
- `verChamado()` e `tecnicoNome()` concatenam parâmetros tipados `int`/`?int` (e o `index.php` ainda faz `(int)`): não é SQL injection. O defeito de `verChamado()` é a falta de autorização (F2).
- Os `htmlspecialchars` de título, descrição e nome do técnico estão corretos; o `$c['id']` impresso sem escape é inteiro vindo do banco.
- `header('Location: index.php')` é relativo e fixo: não há open redirect. `?export[]=` e `?ver[]=` não geram erro (comparação estrita e `(int)` de array).
- O caminho de `EXPORT_DIR` é constante: não há path traversal (o problema dele é o arquivo compartilhado, F9 e F13).
- `date_default_timezone_set` e o índice implícito da FK `fk_tecnico` são inofensivos. Na exceção de conexão, a senha não aparece no PHP 8.2+ (parâmetro sensível); só usuário e host (F16).

## 4. Compatibilidade e verificação

### 4.1 Contrato do manifesto

| Item do manifesto | Situação | Como conferi |
| --- | --- | --- |
| `autenticar(mysqli,string,string): ?array` → `['id','nome','papel']` ou `null` | preservada (mesmos tipos; `id` inteiro como antes) | Reflection + chamadas diretas: 15 combinações de login/senha, incluindo `ANA` (login sem diferenciar maiúsculas) e tentativas de injeção |
| `formatarStatus` (1/2/3 → Aberto / Em atendimento / Resolvido) | inalterada | −3 a 7 idênticos |
| `rotuloPrioridade(int,?int): string` | refatorada, saída idêntica | 64 combinações idênticas |
| `listarChamados(mysqli,string=''): array` com `tecnico_nome` | preservada: mesmas chaves, ordem, valores e tipos (strings) | 12 termos de busca, comparação com `var_export` |
| `verChamado(mysqli,int): ?array` | preservada | ids 101–105, 999, 0 e −1 |
| `mediaResposta(mysqli): float` | preservada (`0.0` quando não há dados, em vez de erro) | 25.666666666666668 igual; 44.96138224082984 igual na base grande |
| `exportarCsv(mysqli): void` (escreve na saída) | preservada; sem arquivo intermediário | 399 bytes do seed, `cmp` idêntico |
| Rotas `busca`, `ver`, `export=csv` | preservadas | fluxos HTTP com login real |
| CSV: cabeçalho exato, uma linha por chamado por `id`, rótulos de status | idêntico | `cmp` e `Content-Type`/`Content-Disposition` iguais |
| HTML: `id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico, ID com link `index.php?ver=<id>` | idêntico para técnico (22 de 22 respostas) | `cmp` byte a byte |
| Visibilidade: cliente só vê o que abriu; técnico vê tudo | **agora implementada** | os 4 usuários do seed |

**Superfície adicionada** (só aditiva): `listarChamadosVisiveis`, `verChamadoVisivel`, `exportarCsvVisivel` e auxiliares internas (`escopoDoUsuario`, `filtroDeChamados`, `consultaPreparada`, `linhaComoTexto`, `consultarLista`, `consultarChamado`, `emitirCsv`, `neutralizarCelulaCsv`, `senhaEhMd5Legado`, `atualizarHashDeSenha`).

### 4.2 Como verifiquei

Tudo foi executado, não só lido. Ambiente: **MariaDB 11.8.6 privado** (porta 33306, modo SQL estrito; o MariaDB que já escutava na 3306 da máquina **não foi tocado**), PHP 8.4.26 com o servidor embutido e `curl` com sessão real, carregando `schema.sql` e `seed.sql`. Antes de editar, guardei uma cópia do original e medi o comportamento dele.

- **Original (linha de base):** reproduzi F1 (hashes saindo pela listagem), F2, F3, F4, F6, F8 (hashes idênticos para senhas iguais), F10, F13, F14, F16, F17, F18, F19 e F23, e medi F9 e F15.
- **HTTP, 4 usuários × (listagem, 6 detalhes, CSV, 3 buscas):** técnicos idênticos ao original em 22 de 22 respostas, nas duas rodadas (hashes em MD5 e já migrados para bcrypt) e, com a coluna `senha` ainda `CHAR(32)`, também para a técnica `carla`. Clientes só veem o próprio: `ana` 101/102/105, `bruno` 103/104; o detalhe alheio vira 'Chamado nao encontrado.'.
- **Chamada direta de função (116 blocos):** assinaturas por Reflection, `formatarStatus`, `rotuloPrioridade`, `listarChamados` (12 buscas), `verChamado`, `mediaResposta`, `tecnicoNome`, `autenticar` (15 casos) e `exportarCsv`. Quatro divergências, todas explicadas: (1) `listarChamados("' OR '1'='1")` devolvia tudo no original e agora devolve nada (a injeção neutralizada); (2) uma linha de `error_log` do usuário com NUL misturada na saída; (3) a senha de 100 bytes depois de migrada (limite de 72 bytes do bcrypt); (4) ruído de avisos no buffer do CSV, motivo pelo qual comparei o CSV puro à parte, onde é idêntico.
- **CSV com títulos especiais** (fórmulas, vírgula, aspas, quebra de linha, barra invertida, acentos, TAB): só as células iniciadas por `= + - @ TAB` diferem (ganham o apóstrofo); o resto é idêntico.
- **Ataques repetidos no código novo:** o `UNION` devolve 0 linhas; o `'` isolado dá HTTP 200; XSS escapado; IDOR bloqueado; fixação de sessão bloqueada; `busca[]`, `login[]` e `senha[]` dão HTTP 200; `Set-Cookie` com `HttpOnly; SameSite=Lax`. Com `display_errors=1` e `E_ALL`, nenhum aviso PHP em nenhuma resposta.
- **Bordas:** banco sem chamado respondido (original: HTTP 500; novo: 200 e '0 min'); senha de banco errada (original: 500; novo: 503 com mensagem genérica); modo SQL **não estrito** com coluna `CHAR(32)` (login funciona e o hash não é truncado; mostrei que um `UPDATE` sem a verificação de largura truncaria para 32 caracteres); MD5 em maiúsculas; senha com byte NUL.
- **Desempenho (20.005 chamados):** listagem 4,07 s e 20.005 consultas → 0,14 s e 1 consulta; CSV 3,87 s → 0,08 s; mesmo tamanho de CSV (1.585.176 bytes).
- **Concorrência (8 workers, 8 sessões, 5 exportações cada, início escalonado):** original 25 íntegras e **15 corrompidas** de 40; novo 40 de 40 íntegras. Com início simultâneo o original não corrompe (todos gravam os mesmos bytes), por isso a corrida exige sobreposição escalonada.
- `php -l` nos 3 arquivos PHP; `schema.sql` e `seed.sql` carregam do zero; nenhum segredo antigo resta em `code/`. Uma segunda leitura independente do código original (auditor cego, sem acesso às minhas conclusões) chegou aos mesmos problemas centrais; dela incorporei o código morto do `$res === false`, a precisão sobre o que a exceção de conexão vaza e a trava de sessão.

**Limites desta verificação:** testei em MariaDB (o schema prevê MySQL 8) e só em PHP 8.4.26; o código usa apenas recursos do PHP 7.1+ mas não foi executado em PHP antigo; não passei por PHP-FPM/Apache; o ramo HTTPS do cookie (`Secure`) não foi exercitado; o status HTTP foi conferido nos fluxos citados, não em todos.

## 5. Implantação e riscos residuais

1. **Definir `DB_PASS` no ambiente do processo PHP** (PHP-FPM `env[DB_PASS]`, Apache `SetEnv`, systemd `Environment=`) **antes** de publicar. Sem ela o painel responde HTTP 503 com 'Falha ao conectar ao banco.' e registra um `error_log` explícito. `DB_HOST`, `DB_NAME` e `DB_USER` só se diferirem dos padrões; `SMTP_API_KEY` e `EXPORT_DIR` se algum script as usa.
2. **Rotacionar a senha do banco e a chave SMTP**: estiveram em texto no código e no histórico dele.
3. **Habilitar a migração de senhas é opcional e deve vir depois** de o código novo estabilizar: `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Antes, confirmar que nenhum outro sistema valida MD5 direto em `usuarios.senha`. **Ponto sem volta:** depois que usuários migram, o código antigo não os autentica; por isso publique primeiro o código (sem o ALTER nada é regravado) e aplique o ALTER só quando o rollback deixar de ser provável.
4. Rotinas que liam `EXPORT_DIR/chamados.csv` como efeito colateral de `exportarCsv()` precisam capturar a saída (`ob_start`): o contrato do manifesto é 'escreve o CSV na saída'. Apagar o `chamados.csv` residual em `/var/www/painel/tmp` e conferir que `tmp/` não é servido.
5. Recomendado no perímetro (fora do código): limite de tentativas de login (F11) e TLS direto ou terminado em proxy que repasse `HTTPS` (o flag `Secure` do cookie só é ligado quando o PHP vê a requisição como HTTPS).
