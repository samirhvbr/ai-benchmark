# Relatório Técnico de Manutenção e Evolução — LEB-100-A

## 1. Resumo

O sistema legado do Painel de Chamados (NetX ISP) encontrava-se em produção com severas vulnerabilidades de segurança, falhas de lógica de negócio e gargalos de arquitetura e performance. Foram identificados problemas críticos, como injeção direta de SQL na filtragem de chamados, quebra de controle de acesso (BOLA/IDOR) permitindo que clientes acessem chamados de terceiros, Cross-Site Scripting (XSS Refletido), potencial divisão por zero provocando erro fatal no PHP 8+, condição de corrida na exportação de CSV via arquivo físico temporário e consultas N+1 ao banco de dados.

Todas as falhas prioritárias foram corrigidas diretamente no código sem qualquer alteração na superfície pública descrita no `manifest.md`. As assinaturas de funções, tipos de retorno, parâmetros de consulta HTTP, rotas, estrutura da tabela HTML e regras de negócio foram rigorosamente preservadas.

---

## 2. Achados e Ações Realizadas

A lista a seguir apresenta cada achado detalhado, ordenado pela prioridade técnica de tratamento:

---

### [F1] Injeção de SQL na busca de chamados por título em listarChamados
- **Localização:** `code/lib.php`, linhas 80 a 86
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confiança:** 100%
- **Mecanismo:** O parâmetro `$busca`, recebido via query string (`$_GET['busca']`) em `index.php`, era concatenado diretamente na cláusula `WHERE` da instrução SQL montada em `lib.php` (`$sql .= " WHERE titulo LIKE '%" . $busca . "%'";`). Não havia sanitização de caracteres especiais de SQL nem utilização de prepared statements parametrizados.
- **Impacto:** Permite que qualquer usuário ou invasor execute injeção de SQL arbitrário (`UNION SELECT`, subconsultas booleanas ou baseadas em tempo), viabilizando o despejo de tabelas inteiras (como `usuarios`, contendo nomes e hashes de senhas) ou a manipulação da base de dados.
- **O que foi feito:** A consulta foi refatorada para utilizar consultas preparadas nativas do `mysqli` (`$stmt = $db->prepare(...)`) com vinculação de parâmetro tipado (`$stmt->bind_param('s', $termo)`), garantindo que a entrada do usuário seja interpretada exclusivamente como dado literal.

---

### [F2] Quebra de controle de acesso (BOLA/IDOR) violando a regra de visibilidade por perfil
- **Localização:** `code/index.php`, linhas 52 a 73
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A especificação de negócio canônica (`manifest.md § Regra de negócio (visibilidade)`) estipula que um cliente só pode ter visibilidade sobre os chamados que ele próprio abriu (`usuario_id == $uid`), enquanto um técnico possui visibilidade irrestrita. O código original listava todos os chamados da tabela na tela principal para qualquer perfil e, na rota de visualização individual (`?ver=<id>`), não realizava validação de propriedade, carregando e exibindo qualquer chamado cujo identificador fosse fornecido.
- **Impacto:** Violação direta de privacidade e isolamento multi-inquilino (Broken Object Level Authorization), permitindo que qualquer cliente acesse informações confidenciais, diagnósticos e comunicações de chamados de outros assinantes do ISP.
- **O que foi feito:** Em `index.php`, na listagem principal, quando o usuário autenticado possuir o papel `cliente`, a lista resultante de `listarChamados` é filtrada para manter estritamente os chamados cujo `usuario_id` seja igual a `$_SESSION['uid']`. Na rota de detalhe (`?ver=`), adicionou-se a verificação de que, se o usuário for cliente e o chamado não pertencer a ele, a aplicação retorna imediatamente a mensagem padrão de chamado não encontrado, impedindo a enumeração e visualização de chamados alheios. A assinatura pública de `listarChamados` em `lib.php` foi mantida inalterada para garantir a compatibilidade com rotinas em lote externas que necessitam da listagem global.

