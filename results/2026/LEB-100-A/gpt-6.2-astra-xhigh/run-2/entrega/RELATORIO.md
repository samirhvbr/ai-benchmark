# Relatório técnico — Painel de Chamados do ISP

## Resumo

O painel autenticava o usuário, mas usava consultas globais nas rotas de clientes. A correção delimita por dono a listagem, a busca, o detalhe, o indicador de SLA e o CSV. Técnicos continuam vendo todos os chamados e as rotinas internas mantêm as sete assinaturas do manifesto.

Dos 24 achados, 22 receberam correção no código. Permanecem em aberto a interpretação de células por planilhas (F16) e o custo das listagens muito grandes (F20). A rotação dos dois segredos e a conclusão da migração de senhas exigem atuação na instalação. As linhas indicadas abaixo seguem a numeração do pacote original.

## Achados por prioridade

### F1 — A busca permite alterar a consulta SQL

`code/lib.php`, linhas 80–85 · Segurança · Severidade crítica · Confiança 100/100 · corrigido no código.

O termo recebido em busca era concatenado entre aspas no LIKE e a instrução inteira seguia para mysqli::query(). O banco interpretava parte do título pesquisado como sintaxe SQL. Um usuário autenticado podia alterar a seleção e ler dados fora da busca, até o limite dos privilégios da conexão do painel.

A listagem vincula o padrão completo a um parâmetro de uma instrução preparada. O predicado de dono usa parâmetros próprios. Os curingas de LIKE continuam disponíveis.

### F2 — O detalhe ignorava o dono do chamado

`code/index.php`, linhas 52–64 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

verChamado() selecionava o registro pelo ID. Antes de imprimir o título e a descrição, a rota verificava apenas se havia encontrado uma linha. Um cliente conseguia ler o conteúdo integral de um chamado de outro cliente. Converter o ID em inteiro mantinha a consulta numérica, mas deixava a decisão de acesso em aberto.

A rota consulta o papel atual e compara usuario_id com o ID do cliente antes de renderizar. Chamado alheio e inexistente produzem a mesma página. Técnicos recebem o detalhe de qualquer dono.

### F3 — Listagem, busca e indicador misturavam clientes

`code/index.php`, linhas 72–75 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

A rota enviava apenas o termo a listarChamados() e apenas a conexão a mediaResposta(). Ambas consultavam a tabela inteira, embora a sessão identificasse o leitor. A tabela expunha IDs, títulos e técnicos de terceiros. O indicador incorporava tempos de atendimento de outros clientes mesmo quando a lista estava filtrada.

As consultas web restringem usuario_id para clientes e abrangem todos os donos para técnicos. A média respeita o mesmo alcance e continua independente da busca por título. O papel é relido do banco a cada requisição autenticada.

### F4 — O download entregava a base de chamados a qualquer cliente

`code/index.php`, linhas 44–46 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

A rota de exportação retornava antes da listagem e chamava diretamente exportarCsv(). A consulta em lib.php, linha 132, percorria todos os IDs. Uma única resposta entregava títulos, status, técnicos e datas de outros clientes, inclusive de chamados que deveriam ficar restritos ao dono.

O download usa o dono calculado para a requisição. A função pública exportarCsv(mysqli $db) continua disponível para a exportação global da rotina noturna.

### F5 — A senha do banco estava embutida no fallback

`code/config.php`, linhas 10–12 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

Quando DB_PASS estava ausente ou tinha valor considerado falso pelo PHP, a configuração escolhia uma senha literal identificada no arquivo como de produção. Quem recebesse uma cópia do código também recebia a credencial. Seu uso para acessar a base dependia da validade da senha, da rede e dos privilégios da conta.

DB_PASS vem do ambiente e a rota responde com HTTP 503 se a senha estiver ausente ou vazia. A rotação da credencial já distribuída depende da operação do ISP.

### F6 — A chave de e-mail acompanhava cada cópia do código

`code/config.php`, linhas 14–15 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

SMTP_API_KEY era definida com a mesma chave literal em todas as instalações, sem consulta à configuração do ambiente. Se a chave ainda estivesse ativa, quem tivesse uma cópia poderia usar as permissões de envio concedidas pelo provedor.

A constante lê SMTP_API_KEY do ambiente. As rotas de chamados funcionam com o serviço de e-mail desconfigurado porque não utilizam essa integração. A revogação da chave antiga cabe ao operador do serviço.

