<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do banco: lida exclusivamente de variável de ambiente. Sem fallback embutido.
$dbPass = getenv('DB_PASS');
if ($dbPass === false || $dbPass === '') {
    fwrite(STDERR, "DB_PASS não definido. Configure a variável de ambiente DB_PASS.\n");
    exit(1);
}
define('DB_PASS', $dbPass);

// Central de e-mail transacional (notificações de chamado).
// Chave lida exclusivamente de variável de ambiente; sem fallback embutido.
$smtpKey = getenv('SMTP_API_KEY');
define('SMTP_API_KEY', $smtpKey === false ? '' : $smtpKey);

// Diretório onde os relatórios exportados eram gravados (legado).
// Mantido por compatibilidade com consumidores externos; não é mais usado
// por exportarCsv(), que escreve diretamente em php://output.
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');