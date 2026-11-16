# Relatório técnico — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

O painel funciona, mas qualquer cliente logado conseguia ler o banco inteiro: a busca aceita SQL injetado (F1) e nenhuma das três telas aplica a regra "cliente só vê o que abriu" (F2–F4). Somam-se a isso XSS na busca, credenciais de produção no código, senhas em md5 sem salt e fixação de sessão. Fora da segurança, a listagem e o export fazem uma consulta por chamado, a página principal cai quando não há chamado respondido e exports simultâneos se corrompem.

São 24 achados: 19 corrigidos no código entregue e 5 apenas reportados (F20–F24), com o motivo em cada um.

A superfície do manifesto foi preservada. As sete funções públicas devolvem exatamente o mesmo que antes (valores e tipos), as páginas vistas por um técnico saem byte a byte iguais e o CSV é idêntico, inclusive nos cabeçalhos HTTP. A seção 6 descreve como isso foi verificado e o que não foi.

## 2. Antes de implantar

Quatro ações ficam fora do código e a primeira é bloqueante:

1. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente**, tanto do servidor web quanto do cron da rotina noturna. O fallback embutido foi removido (F6, F7). Sem `DB_PASS`, o painel responde HTTP 500 "Falha ao conectar ao banco." e o log registra `using password: NO`.
2. **Trocar as duas credenciais.** Elas estiveram no código-fonte e devem ser consideradas expostas; tirá-las do arquivo não as torna secretas de novo.
3. **Alargar a coluna de senha** para ativar a migração de md5 (F8): `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Enquanto isso não for feito, nada muda e o login continua em md5. Antes de rodar, confirmar que nenhum outro script compara `usuarios.senha` com md5 diretamente em vez de chamar `autenticar()`.
4. **Apagar `/var/www/painel/tmp/chamados.csv`** (F12). O export não grava mais esse arquivo; se alguma rotina o lia do disco, ela passa a ver dado velho e deve capturar a saída de `exportarCsv()`.

## 3. Achados

Em ordem de prioridade de correção. As linhas são da numeração original.

| ID | Achado | Categoria | Severidade | Confiança | Corrigido |
| --- | --- | --- | --- | --- | --- |
| F1 | Injeção de SQL na busca | segurança | crítica | 99 | sim |
| F2 | Detalhe do chamado sem checar o dono (IDOR) | segurança | alta | 98 | sim |
| F3 | Listagem mostra chamados de todos os clientes | segurança | alta | 97 | sim |
| F4 | Export CSV entrega todos os chamados a qualquer usuário | segurança | alta | 96 | sim |
| F5 | XSS refletido no parâmetro `busca` | segurança | alta | 98 | sim |
| F6 | Senha do banco de produção embutida no código | segurança | alta | 95 | sim |
| F7 | Chave da API de e-mail embutida no código | segurança | alta | 92 | sim |
| F8 | Senhas em md5 sem salt | segurança | alta | 96 | sim (ativa com o `ALTER`) |
| F9 | Fixação de sessão e cookie sem proteção | segurança | média | 90 | sim |
| F10 | Divisão por zero derruba a listagem | bug | média | 97 | sim |
| F11 | Exports simultâneos se corrompem (arquivo compartilhado) | bug | média | 92 | sim |
| F12 | Cópia do export fica em diretório sob a raiz web | segurança | média | 55 | sim |
| F13 | Uma consulta por chamado na listagem e no export (N+1) | performance | média | 98 | sim |
| F14 | Tratamento de falha de conexão não executa no PHP ≥ 8.1 | bug | baixa | 75 | sim |
| F15 | Parâmetro em formato de array derruba a página | bug | baixa | 95 | sim |
| F16 | Export falha em silêncio e deixa arquivo aberto | bug | baixa | 85 | sim |
| F17 | `fputcsv` sem `$escape` suja o CSV no PHP 8.4 | bug | baixa | 70 | sim |
| F18 | Média calculada no PHP trazendo todas as linhas | performance | baixa | 70 | sim |
| F19 | `rotuloPrioridade` com quatro níveis de `if` aninhado | qualidade | baixa | 60 | sim |
| F20 | Login sem limite de tentativas | segurança | média | 80 | não |
| F21 | Fórmulas no CSV (CSV injection) | segurança | baixa | 65 | não |
| F22 | Sem logout; papel fica congelado na sessão | segurança | baixa | 70 | não |
| F23 | Listagem sem paginação e busca sem índice | performance | baixa | 70 | não |
| F24 | `index.php` concentra login, rotas, consulta e HTML | arquitetura | média | 75 | não |

### F1 — Injeção de SQL na busca

- **Onde:** `code/lib.php:80-85` (a concatenação é a linha 82), alcançada por `index.php?busca=` em `code/index.php:72-73`.
- **Mecanismo:** `$_GET['busca']` chega sem tratamento a `listarChamados()` e é colado dentro do literal `LIKE '%…%'`. Um apóstrofo fecha o literal e o resto vira SQL. Reproduzi no código original, logada como a cliente `ana`: `busca=zzz' UNION SELECT id,1,NULL,CONCAT(login,':',senha),'x',1,1,NULL,NOW() FROM usuarios -- ` devolve login e hash de todos os usuários na coluna Titulo. O mesmo defeito faz uma busca legítima com apóstrofo (`d'agua`) terminar em erro fatal de sintaxe SQL.
- **Impacto e severidade:** qualquer usuário logado lê tudo o que o usuário `painel` do banco alcança, incluindo os hashes de senha (que, por F8, viram senha em segundos). **Crítica.**
- **Confiança:** 99.
- **O que fiz:** a consulta passou a ser preparada, com o termo ligado como parâmetro (`executarConsulta()`). Consulta preparada devolve `INT` como `int`, enquanto `mysqli::query()` devolvia tudo como string; como outros scripts recebem essas linhas, converto de volta para o formato antigo. Os curingas `%` e `_` continuam valendo na busca, como antes (ver Decisões).

### F2 — Detalhe do chamado sem checar o dono (IDOR)

- **Onde:** `code/index.php:52-58`, que chama `verChamado()` (`code/lib.php:98-102`) só com o id.
- **Mecanismo:** nem a rota nem a função comparam `chamados.usuario_id` com o usuário da sessão. O papel é lido em `index.php:39` e nunca mais usado. Reproduzi: logada como `ana`, `index.php?ver=103` mostra título e descrição do chamado de `bruno`. Os ids são sequenciais, então basta contar.
- **Impacto e severidade:** um cliente lê qualquer chamado de qualquer outro cliente, o que viola a regra de visibilidade do manifesto. **Alta.**
- **Confiança:** 98.
- **O que fiz:** a regra passou a existir em um só lugar, `donoVisivel($uid, $papel)`, que devolve `null` para técnico (vê tudo) e o próprio id para qualquer outro papel. A rota usa `verChamadoVisivel()`. Chamado de outro dono responde a mesma página de "Chamado nao encontrado." de um id inexistente, para não confirmar que o id existe. `verChamado()` ficou como estava.

### F3 — Listagem mostra chamados de todos os clientes

- **Onde:** `code/index.php:72-73`, sobre `listarChamados()` (`code/lib.php:78-93`).
- **Mecanismo:** a consulta é `SELECT * FROM chamados` sem filtro por `usuario_id`, e a tela imprime o que vier. A cliente `ana` via os cinco chamados do seed, incluindo 103 e 104, que são de `bruno`.
- **Impacto e severidade:** mesma violação de F2, sem precisar adivinhar id. **Alta.**
- **Confiança:** 97.
- **O que fiz:** a tela usa `listarChamadosVisiveis($db, $dono, $busca)`, que filtra no SQL (`c.usuario_id = ?`, coberto pelo índice `idx_usuario`). `listarChamados()` mantém a assinatura e continua devolvendo todos, porque é assim que os scripts internos a usam.

### F4 — Export CSV entrega todos os chamados a qualquer usuário

- **Onde:** `code/index.php:44-47`, sobre `exportarCsv()` (`code/lib.php:123-151`, consulta na linha 132).
- **Mecanismo:** a rota só exige estar logado e a consulta é `SELECT * FROM chamados ORDER BY id`. O CSV baixado por `ana` trazia os cinco chamados.
- **Impacto e severidade:** é a forma mais barata de F3: um clique entrega a base toda. **Alta.**
- **Confiança:** 96.
- **O que fiz:** a rota usa `exportarCsvVisivel($db, $dono)`. O cliente recebe o CSV dos próprios chamados; o técnico, o de todos. Formato, ordenação e cabeçalhos HTTP não mudaram. `exportarCsv($db)` continua exportando tudo para a rotina noturna.

### F5 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79` (atributo `value`) e `code/index.php:82` ("Resultados para:").
- **Mecanismo:** `$busca` é ecoado sem escape nos dois pontos. `index.php?busca="><script>…</script>` fecha o atributo e executa o script no navegador de quem abrir o link. Reproduzi no original.
- **Impacto e severidade:** um link enviado a um técnico logado executa código na sessão dele, que enxerga todos os chamados; como o cookie não era `HttpOnly` (F9), o script também podia levar a sessão. **Alta.**
- **Confiança:** 98.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos.

