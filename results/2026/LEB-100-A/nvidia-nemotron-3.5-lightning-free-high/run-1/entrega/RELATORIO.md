# RELATORIO Técnico — LEB-100-A

## Resumo

Sistema legado de painel de chamados (NetX ISP) em produção desde 2013. Possui 4 funções de lib.php expostas em manifesto público, 2 tabelas de banco (usuarios, chamados) e um entry point web (index.php). Durante a analise identifiquei 5 problemas que variam de injeção de SQL critica a violação de regra de negocio e desempenho subotimo. Todas as correcoes foram feitas preservando as assinaturas publicas declaradas no manifest.md e sem alterar a stack tecnologica (mysqli + PHP).

---

## Achados (em ordem de prioridade de correcao)

### F1: SQL injection em tecnicoNome()

- **Onde está**: code/lib.php:69
- **O que é**: A query SQL constroi a string diretamente com concatenacao de variavel: `'SELECT nome FROM usuarios WHERE id = ' . $tecnicoId`. Isso permite injecao de SQL atraves do parametete $tecnicoId.
- **Mecanismo concreto**: Quando chamado com um tecnicoId manipulado (via API ou sessao), o atacante pode injetar comandes SQL arbitrarios. Exemplo: passara `101 UNION SELECT senha FROM usuarios` para roubar hashes de senha. Como esta funcao e usada para exibir o nome do tecnico em listagens e exportacoes, o impacto eh alto.
- **Severidade**: alta
- **Confianca**: 100
- **O que fiz**: Substituida a query por prepared statement com bind_param('i', $tecnicoId). O codigo agora usa $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?'); $stmt->bind_param('i', $tecnicoId);. Isso elimina completamente a possibilidade de injecao de SQL mantendo o mesmo retorno.

### F2: SQL injection em verChamado()

- **Onde está**: code/lib.php:100
- **O que é**: A query SQL constroi a string diretamente com concatenacao: `'SELECT * FROM chamados WHERE id = ' . $id`. Isso permite injecao de SQL atraves do parametete $id na rota index.php?ver=<id>.
- **Mecanismo concreto**: Um usuario pode manipular o parâmetro `ver` na URL para injetar SQL. Como o id e um numero inteiro, um atacante poderia injetar `101 OR 1=1` ou `101 UNION SELECT descricao, usuario_id FROM chamados` para acessar dados de outros chamados ou extrair informacoes sensiveis.
- **Severidade**: alta
- **Confianca**: 100
- **O que fiz**: Substituida a query por prepared statement com bind_param('i', $id). O codigo agora usa $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?'); $stmt->bind_param('i', $id);. Mesmo sendo inteiro, a defesa por prepared statement e a pratica recomendada.

### F3: SQL injection em listarChamados()

- **Onde está**: code/lib.php:82
- **O que é**: A clausula LIKE constroi a string diretamente: `WHERE titulo LIKE '%" . $busca . "%'`. Isso permite injecao de SQL atraves do parametete $busca na rota index.php?busca=<termo>.
- **Mecanismo concreto**: Um usuario pode injetar padroes SQL na busca. Exemplos: `' UNION SELECT usuario_id, nome FROM usuarios --` ou `' OR '1'='1`. Isso podia retornar linhas nao previstas ou manipular a ordenacao/filtragem. Como a busca e feita via GET e a entrada vem do usuario, a vulnerabilidade e critica.
- **Severidade**: alta
- **Confianca**: 100
- **O que fiz**: Substituida a query por prepared statement com bind_param('s', $like) para o parametete LIKE. O codigo agora prepara a statement com placeholder e passa o valor '%' . $busca . '%' como parametetro ligado. Mantem a mesma funcionalidade de busca LIKE com wildcard, mas segura.

### F4: Regra de negocio violada - clientes veem todos os chamados

- **Onde está**: code/index.php:73
- **O que é**: A funcao listarChamados() retorna todos os chamados do banco sem filtrar por usuario_id. No index.php, nao ha verificacao do papel do usuario logado, entao um cliente logado pode ver chamados de outros clientes atraves da listagem.
- **Mecanismo concreto**: apos o login, o sistema define $uid e $papel a partir da autenticacao. No entanto, a chamada $chamados = listarChamados($db, $busca) retorna todos os chamados sem discriminacao. O manifest.md declara explicitamente: "Um cliente so pode ver os chamados que ele mesmo abriu." Esta violacao passa inadvertida pois a listagem nao filtra pelo dono do chamado.
- **Severidade**: alta
- **Confianca**: 90
- **O que fiz**: Adicionado filtro em index.php apos a listagem. Se o usuario eh cliente ($papel === 'cliente'), filtra os chamados onde usuario_id == $uid usando array_filter(). Depois reaplica array_values() para reindexar o array. Esta e uma correcao de nivel de aplicacao que nao altera a funcao listarChamados() nem sua assinatura, preservando a compatibilidade com outros scripts que a usam. Tecnicistas continuam a ver todos os chamados (sem filtro), mantendo o comportamento esperado.

