<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
if ($db->connect_errno) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
try {
    $charsetOk = $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception) {
    $charsetOk = false;
}
if (!$charsetOk) {
    http_response_code(500);
    die('Falha ao configurar o banco.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (ini_set('session.use_strict_mode', '1') === false) {
        http_response_code(500);
        die('Falha ao configurar a sessão.');
    }
    if (!session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ])) {
        http_response_code(500);
        die('Falha ao configurar a sessão.');
    }
    if (!session_start()) {
        http_response_code(500);
        die('Falha ao iniciar a sessão.');
    }
}

function e(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function getTexto(string $chave): string
{
    $valor = $_GET[$chave] ?? '';
    return is_string($valor) ? $valor : '';
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login'])) {
        $login = is_string($_POST['login'] ?? null) ? $_POST['login'] : '';
        $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $u = null;
        try {
            $u = autenticarComFalha($db, $login, $senha);
        } catch (FalhaBanco $erro) {
            error_log($erro->getMessage());
            http_response_code(500);
            echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
            echo '<h1>Erro</h1><p>Falha ao autenticar.</p>';
            exit;
        }
        if ($u) {
            if (!session_regenerate_id(true)) {
                $_SESSION = [];
                session_destroy();
                http_response_code(500);
                echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
                echo '<h1>Erro</h1><p>Falha ao renovar a sessao.</p>';
                exit;
            }
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

try {
    $contexto = contextoSessao($db);
} catch (FalhaContextoSessao $erro) {
    error_log($erro->getMessage());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
    echo '<h1>Erro</h1><p>Falha ao validar a sessao.</p>';
    exit;
}
if ($contexto === false) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!is_array($contexto)) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
    echo '<h1>Erro</h1><p>Falha ao validar a sessao.</p>';
    exit;
}

$uid = $contexto['uid'];
$papel = $contexto['papel'];

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
$export = $_GET['export'] ?? '';
if (is_string($export) && $export === 'csv') {
    try {
        exportarCsvComContexto($db, $uid, $papel);
    } catch (FalhaExportacaoCsv|FalhaContextoSessao $erro) {
        error_log($erro->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
            echo '<h1>Erro</h1><p>Falha ao exportar os chamados.</p>';
        }
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (array_key_exists('ver', $_GET)) {
    $id = filter_var($_GET['ver'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    try {
        $c = is_int($id) ? verChamadoComContexto($db, $id, $uid, $papel) : null;
    } catch (FalhaBanco $erro) {
        error_log($erro->getMessage());
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
        echo '<h1>Erro</h1><p>Falha ao carregar o chamado.</p>';
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . e((string) $c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . e((string) $c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = getTexto('busca');
try {
    $chamados = listarChamadosComContexto($db, $busca, $uid, $papel);
    $media = mediaRespostaComContexto($db, $uid, $papel);
} catch (FalhaBanco $erro) {
    error_log($erro->getMessage());
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title>';
    echo '<h1>Erro</h1><p>Falha ao carregar os chamados.</p>';
    exit;
}

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . e($busca) . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . e($busca) . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . e((string) $c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . e((string) ($c['tecnico_nome'] ?? '-')) . '</td>';
    echo '</tr>';
}
echo '</table>';
