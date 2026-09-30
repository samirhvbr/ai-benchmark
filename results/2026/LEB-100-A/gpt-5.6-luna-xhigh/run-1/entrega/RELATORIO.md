# Relatório técnico — LEB-100-A

## Resumo

O sistema mantinha as rotas, assinaturas e formatos declarados, mas a camada de
chamados não aplicava a regra de visibilidade por cliente. Havia também entrada
de busca concatenada em SQL, XSS refletido, segredos no código, sessão sem
regeneração após login, uma divisão por zero possível e consultas N+1.

Foram corrigidos os problemas que podiam ser resolvidos sem quebrar o manifesto:
escopo de acesso na listagem, detalhe, média e exportação; consulta parametrizada;
escape de saída; configuração de sessão; externalização de segredos; tratamento
de conjunto vazio/erro; JOIN para técnico; e arquivos temporários exclusivos
por exportação. As assinaturas públicas, os rótulos, as rotas, a tabela HTML e o
formato do CSV foram preservados.

## F1 — Cliente consegue consultar chamados de outro cliente

**Onde:** code/index.php, linhas 52–74 da versão original, nas rotas de
detalhe e listagem chamadas após a sessão ser identificada; a ausência de filtro
está implementada nas consultas originais de code/lib.php, linhas 80–101.

**Mecanismo:** a sessão guardava uid e papel, mas esses valores não eram
passados nem usados nas consultas. listarChamados selecionava todos os
chamados, e verChamado buscava apenas pelo ID. Assim, um cliente autenticado
podia ver a lista completa ou alterar ver para o ID de um chamado pertencente
a outro cliente. A mesma ausência atingia a exportação.

**Impacto, severidade e confiança:** exposição de títulos, descrições, status,
prioridades e responsáveis de outros clientes; severidade **alta**; confiança
**100/100**.

**Ação:** corrigido. Foi criado o escopo interno baseado na sessão ativa:
técnicos continuam vendo todos, clientes recebem c.usuario_id = uid, e
sessões inválidas não recebem registros. O mesmo escopo é aplicado ao detalhe,
à média de resposta e ao CSV. Chamadas legadas sem sessão continuam sem filtro,
preservando o uso dos scripts internos.

## F2 — Injeção SQL na busca por título

**Onde:** code/lib.php, linhas 80–85 da versão original.

**Mecanismo:** o valor inteiro de busca era concatenado no trecho
titulo LIKE '%...%' e enviado diretamente a query. Aspas e outras construções
SQL fornecidas pelo parâmetro podiam alterar a consulta.

**Impacto, severidade e confiança:** um usuário autenticado podia modificar a
consulta de listagem e potencialmente extrair registros além da busca prevista;
severidade **alta**; confiança **100/100**.

**Ação:** corrigido. A busca usa prepare/bind_param e mantém o comportamento de
substring e a ordenação original.

## F3 — XSS refletido no parâmetro busca

**Onde:** code/index.php, linhas 72–83 da versão original.

**Mecanismo:** o valor de GET busca era colocado sem escape no atributo value
do formulário e no texto “Resultados para”. Um valor contendo markup podia
fechar o atributo ou inserir elementos e script no painel.

**Impacto, severidade e confiança:** execução de conteúdo no navegador de um
usuário autenticado; severidade **média**; confiança **100/100**.

**Ação:** corrigido. O parâmetro é aceito apenas como string e é escapado com
ENT_QUOTES e ENT_SUBSTITUTE em UTF-8 antes de cada saída HTML.

## F4 — Credenciais e chave de API embutidas no código

**Onde:** code/config.php, linhas 11–15 da versão original.

**Mecanismo:** a senha de banco era usada como fallback literal e a chave SMTP
era uma constante literal. Qualquer cópia do pacote, log de código ou acesso de
leitura ao arquivo revelava credenciais de produção.

**Impacto, severidade e confiança:** comprometimento potencial do banco e da
integração de e-mail; severidade **alta**; confiança **100/100**.

**Ação:** corrigido. DB_PASS e SMTP_API_KEY agora vêm do ambiente e ficam
vazios quando não configurados, sem fallback secreto. A implantação precisa
fornecer essas variáveis.

## F5 — Fixação de sessão e atributos fracos do cookie

**Onde:** code/index.php, linhas 15–27 da versão original.

**Mecanismo:** a sessão era iniciada com parâmetros padrão e o identificador
não era regenerado depois de uma autenticação bem-sucedida. Um identificador
pré-login aceito pelo servidor poderia continuar válido após o login; além
disso, o cookie não era marcado explicitamente como HttpOnly, SameSite e Secure
quando HTTPS estivesse ativo.

**Impacto, severidade e confiança:** possibilidade de reutilização de sessão
após indução ou fixação do identificador; severidade **média**; confiança
**95/100**.

**Ação:** corrigido. A sessão usa modo estrito, cookie HttpOnly, SameSite=Lax
e Secure conforme HTTPS, e o ID é regenerado com descarte do anterior após
login.

## F6 — Senhas armazenadas e verificadas com MD5

**Onde:** code/lib.php, linhas 13–20 da versão original; o formato legado
também está declarado em code/schema.sql, linha 7.

**Mecanismo:** autenticar calcula md5 da senha e compara o resultado de 32
caracteres com a coluna CHAR(32). MD5 não é função apropriada para senha:
um vazamento do banco permite testar grandes dicionários offline rapidamente.

