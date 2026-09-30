# Relatório técnico — LEB-100-A

Instância **LEB-100-A**, versão **1.1**. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel possui falhas concretas de segurança e de disponibilidade: a busca permite extrair hashes via SQL, clientes acessam chamados alheios, o HTML aceita código da busca e o login mantém o identificador anterior da sessão. A configuração distribui segredos e as senhas legadas usam MD5. Há ainda concorrência insegura no CSV, erro de média sem respostas e consultas repetidas.

Registrei **14 achados**, em ordem de prioridade. **13 receberam correção no código**; **F9 foi deliberadamente reportado sem alterar os dados exportados**. A remoção de segredos e a migração gradual de senhas exigem as etapas de implantação descritas ao final; não significam que credenciais históricas foram revogadas ou que todos os hashes de produção já foram convertidos.

Os cinco arquivos de code/ foram alterados no lugar. Permanecem PHP/mysqli, assinaturas públicas, credenciais de teste, rotas, tabela HTML e rótulos. A visibilidade passou a cumprir a regra declarada do manifesto. TAREFA.md e manifest.md não foram editados. Todas as localizações abaixo e no JSON usam a **numeração original recebida**, antes das alterações.

## Achados

### F1 — Busca permite injeção SQL e leitura de hashes de usuários

**Localização original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** critica. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** listarChamados concatena busca dentro de LIKE sem parametrização. O termo inexistente' OR 1=1 -- altera o predicado; uma UNION com as nove colunas de chamados consegue colocar login e senha de usuarios nas colunas titulo e descricao do retorno.

**Impacto:** Um cliente autenticado pode alterar consultas e extrair informações fora da busca, inclusive hashes de senhas, conforme os privilégios da conexão. Não é necessário conhecer um título existente.

**Decisão e correção:** A busca usa mysqli::prepare e bind_param, com o padrão LIKE enviado como dado. O filtro de visibilidade é aplicado independentemente da busca. As assinaturas e os curingas legados de LIKE foram preservados.

**Evidência e limites:** Reprodução no original: o primeiro payload retorna os cinco chamados; UNION ALL SELECT id,id,NULL,login,senha,1,1,NULL,NOW() FROM usuarios retorna quatro registros com hashes. Ambos retornam lista vazia no código corrigido. Não atribuo à query simples suporte a comandos empilhados nem destruição de tabelas não demonstrada.

### F2 — Clientes conseguem ler chamados de outros donos

**Localização original:** `code/lib.php:80–116`. **Categoria:** seguranca. **Severidade:** alta. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** index.php lê uid e papel, mas não os usa nas consultas. listarChamados consulta todos os chamados, verChamado filtra apenas id, exportarCsv consulta todos e mediaResposta agrega todos. A autenticação existente não implementa a autorização de proprietário exigida pelo manifesto.

**Impacto:** Ana consegue listar e abrir os chamados 103 e 104 de Bruno e exportá-los. A média também inclui respostas de outros donos. A confidencialidade entre clientes é quebrada.

**Decisão e correção:** Foi centralizada uma condição de visibilidade nas quatro funções leitoras: cliente recebe somente c.usuario_id igual ao uid válido da sessão; técnico recebe todos. Sessões incompletas e chamadas web sem identidade são negadas. Relatórios CLI sem sessão mantêm o acesso global interno.

**Evidência e limites:** Outros locais originais: code/index.php:38–45, 52–65 e 72–74; code/lib.php:132. Testes confirmam Ana com 101/102/105, Bruno com 103/104, ambos os técnicos com todos, CSV com o mesmo escopo e detalhe alheio retornando null. Mantive a mesma mensagem para chamado alheio e inexistente.

### F3 — Termo de busca é inserido sem escape em atributo e texto HTML

**Localização original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** busca é concatenada diretamente no atributo value e no parágrafo de resultados. Um termo com aspas duplas encerra o atributo; um termo como <img src=x onerror=alert(1)> cria uma tag no parágrafo. Ambos chegam pela URL da página autenticada.

