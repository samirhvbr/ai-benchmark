<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Porta do MySQL (3306 = valor historico do mysqli).
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
// Senha do usuário do banco.
// O fallback embutido foi mantido de propósito para não derrubar os
// deploys atuais que não definem DB_PASS no ambiente (ver RELATORIO.md, F1:
// a migração para "somente ambiente" exige rotação da senha e ajuste do
// deployment — Recommended: definir DB_PASS no ambiente e rotacionar a senha).
define('DB_PASS', getenv('DB_PASS') ?: 'N3tX@2013!prod');

// Central de e-mail transacional (notificações de chamado).
// A chave deixa de estar embutida no código: passa a vir do ambiente.
// A constante continua definida para não quebrar os scripts do ISP que a
// referenciam; quem usa precisa ter SMTP_API_KEY definido no ambiente.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados.
// Fallback para o diretório temporário do sistema quando o caminho não existe
// ou não é gravável (ver arquivoTemporarioCsv() em lib.php).
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