### F7 — Os hashes MD5 permitiam testar senhas a baixo custo

`code/lib.php`, linhas 13–20 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

autenticar() calculava MD5 diretamente sobre a senha. O campo CHAR(32) em schema.sql, linha 7, e o seed reforçavam esse formato. Senhas iguais produziam o mesmo valor para usuários diferentes. Uma cópia dos hashes permitia testar candidatos fora do painel, sem sal por conta e sem o custo de uma função própria para senhas.

Novas bases usam VARCHAR(255) e hashes Argon2id com sais individuais. Um login legado válido migra o hash após a ampliação da coluna. A atualização condicional respeita trocas concorrentes. A função usa password_verify() para hashes modernos e devolve somente id, nome e papel.

### F8 — A busca era refletida em dois contextos HTML sem escape

`code/index.php`, linhas 79–82 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

O mesmo valor bruto de busca entrava no atributo value delimitado por aspas e no texto de um parágrafo. O navegador podia interpretar caracteres do termo como marcação. Um link de pesquisa aberto por um usuário autenticado podia alterar a página e executar conteúdo no contexto do painel.

A renderização usa htmlspecialchars() com ENT_QUOTES, ENT_SUBSTITUTE e UTF-8 nos dois pontos. A consulta recebe o termo original.

### F9 — Downloads concorrentes compartilhavam um arquivo com dados de clientes

`code/lib.php`, linhas 125–150 · Segurança · Severidade alta · Confiança 100/100 · corrigido no código.

Cada exportação abria chamados.csv em modo w no mesmo diretório, fechava o arquivo e voltava a abri-lo pelo nome com readfile(). Outra requisição podia truncá-lo ou substituir seu conteúdo nesse intervalo. A cópia permanecia no diretório configurado sob /var/www. Dois downloads podiam receber conteúdo incompleto ou trocado. Mesmo após filtrar por dono, esse caminho comum permitiria misturar respostas. O arquivo residual também ficava acessível a quem tivesse leitura do diretório.

A consulta prepara os dados da requisição e o exportador escreve cada linha em php://output. O resultado segue ordenado por ID e dispensa armazenamento compartilhado.

### F10 — O login preservava o identificador da sessão anônima

`code/index.php`, linhas 15–26 · Segurança · Severidade média · Confiança 99/100 · corrigido no código.

Após autenticar, o código gravava uid e papel na sessão já aberta. Seu identificador continuava válido após a mudança de privilégio. Se outra pessoa já conhecesse o identificador pré-login aceito pelo navegador, poderia reutilizá-lo durante a sessão autenticada.

O login exige a renovação bem-sucedida do identificador, invalida o anterior e só então grava a identidade. A sessão também usa modo estrito e cookie HttpOnly.

### F11 — O painel falhava quando faltava uma primeira resposta

`code/lib.php`, linhas 109–116 · Bug · Severidade média · Confiança 100/100 · corrigido no código.

A consulta excluía minutos_resposta nulos e a divisão final usava a contagem de linhas. Com zero linhas, o denominador era zero. Isso ocorre tanto com a tabela vazia quanto com chamados ainda sem resposta. O cálculo podia interromper a listagem com uma divisão por zero. Os dois chamados de Bruno no seed exemplificam o caso depois da aplicação da regra de dono.

A média SQL ignora NULL e conta respostas de zero minuto. COALESCE produz zero no conjunto vazio, e a função PHP devolve 0.0.

### F12 — Falhas de exportação podiam parecer downloads bem-sucedidos

`code/lib.php`, linhas 125–145 · Bug · Severidade média · Confiança 99/100 · corrigido no código.

Uma falha em fopen() causava retorno imediato. Com mysqli em modo de retorno por valor, o erro da consulta também causava retorno após a escrita do cabeçalho, fora do caminho que chamava fclose(). Os resultados das gravações não eram verificados. A requisição podia terminar vazia com HTTP 200, ou deixar no diretório um arquivo parcial. O PHP podia liberar o descritor ao destruir a variável, mas o arquivo continuava lá.

A exportação executa a consulta antes do cabeçalho, verifica abertura, leitura e escrita e fecha o stream em finally. A rota registra falhas e devolve 503 enquanto ainda é possível substituir a resposta. Uma falha após o início da transmissão pode interromper o download.

