# Relatório técnico — LEB-100-A (Painel de Chamados, NetX ISP)

Sistema: PHP + MySQL (mysqli), em produção desde 2013. Entrega: `code/` alterado no lugar, este relatório e `achados.json` com o mesmo conjunto de achados.

## 1. Resumo

O painel funciona para o técnico, mas tem falhas graves de autorização. Um cliente vê, exporta e abre chamados de qualquer outro cliente, e pode ler o banco inteiro por SQL injection na busca. Somam-se XSS refletido, credenciais de produção escritas no código, um arquivo de export sem controle de acesso e fixação de sessão.

Encontrei **20 achados**: 2 críticos, 7 altos, 5 médios e 6 baixos. Corrigi **14** no código. Os outros **6** ficam como decisão ou dependem de negócio (ver §4).

**Antes de publicar** (o código sozinho não resolve isto):

1. **Rotacionar a senha do usuário `painel` no MySQL e a chave SMTP.** Ambas estão no código atual. A senha de produção também apareceu na saída de um comando de verificação, então trate como exposta.
2. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP.** Sem `DB_PASS` o painel não conecta; não há mais senha de reserva.
3. **Confirmar se a rotina noturna lê `/var/www/painel/tmp/chamados.csv`.** O export não grava mais esse arquivo. Se ele existir no servidor, apagar, e verificar se o diretório é servido pelo Apache (F6).
4. **Avisar os consumidores** das mudanças de compatibilidade listadas em §5 (cabeçalho do CSV e tipos de campo de `listarChamados`).
5. **Migração do hash de senha (F8) continua pendente.** Exige DDL em produção.

## 2. Achados, em ordem de prioridade de correção

### F1 — Senha de produção do banco embutida em `config.php`

- **Onde:** `code/config.php:12`
- **O que é:** fallback literal da senha do usuário `painel` em `getenv('DB_PASS') ?: '<senha>'`. O valor não é repetido aqui; está na linha 12 do original.
- **Mecanismo:** sempre que `DB_PASS` não está no ambiente, o código conecta ao banco de produção com a senha escrita no arquivo. Quem tem o código, um backup ou o histórico do repositório tem a credencial.
- **Impacto:** conexão direta ao banco `suporte` de produção, sem passar pela aplicação: chamados, dados de clientes e hashes de senha.
- **Severidade:** crítica · **Confiança:** 99
- **O que fiz:** removi o fallback. `DB_PASS` vem só do ambiente e, sem ela, fica vazia, então a conexão falha de forma controlada. **Pendente:** rotacionar a senha.

### F2 — SQL injection na busca

- **Onde:** `code/lib.php:80-83`
- **O que é:** o termo de busca é concatenado ao SQL (`LIKE '%…%'`) sem escape.
- **Mecanismo:** um `'` no termo fecha a string, e um `UNION SELECT` lê a tabela `usuarios`.
- **Impacto:** qualquer conta logada, inclusive a de cliente, lê o banco inteiro. Reproduzi com `ana` (cliente): o `UNION` devolveu login e hash md5 de todos os usuários. Os hashes de técnico se quebram por dicionário, e aí o atacante vira técnico.
- **Severidade:** crítica · **Confiança:** 99
- **O que fiz:** consulta preparada em `consultarChamados`. Os caracteres `%`, `_` e a barra invertida são escapados, então o termo é tratado como texto. `listarChamados` mantém a assinatura e delega para ela.

### F3 — Export sem filtro: cliente exporta todos os chamados

- **Onde:** `code/lib.php:132` (função `exportarCsv`, linhas 123–151)
- **O que é:** `SELECT * FROM chamados ORDER BY id` sem restrição. `index.php?export=csv` chama a função para qualquer papel.
- **Mecanismo:** o manifesto diz que o cliente só vê os chamados que abriu. A função nem recebe o usuário, então não há como aplicar a regra.
- **Impacto:** reproduzido: `ana` recebeu os cinco chamados, inclusive o 103 e o 104, que são de `bruno`.
- **Severidade:** alta · **Confiança:** 98
- **O que fiz:** `index.php` passa `$dono` para `escreverCsv($db, $dono)`, que filtra no SQL. `exportarCsv(mysqli)` continua exportando tudo, com a mesma assinatura, para as rotinas internas.

