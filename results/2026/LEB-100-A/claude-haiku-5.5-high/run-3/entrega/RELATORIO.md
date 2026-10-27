# RELATÓRIO — Painel de Chamados (NetX ISP) · LEB-100-A

## 1. Resumo

O painel é um script PHP 8 de ~300 linhas com acesso a dados via mysqli, sem framework, em produção desde 2013. Encontrei **12 achados**: 3 críticos, 3 altos, 3 médios e 3 baixos. Os três críticos eram:

- **SQL injection** na busca por título (`lib.php`), explorável por qualquer usuário logado, inclusive cliente. Permite ler a tabela `usuarios`, com os hashes MD5 das senhas.
- **Quebra da regra de visibilidade**: a listagem, o detalhe e o CSV mostram todos os chamados a qualquer usuário logado. O manifesto exige que o cliente veja apenas os chamados que ele abriu.
- **Credenciais de produção no código**: a senha do banco e a chave SMTP estão no `config.php`, e portanto no pacote entregue.

Corrigi 10 dos 12 achados no `code/`, preservando todas as assinaturas públicas do `manifest.md`. Não corrigi o hash de senha (MD5), que exige migração de dados em produção, nem a regra de prioridade (F12), que depende de confirmação de negócio. Os dois estão em "Decisões". A correção do F3 no código não basta: as credenciais expostas precisam ser **rotacionadas**, e o ambiente de deploy precisa definir `DB_PASS` antes de subir a versão nova.

Verifiquei o comportamento antes e depois contra um MariaDB 11.8 temporário com o `schema.sql` e o `seed.sql` (detalhes na seção 5).

## 2. Achados

Ordem de priorização para correção. `linha` refere-se à numeração do arquivo original recebido.

### F1 — SQL injection na busca por título
- **Onde:** `code/lib.php`, linhas 80–85 (original), função `listarChamados`.
- **O que é:** o termo `busca` vindo de `$_GET['busca']` é concatenado diretamente no SQL: `WHERE titulo LIKE '%" . $busca . "%'`.
- **Mecanismo:** um valor como `zz' UNION SELECT 1,1,NULL,senha,login,1,1,NULL,NULL FROM usuarios -- -` fecha a string do LIKE, anexa um UNION sobre `usuarios` e comenta o restante. O resultado aparece na própria listagem, na coluna Título. Não há nenhuma verificação de tipo ou escape. Esse vetor é ainda mais grave porque qualquer conta logada, inclusive de cliente, pode disparar.
- **Impacto e severidade:** **crítica**. Vazamento de todos os logins e hashes de senha, e possibilidade de ler qualquer outra tabela do banco com o usuário `painel`. Confirmado no original: `ana` (cliente) obteve os dois hashes MD5 de `usuarios` pela listagem.
- **Confiança:** 98.
- **O que fiz:** a busca passou a usar prepared statement (`consultarChamados`, `bind_param`). Os curingas `%` e `_` da busca são escapados com `addcslashes`, para que a busca seja um texto literal sobre o título. Verificado: o payload acima não retorna mais nenhuma linha, e `' OR '1'='1` também não.

### F2 — Regra de visibilidade não aplicada (listagem, detalhe e exportação)
- **Onde:** `code/index.php`, linhas 44–46 (export), 52–58 (detalhe), 72–73 (listagem); `code/lib.php`, linhas 78–93 e 123–151 (funções sem filtro por dono).
- **O que é:** nenhuma das três rotas de leitura verifica o papel do usuário. `listarChamados`, `verChamado` e `exportarCsv` retornam todos os chamados sempre.
- **Mecanismo:** o manifesto define que "um cliente só pode ver os chamados que ele mesmo abriu" e que um técnico vê qualquer chamado. No código, `$_SESSION['papel']` é gravado no login e nunca consultado depois. Assim, `ana` (cliente) vê os chamados de `bruno` pela listagem (`ver=103`, `ver=104`), abre o detalhe `index.php?ver=103` e baixa o CSV com todos os chamados.
- **Impacto e severidade:** **crítica**. Qualquer conta de cliente lê dados de todos os outros clientes: título, descrição, status e técnico responsável. É vazamento de dados de clientes do ISP.
- **Confiança:** 99 (confirmado no original: `ana` obteve o chamado 103 de `bruno`).
- **O que fiz:**
  - Adicionei `visibilidade($uid, $papel)`, que retorna `null` (sem filtro) para técnico e o `uid` para qualquer outro papel. O padrão é negar.
  - `listarChamadosVisiveis` e `exportarCsvVisivel` aplicam esse filtro. `index.php` passou a usá-los na listagem e no export.
  - `podeVerChamado` é checado no detalhe. Chamado de outro cliente responde "Chamado nao encontrado.", a mesma mensagem de chamado inexistente, para não revelar que ele existe.
  - `listarChamados` e `exportarCsv` continuam existindo com a mesma assinatura e sem filtro, para as rotinas internas do ISP citadas no manifesto.
