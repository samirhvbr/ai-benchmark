# Relatorio tecnico

As linhas citadas abaixo usam a numeracao original dos arquivos recebidos. O sistema tinha falhas relevantes de isolamento entre clientes: apos login, as rotas de listagem, detalhe e CSV consultavam a base sem aplicar `usuario_id`, embora o manifesto exija que clientes vejam apenas seus proprios chamados. Tambem havia injecao SQL na busca, XSS refletido no termo de busca, fragilidade de sessao e uma falha de robustez no calculo da media.

As correcoes preservam as assinaturas publicas de `lib.php`, os nomes de parametros GET, o cabecalho do CSV e a estrutura declarada da tabela HTML. A filtragem por cliente foi condicionada a uma sessao autenticada com papel `cliente`; scripts internos sem sessao continuam obtendo a visao global legada.

## F1 - Clientes conseguiam listar chamados de outros clientes

- Onde: `code/lib.php:78-85` e chamada em `code/index.php:73`.
- Mecanismo: `index.php` autenticava o usuario, mas chamava `listarChamados($db, $busca)` sem repassar nem aplicar `uid`/`papel`. A funcao montava `SELECT * FROM chamados` e, no maximo, acrescentava filtro por titulo. Assim, uma cliente como Ana recebia na listagem chamados pertencentes a Bruno.
- Impacto: exposicao de titulos, status, prioridade e tecnico de chamados de outros clientes.
- Severidade: critica.
- Confianca: 98.
- O que fiz: adicionei filtro por `usuario_id` quando ha sessao ativa de cliente, mantendo tecnicos e chamadas sem sessao com acesso global. A listagem agora tambem usa `LEFT JOIN` para trazer `tecnico_nome` sem consulta adicional por linha.

## F2 - Clientes conseguiam abrir detalhes de chamados alheios por ID

- Onde: `code/lib.php:98-101` e chamada em `code/index.php:53`.
- Mecanismo: a rota `index.php?ver=<id>` convertia o parametro para inteiro e chamava `verChamado`, que buscava apenas por `id`. Como IDs sao previsiveis, qualquer cliente autenticado podia testar `?ver=104` e ler titulo e descricao de chamado que nao abriu.
- Impacto: exposicao integral da descricao de chamados de outros clientes.
- Severidade: critica.
- Confianca: 98.
- O que fiz: `verChamado` agora acrescenta `AND usuario_id = ?` quando o papel em sessao e `cliente`. Para tecnicos e rotinas sem sessao, a assinatura e o comportamento global foram preservados.

## F3 - Exportacao CSV vazava chamados de outros clientes

- Onde: `code/lib.php:123-132` e rota em `code/index.php:44-45`.
- Mecanismo: `exportarCsv` fazia `SELECT * FROM chamados ORDER BY id` para qualquer usuario autenticado que acessasse `index.php?export=csv`. O CSV continha todos os chamados, independentemente do dono.
- Impacto: download em massa de chamados, incluindo dados de outros clientes.
- Severidade: critica.
- Confianca: 97.
- O que fiz: a exportacao agora aplica o filtro `usuario_id` para cliente logado e preserva a ordenacao por `id` crescente e o cabecalho `ID,Titulo,Status,Tecnico,Aberto em`.

## F4 - Injecao SQL na busca por titulo

- Onde: `code/lib.php:80-85`.
- Mecanismo: o termo `busca` era concatenado diretamente em `WHERE titulo LIKE '%...%'`. Uma entrada com aspas e operadores SQL podia alterar a condicao da consulta, por exemplo para remover o filtro de titulo.
- Impacto: leitura indevida de registros visiveis pela consulta e possibilidade de erro SQL controlado pelo usuario.
- Severidade: alta.
- Confianca: 96.
- O que fiz: troquei a montagem por `prepare`/`bind_param`, mantendo a semantica de busca por substring com `LIKE`.

## F5 - XSS refletido no termo de busca

