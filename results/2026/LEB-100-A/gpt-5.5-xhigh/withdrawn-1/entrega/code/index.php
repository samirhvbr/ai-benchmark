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

function h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function usuarioPodeVerChamado(array $chamado, int $uid, string $papel): bool
{
    return $papel === 'tecnico' || (int) $chamado['usuario_id'] === $uid;
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $login = is_scalar($_POST['login']) ? (string) $_POST['login'] : '';
        $senha = isset($_POST['senha']) && is_scalar($_POST['senha']) ? (string) $_POST['senha'] : '';
        $u = autenticar($db, $login, $senha);
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
$papel = ($_SESSION['papel'] ?? '') === 'tecnico' ? 'tecnico' : 'cliente';

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($papel === 'tecnico') {
        exportarCsv($db);
    } else {
        exportarCsvCliente($db, $uid);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $ver = is_scalar($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = $ver > 0 ? verChamado($db, $ver) : null;
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c || !usuarioPodeVerChamado($c, $uid, $papel)) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . h($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . h($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = isset($_GET['busca']) && is_scalar($_GET['busca']) ? (string) $_GET['busca'] : '';
$chamados = listarChamados($db, $busca);
if ($papel !== 'tecnico') {
    $chamados = array_values(array_filter($chamados, function (array $chamado) use ($uid, $papel): bool {
        return usuarioPodeVerChamado($chamado, $uid, $papel);
    }));
}
$media = $papel === 'tecnico' ? mediaResposta($db) : mediaRespostaCliente($db, $uid);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . h($busca) . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . h($busca) . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . h($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . h($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
