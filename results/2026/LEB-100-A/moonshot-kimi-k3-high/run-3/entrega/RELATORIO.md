# RELATORIO.md — LEB-100-A — Painel de Chamados (NetX ISP)

## 1. Resumo

O sistema é um painel PHP/mysqli pequeno e funcional, mas com falhas graves de segurança:
**injeção de SQL** na busca, **ausência total da regra de visibilidade** declarada no manifesto
(qualquer cliente autenticado via — e exportava — chamados de todos os outros) e **XSS refletido**
no parâmetro de busca. Há ainda um bug de disponibilidade (divisão por zero no indicador de SLA),
senhas com MD5 e segredos embutidos no código. Corrigi tudo o que era possível corrigir **sem
quebrar o contrato do manifesto** (assinaturas, rotas, rótulos, formato do CSV e regra de
visibilidade preservados); os pontos que exigem migração de dados ou ação operacional foram
reportados e deliberadamente não alterados (seção 3). Todas as correções foram verificadas com
teste de integração real (MariaDB 11 + `php -S`, seed fornecido): login, visibilidade cliente/
técnico, busca legítima, payload de SQLi, payload de XSS, export CSV e média com banco vazio.

## 2. Achados (ordem de prioridade)

### F1 — Injeção de SQL na busca de chamados — CRÍTICA — confiança 100 — CORRIGIDO
- **Onde:** `code/lib.php`, linha 82 (bloco 80–85), função `listarChamados`.
- **Mecanismo:** o termo vindo de `$_GET['busca']` era concatenado diretamente na query:
  `" WHERE titulo LIKE '%" . $busca . "%'"`. Como `index.php` repassa `$_GET['busca']` sem
  nenhum tratamento, um payload como `' OR '1'='1` (ou `' UNION SELECT login, senha, ... --`)
  altera a SQL executada. Diferente de `verChamado`/`tecnicoNome`, aqui o valor é **string
  controlada pelo atacante**, sem cast nem escape em nenhum ponto do caminho.
- **Impacto:** qualquer usuário autenticado (inclusive cliente com credencial fraca) lê
  tabelas arbitrárias do banco — incluindo `usuarios.senha` (hashes MD5, ver F4) — via
  UNION-based SQLi; potencial de escrita conforme privilégios do usuário `painel`.
- **O que fiz:** reescrevi a função com `prepare`/`bind_param` (o padrão `%termo%` vai como
  **parâmetro**, não como SQL). A assinatura `listarChamados(mysqli, string): array` e a
  semântica da busca (substring em título, `%`/`_` continuam curingas como antes) foram
  preservadas. Verificado: `busca=' OR '1'='1` retorna 0 resultados; `busca=Lentidao`
  retorna o chamado 102.

### F2 — Regra de visibilidade do manifesto não era aplicada em lugar nenhum — CRÍTICA — confiança 100 — CORRIGIDO
- **Onde:** origem em `code/lib.php` linhas 80–93 (`listarChamados` faz `SELECT * FROM chamados`
  sem filtro de dono) e manifestação em `code/index.php` linhas 52–67 (detalhe), 72–73
  (listagem) e 44–47 (export CSV).
- **Mecanismo:** o manifesto exige "cliente só vê os chamados que ele mesmo abriu; técnico vê
  qualquer chamado". O papel do usuário (`$_SESSION['papel']`) era gravado no login e **nunca
  consultado**. Na prática: (a) a listagem de uma cliente exibia todos os chamados de todos
  os clientes; (b) `index.php?ver=<id>` devolvia qualquer chamado por IDOR basta variar o id;
  (c) `index.php?export=csv` entregava o CSV completo a qualquer cliente.
- **Impacto:** vazamento massivo de dados entre clientes (títulos, descrições, datas — dados
  de suporte frequentemente contêm dados pessoais) e quebra direta da regra de negócio
  obrigatória do contrato.
