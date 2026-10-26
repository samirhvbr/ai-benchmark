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
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
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
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . filtroVisibilidadeChamados();
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    $res->free();
    $stmt->close();
    return $chamados;
}

/**
 * Escopo comum às leituras. Chamadas internas sem identidade de sessão mantêm
 * o escopo global legado. O index.php exige login antes de chamar a biblioteca.
 * Uma identidade presente, mas inválida ou incompleta, não recebe dados.
 * O alias c é usado por todas as consultas de chamados abaixo.
 */
function filtroVisibilidadeChamados(): string
{
    if (!isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return '1 = 1';
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid > 0 && $papel === 'tecnico') {
        return '1 = 1';
    }
    if ($uid > 0 && $papel === 'cliente') {
        return 'c.usuario_id = ' . $uid;
    }
    return '1 = 0';
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT c.* FROM chamados c WHERE c.id = ' . $id
        . ' AND ' . filtroVisibilidadeChamados());
    $chamado = $res->fetch_assoc();
    $res->free();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    // O literal aproximado preserva a precisão float da divisão PHP anterior.
    $res = $db->query('SELECT AVG(c.minutos_resposta + 0e0) AS media FROM chamados c WHERE '
        . filtroVisibilidadeChamados() . ' AND c.minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    $res->free();
    return (float) ($row['media'] ?? 0);
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    // Stream exclusivo por chamada; acima de 2 MiB, usa temporário privado.
    $fp = fopen('php://temp/maxmemory:2097152', 'w+');
    if ($fp === false) {
        throw new RuntimeException('Falha ao preparar a exportacao.');
    }
    $res = null;
    try {
        // Cabeçalho literal do manifesto, inclusive o espaço em "Aberto em".
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao gravar a exportacao.');
        }
        $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . filtroVisibilidadeChamados() . ' ORDER BY c.id', MYSQLI_USE_RESULT);
        if ($res === false) {
            throw new RuntimeException('Falha ao consultar a exportacao.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao gravar a exportacao.');
            }
        }
        rewind($fp);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        fpassthru($fp);
    } finally {
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        fclose($fp);
    }
}
