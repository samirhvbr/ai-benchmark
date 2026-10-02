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
 * Compatível com hashes legados (md5 hex) e modernos (bcrypt/argon2). No
 * caminho legado, ao validar, promove a senha para password_hash() na
 * própria transação — desde que a coluna `senha` tenha largura suficiente
 * (ver schema.sql).
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

    $hash = (string) $linha['senha'];
    $ok = false;
    if ($hash !== '' && (
        str_starts_with($hash, '$2y$') ||
        str_starts_with($hash, '$2a$') ||
        str_starts_with($hash, '$argon2')
    )) {
        $ok = password_verify($senha, $hash);
    } else {
        // Compatibilidade com hash legado md5 — verifica em tempo constante
        // e, se válido, promove para password_hash() na hora.
        $ok = hash_equals($hash, md5($senha));
        if ($ok) {
            $novo = password_hash($senha, PASSWORD_DEFAULT);
            $upd = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($upd !== false) {
                $upd->bind_param('si', $novo, $linha['id']);
                @$upd->execute();
                $upd->close();
            }
        }
    }
    if (!$ok) {
        return null;
    }
    unset($linha['senha']);
    return $linha;
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 * Contrato: 1→"Aberto", 2→"Em atendimento", 3→"Resolvido".
 */
function formatarStatus(int $status): string
{
    switch ($status) {
        case 1: return 'Aberto';
        case 2: return 'Em atendimento';
        case 3: return 'Resolvido';
        default: return 'Desconhecido';
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
            return $prioridade == 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
        }
        return $prioridade == 4 ? 'CRITICO - dentro do SLA' : 'Alto - dentro do SLA';
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
 *
 * NOTA: a filtragem por papel/usuário é responsabilidade do caller — esta
 * função é compartilhada com a rotina noturna e o relatório gerencial, que
 * precisam ver todos os chamados.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    if ($busca !== '') {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE titulo LIKE ? ORDER BY criado_em DESC');
        if ($stmt === false) {
            return [];
        }
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res === false) {
            return [];
        }
    } else {
        $res = $db->query('SELECT * FROM chamados ORDER BY criado_em DESC');
        if ($res === false) {
            return [];
        }
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $tid = $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null;
        $c['tecnico_nome'] = tecnicoNome($db, $tid);
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
    $r = $stmt->get_result();
    return $r ? ($r->fetch_assoc() ?: null) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT minutos_resposta FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
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

    $res = $db->query('SELECT * FROM chamados ORDER BY id');
    if ($res === false) {
        fclose($fp);
        return;
    }
    while ($c = $res->fetch_assoc()) {
        $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
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