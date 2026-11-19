# Painel de Chamados — relatório de manutenção LEB-100-A, v1.1

## Resumo

O login identificava o usuário, mas as leituras de chamados não usavam essa identidade. Fechar só a página de detalhe não bastaria: a tabela, a busca e o download continuariam entregando registros de terceiros. O painel agora aplica a mesma regra no banco às quatro leituras, inclusive ao indicador de SLA: **o cliente vê o que abriu; o técnico vê todos os chamados**, atribuídos ou não a ele.

São **24 achados: 20 corrigidos no código e 4 mantidos com justificativa**. As pendências são limitação de login (F19), escala da listagem (F20), abertura de CSV em planilha (F21) e status inválido (F24). Duas correções de código ainda exigem resposta operacional: trocar os segredos já expostos e concluir a migração de senhas. Os cinco arquivos de `code/` mantêm seus nomes; o manifesto não foi alterado.

## Achados, por prioridade de correção

As linhas a seguir são as **originais** do pacote, não as posteriores ao patch. Confiança mede a certeza sobre o mecanismo, não a probabilidade de que já tenha havido um incidente.

### F1 — O termo de busca altera o SQL da listagem
`code/lib.php:80–85` · Segurança · **Crítica** · Confiança **100/100** · Corrigido.

O termo de `busca` era concatenado dentro das aspas de `LIKE` e enviado a `query()`. Isso podia tanto quebrar uma pesquisa com apóstrofo quanto permitir a um cliente alterar o significado da leitura com os privilégios da conta do banco. Agora o molde SQL é fixo e o padrão `%termo%` é vinculado como valor. Os curingas da busca anterior continuam válidos. O login original já usava parâmetros; não era a origem dessa injeção.

### F2 — O detalhe aceita o ID de um chamado de outro cliente
`code/lib.php:98–101`; chamada em `code/index.php:52–56` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

O cast para inteiro dava um tipo ao ID, mas a consulta não verificava o dono. Assim, o detalhe podia exibir a descrição de terceiros. Para o cliente, a consulta agora exige simultaneamente o ID pedido e seu `usuario_id`. Registro inexistente e registro não autorizado resultam no mesmo `null` e na mesma página. Não se compara `tecnico_id` com o ID de quem lê.

### F3 — Listagem e busca ignoram quem abriu o chamado
`code/lib.php:78–92`; `code/index.php:38–39,72–74` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

A única condição opcional da lista era o título. O painel já tinha `uid` e `papel`, mas não os usava; todo cliente recebia linhas e atribuições de outros clientes. O filtro de visibilidade é aplicado antes de ordenar ou montar o array. O servidor obtém o papel atual pelo ID da sessão em cada requisição autenticada: a mudança de papel não depende de novo login. Sessão inválida não concede visão global.

### F4 — O download CSV não aplica a visibilidade do cliente
`code/lib.php:132–144`; rota em `code/index.php:44–46` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

O exportador tinha seu próprio `SELECT` sem condição, independente da listagem. Qualquer cliente com sessão podia, portanto, obter a base de chamados em lote. A exportação agora aplica o filtro de dono na consulta. Um cliente sem chamados recebe só o cabeçalho; técnico e rotina interna confiável sem sessão continuam recebendo a exportação integral, em ordem de ID.

### F5 — A senha de produção do banco está embutida como fallback
`code/config.php:8–12` · Segurança · **Alta** · Confiança **100/100** · Corrigido no código; rotação pendente.

O arquivo continha uma senha identificada como de produção e a usava automaticamente sem `DB_PASS`. Uma cópia do pacote bastava para revelar o valor, ainda que a instalação tivesse configurado outro. O literal foi removido e o painel falha antes de conectar caso a variável esteja ausente ou vazia. Não se testou a credencial em produção. Remover o fallback não revoga cópias antigas: é preciso trocar a senha.

### F6 — A chave da central de e-mail acompanha o código
`code/config.php:14–15` · Segurança · **Alta** · Confiança **100/100** · Corrigido no código; revogação pendente.

O token era constante e distribuído com o painel. Mesmo sem uma chamada SMTP nestes arquivos, isso expõe as permissões que a chave ainda tiver no provedor. `SMTP_API_KEY` vem agora do ambiente, sem fallback secreto. O painel não exige essa integração para servir chamados. Quem a usa deve receber uma chave nova; a antiga deve ser revogada.

