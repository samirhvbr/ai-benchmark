# RELATORIO — LEB-100-A · Painel de Chamados (NetX ISP)

## 1. Resumo

O painel atende o caso feliz, mas não aplicava a regra de visibilidade do manifesto em nenhuma rota e tinha uma injeção de SQL na busca. Na prática, qualquer cliente autenticado lia os chamados de todos os outros clientes (listagem, detalhe e CSV) e conseguia extrair os hashes de senha de todos os usuários, que são MD5 sem sal. A senha do banco de produção e a chave do SMTP estavam no código-fonte.

Encontrei 22 problemas. Corrigi 19 no código entregue e deixei 3 só reportados (F15, F16 e F22); o motivo está em cada bloco.

A superfície do manifesto foi preservada: as 7 funções públicas têm a mesma assinatura e devolvem o mesmo retorno, as rotas e os nomes de parâmetro não mudaram, e o HTML e o CSV que um técnico recebe são idênticos byte a byte aos do original. `verChamado()`, `tecnicoNome()` e `seed.sql` não foram tocados. O arquivo mais alterado é `lib.php`.

### Antes do deploy

Quatro pontos dependem de quem opera o sistema. O primeiro derruba o painel se for esquecido.

1. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente** do servidor web e de todo script que inclui `config.php` (rotina noturna, relatório gerencial, faturamento). Sem `DB_PASS` o painel responde "Falha ao conectar ao banco." (HTTP 503). As duas credenciais devem ser trocadas, porque estiveram no código-fonte (F6).
2. **Rodar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`** para ativar a migração dos hashes (F7). O código funciona com ou sem o `ALTER`: enquanto a coluna for `CHAR(32)`, o MD5 continua valendo. Antes de rodar, confirmar que nenhum outro script compara `usuarios.senha` com MD5 por conta própria, e que o usuário do banco tem `UPDATE` em `usuarios`.
3. **Conferir `SELECT status, COUNT(*) FROM chamados GROUP BY status;`**. Linhas com status fora de 1–3 saíam como "Resolvido" e passam a sair como "Desconhecido" (F17).
4. **Apagar `/var/www/painel/tmp/chamados.csv`**. O export não grava mais esse arquivo (F9). Se algum processo o lia de lá, ele precisa passar a capturar a saída de `exportarCsv()`.

### Como verifiquei

Rodei o código original e o entregue lado a lado, em PHP 8.4.26, contra uma instância MariaDB 11.8 privada e descartável, carregada com `schema.sql` + `seed.sql`.

| Verificação | Resultado |
| --- | --- |
| Retorno das 7 funções públicas (72 combinações de `rotuloPrioridade`, 8 logins, 9 buscas, 4 detalhes, média, CSV), comparado com `var_export` | idêntico ao original, inclusive nos tipos; em banco não migrado e em banco migrado, antes e depois da regravação dos hashes |
| Páginas do técnico (login, listagem, busca, 3 detalhes, CSV e seus cabeçalhos) | idênticas byte a byte |
| CSV de 2005 chamados | idêntico byte a byte |
| Todas as rotas com `error_reporting=E_ALL` | nenhum aviso no log |
| Ataques de F1, F2, F3, F4, F5, F8, F10, F13, F14 e F18 | reproduzidos no original; não funcionam mais no entregue |

Não verifiquei: MySQL 8 propriamente dito (usei MariaDB), PHP anterior a 8.4 (o código entregue usa apenas recursos do PHP 7.3+, mas não tinha outro interpretador para rodar), a condição de corrida de F9 (deduzida do código, não reproduzida) e se `/var/www/painel/tmp` é servido pela web em produção.

Havia um servidor de banco já escutando em `127.0.0.1:3306` nesta máquina. Não me conectei a ele: não faz parte do pacote e a credencial embutida em `config.php` é de produção.

---

## 2. Achados

Na ordem em que eu priorizaria a correção. As linhas são da numeração original.

### F1 — SQL injection na busca da listagem

- **Onde:** `code/lib.php:81–83`, em `listarChamados()`.
- **Mecanismo:** `$busca` vem direto de `$_GET['busca']` (`index.php:72`) e é concatenado dentro do literal do `LIKE`. Com `busca=zzz%' UNION SELECT id,1,NULL,CONCAT(login,':',senha),'x',1,1,NULL,NOW() FROM usuarios -- ` a coluna "Titulo" da listagem passa a mostrar `login:hash` dos 4 usuários. Uma busca legítima com apóstrofo (`O'Brien`) gera erro de sintaxe SQL e HTTP 500.
- **Impacto e severidade:** **crítica**. Qualquer usuário autenticado, inclusive cliente, lê qualquer tabela que o usuário do banco alcança, a começar pelos hashes de senha (F7).
- **Confiança:** 99. Reproduzido: 4 hashes vazados no original, 0 no entregue.
- **O que fiz:** a consulta virou prepared statement (`LIKE ?`, com o termo ligado por `bind_param`). Como consultas preparadas devolvem inteiros como `int` e o `query()` anterior devolvia tudo como string, `linhaComoTexto()` converte de volta, para que quem consome `listarChamados()` receba exatamente o formato de antes.

### F2 — Cliente abre o detalhe de qualquer chamado (`?ver=<id>`)

- **Onde:** `code/index.php:52–53`; a consulta está em `lib.php:98–102`.
- **Mecanismo:** `index.php:38–39` lê `$uid` e `$papel` da sessão e nunca mais os usa. A rota passa o id para `verChamado()`, que carrega só pelo id. Ana (cliente) abre `?ver=103`, que é de Bruno, e lê título e descrição. Os ids são sequenciais, então basta incrementar.
- **Impacto e severidade:** **alta**. Viola a regra de visibilidade do manifesto; expõe dados de outros clientes.
- **Confiança:** 99. Reproduzido.
- **O que fiz:** a regra passou a existir em um lugar, `donoRestrito($uid, $papel)`: devolve `null` para técnico e o próprio id para qualquer outro papel. A rota usa `verChamadoDoDono()`, que responde "Chamado nao encontrado." para chamado alheio, igual a um id inexistente, para não confirmar que o id existe. `verChamado()` ficou intacta.

### F3 — Listagem mostra ao cliente os chamados de todos

- **Onde:** `code/index.php:72–73`; a consulta está em `lib.php:80–84`.
- **Mecanismo:** mesma causa de F2. `listarChamados($db, $busca)` não recebe o usuário e o `SELECT` não filtra por `usuario_id`. Ana e Bruno viam os 5 chamados do seed.
- **Impacto e severidade:** **alta**. Exposição em massa, sem esforço algum: é a página inicial.
- **Confiança:** 98. Reproduzido.
- **O que fiz:** a rota usa `listarChamadosDoDono($db, $dono, $busca)`, que acrescenta `c.usuario_id = ?`. Ana vê 101, 102 e 105; Bruno vê 103 e 104; o técnico continua vendo os 5. `listarChamados()` mantém assinatura e retorno e segue devolvendo tudo, para os scripts internos.

### F4 — CSV exporta todos os chamados para qualquer usuário autenticado

- **Onde:** `code/index.php:44–47`; a consulta está em `lib.php:132`.
- **Mecanismo:** mesma causa de F2. A rota chama `exportarCsv($db)`, que faz `SELECT * FROM chamados ORDER BY id` sem filtro. Um cliente baixa a base inteira com um clique em "Exportar CSV".
- **Impacto e severidade:** **alta**.
- **Confiança:** 97. Reproduzido.
- **O que fiz:** a rota usa `exportarCsvDoDono($db, $dono)`. O cliente recebe um CSV com o mesmo cabeçalho e a mesma ordenação, contendo só os chamados dele. Preferi isso a negar a rota, porque negar esconderia do cliente chamados que ele tem direito de ver. `exportarCsv($db)` continua exportando tudo.

### F5 — XSS refletido no campo de busca

- **Onde:** `code/index.php:79–82`.
- **Mecanismo:** `$busca` é ecoado cru no atributo `value` do input (linha 79) e no parágrafo "Resultados para:" (linha 82). `?busca="><script>alert(1)</script>` fecha o atributo e injeta o script. Os campos vindos do banco (título, descrição, técnico) já passavam por `htmlspecialchars`; só esses dois pontos ficaram de fora.
- **Impacto e severidade:** **alta**. Um link enviado a um técnico executa script na sessão dele, com acesso a todos os chamados. O cookie de sessão não tinha `HttpOnly` (F8), então o script também podia roubá-lo.
- **Confiança:** 99. Reproduzido.
- **O que fiz:** os dois pontos usam `htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

### F6 — Senha do banco de produção e chave do SMTP no código-fonte

- **Onde:** `code/config.php:11–15`.
- **Mecanismo:** `DB_PASS` tem como fallback a senha do usuário de produção (linha 12) e `SMTP_API_KEY` é um literal (linha 15). Quem tem o repositório, um backup do diretório web ou este pacote tem as duas credenciais.
- **Impacto e severidade:** **alta**. Acesso direto ao banco e envio de e-mail em nome do ISP.
- **Confiança:** 95.
- **O que fiz:** os dois valores vêm só do ambiente; sem a variável, a constante fica vazia. As constantes continuam definidas, porque outros scripts incluem `config.php`. Isso exige o item 1 de "Antes do deploy". Tirar o literal do código não desfaz o vazamento: as duas credenciais precisam ser trocadas.

### F7 — Senhas guardadas em MD5 sem sal

- **Onde:** `code/lib.php:15–16`; a coluna é `CHAR(32)` em `schema.sql:7`.
- **Mecanismo:** `autenticar()` calcula `md5($senha)` e compara no SQL. MD5 sem sal é quebrado por tabela pré-computada, e senhas iguais dão hashes iguais: no seed, `ana` e `bruno` têm o mesmo hash, assim como `carla` e `diego`.
- **Impacto e severidade:** **alta**. Qualquer vazamento da tabela (F1 permitia) equivale a vazar as senhas.
- **Confiança:** 97.
- **O que fiz:** `autenticar()` busca o usuário pelo login e confere a senha no PHP. Hash com 32 hexadecimais é tratado como legado e comparado com `hash_equals`; os demais, com `password_verify`. No primeiro login válido o hash legado é regravado com `password_hash()`. A regravação é de melhor esforço e nunca impede o login:
  - só acontece se a coluna comportar o hash novo. Com `CHAR(32)` nada é gravado, porque em modo não estrito o MySQL truncaria o hash e trancaria o usuário;
  - se o `UPDATE` falhar (usuário do banco sem permissão, por exemplo), o erro vai para o log e o login segue;
  - o `UPDATE` leva `AND senha = <hash antigo>`, para não sobrescrever uma senha trocada por outro processo nesse intervalo.

  `schema.sql` passou a declarar `VARCHAR(255)`. A assinatura, o retorno `['id','nome','papel']` e os logins do manifesto continuam iguais. Contas que nunca mais fizerem login ficam em MD5; se isso importar, o passo seguinte é uma migração offline que envolva o MD5 existente em `password_hash`. Não fiz.

### F8 — Sessão: id não é trocado no login e o cookie não tem proteção

- **Onde:** `code/index.php:15–25`.
- **Mecanismo:** `session_start()` roda com os padrões (linha 15) e o login grava `uid` e `papel` na sessão que já existia (linhas 24–25), sem `session_regenerate_id()`. O PHP aceita um id de sessão escolhido pelo cliente. Quem consegue plantar um `PHPSESSID` no navegador da vítima (pelo XSS de F5, por exemplo) fica com esse mesmo id autenticado depois que ela faz login.
- **Impacto e severidade:** **média**. Sequestro de sessão; depende de um vetor para plantar o cookie.
- **Confiança:** 88. Reproduzido: no original, o id plantado entrou como a vítima; no entregue, não.
- **O que fiz:** `session_regenerate_id(true)` no login; `use_strict_mode` (id desconhecido é descartado); cookie com `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição chega por HTTPS. Usei `Lax` e não `Strict` para que links e favoritos externos continuem chegando logados.