### F6 — Senha do banco de produção embutida no código

- **Onde:** `code/config.php:11-12`.
- **Mecanismo:** quando `DB_PASS` não está no ambiente, vale um literal que o próprio comentário identifica como a senha de produção. Quem tiver acesso ao repositório, a um backup ou a uma cópia antiga do deploy tem a credencial do banco.
- **Impacto e severidade:** acesso direto de leitura e escrita aos dados, sem passar pelo painel. **Alta.**
- **Confiança:** 95.
- **O que fiz:** removi o fallback; a senha vem só de `DB_PASS`. Isso exige as ações 1 e 2 da seção 2.

### F7 — Chave da API de e-mail embutida no código

- **Onde:** `code/config.php:14-15`.
- **Mecanismo:** `SMTP_API_KEY` é um literal, sem nem a alternativa de ambiente que as outras configurações têm. Nenhum arquivo de `code/` a usa, então ela existe para outros scripts.
- **Impacto e severidade:** quem lê o código pode enviar e-mail em nome do ISP pela central transacional (phishing aos clientes). **Alta.**
- **Confiança:** 92.
- **O que fiz:** a constante passou a vir de `getenv('SMTP_API_KEY')`. Ela continua definida (vazia, se a variável faltar) para que um script que a referencie falhe na autenticação do envio em vez de morrer com constante indefinida.

