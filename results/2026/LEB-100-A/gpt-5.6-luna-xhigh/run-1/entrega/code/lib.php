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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('ss', $usuario, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $resultado = $stmt->get_result();
    if ($resultado === false) {
        $stmt->close();
        return null;
    }
    $linha = $resultado->fetch_assoc();
    $stmt->close();
    return $linha ?: null;
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
 * Retorna o predicado de visibilidade para a sessão web atual.
 * Chamadas sem sessão continuam disponíveis para rotinas internas legadas.
 */
function escopoChamados(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (!isset($_SESSION['uid'], $_SESSION['papel'])) {
        return '1 = 0';
    }
    if ($_SESSION['papel'] === 'tecnico') {
        return '';
    }
    if ($_SESSION['papel'] === 'cliente' && is_scalar($_SESSION['uid'])) {
        return 'c.usuario_id = ' . (int) $_SESSION['uid'];
    }
    return '1 = 0';
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
    if ($res === false) {
        return '-';
    }
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $where = [];
    $escopo = escopoChamados();
    if ($escopo !== '') {
        $where[] = $escopo;
    }
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados AS c'
         . ' LEFT JOIN usuarios AS t ON t.id = c.tecnico_id';
    $paramBusca = null;
    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $paramBusca = '%' . $busca . '%';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($paramBusca !== null) {
        $stmt->bind_param('s', $paramBusca);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return [];
    }

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
    $where = ['c.id = ' . (int) $id];
    $escopo = escopoChamados();
    if ($escopo !== '') {
        $where[] = $escopo;
    }
    $res = $db->query('SELECT c.* FROM chamados AS c WHERE ' . implode(' AND ', $where));
    if ($res === false) {
        return null;
    }
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $where = ['c.minutos_resposta IS NOT NULL'];
    $escopo = escopoChamados();
    if ($escopo !== '') {
        $where[] = $escopo;
    }
    $res = $db->query('SELECT c.minutos_resposta FROM chamados AS c WHERE ' . implode(' AND ', $where));
    if ($res === false) {
        return 0.0;
    }
    $soma = 0;
    $qtd = 0;
    while ($row = $res->fetch_assoc()) {
        $soma += (int) $row['minutos_resposta'];
        $qtd++;
    }
    return $qtd > 0 ? $soma / $qtd : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $where = [];
    $escopo = escopoChamados();
    if ($escopo !== '') {
        $where[] = $escopo;
    }
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados AS c'
         . ' LEFT JOIN usuarios AS t ON t.id = c.tecnico_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.id';
    $res = $db->query($sql);
    if ($res === false) {
        return;
    }

    $caminho = tempnam(EXPORT_DIR, 'chamados-');
    if ($caminho === false) {
        $res->free();
        return;
    }
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        $res->free();
        unlink($caminho);
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
    $res->free();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
    unlink($caminho);
}
