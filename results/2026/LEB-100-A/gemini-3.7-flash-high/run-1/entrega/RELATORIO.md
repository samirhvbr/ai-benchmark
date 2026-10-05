# Relatório Técnico de Auditoria e Evolução — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP) foi submetido a uma auditoria aprofundada de segurança, performance, arquitetura, bugs e qualidade de código. 

Foram identificadas vulnerabilidades críticas de segurança — incluindo Injeção de SQL (SQLi) na busca, quebra de controle de acesso (IDOR) expondo chamados privados entre clientes, Cross-Site Scripting (XSS) refletido e fixação de sessão. Além disso, foram diagnosticados problemas de estabilidade e escalabilidade, como divisão por zero em bases sem resposta calculada, condição de corrida e colisão de arquivos temporários na exportação CSV, e consultas N+1 degradando a performance do banco de dados.

Todas as correções foram implementadas no código preservando integralmente o contrato e as assinaturas públicas estabelecidas no `manifest.md` (compatibilidade de rotas, saídas CSV, regras de negócio e estrutura HTML), mantendo o ecossistema e as integrações externas em pleno funcionamento.

---

## 2. Achados e Correções (Priorizados)

### F1: Injeção de SQL (SQLi) na busca de chamados por título
- **Localização:** `code/lib.php` (linhas 81 a 85 na versão original).
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** 100%
- **Mecanismo:** Na função `listarChamados($db, $busca)`, a variável `$busca` recebida via parâmetro (que em `index.php` provém diretamente de `$_GET['busca']`) era interpolada diretamente na cláusula SQL via concatenação de strings: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`. A ausência de Prepared Statements ou sanitização permitia que entradas como `' OR 1=1 -- ` alterassem a sintaxe da consulta.
- **Impacto:** Permite a um atacante executar comandos SQL arbitrários, extrair dados confidenciais de todas as tabelas (incluindo usuários e senhas), burlar a autenticação ou alterar dados.
- **O que foi feito:** A função `listarChamados` foi refatorada para utilizar Prepared Statements (`$db->prepare`) e bind de parâmetros (`$stmt->bind_param('s', $param)`), parametrizando com segurança a busca por `LIKE ?`.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F2: Quebra de Controle de Acesso (IDOR e violação de visibilidade de chamados entre clientes)
- **Localização:** `code/index.php` (linhas 52 a 74 na versão original).
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** 100%
- **Mecanismo:** O sistema autenticava o usuário e armazenava seu identificador e papel na sessão (`$_SESSION['uid']` e `$_SESSION['papel']`), porém essas informações não eram utilizadas para restringir a visualização de dados. Tanto na listagem geral quanto no detalhe de um chamado (`index.php?ver=<id>`), o código exibia qualquer chamado da base para qualquer usuário logado. Um cliente conseguia visualizar chamados pertencentes a outros clientes simplesmente manipulando o parâmetro `?ver=<id>` ou acessando a tela principal.
- **Impacto:** Violação grave de privacidade e confidencialidade de dados (Insecure Direct Object References - IDOR). Clientes tinham acesso a informações sensíveis, histórico de suporte e dados cadastrais de outros clientes.
- **O que foi feito:** No fluxo de detalhe (`index.php?ver=`), adicionou-se a verificação de que, caso o usuário tenha papel `cliente`, seu `uid` deve corresponder ao `usuario_id` do chamado, emitindo mensagem de não encontrado caso contrário. Na listagem de chamados, incluiu-se o parâmetro opcional `$usuarioId` em `listarChamados` para que usuários com papel `cliente` visualizem exclusivamente os seus próprios chamados, preservando a visão irrestrita para usuários com papel `tecnico`.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F3: Cross-Site Scripting Refletido (XSS) no campo de formulário e resultado de busca
- **Localização:** `code/index.php` (linhas 79 a 83 na versão original).
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** O parâmetro `$busca = $_GET['busca'] ?? ''` era ecoado diretamente no HTML sem sanitização tanto no atributo `value` do formulário (`value="' . $busca . '"`) quanto na mensagem de texto de feedback (`<p>Resultados para: ' . $busca . '</p>`). A injeção de strings como `"><script>alert(1)</script>` provocava a interpretação imediata de tags HTML/JavaScript pelo navegador.
- **Impacto:** Execução de scripts JavaScript maliciosos no contexto do navegador da vítima, possibilitando roubo de cookies/sessões, redirecionamento para sites maliciosos e ações em nome do usuário autenticado.
- **O que foi feito:** O valor de `$busca` foi devidamente escapado utilizando `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` antes de qualquer interpolação no template HTML.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F4: Vulnerabilidade de Fixação de Sessão (Session Fixation) na autenticação
- **Localização:** `code/index.php` (linhas 22 a 28 na versão original).
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 95%
- **Mecanismo:** Ao realizar o login com sucesso, o script atribuía `$_SESSION['uid']` e `$_SESSION['papel']` mantendo o mesmo identificador de sessão PHP previamente estabelecido na conexão não autenticada, sem chamar `session_regenerate_id()`.
- **Impacto:** Um invasor capaz de fixar previamente um identificador de sessão no navegador da vítima (via injeção de cookie ou fixação de URL) poderia utilizar o mesmo ID para acessar o sistema imediatamente após o login legítimo da vítima.
- **O que foi feito:** Adicionou-se a instrução `session_regenerate_id(true)` imediatamente após a validação das credenciais na função `autenticar()`, invalidando o ID de sessão antigo e gerando um novo.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F5: Condição de corrida, corrupção de dados e falha de I/O em arquivo estático na exportação CSV
- **Localização:** `code/lib.php` (linhas 125 a 150 na versão original).
- **Categoria:** `bug` | **Severidade:** `alta` | **Confiança:** 95%
- **Mecanismo:** A função `exportarCsv` gravava o relatório em um arquivo com caminho estático fixo no disco (`EXPORT_DIR . '/chamados.csv'`) através de `fopen($caminho, 'w')`, e em seguida lia esse mesmo arquivo com `readfile($caminho)`. Caso dois usuários ou rotinas automatizadas solicitassem o CSV simultaneamente, ambas as requisições concorriam pela escrita no mesmo arquivo, gerando linhas corrompidas ou incompletas. Adicionalmente, se o diretório `/var/www/painel/tmp` não existisse ou apresentasse restrições de permissão de escrita, a exportação falhava silenciosamente.
- **Impacto:** Corrupção de relatórios exportados em cenários de concorrência e indisponibilidade da funcionalidade de exportação em ambientes onde o diretório temporário não possui permissões adequadas.
- **O que foi feito:** A função foi reestruturada para realizar o streaming direto do conteúdo CSV para a saída padrão (`php://output`) utilizando `fputcsv()`, enviando os cabeçalhos HTTP necessários quando não enviados previamente, eliminando a dependência de arquivos temporários em disco.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F6: Erro fatal por Divisão por Zero em `mediaResposta` com tabela vazia ou sem respostas
- **Localização:** `code/lib.php` (linhas 107 a 117 na versão original).
- **Categoria:** `bug` | **Severidade:** `media` | **Confiança:** 100%
- **Mecanismo:** O cálculo de média de tempo de primeira resposta acumulava `$soma` e incrementava `$qtd` em um laço `while`. Se a base não contivesse chamados ou se nenhum deles tivesse `minutos_resposta` preenchido, a variável `$qtd` permanecia `0`, resultando na operação `$soma / $qtd` (0 / 0). A partir do PHP 8.0, divisão por zero lança uma exceção `DivisionByZeroError` não tratada.
- **Impacto:** Erro HTTP 500 fatal e indisponibilidade completa do painel `index.php` sempre que o sistema estivesse em estado inicial sem atendimentos finalizados.
- **O que foi feito:** O cálculo foi substituído por agregação nativa no banco de dados com `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL`, retornando com segurança `0.0` caso a consulta retorne nulo ou vazia.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F7: Problema de desempenho N+1 Queries na listagem e na exportação CSV de chamados
- **Localização:** `code/lib.php` (linhas 88 a 91 e 136 a 145 na versão original).
- **Categoria:** `performance` | **Severidade:** `media` | **Confiança:** 100%
- **Mecanismo:** As funções `listarChamados` e `exportarCsv` realizavam a busca dos chamados e, dentro de seus respectivos laços `while`, executavam uma consulta individual para cada linha chamando `tecnicoNome($db, $c['tecnico_id'])` (`SELECT nome FROM usuarios WHERE id = ...`). Para uma listagem de $N$ chamados, eram disparadas $N + 1$ consultas ao banco.
- **Impacto:** Sobrecarga severa de rede e conexões ao banco de dados MySQL, elevando a latência de carregamento da página de forma linear conforme o volume de dados aumenta.
- **O que foi feito:** As consultas de listagem e de exportação foram unificadas utilizando `LEFT JOIN usuarios u ON c.tecnico_id = u.id` e `COALESCE(u.nome, '-') AS tecnico_nome`, reduzindo a complexidade de $N+1$ para 1 única query $O(1)$.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F8: Ausência de Prepared Statements e tratamento em `verChamado` e `tecnicoNome`
- **Localização:** `code/lib.php` (linhas 69 a 102 na versão original).
- **Categoria:** `qualidade` | **Severidade:** `baixa` | **Confiança:** 95%
- **Mecanismo:** As funções realizavam a montagem de queries via concatenação de inteiros e chamavam `$db->query()`, sem verificar se o ponteiro de resultado `$res` era válido antes de invocar `fetch_assoc()`.
- **Impacto:** Risco de falhas silenciosas e inconsistência com as melhores práticas de consultas parametrizadas do projeto.
- **O que foi feito:** Ambas as funções foram padronizadas com `mysqli::prepare` e bind de parâmetros inteiros (`bind_param('i', ...)`), com checagens de retorno defensivas.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F9: Chaves de API e senhas de fallback hardcoded no arquivo de configuração
- **Localização:** `code/config.php` (linhas 12 a 15 na versão original).
- **Categoria:** `seguranca` | **Severidade:** `media` | **Confiança:** 90%
- **Mecanismo:** O arquivo `config.php` continha a chave de API de e-mail transacional (`netx-smtp-9f83e2c1a7b64d05`) e a senha do banco de dados embutidas em texto plano.
- **Impacto:** Exposição de credenciais sensíveis em caso de vazamento do código-fonte ou controle de versões.
- **O que foi feito:** Configurou-se o suporte prioritário a variáveis de ambiente via `getenv('SMTP_API_KEY')` e tratamento correto para `DB_PASS`, mantendo o valor legado apenas como fallback de compatibilidade com ambientes que não configuram variáveis de ambiente.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F10: Agregação em memória ineficiente e consumo excessivo de memória em `mediaResposta`
- **Localização:** `code/lib.php` (linhas 107 a 115 na versão original).
- **Categoria:** `performance` | **Severidade:** `baixa` | **Confiança:** 95%
- **Mecanismo:** Carregamento de todos os registros da tabela `chamados` para a memória da aplicação PHP para cálculo de média aritmética através de iteração manual.
- **Impacto:** Desperdício de memória RAM no processo PHP e consumo de banda de rede entre a aplicação e o banco de dados.
- **O que foi feito:** Delegou-se o cálculo da média para o MySQL com `AVG(minutos_resposta)`.
- **Status:** Corrigido no código (`corrigido: true`).

