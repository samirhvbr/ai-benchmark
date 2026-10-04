# RELATORIO.md — Painel de Chamados (NetX ISP) — LEB-100-A

## Resumo

O sistema é um painel PHP 8 + mysqli pequeno e coeso, mas carrega problemas graves típicos de
legado de 2013: **injeção de SQL** na busca, **violação da regra de visibilidade** declarada no
manifesto (cliente via chamados de outros clientes na listagem, no detalhe e no CSV — IDOR),
**XSS refletido**, **senhas em MD5**, **segredos de produção commitados** e um crash de
**divisão por zero** no PHP 8. Foram corrigidos os itens de segurança e o crash, preservando
integralmente a superfície pública do `manifest.md` (assinaturas, rotas, parâmetros, formato do
CSV, estrutura do HTML e regras de negócio). Nada foi renomeado, movido ou reescrito.

Verificação: suíte de 29 asserções executada contra MariaDB 11.8 real (schema+seed do pacote) —
autenticação, busca (incl. payload de SQLi e curinga `%`), visibilidade por papel nas três
saídas (listagem/detalhe/CSV via servidor PHP built-in), rótulos, média com banco vazio e
cabeçalho do CSV. Tudo passa.

---

## Achados (na ordem de priorização)

### F1 — Injeção de SQL na busca de chamados
- **Onde:** `code/lib.php:89` (em `listarChamados`); entrada em `code/index.php` (`$_GET['busca']`).
- **Mecanismo:** o termo de busca era concatenado direto na query:
  `" WHERE titulo LIKE '%" . $busca . "%'"`. Um valor como `' OR '1'='1` altera a lógica do
  `WHERE`; com `UNION` empilhado é possível ler outras tabelas (ex.: logins e hashes de
  `usuarios`) dentro da própria listagem renderizada.
- **Impacto:** leitura arbitrária do banco por qualquer usuário autenticado (a rota exige login,
  mas qualquer cliente tem conta) — incluindo hashes MD5 de senha de todos os usuários.
- **Severidade:** alta. **Confiança:** 100 (reproduzido em teste: antes da correção o payload
  retornava todos os chamados; depois, zero linhas e nenhum erro SQL).
- **Correção:** `prepare` + `bind_param` com `LIKE ? ESCAPE '\\'`; `%`, `_` e `\` digitados são
  escapados para virar literais, preservando a semântica "contém" do filtro por título.

### F2 — Regra de visibilidade não aplicada (IDOR + vazamento na listagem e no CSV)
- **Onde:** `code/index.php:53` (detalhe `?ver=`), `:91` (listagem) e `:52-57` (export CSV).
- **Mecanismo:** `$papel = $_SESSION['papel']` era lido e **nunca usado**. `listarChamados`
  retorna todos os chamados para qualquer papel; `verChamado` busca por id sem checar dono; e
  `exportarCsv` exportava a tabela inteira para qualquer usuário logado. O manifesto declara:
  cliente só vê os chamados que abriu; técnico vê todos.
- **Impacto:** qualquer cliente lia título, descrição, status e técnico de chamados de todos os
  outros clientes (dados pessoais e de suporte), bastando logar e/ou incrementar `?ver=<id>`.
- **Severidade:** alta. **Confiança:** 100 (confirmado via HTTP: cliente `ana` listava/abria/baixava
  chamados de `bruno` antes da correção; depois vê apenas os próprios, e `?ver=103` responde
  "Chamado nao encontrado").
- **Correção:** flag `$ehTecnico = ($papel === 'tecnico')` aplicada nas três saídas: filtro por
  `usuario_id` na listagem (em PHP, após `listarChamados`, para não mudar a assinatura pública),
  negação do detalhe quando o chamado não é do cliente, e nova função **não pública**
  `exportarCsvFiltrado(mysqli $db, int $donoId)` para o CSV do cliente (mesmo cabeçalho e
  ordenação). `exportarCsv(mysqli $db)` — usada pela rotina noturna — **não mudou de assinatura
  nem de comportamento**.

### F3 — Senhas armazenadas/comparadas em MD5
- **Onde:** `code/lib.php:15` (`md5($senha)`); coluna `usuarios.senha CHAR(32)` (`code/schema.sql:8`).
- **Mecanismo:** MD5 é hash rápido sem sal: vazamento da tabela permite quebra massiva por
  rainbow table/GPU. A coluna de 32 chars só comporta MD5, então a migração plena para
  `password_hash` exige alteração de schema — fora do escopo desta evolução (ver Decisões).
- **Impacto:** em caso de vazamento (facilitado pelo F1), todas as senhas caem em minutos.
- **Severidade:** alta. **Confiança:** 100 (código e seed confirmam MD5).
- **Correção (parcial, sem quebra de contrato):** eliminado o `md5()` no PHP; a comparação passou
  a ser feita pelo banco (`senha = MD5(?)`), mantendo o login funcionando com as linhas já
  gravadas (o próprio seed usa `MD5()`), sem trafegar hash em query logável e sem mudar a
  assinatura `autenticar(mysqli, string, string): ?array`. A migração para `password_hash` +
  coluna `VARCHAR(255)` fica registrada como trabalho futuro obrigatório (Decisões).

### F4 — XSS refletido via parâmetro `busca`
- **Onde:** `code/index.php:102` (atributo `value` do input) e `:105` (`Resultados para: ...`).
- **Mecanismo:** `$_GET['busca']` era ecoado sem escape. `?busca=<script>...</script>` executa JS
  no navegador da vítima autenticada; no `value="..."` dava para quebrar o atributo com aspas.
- **Impacto:** roubo de sessão (o cookie não tem flags de segurança — ver F8), ações em nome do
  usuário, defacement do painel.
- **Severidade:** alta. **Confiança:** 100 (reproduzido: antes, `<script>` cru no HTML; depois,
  escapado).
- **Correção:** `htmlspecialchars($busca)` no texto e `htmlspecialchars($busca, ENT_QUOTES)` no
  atributo. Título/descrição/técnico já eram escapados e continuam iguais.

### F5 — Segredos de produção no repositório
- **Onde:** `code/config.php:12` (senha do banco de produção como fallback) e `:15` (chave SMTP).
- **Mecanismo:** credenciais reais em texto claro no código versionado: qualquer clone, backup
  ou vazamento do repo entrega o banco de produção e a conta de e-mail transacional.
- **Impacto:** comprometimento total do banco e do serviço de e-mail; como a credencial esteve
  commitada, ela deve ser considerada vazada.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` agora vêm exclusivamente do ambiente
  (`getenv(...)`), sem fallback com segredo. **Ação operacional obrigatória:** rotacionar a
  senha do banco e revogar a chave SMTP antigas.