### F8 — Senhas em md5 sem salt

- **Onde:** `code/lib.php:15-16` e a coluna `senha CHAR(32)` em `code/schema.sql:7`.
- **Mecanismo:** `autenticar()` calcula `md5($senha)` e compara no SQL. md5 é rápido e não há salt: os dois clientes do seed, que têm a mesma senha, ficam com o mesmo hash, e senhas comuns saem de tabelas prontas. Com F1, um cliente obtinha os hashes dos técnicos.
- **Impacto e severidade:** qualquer vazamento da tabela equivale a vazar as senhas. **Alta.**
- **Confiança:** 96.
- **O que fiz:** `autenticar()` aceita os dois formatos. Hash de `password_hash()` é conferido com `password_verify()`; md5 legado continua aceito, com `hash_equals()`, e é regravado com `password_hash()` no primeiro login correto (`regravarSenha()`). A regravação só acontece se a coluna comportar o hash: gravar 60 caracteres em `CHAR(32)` cortaria o valor e trancaria o usuário para fora. Por isso `schema.sql` passou a `VARCHAR(255)` e o banco existente precisa do `ALTER` da seção 2. Assinatura e retorno (`id` inteiro, `nome`, `papel`) não mudaram. Uma tentativa que falha gasta o tempo de um hash lento, para a demora não revelar se o login existe.
- **O que fica:** quem nunca mais fizer login permanece em md5. Depois de um prazo, vale forçar a redefinição dessas contas.

### F9 — Fixação de sessão e cookie sem proteção

- **Onde:** `code/index.php:23-27` (login) e `code/index.php:15` (`session_start()`).
- **Mecanismo:** o PHP aceita um id de sessão escolhido pelo cliente e o login não troca o id. Reproduzi: um id plantado antes do login continuava valendo depois dele, e quem o plantou via a listagem da vítima. O cookie também saía sem `HttpOnly` e sem `SameSite`.
- **Impacto e severidade:** quem consegue plantar um cookie no navegador da vítima herda a sessão dela. **Média.**
- **Confiança:** 90.
- **O que fiz:** `session_regenerate_id(true)` no login, `session.use_strict_mode`, `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição já é HTTPS. Usei `Lax` e não `Strict` porque o manifesto cita links e favoritos externos, que com `Strict` abririam o painel deslogado.

### F10 — Divisão por zero derruba a listagem

- **Onde:** `code/lib.php:116`, chamada em toda listagem por `code/index.php:74`.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta`, `$qtd` fica em 0 e `$soma / $qtd` lança `DivisionByZeroError` no PHP 8 (no PHP 7, aviso e `NAN`). Reproduzi com a tabela de chamados vazia: a página principal vira erro fatal para todos os usuários.
- **Impacto e severidade:** o painel fica fora do ar numa base nova ou sem chamados respondidos. **Média**, porque depende desse estado.
- **Confiança:** 97.
- **O que fiz:** sem chamado respondido, `mediaResposta()` devolve `0.0`.

