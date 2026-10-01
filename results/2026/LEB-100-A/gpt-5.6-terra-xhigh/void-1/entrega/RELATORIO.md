# Relatorio Tecnico

## Resumo

O painel tinha vulnerabilidades que permitiam a um cliente autenticado acessar chamados de outros clientes, injetar SQL na busca e executar JavaScript no navegador de usuarios tecnicos. Tambem havia segredos de producao no repositorio, hashes MD5, uma exportacao CSV concorrente por arquivo temporario e falhas de disponibilidade e desempenho. As correcoes preservam as assinaturas e os formatos documentados no manifesto.

## F1 - Falta de autorizacao por chamado

- **Onde:** `code/index.php`, linhas originais 44-74.
- **Mecanismo:** apos qualquer login, as rotas de exportacao, detalhe e listagem chamavam `exportarCsv`, `verChamado` e `listarChamados` sem comparar o dono do chamado com a sessao. Assim, por exemplo, Ana podia requisitar `index.php?ver=103`, listar chamados de Bruno e baixar o CSV completo.
- **Impacto e severidade:** exposicao de titulos, descricoes, responsavel e indicadores de outros clientes. Severidade **alta**.
- **Confianca:** **100/100**.
- **Correcao:** `index.php` agora seleciona consultas internas restritas a `usuario_id` para clientes, nega detalhes de terceiros como inexistentes e exporta somente seus chamados. Tecnicos continuam vendo todos os chamados, como exige o manifesto. As funcoes publicas originais permanecem globais para os scripts externos declarados.

## F2 - Injecao SQL na busca

- **Onde:** `code/lib.php`, linhas originais 80-85.
- **Mecanismo:** o valor de `busca` era concatenado entre aspas na clausula `LIKE`. Uma busca contendo aspas, por exemplo `%' OR 1=1 -- `, alterava a estrutura da consulta em vez de ser tratada como texto.
- **Impacto e severidade:** leitura indevida de dados e possibilidade de modificar a logica SQL da listagem. Severidade **alta**.
- **Confianca:** **99/100**.
- **Correcao:** a busca, o identificador do cliente e os identificadores usados pelas consultas passaram a usar statements preparados com parametros ligados. O comportamento de busca por `LIKE`, inclusive curingas fornecidos pelo usuario, foi mantido.

## F3 - XSS refletido na busca

- **Onde:** `code/index.php`, linhas originais 79-83.
- **Mecanismo:** `busca` era inserida sem codificacao tanto no atributo `value` quanto no paragrafo de resultados. Um valor como `"><script>...</script>` encerrava o atributo e executava script ao renderizar a pagina.
- **Impacto e severidade:** roubo ou uso indevido da sessao de usuarios autenticados e alteracao da interface mostrada a eles. Severidade **alta**.
- **Confianca:** **100/100**.
- **Correcao:** toda saida textual vinda da requisicao ou do banco que vai para HTML passou a usar `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8.

## F4 - Segredos de producao embutidos no codigo

- **Onde:** `code/config.php`, linhas originais 12-15.
- **Mecanismo:** a senha do banco e a chave da central SMTP estavam em literais no arquivo. Qualquer pessoa com acesso ao repositorio, pacote de deploy ou backup do codigo recebia credenciais reutilizaveis.
- **Impacto e severidade:** acesso ao banco ou ao provedor de e-mail fora do controle da aplicacao. Severidade **alta**.
- **Confianca:** **100/100**.
- **Correcao:** `DB_PASS` e `SMTP_API_KEY` passaram a ser lidos exclusivamente de variaveis de ambiente, sem fallback secreto no repositorio.

## F5 - Senhas com MD5 rapido e sem sal

- **Onde:** `code/lib.php`, linhas originais 15-20.
- **Mecanismo:** a autenticacao calculava `md5($senha)` e comparava o resultado diretamente com a coluna. MD5 e rapido, sem custo adaptativo e viabiliza ataques de dicionario e tabelas precomputadas quando o banco e obtido.
- **Impacto e severidade:** recuperacao de senhas e comprometimento de contas, especialmente para senhas reutilizadas. Severidade **alta**.
- **Confianca:** **100/100**.
- **Correcao:** o schema e os dados de teste usam bcrypt; a autenticacao usa `password_verify`. Hashes MD5 existentes ainda autenticam somente para permitir a transicao e sao substituidos por bcrypt apos uma autenticacao bem-sucedida.

## F6 - CSV compartilhado em arquivo temporario previsivel

- **Onde:** `code/lib.php`, linhas originais 125-150.
- **Mecanismo:** todas as requisicoes escreviam e liam o mesmo caminho `EXPORT_DIR/chamados.csv`. Duas exportacoes simultaneas podiam truncar ou sobrescrever o arquivo entre o `fopen` de uma requisicao e o `readfile` de outra, retornando conteudo parcial ou de outra requisicao.
- **Impacto e severidade:** corrupcao e exposicao cruzada de exportacoes, alem de deixar dados no diretorio web temporario. Severidade **media**.
- **Confianca:** **97/100**.
- **Correcao:** o CSV agora e gerado diretamente em `php://output`, mantendo cabecalho, colunas, rotulos e ordenacao por `id` exigidos pelo contrato.

