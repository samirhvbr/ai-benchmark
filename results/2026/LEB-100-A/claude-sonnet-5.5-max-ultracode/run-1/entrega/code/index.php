<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

// Erros vão para o log, nunca para o navegador: uma exceção não tratada mostraria usuário/host do banco,
// caminhos e trechos de SQL se o php.ini de produção estiver com display_errors=On.
ini_set('display_errors', '0');

/** Escapa texto para HTML (conteúdo e atributos). */
function escaparHtml(?string $texto): string
{
    return htmlspecialchars((string) $texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); // NULL vira vazio, como antes
}

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conectou = !$db->connect_errno;
} catch (mysqli_sql_exception $e) { // PHP >= 8.1 lança exceção em vez de preencher connect_errno
    error_log('index.php: conexao ao banco falhou: ' . $e->getMessage());
    $conectou = false;
}
if (!$conectou) {
    http_response_code(500);
    die('Falha ao conectar ao banco.');
}
$db->set_charset('utf8mb4');

// Cookie de sessão: HttpOnly; SameSite=Lax (PHP >= 7.3; links externos e favoritos, que são navegações de
// topo por GET, continuam valendo); Secure quando $_SERVER['HTTPS'] indica HTTPS (atrás de proxy que termina
// TLS, o proxy precisa repassar HTTPS); ids que o servidor não emitiu são descartados (use_strict_mode).
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        // login[]=x / senha[]=x chegam como array: tratados como texto vazio (antes: TypeError -> HTTP 500)
        $login = is_string($_POST['login']) ? $_POST['login'] : '';
        $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? $_POST['senha'] : '';
        $u = autenticar($db, $login, $senha);
        if ($u) {
            session_regenerate_id(true); // novo id ao autenticar: um id plantado antes do login não vale
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
// Daqui em diante a sessão é só lida: solta o lock do arquivo de sessão para que uma listagem ou export lento
// não segure as outras requisições do mesmo usuário (o PHP mantém o lock até o fim do script).
session_write_close();
// Regra de visibilidade do manifesto: técnico vê tudo; cliente (e papel desconhecido) só o que abriu.
$dono = escopoDono($papel, $uid);

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvDoDono($db, $dono);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    // Chamado de outro cliente responde exatamente como chamado inexistente.
    $c = verChamadoDoDono($db, $dono, (int) $_GET['ver']);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . escaparHtml($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . escaparHtml($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// ---------------------------------------------------------------------------
// busca[]=x chega como array: tratada como busca vazia (antes: TypeError -> HTTP 500)
$busca = isset($_GET['busca']) && is_string($_GET['busca']) ? $_GET['busca'] : '';
$chamados = listarChamadosDoDono($db, $dono, $busca);
$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . escaparHtml($busca) . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . escaparHtml($busca) . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . escaparHtml($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . escaparHtml($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
