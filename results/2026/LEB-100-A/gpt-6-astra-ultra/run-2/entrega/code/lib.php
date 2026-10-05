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
    $res = $db->query('SELECT nome FROM usuarios WHERE id = ' . $tecnicoId);
    $t = $res->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Predicado interno para consultas cujo alias de chamados é c.
 * Sem identidade de sessão, mantém o acesso dos scripts internos confiáveis.
 * O ponto de entrada web deve autenticar antes de chamar as funções abaixo.
 */
function condicaoVisibilidadeChamados(): string
{
    $sessao = $_SESSION ?? [];
    if (!is_array($sessao)) {
        return '1 = 0';
    }
    if (!array_key_exists('uid', $sessao) && !array_key_exists('papel', $sessao)) {
        return '1 = 1';
    }

    $uid = filter_var($sessao['uid'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $papel = $sessao['papel'] ?? null;
    if ($uid === false) {
        return '1 = 0';
    }
    if ($papel === 'tecnico') {
        return '1 = 1';
    }
    if ($papel === 'cliente') {
        return 'c.usuario_id = ' . (int) $uid;
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
            WHERE " . condicaoVisibilidadeChamados();
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
    $res = $stmt->get_result();

    $chamados = $res->fetch_all(MYSQLI_ASSOC);
    // O protocolo de prepared statements retorna inteiros nativos. Mantém os
    // valores textuais que a consulta mysqli original devolvia aos consumidores.
    foreach ($chamados as &$chamado) {
        foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
            if ($chamado[$campo] !== null) {
                $chamado[$campo] = (string) $chamado[$campo];
            }
        }
    }
    unset($chamado);
    $res->free();
    $stmt->close();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $res = $db->query('SELECT c.* FROM chamados c WHERE c.id = ' . $id
        . ' AND ' . condicaoVisibilidadeChamados());
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(c.minutos_resposta) AS soma,
        COUNT(c.minutos_resposta) AS qtd FROM chamados c WHERE '
        . condicaoVisibilidadeChamados());
    $row = $res->fetch_assoc();
    $qtd = (int) $row['qtd'];
    return $qtd === 0 ? 0.0 : (float) $row['soma'] / $qtd;
}

/** Evita interpretar campos textuais como fórmulas ao abrir o CSV em planilhas. */
function textoSeguroCsv(string $valor): string
{
    if ($valor === '-') {
        return $valor;
    }
    if (preg_match('/\A[\x00-\x20]*[=+@-]|\A[\t\r\n]/', $valor)) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
        WHERE " . condicaoVisibilidadeChamados() . ' ORDER BY c.id');
    if ($res === false) {
        return;
    }
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $res->free();
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    try {
        fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
        while ($c = $res->fetch_assoc()) {
            fputcsv($fp, [
                $c['id'],
                textoSeguroCsv($c['titulo']),
                formatarStatus((int) $c['status']),
                textoSeguroCsv($c['tecnico_nome']),
                $c['criado_em'],
            ], ',', '"', '');
        }
    } finally {
        fclose($fp);
        $res->free();
    }
}