### F13 — O cabeçalho físico do CSV divergia do manifesto

`code/lib.php`, linha 130 · Bug · Severidade média · Confiança 100/100 · corrigido no código.

fputcsv() colocava aspas no último nome de coluna por conter um espaço. A leitura como campos resultava nos mesmos cinco nomes, porém a primeira linha tinha bytes diferentes do cabeçalho contratado. Uma integração que comparasse a linha literalmente podia rejeitar um arquivo válido para um leitor CSV convencional.

O exportador escreve literalmente ID,Titulo,Status,Tecnico,Aberto em seguido de uma quebra de linha, inclusive quando o resultado está vazio.

### F14 — Listagem e CSV consultavam o técnico uma vez por chamado atribuído

`code/lib.php`, linhas 87–91 · Performance · Severidade média · Confiança 100/100 · corrigido no código.

O laço chamava tecnicoNome(), que fazia um SELECT por ID. A exportação repetia a chamada na linha 137. Dois chamados atribuídos ao mesmo técnico geravam duas consultas iguais. Cada operação exigia uma consulta de chamados mais uma ida ao banco por linha com técnico. O tempo de rede e o trabalho do banco cresciam com o total de chamados.

Cada consulta traz o nome por LEFT JOIN e usa COALESCE para produzir o hífen de técnico ausente. O contador de sessão confirmou uma seleção por listagem e uma por exportação, sem contar a verificação de papel da rota.

### F15 — O escape padrão do CSV podia deslocar colunas

`code/lib.php`, linhas 138–144 · Bug · Severidade média · Confiança 100/100 · corrigido no código.

A escrita das linhas usava a barra invertida como escape implícito do PHP. Uma barra imediatamente antes de uma aspa no título podia impedir a duplicação da aspa exigida por leitores CSV convencionais. Um título que combinasse esses caracteres com uma vírgula podia ser lido como mais de um campo, deslocando status e técnico na importação.

fputcsv() recebe escape vazio, vírgula como delimitador e aspas duplas como qualificador. A duplicação das aspas preserva o valor original dos campos.

### F16 — Uma planilha pode interpretar texto do CSV como fórmula

`code/lib.php`, linhas 138–144 · Segurança · Severidade média · Confiança 88/100 · em aberto.

Título e nome de técnico entram no CSV como texto livre. As aspas do formato delimitam células. A decisão de interpretar um valor iniciado por um marcador de fórmula pertence ao aplicativo que abre o arquivo. Se um desses campos for controlável por terceiros e o arquivo for aberto com avaliação automática de fórmulas, a planilha poderá calcular conteúdo fornecido por eles. O pacote não identifica o leitor usado pelo destinatário.

Preservei o valor literal das colunas usado no faturamento. A mitigação cabe à importação como texto ou a um formato específico para planilhas, acordado com seus consumidores.

### F17 — Parâmetros em forma de lista quebravam o tratamento da requisição

`code/index.php`, linhas 72–79 · Bug · Severidade média · Confiança 100/100 · corrigido no código.

PHP aceita parâmetros HTTP estruturados como arrays. A busca chegava a uma função que exigia string, assim como login e senha na linha 22. No detalhe, o cast da linha 53 podia converter um array não vazio em ID 1. Uma requisição malformada podia terminar com TypeError ou selecionar um ID diferente do pretendido.

A entrada web valida o tipo dos parâmetros antes de enviá-los às funções ou convertê-los em ID. Valores estruturados recebem HTTP 400, e parâmetros textuais continuam seguindo as rotas existentes.

### F18 — A falha de conexão dependia do modo de erro de mysqli

`code/index.php`, linhas 9–13 · Bug · Severidade média · Confiança 100/100 · corrigido no código.

O teste de connect_errno vinha depois do construtor. No modo estrito uma exceção saltava esse teste. No modo de retorno por valor, die() terminava a resposta sem definir um status de indisponibilidade. O resultado de set_charset() também podia passar despercebido. O cliente HTTP podia interpretar o erro como sucesso. Com exibição de erros habilitada, uma exceção não tratada podia revelar detalhes da conexão.

A entrada web usa mysqli em modo estrito, trata as falhas da conexão e das consultas, registra a classe e o código do erro e devolve uma mensagem genérica com HTTP 503 antes da saída.

### F19 — O cálculo da média transferia cada observação para o PHP

