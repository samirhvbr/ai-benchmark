# Relatório técnico — LEB-100-A (Painel de Chamados NetX ISP)

## Resumo

É um sistema pequeno (3 arquivos PHP + mysqli) com falhas graves de segurança. Elas se
concentram em dois eixos:

1. **Entrada não tratada.** A busca vai concatenada no SQL (injeção) e é ecoada crua no HTML (XSS refletido).
2. **Ausência total de autorização.** A sessão grava o `papel`, mas ninguém o consulta. Qualquer
   cliente logado lê os chamados de todos os clientes pela listagem, pelo detalhe (`?ver=`) e pelo
   CSV (`?export=csv`). Isso viola a regra de negócio declarada no manifesto.

Há também segredos de produção no código-fonte, senhas em MD5 sem salt, um export que grava a base
inteira num arquivo fixo dentro de `/var/www`, uma divisão por zero que derruba a página
principal e um padrão N+1 nas consultas.

Corrigi tudo o que dava para corrigir sem tocar na superfície pública. As assinaturas, rotas, rótulos,
o CSV e o HTML continuam iguais. Validei isso subindo `schema.sql` + `seed.sql` num MariaDB local e
comparando o código original com o corrigido:

- **Funções públicas** (`autenticar`, `listarChamados`, `verChamado`, `mediaResposta`,
  `tecnicoNome`, `exportarCsv`): os retornos são **byte a byte idênticos** (`var_export`), inclusive
  os tipos (strings, como antes).
- **Técnico**: as rotas `index.php`, `?busca=`, `?ver=` e `?export=csv` produzem saída **idêntica**
  à original.
- **Cliente**: passa a ver só os próprios chamados. `ana` vê 101, 102 e 105; `bruno` vê 103 e 104.
- **Ataques**: a injeção `x' OR 1=1 -- ` e o XSS `"><script>` deixaram de funcionar.

A numeração de linhas abaixo é a **original** dos arquivos recebidos.

---

## Achados (em ordem de prioridade de correção)

### F1 — Injeção de SQL na busca (`code/lib.php:82`)
- **O que é:** `listarChamados` monta `WHERE titulo LIKE '%" . $busca . "%'` com o valor cru de
  `$_GET['busca']` (vem de `index.php:72-73`).
- **Mecanismo:** `?busca=x' OR 1=1 -- ` fecha o literal e anula o filtro. Testei no original: um
  cliente recebeu os 5 chamados. Com `UNION SELECT login, senha, ...` dá para extrair a tabela
  `usuarios`, e os hashes MD5 (F8) caem em segundos. A conexão usa o usuário `painel`, com
  privilégio sobre a base inteira.
- **Impacto / severidade:** **crítica**. Leitura arbitrária do banco por qualquer usuário logado.
- **Confiança:** 98.
- **Feito:** o termo agora passa por `$db->real_escape_string()`, numa conexão com
  `set_charset('utf8mb4')` explícito, o que torna o escape seguro.
  - **Por que não usei prepared statement:** testei e, com mysqlnd, ele devolve `id`, `status`,
    `prioridade` etc. como `int`, enquanto `query()` devolve strings. Isso mudaria o formato de
    retorno de `listarChamados`, que outros scripts consomem.
  - **Filtro de dono:** o `usuario_id` é um `int` tipado, concatenado sem risco.

### F2 — Detalhe de chamado sem verificação de dono (IDOR) (`code/index.php:52-58`, `code/lib.php:98-102`)
- **O que é:** `?ver=<id>` chama `verChamado($db, id)` e mostra o chamado de qualquer pessoa.
- **Mecanismo:** `$papel` é lido em `index.php:39` e nunca usado. `verChamado` não filtra por
  `usuario_id`. Testei no original: a cliente `ana` (id 1) abriu o chamado 104 do `bruno`
  ("Fatura em duplicidade", com descrição). Os IDs são sequenciais, então dá para enumerar tudo.
