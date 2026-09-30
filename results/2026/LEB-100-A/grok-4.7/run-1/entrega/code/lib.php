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
    $res = $stmt->get_result();
    $linha = $res ? $res->fetch_assoc() : null;
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
    $res = $stmt->get_result();
    $t = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ? ESCAPE \'\\\\\'';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '') {
        $like = '%' . addcslashes($busca, "%_\\") . '%';
        $stmt->bind_param('s', $like);
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
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $linha = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query(
        'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd
         FROM chamados
         WHERE minutos_resposta IS NOT NULL'
    );
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    $qtd = (int) ($row['qtd'] ?? 0);
    if ($qtd === 0) {
        return 0.0;
    }
    return (int) $row['soma'] / $qtd;
}

function campoCsv(string $valor): string
{
    if ($valor === '-') {
        return $valor;
    }
    if (preg_match('/^\s*[=+\-@]/', $valor) === 1) {
        return "'" . $valor;
    }
    return $valor;
}

function emitirCsv(array $linhas): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    foreach ($linhas as $c) {
        fputcsv($fp, [
            campoCsv((string) $c['id']),
            campoCsv((string) $c['titulo']),
            campoCsv(formatarStatus((int) $c['status'])),
            campoCsv((string) ($c['tecnico_nome'] ?? '-')),
            campoCsv((string) $c['criado_em']),
        ], ',', '"', '\\', "\n");
    }
    fclose($fp);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(u.nome, \'-\') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id
            ORDER BY c.id';
    $res = $db->query($sql);
    if ($res === false) {
        return;
    }
    $linhas = [];
    while ($c = $res->fetch_assoc()) {
        $linhas[] = $c;
    }
    emitirCsv($linhas);
}
