# RELATORIO — Instância LEB-100-A

Sistema **Painel de Chamados (NetX ISP)** — PHP 8.x + mysqli, legado em produção desde 2013.
Superfícies públicas invertem **todas** as assinaturas do `manifest.md` e o contrato de
rotas/CSV/HTML foi preservado (verificado byte-a-byte no CSV e por testes HTTP).

---

## 1. Resumo

O código funciona e cumpre a maioria dos fluxos, mas carrega os sinais clássicos de
sistema legado exposto: **injeção SQL arbitrária na busca**, **XSS refletido** no mesmo
parâmetro, **ausência total da regra de negócio de visibilidade** (cliente enxerga e
exporta chamados de outros clientes — `IDOR`), senhas em **MD5** com segredos de
produção **embutidos na fonte**, e dois problemas de robustez/performance (divisão por
zero na métrica de SLA com banco vazio e consultas `N+1` na listagem/exportação).

A estratégia adotada foi **evoluir sem reescrever**: todas as correções são no mesmo
local, com as mesmas assinaturas públicas, mesma stack (mysqli) e mesmos formatos de
saída. O CSV exportado pelo `?export=csv` é **idêntico byte-a-byte** ao do código
original (verificação por `cmp`), a listagem mantém `id="tabela-chamados"` e as colunas
exigidas, as rotas `busca`/`ver`/`export` e os rótulos `1→Aberto`,
`2→Em atendimento`, `3→Resolvido` seguem intactos.

---

## 2. Achados (na ordem de prioridade de correção)

### F1 — SQL Injection na busca de chamados (crítica) — `code/lib.php:82`

**Onde:** `listarChamados()`, concatenando `$busca` cru na SQL
(`$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`) e executando via `$db->query()`.

**Mecanismo:** `$busca` vem direto de `$_GET['busca']` (`code/index.php:72`) sem
qualquer escaping ou prepared statement. O atacante injeta SQL literal no único ponto de
entrada não-parametrizado do sistema, com o mesmo privilégio da aplicação (`painel`, com
acesso a todo o `suporte`).

**Impacto:** dump completo de `chamados` (títulos, descrições, donos, técnicos) e de
qualquer outra tabela via `UNION`/`BOOLEAN` (`usuarios` incluído, com os hashes de
senha). É o pior vetor do sistema porque anula qualquer regra de visibilidade no nível de
dados.

**Severidade:** crítica · **Confiança: 100**

**O que fiz:** reescrevi a query com prepared statement
(`c.titulo LIKE ?`, parâmetro `%termo%`). O comportamento público não muda: mesmo retorno,
mesma ordem (`criado_em DESC`), mesma chave extra `tecnico_nome`.

---

### F2 — Regra de visibilidade ausente / IDOR (alta) — `code/index.php:53` (e 44–50, 72–97)

**Onde:** a tela de detalhe, a listagem e o export não consultam o papel do usuário.
`verChamado()` devolve qualquer `id`; `listarChamados()` devolve tudo; `exportarCsv()`
exporta tudo.

**Mecanismo:** após o login, `$_SESSION['papel']` existe mas nunca é usada para filtrar
dados. Um cliente autenticado (`ana/senha123`) pode iterar `index.php?ver=101..105` e ler
títulos/descrições/status de chamados abertos por outro cliente (`bruno`). O link
`index.php?export=csv` também vaza o histórico completo pelo mesmo cookie de cliente.

