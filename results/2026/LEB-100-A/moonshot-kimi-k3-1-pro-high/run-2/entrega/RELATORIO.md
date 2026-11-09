# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas graves de isolamento entre clientes, injeção de SQL na busca e XSS refletido, além de credenciais versionadas, senhas com MD5 e problemas na geração do CSV. Também havia consultas N+1 e uma divisão por zero que derrubava a listagem sem chamados respondidos.

Foram corrigidos dez achados sem renomear arquivos, trocar o mysqli ou alterar as assinaturas públicas, rotas, rótulos de status, estrutura declarada da tabela e cabeçalho/ordenação do CSV. As linhas citadas abaixo são as da numeração original recebida.

## Achados

### F1 — Cliente pode listar, abrir e exportar chamados de outros clientes

- **Onde:** `code/index.php`, linhas originais 44–45, 52–53 e 72–74.
- **Mecanismo:** depois do login, as três rotas chamavam consultas globais usando apenas a conexão. Nenhuma comparava `chamados.usuario_id` com `$_SESSION['uid']`; assim, um cliente autenticado via todos os IDs na listagem, acessava qualquer ID por `?ver=` e baixava a base completa por `?export=csv`.
- **Impacto:** violação direta da regra de visibilidade e exposição de títulos, descrições, responsáveis e datas de chamados de terceiros.
- **Severidade:** crítica. **Confiança:** 100.
- **Correção:** a listagem e o detalhe agora aplicam o dono da sessão para clientes; técnicos continuam vendo tudo. A rota de exportação usa `exportarCsvVisivel`, que filtra o `usuario_id` no SQL para clientes. A função pública `exportarCsv(mysqli $db): void` permanece com a mesma assinatura e comportamento global para a rotina batch.

### F2 — Busca por título concatenada na SQL permite injeção

- **Onde:** `code/lib.php`, linhas originais 78–85.
- **Mecanismo:** `listarChamados` inseria `$busca` diretamente entre aspas em `LIKE '%...%'` e executava o texto com `query`. Um termo contendo aspas e operadores SQL alterava a consulta; por ser uma rota disponível a qualquer usuário autenticado, inclusive cliente, payloads com `UNION` podiam expor colunas de outras tabelas compatíveis com a projeção.
- **Impacto:** leitura não autorizada de dados do banco e alteração arbitrária do filtro, com confidencialidade adicional já comprometida pela ausência de isolamento.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** a busca usa `prepare`, `bind_param` e um parâmetro `'%termo%'`. Os curingas `LIKE`, a ordenação por `criado_em DESC` e o retorno com `tecnico_nome` foram preservados.

### F3 — Termo de busca é refletido no HTML sem escape

- **Onde:** `code/index.php`, linhas originais 79–82.
- **Mecanismo:** o valor de `GET['busca']` era concatenado tanto no atributo `value` do formulário quanto no parágrafo de resultados. Um link com uma aspa e marcação HTML podia sair do atributo e injetar script; marcação no parágrafo também era interpretada pelo navegador.
- **Impacto:** execução de JavaScript no contexto autenticado do painel, permitindo ler a página e agir com a sessão da vítima.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** o mesmo termo é escapado com `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` nos dois pontos, sem mudar o parâmetro `busca` nem a estrutura do formulário.

### F4 — Senhas armazenadas como MD5 sem sal

- **Onde:** `code/lib.php`, linhas originais 13–20; esquema original em `code/schema.sql`, linha 7.
- **Mecanismo:** `autenticar` calculava `md5($senha)` e comparava o resumo diretamente no banco, cuja coluna tinha somente 32 caracteres. MD5 é rápido, não usa sal e permite testar grandes volumes de candidatos após vazamento da tabela; senhas iguais também produziam o mesmo valor.
- **Impacto:** recuperação offline de senhas e possível reutilização das credenciais em outros serviços.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** contas atuais continuam entrando pelo MD5 legado, mas um login válido grava imediatamente `password_hash(..., PASSWORD_DEFAULT)`. Hashes modernos usam `password_verify` e `password_needs_rehash`, e o retorno continua contendo apenas `id`, `nome` e `papel`. `schema.sql` agora admite 255 caracteres. **Na implantação sobre um banco existente, é necessário aplicar antes `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`**; o seed mantém as credenciais conhecidas e exercita a migração.

### F5 — Senha do banco e chave SMTP embutidas no código

- **Onde:** `code/config.php`, linhas originais 8–15.
- **Mecanismo:** a senha de produção era usada como fallback quando `DB_PASS` não existia e a chave SMTP era uma constante literal. Qualquer pessoa com acesso ao pacote ou a uma cópia do versionamento obtinha os segredos, sem possibilidade de rotação por ambiente.
- **Impacto:** acesso ao banco ou ao serviço de e-mail caso as credenciais ainda sejam válidas, além de propagação do segredo a cada cópia do código.
- **Severidade:** alta. **Confiança:** 100.
- **Correção:** ambos vêm exclusivamente de `DB_PASS` e `SMTP_API_KEY`, sem valores secretos de fallback. Os valores expostos devem ser revogados/rotacionados e as variáveis configuradas no ambiente antes do deploy.

### F6 — Login não renova o identificador da sessão

- **Onde:** `code/index.php`, linhas originais 20–27.
- **Mecanismo:** após autenticar, o código gravava `uid` e `papel` na mesma sessão anônima. Em uma configuração PHP que aceite um ID fornecido previamente, um identificador conhecido/fixado antes do login continuava válido depois da elevação para sessão autenticada.
- **Impacto:** sequestro da sessão autenticada sem conhecimento da senha.
- **Severidade:** média. **Confiança:** 95.
- **Correção:** `session_regenerate_id(true)` é chamado no login bem-sucedido, antes de gravar a identidade, invalidando o ID anterior. O redirecionamento e os dados de sessão existentes foram mantidos.

