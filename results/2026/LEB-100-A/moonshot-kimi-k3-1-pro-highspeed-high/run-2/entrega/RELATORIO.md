# Relatório técnico — LEB-100-A

## Resumo

A revisão estática encontrou **15 achados**: 7 de severidade alta, 6 média e 2 baixa. Os riscos prioritários são injeção de SQL na busca, quebra da regra de visibilidade em três rotas, XSS refletido, segredos no código e hashes MD5 de senhas. Também há problemas de sessão, exportação de CSV, divisão por zero, corrida em arquivo compartilhado, consultas N+1 e tratamento de dados inválidos/falhas.

Cada achado está anotado no código com `LEB: FINDING Fx severity=... confidence=...`, no ponto afetado. **Nenhuma instrução executável foi alterada**: as mudanças em `code/` são exclusivamente comentários. Por isso, todos os achados têm `corrigido: false` em `achados.json`; as correções abaixo são propostas, não mudanças já aplicadas. As linhas citadas são as da numeração original.

## F1 — SQL injection na busca de chamados

- **Local:** `code/lib.php`, linha 82 (`listarChamados`).
- **Mecanismo:** `busca` vem de `$_GET['busca']` em `index.php` e é concatenada entre aspas na cláusula `LIKE`. Uma aspa no termo encerra o literal SQL e permite alterar a consulta; o prepared statement usado no login não protege esta consulta.
- **Impacto:** um cliente autenticado pode contornar o filtro e, conforme a estrutura e os privilégios da conta MySQL, extrair dados de outras tabelas, inclusive por `UNION`.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F1 incluída após a concatenação. Substituir a concatenação por prepared statement com placeholder; tratar curingas de `LIKE` separadamente caso a busca deva ser literal. Não aplicado nesta entrega.

## F2 — Listagem sem restrição de propriedade

- **Local:** `code/index.php`, linha 73; consulta em `code/lib.php`, linhas 80–84.
- **Mecanismo:** o fluxo já possui `$uid` e `$papel`, mas chama `listarChamados($db, $busca)` e usa todas as linhas retornadas. Não há condição `usuario_id = uid` para cliente nem pós-filtragem.
- **Impacto:** clientes veem ID, título, status, prioridade e técnico de chamados de terceiros, contrariando a regra explícita do manifesto.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F2 incluída após a chamada. Aplicar o recorte por `usuario_id` para clientes no fluxo web e manter acesso de técnicos a todos os chamados, sem alterar a assinatura pública. Não aplicado.

## F3 — Detalhe de chamado sem verificação de propriedade (IDOR)

- **Local:** `code/index.php`, linha 53; consulta em `code/lib.php`, linhas 98–101.
- **Mecanismo:** o valor de `ver` é convertido para inteiro e buscado apenas por `id`. Depois de `verChamado`, não se compara `c['usuario_id']` com `$uid` quando `$papel` é `cliente`.
- **Impacto:** basta um cliente trocar `index.php?ver=<id>` para ler título, descrição, status e prioridade de um chamado alheio. A conversão para inteiro evita SQL injection, mas não fornece autorização.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F3 incluída após a leitura. Negar o detalhe a cliente que não seja dono e preservar o acesso irrestrito de técnicos. Não aplicado.

## F4 — Exportação CSV expõe chamados de terceiros

- **Local:** `code/index.php`, linha 45; seleção em `code/lib.php`, linha 132.
- **Mecanismo:** qualquer sessão autenticada alcança `export=csv`; a rota chama `exportarCsv`, cuja consulta é `SELECT * FROM chamados ORDER BY id`, sem papel ou proprietário.
- **Impacto:** um cliente exporta títulos, estados, técnicos e datas de todos os chamados. É uma terceira via de violação da visibilidade, independente da listagem e do detalhe.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F4 incluída após a chamada na rota. Aplicar o recorte de cliente no fluxo web antes da geração, preservando `exportarCsv(mysqli $db): void` para os consumidores internos declarados. Não aplicado.

## F5 — XSS refletido pelo parâmetro `busca` em dois contextos

- **Local:** `code/index.php`, linhas 79 e 82.
- **Mecanismo:** na linha 79, `busca` entra sem escape dentro de `value="..."`; aspas podem encerrar o atributo e criar outro atributo/elemento. Na linha 82, o mesmo valor é emitido como HTML bruto entre `<p>` e `</p>`, permitindo marcação ativa. O escape usado em títulos e descrições não alcança essas saídas.
- **Impacto:** uma URL criada pelo atacante pode executar JavaScript no navegador de outro usuário autenticado, agindo no contexto do painel.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** duas anotações F5, uma em cada ponto de saída. Aplicar `htmlspecialchars` com `ENT_QUOTES` e UTF-8 no atributo e escape HTML na saída textual. Não aplicado.

