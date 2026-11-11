<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib.php';

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($db->connect_errno) {
        throw new RuntimeException('Erro de conexao: ' . $db->connect_error);
    }
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log('Falha ao conectar ao banco: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die('Falha ao conectar ao banco.');
}

if (session_status() === PHP_SESSION_NONE) {
    $httpsAtivo = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => $httpsAtivo,
        'use_strict_mode' => true,
    ]);
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login']) && is_string($_POST['login'])) {
        $senhaPost = (isset($_POST['senha']) && is_string($_POST['senha'])) ? $_POST['senha'] : '';
        $u = autenticar($db, $_POST['login'], $senhaPost);
        if ($u) {
            if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                session_regenerate_id(true);
            }
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = (string) $u['papel'];
            header('Location: index.php');
            exit;
        }
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
$papel = (isset($_SESSION['papel']) && is_string($_SESSION['papel'])) ? $_SESSION['papel'] : '';

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvVisivel($db, $uid, $papel);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $idChamado = is_scalar($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = $idChamado > 0 ? verChamado($db, $idChamado) : null;
    if (!$c) {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    if ($papel !== 'tecnico' && (int) $c['usuario_id'] !== $uid) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $idSeguro = (int) $c['id'];
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    echo '<h1>Chamado #' . $idSeguro . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars((string) $c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><b>Status:</b> ' . htmlspecialchars(formatarStatus((int) $c['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><b>Prioridade:</b> '
       . htmlspecialchars(rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars((string) $c['descricao'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = (isset($_GET['busca']) && is_string($_GET['busca'])) ? $_GET['busca'] : '';
$chamados = listarChamadosVisiveis($db, $uid, $papel, $busca);
$media = mediaResposta($db);

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
$buscaSegura = htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . $buscaSegura . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . $buscaSegura . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    $idSeguro = (int) $c['id'];
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . $idSeguro . '">' . $idSeguro . '</a></td>';
    echo '<td>' . htmlspecialchars((string) $c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(formatarStatus((int) $c['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string) $c['tecnico_nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
