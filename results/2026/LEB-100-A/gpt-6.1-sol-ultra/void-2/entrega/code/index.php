<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

ini_set('session.use_strict_mode', '1');
$cookie = session_get_cookie_params();
$cookie['secure'] = $cookie['secure'] || (!empty($_SERVER['HTTPS'])
    && strtolower((string) $_SERVER['HTTPS']) !== 'off');
$cookie['httponly'] = true;
$cookie['samesite'] = 'Lax';
session_set_cookie_params($cookie);
session_start();

$escapar = static function ($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

// Conexao e consultas devem falhar sem expor SQL ou credenciais no HTTP.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$nivelSaida = ob_get_level();
ob_start();
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');

    // ---------------------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------------------
    if (!isset($_SESSION['uid'])) {
        if (isset($_POST['login'])) {
            $senha = $_POST['senha'] ?? '';
            $u = null;
            if (is_string($_POST['login']) && is_string($senha)) {
                $u = autenticar($db, $_POST['login'], $senha);
            } else {
                http_response_code(400);
            }
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

    // ---------------------------------------------------------------------------
    // Exportação CSV (tratada antes de qualquer saída HTML)
    // ---------------------------------------------------------------------------
    if (($_GET['export'] ?? '') === 'csv') {
        // O CSV já é preparado em um stream privado antes de enviar os headers.
        // Evita manter uma segunda cópia inteira no buffer de saída HTML.
        ob_end_clean();
        exportarCsv($db);
        exit;
    }

    // ---------------------------------------------------------------------------
    // Detalhe de um chamado
    // ---------------------------------------------------------------------------
    if (isset($_GET['ver'])) {
        $id = is_string($_GET['ver']) && preg_match('/^[1-9][0-9]*$/D', $_GET['ver'])
            ? filter_var($_GET['ver'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($id === false) {
            http_response_code(400);
        }
        $c = $id === false ? null : verChamado($db, $id);
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        if (!$c) {
            echo '<p>Chamado nao encontrado.</p>';
            exit;
        }
        echo '<h1>Chamado #' . $escapar($c['id']) . '</h1>';
        echo '<p><b>Titulo:</b> ' . $escapar($c['titulo']) . '</p>';
        echo '<p><b>Status:</b> ' . $escapar(formatarStatus((int) $c['status'])) . '</p>';
        echo '<p><b>Prioridade:</b> '
           . $escapar(rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null)) . '</p>';
        echo '<p><b>Descricao:</b> ' . $escapar($c['descricao']) . '</p>';
        echo '<p><a href="index.php">Voltar</a></p>';
        exit;
    }

    // ---------------------------------------------------------------------------
    // Listagem + busca
    // ---------------------------------------------------------------------------
    $busca = $_GET['busca'] ?? '';
    if (!is_string($busca)) {
        http_response_code(400);
        echo '<!doctype html><meta charset="utf-8"><title>Erro</title><p>Parametros invalidos.</p>';
        exit;
    }
    $chamados = listarChamados($db, $busca);
    $media = mediaResposta($db);

    echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
    echo '<h1>Chamados</h1>';
    echo '<p>Tempo medio de 1a resposta: ' . $escapar(round($media)) . ' min</p>';
    echo '<form method="get"><input name="busca" value="' . $escapar($busca) . '" placeholder="buscar titulo">'
       . '<button>Buscar</button></form>';
    if ($busca !== '') {
        echo '<p>Resultados para: ' . $escapar($busca) . '</p>';
    }
    echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

    echo '<table id="tabela-chamados" border="1">';
    echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
    foreach ($chamados as $c) {
        echo '<tr>';
        echo '<td><a href="index.php?ver=' . $escapar(rawurlencode((string) $c['id'])) . '">' . $escapar($c['id']) . '</a></td>';
        echo '<td>' . $escapar($c['titulo']) . '</td>';
        echo '<td>' . $escapar(formatarStatus((int) $c['status'])) . '</td>';
        echo '<td>' . $escapar(rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null)) . '</td>';
        echo '<td>' . $escapar($c['tecnico_nome']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    ob_end_flush();
} catch (RuntimeException $e) {
    while (ob_get_level() > $nivelSaida) {
        ob_end_clean();
    }
    error_log('Falha no painel (' . get_class($e) . ' codigo ' . $e->getCode() . ').');
    http_response_code(500);
    header_remove('Content-Disposition');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Erro</title><p>Falha ao carregar os dados.</p>';
}
