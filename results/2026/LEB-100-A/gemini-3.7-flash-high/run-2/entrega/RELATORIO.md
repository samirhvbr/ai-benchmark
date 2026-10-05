# Relatório Técnico de Auditoria e Manutenção — Painel de Chamados (LEB-100-A)

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP) foi analisado quanto a segurança, bugs, performance, arquitetura e qualidade de código. O sistema apresentava vulnerabilidades críticas de segurança (injeção de SQL na busca, falhas de controle de acesso permitindo que clientes visualizassem e listassem chamados de terceiros, Cross-Site Scripting refletido e vulnerabilidade a fixação de sessão), além de uma falha de interrupção de serviço por divisão por zero no cálculo de SLA com PHP 8, problemas de N+1 queries na listagem/exportação e condições de corrida na geração de CSV em arquivo fixo compartilhado.

As correções necessárias foram implementadas preservando integralmente o contrato público estabelecido no `manifest.md` (assinaturas de funções, rotas GET, regras de negócio de visibilidade, estrutura HTML declarada e formato de saída CSV), sem introduzir dependências externas ou alterar a camada de acesso a dados `mysqli`.

---

## 2. Achados e Correções

### [F1] Injeção de SQL (SQLi) na busca de chamados por título
- **Localização:** `code/lib.php` (linhas 80–85)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100%
- **Mecanismo:** A função `listarChamados` construía a query concatenando diretamente o parâmetro `$busca` em `$sql .= " WHERE titulo LIKE '%" . $busca . "%'"`. Como `$busca` é passado diretamente a partir de `$_GET['busca']` em `index.php`, caracteres de escape SQL (como aspas simples `'`) permitiam que um usuário malicioso injetasse comandos SQL arbitrários (como `UNION SELECT` para obter dados da tabela `usuarios`).
- **Impacto:** Comprometimento total da confidencialidade e integridade da base de dados, permitindo extração de credenciais de clientes e técnicos ou bypass de restrições de busca.
- **Ação realizada:** Refatoração da função `listarChamados` para utilizar Prepared Statements (`$db->prepare` com `bind_param('s', $termo)`), parametrizando a busca com curingas `%` de maneira segura.
- **Status:** Corrigido (`corrigido: true`).

---

### [F2] Quebra de controle de acesso (IDOR) na visualização de detalhes do chamado
- **Localização:** `code/index.php` (linhas 52–58)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100%
- **Mecanismo:** Na rota `index.php?ver=<id>`, o controlador executava `verChamado($db, (int)$_GET['ver'])` e exibia o chamado retornado sem validar a relação de propriedade entre o chamado carregado e o usuário autenticado. O `manifest.md` estipula que *"um cliente só pode ver os chamados que ele mesmo abriu"*. Sem a validação de `$_SESSION['papel']` e `usuario_id`, qualquer cliente conseguia inspecionar chamados de outros clientes alterando o ID na URL.
- **Impacto:** Violação de privacidade e vazamento de informações sigilosas de clientes cadastrados no ISP.
- **Ação realizada:** Adicionada verificação de autorização em `index.php`: caso o usuário possua papel de `cliente` e o campo `usuario_id` do chamado não coincida com seu `uid` de sessão, a página exibe a mensagem padrão de não encontrado (`<p>Chamado nao encontrado.</p>`) e encerra o fluxo.
- **Status:** Corrigido (`corrigido: true`).

---

### [F3] Exposição indevida de todos os chamados na listagem para usuários clientes
- **Localização:** `code/index.php` (linhas 72–96)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100%
- **Mecanismo:** Ao renderizar a listagem de chamados em `index.php`, o resultado de `listarChamados($db, $busca)` era exibido sem qualquer filtragem por usuário para clientes autenticados, violando a regra de visibilidade do manifesto.
- **Impacto:** Exposição irrestrita de todos os chamados de todos os clientes do ISP para qualquer cliente autenticado.
- **Ação realizada:** Implementada filtragem na camada de apresentação (`index.php`) para usuários com papel `cliente`, restringindo a listagem a chamados onde `(int)$c['usuario_id'] === $uid`, mantendo intacta a assinatura pública de `listarChamados` para consumidores externos e técnicos.
- **Status:** Corrigido (`corrigido: true`).