### F9 — Export grava sempre o mesmo arquivo compartilhado

- **Onde:** `code/lib.php:125–126`; o arquivo é devolvido na linha 150.
- **Mecanismo:** todo export abre `EXPORT_DIR/chamados.csv` com `'w'`, que trunca, escreve e depois faz `readfile` do mesmo caminho. Com dois exports simultâneos, o segundo trunca o arquivo enquanto o primeiro ainda escreve ou lê, e alguém recebe um CSV cortado ou misturado. O arquivo fica no disco depois da requisição, com a base inteira, em `/var/www/painel/tmp`.
- **Impacto e severidade:** **média**. CSV corrompido de forma intermitente para quem consome o export. Se `/var/www/painel` for a raiz web, o arquivo também pode ser baixado sem login; não tenho como confirmar isso pelo pacote, e nesse caso a severidade sobe para alta. Com F4 corrigido, um arquivo compartilhado ainda permitiria a um cliente receber o export de um técnico.
- **Confiança:** 85. Vale por construção; não reproduzi a corrida.
- **O que fiz:** o CSV é escrito direto em `php://output`, sem arquivo intermediário. `EXPORT_DIR` continua definida, mas `exportarCsv()` não a usa mais.

### F10 — Divisão por zero derruba a listagem

- **Onde:** `code/lib.php:116`, em `mediaResposta()`.
- **Mecanismo:** `$soma / $qtd` com `$qtd = 0` quando nenhum chamado tem `minutos_resposta`. No PHP 8 isso lança `DivisionByZeroError`; como `index.php:74` chama a função antes de montar a página, a listagem inteira responde HTTP 500 com corpo vazio.
- **Impacto e severidade:** **média**. Painel fora do ar para todos em base nova ou sem chamado respondido.
- **Confiança:** 99. Reproduzido.
- **O que fiz:** sem chamados respondidos a função devolve `0.0`, e a página mostra "0 min".