- **O que fiz:** como as assinaturas públicas de `lib.php` são contrato, apliquei a regra no
  ponto de entrada (`index.php`): filtro por `usuario_id === uid` na listagem quando o papel é
  `cliente`; no detalhe, cliente que não é dono recebe "Chamado nao encontrado." (não revela
  que o id existe); o export CSV passou a exigir papel `tecnico` (HTTP 403 caso contrário),
  pois o formato do CSV ("uma linha por chamado") é contrato e não pode ser fatiado por dono
  sem quebrar a rotina noturna. Verificado com os usuários do seed: `ana` vê apenas 101/102/105,
  `ver=103` responde "nao encontrado", export responde 403; `carla` (técnica) vê tudo.

### F3 — XSS refletido via parâmetro `busca` — ALTA — confiança 100 — CORRIGIDO
- **Onde:** `code/index.php`, linhas 79 e 82.
- **Mecanismo:** `$busca` (vindo de `$_GET`) era ecoado cru em dois pontos: dentro do atributo
  `value="..."` do input e no parágrafo "Resultados para:". Um link
  `index.php?busca=<script>alert(document.cookie)</script>` executa JS na sessão da vítima.
  O caso do atributo é ainda pior: `" autofocus onfocus=... x="` quebra o atributo sem
  precisar de `<script>`.
- **Impacto:** roubo de sessão/automação em nome da vítima (inclusive de técnicos), defacement
  do painel.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos (`ENT_QUOTES`
  é necessário por causa do contexto de atributo com aspas duplas). Nenhuma outra saída estava
  crua (título, descrição e técnico já escapavam). Verificado: payload `<script>alert(1)</script>`
  sai escapado (`&lt;script&gt;`) e nada executa.

### F4 — Senhas armazenadas com MD5 puro — ALTA — confiança 100 — NÃO CORRIGIDO (reportado)
- **Onde:** `code/lib.php` linha 15 (`md5($senha)` na autenticação); reflexo em
  `code/schema.sql` linha 7 (`senha CHAR(32)`).
- **Mecanismo:** MD5 sem sal é rápido de computar (~bilhões/s por GPU); vazando a tabela
  (via F1, por exemplo), senhas fracas como as do seed caem em segundos por força bruta/
  rainbow table.
- **Impacto:** comprometimento de credenciais e reuso em outros sistemas do ISP.
- **Por que não corrigi:** trocar para `password_hash()` exige (a) migrar os hashes existentes
  (não é reversível — precisa re-hash no login ou reset de senhas) e (b) alargar a coluna
  `CHAR(32)` no banco de produção (bcrypt precisa de 60+ chars). É mudança de dados/esquema em
  produção, fora de uma evolução não-destrutiva de código. **Recomendação registrada:** migrar
  para `password_hash`/`password_verify` com re-hash transparente no login e `ALTER TABLE` na
  janela de manutenção.

### F5 — Segredos embutidos no código-fonte — ALTA — confiança 95 — NÃO CORRIGIDO (reportado)
- **Onde:** `code/config.php` linha 12 (fallback de `DB_PASS` com a senha real de produção) e
  linha 15 (`SMTP_API_KEY` fixa).
- **Mecanismo:** o fallback `getenv('DB_PASS') ?: 'N3tX@2013!prod'` embute a credencial de
  produção no repositório; quem lê o fonte (dev, backup, vazamento de repo) tem a senha do banco
  e a chave SMTP. A chave SMTP sequer tem caminho por variável de ambiente.
- **Impacto:** acesso direto ao banco e envio de e-mails em nome do ISP por qualquer pessoa com
  acesso ao código.
- **Por que não corrigi:** remover o fallback pode **derrubar a produção** se ela depender do
  valor embutido (não tenho como verificar), e trocar a chave SMTP no código não a invalida no
  provedor — rotação de segredo é ação operacional (revogar a antiga, distribuir a nova via
  ambiente). Alterar aqui seria cosmético com risco real de outage. **Recomendação registrada:**
  rotacionar ambos os segredos e passar a exigir as variáveis de ambiente.

