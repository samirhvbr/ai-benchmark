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
 *
 * Aceita hashes legados em md5 e, em caso de sucesso, migra para password_hash
 * para que credenciais novas não dependam mais do algoritmo quebrado.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return null;
    }

    $hash = (string) $row['senha'];
    $valido = false;

    // Compatibilidade com o esquema legado: senha armazenada como MD5 (32 hex).
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        $valido = hash_equals($hash, md5($senha));
        if ($valido) {
            $novo = password_hash($senha, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($upd !== false) {
                $uid = (int) $row['id'];
                $upd->bind_param('si', $novo, $uid);
                $upd->execute();
            }
        }
    } else {
        $valido = password_verify($senha, $hash);
        if ($valido && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $novo = password_hash($senha, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($upd !== false) {
                $uid = (int) $row['id'];
                $upd->bind_param('si', $novo, $uid);
                $upd->execute();
            }
        }
    }

    if (!$valido) {
        return null;
    }
    return ['id' => (int) $row['id'], 'nome' => $row['nome'], 'papel' => $row['papel']];
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
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Aplica a regra de visibilidade descrita em manifest.md:
 *  - cliente: somente os chamados que ele mesmo abriu (usuario_id = uid);
 *  - técnico: todos os chamados.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, t.nome AS tecnico_nome '
         . 'FROM chamados c '
         . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];

    $where = [];
    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if (isset($_SESSION['papel']) && $_SESSION['papel'] === 'cliente' && isset($_SESSION['uid'])) {
        $where[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = (int) $_SESSION['uid'];
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] !== null ? $c['tecnico_nome'] : '-';
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 *
 * A visibilidade é aplicada pelo caller (index.php) porque a assinatura não
 * transporta contexto de autenticação; preserve-se aqui apenas o filtro por id.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
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
 * Exporta todos os chamados visíveis para o usuário corrente em formato CSV.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Aplica a regra de visibilidade descrita em manifest.md.
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

    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, c.usuario_id, c.tecnico_id, t.nome AS tecnico_nome '
         . 'FROM chamados c '
         . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if (isset($_SESSION['papel']) && $_SESSION['papel'] === 'cliente' && isset($_SESSION['uid'])) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = (int) $_SESSION['uid'];
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        fclose($fp);
        return;
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] !== null ? $c['tecnico_nome'] : '-',
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}