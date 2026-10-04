# Relatório técnico — Painel de Chamados (NetX ISP)

Instância **LEB-100-A** (versão 1.1) · matriz `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`

## 1. Resumo

O sistema é pequeno (~300 linhas em 5 arquivos), funciona no caminho feliz e não mostra sinais de ter passado por revisão de segurança. O problema central não é estilo:
**a regra de visibilidade declarada no manifesto (cliente só vê o que abriu; técnico vê tudo) não existe no código** - listagem, detalhe e CSV mostram tudo a
qualquer usuário logado (F2-F4, F15) -, a busca é injetável em SQL (F1) e refletida sem escape (F5), há credenciais de produção no código-fonte (F6, F9), senhas em MD5 (F7),
sessão sem proteção contra fixação (F8) e defeitos de execução que derrubam a página no PHP 8 (divisão por zero na média, F10; arrays em parâmetros, F16). O export
grava um arquivo de nome fixo em disco (F11, F12, F19) e a listagem faz uma query por chamado (F13: com 20 mil chamados, 16.005 queries e 7,5 s).

Corrigi 21 dos 26 achados **sem alterar nenhuma assinatura pública, rota, parâmetro, HTML da listagem, formato do CSV ou rótulo** do manifesto.
5 ficaram sem correção de comportamento por razões de escopo ou contrato (F14, F23, F24, F25, F26); a seção Decisões explica cada um. Fora do que o manifesto fixa há mudanças observáveis que vale conhecer
(todas em Decisões): `exportarCsv()` não grava mais `EXPORT_DIR/chamados.csv` (a de maior risco); o download web do CSV põe apóstrofo em títulos iniciados por `= + - @` e muda as aspas de títulos com barra invertida;
termos de busca com barra invertida casam diferente; o cookie de sessão é regenerado no login. Antes de publicar há **passos operacionais obrigatórios** (seção Implantação):
definir `DB_PASS` no ambiente de todos os scripts, rotacionar as credenciais vazadas e, só depois do deploy, ampliar a coluna `senha` (que liga a migração de hashes, com plano de rollback).

Em vez de confiar só na leitura, reproduzi cada falha no original, comparei original e corrigido nos mesmos cenários (função e HTTP, com os quatro usuários do seed) e rodei um harness
de caracterização escrito por um agente independente (COMPAT 13/13, FIX 14/14 no código entregue); seis auditores independentes revisaram o código e reencontraram os achados, e duas rodadas de revisores adversariais
(cinco e depois três) atacaram o resultado: nenhuma violação do manifesto e nenhuma regressão de código; os problemas menores que acharam foram tratados.

| Id | Severidade | Categoria | Conf. | Corrigido | Achado |
| --- | --- | --- | --- | --- | --- |
| F1 | crítica | segurança | 98 | sim | SQL injection em listarChamados: o termo de busca é concatenado dentro do LIKE |
| F2 | alta | segurança | 98 | sim | Detalhe do chamado (`?ver=<id>`) não verifica o dono: qualquer cliente lê chamados de outros |
| F3 | alta | segurança | 98 | sim | Listagem e busca mostram os chamados de todos os clientes a qualquer usuário logado |
| F4 | alta | segurança | 98 | sim | Exportação CSV entrega todos os chamados a qualquer usuário logado |
| F5 | alta | segurança | 98 | sim | XSS refletido: o termo de busca é impresso sem escape no atributo value e no texto |
| F6 | alta | segurança | 97 | sim | Senha do banco de produção embutida em `config.php` como fallback |
| F7 | média | segurança | 95 | sim | Senhas guardadas e comparadas como MD5 puro (sem sal, rápido) |
| F8 | média | segurança | 90 | sim | Sessão: ID não é renovado no login (fixação) e cookie sem HttpOnly/SameSite |
| F9 | média | segurança | 85 | sim | Chave de API de e-mail (`SMTP_API_KEY`) embutida em `config.php` |
| F10 | média | bug | 95 | sim | Divisão por zero em mediaResposta derruba a listagem quando nenhum chamado foi respondido |
| F11 | média | segurança | 55 | sim | Export grava o CSV de todos os chamados em `EXPORT_DIR` e o deixa no disco (exposto se o diretório for servido pela web) |
| F12 | média | bug | 88 | sim | Export com arquivo de nome fixo: corrida entre requisições e falhas silenciosas |
| F13 | média | performance | 96 | sim | N+1: uma query por chamado para buscar o nome do técnico (listagem e export) |
| F14 | média | segurança | 80 | não | Login sem limite de tentativas nem atraso (força bruta) - e agora cada tentativa custa ~0,3 s de CPU |
| F15 | média | arquitetura | 65 | sim | Nenhuma camada aplica autorização: as funções de dados não recebem o usuário e a regra de visibilidade não está codificada em lugar nenhum |
| F16 | baixa | bug | 95 | sim | Parâmetros em array (busca[]=, login[]=) provocam TypeError e HTTP 500 |
| F17 | baixa | bug | 80 | sim | Tratamento de falha de conexão é código morto no PHP >= 8.1 |
| F18 | baixa | segurança | 45 | sim | Injeção de fórmula no CSV: título digitado pelo cliente sai sem neutralização |
| F19 | baixa | arquitetura | 70 | sim | exportarCsv acumula consulta, formatação, E/S em arquivo e HTTP, e depende de `EXPORT_DIR`, constante global definida em outro arquivo |
| F20 | baixa | performance | 80 | sim | `session_start()` segura o lock da sessão até o fim da requisição: requisições do mesmo usuário se serializam |
| F21 | baixa | performance | 75 | sim | mediaResposta traz todas as linhas para o PHP só para somar |
| F22 | baixa | qualidade | 85 | sim | rotuloPrioridade com quatro níveis de if/else aninhados |
| F23 | baixa | qualidade | 35 | não | formatarStatus rotula como 'Resolvido' qualquer status fora de 1..3 |
| F24 | baixa | bug | 30 | não | rotuloPrioridade não destaca chamado crítico que ainda não teve 1ª resposta |
| F25 | baixa | segurança | 60 | não | Sessão sem logout nem expiração absoluta; uid e papel ficam congelados até a sessão acabar |
| F26 | baixa | performance | 60 | não | Listagem sem paginação e busca com LIKE '%termo%' (varredura completa) |

Os achados abaixo estão na ordem em que eu priorizaria a correção (severidade, e dentro dela o que mais expõe dados ou derruba o serviço). Linhas citadas = numeração do arquivo **original** recebido.

## 2. Achados

### F1 — SQL injection em listarChamados: o termo de busca é concatenado dentro do LIKE