### F11 — Uma consulta por chamado para buscar o nome do técnico (N+1)

- **Onde:** `code/lib.php:88–91`; repete-se em `136–137`. A consulta é a de `tecnicoNome()`, `64–72`.
- **Mecanismo:** o laço chama `tecnicoNome()` para cada linha, e cada chamada faz um `SELECT` em `usuarios`.
- **Impacto e severidade:** **média**. Cresce linearmente com a base. Com 2005 chamados medi 2006 `SELECT`s e 0,37 s na listagem e 2005 `SELECT`s no CSV, em socket local; com latência de rede a diferença é maior.
- **Confiança:** 99. Medido.
- **O que fiz:** `LEFT JOIN usuarios` na consulta principal, com `COALESCE(t.nome, '-')` para manter o `-` de chamado sem técnico. A listagem caiu para 2 `SELECT`s (0,03 s) e o CSV para 1. `tecnicoNome()` não é mais usada pelo pacote e ficou como estava, porque outro script pode chamá-la.

### F12 — Título do chamado vira fórmula quando o CSV é aberto em planilha

- **Onde:** `code/lib.php:138–144`.
- **Mecanismo:** o título é escrito pelo cliente e vai cru para o CSV. Um título `=HYPERLINK("http://…","clique")` é interpretado como fórmula quando o técnico ou a gerência abre o arquivo no Excel ou no LibreOffice.
- **Impacto e severidade:** **média**. Um cliente ataca quem abre o export (exfiltração de dados da planilha; execução de comando em versões antigas).
- **Confiança:** 70. Depende de o CSV ser aberto em planilha; o link "Exportar CSV" indica que sim.
- **O que fiz:** `neutralizarFormulaCsv()` prefixa com apóstrofo o título e o nome do técnico que comecem com `=`, `+`, `-`, `@`, TAB, CR ou LF. O `-` de "sem técnico" não é alterado. Custo assumido: um título legítimo como `-5 dBm no sinal` passa a sair como `'-5 dBm no sinal`. Para os dados do seed o CSV é idêntico ao original.

