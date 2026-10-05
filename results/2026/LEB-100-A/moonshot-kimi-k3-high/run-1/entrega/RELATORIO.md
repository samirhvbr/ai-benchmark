# RELATORIO.md — LEB-100-A — Painel de Chamados (NetX ISP)

## 1. Resumo

O sistema é um painel PHP/mysqli pequeno e coeso, mas estava em produção **sem nenhum
controle de acesso por papel**: qualquer usuário autenticado (inclusive cliente) via todos
os chamados, abria o detalhe de qualquer chamado pelo id e baixava o CSV completo —
violando a regra de negócio declarada no manifesto. Havia ainda **injeção de SQL** na
busca, **XSS refletido** no termo pesquisado, **segredos de produção embutidos no
código-fonte**, senhas com **MD5**, uma **divisão por zero** no indicador de SLA e um
**N+1** na listagem/exportação. Corrigi tudo que era possível sem quebrar o contrato do
`manifest.md`; o hash MD5 foi reportado mas não migrado (ver Decisões).

Todas as assinaturas públicas, rotas, rótulos, formato do CSV e a estrutura HTML foram
preservados. `php -l` passa nos três arquivos alterados.

---

## 2. Achados (ordem de prioridade)

### F1 — Injeção de SQL na busca de chamados — `code/lib.php:82` — SEGURANÇA — crítica — confiança 100

**Mecanismo:** `listarChamados` concatenava o parâmetro `$busca` diretamente no SQL:
`" WHERE titulo LIKE '%" . $busca . "%'"`. Como `$busca` vem de `$_GET['busca']`
(index.php:72), um atacante autenticado podia fechar a string com `'` e injetar SQL
arbitrário — ex.: `%' UNION SELECT id,login,senha,nome,... FROM usuarios -- ` para
despejar logins e hashes de senha na própria tabela de chamados renderizada.

**Impacto:** leitura (e, dependendo do driver, modificação) de qualquer dado do banco por
qualquer usuário com sessão válida, incluindo os hashes MD5 de todas as senhas.

**Correção:** a busca agora usa `prepare()`/`bind_param()` com `LIKE ?` e o termo montado
em PHP (`'%' . $busca . '%'`). Assinatura e comportamento de filtro preservados.
**Corrigido: sim.**

### F2 — Cliente via chamados de todos na listagem — `code/index.php:73` — SEGURANÇA — crítica — confiança 100

**Mecanismo:** a listagem chamava `listarChamados($db, $busca)` e renderizava o resultado
sem qualquer filtro por `usuario_id`. O manifesto (Regra de negócio) determina que cliente
só vê os chamados que ele mesmo abriu; o código nunca implementou isso — `$papel` era lido
da sessão mas nunca usado na listagem.

**Impacto:** qualquer cliente autenticado via título, status, prioridade e técnico de todos
os chamados de todos os clientes — violação de confidencialidade e da regra de negócio.

**Correção:** após `listarChamados`, quando `$papel === 'cliente'` o array é filtrado para
`(int) $c['usuario_id'] === $uid`. Técnico continua vendo tudo. A assinatura pública de
`listarChamados` não foi alterada (o filtro fica no ponto de entrada, que é quem conhece a
sessão). **Corrigido: sim.**

### F3 — IDOR no detalhe do chamado — `code/index.php:53` — SEGURANÇA — alta — confiança 100

**Mecanismo:** a rota `index.php?ver=<id>` chamava `verChamado` e exibia título, descrição,
status e prioridade sem conferir se o chamado pertencia ao usuário logado. Bastava um
cliente iterar `?ver=1,2,3...` para ler a descrição completa de chamados alheios.

**Impacto:** leitura integral (inclusive a descrição, que não aparece na listagem) de
chamados de terceiros por qualquer cliente autenticado.

**Correção:** cliente que não é dono recebe a mesma mensagem "Chamado nao encontrado."
(sem vazar a existência do id). Técnico inalterado. **Corrigido: sim.**

### F4 — Exportação CSV sem restrição de papel — `code/index.php:44-47` — SEGURANÇA — alta — confiança 95

