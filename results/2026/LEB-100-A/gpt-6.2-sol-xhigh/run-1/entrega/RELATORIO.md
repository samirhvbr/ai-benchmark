# Relatório técnico — LEB-100-A (1.1)

## Resumo

O painel autenticava os usuários, mas não limitava os chamados ao dono em nenhuma das rotas. A busca também interpolava o termo em SQL e o refletia sem escape em dois pontos do HTML. Corrigi esses caminhos, removi os segredos do fonte e eliminei o arquivo compartilhado na exportação. A listagem e o CSV deixaram de consultar o nome do técnico linha a linha. As funções destinadas às integrações mantêm as assinaturas e as consultas globais; na web, o escopo é definido pelo papel atual do usuário.

As linhas abaixo são as do **pacote original**. A confiança indica a evidência do mecanismo no código, não a probabilidade de exploração em produção. Não examinei a configuração de produção nem verifiquei se os segredos expostos ainda estão ativos.

## Achados em ordem de prioridade

### F1 — Injeção SQL na busca
**Local:** `code/lib.php`, linhas 78–85. **Severidade:** crítica. **Confiança:** 100/100. **Corrigido.**

`index.php` entrega `busca` a `listarChamados`, que concatena o termo entre as aspas do `LIKE` antes de chamar `query`. Assim, parte da entrada pode ser interpretada como sintaxe SQL e alterar a consulta. Um usuário autenticado pode obter dados além dos chamados autorizados, conforme as permissões da conta de banco. O padrão do `LIKE` é agora um parâmetro separado do SQL. O filtro de dono, quando aplicável, também é vinculado por parâmetro.

### F2 — Senha do banco embutida no fonte
**Local:** `code/config.php`, linhas 8–12. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código.**

Na ausência de `DB_PASS`, o código usa uma senha literal descrita como senha de produção. Distribuir uma cópia do fonte já a expõe; se ainda for válida e o banco estiver acessível, ela concede as permissões da conta do painel. Removi o fallback. A aplicação web não abre a conexão se a variável estiver ausente ou vazia. Retirar o literal não revoga cópias anteriores: a rotação é uma ação de implantação.

### F3 — Listagem e busca sem escopo de dono
**Local:** `code/index.php`, linhas 72–74. **Severidade:** alta. **Confiança:** 100/100. **Corrigido.**

O controlador lê `uid` e `papel`, mas chama a função global de listagem. Um cliente vê os títulos, os estados e os técnicos dos chamados de outros clientes, também nos resultados de busca. A rota agora usa o papel lido do banco para a sessão e filtra pelo `usuario_id` antes de retornar os dados. Técnicos continuam vendo todos os chamados. Um papel inexistente ou inválido não recebe acesso global.

### F4 — Detalhe acessível sem verificar o dono
**Local:** `code/index.php`, linhas 52–64. **Severidade:** alta. **Confiança:** 100/100. **Corrigido.**

O controlador busca `ver` só por `chamados.id`. A consulta não sabe quem está logado, então um cliente pode receber a descrição completa de um chamado alheio. A consulta de detalhe para cliente agora combina ID e dono. ID inexistente e chamado de outro dono produzem o mesmo `404` e a mesma mensagem, sem confirmar a existência do chamado.

### F5 — CSV exporta chamados de outros clientes
**Local:** `code/index.php`, linhas 44–47. **Severidade:** alta. **Confiança:** 100/100. **Corrigido.**

A rota invoca a exportação global da biblioteca, que busca todos os registros. O download expõe em lote títulos, responsáveis, datas e estados de outros clientes. A consulta que gera o CSV da rota agora limita o conjunto ao dono para clientes. Técnicos e consumidores autorizados da função pública `exportarCsv` mantêm acesso ao conjunto global.

### F6 — XSS refletido no campo e no texto da busca
**Local:** `code/index.php`, linhas 79–83. **Severidade:** alta. **Confiança:** 100/100. **Corrigido.**

