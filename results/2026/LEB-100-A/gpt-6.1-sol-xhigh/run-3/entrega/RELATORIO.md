# Relatório técnico — LEB-100-A

## Resumo

O painel preservava as funções e telas do legado, mas não aplicava a visibilidade por cliente e continha SQL injection, XSS, segredos embutidos, autenticação MD5 e problemas de sessão, exportação e média. Entreguei alterações localizadas nos cinco arquivos existentes de `code/`, sem trocar mysqli, stack, rotas ou nomes. São **14 achados: 13 tratados no código e um somente reportado (F12)**. A correção de MD5 é uma migração gradual, com riscos residuais explicitados abaixo.

Instância **LEB-100-A**, versão **1.1**. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`. `TAREFA.md` e `manifest.md` não foram alterados. Todas as localizações a seguir usam a **numeração original** dos arquivos recebidos; a numeração final pode ter mudado.

## Achados, em ordem de prioridade

### F1 — SQL injection na busca por título

**Local:** `code/lib.php:82–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** listarChamados concatena busca dentro de LIKE. O termo ' OR 1=1 -- encerra a string, acrescenta uma condição verdadeira e comenta o restante da consulta. A versão original devolveu os cinco chamados com esse payload nos testes.

**Impacto:** Um usuário autenticado altera a consulta e pode ler dados além do filtro solicitado, inclusive outras tabelas acessíveis à conexão por UNION compatível com a projeção.

**Decisão e correção:** Substituí a concatenação por prepared statement mysqli e parâmetro LIKE. O predicado de visibilidade fica separado dos dados da busca. Preservei os curingas % e _ do comportamento anterior.

### F2 — Ausência de autorização por proprietário nas leituras

