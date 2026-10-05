# RELATÓRIO TÉCNICO — LEB-100-A

## Resumo

O sistema é um painel de chamados legado em PHP/MySQLi com pouca separação de
responsabilidades. Os principais riscos encontrados são **injeção SQL** em
múltiplos pontos, **quebra da regra de negócio de visibilidade** (clientes
conseguem ver chamados de terceiros), **XSS refletido** no campo de busca e
**divisão por zero** no cálculo de SLA. Também há segredos hardcoded no
repositório e armazenamento de senhas com MD5.

A correção preservou todas as assinaturas públicas declaradas no `manifest.md`,
continuou usando MySQLi e não alterou a estrutura de arquivos. A regra de
visibilidade foi implementada dentro das funções existentes lendo a sessão,
sem adicionar novos parâmetros à superfície pública.

---

## Achados

### F1 — Injeção SQL no filtro de título (`listarChamados`)

- **Onde:** `code/lib.php`, linha 82 (original).
- **Mecanismo:** A variável `$busca` vinda de `$_GET['busca']` é concatenada
diretamente na cláusula `WHERE titulo LIKE '%...%'`. Um payload como
`busca=' OR '1'='1` permite ler todos os chamados; com UNION é possível
exfiltrar a tabela `usuarios` e as hashes MD5.
- **Impacto:** Vazamento total do banco, bypass de qualquer filtro futuro.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** Reescrita da função com prepared statements. O LIKE
continua funcionando, mas o termo é passado como parâmetro.

### F2 — Quebra da regra de negócio de visibilidade

- **Onde:** `code/lib.php`, linhas 78–93 (`listarChamados`), 98–102
(`verChamado`) e 125–150 (`exportarCsv`) (originais).
- **Mecanismo:** As funções não aplicam o filtro `usuario_id` quando o usuário
logado tem papel `cliente`. Um cliente autenticado consegue listar, detalhar e
exportar todos os chamados do sistema, inclusive de outros clientes, simples
acessando `index.php?ver=103` ou clicando em "Exportar CSV".
- **Impacto:** Violação de privacidade e de contrato; clientes acessam dados de
terceiros.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** As três funções agora leem `$_SESSION['papel']` e
`$_SESSION['uid']`. Quando o papel é `cliente`, adicionam `usuario_id = ?` à
consulta (prepared statement). Técnicos e scripts sem sessão continuam vendo
tudo, preservando as rotinas noturnas.

### F3 — XSS refletido no parâmetro `busca`

- **Onde:** `code/index.php`, linhas 79 e 82 (originais).
- **Mecanismo:** O valor de `$busca` é impresso diretamente no HTML. Um link
como `index.php?busca=<script>alert(document.cookie)</script>` executa
JavaScript no contexto do painel.
- **Impacto:** Roubo de sessão, ações em nome do usuário.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Aplicado `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')`
antes de imprimir o valor no formulário e na mensagem de resultados.

### F4 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php`, linha 116 (original).
- **Mecanismo:** `return $soma / $qtd;` é executado mesmo quando nenhum chamado
tem `minutos_resposta` preenchido. Em uma base nova ou filtrada, `$qtd` vale 0
e gera warning/fatal.
- **Impacto:** Tela de listagem quebrada em instalações sem histórico.
- **Severidade:** média.
- **Confiança:** 100.
- **O que foi feito:** Retorno condicional: `0.0` quando `$qtd === 0`.

### F5 — Injeção SQL em `verChamado`

- **Onde:** `code/lib.php`, linha 100 (original).
- **Mecanismo:** O ID é concatenado na query. Embora `index.php` faça cast para
`int`, outros consumidores internos podem chamar `verChamado($db, '1 OR 1=1')`.
A assinatura exige `int`, mas a função não defende sozinha.
- **Impacto:** Leitura de qualquer chamado; dependendo do caller, bypass de
visibilidade.
- **Severidade:** alta.
- **Confiança:** 95.
- **O que foi feito:** Uso de prepared statement com `id = ?`.

### F6 — Injeção SQL em `tecnicoNome`

- **Onde:** `code/lib.php`, linha 69 (original).
- **Mecanismo:** O `tecnico_id` é concatenado em uma query simples. O parâmetro
é tipado como `?int`, mas a função não usa prepared statement; em cenários de
reuso futuro o risco persiste.
- **Impacto:** Leitura arbitrária via SQL injection secundária.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** Uso de prepared statement com `id = ?`.

