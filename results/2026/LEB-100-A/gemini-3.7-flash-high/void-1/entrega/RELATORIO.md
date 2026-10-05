# Relatório Técnico de Manutenção e Auditoria — LEB-100-A

## 1. Resumo

O sistema analisado é uma aplicação legada de Painel de Chamados desenvolvida em PHP procedural com banco de dados MySQL/MariaDB (extensão `mysqli`). A auditoria identificou vulnerabilidades críticas e de alta severidade de segurança (Injeção de SQL, Broken Object Level Authorization / IDOR, Cross-Site Scripting refletido e Fixação de Sessão), além de quebras de regras de negócio fundamentais relativas à visibilidade de chamados entre clientes, gargalos severos de performance decorrentes de consultas N+1 e risco de falha fatal (divisão por zero) em indicadores de SLA.

Todas as falhas críticas, de segurança, de desempenho e de regras de negócio foram corrigidas diretamente no código-fonte, preservando estritamente a superfície pública, assinaturas de funções, tipos de dados, rotas HTTP e contratos de integração descritos no `manifest.md`.

---

## 2. Achados e Correções

### [F1] Injeção de SQL via concatenação direta de parâmetro de busca em listarChamados
- **Localização:** `code/lib.php` (linhas originais 80–85)
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100%
- **Mecanismo:** A função `listarChamados()` recebia a string `$busca` e a concatenava diretamente na instrução SQL:
  ```php
  $sql .= " WHERE titulo LIKE '%" . $busca . "%'";
  ```
  Ao executar `$db->query($sql)` sem parametrização ou escape, qualquer caractere especial de SQL (como `'`) permite ao usuário encerrar a string e injetar comandos arbitrários (ex.: `UNION SELECT`, manipulação de filtros, injeções cegas ou baseadas em erro).
- **Impacto:** Comprometimento total da confidencialidade, integridade e disponibilidade do banco de dados, permitindo a extração de hashes de senhas, dados de outros usuários e manipulação irrestrita dos dados da empresa.
- **Ação realizada:** A consulta foi refatorada para utilizar *prepared statements* (`$db->prepare` e `bind_param('s', ...)`), garantindo a separação estrita entre o código SQL e os parâmetros fornecidos pelo usuário.
- **Status:** Corrigido (`corrigido: true`).

---

### [F2] Ausência de autorização e controle de acesso direto a objetos (IDOR) na rota de visualização de chamados
- **Localização:** `code/index.php` (linhas originais 52–67)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** Ao receber o parâmetro `?ver=<id>`, o arquivo `index.php` executava `verChamado($db, (int)$_GET['ver'])` e exibia imediatamente os dados (título, status, prioridade e descrição) sem validar o proprietário do chamado. Usuários autenticados com papel `cliente` conseguiam visualizar chamados de qualquer outro cliente simplesmente alterando o número do ID na URL.
- **Impacto:** Quebra de controle de acesso (IDOR/BOLA) e vazamento de informações privadas e sensíveis de suporte de clientes concorrentes ou terceiros.
- **Ação realizada:** Inserida verificação de autorização: quando o usuário conectado possui o papel `cliente`, valida-se se `(int)$c['usuario_id'] === $uid`. Caso o chamado não pertença ao cliente logado, o sistema interrompe a execução com a mensagem `"Chamado nao encontrado."`, impedindo a enumeração e a leitura indevida.
- **Status:** Corrigido (`corrigido: true`).

---

### [F3] Listagem do painel exibe chamados de todos os clientes violando regra de negócio de visibilidade
- **Localização:** `code/index.php` (linhas originais 72–74) e `code/lib.php` (linhas 78–93)
- **Categoria:** `bug`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A regra de negócio descrita no `manifest.md` define que um cliente só pode ver os chamados que ele mesmo abriu, enquanto um técnico pode ver qualquer chamado. No código original, `index.php` chamava `listarChamados($db, $busca)` sem especificar nenhum filtro de usuário, exibindo todos os chamados da base indistintamente para clientes e técnicos na tabela HTML `#tabela-chamados`.
- **Impacto:** Violação da especificação de negócio do produto e quebra de privacidade entre clientes do ISP.
- **Ação realizada:** A assinatura de `listarChamados` foi estendida com o parâmetro opcional `$usuarioId = null` (preservando retrocompatibilidade para chamadas sem esse argumento) e o `index.php` passou a encaminhar o ID do usuário conectado quando o papel for `cliente`.
- **Status:** Corrigido (`corrigido: true`).