- **Verificado:** `ana` vê 101, 102, 105; `bruno` vê 103, 104; `carla` (técnico) vê os 5. Detalhe de 103 por `ana` retorna "não encontrado". O CSV de `ana` tem 3 linhas e o de `carla`, 5.

### F3 — Credenciais de produção embutidas no código
- **Onde:** `code/config.php`, linhas 11–15.
- **O que é:** a senha do usuário de banco de produção está como fallback literal em `DB_PASS`, e a chave da central SMTP está como constante literal em `SMTP_API_KEY`. (Não repito os valores aqui.)
- **Mecanismo:** `getenv('DB_PASS') ?: '<senha>'` faz a senha valer sempre que a variável de ambiente não estiver definida, o que é o caso padrão. Qualquer pessoa com acesso ao código, ao pacote, ao histórico de versões ou ao repositório obtém as duas credenciais.
- **Impacto e severidade:** **crítica**. Acesso direto ao banco de produção e uso indevido da central de e-mail transacional do ISP, independentemente da aplicação.
- **Confiança:** 99.
- **O que fiz:** removi os dois literais. `DB_PASS` e `SMTP_API_KEY` passam a vir do ambiente, com fallback vazio. Sem `DB_PASS` no ambiente, a conexão é recusada. As constantes continuam definidas, então os scripts que incluem `config.php` seguem funcionando.
- **Ação obrigatória fora do código:**
  1. **Rotacionar** a senha do usuário `painel` e a chave SMTP. Elas estão no pacote e em qualquer cópia anterior, e essa correção não as invalida.
  2. Definir `DB_PASS` e `SMTP_API_KEY` no ambiente do servidor antes de implantar esta versão. Sem isso, o painel para de conectar ao banco.

### F4 — CSV gravado em arquivo fixo sob `/var/www/painel/tmp`
- **Onde:** `code/lib.php`, linhas 125–126 e 150 (original), função `exportarCsv`; constante `EXPORT_DIR` em `config.php` linha 18.
- **O que é:** cada exportação grava todos os chamados em `EXPORT_DIR/chamados.csv` e depois lê o arquivo de volta com `readfile`.
- **Mecanismo:** (a) `EXPORT_DIR` fica dentro de `/var/www/painel`, que é provavelmente a raiz web (o `index.php` está nesse mesmo diretório). Se nenhuma regra do servidor bloquear `tmp/`, o arquivo fica baixável sem login, com o último export inteiro. Como o F2 permitia que qualquer cliente exportasse tudo, o conteúdo era o painel inteiro. (b) Com dois exports simultâneos, ambos escrevem no mesmo arquivo com `fopen('w')`, e um leitor pode ver conteúdo misturado. (c) Se `fopen` falhar, a função retorna em silêncio com resposta 200 vazia.
- **Impacto e severidade:** **alta**. Vazamento de dados sem autenticação, dependendo da configuração do servidor, que não está no código entregue. Confiança menor por essa razão.
- **Confiança:** 70.
- **O que fiz:** o CSV passou a ser escrito direto na saída (`php://output`, via `emitirCsv`), sem arquivo intermediário. Não há mais nada a expor nem a sobrescrever, e o caso de falha silenciosa desapareceu. O cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` e a ordem por `id` foram mantidos. A constante `EXPORT_DIR` foi mantida em `config.php`, mas não é mais usada por `lib.php`.
- **Ponto a verificar:** se a rotina noturna do ISP lê `tmp/chamados.csv` em disco (em vez de chamar `exportarCsv` e receber a saída), essa leitura quebra. O manifesto diz que `exportarCsv` "escreve o CSV na saída", o que aponta para o contrato de saída, mas não consegui confirmar isso no código da rotina, que não está no pacote.

### F5 — XSS refletido na busca
- **Onde:** `code/index.php`, linhas 79 e 82 (original).
- **O que é:** `$busca` é impresso sem escape no atributo `value` do campo de busca e no texto "Resultados para:".
- **Mecanismo:** `busca="><script>...</script>` fecha o atributo e injeta script na página. A URL é enviada à vítima por link, e o script roda na sessão dela, com acesso aos chamados visíveis àquela conta.
- **Impacto e severidade:** **alta**. Execução de script na origem do painel, com a sessão da vítima. Confirmado no original: o `<script>` volta sem escape.
- **Confiança:** 97.
- **O que fiz:** `htmlspecialchars($busca)` nos dois pontos. Verificado: o payload volta como `&quot;&gt;&lt;script&gt;...`.

