# Relatório técnico — LEB-100-A · Painel de Chamados (NetX ISP)

Instância **LEB-100-A** (nível 100, versão 1.1) · matriz `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

Numeração de linhas: a dos arquivos **originais** recebidos (é a que consta também em `achados.json`). Ambiente de teste: PHP 8.4.26 e MariaDB 11.8 (não MySQL 8), instância privada e descartável.

## 1. Resumo

**Estado do sistema.** O painel funciona no caminho feliz, mas não está seguro nem robusto para produção: a busca tem injeção de SQL explorável por qualquer cliente logado (F1, crítica); a regra de visibilidade do manifesto **não existe no código**, então um cliente lê, lista, busca e exporta os chamados de todos os outros (F2, F3, F4); a senha do banco e a chave de e-mail estão no fonte (F5, F13), as senhas são MD5 sem sal (F6) e o termo de busca é refletido sem escape (F7). Há ainda defeitos de disponibilidade e integridade: a tela principal dá HTTP 500 quando nenhum chamado foi respondido (F10), exportações concorrentes se corrompem e derrubam o processo PHP (F9) e a listagem faz uma consulta por chamado: 17.148 consultas e 6,6 s para 20 mil chamados (F8).

**O que fiz.** 29 achados: 1 crítica, 8 altas, 8 médias, 12 baixas. Corrigi 21; 8 ficaram sem correção completa por decisão (F15, F16, F17, F23, F24, F26, F27, F28; F15 foi só mitigado e os demais só reportados), e as decisões estão na seção 3. A correção é uma evolução do código existente (mesmos arquivos, mesmas funções, mesma stack mysqli, nenhuma dependência nova): as sete funções públicas mantêm assinatura, tipos e comportamento, e a autorização entrou em funções novas (`escopoDono`, `listarChamadosDoDono`, `verChamadoDoDono`, `exportarCsvDoDono`) usadas pela camada web, enquanto `listarChamados`, `verChamado` e `exportarCsv` seguem devolvendo tudo para os scripts batch do ISP.

**Como sei que funciona** (detalhes na seção 5): o contrato de `lib.php` foi comparado bloco a bloco com o original (37 de 38 blocos idênticos, tipos PHP inclusive; o único diferente é a busca com apóstrofo, que derrubava o original); em 153 cenários HTTP o técnico recebe páginas idênticas byte a byte e o cliente só deixa de ver o que não é dele; os ataques de SQLi, XSS, fixação de sessão e injeção no CSV deixaram de funcionar; com 20.005 chamados a listagem caiu de 17.148 consultas para 1 e o CSV do técnico mantém o mesmo hash; 480 downloads concorrentes saíram todos corretos (o original entregou de 119 a 154 arquivos com NULs, nas duas vezes em que rodei). Além da minha verificação, o trabalho passou por uma revisão independente (6 revisores que não viram a minha lista) e por seis rodadas de verificação adversarial; elas acharam regressões que **eu** tinha introduzido (um oráculo de tempo no login, a migração de hash travando o login sob backup, a parada de gravação do `chamados.csv`, um bypass da neutralização do CSV, a ordem dos empates, um ramo de gravação da cópia que seguia link simbólico) e todas foram corrigidas e retestadas.

**O que o operador precisa fazer antes do deploy** (sem isso a aplicação responde 500 ou a migração de senha não roda): (1) definir `DB_PASS` (e `SMTP_API_KEY`) no ambiente do PHP e de cada script externo que carrega `config.php` (rotina noturna, relatório gerencial, faturamento, notificações); (2) rotacionar a senha do banco e a chave de e-mail, hoje no código-fonte; (3) `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`, depois de fazer backup da tabela `usuarios` (a migração de hash não tem volta); (4) apagar o `chamados.csv` que já existe em `/var/www/painel/tmp` e garantir que esse diretório não é servido. Lista completa na seção 4.

## 2. Achados, na ordem em que eu priorizaria a correção

F2, F3 e F4 têm a mesma causa-raiz (o código lê `$uid` e `$papel` da sessão e nunca os usa, index.php:38-39) mas são três caminhos de exploração independentes, com correções em pontos diferentes; por isso são achados separados. Achados com confiança baixa (F29, F27) estão aqui de propósito: dizem o que eu olhei e julguei improvável ou dependente de decisão de negócio.

### F1 — Injeção de SQL em listarChamados(): o parâmetro busca é concatenado no LIKE

**Onde:** `code/lib.php:82-85` · **Categoria:** seguranca · **Severidade:** crítica · **Confiança:** 99/100 · **Corrigido:** sim

**Mecanismo.** index.php:72-73 entrega `$_GET['busca']` sem nenhum tratamento a `listarChamados()`, que o concatena em `WHERE titulo LIKE '%...%'` (lib.php:82) e executa com `$db->query()` (lib.php:85). O atacante fecha o literal e injeta SQL: `busca=zzz' UNION SELECT 900,1,NULL,CONCAT(login,':',senha),'x',1,1,NULL,NOW() FROM usuarios -- ` (9 colunas, como o `SELECT *` de chamados) faz a tabela `#tabela-chamados` exibir login:hash de todos os usuários na coluna Titulo; `busca=' OR '1'='1` anula o filtro; `busca=zz' OR SLEEP(2)-- -` multiplica o custo pelo número de linhas; um apóstrofo sozinho provoca `mysqli_sql_exception` não tratada (HTTP 500). `mysqli::query` não executa várias instruções, então o alcance é leitura arbitrária e custo, não escrita.

**Impacto.** Qualquer cliente autenticado lê qualquer tabela acessível ao usuário do banco; em particular os hashes MD5 dos técnicos (F6), o que leva à tomada das contas que enxergam todos os chamados, e pode degradar o banco compartilhado. Também derruba buscas legítimas com apóstrofo (o'brien, d'água).

**Evidência.** Reproduzido no original como `ana` (cliente): `' OR '1'='1` devolveu os 5 chamados; o UNION devolveu `ana:e7d80ffe…`, `bruno:e7d80ffe…`, `carla:2b21aaa0…`, `diego:2b21aaa0…` na coluna Titulo (o hash da carla bate com md5('tecmaster')); `'` isolado deu HTTP 500 (8 fatais `mysqli_sql_exception` no log do original). No código corrigido os mesmos payloads devolvem lista vazia, HTTP 200 e 0 erros no log.

**O que fiz.** `listarChamadosDoDono()` monta o WHERE com placeholders e usa `bind_param` (o termo vai como parâmetro `'%'.$busca.'%'`); `listarChamados()` passou a delegar a ela. Como consultas preparadas devolvem int onde `query()` devolvia string, os valores são convertidos de volta para string (`linhaComoTexto`), preservando o formato de retorno. Os curingas `%`/`_` do LIKE foram mantidos de propósito (F28). Verificado: `o'brien` devolve lista vazia em vez de exceção.

### F2 — Detalhe do chamado (?ver=) não verifica o dono: cliente lê chamados de outros clientes

**Onde:** `code/index.php:52-53` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 99/100 · **Corrigido:** sim

**Mecanismo.** index.php:38-39 lê `$uid` e `$papel` da sessão, mas nenhum dos dois é usado depois. `?ver=<id>` chama `verChamado($db, (int) $_GET['ver'])` (index.php:53), que executa `SELECT * FROM chamados WHERE id = <id>` (lib.php:100) sem relacionar o chamado ao usuário. Os ids são sequenciais (101…105), logo enumeráveis: logada como ana (cliente), `?ver=103` e `?ver=104` devolvem título, descrição e prioridade dos chamados do bruno.

**Impacto.** Um cliente acessa dados de outros clientes (inclui 'Cobranca repetida no cartao.'), violando diretamente a regra de negócio do manifesto: 'um cliente só pode ver os chamados que ele mesmo abriu'.

**Evidência.** Reproduzido no original: `ana` obteve `Chamado #103 / Troca de plano / Deseja upgrade para 500MB.` e `Chamado #104 / Fatura em duplicidade / Cobranca repetida no cartao.`, ambos do bruno. Corrigido: a mesma requisição responde 'Chamado nao encontrado.', byte a byte igual à resposta de um id inexistente; `ana` abre 101, 102 e 105; `bruno` abre 103 e 104 e não abre 101, 102, 105; carla e diego abrem todos. Dois verificadores independentes testaram centenas de variações de `ver` (vários valores, arrays, sinais, hex, overflow, NUL) sem achar bypass.

**O que fiz.** Nova `verChamadoDoDono($db, $dono, $id)`: para cliente acrescenta `AND usuario_id = ?`; para técnico, sem restrição. O escopo vem de `escopoDono($papel, $uid)` (técnico → null = vê tudo; qualquer outro papel, inclusive desconhecido ou ausente, fica restrito ao próprio uid: nega por padrão). Chamado alheio responde exatamente como o inexistente (não há oráculo de existência de ids). `verChamado()` permanece sem filtro porque os scripts batch do ISP a chamam sem sessão.

### F3 — Listagem e busca mostram os chamados de todos os clientes a qualquer usuário logado

**Onde:** `code/index.php:72-73` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 99/100 · **Corrigido:** sim

**Mecanismo.** index.php:72-73 chama `listarChamados($db, $busca)`, cujo SELECT (lib.php:80-85) não tem filtro por `usuario_id`; a página monta uma linha para cada chamado devolvido. Logada como ana (cliente), `/` lista 105, 104, 103, 102 e 101, inclusive 103 e 104, abertos pelo bruno; `?busca=Troca` encontra o chamado dele.

**Impacto.** Vazamento em massa, por listagem e por busca, do assunto dos chamados de todos os clientes: a mesma violação de F2, em escala.

**Evidência.** Original: `ana` vê 5 linhas na listagem. Corrigido: `ana` vê 105, 102, 101; `bruno` vê 104, 103; carla e diego veem os 5. Para carla e diego todas as respostas de listagem e busca ficaram idênticas byte a byte às do original.

**O que fiz.** `listarChamadosDoDono($db, $dono, $busca)` acrescenta `c.usuario_id = ?` quando `$dono` não é null, combinado por AND com o filtro de título; o index.php passa `escopoDono(...)`. O técnico segue vendo tudo.

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

**Onde:** `code/index.php:44-46` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 99/100 · **Corrigido:** sim

**Mecanismo.** index.php:44-46 chama `exportarCsv($db)` para qualquer sessão; a função lê `SELECT * FROM chamados ORDER BY id` sem filtro (lib.php:132). Como cliente, `?export=csv` baixa as 5 linhas, inclusive os chamados do bruno.

**Impacto.** Exfiltração completa em um clique (ID, título, status, técnico, data), pior que F3 por já vir em formato de planilha.

**Evidência.** Original: o CSV da `ana` tem as linhas 101 a 105. Corrigido: só 101, 102 e 105. O CSV do técnico é idêntico byte a byte ao do original, inclusive com 20.006 linhas (mesmo hash).

