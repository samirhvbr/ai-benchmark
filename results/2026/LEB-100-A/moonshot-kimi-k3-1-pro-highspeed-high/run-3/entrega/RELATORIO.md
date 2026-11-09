# Relatório técnico — LEB-100-A

## Resumo

O painel preservava os formatos externos, mas não aplicava a regra de visibilidade por cliente e aceitava entrada de busca diretamente em SQL e HTML. Também continha segredos versionados, autenticação baseada somente em MD5, sessão não renovada no login e exportação CSV por um arquivo fixo compartilhado. Havia ainda divisão por zero no indicador de SLA e consultas N+1 para resolver nomes de técnicos.

Foram entregues 10 correções em `code/`, sem renomear arquivos, trocar mysqli, alterar assinaturas públicas, rotas, rótulos contratados, estrutura da tabela ou cabeçalho/ordenação do CSV. As referências de linha abaixo usam a numeração dos arquivos originais.

## Achados

### F1 — Cliente podia listar, abrir, agregar e exportar chamados de terceiros

- **Local original:** `code/lib.php:78-145`; a média sem escopo estava em `107-116`.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** `listarChamados`, `verChamado` e `exportarCsv` consultavam `chamados` sem qualquer predicado sobre `usuario_id`. Embora `index.php` lesse `uid` e `papel` da sessão, esses valores não limitavam as consultas. Assim, o detalhe por `?ver=<id>` era um IDOR e as rotas de listagem e CSV ignoravam a regra de visibilidade. A média global também explia um agregado de chamados inacessíveis ao cliente.
- **Impacto:** qualquer cliente autenticado podia obter títulos, descrições, situação, responsáveis e datas de chamados de outros clientes, inclusive em massa pelo CSV.
- **Correção:** `clienteAutenticadoId` identifica somente sessões com papel `cliente`; as quatro consultas passaram a exigir `usuario_id` correspondente nesse caso. Técnicos e jobs sem sessão de cliente mantêm a visão geral. Nenhuma assinatura recebeu parâmetros novos.

### F2 — Injeção de SQL na busca por título

- **Local original:** `code/lib.php:80-85`.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** o valor de `$_GET['busca']` chegava a `listarChamados` e era concatenado entre aspas em `WHERE titulo LIKE '%...%'`. Aspas e operadores fornecidos pelo usuário alteravam a instrução, sem prepared statement ou escape.
- **Impacto:** um usuário autenticado podia modificar o filtro e, conforme os privilégios da conta SQL, extrair dados por `UNION`, inferência ou consultas baseadas em erro/tempo.
- **Correção:** a consulta usa `prepare` e `bind_param`; o termo é associado como dado a `c.titulo LIKE ?`. O curinga externo `%...%` é montado fora do SQL sem permitir que o termo altere sua sintaxe.

### F3 — Senhas verificadas e armazenadas apenas com MD5

- **Local original:** `code/lib.php:15-20`, `code/schema.sql:7` e `code/seed.sql:4-8`.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** `autenticar` calculava `md5($senha)` e comparava o hash diretamente no banco. MD5 é rápido, não tem sal por usuário nem custo ajustável; a coluna `CHAR(32)` e os seeds reforçavam esse formato.
- **Impacto:** uma cópia do banco, backup ou dump permitiria testar grandes volumes de candidatas e reutilizar hashes iguais, facilitando a recuperação das senhas e ataques contra contas reutilizadas.
- **Correção:** autenticação agora usa `password_verify` e `password_hash(PASSWORD_DEFAULT)`. Hashes MD5 existentes são aceitos uma única vez no login válido e imediatamente substituídos, evitando bloquear usuários durante a migração; hashes modernos também passam por `password_needs_rehash`. A coluna comporta 255 caracteres e os seeds usam bcrypt. A consulta de login continua preparada e o retorno contém somente `id`, `nome` e `papel`.

### F4 — Senha de produção e chave SMTP embutidas no código

