<?php
/**
 * index.php — Painel de Chamados (NetX ISP)
 * Ponto de entrada web. Faz login, roteia, consulta e monta o HTML.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

function exibirLogin(): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
}

function prioridadeDoChamado(array $c): string
{
    $minutos = $c['minutos_resposta'] === null ? null : (int) $c['minutos_resposta'];
    return rotuloPrioridade((int) $c['prioridade'], $minutos);
}

function exibirDetalhe(?array $c): void
{
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        return;
    }
    echo '<h1>Chamado #' . $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo']) . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> ' . prioridadeDoChamado($c) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao']) . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
}

function exibirListagem(string $busca, array $chamados, float $media): void
{
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
        echo '<td>' . prioridadeDoChamado($c) . '</td>';
        echo '<td>' . htmlspecialchars($c['tecnico_nome']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

function parametroTexto(array $origem, string $nome): string
{
    $valor = $origem[$nome] ?? '';
    if (!is_string($valor)) {
        http_response_code(400);
        exit('Parâmetro inválido.');
    }
    return $valor;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    if (DB_PASS === '') {
        throw new RuntimeException('DB_PASS ausente.');
    }
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');

    if (!session_start(['use_strict_mode' => 1, 'cookie_httponly' => 1])) {
        throw new RuntimeException('Falha ao iniciar a sessão.');
    }
    if (!isset($_SESSION['uid'])) {
        if (isset($_POST['login'])) {
            $u = autenticar($db, parametroTexto($_POST, 'login'), parametroTexto($_POST, 'senha'));
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
        exibirLogin();
        exit;
    }

    $uid = (int) $_SESSION['uid'];
    session_write_close();
    $papel = papelAtualDoUsuario($db, $uid);
    if ($papel !== 'cliente' && $papel !== 'tecnico') {
        http_response_code(403);
        exit('Acesso negado.');
    }
    $dono = $papel === 'cliente' ? $uid : null;
    if (parametroTexto($_GET, 'export') === 'csv') {
        exportarCsvPorDono($db, $dono);
        exit;
    }
    if (isset($_GET['ver'])) {
        $c = verChamado($db, (int) parametroTexto($_GET, 'ver'));
        if ($c && $dono !== null && (int) $c['usuario_id'] !== $dono) {
            $c = null;
        }
        exibirDetalhe($c);
        exit;
    }

    $busca = parametroTexto($_GET, 'busca');
    exibirListagem($busca, listarChamadosPorDono($db, $busca, $dono), mediaRespostaPorDono($db, $dono));
} catch (RuntimeException $e) {
    // Não registrar a mensagem do driver, que pode conter dados da conexão.
    error_log('Falha no painel (' . get_class($e) . ', código ' . $e->getCode() . ').');
    if (!headers_sent() && !ob_get_length()) {
        http_response_code(503);
        header_remove('Content-Disposition');
        header('Content-Type: text/plain; charset=utf-8');
        exit('Painel temporariamente indisponível.');
    }
}
