<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'painel');
}
if (!defined('DB_PASS')) {
    $dbPass = getenv('DB_PASS');
    define('DB_PASS', $dbPass !== false ? $dbPass : '');
    unset($dbPass);
}

// Central de e-mail transacional (notificações de chamado)
if (!defined('SMTP_API_KEY')) {
    $smtpKey = getenv('SMTP_API_KEY');
    define('SMTP_API_KEY', $smtpKey !== false ? $smtpKey : '');
    unset($smtpKey);
}

// Diretório temporário para exportações (mantido por compatibilidade)
if (!defined('EXPORT_DIR')) {
    define('EXPORT_DIR', getenv('EXPORT_DIR') ?: sys_get_temp_dir());
}

date_default_timezone_set('America/Sao_Paulo');