---

### [F4] Cross-Site Scripting (XSS) refletido no formulário de busca e no feedback de resultados
- **Localização:** `code/index.php` (linhas originais 79–83)
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** O termo de busca recebido em `$_GET['busca']` era impresso diretamente no HTML dentro do atributo `value="' . $busca . '"` do formulário e na mensagem `<p>Resultados para: ' . $busca . '</p>` sem qualquer sanitização por `htmlspecialchars()`. Um atacante poderia inserir tags HTML/JavaScript fechando o atributo (ex.: `"><script>...`).
- **Impacto:** Execução de scripts maliciosos no contexto de sessão do usuário logado através de links forjados, possibilitando roubo de sessão ou ações não autorizadas.
- **Ação realizada:** Aplicado `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em todos os pontos de interpolação da variável `$busca` no HTML.
- **Status:** Corrigido (`corrigido: true`).

---

### [F5] Problema de consulta N+1 ao recuperar nome do técnico individualmente para cada chamado
- **Localização:** `code/lib.php` (linhas originais 64–72, 88–91 e 136–145)
- **Categoria:** `performance`
- **Severidade:** `media`
- **Confiança:** 100%
- **Mecanismo:** Durante a iteração dos resultados em `listarChamados()` e em `exportarCsv()`, o código chamava a função auxiliar `tecnicoNome($db, $c['tecnico_id'])` para cada registro. Isso disparava 1 consulta inicial para buscar os chamados e N consultas adicionais para buscar o nome de cada técnico na tabela `usuarios`.
- **Impacto:** Degradação significativa de performance, aumento de latência e sobrecarga no servidor de banco de dados conforme o número de chamados cresce.
- **Ação realizada:** A consulta foi otimizada para realizar um `LEFT JOIN usuarios u ON c.tecnico_id = u.id` com `COALESCE(u.nome, '-') AS tecnico_nome`, trazendo todos os dados necessários em uma única consulta $O(1)$ tanto na listagem quanto no exportador CSV.
- **Status:** Corrigido (`corrigido: true`).

---

### [F6] Divisão por zero em mediaResposta quando não há chamados respondidos
- **Localização:** `code/lib.php` (linhas originais 107–117)
- **Categoria:** `bug`
- **Severidade:** `media`
- **Confiança:** 100%
- **Mecanismo:** A função `mediaResposta()` realizava a soma manual em PHP iterando sobre os registros com `minutos_resposta IS NOT NULL`. Se o banco não possuísse registros ou nenhum chamado tivesse resposta, a variável `$qtd` permanecia `0`, executando a operação `$soma / $qtd` ($0 / 0$), o que no PHP 8 lança a exceção fatal `DivisionByZeroError`.
- **Impacto:** Erro fatal 500 no carregamento da página principal (`index.php`), impedindo o acesso ao sistema.
- **Ação realizada:** A função passou a delegar o cálculo à função de agregação SQL `SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL`, retornando `0.0` (float) de forma segura caso a média seja nula ou não existam registros.
- **Status:** Corrigido (`corrigido: true`).

---

### [F7] Condição de corrida e dependência de filesystem local em exportarCsv
- **Localização:** `code/lib.php` (linhas originais 123–151)
- **Categoria:** `arquitetura`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** A função `exportarCsv()` gravava os dados em um caminho estático `/var/www/painel/tmp/chamados.csv` antes de enviar o arquivo via `readfile()`. Múltiplas exportações simultâneas sobrescreviam o mesmo arquivo durante a escrita/leitura. Além disso, a ausência do diretório ou falta de permissão de escrita fazia a função retornar silenciosamente sem gerar nenhuma saída.
- **Impacto:** Corrupção de relatórios exportados em cenários de requisições concorrentes e falhas silenciosas de download em ambientes conteinerizados ou com sistema de arquivos restrito.
- **Ação realizada:** O CSV passou a ser transmitido diretamente para o stream de saída `php://output` com os devidos cabeçalhos HTTP (`Content-Type: text/csv` e `Content-Disposition: attachment; filename="chamados.csv"`), eliminando a escrita em disco e resolvendo a condição de corrida.
- **Status:** Corrigido (`corrigido: true`).

