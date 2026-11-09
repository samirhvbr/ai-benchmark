<?php
/**
 * lib.php — Regras e acesso a dados do Painel de Chamados (NetX ISP)
 *
 * Funções usadas pelo index.php e por relatórios internos.
 * ATENÇÃO: estas assinaturas são consumidas por outros scripts do ISP
 * (rotina noturna de exportação, relatório gerencial). Ver manifest.md.
 *
 * Compatibilidade: nenhuma assinatura pública foi alterada. A regra de
 * visibilidade do manifesto (cliente só vê os chamados que abriu; técnico vê
 * todos) é aplicada pelo "contexto de usuário" (ContextoPainel). Os scripts
 * internos do ISP, que não definem contexto, continuam enxergando o conjunto
 * completo de chamados — exatamente como antes.
 */

/**
 * Contexto do usuário autenticado da requisição atual.
 *
 * - null  => operação interna (relatório gerencial, rotina noturna):
 *            visão global, igual ao comportamento legado.
 * - array => usuário logado no painel ('id' => int, 'papel' => string).
 *            Apenas o papel 'tecnico' enxerga tudo; qualquer outro papel é
 *            restrito aos próprios chamados (falha fechada).
 */
final class ContextoPainel
{
    private static ?array $usuario = null;

    /** Define (ou limpa) o usuário do contexto da requisição. */
    public static function definir(?array $usuario): void
    {
        if ($usuario === null || !isset($usuario['id'], $usuario['papel'])) {
            self::$usuario = null;
            return;
        }
        self::$usuario = [
            'id'    => (int) $usuario['id'],
            'papel' => (string) $usuario['papel'],
        ];
    }

    /** Usuário do contexto (null = operação interna, visão global). */
    public static function usuario(): ?array
    {
        return self::$usuario;
    }

    /** true quando a consulta precisa ser restrita ao próprio usuário. */
    public static function restringe(): bool
    {
        $u = self::$usuario;
        if ($u === null) {
            return false;
        }
        return $u['papel'] !== 'tecnico';
    }
}

/**
 * Executa uma consulta de forma segura, sem exceção escapando.
 *
 * Usa prepared statements quando há parâmetros e devolve null em caso de
 * falha (a exceção mysqli_sql_exception é capturada e registrada), de modo
 * que uma falha de banco não vire erro fatal com stack trace na tela.
 */
function executarConsulta(mysqli $db, string $sql, array $params = [], string $tipos = '')
{
    try {
        if ($params === []) {
            $res = $db->query($sql);
            return $res === false ? null : $res;
        }
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            error_log('[lib] prepare falhou: ' . $sql);
            return null;
        }
        $refs = [];
        foreach ($params as $i => $v) {
            $refs[$i] = &$params[$i];
        }
        $stmt->bind_param($tipos, ...$refs);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res === false ? null : $res;
    } catch (mysqli_sql_exception $e) {
        error_log('[lib] falha de SQL: ' . $e->getMessage());
        return null;
    }
}

/**
 * Normaliza uma linha para o mesmo formato do mysqli legado: todos os
 * valores como string, exceto NULL. Consultas preparadas devolvem tipos
 * nativos (int), e os consumidores do painel esperam strings.
 */
function normalizarValoresLinha(array $linha): array
{
    foreach ($linha as $coluna => $valor) {
        if ($valor !== null && !is_string($valor)) {
            $linha[$coluna] = (string) $valor;
        }
    }
    return $linha;
}

/**
 * Escapa os curingas do LIKE ('%', '_' e o próprio caractere de escape '!').
 */
function escaparLikeBusca(string $termo): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termo);
}

/**
 * Monta a condição SQL da regra de visibilidade para o contexto atual.
 * Retorna [fragmento_sql, parametros].
 */
function condicaoVisibilidadeChamado(string $alias = ''): array
{
    if (!ContextoPainel::restringe()) {
        return ['', []];
    }
    $u = ContextoPainel::usuario();
    $prefixo = $alias === '' ? '' : $alias . '.';
    return [$prefixo . 'usuario_id = ?', [(int) $u['id']]];
}

/**
 * Verifica se a coluna usuarios.senha já aceita hashes modernos
 * (password_hash precisa de 60 caracteres ou mais).
 */
function colunaSenhaAceitaHashLongo(mysqli $db): bool
{
    $res = executarConsulta(
        $db,
        "SELECT CHARACTER_MAXIMUM_LENGTH AS tamanho FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'senha'"
    );
    if ($res === null) {
        return false;
    }
    $row = $res->fetch_assoc();
    if (!$row || $row['tamanho'] === null) {
        return false;
    }
    return (int) $row['tamanho'] >= 60;
}

/**
 * Carrega os dados de um usuário pelo id (papel sempre vindo do banco).
 */
