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

// Cookie de sessão sem exposição a JavaScript e sem envio em navegação cruzada.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

try {
    // -----------------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------------
    if (!isset($_SESSION['uid'])) {
        if (isset($_POST['login'])) {
            $usuario = is_string($_POST['login']) ? $_POST['login'] : '';
            $senha = is_string($_POST['senha'] ?? null) ? $_POST['senha'] : '';
            $u = autenticar($db, $usuario, $senha);
            if ($u) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int) $u['id'];
                $_SESSION['papel'] = (string) $u['papel'];
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

    // Regra de negócio (manifesto): cliente só vê os chamados que ele mesmo
    // abriu; técnico vê todos. O papel é lido do banco (fonte de verdade) e
    // qualquer papel diferente de 'tecnico' vale como cliente — ou seja, na
    // dúvida o sistema restringe em vez de expor chamados.
    $papel = papelDoUsuario($db, $uid);
    $ehCliente = (strtolower((string) $papel) !== 'tecnico');
    $filtroUsuarioId = $ehCliente ? $uid : null;

    // -----------------------------------------------------------------------
    // Exportação CSV (tratada antes de qualquer saída HTML)
    // -----------------------------------------------------------------------
    if (($_GET['export'] ?? '') === 'csv') {
        exportarCsvVisivel($db, $filtroUsuarioId);
        exit;
    }

    // -----------------------------------------------------------------------
    // Detalhe de um chamado
    // -----------------------------------------------------------------------
    if (isset($_GET['ver'])) {
        $id = is_string($_GET['ver']) ? (int) $_GET['ver'] : 0;
        $c = verChamado($db, $id);
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        if (!$c || ($ehCliente && (int) $c['usuario_id'] !== $uid)) {
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

    // -----------------------------------------------------------------------
    // Listagem + busca
    // -----------------------------------------------------------------------
    $busca = is_string($_GET['busca'] ?? null) ? $_GET['busca'] : '';
    $chamados = listarChamadosVisiveis($db, $busca, $filtroUsuarioId);
    $media = mediaResposta($db);

    echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
    echo '<h1>Chamados</h1>';
    echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
    echo '<form method="get"><input name="busca" value="' . htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') . '" placeholder="buscar titulo">'
       . '<button>Buscar</button></form>';
    if ($busca !== '') {
        echo '<p>Resultados para: ' . htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') . '</p>';
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
} catch (Throwable $e) {
    // Erro inesperado: registra no log e devolve mensagem genérica
    // (sem stack trace ou detalhe do banco para o usuário).
    error_log('index.php: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Erro interno ao montar o painel.';
}
