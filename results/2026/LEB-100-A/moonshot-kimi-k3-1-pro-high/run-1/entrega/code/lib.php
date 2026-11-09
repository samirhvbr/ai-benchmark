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

    $hashAtual = $linha['senha'];
    $hashModerno = password_verify($senha, $hashAtual);
    $hashLegado = !$hashModerno && hash_equals($hashAtual, md5($senha));
    if (!$hashModerno && !$hashLegado) {
        return null;
    }

    if ($hashLegado || password_needs_rehash($hashAtual, PASSWORD_DEFAULT)) {
        // Não sobrescreva o MD5 em instalações antigas antes da migração da coluna.
        $coluna = $db->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM INFORMATION_SCHEMA.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
        );
        $info = $coluna ? $coluna->fetch_assoc() : null;
        if ($info && (int) $info['tamanho'] >= 60) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            $update = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            $update->bind_param('si', $novoHash, $linha['id']);
            $update->execute();
        }
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
 * Retorna o dono cuja visibilidade deve ser aplicada, ou null fora de uma
 * sessão web de cliente (técnicos e rotinas internas veem todos os chamados).
 */
function clienteDaSessao(): ?int
{
    if (($_SESSION['papel'] ?? null) !== 'cliente') {
        return null;
    }
    return (int) ($_SESSION['uid'] ?? 0);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $where = [];
    $types = '';
    $params = [];

    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $types .= 's';
        $params[] = '%' . $busca . '%';
    }
    $clienteId = clienteDaSessao();
    if ($clienteId !== null) {
        $where[] = 'c.usuario_id = ?';
        $types .= 'i';
        $params[] = $clienteId;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $clienteId = clienteDaSessao();
    if ($clienteId !== null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        $stmt->bind_param('ii', $id, $clienteId);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        $stmt->bind_param('i', $id);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT COALESCE(AVG(minutos_resposta), 0) AS media FROM chamados '
         . 'WHERE minutos_resposta IS NOT NULL';
    $clienteId = clienteDaSessao();
    if ($clienteId !== null) {
        $stmt = $db->prepare($sql . ' AND usuario_id = ?');
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql);
    }
    return (float) $res->fetch_assoc()['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $clienteId = clienteDaSessao();
    if ($clienteId !== null) {
        $stmt = $db->prepare($sql . ' WHERE c.usuario_id = ? ORDER BY c.id');
        $stmt->bind_param('i', $clienteId);
    } else {
        $stmt = $db->prepare($sql . ' ORDER BY c.id');
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');

    while ($c = $res->fetch_assoc()) {
        // Impede que planilhas interpretem conteúdo dos chamados como fórmula.
        foreach (['titulo', 'tecnico_nome'] as $campo) {
            if (preg_match('/^[=+\-@\t\r]/', $c[$campo]) === 1) {
                $c[$campo] = "'" . $c[$campo];
            }
        }
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
