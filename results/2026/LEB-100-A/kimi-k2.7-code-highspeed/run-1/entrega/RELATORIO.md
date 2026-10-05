# Relatório Técnico — LEB-100-A

## Resumo

O sistema é um painel de chamados PHP procedural/mysqli legado, pequeno e de fácil leitura, mas com várias falhas de segurança e robustez que precisam de atenção imediata. Os problemas mais graves são injeção SQL em múltiplos pontos e a ausência de autorização na camada web, que expõe chamados de outros clientes. As correções foram feitas preservando as assinaturas públicas descritas em `manifest.md` e sem trocar a stack.

---

## Achados

### F1 — SQL Injection em `listarChamados` via parâmetro `busca`

- **Onde:** `code/lib.php`, linhas 80–83 (versão original).
- **Mecanismo:** a função concatena a string de busca diretamente na cláusula `WHERE titulo LIKE '%...%'`. Como o valor vem de `$_GET['busca']` em `index.php`, um atacante pode injetar SQL (ex.: `busca=' OR '1'='1` para listar todos os chamados, ou union/select para ler outras tabelas).
- **Impacto:** vazamento total dos chamados, possível leitura/escrita no banco, dependendo dos privilégios do usuário do banco.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** substituí a concatenação por prepared statement com `bind_param('s', $like)`.

### F2 — SQL Injection em `verChamado`

- **Onde:** `code/lib.php`, linha 100 (versão original).
- **Mecanismo:** a função concatena `$id` diretamente na query. Embora `index.php` faça `(int)$_GET['ver']` antes de chamar a função, a própria função é pública e consumida por outros scripts do ISP; qualquer chamador futuro que passe uma string ou esqueça o cast abre brecha.
- **Impacto:** leitura arbitrária do banco, potencialmente outras tabelas.
- **Severidade:** alta.
- **Confiança:** 95.
- **O que foi feito:** substituí por prepared statement com `bind_param('i', $id)`.

### F3 — SQL Injection em `tecnicoNome`

- **Onde:** `code/lib.php`, linha 69 (versão original).
- **Mecanismo:** a função recebe `?int $tecnicoId`, mas ainda assim concatena o valor em `SELECT nome FROM usuarios WHERE id = ...`. O tipo `?int` ajuda quando chamada tipada, mas a função é chamada a partir de `$c['tecnico_id']` que vem do banco; em cenários diferentes o valor pode ser controlado.
- **Impacto:** leitura da tabela `usuarios` e, via SQLi, de outras tabelas.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** substituí por prepared statement com `bind_param('i', $tecnicoId)`.

### F4 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php`, linha 116 (versão original).
- **Mecanismo:** a função calcula `$soma / $qtd` sem verificar se `$qtd > 0`. Se nenhum chamado tiver `minutos_resposta` preenchido, ocorre `DivisionByZeroError` e a página quebra.
- **Impacto:** indisponibilidade da listagem no topo do painel.
- **Severidade:** alta.
- **Confiança:** 90.
- **O que foi feito:** retorno condicional: `$qtd > 0 ? $soma / $qtd : 0.0`.

### F5 — Violação da regra de visibilidade (cliente vê chamados de outros)

- **Onde:** `code/index.php`, linhas 52–66 (detalhe) e 72–73 (listagem), versão original.
- **Mecanismo:** o manifesto define que cliente só pode ver seus próprios chamados e técnico pode ver todos. No entanto, `listarChamados` retorna todos os chamados e `verChamado` retorna qualquer chamado pelo ID, sem levar em conta o usuário logado. Um cliente autenticado pode simplesmente acessar `index.php?ver=103` (onde 103 é de outro cliente) ou ver na listagem todos os chamados.
- **Impacto:** vazamento de dados de clientes (títulos, descrições, status, nomes de técnicos), potencial exposição de informações sensíveis de negócio.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** mantive as assinaturas públicas de `listarChamados` e `verChamado` inalteradas. A autorização foi implementada em `index.php`: na listagem, filtro o array retornado quando o papel é `cliente`; no detalhe, verifico se o papel é `cliente` e se `usuario_id` pertence ao usuário logado, retornando "não encontrado" caso contrário.

### F6 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php`, linhas 79 e 82 (versão original).
- **Mecanismo:** o valor de `$_GET['busca']` é ecoado sem `htmlspecialchars` no atributo `value` do input e no parágrafo "Resultados para". Um atacante pode construir um link como `index.php?busca=<script>alert(1)</script>` para executar JavaScript no navegador da vítima.
- **Impacto:** roubo de sessão, ações em nome do usuário, defacement parcial.
- **Severidade:** alta.
- **Confiança:** 95.
- **O que foi feito:** apliquei `htmlspecialchars()` no valor exibido, incluindo `ENT_QUOTES` no atributo do input.