O termo chega cru ao atributo `value` do campo e ao texto de “Resultados para”. O navegador pode interpretar os caracteres enviados como marcação e executar código no contexto do painel de uma vítima autenticada. Codifiquei o termo só na saída, com `htmlspecialchars`, `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8. A consulta recebe o termo original como dado. Título, descrição e nome do técnico usam o mesmo escape na renderização.

### F7 — Arquivo de exportação compartilhado entre requisições
**Local:** `code/lib.php`, linhas 125–150. **Severidade:** alta. **Confiança:** 100/100. **Corrigido.**

Cada chamada abre o mesmo `chamados.csv` com `w`, truncando-o, e só depois o reabre por meio de `readfile`. Em concorrência, uma requisição pode truncar ou sobrescrever o arquivo enquanto outra escreve ou lê. Isso corrompe downloads; um arquivo compartilhado também não pode isolar exportações de donos diferentes. Cada consulta agora escreve diretamente na saída da própria requisição, sem arquivo intermediário.

### F8 — Senhas armazenadas com MD5 sem salt
**Local:** `code/lib.php`, linhas 13–20; coluna em `code/schema.sql`, linha 7. **Severidade:** alta. **Confiança:** 100/100. **Corrigido com transição.**

`autenticar` compara um MD5 simples com a coluna `CHAR(32)`. Senhas iguais têm hashes iguais e, se a tabela vazar, a verificação offline de palpites é barata. A coluna comporta hashes modernos e o seed usa Argon2id com salts distintos. A autenticação usa `password_verify` para hashes modernos; aceita MD5 apenas para contas legadas e o substitui após uma senha correta, com atualização condicionada ao hash lido para não sobrescrever uma troca concorrente. A resposta não inclui o hash. Contas que não voltarem a autenticar ainda precisam de redefinição.

### F9 — Chave SMTP embutida na configuração
**Local:** `code/config.php`, linhas 14–15. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código.**

A configuração distribui uma chave literal do serviço transacional. Embora o pacote não use essa chave para enviar e-mail, se estiver ativa sua divulgação dá acesso às operações que ela autoriza. Mantive a constante para consumidores existentes, mas o valor vem exclusivamente do ambiente. A chave anterior deve ser revogada e substituída fora do código.

### F10 — Sessão não renovada após o login
**Local:** `code/index.php`, linhas 15–26. **Severidade:** média. **Confiança:** 98/100. **Corrigido.**

A sessão anônima recebe `uid` sem mudar de identificador. Se outra pessoa conseguir fazer a vítima usar um identificador que ela conhece, esse identificador continua válido depois do login. O painel passa a renovar e invalidar o ID anterior antes de gravar `uid`, a aceitar IDs só por cookie em modo estrito e a configurar `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS ou conforme a configuração PHP no proxy.

### F11 — Indicador de SLA agrega chamados de outros clientes
**Local:** `code/index.php`, linhas 72–78. **Severidade:** média. **Confiança:** 100/100. **Corrigido.**

`mediaResposta($db)` agrega todos os chamados com resposta e imprime o valor para qualquer usuário. Mudanças em chamados alheios passam a afetar um indicador que o cliente pode observar, além de distorcer a visão dele sobre o seu próprio atendimento. A média web usa o mesmo escopo de dono da listagem. Continua independente do termo de busca, e a função pública continua global.

### F12 — Divisão por zero quando não há respostas
**Local:** `code/lib.php`, linhas 107–116. **Severidade:** média. **Confiança:** 100/100. **Corrigido.**

A consulta original descarta `NULL` e deixa o contador em zero se o banco estiver vazio ou se não houver primeira resposta registrada. Em PHP 8, a divisão por zero interrompe a listagem. A correção devolve `0.0` nesse caso e usa apenas respostas não nulas quando há dados. Também cobre clientes sem chamados ou só com chamados pendentes.

### F13 — Login sem limitação de tentativas no painel
**Local:** `code/index.php`, linhas 20–28. **Severidade:** média. **Confiança:** 100/100. **Não corrigido.**

Toda submissão de login bem formada chega a `autenticar`, sem contador nem desaceleração. Sem proteção externa, isso permite tentativas repetidas de senha e aumenta o custo imposto ao servidor com Argon2id. O pacote não inclui o proxy nem um armazenamento compartilhado para contadores. Não acrescentei um bloqueio local por cookie, que não limitaria tentativas entre sessões. Recomendo limitar frequência e concorrência no ponto de entrada e ajustar o limite de acordo com as métricas do serviço.

### F14 — Texto do CSV pode ser interpretado por planilhas como fórmula
**Local:** `code/lib.php`, linhas 136–144. **Severidade:** média. **Confiança:** 90/100. **Não corrigido.**

A exportação grava título e nome do técnico como texto do banco. O escape CSV protege a estrutura do arquivo, mas a planilha pode interpretar um valor como fórmula quando o abre. O risco depende de alguma rotina gravar conteúdo não confiável nesses campos e de o arquivo ser aberto em uma planilha; o painel entregue não tem rota de escrita desses dados. Não prefixei os valores do CSV de integração: isso altera os dados lidos pelos importadores e a mitigação depende da planilha. Para uso humano, proponho aprovar uma saída separada com células tipadas como texto. Referência: OWASP, *Web Security Testing Guide*, seção “CSV Injection”.