- **Impacto / severidade:** **alta**. Vazamento de dados de todos os clientes do ISP, inclusive
  descrições de cobrança.
- **Confiança:** 97.
- **Feito:** em `index.php`, `$donoFiltro = $papel === 'tecnico' ? null : $uid`. Se o chamado
  existe mas não pertence ao cliente, ele recebe a mesma mensagem "Chamado nao encontrado." (não
  revela a existência do id). O técnico continua vendo qualquer chamado.
  - **Fail-closed:** qualquer papel diferente de `tecnico` é tratado como cliente.
  - **`verChamado` intacta:** a assinatura é pública e o comportamento dela (devolver por id) é
    usado por rotinas internas, então a checagem ficou no ponto de entrada web.

### F3 — Listagem e busca mostram chamados de todos os clientes (`code/index.php:72-73`, `code/lib.php:78-93`)
- **O que é:** a listagem chama `listarChamados($db, $busca)`, que lista a tabela inteira.
- **Mecanismo:** não existe nenhum filtro por `usuario_id` em lugar nenhum do caminho. A cliente
  `ana` via na tela os chamados 103 e 104 do `bruno`.
- **Impacto / severidade:** **alta**. Mesma violação da regra de negócio, sem precisar de esforço.
- **Confiança:** 97.
- **Feito:** criei `listarChamadosVisiveis($db, $busca, ?int $donoId)` em `lib.php`.
  - `listarChamados($db, $busca)` agora só delega com `null`. A assinatura e o retorno são
    idênticos, para os consumidores externos que querem a lista completa.
  - `index.php` usa a variante com `$donoFiltro`.

### F4 — Export CSV entrega a base inteira a qualquer cliente (`code/index.php:44-46`, `code/lib.php:123-151`)
- **O que é:** `?export=csv` chama `exportarCsv($db)` para qualquer sessão válida.
- **Mecanismo:** o link "Exportar CSV" aparece para todos e o export não filtra nada. Um cliente
  baixa num clique todos os chamados, com títulos e técnicos.
- **Impacto / severidade:** **alta**. Exfiltração em massa trivial.
- **Confiança:** 95.
- **Feito:** criei `exportarCsvVisiveis($db, ?int $donoId)`.
  - `exportarCsv($db)` delega com `null`: CSV completo, mesmo cabeçalho, mesma ordem por `id`.
  - `index.php` passa `$donoFiltro`: o técnico baixa tudo, o cliente baixa só os próprios chamados.
  - Não proibi o export para clientes, porque isso esconderia dados a que eles têm direito.

### F5 — XSS refletido no campo de busca (`code/index.php:79` e `code/index.php:82`)
- **O que é:** `$busca` é ecoado sem escape dentro de `value="..."` e em "Resultados para: ...".
- **Mecanismo:** um link como `index.php?busca="><script>...</script>` executa script no navegador
  de quem clicar (por exemplo, um técnico). O cookie de sessão não tinha `HttpOnly` (F9), então o
  script consegue roubar a sessão do técnico e, com ela, acesso a tudo.
- **Impacto / severidade:** **alta**.
- **Confiança:** 97.
- **Feito:** `htmlspecialchars($busca, ENT_QUOTES)` nos dois pontos. O resto da página já escapava
  `titulo`, `descricao` e `tecnico_nome`. `id` e os rótulos são valores internos (inteiro do banco
  e strings fixas).

### F6 — Credenciais de produção embutidas no código (`code/config.php:12`, `code/config.php:15`)
- **O que é:** a senha do banco de produção (`N3tX@2013!prod`) está como *fallback* do `getenv`,
  e a chave da API de e-mail (`SMTP_API_KEY`) está literal.
- **Mecanismo:** qualquer pessoa com acesso ao repositório, a um backup ou a um vazamento de fonte
  tem as duas credenciais. O fallback ainda faz o sistema rodar "silenciosamente" com a senha de
  produção em qualquer ambiente que não defina `DB_PASS`.