- **Onde:** `code/lib.php:81-85` (`listarChamados`), alimentada por `code/index.php:72-73` (`$_GET['busca']`)
- **Mecanismo:** `index.php:72` lê `$_GET['busca']` sem validar e passa a `listarChamados()`; `lib.php:82` faz `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"` e `lib.php:85` executa com `$db`->query(). Uma aspa simples fecha o literal e o resto vira SQL: o parâmetro chega ao banco sem escape nem bind. Qualquer usuário logado (inclusive cliente) alcança a rota. Só `autenticar()` usa prepared statement; este ponto não.
- **Impacto e severidade:** Leitura de qualquer tabela do banco de produção: logado como cliente 'ana', `?busca=zzz' UNION SELECT id,1,1,CONCAT(login,':',senha),'x',1,1,1,NOW() FROM usuarios -- -` devolveu login e hash de todos os usuários (técnicos incluídos, e o hash é MD5, ver F7); `?busca=zzz' OR SLEEP(1) OR '` fez a página levar 5,0 s contra 0,004 s (injeção cega por tempo). Também vaza chamados de outros clientes e permite inferir dados caractere a caractere. Uma busca legítima com apóstrofo (O'Brien) já derrubava a página com HTTP 500. **Severidade atribuída: crítica** (segurança).
- **Confiança:** 98/100 — Reproduzida de ponta a ponta (extração de hashes e atraso medido); confirmada por 6 auditores e 2 verificadores independentes.
- **O que fiz:** `painelConsultarChamados()` monta o SQL com marcadores e usa `prepare()`+`bind_param()` (`'%' . $busca . '%'` como parâmetro); `listarChamados()` só delega (assinatura intacta). Mantive os curingas % e _ da busca (ver Decisões); a barra invertida passa a ser interpretada só pelo LIKE (antes também pelo parser do literal SQL, ver Decisões). *(corrigido no código entregue: sim)*
- **Evidência:** Reproduzido no original (UNION e SLEEP). No corrigido os mesmos payloads são texto literal: 0 hashes na resposta e 0,005 s (sem atraso); harness X-SQLI (6 payloads) passa.

### F2 — Detalhe do chamado (`?ver=<id>`) não verifica o dono: qualquer cliente lê chamados de outros

- **Onde:** `code/index.php:52-67` (rota `?ver=`) e `code/lib.php:98-102` (`verChamado`)
- **Mecanismo:** `index.php:53` chama `verChamado($db, (int) $_GET['ver'])` (SELECT * FROM chamados WHERE id = N) e as linhas 59-65 imprimem título, status, prioridade e descrição sem comparar `$c['usuario_id']` com o usuário da sessão. `$uid` e `$papel` são atribuídos em `index.php:38-39` e nunca mais usados: a regra de visibilidade do manifesto simplesmente não existe no código. Os ids são sequenciais (101..105), então enumerar é trivial.
- **Impacto e severidade:** Quebra de controle de acesso (IDOR): logado como 'ana' (cliente), `?ver=103` devolveu o chamado do Bruno ('Troca de plano', 'Deseja upgrade para 500MB.'); `?ver=104` expõe 'Cobranca repetida no cartao'. Descrições de chamado costumam ter dado pessoal/financeiro. **Severidade atribuída: alta** (segurança).
- **Confiança:** 98/100 — Reproduzido; é exatamente a regra de negócio do manifesto sendo violada.
- **O que fiz:** Nova `podeVerChamado($chamado, $uid, $papel)`: técnico vê qualquer chamado; qualquer outro papel só os que abriu (usuario_id == uid, com cast para int, pois o banco devolve string). `index.php` responde ao chamado alheio exatamente como ao inexistente ('Chamado nao encontrado.', mesmo HTTP 200 de antes), para não revelar quais ids existem. `verChamado()` ficou intacta. *(corrigido no código entregue: sim)*
- **Evidência:** Original: ana vê o #103. Corrigido: ana em ?ver=103 e ?ver=999 recebe HTML idêntico; carla (técnica) continua vendo o #103; ana vê o #101 dela igual ao original (harness X-ACL2 e C-HTTP-CLI). Um revisor testou 35 formas de ?ver (1e2, 0x65, %20103, 101&ver=103, full-width...) sem nenhum vazamento.

### F3 — Listagem e busca mostram os chamados de todos os clientes a qualquer usuário logado

- **Onde:** `code/index.php:72-73` e `code/lib.php:78-93` (`listarChamados`)
- **Mecanismo:** `listarChamados()` faz SELECT * FROM chamados sem filtro por usuario_id e `index.php:73` a chama sem olhar `$papel`. A função não recebe quem pergunta, então o único lugar possível para aplicar a regra é o chamador - que não aplica.
- **Impacto e severidade:** Cliente 'ana' vê na tabela os 5 chamados (101-105), com título, status, prioridade e técnico dos de 'bruno'; a caixa de busca vira oráculo sobre títulos alheios. **Severidade atribuída: alta** (segurança).
- **Confiança:** 98/100 — Reproduzido.
- **O que fiz:** Nova `listarChamadosDoUsuario($db, $uid, $busca)` (`WHERE c.usuario_id = ?`); `index.php` usa essa para quem não é técnico e mantém `listarChamados()` (todos) para técnico. Assim a assinatura e o comportamento de `listarChamados()` para os scripts internos (relatório gerencial, rotina noturna) ficam como estavam. (O índice idx_usuario pode ajudar quando o filtro é seletivo; no meu teste, com só 2 usuários e 20 mil chamados, o otimizador preferiu varrer a tabela.) *(corrigido no código entregue: sim)*
- **Evidência:** Original: ana lista 105,104,103,102,101. Corrigido: ana 105,102,101; bruno 104,103; carla e diego os 5, na mesma ordem. Linhas `<tr>` dos chamados visíveis idênticas às do original (harness X-ACL1, C-HTTP-CLI).

### F4 — Exportação CSV entrega todos os chamados a qualquer usuário logado

- **Onde:** `code/index.php:44-47` e `code/lib.php:123-151` (`exportarCsv`)
- **Mecanismo:** exportarCsv(`$db`) não recebe usuário e exporta SELECT * FROM chamados ORDER BY id; o roteador a dispara para qualquer sessão autenticada, sem consultar `$papel`.
- **Impacto e severidade:** Vazamento em massa numa única requisição, pior que o F2: 'ana' baixou o CSV com os 5 chamados, incluindo 103 e 104 do Bruno. **Severidade atribuída: alta** (segurança).
- **Confiança:** 98/100 — Reproduzido.
- **O que fiz:** O download web passa a usar a nova `exportarCsvWeb($db, $usuarioId)`: para não-técnicos só os chamados dele (mesmo formato), para o técnico todos. `exportarCsv()` em si NÃO foi filtrada nem alterada nos bytes: a rotina noturna e o relatório gerencial a chamam sem usuário e precisam do dump completo. *(corrigido no código entregue: sim)*
- **Evidência:** Original: CSV da ana com 5 linhas. Corrigido: ana recebe cabeçalho + 101,102,105; bruno 103,104; carla todos, com os mesmos bytes do original para o seed (harness X-ACL3, C-HTTP-TEC).

### F5 — XSS refletido: o termo de busca é impresso sem escape no atributo value e no texto

- **Onde:** `code/index.php:79` (atributo `value="..."`) e `code/index.php:82` (`Resultados para:`)
- **Mecanismo:** `index.php:72` guarda `$_GET['busca']` e as linhas 79 e 82 a concatenam direto no HTML. Aspas duplas fecham o atributo e `<script>` executa; no texto basta o próprio `<script>`. Todos os outros campos da tela passam por `htmlspecialchars()`; só este ficou de fora.
- **Impacto e severidade:** JavaScript do atacante roda na origem do painel quando um técnico/cliente logado abre o link: lê qualquer chamado a que a vítima tenha acesso, dispara ações e, como o cookie de sessão não tem HttpOnly (F8), pode roubá-lo. `?busca="><script>alert(1)</script>` voltou sem nenhum escape no original. **Severidade atribuída: alta** (segurança).
- **Confiança:** 98/100 — Reproduzido; exige que a vítima abra o link (reflexivo), mas qualquer usuário logado serve de alvo.
- **O que fiz:** `$buscaHtml = htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, calculado uma vez e usado nos dois pontos. *(corrigido no código entregue: sim)*
- **Evidência:** Original: `<input name="busca" value=""><script>alert(1)</script>" ...>`. Corrigido: `value="&quot;&gt;&lt;script&gt;..."`, nenhum elemento script (harness X-XSS, 4 entradas; o revisor atacante testou ainda título, descrição e nome do técnico).

### F6 — Senha do banco de produção embutida em `config.php` como fallback

- **Onde:** `code/config.php:11-12` (`DB_PASS`)
- **Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: '<senha de produção omitida deste relatório>')`: sempre que a variável de ambiente não existe (ou é vazia, ou "0", pois ?: trata valor falso como ausente) o sistema usa a senha literal do usuário 'painel' de produção. Ela está em toda cópia do código (checkouts, backups, homologação) e, presumivelmente, no histórico do repositório (não sei desde quando o fallback existe), e o operador nem percebe que está usando.
- **Impacto e severidade:** Quem lê o código (ou um backup exposto) ganha credencial do banco de produção - e a mesma senha vale em qualquer ambiente em que o fallback seja acionado. **Severidade atribuída: alta** (segurança).
- **Confiança:** 97/100 — Está literalmente no arquivo.
- **O que fiz:** Removi o literal: `DB_PASS` vem só do ambiente, com `getenv()` === false para distinguir ausente de definida (inclusive vazia). Sem a variável a conexão é recusada (fechado por padrão) e o `index.php` mostra a mesma mensagem 'Falha ao conectar ao banco.' (HTTP 500) com o motivo no log. O `config.php` não encerra o processo no require, para não derrubar scripts externos que só precisam das constantes. AÇÃO OPERACIONAL OBRIGATÓRIA (ver Implantação): definir `DB_PASS` no ambiente de TODOS os scripts que usam `config.php` (web, cron, CLI) antes do deploy e ROTACIONAR a senha. *(corrigido no código entregue: sim)*
- **Evidência:** O literal não aparece mais no código entregue (harness X-SECR); sem `DB_PASS`, ou com senha errada, o app responde 'Falha ao conectar ao banco.' com HTTP 500. O valor da senha foi omitido deste relatório de propósito.

### F7 — Senhas guardadas e comparadas como MD5 puro (sem sal, rápido)

- **Onde:** `code/lib.php:13-21` (`autenticar`), `code/schema.sql:7` (`senha CHAR(32)`), `code/seed.sql` (`MD5(...)`)
- **Mecanismo:** `autenticar()` calcula md5(`$senha`) e compara com a coluna no SQL (`lib.php:15-17`). MD5 sem sal é calculado a bilhões por segundo e é reversível por tabela pré-calculada; senhas iguais geram hashes iguais (no seed, ana e bruno têm o mesmo hash e7d80ffe..., carla e diego o mesmo 2b21aaa0...). A coluna CHAR(32) ainda impedia qualquer algoritmo melhor.
- **Impacto e severidade:** Qualquer vazamento da tabela vira senha em texto claro em segundos, inclusive de técnicos. Isoladamente é 'média' (exige antes acesso aos hashes); o F1 torna esse acesso alcançável por qualquer usuário logado, o que torna a quebra concreta neste sistema. **Severidade atribuída: média** (segurança).
- **Confiança:** 95/100 — O uso de MD5 é certo. A migração só vale depois do ALTER manual (por isso 'corrigido' é condicional à Implantação).
- **O que fiz:** `autenticar()` passa a usar `password_hash()`/`password_verify()`. Contas existentes (md5) continuam entrando - o seed e o manifesto exigem isso - e são regravadas com `password_hash()` no primeiro login válido (painelRegravarSenha), SÓ depois que a coluna for ampliada (`schema.sql` agora tem VARCHAR(255); em produção: ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL): o código confere a largura via information_schema antes de calcular o hash, para nunca truncar um hash e trancar o usuário. Contas que nunca logarem continuam em MD5 até uma migração em massa (decisão operacional). Efeitos colaterais tratados: falhas de login (inexistente, md5 errado, bcrypt errado) gastam o mesmo tempo, para não revelar por tempo quais logins existem; senha com mais de 72 bytes não é migrada (limite do bcrypt, que ignoraria o resto); senha com NUL é recusada contra hash bcrypt (o PHP pararia de ler nela). A migração é de mão única para o código antigo: ver plano de rollback em Implantação. *(corrigido no código entregue: sim)*
- **Evidência:** Seed: ana/senha123 loga e a coluna passa a '$2y$12$...' (60 chars); 2º login ok; senha errada -> null; bcrypt inserido à mão é aceito; com o schema ORIGINAL (CHAR(32)) o login funciona em 2 ms e o hash permanece md5, nada truncado (harness X-AUTH-UPG, C-AUTH nos dois esquemas). Tempo das falhas (inexistente / md5 errado / bcrypt errado / conta travada com '!' / hash $6$ legado errado / senha com NUL): todas entre ~270 e ~290 ms; hash $6$ legado correto continua aceito (e é promovido a bcrypt); senha de 100 bytes: entra e continua md5, e os 72 primeiros bytes são recusados; senha com NUL em conta md5 entra e não migra. Limites: a igualdade vale para entradas de tamanho normal e para hashes criados no custo atual do PASSWORD_DEFAULT (contas migradas por um PHP < 8.4, custo 10, respondem mais rápido até o próximo login); corpos de megabytes ainda mexem um pouco no tempo.

### F8 — Sessão: ID não é renovado no login (fixação) e cookie sem HttpOnly/SameSite

- **Onde:** `code/index.php:15` (`session_start`) e `code/index.php:23-27` (sucesso do login)
- **Mecanismo:** Depois de `autenticar()`, `index.php:24-25` grava uid/papel na MESMA sessão que existia antes do login, sem `session_regenerate_id()`; session.use_strict_mode vem 0 por padrão, então o PHP aceita um PHPSESSID escolhido pelo atacante. O cookie sai sem HttpOnly nem SameSite.
- **Impacto e severidade:** Sequestro de sessão de técnico: reproduzido plantando PHPSESSID=atacantescolheu...; a técnica 'carla' faz login com esse cookie e o atacante, com o mesmo cookie e sem senha, abriu o painel dela. Exige plantar o cookie (XSS do F5 sem HttpOnly, subdomínio, rede), por isso 'média'. **Severidade atribuída: média** (segurança).
- **Confiança:** 90/100 — Reproduzido; a exploração depende de um meio de plantar o cookie.
- **O que fiz:** session_regenerate_id(true) logo após autenticar; antes do `session_start()`: use_strict_mode=1, cookie_httponly=1, cookie_samesite=Lax (Lax mantém links/favoritos externos funcionando) e cookie_secure só quando o acesso é HTTPS (Secure incondicional derrubaria o login em HTTP). Limites: SameSite só tem efeito em PHP >= 7.3 (antes disso o ini_set é ignorado em silêncio e o cookie sai só com HttpOnly); sessões abertas antes do deploy continuam válidas e só ganham as flags novas no próximo login; consumidores HTTP scriptados devem usar o cookie devolvido no 302 do login (o ID é regenerado). O endurecimento também depende da configuração: com session.auto_start=1 os ini_set não valem, e atrás de balanceador que termina o TLS o código não vê HTTPS e não marca Secure - defina use_strict_mode=1, cookie_httponly=1, cookie_samesite=Lax e (se o TLS termina fora) cookie_secure=1 no php.ini/pool. *(corrigido no código entregue: sim)*
- **Evidência:** Corrigido: ID muda no login; `Set-Cookie: PHPSESSID=...; path=/; HttpOnly; SameSite=Lax`; cookie forjado não vira sessão (mostra o formulário) (harness X-SESS; o revisor atacante confirmou também Secure sob HTTPS).

### F9 — Chave de API de e-mail (`SMTP_API_KEY`) embutida em `config.php`

- **Onde:** `code/config.php:14-15`
- **Mecanismo:** `define('SMTP_API_KEY', '<chave omitida deste relatório>')` é um segredo literal versionado. Nenhum arquivo de code/ usa a constante (o comentário do `config.php` sugere notificações de chamado; o consumidor, se existir, é externo e não foi visto), então no código ela só existe para vazar.
- **Impacto e severidade:** Quem tem o código pode enviar e-mail transacional em nome da NetX (phishing de clientes) até a chave ser trocada. **Severidade atribuída: média** (segurança).
- **Confiança:** 85/100 — Segredo literal é certo; 85 e não mais porque não consigo confirmar que a chave ainda é válida.
- **O que fiz:** A constante continua definida (scripts externos que a leem não quebram na carga) mas o valor vem do ambiente (getenv === false ? '' : valor). AÇÃO OPERACIONAL: definir `SMTP_API_KEY` no ambiente do script de notificações (cron/CLI inclusive) e rotacionar a chave. *(corrigido no código entregue: sim)*
- **Evidência:** O literal não existe mais no código entregue (harness X-SECR). O valor da chave foi omitido deste relatório de propósito.

### F10 — Divisão por zero em mediaResposta derruba a listagem quando nenhum chamado foi respondido

- **Onde:** `code/lib.php:107-117` (`mediaResposta`), chamada em `code/index.php:74`
- **Mecanismo:** A função soma só as linhas com minutos_resposta IS NOT NULL e retorna `$soma / $qtd`. Com `$qtd` = 0 (instalação nova, ou todos os chamados ainda aguardando) o PHP 8 lança DivisionByZeroError - reproduzido: 'DivisionByZeroError: Division by zero @ `lib.php:116`'. O `index.php` chama `mediaResposta()` em toda listagem, então a tela inicial inteira cai.
- **Impacto e severidade:** Indisponibilidade da página principal (HTTP 500) para todos os usuários, e do relatório gerencial que usa a função, enquanto nenhum chamado tiver 1ª resposta. Antes do PHP 8 isso era só um warning, por isso o defeito pode ter ficado latente até uma atualização de PHP. **Severidade atribuída: média** (bug).
- **Confiança:** 95/100 — Reproduzido no PHP 8.4.
- **O que fiz:** A agregação agora é SELECT COALESCE(SUM(minutos_resposta),0), COUNT(minutos_resposta) e devolve 0.0 quando a contagem é zero. Com dados a conta é a mesma (soma inteira / contagem), então o resultado é idêntico ao do original bit a bit (não usei `AVG()`, que devolve DECIMAL com 4 casas). *(corrigido no código entregue: sim)*
- **Evidência:** Corrigido: com todos os minutos_resposta NULL, e com a tabela vazia, a listagem responde 200 e mostra 'Tempo medio de 1a resposta: 0 min'; com o seed devolve 25,666... como antes (harness X-DIV0, C-MEDIA).

### F11 — Export grava o CSV de todos os chamados em `EXPORT_DIR` e o deixa no disco (exposto se o diretório for servido pela web)

- **Onde:** `code/lib.php:125-150` (`exportarCsv`) e `code/config.php:17-18` (`EXPORT_DIR`)
- **Mecanismo:** `exportarCsv()` abre `EXPORT_DIR . '/chamados.csv'` com 'w', escreve tudo, só depois faz `readfile()` e nunca apaga o arquivo. `EXPORT_DIR` é /var/www/painel/tmp, dentro da árvore web típica do painel. Combinado com o F4, o clique de um cliente deixa em disco uma cópia completa dos chamados, com permissões herdadas do umask (aqui -rw-rw-r--, legível por outros usuários do SO); se o servidor web serve esse diretório, /tmp/chamados.csv é baixável sem login.
- **Impacto e severidade:** Exposição de dados fora do controle de sessão e dado pessoal em repouso sem necessidade. A gravidade depende da configuração do servidor web (não consigo vê-la), por isso a confiança é moderada. **Severidade atribuída: média** (segurança).
- **Confiança:** 55/100 — O arquivo persistir é certo; ser servido pela web depende de config que não vejo.
- **O que fiz:** O CSV é escrito direto em php://output: nenhum arquivo é criado. `EXPORT_DIR` continua definida (scripts externos podem referenciá-la) mas sem uso. ATENÇÃO (compatibilidade fora do manifesto): se algum script externo lia /var/www/painel/tmp/chamados.csv do disco, ele precisa passar a capturar a saída (ob_start) - ver Implantação, passo 2. *(corrigido no código entregue: sim)*
- **Evidência:** Original: após o export da ana, chamados.csv (399 bytes, 5 chamados) permaneceu no diretório. Corrigido: nada é gravado, nem em 96 exports concorrentes (harness X-EXPORT).

### F12 — Export com arquivo de nome fixo: corrida entre requisições e falhas silenciosas

- **Onde:** `code/lib.php:126-135` e `code/lib.php:146-150`
- **Mecanismo:** Todas as requisições usam o mesmo caminho: dois exports simultâneos abrem o arquivo com 'w' (trunca) enquanto o outro ainda faz `readfile()`, e o usuário recebe CSV truncado ou misturado. Além disso, `fopen()` falhando (diretório inexistente/sem permissão - é o caso neste ambiente) só gera um warning no log e retorna sem cabeçalho nem mensagem (HTTP 200 com corpo vazio), e `$res === false` (linha 133) retorna deixando um arquivo parcial. No PHP 8.4, `fputcsv()` sem o parâmetro `$escape` emite um Deprecated por linha; com display_errors ligado a mensagem 'Deprecated: `fputcsv()`: the `$escape` parameter must be provided...' é impressa no corpo da resposta, antes das linhas do CSV (reproduzido).
- **Impacto e severidade:** Downloads corrompidos de forma intermitente e sem log - risco para a integração de faturamento que consome o CSV. **Severidade atribuída: média** (bug).
- **Confiança:** 88/100 — O nome fixo e os 'return' silenciosos estão no código e a corrupção foi medida; 88 e não mais porque a corrida é probabilística.
- **O que fiz:** Sem arquivo compartilhado: a consulta é feita antes de qualquer saída (se falhar, não sai CSV pela metade: log + HTTP 500, inclusive com `MYSQLI_REPORT_OFF`) e as linhas são transmitidas direto. Os campos continuam passando por `fputcsv()` (mesmas aspas do formato legado) com o escape explícito, que silencia a depreciação do 8.4 sem mudar bytes em `exportarCsv()`. *(corrigido no código entregue: sim)*
- **Evidência:** Original: de 19 a 36 de 96 exports concorrentes saíram divergentes do CSV completo (3 execuções do harness) e, com o diretório ausente, a resposta foi 200 com 0 bytes. Corrigido: 96 exports concorrentes idênticos, log limpo.

### F13 — N+1: uma query por chamado para buscar o nome do técnico (listagem e export)

- **Onde:** `code/lib.php:89` (listarChamados), `code/lib.php:137` (exportarCsv) e `code/lib.php:64-72` (tecnicoNome)
- **Mecanismo:** Para cada linha de chamados, `tecnicoNome()` executa SELECT nome FROM usuarios WHERE id = N: a listagem faz 1 + (nº de chamados com técnico) queries e o export mais o mesmo tanto. Os dois ainda trazem SELECT * (inclui o TEXT descricao) mesmo quando só precisam de 5 colunas.
- **Impacto e severidade:** Latência e carga no banco crescem linearmente com o tamanho da tabela (sem paginação, F26); cada abertura do painel é uma rajada de queries. **Severidade atribuída: média** (performance).
- **Confiança:** 96/100 — Número de queries é determinístico (1+N); o impacto em tempo depende do volume.
- **O que fiz:** LEFT JOIN usuarios t ON t.id = c.tecnico_id com COALESCE(t.nome,'-'): uma query só, mesma chave tecnico_nome e o mesmo '-' do legado para chamado sem técnico. O export seleciona só as 5 colunas de que precisa. `tecnicoNome()` ficou no arquivo (compatibilidade) mas nenhum caminho a usa mais. *(corrigido no código entregue: sim)*
- **Evidência:** Medido com 20.000 chamados (16.000 com técnico): listarChamados 16.005 statements / 7,5 s -> 1 statement / 0,36 s; exportarCsv 16.005 / 8,4 s -> 1 / 0,23 s; página de listagem completa (3,0 MB de HTML) 6,8 s -> 0,45 s, com o mesmo número de bytes. Harness X-N1: 5 -> 200 chamados mantém 1 statement.

### F14 — Login sem limite de tentativas nem atraso (força bruta) - e agora cada tentativa custa ~0,3 s de CPU

- **Onde:** `code/index.php:20-29`
- **Mecanismo:** Cada POST de login chama `autenticar()` sem contar falhas por usuário/IP nem introduzir atraso; a página responde igual (e rápido) a cada tentativa. Contra senhas simples ('senha123') e hash MD5 isso é adivinhação em massa. Com a migração para bcrypt (F7) a falta de limite ganha um segundo efeito: cada tentativa passa a custar ~0,28 s de CPU no PHP 8.4 (custo 12), então um flood de logins também degrada o painel para todos (medido por um revisor: de 2 ms para ~0,5 s por página legítima durante o ataque).
- **Impacto e severidade:** Tomada de conta por tentativa e erro e consumo de CPU por requisições não autenticadas; em PHP puro não há contenção nenhuma. **Severidade atribuída: média** (segurança).
- **Confiança:** 80/100 — A ausência é certa; a relevância depende de existir proteção na borda, que não enxergo.
- **O que fiz:** NÃO corrigi: um limite de tentativas exige estado compartilhado (tabela ou cache) ou controle na borda (fail2ban/WAF/proxy), fora do escopo de 'evoluir sem reescrever' e do que o schema/manifesto preveem. Passa a ser PRÉ-REQUISITO de produção por causa do custo do bcrypt: limitar no proxy (Implantação, passo 8), por IP ou pelo id da conta resolvida: a collation do banco (ai_ci) faz 'ANA', 'ána' e 'ana ' autenticarem como 'ana', então um contador pelo texto digitado se reinicia a cada variante. *(corrigido no código entregue: não)*
- **Evidência:** Original: 200 POSTs de login com senha errada -> 200 respostas HTTP 200, sem atraso nem bloqueio; o 201º, com a senha certa, autenticou (302). Corrigido: igual (sem limite), mas cada falha leva ~0,28 s.

### F15 — Nenhuma camada aplica autorização: as funções de dados não recebem o usuário e a regra de visibilidade não está codificada em lugar nenhum

- **Onde:** `code/index.php:38-39` (`$uid`/`$papel` calculados e jamais usados) e as assinaturas de `code/lib.php:78`, `:98` e `:123`
- **Mecanismo:** listarChamados, verChamado e exportarCsv são funções de dados que não recebem quem pergunta, e o único ponto que conhece o usuário (`index.php:38-39`) calcula `$uid`/`$papel` e os abandona. A regra do manifesto não existe em nenhuma camada, então cada rota nova (F2, F3, F4) nasce aberta e não há um lugar único para auditar 'quem vê o quê'. As assinaturas são fixadas pelo manifesto (e usadas por scripts internos que precisam de todos os chamados), então a regra só pode viver no chamador ou em funções novas.
- **Impacto e severidade:** Causa estrutural dos F2-F4 e de qualquer rota futura: sem política central, cada novo ponto de saída pode vazar chamados. **Severidade atribuída: média** (arquitetura).
- **Confiança:** 65/100 — É a causa estrutural dos F2-F4 (reproduzidos); classificá-la como 'arquitetura' é interpretação minha.
- **O que fiz:** Política num lugar só: `veTodosOsChamados($papel)` define quem vê tudo (técnico; qualquer outro papel é tratado como cliente), `podeVerChamado()` a usa para o detalhe e o `index.php` a usa para escolher a variante com escopo (`listarChamadosDoUsuario`, `exportarCsvWeb($db, $uid)`), que aplicam o critério no SQL. As funções legadas (todos os chamados) continuam disponíveis para a rotina noturna e o relatório gerencial. Um novo papel com visão própria (ex.: supervisor) é tratado em `veTodosOsChamados()` (único ponto de decisão); se a visão for parcial, as variantes SQL com escopo precisam de critério novo. *(corrigido no código entregue: sim)*
- **Evidência:** Harness X-ACL1/2/3: ana vê só 101,102,105 na listagem, no detalhe e no CSV; técnicos veem os 5. O revisor atacante testou papel/uid forjados, sessão sem papel e cliente listado como técnico de chamado alheio: nenhum ganha visão extra.

### F16 — Parâmetros em array (busca[]=, login[]=) provocam TypeError e HTTP 500

- **Onde:** `code/index.php:22` (login) e `code/index.php:72-73` (busca); `code/index.php:53` (ver)
- **Mecanismo:** `$_POST['login']`, `$_POST['senha']` e `$_GET['busca']` podem ser arrays (`?busca[]=x`) e vão direto para funções declaradas com string: TypeError não tratado -> HTTP 500 (reproduzido, trace no log). `?ver[]=1` vira `(int) array` = 1 e mostra o chamado 1.
- **Impacto e severidade:** Qualquer visitante (o login nem exige sessão) produz erros 500 e ruído de log; com display_errors ligado o trace aparece na resposta. **Severidade atribuída: baixa** (bug).
- **Confiança:** 95/100 — Reproduzido.
- **O que fiz:** `is_string()` antes de usar: busca em array vira busca vazia, login/senha em array são credencial inválida (mostra o formulário), ver[] vira 'não encontrado'. *(corrigido no código entregue: sim)*
- **Evidência:** Original: 10 de 18 requisições com busca[]/login[]/senha[] deram HTTP 500 (ver[] e export[] já respondiam 200). Corrigido: nenhuma (harness X-ARR).

### F17 — Tratamento de falha de conexão é código morto no PHP >= 8.1

- **Onde:** `code/index.php:9-12`
- **Mecanismo:** O código confere `$db->connect_errno` depois de new `mysqli()`. Desde o PHP 8.1 o construtor lança mysqli_sql_exception (modo padrão), então o die('Falha ao conectar ao banco.') nunca roda: sobra uma exceção fatal não tratada (conexão recusada e senha errada: 'Fatal error: Uncaught mysqli_sql_exception', sem a mensagem esperada). Para host inexistente o PHP ainda imprime antes um Warning de DNS com caminho. A senha aparece redigida no trace do PHP 8.4 (SensitiveParameter, >= 8.2), mas no 8.1 entraria se zend.exception_ignore_args=0.
- **Impacto e severidade:** Mensagem de erro e caminhos internos expostos quando o banco recusa a conexão (dependendo do ini) e comportamento diferente do pretendido pelo autor. **Severidade atribuída: baixa** (bug).
- **Confiança:** 80/100 — Que o ramo é código morto no 8.x é certo; o vazamento de credencial só existiria no 8.1, por isso não passo de 80.
- **O que fiz:** try/catch de mysqli_sql_exception mais a checagem de connect_errno (PHP < 8.1), ambos em `abortarSemBanco()`: registra o motivo no log, responde HTTP 500 e mostra a mesma mensagem genérica de antes. O '@' em new `mysqli()` só silencia o Warning de DNS duplicado. *(corrigido no código entregue: sim)*
- **Evidência:** Harness R-CONN: host inexistente, conexão recusada e senha errada respondem 'Falha ao conectar ao banco.' sem stack trace (display_errors=1); no original os três vazavam.

### F18 — Injeção de fórmula no CSV: título digitado pelo cliente sai sem neutralização

- **Onde:** `code/lib.php:138-144` (linhas do CSV)
- **Mecanismo:** O título do chamado é texto livre do cliente e entra no CSV como está. Excel/LibreOffice tratam célula iniciada por = + - @ como fórmula (`=HYPERLINK(...)`, DDE), e quem abre o CSV é a equipe técnica/gerencial. Além disso o escape padrão do fputcsv ('\') não dobra uma aspa que vem depois de barra invertida: um título com barra+aspas 'abre' células novas num leitor RFC 4180 e contorna qualquer neutralização do primeiro caractere (achado de um revisor).
- **Impacto e severidade:** Execução de fórmula/exfiltração no computador de quem abre o export. Depende de o cliente conseguir criar chamado com esse título (a criação não está neste código) e de a planilha ser aberta com macros/links habilitados. **Severidade atribuída: baixa** (segurança).
- **Confiança:** 45/100 — O vetor é real mas depende de contexto que não está no código (como o chamado é criado e como o CSV é aberto); dois verificadores deram 55 e 35.
- **O que fiz:** Só no download web: `exportarCsvWeb()` prefixa com apóstrofo os títulos iniciados por = + - @ TAB ou CR (recomendação OWASP) e usa o escape RFC 4180 do fputcsv (PHP >= 7.4) para barra+aspas não abrir células. `exportarCsv()` (scripts internos) segue byte-idêntica ao original: é dado para máquina, e a injeção de fórmula é um problema de apresentação. A coluna Tecnico, o '-' de 'sem técnico' e os títulos sem barra invertida (fora o apóstrofo nos iniciados por = + - @) saem idênticos ao legado; com barra invertida o download web muda as aspas (a\b passa a sair sem aspas): é o preço do escape RFC. Consumidores PHP do download web devem ler com `fgetcsv($h, 0, ',', '"', '')` (o escape padrão do PHP lê errado alguns desses títulos); scripts internos devem usar `exportarCsv()`, byte-idêntica. Limite: em PHP < 7.4 o escape RFC não existe e a mitigação cobre só o primeiro caractere. *(corrigido no código entregue: sim)*
- **Evidência:** Download web: título `=1+1` sai como `'=1+1`; `x\",=cmd|...` fica numa célula só (python csv.reader, 5 colunas); `exportarCsv()` devolve os mesmos bytes do original para esses títulos; o CSV do seed é idêntico byte a byte (harness X-CSVINJ, C-CSV-FN, C-HTTP-TEC).

