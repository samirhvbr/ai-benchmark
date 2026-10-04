# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas demonstráveis de isolamento entre clientes, injeção de SQL, XSS, armazenamento de credenciais e disponibilidade. Foram feitas alterações pontuais nos cinco arquivos existentes de `code/`, preservando PHP/mysqli e a superfície pública declarada. Foram identificados 13 achados: 12 com correção no código entregue e 1 reportado sem mudança de conteúdo por compatibilidade. A correção de MD5 é uma migração gradual; remover segredos do código não revoga credenciais já expostas.

Todas as referências de linha dos achados abaixo usam a **numeração original recebida**, anterior às alterações. `achados.json` contém os mesmos identificadores, prioridades e decisões. As severidades consideram o mecanismo demonstrado e as condições de exploração, sem presumir configurações de produção ausentes do pacote.

## Achados, por prioridade de tratamento

### F1 — Ausência de autorização por proprietário nas leituras de chamados

**Local original:** `code/lib.php:78–101`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** listarChamados seleciona todos os registros e verChamado consulta apenas pelo id. index.php lê uid/papel nas linhas 38–39, mas não usa esses valores para autorizar. A mesma ausência ocorre na consulta do CSV (lib.php:132) e na média (109): uma sessão de Ana pode listar/exportar chamados de Bruno ou solicitar ?ver=103.

**Impacto:** Qualquer cliente autenticado lê títulos, descrições, responsáveis e indicadores de outros clientes, contrariando a regra de visibilidade.

**Correção ou decisão:** Centralizei a condição de visibilidade em lib.php e apliquei-a no SQL de lista, detalhe, CSV e média. Cliente recebe somente usuario_id da sessão; técnico recebe todos; contexto parcial ou inválido recebe nenhum. index.php exige sessão válida. Chamadas internas sem identidade de sessão preservam o acesso global anterior, independentemente de SAPI, para compatibilidade com os consumidores do manifesto.

### F2 — Busca permite injeção de SQL

**Local original:** `code/lib.php:80–85`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** A busca é concatenada entre aspas em titulo LIKE. O termo ' OR 1=1 -- seguido de espaço encerra o literal e comenta o restante do filtro; UNION pode acrescentar dados de outras tabelas acessíveis à conta de banco. O parâmetro chega diretamente de GET em index.php:72–73.

**Impacto:** Um usuário autenticado pode alterar o resultado da consulta e extrair dados além da busca autorizada, inclusive hashes de usuários se a conta do banco puder lê-los. Não é necessário supor suporte a múltiplas instruções SQL.

**Correção ou decisão:** Substituí a concatenação por mysqli::prepare e bind_param; a condição de proprietário permanece independente do termo. Mantive LIKE com os curingas % e _ aceitos pelo comportamento anterior.

### F3 — Busca refletida no HTML permite XSS

**Local original:** `code/index.php:79–82`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** O texto de busca é colocado sem escape tanto no atributo value delimitado por aspas quanto no corpo do parágrafo. Uma URL com busca contendo "><img src=x onerror=alert(1)> cria HTML executável quando um usuário autenticado abre a página.

**Impacto:** Execução de JavaScript na origem do painel, permitindo ler dados e realizar ações com a sessão da vítima.

**Correção ou decisão:** Escapei os dois pontos com htmlspecialchars usando ENT_QUOTES | ENT_SUBSTITUTE e UTF-8. Tornei explícitos os mesmos parâmetros nos escapes já existentes de título, descrição e técnico.

### F4 — Credenciais de produção incorporadas ao código

**Local original:** `code/config.php:11–15`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** A senha de banco aparece como fallback de DB_PASS e a chave SMTP é um literal incondicional. Qualquer acesso ao pacote, histórico ou cópia de implantação revela esses valores; a ausência da variável de ambiente ainda faz o sistema reutilizar a senha exposta.

**Impacto:** Uso indevido do banco ou do serviço de e-mail se as credenciais continuarem válidas e os serviços estiverem acessíveis.

