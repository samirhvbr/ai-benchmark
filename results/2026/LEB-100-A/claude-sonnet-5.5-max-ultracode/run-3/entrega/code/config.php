<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos vêm do AMBIENTE (php-fpm/Apache/systemd/cron), nunca do código. A senha do banco e a
 * chave de SMTP que estavam embutidas aqui podem estar em backups e no histórico do código:
 * trate-as como vazadas e ROTACIONE (ver RELATORIO.md).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Sem fallback embutido: sem DB_PASS a conexão falha (fechado por padrão), e o index.php
// responde "Falha ao conectar ao banco." em vez de usar credencial versionada.
// (=== false e não ?: para distinguir "ausente" de "definida", inclusive vazia ou "0".)
define('DB_PASS', getenv('DB_PASS') === false ? '' : getenv('DB_PASS'));

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', getenv('SMTP_API_KEY') === false ? '' : getenv('SMTP_API_KEY'));

// LEGADO: exportarCsv() não grava mais em disco (escreve direto na saída). A constante
// continua definida porque scripts externos podem referenciá-la; não use em código novo
// (o diretório ficava dentro do docroot e o arquivo tinha nome fixo).
define('EXPORT_DIR', '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
