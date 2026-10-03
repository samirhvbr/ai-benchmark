# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas concretas de isolamento entre clientes, SQL injection, XSS e proteção de credenciais, além de problemas na exportação, na estatística e no acesso ao banco. Foram registrados 13 achados: 12 receberam correções no código e um foi somente reportado por risco de alterar valores da integração CSV. A correção de MD5 é gradual; hashes de uma base existente não desaparecem apenas com esta entrega.

Os cinco arquivos de `code/` foram alterados no lugar, sem trocar a stack ou as assinaturas públicas. `TAREFA.md` e `manifest.md` permanecem intactos. As referências de localização abaixo e em `achados.json` usam a **numeração original** dos arquivos recebidos. Achados estão em ordem de prioridade de intervenção.

Instância: **LEB-100-A**, versão **1.1**. Matriz SHA-256: `68088abdb7bc54fa949be972b5cf1f89c2c1c3c9f95b6e472385a6fa084c8625`.

## Achados

### F1 — Ausência de autorização expõe chamados de outros clientes

- **Local original:** `code/index.php:38–74`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** uid e papel são lidos, mas listagem, detalhe e exportação consultam chamados sem restringir usuario_id. Ana consegue listar e exportar 103/104, de Bruno, ou abrir index.php?ver=103. A média também agrega dados alheios.

**Impacto:** Um cliente autenticado obtém títulos, descrições e dados de chamados de outros clientes, contrariando a regra de visibilidade.

**Decisão e correção:** Adicionado escopo comum em listarChamados, verChamado, mediaResposta e exportarCsv: cliente filtra por usuario_id, técnico vê todos e contexto de sessão inválido não retorna linhas. As rotas verificam a sessão; detalhe proibido mantém a resposta de chamado não encontrado. Rotinas internas sem contexto de sessão conservam acesso geral.

### F2 — Busca concatenada permite SQL injection

- **Local original:** `code/lib.php:80–85`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** busca entra diretamente no literal LIKE. O termo ' OR 1=1 -- seguido de espaço fecha a string e muda a condição SQL; UNION pode alcançar outras tabelas acessíveis pela conexão.

**Impacto:** Leitura de dados além do filtro e possibilidade de extração de informações do banco, incluindo hashes se a conexão tiver permissão.

**Decisão e correção:** O padrão LIKE é vinculado como parâmetro de statement mysqli; autorização e busca são condições separadas na mesma consulta. Mantidos os curingas % e _ e a ordenação criado_em DESC.

### F3 — Termo de busca permite XSS em atributo e texto HTML

- **Local original:** `code/index.php:79–82`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** O parâmetro busca é interpolado sem escape no value entre aspas e no parágrafo de resultados. Uma aspa fecha o atributo e <script> ou eventos HTML são interpretados pelo navegador.

**Impacto:** Um link de busca preparado pode executar JavaScript na sessão da vítima e ler dados ou realizar ações no mesmo domínio.

**Decisão e correção:** Criada representação HTML do termo com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8, usada nos dois contextos. Escape explícito também aplicado aos campos exibidos; SQL continua recebendo o termo original.

### F4 — Credenciais de produção são distribuídas no código

- **Local original:** `code/config.php:11–15`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** DB_PASS usa senha de produção literal como fallback e SMTP_API_KEY contém token literal. Quem obtém uma cópia deste pacote recebe ambos os segredos; a exploração depende de alcançar os serviços e de os valores continuarem válidos.

**Impacto:** Comprometimento do banco ou uso indevido do serviço de e-mail caso as credenciais expostas ainda sejam aceitas.

**Decisão e correção:** Removidos os dois segredos do código; DB_PASS e SMTP_API_KEY são lidos do ambiente, sem fallback confidencial. Valores vazios explícitos não são substituídos. Revogação/rotação nos serviços e configuração do ambiente continuam sendo ações operacionais necessárias, não realizadas neste pacote.