**Mecanismo:** qualquer sessão autenticada que acessasse `index.php?export=csv` recebia o
CSV com **todos** os chamados — a rota só checava login, não papel. Mesmo com F2/F3
corrigidos, esta rota contornaria a regra de visibilidade.

**Impacto:** cliente baixava a base completa de chamados em um clique.

**Correção:** a rota web responde 403 para quem não é `tecnico`. A função pública
`exportarCsv(mysqli $db)` (usada pela rotina noturna) e o formato do CSV não mudaram; a
rota e o parâmetro `export=csv` continuam existindo. **Corrigido: sim.**

### F5 — XSS refletido via termo de busca — `code/index.php:79,82` — SEGURANÇA — alta — confiança 100

**Mecanismo:** `$busca` era impresso sem escape em dois pontos: no atributo `value` do
input (`value="' . $busca . '"` — quebra de atributo com `"` permite injetar handlers como
`onfocus`) e no parágrafo "Resultados para: ..." (injeção direta de markup). Ex.:
`index.php?busca="><script>...</script>` executava JavaScript na sessão da vítima.

**Impacto:** roubo de sessão/ações em nome do usuário que clicar num link montado —
inclusive técnicos, escalando o estrago.

**Correção:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` nos dois pontos. **Corrigido: sim.**

### F6 — Segredos de produção embutidos no código — `code/config.php:12,15` — SEGURANÇA — alta — confiança 95

**Mecanismo:** a senha real do banco (`N3tX@2013!prod`) era o fallback de
`getenv('DB_PASS')`, e a chave da API de SMTP (`netx-smtp-...`) era uma constante literal.
Qualquer pessoa com acesso ao repositório, a um backup ou a um dump de deploy obtinha
credenciais válidas de produção.

**Impacto:** comprometimento direto do banco e do serviço de e-mail transacional.

**Correção:** ambos passam a vir exclusivamente de variáveis de ambiente; sem a variável, o
sistema falha explicitamente com mensagem clara em vez de usar um segredo versionado.
**Ação operacional obrigatória:** trocar a senha do banco e revogar a chave SMTP expostas,
pois já vazaram no histórico. **Corrigido: sim** (o código não contém mais os segredos;
a rotação é tarefa de operação).

### F7 — Senhas armazenadas com MD5 puro — `code/lib.php:15` — SEGURANÇA — alta — confiança 100 — **NÃO corrigido**

**Mecanismo:** `autenticar` compara `md5($senha)` com a coluna `senha CHAR(32)`. MD5 é
rápido e sem sal: vazando a tabela (cenário nada hipotético, ver F1), as senhas caem por
força bruta/rainbow table em minutos — e usuários reutilizam senhas.

**Impacto:** comprometimento em massa de contas após qualquer vazamento do banco.

**Por que não corrigi:** migrar exige (a) alterar o schema (`senha CHAR(32)` não comporta
`password_hash`), (b) regravar hashes no login ou em lote, e (c) `seed.sql` usa `MD5(...)`
— os dados de teste do manifesto (`ana`/`senha123` etc.) precisam continuar funcionando.
Isso é uma migração de dados com janela de coexistência, fora do escopo de uma correção
in-place sem quebrar consumidores. Recomendação registrada: ampliar a coluna para
`VARCHAR(255)`, migrar para `password_hash`/`password_verify` com fallback de verificação
MD5 + rehash transparente no primeiro login, e atualizar o seed. **Corrigido: não.**

### F8 — Divisão por zero em `mediaResposta` — `code/lib.php:116` — BUG — média — confiança 85

**Mecanismo:** a função soma `minutos_resposta` e devolve `$soma / $qtd` sem testar
`$qtd`. Num banco novo, ou quando nenhum chamado tem `minutos_resposta` preenchido (o seed
tem 2 de 5 nulos; uma base só com chamados abertos é plausível), `$qtd` é 0 e o PHP 8
lança `DivisionByZeroError` — a página de listagem inteira quebra com erro 500, pois
`$media` é calculada antes do HTML.

**Impacto:** indisponibilidade da listagem exatamente nos cenários de base vazia/nova.

**Correção:** retorna `0.0` quando não há chamados respondidos (tipo `float` preservado;
o painel exibe "0 min"). **Corrigido: sim.**

### F9 — N+1 consultas na listagem e no CSV — `code/lib.php:88-91` — PERFORMANCE — média — confiança 100

**Mecanismo:** para cada chamado retornado, o laço chamava `tecnicoNome`, que fazia um
`SELECT nome FROM usuarios WHERE id = ...` separado — 1 + N consultas por listagem e por
exportação. Com milhares de chamados, cada páginação e cada CSV custam milhares de round-trips.

**Impacto:** latência e carga de banco crescendo linearmente com o volume de chamados.

**Correção:** `listarChamados` e `exportarCsv` agora fazem um único
`LEFT JOIN usuarios u ON u.id = c.tecnico_id` trazendo `u.nome AS tecnico_nome`
(`'-'` quando nulo, como antes). A função `tecnicoNome` foi mantida intacta (pode ser
usada por outros scripts do ISP; não consta no manifesto, então não a removi). O conteúdo
das colunas e a ordenação são idênticos. **Corrigido: sim.**

### F10 — Sessão sem regeneração de id no login — `code/index.php:23-27` — SEGURANÇA — média — confiança 80

**Mecanismo:** após autenticar, o código gravava `$_SESSION['uid']` sem
`session_regenerate_id()`. Se um atacante induzir a vítima a usar um id de sessão
conhecido (cookie plantado, id vazado em log/URL), o id continua válido após o login:
fixação de sessão clássica.

**Impacto:** sequestro da sessão autenticada da vítima.

**Correção:** `session_regenerate_id(true)` logo após o login bem-sucedido, antes de
popular a sessão. **Corrigido: sim.**

---

## 3. Decisões — o que NÃO mudei e por quê

1. **MD5 nas senhas (F7).** Reportado, não migrado: exige mudança de schema, migração de
   dados e manteria o seed funcionando; é projeto, não correção in-place (detalhes em F7).
2. **`verChamado` concatena `$id` no SQL (`code/lib.php:100`).** Não é explorável: o
   parâmetro é tipado `int $id`, então nenhuma string maliciosa chega à query. Deixei
   como está para manter a alteração mínima; se um dia o tipo for afrouxado, vira F1.
3. **`tecnicoNome` mantida e concatenando id.** Não consta no manifesto mas pode ser
   chamada por scripts externos; o parâmetro é `?int` (não explorável). Só deixou de ser
   chamada em loop (F9).
4. **CSV continua gravando em `EXPORT_DIR/chamados.csv` antes do `readfile`.** A rotina
   noturna do ISP pode depender do arquivo em disco; trocar por saída direta em
   `php://output` seria ganho marginal com risco de quebrar consumidor. Apenas fechei o
   ponteiro no caminho de erro (antes vazava o handle e deixava arquivo truncado).
