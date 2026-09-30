<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// Erros do mysqli viram exceção em qualquer versão do PHP (padrão do 8.1+). Sem isto, o teste de
// connect_errno nunca roda no 8.1+ e uma falha de conexão vira erro fatal com stack trace.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('painel: falha ao conectar ao banco: ' . $e->getMessage());
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}

// Cookie de sessão: HttpOnly (JS não lê o id), SameSite=Lax (links/favoritos externos continuam
// funcionando), Secure quando a requisição é HTTPS, e modo estrito (recusa ids não emitidos pelo servidor).
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
    // login/senha só valem como texto (login[]=x derrubava a página com TypeError)
    if (isset($_POST['login']) && is_string($_POST['login'])) {
        $senha = $_POST['senha'] ?? '';
        $u = autenticar($db, $_POST['login'], is_string($senha) ? $senha : '');
        if ($u) {
            session_regenerate_id(true); // id novo após autenticar: evita fixação de sessão
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
// Visibilidade (manifest.md): técnico vê qualquer chamado; cliente só os que abriu.
// $dono = id a que as consultas ficam restritas (null = sem restrição).
$dono = escopoChamados($uid, $papel);

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvDoEscopo($db, $dono);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    // Chamado inexistente e chamado de outro cliente recebem a MESMA resposta: não revela a existência.
    $c = verChamadoDoEscopo($db, $dono, (int) $_GET['ver']);
    if (!$c) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], minutosRespostaDe($c)) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = $_GET['busca'] ?? '';
if (!is_string($busca)) {
    $busca = ''; // busca[]=x derrubava a página com TypeError
}
$chamados = listarChamadosDoEscopo($db, $dono, $busca);
$media = mediaResposta($db);
$buscaHtml = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8'); // o termo volta na página: escapar

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
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], minutosRespostaDe($c)) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