### F6 — Sessão não regenerada no login (fixação de sessão) — MÉDIA — confiança 95 — CORRIGIDO
- **Onde:** `code/index.php`, linhas 23–28 (bloco de sucesso do login).
- **Mecanismo:** `session_start()` aceita um ID de sessão fornecido pelo cliente; no login
  bem-sucedido o ID era mantido. Um atacante que plante um `PHPSESSID` conhecido (link, cookie
  fixado em acesso compartilhado) passa a controlar a sessão autenticada da vítima.
- **Impacto:** sequestro de sessão pós-login.
- **O que fiz:** `session_regenerate_id(true)` imediatamente após autenticar, antes de gravar
  `$_SESSION['uid']`. Não altera nenhum contrato.

### F7 — Divisão por zero em `mediaResposta` — MÉDIA — confiança 95 — CORRIGIDO
- **Onde:** `code/lib.php`, linha 116 (`return $soma / $qtd;`).
- **Mecanismo:** se nenhum chamado tiver `minutos_resposta` preenchido (base nova, ou todos
  aguardando 1ª resposta — estado perfeitamente válido), `$qtd` é 0. Em PHP 8 isso lança
  `DivisionByZeroError` **não tratado**, derrubando a listagem inteira (`index.php` chama a
  função em todo acesso à home).
- **Impacto:** painel fora do ar exatamente no cenário "todos os chamados novos/sem resposta".
- **O que fiz:** guarda `return $qtd > 0 ? $soma / $qtd : 0.0;` — mantém o tipo `float` da
  assinatura e exibe "0 min". Verificado: com todos os `minutos_resposta = NULL`, retorna `0.0`
  sem exceção; com o seed, retorna 25.67 como antes.

### F8 — N+1 consultas ao resolver nome do técnico — MÉDIA — confiança 100 — CORRIGIDO
- **Onde:** `code/lib.php` linha 89 (laço de `listarChamados`) e linha 137 (laço de
  `exportarCsv`), ambas chamando `tecnicoNome()` por linha.
- **Mecanismo:** cada chamado dispara um `SELECT nome FROM usuarios WHERE id = ...` extra.
  A rotina noturna de exportação com N chamados executa N+1 queries; a listagem idem. Com
  crescimento da base, é o clássico gargalo de N+1.
