# Relatorio tecnico - LEB-100-A

## Resumo

O sistema era pequeno e mantinha a maior parte do contrato publico, mas deixava passar falhas importantes em autorizacao, injecao SQL, exposicao de segredos e escape de HTML. A correcao preservou as assinaturas publicas de `lib.php`, as rotas `index.php`, os nomes dos parametros, os rotulos de status e a estrutura da tabela HTML declarada no manifesto.

As funcoes publicas continuam recebendo `mysqli`. Para aplicar a regra de visibilidade sem mudar assinatura, elas agora usam a sessao web quando ela esta ativa: clientes veem apenas os proprios chamados; tecnicos e chamadas internas sem sessao preservam a visao completa.

## F1 - Cliente conseguia listar, abrir e exportar chamados de outros clientes

- Onde: `code/lib.php`, linhas originais 78-85, 98-101 e 123-132; chamadas em `code/index.php`, linhas originais 44-45, 52-53 e 72-73.
- Categoria: seguranca.
- Severidade: critica.
- Confianca: 98.
- Mecanismo: apos o login, `index.php` gravava `uid` e `papel` na sessao, mas `listarChamados`, `verChamado` e `exportarCsv` consultavam `chamados` sem qualquer filtro por `usuario_id`. Como a rota `index.php?ver=<id>` aceitava qualquer id e a exportacao chamava `exportarCsv($db)` diretamente, um usuario cliente autenticado conseguia ver detalhes e CSV de chamados abertos por outros clientes.
- Impacto: vazamento de titulo, descricao, status, tecnico responsavel e datas de chamados de terceiros.
- O que fiz: adicionei um helper interno que identifica o cliente logado pela sessao ativa e apliquei `usuario_id = ?` em listagem, detalhe, media e CSV. Quando nao ha sessao ativa, o comportamento administrativo legado das funcoes publicas permanece completo.

## F2 - Busca por titulo permitia injecao SQL

- Onde: `code/lib.php`, linhas originais 80-85, especialmente a linha 82.
- Categoria: seguranca.
- Severidade: alta.
- Confianca: 95.
- Mecanismo: `listarChamados` concatenava `$_GET['busca']` dentro de `LIKE '%...%'`. Um valor contendo aspas e SQL podia alterar a clausula `WHERE`, ignorar filtros e, dependendo das permissoes do banco, executar consultas indesejadas.
- Impacto: leitura indevida de chamados, erro induzido no banco e possivel ampliacao de ataque conforme configuracao do MySQL.
- O que fiz: troquei a montagem por `prepare`, `bind_param` e parametro `LIKE`, mantendo o parametro publico `busca`.

## F3 - Termo de busca era refletido no HTML sem escape

- Onde: `code/index.php`, linhas originais 79-82.
- Categoria: seguranca.
- Severidade: media.
- Confianca: 95.
- Mecanismo: o valor de `busca` era escrito cru no atributo `value` do input e no paragrafo "Resultados para". Um termo com aspas ou tags podia sair do atributo e injetar HTML/JavaScript na pagina renderizada para o usuario autenticado.
- Impacto: XSS refletido no painel, com risco de roubo de sessao ou acao autenticada em nome do usuario.
- O que fiz: centralizei escape com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` e apliquei nos campos de busca, titulo, descricao e nome do tecnico.

## F4 - Segredos reais estavam embutidos no codigo

- Onde: `code/config.php`, linhas originais 11-15.
- Categoria: seguranca.
- Severidade: alta.
- Confianca: 100.
- Mecanismo: o fallback de `DB_PASS` continha uma senha de producao e `SMTP_API_KEY` era definido como literal no arquivo. Qualquer copia do pacote ou vazamento de repositorio expunha credenciais reutilizaveis.
- Impacto: acesso nao autorizado ao banco ou ao provedor transacional, dependendo de onde essas credenciais ainda fossem aceitas.
- O que fiz: removi os literais sensiveis e mantive as mesmas constantes, agora preenchidas por variaveis de ambiente (`DB_PASS` e `SMTP_API_KEY`).

## F5 - Exportacao CSV usava arquivo fixo compartilhado

- Onde: `code/lib.php`, linhas originais 125-150.
- Categoria: seguranca.
- Severidade: media.
- Confianca: 90.
- Mecanismo: `exportarCsv` gravava sempre em `EXPORT_DIR . '/chamados.csv'` e depois servia esse arquivo com `readfile`. Requisicoes concorrentes podiam sobrescrever o arquivo umas das outras; falhas de permissao retornavam silenciosamente; e um arquivo deixado no diretorio temporario podia ficar acessivel por fora da rota.
- Impacto: exportacao incorreta, vazamento entre usuarios e falhas silenciosas de download.
- O que fiz: passei a emitir o CSV diretamente em `php://output`, sem arquivo intermediario compartilhado.