### F13 — Erros não tratados: mensagem de conexão nunca aparece e exceções expõem SQL

- **Onde:** `code/index.php:9–12`.
- **Mecanismo:** são dois defeitos no mesmo ponto.
  - A partir do PHP 8.1, `new mysqli()` lança exceção quando a conexão falha, então o `if ($db->connect_errno)` é código morto: com o banco fora, o usuário recebe um 500 em branco, não a mensagem prevista. Em PHP anterior a 8.1 a mensagem sai com HTTP 200, e monitoramento e caches enxergam sucesso.
  - Não há tratador de exceção. Uma falha de consulta vira exceção não capturada cuja mensagem traz o SQL (`…near 'Brien%' ORDER BY criado_em DESC'… in …/lib.php:85`) e cujo rastro traz os argumentos de `autenticar()`, entre eles a senha digitada. Com `display_errors` ligado isso vai para o navegador; desligado, para o log.
- **Impacto e severidade:** **média**. Indisponibilidade mal sinalizada e vazamento de detalhes internos.
- **Confiança:** 85. O 500 em branco e o SQL no log foram reproduzidos; o que chega ao navegador depende do `php.ini`.
- **O que fiz:** `mysqli_report()` explícito, para o comportamento não depender da versão do PHP; `try/catch` na conexão, que responde 503 com a mesma mensagem "Falha ao conectar ao banco."; e um `set_exception_handler` que registra classe, mensagem e arquivo:linha (sem rastro) e responde um 500 genérico.

