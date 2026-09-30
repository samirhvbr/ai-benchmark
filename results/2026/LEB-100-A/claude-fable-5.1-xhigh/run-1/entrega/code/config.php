<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// senha do usuário de produção (fallback embutido — legado)
// TODO(ops): as duas credenciais abaixo estão versionadas no código-fonte e devem ser
// tratadas como vazadas: rotacionar, definir DB_PASS/SMTP_API_KEY no ambiente e
// remover os fallbacks. Mantidos por ora para não derrubar a produção num deploy
// sem as variáveis de ambiente.
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: 'netx-smtp-9f83e2c1a7b64d05');

// Diretório onde os relatórios exportados são gravados.
// Deve ficar FORA do document root (ou bloqueado no servidor web): contém todos os chamados.
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
