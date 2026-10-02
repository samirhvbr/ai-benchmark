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
        return null;
    }

    $hash = (string) $linha['senha'];
    $legado = preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
    $senhaValida = $legado
        ? hash_equals(strtolower($hash), md5($senha))
        : password_verify($senha, $hash);
    if (!$senhaValida) {
        return null;
    }

    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        try {
            $coluna = $db->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho
                   FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'usuarios'
                    AND COLUMN_NAME = 'senha'"
            )->fetch_assoc();
            $capacidade = $coluna && $coluna['tamanho'] !== null ? (int) $coluna['tamanho'] : 0;
            if ($capacidade >= strlen($novoHash)) {
                $usuarioId = (int) $linha['id'];
                $atualizar = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
                $atualizar->bind_param('si', $novoHash, $usuarioId);
                $atualizar->execute();
            }
        } catch (Throwable $e) {
            // Bancos legados com CHAR(32) continuam autenticando até a migração do schema.
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
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc();
    return $t ? $t['nome'] : '-';
}

/**
 * Escapa texto para os contextos HTML de texto e atributo usados pelo painel.
 */
function escaparHtml(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Consulta compartilhada pela API pública e pela listagem web com escopo de cliente.
 */
function consultarChamados(mysqli $db, string $busca, ?int $usuarioId): array
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    $parametros = [];
    $tipos = '';

    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
        $parametros[] = '%' . $busca . '%';
        $tipos .= 's';
    }
    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $parametros[] = $usuarioId;
        $tipos .= 'i';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= ' ORDER BY c.criado_em DESC';

    $stmt = $db->prepare($sql);
    if ($parametros) {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();

    $chamados = [];
    $res = $stmt->get_result();
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
 * Lista somente os chamados que o usuário autenticado pode ver.
 */
function listarChamadosVisiveis(mysqli $db, string $busca, int $usuarioId, string $papel): array
{
    if ($papel === 'tecnico') {
        return consultarChamados($db, $busca, null);
    }
    if ($papel === 'cliente') {
        return consultarChamados($db, $busca, $usuarioId);
    }
    return [];
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
 * Carrega um chamado apenas quando ele está no escopo do usuário autenticado.
 */
function verChamadoVisivel(mysqli $db, int $id, int $usuarioId, string $papel): ?array
{
    if ($papel === 'tecnico') {
        return verChamado($db, $id);
    }
    if ($papel !== 'cliente') {
        return null;
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
    $res = $db->query('SELECT AVG(minutos_resposta) AS media FROM chamados');
    $row = $res->fetch_assoc();
    return $row['media'] === null ? 0.0 : (float) $row['media'];
}

/**
 * Calcula o indicador somente sobre os chamados visíveis ao usuário.
 */
function mediaRespostaVisivel(mysqli $db, int $usuarioId, string $papel): float
{
    if ($papel === 'tecnico') {
        return mediaResposta($db);
    }
    if ($papel !== 'cliente') {
        return 0.0;
    }

    $stmt = $db->prepare('SELECT AVG(minutos_resposta) AS media FROM chamados WHERE usuario_id = ?');
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['media'] === null ? 0.0 : (float) $row['media'];
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    escreverCsv($db, null, false);
}

/**
 * Exporta somente os chamados que o usuário autenticado pode ver.
 */
function exportarCsvVisivel(mysqli $db, int $usuarioId, string $papel): void
{
    escreverCsv($db, $papel === 'cliente' ? $usuarioId : null, $papel !== 'cliente' && $papel !== 'tecnico');
}

/**
 * Implementação compartilhada do CSV, transmitida diretamente na resposta.
 */
function escreverCsv(mysqli $db, ?int $usuarioId, bool $semAcesso): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, COALESCE(t.nome, \'-\') AS tecnico_nome, c.criado_em'
         . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    if ($semAcesso) {
        $sql .= ' WHERE 1 = 0';
    } elseif ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
    }
    $sql .= ' ORDER BY c.id';

    $stmt = $db->prepare($sql);
    if ($usuarioId !== null && !$semAcesso) {
        $stmt->bind_param('i', $usuarioId);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
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