### F4 — Listagem mostra a cliente todos os chamados

- **Onde:** `code/index.php:72-73`
- **O que é:** `listarChamados($db, $busca)` sem filtro por usuário.
- **Mecanismo:** a única barreira na listagem é o login. A regra de visibilidade do manifesto não é aplicada.
- **Impacto:** reproduzido: `ana` lista os chamados 103 e 104, de `bruno`, com título, status, prioridade e técnico.
- **Severidade:** alta · **Confiança:** 98
- **O que fiz:** a listagem usa `consultarChamados($db, $busca, $dono)`. Para técnico, `$dono` é `null` e não há filtro. A saída do técnico ficou idêntica à original.

### F5 — Detalhe de chamado por id, sem checar o dono (IDOR)

- **Onde:** `code/index.php:52-58`
- **O que é:** `index.php?ver=<id>` exibe o chamado sem comparar `usuario_id` com o usuário logado.
- **Mecanismo:** `verChamado` devolve qualquer registro pelo id, e a página não confere a posse.
- **Impacto:** reproduzido: `ana` abriu o 103 de `bruno`, com título, status, prioridade e descrição completa. Enumerar ids expõe todos os chamados.
- **Severidade:** alta · **Confiança:** 98
- **O que fiz:** para um chamado de outro cliente, a página responde "Chamado nao encontrado.", a mesma mensagem de id inexistente, sem confirmar que o chamado existe.

### F6 — Export grava arquivo fixo em `EXPORT_DIR`, sem controle de acesso, e sofre corrida

- **Onde:** `code/lib.php:125-150`
- **O que é:** `fopen(EXPORT_DIR.'/chamados.csv', 'w')`, grava todos os chamados, depois `readfile`.
- **Mecanismo:** (1) o arquivo com todos os chamados fica em disco num caminho que parece estar sob a árvore do Apache (`/var/www/painel/tmp`). Se estiver, o download sai direto pela URL, sem login. **Não verifiquei a configuração do servidor; é a hipótese de maior incerteza deste achado.** (2) Exportações concorrentes usam o mesmo arquivo: um `fopen('w')` trunca o que outra requisição está lendo, e o CSV sai truncado ou misturado. Não reproduzi a corrida; é raciocínio sobre o código.
- **Impacto:** vazamento de todos os chamados sem autenticação, se o diretório for servido. Export truncado para o usuário ou para a rotina noturna.
- **Severidade:** alta · **Confiança:** 80
- **O que fiz:** `escreverCsv` escreve direto em `php://output`. Nada mais é gravado em `EXPORT_DIR`. Pendência operacional: §1, item 3.

### F7 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79-82`
- **O que é:** `$busca` sai sem escape no atributo `value` do input e em "Resultados para:".
- **Mecanismo:** `busca="><script>…</script>` fecha o atributo e injeta a tag na página.
- **Impacto:** JavaScript executa na sessão de quem abrir um link malicioso, inclusive técnico. Reproduzido: a tag `<script>` chega crua na resposta.
- **Severidade:** alta · **Confiança:** 98
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.

### F8 — Senhas em MD5 sem sal e login sem limite de tentativas

- **Onde:** `code/lib.php:15-17`; `code/schema.sql:7`
- **O que é:** `md5($senha)` comparado por igualdade com `usuarios.senha`, que é `CHAR(32)`.
- **Mecanismo:** sem sal, senhas iguais geram o mesmo hash. No dump do F2, `ana` e `bruno` (mesma senha) têm `e7d80ffe…`; `carla` e `diego` têm `2b21aaa0…`. Não há limite de tentativas nem atraso no formulário.
- **Impacto:** quebra por dicionário de todas as senhas do banco, ou do dump. Força bruta online sem freio.
- **Severidade:** alta · **Confiança:** 95
- **O que fiz:** **não alterei** (ver §4). Trocar o hash exige `ALTER TABLE usuarios MODIFY senha VARCHAR(255)` em produção antes do rehash no login (`password_hash` na primeira autenticação válida). Sem o DDL, o hash novo seria truncado e ninguém conseguiria entrar.