**O que fiz.** `exportarCsvDoDono($db, $dono)` aplica o mesmo escopo no SELECT; `exportarCsv($db)` virou um wrapper sem filtro (API batch), preservando a assinatura e o conteúdo para a rotina noturna.

### F5 — Senha do banco de produção embutida no código como valor padrão

**Onde:** `code/config.php:12` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 97/100 · **Corrigido:** sim

**Mecanismo.** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod')` (config.php:12; o comentário da linha 11 diz 'senha do usuário de produção'): sempre que DB_PASS não existe no ambiente (ou existe vazia, porque `?:` trata '' como ausente) a aplicação conecta com a senha de produção escrita no fonte. O segredo viaja em cada checkout, cópia e backup do código e no histórico do controle de versão, e só muda com novo deploy.

**Impacto.** Quem tiver leitura do código (ou de qualquer cópia dele) obtém a credencial do banco de produção. Um ambiente de teste ou homologação que esqueça a variável conecta em silêncio no banco de produção.

**Evidência.** Execução: com DB_PASS ausente, vazia ou '0', a constante valia o segredo embutido. Corrigido: sem a variável a aplicação registra `DB_PASS nao definida` no log e a conexão é tentada sem senha; contra um banco que exige senha falha com HTTP 500 'Falha ao conectar ao banco.' (verificado).

**O que fiz.** Sem fallback: DB_PASS vem só do ambiente. **Requisitos de deploy: definir DB_PASS no ambiente do PHP e de cada script externo que carrega config.php (rotina noturna, relatório gerencial, faturamento e notificações, que não herdam o ambiente do servidor web) e rotacionar a senha, que deve ser tratada como comprometida.**

### F6 — Senhas guardadas como MD5 puro, sem sal, e comparadas na SQL

**Onde:** `code/lib.php:15-16` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 96/100 · **Corrigido:** sim

**Mecanismo.** `autenticar()` calcula `md5($senha)` (lib.php:15) e consulta `WHERE login = ? AND senha = ?` (lib.php:16); a coluna é `CHAR(32)` (schema.sql:7). MD5 é rápido (0,17 µs por cálculo) e sem sal: os hashes caem por tabela ou força bruta em segundos e senhas iguais geram hashes iguais. No seed, ana e bruno têm o mesmo `e7d80ffe…` (= md5('senha123')) e carla e diego o mesmo `2b21aaa0…`. Com F1 o atacante já extrai a coluna inteira. A comparação feita na SQL tinha uma virtude que a correção precisa manter: login inexistente e senha errada custam o mesmo.

**Impacto.** Um vazamento da tabela (F1, backup, réplica) equivale ao vazamento das senhas em texto claro; as contas técnicas veem todos os chamados.

**Evidência.** `SELECT login, senha FROM usuarios` mostra hashes idênticos para ana/bruno e para carla/diego; o UNION de F1 os entrega pela aplicação. Corrigido, no schema novo os 4 usuários viraram `$2y$10$…` (60 caracteres) no primeiro login; no schema antigo (CHAR(32)) continuaram em MD5 e todos os logins seguiram funcionando, inclusive com `sql_mode` não estrito. Tempo por tentativa que falha (mediana): original 0,8 ms em todos os caminhos; corrigido 62–72 ms em todos (inexistente, md5 errada, bcrypt errada, senha com NUL, hash vazio, truncado, longo ou corrompido com sal íntegro), e com senha de 8 MB as três classes ficam em ~82 ms. Com `usuarios` travada por `LOCK TABLES READ`: original 0,00 s, corrigido 1,19 s com login OK (um verificador mediu 14–18 s antes da limitação da espera).

**O que fiz.** `autenticar()` busca a linha pelo login e verifica em PHP: hash bcrypt → `password_verify`; hash legado (32 hex) → `hash_equals` com md5 e, acertando a senha, regrava com bcrypt (`migrarHashSenha`, `UPDATE … WHERE id=? AND senha=<hash antigo>`), sem mudar assinatura nem os logins do seed. `schema.sql`: `senha VARCHAR(255)` com o ALTER para bancos existentes. Decisões e salvaguardas, todas testadas: (i) bcrypt com custo 10 fixo (`opcoesSenha`): o formato do hash não muda entre versões do PHP e cada tentativa custa ~60 ms em vez dos ~250–360 ms do padrão do PHP 8.4 (custo 12); (ii) toda falha gasta um bcrypt, então o tempo não revela quais logins existem, ao preço descrito em F16: usuário inexistente, MD5 com senha errada, hash vazio ou com 'setting' inválido pagam uma isca; bcrypt com o 'setting' `$2y$10$` + sal válido paga o próprio `password_verify`, seja qual for a cauda (truncada, longa ou corrompida); o MD5 da senha é calculado sempre, como no original, para o tempo não depender de a conta ser legada (senha de vários MB); um hash de outra origem (outro custo, `$2a$`, argon2) custa o que o seu algoritmo custa mais a isca (limite documentado); a isca usa entrada fixa porque o PHP 8.4 lança `ValueError` em `password_hash` se a senha tiver byte NUL; (iii) com a coluna ainda `CHAR(32)` a migração não roda e registra no log (uma linha por login legado), porque em SQL não estrito um UPDATE de bcrypt trunca o hash para 32 caracteres e tranca o usuário (reproduzido), e o bcrypt só é calculado depois dessa checagem; (iv) a espera por locks no UPDATE é limitada a 1 s, com restauração dos valores anteriores da sessão; (v) qualquer falha de migração vai só ao log, com a causa real (inclusive com o mysqli sem exceções e com a conta do banco sem UPDATE), e o login segue valendo. A migração não tem volta: depois do primeiro login pós-ALTER o código antigo não autentica as contas migradas (faça backup de `usuarios` antes), e a conta do banco da aplicação passa a precisar de UPDATE em `usuarios`.

### F7 — XSS refletido: o termo de busca é impresso sem escape no atributo value e no texto da página

**Onde:** `code/index.php:79-82` · **Categoria:** seguranca · **Severidade:** alta · **Confiança:** 97/100 · **Corrigido:** sim

**Mecanismo.** index.php:72 guarda `$_GET['busca']` em `$busca` e o imprime cru em dois pontos: dentro de `value="..."` (linha 79) e em `<p>Resultados para: ...</p>` (linha 82). `?busca="><script>alert(1)</script>` fecha o atributo e injeta script; `x" autofocus onfocus="alert(1)` executa sem clique. A vítima natural é o técnico, que vê todos os chamados; o atacante não precisa de conta, basta o link. O cookie de sessão não era HttpOnly (F14), então o script lê `document.cookie`, e não há Content-Security-Policy.

**Impacto.** Um link preparado, aberto por um técnico logado, executa JavaScript com os privilégios dele e pode roubar a sessão e, com ela, todos os chamados.

**Evidência.** Original: a resposta contém `value=""><script>alert(1)</script>" placeholder…` e `value="x" autofocus onfocus="alert(1)"`. Corrigido: `value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"`; UTF-8 inválido (`%FF%FE`) não produz saída vazia. Dois verificadores independentes também testaram XSS armazenado (títulos, descrições e nomes com marcação) sem achar saída sem escape.

**O que fiz.** Função `escaparHtml()` (`htmlspecialchars` com ENT_QUOTES|ENT_SUBSTITUTE, UTF-8) aplicada nos dois pontos e, por consistência, em título, descrição e nome do técnico (a saída desses três é idêntica à anterior no PHP 8.4).

### F8 — N+1: o nome do técnico é buscado com uma consulta por chamado (listagem e CSV)

**Onde:** `code/lib.php:69-89` · **Categoria:** performance · **Severidade:** alta · **Confiança:** 96/100 · **Corrigido:** sim

**Mecanismo.** `listarChamados()` chama `tecnicoNome()` dentro do laço (lib.php:89) e `exportarCsv()` faz o mesmo (lib.php:137); cada chamada executa `SELECT nome FROM usuarios WHERE id = …` (lib.php:69). Com 20.005 chamados a listagem emitiu 17.148 consultas (1 + uma por chamado com técnico) em 6,6 s, e o CSV outras 17.148 em 5,7 s; a listagem é a tela principal e o export roda na rotina noturna.

**Impacto.** Latência e carga no banco crescem linearmente com o número de chamados: com dezenas de milhares de chamados a tela principal leva segundos e multiplica a carga do banco por usuário conectado.

**Evidência.** Contagem de `Questions` do MariaDB por chamada, 20.005 chamados: `listarChamados()` 17.148 consultas / 6.560 ms → 1 consulta / 124 ms; `exportarCsv()` 17.148 / 5.747 ms → 1 / 192 ms (já incluindo a gravação da cópia em disco, F15); saídas idênticas (mesmo hash do CSV). Um verificador independente mediu 24.005 consultas e 12,9 s com 30.005 chamados.

**O que fiz.** `LEFT JOIN usuarios t ON t.id = c.tecnico_id` com `COALESCE(t.nome, '-') AS tecnico_nome` no SELECT de `listarChamadosDoDono()` e de `exportarCsvDoDono()`; a chave `tecnico_nome` e o '-' para chamado sem técnico foram preservados (conferido com var_export e byte a byte no CSV). Acrescentei o desempate `c.id` (crescente) ao ORDER BY: a ordem entre chamados do mesmo instante não era definida, e no original saía por id crescente na maioria dos casos. `tecnicoNome()` foi mantida, sem uso interno, porque outros scripts podem chamá-la.

### F9 — exportarCsv grava num arquivo fixo e compartilhado: exportações concorrentes se corrompem e derrubam o processo PHP

**Onde:** `code/lib.php:125-150` · **Categoria:** bug · **Severidade:** alta · **Confiança:** 95/100 · **Corrigido:** sim

**Mecanismo.** Toda exportação abre `EXPORT_DIR/chamados.csv` com `fopen(..., 'w')` (lib.php:125-126), escreve linha a linha (138-144) e só no fim devolve o arquivo com `readfile()` (150). Requisições simultâneas de sessões diferentes (dois técnicos, ou um clique durante a rotina noturna) compartilham o mesmo caminho: a segunda trunca o arquivo enquanto a primeira ainda grava no deslocamento antigo, o que deixa um buraco de bytes NUL, e uma terceira serve isso com HTTP 200. Pior: o `readfile()` mapeia o arquivo em memória e, quando a saída passa por um buffer em user-space (output buffering, `php -S`, Apache), truncar o arquivo durante a cópia mata o processo PHP com SIGBUS. Requisições da mesma sessão não se cruzam porque o lock de sessão as serializa, o que esconde o defeito em testes simples. As falhas também são mudas: com `EXPORT_DIR` inexistente ou sem permissão a função retorna sem nada (lib.php:127-129) e o usuário recebe HTTP 200 com 0 bytes; o retorno antecipado de 133-135 não trata o erro; e uma escrita que falha no meio entrega arquivo truncado como completo.

