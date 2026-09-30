# Relatório técnico — LEB-100-A

## Resumo

O sistema tinha falhas críticas de isolamento entre clientes e segredos de produção no código, além de injeção SQL, XSS refletido e persistente, hashes de senha inadequados e problemas concretos na exportação CSV. As correções foram feitas de forma incremental, sem mudar as assinaturas públicas, as rotas, os rótulos de status, a estrutura da tabela HTML nem a tecnologia `mysqli`.

As referências de linha abaixo usam a numeração **original** recebida, como exigido pela tarefa. Dos 13 achados, 12 foram corrigidos no código entregue. A limitação de tentativas de login ficou como pendência porque uma defesa efetiva exige estado compartilhado ou controle na borda, ausentes neste pacote.

## Achados

### F1 — Ausência de autorização expunha chamados de outros clientes

- **Onde:** `code/index.php`, linhas originais 44–74; `code/lib.php`, linhas originais 78–102 e 123–150.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** depois do login, a rota de listagem chamava `listarChamados` sem o `uid`, o detalhe aceitava qualquer `ver=<id>` e o CSV sempre consultava todos os registros. Assim, Ana podia listar, abrir diretamente e exportar chamados de Bruno. A média global também agregava dados fora do escopo do cliente.
- **Impacto:** quebra direta da regra de visibilidade e divulgação de título, descrição, estado, prioridade, técnico e datas de chamados alheios.
- **O que foi feito:** foram criadas variantes internas escopadas para listagem, detalhe, média e exportação. Clientes filtram por `usuario_id`; técnicos mantêm acesso total. As funções públicas do manifesto conservaram exatamente suas assinaturas e seu uso irrestrito pelos relatórios internos existentes.

### F2 — Credenciais de produção estavam embutidas no repositório

- **Onde:** `code/config.php`, linhas originais 11–15.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** a senha do banco era um fallback literal e a chave SMTP era sempre uma constante literal. Qualquer cópia do pacote, log de diff ou acesso de leitura ao servidor revelava ambos os segredos.
- **Impacto:** acesso não autorizado ao banco e uso indevido do serviço de e-mail, dependendo de os valores ainda estarem ativos.
- **O que foi feito:** os dois segredos agora são lidos de `DB_PASS` e `SMTP_API_KEY`, sem valor secreto de fallback. Os valores expostos devem ser rotacionados fora do repositório; essa ação operacional não pode ser executada pelo pacote.

### F3 — Busca por título permitia injeção SQL

- **Onde:** `code/lib.php`, linhas originais 80–85.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** `busca` era concatenada entre aspas dentro de `LIKE '%...%'`. Uma entrada contendo aspas e operadores SQL alterava a cláusula `WHERE`, em vez de permanecer um termo de busca.
- **Impacto:** leitura indevida dos chamados e possibilidade de consultas mais caras ou erros controlados pelo atacante; o alcance adicional depende da configuração do driver e dos privilégios do usuário do banco.
- **O que foi feito:** a consulta passou a usar `mysqli::prepare` e `bind_param`; somente os `%` adicionados pela aplicação participam da estrutura da busca.

### F4 — Senhas eram armazenadas como MD5 sem sal

- **Onde:** `code/lib.php`, linhas originais 13–20; `code/schema.sql`, linha original 7; `code/seed.sql`, linhas originais 2–8.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** `md5($senha)` era comparado diretamente a uma coluna `CHAR(32)`. MD5 é rápido e sem sal, permitindo testar grandes dicionários offline caso a tabela seja obtida; usuários com a mesma senha também tinham o mesmo hash.
- **Impacto:** recuperação mais barata das senhas e reutilização das credenciais em outros serviços.
- **O que foi feito:** novas instalações usam `VARCHAR(255)` e hashes de `password_hash`; `autenticar` usa `password_verify`. Hashes MD5 existentes continuam aceitos para não bloquear usuários e são atualizados no login quando a coluna já comporta o hash moderno. Os quatro logins e senhas de teste foram preservados.

### F5 — Dados eram renderizados sem escape e permitiam XSS refletido e persistente

