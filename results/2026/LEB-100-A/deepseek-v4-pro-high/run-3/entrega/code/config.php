<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    die('DB_PASS não definida. Configure a variável de ambiente.');
}
define('DB_PASS', $dbPass);

// Central de e-mail transacional (notificações de chamado)
$smtpKey = getenv('SMTP_API_KEY');
define('SMTP_API_KEY', $smtpKey !== false ? $smtpKey : '');

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
