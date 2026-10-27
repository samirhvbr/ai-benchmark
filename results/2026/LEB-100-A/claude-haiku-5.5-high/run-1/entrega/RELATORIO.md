# Relatório técnico — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

O sistema é um painel PHP em procedural com mysqli, em produção desde 2013. Encontrei problemas graves de segurança: credenciais de produção no código, SQL injection na busca, controle de acesso que não aplica a regra "cliente só vê o que abriu", XSS refletido e senhas em md5 sem sal. Também há bugs que derrubam a página (divisão por zero) e problemas menores de performance e de integridade do CSV.

Corrigi 12 dos 15 achados dentro do código (`code/`), sem trocar a stack, sem renomear arquivos e sem alterar as assinaturas do manifesto. Três ficaram fora do código: a exposição do diretório de export (F7) depende da configuração do servidor; o limite de tentativas no login (F8) exige armazenamento e política novos; e a precedência do rótulo de prioridade (F15) é regra de negócio. O F1 foi removido do código, mas as credenciais que estiveram no fonte precisam ser rotacionadas de qualquer forma.

Os achados estão em `achados.json`, com os mesmos IDs usados aqui. Os números de linha referem-se à numeração original dos arquivos recebidos.

**Atenção antes do deploy:** a senha do banco `painel` e a chave SMTP precisam ser trocadas, e as novas variáveis `DB_PASS` e `SMTP_API_KEY` precisam estar definidas no ambiente. Sem `DB_PASS`, a conexão falha (ver Seção 5).

---

## 2. Achados (ordem de prioridade para correção)

### F1 — Credenciais de produção embutidas em `config.php`
- **Onde:** `code/config.php`, linhas 11–15. Categoria: segurança. **Severidade: crítica.** **Confiança: 99.**
- **Mecanismo:** `DB_PASS` tem fallback literal (`N3tX@2013!prod`) e `SMTP_API_KEY` é uma constante com o valor escrito no código. Quem tem o fonte, um backup ou uma cópia do repositório tem as duas credenciais. O fallback também faz o sistema funcionar sem variável de ambiente, o que esconde o problema.
- **Impacto:** acesso direto ao banco de produção a partir de qualquer host que alcance o MySQL, e envio de e-mail em nome do ISP com a chave de terceiros.
- **O que fiz:** removi os dois literais. Agora `DB_PASS` e `SMTP_API_KEY` vêm só de `getenv()`, sem valor embutido. Mantive os nomes das constantes, porque outros scripts as usam.
- **Ação fora do código (obrigatória):** rotacionar a senha do usuário `painel` e a chave SMTP, porque os valores já estiveram no fonte.

