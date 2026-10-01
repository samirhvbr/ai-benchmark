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

    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    // Bcrypt não representa senhas além de 72 bytes ou com bytes nulos.
    // Nesses casos, manter o hash legado até a redefinição da senha.
    $podeRehash = PASSWORD_DEFAULT !== PASSWORD_BCRYPT
        || (strlen($senha) <= 72 && strpos($senha, "\0") === false);
    if ($podeRehash && ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT))) {
        // Não truncar hashes nem impedir o login antes da migração de CHAR(32).
        $colunas = $db->query("SHOW COLUMNS FROM usuarios LIKE 'senha'");
        $coluna = $colunas->fetch_assoc();
        $colunas->free();
        if ($coluna && preg_match('/\A(?:var)?char\((\d+)\)\z/i', $coluna['Type'], $tamanho)
            && (int) $tamanho[1] >= 255) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
            $stmt->bind_param('sis', $novoHash, $linha['id'], $hash);
            $stmt->execute();
            $stmt->close();
        }
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Escopo interno para consultas com o alias c. A sessão é criada pelo index.
 * Rotinas CLI sem identidade continuam tendo acesso administrativo ao conjunto.
 * Em HTTP, identidade ausente/inválida não concede acesso a nenhum chamado.
 */
function filtroVisibilidadeChamados(): string
{
    if (PHP_SAPI === 'cli' && !isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return '1 = 1';
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($uid === false) {
        return '1 = 0';
    }
    if (($_SESSION['papel'] ?? '') === 'tecnico') {
        return '1 = 1';
    }
    if (($_SESSION['papel'] ?? '') === 'cliente') {
        return 'c.usuario_id = ' . (int) $uid;
    }
    return '1 = 0';
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
 * Busca o nome de um técnico pelo id. Mantido para consumidores legados.
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
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . filtroVisibilidadeChamados();
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
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
        . ' AND ' . filtroVisibilidadeChamados());
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(c.minutos_resposta) AS soma, COUNT(c.minutos_resposta) AS qtd
                      FROM chamados c WHERE ' . filtroVisibilidadeChamados());
    $linha = $res->fetch_assoc();
    // Dividir em PHP mantém a precisão do cálculo legado (AVG pode arredondar).
    return (int) $linha['qtd'] === 0 ? 0.0 : (float) $linha['soma'] / (int) $linha['qtd'];
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
                      FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                      WHERE " . filtroVisibilidadeChamados() . ' ORDER BY c.id', MYSQLI_USE_RESULT);
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
