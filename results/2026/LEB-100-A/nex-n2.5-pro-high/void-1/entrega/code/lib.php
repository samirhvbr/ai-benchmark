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
function acessoIrrestritoPermitido(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    return session_status() === PHP_SESSION_ACTIVE
        && !sessaoExpirada()
        && (($_SESSION['papel'] ?? null) === 'tecnico')
        && ($_SESSION['leb_sessao_validada'] ?? false) === true;
}

function sessaoExpirada(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    if (!defined('SESSION_TIMEOUT') || (int) SESSION_TIMEOUT <= 0) {
        return true;
    }

    $ultimaAtividade = $_SESSION['leb_ultima_atividade'] ?? null;
    if (!is_int($ultimaAtividade)) {
        $_SESSION['leb_ultima_atividade'] = time();
        return false;
    }

    return time() - $ultimaAtividade > SESSION_TIMEOUT;
}

function atualizarAtividadeDaSessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['leb_ultima_atividade'] = time();
    }
}

function usuarioIdParaAcessoValidado(mysqli $db): ?int
{
    if (PHP_SAPI === 'cli') {
        return null;
    }

    if (session_status() !== PHP_SESSION_ACTIVE || sessaoExpirada()) {
        return 0;
    }

    $uid = filter_var($_SESSION['uid'] ?? 0, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) ?: 0;
    $papel = is_string($_SESSION['papel'] ?? null) ? $_SESSION['papel'] : '';
    if ($uid <= 0 || !in_array($papel, ['cliente', 'tecnico'], true)) {
        $_SESSION['leb_sessao_validada'] = false;
        return 0;
    }

    $stmt = lebPrepararStatement($db, 'SELECT papel FROM usuarios WHERE id = ?');
    if ($stmt === null) {
        $_SESSION['leb_sessao_validada'] = false;
        return 0;
    }
    if (!lebVincularParametros($stmt, 'i', [$uid])) {
        fecharStatement($stmt);
        $_SESSION['leb_sessao_validada'] = false;
        return 0;
    }
    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        $_SESSION['leb_sessao_validada'] = false;
        return 0;
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    if ($linhas === false || !isset($linhas[0]['papel']) || (string) $linhas[0]['papel'] !== $papel) {
        $_SESSION['leb_sessao_validada'] = false;
        return 0;
    }

    atualizarAtividadeDaSessao();
    $_SESSION['leb_sessao_validada'] = true;
    return $papel === 'cliente' ? $uid : null;
}
function contextoDeAcessoValido(?int $usuarioId): bool
{
    if ($usuarioId === null) {
        return acessoIrrestritoPermitido();
    }

    if (PHP_SAPI === 'cli') {
        return $usuarioId > 0;
    }

    $uid = filter_var($_SESSION['uid'] ?? 0, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) ?: 0;

    return session_status() === PHP_SESSION_ACTIVE
        && !sessaoExpirada()
        && (($_SESSION['papel'] ?? null) === 'cliente')
        && ($_SESSION['leb_sessao_validada'] ?? false) === true
        && $usuarioId === $uid;
}

function normalizarBusca(string $busca): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($busca, 0, 200, 'UTF-8');
    }

    if (preg_match_all('/[\s\S]/u', $busca, $partes) === 1) {
        return implode('', array_slice($partes[0], 0, 200));
    }

    return substr($busca, 0, 200);
}

function termoLikeLiteral(string $busca): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $busca);
}

function fecharStatement(mysqli_stmt $stmt): void
{
    try {
        @$stmt->close();
    } catch (Throwable $_) {
    }
}

function lebPrepararStatement(mysqli $db, string $sql): ?mysqli_stmt
{
    try {
        $stmt = $db->prepare($sql);
        return $stmt instanceof mysqli_stmt ? $stmt : null;
    } catch (Throwable $_) {
        return null;
    }
}

function lebVincularParametros(mysqli_stmt $stmt, string $tipos, array $parametros): bool
{
    try {
        $referencias = [$tipos];
        foreach ($parametros as $indice => $_valor) {
            $referencias[] = &$parametros[$indice];
        }
        return $stmt->bind_param(...$referencias);
    } catch (Throwable $_) {
        return false;
    }
}

function lebExecutarStatement(mysqli_stmt $stmt): bool
{
    try {
        return $stmt->execute();
    } catch (Throwable $_) {
        return false;
    }
}

function resultadoDoStatement(mysqli_stmt $stmt)
{
    if (!method_exists($stmt, 'get_result')) {
        return false;
    }

    try {
        $resultado = @$stmt->get_result();
        return $resultado === false ? false : $resultado;
    } catch (Throwable $_) {
        return false;
    }
}