### F2 — SQL injection no filtro de busca
- **Onde:** `code/lib.php`, `listarChamados`, linhas 78–93 (a concatenação está na linha 82). Categoria: segurança. **Severidade: crítica.** **Confiança: 98.**
- **Mecanismo:** o parâmetro `busca` entra concatenado em `LIKE '%…%'`, sem escape nem bind. Um valor como `x' OR '1'='1' -- ` fecha a string e altera a cláusula `WHERE`. Confirmei no código original: com esse payload, o usuário `ana` recebeu os 5 chamados, embora nenhum título contivesse o termo. Qualquer usuário logado, inclusive cliente, alcança essa injeção por `index.php?busca=`.
- **Impacto:** leitura de dados fora do escopo do cliente. Confirmei a injeção booleana; não testei UNION, mas a exposição de outras tabelas, como `usuarios` com os hashes, fica no mesmo caminho.
- **O que fiz:** consulta preparada com `bind_param`. O termo é envolvido em `%`, e `%`, `_` e `\` são escapados com `addcslashes`, para que a busca seja literal. Essa é uma mudança de comportamento intencional: antes, buscar `%` devolvia tudo. Também troquei o N+1 por `JOIN` (F13), mantendo o formato de retorno.

### F3 — Cliente vê todos os chamados, contra a regra do manifesto
- **Onde:** `code/index.php`, detalhe (linhas 52–53) e listagem (linhas 72–73); `code/lib.php`, `listarChamados` e `verChamado`. Categoria: segurança. **Severidade: alta.** **Confiança: 97.**
- **Mecanismo:** a listagem não filtra por `usuario_id` nem por papel. O detalhe chama `verChamado($id)` e exibe o chamado sem conferir o dono. O manifesto diz que cliente só vê os chamados que abriu. Confirmei: `ana`, dona dos chamados 101, 102 e 105, viu os cinco e abriu o detalhe de 103, que é de `bruno`.
- **Impacto:** qualquer cliente lê título, descrição, status e técnico de chamados alheios. Com IDs sequenciais, a enumeração é trivial.
- **O que fiz:** a regra ficou em `podeVerChamado()` (`lib.php`): técnico vê tudo; cliente só vê o que tem `usuario_id` igual ao dele; papel desconhecido cai no ramo restrito. A listagem usa `array_filter`. No detalhe, chamado não permitido responde `Chamado nao encontrado.`, igual ao inexistente, para não permitir enumeração. As assinaturas de `listarChamados` e `verChamado` foram preservadas, porque relatórios internos as usam sem filtro.
- **Verificado:** `ana` vê 101, 102 e 105; `bruno` vê 103 e 104; `carla` (técnica) vê os cinco. Detalhe de chamado alheio dá "não encontrado".

### F4 — XSS refletido no parâmetro `busca`
- **Onde:** `code/index.php`, linhas 79 e 82. Categoria: segurança. **Severidade: alta.** **Confiança: 95.**
- **Mecanismo:** `$busca` é impresso sem escape dentro de `value="…"` e em `Resultados para:`. Confirmei no original que `busca=<script>…` volta crua no HTML. Um link com o payload enviado a um técnico roda script na sessão dele.
- **Impacto:** execução de JavaScript em usuário autenticado, incluindo técnicos, que podem baixar a base inteira.
- **O que fiz:** `htmlspecialchars()` nos dois pontos. A rota, o nome do parâmetro e o HTML exigido pelo manifesto não mudaram.

### F5 — Export CSV disponível a clientes, com todos os chamados
- **Onde:** `code/index.php`, linhas 44–47; `code/lib.php`, `exportarCsv`. Categoria: segurança. **Severidade: alta.** **Confiança: 95.**
- **Mecanismo:** `index.php?export=csv` chama `exportarCsv()` para qualquer sessão válida, sem checar papel. A função faz `SELECT *` de toda a tabela. Confirmei: `ana` recebeu o CSV com os cinco chamados, inclusive os de `bruno`.
- **Impacto:** um cliente baixa a base inteira de chamados com uma requisição.
- **O que fiz:** só técnico recebe o CSV; cliente recebe `403 Acesso negado.`. Não criei um export filtrado por dono, porque o manifesto não define essa funcionalidade para cliente. Se o negócio quiser, é uma função nova.

### F6 — Senhas em md5 sem sal
- **Onde:** `code/lib.php`, `autenticar`, linhas 13–21 (md5 nas linhas 15–16); `code/schema.sql`, linha 7 (`CHAR(32)`). Categoria: segurança. **Severidade: alta.** **Confiança: 97.**
- **Mecanismo:** md5 é rápido e sem sal. Quem obtém a tabela `usuarios` (um backup, ou a injeção do F2) quebra as senhas por dicionário em segundos. No `seed.sql`, `senha123` e `tecmaster` geram hashes idênticos para usuários diferentes.
- **Impacto:** recuperação das senhas de todos os usuários e reaproveitamento delas em outros sistemas.
- **O que fiz:** `autenticar` aceita `password_hash` (detectado com `password_get_info`). Um hash md5 legado ainda é aceito e é regravado com `password_hash` no primeiro login bem-sucedido, por UPDATE preparado dentro de `try/catch`. O `schema.sql` passa a ter `VARCHAR(255)`. A migração da base é `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`.
- **Verificado:** com a coluna ainda `CHAR(32)`, o código novo continua logando e mantém o md5; a regravação falha em silêncio e o login não quebra. Depois do `ALTER`, a senha é regravada com `$2y$` no login seguinte. A assinatura e o retorno de `autenticar()` foram preservados.

### F7 — Export grava todos os chamados em diretório possivelmente público
- **Onde:** `code/config.php`, linha 18 (`EXPORT_DIR = '/var/www/painel/tmp'`); `exportarCsv` grava `chamados.csv` nesse diretório. Categoria: segurança. **Severidade: alta.** **Confiança: 60.**
- **Mecanismo:** o app está em `/var/www/painel`, então, se `tmp/` estiver dentro do docroot e não houver negação no servidor, `https://<host>/painel/tmp/chamados.csv` é baixável sem login, com a base inteira. Não consigo ver a configuração do servidor daqui, por isso a confiança é moderada.
- **O que fiz:** nada no código. Mudar onde o arquivo é gravado depende de como a rotina noturna o lê. Mantive a gravação porque o manifesto diz que `EXPORT_DIR` é onde os relatórios exportados são gravados.
- **Ação de operação:** confirmar o docroot e negar acesso a `tmp/` (ou mover `EXPORT_DIR` para fora do docroot).

