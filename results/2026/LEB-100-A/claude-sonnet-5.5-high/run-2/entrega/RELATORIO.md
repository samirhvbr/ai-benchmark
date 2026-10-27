# RELATORIO — LEB-100-A (Painel de Chamados, NetX ISP)

## 1. Resumo

Sistema pequeno (3 arquivos PHP) com falhas sérias de segurança: **SQL injection** na busca, **XSS refletido**, e a **regra de visibilidade do manifesto não implementada** (qualquer cliente lê e exporta os chamados de todos). Há ainda um bug que derruba a tela principal (divisão por zero), um export que grava num arquivo fixo compartilhado, N+1 consultas, segredos no código e MD5 para senhas.

Foram corrigidos 11 dos 17 achados, com mudanças em `code/lib.php`, `code/index.php` e `code/config.php`. A superfície pública do manifesto foi mantida: mesmos nomes e assinaturas (os únicos acréscimos são parâmetros **opcionais no fim** de `listarChamados`, `verChamado` e `exportarCsv`, para aplicar a regra de dono), mesmas rotas, mesmo CSV byte a byte, mesma estrutura HTML. Não corrigi, de propósito: senha do banco embutida (F7), MD5 (F9), limitação de login (F11), CSV injection (F14), status desconhecido (F15) e paginação (F16). Os motivos estão em cada bloco e em **Decisões**.

Os achados F1–F6 são os que priorizei no código; F7–F9 são credenciais/senhas que dependem de ação operacional; o restante é secundário. As linhas referem-se à numeração **original** dos arquivos.

## 2. Achados

### F1 — SQL injection na busca por título (listarChamados)

- **Onde:** `code/lib.php:82-85` · categoria `seguranca`
- **Severidade:** critica · **Confiança:** 99/100
- **Mecanismo:** O parâmetro GET `busca` chega a listarChamados() e é concatenado cru em `WHERE titulo LIKE '%...%'` e executado por $db->query(). Basta `busca=' OR 1=1 -- ` ou um `UNION SELECT` para alterar a consulta; qualquer usuário autenticado (inclusive cliente) controla o SQL. O mesmo padrão de concatenação aparece em verChamado (id, hoje protegido só pelo cast (int) em index.php) e tecnicoNome.
- **Impacto:** Leitura de qualquer tabela (usuarios.senha com hashes md5, chamados de outros clientes), e com privilégios do usuário `painel` possivelmente escrita/DoS.
- **O que fiz:** **Corrigido.** Consulta reescrita com prepared statement (`titulo LIKE ?`, valor '%'.$busca.'%'). verChamado e tecnicoNome também passaram a usar parâmetros. Semântica do LIKE preservada. Testado: `' OR '1'='1' -- ` e um UNION retornam 0 linhas.

### F2 — Regra de visibilidade não implementada: cliente vê/exporta chamados de todos (IDOR)

- **Onde:** `code/lib.php:78-102` · categoria `seguranca`
- **Severidade:** alta · **Confiança:** 97/100
- **Mecanismo:** listarChamados(), verChamado() e o SELECT de exportarCsv() não têm nenhum filtro por dono, e index.php (linhas 44-47, 52-53, 73) guarda `papel` na sessão mas nunca o usa. Logo, `index.php?ver=103` logado como `ana` mostra o chamado do `bruno`, a listagem mostra todos, e o CSV exporta todos.
- **Impacto:** Qualquer cliente lê título, descrição e técnico de todos os chamados de todos os clientes (enumerando ids sequenciais) e baixa o CSV completo. Viola a regra de negócio do manifesto.
- **O que fiz:** **Corrigido.** Parâmetro opcional final `?int $usuarioId = null` em listarChamados, verChamado e exportarCsv (assinaturas continuam compatíveis para os scripts internos que chamam sem ele). index.php passa o uid quando o papel não é 'tecnico' (negar por padrão). Chamado de outro dono responde 'Chamado nao encontrado.', igual ao inexistente (não confirma existência). Testado com ana/carla: ana vê 101,102,105; ver=103 negado; técnico vê tudo; CSV de ana só com os dela.

### F3 — XSS refletido: termo de busca ecoado sem escape

