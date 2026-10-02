# RELATORIO.md — Painel de Chamados NetX ISP (LEB-100-A)

## Resumo

Sistema PHP/mysqli pequeno e legível, com superfície pública contratada em
`manifest.md`. O estado entregue tem **duas falhas críticas** validadas em
execução: injeção de SQL no filtro de busca (permite, inclusive, extrair os
hashes de senha da tabela `usuarios`) e quebra total da regra de visibilidade —
qualquer **cliente** autenticado lista, abre e exporta chamados de todos os
demais. Somam-se XSS refletido, segredos de produção gravados no fonte,
`md5` como esquema de senha, um crash fatal (divisão por zero) quando não há
respostas medidas, um N+1 na listagem e fragilidades de sessão e de exportação.

Todas as correções foram feitas **in-place**, preservando assinaturas, rotas,
formatos e a regra de negócio. A compatibilidade foi verificada por
caracterização antes/depois: para uma **técnica**, listagem, detalhe, busca e
CSV do código corrigido são **byte-a-byte idênticos** ao original
(ver seção Verificação).

O ambiente de teste usado: PHP 8.4.26 + MariaDB 11.8 com `schema.sql`/`seed.sql`,
requisições HTTP reais e injeções executadas contra o código original.

---

## Achados (na ordem em que priorizei a correção)

### F1 — Injeção de SQL no parâmetro `busca` (`code/lib.php:82`) — CRÍTICA

**O que é.** `listarChamados()` monta a query por concatenação:
`" WHERE titulo LIKE '%" . $busca . "%'"`, executada com `$db->query()`.

**Mecanismo.** O valor de `$_GET['busca']` entra direto na string SQL. Um
aspas simples fecha o literal do `LIKE` e o restante do payload vira SQL.
Validado em execução contra o código original:

- `busca=zzz' OR 1=1 -- ` → retorna todos os chamados (LIKE vira
  `'%zzz' OR 1=1 -- %'`);
- `busca=%' UNION SELECT 1,2,3,senha,5,6,7,8,9 FROM usuarios -- ` → a tabela
  de listagem passa a exibir **os hashes MD5 de todas as senhas** como se
  fossem títulos (a coluna `titulo` recebeu `usuarios.senha` via UNION,
  casa de 9 colunas com `SELECT *`).

**Impacto.** Leitura arbitrária do banco (exfiltração de hashes de senha —
com MD5, quebra quase imediata por rainbow table), bypass de qualquer filtro
e base para enumeration de usuários.

**Severidade:** crítica · **Confiança:** 98 (demonstrada em execução).

**Ação.** `listarChamados()` agora usa prepared statement:
`WHERE c.titulo LIKE CONCAT('%', ?, '%')` com `bind_param('s', $busca)`.
Re-testado: os dois payloads acima retornam zero linhas; busca legítima
(`busca=Lentidao`) continua retornando o chamado 102.

---

### F2 — Regra de visibilidade ignorada: cliente vê chamados de todo mundo (`code/index.php:38`, rotas em 44/52/73) — CRÍTICA

**O que é.** O manifesto declara: *cliente só vê os próprios chamados;
técnico vê qualquer um*. O código carrega `$uid`/`$papel` (index.php:38-39)
e **nunca os usa**. `listarChamados` devolve tudo, `verChamado` não checa
dono, e `?export=csv` entrega o CSV completo a quem estiver logado.

**Mecanismo.** Não há nenhum ponto do fluxo web onde `usuario_id` do chamado
é comparado com o `uid` da sessão. Validado em execução: logado como `ana`
(cliente), a listagem exibe os 5 chamados, incluindo 103/104 (abertos por
`bruno`); `?ver=103` abre o detalhe; `?export=csv` exporta tudo.

**Impacto.** Vazamento sistemático de dados entre clientes de um ISP —
títulos, descrições, prioridades e histórico de chamados alheios, sem
qualquer sofisticação do atacante: basta logar com uma conta de cliente.

**Severidade:** crítica · **Confiança:** 99 (regra explícita no manifesto +
demonstração).

**Ação.** Três correções em `index.php`, sem tocar nas assinaturas públicas
(a assinatura de `listarChamados` não tem parâmetro de dono, então o filtro
é aplicado pelo chamador):