- **Impacto:** latência e carga desnecessárias no MySQL, proporcionais ao volume de chamados.
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(u.nome, '-') AS tecnico_nome` nas duas
  funções — uma única query, preservando exatamente o comportamento anterior (`'-'` para
  técnico nulo **e** para id inexistente, igual ao original) e a chave `tecnico_nome` exigida
  pelo manifesto. A função `tecnicoNome()` foi mantida no arquivo (não é usada internamente
  agora, mas removê-la não traz ganho e pode quebrar algum consumidor não listado).
  Verificado: CSV idêntico ao esperado, incluindo `-` no chamado 104.

### F9 — `exportarCsv`: vazamento de handle e falha silenciosa — BAIXA — confiança 90 — CORRIGIDO (parcialmente)
- **Onde:** `code/lib.php`, linhas 125–150.
- **Mecanismo:** (a) se a query falhasse, a função retornava **sem `fclose($fp)`**, deixando o
  arquivo truncado aberto; (b) se `EXPORT_DIR` não existisse/não fosse gravável, `fopen` falha e
  a função retorna em silêncio — o usuário recebe HTTP 200 com corpo vazio, indistinguível de
  "CSV vazio"; (c) o arquivo é sempre gravado em caminho fixo e previsível
  (`/var/www/painel/tmp/chamados.csv`), potencialmente servível pela web e sujeito a corrida
  entre requisições concorrentes (uma sobrescreve a outra no meio do `readfile`).
- **Impacto:** arquivo temporário inconsistente sob concorrência; falhas operacionais mudas;
  possível exposição do CSV se o diretório for servido pelo web server.
- **O que fiz:** corrigi (a) — `fclose` no caminho de erro. Mantive (b) e (c) como estão:
  mudar o fluxo de escrita em disco/caminho altera comportamento que a rotina noturna pode
  observar (ver Decisões).

### F10 — `formatarStatus` devolve "Resolvido" para qualquer status desconhecido — BAIXA — confiança 85 — NÃO CORRIGIDO (reportado)
- **Onde:** `code/lib.php`, linhas 32–33 (o `else` final).
- **Mecanismo:** a cadeia `if 1 / else if 2 / else` mapeia **qualquer** valor ≠ 1,2 (0, 99, -1)
  para "Resolvido". Um status inválido vindo de dados legados ou de bug futuro seria exibido e
  exportado como "Resolvido", mascarando o problema e distorcendo o relatório gerencial.
- **Impacto:** erro silencioso de classificação; dado ruim vira "Resolvido".
- **Por que não corrigi:** o contrato só define os rótulos para 1→3 e o relatório gerencial casa
  por esses textos; qualquer novo rótulo (ex.: "Desconhecido") ou exceção mudaria a saída hoje
  observável para valores fora de contrato. Registro aqui como risco latente a tratar quando o
  dono do relatório gerencial puder aprovar um quarto rótulo.

## 3. Decisões — o que NÃO mudei e por quê

1. **Hash de senhas (F4):** manter MD5 é inaceitável a longo prazo, mas a troca exige migração
   de dados (`ALTER TABLE` + re-hash/reset de senhas) — ação de mudança de produção, não de
   código. Reportado com recomendação concreta.
2. **Segredos em `config.php` (F5):** remover o fallback de `DB_PASS` pode derrubar a produção
   caso ela dependa do valor embutido; a chave SMTP precisa ser **revogada no provedor**, não
   só sair do código. Rotação de segredos é operacional; reportado.
3. **Fluxo de escrita em disco do CSV (F9 b/c):** `EXPORT_DIR` e o padrão "grava arquivo →
   `readfile`" podem ser observados pela rotina noturna de exportação (arquivo em disco é um
   artefato consumível). Mexer nisso é risco de compatibilidade sem evidência de ganho
   imediato; corrigi apenas o vazamento de handle, que é comportamento interno.
4. **Rótulo para status fora de 1–3 (F10):** contrato define só 1→3 e há consumidor casando
   texto; mudar a saída para valores inválidos é decisão de produto, não de manutenção.
5. **Assinaturas públicas intactas:** em vez de adicionar parâmetros (`$uid`, `$papel`) a
   `listarChamados`/`verChamado`, a regra de visibilidade foi aplicada em `index.php`. Custo:
   a filtragem do cliente é feita em memória após a query — aceitável para o volume de um
   painel de suporte e **zero risco** de quebrar os consumidores externos das funções.
6. **Stack e camada de dados:** mysqli mantido em tudo (prepared statements da própria mysqli),
   sem dependências novas; nenhum arquivo renomeado ou movido.
7. **`tecnicoNome()` preservada** em `lib.php` embora sem uso interno: não está no manifesto,
   mas removê-la é risco sem benefício.
8. **CSRF no formulário de login:** não adicionado. Login-CSRF tem impacto baixo neste painel
   (não há ação sensível por GET e o form não muda estado além da própria sessão); ficaria
   como endurecimento futuro para não inflar o diff.

## 4. Verificação executada

- `php -l` em `lib.php`, `index.php`, `config.php`: sem erros.
- Teste de integração com MariaDB 11.8 (instância temporária isolada) + `php -S`, usando
  `schema.sql`/`seed.sql` do pacote:
  - login `ana` (cliente): listagem mostra **apenas** 101/102/105; `?ver=103` → "nao
    encontrado"; `?ver=101` → exibe; `?export=csv` → **403**.
  - login `carla` (técnica): listagem completa 101–105; `?ver=103` exibe.
  - SQLi `busca=' OR '1'='1` → 0 resultados (tratado como literal); busca `Lentidao` → 102.
  - XSS `busca=<script>alert(1)</script>` → saída escapada, 0 ocorrências cruas.
  - CSV: cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em`, 5 linhas ordenadas por id,
    rótulos de status corretos, `-` no chamado sem técnico.
  - `mediaResposta`: 25.67 com o seed; **0.0** com todos os `minutos_resposta` nulos (sem
    exceção).
- Rótulos de `formatarStatus`/`rotuloPrioridade` conferidos contra o contrato por asserts.