**Impacto:** Um link preparado pode executar JavaScript no navegador de um usuário autenticado, permitindo leitura dos dados da página e ações com sua sessão.

**Decisão e correção:** O termo é escapado para ambos os contextos com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 explícito. O mesmo encoding explícito foi aplicado a título, descrição e nome do técnico, que já tinham escape básico.

**Evidência e limites:** O HTML original reproduziu a tag nos dois pontos de saída. A versão corrigida apresenta entidades HTML e mantém o texto da busca, o formulário, a tabela e seus links.

### F4 — Credenciais de produção estão embutidas na configuração

**Localização original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** DB_PASS cai em uma senha identificada no comentário como de produção quando a variável está ausente ou vazia; SMTP_API_KEY é uma chave literal no arquivo. Distribuir o código distribui esses segredos e o fallback pode selecionar a credencial de produção involuntariamente.

**Impacto:** Quem obtém uma cópia do pacote pode tentar usar as credenciais no banco ou no serviço de e-mail, caso ainda sejam válidas. Não foi verificada sua validade externa nem constatado uso indevido.

**Decisão e correção:** Os dois segredos literais foram removidos. DB_PASS e SMTP_API_KEY são obtidos do ambiente; senha de banco explicitamente vazia não ativa um segredo alternativo. Os nomes das constantes permanecem disponíveis.

**Evidência e limites:** A correção do fonte não revoga valores já distribuídos. A implantação precisa provisionar as variáveis e rotacionar/revogar as antigas credenciais nos serviços respectivos. Não contatei serviços externos nem reproduzo os valores neste relatório.

### F5 — Senhas usam MD5 sem salt nem custo de derivação

**Localização original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** autenticar calcula md5(senha) e compara o digest diretamente na consulta. schema.sql limita senha a CHAR(32) e seed.sql grava MD5. Senhas iguais têm o mesmo digest e o cálculo barato favorece tentativas offline após vazamento da tabela.

**Impacto:** Uma cópia dos hashes facilita recuperar senhas por dicionário e pode comprometer contas de clientes e técnicos. F1 oferece um caminho concreto de leitura desses hashes.

**Decisão e correção:** O esquema passou a VARCHAR(255), o seed usa bcrypt e autenticar verifica password_hash com password_verify. MD5 legado é aceito em comparação constante e migrado após login válido, apenas quando a coluna suporta o hash. Atualização condicionada ao hash anterior evita sobrescrever uma troca concorrente. A falha da migração oportunista não invalida uma autenticação já verificada.

**Evidência e limites:** Correção com transição explícita: bancos existentes precisam do ALTER TABLE indicado abaixo e de permissão UPDATE para concluir a migração; contas ainda não autenticadas continuam legadas. Em CHAR(32), a autenticação permanece válida sem escrever hashes truncados. Senhas legadas com mais de 72 bytes ou NUL não são migradas para bcrypt, para preservar a identidade inteira; exigem futura redefinição ou estratégia compatível. Hashes bcrypt não aceitam entradas acima de 72 bytes. Foram testados esquema antigo, novo, migração, login posterior, senha longa, NUL e conexão somente-leitura.

### F6 — Login mantém o identificador de sessão anterior à autenticação

**Localização original:** `code/index.php:15–25`. **Categoria:** seguranca. **Severidade:** alta. **Confiança de que é real:** 99/100. **Estado:** Correção implementada.

**Mecanismo:** session_start abre a sessão e o login apenas atribui uid e papel, sem regenerar seu identificador. Se um adversário consegue fazer a vítima usar uma sessão anônima cujo identificador ele conhece, esse mesmo identificador passa a representar a vítima autenticada.

**Impacto:** Nas condições de fixação do cookie, o adversário pode reutilizar a sessão autenticada. A proteção depende também da forma de distribuição do cookie e da configuração do servidor.

