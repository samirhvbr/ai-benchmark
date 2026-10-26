# Relatório técnico — LEB-100-A

## Resumo

O painel tinha falhas de autorização que permitiam a clientes consultar chamados de terceiros, além de SQL injection na busca, saída HTML sem escape, segredos embutidos e fragilidades no login e na exportação. Corrigi os fluxos web sem alterar as assinaturas públicas documentadas, os nomes das rotas, o HTML da tabela nem o formato do CSV. Também corrigi a consulta N+1 e o caso de média sem respostas.

## Achados

### F1 — Clientes acessavam chamados de terceiros — alta, confiança 99

**Onde:** `code/index.php`, linhas originais 44–74.

**Mecanismo:** depois do login, a listagem chamava `listarChamados` sem filtrar por `usuario_id`; `verChamado` carregava qualquer ID informado em `?ver=`; e `exportarCsv` exportava todos os registros. A regra do manifesto só permite a um cliente ver chamados que ele abriu.

**Impacto:** um cliente autenticado podia obter títulos, descrições, responsáveis e dados de chamados de outros clientes, inclusive pelo CSV. IDs sequenciais facilitavam enumerar os detalhes.

**Correção:** adicionei consultas de listagem e exportação com escopo de usuário e verificação de propriedade no detalhe. Técnicos continuam vendo todos os chamados. Chamados alheios no detalhe retornam a mesma resposta de “não encontrado”.

### F2 — SQL injection na busca — alta, confiança 99

**Onde:** `code/lib.php`, linhas originais 78–85.

**Mecanismo:** o termo de `busca` era concatenado diretamente em `LIKE '%...%'`. Aspas e fragmentos SQL enviados na URL podiam alterar a consulta executada.

**Impacto:** um usuário autenticado podia manipular os resultados e, conforme permissões e configuração do MySQLi, provocar consultas não previstas.

**Correção:** a busca agora usa statement preparado e bind do padrão. O filtro continua sendo por título e conserva a busca parcial.

### F3 — Segredos de produção no código — alta, confiança 99

**Onde:** `code/config.php`, linhas originais 11–15.

**Mecanismo:** a senha do banco e uma chave da central de e-mail estavam fixas no arquivo-fonte; a senha do banco também era usada como fallback quando `DB_PASS` não estava definido.

**Impacto:** qualquer cópia do código ou backup exposto revela credenciais reutilizáveis. O fallback também mantém o segredo ativo em implantações sem configuração de ambiente.

**Correção:** removi os valores fixos. `DB_PASS` e `SMTP_API_KEY` agora vêm do ambiente e ficam vazios se não configurados. A implantação deve definir essas variáveis.

### F4 — XSS refletido na busca — média, confiança 99

**Onde:** `code/index.php`, linhas originais 79–82.

**Mecanismo:** o valor da busca era inserido sem escape no atributo `value` e no texto “Resultados para”. Uma URL de busca criada com marcação HTML/JavaScript podia executar código no navegador de quem a abrisse autenticado.

**Impacto:** execução de script na origem do painel, podendo agir com a sessão da vítima e ler dados que ela própria tem permissão para consultar.

**Correção:** os dois usos agora passam por `htmlspecialchars` com `ENT_QUOTES`, `ENT_SUBSTITUTE` e UTF-8. Valores de query que não sejam strings são descartados.

### F5 — Senhas armazenadas com MD5 — alta, confiança 100

**Onde:** `code/lib.php`, linha original 15; formato da coluna em `code/schema.sql`, linha original 7.

**Mecanismo:** a autenticação comparava diretamente `MD5(senha)` com hashes sem salt. MD5 é rápido e permite testar grandes volumes de senhas offline após vazamento do banco; as senhas de exemplo são fracas.

**Impacto:** um vazamento do banco pode levar à recuperação de senhas e ao acesso a contas que reutilizem essas credenciais.