function percorrerResultado(mysqli_stmt $stmt, callable $processar): bool
{
    $resultado = resultadoDoStatement($stmt);
    if ($resultado !== false) {
        try {
            while (($linha = $resultado->fetch_assoc()) !== null) {
                if ($linha === false) {
                    return false;
                }
                if ($processar($linha) === false) {
                    return false;
                }
            }
            return true;
        } catch (Throwable $_) {
            return false;
        } finally {
            try {
                $resultado->free();
            } catch (Throwable $_) {
            }
        }
    }

    $metadados = null;
    try {
        $metadados = $stmt->result_metadata();
        if ($metadados === false) {
            return false;
        }

        $campos = [];
        while ($campo = $metadados->fetch_field()) {
            $campos[] = $campo->name;
        }

        $valores = array_fill(0, count($campos), null);
        $referencias = [];
        foreach ($valores as $indice => &$valor) {
            $referencias[] = &$valor;
        }
        unset($valor);

        if (!$stmt->bind_result(...$referencias) || !$stmt->store_result()) {
            return false;
        }

        while (($status = $stmt->fetch()) !== null) {
            if ($status === false) {
                return false;
            }
            $linha = [];
            foreach ($campos as $indice => $campo) {
                $linha[$campo] = $valores[$indice];
            }
            if ($processar($linha) === false) {
                return false;
            }
        }
        return true;
    } catch (Throwable $_) {
        return false;
    } finally {
        if ($metadados !== null) {
            try {
                $metadados->free();
            } catch (Throwable $_) {
            }
        }
        try {
            $stmt->free_result();
        } catch (Throwable $_) {
        }
    }
}

function linhasDoStatement(mysqli_stmt $stmt)
{
    $linhas = [];
    $ok = percorrerResultado($stmt, static function (array $linha) use (&$linhas): bool {
        $linhas[] = $linha;
        return true;
    });
    return $ok ? $linhas : false;
}

function colunaSenhaComportaHashBcrypt(mysqli $db): bool
{
    $stmt = lebPrepararStatement($db, 'SELECT CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = \'usuarios\'
          AND COLUMN_NAME = \'senha\'');
    if ($stmt === null) {
        return false;
    }

    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return false;
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    return $linhas !== false
        && isset($linhas[0]['CHARACTER_MAXIMUM_LENGTH'])
        && (int) $linhas[0]['CHARACTER_MAXIMUM_LENGTH'] >= 60;
}

function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = lebPrepararStatement($db, 'SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
    if ($stmt === null) {
        return null;
    }

    if (!lebVincularParametros($stmt, 's', [$usuario])) {
        fecharStatement($stmt);
        return null;
    }
    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return null;
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    if ($linhas === false || $linhas === []) {
        return null;
    }
    $linha = $linhas[0];

    $hash = trim((string) $linha['senha']);
    $valida = password_verify($senha, $hash);
    if (!$valida && strlen($hash) === 32 && ctype_xdigit($hash)) {
        $valida = hash_equals(hash('md5', $senha), strtolower($hash));
    }

    if (!$valida) {
        return null;
    }

    try {
        $info = password_get_info($hash);
        if (($info['algo'] === 0 || password_needs_rehash($hash, PASSWORD_DEFAULT))
            && colunaSenhaComportaHashBcrypt($db)
        ) {
            $novoHash = password_hash($senha, PASSWORD_DEFAULT);
            if ($novoHash !== false) {
                $atualiza = lebPrepararStatement($db, 'UPDATE usuarios SET senha = ? WHERE id = ?');
                if ($atualiza !== null) {
                    if (lebVincularParametros($atualiza, 'si', [$novoHash, (int) $linha['id']])
                        && lebExecutarStatement($atualiza)
                    ) {
                    }
                    fecharStatement($atualiza);
                }
            }
        }
    } catch (Throwable $_) {
    }

    return [
        'id' => (int) $linha['id'],
        'nome' => (string) $linha['nome'],
        'papel' => (string) $linha['papel'],
    ];
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    } else if ($status === 2) {
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
                if ($prioridade === 4) {
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

    $stmt = lebPrepararStatement($db, 'SELECT nome FROM usuarios WHERE id = ?');
    if ($stmt === null) {
        return '-';
    }

    if (!lebVincularParametros($stmt, 'i', [$tecnicoId])) {
        fecharStatement($stmt);
        return '-';
    }
    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return '-';
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    return $linhas !== false && isset($linhas[0]['nome']) ? (string) $linhas[0]['nome'] : '-';
}

function listarChamadosComAcesso(mysqli $db, ?int $usuarioId, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];
    $condicoes = [];

    if (!contextoDeAcessoValido($usuarioId)) {
        return [];
    }

    if ($usuarioId !== null) {
        $condicoes[] = 'c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuarioId;
    }

    if ($busca !== '') {
        $condicoes[] = 'c.titulo LIKE ? ESCAPE \'!\'';
        $tipos .= 's';
        $parametros[] = '%' . termoLikeLiteral(normalizarBusca($busca)) . '%';
    }

    if ($condicoes !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $condicoes);
    }

    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = lebPrepararStatement($db, $sql);
    if ($stmt === null) {
        return [];
    }

    if ($tipos !== '') {
        if (!lebVincularParametros($stmt, $tipos, $parametros)) {
            fecharStatement($stmt);
            return [];
        }
    }

    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return [];
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    return $linhas === false ? [] : $linhas;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    return listarChamadosComAcesso($db, usuarioIdParaAcessoValidado($db), $busca);
}

