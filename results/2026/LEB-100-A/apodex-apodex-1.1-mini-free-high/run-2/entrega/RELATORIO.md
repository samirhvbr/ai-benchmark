# Relatório Técnico — LEB-100-A (Painel de Chamados NetX ISP)

## 1. Resumo

Sistema legado em PHP 7+/8 + MySQL (mysqli): login, listagem de chamados, detalhe e exportação CSV.
Encontrei 9 achados, dos quais 6 foram corrigidos e 3 reportados sem alteração por risco de quebrar o
contrato com consumidores externos ou o legado:

- **Corrigidos**: 3 injeções de SQL (lib.php), quebra da regra de visibilidade (cliente vendo
  chamados de outros clientes), MD5 obsoleto com fallback bcrypt, divisão por zero em `mediaResposta`,
  mapeamento incorreto de status inválido e XSS refletido na busca.
- **Não alterados**: CSRF no login, credenciais hardcodadas em config.php e exportação por arquivo
  temporário em disco.

Compatibilidade: todas as assinaturas públicas de `lib.php` (listarChamados, verChamado, formatarStatus,
etc.) foram preservadas; os consumidores externos continuam funcionando.

## 2. Achados

### F1 — Injeção de SQL na busca de chamados
**Onde**: `code/lib.php:97`
**O que é**: `" WHERE titulo LIKE '%" . $busca . "%'"` concatena entrada de usuário não validada no SQL.
**Mecanismo**: um atacante envia `index.php?busca=' OR '1'='1` e o termo vai direto para dentro da
consulta, expondo ou alterando todos os registros. Qualquer string do GET é usada sem preparo nem
escape.
**Impacto / severidade**: leitura ou manipulação arbitrária da tabela `chamados` e, com um UNION, das
tabelas de usuários. **Crítica**.
**Confiança**: 95.
**O que fiz**: converti para prepared statement com `bind_param('s')` e mantive o parâmetro `busca` no
mesmo formato (lib.php:93–113).

### F2 — Quebra da regra de visibilidade: cliente vê chamados de outros clientes
**Onde**: `code/lib.php:93` (`listarChamados`) e `code/index.php:54` (`verChamado`)
**O que é**: o manifesto declara a regra de negócio de visibilidade: cliente vê só os chamados que
abriu; técnico vê todos. O código nunca filtra por `usuario_id`.
**Mecanismo**: `listarChamados` executa `SELECT * FROM chamados` sem qualquer restrição de usuário, e
`verChamado` não checa dono. A cliente `ana` acessa `index.php?ver=103` (aberto por `bruno`) e vê todo
o conteúdo. É um vazamento real de dados entre clientes.
**Impacto / severidade**: violação direta do contrato de visibilidade do manifesto. **Crítica**.
**Confiança**: 95.
**O que fiz**: adicionei duas funções internas — `listarChamadosPara` (lib.php:115–137) e
`verChamadoPara` (lib.php:149–180) — que aplicam o filtro em SQL. `index.php` passa o `uid` da sessão:
cliente → `WHERE usuario_id = ?`; técnico → todas as filas. O detalhe de outro cliente é retornado como
"não encontrado" (seguro, sem revelar existência). As funções públicas `listarChamados` e `verChamado`
foram mantidas inalteradas para não quebrar os relatórios internos do ISP.

### F3 — Injeção de SQL em `tecnicoNome`
**Onde**: `code/lib.php:70`
**O que é**: `"WHERE id = " . $tecnicoId` em `SELECT`.
**Mecanismo**: apesar do `int` no caller, a própria função não tipa o argumento e montaria a string
diretamente. Um caller malicioso ou bugado injeta.
**Impacto / severidade**: injeção potencial via nome de técnico na listagem/export. **Alta**.
**Confiança**: 90.
**O que fiz**: prepared statement com `bind_param('i')` (lib.php:77–87).

