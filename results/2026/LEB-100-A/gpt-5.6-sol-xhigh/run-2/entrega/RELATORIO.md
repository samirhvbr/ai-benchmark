# Relatório técnico — LEB-100-A

## Resumo

O sistema tinha três falhas críticas: clientes autenticados recebiam chamados de outros clientes, a busca permitia injeção SQL e duas credenciais de produção estavam gravadas no código. Também havia autenticação com MD5, XSS refletido, fixação de sessão, condições de corrida na exportação, um cabeçalho CSV incompatível com o manifesto, divisão por zero e consultas N+1.

Foram corrigidos dez dos doze achados sem alterar as sete assinaturas públicas, os nomes das rotas, os rótulos de status, a estrutura da tabela HTML ou a ordem e as colunas do CSV. Os dois riscos restantes estão documentados porque uma correção local mudaria valores consumidos por integrações ou exigiria estado compartilhado/política de infraestrutura que o pacote não fornece. Todas as linhas citadas abaixo são da versão original recebida.

## Achados

### F1 — Clientes acessavam chamados de outros clientes

- **Local:** `code/index.php`, linhas 44–74.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** depois do login, a rota de listagem chamava `listarChamados` sem usar `$uid` ou `$papel`; o detalhe convertia qualquer `ver` em inteiro e chamava `verChamado`; e a exportação chamava `exportarCsv`, também sem escopo. Assim, Ana podia listar, exportar e abrir diretamente, por exemplo, o chamado 103 de Bruno. A média no topo também era calculada sobre todos os chamados.
- **Impacto:** qualquer cliente autenticado conseguia ler títulos, descrições, técnicos, estados e datas de chamados alheios, violando diretamente a regra de visibilidade do manifesto.
- **Correção:** corrigido. As rotas agora usam auxiliares com escopo por `usuario_id`; técnicos continuam vendo tudo, clientes veem apenas seus dados e papéis inválidos falham fechados. As funções públicas sem contexto de usuário mantêm as assinaturas e o comportamento necessário aos consumidores internos.

### F2 — A busca permitia injeção SQL

- **Local:** `code/lib.php`, linhas 80–85.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** `$busca` era concatenada entre aspas dentro de `WHERE titulo LIKE '%...%'`. Uma entrada contendo aspas e operadores SQL alterava a cláusula; com um `UNION` de forma compatível, um usuário autenticado também poderia tentar projetar dados de outras tabelas.
- **Impacto:** leitura indevida de dados, desvio do filtro e erros de banco controlados pelo usuário.
- **Correção:** corrigido. A consulta usa `prepare`, marcador `?` e `bind_param`; o curinga é acrescentado ao valor, não ao texto SQL.

### F3 — Credenciais de produção estavam embutidas no repositório

- **Local:** `code/config.php`, linhas 8–15.
- **Categoria:** segurança.
- **Severidade:** crítica.
- **Confiança:** 100/100.
- **Mecanismo:** a senha do banco era um fallback literal e a chave da central de e-mail era sempre definida por um literal. Qualquer cópia do código, log de empacotamento ou histórico do repositório expunha os segredos sem exigir acesso ao ambiente de produção.
- **Impacto:** uso não autorizado do banco ou do serviço de e-mail, limitado apenas pelas permissões e pelo alcance de rede dessas credenciais.
- **Correção:** corrigido no código: ambos os segredos passam a vir exclusivamente de `DB_PASS` e `SMTP_API_KEY`, com valor vazio quando ausentes. Os valores já expostos precisam ser revogados e substituídos operacionalmente.

### F4 — Senhas eram armazenadas e comparadas com MD5 sem salt

- **Local:** `code/lib.php`, linhas 15–20; relacionado a `code/schema.sql`, linha 7, e `code/seed.sql`, linhas 2–8.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** `md5($senha)` produzia sempre o mesmo hash rápido de 32 caracteres e a consulta comparava esse valor diretamente. Em caso de leitura do banco, senhas comuns podem ser recuperadas em alto volume com tabelas pré-computadas ou força bruta barata.
- **Impacto:** comprometimento das contas do painel e possível reutilização das senhas em outros sistemas.
- **Correção:** corrigido. Novas bases usam `VARCHAR(255)` e hashes de `password_hash`; `autenticar` usa `password_verify`. Hashes MD5 legados continuam válidos durante a transição e são regravados com o algoritmo atual no primeiro login bem-sucedido quando a coluna já comporta o novo hash.