### F7 — CSV usa arquivo fixo compartilhado em diretório web

- **Onde:** `code/lib.php`, linhas originais 123–150.
- **Mecanismo:** toda exportação abria `/var/www/painel/tmp/chamados.csv` com modo `w`, escrevia e depois relia o mesmo caminho. Duas requisições podiam truncar ou misturar o conteúdo uma da outra; por ficar sob a raiz web, o arquivo também podia ser recuperado diretamente conforme a configuração do servidor. Falhas de abertura/consulta ainda retornavam silenciosamente e podiam deixar o recurso aberto.
- **Impacto:** vazamento entre usuários, resposta CSV incompleta/corrompida e exposição residual do último arquivo exportado.
- **Severidade:** alta. **Confiança:** 95.
- **Correção:** o CSV é transmitido diretamente para `php://output`, sem arquivo intermediário. Cabeçalhos HTTP, cabeçalho exato do CSV e ordenação por ID foram preservados; os usos de `fputcsv` recebem escape explícito vazio, evitando também a depreciação do PHP 8.4.

### F8 — Campos de texto do CSV permitem injeção de fórmula

- **Onde:** `code/lib.php`, linhas originais 136–144.
- **Mecanismo:** título e nome do técnico iam do banco diretamente para `fputcsv`. O escape CSV protege a estrutura do arquivo, mas não impede que Excel/LibreOffice interpretem como fórmula um valor iniciado por `=`, `+`, `-` ou `@`, inclusive após espaços.
- **Impacto:** ao abrir uma exportação contendo texto controlado por outra pessoa, o operador pode executar uma fórmula que altera dados ou exfiltra conteúdo conforme os recursos da planilha.
- **Severidade:** média. **Confiança:** 90.
- **Correção:** textos perigosos recebem prefixo de apóstrofo antes de `fputcsv`. ID, status, data, cabeçalho e demais valores permanecem no mesmo formato.

### F9 — Listagem e exportação fazem uma consulta extra por chamado

- **Onde:** `code/lib.php`, linhas originais 64–92 e 132–144.
- **Mecanismo:** depois de buscar N chamados, cada iteração chamava `tecnicoNome`, que executava um novo `SELECT` pelo técnico. Uma listagem ou exportação gerava N+1 consultas e aumentava latência e carga linearmente com o volume.
- **Impacto:** degradação do painel e do banco em bases grandes; a exportação noturna também ficava mais lenta.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** listagem e exportação usam `LEFT JOIN usuarios` e `COALESCE(t.nome, '-')`, produzindo `tecnico_nome` na consulta principal, inclusive para chamados sem técnico. `tecnicoNome` foi mantido para não remover uma função que possa ter consumidores legados.

### F10 — Média divide por zero quando não há respostas

- **Onde:** `code/lib.php`, linhas originais 107–117.
- **Mecanismo:** `mediaResposta` contava apenas linhas com `minutos_resposta` não nulo, mas retornava sempre `$soma / $qtd`. Em banco vazio ou com todos os chamados aguardando resposta, `$qtd` era zero e o PHP lançava `DivisionByZeroError`, interrompendo a listagem.
- **Impacto:** indisponibilidade da página principal justamente em instalações novas ou períodos sem atendimento respondido.
- **Severidade:** média. **Confiança:** 100.
- **Correção:** o banco calcula `COALESCE(AVG(minutos_resposta), 0)` e a função converte o resultado para `float`. A média continua em minutos e ignora valores nulos; o conjunto vazio retorna `0.0`.

## Decisões

- **Contrato preservado:** não mudei nomes/assinaturas das sete funções públicas, parâmetros `busca`, `ver` e `export`, textos de `formatarStatus`, colunas/ID da tabela, links de detalhe ou cabeçalho e ordenação do CSV.
- **Comportamento legado não ambíguo:** `formatarStatus` continua devolvendo `Resolvido` para valores fora de 1–3 e `rotuloPrioridade` não foi alterado. O manifesto define apenas os três status e não especifica outro rótulo para esses casos; mudá-los seria uma suposição com risco para relatórios externos.
- **Compatibilidade de consumidores:** `tecnicoNome` e a constante `EXPORT_DIR` permanecem, embora o fluxo interno não precise mais deles. Removê-los não melhoraria a correção e poderia afetar código fora do pacote.
- **Indicador global:** `mediaResposta(mysqli $db)` continua calculando a média global. Seu contrato não recebe usuário e o manifesto a descreve como indicador geral; restringi-la silenciosamente mudaria relatórios existentes.
- **Sem reescrita ou dependências:** foram mantidos PHP, mysqli, estrutura de arquivos, seed e fluxo de telas. Não adicionei bibliotecas nem novas camadas.

## Validação

- `php -l` passou para `config.php`, `index.php` e `lib.php` com PHP 8.4.26.
- Testes diretos confirmaram os três rótulos de status, a neutralização de fórmulas e o número de parâmetros das sete funções públicas por reflexão.
- Revisão estática confirmou filtro de dono nas três rotas, JOIN para `tecnico_nome`, parâmetros preparados na busca/exportação e cabeçalho CSV inalterado.
- Não havia suíte de testes no pacote. O servidor MariaDB local recusou as credenciais disponíveis, portanto não executei testes integrados nem alterei bancos existentes. Antes do deploy, devem ser testados login/migração de hash, acesso de Ana e Bruno aos próprios chamados, acesso irrestrito de Carla/Diego e os quatro formatos de rota contra um banco de homologação.