**Decisão e correção:** O login executa session_regenerate_id(true) antes de gravar a identidade. A sessão usa modo estrito, somente cookies, HttpOnly e SameSite=Lax; Secure é ativado quando HTTPS é informado pelo servidor. Falhas de criação ou renovação da sessão são tratadas.

**Evidência e limites:** Na versão original, o SID observado antes e depois do login é igual. Na versão entregue muda, e a cópia do cookie anterior retorna à tela de entrada. HTTPS atrás de proxy deve ser comunicado por configuração confiável do servidor; não confio em cabeçalhos encaminhados arbitrários.

### F7 — Exportações compartilham um arquivo truncado sem isolamento

**Localização original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** alta. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** Cada exportação abre EXPORT_DIR/chamados.csv com w e depois faz readfile do mesmo caminho. Outra requisição pode truncar ou alterar esse arquivo entre escrita e leitura. Se o diretório não existir ou não for gravável, a função retorna silenciosamente; se a consulta falhar, retorna sem fechar o handle aberto.

**Impacto:** Downloads podem ficar vazios, parciais ou conter dados de outra exportação. Depois de restringir o CSV por cliente, a mistura entre requisições também causaria vazamento entre donos. O arquivo ainda retém dados no disco sem necessidade contratual.

**Decisão e correção:** A consulta é executada antes dos cabeçalhos e o CSV é escrito diretamente em php://output, sem arquivo compartilhado nem dependência de EXPORT_DIR. Resultado e stream são liberados em finally; falhas de consulta, abertura e escrita são sinalizadas. Cabeçalhos de dados autenticados usam no-store.

**Evidência e limites:** Foram realizadas oito rodadas de downloads simultâneos em dois processos PHP independentes: Ana sempre recebeu 101/102/105 e Bruno 103/104, com CSV válido. A visibilidade é tratada em F2. Arquivos antigos de exportação em um servidor de produção não foram acessados: sua remoção é uma tarefa de implantação fora desta pasta.

### F8 — Média divide por zero quando não há respostas

**Localização original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** A consulta ignora minutos_resposta NULL, o laço mantém qtd em zero quando não há respostas e return soma / qtd divide por zero. Isso ocorre com banco vazio, todos os chamados aguardando resposta ou, após F2, um cliente como Bruno no seed.

**Impacto:** O indicador interrompe a renderização da listagem com DivisionByZeroError no PHP utilizado, justamente em uma situação válida do negócio.

**Decisão e correção:** O banco devolve SUM e COUNT dos minutos não nulos em uma única linha e a função retorna 0.0 para contagem zero. Com respostas, a divisão é feita pelo PHP para preservar a precisão anterior; não se usa a precisão decimal limitada do AVG de inteiros do banco.

**Evidência e limites:** Reproduzido DivisionByZeroError no original. Verificados banco vazio, todos os tempos NULL, cliente sem respostas e fração 1/3. A agregação também evita transferir todos os tempos e percorrê-los no PHP.

### F9 — Valores do CSV podem ser interpretados como fórmulas por planilhas

**Localização original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança de que é real:** 90/100. **Estado:** Reportado; não corrigido.

**Mecanismo:** titulo e o nome do técnico são passados literalmente a fputcsv. Se dados cadastrados por outros fluxos contiverem uma expressão iniciada por =, +, - ou @, aplicativos de planilha podem interpretar a célula como fórmula. Aspas de CSV protegem a estrutura do arquivo, mas não determinam o tipo da célula na planilha.

**Impacto:** Um usuário que abrir o arquivo em uma planilha que avalie esses valores pode executar fórmulas ou consultar referências externas, dependendo do aplicativo e de suas permissões. A aplicação entregue não contém a rota de criação dos títulos; não foi demonstrada exploração remota nem execução de comandos.

**Decisão e correção:** Não alterei os valores das células: prefixar apóstrofos ou outros caracteres mudaria os títulos e nomes consumidos pelas integrações. Recomendo importar o CSV como texto nos consumidores de planilha ou acordar uma exportação específica para planilhas, preservando a rota existente.

