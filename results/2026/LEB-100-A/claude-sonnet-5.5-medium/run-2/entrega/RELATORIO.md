# RELATORIO — LEB-100-A

## Resumo
Painel PHP/mysqli pequeno com falhas graves de segurança: SQL injection, XSS refletido, quebra da regra de visibilidade (cliente vê chamados de todos), segredos no código. Também há bug de divisão por zero, N+1 e export por arquivo compartilhado. Corrigi tudo que era possível sem quebrar o manifesto (assinaturas só ganharam parâmetro opcional final). `php -l` passou nos três arquivos; **não havia MySQL no ambiente, então as consultas não foram executadas** — recomendo rodar schema+seed e conferir: listagem, `?ver=`, busca e CSV (ana não deve ver 103/104; carla vê todos).

## F1 — SQL injection na busca por título
- **Onde:** `code/lib.php` linhas 80–84 (numeração original)
- **Mecanismo:** listarChamados concatena $busca (vinda de $_GET['busca'] sem tratamento) dentro de LIKE '%...%'. Um valor como ' UNION SELECT ... -- altera a consulta.
- **Impacto / severidade:** Leitura de qualquer tabela (inclusive hashes md5 de usuarios) e, conforme privilégios do usuário do banco, escrita. Severidade **critica** (seguranca).
- **Confiança:** 98
- **O que fiz:** Consulta preparada com bind_param; mesmo comportamento de LIKE. (corrigido: sim)

## F2 — Chamados de outros clientes visíveis (detalhe, listagem e CSV sem filtro de dono)
- **Onde:** `code/lib.php` linhas 98–102 (numeração original)
- **Mecanismo:** verChamado busca só por id; index.php?ver=<id> não checa $_SESSION['uid'/'papel']. listarChamados e exportarCsv também selecionam todos os chamados. Qualquer cliente logado enumera ids e lê descrições alheias, exporta todos no CSV e vê todos na listagem. A regra do manifesto (cliente só vê os seus) nunca é aplicada.
- **Impacto / severidade:** Vazamento de dados de todos os clientes por qualquer cliente autenticado. Severidade **alta** (seguranca).
- **Confiança:** 97
- **O que fiz:** Parâmetro opcional final $usuarioId em listarChamados, verChamado e exportarCsv (assinaturas compatíveis; chamadas antigas continuam vendo tudo). index.php passa o uid quando papel != tecnico. Chamado alheio responde 'nao encontrado'. (corrigido: sim)

## F3 — XSS refletido em busca
- **Onde:** `code/index.php` linhas 79–83 (numeração original)
- **Mecanismo:** $busca é ecoado sem htmlspecialchars no atributo value e no parágrafo 'Resultados para'. busca="><script>... executa no navegador da vítima via link.
- **Impacto / severidade:** Execução de JS na sessão de técnico/cliente (roubo de dados da sessão, ações em nome do usuário). Severidade **alta** (seguranca).
- **Confiança:** 97
- **O que fiz:** htmlspecialchars (com ENT_QUOTES no atributo). (corrigido: sim)

## F4 — Segredos embutidos no código (senha do banco de produção e chave SMTP)
- **Onde:** `code/config.php` linhas 11–15 (numeração original)
- **Mecanismo:** DB_PASS tem fallback literal com a senha de produção e SMTP_API_KEY é constante literal; ficam em qualquer cópia/backup/VCS do código.
- **Impacto / severidade:** Quem lê o código obtém acesso ao banco e ao serviço de e-mail. Severidade **alta** (seguranca).
- **Confiança:** 95
- **O que fiz:** Removidos os literais; ambos vêm de variável de ambiente. AÇÃO OPERACIONAL: definir DB_PASS no ambiente antes do deploy e ROTACIONAR as duas credenciais, pois já estão expostas. (corrigido: sim)

## F5 — Exportação CSV grava arquivo fixo compartilhado em disco
- **Onde:** `code/lib.php` linhas 125–150 (numeração original)
- **Mecanismo:** Todo export escreve EXPORT_DIR/chamados.csv e depois o relê. Dois usuários simultâneos sobrescrevem/misturam o arquivo (race); ele permanece em disco com todos os dados, possivelmente servido via web; se a query falha retorna sem fechar o fp, deixando arquivo parcial; se fopen falha o usuário recebe resposta vazia sem cabeçalhos.
- **Impacto / severidade:** Usuário pode baixar CSV de outro; dados persistem em disco; falhas silenciosas. Severidade **media** (bug).
- **Confiança:** 85
- **O que fiz:** CSV é escrito direto em php://output, sem arquivo. Formato idêntico. Ver 'nao_alterei' sobre rotinas que lessem o arquivo. (corrigido: sim)

## F6 — Divisão por zero em mediaResposta
- **Onde:** `code/lib.php` linhas 107–117 (numeração original)
- **Mecanismo:** Se nenhum chamado tem minutos_resposta (banco novo ou todos NULL), $soma / $qtd com $qtd=0 lança DivisionByZeroError no PHP 8 e derruba a listagem inteira, página inicial do painel.
- **Impacto / severidade:** Indisponibilidade da tela principal em certos estados dos dados. Severidade **media** (bug).
- **Confiança:** 90
- **O que fiz:** Usa AVG() no banco; sem dados devolve 0.0. (corrigido: sim)

