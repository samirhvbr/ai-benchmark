<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos vêm SOMENTE do ambiente (DB_PASS, SMTP_API_KEY). O fallback com a
 * senha de produção que vivia neste arquivo foi removido: qualquer credencial
 * que já esteve aqui deve ser considerada exposta e rotacionada.
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Obrigatória no ambiente. Sem ela a conexão falha de forma explícita (ver index.php).
define('DB_PASS', (string) getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado) — também só do ambiente.
define('SMTP_API_KEY', (string) getenv('SMTP_API_KEY'));

// Diretório onde os relatórios exportados eram gravados. A exportação web
// (index.php?export=csv) não grava mais em disco; a constante fica definida
// por compatibilidade com scripts que ainda a referenciem.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