- **Local original:** `code/config.php:8-15`.
- **Categoria / severidade / confiança:** segurança / **alta** / **100**.
- **Mecanismo:** a ausência de `DB_PASS` ativava uma senha real identificada como sendo de produção, e `SMTP_API_KEY` era sempre uma constante literal. Qualquer pessoa com acesso ao pacote obtinha ambos os segredos.
- **Impacto:** vazamento do código ou de um pacote de implantação expunha credenciais reutilizáveis contra banco e serviço de e-mail, sem necessidade de explorar a aplicação.
- **Correção:** `DB_PASS` e `SMTP_API_KEY` vêm exclusivamente do ambiente e ficam vazios se não configurados. Os valores antigos precisam ser revogados/rotacionados no ambiente operacional, pois removê-los do arquivo não desfaz exposições anteriores.

### F5 — XSS refletido no campo e no resultado da busca

- **Local original:** `code/index.php:72-82`.
- **Categoria / severidade / confiança:** segurança / **média** / **100**.
- **Mecanismo:** `$busca` era impresso sem escape tanto dentro de `value="..."` quanto no parágrafo de resultados. Um valor contendo aspas podia sair do atributo e criar elementos ou manipuladores HTML; a segunda impressão também aceitava marcação diretamente.
- **Impacto:** um cliente autenticado induzido a abrir um link de busca manipulado podia executar JavaScript no contexto da sua sessão, com ações e leitura de dados em nome da vítima.
- **Correção:** uma única versão escapada com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` alimenta os dois pontos. Os demais textos vindos do banco também passaram a usar explicitamente os mesmos flags.

### F6 — Sessão não era renovada após autenticação

- **Local original:** `code/index.php:20-27`.
- **Categoria / severidade / confiança:** segurança / **média** / **95**.
- **Mecanismo:** o login gravava `uid` e `papel` no identificador de sessão já existente. Se um atacante conseguisse fazer a vítima autenticar usando um ID conhecido, o mesmo ID permaneceria válido depois do login; a exploração depende das configurações de aceitação de sessão do PHP.
- **Impacto:** sequestro da sessão autenticada e acesso ao perfil da vítima.
- **Correção:** `session_regenerate_id(true)` é chamado imediatamente após autenticação bem-sucedida e antes de gravar a identidade, invalidando o ID anterior.

### F7 — Exportações concorrentes compartilhavam um arquivo temporário fixo

- **Local original:** `code/lib.php:123-150`.
- **Categoria / severidade / confiança:** segurança / **média** / **100**.
- **Mecanismo:** toda execução abria `EXPORT_DIR/chamados.csv` com modo `w`, escrevia e depois relia o mesmo caminho. Duas requisições podiam truncar ou sobrescrever o arquivo entre essas etapas. O `fopen` também seguia links simbólicos e o arquivo permanecia após a resposta.
- **Impacto:** CSV truncado, misturado ou correspondente a outra execução; em um ambiente com escritor local no diretório, havia ainda risco de sobrescrever outro arquivo acessível ao processo web.
- **Correção:** a consulta continua ordenada por `id`, mas `fputcsv` escreve diretamente em `php://output`, depois dos mesmos headers. Não há mais caminho compartilhado, truncamento concorrente nem arquivo residual.

### F8 — Valores do CSV podiam ser interpretados como fórmulas

- **Local original:** `code/lib.php:136-144`.
- **Categoria / severidade / confiança:** segurança / **média** / **95**.
- **Mecanismo:** títulos e nomes eram entregues diretamente ao `fputcsv`. O escape de CSV protege a estrutura de colunas, mas não impede que uma planilha avalie textos iniciados por `=`, `+`, `-`, `@`, tabulação ou quebra de linha como conteúdo ativo.
- **Impacto:** ao abrir a exportação, um operador podia acionar fórmulas inseridas por um chamado malicioso, com risco de alteração de dados ou exfiltração conforme o programa de planilha.
- **Correção:** `valorCsvSeguro` prefixa apóstrofo aos valores textuais com esses caracteres iniciais antes do `fputcsv`. O cabeçalho e as cinco colunas permanecem inalterados.