### F11 — Exports simultâneos se corrompem

- **Onde:** `code/lib.php:125-150`.
- **Mecanismo:** todo export grava no mesmo `EXPORT_DIR/chamados.csv` com `fopen('w')`, que trunca o arquivo, e só depois o devolve com `readfile()`. Um segundo export que comece enquanto o primeiro escreve apaga o que já estava gravado. Reproduzi com 20.005 chamados e dois exports sobrepostos: o primeiro download veio com 31.208 bytes nulos e 409 linhas a menos, sem erro algum. Ocorreu em uma de duas tentativas, porque depende do instante. O N+1 (F13) alarga a janela, já que o export levava segundos.
- **Impacto e severidade:** CSV corrompido entregue em silêncio, inclusive à rotina noturna se coincidir com um export manual. Com o filtro de F4, um arquivo compartilhado ainda misturaria dados de usuários diferentes. **Média.**
- **Confiança:** 92.
- **O que fiz:** o CSV é escrito direto na saída (`php://output`), sem arquivo intermediário. O conteúdo é byte a byte o mesmo.

### F12 — Cópia do export fica em diretório sob a raiz web

- **Onde:** `code/config.php:17-18` (`EXPORT_DIR`), usado em `code/lib.php:125-126`.
- **Mecanismo:** o arquivo de F11 nunca é apagado e fica em `/var/www/painel/tmp`. Se `/var/www/painel` for a raiz servida pelo servidor web, `GET /tmp/chamados.csv` entrega o último export sem login. O nome do caminho sugere isso, mas não tenho como confirmar a configuração do servidor a partir do pacote.
- **Impacto e severidade:** se confirmado, todos os chamados ficam públicos. **Média**, pela incerteza.
- **Confiança:** 55.
- **O que fiz:** o código não grava mais o arquivo. O que já está lá precisa ser apagado (seção 2). Mantive a constante `EXPORT_DIR`, que outros scripts podem usar.

### F13 — Uma consulta por chamado na listagem e no export (N+1)