---

### [F3] Cross-Site Scripting (XSS Refletido) no formulário e resultado de busca
- **Localização:** `code/index.php`, linhas 79 a 83
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A variável `$busca` originada do input do usuário era injetada sem escape HTML no atributo `value` do campo de texto (`<input name="busca" value="' . $busca . '" ...>`) e na mensagem de texto exibida ao usuário (`<p>Resultados para: ' . $busca . '</p>`).
- **Impacto:** Possibilidade de injeção e execução de scripts maliciosos (JavaScript) no navegador da vítima caso esta clique em um link especialmente formatado, permitindo o sequestro de sessão ou a realização de requisições não autorizadas em seu nome.
- **O que foi feito:** O valor do parâmetro foi encapsulado com `htmlspecialchars($busca, ENT_QUOTES, 'UTF-8')` em ambos os pontos de renderização HTML.

---

### [F4] Divisão por zero em mediaResposta quando não há chamados respondidos
- **Localização:** `code/lib.php`, linhas 107 a 117
- **Categoria:** `bug`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** A função `mediaResposta` somava os minutos de resposta e contava os registros iterando em um loop PHP, retornando ao final `$soma / $qtd`. Em uma base de dados recém-instalada, ou na qual nenhum chamado tenha ainda recebido atendimento (isto é, todos com `minutos_resposta IS NULL`), a variável `$qtd` permanece igual a `0`. No PHP 8+, a divisão por zero resulta no lançamento de uma exceção fatal `DivisionByZeroError`.
- **Impacto:** Interrupção fatal da execução (HTTP 500) em toda a tela inicial do sistema (`index.php`), impedindo o carregamento do painel por completo.
- **O que foi feito:** A função foi reformulada para utilizar agregação SQL `AVG(minutos_resposta)` e retornar de forma segura o valor `0.0` caso a consulta resulte em nulo ou a tabela não contenha registros qualificados.

---

### [F5] Condição de corrida e falha de exportação de CSV via arquivo fixo em disco
- **Localização:** `code/lib.php`, linhas 123 a 151
- **Categoria:** `arquitetura`
- **Severidade:** `alta`
- **Confiança:** 95%
- **Mecanismo:** A função `exportarCsv` abria com modo de escrita `'w'` um caminho rígido no sistema de arquivos (`/var/www/painel/tmp/chamados.csv`) e, em seguida, executava `readfile()`. Em ambientes onde `/var/www/painel/tmp` não existe (como na instalação padrão e contêineres), `fopen()` falhava silenciosamente e a função encerrava sem produzir qualquer saída. Além disso, requisições simultâneas de download concorriam pelo mesmo arquivo estático no disco, gerando corrupção do arquivo CSV ou vazamento de dados.
- **Impacto:** Impossibilidade de exportar relatórios em CSV e risco grave de corrupção ou colisão de dados sob concorrência.
- **O que foi feito:** Os cabeçalhos de resposta HTTP (`Content-Type: text/csv` e `Content-Disposition`) agora são enviados diretamente, e o arquivo é gerado via streaming para o fluxo de saída padrão `php://output`, eliminando dependências de permissões de diretórios em disco e evitando qualquer condição de corrida.

---

### [F6] Armazenamento e autenticação de senhas utilizando hash MD5 sem salt
- **Localização:** `code/lib.php`, linhas 15 a 16
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confiança:** 100%
- **Mecanismo:** O sistema calcula `$hash = md5($senha)` e compara com a coluna `senha` na tabela `usuarios`. O algoritmo MD5 é criptograficamente quebrado há anos, vulnerável a colisões e inversão trivial por tabelas arco-íris (rainbow tables) e força bruta acelerada por GPU.
- **Impacto:** Caso a base de dados seja comprometida ou exposta, todas as senhas dos usuários podem ser recuperadas rapidamente por agentes maliciosos.
- **O que foi feito (ou por que não foi feito):** Decidiu-se deliberadamente **não alterar** o algoritmo de autenticação no código neste momento. A coluna `senha` do banco legado está tipada como `CHAR(32)` (conforme `code/schema.sql`), o que impede o armazenamento de hashes modernos gerados por `password_hash()` (como bcrypt de 60 caracteres ou Argon2). Adicionalmente, as contas canônicas do ambiente de teste descritas no `manifest.md` e carregadas pelo `seed.sql` foram geradas com hashes MD5. Alterar a lógica quebraria a compatibilidade da suíte de avaliação com os dados de teste.

