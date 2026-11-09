# Relatório técnico — LEB-100-A

## Resumo

O painel preservava as rotas e os formatos públicos, mas não aplicava a regra de visibilidade a clientes, permitia injeção de SQL e XSS e continha credenciais e hashes de senha fracos. Também havia riscos na geração do CSV, falha no cálculo de média sem dados e consultas N+1.

Foram entregues 11 correções e um achado residual documentado (F11). As sete funções públicas do manifesto mantêm nomes, assinaturas e retornos; as rotas, a estrutura da tabela, os rótulos, a ordenação do CSV e seu cabeçalho foram verificados. A camada web agora usa variantes com filtro de visibilidade, enquanto as funções históricas permanecem adequadas às rotinas internas já existentes.

## Achados

### F1 — Cliente podia listar, abrir e exportar chamados de terceiros

- **Onde:** `code/index.php`, linhas originais 44–74; consultas em `code/lib.php`.
- **Mecanismo:** depois do login, `$papel` e `$uid` eram lidos, mas não participavam de nenhuma das três operações. `listarChamados` não filtrava `usuario_id`, `verChamado` buscava apenas o ID recebido e `exportarCsv` sempre percorria toda a tabela. Assim, qualquer cliente autenticado podia escolher `ver=<id>` ou baixar o CSV completo.
- **Impacto:** exposição de títulos, descrições, responsáveis e datas de todos os clientes, em violação direta à regra de negócio.
- **Severidade:** crítica. **Confiança:** 100.
- **Correção:** a listagem, o detalhe, a média e a exportação web passaram a usar `listarChamadosVisiveis`, `verChamadoVisivel`, `mediaRespostaVisivel` e `exportarCsvVisivel`. Cliente recebe filtro obrigatório por `usuario_id`; técnico continua vendo todos. Detalhe de terceiro retorna a mesma mensagem de chamado inexistente.

### F2 — Injeção de SQL na busca por título

- **Onde:** `code/lib.php`, linhas originais 80–85.
- **Mecanismo:** `$busca` era concatenada entre aspas em `WHERE titulo LIKE '%...%'`. Um termo contendo aspas e operadores SQL alterava a consulta, em vez de ser tratado somente como valor.
- **Impacto:** contorno do filtro, leitura não autorizada e, dependendo dos privilégios da conta e da configuração do mysqli, outras operações sobre o banco.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** a consulta comum usa `prepare`/`bind_param`; termo e filtro de proprietário são parâmetros. O caractere `%` continua sendo adicionado ao valor para preservar a busca por substring.

### F3 — Senha de produção e chave SMTP embutidas no código

- **Onde:** `code/config.php`, linhas originais 11–15.
- **Mecanismo:** a ausência de `DB_PASS` ativava uma senha real de produção versionada, e `SMTP_API_KEY` era sempre uma constante literal. Qualquer pessoa com acesso ao pacote ou ao repositório obtinha as credenciais.
- **Impacto:** acesso ao banco e uso indevido da central de e-mail; a exposição persiste enquanto as credenciais antigas não forem revogadas.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** os dois valores passaram a vir de `DB_PASS` e `SMTP_API_KEY`, sem segredos de fallback. As constantes públicas continuam existindo. É necessário configurar as variáveis no deploy e **rotacionar** as credenciais antigas, pois removê-las do arquivo não desfaz exposições anteriores.

### F4 — Autenticação baseada em MD5 sem sal

- **Onde:** `code/lib.php`, linhas originais 15–20; também `code/schema.sql`, linha 7, e `code/seed.sql`, linhas 2–8.
- **Mecanismo:** a autenticação comparava `md5($senha)` com um `CHAR(32)`. MD5 é rápido e não possui sal por senha; após vazamento da tabela, senhas iguais têm hashes iguais e tentativas offline podem ser feitas em grande volume.
- **Impacto:** recuperação de senhas e comprometimento de contas, inclusive reutilização das credenciais em outros serviços.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** `password_hash`/`password_verify` passaram a ser usados, com coluna `VARCHAR(255)` e hashes novos no seed. Para não interromper a base existente, hashes MD5 de 32 caracteres ainda são verificados com `hash_equals` e substituídos automaticamente após login válido; hashes atuais também são atualizados quando o algoritmo padrão mudar. Em produção, aplicar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` junto com este deploy.

### F5 — XSS refletido pelo parâmetro `busca`

- **Onde:** `code/index.php`, linhas originais 79–83.
- **Mecanismo:** o valor GET era inserido sem escape tanto em um atributo `value` quanto no parágrafo de resultados. Marcação fornecida na URL era, portanto, devolvida como HTML executável.
- **Impacto:** execução de JavaScript no contexto autenticado do painel, leitura de dados visíveis e ações em nome da vítima.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** uma única representação escapada com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` alimenta os dois contextos; o valor original continua sendo usado na consulta.

