# Relatório técnico — LEB-100-A

Instância **LEB-100-A**, versão **1.1**, padrão LEB spec 1.1.0. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Resumo

O painel conserva as funções e rotas previstas no manifesto, mas o código recebido permitia injeção SQL, XSS e acesso de clientes a chamados alheios. A entrega corrige esses caminhos, fortalece a sessão, retira segredos literais e corrige disponibilidade, serialização e custo de consultas. Foram identificados 14 achados: 12 corrigidos no código e 2 reportados sem correção (MD5 e interpretação de fórmulas no CSV).

Foram alterados somente `code/lib.php`, `code/index.php` e `code/config.php`, nos caminhos originais. `schema.sql`, `seed.sql`, `manifest.md` e `TAREFA.md` foram preservados. Não houve troca de stack ou adição de dependências.

## Achados, em ordem de prioridade

Todas as referências abaixo e em `achados.json` usam a **numeração original** dos arquivos recebidos. A localização da implementação entregue pode ter mudado após as correções.

### F1 — Injeção SQL na busca por título

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** listarChamados concatena busca dentro do literal de LIKE. Uma busca como ' OR 1=1 -- altera a expressão WHERE; UNION pode consultar outras tabelas acessíveis à conexão e projetar dados nos campos exibidos.

**Impacto.** Um usuário autenticado pode modificar a consulta e extrair dados acessíveis ao usuário do banco, incluindo hashes de usuarios. Não é necessário supor suporte a múltiplas instruções SQL.

**Tratamento.** Substituída a interpolação por consulta preparada mysqli com parâmetro para o padrão LIKE. Preservados os curingas % e _, a ordenação e as chaves/tipos padrão dos registros.

### F2 — Consultas ignoram o proprietário do chamado

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** index.php lê uid e papel nas linhas 38–39, mas listarChamados, verChamado e exportarCsv (linha 132) consultam todos os chamados sem condicionar usuario_id. A média também considera chamados alheios.

**Impacto.** Ana consegue listar, abrir por ID e exportar chamados de Bruno e vice-versa, violando a regra explícita de visibilidade; o indicador também usa dados fora desse escopo.

**Tratamento.** Adicionado filtro SQL compartilhado nas quatro consultas: sessão de técnico mantém acesso global; cliente só acessa usuario_id igual ao uid. Identidade inválida nega acesso. Sem identidade de sessão, scripts internos mantêm o acesso global. Detalhe alheio retorna null e a mensagem já existente de chamado não encontrado.

### F3 — XSS refletido no formulário e no resultado da busca

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** busca é concatenada sem escape dentro do atributo value delimitado por aspas duplas e também no corpo de um parágrafo. Aspas ou tags recebidas na URL passam a fazer parte do HTML interpretado pelo navegador.

**Impacto.** Um link preparado pode executar JavaScript na sessão de quem abrir a busca, ler conteúdo e fazer requisições autenticadas na mesma origem.

**Tratamento.** Aplicado htmlspecialchars com ENT_QUOTES | ENT_SUBSTITUTE e UTF-8 ao valor exibido em ambos os contextos; mantido o termo original para a consulta. Tornado explícito o mesmo escape para os textos vindos do banco.

### F4 — Credenciais de banco e SMTP embutidas no código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** DB_PASS tem um fallback literal identificado como senha de produção; SMTP_API_KEY é um segredo literal incondicional. Quem obtém uma cópia do código também recebe essas credenciais.

**Impacto.** Exposição das credenciais a leitores do repositório ou do pacote; se ainda válidas e com acesso ao serviço, permitem autenticação no banco ou uso da API SMTP.

**Tratamento.** Removidos os dois valores literais e mantidos os nomes das constantes. DB_PASS e SMTP_API_KEY vêm do ambiente; a senha do banco distingue variável ausente de string vazia ou '0'. Provisionar o ambiente e rotacionar os segredos expostos é uma ação operacional ainda necessária.

### F5 — Senhas armazenadas com MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Situação:** não corrigido; risco permanece.

**Mecanismo.** autenticar calcula md5(senha) e compara diretamente com usuarios.senha. schema.sql:7 limita o armazenamento a CHAR(32), e seed.sql:5–8 insere hashes MD5. Senhas iguais geram hashes iguais e o cálculo é muito barato.

**Impacto.** Quem obtiver a tabela de usuários consegue testar grandes quantidades de senhas offline com baixo custo e identificar contas que usam a mesma senha.

