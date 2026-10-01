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
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $usuario, $hash);
    $stmt->execute();
    $res = $stmt->get_result();
    $linha = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $linha ?: null;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    } elseif ($status === 2) {
        return 'Em atendimento';
    } elseif ($status === 3) {
        return 'Resolvido';
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
    if ($prioridade >= 3) {
        if ($minutos > 30) {
            return ($prioridade === 4) ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
        }
        return 'Alto - dentro do SLA';
    }
    return 'Normal';
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    if (!$stmt) {
        return '-';
    }
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $t = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, "-") AS tecnico_nome 
            FROM chamados c 
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';

    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ? ORDER BY c.criado_em DESC';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
        $stmt->execute();
        $res = $stmt->get_result();
        $chamados = [];
        while ($c = $res->fetch_assoc()) {
            $chamados[] = $c;
        }
        $stmt->close();
        return $chamados;
    }

    $sql .= ' ORDER BY c.criado_em DESC';
    $res = $db->query($sql);
    if (!$res) {
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $chamado = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res && ($row = $res->fetch_assoc()) && $row['media'] !== null) {
        return (float) $row['media'];
    }
    return 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }

    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, "-") AS tecnico_nome 
            FROM chamados c 
            LEFT JOIN usuarios u ON u.id = c.tecnico_id 
            ORDER BY c.id ASC';
    $res = $db->query($sql);
    if ($res !== false) {
        while ($c = $res->fetch_assoc()) {
            fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ]);
        }
    }

    fclose($fp);
}