- **Impacto / severidade:** **alta**. Acesso direto ao banco (se alcançável na rede) e uso
  indevido do serviço de e-mail transacional, por exemplo para phishing com o domínio do ISP.
- **Confiança:** 95.
- **Feito:** os dois valores agora vêm só do ambiente (`getenv(...) ?: ''`). Os nomes das
  constantes continuam os mesmos.
  - **Obrigatório na implantação:** rotacionar a senha do banco e a chave SMTP (as atuais devem ser
    tratadas como comprometidas) e definir `DB_PASS` e `SMTP_API_KEY` no ambiente do servidor web e
    das rotinas que incluem `config.php`.

### F7 — Export grava a base inteira num arquivo fixo e compartilhado (`code/lib.php:125-150`, `code/config.php:18`)
- **O que é:** `exportarCsv` escrevia `EXPORT_DIR/chamados.csv` (`/var/www/painel/tmp`), fechava o
  arquivo e depois fazia `readfile()`.
- **Mecanismo:** são três problemas.
  1. **Arquivo exposto:** o arquivo fica no disco com todos os chamados, dentro da árvore
     `/var/www/painel`. Se o vhost serve esse diretório, `GET /tmp/chamados.csv` baixa tudo
     **sem login**.
  2. **Corrida entre requisições:** o nome é fixo para todo mundo. Duas exportações simultâneas
     truncam e reescrevem o mesmo arquivo, e uma pode receber o conteúdo parcial ou o da outra.
     Depois da correção F4, isso faria um cliente receber o CSV de outro.
  3. **Falhas silenciosas:** se `fopen` falha, a função retorna sem cabeçalho nem conteúdo (resposta
     200 vazia). Se a consulta falha, o *handle* fica aberto e o arquivo fica só com o cabeçalho.
- **Impacto / severidade:** **média**.
- **Confiança:** 70. A exposição pública depende da configuração do servidor, que não recebi. A
  corrida é certa.
- **Feito:** o CSV agora é escrito direto em `php://output`, com os mesmos cabeçalhos HTTP, o mesmo
  formato e o mesmo `fputcsv` (com o `escape` explícito, para manter o mesmo resultado e evitar o
  aviso de depreciação do PHP 8.4). Isso é o que o manifesto declara: "escreve o CSV na saída".
  A constante `EXPORT_DIR` continua definida. Ver **Decisões** sobre a rotina noturna.

### F8 — Senhas com MD5 sem salt (`code/lib.php:15-16`, `code/schema.sql:7`)
- **O que é:** `autenticar` compara `md5($senha)` com a coluna `CHAR(32)`.
- **Mecanismo:** MD5 é rápido e sem salt. Senhas iguais geram hashes iguais (ana e bruno têm o
  mesmo hash) e tabelas prontas revertem senhas comuns na hora. Somado à F1, a injeção entrega os
  hashes e as senhas saem logo em seguida.
- **Impacto / severidade:** **alta**.
- **Confiança:** 95.
- **Feito:** **não corrigido no código.** A migração correta (`password_hash`/`password_verify`,
  com re-hash no próximo login) exige alargar a coluna para `VARCHAR(255)` **antes** do deploy.
  - **Risco de fazer sem migrar:** se o código subir sem o `ALTER`, gravar um hash bcrypt (60
    caracteres) num `CHAR(32)` dá erro no modo estrito (login quebra) ou trunca no não-estrito
    (bloqueia o usuário para sempre).
  - **Plano proposto:**
    1. `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`.
    2. Em `autenticar`, buscar por `login`. Se o hash começa com `$`, usar `password_verify`. Senão,
       usar `hash_equals(md5($senha), $hash)` e, havendo sucesso, gravar `password_hash($senha)`.
    3. Depois de um prazo, forçar troca de senha para quem não migrou.
  - A assinatura de `autenticar` não muda.