**Evidência e limites:** O teste confirmou que um título =1+1 permanece literalmente no CSV corrigido. A avaliação por uma planilha depende do consumidor, justificando confiança menor que nos ataques web reproduzidos.

### F10 — Parâmetros HTTP em formato de array causam erros de tipo

**Localização original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** media. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** PHP permite receber login[], senha[] e busca[] como arrays. index.php os passa a funções com argumentos string, provocando TypeError. ver[] é convertido de array não vazio para inteiro 1, em vez de validar um identificador.

**Impacto:** Uma requisição malformada interrompe o processamento ou solicita um ID que não corresponde à entrada, podendo expor detalhes de erro quando exibidos pelo ambiente. Isso não implica indisponibilidade persistente do serviço.

**Decisão e correção:** Login e senha exigem strings antes da autenticação; busca exige string e retorna 400 para array; ver exige um inteiro positivo válido e mantém a mensagem de chamado não encontrado para entrada inválida.

**Evidência e limites:** Outros locais originais: code/index.php:53 e 72–73. O original reproduziu TypeError para busca[]=x. Foram verificados os três formatos malformados via HTTP, além das entradas normais do manifesto.

### F11 — Falhas mysqli podem escapar do tratamento e revelar detalhes

**Localização original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** O teste connect_errno ocorre depois de new mysqli; em modo estrito, uma falha já lança mysqli_sql_exception antes desse if. Também não há tratamento externo para exceções de consultas e autenticar usa o retorno de prepare sem testar false. O resultado depende do modo mysqli e de display_errors.

**Impacto:** Uma falha de conexão ou consulta aborta a requisição; ambientes com exibição de erros podem revelar caminhos e mensagens internas. A mensagem genérica pretendida pelo if não cobre esse caminho.

**Decisão e correção:** O ponto de entrada configura mysqli estrito e captura Throwable, registra somente classe/código e responde erro genérico, com HTTP 500 quando os cabeçalhos ainda não foram enviados. display_errors é desativado para o fluxo web. A preparação interna testa false e resultados das consultas diretas também são verificados.

**Evidência e limites:** Outros locais originais: code/lib.php:16–19, 85–88, 100–101 e 109–112. Teste HTTP com banco indisponível confirmou 500 sem stack trace ou credenciais. Depois de iniciada uma resposta em streaming, uma falha de transporte pode produzir download parcial: não é possível garantir atomicidade de entrega pela rede.

### F12 — Escape padrão de CSV altera títulos com barra antes de aspas

**Localização original:** `code/lib.php:138–144`. **Categoria:** bug. **Severidade:** media. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** fputcsv é chamado sem definir escape, usando a barra invertida padrão. Em uma sequência de barra seguida de aspas, esse escape proprietário deixa a aspa sem a duplicação esperada por leitores CSV convencionais. O campo reimportado pode ficar diferente do título armazenado.

**Impacto:** Integrações que leem CSV com escape por duplicação de aspas recebem dados corrompidos. Foi reproduzida alteração em títulos contendo aspas precedidas por barra invertida.

**Decisão e correção:** A escrita das linhas usa fputcsv com separador vírgula, enclosure de aspas duplas e escape vazio explícito, permitindo a duplicação padrão de aspas. UTF-8, vírgulas, barras, quebras de linha e os cinco campos são preservados.

**Evidência e limites:** Um leitor Python csv identificou alteração em dois exemplos gerados com o comportamento antigo. A opção explícita também remove a dependência do escape padrão depreciado no PHP 8.4. Nenhuma dependência Python foi adicionada ao aplicativo; Python foi usado apenas na verificação.

### F13 — Listagem e CSV fazem uma consulta adicional por técnico atribuído

**Localização original:** `code/lib.php:88–89`. **Categoria:** performance. **Severidade:** media. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** listarChamados chama tecnicoNome dentro do laço; exportarCsv repete o padrão. tecnicoNome consulta usuarios para cada tecnico_id não nulo, mesmo quando vários chamados têm o mesmo técnico. Assim, uma leitura exige 1 mais o número de chamados atribuídos em SELECTs.

