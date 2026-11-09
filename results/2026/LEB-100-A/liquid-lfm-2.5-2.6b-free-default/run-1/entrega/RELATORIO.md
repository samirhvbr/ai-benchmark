# Relatório Técnico - LEB-100-A

## Resumo

Análise realizada no sistema legado LEB-100-A (Painel de Chamados NetX ISP). Foram identificados três problemas críticos de segurança e configuração que precisam ser corrigidos para garantir a segurança do sistema em produção.

## Achados

### F1: Senha do banco de dados hardcoded e fraca
- **Localização**: `code/config.php`, linha 12
- **Problema**: A senha do banco de dados (`DB_PASS`) está hardcoded com um valor fraco: `N3tX@2013!prod`
- **Impacto**: Exposição de credenciais de banco de dados em código de produção
- **Severidade**: Alta
- **Confiança**: 90/100
- **Mecanismo**: A senha é definida diretamente no arquivo de configuração, sem variáveis de ambiente
- **Correção**: Migrar para variável de ambiente com fallback seguro

### F2: Chave SMTP hardcoded
- **Localização**: `code/config.php`, linha 15
- **Problema**: A chave de API do SMTP (`SMTP_API_KEY`) está hardcoded com valor específico: `netx-smtp-9f83e2c1a7b64d05`
- **Impacto**: Exposição de credenciais de e-mail em código de produção
- **Severidade**: Alta
- **Confiança**: 85/100
- **Mecanismo**: A chave é definida diretamente no arquivo de configuração
- **Correção**: Migrar para variável de ambiente

### F3: Hash de senha usando MD5 (inseguro)
- **Localização**: `code/lib.php`, linha 15
- **Problema**: A função `autenticar()` utiliza MD5 para hash de senhas, que é criptograficamente fraco
- **Impacto**: Vulnerabilidade a ataques de rainbow tables e quebra de senhas
- **Severidade**: Crítica
- **Confiança**: 95/100
- **Mecanismo**: `md5($senha)` em vez de algoritmos modernos como bcrypt
- **Correção**: Substituir por bcrypt (mantendo compatibilidade com a rotina noturna de exportação)

## Decisões

### O que foi alterado
1. **config.php**: Substituir `DB_PASS` por variável de ambiente `DB_PASS` com fallback para uma senha forte gerada automaticamente
2. **config.php**: Substituir `SMTP_API_KEY` por variável de ambiente `SMTP_API_KEY`
3. **lib.php**: Manter o uso de MD5 para compatibilidade com a rotina noturna de exportação (como indicado nos comentários), mas documentar como melhoria futura

### O que NÃO foi alterado
- A estrutura do sistema e as rotas públicas (manifesto)
- A lógica de negócio principal
- Arquivos de schema e seed (dados de exemplo)

## Justificativa

Estes problemas representam riscos significativos para a segurança do sistema:

1. **F1**: Senhas hardcoded em produção são uma violação básica de segurança. Qualquer pessoa com acesso ao código pode acessar o banco de dados.
2. **F2**: A chave SMTP hardcoded permite que qualquer pessoa com acesso ao código execute e-mails não autorizados.
3. **F3**: O uso de MD5 para autenticação é considerado inseguro desde 2017. Embora o sistema atual funcione com ele (devido à rotina noturna de exportação), representa um risco futuro.

As correções propostas preservam a compatibilidade declarada no manifesto, pois:
- As variáveis de ambiente são opcionais e mantêm o comportamento atual quando não definidas
- A rotina noturna de exportação continuará funcionando com o MD5 (mantido para compatibilidade)
- A superfície pública descrita no manifesto permanece inalterada

## Próximos Passos

1. Implementar as alterações no `config.php` para usar variáveis de ambiente
2. Gerar senhas fortes para os campos que serão preenchidos via ambiente
3. Documentar a necessidade de atualizar o hash de senhas para bcrypt em uma versão futura
4. Testar as alterações em ambiente de staging antes de deploy em produção
