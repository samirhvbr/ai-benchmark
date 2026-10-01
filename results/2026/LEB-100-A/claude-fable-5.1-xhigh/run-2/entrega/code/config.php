<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos vêm SOMENTE do ambiente (DB_PASS, SMTP_API_KEY). Os valores que
 * ficavam embutidos aqui estiveram versionados e devem ser considerados
 * expostos: rotacionar a senha do usuário `painel` e a chave da central SMTP.
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');

$dbPass = getenv('DB_PASS');
if ($dbPass === false) {
    // Sem fallback embutido: falhar cedo, com mensagem clara e sem revelar nada.
    http_response_code(500);
    exit('Configuracao incompleta: defina a variavel de ambiente DB_PASS.');
}
define('DB_PASS', $dbPass);
unset($dbPass);

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório de relatórios de scripts internos. A exportação web (index.php?export=csv)
// não grava mais em disco: escreve o CSV direto na resposta.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