### F7 — As senhas são verificadas por MD5 rápido, sem sal individual
`code/lib.php:13–20`; `code/schema.sql:7`; `code/seed.sql:4–8` · Segurança · **Alta** · Confiança **100/100** · Corrigido com migração progressiva.

O banco comparava o MD5 da entrada com uma coluna `CHAR(32)`. Um vazamento permitiria reconhecer senhas iguais e verificar hipóteses fora do painel a baixo custo. O novo schema comporta o hash Argon2id completo; o seed conserva as quatro credenciais públicas com sais distintos. `autenticar` busca por login e não devolve o hash.

Um registro legado só é convertido após validar a senha; o `UPDATE` compara também o hash anterior para não sobrescrever uma troca concorrente. Se a linha mudou, a tentativa não concede sessão e pode ser repetida. Senha incorreta não grava nada. Usuário inexistente ou MD5 incorreto também incorrem no custo do hash moderno, para não criar um caminho de falha muito mais rápido. Para contas que nunca mais entram, é necessário redefinir ou desativar as credenciais. Ver a ordem de implantação abaixo.

### F8 — O termo da busca é refletido em dois contextos HTML sem escape
`code/index.php:79–83` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

A mesma string entrava no atributo `value` entre aspas e no parágrafo de resultados. O navegador podia interpretá-la como marcação, inclusive como script na origem do painel. Os dois pontos agora recebem texto escapado com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8. O escape é feito **na saída HTML**, não na entrada do banco, para não mudar as pesquisas nem o CSV.

### F9 — Downloads concorrentes compartilham um arquivo previsível e persistente
`code/lib.php:125–150`; `code/config.php:17–18` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

Cada pedido truncava `EXPORT_DIR/chamados.csv`, escrevia, fechava e reabria o mesmo nome para leitura. Entre essas etapas, outro pedido podia substituir o conteúdo. Além de misturar ou truncar downloads, sobrava um arquivo sob uma árvore web cuja exposição dependeria da configuração do servidor. O CSV agora percorre um cursor e escreve no `php://output` da própria requisição. Não precisa de diretório gravável. A versão nova não apaga arquivos produzidos pela antiga.

### F10 — O login promove a sessão sem renovar seu identificador
`code/index.php:15–27` · Segurança · **Alta** · Confiança **100/100** · Corrigido.

O código gravava os privilégios no ID pré-login. Se esse identificador fosse conhecido por terceiro, ele poderia continuar válido após a autenticação. O painel exige que `session_regenerate_id(true)` conclua antes de atribuir `uid` e `papel`. Usa modo estrito, cookies apenas, `HttpOnly`, `SameSite=Lax` e `Secure` quando HTTPS é reconhecido ou exigido na configuração. O bloqueio de escrita da sessão é liberado antes das consultas e do download.

### F11 — A média divide por zero quando não há primeira resposta
`code/lib.php:109–116` · Bug · **Média** · Confiança **100/100** · Corrigido.

Base vazia e conjunto só com `NULL` deixavam o contador em zero; a divisão final ainda era executada. Isso podia impedir a abertura da própria listagem. Agora `AVG` ignora os nulos e `COALESCE` cobre a ausência de observações; a API retorna `0.0`. Uma falha de banco não é convertida em média zero.

### F12 — O indicador de SLA inclui atendimentos de outros clientes
`code/lib.php:107–116`; `code/index.php:74–78` · Segurança · **Média** · Confiança **100/100** · Corrigido.

A amostra original só excluía minutos nulos. Mesmo quem não teve resposta via o tempo médio dos demais. Embora não entregasse as linhas individuais, o indicador revelava informação de terceiros e não representava os chamados da lista. O mesmo escopo de dono agora se aplica ao agregado; o indicador técnico e o relatório global sem sessão mantêm a média de toda a base.

### F13 — Listagem e CSV fazem consultas N+1 para resolver técnico
`code/lib.php:85–92`; também `64–71,136–144` · Performance · **Média** · Confiança **100/100** · Corrigido.

Cada linha atribuída disparava uma busca em `usuarios`, mesmo para o mesmo técnico. Ambos os caminhos passaram a usar `LEFT JOIN` por `tecnico_id`. Um `INNER JOIN` teria eliminado chamados sem técnico: por isso o teste inclui o chamado 104, que aparece com `-`. A chave `tecnico_nome` foi preservada. O contador do banco confirmou um único `SELECT` de dados para a lista e um para o CSV, independentemente das atribuições no seed.

