<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados — credenciais via ambiente. Sem segredo embutido em código.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional — chave via ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados.
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');