### F8 — Login sem limite de tentativas
- **Onde:** `code/index.php`, linhas 21–29. Categoria: segurança. **Severidade: média.** **Confiança: 80.**
- **Mecanismo:** o fluxo de login aceita tentativas ilimitadas e não registra nem atrasa falhas por usuário ou IP. Com logins previsíveis (`ana`, `carla`) e senhas comuns, o teste é barato.
- **Impacto:** força bruta ou credential stuffing contra clientes e técnicos.
- **O que fiz:** nada. Exige armazenamento de contagem (tabela nova ou APCu) e uma política de bloqueio que o negócio precisa decidir. Adicionar isso agora seria dependência ou schema novo, fora do escopo.

### F9 — CSV injection
- **Onde:** `code/lib.php`, `exportarCsv`, linhas 138–144 (título na linha 140). Categoria: segurança. **Severidade: média.** **Confiança: 65.**
- **Mecanismo:** títulos abertos pelo cliente que começam com `=`, `+`, `-` ou `@` vão para o CSV crus. Ao abrir no Excel ou Calc, viram fórmula (ex.: `=HYPERLINK(...)` para exfiltrar dados). Confirmei que o título `=HYPERLINK(...)` saía sem nenhum prefixo.
- **Impacto:** um cliente grava um título que executa fórmula na máquina do técnico que exportar.
- **O que fiz:** helper `protegerCelula()` prefixa `'` em títulos que começam com esses caracteres. Apliquei só ao título, porque o nome do técnico vem do cadastro. Efeito colateral aceito: títulos que começam com `-` (ex.: `-- sem sinal`) ganham um apóstrofo.

### F10 — Divisão por zero em `mediaResposta`
- **Onde:** `code/lib.php`, linhas 107–117 (divisão na linha 116). Categoria: bug. **Severidade: média.** **Confiança: 95.**
- **Mecanismo:** sem nenhuma linha com `minutos_resposta` preenchido, `$qtd` é 0 e `$soma / $qtd` lança `DivisionByZeroError` no PHP 8. Confirmei no original: com todos os `minutos_resposta` nulos, a página principal retorna HTTP 500 com `Division by zero`.
- **Impacto:** a listagem inteira quebra numa instalação nova ou sem nenhuma primeira resposta registrada.
- **O que fiz:** retorna `0.0` quando `$qtd === 0`. Verificado: a página responde 200 com `Tempo medio de 1a resposta: 0 min`.

