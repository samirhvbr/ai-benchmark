# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas confirmadas de isolamento de clientes, SQL injection e XSS, além de senhas e segredos inseguros. A entrega implementa correções para onze dos doze achados; o risco de fórmulas em planilhas fica reportado sem alterar os valores exportados. A migração de senhas é gradual e depende de ampliar a coluna no banco existente; remover segredos do fonte não revoga credenciais já expostas.

Foram alterados in-place `code/config.php`, `code/index.php`, `code/lib.php`, `code/schema.sql` e `code/seed.sql`. `manifest.md` e `TAREFA.md` permanecem intactos. As localizações abaixo usam exclusivamente a numeração original recebida, como exige a tarefa. A confiança mede a realidade do problema, não sua frequência em produção.

## Achados, em ordem de prioridade

### F1 — SQL injection no filtro de título

Local original: `code/lib.php:80–85`. Categoria: **seguranca**. Severidade: **critica**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** A busca é concatenada dentro de LIKE; o termo `' OR 1=1 -- ` (com espaço após os dois hífens) encerra o literal SQL e altera a condição. UNION com o número adequado de colunas pode ler outras tabelas acessíveis à conexão.

**Impacto.** Um usuário autenticado pode manipular os resultados e extrair dados, inclusive hashes de usuarios, dentro dos privilégios da conta do banco.

**Decisão e implementação.** LIKE parametrizado com mysqli::prepare e bind_param. O termo continua entre % e os curingas existentes continuam válidos; a busca não é concatenada no SQL.

### F2 — Cliente acessa chamados de outros proprietários

Local original: `code/lib.php:80–85`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** A sessão contém uid/papel, mas as consultas de listagem, detalhe e exportação ignoram usuario_id. Ana consegue listar/exportar os chamados 103/104 e abrir ver=103, pertencentes a Bruno. A média também agrega chamados de todos.

**Impacto.** Vazamento entre clientes de títulos, descrições, atendimento e dados exportados; o indicador inclui dados de outros proprietários.

**Decisão e implementação.** Filtro central por usuario_id para sessões de cliente, aplicado a lista, busca, detalhe, média e CSV. Técnicos continuam com acesso global; sessão inválida não recebe dados. Integrações internas sem contexto de sessão preservam o acesso global legado.

Locais adicionais originais: `code/lib.php:98–101` (detalhe), `109` (média), `132–145` (CSV) e `code/index.php:38–45` (contexto identificado, mas não usado).

### F3 — XSS refletido na busca

Local original: `code/index.php:79–82`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** busca é colocada sem escape no atributo value e no parágrafo de resultados. Aspas permitem sair do atributo e tags HTML fornecidas pelo usuário são interpretadas pelo navegador.

**Impacto.** Um link com busca maliciosa pode executar JavaScript na origem do painel quando um usuário autenticado o abre, ler o conteúdo acessível e realizar ações com sua sessão.

**Decisão e implementação.** Escape somente na saída com htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE e UTF-8. O valor original continua sendo usado na consulta; o mesmo escape explícito é aplicado a título, descrição e técnico.

### F4 — Segredos de produção incorporados à configuração

Local original: `code/config.php:11–15`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** DB_PASS tem fallback com senha de produção e SMTP_API_KEY é uma chave literal. A distribuição ou leitura do fonte revela ambos, mesmo sem acesso ao ambiente de implantação.

**Impacto.** Possibilidade de uso da conta do banco ou do serviço SMTP se as credenciais expostas estiverem ativas e os serviços forem alcançáveis. A exposição no fonte é certa; validade e alcance externos não foram verificados.

**Decisão e implementação.** Valores obtidos do ambiente, sem fallback secreto; valores vazios e a string 0 são tratados sem substituição por senha embutida. Rotação/revogação dos segredos antigos exige ação operacional externa.

### F5 — Senhas armazenadas com MD5 sem salt e sem custo

Local original: `code/lib.php:15–19`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** autenticar calcula md5(senha) e compara com usuarios.senha, limitado a CHAR(32). Hash rápido e determinístico permite testar candidatos offline e reconhecer senhas iguais.

**Impacto.** Extração da tabela facilita descobrir senhas fracas e comprometer contas; a coluna de 32 caracteres impede guardar password_hash sem migração.

