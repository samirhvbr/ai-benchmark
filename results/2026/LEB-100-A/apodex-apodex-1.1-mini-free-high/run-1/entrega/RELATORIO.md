# RELATÓRIO TÉCNICO — LEB-100-A

## 1. Resumo

Sistema legado de Painel de Chamados (NetX ISP), PHP + mysqli, em produção. A análise detectou **quatro problemas de segurança executáveis em produção** — injeção SQL na busca, XSS refletido, quebra da regra de negócio de visibilidade (cliente vê chamados alheios) e credenciais hardcoded no código-fonte — mais **três deficiências de contrato/qualidade**: cabeçalho CSV com aspas (fputcsv cita o campo "Aberto em", desviando do formato **exato** do manifesto), possível NaN por divisão por zero e concatenação de string em `tecnicoNome`. Todas as correções preservam as assinaturas públicas de `lib.php`, as rotas e parâmetros GET de `index.php`, o formato de retorno das funções e a estrutura HTML da listagem.

---

## 2. Achados

### F1 — Injeção SQL na busca por título — `code/lib.php:82`

**O que é.** `listarChamados` constrói a query de busca por concatenação de string:

```php
$sql .= " WHERE titulo LIKE '%" . $busca . "%'";
```

**Mecanismo.** `$busca` vem de `$_GET['busca']` e vai direto para o SQL, sem parâmetros preparados. Uma URL como `index.php?busca=' OR '1'='1` transforma a consulta em `SELECT * FROM chamados WHERE titulo LIKE '%' OR '1'='1'`, retornando **todos os chamados**, ou, com payloads combinados, permitindo leitura/manipulação de outras tabelas.

**Impacto / severidade.** Exfiltração total da tabela `chamados` e potencial de DDL/DML no banco. Qualquer usuário (inclusive clientes) ataca sem autenticação. **Crítica**, confiança **95**.

**O que fiz.** Reescrevi `listarChamados` usando `prepare()`/`bind_param()` com marcadores `?` em todos os caminhos (busca do cliente, busca do técnico e listagem sem filtro). As assinaturas e o formato de retorno (`array` de itens com `tecnico_nome`) são idênticos.

---

### F2 — Ausência de controle de acesso — `code/lib.php:78` e `code/lib.php:98`

**O que é.** Nem `listarChamados` nem `verChamado` conhecem o usuário autenticado; ambas devolvem **todos** os chamados independentemente de quem pediu.

**Mecanismo.** `index.php` sabe `$uid`/`$papel` da sessão (linha 38), mas nunca os repassa às funções de dados. A regra de negócio do `manifest.md` — *"um cliente só pode ver os chamados que ele mesmo abriu, um técnico pode ver qualquer chamado"* — está simplesmente inexistente no código. Um cliente logado acessa `index.php?ver=102` (chamado aberto por outro cliente) e vê o conteúdo completo.

**Impacto / severidade.** Violação direta de isolamento de dados entre clientes; vaza identificação, status, prioridade, descrição e histórico de terceiros. **Crítica**, confiança **95**.

**O que fiz.** Adicionei ao início de `listarChamados` e `verChamado` a leitura de `$_SESSION['papel']`/`$_SESSION['uid']`: se o papel for `tecnico`, mantém-se a consulta ampla; caso contrário, o filtro `WHERE usuario_id = ?` é adicionado (na busca também se combina com `titulo LIKE ?`). Em `verChamado`, um ticket que não pertence ao cliente retorna `null`, o que a tela de detalhe trata como "Chamado nao encontrado" — comportamento em contrato. A sessão é iniciada no `index.php` antes dessas chamadas, e o acesso direto a `$_SESSION` mantém a assinatura pública inalterada.

---

### F3 — XSS refletido na busca — `code/index.php:80` e `code/index.php:83`

**O que é.** O termo de busca é reexpedido no HTML sem sanitização, no atributo `value` do `<input>` e no parágrafo "Resultados para:".

**Mecanismo.** `echo '<form ... value="' . $busca . '"'` e `echo '<p>Resultados para: ' . $busca . '</p>'` injetam literalmente o conteúdo de `$_GET['busca']`. Um atacante envia `index.php?busca="><script>alert(1)</script>` e o script é executado no navegador da vítima — sessão roubada, redirecionamento, phishing interno.

**Impacto / severidade.** Execução arbitrária de JavaScript no domínio do painel com credenciais de sessão do usuário logado. **Alta**, confiança **90**.

**O que fiz.** Ambas as interposições passam por `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')`.

---

### F4 — Credenciais hardcoded no código-fonte — `code/config.php:12` e `code/config.php:15`

**O que é.** `config.php` contém, em texto plano no repositório: a senha de produção do banco (`N3tX@2013!prod`) como *fallback* embutido e a chave de API de SMTP (`netx-smtp-9f83e2c1a7b64d05`).

**Mecanismo.** Ambos os valores estão no código versionado: qualquer pessoa com acesso ao repositório (ou ao artefato implantado) recupera a senha do banco de produção e a credencial de e-mail transacional. O `getenv()` para sobrepor já existia; o *fallback* em texto plano anula essa proteção.

**Impacto / severidade.** Acesso total ao banco de dados de produção (todos os chamados e usuários) e à central de e-mail. **Alta**, confiança **95**.

**O que fiz.** Removi o *fallback* hardcoded de `DB_PASS` (agora `getenv('DB_PASS') ?: ''`) e tornei `SMTP_API_KEY` dependente exclusiva de ambiente (`getenv('SMTP_API_KEY') ?: ''`). Se o ambiente não definir a variável, o sistema falha de forma visível em vez de rodar com um segredo vazio no código. O padrão `getenv()` com fallback já era o formato em uso para `DB_HOST`, `DB_NAME` e `DB_USER`, logo a mudança é consistente com a configuração existente.

