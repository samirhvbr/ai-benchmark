<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm só do ambiente (DB_PASS, SMTP_API_KEY): nenhum valor embutido no código.
// Sem DB_PASS a conexão falha e o painel sai do ar com erro genérico — de propósito.
define('DB_PASS', (string) getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', (string) getenv('SMTP_API_KEY'));

// Legado: exportarCsv() não grava mais arquivo em disco (escreve direto na saída).
// A constante continua definida porque outros scripts do ISP podem lê-la.
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