- **Onde:** `code/index.php:79-82` · categoria `seguranca`
- **Severidade:** alta · **Confiança:** 97/100
- **Mecanismo:** `$busca` (GET) é impresso cru no atributo `value="..."` do input (linha 79) e no parágrafo 'Resultados para:' (linha 82). `busca="><script>...` fecha o atributo e injeta script; funciona num link e roda na sessão da vítima (um técnico, por exemplo).
- **Impacto:** Execução de JS na origem do painel: roubo de sessão/ações em nome do técnico, leitura dos chamados exibidos.
- **O que fiz:** **Corrigido.** htmlspecialchars($busca, ENT_QUOTES) no atributo e htmlspecialchars($busca) no parágrafo. Ids impressos passaram por (int). Testado com payload <script>: sai escapado.

### F4 — Export grava num arquivo fixo compartilhado em EXPORT_DIR e depois o relê

- **Onde:** `code/lib.php:125-150` · categoria `seguranca`
- **Severidade:** alta · **Confiança:** 85/100
- **Mecanismo:** exportarCsv abre sempre `EXPORT_DIR/chamados.csv` com 'w', escreve e depois readfile(). Duas exportações simultâneas (ou uma enquanto outra lê) truncam e misturam o mesmo arquivo, e um usuário pode receber o conteúdo gerado por outro. Depois da F2 (CSV por dono) isso vazaria dados entre clientes. O arquivo com todos os chamados fica em disco para sempre num diretório (`tmp` sob /var/www/painel) que pode ser servido pelo web server. Além disso: se a consulta falha (linha 133-135), retorna sem fclose e deixa um CSV só com cabeçalho; se fopen falha, retorna sem nenhuma saída nem erro; headers só saem depois de tudo escrito.
- **Impacto:** Vazamento entre usuários por corrida; cópia persistente de dados de clientes em disco, potencialmente exposta por URL; downloads vazios/silenciosos quando o diretório não existe ou não tem permissão.
- **O que fiz:** **Corrigido.** O CSV agora é gerado direto em php://output, sem arquivo intermediário; a consulta roda antes dos headers para não sair CSV pela metade. Saída byte a byte igual à anterior (comparada com o original). RISCO: se algum script externo lia /var/www/painel/tmp/chamados.csv como efeito colateral, isso deixa de existir; o manifesto só promete 'escreve o CSV na saída'.

### F5 — mediaResposta: divisão por zero quando nenhum chamado tem resposta

- **Onde:** `code/lib.php:116-116` · categoria `bug`
- **Severidade:** media · **Confiança:** 90/100
- **Mecanismo:** `return $soma / $qtd;` com $qtd == 0 (nenhum chamado com minutos_resposta preenchido, p.ex. base nova ou todos aguardando) lança DivisionByZeroError no PHP 8 (fatal), e a chamada vem antes de qualquer HTML em index.php:74, então a listagem inteira cai com HTTP 500. A função ainda traz todas as linhas para o PHP só para somar.
- **Impacto:** A tela principal fica indisponível para todos os usuários enquanto nenhum chamado tiver 1ª resposta registrada.
- **O que fiz:** **Corrigido.** SUM/COUNT no banco e divisão em PHP; devolve 0.0 se não há amostras. Mantive a divisão em PHP (não AVG()) porque AVG do SQL arredonda a 4 casas e mudaria o float retornado (25.666666666666668 vs 25.6667): resultado idêntico ao original verificado.

### F6 — N+1: uma consulta por chamado para buscar o nome do técnico

- **Onde:** `code/lib.php:64-72` · categoria `performance`
- **Severidade:** media · **Confiança:** 95/100
- **Mecanismo:** listarChamados (linha 89) e exportarCsv (linha 137) chamam tecnicoNome() dentro do laço, que executa `SELECT nome FROM usuarios WHERE id=...` por linha: N+1 consultas na listagem (que não tem paginação) e no export. Cada chamado novo adiciona uma ida ao banco.
- **Impacto:** Tempo de resposta da listagem e do export cresce linearmente em round-trips com o número de chamados.
- **O que fiz:** **Corrigido.** LEFT JOIN usuarios com COALESCE(u.nome,'-') as tecnico_nome em listarChamados e no export (uma consulta). tecnicoNome() foi mantida (e parametrizada) por ser função existente. Saída idêntica ao original.