**Decisão e implementação.** VARCHAR(255) no schema e seed com hashes adaptativos. Autenticação verifica hashes novos com password_verify e aceita MD5 legado, migrando após login válido quando a coluna já foi ampliada. Prefixo sha256: identifica pré-hash da senha completa antes de password_hash, evitando truncagem bcrypt em 72 bytes. CHAR(32) continua autenticando sem gravar hash truncado; falha na migração opcional não invalida login válido.

Local adicional original: `code/schema.sql:7`. O seed mantém ana/bruno com senha123 e carla/diego com tecmaster. SHA-256 serve apenas para codificar a senha completa antes do hash adaptativo; não substitui salt e custo. Atualização compara também o hash anterior, evitando sobrescrever troca de senha concorrente. Hashes bcrypt comuns também são aceitos, sem aceitar sufixos além do limite de 72 bytes.

### F6 — CSV compartilhado sofre corrida e persiste dados exportados

Local original: `code/lib.php:125–150`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** Todas as requisições abrem EXPORT_DIR/chamados.csv com w. Uma pode truncar ou substituir o arquivo enquanto outra escreve ou antes de seu readfile. O arquivo permanece em /var/www/painel/tmp; eventual leitura direta HTTP depende da configuração do servidor.

**Impacto.** Downloads podem sair misturados, incompletos ou com dados de outra exportação. Depois de restringir clientes, a corrida poderia voltar a vazar chamados de outro proprietário; persiste também uma cópia dos dados em disco.

**Decisão e implementação.** Consulta de chamados visíveis e emissão exclusiva na resposta com php://output, sem caminho compartilhado. Mantidos nome do download, colunas, rótulos e ordem por id; recursos fechados em finally. Arquivos de exportações antigas precisam ser removidos na implantação.

### F7 — Login não renova o identificador de sessão

Local original: `code/index.php:15–25`. Categoria: **seguranca**. Severidade: **alta**. Confiança: **95/100**. Estado: **correção implementada**.

**Mecanismo.** session_start abre a sessão pré-login e o sucesso apenas acrescenta uid/papel. Se um adversário conseguir fixar um identificador previamente conhecido no navegador da vítima, o mesmo identificador continua autenticado depois do login.

**Impacto.** Quem conhece a sessão fixada pode assumir a identidade da vítima. A exploração depende de conseguir entregar/fixar o identificador; a ausência de regeneração é confirmada.

**Decisão e implementação.** session_regenerate_id(true) após autenticação e antes de guardar a identidade. Sessões somente por cookie, strict mode, HttpOnly e SameSite=Lax; Secure quando o servidor sinaliza HTTPS.

### F8 — Média de resposta falha quando não há respostas

Local original: `code/lib.php:109–116`. Categoria: **bug**. Severidade: **media**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** A consulta pode não devolver nenhuma linha, por banco vazio ou todos os minutos_resposta serem NULL. qtd permanece zero e soma/qtd provoca divisão por zero; index chama essa função antes de renderizar a listagem.

**Impacto.** A listagem deixa de funcionar em instalação nova ou para conjunto sem resposta. O laço também transfere todos os tempos ao PHP para calcular um único agregado.

**Decisão e implementação.** COALESCE(AVG(minutos_resposta + 0e0), 0), devolvido como float, com o mesmo escopo de visibilidade. O literal exponencial força cálculo em ponto flutuante e evita o arredondamento DECIMAL de AVG(INT), preservando a precisão anterior.

### F9 — Parâmetros HTTP em forma de array interrompem a página

Local original: `code/index.php:22`. Categoria: **bug**. Severidade: **media**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** PHP transforma login[]=x, senha[]=x e busca[]=x em arrays. O index passa esses valores às funções tipadas como string, causando TypeError. ver[]=x também sofre coerção de array para inteiro em vez de representar um identificador válido.

**Impacto.** Requisições malformadas provocam erro de execução; ver em forma de array pode consultar um ID diferente do solicitado. Não há evidência de indisponibilidade persistente para outros usuários.

**Decisão e implementação.** Validação de valores string na fronteira HTTP: login/senha malformados voltam ao formulário; busca/ver em forma de array recebem HTTP 400. Falhas não tratadas no ponto de entrada são registradas no servidor e devolvem mensagem genérica, sem detalhes de SQL.