**Tratamento.** Não alterado nesta entrega. A correção exige ampliar a coluna, adotar password_hash/password_verify e migrar contas existentes com autenticação de transição e rehash após login ou redefinição. Trocar apenas a comparação bloquearia as contas atuais; gravar hash moderno em CHAR(32) falharia ou truncaria o valor.

### F6 — Login conserva o identificador anterior da sessão

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Situação:** corrigido no código entregue.

**Mecanismo.** Após autenticação bem-sucedida, o código grava uid e papel na sessão existente sem regenerar seu identificador. Se um adversário conseguir fazer a vítima usar um identificador conhecido, ele passa a identificar uma sessão autenticada.

**Impacto.** Possível sequestro de sessão por fixação, condicionado à capacidade de impor ou compartilhar um identificador antes do login e à configuração do servidor.

**Tratamento.** Adicionado session_regenerate_id(true) antes de gravar a identidade. Ativados modo estrito, uso exclusivo de cookies, HttpOnly e SameSite=Lax; Secure preserva a configuração existente e é ativado quando PHP identifica HTTPS.

### F7 — Exportações usam o mesmo arquivo e interferem entre si

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** Todas as requisições abrem EXPORT_DIR/chamados.csv em modo w e depois o reabrem com readfile. Entre a escrita e a leitura, outro processo pode truncar ou substituir o arquivo. Se o diretório não existir ou não for gravável, a função retorna sem entregar o CSV.

**Impacto.** Downloads incompletos ou com conteúdo de outra requisição, indisponibilidade em instalações sem o diretório fixo e persistência desnecessária de dados de chamados em disco.

**Tratamento.** Exportação escreve diretamente em php://output após consultar os dados, com fechamento do recurso em finally. Não há caminho compartilhado nem dependência do diretório de exportação para a rota CSV; falhas de consulta/abertura não retornam silenciosamente como sucesso.

### F8 — Média divide por zero quando não há respostas

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** Se chamados estiver vazia ou todos os minutos_resposta forem NULL, o laço não incrementa qtd. A operação soma/qtd executa divisão por zero; no PHP 8 isso lança DivisionByZeroError.

**Impacto.** A listagem deixa de renderizar em um banco novo ou sem chamados respondidos. Com o escopo correto, esse caso também ocorre normalmente para clientes como Bruno no seed.

**Tratamento.** A agregação retorna a contagem dos valores não nulos e a função devolve 0.0 quando a contagem é zero. Respostas com zero minuto continuam contando; a divisão em PHP preserva a precisão do resultado legado nos casos não vazios.

### F9 — Campos CSV podem ser interpretados como fórmulas

**Local original:** `code/lib.php:138–143`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Situação:** não corrigido; risco permanece.

**Mecanismo.** titulo e nome do técnico são passados a fputcsv sem política de neutralização. Se um campo começar por =, +, - ou @, programas de planilha podem interpretá-lo como fórmula, mesmo que a célula esteja entre aspas no CSV.

**Impacto.** Ao abrir o CSV em uma planilha que interprete fórmulas, conteúdo inserido nos dados pode alterar cálculos ou provocar ações/conexões suportadas pela aplicação. O efeito depende do programa e de suas proteções.

**Tratamento.** Não alterado: prefixar apóstrofos ou modificar células alteraria os valores consumidos pelas integrações existentes. Recomendada importação como texto por consumidores de planilhas ou um modo de exportação específico para planilha, definido separadamente com esses consumidores.

### F10 — Uma consulta de técnico por chamado na lista e no CSV

**Local original:** `code/lib.php:88–89`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** Cada iteração da listagem chama tecnicoNome, que faz outro SELECT para cada tecnico_id não nulo. O mesmo ocorre no export nas linhas 136–137, inclusive repetindo consultas quando vários chamados pertencem ao mesmo técnico.

**Impacto.** O número de consultas cresce com a quantidade de chamados atribuídos, aumentando latência e carga no banco; no seed são cinco consultas em cada operação, incluindo a consulta principal.

**Tratamento.** Listagem e exportação usam LEFT JOIN com usuarios e COALESCE para o rótulo '-'. Cada operação passa a usar uma consulta, preservando tecnico_nome, chamados sem técnico e as ordenações originais. tecnicoNome e sua assinatura permanecem disponíveis.

### F11 — Escape CSV padrão perde fidelidade em leitores convencionais

