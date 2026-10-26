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
 * Busca o nome de um técnico pelo id.
 * Mantida por compatibilidade; listagem e export agora usam JOIN.
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
 * $usuarioId (opcional): restringe aos chamados abertos por esse usuário
 * (regra de visibilidade do cliente). Sem ele, lista todos (técnico/rotinas).
 */
function listarChamados(mysqli $db, string $busca = '', ?int $usuarioId = null): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE 1=1";
    $tipos = '';
    $args = [];
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $args[] = '%' . $busca . '%';
    }
    if ($usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $args[] = $usuarioId;
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($args) {
        $stmt->bind_param($tipos, ...$args);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * $usuarioId (opcional): se informado, só devolve o chamado se for desse dono.
 */
function verChamado(mysqli $db, int $id, ?int $usuarioId = null): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    if ($usuarioId !== null) {
        $stmt = $db->prepare($sql . ' AND usuario_id = ?');
        $stmt->bind_param('ii', $id, $usuarioId);
    } else {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem nenhuma resposta registrada, devolve 0.0.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    return $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Exporta os chamados em CSV direto na saída (sem arquivo intermediário).
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 * $usuarioId (opcional): restringe aos chamados desse dono (cliente).
 */
function exportarCsv(mysqli $db, ?int $usuarioId = null): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, '-') AS tecnico
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($usuarioId !== null) {
        $stmt = $db->prepare($sql . ' WHERE c.usuario_id = ? ORDER BY c.id');
        $stmt->bind_param('i', $usuarioId);
    } else {
        $stmt = $db->prepare($sql . ' ORDER BY c.id');
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