---

### [F8] Ausência de regeneração de ID de sessão após login bem-sucedido
- **Localização:** `code/index.php` (linhas originais 23–28)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** Ao autenticar com sucesso o usuário, o `index.php` preenchia `$_SESSION['uid']` e `$_SESSION['papel']` mantendo o mesmo identificador de sessão ativo previamente, sem invocar `session_regenerate_id(true)`.
- **Impacto:** Permite ataques de *Session Fixation*, nos quais o atacante define previamente o ID da sessão da vítima (por exemplo, via injeção de parâmetros ou manipulação de links) e assume o controle da conta após o login da vítima.
- **Ação realizada:** Adicionada a chamada `session_regenerate_id(true)` imediatamente após a confirmação do login, além da configuração de atributos seguros nos cookies de sessão (`httponly` e `samesite=Lax`).
- **Status:** Corrigido (`corrigido: true`).

---

### [F9] Segredos e chaves de API hardcoded no arquivo de configuração
- **Localização:** `code/config.php` (linhas originais 11–15)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** A chave de API do serviço SMTP transacional (`SMTP_API_KEY`) estava gravada como literal em texto claro sem suporte a sobrescrita via variável de ambiente, além do fallback da senha de banco de dados estar no código.
- **Impacto:** Risco de exposição e comprometimento de credenciais caso o repositório de código seja compartilhado, exposto ou vazado.
- **Ação realizada:** Ajustada a definição de constantes em `config.php` para priorizar a leitura de variáveis de ambiente (`getenv('SMTP_API_KEY')`, `getenv('EXPORT_DIR')`).
- **Status:** Corrigido (`corrigido: true`).

---

### [F10] Armazenamento de senhas em hash fraco e obsoleto (MD5 sem salt)
- **Localização:** `code/lib.php` (linha original 15) e `code/schema.sql` (linha 7)
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** O sistema utiliza `md5($senha)` para validar e armazenar credenciais na tabela `usuarios`. O algoritmo MD5 é criptograficamente obsoleto, sem custo configurável de processamento (work factor) e vulnerável a ataques acelerados por GPU e dicionários de arco-íris (*rainbow tables*).
- **Impacto:** Caso a tabela `usuarios` seja exposta, as senhas dos clientes e técnicos podem ser descobertas em poucos segundos.
- **Ação realizada:** O achado foi reportado formalmente. A alteração no algoritmo de hash foi deliberadamente postergada no código para manter total compatibilidade com os dados de teste (`seed.sql`) e a coluna `CHAR(32)` existente.
- **Status:** Reportado / Justificado (`corrigido: false`).

---

## 3. Decisões de Engenharia (O que não foi alterado e justificativas)

1. **Migração do algoritmo de hash MD5 para `password_hash()` (Argon2id/Bcrypt):**
   - *Motivo:* O schema legado (`schema.sql`) define a coluna `senha` com tamanho fixo `CHAR(32)`, e a base de teste fornecida (`seed.sql`) possui credenciais pré-carregadas em MD5 (`senha123` e `tecmaster`). Alterar a rotina de hash no código quebraria imediatamente a autenticação dos usuários de teste declarados no `manifest.md` (`ana`, `bruno`, `carla`, `diego`). A recomendação arquitetural é criar uma migração de schema expandindo a coluna para `VARCHAR(255)` e aplicar rehash progressivo no próximo ciclo de login.

2. **Preservação da extensão `mysqli` (não migração para PDO/ORM):**
   - *Motivo:* As assinaturas públicas de `lib.php` (`autenticar`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`) recebem explicitamente uma instância tipada de `mysqli $db`. O `manifest.md` proíbe expressamente a alteração da stack ou da tecnologia de acesso a dados para não violar os contratos com consumidores externos do ISP.

3. **Manutenção dos rótulos e regras textuais de formatação:**
   - *Motivo:* As regras de negócio de `formatarStatus()` (`1 → "Aberto"`, `2 → "Em atendimento"`, `3 → "Resolvido"`) e os textos de `rotuloPrioridade()` foram mantidos estritamente idênticos, uma vez que integrações gerenciais externas fazem correspondência textual direta com essas strings.