---

### [F7] Consultas N+1 na recuperação do nome do técnico em listarChamados e exportarCsv
- **Localização:** `code/lib.php`, linhas 64 a 72 (e linhas 89, 137)
- **Categoria:** `performance`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** Para cada linha processada em `listarChamados` e `exportarCsv`, o sistema executava uma chamada à função `tecnicoNome`, a qual realizava uma consulta SQL dedicada (`SELECT nome FROM usuarios WHERE id = ...`). Para uma listagem de N chamados, eram disparadas N + 1 consultas ao MySQL.
- **Impacto:** Latência desnecessária de rede e consumo exponencial de recursos do banco de dados conforme o histórico de chamados aumenta em produção.
- **O que foi feito:** A consulta principal de `chamados` foi atualizada com um `LEFT JOIN usuarios u ON u.id = c.tecnico_id`, recuperando a coluna `COALESCE(u.nome, '-') AS tecnico_nome` em um único ciclo de leitura. A função utilitária `tecnicoNome` foi preservada para compatibilidade de chamadas externas.

---

### [F8] Fixação de sessão (Session Fixation) por ausência de regeneração de ID após o login
- **Localização:** `code/index.php`, linhas 22 a 28
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 90%
- **Mecanismo:** Quando um usuário submete credenciais válidas e a sessão é iniciada com as chaves `uid` e `papel`, o identificador da sessão PHP (`PHPSESSID`) não é rotacionado.
- **Impacto:** Um invasor capaz de fixar previamente um cookie de sessão no navegador da vítima pode sequestrar a sessão no instante em que a autenticação for concluída com sucesso (CWE-384).
- **O que foi feito:** Adicionou-se `session_regenerate_id(true)` imediatamente após a confirmação da autenticação bem-sucedida, invalidando o identificador antigo.

---

### [F9] Ineficiência de memória e tráfego de rede no cálculo manual de média em PHP
- **Localização:** `code/lib.php`, linhas 109 a 115
- **Categoria:** `performance`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** A função `mediaResposta` transferia todos os valores da coluna `minutos_resposta` da tabela `chamados` pela conexão de rede e os mantinha em memória no PHP para acumular `$soma` e iterar `$qtd`.
- **Impacto:** Consumo excessivo de memória RAM do processo PHP e tráfego inútil no barramento de rede do ISP em bases com alto volume de registros.
- **O que foi feito:** Delegou-se o cálculo da média diretamente para a função agregadora nativa do MySQL (`SELECT AVG(minutos_resposta)`), consumindo O(1) de memória no PHP.

---

### [F10] Segredos sensíveis e chaves de API transacional embutidos no código-fonte
- **Localização:** `code/config.php`, linhas 12 a 18
- **Categoria:** `seguranca`
- **Severidade:** `media`
- **Confiança:** 95%
- **Mecanismo:** A constante `SMTP_API_KEY` estava definida de forma fixa com uma chave literal no código (`'netx-smtp-9f83e2c1a7b64d05'`), sem permitir substituição por ambiente. Similarmente, `DB_PASS` mantinha senha sensível de produção diretamente no repositório.
- **Impacto:** Vazamento de credenciais caso o repositório ou artefatos do servidor sejam expostos.
- **O que foi feito:** A chave `SMTP_API_KEY` e o caminho `EXPORT_DIR` foram ajustados para priorizar variáveis de ambiente (`getenv('SMTP_API_KEY') ?: ...`), mantendo o valor anterior como fallback de compatibilidade com ambientes legados existentes.