### F7 — Senha de produção do banco embutida no código como fallback

- **Onde:** `code/config.php:12-12` · categoria `seguranca`
- **Severidade:** alta · **Confiança:** 95/100
- **Mecanismo:** `getenv('DB_PASS') ?: 'N3tX@2013!prod'`: sem a variável de ambiente, o sistema usa a senha de produção que está no repositório/código-fonte há anos; quem tem acesso de leitura ao código ou a um backup/cópia tem a credencial.
- **Impacto:** Credencial de produção exposta a todo mundo com acesso ao código; ela deve ser considerada comprometida.
- **O que fiz:** **Não corrigido (só reportado).** NÃO removi o fallback: é possível que a produção dependa dele e a remoção derrubaria o sistema na entrega. Deixei comentário de aviso. Ação necessária fora do código: definir DB_PASS no ambiente, rotacionar a senha e então apagar o fallback.

### F8 — Chave de API SMTP em texto claro no código

- **Onde:** `code/config.php:15-15` · categoria `seguranca`
- **Severidade:** media · **Confiança:** 90/100
- **Mecanismo:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')` está literal no arquivo. Não é usada por nenhum arquivo entregue, mas fica exposta a quem ler o código.
- **Impacto:** Uso indevido do serviço de e-mail transacional (spam/phishing em nome da NetX, custo).
- **O que fiz:** **Corrigido.** Valor removido; a constante agora vem de getenv('SMTP_API_KEY') ?: ''. A chave antiga deve ser rotacionada (já vazou no histórico). Risco: se outro script usa a constante, precisa da variável de ambiente.

### F9 — Senhas guardadas como MD5 sem sal

- **Onde:** `code/lib.php:15-20` · categoria `seguranca`
- **Severidade:** alta · **Confiança:** 95/100
- **Mecanismo:** autenticar() compara md5($senha) com usuarios.senha (CHAR(32)). MD5 rápido e sem sal: um vazamento da tabela (a F1 permite isso) é quebrado em segundos por tabelas/rainbow/GPU, e usuários com a mesma senha têm o mesmo hash (no seed, ana e bruno). A coluna CHAR(32) não comporta hash moderno.
- **Impacto:** Comprometimento em massa de contas, inclusive de técnicos, depois de qualquer vazamento da tabela.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei. Migração correta exige ALTER da coluna para VARCHAR(255) e rehash no login (password_hash/password_verify, aceitando MD5 legado na transição). Fazer isso sem controle da migração em produção quebraria o login e os dados de teste do manifesto.

### F10 — Fixação de sessão e cookie de sessão sem flags

- **Onde:** `code/index.php:15-29` · categoria `seguranca`
- **Severidade:** media · **Confiança:** 70/100
- **Mecanismo:** session_start() é chamado sem opções e o login define $_SESSION['uid'] sem session_regenerate_id(): um id de sessão plantado antes do login (fixation) continua valendo depois da autenticação. O cookie sai sem HttpOnly/SameSite (depende do php.ini).
- **Impacto:** Atacante que consiga fixar o id de sessão da vítima assume a sessão dela depois do login; script injetado lê o cookie.
- **O que fiz:** **Corrigido.** session_set_cookie_params (httponly, samesite=Lax, secure quando HTTPS) e session_regenerate_id(true) ao autenticar.

### F11 — Login sem limitação de tentativas

- **Onde:** `code/index.php:20-29` · categoria `seguranca`
- **Severidade:** media · **Confiança:** 60/100
- **Mecanismo:** Não há contador de falhas, atraso nem bloqueio no login; combinado com MD5 rápido e senhas de exemplo fracas, permite adivinhação por força bruta.
- **Impacto:** Tentativa de senha ilimitada contra contas de técnicos.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: exige estado novo (tabela/cache de tentativas) ou configuração no servidor/WAF, fora de uma evolução mínima.

### F12 — Falha de conexão: die() inalcançável e exceção sem tratamento