---

### [F4] Cross-Site Scripting Refletido (XSS) no formulário e resultado de busca
- **Localização:** `code/index.php` (linhas 79–83)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A variável `$busca` (oriunda de `$_GET['busca']`) era interpolada diretamente no HTML: tanto dentro do atributo `value` da tag `<input name="busca" value="' . $busca . '">` quanto dentro do bloco `<p>Resultados para: ' . $busca . '</p>`, sem passar por `htmlspecialchars()`. Um invasor enviando strings contendo aspas duplas ou tags HTML (`"><script>...`) conseguia injetar código JavaScript arbitrário no navegador da vítima.
- **Impacto:** Execução de scripts no contexto do navegador do usuário autenticado, viabilizando roubo de credenciais/sessão e ações não autorizadas.
- **Ação realizada:** Aplicação de `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em todos os pontos de renderização do parâmetro no HTML.
- **Status:** Corrigido (`corrigido: true`).

---

### [F5] Divisão por zero em mediaResposta quando não há chamados respondidos
- **Localização:** `code/lib.php` (linhas 107–117)
- **Categoria:** `bug`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A função `mediaResposta` somava os minutos e dividia pela contagem `$qtd`. Se a tabela estivesse vazia ou nenhum chamado possuísse `minutos_resposta` preenchido, `$qtd` permanecia `0`, provocando uma divisão por zero (`$soma / $qtd`). No PHP 8+, a divisão por zero lança uma exceção não capturada `DivisionByZeroError`, gerando um Erro 500 no carregamento do painel.
- **Impacto:** Quebra total da página inicial e de rotinas analíticas em bases de dados vazias ou recém-criadas.
- **Ação realizada:** Substituição da lógica por consulta agregada `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL` e retorno explícito de float `0.0` quando o valor for nulo/vazio.
- **Status:** Corrigido (`corrigido: true`).

---

### [F6] Problema de N+1 consultas ao banco na listagem e na exportação CSV
- **Localização:** `code/lib.php` (linhas 89–91, 137)
- **Categoria:** `performance`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** Para cada linha recuperada em `listarChamados` e `exportarCsv`, era feita uma chamada síncrona a `tecnicoNome($db, ...)`, executando uma nova query individual `SELECT nome FROM usuarios WHERE id = ...`. Em um cenário com milhares de chamados, isso gerava milhares de roundtrips ao servidor MySQL.
- **Impacto:** Alta latência, degradação de throughput e esgotamento do pool de conexões do banco de dados sob carga moderada.
- **Ação realizada:** Refatoração das consultas SQL em `listarChamados` e `exportarCsv` utilizando `LEFT JOIN usuarios u ON c.tecnico_id = u.id` e `COALESCE(u.nome, '-') AS tecnico_nome`, consolidando o carregamento dos nomes em uma única query.
- **Status:** Corrigido (`corrigido: true`).

---

### [F7] Condição de corrida e dependência de arquivo temporário fixo em exportarCsv
- **Localização:** `code/lib.php` (linhas 125–150)
- **Categoria:** `arquitetura`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** A função `exportarCsv` abria e gravava o CSV em um arquivo de caminho fixo no disco (`/var/www/painel/tmp/chamados.csv`) antes de ler o arquivo com `readfile()`. Em requisições simultâneas de exportação, múltiplos processos escreviam concorrentemente no mesmo arquivo físico. Além disso, se o diretório temporário não existisse ou estivesse sem permissão de escrita, a função abortava silenciosamente sem entregar o arquivo.
- **Impacto:** Relatórios corrompidos sob concorrência e falha total de exportação caso o caminho local em disco não esteja disponível.
- **Ação realizada:** Alteração da rotina para escrever o CSV diretamente no stream de saída (`php://output`) acompanhado dos cabeçalhos HTTP devidos, eliminando a dependência do arquivo em disco e o risco de concorrência.
- **Status:** Corrigido (`corrigido: true`).

---

