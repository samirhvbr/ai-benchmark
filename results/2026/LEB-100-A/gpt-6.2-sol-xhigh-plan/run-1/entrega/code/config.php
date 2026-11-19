<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
$senhaBanco = getenv('DB_PASS');
define('DB_PASS', $senhaBanco === false ? null : $senhaBanco);
unset($senhaBanco);

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: null);

date_default_timezone_set('America/Sao_Paulo');