### F6 — Senhas armazenadas como MD5 sem sal
- **Onde:** `code/lib.php`, linhas 15–17 (`md5($senha)` e comparação na consulta); `code/schema.sql`, linha 7 (`senha CHAR(32)`).
- **O que é:** o hash das senhas é MD5 puro, sem sal e sem custo. O `seed.sql` usa senhas triviais (`senha123`, `tecmaster`).
- **Mecanismo:** MD5 sem sal permite quebra em massa com tabelas pré-computadas. Combinado com F1, qualquer conta logada obtém todos os hashes.
- **Impacto e severidade:** **alta**. Se os hashes vazarem (por F1 ou por backup), a recuperação das senhas é trivial.
- **Confiança:** 95.
- **O que fiz:** **nada**, por decisão (ver seção 3). A correção certa é `password_hash`/`password_verify` com migração no login, e isso exige `ALTER` em `usuarios.senha`, que hoje é `CHAR(32)` e não cabe um hash bcrypt. Não fiz esse `ALTER` sem janela de migração em produção. Mitigação parcial já feita: F1 foi corrigido, então o vazamento pela listagem não existe mais.

### F7 — Fixação de sessão no login
- **Onde:** `code/index.php`, linhas 22–25 (original).
- **O que é:** após o login bem-sucedido, o `$_SESSION` é preenchido sem regenerar o id da sessão.
- **Mecanismo:** se um atacante fixar um `PHPSESSID` conhecido na vítima antes do login (por exemplo, via cookie injetado), o id continua válido depois do login e o atacante fica autenticado como a vítima.
- **Impacto e severidade:** **média**.
- **Confiança:** 80.
- **O que fiz:** `session_regenerate_id(true)` antes de gravar `uid` e `papel`. Verificado: o `PHPSESSID` muda no login no código novo e permanece igual no original.

### F8 — Divisão por zero na média de resposta: HTTP 500 sem chamados respondidos
- **Onde:** `code/lib.php`, linhas 107–117 (original), função `mediaResposta`.
- **O que é:** a média é calculada como `$soma / $qtd`, com `$qtd` zero quando nenhum chamado tem `minutos_resposta`.
- **Mecanismo:** no PHP 8, `/` por zero lança `DivisionByZeroError`. A página principal (que chama `mediaResposta` antes de qualquer HTML) cai com erro 500. A função também carrega todas as linhas em memória só para somar, o que é desnecessário.
- **Impacto e severidade:** **média**. Painel indisponível em instalação nova ou quando todos os chamados estão sem resposta.
- **Confiança:** 95.
- **O que fiz:** a média passou a ser `SELECT AVG(minutos_resposta)` (o AVG ignora NULLs e faz a soma no banco). Sem dados, retorna `0.0`. Verificado: com os `minutos_resposta` zerados, o código original responde 500 e o novo mostra "Tempo medio de 1a resposta: 0 min". Com os dados do seed, ambos mostram 26 min.

### F9 — Injeção de fórmula no CSV exportado
- **Onde:** `code/lib.php`, linha 140 (original), coluna `titulo` no `fputcsv`.
- **O que é:** o título é texto livre do cliente e vai para o CSV sem tratamento.
- **Mecanismo:** um título como `=HYPERLINK("http://...")` ou `=cmd|...` é interpretado como fórmula quando um técnico abre o CSV no Excel ou no Calc.
- **Impacto e severidade:** **média**. Um cliente malicioso pode fazer uma fórmula rodar ou exfiltrar dados na máquina de quem abre o export.
- **Confiança:** 60. Depende do software de planilha e de como o técnico abre o arquivo.
- **O que fiz:** `neutralizarFormula` acrescenta `'` na frente de títulos que começam com `=`, `+`, `-`, `@`, tab ou CR. Verificado: um título `=HYPERLINK(...)` sai como `'=HYPERLINK(...)`. Títulos normais não mudam. A mudança afeta só o valor de títulos com esses caracteres iniciais, e isso fica registrado como decisão.