- listagem: `array_filter` por `usuario_id === $uid` quando o papel é
  `cliente`;
- detalhe: chamado existente de outro dono responde **"Chamado nao
  encontrado."** — mesma resposta de id inexistente, para não vazar nem a
  existência do registro;
- exportação: cliente recebe CSV restrito aos próprios chamados, via nova
  função `exportarCsvFiltrado($db, $uid)`; técnico continua exportando tudo
  por `exportarCsv($db)` (assinatura intacta para a rotina noturna).

Re-testado: `ana` vê apenas 101/102/105, `?ver=103`/`?ver=104` → "não
encontrado", `?ver=101` abre normal, CSV da `ana` tem 3 linhas + cabeçalho;
`carla` (técnica) continua vendo e exportando os 5.

---

### F3 — XSS refletido no campo de busca (`code/index.php:79` e `:82`) — ALTA

**O que é.** `$busca` é ecoada sem escape no atributo `value` do input e no
texto "Resultados para: ...".

**Mecanismo.** `busca="><script>alert(1)</script>` fecha o atributo `value`
com `">` e injeta marcação executável — validado: o `<script>` vai cru para o
HTML em dois pontos (linha 79 e linha 82). Qualquer link forjado
`index.php?busca=...` executando na sessão logada da vítima é phishing e
roubo de sessão via JS.

**Impacto.** Execução de script no contexto da aplicação para qualquer
usuário que clique num link malicioso (cookies de sessão legíveis por JS).

**Severidade:** alta · **Confiança:** 97 (payload executado na
caracterização).

**Ação.** Uma única variável `$buscaHtml = htmlspecialchars($busca,
ENT_QUOTES)` usada nos dois pontos de eco. O atributo de valor passa a
conter o payload escapado (`&quot;&gt;&lt;script...`) — verificado.

---

### F4 — Segredos de produção gravados no fonte (`code/config.php:12` e `:15`) — ALTA

**O que é.** `DB_PASS` tem fallback embutido com a senha real do banco de
produção (`N3tX@2013!prod`) e `SMTP_API_KEY` é uma literal fixa
(`netx-smtp-9f83e2c1a7b64d05`).

**Mecanismo.** O código-fonte circula em versionamento/backup/pacotes de
deploy; qualquer pessoa com acesso ao repositório (ou ao pacote desta
própria tarefa) obtém a senha do banco de produção e a chave de e-mail
transacional. O próprio comentário "senha do usuário de produção" confirma
que não é um valor de teste. Não há consumo interno da chave SMTP no código
— ou seja, a chave só serve para quem a roubar.

**Impacto.** Acesso direto ao banco de produção e envio de e-mails em nome
do ISP (phishing), sem qualquer exploração de código.

**Severidade:** alta · **Confiança:** 100 (as strings estão lá).

**Ação.** Ambos agora vêm exclusivamente de ambiente:
`getenv('DB_PASS') ?: ''` e `getenv('SMTP_API_KEY') ?: ''`. As constantes
existem com os mesmos nomes (código que as referencia continua funcionando);
a diferença é que, sem variável de ambiente, a conexão falha **de forma
audível** em vez de autenticar silenciosamente com a senha vazada.
Implantação passa a exigir `DB_PASS` (e `SMTP_API_KEY`, quando o envio de
e-mail for usado) no ambiente — ação operacional necessária, registrada
aqui. Validado: o app sobe normalmente com `DB_PASS` no ambiente.
Rotação das credenciais vazadas fica recomendada como passo de ops (não é
alterável por código).

---

### F5 — Senhas com MD5, sem salt (`code/lib.php:15`; coluna em `schema.sql:7`) — ALTA

**O que é.** `autenticar()` faz `md5($senha)` e compara com a coluna
`CHAR(32)`. O esquema está materializado no schema (`senha CHAR(32)`, semente
com `MD5('senha123')`).

**Mecanismo.** MD5 é rápido e sem salt: hashes "quebráveis" por rainbow
table em segundos. F1 mostra que os hashes são exfiltráveis (via SQLi —
corrigida) e o vazamento de fonte (F4) entrega o resto. Combinados, os três
achados formam uma cadeia completa de comprometimento de contas.

**Impacto.** Qualquer posse da tabela `usuarios` resulta em senhas
recuperadas; a senha do banco de produção caiu junto com F4.

