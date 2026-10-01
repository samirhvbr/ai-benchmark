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
    $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$linha) {
        return null;
    }

    $hashLegado = strlen($linha['senha']) === 32
        && ctype_xdigit($linha['senha'])
        && hash_equals($linha['senha'], md5($senha));
    if (!$hashLegado && !password_verify($senha, $linha['senha'])) {
        return null;
    }

    if ($hashLegado || password_needs_rehash($linha['senha'], PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        if ($novoHash !== false && $atualizar !== false) {
            $atualizar->bind_param('si', $novoHash, $linha['id']);
            $atualizar->execute();
            $atualizar->close();
        }
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
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Executa a consulta usada nas listagens web, já trazendo o técnico em uma só operação.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    if ($busca !== '') {
        $sql .= $usuarioId === null ? ' WHERE' : ' AND';
        $sql .= ' c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($usuarioId !== null && $busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $padrao);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    } elseif ($busca !== '') {
        $padrao = '%' . $busca . '%';
        $stmt->bind_param('s', $padrao);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    $stmt->close();
    return $chamados;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Lista somente os chamados de um cliente autenticado.
 */
function listarChamadosDoUsuario(mysqli $db, int $usuarioId, string $busca = ''): array
{
    return consultarChamados($db, $busca, $usuarioId);
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
    $chamado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $chamado ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(AVG(minutos_resposta), 0) AS media'
        . ' FROM chamados WHERE minutos_resposta IS NOT NULL');
    if ($res === false) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    return (float) $row['media'];
}

/**
 * Calcula a média visível para o usuário autenticado.
 */
function mediaRespostaDoUsuario(mysqli $db, int $usuarioId): float
{
    $stmt = $db->prepare('SELECT COALESCE(AVG(minutos_resposta), 0) AS media'
        . ' FROM chamados WHERE usuario_id = ? AND minutos_resposta IS NOT NULL');
    if ($stmt === false) {
        return 0.0;
    }
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (float) $row['media'];
}

/**
 * Protege campos textuais contra fórmulas quando o CSV é aberto em planilhas.
 */
function protegerFormulaCsv(string $valor): string
{
    return preg_match('/^[=+\-@\t\r]/', $valor) === 1 ? "'" . $valor : $valor;
}

/**
 * Escreve o CSV para a resposta HTTP, opcionalmente limitado ao dono dos chamados.
 */
function enviarCsv(mysqli $db, ?int $usuarioId): void
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id ASC';

    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        return;
    }
    if ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $stmt->close();
        return;
    }
    fputcsv($fp, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            protegerFormulaCsv($c['titulo']),
            formatarStatus((int) $c['status']),
            protegerFormulaCsv($c['tecnico_nome']),
            $c['criado_em'],
        ]);
    }
    fclose($fp);
    $stmt->close();
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    enviarCsv($db, null);
}

/**
 * Exportação usada pela rota web, respeitando a visibilidade do usuário.
 */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    enviarCsv($db, $papel === 'tecnico' ? null : $usuarioId);
}