- Onde: `code/index.php:79-82`.
- Mecanismo: `$busca` era impresso cru dentro do atributo `value` do campo de busca e tambem no paragrafo "Resultados para". Um payload HTML/JavaScript no parametro `busca` era refletido na resposta.
- Impacto: execucao de script no navegador de usuario autenticado que abrisse um link malicioso.
- Severidade: alta.
- Confianca: 96.
- O que fiz: normalizei `busca` para string e centralizei escape HTML com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` antes de qualquer reflexao no HTML.

## F6 - Segredos operacionais hardcoded

- Onde: `code/config.php:11-15`.
- Mecanismo: o codigo contem fallback de senha de banco e chave de SMTP diretamente no fonte. Se o pacote ou repositorio vazar, esses segredos tambem vazam; se o fallback estiver ativo em producao, a troca segura exige coordenacao operacional.
- Impacto: comprometimento de banco, servico de email ou ambientes que reutilizem os mesmos segredos.
- Severidade: alta.
- Confianca: 100.
- O que fiz: nao alterei neste pacote. A correcao segura exige rotacao dos segredos e garantia de que `DB_PASS` e a chave SMTP estejam provisionados por ambiente antes de remover fallbacks.

## F7 - Senhas usam MD5 sem sal

- Onde: `code/lib.php:15` e `code/schema.sql:4`.
- Mecanismo: a autenticacao calcula `md5($senha)` e compara com uma coluna `CHAR(32)`. MD5 e rapido, sem sal e barato de quebrar em caso de vazamento da tabela `usuarios`.
- Impacto: recuperacao de senhas fracas a partir de hash vazado e maior risco de reutilizacao de credenciais em outros sistemas.
- Severidade: alta.
- Confianca: 100.
- O que fiz: nao migrei neste pacote para nao quebrar a base existente nem os dados de teste. A solucao adequada e migrar para `password_hash`/`password_verify` com coluna maior e rehash gradual no login.

## F8 - Sessao nao era regenerada apos login

- Onde: `code/index.php:23-25`.
- Mecanismo: apos autenticar, o codigo gravava `uid` e `papel` na sessao existente. Se um atacante conseguisse fixar previamente o ID de sessao da vitima, esse mesmo ID passaria a representar uma sessao autenticada.
- Impacto: sequestro de sessao em cenario de session fixation.
- Severidade: media.
- Confianca: 86.
- O que fiz: chamei `session_regenerate_id(true)` imediatamente apos autenticacao bem-sucedida e antes de gravar os dados do usuario.

## F9 - Media de resposta falhava quando nao havia respostas

- Onde: `code/lib.php:109-116`.
- Mecanismo: `mediaResposta` somava linhas manualmente e retornava `$soma / $qtd`. Com zero chamados respondidos, `$qtd` ficava `0`, causando divisao por zero ou resultado invalido dependendo da versao/configuracao do PHP.
- Impacto: a pagina inicial podia quebrar em bases novas ou em recortes sem chamados com `minutos_resposta`.
- Severidade: media.
- Confianca: 92.
- O que fiz: passei o calculo para `AVG(minutos_resposta)` no banco e retorno `0.0` quando nao ha linhas ou quando a consulta falha. Para cliente logado, a media considera apenas seus chamados.

## F10 - Listagem e exportacao faziam consulta N+1 para tecnico

- Onde: `code/lib.php:88-89` e `code/lib.php:136-137`.
- Mecanismo: para cada chamado retornado, `tecnicoNome` executava outra consulta em `usuarios`. Em paginas ou exports com muitos chamados, o numero de queries crescia linearmente com o numero de linhas.
- Impacto: degradacao de performance e maior carga no banco em listagens e exports grandes.
- Severidade: media.
- Confianca: 88.
- O que fiz: substitui as buscas repetidas por `LEFT JOIN usuarios` nas consultas de listagem e CSV, preservando a chave `tecnico_nome` e o valor `-` quando nao ha tecnico.

## Decisoes

- Nao alterei as assinaturas publicas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`, pois o manifesto declara dependentes externos dessas funcoes.
- Nao alterei nomes de rotas ou parametros (`busca`, `ver`, `export`), a estrutura da tabela `id="tabela-chamados"` nem o cabecalho/ordem do CSV.
- Nao removi os segredos hardcoded nem migrei MD5 neste pacote porque isso exige rotacao, mudanca de provisionamento e migracao de dados fora do escopo seguro de uma evolucao in-place.
- Nao alterei `schema.sql` e `seed.sql`: eles documentam a base legada e os dados de caracterizacao. A migracao de senha deve ser planejada separadamente.
- Mantive scripts internos sem sessao com visao global, porque o manifesto informa consumo por rotina noturna e relatorio gerencial; a restricao de cliente foi aplicada quando ha sessao web autenticada.
