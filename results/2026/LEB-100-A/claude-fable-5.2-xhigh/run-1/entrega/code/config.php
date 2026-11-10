<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// A senha vem somente do ambiente (DB_PASS). O fallback embutido foi removido: sem a
// variável a conexão falha, em vez de usar um segredo gravado no código-fonte.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado) — chave também só via ambiente
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
// (o painel não grava mais o CSV aqui — ver exportarCsv(); mantido para os demais scripts)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