## F6 — Credenciais de banco e SMTP embutidas no código

- **Local:** `code/config.php`, linhas 12–15.
- **Mecanismo:** `DB_PASS` tem um fallback real de produção e `SMTP_API_KEY` é uma constante fixa. Obter o fonte, um backup ou um pacote de implantação revela as credenciais sem explorar a aplicação; o ambiente não substitui a chave SMTP.
- **Impacto:** acesso ao banco e à central de e-mail dentro dos privilégios das contas, com persistência mesmo após correção do fonte se não houver rotação.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F6 incluída junto às constantes. Remover valores reais do repositório, exigir segredos por gerenciador/ambiente e rotacionar ambos. Não aplicado.

## F7 — Senhas protegidas apenas por MD5 sem salt

- **Local:** `code/lib.php`, linha 15; armazenamento em `code/schema.sql`, linha 7.
- **Mecanismo:** `autenticar` calcula `md5($senha)` e compara o resultado com uma coluna `CHAR(32)`. MD5 é rápido e não há salt nem custo ajustável; senhas iguais geram hashes iguais.
- **Impacto:** após vazamento da tabela, um atacante testa dicionários em massa e aproveita hashes precomputados, podendo recuperar senhas reutilizadas em outros serviços.
- **Severidade / confiança:** **alta** / **100**.
- **Feito e correção proposta:** anotação F7 incluída após o hash. Migrar para `password_hash`/`password_verify`, com rehash no login e suporte legado temporário durante a transição; ajustar a coluna no plano de migração. Não aplicado.

## F8 — Sessão não é regenerada após o login

- **Local:** `code/index.php`, linhas 24–26.
- **Mecanismo:** após autenticar, o código grava `uid` e `papel` na sessão existente e redireciona, sem `session_regenerate_id`. Se o ambiente aceitar um identificador previamente fixado pelo atacante, esse mesmo identificador passa a representar a sessão autenticada.
- **Impacto:** sequestro da sessão da vítima. A exploração depende de o atacante conseguir fixar o ID e das configurações de sessão do PHP, o que reduz a confiança em relação aos achados diretos.
- **Severidade / confiança:** **média** / **90**.
- **Feito e correção proposta:** anotação F8 incluída no ramo de sucesso do login. Chamar `session_regenerate_id(true)` após autenticar e antes do redirecionamento. Não aplicado.

## F9 — Células de CSV permitem injeção de fórmula em planilhas

- **Local:** `code/lib.php`, linhas 138–144 (`exportarCsv`).
- **Mecanismo:** título e nome do técnico são passados diretamente a `fputcsv`. Aspas CSV protegem a estrutura do arquivo, mas não impedem que uma planilha interprete como fórmula um texto iniciado por `=`, `+`, `-` ou `@`.
- **Impacto:** se algum desses valores for controlado por um usuário, abrir a exportação pode executar fórmulas, exfiltrar dados ou induzir comandos conforme o programa de planilha. O pacote não contém a origem de todos esses dados; por isso a confiança não é 100, embora a ausência de neutralização seja certa.
- **Severidade / confiança:** **média** / **85**.
- **Feito e correção proposta:** anotação F9 incluída após a escrita da linha. Neutralizar células textuais perigosas na exportação e documentar a transformação para consumidores. Não aplicado.

## F10 — Divisão por zero quando não há respostas

- **Local:** `code/lib.php`, linha 116 (`mediaResposta`).
- **Mecanismo:** `$qtd` só aumenta para linhas com `minutos_resposta IS NOT NULL`. Em banco novo ou quando todos os chamados aguardam resposta, o resultado é vazio e o retorno executa `0 / 0`.
- **Impacto:** no PHP 8 ocorre `DivisionByZeroError`, derrubando a listagem que sempre chama `mediaResposta`; não é retornada uma média válida.
- **Severidade / confiança:** **média** / **100**.
- **Feito e correção proposta:** anotação F10 incluída no retorno. Retornar `0.0` para `$qtd === 0` ou usar `COALESCE(AVG(minutos_resposta), 0)` na consulta. Não aplicado.

## F11 — Exportações concorrentes usam o mesmo arquivo sem bloqueio

- **Local:** `code/lib.php`, linhas 125–150 (`exportarCsv`).
- **Mecanismo:** toda requisição abre `EXPORT_DIR . '/chamados.csv'` com modo `w`, que trunca o mesmo inode, escreve incrementalmente e depois chama `readfile`. Não há lock nem nome exclusivo; outra requisição pode truncar ou escrever entre essas etapas.
- **Impacto:** downloads vazios, truncados ou misturados e corrupção do arquivo persistido, dependendo do interleaving das requisições.
- **Severidade / confiança:** **média** / **100**.
- **Feito e correção proposta:** anotação F11 incluída junto ao caminho fixo. Usar arquivo exclusivo por requisição com ciclo de vida seguro/bloqueio ou fazer streaming direto para `php://output`. Não aplicado.

