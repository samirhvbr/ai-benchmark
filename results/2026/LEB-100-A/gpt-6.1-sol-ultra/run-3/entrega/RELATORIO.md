# Relatório técnico — LEB-100-A

Instância 1.1 · matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel apresentava exposição entre clientes, injeção SQL e XSS, além de credenciais embutidas, autenticação baseada exclusivamente em MD5, falhas de sessão, exportação e cálculo de média. Foram identificados 12 achados: 11 recebem correções no código entregue, com implantação gradual para senhas; a interpretação de fórmulas no CSV permanece como risco documentado de compatibilidade. As correções evoluem os cinco arquivos existentes sem trocar mysqli, stack ou assinaturas públicas.

Todas as referências abaixo usam a **numeração original recebida**, conforme exigido pela tarefa; não são as linhas após a alteração. Cada identificador existe também em `achados.json`. A ordem expressa a prioridade de tratamento; a confiança mede se o problema existe, não se houve exploração em produção.

## Achados

### F1 — Clientes acessam chamados de outros clientes

**Local original:** `code/lib.php:78–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** listarChamados, verChamado e exportarCsv consultavam chamados sem predicado usuario_id. index.php obtinha uid/papel, mas não os usava para autorizar; mediaResposta também agregava dados globais. Ana conseguia listar e abrir 103/104, pertencentes a Bruno, e exportá-los.

**Impacto.** Exposição de títulos, descrições, técnicos e datas de outros clientes por listagem, detalhe e CSV; estatística fora do escopo autorizado.

**Ação e justificativa.** Escopo centralizado aplicado no SQL de listagem, detalhe, média e exportação. Cliente autenticado é filtrado por usuario_id; técnico mantém visão global. Web sem identidade válida retorna conjunto vazio. CLI sem sessão preserva relatórios internos globais.

### F2 — Busca permite injeção SQL

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** O termo busca era concatenado dentro de WHERE titulo LIKE '%...%'. A entrada ' OR 1=1 -- seguida de espaço encerra o literal e altera a condição; no código original o teste devolveu todos os chamados.

**Impacto.** Manipulação da consulta, leitura indevida conforme permissões do usuário de banco e falhas de consulta; também permitiria contornar filtros concatenados de forma ingênua.

**Ação e justificativa.** Consulta preparada mysqli com título e identificador do proprietário como parâmetros. Preservados substring, curingas %/_ e ordenação por criado_em DESC.

### F3 — Busca refletida executa HTML e JavaScript

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** busca entrava sem escape no atributo value entre aspas duplas e no parágrafo de resultados. Aspas fechavam o atributo e tags fornecidas pelo usuário viravam elementos do documento.

**Impacto.** Um link de busca preparado pode executar JavaScript no navegador de um usuário autenticado e acessar dados visíveis para ele.

**Ação e justificativa.** Escape HTML contextual com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 antes de inserir busca nos dois pontos. Mesmo escape explícito para os dados já protegidos da listagem e detalhe.

### F4 — Credenciais de banco e SMTP estão embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** DB_PASS tinha fallback literal identificado como senha de produção e SMTP_API_KEY tinha chave literal. Qualquer cópia do pacote distribuía ambos os segredos; getenv com ?: também substituía senhas vazias ou '0'.

**Impacto.** Quem obtém o código conhece as credenciais embutidas e pode tentar acesso aos serviços; o alcance depende de validade das chaves e acesso de rede, não verificados.

**Ação e justificativa.** Valores sensíveis passaram a vir do ambiente. DB_PASS diferencia variável ausente de valor vazio/'0'. Não há segredo literal de fallback. Implantação deve configurar o ambiente e rotacionar as credenciais previamente expostas.

### F5 — Senhas são armazenadas e verificadas exclusivamente com MD5

**Local original:** `code/lib.php:15–20`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** autenticar calculava md5(senha) e comparava no SQL. usuarios.senha era CHAR(32), conforme schema.sql:7, e seed.sql:5-8 gerava MD5. Não havia salt nem custo adaptativo.

**Impacto.** Uma cópia da tabela permite tentativas offline rápidas e reconhece senhas iguais entre contas, facilitando recuperação de senhas.

**Ação e justificativa.** Novo schema VARCHAR(255), seed com password_hash e autenticação com password_verify. Hash MD5 legado continua aceito para compatibilidade e é convertido após login válido quando a coluna comporta o novo hash; atualização condicional evita sobrescrever troca concorrente. Rehash oportunista de hashes modernos. A proteção completa dos registros existentes depende do ALTER e de login/redefinição de senha.

### F6 — Login não troca o identificador de sessão

**Local original:** `code/index.php:15–27`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 95/100. **Código corrigido:** sim.

**Mecanismo.** session_start aceitava a sessão anterior e o login gravava uid/papel no mesmo identificador, sem session_regenerate_id. Quem conseguisse fixar ou conhecer previamente uma sessão poderia reutilizá-la após a autenticação da vítima.

**Impacto.** Sequestro de sessão nas condições de fixação descritas; exploração depende de conseguir fornecer ou conhecer o identificador antes do login.

**Ação e justificativa.** Regeneração e exclusão do identificador anterior após login válido, modo estrito, somente cookies, HttpOnly, SameSite=Lax e Secure quando a conexão é HTTPS.

### F7 — Exportações concorrentes compartilham e truncam o mesmo CSV

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** Todas as requisições abriam EXPORT_DIR/chamados.csv em modo w e depois readfile nesse caminho. Outra requisição podia truncar ou reescrever o arquivo entre a escrita e a leitura. Sem diretório gravável, fopen falhava e a exportação retornava silenciosamente.

**Impacto.** Downloads vazios, incompletos ou misturados; ao restringir exportações por cliente, o mesmo mecanismo também poderia misturar dados de clientes distintos.

**Ação e justificativa.** Fluxo php://temp exclusivo por chamada, com rewind/fpassthru, fechamento em finally e Cache-Control private, no-store. Exportação não depende do diretório fixo nem deixa CSV compartilhado acessível em disco.

### F8 — Média de resposta divide por zero sem respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** O contador só aumentava para minutos_resposta não NULL, mas a função retornava soma/qtd sem tratar qtd=0. Banco vazio ou todos os minutos NULL provocavam DivisionByZeroError no PHP disponível; Bruno também não tem respostas no seed quando aplicado o escopo correto.

**Impacto.** A listagem inteira falha justamente quando não há respostas para calcular o indicador.

**Ação e justificativa.** AVG no banco, preservando a exclusão de NULL, resultado convertido para float e 0.0 quando AVG retorna NULL. Também evita transferir todas as amostras ao PHP.

### F9 — Listagem e CSV fazem uma consulta extra por técnico de cada chamado

**Local original:** `code/lib.php:87–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** Cada linha da listagem e do CSV chamava tecnicoNome, que executava SELECT nome em lib.php:69. O mesmo técnico era buscado repetidamente; lib.php:136-137 repetia o padrão na exportação.

