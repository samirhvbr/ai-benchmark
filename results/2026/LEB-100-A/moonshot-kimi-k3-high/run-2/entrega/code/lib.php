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
    $ehLegadoMd5 = strlen($hashArmazenado) === 32 && ctype_xdigit($hashArmazenado);
    if ($ehLegadoMd5) {
        // Formato legado: md5 sem sal. Mantido para não invalidar as senhas atuais.
        $ok = hash_equals($hashArmazenado, md5($senha));
    } else {
        $ok = password_verify($senha, $hashArmazenado);
    }
    if (!$ok) {
        return null;
    }

    // Migração transparente: no primeiro login bem-sucedido, regrava o hash
    // legado com algoritmo forte (password_hash). Nenhuma senha é perdida.
    if ($ehLegadoMd5) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $upd->bind_param('si', $novoHash, $linha['id']);
        $upd->execute();
    }

    unset($linha['senha']);
    return $linha;
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
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT chamados.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados LEFT JOIN usuarios t ON t.id = chamados.tecnico_id';
    if ($busca !== '') {
        $sql .= ' WHERE chamados.titulo LIKE ?';
    }
    $sql .= ' ORDER BY chamados.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
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
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    return (float) ($row['media'] ?? 0);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query(
        'SELECT chamados.id, chamados.titulo, chamados.status, chamados.criado_em,'
        . ' COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados LEFT JOIN usuarios t ON t.id = chamados.tecnico_id'
        . ' ORDER BY chamados.id'
    );
    if ($res === false) {
        http_response_code(500);
        return;
    }

    // Nome único por exportação: evita condição de corrida entre exportações
    // simultâneas e não deixa um arquivo previsível gravado no diretório.
    $caminho = tempnam(EXPORT_DIR, 'chamados_');
    $fp = $caminho !== false ? fopen($caminho, 'w') : false;
    if ($fp === false) {
        http_response_code(500);
        return;
    }
    // $escape explícito ('\\' = comportamento legado): silencia a depreciação
    // do PHP 8.4 mantendo a saída idêntica à de antes.
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em'], ',', '"', '\\');
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ], ',', '"', '\\');
    }
    fclose($fp);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    readfile($caminho);
    unlink($caminho);
}
