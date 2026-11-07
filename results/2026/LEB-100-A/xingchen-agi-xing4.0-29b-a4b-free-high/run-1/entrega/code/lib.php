<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

/**
 * Autenta um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }
    // password_verify é do core PHP; não introduz dependência externa.
    if (!password_verify($senha, (string) $linha['senha'])) {
        return null;
    }
    return [
        'id' => (int) $linha['id'],
        'nome' => $linha['nome'],
        'papel' => $linha['papel'],
    ];
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
    $stmt = $db->prepare('SELECTnome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Visibilidade (regra de negócio do manifesto): quando $papel === 'cliente' e
 * $userId fornecido, apenas os chamados de $userId são retornados. Técnicos
 * veem tudo (contrato). Chamadas quebras do escopo de um cliente são descartadas
 * aqui, não no nível da função com assinatura pública herdada — isto preserva
 * o contrato para as rotinas ISP que chamam `listarChamados` com nenhuma filiação
 * de papel.
 */
function listarChamados(mysqli $db, string $busca = '', ?int $userId = null, ?string $papel = null): array
{
    $whereSql = '';
    $params = [];
    $types = '';
    if ($busca !== '') {
        $whereSql .= ' titulo LIKE ?';
        $params[] = '%' . $busca . '%';   // valor injetado nunca mais vira SQL
        $types .= 's';
    }
    if ($papel === 'cliente' && $userId !== null) {
        $whereSql .= ' AND usuario_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }
    $sql = 'SELECT * FROM chamados' . $whereSql . ' ORDER BY criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    // Batch lookup dos técnicos em única query (evita N+1 no loop).
    $techMap = [];
    while ($c = $res->fetch_assoc()) {
        $tid = $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null;
        if ($tid !== null) {
            $techIds[$tid] = true;
        }
    }
    $idsList = array_keys($techIds);
    if ($idsList) {
        $placeholders = implode(', ', array_fill(0, count($idsList), '?'));
        $sel = $db->prepare('SELECT id,nome FROM usuarios WHERE id IN (' . $placeholders . ')');
        $sel->bind_param('i' . str_repeat('i', count($idsList)), ...$idsList);
        $sel->execute();
        while ($r = $sel->get_result()->fetch_assoc()) {
            $techMap[(int) $r['id']] = $r['nome'];
        }
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $tid = $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null;
        $c['tecnico_nome'] = $tid !== null ? ($techMap[$tid] ?? '-') : '-';
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
    $row = $res->fetch_assoc();
    return $row ?: null;
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
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $caminho = EXPORT_DIR . '/chamados.csv';
    $fp = fopen($caminho, 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    // Batch lookup dos técnicos em única query (evita N+1 no loop).
    $techMap = [];
    $res = $db->query('SELECT id FROM chamados ORDER BY id');
    if ($res !== false) {
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $c) {
            $tid = $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null;
            if ($tid !== null) {
                $techIds[$tid] = true;
            }
        }
    }
    $idsList = array_keys($techIds);
    if ($idsList) {
        $placeholders = implode(', ', array_fill(0, count($idsList), '?'));
        $sel = $db->prepare('SELECT id,nome FROM usuarios WHERE id IN (' . $placeholders . ')');
        $sel->bind_param('i' . str_repeat('i', count($idsList)), ...$idsList);
        $sel->execute();
        while ($r = $sel->get_result()->fetch_assoc()) {
            $techMap[(int) $r['id']] = $r['nome'];
        }
    }

    while ($c = $res->fetch_assoc()) {
        $tid = $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null;
        $tecnico = $tid !== null ? ($techMap[$tid] ?? '-') : '-';
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $tecnico,
            $c['criado_em'],
        ]);
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
}
