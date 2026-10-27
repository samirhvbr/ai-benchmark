# RELATÓRIO — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

Sistema PHP/MySQL de chamados em produção desde 2013, com três problemas que deixam o banco exposto de fato:

- **Qualquer cliente logado lê todos os chamados** de todos os clientes (listagem, detalhe e CSV). A regra de visibilidade do manifesto existia só como intenção: `$uid` e `$papel` eram gravados na sessão e nunca usados.
- **Injeção SQL** no campo de busca, com leitura da tabela `usuarios`, inclusive os hashes.
- **Credenciais de produção no código** (senha do MySQL e chave SMTP), com fallback ativo.

Também havia XSS refletido na busca, senhas em MD5 sem sal, um CSV gravado em diretório dentro do webroot, e um erro fatal na listagem quando nenhum chamado tem tempo de resposta.

Todos os achados de severidade crítica e alta foram corrigidos no código. Os que dependem de decisão ou de infraestrutura fora deste pacote (rotação de credenciais, limite de tentativas de login, paginação) estão reportados e não foram implementados. As assinaturas públicas do `manifest.md` foram preservadas; `manifest.md`, `seed.sql` e os nomes/caminhos de `code/` não foram alterados.

**Antes de subir para produção há pré-requisitos operacionais (seção 4).** Dois deles são obrigatórios: definir `DB_PASS` no ambiente e rotacionar a senha do MySQL e a chave SMTP, porque elas estão no código que foi entregue.

---

## 2. Achados (em ordem de prioridade de correção)