function carregarUsuarioPainel(mysqli $db, int $id): ?array
{
    $res = executarConsulta($db, 'SELECT id, nome, papel FROM usuarios WHERE id = ? LIMIT 1', [$id], 'i');
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    return $row ?: null;
}

/**
 * Autentica um usuário. Retorna ['id','nome','papel'] ou null.
 *
 * Aceita as senhas legadas em md5 (base existente) e as modernas em
 * password_hash(). Ao autenticar uma senha legada, o hash é migrado
 * automaticamente para password_hash() — desde que a coluna senha já aceite
 * o tamanho do novo hash (ver schema.sql).
 */
function autenticar(mysqli $db, string $usuario, string $senha): ?array
{
    $res = executarConsulta(
        $db,
        'SELECT id, nome, papel, senha FROM usuarios WHERE login = ? LIMIT 1',
        [$usuario],
        's'
    );
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    if (!$row) {
        return null;
    }

    $armazenado = (string) ($row['senha'] ?? '');
    $autenticado = false;
    $migrar = false;

    if ($armazenado !== '' && $armazenado[0] === '$') {
        // Hash moderno (bcrypt/argon).
        $autenticado = password_verify($senha, $armazenado);
        if ($autenticado && password_needs_rehash($armazenado, PASSWORD_DEFAULT)) {
            $migrar = true;
        }
    } elseif ($armazenado !== '') {
        // Legado: md5 sem sal.
        $autenticado = hash_equals(strtolower($armazenado), md5($senha));
        $migrar = $autenticado;
    }

    if (!$autenticado) {
        return null;
    }

    if ($migrar && colunaSenhaAceitaHashLongo($db)) {
        $novoHash = password_hash($senha, PASSWORD_DEFAULT);
        if (is_string($novoHash) && $novoHash !== '') {
            executarConsulta($db, 'UPDATE usuarios SET senha = ? WHERE id = ?', [$novoHash, (int) $row['id']], 'si');
        }
    }

    return [
        'id'    => (int) $row['id'],
        'nome'  => (string) $row['nome'],
        'papel' => (string) $row['papel'],
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
 * O resultado fica em cache por requisição para não repetir a consulta.
 */
function tecnicoNome(mysqli $db, ?int $tecnicoId): string
{
    static $cache = [];

    if ($tecnicoId === null) {
        return '-';
    }
    if (isset($cache[$tecnicoId])) {
        return $cache[$tecnicoId];
    }

    $res = executarConsulta($db, 'SELECT nome FROM usuarios WHERE id = ? LIMIT 1', [$tecnicoId], 'i');
    $nome = '-';
    if ($res !== null) {
        $t = $res->fetch_assoc();
        if ($t) {
            $nome = (string) $t['nome'];
        }
    }
    $cache[$tecnicoId] = $nome;
    return $nome;
}

/**
 * Lista os chamados, opcionalmente filtrando pelo título.
 * Retorna cada chamado já com a chave extra 'tecnico_nome'.
 *
 * Quando há usuário no contexto (painel web), a lista é limitada aos
 * chamados que ele pode ver; em operação interna, traz todos.
 */
function listarChamados(mysqli $db, string $busca = ''): array
{
    $sql = 'SELECT c.*, COALESCE(u.nome, ?) AS tecnico_nome FROM chamados c '
         . 'LEFT JOIN usuarios u ON u.id = c.tecnico_id';
    $params = ['-'];
    $tipos = 's';

    [$visSql, $visParams] = condicaoVisibilidadeChamado('c');
    if ($visSql !== '') {
        $sql .= ' WHERE ' . $visSql;
        $params = array_merge($params, $visParams);
        $tipos .= 'i';
    }

    if ($busca !== '') {
        $sql .= ($visSql !== '' ? ' AND ' : ' WHERE ') . "c.titulo LIKE ? ESCAPE '!'";
        $params[] = '%' . escaparLikeBusca($busca) . '%';
        $tipos .= 's';
    }

    $sql .= ' ORDER BY c.criado_em DESC';

    $res = executarConsulta($db, $sql, $params, $tipos);
    if ($res === null) {
        return [];
    }

    $chamados = [];
    while ($c = $res->fetch_assoc()) {
        $chamados[] = normalizarValoresLinha($c);
    }
    return $chamados;
}

/**
 * Carrega um chamado pelo id, para a tela de detalhe.
 * Respeita a regra de visibilidade do contexto atual.
 */
function verChamado(mysqli $db, int $id): ?array
{
    $sql = 'SELECT * FROM chamados c WHERE c.id = ?';
    $params = [$id];
    $tipos = 'i';

    [$visSql, $visParams] = condicaoVisibilidadeChamado('c');
    if ($visSql !== '') {
        $sql .= ' AND ' . $visSql;
        $params = array_merge($params, $visParams);
        $tipos .= 'i';
    }

    $res = executarConsulta($db, $sql, $params, $tipos);
    if ($res === null) {
        return null;
    }
    $row = $res->fetch_assoc();
    return $row ? normalizarValoresLinha($row) : null;
}

/**
 * Média de minutos até a primeira resposta (indicador de SLA no topo do painel).
 * Devolve 0.0 quando não há nenhuma resposta registrada (evita divisão por
 * zero, que no PHP 8 é erro fatal).
 */
function mediaResposta(mysqli $db): float
{
    $sql = 'SELECT AVG(c.minutos_resposta) AS media FROM chamados c WHERE c.minutos_resposta IS NOT NULL';
    [$visSql, $visParams] = condicaoVisibilidadeChamado('c');
    if ($visSql !== '') {
        $sql .= ' AND ' . $visSql;
    }

    $res = executarConsulta($db, $sql, $visParams, $visParams === [] ? '' : 'i');
    if ($res === null) {
        return 0.0;
    }
    $row = $res->fetch_assoc();
    if (!$row || $row['media'] === null) {
        return 0.0;
    }
    return (float) $row['media'];
}

/**
 * Neutraliza fórmulas em células de CSV (o Excel executa conteúdo que começa
 * com =, +, -, @ como fórmula). Prefixa a célula com apóstrofo.
 */
function celulaCsvSegura(string $valor): string
{
    if ($valor === '') {
        return $valor;
    }
    $primeiro = $valor[0];
    if ($primeiro === '=' || $primeiro === '+' || $primeiro === '-'
        || $primeiro === '@' || $primeiro === "\t" || $primeiro === "\r") {
        return "'" . $valor;
    }
    return $valor;
}

/**
 * Grava o conteúdo do export em EXPORT_DIR/chamados.csv (artefato consumido
 * pela rotina noturna). A escrita é atômica (arquivo temporário + rename) e
 * não segue links simbólicos. Falhas não interrompem o download.
 */
function gravarArquivoExportacao(string $conteudo): void
{
    $dir = EXPORT_DIR;
    if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
        error_log('[lib] EXPORT_DIR indisponivel para gravar o CSV: ' . $dir);
        return;
    }

    $tmp = @tempnam($dir, 'exp-');
    if ($tmp === false) {
        error_log('[lib] nao foi possivel criar arquivo temporario em ' . $dir);
        return;
    }
    @chmod($tmp, 0600);
    if (@file_put_contents($tmp, $conteudo) === false) {
        @unlink($tmp);
        error_log('[lib] falha ao gravar o CSV temporario em ' . $dir);
        return;
    }
    @chmod($tmp, 0600);
    // rename() substitui o destino (inclusive um eventual link simbolico)
    // sem seguir o link, evitando sobrescrita de arquivos arbitrarios.
    if (!@rename($tmp, $dir . '/chamados.csv')) {
        @unlink($tmp);
        error_log('[lib] falha ao publicar o CSV em ' . $dir);
    }
}

/**
 * Exporta todos os chamados para CSV e devolve o arquivo ao navegador.
 * Cabeçalho do arquivo: ID,Titulo,Status,Tecnico,Aberto em
 *
 * Quando há usuário no contexto, exporta apenas os chamados que ele pode
 * ver (mesma regra da listagem). Sem contexto (uso interno), exporta todos.
 */
function exportarCsv(mysqli $db): void
{
    $sql = 'SELECT c.* FROM chamados c';
    [$visSql, $visParams] = condicaoVisibilidadeChamado('c');
    if ($visSql !== '') {
        $sql .= ' WHERE ' . $visSql;
    }
    $sql .= ' ORDER BY c.id';

    $csv = fopen('php://temp', 'r+');
    if ($csv === false) {
        error_log('[lib] nao foi possivel abrir o buffer temporario do CSV');
        return;
    }

    fputcsv($csv, ['ID', 'Titulo', 'Status', 'Tecnico', 'Aberto em']);

    $res = executarConsulta($db, $sql, $visParams, $visParams === [] ? '' : 'i');
    if ($res !== null) {
        while ($c = $res->fetch_assoc()) {
            $c = normalizarValoresLinha($c);
            $tecnico = tecnicoNome($db, $c['tecnico_id'] !== null ? (int) $c['tecnico_id'] : null);
            if ($tecnico !== '-') {
                $tecnico = celulaCsvSegura($tecnico);
            }
            fputcsv($csv, [
                $c['id'],
                celulaCsvSegura((string) $c['titulo']),
                formatarStatus((int) $c['status']),
                $tecnico,
                $c['criado_em'],
            ]);
        }
    }

    rewind($csv);
    $conteudo = stream_get_contents($csv);
    fclose($csv);
    if ($conteudo === false) {
        $conteudo = '';
    }

    // Artefato em disco para a rotina noturna (mesmo caminho de sempre).
    gravarArquivoExportacao($conteudo);

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="chamados.csv"');
        header('Content-Length: ' . strlen($conteudo));
        header('X-Content-Type-Options: nosniff');
    }
    echo $conteudo;
}
