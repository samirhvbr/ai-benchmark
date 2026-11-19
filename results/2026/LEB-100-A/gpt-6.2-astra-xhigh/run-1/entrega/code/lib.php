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
    $anterior = $linha['senha'] ?? '';
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $anterior) === 1;
    if (!$linha || ($legado && !hash_equals(strtolower($anterior), md5($senha)))) {
        password_hash($senha, PASSWORD_ARGON2ID);
        return null;
    }
    unset($linha['senha']);
    if (!$legado && !password_verify($senha, $anterior)) {
        return null;
    }
    if ($legado || password_needs_rehash($anterior, PASSWORD_ARGON2ID)) {
        $novo = password_hash($senha, PASSWORD_ARGON2ID);
        if ($novo === false) {
            throw new RuntimeException('Falha ao atualizar credencial.');
        }
        $atualiza = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND BINARY senha = ?');
        $atualiza->bind_param('sis', $novo, $linha['id'], $anterior);
        $atualiza->execute();
        if ($atualiza->affected_rows !== 1) {
            return null;
        }
    }
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
 * Busca o nome de um técnico pelo id.
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

function donoDosChamados(): ?int
{
    if (!isset($_SESSION) && session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid === false || !in_array($papel, ['cliente', 'tecnico'], true)) {
        throw new DomainException('Sessão sem identidade válida.');
    }
    return $papel === 'cliente' ? $uid : null;
}

function papelAtualDoUsuario(mysqli $db, int $uid): string
{
    $stmt = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha || !in_array($linha['papel'], ['cliente', 'tecnico'], true)) {
        throw new DomainException('Acesso não autorizado.');
    }
    return $linha['papel'];
}

function consultarChamadosVisiveis(mysqli $db, string $selecao, string $sufixo = '', string $tipos = '', ...$valores): mysqli_stmt
{
    $dono = donoDosChamados();
    $onde = ' WHERE 1=1';
    if ($dono !== null) {
        $onde = ' WHERE c.usuario_id = ?';
        $tipos = 'i' . $tipos;
        array_unshift($valores, $dono);
    }
    $stmt = $db->prepare($selecao . $onde . $sufixo);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$valores);
    }
    $stmt->execute();
    return $stmt;
}

function linhaDeChamado(array $linha): array
{
    foreach ($linha as $chave => $valor) {
        if (is_int($valor)) {
            $linha[$chave] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $ordem = ' ORDER BY c.criado_em DESC';
    if ($busca !== '') {
        $stmt = consultarChamadosVisiveis($db, $sql, ' AND c.titulo LIKE ?' . $ordem, 's', '%' . $busca . '%');
    } else {
        $stmt = consultarChamadosVisiveis($db, $sql, $ordem);
    }
    $res = $stmt->get_result();
    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = linhaDeChamado($c);
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = consultarChamadosVisiveis($db, 'SELECT c.* FROM chamados c', ' AND c.id = ?', 'i', $id);
    $linha = $stmt->get_result()->fetch_assoc();
    return $linha ? linhaDeChamado($linha) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $stmt = consultarChamadosVisiveis($db, 'SELECT COALESCE(AVG(c.minutos_resposta * 1e0), 0) FROM chamados c');
    return (float) $stmt->get_result()->fetch_row()[0];
}

/**
 * Exporta os chamados visíveis diretamente na saída.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $stmt = consultarChamadosVisiveis($db, "SELECT c.id, c.titulo, c.status, COALESCE(t.nome, '-'), c.criado_em
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id", ' ORDER BY c.id ASC');
    $stmt->bind_result($id, $titulo, $status, $tecnico, $criadoEm);
    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        throw new RuntimeException('Não foi possível abrir a saída CSV.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    try {
        $cabecalho = "ID,Titulo,Status,Tecnico,Aberto em\n";
        if (fwrite($fp, $cabecalho) !== strlen($cabecalho)) {
            throw new RuntimeException('Falha na escrita do cabeçalho CSV.');
        }
        while (($proxima = $stmt->fetch()) === true) {
            if (fputcsv($fp, [$id, $titulo, formatarStatus((int) $status), $tecnico, $criadoEm], ',', '"', '') === false) {
                throw new RuntimeException('Falha na escrita do CSV.');
            }
        }
        if ($proxima === false) {
            throw new RuntimeException('Falha na leitura para o CSV.');
        }
    } finally {
        fclose($fp);
        $stmt->close();
    }
}
