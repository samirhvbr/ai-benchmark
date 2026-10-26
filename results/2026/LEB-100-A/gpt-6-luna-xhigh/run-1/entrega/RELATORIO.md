# Relatório técnico — LEB-100-A

## Resumo

A interface permitia que qualquer usuário autenticado consultasse chamados de outros clientes, e a busca concatenava entrada do usuário diretamente no SQL. Também havia saída refletida sem escape, credenciais no código, sessão sem renovação após login e CSV persistido em um caminho compartilhado. Corrigi esses fluxos sem alterar as assinaturas públicas ou o formato declarado do painel e do CSV. A autenticação ainda usa MD5 legado; registrei esse risco e não migrei o armazenamento porque a coluna de produção é `CHAR(32)` e uma troca segura exige migração coordenada.

As referências de linha abaixo apontam para a numeração dos arquivos originais recebidos, conforme solicitado.

## Achados

### F1 — Falta de isolamento por cliente nas rotas web

**Onde:** `code/index.php`, linhas originais 45–53 e 72–73.  
**Mecanismo:** a sessão carregava `uid` e `papel`, mas as rotas chamavam consultas sem `usuario_id`: `listarChamados`, `verChamado` e `exportarCsv` retornavam chamados sem verificar o dono. Um cliente autenticado podia trocar o ID de `?ver=` ou baixar/listar chamados de outros clientes.  
**Impacto e severidade:** exposição de títulos, descrições, técnicos e dados dos chamados entre clientes. **Alta.**  
**Confiança:** 100.  
**Correção:** adicionei consultas web com escopo por usuário para lista, detalhe e exportação; técnicos continuam vendo todos os chamados. As funções públicas sem contexto de usuário mantêm suas assinaturas e seu uso integral por rotinas internas.

### F2 — Injeção SQL na busca por título

**Onde:** `code/lib.php`, linha original 82 (função `listarChamados`).  
**Mecanismo:** o termo recebido pela rota `?busca=` era concatenado à cláusula `LIKE`. Aspas e operadores SQL enviados como termo alteravam a consulta executada pelo banco.  
**Impacto e severidade:** leitura ou alteração do comportamento da consulta por um usuário autenticado; o problema também podia contornar filtros associados à busca. **Alta.**  
**Confiança:** 100.  
**Correção:** substituí a interpolação por parâmetros preparados. Os curingas `%` e `_` continuam funcionando como antes.

### F3 — Segredos embutidos na configuração

**Onde:** `code/config.php`, linhas originais 12 e 15.  
**Mecanismo:** a senha de banco e a chave SMTP estavam literais no pacote; qualquer pessoa com acesso ao código obtinha credenciais de produção.  
**Impacto e severidade:** acesso não autorizado ao banco ou ao serviço SMTP caso esses valores ainda estejam válidos. **Alta.**  
**Confiança:** 100.  
**Correção:** removi os valores e passei a ler `DB_PASS` e `SMTP_API_KEY` do ambiente. Os valores expostos devem ser rotacionados nos serviços correspondentes.

### F4 — XSS refletido no termo de busca

**Onde:** `code/index.php`, linhas originais 79–82.  
**Mecanismo:** `busca` era inserida sem escape no atributo `value` e no texto de resultados. Um link com marcação HTML/JavaScript podia executar código no navegador de quem o abrisse autenticado no painel.  
**Impacto e severidade:** execução de script na sessão do usuário e ações em seu contexto. **Alta.**  
**Confiança:** 100.  
**Correção:** valido que o parâmetro seja texto e aplico `htmlspecialchars` com aspas, substituição de bytes inválidos e UTF-8 antes de renderizá-lo.

### F5 — CSV persistido em caminho compartilhado

**Onde:** `code/lib.php`, linhas originais 125–150.  
**Mecanismo:** cada exportação gravava `chamados.csv` em `/var/www/painel/tmp`, sobrescrevendo um nome fixo e deixando os dados no disco após a resposta. Requisições simultâneas podiam interferir no mesmo arquivo; se `tmp` for servido pelo servidor web, o arquivo também pode ser acessado fora da rota autenticada.  
**Impacto e severidade:** persistência desnecessária de dados e risco de exposição ou corrupção entre exportações. **Alta.**  
**Confiança:** 94.  
**Correção:** o CSV agora é escrito diretamente em `php://output`, sem criar arquivo compartilhado. O cabeçalho, a ordem crescente por ID e as colunas do contrato foram preservados.

