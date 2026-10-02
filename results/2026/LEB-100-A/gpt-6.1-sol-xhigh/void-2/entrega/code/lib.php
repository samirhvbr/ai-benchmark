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
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $linha['senha']) === 1;
    $valida = $legado
        ? hash_equals(strtolower($linha['senha']), md5($senha))
        : password_verify($senha, $linha['senha']);
    if (!$valida) {
        return null;
    }

    // A ampliação da coluna pode ser implantada antes ou depois deste código.
    // Bcrypt limita a entrada a 72 bytes e não aceita NUL: não truncar senhas legadas.
    if (($legado || password_needs_rehash($linha['senha'], PASSWORD_DEFAULT))
        && (PASSWORD_DEFAULT !== PASSWORD_BCRYPT || (strlen($senha) <= 72 && strpos($senha, "\0") === false))) {
        try {
            $resColuna = @$db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
            $coluna = $resColuna ? $resColuna->fetch_assoc() : null;
            if ($coluna && (int) $coluna['tamanho'] >= 255) {
                $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = @$db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                if ($stmt) {
                    $stmt->bind_param('sis', $novoHash, $linha['id'], $linha['senha']);
                    if (!@$stmt->execute()) {
                        error_log('Migracao do hash de senha adiada: verificar permissoes do banco.');
                    }
                    $stmt->close();
                } else {
                    error_log('Migracao do hash de senha adiada: verificar permissoes do banco.');
                }
            } elseif (!$resColuna) {
                error_log('Migracao do hash de senha adiada: verificar metadados do banco.');
            }
        } catch (mysqli_sql_exception $e) {
            // Conexões internas somente de leitura também podem autenticar.
            error_log('Migracao do hash de senha adiada: verificar permissoes do banco.');
        }
    }
    return ['id' => $linha['id'], 'nome' => $linha['nome'], 'papel' => $linha['papel']];
}

/**
 * Dono a filtrar; null representa todos os chamados. Sem identidade válida, nega.
 * Scripts CLI sem contexto de sessão são os consumidores internos do manifesto.
 */
function donoChamadosVisiveis(): ?int
{
    if (PHP_SAPI === 'cli' && empty($_SESSION)) {
        return null;
    }
    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $papel = $_SESSION['papel'] ?? null;
    if ($uid === false || !in_array($papel, ['cliente', 'tecnico'], true)) {
        return 0;
    }
    return $papel === 'tecnico' ? null : $uid;
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
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE 1 = 1";
    $dono = donoChamadosVisiveis();
    if ($dono !== null) {
        $sql .= ' AND c.usuario_id = ' . $dono;
    }
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
        // Consultas preparadas retornam inteiros nativos; manter os tipos anteriores.
        foreach (['id', 'usuario_id', 'tecnico_id', 'status', 'prioridade', 'minutos_resposta'] as $campo) {
            if ($c[$campo] !== null) {
                $c[$campo] = (string) $c[$campo];
            }
        }
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
    $sql = 'SELECT * FROM chamados WHERE id = ' . $id;
    $dono = donoChamadosVisiveis();
    if ($dono !== null) {
        $sql .= ' AND usuario_id = ' . $dono;
    }
    $res = $db->query($sql);
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT SUM(minutos_resposta) AS soma, COUNT(*) AS qtd FROM chamados WHERE minutos_resposta IS NOT NULL';
    $dono = donoChamadosVisiveis();
    if ($dono !== null) {
        $sql .= ' AND usuario_id = ' . $dono;
    }
    $row = $db->query($sql)->fetch_assoc();
    return (int) $row['qtd'] > 0 ? (float) $row['soma'] / (int) $row['qtd'] : 0.0;
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome
        FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id";
    $dono = donoChamadosVisiveis();
    if ($dono !== null) {
        $sql .= ' WHERE c.usuario_id = ' . $dono;
    }
    $sql .= ' ORDER BY c.id';
    $res = $db->query($sql, MYSQLI_USE_RESULT);
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        $res->free();
        throw new RuntimeException('Falha ao abrir a saida CSV.');
    }
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        header('Cache-Control: no-store');
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
