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
 * 1 => 'Aberto', 2 => 'Em atendimento', 3 => 'Resolvido' (contrato de valor).
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    }
    if ($status === 2) {
        return 'Em atendimento';
    }
    if ($status === 3) {
        return 'Resolvido';
    }
    return 'Desconhecido';
}

/**
 * Retorna ['uid','papel'] quando há uma sessão web ativa, ou null quando a
 * função é chamada fora de uma sessão (rotinas externas do ISP, CLI).
 * Usado para aplicar a regra de visibilidade apenas no contexto web.
 */
function usuarioLogadoSessao(): ?array
{
    if (session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION['uid'], $_SESSION['papel'])
    ) {
        return ['uid' => (int) $_SESSION['uid'], 'papel' => $_SESSION['papel']];
    }
    return null;
}

/**
 * Aplica a regra de visibilidade do manifesto: um cliente só vê os próprios
 * chamados; um técnico (ou um chamador sem sessão) vê todos.
 * Retorna [condicao_sql, tipos, params] para compor a consulta preparada.
 */
function condicaoVisibilidade(): array
{
    $u = usuarioLogadoSessao();
    if ($u !== null && $u['papel'] === 'cliente') {
        return ['usuario_id = ?', 'i', [$u['uid']]];
    }
    return ['', '', []];
}

/**
 * Classifica a prioridade de um chamado combinando prioridade e SLA.
 */
function rotuloPrioridade(int $prioridade, ?int $minutos): string
{
    if ($minutos !== null) {
        if ($prioridade >= 3) {
            if ($minutos > 30) {
                if ($prioridade === 4) {
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
    static $cache = [];
    if (isset($cache[$tecnicoId])) {
        return $cache[$tecnicoId];
    }
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $cache[$tecnicoId] = ($t ? $t['nome'] : '-');
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT * FROM chamados';
    $types = '';
    $params = [];

    [$cond, $tVis, $pVis] = condicaoVisibilidade();
    if ($cond !== '') {
        $sql .= ' WHERE ' . $cond;
        $types .= $tVis;
        $params = array_merge($params, $pVis);
    }
    if ($busca !== '') {
        $sql .= ($cond !== '' ? ' AND' : ' WHERE') . ' titulo LIKE ?';
        $types .= 's';
        $params[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $u = usuarioLogadoSessao();
    if ($u !== null && $u['papel'] === 'cliente') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        $uid = $u['uid'];
        $stmt->bind_param('ii', $id, $uid);
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
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    // Regra de visibilidade: sessão web de cliente exporta só os próprios
    // chamados; chamadores sem sessão (rotina noturna) exportam todos.
    $u = usuarioLogadoSessao();
    if ($u !== null && $u['papel'] === 'cliente') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY id');
        $uid = $u['uid'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query('SELECT * FROM chamados ORDER BY id');
    }
    if ($res === false) {
        return;
    }
    while ($c = $res->fetch_assoc()) {
        $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
