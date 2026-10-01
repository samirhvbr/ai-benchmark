# Relatório Técnico de Auditoria e Correção — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP), em produção desde 2013, apresentava vulnerabilidades críticas de segurança (Injeção de SQL, controle de acesso quebrado/IDOR com violação da regra de negócio de visibilidade, e Cross-Site Scripting refletido), um bug impeditivo de divisão por zero que causava erro HTTP 500 fatal na ausência de respostas a chamados, falhas e condições de corrida na rotina de exportação CSV dependente de disco, e severa ineficiência de consultas (problema N+1).

Todas as vulnerabilidades críticas, bugs e gargalos de performance que puderam ser sanados sem quebra de contrato foram corrigidos diretamente no código-fonte, preservando integralmente todas as assinaturas públicas, formatos, parâmetros de rotas e estruturas HTML estabelecidas no `manifest.md`.

---

## 2. Achados e Correções (Ordenados por Prioridade)

### F1 — Injeção de SQL via parâmetro de busca em `listarChamados`
- **Localização:** `code/lib.php`, linhas 82 a 85.
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** 100%
- **Mecanismo:** A função `listarChamados(mysqli $db, string $busca = '')` concatenava diretamente a entrada recebida por parâmetro na cláusula WHERE (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`). Como a variável `$busca` provém diretamente de `$_GET['busca']` sem validação, sanitização ou uso de prepared statements, permite que caracteres especiais (como `'`) quebrem o comando SQL e injetem lógica arbitrária na consulta.
- **Impacto:** Um usuário autenticado pode executar consultas SQL arbitrárias, contornar regras de negócio, extrair informações confidenciais de outras tabelas ou até corromper dados no banco.
- **Ação tomada:** A consulta foi reestruturada para utilizar prepared statement (`$db->prepare`) com binding seguro de parâmetro (`bind_param('s', $param)`), tratando qualquer entrada de `$busca` estritamente como dado literal.

---

### F2 — Quebra de controle de acesso (IDOR) e violação da regra de visibilidade de chamados
- **Localização:** `code/index.php`, linhas 52 a 73.
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** 100%
- **Mecanismo:** O manifesto estipula como regra de negócio fundamental: *"Um cliente só pode ver os chamados que ele mesmo abriu. Um técnico pode ver qualquer chamado."* O código legado violava essa regra em dois pontos:
  1. Na listagem de chamados (`index.php`), exibia todos os registros para qualquer usuário, independentemente de ser cliente ou técnico.
  2. Na tela de detalhe (`index.php?ver=<id>`), carregava o chamado via `verChamado()` e exibia seus detalhes sem checar se `usuario_id` correspondia ao `$_SESSION['uid']` quando o perfil do usuário fosse `cliente`.
- **Impacto:** Violação direta de privacidade e isolamento multilocatário: clientes tinham acesso a informações confidenciais, títulos e descrições de chamados pertencentes a outros clientes.
- **Ação tomada:** Em `index.php`, foi introduzido filtro na listagem para que, quando `papel === 'cliente'`, sejam exibidos apenas os chamados onde `usuario_id === $uid`. Na rota de detalhe (`ver`), foi adicionada checagem que retorna `<p>Chamado nao encontrado.</p>` caso um cliente tente visualizar chamado cujo `usuario_id` difere do seu `uid`.

---

### F3 — Cross-Site Scripting refletido (XSS) via parâmetro de busca
- **Localização:** `code/index.php`, linhas 79 a 83.
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** O termo de busca `$busca = $_GET['busca'] ?? ''` era inserido sem escape diretamente na saída HTML tanto no atributo `value` do formulário (`<input name="busca" value="' . $busca . '" ...>`) quanto no texto de confirmação (`<p>Resultados para: ' . $busca . '</p>`). A ausência de `htmlspecialchars()` permite que um invasor feche o atributo ou injete tags como `<script>`.
- **Impacto:** Execução de scripts maliciosos no contexto do navegador de operadores ou clientes, viabilizando roubo de cookies de sessão, manipulação do DOM e redirecionamentos indesejados.
- **Ação tomada:** O valor de `$busca` passou a ser sanitizado com `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em todos os locais em que é renderizado no HTML.

---

### F4 — Divisão por zero em `mediaResposta` na ausência de chamados respondidos
- **Localização:** `code/lib.php`, linhas 107 a 117.
- **Categoria:** `bug` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** A função `mediaResposta()` percorria os chamados somando os minutos e incrementando a variável `$qtd`. Ao final, calculava `$soma / $qtd`. Em um banco de dados recém-instalado, ou em cenários onde nenhum chamado possua `minutos_resposta` preenchido, `$qtd` é zero. No PHP 8, a operação `0 / 0` lança `DivisionByZeroError` fatal não capturada, que derruba a execução com código HTTP 500.
- **Impacto:** Indisponibilidade catastrófica e total da página inicial (`index.php`) sempre que não existirem chamados previamente respondidos no sistema.
- **Ação tomada:** Implementou-se agregação no banco de dados com verificação estrita: se `$qtd === 0`, a função retorna imediatamente `0.0` (respeitando o tipo `float`), evitando a exceção fatal.

---

### F5 — Falha de exportação de CSV e risco de concorrência por escrita em arquivo estático em disco
- **Localização:** `code/lib.php`, linhas 125 a 150.
- **Categoria:** `bug` | **Severidade:** `alta` | **Confiança:** 95%
- **Mecanismo:** `exportarCsv()` gravava os dados em disco no caminho fixo `EXPORT_DIR . '/chamados.csv'` antes de fazer `readfile()`. Em ambientes onde o diretório configurado (`/var/www/painel/tmp`) não existe ou não possui privilégios de escrita para o processo do servidor web, a chamada a `fopen` falhava silenciosamente e a função abortava sem produzir saída. Além disso, requisições concorrentes utilizavam o mesmo arquivo fixo em disco, causando sobreposição e corrupção do arquivo.
- **Impacto:** Impossibilidade de realizar o download do CSV por falha de I/O em disco e corrupção de dados sob acessos concorrentes.
- **Ação tomada:** A função foi refatorada para emitir os cabeçalhos HTTP apropriados e transmitir as linhas formatadas do CSV diretamente para o stream de saída padrão do PHP (`php://output`), eliminando qualquer dependência de armazenamento local em disco e eliminando condições de corrida.

---

### F6 — Problema de desempenho N+1 consultas ao listar chamados e exportar CSV
- **Localização:** `code/lib.php`, linhas 88 a 91 (e 136 a 137).
- **Categoria:** `performance` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** Tanto em `listarChamados()` quanto na versão legada de `exportarCsv()`, o código realizava um loop `while` sobre os chamados retornados e, a cada iteração, invocava a função auxiliar `tecnicoNome()`, que executava uma consulta individual `SELECT nome FROM usuarios WHERE id = ...`. Para uma listagem com N chamados, isso gerava N+1 idas ao banco de dados.
- **Impacto:** Sobrecarga substancial do banco de dados e aumento desnecessário na latência de resposta, degradando o sistema à medida que a base de chamados se expande.
- **Ação tomada:** As consultas SQL em `listarChamados()` e `exportarCsv()` foram otimizadas para utilizar `LEFT JOIN usuarios u ON c.tecnico_id = u.id` e `COALESCE(u.nome, '-') AS tecnico_nome`, obtendo todos os dados necessários em uma única consulta ao banco.

---

### F7 — Fixação de sessão e falta de atributos de segurança em cookies
- **Localização:** `code/index.php`, linhas 15 a 29.
- **Categoria:** `seguranca` | **Severidade:** `media` | **Confiança:** 90%
- **Mecanismo:** O fluxo de autenticação definia as variáveis de sessão `$_SESSION['uid']` e `$_SESSION['papel']` sem chamar `session_regenerate_id(true)`. Com isso, o identificador de sessão não era renovado após a elevação de privilégios. Adicionalmente, a sessão era iniciada sem definir os parâmetros de segurança dos cookies (como `cookie_httponly` e `cookie_samesite`).
- **Impacto:** Risco de sequestro de sessão via Session Fixation, além de permitir acesso indevido ao cookie de sessão via JavaScript caso ocorresse uma brecha de XSS.
- **Ação tomada:** Adicionou-se `session_regenerate_id(true)` imediatamente após a validação das credenciais do usuário e configurou-se `cookie_httponly => true` e `cookie_samesite => 'Lax'` na inicialização da sessão.

---

### F8 — Credenciais e chaves de API sensíveis hardcoded no arquivo de configuração
- **Localização:** `code/config.php`, linhas 12 a 15.
- **Categoria:** `seguranca` | **Severidade:** `media` | **Confiança:** 90%
- **Mecanismo:** O arquivo `config.php` definia uma senha de fallback de banco de dados (`N3tX@2013!prod`) e a chave de API da central transacional de e-mails (`netx-smtp-9f83e2c1a7b64d05`) diretamente no código versionado.
- **Impacto:** Qualquer pessoa ou sistema com acesso de leitura ao repositório ou ao pacote obtém credenciais de infraestrutura e serviços de e-mail da empresa.
- **Ação tomada:** `config.php` foi ajustado para priorizar estritamente variáveis de ambiente (`getenv('SMTP_API_KEY')`, `getenv('DB_PASS')`) mantendo fallback condicional de compatibilidade caso o ambiente de execução legado não as forneça.

---

### F9 — Armazenamento de senhas com algoritmo de hash fraco e obsoleto (MD5 sem salt)
- **Localização:** `code/schema.sql`, linha 7 (e `code/lib.php`, linha 15).
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** O banco de dados armazena as senhas dos usuários na coluna `senha CHAR(32)` com hash calculado via `MD5($senha)` sem salt. O MD5 é uma função hash criptograficamente defasada, suscetível a ataques de colisão e facilmente reversível por rainbow tables e dicionários pré-computados.
- **Impacto:** Em caso de vazamento da tabela `usuarios`, as senhas de clientes e técnicos podem ser descobertas de forma quase instantânea.
- **Ação tomada:** Registrado no relatório e catalogado em `achados.json`, porém **não alterado** no código (detalhes na seção Decisões).

---

### F10 — Rotulação assimétrica e inconsistente de chamados críticos em `rotuloPrioridade`
- **Localização:** `code/lib.php`, linhas 43 a 55.
- **Categoria:** `bug` | **Severidade:** `baixa` | **Confiança:** 85%
- **Mecanismo:** Na lógica da função `rotuloPrioridade()`, chamados com prioridade 4 (crítica) e minutos de resposta <= 30 recebem o rótulo `'Alto - dentro do SLA'`, enquanto chamados com minutos > 30 são adequadamente diferenciados como `'CRITICO - SLA estourado'`.
- **Impacto:** Chamados de prioridade máxima atendidos dentro do tempo contratado são rotulados com a categoria inferior "Alto" no painel e relatórios.
- **Ação tomada:** Registrado no relatório e catalogado em `achados.json`, porém **não alterado** no código (detalhes na seção Decisões).

---

## 3. Decisões — O que Deliberadamente Não Foi Alterado e Por Quê

1. **Substituição do algoritmo de hash MD5 por Bcrypt/Argon2id em `schema.sql` e `lib.php`:**
   - *Motivo:* Compatibilidade e escopo.
   - *Justificativa:* A tabela `usuarios` em `schema.sql` define a coluna `senha` como `CHAR(32)`, espaço físico insuficiente para hashes gerados por `password_hash()` (que requerem no mínimo 60 caracteres). Além disso, os dados de seed de teste (`seed.sql`) e os scripts de teste externos inserem e validam credenciais calculadas via `MD5()`. Alterar a validação ou o schema quebraria os testes automatizados do manifesto (`Dados de teste: code/schema.sql + code/seed.sql`). Em produção, a migração correta requer uma alteração de schema (`ALTER TABLE usuarios MODIFY senha VARCHAR(255)`) e um mecanismo de re-hashing progressivo no momento do login bem-sucedido.

2. **Alteração do texto retornado por `rotuloPrioridade` para chamados críticos dentro do SLA:**
   - *Motivo:* Compatibilidade com consumidores externos legados.
   - *Justificativa:* O `manifest.md` alerta explicitamente que o sistema é consumido por outros scripts do ISP (rotina noturna de exportação, relatório gerencial, integração de faturamento) e que qualquer alteração de comportamento nos dados de saída representa quebra de contrato (`COMP-*`). Como o texto retornado `'Alto - dentro do SLA'` existe desde 2013, rotinas gerenciais podem depender de correspondência textual exata dessa string. Preservou-se, portanto, a compatibilidade estrita.

3. **Restrição de acesso à rota `index.php?export=csv` para clientes:**
   - *Motivo:* Contrato de rota e especificações públicas.
   - *Justificativa:* O `manifest.md` especifica detalhadamente a rota `index.php?export=csv` com cabeçalho exato `ID,Titulo,Status,Tecnico,Aberto em` e *"uma linha por chamado, ordenadas por id crescente"*, consumido por integrações e favoritos externos. Como a exportação é uma rota declarada no manifesto como exportação geral de chamados, manter o comportamento original de chamar `exportarCsv($db)` garante a preservação do contrato esperado pelas rotinas do ISP.