### F19 — exportarCsv acumula consulta, formatação, E/S em arquivo e HTTP, e depende de `EXPORT_DIR`, constante global definida em outro arquivo

- **Onde:** `code/lib.php:123-151` e `code/config.php:17-18`
- **Mecanismo:** A função faz SELECT *, abre um arquivo em `EXPORT_DIR . '/chamados.csv'`, formata o CSV, relê o próprio arquivo e emite cabeçalhos HTTP. `EXPORT_DIR` só existe se `config.php` foi carregado antes: quem incluir só `lib.php` recebe 'Error: Undefined constant "`EXPORT_DIR`"' (reproduzido). A etapa de disco é desnecessária para entregar o CSV e é o que cria F11 e F12.
- **Impacto e severidade:** Função difícil de testar e de reutilizar: depende de ambiente (arquivo, constante, SAPI web) e falha de formas diferentes conforme o disco. **Severidade atribuída: baixa** (arquitetura).
- **Confiança:** 70/100 — O acoplamento está no código e foi reproduzido; que isso seja um 'problema de arquitetura' e não só de robustez é julgamento.
- **O que fiz:** Removi a dependência de `EXPORT_DIR` e a E/S em disco: consulta primeiro, depois transmissão direta para php://output; a lógica ficou em `painelEscreverCsv()`, com `exportarCsv()` e `exportarCsvWeb()` como fachadas. Os cabeçalhos HTTP continuam dentro da função de propósito (ver Decisões). *(corrigido no código entregue: sim)*
- **Evidência:** `exportarCsv()` chamada só com `lib.php`: original 'Error: Undefined constant "`EXPORT_DIR`"'; corrigido gera os 399 bytes normalmente.

