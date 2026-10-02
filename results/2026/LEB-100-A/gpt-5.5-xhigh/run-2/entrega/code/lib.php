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
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ?: null;
}

function clienteLogadoId(): ?int
{
    if (isset($_SESSION['uid'], $_SESSION['papel']) && $_SESSION['papel'] === 'cliente') {
        return (int) $_SESSION['uid'];
    }
    return null;
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
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    if (!$stmt) {
        return '-';
    }
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $clienteId = clienteLogadoId();
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
              FROM chamados c
         LEFT JOIN usuarios u ON u.id = c.tecnico_id";

    if ($busca !== '' && $clienteId !== null) {
        $sql .= ' WHERE c.titulo LIKE ? AND c.usuario_id = ?';
        $sql .= ' ORDER BY c.criado_em DESC';
        $like = '%' . $busca . '%';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('si', $like, $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
        $sql .= ' ORDER BY c.criado_em DESC';
        $like = '%' . $busca . '%';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
    } else if ($clienteId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $sql .= ' ORDER BY c.criado_em DESC';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $sql .= ' ORDER BY c.criado_em DESC';
        $res = $db->query($sql);
    }
    if ($res === false) {
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
    $clienteId = clienteLogadoId();
    if ($clienteId !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $id, $clienteId);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $clienteId = clienteLogadoId();
    if ($clienteId !== null) {
        $stmt = $db->prepare(
            'SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL AND usuario_id = ?'
        );
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    }
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    if (!$row || $row['media'] === null) {
        return 0.0;
    }
    return (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $clienteId = clienteLogadoId();
    if ($clienteId !== null) {
        $stmt = $db->prepare("SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
                                FROM chamados c
                           LEFT JOIN usuarios u ON u.id = c.tecnico_id
                               WHERE c.usuario_id = ?
                            ORDER BY c.id");
        if (!$stmt) {
            fclose($fp);
            return;
        }
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query("SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
                             FROM chamados c
                        LEFT JOIN usuarios u ON u.id = c.tecnico_id
                         ORDER BY c.id");
    }
    if ($res === false) {
        fclose($fp);
        return;
    }
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}
