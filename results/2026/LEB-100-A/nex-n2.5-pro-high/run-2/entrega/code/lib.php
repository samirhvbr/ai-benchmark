<?php
class FalhaContextoSessao extends RuntimeException
{
}

class FalhaExportacaoCsv extends RuntimeException
{
}

class FalhaBanco extends RuntimeException
{
}

/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 */

function bindParametros(mysqli_stmt $stmt, string $tipos, array $parametros): bool
{
    if ($tipos === '') {
        return $parametros === [];
    }

    if (strlen($tipos) !== count($parametros) || preg_match('/^[idsb]+$/D', $tipos) !== 1) {
        return false;
    }

    $referencias = [&$tipos];
    foreach ($parametros as $indice => $valor) {
        $referencias[] = &$parametros[$indice];
    }

    try {
        return $stmt->bind_param(...$referencias);
    } catch (Throwable) {
        return false;
    }
}

function colunaSenhaComportaHashModerno(mysqli $db): bool
{
    try {
        $resultado = $db->query("SHOW COLUMNS FROM usuarios LIKE 'senha'");
        if ($resultado === false) {
            return false;
        }

        $coluna = $resultado->fetch_assoc();
        $resultado->free();

        if (!is_array($coluna) || !isset($coluna['Type']) || !preg_match('/^(?:var)?char\((\d+)\)$/i', (string) $coluna['Type'], $partes)) {
            return false;
        }

        return (int) $partes[1] >= 60;
    } catch (mysqli_sql_exception) {
        return false;
    }
}

function contextoSessao(mysqli $db)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    if (!isset($_SESSION['uid'])) {
        return false;
    }

    $uid = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $papel = $_SESSION['papel'] ?? null;

    if (!is_int($uid) || !is_string($papel) || !in_array($papel, ['cliente', 'tecnico'], true)) {
        return false;
    }

    $stmt = null;
    $resultado = null;
    try {
        $stmt = $db->prepare('SELECT papel FROM usuarios WHERE id = ?');
        if ($stmt === false) {
            throw new FalhaContextoSessao('Falha ao preparar a validação da sessão.');
        }

        if (!bindParametros($stmt, 'i', [$uid]) || !$stmt->execute()) {
            throw new FalhaContextoSessao('Falha ao validar a sessão no banco.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaContextoSessao('Falha ao ler a sessão no banco.');
        }

        $linha = $resultado->fetch_assoc();
        if ($linha === false) {
            throw new FalhaContextoSessao('Falha ao ler a sessão no banco.');
        }
    } catch (FalhaContextoSessao $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaContextoSessao('Falha ao validar a sessão no banco.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }

    if ($linha === null || !isset($linha['papel']) || $linha['papel'] !== $papel) {
        return false;
    }

    return ['uid' => $uid, 'papel' => $papel];
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 */
function autenticarComFalha(mysqli $db, string $usuario, string $senha): ?array
{
    $stmt = null;
    $resultado = null;
    try {
        $stmt = $db->prepare('SELECT id, nome, papel, senha FROM usuarios WHERE login = ?');
        if ($stmt === false) {
            throw new FalhaBanco('Falha ao preparar a autenticação.');
        }

        if (!bindParametros($stmt, 's', [$usuario]) || !$stmt->execute()) {
            throw new FalhaBanco('Falha ao consultar o usuário.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaBanco('Falha ao ler o usuário.');
        }

        $linha = $resultado->fetch_assoc();
        if ($linha === false) {
            throw new FalhaBanco('Falha ao ler o usuário.');
        }
    } catch (FalhaBanco $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaBanco('Falha ao consultar o usuário.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }

    if ($linha === null) {
        return null;
    }

    $hash = (string) ($linha['senha'] ?? '');
    $senhaValida = password_verify($senha, $hash);

    if (!$senhaValida && preg_match('/^[a-f0-9]{32}$/i', $hash)) {
        $senhaValida = hash_equals(strtolower($hash), hash('md5', $senha));
    }

    if (!$senhaValida) {
        return null;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT) && colunaSenhaComportaHashModerno($db)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        try {
            $atualizacao = $db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            if ($atualizacao !== false) {
                if (bindParametros($atualizacao, 'si', [$novoHash, (int) $linha['id']]) && !$atualizacao->execute()) {
                    error_log('Falha ao atualizar hash de senha do usuario ' . (int) $linha['id']);
                }
                $atualizacao->close();
            }
        } catch (mysqli_sql_exception) {
            error_log('Falha ao atualizar hash de senha do usuario ' . (int) $linha['id']);
        }
    }

    unset($linha['senha']);
    return $linha;
}

function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    try {
        return autenticarComFalha($db, $usuario, $senha);
    } catch (FalhaBanco $erro) {
        error_log($erro->getMessage());
        return null;
    }
}

/**
 * Rótulo textual do status. Consumido também pelo relatório gerencial.
 */
function formatarStatus(int $status): string
{
    if ($status === 1) {
        return 'Aberto';
    }

    if ($status === 2) {
        return 'Em atendimento';
    }

    return 'Resolvido';
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
                }

                return 'Alto - atrasado';
            }

            return 'Alto - dentro do SLA';
        }

        return 'Normal';
    }

    return 'Aguardando 1a resposta';
}

