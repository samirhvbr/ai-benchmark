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
    $legado = preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1;
    $preHash = strncmp($hash, 'sha256:', 7) === 0;
    $hashModerno = $preHash ? substr($hash, 7) : $hash;
    // Pré-hash mantém todas as senhas, inclusive as maiores que 72 bytes,
    // sem a truncagem do bcrypt; a camada externa fornece salt e custo.
    $senhaVerificacao = $preHash ? hash('sha256', $senha) : $senha;
    $bcryptLongo = !$preHash && strncmp($hash, '$2', 2) === 0 && strlen($senha) > 72;
    $valida = $legado
        ? hash_equals(strtolower($hash), md5($senha))
        : (!$bcryptLongo && password_verify($senhaVerificacao, $hashModerno));
    if (!$valida) {
        return null;
    }

    if ($legado || !$preHash || password_needs_rehash($hashModerno, PASSWORD_DEFAULT)) {
        // Uma instalação ainda com CHAR(32) continua autenticando durante a
        // migração. Nunca gravar um hash moderno truncado nesse esquema.
        try {
            $coluna = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH AS capacidade FROM information_schema.COLUMNS"
                . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'");
            $capacidade = $coluna ? (int) ($coluna->fetch_assoc()['capacidade'] ?? 0) : 0;
            if ($coluna) {
                $coluna->free();
            } else {
                error_log('Nao foi possivel consultar a coluna de senha: ' . $db->errno);
            }
            if ($capacidade >= 255) {
                $novoHash = 'sha256:' . password_hash(hash('sha256', $senha), PASSWORD_DEFAULT);
                // Comparação com o valor anterior evita sobrescrever troca de
                // senha ou migração concorrente realizada após o SELECT.
                $update = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ? AND senha = ?');
                if ($update === false) {
                    error_log('Nao foi possivel preparar a atualizacao de senha: ' . $db->errno);
                } else {
                    try {
                        $update->bind_param('sis', $novoHash, $linha['id'], $hash);
                        if (!$update->execute()) {
                            error_log('Nao foi possivel atualizar o hash de senha: ' . $update->errno);
                        }
                    } finally {
                        $update->close();
                    }
                }
            }
        } catch (mysqli_sql_exception $erro) {
            error_log('Nao foi possivel atualizar o hash de senha: ' . $erro->getCode());
        }
    }
    unset($linha['senha']);
    return $linha;
}

/**
 * Contexto web: cliente acessa somente seus chamados; sessão inválida é negada.
 * Integrações internas sem sessão mantêm o acesso global já existente.
 * $alias é definido apenas pelas consultas abaixo, nunca por entrada HTTP.
 */
function filtroVisibilidadeChamados(string $alias = ''): string
{
    if (!isset($_SESSION) && session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $uid = $_SESSION['uid'] ?? null;
    $papel = $_SESSION['papel'] ?? null;
    if ((!is_int($uid) && !is_string($uid)) || !ctype_digit((string) $uid) || (int) $uid <= 0) {
        return ' AND 1 = 0';
    }
    if ($papel === 'tecnico') {
        return '';
    }
    if ($papel === 'cliente') {
        return ' AND ' . $alias . 'usuario_id = ' . (int) $uid;
    }
    return ' AND 1 = 0';
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
    $sql = "SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE 1 = 1'
        . filtroVisibilidadeChamados('c.');
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
        // mysqli::query devolvia os números como texto. Prepared statements
        // usam tipos nativos: conservar os valores textuais da listagem legada.
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
    // O parâmetro é int; conservar o protocolo textual e os tipos do retorno.
    $res = $db->query('SELECT * FROM chamados WHERE id = ' . $id . filtroVisibilidadeChamados());
    return $res->fetch_assoc() ?: null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    $res = $db->query('SELECT COALESCE(AVG(minutos_resposta + 0e0), 0) AS media FROM chamados WHERE 1 = 1'
        . filtroVisibilidadeChamados());
    return (float) $res->fetch_assoc()['media'];
}

/**
 * Exporta os chamados visíveis para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $res = $db->query("SELECT c.*, COALESCE(t.nome, '-') AS tecnico_nome"
        . ' FROM chamados c LEFT JOIN usuarios t ON t.id = c.tecnico_id WHERE 1 = 1'
        . filtroVisibilidadeChamados('c.') . ' ORDER BY c.id');
    if ($res === false) {
        throw new RuntimeException('Falha ao consultar os chamados para exportacao.');
    }
    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        throw new RuntimeException('Falha ao abrir a saida do CSV.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    try {
        // Literal para cumprir o cabeçalho exato do manifesto, sem aspas extras.
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
        fclose($fp);
        $res->free();
    }
}
