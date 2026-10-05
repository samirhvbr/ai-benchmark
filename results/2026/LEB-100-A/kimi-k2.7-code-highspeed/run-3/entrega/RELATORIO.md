# Relatório Técnico — LEB-100-A

## Resumo

O sistema é um painel de chamados em PHP/mysqli de pequeno porte (`index.php` + `lib.php`).
O código funciona, mas carrega vulnerabilidades clássicas de sistemas legados: injeção de SQL em
múltiplos pontos, XSS refletido, quebra da regra de visibilidade cliente/técnico e divisão por
zero. As correções foram feitas **sem reescrever o sistema** e **sem quebrar as assinaturas
públicas** descritas em `manifest.md`. A compatibilidade com scripts externos que chamam
`listarChamados($db, $busca)` e `verChamado($db, $id)` foi preservada por meio de parâmetros
opcionais.

Testes locais (MariaDB 11.8 + PHP 8.4) validaram: autenticação, visibilidade, proteção contra
SQL injection, escape de XSS, geração de CSV e chamadas legadas sem os novos parâmetros.

---

## Achados e correções

### F1 — SQL injection em `listarChamados`

- **Onde:** `code/lib.php`, linha 82.
- **Mecanismo:** a string de busca do usuário é concatenada diretamente no SQL:
  `WHERE titulo LIKE '%" . $busca . "%'`. Um atacante pode injetar `' OR 1=1 --` (ou
  `UNION`, `; DELETE ...`, etc.) e alterar o resultado da consulta ou executar comandos no
  banco, dependendo dos privilégios do usuário `painel`.
- **Impacto:** vazamento de todos os chamados, potencial destruição/alteração de dados.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** a função foi reescrita para usar prepared statements. O termo de busca é
  passado como parâmetro `LIKE ?` com o valor `'%' . $busca . '%'`.

### F2 — SQL injection em `verChamado`

- **Onde:** `code/lib.php`, linha 100.
- **Mecanismo:** o `id` é interpolado na query: `WHERE id = ' . $id`. Embora `index.php` faça
  `cast` para `int`, a função pública é consumida por outros scripts do ISP; se algum deles
  passar uma string (ou deixar de validar), o banco executa SQL arbitrário.
- **Impacto:** leitura/alteração não autorizada de chamados.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** prepared statement com `WHERE id = ?`.

### F3 — SQL injection em `tecnicoNome`

- **Onde:** `code/lib.php`, linha 69.
- **Mecanismo:** o `tecnicoId` é interpolado na query: `WHERE id = ' . $tecnicoId`. Hoje o
  caller faz cast para `int`, mas a função é pública e recebe `?int`; chamadores futuros podem
  errar.
- **Impacto:** leitura do banco de usuários; pode ser combinado com `UNION`.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** prepared statement com `WHERE id = ?`.

### F4 — Regra de visibilidade não implementada

- **Onde:** `code/lib.php` (`listarChamados`, `verChamado`) e `code/index.php`.
- **Mecanismo:** o manifesto define que clientes só podem ver seus próprios chamados e técnicos
  podem ver qualquer chamado. As funções consultavam `chamados` sem filtrar `usuario_id`, então
  um cliente logado que alterasse `?ver=103` na URL visualizava chamados de outros clientes.
  Teste local: `ana` (uid=1) conseguia enxergar o chamado 103 de `bruno`.
- **Impacto:** vazamento de dados de suporte entre clientes.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** adicionei parâmetros opcionais `?int $usuarioId = null` e
  `?string $papel = null` a `listarChamados` e `verChamado`. Quando informados e o papel for
  `'cliente'`, a query inclui `AND usuario_id = ?`. Scripts externos antigos que chamam as
  funções com apenas os argumentos originais continuam recebendo todos os chamados
  (compatibilidade preservada). `index.php` passa `$uid` e `$papel` para as duas funções.

### F5 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php`, linha 116.
- **Mecanismo:** se não houver nenhum chamado com `minutos_resposta IS NOT NULL`, `$qtd` vale 0
  e `$soma / $qtd` gera `DivisionByZeroError` (PHP 8), quebrando a página inicial.
- **Impacto:** indisponibilidade do painel quando não há dados de SLA.
- **Severidade:** média.
- **Confiança:** 100.
- **O que foi feito:** retorno condicional: `$qtd > 0 ? $soma / $qtd : 0.0`.

### F6 — XSS refletido no campo de busca

- **Onde:** `code/index.php`, linhas 72, 79 e 82.
- **Mecanismo:** o valor de `$_GET['busca']` é reimpresso no atributo `value` do input e na
  mensagem “Resultados para: …” sem escaping. Um payload `<script>alert(1)</script>` é
  executado no navegador do usuário.
- **Impacto:** roubo de sessão, defacement, redirecionamento malicioso.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em ambos os pontos.

### F7 — XSS no ID do chamado