---

### F11: Uso de algoritmo criptográfico obsoleto (MD5 sem salt) para armazenamento de senhas
- **Localização:** `code/lib.php` (linha 15 na versão original) e `code/schema.sql` (linha 7).
- **Categoria:** `arquitetura` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** As senhas dos usuários são geradas e validadas através de `md5($senha)` sem salt. O algoritmo MD5 é criptograficamente vulnerável a colisões e altamente suscetível a ataques rápidos de dicionário e rainbow tables.
- **Impacto:** Em caso de vazamento da tabela `usuarios`, as senhas podem ser revertidas com facilidade por atacantes.
- **O que foi feito / Por que não foi alterado no código entregue:** O hash MD5 foi mantido na função `autenticar()` para preservar compatibilidade estrita com a massa de dados do `seed.sql`, os dados dos usuários de teste definidos no manifesto e ferramentas legadas externas. Em uma evolução arquitetural futura, recomenda-se adotar `password_hash()` com bcrypt/Argon2id e migração transparente no próximo login do usuário.
- **Status:** Reportado como decisão arquitetural (`corrigido: false`).

---

### F12: Classificação frágil de status desconhecidos em `formatarStatus`
- **Localização:** `code/lib.php` (linhas 26 a 35 na versão original).
- **Categoria:** `qualidade` | **Severidade:** `baixa` | **Confiança:** 85%
- **Mecanismo:** A lógica encadeada `if ($status == 1) ... else if ($status == 2) ... else return 'Resolvido'` tratava tacitamente qualquer valor numérico diferente de 1 e 2 (como 0, 4 ou negativos) como "Resolvido".
- **Impacto:** Mascaramento de anomalias ou dados corrompidos de status na base de dados.
- **O que foi feito:** Reestruturação com `switch/case` mapeando explicitamente cada status previsto no contrato (`1 => 'Aberto'`, `2 => 'Em atendimento'`, `3 => 'Resolvido'`).
- **Status:** Corrigido no código (`corrigido: true`).