### F6 — CSV usava arquivo temporário fixo e compartilhado

- **Onde:** `code/lib.php`, linhas originais 125–150.
- **Mecanismo:** toda requisição abria `/var/www/painel/tmp/chamados.csv` com `w`, gravava e depois relia o mesmo caminho. Exportações simultâneas podiam truncar ou misturar conteúdo; se um usuário local pudesse criar um link simbólico nesse caminho, a escrita podia atingir outro arquivo acessível ao processo web. Uma falha após abrir o arquivo também deixava o descritor sem fechamento no caminho de retorno.
- **Impacto:** dados incorretos no download, corrida entre usuários e possível sobrescrita de arquivos.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** o arquivo intermediário foi eliminado. Após obter o resultado do banco, o CSV é transmitido diretamente para `php://output`, mantendo os mesmos cabeçalhos HTTP.

### F7 — Sessão autenticada conservava o identificador anterior ao login

- **Onde:** `code/index.php`, linhas originais 15–26.
- **Mecanismo:** o login gravava `uid` e `papel` na sessão existente, sem `session_regenerate_id`. Se um identificador anônimo fosse fixado ou obtido, ele continuava válido depois da autenticação. Os cookies também não declaravam `HttpOnly` nem `SameSite`.
- **Impacto:** sequestro de sessão autenticada em um cenário de fixação e maior exposição do cookie a scripts ou requisições entre sites.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** o identificador é regenerado com exclusão do anterior antes de gravar a identidade. O cookie passa a usar `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição é HTTPS.

### F8 — Células do CSV permitiam injeção de fórmula

- **Onde:** `code/lib.php`, linhas originais 138–145.
- **Mecanismo:** título e nome de técnico eram enviados diretamente ao `fputcsv`. Escapar vírgulas e aspas não impede que planilhas interpretem valores iniciados por `=`, `+`, `-` ou `@` como fórmulas.
- **Impacto:** ao abrir uma exportação contendo um título controlado por usuário, a planilha pode executar uma fórmula, fazer requisições externas ou induzir o operador a interagir com conteúdo malicioso, conforme o programa utilizado.
- **Severidade:** média. **Confiança:** 95.
- **Correção:** valores textuais perigosos recebem apóstrofo inicial antes de `fputcsv`; o placeholder `-` de ausência de técnico é preservado. O delimitador, as colunas e a ordenação não mudaram.

### F9 — Média sem respostas causava divisão por zero

- **Onde:** `code/lib.php`, linhas originais 107–117.
- **Mecanismo:** `$qtd` só aumentava para valores não nulos. Banco vazio ou chamados ainda sem primeira resposta executavam `$soma / 0`.
- **Impacto:** a listagem inteira falhava justamente para instalações novas ou quando nenhum chamado havia recebido resposta.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** `SUM` e `COUNT(minutos_resposta)` são calculados em uma única consulta agregada; quantidade zero retorna `0.0`, compatível com o tipo `float`. A divisão em PHP preserva a precisão anterior.

### F10 — Uma consulta adicional por chamado para obter o técnico

- **Onde:** `code/lib.php`, linhas originais 88–90 na listagem e 136–137 no CSV.
- **Mecanismo:** após buscar N chamados, o laço chamava `tecnicoNome` para cada linha, produzindo N consultas adicionais. A exportação repetia o mesmo padrão.
- **Impacto:** latência e carga no MySQL cresciam linearmente com o volume exportado/listado, além do custo de cada round trip.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** listagem e exportação usam um `LEFT JOIN` com `usuarios` e `COALESCE(t.nome, '-')`. A chave pública `tecnico_nome` e o placeholder continuam presentes; `tecnicoNome` foi mantido para compatibilidade com eventuais consumidores fora do manifesto.

### F11 — Login não possui limitação de tentativas

- **Onde:** `code/index.php`, linhas originais 20–29.
- **Mecanismo:** toda requisição POST chama `autenticar`, sem contador, atraso, bloqueio ou integração com um limitador por conta/IP. Um cliente pode abandonar a sessão e iniciar outra a cada tentativa.
- **Impacto:** força bruta e credential stuffing online sem atrito na aplicação; hashes fortes não eliminam esse vetor.
- **Severidade:** média. **Confiança:** 100.
- **Não corrigido neste pacote:** um contador apenas em `$_SESSION` seria trivialmente contornável. Uma correção real exige estado compartilhado entre processos, política de desbloqueio e cuidado para não facilitar negação de serviço contra contas. Recomenda-se limitador no proxy/WAF ou armazenamento compartilhado já disponível na infraestrutura, sem inventar uma nova dependência nesta evolução.

### F12 — Cabeçalho CSV não era literalmente o contratado

- **Onde:** `code/lib.php`, linha original 130.
- **Mecanismo:** `fputcsv` colocava aspas no último campo por causa do espaço, gerando `ID,Titulo,Status,Tecnico,"Aberto em"`, enquanto o manifesto exige `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** consumidores que comparam a primeira linha literalmente, em vez de interpretá-la como CSV, rejeitam a exportação.
- **Severidade:** baixa. **Confiança:** 100.
- **Correção:** a primeira linha passou a ser escrita literalmente. As linhas de dados continuam usando `fputcsv` para escapar corretamente seu conteúdo.

