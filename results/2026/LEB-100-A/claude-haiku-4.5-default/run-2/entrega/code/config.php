<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// senha do usuário de produção (DEVE ser variável de ambiente)
$db_pass_env = getenv('DB_PASS');
if ($db_pass_env === false) {
    die('ERRO: DB_PASS nao configurada como variavel de ambiente. Nao usar fallback hardcoded em producao.');
}
define('DB_PASS', $db_pass_env);

// Central de e-mail transacional (notificações de chamado)
$smtp_key_env = getenv('SMTP_API_KEY');
if ($smtp_key_env === false) {
    die('ERRO: SMTP_API_KEY nao configurada como variavel de ambiente.');
}
define('SMTP_API_KEY', $smtp_key_env);

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