### F14 — Export falha em silêncio

- **Onde:** `code/lib.php:127–135`.
- **Mecanismo:** se o `fopen` falha (diretório inexistente ou sem permissão), a função dá `return` e a rota responde HTTP 200 com 0 bytes. Se a consulta falha, a função retorna sem `fclose($fp)` e deixa no disco um arquivo só com o cabeçalho.
- **Impacto e severidade:** **média**. A rotina noturna recebe um export vazio sem nenhum erro, e o problema só aparece mais adiante, em quem consome o arquivo.
- **Confiança:** 90. Reproduzido: nesta máquina `/var/www/painel/tmp` não existe e o original respondeu 200 com 0 bytes.
- **O que fiz:** resolvido junto com F9. Não há mais arquivo para abrir, e a consulta roda antes dos cabeçalhos, então uma falha vira exceção e resposta de erro, não um CSV vazio.

### F15 — Login aceita tentativas ilimitadas

- **Onde:** `code/index.php:20–29`.
- **Mecanismo:** não há contagem de tentativas, atraso nem bloqueio. Com senhas fracas (o seed usa `senha123`) e verificação barata, um dicionário resolve.
- **Impacto e severidade:** **média**. Tomada de conta por força bruta.
- **Confiança:** 75.
- **O que fiz:** **não corrigi.** Limitar tentativas exige guardar estado (tabela nova ou cache), o que é mudança de schema ou de infraestrutura que não posso validar daqui. Proposta: limite por IP e por login no proxy reverso, ou uma tabela `tentativas_login` consultada por `autenticar()`.

### F16 — Listagem sem limite, trazendo todas as colunas

- **Onde:** `code/lib.php:80–85`.
- **Mecanismo:** `SELECT *` sem `LIMIT` traz todos os chamados, incluindo `descricao` (`TEXT`), que a listagem não exibe. `LIKE '%termo%'` não usa índice. Com 2005 chamados a página já tem 275 KB.
- **Impacto e severidade:** **média**. Tempo e memória crescem com a base, que acumula desde 2013.
- **Confiança:** 75. O problema é real; o tamanho da base de produção eu não conheço.
- **O que fiz:** **não corrigi.** `listarChamados()` é pública e seu retorno, com todas as colunas e todas as linhas, pode estar sendo consumido pelo relatório gerencial; paginar a tela esconderia chamados de quem hoje os vê em uma página só. O filtro por dono de F3 já reduz a consulta dos clientes. Proposta: parâmetro opcional `pagina` só na rota web, com `LIMIT` em uma função interna, depois de combinado com quem usa a tela.

