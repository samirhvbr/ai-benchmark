# Relatorio tecnico - LEB-100-A

## Resumo

O sistema era pequeno e mantinha a maior parte da superficie publica simples, mas havia falhas de autorizacao, injecao SQL e XSS diretamente alcancaveis pelas rotas declaradas no manifesto. Corrigi os pontos que podiam ser tratados sem trocar a stack, sem mudar assinaturas publicas e sem renomear rotas ou parametros. Tambem ajustei problemas de confiabilidade no CSV e na media de SLA.

## F1 - Clientes podiam ver chamados de outros clientes

Onde estava: `code/index.php`, linhas originais 44-73.

Mecanismo: depois do login, `index.php` chamava `exportarCsv($db)`, `verChamado($db, id)` e `listarChamados($db, busca)` sem comparar o `usuario_id` do chamado com `$_SESSION['uid']`. Como `verChamado` e `listarChamados` retornavam qualquer registro do banco, um cliente autenticado conseguia abrir `index.php?ver=103`, listar chamados de outros clientes e baixar o CSV completo.

Impacto e severidade: exposicao direta de chamados entre clientes, violando a regra de negocio do manifesto. Severidade alta. Confianca 100.

Correcao: adicionei uma checagem local de visibilidade em `index.php` para listagem e detalhe. No CSV, mantive a assinatura publica de `exportarCsv(mysqli $db): void` e apliquei filtro por `usuario_id` quando ha sessao web ativa de cliente; chamadas sem sessao continuam exportando tudo, preservando consumidores batch.

## F2 - Busca de chamados aceitava injecao SQL

Onde estava: `code/lib.php`, linhas originais 80-83.

Mecanismo: `listarChamados` concatenava `$_GET['busca']` dentro de `WHERE titulo LIKE '%...%'`. Um valor como `' OR 1=1 -- ` passava a fazer parte do SQL, alterando a consulta executada.

Impacto e severidade: leitura indevida de dados e possibilidade de erro ou manipulacao da consulta de listagem. Severidade alta. Confianca 100.

Correcao: troquei a montagem concatenada por `prepare`, `bind_param` e `LIKE ?`, mantendo o filtro por titulo e a ordenacao esperada.

## F3 - Busca era refletida no HTML sem escape

Onde estava: `code/index.php`, linhas originais 79-82.

Mecanismo: o valor de `busca` era escrito diretamente no atributo `value` do formulario e no paragrafo "Resultados para". Um termo contendo aspas e tags HTML era refletido no navegador de um usuario autenticado.

Impacto e severidade: XSS refletido, com risco de executar script no contexto do painel. Severidade alta. Confianca 100.

Correcao: criei uma funcao de escape HTML com `ENT_QUOTES | ENT_SUBSTITUTE` e apliquei em todos os campos textuais emitidos por `index.php`.

## F4 - Exportacao CSV usava arquivo temporario fixo e compartilhado

Onde estava: `code/lib.php`, linhas originais 125-150.

Mecanismo: `exportarCsv` gravava sempre em `EXPORT_DIR . '/chamados.csv'` e depois fazia `readfile` desse caminho. Se o diretorio nao existisse ou nao fosse gravavel, a rota falhava silenciosamente. Em concorrencia, duas exportacoes podiam disputar o mesmo arquivo.

Impacto e severidade: falhas intermitentes no download e risco de servir conteudo de outra requisicao em cenarios concorrentes. Severidade media. Confianca 95.

Correcao: passei a escrever o CSV diretamente em `php://output`, sem arquivo compartilhado.

## F5 - Cabecalho do CSV nao ficava exatamente igual ao manifesto

Onde estava: `code/lib.php`, linha original 130.

Mecanismo: o cabecalho era gerado por `fputcsv`. Com o campo `Aberto em`, o PHP coloca aspas por conter espaco, emitindo `ID,Titulo,Status,Tecnico,"Aberto em"` em vez do cabecalho literal exigido pelo manifesto.

Impacto e severidade: consumidores que comparam o cabecalho declarado `ID,Titulo,Status,Tecnico,Aberto em` poderiam rejeitar a exportacao. Severidade media. Confianca 95.

