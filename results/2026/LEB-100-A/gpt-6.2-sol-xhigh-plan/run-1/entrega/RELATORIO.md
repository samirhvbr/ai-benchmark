# Relatório técnico — LEB-100-A

## Resumo

A revisão identificou falhas de isolamento entre clientes, injeção SQL e HTML, credenciais em código e problemas na autenticação e na exportação. As correções aplicam autorização na consulta web, preservam as assinaturas `mysqli` usadas pelos scripts internos e mantêm o formato contratado da listagem e do CSV. Dos 24 achados, 20 têm correção no código entregue. As referências de linha apontam para a versão original do pacote.

## Achados

### F1 — Injeção SQL na busca por título

`code/lib.php`, linhas 78–85. Segurança. Severidade crítica. Confiança 100/100. Corrigido no código.

A função concatenava o texto de `busca` no literal SQL usado por `LIKE` e executava a consulta resultante. Caracteres da entrada podiam ser interpretados como sintaxe SQL. Uma sessão autenticada podia interferir na consulta e acessar dados conforme os privilégios da conta do banco. Buscas legítimas com apóstrofo também podiam falhar.

Vinculei o padrão `LIKE` como parâmetro mysqli, mantendo a pesquisa por trecho e os curingas `%` e `_`.

### F2 — Detalhe sem autorização por proprietário

`code/index.php`, linhas 52–64. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

A rota repassava apenas o ID a `verChamado`, que filtrava por `id` em `lib.php` (linhas 98–101). O proprietário do chamado nunca era comparado com o usuário autenticado. Um cliente podia ler o título, a descrição, o status e a prioridade de outro cliente.

O detalhe web aplica o proprietário na consulta. Um registro fora do escopo recebe a mesma resposta de um ID ausente.

### F3 — Listagem e busca exibiam chamados de outros clientes

`code/index.php`, linhas 72–94. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

O controlador chamava a lista global. A consulta de `lib.php` (linhas 78–85) podia restringir o título, mas nunca usava `usuario_id`. Um cliente podia ver IDs, títulos, status, prioridades e nomes de responsáveis de outros clientes mesmo na listagem inicial.

A listagem web combina o filtro do proprietário com o filtro de título, quando presente. O técnico continua vendo todos os chamados.

### F4 — CSV web exportava a base inteira para clientes

`code/index.php`, linhas 41–47. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

A rota chamava `exportarCsv` logo após verificar a sessão. A consulta global em `lib.php` (linha 132) colocava todos os registros no arquivo. Uma conta de cliente podia obter em um download os títulos, os status, os responsáveis e as datas de chamados alheios.

O CSV web usa o mesmo filtro por proprietário da listagem. A função pública de exportação continua global para a rotina interna.

### F5 — Credenciais embutidas na configuração

`code/config.php`, linhas 7–15. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

`DB_PASS` possuía um valor de fallback no código e `SMTP_API_KEY` era um literal usado diretamente na configuração. Quem recebe uma cópia do pacote obtém os valores. Se continuarem válidos, permitem uso das respectivas credenciais conforme os privilégios concedidos.

Removi os literais e passei a ler os valores do ambiente. A aplicação exige `DB_PASS` configurada. A rotação das credenciais já distribuídas é uma ação de implantação.

### F6 — Busca interpolada diretamente no HTML

`code/index.php`, linhas 76–83. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

A página inseria `busca` no atributo `value` e no texto de um parágrafo sem codificar caracteres especiais. O navegador podia interpretar texto recebido na URL como marcação ou código no contexto da sessão do painel.

Apliquei `htmlspecialchars` com aspas, substituição de sequências inválidas e UTF-8 nas duas saídas.

### F7 — Senhas armazenadas em MD5 sem sal

`code/lib.php`, linhas 13–20. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

O login calculava um MD5 direto da senha e o comparava à coluna `CHAR(32)` de `usuarios` em `schema.sql` (linha 7). Senhas idênticas geravam hashes idênticos. Uma cópia da tabela permite verificar palpites rapidamente e identificar contas com a mesma senha.

