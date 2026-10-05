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
    switch ($status) {
        case 1:
            return 'Aberto';
        case 2:
            return 'Em atendimento';
        case 3:
            return 'Resolvido';
        default:
            return 'Desconhecido';
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

    if ($prioridade >= 3) {
        if ($minutos > 30) {
            if ($prioridade == 4) {
                return 'CRITICO - SLA estourado';
            }
            return 'Alto - atrasado';
        }

        if ($prioridade == 4) {
            return 'CRITICO - dentro do SLA';
        }
        return 'Alto - dentro do SLA';
    }

    return 'Normal';
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
    $res = $stmt->get_result();
    $t = $res ? $res->fetch_assoc() : null;
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $chamados = [];

    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt = $db->prepare('SELECT * FROM chamados WHERE titulo LIKE ? ORDER BY criado_em DESC');
        $stmt->bind_param('s', $termo);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados ORDER BY criado_em DESC');
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($res === false) {
        return $chamados;
    }

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
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? ($res->fetch_assoc() ?: null) : null;
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
    return $qtd > 0 ? $soma / $qtd : 0.0;
}

/**
 * Escreve uma linha de CSV no handle fornecido.
 */
function _escreverLinhaCsv($fp, array $c, mysqli $db): void
{
    $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
    fputcsv($fp, [
        $c['id'],
        $c['titulo'],
        formatarStatus((int) $c['status']),
        $tecnico,
        $c['criado_em'],
    ]);
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

    $res = $db->query('SELECT * FROM chamados ORDER BY id');
    if ($res === false) {
        fclose($fp);
        return;
    }

    while ($c = $res->fetch_assoc()) {
        _escreverLinhaCsv($fp, $c, $db);
    }
    fclose($fp);
}

/**
 * Exporta chamados visíveis para um usuário específico.
 * Técnicos veem tudo; clientes veem apenas seus próprios chamados.
 */
function exportarCsvDoUsuario(mysqli $db, int $usuarioId, string $papel): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }

    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    if ($papel === 'cliente') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE usuario_id = ? ORDER BY id');
        $stmt->bind_param('i', $usuarioId);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados ORDER BY id');
    }

    $stmt->execute();
    $res = $stmt->get_result();
    if ($res === false) {
        fclose($fp);
        return;
    }

    while ($c = $res->fetch_assoc()) {
        _escreverLinhaCsv($fp, $c, $db);
    }
    fclose($fp);
}