**Local:** `code/lib.php:100–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** verChamado consulta apenas id; listarChamados (linhas 80–89), exportarCsv (132–137) e mediaResposta (109) também leem todos os clientes. index.php guarda uid/papel nas linhas 24–25 e os recupera em 38–39, mas não os usa para limitar consultas. Ana conseguiu abrir o chamado 103, de Bruno, na versão original.

**Impacto:** Clientes leem títulos, descrições, informações de atendimento e exportações de outros clientes, contrariando diretamente o manifesto. O indicador de média também agrega atendimentos alheios.

**Decisão e correção:** Centralizei um predicado de visibilidade na biblioteca: cliente com uid válido lê somente usuario_id correspondente; técnico autenticado lê todos; contexto web ausente ou inválido não lê nenhum. Apliquei o predicado à lista, detalhe, CSV e média. Rotinas CLI sem sessão conservam o acesso global dos relatórios internos.

### F3 — XSS refletido no termo de busca

**Local:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** busca é inserida sem escape no atributo value do input e no texto de Resultados para. Aspas encerram o atributo e tags como <script> ou <img onerror> entram diretamente no HTML. O payload apareceu como marcação ativa na versão original.

**Impacto:** Um link de busca malicioso executa JavaScript na origem do painel quando aberto por um usuário autenticado, permitindo ler dados e fazer requisições com a sessão da vítima.

**Decisão e correção:** Escapei ambos os pontos com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 explícito. Usei a mesma função nos demais campos textuais exibidos.

### F4 — Credenciais de produção embutidas na configuração

**Local:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** DB_PASS tem uma senha de produção como fallback e SMTP_API_KEY contém uma chave literal. Quem recebe o código obtém esses valores; a falta de DB_PASS usa silenciosamente o segredo embutido.

**Impacto:** Vazamento do pacote ou do repositório permite tentar acesso ao banco e ao serviço de e-mail, conforme validade dos segredos, alcance da rede e permissões das contas.

**Decisão e correção:** Removi ambos os segredos do código. DB_PASS precisa existir no ambiente e aceita valor vazio explicitamente fornecido; SMTP_API_KEY vem do ambiente. A remoção não revoga credenciais já expostas: rotação no ambiente de implantação continua necessária.

### F5 — Senhas armazenadas e verificadas com MD5 sem salt

**Local:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** autenticar calcula md5(senha) e compara no SQL. schema.sql:7 limita senha a CHAR(32), e seed.sql:5–8 grava MD5. O algoritmo é rápido e determinístico; hashes iguais revelam reutilização e permitem testes de candidatos fora do sistema sem custo adaptativo.

**Impacto:** Uma cópia da tabela usuarios permite tentativas offline rápidas de descobrir senhas e assumir contas, inclusive de técnicos.

**Decisão e correção:** Introduzi password_verify/password_hash e VARCHAR(255); os quatro usuários de teste mantêm as senhas do manifesto com hashes bcrypt. MD5 é aceito temporariamente e migrado após login válido, com atualização condicionada ao hash anterior. O código verifica a capacidade da coluna e não trunca hashes em bancos antigos. Senhas legadas maiores que 72 bytes ou com NUL não são convertidas para bcrypt; logins bcrypt rejeitam esses formatos. A conclusão da migração em produção exige ALTER TABLE e tratar contas que não migrarem.

### F6 — Identificador de sessão preservado depois do login

**Local:** `code/index.php:15–25`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 98/100. **Estado:** Corrigido no código.

**Mecanismo:** session_start mantém a sessão pré-login; autenticar com sucesso apenas grava uid e papel no mesmo identificador. O teste confirmou que o cookie original não mudava ao autenticar. Quem conseguir impor ou conhecer uma sessão pré-login pode continuar usando seu identificador após a vítima entrar.

**Impacto:** Se houver um vetor para fixar a sessão no navegador da vítima, o atacante reaproveita a sessão autenticada e seus privilégios.

**Decisão e correção:** Ativei strict mode e cookies exclusivos, configurei HttpOnly, SameSite=Lax e Secure quando HTTPS é informado pelo servidor. Renovo o identificador com session_regenerate_id(true) antes de gravar a identidade e interrompo o login se a renovação falhar. Encerro a escrita da sessão antes das leituras demoradas.

### F7 — Exportações concorrentes compartilham um arquivo persistente

**Local:** `code/lib.php:125–150`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** Toda exportação abre EXPORT_DIR/chamados.csv com modo w e, após fechar, reabre o mesmo caminho com readfile. Outra requisição pode truncar, sobrescrever ou substituir o conteúdo nesse intervalo. O caminho configurado fica sob /var/www/painel/tmp; se servido como conteúdo estático, o arquivo persistente também fica acessível sem passar pelo login.

**Impacto:** Downloads concorrentes podem receber conteúdo incompleto ou de outra requisição. Depois da correção de visibilidade, o arquivo compartilhado também permitiria misturar dados de clientes. Exposição estática depende da configuração do servidor web, não fornecida.

**Decisão e correção:** Troquei o arquivo compartilhado por php://temp privado para cada chamada. O CSV é finalizado antes dos headers e enviado pelo mesmo recurso, sem reabrir um nome global; finally fecha o stream e libera a consulta. EXPORT_DIR foi mantido como constante, mas não é mais usado pelo exportador. Arquivos antigos de implantação precisam ser removidos separadamente.

### F8 — Campos textuais do CSV podem ser interpretados como fórmulas

**Local:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Estado:** Corrigido no código.

**Mecanismo:** titulo e nome do técnico são enviados diretamente ao fputcsv. O escape CSV protege delimitadores, mas não impede que uma planilha interprete um campo iniciado por =, +, -, @ ou controles como fórmula. Um título =1+1 foi usado como fixture e a nova exportação o neutralizou.

**Impacto:** Se esses campos contiverem conteúdo controlado por terceiros e o CSV for aberto em uma planilha, podem executar fórmulas e referências externas conforme o aplicativo e suas opções. A entrada desses dados não está implementada neste pacote.

**Decisão e correção:** Prefixei com apóstrofo os campos textuais potencialmente interpretáveis como fórmula, incluindo espaços/controles antes do operador. Preservei o técnico ausente como '-' e os campos comuns sem alteração. Cabeçalho, quantidade e ordem de registros, status e colunas permanecem no contrato; a proteção altera somente a representação de textos perigosos.

### F9 — Média falha quando não há respostas registradas

**Local:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** mediaResposta inicializa qtd em zero e só incrementa para minutos_resposta não nulo. Sem chamados respondidos, executa soma / 0; no PHP 8.4 isso produz DivisionByZeroError. Reproduzi o erro na versão original com todos os minutos nulos.

**Impacto:** A listagem deixa de carregar em banco vazio ou sem respostas. Após o isolamento por cliente, Bruno também precisa de um resultado válido, pois seus dois chamados têm minutos nulos.

**Decisão e correção:** Passei a calcular COALESCE(AVG(...), 0) no banco e devolver float. A expressão minutos_resposta + 0e0 evita o arredondamento DECIMAL de AVG(INT), preservando a média original. Sem respostas, o resultado é 0.0.

### F10 — Consulta adicional por técnico em cada chamado

**Local:** `code/lib.php:88–90`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** listarChamados chama tecnicoNome para cada linha com tecnico_id; exportarCsv repete o padrão nas linhas 136–137. A mesma Carla é consultada repetidamente, e o número de consultas cresce com a quantidade de registros atribuídos.

**Impacto:** Mais viagens ao banco e carga por listagem ou exportação, mesmo quando muitos chamados têm o mesmo técnico.

**Decisão e correção:** Usei LEFT JOIN usuarios e COALESCE(nome, '-') em ambas as consultas. Preservei os chamados sem técnico, a chave tecnico_nome, os campos numéricos como strings na listagem e as ordenações originais. Medição de Com_select confirmou uma consulta de leitura por operação, sem N+1.

### F11 — Tratamento de falha de conexão não cobre exceções mysqli

**Local:** `code/index.php:9–13`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** O código pretende tratar erros via connect_errno depois de new mysqli. No PHP 8.4 instalado, mysqli lança mysqli_sql_exception por padrão e esse fluxo é interrompido antes do if. Outras consultas também não possuem tratamento de exceção no ponto de entrada; a exposição de detalhes depende de display_errors.

**Impacto:** Falhas do banco causam resposta não controlada e podem expor mensagens ou stack traces dependendo da configuração do PHP. Falhas de exportação também podiam terminar silenciosamente com resposta vazia.

**Decisão e correção:** Configurei o modo estrito mysqli explicitamente, envolvi conexão/charset em try/catch com HTTP 500 e mensagem genérica, e instalei um handler para falhas restantes no index. Desativei a exibição de erros e mantive diagnóstico nos logs do servidor. O exportador lança erro em falhas de preparação/escrita/leitura e libera recursos.

### F12 — Tentativas de login não têm limite de frequência

**Local:** `code/index.php:20–29`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 100/100. **Estado:** Somente reportado.

**Mecanismo:** Cada POST com login executa autenticar novamente, sem contador, janela de tempo ou controle por conta/origem. É possível repetir candidatos indefinidamente; renovar o cookie não impõe um limite. Bcrypt encarece a verificação, mas não cria um limite de tentativas.

**Impacto:** Permite ataques online de adivinhação de senhas e consumo de recursos com sucessivas verificações de hashes.

**Decisão e correção:** Somente reportado. Um controle efetivo precisa de estado compartilhado e de uma política de janela, recuperação e origem confiável para evitar bloqueio indevido de clientes. Não inseri um limite arbitrário em sessão, que seria contornável por novos cookies. Recomendo rate limit no ingresso ou implementação persistente após definir essa política.

### F13 — Parâmetros HTTP em formato de array causam erros de tipo

**Local:** `code/index.php:22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** PHP transforma login[]=ana ou busca[]=x em arrays. index.php passa os valores diretamente a autenticar e listarChamados (linha 73), que exigem string; isso causa TypeError. ver[] também era convertido para inteiro sem distinguir o formato recebido.