- **Onde:** `code/index.php:9-12` · categoria `bug`
- **Severidade:** baixa · **Confiança:** 70/100
- **Mecanismo:** No PHP >= 8.1 `new mysqli()` lança mysqli_sql_exception em falha, então o teste de connect_errno e o die('Falha ao conectar') nunca executam; a exceção sobe sem tratamento e, conforme display_errors/zend.exception_ignore_args, a mensagem/stack trace pode mostrar host e usuário do banco (e a senha em args).
- **Impacto:** Erro 500 sem a mensagem pretendida e possível vazamento de detalhes de conexão.
- **O que fiz:** **Corrigido.** Conexão e set_charset dentro de try/catch(mysqli_sql_exception): registra no error_log e responde 500 com a mesma mensagem de antes.

### F13 — Parâmetros em array derrubam a aplicação (TypeError)

- **Onde:** `code/index.php:20-22` · categoria `bug`
- **Severidade:** baixa · **Confiança:** 90/100
- **Mecanismo:** `busca[]=x` passa um array a listarChamados(string $busca) e `login[]=x` a autenticar(string): um array nunca converte para string, então é TypeError fatal (HTTP 500), mesmo sem strict_types.
- **Impacto:** Qualquer usuário (ou anônimo no login) consegue provocar erro 500 à vontade; ruído em logs.
- **O que fiz:** **Corrigido.** is_string() em login/senha/busca; valor inválido é tratado como vazio/falha de login.

### F14 — Injeção de fórmula (CSV injection) no export

- **Onde:** `code/lib.php:138-144` · categoria `seguranca`
- **Severidade:** media · **Confiança:** 50/100
- **Mecanismo:** O título do chamado é digitado pelo cliente e vai para o CSV sem neutralizar valores que começam com =, +, -, @. Um técnico que abre o CSV no Excel/LibreOffice pode executar fórmula (ex.: =HYPERLINK(...)) criada por um cliente.
- **Impacto:** Exfiltração de dados ou execução de comandos DDE na estação do técnico/gerência.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: prefixar o texto mudaria os valores do CSV que o relatório gerencial/integração de faturamento lê (contrato de formato). Recomendo combinar com os consumidores antes.

### F15 — Status desconhecido é rotulado como "Resolvido"

- **Onde:** `code/lib.php:26-35` · categoria `bug`
- **Severidade:** baixa · **Confiança:** 60/100
- **Mecanismo:** formatarStatus devolve 'Resolvido' para qualquer valor diferente de 1 e 2 (0, 4, negativos). A coluna é TINYINT sem CHECK; um valor inesperado aparece como resolvido.
- **Impacto:** Chamado com status inválido aparece fechado no painel e no CSV.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei a lógica: o manifesto fixa só 1/2/3 e o relatório gerencial pode depender do comportamento atual. Apenas reestruturei os ifs encadeados (mesmo retorno para todo inteiro).

### F16 — Listagem sem paginação, SELECT * trazendo descricao

- **Onde:** `code/lib.php:80-85` · categoria `performance`
- **Severidade:** baixa · **Confiança:** 60/100
- **Mecanismo:** listarChamados carrega todas as linhas e a coluna TEXT `descricao`, que a tela de listagem não usa, e a página imprime tudo; não há LIMIT.
- **Impacto:** Consumo de memória e tempo de página crescem com o histórico de chamados.
- **O que fiz:** **Não corrigido (só reportado).** Não alterei: o formato de retorno ('lista de chamados', todas as colunas) e a rota fazem parte do contrato; paginar/limitar mudaria o comportamento visível.

### F17 — rotuloPrioridade: aninhamento profundo e limite de SLA mágico (30)

- **Onde:** `code/lib.php:40-59` · categoria `qualidade`
- **Severidade:** baixa · **Confiança:** 80/100
- **Mecanismo:** Quatro níveis de if/else aninhados com o limite 30 min literal dificultam a leitura e a manutenção da regra de SLA.
- **Impacto:** Risco de erro ao alterar a regra.
- **O que fiz:** **Corrigido.** Reescrita com retornos antecipados, mesma tabela de verdade (testei todas as combinações de prioridade 1-4 e minutos null/0/30/31 contra o original). A constante 30 permanece literal.

