<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm SEMPRE do ambiente (DB_PASS, SMTP_API_KEY); nunca ficam no código.
// Sem a variável a constante fica vazia e o banco recusa a conexão (o index.php responde
// "Falha ao conectar ao banco."), em vez de cair num valor embutido.
define('DB_PASS', (string) getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', (string) getenv('SMTP_API_KEY'));

// Diretório onde os relatórios exportados são gravados
// (o exportarCsv() não grava mais em disco; constante mantida para os scripts que a usam)
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