### F5 — O termo de busca causava XSS refletido

- **Local:** `code/index.php`, linhas 79–82.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 100/100.
- **Mecanismo:** o valor de `busca` era escrito sem escape tanto no atributo `value` quanto no parágrafo de resultados. Uma URL com aspas e uma tag de script fechava o atributo e inseria HTML executável na página autenticada.
- **Impacto:** execução de JavaScript no navegador da vítima, com possibilidade de realizar ações e ler dados acessíveis pela sessão.
- **Correção:** corrigido. Toda saída textual dinâmica da página passa por `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8; parâmetros que chegam como array são rejeitados como entrada vazia.

### F6 — O identificador de sessão sobrevivia ao login

- **Local:** `code/index.php`, linhas 20–27.
- **Categoria:** segurança.
- **Severidade:** alta.
- **Confiança:** 95/100.
- **Mecanismo:** após validar usuário e senha, o código apenas preenchia `$_SESSION`; não regenerava o ID. Se um atacante conseguisse induzir o navegador da vítima a usar um ID conhecido antes do login, esse mesmo ID passaria a representar a conta autenticada.
- **Impacto:** sequestro da sessão recém-autenticada.
- **Correção:** corrigido. O login chama `session_regenerate_id(true)`, ativa `session.use_strict_mode` e configura o cookie com `HttpOnly`, `SameSite=Lax` e `Secure` quando a requisição é HTTPS.

### F7 — A exportação usava um arquivo global compartilhado

- **Local:** `code/lib.php`, linhas 125–150.
- **Categoria:** arquitetura.
- **Severidade:** média.
- **Confiança:** 95/100.
- **Mecanismo:** toda requisição truncava e escrevia o mesmo `/var/www/painel/tmp/chamados.csv` e depois o lia. Duas exportações concorrentes podiam intercalar truncamento, escrita e leitura; uma falha também deixava dados persistentes nesse caminho e o retorno silencioso podia produzir uma resposta vazia.
- **Impacto:** CSV corrompido ou pertencente a outra requisição, além de retenção desnecessária de dados no disco.
- **Correção:** corrigido. O CSV é consultado antes dos cabeçalhos e transmitido diretamente por `php://output`, sem arquivo compartilhado. A consulta também respeita o escopo do usuário na rota web.

### F8 — O cabeçalho CSV real não era o cabeçalho exato do contrato

- **Local:** `code/lib.php`, linha 130.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** `fputcsv` cercava o campo com espaço por aspas. O resultado era `ID,Titulo,Status,Tecnico,"Aberto em"`, enquanto o manifesto exige literalmente `ID,Titulo,Status,Tecnico,Aberto em`.
- **Impacto:** consumidores que conferem ou dividem o cabeçalho como texto exato deixam de reconhecer a exportação.
- **Correção:** corrigido. Somente o cabeçalho é gravado literalmente; as linhas de dados continuam usando serialização CSV apropriada e permanecem ordenadas por `id` crescente.

### F9 — A média lançava erro quando não havia respostas

- **Local:** `code/lib.php`, linhas 109–116.
- **Categoria:** bug.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** se a tabela estivesse vazia ou todos os `minutos_resposta` fossem `NULL`, `$qtd` permanecia zero e a função executava `$soma / $qtd`. Em PHP atual isso lança `DivisionByZeroError` e interrompe a listagem.
- **Impacto:** resposta HTTP 500 justamente em bases novas ou períodos sem primeira resposta registrada.
- **Correção:** corrigido. O banco calcula `AVG`; resultado `NULL` é convertido em `0.0`, preservando o retorno `float`. A variante usada na página calcula a média apenas sobre chamados visíveis.

### F10 — Listagem e exportação faziam uma consulta por chamado

