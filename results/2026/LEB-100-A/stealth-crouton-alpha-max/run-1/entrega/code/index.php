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

// F13: previne fixação de sessão.
if (empty($_SESSION['uid'])) {
    session_regenerate_id(true);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------
if (!isset($_SESSION['uid'])) {
    if (isset($_POST['login'])) {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            exit('Forbidden');
        }
        $u = autenticar($db, $_POST['login'], $_POST['senha'] ?? '');
        if ($u) {
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            session_regenerate_id(true);
            header('Location: index.php');
            exit;
        }
        // F10: mensagem genérica (sem revelar se usuário existe)
        echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
        echo '<h1>Painel de Chamados</h1>';
        echo '<p>Credenciais inválidas.</p>';
        echo '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
           . '<input name="login" placeholder="usuario">'
           . '<input type="password" name="senha" placeholder="senha">'
           . '<button>Entrar</button></form>';
        exit;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Entrar</title>';
    echo '<h1>Painel de Chamados</h1>';
    echo '<form method="post"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">'
       . '<input name="login" placeholder="usuario">'
       . '<input type="password" name="senha" placeholder="senha">'
       . '<button>Entrar</button></form>';
    exit;
}

$uid = (int) $_SESSION['uid'];
$papel = $_SESSION['papel'];
$isTecnico = ($papel === 'tecnico');

// ---------------------------------------------------------------------------
// Exportação CSV (tratada antes de qualquer saída HTML)
// Corrigido (F11): autorização por papel. Cliente exportaria tudo antes.
// Mantém o formato do manifesto: cabeçalho ID,Titulo,Status,Tecnico,Aberto em
// e ordem por id. Por padrão, técnico exporta tudo; cliente exporta só seus.
// HACK: exportarCsv() não tem parâmetro de filtro, então o controle é feito
// aqui filtrando em tempo de escrita? Não — mantemos o contrato chamando
// exportarCsv() para técnico e para cliente repetimos a mesma chamada com
// filtro em memória é impossível sem quebrar a assinatura. Simplificação:
// por compatibilidade, bloqueamos cliente de baixar o CSV master e orientamos
// a usar a listagem. ver Decisão D2 em RELATORIO.md.
// Para não quebrar consumidores que chamam exportarCsv() direto na lib,
// adotamos: técnico → exportarCsv() completo; cliente → 403.
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    if (!$isTecnico) {
        // F11/F14: IDOR no export. Cliente não pode exportar base inteira.
        // Isso preserva a regra de visibilidade do manifesto.
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Acesso negado.');
    }
    exportarCsv($db);
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// Corrigido (F11): checagem de dono/tecnico. Sem isso, cliente enumerava
// qualquer id.
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $c = verChamado($db, (int) $_GET['ver']);
    echo '<!doctype html><meta charset="utf-8"><title>Chamado</title>';
    if (!$c) {
        echo '<p>Chamado nao encontrado.</p>';
        exit;
    }
    if (!$isTecnico && (int) $c['usuario_id'] !== $uid) {
        http_response_code(403);
        echo '<p>Acesso negado.</p>';
        exit;
    }
    echo '<h1>Chamado #' . $c['id'] . '</h1>';
    echo '<p><b>Titulo:</b> ' . htmlspecialchars($c['titulo'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><b>Status:</b> ' . formatarStatus((int) $c['status']) . '</p>';
    echo '<p><b>Prioridade:</b> '
       . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</p>';
    echo '<p><b>Descricao:</b> ' . htmlspecialchars($c['descricao'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">Voltar</a></p>';
    exit;
}

// ---------------------------------------------------------------------------
// Listagem + busca
// Corrigido (F11): filtro por dono quando cliente. Para técnico, lista tudo.
// Implementado via pós-filtro? Não — filtramos em PHP sobre o resultado de
// listarChamados() para não inventar parâmetro novo (quebraria o manifesto).
// Uid é int e tecnico é bool, então o pós-filtro é O(n) e n é a listagem já
// carregada. Para volume alto o ideal seria WHERE usuario_id=? no SQL, mas
// a assinatura pública não permite filtro — mantemos e documentamos.
// ---------------------------------------------------------------------------
$busca = $_GET['busca'] ?? '';
$chamados = listarChamados($db, $busca);
if (!$isTecnico) {
    $chamados = array_values(array_filter($chamados, fn($c) => (int) $c['usuario_id'] === $uid));
}
$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
// F3/FXSS: escape do atributo + htmlspecialchars completo (F2 fix)
echo '<form method="get"><input name="busca" value="' . htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
if ($busca !== '') {
    // F2: $busca era ecoado sem escape → XSS refletido.
    echo '<p>Resultados para: ' . htmlspecialchars($busca, ENT_QUOTES, 'UTF-8') . '</p>';
}
if ($isTecnico) {
    echo '<p><a href="index.php?export=csv">Exportar CSV</a></p>';
}

echo '<table id="tabela-chamados" border="1">';
echo '<tr><th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th></tr>';
foreach ($chamados as $c) {
    echo '<tr>';
    echo '<td><a href="index.php?ver=' . $c['id'] . '">' . $c['id'] . '</a></td>';
    echo '<td>' . htmlspecialchars($c['titulo'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . formatarStatus((int) $c['status']) . '</td>';
    echo '<td>' . rotuloPrioridade((int) $c['prioridade'], $c['minutos_resposta'] !== null ? (int) $c['minutos_resposta'] : null) . '</td>';
    echo '<td>' . htmlspecialchars($c['tecnico_nome'], ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}
echo '</table>';