**Impacto.** Downloads e arquivos de integração (faturamento, rotina noturna) truncados ou com NULs sem qualquer erro, e queda do processo PHP (o PHP-FPM devolve 502 e recria o worker; o `php -S` não recria), quando duas exportações se sobrepõem; a janela de corrida cresce com o N+1 (F8): cerca de 6 s por exportação com 20 mil chamados. Exige sobreposição, mas o dano é silencioso, vai para sistemas que não o detectam e inclui a queda de processo.

**Evidência.** Original, seed de 5 linhas, 8 workers, 16 sessões distintas × 30 rodadas (480 downloads), duas execuções: 119 e 154 downloads com NUL, 14 e 19 vazios, 3 e 4 truncados, 15 e 8 conexões derrubadas (`RemoteDisconnected`/`ConnectionReset`). Com 20.005 chamados: 83 de 144 downloads corrompidos (tamanho certo, conteúdo errado); num caso 304.878 de 1.746.967 bytes (17%) eram NUL e só 16.520 das 20.006 linhas eram legíveis. Um verificador independente reproduziu a corrupção com NULs (11 a 12 de 16 downloads). O SIGBUS (código de saída 135) foi reproduzido por um auditor independente e por mim: 53 de 300 leituras de um PoC mínimo (`ob_start(); readfile()` contra outro processo que trunca e regrava o arquivo); com a saída direto para um pipe o kernel devolve EFAULT e o PHP termina com a saída truncada (73.728 bytes de 300 MB, sem crash), o que explica por que testes mais simples não o viam. Numa das minhas execuções o servidor embutido deixou de aceitar conexões, coerente com workers mortos que o `php -S` não recria. `EXPORT_DIR` inexistente: HTTP 200, `text/html`, 0 bytes. Corrigido, mesmas condições: 480/480 corretos em quatro execuções e 144/144 com 20 mil chamados.

**O que fiz.** O CSV é montado em memória (`php://temp`, até 16 MB em RAM) depois da consulta, com cada escrita conferida contra o tamanho da linha: falha total, escrita parcial (disco cheio no meio da linha) ou 0 (diretório temporário inválido) viram exceção antes de qualquer byte, e não CSV truncado com status 200; nada é lido por `readfile()` de arquivo compartilhado. A cópia que o código antigo deixava em `EXPORT_DIR/chamados.csv` (outros scripts podem lê-la, ver F15 e Decisões) agora é gravada só na exportação completa, antes de responder (o cliente que abandona o download não a deixa velha), por troca atômica (arquivo temporário oculto + `rename`) ou, se isso não for possível, no lugar; nunca no CSV restrito de um cliente. Falha ao gravar a cópia só vai ao log e não afeta a resposta.

### F10 — mediaResposta() divide por zero quando nenhum chamado tem minutos_resposta

**Onde:** `code/lib.php:116` · **Categoria:** bug · **Severidade:** média · **Confiança:** 99/100 · **Corrigido:** sim

**Mecanismo.** A função soma só os chamados com `minutos_resposta IS NOT NULL` (lib.php:109-115) e devolve `$soma / $qtd` (116). Se a consulta não trouxer linhas (tabela vazia, instalação nova, período em que ninguém foi respondido), `$qtd` é 0 e o PHP 8 lança `DivisionByZeroError`. index.php:74 chama `mediaResposta()` em toda renderização da listagem, então a página principal inteira dá HTTP 500 para todos os usuários.

**Impacto.** Indisponibilidade da tela principal para técnicos e clientes em estados legítimos dos dados.

**Evidência.** Original: com todos os `minutos_resposta` NULL, e também com a tabela vazia, `GET /` responde HTTP 500 (log: `DivisionByZeroError` em lib.php:116). Corrigido: 200 e 'Tempo medio de 1a resposta: 0 min' nos dois estados; com dados a média é idêntica (25,666…; 44,9851 nos 20 mil; 44,666168 nos 500 mil).

**O que fiz.** Devolve `0.0` quando não há chamado respondido (o tipo de retorno continua `float`).

### F11 — Fixação de sessão: o id de sessão não é renovado no login

**Onde:** `code/index.php:24-25` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 92/100 · **Corrigido:** sim

**Mecanismo.** Depois de `autenticar()` (index.php:22-23) o código só grava `$_SESSION['uid']` e `['papel']` (24-25) no mesmo id que o visitante já tinha, e `session.use_strict_mode` estava desligado. Quem consegue plantar um id no navegador da vítima (o XSS de F7 escreve `document.cookie`; também subdomínio ou rede) fica com a sessão autenticada quando a vítima entra.

**Impacto.** Sequestro de sessão sem precisar da senha, inclusive de técnicos.

**Evidência.** Original: um id que o servidor nunca emitiu foi aceito e, depois do login da ana, continuou sendo o id da sessão; com id emitido pelo servidor, o login não gera novo Set-Cookie. Corrigido: o id muda no login e um id plantado é descartado (cenários repetidos por dois verificadores).

**O que fiz.** `session_regenerate_id(true)` logo após a autenticação e `session.use_strict_mode=1` antes do `session_start()`.

### F12 — Injeção no CSV: títulos digitados por clientes vão crus para a planilha (fórmulas e forja ou fusão de colunas e linhas)

**Onde:** `code/lib.php:138-144` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 90/100 · **Corrigido:** sim

**Mecanismo.** `fputcsv` grava `$c['titulo']` (lib.php:140) exatamente como está no banco, e o título é texto livre do cliente. (a) Um título como `=HYPERLINK("http://evil/","clique")`, `+1+1` ou `@SUM(A1:A9)` vira célula que Excel/LibreOffice interpretam como fórmula ao abrir o arquivo do técnico. (b) Com o escape padrão `\` do `fputcsv`, uma barra invertida colada numa aspa ou no fim do campo é lida de um jeito pelo PHP e de outro por leitores RFC 4180: o título `Erro C:\"x",FALSO,Resolvido,-,2026-01-01` dá 5 colunas no `fgetcsv` e 9 no `csv.reader` do Python; um título que contenha `\"` seguido de quebra de linha e de uma linha inteira forja um registro separado; e um título inocente terminado em `\` (como `C:\`) funde colunas (2 em vez de 5) no `fgetcsv`, corrompendo a linha para integrações PHP.

**Impacto.** Execução de fórmulas na estação de quem abre o export (os técnicos são o alvo), colunas e linhas forjadas (por exemplo um Status 'Resolvido') em planilhas e relatórios que casam por texto, e linhas corrompidas para os consumidores PHP.

**Evidência.** Original, chamados 201-207 inseridos no banco de teste: as fórmulas saem intactas. Teste com 12 títulos (barra antes de aspa, barra no fim, quebra de linha, unicode, fórmulas): no legado 5 de 12 são lidos de forma divergente pelo `fgetcsv` e pelo `csv.reader` (9 colunas contra 5; 2 contra 5; ou mesmo número de colunas com texto diferente); trocar `$escape` para '' só move o problema (o leitor PHP passa a ver 9 colunas). Corrigido: 0 de 12 divergem. Os payloads de um verificador independente (`\",=1+1,"x` e um título com quebra de linha que forja uma linha `999`) saem como 7 linhas × 5 colunas, sem linha forjada, e um nome de técnico `=cmd|calc` sai como `'=cmd|calc`.

**O que fiz.** `celulaCsvSegura()`, aplicada ao título e ao nome do técnico (o '-' que marca 'sem técnico' fica intacto): prefixa apóstrofo quando o texto começa com `=`, `+`, `-`, `@`, TAB ou CR (recomendação OWASP) e insere um espaço depois de barra invertida colada numa aspa ou no fim do campo. A resposta também sai com `X-Content-Type-Options: nosniff`. Custo assumido e documentado: um título legítimo que comece com `-` (p.ex. '-50% de velocidade') sai como `'-50% de velocidade`, e um terminado em `\` ganha um espaço.

### F13 — Chave da API de e-mail transacional embutida no código

**Onde:** `code/config.php:14-15` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 90/100 · **Corrigido:** sim

**Mecanismo.** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')` é um literal sem leitura de ambiente; vale para todo checkout, cópia e backup e para o histórico do repositório. Nenhum arquivo de `code/` usa a constante: quem depende dela são outros scripts do ISP (as notificações de chamado) que incluem config.php.

**Impacto.** Quem ler o código pode enviar e-mail em nome da NetX pelo provedor e consumir a cota; como a constante nem é usada aqui, o segredo vaza sem benefício.

**Evidência.** `grep -n SMTP_API_KEY code/*.php` só encontra a definição (config.php:15).

**O que fiz.** A constante continua definida (scripts externos podem referenciá-la), mas passa a vir de `getenv('SMTP_API_KEY')`, vazia se ausente. **Deploy: definir a variável no ambiente do PHP e de cada script externo que carrega config.php (as notificações de chamado incluídas) e rotacionar a chave, que deve ser tratada como comprometida.**

### F14 — Cookie de sessão sem HttpOnly, SameSite nem Secure

**Onde:** `code/index.php:15` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 90/100 · **Corrigido:** sim

**Mecanismo.** `session_start()` (index.php:15) roda com os padrões do php.ini, que nesta instalação têm `session.cookie_httponly=Off` e `session.use_strict_mode=Off` (conferido em /etc/php/8.4/cli/php.ini e no do Apache): o cookie sai `Set-Cookie: PHPSESSID=…; path=/`. Sem HttpOnly qualquer XSS (F7) lê o cookie, o que faz do cookie o amplificador do XSS; sozinho, sem XSS, o achado seria baixo (as rotas de dados são GET de leitura, então o argumento de CSRF é fraco, e navegadores modernos tratam cookie sem SameSite como Lax).

**Impacto.** Facilita o roubo de sessão por XSS e expõe o cookie a requisições originadas em outros sites.

**Evidência.** Original: `Set-Cookie: PHPSESSID=…; path=/`. Corrigido: `Set-Cookie: PHPSESSID=…; path=/; HttpOnly; SameSite=Lax`.

**O que fiz.** Antes do `session_start()`: `session.cookie_httponly=1`, `session.cookie_samesite=Lax` (links externos e favoritos são navegações de topo por GET e continuam funcionando; o ini só existe no PHP ≥ 7.3) e `session.cookie_secure=1` só quando `$_SERVER['HTTPS']` indica HTTPS (Secure incondicional quebraria o login em HTTP; atrás de proxy que termina TLS o proxy precisa repassar HTTPS).

### F15 — Cópia persistente de todos os chamados em disco, com nome previsível, em diretório 0777 possivelmente servido pela web

**Onde:** `code/lib.php:125-126` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 55/100 · **Corrigido:** não (mitigado; ver abaixo)

