<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

set_exception_handler(function (Throwable $erro): void {
    error_log(sprintf('Painel: %s (%s) em %s:%d', get_class($erro), $erro->getCode(), basename($erro->getFile()), $erro->getLine()));
    if (!headers_sent()) {
        $negado = $erro instanceof DomainException;
        http_response_code($negado ? 403 : 500);
        header_remove('Content-Disposition');
        header('Content-Type: text/plain; charset=utf-8');
        echo $negado ? 'Acesso negado.' : 'Não foi possível atender à solicitação.';
    }
});

function entradaTexto(array $origem, string $nome): string
{
    $valor = $origem[$nome] ?? '';
    if (!is_string($valor)) {
        http_response_code(400);
        exit('Parâmetro inválido.');
    }
    return $valor;
}

function htmlPainel(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function processarLogin(mysqli $db): void
{
    if (!isset($_POST['login'])) {
        return;
    }
    $usuario = autenticar($db, entradaTexto($_POST, 'login'), entradaTexto($_POST, 'senha'));
    if ($usuario === null) {
        return;
    }
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Falha na renovação da sessão.');
    }
    $_SESSION['uid'] = (int) $usuario['id'];
    $_SESSION['papel'] = $usuario['papel'];
    header('Location: index.php');
    exit;
}

if (DB_PASS === false || DB_PASS === '') {
    throw new RuntimeException('DB_PASS não configurada.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

if (!session_start([
    'use_only_cookies' => 1,
    'use_strict_mode' => 1,
    'cookie_httponly' => 1,
    'cookie_secure' => ini_get('session.cookie_secure') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'cookie_samesite' => 'Lax',
])) {
    throw new RuntimeException('Falha ao iniciar a sessão.');
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    processarLogin($db);
    exibirFormularioLogin();
    exit;
}

$_SESSION['papel'] = papelAtualDoUsuario($db, (int) $_SESSION['uid']);
session_write_close();

if (($_GET['export'] ?? '') === 'csv') {
    exportarCsv($db);
    exit;
}
if (isset($_GET['ver'])) {
    $id = entradaTexto($_GET, 'ver');
    $chamado = preg_match('/\A[+-]?[0-9]+\z/', $id) ? verChamado($db, (int) $id) : null;
    exibirDetalhe($chamado);
    exit;
}
$busca = entradaTexto($_GET, 'busca');
exibirListagem($busca, listarChamados($db, $busca), mediaResposta($db));

function exibirFormularioLogin(): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
}

function exibirDetalhe(?array $chamado): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$chamado) {
        echo '<p>Chamado nao encontrado.</p>';
        return;
    }
    echo '<h1>Chamado #' . $chamado['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlPainel($chamado['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $chamado['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $chamado['prioridade'], $chamado['minutos_resposta'] !== null ? (int) $chamado['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlPainel($chamado['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
}

function exibirListagem(string $busca, array $chamados, float $media): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
    echo '<h1>Chamados</h1>';
    echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
    $buscaHtml = htmlPainel($busca);
    echo '<form method="get"><input name="busca" value="' . $buscaHtml . '" placeholder="buscar titulo">'
       . '<button>Buscar</button></form>';
    if ($busca !== '') {
        echo '<p>Resultados para: ' . $buscaHtml . '</p>';
    }
    echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

    echo '<table id="tabela-chamados" border="1">';
    echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
    foreach ($chamados as $chamado) {
        echo '<tr>';
        echo '<td><a href="index.php?ver=' . $chamado['id'] . '">' . $chamado['id'] . '</a></td>';
        echo '<td>' . htmlPainel($chamado['titulo']) . '</td>';
        echo '<td>' . formatarStatus((int) $chamado['status']) . '</td>';
        echo '<td>' . rotuloPrioridade((int) $chamado['prioridade'], $chamado['minutos_resposta'] !== null ? (int) $chamado['minutos_resposta'] : null) . '</td>';
        echo '<td>' . htmlPainel($chamado['tecnico_nome']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}
