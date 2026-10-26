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
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    if (preg_match('/\A[a-f0-9]{32}\z/i', $hashArmazenado)) {
        if (!hash_equals(strtolower($hashArmazenado), md5($senha))) {
            return null;
        }
        atualizarHashLegado($db, (int) $linha['id'], $hashArmazenado, $senha);
    } elseif (!password_verify($senha, $hashArmazenado)) {
        return null;
    }

    unset($linha['senha']);
    return $linha;
}

/**
 * Atualiza hashes MD5 após um login válido quando a coluna já comporta hashes modernos.
 */
function atualizarHashLegado(mysqli $db, int $id, string $hashLegado, string $senha): void
{
    try {
        $coluna = $db->query("SHOW COLUMNS FROM usuarios LIKE 'senha'");
        $definicao = $coluna ? $coluna->fetch_assoc() : null;
        $tipo = $definicao['Type'] ?? '';
        if (!preg_match('/\A(?:var)?char\((\d+)\)/i', $tipo, $matches)) {
            return;
        }

        $limite = (int) $matches[1];
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        if ($novoHash === false || strlen($novoHash) > $limite) {
            return;
        }

        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('sis', $novoHash, $id, $hashLegado);
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // A migração é oportunista: uma falha ao atualizar não deve negar o login válido.
    }
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
    return consultarChamados($db, $busca, null);
}

/** Lista os chamados visíveis ao usuário da sessão, preservando a busca por título. */
function listarChamadosVisiveis(mysqli $db, int $usuarioId, string $papel, string $busca = ''): array
{
    $donoId = $papel === 'tecnico' ? null : $usuarioId;
    return consultarChamados($db, $busca, $donoId);
}

/** Consulta compartilhada; donoId nulo mantém o comportamento amplo das funções públicas. */
function consultarChamados(mysqli $db, string $busca, ?int $donoId): array
{
    $sql = 'SELECT c.*, t.nome AS tecnico_nome FROM chamados c '
         . 'LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    if ($donoId !== null && $busca !== '') {
        $stmt = $db->prepare($sql);
        $termo = '%' . $busca . '%';
        $stmt->bind_param('is', $donoId, $termo);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($donoId !== null) {
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $donoId);
        $stmt->execute();
        $res = $stmt->get_result();
    } elseif ($busca !== '') {
        $stmt = $db->prepare($sql);
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql);
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        if ($c['tecnico_nome'] === null) {
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
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id);
    return $res->fetch_assoc() ?: null;
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
    return $qtd === 0 ? 0.0 : $soma / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsvChamados($db, null);
}

/** Exporta somente os chamados que o usuário atual pode consultar. */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    $donoId = $papel === 'tecnico' ? null : $usuarioId;
    escreverCsvChamados($db, $donoId);
}

/** Escreve o CSV diretamente na resposta, sem arquivo temporário compartilhado. */
function escreverCsvChamados(mysqli $db, ?int $donoId): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, t.nome AS tecnico_nome '
         . 'FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($donoId !== null) {
        $sql .= ' WHERE c.usuario_id = ? ORDER BY c.id';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $donoId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $db->query($sql . ' ORDER BY c.id');
    }
    if ($res === false) {
        return;
    }

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'] ?? '-',
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
