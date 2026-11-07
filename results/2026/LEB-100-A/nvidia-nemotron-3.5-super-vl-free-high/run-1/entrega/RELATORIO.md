# RELATORIO TECNICO — LEB-100-A

## Resumo

O sistema é um painel de chamados legado (PHP + MySQL/mysqli) com ponto de entrada único
(`index.php`) e camada de acesso em `lib.php`. Funciona, mas apresenta falhas graves de
segurança (injecao SQL na busca, XSS refletido no parametro `busca` e quebra da regra de
visibilidade: clientes veem e exportam chamados de outros clientes), um erro de tempo de
execucao (divisao por zero em `mediaResposta` quando nao há tempos de resposta) e fraquezas
de autenticacao/sessao (hash MD5 sem sal e ausencia de regeneracao de ID de sessao apos
login). Todas as assinaturas publicas declaradas em `manifest.md` foram preservadas; nenhum
arquivo foi renomeado, movido ou reescrito.

## Achados (prioridade de correcao)

### F1 — Injecao SQL na busca (`code/lib.php`, linha 82)

- **O que é:** `listarChamados` monta o SQL concatenando o parametro `busca` diretamente na
  string da consulta: `" WHERE titulo LIKE '%" . $busca . "%'"`.
- **Mecanismo:** o valor de `$_GET['busca']` chega via `index.php` (linha 73 original) e e
  interpolado sem escape nem prepared statement. Um atacante pode enviar
  `?busca=' UNION SELECT id,login,senha...--` e extrair dados do banco, incluindo senhas.
- **Impacto:** leitura nao autorizada de qualquer tabela do banco (usuarios, senhas hash,
  chamados de terceiros).
- **Severidade:** critica.
- **Confianca:** 100.
- **O que fiz:** substituí a concatencao por prepared statement (`titulo LIKE ?`) com
  bind_param, preservando a assinatura `listarChamados(mysqli $db, string $busca = ''): array`.
  Corrigido: true.

### F2 — XSS refletido no parametro `busca` (`code/index.php`, linhas 79 e 82)

- **O que é:** o valor de `$busca` e impresso no HTML sem `htmlspecialchars`, tanto no
  `value` do input quanto no texto "Resultados para: ...".
- **Mecanismo:** `index.php` faz `echo '<form ... value="' . $busca . '" ...>'` e
  `echo '<p>Resultados para: ' . $busca . '</p>'`. Um link malicioso contendo
  `<script>alert(1)</script>` no parametro `busca` executa JavaScript no navegador da
  vítima.
- **Impacto:** roubo de sessao/cookies, execucao de acoes em nome do usuario logado.
- **Severidade:** alta.
- **Confianca:** 100.
- **O que fiz:** envolvi as saidas com `htmlspecialchars($busca, ENT_QUOTES)` e
  `htmlspecialchars($busca)`. Corrigido: true.

### F3 — Quebra de visibilidade na listagem (`code/lib.php`, linha 80 original)

- **O que é:** `listarChamados` retorna todos os chamados independentemente de quem abriu.
  O manifesto exige que um cliente veja apenas os chamados que ele mesmo abriu.
- **Mecanismo:** a consulta e `SELECT * FROM chamados` sem filtro de `usuario_id`, e a
  funcao nao recebe contexto do usuario logado. Um cliente logado enxerga chamados de
  outros clientes na tabela e na busca.
- **Impacto:** divulgacao de informacoes de chamados alheios (titulo, descricao, tecnico,
  status) para clientes.
- **Severidade:** alta.
- **Confianca:** 100.
- **O que fiz:** adiciei filtro `usuario_id = ?` (prepared statement) quando o papel na
  sessao e `cliente`. Assinatura nao mudada. Corrigido: true.

### F4 — IDOR no detalhe do chamado (`code/lib.php`, linha 100 original)

- **O que é:** `verChamado` carrega qualquer chamado pelo `id`, sem verificar se o cliente
  logado e o dono.
- **Mecanismo:** `SELECT * FROM chamados WHERE id = ' . $id`. Como o `id` chega
  convertido para int na rota, nao houve injecao SQL, mas nao ha controle de propriedade:
  um cliente pode adivinhar o `id` de um chamado de outro cliente via `?ver=<id>` e lê-lo.
- **Impacto:** acesso a detalhes (incluindo descricao) de chamados de terceiros.
- **Severidade:** alta.
- **Confianca:** 100.
- **O que fiz:** mantive prepared statement e adiciei verificacao de propriedade para
  papel `cliente`; retorno `null` (mensagem "Chamado nao encontrado") quando nao for o
  dono, sem confirmar existencia. Assinatura nao mudada. Corrigido: true.

### F5 — Export CSV ignora visibilidade (`code/lib.php`, linha 132 original)

- **O que é:** `exportarCsv` exporta todos os chamados para qualquer usuario logado,
  incluindo clientes, violando a regra de visibilidade.
- **Mecanismo:** `SELECT * FROM chamados ORDER BY id` sem filtro de usuario, e a rota de
  exportacao em `index.php` nao restringe por papel. Um cliente acessando
  `index.php?export=csv` recebe chamados de todos os usuarios.
- **Impacto:** divulgacao em massa de chamados alheios via CSV.
- **Severidade:** alta.
- **Confianca:** 100.
- **O que fiz:** adiciei filtro `usuario_id = ?` para papel `cliente` dentro de
  `exportarCsv`, usando a sessao ja iniciada em `index.php`. Assinatura
  `exportarCsv(mysqli $db): void` preservada. Corrigido: true.

### F6 — Divisao por zero em `mediaResposta` (`code/lib.php`, linha 116 original)