### F5 — MD5 sem salt facilita quebra offline de senhas

- **Local original:** `code/lib.php:15–20`.
- **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** A autenticação calcula MD5 diretamente da senha e compara com usuarios.senha CHAR(32) (schema.sql:7); o seed usa o mesmo digest para usuários com senhas iguais. O hash é rápido e não tem salt ou fator de custo.

**Impacto:** Uma cópia da tabela permite testar grandes quantidades de candidatos offline e reconhecer senhas iguais.

**Decisão e correção:** Autenticação passou a reconhecer password_verify e MD5 legado, preservando retorno e credenciais. Schema ampliado para VARCHAR(255), seed usa hashes modernos com salts distintos e login válido pode migrar com UPDATE condicionado ao id e ao hash anterior. Antes de gravar, verifica a capacidade da coluna; CHAR(32) e acesso somente leitura continuam permitindo login sem truncamento. Candidatos bcrypt com NUL ou mais de 72 bytes são rejeitados; senhas MD5 dessas formas permanecem verificadas exatamente e sem migração. A eliminação de hashes antigos depende da ampliação do banco existente e dos logins/migração posterior.

### F6 — Exportações compartilham arquivo e falham sem diretório gravável

- **Local original:** `code/lib.php:125–150`.
- **Categoria:** bug. **Severidade:** alta. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** Todas as requisições abrem EXPORT_DIR/chamados.csv em modo w e depois leem esse mesmo caminho. Uma exportação concorrente pode truncar ou sobrescrever o conteúdo antes do readfile da outra. Se o diretório não existir ou não for gravável, fopen falha e a função retorna sem CSV; a falha da query também abandona o handle.

**Impacto:** Download vazio, parcial ou misturado; se houvesse escopos diferentes, o arquivo compartilhado também poderia entregar dados de outro usuário.

**Decisão e correção:** CSV é emitido diretamente em php://output por requisição, sem arquivo compartilhado ou dependência de EXPORT_DIR. Consulta ocorre antes da saída, usa o escopo autorizado e resultado/stream são liberados em finally. Mantidos Content-Type, nome de download e ordem por id crescente.

### F7 — Login não renova a identificação da sessão

- **Local original:** `code/index.php:15–26`.
- **Categoria:** seguranca. **Severidade:** media. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** session_start aceita a sessão pré-login e a autenticação apenas acrescenta uid/papel ao mesmo identificador. Se um atacante conseguir fixar ou conhecer esse identificador antes do login, ele permanece associado à conta autenticada.

**Impacto:** Se houver fixação/conhecimento prévio da sessão, reutilização do identificador para assumir a sessão autenticada.

**Decisão e correção:** Login válido regenera o identificador e remove a sessão antiga. Ativados modo estrito, uso exclusivo de cookies, HttpOnly e SameSite=Lax; Secure é aplicado quando a requisição é HTTPS, preservando funcionamento em HTTP.

### F8 — Média de resposta divide por zero sem chamados respondidos

- **Local original:** `code/lib.php:109–116`.
- **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** A consulta exclui respostas NULL e qtd começa em zero. Sem linhas ou com todas as respostas NULL, a expressão soma/qtd usa denominador zero, interrompendo a listagem em PHP atual.

**Impacto:** O painel fica indisponível justamente para base vazia ou sem primeiras respostas; após filtrar por cliente, essa situação também ocorre com usuários sem chamados respondidos.

**Decisão e correção:** SUM e COUNT são calculados no banco sob o mesmo escopo; a função retorna 0.0 quando a contagem é zero. A divisão final permanece em PHP para conservar a precisão de 77/3, sem arredondamento DECIMAL introduzido por AVG(INT). Também reduz o tráfego de dados da estatística.

### F9 — Listagem e CSV fazem uma consulta por técnico de cada chamado

- **Local original:** `code/lib.php:85–90`.
- **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** Depois da consulta de chamados, cada linha com tecnico_id chama tecnicoNome, que executa outro SELECT (linha 69). O mesmo padrão está na exportação, linha 137, mesmo quando centenas de chamados compartilham um técnico.