- **Onde:** `code/index.php`, linhas originais 61–66, 79–82 e 91–94.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** `busca` era inserida sem escape tanto no atributo `value` quanto no parágrafo de resultados; além disso, título, descrição e nome do técnico vindos do banco eram concatenados diretamente no HTML. Uma URL preparada podia encerrar o atributo e inserir marcação ativa (XSS refletido), enquanto conteúdo malicioso persistido nesses campos era executado sempre que outra pessoa abria a listagem ou o detalhe (XSS persistente).
- **Impacto:** execução de JavaScript no contexto autenticado do painel, com leitura das informações acessíveis à vítima e possibilidade de realizar ações em nome dela.
- **O que foi feito:** foi centralizado o escape contextual com `htmlspecialchars`, `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8, aplicado ao termo e a todos os campos textuais renderizados.

### F6 — Login não limita tentativas

- **Onde:** `code/index.php`, linhas originais 20–35.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 95/100.
- **Mecanismo:** cada POST com `login` executa uma nova verificação, sem atraso, contador, bloqueio ou limite por origem/conta. Um cliente automatizado pode repetir tentativas indefinidamente.
- **Impacto:** facilita ataques de dicionário e reutilização de credenciais vazadas.
- **O que foi feito:** não corrigido neste pacote. Um contador apenas na sessão seria contornável descartando cookies; uma solução efetiva requer armazenamento compartilhado ou rate limiting no proxy, nenhum dos quais faz parte da stack entregue.

### F7 — Sessão de login podia ser fixada e o cookie não era endurecido

- **Onde:** `code/index.php`, linhas originais 15 e 20–26.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 98/100.
- **Mecanismo:** o mesmo identificador de sessão anterior ao login continuava autenticado depois da senha correta. Além disso, o código não exigia `HttpOnly`, `SameSite` nem `Secure` quando a requisição era HTTPS.
- **Impacto:** quem conseguisse induzir ou obter o identificador pré-login poderia reutilizá-lo depois da autenticação; atributos ausentes ampliavam a exposição do cookie.
- **O que foi feito:** o ID é regenerado com invalidação do anterior após autenticar, e o cookie passou a usar `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS. Sessões com papel ou ID inválido são encerradas.

### F8 — Células controladas por usuário podiam virar fórmulas no CSV

- **Onde:** `code/lib.php`, linhas originais 136–144.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 90/100.
- **Mecanismo:** título e nome de técnico eram gravados literalmente. Ao abrir o arquivo em uma planilha, valores iniciados por `=`, `+`, `-` ou `@` (inclusive após espaços de controle) podem ser interpretados como fórmulas.
- **Impacto:** uma planilha exportada pode executar fórmulas maliciosas, produzir links enganosos ou tentar exfiltrar dados, conforme o aplicativo usado pelo operador.
- **O que foi feito:** campos textuais potencialmente controláveis recebem prefixo de apóstrofo quando apresentam um prefixo de fórmula; o quoting CSV continua a cargo de `fputcsv`.

### F9 — Exportação usava um arquivo global compartilhado entre requisições

- **Onde:** `code/lib.php`, linhas originais 125–150.
- **Categoria:** arquitetura.
- **Severidade:** média.
- **Confiança:** 98/100.
- **Mecanismo:** toda requisição truncava e reescrevia `EXPORT_DIR/chamados.csv`; duas exportações simultâneas podiam intercalar escrita e leitura. Se o diretório não existisse ou não fosse gravável, a função retornava sem CSV.
- **Impacto:** resposta truncada ou misturada, vazamento entre exportações com escopos diferentes e falha dependente de permissão no filesystem.
- **O que foi feito:** o CSV agora é escrito diretamente em `php://output`, sem estado compartilhado nem arquivo temporário. `EXPORT_DIR` foi mantida para não quebrar eventual configuração legada que apenas inclua `config.php`.

### F10 — Média sem respostas causava divisão por zero

- **Onde:** `code/lib.php`, linhas originais 107–116.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** quando nenhuma linha tinha `minutos_resposta`, `$qtd` permanecia zero e a expressão `$soma / $qtd` lançava `DivisionByZeroError` nas versões atuais do PHP.
- **Impacto:** a listagem respondia com erro 500 para bancos novos, filtros sem respostas ou clientes cujos chamados ainda não foram atendidos.
- **O que foi feito:** o banco calcula `AVG`; `NULL` (conjunto vazio) é convertido em `0.0`, preservando o retorno `float`. A versão escopada calcula apenas sobre os chamados visíveis.

