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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linha) {
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    $hashLegado = preg_match('/\A[a-f0-9]{32}\z/i', $hashArmazenado) === 1;
    $senhaValida = $hashLegado
        ? hash_equals(strtolower($hashArmazenado), md5($senha))
        : password_verify($senha, $hashArmazenado);

    if (!$senhaValida) {
        return null;
    }

    // A base existente usa MD5. Migra a credencial sem exigir redefinição de senha.
    if ($hashLegado) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($atualizar !== false) {
            $id = (int) $linha['id'];
            $atualizar->bind_param('sis', $novoHash, $id, $hashArmazenado);
            $atualizar->execute();
            $atualizar->close();
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
    $stmt->execute();
    $res = $stmt->get_result();
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
    return buscarChamados($db, $busca);
}

/**
 * Lista chamados de um cliente. É usada pelo ponto de entrada web para aplicar
 * a regra de visibilidade sem alterar a assinatura pública de listarChamados.
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return buscarChamados($db, $busca, $usuarioId);
}

/**
 * Consulta interna compartilhada pelas listagens pública e restrita ao cliente.
 */
function buscarChamados(mysqli $db, string $busca, ?int $usuarioId = null): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $condicoes = [];

    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($usuarioId !== null && $busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $termo);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    } elseif ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

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
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    if ($stmt === false) {
        return null;
    }
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
    $res = $db->query('SELECT COALESCE(AVG(minutos_resposta), 0) AS media FROM chamados');
    if ($res === false) {
        return 0.0;
    }
    $linha = $res->fetch_assoc();
    return (float) $linha['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvFiltrado($db);
}

/** Exporta somente os chamados do cliente autenticado. */
function exportarCsvDoUsuario(mysqli $db, int $usuarioId): void
{
    exportarCsvFiltrado($db, $usuarioId);
}

/**
 * Implementação interna do CSV. O filtro opcional mantém o export público
 * completo para rotinas internas e permite à rota web respeitar a visibilidade.
 */
function exportarCsvFiltrado(mysqli $db, ?int $usuarioId = null): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
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
        $stmt->close();
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            textoSeguroParaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            textoSeguroParaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
    $stmt->close();
}

/** Evita que células controladas por usuários sejam interpretadas como fórmulas. */
function textoSeguroParaCsv(string $texto): string
{
    return preg_match('/^[=+\-@\t\r]/', $texto) === 1 ? "'" . $texto : $texto;
}