- **Onde:** `code/lib.php:88-91` e `code/lib.php:137`, que chamam `tecnicoNome()` (`code/lib.php:64-72`) a cada linha.
- **Mecanismo:** para cada chamado com técnico, uma consulta extra busca o nome. Medi com 20.005 chamados: a listagem fazia 17.148 consultas em 3,6 s e o export, 4,9 s.
- **Impacto e severidade:** o tempo da página principal cresce com a base, e cada acesso ocupa o banco com milhares de consultas. **Média.**
- **Confiança:** 98.
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(u.nome, '-')`, que reproduz o `-` de chamado sem técnico. Na mesma base: 1 consulta, 0,31 s na listagem e 0,18 s no export, com o mesmo resultado. `tecnicoNome()` continua existindo para quem a chame de fora.

### F14 — Tratamento de falha de conexão não executa no PHP ≥ 8.1

- **Onde:** `code/index.php:9-12`.
- **Mecanismo:** desde o PHP 8.1, `new mysqli` lança exceção quando a conexão falha, então o `if ($db->connect_errno)` da linha 10 nunca roda. O usuário recebe o erro fatal cru ou uma página em branco, conforme `display_errors`. Em versões anteriores o `die` funciona, mas responde HTTP 200. Não sei a versão de produção, daí a confiança.
- **Impacto e severidade:** mensagem interna exposta ou página em branco numa queda do banco; monitoramento vê 200. **Baixa.**
- **Confiança:** 75.
- **O que fiz:** `try/catch` na conexão, mesma mensagem "Falha ao conectar ao banco.", agora com HTTP 500 e o detalhe no log. Um `set_exception_handler` dá o mesmo tratamento (mensagem genérica, detalhe no log) a qualquer outro erro não previsto.

### F15 — Parâmetro em formato de array derruba a página

- **Onde:** `code/index.php:21-22` e `code/index.php:72-73`.
- **Mecanismo:** `?busca[]=x` entrega um array a `listarChamados(string $busca)` e `login[]=x` faz o mesmo com `autenticar()`; os dois terminam em `TypeError` fatal. Reproduzi ambos.
- **Impacto e severidade:** erro fatal provocável por qualquer visitante, com caminho de arquivo na tela se `display_errors` estiver ligado. **Baixa.**
- **Confiança:** 95.
- **O que fiz:** valor que não é string é tratado como ausente.

### F16 — Export falha em silêncio e deixa arquivo aberto

- **Onde:** `code/lib.php:127-135`.
- **Mecanismo:** se o `fopen` falha (diretório inexistente ou sem permissão), a função retorna sem emitir nada: o navegador recebe 200 com corpo vazio e a rotina noturna "exporta" um arquivo vazio sem erro. Se a consulta falha no modo sem exceções (PHP < 8.1), o retorno da linha 134 pula o `fclose` e deixa em disco um arquivo só com o cabeçalho.
- **Impacto e severidade:** export vazio que parece sucesso. **Baixa.**
- **Confiança:** 85.
- **O que fiz:** sem arquivo, os dois caminhos deixam de existir. A consulta roda antes dos cabeçalhos HTTP e a falha vira exceção.

### F17 — `fputcsv` sem `$escape` suja o CSV no PHP 8.4

- **Onde:** `code/lib.php:130` e `code/lib.php:138-144`.
- **Mecanismo:** o PHP 8.4 emite um aviso de depreciação a cada `fputcsv` sem o parâmetro `$escape`. Com `display_errors` ligado, o texto do aviso entra na resposta antes do CSV e os `header()` seguintes falham. Reproduzi no PHP 8.4.26. Só afeta produção se ela estiver nessa versão.
- **Impacto e severidade:** download que vira HTML de avisos seguido do CSV. **Baixa.**
- **Confiança:** 70.
- **O que fiz:** passei separador, aspas e escape explicitamente, com os valores que sempre foram o padrão. A saída é a mesma.

### F18 — Média calculada no PHP trazendo todas as linhas

- **Onde:** `code/lib.php:109-115`.
- **Mecanismo:** a função traz uma linha por chamado respondido e soma em um laço, a cada carregamento da listagem.
- **Impacto e severidade:** tráfego e memória proporcionais à base para obter um número. **Baixa**: com 20 mil chamados em banco local não medi diferença de tempo (15 ms antes, 17 ms depois).
- **Confiança:** 70.
- **O que fiz:** `SUM` e `COUNT` no banco, com a divisão ainda no PHP para o valor ser exatamente o de antes. `AVG()` arredondaria em quatro casas.

### F19 — `rotuloPrioridade` com quatro níveis de `if` aninhado

- **Onde:** `code/lib.php:40-59`.
- **Mecanismo:** as cinco saídas ficam escondidas em quatro níveis de `if/else`, e é difícil ver qual condição leva a qual rótulo. A função está correta; o problema é de leitura e de risco em manutenção futura.
- **Impacto e severidade:** nenhum em produção hoje. **Baixa.**
- **Confiança:** 60.
- **O que fiz:** reescrevi com retornos antecipados, um por rótulo. Comparei com a original em 1.284 combinações de prioridade e minutos (incluindo `null` e valores fora da faixa): mesmos rótulos.

### F20 — Login sem limite de tentativas (não corrigido)

- **Onde:** `code/index.php:20-29`.
- **Mecanismo:** cada POST chama `autenticar()` sem contar falhas, atrasar ou bloquear. As senhas do seed mostram o padrão de senha que a base tolera.
- **Impacto e severidade:** adivinhação de senha por força bruta contra o formulário. **Média.**
- **Confiança:** 80.
- **Por que não fiz:** exige estado persistente (uma tabela de tentativas ou uma regra no proxy) e decisões de produto sobre bloquear por IP ou por login e por quanto tempo. Não quis criar tabela nem dependência por conta própria. A correção de F8 ajuda um pouco: cada tentativa errada passa a custar um hash lento (cerca de 0,3 s na máquina de teste).

### F21 — Fórmulas no CSV (não corrigido)

- **Onde:** `code/lib.php:138-144`.
- **Mecanismo:** `titulo` é texto escrito pelo cliente e vai sem alteração para a célula. Um título que começa com `=`, `+`, `-` ou `@` é interpretado como fórmula quando alguém abre o CSV numa planilha.
- **Impacto e severidade:** execução de fórmula na máquina de quem abre o export, dependendo dos avisos que a planilha exibe. **Baixa.**
- **Confiança:** 65.
- **Por que não fiz:** o remédio usual, prefixar a célula com `'`, altera o valor da coluna Titulo que consumidores automáticos leem, e quebraria o `-` que marca chamado sem técnico se aplicado à coluna errada. É uma mudança no conteúdo do CSV, que precisa ser combinada com quem o consome.

