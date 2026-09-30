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
    $stmt = prepararConsulta($db, 'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$linha) {
        return null;
    }

    $hash = $linha['senha'];
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    // Bcrypt limita a senha a 72 bytes; nunca aceite um sufixo ignorado.
    if (!$legado && strncmp($hash, '$2y$', 4) === 0 && strlen($senha) > 72) {
        return null;
    }
    $valida = $legado ? hash_equals(strtolower($hash), md5($senha)) : password_verify($senha, $hash);
    if (!$valida) {
        return null;
    }

    // O banco já existente deve receber ALTER TABLE antes de armazenar hashes longos.
    // Enquanto isso, as credenciais legadas continuam funcionando, sem truncamento.
    $senhaMigravel = PASSWORD_DEFAULT !== PASSWORD_BCRYPT
        || (strlen($senha) <= 72 && strpos($senha, "\0") === false);
    if (($legado || password_needs_rehash($hash, PASSWORD_DEFAULT)) && $senhaMigravel) {
        try {
            $capacidade = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
            if ($capacidade === false) {
                throw new RuntimeException('Falha ao consultar o formato de senha.');
            }
            $coluna = $capacidade->fetch_assoc();
            $capacidade->free();
            if ($coluna && (int) $coluna['tamanho'] >= 255) {
                $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                $id = (int) $linha['id'];
                $stmt = prepararConsulta($db, 'UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                $stmt->bind_param('sis', $novoHash, $id, $hash);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Falha ao atualizar o hash.');
                }
                $stmt->close();
            }
        } catch (mysqli_sql_exception | RuntimeException $erro) {
            // A migração é oportunista: uma conexão somente-leitura ainda autentica.
            error_log('Painel de chamados: migracao de senha pendente (codigo ' . (int) $erro->getCode() . ').');
        }
    }
    unset($linha['senha']);
    return $linha;
}

/** Helper interno: falhas de preparação não podem virar consultas incompletas. */
function prepararConsulta(mysqli $db, string $sql): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Falha ao preparar consulta.');
    }
    return $stmt;
}

/**
 * Helper interno, para consultas com alias c. A sessão restringe todos os leitores.
 * Scripts CLI sem sessão continuam sendo os relatórios internos do manifesto.
 * Uma sessão parcial/inválida ou uma chamada web sem identidade não vê dados.
 */
function condicaoVisibilidadeChamados(): string
{
    if (empty($_SESSION) && session_status() === PHP_SESSION_NONE && PHP_SAPI === 'cli') {
        return '1 = 1';
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid === false || !in_array($papel, ['cliente', 'tecnico'], true)) {
        return '1 = 0';
    }
    return $papel === 'tecnico' ? '1 = 1' : 'c.usuario_id = ' . $uid;
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
    $stmt = prepararConsulta($db, 'SELECT nome FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $tecnicoId);
    $stmt->execute();
    $res = $stmt->get_result();
    $t = $res->fetch_assoc();
    $stmt->close();
    return $t ? $t['nome'] : '-';
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = "SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
            FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id
            WHERE " . condicaoVisibilidadeChamados();
    if ($busca !== '') {
        $sql .= ' AND c.titulo LIKE ?';
    }
    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = prepararConsulta($db, $sql);
    if ($busca !== '') {
        $termo = '%' . $busca . '%';
        $stmt->bind_param('s', $termo);
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
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $stmt = prepararConsulta($db, 'SELECT c.* FROM chamados c WHERE c.id = ? AND ' . condicaoVisibilidadeChamados());
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $linha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $linha ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT SUM(c.minutos_resposta) AS soma, COUNT(c.minutos_resposta) AS qtd
        FROM chamados c WHERE ' . condicaoVisibilidadeChamados());
    if ($res === false) {
        throw new RuntimeException('Falha ao consultar a média.');
    }
    $linha = $res->fetch_assoc();
    $res->free();
    $qtd = (int) $linha['qtd'];
    return $qtd > 0 ? (float) $linha['soma'] / $qtd : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.*, COALESCE(u.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios u ON u.id = c.tecnico_id
        WHERE " . condicaoVisibilidadeChamados() . ' ORDER BY c.id');
    if ($res === false) {
        throw new RuntimeException('Falha ao consultar a exportação.');
    }
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $res->free();
        throw new RuntimeException('Falha ao abrir a saída CSV.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('Cache-Control: no-store');
    try {
        if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
            throw new RuntimeException('Falha ao escrever o cabeçalho CSV.');
        }
        while ($c = $res->fetch_assoc()) {
            if (fputcsv($fp, [
                $c['id'],
                $c['titulo'],
                formatarStatus((int) $c['status']),
                $c['tecnico_nome'],
                $c['criado_em'],
            ], ',', '"', '') === false) {
                throw new RuntimeException('Falha ao escrever uma linha CSV.');
            }
        }
    } finally {
        $res->free();
        fclose($fp);
    }
}
