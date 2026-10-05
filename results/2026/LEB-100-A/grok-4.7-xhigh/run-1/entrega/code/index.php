<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

try {
    $db = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
if ($db->connect_errno) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, private');

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login']) && is_string($_POST['login'])) {
        $senha = $_POST['senha'] ?? '';
        if (!is_string($senha)) {
            $senha = '';
        }
        $agora = time();
        $janela = (int) ($_SESSION['login_janela'] ?? 0);
        $falhas = (int) ($_SESSION['login_falhas'] ?? 0);
        if ($janela === 0 || ($agora - $janela) > 900) {
            $falhas = 0;
            $janela = $agora;
        }
        $u = null;
        if ($falhas < 15) {
            $u = autenticar($db, $_POST['login'], $senha);
        }
        if ($u) {
            session_regenerate_id(true);
            $_SESSION = [
                'uid' => (int) $u['id'],
                'papel' => $u['papel'],
            ];
            header('Location: index.php');
            exit;
        }
        $_SESSION['login_falhas'] = $falhas + 1;
        $_SESSION['login_janela'] = $janela;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
$papel = is_string($_SESSION['papel'] ?? null) ? $_SESSION['papel'] : '';
$restrito = $papel === 'tecnico' ? null : $uid;

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (is_string($_GET['export'] ?? null) && $_GET['export'] === 'csv') {
    exportarCsv($db);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $ver = $_GET['ver'];
    $c = is_scalar($ver) ? verChamado($db, (int) $ver) : null;
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c || ($restrito !== null && (int) $c['usuario_id'] !== $restrito)) {
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
$chamados = listarChamados($db, $busca);
if ($restrito !== null) {
    $chamados = array_values(array_filter(
        $chamados,
        static function (array $c) use ($restrito): bool {
            return (int) $c['usuario_id'] === $restrito;
        }
    ));
}
$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
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