Locais adicionais originais: `code/index.php:53` e `72–73`. Parâmetros escalares e os caminhos válidos do manifesto continuam funcionando.

### F10 — Listagem e exportação fazem consultas N+1

Local original: `code/lib.php:87–90`. Categoria: **performance**. Severidade: **media**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** Para cada chamado com tecnico_id, listarChamados e exportarCsv chamam tecnicoNome, que executa SELECT em usuarios. Técnicos repetidos são consultados novamente; o custo cresce com a quantidade de chamados além da consulta principal.

**Impacto.** Mais viagens ao banco e maior latência/carga para listagens e exportações grandes.

**Decisão e implementação.** LEFT JOIN com usuarios e COALESCE(nome, '-') nas duas consultas. Mantidos chamados sem técnico, a chave tecnico_nome e tecnicoNome para consumidores existentes. Colunas numéricas da listagem são convertidas a texto para preservar o retorno legado padrão de mysqli::query.

Locais adicionais originais: `code/lib.php:64–71` e `136–137`. O LEFT JOIN não filtra o papel do usuário relacionado, preservando o comportamento anterior do técnico associado. Detalhe mantém o protocolo textual de mysqli::query; o ID é int, portanto sua concatenação não é a vulnerabilidade de F1.

### F11 — CSV pode ser interpretado como fórmula em planilhas

Local original: `code/lib.php:138–144`. Categoria: **seguranca**. Severidade: **media**. Confiança: **85/100**. Estado: **reportado; não alterado**.

**Mecanismo.** fputcsv separa e escapa campos CSV, mas não neutraliza fórmulas. Um título ou nome iniciado por =, +, - ou @ pode ser interpretado como fórmula ao abrir o arquivo em determinadas planilhas. O pacote não contém a rota que grava esses textos, então a possibilidade de inserção depende de outros consumidores.

**Impacto.** Um valor inserido por fonte não confiável pode alterar cálculos ou disparar comportamentos externos na planilha que abre o CSV; o efeito depende do software e de suas configurações.

**Decisão e implementação.** Reportado, sem prefixar ou substituir os valores. Neutralização altera o texto exportado usado nas integrações; uma exportação específica para planilhas exige decisão sobre esse contrato. Aspas CSV, isoladamente, não corrigem a interpretação como fórmula.

### F12 — Cabeçalho CSV não corresponde aos bytes declarados

Local original: `code/lib.php:130`. Categoria: **bug**. Severidade: **baixa**. Confiança: **100/100**. Estado: **correção implementada**.

**Mecanismo.** fputcsv coloca o campo Aberto em entre aspas por conter espaço, produzindo ID,Titulo,Status,Tecnico,"Aberto em". O manifesto exige a linha literal ID,Titulo,Status,Tecnico,Aberto em.

**Impacto.** Consumidor que valida o cabeçalho por comparação exata rejeita a exportação, embora um parser CSV normalmente interprete as mesmas cinco colunas.

**Decisão e implementação.** Cabeçalho escrito como literal exato e newline; linhas de dados continuam usando fputcsv com escape explícito vazio, preservando aspas, vírgulas, novas linhas e barras nos campos.

## Validação

Validação executada com PHP 8.4.26, mysqli/mysqlnd e MariaDB 11.8.6 em instância isolada, sem rede de banco e sem acessar dados de produção. Os três arquivos PHP passaram em `php -l`.

Passaram 18 grupos de testes PHP e 8 grupos HTTP. Eles verificaram assinaturas por reflection, rótulos de status e limites de prioridade/SLA, as quatro credenciais do seed, visibilidade de cliente/técnico, sessão inválida, busca normal e curingas, SQL injection, XSS refletido, parâmetros em array, troca de ID/cookies de sessão, cabeçalho/ordem/escape CSV, senhas longas, migração MD5 e autenticação em schema antigo CHAR(32). A média foi verificada com respostas, todas NULL e conjunto vazio. A listagem manteve quantidade constante de consultas com 5 e 105 chamados; a exportação tem uma única consulta principal por inspeção do código.