### F11 — Listagem e CSV executavam uma consulta adicional por chamado

- **Onde:** `code/lib.php`, linhas originais 85–90 e 132–137.
- **Categoria:** performance.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** apó buscar N chamados, cada laço chamava `tecnicoNome`, produzindo N consultas extras. A exportação repetia o mesmo padrão.
- **Impacto:** latência e carga no banco crescem linearmente com o número de chamados, especialmente na exportação completa.
- **O que foi feito:** listagem e exportação usam um `LEFT JOIN` e `COALESCE`, mantendo a chave `tecnico_nome` e o valor `-` quando não há técnico. `tecnicoNome` foi preservada para compatibilidade com chamadas legadas não declaradas.

### F12 — Cabeçalho CSV não era literalmente o exigido pelo manifesto

- **Onde:** `code/lib.php`, linha original 130.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** `fputcsv` coloca aspas ao redor de campos que contêm espaço. Portanto, a linha produzida era `ID,Titulo,Status,Tecnico,"Aberto em"`, enquanto o contrato exige literalmente `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** consumidores que comparam ou processam o cabeçalho exato podem rejeitar a exportação.
- **O que foi feito:** somente o cabeçalho fixo é escrito como literal; as linhas de dados continuam sendo serializadas com `fputcsv`. A ordem por `id` e os rótulos de status foram preservados.

### F13 — Parâmetros em formato de array derrubavam requisições

- **Onde:** `code/index.php`, linhas originais 22, 53 e 72.
- **Categoria:** qualidade.
- **Severidade:** baixa.
- **Confiança:** 98/100.
- **Mecanismo:** PHP aceita entradas como `busca[]=x` e `login[]=x`; esses arrays eram encaminhados a parâmetros tipados como `string`, causando `TypeError`, ou convertidos de modo ruidoso para inteiro.
- **Impacto:** respostas 500 e poluição de logs por entradas HTTP malformadas, sem comprometimento persistente do serviço.
- **O que foi feito:** login, senha, busca e ID de detalhe agora são validados quanto ao tipo antes de chamar as funções tipadas.

## Decisões e itens deliberadamente não alterados

- Não mudei nomes, tipos ou parâmetros das sete funções públicas, nem as rotas `busca`, `ver` e `export`. As novas funções de escopo são internas e usadas apenas pelo ponto de entrada web.
- Não mudei os rótulos de `formatarStatus`, a ordem/ID da tabela HTML, a ordem do CSV nem as regras de `rotuloPrioridade`, pois fazem parte da compatibilidade declarada ou não há regra suficiente para redefini-las.
- Não removi `tecnicoNome` nem `EXPORT_DIR`, embora o fluxo principal não dependa mais deles. A remoção poderia quebrar scripts legados fora do manifesto sem trazer benefício relevante.
- Não executei DDL automaticamente durante uma requisição. O `schema.sql` foi atualizado para novas instalações; em produção, a alteração equivalente de `usuarios.senha` para `VARCHAR(255)` deve passar pelo processo normal de migração. Até lá, o caminho MD5 legado mantém o login funcional sem tentar gravar um hash truncado.
- Não implementei bloqueio de login local por sessão, pois ele daria uma falsa proteção facilmente contornável. Recomenda-se rate limiting compartilhado no proxy ou uma tabela de tentativas em uma migração futura.
- Não adicionei frameworks, dependências, uma nova camada de dados ou uma reescrita do HTML. O trabalho permaneceu na stack PHP/mysqli existente.

## Validação realizada

- `php -l` passou em `config.php`, `index.php` e `lib.php`.
- Um MariaDB temporário foi criado dentro do diretório da tarefa com `schema.sql` e `seed.sql` e removido depois dos testes.
- Passaram 46 verificações de integração: assinaturas públicas, quatro credenciais declaradas, rejeição de senha incorreta, formato exato do retorno de `autenticar`, migração gradual de MD5, busca contra payload SQL, escopos de Ana/Bruno/técnico, bloqueio e permissão de detalhes, média global/vazia, cabeçalho e ordem do CSV, exportação escopada e neutralização de fórmulas.
