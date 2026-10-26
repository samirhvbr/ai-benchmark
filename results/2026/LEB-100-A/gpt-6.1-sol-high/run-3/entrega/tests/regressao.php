<?php
// Executar exclusivamente contra um banco descartável com schema.sql + seed.sql.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../code/config.php';
require __DIR__ . '/../code/lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_error_handler(function ($nivel, $mensagem) { throw new RuntimeException($mensagem); });
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');
$checks = 0;
function verificar($ok, string $mensagem): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($mensagem); }
    $checks++;
}
function ids(array $rows): array { return array_map('intval', array_column($rows, 'id')); }
function csv(mysqli $db): array {
    ob_start();
    exportarCsv($db);
    $texto = ob_get_clean();
    $fp = fopen('php://temp', 'w+');
    fwrite($fp, $texto);
    rewind($fp);
    $linhas = [];
    while (($linha = fgetcsv($fp, null, ',', '"', '\\')) !== false) { $linhas[] = $linha; }
    fclose($fp);
    return $linhas;
}
function selects(mysqli $db): int {
    return (int) $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
}
$_SESSION = [];
foreach (['ana' => [1, 'cliente', 'senha123'], 'bruno' => [2, 'cliente', 'senha123'],
          'carla' => [3, 'tecnico', 'tecmaster'], 'diego' => [4, 'tecnico', 'tecmaster']] as $login => $d) {
    $u = autenticar($db, $login, $d[2]);
    verificar(array_keys($u) === ['id', 'nome', 'papel'] && (int) $u['id'] === $d[0] && $u['papel'] === $d[1], 'Login ' . $login);
}
verificar(autenticar($db, 'ana', 'errada') === null, 'Senha inválida');
verificar(autenticar($db, "ana' OR 1=1 -- ", 'senha123') === null, 'Login parametrizado');
foreach ([1 => 'Aberto', 2 => 'Em atendimento', 3 => 'Resolvido'] as $n => $r) {
    verificar(formatarStatus($n) === $r, 'Status contratual');
}
// Casos explícitos de todos os ramos e limites do SLA.
$normal = ['Aguardando 1a resposta', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal'];
$alto = ['Aguardando 1a resposta', 'Alto - dentro do SLA', 'Alto - dentro do SLA',
         'Alto - dentro do SLA', 'Alto - dentro do SLA', 'Alto - atrasado', 'Alto - atrasado'];
$critico = ['Aguardando 1a resposta', 'Alto - dentro do SLA', 'Alto - dentro do SLA',
            'Alto - dentro do SLA', 'Alto - dentro do SLA', 'CRITICO - SLA estourado', 'CRITICO - SLA estourado'];
foreach ([0 => $normal, 1 => $normal, 2 => $normal, 3 => $alto, 4 => $critico, 5 => $alto] as $prioridade => $esperados) {
    foreach ([null, -1, 0, 29, 30, 31, 90] as $i => $minutos) {
        verificar(rotuloPrioridade($prioridade, $minutos) === $esperados[$i], 'Rótulo de prioridade');
    }
}
$global = [105, 104, 103, 102, 101];
verificar(ids(listarChamados($db)) === $global, 'Script interno sem sessão');
verificar(abs(mediaResposta($db) - 77 / 3) < 0.0001, 'Média global');
verificar(tecnicoNome($db, null) === '-' && tecnicoNome($db, 3) === 'Carla Tecnica', 'Helper legado');
foreach ([[1, 'cliente', [105,102,101], [101,102,105]], [2, 'cliente', [104,103], [103,104]],
          [3, 'tecnico', $global, [101,102,103,104,105]], [4, 'tecnico', $global, [101,102,103,104,105]]] as $caso) {
    $_SESSION = ['uid' => $caso[0], 'papel' => $caso[1]];
    $antes = selects($db);
    $rows = listarChamados($db);
    verificar(selects($db) - $antes === 1, 'Uma consulta na listagem');
    verificar(ids($rows) === $caso[2], 'Visibilidade e ordem da listagem');
    verificar(array_key_exists('tecnico_nome', $rows[0]), 'Chave tecnico_nome');
    foreach (range(101,105) as $id) {
        verificar((verChamado($db, $id) !== null) === in_array($id, $caso[3], true), 'Visibilidade do detalhe');
    }
    verificar(verChamado($db, 999) === null, 'Detalhe inexistente');
    $antes = selects($db);
    $linhas = csv($db);
    verificar(selects($db) - $antes === 1, 'Uma consulta no CSV');
    verificar($linhas[0] === ['ID','Titulo','Status','Tecnico','Aberto em'], 'Cabeçalho exato');
    verificar(array_map('intval', array_column(array_slice($linhas,1),0)) === $caso[3], 'Visibilidade e ordem do CSV');
    verificar(ids(listarChamados($db, "%' OR 1=1 -- ")) === [], 'SQL injection tratada como dado');
}
$_SESSION = ['uid' => 1, 'papel' => 'cliente'];
verificar(ids(listarChamados($db, 'Roteador')) === [105], 'Busca de título');
verificar(ids(listarChamados($db, 'Fatura')) === [], 'Busca não vaza outro dono');
verificar(ids(listarChamados($db, '%')) === [105,102,101], 'Preserva curingas LIKE');
$_SESSION = ['uid' => 2, 'papel' => 'cliente'];
verificar(mediaResposta($db) === 0.0, 'Cliente só com respostas nulas');
$_SESSION = ['uid' => 3, 'papel' => 'invalido'];
verificar(listarChamados($db) === [] && verChamado($db,101) === null && mediaResposta($db) === 0.0 && count(csv($db)) === 1, 'Papel inválido sem acesso');
$_SESSION = [];
$db->begin_transaction();
try {
    $titulo = '=1+1,"teste"' . "\nsegunda linha";
    $stmt = $db->prepare("INSERT INTO chamados (id,usuario_id,titulo,descricao,criado_em) VALUES (106,1,?,'Teste','2026-07-01')");
    $stmt->bind_param('s', $titulo);
    $stmt->execute();
    $linhas = csv($db);
    verificar($linhas[6][1] === "'" . $titulo && count($linhas[6]) === 5, 'Neutralização e round-trip CSV');
    verificar($linhas[4][3] === '-', 'Marcador de técnico ausente preservado');
    foreach (['=1+1','+SUM(1,2)','-1+1','@SUM(1,2)',"\t=1+1","\r\n=1+1"] as $texto) {
        verificar(textoCsvSeguro($texto) === "'" . $texto, 'Fórmula como texto');
    }
    $db->query('UPDATE chamados SET minutos_resposta = NULL');
    verificar(mediaResposta($db) === 0.0, 'Todos os tempos nulos');
    $db->query('DELETE FROM chamados');
    verificar(mediaResposta($db) === 0.0 && listarChamados($db) === [] && count(csv($db)) === 1, 'Base vazia');
} finally {
    $db->rollback();
}
echo "OK: $checks verificações PHP/mysqli.\n";
