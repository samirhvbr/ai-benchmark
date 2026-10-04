# Relatorio tecnico — LEB-100-A

As linhas citadas nos achados usam a numeracao original dos arquivos recebidos. O sistema era pequeno e preservava parte do contrato publico, mas tinha falhas relevantes em autorizacao, tratamento de entrada, configuracao de segredos e robustez operacional. As correcoes foram pontuais: mantive `mysqli`, os nomes e assinaturas publicas, as rotas, o cabecalho do CSV, os rotulos de status e a estrutura HTML declarada no manifesto.

## Achados

### F1 — Clientes podiam ver e exportar chamados de outros clientes

Onde: `code/index.php`, linhas 44 a 74. A rota de CSV chamava `exportarCsv($db)` para qualquer usuario autenticado, a rota de detalhe carregava `verChamado($db, (int) $_GET['ver'])` sem conferir o dono, a listagem chamava `listarChamados($db, $busca)` e exibia tudo, e a media de resposta era calculada sobre todos os chamados.

Mecanismo: depois do login, um cliente podia acessar `index.php`, `index.php?ver=103` ou `index.php?export=csv` e receber registros de outros clientes, porque o papel e o `uid` guardados na sessao nao eram usados nas consultas nem antes da renderizacao. Isso viola diretamente a regra publica de visibilidade.

Impacto: exposicao de titulos, descricoes, status e dados operacionais de chamados de outros clientes. Severidade: alta. Confianca: 98.

Correcao: adicionei `usuarioPodeVerChamado()` em `index.php`, apliquei a checagem na listagem e no detalhe, e criei `exportarCsvCliente()` e `mediaRespostaCliente()` para que clientes exportem e vejam indicadores apenas dos proprios chamados. Tecnicos continuam vendo, exportando e medindo todos os chamados, preservando a regra de negocio.

### F2 — Busca por titulo aceitava SQL injection

Onde: `code/lib.php`, linhas 80 a 85. `listarChamados()` concatenava `$busca` diretamente em `WHERE titulo LIKE '%...%'`.

Mecanismo: o valor de `index.php?busca=` chegava a `listarChamados()` sem parametrizacao. Um termo com aspas e operadores SQL passava a fazer parte da consulta, alterando o `WHERE` montado antes do `ORDER BY`.

Impacto: um usuario autenticado poderia alterar a consulta de listagem, forcar resultados inesperados e, dependendo do payload e das permissoes do banco, extrair ou corromper dados acessiveis ao usuario do MySQL. Severidade: alta. Confianca: 95.

Correcao: troquei a concatenacao por `prepare()`/`bind_param()` mantendo a busca por `LIKE '%termo%'` e o mesmo retorno publico da funcao.

### F3 — Busca era refletida no HTML sem escape

Onde: `code/index.php`, linhas 79 a 82. O valor de `$busca` era escrito diretamente no atributo `value` do campo de busca e no paragrafo "Resultados para".

Mecanismo: como o parametro GET podia conter aspas e marcacao HTML, uma URL de busca maliciosa podia quebrar o atributo do input ou injetar HTML/script na pagina autenticada.

Impacto: XSS refletido no painel, com possibilidade de executar acoes no contexto da sessao do usuario que abrisse o link. Severidade: alta. Confianca: 99.

Correcao: adicionei o helper `h()` com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, usei o escape em campos dinamicos e normalizei parametros GET/POST para escalares antes de passa-los adiante.

### F4 — Segredos de producao estavam embutidos no codigo

Onde: `code/config.php`, linhas 11 a 15. O fallback de `DB_PASS` continha uma senha de producao e `SMTP_API_KEY` era definido como literal no arquivo.

Mecanismo: qualquer copia do pacote, backup, log de diff ou leitura do repositorio revelava credenciais reutilizaveis fora do runtime. O uso de `getenv()` existia para o banco, mas o fallback mantinha o segredo exposto.

Impacto: vazamento de credenciais de banco e de e-mail transacional. Severidade: alta. Confianca: 100.

Correcao: mantive os nomes das constantes, mas removi os valores sensiveis embutidos. `DB_PASS` e `SMTP_API_KEY` passam a vir do ambiente, com string vazia como fallback nao secreto.

### F5 — `mediaResposta()` podia dividir por zero

Onde: `code/lib.php`, linhas 109 a 116. A funcao somava linhas de `minutos_resposta` e retornava `$soma / $qtd` sem tratar o caso de nenhum registro.

Mecanismo: em uma base vazia, ou em uma base em que todos os chamados ainda tenham `minutos_resposta IS NULL`, o loop nao incrementa `$qtd`. O retorno divide por zero e pode gerar erro, warning ou valor nao finito conforme a versao/configuracao do PHP.

Impacto: a listagem principal pode quebrar ou exibir um indicador invalido justamente em instalacoes novas ou com chamados sem primeira resposta. Severidade: media. Confianca: 90.

Correcao: troquei a agregacao manual por `AVG(minutos_resposta)` e retorno `0.0` quando nao houver media calculavel ou quando a consulta falhar.

