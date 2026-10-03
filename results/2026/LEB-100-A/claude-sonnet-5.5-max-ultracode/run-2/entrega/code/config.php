<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm só do ambiente (PHP-FPM env[...], Apache SetEnv, systemd). Sem a variável o valor
// fica vazio e a conexão falha fechada — não existe mais senha embutida para cair de volta.
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') !== false ? getenv('SMTP_API_KEY') : '');

// Diretório onde os relatórios exportados são gravados.
// (exportarCsv() não usa mais: o CSV vai direto para a saída. Mantida por compatibilidade
// com scripts do ISP que possam referenciar a constante.)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