**Mecanismo.** Depois de cada exportação `EXPORT_DIR/chamados.csv` fica no disco com todos os chamados (título, técnico, data), em caminho fixo e com modo `rw-rw-r--` (reproduzido: legível por qualquer usuário do servidor). `EXPORT_DIR` é `/var/www/painel/tmp` (config.php:18), que na máquina de teste existe com modo 0777 e sem sticky bit. Se a docroot do virtual host for `/var/www/painel`, `/tmp/chamados.csv` é baixável sem login; e o `fopen('w')` segue link simbólico, então quem tiver acesso local pode pré-criar `chamados.csv` apontando para outro arquivo gravável pelo usuário do PHP e fazê-lo sobrescrever com o CSV, ou pré-criar um arquivo seu e transformar a exportação em página em branco. A docroot real não consta do pacote.

**Impacto.** Se a docroot cobrir o diretório, exposição não autenticada de todos os chamados; mesmo sem isso, dados pessoais ficam em repouso sem controle de acesso, num arquivo previsível que pode ser trocado por um link simbólico.

**Evidência.** Reproduzido por mim: o arquivo deixado por uma exportação tem modo 0664, e com um symlink pré-criado em `chamados.csv` o código original sobrescreveu o arquivo-alvo com o CSV. Revisores independentes demonstraram também o download sem cookie com o diretório dentro da docroot do `php -S` e a página em branco com arquivo pré-criado 0444. (Chamo de candidato N a N-ésima versão do meu código que foi congelada para verificação; o 7 é o entregue.) Com um atacante local trocando por symlink, durante 15 s (~190 mil trocas), qualquer arquivo novo de um diretório 0777 sem sticky, o candidato 3 (`tempnam` + `chmod` por caminho) alterou o modo da vítima de 600 para 644 em 3 de 3 rodadas, e uma versão intermediária minha (`chmod` com a máscara do modo existente) chegou a deixá-la com modo 000 por um bug meu. A quarta rodada de verificação achou ainda uma falha que eu tinha introduzido no candidato 5 ao ampliar o ramo 'no lugar', e eu a reproduzi: ele abria o nome final com `fopen('cb')`, que segue link simbólico, e um atacante que plantasse o link e apagasse cada temporário antes do `rename` (o que força esse ramo) fazia o PHP sobrescrever a vítima (numa execução de 8 s com dois escritores, o monitor viu a vítima alterada 14.905 vezes nesse ataque, 2.340 com o atacante trocando todo arquivo do diretório por link, 1.286 alternando o nome final entre arquivo comum e link e 6 trocando cada temporário por link); numa de 10 s o mesmo `'cb'` criou 825 vezes um arquivo no caminho pendente escolhido pelo atacante, e um arquivo com dois links físicos foi sobrescrito. No entregue (recusa link simbólico e arquivo com mais de um link físico, abre com `r+b`, que não cria arquivo, e confere por `fstat` e `lstat` que o inode aberto é o do nome) os mesmos ataques deram 0 alterações da vítima e 0 arquivos criados no caminho pendente, e o arquivo de dois links ficou intacto; o atacante que troca por link todo arquivo novo do diretório (3 rodadas de 15 s sem mexer no nome final e 3 mexendo, ~81 mil e ~98 mil trocas) também não alterou a vítima, que seguiu em 600 (em troca, o `chamados.csv` pode ficar ausente, porque o pós-`rename` remove o destino que virou link). Com uma ACL padrão no diretório (montada por `setxattr`, que faz o arquivo novo nascer 664 apesar do umask 077), o candidato 4 publicou um `chamados.csv` restrito (600) como 664; do candidato 5 em diante a cópia grava no lugar e o mantém em 600. Sem espaço para o temporário (tmpfs de 256 KB, exportação de 6.000 linhas que não cabe), o ramo 'no lugar' do candidato 5 deixava a cópia antiga truncada (183.823 bytes completos viraram 262.144 incompletos); a quinta rodada achou o mesmo dano quando nem o temporário podia ser criado (tmpfs sem inodes livres), que os candidatos 5 e 6 tinham. O entregue mantém a cópia antiga completa nos dois casos e o log traz a causa ('No space left on device'). Não consegui verificar o layout de produção, por isso a confiança é moderada.

**O que fiz (e por que não corrigi).** Mitigado, não eliminado (corrigido=false): mantive a cópia porque outros scripts podem lê-la (ver Decisões), mas agora ela é gravada só na exportação completa, num temporário oculto de nome aleatório e imprevisível criado com `fopen('x')` (não sobrescreve arquivo existente; a proteção contra link é o nome imprevisível, pois o PHP segue link pendente) e publicada por `rename` atômico, que substitui um link simbólico em vez de segui-lo (verificado: o alvo do link ficou intacto; no original foi sobrescrito). O modo de um arquivo existente limita o do novo (aplicado pelo umask na criação, sem `chmod` por caminho, e conferido por `fstat` para o caso de ACL padrão); se o diretório é fechado, se o `rename` é recusado (bind mount de arquivo, outro sistema de arquivos) ou se o modo não puder ser respeitado, e o arquivo é gravável, grava no lugar, sem esperar lock (esse ramo não é atômico: uma falha de escrita no meio dele, por disco, cota ou limite de tamanho, deixa a cópia incompleta, como no código original), mas só num arquivo comum com um único link físico que não seja link simbólico, aberto com `r+b` (não cria arquivo) e com o mesmo inode do nome (conferido por `fstat` e `lstat`), e nunca quando o temporário não pôde ser criado ou copiado por inteiro (disco, inodes ou cota esgotados: a cópia anterior fica, velha, em vez de truncada; o erro vai ao log); sobras de processos mortos são limpas; o cliente nunca grava a cópia e o arquivo nunca aparece pela metade no caminho do `rename` (12.115 leituras concorrentes, com 8 processos exportando em loop, sem nenhuma incompleta). Limites que assumo: permanece um arquivo com todos os chamados no disco; o `rename` troca o inode (dono, grupo, ACL, hardlinks e quem observa o arquivo por inotify não o acompanham) e pede espaço livre, inode e cota para um segundo arquivo; um `chamados.csv` que o usuário do PHP pode escrever mas não ler só é atualizado pelo `rename`, não no lugar (o ramo abre com `r+b`); se o temporário não puder ser criado, por qualquer motivo, a cópia fica velha (o ramo 'no lugar' não roda); num diretório 0777 sem sticky um usuário local ainda pode apagar ou trocar o `chamados.csv`; um CSV acima de 16 MB usa um arquivo temporário do PHP no diretório temporário do sistema, que não é removido se o processo morrer por kill. **Deploy: apagar o `chamados.csv` que já existe em `/var/www/painel/tmp`, confirmar que o diretório não é servido e trocá-lo por um diretório fora da docroot, com dono igual ao usuário do PHP e modo 0750 (ou ao menos sticky).**

### F16 — Login sem limite de tentativas nem bloqueio, agora com custo de CPU por tentativa

**Onde:** `code/index.php:20-29` · **Categoria:** seguranca · **Severidade:** média · **Confiança:** 90/100 · **Corrigido:** não (só reportado)

**Mecanismo.** O bloco de login (index.php:20-29) chama `autenticar()` a cada POST, sem contador por usuário ou IP, atraso, bloqueio ou captcha: 200 tentativas erradas seguidas foram respondidas normalmente e a senha certa entrou depois. No original cada tentativa custava 0,8 ms de CPU; depois de F6 toda tentativa que falha gasta um bcrypt (custo 10, ~62 ms, cerca de 80 vezes mais), porque é isso que impede o tempo de resposta de revelar quais logins existem. O mesmo endpoint vira, portanto, um amplificador de carga sem limitação, e cada requisição anônima ainda abre uma conexão no banco e cria um arquivo de sessão (index.php:9-15) antes de chegar ao login.

**Impacto.** Adivinhação de senha e preenchimento de credenciais sem atrito, e saturação do pool de workers por requisições anônimas de login (cada worker processa ~16 tentativas falhas por segundo com custo 10, contra ~1.250 no original).

**Evidência.** Leitura de index.php:20-29; 200 POSTs seguidos sem bloqueio (verificador independente). Tempos medidos nesta máquina, que variam com a carga: custo 12 (padrão do PHP 8.4) 246–358 ms por operação (`password_hash` 358 ms, `password_verify` 267 ms), `md5` 0,17 µs; com o custo 10 adotado, 62–72 ms por tentativa que falha.

**O que fiz (e por que não corrigi).** Não implementei: exige estado novo (tabela ou cache de tentativas) e altera o comportamento do login; limitar por IP pune clientes atrás de CGNAT e limitar por login permite trancar a conta alheia. Recomendo limitar a taxa na borda (fail2ban, WAF, `mod_evasive`, limite por IP no proxy) ou numa tabela `tentativas_login`. O custo 10 do bcrypt foi escolhido em parte para limitar esta amplificação.

### F17 — Listagem e CSV sem limite de linhas (sem paginação): a tela principal estoura a memória em tabelas grandes

**Onde:** `code/lib.php:80-85` · **Categoria:** performance · **Severidade:** média · **Confiança:** 75/100 · **Corrigido:** não (só reportado)

**Mecanismo.** `SELECT * FROM chamados` sem `LIMIT` (lib.php:80-85) devolve todos os chamados, inclusive a coluna TEXT `descricao` que a tabela nem exibe, e o index.php gera uma linha de HTML para cada um (index.php:88-96). A memória cresce ~1,85 KB por chamado com `descricao` de 400 bytes (e cresce com o tamanho da descrição): com `memory_limit=128M` (o padrão do php.ini de produção) a listagem estoura perto de 70 mil chamados e a tela principal responde HTTP 500.

**Impacto.** Com o crescimento do histórico a tela principal deixa de abrir (HTTP 500 por memória), para todos os usuários, sem aviso prévio.

**Evidência.** 120.005 chamados com `descricao` de 400 bytes (o consumo cresce com o tamanho da descrição): a listagem precisa de ~218 MB de pico real no original e no corrigido (sem regressão) e estoura com `memory_limit=128M` nos dois (HTTP 500). Um verificador independente mediu 140 MB e a mesma falha perto de 55 mil chamados com descrições maiores. O CSV, em compensação, caiu de ~60 MB para ~32 MB de pico real (com a saída indo direto ao cliente; um script que a captura com `ob_start` mede 82 e 42 MB) porque o novo SELECT só traz as colunas exportadas: pelo HTTP o corrigido responde 200 a partir de `memory_limit=40M` (HTTP 500 com 32M) e o original só a partir de ~62M (HTTP 500 com 56M).

**O que fiz (e por que não corrigi).** Não alterei a listagem (corrigido=false): paginar mudaria o conteúdo da tela e os consumidores da lib precisam do conjunto completo; `SELECT *` também faz parte do formato de retorno de `listarChamados()`. Recomendo paginar só a tela numa versão futura, com parâmetro novo (p.ex. `pagina`) e sem tocar nas funções públicas, e subir o `memory_limit` enquanto isso.

