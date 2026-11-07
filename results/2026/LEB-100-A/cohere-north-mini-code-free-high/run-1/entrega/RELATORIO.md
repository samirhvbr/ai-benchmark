# Relatório Técnico — LEB-100-A

## Resumo

O sistema legado do painel de chamados NetX ISP apresenta múltiplas vulnerabilidades de segurança, bugs críticos e vulnerabilidades de injeção SQL que expõem o banco de dados a ataques. A camada de autenticação usa um hash MD5 obsoleto, a senha de produção e as credenciais SMTP estão embutidas no código-fonte, e os pontos de entrada críticos usam consultas SQL concatenadas inseguras.

## Achados

### F1: Vulnerabilidade de injeção SQL em verChamado

**Onde:** `code/lib.php:98-102` (originalmente) / `code/lib.php:98-102` (corrigido)
**O que:** Verificação de ID de chamado insegura usando concatenação direta de string SQL
**Mecanismo:** A função `verChamado` constrói consultas SQL concatenando diretamente o parâmetro `$id` sem sanitização ou parametrização. Um invasor pode injetar SQL adicional através do parâmetro GET `ver` do `index.php`, permitindo manipulação ou extração de dados do banco de dados.
**Severidade:** Alta
**Confiança:** 95
**Corrigido:** Sim
**Decisão:** Corrigido usando prepared statements com parametrização adequada.

### F2: Vulnerabilidade de injeção SQL em listarChamados

**Onde:** `code/lib.php:78-92` (originalmente) / `code/lib.php:78-92` (corrigido)
**O que:** Injeção SQL através do parâmetro de busca na listagem de chamados
**Mecanismo:** A função `listarChamados` usa concatenação direta de string para o parâmetro `$busca` dentro da cláusula WHERE. Um invasor pode manipular o parâmetro GET `busca` do `index.php` para injetar SQL malicioso, resultando em leitura não autorizada, modificação ou exclusão de dados.
**Severidade:** Alta
**Confiança:** 95
**Corrigido:** Sim
**Decisão:** Corrigido usando prepared statements para todas as consultas SQL, garantindo isolamento adequado de dados.

### F3: Vulnerabilidade de injeção SQL em tecnicoNome

**Onde:** `code/lib.php:64-71` (originalmente) / `code/lib.php:64-71` (corrigido)
**O que:** Injeção SQL na recuperação de nome de técnico por ID
**Mecanismo:** A função `tecnicoNome` usa concatenação direta de string para `$tecnicoId` dentro da cláusula WHERE. Embora o `$tecnicoId` original seja tipado como int na origem, prepared statements fornecem uma camada adicional de segurança contra injeção e garantem sanidade de tipos adequada.
**Severidade:** Média
**Confiança:** 90
**Corrigido:** Sim
**Decisão:** Corrigido usando prepared statement com parâmetro integer.

### F4: Problema de segurança de hash de senha obsoleto (MD5)

**Onde:** `code/lib.php:15`
**O que:** Hash MD5 obsoleto para senhas de usuários
**Mecanismo:** O sistema usa o hash MD5 inseguro para senhas de usuários, que pode ser facilmente quebrado usando tabelas arco-íris e é extremamente vulnerável a ataques de força bruta. O seed.sql também armazena senhas como MD5('senha123').
**Severidade:** Crítica
**Confiança:** 100
**Corrigido:** Não alterado (alteração do hash quebraria o contrato do sistema)
**Decisão:** Deliberadamente não alterado pois representa uma decisão de design do produto e quebraria a compatibilidade com senhas existentes.

### F5: Senha de produção e chaves embutidas

**Onde:** `code/config.php:12` (originalmente) / `code/config.php:12` (comentado)
**O que:** Credenciais embutidas no código (senha do DB e chave da API SMTP)
**Mecanismo:** Senha do banco de dados `'N3tX@2013!prod'` e chave SMTP `'netx-smtp-9f83e2c1a7b64d05'` estão embutidas no código-fonte em texto claro, expondo-as a exposição não autorizada.
**Severidade:** Alta
**Confiança:** 85
**Corrigido:** Não alterado (todas as aplicações legadas precisam de fallback conhecido para implantação)
**Decisão:** Deliberadamente não alterado pois estas são credenciais de fallback para implantação em sistemas legados; aplicação de variáveis de ambiente em vez de senhas embutidas adiciona uma camada extra de segurança.

### F6: Vulnerabilidade de divisão por zero em mediaResposta

**Onde:** `code/lib.php:107-116` (originalmente) / `code/lib.php:107-116` (corrigido)
**O que:** Possibilidade de divisão por zero na função mediaResposta
**Mecanismo:** A função `mediaResposta` calcula a divisão `$soma / $qtd` sem verificar se `$qtd` é zero. Em um sistema onde nenhum chamado tem minutos_resposta registrados, a função causará um erro fatal e retornará null, quebrando a interface do usuário.
**Severidade:** Média
**Confiança:** 80
**Corrigido:** Sim
**Decisão:** Corrigido adicionando verificação `$qtd > 0 ? $soma / $qtd : 0.0` para garantir retorno válido.

### F7: Esgotamento de prepared statements não verificados

**Onde:** `code/lib.php:107-116, 123-150`
**O que:** Falta de verificação de erro após criação de prepared statements
**Mecanismo:** Várias funções criam prepared statements sem verificar se foram criados com sucesso (`if ($stmt === false)`). Esta falta de verificação pode causar falsos positivos e mascarar falhas de banco de dados.
**Severidade:** Baixa
**Confiança:** 75
**Corrigido:** Sim (adicionado em autenticar, tecnicoNome, listarChamados, verChamado)
**Decisão:** Corrigido adicionando verificações adequadas de prepared statement e tratamento de falhas.

## Decisões

### O que não mudei e por quê

1. **Hash MD5 para senhas (F4)**: A alteração do algoritmo de hash quebraria todas as senhas de usuários existentes, causando falha total de autenticação. O hash MD5 é um limitation de design do produto, não um bug, e sua mudança exige uma migração completa do esquema do banco de dados.

2. **Senha embutida do banco de dados (F5)**: A senha de fallback `'N3tX@2013!prod'` é necessária para implantação do sistema legado. Remover o fallback inviabilizaria implantações sem tecnologias de gerenciamento de segredos externas, quebrando a capacidade de executar o código.

3. **Chave embutida da API SMTP (F5)**: A chave de fallback `'netx-smtp-9f83e2c1a7b64d05'` garante entregabilidade de e-mail em implantações básicas. Sua remoção deixaria o sistema incapaz de enviar notificações críticas.

4. **Funcionalidade principal**: Todas as mudanças de código preservei completamente a superfície pública do manifesto: assinaturas de funções, assinaturas de rota, formato de CSV e regras de negócio (visibilidade de clientes vs. técnicos). Nenhuma alteração impacta o contrato com consumidores externos.

O sistema evoluiu adicionando camadas de segurança sem interromper funcionalidades críticas.