### F15 — Consultas N+1 de nomes de técnicos
**Local:** `code/lib.php`, linhas 64–71 e 85–90; também linhas 132–137 no CSV. **Severidade:** média. **Confiança:** 100/100. **Corrigido.**

Depois de buscar os chamados, ambos os laços consultam `usuarios` mais uma vez por chamado atribuído, inclusive quando vários registros têm o mesmo técnico. Isso aumenta as idas ao banco proporcionalmente ao número de registros. Um `LEFT JOIN` com `COALESCE` traz os nomes na própria consulta e mantém `-` quando não há técnico. O filtro da função pública tem uma consulta adicional, de tamanho fixo, para preservar o comportamento dos tipos numéricos do `mysqli` da conexão do consumidor.

### F16 — A média transfere todas as respostas para o PHP
**Local:** `code/lib.php`, linhas 109–116. **Severidade:** média. **Confiança:** 100/100. **Corrigido.**

Para calcular um único número, a consulta retorna uma linha por chamado respondido e o PHP percorre todas elas. Isso aumenta o volume transferido e a memória usada pelo resultado. O SQL agora devolve apenas `SUM` e `COUNT` de `minutos_resposta`, e o PHP calcula o mesmo quociente em `float` sem limitar a precisão ao arredondamento de uma média SQL decimal. O banco ainda precisa ler o conjunto aplicável.

### F17 — Exportação acoplada a um diretório gravável do servidor
**Local:** `code/lib.php`, linhas 123–135; diretório em `code/config.php`, linhas 17–18. **Severidade:** média. **Confiança:** 100/100. **Corrigido.**

A função pública promete escrever na saída, mas depende de `EXPORT_DIR` definido em outro arquivo e de uma pasta que já exista e seja gravável. Se `fopen` falha, retorna sem CSV; o fluxo também abre o arquivo antes de saber se a consulta vai funcionar. O caminho de exportação agora executa a consulta antes de iniciar a saída e não precisa de arquivo, configuração de diretório nem acesso de escrita no servidor.

### F18 — Falhas de banco não têm tratamento consistente na rota
**Local:** `code/index.php`, linhas 9–13. **Severidade:** média. **Confiança:** 99/100. **Corrigido.**

A checagem de `connect_errno` depois do construtor não intercepta uma exceção do `mysqli` em modo estrito. A troca de charset também não era verificada. Além do erro de serviço, mensagens de diagnóstico poderiam aparecer ao visitante se `display_errors` estivesse ativo. A entrada web explicita modo estrito, desabilita a exibição de erros e registra falhas no servidor. Antes do envio da resposta, retorna um `500` genérico; uma falha de transmissão após o início de um download ainda pode interrompê-lo.

### F19 — Parâmetros HTTP com formato inesperado
**Local:** `code/index.php`, linhas 20–22; também linhas 52–53 e 72–73. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido.**

O PHP pode montar arrays a partir de campos da requisição. O código os repassava a funções que exigem `string`, provocando `TypeError` no login ou na busca; no detalhe, o cast de array para inteiro podia consultar um ID não solicitado. Agora o login só processa strings, a busca não escalar é ignorada e o detalhe aceita apenas um parâmetro textual em formato de ID inteiro.

### F20 — Cabeçalho CSV não literal e escape dependente do padrão do PHP
**Local:** `code/lib.php`, linhas 130–144. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido.**

`fputcsv` põe aspas em `Aberto em`, de modo que o cabeçalho anterior tem os campos corretos, mas não o texto exato do manifesto. Já o escape implícito com barra invertida pode prejudicar a leitura interoperável de campos com aspas e ficou obsoleto no PHP 8.4. Escrevi o cabeçalho literal e desativei explicitamente o escape proprietário nas linhas, mantendo vírgulas como separadores e aspas duplicadas dentro de campos. Referência: Manual do PHP, `fputcsv`, parâmetro `escape`.

### F21 — Status inválido aparece como resolvido
**Local:** `code/lib.php`, linhas 26–34. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido.**

O banco aceita inteiros fora dos três estados e `formatarStatus` devolvia `Resolvido` para tudo que não fosse `1` nem `2`. Um dado inválido assim aparece como chamado encerrado no painel e no CSV. Os códigos 1, 2 e 3 mantêm exatamente os textos contratados; valores inválidos agora aparecem como `Desconhecido`. Não adicionei restrição de dados que possa falhar sobre registros históricos.

### F22 — Classificação de prioridade combina categorias distintas
**Local:** `code/lib.php`, linhas 40–58. **Severidade:** baixa. **Confiança:** 96/100. **Não corrigido.**

