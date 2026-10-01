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
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('s', $usuario);
    if (!$stmt->execute()) {
        return null;
    }
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    $hashLegado = preg_match('/\A[a-f0-9]{32}\z/i', $hashArmazenado) === 1;
    $senhaValida = password_verify($senha, $hashArmazenado)
        || ($hashLegado && hash_equals($hashArmazenado, md5($senha)));
    if (!$senhaValida) {
        return null;
    }

    // Atualiza hashes MD5 legados depois de uma autenticação bem-sucedida.
    if ($hashLegado) {
        $novoHash = password_hash($senha, PASSWORD_BCRYPT);
        $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($atualizar !== false) {
            $id = (int) $linha['id'];
            $atualizar->bind_param('si', $novoHash, $id);
            $atualizar->execute();
        }
    }

    return [
        'id' => $linha['id'],
        'nome' => $linha['nome'],
        'papel' => $linha['papel'],
    ];
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
        return '-';
    }
    $t = $stmt->get_result()->fetch_assoc();
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

/**
 * Lista chamados de um cliente para uso no painel autenticado.
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return buscarChamados($db, $busca, $usuarioId);
}

/**
 * Executa a consulta compartilhada pela listagem geral e pela listagem restrita.
 */
function buscarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome '
        . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($busca !== '' && $usuarioId !== null) {
        $sql .= ' WHERE c.titulo LIKE ? AND c.usuario_id = ?';
    } elseif ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
    } elseif ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '' && $usuarioId !== null) {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('si', $padrao, $usuarioId);
    } elseif ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    if (!$stmt->execute()) {
        return [];
    }
    $res = $stmt->get_result();

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
    if (!$stmt->execute()) {
        return null;
    }
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    return $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Calcula a média apenas dos chamados visíveis ao cliente autenticado.
 */
function mediaRespostaDoUsuario(mysqli $db, int $usuarioId): float
{
    $stmt = $db->prepare(
        'SELECT AVG(minutos_resposta) AS media FROM chamados '
        . 'WHERE usuario_id = ? AND minutos_resposta IS NOT NULL'
    );
    if ($stmt === false) {
        return 0.0;
    }
    $stmt->bind_param('i', $usuarioId);
    if (!$stmt->execute()) {
        return 0.0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    return $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvDoUsuario($db, null);
}

/**
 * Exporta chamados globais ou apenas os pertencentes a um cliente.
 */
function exportarCsvDoUsuario(mysqli $db, ?int $usuarioId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, \'-\') AS tecnico_nome '
        . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id ASC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    if ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    if (!$stmt->execute()) {
        return;
    }
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '');
    }
    fclose($fp);
}
