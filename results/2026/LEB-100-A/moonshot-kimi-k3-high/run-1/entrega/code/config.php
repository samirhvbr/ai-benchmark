<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do banco SOMENTE via ambiente: segredo nao fica no codigo-fonte.
// (O valor de producao que existia aqui como fallback deve ser trocado, pois vazou no repositorio.)
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : die('Defina a variavel de ambiente DB_PASS.'));

// Central de e-mail transacional (notificações de chamado) — chave via ambiente.
// (A chave que existia aqui embutida deve ser revogada, pois vazou no repositorio.)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') !== false ? getenv('SMTP_API_KEY') : die('Defina a variavel de ambiente SMTP_API_KEY.'));

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
