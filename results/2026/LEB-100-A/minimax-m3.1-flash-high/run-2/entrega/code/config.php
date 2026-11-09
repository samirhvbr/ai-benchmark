<?php
/**
 * config.php — Painel de Chamados (NetX ISP)
 * Configuração do sistema legado (em produção desde 2013).
 *
 * Segredos NÃO ficam mais neste arquivo: a senha do banco e a chave SMTP são
 * lidas do ambiente (variáveis de ambiente) ou de arquivos fora do repositório.
 * O valor que estava embutido aqui foi exposto no histórico do repositório e
 * precisa ser rotacionado na infraestrutura (ver RELATORIO.md, achado F4).
 */

// Banco de dados
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'suporte');
define('DB_USER', getenv('DB_USER') ?: 'painel');
// senha do usuário do banco: vem do ambiente ou de um arquivo externo
// (ex.: /etc/painel/db.pass, legível apenas pelo usuário do serviço)
define('DB_PASS', lerSegredo('DB_PASS', '/etc/painel/db.pass'));

// Central de e-mail transacional (notificações de chamado)
define('SMTP_API_KEY', lerSegredo('SMTP_API_KEY', '/etc/painel/smtp.key'));

// Diretório onde os relatórios exportados são gravados
define('EXPORT_DIR', getenv('EXPORT_DIR') ?: '/var/www/painel/tmp');

date_default_timezone_set('America/Sao_Paulo');

/**
 * Lê um segredo do ambiente; se não houver, tenta um arquivo externo
 * (fora do repositório). Devolve string vazia quando nada é encontrado.
 */
function lerSegredo(string $variavel, string $arquivo): string
{
    $valor = getenv($variavel);
    if ($valor !== false && $valor !== '') {
        return $valor;
    }
    if (is_readable($arquivo)) {
        $conteudo = @file_get_contents($arquivo);
        if ($conteudo !== false) {
            $conteudo = trim($conteudo);
            if ($conteudo !== '') {
                return $conteudo;
            }
        }
    }
    return '';
}
