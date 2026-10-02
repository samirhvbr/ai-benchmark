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
    $hash = md5($senha);
    $stmt = $db->prepare('SELECT id, nome, papel FROM usuarios WHERE login = ? AND senha = ?');
    try {
        $stmt->bind_param('ss', $usuario, $hash);
        $stmt->execute();
        $linha = $stmt->get_result()->fetch_assoc();
        return $linha ?: null;
    } finally {
        $stmt->close();
    }
}

/**
 * Mantém o contexto web sem alterar as assinaturas públicas. Scripts internos
 * sem usuário em sessão continuam consultando o conjunto completo.
 * Todos os SELECTs de chamados usam o alias c.
 */
function escopoChamados(): array
{
    if (!isset($_SESSION['uid'])) {
        return ['1 = 1', []];
    }
    if (($_SESSION['papel'] ?? '') === 'tecnico') {
        return ['1 = 1', []];
    }
    if (($_SESSION['papel'] ?? '') === 'cliente' && (int) $_SESSION['uid'] > 0) {
        return ['c.usuario_id = ?', [(int) $_SESSION['uid']]];
    }
    return ['1 = 0', []];
}

/** Executa SELECT parametrizado e libera o statement após obter o resultado. */
function consultarChamados(mysqli $db, string $sql, string $tipos, array $parametros): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta de chamados.');
    }
    try {
        if ($tipos !== '') {
            $stmt->bind_param($tipos, ...$parametros);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Falha ao consultar chamados.');
        }
        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new RuntimeException('Falha ao obter chamados.');
        }
        return $resultado;
    } finally {
        $stmt->close();
    }
}

/** Preserva os campos numéricos como strings do SELECT textual legado. */
function normalizarLinhaChamado(array $linha): array
{
    foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
        if ($linha[$campo] !== null) {
            $linha[$campo] = (string) $linha[$campo];
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
 * Busca o nome de um técnico pelo id; mantida para consumidores existentes.
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
    [$condicao, $parametros] = escopoChamados();
    $tipos = str_repeat('i', count($parametros));
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . $condicao;
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $parametros[] = '%' . $busca . '%';
        $tipos .= 's';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = consultarChamados($db, $sql, $tipos, $parametros);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarLinhaChamado($c);
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$condicao, $parametros] = escopoChamados();
    array_unshift($parametros, $id);
    $res = consultarChamados($db, 'SELECT c.* FROM chamados c WHERE c.id = ? AND ' . $condicao,
        str_repeat('i', count($parametros)), $parametros);
    $linha = $res->fetch_assoc();
    return $linha ? normalizarLinhaChamado($linha) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$condicao, $parametros] = escopoChamados();
    $res = consultarChamados($db,
        'SELECT COALESCE(SUM(c.minutos_resposta), 0) AS soma, COUNT(c.minutos_resposta) AS qtd
         FROM chamados c WHERE ' . $condicao,
        str_repeat('i', count($parametros)), $parametros);
    $linha = $res->fetch_assoc();
    $qtd = (int) $linha['qtd'];
    return $qtd > 0 ? (float) $linha['soma'] / $qtd : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    [$condicao, $parametros] = escopoChamados();
    $res = consultarChamados($db,
        "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
         FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
         WHERE " . $condicao . ' ORDER BY c.id',
        str_repeat('i', count($parametros)), $parametros);
    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        throw new RuntimeException('Falha ao gerar CSV.');
    }
    try {
        // Cabeçalho literal: o manifesto exige estes bytes, inclusive o espaço.
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao gerar CSV.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao gerar CSV.');
            }
        }
        rewind($fp);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        if (fpassthru($fp) === false) {
            throw new RuntimeException('Falha ao enviar CSV.');
        }
    } finally {
        fclose($fp);
    }
}