## F7 — N+1 consultas para nome do técnico (listagem e export)
- **Onde:** `code/lib.php` linhas 89–137 (numeração original)
- **Mecanismo:** Para cada chamado é executada uma query em tecnicoNome (listarChamados linha 89, exportarCsv linha 137): 1+N consultas; mediaResposta ainda carrega todas as linhas para somar em PHP.
- **Impacto / severidade:** Latência cresce linearmente com o número de chamados. Severidade **media** (performance).
- **Confiança:** 92
- **O que fiz:** LEFT JOIN em usuarios (tecnico_nome '-' quando sem técnico) e AVG() no SQL. tecnicoNome mantida, agora preparada. (corrigido: sim)

## F8 — Sessão: sem regenerar id no login, cookie sem flags, entrada não-string derruba o login/busca
- **Onde:** `code/index.php` linhas 15–29 (numeração original)
- **Mecanismo:** session_start sem httponly/samesite/strict_mode e sem session_regenerate_id após autenticar permite fixação de sessão. POST login[]=x ou GET busca[]=x chega como array e provoca TypeError (autenticar/listarChamados tipados string) -> erro 500.
- **Impacto / severidade:** Fixação de sessão; erro fatal provocável por qualquer visitante. Severidade **media** (seguranca).
- **Confiança:** 80
- **O que fiz:** Flags httponly/samesite/strict_mode, session_regenerate_id(true), validação is_string em login, senha e busca. (corrigido: sim)

## F9 — Senhas armazenadas como md5 sem sal
- **Onde:** `code/lib.php` linhas 15–20 (numeração original)
- **Mecanismo:** autenticar compara md5($senha) com a coluna CHAR(32); md5 é rápido e sem sal, hashes iguais para senhas iguais (ana e bruno compartilham hash). Combinado com F1 permite quebra offline trivial.
- **Impacto / severidade:** Comprometimento em massa de contas se o banco vazar. Severidade **alta** (seguranca).
- **Confiança:** 95
- **O que fiz:** Não corrigido: migrar exige ampliar a coluna senha (schema) e rehash gradual no login (password_hash/password_verify). Proposta no relatório. (corrigido: não)

## F10 — Sem limitação de tentativas de login
- **Onde:** `code/index.php` linhas 20–29 (numeração original)
- **Mecanismo:** Nenhum contador/atraso por usuário ou IP; combinado com md5 e senhas simples do seed, força bruta é viável.
- **Impacto / severidade:** Adivinhação de senhas. Severidade **media** (seguranca).
- **Confiança:** 70
- **O que fiz:** Não corrigido (precisa de armazenamento/infra novos; melhor no proxy/WAF ou tabela de tentativas). (corrigido: não)

## F11 — CSV sujeito a injeção de fórmula
- **Onde:** `code/lib.php` linhas 138–144 (numeração original)
- **Mecanismo:** Título é texto livre do cliente; se começar com =, +, -, @ o Excel do técnico pode interpretá-lo como fórmula.
- **Impacto / severidade:** Execução de fórmula na planilha de quem abre o CSV. Severidade **baixa** (seguranca).
- **Confiança:** 50
- **O que fiz:** Não corrigido: prefixar apóstrofo alteraria os valores consumidos por rotinas externas (contrato do CSV). (corrigido: não)

## F12 — Qualidade: if/else aninhado, status desconhecido vira 'Resolvido', sem tratamento de erro/logging
- **Onde:** `code/lib.php` linhas 26–59 (numeração original)
- **Mecanismo:** formatarStatus devolve 'Resolvido' para qualquer valor != 1,2; rotuloPrioridade tem 4 níveis de aninhamento. Funciona para os valores do schema.
- **Impacto / severidade:** Status inválido aparece como Resolvido; manutenção mais difícil. Severidade **baixa** (qualidade).
- **Confiança:** 60
- **O que fiz:** Não alterado: o contrato de valor é fixo e o comportamento é coberto por consumidores. (corrigido: não)

## Decisões (o que NÃO mudei)

- **Hash md5 de senhas (F9)** — Exige mudar schema.sql (coluna maior) e fluxo de migração; fora do escopo de evolução segura sem janela de migração. Proposta: password_hash no login bem-sucedido ao detectar md5.
- **Rate limit de login (F10)** — Requer nova infraestrutura; recomendável no proxy.
- **CSV: prefixo anti-fórmula (F11) e arquivo em EXPORT_DIR** — Contrato do formato; a constante EXPORT_DIR foi mantida em config.php caso algum script a use. Se a rotina noturna lia chamados.csv do disco, ela deve passar a chamar exportarCsv (saída) — risco declarado.
- **formatarStatus/rotuloPrioridade** — Rótulos e regras são contrato; refatorar traz risco sem ganho funcional.
- **mediaResposta global para clientes** — É um agregado sem dados individuais; manifesto não restringe; mantido.
- **Semântica de LIKE (% e _ digitados pelo usuário funcionam como curinga)** — Comportamento atual preservado; impacto apenas de relevância, sem risco.
- **Tela de login sem mensagem de erro, sem CSRF/logout, sem cabeçalhos de segurança** — Mudança de UX/escopo; não há ações de escrita que exijam CSRF hoje.

## Compatibilidade
Nomes/assinaturas preservados (novo parâmetro opcional final em `listarChamados`, `verChamado`, `exportarCsv`); rotas, cabeçalho e ordem do CSV, rótulos, id `tabela-chamados` e colunas inalterados.