## F6 - Cabecalho do CSV nao batia literalmente com o manifesto

- Onde: `code/lib.php`, linha original 130.
- Categoria: bug.
- Severidade: media.
- Confianca: 100.
- Mecanismo: `fputcsv` coloca aspas no campo `Aberto em`, gerando `ID,Titulo,Status,Tecnico,"Aberto em"`. O manifesto exige o cabecalho exato `ID,Titulo,Status,Tecnico,Aberto em`.
- Impacto: consumidores que validam o cabecalho literalmente podem rejeitar o arquivo.
- O que fiz: escrevi o cabecalho manualmente e mantive `fputcsv` nas linhas de dados.

## F7 - Media de resposta quebrava quando nao havia respostas

- Onde: `code/lib.php`, linhas originais 109-116.
- Categoria: bug.
- Severidade: media.
- Confianca: 90.
- Mecanismo: `mediaResposta` somava linhas e retornava `$soma / $qtd` sem testar se `$qtd` era zero. Em uma base nova, ou em uma visao filtrada sem `minutos_resposta`, isso gera divisao por zero.
- Impacto: aviso/erro em producao e quebra da tela de listagem.
- O que fiz: troquei o calculo manual por `AVG(minutos_resposta)` e retorno `0.0` quando nao ha linhas aplicaveis.

## F8 - Listagem e exportacao faziam consulta N+1 para tecnico

- Onde: `code/lib.php`, linhas originais 64-71, 88-90 e 136-138.
- Categoria: performance.
- Severidade: baixa.
- Confianca: 95.
- Mecanismo: para cada chamado, o codigo chamava `tecnicoNome`, que executava outro `SELECT` em `usuarios`. Uma tela com N chamados fazia 1 consulta principal mais N consultas auxiliares; o CSV repetia o mesmo padrao.
- Impacto: latencia crescente e carga desnecessaria no banco conforme o volume de chamados.
- O que fiz: alterei listagem e exportacao para `LEFT JOIN usuarios`, retornando `tecnico_nome` diretamente. Mantive `tecnicoNome` por compatibilidade interna e passei sua consulta para preparada.

## F9 - Login nao regenerava o identificador de sessao

- Onde: `code/index.php`, linhas originais 20-27.
- Categoria: seguranca.
- Severidade: media.
- Confianca: 85.
- Mecanismo: depois de autenticar, o codigo reutilizava o mesmo session id que existia antes do login. Se um atacante conseguisse fixar esse id antes da autenticacao, a sessao autenticada continuaria no identificador conhecido.
- Impacto: sequestro de sessao em cenario de session fixation.
- O que fiz: adicionei `session_regenerate_id(true)` imediatamente apos autenticacao bem-sucedida.

## F10 - Senhas ainda usam MD5 legado

- Onde: `code/lib.php`, linha original 15; `code/schema.sql`, linha original 7; `code/seed.sql`, linhas originais 2 e 5-8.
- Categoria: seguranca.
- Severidade: alta.
- Confianca: 100.
- Mecanismo: `autenticar` compara `md5($senha)` com uma coluna `CHAR(32)`. MD5 e rapido, sem sal e inadequado para armazenamento de senha; um vazamento da tabela permite ataque offline barato.
- Impacto: descoberta de senhas por dicionario ou brute force offline caso hashes sejam expostos.
- O que fiz: nao migrei nesta entrega. Corrigir de verdade exige mudanca de esquema, rehash com `password_hash`, caminho de migracao para usuarios existentes e atualizacao dos dados de seed; isso extrapola a correcao segura sem quebrar compatibilidade dos dados fornecidos.

## Decisoes

- Nao alterei nomes, assinaturas ou tipos das funcoes publicas de `lib.php`; o manifesto declara que scripts externos dependem deles.
- Nao alterei as rotas nem os parametros `busca`, `ver` e `export`; links e favoritos externos dependem desses nomes.
- Nao troquei `mysqli` nem adicionei dependencias externas; a camada de acesso a dados faz parte do contrato.
- Nao mudei os textos de `formatarStatus`; o relatorio gerencial depende exatamente de `Aberto`, `Em atendimento` e `Resolvido`.
- Nao migrei MD5 para `password_hash` nesta intervencao, porque isso exige alteracao coordenada de banco e dados existentes. O achado ficou registrado como nao corrigido.
- Nao removi `EXPORT_DIR` de `config.php`, embora a exportacao corrigida nao dependa mais dele, para reduzir risco de quebrar codigo externo que inclua a configuracao e use a constante.
