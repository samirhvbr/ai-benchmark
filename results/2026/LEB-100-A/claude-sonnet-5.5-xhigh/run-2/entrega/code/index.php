<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

/** Escapa texto para HTML (corpo e atributos entre aspas). */
function h(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// A conexão falha por exceção (PHP >= 8.1) ou por connect_errno (anterior). Em ambos os casos
// o detalhe (host, usuário) vai só para o log, nunca para o navegador.
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $falhaConexao = $db->connect_errno ? $db->connect_error : null;
} catch (mysqli_sql_exception $e) {
    $falhaConexao = $e->getMessage();
}
if ($falhaConexao !== null) {
    error_log('index.php: falha ao conectar ao banco: ' . $falhaConexao);
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $login = $_POST['login'];
        $senha = $_POST['senha'] ?? '';
        // login[]=x / senha[]=x chegam como array: não é credencial válida (e quebraria a tipagem de autenticar()).
        $u = (is_string($login) && is_string($senha)) ? autenticar($db, $login, $senha) : null;
        if ($u) {
            session_regenerate_id(true); // novo id ao mudar de anônimo para autenticado (anti session fixation)
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
$papel = (string) $_SESSION['papel'];
// Regra de visibilidade: técnico vê todos os chamados; cliente só os que abriu.
$donoId = escopoVisibilidade($papel, $uid);

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvVisivel($db, $donoId);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $id = is_string($_GET['ver']) ? (int) $_GET['ver'] : 0;
    $c = verChamadoVisivel($db, $donoId, $id);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        // Mesma resposta para "não existe" e "não é seu": não revela quais ids existem.
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
$busca = $_GET['busca'] ?? '';
if (!is_string($busca)) { // busca[]=x chega como array
    $busca = '';
}
$chamados = listarChamadosVisiveis($db, $donoId, $busca);
$media = mediaResposta($db);

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
