# RELATÓRIO TÉCNICO — LEB-100-A

## Resumo

O sistema é um painel de chamados simples em PHP procedural sobre `mysqli`. A
superfície pública (funções de `lib.php`, rotas de `index.php`, formato do CSV e
estrutura HTML da listagem) está documentada em `manifest.md` e foi preservada.

Funcionalmente o código atende ao contrato, mas carrega dívidas técnicas e
vulnerabilidades típicas de aplicações legadas: injeção SQL em três funções,
quebra da regra de visibilidade cliente/técnico, XSS refletido, sessão sem
regeneração pós-login, divisão por zero no indicador de SLA, exportação de CSV
gravada em disco e uso de MD5 para senhas. As correções focaram em eliminar os
riscos de segurança e robustez sem alterar assinaturas públicas, formatos de
saída ou a stack de acesso a dados.

---

## Achados (ordem de prioridade para correção)

### F1 — Injeção SQL em `listarChamados` via parâmetro `busca`

- **Onde:** `code/lib.php`, linha 82 (original).
- **Mecanismo:** A string `$busca` é concatenada diretamente na cláusula
  `WHERE titulo LIKE '%" . $busca . "%'`, sem prepared statement. Um payload
  como `'; DROP TABLE chamados; --` (ou variações que escapem as aspas simples)
  é interpretado pelo MySQL.
- **Impacto:** Leitura, alteração ou destruição arbitrária do banco; vazamento
  de chamados de outros clientes.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** Reescrita com prepared statement e `bind_param('s', $like)`.

### F2 — Injeção SQL em `verChamado`

- **Onde:** `code/lib.php`, linha 100 (original).
- **Mecanismo:** `$id` é concatenado em `'SELECT * FROM chamados WHERE id = ' . $id`.
  Embora `index.php` faça cast para `int`, a função é pública e pode ser chamada
  por scripts internos sem essa proteção.
- **Impacto:** Leitura arbitrária de chamados; escalada para outras injeções se
  combinada com trechos vulneráveis.
- **Severidade:** crítica.
- **Confiança:** 100.
- **O que foi feito:** Reescrita com prepared statement e `bind_param('i', $id)`.

### F3 — Injeção SQL em `tecnicoNome`

- **Onde:** `code/lib.php`, linha 69 (original).
- **Mecanismo:** `$tecnicoId` é concatenado diretamente em
  `'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`.
- **Impacto:** Via `listarChamados` ou `exportarCsv`, um valor manipulado no
  campo `tecnico_id` (se a integridade referencial for contornada) permite
  injeção SQL.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Reescrita com prepared statement e `bind_param('i', $tecnicoId)`.

### F6 — Quebra da regra de visibilidade (listagem e detalhe)

- **Onde:** `code/index.php`, linhas 52–53 e 72–73 (original).
- **Mecanismo:** `verChamado` e `listarChamados` não recebem contexto do usuário
  logado, e `index.php` não filtra os resultados. Um cliente autenticado pode
  acessar `index.php?ver=103` (chamado de outro cliente) e vê-lo, bem como ver
  todos os chamados na listagem.
- **Impacto:** Violação de privacidade; cliente visualiza chamados alheios.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Em `index.php`, após carregar o chamado, se
  `$papel === 'cliente'` e `$c['usuario_id'] !== $uid`, o chamado é tratado como
  inexistente. Na listagem, o array é filtrado para conter apenas os chamados do
  cliente logado. As funções de `lib.php` foram preservadas para não quebrar
  scripts externos que chamam `listarChamados($db)` esperando todos os registros.

### F7 — Exportação CSV acessível a clientes

- **Onde:** `code/index.php`, linha 44 (original).
- **Mecanismo:** A rota `index.php?export=csv` chama `exportarCsv($db)`, que
  sempre exporta **todos** os chamados. Não há verificação de papel, então um
  cliente baixa os chamados de todos os clientes.
- **Impacto:** Vazamento em massa de dados.
- **Severidade:** alta.
- **Confiança:** 95.
- **O que foi feito:** Adicionada verificação em `index.php`: se o papel não for
  `tecnico`, retorna HTTP 403. A função `exportarCsv` em si não foi alterada,
  mantendo compatibilidade com scripts internos/CLI que a consomem diretamente.

### F5 — XSS refletido no campo de busca

- **Onde:** `code/index.php`, linhas 79 e 82 (original).
- **Mecanismo:** `$_GET['busca']` é reimpresso no `value` do input e no
  parágrafo "Resultados para:" sem escaping. Um payload como
  `<script>alert(document.cookie)</script>` executa no contexto autenticado do
  painel.
- **Impacto:** Roubo de sessão, ações em nome do usuário.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Aplicado `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')`
  em ambas as saídas.

### F4 — Divisão por zero em `mediaResposta`

- **Onde:** `code/lib.php`, linha 116 (original).
- **Mecanismo:** Se nenhum chamado tiver `minutos_resposta IS NOT NULL`, `$qtd`
  é 0 e a expressão `$soma / $qtd` gera warning e retorna `INF`.
- **Impacto:** Quebra do indicador "Tempo médio de 1ª resposta" no topo da
  página; comportamento instável.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** Retorna `0.0` quando `$qtd === 0`.

### F8 — CSV escrito em disco antes do download

- **Onde:** `code/lib.php`, linhas 125–150 (original).
- **Mecanismo:** A função grava `/var/www/painel/tmp/chamados.csv` e depois faz
  `readfile`. Se `EXPORT_DIR` não existir ou não tiver permissão, a exportação
  falha silenciosamente. Também deixa um arquivo temporário com dados
  potencialmente sensíveis no filesystem.