**Severidade:** alta · **Confiança:** 95.

**Ação — NÃO corrigida, por decisão.** Trocar para `password_hash()` exige
(i) alterar a coluna `senha` (CHAR(32) → CHAR(60)+), (ii) migrar/re-semebrar
todos os usuários e (iii) coordenar com os consumidores externos que tocariam
o novo formato — a coluna faz parte do contrato de dados legado que o
manifesto manda preservar (o schema é entregue como contexto do sistema em
produção). Fazer isso unilateralmente aqui quebraria a verificação de login
documentada no manifesto (`ana`/`senha123`) contra qualquer consumidor que
compare o hash. O caminho correto é uma migração versionada, com dupla
leitura (MD5 legado + bcrypt novo, re-hash no primeiro login com sucesso) em
janela coordenada. Registrei o risco; a correção fica para essa janela.

---

### F6 — Crash fatal quando não há respostas medidas (`code/lib.php:116`) — MÉDIA

**O que é.** `mediaResposta()` devolve `$soma / $qtd` sem checar `$qtd`.

**Mecanismo.** Se nenhum chamado tem `minutos_resposta` preenchido (base
nova, ou todos os chamados aguardando 1ª resposta — situação plausível de
operação), `$qtd = 0` e em PHP 8 a divisão lança `DivisionByZeroError`.
Como a chamada roda no topo da listagem, **o painel inteiro cai com 500**
para todos os usuários. Validado: com `minutos_resposta = NULL` em todos os
chamados, o original responde 500; o corrigido responde 200 com "0 min".

**Impacto.** Indisponibilidade total da tela principal por um estado de
dados perfeitamente legítimo.

**Severidade:** média · **Confiança:** 90.

**Ação.** Guarda `if ($qtd === 0) return 0.0;` (+ guarda de erro de query).
Assinatura e retorno `float` preservados.

---

### F7 — N+1 na listagem e no export (`code/lib.php:69`, chamado em `:89` e `:137`) — MÉDIA

**O que é.** `listarChamados()`/`exportarCsv()` chamam `tecnicoNome()` —
uma query extra — para **cada** chamado retornado.

**Mecanismo.** Uma página com N chamados executa N+1 queries ao banco; o
crescimento é linear no volume de chamados (ISP real: milhares), saturando o
banco e a latência da listagem, rota mais usada do sistema.

**Impacto.** Degradação progressiva de performance do painel e carga
desnecessária no banco compartilhado.

**Severidade:** média (performance) · **Confiança:** 95.

**Ação.** As duas consultas agora resolvem o nome via
`LEFT JOIN usuarios t ON t.id = c.tecnico_id` com
`COALESCE(t.nome, '-') AS tecnico_nome` — mesmo valor e mesma chave
`tecnico_nome` que `tecnicoNome()` produzia. `tecnicoNome()` foi mantida
(prepared statement), pois é API interna usada por relatórios do ISP, mesmo
sem constar no manifesto. Saída byte-a-byte idêntica verificada.

---

### F8 — Exportação via arquivo de caminho fixo: corrida, falha silenciosa e (pós-F2) vazamento (`code/lib.php:125-150`) — MÉDIA

**O que é.** `exportarCsv()` grava o CSV inteiro em
`EXPORT_DIR/chamados.csv` (caminho fixo, compartilhado) e depois faz
`readfile`.

**Mecanismo.** Três problemas concretos: (i) **corrida** — duas exportações
simultâneas escrevem no mesmo arquivo; quem ler no meio recebe um CSV
**truncado ou misturado**; (ii) **falha silenciosa** — se o diretório não
existe, `fopen` falha e a função retorna sem output nem erro: validado, a
rota `?export=csv` do original devolve **200 vazio**; (iii) pós-correção de
F2, o arquivo único passaria a guardar o CSV *filtrado do último usuário* e
servi-lo ao próximo — vazamento entre contas, exatamente o que F2 elimina.

**Impacto.** Exportação quebrada (comportamento atual) e intermitente;
risco de servir dados de um usuário para outro.

**Severidade:** média · **Confiança:** 85.