### F22 — Sem logout; papel fica congelado na sessão (não corrigido)

- **Onde:** `code/index.php:24-25`.
- **Mecanismo:** o papel é copiado para a sessão no login e nunca relido, e não existe rota de saída. Um técnico rebaixado ou um usuário removido continua com o acesso antigo enquanto a sessão durar, e ninguém consegue encerrar a própria sessão num computador compartilhado.
- **Impacto e severidade:** acesso que sobrevive à revogação pelo tempo de vida da sessão. **Baixa.**
- **Confiança:** 70.
- **Por que não fiz:** logout é uma rota nova, fora do manifesto, e reler o papel a cada requisição muda o custo de toda página. Os dois são pequenos, mas são funcionalidade, não correção; proponho como próximo passo.

### F23 — Listagem sem paginação e busca sem índice (não corrigido)

- **Onde:** `code/lib.php:80-84`.
- **Mecanismo:** a listagem traz todos os chamados com todas as colunas, incluindo `descricao`, que a tela não mostra. A busca usa `LIKE '%termo%'`, que não aproveita índice e varre a tabela.
- **Impacto e severidade:** depois de F13, é o que resta de custo proporcional à base: 0,31 s para 20 mil chamados no teste. **Baixa** hoje.
- **Confiança:** 70.
- **Por que não fiz:** paginar muda o que a rota de listagem devolve, e tirar colunas muda o que `listarChamados()` entrega a outros scripts. As duas coisas mexem no contrato.

### F24 — `index.php` concentra login, rotas, consulta e HTML (não corrigido)

- **Onde:** `code/index.php:1-97`.
- **Mecanismo:** um único script autentica, decide a rota, consulta e imprime HTML por concatenação. Não havia um ponto por onde toda requisição passasse para aplicar autorização, e foi isso que permitiu F2, F3 e F4: cada rota teria de lembrar da regra por conta própria, e nenhuma lembrou. O escape de saída também depende de cada `echo`, como F5 mostra.
- **Impacto e severidade:** cada rota nova tende a repetir os mesmos defeitos. **Média.**
- **Confiança:** 75.
- **Por que não fiz:** separar em camadas é reescrever o sistema, o que a tarefa proíbe. Fiz a parte que cabia: a regra de visibilidade ficou em uma função (`donoVisivel()`) e as três rotas passam por variantes que a recebem.

## 4. Decisões — o que não mudei e por quê

