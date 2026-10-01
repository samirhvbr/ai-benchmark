# RELATORIO — LEB-100-A

Instância **LEB-100-A**, versão **1.1**. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel tinha injeção SQL, XSS refletido e ausência de autorização por proprietário, além de credenciais embutidas, autenticação MD5, sessão sem renovação no login e problemas de disponibilidade e desempenho. Foram registrados **12 achados**: **11 com correções implementadas** e **1 reportado sem alteração**, relativo à interpretação do CSV por planilhas. A migração de contas antigas e a revogação de credenciais expostas dependem de ações de implantação detalhadas abaixo.

Os cinco arquivos originais de `code/` foram alterados no lugar. As sete assinaturas públicas, seus formatos de retorno declarados, a stack mysqli, as quatro rotas, os rótulos, as colunas e links HTML e o conteúdo/ordem do CSV foram preservados nos testes. O cabeçalho CSV agora é emitido literalmente como especificado no manifesto. A regra obrigatória de visibilidade é aplicada igualmente na listagem, busca, detalhe, CSV e média.

**Todas as linhas citadas nos achados referem-se aos arquivos originais recebidos**, não à numeração posterior à edição. A ordem abaixo representa a prioridade de correção. Severidade mede impacto; confiança indica a probabilidade de o problema ser real, não a probabilidade de exploração.

## Achados

### F1 — Injeção SQL pelo termo de busca

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** critica. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** listarChamados concatena busca dentro de LIKE. O termo ' OR 1=1 -- encerra o literal e transforma o filtro em condição verdadeira; uma entrada com apóstrofo também quebra a sintaxe. O texto pode alterar a consulta, inclusive projetar dados por UNION conforme os privilégios do usuário SQL.

**Impacto.** Um usuário autenticado pode contornar a busca e extrair dados acessíveis à conexão, inclusive fora do conjunto de chamados autorizado. Buscas legítimas com apóstrofo falham.

**Decisão e correção.** A busca usa prepare/bind_param em mysqli. Os curingas externos continuam formando a busca por substring; o escopo do usuário é aplicado independentemente do termo.

**Evidência e limites.** No código original, o payload retornou os cinco chamados e D'agua causou erro SQL 1064. Na versão entregue, o payload não retorna chamados e um título com apóstrofo é encontrado normalmente.

### F2 — Ausência de autorização por proprietário nas leituras de chamados

**Local original:** `code/index.php:38–74`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** O login guarda uid/papel, mas listagem, detalhe e exportação não usam essa identidade para limitar as consultas. verChamado busca somente por id, listarChamados consulta todos os donos e exportarCsv exporta toda a tabela. A média também agrega chamados de outros clientes.

**Impacto.** Ana consegue ler os chamados 103/104 de Bruno, inclusive pelo endereço de detalhe e pelo CSV; o indicador divulga informação agregada de terceiros.

**Decisão e correção.** Um helper interno aplica a mesma condição SQL às quatro leituras: cliente vê apenas usuario_id igual ao uid da sessão; técnico vê todos. Identidade inválida em HTTP não recebe registros. CLI sem identidade mantém o acesso administrativo dos relatórios internos.

**Evidência e limites.** Confirmado no original com Ana consultando o chamado 104. Testes HTTP verificaram listagem, busca, todos os detalhes e CSV dos quatro usuários; Ana recebeu 101/102/105, Bruno recebeu 103/104 e ambos os técnicos receberam os cinco. Referências adicionais originais: lib.php:78–101, 107–116 e 123–150.

### F3 — XSS refletido na busca exibida em HTML

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** busca entra sem codificação tanto no atributo value do input quanto no texto de Resultados para. Uma entrada com aspas pode sair do atributo; tags como script também são emitidas diretamente no parágrafo.

**Impacto.** Ao abrir um link de busca malicioso, um usuário autenticado pode executar JavaScript fornecido pelo atacante na origem do painel, permitindo acesso às páginas que sua sessão autoriza.

**Decisão e correção.** A saída da busca passa por htmlspecialchars com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 nos dois contextos. Os demais campos de texto HTML passaram a usar as mesmas opções explícitas.

