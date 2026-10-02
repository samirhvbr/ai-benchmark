<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// A partir do PHP 8.1 o mysqli lança mysqli_sql_exception na falha de conexão (em vez de
// preencher connect_errno); sem o try/catch, o die() abaixo nunca era alcançado.
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    $db = null;
}
if ($db === null || $db->connect_errno) {
    http_response_code(503);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

// Cookie de sessão: inacessível a JavaScript, não enviado em requisições cross-site e, sob
// HTTPS, só trafega por HTTPS. Ids de sessão que o servidor não emitiu não são aceitos.
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    $login = $_POST['login'] ?? null;
    $senha = $_POST['senha'] ?? '';
    if (is_string($login) && is_string($senha)) {
        $u = autenticar($db, $login, $senha);
        if ($u) {
            // Novo id de sessão após autenticar: um id plantado antes do login não vale depois.
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
// Regra de visibilidade (manifest.md): só 'tecnico' vê tudo; qualquer outro valor = cliente.
$papel = ($_SESSION['papel'] ?? '') === 'tecnico' ? 'tecnico' : 'cliente';

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
    // Chamado de outro cliente é tratado como inexistente: mesma resposta, sem revelar que existe.
    $c = verChamadoVisivel($db, is_string($_GET['ver']) ? (int) $_GET['ver'] : 0, $uid, $papel);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . $c['id'] . '</h1>';
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
$chamados = listarChamadosVisiveis($db, $uid, $papel, $busca);
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
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . $c['id'] . '">' . $c['id'] . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
