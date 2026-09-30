# RELATORIO — LEB-100-A

## Resumo

Painel PHP/mysqli de chamados (NetX ISP), em produção desde 2013. A superfície pública (`autenticar`, listagem, detalhe, CSV, rótulos) está preservada. O código original concatenava o termo de busca no SQL, não aplicava a regra de visibilidade cliente/técnico em nenhuma rota, ecoava `busca` sem escape e dependia de um arquivo temporário em caminho absoluto para exportar. Corrigi esses pontos de forma pontual, sem trocar a stack nem as assinaturas de `lib.php`.

## Achados

### F1 — Injeção SQL em `listarChamados` via `busca`

- **Onde:** `code/lib.php:82` (`linha_fim` 82).
- **O que é:** o termo de busca era interpolado em `WHERE titulo LIKE '%…%'`.
- **Mecanismo:** `index.php` passa `$_GET['busca']` direto para `listarChamados`. Em `lib.php:82` a string entra no SQL sem `prepare`/`bind`. Um valor como `' OR 1=1 -- ` altera o predicado; um `UNION SELECT` lê `usuarios` (hashes MD5 inclusive). `autenticar` já usava statement preparado — só a listagem não.
- **Impacto / severidade:** leitura e alteração lógica do banco por qualquer usuário autenticado (e, na prática, a busca é o vetor). **critica**.
- **Confiança:** 100.
- **O que fiz:** `SELECT` com `LEFT JOIN` e `LIKE ?`; `bind_param('s', …)`; `%`/`_`/`\` do usuário são escapados para não virarem curingas.

### F2 — Regra de visibilidade não aplicada (IDOR)

- **Onde:** `code/index.php:73` (listagem); o mesmo buraco em `index.php:44` (CSV) e `index.php:52` (detalhe).
- **O que é:** cliente via os chamados dos outros.
- **Mecanismo:** após o login, `$uid` e `$papel` são lidos e nunca usados. `listarChamados` devolve a tabela inteira; `verChamado` carrega por id sem dono; `exportarCsv` grava todos os chamados. Com `ana`/`senha123` dava para abrir `index.php?ver=104` (chamado do Bruno, “Fatura em duplicidade”) e baixar o CSV completo.
- **Impacto / severidade:** vazamento de tickets de outros clientes (título, descrição, técnico). **critica**.
- **Confiança:** 100.
- **O que fiz:** filtro só no `index.php` (produto). Técnico vê tudo; cliente só `usuario_id === uid`. Detalhe de terceiro responde “Chamado nao encontrado.” CSV da rota web, para cliente, filtra e ordena por `id`. **Não** alterei as assinaturas de `listarChamados` / `verChamado` / `exportarCsv`: a rotina noturna e o relatório gerencial continuam vendo a base toda.

### F3 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79`–`82`.
- **O que é:** `$_GET['busca']` ia cru no `value=""` e em “Resultados para:”.
- **Mecanismo:** `value="' . $busca . '"` fecha o atributo com `"` e injeta HTML/JS. A linha 82 ecoa o mesmo termo no corpo. Título e descrição já passavam por `htmlspecialchars`; a busca não.
- **Impacto / severidade:** script no navegador da sessão autenticada. **alta**.
- **Confiança:** 95.
- **O que fiz:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos.

### F4 — Segredo SMTP versionado em `config.php`

- **Onde:** `code/config.php:15`.
- **O que é:** `SMTP_API_KEY` com valor de produção no fonte.
- **Mecanismo:** constante `'netx-smtp-9f83e2c1a7b64d05'` no repositório. Qualquer cópia do pacote leva a chave da central de e-mail. Não há uso no painel, mas o valor está no artefato.
- **Impacto / severidade:** comprometimento do canal transacional se a chave for válida. **alta**.
- **Confiança:** 95.
- **O que fiz:** `getenv('SMTP_API_KEY') ?: ''`. A constante permanece (não quebra include). A chave em si saiu do código. A senha de fallback do MySQL ficou — ver Decisões.

### F5 — `exportarCsv` falha em silêncio se `EXPORT_DIR` não existe

- **Onde:** `code/lib.php:125`–`128`.
- **O que é:** o CSV só era gerado se `fopen('/var/www/painel/tmp/chamados.csv')` funcionasse.
- **Mecanismo:** caminho absoluto fixo. Se o diretório não existe (ambiente novo, permissão, chroot), `fopen` retorna `false` e a função dá `return` sem header e sem corpo. Além disso todos os usuários compartilhavam o mesmo arquivo — duas exportações concorrentes se sobrescrevem, e um cliente autenticado lia o dump completo no disco.
- **Impacto / severidade:** export da rota contratada vazio; condição de corrida no arquivo. **alta**.
- **Confiança:** 90.
- **O que fiz:** cabeçalhos HTTP e `fputcsv` em `php://output`, com `JOIN` para o técnico. Contrato do manifesto (“escreve o CSV na saída”, cabeçalho exato, ordem por `id`, rótulos de `formatarStatus`) mantido. `EXPORT_DIR` continua definido.