**Impacto:** O custo e a latência crescem com a quantidade de chamados, ocupando conexão e banco com consultas repetidas. Nos cinco chamados do seed, a listagem executa cinco SELECTs.

**Decisão e correção:** Listagem e exportação usam um LEFT JOIN com usuarios e COALESCE para manter tecnico_nome e o marcador -. Cada operação passou a executar um único SELECT. A função tecnicoNome foi preservada, inclusive sua assinatura.

**Evidência e limites:** Outros locais originais: code/lib.php:69 e 136–137. O contador Com_select confirmou cinco SELECTs no original e um por listagem/exportação na versão entregue. LEFT JOIN preserva os chamados sem técnico e a ordenação de cada operação.

### F14 — Cabeçalho CSV não coincide literalmente com o manifesto

**Localização original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** baixa. **Confiança de que é real:** 100/100. **Estado:** Correção implementada.

**Mecanismo:** fputcsv coloca aspas em Aberto em por conter espaço. O cabeçalho emitido é ID,Titulo,Status,Tecnico,"Aberto em", enquanto o manifesto exige literalmente ID,Titulo,Status,Tecnico,Aberto em.

**Impacto:** Um consumidor que compara a primeira linha por texto encontra uma divergência de contrato, mesmo que um parser CSV produza os mesmos nomes de colunas.

**Decisão e correção:** O cabeçalho fixo é escrito literalmente com a linha exigida e LF. As linhas de dados continuam sendo serializadas por fputcsv e os status continuam usando formatarStatus.

**Evidência e limites:** Foi caracterizado o cabeçalho antigo e verificada igualdade literal no novo, na função e nos downloads HTTP dos quatro usuários.

## Decisões

- **Stack PHP/mysqli, nomes e assinaturas públicas, caminhos dos cinco arquivos e parâmetros busca/ver/export.** Constituem a superfície pública. Foram usados recursos nativos existentes e correções locais, sem novo framework, camada de dados ou dependência.
- **Rótulos de status e todos os resultados de rotuloPrioridade, inclusive prioridade 4 dentro do SLA e o fallback de status desconhecido.** Os rótulos são consumidos externamente; não há regra adicional que autorize reinterpretar esses casos. A caracterização original e alterada é idêntica.
- **Semântica de LIKE com os curingas % e _, ordenação da lista por criado_em DESC e retorno integral em array.** Escape de curingas, paginação ou troca por busca textual modificariam os resultados ou a interface. Parametrizar resolve F1 sem essa alteração.
- **Relatórios CLI confiáveis sem sessão continuam lendo todos os chamados.** As assinaturas não recebem identidade e são usadas por rotinas internas. A exceção é exclusiva de CLI sem sessão; sessões presentes seguem o papel e chamadas web sem identidade não recebem dados.
- **Conteúdo literal de título e técnico no CSV, sem prefixo de apóstrofo para neutralizar fórmulas.** F9 permanece reportado: mudar dados afetaria consumidores de exportação/faturamento. A mitigação deve ocorrer na importação como texto ou em um formato específico acordado.
- **Autenticação de contas ainda em MD5 no banco existente, inclusive senhas longas ou com NUL.** F5 tem migração gradual. Hashes fortes exigem ampliar a coluna e permitir a atualização; bcrypt não representa integralmente essas senhas especiais. Não bloqueei contas existentes nem fiz redefinição compulsória.
- **Banco de produção e rotação de credenciais nos serviços externos.** A entrega é o código dentro desta pasta. F4 remove os segredos do fonte; revogação, provisionamento de variáveis e migração do banco real são etapas explícitas de implantação, não foram simuladas como concluídas.
- **Constante EXPORT_DIR e eventuais CSVs antigos fora da pasta.** Preservei a constante para consumidores não declarados, embora o fluxo entregue não a utilize. Não acessei nem apaguei arquivos externos; a implantação deve remover cópias antigas de exportações.
- **Configuração externa de TLS, proxy e políticas de rede.** A detecção de HTTPS usa informação do servidor para Secure, sem confiar em cabeçalhos de cliente. Forçar uma topologia de proxy ou redirecionamento mudaria a implantação sem contexto suficiente.
- **Reorganização arquitetural do index.php e regras adicionais de validade de status, prioridade ou SLA.** Não são necessárias para corrigir os mecanismos demonstrados. Uma reescrita ou regras novas aumentariam o risco de incompatibilidade sem requisito de negócio.