### F10 — Consultas N+1 para o nome do técnico
- **Onde:** `code/lib.php`, linha 69 (`tecnicoNome`), chamado nas linhas 89 (listagem) e 137 (export) do original.
- **O que é:** para cada chamado, uma consulta extra a `usuarios`.
- **Mecanismo:** a listagem e o export fazem 1 + N consultas. Com milhares de chamados, o tempo de resposta cresce linearmente.
- **Impacto e severidade:** **baixa**. Desempenho.
- **Confiança:** 95.
- **O que fiz:** `consultarChamados` faz `LEFT JOIN usuarios` e `COALESCE(t.nome, '-')`, em uma consulta só. O valor `'-'` para chamados sem técnico é o mesmo de antes. `tecnicoNome` continua existindo para quem a usa.

### F11 — `formatarStatus` rotula valor inválido como "Resolvido"
- **Onde:** `code/lib.php`, linhas 26–35 (original).
- **O que é:** o `else` final devolve `'Resolvido'` para qualquer valor diferente de 1 e 2, inclusive 0, negativos e valores fora do domínio.
- **Mecanismo:** um status corrompido ou um valor inesperado aparece como "Resolvido" no painel e no CSV, e o relatório gerencial (que compara textos) recebe dado falso.
- **Impacto e severidade:** **baixa**. Só acontece com dado já inconsistente, mas mente sobre o estado.
- **Confiança:** 75.
- **O que fiz:** `3 → 'Resolvido'` explícito e `else → 'Desconhecido'`. Os três rótulos do contrato do manifesto (`1`, `2`, `3`) foram mantidos exatamente.

### F12 — Prioridade crítica dentro do SLA aparece como "Alto"
- **Onde:** `code/lib.php`, linhas 40–59 (original), função `rotuloPrioridade`, em especial a linha 51.
- **O que é:** para `prioridade == 4` (crítica) com `minutos <= 30`, o rótulo é `'Alto - dentro do SLA'`. O rótulo `'CRITICO - SLA estourado'` só aparece para prioridade 4 com SLA estourado.
- **Mecanismo:** a lógica nunca produz um rótulo de criticidade para chamado crítico dentro do prazo. Pode ser intencional, mas parece mais provável que seja um `if` faltando.
- **Impacto e severidade:** **baixa**, se for bug. Pode fazer um chamado crítico ser tratado como alto na triagem.
- **Confiança:** 35. Não sei a regra de negócio, e o manifesto não define os rótulos de prioridade.
- **O que fiz:** nada. Ver seção 3.

## 3. Decisões — o que não mudei e por quê

1. **Hash MD5 das senhas (F6) — mantido.** A correção exige `ALTER` em `usuarios.senha` (de `CHAR(32)` para algo como `VARCHAR(255)`), migração dos hashes e rehash no login. Isso muda dados de produção e precisa de janela de migração e de um plano de rollback. Fazer isso junto com a correção de segurança desta entrega seria arriscado. Recomendo fazer como próximo passo: `password_hash` com "upgrade on login" para contas que ainda têm MD5.

2. **Rotação de credenciais (F3) — não é código.** A senha e a chave que estavam no `config.php` continuam válidas até serem rotacionadas. Não consigo fazer isso daqui. Além disso, o ambiente precisa de `DB_PASS` antes do deploy.

3. **Sem limite de tentativas de login e sem token CSRF no formulário de login — mantido.** Ambos mudam o fluxo de uso (bloqueio de conta, mensagens de erro, tokens nos formulários). Não estavam no escopo de "corrigir sem quebrar o contrato" e merecem decisão de produto.

4. **Diretório `EXPORT_DIR` e constante — mantidos.** A constante continua em `config.php` para não quebrar quem a inclui. O arquivo em disco deixou de ser gerado (F4), e isso depende da confirmação do ponto indicado em F4.

5. **Busca com curingas escapados — mudança de comportamento deliberada.** Antes, `%` e `_` digitados na busca funcionavam como curingas do LIKE, e buscar `%` listava todos os chamados. Agora a busca é literal. Entendi "filtrada por título" como busca textual. Se algum consumidor depende do curinga, ele deve ser avisado.

6. **`mediaResposta` sem dados retorna `0.0` — mudança de comportamento.** O original quebrava com 500. O painel agora mostra "0 min". Preferiria mostrar "-" na tela, mas isso mudaria o HTML do topo, que não está no manifesto como contrato; deixei o número.