function verChamadoComAcesso(mysqli $db, int $id, ?int $usuarioId): ?array
{
    if (!contextoDeAcessoValido($usuarioId)) {
        return null;
    }

    $sql = 'SELECT c.* FROM chamados c WHERE c.id = ?';
    $tipos = 'i';
    $parametros = [$id];

    if ($usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $usuarioId;
    }

    $stmt = lebPrepararStatement($db, $sql);
    if ($stmt === null) {
        return null;
    }

    if (!lebVincularParametros($stmt, $tipos, $parametros)) {
        fecharStatement($stmt);
        return null;
    }

    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return null;
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    return $linhas === false || $linhas === [] ? null : $linhas[0];
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    return verChamadoComAcesso($db, $id, usuarioIdParaAcessoValidado($db));
}

function mediaRespostaComAcesso(mysqli $db, ?int $usuarioId): float
{
    if (!contextoDeAcessoValido($usuarioId)) {
        return 0.0;
    }

    $sql = 'SELECT AVG(c.minutos_resposta) AS media FROM chamados c'
        . ' WHERE c.minutos_resposta IS NOT NULL';
    $tipos = '';
    $parametros = [];

    if ($usuarioId !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos = 'i';
        $parametros[] = $usuarioId;
    }

    $stmt = lebPrepararStatement($db, $sql);
    if ($stmt === null) {
        return 0.0;
    }

    if ($tipos !== '') {
        if (!lebVincularParametros($stmt, $tipos, $parametros)) {
            fecharStatement($stmt);
            return 0.0;
        }
    }

    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return 0.0;
    }

    $linhas = linhasDoStatement($stmt);
    fecharStatement($stmt);
    return $linhas !== false && isset($linhas[0]['media']) && $linhas[0]['media'] !== null
        ? (float) $linhas[0]['media']
        : 0.0;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    return mediaRespostaComAcesso($db, usuarioIdParaAcessoValidado($db));
}

function celulaCsvSegura(string $valor): string
{
    if ($valor === '') {
        return $valor;
    }

    $inicio = 0;
    if (preg_match('/^(?:[\p{Z}\p{C}\x00-\x1F\x7F])+/u', $valor, $correspondencias) === 1) {
        $inicio = strlen($correspondencias[0]);
    } else {
        $inicio = strspn($valor, " \t\n\r\0\x0B\f");
    }
    $primeiro = $valor[$inicio] ?? '';
    if ($primeiro === '=' || $primeiro === '+' || $primeiro === '@') {
        return "'" . $valor;
    }
    if ($primeiro === '-' && $valor !== '-') {
        return "'" . $valor;
    }

    return $valor;
}

function exportarCsvComAcesso(mysqli $db, ?int $usuarioId): void
{
    if (!contextoDeAcessoValido($usuarioId)) {
        return;
    }

    $sql = 'SELECT c.id, c.titulo, c.status, c.criado_em,'
        . ' COALESCE(u.nome, \'-\') AS tecnico_nome FROM chamados c'
        . ' LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];

    if ($usuarioId !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $parametros[] = $usuarioId;
    }

    $sql .= ' ORDER BY c.id';
    $stmt = lebPrepararStatement($db, $sql);
    if ($stmt === null) {
        return;
    }

    if ($tipos !== '') {
        if (!lebVincularParametros($stmt, $tipos, $parametros)) {
            fecharStatement($stmt);
            return;
        }
    }

    if (!lebExecutarStatement($stmt)) {
        fecharStatement($stmt);
        return;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chamados.csv"');
    header('X-Content-Type-Options: nosniff');

    $fp = fopen('php://output', 'w');
    if ($fp === false) {
        fecharStatement($stmt);
        return;
    }

    if (fwrite($fp, "ID,Titulo,Status,Tecnico,Aberto em\n") === false) {
        fecharStatement($stmt);
        fclose($fp);
        return;
    }

    $escapeCsv = PHP_VERSION_ID >= 70400 ? '' : '\\';
    $ok = percorrerResultado($stmt, static function (array $linha) use ($fp, $escapeCsv): bool {
        return fputcsv($fp, [
            $linha['id'],
            celulaCsvSegura((string) $linha['titulo']),
            formatarStatus((int) $linha['status']),
            celulaCsvSegura((string) $linha['tecnico_nome']),
            $linha['criado_em'],
        ], ',', '"', $escapeCsv) !== false;
    });
    fecharStatement($stmt);
    fclose($fp);
    if (!$ok) {
        return;
    }
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    exportarCsvComAcesso($db, usuarioIdParaAcessoValidado($db));
}
