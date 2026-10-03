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

    $hash = $linha['senha'];
    $legado = strlen($hash) === 32 && ctype_xdigit($hash);
    if (!$legado && password_get_info($hash)['algoName'] === 'bcrypt'
        && (strlen($senha) > 72 || strpos($senha, "\0") !== false)) {
        return null;
    }
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    // Bcrypt não representa senhas com NUL ou com mais de 72 bytes sem perda.
    // Nesse caso preservamos o login legado até uma migração específica.
    if (($legado || password_needs_rehash($hash, PASSWORD_DEFAULT))
        && strlen($senha) <= 72 && strpos($senha, "\0") === false) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        // O schema.sql não altera bases já instaladas. Não trunque o hash em CHAR(32).
        try {
            $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS capacidade
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
            $coluna = $res !== false ? $res->fetch_assoc() : null;
            if ($res !== false) {
                $res->free();
            }
            if ($coluna && (int) $coluna['capacidade'] >= strlen($novoHash)) {
                $stmt = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                if ($stmt !== false) {
                    try {
                        $stmt->bind_param('sis', $novoHash, $linha['id'], $hash);
                        $stmt->execute();
                    } finally {
                        $stmt->close();
                    }
                }
            }
        } catch (mysqli_sql_exception $e) {
            // A atualização é oportunista; relatórios com acesso só de leitura
            // continuam autenticando. Não registre a senha nem o hash.
            error_log('Nao foi possivel atualizar o hash de senha; verificar schema e permissoes.');
        }
    }

    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Escopo compartilhado das consultas. Rotinas internas sem contexto de sessão
 * mantêm acesso geral; no painel, somente técnicos têm acesso geral.
 */
function escopoChamados(): array
{
    if (!isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return ['1 = 1', '', []];
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid !== false && $papel === 'tecnico') {
        return ['1 = 1', '', []];
    }
    if ($uid !== false && $papel === 'cliente') {
        return ['c.usuario_id = ?', 'i', [$uid]];
    }
    return ['1 = 0', '', []];
}

/** Executa consultas internas sem interpolar os valores dos parâmetros. */
function consultarChamados(mysqli $db, string $sql, string $tipos, array $parametros): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($parametros) {
        $stmt->bind_param($tipos, ...$parametros);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return $res;
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
    [$filtro, $tipos, $parametros] = escopoChamados();
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE " . $filtro;
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
        $tipos .= 's';
        $parametros[] = '%' . $busca . '%';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $res = consultarChamados($db, $sql, $tipos, $parametros);

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        // query() retornava os campos numéricos como strings por padrão.
        $chamados[] = array_map(function ($valor) {
            return is_int($valor) || is_float($valor) ? (string) $valor : $valor;
        }, $c);
    }
    $res->free();
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    [$filtro, $tipos, $parametros] = escopoChamados();
    array_unshift($parametros, $id);
    $res = consultarChamados($db, 'SELECT c.* FROM chamados c WHERE c.id = ? AND ' . $filtro,
        'i' . $tipos, $parametros);
    $chamado = $res->fetch_assoc() ?: null;
    $res->free();
    return $chamado === null ? null : array_map(function ($valor) {
        return is_int($valor) || is_float($valor) ? (string) $valor : $valor;
    }, $chamado);
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    [$filtro, $tipos, $parametros] = escopoChamados();
    $res = consultarChamados($db, 'SELECT COALESCE(SUM(c.minutos_resposta), 0) AS soma,
        COUNT(c.minutos_resposta) AS quantidade
        FROM chamados c WHERE ' . $filtro, $tipos, $parametros);
    $row = $res->fetch_assoc();
    $res->free();
    $quantidade = (int) $row['quantidade'];
    return $quantidade === 0 ? 0.0 : (float) $row['soma'] / $quantidade;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    [$filtro, $tipos, $parametros] = escopoChamados();
    $res = consultarChamados($db, "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
        WHERE " . $filtro . ' ORDER BY c.id', $tipos, $parametros);
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $res->free();
        return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    try {
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
    } finally {
        $res->free();
        fclose($fp);
    }
}