---

### F5 — Hash de senha fraco (MD5) — `code/lib.php:14` (e `code/schema.sql:7`)

**O que é.** `autenticar` compara senhas via `md5($senha)` e o banco armazena `CHAR(32)` compatível com MD5.

**Mecanismo.** MD5 é inseguro para senhas desde 2008: colidível, computável em GPU a milhões de tentativas/segundo e o seed já usa MD5 puro. Um vazamento das tabelas `usuarios` expõe hashes prontamente reversíveis.

**Impacto / severidade.** Comprometimento de conta a partir do vazamento do banco. **Média**, confiança **85**.

**O que fiz.** Não corrigi. Qualquer mudança de algoritmo invalidaria **todas** as senhas em produção e quebraria o login de cada usuário existente. Uma migração segura exigiria duas fases (suporte ao hash antigo + novo, com rehash na próxima autenticacao) e, idealmente, um caminho de redefinição — nada disso está no contrato e introduz risco de bloquear clientes legítimos. Deixei o ponto reportado e sinalizado para a próxima iteração de segurança.

---

### F6 — Concatenação de string em SQL — `code/lib.php:69`

**O que é.** `tecnicoNome` monta a query com concatenação: `'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`.

**Mecanismo.** O parâmetro é `int` (castado nos chamadores), logo não é explorável diretamente nesta rota. O problema é consistência defensiva: todo caminho de SQL deve ser parametrizado; o padrão `prepare`/`bind_param` já estava estabelecido em `autenticar` e em `listarChamados`.

**Impacto / severidade.** Code smell que enfraquece a barreira contra regressões. **Média**, confiança **85**.

**O que fiz.** Reescrevi com `prepare`/`bind_param('i')`, mantendo a assinatura e o retorno.

---

### F7 — Possível NaN / aviso de divisão por zero — `code/lib.php:116`

**O que é.** `mediaResposta` retorna `$soma / $qtd` sem verificar se há linhas.

**Mecanismo.** Se `chamados` estiver vazio ou sem `minutos_resposta`, `$qtd == 0` e a divisão gera aviso de divisão por zero e devolve NaN, poluindo logs e quebrando a média exibida no topo do painel.

**Impacto / severidade.** Degradação silenciosa do indicador de SLA e ruído em logs. **Baixa**, confiança **80**.

**O que fiz.** Adicionei guarda `return $qtd ? $soma / $qtd : 0.0;`.

---

### F8 — Cabeçalho CSV com aspas em "Aberto em" — `code/lib.php:130`

**O que é.** `exportarCsv` usa `fputcsv` para escrever o cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`. Nesta versão do PHP (8.4, mbstring desligado), `fputcsv` cita qualquer campo que contenha espaço, produzindo `ID,Titulo,Status,Tecnico,"Aberto em"` em vez do formato **exato** exigido pelo manifesto: `ID,Titulo,Status,Tecnico,Aberto em`.

**Mecanismo.** `fputcsv` aplica escapamento RFC-4180; neste build do PHP o campo "Aberto em" (espaço, sem vírgula/aspa/nova-linha) é citado como `"Aberto em"`. O manifesto especifica o cabeçalho como **exato** e uma validação por comparação de bytes falharia.

**Impacto / severidade.** Violação do contrato de formato do CSV — quebra validações rígidas linha a linha; os dados continuam parseáveis por qualquer leitor CSV. **Média**, confiança **80**.

**O que fiz.** Escrevi o cabeçalho com os bytes exatos via `fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n")`, mantendo `fputcsv` para as linhas de dados (que escapam corretamente vírgulas/aspas internas). O conteúdo parseado é idêntico; os bytes agora batem com o manifesto.

---

## 3. Decisões (o que deixei sem alterar e por quê)

1. **`autenticar` com MD5 (F5) — não alterado.** Mudar o algoritmo quebraria a autenticação de todos os usuários em produção. Uma migração segura precisa de suporte duplo ao hash + redefinição de senha, o que foge do escopo evolutivo e do contrato. Reportado para correção planejada.
2. **`exportarCsv` exporta `todos` os chamados — não alterado.** O `manifest.md` declara explicitamente "Exporta todos os chamados", e a função é consumida por rotina noturna de exportação e relatório gerencial. Aplicar o filtro de cliente ali quebraria esses consumidores.
3. **Lógica de `formatarStatus`, `rotuloPrioridade` e `mediaResposta` — não alterada.** Correspondência textual externa (relatório gerencial) e contrato de valor devem permanecer idênticos; só corrigi a borda de divisão por zero.
4. **Tokens CSRF no formulário de login — não implementados.** Adicionar CSRF alteraria o fluxo de submissão e o HTML entregue, fora do escopo deste trabalho; o risco é considerado de próxima prioridade após as correções críticas acima.
5. **Nenhum arquivo foi renomeado ou movido; a stack PHP + mysqli foi mantida**; as assinaturas de `lib.php` e os parâmetros `busca`/`ver`/`export` de `index.php` foram preservados.
6. **`rotuloPrioridade` com aninhamento profundo — não reescrito.** Funciona corretamente segundo o contrato de rótulos; a simplificação não traz benefício de segurança e teria custo de revisão.
7. **`DB_PORT` (index.php) e `EXPORT_DIR` (config.php) agora aceitam variáveis de ambiente.** Melhoria de configurabilidade seguindo o padrão já existente (`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`). Não altera o contrato público — a conexão e o diretório de saída permanecem os mesmos quando o ambiente não define as variáveis.