### F20 — `session_start()` segura o lock da sessão até o fim da requisição: requisições do mesmo usuário se serializam

- **Onde:** `code/index.php:15` (`session_start`) até o fim do script
- **Mecanismo:** O PHP abre o arquivo de sessão com lock exclusivo no `session_start()` e só o solta no fim da requisição (ou em `session_write_close()`). Depois do login a sessão só é lida (`index.php:38-39`), mas o lock continua até o último echo: uma segunda requisição da mesma sessão (outra aba, o clique em Exportar) fica na fila.
- **Impacto e severidade:** Medido com 20.000 chamados: com a listagem completa em andamento (6,8 s no original), `?ver=101` da mesma sessão levou 7,8 s. É o que faz uma listagem lenta travar o usuário inteiro. **Severidade atribuída: baixa** (performance).
- **Confiança:** 80/100 — Mecanismo e medição são diretos; relevância baixa porque, com o F13 corrigido, a listagem deixa de ser lenta.
- **O que fiz:** `session_write_close()` logo depois de ler uid/papel (nada grava na sessão a partir dali). No corrigido o mesmo teste leva 0,007 s (0,29 s sem a chamada, esperando a listagem de 0,45 s). *(corrigido no código entregue: sim)*
- **Evidência:** Teste com `PHP_CLI_SERVER_WORKERS`=6: original 7,78 s; corrigido sem o close 0,29 s; corrigido entregue 0,007 s.