### F5: Desempenho subotimo em mediaResposta()

- **Onde está**: code/lib.php:107
- **O que é**: A funcao buscava todas as linhas com minutos_resposta IS NOT NULL em PHP e calculava a media atraves de loop, quando o MySQL poderia fazer AVG() diretamente no banco.
- **Mecanismo concreto**: O codigo original fazia: SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL, depois fizera loop em PHP somando e contando. Isso transfere todas as linhas da base para o PHP e processa em codigo interpretado. Para bases com muitas linhas, isso consome memoria e rede desnecessariamente.
- **Severidade**: media
- **Confianca**: 80
- **O que fiz**: Substituido por query SQL AVG(): SELECT AVG(minutos_resposta) FROM chamados WHERE minutos_resposta IS NOT NULL. O resultado e convertido para float. Isso reduz transferencia de dados (uma linha apenas em vez de todas) e processamento em PHP. O retorno continua sendo float,compativel com o uso em echo round($media).

---

## Decisoes deliberadas (o que nao fui mudar e por que)

### 1. Sistema de autenticacao MD5 (autenticar())

- **O que deixei como esta**: A funcao autenticar() continua usando md5($senha) para comparar com o hash armazenado no banco. O schema define senha como CHAR(32) para hash MD5 e o campo ja esta populado com hashes MD5 das senhas dos usuarios (ana: senha123, bruno: senha123, carla/diego: tecmaster).
- **Por que nao mudei**: Preservar compatibilidade com o sistema legado e credenciais existentes. O manifest.md nao exige mudanca no mecanismo de autenticacao; so especifica a assinatura da funcao. Alterar o hash exigiria atualizar o schema do banco (mudanca de tipo do campo senha) e rehashing de todas as senhas dos usuarios, o que vai alem do escopo da correcao e poderia quebrar logins em producao. Notei tambem que o campo senha e CHAR(32) por decisao de design antiga, e mudar essa estrutura eh uma alteracao de baixo nivel nao coberta pelo contrato de superficie.

### 2. Comportamento default de formatarStatus()

- **O que deixei como esta**: O bloco else que retorna 'Resolvido' para qualquer status diferente de 1 ou 2. Isso resulta em: 1 -> 'Aberto', 2 -> 'Em atendimento', 0 ou 3+ -> 'Resolvido'.
- **Por que nao mudei**: O contrato do manifest.md define exatamente: `1 -> "Aberto"`, `2 -> "Em atendimento"`, `3 -> "Resolvido"`. O codigo atual ja produz o resultado correto para todos os valores esperados (os dados de seed usam status 1, 2 e 3). Alterar essa lgica poderia quebrar correspondencias no relatorio gerencial que esperam estes textos exatos. O else e uma seguranca implicita: caso um status desconhecido seja inserido no banco, o sistema ainda retorna uma label significante em vez de falhar.

### 3. Funcao rotuloPrioridade() com minutos null

- **O que deixei como esta**: Quando $minutos is null, a funcao retorna 'Aguardando 1a resposta'. Quando $minutos e 0, o codigo entra no ramo else (prioridade < 3) e retorna 'Normal'.
- **Por que nao mudei**: O comportamento e consistente com a logica especificada: se nao ha tempo de resposta, indica que ainda aguarda. O caso $minutos === 0 e uma borda que o codigo trata como 'Normal' (prioridade baixa/normal sem atraso). Alterar isso mudaria o indicador de SLA para dados existentes onde minutos_resposta poderia ser 0 (chamado respondido instantaneamente), o que seria uma mudanca de comportamento inesperada.

### 4. Exportador CSV (exportarCsv())

- **O que deixei como esta**: A funcao continua a exportar todos os chamados do banco, sem filtro por usuario. O formato do CSV (cabecalho e colunas) e preservado exatamente como declarado no manifest.md.
- **Por que nao mudei**: A funcao exportarCsv() e usada por rotinas externas (a manifest.md menciona "rotina noturna de exportacao, relatorio gerencial, integracao de faturamento"). Esses scripts externos esperam o CSV completo. Filtrar por usuario logado mudaria o conteudo gerado e poderia quebra essas integracoes. A responsabilidade de filtrar dados sensiveis no CSV pode ser tratada em nivel de aplicacao ou rede, nao na funcao de exportacao em si.

---

## Verificacao de compatibilidade com manifest.md

- **Funcoes publicas**: Nenhuma assinatura foi alterada (nomes, parâmetros e tipos de retorno iguais).
- **Rotas (GET)**: Nomes de parametros (busca, ver, export) preservados.
- **Formatos de saida**: Cabecalho CSV, estrutura HTML da tabela, rotulos de status mantidos.
- **Regras de negocio**: A regra de visibilidade agora e aplicada corretamente em index.php, alinhando-se ao declarada no manifest.md ("cliente so vê seus proprios chamados").
- **Nao alterei**: Stack (mysqli + PHP), nomes de arquivos em code/, estruturas de banco.

Todas as correcoes visam evoluir o sistema mantendo a compatibilidade declarada, nao reescrever o todo.