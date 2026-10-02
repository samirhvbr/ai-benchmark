# Relatório técnico — LEB-100-A

## Resumo

O sistema tinha duas exposições críticas: qualquer usuário autenticado conseguia consultar e exportar chamados de outros clientes, e credenciais de produção estavam gravadas no repositório. A busca também combinava injeção SQL com XSS refletido. Foram corrigidos esses caminhos, a autenticação e o armazenamento de senhas, a geração concorrente do CSV, a média sem dados, o padrão N+1 e a validação de estados, sem mudar as assinaturas públicas, as rotas, a ordem das colunas HTML nem os rótulos contratados.

Foram registrados 14 achados: 12 corrigidos no código entregue e 2 mantidos por exigirem uma decisão de arquitetura ou uma mudança de semântica no CSV. As linhas citadas abaixo são as linhas originais recebidas, conforme exigido pela tarefa.

## Achados

### F1 — Regra de visibilidade não era aplicada

- **Onde:** `code/index.php`, linhas 44–45, 52–53 e 72–74.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** depois do login, a rota de listagem chamava `listarChamados` sem identidade, o detalhe aceitava qualquer `id` e a exportação chamava `exportarCsv` para toda a tabela. Assim, um cliente como Ana podia listar, abrir diretamente `?ver=103` e exportar chamados de Bruno. A média global ainda revelava informações agregadas fora de seu escopo.
- **Impacto:** quebra direta de confidencialidade de títulos, descrições, responsáveis, estados e datas de outros clientes; enumeração por ID e extração em massa por CSV.
- **Correção:** a interface web agora usa consultas parametrizadas que recebem internamente usuário e papel. Clientes são filtrados por `usuario_id` na listagem, detalhe, média e CSV; técnicos continuam vendo todos os chamados. O papel é relido do banco a cada requisição autenticada, de modo que remoções ou mudanças de papel surtam efeito sem esperar o fim da sessão. Uma tentativa não autorizada no detalhe recebe a mesma resposta de chamado inexistente. As funções públicas do manifesto conservaram suas assinaturas.

### F2 — Segredos de produção estavam embutidos no código

- **Onde:** `code/config.php`, linhas 8–15.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** a senha do banco tinha um fallback identificado como sendo de produção, e a chave da central SMTP era um literal. Qualquer cópia do código, artefato, backup ou acesso de leitura ao repositório revelava ambos.
- **Impacto:** acesso indevido ao banco e abuso do serviço de e-mail, conforme o alcance real dessas credenciais.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` passam a ser lidos apenas do ambiente; os nomes das constantes foram preservados. A retirada do código impede novas exposições pelo repositório. Os valores anteriormente publicados precisam ser rotacionados fora deste pacote.

### F3 — Injeção SQL na busca por título

- **Onde:** `code/lib.php`, linhas 80–85.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** `listarChamados` concatenava `busca` entre aspas dentro de `LIKE`. Um valor contendo aspas e operadores SQL alterava a cláusula `WHERE`; como a rota passava `$_GET['busca']` diretamente, qualquer usuário autenticado alcançava o ponto vulnerável.
- **Impacto:** desvio do filtro, leitura de dados além do pretendido e possibilidade de consultas `UNION` compatíveis com a projeção.
- **Correção:** busca e filtro por proprietário agora usam placeholders e `bind_param`. O teste com `%' OR 1=1 -- ` não retornou linhas alheias.

### F4 — XSS refletido pelo termo de busca

- **Onde:** `code/index.php`, linhas 79–82.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** o valor de `busca` era escrito sem escape tanto no atributo `value` quanto no parágrafo de resultados. Uma URL preparada podia fechar o atributo ou inserir uma tag executável no navegador de um usuário autenticado.
- **Impacto:** execução de JavaScript no contexto do painel, leitura do conteúdo acessível à vítima e realização de ações com sua sessão.
- **Correção:** toda saída variável em HTML passa por escape UTF-8 com `ENT_QUOTES | ENT_SUBSTITUTE`; IDs são convertidos explicitamente para inteiro. Títulos, descrições e nomes também usam o mesmo tratamento.

### F5 — Senhas eram armazenadas com MD5 sem sal

- **Onde:** `code/lib.php`, linhas 15–20; relacionado a `code/schema.sql`, linha 7, e `code/seed.sql`, linhas 2–8.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** a autenticação calculava `md5($senha)` e o comparava diretamente com uma coluna `CHAR(32)`. MD5 é rápido e sem sal, permitindo testar grandes dicionários offline contra um vazamento da tabela; usuários com a mesma senha tinham o mesmo hash.
- **Impacto:** recuperação prática de senhas fracas e reutilização dessas credenciais em outros sistemas.
- **Correção:** o schema usa `VARCHAR(255)`, o seed usa `password_hash` e a autenticação usa `password_verify`. Hashes MD5 existentes continuam válidos e são atualizados de forma oportunista após um login correto, preservando a base legada. Também há um hash fictício para aproximar o custo temporal de logins existentes e inexistentes. Em produção, a alteração da coluna deve ser aplicada antes da implantação para permitir a gravação do hash novo.