### F9 — Chave da API SMTP escrita no código

- **Onde:** `code/config.php:15`
- **O que é:** `define('SMTP_API_KEY', '<chave>')` com o segredo literal, na linha 15 do original. Neste pacote a constante não é usada, mas o segredo está no arquivo.
- **Mecanismo:** qualquer leitor do arquivo tem o segredo.
- **Impacto:** envio de e-mail pela central transacional da NetX, inclusive phishing em nome do ISP.
- **Severidade:** alta · **Confiança:** 95
- **O que fiz:** `getenv('SMTP_API_KEY') ?: ''`, com o mesmo nome de constante. **Pendente:** rotacionar a chave.

### F10 — Id de sessão não muda no login (fixação)

- **Onde:** `code/index.php:23-25`
- **O que é:** após autenticar, o código grava `uid` e `papel` na sessão existente.
- **Mecanismo:** sem `session_regenerate_id`, o id de sessão não muda. O PHP aceita o `PHPSESSID` enviado pelo cliente (`session.use_strict_mode` vem desligado). Reproduzido: um id escolhido antes do login continua autenticado depois dele, e a resposta do login não traz `Set-Cookie`.
- **Impacto:** quem fixa o id da vítima (por exemplo, pelo XSS do F7) fica logado na conta dela depois que ela entra.
- **Severidade:** média · **Confiança:** 85
- **O que fiz:** `session_regenerate_id(true)` no login bem-sucedido, e `ini_set('session.cookie_httponly', '1')` antes de `session_start()`. Reproduzido no código novo: o id fixado deixa de autenticar e o `Set-Cookie` traz `HttpOnly`.

### F11 — Divisão por zero em `mediaResposta` derruba a página

- **Onde:** `code/lib.php:107-117` (divisão na linha 116)
- **O que é:** `return $soma / $qtd` sem checar `$qtd`.
- **Mecanismo:** sem nenhum `minutos_resposta` preenchido, `$qtd` é 0 e o PHP 8 lança `DivisionByZeroError`.
- **Impacto:** a página inteira retorna HTTP 500 para todos os papéis, em base sem atendimentos respondidos. Reproduzido: 500, e o log aponta `lib.php:116`.
- **Severidade:** média · **Confiança:** 97 (categoria: bug)
- **O que fiz:** `SUM` e `COUNT` no SQL, divisão em PHP, e `0.0` quando não há registros. Com os dados de teste, o valor é idêntico ao original (`25.666666666666668`). Decisão ligada em §4.

### F12 — N+1 para buscar o nome do técnico

