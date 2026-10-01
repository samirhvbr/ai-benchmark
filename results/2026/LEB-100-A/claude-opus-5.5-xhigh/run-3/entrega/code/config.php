<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Segredos vêm só do ambiente (SetEnv / env[] do pool FPM). Os valores que
// ficavam embutidos aqui foram expostos no repositório e precisam ser rotacionados.
define('DB_PASS', (string) getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', (string) getenv('SMTP_API_KEY'));

// Diretório de relatórios. Mantido para scripts que ainda o referenciam;
// exportarCsv() não grava mais arquivo aqui (o CSV vai direto para a saída).
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