### F7 — Gravação de CSV em arquivo temporário no disco

- **Onde:** `code/lib.php`, linhas 125–150 (versão original).
- **Mecanismo:** `exportarCsv` grava `/var/www/painel/tmp/chamados.csv` em disco e depois faz `readfile`. O arquivo pode ser lido por outros processos/usuários no servidor entre a gravação e o envio, vazar dados em caso de falha parcial e nunca é removido. Além disso, se o diretório não existir ou não for gravável, a função falha silenciosamente (`return`).
- **Impacto:** vazamento de dados do CSV no filesystem, falhas silenciosas.
- **Severidade:** média.
- **Confiança:** 80.
- **O que foi feito:** reescrevi a função para escrever diretamente em `php://output`, eliminando o arquivo temporário. Os headers e o formato do CSV permanecem idênticos.

### F8 — `formatarStatus` retorna rótulo incorreto para status inválidos

- **Onde:** `code/lib.php`, linhas 26–35 (versão original).
- **Mecanismo:** a função usa `if/else` de modo que qualquer valor diferente de 1 e 2 retorna `"Resolvido"`. Um status 0, 4 ou corrompido seria exibido como resolvido, o que pode induzir a decisões erradas no relatório gerencial.
- **Impacto:** informação errada no painel/relatórios.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** deixei explícitos os casos 1, 2 e 3, retornando `"Desconhecido"` para qualquer outro valor. O contrato de valor do manifesto (1, 2, 3) continua sendo atendido exatamente.

### F9 — Senhas armazenadas como MD5 sem salt

- **Onde:** `code/lib.php`, linha 15; `code/schema.sql`, linha 7.
- **Mecanismo:** a senha do usuário é hasheada com `md5($senha)` e comparada com o banco. MD5 é rápido, reversível por rainbow tables e não usa salt. Duas senhas iguais produzem hashes idênticos.
- **Impacto:** em caso de vazamento do banco, as senhas podem ser recuperadas rapidamente, permitindo reutilização em outros serviços.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** **não alterei**. Mudar para `password_hash`/`password_verify` exigiria alterar o schema (`CHAR(32)` não comporta bcrypt), re-gerar as senhas de teste e potencialmente quebrar integrações que fazem a comparação direta de hash MD5. Registrei como débito técnico.

### F10 — Credenciais e chave de API hardcoded em `config.php`

- **Onde:** `code/config.php`, linhas 12 e 15.
- **Mecanismo:** `DB_PASS` e `SMTP_API_KEY` possuem valores padrão embutidos no código-fonte. Se o repositório for exposto, essas credenciais vazam junto.
- **Impacto:** acesso não autorizado ao banco e/ou ao serviço de e-mail.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** **não alterei**. O arquivo já permite sobrescrever via variáveis de ambiente (`getenv`), mas remover o fallback quebraria ambientes de teste legados que não configuram as variáveis. A correção correta é feita fora do código (CI/CD, secrets manager), não na lógica.

---

## Decisões — o que não foi alterado

1. **MD5 para senhas:** não mudei o algoritmo de hash. A mudança exigiria alterar o schema, os dados de teste e potencialmente quebrar scripts que consomem a tabela `usuarios` diretamente. A assinatura de `autenticar()` seria preservada, mas o contrato implícito de valor (hash MD5) mudaria.
2. **Credenciais hardcoded:** mantive os fallbacks em `config.php`. O código já oferece a opção de variáveis de ambiente; remover os fallbacks sem garantir que todos os ambientes as configurem seria arriscado.
3. **CSRF / logout / session fixation:** não adicionei tokens CSRF, botão de logout nem regeneração de ID de sessão. A aplicação não possui ações destrutivas via POST além do login, e adicionar uma nova rota de logout mudaria a superfície pública. A sessão atual é funcional para o escopo.
4. **Não alterei a stack de acesso a dados:** mantive `mysqli` e prepared statements, conforme a restrição do manifesto.
5. **Não alterei `rotuloPrioridade`:** a lógica, embora aninhada, parece produzir os rótulos esperados para as prioridades e SLAs do seed. Sem testes de negócio detalhados, prefiro não reescrever.
6. **Não alterei a estrutura HTML da listagem:** mantive a tabela `id="tabela-chamados"`, colunas e links exatamente como declarado no manifesto.

---

## Verificação de compatibilidade

- Assinaturas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`: preservadas.
- Rótulos de status para 1, 2, 3: preservados exatamente.
- Rotas GET (`busca`, `ver`, `export`): preservadas.
- Cabeçalho e colunas do CSV: preservados.
- Estrutura HTML da listagem: preservada.
- Regra de visibilidade: agora corretamente aplicada.
