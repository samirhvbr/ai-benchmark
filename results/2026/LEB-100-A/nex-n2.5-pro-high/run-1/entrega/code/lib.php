<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

function executarPreparada(mysqli $db, string $sql, string $tipos, array $parametros): ?mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return null;
    }

    if ($parametros !== []) {
        $argumentos = [$tipos];
        foreach ($parametros as $i => $valor) {
            $argumentos[] = &$parametros[$i];
        }
        if (!call_user_func_array([$stmt, 'bind_param'], $argumentos)) {
            $stmt->close();
            return null;
        }
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $resultado = $stmt->get_result();
    $stmt->close();
    return $resultado ?: null;
}

function usuarioAtual(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['uid'])) {
        return null;
    }

    $uid = $_SESSION['uid'] ?? null;
    $papel = $_SESSION['papel'] ?? null;
    $uidInt = is_int($uid) ? $uid : (is_string($uid) && ctype_digit($uid) ? (int) $uid : null);
    if ($uidInt === null || $uidInt < 1 || !in_array($papel, ['tecnico', 'cliente'], true)) {
        return null;
    }

    return [
        'id' => $uidInt,
        'papel' => $papel,
    ];
}

function usuarioSessaoValido(mysqli $db): ?array
{
    $usuario = usuarioAtual();
    if ($usuario === null) {
        return null;
    }

    $resultado = executarPreparada(
        $db,
        'SELECT id, papel FROM usuarios WHERE id = ?',
        'i',
        [$usuario['id']]
    );
    if ($resultado === null) {
        return [];
    }

    $linha = $resultado->fetch_assoc();
    $resultado->free();
    if ($linha === false || $linha === null
        || (int) $linha['id'] !== $usuario['id']
        || ($linha['papel'] ?? null) !== $usuario['papel']
    ) {
        return [];
    }

    return $usuario;
}

function valorCsvSeguro($valor): string
{
    $texto = (string) $valor;
    $trecho = $texto;

    while (true) {
        if (substr($trecho, 0, 3) === "\xEF\xBB\xBF") {
            $trecho = substr($trecho, 3);
            continue;
        }
        if (preg_match('/^[\p{Z}\p{Cf}\x00-\x1f\x7f]+/u', $trecho, $correspondencias) === 1) {
            $trecho = substr($trecho, strlen($correspondencias[0]));
            continue;
        }
        break;
    }

    if (strlen($trecho) > 1 && (
        preg_match('/^(?:=|\+|-|@)/', $trecho) === 1
        || preg_match('/^(?:＝|＋|－|＠)/u', $trecho) === 1
    )) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    if ($stmt === false) {
        return null;
    }
    if (!$stmt->bind_param('s', $usuario)) {
        $stmt->close();
        return null;
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $resultado = $stmt->get_result();
    $linha = $resultado ? $resultado->fetch_assoc() : false;
    if ($resultado) {
        $resultado->free();
    }
    $stmt->close();

    if (!$linha) {
        return null;
    }

    $senhaArmazenada = (string) ($linha['senha'] ?? '');
    $senhaValida = password_verify($senha, $senhaArmazenada);
    if (!$senhaValida) {
        $senhaValida = hash_equals(md5($senha), $senhaArmazenada);
    }

    if (!$senhaValida) {
        return null;
    }

    if (password_needs_rehash($senhaArmazenada, PASSWORD_DEFAULT)) {
        $coluna = $db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho "
            . "FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $metadado = $coluna ? $coluna->fetch_assoc() : false;
        if ($coluna) {
            $coluna->free();
        }

        if ($metadado !== false && (int) ($metadado['tamanho'] ?? 0) >= 60) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            $atualizacao = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($atualizacao !== false) {
                if ($atualizacao->bind_param('si', $novoHash, $linha['id'])) {
                    $atualizacao->execute();
                }
                $atualizacao->close();
            }
        }
    }

    return [
        'id' => (int) $linha['id'],
        'nome' => (string) $linha['nome'],
        'papel' => (string) $linha['papel'],
    ];
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    }
    if ($status === 2) {
        return 'Em atendimento';
    }
    return 'Resolvido';
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos !== null) {
        if ($prioridade >= 3) {
            if ($minutos > 30) {
                if ($prioridade === 4) {
                    return 'CRITICO - SLA estourado';
                }
                return 'Alto - atrasado';
            }
            return 'Alto - dentro do SLA';
        }
        return 'Normal';
    }
    return 'Aguardando 1a resposta';
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $resultado = executarPreparada(
        $db,
        'SELECT nome FROM usuarios WHERE id = ?',
        'i',
        [$tecnicoId]
    );
    if ($resultado === null) {
        return '-';
    }
    $t = $resultado->fetch_assoc();
    $resultado->free();
    return $t ? (string) $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $usuario = usuarioSessaoValido($db);
    if ($usuario === null || $usuario === []) {
        return [];
    }
    $cliente = $usuario !== null && $usuario['papel'] === 'cliente';
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];

    if ($cliente) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuario['id'];
    }
    if ($busca !== '') {
        $sql .= $cliente ? ' AND c.titulo LIKE ?' : ' WHERE c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $resultado = executarPreparada($db, $sql, $tipos, $parametros);
    if ($resultado === null) {
        return [];
    }

    $chamados = [];
    while ($c = $resultado->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] !== null ? (string) $c['tecnico_nome'] : '-';
        $chamados[] = $c;
    }
    $resultado->free();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $usuario = usuarioSessaoValido($db);
    if ($usuario === null || $usuario === []) {
        return null;
    }
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $tipos = 'i';
    $parametros = [$id];

    if ($usuario !== null && $usuario['papel'] === 'cliente') {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuario['id'];
    }

    $resultado = executarPreparada($db, $sql, $tipos, $parametros);
    if ($resultado === null) {
        return null;
    }
    $chamado = $resultado->fetch_assoc();
    $resultado->free();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $usuario = usuarioSessaoValido($db);
    if ($usuario === null || $usuario === []) {
        return 0.0;
    }
    $sql = 'SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL';
    $tipos = '';
    $parametros = [];

    if ($usuario !== null && $usuario['papel'] === 'cliente') {
        $sql .= ' AND usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuario['id'];
    }

    $resultado = executarPreparada($db, $sql, $tipos, $parametros);
    if ($resultado === null) {
        return 0.0;
    }

    $linha = $resultado->fetch_assoc();
    $resultado->free();
    return $linha !== false && $linha['media'] !== null ? (float) $linha['media'] : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $usuario = usuarioSessaoValido($db);
    if ($usuario === null || $usuario === []) {
        return;
    }
    $sql = 'SELECT c.id, c.titulo, c.status, COALESCE(u.nome, \'-\') AS tecnico_nome,'
        . ' c.criado_em FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];

    if ($usuario !== null && $usuario['papel'] === 'cliente') {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuario['id'];
    }
    $sql .= ' ORDER BY c.id';

    $resultado = executarPreparada($db, $sql, $tipos, $parametros);
    if ($resultado === null) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $saida = fopen('php://output', 'w');
    if ($saida === false) {
        $resultado->free();
        return;
    }

    fwrite($saida, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $resultado->fetch_assoc()) {
        fputcsv($saida, [
            $c['id'],
            valorCsvSeguro($c['titulo']),
            formatarStatus((int) $c['status']),
            valorCsvSeguro($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    $resultado->free();
    fclose($saida);
}
