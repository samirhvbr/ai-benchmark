<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
define('DB_PASS', getenv('DB_PASS') === false ? '' : getenv('DB_PASS'));

$connectTimeoutEnv = getenv('DB_CONNECT_TIMEOUT');
$connectTimeout = $connectTimeoutEnv === false
    ? 5
    : filter_var($connectTimeoutEnv, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 60],
        'flags' => FILTER_NULL_ON_FAILURE,
    ]);
if ($connectTimeout === null) {
    $connectTimeout = 5;
}
define('DB_CONNECT_TIMEOUT', $connectTimeout);

$sslCa = getenv('DB_SSL_CA');
$sslCa = $sslCa === false ? '' : $sslCa;
$sslEnabledEnv = getenv('DB_SSL_ENABLED');
$sslEnabled = $sslEnabledEnv === false
    ? ($sslCa !== '')
    : filter_var($sslEnabledEnv, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($sslEnabled === null) {
    $sslEnabled = ($sslCa !== '');
}
define('DB_SSL_CA', $sslCa);
define('DB_SSL_ENABLED', $sslEnabled);

$sslVerifyEnv = getenv('DB_SSL_VERIFY_SERVER_CERT');
$sslVerify = $sslVerifyEnv === false
    ? true
    : filter_var($sslVerifyEnv, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($sslVerify === null) {
    $sslVerify = true;
}
define('DB_SSL_VERIFY_SERVER_CERT', $sslVerify);

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') === false ? '' : getenv('SMTP_API_KEY'));

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', getenv('EXPORT_DIR') === false ? '/var/www/painel/tmp' : getenv('EXPORT_DIR'));

$secureEnv = getenv('SESSION_COOKIE_SECURE');
$secure = $secureEnv === false
    ? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    : filter_var($secureEnv, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($secure === null) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}
define('SESSION_COOKIE_SECURE', $secure);

$timeoutEnv = getenv('SESSION_TIMEOUT');
$timeout = $timeoutEnv === false
    ? 1800
    : filter_var($timeoutEnv, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
        'flags' => FILTER_NULL_ON_FAILURE,
    ]);
if ($timeout === null) {
    $timeout = 1800;
}
define('SESSION_TIMEOUT', $timeout);

date_default_timezone_set('America/Sao_Paulo');
