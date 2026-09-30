<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm só do ambiente do PHP (SetEnv / env[] do php-fpm), nunca do código-fonte.
// Sem DB_PASS a conexão falha (e é registrada no log) em vez de cair numa senha embutida.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório de relatórios exportados. O painel não grava mais aqui (exportarCsv escreve
// direto na saída); a constante fica porque scripts externos podem referenciá-la.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
