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
    $res = $stmt->get_result();
    $linha = $res->fetch_assoc();
    $res->free();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $armazenada = $linha['senha'];
    $legada = preg_match('/\A[0-9a-f]{32}\z/i', $armazenada) === 1;
    if (!$legada && password_get_info($armazenada)['algoName'] === 'bcrypt'
        && (strlen($senha) > 72 || strpos($senha, "\0") !== false)) {
        return null;
    }
    $valida = $legada
        ? hash_equals(strtolower($armazenada), md5($senha))
        : password_verify($senha, $armazenada);
    if (!$valida) {
        return null;
    }

    // Nunca trunca uma senha legada ao migrá-la para bcrypt.
    if (($legada || password_needs_rehash($armazenada, PASSWORD_DEFAULT))
        && strlen($senha) <= 72 && strpos($senha, "\0") === false) {
        $migracao = null;
        try {
            $res = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS capacidade
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
            $capacidade = (int) $res->fetch_assoc()['capacidade'];
            $res->free();
            // Banco antigo continua autenticando; ampliar a coluna antes do rollout.
            if ($capacidade >= 255) {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $migracao = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                $migracao->bind_param('sis', $hash, $linha['id'], $armazenada);
                $migracao->execute();
            }
        } catch (mysqli_sql_exception $erro) {
            // Uma migração opcional sem permissão de escrita não invalida o login.
            error_log('Painel de Chamados: migracao de senha pendente (' . $erro->getCode() . ').');
        } finally {
            if ($migracao instanceof mysqli_stmt) {
                $migracao->close();
            }
        }
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Predicado comum a todas as leituras. Os valores numéricos são convertidos
 * antes de entrar no SQL. CLI sem sessão mantém os relatórios globais do ISP.
 */
function condicaoVisibilidadeChamados(string $alias = ''): string
{
    if (PHP_SAPI === 'cli' && !isset($_SESSION['uid']) && !isset($_SESSION['papel'])) {
        return '1 = 1';
    }
    $uid = (int) ($_SESSION['uid'] ?? 0);
    $papel = $_SESSION['papel'] ?? '';
    if ($uid > 0 && $papel === 'tecnico') {
        return '1 = 1';
    }
    if ($uid > 0 && $papel === 'cliente') {
        return $alias . 'usuario_id = ' . $uid;
    }
    return '1 = 0';
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
    $res->free();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
        WHERE " . condicaoVisibilidadeChamados('c.');
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
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
        // Prepared statements retornam inteiros nativos; a listagem legada
        // retornava os campos numéricos como strings (e NULL como NULL).
        foreach ($c as $campo => $valor) {
            if (is_int($valor)) {
                $c[$campo] = (string) $valor;
            }
        }
        $chamados[] = $c;
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
    // $id já tem tipo int: preserva os tipos retornados pela consulta legada.
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id . ' AND ' . condicaoVisibilidadeChamados());
    $chamado = $res->fetch_assoc() ?: null;
    $res->free();
    return $chamado;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    // A expressão DOUBLE evita o arredondamento DECIMAL de AVG(INT).
    $res = $db->query('SELECT COALESCE(AVG(minutos_resposta + 0e0), 0) AS media FROM chamados WHERE '
        . condicaoVisibilidadeChamados());
    $media = (float) $res->fetch_assoc()['media'];
    $res->free();
    return $media;
}

/** Neutraliza fórmulas em campos textuais ao abrir o CSV em planilhas. */
function textoSeguroCsv(string $valor): string
{
    if ($valor !== '-' && preg_match('/\A(?:[\x00-\x20]*[=+@-]|[\t\r\n])/', $valor)) {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    // Stream privado por chamada, com spill em arquivo temporário fora do webroot.
    $fp = fopen('php://temp', 'w+');
    if ($fp === false) {
        throw new RuntimeException('Falha ao preparar CSV.');
    }
    $res = null;
    try {
        // Cabeçalho literal exigido pelo manifesto.
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao gravar CSV.');
        }
        $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id
            WHERE " . condicaoVisibilidadeChamados('c.') . ' ORDER BY c.id', MYSQLI_USE_RESULT);
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                textoSeguroCsv($c['titulo']),
                formatarStatus((int) $c['status']),
                textoSeguroCsv($c['tecnico_nome']),
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao gravar CSV.');
            }
        }
        $res->free();
        $res = null;
        if (!rewind($fp)) {
            throw new RuntimeException('Falha ao ler CSV.');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        if (fpassthru($fp) === false) {
            throw new RuntimeException('Falha ao enviar CSV.');
        }
    } finally {
        if ($res instanceof mysqli_result) {
            $res->free();
        }
        fclose($fp);
    }
}
