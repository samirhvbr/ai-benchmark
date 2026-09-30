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
 *
 * Compatível com os hashes MD5 legados (coluna CHAR(32)) e já capaz de
 * validar hashes modernos (password_hash) caso a coluna seja ampliada.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $armazenado = (string) $linha['senha'];
    if (strlen($armazenado) === 32) {
        // Comparação de tempo constante para o hash legado.
        $confere = hash_equals($armazenado, md5($senha));
    } else {
        $confere = password_verify($senha, $armazenado);
    }
    if (!$confere) {
        return null;
    }

    return [
        'id'    => (int) $linha['id'],
        'nome'  => $linha['nome'],
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
    $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
    if ($stmt === false) {
        return '-';
    }
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    // JOIN evita o problema de N+1 consultas para buscar o técnico de cada linha.
    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c '
         . 'LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] !== null ? $c['tecnico_nome'] : '-';
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
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    return ($row && $row['media'] !== null) ? (float) $row['media'] : 0.0;
}

/**
 * Neutraliza fórmulas em células CSV (CSV/formula injection).
 */
function csvCelula($valor): string
{
    $texto = (string) $valor;
    if ($texto !== '' && preg_match('/^[=+\-@\t\r]/', $texto) === 1) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Mantida por compatibilidade: a rotina noturna externa continua recebendo
 * a exportação completa (equivale ao perfil "técnico").
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null, 'tecnico');
}

/**
 * Exporta os chamados visíveis para um usuário.
 * Técnicos enxergam todos; clientes enxergam apenas os próprios chamados.
 */
function exportarCsvVisivel(mysqli $db, ?int $usuarioId, string $papel): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, u.nome AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $filtrar = ($papel !== 'tecnico' && $usuarioId !== null);
    if ($filtrar) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    if ($filtrar) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    // Escreve direto na saída: evita arquivo compartilhado em disco e corrida.
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    // Cabeçalho gravado literalmente para garantir o texto exato do contrato
    // (o fputcsv passou a entrecolchear campos com espaço em versões recentes).
    fwrite($out, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        $tecnico = $c['tecnico_nome'] !== null ? $c['tecnico_nome'] : '-';
        fputcsv($out, [
            $c['id'],
            csvCelula($c['titulo']),
            formatarStatus((int) $c['status']),
            csvCelula($tecnico),
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($out);
}