- **Local:** `code/lib.php`, linhas 88–90; o mesmo padrão aparecia nas linhas 132–137.
- **Categoria:** performance.
- **Severidade:** média.
- **Confiança:** 100/100.
- **Mecanismo:** após buscar N chamados, cada iteração chamava `tecnicoNome`, que executava outro `SELECT`. Uma página ou exportação com N registros fazia N+1 viagens ao banco.
- **Impacto:** latência e carga no banco cresciam linearmente com a quantidade de chamados, reduzindo a capacidade do painel.
- **Correção:** corrigido. Listagem e exportação usam um único `LEFT JOIN` com `usuarios`, preservando `tecnico_nome` e o rótulo `-` quando não há técnico.

### F11 — Títulos podem virar fórmulas ao abrir o CSV em uma planilha

- **Local:** `code/lib.php`, linhas 138–144.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 75/100.
- **Mecanismo:** `fputcsv` protege a sintaxe do arquivo, mas não neutraliza valores iniciados por `=`, `+`, `-` ou `@`. Se outra parte do sistema permitir um título assim e uma pessoa abrir a exportação em uma planilha que avalia fórmulas, o conteúdo será interpretado como fórmula.
- **Impacto:** fórmulas maliciosas podem induzir requisições externas, alterar a interpretação dos dados ou explorar recursos do aplicativo de planilha, conforme o software usado.
- **Correção:** não corrigido. Prefixar apóstrofo ou tabulação mudaria o título exportado e, portanto, os dados vistos pelos consumidores externos. A mitigação deve ser acordada como uma nova regra de formato ou aplicada apenas no fluxo destinado a planilhas.

### F12 — O login não limita tentativas

- **Local:** `code/index.php`, linhas 20–29.
- **Categoria:** segurança.
- **Severidade:** média.
- **Confiança:** 85/100.
- **Mecanismo:** cada POST com `login` executa imediatamente uma verificação de senha; não há contador por conta/origem, atraso progressivo ou bloqueio. O código, isoladamente, aceita tentativas automatizadas sem limite.
- **Impacto:** força bruta e preenchimento de credenciais, especialmente contra senhas legadas ou reutilizadas.
- **Correção:** não corrigido. Um limite apenas em `$_SESSION` seria contornável descartando cookies. Uma solução efetiva exige armazenamento compartilhado e política operacional (janela, chave por conta/origem, desbloqueio e observabilidade) ausentes deste pacote; deve ser implementada no proxy ou em um componente compartilhado.

## Decisões

- Mantive exatamente as assinaturas públicas de `autenticar`, `formatarStatus`, `rotuloPrioridade`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`. Auxiliares novos aplicam autorização somente onde há contexto de sessão; os consumidores noturnos ainda podem chamar as funções públicas e obter a visão completa contratada.
- Mantive rotas, nomes dos parâmetros GET, colunas e `id` da tabela HTML, rótulos de status e as cinco colunas do CSV. Não troquei `mysqli` nem acrescentei dependências.
- Não neutralizei fórmulas no CSV pelo motivo registrado em F11.
- Não implementei limitação de login local pelo motivo registrado em F12.
- Não executei DDL automaticamente durante o login. Em uma base de produção já existente, a coluna `usuarios.senha` deve ser migrada de `CHAR(32)` para `VARCHAR(255)` antes de esperar a atualização oportunista; até lá, a leitura de MD5 permanece apenas para compatibilidade.
- Não removi `EXPORT_DIR`, embora a rota corrigida não o utilize, porque outros scripts legados podem carregar `config.php` e depender da constante.
- Não tentei revogar credenciais externamente. A remoção dos literais impede novas exposições no código, mas os dois valores anteriormente publicados devem ser rotacionados pelos responsáveis pelos serviços.

## Validação

- `php -l` passou em `config.php`, `lib.php` e `index.php` com PHP 8.4.26.
- O schema e o seed foram carregados em uma instância MariaDB temporária. Passaram 24 verificações de integração cobrindo autenticação moderna e legada, rehash, assinaturas de retorno, busca parametrizada, visibilidade por papel, detalhe, média vazia, escape HTML, rótulos e CSV.
- Um teste adicional, com o modo estrito do banco desativado, confirmou que uma coluna legada `CHAR(32)` não recebe hash truncado e continua autenticando até a migração para `VARCHAR(255)`.
- Testes HTTP com o servidor embutido confirmaram que Ana vê somente 101, 102 e 105; não abre 103; exporta três linhas; Carla vê e abre os cinco chamados; e a carga de XSS aparece escapada.
