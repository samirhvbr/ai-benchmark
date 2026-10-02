# Relatório técnico — LEB-100-A, versão 1.1

## Resumo

O painel PHP/mysqli mantinha a superfície de apresentação, mas permitia injeção SQL, XSS e acesso de clientes a chamados alheios. Login, exportação e indicador de resposta também apresentavam problemas de segurança, confiabilidade e custo de consultas. Foram identificados 11 achados: nove corrigidos no código, um mitigado com migração pendente (F5) e um reportado sem alterar os valores do CSV (F9).

Os cinco arquivos de `code/` foram alterados no lugar, sem trocar a stack, renomear arquivos ou acrescentar dependências. `TAREFA.md` e `manifest.md` foram preservados. O bloco LEB e os identificadores de achados coincidem com `achados.json`. A referência da matriz é `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Achados em ordem de prioridade

Todas as localizações abaixo se referem à **numeração original** dos arquivos recebidos. As linhas atuais podem diferir após as correções. Severidade descreve impacto/prioridade; confiança expressa a probabilidade de o mecanismo ser real, não a probabilidade de exploração em produção.

### F1 — Injeção SQL na busca por título

**Localização original:** `code/lib.php:80–85`. Relacionados: code/index.php:72–73.

**Categoria:** seguranca. **Severidade:** critica. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** listarChamados concatena busca dentro de LIKE. A entrada inexistente' OR 1=1 -- fecha a string, acrescenta uma condição verdadeira e comenta o restante da consulta. O parâmetro GET busca chega a essa função sem proteção SQL.

**Impacto:** Um usuário autenticado pode alterar a consulta de chamados e usar UNION ou condições SQL para extrair dados de outras tabelas acessíveis à conexão, incluindo hashes de usuarios. Não é necessário suporte a múltiplas instruções SQL.

**Decisão e alteração:** A busca é vinculada a um placeholder de uma consulta preparada mysqli. Os filtros de propriedade são compostos exclusivamente com inteiros validados. Foram mantidas a busca por substring, a semântica de % e _ e a ordenação existente.

**Evidência e limites:** No original, uma busca inexistente retornou zero chamados; a mesma busca acrescida de OR 1=1 retornou os cinco registros. Após a correção, as tentativas com OR e UNION retornaram lista vazia, sem erro SQL.

### F2 — Ausência de autorização por proprietário nos chamados

**Localização original:** `code/lib.php:78–101`. Relacionados: code/index.php:38–45,52–53,72–74; code/lib.php:109 e 132.

**Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** index.php lê uid e papel da sessão, mas não os usa para limitar os dados. listarChamados consulta todos os registros, verChamado filtra somente id, exportarCsv exporta todos e mediaResposta agrega dados de todos os clientes.

**Impacto:** Um cliente autenticado consegue listar, ler descrições e exportar chamados de outro cliente, contrariando diretamente a regra de visibilidade do manifesto. O indicador também inclui dados fora do seu escopo.

**Decisão e alteração:** Foi centralizada a determinação do escopo da sessão e aplicado usuario_id ao SQL da listagem, detalhe, CSV e média. Técnicos veem todos; clientes veem somente seus chamados; identidades incompletas ou papéis desconhecidos não obtêm dados. Chamado alheio recebe a mesma resposta de chamado inexistente. Scripts CLI internos sem contexto de sessão continuam tendo acesso global.

**Evidência e limites:** No original, a sessão de Ana listou [105,104,103,102,101], abriu o chamado 103 de Bruno e exportou ambos os clientes. Agora Ana recebe [105,102,101], Bruno [104,103], técnicos recebem todos; o CSV mantém ordem crescente dentro de cada escopo.

### F3 — XSS refletido na busca, em texto e atributo HTML

**Localização original:** `code/index.php:79–82`. Relacionados: code/index.php:60,64,91,94: padronização dos escapes existentes.

**Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** A variável busca é inserida diretamente no atributo value de input e no parágrafo de resultados. Uma aspa dupla encerra o atributo; tags como script passam a fazer parte do documento, também pelo parágrafo.

**Impacto:** Um link de busca preparado pode executar JavaScript na origem do painel quando uma pessoa autenticada o abre, permitindo ler dados exibidos e realizar requisições com sua sessão.

**Decisão e alteração:** Os dois pontos usam htmlspecialchars com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 explícito. O SQL continua recebendo o texto original. Títulos, descrições e nomes já escapados receberam os mesmos parâmetros explícitos.

**Evidência e limites:** O original devolveu literalmente o payload "><script>alert(1)</script>. Depois da correção, o parser HTML não encontra tags script/img injetadas e o atributo value preserva o texto integral da busca.

### F4 — Credenciais de banco e SMTP embutidas no código

**Localização original:** `code/config.php:11–15`. Relacionados: code/index.php:9–13.

**Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** DB_PASS tem um fallback literal apresentado como senha de produção e SMTP_API_KEY é uma constante literal. Acesso ao pacote ou ao histórico revela os dois valores; a ausência de configuração reutiliza automaticamente a senha embutida.

**Impacto:** Se os valores continuam válidos, quem obtém uma cópia do código pode tentar acesso ao banco ou abuso da central de e-mail. A validade das credenciais externas não foi verificada e os valores não são reproduzidos no relatório.

**Decisão e alteração:** Os dois segredos são lidos de variáveis de ambiente, sem fallback secreto. A entrada web retorna HTTP 503 se DB_PASS não foi fornecida. Falhas de conexão são tratadas com mensagem genérica, inclusive no modo estrito do mysqli. É necessária rotação externa dos valores anteriormente expostos.

**Evidência e limites:** Os literais foram removidos dos arquivos entregues. A conexão de teste utiliza credencial local independente; as respostas sem DB_PASS e com senha de banco inválida foram verificadas como 503, sem detalhes de conexão.

### F5 — Armazenamento de senhas com MD5 sem salt

**Localização original:** `code/lib.php:15–19`. Relacionados: code/schema.sql:7; code/seed.sql:2,5–8.

**Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Mitigado; migração pendente; `corrigido: false`.

**Mecanismo:** autenticar calcula md5 da senha e compara diretamente com usuarios.senha. schema.sql limita a coluna a CHAR(32) e seed.sql grava MD5, impedindo armazenar hashes modernos completos. O hash rápido e determinístico permite testar dicionários sem o custo de uma função própria para senhas.

**Impacto:** Uma cópia da tabela de usuários, inclusive obtida por F1, permite tentativas de descoberta de senhas offline; senhas iguais geram o mesmo hash.

**Decisão e alteração:** Mitigação entregue: autenticação com password_verify para hashes modernos, schema VARCHAR(255), seed com password_hash mantendo as quatro credenciais públicas e atualização oportunista de MD5 após login válido. O código verifica a capacidade da coluna, compara o hash antigo na atualização concorrente e preserva login com conexão somente de leitura. A migração ainda não encerra o achado: contas MD5 sem login e senhas legadas incompatíveis com bcrypt permanecem em MD5 até tratamento operacional.

**Evidência e limites:** Foram testados os quatro logins no banco CHAR(32), sem truncamento; após ampliar a coluna, o login de Ana substituiu MD5 por um hash verificável. O seed moderno autentica os quatro usuários. Senhas maiores que 72 bytes ou contendo NUL preservam o login legado, sem conversão silenciosa. Conexões somente de leitura mantêm login e registram adiamento da migração.

### F6 — Exportação usa arquivo fixo compartilhado e persistente

**Localização original:** `code/lib.php:125–150`. Relacionados: code/config.php:17–18.

**Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** Toda exportação abre EXPORT_DIR/chamados.csv com modo w, fecha e depois lê o mesmo caminho. Outra requisição pode truncar ou reescrever o arquivo entre essas etapas. O CSV permanece em disco e a abertura pode falhar quando o diretório não existe ou não é gravável, encerrando o download silenciosamente.

**Impacto:** Exportações concorrentes podem ficar vazias, truncadas ou misturadas. Ao aplicar visibilidade por cliente, reutilizar o mesmo arquivo também permitiria cruzar dados de sessões. O arquivo retém informações sensíveis; acesso estático adicional depende da configuração do servidor, que não foi fornecida.

**Decisão e alteração:** O CSV agora é escrito diretamente em php://output, com consulta sem buffer e fechamento do resultado/stream em finally. Não depende de EXPORT_DIR nem cria artefato compartilhado. Cabeçalho literal, nome do download, colunas, rótulos e ordem crescente foram preservados; o escape CSV é explícito e valores com aspas, barras e quebras de linha fazem round-trip correto.

**Evidência e limites:** O original criou e deixou chamados.csv no diretório de teste. A implementação nova exportou sem configurar EXPORT_DIR. Foram verificadas 12 requisições intercaladas de clientes diferentes, sem mistura de dados. O servidor PHP usado nesse teste serializa requisições; a eliminação da corrida se sustenta também pela ausência de caminho compartilhado no código.

### F7 — Identificador de sessão permanece igual depois do login

**Localização original:** `code/index.php:23–26`. Relacionados: code/index.php:15.

**Categoria:** seguranca. **Severidade:** media. **Confiança:** 98/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** Após autenticar, index.php grava uid e papel na sessão preexistente sem trocar seu identificador. Quem consegue induzir a vítima a usar uma sessão cujo ID conhece pode reutilizar esse ID depois da autenticação. O início da sessão também não impõe explicitamente modo estrito ou cookies somente.

**Impacto:** Sob a condição de conseguir fixar ou conhecer a sessão usada pela vítima, um adversário pode herdar o acesso autenticado.

**Decisão e alteração:** session_regenerate_id(true) é chamado antes de registrar a identidade. Foram habilitados modo estrito, transporte somente por cookie, HttpOnly, SameSite=Lax e Secure quando HTTPS é informado pelo servidor.

**Evidência e limites:** No fluxo HTTP original, o ID anterior ao login permaneceu igual após autenticar. Nos logins HTTP corrigidos de cliente e técnico, o ID mudou.

### F8 — Média de resposta divide por zero quando não há respostas

**Localização original:** `code/lib.php:109–116`. Relacionados: code/index.php:74,78.

**Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** mediaResposta inicializa qtd em zero, ignora chamados com minutos_resposta NULL e retorna soma/qtd incondicionalmente. Quando a tabela está vazia ou todos os minutos são NULL, o denominador permanece zero.

**Impacto:** Em PHP 8.4, a listagem termina com DivisionByZeroError. O caso também ocorre para um cliente sem respostas depois de corrigir o filtro de visibilidade.

**Decisão e alteração:** SUM e COUNT são calculados no banco, respeitando o escopo. A função devolve 0.0 quando não há respostas e divide soma por quantidade caso contrário. SUM/COUNT foi escolhido para manter a precisão do cálculo PHP anterior, sem o arredondamento de AVG do banco.

**Evidência e limites:** A transação de teste com todos os minutos NULL provocou DivisionByZeroError no original. Na correção foram verificados tabela vazia, todos NULL, Bruno sem resposta e a média 77/3 para os registros respondidos do seed.

### F9 — Texto do CSV pode ser interpretado como fórmula de planilha

**Localização original:** `code/lib.php:138–143`. Relacionados: code/lib.php:137: origem da coluna Tecnico.

**Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Estado:** Reportado; não alterado; `corrigido: false`.

**Mecanismo:** Título e nome do técnico são enviados a fputcsv sem tratamento para interpretação de fórmulas. O escape CSV organiza delimitadores e aspas, mas não muda uma célula como =1+1 para texto. Se um componente que grava esses campos permitir conteúdo controlado por usuário, esse conteúdo chegará ao download.

**Impacto:** Ao abrir o CSV em uma planilha que reconheça fórmulas, células podem executar expressões ou criar links enganosos; capacidades adicionais dependem do programa e suas configurações. Não há rota de criação de chamados neste pacote que comprove quem pode inserir o payload.

**Decisão e alteração:** Somente reportado. Prefixar apóstrofo ou tabulação alteraria o título/nome exportado para as integrações de faturamento e relatórios. O CSV contratado mantém os valores originais. Uma exportação específica para planilhas ou orientação de importação como texto exige definição adicional do contrato.

**Evidência e limites:** Um título =1+1 inserido apenas dentro de transação de teste foi emitido sem alteração no CSV. Não foi executado Excel/LibreOffice; a confiança de 95 reflete a dependência da origem dos campos e do software de destino.

### F10 — Consultas N+1 para nomes de técnicos e transferência da média

**Localização original:** `code/lib.php:87–90`. Relacionados: code/lib.php:64–71,109–114,132–137.

**Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** Para cada chamado com tecnico_id, listarChamados chama tecnicoNome, que executa outra SELECT. exportarCsv repete a mesma busca por registro. mediaResposta transfere cada tempo de resposta para somar em PHP. Técnicos repetidos continuam gerando consultas repetidas.

**Impacto:** A listagem e o CSV fazem 1+N consultas para N chamados atribuídos, aumentando latência e carga no banco. A média também transfere O(N) linhas sem necessidade. A consulta CSV original mantém o resultado inteiro em memória.

**Decisão e alteração:** Listagem e CSV obtêm tecnico_nome com LEFT JOIN e COALESCE, preservando chamados sem técnico e o rótulo -. Cada caminho usa uma única SELECT. A média usa SUM/COUNT e o CSV usa MYSQLI_USE_RESULT para evitar carregar todos os registros. A lista continua sendo um array, conforme a assinatura pública.

**Evidência e limites:** O seed contém cinco chamados e quatro atribuições, portanto o original faz cinco SELECTs por listagem/exportação. Contadores Com_select confirmaram uma SELECT por caminho na versão corrigida. Foram preservados tecnico_nome, tipos numéricos originalmente retornados como strings e os NULLs.

### F11 — Parâmetros HTTP em formato de array causam erro de tipo

**Localização original:** `code/index.php:72–73`. Relacionados: code/index.php:22,53.

**Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** Corrigido; `corrigido: true`.

**Mecanismo:** PHP interpreta busca[]=x e login[]=ana como arrays. index.php passa esses valores a funções que exigem string, produzindo TypeError antes de montar uma resposta normal. senha[] possui o mesmo problema. ver[] é convertido implicitamente a inteiro, selecionando um ID sem representar uma entrada escalar válida.

**Impacto:** Requisições malformadas terminam em erro 500 e podem expor detalhes se display_errors estiver ativo. O efeito observado limita-se à requisição; não foi classificado como indisponibilidade geral do serviço.

**Decisão e alteração:** A entrada web valida o formato escalar de login, senha, busca e ver. Arrays recebem HTTP 400 com mensagem genérica. Os nomes dos parâmetros e o processamento de valores escalares válidos foram mantidos.

**Evidência e limites:** O harness HTTP verificou HTTP 400 para busca[], ver[], login[] e senha[], sem erro fatal no corpo.

## Decisões

- **Assinaturas públicas, mysqli, caminhos de arquivos e consumidores CLI internos:** O manifesto exige compatibilidade. Todas as funções existentes foram mantidas, inclusive tecnicoNome; não foram adicionadas dependências. A autorização usa a sessão quando existe identidade; scripts CLI sem contexto de sessão são tratados como consumidores internos confiáveis. Qualquer script que processe requisição em nome de cliente deve estabelecer esse contexto antes de chamar as funções.

- **Rótulos de status e prioridade e comportamento para status não documentados:** Os textos são consumidos por relatórios. Mantive também o fallback Resolvido para outros status e as fronteiras de SLA existentes; não há contrato que permita substituí-los. A função de prioridade não foi reescrita por preferência de estilo.

- **Semântica LIKE de % e _, ordenação da listagem e ausência de paginação:** São comportamentos existentes que podem ser usados por consumidores. Busca continua por título com substring e coringas; a lista continua completa, em criado_em DESC. Paginação ou busca FULLTEXT mudariam comportamento e precisam de demanda e medição próprias.

- **Leitura transitória de MD5 em bancos existentes e contas fora dos limites bcrypt (F5):** Não é possível recuperar senhas reais a partir dos hashes. Remover a leitura MD5 imediatamente bloquearia clientes existentes. O código migra contas elegíveis após login, sem truncar senhas longas ou com NUL; ampliar a coluna e concluir a renovação das contas restantes continuam necessários. Por isso F5 está marcado como não encerrado.

- **Conteúdo de título e nome no CSV para fórmulas de planilha (F9):** Escapar fórmulas acrescentando caracteres modificaria os valores lidos por integrações. Preservei o CSV contratado e registrei o risco condicionado a conteúdo controlável e abertura em planilha. Uma variante voltada a planilhas não foi inventada nesta entrega.

- **Rotação das credenciais expostas e exclusão de CSVs históricos em produção:** O pacote não dá acesso à implantação, ao banco de produção ou à central SMTP. Removi os segredos e a persistência das novas exportações no código, mas não alego revogação externa nem exclusão de arquivos que não estão no workspace. Esses passos constam nas instruções de implantação.

- **Constante EXPORT_DIR:** A exportação nova não a utiliza, mas mantive o nome e valor para evitar surpresa a scripts internos que incluam config.php. Nenhuma criação de diretório ou gravação de arquivo continua necessária para o download.

## Compatibilidade e implantação

As sete assinaturas públicas foram mantidas, com os tipos de conexão, parâmetros, valores padrão e retornos. `autenticar` devolve somente `id`, `nome`, `papel` ou `null`. `listarChamados` mantém os campos anteriores, `tecnico_nome`, valores numéricos como strings e campos anuláveis como `null`. A tabela mantém `id="tabela-chamados"`, as cinco colunas na ordem declarada e links `index.php?ver=<id>`. As rotas continuam sendo `busca`, `ver`, `export=csv`, com CSV ordenado por ID e os mesmos rótulos.

A visibilidade agora cumpre o manifesto em todos os caminhos: cliente vê somente o que abriu e técnico vê todos. A média foi alinhada ao mesmo escopo, evitando agregação de chamados alheios. Para zero respostas retorna `0.0`. Exportações CLI internas sem sessão continuam abrangendo todos os chamados. O cabeçalho é escrito literalmente como `ID,Titulo,Status,Tecnico,Aberto em` seguido de LF; a serialização das linhas usa vírgula, aspas e escape explícito vazio, sem modificar os valores dos campos.

Na implantação existente:

1. Fornecer `DB_PASS` no ambiente do processo PHP e `SMTP_API_KEY` onde o serviço de e-mail for utilizado. `DB_HOST`, `DB_NAME`, `DB_USER` continuam disponíveis. A entrada web falha com 503 se `DB_PASS` não estiver definida. Uma variável explicitamente vazia é distinta de ausência, permitindo configurações locais que a utilizem.
2. Rotacionar as credenciais antes embutidas e retirá-las de históricos/distribuições acessíveis, conforme os procedimentos reais da implantação. A edição deste pacote não revoga credenciais externas.
3. Ampliar a coluna existente antes de concluir a migração de senhas:

   ```sql
   ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
   ```

   `schema.sql` é para instalações novas; não se deve recriar tabelas ou aplicar o seed sobre produção. O ALTER foi exercitado somente no banco isolado de testes. Sem ele, a autenticação legada continua funcionando, mas o hash não é atualizado. Atualizações oportunistas precisam de permissão UPDATE em `usuarios`; consumidores somente de leitura continuam autenticando, com adiamento registrado no log. Contas que não fizerem login e senhas fora dos limites bcrypt requerem renovação/estratégia adicional; F5 permanece aberto.
4. Remover o antigo arquivo persistido de exportação da implantação, se existir, e verificar se cópias estáticas foram expostas. Nenhum arquivo histórico de produção foi acessado nesta execução.
5. Configurar HTTPS no servidor para que o cookie seja marcado Secure. A detecção usa a informação HTTPS do servidor, sem confiar em cabeçalhos de proxy fornecidos diretamente pelo cliente; instalações com proxy devem ajustar essa informação em sua configuração.

O fluxo não recebeu novas telas, rotas ou critérios de negócio. Não foram alterados índices, enums nem relacionamentos; o aumento de `usuarios.senha` é a única mudança estrutural do banco.

## Validação realizada

A verificação usou PHP **8.4.26**, extensão `mysqli`/`mysqlnd` e um servidor MariaDB **11.8.6** isolado, criado em diretório temporário, com socket próprio e sem acesso TCP. Foram utilizados o schema e o seed originais para caracterização, e uma segunda base criada diretamente com os arquivos SQL alterados. Não houve conexão a banco ou SMTP de produção. O resultado não substitui homologação em MySQL 8, citado no schema, nem verificação da configuração do servidor de produção.

- Sintaxe: `php -l` para `config.php`, `lib.php` e `index.php`, sem erros.
- **73 verificações PHP**: assinaturas/comportamentos de retorno exercitados, quatro logins legados e modernos, senha incorreta/login inexistente, todos os status documentados, fronteiras de SLA, busca/coringas/aspas/injeção SQL, listagem/detalhe/CSV/média por papel, identidades inválidas, consumidores CLI, ausência de técnico, preservação de tipos e NULL, tabela vazia, minutos todos NULL, precisão `77/3`, uma SELECT por listagem e por CSV, migração de senha e round-trip CSV de vírgula/aspas/barra/LF.
- **57 verificações HTTP**: autenticação e regeneração de sessão, ordem/ID/links/colunas HTML, detalhes autorizados e negados, SQL/XSS, parâmetros em array com 400, CSV e seus cabeçalhos MIME/nome/colunas/status/ordem, exportação anônima e 12 requisições intercaladas de clientes distintos. O servidor PHP de desenvolvimento serializa requisições; esse teste valida isolamento das respostas, não simula a concorrência multiprocessos de produção.
- Verificações adicionais de autenticação somente de leitura, com mysqli estrito e sem exceções: sucesso com migração adiada, sem impedir o login.
- Verificações adicionais de configuração: ausência de `DB_PASS` e senha de banco inválida retornam 503 genérico.
- Revisão das assinaturas públicas e integridade dos artefatos: mesmos parâmetros/tipos/defaults; mesmos caminhos de `code/`; JSON válido, IDs únicos, localizações originais válidas, correspondência integral entre relatório e JSON e metadados LEB conferidos contra `TAREFA.md`.

O achado F9 foi caracterizado pela emissão de uma célula iniciada por `=`, sem execução em aplicativo de planilha. Não foi realizado teste de carga em produção, revogação externa de credenciais ou exclusão de exportações históricas.