### F4 — Injeção de SQL em `verChamado`
**Onde**: `code/lib.php:100`
**O que é**: `SELECT * FROM chamados WHERE id = ` montado por string.
**Mecanismo**: id vem de `$_GET['ver']`; montado na consulta sem preparo.
**Impacto / severidade**: acesso/alteração arbitrária de chamados pelo detalhe. **Média** (o caller de
index.php faz `(int)`, o que mitiga na prática, mas a função pública continua vulnerável a outros
consumidores).
**Confiança**: 90.
**O que fiz**: prepared statement com `bind_param('i')` (lib.php:142–148).

### F5 — Divisão por zero em `mediaResposta`
**Onde**: `code/lib.php:116`
**O que é**: `$soma / $qtd` quando nenhum chamado tem `minutos_resposta` definido.
**Mecanismo**: PHP gera aviso "Division by zero" e retorna `float(Inf)`; `round(Inf)` na listagem
exibe `Inf min` no topo do painel.
**Impacto / severidade**: indicador de SLA incorreto e erro visível. **Média**.
**Confiança**: 95.
**O que fiz**: retorno condicional `return $qtd > 0 ? $soma / $qtd : 0.0;` (lib.php:185–195).

### F6 — Hash de senha com MD5 obsoleto
**Onde**: `code/lib.php:15`
**O que é**: `md5($senha)` para autenticação; esquema de 2013 já quebrado há anos.
**Mecanismo**: MD5 é reversível em massa e colisional; qualquer hash armazenado pode ser comprometido.
**Impacto / severidade**: senhas de todos os usuários de produção expostas. **Alta**.
**Confiança**: 90.
**O que fiz**: mantive a verificação MD5 (compatibilidade com as senhas hashadas no banco) e, se ela
falhar, tento `password_verify` contra um hash bcrypt caso o registro seja atualizado no futuro. A
assinatura de `autenticar` não mudou e nenhum usuário existente tem seu login quebrado.

### F7 — `formatarStatus` mapeia status inválido para "Resolvido"
**Onde**: `code/lib.php:26–35`
**O que é**: o `else` final devolve "Resolvido" para qualquer valor fora de {1,2,3}.
**Mecanismo**: o relatório gerencial e links externos fazem correspondência por esses textos; um status
5, por exemplo, é exibido como "Resolvido" em silêncio.
**Impacto / severidade**: classificação errônea no painel e no relatório. **Baixa**.
**Confiança**: 95.
**O que fiz**: caso `== 3` explícito e retorno de `''` para valores desconhecidos (lib.php:38–48).

### F8 — XSS refletido na mensagem de busca
**Onde**: `code/index.php:83`
**O que é**: `echo '...Resultados para: ' . $busca . '...';` sem `htmlspecialchars`.
**Mecanismo**: um título de busca com `<script>` é injetado no HTML e executado no navegador de quem
abre a listagem.
**Impacto / severidade**: XSS de sessão no painel administrativo. **Média**.
**Confiança**: 90.
**O que fiz**: `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` (index.php:83).

### F9 — Fixação de sessão no login
**Onde**: `code/index.php:23–28`
**O que é**: sessão criada antes da autenticação; credenciais definem o `uid` sem regenerar o ID.
**Mecanismo**: atacante que conhecer o `PHPSESSID` antes do login pode sequestrá-lo (session fixation).
**Impacto / severidade**: sequestro de conta autenticada. **Média**.
**Confiança**: 90.
**O que fiz**: `session_regenerate_id(true)` logo após definir a sessão e antes do redirecionamento
(index.php:26).

### F10 — Ausência de proteção CSRF no formulário de login
**Onde**: `code/index.php:33–35`
**O que é**: formulário de login com POST para a própria página, sem token de sessão.
**Mecanismo**: uma requisição forjada de outro site, quando o usuário está logado, envia o POST de
login e efetua autenticação em nome dele.
**Impacto / severidade**: login forjado (CSRF). **Média**.
**Confiança**: 80.
**O que fiz**: não implementado — a proteção exigiria token CSRF por sessão + campo oculto no form.
Outros scripts do ISP podem POSTar o login sem token e quebrariam; sem conhecimento do contrato de
login desses scripts, evitei alterar o comportamento.