**Evidência e limites.** Respostas HTTP com aspas, apóstrofo, script e ampersand contiveram as entidades esperadas, sem tag script injetada. Os testes inspecionam a saída HTTP; não executam um navegador.

### F4 — Credenciais de serviços embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** DB_PASS tem um fallback descrito como senha de produção e SMTP_API_KEY contém um token literal. Qualquer leitor do pacote obtém esses valores; a chave SMTP sequer pode ser substituída pelo ambiente.

**Impacto.** Exposição das credenciais a quem recebe o código. Se ainda válidas, podem permitir acesso ao banco ou uso indevido do serviço de e-mail; não foi verificada sua validade nem seu alcance.

**Decisão e correção.** Removidos os valores embutidos. DB_PASS e SMTP_API_KEY são obtidos pelo ambiente, com vazio quando ausentes e sem tratar a string 0 como ausência. Rotação dos valores anteriormente expostos continua sendo uma ação operacional necessária.

**Evidência e limites.** Verificada a leitura das variáveis e sua ausência, incluindo DB_PASS igual a 0. Os valores originais não são reproduzidos neste relatório. Remover o código não revoga credenciais nem as apaga de cópias antigas.

### F5 — Identificador de sessão mantido após autenticação

**Local original:** `code/index.php:15–25`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** session_start aceita a sessão anterior e, após validar a senha, o código somente acrescenta uid/papel. Não há renovação do identificador na mudança de privilégio. Se o atacante conseguir fixar no navegador da vítima uma sessão que conhece, esse identificador passa a representar a conta autenticada.

**Impacto.** Se a precondição de fixação for satisfeita, o atacante reutiliza a sessão autenticada da vítima.

**Decisão e correção.** Adicionado session_regenerate_id(true) antes de salvar a identidade, com modo estrito, cookies exclusivos, HttpOnly e SameSite=Lax. Secure é ativado quando HTTPS é detectado ou já configurado. A sessão é fechada após a leitura, liberando o bloqueio durante relatórios.

**Evidência e limites.** Em HTTP, o cookie anterior mudou após cada login e o identificador antigo voltou a exigir autenticação. Verificados HttpOnly e SameSite. Secure sob HTTPS não foi ensaiado com TLS; atrás de proxy, a implantação deve configurar session.cookie_secure ou informar HTTPS de forma confiável.

### F6 — Senhas armazenadas com MD5 sem sal e sem custo adaptativo

**Local original:** `code/lib.php:15–20`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** autenticar compara md5(senha) com usuarios.senha; schema.sql:7 restringe o campo a CHAR(32) e seed.sql:5–8 grava MD5. O mesmo segredo sempre produz o mesmo digest rápido, permitindo tentativas offline baratas após acesso aos hashes.

**Impacto.** Uma cópia do banco ou extração dos hashes por outra falha facilita recuperar senhas e identificar contas que reutilizam o mesmo segredo.

**Decisão e correção.** A autenticação agora lê o hash por login e usa password_verify para hashes modernos. Aceita MD5 legado com hash_equals e migra após login válido com password_hash/PASSWORD_DEFAULT, com atualização condicional ao hash anterior. schema.sql usa VARCHAR(255); seed.sql contém hashes modernos e mantém as quatro credenciais de exemplo.

**Evidência e limites.** Testados o esquema original CHAR(32), sua ampliação, login antes/depois da migração, senha incorreta, rehash de custo antigo e retorno sem a chave senha. Antes do ALTER, o login continua funcionando sem tentar truncar um hash moderno. Contas sem novo login permanecem em MD5; senhas legadas acima de 72 bytes ou com byte nulo não são migradas automaticamente para bcrypt, preservando sua semântica até uma redefinição. A correção entregue é o mecanismo de migração, não uma alegação de migração de produção concluída.

### F7 — Exportação reutiliza um arquivo compartilhado e potencialmente público

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 99/100. **Corrigido:** sim.

**Mecanismo.** Todas as requisições abrem EXPORT_DIR/chamados.csv com w, gravam e depois reabrem o mesmo caminho com readfile. Uma requisição pode truncar ou sobrescrever o arquivo entre a gravação e a leitura de outra. O destino fixo fica em /var/www/painel/tmp; se servido pelo servidor web, deixa uma cópia acessível fora do login. Diretório inexistente ou sem permissão resulta em exportação vazia, e a saída antecipada após falha SQL deixa o stream sem fechamento explícito.

