<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// ---------------------------------------------------------------------------
// Conexão
// ---------------------------------------------------------------------------
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('[painel] falha de conexao: ' . $e->getMessage());
    die('Falha ao conectar ao banco.');
}

// ---------------------------------------------------------------------------
// Sessão
// ---------------------------------------------------------------------------
$https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
}

/**
 * Tela de login (também usada quando a sessão expira ou é invalidada).
 */
function mostrarLogin(): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $usuarioInformado = is_string($_POST['login']) ? $_POST['login'] : '';
        $senhaInformada = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
        $u = autenticar($db, $usuarioInformado, $senhaInformada);
        if ($u) {
            // Renova o id da sessão no login (evita fixação de sessão).
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            $_SESSION['ultima_atividade'] = time();
            header('Location: index.php');
            exit;
        }
    }
    mostrarLogin();
    exit;
}

// ---------------------------------------------------------------------------
// Sessão válida?
// ---------------------------------------------------------------------------
$uid = (int) $_SESSION['uid'];
$usuario = $uid > 0 ? carregarUsuarioPainel($db, $uid) : null;
$ultimaAtividade = (int) ($_SESSION['ultima_atividade'] ?? 0);
$expirou = $ultimaAtividade > 0 && (time() - $ultimaAtividade) > SESSION_TIMEOUT;

if ($usuario === null || $expirou) {
    // Usuário removido/renomeado no banco ou sessão ociosa demais.
    $_SESSION = [];
    session_destroy();
    mostrarLogin();
    exit;
}

$_SESSION['papel'] = $usuario['papel'];
$_SESSION['ultima_atividade'] = time();

// A partir daqui as consultas respeitam a regra de visibilidade do manifesto.
ContextoPainel::definir(['id' => (int) $usuario['id'], 'papel' => $usuario['papel']]);

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsv($db);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $id = is_scalar($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = verChamado($db, $id);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars((string) $c['titulo'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars((string) $c['descricao'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = isset($_GET['busca']) && is_scalar($_GET['busca']) ? (string) $_GET['busca'] : '';
$buscaHtml = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8');
$chamados = listarChamados($db, $busca);
$media = mediaResposta($db);

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
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . htmlspecialchars((string) $c['titulo'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars((string) $c['tecnico_nome'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
