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
            $_SESSION['uid'] = (int) $u['id'];
            $_SESSION['papel'] = $u['papel'];
            // LEB: FINDING F8 severity=media confidence=90 | O login mantém o identificador de sessão anterior à autenticação; se um atacante fixar esse id na vítima, passa a usar a sessão autenticada | Impacto: sequestro da sessão quando o ambiente aceitar o id escolhido pelo atacante | Correção proposta: session_regenerate_id(true) após autenticar e antes de redirecionar.
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
    exportarCsv($db);
    // LEB: FINDING F4 severity=alta confidence=100 | A rota autenticada chama uma exportação de todos os chamados sem verificar papel ou dono | Impacto: qualquer cliente exporta dados de chamados de terceiros, violando a visibilidade do manifesto | Correção proposta: aplicar no fluxo web o mesmo recorte por usuário/papel antes de gerar o CSV, preservando a função pública para consumidores internos.
    exit;
}

// ---------------------------------------------------------------------------
// Detalhe de um chamado
// ---------------------------------------------------------------------------
if (isset($_GET['ver'])) {
    $c = verChamado($db, (int) $_GET['ver']);
    // LEB: FINDING F3 severity=alta confidence=100 | O id vem de GET e o resultado é exibido sem comparar usuario_id com uid quando papel é cliente | Impacto: um cliente altera ver=<id> e lê título, descrição e estado de chamados alheios | Correção proposta: negar o detalhe quando o chamado não pertencer ao cliente, mantendo acesso irrestrito ao técnico.
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
$busca = $_GET['busca'] ?? '';
$chamados = listarChamados($db, $busca);
// LEB: FINDING F2 severity=alta confidence=100 | A listagem recebe apenas busca e nunca aplica uid/papel; assim clientes recebem linhas cujo usuario_id é de outra pessoa | Impacto: exposição de metadados de todos os chamados e links para os detalhes | Correção proposta: filtrar por usuario_id para clientes no fluxo web, sem restringir técnicos nem mudar a assinatura pública.
$media = mediaResposta($db);

echo '<!doctype html><meta charset="utf-8"><title>Chamados</title>';
echo '<h1>Chamados</h1>';
echo '<p>Tempo medio de 1a resposta: ' . round($media) . ' min</p>';
echo '<form method="get"><input name="busca" value="' . $busca . '" placeholder="buscar titulo">'
   . '<button>Buscar</button></form>';
// LEB: FINDING F5 severity=alta confidence=100 | busca é concatenada em atributo sem escape; aspas podem encerrar value e criar atributos/elementos no HTML | Impacto: JavaScript refletido no contexto de outro usuário autenticado que abrir a URL | Correção proposta: usar htmlspecialchars com ENT_QUOTES e UTF-8 neste atributo.
if ($busca !== '') {
    echo '<p>Resultados para: ' . $busca . '</p>';
    // LEB: FINDING F5 severity=alta confidence=100 | O mesmo parâmetro busca é emitido como conteúdo HTML bruto, permitindo injetar marcação e script | Impacto: execução de ações/JS no navegador da vítima autenticada | Correção proposta: escapar busca na saída textual com htmlspecialchars e UTF-8.
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