## Decisões

- **Superfície pública preservada:** não alterei nenhuma assinatura listada no manifesto. As funções históricas sem usuário no argumento continuam retornando o conjunto global para rotinas internas; a autorização contextual foi adicionada por funções novas usadas por `index.php`. Isso evita introduzir dependência oculta de sessão nas rotinas noturnas.
- **Limitação de login ficou fora (F11):** não entreguei uma proteção baseada somente em sessão, que daria falsa sensação de segurança. A correção depende de infraestrutura compartilhada e deve ser tratada como mudança operacional explícita.
- **Rótulos e classificação não foram redesenhados:** `formatarStatus` e `rotuloPrioridade` permanecem com os mesmos resultados. Em particular, não inventei um novo rótulo para status fora de 1–3, pois o contrato não define esse caso e alterá-lo poderia afetar o relatório gerencial.
- **Símbolos legados mantidos:** `tecnicoNome` e a constante `EXPORT_DIR` continuam disponíveis para possíveis consumidores, embora os caminhos quentes corrigidos não precisem mais deles. Removê-los não traria benefício proporcional ao risco.
- **Sem mudança de stack ou reescrita:** continua sendo PHP com mysqli e SQL, sem dependências novas. Os `SELECT *` públicos, a ordem da listagem e o HTML contratado foram mantidos.
- **Mudança intencional em células perigosas do CSV:** o apóstrofo de neutralização altera a representação de textos iniciados como fórmula, não os dados armazenados. Essa exceção é necessária para F8; cabeçalho, ordem por ID e rótulos de status continuam contratuais.
- **Implantação:** definir `DB_PASS` e `SMTP_API_KEY`, rotacionar os segredos anteriormente expostos e ampliar a coluna de senha antes de esperar a migração automática dos hashes MD5.

## Verificação

- `php -l` sem erros em `config.php`, `lib.php` e `index.php`.
- Banco temporário MariaDB criado exclusivamente com `schema.sql` e `seed.sql`.
- 34 verificações automatizadas de função/CSV passaram: assinaturas, login atual e legado, migração de hash, rótulos, busca e payload de SQLi, visibilidade de Ana/Bruno/técnico, detalhe, médias (inclusive vazia), cabeçalho e ordem do CSV e neutralização de fórmula.
- Integração HTTP passou para login, rotação e atributos do cookie de sessão, tabela contratual, detalhe permitido/negado, XSS escapado e CSV isolado por cliente.
- O banco, o servidor HTTP e todos os arquivos temporários de teste foram removidos após a verificação.
