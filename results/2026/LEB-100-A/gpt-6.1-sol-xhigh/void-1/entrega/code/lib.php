<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $valida = $legado
        ? hash_equals(strtolower($hash), md5($senha))
        : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    // Bcrypt limita a entrada a 72 bytes; não truncar senhas legadas maiores.
    if (($algoritmo !== PASSWORD_DEFAULT || strlen($senha) <= 72)
        && ($legado || password_needs_rehash($hash, $algoritmo))) {
        $novoHash = password_hash($senha, $algoritmo);
        if ($novoHash === false) {
            throw new RuntimeException('Falha ao gerar hash de senha.');
        }
        // A comparação evita sobrescrever uma troca de senha concorrente.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novoHash, $linha['id'], $hash);
        $stmt->execute();
        $stmt->close();
    }
    unset($linha['senha']);
    return $linha;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status == 1) {
        return 'Aberto';
    } else if ($status == 2) {
        return 'Em atendimento';
    } else {
        return 'Resolvido';
    }
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos !== null) {
        if ($prioridade >= 3) {
            if ($minutos > 30) {
                if ($prioridade == 4) {
                    return 'CRITICO - SLA estourado';
                } else {
                    return 'Alto - atrasado';
                }
            } else {
                return 'Alto - dentro do SLA';
            }
        } else {
            return 'Normal';
        }
    } else {
        return 'Aguardando 1a resposta';
    }
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $res = consultarListaChamados($db, $busca);
    try {
        return array_map('normalizarChamado', $res->fetch_all(MYSQLI_ASSOC));
    } finally {
        $res->free();
    }
}

/** Preserva os tipos retornados pelas antigas consultas textuais mysqli. */
function normalizarChamado(array $chamado): array
{
    foreach ($chamado as $campo => $valor) {
        if (is_int($valor) || is_float($valor)) {
            $chamado[$campo] = (string) $valor;
        }
    }
    return $chamado;
}

/**
 * Escopo da sessão web. Sem sessão, mantém as consultas globais dos scripts
 * internos que já chamavam esta biblioteca; o index.php exige autenticação.
 */
function escopoChamados(): array
{
    if (!isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return [[], '', []];
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid !== false && $papel === 'tecnico') {
        return [[], '', []];
    }
    if ($uid !== false && $papel === 'cliente') {
        return [['c.usuario_id = ?'], 'i', [$uid]];
    }
    return [['1 = 0'], '', []];
}

/** Consulta compartilhada pela listagem e pelo CSV, sem consultas por linha. */
function consultarListaChamados(mysqli $db, string $busca = '', bool $porId = false): mysqli_result
{
    [$filtros, $tipos, $parametros] = escopoChamados();
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome FROM chamados c"
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= $porId ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    try {
        if ($parametros) {
            $stmt->bind_param($tipos, ...$parametros);
        }
        $stmt->execute();
        return $stmt->get_result();
    } finally {
        $stmt->close();
    }
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$filtros, $tipos, $parametros] = escopoChamados();
    $filtros[] = 'c.id = ?';
    $tipos .= 'i';
    $parametros[] = $id;
    $stmt = $db->prepare('SELECT c.* FROM chamados c WHERE ' . implode(' AND ', $filtros));
    try {
        $stmt->bind_param($tipos, ...$parametros);
        $stmt->execute();
        $chamado = $stmt->get_result()->fetch_assoc();
        return $chamado ? normalizarChamado($chamado) : null;
    } finally {
        $stmt->close();
    }
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$filtros, $tipos, $parametros] = escopoChamados();
    // A coerção para double evita o arredondamento decimal de AVG(INT).
    $sql = 'SELECT COALESCE(AVG(c.minutos_resposta + 0e0), 0) AS media FROM chamados c';
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $stmt = $db->prepare($sql);
    try {
        if ($parametros) {
            $stmt->bind_param($tipos, ...$parametros);
        }
        $stmt->execute();
        return (float) $stmt->get_result()->fetch_assoc()['media'];
    } finally {
        $stmt->close();
    }
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = consultarListaChamados($db, '', true);
    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        $res->free();
        throw new RuntimeException('Falha ao abrir a exportação.');
    }
    try {
        if (fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '') === false) {
            throw new RuntimeException('Falha ao escrever o cabeçalho CSV.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever o CSV.');
            }
        }
        if (!rewind($fp)) {
            throw new RuntimeException('Falha ao ler a exportação.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        if (fpassthru($fp) === false) {
            throw new RuntimeException('Falha ao enviar a exportação.');
        }
    } finally {
        $res->free();
        fclose($fp);
    }
}