Também passaram 24 exportações concorrentes entre perfis usando servidor PHP com quatro workers. Em 19 comparações estritas de retornos originais e alterados, valores, chaves e tipos foram preservados para o contexto das integrações sem sessão. A falha simulada do banco retornou HTTP 500 com mensagem genérica. Após o último ajuste, quatro testes adicionais com MYSQLI_REPORT_OFF confirmaram que falhas de prepare/execute da atualização e de consulta de metadata preservam login válido, rejeitam senha incorreta e registram a falha; a migração normal também passou. Os serviços de teste foram encerrados; os scripts e resultados dessa execução estão em `/tmp/leb-validation.8kVwx1/`.

Limite da validação: o banco disponível foi MariaDB, enquanto o schema descreve MySQL 8. A sintaxe usada é compatível com a stack declarada, mas não foi executada uma segunda instância MySQL 8. Não foram simuladas configurações reais de proxy/TLS nem executadas revogações nos serviços externos.

## Implantação em banco existente

Antes de implantar, configurar `DB_PASS` e, quando utilizado, `SMTP_API_KEY` no ambiente e revogar/substituir os valores expostos anteriormente. Os defaults não secretos de host, usuário e banco foram mantidos. Não importar o seed de demonstração em produção.

Executar somente a migração da coluna no banco existente, sem recriar tabelas ou dados:

```sql
ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;
```

O `schema.sql` cria instalações novas com a coluna adequada e inclui essa instrução em comentário para a instalação existente. Antes da ampliação, o login legado funciona e não grava hash truncado. Depois dela, logins válidos migram MD5; usuários que não voltam a entrar exigem campanha de redefinição de senha para eliminar o risco residual. Se a conta do banco não puder atualizar hashes, o login válido é preservado e a falha de migração é registrada.

Remover os `chamados.csv` históricos do diretório antigo de exportação, conforme a política operacional de retenção. O código entregue deixa de criar esses arquivos. Em instalação atrás de proxy HTTPS, o servidor precisa sinalizar HTTPS corretamente para que o cookie use Secure. Essas mudanças externas não foram executadas contra produção.

## Decisões — o que não alterei

**Assinaturas mysqli, rotas, estrutura HTML, rótulos e prioridades/SLA existentes.** São o contrato ou comportamento caracterizado; não há evidência de defeito nos rótulos/prioridades. Mantidos parâmetros busca/ver/export, tabela e colunas na ordem exigida, links de ID, CSV e ordenações.

**Acesso global das funções em integrações internas sem contexto de sessão.** As assinaturas públicas não recebem usuário/papel e são consumidas por rotinas internas. O index exige autenticação; a biblioteca aplica o proprietário quando existe contexto de sessão e nega sessão inválida. Consumidores HTTP novos precisam inicializar contexto autenticado antes de usar a biblioteca.

**Curingas % e _ da busca LIKE e ausência de paginação obrigatória.** Mudar a semântica da busca ou limitar os resultados alteraria consultas e relatórios existentes. Parametrização mantém a busca atual; LEFT JOIN reduz viagens sem truncar resultados.

**Aceitação transitória de MD5 legado e implantação em banco CHAR(32).** Bloquear MD5 invalidaria contas em produção. O código permite ampliar a coluna antes de converter cada conta no login; contas ainda não migradas permanecem vulneráveis. Não é possível reconstruir senha em texto a partir de MD5 para converter todas offline.

**Prefixação dos campos CSV para neutralizar fórmulas (F11).** Adicionaria caracteres aos títulos e nomes usados por integrações. O risco está reportado e precisa de contrato específico para exportação destinada a planilhas.

**Rotação externa dos segredos e remoção de CSVs antigos.** O pacote não fornece acesso autorizado aos serviços de produção nem ao diretório real de implantação. Os fontes não contêm mais os segredos nem geram arquivo compartilhado; revogação e limpeza dos artefatos históricos são passos operacionais necessários.

**TLS/proxy, política de expiração de sessões, rate limiting e novas dependências.** Dependem da infraestrutura e de políticas ausentes do pacote. Secure respeita HTTPS informado pelo servidor, sem confiar em cabeçalhos de proxy enviados pelo cliente; não foram inventadas configurações de produção nem adicionadas bibliotecas.

**Arquitetura geral e schema fora da coluna senha.** O sistema foi evoluído nos mesmos cinco arquivos, sem trocar stack, mover arquivos ou refazer aplicação. Não foram adicionadas restrições de dados sem evidência de violação e sem regra de negócio declarada.
