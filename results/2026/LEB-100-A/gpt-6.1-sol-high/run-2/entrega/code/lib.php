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
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ?: null;
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
 * Busca o nome de um técnico pelo id; mantida para consumidores legados.
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
    [$filtro, $tipos, $parametros] = filtroVisibilidadeChamados();
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE ' . $filtro;
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarLinhaChamado($c);
    }
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$filtro, $tipos, $parametros] = filtroVisibilidadeChamados();
    $stmt = $db->prepare('SELECT c.* FROM chamados c WHERE c.id = ? AND ' . $filtro);
    array_unshift($parametros, $id);
    $stmt->bind_param('i' . $tipos, ...$parametros);
    $stmt->execute();
    $chamado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $chamado ? normalizarLinhaChamado($chamado) : null;
}

/** Mantém as strings numéricas e os nulls retornados pelas consultas legadas. */
function normalizarLinhaChamado(array $chamado): array
{
    foreach ($chamado as $campo => $valor) {
        if (is_int($valor) || is_float($valor)) {
            $chamado[$campo] = (string) $valor;
        }
    }
    return $chamado;
}

/**
 * Escopo compartilhado por listagem, detalhe, indicador e exportação.
 * Scripts CLI sem sessão são integrações internas confiáveis (manifest.md).
 * Em contexto web, uma sessão ausente ou inválida não autoriza nenhum chamado.
 */
function filtroVisibilidadeChamados(): array
{
    if (PHP_SAPI === 'cli' && !isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return ['1 = 1', '', []];
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($uid === false) {
        return ['1 = 0', '', []];
    }
    if (($_SESSION['papel'] ?? '') === 'tecnico') {
        return ['1 = 1', '', []];
    }
    if (($_SESSION['papel'] ?? '') === 'cliente') {
        return ['c.usuario_id = ?', 'i', [$uid]];
    }
    return ['1 = 0', '', []];
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$filtro, $tipos, $parametros] = filtroVisibilidadeChamados();
    $stmt = $db->prepare('SELECT SUM(c.minutos_resposta) AS soma, COUNT(c.minutos_resposta) AS qtd
                         FROM chamados c WHERE ' . $filtro);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) $linha['qtd'] > 0 ? (float) $linha['soma'] / (int) $linha['qtd'] : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    [$filtro, $tipos, $parametros] = filtroVisibilidadeChamados();
    $stmt = $db->prepare('SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, \'-\') AS tecnico_nome
                         FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                         WHERE ' . $filtro . ' ORDER BY c.id');
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $stmt->bind_result($id, $titulo, $status, $criadoEm, $tecnicoNome);
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $stmt->close();
        throw new RuntimeException('Falha ao abrir a exportacao.');
    }
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
        while ($stmt->fetch()) {
            fputcsv($fp, [$id, $titulo, formatarStatus((int) $status), $tecnicoNome, $criadoEm], ',', '"', '');
        }
    } finally {
        fclose($fp);
        $stmt->close();
    }
}