### F14 — O cálculo de uma única média transfere todas as observações ao PHP
`code/lib.php:109–115` · Performance · **Média** · Confiança **100/100** · Corrigido.

Para produzir um escalar, o código transferia cada medição e acumulava contagem e soma em PHP. O banco agora calcula a média e devolve uma célula, sem eliminar o custo inevitável de ler a amostra. `1e0` na expressão preserva o cálculo de ponto flutuante em vez de introduzir arredondamento decimal fixo. Só a página arredonda para exibição.

### F15 — O cabeçalho CSV não tem os bytes prometidos no manifesto
`code/lib.php:130` · Bug · **Média** · Confiança **100/100** · Corrigido.

O espaço em `Aberto em` levava `fputcsv` a escrever `"Aberto em"`. Um leitor CSV reconhece o mesmo campo, mas um importador que compara a primeira linha literalmente rejeita o arquivo. A primeira linha fixa agora é `ID,Titulo,Status,Tecnico,Aberto em`, seguida de LF, inclusive em exportações sem dados. As linhas variáveis não são concatenadas manualmente.

### F16 — O escape implícito do CSV pode perder os limites de um campo
`code/lib.php:138–144` · Bug · **Média** · Confiança **98/100** · Corrigido.

A barra invertida tinha tratamento especial no escape padrão de `fputcsv`. Se ela precedesse uma aspa no título, um leitor que usa aspas duplicadas podia encerrar o campo no lugar errado. O exportador define vírgula, aspas e escape vazio explicitamente. A leitura de volta no teste recuperou um título com vírgula, barra seguida de aspas e quebra de linha sem perder caracteres ou criar registros extras.

### F17 — Falhas de conexão e consulta não têm resposta de erro confiável
`code/index.php:9–13`; exemplos em `code/lib.php:85–88,126–135` · Bug · **Média** · Confiança **99/100** · Corrigido no painel.

Uma exceção do construtor ignorava o teste posterior de `connect_errno`; sem exceção, `die` não definia status de erro. A listagem podia usar um resultado `false`, e o exportador também podia simplesmente retornar sem entregar arquivo. No ponto de entrada, habilitei o modo estrito do mysqli e tratamento uniforme antes das rotas: erro HTTP genérico, registro de classe, código, arquivo e linha, sem despejar parâmetros ou credenciais. O exportador prepara a consulta antes dos headers e verifica leitura e escrita. Uma interrupção **depois** de enviar bytes ainda pode deixar um arquivo parcial: nesse ponto o servidor já não pode trocar o status recebido pelo cliente.

### F18 — Campos HTTP não escalares chegam a funções tipadas ou viram IDs
`code/index.php:52–53`; também `21–22,72–73` · Bug · **Média** · Confiança **100/100** · Corrigido.

Login, senha e busca podiam ser arrays onde se esperava `string`, causando `TypeError`. O cast de `ver` podia converter uma lista ou aceitar apenas a parte numérica inicial de um valor inválido. A leitura comum rejeita formatos não escalares com 400. Para o detalhe exige-se um inteiro decimal completo, preservando IDs com zeros iniciais; textos que não são IDs caem na apresentação de não encontrado.

### F19 — O painel não limita tentativas de autenticação
`code/index.php:20–29` · Segurança · **Média** · Confiança **98/100** · Não corrigido no pacote.

Todo POST de login chega à verificação sem limite compartilhado de tentativas. Isso permite tentativas repetidas e, com hashes caros, pode consumir capacidade. Não há configuração do proxy no pacote para concluir se a instalação já mitiga o risco. Recomendo limite compartilhado por origem e conta, monitoramento e medição da capacidade de autenticação. Um contador local à sessão não seria uma correção confiável.

### F20 — A listagem materializa todo o resultado e a busca procura infixos
`code/lib.php:80–92` · Performance · **Média** · Confiança **100/100** · Não corrigido.

O array cresce com o número de chamados; na rota web, cresce também a tabela HTML. A pesquisa `%termo%` exige examinar os títulos candidatos e não equivale a uma busca por prefixo. O filtro de dono e a remoção do N+1 reduzem custo, mas não limitam o resultado para um técnico. Inserir `LIMIT` silenciosamente omitiria chamados. Uma interface paginada opcional deve ser acordada e os planos medidos com dados representativos antes de alterar a indexação.