5. **`formatarStatus` mantém o `else` final → "Resolvido".** O contrato só fixa 1/2/3;
   endurecer para valores inesperados poderia mudar o texto exibido ao relatório gerencial
   em bases com dados sujos. Sem evidência de bug real, não toquei.
6. **`rotuloPrioridade` mantida com os `if`s aninhados.** A lógica é legível o bastante e
   qualquer "simplificação" arrisca alterar rótulos consumidos externamente.
7. **Média de SLA global (`mediaResposta`) sem filtro por papel.** O manifesto a descreve
   como "indicador de SLA no topo do painel", sem segmentação por usuário; filtrá-la por
   cliente mudaria o número exibido e não há regra declarada sobre isso.
8. **Restrição do export a técnicos (F4):** é a única mudança comportamental de rota. Foi
   feita porque mantê-la aberta a clientes violaria a regra de visibilidade, que o
   manifesto declara obrigatória; a rota, o parâmetro e o formato do CSV permanecem.
9. **Sem CSRF token no formulário de login.** Login sem token tem risco baixo (login CSRF)
   e adicionar token mudaria o HTML do formulário; registrado como melhoria futura, fora
   do escopo mínimo.
10. **Stack mysqli e estrutura de arquivos intactas**, conforme as restrições da tarefa.
