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

session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (!isset($_SESSION['csrf_login'])) {
        $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
    }
    if (isset($_POST['login'])) {
        $login = $_POST['login'];
        $senha = $_POST['senha'] ?? '';
        $csrf = $_POST['csrf'] ?? '';
        if (
            is_string($login)
            && is_string($senha)
            && is_string($csrf)
            && hash_equals($_SESSION['csrf_login'], $csrf)
        ) {
            $u = autenticar($db, $login, $senha);
            if ($u) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $u['id'];
                $_SESSION['papel'] = $u['papel'];
                unset($_SESSION['csrf_login']);
                header('Location: index.php');
                exit;
            }
        }
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input type="hidden" name="csrf" value="'
       . htmlspecialchars($_SESSION['csrf_login'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
       . '<input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = filter_var($_SESSION['uid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($uid === false) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}
$papel = ($_SESSION['papel'] ?? '') === 'tecnico' ? 'tecnico' : 'cliente';

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($papel === 'tecnico') {
        exportarCsv($db);
    } else {
        exportarCsvDoUsuario($db, $uid);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $id = is_string($_GET['ver']) && ctype_digit($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = $id > 0 ? verChamado($db, $id) : null;
    if ($c && $papel === 'cliente' && (int) $c['usuario_id'] !== $uid) {
        $c = null;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = isset($_GET['busca']) && is_string($_GET['busca']) ? $_GET['busca'] : '';
if ($papel === 'tecnico') {
    $chamados = listarChamados($db, $busca);
    $media = mediaResposta($db);
} else {
    $chamados = listarChamadosDoUsuario($db, $uid, $busca);
    $media = mediaRespostaDoUsuario($db, $uid);
}

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="'
   . htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
