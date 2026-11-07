# RELATORIO — LEB-100-A — Painel de Chamados (NetX ISP)

## 1. Resumo

Sistema legado em PHP 8 + mysqli + MySQL, em produção desde 2013, mantendo superfície pública consumida por scripts do ISP (rotina noturna de exportação, relatório gerencial, integração de faturamento).

Na entrega foram identificados problemas de segurança, um defeito de regra de negócio (ausência de controle de escopo) e falhas de robustez. As correções foram aplicadas como evolução pontual sobre o contrato existente — nenhuma reescrita foi feita.

## 2. Achados

### F2 — Ausência de controle de escopo nas consultas

- **Onde está:** `code/lib.php`, funções `listarChamados` (linhas 78–93) e `verChamado` (linhas 98–102) na numeração original.
- **O que é:** o manifesto define que cliente só pode ver chamados que ele mesmo abriu; técnico pode ver qualquer chamado. O código não implementava essa regra.
- **Mecanismo:** `listarChamados` executava `SELECT * FROM chamados` sem condição de dono; `verChamado` buscava por `id` sem comparar o dono (`usuario_id`) com o usuário autenticado. Resultado: qualquer usuário autenticado listava e acessava detalhes de chamados alheios.
- **Impacto e severidade:** exposição de dados entre clientes e quebra da regra de negócio. Severidade **alta**.
- **Confiança:** 99.
- **O que foi feito:** filtro por escopo (uid/papel da sessão) nas consultas; cliente recebe apenas chamados com `usuario_id = ?`, técnico sem filtro. Aplicado em listagem, detalhe e exportação, preservando as assinaturas públicas.

### F3 — SQL Injection no filtro de busca por título

- **Onde está:** `code/lib.php`, linha 82 (função `listarChamados`), na numeração original.
- **O que é:** o termo de busca era concatenado diretamente na query.
- **Mecanismo:** `$sql .= " WHERE titulo LIKE '%" . $busca . "%'";` com `$busca` recebendo `$_GET['busca']`.
- **Impacto e severidade:** entrada do usuário podia alterar a query (injeção). Severidade **alta**.
- **Confiança:** 99.
- **O que foi feito:** prepared statement com bind (`LIKE ?` via `'s'`; escopo via `'i'`).

### F1 — Falha na autenticação com MD5

- **Onde está:** `code/lib.php` (função `autenticar`, linhas 15–21), `code/schema.sql` (coluna `senha`) e `code/seed.sql`.
- **O que é:** verificação de senha via `md5($senha)`.
- **Mecanismo:** MD5 é não-cryptográfico, sem salt e sem hash de senha; sem rate limit; coluna `CHAR(32)`.
- **Impacto e severidade:** dump da base → credenciais reversíveis; força bruta offline trivial. Severidade **alta**.
- **Confiança:** 90.
- **O que foi feito:** autenticação com `password_verify`, com fallback para MD5 legado durante migração; coluna alterada para `VARCHAR(255)`; seed atualizado com hash bcrypt.

### F6 — Segredos embutidos no código-fonte

- **Onde está:** `code/config.php`, linhas 12–15.
- **O que é:** credencial de produção com fallback hardcoded e chave SMTP hardcoded.
- **Mecanismo:** `define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');` e `define('SMTP_API_KEY', 'netx-smtp-...');`.
- **Impacto e severidade:** segredos no repositório. Severidade **alta**.
- **Confiança:** 95.
- **O que foi feito:** leitura via `getenv`, com fallback vazio; nomes das constantes preservados.

### F4 — Divisão por zero na média de resposta

- **Onde está:** `code/lib.php`, linha 116 (função `mediaResposta`).
- **Mecanismo:** `$soma / $qtd` sem verificação; com zero registros com resposta, ocorre divisão inválida.
- **Impacto e severidade:** baixo/médio. Severidade **baixa**.
- **Confiança:** 95.
- **O que foi feito:** retorno de `0.0` quando não há respondidos.

### F5 — Rótulo de status inválido tratado como "Resolvido"

- **Onde está:** `code/lib.php`, linhas 33–35 (função `formatarStatus`).
- **Mecanismo:** ramo `else` final aceitava qualquer status diferente de 1/2 como resolvido.
- **Impacto e severidade:** baixo. Severidade **baixa**.
- **Confiança:** 90.
- **O que foi feito:** comparações explícitas (`===`) com retorno `Desconhecido` para valores fora do contrato.

### F7 — Consultas adicionais na listagem/exportação (N+1)

- **Onde está:** `code/lib.php`, função `tecnicoNome` (linhas 64–72) e chamadas no loop (linhas 89–91), na numeração original.
- **O que é:** busca do nome do técnico executada uma vez por chamado.
- **Mecanismo:** `tecnicoNome` abria query dentro do loop de listagem/export.
- **Impacto e severidade:** baixo. Severidade **baixa**.
- **Confiança:** 85.
- **O que foi feito:** `JOIN LEFT` para trazer `tecnico_nome` em única consulta.

## 3. Decisões

- **Não alterado:** stack (PHP/mysqli/MySQL), camada de acesso a dados, nomes e assinaturas de funções, rotas, formatos de saída (CSV, rótulos), estrutura HTML e regras de `rotuloPrioridade`.
- **Não alterado:** rate limit, logout, proteção CSRF e flags de cookie de sessão — adiados por escopo/risco de quebrar integrações.
- **Não alterado:** textos/semântica de `rotuloPrioridade` (comportamento de produto considerado aceito).

## 4. Checklist antes de entregar

- [x] `code/` alterado in-place, mesmos caminhos e nomes.
- [x] `RELATORIO.md` com mecanismo, severidade e confiança 0–100 por achado.
- [x] `achados.json` válido, com o bloco `leb` copiado do topo do `TAREFA.md`.
- [x] Todo achado do relatório está no JSON, e vice-versa.
- [x] A seção **Decisões** diz o que não mudou e por quê.
- [x] Nada do `manifest.md` foi quebrado (assinaturas, rotas, formatos e regras preservados).
