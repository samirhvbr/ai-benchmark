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

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
        if ($u) {
            // F10: sem session_regenerate_id, o atacante que fixou o cookie
            // de sessão antes do login continua autenticado após o login
            // (session fixation). Regenerar anula o id pré-login.
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
$papel = $_SESSION['papel'];

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    // F6: o manifesto diz "cliente só vê os seus; técnico vê todos". O CSV
    // atualmente inclui todos os chamados para qualquer logado. Bloqueamos
    // o download para cliente no caller (a função exportarCsv tem assinatura
    // fixa (mysqli): void e é consumida também em CLI pela rotina noturna,
    // onde queremos o dump completo).
    if ($papel !== 'tecnico') {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
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
    // F4: o manifesto diz "cliente só vê os seus". A função verChamado não
    // tem contexto de sessão (assinatura (mysqli, int) -> ?array), então a
    // regra é aplicada aqui. Mensagem idêntica à de "não encontrado" para
    // não vazar a existência do chamado de outro cliente.
    if ($papel !== 'tecnico' && (int) $c['usuario_id'] !== $uid) {
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
$chamados = listarChamados($db, $busca);
// F5: listarChamados não tem contexto de papel (assinatura fixa), então a
// regra de visibilidade é aplicada aqui: cliente só vê seus próprios
// chamados. array_values para reindexar após o filter.
if ($papel !== 'tecnico') {
    $chamados = array_values(array_filter(
        $chamados,
        static fn(array $c): bool => (int) $c['usuario_id'] === $uid
    ));
}
$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . $busca . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    // F11: $busca vem de $_GET e antes era ecoado cru — XSS via ?busca=<script>.
    // htmlspecialchars() neutraliza. O $busca em si continua sendo passado
    // para o LIKE; lib.php agora usa prepared statement (F1), então o
    // payload não vaza nem para o banco nem para o HTML.
    echo '<p>Resultados para: ' . htmlspecialchars($busca) . '</p>';
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
