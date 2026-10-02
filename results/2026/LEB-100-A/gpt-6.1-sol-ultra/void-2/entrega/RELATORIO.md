# Relatório técnico — LEB-100-A

Instância 1.1 · LEB spec 1.1.0 · matriz SHA-256 `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel tinha vulnerabilidades concretas na busca, na saída HTML e na autorização de clientes, além de falhas de exportação, sessão, tratamento de erros e desempenho. Foram identificados 13 achados: 11 corrigidos no código entregue e dois reportados sem correção, com motivos explícitos. A remoção dos segredos do fonte não representa sua revogação no ambiente externo.

As mudanças são incrementais em `code/lib.php`, `code/index.php` e `code/config.php`. `code/schema.sql` e `code/seed.sql` foram preservados. Mantidos mysqli, assinaturas públicas, rotas, rótulos, campos retornados e estrutura da tabela HTML; nenhuma dependência foi introduzida.

## Achados, em ordem de prioridade

Todas as referências abaixo usam a **numeração original dos arquivos recebidos**, antes das alterações. Os IDs são os mesmos de `achados.json`. A confiança mede a existência do problema; condições adicionais de exploração estão descritas no mecanismo.

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** critica. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** listarChamados concatenava busca dentro de WHERE titulo LIKE '%...%'. Uma aspa fecha o literal e permite modificar o SELECT, inclusive por UNION com as nove colunas de chamados. O mesmo usuário de banco consulta usuarios na autenticação; seus logins e hashes podem ser projetados em campos da listagem.

**Impacto:** Leitura de dados de outros clientes e potencial extração de logins e hashes de senha; a extensão depende dos privilégios do usuário SQL. Não é necessário autenticar como técnico para fornecer busca.

**Ação/justificativa:** Consulta preparada mysqli com o padrão LIKE vinculado como parâmetro. Os curingas % e _ mantêm a semântica anterior; a restrição por proprietário integra a mesma consulta.

### F2 — Ausência de autorização por proprietário dos chamados

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** index.php, linhas 38–45 e 52–74, lia uid e papel, mas não os usava para restringir consultas. listarChamados retornava todos; verChamado buscava somente pelo id. exportarCsv, linhas 132–143, exportava todos, e mediaResposta, linhas 109–116, agregava todos. Ana conseguia ler os chamados 103 e 104 de Bruno, inclusive por URL direta e CSV.

**Impacto:** Exposição horizontal de títulos, descrições, responsáveis e dados de atendimento de outros clientes, contrariando a regra explícita do manifesto.

**Ação/justificativa:** Escopo único aplicado em SQL à listagem, detalhe, CSV e média: cliente recebe apenas c.usuario_id igual ao uid da sessão; técnico mantém acesso completo; papel inválido é negado. Detalhe alheio retorna null e a mesma mensagem de inexistente. Chamadas internas sem uid em sessão mantêm o acesso global preexistente.

### F3 — XSS refletido em dois contextos HTML da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** busca era inserida diretamente no atributo value entre aspas duplas e no conteúdo de um parágrafo. Aspas podiam criar atributos e tags como `<img src=x onerror=...>` podiam criar elementos executáveis quando um usuário autenticado abria a URL.

**Impacto:** Execução de JavaScript na origem do painel, leitura de dados disponíveis à vítima e ações com sua sessão.

**Ação/justificativa:** Escape de saída com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 explícito nos dois pontos e nos demais valores HTML. O valor original continua sendo usado como parâmetro da consulta.

### F4 — Arquivo CSV compartilhado causa corrida e vazamento entre exportações

**Local original:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Todas as requisições abriam EXPORT_DIR/chamados.csv com w, truncando o mesmo arquivo. Enquanto uma escrevia, outra podia truncar ou sobrescrever; depois de fechar seu handle, a primeira fazia readfile pelo mesmo caminho e podia enviar o conteúdo da segunda. fopen também falhava quando o diretório fixo não existia ou não era gravável.

**Impacto:** CSV incompleto ou incorreto e exposição entre clientes em exportações concorrentes. A falha de abertura era silenciosa, produzindo resposta vazia.

**Ação/justificativa:** Geração em php://temp privado da requisição, envio pelo mesmo handle e fechamento em finally; não há arquivo publicado ou compartilhado nem dependência do diretório fixo. Falhas de geração são explícitas e controladas na rota. O CSV não fica duplicado no buffer HTML.

### F5 — Segredos de banco e SMTP incorporados ao código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 95/100. **Corrigido:** sim.

**Mecanismo:** DB_PASS incluía um fallback identificado no comentário como senha de produção, e SMTP_API_KEY era uma constante literal. Quem obtivesse o pacote, um backup ou o repositório receberia os valores; getenv ausente ou vazio ainda selecionava a senha embutida. Não há evidência fornecida para confirmar se o provedor ainda aceita esses segredos.

**Impacto:** Uso indevido do banco ou serviço de e-mail se as credenciais estiverem ativas. A exposição do segredo no código é certa; sua validade operacional é condicionada ao ambiente.

**Ação/justificativa:** Removidos os valores incorporados. DB_PASS e SMTP_API_KEY vêm do ambiente; DB_PASS distingue variável ausente de valores como 0. É necessário configurar o ambiente e rotacionar os segredos antigos no banco/provedor; essa revogação externa não foi executada.

### F6 — Hashes MD5 sem salt permanecem vulneráveis a quebra offline

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido:** não.

**Mecanismo:** autenticar aplica md5 à senha e compara o resultado ao campo usuarios.senha. schema.sql, linha 7, limita o campo a CHAR(32), e seed.sql grava MD5. Senhas iguais têm hashes iguais, e o cálculo rápido permite testar muitos candidatos após leitura de usuarios.

**Impacto:** Recuperação de senhas fracas em ataques offline e possível comprometimento de contas ou de outros serviços com senhas reutilizadas.

**Ação/justificativa:** Não corrigido nesta entrega. Uma migração segura precisa ampliar a coluna em produção para VARCHAR(255), verificar com password_verify e fazer atualização gradual de MD5 após login correto usando password_hash, preservando as credenciais de teste. Alterar somente schema.sql não migra o banco já implantado; não foi feita DDL automática nem uma atualização que poderia truncar hashes em CHAR(32).

### F7 — Login não renovava o identificador da sessão

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 98/100. **Corrigido:** sim.

**Mecanismo:** session_start carregava uma sessão existente e, após autenticação, o código apenas preenchia uid e papel. Um atacante que conseguisse estabelecer e conhecer esse identificador antes do login poderia reutilizá-lo após a autenticação da vítima. O sucesso depende de conseguir fixar/compartilhar uma sessão, mas a ausência de renovação é explícita.

**Impacto:** Reutilização indevida de uma sessão autenticada sob as condições descritas.

**Ação/justificativa:** session_regenerate_id(true) no login bem-sucedido, strict_mode e cookies HttpOnly e SameSite=Lax. Secure é habilitado quando HTTPS é informado pelo servidor; não se confia diretamente em cabeçalhos encaminhados pelo cliente.

### F8 — Média de resposta divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** A consulta excluía minutos_resposta NULL, iniciava qtd em zero e retornava soma/qtd sem guarda. Banco vazio ou somente chamados sem primeira resposta deixavam qtd igual a zero; PHP 8 lança DivisionByZeroError. No seed, Bruno possui apenas chamados com resposta NULL, tornando o caso imediato após corrigir seu escopo.

**Impacto:** A listagem deixa de carregar exatamente para clientes sem primeira resposta ou para instalações sem dados.

**Ação/justificativa:** SUM e COUNT dos minutos não nulos calculados no banco, divisão apenas com contagem positiva e retorno float 0.0 para conjunto sem respostas. Mantida a divisão em PHP para preservar a precisão do resultado anterior; AVG sobre INT pode arredondar para quatro casas no banco testado.

### F9 — Exceções de banco escapavam do ponto de entrada web

**Local original:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** O teste de connect_errno ocorria depois de new mysqli. Em PHP 8.4 com mysqli em modo de exceção, uma falha de conexão lança mysqli_sql_exception antes desse teste; as consultas posteriores também não eram capturadas. Com display_errors habilitado, o erro inclui dados técnicos e stack trace na resposta.

**Impacto:** Resposta fatal sem tratamento e possível exposição de caminhos, usuário/host de banco e SQL, conforme a configuração de exibição de erros.

**Ação/justificativa:** Modo mysqli estrito explícito e captura de RuntimeException, incluindo mysqli_sql_exception, na entrada web. Resposta HTTP 500 genérica, limpeza de saída HTML parcial e log somente da classe/código. Scripts que usam lib.php continuam recebendo exceções para tratar em seu próprio contexto.

### F10 — Fórmulas em campos CSV podem ser executadas por planilhas

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Corrigido:** não.

**Mecanismo:** titulo e nome do técnico são enviados como conteúdo de células sem política de importação. fputcsv protege delimitadores e aspas, mas não impede que uma planilha interprete células iniciadas por =, +, - ou @ como fórmulas. O pacote não mostra a rota que grava esses campos nem o aplicativo consumidor, portanto a exploração depende de controlar um campo e de como o CSV é aberto.

**Impacto:** Execução de fórmulas ao abrir o CSV, com manipulação da planilha e possíveis requisições externas conforme os recursos do aplicativo.

**Ação/justificativa:** Não corrigido no CSV público: prefixar apóstrofo ou modificar esses campos altera os dados lidos por integrações externas. Recomenda-se importação explícita como texto ou um formato/rota separados para planilhas mediante acordo com consumidores. Nenhuma neutralização foi apresentada como concluída.

### F11 — Consultas N+1 na listagem e na exportação

**Local original:** `code/lib.php:87–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** Cada chamado com tecnico_id invocava tecnicoNome, que executava SELECT nome FROM usuarios; exportarCsv repetia o padrão nas linhas 136–137. Para N chamados atribuídos, cada operação executava 1+N SELECTs, inclusive consultas repetidas para o mesmo técnico.