**Impacto:** Número de consultas cresce como 1+N para linhas com técnico, aumentando latência e carga no banco.

**Decisão e correção:** Listagem e exportação obtêm tecnico_nome com LEFT JOIN e COALESCE em uma única consulta cada, preservando chamados sem técnico e o rótulo '-'. tecnicoNome continua disponível, sem alteração de assinatura.

### F10 — Valores do CSV podem ser interpretados como fórmulas

- **Local original:** `code/lib.php:138–144`.
- **Categoria:** seguranca. **Severidade:** media. **Confiança:** 85/100.
- **Situação:** somente reportado.

**Mecanismo:** titulo e nome do técnico são enviados a fputcsv como valores literais. Aspas CSV não impedem que uma planilha trate um campo iniciado por =, +, - ou @ como fórmula. O pacote não contém a origem de escrita desses campos; portanto o controle por um atacante e a execução dependem do fluxo de ingestão e do programa que abre o CSV.

**Impacto:** Quem abrir dados maliciosos em uma planilha pode acionar cálculos ou funções externas, conforme configuração do aplicativo.

**Decisão e correção:** Somente reportado. Prefixar apóstrofo ou tabulação alteraria os valores consumidos pela exportação noturna e faturamento. Recomenda-se um futuro formato explicitamente destinado a planilhas, ou importação com colunas tratadas como texto, mediante contrato próprio; a rota CSV atual preserva os dados.

### F11 — Parâmetros HTTP em formato de array provocam TypeError

- **Local original:** `code/index.php:21–22`.
- **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** PHP permite login[]=ana, senha[]=x e busca[]=x. Esses valores viram arrays e são passados a parâmetros string de autenticar (linha 22) ou listarChamados (linhas 72–73), causando TypeError em vez de uma resposta normal.

**Impacto:** Requisições malformadas encerram o processamento e podem produzir erro HTTP e ruído nos logs; o efeito comprovado é limitado à própria requisição.

**Decisão e correção:** A entrada verifica is_string: login/senha inválidos não autenticam, busca inválida é tratada como vazia e ver inválido como id inexistente. Os nomes de parâmetros e o comportamento para valores escalares válidos permanecem iguais.

### F12 — Erro de conexão lançado como exceção escapa da resposta prevista

- **Local original:** `code/index.php:9–11`.
- **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** new mysqli pode lançar mysqli_sql_exception, como ocorre por padrão no PHP 8.4 disponível. Assim, com conexão recusada ou acesso negado, a checagem posterior de connect_errno não é executada e a mensagem genérica prevista não é usada.

**Impacto:** Falha não tratada na inicialização; quando display_errors está ativo, também pode revelar mensagem do driver e caminhos do código.

**Decisão e correção:** Adicionado catch de mysqli_sql_exception no estabelecimento da conexão, com HTTP 500 e mensagem genérica. Mantida a checagem connect_errno para instalações sem modo de exceção; não houve mudança global de mysqli_report dos consumidores.

### F13 — Cabeçalho CSV não corresponde ao texto exato do manifesto

- **Local original:** `code/lib.php:130`.
- **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100.
- **Situação:** correção implementada.

**Mecanismo:** fputcsv coloca aspas no campo com espaço, gerando ID,Titulo,Status,Tecnico,"Aberto em", enquanto o contrato exige literalmente ID,Titulo,Status,Tecnico,Aberto em.

**Impacto:** Consumidores que comparam a primeira linha textualmente não reconhecem o cabeçalho, apesar de um parser CSV ler os mesmos cinco nomes.

**Decisão e correção:** Cabeçalho fixo é escrito literalmente, com quebra de linha e sem BOM. Linhas de dados continuam serializadas por fputcsv, com delimitador, aspas e escape vazio explícitos, preservando campos complexos.

## Decisões

