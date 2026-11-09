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
    $legado = strlen($hash) === 32 && ctype_xdigit($hash);
    $valida = $legado
        ? hash_equals($hash, md5($senha))
        : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    // Migra hashes MD5 existentes sem exigir troca imediata de todas as senhas.
    if ($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        $atualiza = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $atualiza->bind_param('si', $novoHash, $linha['id']);
        $atualiza->execute();
    }

    unset($linha['senha']);
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
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
 * Executa a consulta comum de chamados. O filtro de proprietário é usado pela
 * camada web; as funções públicas históricas continuam listando todos os dados.
 */
function consultarChamados(mysqli $db, string $busca = '', ?int $usuarioId = null, bool $ordenarPorId = false)
{
    $sql = 'SELECT c.*, COALESCE(t.nome, \'-\') AS tecnico_nome'
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id';
    $condicoes = [];
    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
    }
    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ?';
    }
    if ($condicoes) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }
    $sql .= $ordenarPorId ? ' ORDER BY c.id' : ' ORDER BY c.criado_em DESC';

    if (!$condicoes) {
        return $db->query($sql);
    }

    $stmt = $db->prepare($sql);
    if ($usuarioId !== null && $busca !== '') {
        $like = '%' . $busca . '%';
        $stmt->bind_param('is', $usuarioId, $like);
    } elseif ($usuarioId !== null) {
        $stmt->bind_param('i', $usuarioId);
    } else {
        $like = '%' . $busca . '%';
        $stmt->bind_param('s', $like);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $res = consultarChamados($db, $busca);
    $chamados = [];
    if ($res === false) {
        return $chamados;
    }
    while ($c = $res->fetch_assoc()) {
        $chamados[] = $c;
    }
    return $chamados;
}

/** Lista apenas os chamados que o papel autenticado pode ver. */
function listarChamadosVisiveis(mysqli $db, string $papel, int $uid, string $busca = ''): array
{
    $res = consultarChamados($db, $busca, $papel === 'cliente' ? $uid : null);
    $chamados = [];
    if ($res === false) {
        return $chamados;
    }
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
    $stmt = $db->prepare('SELECT * FROM chamados WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/** Carrega um chamado somente se o papel autenticado tiver acesso a ele. */
function verChamadoVisivel(mysqli $db, int $id, string $papel, int $uid): ?array
{
    $c = verChamado($db, $id);
    if ($c && $papel === 'cliente' && (int) $c['usuario_id'] !== $uid) {
        return null;
    }
    return $c;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function calcularMediaResposta(mysqli $db, ?int $usuarioId = null): float
{
    $sql = 'SELECT SUM(minutos_resposta) AS soma, COUNT(minutos_resposta) AS quantidade FROM chamados';
    if ($usuarioId === null) {
        $res = $db->query($sql);
    } else {
        $stmt = $db->prepare($sql . ' WHERE usuario_id = ?');
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $res = $stmt->get_result();
    }
    if ($res === false) {
        return 0.0;
    }
    $linha = $res->fetch_assoc();
    $quantidade = (int) $linha['quantidade'];
    return $quantidade === 0 ? 0.0 : (float) $linha['soma'] / $quantidade;
}

function mediaResposta(mysqli $db): float
{
    return calcularMediaResposta($db);
}

/** Calcula o indicador somente sobre chamados visíveis ao usuário. */
function mediaRespostaVisivel(mysqli $db, string $papel, int $uid): float
{
    return calcularMediaResposta($db, $papel === 'cliente' ? $uid : null);
}

/** Impede que valores controlados por usuários sejam interpretados como fórmulas. */
function valorCsvSeguro(string $valor): string
{
    if ($valor === '-') {
        return $valor;
    }
    return preg_match('/^\s*[=+\-@]/u', $valor) === 1 ? "'" . $valor : $valor;
}

function exportarCsvComFiltro(mysqli $db, ?int $usuarioId = null): void
{
    $res = consultarChamados($db, '', $usuarioId, true);
    if ($res === false) {
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n");
    while ($c = $res->fetch_assoc()) {
        fputcsv($fp, [
            $c['id'],
            valorCsvSeguro($c['titulo']),
            formatarStatus((int) $c['status']),
            valorCsvSeguro($c['tecnico_nome']),
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
    exportarCsvComFiltro($db);
}

/** Exporta somente os chamados visíveis ao papel autenticado. */
function exportarCsvVisivel(mysqli $db, string $papel, int $uid): void
{
    exportarCsvComFiltro($db, $papel === 'cliente' ? $uid : null);
}