### F21 — CSV de integração pode ser interpretado como fórmula por planilhas
`code/lib.php:138–143` · Segurança · **Média** · Confiança **92/100** · Não corrigido no formato legado.

`titulo` e o nome do técnico são dados textuais. O escape CSV protege a divisão em colunas, não determina o tipo de célula no programa que abre o arquivo. Se esses campos tiverem origem não confiável e uma planilha os avaliar como fórmulas, a exibição ou o cálculo pode diferir do texto gravado. Não há evidência de execução no painel. Prefixar o conteúdo modificaria o valor lido pelo faturamento; para uso humano, importar as colunas como texto ou oferecer outra exportação com células explicitamente textuais.

### F22 — Roteamento, autenticação e montagem do HTML estão intercalados
`code/index.php:15–97` · Arquitetura · **Baixa** · Confiança **100/100** · Corrigido sem trocar o ponto de entrada.

As mesmas ramificações liam o pedido, mudavam a sessão, consultavam e imprimiam. Mover um `echo` ou `exit` podia afetar os headers e o fluxo de outra rota; testar uma página exigia acionar seus efeitos de banco. O roteador agora seleciona a operação e passa os dados a funções de apresentação, que não fazem consultas. Autenticação, leitura de entrada e escape têm pontos próprios. Não foi necessário mover arquivos nem adotar framework.

### F23 — A regra de prioridade esconde seus limites em quatro níveis de `if`
`code/lib.php:40–59` · Qualidade · **Baixa** · Confiança **100/100** · Corrigido.

O texto dependia de percorrer quatro decisões aninhadas, tornando fáceis de confundir ausência de resposta, zero minutos e limite exato de 30 minutos. Não havia evidência de que os textos estivessem errados. Usei retornos antecipados nessas fronteiras, mantendo os cinco resultados e a distinção entre atraso de prioridade 4 e demais atrasos altos.

### F24 — Um status fora do domínio aparece como Resolvido
`code/lib.php:26–34`; `code/schema.sql:18` · Bug · **Baixa** · Confiança **99/100** · Não corrigido.

O `else` final cobre tanto 3 quanto qualquer inteiro diferente de 1 e 2. O comentário SQL documenta o domínio, mas `TINYINT` não o impõe. Um valor inesperado de uma integração poderia, portanto, ser contado como resolvido. Não foi observado no seed. Mantive o fallback e os três rótulos públicos; antes de impor restrição na base ou criar um rótulo, é preciso conhecer os códigos existentes e combinar com quem consome os relatórios.

## Decisões de compatibilidade

- **Superfície pública.** Mantidos `mysqli`, as sete assinaturas (inclusive os nomes dos parâmetros), as rotas `busca`, `ver`, `export=csv`, os textos de status, a ordem e os links da tabela `tabela-chamados`. A listagem conserva `c.*` e `tecnico_nome`; o detalhe não ganha campos. Preservados nulos e o formato textual dos campos numéricos dos chamados na conexão padrão, apesar do uso de prepared statements. `autenticar` só retorna `id`, `nome`, `papel` ou `null`.
- **Limite de confiança.** Os jobs e relatórios que chamam diretamente a biblioteca com sua conexão e **sem sessão de usuário** mantêm a leitura integral. Essa é uma interface servidor-servidor confiável, não uma rota pública anônima. O ponto de entrada inicia e verifica a sessão antes de chegar às consultas. Não se concede acesso de técnico por ausência de `papel` em uma sessão, por um parâmetro GET nem por ser o técnico atribuído.
- **Dados, não apresentação, no CSV.** Não adicionar BOM, tradução de cabeçalho ou prefixos nos campos de máquina (F15, F16, F21). Mantidos nome do download, cinco colunas e linhas em ordem de ID. `tecnicoNome` e a constante `EXPORT_DIR` continuam disponíveis por compatibilidade potencial, mas nenhum caminho de exportação depende deles (F9, F13).
- **Sem mudanças silenciosas de escala ou domínio.** Não paginar por padrão, não trocar busca de trecho por prefixo, não impor índices ou um novo status sem inventário de dados (F20, F24). Não alterar regras de SLA por preferência de estilo (F23).
- **Transição, não bloqueio geral.** Mantidos o leitor de MD5 transitório e as credenciais de exemplo para não bloquear consumidores na atualização (F7). Não simular troca de segredos, remoção de arquivos de produção ou limite de login distribuído dentro deste pacote (F5, F6, F9, F19).

