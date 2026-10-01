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
    if ($status === 3) {
        return 'Resolvido';
    }
    return 'Desconhecido';
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
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    if ($stmt === false) {
        return '-';
    }
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $t = $res ? $res->fetch_assoc() : null;
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return buscarChamados($db, $busca, null);
}

function buscarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome"
        . ' FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $conds = [];
    if ($usuarioId !== null) {
        $conds[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $conds[] = "c.titulo LIKE ? ESCAPE '\\\\'";
    }
    if ($conds) {
        $sql .= ' WHERE ' . implode(' AND ', $conds);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    if ($usuarioId === null && $busca === '') {
        $res = $db->query($sql);
    } else {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        if ($usuarioId !== null && $busca !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
            $stmt->bind_param('is', $usuarioId, $like);
        } elseif ($usuarioId !== null) {
            $stmt->bind_param('i', $usuarioId);
        } else {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
            $stmt->bind_param('s', $like);
        }
        $stmt->execute();
        $res = $stmt->get_result();
    }

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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) {
        return null;
    }
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query(
        'SELECT COALESCE(SUM(minutos_resposta), 0) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados'
    );
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    if (!$row) {
        return 0.0;
    }
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    emitirCsv($db, null);
}

function emitirCsv(mysqli $db, ?int $usuarioId): void
{
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome"
        . ' FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    if ($usuarioId !== null) {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql);
    }
    if (!$res) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\', "\n");
    }
    fclose($fp);
}
