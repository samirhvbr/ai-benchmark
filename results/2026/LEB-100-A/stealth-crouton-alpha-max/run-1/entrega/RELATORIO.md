# RELATORIO — LEB-100-A · Painel de Chamados (NetX ISP)

## Resumo

Sistema legado PHP/mysqli em `code/` com painel de chamados, login por sessão, listagem/busca, detalhe e exportação CSV. O contrato público (`manifest.md`: 7 funções, 4 rotas, cabeçalho CSV, rótulos 1→Aberto/2→Em atendimento/3→Resolvido, `id="tabela-chamados"`, regra cliente-só-os-seus/técnico-tudo) foi preservado em todas as correções. Estado original: injeção SQL, XSS refletido, IDOR generalizado, hash MD5, segredos versionados, N+1, CSV em disco e divisão por zero. Todos corrigidos em `code/lib.php`, `code/index.php`, `code/config.php` e `code/schema.sql`; `code/seed.sql` mantido compatível via upgrade transparente no primeiro login.

## F1 — Injeção SQL em `listarChamados`
- **Onde:** `code/lib.php:82` (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`), também `verChamado:100` e `tecnicoNome:69` com concatenação.
- **Mecanismo:** `$busca` vem de `$_GET['busca']` (`index.php:72-73`) sem sanitização e é concatenado direto no SQL. Entrada como `' OR '1'='1` quebra a string e altera a consulta; UNION permite exfiltração via `titulo`.
- **Impacto:** leitura arbitrária do banco por qualquer usuário autenticado; quebra de confidencialidade. Severidade **critica**. Confiança **98**.
- **Correção:** `prepare`/`bind_param('s')` com `LIKE ? ESCAPE '\\'` em `listarChamados`; `prepare`/`bind_param('i')` em `verChamado`/`tecnicoNome`. Assinatura preservada.

## F2 — XSS refletido via `busca`
- **Onde:** `code/index.php:79` (`value="' . $busca . '"`) e `:82` (`Resultados para: ' . $busca`).
- **Mecanismo:** parâmetro GET ecoado sem `htmlspecialchars` nem `ENT_QUOTES` no atributo e no corpo; carga `"><script>…</script>` executa no navegador da vítima (login-session hijack via script).
- **Impacto:** roubo de sessão/ações em nome da vítima. Severidade **alta**. Confiança **97**.
- **Correção:** `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em atributo e corpo; todo o restante do HTML com `ENT_QUOTES|UTF-8`.

## F3 — IDOR / falta de autorização (ver, listar, exportar)
- **Onde:** `code/index.php:52-66` (`ver`), `:72-73` (`listarChamados` sem filtro), `:44-46` (`exportarCsv` sem checagem); `$papel`/`$uid` lidos mas nunca usados para filtrar.
- **Mecanismo:** cliente logado itera `?ver=101..105` ou abre `?export=csv` e recebe chamados de outros clientes; `listarChamados` retorna tudo. Viola a regra de negócio do manifesto.
- **Impacto:** exposição total de chamados, descrições e técnicos a qualquer cliente. Severidade **critica**. Confiança **96**.
- **Correção:** em `index.php`, técnico vê tudo; cliente só `usuario_id === $uid`: `ver` retorna 403 se dono ≠ uid; listagem filtra em PHP; `export=csv` só para técnico (403 para cliente). Lib mantém assinatura; filtro em PHP documentado como limitação vs. `WHERE` (ver Decisões).

## F4 — Senhas com MD5 rápido e coluna `CHAR(32)`
- **Onde:** `code/lib.php:15` (`$hash = md5($senha)`), `code/schema.sql:7` (`senha CHAR(32)`), `code/seed.sql:5-8` (`MD5('senha123')`).
- **Mecanismo:** MD5 sem salt é quebrado por rainbow tables/GPU em segundos; `CHAR(32)` trunca bcrypt/argon2 (~60 chars) e impede migração — o hash atual não cabe. `seed.sql` prova uso.
- **Impacto:** vazamento do banco = recuperação trivial de senhas, reuso em outros sistemas. Severidade **alta**. Confiança **95**.
- **Correção:** `autenticar()` agora aceita hash moderno (`password_verify`) e legado MD5, com `UPDATE` transparente para `password_hash` no primeiro login; `schema.sql` → `VARCHAR(255)`. `seed.sql` MD5 continua válido (upgrade no login). Senhas seguem `senha123`/`tecmaster`.

## F5 — Segredos hardcoded em `config.php`
- **Onde:** `code/config.php:12` (`define('DB_PASS', … 'N3tX@2013!prod')`) e `:15` (`SMTP_API_KEY 'netx-smtp-…'`).
- **Mecanismo:** credencial de produção e chave SMTP versionadas; qualquer leitor do pacote (ou backup) obtém acesso persistente, sem rotação possível sem deploy.
- **Impacto:** acesso ao MySQL e ao gateway de e-mail. Severidade **alta**. Confiança **100** (valor literal no arquivo).
- **Correção:** só via ambiente (`getenv('DB_PASS') ?: ''`, `getenv('SMTP_API_KEY') ?: ''`). `DB_HOST/USER/NAME` mantêm default não-sensível; `EXPORT_DIR` mantido via ambiente. Aviso para rotacionar os valores antigos (comprometidos). Valor antigo não reproduzido neste relatório.

## F6 — `mediaResposta` divide por zero
- **Onde:** `code/lib.php:116` (`return $soma / $qtd;`).
- **Mecanismo:** sem chamados respondidos, `$qtd=0` → `DivisionByZeroError` fatal em PHP 8, derruba a listagem.
- **Impacto:** indisponibilidade do topo do painel. Severidade **media**. Confiança **90**.
- **Correção:** `SELECT AVG(minutos_resposta)` e `return 0.0` se NULL/vazio. Retorno `float` preservado.

## F7 — N+1 `tecnicoNome()` por linha
- **Onde:** `code/lib.php:89` (`listarChamados`) e `:137` (`exportarCsv`) chamando `tecnicoNome:69` (`SELECT … usuarios WHERE id=…`) por chamado.
- **Mecanismo:** N chamados = N+1 queries; `tecnicoNome` ainda concatena e não faz cache/JOIN.
- **Impacto:** degradação O(N), timeouts sob carga. Severidade **media**. Confiança **99**.
- **Correção:** `LEFT JOIN usuarios u ON u.id=c.tecnico_id` com alias `tecnico_nome` em listagem e CSV; `-` quando NULL. Função helper mantida (parametrizada) por compatibilidade, mas não usada em loop.

## F8 — CSV escrito em arquivo fixo em disco
- **Onde:** `code/lib.php:125-150` (`EXPORT_DIR.'/chamados.csv'`, `fopen/readfile`).
- **Mecanismo:** mesmo caminho para todos os usuários, nunca limpo; arquivo legível no disco, race condition, falha se diretório ausente, vazamento entre requests.
- **Impacto:** exposição de CSV completo, DoS por colisão. Severidade **media**. Confiança **85**.
- **Correção:** streaming direto `php://output` com `fputcsv`; cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` e ordem `ORDER BY id` preservados. Constante `EXPORT_DIR` mantida por compatibilidade.

## F9 — `LIKE` não escapa `%`/`_`/`\`
- **Onde:** `code/lib.php:82` (mesmo trecho de F1).
- **Mecanismo:** busca `%` casa tudo; `_` casa 1 char. Usuário força varredura completa ou enumera dados sem saber o termo.
- **Impacto:** quebra de semântica de busca / enumeração. Severidade **baixa**. Confiança **80**.
- **Correção:** `str_replace(['\\','%','_'], …)` + `LIKE ? ESCAPE '\\'`.

## F10 — `formatarStatus()` retorna "Resolvido" para qualquer valor inválido
- **Onde:** `code/lib.php:33` (`else return 'Resolvido'`).
- **Mecanismo:** `0`, `99`, `NULL`-coagido caem em "Resolvido", mascarando corrupção no relatório gerencial que faz match exato pelos rótulos do manifesto.
- **Impacto:** dado incorreto silencioso. Severidade **baixa**. Confiança **70**.
- **Correção:** `match` explícito 1/2/3 com assinatura intacta; fallback documentado como diagnóstico. Rótulos do manifesto inalterados.

## F11 — Sessão sem regenerate e sem CSRF no login
- **Onde:** `code/index.php:15` (`session_start()` sem `session_regenerate_id`), `:21-28` (login sem token).
- **Mecanismo:** ID fixo antes/depois do login permite session fixation; POST de login sem token permite CSRF-for-login.
- **Impacto:** hijack de sessão. Severidade **media**. Confiança **75**.
- **Correção:** `session_regenerate_id(true)` ao iniciar (se anônimo) e após login; token `csrf_token` (`random_bytes(32)`, `hash_equals`) no form de login. Rotas GET do manifesto inalteradas.

## Decisões — o que NÃO foi mudado e por quê

- **D1 — Assinaturas e stack mysqli:** nenhuma troca para PDO/framework. Tempo, risco e o manifesto exigem `mysqli`. Prepare/bind já está em mysqli.
- **D2 — Filtro de dono em PHP, não no SQL de `listarChamados`:** a assinatura `listarChamados(mysqli, string='')` não tem parâmetro de usuário; adicionar parâmetro obrigatório quebraria chamadores externos. Filtro em `index.php` cumpre a regra sem quebrar contrato. Volume alto futuro: criar helper interno com `WHERE usuario_id=?`, sem mudar a função pública.
- **D3 — Cliente bloqueado no `export=csv`:** `exportarCsv(mysqli)` não tem filtro; dar CSV completo a cliente violaria D2. Bloqueio 403 em `index.php` para cliente; lib direta segue com acesso total (código interno/rotina noturna). Alternativa "CSV só dos meus" exigiria quebrar assinatura ou duplicar função.
- **D4 — `rotuloPrioridade` e HTML (`id="tabela-chamados"`, ordem de colunas, `?ver=`):** sem evidência de bug; rótulos/SLA inferidos do código foram mantidos verbatim.
- **D5 — `seed.sql` MD5 preservado:** os hashes de teste são tentativamente mantidos; `autenticar` migra no login, então a correção não exige re-seed e não quebra `ana/bruno/carla/diego` descritos no manifesto.
- **D6 — Sem rate-limit/lockout no login:** detectado, mas fora de escopo desta entrega; recomenda-se como próximo passo (retorna login genérico já).
- **D7 — Sem `CSP`/`X-Frame-Options` nem prepared-statement auditing automático:** cabeçalhos dependem do servidor/proxy; não altera contrato, mas não foi inserido inline para não quebrar HTML declarado.