**Impacto:** Requisições malformadas causam erros no atendimento e ruído nos logs; o cast de ver[] pode selecionar um identificador não solicitado como escalar. Não implica queda global do serviço.

**Decisão e correção:** Validei os tipos de login, senha, busca e ver na fronteira HTTP. Formatos não escalares não são enviados às funções tipadas nem convertidos em um identificador válido; requisições normais mantêm os mesmos parâmetros e respostas.

### F14 — Média transfere todas as respostas para somar em PHP

**Local:** `code/lib.php:109–114`. **Categoria:** performance. **Severidade:** baixa. **Confiança:** 100/100. **Estado:** Corrigido no código.

**Mecanismo:** A consulta devolve uma linha por resposta registrada, o mysqli mantém um resultado bufferizado e o PHP percorre todas as linhas para somar e contar. A página faz esse trabalho em toda listagem, mesmo sem precisar dos valores individuais.

**Impacto:** Transferência de dados, memória do resultado e trabalho no PHP crescem com os atendimentos respondidos. O banco ainda precisa examinar os registros relevantes mesmo com a correção.

**Decisão e correção:** Usei agregação AVG/COALESCE no SQL com o mesmo filtro de visibilidade. Só um escalar é transferido para PHP. Não afirmei ganho medido de latência: a redução comprovada é de cardinalidade do resultado e trabalho no cliente.