- **O que é:** quando nenhum chamado tem `minutos_resposta` preenchido, `$qtd` fica 0 e a
  funcao executa `return $soma / $qtd`, gerando aviso de divisao por zero e resultado
  invalido (NaN/INF).
- **Mecanismo:** falta de guarda contra `$qtd === 0` antes da divisao. No painel,
  `round($media)` exibe um valor invalido/aviso.
- **Impacto:** erro de tempo de execucao e indicador de SLA quebrado no topo do painel.
- **Severidade:** media.
- **Confianca:** 100.
- **O que fiz:** retorne `0.0` quando `$qtd === 0`. Corrigido: true.

### F7 — Ausencia de regeneracao de ID de sessao apos login (`code/index.php`, linhas 24-26)

- **O que é:** apos autenticacao bem-sucedida, o ID de sessao nao e regenerado.
- **Mecanismo:** fixacao de sessao: um atacante pode forjar um ID de sessao, induzir a
  vítima a logar com ele e, apos o login, a sessao ja esta autenticada sob o ID conhecido
  pelo atacante.
- **Impacto:** sequestro de sessao de usuario autenticado.
- **Severidade:** media.
- **Confianca:** 90.
- **O que fiz:** adicionei `session_regenerate_id(true);` apos definir
  `$_SESSION['uid']` e `$_SESSION['papel']`. Corrigido: true.

### F8 — Hash de senha MD5 sem sal (`code/lib.php`, linha 15)

- **O que é:** `autenticar` compara senhas com `md5($senha)`, algoritmo criptograficamente
  quebrado e sem sal.
- **Mecanismo:** senhas armazenadas como MD5 simples (`senha CHAR(32)` no schema). Hashes
  iguais para senhas iguais e vulneraveis a tabela arco-iris/brute-force offline.
- **Impacto:** se o banco vazar, as senhas sao facilmente recuperaveis.
- **Severidade:** media.
- **Confianca:** 100.
- **O que fiz:** nao alterei. Motivo: mudar o algoritmo quebraria a autenticacao de todos
  os usuarios existentes (hashes MD5 ja armazenados) e a coluna `senha` e `CHAR(32)`, muito
  curta para hashes modernos (bcrypt/argon2), exigindo mudanca de schema e migracao de
  credenciais que rompe o contrato de login. Reportado como nao corrigido. Corrigido: false.

### F9 — Concatencao de `tecnicoId` em SQL (`code/lib.php`, linha 69 original)

- **O que é:** `tecnicoNome` monta `SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`
  sem prepared statement.
- **Mecanismo:** atualmente chamado sempre com `(int)` dos chamadores, entao nao e
  exploravel no fluxo atual, mas e uma injecao SQL latente se a funcao for chamada
  diretamente com um valor nao inteiro.
- **Impacto:** injecao SQL se o uso futuro nao garantir casting.
- **Severidade:** baixa.
- **Confianca:** 80.
- **O que fiz:** converti para prepared statement com `bind_param('i', ...)`. Assinatura
  preservada. Corrigido: true.

### F10 — Credenciais embutidas em `config.php` (`code/config.php`, linhas 12 e 15)

- **O que é:** senha do banco (`DB_PASS`) e chave de API SMTP (`SMTP_API_KEY`) estao
  escritas no codigo-fonte com fallback embutido.
- **Mecanismo:** segredos versionados junto ao codigo; qualquer pessoa com leitura do
  repositorio obtém credenciais de producao.
- **Impacto:** acesso ao banco e ao servico de e-mail transacional.
- **Severidade:** media.
- **Confianca:** 100.
- **O que fiz:** nao alterei. O codigo ja suporta sobrescrita por variaveis de ambiente via
  `getenv`; remover o fallback poderia quebrar deploios que nao definem as variaveis.
  Reportado como nao corrigido. Corrigido: false.

## Decisoes (o que nao mudei e por que)

1. **Hash MD5 de senha (`autenticar`):** mantido. Alterar quebraria o login de todos os
   usuarios existentes e exigiria mudanca de schema (`senha CHAR(32)`) + migracao de
   credenciais, rompendo o contrato de autenticacao. Reportado (F8).
2. **Credenciais embutidas (`config.php`):** mantido. O suporte a `getenv` ja permite
   sobrescrita; remover fallbacks e arriscar quebra de deploios sem ganho claro de
   contrato publico. Reportado (F10).
3. **Assinaturas publicas:** nenhuma alterada (`autenticar`, `formatarStatus`,
   `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`),
   conforme o manifesto. O controle de visibilidade e feito dentro das funcoes usando a
   sessao ja iniciada, sem novos parametros.
4. **Nomes e locais de arquivos:** inalterados (`code/` mantido in-place, mesmos nomes).
5. **Tecnologia de acesso a dados:** mantida `mysqli`; nenhuma dependencia nova adicionada.
6. **`mediaResposta`:** passou de "aviso + NaN/INF" para `0.0` quando nao há dados. Trata-se
   de correcao de bug; o contrato de retorno (`float`) e respeitado.
7. **Não reescrevi o sistema:** so evoluí pontos pontuais (prepared statements, filtros de
   visibilidade, guarda de divisao, regeneracao de sessao, escape de saida).

## Compatibilidade com o manifesto

Todas as regras de `manifest.md` foram preservadas: assinaturas das funcoes publicas,
rótulos de status (`1 → Aberto`, `2 → Em atendimento`, `3 → Resolvido`), nomes de rotas e
parametros (`busca`, `ver`, `export`), cabeçalho exato do CSV, ordem das colunas da tabela
(`id="tabela-chamados"`), IDs como links para `?ver=<id>`, e a regra de visibilidade
(cliente so vê os próprios chamados; tecnico vê todos) agora efetivamente aplicada na
listagem, no detalhe e na exportacao CSV.
