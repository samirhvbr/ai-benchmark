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
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos > 30) {
        return $prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
    }
    return 'Alto - dentro do SLA';
}

/**
 * Busca o nome de um técnico pelo id (usado internamente pela listagem/export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Quando $papel === 'cliente', restringe aos chamados do $usuarioId
 * (regra de negócio do manifesto). Para outros papéis, não filtra.
 */
function listarChamados(mysqli $db, string $busca = '', ?int $usuarioId = null, ?string $papel = null): array
{
    $sql = 'SELECT c.id, c.usuario_id, c.tecnico_id, c.titulo, c.descricao, c.status, '
         . 'c.prioridade, c.minutos_resposta, c.criado_em, '
         . "COALESCE(t.nome, '-') AS tecnico_nome "
         . 'FROM chamados c '
         . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id';

    $conds = [];
    $params = [];
    $types  = '';

    if ($papel === 'cliente' && $usuarioId !== null) {
        $conds[] = 'c.usuario_id = ?';
        $params[] = $usuarioId;
        $types  .= 'i';
    }

    if ($busca !== '') {
        $conds[] = 'c.titulo LIKE ?';
        $params[] = '%' . $busca . '%';
        $types  .= 's';
    }

    if ($conds) {
        $sql .= ' WHERE ' . implode(' AND ', $conds);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Quando $papel === 'cliente', só devolve chamados do $usuarioId.
 */
function verChamado(mysqli $db, int $id, ?int $usuarioId = null, ?string $papel = null): ?array
{
    $sql    = 'SELECT c.id, c.usuario_id, c.tecnico_id, c.titulo, c.descricao, c.status, '
            . 'c.prioridade, c.minutos_resposta, c.criado_em, '
            . "COALESCE(t.nome, '-') AS tecnico_nome "
            . 'FROM chamados c '
            . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id '
            . 'WHERE c.id = ?';
    $params = [$id];
    $types  = 'i';

    if ($papel === 'cliente' && $usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $params[] = $usuarioId;
        $types  .= 'i';
    }

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Quando $papel === 'cliente', considera apenas os chamados do $usuarioId.
 */
function mediaResposta(mysqli $db, ?int $usuarioId = null, ?string $papel = null): float
{
    $sql    = 'SELECT AVG(minutos_resposta) AS m FROM chamados WHERE minutos_resposta IS NOT NULL';
    $params = [];
    $types  = '';

    if ($papel === 'cliente' && $usuarioId !== null) {
        $sql .= ' AND usuario_id = ?';
        $params[] = $usuarioId;
        $types  .= 'i';
    }

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row === null || $row['m'] === null ? 0.0 : (float) $row['m'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Quando $papel === 'cliente', exporta apenas os chamados do $usuarioId.
 */
function exportarCsv(mysqli $db, ?int $usuarioId = null, ?string $papel = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $sql    = 'SELECT c.id, c.titulo, c.status, '
            . "COALESCE(t.nome, '-') AS tecnico_nome, c.criado_em "
            . 'FROM chamados c '
            . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $params = [];
    $types  = '';

    if ($papel === 'cliente' && $usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $params[] = $usuarioId;
        $types  .= 'i';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($c = $res->fetch_assoc()) {
        fputcsv($out, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($out);
}