### F18 — mediaResposta() traz todas as linhas para o PHP só para somar

**Onde:** `code/lib.php:109-115` · **Categoria:** performance · **Severidade:** baixa · **Confiança:** 80/100 · **Corrigido:** sim

**Mecanismo.** A função faz `SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL` (lib.php:109), percorre todas as linhas em PHP somando e contando (112-115), trabalho que o banco faz com `SUM`/`COUNT`, e roda a cada renderização da listagem (index.php:74).

**Impacto.** Custo linear em linhas e memória a cada visita à tela principal: baixo hoje, cresce com o histórico.

**Evidência.** Medido com 500.005 chamados: 395–444 ms e 9,7 MB de pico no original contra 248–263 ms e 2,0 MB com agregação no banco; média idêntica (44,666168).

**O que fiz.** `SELECT COALESCE(SUM(minutos_resposta), 0), COUNT(minutos_resposta)` e a divisão em PHP: mesma aritmética de antes (soma inteira / contagem), então o `float` devolvido é idêntico bit a bit. Não usei `AVG()` porque o DECIMAL do MySQL arredonda a 4 casas e mudaria o valor.

### F19 — O lock da sessão do PHP dura a requisição inteira: uma listagem ou export lento bloqueia as outras requisições do mesmo usuário

**Onde:** `code/index.php:15-39` · **Categoria:** performance · **Severidade:** baixa · **Confiança:** 93/100 · **Corrigido:** sim

**Mecanismo.** `session_start()` (index.php:15) com o handler `files` mantém o flock do arquivo de sessão até o fim do script, mas o código só lê `$_SESSION` nas linhas 38-39 (a única escrita é no login, que termina em `exit`). Enquanto uma listagem ou exportação demora, qualquer outra requisição do mesmo usuário (outra aba, um `?ver=`) espera o lock. O efeito era agravado pelo N+1 (F8) e por tabelas grandes, que alongavam cada requisição.

**Impacto.** Uma exportação lenta congela o restante da navegação do usuário; com o N+1 e tabelas grandes são segundos ou dezenas de segundos de bloqueio por sessão.

**Evidência.** Original com 40.005 chamados (verificador independente): `?ver=1` na mesma sessão esperou até o fim de uma listagem de ~15 s (a espera é o tempo que falta para a listagem terminar); em outra sessão, 4,8 ms. Código corrigido com um export de 500 mil linhas em andamento na mesma sessão: `?ver=101` levou 9,45 s antes e 0,008 s depois de `session_write_close()`.

**O que fiz.** `session_write_close()` logo depois de ler `uid` e `papel` (index.php, depois da leitura de `$_SESSION`): nada mais escreve na sessão, e login, fixação e strict mode continuam corretos (cenários repetidos).

### F20 — Parâmetros de formulário em formato de array derrubam a requisição com TypeError (HTTP 500)

**Onde:** `code/index.php:22` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 97/100 · **Corrigido:** sim

**Mecanismo.** `$_POST['login']` e `$_POST['senha']` vão direto para `autenticar(mysqli, string, string)` (index.php:22) e `$_GET['busca']` para `listarChamados(mysqli, string)` (72-73). Com `login[]=a`, `senha[]=x` ou `busca[]=a` o valor é array e o PHP 8 lança `TypeError` antes de a função rodar.

**Impacto.** Um visitante (login) ou usuário logado (busca) provoca erro fatal e ruído no log com uma requisição; não há exploração além da indisponibilidade daquela requisição.

**Evidência.** Original: `POST login[]=a`, `POST senha[]=x` (sem sessão) e `GET ?busca[]=a` (logado) respondem HTTP 500; o log registra 6 fatais `TypeError`. Corrigido: os três respondem 200.

**O que fiz.** `is_string()` antes do uso: array vira texto vazio (o login não autentica; a busca fica vazia). As funções mantêm as assinaturas `string`.

### F21 — Falha de conexão e exceções: a mensagem tratada nunca aparece no PHP 8.1+ e, com display_errors=On, detalhes vazam

**Onde:** `code/index.php:9-13` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 90/100 · **Corrigido:** sim

**Mecanismo.** `new mysqli(...)` seguido de `if ($db->connect_errno) die('Falha ao conectar ao banco.')` (index.php:9-12) foi escrito para o PHP antigo, que emitia warning e preenchia `connect_errno`. No PHP 8.1+ o construtor lança `mysqli_sql_exception`: a verificação é código morto e o visitante (inclusive anônimo, pois a conexão abre antes do login) recebe HTTP 500 com corpo vazio. Com `display_errors=On` no php.ini de produção, a exceção não tratada mostraria usuário, host e nome do banco, caminhos e trechos de SQL (a senha não vaza: o PHP ≥ 8.2 a marca como parâmetro sensível).

**Impacto.** A mensagem de erro prevista nunca é vista e, conforme a configuração do PHP, detalhes internos da conexão e do SQL vazam ao navegador.

**Evidência.** Original, com senha errada: HTTP 500, corpo vazio; log com `Uncaught mysqli_sql_exception: Access denied for user 'painel'@'127.0.0.1'`. Corrigido: HTTP 500 com 'Falha ao conectar ao banco.' (também sem DB_PASS).

**O que fiz.** Construtor dentro de `try/catch (mysqli_sql_exception)` com log do motivo e resposta HTTP 500 'Falha ao conectar ao banco.' (o caminho `connect_errno` continua valendo no PHP < 8.1) e `ini_set('display_errors', '0')` no ponto de entrada web, para erros irem ao log e não ao navegador. Só o index.php foi tocado: config.php e lib.php também rodam em scripts CLI.

### F22 — fputcsv() sem o parâmetro $escape: depreciado no PHP 8.4

**Onde:** `code/lib.php:130-144` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 85/100 · **Corrigido:** sim

**Mecanismo.** As chamadas de `fputcsv` (lib.php:130 e 138) omitem `$escape`; o PHP 8.4 emite E_DEPRECATED ('the $escape parameter must be provided as its default value will change') a cada chamada. Com `error_reporting` incluindo E_DEPRECATED e `display_errors` ligado o aviso é impresso antes dos cabeçalhos e dentro do corpo do CSV; com o php.ini padrão do Debian (`E_ALL & ~E_DEPRECATED`) nada aparece.

**Impacto.** CSV com texto de aviso no início e cabeçalhos rejeitados ('headers already sent') em ambientes que exibem depreciações; o comportamento muda no PHP 9.

**Evidência.** Reproduzido com `php -d error_reporting=-1`: uma mensagem de depreciação por chamada. Corrigido: nenhum aviso com E_ALL e display_errors=1 (respostas e log).

**O que fiz.** Passa `','`, `'"'`, `'\\'` explícitos, que é o padrão histórico: os bytes do CSV não mudam (CSV do técnico idêntico ao do original).

### F23 — O papel gravado na sessão no login nunca é revalidado, e agora é a base da regra de visibilidade

**Onde:** `code/index.php:38-39` · **Categoria:** seguranca · **Severidade:** baixa · **Confiança:** 75/100 · **Corrigido:** não (só reportado)

**Mecanismo.** index.php:24-25 grava `uid` e `papel` na sessão no login e index.php:38-39 os lê a cada requisição sem consultar o banco. No original o papel era lido e ignorado; depois de F2, F3 e F4 ele decide o que cada usuário vê. Um técnico rebaixado para cliente, ou uma conta removida, continua vendo e exportando tudo até a sessão acabar.

**Impacto.** Rebaixamento ou desligamento de um técnico só vale no próximo login; a janela é a vida da sessão (por padrão, expira por inatividade em 24 min; contínua se o usuário seguir ativo).

**Evidência.** Um revisor independente executou: conta apagada do banco segue vendo a lista e o CSV enquanto a sessão existe.

**O que fiz (e por que não corrigi).** Não alterei (corrigido=false): reconsultar o papel a cada requisição acrescenta uma consulta por página e muda a semântica de sessão, e as sessões do PHP já expiram por inatividade. Se o turnover de técnicos for alto, leia `papel` do banco em cada requisição (`SELECT papel FROM usuarios WHERE id = ?`) e encerre a sessão se a conta não existir.

### F24 — Não há logout nem expiração de sessão definida no código

**Onde:** `code/index.php:15` · **Categoria:** arquitetura · **Severidade:** baixa · **Confiança:** 90/100 · **Corrigido:** não (só reportado)

**Mecanismo.** Nenhuma rota destrói a sessão (`session_destroy`/`unset`) e o código não define tempo de vida do cookie nem `session.gc_maxlifetime`: o cookie dura até fechar o navegador e o arquivo de sessão até o coletor de lixo do PHP (padrão de 1440 s de inatividade, dependente do deploy). Em estação compartilhada o usuário não consegue encerrar a própria sessão.

**Impacto.** Sessão aberta em computador compartilhado permanece válida até o navegador fechar ou o GC rodar.

**Evidência.** Leitura de index.php e lib.php: não há `session_destroy`, `setcookie` de expiração nem rota de logout.

**O que fiz (e por que não corrigi).** Não implementei: uma rota nova de logout e uma política de expiração estão fora do manifesto e mudariam a superfície pública. Recomendo uma rota `?sair=1` que destrua a sessão e defina `session.gc_maxlifetime` e o tempo do cookie no deploy.

### F25 — rotuloPrioridade() e formatarStatus() com condicionais aninhados e encadeados

**Onde:** `code/lib.php:26-59` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 75/100 · **Corrigido:** sim

**Mecanismo.** `rotuloPrioridade` tem quatro níveis de `if/else` aninhados (lib.php:42-58) para chegar a cinco rótulos, e `formatarStatus` encadeia `if/else if/else` (28-34). A regra real (sem resposta → 'Aguardando'; prioridade < 3 → 'Normal'; até 30 min → dentro do SLA; prioridade 4 → crítico) fica escondida no aninhamento, o que torna arriscado mexer em qualquer limiar.

**Impacto.** Manutenibilidade: os textos são contrato do relatório gerencial e um erro de ramo altera a classificação em silêncio.

**Evidência.** Teste diferencial exaustivo original × corrigido: grade de 100 combinações prioridade × minutos (negativos, nulo, 0, 29, 30, 31, 1000) e 19 valores de status (-5…8, 100, 255, 1000, PHP_INT_MAX/MIN): saída idêntica; um verificador independente confirmou a equivalência em 1.112 casos.

**O que fiz.** Reescritas com cláusulas de guarda (`rotuloPrioridade`) e tabela de rótulos (`formatarStatus`), mantendo ramos, limiares e textos.

### F26 — formatarStatus() devolve 'Resolvido' para qualquer valor desconhecido

**Onde:** `code/lib.php:32-34` · **Categoria:** qualidade · **Severidade:** baixa · **Confiança:** 65/100 · **Corrigido:** não (só reportado)