### F11 — Export não atômico: truncamento em leituras concorrentes
- **Onde:** `code/lib.php`, `exportarCsv`, linhas 123–151 (`fopen(..., 'w')` na linha 126, `readfile` na linha 150). Categoria: bug. **Severidade: média.** **Confiança: 70.**
- **Mecanismo:** duas exportações simultâneas abrem o mesmo `chamados.csv` com `'w'`. O segundo `fopen` trunca o arquivo no meio da leitura do primeiro, e o download sai incompleto. Um leitor externo do mesmo arquivo pode pegar um arquivo parcial.
- **Impacto:** download de CSV incompleto. Não há perda de dados na base.
- **O que fiz:** o conteúdo é montado em `php://temp`, gravado em arquivo temporário com nome único e movido com `rename()` (atômico no mesmo diretório), e enviado pela saída com o mesmo cabeçalho. O formato não muda.

### F12 — `formatarStatus` rotula qualquer valor fora de 1 e 2 como "Resolvido"
- **Onde:** `code/lib.php`, linhas 26–35 (o `else` original, linhas 32–33). Categoria: bug. **Severidade: baixa.** **Confiança: 70.**
- **Mecanismo:** o `else` final devolve `Resolvido` para qualquer valor que não seja 1 ou 2, inclusive 0, negativos ou lixo numa coluna `TINYINT`. O relatório gerencial casa pelo texto, então a contagem de resolvidos infla em silêncio.
- **O que fiz:** só o valor 3 vira `Resolvido`; o resto devolve `Desconhecido`. Os rótulos 1, 2 e 3 do contrato de valor não mudaram.

