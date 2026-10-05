# Relatório Técnico de Auditoria e Manutenção — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP) foi auditado quanto à segurança, integridade de regras de negócio, robustez operacional, desempenho e qualidade de código. 

A análise identificou vulnerabilidades críticas e de alta severidade, com destaque para Injeção de SQL na funcionalidade de busca (`code/lib.php`), Cross-Site Scripting (XSS) no formulário e saída de pesquisa (`code/index.php`), e Quebra de Controle de Acesso (IDOR / visualização indevida de chamados entre clientes em `code/index.php`). Foram também identificados e corrigidos bugs com risco de indisponibilidade (divisão por zero em `mediaResposta`), condições de corrida na exportação de relatórios CSV, gargalos de performance por consultas N+1 e deficiências no gerenciamento de sessões.

Todas as correções foram implementadas preservando rigorosamente o contrato público (`manifest.md`), mantendo as assinaturas de funções com `mysqli`, parâmetros de rotas, estrutura de HTML e formatos de exportação.

---

## 2. Achados e Correções (Ordenados por Prioridade)

### [F1] Injeção de SQL no parâmetro de busca de chamados
- **Localização:** `code/lib.php`, linhas originais 80–85.
- **Categoria:** `seguranca` | **Severidade:** `critica` | **Confiança:** 100%
- **Mecanismo:** A função `listarChamados()` recebia o parâmetro de busca `$busca` (oriundo de `$_GET['busca']`) e o concatenava diretamente na instrução SQL: `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`. Não havia sanitização, escape de caracteres especiais ou uso de consultas preparadas (*prepared statements*).
- **Impacto:** Um usuário autenticado poderia injetar comandos SQL arbitrários (por exemplo, payloads com `UNION SELECT`), permitindo a extração completa de tabelas sensíveis (como a tabela `usuarios`, expondo nomes e hashes de senhas) ou a manipulação de consultas no banco de dados.
- **Ação realizada:** A consulta foi refatorada para utilizar consultas preparadas (`$db->prepare` e `bind_param('s', $param)`), vinculando o valor de pesquisa de forma segura com os delimitadores de wildcard `%`.
- **Status:** Corrigido (`corrigido: true`).

---

