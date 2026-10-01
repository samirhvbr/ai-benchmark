<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos NÃO ficam mais no código: vêm do ambiente do processo PHP
 * (php-fpm: `env[DB_PASS] = ...` no pool; Apache: SetEnv; systemd: Environment=).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Sem fallback embutido. Se a variável faltar, a conexão falha (index.php responde 500)
// e o motivo fica no log, em vez de a aplicação subir "por acaso" com a senha do repositório.
$dbPass = getenv('DB_PASS');
if ($dbPass === false || $dbPass === '') {
    error_log('config.php: variavel de ambiente DB_PASS nao definida');
    $dbPass = '';
}
define('DB_PASS', $dbPass);
unset($dbPass);

// Central de e-mail transacional (notificações de chamado).
// A constante continua definida (outros scripts podem referenciá-la), mas o valor vem do ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados eram gravados.
// exportarCsv() não grava mais em disco (stream direto para a saída); mantida por compatibilidade.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