### F6 — Divisão por zero em `mediaResposta` (crash no PHP 8)
- **Onde:** `code/lib.php:130` (`return $soma / $qtd;`).
- **Mecanismo:** se nenhum chamado tem `minutos_resposta` preenchido (base nova, ou logo após
  limpeza), `$qtd` é 0 e o PHP ≥ 8 lança `DivisionByZeroError` — a página inteira do painel
  morre antes de renderizar.
- **Impacto:** indisponibilidade total do painel em uma condição de dados perfeitamente normal.
- **Severidade:** média. **Confiança:** 100 (reproduzido em teste com todos os minutos nulos).
- **Correção:** `return $qtd > 0 ? $soma / $qtd : 0.0;` — mantém o tipo `float` do contrato e o
  topo do painel exibe "0 min".

### F7 — Consulta N+1 na listagem e no CSV (`tecnicoNome`)
- **Onde:** `code/lib.php:69-76` (query por id) chamada em loop em `listarChamados` (lib.php:97)
  e nos dois exports.
- **Mecanismo:** para cada chamado, uma query extra `SELECT nome FROM usuarios WHERE id = N`.
  Com M chamados são M+1 round-trips; na rotina noturna de exportação (base inteira) isso é um
  multiplicador de carga desnecessário.
- **Impacto:** latência e carga crescentes com o volume; em exportações grandes, degradação
  perceptível.
- **Severidade:** baixa. **Confiança:** 100.
- **Correção:** nenhuma (ver Decisões) — resolver via JOIN exigiria alterar a projeção de
  `listarChamados`/`exportarCsv` com risco de colisão de colunas (`SELECT *` + `nome`) e a
  função `tecnicoNome` é parte do código consumido internamente; o ganho não paga o risco nesta
  manutenção.

### F8 — Sessão sem regeneração de id no login e cookie sem flags
- **Onde:** `code/index.php:24-27` (login grava `$_SESSION` sem regenerar) e `session_start()`
  em `:14` sem `session_set_cookie_params`.
- **Mecanismo:** um id de sessão fixado antes do login (session fixation) continua válido depois
  da autenticação. Somado ao F4 (XSS), o cookie acessível via JS facilita o sequestro.
- **Impacto:** sequestro de conta sem credenciais.
- **Severidade:** média. **Confiança:** 85 (fixação mitigada por config de servidor possível,
  mas nada no código impedia).
- **Correção:** `session_regenerate_id(true)` no login (verificado: o id muda). Flags do cookie
  ficaram como estão (ver Decisões) para não alterar comportamento de consumidores que possam
  depender de acesso ao cookie fora de HTTPS.