**Correção ou decisão:** Removi os literais e carreguei DB_PASS e SMTP_API_KEY exclusivamente do ambiente, preservando as constantes e o valor válido "0". A exposição foi removida do código entregue; revogação/rotação das credenciais anteriores precisa ser realizada na implantação e não foi executada nesta tarefa.

### F5 — Armazenamento de senhas com MD5 sem salt

**Local original:** `code/lib.php:15–19`. **Categoria:** seguranca. **Severidade:** alta. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** autenticar transforma a senha diretamente em MD5 e compara esse digest no banco. schema.sql:7 limita a coluna a CHAR(32), e seed.sql:5–8 grava MD5. Esses hashes rápidos e determinísticos permitem testar grandes quantidades de senhas offline após vazamento.

**Impacto:** Recuperação de senhas fracas de clientes e técnicos e comprometimento das contas correspondentes.

**Correção ou decisão:** Adotei password_hash/password_verify, ampliei a coluna para VARCHAR(255) e forneci hashes modernos para as mesmas credenciais de teste. Hashes MD5 existentes continuam sendo verificados com hash_equals e são substituídos após login válido; o UPDATE compara também o hash anterior para não sobrescrever troca concorrente. A migração SQL prévia de produção está descrita abaixo. Senhas legadas com NUL ou mais de 72 bytes não são convertidas automaticamente para bcrypt, para evitar truncamento ou erro; precisam de troca de senha. Candidatos incompatíveis com bcrypt são recusados para hashes modernos desse algoritmo. Contas legadas ainda não migradas continuam expostas ao risco de MD5.

### F6 — Exportações concorrentes compartilham um arquivo destrutivo

**Local original:** `code/lib.php:125–150`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** Cada exportação abre EXPORT_DIR/chamados.csv com modo w, grava, fecha e depois relê esse mesmo caminho. Uma segunda requisição pode truncar ou substituir o arquivo antes/durante a leitura da primeira. O retorno após falha de query também não fecha o descritor aberto; a exportação depende de um diretório gravável.

**Impacto:** Downloads vazios, truncados ou com conteúdo de outra execução. Com exportação restrita por cliente, manter esse arquivo compartilhado também permitiria misturar conjuntos de usuários. A eventual exposição direta via HTTP depende da configuração do servidor e não foi presumida.

**Correção ou decisão:** Passei a escrever em php://output, sem arquivo compartilhado nem dependência de EXPORT_DIR. A consulta sem buffer e o JOIN permitem envio progressivo; resultado e stream são liberados em finally.

### F7 — Identificador de sessão não é renovado após autenticação

**Local original:** `code/index.php:15–26`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 95/100. **Corrigido no código:** sim.

**Mecanismo:** session_start aceita/cria a sessão anônima e o login apenas acrescenta uid e papel ao mesmo identificador. Se um atacante conseguir fixar ou conhecer esse identificador antes do login, poderá reutilizá-lo após a vítima se autenticar. A exploração depende dessa condição prévia.

**Impacto:** Sequestro de sessão autenticada por fixação do identificador.

**Correção ou decisão:** Adicionei session_regenerate_id(true) no login bem-sucedido, modo estrito e uso exclusivo de cookies. Configurei HttpOnly, SameSite=Lax e Secure quando o servidor informa HTTPS; sessões com identidade/papel inválidos voltam ao login.

### F8 — Média sem amostras provoca divisão por zero

**Local original:** `code/lib.php:109–116`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** Quando não existe chamado com minutos_resposta preenchido, o laço não roda e qtd permanece zero; o retorno calcula 0/0. Isso acontece em banco vazio e, após aplicar corretamente a visibilidade, já acontece para Bruno com os dados fornecidos.

**Impacto:** Falha ao abrir o painel; em PHP 8 o cálculo lança DivisionByZeroError.