## F7 - Divisao por zero na metrica de SLA

- **Onde:** `code/lib.php`, linhas originais 109-116.
- **Mecanismo:** quando nenhum chamado tinha `minutos_resposta`, `qtd` permanecia zero e a expressao `$soma / $qtd` disparava `DivisionByZeroError` nas versoes atuais do PHP.
- **Impacto e severidade:** indisponibilidade da pagina de listagem em bases novas ou sem primeiras respostas. Severidade **media**.
- **Confianca:** **100/100**.
- **Correcao:** a metrica usa `AVG` no banco e retorna `0.0` para conjunto vazio. Para clientes, o indicador tambem respeita o mesmo escopo de visibilidade dos chamados.

## F8 - Consulta N+1 na listagem e exportacao

- **Onde:** `code/lib.php`, linhas originais 88-90.
- **Mecanismo:** apos buscar a lista de chamados, cada iteracao executava `tecnicoNome`, gerando uma consulta adicional por chamado. Uma listagem de N chamados fazia N+1 consultas, inclusive quando diversos chamados tinham o mesmo tecnico.
- **Impacto e severidade:** aumento desnecessario de latencia e carga no banco conforme o volume cresce. Severidade **baixa**.
- **Confianca:** **100/100**.
- **Correcao:** as consultas de listagem e CSV usam `LEFT JOIN` com `usuarios`, preservando a chave `tecnico_nome` e o valor `-` para chamado sem tecnico.

## F9 - Sessao nao renovada e login sem protecao CSRF

- **Onde:** `code/index.php`, linhas originais 21-25.
- **Mecanismo:** apos autenticar, o codigo mantinha o mesmo identificador de sessao recebido antes do login, permitindo fixacao de sessao caso o identificador seja induzido. O formulario de login tambem aceitava qualquer POST com credenciais e sem token, permitindo login CSRF em uma conta controlada por atacante.
- **Impacto e severidade:** sequestro de sessao em cenarios de fixacao e confusao de conta por login forcado. Severidade **media**.
- **Confianca:** **95/100**.
- **Correcao:** foi incluido token CSRF na tela de login e `session_regenerate_id(true)` depois da autenticacao bem-sucedida.

## Decisoes

- As assinaturas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv` nao foram modificadas. As funcoes restritas ao painel foram adicionadas separadamente para nao alterar os consumidores externos de relatorio e exportacao.
- Nao foi criado um executavel de migracao para bancos de producao ja existentes, pois o pacote nao contem infraestrutura de migracoes e um DDL automatico nao idempotente seria arriscado. Antes do deploy, a coluna existente precisa receber `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL`; o `schema.sql` ja descreve a estrutura correta para instalacoes novas.
- Nao foi alterado o comportamento de `formatarStatus` para valores fora de 1, 2 e 3. O manifesto so estabelece esses valores e inventar um novo rotulo alteraria o contrato do relatorio gerencial.
- Nao foi adicionada neutralizacao de formulas de planilha no CSV. Isso alteraria o valor textual exportado em uma coluna de contrato; a decisao deve ser tomada com os consumidores do CSV se o arquivo passar a ser aberto em planilhas com dados de origem nao confiavel.