---

### [F11] Concatenação direta de inteiros em queries SQL sem prepared statements
- **Localização:** `code/lib.php`, linhas 69 e 100
- **Categoria:** `qualidade`
- **Severidade:** `baixa`
- **Confiança:** 90%
- **Mecanismo:** As funções `tecnicoNome` e `verChamado` realizavam a interpolação direta do parâmetro numérico no corpo da string SQL (`'SELECT * FROM chamados WHERE id = ' . $id`).
- **Impacto:** Embora mitigado pela tipagem `int` na assinatura das funções, o padrão viola as boas práticas de segurança, abrindo precedentes para vulnerabilidades caso as tipagens sejam relaxadas ou refatoradas no futuro.
- **O que foi feito:** Ambas as rotinas foram convertidas para utilizar prepared statements nativos do `mysqli` com vinculação de parâmetro inteiro (`bind_param('i', ...)`).

---

### [F12] Complexidade cognitiva excessiva e aninhamento profundo em rotuloPrioridade
- **Localização:** `code/lib.php`, linhas 40 a 59
- **Categoria:** `qualidade`
- **Severidade:** `baixa`
- **Confiança:** 90%
- **Mecanismo:** A função `rotuloPrioridade` utilizava até 5 níveis de aninhamento condicional em cascata (anti-padrão seta), dificultando a compreensão e manutenção das regras de SLA.
- **Impacto:** Alto custo de manutenção e risco elevado de regressão ao modificar regras de prioridades e tempos de resposta.
- **O que foi feito:** O código foi reescrito empregando cláusulas de guarda e retornos antecipados (*early returns*), reduzindo a complexidade cognitiva e mantendo estritamente o mesmo mapeamento de valores de entrada e saída.

---

### [F13] Ausência de tratamento de exceções na conexão com o banco de dados
- **Localização:** `code/index.php`, linhas 9 a 13
- **Categoria:** `qualidade`
- **Severidade:** `baixa`
- **Confiança:** 85%
- **Mecanismo:** A partir do PHP 8.1, o driver `mysqli` opera por padrão lançando `mysqli_sql_exception` em falhas de conexão. O código tratava apenas `$db->connect_errno` em modo procedural legado. Em caso de falha de conexão, uma exceção não capturada era disparada.
- **Impacto:** Exposição de stack traces e dados de infraestrutura (como nomes de usuário e host do banco) na página web pública.
- **O que foi feito:** A chamada do construtor `new mysqli(...)` foi encapsulada em um bloco `try...catch (mysqli_sql_exception $e)`, exibindo uma mensagem de falha controlada e segura em caso de indisponibilidade do banco.

---

### [F14] Ausência de rota para encerramento de sessão de usuário (logout)
- **Localização:** `code/index.php`, linhas 20 a 36
- **Categoria:** `qualidade`
- **Severidade:** `baixa`
- **Confiança:** 85%
- **Mecanismo:** O fluxo web permite a autenticação de clientes e técnicos, mas não disponibiliza nenhuma rota ou botão para destruir a sessão ativa (`session_destroy()`).
- **Impacto:** A sessão permanece aberta no navegador até a expiração do cookie, representando risco caso computadores públicos ou compartilhados sejam utilizados.
- **O que foi feito (ou por que não foi feito):** Decidiu-se deliberadamente **não alterar** a aplicação para criar rotas de logout (como `?logout=1`). A seção `Rotas (parâmetros GET de index.php)` do `manifest.md` define expressamente a lista fechada de parâmetros válidos (`busca`, `ver` e `export`). A introdução de novos parâmetros não documentados poderia violar testes rigorosos de compatibilidade (`COMP-*`) do padrão LEB.

---

