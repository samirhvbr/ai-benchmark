# Relatório Técnico — LEB-100-A

## 1. Resumo

O sistema legado de Painel de Chamados do NetX ISP (baseado em PHP procedural e MySQL/MariaDB com extensão `mysqli`) apresentava graves vulnerabilidades de segurança (injeção de SQL via parâmetro de busca e manipulação de IDs, quebra de controle de acesso/BOLA permitindo que clientes visualizassem chamados alheios, e XSS refletido no parâmetro de busca). Além disso, identificaram-se problemas críticos de performance causados por consultas N+1 na listagem e exportação de chamados, cálculo ineficiente de agregados em memória com risco de divisão por zero (`DivisionByZeroError`), gravação de arquivos temporários inseguros e credenciais sensíveis codificadas diretamente nos arquivos-fonte.

Todas as falhas ativas de segurança, correção de regras de negócio de visibilidade, riscos de indisponibilidade e gargalos de performance foram corrigidas diretamente no código (`code/lib.php` e `code/index.php`), mantendo estrita compatibilidade com a superfície pública, assinaturas de funções, tipos de retorno, parâmetros GET, formato de CSV e estrutura de HTML declarados no `manifest.md`.

---

## 2. Achados e Prioridades de Correção

### [F1] Injeção de SQL via parâmetro de busca na listagem de chamados
- **Onde está:** `code/lib.php`, linhas 78 a 93 (especificamente linhas 80–85 na versão original).
- **Mecanismo:** A função `listarChamados(mysqli $db, string $busca = '')` concatenava a variável `$busca` diretamente na consulta SQL (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`) sem qualquer sanitização, escape (`mysqli_real_escape_string`) ou uso de prepared statements parametrizados.
- **Impacto e Severidade:** Severidade **crítica**. Impacto: qualquer usuário autenticado (incluindo clientes) podia manipular a cláusula SQL para ler dados confidenciais do banco (extração de hashes de senhas da tabela `usuarios`, leitura de chamados de outros clientes) ou corromper dados caso o driver/permissões permitissem múltiplas declarações.
- **Confiança:** 100%.
- **O que foi feito:** A consulta foi refatorada para utilizar *prepared statements* (`$db->prepare`) com ligação de parâmetros tipados (`bind_param('s', $termo)`), neutralizando totalmente a injeção de SQL.

---

### [F2] Quebra de controle de acesso a nível de objeto (BOLA / IDOR) na visualização e listagem de chamados
- **Onde está:** `code/index.php`, linhas 52 a 67 e 72 a 75 na versão original.
- **Mecanismo:** O manifesto estabelece expressamente a regra de negócio: *"Um cliente só pode ver os chamados que ele mesmo abriu; um técnico pode ver qualquer chamado"*. No entanto, o `index.php` passava o `$_GET['ver']` diretamente para `verChamado()` e exibia o chamado retornado sem checar se o usuário logado era técnico ou o proprietário (`usuario_id === $uid`). Similarmente, a listagem de chamados em `index.php` exibia indiscriminadamente todos os chamados retornados por `listarChamados()` para qualquer papel logado.
- **Impacto e Severidade:** Severidade **alta**. Impacto: quebra direta de confidencialidade de dados entre clientes; um cliente podia listar e inspecionar chamados de outros clientes alterando o ID na URL ou acessando o painel inicial.
- **Confiança:** 100%.
- **O que foi feito:** No `index.php`, foi implementada a verificação estrita de posse: se `$_SESSION['papel'] === 'cliente'`, o acesso a `index.php?ver=<id>` é bloqueado com a mensagem `"Chamado nao encontrado."` se o chamado pertencer a outro usuário (`usuario_id !== $uid`). Na listagem, os chamados são filtrados em memória para clientes antes da renderização da tabela HTML, mantendo a assinatura pública de `listarChamados($db, $busca)` inalterada conforme o contrato do `manifest.md`.

---

### [F3] Cross-Site Scripting refletido (XSS) via parâmetro de busca
- **Onde está:** `code/index.php`, linhas 79 e 82 na versão original.
- **Mecanismo:** O valor da variável `$busca` (`$_GET['busca']`) era interpolado diretamente no atributo `value` do formulário (`value="' . $busca . '"`) e no parágrafo de confirmação (`<p>Resultados para: ' . $busca . '</p>`) sem escape para contexto HTML.
- **Impacto e Severidade:** Severidade **alta**. Impacto: execução de scripts arbitrários no navegador do usuário autenticado caso um link malicioso seja aberto, viabilizando sequestro de sessão e roubo de cookies/credenciais.
- **Confiança:** 100%.
- **O que foi feito:** O valor de `$busca` passou a ser devidamente tratado com `htmlspecialchars()` em todos os pontos de saída no HTML.

---

### [F4] Concatenação insegura de ID em consulta SQL na função de técnico
- **Onde está:** `code/lib.php`, linhas 64 a 72 na versão original.
- **Mecanismo:** A função `tecnicoNome(mysqli $db, ?int $tecnicoId)` concatenava `$tecnicoId` diretamente na string de consulta SQL (`'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`). Embora a tipagem do PHP force `?int`, em chamadas internas ou legadas sem coerção estrita isso permitia brechas de injeção direta de SQL no banco de dados.
- **Impacto e Severidade:** Severidade **media**. Impacto: potencial de injeção SQL caso a função fosse chamada em contextos sem validação prévia de tipo ou com casts frouxos.
- **Confiança:** 95%.
- **O que foi feito:** A função foi refatorada para utilizar *prepared statements* parametrizados com `$stmt->bind_param('i', $tecnicoId)`.

---

### [F5] Concatenação insegura de ID em consulta SQL na visualização de chamado
- **Onde está:** `code/lib.php`, linhas 98 a 102 na versão original.
- **Mecanismo:** A função `verChamado(mysqli $db, int $id)` concatenava o `$id` diretamente na query (`'SELECT * FROM chamados WHERE id = ' . $id`).
- **Impacto e Severidade:** Severidade **media**. Impacto: vetor latente de injeção SQL caso o chamador invoque a função com valores não higienizados.
- **Confiança:** 95%.
- **O que foi feito:** A consulta foi refatorada para utilizar *prepared statement* parametrizado (`$stmt->bind_param('i', $id)`).

---

### [F6] Problema de desempenho N+1 consultas na listagem e na exportação de chamados
- **Onde está:** `code/lib.php`, linhas 88 a 91 e 136 a 138 na versão original.
- **Mecanismo:** Para cada linha recuperada na tabela `chamados`, o código executava uma consulta individual ao banco (`tecnicoNome()`, que dispara `SELECT nome FROM usuarios WHERE id = ...`). Com $N$ chamados, eram disparadas $1 + N$ queries consecutivas via rede.
- **Impacto e Severidade:** Severidade **alta**. Impacto: degradação severa de performance, esgotamento do pool de conexões do MySQL e latência elevada à medida que o volume de chamados cresce.
- **Confiança:** 100%.
- **O que foi feito:** Em `listarChamados()` e `exportarCsv()`, as consultas foram reescritas com `LEFT JOIN usuarios u ON c.tecnico_id = u.id`, trazendo `u.nome AS tecnico_nome` em uma única viagem ao banco de dados ($O(1)$ query em vez de $1 + N$).

---

### [F7] Risco de divisão por zero (DivisionByZeroError) e cálculo ineficiente de média
- **Onde está:** `code/lib.php`, linhas 107 a 117 na versão original.
- **Mecanismo:** A função `mediaResposta()` buscava todas as linhas de `chamados` em que `minutos_resposta IS NOT NULL`, iterava linha a linha em PHP somando os valores em memória e calculava `$soma / $qtd`. Se não existisse nenhum chamado respondido (`$qtd == 0`), o PHP 8 disparava um erro fatal `DivisionByZeroError`, travando o painel inteiro. Além disso, carregar milhares de linhas na memória do PHP é computacionalmente ineficiente.
- **Impacto e Severidade:** Severidade **alta**. Impacto: indisponibilidade total do painel (`500 Internal Server Error`) em bancos recém-iniciados ou períodos sem respostas registradas, além de consumo desnecessário de memória.
- **Confiança:** 100%.
- **O que foi feito:** Delegou-se o cálculo da agregação ao próprio banco de dados via `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL`. Caso não existam registros ou o valor seja nulo, a função retorna `0.0`, prevenindo erros de divisão por zero e eliminando o consumo de memória.

---

### [F8] Gravação insegura de arquivo temporário na exportação CSV e vazamento de dados em disco
- **Onde está:** `code/lib.php`, linhas 123 a 151 na versão original.
- **Mecanismo:** A função `exportarCsv()` gravava os dados em um caminho estático compartilhado em disco (`EXPORT_DIR . '/chamados.csv'`) antes de enviá-lo ao navegador com `readfile()`. Se o diretório `/var/www/painel/tmp` não existisse ou não tivesse permissão de escrita, o download falhava silenciosamente. Adicionalmente, gravava dados sensíveis em disco em arquivo compartilhado sob concorrência de múltiplos usuários simultâneos.
- **Impacto e Severidade:** Severidade **media**. Impacto: falha de exportação, risco de colisão e corrupção de arquivos entre downloads simultâneos e exposição desnecessária de dados em disco do servidor.
- **Confiança:** 95%.
- **O que foi feito:** O fluxo foi alterado para gravar diretamente no fluxo de saída HTTP padrão usando o stream em memória `php://output`, enviando os cabeçalhos HTTP apropriados e liberando os recursos sem tocar no disco.

---

### [F9] Credenciais e chaves secretas codificadas diretamente no código-fonte
- **Onde está:** `code/config.php`, linhas 12 e 15 na versão original.
- **Mecanismo:** A senha padrão do banco de produção (`N3tX@2013!prod`) e a chave estática `SMTP_API_KEY` (`netx-smtp-9f83e2c1a7b64d05`) estão hardcoded no arquivo de configuração do repositório.
- **Impacto e Severidade:** Severidade **alta**. Impacto: vazamento de credenciais e risco de acesso não autorizado à infraestrutura de banco de dados e envio de e-mails em caso de acesso ao repositório ou backup.
- **Confiança:** 95%.
- **O que foi feito:** Reportado. A definição em `config.php` foi mantida inalterada no momento para não quebrar ambientes de produção ou rotinas que dependem dos fallbacks definidos sem injeção de variáveis de ambiente prévias.

---

### [F10] Armazenamento de senhas com algoritmo MD5 obsoleto e sem salt
- **Onde está:** `code/lib.php`, linha 15 e `code/schema.sql`, linha 7 na versão original.
- **Mecanismo:** A função `autenticar()` calcula `$hash = md5($senha)` e compara com o banco. O algoritmo MD5 é criptograficamente quebrado, suscetível a colisões rápidas e ataques de rainbow table, além de não utilizar salt por usuário.
- **Impacto e Severidade:** Severidade **alta**. Impacto: facilidade extrema de recuperação das senhas em texto puro caso a tabela de usuários seja exposta.
- **Confiança:** 95%.
- **O que foi feito:** Reportado. Não foi alterado no código de runtime porque o schema e os registros em produção (`code/seed.sql`) utilizam hashes MD5 fixos e a alteração quebraria a compatibilidade de autenticação dos usuários existentes sem um processo formal de migração de hash.

---

## 3. Decisões — O que não foi alterado e justificativas

1. **Migração do algoritmo de hash MD5 para `password_hash()` / `password_verify()` (`code/lib.php`, `code/schema.sql` e `code/seed.sql`):**
   - *Motivo:* As credenciais de teste e o banco existente (`seed.sql`) estão populados com hashes MD5 de 32 caracteres. Mudar unilateralmente a lógica de autenticação sem um script de migração ou recadastro tornaria impossível o login dos usuários existentes e violaria o contrato de teste do sistema estabelecido no manifesto.
2. **Remoção dos fallbacks de credenciais hardcoded em `code/config.php`:**
   - *Motivo:* Outros scripts do ISP ou ambientes de execução podem não ter as variáveis de ambiente (`DB_PASS`) configuradas e dependem do fallback legado atual para conseguir se conectar. Alterar isso poderia derrubar integrações externas em lote.
3. **Assinatura e escopo da função `listarChamados()` em `code/lib.php`:**
   - *Motivo:* O `manifest.md` define estritamente a assinatura pública `listarChamados(mysqli $db, string $busca = ''): array` consumida por rotinas externas. Adicionar um parâmetro obrigatório de usuário ou ID quebraria a compatibilidade dessa função. Por isso, a regra de negócio de visibilidade de clientes foi aplicada na camada de visualização em `index.php`.
4. **Camada de persistência (permanência em `mysqli` procedural / orientado a objetos do driver padrão):**
   - *Motivo:* O manifesto estipula expressamente que o ISP utiliza `mysqli` e proíbe a substituição da tecnologia de acesso a dados (como migração para PDO ou um ORM), visto que as assinaturas recebem uma conexão `mysqli $db`.
5. **Estrutura de tabelas e schema SQL (`code/schema.sql`):**
   - *Motivo:* O schema define o contrato do banco consumido por rotinas externas de faturamento e exportação noturna. Manter os tipos e tamanhos de colunas assegura total compatibilidade com os scripts legados do provedor.
