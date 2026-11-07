# Resumo
O sistema legado apresenta vulnerabilidades críticas de injeção de SQL em funções de listagem, visualização e obtenção de nome de técnico, além de exposição de credenciais de e‑mail. As correções implementadas utilizam prepared statements e variáveis de ambiente, preservando a compatibilidade da interface pública.

## Achados

**1. Injeção de SQL em listarChamados**  
- Onde: `code/lib.php`, linha 82.  
- O que é: A query é construída por concatenação de string com o parâmetro de busca, permitindo injeção de SQL.  
- Mecanismo: Falta de parâmetros preparados; o valor do usuário é inserido diretamente na cláusula `LIKE`.  
- Impacto: Um atacante pode manipular a busca para executar comandos SQL arbitrários, comprometendo a integridade dos dados.  
- Severidade: alta.  
- Confiança: 95.  
- Correção: Substituir a concatenação por prepared statement com parâmetro `LIKE`.  
- **corrigido**: true.

**2. Injeção de SQL em verChamado**  
- Onde: `code/lib.php`, linha 100.  
- O que é: Consulta ao banco usando concatenação direta do `id`.  
- Mecanismo: Injeção de SQL permitida por falta de prepared statement.  
- Impacto: Possível execução de comandos SQL arbitrários, levando a divulgação ou alteração de registros.  
- Severidade: alta.  
- Confiança: 95.  
- Correção: Utilizar prepared statement para o parâmetro `id`.  
- **corrigido**: true.

**3. Injeção de SQL em tecnicoNome**  
- Onde: `code/lib.php`, linha 69.  
- O que é: Busca do nome do técnico usando concatenação de `id`.  
- Mecanismo: Injeção de SQL por construção de query sem sanitização.  
- Impacto: Possível execução de SQL injection que pode levar a vazamento ou corrupção de dados de usuários.  
- Severidade: média.  
- Confiança: 90.  
- Correção: Usar prepared statement para o `id` do técnico.  
- **corrigido**: true.

**4. Chave SMTP exposta em código**  
- Onde: `code/config.php`, linha 15.  
- O que é: A chave da API está codificada literalmente no arquivo de configuração.  
- Mecanismo: Exposição de segredo que pode ser lida por qualquer pessoa com acesso ao código.  
- Impacto: Um atacante pode obter a chave e usar o serviço de e‑mail para spam ou outras atividades maliciosas.  
- Severidade: alta.  
- Confiança: 85.  
- Correção: Mover a chave para variável de ambiente com fallback para valor padrão.  
- **corrigido**: true.

## Decisões
- Não alteramos o valor padrão da senha `DB_PASS` nem a forma de geração de hash de senha, pois a compatibilidade com o contrato existente seria impactada. Mantivemos o fallback para evitar que o sistema deixe de funcionar caso a variável de ambiente não esteja definida.  
- As mudanças em `lib.php` (prepared statements) e em `config.php` (uso de `getenv` para `SMTP_API_KEY`) preservam a interface pública e não alteram o comportamento observável, atendendo ao requisito de preservar a superfície pública.  
- O restante do código permanece inalterado, garantindo que a entrega atenda ao critério de “não reescrever o sistema”.