`code/lib.php`, linhas 109–115 · Performance · Severidade média · Confiança 100/100 · corrigido no código.

Todo minuto de resposta não nulo era enviado ao painel. O laço só utilizava esses valores para somar e contar antes de apresentar um único número. O tráfego, o resultado mantido pelo driver e as iterações no PHP cresciam com o histórico de chamados a cada carregamento da listagem.

O banco calcula AVG e envia um único escalar ao PHP. O operando de ponto flutuante conserva a precisão da divisão, inclusive para médias periódicas.

### F20 — Listagens amplas materializam todos os registros correspondentes

`code/lib.php`, linhas 78–92 · Performance · Severidade média · Confiança 98/100 · em aberto.

A consulta seleciona todas as colunas, inclusive descricao, e a função constrói o array inteiro antes de o HTML percorrê-lo. A busca por trecho começa com curinga e pode examinar muitos títulos. Para um técnico com histórico extenso, uma única página pode consumir memória e tempo proporcionais ao número de registros. O tamanho da base em produção não foi fornecido.

O recorte por dono reduz o volume dos clientes. Mantive lista e busca completas. Paginação na interface ou uma projeção menor para a página exigem validar a navegação e medir o volume representativo.

### F21 — As rotas misturavam decisão de acesso e construção das páginas

`code/index.php`, linhas 38–97 · Arquitetura · Severidade média · Confiança 98/100 · corrigido no código.

O fluxo de exportação, detalhe e listagem fazia consulta, decisão de saída e composição de HTML no mesmo nível. A rota de exportação saía antecipadamente, e uid e papel eram lidos sem orientar nenhuma das três rotas. Uma correção localizada na tabela deixaria o detalhe e o download expostos. A mistura também duplicava a conversão dos campos de prioridade e SLA na apresentação.

A entrada calcula o alcance uma vez antes do despacho. As funções de apresentação recebem dados já selecionados, compartilham a conversão de prioridade e deixam as consultas em lib.php.

### F22 — Um status fora do domínio aparecia como resolvido

`code/lib.php`, linhas 26–34 · Bug · Severidade baixa · Confiança 100/100 · corrigido no código.

O ramo final de formatarStatus() devolvia Resolvido para qualquer inteiro diferente de 1 e 2. O TINYINT em schema.sql, linha 18, também permite armazenar outros valores além de 3. Um código inesperado podia fazer um chamado parecer encerrado no painel, no CSV e no relatório gerencial.

O código 3 passa a ter um ramo explícito. Valores fora dos três documentados recebem Desconhecido. Os três rótulos contratuais conservam a grafia original.

### F23 — A regra de prioridade escondia os limites de SLA em quatro níveis

`code/lib.php`, linhas 40–58 · Qualidade · Severidade baixa · Confiança 100/100 · corrigido no código.

Para descobrir quando devolver o rótulo crítico era preciso seguir quatro decisões aninhadas e seus ramos alternativos. NULL e 30 minutos determinam saídas diferentes de 0 e 31 em partes da regra. Uma manutenção no limite de atraso ou no tratamento da primeira resposta podia alterar rótulos usados pelo painel. O problema identificado era o custo da manutenção.

Retornos antecipados explicitam ausência de resposta, prioridade normal e limite de 30 minutos. Comparei os dois códigos em 56 combinações, inclusive prioridades fora da faixa usual.

### F24 — A sessão ficava bloqueada durante consultas e downloads

`code/index.php`, linhas 15–46 · Performance · Severidade baixa · Confiança 96/100 · corrigido no código.

Depois de ler a identidade, o código mantinha a sessão aberta até o fim. Com o manipulador de sessão em arquivos do PHP, isso mantinha um bloqueio exclusivo enquanto o painel consulta e transmite o resultado. Um download longo podia fazer outra aba da mesma sessão esperar antes de carregar a listagem ou o detalhe.

A rota copia o ID e chama session_write_close() antes de consultar o papel e os chamados. A autenticação continua gravando os dados da sessão no login.

## Decisões

