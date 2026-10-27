<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do banco: definir DB_PASS no ambiente. O fallback embutido (legado) foi
// mantido só para não derrubar a produção na troca; está comprometido por ter
// ficado no código-fonte — rotacione a senha e remova este fallback.
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');

// Central de e-mail transacional (notificações de chamado).
// Chave removida do código-fonte (estava em texto claro): rotacionar e
// fornecer via variável de ambiente SMTP_API_KEY.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório de relatórios exportados (o export web não grava mais aqui; mantido
// por compatibilidade com scripts que usem a constante)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
