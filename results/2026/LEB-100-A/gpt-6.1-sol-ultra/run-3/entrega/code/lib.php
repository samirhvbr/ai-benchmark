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
    $res = painelConsultar($db, 'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?', 's', [$usuario]);
    try {
        $linha = $res->fetch_assoc();
    } finally {
        $res->free();
    }
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        painelAtualizarHash($db, (int) $linha['id'], $hash, $senha);
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Migra somente quando a coluna comporta o hash. Bancos CHAR(32) continuam
 * autenticando até receberem o ALTER TABLE descrito no relatório de implantação.
 */
function painelAtualizarHash(mysqli $db, int $id, string $anterior, string $senha): void
{
    $stmt = null;
    try {
        $res = painelConsultar($db,
            "SELECT CHARACTER_MAXIMUM_LENGTH AS capacidade FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
        try {
            $coluna = $res->fetch_assoc();
        } finally {
            $res->free();
        }
        // Bcrypt não representa bytes após o 72º nem aceita NUL. Não enfraquecer
        // uma senha legada ao truncá-la durante a migração oportunista.
        if (PASSWORD_DEFAULT === PASSWORD_BCRYPT && (strlen($senha) > 72 || strpos($senha, "\0") !== false)) {
            return;
        }
        $novo = password_hash($senha, PASSWORD_DEFAULT);
        if (!is_string($novo) || !$coluna || (int) $coluna['capacidade'] < strlen($novo)) {
            return;
        }
        // A comparação evita sobrescrever uma troca de senha concorrente.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt === false || !$stmt->bind_param('sis', $novo, $id, $anterior) || !$stmt->execute()) {
            throw new RuntimeException('Falha ao atualizar o hash.');
        }
    } catch (mysqli_sql_exception | RuntimeException $e) {
        // A migração é oportunista: indisponibilidade da escrita não bloqueia login válido.
        error_log('Painel: não foi possível migrar o hash de senha.');
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    }
}

/** Executa SELECT parametrizado e libera o statement, mantendo o resultado. */
function painelConsultar(mysqli $db, string $sql, string $tipos = '', array $parametros = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta.');
    }
    try {
        if ($tipos !== '' && !$stmt->bind_param($tipos, ...$parametros)) {
            throw new RuntimeException('Falha ao parametrizar consulta.');
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Falha ao executar consulta.');
        }
        $res = $stmt->get_result();
        if ($res === false) {
            throw new RuntimeException('Falha ao obter resultado.');
        }
        return $res;
    } finally {
        $stmt->close();
    }
}

/**
 * Escopo das consultas de chamados (alias c). Relatórios CLI sem sessão
 * conservam a visão global; no acesso web uma identidade válida é obrigatória.
 */
function painelEscopoChamados(): array
{
    if (PHP_SAPI === 'cli' && !isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return ['1 = 1', '', []];
    }
    $uid = $_SESSION['uid'] ?? null;
    $papel = $_SESSION['papel'] ?? null;
    if ((!is_int($uid) && !(is_string($uid) && ctype_digit($uid))) || (int) $uid <= 0) {
        return ['1 = 0', '', []];
    }
    if ($papel === 'tecnico') {
        return ['1 = 1', '', []];
    }
    if ($papel === 'cliente') {
        return ['c.usuario_id = ?', 'i', [(int) $uid]];
    }
    return ['1 = 0', '', []];
}

/** Preserva os valores numéricos textuais que query() retornava aos consumidores. */
function painelLinhaChamado(array $linha): array
{
    foreach ($linha as $chave => $valor) {
        if (is_int($valor)) {
            $linha[$chave] = (string) $valor;
        }
    }
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
    $res = painelConsultar($db, 'SELECT nome FROM usuarios WHERE id = ?', 'i', [$tecnicoId]);
    try {
        $t = $res->fetch_assoc();
        return $t ? $t['nome'] : '-';
    } finally {
        $res->free();
    }
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    [$escopo, $tipos, $parametros] = painelEscopoChamados();
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome FROM chamados c "
         . 'LEFT JOIN usuarios u ON u.id = c.tecnico_id WHERE ' . $escopo;
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = painelConsultar($db, $sql, $tipos, $parametros);

    $chamados = [];
    try {
        while ($c = $res->fetch_assoc()) {
            $chamados[] = painelLinhaChamado($c);
        }
    } finally {
        $res->free();
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$escopo, $tipos, $parametros] = painelEscopoChamados();
    $tipos .= 'i';
    $parametros[] = $id;
    $res = painelConsultar($db, 'SELECT c.* FROM chamados c WHERE ' . $escopo . ' AND c.id = ?', $tipos, $parametros);
    try {
        $linha = $res->fetch_assoc();
        return $linha ? painelLinhaChamado($linha) : null;
    } finally {
        $res->free();
    }
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$escopo, $tipos, $parametros] = painelEscopoChamados();
    // AVG(INT) retorna DECIMAL com escala limitada; 0e0 mantém a precisão float
    // da divisão que a implementação anterior fazia em PHP.
    $res = painelConsultar($db, 'SELECT AVG(c.minutos_resposta + 0e0) AS media FROM chamados c WHERE ' . $escopo, $tipos, $parametros);
    try {
        $row = $res->fetch_assoc();
        return (float) ($row['media'] ?? 0.0);
    } finally {
        $res->free();
    }
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $fp = fopen('php://temp', 'w+b');
    if ($fp === false) {
        throw new RuntimeException('Falha ao abrir o CSV.');
    }
    $res = null;
    try {
        [$escopo, $tipos, $parametros] = painelEscopoChamados();
        $res = painelConsultar($db,
            "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome FROM chamados c "
            . 'LEFT JOIN usuarios u ON u.id = c.tecnico_id WHERE ' . $escopo . ' ORDER BY c.id ASC',
            $tipos, $parametros);
        // fputcsv colocaria aspas em "Aberto em", violando o cabeçalho público.
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
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
            throw new RuntimeException('Falha ao finalizar o CSV.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        header('Cache-Control: private, no-store');
        if (fpassthru($fp) === false) {
            throw new RuntimeException('Falha ao enviar o CSV.');
        }
    } finally {
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        fclose($fp);
    }
}