- **Impacto:** Indisponibilidade da exportação e possível vazamento residual.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** Stream direto para `php://output`, eliminando a
  dependência do diretório em disco e do arquivo residual.

### F9 — Session fixation no login

- **Onde:** `code/index.php`, linhas 22–27 (original).
- **Mecanismo:** Após autenticação bem-sucedida, o ID de sessão não é
  regenerado. Se um atacante conhecer o session ID pré-login (ex.: via cookie
  fixado), ele continua válido após o usuário se autenticar.
- **Impacto:** Hijack de sessão.
- **Severidade:** média.
- **Confiança:** 95.
- **O que foi feito:** Adicionado `session_regenerate_id(true)` após popular
  `$_SESSION` e antes do redirecionamento.

### F10 — Hash de senha MD5

- **Onde:** `code/lib.php`, linha 15 (original).
- **Mecanismo:** `autenticar` usa `md5($senha)` para comparar com o banco. MD5
  é rápido e reversível por força bruta/rainbow tables.
- **Impacto:** Comprometimento em massa de credenciais se o banco vazar.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Não alterei. O schema legado define `senha CHAR(32)` e os
  dados de teste usam MD5. Migrar para `password_hash` exigiria alterar a coluna
  para acomodar hashes bcrypt e invalidar/redefinir todas as senhas existentes,
  quebrando a compatibilidade com consumidores que inserem ou verificam hashes
  MD5. Registrei como débito técnico a ser tratado em migração de segurança.

### F11 — Segredos hardcoded em `config.php`

- **Onde:** `code/config.php`, linhas 12 e 15 (original).
- **Mecanismo:** `DB_PASS` e `SMTP_API_KEY` possuem valores padrão embutidos no
  código-fonte.
- **Impacto:** Vazamento de credenciais de produção se o repositório for
  exposto.
- **Severidade:** alta.
- **Confiança:** 100.
- **O que foi feito:** Não alterei. O ambiente de teste/empacotamento pode
  depender dos fallbacks. A correção correta é remover os defaults e exigir que
  todos os valores venham de variáveis de ambiente, mas isso pode inviabilizar a
  execução local sem configuração adicional. Indiquei como ação de hardening
  fora do escopo imediato.

### F12 — N+1 na resolução do nome do técnico

- **Onde:** `code/lib.php`, linhas 78–93 e 123–151 (original).
- **Mecanismo:** `listarChamados` e `exportarCsv` chamam `tecnicoNome` uma vez
  para cada chamado, gerando uma query extra por registro.
- **Impacto:** Latência crescente com o número de chamados; carga desnecessária
  no banco.
- **Severidade:** média.
- **Confiança:** 90.
- **O que foi feito:** Não alterei. A assinatura pública não impede um cache
  interno, mas optei por manter a mudança mínima. A correção ideal é carregar
  todos os técnicos em uma única query e mapear por `id` antes do loop.

### F13 — `formatarStatus` mapeia valores inválidos para "Resolvido"

- **Onde:** `code/lib.php`, linhas 26–35 (original).
- **Mecanismo:** O ramo `else` retorna `"Resolvido"` para qualquer status
  diferente de 1 e 2, incluindo 0, 4, etc.
- **Impacto:** Relatório gerencial pode classificar chamados com status
  corrupto como resolvidos.
- **Severidade:** baixa.
- **Confiança:** 80.
- **O que foi feito:** Não alterei. O manifesto só define os três mapeamentos
  válidos; mudar o fallback poderia quebrar consumidores que, porventura,
  dependam de receber um dos três rótulos mesmo com dados inconsistentes.

---

## Decisões (o que deliberadamente não foi alterado e por quê)

1. **MD5 de senhas (F10):** migrar para `password_hash` exigiria mudança de
   schema e invalidação das senhas atuais. Fora do escopo sem quebrar
   compatibilidade.
2. **Segredos hardcoded (F11):** não removi os fallbacks para não inviabilizar
   a execução em ambientes sem variáveis de ambiente configuradas. Recomendo
   ação de hardening separada.
3. **N+1 de técnicos (F12):** mantido para preservar simplicidade; pode ser
   otimizado futuramente sem alterar a superfície pública.
4. **Fallback de `formatarStatus` (F13):** mantido para evitar que relatórios
   externos recebam rótulos inesperados em caso de dados inconsistentes.
5. **Autorização na função `exportarCsv`:** a filtragem por papel foi colocada
   apenas em `index.php`. A função continua exportando todos os chamados para
   não quebrar scripts internos/CLI que a chamam diretamente.
6. **Autorização nas funções `listarChamados` e `verChamado`:** as assinaturas
   públicas não recebem contexto de usuário (conforme manifesto). A regra de
   visibilidade foi implementada no controlador `index.php`, preservando o
   comportamento para scripts externos.
7. **Stack `mysqli` e nomes de arquivos:** preservados conforme as restrições
   do manifesto.

---

## Verificação

- `php -l code/config.php`, `php -l code/index.php` e `php -l code/lib.php`
  executados sem erros de sintaxe.
- Todas as assinaturas de funções do `manifest.md` foram mantidas.
- Rotas, nomes de parâmetros GET, cabeçalho e colunas do CSV, e estrutura HTML
  da listagem (`id="tabela-chamados"`, colunas e links) permanecem inalterados.
