# RELATORIO — LEB-100-A

## Resumo

O painel tinha falhas graves: qualquer usuário logado via todos os chamados (a regra de visibilidade do manifesto não era aplicada em lugar nenhum), SQL injection na busca, XSS refletido, divisão por zero na média e exportação via arquivo compartilhado. Corrigi o que dava para corrigir sem quebrar o contrato; senhas MD5 e credenciais embutidas foram só reportadas. A sintaxe PHP foi verificada com `php -l`; não havia MySQL no ambiente, então as consultas **não foram executadas** contra um banco.

## Achados (ordem de prioridade)

### F1 — Cliente vê chamados de qualquer um (IDOR / quebra da regra de visibilidade)
- **Onde:** `code/index.php` linhas 52–53 (numeração original)
- **Categoria/Severidade:** seguranca / critica — **Confiança:** 97
- **Mecanismo:** verChamado faz SELECT por id sem checar dono; index.php ?ver=<id> aceita qualquer id de qualquer sessão. A listagem (listarChamados) e o CSV também devolvem todos os chamados sem olhar $_SESSION['papel'], que nunca é usado.
- **Impacto:** Cliente ana lê/exporta chamados de bruno (dados pessoais, faturas) só trocando o id ou clicando em Exportar CSV.
- **Ação:** index.php calcula $filtroUsuario (null p/ técnico, uid p/ cliente) e passa a verChamado, listarChamados e exportarCsv, que ganharam parâmetro opcional final $usuarioId. Chamado alheio devolve 'não encontrado'. Chamadas antigas (sem o parâmetro) mantêm o comportamento. (corrigido)

### F2 — SQL injection na busca por título
- **Onde:** `code/lib.php` linhas 80–83 (numeração original)
- **Categoria/Severidade:** seguranca / critica — **Confiança:** 98
- **Mecanismo:** $busca (GET busca) é concatenada em "LIKE '%...%'" sem escape; ex.: busca=' UNION SELECT ... lê qualquer tabela, inclusive hashes de usuarios.senha.
- **Impacto:** Leitura arbitrária do banco, vazamento de hashes e dados de todos os clientes (inclusive contornando a regra de visibilidade).
- **Ação:** Prepared statement com bind_param; filtro por usuário também parametrizado. (corrigido)

### F3 — XSS refletido no termo de busca
- **Onde:** `code/index.php` linhas 79–82 (numeração original)
- **Categoria/Severidade:** seguranca / alta — **Confiança:** 95
- **Mecanismo:** $busca é impresso cru no atributo value="..." e em 'Resultados para:' sem htmlspecialchars; busca="><script>... executa no navegador da vítima via link.
- **Impacto:** Execução de JS na sessão do atendente/cliente (roubo de sessão, ações em nome do usuário).
- **Ação:** htmlspecialchars nas duas saídas; busca não-string (array) tratada como vazia. (corrigido)

### F4 — Credenciais embutidas no código-fonte (senha do banco de produção e chave SMTP)
- **Onde:** `code/config.php` linhas 12–15 (numeração original)
- **Categoria/Severidade:** seguranca / alta — **Confiança:** 90
- **Mecanismo:** DB_PASS tem fallback literal de produção e SMTP_API_KEY é constante literal; qualquer pessoa com acesso ao repositório/backup as obtém.
- **Impacto:** Comprometimento do banco de produção e abuso da conta de e-mail transacional.
- **Ação:** Não alterado: remover o fallback pode derrubar produção se a variável de ambiente não estiver definida. Recomendado: definir DB_PASS/SMTP_API_KEY no ambiente, rotacionar ambas e remover os literais. (não corrigido)

### F5 — Senhas armazenadas/validadas com MD5 sem sal
- **Onde:** `code/lib.php` linhas 15–17 (numeração original)
- **Categoria/Severidade:** seguranca / alta — **Confiança:** 92
- **Mecanismo:** autenticar usa md5($senha) comparado a usuarios.senha CHAR(32); hash rápido e sem sal, quebrável por rainbow table (as senhas do seed são triviais).
- **Impacto:** Vazamento do banco (ver F2) leva a recuperação imediata das senhas.
- **Ação:** Não alterado: CHAR(32) e o seed são o contrato de dados; migrar exige mudar schema e migração de usuários. Proposta: coluna nova com password_hash e re-hash no login. (não corrigido)

### F6 — Exportação CSV grava arquivo fixo compartilhado em disco e falha silenciosamente
- **Onde:** `code/lib.php` linhas 125–150 (numeração original)
- **Categoria/Severidade:** bug / media — **Confiança:** 85
- **Mecanismo:** Todo export escreve EXPORT_DIR/chamados.csv e depois o relê com readfile: requisições concorrentes corrompem/misturam o arquivo, o conteúdo (dados de todos) fica permanente em tmp acessível; se fopen falha ou a query falha retorna sem resposta (e sem fclose no segundo caso); headers só saem depois do processamento.
- **Impacto:** CSV corrompido ou com dados de outro usuário numa corrida; dados em repouso expostos; usuário recebe página vazia sem erro.
- **Ação:** Escrita direta em php://output (mesmo conteúdo, mesmo cabeçalho, ordenado por id), sem arquivo intermediário. EXPORT_DIR deixou de ser usado, constante mantida. (corrigido)