### F9 — Fixação de sessão e cookie sem `HttpOnly`/`SameSite` (`code/index.php:15`, `code/index.php:23-25`)
- **O que é:** o login grava `uid`/`papel` na sessão existente sem trocar o id, e o cookie usa os
  padrões do PHP.
- **Mecanismo:** quem consegue plantar um id de sessão no navegador da vítima continua dono da
  sessão depois que ela loga. Isso é possível com `session.use_strict_mode` desligado (o padrão),
  via subdomínio ou rede. Sem `HttpOnly`, o XSS da F5 lê `document.cookie`.
- **Impacto / severidade:** **média**.
- **Confiança:** 80.
- **Feito:**
  - `session_regenerate_id(true)` no login bem-sucedido.
  - `session_set_cookie_params` com `httponly`, `samesite=Lax` e `secure` quando a requisição é
    HTTPS. O `secure` é condicional para não quebrar o acesso caso o painel ainda rode em HTTP
    interno.

### F10 — Divisão por zero derruba a página principal (`code/lib.php:116`)
- **O que é:** `mediaResposta` retorna `$soma / $qtd` sem checar `$qtd`.
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` (base nova, todos aguardando 1ª resposta,
  expurgo), o PHP 8 lança `DivisionByZeroError`. Como `index.php:74` chama a função em toda
  listagem, a tela principal inteira passa a dar erro 500. Reproduzi: o original lança
  `DivisionByZeroError`.
- **Impacto / severidade:** **média**. Indisponibilidade total da listagem.
- **Confiança:** 95.
- **Feito:** retorna `0.0` quando não há amostras. O tipo de retorno (`float`) é o mesmo, e para
  bases com dados o valor é idêntico (`25.666666666666668` antes e depois).

### F11 — Consultas N+1 para o nome do técnico (`code/lib.php:89`, `code/lib.php:137`)
- **O que é:** `listarChamados` e `exportarCsv` chamam `tecnicoNome()` para cada chamado, e cada
  chamada é um `SELECT` separado.
- **Mecanismo:** com N chamados são N+1 idas ao banco por página e por exportação. A listagem não
  tem paginação, então isso cresce linearmente com o histórico desde 2013.
- **Impacto / severidade:** **média** (performance).
- **Confiança:** 95.
- **Feito:** troquei por um único `LEFT JOIN usuarios ... COALESCE(t.nome, '-') AS tecnico_nome`.
  O resultado é idêntico: mesmas chaves (`c.*` + `tecnico_nome` no fim), `'-'` quando não há
  técnico e valores em string. `tecnicoNome()` foi mantida sem alteração, porque pode ser usada
  por outros scripts.

### F12 — `mediaResposta` traz todas as linhas para o PHP só para somar (`code/lib.php:109-115`)
- **Mecanismo:** transfere uma linha por chamado respondido a cada carregamento da listagem.
- **Impacto / severidade:** **baixa** (performance).
- **Confiança:** 90.
- **Feito:** `SUM()`/`COUNT()` no banco e a divisão em PHP. Não usei `AVG()` de propósito: o
  `AVG` do MySQL arredonda a 4 casas, e o valor devolvido aos consumidores mudaria.

### F13 — Injeção de fórmula no CSV (`code/lib.php:138-144`)
- **O que é:** títulos escritos por clientes vão sem tratamento para o CSV.
- **Mecanismo:** um cliente abre um chamado com título `=HYPERLINK("http://...","clique")` ou
  `=cmd|...`. Quando o técnico ou o gerente abre o CSV no Excel/LibreOffice, a célula é
  interpretada como fórmula.
- **Impacto / severidade:** **média**.
- **Confiança:** 70. Depende de o CSV ser aberto em planilha, o que é provável para um "relatório".
- **Feito:** **não corrigido.** A mitigação usual é prefixar `'` em valores que começam com
  `= + - @`, mas isso altera os dados do CSV, que é contrato e alimenta a integração de faturamento
  e a rotina noturna. Precisa ser combinado com os consumidores.