---

## 3. Decisões Arquiteturais e O Que Foi Mantido

Em conformidade com as restrições da tarefa e o contrato de superfície pública (`manifest.md`), foram tomadas as seguintes decisões deliberadas de engenharia:

1. **Manutenção do Hash MD5 na Autenticação (F11):**
   - **Decisão:** Não alterar o algoritmo de hash no banco de dados e na função `autenticar()`.
   - **Motivo:** O banco de dados e os dados de teste (`schema.sql` e `seed.sql`) utilizam MD5 para os usuários contratuais (`ana`/`senha123`, `bruno`/`senha123`, `carla`/`tecmaster`, `diego`/`tecmaster`). Alterar a validação sem um processo de migração assíncrono quebraria a autenticação dos usuários de teste e rotinas externas do ISP.

2. **Preservação da Camada de Acesso a Dados com `mysqli`:**
   - **Decisão:** Não migrar para PDO ou ORMs modernos.
   - **Motivo:** A restrição técnica estipula expressamente que a camada de acesso a dados do ISP é `mysqli` e todas as funções públicas recebem instâncias de `mysqli`. Qualquer alteração nessa assinatura constituiria uma violação de compatibilidade (`COMP-*`).

3. **Preservação das Assinaturas Públicas e Rotas GET:**
   - **Decisão:** Manter estritamente os nomes de funções, tipos e parâmetros das rotas públicas (`index.php`, `index.php?busca=<termo>`, `index.php?ver=<id>`, `index.php?export=csv`).
   - **Motivo:** Garantir a interoperabilidade com sistemas legados, relatórios noturnos e links externos já estabelecidos. A adição do parâmetro de usuário em `listarChamados` foi implementada de forma retrocompatível (com valor padrão `null`), permitindo que chamadores legados continuem invocando a função com sua assinatura original.

4. **Preservação do Formato de Exportação CSV e Estrutura HTML:**
   - **Decisão:** Manter a ordem exata de colunas do CSV (`ID,Titulo,Status,Tecnico,Aberto em`), a ordenação por `id ASC`, o identificador `id="tabela-chamados"` e a ordem das colunas da tabela HTML.
   - **Motivo:** Contrato rígido com relatórios gerenciais e rotinas de extração que processam a saída diretamente.