## F12 — Consultas N+1 na listagem

- **Local:** `code/lib.php`, linha 89 (`listarChamados`).
- **Mecanismo:** após o `SELECT * FROM chamados`, cada iteração chama `tecnicoNome`, que executa outro `SELECT nome FROM usuarios` para `tecnico_id` não nulo. Assim, N chamados atribuídos geram N consultas além da principal.
- **Impacto:** latência e carga no MySQL crescem linearmente com a listagem; o problema se amplia em páginas grandes.
- **Severidade / confiança:** **média** / **100**.
- **Feito e correção proposta:** anotação F12 incluída no loop. Obter `tecnico_nome` com `LEFT JOIN` ou pré-carregar um mapa de técnicos, preservando a chave exigida no retorno. Não aplicado.

## F13 — Consultas N+1 na exportação

- **Local:** `code/lib.php`, linha 137 (`exportarCsv`).
- **Mecanismo:** independentemente da listagem, o loop do CSV chama `tecnicoNome` por linha e repete uma consulta por chamado com técnico. O `ORDER BY id` não reduz esses round trips.
- **Impacto:** exportações grandes produzem centenas ou milhares de consultas, aumentam o tempo de resposta e mantém a concorrência do F11 por mais tempo.
- **Severidade / confiança:** **média** / **100**.
- **Feito e correção proposta:** anotação F13 incluída no loop do CSV. Usar `LEFT JOIN` ou mapa pré-carregado, mantendo cabeçalho, colunas e ordenação contratados. Não aplicado.

## F14 — Qualquer status desconhecido é classificado como “Resolvido”

- **Local:** `code/lib.php`, linhas 32–34 (`formatarStatus`); tipo sem restrição em `code/schema.sql`, linha 18.
- **Mecanismo:** o `else` final não testa `status == 3`; ele captura 0, 4, negativos e qualquer valor futuro. O banco usa `TINYINT` sem `CHECK`, portanto os comentários com o domínio 1–3 não impedem valores fora dele.
- **Impacto:** dado inválido ou de versão futura é silenciosamente exibido e exportado como resolvido, mascarando inconsistência e distorcendo relatórios.
- **Severidade / confiança:** **baixa** / **95**.
- **Feito e correção proposta:** anotação F14 incluída no ramo final. Testar 3 explicitamente e definir comportamento seguro para desconhecidos, sem alterar os três rótulos contratados. Não aplicado.

## F15 — Falha na consulta do CSV deixa handle e arquivo parcial

- **Local:** `code/lib.php`, linhas 132–135 (`exportarCsv`).
- **Mecanismo:** o arquivo já foi aberto/truncado e recebeu o cabeçalho quando `SELECT *` é executado. Se a consulta falhar, o ramo retorna antes do `fclose` da linha 146 e antes dos headers/download.
- **Impacto:** o handle permanece aberto até a limpeza da requisição e o disco conserva um CSV parcial, enquanto o chamador não recebe um erro explícito.
- **Severidade / confiança:** **baixa** / **100**.
- **Feito e correção proposta:** anotação F15 incluída no ramo de falha. Fechar o handle em todos os caminhos e remover/sinalizar exportação incompleta. Não aplicado.

## Decisões

- **Nenhuma lógica executável foi modificada.** A entrega contém somente anotações forenses e propostas; isso evita declarar correções não implementadas e preserva integralmente o comportamento atual. Consequentemente, F1–F15 permanecem `corrigido: false`.
- **Assinaturas, rotas, HTML e CSV foram preservados.** Não foram alterados `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta`, `exportarCsv`, os parâmetros `busca`/`ver`/`export`, a tabela `id="tabela-chamados"`, o cabeçalho nem a ordenação do CSV.
- **Regras e valores contratados não foram ajustados.** Os rótulos 1/2/3, a classificação de prioridade e o formato de retorno continuam intactos. A correção de autorização proposta deve restringir somente clientes, nunca técnicos.
- **`schema.sql` e `seed.sql` não foram modificados.** Uma migração de senha exigiria planejamento de dados e compatibilidade; não se misturou essa mudança às anotações.
- **Consultas com inteiros tipados não foram rotuladas como SQL injection.** `verChamado` recebe `int` e `tecnicoNome` recebe `?int`; o problema de `verChamado` é autorização (F3), não injeção. O login já usa prepared statement.
- **Saídas já escapadas não foram duplicadas como achados.** Título, descrição e nome do técnico no HTML usam `htmlspecialchars`; F5 limita-se aos dois pontos em que `busca` é emitido sem escape.
- **Nenhuma dependência ou arquivo da stack foi adicionado, renomeado ou movido.**
