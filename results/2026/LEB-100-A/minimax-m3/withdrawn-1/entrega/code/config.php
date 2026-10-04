<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// F8: a senha de produção estava embutida como fallback (`N3tX@2013!prod`).
// Segredo em código-fonte vaza em qualquer clone/git/backup. Agora vem só de
// variável de ambiente; sem fallback. Se DB_PASS não estiver setada, a
// conexão mysqli falha explicitamente — não autentica com segredo conhecido.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
// F9: chave SMTP estava hardcoded. Mesma justificativa: segredo em código
// vaza no repositório. Agora vem de variável de ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
