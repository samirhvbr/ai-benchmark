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
        $stmt->close();
        return null;
    }
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $armazenada = (string) $linha['senha'];
    $algoritmo = password_get_info($armazenada)['algo'];
    $valida = $algoritmo !== 0
        ? password_verify($senha, $armazenada)
        : hash_equals(strtolower($armazenada), md5($senha));
    if (!$valida) {
        return null;
    }

    if ($algoritmo === 0) {
        migrarSenhaLegada($db, (int) $linha['id'], $senha, $armazenada);
    }

    unset($linha['senha']);
    return $linha;
}

/**
 * Atualiza um hash MD5 após um login correto quando o schema já aceita hashes
 * longos. A verificação do tamanho evita truncar hashes em instalações antigas.
 */
function migrarSenhaLegada(mysqli $db, int $usuarioId, string $senha, string $hashLegado): void
{
    $meta = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho
                        FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'usuarios'
                          AND COLUMN_NAME = 'senha'");
    if ($meta === false) {
        return;
    }
    $coluna = $meta->fetch_assoc();
    $meta->free();
    if (!$coluna || (int) $coluna['tamanho'] < 255) {
        return;
    }

    $novoHash = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
    if ($stmt === false) {
        return;
    }
    $stmt->bind_param('sis', $novoHash, $usuarioId, $hashLegado);
    $stmt->execute();
    $stmt->close();
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
    $t = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Retorna o contexto de acesso da sessão web, quando houver um usuário autenticado.
 * Scripts internos que chamam a biblioteca fora de uma sessão continuam vendo todos
 * os chamados, como no contrato legado.
 */
function contextoUsuarioAtual(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION) || !array_key_exists('uid', $_SESSION)) {
        return null;
    }
    if (!is_numeric($_SESSION['uid']) || !in_array($_SESSION['papel'] ?? null, ['cliente', 'tecnico'], true)) {
        return ['id' => -1, 'papel' => 'cliente'];
    }
    return ['id' => (int) $_SESSION['uid'], 'papel' => $_SESSION['papel']];
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $contexto = contextoUsuarioAtual();
    $usuarioId = $contexto['id'] ?? 0;
    $condicoes = [];
    if ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $condicoes[] = 'c.usuario_id = ?';
    }
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($contexto !== null && $contexto['papel'] !== 'tecnico' && $busca !== '') {
        $like = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $like);
    } elseif ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $stmt->bind_param('i', $usuarioId);
    } elseif ($busca !== '') {
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
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
    $contexto = contextoUsuarioAtual();
    if ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $usuarioId = $contexto['id'];
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $id, $usuarioId);
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
    $chamado = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $chamado;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $contexto = contextoUsuarioAtual();
    if ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $usuarioId = $contexto['id'];
        $stmt = $db->prepare('SELECT AVG(minutos_resposta) AS media
                              FROM chamados
                              WHERE minutos_resposta IS NOT NULL AND usuario_id = ?');
        if ($stmt === false) {
            return 0.0;
        }
        $stmt->bind_param('i', $usuarioId);
    } else {
        $stmt = $db->prepare('SELECT AVG(minutos_resposta) AS media
                              FROM chamados
                              WHERE minutos_resposta IS NOT NULL');
        if ($stmt === false) {
            return 0.0;
        }
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return 0.0;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Evita que valores controlados por usuários sejam interpretados como fórmulas
 * por planilhas ao abrir o CSV.
 */
function valorCsvSeguro(string $valor): string
{
    if ($valor !== '' && $valor !== '-' && in_array($valor[0], ['=', '+', '-', '@'], true)) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $contexto = contextoUsuarioAtual();
    $usuarioId = $contexto['id'] ?? 0;
    $sql = "SELECT c.id, c.titulo, c.status, COALESCE(t.nome, '-') AS tecnico_nome, c.criado_em
            FROM chamados c
            LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    if ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id ASC';
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        fclose($fp);
        return;
    }
    if ($contexto !== null && $contexto['papel'] !== 'tecnico') {
        $stmt->bind_param('i', $usuarioId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        fclose($fp);
        return;
    }
    $res = $stmt->get_result();
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            valorCsvSeguro((string) $c['titulo']),
            formatarStatus((int) $c['status']),
            valorCsvSeguro((string) $c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    $stmt->close();
    fclose($fp);
}
