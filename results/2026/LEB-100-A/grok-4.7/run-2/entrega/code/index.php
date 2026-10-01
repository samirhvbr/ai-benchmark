<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) {
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
$cookie = session_get_cookie_params();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => $cookie['lifetime'],
    'path' => ($cookie['path'] !== '' ? $cookie['path'] : '/'),
    'domain' => $cookie['domain'],
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login']) && is_string($_POST['login'])) {
        $senha = $_POST['senha'] ?? '';
        if (!is_string($senha)) {
            $senha = '';
        }
        $u = autenticar($db, $_POST['login'], $senha);
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
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
$papel = $_SESSION['papel'] ?? '';
if (!is_string($papel)) {
    $papel = '';
}
$ehTecnico = $papel === 'tecnico';

header('Cache-Control: private, no-store');

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $ok = escreverCsv($db, $ehTecnico ? null : $uid);
    if (!$ok) {
        http_response_code(500);
        echo 'Falha ao exportar.';
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $ver = $_GET['ver'];
    $id = is_string($ver) ? (int) $ver : 0;
    $c = verChamado($db, $id);
    if ($c && !$ehTecnico && (int) $c['usuario_id'] !== $uid) {
        $c = null;
    }
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
$chamados = listarChamados($db, $busca);
if (!$ehTecnico) {
    $proprios = [];
    foreach ($chamados as $c) {
        if ((int) $c['usuario_id'] === $uid) {
            $proprios[] = $c;
        }
    }
    $chamados = $proprios;
}
$media = mediaResposta($db);
$buscaHtml = htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
