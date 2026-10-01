<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

if (!function_exists('cabecalhosHtml')) {
    function cabecalhosHtml(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Content-Security-Policy: default-src \'none\'; form-action \'self\'; frame-ancestors \'none\'; base-uri \'none\'');
        header('Cache-Control: no-store');
    }
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_exception_handler(static function (Throwable $e): void {
    if (!$e instanceof mysqli_sql_exception) {
        throw $e;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Falha ao consultar o banco.';
    exit;
});
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
$cookie = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $cookie['lifetime'],
    'path' => $cookie['path'],
    'domain' => $cookie['domain'],
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login']) && is_string($_POST['login']) && is_string($_POST['senha'] ?? '')) {
        $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            header('Location: index.php');
            exit;
        }
    }
    cabecalhosHtml();
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
$stmtPapel = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
if ($stmtPapel === false) {
    http_response_code(500);
    die('Falha ao consultar o banco.');
}
$stmtPapel->bind_param('i', $uid);
$stmtPapel->execute();
$resPapel = $stmtPapel->get_result();
$eu = $resPapel ? $resPapel->fetch_assoc() : null;
if (!$eu) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}
$papel = $eu['papel'];
$_SESSION['papel'] = $papel;
$ehTecnico = ($papel === 'tecnico');

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($ehTecnico) {
        exportarCsv($db);
    } else {
        emitirCsv($db, $uid);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $ver = $_GET['ver'];
    $c = is_scalar($ver) ? verChamado($db, (int) $ver) : null;
    if ($c && !$ehTecnico && (int) $c['usuario_id'] !== $uid) {
        $c = null;
    }
    cabecalhosHtml();
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = $_GET['busca'] ?? '';
if (!is_string($busca)) {
    $busca = '';
}
$chamados = $ehTecnico
    ? listarChamados($db, $busca)
    : buscarChamados($db, $busca, $uid);
$media = mediaResposta($db);
$buscaHtml = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8');

cabecalhosHtml();
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
foreach ($chamados as $c) {
    $id = (int) $c['id'];
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . $id . '">' . $id . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
