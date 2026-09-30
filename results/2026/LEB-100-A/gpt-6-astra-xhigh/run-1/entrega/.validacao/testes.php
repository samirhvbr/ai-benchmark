<?php
// Banco descartável exclusivo: nunca apontar este teste para produção.
require __DIR__ . '/../code/config.php';
require __DIR__ . '/../code/lib.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');
if ($db->query('SELECT DATABASE() AS nome')->fetch_assoc()['nome'] !== 'leb_validacao') {
    throw new RuntimeException('Execute somente no banco leb_validacao.');
}
$checks = 0;
function verificar(bool $condicao, string $mensagem): void
{
    global $checks;
    if (!$condicao) {
        throw new RuntimeException($mensagem);
    }
    $checks++;
}
function ids(array $chamados): array
{
    return array_map('intval', array_column($chamados, 'id'));
}
function csv(mysqli $db): array
{
    ob_start();
    exportarCsv($db);
    $texto = ob_get_clean();
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $texto);
    rewind($stream);
    $linhas = [];
    while (($linha = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $linhas[] = $linha;
    }
    fclose($stream);
    return [$texto, $linhas];
}
function consultas(mysqli $db): int
{
    return (int) $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_assoc()['Value'];
}
function pedido(string $rota, string &$cookie, ?array $post = null): array
{
    $headers = ['Connection: close'];
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $contexto = stream_context_create(['http' => [
        'method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $post === null ? '' : http_build_query($post),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    $body = file_get_contents('http://127.0.0.1:18765/index.php' . $rota, false, $contexto);
    // Disponível desde PHP 8.4, sem a variável depreciada no PHP 8.5.
    $resposta = http_get_last_response_headers();
    foreach ($resposta as $header) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i', $header, $m)) {
            $cookie = $m[1];
        }
    }
    preg_match('/^HTTP\/\S+ (\d+)/', $resposta[0], $m);
    return ['status' => (int) $m[1], 'body' => $body, 'headers' => implode("\n", $resposta)];
}

verificar(autenticar($db, 'ana', 'senha123') === ['id' => 1, 'nome' => 'Ana Souza', 'papel' => 'cliente'], 'Contrato de autenticar');
verificar(autenticar($db, 'bruno', 'senha123')['id'] === 2, 'Login Bruno');
verificar(autenticar($db, 'carla', 'tecmaster')['papel'] === 'tecnico', 'Login Carla');
verificar(autenticar($db, 'diego', 'tecmaster')['id'] === 4, 'Login Diego');
verificar(autenticar($db, 'ana', 'errada') === null, 'Rejeição de senha');
verificar(autenticar($db, "ana' OR 1=1 -- ", 'senha123') === null, 'Login parametrizado');
verificar([formatarStatus(1), formatarStatus(2), formatarStatus(3)] === ['Aberto', 'Em atendimento', 'Resolvido'], 'Rótulos de status');
verificar(formatarStatus(99) === 'Resolvido', 'Fallback legado de status');
foreach ([1, 2, 3, 4, 5] as $prioridade) {
    foreach ([null, 0, 30, 31] as $minutos) {
        $esperado = $minutos === null ? 'Aguardando 1a resposta' : ($prioridade < 3 ? 'Normal' : ($minutos <= 30 ? 'Alto - dentro do SLA' : ($prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado')));
        verificar(rotuloPrioridade($prioridade, $minutos) === $esperado, 'Rótulo de prioridade e limite SLA');
    }
}

unset($_SESSION);
$antes = consultas($db);
verificar(ids(listarChamados($db)) === [105, 104, 103, 102, 101], 'CLI sem sessão conserva listagem global');
verificar(consultas($db) - $antes === 1, 'Listagem tem uma consulta');
verificar(abs(mediaResposta($db) - 77 / 3) < 1e-12, 'Média global, precisão original e exclusão de NULL');
verificar(verChamado($db, 9999) === null, 'ID ausente retorna null');
verificar(ids(listarChamados($db, 'conexao')) === [101], 'Filtro por título');
verificar(count(listarChamados($db, '%')) === 5, 'Semântica LIKE preservada');
$injecao = "' OR 1=1 -- ";
verificar($db->query("SELECT * FROM chamados WHERE titulo LIKE '%" . $injecao . "%' ORDER BY criado_em DESC")->num_rows === 5, 'Reprodução da SQL injection original no banco descartável');
verificar(listarChamados($db, $injecao) === [], 'SQL injection neutralizada');
[$raw, $linhas] = csv($db);
verificar(str_starts_with($raw, "ID,Titulo,Status,Tecnico,Aberto em\n"), 'Cabeçalho CSV exato');
verificar(array_column(array_slice($linhas, 1), 0) === ['101', '102', '103', '104', '105'], 'CSV crescente e completo');
verificar($linhas[1][2] === 'Resolvido' && $linhas[2][2] === 'Em atendimento' && $linhas[3][2] === 'Aberto', 'Status no CSV');
verificar($linhas[4][3] === '-', 'Técnico nulo permanece no CSV');

foreach ([[1, [105, 102, 101], 103, 77 / 3], [2, [104, 103], 101, 0]] as [$uid, $esperados, $alheio, $media]) {
    $_SESSION = ['uid' => $uid, 'papel' => 'cliente'];
    verificar(ids(listarChamados($db)) === $esperados, 'Cliente lista somente seus chamados');
    verificar(verChamado($db, $alheio) === null, 'Cliente não acessa detalhe alheio');
    verificar(verChamado($db, $esperados[0]) !== null, 'Cliente mantém detalhe próprio');
    verificar(abs(mediaResposta($db) - $media) < 1e-12, 'Média restrita e sem respostas vale zero');
    [$raw, $linhas] = csv($db);
    $esperadosCsv = $esperados;
    sort($esperadosCsv);
    verificar(array_map('intval', array_column(array_slice($linhas, 1), 0)) === $esperadosCsv, 'CSV respeita dono');
    verificar(listarChamados($db, $injecao) === [], 'Busca maliciosa não amplia escopo');
}
foreach ([3, 4] as $uid) {
    $_SESSION = ['uid' => $uid, 'papel' => 'tecnico'];
    verificar(count(listarChamados($db)) === 5, 'Técnico vê todos, inclusive de outro técnico');
    verificar(verChamado($db, 104) !== null, 'Técnico vê chamado sem responsável');
    verificar(count(csv($db)[1]) === 6, 'Técnico exporta todos');
}
foreach ([['uid' => 1], ['papel' => 'tecnico'], ['uid' => 1, 'papel' => 'admin'], ['uid' => -1, 'papel' => 'cliente']] as $sessao) {
    $_SESSION = $sessao;
    verificar(listarChamados($db) === [] && verChamado($db, 101) === null && mediaResposta($db) === 0.0 && count(csv($db)[1]) === 1, 'Sessão inválida nega todas as leituras');
}
unset($_SESSION);
$db->begin_transaction();
try {
    $db->query('DELETE FROM chamados');
    verificar(mediaResposta($db) === 0.0, 'Banco sem chamados não divide por zero');
    verificar(listarChamados($db) === [] && count(csv($db)[1]) === 1, 'Listagem e CSV vazios');
} finally {
    $db->rollback();
}

$titulo = "=1+1,\"teste\"\nsegunda linha \\";
$stmt = $db->prepare("INSERT INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, status, prioridade, minutos_resposta, criado_em) VALUES (106, 1, 3, ?, '<script>descricao</script>', 1, 4, NULL, '2026-06-06 10:00:00')");
$stmt->bind_param('s', $titulo);
$stmt->execute();
$stmt->close();
try {
    [$raw, $linhas] = csv($db);
    verificar(count($linhas) === 7 && count($linhas[6]) === 5, 'CSV preserva aspas, vírgula, barra e quebra de linha');
    verificar($linhas[6][1] === "'" . $titulo, 'Fórmula no título neutralizada');
    foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tvalor", "\rvalor", "\nvalor", '  =1'] as $valor) {
        verificar(textoSeguroCsv($valor) === "'" . $valor, 'Prefixos de planilha neutralizados');
    }
    verificar(textoSeguroCsv('Título comum') === 'Título comum', 'Texto comum preservado');
    $db->query("UPDATE usuarios SET nome = '=1+1' WHERE id = 3");
    verificar(csv($db)[1][1][3] === "'=1+1", 'Fórmula no nome do técnico neutralizada');
    $db->query("UPDATE usuarios SET nome = 'Carla Tecnica' WHERE id = 3");

    $cookie = 'PHPSESSID=identificadorimpostoteste';
    $r = pedido('', $cookie);
    verificar($r['status'] === 200 && str_contains($r['body'], 'name="login"'), 'Login HTTP');
    verificar($cookie !== 'PHPSESSID=identificadorimpostoteste', 'Modo estrito rejeita ID não inicializado');
    verificar(str_contains($r['headers'], 'HttpOnly') && str_contains($r['headers'], 'SameSite=Lax'), 'Cookies protegidos');
    $antesLogin = $cookie;
    $r = pedido('', $cookie, ['login' => 'ana', 'senha' => 'senha123']);
    verificar($r['status'] === 302 && str_contains($r['headers'], 'Location: index.php'), 'Redirecionamento pós-login');
    verificar($cookie !== $antesLogin, 'ID de sessão regenerado');
    $r = pedido('', $cookie);
    verificar(str_contains($r['body'], 'id="tabela-chamados"'), 'ID da tabela');
    verificar(str_contains($r['body'], '<th>ID</th><th>Titulo</th><th>Status</th><th>Prioridade</th><th>Tecnico</th>'), 'Ordem das colunas HTML');
    verificar(str_contains($r['body'], 'index.php?ver=101') && !str_contains($r['body'], 'index.php?ver=103'), 'Links e visibilidade HTTP');
    $r = pedido('?ver=103', $cookie);
    verificar(str_contains($r['body'], 'Chamado nao encontrado.') && !str_contains($r['body'], 'Troca de plano'), 'IDOR bloqueado por HTTP');
    $r = pedido('?ver=106', $cookie);
    verificar(str_contains($r['body'], '&lt;script&gt;descricao&lt;/script&gt;') && !str_contains($r['body'], '<script>'), 'Descrição escapada');
    $payload = '\"><script>alert(1)</script><input autofocus onfocus="alert(2)';
    $r = pedido('?busca=' . rawurlencode($payload), $cookie);
    verificar($r['status'] === 200 && !str_contains($r['body'], '<script>') && !str_contains($r['body'], '<input autofocus'), 'XSS refletido neutralizado');
    verificar(substr_count($r['body'], htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) === 2, 'Escape no atributo e no texto');
    $r = pedido('?busca=' . rawurlencode($injecao), $cookie);
    verificar($r['status'] === 200 && !str_contains($r['body'], 'index.php?ver='), 'Busca SQL maliciosa por HTTP');
    verificar(pedido('?busca[]=a', $cookie)['status'] === 400, 'Busca em array');
    verificar(pedido('?ver[]=101', $cookie)['status'] === 400, 'ID em array');
    $r = pedido('?export=csv', $cookie);
    verificar($r['status'] === 200 && str_contains($r['headers'], 'text/csv; charset=utf-8') && str_contains($r['headers'], 'filename="chamados.csv"'), 'Headers do download');
    verificar(!str_contains($r['body'], 'Troca de plano') && str_contains($r['body'], 'Sem conexao'), 'CSV HTTP restrito');
    $antigo = $antesLogin;
    verificar(str_contains(pedido('', $antigo)['body'], 'name="login"'), 'Sessão anterior ao login não autentica');

    $bruno = '';
    pedido('', $bruno, ['login' => 'bruno', 'senha' => 'senha123']);
    $r = pedido('', $bruno);
    verificar(str_contains($r['body'], '0 min') && str_contains($r['body'], 'index.php?ver=103') && !str_contains($r['body'], 'index.php?ver=101'), 'Bruno vê próprios chamados e média zero');
    $tecnico = '';
    pedido('', $tecnico, ['login' => 'carla', 'senha' => 'tecmaster']);
    $r = pedido('', $tecnico);
    verificar(substr_count($r['body'], '<td><a href="index.php?ver=') === 6, 'Técnico mantém todos os chamados por HTTP');
    verificar(str_contains(pedido('?ver=104', $tecnico)['body'], 'Fatura em duplicidade'), 'Detalhe sem responsável para técnico');
    $anonimo = '';
    verificar(str_contains(pedido('?export=csv', $anonimo)['body'], 'name="login"'), 'Export anônimo exige login');
    $anonimo = '';
    verificar(pedido('', $anonimo, ['login' => ['ana'], 'senha' => 'senha123'])['status'] === 200, 'Login em array não causa TypeError');
    $anonimo = '';
    verificar(pedido('', $anonimo, ['login' => 'ana', 'senha' => ['senha123']])['status'] === 200, 'Senha em array não causa TypeError');

    // Cada processo exporta com uma conexão e uma identidade próprias.
    $processos = [];
    foreach (range(0, 7) as $i) {
        $uid = $i % 2 + 1;
        $programa = 'require ' . var_export(__DIR__ . '/../code/lib.php', true) . '; '
            . '$db = new mysqli("localhost", "root", "", "leb_validacao"); '
            . '$_SESSION = ["uid" => ' . $uid . ', "papel" => "cliente"]; exportarCsv($db);';
        $pipes = [];
        $processo = proc_open([PHP_BINARY, '-d', 'mysqli.default_socket=' . ini_get('mysqli.default_socket'), '-r', $programa], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        $processos[] = [$processo, $pipes, $uid];
    }
    foreach ($processos as [$processo, $pipes, $uid]) {
        $saida = stream_get_contents($pipes[1]);
        $erro = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($processo);
        verificar($status === 0 && $erro === '', 'Exportação concorrente sem erros');
        verificar($uid === 1
            ? str_contains($saida, 'Sem conexao') && !str_contains($saida, 'Troca de plano')
            : str_contains($saida, 'Troca de plano') && !str_contains($saida, 'Sem conexao'), 'Exportações concorrentes isolam os clientes');
    }

    $db->query('RENAME TABLE chamados TO chamados_backup_validacao');
    try {
        $r = pedido('', $cookie);
        verificar($r['status'] === 500 && $r['body'] === 'Falha ao processar a solicitacao.', 'Erro SQL recebe resposta genérica, sem SQL nem stack trace');
    } finally {
        $db->query('RENAME TABLE chamados_backup_validacao TO chamados');
    }

    // Consultas permanecem constantes mesmo com mais chamados.
    $db->begin_transaction();
    try {
        for ($id = 200; $id < 300; $id++) {
            $db->query("INSERT INTO chamados (id, usuario_id, tecnico_id, titulo, descricao, criado_em) VALUES ($id, 1, 3, 'Carga', '', '2026-06-01 00:00:00')");
        }
        $antes = consultas($db);
        verificar(count(listarChamados($db)) === 106 && consultas($db) - $antes === 1, 'Uma consulta de listagem com 106 chamados');
        $antes = consultas($db);
        verificar(count(csv($db)[1]) === 107 && consultas($db) - $antes === 1, 'Uma consulta de exportação com 106 chamados');
    } finally {
        $db->rollback();
    }
} finally {
    $db->query('DELETE FROM chamados WHERE id = 106');
    $db->query("UPDATE usuarios SET nome = 'Carla Tecnica' WHERE id = 3");
}
echo "OK: $checks verificações passaram.\n";