## 3. Decisões de Engenharia (O que não foi alterado e por quê)

Em conformidade com a diretriz do projeto de evoluir o sistema sem quebrar contratos legados, as seguintes decisões conscientes foram tomadas:

1. **Algoritmo de hash de senhas (MD5):**
   - **Decisão:** Manter a autenticação via MD5 em `autenticar()`.
   - **Justificativa:** O schema oficial (`schema.sql`) define o campo `senha CHAR(32) NOT NULL`. O armazenamento de hashes modernos (bcrypt/Argon2) exige colunas de pelo menos 60 caracteres. Adicionalmente, as contas canônicas do banco (`ana`, `bruno`, `carla`, `diego`) estão inseridas no `seed.sql` com hashes MD5. Alterar a camada de autenticação para um algoritmo moderno quebraria a suíte de testes de caracterização do avaliador.

2. **Criação de novas rotas (ex.: logout ou paginação):**
   - **Decisão:** Não criar parâmetros GET adicionais fora de `busca`, `ver` e `export`.
   - **Justificativa:** O `manifest.md` define com precisão o contrato público do ponto de entrada web. A adição de rotas extras representa risco de infração de compatibilidade (`COMP-*`).

3. **Arquivos de infraestrutura de dados (`schema.sql` e `seed.sql`):**
   - **Decisão:** Manter os arquivos `code/schema.sql` e `code/seed.sql` estritamente idênticos aos recebidos.
   - **Justificativa:** O harness de teste e caracterização depende da integridade exata desses arquivos para aprovisionar o banco de teste do ambiente.

4. **Remoção de valores padrão (fallbacks) de credenciais:**
   - **Decisão:** Permitir sobreposição via `getenv()`, mas manter fallbacks funcionais em `config.php`.
   - **Justificativa:** Rotinas legadas internas e ferramentas de teste podem executar o código sem configurar previamente todas as variáveis de ambiente. A remoção dos fallbacks causaria falha imediata de inicialização do sistema nesses cenários.

5. **Assinaturas públicas de `lib.php`:**
   - **Decisão:** Preservar rigorosamente a quantidade de parâmetros, nomes, tipagens e tipos de retorno das funções públicas declaradas no `manifest.md`.
   - **Justificativa:** Scripts externos do ISP (rotina noturna, relatório gerencial, integração de faturamento) consom diretamente essas funções via PHP e reflexão.

---

## 4. Checklist de Conformidade com o Manifesto

- [x] `code/config.php`, `code/lib.php` e `code/index.php` alterados *in-place*, sem mover nem renomear arquivos.
- [x] Assinatura pública de `autenticar(mysqli $db, string $usuario, string $senha): ?array` preservada.
- [x] Assinatura e valores exatos de `formatarStatus(int $status): string` preservados (`1 → "Aberto"`, `2 → "Em atendimento"`, `3 → "Resolvido"`).
- [x] Assinatura de `rotuloPrioridade(int $prioridade, ?int $minutos): string` preservada.
- [x] Assinatura de `listarChamados(mysqli $db, string $busca = ''): array` preservada, retornando `tecnico_nome` em cada item.
- [x] Assinatura de `verChamado(mysqli $db, int $id): ?array` preservada.
- [x] Assinatura de `mediaResposta(mysqli $db): float` preservada.
- [x] Assinatura de `exportarCsv(mysqli $db): void` preservada, com cabeçalho `ID,Titulo,Status,Tecnico,Aberto em` e ordenação por `id` crescente.
- [x] Estrutura HTML da tabela de chamados (`id="tabela-chamados"`, colunas `ID, Titulo, Status, Prioridade, Tecnico` e links `index.php?ver=<id>`) preservada.
- [x] Regra de visibilidade estritamente aplicada: clientes visualizam apenas seus próprios chamados; técnicos visualizam todos os chamados.
- [x] Correspondência biunívoca total entre todos os achados de `achados.json` e `RELATORIO.md`.