**Local original:** `code/lib.php:138–144`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** fputcsv é chamado sem especificar escape, usando a barra invertida padrão. Em um valor com barra invertida imediatamente antes de aspas, a saída pode conter uma aspa sem a duplicação que leitores CSV convencionais esperam; o valor relido fica diferente.

**Impacto.** Títulos ou nomes com essa sequência podem perder ou deslocar aspas ao importar o CSV em consumidores que usam apenas duplicação de aspas, comprometendo a fidelidade dos dados.

**Tratamento.** Definidos explicitamente vírgula, aspas duplas e escape vazio em fputcsv. Aspas internas são duplicadas e barras permanecem literais, sem mudar os valores das células.

### F12 — Média transfere todas as respostas para calcular dois agregados

**Local original:** `code/lib.php:109–115`. **Categoria:** performance. **Severidade:** baixa. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** mediaResposta seleciona um registro por chamado respondido e percorre todos os resultados em PHP para somar e contar, embora a saída pública seja apenas um float.

**Impacto.** Tráfego banco/aplicação, armazenamento do resultado e processamento em PHP crescem com o histórico em toda abertura da listagem. O banco ainda precisa ler os valores, mas não precisa transferir todos eles.

**Tratamento.** Movidos SUM e COUNT para a consulta SQL, que devolve uma única linha. A divisão final continua em PHP: AVG de uma coluna inteira em MySQL/MariaDB arredonda casas decimais e alteraria o resultado legado de 77/3.

### F13 — Parâmetros HTTP em forma de array causam erros de tipo

**Local original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** PHP converte login[]=x, senha[]=x e busca[]=x em arrays. index.php passa esses valores diretamente a funções que exigem string, nas linhas 22 e 72–73, causando TypeError em vez de uma resposta normal.

**Impacto.** Uma requisição malformada encerra a página com erro; não há evidência de comprometimento de todo o serviço. A conversão cega de ver para int também aceita identificadores malformados.

**Tratamento.** Login só é tentado com campos textuais; busca não textual é tratada como vazia. ver não textual devolve a mensagem existente de chamado não encontrado; para valores textuais, mantida a conversão inteira legada, inclusive zeros à esquerda. Entradas válidas e nomes dos parâmetros permanecem.

### F14 — Cabeçalho CSV não corresponde literalmente ao manifesto

**Local original:** `code/lib.php:130`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Situação:** corrigido no código entregue.

**Mecanismo.** fputcsv coloca aspas no campo Aberto em por conter espaço, emitindo ID,Titulo,Status,Tecnico,"Aberto em". O manifesto exige a primeira linha literal ID,Titulo,Status,Tecnico,Aberto em.

**Impacto.** Um consumidor que compara a linha de cabeçalho como texto rejeita o arquivo, mesmo que um parser CSV reconheça as mesmas cinco colunas.

**Tratamento.** Cabeçalho fixo escrito literalmente, com os cinco nomes exatos e terminador de linha LF; as linhas de dados continuam usando serialização CSV.

## Decisões

- **MD5, coluna usuarios.senha e dados de login do seed (F5).** Migrar com segurança exige DDL no banco instalado, armazenamento ampliado, transição para hashes modernos e verificação do rollout. Esta entrega mantém os logins e relata o risco, sem apresentar a migração como concluída.

- **Valores textuais das células CSV, inclusive possíveis fórmulas (F9).** Prefixar ou remover caracteres muda os dados usados por faturamento e exportação noturna. A interpretação segura em planilhas precisa ser acordada com os consumidores; o CSV atual preserva os dados.

- **Assinaturas mysqli e acesso global de scripts internos sem identidade de sessão.** O manifesto inclui relatórios e rotina noturna que recebem só a conexão mysqli. Adicionar argumentos obrigatórios ou exigir sessão nesses scripts quebraria esse uso. O index.php continua exigindo login antes de consultar dados; sessões com identidade inválida são negadas.

- **Rótulos de status, fallback para status não previsto e regras de prioridade/SLA.** Os três rótulos são contratuais, e não há regra fornecida que autorize alterar o fallback ou os limites e textos de prioridade. A estrutura condicional é pequena e não justifica uma refatoração de negócio.

- **Paginação, semântica dos curingas da busca, esquema e índices do banco.** Limitar a listagem ou mudar % e _ alteraria resultados existentes. Não há medição que justifique novos índices ou mudanças de schema; os joins usam as chaves existentes.