**Impacto:** quebra da regra de negócio declarada no manifesto ("um cliente só pode ver os
chamados que ele mesmo abriu") e exposição de dados de terceiros — violação de LGPD
classificável como vazamento.

**Severidade:** alta · **Confiança: 95**

**O que fiz:** a regra passou a ser aplicada **na camada web** (preservando as assinaturas
públicas de `lib.php` para a rotina noturna e o relatório gerencial, que consomem o
dataset completo fora de sessão):
- `index.php:58` — detalhe: se `cliente` e `usuario_id != uid`, responde
  "Chamado nao encontrado." (mesma resposta de id inexistente, sem confirmar existência);
- `index.php:78-83` — listagem: cliente só recebe os próprios chamados;
- `exportarCsv()` agora filtra por `usuario_id` quando a sessão web é de cliente; fora de
  sessão (rotina noturna CLI) continua exportando tudo, preservando o consumidor legado.

---

### F3 — XSS refletido na busca (alta) — `code/index.php:79` e `:82`

**Onde:** `echo '<input name="busca" value="' . $busca . '">'` e
`echo '<p>Resultados para: ' . $busca . '</p>'`.

**Mecanismo:** `$busca` (GET) é ecoada sem `htmlspecialchars`. `?busca="><script>…`
quebra o atributo `value` e injeta HTML/JS; o parágrafo idem. Ao clicar num link de busca
(ou via e-mail/IM com payload), o script roda no contexto da vítima **logada**.

**Impacto:** roubo de sessão (mitigado agora pelo `HttpOnly` — F7), execução de ações em
nome do usuário logado (incluindo puxar o CSV de todos os chamados se for técnico) e
defacement da página.

**Severidade:** alta · **Confiança: 100**

**O que fiz:** escape com `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` tanto no `value`
quanto no parágrafo. Validado com payloads `<script>alert(1)</script>` no teste HTTP.

---

### F4 — Senhas armazenadas/verificadas com MD5 (alta) — `code/lib.php:15`, `code/schema.sql:7`

**Onde:** `autenticar()` calcula `md5($senha)` e compara na SQL; `schema.sql` fixa
`senha CHAR(32)`; `seed.sql` grava `MD5(...)`. Além disso o retorno da função era a linha
inteira (incluindo o hash em `$linha['senha']`).

**Mecanismo:** MD5 é instantâneo de forçar por dicionário/GPU; qualquer dump do banco
(ver F1/F2) expõe senhas recuperáveis em minutos para senhas comuns — e há reuso
presumível com outros sistemas do ISP.

**Impacto:** comprometimento de contas de clientes e técnicos; senha de `carla`/`diego`
têm papel elevado.

**Severidade:** alta · **Confiança: 100**

**O que fiz:** `autenticar()` agora busca por `login` e **verifica** o hash na aplicação:
aceita hashes **bcrypt** via `password_verify()` e mantém o login de **hashes md5
legados** via `hash_equals()` — migração transparente e compatível (um banco antigo
continua logando, um banco novo já usa bcrypt). O retorno passa a ser exatamente
`['id','nome','papel']` (sem vazar `senha`/`login`). `schema.sql` → `senha VARCHAR(255)`
e `seed.sql` com hashes bcrypt reais. Testado com banco populado por ambos os formatos.

---

### F5 — Segredo de serviço externo embutido no código-fonte (média) — `code/config.php:15`

**Onde:** `define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05')`.

**Mecanismo:** a credencial do serviço de e-mail transacional está literal no repositório.
Qualquer pessoa com acesso ao código (funcionários, repositórios internos, backups,
crash-dumps) obtém a chave sem precisar explorar nada.

**Impacto:** abuso do serviço de e-mail em nome do ISP (envio em massa), custo/fraude.

**Severidade:** média · **Confiança: 100**

**O que fiz:** a chave é lida de `getenv('SMTP_API_KEY')` (sem fallback embutido),
mantendo a constante definida para quem a consome. Como a aplicação atual não usa a chave,
a remoção não afeta o runtime.

> A **senha do banco** (`DB_PASS`) também tem fallback embutido (`config.php:12`). Por
> risco de quebrar a subida do legado sem variáveis de ambiente, **decidi NÃO alterá-la**
> (ver Decisões).

---

### F6 — Divisão por zero na métrica de SLA (média) — `code/lib.php:116`

**Onde:** `return $soma / $qtd;` em `mediaResposta()`.

**Mecanismo:** quando nenhum chamado tem `minutos_resposta` (banco recém-criado ou
primeiro chamado ainda sem primeira resposta), `$qtd == 0` e o PHP ≥ 8 lança
`DivisionByZeroError`. É o código executado na home (`index.php:84`).

**Impacto:** a página inicial morre com 500 até existir o primeiro chamado respondido —
indisponibilidade em produção logo após deploy/manutenção.

**Severidade:** média · **Confiança: 100**

**O que fiz:** guarda explícita `if ($qtd === 0) return 0.0;`. Validado com tabela temporária
vazia (retorna `0.0` sem erro) e com os dados do seed (`25.67`).

---

### F7 — Sessão sem hardening (média) — `code/index.php:15` e `:22-28`

**Onde:** `session_start()` com defaults e entrada de sessão no login sem regenerar id.

**Mecanismo:** o cookie de sessão não marca `HttpOnly` (acessível ao JS — um XSS como F3
rouba a sessão diretamente), não tem `SameSite` (permite CSRF associado a rotas GET
sensíveis como `?export=csv`) e o id não é regenerado no login (sessão pré-login pode ser
"fixed" e assumida).

**Impacto:** roubo de sessão com uma falha de XSS; CSRF básico; session fixation.

**Severidade:** média · **Confiança: 85**

**O que fiz:** `session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax',
'secure'=>)]` antes do `session_start()`, e `session_regenerate_id(true)` imediatamente
após autenticação.

---

### F8 — Consultas N+1 na listagem e no export (média) — `code/lib.php:89` e `:137`

**Onde:** na listagem, para **cada** chamado, era chamado `tecnicoNome()` executando outra
`SELECT nome FROM usuarios WHERE id=...` (N+1). O mesmo ocorria no `exportarCsv()`.

**Mecanismo:** com `N` chamados são `N+1` round-trips ao banco; página e relatório
crescem linearmente em idas ao SGBD. Dado o uso em relatório noturno e em telas com
centenas de chamados, é degradação real de latência e carga.

**Impacto:** páginas lentas e exportação noturna pesada; transacionado com o volume
histórico, tende a estourar timeouts.

**Severidade:** média · **Confiança: 90**

**O que fiz:** `listarChamados()` e `exportarCsv()` agora usam `LEFT JOIN usuarios u ON
u.id = c.tecnico_id` trazendo `u.nome AS tecnico_nome` na própria query (1 round-trip).
A chave `tecnico_nome` e o valor `'-'` para chamado sem técnico (contrato do manifesto)
são mantidos. Comparação do CSV produzido: byte-a-byte igual ao original.

---

## 3. Decisões — o que deliberadamente NÃO mudei

| O quê | Por quê |
| --- | --- |
| **Fallback da senha do banco em `config.php:12`** | Remover o fallback quebraria a subida do sistema em ambientes sem `DB_PASS` no environment (o processo hoje depende dele para conectar). Não há gerenciador de segredos para onde mover; a correção definitiva é exigir env var no deploy — anotada como recomendação, não aplicada para não derrubar o runtime. |
| **Assinaturas públicas de `lib.php`** | `listarChamados`/`verChamado`/`exportarCsv` continuam aceitando/retornando o dataset completo **sem** parâmetro de usuário. A rotina noturna e o relatório gerencial dependem disso (manifesto §1). A regra de visibilidade (F2) foi aplicada na camada web e no `exportarCsv()` apenas quando há sessão web de cliente. |
| **Hashes md5 já gravados em banco** | Não vou "migrar forçada" de hashes: comprometeria as contas existentes do legado. A correção (F4) torna a verificação compatível com md5 legado **e** bcrypt, e o `seed.sql` passa a usar bcrypt; quem está em produção pode migrar on-island no próximo reset. |
| **`formatarStatus()` para valores fora de 1–3** | O religamento é por texto exato no relatório gerencial; o fallback atual (`Resolvido`) é comportamento herdado. Ajustar o tratamento de valores inválidos não tem consumidor conhecido e mudaria o contrato de rótulo. |
| **Rota `index.php=media de resposta` do topo para clientes logados** | A métrica agregada é exibida para todos; ela não permite deduzir um chamado individual e alterá-la exigiria mudar assinatura/escopo de `mediaResposta()`. Cust/benefício negativo. |
| **CSRF token no form de login** | O login não tem efeito colateral destrutivo e a única rota mutável via sessão é mitigada por `SameSite=Lax` (F7). Adicionar token mudaria o HTML declarado do form sem ganho real hoje. |
| **Troca de stack / novas dependências** | Proibido pelo §6 da tarefa e desnecessário: bcrypt nativo do PHP resolve o F4; prepared statements nativos do mysqli resolvem o F1. |

---

## 4. Checklist de entrega

- code/ alterado in-place (mesmos nomes e caminhos) — **ok**
- RELATORIO.md com mecanismo, severidade e confiança 0–100 por achado — **ok**
- achados.json válido, bloco `leb` copiado do topo do TAREFA.md — **ok**
- Todo achado do relatório está no JSON e vice-versa — **ok**
- Seção Decisões presente — **ok**
- Manifesto respeitado: assinaturas, rotas, CSV (byte-a-byte), HTML e regra de negócio — **ok**