- **Assinaturas públicas, stack PHP/mysqli e nomes/caminhos dos cinco arquivos:** São o contrato consumido por integrações; a evolução ocorreu nos arquivos existentes, sem novo framework, camada de banco ou dependência.
- **Rótulos de status e prioridade, limiares de SLA e fallback de status desconhecido:** Os valores declarados e os rótulos existentes precisam permanecer iguais; não há regra fornecida que justifique redesenhar a prioridade ou o fallback.
- **Rotas, tabela tabela-chamados, ordem das cinco colunas e links de detalhe:** Há consumidores e favoritos externos; os filtros de autorização corrigem o comportamento pretendido mantendo essa estrutura.
- **Curingas LIKE (% e _), ordenação da listagem e ausência de paginação:** A consulta preparada mantém a semântica de busca e criado_em DESC. Paginação ou busca por outra técnica alterariam a lista pública e exigiriam um novo contrato.
- **Acesso geral das rotinas internas sem contexto de sessão:** As assinaturas não recebem usuário e são usadas por relatórios/exportação noturna. O painel autentica antes de consultar; sessões cliente são filtradas dentro das funções e contextos incompletos são negados.
- **Valores numéricos como strings nas linhas de listagem e detalhe:** O query() original normalmente devolvia strings; as consultas preparadas devolvem inteiros nativos. Os valores retornados foram normalizados para conservar o comportamento padrão, mantendo NULL e as chaves.
- **Aceitação temporária de MD5 e ausência de ALTER TABLE automático:** Invalidar senhas existentes ou modificar estrutura com credenciais da aplicação quebraria produção. A atualização é gradual, detecta CHAR(32), tolera conexão somente leitura e depende de migração operacional do banco.
- **Senhas MD5 com NUL ou mais de 72 bytes não são convertidas para bcrypt:** O bcrypt atual não representa essas senhas integralmente. O login legado exato permanece possível; sua migração exige uma política/algoritmo que preserve o valor completo.
- **Literais de título e técnico no CSV, incluindo possíveis fórmulas (F10):** Acrescentar apóstrofos/tabulações modificaria dados das integrações. O risco foi reportado com confiança condicionada à origem dos dados e ao aplicativo de planilha.
- **Constante EXPORT_DIR e função tecnicoNome:** Embora a exportação deixe de usá-las, removê-las não é necessário para a correção e pode atingir usos externos não enumerados; nenhum arquivo persistente de CSV é prometido pelo manifesto.
- **Ausência de índices adicionais, cache e reorganização de camadas:** O JOIN remove o custo concreto de N+1. A busca contém curinga inicial, que não seria resolvido por um índice B-tree simples; mudanças maiores precisam de carga e plano de execução representativos.
- **Rotação real de credenciais, configuração de proxy/TLS e deploy em produção:** Não há acesso/configuração desses serviços no pacote. O código remove os segredos, mas a invalidação dos valores já divulgados e a implantação continuam sendo ações operacionais.

## Implantação e limites das correções

Configure `DB_PASS` e, se houver envio transacional em outros módulos, `SMTP_API_KEY` no ambiente. O pacote não contém mais os valores antigos. Rotacione os segredos anteriormente publicados nos respectivos serviços; remover os literais não revoga uma credencial.

Para uma instalação existente, não execute `schema.sql` ou `seed.sql` sobre os dados de produção. Primeiro disponibilize a autenticação que aceita ambos os formatos em todos os processos consumidores. Enquanto a coluna for `CHAR(32)`, o código conserva o login legado sem gravar um hash maior. Depois, em janela apropriada à instalação, aplique a migração estrutural:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

A conexão precisa de permissão para atualizar `usuarios.senha` para realizar a migração oportunista. Sem essa permissão, o login válido permanece funcionando e a atualização não ocorre. O `UPDATE` verifica também o hash antigo, evitando sobrescrever uma mudança concorrente de senha. Contas que não fizerem login continuarão com MD5 até uma migração/reset específico. Senhas legadas não representáveis por bcrypt não são truncadas nem convertidas silenciosamente. O esquema/seed novos permitem testar os quatro usuários e senhas do manifesto sem MD5.