- **Onde:** `code/index.php`, linhas 59 e 90.
- **Mecanismo:** `$c['id']` é interpolado no título da página de detalhe e no texto do link da
  listagem sem escaping. Como o id vem do banco, o risco depende de algum outro vetor conseguir
  gravar um id malicioso (hoje improvável, pois é autoincremento), mas a prática é incorreta e
  fragiliza o código futuro.
- **Impacto:** execução de script se o id for controlável.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** `htmlspecialchars((string) $c['id'], ENT_QUOTES, 'UTF-8')` no texto
  exibido; o `href` continua usando cast explícito para `int`, que é seguro.

### F8 — CSV exportado para arquivo temporário em disco

- **Onde:** `code/lib.php`, linhas 125–150.
- **Mecanismo:** `exportarCsv` gravava `chamados.csv` em `/var/www/painel/tmp` e depois chamava
  `readfile`. Isso cria: (a) dependência de um diretório específico com permissões corretas;
  (b) risco de vazamento se o arquivo antigo for lido por outro processo; (c) lixo acumulado;
  (d) possibilidade de condição de corrida se duas exportações ocorrerem simultaneamente.
- **Impacto:** exposição de dados, falha de exportação por problema de permissão.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** o CSV agora é escrito diretamente em `php://output` com `fputcsv`, sem
  criar arquivo temporário. O cabeçalho é escrito manualmente (`fwrite`) para garantir o formato
  exato exigido pelo manifesto (`ID,Titulo,Status,Tecnico,Aberto em`), sem aspas introduzidas
  pelo `fputcsv`.

### F9 — Senhas armazenadas com MD5

- **Onde:** `code/lib.php`, linha 15; `code/schema.sql`, linha 7; `code/seed.sql`.
- **Mecanismo:** `autenticar` faz `md5($senha)` e compara com a coluna `senha CHAR(32)`. MD5 é
  rápido demais e vulnerável a rainbow tables/Força bruta; não é adequado para hash de senhas.
- **Impacto:** se o banco vazar, as senhas dos clientes/técnicos podem ser recuperadas.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** **não alterei**. Migrar para `password_hash`/`password_verify` exigiria
  rehashar todas as senhas existentes, alterar `schema.sql`/`seed.sql` e invalidar os dados de
  teste fornecidos. É a correção correta, mas foge do escopo de uma manutenção agêntica e deve
  ser feita em janela planejada com migração de hashes. Deixei documentado para a próxima
  evolução.

### F10 — Credenciais e chaves de API hardcoded

- **Onde:** `code/config.php`, linhas 12 e 15.
- **Mecanismo:** `DB_PASS` e `SMTP_API_KEY` possuem fallback embutido no código-fonte. Quem
  obtiver acesso ao repositório lê esses segredos.
- **Impacto:** vazamento de credenciais de produção e de API de e-mail.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** **não alterei**. Remover os fallbacks poderia quebrar ambientes legados
  que ainda dependem deles. A mudança correta é exigir que esses valores venham exclusivamente
  de variáveis de ambiente e remover os segredos do código, mas isso é uma alteração operacional
  que deve ser coordenada com o time de infraestrutura. O risco foi reportado.

---

## Decisões — o que não mudei e por quê

1. **Hash de senhas (MD5):** deixei como está para não invalidar os usuários de teste nem
   exigir migração de hashes em produção. A correção é prioritária, mas é uma mudança que
   demanda janela e planejamento.

2. **Credenciais hardcoded em `config.php`:** mantive os fallbacks para preservar o
   funcionamento em ambientes que ainda não usam variáveis de ambiente. A remoção deve ser
   acompanhada de mudança nos scripts de deploy.

3. **Constante `EXPORT_DIR` em `config.php`:** deixei a constante definida, mesmo que
   `exportarCsv` não a use mais. Scripts externos ou includes podem referenciá-la; removê-la seria
   uma quebra de compatibilidade silenciosa.

4. **Não adicionei CSRF token no login/formulários:** o login legado usa senha, o que já atua
   como uma camada, e adicionar proteção CSRF exigiria mudanças na interface e no fluxo. Fica
   como melhoria futura.

5. **Não implementei rate-limit/lockout:** força bruta no login é possível, mas adicionar
   rate-limit exigiria armazenamento de estado (banco/cache) e mudanças de comportamento. Fora
   do escopo desta manutenção.

---

## Verificação

- `php -l` em `config.php`, `index.php` e `lib.php`: sem erros de sintaxe.
- Testes de `lib.php`: autenticação, visibilidade, SQL injection, `tecnicoNome`, `mediaResposta`,
  CSV e compatibilidade legada passaram.
- Testes de `index.php` (renderização simulada): cliente vê só seus chamados, técnico vê todos,
  cliente não acessa chamado alheio via `?ver=`, XSS na busca é escapado, SQL injection na busca
  não expõe dados.
