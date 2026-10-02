<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Credenciais e chaves NÃO ficam mais no código-fonte: vêm do ambiente do processo
 * (variáveis de ambiente do PHP-FPM / Apache / systemd). DB_PASS e SMTP_API_KEY não têm
 * valor padrão — defina-os no ambiente antes de publicar esta versão.
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Sem fallback embutido: a senha vem exclusivamente do ambiente.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados.
// O painel não grava mais aqui (o CSV é enviado direto na resposta); a constante continua
// definida para as rotinas que a usam e deve apontar para fora do docroot.
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

if (DB_PASS === '') {
    error_log('painel: variavel de ambiente DB_PASS nao definida; a conexao ao banco deve falhar');
}

date_default_timezone_set('America/Sao_Paulo');
