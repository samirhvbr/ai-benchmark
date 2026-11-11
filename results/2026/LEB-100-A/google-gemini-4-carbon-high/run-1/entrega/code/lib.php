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
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $linha = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$linha) {
        return null;
    }

    $armazenada = (string) $linha['senha'];
    $info = password_get_info($armazenada);
    if ($info['algo'] !== null) {
        $valido = password_verify($senha, $armazenada);
    } else {
        $valido = hash_equals(strtolower($armazenada), md5($senha));
    }

    if (!$valido) {
        return null;
    }

    unset($linha['senha']);
    return $linha;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    }
    if ($status === 2) {
        return 'Em atendimento';
    }
    return 'Resolvido';
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
    if ($prioridade === 4) {
        return 'CRITICO - SLA estourado';
    }
    return 'Alto - atrasado';
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
    return $t ? (string) $t['nome'] : '-';
}

/**
 * Consulta interna de chamados com JOIN do técnico e filtros parametrizados.
 */
function consultarChamados(mysqli $db, string $busca = '', ?int $apenasUsuarioId = null): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    $condicoes = [];
    $tipos = '';
    $params = [];

    if ($apenasUsuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $apenasUsuarioId;
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $chamados = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Lista os chamados aplicando a regra de visibilidade do usuário autenticado.
 */
function listarChamadosVisiveis(mysqli $db, int $usuarioId, string $papel, string $busca = ''): array
{
    $filtroUsuario = ($papel === 'tecnico') ? null : $usuarioId;
    return consultarChamados($db, $busca, $filtroUsuario);
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
    $chamado = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS qtd FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    $qtd = $row ? (int) $row['qtd'] : 0;
    if ($qtd === 0) {
        return 0.0;
    }
    return ((int) $row['soma']) / $qtd;
}

/**
 * Escreve o CSV de chamados diretamente no stream de saída.
 */
function escreverCsvChamados(mysqli $db, ?int $apenasUsuarioId = null): void
{
    $sql = "SELECT c.id, c.titulo, c.status, COALESCE(u.nome, '-') AS tecnico_nome, c.criado_em
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id";
    if ($apenasUsuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    if ($apenasUsuarioId !== null) {
        $stmt->bind_param('i', $apenasUsuarioId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        $stmt->close();
        return;
    }

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $stmt->close();
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
    }

    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '');
    }
    fclose($fp);
    $stmt->close();
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsvChamados($db, null);
}

/**
 * Exporta os chamados visíveis ao usuário autenticado para CSV.
 */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    $filtroUsuario = ($papel === 'tecnico') ? null : $usuarioId;
    escreverCsvChamados($db, $filtroUsuario);
}
