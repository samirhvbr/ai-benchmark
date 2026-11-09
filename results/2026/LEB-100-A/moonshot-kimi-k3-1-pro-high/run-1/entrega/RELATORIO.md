# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas graves de isolamento entre clientes e uma busca com injeção de SQL, além de segredos versionados, senhas em MD5 e saídas HTML/CSV sem proteção. Também havia risco de fixação de sessão, corrida no arquivo de exportação, divisão por zero e consultas N+1.

Foram feitas correções localizadas em `config.php`, `index.php`, `lib.php` e `schema.sql`. As sete assinaturas públicas, as rotas, os rótulos, a ordenação e o cabeçalho do CSV e a estrutura declarada da tabela HTML foram preservados. As referências de linha abaixo usam a numeração dos arquivos originais.

## Achados

### F1 — Clientes podiam ler e exportar chamados de outros clientes

- **Onde:** `code/index.php`, linhas originais 44–74; consultas em `code/lib.php`, linhas 78–101 e 123–145.
- **Categoria / severidade / confiança:** segurança / **crítica** / **100**.
- **Mecanismo:** depois do login, `uid` e `papel` eram lidos da sessão, mas nenhuma das três rotas de leitura usava esses valores. A listagem, o detalhe por `ver` e o CSV selecionavam todos os registros de `chamados`. Assim, o detalhe era um IDOR: bastava um cliente autenticado informar o ID de outro chamado.
- **Impacto:** exposição de títulos, descrições, situação e dados de atendimento de outros assinantes; o CSV permitia exfiltrar toda a base de uma vez.
- **Correção:** `clienteDaSessao()` identifica sessões de cliente e as consultas de listagem, detalhe, exportação e média passam a restringir `usuario_id`. Técnicos continuam vendo todos. Chamadas internas sem sessão, como a rotina noturna, mantêm a visão integral. Não houve alteração nas assinaturas públicas.

### F2 — Injeção de SQL na busca por título

- **Onde:** `code/lib.php`, linhas originais 80–85.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** o valor de `GET busca` chegava a `listarChamados()` e era concatenado entre aspas em `WHERE titulo LIKE '%...%'` sem escape ou parâmetro. Um usuário autenticado podia fechar a literal SQL e alterar a consulta.
- **Impacto:** leitura ou manipulação não autorizada de dados dentro dos privilégios do usuário do banco, além de indisponibilidade por consultas inválidas ou custosas.
- **Correção:** a busca passou a usar `prepare()`/`bind_param()`; apenas o valor `%termo%` é vinculado, nunca a estrutura SQL. Os curingas `LIKE` continuam funcionando como antes. A condição de proprietário, quando aplicável, também é parametrizada.

### F3 — Senha do banco e chave SMTP embutidas no código

- **Onde:** `code/config.php`, linhas originais 11–15.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** a senha de produção era usada como fallback quando `DB_PASS` não existia e a chave SMTP era uma constante literal. Qualquer pessoa com acesso ao pacote, repositório ou backup do código obtinha credenciais reutilizáveis.
- **Impacto:** comprometimento do banco e do serviço transacional, inclusive fora da aplicação web.
- **Correção:** ambos os segredos agora vêm exclusivamente de `DB_PASS` e `SMTP_API_KEY`. Os valores expostos devem ser revogados/rotacionados e definidos no ambiente de implantação; removê-los do arquivo não os invalida.

### F4 — Autenticação baseada em MD5 rápido e sem salt

- **Onde:** `code/lib.php`, linhas originais 15–20; armazenamento em `code/schema.sql`, linha 7.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** `autenticar()` calculava `md5($senha)` e comparava esse valor diretamente no banco. MD5 é rápido e não possui salt por senha, permitindo testar grandes volumes de candidatas e reutilizar tabelas de hashes após vazamento.
- **Impacto:** recuperação das senhas dos usuários a partir de um vazamento da tabela e possível comprometimento de outras contas por reutilização de senha.
- **Correção:** novos hashes usam `password_hash()`/`PASSWORD_DEFAULT` e a verificação usa `password_verify()`. Para não invalidar as contas atuais, hashes MD5 ainda são reconhecidos uma vez no login e substituídos após autenticação válida. A coluna passou a `VARCHAR(255)`. Em instalações existentes, é necessário ampliar a coluna antes de ativar a migração; o código verifica seu tamanho e não sobrescreve um MD5 enquanto ele ainda não couber.

### F5 — XSS refletido pelo parâmetro de busca