**Correção ou decisão:** A média agora usa a contagem de respostas não nulas e retorna float 0.0 quando ela é zero. Havendo amostras, mantém a divisão em PHP e a precisão anterior.

### F9 — Serialização CSV não respeita integralmente o formato declarado

**Local original:** `code/lib.php:130–144`. **Categoria:** bug. **Severidade:** media. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** fputcsv coloca aspas em "Aberto em", portanto o cabeçalho produzido não é o literal exigido. Além disso, o escape padrão por barra invertida pode impedir o roundtrip de títulos contendo barra antes de aspas ou no final de um campo delimitado, juntando colunas ou linhas ao reler o CSV.

**Impacto:** Falha de consumidores que validam o cabeçalho literalmente e alteração da quantidade ou do conteúdo das células em casos com caracteres especiais.

**Correção ou decisão:** Escrevo o cabeçalho exato ID,Titulo,Status,Tecnico,Aberto em, seguido de newline, e uso fputcsv com vírgula, aspas duplas e escape vazio nas linhas de dados. Permanecem as cinco colunas, rótulos de status, nome de download e ordenação crescente por id.

### F10 — Conteúdo exportado pode ser interpretado como fórmula em planilhas

**Local original:** `code/lib.php:138–144`. **Categoria:** seguranca. **Severidade:** media. **Confiança:** 90/100. **Corrigido no código:** não.

**Mecanismo:** Título e nome do técnico são passados diretamente ao CSV. As aspas de CSV protegem separadores, mas não fazem uma planilha tratar uma célula iniciada por =, +, - ou @ como texto. Por exemplo, um título =1+1 permanece uma fórmula ao ser interpretado por aplicações que habilitam esse comportamento.

**Impacto:** Se alguém conseguir inserir texto malicioso nesses campos e o destinatário abrir o CSV em uma planilha que avalia fórmulas, pode haver execução de fórmula, links ou requisições externas conforme os recursos e controles dessa aplicação. O código fornecido não inclui a rota de gravação desses campos.

**Correção ou decisão:** Reportado, sem alterar células: adicionar apóstrofos mudaria os títulos/nomes recebidos pelas integrações de faturamento e exportação. Para consumo humano, importar as colunas como texto; uma exportação específica para planilhas, com contrato próprio, deve ser projetada separadamente.

### F11 — Listagem e exportação fazem consultas N+1 de técnicos

**Local original:** `code/lib.php:88–89`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** O laço de listagem chama tecnicoNome, que consulta usuarios para cada chamado com tecnico_id. O mesmo ocorre no export em 136–137. Mesmo quando vários chamados têm o mesmo técnico, cada linha gera nova ida ao banco: com N técnicos não nulos são 1+N SELECTs.

**Impacto:** Latência e carga de banco crescem com o número de chamados, inclusive durante downloads grandes.

**Correção ou decisão:** Usei LEFT JOIN de usuarios nas consultas de lista e CSV, com COALESCE para manter tecnico_nome como "-" quando ausente. Cada operação executa um único SELECT. Mantive tecnicoNome disponível para consumidores existentes.

### F12 — Indicador de média transfere todas as amostras para PHP

**Local original:** `code/lib.php:109–115`. **Categoria:** performance. **Severidade:** media. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** A consulta retorna uma linha por chamado respondido e o resultado é armazenado pelo mysqli e percorrido em PHP apenas para somar e contar. O tráfego e o resultado em memória crescem linearmente com a quantidade de respostas.

**Impacto:** Maior consumo de memória, tráfego entre aplicação e banco e tempo de renderização a cada abertura do painel.

**Correção ou decisão:** Agreguei SUM e COUNT no banco, retornando uma única linha, já filtrada pela visibilidade. Mantive a divisão em PHP: AVG de coluna inteira pode arredondar a escala decimal e alterar o valor público da média.

### F13 — Parâmetros HTTP em formato de array causam falhas de tipo