### [F8] Fixação de sessão (Session Fixation) no processo de autenticação
- **Localização:** `code/index.php` (linhas 23–27)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 90%
- **Mecanismo:** Ao autenticar as credenciais no formulário de login, o sistema registrava o `uid` e o `papel` na sessão atual sem invocar `session_regenerate_id()`.
- **Impacto:** Um invasor capaz de predefinir o cookie de sessão da vítima (ex.: via XSS ou compartilhamento de links) podia obter acesso autenticado imediatamente após o login do usuário legítimo.
- **Ação realizada:** Inclusão de `session_regenerate_id(true)` no bloco de login bem-sucedido, gerando um novo identificador e invalidando o identificador antigo.
- **Status:** Corrigido (`corrigido: true`).

---

### [F9] Segredos e configurações sensíveis hardcoded no código-fonte
- **Localização:** `code/config.php` (linhas 12–18)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 90%
- **Mecanismo:** Chaves e configurações como `SMTP_API_KEY` estavam definidas diretamente no código-fonte como strings estáticas, sem possibilidade de injeção externa via variáveis de ambiente.
- **Impacto:** Risco de vazamento de segredos em repositórios de código e acoplamento rígido com o ambiente local.
- **Ação realizada:** Ajuste em `config.php` para utilizar `getenv('SMTP_API_KEY')` e `getenv('EXPORT_DIR')`, mantendo valores de fallback para preservar compatibilidade com o ambiente existente.
- **Status:** Corrigido (`corrigido: true`).

---

### [F10] Armazenamento e validação de senhas com algoritmo de hash fraco (MD5)
- **Localização:** `code/lib.php` (linha 15)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 95%
- **Mecanismo:** A função `autenticar` utiliza `md5($senha)` para verificar credenciais contra a coluna `senha` (`CHAR(32)`). O algoritmo MD5 é criptograficamente vulnerável a colisões e ataques de dicionário/rainbow tables acelerados por GPU.
- **Impacto:** Alta vulnerabilidade a comprometimento de senhas caso haja vazamento da tabela `usuarios`.
- **Ação realizada:** Reportado como vulnerabilidade técnica. A substituição para `password_hash()` / Bcrypt não foi realizada no código neste momento para evitar a quebra dos logins pré-existentes na base legada de produção.
- **Status:** Não corrigido no código (`corrigido: false`).

---

## 3. Decisões — O que não foi alterado e justificativa

1. **Manutenção do Hash MD5 na Autenticação:**
   - *Decisão:* Não alterar imediatamente o algoritmo em `autenticar()` para `password_hash()`.
   - *Justificativa:* Os dados existentes no banco (`schema.sql` e `seed.sql`) utilizam hashes MD5 de 32 caracteres. Alterar o algoritmo no código sem uma migração de schema e sem uma estratégia de rehash progressivo no próximo login impediria todos os usuários legítimos existentes de acessar o sistema. Recomendamos criar uma migração de banco para alterar a coluna para `VARCHAR(255)` e aplicar rehash progressivo durante o login.

2. **Preservação da Assinatura e Escopo de `listarChamados`:**
   - *Decisão:* Não restringir a query interna de `listarChamados` por `$usuario_id`.
   - *Justificativa:* O `manifest.md` declara que rotinas externas do ISP (rotinas noturnas, relatórios gerenciais e faturamento) chamam `listarChamados(mysqli $db, string $busca = ''): array` esperando a lista completa dos chamados. Restringir a função na biblioteca quebraria essas integrações (`COMP-*`). A regra de negócio de visibilidade por perfil foi aplicada na camada do controlador web (`index.php`).

3. **Preservação dos Rótulos Textuais de Prioridade em `rotuloPrioridade`:**
   - *Decisão:* Manter exatamente as regras e retornos textuais da função `rotuloPrioridade`.
   - *Justificativa:* O formato dos textos retornados é consumido por sistemas externos de auditoria e SLA. Alterar as strings para "uniformizar" a taxonomia violaria contratos públicos externos.

4. **Preservação da Extensão de Banco `mysqli`:**
   - *Decisão:* Não migrar a camada de dados para PDO ou ORM.
   - *Justificativa:* Todas as assinaturas do manifesto exigem o parâmetro de tipo `mysqli $db`. Alterar a biblioteca de conexão violaria as restrições estritas do projeto.
