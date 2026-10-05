<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

if (!function_exists('netxPainelClienteRestritoId')) {
    function netxPainelClienteRestritoId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (($_SESSION['papel'] ?? null) !== 'cliente') {
            return null;
        }
        $uid = $_SESSION['uid'] ?? null;
        if (is_int($uid) && $uid > 0) {
            return $uid;
        }
        if (is_string($uid) && ctype_digit($uid) && (int) $uid > 0) {
            return (int) $uid;
        }
        return 0;
    }
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $hash = md5($senha);
    $stmt = $db->prepare('SELECT id, nome, papel FROM usuarios WHERE login = ? AND senha = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ss', $usuario, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return null;
    }
    $linha = $res->fetch_assoc();
    $stmt->close();
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
    if (!$stmt->execute()) {
        $stmt->close();
        return '-';
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return '-';
    }
    $t = $res->fetch_assoc();
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $restrito = netxPainelClienteRestritoId();
    $like = null;
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, '\\%_') . '%';
    }

    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';

    if ($restrito !== null && $like !== null) {
        $sql .= " WHERE c.usuario_id = ? AND c.titulo LIKE ? ESCAPE '\\\\' ORDER BY c.criado_em DESC";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('is', $restrito, $like);
    } elseif ($restrito !== null) {
        $sql .= ' WHERE c.usuario_id = ? ORDER BY c.criado_em DESC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $restrito);
    } elseif ($like !== null) {
        $sql .= " WHERE c.titulo LIKE ? ESCAPE '\\\\' ORDER BY c.criado_em DESC";
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $like);
    } else {
        $sql .= ' ORDER BY c.criado_em DESC';
        $res = $db->query($sql);
        if ($res === false) {
            return [];
        }
        $chamados = [];
        while ($c = $res->fetch_assoc()) {
            $chamados[] = $c;
        }
        return $chamados;
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return [];
    }
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $restrito = netxPainelClienteRestritoId();
    if ($restrito !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $id, $restrito);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return null;
    }
    $linha = $res->fetch_assoc();
    $stmt->close();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
    $soma = 0;
    $qtd = 0;
    while ($row = $res->fetch_assoc()) {
        $soma += (int) $row['minutos_resposta'];
        $qtd++;
    }
    if ($qtd === 0) {
        return 0.0;
    }
    return $soma / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $restrito = netxPainelClienteRestritoId();
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($restrito !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = null;
    if ($restrito !== null) {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('i', $restrito);
        if (!$stmt->execute()) {
            $stmt->close();
            return;
        }
        $res = $stmt->get_result();
        if ($res === false) {
            $stmt->close();
            return;
        }
    } else {
        $res = $db->query($sql);
        if ($res === false) {
            return;
        }
    }

    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        if ($stmt !== null) {
            $stmt->close();
        }
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
        ], ',', '"', '\\');
    }
    if ($stmt !== null) {
        $stmt->close();
    }
    rewind($fp);
    $csv = stream_get_contents($fp);
    fclose($fp);
    if ($csv === false || $csv === '') {
        return;
    }

    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        header('Content-Length: ' . strlen($csv));
    }
    echo $csv;
}
