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

    $hash = (string) $linha['senha'];
    $senhaValida = password_verify($senha, $hash);
    $hashLegado = !$senhaValida
        && preg_match('/^[a-f0-9]{32}$/i', $hash) === 1
        && hash_equals(strtolower($hash), md5($senha));

    if (!$senhaValida && !$hashLegado) {
        return null;
    }

    // Migra hashes MD5 no primeiro login e atualiza hashes modernos quando necessário.
    if ($hashLegado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $upgrade = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $upgrade->bind_param('si', $novoHash, $linha['id']);
        $upgrade->execute();
    }

    return [
        'id' => $linha['id'],
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
 * Retorna o dono que deve limitar as consultas em uma sessão de cliente.
 * Sem sessão de cliente, preserva a visão geral usada pelos técnicos e jobs.
 */
function clienteAutenticadoId(): ?int
{
    if (($_SESSION['papel'] ?? null) !== 'cliente' || !isset($_SESSION['uid'])) {
        return null;
    }
    return (int) $_SESSION['uid'];
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
    $tipos = '';
    $parametros = [];

    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $clienteId = clienteAutenticadoId();
    if ($clienteId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $clienteId;
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($tipos !== '') {
        $stmt->bind_param($tipos, ...$parametros);
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
    $clienteId = clienteAutenticadoId();
    if ($clienteId === null) {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
        $stmt->bind_param('ii', $id, $clienteId);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT AVG(minutos_resposta) AS media FROM chamados'
        . ' WHERE minutos_resposta IS NOT NULL';
    $clienteId = clienteAutenticadoId();
    if ($clienteId === null) {
        $res = $db->query($sql);
    } else {
        $stmt = $db->prepare($sql . ' AND usuario_id = ?');
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    }
    $linha = $res->fetch_assoc();
    return (float) ($linha['media'] ?? 0.0);
}

/**
 * Impede que planilhas interpretem valores de texto como fórmulas.
 */
function valorCsvSeguro(string $valor): string
{
    if ($valor !== '' && strpos("=+-@\t\r\n", $valor[0]) !== false) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em,'
        . ' COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $clienteId = clienteAutenticadoId();
    if ($clienteId === null) {
        $sql .= ' ORDER BY c.id';
        $res = $db->query($sql);
    } else {
        $sql .= ' WHERE c.usuario_id = ? ORDER BY c.id';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $res = $stmt->get_result();
    }
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
            valorCsvSeguro($c['titulo']),
            formatarStatus((int) $c['status']),
            valorCsvSeguro($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
}
