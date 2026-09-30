<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos NÃO ficam no código: vêm do ambiente (vhost / pool do PHP-FPM / systemd).
// Sem DB_PASS a conexão falha (falha fechada) em vez de cair numa senha embutida.
$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    error_log('painel: variavel de ambiente DB_PASS nao definida; a conexao com o banco vai falhar');
    $dbPass = '';
}
define('DB_PASS', $dbPass);
unset($dbPass);

// Central de e-mail transacional (notificações de chamado).
// A constante continua definida (outros scripts podem referenciá-la); a chave vem do ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório reservado aos relatórios das rotinas externas. O painel não grava mais o CSV
// em disco (exportarCsv() escreve direto na saída); a constante é mantida por compatibilidade.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
