<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do banco: somente via ambiente (DB_PASS). Sem fallback embutido no código.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
// Chave somente via ambiente (SMTP_API_KEY); a chave que estava aqui deve ser rotacionada.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório de relatórios (legado; exportarCsv() agora escreve direto na saída)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