### F6 — Exportação usava um arquivo global previsível

- **Onde:** `code/lib.php`, linhas 125–150.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 95/100.
- **Mecanismo:** todas as requisições escreviam em `EXPORT_DIR/chamados.csv` e depois liam o mesmo caminho. Duas exportações simultâneas podiam truncar ou sobrescrever o arquivo uma da outra; em um diretório manipulável, um link simbólico com esse nome também desviaria a escrita.
- **Impacto:** um usuário podia receber conteúdo parcial ou pertencente a outra exportação, além do risco de sobrescrita no servidor conforme as permissões do diretório.
- **Correção:** o CSV é escrito diretamente em `php://output`, sem artefato compartilhado em disco. A consulta usa `JOIN`, mantém a ordenação por `id` e aplica visibilidade antes de escrever qualquer linha.

### F7 — Sessão não era renovada após autenticação

- **Onde:** `code/index.php`, linhas 23–27.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 95/100.
- **Mecanismo:** o código promovia a sessão existente a autenticada sem trocar seu identificador. Se um atacante conseguisse fixar ou conhecer esse ID antes do login, o mesmo ID continuaria válido depois que a vítima entrasse.
- **Impacto:** sequestro da sessão autenticada e acesso aos chamados permitidos à vítima.
- **Correção:** o login chama `session_regenerate_id(true)` antes de gravar a identidade. Os cookies ganharam `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição é HTTPS; respostas autenticadas recebem `Cache-Control: no-store`.

### F8 — Login não tem limitação compartilhada de tentativas

- **Onde:** `code/index.php`, linhas 20–29.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 70/100.
- **Mecanismo:** cada POST de login dispara uma verificação e não há contador, atraso progressivo ou bloqueio no código fornecido. Um controle externo pode existir, mas não aparece no pacote.
- **Impacto:** tentativas automatizadas de adivinhação de senha e preenchimento de credenciais, especialmente contra senhas antigas ou reutilizadas.
- **Correção:** não corrigido neste pacote. Um contador apenas na sessão seria contornável criando novos cookies. A correção confiável exige limite compartilhado por conta e origem no proxy ou em armazenamento comum, com política de bloqueio definida pela operação.

### F9 — Valores do CSV podem ser interpretados como fórmulas

- **Onde:** `code/lib.php`, linhas 138–144.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 75/100.
- **Mecanismo:** título e nome do técnico são exportados literalmente. Se outra parte do sistema permitir que um desses valores comece por `=`, `+`, `-` ou `@`, planilhas podem tratá-lo como fórmula ao abrir o arquivo. O pacote não contém a rota que cria chamados, por isso a controlabilidade do título não pode ser confirmada aqui.
- **Impacto:** fórmulas maliciosas podem induzir requisições externas, manipular a planilha ou explorar integrações do aplicativo usado para abri-la.
- **Correção:** não corrigido para não alterar silenciosamente os valores consumidos pelas rotinas externas declaradas no manifesto. A mitigação deve ser acordada como regra do formato — por exemplo, uma exportação própria para planilhas que prefixe células perigosas — ou aplicada pelo consumidor que abre o CSV.

### F10 — Cabeçalho CSV divergia do valor exato contratado

- **Onde:** `code/lib.php`, linha 130.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** `fputcsv` coloca aspas em campos que contêm espaço. Portanto, a quinta célula era emitida como `"Aberto em"`, enquanto o manifesto exige literalmente `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** consumidores que validam o cabeçalho textual exato podem recusar a exportação.
- **Correção:** o cabeçalho fixo é escrito literalmente; as linhas de dados continuam usando `fputcsv`, com escape proprietário vazio para produzir CSV interoperável.

### F11 — Média sem respostas causava divisão por zero

- **Onde:** `code/lib.php`, linhas 109–116.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** quando nenhum chamado possuía `minutos_resposta`, `$qtd` permanecia zero e a função executava `$soma / $qtd`, que nas versões atuais do PHP lança `DivisionByZeroError`.
- **Impacto:** a página de listagem falhava para uma base nova ou para um cliente que ainda não tivesse respostas.
- **Correção:** a média é calculada por `AVG` no banco e retorna `0.0` quando o resultado é `NULL`. A versão com visibilidade usa o mesmo comportamento.

### F12 — Listagem e CSV executavam consultas N+1

