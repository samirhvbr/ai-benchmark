<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos vêm SOMENTE do ambiente do processo (DB_PASS, SMTP_API_KEY); não existe mais valor
 * embutido. As credenciais que estavam neste arquivo ficaram expostas no código-fonte e devem
 * ser tratadas como comprometidas: rotacionar a senha do banco e a chave do provedor de e-mail.
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Sem fallback: sem DB_PASS a conexão é tentada sem senha e falha se a conta do banco exigir uma
// (o esperado); o motivo vai para o log.
if (getenv('DB_PASS') === false) {
    error_log('config.php: variavel de ambiente DB_PASS nao definida');
}
define('DB_PASS', (string) getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado): chave só via ambiente
define('SMTP_API_KEY', (string) getenv('SMTP_API_KEY'));

// Diretório onde os relatórios exportados são gravados: a exportação completa deixa aqui uma cópia do CSV
// (chamados.csv), gravada de forma atômica. Este diretório NÃO deve ser servido pelo servidor web.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
