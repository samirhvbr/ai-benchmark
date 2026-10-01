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
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    $legado = preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }
    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novoHash, $linha['id'], $hash);
        $stmt->execute();
        $stmt->close();
    }
    unset($linha['senha']);
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
 * Busca o nome de um técnico pelo id (mantida para consumidores legados).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    $res->free();
    return $t ? $t['nome'] : '-';
}

/**
 * Escopo comum às consultas de chamados (sempre com alias c).
 * Relatórios internos sem uid de sessão mantêm o acesso global legado.
 * Na interface web, index.php exige autenticação antes de chamar estas funções.
 */
function condicaoVisibilidadeChamados(): string
{
    if (!isset($_SESSION['uid'])) {
        return '1 = 1';
    }
    if (($_SESSION['papel'] ?? null) === 'tecnico') {
        return '1 = 1';
    }
    if (($_SESSION['papel'] ?? null) === 'cliente' && (int) $_SESSION['uid'] > 0) {
        return 'c.usuario_id = ' . (int) $_SESSION['uid'];
    }
    return '1 = 0';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . condicaoVisibilidadeChamados();
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
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT c.* FROM chamados c WHERE c.id = ? AND ' . condicaoVisibilidadeChamados());
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $chamado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(AVG(c.minutos_resposta + 0e0), 0) AS media FROM chamados c WHERE '
        . condicaoVisibilidadeChamados());
    $row = $res->fetch_assoc();
    $res->free();
    return (float) $row['media'];
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        throw new RuntimeException('Falha ao preparar a exportacao.');
    }
    $res = null;
    try {
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao escrever a exportacao.');
        }
        $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . condicaoVisibilidadeChamados() . ' ORDER BY c.id', MYSQLI_USE_RESULT);
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever a exportacao.');
            }
        }
        if (!rewind($fp)) {
            throw new RuntimeException('Falha ao ler a exportacao.');
        }
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