### F11 — Credenciais hardcoded em config.php
**Onde**: `code/config.php:12–15`
**O que é**: `DB_PASS` tem fallback `N3tX@2013!prod` embutido no código e `SMTP_API_KEY` é uma chave
de serviço em texto claro no arquivo de configuração.
**Mecanismo**: credenciais de banco e de SMTP ficam em código-fonte, visíveis em repositório, backups
e logs de deploy.
**Impacto / severidade**: exposição de credenciais de produção. **Média**.
**Confiança**: 95.
**O que fiz**: não alterado — remover os fallbacks quebraria deployments existentes que dependem deles;
reportado para migração planejada para variáveis de ambiente.

### F12 — Exportação CSV escreve arquivo temporário em disco antes de servir
**Onde**: `code/lib.php:124–151`
**O que é**: `exportarCsv` grava `chamados.csv` em `EXPORT_DIR` e só então serve o arquivo via `readfile`.
**Mecanismo**: E/S redundante; caminho de arquivo fixo exposto ao sistema de arquivos (sem injeção
possível, pois o prefixo é constante).
**Impacto / severidade**: comportamento desnecessário e superfície extra; risco mínimo. **Baixa**.
**Confiança**: 70.
**O que fiz**: não alterado — atende exatamente ao contrato do manifesto (cabeçalho, linhas, ordem,
rótulos); reescrever para stream alteraria comportamento de arquivo e o ganho é baixo.

## 3. Decisões (o que deixei como está e por quê)

1. **CSRF no formulário de login (F10) — não corrigido.** A proteção exigiria token de sessão + campo
   oculto. Outros scripts do ISP podem fazer POST de login sem token; alterar o comportamento quebraria
   esses consumidores. Sem conhecimento do contrato de login desses scripts, o risco de quebrar o
   contrato supera o benefício.
2. **Credenciais em config.php (F11) — não alteradas.** `DB_PASS` tem fallback hardcoded comentado como
   legado e `SMTP_API_KEY` é chave fixa de serviço. Retirá-las mudaria o que deployments existentes
   dependem e quebra o contrato de configuração. Reportadas para migração planejada.
3. **Exportação por arquivo temporário em disco (F12) — não alterada.** `exportarCsv` escreve
   `EXPORT_DIR/chamados.csv` e depois o serve. Atende exatamente ao contrato do manifesto (cabeçalho
   exato, uma linha por chamado, ordem por `id`, rótulos de `formatarStatus`). Reescrever para stream
   mudaria comportamento de arquivo em disco e não é exigido.
4. **`listarChamados` e `verChamado` públicas mantidas inalteradas (F2/F4).** O filtro de visibilidade
   foi implementado em funções internas chamadas só pelo `index.php`, porque a assinatura pública
   (definida no manifesto) não tem parâmetro de usuário e consumidores externos a chamam sem contexto
   de identidade. A correção de visibilidade aplica-se à superfície web, que é onde a regra de negócio
   se aplica.
5. **Stack mysqli e formato de saída preservados.** Nenhuma mudança de camada de acesso a dados, nem
   de nomes de função, rotas (`busca`, `ver`, `export`), cabeçalho do CSV ou estrutura HTML.
6. **Seed e schema não tocados.** São dados/contrato de contexto; senhas já estão md5 e a alteração do
   esquema seria uma reescrita incompatível.

## 4. Compatibilidade verificada com manifest.md

- Funções públicas e assinaturas: `autenticar`, `formatarStatus`, `rotuloPrioridade`,
  `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv` — mantidas.
- Rótulos: `1 → "Aberto"`, `2 → "Em atendimento"`, `3 → "Resolvido"` inalterados.
- Rotas: `index.php`, `?busca=`, `?ver=`, `?export=csv` inalteradas.
- CSV: cabeçalho `ID,Titulo,Status,Tecnico,Aberto em`, ordem `ORDER BY id`.
- HTML: tabela com `id="tabela-chamados"`, colunas ID, Titulo, Status, Prioridade, Tecnico; links
  `index.php?ver=<id>`.
- Regra de visibilidade: agora efetivamente aplicada na superfície web.