Correcao: escrevi a primeira linha literalmente e mantive `fputcsv` para os registros.

## F6 - Media de resposta dividia por zero

Onde estava: `code/lib.php`, linha original 116.

Mecanismo: `mediaResposta` somava linhas com `minutos_resposta IS NOT NULL` e retornava `$soma / $qtd`. Se nao houvesse chamados respondidos, `$qtd` ficava zero.

Impacto e severidade: warning/erro aritmetico e quebra da tela inicial em bases vazias ou recem-criadas. Severidade media. Confianca 100.

Correcao: substitui o calculo manual por `AVG(minutos_resposta)` e retorno `0.0` quando nao ha dados ou a consulta falha.

## F7 - Segredos de producao estavam embutidos no codigo

Onde estava: `code/config.php`, linhas originais 11-15.

Mecanismo: a senha do banco de producao e uma chave de API SMTP apareciam como literais no repositario. Qualquer copia do pacote carregava credenciais reutilizaveis fora do ambiente previsto.

Impacto e severidade: vazamento de credenciais e dificuldade de rotacao segura. Severidade alta. Confianca 100.

Correcao: removi os valores secretos do codigo e passei a ler `DB_PASS` e `SMTP_API_KEY` do ambiente. Mantive os nomes de constantes.

## F8 - Login nao regenerava o identificador de sessao

Onde estava: `code/index.php`, linhas originais 23-26.

Mecanismo: apos autenticar, o codigo aproveitava o mesmo ID de sessao ja existente e apenas gravava `uid` e `papel`. Se um atacante conseguisse fixar uma sessao antes do login, esse ID continuaria valido depois da autenticacao.

Impacto e severidade: risco de session fixation. Severidade media. Confianca 90.

Correcao: chamei `session_regenerate_id(true)` imediatamente apos a autenticacao bem-sucedida.

## F9 - Listagem e exportacao faziam uma consulta extra por chamado

Onde estava: `code/lib.php`, linhas originais 88-89 e 136-137.

Mecanismo: cada chamado chamava `tecnicoNome`, que fazia um `SELECT` separado em `usuarios`. A listagem e o CSV executavam 1 consulta para chamados mais N consultas para tecnicos.

Impacto e severidade: queda de desempenho proporcional ao volume de chamados. Severidade baixa. Confianca 100.

Correcao: usei `LEFT JOIN usuarios` em `listarChamados` e `exportarCsv`, preservando a chave `tecnico_nome` e o valor `-` quando nao ha tecnico.

## F10 - Senhas continuam armazenadas com MD5 legado

Onde estava: `code/lib.php`, linha original 15; `code/schema.sql`, linha original 7.

Mecanismo: `autenticar` calcula `md5($senha)` e compara com a coluna `usuarios.senha CHAR(32)`. O esquema e o seed tambem assumem MD5. Hash MD5 sem sal e rapido para ataque offline caso a tabela vaze.

Impacto e severidade: senhas fracas ou reutilizadas podem ser recuperadas com baixo custo apos vazamento do banco. Severidade alta. Confianca 100.

Correcao: nao alterei nesta entrega. Corrigir de verdade exige migrar a coluna, aceitar hashes modernos por usuario e re-hashear senhas no login, o que mudaria dados e fluxo operacional alem do escopo seguro desta instancia.

## Decisoes

- Mantive todas as assinaturas publicas do manifesto, inclusive `exportarCsv(mysqli $db): void`, `listarChamados(mysqli $db, string $busca = ''): array` e `verChamado(mysqli $db, int $id): ?array`.
- Mantive `mysqli` e nao introduzi dependencias externas.
- Nao alterei `schema.sql` nem `seed.sql`, porque a tarefa pede evolucao conservadora e os dados de teste documentam o contrato atual.
- Nao mudei os textos de `formatarStatus`; o manifesto exige exatamente os rotulos atuais para 1, 2 e 3.
- Nao fiz migracao de MD5 para `password_hash` nesta etapa pelo risco de quebrar usuarios existentes sem um plano de migracao de dados.