7. **Média global visível ao cliente — mantido.** `mediaResposta` é um agregado de todos os chamados e aparece no topo para qualquer usuário. Não identifica chamado nem cliente. Mudar isso é decisão de produto: se não deve aparecer para cliente, basta condicionar a linha a `$papel === 'tecnico'`.

8. **`tecnicoNome` com interpolação de SQL — mantido.** O argumento é `?int` e passa por `(int)` antes, então não é injetável. A função não é mais usada por `lib.php`, mas continua pública por compatibilidade.

9. **Regra de prioridade (F12) — mantida.** Mudar o rótulo sem a regra de negócio confirmada pode trocar um erro por outro. Precisa de confirmação de quem define a triagem.

10. **Formato de título no CSV (F9) — mudança mínima.** O apóstrofo só entra em títulos que começam com caracteres de fórmula. O cabeçalho, a ordem e as colunas não mudaram.

11. **Não tratei: entrada não-string no login** (`login[]=x` gera erro 500). Não tem impacto de segurança, e o tratamento correto está fora do escopo.

12. **Não tratei: configuração de cookie de sessão** (`httponly`, `secure`, `samesite`). Isso está no `php.ini` do servidor, que não está no pacote. Recomendo conferir.

13. **Não tratei: exibição de erros do PHP/mysqli.** O mesmo motivo: depende de `display_errors` no servidor.

14. **Não reescrevi nada.** Nenhuma stack, camada de acesso ou arquivo foi trocado ou renomeado. Não criei dependências. Não criei logout (não existe no manifesto e não estava no código).

15. **Compatibilidade com o manifesto.** Todas as assinaturas públicas (`autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`) foram mantidas. As funções novas (`visibilidade`, `podeVerChamado`, `consultarChamados`, `listarChamadosVisiveis`, `emitirCsv`, `exportarCsvVisivel`, `neutralizarFormula`) são acréscimos. As rotas, os parâmetros GET, o cabeçalho e as colunas do CSV, a estrutura HTML da tabela e os rótulos de status 1/2/3 foram preservados. Mudanças observáveis fora do contrato: itens 5, 6, 10 e F4.

## 4. Arquivos alterados

- `code/lib.php`
- `code/index.php`
- `code/config.php`

`schema.sql` e `seed.sql` não foram alterados. O `.leb-pacote.sha256` deixa de bater com os três arquivos acima, o que é esperado.

## 5. Verificação

Ambiente: PHP 8.4.26 (CLI, servidor embutido) e MariaDB 11.8.6 temporário, em porta e socket próprios, com `schema.sql` e `seed.sql`. Não toquei no MariaDB do sistema que já estava rodando. Código original e código novo foram servidos em portas diferentes e comparados com os mesmos scripts.

| Teste | Original | Novo |
|---|---|---|
| `ana` (cliente) lista chamados | 101–105 | 101, 102, 105 |
| `bruno` (cliente) lista chamados | 101–105 | 103, 104 |
| `carla` (técnico) lista chamados | 101–105 | 101–105 |
| `ana` abre `?ver=103` (de `bruno`) | mostra o chamado | "Chamado nao encontrado." |
| `ana` abre `?ver=101` (dela) | mostra | mostra |
| UNION sobre `usuarios` na busca | hashes MD5 retornados | nada retornado |
| `busca=' OR '1'='1` | todos | nenhum |
| `busca=Roteador` (técnico) | 105 | 105 |
| `busca=%` | todos (curinga) | nenhum (literal) — item 5 |
| XSS na busca | script sem escape | escapado |
| Export CSV de `ana` | corpo vazio (`EXPORT_DIR` inexistente no teste; fopen falha em silêncio) | 3 linhas + cabeçalho |
| Export CSV de `carla` | — | 5 linhas + cabeçalho |
| Média com dados do seed | 26 min | 26 min |
| Média sem chamados respondidos | HTTP 500 | 0 min |
| Título `=HYPERLINK(...)` no CSV | sem tratamento | `'=HYPERLINK(...)` |
| Sessão no login | mesmo id | id novo |
| `exportarCsv` chamado por CLI | — | imprime o CSV na saída |
| `config.php` sem `DB_PASS` no ambiente | conecta com a senha embutida | `DB_PASS` vazio (conexão recusada) |

`php -l` passou em todos os arquivos. Não testei: a rotina noturna real, o servidor de produção (configuração de webroot, cookies, `php.ini`), nem a migração de hashes (não feita). Os testes foram rodados contra dados de exemplo, não contra o banco de produção.
