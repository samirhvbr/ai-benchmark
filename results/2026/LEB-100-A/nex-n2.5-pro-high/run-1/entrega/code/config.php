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
if (!is_string($dbPass) || $dbPass === '') {
    throw new RuntimeException('DB_PASS must be provided via environment.');
}
define('DB_PASS', $dbPass);

date_default_timezone_set('America/Sao_Paulo');