### F7 — CSV escrito em arquivo temporário no servidor

- **Onde:** `code/lib.php`, linhas 125–150 (originais).
- **Mecanismo:** `exportarCsv` grava `/var/www/painel/tmp/chamados.csv` em disco
antes de enviá-lo. O arquivo permanece legível no servidor e pode ser acessado
por outros processos ou backups.
- **Impacto:** Vazamento de dados pessoais em disco; dependência de permissões
de diretório.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** Saída direta via `php://output`, eliminando a escrita em
disco. Cabeçalho e ordenação por `id` foram preservados.

### F8 — Segredos hardcoded no código-fonte

- **Onde:** `code/config.php`, linhas 12 e 15 (originais).
- **Mecanismo:** A senha do banco de dados (`N3tX@2013!prod`) e a chave de API
SMTP (`netx-smtp-9f83e2c1a7b64d05`) estavam literalmente no arquivo.
- **Impacto:** Exposição de credenciais de produção no versionamento; risco
associado a vazamento do repositório.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Substituídos por `getenv(...)` com fallback vazio. O
ambiente deve prover as variáveis `DB_PASS` e `SMTP_API_KEY`.

### F9 — Session fixation no login

- **Onde:** `code/index.php`, linha 24 (original).
- **Mecanismo:** Após autenticação bem-sucedida, o ID de sessão não é
regenerado. Um atacante que conheça o ID de sessão pré-login pode assumir a
sessão do usuário autenticado.
- **Impacto:** Sequestro de sessão.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** Adicionado `session_regenerate_id(true)` logo após
preencher `$_SESSION`.

### F10 — Armazenamento de senhas com MD5

- **Onde:** `code/lib.php`, linha 15 (original); `code/schema.sql`, linha 7.
- **Mecanismo:** As senhas são armazenadas como `MD5(senha)` sem salt. MD5 é
rápido e quebrável por força bruta/rainbow tables.
- **Impacto:** Comprometimento em massa se a tabela `usuarios` vazar.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** **Não foi alterado.** A mudança exigiria alterar o
schema (`CHAR(32)`), migrar as senhas dos usuários existentes e invalidar os
dados de teste do `seed.sql`. Isso extrapola a correção evolutiva e quebraria a
compatibilidade imediata. Foi deixado comentário explicativo no código.

---

## Decisões (o que não foi alterado e por quê)

1. **Algoritmo de hash MD5** — Mantido por compatibilidade. Mudar para
   password_hash/bcrypt exigiria migração de senhas e quebra do `seed.sql` e
   dos logins atuais. Recomenda-se tratar como débito técnico em projeto
   separado.

2. **Comportamento de `formatarStatus`** — O manifesto estabelece o contrato de
   valor: valores diferentes de `1` e `2` retornam `"Resolvido"`. Não foi
   alterado, pois o relatório gerencial depende desse mapeamento.

3. **Stack MySQLi e nomes de arquivos** — Preservados conforme as restrições do
   manifesto. Não foram introduzidos ORMs, frameworks ou dependências novas.

4. **Não foi adicionado CSRF token ao login** — Embora desejável, o formulário
   de login comum não realiza ações sensíveis além da própria autenticação; o
   ganho é baixo em comparação com o risco de quebrar integrações simples. Foi
   priorizada a regeneração de ID de sessão.

5. **Regra de visibilidade via `$_SESSION` dentro das funções** — A superfície
   pública (`listarChamados`, `verChamado`, `exportarCsv`) não possui
   parâmetros de usuário/papel. Em vez de quebrar as assinaturas, as funções
   leem a sessão ativa. Scripts CLI sem sessão continuam com comportamento de
   técnico (visão total), o que mantém as rotinas noturnas.

---

## Checklist de entrega

- [x] `code/` alterado in-place, mesmos caminhos e nomes.
- [x] `RELATORIO.md` com mecanismo, severidade e confiança 0–100 por achado.
- [x] `achados.json` válido, com o bloco `leb` copiado do `TAREFA.md`.
- [x] Todo achado do relatório está no JSON, e vice-versa.
- [x] Seção **Decisões** presente.
- [x] Nada do `manifest.md` foi quebrado (assinaturas, rotas, CSV, HTML).
