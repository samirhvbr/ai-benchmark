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
    if (!$linha) {
        return null;
    }

    $hash = (string) $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    if ($legado) {
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }

        // Atualização gradual dos hashes MD5 legados após um login válido.
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        if ($novoHash !== false) {
            $coluna = $db->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho "
                . "FROM information_schema.COLUMNS "
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
            )->fetch_assoc();
            if ($coluna && (int) $coluna['tamanho'] >= strlen($novoHash)) {
                $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                $idUsuario = (int) $linha['id'];
                $atualizar->bind_param('sis', $novoHash, $idUsuario, $hash);
                $atualizar->execute();
            }
        }
    } elseif (!password_verify($senha, $hash)) {
        return null;
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
 * Busca o nome de um técnico pelo id (usado por consumidores internos).
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
 * Executa a consulta comum de listagem. A ordenação vem somente de chamadas
 * internas com valores constantes; os dados fornecidos pelo usuário são bindados.
 */
function painelConsultarChamados(mysqli $db, string $busca, ?int $usuarioId, string $ordenacao): mysqli_result
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $condicoes = [];
    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= $ordenacao === 'id' ? ' ORDER BY c.id ASC' : ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($usuarioId !== null && $busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $padrao);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    } elseif ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/** Monta a lista preservando a chave tecnico_nome do contrato público. */
function painelMontarListaChamados(mysqli_result $res): array
{
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return painelMontarListaChamados(painelConsultarChamados($db, $busca, null, 'criado_em'));
}

/** Listagem da interface com a regra de visibilidade aplicada. */
function painelListarChamadosVisiveis(mysqli $db, string $busca, int $usuarioId, string $papel): array
{
    $dono = $papel === 'tecnico' ? null : $usuarioId;
    return painelMontarListaChamados(painelConsultarChamados($db, $busca, $dono, 'criado_em'));
}

/** Busca um chamado, aplicando um dono opcional para as rotas de cliente. */
function painelConsultarChamado(mysqli $db, int $id, ?int $usuarioId): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    if ($usuarioId !== null) {
        $sql .= ' AND usuario_id = ?';
    }
    $stmt = $db->prepare($sql);
    if ($usuarioId !== null) {
        $stmt->bind_param('ii', $id, $usuarioId);
    } else {
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Carrega um chamado pelo id, para consumidores internos.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return painelConsultarChamado($db, $id, null);
}

/** Detalhe da interface com a regra de visibilidade aplicada. */
function painelVerChamadoVisivel(mysqli $db, int $id, int $usuarioId, string $papel): ?array
{
    return painelConsultarChamado($db, $id, $papel === 'tecnico' ? null : $usuarioId);
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    return $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/** Emite o CSV no formato público a partir de um resultado ordenado. */
function painelProtegerFormulaCsv(string $valor): string
{
    if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $valor) === 1) {
        return "'" . $valor;
    }
    return $valor;
}

function painelEmitirCsv(mysqli_result $res): void
{
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            painelProtegerFormulaCsv((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            painelProtegerFormulaCsv((string) $c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/** Exporta todos os chamados para os consumidores internos (assinatura mantida). */
function exportarCsv(mysqli $db): void
{
    $res = painelConsultarChamados($db, '', null, 'id');
    painelEmitirCsv($res);
}

/** Exportação da interface com a regra de visibilidade aplicada. */
function painelExportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    $dono = $papel === 'tecnico' ? null : $usuarioId;
    $res = painelConsultarChamados($db, '', $dono, 'id');
    painelEmitirCsv($res);
}