### F14 — Entradas em formato de array derrubam o script (`code/index.php:22`, `code/index.php:72`)
- **Mecanismo:** `login[]=x` ou `busca[]=x` fazem chegar um array a `autenticar(string ...)` ou a
  `listarChamados(..., string ...)`, e o PHP lança `TypeError` fatal (erro 500, possivelmente com
  *stack trace* se `display_errors` estiver ligado).
- **Impacto / severidade:** **baixa**.
- **Confiança:** 90.
- **Feito:** `is_string()` antes de usar. Busca inválida vira busca vazia e login inválido é
  ignorado.

### F15 — Autorização inexistente na camada de dados; regra de acesso espalhada (`code/lib.php:78-151`, `code/index.php:38-39`)
- **O que é:** as funções de `lib.php` não conhecem o usuário. A regra de visibilidade só pode
  existir no chamador, e `index.php` mistura roteamento, consulta e HTML num arquivo só.
- **Mecanismo:** cada novo ponto de entrada que use `lib.php` precisa lembrar de filtrar. Foi
  exatamente esse esquecimento que gerou F2, F3 e F4.
- **Impacto / severidade:** **média** (arquitetura).
- **Confiança:** 75.
- **Feito:** **parcial.** Centralizei a decisão em `$donoFiltro`, num único lugar de `index.php`, e
  criei variantes `*Visiveis` com o filtro de dono em `lib.php`. Não separei camadas nem templates;
  ver **Decisões**.

### F16 — `formatarStatus` rotula qualquer valor desconhecido como "Resolvido" (`code/lib.php:32-34`)
- **Mecanismo:** o `else` final captura 0, 4, 99 etc. A coluna `status` é `TINYINT` sem `CHECK`.
  Um status inválido (bug de outra rotina, valor novo) aparece como "Resolvido" na tela, no CSV e
  no relatório gerencial, mascarando chamados pendentes.
- **Impacto / severidade:** **baixa**.
- **Confiança:** 60. Só é problema se valores fora de 1–3 existirem.
- **Feito:** **não corrigido.** O manifesto fixa os rótulos de 1–3, e o relatório gerencial casa
  por texto. Introduzir um rótulo novo ("Desconhecido") mudaria contagens de um consumidor externo
  sem aviso. Recomendo antes auditar `SELECT DISTINCT status FROM chamados` e adicionar um
  `CHECK (status IN (1,2,3))`.

### F17 — `rotuloPrioridade`: rótulos ambíguos e lógica aninhada (`code/lib.php:40-59`)
- **Mecanismo:** são quatro problemas.
  1. **Crítico sem resposta não se destaca:** um chamado de prioridade 4 sem resposta (ex.: 104,
     "Fatura em duplicidade", crítica, aberta) aparece como "Aguardando 1a resposta", igual a um de
     prioridade baixa. Justamente o caso mais urgente.
  2. **Crítico rebaixado:** prioridade 4 dentro do SLA aparece como "Alto - dentro do SLA".
  3. **Baixa vira "Normal":** prioridade 1 aparece como "Normal".
  4. **Legibilidade:** quatro níveis de `if` aninhado escondem esses casos.
- **Impacto / severidade:** **baixa** (qualidade / possível bug de regra).
- **Confiança:** 50. Pode ser regra intencional; não há especificação.
- **Feito:** **não corrigido.** Os rótulos são saída visível e possivelmente consumida.
  Reestruturar com *guard clauses* sem mudar a saída seria só cosmético, e mudar a saída exige
  decisão do produto.

### F18 — Sem proteção contra força bruta no login (`code/index.php:20-35`)
- **Mecanismo:** tentativas ilimitadas, sem atraso nem bloqueio. Combinado com MD5 (F8) e senhas
  fracas do seed (`senha123`), facilita a adivinhação.