- **Onde:** `code/index.php`, linhas originais 79–82.
- **Categoria / severidade / confiança:** segurança / **média** / **100**.
- **Mecanismo:** `$busca` era impresso sem codificação tanto no atributo `value` quanto no parágrafo de resultados. Marcação HTML enviada em `index.php?busca=...` era interpretada no navegador de um usuário autenticado.
- **Impacto:** execução de JavaScript no contexto do painel, ações em nome da vítima e leitura de conteúdo acessível à sessão.
- **Correção:** o termo é codificado uma vez com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` e a versão segura é usada nos dois contextos. O parâmetro e o comportamento da busca não mudaram.

### F6 — Sessão não era renovada após o login

- **Onde:** `code/index.php`, linhas originais 20–27.
- **Categoria / severidade / confiança:** segurança / **média** / **95**.
- **Mecanismo:** ao autenticar, o código gravava `uid` e `papel` na sessão existente sem trocar seu identificador. Se um atacante conseguisse fixar previamente esse ID no navegador da vítima, o mesmo ID passaria a representar uma sessão autenticada.
- **Impacto:** sequestro da sessão autenticada sem necessidade de conhecer a senha.
- **Correção:** `session_regenerate_id(true)` é chamado antes de gravar a identidade, invalidando o identificador anterior.

### F7 — Células de CSV podiam ser interpretadas como fórmulas

- **Onde:** `code/lib.php`, linhas originais 136–145.
- **Categoria / severidade / confiança:** segurança / **média** / **95**.
- **Mecanismo:** títulos e nomes eram entregues diretamente a `fputcsv()`. A cotação CSV não neutraliza fórmulas: ao abrir o arquivo em uma planilha, valores iniciados por `=`, `+`, `-`, `@`, tabulação ou retorno de carro podem ser avaliados como conteúdo ativo.
- **Impacto:** execução de comandos/fórmulas ou exfiltração de dados, conforme o programa de planilha e suas proteções, quando um operador abre uma exportação contendo texto controlado por outra pessoa.
- **Correção:** os campos textuais variáveis recebem um apóstrofo inicial quando começam com um desses caracteres. Cabeçalho, colunas, rótulos e ordenação permanecem iguais.

### F8 — Exportações concorrentes compartilhavam um arquivo temporário

- **Onde:** `code/lib.php`, linhas originais 125–150.
- **Categoria / severidade / confiança:** segurança / **média** / **100**.
- **Mecanismo:** toda requisição abria `/var/www/painel/tmp/chamados.csv` com escrita destrutiva e depois o relia. Duas exportações simultâneas podiam truncar ou sobrescrever o arquivo enquanto a outra ainda escrevia ou lia. Além disso, retorno por erro após `fopen()` deixava o recurso aberto.
- **Impacto:** CSV misturado ou truncado e possível entrega do conteúdo de outra requisição; com escopos de cliente diferentes, isso também poderia romper o isolamento.
- **Correção:** o CSV é transmitido diretamente para `php://output`, sem arquivo compartilhado. Os headers e o formato de download foram mantidos.

### F9 — Média dividia por zero quando não havia respostas

- **Onde:** `code/lib.php`, linhas originais 109–116.
- **Categoria / severidade / confiança:** bug / **média** / **100**.
- **Mecanismo:** `$qtd` só aumentava para linhas com `minutos_resposta` não nulo, mas o retorno sempre calculava `$soma / $qtd`. Base vazia ou apenas chamados sem resposta produzia divisão por zero; no PHP atual isso lança `DivisionByZeroError` e impede renderizar a listagem.
- **Impacto:** indisponibilidade do painel justamente em uma instalação nova ou quando nenhum atendimento teve resposta registrada.
- **Correção:** a agregação usa `AVG` com `COALESCE(..., 0)` e retorna `0.0` quando não há amostras. O cálculo para conjuntos não vazios permanece equivalente e a média visível a clientes respeita seus próprios chamados.

### F10 — Consultas N+1 para nomes de técnicos

- **Onde:** `code/lib.php`, linhas originais 64–71, 88–90 e 132–138.
- **Categoria / severidade / confiança:** performance / **média** / **100**.
- **Mecanismo:** após buscar N chamados, a listagem executava `tecnicoNome()` separadamente para cada linha; a exportação repetia o mesmo padrão. Uma página ou CSV com N chamados fazia N+1 consultas, aumentando latência e carga com o volume de dados.
- **Impacto:** respostas progressivamente mais lentas e maior pressão sobre o MySQL, especialmente na exportação completa.
- **Correção:** listagem e exportação fazem um único `LEFT JOIN` com `usuarios` e `COALESCE` para preservar `-` quando não há técnico. A função `tecnicoNome()` foi mantida para eventuais consumidores legados.

## Decisões

- **Assinaturas e integração interna:** não acrescentei parâmetros de usuário às funções públicas. A visibilidade é derivada da sessão somente quando ela representa um cliente; processos internos sem sessão continuam recebendo a visão geral de que dependem.
- **Contratos de apresentação:** mantive os três textos de `formatarStatus()`, os rótulos de prioridade, os parâmetros `busca`/`ver`/`export`, a ordem `ID, Titulo, Status, Prioridade, Tecnico`, os links de detalhe, o cabeçalho CSV e a ordenação por ID.
- **Semântica da busca:** não escapei `%` e `_` como literais, pois isso alteraria o comportamento atual do filtro. A parametrização elimina a injeção sem mudar esses curingas.
- **Funções/constantes legadas:** mantive `tecnicoNome()` e `EXPORT_DIR`, embora os caminhos principais não precisem mais deles, para evitar quebrar consumidores fora do manifesto.
- **Stack e estrutura:** permaneci com PHP, mysqli, sessões e os mesmos arquivos; não introduzi dependências nem reescrevi o roteador ou a camada de dados.
- **Migração de senha:** não forcei redefinição nem troquei as senhas do seed. A ampliação da coluna deve ser aplicada ao banco existente; o seed MD5 continua aceito e é convertido no primeiro login bem-sucedido.

## Verificação

- `php -l` passou em `config.php`, `lib.php` e `index.php`.
- Reflexão confirmou quantidade de parâmetros e tipos de retorno das sete funções públicas.
- Foram conferidos os três rótulos exatos de status e os caminhos preservados de prioridade.
- Não havia credenciais válidas para o MySQL local durante a execução; portanto, um teste de integração com o banco não pôde ser realizado neste ambiente.