**Ação.** O CSV agora é transmitido direto para a saída (`fopen
'php://output'` + `fputcsv`), sem arquivo intermediário. Mesmos headers
(`Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment;
filename="chamados.csv"`), mesmas linhas, mesma ordem (`ORDER BY id ASC`).
A constante `EXPORT_DIR` permanece definida em `config.php` por
compatibilidade. Validado: rota devolve o CSV completo (a do original,
vazio).

---

### F9 — Sessão sem endurecimento: fixação e cookie sem flags (`code/index.php:15-28`) — MÉDIA

**O que é.** Login não chama `session_regenerate_id()` (fixação de sessão) e
o cookie de sessão sai sem `HttpOnly`/`SameSite`.

**Mecanismo.** Sem regeneração, um id de sessão fixado (link forjado, subdomínio
compartilhado, header injetado) sobrevive ao login: o atacante que fixou o id
antes continua válido depois da autenticação da vítima. Sem `HttpOnly`,
cookies legíveis por JS (combina com F3); sem `SameSite`, requisições
cross-site levam a sessão.

**Impacto.** Sequestro de sessão via fixação; ataque se potencializa junto
com F3.

**Severidade:** média · **Confiança:** 80.

**Ação.** `session_regenerate_id(true)` no login bem-sucedido (antes de
gravar `uid`) e `session_set_cookie_params(['httponly' => true,
'samesite' => 'Lax'])` antes de `session_start()`. Fluxo de login
re-validado (302 + listagem). Não ativei `secure` deliberadamente — ver
Decisões.

---

### F10 — Interpolação de id em SQL em `tecnicoNome`/`verChamado` (`code/lib.php:69` e `:100`) — BAIXA

**O que é.** `'... WHERE id = ' . $tecnicoId` e `'... WHERE id = ' . $id`.

**Mecanismo.** Hoje não é explorável: as assinaturas tipam `?int`/`int` e os
chamadores fazem `(int)` antes. Mas é a mesma família de F1 num ponto em que
um refactor descuidado (trocar tipo da assinatura, remover cast) reabre a
injeção — e o valor chega concatenado, não parametrizado.

**Impacto.** Nenhum hoje; risco latente de regressão.

**Severidade:** baixa (qualidade) · **Confiança:** 70 de que o problema
(real, mas mitigado por tipo) mereça prioridade.

**Ação.** Ambas convertidas para prepared statement (`bind_param('i', ...)`),
comportamento idêntico verificado.

---

### F11 — Entradas superglobais sem normalização derrubam o PHP (`code/index.php:22`; idem `:72`) — BAIXA

**O que é.** `autenticar($db, $_POST['login'], ...)` e
`$busca = $_GET['busca'] ?? ''` assumem string.

**Mecanismo.** `?busca[]=x` entrega um **array**: a tipagem `string` de
`listarChamados()` lança `TypeError` → 500 (idem `login[]` para
`autenticar`). Não é vulnerabilidade de dados, é queda de serviço barata de
provocar.

**Impacto.** Erro 500 reproduzível por qualquer visitante (antes do login,
no caso do POST).

**Severidade:** baixa · **Confiança:** 75.

**Ação.** Casts `(string)` nos dois pontos. Rotas legítimas insensíveis
(entradas normais continuam idênticas — dif byte-a-byte nulo).

---

### F12 — Injeção de fórmula no CSV (`code/lib.php:138`) — BAIXA

**O que é.** `fputcsv` escreve `titulo`/`nome` sem neutralizar conteúdo que o
Excel/Sheets interpreta como fórmula (`=`, `+`, `-`, `@` iniciais).

**Mecanismo.** Um chamado criado (por API/cliente malicioso) com título
`=WEBSERVICE(...)` vira fórmula ao ser aberto pelo atendente que exportar o
CSV — exfiltração por planilha.

**Impacto.** Execução limitada no Excel do consumidor do relatório.

**Severidade:** baixa · **Confiança:** 55.

**Ação — NÃO corrigida, por decisão.** Prefixar/escapar as células mudaria
os bytes do CSV, cujo formato é contrato explícito do manifesto (cabeçalho
exato e rótulos textuais casados por outro consumidor). A mitigação correta
é no consumidor (abrir como texto/importar com fórmulas desabilitadas) ou
por política de criação de chamado. Registrada como dívida.

---

### F13 — Form de login sem proteção CSRF (`code/index.php:32`) — BAIXA

