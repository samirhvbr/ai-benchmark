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
        return null;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        return null;
    }
    $linha = $res->fetch_assoc();
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
        return '-';
    }
    $res = $stmt->get_result();
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
    $sql = 'SELECT c.*, u.nome AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ? ORDER BY c.criado_em DESC';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return [];
        }
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
    } else {
        $sql .= ' ORDER BY c.criado_em DESC';
        $res = $db->query($sql);
    }
    if ($res === false) {
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        if (!isset($c['tecnico_nome']) || $c['tecnico_nome'] === null) {
            $c['tecnico_nome'] = '-';
        }
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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        return null;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        return null;
    }
    $linha = $res->fetch_assoc();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query(
        'SELECT COALESCE(SUM(minutos_resposta), 0) AS soma, COUNT(minutos_resposta) AS qtd
         FROM chamados
         WHERE minutos_resposta IS NOT NULL'
    );
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    if (!$row) {
        return 0.0;
    }
    $qtd = (int) $row['qtd'];
    if ($qtd === 0) {
        return 0.0;
    }
    return ((float) $row['soma']) / $qtd;
}

/**
 * Impede que um campo aberto no Excel seja interpretado como fórmula.
 * O placeholder "-" (técnico não atribuído) é preservado.
 */
function celulaCsv(string $valor): string
{
    if ($valor === '' || $valor === '-') {
        return $valor;
    }
    $primeiro = $valor[0];
    if (
        $primeiro === '=' || $primeiro === '+' || $primeiro === '-'
        || $primeiro === '@' || $primeiro === "\t" || $primeiro === "\r"
    ) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Escreve o CSV na saída. $usuarioId restringe ao dono; null exporta todos.
 * Retorna false se a consulta falhar (nenhum byte de corpo é emitido).
 */
function escreverCsv(mysqli $db, ?int $usuarioId): bool
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, u.nome AS tecnico_nome
            FROM chamados c
            LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ? ORDER BY c.id';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $usuarioId);
        if (!$stmt->execute()) {
            return false;
        }
        $res = $stmt->get_result();
    } else {
        $sql .= ' ORDER BY c.id';
        $res = $db->query($sql);
    }
    if ($res === false) {
        return false;
    }

    header('Cache-Control: private, no-store');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        return false;
    }
    fwrite($out, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        $tecnico = (isset($c['tecnico_nome']) && $c['tecnico_nome'] !== null)
            ? (string) $c['tecnico_nome']
            : '-';
        $titulo = $c['titulo'] !== null ? (string) $c['titulo'] : '';
        $aberto = $c['criado_em'] !== null ? (string) $c['criado_em'] : '';
        fputcsv($out, [
            $c['id'],
            celulaCsv($titulo),
            formatarStatus((int) $c['status']),
            celulaCsv($tecnico),
            celulaCsv($aberto),
        ]);
    }
    fclose($out);
    return true;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null);
}
