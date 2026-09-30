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
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $hashArmazenado = $linha['senha'];
    $senhaLegada = preg_match('/^[a-f0-9]{32}$/D', $hashArmazenado) === 1;
    $valida = $senhaLegada
        ? hash_equals($hashArmazenado, md5($senha))
        : password_verify($senha, $hashArmazenado);
    if (!$valida) {
        return null;
    }

    // Em bancos ainda não migrados a coluna CHAR(32) não comporta bcrypt.
    // Nesse caso a autenticação legada continua funcionando até o ALTER TABLE.
    $podeAtualizarHash = !$senhaLegada || colunaSenhaSuportaPasswordHash($db);
    if ($podeAtualizarHash && ($senhaLegada || password_needs_rehash($hashArmazenado, PASSWORD_DEFAULT))) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $usuarioId = (int) $linha['id'];
        $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($atualizar !== false) {
            $atualizar->bind_param('sis', $novoHash, $usuarioId, $hashArmazenado);
            $atualizar->execute();
        }
    }

    unset($linha['senha']);
    return $linha;
}

/** Verifica se a instalação já ampliou a coluna de senha para hashes modernos. */
function colunaSenhaSuportaPasswordHash(mysqli $db): bool
{
    $res = $db->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho"
        . " FROM information_schema.COLUMNS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
    );
    if ($res === false || !($coluna = $res->fetch_assoc())) {
        return false;
    }
    return (int) $coluna['tamanho'] >= 255;
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
    $stmt->execute();
    $res = $stmt->get_result();
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados AS c'
        . ' LEFT JOIN usuarios AS t ON t.id = c.tecnico_id';
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE CONCAT(\'%\', ?, \'%\')';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '') {
        $stmt->bind_param('s', $busca);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Lista somente os chamados pertencentes a um cliente autenticado.
 * Esta função é interna: listarChamados continua disponível para os relatórios
 * técnicos que, por contrato, consultam todos os chamados.
 */
function listarChamadosDoCliente(mysqli $db, int $usuarioId, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados AS c'
        . ' LEFT JOIN usuarios AS t ON t.id = c.tecnico_id'
        . ' WHERE c.usuario_id = ?';
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE CONCAT(\'%\', ?, \'%\')';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '') {
        $stmt->bind_param('is', $usuarioId, $busca);
    } else {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
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
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(AVG(minutos_resposta), 0) AS media FROM chamados');
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    return (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvComFiltro($db, null);
}

/**
 * Exporta todos os chamados ou, quando informado, apenas os de um cliente.
 * O filtro é usado pela rota web; a função pública exportarCsv permanece
 * compatível com a exportação administrativa completa.
 */
function exportarCsvComFiltro(mysqli $db, ?int $usuarioId): void
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados AS c'
        . ' LEFT JOIN usuarios AS t ON t.id = c.tecnico_id';
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
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

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
}
