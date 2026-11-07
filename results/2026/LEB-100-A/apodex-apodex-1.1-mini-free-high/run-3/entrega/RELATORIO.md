# Relatório Técnico — Manutenção do Painel de Chamados (LEB-100-A)

## 1. Resumo

Sistema legado PHP + MySQL (mysqli), em produção desde 2013, com consumidores externos
(rotina de exportação, relatório gerencial, integração de faturamento) que dependem das
assinaturas de `lib.php`, das rotas de `index.php` e do formato do CSV.

Foram encontrados **dez** achados: seis de segurança (um **crítica** — injeção de SQL ativa),
dois de bug e dois de qualidade. **Oito foram corrigidos** mantendo a superfície pública
intacta; **dois só foram reportados** (MD5 em senhas — incompatível com o schema e seed
entregues — e a estrutura HTML minimalista, que o manifesto não exige).

A correção mais sensível foi a **regra de visibilidade**, que o manifesto declara obrigatória
("um cliente só pode ver os chamados que ele mesmo abriu") e que o código ignorava por
completo: as variáveis `$uid`/`$papel` em `index.php` eram mortas. Como as assinaturas
públicas não admitem parâmetros de contexto, o filtro é aplicado lendo `$_SESSION['papel']`
e `$_SESSION['uid']` dentro das próprias funções de `lib.php`; a degradação é segura
(ausência de sessão ⇒ comportamento de técnico, isto é, visibilidade total, igual ao
comportamento atual).

---

## 2. Achados

### F1 — Injeção de SQL na busca via `$_GET['busca']`
- **Onde:** `code/lib.php`, linha 82 (`... LIKE '%` . `$busca` . `"%'`), função `listarChamados`.
- **Mecanismo:** `$busca` vem direto do usuário (`index.php:72`) e é interpolada crua na
  consulta. Payload como `?busca=' OR '1'='1` devolve todos os chamados; com `UNION SELECT`
  é possível extrair colunas da tabela `usuarios` (incluindo os hashes MD5).
- **Impacto/severidade:** vazamento total de dados; **crítica**.
- **Confiança:** 95.
- **O que foi feito:** consulta preparada com `bind_param('s', $like)`. Assinatura e retorno
  de `listarChamados` inalterados.

### F2 — Regra de visibilidade (cliente só vê seus chamados) ignorada
- **Onde:** `code/lib.php`: `listarChamados` (linha 80), `verChamado` (linha 98),
  `exportarCsv` (linha 123).
- **Mecanismo:** nenhuma das três consultas filtra por `usuario_id`; os clientes logados
  listam, visualizam e exportam todos os chamados, inclusive de outros clientes, violando o
  contrato do manifesto.
- **Impacto/severidade:** exposição indevida de dados de múltiplos clientes; **alta**.
- **Confiança:** 92.
- **O que foi feito:** nas três funções, se `$_SESSION['papel'] === 'cliente'` adiciona-se
  `usuario_id = ?` (ou `id = ? AND usuario_id = ?` no detalhe). Técnicos veem tudo. A
  ausência de sessão (chamadores externos em CLI) mantém a visibilidade total, compatível
  com o comportamento legado.

