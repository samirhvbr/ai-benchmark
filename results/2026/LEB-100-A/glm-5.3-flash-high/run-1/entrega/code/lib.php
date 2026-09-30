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
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if ($linha === null) {
        return null;
    }

    $armazenado = $linha['senha'];
    if (strlen($armazenado) === 32) {
        // hash md5 legado (sem salt)
        $ok = hash_equals($armazenado, md5($senha));
    } else {
        $ok = password_verify($senha, $armazenado);
    }
    if (!$ok) {
        return null;
    }

    // Upgrade transparente: md5 -> password_hash, sem quebrar logins existentes.
    if (strlen($armazenado) === 32) {
        if ($up = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')) {
            $novo = password_hash($senha, PASSWORD_DEFAULT);
            $up->bind_param('si', $novo, $linha['id']);
            $up->execute();
        }
    }

    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
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
 * $somenteUsuarioId restringe o resultado aos chamados do usuário informado
 * (regra de visibilidade do painel); null mantém o comportamento de sempre:
 * listar todos (scripts internos/CLI). Chamadas existentes continuam válidas.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = '', ?int $somenteUsuarioId = null): array
{
    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $params = [];
    if ($busca !== '') {
        $sql .= ' WHERE c.titulo LIKE ?';
        $tipos .= 's';
        $params[] = '%' . $busca . '%';
    }
    if ($somenteUsuarioId !== null) {
        $sql .= ($busca !== '' ? ' AND' : ' WHERE') . ' c.usuario_id = ?';
        $tipos .= 'i';
        $params[] = $somenteUsuarioId;
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res === false) {
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $c['tecnico_nome'] = $c['tecnico_nome'] ?? '-';
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
    $res = $stmt->get_result();
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
    if ($qtd === 0) {
        return 0.0;
    }
    return $soma / $qtd;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * $somenteUsuarioId restringe a exportação aos chamados do usuário informado
 * (regra de visibilidade do painel); null mantém o comportamento de sempre:
 * exportar tudo (rotina noturna e relatórios internos). Chamadas existentes
 * continuam válidas.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db, ?int $somenteUsuarioId = null): void
{
    $sql = 'SELECT c.*, u.nome AS tecnico_nome FROM chamados c'
         . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    if ($somenteUsuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    if ($somenteUsuarioId !== null) {
        $stmt->bind_param('i', $somenteUsuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res === false) {
        return;
    }

    // Grava direto na saída: elimina o arquivo temporário compartilhado
    // (condição de corrida entre requests simultâneos).
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    // Cabeçalho escrito manualmente: o contrato exige exatamente
    // "ID,Titulo,Status,Tecnico,Aberto em", e o fputcsv citaria o campo
    // com espaço ("Aberto em").
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");

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