### F6 — Hash de senha MD5 sem salt

**Onde:** `code/lib.php`, linha original 15; armazenamento definido em `code/schema.sql`, linha original 7.  
**Mecanismo:** a senha é reduzida a MD5 e comparada com uma coluna `CHAR(32)`. MD5 é rápido e sem salt, permitindo testar grandes quantidades de senhas por segundo após o vazamento do banco.  
**Impacto e severidade:** senhas reutilizadas podem ser recuperadas e usadas em outros serviços. **Alta.**  
**Confiança:** 100.  
**Correção:** não alterei o esquema nem o algoritmo nesta entrega. A coluna atual não comporta hashes modernos e os dados de exemplo e instalações existentes dependem do formato MD5. Uma atualização precisa de migração coordenada da coluna e dos hashes; veja **Decisões**.

### F7 — Fixação de sessão no login

**Onde:** `code/index.php`, linhas originais 23–26.  
**Mecanismo:** após autenticar, o código gravava o usuário na sessão existente sem trocar o identificador. Se um atacante conseguisse fixar um ID de sessão antes do login da vítima, poderia reutilizá-lo depois da autenticação.  
**Impacto e severidade:** sequestro da sessão autenticada em cenário de fixação. **Média.**  
**Confiança:** 90.  
**Correção:** renovo o ID com `session_regenerate_id(true)` imediatamente após autenticação válida. Também rejeito campos de login que cheguem como arrays.

### F8 — Média indefinida quando não há respostas

**Onde:** `code/lib.php`, linhas originais 107–116.  
**Mecanismo:** com zero linhas não nulas, `$qtd` permanecia zero e a divisão final causava erro de divisão por zero (ou resultado inválido, conforme a versão do PHP).  
**Impacto e severidade:** o painel podia falhar ao renderizar em uma base sem respostas registradas. **Média.**  
**Confiança:** 100.  
**Correção:** calculo a média com `AVG` no banco e retorno `0.0` quando não há amostras.

### F9 — Consultas N+1 para nomes de técnicos

**Onde:** `code/lib.php`, linhas originais 88–90 e 136–137.  
**Mecanismo:** a listagem e o CSV faziam uma consulta adicional a `usuarios` para cada chamado, além da consulta principal. O total crescia de uma consulta para `N + 1` conforme o número de chamados.  
**Impacto e severidade:** latência e carga no banco aumentavam linearmente com o tamanho da listagem/exportação. **Média.**  
**Confiança:** 100.  
**Correção:** as consultas de listagem e exportação agora usam `LEFT JOIN` para carregar o técnico junto com cada chamado.

### F10 — Fórmulas em campos textuais do CSV

**Onde:** `code/lib.php`, linhas originais 138–144.  
**Mecanismo:** `fputcsv` delimitava corretamente os campos, mas não impede que planilhas interpretem títulos iniciados por `=`, `+`, `-` ou `@` como fórmulas. Um título malicioso poderia executar fórmula ao ser aberto em uma planilha.  
**Impacto e severidade:** execução de fórmulas ou referências externas no programa de planilha usado pelo destinatário. **Média.**  
**Confiança:** 93.  
**Correção:** neutralizo esses prefixos nos campos textuais do CSV, preservando os valores que não podem ser interpretados como fórmula.

## Decisões

- Mantive `autenticar(mysqli, string, string): ?array`, `listarChamados`, `verChamado`, `exportarCsv` e as demais assinaturas do manifesto. As variantes com escopo são aditivas e usadas pelas rotas web; as funções sem contexto continuam adequadas às rotinas internas que precisam de visão integral.
- Não migrei MD5 para `password_hash` nesta alteração: a coluna de produção é `CHAR(32)`, e gravar um hash moderno sem migrar o esquema o truncaria. A migração precisa ampliar a coluna e tratar os usuários existentes de forma coordenada.
- Mantive a constante `EXPORT_DIR` definida para não remover configuração que outros scripts possam consumir, embora o export web não grave mais nesse diretório.
- Mantive os rótulos de status e prioridade existentes. O manifesto fixa os rótulos de status e não fornece regras suficientes para redefinir os textos de prioridade sem risco de incompatibilidade.
- A remoção das credenciais do código não rotaciona os valores já expostos. É necessário substituí-los no banco e no provedor SMTP e configurar as variáveis de ambiente correspondentes.
