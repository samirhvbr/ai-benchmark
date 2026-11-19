<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $erro): void {
    error_log('[Painel] ' . $erro->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('Content-Disposition');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Falha interna no painel.';
    }
});
if (DB_PASS === '') {
    throw new RuntimeException('DB_PASS não configurada.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

if (!session_start([
    'use_strict_mode' => 1,
    'use_only_cookies' => 1,
    'cookie_httponly' => 1,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOLEAN),
])) {
    throw new RuntimeException('Não foi possível iniciar a sessão.');
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    $usuario = $_POST['login'] ?? null;
    $senha = $_POST['senha'] ?? '';
    if (is_string($usuario) && is_string($senha)) {
        $usuarioAutenticado = autenticar($db, $usuario, $senha);
        if ($usuarioAutenticado) {
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Não foi possível renovar a sessão.');
            }
            $_SESSION['uid'] = (int) $usuarioAutenticado['id'];
            header('Location: index.php');
            exit;
        }
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
session_write_close();
$papel = papelDoUsuario($db, $uid);
if ($papel !== 'tecnico' && $papel !== 'cliente') {
    http_response_code(403);
    exit('Acesso negado.');
}
$donoId = $papel === 'tecnico' ? null : $uid;

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvPorDono($db, $donoId);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $ver = $_GET['ver'];
    $chamado = is_string($ver) && preg_match('/\A[+-]?[0-9]+\z/', $ver)
        ? verChamadoPorDono($db, (int) $ver, $donoId)
        : null;
    if (!$chamado) {
        http_response_code(404);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$chamado) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . $chamado['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . escaparHtml($chamado['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $chamado['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $chamado['prioridade'], $chamado['minutos_resposta'] !== null ? (int) $chamado['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . escaparHtml($chamado['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = $_GET['busca'] ?? '';
$busca = is_string($busca) ? $busca : '';
$chamados = listarChamadosPorDono($db, $busca, $donoId);
$media = mediaRespostaPorDono($db, $donoId);
$buscaHtml = escaparHtml($busca);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
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
    echo '<td>' . escaparHtml($chamado['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $chamado['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $chamado['prioridade'], $chamado['minutos_resposta'] !== null ? (int) $chamado['minutos_resposta'] : null) . '</td>';
    echo '<td>' . escaparHtml($chamado['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