A escolha de armazenamento de 255 caracteres e o limite atual de 72 bytes do bcrypt seguem a [documentação oficial de password_hash](https://www.php.net/manual/en/function.password-hash.php). O CSV usa escape vazio explícito para serialização normal de campos e para evitar a dependência do parâmetro padrão deprecado no PHP 8.4, conforme a [documentação oficial de fputcsv](https://www.php.net/manual/en/function.fputcsv.php).

O escopo sem sessão destina-se aos scripts internos já consumidores da biblioteca. Uma eventual nova rota web deverá autenticar antes de chamar as funções, assim como `index.php`; a biblioteca não cria uma identidade para quem a chama. O cookie Secure depende de HTTPS informado pelo servidor; terminação TLS por proxy precisa da configuração correta do servidor, sem confiar automaticamente em um header enviado pelo cliente.

## Validação

Foram aprovadas **184/184 verificações** de integração/regressão em PHP 8.4.26, mysqli/mysqlnd e MariaDB 11.8.6, em banco e servidores locais isolados. Os servidores de teste foram encerrados; nenhum banco preexistente foi alterado. O MySQL 8 citado no schema não foi executado nesta sessão; o teste de banco utilizou MariaDB, e isso limita a evidência de ambiente de produção.

| Grupo | Verificações aprovadas | Evidência principal |
| --- | --- | --- |
| Biblioteca | 102 | Assinaturas/reflexão, chaves e tipos, rótulos/SLA, busca, acesso por cliente/técnico, sessões inválidas, média e autenticação |
| Rotas HTTP | 67 | Login dos quatro usuários, renovação de sessão, estrutura da tabela, listagem/detalhe/CSV autorizados, SQLi, XSS e parâmetros array |
| CSV e performance | 4 | Com 1.006 chamados, um SELECT na listagem e um SELECT no CSV; roundtrip de vírgulas, aspas, barra seguida de aspas, quebra de linha e UTF-8 |
| Esquema original CHAR(32) | 8 | Login MD5, senha incorreta, relogin e ausência de truncamento ou atualização incompatível |
| Conexão somente SELECT | 3 | Login válido mantido mesmo sem permissão de UPDATE; hash legado permanece intacto |

A média dos dados do seed continuou sendo `77/3`, sem o arredondamento adicional de `AVG(INT)`; com zero respostas ou tabela vazia, o retorno é `0.0`. Ana recebe os IDs 101/102/105; Bruno, 103/104; Carla e Diego recebem todos. Chamados sem técnico continuam presentes com `tecnico_nome = '-'`. A exportação tem o cabeçalho literal obrigatório e ordem crescente por ID, com os mesmos rótulos de status.

Senhas MD5 com NUL ou 73 bytes foram verificadas exatamente sem conversão; bcrypt rejeitou NUL e candidatos acima de 72 bytes. Login inválido não atualizou hashes. As rotas HTTP não produziram Warning, Fatal, Deprecated ou Notice. O teste de acesso somente leitura produziu apenas a mensagem genérica esperada da atualização oportunista, sem senha/hash.

Também foram executados `php -l code/config.php`, `php -l code/lib.php` e `php -l code/index.php`, todos sem erro de sintaxe. Os testes ficaram fora de `code/`, para preservar os caminhos do pacote. Scripts e resultados desta sessão estão em `/tmp/leb-validacao.fFtFRJ/` (`test-lib.php`, `test-http.py`, `test-csv-perf.php`, `test-auth-original.php`, `test-auth-readonly.php` e `results-*.json`).

A revisão final verifica JSON válido, unicidade dos IDs, correspondência entre os 13 blocos do relatório e o índice, campos/enums exigidos e integridade de `TAREFA.md` e `manifest.md`.