**Local original:** `code/index.php:21–22`. **Categoria:** bug. **Severidade:** baixa. **Confiança:** 100/100. **Corrigido no código:** sim.

**Mecanismo:** PHP interpreta login[]=x, senha[]=x e busca[]=x como arrays. O login e a busca os repassam para funções com parâmetros string (também em 72–73), causando TypeError. O detalhe em 52–53 converte ver[]=x para inteiro, podendo selecionar um id que não foi solicitado como número.

**Impacto:** Respostas de erro do servidor para requisições malformadas e interpretação incorreta do identificador de detalhe.

**Correção ou decisão:** Validei strings e identificador inteiro antes das chamadas, retornando HTTP 400 para entradas inválidas. Preservei ids numéricos com zeros à esquerda e as rotas/parâmetros públicos.

## Implantação e migração de dados

Para uma instalação nova, carregar `code/schema.sql` e depois `code/seed.sql`. Os quatro logins de teste continuam sendo `ana`/`senha123`, `bruno`/`senha123`, `carla`/`tecmaster` e `diego`/`tecmaster`. O seed é exclusivo de testes.

Para uma instalação existente, ampliar a coluna **antes de publicar a nova biblioteca**, sem recarregar o schema/seed sobre dados reais:

```sql
ALTER TABLE usuarios MODIFY COLUMN senha VARCHAR(255) NOT NULL;
```

Esse comando preserva os MD5 já armazenados e permite gravar hashes modernos. O código não executa DDL automaticamente. Sem a alteração prévia, a tentativa de atualizar um hash após login pode falhar ou truncar o valor, conforme o modo SQL. Após a migração, cada login válido compatível com bcrypt atualiza somente sua conta. Contas sem login e os casos excepcionais descritos em F5 precisam de troca de senha administrada; não há fluxo de troca de senha neste pacote.

Provisionar `DB_PASS` e `SMTP_API_KEY` no ambiente e revogar os valores anteriormente embutidos. Os defaults não secretos de host, banco e usuário foram preservados. Quando há terminação TLS em proxy, o servidor de aplicação deve informar corretamente HTTPS ou manter a configuração de cookie Secure na implantação; o código não confia arbitrariamente em cabeçalhos encaminhados pelo cliente. Essas operações de produção não foram executadas.

## Verificação

Foram executadas **300 verificações, todas aprovadas**, com PHP 8.4.26 e mysqli real contra uma instância isolada de MariaDB 11.8.6. O banco e o servidor HTTP temporários não usaram serviços de produção. Os arquivos PHP também passaram por `php -l`.

| Grupo | Verificações | Evidência principal |
| --- | ---: | --- |
| Biblioteca | 167 | Sete assinaturas por reflexão; credenciais do seed; retornos; rótulos; prioridade/SLA; lista, detalhe, média e CSV por papel; contextos inválidos; consultas parametrizadas; banco vazio; migração MD5 e limites de bcrypt |
| Rotas HTTP e sessão | 114 | Login dos quatro usuários; renovação da sessão e rejeição do id anterior; tabela e colunas; links; busca escapada; visibilidade; CSV sem HTML; HTTP 400 para arrays; detalhe com zeros à esquerda |
| Fixtures de texto e CSV | 12 | Título/descrição com HTML, UTF-8, aspas, vírgula, newline, barra final e barra antes de aspas; conteúdo recuperado pelo leitor CSV; fórmulas preservadas como risco residual |
| Migração de banco legado | 7 | Schema original CHAR(32), alteração para VARCHAR(255), MD5 original, login preservado, atualização do hash, segundo login e rejeição de senha errada |

O seed produz lista de Ana `[105,102,101]`, lista de Bruno `[104,103]` e lista completa para ambos os técnicos. Cada CSV contém os mesmos ids autorizados em ordem crescente. Bruno recebe média `0.0`; Ana e técnicos recebem `77/3`. A contagem de SELECTs foi medida: listagem e exportação fazem uma consulta cada. Foram mantidos o retorno `['id','nome','papel']` de autenticação, `tecnico_nome` na listagem e, na conexão padrão do sistema, os campos numéricos como strings na listagem.

