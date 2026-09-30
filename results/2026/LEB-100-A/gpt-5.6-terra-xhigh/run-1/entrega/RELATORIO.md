# Relatório técnico — LEB-100-A

## Resumo

O painel possuía falhas graves de isolamento entre clientes, injeção SQL e XSS refletido. Corrigi esses fluxos preservando as assinaturas públicas, as rotas, o formato/ordenação do CSV e a estrutura da tabela HTML. Também removi segredos do código, modernizei hashes, eliminei consultas N+1 e tratei base sem respostas.

## F1 — Cliente podia acessar chamados de terceiros

- **Onde (linhas originais):** `code/index.php:44–74`.
- **Mecanismo:** após autenticar, as rotas `export=csv`, `ver=<id>` e a listagem chamavam consultas globais sem comparar `usuario_id` com o `uid` da sessão. Ana (cliente 1), por exemplo, podia abrir `?ver=103` ou baixar os chamados de Bruno (cliente 2).
- **Impacto e severidade:** exposição de títulos, descrições e outros dados de chamados; **crítica**.
- **Confiança:** 99/100.
- **O que fiz:** a rota filtra listagem e CSV por `usuario_id` para `cliente` e oculta detalhes de outro dono. `tecnico` continua vendo todos os chamados. As funções públicas originais mantêm as assinaturas para consumidores administrativos.

## F2 — Busca permitia injeção SQL

- **Onde (linhas originais):** `code/lib.php:80–85`.
- **Mecanismo:** `listarChamados` concatenava `busca` dentro de `LIKE '%...%'`. Uma aspa no termo encerrava o literal SQL e permitia alterar o predicado.
- **Impacto e severidade:** leitura indevida de chamados e possível composição de consultas de leitura com os privilégios da aplicação; **alta**.
- **Confiança:** 99/100.
- **O que fiz:** as buscas global e filtrada usam `mysqli::prepare` e parâmetros ligados. A consulta de detalhe também foi parametrizada.

## F3 — XSS refletido no termo de busca

- **Onde (linhas originais):** `code/index.php:79–82`.
- **Mecanismo:** `busca` era emitida sem escaping no atributo `value` e no parágrafo de resultados. Uma URL com aspa/tag podia injetar HTML ou script.
- **Impacto e severidade:** JavaScript na origem do painel e ações na sessão da vítima; **alta**.
- **Confiança:** 98/100.
- **O que fiz:** normalizei parâmetros escalares e usei `htmlspecialchars` com `ENT_QUOTES | ENT_SUBSTITUTE` e UTF-8 nas duas reflexões.

## F4 — Credenciais de produção estavam embutidas

- **Onde (linhas originais):** `code/config.php:11–15`.
- **Mecanismo:** a senha do banco era fallback literal e a chave SMTP era constante literal. Uma cópia do pacote revelava os dois segredos.
- **Impacto e severidade:** comprometimento potencial do banco e do serviço de e-mail; **alta**.
- **Confiança:** 100/100.
- **O que fiz:** ambos vêm agora de variáveis de ambiente; ausência de `DB_PASS` falha explicitamente. Os valores já expostos devem ser revogados e rotacionados, pois a alteração do código não os invalida.

## F5 — Senhas eram armazenadas e verificadas com MD5

- **Onde (linhas originais):** `code/lib.php:15–19`, `code/schema.sql:7` e `code/seed.sql:4–8`.
- **Mecanismo:** MD5 é rápido, sem salt e determinístico. Uma cópia de `usuarios.senha` permite tentativa offline acelerada e evidencia reutilização de senha.
- **Impacto e severidade:** recuperação prática de senhas fracas e reutilização de credenciais; **alta**.
- **Confiança:** 100/100.
- **O que fiz:** o esquema aceita 255 caracteres, sementes usam bcrypt de `password_hash` e autenticação usa `password_verify`. Um MD5 legado válido é atualizado no primeiro login quando a coluna já suporta bcrypt; a verificação do tamanho impede truncamento em bancos ainda em `CHAR(32)`.

## F6 — Listagem e CSV faziam consultas N+1

- **Onde (linhas originais):** `code/lib.php:69–71`, chamadas nas linhas 89 e 137.
- **Mecanismo:** após buscar chamados, cada linha chamava `tecnicoNome`, disparando outro `SELECT`. Para N chamados, eram 1 + N consultas tanto na tela quanto no CSV.
- **Impacto e severidade:** latência e carga crescem linearmente com a quantidade de chamados; **média**.
- **Confiança:** 100/100.
- **O que fiz:** listagem e exportação usam `LEFT JOIN` e devolvem a mesma chave pública `tecnico_nome`; a função auxiliar isolada também foi parametrizada.

## F7 — Média de resposta dividia por zero

- **Onde (linhas originais):** `code/lib.php:109–116`.
- **Mecanismo:** sem nenhum `minutos_resposta` não nulo, `$qtd` fica zero e `$soma / $qtd` falha.
- **Impacto e severidade:** a página principal não renderiza em uma base nova ou sem primeiras respostas; **média**.
- **Confiança:** 100/100.
- **O que fiz:** uso `COALESCE(AVG(...), 0)` e retorno `0.0` em ausência/erro, preservando o retorno `float`.

## F8 — CSV usava arquivo temporário compartilhado e previsível

- **Onde (linhas originais):** `code/lib.php:125–150`.
- **Mecanismo:** toda requisição sobrescrevia `EXPORT_DIR/chamados.csv` e depois o lia. Requisições concorrentes podiam truncar/substituir o arquivo, que permanecia sob o diretório web configurado.
- **Impacto e severidade:** download inconsistente e possível exposição de CSV persistente; **média**.
- **Confiança:** 96/100.
- **O que fiz:** o CSV é escrito diretamente em `php://output`, sem arquivo persistente, com o mesmo cabeçalho, colunas, rótulos e ordenação contratados.

## Decisões

- Não alterei nomes, assinaturas ou retornos das sete funções públicas. As funções filtradas por cliente são internas; `exportarCsv` continua global para os consumidores administrativos.
- Não alterei rótulos de status, parâmetros de rota, tabela HTML, cabeçalho/ordem do CSV nem as credenciais de exemplo informadas no manifesto. Os quatro logins de teste continuam válidos.
- Não removi `EXPORT_DIR`: a exportação não o usa mais, mas ele pode ser referenciado por automações fora da superfície conhecida.
- Não forcei reset de senha nem `ALTER TABLE` numa requisição. Em base de produção já existente, o DBA deve aplicar `ALTER TABLE usuarios MODIFY senha VARCHAR(255) NOT NULL` antes da migração preguiçosa de hashes; a checagem preserva o login legado até essa etapa.