### F17 — Status desconhecido é exibido como "Resolvido"

- **Onde:** `code/lib.php:32–34`, em `formatarStatus()`.
- **Mecanismo:** o `else` final devolve "Resolvido" para qualquer valor que não seja 1 ou 2. A coluna é `TINYINT` sem restrição, então um 0, um 4 ou um status novo aparecem como resolvidos no painel, no CSV e no relatório gerencial.
- **Impacto e severidade:** **baixa**. Chamado não resolvido contado como resolvido.
- **Confiança:** 75. O defeito está no código; se existem linhas assim em produção, não sei.
- **O que fiz:** 3 devolve "Resolvido" e o restante devolve "Desconhecido". Os rótulos de 1, 2 e 3 do manifesto não mudaram. Ver o item 3 de "Antes do deploy".

### F18 — Parâmetro enviado como array derruba a página

- **Onde:** `code/index.php:22`; também `53` e `72–73`.
- **Mecanismo:** `login[]=x` e `busca[]=x` chegam como array a funções que exigem `string`, o que lança `TypeError` e responde HTTP 500. `ver[]=7` vira `(int) ['7']`, que vale 1, e abre o chamado de id 1 se ele existir.
- **Impacto e severidade:** **baixa**. Erro 500 provocável por qualquer visitante, inclusive sem login.
- **Confiança:** 97. Reproduzido.
- **O que fiz:** as três entradas só são aceitas se forem string. Array em `login` reapresenta o formulário, em `busca` equivale a busca vazia e em `ver` responde "Chamado nao encontrado.".

### F19 — Média calculada no PHP, trazendo todas as linhas

- **Onde:** `code/lib.php:109–115`, em `mediaResposta()`.
- **Mecanismo:** a cada exibição da listagem, a função traz todos os chamados respondidos para somar em um laço.
- **Impacto e severidade:** **baixa**. Tráfego e memória proporcionais à base, em toda página.
- **Confiança:** 90.
- **O que fiz:** `SUM` e `COUNT` no banco, com a divisão no PHP. Não usei `AVG()` porque ele devolve um decimal com 4 casas (25.6667), e a função deixaria de devolver o mesmo `float` de antes (25.666666666666668).

### F20 — `fputcsv` sem `$escape` gera aviso a cada linha no PHP 8.4

- **Onde:** `code/lib.php:130`; também `138`.
- **Mecanismo:** desde o PHP 8.4, chamar `fputcsv` sem o parâmetro `$escape` emite `Deprecated`. Com `E_DEPRECATED` reportado são N+1 avisos por export; com `display_errors` ligado eles entram na resposta antes dos cabeçalhos e o download quebra.
- **Impacto e severidade:** **baixa**. Só aparece em PHP 8.4+ com `E_DEPRECATED` ativo, o que o `php.ini` de produção costuma desligar.
- **Confiança:** 65. Reproduzido no PHP 8.4.26 com `E_ALL`: 6 avisos para 5 chamados. Não sei qual é a versão de produção.
- **O que fiz:** separador, aspas e escape passados explicitamente, com os valores padrão atuais. Os bytes gerados são os mesmos.

### F21 — `rotuloPrioridade` com quatro níveis de `if` aninhado e números mágicos

- **Onde:** `code/lib.php:40–59`.
- **Mecanismo:** a regra de SLA está em `if/else` aninhados em quatro níveis, com 30, 3 e 4 soltos no código. Cada regra nova duplica ramos, e não há como saber o que os números significam sem ler `schema.sql`.
- **Impacto e severidade:** **baixa**. Manutenção; não há erro de resultado.
- **Confiança:** 80.
- **O que fiz:** retornos antecipados e nomes para os três números. O resultado é igual ao original nas 72 combinações testadas (prioridade de −1 a 6; minutos `null`, −5, 0, 1, 29, 30, 31, 45 e 100000).

### F22 — Um arquivo faz tudo; não há lugar para regras transversais

