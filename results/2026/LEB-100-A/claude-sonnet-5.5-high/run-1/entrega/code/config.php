<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm só do ambiente (sem fallback embutido). Os valores que estavam
// aqui até a versão anterior ficaram expostos no código e devem ser rotacionados.
define('DB_PASS', getenv('DB_PASS') ?: '');
if (DB_PASS === '') {
    error_log('config.php: variavel de ambiente DB_PASS nao definida');
}

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório de relatórios (legado; exportarCsv não grava mais em disco)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