**Impacto:** Latência e carga no banco crescem com o número de chamados, prejudicando listagens e relatórios.

**Ação/justificativa:** LEFT JOIN usuarios na consulta de listagem e de CSV, com COALESCE(t.nome, '-') para preservar o marcador sem técnico. Cada operação passa a fazer um SELECT. tecnicoNome permanece disponível, com a mesma assinatura, para outros consumidores.

### F12 — Cabeçalho CSV emitido diferia do texto exato do manifesto

**Local original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** fputcsv colocava o campo Aberto em entre aspas por conter um espaço. A primeira linha efetiva era ID,Titulo,Status,Tecnico,"Aberto em", enquanto o manifesto exige exatamente ID,Titulo,Status,Tecnico,Aberto em. Ambos são CSV válido, mas diferem na comparação literal declarada.

**Impacto:** Consumidores que reconhecem o cabeçalho literal podem rejeitar o arquivo apesar de parsers CSV normais lerem os mesmos nomes.

**Ação/justificativa:** Cabeçalho literal com os cinco nomes exigidos e quebra LF. Linhas de dados continuam em fputcsv, com parâmetro escape explícito vazio para roundtrip correto de aspas e barras invertidas e compatibilidade com PHP 8.4.

### F13 — Parâmetros HTTP estruturados causavam erros de tipo