Ampliei a coluna para `VARCHAR(255)`, atualizei os dados de teste e migrei a verificação para `password_hash` e `password_verify`. Contas legadas são atualizadas no login válido por comparação com o hash lido. Contas inativas continuam em MD5 até migração ou redefinição. [Manual do PHP sobre password_hash](https://www.php.net/manual/en/function.password-hash.php)

### F8 — Arquivo de exportação compartilhado entre requisições

`code/lib.php`, linhas 123–150. Segurança. Severidade alta. Confiança 100/100. Corrigido no código.

Toda exportação truncava, preenchia e relia `chamados.csv` no diretório fixo de `config.php` (linha 18). A gravação e o envio usavam o mesmo caminho, sem isolamento por requisição. Exportações simultâneas podiam produzir conteúdo truncado ou misturado. O CSV persistia no servidor e poderia ser servido diretamente se o caminho estivesse publicado.

Passei a transmitir a resposta por `php://output`, com um cursor por requisição e sem gravar o CSV em disco. Arquivos residuais de versões anteriores exigem limpeza na implantação.

### F9 — Identificador da sessão permanecia igual após o login

`code/index.php`, linhas 15–27. Segurança. Severidade alta. Confiança 98/100. Corrigido no código.

`session_start()` abria a sessão e o login gravava a identidade nela sem trocar seu identificador. A proteção efetiva do cookie também dependia da configuração externa. Se um terceiro conseguisse fixar um identificador válido antes da autenticação, ele poderia reaproveitar a sessão autenticada.

Renovo o identificador antes de gravar a identidade e inicio a sessão em modo estrito, por cookie, com `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS.

### F10 — Papel e existência da conta não eram revalidados

`code/index.php`, linhas 20–39. Segurança. Severidade média. Confiança 100/100. Corrigido no código.

O login gravava o ID e o papel na sessão. Nas requisições seguintes, o controlador verificava apenas a presença de `uid` e lia o papel gravado, sem consultar a conta. Uma sessão já aberta sobrevivia à exclusão da conta. Se o papel guardado fosse usado para autorizar consultas, uma sessão antiga conservaria os acessos de técnico.

O controlador consulta o papel atual pelo ID a cada requisição autenticada e volta ao login quando a conta deixa de ser válida.

### F11 — Células textuais do CSV podiam ser interpretadas como fórmulas

`code/lib.php`, linhas 136–144. Segurança. Severidade média. Confiança 99/100. Corrigido no código.

Títulos e nomes de técnicos eram colocados no CSV diretamente. A delimitação com `fputcsv` preserva a célula, mas não define seu tipo ao abrir uma planilha. Leitores que inferem fórmulas podem avaliar conteúdo textual de um chamado ou nome quando alguém abre o arquivo.

Prefixei com apóstrofo os títulos e nomes que começam com caracteres de fórmula, incluindo variantes comuns e espaços antecedentes, ou com controles iniciais de tabulação e quebra de linha. Isso altera apenas esses valores na representação CSV.

### F12 — Indicador de SLA incluía respostas fora do escopo

`code/index.php`, linhas 72–78. Segurança. Severidade média. Confiança 100/100. Corrigido no código.

A tela usava `mediaResposta` sem filtro. A consulta em `lib.php` (linha 109) agregava minutos de toda a tabela. Clientes viam uma métrica que incluía atividades de terceiros e que podia ser incorreta para seus próprios chamados.

O painel calcula a média no mesmo conjunto autorizado das outras rotas. A função pública do relatório continua retornando a média global.

### F13 — Divisão por zero sem chamados respondidos

`code/lib.php`, linhas 107–116. Bug. Severidade média. Confiança 100/100. Corrigido no código.

O contador só crescia para linhas com `minutos_resposta` preenchido. Com a tabela vazia ou todos os valores nulos, o código dividia por zero. O painel podia interromper a listagem no PHP 8 antes de devolver HTML. O problema também atingia os chamadores diretos da função.

Retorno `0.0` quando a contagem de respostas é zero, mantendo o tipo `float` e a média dos valores presentes nos demais casos.

### F14 — Falhas de conexão e consulta sem resposta web estável

`code/index.php`, linhas 9–13. Segurança. Severidade média. Confiança 97/100. Corrigido no código.

A checagem de `connect_errno` vinha depois do construtor mysqli, que pode lançar antes de retornar. O fluxo também não tratava exceções das consultas. Conforme `display_errors`, o PHP podia emitir diagnóstico para o navegador. Uma falha de banco podia revelar detalhes internos ou devolver uma resposta incompleta em vez de um erro HTTP estável.

Ativei explicitamente o modo de exceções mysqli, desativei diagnóstico na saída web e centralizei o erro 500 genérico antes do início do corpo. Tipo e código do erro são registrados no servidor.

### F15 — Login sem limitação de tentativas no aplicativo

`code/index.php`, linhas 20–29. Segurança. Severidade média. Confiança 100/100. Em aberto.

Cada POST ao formulário podia chamar `autenticar` novamente sem limite, atraso ou contador neste código. Tentativas repetidas de senha e consumo do processamento de hashes ficam limitados apenas pelos controles externos que a implantação tiver.

Mantive o fluxo de autenticação. Um limite precisa ser coordenado no ingresso para funcionar em todos os processos e evitar bloqueios locais inconsistentes.

### F16 — Uma consulta por chamado para buscar o técnico

`code/lib.php`, linhas 87–91. Performance. Severidade média. Confiança 100/100. Corrigido no código.

A listagem chamava `tecnicoNome` para cada linha e a exportação repetia o padrão em `lib.php` (linhas 136–137). Cada ID não nulo acionava outro SELECT, inclusive quando vários chamados tinham o mesmo técnico. O número de consultas e viagens ao banco crescia com a quantidade de chamados atribuídos.

Usei `LEFT JOIN` e `COALESCE` para trazer `tecnico_nome` na consulta principal e manter `-` para chamados sem técnico.

### F17 — Parâmetros em formato de array chegavam a funções escalares

`code/index.php`, linhas 20–23. Bug. Severidade média. Confiança 100/100. Corrigido no código.

O formulário passava `login` e `senha` sem checar se eram strings. A busca fazia o mesmo na linha 73 e o detalhe convertia a entrada da linha 53 diretamente para inteiro. PHP aceita arrays em parâmetros de formulário ou query string. Eles podiam interromper login e busca com erro de tipo ou ser convertidos em outro ID no detalhe.

Validei os escalares na fronteira. Login malformado volta ao formulário, busca malformada vira busca vazia e detalhe malformado recebe a resposta de chamado ausente.

### F18 — CSV dependia do escape implícito do PHP

`code/lib.php`, linhas 138–144. Bug. Severidade média. Confiança 100/100. Corrigido no código.

A chamada a `fputcsv` usava o escape padrão. Com barras invertidas imediatamente antes de aspas, o resultado pode divergir do escape por duplicação de aspas. PHP 8.4 também deprecia essa omissão. Leitores CSV podem decodificar incorretamente alguns campos. Diagnósticos de depreciação podem interferir na resposta quando sua exibição está habilitada.

Passei o escape vazio explicitamente para usar a duplicação de aspas e preservei as cinco colunas. [Manual do PHP sobre fputcsv](https://www.php.net/manual/en/function.fputcsv.php).

### F19 — Listagem sem limite e busca por trecho sobre o conjunto visível

`code/lib.php`, linhas 78–92. Performance. Severidade média. Confiança 100/100. Em aberto.

A consulta de listagem materializava todos os resultados no PHP. O padrão com `%` inicial requer inspecionar títulos do conjunto consultado para localizar trechos. Com mais chamados, o tempo de consulta e o tamanho da página crescem, sobretudo para técnicos com acesso global.

Mantive a lista completa e a busca por trecho, pois paginação ou outro tipo de correspondência muda o comportamento da rota e exige um contrato próprio.

### F20 — Média transferia cada resposta do banco para o PHP

`code/lib.php`, linhas 107–116. Performance. Severidade baixa. Confiança 100/100. Corrigido no código.

A função selecionava uma linha por chamado respondido e percorria todas para calcular soma e contagem. O volume transferido e o trabalho do processo PHP cresciam com o histórico de respostas.

Agreguei com `SUM` e `COUNT` no banco e mantive a divisão final em PHP para preservar a precisão da média.

### F21 — Cabeçalho serializado divergia do texto exato do contrato

`code/lib.php`, linhas 125–130. Bug. Severidade baixa. Confiança 100/100. Corrigido no código.

`fputcsv` colocava aspas no rótulo `Aberto em` por conter espaço. A linha era válida como CSV, mas seus bytes divergiam do cabeçalho literal do manifesto. Um consumidor que compare a primeira linha integralmente pode rejeitar a exportação.

Escrevi o cabeçalho literal documentado seguido de quebra de linha e deixei a serialização CSV para os dados.

### F22 — Status fora de domínio era rotulado como resolvido

`code/lib.php`, linhas 26–34. Bug. Severidade baixa. Confiança 100/100. Em aberto.

Qualquer valor diferente de 1 ou 2 caía no mesmo ramo de 3. A coluna `TINYINT` em `schema.sql` (linha 18) aceita outros inteiros. Se um status inválido for gravado, o relatório e a tela o apresentam como resolvido. Os dados de exemplo usam apenas valores válidos.

Mantive a saída legada. Uma restrição de banco ou um novo rótulo exige verificar dados e alinhar o comportamento com os consumidores.

### F23 — Regra de prioridade com condições profundamente aninhadas

`code/lib.php`, linhas 40–58. Qualidade. Severidade baixa. Confiança 100/100. Corrigido no código.

O cálculo colocava a ausência de resposta, o nível de prioridade, o limite de 30 minutos e a exceção de criticidade em níveis sucessivos de `if` e `else`. Uma alteração nos limiares ou rótulos fica sujeita a mudar acidentalmente um dos ramos de SLA.

Usei retornos antecipados para os casos independentes, mantendo rótulos, limiar e tratamento dos valores existentes.

### F24 — Controlador reúne roteamento, autenticação e HTML

`code/index.php`, linhas 17–97. Arquitetura. Severidade baixa. Confiança 100/100. Em aberto.

Login, exportação, detalhe e lista eram blocos sequenciais no mesmo arquivo, com HTML emitido diretamente. Status e prioridade eram formatados em dois fluxos de saída. Alterações transversais precisam considerar vários pontos de saída para preservar o comportamento das rotas e a estrutura contratada.

Mantive a estrutura procedural e fiz mudanças locais. Uma divisão de controladores e templates teria impacto maior que o necessário para as correções.

## Decisões

- **Assinaturas públicas, rotas, parâmetros, HTML e regras de visibilidade.** Mantive as sete assinaturas com `mysqli`, os parâmetros GET, o ID, as colunas e os links da tabela, os rótulos e a ordenação. As funções públicas globais mantêm o escopo dos scripts internos. A aplicação web usa o escopo do usuário autenticado.
- **Pesquisa por trecho e lista completa.** Mantive a lista completa, os curingas `%` e `_` e o escape de padrões do `LIKE`. O protocolo preparado na busca pode devolver campos numéricos internos como inteiros onde o protocolo de texto do `mysqli` devolvia strings. O contrato define retorno `array` e a chave `tecnico_nome`.
- **Valores de status fora do domínio.** Mantive o rótulo que os consumidores recebem hoje para valores inesperados. Uma política explícita exige levantamento dos dados e alinhamento com o relatório gerencial.
- **Contas MD5 e senhas de teste.** Mantive o acesso das contas existentes mediante migração no login e preservei as credenciais de teste descritas no manifesto. Para senhas legadas que o bcrypt truncaria ou recusaria, o código aplica SHA-256 antes do hash de senha e marca o formato. A redefinição de senhas de contas inativas requer um processo operacional.
- **Limitação de tentativas, infraestrutura e organização do controlador.** Mantive o formulário, a configuração da infraestrutura e o roteamento procedural. A limitação de tentativas depende de um controle compartilhado entre processos. A estrutura do controlador admite as correções locais com menor impacto no HTML.
- **Histórico de credenciais e CSVs pré-existentes.** A rotação de segredos distribuídos e a remoção de CSVs antigos dependem do ambiente de implantação.

## Implantação

Em uma base existente, amplie a coluna antes de ativar o PHP atualizado. Use `schema.sql` e `seed.sql` para instalações novas e ambientes de teste.

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

A conta mysqli usada no login precisa de `SELECT` e de permissão de atualização sobre `usuarios.senha`. Configure `DB_PASS` no ambiente e `SMTP_API_KEY` se houver integração ativa. Rotacione os valores distribuídos com a versão anterior e remova cópias antigas de `chamados.csv` do diretório legado. Em implantação com vários processos, ative o verificador novo em todos eles antes de liberar logins. A versão antiga aceita somente MD5 e perde acesso às contas que já migraram. Em HTTPS terminado no proxy, configure `session.cookie_secure` no PHP se o servidor não receber o indicador de HTTPS.

## Verificação

- `php -l` passou nos três arquivos PHP.
- Integração com PHP 8.4.26 e MariaDB 11.8.6 em instância isolada. Verifiquei as sete funções públicas, as quatro contas de teste, visibilidade por cliente e técnico na lista, busca, detalhe, CSV e média, renovação de sessão, parâmetros em formato de array, codificação do termo de busca, cabeçalho literal, status, ordem do CSV e 24 downloads concorrentes.
- Em uma base legada isolada, executei o `ALTER TABLE` e confirmei autenticação com migração persistida, incluindo senhas com mais de 72 bytes e com byte nulo. Senhas incorretas permaneceram recusadas e as médias sem respostas retornaram `0.0`.
- Confirmei busca com apóstrofo e aspas, leitura de células com aspas e barra invertida, prefixo em texto de planilha, mudança de escopo após alteração de papel com a sessão ativa e resposta 500 genérica quando `DB_PASS` está ausente.

A validação de banco cobriu MariaDB. MySQL 8 e a configuração da implantação de produção ficaram fora dos testes locais.