**Mecanismo.** O `else` final (lib.php:32-34) captura tudo que não é 1 ou 2; a coluna `status` é TINYINT sem CHECK (schema.sql), então 0, 4 ou um valor corrompido aparece na tela e no CSV como 'Resolvido', escondendo o dado ruim e fazendo um chamado possivelmente aberto parecer encerrado.

**Impacto.** Indicador enganoso apenas se existir status fora de 1–3 (o seed não tem); o relatório gerencial casa por esses textos.

**Evidência.** Varredura de 19 valores inteiros: todos fora de 1 e 2 retornam 'Resolvido'.

**O que fiz (e por que não corrigi).** Não alterei o comportamento: o manifesto só define 1–3 e trocar o fallback mudaria uma saída que consumidores comparam por texto. Apenas tornei o fallback explícito (tabela e comentário). Recomendo um `CHECK (status BETWEEN 1 AND 3)` numa migração futura.

### F27 — Chamado crítico sem resposta aparece como 'Aguardando 1a resposta', nunca como SLA estourado

**Onde:** `code/lib.php:56-58` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 35/100 · **Corrigido:** não (só reportado)

**Mecanismo.** `rotuloPrioridade` só avalia o SLA quando `$minutos !== null`, isto é, depois da primeira resposta (lib.php:42); sem resposta devolve 'Aguardando 1a resposta' para qualquer prioridade (56-58). No seed o chamado 104 (prioridade 4, sem resposta desde 2026-06-04) mostra 'Aguardando 1a resposta'; 'CRITICO - SLA estourado' só pode aparecer para quem já foi respondido com atraso, nunca para quem espera indefinidamente.

**Impacto.** Se a intenção do rótulo é sinalizar SLA vencido, os piores casos não são sinalizados. É hipótese: o código não diz se essa é a regra pretendida.

**Evidência.** Listagem do seed: chamado 104 (prioridade 4, `minutos_resposta` NULL) → 'Aguardando 1a resposta'.

**O que fiz (e por que não corrigi).** Não alterei: é regra de negócio que o manifesto não descreve, exigiria calcular o tempo decorrido desde `criado_em` e mudaria rótulos visíveis na listagem e no detalhe. Levar à área de produto.

### F28 — Busca: %, _ e \ são metacaracteres do LIKE em vez de texto literal (comportamento preservado por decisão)

**Onde:** `code/lib.php:82` · **Categoria:** bug · **Severidade:** baixa · **Confiança:** 60/100 · **Corrigido:** não (só reportado)

**Mecanismo.** O termo entra em `LIKE '%<termo>%'` sem escapar metacaracteres (lib.php:82): `%` casa qualquer sequência, `_` qualquer caractere e `\` escapa o próximo. Buscar por `_` ou `%` devolve todos os chamados e `100%` encontra qualquer título com '100'. É independente da injeção (F1): com o termo parametrizado os metacaracteres continuam valendo. Uma diferença mínima e aceita: sequências com barra invertida (`\t`, `\n`, `\r`, `\0`, `\b`, `\Z`, `\\`) deixaram de passar antes pelo parser de literais do SQL e ficam só com a semântica do LIKE, então uma busca por elas encontra outra coisa que no original.

**Impacto.** Busca imprecisa para termos com esses caracteres (devolve mais do que o pedido); sem risco de segurança depois de F1, e um termo só de curingas equivale a não filtrar.

**Evidência.** Executado no original e no corrigido, com resultados idênticos: `_` e `%` devolvem os 5 chamados, `S_m` encontra só o 101 ('Sem conexao…'), `100%` e `\\` não encontram nada.

**O que fiz (e por que não corrigi).** Não alterei: escapar `%`, `_` e `\` os trataria como texto literal e mudaria o resultado de buscas e favoritos existentes com esses caracteres, e o manifesto só diz 'filtrada por título'. Se o produto quiser busca literal, basta escapar os três caracteres antes do `bind_param` e declarar o caractere de escape no LIKE.

### F29 — tecnicoNome() e verChamado() montam SQL por concatenação (não explorável hoje: ids tipados como int)

**Onde:** `code/lib.php:69-100` · **Categoria:** seguranca · **Severidade:** baixa · **Confiança:** 10/100 · **Corrigido:** sim

**Mecanismo.** `'... WHERE id = ' . $tecnicoId` (lib.php:69) e `'... WHERE id = ' . $id` (lib.php:100) concatenam o argumento sem bind. Hoje não há injeção: os parâmetros são declarados `?int` e `int`, o PHP converte ou lança TypeError antes de a função rodar, e o único chamador faz `(int)` na entrada (index.php:53). O risco é só futuro: quem chamar com outra origem ou relaxar a tipagem abre uma injeção.

**Impacto.** Nenhuma exploração atual; padrão frágil numa base que já teve injeção real (F1).

**Evidência.** `?ver=1%20OR%201=1` vira `(int)` 1 e responde 'Chamado nao encontrado.' (cenário executado); 928 variações de `ver` testadas por um verificador independente não injetaram nada.

**O que fiz.** Trocadas por consultas preparadas (`executarConsulta`) por consistência, sem mudança de comportamento. A confiança é baixa de propósito: a probabilidade de isto ser uma vulnerabilidade real hoje é pequena.

## 3. Decisões — o que deliberadamente não mudei, e por quê

Cada item abaixo também está em `nao_alterei` no `achados.json`.

1. **Nome, parâmetros, tipos e formato de retorno das funções públicas de lib.php (inclusive os valores dos arrays como strings), e as funções legadas continuarem sem filtro por usuário.** Contrato do manifesto. A regra de visibilidade foi implementada em funções novas (`escopoDono`, `listarChamadosDoDono`, `verChamadoDoDono`, `exportarCsvDoDono`) usadas pela camada web; `listarChamados`, `verChamado` e `exportarCsv` continuam devolvendo tudo porque a rotina noturna, o relatório gerencial e a integração de faturamento as chamam sem sessão. Consultas preparadas devolvem int onde `query()` devolvia string, então os valores são convertidos de volta para string (quem ligasse `MYSQLI_OPT_INT_AND_FLOAT_NATIVE` na sua conexão recebia ints do original e passa a receber strings). O risco assumido é alguém chamar a variante sem filtro numa tela nova; os docblocks avisam.
2. **Continuar gravando `EXPORT_DIR/chamados.csv` na exportação completa (agora de forma atômica), em vez de só escrever na saída.** O manifesto diz que `exportarCsv` 'escreve o CSV na saída', mas o comentário do próprio config.php diz que EXPORT_DIR é o 'diretório onde os relatórios exportados são gravados': a rotina noturna ou outro script pode ler o arquivo, e parar de gravá-lo o deixaria lendo um arquivo velho sem erro. Quatro revisores independentes apontaram esse risco. A cópia mantém o nome de antes e tem o mesmo conteúdo do download, com as neutralizações de F12 (apóstrofo na frente de títulos e nomes que começam com `=`, `+`, `-`, `@`, TAB ou CR; espaço depois de barra invertida colada numa aspa ou no fim); o modo é o do arquivo antigo limitado por `0666 & ~umask` (nunca mais largo, inclusive com ACL padrão no diretório, caso em que grava no lugar); e ela não tem a corrida, não segue link simbólico e nunca vem do CSV restrito de um cliente (F9, F15). Em troca, o `rename` não preserva dono, grupo, ACL nem hardlinks do arquivo antigo, não acompanha quem observa o arquivo por inotify e substitui um symlink no nome final em vez de atualizá-lo; o ramo 'no lugar' (diretório fechado, bind mount de arquivo) não é atômico (uma falha de escrita no meio dele, por disco, cota ou limite de tamanho, deixa a cópia incompleta, como no código original), só escreve em arquivo comum com um único link físico e, se o temporário não puder ser criado ou copiado por inteiro (disco, inodes ou cota esgotados), deixa a cópia anterior como está (velha) em vez de truncá-la. Se ninguém a consome, pode-se remover `gravarCopiaCsv` e o bloco `if ($donoId === null) { gravarCopiaCsv($csv); }` de `exportarCsvDoDono()`, sem tocar em mais nada (remover só a função faz toda exportação completa falhar).
3. **formatarStatus(): valores fora de 1 e 2 continuam resultando em 'Resolvido'.** O manifesto só define 1, 2 e 3 e o relatório gerencial casa por esses textos; trocar o fallback por outro rótulo, ou lançar exceção, mudaria uma saída consumida por texto e derrubaria a listagem inteira por causa de uma linha ruim. Recomendo CHECK no banco numa migração futura (F26).
4. **rotuloPrioridade(): rótulos, o limiar `> 30` minutos e 'Aguardando 1a resposta' para qualquer prioridade sem resposta.** São regra de negócio não descrita no manifesto e visível na listagem e no detalhe; mudar exigiria decisão de produto e um cálculo novo de tempo decorrido (F27). A refatoração estrutural foi validada como idêntica (F25).
5. **Curingas `%` e `_` no termo de busca continuam valendo como curingas do LIKE.** Fecha a injeção (F1) sem alterar o que um termo comum encontra; há links e favoritos externos com `busca=`. Curingas não são vetor de injeção e escapá-los mudaria resultados de buscas existentes (F28). A única diferença é em buscas com sequências de barra invertida (`\t`, `\n`, `\r`, `\0`, `\b`, `\Z`, `\\`; `\'`, `\"`, `\%` e `\_` continuam iguais), que deixaram de passar pelo parser de literais do SQL.
6. **`SELECT *`, as chaves e a ordem dos arrays retornados, e a ausência de LIMIT/paginação na listagem.** Fazem parte do formato de retorno de `listarChamados()` e os consumidores precisam do conjunto completo; paginar ou cortar colunas mudaria o contrato (F17). Só o CSV passou a trazer apenas as colunas que exporta.
7. **Página 'Chamado nao encontrado.' continua com HTTP 200, rotas sem sessão continuam devolvendo o formulário de login com 200, e o login falho continua sem mensagem.** Mudar status ou texto altera saída observável. Um chamado de outro cliente responde exatamente como um inexistente, o que evita criar um oráculo de existência de ids; responder 403 só para o alheio o criaria.
8. **O cabeçalho do CSV continua saindo como `ID,Titulo,Status,Tecnico,"Aberto em"` (com aspas em 'Aberto em'), embora o manifesto o escreva sem aspas.** É o que o `fputcsv` sempre produziu (campo com espaço é cotado) e é igual ao texto do manifesto para qualquer leitor de CSV (`csv.reader` e `fgetcsv` devolvem as cinco colunas esperadas). Trocar `fputcsv` por montagem manual mudaria os bytes de todas as linhas que consumidores já leem.
9. **Indicador 'Tempo medio de 1a resposta' continua global, também para clientes.** É um agregado sem dado pessoal e é o número que todos veem hoje; escopá-lo por cliente mudaria o valor exibido e não expõe chamado de ninguém.
10. **Limite de tentativas de login, CSRF no formulário de login, rota de logout e expiração de sessão explícita.** Exigem estado novo (tabela, cache), rotas novas ou mudança de UX fora do que o manifesto descreve (F16, F24). Recomendo tratar o limite de taxa na borda (fail2ban, WAF) e, em versão futura, acrescentar logout e tempo de vida de sessão definidos no deploy.
11. **O papel do usuário continua gravado na sessão no login, sem revalidação por requisição.** Reconsultar o papel a cada requisição acrescenta uma consulta por página e muda a semântica de sessão; as sessões do PHP já expiram por inatividade e o ganho é pequeno neste sistema (F23).
12. **Depois do login o redirecionamento continua indo para `index.php`, sem preservar `?ver=`, `?busca=` ou `?export=`.** Quem abre um favorito `?ver=<id>` sem sessão cai na listagem depois de entrar. É limitação de UX, não de segurança, e mudar o destino do 302 altera comportamento observável; preservar a query string exigiria validar o destino para não criar um redirecionamento aberto.
13. **schema.sql e seed.sql continuam dentro de code/; o seed continua com MD5().** Restrição de não mover arquivos. O seed com MD5 é de propósito: exercita o caminho de migração transparente de hash. Se a pasta do código for a docroot, bloquear `*.sql` no servidor web: hoje seriam baixáveis.
14. **mysqli como camada de dados, nenhuma dependência nova, nenhum arquivo renomeado ou movido; tecnicoNome() e as constantes EXPORT_DIR e SMTP_API_KEY continuam definidas.** Restrições 2, 3 e 5 do enunciado e o manifesto. Outros scripts podem chamar `tecnicoNome()` e ler as constantes; por isso ficam, mas sem segredo embutido.
15. **Limites do bcrypt (72 bytes; senha com byte NUL não entra no hash), custo fixo em 10 e a migração sem volta.** `password_hash` segue o padrão do PHP: só os 72 primeiros bytes contam, o `password_verify` ignora tudo depois de um byte NUL (`senha123\0qualquer` entra como `senha123` numa conta migrada; só chega por requisição forjada e não dá vantagem a quem não sabe a senha) e, no PHP 8.4, senha com NUL é recusada no hash. No login legado uma senha com NUL continua verificada por MD5 e simplesmente não migra; para usuário inexistente o custo é gasto com entrada fixa, sem erro. Pré-hash com SHA-256 fugiria do formato padrão de `password_verify` que outros scripts podem usar. O custo 10 (mínimo recomendado pela OWASP) foi escolhido em vez dos 12 do PHP 8.4 para estabilizar o formato entre versões e limitar a CPU por tentativa (F16); `password_needs_rehash` sobe o custo no login seguinte se a constante mudar. A migração é irreversível: depois do primeiro login pós-ALTER o código antigo não autentica as contas migradas, então faça backup de `usuarios` antes.
16. **O MD5 continua no caminho de verificação legado (autenticar).** É o que permite entrar com os hashes existentes e migrá-los no primeiro login sem resetar senhas; é usado só para comparar, nunca para gravar.
17. **Cabeçalhos defensivos além de `X-Content-Type-Options` no CSV (CSP, X-Frame-Options, Referrer-Policy).** As páginas não usam script nem recursos externos e o ganho é pequeno; `X-Frame-Options` poderia quebrar uma eventual incorporação do painel em outro portal, o que o pacote não permite descartar.

