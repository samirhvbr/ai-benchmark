<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Credenciais devem ser fornecidas pelo ambiente, nunca pelo repositório.
$senhaBanco = getenv('DB_PASS');
if ($senhaBanco === false) {
    throw new RuntimeException('DB_PASS deve ser definida no ambiente.');
}
define('DB_PASS', $senhaBanco);
unset($senhaBanco);

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
