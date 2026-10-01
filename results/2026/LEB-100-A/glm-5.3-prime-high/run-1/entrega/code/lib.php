<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Os parâmetros opcionais $usuarioId/$papel, quando informados, aplicam a
 * regra de visibilidade (cliente vê só os próprios chamados; técnico vê
 * todos). Chamadas legadas, sem esses parâmetros, continuam vendo tudo.
 */

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 * ATENÇÃO: o armadazenamento de senhas é MD5 (legado, ver RELATORIO.md);
 * mantido por compatibilidade com a base existente.
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
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Com $usuarioId informado e $papel diferente de 'tecnico', devolve apenas
 * os chamados abertos por esse usuário (regra de visibilidade do manifest).
 */
function listarChamados(mysqli $db, string $busca = '', ?int $usuarioId = null, ?string $papel = null): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $where = [];
    $params = [];
    $types = '';

    if ($busca !== '') {
        $where[] = 'c.titulo LIKE ?';
        $params[] = '%' . $busca . '%';
        $types .= 's';
    }
    if ($usuarioId !== null && $papel !== 'tecnico') {
        $where[] = 'c.usuario_id = ?';
        $params[] = $usuarioId;
        $types .= 'i';
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
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 *
 * Com $usuarioId informado e $papel diferente de 'tecnico', devolve o
 * chamado apenas se ele pertencer a esse usuário (null caso contrário).
 */
function verChamado(mysqli $db, int $id, ?int $usuarioId = null, ?string $papel = null): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ?';
    $params = [$id];
    $types = 'i';
    if ($usuarioId !== null && $papel !== 'tecnico') {
        $sql .= ' AND usuario_id = ?';
        $params[] = $usuarioId;
        $types .= 'i';
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Sem chamados respondidos, devolve 0.0 (antes: DivisionByZeroError).
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
    return $qtd > 0 ? $soma / $qtd : 0.0;
}

/**
 * Prefixa valores que planilhas interpretariam como fórmula (= + - @),
 * evitando injeção de fórmula ao abrir o CSV no Excel/LibreOffice.
 */
function campoCsv(string $valor): string
{
    if ($valor !== '' && in_array($valor[0], ['=', '+', '-', '@'], true)) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Sem $usuarioId (chamada legada da rotina noturna), exporta todos os
 * chamados. Com $usuarioId e $papel de cliente, exporta apenas os dele.
 * O CSV é montado direto na saída (php://output), sem arquivo temporário.
 */
function exportarCsv(mysqli $db, ?int $usuarioId = null, ?string $papel = null): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $params = [];
    $types = '';
    if ($usuarioId !== null && $papel !== 'tecnico') {
        $sql .= ' WHERE c.usuario_id = ?';
        $params[] = $usuarioId;
        $types = 'i';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($c = $res->fetch_assoc()) {
        $tecnico = $c['tecnico_id'] !== null ? campoCsv($c['tecnico_nome']) : '-';
        fputcsv($fp, [
            $c['id'],
            campoCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