## 4. Mudanças observáveis e pré-requisitos de deploy

**Mudanças de comportamento que um consumidor pode perceber (todas intencionais):**

- Cliente passa a ver, buscar, abrir e exportar **somente** os chamados que abriu; técnico continua vendo tudo, com respostas idênticas às de antes. Chamado de outro cliente responde "Chamado nao encontrado.".
- CSV: título ou nome de técnico que comece com `=`, `+`, `-`, `@`, TAB ou CR sai com um apóstrofo na frente (um título legítimo como "-50% de velocidade" vira `'-50% de velocidade`); barra invertida colada numa aspa ou no fim do texto ganha um espaço depois; a resposta traz `X-Content-Type-Options: nosniff`. Cabeçalho, ordem, rótulos e demais colunas são byte a byte os de antes.
- `exportarCsv()` continua escrevendo o CSV na saída e deixando a cópia em `EXPORT_DIR/chamados.csv` (mesmo nome; conteúdo igual ao do download, com as neutralizações do CSV descritas acima; modo limitado por `0666 & ~umask`), mas agora gravada antes de responder, por troca atômica (ou no lugar, se o diretório é fechado ou o `rename` é recusado; o ramo no lugar não escreve em link simbólico nem em arquivo com mais de um link físico), só na exportação completa (técnico ou rotina batch; o CSV restrito de um cliente nunca é gravado) e sem afetar a resposta se a gravação falhar. O `rename` troca o inode: dono, grupo, ACL, hardlinks e quem observa o arquivo por inotify (`close_write` no nome) não o acompanham, e é preciso espaço livre, inode e cota para um segundo arquivo: sem eles a cópia anterior é mantida, velha, e o erro vai ao log.
- Exportações acima de 16 MB usam um arquivo temporário do PHP no diretório temporário do sistema: se ele for inválido ou estiver cheio a exportação falha com HTTP 500 (antes escrevia direto em `EXPORT_DIR`), e o arquivo não é removido se o processo morrer por kill.
- Busca: termos com sequências de barra invertida (`\t`, `\n`, `\r`, `\0`, `\b`, `\Z`, `\\`; `\'`, `\"`, `\%` e `\_` continuam iguais) encontram outra coisa, porque deixaram de passar pelo parser de literais do SQL; `%` e `_` seguem como curingas.
- Listagem: chamados com o mesmo instante de criação saem sempre por id crescente (antes a ordem entre eles não era definida; na prática saía assim, com exceções).
- Sessão: cookie com `HttpOnly` e `SameSite=Lax` (e `Secure` em HTTPS); o id muda no login; ids nunca emitidos pelo servidor são descartados; o lock da sessão é liberado logo depois da leitura, então requisições do mesmo usuário não se bloqueiam.
- Erros que eram HTTP 500 sem corpo agora respondem de forma tratada: busca com apóstrofo ou `busca[]`, `login[]`/`senha[]`, tela principal sem chamado respondido (média "0 min") e banco indisponível (500 com "Falha ao conectar ao banco."). `display_errors` fica desligado no index.php; os motivos vão para o log.
- Senhas: no primeiro login bem-sucedido de cada conta o hash MD5 é regravado com bcrypt (custo 10), desde que a coluna `senha` já seja larga o bastante (pré-requisito 3; enquanto não for, o log registra "hash nao migrado"). Os logins do seed continuam os mesmos; enquanto a coluna for `CHAR(32)`, cada login legado bem-sucedido consulta `information_schema` e escreve uma linha no log. Toda tentativa de login que falha passa a custar ~60 ms de CPU (antes ~1 ms), para que o tempo não revele quais logins existem.
- Sem `DB_PASS` no ambiente a aplicação falha (HTTP 500); antes usava a senha embutida.

**Pré-requisitos de deploy, em ordem:**

1. Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do processo PHP (pool do PHP-FPM com `env[...]`, `SetEnv` do Apache ou unit do systemd) **e** no ambiente de cada script externo que carrega `config.php` ou `lib.php` (rotina noturna, relatório gerencial, integração de faturamento, notificações de chamado): cron e serviços fora do servidor web não herdam o ambiente dele, e sem as variáveis `DB_PASS` e `SMTP_API_KEY` ficam vazias (conexão recusada, envio de e-mail falha em silêncio).
2. Rotacionar a senha do usuário `painel` no banco e a chave do provedor de e-mail: os valores antigos estão no código-fonte e no histórico e devem ser tratados como comprometidos.
3. Fazer backup da tabela `usuarios` e então `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`, antes ou junto do deploy. Enquanto a coluna for `CHAR(32)` nada quebra (as senhas seguem em MD5 e o login funciona; verificado inclusive com `sql_mode` não estrito), só a migração do hash não acontece. Depois do primeiro login pós-ALTER não há volta: o código antigo não autentica as contas migradas, e o rollback exige restaurar os hashes MD5 do backup. A conta do banco da aplicação precisa de UPDATE em `usuarios` (o código antigo só lia); sem isso a migração nunca acontece e o log registra o motivo.
4. Apagar `/var/www/painel/tmp/chamados.csv` (cópia de todos os chamados deixada pelo código antigo) e confirmar que esse diretório não é servido pelo servidor web; de preferência mover `EXPORT_DIR` para fora da docroot, com permissão só para o usuário do PHP e o da rotina noturna.
5. Se a pasta do código for a docroot, bloquear o acesso a `*.sql` (`schema.sql` e `seed.sql` estão ao lado do `index.php`).
6. Limitar a taxa de tentativas de login na borda (fail2ban, WAF, limite por IP no proxy): o código não faz isso e cada tentativa agora custa CPU.
7. Conferir que `SameSite`/`Secure` fazem sentido no ambiente: `SameSite` exige PHP ≥ 7.3, e atrás de proxy que termina TLS o proxy precisa repassar `HTTPS` para o cookie sair com `Secure`. `session.auto_start` deve estar desligado e nenhum `auto_prepend_file` pode iniciar a sessão antes do index.php, senão o endurecimento do cookie não vale.
8. Garantir `TMPDIR`/`sys_temp_dir` válidos e com espaço livre (ao menos o tamanho do CSV) se houver exportações acima de 16 MB.

## 5. Como verifiquei

Tudo foi executado, não só lido: banco MariaDB 11.8 privado e descartável (o MariaDB do usuário não foi tocado), `php -S` com sessões isoladas por porta e scripts que rodam o **mesmo** roteiro contra o original e contra o corrigido.

Nas comparações abaixo, "candidato N" é a N-ésima versão do meu código que foi congelada para verificação; o 7 é o entregue e os anteriores não fazem parte da entrega.