### F21 — mediaResposta traz todas as linhas para o PHP só para somar

- **Onde:** `code/lib.php:109-115`
- **Mecanismo:** A função faz SELECT minutos_resposta de todos os chamados respondidos, percorre o resultado em PHP e soma. É a conta que o banco faz com SUM/COUNT transferindo uma linha; e é executada em toda abertura da listagem (`index.php:74`).
- **Impacto e severidade:** Transferência e memória proporcionais ao número de chamados a cada page view. O ganho medido é pequeno, por isso a severidade é baixa. **Severidade atribuída: baixa** (performance).
- **Confiança:** 75/100 — O fato é certo (a função lê todas as linhas); a relevância é pequena, o que está na severidade. Dois verificadores deram 35 e 25 para 'problema relevante'.
- **O que fiz:** SELECT COALESCE(SUM(...),0), COUNT(...) numa linha só (e a divisão continua em PHP, para o resultado ser idêntico bit a bit ao do legado). *(corrigido no código entregue: sim)*
- **Evidência:** 20.000 chamados: 25 ms -> 16 ms (1 statement nos dois casos; muda o que trafega: ~13 mil linhas -> 1).

### F22 — rotuloPrioridade com quatro níveis de if/else aninhados

- **Onde:** `code/lib.php:40-59`
- **Mecanismo:** Quatro níveis de aninhamento e cinco returns escondem uma regra simples: sem 1ª resposta -> 'Aguardando 1a resposta'; prioridade < 3 -> 'Normal'; prioridade >= 3 com até 30 min -> 'Alto - dentro do SLA'; acima de 30 min -> 'CRITICO - SLA estourado' (prioridade 4) ou 'Alto - atrasado'. O 30 e os códigos 3/4 são números mágicos.
- **Impacto e severidade:** Difícil de ler e de alterar sem erro, numa função cuja saída o relatório gerencial consome. **Severidade atribuída: baixa** (qualidade).
- **Confiança:** 85/100 — O aninhamento é um fato; é defeito de legibilidade, não funcional (a relevância está na severidade baixa).
- **O que fiz:** Guard clauses com a mesma tabela-verdade (verificada exaustivamente: prioridade -1..6 x minutos {null,-5,0,1,29,30,31,1000}); textos dos rótulos intactos. *(corrigido no código entregue: sim)*
- **Evidência:** Dump das 64 combinações idêntico ao do original; harness C-FMT (73 casos) passa.