## Decisões — o que não alterei

**Assinaturas públicas, mysqli, nomes dos arquivos, rotas e estrutura HTML/CSV.** São o contrato dos consumidores. Mantive as sete funções públicas, os parâmetros/defaults, os retornos, id da tabela, cinco colunas, links por ID, rótulos e ordenações. Também mantive tecnicoNome, embora não esteja listado como função pública no manifesto.

**Lógica de rotuloPrioridade e fallback de formatarStatus para valores fora de 1–3.** O manifesto define rótulos específicos e não redefine o comportamento para valores fora da faixa. Não alterei os textos nem inferi novas regras de SLA; a comparação com a versão original cobriu 54 combinações de prioridade/minutos e nove valores de status.

**Busca LIKE com curingas e lista completa, sem paginação nem índice full-text novo.** Transformar % e _ em texto literal, limitar linhas ou substituir a busca mudaria comportamentos consumidos externamente. O retorno público é uma lista completa. Eliminei o N+1 sem redesenhar a consulta ou a interface; uma busca com % no início ainda pode exigir varredura.

**Acesso global de processos CLI sem identidade de usuário.** O manifesto cita exportação noturna e relatórios internos e não lhes fornece novo parâmetro de autenticação. Reservei esse acesso a PHP_SAPI=cli sem uid e papel; qualquer sessão com identidade recebe o filtro correspondente e um contexto inválido é negado. Processos CLI são parte confiável do ambiente de operação, não uma rota web.

**Aceitação transitória de MD5 e migração de senhas somente após login válido.** Não há senha em claro para converter hashes offline, e invalidar todas as contas quebraria os logins existentes. Ampliação da coluna e migração gradual são necessárias. Contas inativas, banco ainda CHAR(32), conexão sem permissão de UPDATE e senhas legadas incompatíveis com bcrypt podem continuar em MD5 e exigem ação operacional. Uma falha SQL na migração opcional é registrada e não invalida um login já verificado.

**Limite de tentativas de login (F12).** Faltam política de tentativas, recuperação e origem de cliente atrás de proxies. Um bloqueio arbitrário poderia negar acesso legítimo; um contador só na sessão seria facilmente contornado. O achado permanece explicitamente pendente.

**Separação completa do index.php, novos frameworks/dependências e mudanças adicionais no schema.** Não eram necessários para corrigir os caminhos concretos e aumentariam o risco de reescrita. Mantive a stack e os relacionamentos/índices; a alteração estrutural necessária é ampliar usuarios.senha. EXPORT_DIR e o fuso America/Sao_Paulo também foram preservados.

## Implantação e riscos remanescentes

Antes de implantar sobre o banco existente, ampliar a coluna sem apagar usuários ou alterar senhas:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

