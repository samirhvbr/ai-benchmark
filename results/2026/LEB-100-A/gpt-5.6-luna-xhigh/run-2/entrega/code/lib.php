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
 * Hashes MD5 existentes continuam válidos durante a transição e são
 * substituídos por password_hash() depois do primeiro login bem-sucedido.
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    if (!$stmt->execute()) {
        return null;
    }
    $resultado = $stmt->get_result();
    $linha = $resultado ? $resultado->fetch_assoc() : null;
    if (!$linha) {
        return null;
    }

    $senhaArmazenada = (string) $linha['senha'];
    $hashLegadoValido = preg_match('/^[a-f0-9]{32}$/i', $senhaArmazenada)
        && hash_equals(strtolower($senhaArmazenada), md5($senha));
    if (!$hashLegadoValido && !password_verify($senha, $senhaArmazenada)) {
        return null;
    }

    if ($hashLegadoValido) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        if ($novoHash !== false) {
            $migracao = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($migracao !== false) {
                $id = (int) $linha['id'];
                $migracao->bind_param('si', $novoHash, $id);
                $migracao->execute();
            }
        }
    }

    return [
        'id' => (int) $linha['id'],
        'nome' => $linha['nome'],
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
 * Busca o nome de um técnico pelo id (usado por consumidores legados).
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
    if (!$stmt->execute()) {
        return '-';
    }
    $resultado = $stmt->get_result();
    $tecnico = $resultado ? $resultado->fetch_assoc() : null;
    return $tecnico ? $tecnico['nome'] : '-';
}

/**
 * Retorna o id do usuário da sessão quando a biblioteca é usada pelo painel.
 * Chamadas internas sem sessão preservam o escopo legado.
 */
function painelUsuarioSessaoId(): ?int
{
    if (!isset($_SESSION['uid'])) {
        return null;
    }
    $id = filter_var($_SESSION['uid'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    return $id === false ? null : (int) $id;
}

/**
 * Monta o predicado de visibilidade sem alterar as assinaturas públicas.
 * Técnicos veem todos; qualquer outro papel autenticado vê seus chamados.
 */
function painelFiltroVisibilidade(string $alias, array &$parametros, string &$tipos): string
{
    $id = painelUsuarioSessaoId();
    if ($id === null || ($_SESSION['papel'] ?? '') === 'tecnico') {
        return '';
    }
    $parametros[] = $id;
    $tipos .= 'i';
    return $alias . '.usuario_id = ?';
}

/**
 * Vincula uma lista pequena de parâmetros a uma instrução mysqli preparada.
 */
function painelVincularParametros(mysqli_stmt $stmt, string $tipos, array &$parametros): void
{
    if ($tipos === '') {
        return;
    }
    $referencias = [$tipos];
    foreach ($parametros as &$parametro) {
        $referencias[] = &$parametro;
    }
    unset($parametro);
    call_user_func_array([$stmt, 'bind_param'], $referencias);
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $parametros = [];
    $tipos = '';

    $visibilidade = painelFiltroVisibilidade('c', $parametros, $tipos);
    if ($visibilidade !== '') {
        $condicoes[] = $visibilidade;
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $parametros[] = '%' . $busca . '%';
        $tipos .= 's';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    painelVincularParametros($stmt, $tipos, $parametros);
    if (!$stmt->execute()) {
        return [];
    }
    $res = $stmt->get_result();
    if ($res === false) {
        return [];
    }

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
    $sql = 'SELECT * FROM chamados c WHERE c.id = ?';
    $parametros = [$id];
    $tipos = 'i';
    $visibilidade = painelFiltroVisibilidade('c', $parametros, $tipos);
    if ($visibilidade !== '') {
        $sql .= ' AND ' . $visibilidade;
    }

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return null;
    }
    painelVincularParametros($stmt, $tipos, $parametros);
    if (!$stmt->execute()) {
        return null;
    }
    $res = $stmt->get_result();
    return $res ? ($res->fetch_assoc() ?: null) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT AVG(c.minutos_resposta) AS media'
        . ' FROM chamados c WHERE c.minutos_resposta IS NOT NULL';
    $parametros = [];
    $tipos = '';
    $visibilidade = painelFiltroVisibilidade('c', $parametros, $tipos);
    if ($visibilidade !== '') {
        $sql .= ' AND ' . $visibilidade;
    }

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return 0.0;
    }
    painelVincularParametros($stmt, $tipos, $parametros);
    if (!$stmt->execute()) {
        return 0.0;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row && $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $parametros = [];
    $tipos = '';
    $visibilidade = painelFiltroVisibilidade('c', $parametros, $tipos);
    if ($visibilidade !== '') {
        $sql .= ' WHERE ' . $visibilidade;
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    painelVincularParametros($stmt, $tipos, $parametros);
    if (!$stmt->execute()) {
        return;
    }
    $res = $stmt->get_result();
    if ($res === false) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            $c['titulo'],
            formatarStatus((int) $c['status']),
            $c['tecnico_nome'],
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