/**
 * Busca o nome de um técnico pelo id (usado na listagem e no export).
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    if ($tecnicoId === null) {
        return '-';
    }

    try {
        $stmt = $db->prepare('SELECT nome FROM usuarios WHERE id = ?');
        if ($stmt === false) {
            return '-';
        }

        if (!bindParametros($stmt, 'i', [$tecnicoId]) || !$stmt->execute()) {
            $stmt->close();
            return '-';
        }

        $resultado = $stmt->get_result();
        $tecnico = $resultado !== false ? $resultado->fetch_assoc() : false;
        $stmt->close();
    } catch (mysqli_sql_exception) {
        return '-';
    }

    return $tecnico !== null && $tecnico !== false ? (string) $tecnico['nome'] : '-';
}

function listarChamadosComContexto(mysqli $db, string $busca, ?int $uid, ?string $papel): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, \'-\') AS tecnico_nome FROM chamados AS c LEFT JOIN usuarios AS u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];
    $visivelPorDono = $papel === 'cliente' && $uid !== null;

    if ($visivelPorDono) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $uid;
    }

    if ($busca !== '') {
        $sql .= ($visivelPorDono ? ' AND ' : ' WHERE ') . "c.titulo LIKE CONCAT('%', ?, '%')";
        $tipos .= 's';
        $parametros[] = $busca;
    }

    $sql .= ' ORDER BY c.criado_em DESC';
    $stmt = null;
    $resultado = null;
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new FalhaBanco('Falha ao preparar a listagem de chamados.');
        }

        if (!bindParametros($stmt, $tipos, $parametros) || !$stmt->execute()) {
            throw new FalhaBanco('Falha ao consultar os chamados.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaBanco('Falha ao ler os chamados.');
        }

        $chamados = [];
        while (true) {
            $chamado = $resultado->fetch_assoc();
            if ($chamado === false) {
                throw new FalhaBanco('Falha ao ler os chamados.');
            }
            if ($chamado === null) {
                break;
            }
            $chamados[] = $chamado;
        }

        return $chamados;
    } catch (FalhaBanco $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaBanco('Falha ao consultar os chamados.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    try {
        $contexto = contextoSessao($db);
    } catch (FalhaContextoSessao $erro) {
        error_log($erro->getMessage());
        return [];
    }
    if ($contexto === false) {
        return [];
    }

    try {
        return listarChamadosComContexto(
            $db,
            $busca,
            is_array($contexto) ? $contexto['uid'] : null,
            is_array($contexto) ? $contexto['papel'] : null,
        );
    } catch (FalhaBanco $erro) {
        error_log($erro->getMessage());
        return [];
    }
}

function verChamadoComContexto(mysqli $db, int $id, ?int $uid, ?string $papel): ?array
{
    $sql = 'SELECT c.* FROM chamados AS c WHERE c.id = ?';
    $tipos = 'i';
    $parametros = [$id];

    if ($papel === 'cliente' && $uid !== null) {
        $sql .= ' AND c.usuario_id = ?';
        $tipos .= 'i';
        $parametros[] = $uid;
    }

    $stmt = null;
    $resultado = null;
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new FalhaBanco('Falha ao preparar o detalhe do chamado.');
        }

        if (!bindParametros($stmt, $tipos, $parametros) || !$stmt->execute()) {
            throw new FalhaBanco('Falha ao consultar o chamado.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaBanco('Falha ao ler o chamado.');
        }

        $chamado = $resultado->fetch_assoc();
        if ($chamado === false) {
            throw new FalhaBanco('Falha ao ler o chamado.');
        }

        return $chamado;
    } catch (FalhaBanco $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaBanco('Falha ao consultar o chamado.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 */