**Impacto.** Até 1+N consultas e viagens ao banco por operação, aumentando latência e carga conforme o número de chamados cresce.

**Ação e justificativa.** LEFT JOIN usuarios nas consultas da listagem e exportação e COALESCE para o mesmo fallback '-'. Cada operação usa uma consulta, mantém todos os campos do chamado e a chave tecnico_nome. Tipos numéricos originalmente retornados como strings são preservados.

### F10 — Campos CSV podem ser interpretados como fórmulas por planilhas

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Código corrigido:** não.

**Mecanismo.** Título e nome de técnico são enviados como texto bruto para fputcsv. Se houver valor iniciado por =, +, - ou @ e o consumidor abrir o CSV em uma planilha que reconhece esse prefixo como fórmula, o conteúdo pode ser avaliado. Aspas de CSV apenas delimitam o campo e não garantem texto literal.

**Impacto.** Fórmulas e, conforme o aplicativo/configuração, ações ou requisições externas ao abrir dados manipulados. O pacote não oferece rota de escrita desses campos; o risco pressupõe entrada por outro sistema, banco ou integração.

**Ação e justificativa.** Não alterado: prefixar apóstrofo/tabulação modifica os títulos e nomes entregues às integrações existentes. Recomenda-se pactuar exportação específica para planilhas ou importação desses campos como texto, preservando o CSV atual até definir esse contrato.

### F11 — Parâmetros em formato de array provocam erro de tipo

**Local original:** `code/index.php:72–73`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** PHP aceita busca[]=x como array em GET, mas index.php encaminhava o valor diretamente ao argumento string de listarChamados. O mesmo ocorre com login/senha arrays em index.php:22 ao chamar autenticar.

**Impacto.** Uma requisição malformada termina em TypeError/HTTP 500 e pode expor detalhes se display_errors estiver ligado; não foi demonstrada indisponibilidade de outras requisições.