- **Impacto / severidade:** **baixa**.
- **Confiança:** 70.
- **Feito:** **não corrigido.** Exige armazenamento de tentativas (tabela nova ou cache), o que é
  fora do escopo mínimo. Recomendo resolver junto com a F8.

---

## Decisões — o que deliberadamente NÃO mudei

1. **Assinaturas públicas.** Nenhuma assinatura, rota, rótulo, cabeçalho de CSV ou estrutura de
   HTML mudou. Em vez de acrescentar parâmetros opcionais às funções públicas, criei funções novas
   (`listarChamadosVisiveis`, `exportarCsvVisiveis`), e as públicas delegam a elas mantendo o
   comportamento de antes. Isso vale também para rotinas internas que chamam
   `listarChamados`/`exportarCsv` esperando a base completa.
2. **Prepared statements em `listarChamados`/`verChamado`.** Testei e descartei: com mysqlnd, os
   valores numéricos voltam como `int` em vez de `string`, uma mudança de formato de retorno
   detectável pelos consumidores (`===`, JSON).
   - Em `listarChamados`, usei `real_escape_string` com charset explícito.
   - `verChamado` e `tecnicoNome` já eram seguras (parâmetro `int` tipado) e ficaram como estavam.
   - `autenticar` já usava prepared statement e não mudou.
3. **Migração de senha (F8).** Não entra sem o `ALTER TABLE` coordenado. O código "meio migrado" é
   pior que o atual (pode bloquear usuários). O plano está na F8.
4. **Fim do arquivo `EXPORT_DIR/chamados.csv` (F7).** O manifesto define o efeito de
   `exportarCsv` como escrever o CSV **na saída**. O arquivo em disco era um efeito colateral não
   documentado, inseguro e com corrida.
   - **Risco residual:** se a rotina noturna lê esse arquivo depois de chamar a função, ela vai
     precisar capturar a saída (`ob_start()`) ou gravar onde quiser. É preciso confirmar com o dono
     da rotina antes do deploy.
   - Mantive a constante `EXPORT_DIR` para não quebrar quem a referencia.
5. **Segredos (F6).** Removi os literais. Isso exige configurar o ambiente e rotacionar as
   credenciais no deploy. Sem `DB_PASS` no ambiente, a aplicação mostra "Falha ao conectar ao
   banco." em vez de usar a senha vazada. Considero esse comportamento correto: a senha antiga
   precisa ser invalidada de qualquer forma.
6. **Injeção de fórmula no CSV (F13), "Resolvido" como padrão (F16) e rótulos de prioridade
   (F17).** Todos alteram dados ou rótulos que consumidores externos leem. Ficam reportados para
   decisão de produto e combinação com os consumidores.
7. **Curingas `%` e `_` na busca.** Continuam funcionando como curingas do `LIKE`, como antes (o
   escape só neutraliza a injeção). Escapá-los mudaria resultados de busca de quem já usa `%`.
   Também não mexi na busca `LIKE '%termo%'`, que não usa índice: com o volume de um painel de
   chamados não compensa um índice FULLTEXT agora.
8. **Média de SLA exibida a clientes.** `mediaResposta` continua global (de todos os chamados)
   também na tela do cliente. É um indicador agregado, não expõe chamado de ninguém, e a assinatura
   não tem como receber o usuário.
9. **Arquitetura (F15).** Não separei `index.php` em controlador/templates nem criei camada de
   autorização em `lib.php`, porque seria reescrita. A regra ficou centralizada em uma variável
   (`$donoFiltro`) e em funções de dados que recebem o dono explicitamente.
10. **Fora do escopo mínimo.** Logout, CSRF no login, cabeçalhos de segurança (CSP,
    `X-Frame-Options`) e limite de tentativas (F18) acrescentariam rotas ou infraestrutura. São
    recomendados, mas não são correções do que existe.