- **Onde:** `code/index.php:4` em diante (o arquivo inteiro).
- **Mecanismo:** `index.php` conecta ao banco, abre sessão, autentica, roteia, consulta e monta HTML com `echo`. `lib.php` mistura SQL, regra de negócio e HTTP (`exportarCsv` envia cabeçalhos). Sem um ponto único para autorização e para escape, cada rota precisa lembrar de fazer as duas coisas. É a causa estrutural de F2, F3, F4 e F5: a regra de visibilidade não estava em lugar nenhum e o escape foi esquecido em dois dos sete pontos de saída.
- **Impacto e severidade:** **média**. Cada rota nova pode repetir os mesmos defeitos.
- **Confiança:** 85.
- **O que fiz:** **não reestruturei**, por ser reescrita, que a tarefa veda. Dei três passos incrementais: a regra de visibilidade tem uma função (`donoRestrito`), as consultas novas passam por um único ponto (`consultaPreparada`) e há um tratador de erro central. Próximos passos sugeridos, um de cada vez: função de escape única e HTML em templates; sessão e login em arquivo próprio; testes de caracterização versionados junto do código.

---

## 3. Decisões — o que não mudei e por quê

**Assinaturas públicas e mysqli.** Nenhuma função do manifesto ganhou parâmetro. O filtro por dono entrou em funções internas novas (`listarChamadosDoDono`, `verChamadoDoDono`, `exportarCsvDoDono`), e as públicas continuam devolvendo tudo, como os scripts internos esperam. A consequência é que quem chamar uma função pública a partir de uma tela nova precisa lembrar de usar a variante com dono; isso está nos comentários das funções.

**`verChamado()` e `tecnicoNome()` concatenam um valor no SQL, e deixei assim.** O valor é um parâmetro tipado como `int`, então não há como injetar texto por ele. Não é vulnerabilidade, e mexer só acrescentaria risco.

**Título, descrição e nome do técnico no HTML.** Já passavam por `htmlspecialchars`. `$c['id']` e o rótulo de status saem sem escape, mas são um inteiro do banco e textos fixos.

**Curingas do `LIKE` na busca.** `%` e `_` digitados pelo usuário continuam funcionando como curingas, como sempre funcionaram. Escapá-los mudaria o resultado de buscas e favoritos existentes.

**Média de primeira resposta.** Continua global, também para clientes. É um indicador agregado, não um chamado; restringi-la mudaria o número que o cliente vê hoje.

**Rótulos de `rotuloPrioridade`.** Um chamado crítico ainda sem resposta aparece como "Aguardando 1a resposta", e um crítico dentro do SLA como "Alto - dentro do SLA". Podem ser falhas de produto, mas os textos são consumidos fora daqui e não há especificação que diga o contrário.

**Escape `\` do CSV.** Mantive o comportamento histórico do `fputcsv` (um `"` precedido de `\` não é duplicado), que foge do RFC 4180, porque o formato do CSV é contrato e o faturamento o lê como está.

**Arquivo em `EXPORT_DIR`.** Deixar de gravá-lo é a única mudança de efeito colateral que fiz de propósito. O manifesto define o contrato de `exportarCsv` como "escreve o CSV na saída", e manter o arquivo seria manter F9.

**Paginação, limite de tentativas de login e reestruturação.** Ver F16, F15 e F22.

**Outros pontos do login.** Não há rota de logout, token CSRF no formulário, mensagem de erro em login inválido nem revalidação do papel guardado na sessão (um técnico rebaixado mantém o acesso até a sessão expirar). São melhorias reais, mas são funcionalidade nova, não correção.

**Cabeçalhos de segurança (CSP, `X-Frame-Options`).** Não acrescentei, porque não sei se o painel é embutido em algum portal interno.

**Código de resposta de "Chamado nao encontrado."** Continua 200, como no original.

**Banco.** Não acrescentei `CHECK` em `status` e `prioridade` nem reduzi privilégios do usuário do banco. Ambos são recomendáveis e dependem de conhecer os dados e os outros scripts.

**`seed.sql`.** Intacto. Os usuários do manifesto continuam entrando com as senhas declaradas.
