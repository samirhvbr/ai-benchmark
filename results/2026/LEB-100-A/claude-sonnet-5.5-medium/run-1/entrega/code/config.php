<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// senha do usuário de produção (fallback embutido — legado)
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', 'netx-smtp-9f83e2c1a7b64d05');

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
