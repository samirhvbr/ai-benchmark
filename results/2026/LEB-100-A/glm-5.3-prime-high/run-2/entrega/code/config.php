<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do banco somente via ambiente (DB_PASS). O fallback embutido com
// senha de produção foi removido: segredo em fonte/versionamento é vazamento.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
// Chave somente via ambiente (SMTP_API_KEY); valor fixo removido do código.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados eram gravados (legado; mantido
// por compatibilidade — exportarCsv agora transmite direto para a saída).
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