A execução separada da biblioteca original confirmou: Ana via todos os cinco chamados e abria o detalhe 103; a busca `' OR 1=1 -- ` devolvia todos os registros; a listagem fazia cinco SELECTs para o seed; banco sem respostas causava `DivisionByZeroError`; o escape original de CSV alterava um campo contendo barra seguida de aspas ao ser lido por um leitor CSV comum.

Os harnesses e resultados desta sessão estão em `/tmp/leb-tests/`, fora do código da aplicação. Comandos usados após preparar o banco isolado, as fixtures e o servidor HTTP:

```sh
php -d error_reporting=E_ALL -d display_errors=1 /tmp/leb-tests/library.php
python3 /tmp/leb-tests/web_contract.py
python3 /tmp/leb-tests/fixtures.py
php /tmp/leb-tests/migration.php
php /tmp/leb-tests/baseline.php
php /tmp/leb-tests/baseline-csv.php
```

`/tmp/leb-tests/results.json` registra resultados e ambiente. Esses arquivos temporários não são dependências de implantação. A matriz de avaliação indicada em TAREFA.md não foi fornecida nem executada: as 300 verificações são regressões locais construídas a partir do manifesto e dos problemas encontrados.

**Limites:** a integração foi testada em MariaDB, não no MySQL 8 citado no schema. HTTPS/proxy, carga concorrente e volume de produção não foram testados. A eliminação da disputa de arquivo foi verificada pela remoção do caminho compartilhado no código; não se afirma um ensaio de carga. A rotação de segredos e a migração do banco real permanecem etapas de implantação. Fórmulas CSV continuam preservadas conforme F10.

## Decisões — o que não alterei

- **Conteúdo literal das células CSV (F10).** Prefixar caracteres para planilhas alteraria valores consumidos por integrações existentes. O risco condicional de fórmulas foi documentado e permanece pendente.

- **Acesso global das funções de relatório quando não há identidade de sessão.** As assinaturas não recebem usuário e o manifesto declara consumidores internos. O index autentica antes de chamar a biblioteca; outros consumidores continuam responsáveis pela própria autenticação e por fornecer contexto quando atendem usuários. Contextos parciais ou inválidos são negados.

- **Suporte transitório a MD5 e credenciais legadas fora dos limites de bcrypt.** Remover o suporte imediatamente bloquearia contas existentes. Após ampliar a coluna, o login migra credenciais compatíveis. Contas inativas ou com senha acima de 72 bytes/NUL exigem ação operacional de troca; não se presume que tenham sido migradas.

- **Rotação real das credenciais de banco e SMTP.** O pacote permite remover a exposição em fonte, mas não fornece os serviços de produção para revogar valores já vazados. A implantação precisa provisionar novos segredos antes de usar esta versão.

- **Rótulos de status, regras de prioridade e limiar de SLA.** São comportamentos observáveis. Mantive inclusive o fallback de status desconhecido como Resolvido, prioridade 4 em até 30 minutos como Alto - dentro do SLA e resposta nula como Aguardando 1a resposta. O aninhamento de prioridade não justifica uma refatoração sem ganho funcional nesta entrega.

- **Curingas de LIKE, ordenações, ausência de paginação e índices existentes.** Alterar curingas, usar busca de texto diferente ou paginar mudaria os resultados/retornos. Não há volume nem plano de execução de produção para justificar novos índices; resolvi os custos demonstráveis de N+1 e agregação.

- **Stack, assinaturas públicas, nomes de arquivos, constantes legadas e organização geral.** Preservei PHP/mysqli, as sete funções declaradas, tecnicoNome, os caminhos existentes, as rotas e a tabela HTML. EXPORT_DIR continua definido por compatibilidade embora a exportação não o utilize. Não acrescentei framework ou dependência externa.