## Implantação em base existente

1. Fazer backup e validar em homologação com a versão de **MySQL 8** em uso. Confirmar suporte a `PASSWORD_ARGON2ID` no PHP dos servidores, `mysqli`/`mysqlnd` e capacidade para a verificação de senha. O código usa `fputcsv` com escape vazio; usar PHP compatível (7.4 ou posterior para esse parâmetro).
2. Antes de publicar o código, **ampliar a coluna sem substituir os hashes existentes**: `ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;`. Executar na janela adequada ao volume e à versão do banco. `schema.sql` e `seed.sql` são para base nova/de teste: não recarregar o seed sobre dados reais.
3. Provisionar novas credenciais fora do repositório: `DB_PASS` obrigatória no painel e `SMTP_API_KEY` nos consumidores de e-mail. Coordenar a troca nos consumidores e revogar os valores antigos. Além de leitura, o login precisa de permissão para atualizar `usuarios.senha`; não precisa de direitos de DDL. Desativar exposição de erros ao cliente e impor HTTPS; se o TLS termina no proxy, configurar `session.cookie_secure=1` no PHP.
4. Coordenar a virada de **todos os leitores de senha**: depois da primeira conversão não é seguro devolver tráfego a uma versão que só lê MD5. Acompanhar a contagem de hashes antigos, definir prazo de redefinição/desativação para inativos e então retirar a transição. Não registrar senhas nem hashes nas métricas.
5. Remover o antigo `chamados.csv` dos diretórios servidos e revisar eventual exposição. Verificar a proteção de login no ingresso e a política de importação em planilhas. Nenhuma dessas operações foi executada em produção.

## Validação executada

Usei instância descartável de **MariaDB 11.8.6**, via socket privado, e **PHP 8.4.26** com `mysqli`/`mysqlnd`. Carreguei os arquivos SQL entregues e exercitei a rota por HTTP e a biblioteca diretamente. A conta usada no teste HTTP só tinha leitura e atualização de `usuarios.senha`. Não houve acesso à base de produção. Este teste não equivale a homologação em MySQL 8 nem a ensaio de carga.

| Identidade do seed | IDs na listagem (mais recente primeiro) | IDs no CSV (ordem crescente) | SLA exibido |
| --- | --- | --- | --- |
| Ana | 105, 102, 101 | 101, 102, 105 | 26 min |
| Bruno | 104, 103 | 103, 104 | 0 min |
| Carla e Diego, cada um | 105, 104, 103, 102, 101 | 101, 102, 103, 104, 105 | 26 min |

- `php -l` passou nos três arquivos PHP. Sem credencial de banco, a resposta foi genérica. O log dos testes HTTP válidos não registrou avisos, depreciações nem erros fatais.
- Antes do login, lista, detalhe e CSV mostraram o formulário. Os quatro logins válidos renovaram o ID de sessão; senha incorreta não autenticou. Rebaixar temporariamente uma técnica com sessão aberta retirou a visão global na requisição seguinte; restituir o papel a restaurou.
- Conferidos o ID da tabela, a ordem das colunas, os links, os rótulos, os filtros de busca e a igualdade da resposta para detalhe de terceiro/inexistente. Termo com apóstrofo, acento, aspas e `&` permaneceu texto no formulário e no parágrafo; pesquisa e exportação não herdaram escape HTML. Parâmetros não escalares de busca/detalhe receberam 400.
- CSV: conferidos os headers HTTP, os bytes da primeira linha, campos e ordenação, o técnico `-` e a leitura de um campo multilinha com pontuação. Base vazia: lista vazia, média `0.0`, CSV só com cabeçalho. Só `NULL` na amostra de Bruno: `0.0`; amostra global do seed: `77/3` sem arredondamento na função.
- API direta: confirmados chaves e tipos da autenticação/detalhe, contagem e ordem sem sessão, escopo de sessão cliente/técnica e recusa de sessões incompletas. Um registro MD5 de teste permaneceu inalterado após erro de senha, migrou no primeiro acerto e continuou válido no segundo, sem nova escrita. Conferidas 49 combinações de prioridade/minutos, incluindo `NULL`, 30 e 31. Para os cinco registros do seed, o contador `Com_select` variou em **1** na listagem e **1** no CSV (medições isoladas da biblioteca).