- **Onde:** `code/lib.php:88-90`; também `:137` no export
- **O que é:** `tecnicoNome` faz uma consulta por chamado.
- **Mecanismo:** são 1 + N consultas por requisição, na listagem e no export.
- **Impacto:** a latência cresce linearmente com o número de chamados, na página e no export noturno.
- **Severidade:** média · **Confiança:** 95 (categoria: performance)
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(t.nome, '-') AS tecnico_nome`. A chave e o valor de saída são os mesmos. Não medi o ganho de tempo.

### F13 — CSV sem neutralização de fórmula

- **Onde:** `code/lib.php:138-144`
- **O que é:** `fputcsv` grava `titulo` como está.
- **Mecanismo:** um título que começa com `=`, `+`, `-` ou `@` vira fórmula ao abrir o CSV no Excel ou LibreOffice (por exemplo, `HYPERLINK` para exfiltrar dados). O título é texto livre, e a origem dele não está no pacote.
- **Impacto:** execução de fórmula na máquina de quem abre o export, a partir de um título malicioso.
- **Severidade:** média · **Confiança:** 60
- **O que fiz:** **não alterei** (ver §4). Prefixar com apóstrofo muda o valor que o relatório gerencial e a rotina noturna leem.

### F14 — Prioridade crítica sem resposta aparece como "Aguardando 1a resposta"

- **Onde:** `code/lib.php:56-57`
- **O que é:** sem `minutos_resposta`, o rótulo é "Aguardando 1a resposta" para qualquer prioridade.
- **Mecanismo:** o chamado 104 (prioridade 4, crítica, sem técnico) aparece assim na listagem, sem sinal de urgência. Reproduzido na saída.
- **Impacto:** um chamado crítico parado pode parecer normal. Confiança baixa: pode ser regra intencional.
- **Severidade:** média · **Confiança:** 40
- **O que fiz:** **não alterei** (ver §4). Precisa de decisão de negócio.

### F15 — Parâmetros em forma de array (`login[]`, `busca[]`) causam HTTP 500

- **Onde:** `code/index.php:22` e `:72`
- **O que é:** `$_POST['login']` vai direto para `autenticar(string …)`. `$_GET['busca']` vai para `listarChamados(string …)`.
- **Mecanismo:** um array no lugar de string lança `TypeError`.
- **Impacto:** erro 500 e ruído no log. Sem vazamento.
- **Severidade:** baixa · **Confiança:** 95
- **O que fiz:** aceito só string (`is_string`). Reproduzido: 500 no original, 200 no código novo.

### F16 — Falha de conexão: `die()` nunca executa

- **Onde:** `code/index.php:9-12`
- **O que é:** o teste `connect_errno` depois de `new mysqli(…)`.
- **Mecanismo:** no PHP 8.1+, `new mysqli` lança `mysqli_sql_exception` antes desse teste. A mensagem `Access denied for user 'painel'@'localhost'` sobe sem tratamento. Reproduzido no log, em `index.php:9`.
- **Impacto:** 500 em vez de mensagem controlada. Com `display_errors` ligado, usuário e host do banco vão para o navegador.
- **Severidade:** baixa · **Confiança:** 85
- **O que fiz:** `try/catch` com `error_log` e `die` controlado. O teste `connect_errno` continua para PHP anterior a 8.1.

### F17 — Cabeçalho do CSV difere do manifesto

- **Onde:** `code/lib.php:130`
- **O que é:** o cabeçalho sai como `ID,Titulo,Status,Tecnico,"Aberto em"`.
- **Mecanismo:** `fputcsv` cita campos que têm espaço. O manifesto exige o cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** consumidor que compara o cabeçalho byte a byte falha. Um parser CSV lê os dois do mesmo jeito.
- **Severidade:** baixa · **Confiança:** 90 (categoria: bug)
- **O que fiz:** cabeçalho escrito literalmente como o manifesto. As linhas de dados seguem por `fputcsv`, sem mudança. Efeito colateral em §5.

### F18 — `formatarStatus` devolve "Resolvido" para qualquer valor fora de 1 e 2

- **Onde:** `code/lib.php:26-35` (o `else` está na linha 33)
- **O que é:** o `else` final trata qualquer status diferente de 1 e 2, inclusive 0 ou 4+, como "Resolvido".
- **Mecanismo:** a coluna é `TINYINT` sem restrição. Um valor inesperado aparece como resolvido.
- **Impacto:** o relatório gerencial pode contar um chamado em estado desconhecido como resolvido.
- **Severidade:** baixa · **Confiança:** 50 (categoria: qualidade)
- **O que fiz:** **não alterei** (ver §4). O manifesto fixa só os três rótulos.

### F19 — Listagem sem paginação

- **Onde:** `code/lib.php:78-93`
- **O que é:** a consulta não tem `LIMIT`.
- **Mecanismo:** toda requisição carrega a tabela inteira e monta o array em memória.
- **Impacto:** custo crescente com o volume de chamados. O impacto real depende do tamanho da tabela, que não está no pacote.
- **Severidade:** baixa · **Confiança:** 55 (categoria: performance)
- **O que fiz:** **não alterei** (ver §4).

### F20 — Camada de dados sem contexto de usuário (raiz de F3 a F5)

- **Onde:** `code/lib.php:148-150`; assinaturas em `:78`, `:98` e `:123`
- **O que é:** as funções de dados recebem só o `mysqli`. `exportarCsv` ainda emite `header()` e grava na saída HTTP.
- **Mecanismo:** a regra de visibilidade não tinha onde morar, então foi esquecida em três rotas. Cada rota nova pode repetir o erro.
- **Impacto:** regra de segurança espalhada e sujeita a esquecimento.
- **Severidade:** baixa · **Confiança:** 65 (categoria: arquitetura)
- **O que fiz:** **parcial, marcado como não corrigido.** A regra ficou centralizada em `donoVisivel`, e as consultas filtram no SQL. Não separei as camadas, por compatibilidade com as assinaturas públicas e com a rotina noturna.

## 3. Resumo de correções por arquivo

- `code/config.php`: F1, F9.
- `code/lib.php`: F2, F3, F6, F11, F12, F17, e a parte centralizada de F20. Funções novas: `donoVisivel`, `consultarChamados` e `escreverCsv`. As assinaturas públicas do manifesto foram mantidas.
- `code/index.php`: F4, F5, F7, F10, F15, F16.
- `code/schema.sql` e `code/seed.sql`: sem alteração.

## 4. Decisões — o que não mudei e por quê

- **F8 (MD5 sem sal, sem limite de tentativas).** A troca exige DDL (`senha VARCHAR(255)`) aplicado em produção antes do rehash no login. Sem isso, o hash moderno seria truncado e ninguém entraria. O limite de tentativas precisa de estado persistente. Plano: (a) ampliar a coluna em janela de manutenção; (b) no login, aceitar md5 legado e regravar com `password_hash`; (c) depois de um ciclo, bloquear md5. Fica para uma entrega própria.
- **F13 (fórmula no CSV).** Escapar muda o valor exportado, que o relatório gerencial e a rotina noturna leem. Decisão de quem consome o CSV.
- **F14 (rótulo de prioridade crítica sem resposta).** A regra de negócio não está no manifesto. Mudar sem decisão do dono do produto troca a classificação exibida.
- **F18 (default de `formatarStatus`).** O contrato lista só 1, 2 e 3. O default atual fica, mas é uma armadilha: um status fora do domínio aparece como "Resolvido". Vale definir o rótulo para valores inesperados.
- **F19 (paginação).** Mudaria o conteúdo retornado por `listarChamados`, que o relatório gerencial consome.
- **F20 (separação de camadas).** Separar exigiria mudar o que `exportarCsv` faz (deixaria de escrever na saída), e a rotina noturna depende desse comportamento. Ficou só a regra centralizada.
- **`mediaResposta` global também para cliente.** É um agregado, não um chamado individual, e a regra do manifesto fala de chamados. Não restringi. Se o negócio considerar a média dado restrito, precisa de decisão própria.
- **`mediaResposta` com `0.0` sem atendimentos.** O contrato é `float`, então não usei `null`. Quem consome pode ler `0 min` como SLA perfeito. Sugiro tratar esse caso no relatório gerencial.
- **`tecnicoNome` e `EXPORT_DIR` mantidas, sem uso interno.** Podem ser usadas por scripts que não estão no manifesto. Remover não traz ganho e cria risco.
- **Stack mysqli procedural.** Trocar por PDO mudaria todas as assinaturas públicas, o que o TAREFA.md proíbe (restrição 2).
- **Estilo** (`else if`, números mágicos 30, 3 e 4 em `rotuloPrioridade`, nomes mistos). Sem mudança de comportamento e fora do escopo. Reformatar aumentaria o diff sem ganho de segurança.
- **Logout, CSRF no login e cabeçalhos de segurança HTTP.** Não existem no código. Não adicionei, por escopo. CSRF no login tem impacto baixo, mas vale um token no futuro.
- **`.leb-pacote.sha256` não foi atualizado.** Ele descreve o pacote entregue. `code/` agora difere do original por desenho.
- **Ações fora do alcance do código:** rotacionar credenciais (F1, F9); apagar o `chamados.csv` antigo em `/var/www/painel/tmp`, se existir (não tenho acesso a esse caminho neste ambiente).

## 5. Compatibilidade: o que muda para quem consome

- **Configuração.** `DB_PASS` e `SMTP_API_KEY` vêm do ambiente. Sem `DB_PASS`, a conexão falha de forma controlada.
- **CSV (`index.php?export=csv` e `exportarCsv`).**
  - O cabeçalho agora é `ID,Titulo,Status,Tecnico,Aberto em`, sem aspas, como no manifesto. As linhas de dados não mudaram.
  - O arquivo `chamados.csv` não é mais gravado em `EXPORT_DIR`. Se a rotina noturna lê esse arquivo, precisa passar a usar a saída da função.
- **Tipos em `listarChamados` e `consultarChamados`.** Os campos numéricos (`id`, `usuario_id`, `tecnico_id`, `status`, `prioridade`, `minutos_resposta`) saem como `int`. Antes saíam como `string`, pelo protocolo de texto do `query()`. Os valores são os mesmos, mas comparação com `===` contra string muda de resultado. Verificar os consumidores.
- **Busca.** `%` e `_` no termo de busca agora são caracteres literais. Antes eram curingas do `LIKE`, e `busca=%` devolvia todos os chamados.
- **`mediaResposta`.** Mesmo valor. Retorna `0.0` quando não há atendimentos; antes dava erro fatal.
- **Visão de técnico.** Listagem, detalhe, busca e export são idênticos ao original, exceto o cabeçalho do CSV (F17). Verificado por diff.
- **Visão de cliente.** Mudança intencional (F3, F4, F5): vê só os próprios chamados, como o manifesto determina.
- **Sessão.** Id novo a cada login e cookie com `HttpOnly`. Quem já estava logado continua com a sessão atual até sair ou o cookie expirar; não há logout no código.

## 6. Como verifiquei e o que não verifiquei

**Feito:**

- Subi um MariaDB 11.8 temporário, fora do pacote, com `schema.sql` e `seed.sql`, e um servidor PHP 8.4 embutido. Usei um usuário de teste local. A senha de produção não foi usada em nenhum teste.
- Reproduzi no código original, antes de editar: F2, F3, F4, F5, F7, F10, F11, F15, F16 e F17. F14 foi confirmado na saída da listagem. F6, F8, F9, F12, F13, F18 e F19 foram verificados pela leitura do código.
- Repeti no código novo: visibilidade por papel (listagem, detalhe, export, busca combinada com dono), SQL injection, XSS, fixação de sessão, divisão por zero, falha de conexão, arrays em parâmetros.
- Comparei original × novo para técnico: listagem, detalhe, busca e CSV. A única diferença é o cabeçalho do CSV (F17).
- `mediaResposta`: o valor exato é o mesmo do original (`25.666666666666668`).
- `php -l` nos três arquivos PHP alterados. O código só foi executado em PHP 8.4.

**Não verifiquei:**

- A configuração real do Apache e o caminho `/var/www/painel/tmp` (F6).
- A corrida de export concorrente (F6): é análise do código, não reprodução.
- A rotina noturna, o relatório gerencial e a integração de faturamento, que não estão no pacote. As mudanças em §5 são o que precisa ser checado nelas.
- Comportamento em PHP 7.x. O código usa `??`, tipos nulos e `[$a, $b] = …`, o que já exigia PHP 7.1+ no original.

O banco e os servidores de teste foram desligados ao final; não restou nada apontando para produção.
