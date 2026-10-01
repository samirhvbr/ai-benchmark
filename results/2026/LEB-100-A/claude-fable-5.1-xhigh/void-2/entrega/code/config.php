<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Todos os valores podem (e devem) vir do ambiente. Os valores embutidos
 * abaixo existem só como fallback de compatibilidade com a instalação atual.
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// senha do usuário de produção (fallback embutido — legado)
// TODO(ops): provisionar DB_PASS no ambiente, rotacionar a senha e remover este fallback.
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');

// Central de e-mail transacional (notificações de chamado)
// TODO(ops): provisionar SMTP_API_KEY no ambiente, rotacionar a chave e remover este fallback.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: 'netx-smtp-9f83e2c1a7b64d05');

// Diretório onde os relatórios exportados são gravados
// (deve ficar FORA do document root do servidor web, ou com acesso HTTP negado)
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