**Local original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido:** sim.

**Mecanismo:** PHP permite login[]=x, senha[]=x e busca[]=x. Os arrays eram passados diretamente a parâmetros string de autenticar e listarChamados, causando TypeError. A busca fica nas linhas 72–73. ver, linha 53, era convertido diretamente para int, aceitando coerções como 101abc em lugar de um identificador válido.

**Impacto:** Requisições malformadas produzem erro fatal ou consultam um identificador diferente do enviado, com possível exposição de diagnóstico se display_errors estiver ligado.

**Ação/justificativa:** Validação de strings em login, senha e busca e validação de identificador decimal positivo dentro do intervalo inteiro. Entradas inválidas recebem HTTP 400 sem consultar o chamado e sem TypeError.

## Decisões

- **MD5 e coluna usuarios.senha CHAR(32):** F6 permanece aberto: ampliar a coluna requer migração do banco existente e implantação coordenada da transição de hashes. Preservadas as credenciais de teste e a assinatura de autenticar; mudar só o SQL de instalação seria insuficiente.
- **Conteúdo original das células CSV potencialmente interpretáveis como fórmula:** F10 permanece aberto: neutralizar com prefixos muda títulos/nomes para integrações que consomem os mesmos dados. É necessário acordar importação como texto ou um canal específico de planilha.
- **Acesso global das rotinas internas sem uid na sessão:** Os scripts externos recebem apenas mysqli e o manifesto não acrescenta um argumento de identidade. A entrada web autentica antes de chamar as funções; sessões de clientes são filtradas em todas elas. Scripts de serviço continuam sendo consumidores confiáveis com acesso ao banco, como anteriormente.
- **Rótulos de status, regras de prioridade/SLA e fallback de status desconhecido:** Os valores 1/2/3 continuam exatamente Aberto/Em atendimento/Resolvido; limite de atraso continua maior que 30, prioridade 4 mantém seu rótulo e NULL mantém Aguardando 1a resposta. Não há regra fornecida que permita inventar outro rótulo para status desconhecido ou reclassificar prioridades.
- **Rotas, funções existentes, stack mysqli e estrutura HTML:** Compatibilidade exigida pelo manifesto: mesmos nomes/assinaturas, chave tecnico_nome, tabela tabela-chamados, colunas e links. tecnicoNome, embora fora da tabela do manifesto, também foi mantida. Sem framework, dependência nova, renomeação ou movimentação de arquivos.
- **Curingas LIKE, ordenação e paginação:** Preservados busca por título com %/_, criado_em DESC na listagem e id crescente no CSV. Não adicionei paginação, escape literal de curingas ou limites de linhas, pois isso alteraria os conjuntos retornados aos consumidores.
- **Índices, schema.sql e seed.sql:** Os índices de proprietário e ordenação já existem; a busca %termo% não seria resolvida simplesmente com um índice B-tree. Nenhuma DDL extra é necessária para as correções feitas. Sem evidência de volume/plano ruim, não acrescentei índices ou constraints que rejeitassem dados legados.
- **EXPORT_DIR e demais constantes de configuração públicas existentes:** EXPORT_DIR deixa de ser usada pelo exportador, mas permanece definida para não remover uma constante que scripts externos possam consultar. Host, nome, usuário do banco e fuso horário continuam configurados como antes.
- **Rotação dos segredos expostos e configuração de HTTPS atrás de proxy:** A entrega remove os segredos do código, mas não dispõe de acesso administrativo ao banco/provedor para revogá-los. A implantação deve fornecer DB_PASS/SMTP_API_KEY e rotacionar os valores antigos. Secure depende do HTTPS informado pelo servidor; a configuração de um proxy confiável deve traduzir esse estado sem confiar em cabeçalhos arbitrários.
- **Separação completa do controlador, políticas de autenticação adicionais e mudanças de infraestrutura:** A correção mantém o sistema legado e sua superfície. Não reestruturei em camadas nem acrescentei logout, regras de senha, serviços de rate limiting ou serviços externos sem requisitos/dados operacionais que permitam implantar essas mudanças com compatibilidade.

