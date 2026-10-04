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
    $stmt->close();
    if (!$linha) {
        return null;
    }

    // Compatibilidade com contas antigas; requer senha VARCHAR(255).
    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $compativelBcrypt = strlen($senha) <= 72 && strpos($senha, "\0") === false;
    if (!$legado && password_get_info($hash)['algoName'] === 'bcrypt' && !$compativelBcrypt) {
        return null;
    }
    $valida = $legado
        ? hash_equals(strtolower($hash), md5($senha))
        : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    // Bcrypt não representa NUL nem distingue bytes após o 72º. Essas
    // credenciais legadas exigem troca de senha antes da migração automática.
    $podeRehash = PASSWORD_DEFAULT !== PASSWORD_BCRYPT || $compativelBcrypt;
    if ($podeRehash && ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT))) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        // A condição adicional evita sobrescrever uma troca concorrente de senha.
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        $stmt->bind_param('sis', $novoHash, $linha['id'], $hash);
        $stmt->execute();
        $stmt->close();
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
 * Busca o nome de um técnico pelo id; mantida para consumidores existentes.
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
 * Escopo da sessão, aplicado em todas as leituras de chamados.
 * Consumidores internos sem identidade de sessão mantêm o acesso global.
 * O index.php autentica antes de chamar estas funções; outros chamadores
 * continuam responsáveis por autenticar e fornecer a sessão de seus usuários.
 * O único valor interpolado é um identificador inteiro validado.
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
    if ($uid === false) {
        return '1 = 0';
    }
    if (($sessao['papel'] ?? null) === 'tecnico') {
        return '1 = 1';
    }
    if (($sessao['papel'] ?? null) === 'cliente') {
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
        // Preserva os curingas LIKE aceitos pela busca existente.
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // query() devolvia os números como strings na conexão padrão.
        foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
            if ($c[$campo] !== null) {
                $c[$campo] = (string) $c[$campo];
            }
        }
        $chamados[] = $c;
    }
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
    $res = $db->query('SELECT SUM(c.minutos_resposta) AS soma, COUNT(c.minutos_resposta) AS qtd
                      FROM chamados c WHERE '
        . condicaoVisibilidadeChamados());
    $row = $res->fetch_assoc();
    return (int) $row['qtd'] > 0 ? (float) $row['soma'] / (int) $row['qtd'] : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.id, c.titulo, c.status, c.criado_em,
                             COALESCE(t.nome, '-') AS tecnico_nome
                      FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                      WHERE " . condicaoVisibilidadeChamados() . ' ORDER BY c.id', MYSQLI_USE_RESULT);
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
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '');
        }
    } finally {
        $res->free();
        fclose($fp);
    }
}
