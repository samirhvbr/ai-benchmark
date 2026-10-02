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
    $res = consultarPainel($db, 'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?', 's', [$usuario]);
    $linha = $res->fetch_assoc();
    $res->free();
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    $algoritmo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    // O fallback bcrypt limita a senha a 72 bytes e rejeita NUL. Nestes casos,
    // preservar o login legado até um runtime com Argon2id estar disponível.
    $podeMigrar = $algoritmo !== PASSWORD_BCRYPT || (strlen($senha) <= 72 && strpos($senha, "\0") === false);
    if ($podeMigrar && ($legado || password_needs_rehash($hash, $algoritmo))) {
        $novoHash = password_hash($senha, $algoritmo);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
        if ($stmt === false) {
            throw new RuntimeException('Falha ao atualizar a senha.');
        }
        try {
            // A comparação evita sobrescrever uma senha alterada em outro login.
            if (!$stmt->bind_param('sis', $novoHash, $linha['id'], $hash) || !$stmt->execute()) {
                throw new RuntimeException('Falha ao atualizar a senha.');
            }
        } finally {
            $stmt->close();
        }
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/** Executa uma leitura parametrizada e libera o statement antes de retornar. */
function consultarPainel(mysqli $db, string $sql, string $tipos = '', array $parametros = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao consultar o banco.');
    }
    try {
        if ($tipos !== '' && !$stmt->bind_param($tipos, ...$parametros)) {
            throw new RuntimeException('Falha ao vincular os parametros.');
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Falha ao consultar o banco.');
        }
        $res = $stmt->get_result();
        if ($res === false) {
            throw new RuntimeException('Falha ao obter o resultado.');
        }
        return $res;
    } finally {
        $stmt->close();
    }
}

/** Escopo comum a listagem, detalhe, indicador e exportação (alias c). */
function escopoChamados(): array
{
    // Relatórios internos CLI sem identidade web continuam com acesso global.
    if (PHP_SAPI === 'cli' && !isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return ['1 = 1', '', []];
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $papel = $_SESSION['papel'] ?? '';
    if ($uid > 0 && $papel === 'tecnico') {
        return ['1 = 1', '', []];
    }
    if ($uid > 0 && $papel === 'cliente') {
        return ['c.usuario_id = ?', 'i', [$uid]];
    }
    return ['1 = 0', '', []];
}

/** Mantém os tipos das linhas que antes vinham pelo protocolo query() textual. */
function normalizarChamado(array $chamado): array
{
    foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
        if (isset($chamado[$campo])) {
            $chamado[$campo] = (string) $chamado[$campo];
        }
    }
    return $chamado;
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
    [$where, $tipos, $parametros] = escopoChamados();
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . $where;
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = consultarPainel($db, $sql, $tipos, $parametros);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarChamado($c);
    }
    $res->free();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$where, $tipos, $parametros] = escopoChamados();
    $parametros[] = $id;
    $res = consultarPainel($db, 'SELECT c.* FROM chamados c WHERE ' . $where . ' AND c.id = ?', $tipos . 'i', $parametros);
    $linha = $res->fetch_assoc();
    $chamado = $linha ? normalizarChamado($linha) : null;
    $res->free();
    return $chamado;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$where, $tipos, $parametros] = escopoChamados();
    $res = consultarPainel($db, 'SELECT SUM(c.minutos_resposta) AS soma, COUNT(c.minutos_resposta) AS qtd FROM chamados c WHERE ' . $where, $tipos, $parametros);
    $row = $res->fetch_assoc();
    // Dividir em PHP preserva a precisão do retorno float legado (AVG de INT
    // no banco pode arredondar para DECIMAL com poucas casas).
    $media = (int) $row['qtd'] > 0 ? (float) $row['soma'] / (int) $row['qtd'] : 0.0;
    $res->free();
    return $media;
}

/** Neutraliza células textuais que planilhas podem interpretar como fórmulas. */
function textoCsvSeguro(string $texto): string
{
    if ($texto !== '-' && preg_match('/\A(?:[\t\r\n]|[\x00-\x20]*[=+@-])/', $texto) === 1) {
        return "'" . $texto;
    }
    return $texto;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    [$where, $tipos, $parametros] = escopoChamados();
    $res = consultarPainel($db, "SELECT c.id, c.titulo, c.status, c.criado_em,
                              COALESCE(t.nome, '-') AS tecnico_nome
                              FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
                              WHERE " . $where . ' ORDER BY c.id', $tipos, $parametros);
    $fp = tmpfile();
    if ($fp === false) {
        $res->free();
        throw new RuntimeException('Falha ao criar a exportacao.');
    }
    try {
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao escrever o CSV.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                textoCsvSeguro($c['titulo']),
                formatarStatus((int) $c['status']),
                textoCsvSeguro($c['tecnico_nome']),
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever o CSV.');
            }
        }
        if (!rewind($fp)) {
            throw new RuntimeException('Falha ao ler o CSV.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        if (fpassthru($fp) === false) {
            throw new RuntimeException('Falha ao enviar o CSV.');
        }
    } finally {
        $res->free();
        fclose($fp);
    }
}