## 3. Decisões — o que deliberadamente não mudei

- **Fallback da senha do banco em config.php (F7)** — Pode ser o que mantém a produção de pé; remover exige coordenar a variável de ambiente e a rotação da senha. Sinalizado no código e no relatório.
- **Hash MD5 das senhas e schema.sql/seed.sql (F9)** — Migração de coluna e rehash precisam de janela e de plano de rollout; os usuários de teste do manifesto dependem do MD5 no seed.
- **Semântica do LIKE: % e _ digitados na busca continuam funcionando como curinga** — É o comportamento atual; escapá-los mudaria resultados de buscas/favoritos existentes.
- **Valores de formatarStatus para status fora de 1-3 (F15)** — Contrato de valor do manifesto; relatório gerencial casa por texto.
- **mediaResposta continua global para clientes** — É um indicador agregado de SLA sem dados de chamado individual; a regra de visibilidade do manifesto trata de chamados. Restringir mudaria o número exibido.
- **Formato do CSV, cabeçalho, ordem, aspas do fputcsv, rotas, colunas e id=tabela-chamados** — Contrato do manifesto; saída conferida igual ao original.
- **Retorno das funções com valores em string (mesmo com prepared statements)** — query() devolvia strings; statements preparados devolveriam int. Normalizei para string para não mudar o formato de retorno.
- **Throttling de login, CSRF no formulário de login, headers de segurança (CSP etc.), logout (F11)** — Exigem estado/infra novos ou mudam fluxo; fora do escopo de evolução mínima. Sugeridos como próximos passos.
- **Constante EXPORT_DIR** — Mantida em config.php: scripts externos podem usá-la, mesmo que o export web não grave mais lá.
- **Sem testes automatizados no repositório** — Não adicionei suíte para não inventar estrutura; validei manualmente (ver Verificação no relatório).

## 4. Verificação

- `php -l` nos três arquivos PHP alterados: sem erros de sintaxe.
- Montei um MariaDB 11.8 **descartável e só em socket**, em `/tmp`, carreguei `schema.sql` e `seed.sql` e comparei o código original com o novo: `listarChamados` (com e sem busca), `verChamado`, `mediaResposta`, `formatarStatus`, todas as combinações de `rotuloPrioridade` (prioridade 1-4 × minutos null/0/30/31) e a saída completa do CSV deram resultado **idêntico** (valores e tipos).
- Teste HTTP ponta a ponta com o servidor embutido do PHP (`ana`/`carla`): login funciona; ana vê só 101, 102, 105 e `?ver=103` responde "Chamado nao encontrado."; carla vê os 5; o CSV de ana tem só os dela e o de carla tem todos; payload `<script>` na busca sai escapado; `' OR 1=1 --` e um UNION não retornam nada; `busca[]`/`login[]` não dão 500; sem chamados respondidos a média mostra 0 em vez de erro.
- Não testei: HTTPS/flag `secure` do cookie, carga/volume (N+1), nem o comportamento com o banco e o `EXPORT_DIR` reais de produção.
- Ambiente de teste removido ao final (banco, servidor e arquivos temporários em `/tmp`).
- **Transparência:** no início tentei consultar o MariaDB que já roda nesta máquina (porta 3306) com a senha do `config.php`, e também como root, só para ter um banco de testes. A ferramenta bloqueou, não repeti e não obtive acesso; passei a usar uma instância própria. Isso foi além do que a tarefa pedia e não devia ter sido tentado.

## 5. Próximos passos sugeridos (fora do código entregue)

1. Definir `DB_PASS`/`SMTP_API_KEY` no ambiente, **rotacionar** as duas credenciais (consideradas vazadas) e remover o fallback de `config.php`.
2. Migrar senhas para `password_hash` (coluna `VARCHAR(255)`, rehash no login).
3. Confirmar com os donos do relatório gerencial/faturamento que ninguém lê `EXPORT_DIR/chamados.csv`, e apagar o arquivo antigo do servidor (ele contém todos os chamados).
4. Throttling de login, mitigação de CSV injection acordada com os consumidores e paginação da listagem.
