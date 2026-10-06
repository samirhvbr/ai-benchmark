<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

function fecharBanco(?mysqli $db): void
{
    if ($db !== null) {
        try {
            @$db->close();
        } catch (Throwable $_) {
        }
    }
}

function abrirBanco(): ?mysqli
{
    $db = null;
    try {
        $db = mysqli_init();
        if ($db === false) {
            return null;
        }
        if (!@mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, DB_CONNECT_TIMEOUT)) {
            fecharBanco($db);
            return null;
        }

        $flags = 0;
        if (DB_SSL_ENABLED) {
            if (DB_SSL_CA === '' || !@$db->ssl_set('', '', DB_SSL_CA, '', '')) {
                fecharBanco($db);
                return null;
            }
            $flags = MYSQLI_CLIENT_SSL;
            if (DB_SSL_VERIFY_SERVER_CERT) {
                if (!defined('MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT')) {
                    fecharBanco($db);
                    return null;
                }
                $flags |= MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT;
            }
        }

        if (!@$db->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, 0, null, $flags)) {
            fecharBanco($db);
            return null;
        }
        if (!@$db->set_charset('utf8mb4')) {
            fecharBanco($db);
            return null;
        }
        return $db;
    } catch (Throwable $_) {
        fecharBanco($db);
        return null;
    }
}

function definirHeadersSeguranca(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
}

function encerrarSessao(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $parametros = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parametros['path'],
                $parametros['domain'],
                $parametros['secure'],
                $parametros['httponly']
            );
        }
        session_destroy();
    }
}

function iniciarSessaoSegura(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', SESSION_COOKIE_SECURE ? '1' : '0');
    session_set_cookie_params(
        0,
        '/',
        '',
        SESSION_COOKIE_SECURE,
        true
    );

    return session_start();
}

function idParametroGet(string $nome): int
{
    $valor = $_GET[$nome] ?? null;
    if (!is_string($valor) || !ctype_digit($valor)) {
        return 0;
    }

    $id = filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);
    return $id === false ? 0 : (int) $id;
}

definirHeadersSeguranca();
$db = abrirBanco();
if ($db === null) {
    http_response_code(503);
    exit('Falha ao conectar ao banco.');
}

if (!iniciarSessaoSegura()) {
    http_response_code(500);
    exit('Falha ao iniciar sessão.');
}

function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        $login = isset($_POST['login']) && is_string($_POST['login']) ? $_POST['login'] : '';
        $senha = isset($_POST['senha']) && is_string($_POST['senha']) ? $_POST['senha'] : '';
        $u = autenticar($db, $login, $senha);
        if ($u && in_array($u['papel'], ['cliente', 'tecnico'], true)) {
            try {
                $regenerada = session_regenerate_id(true);
            } catch (Throwable $_) {
                $regenerada = false;
            }
            if (!$regenerada) {
                encerrarSessao();
                header('Location: index.php');
                exit;
            }
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            $_SESSION['leb_ultima_atividade'] = time();
            $_SESSION['leb_sessao_validada'] = true;
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

$usuarioId = usuarioIdParaAcessoValidado($db);
if ($usuarioId === 0) {
    encerrarSessao();
    header('Location: index.php');
    exit;
}

if (($_GET['export'] ?? '') === 'csv') {
    exportarCsvComAcesso($db, $usuarioId);
    exit;
}

if (isset($_GET['ver'])) {
    $id = idParametroGet('ver');
    $c = verChamadoComAcesso($db, $id, $usuarioId);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        http_response_code(404);
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . (int) $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . e($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . e($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

$busca = isset($_GET['busca']) && is_string($_GET['busca']) ? normalizarBusca($_GET['busca']) : '';
$chamados = listarChamadosComAcesso($db, $usuarioId, $busca);
$media = mediaRespostaComAcesso($db, $usuarioId);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . e($busca) . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    echo '<p>Resultados para: ' . e($busca) . '</p>';
}
echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . (int) $c['id'] . '">' . (int) $c['id'] . '</a></td>';
    echo '<td>' . e($c['titulo']) . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . e($c['tecnico_nome']) . '</td>';
    echo '</tr>';
}
echo '</table>';
