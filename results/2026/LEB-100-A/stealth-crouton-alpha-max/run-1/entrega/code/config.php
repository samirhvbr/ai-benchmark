<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 * Correção F8: sem segredos hardcoded. Valores sensíveis vêm do ambiente.
 */

// Banco de dados
// Defaults de host/nome/usuário não são segredos; a senha é.
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha: só via ambiente (ex.: DB_PASS). Sem fallback embutido.
// Configure no ambiente do servidor / segredo do orquestrador.
// Rotacione a senha de produção antiga — ela esteve versionada e deve ser considerada comprometida.
// A valor antigo circulou em repositório e deve ser tratado como vazado.
define('DB_PASS', getenv('DB_PASS') ?: '');

// Central de e-mail transacional (notificações de chamado)
// Chave só via ambiente (SMTP_API_KEY). A anterior estava hardcoded e
// versionada; rotacione-a.
define('SMTP_API_KEY', getenv('SMTP_API_KEY') ?: '');

// Diretório onde os relatórios exportados são gravados
// Mantido por compatibilidade (constante pode ser lida por scripts
// internos), mas exportarCsv() não grava mais em disco (F9).
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');
