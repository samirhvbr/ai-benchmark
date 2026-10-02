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
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    if (!$linha) {
        // Mantém o custo de uma tentativa parecido para logins existentes e inexistentes.
        password_verify($senha, '$2y$12$C6UzMDM.H6dfI/f/IKcEe.ogMzW3M1x6oX3WJs7QNNK7tA2gK3K6K');
        return null;
    }

    $hashArmazenado = (string) $linha['senha'];
    $hashLegado = preg_match('/^[a-f0-9]{32}$/i', $hashArmazenado) === 1;
    $senhaValida = $hashLegado
        ? hash_equals(strtolower($hashArmazenado), md5($senha))
        : password_verify($senha, $hashArmazenado);

    if (!$senhaValida) {
        return null;
    }

    if ($hashLegado || password_needs_rehash($hashArmazenado, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $id = (int) $linha['id'];
        try {
            $atualizacao = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
            $atualizacao->bind_param('sis', $novoHash, $id, $hashArmazenado);
            $atualizacao->execute();
        } catch (mysqli_sql_exception $e) {
            // Instalações ainda não migradas de CHAR(32) continuam autenticando.
        }
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
    switch ($status) {
        case 1:
            return 'Aberto';
        case 2:
            return 'Em atendimento';
        case 3:
            return 'Resolvido';
        default:
            return 'Desconhecido';
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
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Executa a consulta de listagem, restringindo por dono quando solicitado.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
              FROM chamados c
              LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $filtros = [];
    $parametros = [];
    $tipos = '';

    if ($usuarioId !== null) {
        $filtros[] = 'c.usuario_id = ?';
        $parametros[] = $usuarioId;
        $tipos .= 'i';
    }
    if ($busca !== '') {
        $filtros[] = 'c.titulo LIKE ?';
        $parametros[] = '%' . $busca . '%';
        $tipos .= 's';
    }
    if ($filtros) {
        $sql .= ' WHERE ' . implode(' AND ', $filtros);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($parametros) {
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
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return consultarChamados($db, $busca, null);
}

/**
 * Versão usada pela interface web para aplicar a visibilidade do usuário atual.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, int $usuarioId, string $papel): array
{
    return consultarChamados($db, $busca, $papel === 'tecnico' ? null : $usuarioId);
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Carrega um chamado somente se ele for visível para o usuário atual.
 */
function verChamadoVisivel(mysqli $db, int $id, int $usuarioId, string $papel): ?array
{
    if ($papel === 'tecnico') {
        return verChamado($db, $id);
    }

    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ? AND usuario_id = ?');
    $stmt->bind_param('ii', $id, $usuarioId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE minutos_resposta IS NOT NULL');
    $row = $res->fetch_assoc();
    return $row && $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Calcula a média apenas sobre chamados visíveis para o usuário atual.
 */
function mediaRespostaVisivel(mysqli $db, int $usuarioId, string $papel): float
{
    if ($papel === 'tecnico') {
        return mediaResposta($db);
    }

    $stmt = $db->prepare(
        'SELECT AVG(minutos_resposta) AS media
           FROM chamados
          WHERE usuario_id = ? AND minutos_resposta IS NOT NULL'
    );
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && $row['media'] !== null ? (float) $row['media'] : 0.0;
}

/**
 * Escreve um resultado já ordenado no formato CSV público.
 */
function escreverCsv(mysqli_result $res): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');

    $fp = fopen('php://output', 'wb');
    if ($fp === false) {
        return;
    }
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
    fclose($fp);
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query(
        "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
           FROM chamados c
           LEFT JOIN usuarios t ON t.id = c.tecnico_id
          ORDER BY c.id"
    );
    escreverCsv($res);
}

/**
 * Exporta apenas os chamados visíveis para o usuário da interface web.
 */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    if ($papel === 'tecnico') {
        exportarCsv($db);
        return;
    }

    $stmt = $db->prepare(
        "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
           FROM chamados c
           LEFT JOIN usuarios t ON t.id = c.tecnico_id
          WHERE c.usuario_id = ?
          ORDER BY c.id"
    );
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    escreverCsv($stmt->get_result());
}