`schema.sql` já contém a definição nova para instalações vazias; ele não é um migrador do banco existente. A biblioteca consulta a capacidade da coluna antes de gravar um hash novo, mantendo login no schema antigo sem truncamento. Após ampliar, contas MD5 migram no próximo login válido. A conta do banco usada para login precisa de UPDATE em usuarios para concluir essa migração; se essa etapa falhar por erro SQL, o login continua e a pendência fica no log. Contas que não migrarem exigem tratamento posterior, especialmente senhas acima de 72 bytes ou com NUL; não alterei silenciosamente essas credenciais.

Fornecer `DB_PASS` no ambiente é obrigatório; ausência agora causa erro controlado no ponto de entrada. `DB_HOST`, `DB_NAME` e `DB_USER` mantêm seus defaults. Configurar `SMTP_API_KEY` no ambiente quando o serviço de notificações externo o utilizar. **Rotacionar os dois segredos anteriormente embutidos** e remover eventuais arquivos `chamados.csv` antigos do diretório público são ações do ambiente de implantação; não foram executadas em um serviço de produção.

O cookie Secure acompanha a informação HTTPS fornecida pelo servidor PHP. Se houver terminação TLS em proxy, esse servidor deve receber a indicação correta; não confiei automaticamente em cabeçalhos de proxy enviados pelo cliente. O pacote não fornece topologia de implantação. F12 permanece sem rate limit e MD5 pode permanecer em contas ainda não migradas. Não há alegação de que essas pendências operacionais já foram eliminadas.

## Validação

Usei **PHP 8.4.26, extensão mysqli/mysqlnd e MariaDB 11.8.6**, com banco descartável e servidor HTTP somente em loopback. A stack declarada usa MySQL 8; o SQL empregado é compatível, mas não executei os testes em um servidor MySQL 8. Nenhum banco de produção foi alterado e nenhuma dependência foi acrescentada à aplicação.

- `php -l` passou nos três arquivos PHP. Schema e seed foram aplicados em banco vazio; os quatro logins e senhas do manifesto continuaram válidos.
- **44 verificações CLI** passaram: retornos de autenticação, senha incorreta, buscas normais e maliciosas, clientes/técnicos, detalhes próprios/alheios/inexistentes, papel inválido, CLI global, média vazia e com precisão de ponto flutuante, CSV vazio e ordenado, técnico ausente, MD5→bcrypt, schema antigo CHAR(32), senha legada longa e rejeição de NUL em bcrypt.
- **30 verificações HTTP** passaram: login e rotação de sessão, flags de cookie, rejeição de sessão escolhida, tabela/colunas/links, visibilidade, detalhes, médias, CSV/headers, XSS e SQL injection, parâmetros em array e falha SQL com HTTP 500 sem stack trace. Incluem **32 exportações simultâneas**, alternando Ana e Bruno, sem mistura de registros.
- A versão original confirmou SQL injection, XSS, acesso de Ana ao chamado de Bruno, ausência de renovação de sessão e DivisionByZeroError sem respostas.
- Comparei por reflexão as sete assinaturas públicas; comparei também a lista completa (inclusive tipos dos campos), o detalhe, o retorno do login e a média no contexto global: iguais ao original. As 54 combinações de prioridade/minutos e nove valores de status deram resultados idênticos.
- Contadores `Com_select` mostraram uma consulta de leitura para listar e uma para exportar, eliminando N+1. Não fiz benchmark de latência ou teste de carga de produção.
- O CSV preservou Unicode, vírgulas, aspas, barras e quebras de linha em um teste de leitura após exportação.
- Configuração sem DB_PASS foi rejeitada; DB_PASS explicitamente vazia foi preservada.

## Entregáveis

`code/` contém os cinco arquivos nos caminhos originais, alterados no lugar. `RELATORIO.md` e `achados.json` usam os mesmos IDs F1–F14, severidades, confidências, mecanismos, impactos e decisões. O JSON contém os metadados exatos da instância e o índice das decisões de não alteração.