### F6 — Exportacao CSV dependia de arquivo fixo compartilhado

Onde: `code/lib.php`, linhas 125 a 150. `exportarCsv()` escrevia sempre em `EXPORT_DIR . '/chamados.csv'` e depois fazia `readfile()` desse caminho.

Mecanismo: duas exportacoes simultaneas disputavam o mesmo arquivo, um diretorio ausente ou sem permissao fazia a funcao retornar sem CSV, e o arquivo ficava persistido no servidor apos o download. A funcao tambem podia sair apos erro de consulta sem fechar o arquivo ja aberto.

Impacto: falhas intermitentes de exportacao, possibilidade de usuarios receberem conteudo de outra exportacao concorrente e acúmulo de dados exportados no filesystem. Severidade: media. Confianca: 88.

Correcao: passei a emitir o CSV diretamente em `php://output`, mantendo o cabecalho exato `ID,Titulo,Status,Tecnico,Aberto em`, a ordem por `id` e os rotulos de `formatarStatus()`.

### F7 — Login nao regenerava o identificador da sessao

Onde: `code/index.php`, linhas 23 a 26. Depois de autenticar, o codigo gravava `uid` e `papel` na sessao existente.

Mecanismo: se um atacante conseguisse induzir a vitima a usar um ID de sessao conhecido antes do login, esse mesmo ID continuaria valido depois da autenticacao.

Impacto: risco de fixacao de sessao em cenarios de exposicao ou predefinicao do cookie de sessao. Severidade: media. Confianca: 85.

Correcao: chamei `session_regenerate_id(true)` imediatamente apos autenticacao bem-sucedida e antes de gravar os dados do usuario.

### F8 — Listagem e exportacao faziam consulta N+1 para nomes de tecnicos

Onde: `code/lib.php`, linhas 88 a 90 e 136 a 137. Para cada chamado, o codigo chamava `tecnicoNome()`, que fazia nova consulta em `usuarios`.

Mecanismo: uma listagem ou exportacao com N chamados executava uma consulta principal mais N consultas de tecnico. Esse custo cresce linearmente com o numero de linhas e afeta justamente a rota de exportacao, que tende a processar mais registros.

Impacto: lentidao e carga desnecessaria no banco em bases maiores. Severidade: baixa. Confianca: 95.

Correcao: alterei listagem e exportacao para buscar `tecnico_nome` com `LEFT JOIN usuarios`, preservando a chave `tecnico_nome` exigida pelo manifesto e o valor `-` quando nao ha tecnico.

### F9 — Senhas usam MD5 legado

Onde: `code/lib.php`, linha 15, com armazenamento definido em `code/schema.sql`, linha 7.

Mecanismo: `autenticar()` calcula `md5($senha)` e compara com uma coluna `CHAR(32)`. MD5 e rapido e sem sal; se o banco vazar, senhas fracas como as de exemplo podem ser testadas em massa com baixo custo.

Impacto: maior dano em caso de vazamento da tabela `usuarios` e maior probabilidade de reutilizacao de credenciais comprometidas em outros sistemas. Severidade: alta. Confianca: 100.

Correcao: nao alterei nesta entrega. Corrigir de verdade exige migracao de dados, coluna para hashes modernos ou estrategia de rehash no login. Fazer isso sem um plano de migracao poderia bloquear usuarios existentes e extrapola a evolucao pontual pedida pelo manifesto.

## Decisoes

- Mantive todas as assinaturas publicas listadas no manifesto: `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`.
- Mantive as rotas e parametros GET (`busca`, `ver`, `export`), o `id="tabela-chamados"`, a ordem das colunas da listagem, o cabecalho do CSV e os textos de status.
- Nao troquei `mysqli` nem adicionei dependencias externas.
- Nao migrei MD5 para `password_hash()` nesta entrega; o achado F9 registra o risco, mas a correcao segura depende de migracao compativel com a base existente.
- Mantive `EXPORT_DIR` definido em `config.php`, embora `exportarCsv()` nao dependa mais dele, para reduzir risco de quebra em scripts legados que incluam a configuracao.
- Nao alterei o conteudo semantico das colunas do CSV. Continuei usando `fputcsv()` para o escape de formato, porque transformar valores como titulo ou tecnico poderia quebrar consumidores que esperam os dados do banco.

## Validacao

Validei sintaxe com `php -l` em `code/config.php`, `code/lib.php` e `code/index.php`. Tambem subi MariaDB local, carreguei `code/schema.sql` e `code/seed.sql`, e executei testes funcionais em PHP cobrindo autenticacao, busca parametrizada, retorno de `tecnico_nome`, media global, media por cliente e CSV global/por cliente. Por fim, subi o servidor embutido do PHP e testei as rotas reais com cookies de sessao: cliente vendo apenas chamados proprios, detalhe alheio retornando "Chamado nao encontrado.", CSV de cliente filtrado, tecnico vendo todos os chamados, busca escapada no HTML e cabecalho CSV literal `ID,Titulo,Status,Tecnico,Aberto em`.