### F1 — Injeção SQL na busca
- **Onde:** `code/lib.php`, linhas 80–85 (original), função `listarChamados`.
- **Mecanismo:** o termo `$busca`, vindo de `$_GET['busca']`, é concatenado diretamente em `WHERE titulo LIKE '%...%'`, sem escape nem prepared statement. Um cliente logado envia `busca=' OR '1'='1` e recebe a tabela inteira (testado: retornou 5 de 5 linhas no original). Pela mesma brecha, um `UNION SELECT` poderia ler outras tabelas, como `usuarios` com os hashes MD5. Esse payload não foi testado; a injeção booleana acima foi.
- **Impacto:** leitura do banco por qualquer conta, inclusive de cliente, na medida em que o usuário do MySQL tiver permissão de leitura nas tabelas (não verifiquei os privilégios de produção).
- **Severidade:** crítica. **Confiança:** 99 (reproduzido no original).
- **O que fiz:** a busca passou a usar prepared statement (`consultarChamados`, `lib.php`). O termo é escapado para `LIKE` (`%`, `_` e `\` viram literais), então a busca é uma substring literal, como descreve o manifesto ("filtrada por título"). Testado: `' OR '1'='1` retorna 0 linhas; `%` não vira curinga.

### F2 — Credenciais de produção no código
- **Onde:** `code/config.php`, linhas 12 e 15.
- **Mecanismo:** `DB_PASS` tinha como fallback a senha real do usuário `painel` (`N3tX@2013!prod`), e `SMTP_API_KEY` era um literal. Quem tiver o código (repositório, backup, cópia de `code/`) tem acesso ao banco de produção e à conta de envio de e-mail. O fallback também fazia o sistema funcionar sem configuração de ambiente, o que escondia o problema.
- **Impacto:** acesso ao banco de produção e uso indevido da conta SMTP por quem tiver acesso ao código ou a cópias dele.
- **Severidade:** crítica. **Confiança:** 98.
- **O que fiz:** as duas constantes passaram a vir só de `getenv()`, sem fallback literal. Os nomes das constantes foram mantidos, porque outros scripts podem usá-las.
- **Ação obrigatória fora do código:** rotacionar a senha do MySQL e a chave SMTP. Elas já estão no código entregue, e eu não tenho acesso para rotacioná-las.

### F3 — Regra de visibilidade não aplicada (IDOR na listagem e no detalhe)
- **Onde:** `code/index.php`, linhas 52–73 (detalhe e listagem); `code/lib.php`, linhas 78–102.
- **Mecanismo:** no login, `$_SESSION['uid']` e `$_SESSION['papel']` são gravados, mas nenhuma rota os usa para filtrar. `listarChamados` não tem filtro por usuário e `verChamado($db, $id)` é chamado com o id da URL sem checar dono. Um cliente (ana, id 1) abre `index.php?ver=103` e vê o chamado do bruno, e a listagem mostra os chamados de todos (testado).
- **Impacto:** cada cliente lê títulos e descrições de chamados de outros clientes. O manifesto diz que isso é violação da regra de negócio ("um cliente só pode ver os chamados que ele mesmo abriu").
- **Severidade:** crítica. **Confiança:** 97 (reproduzido).
- **O que fiz:**
  - `listarChamadosVisiveis($db, $uid, $papel, $busca)`: técnico recebe todos; qualquer outro papel recebe só os próprios (fail-closed).
  - `podeVer($papel, $uid, $chamado)` aplicado no detalhe. Um chamado de outro cliente responde "Chamado nao encontrado.", igual a um id inexistente, para não confirmar que ele existe.
  - Técnico continua vendo tudo: a listagem dele ficou idêntica ao original, byte a byte.
  - As assinaturas de `listarChamados` e `verChamado` não mudaram. Os scripts externos (relatório gerencial, rotina noturna) continuam recebendo a lista completa.

### F4 — Exportação CSV entrega a base inteira a qualquer usuário logado
- **Onde:** `code/index.php`, linhas 44–47; `code/lib.php`, linhas 123–151 (`exportarCsv`).
- **Mecanismo:** `index.php?export=csv` chama `exportarCsv($db)`, que faz `SELECT * FROM chamados` sem filtro. Um cliente baixa o CSV com todos os chamados e os técnicos de todos.
- **Impacto:** vazamento da base de chamados inteira com um clique, para qualquer conta de cliente.
- **Severidade:** crítica. **Confiança:** 97.
- **O que fiz:** a rota web usa `exportarCsvVisiveis($db, $uid, $papel)`, que aplica a mesma regra de visibilidade da listagem. `exportarCsv($db)` continua existindo, com a lista completa, para os scripts internos. Testado: o CSV do cliente contém só os chamados dele; o CSV do técnico é idêntico ao original, byte a byte.

### F5 — CSV gravado em diretório do webroot, compartilhado entre requisições
- **Onde:** `code/lib.php`, linhas 125–150; `code/config.php`, linha 18 (`EXPORT_DIR = '/var/www/painel/tmp'`).
- **Mecanismo:** cada exportação grava `chamados.csv` com a base inteira dentro de `/var/www/painel/tmp`, que fica sob o Apache. Se esse diretório for servido pela web, qualquer pessoa baixa `.../tmp/chamados.csv` sem login. Além disso, o nome é fixo: duas exportações simultâneas se sobrescrevem, e um usuário pode baixar o arquivo gerado para outro. A configuração do Apache não está no pacote, então este achado depende dela.
- **Impacto:** vazamento da base sem autenticação, se o diretório for servido; vazamento entre usuários por condição de corrida.
- **Severidade:** crítica (condicional à configuração do servidor). **Confiança:** 75.
- **O que fiz:** `escreverCsv()` escreve direto em `php://output`. Nenhum arquivo é criado. `EXPORT_DIR` ficou definido, sem uso no código.
- **Atenção:** se a rotina noturna lê o arquivo `chamados.csv` em vez de capturar a saída da função, ela vai quebrar. Ver seção 4.

### F6 — XSS refletido na busca
- **Onde:** `code/index.php`, linhas 79–82.
- **Mecanismo:** `$busca` é impresso sem escape em `value="..."` do formulário e em `Resultados para: ...`. Com `busca="><script>alert(1)</script>` o script é injetado (reproduzido no original). A URL pode ser enviada a um técnico, que executa o script com a sessão dele.
- **Impacto:** execução de JavaScript no navegador de um usuário logado, incluindo técnicos, com acesso à sessão e às ações deles.
- **Severidade:** alta. **Confiança:** 95.
- **O que fiz:** `htmlspecialchars()` nas duas saídas. Testado: o payload aparece escapado.

### F7 — Senhas em MD5 sem sal
- **Onde:** `code/lib.php`, linhas 15–17 (`autenticar`); `code/schema.sql`, linha 7 (`senha CHAR(32)`).
- **Mecanismo:** `md5($senha)` é um hash rápido e sem sal. Um dump da tabela `usuarios` é quebrado por tabela de consulta ou GPU. Os hashes do seed (`e7d80ffeefa212b7c5c55700e4f7193e` = `md5('senha123')`) são exemplo disso.
- **Impacto:** com acesso de leitura ao banco (por exemplo, via F1), as senhas de todos os usuários, inclusive de técnicos, são recuperadas.
- **Severidade:** alta. **Confiança:** 98.
- **O que fiz:**
  - Senhas novas são guardadas com `password_hash()`.
  - Senhas legadas (MD5) continuam aceitas. No primeiro login bem-sucedido, o hash é regravado no formato novo.
  - A regravação só acontece se a coluna couber o hash. Com `CHAR(32)`, um hash de 60 caracteres seria truncado e o usuário ficaria trancado para fora; o código verifica o tamanho da coluna em `information_schema` e pula a regravação nesse caso. Testado: com o schema antigo, a senha do `ana` continua em MD5 e o login funciona.
  - `schema.sql` passou a `VARCHAR(255)`. A migração de produção está na seção 4.
  - `autenticar` mantém a assinatura, o retorno `['id','nome','papel']` e os tipos dos valores (o `id` continua inteiro, como antes).

### F8 — Fixação de sessão e cookie sem flags
- **Onde:** `code/index.php`, linha 15 (`session_start`) e linhas 24–25 (gravação de `uid`/`papel` no login).
- **Mecanismo:** o id de sessão não muda no login (sem `session_regenerate_id`), então um id fixado antes do login continua válido depois. O cookie também não tinha `HttpOnly` nem `SameSite`, o que deixa a sessão acessível a JavaScript (ver F6) e enviada em requisições cruzadas.
- **Impacto:** sequestro de sessão se o atacante conseguir definir o id de sessão da vítima antes do login.
- **Severidade:** média. **Confiança:** 85 (a exploração depende de o atacante conseguir fixar o id).
- **O que fiz:** `session_regenerate_id(true)` no login; `session_set_cookie_params` com `httponly`, `samesite=Lax` e `secure` quando a conexão é HTTPS. Testado: `HttpOnly` e `SameSite=Lax` presentes, e o id muda no login.

### F9 — Divisão por zero na média de resposta (página quebra)
- **Onde:** `code/lib.php`, linhas 107–117 (`mediaResposta`), em especial a linha 116 (original).
- **Mecanismo:** `$soma / $qtd` com `$qtd = 0`. No PHP 8 isso lança `DivisionByZeroError`. Como `mediaResposta` roda em toda chamada da listagem, a página inteira dá erro 500 enquanto nenhum chamado tiver `minutos_resposta` preenchido. Um banco novo ou sem atendimentos cai nesse caso (reproduzido com o original).
- **Impacto:** a listagem do painel deixa de funcionar para todos os usuários nessa condição.
- **Severidade:** média. **Confiança:** 95.
- **O que fiz:** a função soma e conta com `SUM`/`COUNT` e devolve `0.0` quando não há amostras. Testado: banco vazio retorna `0` sem exceção, e os valores para dados existentes são idênticos aos do original (`25.666666666666668`).

### F10 — Consulta por linha (N+1) na listagem e no CSV
- **Onde:** `code/lib.php`, linhas 88–89 (`listarChamados`) e 137 (`exportarCsv`), ambas chamando `tecnicoNome()`.
- **Mecanismo:** para cada chamado é disparada uma consulta extra à tabela `usuarios` só para obter o nome do técnico. Uma listagem de N chamados faz N+1 consultas, e o CSV faz o mesmo.
- **Impacto:** a latência da listagem e da exportação cresce linearmente com o número de chamados.
- **Severidade:** média. **Confiança:** 90.
- **O que fiz:** `consultarChamados` faz `LEFT JOIN usuarios` e traz `COALESCE(t.nome, '-') AS tecnico_nome` numa única consulta. O `'-'` para técnico ausente continua igual ao original.

### F11 — Injeção de fórmula no CSV
- **Onde:** `code/lib.php`, linha 140 (original, campo `titulo` do CSV).
- **Mecanismo:** o título é escrito sem neutralização. Um cliente abre um chamado com título `=HYPERLINK("http://...")` ou `=cmd|...`. Quando um técnico abre o CSV no Excel ou no Calc, a célula é tratada como fórmula.
- **Impacto:** execução de fórmula ou link malicioso na máquina de quem abre o export.
- **Severidade:** média. **Confiança:** 80.
- **O que fiz:** `protegerCelula()` prefixa `'` nos títulos que começam com `=`, `+`, `-`, `@`, tab ou CR. Aplico só no título, porque é o único campo que o cliente controla; o placeholder `-` do técnico ausente continua intacto. Testado: a célula com `=HYPERLINK` sai como `'=HYPERLINK`.
- **Efeito colateral:** títulos que já começavam com `-` ou `+` ganham um `'` na frente. É uma mudança de dado, pequena e intencional.

### F12 — Status desconhecido é exibido como "Resolvido"
- **Onde:** `code/lib.php`, linhas 26–35 (`formatarStatus`), `else` na linha 32–33.
- **Mecanismo:** qualquer valor que não seja 1 ou 2 cai no `else` e vira `'Resolvido'`. Um status 0 ou 9 aparece como resolvido na listagem, no CSV e no relatório gerencial, que faz correspondência pelo texto.
- **Impacto:** dado inválido é reportado como resolvido, o que distorce o indicador gerencial.
- **Severidade:** baixa. **Confiança:** 85.
- **O que fiz:** `3` mapeia para `'Resolvido'` e qualquer outro valor para `'Desconhecido'`. Os rótulos 1, 2 e 3 do manifesto não mudaram. Diferença confirmada pelo teste unitário: só para status 0 e 9.

### F13 — Parâmetros em forma de array geram erro 500
- **Onde:** `code/index.php`, linhas 21–22 (login) e 72 (busca).
- **Mecanismo:** `login[]=x&senha[]=y` ou `busca[]=x` chega como array. `autenticar(string $usuario, ...)` e `listarChamados(string $busca)` lançam `TypeError`, e a página responde 500 (reproduzido com o original).
- **Impacto:** erro 500 provocado pelo cliente. Não há vazamento, mas quebra o tratamento de erro.
- **Severidade:** baixa. **Confiança:** 70.
- **O que fiz:** os valores são aceitos só se forem strings; caso contrário viram `''`. Testado: não há mais 500.

### F14 — Sem limite de tentativas de login
- **Onde:** `code/index.php`, linhas 20–29.
- **Mecanismo:** o login aceita tentativas ilimitadas, sem atraso, bloqueio ou CAPTCHA. Com o nome de usuário conhecido (são logins simples, como `ana`), a senha pode ser testada por força bruta ou por credential stuffing com senhas vazadas.
- **Impacto:** tomada de conta de clientes e de técnicos.
- **Severidade:** média. **Confiança:** 80.
- **O que NÃO fiz:** a proteção certa depende de onde o estado fica (tabela, cache ou proxy). Implementar isso aqui exigiria armazenamento novo, que está fora do escopo de uma correção no código legado. Recomendo limitar no proxy/Apache (por IP e por login) ou com fail2ban sobre o log do PHP. Não corrigido.

### F15 — Login sem proteção CSRF
- **Onde:** `code/index.php`, linhas 30–35 (formulário de login).
- **Mecanismo:** o formulário de login não tem token. Um site de terceiros consegue submeter o login da vítima com as credenciais do atacante ("login CSRF"), e a vítima passa a trabalhar numa conta que o atacante controla.
- **Impacto:** baixo. Não há ação que altere dados sem login, então o ganho é restrito a confundir o usuário sobre em qual conta ele está.
- **Severidade:** baixa. **Confiança:** 60.
- **O que NÃO fiz:** o app não tem outras ações de escrita (o único POST é o login), então o token traria pouco benefício agora. Fica registrado para o caso de a aplicação ganhar escrita por formulário.

### F16 — Listagem sem paginação e busca sem índice
- **Onde:** `code/lib.php`, linhas 78–93 (original, `listarChamados`).
- **Mecanismo:** a listagem carrega todos os chamados de uma vez. A busca `LIKE '%termo%'` não usa índice (o índice `idx_criado_em` só ajuda o `ORDER BY`), então cada busca lê a tabela inteira.
- **Impacto:** o tempo de resposta cresce com o histórico. No seed a tabela tem 5 linhas, então o impacto em produção depende do volume real, que não conheço.
- **Severidade:** baixa. **Confiança:** 60.
- **O que NÃO fiz:** paginação muda a estrutura da resposta HTML e a assinatura de `listarChamados`, o que mexe na superfície pública. Recomendo decidir isso com quem consome o painel. Não corrigido.

---

## 3. Decisões — o que deixei de fora e por quê

1. **Regras de prioridade (`rotuloPrioridade`) mantidas como estão.** Há comportamentos que parecem estranhos: prioridade 4 com SLA dentro do prazo aparece como "Alto - dentro do SLA", e prioridade 1 ou 2 nunca aparece como atrasada, mesmo com SLA estourado. O manifesto não define essas regras, e mudá-las mudaria o que o negócio vê. Precisa de confirmação de quem define a regra de SLA.

2. **`mediaResposta` continua global, visível a clientes.** A média de minutos de todos os chamados aparece no topo do painel de qualquer cliente. Isso é agregado, não expõe chamado individual, e o manifesto descreve a média como indicador do painel. Mantive, mas é uma decisão de produto: se a média não deve aparecer para clientes, é preciso confirmar.

3. **`verChamado` não ganhou prepared statement.** O parâmetro é tipado `int` e é convertido com `(int)` antes da chamada. Não há injeção possível por essa via, e mexer aqui não traria ganho.

4. **`tecnicoNome()` e a constante `EXPORT_DIR` ficaram no código sem uso.** Não são usados em nenhum lugar do pacote, mas não sei se os scripts externos (rotina noturna, relatório gerencial) os usam. Remover seria arriscado sem essa verificação.

5. **Cabeçalho do CSV preservado byte a byte.** O `fputcsv` original já escrevia `"Aberto em"` entre aspas (espaço força o encapsulamento). Mantive, porque o arquivo gerado é idêntico ao original e qualquer leitor CSV entende o cabeçalho como `Aberto em`.

6. **Logout não foi adicionado.** O manifesto não tem rota de logout. Criar uma rota é aumentar a superfície pública, então deixei para decidir junto com o restante do painel.

7. **Não reescrevi o sistema.** Mantive a stack (PHP + mysqli), a camada de acesso (prepared statements na mesma API), os nomes de arquivos e as assinaturas públicas. As mudanças são cirúrgicas, nas funções e nas linhas dos achados.

8. **Remoção do arquivo em disco (F5) é a mudança com maior risco de compatibilidade.** Preferi corrigir a exposição, que é crítica, a preservar um efeito colateral que não está no manifesto (o manifesto diz "escreve o CSV na saída"). Mas se a rotina noturna depende do arquivo, ela precisa ser ajustada. Ver seção 4.

9. **Hash legado mantido por compatibilidade.** Usuários que nunca logam continuam com MD5 até o primeiro login. Não forcei redefinição de senha em massa: isso bloquearia usuários sem aviso prévio.

---

## 4. Implantação — o que precisa acontecer fora do código

1. **Definir `DB_PASS` e `SMTP_API_KEY` no ambiente antes de subir.** Sem `DB_PASS`, o sistema responde "Falha ao conectar ao banco." (era o comportamento sem o fallback).
2. **Rotacionar a senha do MySQL (usuário `painel`) e a chave SMTP.** Ambas estão no código entregue e no histórico.
3. **Migrar a coluna de senha:** `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`. Antes dela o sistema continua funcionando; só não converte os hashes para o formato novo.
4. **Confirmar com o responsável pela rotina noturna** se ela lê `/var/www/painel/tmp/chamados.csv`. Se lê, ela precisa passar a capturar a saída de `exportarCsv($db)` (por exemplo, `php -r` com `require` redirecionando para o arquivo). Sem isso, a rotina lê um arquivo que não é mais gerado.
5. **Remover `tmp/chamados.csv` antigo**, se existir. Ele contém a base inteira e pode estar acessível pela web. Olhe antes de apagar, como exige a política de dados.
6. **Versão mínima do PHP: 7.4.** Uso arrow functions (`fn`), e `session_set_cookie_params` com array precisa de 7.3.

---

## 5. Verificação realizada

- `php -l` em `config.php`, `index.php` e `lib.php`: sem erros de sintaxe.
- **Testes de comportamento (35 verificações) comparando original e modificado** contra MariaDB 11.8 temporário, com dados de `seed.sql`, servidos pelo PHP 8.4. Resultado: **35 de 35 passam**. Cobrem visibilidade por papel (listagem, detalhe, CSV), injeção e XSS na busca, login legado e migração de hash, cabeçalhos de sessão, fixação de sessão, entrada em array e fórmula no CSV. Os controles do original (que confirmam que o teste de fato detecta o problema) também passam.
- **Paridade das funções públicas**, chamando-as diretamente com o mesmo banco: `listarChamados`, `verChamado`, `autenticar`, `rotuloPrioridade` e `mediaResposta` devolvem o mesmo resultado do original, inclusive tipos. A única diferença é `formatarStatus` para status 0 e 9, que é a correção F12.
- **Schema com `CHAR(32)`:** o login funciona e não regrava o hash, confirmado pelo valor da coluna antes e depois.
- **Banco vazio:** o original lança `DivisionByZeroError`; o modificado retorna `0`.

**Não verificado:**
- Banco de produção, rotina noturna, relatório gerencial, integração de faturamento e configuração do Apache. Não tenho acesso a eles; os pontos que dependem disso estão na seção 4.
- Não usei o MySQL que está escutando em `127.0.0.1:3306` neste host: não é um banco de teste meu e não tenho credenciais para ele. Os testes rodaram numa instância temporária em `/tmp`, em outra porta, que foi desligada depois.
