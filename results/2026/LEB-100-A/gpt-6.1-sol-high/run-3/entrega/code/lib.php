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
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= 30) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Busca o nome de um técnico pelo id (mantida para consumidores legados).
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
 * Escopo compartilhado por listagem, detalhe, indicador e CSV.
 * Sem identidade de sessão, os scripts internos mantêm a visão global.
 * O ponto de entrada web exige autenticação antes de chamar estas funções.
 */
function filtroVisibilidadeChamados(): string
{
    if (!isset($_SESSION['uid'])) {
        return '1 = 1';
    }
    if (($_SESSION['papel'] ?? '') === 'tecnico') {
        return '1 = 1';
    }
    if (($_SESSION['papel'] ?? '') === 'cliente' && (int) $_SESSION['uid'] > 0) {
        return 'c.usuario_id = ' . (int) $_SESSION['uid'];
    }
    return '1 = 0';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . filtroVisibilidadeChamados();
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    }
    $stmt->execute();
    $chamados = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado visível pelo id, ou null se ausente/sem permissão.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT c.* FROM chamados c WHERE c.id = ? AND ' . filtroVisibilidadeChamados());
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $chamado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem respostas no escopo, a média exibida é zero.
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(AVG(c.minutos_resposta), 0) AS media
                       FROM chamados c WHERE ' . filtroVisibilidadeChamados());
    return (float) $res->fetch_assoc()['media'];
}

/**
 * Impede que texto controlável seja interpretado como fórmula na planilha.
 * O marcador legado de técnico ausente continua sendo "-".
 */
function textoCsvSeguro(string $texto): string
{
    if ($texto !== '-' && preg_match('/^[\x00-\x20]*[=+@-]/', $texto)) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Exporta os chamados visíveis, sem arquivo compartilhado no servidor.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
                       FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                       WHERE " . filtroVisibilidadeChamados() . ' ORDER BY c.id');
    if ($res === false) {
        return;
    }
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $res->free();
        return;
    }
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        // Escape explícito evita a opção depreciada no PHP 8.4 e preserva o CSV legado.
        fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', "\\");
        while ($c = $res->fetch_assoc()) {
            fputcsv($fp, [
                $c['id'],
                textoCsvSeguro($c['titulo']),
                formatarStatus((int) $c['status']),
                textoCsvSeguro($c['tecnico_nome']),
                $c['criado_em'],
            ], ',', '"', "\\");
        }
    } finally {
        fclose($fp);
        $res->free();
    }
}