**Correção parcial no pacote:** a autenticação agora aceita hashes de `password_hash` e atualiza um MD5 legado após login válido quando a coluna comporta o novo hash. `schema.sql` usa `VARCHAR(255)`. **Em bancos existentes com `CHAR(32)`, é necessário executar antes a migração `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL;`; até isso ocorrer, hashes antigos continuam no formato MD5.** Não executei DDL em uma base de produção.

### F6 — Fixação de sessão no login — média, confiança 90

**Onde:** `code/index.php`, linhas originais 15–27.

**Mecanismo:** a sessão anônima era iniciada antes do login e o ID não era trocado quando a autenticação tinha sucesso. Se o atacante conseguisse fazer a vítima usar um ID de sessão conhecido, poderia reaproveitá-lo após o login.

**Impacto:** possível sequestro de uma sessão autenticada em instalações que aceitem o ID fornecido pelo atacante.

**Correção:** ativei o modo estrito de sessão, configurei os atributos `HttpOnly`, `SameSite=Lax` e `Secure` sob HTTPS, e regenero o ID após autenticação bem-sucedida.

### F7 — CSV persistido em arquivo compartilhado — média, confiança 95

**Onde:** `code/lib.php`, linhas originais 125–150.

**Mecanismo:** cada exportação sobrescrevia `/var/www/painel/tmp/chamados.csv` e depois lia esse mesmo caminho. Requisições concorrentes podiam sobrescrever o arquivo entre gravação e leitura; o conteúdo sensível também permanecia no disco após a resposta.

**Impacto:** respostas concorrentes podiam receber conteúdo de outra exportação, e o arquivo persistente ampliava a exposição caso o diretório fosse acessível por outro processo ou pela configuração web.

**Correção:** o CSV agora é consultado e escrito diretamente em `php://output`, sem arquivo compartilhado. A rota aplica o escopo de visibilidade de F1.

### F8 — Divisão por zero sem respostas registradas — média, confiança 100

**Onde:** `code/lib.php`, linha original 116.

**Mecanismo:** quando a consulta não retornava nenhum `minutos_resposta`, `qtd` permanecia zero e `$soma / $qtd` causava divisão por zero.

**Impacto:** em PHP 8, a página de listagem podia terminar com `DivisionByZeroError` em uma base nova ou sem respostas.

**Correção:** `mediaResposta` agora retorna `0.0` quando não há linhas; a assinatura e o tipo de retorno permanecem iguais.

### F9 — Consulta N+1 para nomes de técnicos — média, confiança 99

**Onde:** `code/lib.php`, linhas originais 88–90 (com a consulta por técnico em 69–71).

**Mecanismo:** a listagem fazia uma consulta adicional a `usuarios` para cada chamado, chamando `tecnicoNome` dentro do laço.

**Impacto:** a página executava 1+N consultas para N chamados, elevando latência e carga do banco conforme a lista crescia. O export tinha o mesmo padrão.

**Correção:** listagem e exportação usam `LEFT JOIN` para obter o nome do técnico na própria consulta.

## Decisões

- Mantive as assinaturas de `autenticar`, `listarChamados`, `verChamado`, `mediaResposta` e `exportarCsv`, e preservei a conexão `mysqli`. As rotas `busca`, `ver` e `export=csv`, o cabeçalho/colunas/ordem do CSV, os rótulos de status e a estrutura da tabela também foram mantidos.
- As funções públicas de listagem, detalhe e exportação continuam podendo retornar todos os chamados quando chamadas por consumidores internos, como relatórios e rotinas noturnas. A filtragem por usuário fica nos novos caminhos usados pelas rotas web, mantendo o contrato dos consumidores existentes enquanto aplica a regra de visibilidade no painel.
- Mantive a verificação de hashes MD5 para contas antigas como ponte até que a coluna possa ser migrada; sem isso, os usuários atuais perderiam acesso antes de fazer login e converter seu hash. A atualização depende de ampliar a coluna nos bancos já existentes, conforme F5; não fiz alteração direta em banco.
- Não removi a constante `EXPORT_DIR` da configuração, embora o painel não a use mais, para não afetar outros scripts que incluam `config.php`.