**Ação e justificativa.** Busca exige string e retorna HTTP 400 para array. Login só chama autenticar com strings. Detalhe valida identificador inteiro positivo antes de chamar verChamado.

### F12 — Cabeçalho CSV diverge do texto literal declarado

**Local original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Código corrigido:** sim.

**Mecanismo.** fputcsv aplica aspas ao campo com espaço e gera ID,Titulo,Status,Tecnico,"Aberto em", enquanto o manifesto exige literalmente ID,Titulo,Status,Tecnico,Aberto em. Os campos são equivalentes para um parser CSV, mas os bytes não são o cabeçalho exato prometido.

**Impacto.** Consumidores que comparam a primeira linha literalmente rejeitam o arquivo, apesar de os cinco nomes serem semanticamente iguais.

**Ação e justificativa.** Cabeçalho estático escrito literalmente; linhas de dados continuam com fputcsv. Nome do download, delimitador, ordem crescente de IDs e rótulos de status preservados.

## Decisões

- **Assinaturas públicas, mysqli, rotas, nomes/caminhos e estrutura HTML declarada.** São contrato de consumidores externos. Nenhum arquivo foi renomeado; não houve troca de stack ou nova dependência da aplicação.
- **Textos e decisões de formatarStatus e rotuloPrioridade, inclusive valores fora do intervalo esperado.** Não há especificação que demonstre erro nas prioridades. Status 1/2/3 continua exatamente Aberto/Em atendimento/Resolvido; comportamento dos demais valores foi preservado para evitar regressão.
- **Busca LIKE com curingas %/_ e listagem criado_em DESC, sem paginação nova.** Alterar interpretação, ordem ou limitar resultados afetaria consumidores. A segurança vem de parâmetros SQL, e não de mudar a busca.
- **Conteúdo textual dos campos no CSV (risco F10).** Prefixos de proteção de planilhas alterariam valores da integração de faturamento; precisa de formato/opção pactuado. O risco residual está explicitamente reportado.
- **Leitura global nas rotinas CLI sem contexto de sessão.** O manifesto identifica exportação noturna e relatórios internos que chamam as funções diretamente. No contexto web a ausência/invalidade da identidade falha fechada; cliente com sessão é restrito também na CLI.
- **Aceitação transitória de MD5 e ausência de DDL automático durante login.** Bancos existentes CHAR(32) e senhas legadas devem continuar autenticando até uma migração planejada. O código confere capacidade e não trunca hashes; o operador deve aplicar ALTER. Contas sem login continuam com MD5 até redefinição/migração.
- **Constante EXPORT_DIR e função tecnicoNome.** Embora a exportação/listagem não dependam mais deles, mantê-los evita retirar pontos de integração internos sem benefício.
- **Rotação efetiva dos segredos e alteração de um banco de produção.** Nenhum serviço de produção foi acessado. Remoção de literais não revoga credenciais já expostas; a implantação precisa fornecer novas credenciais por ambiente e executar a migração do banco.
- **Recursos de produto novos, reestruturação geral, bibliotecas e índices sem evidência.** A tarefa pede evolução do legado; não há necessidade demonstrada para mudanças adicionais com custo ou risco de compatibilidade.