### F6 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php:116`.
- **O que é:** `$soma / $qtd` com `$qtd = 0`.
- **Mecanismo:** o laço só incrementa `$qtd` quando `minutos_resposta IS NOT NULL`. Banco vazio ou tickets só com NULL (ex.: seed se removermos 101/102/105) deixa `$qtd` em 0. Em PHP 8 isso é `DivisionByZeroError` e derruba o painel na listagem, que sempre chama `mediaResposta`.
- **Impacto / severidade:** página inicial indisponível. **media**.
- **Confiança:** 92.
- **O que fiz:** `AVG(minutos_resposta)` no SQL; `null`/falha → `0.0`. Assinatura `float` inalterada.

### F7 — N+1 em `listarChamados` / `exportarCsv`

- **Onde:** `code/lib.php:88`–`90` (e o mesmo padrão em `lib.php:137`).
- **O que é:** um `SELECT nome FROM usuarios` por linha.
- **Mecanismo:** `tecnicoNome()` dispara query por chamado. Listagem e CSV de 5 linhas no seed = 5 queries extras; em volume vira latência linear.
- **Impacto / severidade:** degradação proporcional ao número de tickets. **media**.
- **Confiança:** 95.
- **O que fiz:** `LEFT JOIN usuarios` com `COALESCE(nome, '-')`, mesma chave `tecnico_nome`. `tecnicoNome()` permanece (não é superfície pública, mas pode ter caller interno).

### F8 — Senha armazenada em MD5

- **Onde:** `code/lib.php:15`.
- **O que é:** `md5($senha)` comparado com `usuarios.senha CHAR(32)`.
- **Mecanismo:** MD5 é determinístico e rápido. Com o dump (via F1) ou o seed, a senha cai em rainbow table. `ana`/`senha123` e `carla`/`tecmaster` são exatamente esse esquema.
- **Impacto / severidade:** comprometimento de contas se o hash vaza. **media**.
- **Confiança:** 100.
- **O que fiz:** nada. Schema, seed e hashes já gravados dependem de MD5; migrar para `password_hash` quebraria o login de todo mundo sem job de migração, que está fora deste pacote.

### F9 — Fixação de sessão no login

- **Onde:** `code/index.php:15` e `24`–`26`.
- **O que é:** o id de sessão anterior ao login era reaproveitado.
- **Mecanismo:** `session_start()` e, no POST bem-sucedido, grava `uid`/`papel` na sessão já existente. Cookie sem `HttpOnly`. Um id plantado (cookie ou URL) vira sessão autenticada.
- **Impacto / severidade:** sequestro de sessão pós-login. **media**.
- **Confiança:** 80.
- **O que fiz:** `session_regenerate_id(true)` após autenticar; `session.cookie_httponly=1`.

### F10 — Concatenação SQL em `verChamado` e `tecnicoNome`

- **Onde:** `code/lib.php:100` e `lib.php:69`.
- **O que é:** `'… id = ' . $id` sem placeholder.
- **Mecanismo:** os parâmetros são `int`/`?int`, então o type hint do PHP já coerção para inteiro e o exploit clássico de string não passa por essas funções. Ainda assim `query()` em false (prepare/servidor) seguido de `fetch_assoc()` fataliza.
- **Impacto / severidade:** hoje baixo; vira alto se um caller futuro passar a função sem o hint. **baixa**.
- **Confiança:** 70.
- **O que fiz:** `prepare` + `bind_param('i', …)` e retorno seguro (`null` / `'-'`) se o statement falhar.

## Decisões

O que **não** mudei, e por quê:

- **Assinaturas de `lib.php` e camada mysqli.** Contrato explícito; outros scripts do ISP chamam essas funções. Filtro de visibilidade ficou no `index.php`.
- **`listarChamados` / `verChamado` / `exportarCsv` sem parâmetro de usuário.** Incluir `$uid` mudaria a superfície. Quem consome a lib para relatório/faturamento precisa da base completa.
- **Fallback `DB_PASS = 'N3tX@2013!prod'`.** Remover a senha embutida derruba o painel se o processo ainda sobe sem `DB_PASS` no ambiente (comentário: “legado”). Só a chave SMTP, que ninguém neste pacote lê, saiu do fonte.
- **Hash MD5 e `schema.sql` / `seed.sql`.** Ver F8. Evoluir o hash exige migração e `CHAR(32)` no schema.
- **`formatarStatus` mapeando qualquer coisa ≠ 1,2 para `"Resolvido"`.** O relatório gerencial casa por esses três textos. Estreitar o `else` poderia mudar rótulo de status inesperado.
- **Lógica de `rotuloPrioridade`.** Não está no contrato de valores; aninhar ifs é feio, mas prioridade 4 com SLA ok → “Alto - dentro do SLA” pode ser regra de negócio antiga.
- **KPI `mediaResposta` visível para cliente.** É agregado, não um chamado. A função pública não recebe usuário; filtrar no SQL mudaria o número que o relatório gerencial espera.
- **HTML da listagem, nomes GET (`busca`, `ver`, `export`), cabeçalho CSV.** Contrato.
- **Não reescrevi o painel** (auth framework, PDO, templates). Evolução pontual.
