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

// Cookie de sessão fora do alcance de JS, não enviado em navegação cross-site
// e só por HTTPS quando o painel está atrás de TLS.
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
    if (isset($_POST['login'])) {
        $login = is_string($_POST['login']) ? $_POST['login'] : '';
        $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? $_POST['senha'] : '';
        $u = autenticar($db, $login, $senha);
        if ($u) {
            session_regenerate_id(true); // novo id após autenticar: evita fixação de sessão
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
$papel = $_SESSION['papel'];

// Regra de visibilidade (manifest.md): técnico vê qualquer chamado; qualquer
// outro papel só vê os chamados que ele mesmo abriu.
$donoId = ($papel === 'tecnico') ? null : $uid;

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($donoId === null) {
        exportarCsv($db);               // técnico: exportação completa
    } else {
        gerarCsvChamados($db, $donoId); // cliente: só os próprios chamados
    }
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $c = verChamado($db, is_scalar($_GET['ver']) ? (int) $_GET['ver'] : 0);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    // Mesma resposta para "não existe" e "não é seu": não revela ids alheios.
    if (!$c || ($donoId !== null && (int) $c['usuario_id'] !== $donoId)) {
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
$busca = isset($_GET['busca']) && is_string($_GET['busca']) ? $_GET['busca'] : '';
$chamados = consultarChamados($db, $busca, $donoId);
$media = mediaResposta($db);
$buscaHtml = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8');

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