**Impacto, severidade e confiança:** recuperação de senhas reutilizadas e
posterior acesso às contas; severidade **alta**; confiança **100/100**.

**Ação:** não corrigido nesta entrega. Migrar para password_hash exige mudar a
coluna, migrar os registros e definir uma estratégia de verificação dupla
durante a transição. O esquema, os dados de teste e o comportamento de login
legado foram preservados para não quebrar consumidores nem a implantação atual.

## F7 — Consultas N+1 para o nome do técnico

**Onde:** code/lib.php, linhas 80–90 da versão original na listagem e linhas
132–137 na exportação.

**Mecanismo:** cada chamado retornado fazia uma nova consulta em tecnicoNome.
Uma lista com n chamados gerava uma consulta principal mais n consultas de
usuário; a exportação repetia o mesmo padrão.

**Impacto, severidade e confiança:** latência e carga de banco crescem
linearmente com muitas consultas pequenas; severidade **média**; confiança
**100/100**.

**Ação:** corrigido. A listagem e o CSV obtêm o nome com LEFT JOIN, mantendo a
chave pública tecnico_nome, o hífen para técnico ausente e as ordenações
existentes.

## F8 — Divisão por zero em mediaResposta

**Onde:** code/lib.php, linhas 109–116 da versão original.

**Mecanismo:** quando nenhum chamado possuía minutos_resposta, o contador qtd
permanecia zero e a função calculava soma / qtd. Isso ocorre naturalmente em
uma base nova ou quando todos os chamados aguardam resposta.

**Impacto, severidade e confiança:** erro fatal ao carregar a listagem,
severidade **média**; confiança **100/100**.

**Ação:** corrigido. Consultas sem linhas ou com erro retornam 0.0; com linhas,
a média permanece a mesma.

## F9 — Arquivo compartilhado permite corrida entre exportações

**Onde:** code/lib.php, linhas 125–150 da versão original.

**Mecanismo:** todas as requisições escreviam em
EXPORT_DIR/chamados.csv com modo w e depois liam o mesmo caminho. Duas
requisições simultâneas podiam truncar, sobrescrever ou ler o conteúdo gerado
para outra sessão, inclusive com escopos de clientes diferentes.

**Impacto, severidade e confiança:** CSV inconsistente ou possível exposição
cruzada em uma janela de concorrência; severidade **média**; confiança
**90/100**.

**Ação:** corrigido. Cada exportação usa um arquivo temporário exclusivo,
libera o resultado do banco e remove o arquivo após a leitura. O nome do
download, o cabeçalho e os dados do CSV permanecem iguais.

## F10 — Falhas de banco eram tratadas como resultados válidos

**Onde:** code/lib.php, linhas 16–20, 69–71, 85–89 e 109–112 da versão original; a
exportação também retornava antes de fechar o arquivo nas linhas 132–135.

**Mecanismo:** várias chamadas usavam o retorno de query sem verificar false.
Uma falha de conexão, esquema ou consulta fazia fetch_assoc ser chamado em um
valor inválido, resultando em erro fatal; na exportação, o retorno antecipado
deixava o arquivo aberto.

**Impacto, severidade e confiança:** indisponibilidade da página ou do relatório
em falhas recuperáveis de banco; severidade **média**; confiança **98/100**.

**Ação:** corrigido. As funções retornam lista vazia, null, 0.0 ou hífen
conforme seu contrato quando a consulta falha, e a exportação fecha/libera os
recursos antes de retornar.

## F11 — Fórmula de planilha possível no CSV

**Onde:** code/lib.php, linhas 138–144 da versão original.

**Mecanismo:** fputcsv protege delimitadores e aspas, mas não impede que um
 título vindo do banco e começando por =, +, - ou @ seja interpretado como
 fórmula quando o arquivo for aberto diretamente em uma planilha.

**Impacto, severidade e confiança:** execução de fórmula, alteração visual ou
exfiltração de dados no ambiente da planilha; severidade **média**; confiança
**85/100**.

**Ação:** não corrigido nesta entrega. Prefixar valores mudaria os títulos
exportados e poderia quebrar consumidores que esperam o texto original; a
sanitização deve ser definida junto aos consumidores do CSV ou em uma migração
explícita do formato.

## Decisões

- Não alterei as assinaturas de autenticar, formatarStatus, rotuloPrioridade,
  listarChamados, verChamado, mediaResposta e exportarCsv. O manifesto declara
  essas assinaturas como superfície pública.
- Não troquei mysqli, não renomeei nem movi arquivos e não alterei
  schema.sql/seed.sql. Isso preserva a camada de dados e os dados de teste.
- Mantive os três rótulos de status, os nomes dos parâmetros busca, ver e
  export, a tabela tabela-chamados, a ordem das colunas HTML, a ordenação do
  CSV por ID e seu cabeçalho exato.
- O hash MD5 e a sanitização de fórmulas no CSV ficaram pendentes pelos motivos
  específicos descritos em F6 e F11. São itens de migração, não foram
  silenciosamente descartados.

## Validação

- php -l code/config.php
- php -l code/index.php
- php -l code/lib.php
- Verificação dos rótulos de status contratados.
- Verificação de que os dois segredos literais não permanecem em code/.
