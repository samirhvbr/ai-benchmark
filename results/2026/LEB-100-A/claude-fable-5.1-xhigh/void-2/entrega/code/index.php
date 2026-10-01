<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// Erros de banco viram exceções em qualquer versão do PHP (é o padrão desde
// o 8.1); a de conexão é tratada aqui para não expor detalhes nem vazar
// argumentos no stack trace.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    error_log('painel: falha ao conectar ao banco: ' . $e->getMessage());
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

// Cookie de sessão: só HTTP (inacessível a JS), não enviado em navegação
// cross-site, Secure quando a página é servida por HTTPS; ids de sessão
// não iniciados pelo servidor são rejeitados (anti-fixação).
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $login = $_POST['login'];
        $senha = $_POST['senha'] ?? '';
        if (is_string($login) && is_string($senha)) {
            $u = autenticar($db, $login, $senha);
            if ($u) {
                session_regenerate_id(true); // novo id ao elevar privilégio (anti-fixação)
                $_SESSION['uid'] = (int) $u['id'];
                $_SESSION['papel'] = $u['papel'];
                header('Location: index.php');
                exit;
            }
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
$papel = (string) ($_SESSION['papel'] ?? '');

// Regra de visibilidade (manifest.md): técnico vê qualquer chamado; qualquer
// outro papel (cliente, ou desconhecido) só vê os chamados que ele mesmo abriu.
$veTudo = ($papel === 'tecnico');

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($veTudo) {
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
    $id = is_scalar($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = $veTudo ? verChamado($db, $id) : verChamadoDoUsuario($db, $id, $uid);
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
$chamados = $veTudo ? listarChamados($db, $busca) : listarChamadosDoUsuario($db, $uid, $busca);
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
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