### F9 — Exportação CSV não atômica e vazamento de arquivo parcial em erro
- **Onde:** `code/lib.php:137-153` (`exportarCsv` grava `EXPORT_DIR/chamados.csv` e devolve com
  `readfile`); caminho fixo compartilhado entre requisições concorrentes.
- **Mecanismo:** dois exports simultâneos (web + rotina noturna) escrevem no mesmo arquivo; um
  pode ler metade do outro. E se a query falhasse (`$res === false`), a função retornava sem
  `fclose`, deixando no disco um CSV só com cabeçalho que pareceria válido.
- **Impacto:** CSV truncado/corrompido entregue a consumidores (integração de faturamento).
- **Severidade:** baixa. **Confiança:** 80 (condição de corrida real, janela pequena).
- **Correção:** fechamento do handle no caminho de erro (`fclose` antes do `return`). A escrita
  atômica (tmp + rename) e nome por requisição ficam como proposta (Decisões) por mexer no
  arquivo que a rotina noturna lê em caminho fixo.

### F10 — Sem proteção CSRF no formulário de login
- **Onde:** `code/index.php:20-36` (POST de login aceito sem token).
- **Mecanismo:** login CSRF permite autenticar a vítima numa conta do atacante e observar o que
  ela fizer logada. Impacto limitado porque o painel é basicamente de leitura e não há outras
  ações por POST.
- **Impacto:** baixo (login-CSRF, sem ações sensíveis a montante).
- **Severidade:** baixa. **Confiança:** 70.
- **Correção:** nenhuma (Decisões) — adicionar token muda o HTML do formulário de login, que não
  faz parte do contrato, mas o custo/benefício nesta janela não compensa; registrado para a
  próxima iteração junto com as flags de cookie.

---

## Decisões — o que NÃO foi mudado e por quê

1. **Migração plena de senha para `password_hash`/`password_verify` (F3).** Exige alargar
   `usuarios.senha` de `CHAR(32)` e regravar hashes — mudança de schema e de dados que altera a
   camada pública de persistência. Fiz a melhoria compatível (MD5 calculado no banco, sem hash
   em query) e deixei a migração como trabalho agendado: alterar coluna para `VARCHAR(255)`,
   re-hash no próximo login bem-sucedido (padrão "upgrade on login").
2. **`formatarStatus` mapeia qualquer valor ≠ 1,2 para "Resolvido".** O manifesto define os
   três rótulos e o CSV consumido externamente depende deles; tratar valores inesperados como
   erro mudaria saída contratual. Mantido.
3. **Assinatura e semântica de `exportarCsv`/`listarChamados`.** A filtragem por dono foi
   implementada fora delas (em `index.php` e na nova função não pública `exportarCsvFiltrado`)
   para não quebrar a rotina noturna nem o relatório gerencial, que esperam o conjunto completo.
4. **JOIN para eliminar o N+1 (F7).** Exigiria mudar a projeção das queries (`SELECT *` +
   coluna `nome` colide com `chamados` se um dia existir) e tocar código consumido por outros
   scripts; risco/benefício desfavorável nesta janela.
5. **Escrita atômica do CSV e nome de arquivo por requisição (F9).** A rotina noturna lê
   `EXPORT_DIR/chamados.csv` em caminho fixo; mudar o alvo sem coordenar com o consumidor
   quebraria a integração. Corrigi apenas o leak de handle no erro.
6. **Flags de cookie de sessão (HttpOnly/SameSite/Secure) (F8).** Não sei se algum consumidor do
   ISP lê o cookie fora de HTTPS; endurecer por config de código pode quebrar integração
   silenciosamente. Recomendo aplicar via `php.ini`/vhost com janela de validação.
7. **Token CSRF no login (F10).** Muda o HTML do formulário; impacto do login-CSRF é baixo
   (painel de leitura). Fica para a próxima iteração junto com as flags de cookie.
8. **`tecnicoNome` usa query interpolada com int (`WHERE id = ' . $tecnicoId`).** O parâmetro é
   `?int` tipado na assinatura, então não há injeção; converti o padrão de concatenação apenas
   onde havia entrada de usuário (F1) para manter a menor mudança possível.
9. **`$_SESSION['papel']` confiado como vem do login.** O papel é gravado no login a partir do
   banco e não é mutável por request; revalidar a cada request seria defesa extra com custo de
   query — não incluída (compatível e sem vetor de escalação conhecido neste fluxo).
10. **Stack e estrutura de arquivos.** Nada renomeado, movido ou reescrito; nenhuma dependência
    nova. As 29 verificações automatizadas (funções + HTTP) rodam contra `schema.sql`+`seed.sql`
    sem alterar os dados (transação com rollback).
