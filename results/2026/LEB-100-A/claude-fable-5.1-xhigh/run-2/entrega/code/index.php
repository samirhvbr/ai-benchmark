<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
} catch (mysqli_sql_exception $e) {
    $db = null; // PHP >= 8.1 lança exceção em vez de preencher connect_errno
}
if ($db === null || $db->connect_errno) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

// Cookie de sessão: fora do alcance de JavaScript, não enviado em navegação
// cross-site, só por HTTPS quando a requisição veio por HTTPS, e sem aceitar
// id de sessão não iniciado pelo servidor.
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

/** Parâmetro de requisição como string; ausente ou array (ex.: busca[]=x) vira ''. */
function parametroTexto(array $fonte, string $nome): string
{
    return isset($fonte[$nome]) && is_string($fonte[$nome]) ? $fonte[$nome] : '';
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $login = parametroTexto($_POST, 'login');
        $u = autenticar($db, $login, parametroTexto($_POST, 'senha'));
        if ($u) {
            session_regenerate_id(true); // novo id após autenticar: impede fixação de sessão
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            header('Location: index.php');
            exit;
        }
        // Rastro para bloqueio externo (fail2ban/WAF); a aplicação não limita tentativas.
        error_log(sprintf(
            'painel: falha de login para "%s" de %s',
            substr(preg_replace('/[^\x20-\x7E]/', '?', $login), 0, 60),
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ));
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
$ehTecnico = ($papel === 'tecnico'); // qualquer outro papel segue a regra do cliente

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if ($ehTecnico) {
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
    $c = verChamado($db, (int) parametroTexto($_GET, 'ver'));
    if ($c && !podeVerChamado($c, $uid, $papel)) {
        $c = null; // mesma resposta de "não existe": não revela chamados alheios
    }
    if (!$c) {
        http_response_code(404);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
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
$busca = parametroTexto($_GET, 'busca');
$chamados = $ehTecnico ? listarChamados($db, $busca) : listarChamadosDoUsuario($db, $uid, $busca);
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
