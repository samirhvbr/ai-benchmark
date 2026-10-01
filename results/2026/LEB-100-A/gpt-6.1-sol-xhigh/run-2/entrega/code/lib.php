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

    $armazenado = $linha['senha'];
    $legado = preg_match('/^[a-f0-9]{32}$/i', $armazenado) === 1;
    $preHash = strncmp($armazenado, 'sha256:', 7) === 0;
    $hash = $preHash ? substr($armazenado, 7) : $armazenado;
    $senhaVerificada = $preHash ? hash('sha256', $senha) : $senha;
    $valida = $legado
        ? hash_equals(strtolower($armazenado), md5($senha))
        : password_verify($senhaVerificada, $hash);
    if (!$valida) {
        return null;
    }

    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        // Bcrypt não deve truncar senhas longas nem receber bytes NUL.
        $usarPreHash = $preHash || (PASSWORD_DEFAULT === PASSWORD_BCRYPT
            && (strlen($senha) > 72 || strpos($senha, "\0") !== false));
        $novoHash = ($usarPreHash ? 'sha256:' : '')
            . password_hash($usarPreHash ? hash('sha256', $senha) : $senha, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND BINARY senha = ?');
        $stmt->bind_param('sis', $novoHash, $linha['id'], $armazenado);
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
 * Busca o nome de um técnico pelo id (mantido para consumidores internos).
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
 * Escopo da sessão web. Sem sessão autenticada, preserva os relatórios internos
 * que recebem somente mysqli; esses consumidores precisam ser confiáveis.
 */
function filtroVisibilidadeChamados(): string
{
    if (!isset($_SESSION['uid'])) {
        return '';
    }
    $uid = filter_var($_SESSION['uid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($uid === false) {
        return '1 = 0';
    }
    if (($_SESSION['papel'] ?? '') === 'tecnico') {
        return '';
    }
    if (($_SESSION['papel'] ?? '') === 'cliente') {
        return 'c.usuario_id = ' . $uid;
    }
    return '1 = 0';
}

/** Mantém os campos numéricos textuais retornados pelas consultas legadas. */
function normalizarTiposChamado(array $chamado): array
{
    foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
        if ($chamado[$campo] !== null) {
            $chamado[$campo] = (string) $chamado[$campo];
        }
    }
    return $chamado;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome FROM chamados c"
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $escopo = filtroVisibilidadeChamados();
    if ($escopo !== '') {
        $condicoes[] = $escopo;
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = $db->prepare($sql);
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarTiposChamado($c);
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
    $sql = 'SELECT c.* FROM chamados c WHERE c.id = ?';
    $escopo = filtroVisibilidadeChamados();
    if ($escopo !== '') {
        $sql .= ' AND ' . $escopo;
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha ? normalizarTiposChamado($linha) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT COALESCE(SUM(c.minutos_resposta), 0) AS soma,'
         . ' COUNT(c.minutos_resposta) AS quantidade FROM chamados c';
    $escopo = filtroVisibilidadeChamados();
    if ($escopo !== '') {
        $sql .= ' WHERE ' . $escopo;
    }
    $res = $db->query($sql);
    $linha = $res->fetch_assoc();
    $res->free();
    return (int) $linha['quantidade'] === 0
        ? 0.0
        : (float) $linha['soma'] / (int) $linha['quantidade'];
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome FROM chamados c"
         . ' LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $escopo = filtroVisibilidadeChamados();
    if ($escopo !== '') {
        $sql .= ' WHERE ' . $escopo;
    }
    $res = $db->query($sql . ' ORDER BY c.id');
    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        $res->free();
        throw new RuntimeException('Não foi possível preparar o CSV.');
    }
    try {
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Não foi possível escrever o CSV.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Não foi possível escrever o CSV.');
            }
        }
        if (!rewind($fp)) {
            throw new RuntimeException('Não foi possível ler o CSV.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        fpassthru($fp);
    } finally {
        $res->free();
        fclose($fp);
    }
}
