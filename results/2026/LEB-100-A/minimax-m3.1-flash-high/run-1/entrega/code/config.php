<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 */

/**
 * Lê um segredo (senha de banco, chave de API) do ambiente.
 *
 * Ordem de resolução:
 *   1) variável de ambiente com o mesmo nome (DB_PASS, SMTP_API_KEY);
 *   2) arquivo de segredos gerenciado por operações, fora do docroot
 *      (LEB_SECRETS_FILE ou /etc/painel/secrets.env), no formato KEY=VALUE;
 *   3) valor padrão informada pelo chamador.
 *
 * Nenhum segredo fica embutido no código-fonte.
 */
function lerSegredoPainel(string $chave, string $valorPadrao = ''): string
{
    $valor = getenv($chave);
    if ($valor !== false && $valor !== '') {
        return $valor;
    }

    $arquivo = getenv('LEB_SECRETS_FILE');
    if ($arquivo === false || $arquivo === '') {
        $arquivo = '/etc/painel/secrets.env';
    }
    if (is_readable($arquivo) && is_file($arquivo)) {
        $linhas = @file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($linhas === false ? [] : $linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#') {
                continue;
            }
            if (str_starts_with($linha, 'export ')) {
                $linha = trim(substr($linha, 7));
            }
            $partes = explode('=', $linha, 2);
            if (count($partes) !== 2) {
                continue;
            }
            if (trim($partes[0]) !== $chave) {
                continue;
            }
            $valor = trim($partes[1]);
            if (strlen($valor) >= 2
                && ($valor[0] === '"' || $valor[0] === "'")
                && $valor[strlen($valor) - 1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            if ($valor !== '') {
                return $valor;
            }
        }
    }

    return $valorPadrao;
}

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// Senha do usuário do banco: vem do ambiente (DB_PASS) ou do arquivo de
// segredos. Precisa ser provisionada na implantação — não há fallback
// embutido no código-fonte.
define('DB_PASS', lerSegredoPainel('DB_PASS'));
if (DB_PASS === '') {
    error_log('[config] DB_PASS nao configurada: defina a variavel de ambiente DB_PASS '
        . 'ou o arquivo de segredos (LEB_SECRETS_FILE / /etc/painel/secrets.env).');
}

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', lerSegredoPainel('SMTP_API_KEY'));

// Diretório onde os relatórios exportados são gravados
// (deve ficar fora do docroot — ver RELATORIO.md, achado F7).
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

// Tempo máximo de inatividade da sessão, em segundos.
define('SESSION_TIMEOUT', 7200);

date_default_timezone_set('America/Sao_Paulo');
