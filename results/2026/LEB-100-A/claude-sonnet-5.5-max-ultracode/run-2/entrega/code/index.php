<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// Exceção sem tratamento vira resposta genérica; o detalhe vai só para o log. O log leva a mensagem,
// onde ocorreu, a rota e quem chamou, mas NUNCA os argumentos das chamadas (um login falho teria a senha).
set_exception_handler(function (Throwable $e) {
    $trilha = [];
    foreach ($e->getTrace() as $q) {
        $trilha[] = (isset($q['class']) ? $q['class'] . $q['type'] : '') . $q['function'] . '() '
            . ($q['file'] ?? '?') . ':' . ($q['line'] ?? '?');
    }
    error_log('painel: ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine()
        . ' [' . substr($_SERVER['REQUEST_URI'] ?? '-', 0, 200) . '] ' . implode(' < ', $trilha));
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Erro interno. Tente novamente em instantes.';
});

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    // PHP >= 8.1 lança exceção em vez de preencher connect_errno
    error_log('painel: falha ao conectar ao banco: ' . $e->getMessage());
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
if ($db->connect_errno) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

// Sessão: cookie inacessível ao JavaScript, SameSite=Lax (existe a partir do PHP 7.3), modo estrito
// (recusa ids de sessão que o servidor não emitiu) e Secure quando a requisição é HTTPS.
$opcoesSessao = ['use_strict_mode' => 1, 'cookie_httponly' => 1];
if (PHP_VERSION_ID >= 70300) {
    $opcoesSessao['cookie_samesite'] = 'Lax';
}
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $opcoesSessao['cookie_secure'] = 1;
}
session_start($opcoesSessao);

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        // só strings: login[]=x ou senha[]=x chegariam como array e derrubariam a requisição (TypeError)
        $login = is_string($_POST['login']) ? $_POST['login'] : '';
        $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? $_POST['senha'] : '';
        $u = autenticar($db, $login, $senha);
        if ($u) {
            session_regenerate_id(true); // id novo ao autenticar: impede fixação de sessão
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
$papel = (string) ($_SESSION['papel'] ?? '');

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
    $c = verChamado($db, (int) $_GET['ver']);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    // inexistente e "de outro cliente" respondem igual: não revela que o id existe
    if (!$c || !chamadoVisivel($c, $uid, $papel)) {
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
    $busca = ''; // busca[]=x chegaria como array e derrubaria a página (TypeError)
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