function verChamado(mysqli $db, int $id): ?array
{
    try {
        $contexto = contextoSessao($db);
    } catch (FalhaContextoSessao $erro) {
        error_log($erro->getMessage());
        return null;
    }
    if ($contexto === false) {
        return null;
    }

    try {
        return verChamadoComContexto(
            $db,
            $id,
            is_array($contexto) ? $contexto['uid'] : null,
            is_array($contexto) ? $contexto['papel'] : null,
        );
    } catch (FalhaBanco $erro) {
        error_log($erro->getMessage());
        return null;
    }
}

function mediaRespostaComContexto(mysqli $db, ?int $uid, ?string $papel): float
{
    $sql = 'SELECT COALESCE(AVG(minutos_resposta), 0) AS media FROM chamados';
    $tipos = '';
    $parametros = [];

    if ($papel === 'cliente' && $uid !== null) {
        $sql .= ' WHERE usuario_id = ? AND minutos_resposta IS NOT NULL';
        $tipos = 'i';
        $parametros[] = $uid;
    } else {
        $sql .= ' WHERE minutos_resposta IS NOT NULL';
    }

    $stmt = null;
    $resultado = null;
    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new FalhaBanco('Falha ao preparar a média de resposta.');
        }

        if (!bindParametros($stmt, $tipos, $parametros) || !$stmt->execute()) {
            throw new FalhaBanco('Falha ao consultar a média de resposta.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaBanco('Falha ao ler a média de resposta.');
        }

        $linha = $resultado->fetch_assoc();
        if ($linha === false) {
            throw new FalhaBanco('Falha ao ler a média de resposta.');
        }

        return $linha !== null ? (float) $linha['media'] : 0.0;
    } catch (FalhaBanco $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaBanco('Falha ao consultar a média de resposta.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 */
function mediaResposta(mysqli $db): float
{
    try {
        $contexto = contextoSessao($db);
    } catch (FalhaContextoSessao $erro) {
        error_log($erro->getMessage());
        return 0.0;
    }
    if ($contexto === false) {
        return 0.0;
    }

    try {
        return mediaRespostaComContexto(
            $db,
            is_array($contexto) ? $contexto['uid'] : null,
            is_array($contexto) ? $contexto['papel'] : null,
        );
    } catch (FalhaBanco $erro) {
        error_log($erro->getMessage());
        return 0.0;
    }
}

function normalizarTextoUtf8(string $valor): string
{
    if ($valor === '') {
        return '';
    }

    if (preg_match('//u', $valor) === 1) {
        return $valor;
    }

    $normalizado = @iconv('UTF-8', 'UTF-8//IGNORE', $valor);
    if ($normalizado === false || $normalizado === '') {
        return "\u{FFFD}";
    }

    return preg_match('//u', $normalizado) === 1 ? $normalizado : "\u{FFFD}";
}

function valorCsvSeguro(string $valor): string
{
    $valor = normalizarTextoUtf8($valor);
    $valorSemPrefixo = preg_replace('/^[\p{Z}\p{C}\p{M}]+/u', '', $valor);
    if ($valorSemPrefixo === null) {
        return "\t" . $valor;
    }

    $prefixosPerigosos = '=+@-'
        . "\u{FF0B}\u{FF1D}\u{FF20}\u{FF0D}"
        . "\u{2212}\u{207B}\u{208B}"
        . "\u{2010}\u{2011}\u{2012}\u{2013}\u{2014}\u{2015}"
        . "\u{FE58}\u{FE62}\u{FE63}\u{FE66}\u{FE6B}";
    if ($valorSemPrefixo !== '' && preg_match('/^[' . preg_quote($prefixosPerigosos, '/') . ']/u', $valorSemPrefixo) === 1) {
        return "\t" . $valorSemPrefixo;
    }

    return $valor;
}

function precisaCitacaoCsv(string $valor): bool
{
    $valor = normalizarTextoUtf8($valor);
    $precisa = preg_match('/[,"\r\n;\x00-\x1F\x7F]|\p{Z}|\p{C}/u', $valor);

    return $precisa !== 0;
}

function serializarCampoCsv(string $valor): string
{
    $valor = normalizarTextoUtf8($valor);
    if (!precisaCitacaoCsv($valor)) {
        return $valor;
    }

    return '"' . str_replace('"', '""', $valor) . '"';
}

function serializarLinhaCsv(array $campos): string
{
    $partes = [];
    foreach ($campos as $campo) {
        $partes[] = serializarCampoCsv((string) $campo);
    }

    return implode(',', $partes) . "\n";
}

function escreverLinhaCsv($saida, string $linha): bool
{
    $total = strlen($linha);
    $escrito = 0;

    while ($escrito < $total) {
        $bytes = fwrite($saida, substr($linha, $escrito));
        if ($bytes === false || $bytes === 0) {
            return false;
        }
        $escrito += $bytes;
    }

    return true;
}

function exportarCsvComContexto(mysqli $db, ?int $uid, ?string $papel): void
{
    $sql = 'SELECT c.id, c.titulo, c.status, u.nome AS tecnico_nome, c.criado_em FROM chamados AS c LEFT JOIN usuarios AS u ON u.id = c.tecnico_id';
    $tipos = '';
    $parametros = [];

    if ($papel === 'cliente' && $uid !== null) {
        $sql .= ' WHERE c.usuario_id = ?';
        $tipos = 'i';
        $parametros[] = $uid;
    }

    $sql .= ' ORDER BY c.id ASC';
    $stmt = null;
    $resultado = null;
    $buffer = null;
    $saida = null;

    try {
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new FalhaExportacaoCsv('Falha ao preparar a exportação CSV.');
        }

        if (!bindParametros($stmt, $tipos, $parametros) || !$stmt->execute()) {
            throw new FalhaExportacaoCsv('Falha ao consultar os chamados para exportação CSV.');
        }

        $resultado = $stmt->get_result();
        if ($resultado === false) {
            throw new FalhaExportacaoCsv('Falha ao ler os chamados para exportação CSV.');
        }

        $buffer = fopen('php://temp/maxmemory:2097152', 'w+');
        if ($buffer === false) {
            throw new FalhaExportacaoCsv('Falha ao preparar a saída CSV.');
        }

        if (!escreverLinhaCsv($buffer, "ID,Titulo,Status,Tecnico,Aberto em\n")) {
            throw new FalhaExportacaoCsv('Falha ao escrever o cabeçalho CSV.');
        }

        while (true) {
            $chamado = $resultado->fetch_assoc();
            if ($chamado === false) {
                throw new FalhaExportacaoCsv('Falha ao ler os chamados para exportação CSV.');
            }
            if ($chamado === null) {
                break;
            }
            $linha = serializarLinhaCsv([
                (int) $chamado['id'],
                valorCsvSeguro((string) ($chamado['titulo'] ?? '')),
                formatarStatus((int) ($chamado['status'] ?? 0)),
                valorCsvSeguro((string) ($chamado['tecnico_nome'] ?? '-')),
                (string) ($chamado['criado_em'] ?? ''),
            ]);
            if (!escreverLinhaCsv($buffer, $linha)) {
                throw new FalhaExportacaoCsv('Falha ao escrever os dados CSV.');
            }
        }

        if (rewind($buffer) === false) {
            throw new FalhaExportacaoCsv('Falha ao preparar o envio CSV.');
        }

        if (headers_sent()) {
            throw new FalhaExportacaoCsv('Não é possível enviar os cabeçalhos CSV após o início da resposta.');
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');

        $saida = fopen('php://output', 'w');
        if ($saida === false) {
            throw new FalhaExportacaoCsv('Falha ao abrir a saída CSV.');
        }

        while (!feof($buffer)) {
            $trecho = fread($buffer, 8192);
            if ($trecho === false) {
                throw new FalhaExportacaoCsv('Falha ao ler a saída CSV.');
            }
            if (!escreverLinhaCsv($saida, $trecho)) {
                throw new FalhaExportacaoCsv('Falha ao enviar a saída CSV.');
            }
        }
    } catch (FalhaExportacaoCsv $erro) {
        throw $erro;
    } catch (mysqli_sql_exception $erro) {
        throw new FalhaExportacaoCsv('Falha ao exportar os chamados para CSV.', 0, $erro);
    } finally {
        if ($resultado instanceof mysqli_result) {
            try {
                $resultado->free();
            } catch (Throwable) {
            }
        }
        if ($stmt instanceof mysqli_stmt) {
            try {
                $stmt->close();
            } catch (Throwable) {
            }
        }
        if (is_resource($buffer)) {
            fclose($buffer);
        }
        if (is_resource($saida)) {
            fclose($saida);
        }
    }
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 */
function exportarCsv(mysqli $db): void
{
    $contexto = contextoSessao($db);
    if ($contexto === false) {
        return;
    }

    exportarCsvComContexto(
        $db,
        is_array($contexto) ? $contexto['uid'] : null,
        is_array($contexto) ? $contexto['papel'] : null,
    );
}
