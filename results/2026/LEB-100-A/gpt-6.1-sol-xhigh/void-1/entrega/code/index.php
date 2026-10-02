<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
ini_set('display_errors', '0');

try {
    require __DIR__ . '/config.php';
    require __DIR__ . '/lib.php';

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$db->set_charset('utf8mb4')) {
        throw new RuntimeException('Falha ao configurar o banco.');
    }

    if (!session_start([
        'use_strict_mode' => 1,
        'use_only_cookies' => 1,
        'cookie_httponly' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cookie_samesite' => 'Lax',
    ])) {
        throw new RuntimeException('Falha ao iniciar a sessão.');
    }
    header('Cache-Control: no-store');

    // Rejeitar arrays antes de passar parâmetros HTTP às funções tipadas.
    foreach (['busca', 'ver', 'export'] as $parametro) {
        if (isset($_GET[$parametro]) && !is_string($_GET[$parametro])) {
            http_response_code(400);
            echo 'Parametro invalido.';
            exit;
        }
    }
    foreach (['login', 'senha'] as $parametro) {
        if (isset($_POST[$parametro]) && !is_string($_POST[$parametro])) {
            http_response_code(400);
            echo 'Parametro invalido.';
            exit;
        }
    }
    if (isset($_SESSION['uid'])
        && (filter_var($_SESSION['uid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || !in_array($_SESSION['papel'] ?? null, ['cliente', 'tecnico'], true))) {
        session_unset();
    }

    // ---------------------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------------------
    if (!isset($_SESSION['uid'])) {
        if (isset($_POST['login'])) {
            $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
            if ($u) {
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('Falha ao renovar a sessão.');
                }
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

    // Liberar o lock da sessão antes das consultas e da exportação.
    session_write_close();

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
        $c = verChamado($db, (int) $_GET['ver']);
        echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
        if (!$c) {
            echo '<p>Chamado nao encontrado.</p>';
            exit;
        }
        echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
        echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
        echo '<p><b>Prioridade:</b> '
           . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
        echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        echo '<p><a href="index.php">Voltar</a></p>';
        exit;
    }

    // ---------------------------------------------------------------------------
    // Listagem + busca
    // ---------------------------------------------------------------------------
    $busca = $_GET['busca'] ?? '';
    $buscaHtml = htmlspecialchars($busca, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        echo '<td>' . htmlspecialchars($c['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
        echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
        echo '<td>' . htmlspecialchars($c['tecnico_nome'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
} catch (Throwable $erro) {
    // Registrar tipo/código sem expor SQL, credenciais ou entradas do usuário.
    error_log('Painel: ' . get_class($erro) . ' (codigo ' . $erro->getCode() . ')');
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Falha ao processar a solicitacao.';
}
