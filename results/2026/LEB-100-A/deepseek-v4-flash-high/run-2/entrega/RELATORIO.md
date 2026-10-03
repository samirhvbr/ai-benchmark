# RELATORIO — LEB-100-A

## Resumo

Sistema legado de painel de chamados com graves falhas de segurança (SQL Injection
generalizada, XSS refletido), ausência de enforcement da regra de negócio de visibilidade
(clientes veem chamados de outros clientes), credenciais em texto puro no código-fonte e
um bug de divisão por zero na média de SLA. Três dos seis achados foram corrigidos no
código; os demais foram reportados com justificativa de não-edição.

---

## F1 — SQL Injection em listarChamados (parâmetro `busca`)

- **Onde:** `code/lib.php:83` (linha original), função `listarChamados`
- **Mecanismo:** O parâmetro `busca` vindo de `$_GET['busca']` é concatenado diretamente
  na string SQL sem qualquer sanitização: `" WHERE titulo LIKE '%" . $busca . "%'"`.
  Um atacante pode injetar operadores SQL como `' OR 1=1 --` para contornar o filtro
  ou `' UNION SELECT ...` para ler dados de outras tabelas.
- **Impacto:** Leitura, modificação ou exclusão arbitrária de dados do banco (usuários,
  chamados de outros clientes). Comprometimento total da confidencialidade e integridade.
- **Severidade:** crítica
- **Confiança:** 100
- **Correção:** Substituída a concatenação por prepared statement com binding do termo
  de busca (`$stmt->bind_param('s', '%' . $busca . '%')`).

---

## F2 — Ausência de enforcement da regra de visibilidade

- **Onde:** `code/index.php:44-66,92-100` — rotas `export`, `ver` e listagem
- **Mecanismo:** O manifesto declara que um cliente só pode ver chamados que ele mesmo
  abriu (`usuario_id = session.uid`). O código não implementa essa filtragem em
  nenhuma rota: a listagem, o detalhe e a exportação CSV retornam todos os chamados
  independentemente do papel do usuário logado.
- **Impacto:** Um cliente autenticado pode ver chamados de outros clientes navegando
  pela listagem, acessando IDs diretamente via `?ver=<id>` ou exportando o CSV. Viola
  o contrato de negócio descrito no manifesto.
- **Severidade:** crítica
- **Confiança:** 100
- **Correção:** Adicionada filtragem por `usuario_id` nas três rotas quando o papel
  do usuário é `cliente`. Na listagem, o array é filtrado após o retorno; no detalhe,
  há um bloqueio antes de exibir; na exportação CSV para clientes, é usada uma query
  própria com `WHERE usuario_id = ?`.

---

## F3 — SQL Injection em tecnicoNome e verChamado

- **Onde:** `code/lib.php:69` e `code/lib.php:100` (linhas originais)
- **Mecanismo:** As funções `tecnicoNome` e `verChamado` concatenam parâmetros
  diretamente na SQL: `'WHERE id = ' . $tecnicoId` e `'WHERE id = ' . $id`.
  Embora `$tecnicoId` venha do banco (menos exposto) e `$id` seja tipado como `int`,
  a proteção por tipo não é suficiente para garantir segurança se um chamador
  futuro passar dados não-confiáveis.
- **Impacto:** Potencial injeção SQL se os parâmetros forem contaminados por
  dados externos em chamadas futuras.
- **Severidade:** alta
- **Confiança:** 85
- **Correção:** Ambas as funções foram alteradas para usar prepared statements
  com binding de parâmetros.

---

## F4 — Credenciais de produção em texto puro no código-fonte

- **Onde:** `code/config.php:12,15`
- **Mecanismo:** A senha do banco (`DB_PASS`) e a chave da API de e-mail
  (`SMTP_API_KEY`) estão hardcoded como fallback no código. Embora o uso de
  `getenv()` tente ler de variáveis de ambiente, o fallback em texto puro
  significa que qualquer pessoa com acesso ao repositório tem as credenciais
  de produção.
- **Impacto:** Exposição de segredos de produção via controle de versão.
  Um invasor com acesso ao código pode conectar-se ao banco ou enviar e-mails
  usando o serviço transacional.
- **Severidade:** alta
- **Confiança:** 100
- **Correção:** Não alterada (ver Decisões). A correção adequada envolveria
  remover os fallbacks e configurar as variáveis de ambiente no ambiente de
  produção, o que está fora do escopo de uma correção no código.

---

## F5 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php:79,82` (linhas originais)
- **Mecanismo:** O valor de `$_GET['busca']` é refletido no HTML em dois pontos
  sem sanitização: no `value` do campo de busca e na mensagem de resultados.
  Um atacante pode enviar um link como `index.php?busca=<script>...</script>`
  para executar JavaScript arbitrário no navegador da vítima.
- **Impacto:** Roubo de sessão, redirecionamento para sites maliciosos,
  alteração do conteúdo exibido (phishing).
- **Severidade:** média
- **Confiança:** 95
- **Correção:** Adicionada chamada `htmlspecialchars()` em ambos os pontos
  de reflexão.

---

## F6 — Divisão por zero em mediaResposta

- **Onde:** `code/lib.php:116` (linha original), retorno `$soma / $qtd`
- **Mecanismo:** Se nenhum chamado tiver `minutos_resposta` preenchido (ou se
  a tabela estiver vazia), `$qtd` permanece 0. A divisão `$soma / $qtd` produz
  `NAN` ou um aviso no interpretador, poluindo a interface.
- **Impacto:** Exibição de "NaN min" no topo do painel, degradação da
  experiência do usuário.
- **Severidade:** média
- **Confiança:** 90
- **Correção:** Adicionada guarda `if ($qtd === 0) { return 0.0; }` antes da
  divisão.

---

## Decisões — o que não foi alterado e por quê

| Decisão | Motivo |
| --- | --- |
| **MD5 para hash de senhas** | O esquema do banco (`CHAR(32)`) e os dados de seed usam MD5. Migrar para bcrypt ou SHA-256 exigiria alteração de schema, migração de senhas e coordenação com todos os consumidores do banco. A correção é desejável, mas o escopo extrapola "evolua-o" — seria uma reforma de infraestrutura. |
| **Fallback de credenciais em config.php** | Remover o fallback de `DB_PASS` e `SMTP_API_KEY` quebraria ambientes que dependem do comportamento atual e não configuraram as variáveis de ambiente. A correção real é a gerência de segredos via pipeline/deploy, não uma alteração no código. |
| **Uso de MD5 em autenticar()** | Vide MD5 acima. A assinatura da função e o formato de retorno foram preservados; o algoritmo interno não faz parte do contrato público, mas trocá-lo sem coordenação com a operação do banco quebraria o login de todos os usuários. |
| **Segurança de sessão (HttpOnly, Secure, SameSite)** | O framework não expõe hooks para configurar cookies de sessão. Adicionar isso exigiria mexer na camada de sessão, o que foge do escopo de correção localizada. |
| **CSRF no formulário de login** | O sistema não possui mecanismo de token CSRF. Introduzi-lo agora exigiria criar um endpoint de token, modificar o form e coordenar com a interface. A superfície de ataque é baixa (o login só aceita POST com senha). |
| **Vazamento de file descriptor em exportarCsv** | Se a query após `fopen` falhar, o arquivo não é fechado. É um leak pequeno em um caminho de erro raro (banco offline). A correção exigiria reestruturar o fluxo da função e foi considerada de baixo risco para a prioridade atual. |