## Implantação e limites das correções

Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` e, quando utilizado, `SMTP_API_KEY` no ambiente do serviço PHP. As credenciais que constavam do pacote devem ser consideradas expostas e rotacionadas no serviço correspondente. Sem `DB_PASS`, o programa não reutiliza a antiga senha embutida; uma falha de conexão retorna HTTP 503 com mensagem genérica.

Para instalações existentes, aplique no banco correto, em janela compatível com a operação:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

`schema.sql` define instalações novas; executar sua edição não altera um banco já existente. Não execute `seed.sql` novamente sobre dados de produção. A autenticação tolera a coluna antiga sem tentar gravar um hash que não cabe. Após o ALTER, logins válidos migram MD5; contas que não voltarem a autenticar precisam de redefinição de senha para eliminar todos os hashes legados. Falha na atualização oportunista não invalida o login já verificado. Senhas legadas incompatíveis com bcrypt (mais de 72 bytes ou byte NUL) não são convertidas automaticamente quando esse é o algoritmo padrão, para não alterar sua semântica; exigem redefinição. A alteração de senha usa comparação com o hash anterior para não sobrescrever uma atualização concorrente.

A sessão exige cookies. `Secure` é ativado quando o servidor PHP identifica HTTPS; em instalações com terminação TLS em proxy, esse indicador deve ser configurado pelo servidor confiável. Não se confia automaticamente em cabeçalhos encaminhados pelo cliente. A média visível ao cliente usa somente seus chamados; ausência de amostras representa `0.0` na API e `0 min` no painel.

O corpo do CSV usa escape vazio em `fputcsv`: aspas internas são duplicadas, preservando barras invertidas como dados. Isso evita o escape proprietário por barra e a depreciação do argumento omitido no PHP 8.4. Consumidores que usam `fgetcsv` com escape proprietário devem importar com `escape=''` para interpretar corretamente combinações de barra e aspas; os testes de roundtrip usaram parsers CSV padrão.

Não se removeu suporte a relatórios CLI sem sessão. Esses processos são integrações internas confiáveis; a aplicação HTTP exige login. Funções continuam recebendo apenas mysqli e os parâmetros manifestados, com escopo de sessão centralizado. Listagem e detalhe conservam tipos dos campos numéricos retornados pelo protocolo textual original, além das chaves e valores.

## Validação

Testes executados em PHP 8.4.26 (mysqli/mysqlnd) e MariaDB 11.8.6, com servidor e bancos isolados de teste. A referência do schema declara MySQL 8; não havia servidor MySQL 8 disponível. Nenhum banco de produção foi alterado. As consultas usadas são compatíveis com os recursos comuns das duas implementações, mas essa equivalência não substitui uma execução adicional no MySQL 8 da implantação.

- **Sintaxe:** `php -l code/lib.php`, `php -l code/index.php` e `php -l code/config.php`, todos aprovados.
- **246 verificações de funções:** 82 em cada um de três bancos: schema/seed originais CHAR(32), schema/seed atualizados e banco legado após ALTER para VARCHAR(255). Verificadas assinaturas por reflexão, chaves/tipos/valores de retorno, status, limites da prioridade, ordenação, curingas LIKE, tentativa de SQL injection, listagem/detalhe/CSV/média para Ana, Bruno, Carla e Diego, acesso global CLI sem sessão, identidade inválida fechada, técnico NULL, ausência de respostas, todas NULL e média zero. Confirmados login legado sem truncamento, migração e login moderno.
- **66 verificações HTTP:** quatro logins, rotação de sessão e rejeição de identificador fixado, cookies HttpOnly/SameSite=Lax, rotas e estrutura da tabela, visibilidade dos clientes/técnicos, XSS refletido e dados armazenados, busca e identificadores malformados, SQL injection, cabeçalho CSV literal e headers de download. CSV preservou valores com vírgulas, aspas, barra invertida e quebra de linha; o risco de fórmulas permaneceu explícito, sem prefixar/apagar dados.
- **4 verificações complementares:** confirmação de TypeError/HTTP 500 do original para arrays no login e na busca; conexão com senha errada no corrigido retorna HTTP 503 genérico; exportação concorrente conserva o isolamento. Foram executadas 20 exportações de Ana/Bruno em subprocessos com 8 trabalhadores, sem mistura de IDs.
- **Carga representativa:** com 320 chamados, listagem e exportação executam exatamente uma consulta cada, em vez de consultas por técnico de cada linha. A média mantém a precisão de `77/3`, evitando o arredondamento de AVG sobre inteiro para DECIMAL de escala limitada.
- **Seed final:** após atribuir salts distintos aos quatro usuários, verificados os quatro hashes diferentes e as quatro senhas públicas preservadas por `password_verify`.
- **Artefatos:** JSON parseado, 12 IDs únicos e correspondência integral com os 12 blocos do relatório; categorias/severidades/confianças e intervalos de linhas originais conferidos. Mesmo conjunto de cinco nomes em `code/`; `manifest.md` e `TAREFA.md` permanecem byte a byte iguais aos recebidos.

Total: **316 verificações funcionais aprovadas**, além do lint, dos quatro hashes do seed final e das verificações dos artefatos. Dois desses checks confirmam falhas esperadas no código original, para demonstrar a reprodução; os demais avaliam a entrega corrigida. XSS foi verificado pela saída HTML, sem executar JavaScript em navegador. Não se simulou TLS no teste HTTP local, rotação real de credenciais ou uso do CSV em aplicativos de planilha. A atualização condicional de senha foi revisada no código, sem reproduzir uma corrida de troca de senha. Esses limites e a migração necessária estão documentados acima.
