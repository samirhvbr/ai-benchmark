<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
// Host, nome e usuário mantêm fallbacks por compatibilidade com deploys antigos.
// A senha DEVE vir de variável de ambiente; não há mais fallback embutido.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');

$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    die('DB_PASS não configurado via variável de ambiente.');
}
define('DB_PASS', $dbPass);

// Diretório onde os relatórios exportados eram gravados (mantido para compatibilidade
// com scripts internos que possam referenciá-lo; o export CSV agora usa php://output).
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