### [F2] Vulnerabilidade de Cross-Site Scripting (XSS Refletido) na busca
- **Localização:** `code/index.php`, linhas originais 79–83.
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** A variável `$busca` era interpolada diretamente no HTML de saída no atributo `value` do formulário (`value="' . $busca . '"`) e no texto de resultado (`<p>Resultados para: ' . $busca . '</p>`), sem o devido escape através de `htmlspecialchars()`.
- **Impacto:** Um invasor poderia elaborar uma URL maliciosa contendo código JavaScript (ex.: `index.php?busca="><script>alert(1)</script>`). Ao induzir um usuário a clicar no link, o script seria executado no contexto do navegador da vítima, viabilizando o roubo de tokens, sequestro de sessão e ataques contra a interface.
- **Ação realizada:** O valor foi devidamente tratado com `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em todas as saídas no HTML.
- **Status:** Corrigido (`corrigido: true`).

---

### [F3] Controle de acesso quebrado (IDOR) e vazamento de chamados entre clientes
- **Localização:** `code/index.php`, linhas originais 52–73.
- **Categoria:** `seguranca` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** A regra de negócio estipulada no manifesto determina que clientes só podem visualizar chamados abertos por eles mesmos, enquanto técnicos podem visualizar todos. No código original, a listagem (`listarChamados`) retornava todos os chamados da base sem considerar o `usuario_id` da sessão, e a rota de detalhes (`index.php?ver=<id>`) não validava a titularidade do chamado (`usuario_id == $_SESSION['uid']`) para usuários com papel `cliente`.
- **Impacto:** Qualquer cliente autenticado conseguia listar todos os chamados de outros clientes na página principal e inspecionar detalhes confidenciais de chamados alheios manipulando o parâmetro `?ver=<id>`.
- **Ação realizada:** A listagem em `index.php` passou a filtrar por `usuario_id` quando o usuário logado possui papel `cliente`. Além disso, a rota de detalhe agora verifica se o chamado pertence ao cliente antes de exibi-lo, retornando mensagem de chamado não encontrado caso o acesso não seja autorizado.
- **Status:** Corrigido (`corrigido: true`).

---

### [F4] Divisão por zero e crash fatal em `mediaResposta` quando não há respostas de SLA
- **Localização:** `code/lib.php`, linhas originais 107–117.
- **Categoria:** `bug` | **Severidade:** `alta` | **Confiança:** 100%
- **Mecanismo:** A função `mediaResposta()` realizava a soma manual em PHP dos chamados com `minutos_resposta IS NOT NULL` e calculava a média através de `$soma / $qtd`. Caso o banco não possuísse registros respondidos (como em novas instalações ou quando todos os chamados estão aguardando primeira resposta), a variável `$qtd` permanecia `0`. No PHP 8+, a operação `$soma / 0` dispara uma exceção não tratada `DivisionByZeroError`.
- **Impacto:** Erro fatal (HTTP 500) e indisponibilidade total do painel de chamados (`index.php`) sempre que a base não contivesse chamados respondidos.
- **Ação realizada:** A lógica foi substituída pela função de agregação SQL nativa `AVG(minutos_resposta)`, com tratamento defensivo que retorna `0.0` se não houver registros ou se o valor for nulo.
- **Status:** Corrigido (`corrigido: true`).

---

### [F5] Gargalo de performance por consultas N+1 na listagem e exportação
- **Localização:** `code/lib.php`, linhas originais 88–91 e 136–138.
- **Categoria:** `performance` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** Nas funções `listarChamados()` e `exportarCsv()`, o código iterava sobre o conjunto de resultados chamando `tecnicoNome($db, $c['tecnico_id'])` para cada linha, executando uma consulta individual `SELECT nome FROM usuarios WHERE id = ...` a cada chamado (padrão N+1).
- **Impacto:** Degradação significativa de tempo de resposta e consumo excessivo de conexões de banco de dados proporcional ao número de chamados existentes.
- **Ação realizada:** As consultas de listagem e exportação foram otimizadas com `LEFT JOIN usuarios u ON c.tecnico_id = u.id`, trazendo a coluna `tecnico_nome` diretamente na consulta inicial em uma única requisição ao banco.
- **Status:** Corrigido (`corrigido: true`).

---

### [F6] Condição de corrida e dependência de arquivo em disco na exportação CSV
- **Localização:** `code/lib.php`, linhas originais 125–130.
- **Categoria:** `bug` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** A exportação gravava os dados em um arquivo fixo em disco (`/var/www/painel/tmp/chamados.csv`) antes de chamar `readfile()`. Múltiplos usuários exportando relatórios simultaneamente causavam colisão de escrita e leitura no mesmo arquivo; além disso, a operação falhava silenciosamente se o diretório local não existisse ou estivesse sem permissões de escrita.
- **Impacto:** Corrupção de relatórios exportados em cenários de concorrência e falha de download em ambientes de produção com sistemas de arquivos somente leitura ou sem o diretório `/var/www/painel/tmp`.
- **Ação realizada:** O fluxo foi reescrito para enviar os cabeçalhos HTTP apropriados e emitir os dados do CSV diretamente para a saída padrão com `fopen('php://output', 'w')`, eliminando I/O em disco e eliminando condições de corrida.
- **Status:** Corrigido (`corrigido: true`).

---

### [F7] Vulnerabilidade de fixação de sessão e falta de proteção de cookies
- **Localização:** `code/index.php`, linhas originais 15 e 23–28.
- **Categoria:** `seguranca` | **Severidade:** `media` | **Confiança:** 90%
- **Mecanismo:** Após a validação bem-sucedida das credenciais do usuário, o ID de sessão não era regenerado. Além disso, a sessão não ativava a flag de proteção `HttpOnly` para o cookie de sessão.
- **Impacto:** Possibilidade de ataques de *Session Fixation*, nos quais um invasor força um identificador de sessão prévio e assume a conta da vítima após sua autenticação.
- **Ação realizada:** Adicionada a diretiva `ini_set('session.cookie_httponly', '1')` e a chamada `session_regenerate_id(true)` imediatamente após a confirmação do login.
- **Status:** Corrigido (`corrigido: true`).

---

### [F8] Vazamento de detalhes de conexão devido a exceções não capturadas no PHP 8.1+
- **Localização:** `code/index.php`, linhas originais 9–12.
- **Categoria:** `qualidade` | **Severidade:** `baixa` | **Confiança:** 90%
- **Mecanismo:** No PHP 8.1+, o driver `mysqli` tem como padrão o modo estrito de erros (`MYSQLI_REPORT_STRICT`), lançando `mysqli_sql_exception` em falhas de conexão. O código tratava apenas `$db->connect_errno`, de modo que erros de conexão resultavam em exceções não capturadas expondo caminhos e nomes de usuário no stack trace.
- **Impacto:** Divulgação de dados técnicos de infraestrutura caso o banco de dados esteja indisponível.
- **Ação realizada:** A conexão foi envolvida em um bloco `try...catch (mysqli_sql_exception $e)` que interrompe a execução com mensagem genérica e segura.
- **Status:** Corrigido (`corrigido: true`).

---

### [F9] Segredos e chaves de API hardcoded no arquivo de configuração
- **Localização:** `code/config.php`, linhas originais 12–18.
- **Categoria:** `seguranca` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** As constantes `SMTP_API_KEY` e `EXPORT_DIR` continham valores fixos em código, sem suporte a override via variáveis de ambiente, além de senha padrão de banco exposta no código.
- **Impacto:** Exposição de credenciais caso o código seja versionado ou distribuído em repositórios abertos.
- **Ação realizada:** Atualizadas as definições para priorizar variáveis de ambiente (`getenv('SMTP_API_KEY')`, `getenv('EXPORT_DIR')`), mantendo os valores legados como fallback de compatibilidade.
- **Status:** Corrigido (`corrigido: true`).

---

### [F10] Armazenamento e validação de senhas com algoritmo MD5 sem salt
- **Localização:** `code/lib.php`, linhas originais 15–18.
- **Categoria:** `arquitetura` | **Severidade:** `media` | **Confiança:** 95%
- **Mecanismo:** O sistema calcula `md5($senha)` e compara com a coluna `senha CHAR(32)` na tabela `usuarios`. O algoritmo MD5 é criptograficamente fraco, obsoleto e vulnerável a colisões e tabelas pré-computadas (*rainbow tables*).
- **Impacto:** Em caso de vazamento da tabela `usuarios`, senhas de clientes e técnicos podem ser revertidas com facilidade.
- **Ação realizada:** Registrado como débito técnico arquitetural. A substituição do algoritmo por `password_hash` (bcrypt/Argon2) não foi aplicada imediatamente para não quebrar a autenticação dos usuários existentes na base legada (`seed.sql`).
- **Status:** Reportado / Não alterado (`corrigido: false`).

---

### [F11] Mapeamento genérico e permissivo de status em `formatarStatus`
- **Localização:** `code/lib.php`, linhas originais 26–35.
- **Categoria:** `qualidade` | **Severidade:** `baixa` | **Confiança:** 90%
- **Mecanismo:** A função `formatarStatus` tratava os códigos 1 e 2, caindo em um `else` incondicional que retornava `'Resolvido'` para qualquer outro valor inteiro (incluindo 0, 4 ou números negativos).
- **Impacto:** Chamados com status inválidos ou corrompidos eram exibidos e exportados incorretamente como 'Resolvido'.
- **Ação realizada:** Refatorada a função utilizando a expressão `match`, tratando explicitamente 1 (`Aberto`), 2 (`Em atendimento`), 3 (`Resolvido`) e retornando `'Desconhecido'` para valores fora do domínio esperado.
- **Status:** Corrigido (`corrigido: true`).

---

## 3. Decisões e Justificativas (O que NÃO foi alterado)

1. **Camada de acesso a dados (MySQLi mantida em vez de migração para PDO/ORM):**
   - *Motivo:* O manifesto de superfície pública (`manifest.md`) define formalmente que as funções públicas (`autenticar`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`) recebem como primeiro parâmetro uma instância de `mysqli $db`. A restrição §6.2 da tarefa proíbe terminantemente trocar a stack ou a camada de acesso a dados para não violar contratos de scripts externos do ISP.

2. **Migração forçada e imediata de hashes de senhas MD5 no banco de dados:**
   - *Motivo:* Os dados pré-existentes no banco (`schema.sql` com coluna `CHAR(32)` e `seed.sql`) dependem do formato MD5. Alterar a função `autenticar` unilateralmente impediria o login de todos os usuários do ISP (`ana`, `bruno`, `carla`, `diego`). Em um ciclo evolutivo de produção, essa mudança exige um processo de migração gradual com rehash no login e ampliação da coluna para `VARCHAR(255)`.

3. **Reescrita visual ou estrutural do HTML e rotas:**
   - *Motivo:* Conforme a regra §6.4 ("Não reescreva o sistema. Evolua-o"), preservamos as rotas (`?busca=`, `?ver=`, `?export=csv`), o identificador da tabela (`id="tabela-chamados"`), a ordem exata das colunas (`ID`, `Titulo`, `Status`, `Prioridade`, `Tecnico`) e o layout básico, garantindo total compatibilidade com scrapers, relatórios automatizados e testes integrados.