**Impacto.** Downloads incompletos ou com conteúdo de outra execução; possível exposição de cópias históricas conforme a configuração do servidor. A exportação depende desnecessariamente de um diretório gravável.

**Decisão e correção.** A exportação consulta os dados autorizados e transmite diretamente para php://output. Não cria um arquivo compartilhado, mantém os cabeçalhos e a ordenação por id, usa resultado não armazenado integralmente no cliente e fecha recursos em finally.

**Evidência e limites.** Passaram 24 exportações HTTP concorrentes entre as quatro identidades, com conteúdo correto para cada uma. O novo export também funcionou sem usar EXPORT_DIR. Não foi testado o acesso público ao caminho antigo, pois não há configuração do servidor de produção disponível.

### F8 — Média de resposta divide por zero quando não há amostras

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Se a tabela estiver vazia ou todos os minutos_resposta forem NULL, o loop não incrementa qtd e a função calcula soma/qtd com divisor zero. No PHP 8.4 disponível, isso lança DivisionByZeroError e impede a listagem.

**Impacto.** O painel falha justamente quando ainda não houve resposta a nenhum chamado; após aplicar o escopo correto, também afeta clientes sem amostras próprias.

**Decisão e correção.** SUM e COUNT agregam somente os dados visíveis, sem transferir cada amostra ao PHP. Quantidade zero retorna 0.0. A divisão final continua em PHP para preservar a precisão anterior, evitando o arredondamento decimal observado em AVG no banco de teste.

**Evidência e limites.** Reproduzida a exceção no original. Testados banco vazio, todas as respostas NULL, cliente sem chamados, Bruno sem respostas e média de 77/3 com tolerância 1e-10.

### F9 — Consultas N+1 na listagem e na exportação

**Local original:** `code/lib.php:88–89`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** Cada chamado com tecnico_id chama tecnicoNome, que faz um SELECT adicional em usuarios, mesmo quando o técnico já apareceu em outra linha. O mesmo ocorre no export em lib.php:137. Com N chamados atribuídos, cada operação realiza 1+N consultas de dados.

**Impacto.** Latência e carga no banco crescem com o número de chamados, além do custo da própria consulta de listagem.

**Decisão e correção.** Listagem e CSV usam LEFT JOIN usuarios e COALESCE(nome, -), preservando tecnico_nome e o marcador para chamado sem técnico. O helper tecnicoNome permanece disponível, mas não é chamado por esses loops.

**Evidência e limites.** Com os cinco chamados de exemplo, o contador Com_select confirmou a redução de cinco para uma consulta SELECT em cada operação. LEFT JOIN preservou o chamado 104 sem técnico.

### F10 — Falha de conexão escapa do tratamento de erro no PHP atual

**Local original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** new mysqli é executado antes de consultar connect_errno. No modo de erros padrão do PHP 8.4 disponível, uma falha lança mysqli_sql_exception no construtor e nunca alcança a mensagem prevista. A falha de set_charset também não era tratada.

**Impacto.** A requisição termina com exceção não capturada em vez da resposta controlada. Com display_errors ativo, detalhes internos podem aparecer no navegador.

**Decisão e correção.** Conexão e configuração de charset ficam em try/catch, mantendo também a verificação do retorno para ambientes sem exceções. Falhas retornam HTTP 503 e a mensagem genérica já existente.

**Evidência e limites.** A tentativa inicial de usar a configuração disponível lançou erro de conexão 1698. Em servidor HTTP isolado com senha propositalmente incorreta e display_errors=1, a versão final retornou exatamente HTTP 503 e Falha ao conectar ao banco., sem stack trace.

### F11 — Textos do CSV podem ser interpretados como fórmulas em planilhas

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 85/100. **Corrigido:** não.

**Mecanismo.** O título e o nome do técnico vão diretamente para fputcsv. A codificação CSV separa campos, mas não marca seu tipo como texto: um título =1+1 permanece uma célula iniciada por =. Leitores de planilha que avaliam fórmulas podem interpretá-lo dessa forma.