- **Onde:** `code/lib.php`, linhas 88–89 e 136–137.
- **Categoria:** performance.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** para cada chamado, `tecnicoNome` fazia uma consulta adicional. Listar ou exportar N chamados executava uma consulta principal mais N consultas de usuário.
- **Impacto:** latência e carga no banco cresciam linearmente com o volume, de forma especialmente cara na exportação completa.
- **Correção:** listagem e exportação fazem um único `LEFT JOIN` com `usuarios` e preservam a chave `tecnico_nome`, inclusive o valor `-` para chamado sem técnico. A função `tecnicoNome` foi mantida para compatibilidade interna eventual e passou a usar uma consulta preparada.

### F13 — Estados inválidos eram aceitos e exibidos como resolvidos

- **Onde:** `code/lib.php`, linhas 28–34; relacionado a `code/schema.sql`, linhas 18–20.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 95/100.
- **Mecanismo:** qualquer status diferente de 1 ou 2 caía no ramo `else` e recebia o rótulo `Resolvido`; o banco não restringia status, prioridade nem minutos negativos. Um valor corrompido como status 9 apareceria como resolução legítima.
- **Impacto:** relatórios e operação podem considerar encerrado um chamado cujo estado é inválido, mascarando corrupção de dados e afetando indicadores.
- **Correção:** os três valores contratados continuam exatamente iguais e valores fora do domínio recebem `Desconhecido`. O schema ganhou `CHECK` para status 1–3, prioridade 1–4 e minutos nulos ou não negativos.

### F14 — Parâmetros estruturados podiam derrubar a requisição

- **Onde:** `code/index.php`, linhas 22, 53 e 72.
- **Categoria:** qualidade.
- **Severidade:** baixa.
- **Confiança:** 100/100.
- **Mecanismo:** parâmetros como `login[]=x`, `busca[]=x` ou `ver[]=1` produziam arrays, mas eram enviados a funções tipadas como `string` ou convertidos para inteiro. Isso gerava `TypeError` ou avisos em vez de uma resposta controlada.
- **Impacto:** qualquer cliente podia provocar erros HTTP e poluir logs com requisições malformadas.
- **Correção:** entradas são aceitas apenas quando escalares do tipo esperado; o ID também passa por validação inteira positiva. Valores inválidos resultam em busca vazia, login recusado ou chamado não encontrado.

## Decisões

- As sete assinaturas públicas, seus tipos e o valor padrão de `listarChamados` foram preservados. `listarChamados` e `exportarCsv` continuam representando a visão completa usada por rotinas internas; a interface web ganhou funções auxiliares com identidade explícita para cumprir a regra de visibilidade. Alterar as funções públicas para depender de `$_SESSION` criaria comportamento implícito e quebraria consumidores CLI.
- `rotuloPrioridade` não foi simplificada nem teve seus textos alterados. O manifesto não define esses rótulos, e mudar a classificação de prioridade 4 dentro do SLA poderia alterar relatórios existentes sem uma regra de negócio que justificasse a mudança.
- O risco de fórmula em CSV (F9) ficou documentado, pois prefixar dados mudaria o conteúdo observado pelas integrações. A limitação de tentativas (F8) também ficou pendente porque precisa de estado compartilhado e política operacional; uma trava por sessão daria apenas aparência de proteção.
- `EXPORT_DIR` e a constante `SMTP_API_KEY` foram mantidos, embora não sejam usados pelo fluxo fornecido. Outros arquivos legados podem depender desses nomes; somente o valor secreto saiu do código.
- A implantação deve fornecer `DB_PASS` e `SMTP_API_KEY`, rotacionar os valores que já estiveram no repositório e aplicar a mudança de `usuarios.senha` para `VARCHAR(255)` antes de contar com a migração oportunista. As restrições `CHECK` devem ser aplicadas depois de verificar e corrigir dados históricos fora dos domínios válidos.
- Não foram adicionadas dependências, arquivos de aplicação ou uma nova camada de dados. A solução permanece em PHP, `mysqli` e MySQL/MariaDB.

## Verificação

- `php -l` passou em todos os arquivos PHP.
- Teste por reflexão confirmou nomes, tipos, retornos e valor padrão das sete funções públicas; os rótulos 1, 2 e 3 permaneceram idênticos ao manifesto.
- Uma instância temporária de MariaDB carregou `schema.sql` e `seed.sql`. Os hashes das quatro credenciais do manifesto foram verificados; os logins de Ana e Bruno foram exercitados pela função pública, e um registro MD5 legado autenticou e foi atualizado para `password_hash`.
- Testes integrados confirmaram os conjuntos de IDs visíveis para Ana (`105, 102, 101`), Bruno (`104, 103`) e técnicos (todos), inclusive no detalhe e no CSV; confirmaram também média vazia igual a `0.0`, bloqueio de status inválido e neutralização da injeção de busca.
- O CSV foi comparado byte a byte quanto ao cabeçalho e verificado quanto à ordem crescente de IDs. A tabela HTML mantém `id="tabela-chamados"` e as colunas `ID, Titulo, Status, Prioridade, Tecnico` na ordem contratada.
