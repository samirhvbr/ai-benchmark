# Relatorio tecnico - LEB-100-A

## Resumo

O sistema tinha falhas relevantes de seguranca e confiabilidade no fluxo principal (listagem, detalhe, busca e exportacao de chamados). As correcoes aplicadas preservam a superficie publica do `manifest.md` (assinaturas, rotas, HTML principal e formato do CSV), focando em evolucao incremental do legado. Quatro achados foram corrigidos no codigo entregue e dois foram mantidos por risco de compatibilidade, com plano recomendado.

## Achados priorizados

### F1 - Quebra de controle de acesso: cliente conseguia ver chamados de outros clientes

- **Onde (numeracao original):** `code/lib.php:80` a `code/lib.php:84` (`listarChamados` sem filtro por dono), `code/lib.php:100` (`verChamado` por id sem validar dono), `code/lib.php:132` (`exportarCsv` exportando todos).
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confianca:** `98`
- **Mecanismo:** a regra de visibilidade do produto exige que cliente veja apenas seus chamados. No codigo original, as consultas nao filtravam `usuario_id` para sessao de cliente. Assim, qualquer cliente autenticado conseguia listar, abrir detalhe por id e exportar CSV contendo tickets de terceiros.
- **Impacto:** exposicao indevida de dados operacionais e de atendimento entre clientes, com violacao direta da regra de negocio declarada no manifesto.
- **O que fiz:** adicionei `clienteLogadoId()` e passei a aplicar `WHERE usuario_id = ?` quando a sessao ativa for de cliente em `listarChamados`, `verChamado` e `exportarCsv`. Para tecnico (ou sem sessao web), o comportamento legado de visao completa foi mantido.

### F2 - SQL Injection na busca de chamados

- **Onde (numeracao original):** `code/lib.php:82`
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confianca:** `96`
- **Mecanismo:** a busca era concatenada diretamente no SQL (`titulo LIKE '%...%'`) a partir de entrada controlada por usuario (`$_GET['busca']` via `index.php`). Isso permitia injetar operadores SQL para alterar o predicado da consulta.
- **Impacto:** adulteracao da consulta e bypass de filtro, com potencial de ampliar exposicao de dados e causar consultas anormais.
- **O que fiz:** substitui a montagem dinamica por `prepared statements` com `bind_param`, incluindo os cenarios com e sem filtro de busca.

### F3 - XSS refletido no campo/resultado de busca

- **Onde (numeracao original):** `code/index.php:79` e `code/index.php:82`
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confianca:** `95`
- **Mecanismo:** o termo de busca era renderizado em HTML sem escape, tanto no `value` do input quanto no texto de "Resultados para". Um link malicioso com payload HTML/JS executava no navegador da vitima autenticada.
- **Impacto:** execucao de script no contexto da sessao do usuario (sequestro de sessao, acao indevida no painel).
- **O que fiz:** apliquei `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` antes de renderizar o termo em ambas as saidas.

### F4 - Divisao por zero no calculo de media de resposta

- **Onde (numeracao original):** `code/lib.php:116`
- **Categoria:** `bug`
- **Severidade:** `media`
- **Confianca:** `97`
- **Mecanismo:** quando nao existe nenhum chamado com `minutos_resposta` preenchido, `qtd` fica `0` e o codigo fazia `$soma / $qtd`, gerando aviso de runtime e resultado invalido.
- **Impacto:** inconsistencias na exibicao do indicador do painel e ruido operacional por warnings.
- **O que fiz:** tratei falha de consulta e caso `qtd === 0`, retornando `0.0` nesses cenarios.

### F5 - Segredos sensiveis embutidos no codigo-fonte

- **Onde (numeracao original):** `code/config.php:12` e `code/config.php:15`
- **Categoria:** `seguranca`
- **Severidade:** `critica`
- **Confianca:** `72`
- **Mecanismo:** senha de banco (fallback) e chave SMTP estao hardcoded no repositorio. Qualquer vazamento de codigo expoe credenciais reutilizaveis e dificulta rotacao segura.
- **Impacto:** acesso indevido a banco e canal de e-mail transacional, com risco operacional e de dados.
- **O que fiz:** **nao alterei nesta entrega** para evitar quebra de ambiente legado (o fallback pode estar em uso em producao). Registrei plano de migracao em "Decisoes".

### F6 - Hash de senha com MD5 (fraco para autenticacao)

- **Onde (numeracao original):** `code/lib.php:15`
- **Categoria:** `seguranca`
- **Severidade:** `alta`
- **Confianca:** `93`
- **Mecanismo:** MD5 e rapido e sem custo adaptativo; com vazamento da base, hashes sao suscetiveis a brute force/rainbow table, acelerando comprometimento de contas.
- **Impacto:** maior probabilidade de takeover de usuarios apos incidente de dados.
- **O que fiz:** **nao alterei nesta entrega** porque migrar hash requer estrategia de compatibilidade com base existente e fluxos externos que dependem do esquema legado.

## Decisoes (deliberadamente nao alterado)

1. **Nao removi os segredos hardcoded de imediato** (`code/config.php`) para nao interromper o boot em ambiente que ainda dependa de fallback local. Mudanca recomendada: migrar para variaveis de ambiente obrigatorias, rotacionar credenciais e remover valores padrao em janela controlada.
2. **Nao troquei MD5 por `password_hash` diretamente** para evitar lockout geral. Mudanca recomendada: migracao progressiva (aceitar hash antigo no login, rehash no sucesso, e depois descontinuar MD5).
3. **Nao troquei stack/acesso mysqli** nem assinaturas publicas, em conformidade com as restricoes e com o manifesto.