**Impacto.** Se um agente puder cadastrar um título ou nome malicioso por outra integração e um usuário abrir o CSV como planilha, poderá induzir cálculos ou ações aceitas pelo leitor. O pacote não contém a interface de gravação desses textos, e não foi testada execução em Excel/LibreOffice.

**Decisão e correção.** Somente reportado. Prefixar apóstrofo ou tabulação mudaria os valores consumidos pelos scripts externos. Uma exportação específica para planilhas ou uma política de importação como texto exige definição de contrato; o CSV atual preserva os valores.

**Evidência e limites.** Inserido =1+1 apenas em transação no banco de teste e confirmado o valor literal no CSV; a transação foi revertida. A confiança menor reflete a dependência do leitor e de um caminho externo de entrada de dados.

### F12 — Parâmetros HTTP em forma de array causam erros de tipo

**Local original:** `code/index.php:72–73`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo.** PHP aceita busca[]=x e login[]=ana como arrays em GET/POST. O código os encaminha diretamente a funções com parâmetros string, causando TypeError. senha[] tem o mesmo problema em index.php:22; ver[] é convertido em inteiro em vez de rejeitado.

**Impacto.** Requisições malformadas encerram com erro interno; a conversão de ver[] pode consultar um identificador diferente daquele representado pela entrada.

**Decisão e correção.** Validação de entradas escalares nas rotas antes de chamar as funções públicas. Parâmetros em forma de array retornam HTTP 400, preservando a interface de entradas válidas.

**Evidência e limites.** Testes HTTP confirmaram 400 para busca[], ver[], login[] e senha[]. Os acessos normais, nomes dos parâmetros e redirecionamento após login continuaram funcionando.

## Decisões — o que não alterei e por quê

- **Stack, assinaturas mysqli, caminhos de arquivos e estrutura geral:** Os consumidores externos dependem dessas interfaces. Não houve troca de tecnologia, ORM, framework ou reescrita. tecnicoNome e EXPORT_DIR foram mantidos para reduzir risco a consumidores não inventariados.

- **Rótulos e limites de prioridade/SLA:** formatarStatus mantém os três textos exatos e seu fallback original. rotuloPrioridade conserva todos os ramos, inclusive NULL, o limite estrito de 30 minutos e a prioridade 4 dentro do SLA. Não há fundamento no contrato para alterar essas regras.

- **Semântica de busca LIKE, retorno completo da listagem e ordenação:** Mantidos os curingas % e _, a busca por substring e criado_em DESC. Paginação, full-text e limites arbitrários mudariam resultados ou a interface. O CSV continua ordenado por id crescente.

- **Valores de texto exportados para CSV (F11):** Neutralizar fórmulas prefixando caracteres altera os dados recebidos por integrações. O risco dependente do leitor foi documentado; não foi introduzido um segundo formato ou rota sem autorização de escopo.

- **Autenticação MD5 de contas legadas ainda não migradas:** Rejeitar todos os hashes antigos bloquearia contas existentes. O rehash acontece após autenticação válida e ampliação da coluna; senhas incompatíveis com bcrypt aguardam redefinição, sem truncamento nem mudança silenciosa de credencial.

- **Acesso administrativo de rotinas CLI sem sessão:** As assinaturas não recebem identidade e o manifesto cita exportação noturna e relatórios internos. CLI sem identidade continua acessando o conjunto completo; sessão presente limita o acesso e HTTP sem identidade válida não obtém registros. Código CLI é uma fronteira de confiança, não um mecanismo para processar pedidos públicos anônimos.

- **Rotação de segredos, remoção de cópias históricas de CSV e migração do banco de produção:** O pacote não fornece controle dos serviços nem acesso autorizado ao banco de produção. Foram corrigidos os arquivos e testada a migração em banco isolado. A implantação precisa executar as ações operacionais descritas, sem presumir que alterações locais as realizaram.

- **Consultas por identificadores inteiros e outras mudanças sem evidência de defeito:** verChamado e tecnicoNome recebem int; suas concatenações não oferecem a injeção por texto encontrada na busca. Foi adicionado o escopo de visibilidade onde necessário, sem uma refatoração geral motivada apenas pelo estilo do código.


## Implantação e limites operacionais

1. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` e, se o serviço for utilizado, `SMTP_API_KEY` no ambiente. Os dois segredos que constavam no fonte devem ser revogados/rotacionados nos serviços; apagar o literal não elimina sua exposição anterior. O usuário da aplicação precisa de leitura e de `UPDATE` em `usuarios.senha` para rehash; não precisa de privilégio DDL em execução.
2. Para um banco existente, execute a migração abaixo com uma identidade administrativa, em janela compatível com o volume e a versão do banco. O esquema novo já tem o tamanho adequado. Não execute `schema.sql`/`seed.sql` sobre dados de produção para migrá-los.

   ```sql
   ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
   ```

   A migração foi exercitada sobre uma cópia isolada do esquema e seed originais. O login anterior ao ALTER também foi testado e preservado. Hashes MD5 não permitem conversão segura sem conhecer a senha: contas inativas e senhas incompatíveis com bcrypt exigem redefinição por processo operacional. A alteração do tipo não foi executada no banco de produção.
3. A exportação nova não cria `EXPORT_DIR/chamados.csv`. Uma cópia antiga pode continuar existindo na implantação; retire-a da área pública depois de verificar seu uso operacional. Não foram apagados arquivos de produção. O caminho e a constante permanecem definidos, mas não são usados pelo novo export.
4. HTTP usa a identidade de sessão criada no login. Relatórios administrativos CLI sem sessão continuam globais; scripts HTTP internos devem estabelecer uma identidade válida. Em terminação TLS por proxy, configure `session.cookie_secure=1` quando o backend não receber indicação confiável de HTTPS.

## Verificação executada

Ambiente: PHP **8.4.26**, mysqli/mysqlnd e MariaDB **11.8.6** local isolado por socket Unix. O servidor e os bancos temporários foram usados apenas para testes; não foi instalado nenhum pacote nem adicionada dependência ao sistema. O esquema se declara MySQL 8: a execução nesse produto específico não foi realizada, embora as consultas e o DDL usados sejam compatíveis com sua sintaxe.

- `php -l` passou em `config.php`, `lib.php` e `index.php`.
- **151 verificações PHP** passaram: reflexão das sete assinaturas públicas comparadas com o original; rótulos; combinações de prioridade/SLA; quatro logins e falhas de autenticação; identidades inválidas; autorização das leituras; formatos e conteúdo CSV; apóstrofos, aspas, barras, quebras de linha e Unicode; bancos sem amostras; login legado e migração.
- **129 verificações HTTP** passaram: formulário e redirecionamento, cookie renovado e sessão anterior invalidada, estrutura exata da tabela, visibilidade por usuário, busca, detalhe, headers do CSV, status, tentativas SQLi/XSS, entradas em array e **24 downloads concorrentes**. Essa suíte foi repetida após o ajuste do tratamento da conexão, com o mesmo resultado.
- **14 verificações adicionais de migração** passaram em outro banco, criado com os arquivos originais: CHAR(32), alteração para VARCHAR(255), rehash, senha inválida, senhas com mais de 72 bytes e byte nulo e atualização de custo antigo.
- Reproduzidos no original e comparados com a versão corrigida: SQLi, leitura do chamado de outro cliente, erro de sintaxe em busca com apóstrofo e divisão por zero. Contadores de sessão SQL confirmaram **5 → 1 SELECT** na listagem e **5 → 1 SELECT** no CSV, usando os dados de exemplo.
- Teste separado com credenciais de banco inválidas e `display_errors=1` confirmou **HTTP 503**, mensagem genérica e ausência de stack trace. Testada também a configuração por ambiente, incluindo senha igual a `0` e ausência das variáveis.
- O título `=1+1` foi inserido somente em transação de teste e permaneceu literal no CSV, confirmando o limite descrito em F11. Não se afirma execução de fórmulas em uma planilha real.

Os scripts auxiliares e bancos de teste ficaram em `/tmp`, fora da entrega. O código não recebeu arquivos de teste nem dependências. Não foram executados testes no servidor web, SMTP ou banco de produção. O comportamento de cookies sob TLS e a possibilidade de servir o diretório de exportação antigo dependem dessa infraestrutura e não foram apresentados como verificados.
