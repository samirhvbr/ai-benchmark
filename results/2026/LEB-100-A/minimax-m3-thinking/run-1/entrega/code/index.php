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

// Cookies de sessão mais seguros.
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    $loginErro = false;
    if (isset($_POST['login'])) {
        $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
        if ($u) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            header('Location: index.php');
            exit;
        }
        $loginErro = true;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    if ($loginErro) {
        echo '<p style="color:#b00">Usuário ou senha inválidos.</p>';
    }
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
$papel = $_SESSION['papel'];

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    if ($papel === 'tecnico') {
        $res = $db->query('SELECT * FROM chamados ORDER BY id');
        if ($res !== false) {
            while ($c = $res->fetch_assoc()) {
                $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
                fputcsv($out, [
                    $c['id'],
                    $c['titulo'],
                    formatarStatus((int) $c['status']),
                    $tecnico,
                    $c['criado_em'],
                ]);
            }
        }
    } else {
        // Cliente: respeita a regra de negócio — só os próprios.
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY id');
        if ($stmt !== false) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($c = $res->fetch_assoc()) {
                $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
                fputcsv($out, [
                    $c['id'],
                    $c['titulo'],
                    formatarStatus((int) $c['status']),
                    $tecnico,
                    $c['criado_em'],
                ]);
            }
        }
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $c = verChamado($db, (int) $_GET['ver']);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c || ($papel === 'cliente' && (int) $c['usuario_id'] !== $uid)) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    $idC = (int) $c['id'];
    $status = (int) $c['status'];
    $prio = (int) $c['prioridade'];
    $minResp = $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null;
    echo '<h1>Chamado #' . $idC . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus($status) . '</p>';
    echo '<p><b>Prioridade:</b> ' . rotuloPrioridade($prio, $minResp) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
$busca = $_GET['busca'] ?? '';
$buscaEsc = htmlspecialchars($busca, ENT_QUOTES, 'UTF-8');
$chamados = listarChamados($db, $busca);

// Regra de negócio: cliente só vê os próprios.
if ($papel === 'cliente') {
    $chamados = array_values(array_filter(
        $chamados,
        static fn($c) => (int) $c['usuario_id'] === $uid
    ));
}

$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . (int) round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . $buscaEsc . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . $buscaEsc . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    $idC = (int) $c['id'];
    $uidC = (int) $c['usuario_id'];
    $status = (int) $c['status'];
    $prio = (int) $c['prioridade'];
    $minResp = $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null;
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . $idC . '">' . $idC . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . formatarStatus($status) . '</td>';
    echo '<td>' . rotuloPrioridade($prio, $minResp) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';