**O que é.** O POST de login não carrega token anti-CSRF.

**Mecanismo.** Um site de terceiro pode submeter credenciais em nome do
usuário (login CSRF — "session riding" para logar a vítima numa conta do
atacante). Impacto limitado aqui: não há outras ações POST no sistema.

**Severidade:** baixa · **Confiança:** 60.

**Ação — NÃO corrigida, por decisão.** Não há evidência no manifesto de
fluxos POST externos, mas a rotina de login é justamente o ponto em que
integrações automatizadas costumam tocar num legado desses; exigir token
quebraria qualquer POST existente sem como testá-los daqui. Junto com F9 (o
que foi corrigido) o risco residual é pequeno.

---

## Decisões — o que NÃO mudei, e por quê

| O que | Por quê |
| --- | --- |
| **MD5 como hash de senha (F5)** | Exige migração de coluna + dados + coordenação com consumidores que leem `usuarios.senha`. Fora do escopo de uma evolução compatível; caminho documentado no achado. |
| **CSV: escape de fórmula (F12)** | Mudaria os bytes do arquivo; o formato do CSV é contrato explícito. Mitigação cabe ao consumidor. |
| **CSRF no login (F13)** | Risco de quebrar POSTs automatizados de integrações não documentadas; benefício baixo frente ao custo de compatibilidade. |
| **Stack mysqli / PDO** | Proibido pelo manifesto (as assinaturas públicas recebem `mysqli`). |
| **Cookie de sessão sem `secure`** | Ativar quebraria instalações HTTP/localhost documentadas no fluxo de teste; com `httponly` + `samesite=Lax` já cobrimos os vetores realistas. Flag a ativar quando houver TLS. |
| **Mensagem `die('Falha ao conectar ao banco.')`** | Genérica, não vaza detalhes; o comportamento de falha não é contrato, mas não há ganho em mudá-la. |
| **Indicador de média global** | O manifesto não restringe o "Tempo medio de 1a resposta" por dono; filtrá-lo para clientes mudaria comportamento além da regra de visibilidade. Mantive global. |
| **`SELECT *` nas consultas** | Consumidores dependem das chaves completes do registro (inclusive a nova `tecnico_nome` mantida por cima); restringir colunas é risco de contrato sem ganho real. |
| **Constante `EXPORT_DIR` em `config.php`** | Mantida (vazia de uso pelo app) para não quebrar quem a referencia; o export não depende mais dela. |
| **Estrutura/ordem das colunas da tabela e HTML da listagem** | Contrato do manifesto (`id="tabela-chamados"`, colunas ID/Titulo/Status/Prioridade/Tecnico) — intacto, verificado. |
| **Renomear/mover arquivos, adicionar dependências** | Proibidos pelas regras da tarefa. |

---

## Verificação (como validei)

Ambiente: PHP 8.4.26, MariaDB 11.8, `schema.sql` + `seed.sql` carregados,
app servido por servidor web embutido com acesso real ao banco.

1. **Caracterização do original** (antes de alterar): `ana` lista e abre
   chamados 103/104 do `bruno`; payloads de SQLi (OR 1=1, UNION com dump de
   `usuarios.senha`) executam com sucesso; XSS refletido renderiza
   `<script>`; `?export=csv` devolve 200 vazio (diretório inexistente);
   base sem `minutos_resposta` derruba a listagem com 500.
2. **Regressão da superfície**: para `carla` (técnica), listagem, `?ver=101`
   e `?busca=Lentidao` são **byte-a-byte idênticos** entre original e
   corrigido (`diff` vazio). CSV com cabeçalho exato, 5 linhas, ordem por
   `id`, rótulos de `formatarStatus`, técnico `-` quando NULL.
3. **Correções**: `ana` vê apenas 101/102/105 (listagem, detalhe e CSV);
   `?ver=103`/`104` → "Chamado nao encontrado"; payloads SQLi retornam 0
   linhas; XSS escapa (`&quot;&gt;&lt;script`); base sem respostas → 200 com
   "0 min"; login/senha inválida reexibe o formulário; `exportarCsv($db)`
   chamada direta (modo "rotina noturna") imprime o CSV completo no stdout.
4. `php -l` sem erros nos três arquivos alterados.