### F7 — Divisão por zero em mediaResposta quando não há chamados respondidos
- **Onde:** `code/lib.php` linhas 116–116 (numeração original)
- **Categoria/Severidade:** bug / media — **Confiança:** 95
- **Mecanismo:** $soma / $qtd com $qtd == 0 lança DivisionByZeroError (PHP 8) e derruba a página inicial inteira.
- **Impacto:** Painel indisponível para todos enquanto nenhum chamado tiver minutos_resposta.
- **Ação:** Cálculo por AVG() no SQL; retorna 0.0 se não houver dados. (corrigido)

### F8 — N+1 consultas para nome do técnico (listagem e CSV)
- **Onde:** `code/lib.php` linhas 64–93 (numeração original)
- **Categoria/Severidade:** performance / media — **Confiança:** 93
- **Mecanismo:** listarChamados e exportarCsv chamam tecnicoNome por linha, uma query extra por chamado.
- **Impacto:** Latência cresce linearmente com o número de chamados.
- **Ação:** LEFT JOIN em usuarios; tecnicoNome mantida (agora parametrizada) por compatibilidade. (corrigido)

### F9 — mediaResposta lê todas as linhas para somar em PHP
- **Onde:** `code/lib.php` linhas 109–115 (numeração original)
- **Categoria/Severidade:** performance / baixa — **Confiança:** 85
- **Mecanismo:** Transfere cada minutos_resposta ao PHP em vez de agregar no banco.
- **Impacto:** Custo desnecessário a cada carga do painel.
- **Ação:** Substituído por AVG() (ver F7). (corrigido)

### F10 — Sessão: sem regenerar id no login (fixation) e login aceita array
- **Onde:** `code/index.php` linhas 20–28 (numeração original)
- **Categoria/Severidade:** seguranca / media — **Confiança:** 70
- **Mecanismo:** session_start sem regenerate no login permite fixar o id da sessão; $_POST['login'][]=x chega como array a autenticar(string) e gera TypeError (500).
- **Impacto:** Sequestro de sessão por fixação; erro 500 forçado.
- **Ação:** session_regenerate_id(true) após autenticar; cast para string. Flags de cookie (HttpOnly/SameSite) não alteradas por serem configuração de infraestrutura. (corrigido)

### F11 — Injeção em SQL de tecnicoNome/verChamado por concatenação
- **Onde:** `code/lib.php` linhas 69–69 (numeração original)
- **Categoria/Severidade:** qualidade / baixa — **Confiança:** 60
- **Mecanismo:** Concatenação de inteiro tipado: hoje não explorável (tipos int), mas padrão frágil.
- **Impacto:** Risco latente se a tipagem mudar.
- **Ação:** Convertidas para prepared statements. (corrigido)

### F12 — Sem logout, sem proteção contra força bruta no login, sem CSRF/headers de segurança
- **Onde:** `code/index.php` linhas 20–36 (numeração original)
- **Categoria/Severidade:** seguranca / baixa — **Confiança:** 55
- **Mecanismo:** Login sem limite de tentativas, sem rota de logout; nenhuma aplicação com POST de estado, então CSRF hoje não relevante.
- **Impacto:** Adivinhação de senhas fracas (F5).
- **Ação:** Não alterado (funcionalidade nova, fora do escopo/contrato). (não corrigido)

### F13 — Injeção de fórmula em CSV (títulos começando com = + - @)
- **Onde:** `code/lib.php` linhas 138–144 (numeração original)
- **Categoria/Severidade:** seguranca / baixa — **Confiança:** 50
- **Mecanismo:** fputcsv não neutraliza títulos digitados por clientes; abertos no Excel podem executar fórmulas.
- **Impacto:** Ataque a quem abre o relatório.
- **Ação:** Não alterado: prefixar apóstrofo mudaria o conteúdo lido pelas rotinas consumidoras do CSV. (não corrigido)

### F14 — rotuloPrioridade com ifs aninhados; formatarStatus trata qualquer valor desconhecido como Resolvido
- **Onde:** `code/lib.php` linhas 26–59 (numeração original)
- **Categoria/Severidade:** qualidade / baixa — **Confiança:** 60
- **Mecanismo:** Lógica correta mas difícil de ler; status fora de 1-3 vira 'Resolvido'.
- **Impacto:** Manutenção; dado inválido mascarado.
- **Ação:** Não alterado: valores de retorno são contrato; sem ganho para o risco. (não corrigido)

## Decisões (o que não mudei e por quê)

- **DB_PASS/SMTP_API_KEY literais em config.php (F4):** Remover fallback pode derrubar produção; exige rotação e configuração de ambiente pela operação.
- **Hash MD5 (F5):** Depende de schema CHAR(32)/seed/migração de dados; fora do contrato.
- **Assinaturas públicas:** Apenas adicionei parâmetro opcional final em listarChamados, verChamado e exportarCsv; chamadas existentes funcionam idênticas. Alternativa sem tocar assinaturas seria filtrar em index.php, mas o export precisaria de lógica duplicada.
- **mediaResposta continua global para clientes:** É agregado sem dados individuais e o manifesto não o restringe.
- **rotuloPrioridade/formatarStatus (F14), CSV formula (F13), logout/brute-force (F12):** Risco/benefício e contrato; ver achados.
- **Config de cookie de sessão, HTTPS, headers:** Infraestrutura, não aparece no código.
- **schema.sql e seed.sql:** Sem mudanças; índices existentes atendem. LIKE '%x%' não usa índice, mas é requisito de busca.
