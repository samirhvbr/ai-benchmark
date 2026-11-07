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
    $hash = (string) ($linha['senha'] ?? '');
    if (password_verify($senha, $hash) || md5($senha) === $hash) {
        return ['id' => (int) $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
    }
    return null;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    } else if ($status === 2) {
        return 'Em atendimento';
    } else if ($status === 3) {
        return 'Resolvido';
    }
    return 'Desconhecido';
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
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $uid = null;
    $papel = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        $papel = (string) ($_SESSION['papel'] ?? 'cliente');
    }

    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios u ON c.tecnico_id = u.id WHERE 1=1';
    $where = [];
    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
    }
    if ($papel === 'cliente') {
        $where[] = 'c.usuario_id = ?';
    }
    if ($where) {
        $sql .= ' AND ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '') {
        $valorBusca = '%' . $busca . '%';
    }
    if ($papel === 'cliente') {
        $valorUsuario = (int) ($uid ?? 0);
    }

    if ($busca !== '') {
        if ($papel === 'cliente') {
            $stmt->bind_param('si', $valorBusca, $valorUsuario);
        } else {
            $stmt->bind_param('s', $valorBusca);
        }
    } elseif ($papel === 'cliente') {
        $stmt->bind_param('i', $valorUsuario);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] ?? '-';
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
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $c = $res->fetch_assoc();
    if (!$c) {
        return null;
    }
    $uid = null;
    $papel = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        $papel = (string) ($_SESSION['papel'] ?? 'cliente');
    }
    if ($papel === 'cliente' && (int) $c['usuario_id'] !== $uid) {
        return null;
    }
    return $c;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL');
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
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $uid = null;
    $papel = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
        $papel = (string) ($_SESSION['papel'] ?? 'cliente');
    }

    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios u ON c.tecnico_id = u.id WHERE 1=1';
    $where = [];
    if ($papel === 'cliente') {
        $where[] = 'c.usuario_id = ?';
    }
    if ($where) {
        $sql .= ' AND ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        fclose($fp);
        return;
    }
    if ($where) {
        $stmt->bind_param('i', $uid);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] ?? '-',
            $c['criado_em'],
        ]);
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}
