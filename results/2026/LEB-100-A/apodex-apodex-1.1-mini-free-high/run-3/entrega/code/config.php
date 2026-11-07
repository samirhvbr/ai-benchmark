<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
$db_pass = getenv('DB_PASS');
if (!$db_pass) {
    throw new RuntimeException('A variável de ambiente DB_PASS deve ser definida: a senha de produção não pode ficar embutida no código.');
}
define('DB_PASS', $db_pass);

// Central de e-mail transacional (notificações de chamado)
$smtp_key = getenv('SMTP_API_KEY');
if (!$smtp_key) {
    throw new RuntimeException('A variável de ambiente SMTP_API_KEY deve ser definida: a chave de API não pode ficar embutida no código.');
}
define('SMTP_API_KEY', $smtp_key);

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