## Validação e limites

A validação foi executada com PHP 8.4.26, mysqli/mysqlnd e um MariaDB 11.8.6 isolado, carregando o schema e o seed entregues. Não foram acessados dados de produção. A compatibilidade de MySQL 8 foi avaliada pela sintaxe utilizada, mas não executada nesse motor; os testes reais usam MariaDB.

Foram aprovadas **84 verificações de biblioteca/contrato, 49 verificações HTTP e duas verificações da flag Secure (135 no total)**, sem Warning, Deprecated ou Fatal nos logs de execução. Também passaram as verificações de sintaxe dos três arquivos PHP entregues.

As verificações cobriram:

- Os quatro logins do seed, senha incorreta, retorno de autenticação e assinaturas públicas por reflexão; rótulos de status e prioridades, incluindo minutos NULL e fronteira 30/31.
- Equivalência estrita de campos, valores e tipos da listagem e dos detalhes frente ao SELECT original sem sessão. A normalização mantém os seis campos numéricos como strings, com NULL intacto; a autenticação mantém seu retorno original.
- SQLi tratada como dado; busca por aspas, barras invertidas e Unicode; semântica dos curingas LIKE.
- Visibilidade de Ana/Bruno e dos dois técnicos em lista, detalhe, média e CSV; papel desconhecido negado; acesso global preservado para scripts sem sessão. Bruno recebe média 0.0; a média global do seed permanece exatamente 77/3, sem arredondamento adicional do banco.
- Banco vazio e todas as respostas NULL, com média float 0.0, listagem vazia e CSV somente com cabeçalho. Fixtures foram removidas após os testes.
- Cabeçalho CSV literal, cinco campos, ids crescentes, rótulos e técnico ausente; roundtrip de vírgulas, aspas, barras invertidas e UTF-8; ausência de arquivo compartilhado em EXPORT_DIR.
- Contagem real de consultas: um SELECT para listarChamados e um para exportarCsv, eliminando as buscas repetidas por técnico.
- Rotas e estrutura HTML do manifesto; cookies HttpOnly/SameSite, regeneração e invalidação do ID antigo e rejeição de ID de sessão arbitrário pelo strict_mode.
- XSS refletido nos dois contextos de busca e escape de título/descrição/nome de técnico; parâmetros login, senha, busca e ver enviados como arrays, além de IDs malformados, com resposta controlada.
- **16 downloads CSV paralelos**, com clientes e técnicos, sem truncamento ou mistura de registros.
- Credencial de banco inválida: HTTP 500 genérico, sem usuário/senha, SQL ou stack trace na resposta.

O parâmetro export enviado como array não é reconhecido como a string csv e cai na listagem normal, sem erro de tipo. A flag Secure foi verificada em dois casos: configuração prévia true preservada e HTTPS informado/simulado pelo servidor; não foi montado um endpoint TLS real. Permanecem os riscos F6 e F10, a necessidade de revogar os segredos antigos e o pressuposto de que scripts de serviço sem sessão são confiáveis. O manifesto não foi editado e os nomes/caminhos de code/ foram mantidos.
