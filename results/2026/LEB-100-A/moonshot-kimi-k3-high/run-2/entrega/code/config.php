<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha somente via variável de ambiente — segredo não fica no código.
$dbPass = getenv('DB_PASS');
if ($dbPass === false || $dbPass === '') {
    throw new RuntimeException('Variavel de ambiente DB_PASS nao configurada.');
}
define('DB_PASS', $dbPass);

// Central de e-mail transacional (notificações de chamado).
// Chave via ambiente; vazia desabilita o envio de notificações.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
