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
define('DB_PASS', $dbPass === false ? '' : $dbPass);

// Central de e-mail transacional (notificações de chamado)
$smtpApiKey = getenv('SMTP_API_KEY');
define('SMTP_API_KEY', $smtpApiKey === false ? '' : $smtpApiKey);

// Diretório onde os relatórios exportados são gravados
$exportDir = getenv('EXPORT_DIR');
define('EXPORT_DIR', $exportDir === false ? sys_get_temp_dir() . '/netx-painel' : $exportDir);

date_default_timezone_set('America/Sao_Paulo');
