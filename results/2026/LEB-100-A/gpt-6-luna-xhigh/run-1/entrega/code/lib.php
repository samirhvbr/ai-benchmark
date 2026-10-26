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
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return painelBuscarChamados($db, $busca, null);
}

/** Lista somente os chamados visíveis ao usuário da sessão web. */
function painelListarChamadosVisiveis(mysqli $db, int $usuarioId, bool $tecnico, string $busca = ''): array
{
    return painelBuscarChamados($db, $busca, $tecnico ? null : $usuarioId);
}

/** Consulta compartilhada com parâmetros SQL e junção do técnico. */
function painelBuscarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($usuarioId !== null && $busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $termo);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    } elseif ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
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
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc() ?: null;
}

/** Carrega um chamado apenas se o usuário da sessão puder vê-lo. */
function painelVerChamadoVisivel(mysqli $db, int $id, int $usuarioId, bool $tecnico): ?array
{
    if ($tecnico) {
        return verChamado($db, $id);
    }
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
    $stmt->bind_param('ii', $id, $usuarioId);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados');
    $row = $res->fetch_assoc();
    return $row['media'] === null ? 0.0 : (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    painelEmitirCsv($db, null);
}

/** Exporta os chamados visíveis ao usuário da sessão web. */
function painelExportarCsvVisivel(mysqli $db, int $usuarioId, bool $tecnico): void
{
    painelEmitirCsv($db, $tecnico ? null : $usuarioId);
}

/** Emite CSV sem criar arquivo persistente ou compartilhado no servidor. */
function painelEmitirCsv(mysqli $db, ?int $usuarioId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, COALESCE(t.nome, \'-\') AS tecnico_nome, c.criado_em '
         . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            painelProtegerFormulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            painelProtegerFormulaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}

/** Neutraliza fórmulas em campos textuais ao abrir o CSV em planilhas. */
function painelProtegerFormulaCsv(string $valor): string
{
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $valor) === 1
        ? "'" . $valor
        : $valor;
}
