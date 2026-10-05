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

function sessaoClienteId(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['uid'])) {
        return null;
    }
    if (($_SESSION['papel'] ?? null) === 'tecnico') {
        return null;
    }
    return (int) $_SESSION['uid'];
}

function escaparLike(string $valor): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $valor);
}

function celulaCsv(string $valor): string
{
    if ($valor !== '' && preg_match('/\A[=+@\t\r\n]/', $valor) === 1) {
        return "'" . $valor;
    }
    return $valor;
}

function linhaComoQuery(array $c): array
{
    foreach ($c as $k => $v) {
        if (is_int($v) || is_float($v)) {
            $c[$k] = (string) $v;
        }
    }
    return $c;
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
    $dono = sessaoClienteId();
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    $where = [];
    if ($busca !== '') {
        $where[] = "c.titulo LIKE ? ESCAPE '!'";
    }
    if ($dono !== null) {
        $where[] = 'c.usuario_id = ?';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    $like = '%' . escaparLike($busca) . '%';
    if ($busca !== '' && $dono !== null) {
        $stmt->bind_param('si', $like, $dono);
    } elseif ($busca !== '') {
        $stmt->bind_param('s', $like);
    } elseif ($dono !== null) {
        $stmt->bind_param('i', $dono);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $chamados = [];
    if ($res instanceof mysqli_result) {
        while ($c = $res->fetch_assoc()) {
            $c = linhaComoQuery($c);
            if (!isset($c['tecnico_nome']) || $c['tecnico_nome'] === null) {
                $c['tecnico_nome'] = '-';
            }
            $chamados[] = $c;
        }
    }
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $dono = sessaoClienteId();
    if ($dono !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $id, $dono);
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
    $linha = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $linha ? linhaComoQuery($linha) : null;
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
    $dono = sessaoClienteId();
    $sql = "SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, '-') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    if ($dono !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        return;
    }
    if ($dono !== null) {
        $stmt->bind_param('i', $dono);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        if (!headers_sent()) {
            http_response_code(500);
        }
        return;
    }
    $res = $stmt->get_result();
    if (!$res) {
        $stmt->close();
        if (!headers_sent()) {
            http_response_code(500);
        }
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $stmt->close();
        return;
    }
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        $tecnico = $c['tecnico_nome'] ?? '-';
        if ($tecnico === null) {
            $tecnico = '-';
        }
        fputcsv($fp, [
            $c['id'],
            celulaCsv((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            celulaCsv((string) $tecnico),
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);
    $stmt->close();
}