- **Constante EXPORT_DIR e arquivos exportados já existentes em produção.** A exportação entregue dispensa o diretório, mas a constante foi mantida. Não houve acesso ao sistema de arquivos de produção; revisar/remover relatórios antigos depende do ambiente operacional.

- **Rotação efetiva dos segredos externos e configuração de infraestrutura.** Os literais foram removidos do código, mas o pacote não permite revogar credenciais no banco ou no provedor SMTP. Antes de implantar, fornecer DB_PASS/SMTP_API_KEY pelo ambiente e rotacionar os valores anteriormente expostos.

A política de visibilidade é aplicada na própria consulta, inclusive no CSV e no indicador, para que o HTML não seja o único controle. A ausência de identidade de sessão é o contexto dos chamadores internos já existentes; não é uma alternativa de autenticação da rota web. Um cliente nunca recebe detalhes de um chamado alheio para depois filtrá-los em HTML. O técnico continua vendo todos os chamados.

O download conserva `Content-Type: text/csv; charset=utf-8`, o nome `chamados.csv`, as cinco colunas e a ordenação crescente por ID. A listagem conserva `id="tabela-chamados"`, a ordem ID/Titulo/Status/Prioridade/Tecnico e os links `index.php?ver=<id>`. A serialização dos dados corrige aspas sem prefixar títulos ou nomes. Os tipos numéricos padrão da listagem são normalizados para strings, mantendo NULL, como na consulta mysqli original; o retorno de autenticação permanece `['id', 'nome', 'papel']` ou NULL.

## Verificação

Executadas **146 verificações, todas aprovadas**, em banco isolado criado a partir de `code/schema.sql` e `code/seed.sql`, usando PHP **8.4.26** com mysqli/mysqlnd e MariaDB **11.8.6**. Também passaram as verificações de sintaxe dos três arquivos PHP alterados.

- Login dos quatro usuários, retorno público de autenticação, regeneração de sessão e atributos dos cookies; acesso sem login continua restrito ao formulário.
- Ana recebe IDs 101, 102 e 105; Bruno recebe 103 e 104; Carla e Diego recebem todos. Lista, busca, detalhe, CSV e média respeitam esse escopo; detalhe alheio não entrega dados. `ver=00101` mantém a conversão legada. Scripts internos sem identidade conservam acesso global; UID ou papel inválido é negado.
- Consultas preparadas resistem às buscas com aspas/payloads SQL; busca exibida não injeta HTML. Arrays em parâmetros não causam TypeError. Valores e NULL da lista mantêm os tipos padrão da consulta mysqli original.
- Média é float exatamente igual a `77/3` para as respostas do seed, preserva respostas de zero minuto e retorna `0.0` para conjunto vazio ou só NULL. Rótulos de status e prioridade/SLA permanecem.
- Cabeçalho, cinco colunas, ordenação e valores do CSV conferidos; aspas, barras invertidas e Unicode preservam o round-trip. **80 exportações concorrentes**, distribuídas entre os quatro usuários, passaram sem mistura de dados, truncamento ou alteração de formato, com 12 threads de teste e quatro workers PHP.
- Listagem e CSV fazem **um SELECT cada**, sem consultas adicionais por técnico. As execuções HTTP/CLI não apresentaram warnings, depreciações ou erros fatais. O JSON é válido e seus 14 IDs, campos, decisões e linhas originais correspondem ao relatório.

A evidência temporária foi registrada em `/tmp/leb-validacao/resultados.json`; o runner foi `python3 /tmp/leb-validacao/run.py`. Banco e workers temporários foram encerrados ao final. A integração usou **MariaDB, não o MySQL 8 exato** indicado no schema; as consultas usam SQL compatível, mas não houve execução nesse servidor específico. Não foram medidos tempos ou ganhos de latência em produção: a redução de consultas foi verificada diretamente.

## Implantação e limites

Fornecer a senha do banco através de `DB_PASS` antes de implantar: o fallback de produção foi removido. Fornecer `SMTP_API_KEY` quando usada pelos consumidores dessa configuração. Rotacionar as credenciais antigas, pois removê-las do arquivo não invalida cópias anteriores. O valor padrão vazio não é uma nova credencial de produção.

A migração de MD5 e a política de abertura do CSV em planilhas permanecem pendentes, conforme F5 e F9. A proteção Secure do cookie depende de PHP reconhecer HTTPS ou da configuração de cookie já existente; a detecção atrás de proxy deve ser estabelecida na infraestrutura. Não foram executadas alterações no banco ou nos serviços de produção.
