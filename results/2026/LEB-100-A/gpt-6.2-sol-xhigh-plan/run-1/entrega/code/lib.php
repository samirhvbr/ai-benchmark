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

    $hash = $linha['senha'];
    $senhaForaDoLimiteBcrypt = strlen($senha) > 72 || strpos($senha, "\0") !== false;

    if (preg_match('/\A[0-9a-f]{32}\z/i', $hash)) {
        if (!hash_equals(strtolower($hash), md5($senha))) {
            return null;
        }

        // Mantém válidas as senhas legadas que o bcrypt truncaria ou recusaria.
        $novoHash = $senhaForaDoLimiteBcrypt
            ? 'sha256:' . password_hash(hash('sha256', $senha), PASSWORD_DEFAULT)
            : password_hash($senha, PASSWORD_DEFAULT);
        $atualizar = $db->prepare(
            'UPDATE usuarios SET senha = ? WHERE id = ? AND CAST(senha AS BINARY) = CAST(? AS BINARY)'
        );
        $atualizar->bind_param('sis', $novoHash, $linha['id'], $hash);
        $atualizar->execute();
    } elseif (strncmp($hash, 'sha256:', 7) === 0) {
        if (!password_verify(hash('sha256', $senha), substr($hash, 7))) {
            return null;
        }
    } elseif (($senhaForaDoLimiteBcrypt && password_get_info($hash)['algoName'] === 'bcrypt')
        || !password_verify($senha, $hash)) {
        return null;
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
    if ($minutos === null) {
        return 'Aguardando 1a resposta';
    }
    if ($prioridade < 3) {
        return 'Normal';
    }
    if ($minutos <= 30) {
        return 'Alto - dentro do SLA';
    }
    return $prioridade === 4 ? 'CRITICO - SLA estourado' : 'Alto - atrasado';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosVisiveis($db, $busca, null);
}

/** @internal O ID do dono é obrigatório para clientes. */
function listarChamadosVisiveis(mysqli $db, string $busca, ?int $donoId): array
{
    $res = consultarChamados($db, $donoId, $busca, false);
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/** @internal Consulta compartilhada pela lista e pelo CSV. */
function consultarChamados(mysqli $db, ?int $donoId, string $busca, bool $csv): mysqli_result
{
    $colunas = $csv ? 'c.id, c.titulo, c.status, c.criado_em' : 'c.*';
    $sql = "SELECT $colunas, COALESCE(t.nome, '-') AS tecnico_nome"
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $filtros = [];
    if ($donoId !== null) {
        $filtros[] = 'c.usuario_id = ' . $donoId;
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= $csv ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC';

    if ($busca === '') {
        return $db->query($sql, $csv ? MYSQLI_USE_RESULT : MYSQLI_STORE_RESULT);
    }
    $padrao = '%' . $busca . '%';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('s', $padrao);
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoVisivel($db, $id, null);
}

/** @internal O ID do dono é obrigatório para clientes. */
function verChamadoVisivel(mysqli $db, int $id, ?int $donoId): ?array
{
    $sql = 'SELECT * FROM chamados WHERE id = ' . $id;
    if ($donoId !== null) {
        $sql .= ' AND usuario_id = ' . $donoId;
    }
    $res = $db->query($sql);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return mediaRespostaVisivel($db, null);
}

/** @internal O ID do dono é obrigatório para clientes. */
function mediaRespostaVisivel(mysqli $db, ?int $donoId): float
{
    // A soma e a contagem usam precisão inteira antes da divisão em PHP.
    $sql = 'SELECT SUM(minutos_resposta) AS total, COUNT(minutos_resposta) AS qtd FROM chamados';
    if ($donoId !== null) {
        $sql .= ' WHERE usuario_id = ' . $donoId;
    }
    $linha = $db->query($sql)->fetch_assoc();
    return (int) $linha['qtd'] === 0 ? 0.0 : $linha['total'] / $linha['qtd'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvVisivel($db, null);
}

/** @internal O ID do dono é obrigatório para clientes. */
function exportarCsvVisivel(mysqli $db, ?int $donoId): void
{
    $res = consultarChamados($db, $donoId, '', true);
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        throw new RuntimeException('Falha ao abrir a saída do CSV.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    try {
        fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
        while ($c = $res->fetch_assoc()) {
            fputcsv($fp, [
                $c['id'],
                textoParaCsv($c['titulo']),
                formatarStatus((int) $c['status']),
                textoParaCsv($c['tecnico_nome']),
                $c['criado_em'],
            ], ',', '"', '');
        }
    } finally {
        $res->free();
        fclose($fp);
    }
}

/** @internal Preserva textos comuns e força texto nos casos interpretáveis como fórmula. */
function textoParaCsv(string $texto): string
{
    $inicioDeFormula = '/\A(?:[\t\r\n]|[\p{Z}\x00-\x20\x{FEFF}]*[=+\-@＝＋－＠])/u';
    $interpretavel = preg_match($inicioDeFormula, $texto);
    if ($interpretavel === false) {
        // Chamadores mysqli também podem usar um charset diferente de UTF-8.
        $interpretavel = preg_match('/\A(?:[\t\r\n]|[\x00-\x20]*[=+\-@])/', $texto);
    }
    return $interpretavel === 1 ? "'" . $texto : $texto;
}