| O quê | Resultado |
| --- | --- |
| Contrato de `lib.php` (38 blocos: assinaturas por Reflection, grade de 100 combinações de `rotuloPrioridade`, 19 status, listagens e `verChamado` com `var_export` para ver tipos, `mediaResposta`, saída de `exportarCsv`, 12 chamadas de `autenticar`) | 37 idênticos; 1 diferente: `listarChamados("o'brien")` lançava exceção SQL no original e agora devolve lista vazia. |
| 153 cenários HTTP (anônimo e 4 usuários × 20 rotas, 9 ataques, sessão, NUL e senha de 100 KB), banco no schema novo e no antigo | Resultados iguais nos dois schemas. Técnico: corpos idênticos (só ganha o cabeçalho `nosniff` no CSV). Diferenças restantes: cliente sem chamados alheios, ataques barrados, cookies, 500 → 200. |
| Escala, 20.005 chamados (e 500.005 para a média; 120.005 para memória, com `descricao` de 400 bytes por chamado) | `listarChamados()` 17.148 consultas/6.560 ms → 1/124 ms; `exportarCsv()` 17.148/5.747 ms → 1/192 ms (com a cópia em disco); CSV do técnico com o mesmo hash; `mediaResposta` 395–444 ms/9,7 MB → 248–263 ms/2 MB, mesmo valor; listagem com ~218 MB nos dois; CSV de ~60 → ~32 MB de pico real (82 → 42 MB se a saída é capturada em memória). |
| Concorrência: 16 sessões distintas × 30 rodadas de `?export=csv`, 8 workers | Original (duas execuções): 119 e 154 downloads com NUL, 14 e 19 vazios, conexões derrubadas. Corrigido: 480/480 corretos em quatro execuções. Com 20 mil chamados: 83/144 corrompidos → 0/144. SIGBUS (código 135) no original: 53 de 300 leituras de um PoC com `ob_start(); readfile()` contra um escritor concorrente. |
| Cópia `chamados.csv`: 8 processos exportando em loop (~17 mil exportações em 20 s) e um leitor contínuo | 12.115 leituras do arquivo (cada uma tinha de ser a exportação completa de um só escritor), nenhuma incompleta, misturada ou ausente, nenhum temporário sobrando, nenhum vazamento de umask; cliente nunca grava a cópia; cópia atualizada mesmo se o cliente abandona o download (web e CLI com a saída fechada no meio). |
| Cópia: symlink pré-criado no nome final; atacante local em diretório 0777 sem sticky trocando arquivos por symlink (6 rodadas de 15 s, ~180 mil trocas) e, em 6 variantes de 8 a 10 s, trocando ou apagando também o temporário para forçar o ramo no lugar (inclusive alternando o nome final entre arquivo comum e link, existente ou pendente); arquivo com 2 links físicos; modos e umask; diretório 0555; órfãos; disco cheio | Symlink no nome final: alvo intacto (no original foi sobrescrito). Atacante: vítima segue 600 e intacta nas 6 rodadas e nas 5 variantes que miram a vítima, e o PHP não cria arquivo no alvo pendente da sexta (o candidato 5 sobrescreveu a vítima em 4 das 5 e criou 825 arquivos no alvo pendente); arquivo com 2 links físicos não é tocado no lugar; disco cheio e inodes esgotados (tmpfs de 256 KB): a cópia antiga fica completa (o candidato 5 a deixava truncada com o disco cheio e o 6 também com os inodes esgotados). Modo de arquivo existente nunca alargado, nem com ACL padrão no diretório (aí grava no lugar e mantém 600); arquivo novo = `0666 & ~umask` como o `fopen` antigo; diretório fechado com arquivo gravável é atualizado no lugar; órfãos com mais de 1 h são removidos, inclusive em `EXPORT_DIR` com metacaracteres de glob. |
| `TMPDIR` inválido, exportação de 60 mil (8 MB) e de 300 mil chamados (>16 MB), linha com título/descrição NULL | Até 16 MB não usa o diretório temporário e sai completa; acima disso falha com exceção, 0 bytes e a cópia boa intacta (o candidato 3 truncava em 2 MB com HTTP 200 e trocava a cópia). Escrita parcial no meio ou na última linha (limite de tamanho de arquivo): exceção, 0 bytes e cópia intacta (o candidato 4 já tratava o meio, mas deixava passar a escrita parcial na última linha). Linha com NULL: 200 em listagem, detalhe e CSV (o candidato 3 dava 500). |
| `chamados.csv` como bind mount de arquivo (`unshare -rm`); diretório fechado com leitor segurando `flock`; `prepare()` do UPDATE falhando sem exceções do mysqli (conta do app sem UPDATE) | Bind mount: a origem do bind é atualizada (os candidatos 3 e 4 a deixavam velha). `flock` alheio: a exportação não espera, a cópia fica como estava e o log diz. Conta sem UPDATE: o log mostra a causa real (`UPDATE command denied`), e o login passa. |
| Login: mediana de 15 tentativas por caminho | Original 0,8 ms em todos os caminhos; corrigido 62–72 ms em todos (inexistente, MD5 errada, bcrypt errada, senha com NUL, hash vazio, truncado, longo ou corrompido com sal íntegro) e ~82 ms nas três classes com senha de 8 MB: sem oráculo de tempo. Sob `LOCK TABLES` de backup: 0,00 s no original, 1,19 s no corrigido, com login OK. |
| Bordas: banco indisponível, `DB_PASS` ausente, tabela vazia, nenhum chamado respondido, PHP em `E_ALL` com `display_errors=1`, SQL não estrito com coluna `CHAR(32)`, senha com byte NUL, sessão da mesma conta com export lento | Sem mensagens de PHP nas respostas e no log; falha controlada sem `DB_PASS`; `/` responde 200 sem dados; nenhum usuário trancado para fora; NUL responde como no original (200); `?ver=` na mesma sessão durante um export de 500 mil linhas: 9,45 s → 0,008 s. |
| CSV: 12 títulos traiçoeiros e os payloads dos verificadores, lidos por `fgetcsv` e por `csv.reader` sobre o arquivo inteiro | Legado: 5 de 12 lidos de forma divergente. Corrigido: 0 de 12, 7 linhas × 5 colunas nos payloads de forja, sem linha forjada. |
| Sintaxe: varredura de tokens por construções posteriores ao PHP 7.1 (o legado usa `?array` e `void`) | Nenhuma; o scanner acusa as construções novas num controle positivo. |

**Verificação independente.** Além dos meus testes, o trabalho passou por (1) uma revisão independente em seis frentes (injeção e saída, autenticação e sessão, segredos e arquivos, bugs e robustez, desempenho, arquitetura e compatibilidade), feita por revisores que não viram a minha lista: 76 candidatos viraram 39 achados únicos, cada um verificado por um cético com execução no original (mais 4 extras de um crítico de completude). Todos os meus achados têm correspondente; o que a revisão trouxe e não virou achado próprio foi tratado como decisão (cabeçalho do CSV com aspas, redirect depois do login, média global e cabeçalhos defensivos: itens 8, 9, 12 e 17 da seção 3) ou fundido num achado (os dois `TypeError` por array em F20; o N+1 da listagem e do CSV em F8). Ajustei severidade e confiança onde os céticos divergiram (XSS e N+1 subiram para alta, paginação e cookie para média, e a corrupção do CSV subiu para alta depois que o SIGBUS foi reproduzido); e (2) seis rodadas de verificação adversarial do **código corrigido**, com verificadores que receberam a lista do que eu alego ter corrigido e tinham de desmentir. A primeira rodada achou regressões que eu tinha introduzido: um oráculo de tempo no login (a isca equalizava só metade dos caminhos), a migração de hash travando o login sob `LOCK TABLES`, a parada de gravação do `chamados.csv`, um bypass da neutralização do CSV por `\"`, a ordem invertida dos empates e comentários que prometiam mais do que o código. A segunda achou problemas na cópia atômica do CSV que eu tinha acabado de escrever (`fputcsv` devolvendo `0` com diretório temporário inválido, cópia gravada só depois de transmitir, modo do arquivo alargado, temporário órfão e corrida de symlink no temporário); num dos PoCs refiz o teste por conta própria, porque o atacante não reconhecia o novo nome do temporário, e encontrei um `chmod` por caminho meu que seguia o link (vítima com modo 000). A terceira achou resíduos nas correções: hash truncado com sal íntegro pagando dois bcrypts, o MD5 da senha calculado só no ramo legado (oráculo com senha de vários MB), escrita parcial da última linha, `rename` recusado em bind mount, ACL padrão anulando o umask, `glob` sem escape e, no documento, uma afirmação minha errada (eu dizia não ter reproduzido o SIGBUS; ele é reproduzível). A quarta, sobre o que eu tinha mudado depois da terceira, confirmou essas correções e achou outra regressão minha: ao ampliar o ramo 'no lugar' da cópia do CSV (para bind mount e ACL padrão) eu o deixei seguir link simbólico no nome final, de modo que um atacante local que também apagasse o temporário fazia o PHP sobrescrever um arquivo alheio (dois verificadores chegaram a isso de forma independente, um como falha do código e outro como afirmação falsa do relatório); achou também que esse ramo podia truncar uma cópia boa com o disco cheio e que o log podia citar um aviso anterior e alheio. A quinta, sobre essa correção, confirmou o endurecimento (troca do nome final por arquivo comum, link existente, link pendente, diretório e FIFO; link físico; hardlink com `protected_hardlinks` desligado simulado por troca em laço: 0 alterações da vítima) e achou um resíduo meu, que reproduzi: com os inodes esgotados o temporário nem era criado e o ramo 'no lugar' ainda truncava a cópia boa; achou também quatro imprecisões do texto (a lista das sequências de barra invertida que mudam na busca, 'a versão anterior' sem dizer qual, o consumo de memória sem o tamanho da descrição e o deploy citando só um dos scripts externos). A sexta (um auditor, sobre o texto final e o ajuste de código da quinta) confirmou o ajuste (cópia antiga intacta com os inodes esgotados, nenhum fluxo legítimo bloqueado) e achou só imprecisões de texto, todas minhas: números de memória do CSV que vinham de uma medição com a saída capturada em memória (o pico real é de ~60 para ~32 MB e, pelo HTTP, o original passa a partir de ~62M, ao contrário do que eu escrevia sobre 64M), a instrução de remover `gravarCopiaCsv` que esquecia a chamada em `exportarCsvDoDono`, dois tempos incoerentes em F19 e a frase de que a cópia mantinha o conteúdo de antes (ela traz as neutralizações do CSV). Corrigi tudo e reexecutei os PoCs. Os scripts de teste (contrato, HTTP, escala, concorrência, bordas) ficaram no diretório de trabalho da sessão e não fazem parte da entrega, que contém apenas `code/`, `RELATORIO.md` e `achados.json`.

**O que não consegui verificar:** MySQL 8 real (usei MariaDB 11.8; as consultas são SQL padrão e a API é mysqli/mysqlnd), PHP anterior ao 8.4 (só revisei a sintaxe por varredura de tokens e por leitura), Apache/PHP-FPM em vez do servidor embutido, Excel/LibreOffice reais (a neutralização do CSV foi validada com leitores PHP e Python), ACL padrão só com uma ACL montada por `setxattr` (não há `setfacl` aqui), inodes e cota esgotados só em tmpfs com `nr_inodes` e tamanho limitados (o sandbox não monta disco em loop), `protected_hardlinks=0` só simulado por troca do nome em laço (esta máquina usa 1), consumidores reais do ISP (a rotina noturna, o relatório e o faturamento não estão no pacote) e a docroot e o `php.ini` reais de produção (por isso a confiança é moderada em F15 e em parte de F21).