## Validação

A validação usou PHP 8.4.26 com mysqli/mysqlnd e um servidor MariaDB 11.8.6 isolado: datadir, socket, arquivos de sessão e logs temporários ficaram nesta pasta. Não foi instalada dependência. O esquema declarado é MySQL 8; os comandos empregados são compatíveis com essa stack, mas **não afirmo ter executado os testes em MySQL 8**.

Passaram **54 verificações da biblioteca e 46 verificações HTTP**, totalizando **100**. As verificações cobrem as quatro credenciais do manifesto; retornos de autenticação; busca normal e com curingas; ataques OR e UNION; visibilidade de clientes e técnicos na lista, detalhe, média e CSV; sessões inválidas; ausência de respostas; precisão 1/3; migração MD5 em CHAR(32)/VARCHAR(255); senhas longas/com NUL; permissão somente-leitura; cabeçalho, dados especiais e ordenação CSV; estrutura da tabela e links; cookies, regeneração e rejeição de SID antigo; parâmetros em arrays; falha de banco controlada. O log de migração pendente com código 1142 no teste somente-leitura é esperado: o login válido continua funcionando.

Além disso, comparei por reflexão as sete assinaturas públicas, sete entradas de status e 63 combinações de prioridade/tempo entre original e alterado: nenhuma diferença. Medi SELECTs e constatei a redução de cinco para um nas operações de leitura do seed. Os downloads simultâneos foram exercitados em oito rodadas com dois processos PHP independentes. A reprodução no original confirmou leitura alheia, SQL injetável com quatro hashes extraíveis, XSS, SID mantido e DivisionByZeroError.

A serialização antiga de campos com barra seguida de aspas também foi comparada com um leitor CSV convencional. Os arquivos PHP entregues passaram por php -l. O JSON foi validado quanto a metadados, enumerações, faixas de confiança, linhas originais e correspondência exata dos IDs com os blocos do relatório.

Os servidores e dados de validação são temporários e são removidos ao concluir a entrega. O código da aplicação mantém apenas os cinco caminhos originais.

## Implantação e limites restantes

1. Provisionar DB_HOST, DB_NAME, DB_USER e DB_PASS no ambiente do banco real; DB_PASS não tem mais fallback secreto. Fornecer SMTP_API_KEY se a integração a usar. Revogar/rotacionar os valores anteriormente embutidos e auditar sua distribuição (F4).
2. Para um banco existente, aplicar a migração abaixo pelo processo habitual de implantação, preservando os dados; não executar o CREATE TABLE nem o seed sobre produção:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

3. A conta usada no login precisa conseguir atualizar usuarios.senha para completar a migração oportunista. Com a coluna ainda curta ou sem UPDATE, as credenciais antigas continuam funcionando, e o risco dos hashes MD5 remanescentes continua até migração ou redefinição (F5). Não existe recuperação do texto de uma senha a partir de MD5 para convertê-la offline.
4. Remover cópias antigas de chamados.csv no servidor pelo procedimento operacional adequado (F7). Elas não são mais criadas pelo fluxo entregue. Configurar TLS/proxy de modo que o servidor informe HTTPS para cookies Secure (F6).
5. Consumidores que abrem o CSV em planilhas devem importar as colunas textuais como texto. F9 permanece sem neutralização automática para manter os valores das integrações.

Nenhuma dessas operações foi executada contra produção ou fora da pasta autorizada. O que foi efetivamente entregue é distinguido acima das etapas operacionais necessárias.