- **Assinaturas e retorno das sete funções públicas.** São o contrato. `listarChamados()`, `verChamado()` e `exportarCsv()` continuam sem saber quem é o usuário e devolvem tudo, porque a assinatura não tem usuário e a rotina noturna e o relatório gerencial dependem disso. A regra de visibilidade foi para as variantes `*Visivel`, usadas pelo `index.php`. Uma tela nova que atenda usuário logado deve usar as variantes.
- **`formatarStatus()` e o `else` que devolve "Resolvido".** Um status fora de 1–3 vira "Resolvido", o que parece errado. Não mexi: o relatório gerencial casa por esses três textos, e um quarto rótulo ("Desconhecido") é uma alteração de contrato. O `==` também fica; o parâmetro é `int` tipado e a comparação frouxa não tem efeito.
- **SQL com inteiro concatenado em `verChamado()` (`lib.php:100`) e `tecnicoNome()` (`lib.php:69`).** Parece injeção e não é: os parâmetros são `int` tipados, e o PHP recusa ou converte qualquer outra coisa antes de a consulta ser montada. Deixei como estava.
- **`$c['id']` impresso sem escape (`index.php:59` e `:90`).** Vem de coluna `INT`; não há como carregar HTML.
- **mysqli.** Mantido, como o manifesto exige. Não troquei por PDO.
- **Curingas na busca.** `%` e `_` digitados pelo usuário continuam funcionando como curinga, como no original. Escapá-los tornaria a busca literal, que é o mais correto, mas muda o resultado de buscas que hoje funcionam.
- **Média de resposta global.** O indicador no topo continua calculado sobre todos os chamados, também para clientes. É um agregado e não expõe chamado algum; restringi-lo mudaria o número que o cliente vê hoje.
- **Cabeçalho do CSV com aspas.** O manifesto mostra `ID,Titulo,Status,Tecnico,Aberto em`, mas o sistema em produção emite `"Aberto em"` entre aspas, porque `fputcsv` protege campos com espaço. Mantive os bytes de produção. Para quem lê como CSV, é o mesmo cabeçalho.
- **HTTP 200 em "Chamado nao encontrado."** O correto seria 404, mas é comportamento observável das rotas e não está no manifesto. Não mudei.
- **Redirecionamento após o login.** Vai sempre para `index.php` e perde a rota pedida (um favorito para `?ver=101` cai na listagem). Um cliente HTTP que faça login pode depender desse destino.
- **Tela de login.** Continua sem mensagem de senha inválida e sem token contra CSRF. O primeiro é melhoria de uso; o segundo tem pouco valor num painel só de leitura.
- **`tecnicoNome()` e `EXPORT_DIR`.** O `lib.php` não usa mais nenhum dos dois, mas outros scripts podem usar. Remover é um risco sem ganho.
- **Ordenação da listagem.** `ORDER BY criado_em DESC` não tem critério de desempate. Acrescentar um mudaria a ordem em empates.
- **`declare(strict_types=1)`.** Não adicionei: quem chama as funções de fora com string numérica passaria a receber `TypeError`.
- **`seed.sql`.** Inalterado. As senhas em md5 do seed são justamente o caso que a migração de F8 precisa cobrir.
- **Arquivos novos.** Não criei testes nem script de migração dentro de `code/`: a avaliação compara arquivo a arquivo. O `ALTER` necessário está em comentário no `schema.sql` e na seção 2.

## 5. Mudanças de comportamento que alguém pode notar

- Cliente deixa de ver chamados alheios na listagem, no detalhe e no CSV. É a regra do manifesto, mas é diferente do que acontecia.
- `DB_PASS` e `SMTP_API_KEY` passam a ser obrigatórias no ambiente.
- `exportarCsv()` não grava mais `EXPORT_DIR/chamados.csv`.
- Falha de conexão responde HTTP 500 (antes 200 ou erro fatal). Outros erros internos respondem 500 com "Erro interno. Tente novamente mais tarde.".
- Login com senha errada demora o tempo de um hash lento.
- O cookie de sessão ganha `HttpOnly` e `SameSite=Lax`, e o id muda no login.
- Depois do `ALTER`, `usuarios.senha` passa a guardar `password_hash()` à medida que os usuários fazem login.
- Busca com apóstrofo funciona (antes dava erro fatal).

## 6. Como verifiquei

Rodei o código original e o entregue lado a lado, em PHP 8.4.26 com uma instância descartável de MariaDB 11.8 carregada com `schema.sql` e `seed.sql`.

- **Funções públicas.** Comparei a saída das sete funções, com `var_dump` para pegar tipos: `formatarStatus` de -3 a 10, `rotuloPrioridade` em 1.284 combinações, `listarChamados` com 12 buscas, `verChamado`, `mediaResposta`, os bytes de `exportarCsv` e `autenticar` com 9 pares de login e senha. Idêntico nos três cenários: schema original, schema novo antes da migração de senhas e depois dela.
- **HTTP.** Com um técnico logado, listagem, busca, detalhe e CSV saem byte a byte iguais nos dois códigos, incluindo `Content-Type` e `Content-Disposition` do CSV. Com um cliente, conferi as três rotas contra a regra de visibilidade.
- **Defeitos.** F1, F2, F3, F4, F5, F9, F10, F11, F15 e F17 foram reproduzidos no original e deixaram de ocorrer no entregue. F13 foi medido.

O que não foi verificado: não executei em MySQL 8 nem em PHP 7.x. O código entregue usa só sintaxe e funções disponíveis desde o PHP 7.1, mas isso foi conferido por leitura. F12 depende da configuração do servidor web, que não está no pacote. F6 e F7 só se resolvem de fato com a troca das credenciais.