### F13 — N+1 no nome do técnico
- **Onde:** `code/lib.php`, `listarChamados`, linhas 88–90 (chamada na linha 89), e `exportarCsv`, linha 137. Categoria: performance. **Severidade: baixa.** **Confiança: 95.**
- **Mecanismo:** `tecnicoNome()` roda dentro do `while`. Cada chamado gera um `SELECT` a mais; uma página ou um export custa 1 + N consultas.
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(t.nome, '-')`. Reproduz o retorno anterior, `-` quando não há técnico ou o id não existe. `tecnicoNome()` continua definida, mas não é mais usada em `lib.php`.

### F14 — Sessão não regenerada no login (session fixation)
- **Onde:** `code/index.php`, linhas 20–25. Categoria: segurança. **Severidade: baixa.** **Confiança: 45.**
- **Mecanismo:** o ID de sessão criado antes do login continua válido depois dele. O PHP aceita ID vindo do cookie por padrão, então quem consegue definir o `PHPSESSID` da vítima pode esperar o login e usar a mesma sessão. Exige injeção de cookie, por isso a confiança é moderada.
- **O que fiz:** `session_regenerate_id(true)` antes de gravar `uid` e `papel`. Verificado: o cookie muda após o login.

### F15 — Chamado crítico sem resposta aparece como "Aguardando 1a resposta"
- **Onde:** `code/lib.php`, `rotuloPrioridade`, linhas 40–59 (retorno nas linhas 56–58). Categoria: bug. **Severidade: baixa.** **Confiança: 40.**
- **Mecanismo:** com `$minutos` nulo, a função devolve `Aguardando 1a resposta` antes de olhar a prioridade. O chamado 104 da seed (prioridade 4, crítica, sem técnico e sem resposta) aparece como um "Aguardando" comum. Não sei se essa precedência é intencional.
- **O que fiz:** nada. A ordem entre SLA e prioridade é regra de negócio que não está no manifesto, e o rótulo é mostrado na tela. Precisa de decisão do responsável pelo produto.

---

## 3. Decisões: o que deixei como está, e por quê

- **`tecnicoNome()`** continua definida, mesmo sem uso em `lib.php`. Não está no manifesto, mas pode ser chamada por relatórios internos.
- **Arquitetura** (`lib.php` mistura acesso a dados, regra e sessão; `index.php` monta o HTML). Refatorar seria reescrever, o que a tarefa proíbe. Mantive a estrutura e evoluí dentro dela.
- **`mediaResposta` continua visível a clientes.** É uma média agregada e não identifica chamados alheios. Se o negócio quiser esconder, é mudança de regra.
- **Cliente não recebe CSV (F5).** Decisão minha, e a única que muda comportamento de cliente além da visibilidade. Se clientes precisarem do próprio histórico, é preciso uma função nova com filtro.
- **Cabeçalho do CSV com `"Aberto em"` entre aspas.** O `fputcsv` sempre citou esse campo, inclusive no original. O manifesto diz "cabeçalho exato"; o conteúdo lógico bate, mas um consumidor que compare bytes pode estranhar. Mantive a saída atual para não mudá-la sem pedido.
- **CSRF no login e logout.** O risco de login CSRF é baixo e a correção mexe no HTML do formulário sem necessidade clara. Logout não existe e não criei, porque seria funcionalidade nova.
- **Limite de login (F8) e `EXPORT_DIR` (F7)** dependem de armazenamento novo ou de configuração de servidor que não está no código entregue.
- **`rotuloPrioridade` (F15)**: a precedência é regra de negócio, e não sei a intenção.
- **`verChamado`** concatena um `int` já tipado e convertido com `(int)`. Não há vetor de injeção, então não mexi.
- **`.leb-pacote.sha256` não foi atualizado.** Ele registra o pacote entregue, e os hashes de `code/` mudam por causa desta correção.
- **Efeito colateral aceito em F2 e F9:** buscar `%` ou `_` deixa de ser curinga, e títulos que começam com `-` ganham apóstrofo no CSV.

---

## 4. Verificação

Testes em ambiente local, não em produção: MariaDB 11.8 (com `STRICT_TRANS_TABLES`) em instância temporária, PHP 8.4 com servidor embutido, com uma cópia de `code/` fora do pacote e `EXPORT_DIR` apontado para um diretório de teste. Comparei o código original e o alterado com as mesmas requisições.

- Visibilidade por papel (F3), XSS (F4), SQL injection (F2), export por papel (F5): reproduzido no original, corrigido no novo.
- Divisão por zero (F10): 500 no original, 200 no novo.
- Login: md5 legado aceito, regravado para `$2y$`, senha errada e usuário inexistente recusados, sessão regenerada (F6, F14).
- Compatibilidade com a base antiga: com `CHAR(32)`, login funciona e o md5 é mantido. Após o `ALTER`, o schema novo e a seed carregam limpo.
- Assinaturas públicas comparadas por reflection (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `tecnicoNome`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`): idênticas.
- `rotuloPrioridade` não foi alterada; `formatarStatus` mantém os rótulos 1, 2 e 3.
- CSV: cabeçalho e ordenação por `id` mantidos; proteção de fórmula aplicada e verificada; arquivo gravado e saída idênticos byte a byte.

Limites: não testei em PHP 7.x, nem o uso de `EXPORT_DIR` e da rotina noturna em produção, nem a configuração do servidor web (F7).

---

## 5. Ordem de deploy

1. Rotacionar a senha do usuário `painel` e a chave SMTP.
2. Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do PHP antes de subir o código. Sem `DB_PASS`, `mysqli` falha e a página responde "Falha ao conectar ao banco."
3. Aplicar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. A ordem com o código não importa: com o código antigo, o `VARCHAR` é aceito; com o código novo e a coluna antiga, a regravação é pulada sem quebrar o login.
4. Publicar `code/`.
5. Negar acesso web a `tmp/` ou mover `EXPORT_DIR` (F7).
6. Decidir F8 (limite de login) e F15 (precedência do rótulo) com o responsável pelo produto.