### F23 — formatarStatus rotula como 'Resolvido' qualquer status fora de 1..3

- **Onde:** `code/lib.php:26-35`
- **Mecanismo:** A cadeia if/else-if termina num else que devolve 'Resolvido' para qualquer valor (0, 4, -1...). Um status novo (ex.: 4 = cancelado) apareceria como 'Resolvido' sem aviso. A coluna é TINYINT sem CHECK, então valores fora do domínio são possíveis.
- **Impacto e severidade:** Hoje nenhum dado do seed foge de 1..3, então o impacto é latente: classificação errada silenciosa se o domínio crescer. **Severidade atribuída: baixa** (qualidade).
- **Confiança:** 35/100 — Pode ser decisão de projeto, não defeito; por isso 35.
- **O que fiz:** Reestruturei em tabela de rótulos (mesma saída) mas MANTIVE o fallback para 'Resolvido' de propósito: o manifesto trata os rótulos como contrato e o relatório gerencial os casa por texto. Decisão de produto pendente: qual rótulo dar a status desconhecido. *(corrigido no código entregue: não)*
- **Evidência:** Harness C-FMT: formatarStatus(-1,0,1,2,3,4,99,INT_MAX,INT_MIN) idêntico ao original.

### F24 — rotuloPrioridade não destaca chamado crítico que ainda não teve 1ª resposta

- **Onde:** `code/lib.php:56-58` (ramo `$minutos === null`)
- **Mecanismo:** O ramo sem resposta devolve 'Aguardando 1a resposta' para qualquer prioridade: o chamado 104 (prioridade 4, 'Fatura em duplicidade', sem resposta) aparece como 'Aguardando 1a resposta', enquanto um chamado crítico JÁ respondido em mais de 30 min aparece como 'CRITICO - SLA estourado'. O pior caso de SLA (crítico sem resposta) é o menos destacado. A função não conhece o tempo decorrido desde criado_em, então não consegue saber se o SLA estourou.
- **Impacto e severidade:** Possível subnotificação de chamados críticos na fila, se a regra não for intencional. **Severidade atribuída: baixa** (bug).
- **Confiança:** 30/100 — Pode ser intencional (sem minutos_resposta não há como saber se estourou); por isso 30.
- **O que fiz:** NÃO alterei: é regra de negócio com saída caracterizada e consumida pelo relatório gerencial, e corrigi-la exigiria passar criado_em (mudança de assinatura). Proposta: decidir com o produto se prioridade 4 sem resposta deve ganhar destaque. *(corrigido no código entregue: não)*
- **Evidência:** Harness C-ROWS/C-FMT: (4, null) -> 'Aguardando 1a resposta' no original e no corrigido.

### F25 — Sessão sem logout nem expiração absoluta; uid e papel ficam congelados até a sessão acabar

- **Onde:** `code/index.php:24-25` (grava uid/papel) e `code/index.php:38-39` (só relê a sessão)
- **Mecanismo:** O login grava uid e papel na sessão e as linhas 38-39 só releem a sessão, nunca a tabela usuarios. Não há rota de logout, o cookie é de sessão do navegador e o gc_maxlifetime (1440 s) conta inatividade: quem usa o painel continuamente nunca expira. Técnico rebaixado para cliente, ou com a senha trocada, continua com acesso total até encerrar a sessão - e agora que a visibilidade depende de `$papel` isso passa a importar.
- **Impacto e severidade:** Janela de acesso indevido após mudança de papel/senha ou em computador compartilhado. **Severidade atribuída: baixa** (segurança).
- **Confiança:** 60/100 — A ausência de logout é certa; o impacto depende do uso real. Prática comum em apps legados.
- **O que fiz:** NÃO corrigi: logout é rota nova (fora do contrato de rotas e do HTML) e revalidar o papel a cada requisição acrescenta uma query por request e um comportamento novo. Recomendo: rota de logout, revalidação do papel por id (1 query na chave primária) e expiração absoluta. *(corrigido no código entregue: não)*
- **Evidência:** Leitura do código e do php.ini efetivo (gc_maxlifetime=1440, cookie_lifetime=0); sem experimento de produção.

### F26 — Listagem sem paginação e busca com LIKE '%termo%' (varredura completa)

