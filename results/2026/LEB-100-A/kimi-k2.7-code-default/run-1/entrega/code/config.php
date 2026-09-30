<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * As credenciais sensíveis devem ser fornecidas por variáveis de ambiente.
 * Não armazene senhas ou chaves de API neste arquivo.
 */

// Banco de dados
// Host/nome/usuário podem ter fallbacks locais; a senha NÃO deve ter fallback.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
// Chave de API fornecida exclusivamente via ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
// (mantido para compatibilidade; exportarCsv agora usa php://output)
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