### F3 — XSS refletido na listagem via parâmetro `busca`
- **Onde:** `code/index.php`, linhas 79 (atributo `value` do `<input>`) e 82 ("Resultados
  para:").
- **Mecanismo:** `$busca` é interpolada sem `htmlspecialchars` nos dois pontos. Um link
  enviado a um cliente executa JavaScript no contexto do painel.
- **Impacto/severidade:** execução de scripts de terceiros em nome do usuário logado; **alta**.
- **Confiança:** 95.
- **O que foi feito:** `htmlspecialchars($busca, ENT_QUOTES | ENT_HTML5, 'UTF-8')` nos dois
  pontos de saída. Rotas e parâmetros inalterados.

### F4 — Hash de senha com MD5 (algoritmo quebrado)
- **Onde:** `code/lib.php`, linha 15 (`$hash = md5($senha)`), corroborado por
  `code/seed.sql` (senhas como `MD5('...')`) e `code/schema.sql` (`CHAR(32)`).
- **Mecanismo:** autenticação por comparação de MD5 sem sal. GPUs/reverso em rainbow tables
  recuperam as senhas; qualquer colisão quebra o acesso.
- **Impacto/severidade:** comprometimento de contas; **alta**.
- **Confiança:** 95.
- **O que foi feito:** **não corrigido**. MD5 é o único formato armazenado no seed e o
  schema limita a coluna a `CHAR(32)` — migração para bcrypt/argon2 exigiria alterar o schema
  e repassar as senhas, quebrando o login dos usuários atuais. Mantive o mecanismo para
  preservar a compatibilidade e reporto a correção como recomendação de evolução planejada
  (coluna para hash moderno + rehash no próximo login).

### F5 — Credencial de banco e chave SMTP hardcoded em `config.php`
- **Onde:** `code/config.php`, linhas 12 (`DB_PASS`) e 15 (`SMTP_API_KEY`).
- **Mecanismo:** fallback com senha de produção (`N3tX@2013!prod`) e chave de API em texto
  plano no código-fonte, acessíveis a qualquer pessoa com leitura do repositório.
- **Impacto/severidade:** credenciais de produção exfiltradas; **alta**.
- **Confiança:** 98.
- **O que foi feito:** ambos os `define` agora leem exclusivamente de variáveis de ambiente
  (`DB_PASS`, `SMTP_API_KEY`); se não estiverem definidas, `RuntimeException` com mensagem
  explícita. `DB_HOST`/`DB_NAME`/`DB_USER` mantiveram fallback, por não serem segredos.

### F6 — Configuração de sessão insegura (fixação + cookies sem flags)
- **Onde:** `code/index.php`, linha 15 (`session_start()`) e bloco de login (linhas 20–36).
- **Mecanismo:** `session_start()` com valores padrão — cookie sem `httponly` (lível por JS),
  sem `samesite`, e sem `session_regenerate_id(true)` após o login, permitindo fixação de
  sessão: um ID de sessão pré-definido antes do login continua válido após a autenticação.
- **Impacto/severidade:** sequestro de sessão; **média**.
- **Confiança:** 85.
- **O que foi feito:** cookie com `secure` (dependente de HTTPS), `httponly`, `samesite=Lax`;
  regeneração do ID de sessão (`session_regenerate_id(true)`) logo após login bem-sucedido.

### F7 — `division by zero` em `mediaResposta` retorna `NAN`
- **Onde:** `code/lib.php`, linha 107 (retorno na linha 116), função `mediaResposta`.
- **Mecanismo:** se nenhum chamado tiver `minutos_resposta` não nulo, `$qtd` é 0 e a divisão
  gera o aviso `Division by zero` e devolve `NAN` (PHP 8), corrompendo o indicador de SLA no
  painel e qualquer consumidor externo do valor retornado.
- **Impacto/severidade:** indicador quebrado e erro em logs/consumidores; **média**.
- **Confiança:** 85.
- **O que foi feito:** retorno condicionado: `$qtd > 0 ? $soma / $qtd : 0.0`. Tipo `: float`
  preservado.

### F8 — Consultas sem prepared statement em `tecnicoNome` e `verChamado`
- **Onde:** `code/lib.php`, linha 69 (`tecnicoNome`) e linha 98 (`verChamado`).
- **Mecanismo:** interpolação crua de `$tecnicoId`/`$id` na query. No endpoint web elas estão
  contidas pelo cast `(int)` do chamador, mas `tecnicoNome` e `verChamado` têm assinatura
  pública (consumida por relatórios internos do ISP) e podem ser chamadas sem proteção.
- **Impacto/severidade:** vetor latente de injeção em chamadores externos; **média**.
- **Confiança:** 80.
- **O que foi feito:** ambas as funções passaram a usar instruções preparadas com
  `bind_param('i', ...)`. Contratos de retorno inalterados.

### F9 — `exportarCsv` escreve o CSV em disco e usa `SELECT *`
- **Onde:** `code/lib.php`, linhas 123–151, função `exportarCsv`.
- **Mecanismo:** o arquivo é gravado em `EXPORT_DIR` (`/var/www/painel/tmp`) e só então
  servido por `readfile()` — o dado fica exposto a outros usuários do sistema no disco e
  há risco de arquivo estalão/raça em exportações simultâneas; `SELECT *` traz mais colunas
  que o necessário.
- **Impacto/severidade:** exposição secundária do dado exportado e instabilidade; **média**.
- **Confiança:** 75.
- **O que foi feito:** exportação direta para a saída (`php://output`) — sem arquivo
  intermediário; consulta selecionando apenas as cinco colunas; cabeçalhos de download e
  ordem por `id` (contrato do manifesto) mantidos.

### F10 — Falta de proteção CSRF no formulário de login
- **Onde:** `code/index.php`, bloco de login (linhas 20–36).
- **Mecanismo:** formulário POST sem token CSRF; embora o sistema seja essencialmente
  read-only, qualquer ator able de fazer o cliente logado acessar uma página maliciosa pode
  provocar uma autenticção forjada contra `index.php`.
- **Impacto/severidade:** autenticação sob controle de terceiro; **média**.
- **Confiança:** 78.
- **O que foi feito:** token CSRF armazenado na sessão e verificado contra o campo oculto
  `csrf_token` no POST; token regenerado em hexa randomico após login bem-sucedido. Campos
  `login`/`senha` e método POST inalterados.

---

## 3. Decisões — o que não foi alterado e por quê

1. **Algoritmo de hash MD5 em `autenticar` (F4)** — não alterado. Mudar para bcrypt/argon2
   exigiria alterar `schema.sql` (`CHAR(32)`) e repassar as senhas, o que quebraria a
   autenticação dos usuários atuais e do seed fornecido. Mantida compatibilidade; a correção
   é reportada como recomendação de evolução (nova coluna + rehash no próximo login).
2. **`formatarStatus` com `else` genérico** — mantido. O manifesto fixa exatamente os três
   rótulos; o `else → 'Resolvido'` é o fallback implícito do contrato e o relatório
   gerencial faz correspondência por texto. Alterar para lançar exceção ou retornar valor
   alternativo quebraria esse consumidor.
3. **`rotuloPrioridade`** — mantido integralmente. É lógica de negócio; o manifesto não
   especifica os rótulos e mudar o comportamento alteraria o relatório gerencial.
4. **Mensagem de erro de conexão (`die('Falha ao conectar ao banco.')`)** — mantida genérica.
   Ela já não divulga informação do sistema; mudar não traria benefício de segurança e
   poderia alterar comportamento esperado por scripts de monitoramento.
5. **Ordem da listagem (`ORDER BY criado_em DESC`)** — mantida. O manifesto exige ordem por
   `id` crescente **apenas no CSV**; a listagem web não tem restrição de ordem declarada.
6. **Estrutura HTML minimalista (sem `</body></html>`)** — não ampliada. O manifesto só
   exige a tabela com `id="tabela-chamados"` e a ordem das colunas; adicionar tags de
   fechamento é melhoria cosmética de baixo custo-benefício em um sistema que não a exige.
7. **Definições `DB_HOST`, `DB_NAME`, `DB_USER` com fallback** — mantidas. São identificadores
   de infraestrutura, não segredos; o fallback preserva a implantação sem configuração de
   ambiente e não viola nenhuma regra de segurança.
8. **Definição `EXPORT_DIR`** — mantida. Embora não seja mais usada internamente, scripts
   externos do ISP podem lê-la; remover mudaria uma variável pública.

---

## 4. Verificação de compatibilidade com `manifest.md`

| Contrato | Estado |
| --- | --- |
| `autenticar(mysqli, string, string): ?array` | ✅ inalterada; SQL já era preparado, MD5 reportado (F4) |
| `formatarStatus(int): string` | ✅ 1→"Aberto", 2→"Em atendimento", 3→"Resolvido" |
| `rotuloPrioridade(int, ?int): string` | ✅ inalterada |
| `listarChamados(mysqli, string=''): array` | ✅ inalterada; devolve `tecnico_nome` |
| `verChamado(mysqli, int): ?array` | ✅ inalterada; devolve `null` quando cliente não é dono |
| `mediaResposta(mysqli): float` | ✅ inalterada; `: float` preservado |
| `exportarCsv(mysqli): void` | ✅ inalterada; ainda escreve CSV na saída com headers |
| Rotas `?busca`, `?ver`, `?export=csv` | ✅ todas preservadas |
| CSV: cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` | ✅ idêntico |
| CSV: ordenado por `id` crescente | ✅ `ORDER BY id` |
| CSV: coluna `Status` via `formatarStatus` | ✅ mantida |
| HTML: `id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico | ✅ mantidas |
| Regra de visibilidade | ✅ **implementada** (antes violada — F2) |

Nenhuma assinatura, rota, parâmetro GET, nome de parâmetro, formato de saída ou estrutura
HTML declarada foi alterada.