A prioridade crítica só tem rótulo próprio acima de 30 minutos; em até 30 minutos vira `Alto - dentro do SLA`. A prioridade baixa recebe `Normal` quando já respondida, e sem resposta todas recebem o mesmo rótulo. Isso oculta distinções registradas no banco na coluna de prioridade. O manifesto não define se o rótulo deve mostrar a prioridade nominal, o SLA ou ambos. Mantive o comportamento da função pública até validar a matriz de rótulos com os consumidores.

### F23 — Volume da listagem cresce sem limite
**Local:** `code/lib.php`, linhas 78–92. **Severidade:** baixa. **Confiança:** 98/100. **Não corrigido.**

A consulta usa `SELECT *`, busca por substring com `%` inicial e materializa todos os resultados em um array, incluindo a descrição que a tabela HTML não exibe. Em bases grandes, uma listagem ampla pode exceder a memória do PHP ou ficar lenta, mesmo sem o N+1. Não adicionei um limite silencioso, não removi campos da função pública e não troquei a semântica da busca por prefixo. Paginação web e projeção menor exigem validação com consumidores e métricas de volume.

## Decisões de compatibilidade

- Mantive `mysqli`, os caminhos e as sete assinaturas públicas, incluindo a ordem dos campos retornados por `autenticar`. O detalhe e a listagem preservam as chaves e o comportamento dos tipos numéricos do `mysqli`, inclusive com `MYSQLI_OPT_INT_AND_FLOAT_NATIVE`. As funções globais da biblioteca são para scripts de confiança; não inferem identidade de uma sessão web. O controlador chama variantes com escopo explícito em todas as rotas de chamados.
- Mantive rotas, parâmetros, colunas, `id="tabela-chamados"`, links de detalhe, ordenações e rótulos de status válidos. A busca preserva o comportamento de padrões `LIKE` (incluindo `%` e `_`), mas o termo não entra na sintaxe do SQL. O indicador continua a medir o conjunto visível antes da busca.
- Mantive o nome do download, as cinco colunas e o conteúdo textual do CSV. A segurança de planilhas (F14) pede outro formato para uso humano, não a alteração silenciosa dos dados de integração. Mantive a constante `EXPORT_DIR` e a função `tecnicoNome` por compatibilidade com eventuais scripts; a listagem e a exportação não dependem mais delas.
- Não alterei a regra de SLA/prioridade (F22), não adicionei paginação, índices ou limites arbitrários (F23) e não introduzi infraestrutura de controle de tentativas (F13). Não reestruturei a aplicação em outro framework nem alterei os dados de chamados do seed. O seed continua exclusivamente para teste.

## Implantação

1. Em base existente, amplie a coluna **antes** de publicar o código: `ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;`. Em base nova, `schema.sql` já tem a definição. Conceda à conta que autentica permissão de leitura e `UPDATE` restrita à coluna `usuarios.senha`, necessária para migrar os hashes.
2. Valide PHP com `mysqli`/mysqlnd e suporte nativo a Argon2id, além de PHP 7.4 ou superior para o escape CSV explícito. Configure `DB_PASS` e, onde houver notificações, `SMTP_API_KEY` pelo ambiente. Rotacione as credenciais anteriores. Não publique `seed.sql` ou `schema.sql` como arquivos baixáveis do servidor web.
3. Invalide sessões anteriores no rollout. Use HTTPS e, se a terminação TLS ocorrer no proxy, configure `session.cookie_secure=1` no PHP. Depois do período de transição, redefina as senhas das contas ainda em MD5 e retire o caminho legado. Uma reversão para o autenticador original não entende os novos hashes; planeje uma reversão que mantenha a verificação moderna.

## Validação executada

- `php -l` passou nos três arquivos PHP. Os testes de integração usaram PHP 8.4.26 e MariaDB 11.8.6 locais, em um banco temporário; MySQL 8 não foi executado neste ambiente.
- Comparei os retornos da listagem com e sem busca, do detalhe e da média com o original, com tipos numéricos nativos ligados e desligados. Conferi as sete assinaturas, os quatro logins do seed, senha incorreta, migração após `ALTER TABLE`, senha legada longa e média vazia. A contagem de consultas confirma que o número de `SELECT`s de listagem e exportação não cresce por chamado.
- Via HTTP, verifiquei os papéis, alteração de papel em sessão já autenticada, detalhe próprio e alheio, isolamento da média, busca com apóstrofo e aspas, estrutura HTML, CSV global e por dono, campos com vírgula e aspas, 24 downloads concorrentes, mudança de ID de sessão e tratamento de parâmetros em array. `DB_PASS` ausente ou inválida resultou em `500` sem diagnóstico no corpo, mesmo com a exibição de erros habilitada na configuração inicial de teste. A exportação em CLI também passou sem diretório de relatórios.