- **Assinaturas públicas em mysqli e alcance global das consultas internas.** Os scripts de exportação e relatórios fornecem uma conexão, sem identidade de sessão. Mantive as assinaturas, os retornos e o funcionamento fora da web. As rotas passam o dono explicitamente às consultas de listagem, média e exportação. O detalhe verifica a propriedade antes da apresentação.
- **Semântica da busca, ordenação, links e rótulos publicados.** A busca continua por trecho e conserva os curingas de LIKE. A listagem usa criado_em decrescente e o CSV usa ID crescente. A tabela mantém o identificador, as cinco colunas e os links documentados. Os relatórios dependem dos textos dos status 1 a 3.
- **Compatibilidade de contas antigas durante a migração.** Uma senha conhecida apenas pelo seu MD5 só pode ser migrada ao receber a senha correta ou por redefinição. Enquanto a coluna conserva CHAR(32), o login verifica o legado e adia a gravação para impedir truncamento. Contas inativas requerem redefinição para eliminar os hashes restantes.
- **Texto original dos campos de faturamento e volume da listagem.** Prefixar células alteraria os dados consumidos por outros scripts. Limitar o resultado por padrão também omitiria chamados. F16 e F20 permanecem registrados para decisão com os consumidores.
- **Consultas avulsas por ID e função tecnicoNome().** verChamado() e tecnicoNome() recebem IDs tipados como inteiros. Essa origem restringe a concatenação a dígitos e sinal. Preservei a consulta unitária e a função auxiliar para possíveis chamadas existentes. A consulta repetida saiu dos dois laços.
- **Constante EXPORT_DIR, arquivos históricos e credenciais de produção.** A constante pode atender outros scripts legados. O novo exportador escreve direto na resposta. A remoção de eventuais CSVs antigos e a rotação dos segredos dependem de acesso à instalação do ISP.

## Implantação na base existente

1. Provisione uma nova senha do banco no ambiente do painel como `DB_PASS`. Revogue a senha antiga após a troca. Para as integrações que usam e-mail, distribua uma nova `SMTP_API_KEY` e revogue a antiga no provedor. Verifique o destino de eventuais cópias históricas de `chamados.csv`.
2. O PHP usado pelo painel precisa ter mysqli com mysqlnd e suporte a Argon2id em `password_algos()`. A conta usada no login precisa de leitura de usuários e chamados e de atualização da coluna `usuarios.senha` para a migração.
3. Na base legada, aplique a ampliação abaixo. O `CREATE TABLE` de `schema.sql` atende instalações novas. Coordene a ampliação com a troca de todos os processos que autenticam usuários, pois a versão antiga do login só reconhece MD5.

```sql
ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
```

Após a ampliação e a atualização dos processos, o primeiro login válido converte cada hash legado. Para contas que nunca retornarem ao painel, a operação precisará de uma redefinição de senha. As credenciais do seed continuam servindo às quatro contas de teste documentadas.

## Verificação

- Executei `php -l` nos três arquivos PHP. Os testes usaram PHP 8.4.26, mysqli/mysqlnd e MariaDB 11.8.6 em instância descartável. A execução direta em MySQL 8 e as ações de implantação ficaram fora deste ambiente.
- Comparei por reflexão as sete assinaturas e, com os mesmos dados, os retornos de autenticação, listagem, detalhe, status e média das duas versões. As linhas da lista mantiveram chaves, tipos, ordem e o hífen em `tecnico_nome`. As 56 combinações de prioridade e minutos produziram os mesmos textos.
- Pela entrada HTTP, Ana obteve os IDs 101, 102 e 105, Bruno obteve 103 e 104, e cada técnico obteve os cinco. Conferi busca por título, detalhe próprio, resposta idêntica para alheio e inexistente, tabela, links, status, cabeçalho literal do CSV e ordem crescente. A mudança de papel de Carla passou a valer em sua sessão já aberta.
- Um título com apóstrofo, aspas, acento, vírgula, quebra de linha e barra voltou intacto do CSV. A busca com pontuação conservou seu valor no formulário. Parâmetros estruturados receberam HTTP 400. O login renovou o cookie de sessão e as falhas de configuração e autenticação do banco produziram 503.
- Conferi média para tabela vazia, conjunto só com NULL, zero e resultado fracionário de 1/3. Medi uma seleção na listagem e uma na exportação, além de verificar o CSV global, o arquivo só com cabeçalho e 16 exportações por dono em até oito processos simultâneos.
- Exercitei o login sobre a coluna original `CHAR(32)`, ampliei para `VARCHAR(255)` e confirmei que uma senha incorreta preserva o hash. Dois logins concorrentes com a senha correta concluíram a migração. A leitura seguinte verificou o novo hash e devolveu o mesmo usuário.