- **Onde:** `code/lib.php:78-85` e `code/index.php:73,88-96`
- **Mecanismo:** A listagem devolve e renderiza TODOS os chamados (sem LIMIT) e a busca usa LIKE com % no início, que não usa índice. Cada abertura do painel é O(tabela).
- **Impacto e severidade:** Páginas e consultas cada vez maiores conforme o histórico cresce. **Severidade atribuída: baixa** (performance).
- **Confiança:** 60/100 — Depende do volume real de chamados, que não conheço.
- **O que fiz:** NÃO corrigi: paginação mudaria o HTML/contrato da listagem (consumidores esperam a tabela completa em #tabela-chamados) e um índice FULLTEXT mudaria a semântica da busca por substring. Registrado para uma evolução combinada com os consumidores. *(corrigido no código entregue: não)*
- **Evidência:** 20.000 chamados: a listagem inteira tem 3,0 MB de HTML numa só página; EXPLAIN da busca por `LIKE '%roteador%'` = type ALL (~19,5 mil linhas lidas) + filesort.

## 3. Decisões — o que deliberadamente não mudei

- **Assinaturas e retornos das 7 funções públicas, rotas e parâmetros (busca, ver, export), HTML da listagem (#tabela-chamados, colunas, links), formato do CSV e rótulos de status.** São o contrato do manifesto. Corrigi por baixo (JOIN, bind, streaming) e por cima (funções novas e filtros no `index.php`). As 7 linhas de declaração são idênticas byte a byte; o dump de funções, o HTML/CSV do técnico e o corpo das respostas HTTP são idênticos aos do original (os cabeçalhos também, exceto Set-Cookie, que muda por desenho: F8).
- **Camada de acesso a dados (mysqli) e a stack (PHP puro + MySQL).** Trocar por PDO/ORM mudaria as assinaturas (mysqli `$db`) que o manifesto fixa. Nenhuma dependência nova foi adicionada; o código usa só sintaxe e funções disponíveis desde o PHP 7.1.
- **A regra de visibilidade ficou no `index.php` + funções novas, e NÃO dentro de listarChamados/verChamado/exportarCsv.** Essas funções são chamadas pela rotina noturna e pelo relatório gerencial, que precisam de todos os chamados e não têm usuário de sessão. Dar a elas um parâmetro novo, mesmo opcional, mudaria a assinatura pública. Criei listarChamadosDoUsuario, exportarCsvWeb, veTodosOsChamados e podeVerChamado e deixei as originais como estavam. Os auxiliares internos levam o prefixo `painel` para não colidir com nomes de scripts externos.
- **Os valores devolvidos por `listarChamados()` continuam sendo strings (ou null).** O `query()` legado devolvia tudo como string numa conexão padrão; prepared statements devolvem int. Normalizei de volta para que comparações com === e json_encode feitos por consumidores externos não mudem. Limite conhecido: quem abre a conexão com `MYSQLI_OPT_INT_AND_FLOAT_NATIVE` recebia inteiros do original e agora recebe strings em listarChamados (verChamado e mediaResposta seguem como eram).
- **`exportarCsv()` deixou de gravar `EXPORT_DIR`/chamados.csv.** O manifesto define só 'escreve o CSV na saída'; o arquivo era um efeito colateral não declarado e a causa de F11/F12. Se algum script externo lia esse arquivo do disco, ele precisa passar a capturar a saída (ob_start). É o ponto de maior risco de compatibilidade fora do manifesto (os demais estão nas decisões seguintes), por isso está destacado aqui e na Implantação (passo 2). Não restaurei a gravação: reabriria F11/F12.
- **A neutralização de fórmula e o escape RFC 4180 valem só no download web (exportarCsvWeb), não em `exportarCsv()`.** `exportarCsv()` é função pública consumida por máquina (rotina noturna, faturamento): os bytes do CSV continuam idênticos aos do original para qualquer título. Injeção de fórmula é problema de quem abre o arquivo numa planilha, ou seja, do download pela web. Efeito no download web: títulos iniciados por = + - @ ganham apóstrofo e títulos com barra invertida mudam de aspas (leia com `fgetcsv($h, 0, ',', '"', '')`).
- **Rótulo 'Resolvido' para qualquer status fora de 1..3 em formatarStatus (F23).** O manifesto só define 1, 2 e 3 e diz que o relatório gerencial casa por texto. Mudar o fallback altera a saída para dados fora do domínio; é decisão de produto.
- **Regra de rotuloPrioridade: chamado crítico (prioridade 4) sem 1ª resposta aparece como 'Aguardando 1a resposta' (F24, ex.: o chamado 104).** Pode ser intencional (sem minutos_resposta não há como saber se o SLA estourou) e a saída está caracterizada. Só reestruturei o código (F22). Vale perguntar ao negócio se prioridade 4 sem resposta deveria ganhar destaque.
- **Curingas % e _ no termo de busca continuam valendo; a barra invertida agora é interpretada só pelo LIKE.** Curingas: é o comportamento atual (busca=% lista tudo) e escapá-los mudaria o resultado de links e favoritos; o único risco é custo de consulta do próprio usuário autenticado. Barra invertida: no original o termo passava por dois escapes (parser do literal SQL e depois o LIKE); com bind só o LIKE age. Termos com barra simples antes de letra comum, `\%` e `\_` se comportam como antes; sequências como `\n`, `\t`, `\r`, `\b`, `\Z`, `\0` e barra dupla casam diferente (e esse caminho era o da injeção). Não emulei o primeiro escape: seria perpetuar uma esquisitice do código injetável.
- **Paginação e índice para a busca por título (F26).** Paginar muda o HTML/contrato da listagem; um índice FULLTEXT muda a semântica de substring. Precisa ser combinado com os consumidores.
- **'Chamado nao encontrado.' continua com HTTP 200 e sem o link 'Voltar'.** Mantive status e corpo do original (e agora o mesmo corpo e status valem para o chamado alheio, o que evita enumeração de ids). Trocar por 404 é melhoria possível, mas não necessária e poderia afetar quem trata 200.
- **`mediaResposta()` continua global (média de todos os chamados, inclusive os que o cliente não vê).** É um indicador agregado de SLA que não identifica chamado nem cliente; filtrá-lo exigiria assinatura nova, mudaria o número mostrado no topo e, para clientes sem chamado respondido (bruno no seed), cairia no caso sem dados.
- **`tecnicoNome()` e `verChamado()` concatenam um inteiro tipado no SQL.** Analisei e testei (ver=1 OR 1=1, ver=101';DROP..., valores gigantes): o parâmetro é int/?int e o `index.php` ainda faz o cast; um inteiro não carrega aspas nem SQL, então não é injetável (o ponto injetável era a string de busca, F1). Por isso não os reescrevi. `tecnicoNome()` ficou sem uso depois do JOIN (F13), mas é função do arquivo e foi mantida.
- **Ids impressos sem escape no HTML (`index.php:59`,90) e ordem dos empates de criado_em.** O id vem de coluna INT AUTO_INCREMENT; com a injeção fechada (F1) nenhum valor não numérico chega ali. A ordem em empates de criado_em segue indefinida como no original; o JOIN a preservou (testado com empates), e acrescentar desempate mudaria a ordem observável.
- **SELECT * em listarChamados e verChamado.** O retorno inclui todas as colunas (descricao, usuario_id, ...) e consumidores externos podem lê-las; só o CSV, que tem formato fixo, passou a selecionar as 5 colunas de que precisa.
- **exportarCsv continua emitindo os cabeçalhos HTTP de dentro da biblioteca.** Separar HTTP de dados é melhor desenho, mas tiraria o Content-Disposition de quem chama exportarCsv direto (relatório/rotina); em CLI os `header()` são inócuos.
- **Tratamento de erro de SQL do mysqli (modo exceção do PHP >= 8.1 x `MYSQLI_REPORT_OFF`).** Não normalizei com `mysqli_report()` nem acrescentei checagem de retorno a cada consulta: seria reescrever o tratamento de erros da biblioteca. Em `MYSQLI_REPORT_OFF` uma consulta que falha vira 'Call to a member function ... on bool', como no original; só o caminho de conexão (F17) e o export (que no original voltava em silêncio e agora registra o motivo e responde 500) foram tratados.
- **Senhas com mais de 72 bytes não são migradas para bcrypt.** O bcrypt só enxerga os 72 primeiros bytes: migrar enfraqueceria essas contas (qualquer entrada com o mesmo prefixo passaria). Elas ficam em md5 até a troca de senha.
- **As falhas de login gastam o tempo de um bcrypt (~0,3 s).** Decisão de segurança: sem isso o tempo de resposta revelaria quais logins existem e já migraram (inclui contas travadas com hash irreconhecível e senhas com NUL). Vale para entradas de tamanho normal e hashes no custo atual do PHP (limites em F7). O custo é CPU por tentativa, o que torna o limite de tentativas (F14) pré-requisito de produção.
- **CSRF no login, logout, expiração de sessão (F25), captcha, HTTPS/HSTS, CSP/X-Frame-Options/nosniff e X-Powered-By.** Exigem rotas/estado novos (fora de 'evoluir sem reescrever') ou dependem da configuração do servidor web. Ficam como recomendação (o limite de tentativas está em F14).
- **Contas dormentes continuam em MD5 e as credenciais vazadas continuam válidas até serem trocadas.** A migração em massa é decisão operacional: o código só reconhece md5 puro (32 hex) ou `password_hash()` da senha, então um hash bcrypt(md5(senha)) NÃO é reconhecido e trancaria as contas. Opções seguras: esperar o login, forçar troca de senha/reset, ou estender `autenticar()` (fora desta entrega). A rotação da senha do banco e da chave SMTP também é operacional e URGENTE: os segredos podem estar em backups e no histórico do código.
- **A migração de senha é de mão única para o código antigo.** Depois que a coluna é ampliada e as contas logam, voltar ao código original tranca as contas já migradas. Por isso a Implantação separa o deploy do código (reversível) do ALTER (que liga a migração) e traz backup e roteiro de rollback.
- **`seed.sql`.** Mantido com MD5 de propósito: é o dado de teste do manifesto e exercita o caminho de migração no primeiro login. Não deve ser aplicado em produção (contas conhecidas, inclusive de técnico).
- **As constantes `EXPORT_DIR` e `SMTP_API_KEY` continuam definidas em `config.php`.** Scripts externos podem referenciá-las; removê-las quebraria na carga. Só deixaram de carregar segredo ou de ser usadas pelo export.

## 4. Implantação

A mudança é de código + uma coluna, mas há **passos operacionais obrigatórios**. Ordem recomendada (o deploy do código é reversível; a migração de senhas só liga no passo 7):

1. **Inventariar quem usa `config.php`/`lib.php`** (painel web, rotina noturna de exportação, relatório gerencial, faturamento, notificações) e **provisionar `DB_PASS` e `SMTP_API_KEY` no ambiente de CADA um, com os valores ATUAIS**:
   php-fpm `env[...]` (atenção a `clear_env`), Apache `SetEnv` e, para cron/CLI, o `crontab` ou o `EnvironmentFile` do timer systemd - o ambiente do php-fpm não vale para eles. Valide com
   `env -i ... php script.php` **antes** de publicar. Sem `DB_PASS` a conexão é negada (de propósito: `Falha ao conectar ao banco.`, HTTP 500, motivo no log); se hoje a produção depende do fallback embutido,
   este passo é **pré-requisito do deploy**. Atenção: o `config.php` ANTIGO ignora o ambiente para `SMTP_API_KEY` (usa o literal), então a chave só passa a vir do ambiente depois do deploy.
2. **Verificar se algum script lê `/var/www/painel/tmp/chamados.csv`** (rotina noturna, relatório). O export deixou de gravar esse arquivo; se algum consumidor o lê do disco, migre-o para capturar a saída de
   `exportarCsv()` com `ob_start()` **antes** do deploy e só então apague o resíduo.
3. **Verificar se outro sistema valida senha direto em `usuarios.senha`** (comparando md5): o passo 7 quebraria esse fluxo para as contas migradas.
4. **Deploy do código** (`config.php`, `lib.php`, `index.php`; `schema.sql` só para instalações novas) **sem** o `ALTER`. Nesta fase o código funciona e **não migra hashes**: voltar ao código anterior não tranca ninguém.
   Sessões abertas antes do deploy continuam válidas e só ganham as flags de cookie novas no próximo login; consumidores HTTP scriptados devem usar o cookie devolvido no 302 do login (o ID é regenerado).
   Defina no `php.ini`/pool: `session.auto_start=0`, `session.use_strict_mode=1`, `session.cookie_httponly=1`, `session.cookie_samesite=Lax` e, se o TLS termina no balanceador, `session.cookie_secure=1`.
   **Não** publique `schema.sql`/`seed.sql` dentro do docroot (o servidor embutido do PHP os serve como `application/x-sql`; bloqueie `*.sql` no servidor web) e **não** aplique `seed.sql` em produção.
5. **Rotacionar** a senha do usuário `painel` do banco e a chave SMTP, **junto com a troca do valor no ambiente de todos** (passo 1): os valores antigos podem estar em backups e no histórico do código e devem ser tratados como vazados.
   Entre trocar a senha no banco e trocar o valor no ambiente os scripts ficam sem conexão: faça as duas coisas numa janela curta e coordenada. No MySQL >= 8.0.14,
   `ALTER USER 'painel'@'host' IDENTIFIED BY '<nova>' RETAIN CURRENT PASSWORD;` mantém as duas senhas válidas durante a troca (depois `DISCARD OLD PASSWORD`); no MariaDB isso não existe.
   Um rollback do código para a versão antiga restauraria o literal da chave SMTP antiga (já revogada).
6. **Backup das senhas** antes de ligar a migração: `CREATE TABLE usuarios_senha_bak AS SELECT id, senha FROM usuarios;` (contém md5: guarde com cuidado e descarte quando a migração estiver validada).
7. **Ligar a migração**: `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;` O usuário do banco precisa de `UPDATE` em `usuarios.senha` (sem isso o login funciona e o erro vai para o log).
   A partir daí cada conta migra no primeiro login. **Rollback** (só depois deste passo): volte **primeiro** o código antigo (ou pause os logins) e só então rode
   `UPDATE usuarios u JOIN usuarios_senha_bak b ON b.id = u.id SET u.senha = b.senha;` (idempotente; senhas trocadas depois do backup também voltam ao valor antigo). Com o código novo ainda no ar, o próximo login migra a conta de novo.
8. **Limite de tentativas de login** no proxy/WAF/fail2ban, por IP ou pelo id da conta resolvida (a collation do banco faz 'ANA', 'ána' e 'ana ' autenticarem como 'ana'): com o bcrypt cada tentativa custa ~0,3 s de CPU,
   então isso deixa de ser opcional (F14). Considere também reduzir `post_max_size`/`LimitRequestBody` na rota de login.
9. Produção: `display_errors=Off` e `expose_php=Off`; se possível, usuário do banco só com `SELECT` em `chamados` e `usuarios` + `UPDATE` em `usuarios.senha`.
10. (Opcional) contas que nunca logam: o código só reconhece md5 puro ou `password_hash()` da senha (um hash `bcrypt(md5(senha))` NÃO é reconhecido e trancaria a conta); use troca de senha forçada ou estenda `autenticar()`.

## 5. Como verifiquei

**Ambiente.** PHP 8.4 (CLI e servidor embutido) + MariaDB 11.8 numa instância privada carregada com `schema.sql` e `seed.sql`. O alvo declarado é MySQL 8: não pude testar nele (ver limitações).
Cada defeito executável foi reproduzido **no original** antes de ser corrigido (os de qualidade e arquitetura, F22-F26, foram caracterizados por leitura do código e, quando cabia, por EXPLAIN); o original pristino ficou guardado para comparação.

**Auditoria independente.** Seis auditores (injeção/saída, acesso/sessão/segredos, bugs de execução, performance, arquitetura/qualidade e "guarda do contrato") reportaram 63 candidatos, consolidados em 30;
cada um foi verificado por um reprodutor e por um cético adversarial, e um crítico de completude mapeou o código linha a linha. Todos os achados que eu já tinha foram reencontrados de forma independente e a verificação os confirmou
(alguns com severidade ou confiança menores, que usei para calibrar); incorporei o que eu não tinha visto (tratamento de valores "falsos" em `getenv`, lock de sessão, acoplamento de `exportarCsv` a `EXPORT_DIR`, recomendações de implantação).

**Revisão adversarial do resultado, em duas rodadas.** Com o código e este relatório prontos, cinco revisores independentes (guardião do contrato, atacante, revisor de código, auditor dos artefatos e auditor de escopo) tentaram quebrá-los; cada problema
relatado passou por um cético que tentou refutá-lo. Resultado: **nenhuma violação do manifesto e nenhum bypass de autorização** (35 formas de `?ver=`, 1.764 requisições aleatórias sem nenhum 5xx, 220 chamadas de função com dados hostis idênticas ao original)
e ~20 problemas menores distintos (45 confirmações), **todos tratados**. No código: o `;` dentro de comentário do `schema.sql` (quebrava carregadores que dividem por `;`), a neutralização de fórmula alterando a função pública `exportarCsv()` (agora só no download web),
o escape do CSV (barra+aspas), o tempo das falhas de login, o hash calculado antes de conferir a coluna, o limite de 72 bytes do bcrypt, a política de visibilidade duplicada, variáveis temporárias do `config.php`, o caminho de erro do export com `MYSQLI_REPORT_OFF`
e nomes de auxiliares sem prefixo. Uma **segunda rodada** (três revisores: regressão e contrato, veracidade dos artefatos e atacante, de novo com um cético por problema) **não achou regressão de código**; suas 21 confirmações - texto, implantação e bordas de baixa severidade -
também foram tratadas: ordem e rollback da Implantação (testados num banco), segredos totalmente omitidos, a sugestão de migração em massa que contradizia o código, afirmações mais fortes que a evidência, igualdade de tempo também para conta travada e senha com NUL,
e o limite do escape do CSV web para leitores PHP.

**Compatibilidade - original x corrigido nos mesmos cenários.**
- *Função:* um script único, executado contra os dois códigos (um banco para cada), imprime com `var_export` o retorno de `autenticar` (9 casos), `formatarStatus` (7), `rotuloPrioridade`
  (64 combinações), `listarChamados` (12 buscas, incluindo `%` e `_`), `verChamado` (9), `tecnicoNome` (5), `mediaResposta` e os bytes de `exportarCsv`: **694 linhas idênticas** (tipos, ordem das chaves e ordenação incluídos; a ordem em empates de `criado_em` também foi conferida).
  Os revisores repetiram com dados hostis (acentos, emoji, aspas, vírgulas, CR/LF, títulos iniciados por `= + - @`, técnico nulo/órfão, status fora do domínio, 78 títulos hostis no CSV): só divergem os payloads de injeção, os termos de busca com barra invertida (ver Decisões), a média sem dados e o download web do CSV (esperado).
- *HTTP (php -S):* 14 URLs (listagem, 4 buscas, `ver` de 101 a 105, inexistente, `abc`, vazio, CSV) para cada usuário do seed, mais página sem sessão, login errado e login certo.
  Técnicos, sem sessão e login: **31 de 31 respostas idênticas** - corpo e cabeçalhos, exceto `Date` (varia por natureza) e `Set-Cookie` (muda por desenho, F8); na minha comparação normalizei também os cabeçalhos de cache e versão, e os revisores a repetiram com cabeçalhos completos sem achar outra diferença.
  Clientes: as respostas só diferem onde o chamado é de outro cliente (comportamento corrigido); nos chamados próprios são idênticas ao original.
- *Harness de caracterização (golden master gravado do original por um agente independente, não por mim):* 13 testes de compatibilidade (assinaturas por Reflection, retornos, HTML, CSV, HTTP de técnicos e clientes), 14 de comportamento corrigido
  e 6 de robustez. Ele foi validado contra um oráculo de referência, 51 variantes sabotadas e 40 mutantes do original que quebram o contrato (40/40 detectados) **antes** de eu adaptar o teste de injeção de fórmula à separação `exportarCsv`/`exportarCsvWeb` (e acrescentar o caso barra+aspas);
  depois da adaptação reexecutei o original (COMPAT 13/13, FIX 1/14: só o que já passava), o oráculo (13/13, 14/14, ROBUST 6/6) e o código entregue; as sabotagens e os mutantes não foram reexecutados.
  **No código entregue: COMPAT 13/13 e FIX 14/14, com o schema novo e também com o schema ORIGINAL (`CHAR(32)`); ROBUST 5/6** (o que falha é o modo `MYSQLI_REPORT_OFF`, em que uma consulta que falha vira Error, como no original).
- As 7 assinaturas públicas (e `tecnicoNome`) são idênticas byte a byte; varredura de tokens não achou recurso posterior ao PHP 7.1 (`match`, `fn`, `?->`, `str_contains`, ...). `schema.sql` e `seed.sql` carregam também por um divisor ingênuo de `;`.

**Segurança (original -> corrigido).** UNION em `?busca=` extrai login e hash de todos os usuários -> 0 hashes; `OR SLEEP(1)` leva 5,0 s -> 0,005 s; `busca="><script>` volta crua -> escapada; `ana` lê `?ver=103` e baixa o CSV
inteiro -> recebe só o que abriu (e o 103 responde igual a um id inexistente); `busca[]=`, `login[]=` e `senha[]=` dão 500 -> 200; cookie plantado vira sessão autenticada -> o ID muda no login e o cookie sai com
`HttpOnly; SameSite=Lax`; sem `DB_PASS` ou com senha errada -> `Falha ao conectar ao banco.` (500). Migração de senha: login do seed -> hash `$2y$` (60 caracteres) e 2º login ok; com o schema ORIGINAL (`CHAR(32)`) o login funciona e o hash **não** é truncado.
Falhas de login (inexistente, md5 errado, bcrypt errado, conta travada, hash legado `$6$` errado, senha com NUL): todas entre ~270 e ~290 ms.

**Desempenho (20.000 chamados).** `listarChamados` 16.005 statements / 7,5 s -> 1 / 0,36 s; `exportarCsv` 16.005 / 8,4 s -> 1 / 0,23 s; página completa 6,8 s -> 0,45 s com o mesmo número de bytes; segunda requisição da mesma sessão 7,8 s -> 0,007 s.

**Limitações.** Não testei em MySQL 8 nem em PHP 7.x/8.1 (só 8.4; a ausência de recursos pós-7.1 foi verificada por varredura de tokens, não por execução; `SameSite` só tem efeito em PHP >= 7.3 e o escape RFC 4180 do CSV web em PHP >= 7.4; em PHP < 8.4 o custo do bcrypt é 10); a flag `Secure` do cookie só vale sob HTTPS (um revisor a exercitou simulando HTTPS);
não vi a configuração do servidor web real (relevante para F11) nem os scripts externos que consomem as funções (a compatibilidade foi medida contra o manifesto, não contra eles); a collation do login (case/acento-insensível) é do banco e pode diferir entre MariaDB e MySQL 8 em casos de borda.