### F9 — Média de resposta dividia por zero quando não havia dados

- **Local original:** `code/lib.php:107-116`.
- **Categoria / severidade / confiança:** bug / **média** / **100**.
- **Mecanismo:** `$qtd` só aumentava para linhas com resposta. Em banco vazio, ou com todos os `minutos_resposta` nulos, a função executava `$soma / $qtd` com denominador zero; no PHP 8 isso lança `DivisionByZeroError` e impede a listagem.
- **Impacto:** indisponibilidade da página inicial justamente em instalações novas ou sem atendimentos respondidos.
- **Correção:** `AVG(minutos_resposta)` calcula o valor no banco, mantém o filtro de visibilidade e devolve `0.0` quando a agregação é `NULL`. O retorno público continua `float`.

### F10 — Listagem e CSV executavam consultas N+1 para técnicos

- **Local original:** `code/lib.php:64-71`, `88-90` e `132-137`.
- **Categoria / severidade / confiança:** performance / **média** / **100**.
- **Mecanismo:** após buscar todos os chamados, cada iteração chamava `tecnicoNome`, que executava um novo `SELECT nome FROM usuarios`. Uma página com N chamados fazia N+1 consultas; o CSV repetia o padrão para toda a base.
- **Impacto:** latência e carga no MySQL cresciam linearmente com o volume exportado/listado, além do custo de ida e volta por linha.
- **Correção:** listagem e exportação usam um único `LEFT JOIN` com `usuarios` e `COALESCE(t.nome, '-')`, preservando `tecnico_nome`, o hífen para chamado sem técnico e as ordenações originais.

## Decisões — o que não foi alterado

1. **Contratos públicos e navegação:** não mudei nomes, parâmetros, tipos de retorno, parâmetros GET, textos dos três status, colunas da tabela, links de detalhe, cabeçalho CSV ou ordenações. Isso evita quebrar rotinas externas e favoritos existentes.
2. **Semântica dos curingas da busca:** `%` e `_` continuam funcionando como curingas do `LIKE`; somente a interpretação do termo como SQL foi removida. Escapá-los mudaria um comportamento observável não proibido pelo manifesto.
3. **Rótulos de prioridade e fallback de status:** não introduzi novos textos nem reclassifiquei prioridades. O manifesto fixa apenas os status 1–3 e não há evidência suficiente para alterar a regra atual para valores fora do domínio.
4. **`tecnicoNome`:** deixou de ser usado internamente pelos fluxos N+1, mas a função foi mantida para não retirar uma função que outros arquivos possam incluir diretamente.
5. **`EXPORT_DIR`:** a exportação não usa mais arquivo local, mas a constante foi mantida porque configurações ou scripts auxiliares podem referenciá-la. O diretório deixou de ser necessário para `exportarCsv`.
6. **Compatibilidade temporária com MD5:** não foi feita uma quebra imediata que invalidaria todos os hashes existentes. A aceitação é restrita a hashes MD5 válidos no login e termina para cada usuário após a migração automática; removê-la no futuro exige confirmar que não restam hashes legados.
7. **Stack e modelo de dados:** mysqli, PHP, MySQL, chaves estrangeiras e demais regras foram preservados. A única mudança de schema é ampliar `senha` para comportar hashes modernos. Em uma base já existente, a implantação deve executar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` antes da migração automática dos hashes; `schema.sql` já cria instalações novas no formato correto.

## Verificação

- `php -l` aprovado em `config.php`, `lib.php` e `index.php`.
- 18 verificações funcionais com dupla de teste de `mysqli`: login moderno/legado e migração, senha inválida, busca parametrizada, escopo de cliente e técnico, IDOR, média vazia, cabeçalho/conteúdo CSV, fórmulas e ausência de N+1.
- Reflexão confirmou as sete assinaturas do manifesto; `password_verify` confirmou os dois hashes usados no seed.
- Não foi executado teste contra um MySQL real: o serviço local recusou tanto a conta do projeto quanto a conta administrativa disponível. Nenhuma credencial foi alterada e nenhum banco foi modificado para contornar isso.
